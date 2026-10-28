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
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IConfig;

/**
 * When a file or folder changes, moves, is deleted to the trash, or is
 * restored from the trash, queue source events for Synaplan (including
 * descendant files inside a moved, deleted, or restored folder).
 * Respects dev_mute_events.
 *
 * @implements IEventListener<Event>
 */
class SourceEventListener implements IEventListener
{
    public const DEV_MUTE = 'dev_mute_events';

    /** @var array<int, list<array{id: string, event: string, version: string, path: string}>> */
    private array $pendingDeletedDescendants = [];

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

        if ($event instanceof BeforeNodeDeletedEvent) {
            $node = $event->getNode();
            if ($node instanceof Folder && $this->userRelativePath($node->getPath()) !== null) {
                $this->pendingDeletedDescendants[(int) $node->getId()] = $this->descendantPayloads($node, 'deleted');
            }

            return;
        }

        foreach ($this->mapEvents($event) as $mapped) {
            $this->jobs->add(SourceEventsJob::class, $mapped);
        }
    }

    /**
     * @return list<array{id: string, event: string, version: string, path: string}>
     */
    private function mapEvents(Event $event): array
    {
        if ($event instanceof NodeWrittenEvent) {
            $one = $this->payload($event->getNode(), 'changed', false);

            return $one !== null ? [$one] : [];
        }
        if ($event instanceof NodeDeletedEvent) {
            $node = $event->getNode();
            $one = $this->payload($node, 'deleted', true);
            if ($one === null) {
                return [];
            }
            $folderId = (int) $node->getId();
            $descendants = $this->pendingDeletedDescendants[$folderId] ?? [];
            unset($this->pendingDeletedDescendants[$folderId]);

            return [$one, ...$descendants];
        }
        if ($event instanceof NodeRenamedEvent) {
            $sourcePath = $event->getSource()->getPath();
            $kind = str_contains($sourcePath, '/files_trashbin/') ? 'restored' : 'moved';
            $target = $event->getTarget();
            $one = $this->payload($target, $kind, true);
            if ($one === null) {
                return [];
            }
            $descendants = $target instanceof Folder ? $this->descendantPayloads($target, $kind) : [];

            return [$one, ...$descendants];
        }
        if ($event instanceof AbstractNodesEvent) {
            $target = $event->getTarget();
            $one = $this->payload($target, 'restored', true);
            if ($one === null) {
                return [];
            }
            $descendants = $target instanceof Folder ? $this->descendantPayloads($target, 'restored') : [];

            return [$one, ...$descendants];
        }
        if ($event instanceof AbstractNodeEvent) {
            return [];
        }

        return [];
    }

    /**
     * @return list<array{id: string, event: string, version: string, path: string}>
     */
    private function descendantPayloads(Folder $folder, string $kind): array
    {
        $out = [];
        try {
            foreach ($folder->getDirectoryListing() as $child) {
                if ($child instanceof Folder) {
                    $row = $this->payload($child, $kind, true);
                    if ($row !== null) {
                        $out[] = $row;
                    }
                    array_push($out, ...$this->descendantPayloads($child, $kind));
                } else {
                    $row = $this->payload($child, $kind, false);
                    if ($row !== null) {
                        $out[] = $row;
                    }
                }
            }
        } catch (\Throwable) {
            // Best-effort walk.
        }

        return $out;
    }

    /**
     * @return array{id: string, event: string, version: string, path: string}|null
     */
    private function payload(Node $node, string $kind, bool $allowFolder): ?array
    {
        try {
            $type = $node->getType();
            if ($type !== FileInfo::TYPE_FILE && (!$allowFolder || $type !== FileInfo::TYPE_FOLDER)) {
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
