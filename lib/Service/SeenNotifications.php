<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Notification ids already accepted, remembered for ten minutes in the
 * database. A repeat is refused. The reservation is one insert, so two
 * workers cannot both accept the same id, and it does not depend on a
 * memory cache being configured.
 */
class SeenNotifications implements SeenNotificationStore
{
    private const TABLE = 'synaplan_seen';
    private const TTL = 600;

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    public function alreadySeen(string $type, string $id): bool
    {
        $now = time();
        $hash = hash('sha256', $type . '|' . $id);
        $this->forgetExpired($now);

        $insert = $this->db->getQueryBuilder();
        $insert->insert(self::TABLE)->values([
            'notice_hash' => $insert->createNamedParameter($hash),
            'expires' => $insert->createNamedParameter($now + self::TTL, IQueryBuilder::PARAM_INT),
        ]);
        try {
            $insert->executeStatement();
        } catch (DBException $e) {
            if ($e->getReason() === DBException::REASON_CONSTRAINT_VIOLATION) {
                return true;
            }
            throw $e;
        }

        return false;
    }

    private function forgetExpired(int $now): void
    {
        $delete = $this->db->getQueryBuilder();
        $delete->delete(self::TABLE)
            ->where($delete->expr()->lt('expires', $delete->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
        $delete->executeStatement();
    }
}
