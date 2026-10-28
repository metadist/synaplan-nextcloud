<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\BackgroundJob;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Service\KeyStorage;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Once this Nextcloud is registered and Synaplan can adopt identities, rewrite
 * each provisioned person's legacy row. One pass: a later run does nothing.
 * A person without a stored key is skipped. A refusal (403) stops the pass
 * and is logged once, without the key.
 */
class AdoptIdentitiesJob extends TimedJob
{
    public const DONE = 'identities_adopted';

    private const PAGE = 200;

    public function __construct(
        ITimeFactory $time,
        private PlatformLinkService $links,
        private SynaplanConfig $synaplanConfig,
        private KeyStorage $keys,
        private IUserManager $users,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(3600);
    }

    protected function run(mixed $argument): void
    {
        if ($this->config->getAppValue(Application::APP_ID, self::DONE, '0') === '1') {
            return;
        }
        if ($this->synaplanConfig->getLinkInstanceId() === ''
            || $this->synaplanConfig->getLinkInstanceSecretCipher() === '') {
            return;
        }
        if (!in_array('identity_adopt', $this->links->capabilities(), true)) {
            return;
        }
        try {
            $url = $this->links->defaultDeliveryUrl();
            if ($url !== '') {
                $this->links->announceDelivery($url);
            }
        } catch (\Throwable) {
            return;
        }

        $offset = 0;
        $clean = true;
        do {
            $batch = $this->users->search('', self::PAGE, $offset);
            foreach ($batch as $user) {
                $uid = $user->getUID();
                $key = $this->keys->getUserKey($uid);
                if ($key === null || $key === '') {
                    continue;
                }
                $status = $this->links->adoptIdentity($uid, $key);
                if ($status === 403) {
                    $this->logger->error('Stopped adopting Synaplan accounts: Synaplan refused {uid}.', [
                        'app' => Application::APP_ID,
                        'uid' => $uid,
                    ]);

                    return;
                }
                if ($status !== 200) {
                    $clean = false;
                    continue;
                }
                if (!$user->isEnabled()) {
                    $this->links->reportLifecycle($uid, 'disabled');
                }
            }
            $count = count($batch);
            $offset += $count;
        } while ($count === self::PAGE);

        if ($clean) {
            $this->config->setAppValue(Application::APP_ID, self::DONE, '1');
        }
    }
}
