<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Contract;

/**
 * Checks a recorded call against the pinned Synaplan's OpenAPI spec and, for
 * the OpenAI-compatible gateway (/v1/*, kept out of the published spec on
 * purpose), against its route table.
 *
 * A path parameter matches more than one segment only when its schema pattern
 * or Symfony requirement is a catch-all (".+" or ".*"), as on upload downloads.
 * Object and array bodies are checked at every level: required fields,
 * undeclared fields and enums.
 */
final class OpenApiContract
{
    /** @var array<string, mixed> */
    private array $spec;

    /**
     * @var list<array{path: string, method: string, pathRegex: string, requirements: array<string, string>}>
     */
    private array $routes = [];

    public function __construct(string $specFile, string $routesFile)
    {
        $this->spec = self::readJson($specFile);
        foreach (self::readJson($routesFile) as $route) {
            if (!is_array($route) || !isset($route['path'], $route['method'])) {
                continue;
            }
            $requirements = [];
            if (is_array($route['requirements'] ?? null)) {
                foreach ($route['requirements'] as $name => $requirement) {
                    if (is_string($requirement)) {
                        $requirements[(string) $name] = $requirement;
                    }
                }
            }
            $this->routes[] = [
                'path' => (string) $route['path'],
                'method' => (string) $route['method'],
                'pathRegex' => is_string($route['pathRegex'] ?? null) ? $route['pathRegex'] : '',
                'requirements' => $requirements,
            ];
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
        $this->checkObject($method, $template, '', $schema, $body, $violations);

        return $violations;
    }

    /**
     * True when the recorded call is that operation, with each parameter one
     * segment. A shared prefix is not a match.
     */
    public static function operationCalled(string $method, string $template, string $callMethod, string $callPath): bool
    {
        if ($method !== $callMethod) {
            return false;
        }
        $parts = preg_split('/(\{[^}]+\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex = '';
        foreach ($parts as $part) {
            $regex .= str_starts_with($part, '{') ? '[^/]+' : preg_quote($part, '#');
        }

        return preg_match('#^' . $regex . '$#', $callPath) === 1;
    }

    /**
     * @param array<string|int, mixed> $body
     * @param list<string> $violations
     */
    private function checkObject(string $method, string $template, string $prefix, array $schema, array $body, array &$violations): void
    {
        $schema = $this->resolve($schema);
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach ($schema['required'] ?? [] as $field) {
            if (is_string($field) && !array_key_exists($field, $body)) {
                $where = $prefix === '' ? $field : $prefix . '.' . $field;
                $violations[] = "$method $template: required field '$where' is not sent";
            }
        }
        foreach ($body as $field => $value) {
            $where = $prefix === '' ? (string) $field : $prefix . '.' . $field;
            if ($properties !== [] && !isset($properties[$field])) {
                $violations[] = "$method $template: sends '$where', which the spec does not declare";
                continue;
            }
            $this->checkValue($method, $template, $where, $properties[$field] ?? [], $value, $violations);
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string> $violations
     */
    private function checkValue(string $method, string $template, string $where, array $schema, mixed $value, array &$violations): void
    {
        $schema = $this->resolve($schema);
        if ($schema === []) {
            return;
        }
        $enum = $schema['enum'] ?? null;
        if (is_array($enum) && $value !== null && !is_array($value) && !in_array($value, $enum, true)) {
            $rendered = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $allowed = implode(', ', array_map(static fn (mixed $item): string => (string) $item, $enum));
            $violations[] = "$method $template: '$where' = '$rendered' is not one of $allowed";
        }

        if (is_array($value) && (($schema['type'] ?? null) === 'array' || isset($schema['items']))) {
            $itemSchema = $this->resolve($schema['items'] ?? []);
            foreach ($value as $index => $item) {
                $this->checkValue($method, $template, $where . '[' . $index . ']', $itemSchema, $item, $violations);
            }

            return;
        }

        if (
            is_array($value)
            && !array_is_list($value)
            && (($schema['type'] ?? null) === 'object' || isset($schema['properties']))
        ) {
            $this->checkObject($method, $template, $where, $schema, $value, $violations);
        }
    }

    /**
     * The most specific template for the path (most literal characters), one
     * that has the method when there is one.
     */
    private function specPath(string $method, string $path): ?string
    {
        $paths = array_keys($this->spec['paths'] ?? []);
        $matches = array_values(array_filter(
            $paths,
            fn (string $template): bool => preg_match($this->pattern($template), $path) === 1,
        ));
        if ($matches === []) {
            return null;
        }
        usort($matches, static fn (string $a, string $b): int => self::literalLength($b) <=> self::literalLength($a));
        foreach ($matches as $template) {
            if (isset($this->spec['paths'][$template][strtolower($method)])) {
                return $template;
            }
        }

        return $matches[0];
    }

    private static function literalLength(string $template): int
    {
        return strlen((string) preg_replace('/\{[^}]+\}/', '', $template));
    }

    private function routeExists(string $method, string $path): bool
    {
        foreach ($this->routes as $route) {
            $methods = $route['method'] === 'ANY' ? [$method] : explode('|', $route['method']);
            if (!in_array($method, $methods, true)) {
                continue;
            }
            if ($route['pathRegex'] !== '') {
                if (preg_match($route['pathRegex'], $path) === 1) {
                    return true;
                }
                continue;
            }
            if (preg_match($this->pattern($route['path']), $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * One segment per parameter, unless that parameter's pattern or route
     * requirement is a catch-all.
     */
    private function pattern(string $template): string
    {
        $parts = preg_split('/(\{[^}]+\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex = '';
        foreach ($parts as $part) {
            if (!str_starts_with($part, '{')) {
                $regex .= preg_quote($part, '#');
                continue;
            }
            $name = substr($part, 1, -1);
            $regex .= $this->placeholderSpans($template, $name) ? '.+' : '[^/]+';
        }

        return '#^' . $regex . '$#';
    }

    private function placeholderSpans(string $template, string $name): bool
    {
        if (self::patternSpansSegments($this->openApiPathPattern($template, $name))) {
            return true;
        }
        foreach ($this->routes as $route) {
            if ($route['path'] === $template && self::patternSpansSegments($route['requirements'][$name] ?? '')) {
                return true;
            }
        }

        return false;
    }

    private function openApiPathPattern(string $template, string $name): string
    {
        $item = $this->spec['paths'][$template] ?? null;
        if (!is_array($item)) {
            return '';
        }
        $parameters = is_array($item['parameters'] ?? null) ? $item['parameters'] : [];
        foreach (['get', 'post', 'put', 'patch', 'delete', 'head', 'options'] as $method) {
            if (is_array($item[$method]['parameters'] ?? null)) {
                array_push($parameters, ...$item[$method]['parameters']);
            }
        }
        foreach ($parameters as $parameter) {
            if (!is_array($parameter) || ($parameter['name'] ?? '') !== $name || ($parameter['in'] ?? '') !== 'path') {
                continue;
            }
            $schema = $this->resolve($parameter['schema'] ?? []);
            if (is_string($schema['pattern'] ?? null)) {
                return $schema['pattern'];
            }
        }

        return '';
    }

    private static function patternSpansSegments(string $pattern): bool
    {
        return $pattern === '.+' || $pattern === '.*';
    }

    /**
     * @param mixed $schema
     *
     * @return array<string, mixed>
     */
    private function resolve($schema): array
    {
        $guard = 0;
        while (is_array($schema) && isset($schema['$ref']) && $guard < 8) {
            $name = substr((string) $schema['$ref'], strlen('#/components/schemas/'));
            $schema = $this->spec['components']['schemas'][$name] ?? [];
            $guard++;
        }
        if (!is_array($schema)) {
            return [];
        }
        if (!isset($schema['allOf']) || !is_array($schema['allOf'])) {
            return $schema;
        }

        $merged = $schema;
        unset($merged['allOf']);
        foreach ($schema['allOf'] as $part) {
            $part = $this->resolve($part);
            if (is_array($part['properties'] ?? null)) {
                $merged['properties'] = array_merge($merged['properties'] ?? [], $part['properties']);
            }
            if (is_array($part['required'] ?? null)) {
                $merged['required'] = array_values(array_unique(array_merge($merged['required'] ?? [], $part['required'])));
            }
            foreach (['enum', 'type', 'items'] as $key) {
                if (!isset($merged[$key]) && isset($part[$key])) {
                    $merged[$key] = $part[$key];
                }
            }
        }

        return $merged;
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
