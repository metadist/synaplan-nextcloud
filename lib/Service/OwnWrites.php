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
 * other. A mark lasts while the file has that version, however long: a
 * watch can be paused or reconciled long after the write.
 */
class OwnWrites
{
    private const TABLE = 'synaplan_own_writes';
    private const PRUNE_BATCH = 1000;

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
        if (!is_string($written)) {
            return false;
        }
        if ($written === $version) {
            return true;
        }
        $delete = $this->db->getQueryBuilder();
        $delete->delete(self::TABLE)
            ->where($delete->expr()->eq('file_id', $delete->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->andWhere($delete->expr()->eq('version', $delete->createNamedParameter($written)));
        $delete->executeStatement();

        return false;
    }

    /**
     * Forget the marks of files that are gone.
     */
    public function prune(): void
    {
        if (!$this->ready()) {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('o.file_id')->from(self::TABLE, 'o')
            ->leftJoin('o', 'filecache', 'f', $qb->expr()->eq('f.fileid', 'o.file_id'))
            ->where($qb->expr()->isNull('f.fileid'))
            ->setMaxResults(self::PRUNE_BATCH);
        $result = $qb->executeQuery();
        $gone = [];
        while (($id = $result->fetchOne()) !== false) {
            $gone[] = (int) $id;
        }
        $result->closeCursor();
        if ($gone === []) {
            return;
        }
        $delete = $this->db->getQueryBuilder();
        $delete->delete(self::TABLE)
            ->where($delete->expr()->in('file_id', $delete->createNamedParameter($gone, IQueryBuilder::PARAM_INT_ARRAY)));
        $delete->executeStatement();
    }

    private function ready(): bool
    {
        return $this->ready ??= $this->db->tableExists(self::TABLE);
    }
}
