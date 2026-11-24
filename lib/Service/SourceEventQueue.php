<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Each person's source events for Synaplan, in the order they happened. The
 * rows of one person go out from the head only, so an event waiting for a
 * retry holds back the ones after it: a restore never reaches Synaplan
 * before the deletion it undoes.
 *
 * @phpstan-type QueuedEvent array{seq: int, ord: int, uid: string, file_id: int, event: string, version: string, path: string, step: string, walked: int, due: int, attempts: int}
 */
class SourceEventQueue
{
    public const TABLE = 'synaplan_source_events';
    /** Ready to be sent. */
    public const STEP_SEND = 'send';
    /** A moved folder whose files are still to be queued; `walked` is the last file id queued. */
    public const STEP_WALK = 'walk';
    /** Below a folder about to be deleted; `walked` is the folder's id. */
    public const STEP_HELD = 'held';
    /** The people the file is shared with are still to be told. */
    public const STEP_SHARE = 'share';

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    /**
     * The order of an event that happens now, in microseconds: events of one
     * person from different requests sort by when they happened.
     *
     * @phpstan-impure
     */
    public static function now(): int
    {
        return (int) floor(microtime(true) * 1_000_000);
    }

    /**
     * @param array{id: string, event: string, version: string, path: string} $event
     */
    public function add(string $uid, array $event, int $ord, string $step = self::STEP_SEND, int $walked = 0): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)
            ->values([
                'ord' => $qb->createNamedParameter($ord, IQueryBuilder::PARAM_INT),
                'uid' => $qb->createNamedParameter($uid),
                'file_id' => $qb->createNamedParameter((int) $event['id'], IQueryBuilder::PARAM_INT),
                'event' => $qb->createNamedParameter($event['event']),
                'version' => $qb->createNamedParameter($event['version']),
                'path' => $qb->createNamedParameter($event['path']),
                'step' => $qb->createNamedParameter($step),
                'walked' => $qb->createNamedParameter($walked, IQueryBuilder::PARAM_INT),
            ])
            ->executeStatement();
    }

    /**
     * The first rows of a person's queue, oldest first.
     *
     * @return list<QueuedEvent>
     */
    public function head(string $uid, int $limit): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('seq', 'ord', 'uid', 'file_id', 'event', 'version', 'path', 'step', 'walked', 'due', 'attempts')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));

        return $this->rows($qb, $limit);
    }

    /**
     * A person's events that still go to the people the file is shared
     * with, oldest first.
     *
     * @return list<QueuedEvent>
     */
    public function shares(string $uid, int $limit): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('seq', 'ord', 'uid', 'file_id', 'event', 'version', 'path', 'step', 'walked', 'due', 'attempts')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->eq('step', $qb->createNamedParameter(self::STEP_SHARE)));

        return $this->rows($qb, $limit);
    }

    /**
     * The oldest event of someone else that has yet to go to the people the
     * file is shared with: until then, no one knows whose queue it joins.
     *
     * @return array{uid: string, ord: int}|null
     */
    public function oldestShare(string $exceptUid): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('uid', 'ord')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('step', $qb->createNamedParameter(self::STEP_SHARE)))
            ->andWhere($qb->expr()->neq('uid', $qb->createNamedParameter($exceptUid)))
            ->orderBy('ord', 'ASC')
            ->setMaxResults(1)
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return is_array($row) ? ['uid' => (string) $row['uid'], 'ord' => (int) $row['ord']] : null;
    }

    /**
     * @return list<QueuedEvent>
     */
    private function rows(IQueryBuilder $qb, int $limit): array
    {
        $result = $qb->orderBy('ord', 'ASC')
            ->addOrderBy('seq', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery();
        $rows = [];
        while (($row = $result->fetch()) !== false) {
            $rows[] = [
                'seq' => (int) $row['seq'],
                'ord' => (int) $row['ord'],
                'uid' => (string) $row['uid'],
                'file_id' => (int) $row['file_id'],
                'event' => (string) $row['event'],
                'version' => (string) ($row['version'] ?? ''),
                'path' => (string) ($row['path'] ?? ''),
                'step' => (string) $row['step'],
                'walked' => (int) $row['walked'],
                'due' => (int) $row['due'],
                'attempts' => (int) $row['attempts'],
            ];
        }
        $result->closeCursor();

        return $rows;
    }

    /**
     * Queue every file and folder below a folder for one person, in one
     * statement from the file cache: the request reads no node. The path is
     * left out, as Synaplan reads it for moves only.
     *
     * @param list<string> $except file cache paths whose subtrees stay out
     */
    public function addDescendants(string $uid, int $folderId, string $event, int $ord, string $step, int $walked = 0, array $except = []): void
    {
        $folder = $this->location($folderId);
        if ($folder === null) {
            return;
        }
        $select = $this->db->getQueryBuilder();
        $expr = $select->expr();
        $select->select(
            $expr->literal($ord, IQueryBuilder::PARAM_INT),
            $expr->literal($uid),
            'fileid',
            $expr->literal($event),
            'etag',
            $expr->literal(''),
            $expr->literal($step),
            $expr->literal($walked, IQueryBuilder::PARAM_INT),
        )
            ->from('filecache')
            ->where($expr->eq('storage', $select->createNamedParameter($folder['storage'], IQueryBuilder::PARAM_INT)))
            ->andWhere($this->below($select, $folder['path']));
        foreach ($except as $path) {
            $select->andWhere($expr->neq('path_hash', $select->createNamedParameter(md5($path))))
                ->andWhere($expr->notLike('path', $select->createNamedParameter($this->db->escapeLikeParameter($path) . '/%')));
        }

        $columns = array_map(
            static fn (string $column): string => $select->getColumnName($column),
            ['ord', 'uid', 'file_id', 'event', 'version', 'path', 'step', 'walked'],
        );
        $this->db->executeStatement(
            'INSERT INTO ' . $select->getTableName(self::TABLE) . ' (' . implode(', ', $columns) . ') ' . $select->getSQL(),
            $select->getParameters(),
            $select->getParameterTypes(),
        );
    }

    /**
     * A page of the files and folders below a folder, by file id, with
     * their paths below it.
     *
     * @return list<array{id: int, path: string, etag: string}>
     */
    public function descendants(int $folderId, int $afterId, int $limit): array
    {
        $folder = $this->location($folderId);
        if ($folder === null) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('fileid', 'path', 'etag')
            ->from('filecache')
            ->where($qb->expr()->eq('storage', $qb->createNamedParameter($folder['storage'], IQueryBuilder::PARAM_INT)))
            ->andWhere($this->below($qb, $folder['path']))
            ->andWhere($qb->expr()->gt('fileid', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
            ->orderBy('fileid', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery();
        $prefix = $folder['path'] === '' ? 0 : strlen($folder['path']) + 1;
        $rows = [];
        while (($row = $result->fetch()) !== false) {
            $rows[] = ['id' => (int) $row['fileid'], 'path' => substr((string) $row['path'], $prefix), 'etag' => (string) ($row['etag'] ?? '')];
        }
        $result->closeCursor();

        return $rows;
    }

    /**
     * Where the file cache keeps a folder: a node seen through a share
     * names its path below the share, the cache below its storage.
     *
     * @return array{storage: int, path: string}|null
     */
    public function location(int $fileId): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('storage', 'path')
            ->from('filecache')
            ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return is_array($row) ? ['storage' => (int) $row['storage'], 'path' => (string) ($row['path'] ?? '')] : null;
    }

    private function below(IQueryBuilder $qb, string $folderPath): string
    {
        if ($folderPath === '') {
            return (string) $qb->expr()->neq('path_hash', $qb->createNamedParameter(md5('')));
        }

        return (string) $qb->expr()->like('path', $qb->createNamedParameter($this->db->escapeLikeParameter($folderPath) . '/%'));
    }

    /**
     * The held rows of a deletion that happened become ready to send: those
     * of its last attempt, as the rows of an earlier one that failed may
     * name files that have left the folder since. Those go.
     *
     * @return list<string> the people whose rows they are
     */
    public function release(int $folderId): array
    {
        $select = $this->db->getQueryBuilder();
        $result = $select->selectDistinct(['uid', 'ord'])
            ->from(self::TABLE)
            ->where($select->expr()->eq('step', $select->createNamedParameter(self::STEP_HELD)))
            ->andWhere($select->expr()->eq('walked', $select->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
            ->executeQuery();
        $held = [];
        while (($row = $result->fetch()) !== false) {
            $held[] = ['uid' => (string) $row['uid'], 'ord' => (int) $row['ord']];
        }
        $result->closeCursor();
        if ($held === []) {
            return [];
        }
        $last = max(array_column($held, 'ord'));

        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('step', $qb->createNamedParameter(self::STEP_SEND))
            ->where($qb->expr()->eq('step', $qb->createNamedParameter(self::STEP_HELD)))
            ->andWhere($qb->expr()->eq('walked', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('ord', $qb->createNamedParameter($last, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('step', $qb->createNamedParameter(self::STEP_HELD)))
            ->andWhere($qb->expr()->eq('walked', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lt('ord', $qb->createNamedParameter($last, IQueryBuilder::PARAM_INT)))
            ->executeStatement();

        return array_values(array_unique(array_column(
            array_filter($held, static fn (array $row): bool => $row['ord'] === $last),
            'uid',
        )));
    }

    /**
     * The held rows of a deletion attempt that never happened.
     */
    public function removeHeld(string $uid, int $folderId, int $ord): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->eq('step', $qb->createNamedParameter(self::STEP_HELD)))
            ->andWhere($qb->expr()->eq('walked', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('ord', $qb->createNamedParameter($ord, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    /**
     * How far the walk of a moved folder got.
     */
    public function walked(int $seq, int $walked): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('walked', $qb->createNamedParameter($walked, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('seq', $qb->createNamedParameter($seq, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    /**
     * @param list<int> $seqs
     */
    public function remove(array $seqs): void
    {
        foreach (array_chunk($seqs, 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete(self::TABLE)
                ->where($qb->expr()->in('seq', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->executeStatement();
        }
    }

    /**
     * Hold rows back until `due`, after a passing failure.
     *
     * @param list<int> $seqs
     */
    public function retryLater(array $seqs, int $attempts, int $due): void
    {
        foreach (array_chunk($seqs, 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->update(self::TABLE)
                ->set('attempts', $qb->createNamedParameter($attempts, IQueryBuilder::PARAM_INT))
                ->set('due', $qb->createNamedParameter($due, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->in('seq', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->executeStatement();
        }
    }
}
