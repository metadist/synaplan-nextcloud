<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Listener;

use OCA\SynaplanIntegration\Listener\LoadScriptsListener;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\L10N\IFactory;
use OCP\Util;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class LoadScriptsListenerTest extends TestCase
{
    protected function setUp(): void
    {
        // Util::addScript() loads the app's translations through \OC::$server.
        $l10nFactory = $this->createStub(IFactory::class);
        $l10nFactory->method('findLanguage')->willReturn('en');
        $server = $this->createStub(ContainerInterface::class);
        $server->method('get')->willReturn($l10nFactory);
        \OC::$server = $server;

        foreach (['scripts', 'scriptDeps'] as $property) {
            (new \ReflectionProperty(Util::class, $property))->setValue(null, []);
        }
    }

    protected function tearDown(): void
    {
        \OC::$server = null;
    }

    public function testASignedInPageGetsTheFilesActionsAndTheLauncher(): void
    {
        (new LoadScriptsListener())->handle(new BeforeTemplateRenderedEvent(true, new TemplateResponse('files', 'index')));

        self::assertSame([
            'synaplan_integration/js/synaplan_integration-files-init',
            'synaplan_integration/js/synaplan_integration-chat-launcher',
        ], $this->scripts());
    }

    public function testTheLoginPageGetsNeither(): void
    {
        (new LoadScriptsListener())->handle(new BeforeTemplateRenderedEvent(false, new TemplateResponse('core', 'login')));

        self::assertSame([], $this->scripts());
    }

    /**
     * The app's scripts, without the translations Util adds beside them.
     *
     * @return list<string>
     */
    private function scripts(): array
    {
        $scripts = (new \ReflectionProperty(Util::class, 'scripts'))->getValue();

        return array_values(array_filter(
            $scripts['synaplan_integration'] ?? [],
            static fn (string $path): bool => str_contains($path, '/js/'),
        ));
    }
}
