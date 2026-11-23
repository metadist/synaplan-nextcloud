<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\BackgroundJob;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IUserManager;

/**
 * Once this Nextcloud is registered, bring every account provisioned before
 * the registration onto its client (`02` §3.1), so deleting or pausing those
 * people reaches Synaplan. A pass that leaves nobody to retry is the last.
 */
class AdoptIdentitiesJob extends TimedJob
{
    public const DONE = 'legacy_accounts_adopted';

    private const PAGE = 200;

    public function __construct(
        ITimeFactory $time,
        private IConfig $config,
        private SynaplanConfig $synaplanConfig,
        private UserAccountService $userAccounts,
        private IUserManager $users,
    ) {
        parent::__construct($time);
        $this->setInterval(3600);
    }

    protected function run(mixed $argument): void
    {
        if ($this->config->getAppValue(Application::APP_ID, self::DONE, '0') === '1') {
            return;
        }
        if ($this->synaplanConfig->getLinkInstanceId() === '' || $this->synaplanConfig->getAdminApiKey() === '') {
            return;
        }
        $clean = true;
        $offset = 0;
        do {
            $batch = $this->users->search('', self::PAGE, $offset);
            foreach ($batch as $user) {
                if (!$this->userAccounts->adoptLegacyAccount($user->getUID())) {
                    $clean = false;
                }
            }
            $count = count($batch);
            $offset += $count;
        } while ($count === self::PAGE);
        if ($clean) {
            $this->config->setAppValue(Application::APP_ID, self::DONE, '1');
        }
    }
}
