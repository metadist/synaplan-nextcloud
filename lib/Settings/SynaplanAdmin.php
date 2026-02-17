<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Settings;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

/**
 * Admin settings page for Synaplan Integration.
 *
 * Renders in: Admin → Settings → Connected accounts.
 */
class SynaplanAdmin implements ISettings
{
	public function __construct(
		private IConfig $config,
	) {
	}

	public function getForm(): TemplateResponse
	{
		\OCP\Util::addScript(Application::APP_ID, 'synaplan_integration-settings');

		return new TemplateResponse(Application::APP_ID, 'settings/admin');
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
