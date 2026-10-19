<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Who may call each route. Nextcloud requires an admin and a CSRF token unless
 * the method carries an attribute that lifts it; a lost attribute locks users
 * out, an added one opens a route. Every route needs an entry here.
 */
final class ControllerAccessTest extends TestCase
{
    private const ATTRIBUTE_NAMESPACE = 'OCP\\AppFramework\\Http\\Attribute\\';

    /** @var array<string, list<string>> */
    private const ACCESS = [
        'adminAiUsers#deactivate' => [],
        'adminAiUsers#list' => [],
        'adminAiUsers#usage' => [],
        'api#clientConfig' => ['NoAdminRequired'],
        'api#getGroups' => ['NoAdminRequired'],
        'api#getModels' => ['NoAdminRequired'],
        'api#knowledgeStatus' => ['NoAdminRequired'],
        'api#removeFromKnowledge' => ['NoAdminRequired'],
        'api#summarize' => ['NoAdminRequired'],
        'api#translate' => ['NoAdminRequired'],
        'api#uploadToKnowledge' => ['NoAdminRequired'],
        'chat#chat' => ['NoAdminRequired'],
        'chat#chatStream' => ['NoAdminRequired'],
        'consent#getConsent' => ['NoAdminRequired'],
        'consent#setConsent' => ['NoAdminRequired'],
        'link#callback' => ['NoAdminRequired', 'NoCSRFRequired'],
        'link#disconnect' => ['NoAdminRequired'],
        'link#start' => ['NoAdminRequired', 'NoCSRFRequired'],
        'link#status' => ['NoAdminRequired'],
        'media#generate' => ['NoAdminRequired'],
        'media#proxy' => ['NoAdminRequired', 'NoCSRFRequired'],
        'media#save' => ['NoAdminRequired'],
        'page#research' => ['NoAdminRequired', 'NoCSRFRequired'],
        'settings#forgetInstance' => [],
        'settings#getSettings' => [],
        'settings#instanceStatus' => [],
        'settings#registerInstance' => [],
        'settings#saveSettings' => [],
        'settings#testConnection' => [],
    ];

    public function testEveryRouteHasAnAccessEntry(): void
    {
        $routes = array_keys(self::routes());
        sort($routes);
        $expected = array_keys(self::ACCESS);
        sort($expected);

        self::assertSame($expected, $routes);
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('access')]
    public function testRouteCarriesExactlyItsAccessAttributes(string $route, array $expected): void
    {
        [$controller, $method] = explode('#', $route);
        $class = 'OCA\\SynaplanIntegration\\Controller\\' . ucfirst($controller) . 'Controller';
        $reflection = new \ReflectionMethod($class, $method);

        $actual = [];
        foreach ($reflection->getAttributes() as $attribute) {
            if (str_starts_with($attribute->getName(), self::ATTRIBUTE_NAMESPACE)) {
                $actual[] = substr($attribute->getName(), strlen(self::ATTRIBUTE_NAMESPACE));
            }
        }
        sort($actual);

        self::assertSame($expected, $actual);
        self::assertStringNotContainsString('@NoAdminRequired', (string) $reflection->getDocComment());
        self::assertStringNotContainsString('@NoCSRFRequired', (string) $reflection->getDocComment());
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function access(): iterable
    {
        foreach (self::ACCESS as $route => $attributes) {
            yield $route => [$route, $attributes];
        }
    }

    /**
     * @return array<string, true>
     */
    private static function routes(): array
    {
        /** @var array{routes: list<array{name: string}>} $definition */
        $definition = require __DIR__ . '/../../../appinfo/routes.php';

        $routes = [];
        foreach ($definition['routes'] as $route) {
            $routes[$route['name']] = true;
        }

        return $routes;
    }
}
