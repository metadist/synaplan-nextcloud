<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\Util;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'synaplan_integration';

    public function __construct(array $urlParams = [])
    {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void
    {
        // Services and listeners will be registered here as features are added
    }

    public function boot(IBootContext $context): void
    {
        // Load file actions script in the Files app
        Util::addScript(self::APP_ID, 'synaplan_integration-files-init');

        // Floating in-page chat launcher (bottom-right button -> chat window)
        Util::addScript(self::APP_ID, 'synaplan_integration-chat-launcher');

        // Register top-level navigation entry
        $context->getAppContainer()->get(INavigationManager::class)->add(function () use ($context) {
            $urlGenerator = $context->getAppContainer()->get(IURLGenerator::class);

            return [
                'id' => self::APP_ID,
                'order' => 80,
                'href' => $urlGenerator->linkToRoute(self::APP_ID . '.page.research'),
                'icon' => $urlGenerator->imagePath(self::APP_ID, 'app.svg'),
                'name' => 'Synaplan',
            ];
        });
    }
}
