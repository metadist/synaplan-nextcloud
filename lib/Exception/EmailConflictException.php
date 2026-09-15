<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Exception;

/**
 * Synaplan already has an account for this email (HTTP 409 on provision).
 *
 * In link mode the UI offers to connect that account; provision-only installs
 * keep this as a hard failure.
 */
class EmailConflictException extends \RuntimeException
{
    public function __construct(string $message = 'An account with this email already exists.')
    {
        parent::__construct($message);
    }
}
