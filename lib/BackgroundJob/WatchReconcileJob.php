<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\BackgroundJob;

use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCA\SynaplanIntegration\Service\WatchReconcile;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Reconciles each active watch once per interval (an hour by default, the
 * app value watch_reconcile_minutes, 15 to 1440). The job wakes every 15
 * minutes; each watch carries when it was last reconciled.
 */
class WatchReconcileJob extends TimedJob
{
    private const BUDGET_SECONDS = 480;

    public function __construct(
        ITimeFactory $time,
        private SynaplanConfig $synaplanConfig,
        private WatchReconcile $reconcile,
    ) {
        parent::__construct($time);
        $this->setInterval(900);
    }

    protected function run(mixed $argument): void
    {
        if (!$this->synaplanConfig->isRegistered()) {
            return;
        }
        $this->reconcile->run($this->time->getTime(), microtime(true) + self::BUDGET_SECONDS, false);
    }
}
