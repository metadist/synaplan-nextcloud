<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Listener;

use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserChangedEvent;

/**
 * When a Nextcloud account is disabled or enabled, tell Synaplan about that
 * person only. Synaplan pauses or resumes the identity; it does not delete
 * the account.
 *
 * @implements IEventListener<UserChangedEvent>
 */
class UserChangedListener implements IEventListener
{
    public function __construct(
        private UserAccountService $userAccounts,
        private PlatformLinkService $platformLinks,
    ) {
    }

    public function handle(Event $event): void
    {
        if ($event->getFeature() !== 'enabled') {
            return;
        }
        $now = (bool) $event->getValue();
        $before = $event->getOldValue();
        if ($before !== null && (bool) $before === $now) {
            return;
        }
        $uid = $event->getUser()->getUID();
        // A disabled person's grant ends, which ends their link in Synaplan.
        // After they are enabled again they connect once more.
        if (!$now && $this->userAccounts->getLinkKind($uid) === UserAccountService::KIND_LINKED) {
            $this->platformLinks->disconnectUid($uid);
        }
    }
}
