<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The Synaplan keys at rest: each person's key and the install-wide admin and
 * shared keys, stored as sensitive config values, which Nextcloud encrypts.
 *
 * A key written by an older release is still plain text; reading it marks it
 * sensitive, which encrypts it in place. A key that no longer decrypts (the
 * instance secret changed) is removed and read as no key.
 *
 * Every write of these keys goes through here: once a key is stored as a
 * sensitive string, Nextcloud refuses an untyped write (`occ user:setting`,
 * IConfig::setUserValue) of the same key.
 */
final class KeyStorage
{
    public const USER_KEY = 'synaplan_user_api_key';

    /** What Nextcloud 33–35 return for a sensitive user value they cannot decrypt. */
    private const USER_ENCRYPTION_PREFIX = '$UserConfigEncryption$';

    /** What Nextcloud's ICrypto throws when a value no longer decrypts. */
    private const DECRYPTION_FAILURE = '/HMAC does not match|ciphertext could not be decoded|Decryption failed/';

    public function __construct(
        private IUserConfig $userConfig,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return string|null the key, '' when there is none, null when it could not be decrypted and was removed
     */
    public function getUserKey(string $uid): ?string
    {
        $key = $this->userConfig->getValueString($uid, Application::APP_ID, self::USER_KEY);
        if ($key === '') {
            return '';
        }
        if (str_starts_with($key, self::USER_ENCRYPTION_PREFIX)) {
            $this->userConfig->deleteUserConfig($uid, Application::APP_ID, self::USER_KEY);
            $this->logger->warning('The stored Synaplan key of {uid} could not be decrypted and was removed', [
                'app' => Application::APP_ID,
                'uid' => $uid,
            ]);

            return null;
        }
        if (!$this->userConfig->isSensitive($uid, Application::APP_ID, self::USER_KEY)) {
            $this->userConfig->updateSensitive($uid, Application::APP_ID, self::USER_KEY, true);
        }

        return $key;
    }

    public function setUserKey(string $uid, string $key): void
    {
        $this->userConfig->setValueString($uid, Application::APP_ID, self::USER_KEY, $key, flags: IUserConfig::FLAG_SENSITIVE);
    }

    /**
     * @param 'api_key'|'api_key_local' $name
     * @throws \Exception when the config cannot be read for another reason than decryption
     */
    public function getAppKey(string $name): string
    {
        try {
            $key = $this->appConfig->getValueString(Application::APP_ID, $name);
        } catch (\Exception $e) {
            if (preg_match(self::DECRYPTION_FAILURE, $e->getMessage()) !== 1) {
                throw $e;
            }
            $this->appConfig->deleteKey(Application::APP_ID, $name);
            $this->logger->warning('The stored Synaplan key {name} could not be decrypted and was removed', [
                'app' => Application::APP_ID,
                'name' => $name,
            ]);

            return '';
        }
        if ($key !== '' && !$this->appConfig->isSensitive(Application::APP_ID, $name)) {
            $this->appConfig->updateSensitive(Application::APP_ID, $name, true);
        }

        return $key;
    }

    /**
     * @param 'api_key'|'api_key_local' $name
     */
    public function setAppKey(string $name, string $key): void
    {
        $this->appConfig->setValueString(Application::APP_ID, $name, $key, sensitive: true);
    }
}
