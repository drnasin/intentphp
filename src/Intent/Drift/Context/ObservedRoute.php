<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Drift\Context;

final readonly class ObservedRoute
{
    /**
     * @param string   $uri        URI with leading slash
     * @param string   $name       Route name, or "" if unnamed
     * @param string[] $methods    Uppercase, sorted alpha, HEAD excluded
     * @param string[] $middleware  Resolved middleware strings
     * @param string   $action     Controller action string or "Closure"
     */
    public function __construct(
        public string $uri,
        public string $name,
        public array $methods,
        public array $middleware,
        public string $action,
    ) {}
}
