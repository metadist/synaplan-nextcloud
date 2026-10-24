<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\BackgroundJob;

use OCA\SynaplanIntegration\Service\FolderRead;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Finishes a folder read that did not fit in the notification request.
 */
class PickupJob extends QueuedJob
{
    public function __construct(
        ITimeFactory $time,
        private FolderRead $reads,
    ) {
        parent::__construct($time);
    }

    protected function run(mixed $argument): void
    {
        $id = is_array($argument) ? (string) ($argument['id'] ?? '') : '';
        if ($id !== '') {
            $this->reads->finish($id);
        }
    }
}
