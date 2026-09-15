<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Listener;

use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\BeforeUserDeletedEvent;

/**
 * When a Nextcloud user is deleted, delete their Synaplan account too (in
 * per-user mode) so no orphaned external account/data is left behind.
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
        private SynaplanConfig $synaplanConfig,
        private ?PlatformLinkService $platformLinks = null,
    ) {
    }

    public function handle(Event $event): void
    {
        if (!$event instanceof BeforeUserDeletedEvent) {
            return;
        }

        $uid = $event->getUser()->getUID();
        // Linking never deletes a Synaplan user (decision 9). Disconnect
        // revokes only the minted key. Check this before the mode guard:
        // an admin who switched back to shared mode must still revoke the
        // linked key, and a disconnected-but-once-linked user must not
        // fall through to deleteRemoteAccount.
        if ($this->userAccounts->wasLinked($uid)
            || $this->userAccounts->getLinkKind($uid) === UserAccountService::KIND_LINKED) {
            $this->platformLinks?->disconnectUid($uid);

            return;
        }

        if (!$this->synaplanConfig->isPerUserAccountsEnabled()) {
            return;
        }

        // Best-effort; deleteRemoteAccount never throws so Nextcloud's own user
        // deletion is never blocked by a Synaplan hiccup.
        $this->userAccounts->deleteRemoteAccount($uid);
    }
}
