<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\Files\IRootFolder;
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
        private SynaplanFolders $folders,
    ) {
    }

    /**
     * @return array{files: list<array{id: int, name: string, generated: bool}>, page: int, pages: int}
     */
    public function list(int $page = 1): array
    {
        $page = max(1, $page);
        $decoded = $this->client->listRecentFiles($page);
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

        $pages = is_array($decoded['pagination'] ?? null) ? (int) ($decoded['pagination']['pages'] ?? 1) : 1;

        return [
            'files' => $files,
            'page' => $page,
            'pages' => max(1, $pages),
        ];
    }

    /**
     * @return array{name: string, folder: string}
     */
    public function copy(string $uid, int $fileId, string $folder, string $name): array
    {
        if ($fileId < 1) {
            throw new \InvalidArgumentException('Missing file.');
        }
        $destination = SynaplanFolders::cleanFolder($folder);
        $bytes = $this->client->downloadStoredFile($fileId, SynaplanFolders::MAX_BYTES);
        $name = basename(str_replace('\\', '/', trim($name)));
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'file';
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($uid);
            $target = $destination === '' ? $userFolder : $this->folders->ensure($userFolder, $destination);
            $file = $this->folders->write($target, $name, $bytes);
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
}
