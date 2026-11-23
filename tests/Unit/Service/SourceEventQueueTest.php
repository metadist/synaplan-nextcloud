<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\SourceEventQueue;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\ILiteral;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The statements the queue builds, as text: `kind table`, then each value,
 * `set`, condition and order.
 */
final class SourceEventQueueTest extends TestCase
{
    /** @var list<list<string>> */
    private array $statements = [];
    /** @var list<string> */
    private array $sql = [];
    /** The file cache path of folder 50 on storage 3, null when it is not there. */
    private ?string $folderPath = null;
    /** @var list<array<string, mixed>> the people with held rows */
    private array $heldFor = [];
    /** @var list<array<string, mixed>> the page the file cache answers */
    private array $cacheRows = [];

    public function testAPersonsHeadIsReadInTheOrderTheEventsHappened(): void
    {
        $this->queue()->head('ada', 100);

        self::assertSame(
            ['select synaplan_source_events', 'where uid="ada"', 'order ord ASC', 'order seq ASC', 'limit 100'],
            $this->statements[0],
        );
    }

    public function testAnEventIsQueuedWithItsOrderAndStep(): void
    {
        $this->queue()->add('ada', ['id' => '42', 'event' => 'deleted', 'version' => 'e1', 'path' => ''], 1_700, SourceEventQueue::STEP_SEND);

        self::assertSame([
            'insert synaplan_source_events',
            'value ord=1700',
            'value uid="ada"',
            'value file_id=42',
            'value event="deleted"',
            'value version="e1"',
            'value path=""',
            'value step="send"',
            'value walked=0',
        ], $this->statements[0]);
    }

    public function testTheOldestEventStillToGoToOthersIsFoundAcrossPeople(): void
    {
        self::assertNull($this->queue()->oldestShare('carol'));
        self::assertSame(
            ['select synaplan_source_events', 'where step="share"', 'where uid<>"carol"', 'order ord ASC', 'limit 1'],
            $this->statements[0],
        );
    }

    public function testARetryHoldsTheRowsBackUntilTheyAreDue(): void
    {
        $this->queue()->retryLater([3, 4], 2, 1_300);

        self::assertSame(['update synaplan_source_events', 'set attempts=2', 'set due=1300', 'where seq IN [3,4]'], $this->statements[0]);
    }

    public function testRemovingManyRowsTakesChunksTheDatabaseAccepts(): void
    {
        $this->queue()->remove(range(1, 501));

        self::assertCount(2, $this->statements);
        self::assertSame('where seq IN [501]', $this->statements[1][1]);
    }

    public function testAFoldersFilesAreQueuedInOneStatementFromTheFileCache(): void
    {
        $this->folderPath = 'files/Specs_1';
        $this->queue()->addDescendants('ada', 50, 'deleted', 1_700, SourceEventQueue::STEP_HELD, 50);

        self::assertSame([
            'INSERT INTO `*PREFIX*synaplan_source_events` (`ord`, `uid`, `file_id`, `event`, `version`, `path`, `step`, `walked`) SELECT',
        ], $this->sql);
        self::assertSame([
            'select 1700,"ada",fileid,"deleted",etag,"","held",50',
            'select filecache',
            'where storage=3',
            'where path LIKE "files/Specs\\\\_1/%"',
        ], $this->statements[1]);
        self::assertSame(['select filecache', 'where fileid=50'], $this->statements[0]);
    }

    public function testTheSubtreesSomeoneKeepsStayOutOfTheirFoldersFiles(): void
    {
        $this->folderPath = 'files/Specs';
        $this->queue()->addDescendants('bob', 50, 'deleted', 1_700, SourceEventQueue::STEP_SEND, 0, ['files/Specs/Old_1']);

        self::assertSame([
            'select 1700,"bob",fileid,"deleted",etag,"","send",0',
            'select filecache',
            'where storage=3',
            'where path LIKE "files/Specs/%"',
            'where path_hash<>"' . md5('files/Specs/Old_1') . '"',
            'where path NOT LIKE "files/Specs/Old\\\\_1/%"',
        ], $this->statements[1]);
    }

    public function testAMovedFolderIsReadAPageAtATimeByFileIdWithPathsBelowIt(): void
    {
        $this->folderPath = 'files/Specs';
        $this->cacheRows = [['fileid' => 121, 'path' => 'files/Specs/Old/b.pdf', 'etag' => 'eb']];

        self::assertSame([['id' => 121, 'path' => 'Old/b.pdf', 'etag' => 'eb']], $this->queue()->descendants(50, 120, 500));
        self::assertSame(
            ['select filecache', 'where storage=3', 'where path LIKE "files/Specs/%"', 'where fileid > 120', 'order fileid ASC', 'limit 500'],
            $this->statements[1],
        );
    }

    public function testAFolderNoLongerInTheFileCacheQueuesNothing(): void
    {
        $this->queue()->addDescendants('ada', 50, 'deleted', 1_700, SourceEventQueue::STEP_HELD, 50);

        self::assertSame([], $this->sql);
    }

    public function testADeletionThatHappenedReleasesTheHeldRowsOfItsLastAttemptOnly(): void
    {
        $this->heldFor = [['uid' => 'bob', 'ord' => 10], ['uid' => 'ada', 'ord' => 20], ['uid' => 'bob', 'ord' => 20]];

        self::assertSame(['ada', 'bob'], $this->queue()->release(50));
        self::assertSame(['select synaplan_source_events', 'where step="held"', 'where walked=50'], $this->statements[0]);
        self::assertSame(['update synaplan_source_events', 'set step="send"', 'where step="held"', 'where walked=50', 'where ord=20'], $this->statements[1]);
        self::assertSame(['delete synaplan_source_events', 'where step="held"', 'where walked=50', 'where ord < 20'], $this->statements[2]);
    }

    public function testADeletionAttemptThatNeverHappenedDropsItsOwnHeldRows(): void
    {
        $this->queue()->removeHeld('ada', 50, 10);

        self::assertSame(['delete synaplan_source_events', 'where uid="ada"', 'where step="held"', 'where walked=50', 'where ord=10'], $this->statements[0]);
    }

    private function queue(): SourceEventQueue
    {
        $db = $this->createStub(IDBConnection::class);
        $db->method('escapeLikeParameter')->willReturnCallback(static fn (string $value): string => addcslashes($value, '\\_%'));
        $db->method('executeStatement')->willReturnCallback(function (string $sql): int {
            $this->sql[] = $sql;

            return 1;
        });
        $db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->builder());

        return new SourceEventQueue($db);
    }

    private function builder(): IQueryBuilder
    {
        $at = count($this->statements);
        $this->statements[$at] = [];
        $record = function (string $line) use ($at): void {
            $this->statements[$at][] = $line;
        };
        $qb = $this->createStub(IQueryBuilder::class);
        $expr = $this->createStub(IExpressionBuilder::class);
        $expr->method('eq')->willReturnCallback(static fn (mixed $x, mixed $y): string => $x . '=' . $y);
        $expr->method('in')->willReturnCallback(static fn (mixed $x, mixed $y): string => $x . ' IN ' . $y);
        $expr->method('like')->willReturnCallback(static fn (mixed $x, mixed $y): string => $x . ' LIKE ' . $y);
        $expr->method('notLike')->willReturnCallback(static fn (mixed $x, mixed $y): string => $x . ' NOT LIKE ' . $y);
        $expr->method('neq')->willReturnCallback(static fn (mixed $x, mixed $y): string => $x . '<>' . $y);
        $expr->method('gt')->willReturnCallback(static fn (mixed $x, mixed $y): string => $x . ' > ' . $y);
        $expr->method('lt')->willReturnCallback(static fn (mixed $x, mixed $y): string => $x . ' < ' . $y);
        $expr->method('literal')->willReturnCallback(function (mixed $value): ILiteral {
            $literal = $this->createStub(ILiteral::class);
            $literal->method('__toString')->willReturn((string) json_encode($value, JSON_UNESCAPED_SLASHES));

            return $literal;
        });
        $qb->method('getTableName')->willReturnCallback(static fn (string $table): string => '`*PREFIX*' . $table . '`');
        $qb->method('getColumnName')->willReturnCallback(static fn (string $column): string => '`' . $column . '`');
        $qb->method('getSQL')->willReturn('SELECT');
        $qb->method('getParameters')->willReturn([]);
        $qb->method('getParameterTypes')->willReturn([]);
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnCallback(function (mixed $value): IParameter {
            $parameter = $this->createStub(IParameter::class);
            $parameter->method('__toString')->willReturn((string) json_encode($value, JSON_UNESCAPED_SLASHES));

            return $parameter;
        });
        foreach (['insert', 'update', 'delete', 'from'] as $kind) {
            $qb->method($kind)->willReturnCallback(static function (string $table) use ($qb, $record, $kind): IQueryBuilder {
                $record(($kind === 'from' ? 'select' : $kind) . ' ' . $table);

                return $qb;
            });
        }
        $qb->method('selectDistinct')->willReturn($qb);
        $qb->method('select')->willReturnCallback(static function (mixed ...$columns) use ($qb, $record): IQueryBuilder {
            if (array_filter($columns, static fn (mixed $column): bool => !is_string($column)) !== []) {
                $record('select ' . implode(',', array_map(static fn (mixed $column): string => (string) $column, $columns)));
            }

            return $qb;
        });
        $qb->method('values')->willReturnCallback(static function (array $values) use ($qb, $record): IQueryBuilder {
            foreach ($values as $column => $value) {
                $record('value ' . $column . '=' . $value);
            }

            return $qb;
        });
        $qb->method('set')->willReturnCallback(static function (string $column, mixed $value) use ($qb, $record): IQueryBuilder {
            $record('set ' . $column . '=' . $value);

            return $qb;
        });
        $qb->method('andWhere')->willReturnCallback(static function (string $condition) use ($qb, $record): IQueryBuilder {
            $record('where ' . $condition);

            return $qb;
        });
        $qb->method('where')->willReturnCallback(static function (string $condition) use ($qb, $record): IQueryBuilder {
            $record('where ' . $condition);

            return $qb;
        });
        foreach (['orderBy', 'addOrderBy'] as $method) {
            $qb->method($method)->willReturnCallback(static function (string $column, string $direction) use ($qb, $record): IQueryBuilder {
                $record('order ' . $column . ' ' . $direction);

                return $qb;
            });
        }
        $qb->method('setMaxResults')->willReturnCallback(static function (int $limit) use ($qb, $record): IQueryBuilder {
            $record('limit ' . $limit);

            return $qb;
        });
        $qb->method('executeQuery')->willReturnCallback(function () use ($at): IResult {
            $lines = $this->statements[$at];
            $rows = match (true) {
                in_array('where fileid=50', $lines, true) => $this->folderPath === null ? [] : [['storage' => 3, 'path' => $this->folderPath]],
                ($lines[0] ?? '') === 'select filecache' => $this->cacheRows,
                in_array('where step="held"', $lines, true) => $this->heldFor,
                default => [],
            };
            $result = $this->createStub(IResult::class);
            $result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);

            return $result;
        });
        $qb->method('executeStatement')->willReturn(1);

        return $qb;
    }
}
