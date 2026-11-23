<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\SourceEventQueue;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
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

    private function queue(): SourceEventQueue
    {
        $db = $this->createStub(IDBConnection::class);
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
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnCallback(function (mixed $value): IParameter {
            $parameter = $this->createStub(IParameter::class);
            $parameter->method('__toString')->willReturn((string) json_encode($value));

            return $parameter;
        });
        foreach (['insert', 'update', 'delete', 'from'] as $kind) {
            $qb->method($kind)->willReturnCallback(static function (string $table) use ($qb, $record, $kind): IQueryBuilder {
                $record(($kind === 'from' ? 'select' : $kind) . ' ' . $table);

                return $qb;
            });
        }
        $qb->method('select')->willReturn($qb);
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
        $result = $this->createStub(IResult::class);
        $result->method('fetch')->willReturn(false);
        $qb->method('executeQuery')->willReturn($result);
        $qb->method('executeStatement')->willReturn(1);

        return $qb;
    }
}
