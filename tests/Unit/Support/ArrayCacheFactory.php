<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Support;

use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;

/**
 * Hands every caller the same {@see ArrayCache}.
 */
final class ArrayCacheFactory implements ICacheFactory
{
    public function __construct(
        public ArrayCache $cache = new ArrayCache(),
    ) {
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function isLocalCacheAvailable(): bool
    {
        return true;
    }

    public function createLocking(string $prefix = ''): IMemcache
    {
        throw new \RuntimeException('Locking cache is not used here.');
    }

    public function createDistributed(string $prefix = ''): ICache
    {
        return $this->cache;
    }

    public function createLocal(string $prefix = ''): ICache
    {
        return $this->cache;
    }

    public function createInMemory(int $capacity = 512): ICache
    {
        return $this->cache;
    }
}
