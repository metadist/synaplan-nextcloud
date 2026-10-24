<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\BackgroundJob;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Service\NotifyKeyCache;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\ReadScope;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;

/**
 * Once an hour: read Synaplan's signing keys, tell Synaplan again where
 * copies and folder reads are delivered and what this app can do, and send
 * read settings whose first report never arrived. The re-announce is how an
 * already-registered instance picks up new capabilities after an app
 * update; a failed run is simply retried the next hour. A notification
 * never does any of this.
 */
class RefreshNotifyKeysJob extends TimedJob
{
    public function __construct(
        ITimeFactory $time,
        private NotifyKeyCache $keys,
        private PlatformLinkService $links,
        private SynaplanConfig $synaplanConfig,
        private IConfig $config,
        private ReadScope $scope,
    ) {
        parent::__construct($time);
        $this->setInterval(3600);
    }

    protected function run(mixed $argument): void
    {
        $this->keys->refresh();
        if ($this->synaplanConfig->getLinkInstanceId() !== '') {
            try {
                $url = $this->links->defaultDeliveryUrl();
                if ($url !== '') {
                    $this->links->announceDelivery($url);
                }
            } catch (\Throwable) {
                // Next hour tries again.
            }
        }
        foreach ($this->config->getUsersForUserValue(Application::APP_ID, ReadScope::PENDING, '1') as $uid) {
            $this->scope->republish($uid);
        }
    }
}
