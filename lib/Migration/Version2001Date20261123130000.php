<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Migration;

use Closure;
use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Service\ReadScope;
use OCP\Config\IUserConfig;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Earlier versions told Synaplan the named folders under every choice, and
 * Synaplan opens a read of any folder in its copy. Whoever kept named folders
 * under "Only what I watch" or "All my files" is reported again by the hourly
 * job, which now leaves the folders out. The folders stay here.
 */
class Version2001Date20261123130000 extends SimpleMigrationStep
{
    public function __construct(
        private IConfig $config,
        private IUserConfig $userConfig,
    ) {
    }

    /**
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array<string, mixed> $options
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $marked = 0;
        foreach ($this->userConfig->getValuesByUsers(Application::APP_ID, ReadScope::PREF) as $uid => $raw) {
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($decoded)
                || ($decoded['mode'] ?? ReadScope::MODE_WATCHED) === ReadScope::MODE_ALLOWED
                || !is_array($decoded['folders'] ?? null)
                || $decoded['folders'] === []) {
                continue;
            }
            $this->config->setUserValue((string) $uid, Application::APP_ID, ReadScope::PENDING, '1');
            $marked++;
        }
        if ($marked > 0) {
            $output->info(sprintf('Synaplan learns within the hour that %d people no longer let it read their named folders.', $marked));
        }
    }
}
