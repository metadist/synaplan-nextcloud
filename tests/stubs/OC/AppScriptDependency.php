<?php

declare(strict_types=1);

namespace OC;

/**
 * Minimal stub for OC\AppScriptDependency used by OCP\Util::addScript().
 */
class AppScriptDependency
{
	private string $app;

	/** @var string[] */
	private array $deps;

	/**
	 * @param string[] $deps
	 */
	public function __construct(string $app, array $deps = [])
	{
		$this->app = $app;
		$this->deps = $deps;
	}

	public function addDep(string $dep): void
	{
		$this->deps[] = $dep;
	}

	public function getApp(): string
	{
		return $this->app;
	}

	/**
	 * @return string[]
	 */
	public function getDeps(): array
	{
		return $this->deps;
	}
}
