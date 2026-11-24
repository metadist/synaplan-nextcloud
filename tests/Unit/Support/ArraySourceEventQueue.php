<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Support;

use OCA\SynaplanIntegration\Service\SourceEventQueue;

/**
 * The source event queue in memory, with the table's order and steps.
 *
 * @phpstan-import-type QueuedEvent from SourceEventQueue
 */
final class ArraySourceEventQueue extends SourceEventQueue
{
    /** @var array<int, QueuedEvent> */
    public array $rows = [];
    private int $seq = 0;

    public function __construct()
    {
    }

    public function add(string $uid, array $event, int $ord, string $step = self::STEP_SEND, int $walked = 0): void
    {
        $seq = ++$this->seq;
        $this->rows[$seq] = [
            'seq' => $seq,
            'ord' => $ord,
            'uid' => $uid,
            'file_id' => (int) $event['id'],
            'event' => $event['event'],
            'version' => $event['version'],
            'path' => $event['path'],
            'step' => $step,
            'walked' => $walked,
            'due' => 0,
            'attempts' => 0,
        ];
    }

    public function head(string $uid, int $limit): array
    {
        $rows = array_values(array_filter($this->rows, static fn (array $row): bool => $row['uid'] === $uid));
        usort($rows, static fn (array $a, array $b): int => [$a['ord'], $a['seq']] <=> [$b['ord'], $b['seq']]);

        return array_slice($rows, 0, $limit);
    }

    public function shares(string $uid, int $limit): array
    {
        return array_values(array_filter($this->head($uid, PHP_INT_MAX), static fn (array $row): bool => $row['step'] === self::STEP_SHARE));
    }

    public function oldestShare(string $exceptUid): ?array
    {
        $oldest = null;
        foreach ($this->rows as $row) {
            if ($row['step'] === self::STEP_SHARE && $row['uid'] !== $exceptUid && ($oldest === null || $row['ord'] < $oldest['ord'])) {
                $oldest = ['uid' => $row['uid'], 'ord' => $row['ord']];
            }
        }

        return $oldest;
    }

    /**
     * The steps of the rows waiting, oldest first, as `uid step file_id`.
     *
     * @return list<string>
     */
    public function steps(): array
    {
        $rows = array_values($this->rows);
        usort($rows, static fn (array $a, array $b): int => [$a['ord'], $a['seq']] <=> [$b['ord'], $b['seq']]);

        return array_map(static fn (array $row): string => $row['uid'] . ' ' . $row['step'] . ' ' . $row['file_id'], $rows);
    }

    /**
     * Put a file or folder into the fake file cache.
     */
    public function cache(int $storage, string $path, int $id, string $etag = 'e1'): void
    {
        $this->fileCache[] = ['storage' => $storage, 'path' => $path, 'id' => $id, 'etag' => $etag];
    }

    /** @var list<array{storage: int, path: string, id: int, etag: string}> */
    public array $fileCache = [];
    /** Statements that read the file cache. */
    public int $cacheReads = 0;

    public function addDescendants(string $uid, int $folderId, string $event, int $ord, string $step, int $walked = 0, array $except = []): void
    {
        ++$this->cacheReads;
        $prefix = ($this->location($folderId)['path'] ?? '') . '/';
        foreach ($this->below($folderId) as $entry) {
            $path = ltrim($prefix . $entry['path'], '/');
            foreach ($except as $kept) {
                if ($path === $kept || str_starts_with($path, $kept . '/')) {
                    continue 2;
                }
            }
            $this->add($uid, ['id' => (string) $entry['id'], 'event' => $event, 'version' => $entry['etag'], 'path' => ''], $ord, $step, $walked);
        }
    }

    public function descendants(int $folderId, int $afterId, int $limit): array
    {
        ++$this->cacheReads;
        $page = array_values(array_filter($this->below($folderId), static fn (array $entry): bool => $entry['id'] > $afterId));
        usort($page, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return array_map(
            static fn (array $entry): array => ['id' => $entry['id'], 'path' => $entry['path'], 'etag' => $entry['etag']],
            array_slice($page, 0, $limit),
        );
    }

    public function location(int $fileId): ?array
    {
        foreach ($this->fileCache as $entry) {
            if ($entry['id'] === $fileId) {
                return ['storage' => $entry['storage'], 'path' => $entry['path']];
            }
        }

        return null;
    }

    /**
     * The entries below a folder, with their paths below it.
     *
     * @return list<array{storage: int, path: string, id: int, etag: string}>
     */
    private function below(int $folderId): array
    {
        $folder = null;
        foreach ($this->fileCache as $entry) {
            if ($entry['id'] === $folderId) {
                $folder = $entry;
            }
        }
        if ($folder === null) {
            return [];
        }
        $prefix = $folder['path'] === '' ? '' : $folder['path'] . '/';
        $out = [];
        foreach ($this->fileCache as $entry) {
            if ($entry['storage'] === $folder['storage'] && $entry['path'] !== $folder['path'] && str_starts_with($entry['path'], $prefix)) {
                $out[] = ['path' => substr($entry['path'], strlen($prefix))] + $entry;
            }
        }

        return $out;
    }

    public function release(int $folderId): array
    {
        $held = array_filter($this->rows, static fn (array $row): bool => $row['step'] === self::STEP_HELD && $row['walked'] === $folderId);
        if ($held === []) {
            return [];
        }
        $last = max(array_column($held, 'ord'));
        $people = [];
        foreach ($held as $seq => $row) {
            if ($row['ord'] === $last) {
                $this->rows[$seq]['step'] = self::STEP_SEND;
                $people[$row['uid']] = true;
            } else {
                unset($this->rows[$seq]);
            }
        }

        return array_map('strval', array_keys($people));
    }

    public function removeHeld(string $uid, int $folderId, int $ord): void
    {
        foreach ($this->rows as $seq => $row) {
            if ($row['uid'] === $uid && $row['step'] === self::STEP_HELD && $row['walked'] === $folderId && $row['ord'] === $ord) {
                unset($this->rows[$seq]);
            }
        }
    }

    public function walked(int $seq, int $walked): void
    {
        $this->rows[$seq]['walked'] = $walked;
    }

    public function remove(array $seqs): void
    {
        foreach ($seqs as $seq) {
            unset($this->rows[$seq]);
        }
    }

    public function retryLater(array $seqs, int $attempts, int $due): void
    {
        foreach ($seqs as $seq) {
            $this->rows[$seq]['attempts'] = $attempts;
            $this->rows[$seq]['due'] = $due;
        }
    }

    /**
     * Each row ready to send as `uid`, then the event Synaplan gets, oldest
     * first.
     *
     * @return list<array{uid: string, id: string, event: string, version: string, path: string}>
     */
    public function events(): array
    {
        $rows = array_values(array_filter($this->rows, static fn (array $row): bool => $row['step'] === self::STEP_SEND));
        usort($rows, static fn (array $a, array $b): int => [$a['ord'], $a['seq']] <=> [$b['ord'], $b['seq']]);

        return array_map(static fn (array $row): array => [
            'uid' => $row['uid'],
            'id' => (string) $row['file_id'],
            'event' => $row['event'],
            'version' => $row['version'],
            'path' => $row['path'],
        ], $rows);
    }
}
