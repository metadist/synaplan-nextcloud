<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\AppInfo;

use OCA\SynaplanIntegration\BackgroundJob\RefreshNotifyKeysJob;
use OCA\SynaplanIntegration\Listener\LoadScriptsListener;
use OCA\SynaplanIntegration\Listener\UserDeletedListener;
use OCA\SynaplanIntegration\Notification\CopyNotifier;
use OCA\SynaplanIntegration\Service\NotifyKeyCache;
use OCP\BackgroundJob\IJobList;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\User\Events\BeforeUserDeletedEvent;

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
        $context->registerEventListener(BeforeTemplateRenderedEvent::class, LoadScriptsListener::class);
        $context->registerNotifierService(CopyNotifier::class);
    }

    public function boot(IBootContext $context): void
    {
        $context->injectFn(function (IJobList $jobs, NotifyKeyCache $keys): void {
            if (!$jobs->has(RefreshNotifyKeysJob::class, null)) {
                $jobs->add(RefreshNotifyKeysJob::class);
            }
            $keys->refreshIfEmpty();
        });

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
