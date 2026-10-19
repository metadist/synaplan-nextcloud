<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Http;

use OCA\SynaplanIntegration\Exception\FailureException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotFoundException;

/**
 * A failed action as the browser sees it: an HTTP status and a code the
 * frontend turns into one sentence (src/failures.ts). Synaplan's own sentence
 * travels along when Synaplan sent one. The exception text never does: it can
 * carry internal addresses.
 */
final class Failure
{
    public const FILE_NOT_FOUND = 'file_not_found';
    public const NOT_CONNECTED = 'not_connected';
    public const CONSENT_REQUIRED = 'consent_required';
    public const ACCOUNT_UNAVAILABLE = 'account_unavailable';
    public const FILE_TOO_LARGE = 'file_too_large';
    public const UNSUPPORTED_TYPE = 'unsupported_type';
    public const FILE_EMPTY = 'file_empty';
    public const NO_TEXT = 'no_text';
    public const SYNAPLAN_UNREACHABLE = 'synaplan_unreachable';
    public const SYNAPLAN_ERROR = 'synaplan_error';
    public const SYNAPLAN_KEY_REJECTED = 'synaplan_key_rejected';
    public const SYNAPLAN_REJECTED = 'synaplan_rejected';
    public const RATE_LIMITED = 'rate_limited';
    public const UNEXPECTED = 'unexpected';

    private const MESSAGE_MAX_LENGTH = 300;

    /**
     * @param Http::STATUS_* $status
     */
    public function __construct(
        public readonly int $status,
        public readonly string $code,
        public readonly ?string $message = null,
    ) {
    }

    public static function fromThrowable(\Throwable $e): self
    {
        if ($e instanceof FailureException) {
            return $e->failure;
        }
        if ($e instanceof NotFoundException) {
            return new self(Http::STATUS_NOT_FOUND, self::FILE_NOT_FOUND);
        }

        $status = (int) $e->getCode();
        if ($status >= 400 && $status < 600 && method_exists($e, 'getResponse')) {
            $response = $e->getResponse();
            $body = is_object($response) && method_exists($response, 'getBody') ? (string) $response->getBody() : '';

            return self::fromSynaplanResponse($status, $body);
        }

        if (str_ends_with($e::class, 'ConnectException') || str_starts_with($e->getMessage(), 'cURL error')) {
            return new self(Http::STATUS_BAD_GATEWAY, self::SYNAPLAN_UNREACHABLE);
        }

        return new self(Http::STATUS_INTERNAL_SERVER_ERROR, self::UNEXPECTED);
    }

    /**
     * A Synaplan answer with an error status. A machine code Synaplan sent
     * (`insufficient_scope`) is kept; a sentence is passed on as the message.
     * Statuses the browser would read as its own session or proxy problem
     * (401, 407, 412) are never passed on. A body that is not Synaplan's JSON
     * (a proxy's error page) is a 502, except 413 and 429, which mean the same
     * whoever sent them.
     */
    public static function fromSynaplanResponse(int $status, string $body): self
    {
        if ($status === Http::STATUS_UNAUTHORIZED) {
            return new self(Http::STATUS_BAD_GATEWAY, self::SYNAPLAN_KEY_REJECTED);
        }
        if ($status >= 500 || $status < 400 || in_array($status, [Http::STATUS_PROXY_AUTHENTICATION_REQUIRED, Http::STATUS_PRECONDITION_FAILED], true)) {
            return new self(Http::STATUS_BAD_GATEWAY, self::SYNAPLAN_ERROR);
        }

        $kept = match ($status) {
            Http::STATUS_BAD_REQUEST => Http::STATUS_BAD_REQUEST,
            Http::STATUS_FORBIDDEN => Http::STATUS_FORBIDDEN,
            Http::STATUS_NOT_FOUND => Http::STATUS_NOT_FOUND,
            Http::STATUS_CONFLICT => Http::STATUS_CONFLICT,
            Http::STATUS_REQUEST_ENTITY_TOO_LARGE => Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
            Http::STATUS_UNSUPPORTED_MEDIA_TYPE => Http::STATUS_UNSUPPORTED_MEDIA_TYPE,
            Http::STATUS_TOO_MANY_REQUESTS => Http::STATUS_TOO_MANY_REQUESTS,
            default => Http::STATUS_UNPROCESSABLE_ENTITY,
        };

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return match ($kept) {
                Http::STATUS_TOO_MANY_REQUESTS => new self($kept, self::RATE_LIMITED),
                Http::STATUS_REQUEST_ENTITY_TOO_LARGE => new self($kept, self::FILE_TOO_LARGE),
                default => new self(Http::STATUS_BAD_GATEWAY, self::SYNAPLAN_ERROR),
            };
        }
        $error = is_string($decoded['error'] ?? null) ? trim($decoded['error']) : '';
        if (preg_match('/^[a-z][a-z0-9_]*$/', $error) === 1) {
            return new self($kept, $error);
        }

        $code = match ($kept) {
            Http::STATUS_TOO_MANY_REQUESTS => self::RATE_LIMITED,
            Http::STATUS_REQUEST_ENTITY_TOO_LARGE => self::FILE_TOO_LARGE,
            default => self::SYNAPLAN_REJECTED,
        };
        $message = $error !== '' && mb_strlen($error) <= self::MESSAGE_MAX_LENGTH ? $error : null;

        return new self($kept, $code, $message);
    }

    /**
     * @param array<string, mixed> $extra keys the frontend reads beside the failure
     */
    public function toResponse(array $extra = []): JSONResponse
    {
        $body = ['success' => false, 'error' => $this->code];
        if ($this->message !== null) {
            $body['message'] = $this->message;
        }

        return new JSONResponse(array_merge($extra, $body), $this->status);
    }
}
