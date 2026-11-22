<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\Exception\FailureException;
use OCA\SynaplanIntegration\Http\Failure;
use OCP\AppFramework\Http;
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
 * when they may edit it. The path is taken from that node. A caller-supplied
 * path is never trusted.
 */
final class WatchTarget
{
    private const PATH_MAX_LENGTH = 255;

    public function __construct(
        private IRootFolder $rootFolder,
        private IUserSession $userSession,
        private WatchPolicy $policy,
    ) {
    }

    /**
     * How many files are directly in this person's folder: the files a
     * folder watch would get when the person asks for the ones already there.
     *
     * @throws FailureException the person may not watch the folder
     */
    public function existingFiles(string $id): int
    {
        try {
            $this->resolve('folder', $id);
            $user = $this->userSession->getUser();
            $folder = $user instanceof IUser ? $this->rootFolder->getUserFolder($user->getUID())->getFirstNodeById((int) $id) : null;
        } catch (FailureException $e) {
            throw $e;
        } catch (\Throwable) {
            return 0;
        }
        if (!$folder instanceof Folder) {
            return 0;
        }

        return count(array_filter($folder->getDirectoryListing(), static fn ($node): bool => $node instanceof File));
    }

    /**
     * @return array{target_kind: string, target_id: string, target_path: string}
     * @throws NotFoundException the id is not a readable file or folder of this kind
     * @throws FailureException the person may not edit it, or it is shared while watching shared folders is off
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
        // A share's root always reports UPDATE because its mount point can be
        // renamed; adding files is what editing a folder means.
        $edit = $node instanceof Folder ? Constants::PERMISSION_CREATE : Constants::PERMISSION_UPDATE;
        if (($node->getPermissions() & $edit) === 0) {
            throw new FailureException(
                new Failure(Http::STATUS_FORBIDDEN, Failure::WATCH_NEEDS_EDIT),
                'Watching needs permission to edit the target',
            );
        }
        if ($this->policy->blocks($node)) {
            throw new FailureException(
                new Failure(Http::STATUS_FORBIDDEN, Failure::WATCH_SHARED_OFF),
                'Watching shared folders is turned off',
            );
        }

        $relative = $home->getRelativePath($node->getPath());
        $path = is_string($relative) ? ltrim($relative, '/') : '';
        if ($path === '') {
            $path = $node->getName();
        }

        return [
            'target_kind' => $actual,
            'target_id' => (string) $node->getId(),
            'target_path' => mb_strcut($path, 0, self::PATH_MAX_LENGTH, 'UTF-8'),
        ];
    }
}
