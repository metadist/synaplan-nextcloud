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
    /** Synaplan refused to mint a key because this install sells a paid plan. */
    public const API_PLAN_REQUIRED = 'api_plan_required';
    /** The person's email is already a Synaplan account. */
    public const EMAIL_ALREADY_USED = 'email_already_used';
    /** Provision mode has no administrator key that can create accounts. */
    public const ADMIN_KEY_MISSING = 'admin_key_missing';
    public const FILE_TOO_LARGE = 'file_too_large';
    public const UNSUPPORTED_TYPE = 'unsupported_type';
    public const FILE_EMPTY = 'file_empty';
    public const NO_TEXT = 'no_text';
    public const TRANSLATION_UNAVAILABLE = 'translation_unavailable';
    public const SYNAPLAN_UNREACHABLE = 'synaplan_unreachable';
    public const SYNAPLAN_ERROR = 'synaplan_error';
    public const SYNAPLAN_KEY_REJECTED = 'synaplan_key_rejected';
    public const SYNAPLAN_REJECTED = 'synaplan_rejected';
    public const RATE_LIMITED = 'rate_limited';
    public const UNEXPECTED = 'unexpected';
    /** Only people who may edit a folder or a file can watch it. */
    public const WATCH_NEEDS_EDIT = 'watch_needs_edit';
    /** The person cannot read the folder or file they asked to watch. */
    public const NO_ACCESS = 'no_access';
    /** The administrator turned off watching folders. */
    public const WATCHES_OFF = 'watches_off';
    /** The administrator turned off watching shared folders. */
    public const WATCH_SHARED_OFF = 'watch_shared_off';

    private const MESSAGE_MAX_LENGTH = 300;

    /**
     * @param Http::STATUS_* $status
     * @param list<string> $required scopes Synaplan said this action needs
     */
    public function __construct(
        public readonly int $status,
        public readonly string $code,
        public readonly ?string $message = null,
        public readonly ?string $module = null,
        public readonly array $required = [],
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
            return new self(Http::STATUS_BAD_GATEWAY, self::SYNAPLAN_ERROR, self::statusReason($status));
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
                default => new self(Http::STATUS_BAD_GATEWAY, self::SYNAPLAN_ERROR, self::statusReason($status)),
            };
        }
        [$code, $message] = self::errorOf($decoded);
        $module = self::moduleOf($decoded['module'] ?? null);
        $required = self::requiredOf($decoded['required'] ?? null);

        return new self($kept, $code ?? match ($kept) {
            Http::STATUS_TOO_MANY_REQUESTS => self::RATE_LIMITED,
            Http::STATUS_REQUEST_ENTITY_TOO_LARGE => self::FILE_TOO_LARGE,
            default => self::SYNAPLAN_REJECTED,
        }, $message, $module, $required);
    }

    /**
     * A module id Synaplan names (`cloud_files`). A path, a URL or a sentence is not one.
     */
    private static function moduleOf(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-z][a-z0-9_]{0,40}$/', $value) === 1 ? $value : null;
    }

    /**
     * The scopes Synaplan said were missing (`cloud:watch`). Anything else in
     * that list, including what the key already has, stays out of the answer.
     *
     * @return list<string>
     */
    private static function requiredOf(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $scopes = [];
        foreach ($value as $item) {
            if (!is_string($item) || strlen($item) > 40 || preg_match('/^[a-z][a-z0-9_]*(\:[a-z*][a-z0-9_*]*)?$/', $item) !== 1) {
                continue;
            }
            $scopes[] = $item;
            if (count($scopes) === 8) {
                break;
            }
        }

        return $scopes;
    }

    /**
     * Synaplan's machine code and sentence from its error body, in the shapes
     * its endpoints use: `{error: "code"}`, `{error: "Sentence", code: "code"}`
     * and, from the chat completions endpoint, `{error: {code, message}}`.
     *
     * @param array<mixed> $decoded
     * @return array{0: ?string, 1: ?string}
     */
    private static function errorOf(array $decoded): array
    {
        $error = $decoded['error'] ?? null;
        $code = $decoded['code'] ?? null;
        $text = $error;
        if (is_array($error)) {
            $code = $error['code'] ?? null;
            $text = $error['message'] ?? null;
        }
        $code = is_string($code) && self::isCode(trim($code)) ? trim($code) : null;
        $text = is_string($text) ? trim($text) : '';
        if (self::isCode($text)) {
            $code ??= $text;
            $alongside = $decoded['message'] ?? null;
            $alongside = is_string($alongside) ? trim($alongside) : '';

            return [$code, self::sentence($alongside)];
        }
        $sentence = self::sentence($text);

        return [$code, $sentence];
    }

    /**
     * The status, when that is all a non-JSON body can safely say. The page
     * itself stays out of the message.
     */
    private static function statusReason(int $status): ?string
    {
        if ($status < 400) {
            return null;
        }

        return 'Synaplan answered HTTP ' . $status . '.';
    }

    /**
     * A sentence that may be shown to the person. Synaplan's own wording is
     * kept; a code, a page, a URL, SQL or a stack trace is not.
     */
    public static function safeSentence(string $text): ?string
    {
        $sentence = self::sentence($text);
        if ($sentence === null) {
            return null;
        }
        $lower = strtolower($sentence);
        if (
            str_contains($lower, 'http://')
            || str_contains($lower, 'https://')
            || str_contains($lower, 'sqlstate')
            || str_contains($lower, '.php')
            || str_contains($sentence, '/var/')
            || str_contains($lower, 'stack trace')
            || str_contains($sentence, '<')
            || $lower === 'an error occurred'
        ) {
            return null;
        }

        return $sentence;
    }

    /**
     * A sentence Synaplan sent beside a code. A machine code, an empty string
     * and a body long enough to be a page are not one.
     */
    private static function sentence(string $text): ?string
    {
        if ($text === '' || self::isCode($text) || mb_strlen($text) > self::MESSAGE_MAX_LENGTH) {
            return null;
        }

        return $text;
    }

    private static function isCode(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*$/', $value) === 1;
    }

    /**
     * @param array<string, mixed> $extra keys the frontend reads beside the failure
     * @return JSONResponse<Http::STATUS_*, array<string, mixed>, array{}>
     */
    public function toResponse(array $extra = []): JSONResponse
    {
        $body = ['success' => false, 'error' => $this->code];
        if ($this->message !== null) {
            $body['message'] = $this->message;
        }
        if ($this->module !== null) {
            $body['module'] = $this->module;
        }
        if ($this->required !== []) {
            $body['required'] = $this->required;
        }

        return new JSONResponse(array_merge($extra, $body), $this->status);
    }
}
