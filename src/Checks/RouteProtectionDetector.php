<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Checks;

use Illuminate\Routing\Route;

final readonly class RouteProtectionDetector
{
    /** @var string[] */
    private array $authMiddlewares;

    /**
     * @param string[] $authMiddlewares
     */
    public function __construct(array $authMiddlewares = ['auth', 'auth:sanctum'])
    {
        $this->authMiddlewares = $authMiddlewares;
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
        foreach ($middlewares as $middleware) {
            foreach ($this->authMiddlewares as $authMiddleware) {
                if ($middleware === $authMiddleware || str_starts_with($middleware, $authMiddleware . ':')) {
                    return true;
                }
            }
        }

        return false;
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
}
