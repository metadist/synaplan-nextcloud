<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\WatchIndex;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
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
        foreach (['where', 'andWhere'] as $method) {
            $qb->method($method)->willReturnCallback(static function (string $condition) use ($qb, $record): IQueryBuilder {
                $record('where ' . $condition);

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
