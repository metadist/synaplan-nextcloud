<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\BackgroundJob;

use OCA\SynaplanIntegration\Service\NotifyKeyCache;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Reads Synaplan's signing keys once an hour. A notification never does this.
 */
class RefreshNotifyKeysJob extends TimedJob
{
    public function __construct(
        ITimeFactory $time,
        private NotifyKeyCache $keys,
    ) {
        parent::__construct($time);
        $this->setInterval(3600);
    }

    protected function run(mixed $argument): void
    {
        $this->keys->refresh();
    }
}
