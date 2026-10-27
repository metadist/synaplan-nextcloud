<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Listener;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\BackgroundJob\SourceEventsJob;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\AbstractNodeEvent;
use OCP\Files\Events\Node\AbstractNodesEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\FileInfo;
use OCP\Files\Node;
use OCP\IConfig;

/**
 * When a file changes, moves, is deleted to the trash, or is restored from the
 * trash, queue one source event for Synaplan. Respects dev_mute_events.
 *
 * @implements IEventListener<Event>
 */
class SourceEventListener implements IEventListener
{
    public const DEV_MUTE = 'dev_mute_events';

    public function __construct(
        private SynaplanConfig $synaplanConfig,
        private IConfig $config,
        private IJobList $jobs,
    ) {
    }

    public function handle(Event $event): void
    {
        if ($this->config->getAppValue(Application::APP_ID, self::DEV_MUTE, '0') === '1') {
            return;
        }
        if ($this->synaplanConfig->getLinkInstanceId() === ''
            || $this->synaplanConfig->getLinkInstanceSecretCipher() === '') {
            return;
        }

        $mapped = $this->mapEvent($event);
        if ($mapped === null) {
            return;
        }

        $this->jobs->add(SourceEventsJob::class, $mapped);
    }

    /**
     * @return array{id: string, event: string, version: string, path: string}|null
     */
    private function mapEvent(Event $event): ?array
    {
        if ($event instanceof NodeWrittenEvent) {
            return $this->payload($event->getNode(), 'changed');
        }
        if ($event instanceof NodeDeletedEvent) {
            return $this->payload($event->getNode(), 'deleted');
        }
        if ($event instanceof NodeRenamedEvent) {
            $sourcePath = $event->getSource()->getPath();
            $kind = str_contains($sourcePath, '/files_trashbin/') ? 'restored' : 'moved';

            return $this->payload($event->getTarget(), $kind);
        }
        if ($event instanceof AbstractNodesEvent) {
            return $this->payload($event->getTarget(), 'restored');
        }
        if ($event instanceof AbstractNodeEvent) {
            return null;
        }

        return null;
    }

    /**
     * @return array{id: string, event: string, version: string, path: string}|null
     */
    private function payload(Node $node, string $kind): ?array
    {
        try {
            if ($node->getType() !== FileInfo::TYPE_FILE) {
                return null;
            }
            $id = (int) $node->getId();
            if ($id <= 0) {
                return null;
            }
            $relative = $this->userRelativePath($node->getPath());
            if ($relative === null) {
                return null;
            }
            $etag = '';
            try {
                $etag = (string) $node->getEtag();
            } catch (\Throwable) {
                $etag = '';
            }

            return [
                'id' => (string) $id,
                'event' => $kind,
                'version' => substr($etag, 0, 255),
                'path' => substr($relative, 0, 255),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Strips "/<uid>/files/" and ignores trash, uploads, thumbnails and appdata.
     */
    private function userRelativePath(string $fullPath): ?string
    {
        if (preg_match('#^/[^/]+/files/(.+)$#', $fullPath, $match) !== 1) {
            return null;
        }
        $relative = trim($match[1], '/');

        return $relative !== '' ? $relative : null;
    }
}
