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
 * The administrator's read ceiling arrives with its default, "folders people
 * allow". 1.8.0 to 2.0.0 let everyone choose "All my files"; where someone
 * did, the ceiling starts at "all", so the update narrows nobody.
 */
class Version2001Date20261123110000 extends SimpleMigrationStep
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
        if ($this->config->getAppValue(Application::APP_ID, ReadScope::CEILING, '') !== '') {
            return;
        }
        foreach ($this->userConfig->getValuesByUsers(Application::APP_ID, ReadScope::PREF) as $raw) {
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded) && ($decoded['mode'] ?? '') === ReadScope::MODE_ALL) {
                $this->config->setAppValue(Application::APP_ID, ReadScope::CEILING, ReadScope::MODE_ALL);
                $output->info('People here let Synaplan read all their files: the read ceiling starts at "all".');

                return;
            }
        }
    }
}
