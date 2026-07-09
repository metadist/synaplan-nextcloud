<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Resolves a per-user Synaplan API key for the current Nextcloud user.
 *
 * On first use for a user, this service uses the admin API key to:
 *   1. provision a Synaplan account for the NC user (idempotent on
 *      source="nextcloud" + external_id="<instanceId>:<uid>"), and
 *   2. mint a per-user API key on that account.
 *
 * The minted key is cached in the NC user's preferences (server-side only,
 * never exposed to the browser). All subsequent Synaplan calls made on behalf
 * of that user carry the per-user key, so each NC user acts ONLY on their own
 * Synaplan account (isolated knowledge base, memories, usage).
 *
 * Deliberately talks to Synaplan's admin API directly via IClientService (not
 * via SynaplanClient) to avoid a circular dependency: SynaplanClient depends on
 * this service to resolve the per-user key.
 */
class UserAccountService
{
    private const USER_KEY_PREF = 'synaplan_user_api_key';
    private const USER_ACCOUNT_ID_PREF = 'synaplan_user_id';
    private const CONSENT_PREF = 'ai_consent';
    private const CONSENT_AT_PREF = 'ai_consent_at';

    /** Scopes granted to a per-user key (see Synaplan CORE-3 scope vocabulary). */
    private const USER_KEY_SCOPES = ['chat', 'files', 'rag'];

    public function __construct(
        private IClientService $clientService,
        private IConfig $config,
        private IUserSession $userSession,
        private SynaplanConfig $synaplanConfig,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Resolve the API key to use for the current user.
     *
     * Returns null when per-user mode is off, when there is no logged-in user,
     * or when provisioning could not complete — the caller then falls back to
     * the install-wide admin key.
     */
    public function getCurrentUserApiKey(): ?string
    {
        if (!$this->synaplanConfig->isPerUserAccountsEnabled()) {
            return null;
        }

        $user = $this->userSession->getUser();
        if (!$user instanceof IUser) {
            return null;
        }

        $stored = $this->config->getUserValue($user->getUID(), Application::APP_ID, self::USER_KEY_PREF, '');
        if ($stored !== '') {
            return $stored;
        }

        // Consent gate: never provision a Synaplan account for a user who has
        // not explicitly activated AI. Without a key the caller does NOT fall
        // back to the shared admin key (see SynaplanClient::getApiKey), so the
        // request simply fails until the user consents.
        if (!$this->hasConsent($user)) {
            return null;
        }

        try {
            return $this->provisionAndMint($user);
        } catch (\Throwable $e) {
            $this->logger->error('Per-user Synaplan provisioning failed for {uid}: {message}', [
                'app' => Application::APP_ID,
                'uid' => $user->getUID(),
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Forget the current user's stored key (e.g. after a 401) so the next
     * request re-provisions and re-mints.
     */
    public function clearCurrentUserApiKey(): void
    {
        $user = $this->userSession->getUser();
        if ($user instanceof IUser) {
            $this->config->deleteUserValue($user->getUID(), Application::APP_ID, self::USER_KEY_PREF);
        }
    }

    /**
     * Whether the current user must give consent before AI is used.
     *
     * Consent is only meaningful in per-user mode (where using AI creates a
     * personal account on the Synaplan server). In shared-key mode there is no
     * per-user external account, so no per-user consent is required here.
     */
    public function consentRequired(): bool
    {
        return $this->synaplanConfig->isPerUserAccountsEnabled()
            && $this->userSession->getUser() instanceof IUser;
    }

    /**
     * Has the current user activated AI (granted consent)?
     */
    public function hasConsentForCurrentUser(): bool
    {
        $user = $this->userSession->getUser();

        return $user instanceof IUser && $this->hasConsent($user);
    }

    /**
     * Record the current user's consent to activate AI. Provisioning happens
     * lazily on the next Synaplan call (or callers may warm it immediately).
     */
    public function grantConsent(): void
    {
        $user = $this->userSession->getUser();
        if (!$user instanceof IUser) {
            return;
        }

        $this->config->setUserValue($user->getUID(), Application::APP_ID, self::CONSENT_PREF, '1');
        $this->config->setUserValue(
            $user->getUID(),
            Application::APP_ID,
            self::CONSENT_AT_PREF,
            (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)
        );

        $this->logger->info('User activated AI (consent granted): {uid}', [
            'app' => Application::APP_ID,
            'uid' => $user->getUID(),
        ]);
    }

    /**
     * Withdraw consent and forget the cached per-user key. The Synaplan account
     * itself is not deleted here (an admin can remove it server-side).
     */
    public function revokeConsent(): void
    {
        $user = $this->userSession->getUser();
        if (!$user instanceof IUser) {
            return;
        }

        $this->config->deleteUserValue($user->getUID(), Application::APP_ID, self::CONSENT_PREF);
        $this->config->deleteUserValue($user->getUID(), Application::APP_ID, self::CONSENT_AT_PREF);
        $this->config->deleteUserValue($user->getUID(), Application::APP_ID, self::USER_KEY_PREF);
    }

    private function hasConsent(IUser $user): bool
    {
        return $this->config->getUserValue($user->getUID(), Application::APP_ID, self::CONSENT_PREF, '') === '1';
    }

    private function provisionAndMint(IUser $user): ?string
    {
        $adminKey = $this->synaplanConfig->getAdminApiKey();
        if ($adminKey === '') {
            $this->logger->warning('Per-user mode enabled but no admin API key configured', [
                'app' => Application::APP_ID,
            ]);

            return null;
        }

        $externalId = $this->synaplanConfig->getInstanceId() . ':' . $user->getUID();
        $email = $user->getEMailAddress();
        if ($email === null || $email === '') {
            // Synaplan requires an email; synthesize a stable, unique one from
            // the NC uid + instance when the user has none set.
            $email = $user->getUID() . '@' . $this->synaplanConfig->getInstanceId() . '.nextcloud.local';
        }

        $account = $this->adminRequest('POST', '/api/v1/admin/users', [
            'source' => 'nextcloud',
            'external_id' => $externalId,
            'email' => $email,
            'display_name' => $user->getDisplayName(),
        ], $adminKey);

        $synaplanUserId = (int) ($account['user']['id'] ?? 0);
        if ($synaplanUserId <= 0) {
            throw new \RuntimeException('Provisioning response missing user id');
        }

        $minted = $this->adminRequest('POST', '/api/v1/admin/users/' . $synaplanUserId . '/api-keys', [
            'name' => 'nextcloud-' . $user->getUID(),
            'scopes' => self::USER_KEY_SCOPES,
        ], $adminKey);

        $userKey = (string) ($minted['api_key']['key'] ?? '');
        if ($userKey === '') {
            throw new \RuntimeException('Minting response missing api key');
        }

        $this->config->setUserValue($user->getUID(), Application::APP_ID, self::USER_KEY_PREF, $userKey);
        $this->config->setUserValue($user->getUID(), Application::APP_ID, self::USER_ACCOUNT_ID_PREF, (string) $synaplanUserId);

        $this->logger->info('Provisioned per-user Synaplan account for {uid} (synaplan id {sid})', [
            'app' => Application::APP_ID,
            'uid' => $user->getUID(),
            'sid' => $synaplanUserId,
        ]);

        return $userKey;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function adminRequest(string $method, string $path, array $body, string $adminKey): array
    {
        $client = $this->clientService->newClient();
        $url = $this->synaplanConfig->getBaseUrl() . $path;

        $options = [
            'headers' => [
                'X-API-Key' => $adminKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($body),
            'timeout' => 30,
        ];

        $response = $method === 'POST' ? $client->post($url, $options) : $client->get($url, $options);
        $decoded = json_decode($response->getBody(), true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON response from Synaplan admin API');
        }

        return $decoded;
    }
}
