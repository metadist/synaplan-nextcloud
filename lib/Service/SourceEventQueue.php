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
        $result = $qb->select('seq', 'ord', 'uid', 'file_id', 'event', 'version', 'path', 'step', 'walked', 'due', 'attempts')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
            ->orderBy('ord', 'ASC')
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
