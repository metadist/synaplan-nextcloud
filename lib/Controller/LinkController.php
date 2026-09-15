<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Controller;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Exception\PlatformLinkException;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Authorization-code handshake: start → Synaplan confirm → callback → key.
 *
 * The browser never sees the API key. `state` is the CSRF check.
 */
class LinkController extends Controller
{
    private const SESSION_STATE = 'synaplan_link_state';
    private const SESSION_STATE_AT = 'synaplan_link_state_at';
    private const STATE_TTL_SECONDS = 600;

    public function __construct(
        IRequest $request,
        private ISession $session,
        private IUserSession $userSession,
        private SynaplanConfig $synaplanConfig,
        private UserAccountService $userAccounts,
        private PlatformLinkService $platformLinks,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Begin the handshake: store state and send the user to Synaplan.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function start(): RedirectResponse
    {
        $user = $this->userSession->getUser();
        if (!$user instanceof IUser) {
            return new RedirectResponse($this->platformLinks->personalSettingsUrl([
                'link_error' => PlatformLinkException::CODE_STATE,
            ]));
        }

        if (!$this->synaplanConfig->isLinkAvailable()) {
            return new RedirectResponse($this->platformLinks->personalSettingsUrl([
                'link_error' => PlatformLinkException::CODE_INSTANCE_PENDING,
            ]));
        }

        $state = bin2hex(random_bytes(16));
        $this->session->set(self::SESSION_STATE, $state);
        $this->session->set(self::SESSION_STATE_AT, time());

        return new RedirectResponse($this->platformLinks->connectUrl(
            $this->synaplanConfig->getLinkInstanceId(),
            $user->getUID(),
            $state,
        ));
    }

    /**
     * Cross-site GET from Synaplan. `state` is the CSRF check.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function callback(): RedirectResponse
    {
        $code = trim((string) $this->request->getParam('code', ''));
        $state = trim((string) $this->request->getParam('state', ''));

        $error = $this->consumeState($state);
        if ($error !== null) {
            return new RedirectResponse($this->platformLinks->personalSettingsUrl([
                'link_error' => $error,
            ]));
        }

        $user = $this->userSession->getUser();
        if (!$user instanceof IUser || $code === '') {
            return new RedirectResponse($this->platformLinks->personalSettingsUrl([
                'link_error' => PlatformLinkException::CODE_EXCHANGE,
            ]));
        }

        try {
            $exchange = $this->platformLinks->exchange($code);
            $this->userAccounts->storeLinkedAccount($user, $exchange);
        } catch (PlatformLinkException $e) {
            return new RedirectResponse($this->platformLinks->personalSettingsUrl([
                'link_error' => $e->getErrorCode(),
            ]));
        } catch (\Throwable) {
            return new RedirectResponse($this->platformLinks->personalSettingsUrl([
                'link_error' => PlatformLinkException::CODE_EXCHANGE,
            ]));
        }

        return new RedirectResponse($this->platformLinks->personalSettingsUrl([
            'linked' => '1',
        ]));
    }

    /**
     * Revoke the linked key and forget local prefs.
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function disconnect(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (!$user instanceof IUser) {
            return new JSONResponse(['success' => false], 401);
        }

        $this->platformLinks->disconnect($user);

        return new JSONResponse(['success' => true]);
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function status(): JSONResponse
    {
        return new JSONResponse($this->userAccounts->getLinkStatus());
    }

    /**
     * Validate, then delete, the session state. Always single-use.
     */
    private function consumeState(string $state): ?string
    {
        $expected = (string) $this->session->get(self::SESSION_STATE);
        $storedAt = (int) $this->session->get(self::SESSION_STATE_AT);
        $this->session->remove(self::SESSION_STATE);
        $this->session->remove(self::SESSION_STATE_AT);

        if ($state === '' || $expected === '') {
            return PlatformLinkException::CODE_STATE;
        }
        if (!hash_equals($expected, $state)) {
            return PlatformLinkException::CODE_STATE;
        }
        if ($storedAt <= 0 || (time() - $storedAt) > self::STATE_TTL_SECONDS) {
            return PlatformLinkException::CODE_STATE;
        }

        return null;
    }
}
