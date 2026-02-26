<?php

declare(strict_types=1);

/**
 * Minimal OC stub for unit tests.
 *
 * Nextcloud's OCP\Util and OCP\Server reference \OC::$server internally.
 * This stub provides a no-op server so tests can call code paths that
 * trigger addScript() / addTranslations() without fatal errors.
 */
class OC
{
    /** @var \OC\Server|null */
    public static $server = null;
}
