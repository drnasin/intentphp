<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Checks;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use IntentPHP\Guard\Scan\Finding;

class RouteAuthorizationCheck implements CheckInterface
{
    /** @var string[] */
    private readonly array $authMiddlewares;

    /** @var string[] */
    private readonly array $publicRoutes;

    /**
     * @param string[] $authMiddlewares
     * @param string[] $publicRoutes
     */
    public function __construct(
        private readonly Router $router,
        array $authMiddlewares = ['auth', 'auth:sanctum'],
        array $publicRoutes = [],
    ) {
        $this->authMiddlewares = $authMiddlewares;
        $this->publicRoutes = $publicRoutes;
    }

    public function name(): string
    {
        return 'route-authorization';
    }

    /** @return Finding[] */
    public function run(): array
    {
        $findings = [];

        foreach ($this->router->getRoutes() as $route) {
            /** @var Route $route */
            $uri = $route->uri();

            if ($this->isPublicRoute($uri)) {
                continue;
            }

            $middlewares = $this->collectMiddleware($route);

            if ($this->hasAuthMiddleware($middlewares)) {
                continue;
            }

            $action = $route->getActionName();

            if ($this->controllerCallsAuthorize($action)) {
                continue;
            }

            if ($this->constructorCallsAuthorizeResource($action)) {
                continue;
            }

            $hasFormRequest = $this->methodHasFormRequest($action);

            $methods = implode('|', $route->methods());

            $context = [
                    'uri' => $uri,
                    'methods' => $route->methods(),
                    'action' => $action,
                    'middleware' => $middlewares,
                ];

            if ($hasFormRequest) {
                $context['has_form_request'] = true;
            }

            $findings[] = Finding::high(
                check: $this->name(),
                message: "Route [{$methods}] {$uri} has no authorization protection.",
                context: $context,
                fix_hint: "Add auth middleware to this route or its group, or call \$this->authorize() in the controller method.",
            );
        }

        return $findings;
    }

    private function isPublicRoute(string $uri): bool
    {
        $uri = ltrim($uri, '/');

        foreach ($this->publicRoutes as $pattern) {
            $pattern = ltrim($pattern, '/');

            if ($uri === $pattern) {
                return true;
            }

            if (str_contains($pattern, '*') && fnmatch($pattern, $uri)) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] */
    private function collectMiddleware(Route $route): array
    {
        $middleware = $route->gatherMiddleware();

        return array_values(array_map(
            fn ($m) => is_string($m) ? $m : get_class($m),
            $middleware,
        ));
    }

    /** @param string[] $middlewares */
    private function hasAuthMiddleware(array $middlewares): bool
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

    private function controllerCallsAuthorize(string $action): bool
    {
        if ($action === 'Closure' || ! str_contains($action, '@')) {
            return false;
        }

        [$class, $method] = explode('@', $action);

        if (! class_exists($class)) {
            return false;
        }

        try {
            $reflection = new \ReflectionMethod($class, $method);
        } catch (\ReflectionException) {
            return false;
        }

        $file = $reflection->getFileName();
        if ($file === false || ! is_readable($file)) {
            return false;
        }

        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();

        if ($startLine === false || $endLine === false) {
            return false;
        }

        $lines = file($file);
        if ($lines === false) {
            return false;
        }

        $methodBody = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

        return (bool) preg_match('/(\$this->authorize\(|Gate::authorize\(|Gate::allows\(|Gate::denies\(|Gate::check\(|\$this->authorizeResource\(|can\(|cannot\()/', $methodBody);
    }

    private function constructorCallsAuthorizeResource(string $action): bool
    {
        if ($action === 'Closure' || ! str_contains($action, '@')) {
            return false;
        }

        [$class] = explode('@', $action);

        if (! class_exists($class)) {
            return false;
        }

        try {
            $ref = new \ReflectionMethod($class, '__construct');
        } catch (\ReflectionException) {
            return false;
        }

        $file = $ref->getFileName();
        if ($file === false || ! is_readable($file)) {
            return false;
        }

        $startLine = $ref->getStartLine();
        $endLine = $ref->getEndLine();

        if ($startLine === false || $endLine === false) {
            return false;
        }

        $lines = file($file);
        if ($lines === false) {
            return false;
        }

        $constructorBody = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

        return (bool) preg_match('/\$this->authorizeResource\(/', $constructorBody);
    }

    private function methodHasFormRequest(string $action): bool
    {
        if ($action === 'Closure' || ! str_contains($action, '@')) {
            return false;
        }

        [$class, $method] = explode('@', $action);

        if (! class_exists($class)) {
            return false;
        }

        try {
            $ref = new \ReflectionMethod($class, $method);
        } catch (\ReflectionException) {
            return false;
        }

        foreach ($ref->getParameters() as $param) {
            $type = $param->getType();

            if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $typeName = $type->getName();

            // Skip base Illuminate\Http\Request — only flag custom FormRequests
            if ($typeName === 'Illuminate\\Http\\Request') {
                continue;
            }

            if (is_subclass_of($typeName, 'Illuminate\\Foundation\\Http\\FormRequest')) {
                return true;
            }
        }

        return false;
    }
}
