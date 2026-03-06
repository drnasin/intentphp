<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Checks;

final readonly class AuthMiddlewareClassifier
{
    /** @var string[] */
    private array $exact;

    /** @var string[] */
    private array $prefixes;

    /** @var string[] */
    private array $suffixes;

    /**
     * @param string[] $exact     Exact middleware names/FQCNs (e.g. 'auth', 'Filament\Http\Middleware\Authenticate')
     * @param string[] $prefixes  Alias prefixes (e.g. 'auth' matches 'auth:sanctum'). Colon appended automatically.
     * @param string[] $suffixes  FQCN suffix patterns (e.g. '\Http\Middleware\Authenticate'). Opt-in, default empty.
     */
    public function __construct(
        array $exact = [],
        array $prefixes = [],
        array $suffixes = [],
    ) {
        $this->exact = $exact;
        $this->prefixes = $prefixes;
        $this->suffixes = $suffixes;
    }

    /**
     * Build from full guard config array. Checks route_authorization nested key first,
     * then falls back to legacy top-level auth_middlewares.
     *
     * @param array<string, mixed> $config Full guard config
     */
    public static function fromConfig(array $config): self
    {
        $ra = $config['route_authorization'] ?? [];

        // Support new structured keys under route_authorization
        if (isset($ra['auth_middleware_exact']) || isset($ra['auth_middleware_prefixes']) || isset($ra['auth_middleware_suffixes'])) {
            return new self(
                exact: (array) ($ra['auth_middleware_exact'] ?? self::defaultExact()),
                prefixes: (array) ($ra['auth_middleware_prefixes'] ?? self::defaultPrefixes()),
                suffixes: (array) ($ra['auth_middleware_suffixes'] ?? []),
            );
        }

        // Fallback: legacy flat 'auth_middlewares' top-level key (deprecated)
        if (isset($config['auth_middlewares']) && is_array($config['auth_middlewares'])) {
            return self::fromLegacyList($config['auth_middlewares']);
        }

        return self::defaults();
    }

    /**
     * Build from a legacy flat middleware list (backward compat).
     *
     * Splits entries into exact (FQCNs) and prefix (aliases) buckets automatically.
     *
     * @param string[] $middlewares
     */
    public static function fromLegacyList(array $middlewares): self
    {
        $exact = [];
        $prefixes = [];

        foreach ($middlewares as $m) {
            $exact[] = $m;

            // Short aliases (no backslash) also get prefix matching
            if (!str_contains($m, '\\') && !str_contains($m, ':')) {
                $prefixes[] = $m;
            }
        }

        return new self($exact, $prefixes, []);
    }

    public static function defaults(): self
    {
        return new self(
            exact: self::defaultExact(),
            prefixes: self::defaultPrefixes(),
            suffixes: [],
        );
    }

    /**
     * Check if a middleware string counts as auth protection.
     */
    public function isAuth(string $middleware): bool
    {
        // Exact match
        foreach ($this->exact as $expected) {
            if ($middleware === $expected) {
                return true;
            }
        }

        // Prefix match (alias:parameter convention)
        foreach ($this->prefixes as $prefix) {
            if (str_starts_with($middleware, $prefix . ':')) {
                return true;
            }
        }

        // Suffix match (opt-in FQCN suffix)
        foreach ($this->suffixes as $suffix) {
            if (str_ends_with($middleware, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if any middleware in the list counts as auth protection.
     *
     * @param string[] $middlewares
     */
    public function hasAuth(array $middlewares): bool
    {
        foreach ($middlewares as $middleware) {
            if ($this->isAuth($middleware)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the first matching auth middleware from the list, or null.
     *
     * @param string[] $middlewares
     */
    public function matchedAuth(array $middlewares): ?string
    {
        foreach ($middlewares as $middleware) {
            if ($this->isAuth($middleware)) {
                return $middleware;
            }
        }

        return null;
    }

    /**
     * Return a flat list of all exact entries (for backward compat / serialization).
     *
     * @return string[]
     */
    public function toFlatList(): array
    {
        return $this->exact;
    }

    /** @return string[] */
    private static function defaultExact(): array
    {
        return [
            'auth',
            'auth:sanctum',
            'Filament\\Http\\Middleware\\Authenticate',
        ];
    }

    /** @return string[] */
    private static function defaultPrefixes(): array
    {
        return ['auth'];
    }
}
