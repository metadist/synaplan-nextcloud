<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Controller;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Service\CopyDelivery;
use OCA\SynaplanIntegration\Service\FolderRead;
use OCA\SynaplanIntegration\Service\NotificationSignature;
use OCA\SynaplanIntegration\Service\NotifyKeyCache;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\SeenNotificationStore;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The one address Synaplan calls. Nothing is fetched until the notification
 * verifies: signature, this instance, five minutes, and an id not seen in
 * the last ten minutes.
 */
class InboundController extends Controller
{
    public function __construct(
        IRequest $request,
        private SynaplanConfig $synaplanConfig,
        private NotifyKeyCache $keys,
        private SeenNotificationStore $seen,
        private CopyDelivery $copies,
        private FolderRead $reads,
        private PlatformLinkService $links,
        private UserAccountService $accounts,
        private ICacheFactory $cacheFactory,
        private ITimeFactory $time,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * @param string|null $body the notification token, read from the request when omitted
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 120, period: 60)]
    public function receive(?string $body = null): JSONResponse
    {
        $token = $body ?? (string) file_get_contents('php://input');
        $reason = $this->rejectReason($token);
        if ($reason !== null) {
            $this->noteReject();

            return new JSONResponse(['error' => $reason], Http::STATUS_UNAUTHORIZED);
        }

        $notification = NotificationSignature::claims($token);
        $type = (string) $notification['type'];
        $id = (string) $notification['id'];
        // A copy or a folder read is done for the person the signed notice
        // names, with that person's own key. Without one nobody here may act,
        // and Synaplan is told so before anything is claimed.
        $person = is_string($notification['sub'] ?? null) ? $notification['sub'] : '';
        if (in_array($type, ['delivery', 'pickup'], true) && !$this->links->canActFor($person)) {
            return new JSONResponse(['error' => 'not_linked'], Http::STATUS_CONFLICT);
        }

        return match ($type) {
            'delivery' => $this->delivered($this->copies->handle($id, $person)),
            'identity_revoked' => $this->revoked($id),
            'pickup' => $this->read($id, $person),
            default => new JSONResponse([], Http::STATUS_NO_CONTENT),
        };
    }

    private function rejectReason(string $token): ?string
    {
        $reason = NotificationSignature::verify(
            $token,
            $this->keys->issuer(),
            $this->synaplanConfig->getLinkInstanceId(),
            $this->keys->keys(),
            $this->time->getTime(),
        );
        if ($reason !== NotificationSignature::OK) {
            return $reason;
        }
        $notification = NotificationSignature::claims($token);
        $type = $notification['type'] ?? null;
        $id = $notification['id'] ?? null;
        if (!is_string($type) || $type === '' || !is_scalar($id)) {
            return NotificationSignature::BAD_SIGNATURE;
        }
        if ($this->seen->alreadySeen($type, (string) $id)) {
            return NotificationSignature::REPLAY;
        }

        return null;
    }

    /**
     * @param array{status: int, body: array<string, mixed>} $result
     */
    private function delivered(array $result): JSONResponse
    {
        $status = $result['status'] === Http::STATUS_ACCEPTED
            ? Http::STATUS_ACCEPTED
            : Http::STATUS_OK;

        return new JSONResponse($result['body'], $status);
    }

    private function read(string $id, string $person): JSONResponse
    {
        $this->reads->handle($id, $person);

        return new JSONResponse(['state' => 'accepted'], Http::STATUS_ACCEPTED);
    }

    private function revoked(string $linkId): JSONResponse
    {
        $this->accounts->revokeLinkedKey($linkId);

        return new JSONResponse([], Http::STATUS_NO_CONTENT);
    }

    /**
     * One line per minute, with how many notifications were refused, instead
     * of one line per forged request.
     */
    private function noteReject(): void
    {
        $cache = $this->cacheFactory->createDistributed(Application::APP_ID);
        $minute = (string) intdiv(time(), 60);
        $count = (int) $cache->get('reject_' . $minute);
        $cache->set('reject_' . $minute, (string) ($count + 1), 120);
        if ($count !== 0) {
            return;
        }
        $previous = (int) $cache->get('reject_' . ((int) $minute - 1));
        if ($previous > 0) {
            $this->logger->info('Refused {count} Synaplan notifications in the last minute', [
                'app' => Application::APP_ID,
                'count' => $previous,
            ]);
        }
    }
}
