<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\AppInfo;

use OCA\SynaplanIntegration\BackgroundJob\RefreshNotifyKeysJob;
use OCA\SynaplanIntegration\BackgroundJob\WatchFeedJob;
use OCA\SynaplanIntegration\BackgroundJob\WatchReconcileJob;
use OCA\SynaplanIntegration\Listener\LoadScriptsListener;
use OCA\SynaplanIntegration\Listener\ReadScopeListener;
use OCA\SynaplanIntegration\Listener\SourceEventListener;
use OCA\SynaplanIntegration\Listener\UserChangedListener;
use OCA\SynaplanIntegration\Listener\UserDeletedListener;
use OCA\SynaplanIntegration\Listener\WatchEventListener;
use OCA\SynaplanIntegration\Notification\CopyNotifier;
use OCA\SynaplanIntegration\Service\SeenNotificationStore;
use OCA\SynaplanIntegration\Service\SeenNotifications;
use OCP\BackgroundJob\IJobList;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\Share\Events\ShareDeletedFromSelfEvent;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\User\Events\UserChangedEvent;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'synaplan_integration';

    /**
     * @param array<string, mixed> $urlParams
     */
    public function __construct(array $urlParams = [])
    {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void
    {
        // A removed, disabled or re-enabled Nextcloud user is reported to
        // Synaplan as that one identity. The account stays when something
        // else still uses it.
        $context->registerEventListener(BeforeUserDeletedEvent::class, UserDeletedListener::class);
        $context->registerEventListener(UserChangedEvent::class, UserChangedListener::class);
        $context->registerEventListener(NodeWrittenEvent::class, SourceEventListener::class);
        $context->registerEventListener(NodeRenamedEvent::class, SourceEventListener::class);
        $context->registerEventListener(NodeRenamedEvent::class, ReadScopeListener::class);
        $context->registerEventListener(BeforeNodeDeletedEvent::class, SourceEventListener::class);
        $context->registerEventListener(NodeDeletedEvent::class, SourceEventListener::class);
        $context->registerEventListener('OCA\\Files_Trashbin\\Events\\NodeRestoredEvent', SourceEventListener::class);
        $context->registerEventListener(ShareDeletedFromSelfEvent::class, SourceEventListener::class);
        // Files arriving in a watched folder, and a watched target deleted or restored.
        $context->registerEventListener(NodeWrittenEvent::class, WatchEventListener::class);
        $context->registerEventListener(NodeRenamedEvent::class, WatchEventListener::class);
        $context->registerEventListener(NodeCopiedEvent::class, WatchEventListener::class);
        $context->registerEventListener(NodeDeletedEvent::class, WatchEventListener::class);
        $context->registerEventListener('OCA\\Files_Trashbin\\Events\\NodeRestoredEvent', WatchEventListener::class);
        $context->registerEventListener(BeforeTemplateRenderedEvent::class, LoadScriptsListener::class);
        $context->registerNotifierService(CopyNotifier::class);
        $context->registerServiceAlias(SeenNotificationStore::class, SeenNotifications::class);
    }

    public function boot(IBootContext $context): void
    {
        $context->injectFn(function (IJobList $jobs): void {
            foreach ([RefreshNotifyKeysJob::class, WatchFeedJob::class, WatchReconcileJob::class] as $job) {
                if (!$jobs->has($job, null)) {
                    $jobs->add($job);
                }
            }
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
