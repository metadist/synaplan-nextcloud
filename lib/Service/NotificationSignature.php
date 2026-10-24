<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

/**
 * Checks a signed Synaplan notification: Ed25519 over the body without `sig`,
 * keys sorted, as compact JSON. The five vectors in
 * `tests/fixtures/notify-vectors.json` (copied from Synaplan's
 * `notify-vectors.json`) pin the bytes.
 */
final class NotificationSignature
{
    public const OK = 'ok';
    public const BAD_SIGNATURE = 'bad_signature';
    public const WRONG_INSTANCE = 'wrong_instance';
    public const STALE = 'stale';
    public const REPLAY = 'replay';

    public const WINDOW_SECONDS = 300;

    private const PREFIX = 'ed25519:';

    /**
     * @param array<string, mixed> $body
     * @param list<array{publicKey?: mixed, kid?: mixed}> $keys
     */
    public static function verify(array $body, string $instanceId, array $keys, int $now, int $window = self::WINDOW_SECONDS): string
    {
        $signature = $body['sig'] ?? null;
        if (!is_string($signature) || $signature === '') {
            return self::BAD_SIGNATURE;
        }
        if (($body['instance_id'] ?? null) !== $instanceId) {
            return self::WRONG_INSTANCE;
        }
        $timestamp = $body['ts'] ?? null;
        if (!is_int($timestamp) || abs($now - $timestamp) > $window) {
            return self::STALE;
        }
        $message = self::canonical($body);
        foreach ($keys as $key) {
            $publicKey = $key['publicKey'] ?? null;
            if (is_string($publicKey) && self::verifies($message, $signature, $publicKey)) {
                return self::OK;
            }
        }

        return self::BAD_SIGNATURE;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function canonical(array $body): string
    {
        unset($body['sig']);

        return json_encode(self::sorted($body), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function verifies(string $message, string $signature, string $publicKey): bool
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }
        $sig = self::decode($signature, SODIUM_CRYPTO_SIGN_BYTES);
        $key = self::decode($publicKey, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);

        return $sig !== null && $key !== null && sodium_crypto_sign_verify_detached($sig, $message, $key);
    }

    private static function decode(string $encoded, int $length): ?string
    {
        if (!str_starts_with($encoded, self::PREFIX)) {
            return null;
        }
        $b64 = strtr(substr($encoded, strlen(self::PREFIX)), '-_', '+/');
        $raw = base64_decode($b64 . str_repeat('=', (4 - strlen($b64) % 4) % 4), true);

        return is_string($raw) && strlen($raw) === $length ? $raw : null;
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function sorted(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sorted($item);
            }
        }

        return $value;
    }
}
