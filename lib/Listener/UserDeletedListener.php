<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Listener;

use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\BeforeUserDeletedEvent;

/**
 * When a Nextcloud user is deleted, tell Synaplan that this person is gone.
 * Synaplan revokes that identity and its key. The Synaplan account is removed
 * only while this cloud alone manages it.
 *
 * Uses BeforeUserDeletedEvent so the uid → Synaplan-account mapping (stored in
 * the user's preferences) is still readable at the time we call Synaplan.
 *
 * @implements IEventListener<BeforeUserDeletedEvent>
 */
class UserDeletedListener implements IEventListener
{
    public function __construct(
        private UserAccountService $userAccounts,
        private ?PlatformLinkService $platformLinks = null,
    ) {
    }

    public function handle(Event $event): void
    {
        $uid = $event->getUser()->getUID();
        $kind = $this->userAccounts->getLinkKind($uid);
        $linked = $kind === UserAccountService::KIND_LINKED || $this->userAccounts->wasLinked($uid);
        $key = $this->userAccounts->getStoredApiKey($uid);
        // Decide before disconnect clears the stored credential.
        if ($linked || $key !== '' || $kind !== null || $this->userAccounts->getSynaplanUserId($uid) !== null) {
            // Synaplan ends the link, and deletes an account it only kept for
            // this person here.
            $this->platformLinks?->reportLifecycle($uid, 'deleted');
        }
        if ($linked || $key !== '') {
            $this->platformLinks?->disconnectUid($uid);
        }
    }
}
