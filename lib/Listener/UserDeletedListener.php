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
        $key = $kind === UserAccountService::KIND_PROVISIONED ? $this->userAccounts->getStoredApiKey($uid) : '';
        if ($kind === UserAccountService::KIND_PROVISIONED) {
            // Synaplan deletes an account it only kept for this person here,
            // and keeps one the person also uses otherwise.
            $this->userAccounts->deleteProvisionedAccount($uid);
        }
        if ($kind !== null || $key !== '') {
            // A connected person's grant is revoked, which ends their link in
            // Synaplan; a provisioned key is revoked. Local preferences go.
            $this->platformLinks?->disconnectUid($uid);
        }
    }
}
