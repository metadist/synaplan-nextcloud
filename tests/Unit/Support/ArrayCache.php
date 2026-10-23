<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Support;

use OCP\ICache;

/**
 * A process-local cache for unit tests. One instance is shared by every
 * create* call so a service and the test see the same entries.
 */
final class ArrayCache implements ICache
{
    /** @var array<string, mixed> */
    public array $values = [];

    public function get($key)
    {
        return $this->values[(string) $key] ?? null;
    }

    public function set($key, $value, $ttl = 0)
    {
        $this->values[(string) $key] = $value;

        return true;
    }

    public function hasKey($key)
    {
        return array_key_exists((string) $key, $this->values);
    }

    public function remove($key)
    {
        unset($this->values[(string) $key]);

        return true;
    }

    public function clear($prefix = '')
    {
        foreach (array_keys($this->values) as $key) {
            if ($prefix === '' || str_starts_with($key, (string) $prefix)) {
                unset($this->values[$key]);
            }
        }

        return true;
    }

    public static function isAvailable(): bool
    {
        return true;
    }
}
