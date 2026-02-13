<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Checks;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use IntentPHP\Guard\Intent\Auth\AuthRule;
use IntentPHP\Guard\Intent\IntentSpec;
use IntentPHP\Guard\Scan\Finding;

class IntentAuthCheck implements CheckInterface
{
    public function __construct(
        private readonly Router $router,
        private readonly IntentSpec $spec,
        private readonly RouteProtectionDetector $detector,
    ) {}

    public function name(): string
    {
        return 'intent-auth';
    }

    /** @return Finding[] */
    public function run(): array
    {
        $rules = $this->spec->auth->rules;

        if ($rules === []) {
            return [];
        }

        $findings = [];

        foreach ($this->router->getRoutes() as $route) {
            /** @var Route $route */
            $routeFindings = $this->checkRoute($route, $rules);
            foreach ($routeFindings as $f) {
                $findings[] = $f;
            }
        }

        return $findings;
    }

    /**
     * @param AuthRule[] $rules
     * @return Finding[]
     */
    private function checkRoute(Route $route, array $rules): array
    {
        $rawUri = $route->uri();
        // RouteSelector expects URIs with a leading slash
        $uri = '/' . ltrim($rawUri, '/');
        $routeName = $route->getName() ?? '';
        $rawMethods = $route->methods();

        // Exclude HEAD, sort alphabetically for stable fingerprints
        $methods = array_values(array_filter($rawMethods, fn (string $m) => $m !== 'HEAD'));
        sort($methods);

        $middlewares = $this->detector->collectMiddleware($route);
        $hasAuth = $this->detector->hasAuthMiddleware($middlewares);
        $action = $route->getActionName();

        // Find all matching rules
        $matchedRules = [];
        foreach ($rules as $rule) {
            if ($this->ruleMatchesRoute($rule, $routeName, $uri, $methods)) {
                $matchedRules[] = $rule;
            }
        }

        if ($matchedRules === []) {
            return [];
        }

        // Group by canonical requirement to deduplicate
        $grouped = $this->groupByRequirement($matchedRules);
        $findings = [];

        foreach ($grouped as $group) {
            /** @var AuthRule[] $groupRules */
            $groupRules = $group['rules'];
            $requirement = $groupRules[0]->require;

            $ruleIds = array_map(fn (AuthRule $r) => $r->id, $groupRules);
            sort($ruleIds);

            $context = [
                'matched_rule_ids' => $ruleIds,
                'uri' => $uri,
                'route_name' => $routeName,
                'methods' => $methods,
                'action' => $action,
                'middleware' => $middlewares,
                'require' => $requirement->toArray(),
            ];

            $finding = $this->evaluateRequirement($requirement, $hasAuth, $middlewares, $uri, $methods, $context);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function ruleMatchesRoute(AuthRule $rule, string $routeName, string $uri, array $methods): bool
    {
        $selector = $rule->match;

        // If the selector has a methods constraint, check intersection with route methods (excluding HEAD)
        if ($selector->methods !== null) {
            $intersection = array_intersect($methods, $selector->methods);
            if ($intersection === []) {
                return false;
            }
        }

        // Test each route method against the selector (without its methods constraint,
        // since we already checked methods above)
        // We need at least one method to match the non-method selectors
        foreach ($methods as $method) {
            if ($selector->matches($routeName, $uri, $method)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Group matched rules by their canonical requirement key.
     *
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
     * @param string[] $middlewares
     * @param string[] $methods
     * @param array<string, mixed> $context
     */
    private function evaluateRequirement(
        \IntentPHP\Guard\Intent\Auth\AuthRequirement $requirement,
        bool $hasAuth,
        array $middlewares,
        string $uri,
        array $methods,
        array $context,
    ): ?Finding {
        $methodsStr = implode('|', $methods);

        // Public route declaration
        if ($requirement->public) {
            if (! $hasAuth) {
                return Finding::medium(
                    check: $this->name(),
                    message: "Route [{$methodsStr}] {$uri} is declared public in intent spec but has no auth middleware.",
                    context: $context,
                    fix_hint: "Add to `config/guard.php` `public_routes` to also silence route-authorization check.",
                );
            }

            // Protected route declared public — no finding (informational)
            return null;
        }

        // Authenticated requirement
        if ($requirement->authenticated && ! $hasAuth) {
            return Finding::high(
                check: $this->name(),
                message: "Route [{$methodsStr}] {$uri} requires authentication per intent spec but has no auth middleware.",
                context: $context,
                fix_hint: "Add auth middleware to this route or its group.",
            );
        }

        // Guard-specific requirement
        if ($requirement->guard !== null && ! $this->detector->hasGuardMiddleware($middlewares, $requirement->guard)) {
            return Finding::high(
                check: $this->name(),
                message: "Route [{$methodsStr}] {$uri} requires guard '{$requirement->guard}' per intent spec but middleware does not include 'auth:{$requirement->guard}'.",
                context: $context,
                fix_hint: "Add 'auth:{$requirement->guard}' middleware to this route.",
            );
        }

        return null;
    }
}
