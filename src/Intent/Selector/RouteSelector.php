<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Selector;

final readonly class RouteSelector
{
    /**
     * @param string|null $name       fnmatch pattern for route name
     * @param string|null $prefix     URI prefix (str_starts_with)
     * @param string|null $uri        exact URI match
     * @param string[]|null $methods  HTTP methods (uppercase)
     * @param RouteSelector[]|null $any  OR-logic: match if any child matches
     */
    public function __construct(
        public ?string $name = null,
        public ?string $prefix = null,
        public ?string $uri = null,
        public ?array $methods = null,
        public ?array $any = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $routes = $data['routes'] ?? $data;

        $any = null;
        if (isset($routes['any']) && is_array($routes['any'])) {
            $any = array_map(
                static fn(array $item): self => self::fromArray(['routes' => $item]),
                $routes['any'],
            );
        }

        $methods = null;
        if (isset($routes['methods']) && is_array($routes['methods'])) {
            $methods = array_map(
                static fn(string $m): string => strtoupper(trim($m)),
                $routes['methods'],
            );
        }

        return new self(
            name: isset($routes['name']) ? trim((string) $routes['name']) : null,
            prefix: isset($routes['prefix']) ? trim((string) $routes['prefix']) : null,
            uri: isset($routes['uri']) ? trim((string) $routes['uri']) : null,
            methods: $methods,
            any: $any,
        );
    }

    /**
     * Test whether a route matches this selector.
     * Criteria are AND-combined: all specified criteria must match.
     * The `any` field uses OR-logic: at least one child must match.
     */
    public function matches(string $routeName, string $routeUri, string $method): bool
    {
        if ($this->any !== null) {
            foreach ($this->any as $child) {
                if ($child->matches($routeName, $routeUri, $method)) {
                    return true;
                }
            }

            return false;
        }

        if ($this->isEmpty()) {
            return false;
        }

        if ($this->name !== null && ! fnmatch($this->name, $routeName)) {
            return false;
        }

        if ($this->prefix !== null && ! str_starts_with($routeUri, $this->prefix)) {
            return false;
        }

        if ($this->uri !== null && $routeUri !== $this->uri) {
            return false;
        }

        if ($this->methods !== null && ! in_array(strtoupper($method), $this->methods, true)) {
            return false;
        }

        return true;
    }

    public function isEmpty(): bool
    {
        return $this->name === null
            && $this->prefix === null
            && $this->uri === null
            && $this->methods === null
            && $this->any === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];

        if ($this->name !== null) {
            $result['name'] = $this->name;
        }

        if ($this->prefix !== null) {
            $result['prefix'] = $this->prefix;
        }

        if ($this->uri !== null) {
            $result['uri'] = $this->uri;
        }

        if ($this->methods !== null) {
            $result['methods'] = $this->methods;
        }

        if ($this->any !== null) {
            $result['any'] = array_map(
                static fn(self $s): array => $s->toArray(),
                $this->any,
            );
        }

        return $result;
    }
}
