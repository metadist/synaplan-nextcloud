<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\ShareRecipients;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCP\Files\Node;
use OCP\Share\IManager;
use PHPUnit\Framework\TestCase;

final class ShareRecipientsTest extends TestCase
{
    public function testThePeopleWithAccessAndACredentialWhoWereNotToldYet(): void
    {
        $shares = $this->createStub(IManager::class);
        $shares->method('getAccessList')->willReturn(['users' => ['ada', 'bob', 'carol', 'dan', 'bob'], 'public' => false]);
        $accounts = $this->createStub(UserAccountService::class);
        $accounts->method('hasStoredCredential')->willReturnCallback(static fn (string $uid): bool => $uid !== 'dan');

        self::assertSame(['bob', 'carol'], (new ShareRecipients($shares, $accounts))->of($this->createStub(Node::class), ['ada']));
    }

    public function testAnAccessListThatFailsTellsNobodyElse(): void
    {
        $shares = $this->createStub(IManager::class);
        $shares->method('getAccessList')->willThrowException(new \RuntimeException('no share provider'));

        self::assertSame([], (new ShareRecipients($shares, $this->createStub(UserAccountService::class)))->of($this->createStub(Node::class), []));
    }
}
