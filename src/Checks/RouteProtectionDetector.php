<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Checks;

use Illuminate\Routing\Route;

final readonly class RouteProtectionDetector
{
    private AuthMiddlewareClassifier $classifier;

    /**
     * @param string[]|AuthMiddlewareClassifier $authMiddlewares Flat list (legacy) or classifier instance
     */
    public function __construct(array|AuthMiddlewareClassifier $authMiddlewares = [])
    {
        if ($authMiddlewares instanceof AuthMiddlewareClassifier) {
            $this->classifier = $authMiddlewares;
        } elseif ($authMiddlewares === []) {
            $this->classifier = AuthMiddlewareClassifier::defaults();
        } else {
            $this->classifier = AuthMiddlewareClassifier::fromLegacyList($authMiddlewares);
        }
    }

    /**
     * @return string[]
     */
    public function collectMiddleware(Route $route): array
    {
        $middleware = $route->gatherMiddleware();

        return array_values(array_map(
            fn ($m) => is_string($m) ? $m : get_class($m),
            $middleware,
        ));
    }

    /**
     * @param string[] $middlewares
     */
    public function hasAuthMiddleware(array $middlewares): bool
    {
        return $this->classifier->hasAuth($middlewares);
    }

    /**
     * Check if the middleware list includes a specific guard.
     *
     * @param string[] $middlewares
     */
    public function hasGuardMiddleware(array $middlewares, string $guard): bool
    {
        $expected = 'auth:' . $guard;

        foreach ($middlewares as $middleware) {
            if ($middleware === $expected) {
                return true;
            }
        }

        return false;
    }

    public function getClassifier(): AuthMiddlewareClassifier
    {
        return $this->classifier;
    }
}
