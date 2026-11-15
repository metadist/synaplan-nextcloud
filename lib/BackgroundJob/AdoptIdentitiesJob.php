<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\BackgroundJob;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;

/**
 * Kept so an install that already scheduled this job does not fatal.
 * Adopting legacy identity rows belonged to the instance-secret protocol.
 * A cloud is now an OAuth client, and a person's link is created when they
 * allow it. One pass marks the job finished.
 */
class AdoptIdentitiesJob extends TimedJob
{
    public const DONE = 'identities_adopted';

    public function __construct(
        ITimeFactory $time,
        private IConfig $config,
    ) {
        parent::__construct($time);
        $this->setInterval(3600);
    }

    protected function run(mixed $argument): void
    {
        if ($this->config->getAppValue(Application::APP_ID, self::DONE, '0') === '1') {
            return;
        }
        $this->config->setAppValue(Application::APP_ID, self::DONE, '1');
    }
}
