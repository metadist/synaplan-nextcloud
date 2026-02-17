<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Settings;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Admin settings section for Synaplan Integration.
 *
 * Creates a "Synaplan" entry in the admin settings sidebar.
 */
class SynaplanSection implements IIconSection
{
	public function __construct(
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string
	{
		return Application::APP_ID;
	}

	public function getName(): string
	{
		return 'Synaplan';
	}

	public function getPriority(): int
	{
		return 90;
	}

	public function getIcon(): string
	{
		return $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg');
	}
}
