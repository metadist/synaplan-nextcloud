<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCP\Files\Config\IUserMountCache;
use OCP\Files\Node;
use OCP\IUserManager;
use OCP\Share\IManager;

/**
 * The people a file or folder is shared with, directly, through a group or
 * through a folder around it, who can tell Synaplan of a change to their
 * copies: Synaplan applies a source event to the copies of the person who
 * reports it.
 */
class ShareRecipients
{
    public function __construct(
        private IManager $shares,
        private UserAccountService $accounts,
        private IUserMountCache $mounts,
        private IUserManager $users,
    ) {
    }

    /**
     * @param list<string> $except the people already told
     * @return list<string>
     */
    public function of(Node $node, array $except): array
    {
        try {
            $users = $this->shares->getAccessList($node)['users'] ?? [];
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach (array_unique(array_map('strval', $users)) as $uid) {
            if ($uid !== '' && !in_array($uid, $except, true) && $this->accounts->hasStoredCredential($uid)) {
                $out[] = $uid;
            }
        }

        return $out;
    }

    /**
     * Whether a person who can tell Synaplan has no way left to the node:
     * after leaving a group share they stay in the group, so only the
     * current access, which leaves them out, says so.
     */
    public function lost(Node $node, string $uid): bool
    {
        if ($uid === '' || !$this->accounts->hasStoredCredential($uid)) {
            return false;
        }
        try {
            $users = $this->shares->getAccessList($node, true, true)['users'] ?? [];
        } catch (\Throwable) {
            return false;
        }

        return !array_key_exists($uid, $users);
    }

    /**
     * The file cache paths of the folders below a folder that a person still
     * has mounted on their own, such as a share of a subfolder.
     *
     * @param array{storage: int, path: string} $folder
     * @return list<string>
     */
    public function keptBelow(string $uid, array $folder): array
    {
        $user = $this->users->get($uid);
        if ($user === null) {
            return [];
        }
        $prefix = $folder['path'] === '' ? '' : $folder['path'] . '/';
        $kept = [];
        foreach ($this->mounts->getMountsForUser($user) as $mount) {
            $path = $mount->getRootInternalPath();
            if ($mount->getStorageId() === $folder['storage'] && $path !== $folder['path'] && str_starts_with($path, $prefix)) {
                $kept[] = $path;
            }
        }

        return $kept;
    }
}
