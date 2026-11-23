<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Listener;

use OCA\SynaplanIntegration\Service\ReadScope;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Folder;

/**
 * When a folder is renamed or moved, an allowed folder may now be somewhere
 * else (itself or inside it). Report the new paths to Synaplan, which checks
 * a run against the paths it holds, for the person who moved it and for the
 * owner of a shared folder.
 *
 * @implements IEventListener<Event>
 */
class ReadScopeListener implements IEventListener
{
    public function __construct(
        private ReadScope $readScope,
    ) {
    }

    public function handle(Event $event): void
    {
        if (!$event instanceof NodeRenamedEvent) {
            return;
        }
        $target = $event->getTarget();
        if (!$target instanceof Folder) {
            return;
        }
        $people = [];
        try {
            if (preg_match('#^/([^/]+)/files/#', $target->getPath(), $match) === 1) {
                $people[] = $match[1];
            }
            $owner = (string) $target->getOwner()?->getUID();
            if ($owner !== '') {
                $people[] = $owner;
            }
        } catch (\Throwable) {
        }
        foreach (array_unique($people) as $uid) {
            try {
                $this->readScope->follow($uid);
            } catch (\Throwable) {
                // A rename never fails because of this; the next save or
                // rename reports the paths again.
            }
        }
    }
}
