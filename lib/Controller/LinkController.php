<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Controller;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Exception\PlatformLinkException;
use OCA\SynaplanIntegration\Service\OAuthClient;
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
 * Connecting a person through Synaplan's OAuth2 server: start → Synaplan
 * consent → callback → the person's grant (code flow with PKCE).
 *
 * The browser never sees a token. `state` is the CSRF check; the PKCE
 * verifier stays in the session.
 */
class LinkController extends Controller
{
    private const SESSION_STATE = 'synaplan_link_state';
    private const SESSION_STATE_AT = 'synaplan_link_state_at';
    private const SESSION_UID = 'synaplan_link_uid';
    private const SESSION_VERIFIER = 'synaplan_link_verifier';
    private const STATE_TTL_SECONDS = 600;

    public function __construct(
        IRequest $request,
        private ISession $session,
        private IUserSession $userSession,
        private SynaplanConfig $synaplanConfig,
        private UserAccountService $userAccounts,
        private PlatformLinkService $platformLinks,
        private OAuthClient $oauth,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Begin the handshake: store state and send the user to Synaplan.
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

        if (!$this->synaplanConfig->isLinkAvailable() || $this->platformLinks->isWaitingForApproval()) {
            return new RedirectResponse($this->platformLinks->personalSettingsUrl([
                'link_error' => PlatformLinkException::CODE_INSTANCE_PENDING,
            ]));
        }

        $state = bin2hex(random_bytes(16));
        try {
            $authorize = $this->oauth->authorizeUrl(
                $user->getUID(),
                $state,
                $this->platformLinks->callbackUrl(),
                $this->synaplanConfig->isMemoriesEnabled(),
            );
        } catch (PlatformLinkException $e) {
            return new RedirectResponse($this->platformLinks->personalSettingsUrl([
                'link_error' => $e->getErrorCode(),
            ]));
        }
        $this->session->set(self::SESSION_STATE, $state);
        $this->session->set(self::SESSION_STATE_AT, time());
        $this->session->set(self::SESSION_UID, $user->getUID());
        $this->session->set(self::SESSION_VERIFIER, $authorize['verifier']);

        return new RedirectResponse($authorize['url']);
    }

    /**
     * Cross-site GET from Synaplan. `state` is the CSRF check.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function callback(): RedirectResponse
    {
        $code = trim((string) $this->request->getParam('code', ''));
        $state = trim((string) $this->request->getParam('state', ''));
        $user = $this->userSession->getUser();
        $uid = $user instanceof IUser ? $user->getUID() : '';
        $verifier = (string) $this->session->get(self::SESSION_VERIFIER);
        $this->session->remove(self::SESSION_VERIFIER);

        $error = $this->consumeState($state, $uid);
        if ($error !== null) {
            return new RedirectResponse($this->platformLinks->personalSettingsUrl([
                'link_error' => $error,
            ]));
        }

        if ($this->request->getParam('error') === 'access_denied') {
            return new RedirectResponse($this->platformLinks->personalSettingsUrl([
                'link_error' => PlatformLinkException::CODE_DENIED,
            ]));
        }

        if (!$user instanceof IUser || $code === '' || $verifier === '') {
            return new RedirectResponse($this->platformLinks->personalSettingsUrl([
                'link_error' => PlatformLinkException::CODE_EXCHANGE,
            ]));
        }

        try {
            $this->oauth->connect($user->getUID(), $code, $verifier, $this->platformLinks->callbackUrl());
            $this->userAccounts->storeLinkedAccount($user, $this->whoIs($user->getUID()));
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
     * Revoke the person's grant (which ends their link in Synaplan) and
     * forget local prefs.
     */
    #[NoAdminRequired]
    public function disconnect(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (!$user instanceof IUser) {
            return new JSONResponse(['success' => false], 401);
        }

        try {
            $this->platformLinks->disconnect($user);
        } catch (PlatformLinkException) {
            return new JSONResponse(['success' => false], 502);
        }

        return new JSONResponse(['success' => true]);
    }

    #[NoAdminRequired]
    public function status(): JSONResponse
    {
        return new JSONResponse($this->userAccounts->getLinkStatus());
    }

    /**
     * Who the person is in Synaplan, for the settings page. The connection
     * stands without it; the name shows once Synaplan answers.
     *
     * @return array{id?: int|string, email?: string}
     */
    private function whoIs(string $uid): array
    {
        try {
            return $this->platformLinks->whoAmI($uid);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Validate, then delete, the session state. Always single-use.
     * The initiating UID is bound to the state so a later login cannot
     * complete the first person's handshake.
     */
    private function consumeState(string $state, string $uid): ?string
    {
        $expected = (string) $this->session->get(self::SESSION_STATE);
        $storedAt = (int) $this->session->get(self::SESSION_STATE_AT);
        $expectedUid = (string) $this->session->get(self::SESSION_UID);
        $this->session->remove(self::SESSION_STATE);
        $this->session->remove(self::SESSION_STATE_AT);
        $this->session->remove(self::SESSION_UID);

        if ($state === '' || $expected === '' || $uid === '' || $expectedUid === '') {
            return PlatformLinkException::CODE_STATE;
        }
        if (!hash_equals($expected, $state) || !hash_equals($expectedUid, $uid)) {
            return PlatformLinkException::CODE_STATE;
        }
        if ($storedAt <= 0 || (time() - $storedAt) > self::STATE_TTL_SECONDS) {
            return PlatformLinkException::CODE_STATE;
        }

        return null;
    }
}
