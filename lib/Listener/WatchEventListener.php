<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Listener;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Service\OwnWrites;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCA\SynaplanIntegration\Service\WatchIndex;
use OCA\SynaplanIntegration\Service\WatchSender;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\AbstractNodesEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Node;
use OCP\IConfig;

/**
 * Files that arrive in a watched folder, and new versions of a watched file,
 * go into the watch queue; the job sends them once they have been quiet.
 * Nothing is sent from the request that wrote the file. A watched folder or
 * file that is deleted or restored is reported to its watch. The app's own
 * writes are ignored.
 *
 * @implements IEventListener<Event>
 */
class WatchEventListener implements IEventListener
{
    public function __construct(
        private SynaplanConfig $synaplanConfig,
        private IConfig $config,
        private WatchIndex $index,
        private OwnWrites $ownWrites,
        private ITimeFactory $time,
    ) {
    }

    public function handle(Event $event): void
    {
        if ($this->config->getAppValue(Application::APP_ID, SourceEventListener::DEV_MUTE, '0') === '1'
            || !$this->synaplanConfig->isRegistered()) {
            return;
        }
        try {
            if ($event instanceof NodeDeletedEvent) {
                $this->targetEvent($event->getNode(), 'deleted');
            } elseif ($event instanceof NodeWrittenEvent) {
                $this->arrived($event->getNode());
            } elseif ($event instanceof AbstractNodesEvent) {
                // Moved, copied or restored from the trash.
                $this->arrived($event->getTarget());
                $this->targetEvent($event->getTarget(), 'restored');
            }
        } catch (\Throwable) {
            // A file write never fails because of a watch; the hourly pass catches up.
        }
    }

    private function arrived(Node $node): void
    {
        if (!$node instanceof File) {
            return;
        }
        $id = (int) $node->getId();
        $now = $this->time->getTime();
        if ($id <= 0 || $this->ownWrites->isOwnVersion($id, WatchSender::version($node))) {
            return;
        }
        try {
            $parent = (int) $node->getParent()->getId();
        } catch (\Throwable) {
            $parent = 0;
        }
        foreach ($this->index->activeOn([$id, $parent]) as $watch) {
            if (WatchSender::belongs($watch, $node)) {
                $this->index->enqueue($watch['id'], $id, '', $now);
            }
        }
    }

    /**
     * A deleted target is reported while its watch runs; a restored one only
     * while its watch says the target is gone. A target restored before its
     * deletion went out takes the deletion back: Synaplan never knew.
     */
    private function targetEvent(Node $node, string $event): void
    {
        $id = (int) $node->getId();
        foreach ($this->index->on([$id]) as $watch) {
            if ($watch['target'] !== $id) {
                continue;
            }
            $running = $watch['state'] === WatchIndex::ACTIVE;
            if ($event === 'deleted' ? $running : !$running) {
                $this->index->enqueue($watch['id'], $id, $event, $this->time->getTime());
            } elseif ($event === 'restored') {
                $this->index->cancelEvent($watch['id'], $id, 'deleted');
            }
        }
    }
}
