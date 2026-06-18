<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Checks;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use IntentPHP\Guard\Scan\Finding;

class RouteAuthorizationCheck implements CheckInterface
{
    /** @var string[] */
    private readonly array $publicRoutes;

    /** @var string[] */
    private readonly array $skipGuestRoutes;

    /** @var string[] */
    private readonly array $skipInfraRoutes;

    private readonly RouteProtectionDetector $detector;

    public const DEFAULT_SKIP_GUEST = [
        'login',
        'register',
        'forgot-password',
        'reset-password/*',
        'two-factor-challenge',
        'email/verify',
        'email/verify/*',
        'confirm-password',
    ];

    public const DEFAULT_SKIP_INFRA = [
        'up',
        'health',
        'sanctum/csrf-cookie',
        'livewire/*',
        '_ignition/*',
        '_debugbar/*',
        '_boost/*',
    ];

    /**
     * @param string[] $authMiddlewares Legacy flat list (ignored if $detector is provided)
     * @param string[] $publicRoutes    User-declared public routes
     * @param string[] $skipGuestRoutes Built-in guest auth route skip patterns
     * @param string[] $skipInfraRoutes Built-in infrastructure route skip patterns
     */
    public function __construct(
        private readonly Router $router,
        array $authMiddlewares = [],
        array $publicRoutes = [],
        ?RouteProtectionDetector $detector = null,
        array $skipGuestRoutes = self::DEFAULT_SKIP_GUEST,
        array $skipInfraRoutes = self::DEFAULT_SKIP_INFRA,
    ) {
        $this->publicRoutes = $publicRoutes;
        $this->skipGuestRoutes = $skipGuestRoutes;
        $this->skipInfraRoutes = $skipInfraRoutes;
        $this->detector = $detector ?? new RouteProtectionDetector($authMiddlewares);
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

            if ($this->isSkippedRoute($uri)) {
                continue;
            }

            if ($this->isPublicRoute($uri)) {
                continue;
            }

            $middlewares = $this->detector->collectMiddleware($route);

            if ($this->detector->hasAuthMiddleware($middlewares)) {
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

            // Canonical methods (HEAD excluded, sorted) so the stored context
            // and message are stable regardless of registration order — mirrors
            // IntentAuthCheck and keeps the baseline byte-stable.
            $methodList = array_values(array_filter(
                $route->methods(),
                static fn (string $m): bool => $m !== 'HEAD',
            ));
            sort($methodList);
            $methodsLabel = implode('|', $methodList);

            $context = [
                    'uri' => $uri,
                    'methods' => $methodList,
                    'action' => $action,
                    'middleware' => $middlewares,
                ];

            if ($hasFormRequest) {
                $context['has_form_request'] = true;
            }

            $findings[] = Finding::high(
                check: $this->name(),
                message: "Route [{$methodsLabel}] {$uri} has no authorization protection.",
                context: $context,
                fix_hint: "Add auth middleware to this route or its group, or call \$this->authorize() in the controller method.",
            );
        }

        return $findings;
    }

    /**
     * Check built-in skip lists (guest auth + infrastructure routes).
     */
    private function isSkippedRoute(string $uri): bool
    {
        $uri = ltrim($uri, '/');

        foreach ($this->skipGuestRoutes as $pattern) {
            if ($this->matchesPattern($uri, $pattern)) {
                return true;
            }
        }

        foreach ($this->skipInfraRoutes as $pattern) {
            if ($this->matchesPattern($uri, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check user-declared public routes.
     */
    private function isPublicRoute(string $uri): bool
    {
        $uri = ltrim($uri, '/');

        foreach ($this->publicRoutes as $pattern) {
            if ($this->matchesPattern($uri, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function matchesPattern(string $uri, string $pattern): bool
    {
        $pattern = ltrim($pattern, '/');

        if ($uri === $pattern) {
            return true;
        }

        if (str_contains($pattern, '*') && fnmatch($pattern, $uri)) {
            return true;
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
        $methodBody = $this->stripCommentsAndStrings($methodBody);

        // `->can(` / `->cannot(` are anchored to a real call boundary (covers
        // `$this->can(` and `$user->can(`) so method names that merely contain
        // the substring "can(" — scan(), rescan(), lifespan() — are not misread
        // as authorized. Comments and string literals were stripped above so
        // these tokens only match real code.
        return (bool) preg_match('/(\$this->authorize\(|Gate::authorize\(|Gate::allows\(|Gate::denies\(|Gate::check\(|\$this->authorizeResource\(|->can\(|->cannot\()/', $methodBody);
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
        $constructorBody = $this->stripCommentsAndStrings($constructorBody);

        return (bool) preg_match('/\$this->authorizeResource\(/', $constructorBody);
    }

    /**
     * Remove comment and string-literal contents from a raw PHP source slice so
     * authorization tokens are only matched in real code, not inside comments or
     * strings. Deterministic, dependency-free (PHP core tokenizer).
     *
     * The slice is a method/constructor body, not a complete program, so it is
     * prefixed with an open tag to tokenize. Interpolated strings keep their
     * embedded variables/property accesses (e.g. `$this->authorize`) but lose the
     * literal text around them (the `(` lands in an encapsed token), so a token
     * appearing only inside a string never matches the anchored call patterns.
     */
    private function stripCommentsAndStrings(string $body): string
    {
        $tokens = @token_get_all('<?php ' . $body);

        $out = '';
        foreach ($tokens as $token) {
            if (is_array($token)) {
                $id = $token[0];

                if ($id === \T_COMMENT || $id === \T_DOC_COMMENT) {
                    continue;
                }

                if ($id === \T_CONSTANT_ENCAPSED_STRING || $id === \T_ENCAPSED_AND_WHITESPACE) {
                    continue;
                }

                $out .= $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
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
