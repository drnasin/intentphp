<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Drift\Detectors;

use IntentPHP\Guard\Intent\Auth\AuthRequirement;
use IntentPHP\Guard\Intent\Auth\AuthRule;
use IntentPHP\Guard\Intent\Drift\Context\ObservedRoute;
use IntentPHP\Guard\Intent\Drift\Context\ProjectContext;
use IntentPHP\Guard\Intent\Drift\DriftDetectorInterface;
use IntentPHP\Guard\Intent\Drift\DriftItem;
use IntentPHP\Guard\Intent\Drift\RouteIdentifier;
use IntentPHP\Guard\Intent\IntentSpec;

final class AuthDriftDetector implements DriftDetectorInterface
{
    /** @var string[] */
    private readonly array $authMiddlewares;

    /** @param string[] $authMiddlewares */
    public function __construct(array $authMiddlewares = ['auth', 'auth:sanctum'])
    {
        $this->authMiddlewares = $authMiddlewares;
    }

    public function name(): string
    {
        return 'auth';
    }

    /** @return DriftItem[] */
    public function detect(IntentSpec $spec, ProjectContext $context): array
    {
        $rules = $spec->auth->rules;

        if ($rules === []) {
            return [];
        }

        $items = [];

        foreach ($context->routes as $route) {
            array_push($items, ...$this->checkRoute($route, $rules));
        }

        return $items;
    }

    /**
     * @param AuthRule[] $rules
     * @return DriftItem[]
     */
    private function checkRoute(ObservedRoute $route, array $rules): array
    {
        $matchedRules = [];

        foreach ($rules as $rule) {
            if ($this->ruleMatchesRoute($rule, $route)) {
                $matchedRules[] = $rule;
            }
        }

        if ($matchedRules === []) {
            return [];
        }

        $grouped = $this->groupByRequirement($matchedRules);
        $items = [];

        foreach ($grouped as $group) {
            /** @var AuthRule[] $groupRules */
            $groupRules = $group['rules'];
            $requirement = $groupRules[0]->require;

            $ruleIds = array_map(static fn (AuthRule $r): string => $r->id, $groupRules);
            sort($ruleIds);
            $firstRuleId = $ruleIds[0];

            $routeId = RouteIdentifier::composite($route);
            $hasAuth = $this->hasAuthMiddleware($route->middleware);

            $item = $this->evaluateRequirement(
                $requirement, $hasAuth, $route, $firstRuleId, $ruleIds, $routeId,
            );

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function ruleMatchesRoute(AuthRule $rule, ObservedRoute $route): bool
    {
        $selector = $rule->match;

        if ($selector->methods !== null) {
            $intersection = array_intersect($route->methods, $selector->methods);

            if ($intersection === []) {
                return false;
            }
        }

        foreach ($route->methods as $method) {
            if ($selector->matches($route->name, $route->uri, $method)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param AuthRule[] $rules
     * @return array<string, array{rules: AuthRule[]}>
     */
    private function groupByRequirement(array $rules): array
    {
        $groups = [];

        foreach ($rules as $rule) {
            $key = json_encode($rule->require->toCanonicalArray(), JSON_THROW_ON_ERROR);

            if (! isset($groups[$key])) {
                $groups[$key] = ['rules' => []];
            }

            $groups[$key]['rules'][] = $rule;
        }

        return $groups;
    }

    /**
     * @param string[] $ruleIds
     */
    private function evaluateRequirement(
        AuthRequirement $requirement,
        bool $hasAuth,
        ObservedRoute $route,
        string $firstRuleId,
        array $ruleIds,
        string $routeId,
    ): ?DriftItem {
        $methodsStr = implode('|', $route->methods);

        $baseContext = [
            'rule_id' => $firstRuleId,
            'matched_rule_ids' => $ruleIds,
            'route_identifier' => $routeId,
            'uri' => $route->uri,
            'route_name' => $route->name,
            'methods' => $route->methods,
            'middleware' => $route->middleware,
            'require' => $requirement->toArray(),
        ];

        // Public declaration: check for unnecessary auth middleware (spec↔code divergence)
        if ($requirement->public) {
            if ($hasAuth) {
                return new DriftItem(
                    detector: $this->name(),
                    driftType: 'public_but_protected',
                    targetId: $routeId,
                    severity: 'medium',
                    message: "Route [{$methodsStr}] {$route->uri} is declared public in intent spec but has auth middleware.",
                    file: null,
                    context: array_merge($baseContext, ['drift_type' => 'public_but_protected']),
                    fixHint: 'Remove auth middleware from this route, or update the intent spec to require authentication.',
                );
            }

            return null;
        }

        if ($requirement->authenticated && ! $hasAuth) {
            return new DriftItem(
                detector: $this->name(),
                driftType: 'missing_auth_middleware',
                targetId: $routeId,
                severity: 'high',
                message: "Route [{$methodsStr}] {$route->uri} requires authentication per intent spec but has no auth middleware.",
                file: null,
                context: array_merge($baseContext, ['drift_type' => 'missing_auth_middleware']),
                fixHint: 'Add auth middleware to this route or its group.',
            );
        }

        if ($requirement->guard !== null && ! $this->hasGuardMiddleware($route->middleware, $requirement->guard)) {
            return new DriftItem(
                detector: $this->name(),
                driftType: 'missing_guard_middleware',
                targetId: $routeId,
                severity: 'high',
                message: "Route [{$methodsStr}] {$route->uri} requires guard '{$requirement->guard}' per intent spec but middleware does not include 'auth:{$requirement->guard}'.",
                file: null,
                context: array_merge($baseContext, ['drift_type' => 'missing_guard_middleware']),
                fixHint: "Add 'auth:{$requirement->guard}' middleware to this route.",
            );
        }

        return null;
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

    /** @param string[] $middlewares */
    private function hasGuardMiddleware(array $middlewares, string $guard): bool
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
