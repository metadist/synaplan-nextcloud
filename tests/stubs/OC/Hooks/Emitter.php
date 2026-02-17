<?php

declare(strict_types=1);

namespace OC\Hooks;

/**
 * Stub for testing — the real interface lives in Nextcloud server internals.
 */
interface Emitter
{
	public function listen(string $scope, string $method, callable $callback): void;
	public function removeListener(?string $scope = null, ?string $method = null, ?callable $callback = null): void;
}
