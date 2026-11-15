<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\IConfig;
use OCP\Security\ICrypto;

/**
 * The key this Nextcloud signs OAuth client assertions with (private_key_jwt).
 * Synaplan stores the public half only. The private half stays in app config,
 * encrypted, and is created the first time this cloud registers.
 */
final class CloudClientKey
{
    public const KID = 'nextcloud';

    public const ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    private ?string $pem = null;

    public function __construct(
        private IConfig $config,
        private ICrypto $crypto,
        private SynaplanConfig $synaplanConfig,
    ) {
    }

    public function issuer(): string
    {
        return $this->synaplanConfig->getPublicBaseUrl();
    }

    /**
     * A client assertion for one Synaplan endpoint. The audience is that
     * endpoint's public URL: Synaplan refuses an assertion made for another.
     */
    public function assertion(string $path): string
    {
        $clientId = $this->synaplanConfig->getLinkInstanceId();
        $now = time();
        $header = self::base64Url((string) json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => self::KID,
        ], JSON_THROW_ON_ERROR));
        $payload = self::base64Url((string) json_encode([
            'iss' => $clientId,
            'sub' => $clientId,
            'aud' => $this->issuer() . $path,
            'jti' => bin2hex(random_bytes(16)),
            'iat' => $now,
            'exp' => $now + 60,
        ], JSON_THROW_ON_ERROR));
        $signing = $header . '.' . $payload;
        $signature = '';
        if (!openssl_sign($signing, $signature, $this->privateKey(), OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Could not sign the client assertion.');
        }

        return $signing . '.' . self::base64Url($signature);
    }

    /**
     * @return array{kty: string, kid: string, use: string, alg: string, n: string, e: string}
     */
    public function publicJwk(): array
    {
        $details = openssl_pkey_get_details($this->privateKey());
        if (!is_array($details)) {
            throw new \RuntimeException('Could not read the cloud client key.');
        }
        $rsa = is_array($details['rsa'] ?? null) ? $details['rsa'] : [];

        return [
            'kty' => 'RSA',
            'kid' => self::KID,
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => self::base64Url((string) ($rsa['n'] ?? '')),
            'e' => self::base64Url((string) ($rsa['e'] ?? '')),
        ];
    }

    private function privateKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_private($this->pem());
        if ($key === false) {
            throw new \RuntimeException('Could not read the cloud client key.');
        }

        return $key;
    }

    private function pem(): string
    {
        if ($this->pem !== null) {
            return $this->pem;
        }
        $stored = $this->decrypt($this->synaplanConfig->getLinkInstanceSecretCipher());
        if ($this->isPem($stored)) {
            return $this->pem = $stored;
        }
        $generated = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($generated === false) {
            throw new \RuntimeException('Could not create the cloud client key.');
        }
        $pem = '';
        if (!openssl_pkey_export($generated, $pem) || !$this->isPem($pem)) {
            throw new \RuntimeException('Could not export the cloud client key.');
        }
        $this->config->setAppValue(Application::APP_ID, 'link_instance_secret', $this->crypto->encrypt($pem));

        return $this->pem = $pem;
    }

    private function decrypt(string $cipher): string
    {
        if ($cipher === '') {
            return '';
        }
        try {
            return $this->crypto->decrypt($cipher);
        } catch (\Throwable) {
            return $cipher;
        }
    }

    private function isPem(string $value): bool
    {
        return str_contains($value, 'BEGIN PRIVATE KEY') || str_contains($value, 'BEGIN RSA PRIVATE KEY');
    }

    private static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
