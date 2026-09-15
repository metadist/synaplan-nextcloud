<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Exception;

/**
 * Handshake or instance-registration failure with a machine-readable code
 * used by the callback redirect (`state` | `exchange` | `instance_pending`).
 */
class PlatformLinkException extends \RuntimeException
{
    public const CODE_STATE = 'state';
    public const CODE_EXCHANGE = 'exchange';
    public const CODE_INSTANCE_PENDING = 'instance_pending';

    public function __construct(
        string $message,
        private string $errorCode = self::CODE_EXCHANGE,
    ) {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
