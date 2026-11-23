<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\Files\AlreadyExistsException;
use OCP\Files\EntityTooLargeException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotFoundException;
use OCP\IConfig;

/**
 * Where files from Synaplan go and how they are written, the same for a copy
 * Synaplan sends, From Synaplan in the Files app and Save in the chat.
 */
class SynaplanFolders
{
    public const COPY_FOLDER = 'copy_folder';

    /** Synaplan's cap for a copy; it refuses larger files before sending. */
    public const MAX_BYTES = 100 * 1024 * 1024;

    /** `name.ext` and `name (2).ext` up to `name (20).ext`. */
    public const MAX_SAME_NAME = 20;

    private const DEFAULT_ROOT = 'Synaplan';

    private const KIND_FOLDERS = [
        'docx' => 'Documents', 'doc' => 'Documents', 'pptx' => 'Documents',
        'ppt' => 'Documents', 'xlsx' => 'Documents', 'xls' => 'Documents',
        'csv' => 'Documents', 'pdf' => 'Documents', 'txt' => 'Documents',
        'md' => 'Documents', 'odt' => 'Documents', 'rtf' => 'Documents',
        'mp3' => 'Audio', 'wav' => 'Audio', 'ogg' => 'Audio',
        'm4a' => 'Audio', 'flac' => 'Audio', 'aac' => 'Audio',
        'ics' => 'Calendar',
        'png' => 'Images', 'jpg' => 'Images', 'jpeg' => 'Images',
        'gif' => 'Images', 'webp' => 'Images', 'svg' => 'Images',
        'mp4' => 'Video', 'webm' => 'Video', 'mov' => 'Video', 'mkv' => 'Video',
    ];

    public function __construct(
        private IConfig $config,
        private OwnWrites $ownWrites,
    ) {
    }

    /**
     * A folder a person typed. Empty, or only spaces, means the Synaplan
     * folder. `..` and a blank folder name inside the path are refused.
     */
    public static function cleanFolder(string $folder): string
    {
        $folder = trim(trim(str_replace('\\', '/', $folder)), '/');
        if (trim($folder) === '') {
            return '';
        }
        foreach (explode('/', $folder) as $segment) {
            if (trim($segment) === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('That folder cannot be used.');
            }
        }

        return $folder;
    }

    /**
     * The folder the person chose for files from Synaplan (or `Synaplan`),
     * with the kind folder of the file below it, made when missing.
     */
    public function kindFolder(Folder $userFolder, string $uid, string $name): Folder
    {
        try {
            $root = self::cleanFolder((string) $this->config->getUserValue($uid, Application::APP_ID, self::COPY_FOLDER, ''));
        } catch (\InvalidArgumentException) {
            $root = '';
        }
        $root = $root !== '' ? $root : self::DEFAULT_ROOT;
        $sub = self::KIND_FOLDERS[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? '';

        return $this->ensure($userFolder, trim($root . '/' . $sub, '/'));
    }

    public function ensure(Folder $root, string $path): Folder
    {
        $current = $root;
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }
            if ($current->nodeExists($segment)) {
                $node = $current->get($segment);
                if (!$node instanceof Folder) {
                    throw new NotFoundException('A file is where a folder should be.');
                }
                $current = $node;
            } else {
                $current = $current->newFolder($segment);
            }
        }

        return $current;
    }

    /**
     * @throws EntityTooLargeException over {@see self::MAX_BYTES}
     * @throws NotEnoughSpaceException when the folder's storage has no room
     */
    public function assertFits(Folder $folder, int $bytes): void
    {
        if ($bytes > self::MAX_BYTES) {
            throw new EntityTooLargeException('The file is larger than a copy from Synaplan may be.');
        }
        $free = $folder->getFreeSpace();
        if ($free >= 0 && $free < $bytes) {
            throw new NotEnoughSpaceException('The file does not fit in the person\'s storage.');
        }
    }

    /**
     * Write a new file, numbered when the name is taken.
     *
     * @throws AlreadyExistsException when twenty files have the name
     */
    public function write(Folder $folder, string $name, string $bytes): File
    {
        $this->assertFits($folder, strlen($bytes));
        $file = $folder->newFile($this->freeName($folder, $name));
        $file->putContent($bytes);
        $this->ownWrites->note($file, time());

        return $file;
    }

    private function freeName(Folder $folder, string $name): string
    {
        $target = $name;
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $n = 2;
        while ($folder->nodeExists($target)) {
            if ($n > self::MAX_SAME_NAME) {
                throw new AlreadyExistsException('Twenty files with this name already exist.');
            }
            $target = $stem . ' (' . $n . ')' . ($ext !== '' ? '.' . $ext : '');
            $n++;
        }

        return $target;
    }
}
