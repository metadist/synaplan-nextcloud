<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\BackgroundJob;

use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCA\SynaplanIntegration\Service\WatchFeed;
use OCA\SynaplanIntegration\Service\WatchReconcile;
use OCA\SynaplanIntegration\Service\WatchSync;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Every cron run: bring the watch index up to date, give new watches their
 * first pass, and send the files that have been quiet for the quiet period.
 * One run stops starting new work after eight minutes.
 */
class WatchFeedJob extends TimedJob
{
    private const BUDGET_SECONDS = 480;

    public function __construct(
        ITimeFactory $time,
        private SynaplanConfig $synaplanConfig,
        private WatchSync $sync,
        private WatchReconcile $reconcile,
        private WatchFeed $feed,
    ) {
        parent::__construct($time);
        $this->setInterval(60);
        $this->setTimeSensitivity(IJob::TIME_SENSITIVE);
    }

    protected function run(mixed $argument): void
    {
        if (!$this->synaplanConfig->isRegistered()) {
            return;
        }
        $deadline = microtime(true) + self::BUDGET_SECONDS;
        $now = $this->time->getTime();
        $this->sync->run($now);
        $this->reconcile->run($now, $deadline, true);
        $this->feed->run($this->time->getTime(), $deadline);
    }
}
