<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Invariant\Invariants;

use Illuminate\Routing\Route;
use IntentPHP\Guard\Checks\RouteAuthorizationCheck;
use IntentPHP\Guard\Invariant\Invariant;
use IntentPHP\Guard\Invariant\InvariantInput;
use IntentPHP\Guard\Invariant\Violation;

/**
 * Invariant: every non-public, non-skipped route is authorization-protected
 * (auth middleware, a controller $this->authorize()/->can() call, or a
 * constructor authorizeResource()).
 *
 * Logic moved verbatim from {@see RouteAuthorizationCheck} (Phase 11 migration).
 * Output parity is a contract: id() returns the legacy check name and the
 * Violation carries the same severity/message/context/fix-hint so findings keep
 * identical fingerprints (see DECISIONS D-007). It uses $input->router and
 * $input->detector.
 */
final class RouteAuthorizationInvariant implements Invariant
{
    /** @var string[] */
    private readonly array $publicRoutes;

    /** @var string[] */
    private readonly array $skipGuestRoutes;

    /** @var string[] */
    private readonly array $skipInfraRoutes;

    /**
     * @param string[] $publicRoutes    User-declared public routes
     * @param string[] $skipGuestRoutes Built-in guest auth route skip patterns
     * @param string[] $skipInfraRoutes Built-in infrastructure route skip patterns
     */
    public function __construct(
        array $publicRoutes = [],
        array $skipGuestRoutes = RouteAuthorizationCheck::DEFAULT_SKIP_GUEST,
        array $skipInfraRoutes = RouteAuthorizationCheck::DEFAULT_SKIP_INFRA,
    ) {
        $this->publicRoutes = $publicRoutes;
        $this->skipGuestRoutes = $skipGuestRoutes;
        $this->skipInfraRoutes = $skipInfraRoutes;
    }

    public function id(): string
    {
        return 'route-authorization';
    }

    public function description(): string
    {
        return 'Every non-public route must have authorization protection.';
    }

    /** @return Violation[] */
    public function evaluate(InvariantInput $input): array
    {
        $router = $input->router;
        $detector = $input->detector;

        if ($router === null || $detector === null) {
            return [];
        }

        $violations = [];

        foreach ($router->getRoutes() as $route) {
            /** @var Route $route */
            $uri = $route->uri();

            if ($this->isSkippedRoute($uri)) {
                continue;
            }

            if ($this->isPublicRoute($uri)) {
                continue;
            }

            $middlewares = $detector->collectMiddleware($route);

            if ($detector->hasAuthMiddleware($middlewares)) {
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

            $violations[] = new Violation(
                invariantId: $this->id(),
                targetId: "{$methodsLabel} {$uri}@{$action}",
                severity: 'high',
                message: "Route [{$methodsLabel}] {$uri} has no authorization protection.",
                file: null,
                line: null,
                context: $context,
                fixHint: "Add auth middleware to this route or its group, or call \$this->authorize() in the controller method.",
            );
        }

        return $violations;
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
