<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\IDBConnection;

/**
 * The files this app wrote for Synaplan: copies, saved media, imported
 * library files. A watch must not get them back, or a translation saved into
 * a watched folder is translated again. The version the app wrote is kept:
 * while the file still has it, a watch is told it is an own write, so its
 * ledger keeps it for good; once a person changed it, it is a file like any
 * other.
 */
class OwnWrites
{
    private const TABLE = 'synaplan_own_writes';
    private const KEEP_SECONDS = 7 * 86400;

    private ?bool $ready = null;

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    /**
     * Call after the write: the node then reports the version it wrote.
     */
    public function note(File $file, int $now): void
    {
        $fileId = (int) $file->getId();
        if ($fileId <= 0 || !$this->ready()) {
            return;
        }
        $version = WatchSender::version($file);
        $insert = $this->db->getQueryBuilder();
        $insert->insert(self::TABLE)->values([
            'file_id' => $insert->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
            'written' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'version' => $insert->createNamedParameter($version),
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
            ->set('version', $update->createNamedParameter($version))
            ->where($update->expr()->eq('file_id', $update->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
        $update->executeStatement();
    }

    /**
     * The file still has the version this app wrote.
     */
    public function isOwnVersion(int $fileId, string $version): bool
    {
        if ($fileId <= 0 || $version === '' || !$this->ready()) {
            return false;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('version')->from(self::TABLE)
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $written = $result->fetchOne();
        $result->closeCursor();

        return is_string($written) && $written === $version;
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
