<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\BackgroundJob;

use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;

/**
 * Reports a file change, move, deletion or restore to Synaplan and queues a
 * re-upload for each copy that is kept up to date.
 */
class SourceEventsJob extends QueuedJob
{
    public function __construct(
        ITimeFactory $time,
        private PlatformLinkService $links,
        private IJobList $jobs,
    ) {
        parent::__construct($time);
    }

    protected function run(mixed $argument): void
    {
        if (!is_array($argument)) {
            return;
        }
        $id = is_string($argument['id'] ?? null) ? $argument['id'] : '';
        $event = is_string($argument['event'] ?? null) ? $argument['event'] : '';
        $version = is_string($argument['version'] ?? null) ? $argument['version'] : '';
        $path = is_string($argument['path'] ?? null) ? $argument['path'] : '';
        $attempts = is_int($argument['attempts'] ?? null) ? $argument['attempts'] : 0;
        $uid = is_string($argument['uid'] ?? null) ? $argument['uid'] : '';
        if ($id === '' || $event === '' || $uid === '') {
            return;
        }

        $answer = $this->links->reportSourceEvents([[
            'id' => $id,
            'event' => $event,
            'version' => $version,
            'path' => $path,
        ]], $uid);
        if (($answer['retry'] ?? false) === true && $attempts < 3) {
            $this->jobs->add(self::class, [
                'uid' => $uid,
                'id' => $id,
                'event' => $event,
                'version' => $version,
                'path' => $path,
                'attempts' => $attempts + 1,
            ]);

            return;
        }
        foreach ($answer['reupload'] as $entry) {
            $this->jobs->add(ReuploadJob::class, [
                'uid' => $entry['external_id'],
                'id' => $entry['id'],
            ]);
        }
    }
}
