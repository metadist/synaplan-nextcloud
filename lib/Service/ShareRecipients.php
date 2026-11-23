<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCP\Files\Node;
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
}
