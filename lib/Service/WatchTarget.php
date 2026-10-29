<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IUser;
use OCP\IUserSession;

/**
 * The folder or file a watch may name: the current person's own node, only
 * when they can read it. The path is taken from that node. A caller-supplied
 * path is never trusted.
 */
final class WatchTarget
{
    private const PATH_MAX_LENGTH = 255;

    public function __construct(
        private IRootFolder $rootFolder,
        private IUserSession $userSession,
    ) {
    }

    /**
     * @return array{target_kind: string, target_id: string, target_path: string}
     * @throws NotFoundException the id is not a readable file or folder of this kind
     */
    public function resolve(string $kind, string $id): array
    {
        $user = $this->userSession->getUser();
        if (!$user instanceof IUser || !ctype_digit($id)) {
            throw new NotFoundException('Choose a folder or a file.');
        }

        try {
            $home = $this->rootFolder->getUserFolder($user->getUID());
            $node = $home->getFirstNodeById((int) $id);
        } catch (NotPermittedException) {
            throw new NotFoundException('Choose a folder or a file.');
        }

        if (!$node instanceof File && !$node instanceof Folder) {
            throw new NotFoundException('Choose a folder or a file.');
        }
        if (($node->getPermissions() & Constants::PERMISSION_READ) === 0) {
            throw new NotFoundException('Choose a folder or a file.');
        }

        $actual = $node instanceof Folder ? 'folder' : 'file';
        if ($actual !== $kind) {
            throw new NotFoundException('Choose a folder or a file.');
        }

        $relative = $home->getRelativePath($node->getPath());
        $path = is_string($relative) ? ltrim($relative, '/') : '';
        if ($path === '') {
            $path = $node->getName();
        }

        return [
            'target_kind' => $actual,
            'target_id' => (string) $node->getId(),
            'target_path' => substr($path, 0, self::PATH_MAX_LENGTH),
        ];
    }
}
