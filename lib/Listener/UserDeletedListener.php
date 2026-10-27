<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Listener;

use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\BeforeUserDeletedEvent;

/**
 * When a Nextcloud user is deleted, tell Synaplan that this person is gone.
 * Synaplan revokes that identity and its key. The Synaplan account is removed
 * only while this cloud alone manages it.
 *
 * Uses BeforeUserDeletedEvent so the uid → Synaplan-account mapping (stored in
 * the user's preferences) is still readable at the time we call Synaplan.
 *
 * @implements IEventListener<BeforeUserDeletedEvent>
 */
class UserDeletedListener implements IEventListener
{
    public function __construct(
        private UserAccountService $userAccounts,
        private ?PlatformLinkService $platformLinks = null,
    ) {
    }

    public function handle(Event $event): void
    {
        $uid = $event->getUser()->getUID();
        $linked = $this->userAccounts->wasLinked($uid)
            || $this->userAccounts->getLinkKind($uid) === UserAccountService::KIND_LINKED;
        // Decide before disconnect clears the stored key.
        $report = $linked || $this->hasIdentity($uid);
        if ($linked) {
            // A linked key is revoked locally as well. An admin who switched
            // back to one shared account must still drop it.
            $this->platformLinks?->disconnectUid($uid);
        }
        if ($report) {
            $this->platformLinks?->reportLifecycle($uid, 'deleted');
        }
    }

    private function hasIdentity(string $uid): bool
    {
        return $this->userAccounts->getLinkKind($uid) !== null
            || $this->userAccounts->getStoredApiKey($uid) !== ''
            || $this->userAccounts->getSynaplanUserId($uid) !== null;
    }
}
