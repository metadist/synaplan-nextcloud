<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Service\ResearchHistory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class ResearchHistoryTest extends TestCase
{
    /** @var array<string, string> */
    private array $stored = [];

    public function testASavedThreadIsListedAndReopenedWithTheWording(): void
    {
        $history = $this->history();
        $saved = $history->save('ada', 'conv0001', [
            ['role' => 'user', 'text' => 'Where is the invoice?'],
            ['role' => 'assistant', 'text' => 'It is in the Invoices folder.', 'model' => 'demo'],
            ['role' => 'system', 'text' => 'ignore me'],
        ]);

        self::assertSame('Where is the invoice?', $saved['title']);
        self::assertSame('Where is the invoice?', $history->list('ada')[0]['title']);

        $opened = $history->open('ada', 'conv0001');
        self::assertNotNull($opened);
        self::assertSame([
            ['role' => 'user', 'text' => 'Where is the invoice?'],
            ['role' => 'assistant', 'text' => 'It is in the Invoices folder.', 'model' => 'demo'],
        ], $opened['messages']);
    }

    public function testTwoThreadsStayDistinctAndTheNewerOneIsFirst(): void
    {
        $history = $this->history();
        $history->save('ada', 'conv0001', [['role' => 'user', 'text' => 'First question']]);
        $history->save('ada', 'conv0002', [['role' => 'user', 'text' => 'Second question']]);

        $list = $history->list('ada');
        self::assertSame(['conv0002', 'conv0001'], array_column($list, 'id'));
        self::assertSame('First question', $history->open('ada', 'conv0001')['messages'][0]['text']);
    }

    public function testRemovingOneLeavesTheOther(): void
    {
        $history = $this->history();
        $history->save('ada', 'conv0001', [['role' => 'user', 'text' => 'Keep me']]);
        $history->save('ada', 'conv0002', [['role' => 'user', 'text' => 'Drop me']]);

        $history->remove('ada', 'conv0002');

        self::assertNull($history->open('ada', 'conv0002'));
        self::assertSame('Keep me', $history->open('ada', 'conv0001')['messages'][0]['text']);
        self::assertSame([], $history->list('bob'));
    }

    public function testAPersonDoesNotSeeAnotherPersonsThread(): void
    {
        $history = $this->history();
        $history->save('ada', 'conv0001', [['role' => 'user', 'text' => 'Ada only']]);

        self::assertSame([], $history->list('bob'));
        self::assertNull($history->open('bob', 'conv0001'));
    }

    private function history(): ResearchHistory
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturnCallback(
            fn (string $uid, string $app, string $key): string => $this->stored[$uid . $key] ?? '',
        );
        $config->method('setUserValue')->willReturnCallback(
            function (string $uid, string $app, string $key, string $value): void {
                self::assertSame(Application::APP_ID, $app);
                $this->stored[$uid . $key] = $value;
            },
        );

        return new ResearchHistory($config);
    }
}
