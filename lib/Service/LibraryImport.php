<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Copies one file out of the person's Synaplan into a folder they have open.
 */
class LibraryImport
{
    public function __construct(
        private SynaplanClient $client,
        private IRootFolder $rootFolder,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<array{id: int, name: string, generated: bool}>
     */
    public function list(): array
    {
        $decoded = $this->client->listRecentFiles();
        $rows = is_array($decoded['files'] ?? null) ? $decoded['files'] : [];
        $files = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }
            $name = $this->name($row);
            if ($name === '') {
                continue;
            }
            $files[] = [
                'id' => (int) $row['id'],
                'name' => $name,
                'generated' => ($row['source'] ?? '') === 'generated',
            ];
        }
        usort($files, static function (array $a, array $b): int {
            return ($a['generated'] === $b['generated']) ? 0 : ($a['generated'] ? -1 : 1);
        });

        return $files;
    }

    /**
     * @return array{name: string, folder: string}
     */
    public function copy(string $uid, int $fileId, string $folder, string $name): array
    {
        if ($fileId < 1) {
            throw new \InvalidArgumentException('Missing file.');
        }
        $destination = CopyDelivery::cleanFolder($folder);
        $bytes = $this->client->downloadStoredFile($fileId);
        $name = basename(str_replace('\\', '/', trim($name)));
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'file';
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($uid);
            $target = $destination === '' ? $userFolder : $this->ensure($userFolder, $destination);
            $file = $this->place($target, $name, $bytes);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not copy a Synaplan file for {uid}: {message}', [
                'app' => Application::APP_ID,
                'uid' => $uid,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        $relative = ltrim((string) ($userFolder->getRelativePath($file->getPath()) ?? $file->getName()), '/');
        $directory = dirname($relative);

        return [
            'name' => $file->getName(),
            'folder' => $directory === '.' || $directory === '' ? 'Files' : $directory,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function name(array $row): string
    {
        $display = is_string($row['display_name'] ?? null) ? trim($row['display_name']) : '';
        $filename = is_string($row['filename'] ?? null) ? trim($row['filename']) : '';
        $base = basename(str_replace('\\', '/', $display !== '' ? $display : $filename));

        return $base === '.' || $base === '..' ? '' : $base;
    }

    private function place(Folder $folder, string $name, string $bytes): \OCP\Files\File
    {
        $name = $name !== '' ? $name : 'file';
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $target = $name;
        $n = 2;
        while ($folder->nodeExists($target)) {
            $target = $stem . ' (' . $n . ')' . ($ext !== '' ? '.' . $ext : '');
            $n++;
        }
        $file = $folder->newFile($target);
        $file->putContent($bytes);

        return $file;
    }

    private function ensure(Folder $root, string $path): Folder
    {
        $current = $root;
        foreach (explode('/', $path) as $segment) {
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
}
