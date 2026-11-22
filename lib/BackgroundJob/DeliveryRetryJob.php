<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\BackgroundJob;

use OCA\SynaplanIntegration\Service\CopyDelivery;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Claims a copy again after the claim in the notification request failed,
 * so the answer "still copying" is followed by a result.
 */
class DeliveryRetryJob extends QueuedJob
{
    public function __construct(
        ITimeFactory $time,
        private CopyDelivery $copies,
    ) {
        parent::__construct($time);
    }

    protected function run(mixed $argument): void
    {
        if (!is_array($argument)) {
            return;
        }
        $id = (string) ($argument['id'] ?? '');
        $uid = (string) ($argument['uid'] ?? '');
        if ($id !== '' && $uid !== '') {
            $this->copies->retry($id, $uid, (int) ($argument['since'] ?? 0));
        }
    }
}
