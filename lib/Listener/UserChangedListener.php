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
        if (!$this->hasIdentity($uid)) {
            return;
        }
        $this->platformLinks->reportLifecycle($uid, $now ? 'enabled' : 'disabled');
    }

    private function hasIdentity(string $uid): bool
    {
        return $this->userAccounts->wasLinked($uid)
            || $this->userAccounts->getLinkKind($uid) !== null
            || $this->userAccounts->getStoredApiKey($uid) !== ''
            || $this->userAccounts->getSynaplanUserId($uid) !== null;
    }
}
