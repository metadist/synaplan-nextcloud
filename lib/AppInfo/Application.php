<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\AppInfo;

use OCA\SynaplanIntegration\Listener\UserDeletedListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\User\Events\BeforeUserDeletedEvent;
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
        // When a Nextcloud user is deleted, cascade-delete their Synaplan
        // account (per-user mode) so no orphaned external data remains.
        $context->registerEventListener(BeforeUserDeletedEvent::class, UserDeletedListener::class);
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
