<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

/**
 * How a stored Synaplan credential is sent. An API key (`sk_…`) stays in
 * X-API-Key. An OAuth access token is a JWT and must be a bearer: Synaplan
 * ignores a bearer on a request that also carries an API key.
 */
final class SynaplanCredential
{
    public static function isBearer(string $key): bool
    {
        return $key !== '' && !str_starts_with($key, 'sk_') && substr_count($key, '.') === 2;
    }

    /**
     * @return array<string, string>
     */
    public static function headers(string $key, string $accept = 'application/json'): array
    {
        $headers = ['Accept' => $accept];
        if ($key === '') {
            return $headers;
        }
        if (self::isBearer($key)) {
            $headers['Authorization'] = 'Bearer ' . $key;

            return $headers;
        }
        $headers['X-API-Key'] = $key;

        return $headers;
    }

    /**
     * @return list<string>
     */
    public static function curlHeaders(string $key, string $accept = 'application/json'): array
    {
        $lines = [];
        foreach (self::headers($key, $accept) as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }
}
