<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\IConfig;

/**
 * Central accessor for the Synaplan integration configuration.
 *
 * Extracted from SynaplanClient so that both the HTTP client AND the per-user
 * account service can read the base URL / admin key without a circular
 * dependency between them.
 *
 * The "admin API key" is the single install-wide key an admin configures. In
 * per-user mode it is used ONLY to provision accounts and mint per-user keys
 * via Synaplan's admin API — end-user traffic uses the per-user key instead
 * (see UserAccountService).
 */
class SynaplanConfig
{
    public const MODE_SHARED = 'shared';
    public const MODE_PROVISION = 'provision';
    public const MODE_LINK = 'link';

    public function __construct(
        private IConfig $config,
    ) {
    }

    /**
     * Active backend environment: 'live' (production) or 'local' (development).
     */
    public function getActiveEnv(): string
    {
        return $this->config->getAppValue(Application::APP_ID, 'active_env', 'live') === 'local'
            ? 'local'
            : 'live';
    }

    public function getBaseUrl(): string
    {
        if ($this->getActiveEnv() === 'local') {
            return rtrim(
                $this->config->getAppValue(Application::APP_ID, 'synaplan_url_local', 'http://localhost:8000'),
                '/'
            );
        }

        return rtrim(
            $this->config->getAppValue(Application::APP_ID, 'synaplan_url', 'http://localhost:8000'),
            '/'
        );
    }

    /**
     * Origin the user's browser opens for /connect/platform.
     *
     * Falls back to getBaseUrl() when unset. Needed when Nextcloud talks to
     * Synaplan on an internal hostname (Docker `http://backend`) that the
     * browser cannot resolve.
     */
    public function getPublicBaseUrl(): string
    {
        $key = $this->getActiveEnv() === 'local'
            ? 'synaplan_public_url_local'
            : 'synaplan_public_url';
        $public = rtrim((string) $this->config->getAppValue(Application::APP_ID, $key, ''), '/');

        return $public !== '' ? $public : $this->getBaseUrl();
    }

    /**
     * The install-wide key configured by the admin. In per-user mode this is
     * an ADMIN key (used for provisioning); otherwise it is the shared key that
     * every request uses directly.
     */
    public function getAdminApiKey(): string
    {
        if ($this->getActiveEnv() === 'local') {
            return $this->config->getAppValue(Application::APP_ID, 'api_key_local', '');
        }

        return $this->config->getAppValue(Application::APP_ID, 'api_key', '');
    }

    /**
     * When enabled, each Nextcloud user gets their own Synaplan account and
     * per-user API key (isolated knowledge base, memories, usage). When
     * disabled (default, backward-compatible), all traffic uses the single
     * install-wide key.
     *
     * Link mode also counts as per-user: the key is the user's linked key,
     * never the shared admin key.
     */
    public function isPerUserAccountsEnabled(): bool
    {
        return $this->getMode() !== self::MODE_SHARED;
    }

    /**
     * Admin-chosen integration mode. Unset `mode` falls back to the legacy
     * `per_user_accounts` flag so 1.5 installs keep working after upgrade.
     */
    public function getMode(): string
    {
        $mode = (string) $this->config->getAppValue(Application::APP_ID, 'mode', '');
        if ($mode === self::MODE_LINK || $mode === self::MODE_PROVISION || $mode === self::MODE_SHARED) {
            return $mode;
        }

        return $this->isPerUserAccountsStored() ? self::MODE_PROVISION : self::MODE_SHARED;
    }

    public function isLinkMode(): bool
    {
        return $this->getMode() === self::MODE_LINK;
    }

    /**
     * Link mode with a stored instance registration (id + secret).
     */
    public function isLinkAvailable(): bool
    {
        return $this->isLinkMode()
            && $this->getLinkInstanceId() !== ''
            && $this->getLinkInstanceSecretCipher() !== '';
    }

    /**
     * Auto-create a Synaplan account for users who have none. Admin opt-in;
     * requires the admin API key (decision §11.3).
     */
    public function isAutoProvisionEnabled(): bool
    {
        return $this->isLinkMode()
            && $this->config->getAppValue(Application::APP_ID, 'link_auto_provision', '0') === '1'
            && $this->getAdminApiKey() !== '';
    }

    public function getLinkInstanceId(): string
    {
        return (string) $this->config->getAppValue(Application::APP_ID, 'link_instance_id', '');
    }

    /**
     * Encrypted instance secret (ICrypto). Decrypt in PlatformLinkService.
     */
    public function getLinkInstanceSecretCipher(): string
    {
        return (string) $this->config->getAppValue(Application::APP_ID, 'link_instance_secret', '');
    }

    public function isMemoriesEnabled(): bool
    {
        return $this->config->getAppValue(Application::APP_ID, 'enable_memories', '1') === '1';
    }

    /**
     * Raw legacy flag — used only as the fallback when `mode` is unset.
     */
    private function isPerUserAccountsStored(): bool
    {
        return $this->config->getAppValue(Application::APP_ID, 'per_user_accounts', '0') === '1';
    }

    /**
     * Stable identifier for THIS Nextcloud instance — used to namespace the
     * external_id sent to Synaplan so two Nextcloud installs pointing at the
     * same Synaplan never collide.
     */
    public function getInstanceId(): string
    {
        return $this->config->getSystemValueString('instanceid', 'nextcloud');
    }
}
