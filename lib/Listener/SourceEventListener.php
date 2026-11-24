<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Listener;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\BackgroundJob\SourceEventsJob;
use OCA\SynaplanIntegration\Service\ShareRecipients;
use OCA\SynaplanIntegration\Service\SourceEventQueue;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\AbstractNodesEvent;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\Share\Events\ShareDeletedFromSelfEvent;
use OCP\Share\IShare;

/**
 * When a file or folder changes, moves, is deleted to the trash, or is
 * restored from the trash, or a recipient removes a share from their files,
 * queue source events for Synaplan (including descendant files inside a
 * moved, deleted, or restored folder), in each person's queue in the order
 * they happen.
 * Respects dev_mute_events.
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
        private IRootFolder $rootFolder,
        private SourceEventQueue $queue,
        private ShareRecipients $recipients,
        private IUserSession $session,
    ) {
    }

    public function handle(Event $event): void
    {
        if ($this->config->getAppValue(Application::APP_ID, self::DEV_MUTE, '0') === '1') {
            return;
        }
        if (!$this->synaplanConfig->isRegistered()) {
            return;
        }

        $ord = SourceEventQueue::now();
        if ($event instanceof BeforeNodeDeletedEvent) {
            $this->holdDeletion($event->getNode(), $ord);

            return;
        }
        if ($event instanceof ShareDeletedFromSelfEvent) {
            $this->leftShare($event->getShare(), $ord);

            return;
        }

        $people = [];
        foreach ($this->mapEvents($event) as [$mapped, $node, $moverOnly]) {
            $rows = $this->perPerson($mapped);
            if ($rows !== [] && $mapped['event'] !== 'deleted' && !$moverOnly) {
                $this->queue->add($rows[0]['uid'], $rows[0], $ord, SourceEventQueue::STEP_SHARE);
            }
            foreach ($rows as $row) {
                $this->queue->add($row['uid'], $row, $ord);
                $people[$row['uid']] = true;
                if (!$node instanceof Folder) {
                    continue;
                }
                if ($mapped['event'] === 'moved') {
                    $this->queue->add($row['uid'], $row, $ord, SourceEventQueue::STEP_WALK);
                } elseif ($mapped['event'] === 'restored') {
                    $this->queueDescendants($row['uid'], $node, 'restored', $ord, SourceEventQueue::STEP_SEND);
                }
            }
        }
        if ($event instanceof NodeDeletedEvent) {
            foreach ($this->queue->release((int) $event->getNode()->getId()) as $uid) {
                $people[$uid] = true;
            }
        }
        foreach (array_keys($people) as $uid) {
            $this->jobs->add(SourceEventsJob::class, ['uid' => (string) $uid]);
        }
    }

    /**
     * Before a deletion the shares still say who else holds the file: their
     * rows go in now, held until the deletion happens, as do the files below
     * a folder for everyone.
     */
    private function holdDeletion(Node $node, int $ord): void
    {
        $mapped = $this->payload($node, 'deleted', true);
        if ($mapped === null) {
            return;
        }
        $id = (int) $mapped['id'];
        $told = array_values(array_filter([$mapped['uid'], $mapped['owner']], static fn (string $uid): bool => $uid !== ''));
        $recipients = $this->recipients->of($node, $told);
        foreach ([...array_unique($told), ...$recipients] as $uid) {
            if ($node instanceof Folder) {
                $this->queueDescendants($uid, $node, 'deleted', $ord, SourceEventQueue::STEP_HELD, $id);
            }
        }
        foreach ($recipients as $uid) {
            $this->queue->add($uid, ['id' => $mapped['id'], 'event' => 'deleted', 'version' => $mapped['version'], 'path' => ''], $ord, SourceEventQueue::STEP_HELD, $id);
        }
        $holding = $node instanceof Folder ? [...array_unique($told), ...$recipients] : $recipients;
        if ($node instanceof Folder) {
            $holding = [...$holding, ...$this->holdSharesBelow($id, [...$told, ...$recipients], $ord)];
        }
        // A deletion that fails says so nowhere: a run after the rows time
        // out drops them, under its own argument so it delays no other run.
        foreach (array_unique($holding) as $uid) {
            $this->jobs->scheduleAfter(
                SourceEventsJob::class,
                intdiv($ord, 1_000_000) + SourceEventsJob::HELD_FOR + 1,
                ['uid' => $uid, 'held' => true],
            );
        }
    }

    /**
     * Those who have only a share of something below a deleted folder lose
     * it with the folder.
     *
     * @param list<string> $told
     * @return list<string> the people whose rows are held
     */
    private function holdSharesBelow(int $id, array $told, int $ord): array
    {
        try {
            $folder = $this->queue->location($id);
            $mounts = $folder === null ? [] : $this->recipients->mountedBelow($folder, $told);
            foreach ($mounts as $mount) {
                $this->queue->addDescendants($mount['uid'], $mount['root'], 'deleted', $ord, SourceEventQueue::STEP_HELD, $id);
                $this->queue->add($mount['uid'], ['id' => (string) $mount['root'], 'event' => 'deleted', 'version' => '', 'path' => ''], $ord, SourceEventQueue::STEP_HELD, $id);
            }
        } catch (\Throwable) {
            return [];
        }

        return array_column($mounts, 'uid');
    }

    /**
     * A recipient who removes a share from their files deletes nothing in
     * Nextcloud, but their copies of what it holds are gone for them as for
     * a deletion, but for what they still reach through a share of their
     * own further down. Nextcloud does not say who left a group share; it is
     * the person signed in.
     */
    private function leftShare(IShare $share, int $ord): void
    {
        try {
            $uid = $share->getShareType() === IShare::TYPE_USER
                ? $share->getSharedWith()
                : ($this->session->getUser()?->getUID() ?? '');
            $node = $share->getNode();
            if (!$this->recipients->lost($node, $uid)) {
                return;
            }
            $folder = $node instanceof Folder ? $this->queue->location((int) $node->getId()) : null;
            if ($folder !== null) {
                $this->queue->addDescendants($uid, (int) $node->getId(), 'deleted', $ord, SourceEventQueue::STEP_SEND, 0, $this->recipients->keptBelow($uid, $folder));
            }
            $this->queue->add($uid, ['id' => (string) $node->getId(), 'event' => 'deleted', 'version' => $node->getEtag(), 'path' => ''], $ord);
            $this->jobs->add(SourceEventsJob::class, ['uid' => $uid]);
        } catch (\Throwable) {
            // Best effort: the share is gone either way.
        }
    }

    private function queueDescendants(string $uid, Folder $folder, string $kind, int $ord, string $step, int $walked = 0): void
    {
        try {
            $this->queue->addDescendants($uid, (int) $folder->getId(), $kind, $ord, $step, $walked);
        } catch (\Throwable) {
            // Best effort: the folder's own event still goes out.
        }
    }

    /**
     * Synaplan applies a source event to the copies of the person whose key
     * reports it, and a move renames the copy to the path it reports. A file
     * is reported for the person in whose files it changed, with their path,
     * and, for a shared file, for its owner too, with the owner's path.
     *
     * @param array{id: string, event: string, version: string, path: string, uid: string, owner: string} $mapped
     * @return list<array{uid: string, id: string, event: string, version: string, path: string}>
     */
    private function perPerson(array $mapped): array
    {
        $event = ['id' => $mapped['id'], 'event' => $mapped['event'], 'version' => $mapped['version']];
        $jobs = [];
        if ($mapped['uid'] !== '') {
            $jobs[] = ['uid' => $mapped['uid']] + $event + ['path' => $mapped['path']];
        }
        if ($mapped['owner'] !== '' && $mapped['owner'] !== $mapped['uid']) {
            $jobs[] = ['uid' => $mapped['owner']] + $event + ['path' => $this->ownerPath($mapped['owner'], (int) $mapped['id'])];
        }

        return $jobs;
    }

    /**
     * The path as sent, or '' when it does not fit Synaplan's 255 bytes:
     * Synaplan renames its copy to the path it gets and leaves it alone on
     * '', and a cut path would name another file or split a character.
     */
    private static function fitting(string $path): string
    {
        return strlen($path) <= 255 ? $path : '';
    }

    /**
     * Where the owner sees a shared node, '' when it is gone (deleted).
     */
    private function ownerPath(string $owner, int $id): string
    {
        try {
            $node = $this->rootFolder->getUserFolder($owner)->getFirstNodeById($id);
        } catch (\Throwable) {
            return '';
        }

        return $node instanceof Node ? self::fitting($this->userRelativePath($node->getPath()) ?? '') : '';
    }

    /**
     * The event of the node itself, the node, and whether the event changed
     * the paths of the person who acted only; the job and the file cache add
     * what lies below a folder.
     *
     * @return list<array{array{id: string, event: string, version: string, path: string, uid: string, owner: string}, Node, bool}>
     */
    private function mapEvents(Event $event): array
    {
        [$node, $kind, $allowFolder] = match (true) {
            $event instanceof NodeWrittenEvent => [$event->getNode(), 'changed', false],
            $event instanceof NodeDeletedEvent => [$event->getNode(), 'deleted', true],
            $event instanceof NodeRenamedEvent => [
                $event->getTarget(),
                str_contains($event->getSource()->getPath(), '/files_trashbin/') ? 'restored' : 'moved',
                true,
            ],
            $event instanceof AbstractNodesEvent => [$event->getTarget(), 'restored', true],
            default => [null, '', false],
        };
        if (!$node instanceof Node) {
            return [];
        }
        $one = $this->payload($node, $kind, $allowFolder);
        if ($one === null) {
            return [];
        }
        $moverOnly = $kind === 'moved' && self::isMountRoot($node);
        if ($moverOnly) {
            $one['owner'] = '';
        }

        return [[$one, $node, $moverOnly]];
    }

    /**
     * The root of a mount, such as a share as its recipient sees it: moving
     * it renames it for that person only.
     */
    private static function isMountRoot(Node $node): bool
    {
        try {
            return $node->getInternalPath() === '';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{id: string, event: string, version: string, path: string, uid: string, owner: string}|null
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
            $owner = '';
            try {
                $owner = (string) $node->getOwner()?->getUID();
            } catch (\Throwable) {
                $owner = '';
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
                'path' => self::fitting($relative),
                'uid' => $this->pathUser($node->getPath()),
                'owner' => $owner,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function pathUser(string $fullPath): string
    {
        return preg_match('#^/([^/]+)/files/#', $fullPath, $match) === 1 ? $match[1] : '';
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
