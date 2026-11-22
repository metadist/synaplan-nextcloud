<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\BackgroundJob;

use OCA\SynaplanIntegration\Service\FolderRead;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Finishes a folder read that did not fit in the notification request.
 * FolderRead keeps the uploads still owed; a job queued by an older version
 * carries them in its arguments.
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
        if (!is_array($argument)) {
            return;
        }
        $id = (string) ($argument['id'] ?? '');
        unset($argument['id']);
        if ($id !== '') {
            $this->reads->finish($id, isset($argument['uid']) ? $argument : null);
        }
    }
}
