<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Notification;

use OCA\SynaplanIntegration\Notification\CopyNotifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

final class CopyNotifierTest extends TestCase
{
    public function testTheLinkIsMadeInThePersonsOwnRequestFromTheFile(): void
    {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);
        $l10n = $this->createMock(IFactory::class);
        $l10n->method('get')->willReturn($l);
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('linkToRouteAbsolute')
            ->with('files.View.showFile', ['fileid' => '42'])
            ->willReturn('https://files.example.org/index.php/f/42');

        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('synaplan_integration');
        $notification->method('getSubject')->willReturn('copied');
        $notification->method('getObjectType')->willReturn('file');
        $notification->method('getObjectId')->willReturn('42');
        $notification->method('getSubjectParameters')->willReturn([
            'name' => 'Q3 plan.docx',
            'folder' => 'Synaplan/Documents',
            'link' => 'http://nextcloud/index.php/f/42',
        ]);
        $notification->method('setParsedSubject')->willReturnSelf();
        $notification->method('setIcon')->willReturnSelf();
        $notification->expects(self::once())->method('setLink')
            ->with('https://files.example.org/index.php/f/42')
            ->willReturnSelf();

        (new CopyNotifier($l10n, $urls))->prepare($notification, 'en');
    }
}
