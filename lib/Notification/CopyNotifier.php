<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Notification;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * "Synaplan copied the file to your folder", with a link to the file.
 */
class CopyNotifier implements INotifier
{
    public function __construct(
        private IFactory $l10nFactory,
        private IURLGenerator $urls,
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

    public function prepare(INotification $notification, string $languageCode): INotification
    {
        if ($notification->getApp() !== Application::APP_ID || $notification->getSubject() !== 'copied') {
            throw new UnknownNotificationException();
        }
        $parameters = $notification->getSubjectParameters();
        $name = is_string($parameters['name'] ?? null) ? $parameters['name'] : '';
        $folder = is_string($parameters['folder'] ?? null) ? $parameters['folder'] : '';
        $l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
        if ($folder === '') {
            $folder = (string) $l->t('Files');
        }
        $notification->setParsedSubject($this->sentence($l, $name, $folder));
        $link = is_string($parameters['link'] ?? null) ? $parameters['link'] : '';
        if ($link !== '') {
            $notification->setLink($link);
        }
        $notification->setIcon($this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app.svg')));

        return $notification;
    }

    private function sentence(IL10N $l, string $name, string $folder): string
    {
        // Nextcloud applies translation parameters with vsprintf, which does not
        // fill {name}. The locales keep those placeholders, so replace them here.
        $translated = (string) $l->t('Synaplan copied {name} to {folder}.');

        return strtr($translated, ['{name}' => $name, '{folder}' => $folder]);
    }
}
