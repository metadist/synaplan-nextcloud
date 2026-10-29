<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Exception\EmailConflictException;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Resolves a per-user Synaplan API key for the current Nextcloud user.
 *
 * On first use for a user, this service uses the admin API key to:
 *   1. provision a Synaplan account for the NC user, and
 *   2. mint a per-user API key on that account.
 *
 * Once this Nextcloud is registered, both calls also send the instance
 * credential and the bare Nextcloud user id, so Synaplan stores the
 * canonical identity row. Without a registration the external id stays
 * "<instanceId>:<uid>", as older releases sent it.
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
    public const USER_KEY_PREF = KeyStorage::USER_KEY;
    public const USER_ACCOUNT_ID_PREF = 'synaplan_user_id';
    public const USER_KEY_ID_PREF = 'synaplan_user_key_id';
    /** Scopes of the stored key. Empty means a key minted before watching existed. */
    public const KEY_SCOPES_PREF = 'synaplan_key_scopes';
    public const CONSENT_PREF = 'ai_consent';
    public const CONSENT_AT_PREF = 'ai_consent_at';
    public const LINK_KIND_PREF = 'synaplan_link_kind';
    public const LINK_EMAIL_PREF = 'synaplan_link_email';
    public const LINKED_AT_PREF = 'synaplan_linked_at';
    public const LINK_ID_PREF = 'synaplan_link_id';
    /** Durable: survives disconnect / 401 so a removed linked person is reported, not dropped silently. */
    public const LINK_ORIGIN_PREF = 'synaplan_link_origin';

    public const KIND_LINKED = 'linked';
    public const KIND_PROVISIONED = 'provisioned';
    public const SOURCE = 'nextcloud';

    /** Scopes granted to a per-user key (see Synaplan CORE-3 scope vocabulary). */
    private const USER_KEY_SCOPES = ['chat', 'files', 'rag', 'cloud:watch'];

    public function __construct(
        private IClientService $clientService,
        private IConfig $config,
        private IUserSession $userSession,
        private SynaplanConfig $synaplanConfig,
        private LoggerInterface $logger,
        private KeyStorage $keys,
        private ICrypto $crypto,
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
        $user = $this->userSession->getUser();
        if (!$user instanceof IUser) {
            return null;
        }

        return $this->resolveKeyForUser($user);
    }

    /**
     * Resolve the Synaplan API key for a Nextcloud user.
     *
     * shared → null (caller uses the install-wide key).
     * provision → stored key, or provision+mint after consent.
     * link → stored key or null — never provisions implicitly.
     */
    public function resolveKeyForUser(IUser $user): ?string
    {
        if ($this->synaplanConfig->getMode() === SynaplanConfig::MODE_SHARED) {
            return null;
        }

        $stored = $this->storedKey($user->getUID());
        if ($stored !== '') {
            return $stored;
        }

        if ($this->synaplanConfig->isLinkMode()) {
            return null;
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
     * Forget link-mode prefs for the current user (401 on a revoked linked key).
     */
    public function clearLinkPrefs(): void
    {
        $user = $this->userSession->getUser();
        if ($user instanceof IUser) {
            $this->clearLinkPrefsForUid($user->getUID());
        }
    }

    public function clearLinkPrefsForUid(string $uid): void
    {
        foreach ([
            self::LINK_KIND_PREF,
            self::LINK_EMAIL_PREF,
            self::LINKED_AT_PREF,
            self::LINK_ID_PREF,
            self::USER_KEY_ID_PREF,
            self::CONSENT_PREF,
            self::CONSENT_AT_PREF,
        ] as $key) {
            $this->config->deleteUserValue($uid, Application::APP_ID, $key);
        }
    }

    /**
     * Persist a successful handshake. A link is consent.
     *
     * @param array{api_key: array{id?: int|string, key?: string}, user: array{id?: int|string, email?: string}, link_id?: int|string} $exchange
     */
    public function storeLinkedAccount(IUser $user, array $exchange): void
    {
        $uid = $user->getUID();
        $key = (string) ($exchange['api_key']['key'] ?? '');
        $keyId = (string) ($exchange['api_key']['id'] ?? '');
        $synaplanUserId = (string) ($exchange['user']['id'] ?? '');
        $email = (string) ($exchange['user']['email'] ?? '');
        $linkId = (string) ($exchange['link_id'] ?? '');
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        if ($key !== '') {
            $this->keys->setUserKey($uid, $key);
        }
        if ($keyId !== '') {
            $this->config->setUserValue($uid, Application::APP_ID, self::USER_KEY_ID_PREF, $keyId);
        }
        if ($synaplanUserId !== '') {
            $this->config->setUserValue($uid, Application::APP_ID, self::USER_ACCOUNT_ID_PREF, $synaplanUserId);
        }
        $this->config->setUserValue($uid, Application::APP_ID, self::LINK_KIND_PREF, self::KIND_LINKED);
        $this->config->setUserValue($uid, Application::APP_ID, self::LINK_ORIGIN_PREF, self::KIND_LINKED);
        $this->config->setUserValue($uid, Application::APP_ID, self::LINK_EMAIL_PREF, $email);
        $this->config->setUserValue($uid, Application::APP_ID, self::LINKED_AT_PREF, $now);
        if ($linkId !== '') {
            $this->config->setUserValue($uid, Application::APP_ID, self::LINK_ID_PREF, $linkId);
        }
        $this->config->setUserValue($uid, Application::APP_ID, self::CONSENT_PREF, '1');
        $this->config->setUserValue($uid, Application::APP_ID, self::CONSENT_AT_PREF, $now);
    }

    /**
     * @return 'linked'|'provisioned'|null
     */
    public function getLinkKind(string $uid): ?string
    {
        // Read the key first: a key that no longer decrypts takes the link with it.
        $key = $this->storedKey($uid);
        $kind = $this->config->getUserValue($uid, Application::APP_ID, self::LINK_KIND_PREF, '');
        if ($kind === self::KIND_LINKED || $kind === self::KIND_PROVISIONED) {
            return $kind;
        }

        return $key !== '' ? self::KIND_PROVISIONED : null;
    }

    /**
     * True when this Nextcloud user ever completed a Synaplan link handshake.
     * Survives disconnect so user-deletion never calls deleteRemoteAccount.
     */
    public function wasLinked(string $uid): bool
    {
        return $this->config->getUserValue($uid, Application::APP_ID, self::LINK_ORIGIN_PREF, '') === self::KIND_LINKED;
    }

    /**
     * Status payload for the personal gate and `link#status`.
     *
     * @return array{mode: string, link_available: bool, auto_provision: bool, linked: array{email: string, since: string}|null, kind: 'linked'|'provisioned'|null}
     */
    public function getLinkStatus(): array
    {
        $user = $this->userSession->getUser();
        $uid = $user instanceof IUser ? $user->getUID() : '';
        $kind = $uid !== '' ? $this->getLinkKind($uid) : null;
        $email = $uid !== '' ? $this->config->getUserValue($uid, Application::APP_ID, self::LINK_EMAIL_PREF, '') : '';
        $since = $uid !== '' ? $this->config->getUserValue($uid, Application::APP_ID, self::LINKED_AT_PREF, '') : '';
        if ($since === '' && $uid !== '') {
            $since = $this->config->getUserValue($uid, Application::APP_ID, self::CONSENT_AT_PREF, '');
        }
        $linked = null;
        if ($kind === self::KIND_LINKED) {
            $linked = [
                'email' => $email !== '' ? $email : (string) $user->getEMailAddress(),
                'since' => $since,
            ];
        }

        return [
            'mode' => $this->synaplanConfig->getMode(),
            'link_available' => $this->synaplanConfig->isLinkAvailable(),
            'auto_provision' => $this->synaplanConfig->isAutoProvisionEnabled(),
            'linked' => $linked,
            'kind' => $kind,
        ];
    }

    /**
     * Create a Synaplan account from the two-option gate ("Create one for me").
     *
     * @throws EmailConflictException
     */
    public function provisionForLinkMode(IUser $user): ?string
    {
        if (!$this->synaplanConfig->isAutoProvisionEnabled()) {
            return null;
        }

        $key = $this->provisionAndMint($user);
        if ($key !== null) {
            $uid = $user->getUID();
            $this->config->setUserValue($uid, Application::APP_ID, self::LINK_KIND_PREF, self::KIND_PROVISIONED);
            if (!$this->wasLinked($uid)) {
                $this->config->setUserValue($uid, Application::APP_ID, self::LINK_ORIGIN_PREF, self::KIND_PROVISIONED);
            }
        }

        return $key;
    }

    /**
     * Provision + mint without the auto-provision gate (tests / consent path).
     *
     * @throws EmailConflictException
     */
    public function provisionAccount(IUser $user): ?string
    {
        return $this->provisionAndMint($user);
    }

    public function getStoredApiKey(string $uid): string
    {
        return $this->storedKey($uid);
    }

    public function getStoredApiKeyId(string $uid): string
    {
        return $this->config->getUserValue($uid, Application::APP_ID, self::USER_KEY_ID_PREF, '');
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
        $this->clearLinkPrefsForUid($user->getUID());
    }

    /**
     * The person's stored key, '' when there is none. A key that no longer
     * decrypts is gone: in link mode the link goes with it, so the person sees
     * Connect again; in provision mode the next request provisions a new key.
     */
    private function storedKey(string $uid): string
    {
        $key = $this->keys->getUserKey($uid);
        if ($key === null && $this->synaplanConfig->isLinkMode()) {
            $this->clearLinkPrefsForUid($uid);
        }

        return $key ?? '';
    }

    private function hasConsent(IUser $user): bool
    {
        return $this->config->getUserValue($user->getUID(), Application::APP_ID, self::CONSENT_PREF, '') === '1';
    }

    // ---- Admin operations on arbitrary users (used by the admin panel) ----

    /**
     * The Synaplan account id provisioned for a given Nextcloud user, if any.
     */
    public function getSynaplanUserId(string $uid): ?int
    {
        $id = $this->config->getUserValue($uid, Application::APP_ID, self::USER_ACCOUNT_ID_PREF, '');

        return $id !== '' ? (int) $id : null;
    }

    /**
     * Synaplan removed this link. Drop the stored key for whoever holds it.
     */
    public function revokeLinkedKey(string $linkId): void
    {
        if ($linkId === '') {
            return;
        }
        foreach ($this->config->getUsersForUserValue(Application::APP_ID, self::LINK_ID_PREF, $linkId) as $uid) {
            if ($uid !== '') {
                $this->deactivateUser($uid);
            }
        }
    }

    /**
     * Admin action: deactivate AI for a specific user — withdraw their consent
     * and forget their cached key. The Synaplan account itself is left intact
     * (an admin can delete it server-side); the next time the user activates AI
     * a fresh key is minted.
     */
    public function deactivateUser(string $uid): void
    {
        $this->config->deleteUserValue($uid, Application::APP_ID, self::CONSENT_PREF);
        $this->config->deleteUserValue($uid, Application::APP_ID, self::CONSENT_AT_PREF);
        $this->config->deleteUserValue($uid, Application::APP_ID, self::USER_KEY_PREF);
        $this->clearLinkPrefsForUid($uid);

        $this->logger->info('Admin deactivated AI for user {uid}', [
            'app' => Application::APP_ID,
            'uid' => $uid,
        ]);
    }

    /**
     * Fetch per-user usage from Synaplan (admin API) for a provisioned account.
     *
     * @return array<string, mixed>
     */
    public function fetchUsage(int $synaplanUserId): array
    {
        $adminKey = $this->synaplanConfig->getAdminApiKey();
        if ($adminKey === '') {
            return [];
        }

        return $this->adminRequest('GET', '/api/v1/admin/users/' . $synaplanUserId . '/usage', null, $adminKey);
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

        $credential = $this->instanceHeaders();
        $externalId = $credential === []
            ? $this->synaplanConfig->getInstanceId() . ':' . $user->getUID()
            : $user->getUID();
        $email = $user->getEMailAddress();
        if ($email === null || $email === '') {
            // Synaplan requires an email; synthesize a stable, unique one from
            // the NC uid + instance when the user has none set.
            $email = $user->getUID() . '@' . $this->synaplanConfig->getInstanceId() . '.nextcloud.local';
        }

        try {
            $account = $this->adminRequest('POST', '/api/v1/admin/users', [
                'source' => self::SOURCE,
                'external_id' => $externalId,
                'email' => $email,
                'display_name' => $user->getDisplayName(),
            ], $adminKey, $credential);
        } catch (\Throwable $e) {
            if ($this->isConflict($e)) {
                throw new EmailConflictException();
            }
            throw $e;
        }

        $synaplanUserId = (int) ($account['user']['id'] ?? 0);
        if ($synaplanUserId <= 0) {
            throw new \RuntimeException('Provisioning response missing user id');
        }

        $minted = $this->adminRequest('POST', '/api/v1/admin/users/' . $synaplanUserId . '/api-keys', [
            'name' => 'nextcloud-' . $user->getUID(),
            'scopes' => self::USER_KEY_SCOPES,
        ], $adminKey, $credential);

        $userKey = (string) ($minted['api_key']['key'] ?? '');
        if ($userKey === '') {
            throw new \RuntimeException('Minting response missing api key');
        }

        $this->keys->setUserKey($user->getUID(), $userKey);
        $this->config->setUserValue($user->getUID(), Application::APP_ID, self::USER_ACCOUNT_ID_PREF, (string) $synaplanUserId);
        $this->rememberKeyScopes($user->getUID());
        $mintedKeyId = (string) ($minted['api_key']['id'] ?? '');
        if ($mintedKeyId !== '') {
            $this->config->setUserValue($user->getUID(), Application::APP_ID, self::USER_KEY_ID_PREF, $mintedKeyId);
        }

        $this->logger->info('Provisioned per-user Synaplan account for {uid} (synaplan id {sid})', [
            'app' => Application::APP_ID,
            'uid' => $user->getUID(),
            'sid' => $synaplanUserId,
        ]);

        return $userKey;
    }

    /**
     * Replace a provisioned key that was minted before watching, without
     * withdrawing consent. A linked key is left alone: connecting again is
     * what gives that person the new permission. Returns the new key, or null
     * when there is nothing to replace.
     */
    public function renewProvisionedKey(): ?string
    {
        if ($this->synaplanConfig->getMode() !== SynaplanConfig::MODE_PROVISION) {
            return null;
        }
        $user = $this->userSession->getUser();
        if (!$user instanceof IUser || !$this->hasConsent($user) || $this->keyIncludesWatch($user->getUID())) {
            return null;
        }
        $uid = $user->getUID();
        $oldKey = $this->storedKey($uid);
        if ($oldKey === '') {
            return null;
        }
        $oldKeyId = $this->getStoredApiKeyId($uid);

        try {
            $minted = $this->provisionAndMint($user);
        } catch (\Throwable $e) {
            $this->logger->error('Could not renew the Synaplan key for {uid}: {message}', [
                'app' => Application::APP_ID,
                'uid' => $uid,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
        $this->revokeReplacedKey($oldKey, $oldKeyId);

        return $minted;
    }

    private function keyIncludesWatch(string $uid): bool
    {
        return str_contains(
            $this->config->getUserValue($uid, Application::APP_ID, self::KEY_SCOPES_PREF, ''),
            'cloud:watch',
        );
    }

    private function rememberKeyScopes(string $uid): void
    {
        $this->config->setUserValue(
            $uid,
            Application::APP_ID,
            self::KEY_SCOPES_PREF,
            implode(' ', self::USER_KEY_SCOPES),
        );
    }

    /**
     * The key just replaced can still call Synaplan until it is revoked.
     * Failure here keeps the new key: watching works, and the old key expires
     * when the person turns AI off.
     */
    private function revokeReplacedKey(string $oldKey, string $oldKeyId): void
    {
        if ($oldKey === '' || !ctype_digit($oldKeyId)) {
            return;
        }
        try {
            $id = $oldKeyId;
            $this->adminRequest('DELETE', '/api/v1/apikeys/' . $id, null, $oldKey);
        } catch (\Throwable $e) {
            $this->logger->info('The previous Synaplan key could not be revoked: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Instance credential when this Nextcloud is registered, otherwise none.
     * Provisioning then keeps the legacy external id.
     *
     * @return array<string, string>
     */
    private function instanceHeaders(): array
    {
        $id = $this->synaplanConfig->getLinkInstanceId();
        $cipher = $this->synaplanConfig->getLinkInstanceSecretCipher();
        if ($id === '' || $cipher === '') {
            return [];
        }
        try {
            $secret = $this->crypto->decrypt($cipher);
        } catch (\Throwable) {
            $secret = $cipher;
        }
        if ($secret === '') {
            return [];
        }

        return [
            'X-Instance-Id' => $id,
            'X-Instance-Secret' => $secret,
        ];
    }

    /**
     * @param array<string, mixed>|null $body Request body for POST; null for GET.
     * @param array<string, string> $extraHeaders
     * @return array<string, mixed>
     */
    private function adminRequest(string $method, string $path, ?array $body, string $adminKey, array $extraHeaders = []): array
    {
        $client = $this->clientService->newClient();
        $url = $this->synaplanConfig->getBaseUrl() . $path;

        $headers = [
            'X-API-Key' => $adminKey,
            'Accept' => 'application/json',
            ...$extraHeaders,
        ];
        $options = [
            'headers' => $headers,
            'timeout' => 30,
            'nextcloud' => [
                'allow_local_address' => true,
            ],
        ];

        if ($body !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = json_encode($body);
        }

        $response = match ($method) {
            'POST' => $client->post($url, $options),
            'DELETE' => $client->delete($url, $options),
            default => $client->get($url, $options),
        };
        $status = (int) $response->getStatusCode();
        if ($status === 409) {
            throw new EmailConflictException();
        }
        if ($status >= 400) {
            throw new \RuntimeException('Synaplan admin API returned HTTP ' . $status);
        }

        $decoded = json_decode($response->getBody(), true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON response from Synaplan admin API');
        }

        return $decoded;
    }

    private function isConflict(\Throwable $e): bool
    {
        if ($e instanceof EmailConflictException) {
            return true;
        }
        if ((int) $e->getCode() === 409) {
            return true;
        }
        if (method_exists($e, 'getResponse')) {
            $response = $e->getResponse();
            if (is_object($response) && method_exists($response, 'getStatusCode')
                && (int) $response->getStatusCode() === 409) {
                return true;
            }
        }

        return (bool) preg_match('/\b409\b/', $e->getMessage());
    }
}
