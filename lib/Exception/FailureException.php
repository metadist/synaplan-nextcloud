<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Exception;

use OCA\SynaplanIntegration\Http\Failure;
use OCP\AppFramework\Http;

/**
 * A state the app recognises itself, with the failure the browser gets. The
 * exception message is for the log.
 */
final class FailureException extends \RuntimeException
{
    /**
     * @param bool $passing whether trying again later may succeed, as for
     *                      an answer Synaplan sent that could not be read
     */
    public function __construct(
        public readonly Failure $failure,
        string $logMessage,
        public readonly bool $passing = false,
    ) {
        parent::__construct($logMessage);
    }

    public static function notConnected(): self
    {
        return new self(new Failure(Http::STATUS_CONFLICT, Failure::NOT_CONNECTED), 'No Synaplan account connected');
    }

    public static function consentRequired(): self
    {
        return new self(new Failure(Http::STATUS_CONFLICT, Failure::CONSENT_REQUIRED), 'AI not activated by the user');
    }

    public static function accountUnavailable(): self
    {
        return new self(
            new Failure(Http::STATUS_BAD_GATEWAY, Failure::ACCOUNT_UNAVAILABLE),
            'No Synaplan key for a user who activated AI: provisioning failed or the key was refused',
        );
    }

    public static function fileTooLarge(int $maxBytes): self
    {
        return new self(
            new Failure(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, Failure::FILE_TOO_LARGE),
            'File larger than ' . $maxBytes . ' bytes',
        );
    }

    /**
     * A file from Synaplan larger than a copy into the person's files may be.
     */
    public static function copyTooLarge(int $maxBytes): self
    {
        return new self(
            new Failure(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, Failure::COPY_TOO_LARGE),
            'File from Synaplan larger than ' . $maxBytes . ' bytes',
        );
    }

    public static function unsupportedType(string $mimeType): self
    {
        return new self(
            new Failure(Http::STATUS_UNSUPPORTED_MEDIA_TYPE, Failure::UNSUPPORTED_TYPE),
            'Unsupported file type ' . $mimeType,
        );
    }

    public static function fileEmpty(): self
    {
        return new self(new Failure(Http::STATUS_UNPROCESSABLE_ENTITY, Failure::FILE_EMPTY), 'File is empty');
    }

    public static function noText(string $fileName): self
    {
        return new self(
            new Failure(Http::STATUS_UNPROCESSABLE_ENTITY, Failure::NO_TEXT),
            'No text extracted from ' . $fileName,
        );
    }

    /**
     * Synaplan accepted the request but refused the file and said why (storage
     * quota, rate limit, unsupported type) in a sentence of its own.
     */
    public static function refusedBySynaplan(string $sentence): self
    {
        return new self(
            Failure::fromSynaplanResponse(Http::STATUS_UNPROCESSABLE_ENTITY, (string) json_encode(['error' => $sentence])),
            'Synaplan refused the file: ' . $sentence,
        );
    }

    public static function translationUnavailable(): self
    {
        return new self(
            new Failure(Http::STATUS_UNPROCESSABLE_ENTITY, Failure::TRANSLATION_UNAVAILABLE),
            'Connected Synaplan does not list translation',
        );
    }

    public static function invalidSynaplanAnswer(): self
    {
        return new self(new Failure(Http::STATUS_BAD_GATEWAY, Failure::SYNAPLAN_ERROR), 'Invalid JSON response from Synaplan API', true);
    }
}
