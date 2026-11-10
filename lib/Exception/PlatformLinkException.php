<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Exception;

/**
 * A failed exchange with Synaplan's OAuth2 server, with a machine-readable
 * code; the connect callback puts it into its redirect.
 */
class PlatformLinkException extends \RuntimeException
{
    public const CODE_STATE = 'state';
    public const CODE_EXCHANGE = 'exchange';
    public const CODE_INSTANCE_PENDING = 'instance_pending';
    /** Synaplan ended the grant (revoked, or the link was disconnected there). */
    public const CODE_GRANT_ENDED = 'grant_ended';
    /**
     * Synaplan does not accept this Nextcloud as its client: the registration
     * was revoked or removed there. It ends for everybody until an
     * administrator registers again.
     */
    public const CODE_CLIENT_REFUSED = 'client_refused';
    public const CODE_UNREACHABLE = 'unreachable';
    public const CODE_NOT_REGISTERED = 'not_registered';
    /** The person did not allow the connection on Synaplan's consent page. */
    public const CODE_DENIED = 'denied';

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
