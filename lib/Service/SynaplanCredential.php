<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

/**
 * How a credential travels to Synaplan: an API key (provisioned or shared,
 * `sk_…`) in X-API-Key, a person's OAuth2 access token as a bearer.
 */
final class SynaplanCredential
{
    /**
     * @return array<string, string>
     */
    public static function header(string $credential): array
    {
        return str_starts_with($credential, 'sk_')
            ? ['X-API-Key' => $credential]
            : ['Authorization' => 'Bearer ' . $credential];
    }
}
