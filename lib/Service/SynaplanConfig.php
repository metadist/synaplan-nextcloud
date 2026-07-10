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
     */
    public function isPerUserAccountsEnabled(): bool
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
