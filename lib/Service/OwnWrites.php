<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The files this app wrote for Synaplan: copies, saved media, imported
 * library files. A watch must not get them back, or a translation saved into
 * a watched folder is translated again. Events for them are ignored for ten
 * minutes; a later pass that still finds one unchanged tells Synaplan it is
 * an own write, so its ledger keeps it for good.
 */
class OwnWrites
{
    public const IGNORE_SECONDS = 600;
    private const TABLE = 'synaplan_own_writes';
    private const KEEP_SECONDS = 7 * 86400;

    private ?bool $ready = null;

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    public function note(int $fileId, int $now): void
    {
        if ($fileId <= 0 || !$this->ready()) {
            return;
        }
        $insert = $this->db->getQueryBuilder();
        $insert->insert(self::TABLE)->values([
            'file_id' => $insert->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
            'written' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_INT),
        ]);
        try {
            $insert->executeStatement();

            return;
        } catch (DBException $e) {
            if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw $e;
            }
        }
        $update = $this->db->getQueryBuilder();
        $update->update(self::TABLE)
            ->set('written', $update->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($update->expr()->eq('file_id', $update->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
        $update->executeStatement();
    }

    /**
     * When this app last wrote the file, or null.
     */
    public function writtenAt(int $fileId): ?int
    {
        if ($fileId <= 0 || !$this->ready()) {
            return null;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('written')->from(self::TABLE)
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $written = $result->fetchOne();
        $result->closeCursor();

        return $written === false || $written === null ? null : (int) $written;
    }

    /**
     * An event for this file is the app's own write.
     */
    public function isRecent(int $fileId, int $now): bool
    {
        $written = $this->writtenAt($fileId);

        return $written !== null && $now - $written <= self::IGNORE_SECONDS;
    }

    /**
     * The file is still as this app wrote it: not changed by a person since.
     */
    public function isUnchanged(int $fileId, int $mtime): bool
    {
        $written = $this->writtenAt($fileId);

        return $written !== null && $mtime <= $written + self::IGNORE_SECONDS;
    }

    public function prune(int $now): void
    {
        if (!$this->ready()) {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->lt('written', $qb->createNamedParameter($now - self::KEEP_SECONDS, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    private function ready(): bool
    {
        return $this->ready ??= $this->db->tableExists(self::TABLE);
    }
}
