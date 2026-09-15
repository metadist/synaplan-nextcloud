<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Settings;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

/**
 * Personal settings: connect or disconnect a Synaplan account.
 */
class SynaplanPersonal implements ISettings
{
    public function getForm(): TemplateResponse
    {
        \OCP\Util::addScript(Application::APP_ID, 'synaplan_integration-personal-settings');

        return new TemplateResponse(Application::APP_ID, 'settings/personal');
    }

    public function getSection(): string
    {
        return Application::APP_ID;
    }

    public function getPriority(): int
    {
        return 10;
    }
}
