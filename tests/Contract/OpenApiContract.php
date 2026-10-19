<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Contract;

/**
 * Checks a recorded call against the pinned Synaplan's OpenAPI spec and, for
 * the OpenAI-compatible gateway (/v1/*, kept out of the published spec on
 * purpose), against its route table.
 */
final class OpenApiContract
{
    /** @var array<string, mixed> */
    private array $spec;

    /** @var list<array{path: string, method: string}> */
    private array $routes = [];

    public function __construct(string $specFile, string $routesFile)
    {
        $this->spec = self::readJson($specFile);
        foreach (self::readJson($routesFile) as $route) {
            if (is_array($route) && isset($route['path'], $route['method'])) {
                $this->routes[] = ['path' => (string) $route['path'], 'method' => (string) $route['method']];
            }
        }
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string> what the pinned Synaplan does not accept
     */
    public function violations(string $method, string $path, array $body, bool $multipart): array
    {
        $template = $this->specPath($method, $path);
        if ($template === null) {
            return $this->routeExists($method, $path) ? [] : ["$method $path: no such route"];
        }

        $operation = $this->spec['paths'][$template][strtolower($method)] ?? null;
        if (!is_array($operation)) {
            return ["$method $path: $template has no " . strtoupper($method)];
        }

        $contentType = $multipart ? 'multipart/form-data' : 'application/json';
        $schema = $this->resolve($operation['requestBody']['content'][$contentType]['schema'] ?? []);
        if ($body !== [] && $schema === []) {
            return ["$method $template: sends a $contentType body the spec does not describe"];
        }

        $violations = [];
        foreach ($schema['required'] ?? [] as $field) {
            if (!array_key_exists($field, $body)) {
                $violations[] = "$method $template: required field '$field' is not sent";
            }
        }
        $properties = $schema['properties'] ?? [];
        foreach ($body as $field => $value) {
            if ($properties !== [] && !isset($properties[$field])) {
                $violations[] = "$method $template: sends '$field', which the spec does not declare";
                continue;
            }
            $enum = $this->resolve($properties[$field] ?? [])['enum'] ?? null;
            if (is_array($enum) && is_string($value) && !in_array($value, $enum, true)) {
                $violations[] = "$method $template: '$field' = '$value' is not one of " . implode(', ', $enum);
            }
        }

        return $violations;
    }

    /**
     * The most specific template for the path (most literal characters), one
     * that has the method when there is one.
     */
    private function specPath(string $method, string $path): ?string
    {
        $paths = array_keys($this->spec['paths'] ?? []);
        foreach ([false, true] as $catchAll) {
            $matches = array_values(array_filter(
                $paths,
                static fn (string $template): bool => preg_match(self::pattern($template, $catchAll), $path) === 1,
            ));
            if ($matches === []) {
                continue;
            }
            usort($matches, static fn (string $a, string $b): int => self::literalLength($b) <=> self::literalLength($a));
            foreach ($matches as $template) {
                if (isset($this->spec['paths'][$template][strtolower($method)])) {
                    return $template;
                }
            }

            return $matches[0];
        }

        return null;
    }

    private static function literalLength(string $template): int
    {
        return strlen((string) preg_replace('/\{[^}]+\}/', '', $template));
    }

    private function routeExists(string $method, string $path): bool
    {
        foreach ($this->routes as $route) {
            $methods = $route['method'] === 'ANY' ? [$method] : explode('|', $route['method']);
            if (in_array($method, $methods, true) && preg_match(self::pattern($route['path'], false), $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * A placeholder matches one segment; with $catchAll the last one may span
     * several, like Symfony routes whose last parameter allows slashes.
     */
    private static function pattern(string $template, bool $catchAll): string
    {
        $parts = preg_split('/(\{[^}]+\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $finalParameter = count($parts) - 2;
        $endsWithParameter = end($parts) === '';
        $regex = '';
        foreach ($parts as $i => $part) {
            if (!str_starts_with($part, '{')) {
                $regex .= preg_quote($part, '#');
                continue;
            }
            $regex .= $catchAll && $endsWithParameter && $i === $finalParameter ? '.+' : '[^/]+';
        }

        return '#^' . $regex . '$#';
    }

    /**
     * @param mixed $schema
     *
     * @return array<string, mixed>
     */
    private function resolve($schema): array
    {
        while (is_array($schema) && isset($schema['$ref'])) {
            $name = substr((string) $schema['$ref'], strlen('#/components/schemas/'));
            $schema = $this->spec['components']['schemas'][$name] ?? [];
        }

        return is_array($schema) ? $schema : [];
    }

    /**
     * @return array<mixed>
     */
    private static function readJson(string $file): array
    {
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($data)) {
            throw new \RuntimeException("$file is missing or not JSON. Run scripts/synaplan-openapi.sh first.");
        }

        return $data;
    }
}
