<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\OwnWrites;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The statements the marks write, as text, against a table of marks and
 * the files Nextcloud still has.
 */
final class OwnWritesTest extends TestCase
{
    /** @var list<list<string>> */
    private array $statements = [];
    /** @var array<int, string> file id => version the app wrote */
    private array $marks = [];
    /** @var list<int> */
    private array $files = [];

    public function testAMarkIsKeptForAsLongAsTheFileExists(): void
    {
        $this->marks = [7 => 'e7'];
        $this->files = [7];
        $writes = $this->ownWrites();

        $writes->prune();

        self::assertSame([7 => 'e7'], $this->marks);
        self::assertTrue($writes->isOwnVersion(7, 'e7'));

        $this->files = [];
        $writes->prune();

        self::assertSame([], $this->marks);
    }

    public function testAPersonsChangeEndsTheMark(): void
    {
        $this->marks = [7 => 'e7', 8 => 'e8'];
        $this->files = [7, 8];
        $writes = $this->ownWrites();

        self::assertFalse($writes->isOwnVersion(7, 'e7-edited'));

        self::assertSame([8 => 'e8'], $this->marks);
        self::assertFalse($writes->isOwnVersion(7, 'e7'));
    }

    private function ownWrites(): OwnWrites
    {
        $db = $this->createStub(IDBConnection::class);
        $db->method('tableExists')->willReturn(true);
        $db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->builder());

        return new OwnWrites($db);
    }

    /**
     * Runs the few statements OwnWrites writes against $marks and $files.
     */
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
        $expr->method('isNull')->willReturnCallback(static fn (mixed $x): string => $x . ' IS NULL');
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnCallback(function (mixed $value): IParameter {
            $parameter = $this->createStub(IParameter::class);
            $parameter->method('__toString')->willReturn((string) json_encode($value));

            return $parameter;
        });
        foreach (['select', 'delete'] as $kind) {
            $qb->method($kind)->willReturnCallback(static function (mixed $what) use ($qb, $record, $kind): IQueryBuilder {
                $record($kind . ' ' . $what);

                return $qb;
            });
        }
        $qb->method('from')->willReturnCallback(static function (string $table, ?string $alias = null) use ($qb, $record): IQueryBuilder {
            $record('from ' . $table . ($alias !== null ? ' ' . $alias : ''));

            return $qb;
        });
        $qb->method('leftJoin')->willReturnCallback(static function (string $from, string $table, string $alias, mixed $on) use ($qb, $record): IQueryBuilder {
            $record('left join ' . $table . ' ' . $alias . ' on ' . $on);

            return $qb;
        });
        foreach (['where', 'andWhere'] as $method) {
            $qb->method($method)->willReturnCallback(static function (mixed $condition) use ($qb, $record): IQueryBuilder {
                $record('where ' . (string) $condition);

                return $qb;
            });
        }
        $qb->method('setMaxResults')->willReturn($qb);
        $qb->method('executeQuery')->willReturnCallback(function () use ($at): IResult {
            $rows = $this->select($this->statements[$at]);
            $result = $this->createStub(IResult::class);
            $result->method('fetchOne')->willReturnCallback(static function () use (&$rows): mixed {
                return array_shift($rows) ?? false;
            });

            return $result;
        });
        $qb->method('executeStatement')->willReturnCallback(fn (): int => $this->delete($this->statements[$at]));

        return $qb;
    }

    /**
     * @param list<string> $statement
     * @return list<int|string>
     */
    private function select(array $statement): array
    {
        if ($statement === [
            'select o.file_id',
            'from synaplan_own_writes o',
            'left join filecache f on f.fileid=o.file_id',
            'where f.fileid IS NULL',
        ]) {
            return array_values(array_diff(array_keys($this->marks), $this->files));
        }
        if (count($statement) === 3 && $statement[0] === 'select version' && preg_match('/^where file_id=(\d+)$/', $statement[2], $m) === 1) {
            return isset($this->marks[(int) $m[1]]) ? [$this->marks[(int) $m[1]]] : [];
        }
        self::fail('Unexpected query: ' . implode(' | ', $statement));
    }

    /**
     * @param list<string> $statement
     */
    private function delete(array $statement): int
    {
        self::assertSame('delete synaplan_own_writes', $statement[0]);
        $before = count($this->marks);
        if (preg_match('/^where file_id IN (\[[\d,]*\])$/', $statement[1] ?? '', $m) === 1) {
            foreach ((array) json_decode($m[1]) as $id) {
                unset($this->marks[(int) $id]);
            }
        } elseif (preg_match('/^where file_id=(\d+)$/', $statement[1] ?? '', $m) === 1
            && preg_match('/^where version=(".*")$/', $statement[2] ?? '', $v) === 1) {
            if (($this->marks[(int) $m[1]] ?? null) === json_decode($v[1])) {
                unset($this->marks[(int) $m[1]]);
            }
        } else {
            self::fail('Unexpected statement: ' . implode(' | ', $statement));
        }

        return $before - count($this->marks);
    }
}
