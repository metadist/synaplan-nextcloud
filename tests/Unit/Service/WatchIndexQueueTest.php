<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\WatchIndex;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The statements the queue writes, as text: `kind table`, then each value,
 * `set` and condition.
 */
final class WatchIndexQueueTest extends TestCase
{
    /** @var list<list<string>> */
    private array $statements = [];
    /** @var list<string> */
    private array $generations = [];
    private bool $rowExists = false;

    public function testEachQueuedChangeGetsANewGeneration(): void
    {
        $index = $this->index();
        $index->enqueue(5, 7, '', 100);
        $this->rowExists = true;
        $index->enqueue(5, 7, '', 100);

        $replaced = $this->generations[array_key_last($this->generations)];
        self::assertNotSame($this->generations[0], $replaced);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $replaced);
        self::assertContains('set generation=' . json_encode($replaced), $this->last());
    }

    public function testAChangeAlreadyQueuedStaysWhenReconciliationQueuesAnOlderOne(): void
    {
        $this->rowExists = true;

        self::assertFalse($this->index()->enqueueIfAbsent(5, 7, '', 100, 160));
        self::assertSame('insert synaplan_watch_queue', $this->last()[0]);
    }

    public function testAFileNotQueuedYetIsQueued(): void
    {
        self::assertTrue($this->index()->enqueueIfAbsent(5, 7, '', 100, 160));
        self::assertContains('value due=160', $this->last());
    }

    public function testSettlingARowMatchesItsGenerationOnly(): void
    {
        $this->index()->done(['watch' => 5, 'file' => 7, 'event' => 'deleted', 'changed' => 100, 'generation' => 'g1', 'attempts' => 0]);

        self::assertSame(['delete synaplan_watch_queue', 'where watch_id=5', 'where file_id=7', 'where generation="g1"'], $this->last());
    }

    public function testARetryOfAReplacedRowLeavesTheNewerChange(): void
    {
        $this->index()->retryLater(['watch' => 5, 'file' => 7, 'event' => '', 'changed' => 100, 'generation' => 'g1', 'attempts' => 0], 160, 1);

        self::assertContains('where generation="g1"', $this->last());
    }

    public function testARowQueuedBeforeGenerationsIsToldByItsTimeAndEvent(): void
    {
        $this->index()->done(['watch' => 5, 'file' => 7, 'event' => '', 'changed' => 100, 'generation' => '', 'attempts' => 0]);

        self::assertSame(
            ['delete synaplan_watch_queue', 'where watch_id=5', 'where file_id=7', 'where generation IS NULL', 'where changed=100', 'where event IS NULL'],
            $this->last(),
        );
    }

    public function testAQueuedDeletionBecomesARestoreAsANewChange(): void
    {
        self::assertTrue($this->index()->replaceEvent(5, 30, 'deleted', 'restored', 200));

        $statement = $this->last();
        self::assertSame('update synaplan_watch_queue', $statement[0]);
        self::assertContains('set event="restored"', $statement);
        self::assertContains('set changed=200', $statement);
        self::assertContains('set generation=' . json_encode($this->generations[0]), $statement);
        self::assertContains('where event="deleted"', $statement);
    }

    public function testAWatchIsNotedAsHavingLostItsTargetAndAsRunningAgain(): void
    {
        $index = $this->index();
        $index->markTarget(5, true);
        self::assertSame(['update synaplan_watches', 'set state="attention"', 'set reason="target_gone"', 'where watch_id=5'], $this->last());

        $index->markTarget(5, false);
        self::assertSame(['update synaplan_watches', 'set state="active"', 'set reason=""', 'where watch_id=5'], $this->last());
    }

    public function testResumingWithOnlyNewFilesLetsGoOfTheFilesQueuedBefore(): void
    {
        $this->index()->restartFrom(5, 200);

        self::assertSame(['update synaplan_watches', 'set since=200', 'where watch_id=5'], $this->statements[0]);
        self::assertSame(['delete synaplan_watch_queue', 'where watch_id=5', 'where event IS NULL', 'where changed<200'], $this->last());
    }

    public function testThePausedWatchesFilesWaitOutsideTheFeedsBatch(): void
    {
        $this->index()->due(1000, 60, 500);

        self::assertSame([
            'select q.*',
            'from synaplan_watch_queue q',
            'left join synaplan_watches w on w.watch_id=q.watch_id',
            'where q.due<=1000',
            'where (q.event IS NOT NULL OR q.changed<=940)',
            'where (q.event IS NOT NULL OR w.watch_id IS NULL OR w.state="active")',
        ], $this->last());
    }

    public function testASyncThatReadSynaplansListBeforeTheResumeLeavesTheNewStart(): void
    {
        $this->rowExists = true;
        $this->index()->replace('ada', [['id' => 5, 'target_kind' => 'folder', 'target_id' => '30', 'state' => 'paused']], 100);

        $updates = array_values(array_filter($this->statements, static fn (array $statement): bool => ($statement[0] ?? '') === 'update synaplan_watches'));
        self::assertCount(1, $updates);
        self::assertSame([], array_values(array_filter($updates[0], static fn (string $line): bool => str_starts_with($line, 'set since'))));
    }

    private function index(): WatchIndex
    {
        $db = $this->createStub(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->builder());

        return new WatchIndex($db);
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
        $expr->method('lte')->willReturnCallback(static fn (mixed $x, mixed $y): string => $x . '<=' . $y);
        $expr->method('isNotNull')->willReturnCallback(static fn (mixed $x): string => $x . ' IS NOT NULL');
        $expr->method('orX')->willReturnCallback(static fn (mixed ...$parts): ICompositeExpression => new class (array_map('strval', $parts)) implements ICompositeExpression {
            /** @param list<string> $parts */
            public function __construct(private array $parts)
            {
            }

            /** @param list<mixed> $parts */
            public function addMultiple(array $parts = []): ICompositeExpression
            {
                return $this;
            }

            public function add($part): ICompositeExpression
            {
                return $this;
            }

            public function count(): int
            {
                return count($this->parts);
            }

            public function getType(): string
            {
                return 'OR';
            }

            public function __toString(): string
            {
                return '(' . implode(' OR ', $this->parts) . ')';
            }
        });
        $expr->method('lt')->willReturnCallback(static fn (mixed $x, mixed $y): string => $x . '<' . $y);
        $expr->method('isNull')->willReturnCallback(static fn (mixed $x): string => $x . ' IS NULL');
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnCallback(function (mixed $value): IParameter {
            $parameter = $this->createStub(IParameter::class);
            $parameter->method('__toString')->willReturn((string) json_encode($value));

            return $parameter;
        });
        foreach (['insert', 'update', 'delete'] as $kind) {
            $qb->method($kind)->willReturnCallback(static function (string $table) use ($qb, $record, $kind): IQueryBuilder {
                $record($kind . ' ' . $table);

                return $qb;
            });
        }
        $qb->method('values')->willReturnCallback(function (array $values) use ($qb, $record): IQueryBuilder {
            foreach ($values as $column => $value) {
                $record('value ' . $column . '=' . $value);
                if ($column === 'generation') {
                    $this->generations[] = (string) json_decode((string) $value);
                }
            }

            return $qb;
        });
        $qb->method('set')->willReturnCallback(function (string $column, mixed $value) use ($qb, $record): IQueryBuilder {
            $record('set ' . $column . '=' . $value);
            if ($column === 'generation') {
                $this->generations[] = (string) json_decode((string) $value);
            }

            return $qb;
        });
        $qb->method('select')->willReturnCallback(static function (mixed ...$columns) use ($qb, $record): IQueryBuilder {
            $record('select ' . implode(', ', $columns));

            return $qb;
        });
        $qb->method('from')->willReturnCallback(static function (string $table, ?string $alias = null) use ($qb, $record): IQueryBuilder {
            $record('from ' . $table . ($alias !== null ? ' ' . $alias : ''));

            return $qb;
        });
        $qb->method('leftJoin')->willReturnCallback(static function (string $from, string $table, string $alias, mixed $on) use ($qb, $record): IQueryBuilder {
            $record('left join ' . $table . ' ' . $alias . ' on ' . $on);

            return $qb;
        });
        $qb->method('orderBy')->willReturn($qb);
        $qb->method('setMaxResults')->willReturn($qb);
        $qb->method('executeQuery')->willReturnCallback(function (): IResult {
            $result = $this->createStub(IResult::class);
            $result->method('fetchAssociative')->willReturn(false);
            $result->method('fetchOne')->willReturn(false);

            return $result;
        });
        foreach (['where', 'andWhere'] as $method) {
            $qb->method($method)->willReturnCallback(static function (mixed $condition) use ($qb, $record): IQueryBuilder {
                $record('where ' . (string) $condition);

                return $qb;
            });
        }
        $qb->method('executeStatement')->willReturnCallback(function () use ($at): int {
            if ($this->rowExists && str_starts_with($this->statements[$at][0] ?? '', 'insert ')) {
                $conflict = $this->createStub(DBException::class);
                $conflict->method('getReason')->willReturn(DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
                throw $conflict;
            }

            return 1;
        });

        return $qb;
    }

    /**
     * @return list<string>
     */
    private function last(): array
    {
        return $this->statements[array_key_last($this->statements)] ?? [];
    }
}
