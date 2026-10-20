<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Listener;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Adds the Files actions and the chat launcher to pages of a signed-in person.
 * The login page and public shares get neither: nobody there can use them.
 *
 * @template-implements IEventListener<BeforeTemplateRenderedEvent>
 */
final class LoadScriptsListener implements IEventListener
{
    public function handle(Event $event): void
    {
        if (!$event->isLoggedIn()) {
            return;
        }

        Util::addScript(Application::APP_ID, Application::APP_ID . '-files-init');
        Util::addScript(Application::APP_ID, Application::APP_ID . '-chat-launcher');
    }
}
