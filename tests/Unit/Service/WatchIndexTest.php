<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\WatchIndex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WatchIndexTest extends TestCase
{
    public function testAWatchAsSynaplanListsItIsKeptByItsTarget(): void
    {
        self::assertSame(
            ['id' => 5, 'kind' => 'folder', 'target' => 30, 'state' => 'active'],
            WatchIndex::parse(['id' => 5, 'target_kind' => 'folder', 'target_id' => '30', 'state' => 'active', 'name' => 'Incoming']),
        );
        self::assertSame(
            ['id' => 6, 'kind' => 'file', 'target' => 31, 'state' => 'paused'],
            WatchIndex::parse(['id' => '6', 'target_kind' => 'file', 'target_id' => 31, 'state' => 'paused']),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unusable(): iterable
    {
        $good = ['id' => 5, 'target_kind' => 'folder', 'target_id' => '30', 'state' => 'active'];
        yield 'no id' => [['id' => null] + $good];
        yield 'another cloud kind' => [['target_kind' => 'space'] + $good];
        yield 'a target id that is not a file id here' => [['target_id' => 'abc-123'] + $good];
        yield 'no state' => [['state' => ''] + $good];
    }

    /**
     * @param array<string, mixed> $watch
     */
    #[DataProvider('unusable')]
    public function testAWatchThisAppCannotMatchIsLeftOut(array $watch): void
    {
        self::assertNull(WatchIndex::parse($watch));
    }
}
