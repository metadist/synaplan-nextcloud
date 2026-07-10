<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Controller;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Per-user "Activate AI" consent.
 *
 * In per-user mode, using AI provisions a personal account on the Synaplan
 * server for the Nextcloud user. We do not create that external account
 * silently — the user must activate AI once. This controller is deliberately
 * small and self-contained so the same pattern can be lifted into other cloud
 * apps (ownCloud, …) that integrate Synaplan.
 */
class ConsentController extends Controller
{
    public function __construct(
        IRequest $request,
        private UserAccountService $userAccounts,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Current consent state for the logged-in user.
     *
     * @NoAdminRequired
     */
    public function getConsent(): JSONResponse
    {
        return new JSONResponse([
            'required' => $this->userAccounts->consentRequired(),
            'granted' => $this->userAccounts->hasConsentForCurrentUser(),
        ]);
    }

    /**
     * Grant or withdraw consent to activate AI.
     *
     * @NoAdminRequired
     */
    public function setConsent(bool $granted = false): JSONResponse
    {
        // Nextcloud does not always bind a JSON body to typed params; re-read
        // the raw body as a fallback so a `{ "granted": true }` payload works.
        $body = file_get_contents('php://input');
        if ($body !== false && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && array_key_exists('granted', $decoded)) {
                $granted = (bool) $decoded['granted'];
            }
        }

        if ($granted) {
            $this->userAccounts->grantConsent();
        } else {
            $this->userAccounts->revokeConsent();
        }

        return new JSONResponse([
            'success' => true,
            'required' => $this->userAccounts->consentRequired(),
            'granted' => $this->userAccounts->hasConsentForCurrentUser(),
        ]);
    }
}
