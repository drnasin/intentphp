<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Intent\Drift;

use IntentPHP\Guard\Intent\Auth\AuthRequirement;
use IntentPHP\Guard\Intent\Auth\AuthRule;
use IntentPHP\Guard\Intent\Auth\AuthSpec;
use IntentPHP\Guard\Intent\Baseline\BaselineSpec;
use IntentPHP\Guard\Intent\Data\DataSpec;
use IntentPHP\Guard\Intent\Defaults;
use IntentPHP\Guard\Intent\Drift\Context\ObservedRoute;
use IntentPHP\Guard\Intent\Drift\Context\ProjectContext;
use IntentPHP\Guard\Intent\Drift\Detectors\AuthDriftDetector;
use IntentPHP\Guard\Intent\IntentSpec;
use IntentPHP\Guard\Intent\ProjectMeta;
use IntentPHP\Guard\Intent\Selector\RouteSelector;
use PHPUnit\Framework\TestCase;

class AuthDriftDetectorTest extends TestCase
{
    private function makeSpec(array $rules = [], array $guards = []): IntentSpec
    {
        return new IntentSpec(
            version: '0.1',
            project: new ProjectMeta('test', 'laravel'),
            defaults: new Defaults(),
            auth: new AuthSpec(guards: $guards, rules: $rules),
            data: DataSpec::empty(),
            baseline: BaselineSpec::empty(),
        );
    }

    private function makeContext(array $routes): ProjectContext
    {
        return new ProjectContext($routes, []);
    }

    private function route(
        string $uri,
        string $name = '',
        array $methods = ['GET'],
        array $middleware = [],
    ): ObservedRoute {
        return new ObservedRoute(
            uri: $uri,
            name: $name,
            methods: $methods,
            middleware: $middleware,
            action: 'Closure',
        );
    }

    public function test_authenticated_rule_no_middleware_emits_missing_auth(): void
    {
        $detector = new AuthDriftDetector();
        $spec = $this->makeSpec([
            new AuthRule(
                id: 'require-auth-orders',
                match: new RouteSelector(prefix: '/orders'),
                require: new AuthRequirement(authenticated: true),
            ),
        ]);
        $context = $this->makeContext([
            $this->route('/orders', 'orders.index'),
        ]);

        $items = $detector->detect($spec, $context);

        $this->assertCount(1, $items);
        $this->assertSame('auth', $items[0]->detector);
        $this->assertSame('missing_auth_middleware', $items[0]->driftType);
        $this->assertSame('high', $items[0]->severity);
        $this->assertNull($items[0]->file);
    }

    public function test_guard_mismatch_emits_missing_guard(): void
    {
        $detector = new AuthDriftDetector();
        $spec = $this->makeSpec(
            rules: [
                new AuthRule(
                    id: 'api-guard',
                    match: new RouteSelector(prefix: '/api'),
                    require: new AuthRequirement(authenticated: true, guard: 'api'),
                ),
            ],
            guards: ['api' => 'token'],
        );
        $context = $this->makeContext([
            $this->route('/api/orders', '', ['GET'], ['auth:sanctum']),
        ]);

        $items = $detector->detect($spec, $context);

        $this->assertCount(1, $items);
        $this->assertSame('missing_guard_middleware', $items[0]->driftType);
        $this->assertStringContainsString("guard 'api'", $items[0]->message);
    }

    public function test_public_route_no_middleware_emits_no_drift(): void
    {
        $detector = new AuthDriftDetector();
        $spec = $this->makeSpec([
            new AuthRule(
                id: 'public-login',
                match: new RouteSelector(uri: '/login'),
                require: new AuthRequirement(public: true, authenticated: false, reason: 'Login page'),
            ),
        ]);
        $context = $this->makeContext([
            $this->route('/login'),
        ]);

        $items = $detector->detect($spec, $context);
        $this->assertSame([], $items);
    }

    public function test_public_route_with_auth_middleware_emits_public_but_protected(): void
    {
        $detector = new AuthDriftDetector();
        $spec = $this->makeSpec([
            new AuthRule(
                id: 'public-login',
                match: new RouteSelector(uri: '/login'),
                require: new AuthRequirement(public: true, authenticated: false, reason: 'Login page'),
            ),
        ]);
        $context = $this->makeContext([
            $this->route('/login', 'login', ['GET'], ['auth']),
        ]);

        $items = $detector->detect($spec, $context);

        $this->assertCount(1, $items);
        $this->assertSame('auth', $items[0]->detector);
        $this->assertSame('public_but_protected', $items[0]->driftType);
        $this->assertSame('medium', $items[0]->severity);
        $this->assertStringContainsString('declared public', $items[0]->message);
        $this->assertStringContainsString('auth middleware', $items[0]->message);
    }

    public function test_matching_middleware_emits_no_drift(): void
    {
        $detector = new AuthDriftDetector();
        $spec = $this->makeSpec([
            new AuthRule(
                id: 'require-auth',
                match: new RouteSelector(prefix: '/orders'),
                require: new AuthRequirement(authenticated: true),
            ),
        ]);
        $context = $this->makeContext([
            $this->route('/orders', '', ['GET'], ['auth']),
        ]);

        $items = $detector->detect($spec, $context);
        $this->assertSame([], $items);
    }

    public function test_no_matching_rules_returns_empty(): void
    {
        $detector = new AuthDriftDetector();
        $spec = $this->makeSpec([
            new AuthRule(
                id: 'admin-only',
                match: new RouteSelector(prefix: '/admin'),
                require: new AuthRequirement(authenticated: true),
            ),
        ]);
        $context = $this->makeContext([
            $this->route('/orders'),
        ]);

        $items = $detector->detect($spec, $context);
        $this->assertSame([], $items);
    }

    public function test_no_rules_returns_empty(): void
    {
        $detector = new AuthDriftDetector();
        $spec = $this->makeSpec();
        $context = $this->makeContext([
            $this->route('/orders'),
        ]);

        $items = $detector->detect($spec, $context);
        $this->assertSame([], $items);
    }

    public function test_multiple_rules_same_requirement_grouped(): void
    {
        $detector = new AuthDriftDetector();
        $spec = $this->makeSpec([
            new AuthRule(
                id: 'rule-a',
                match: new RouteSelector(prefix: '/orders'),
                require: new AuthRequirement(authenticated: true),
            ),
            new AuthRule(
                id: 'rule-b',
                match: new RouteSelector(name: 'orders.*'),
                require: new AuthRequirement(authenticated: true),
            ),
        ]);
        $context = $this->makeContext([
            $this->route('/orders', 'orders.index'),
        ]);

        $items = $detector->detect($spec, $context);

        $this->assertCount(1, $items);
        $this->assertSame(['rule-a', 'rule-b'], $items[0]->context['matched_rule_ids']);
    }

    public function test_output_sorted_deterministically(): void
    {
        $detector = new AuthDriftDetector();
        $spec = $this->makeSpec([
            new AuthRule(
                id: 'rule-1',
                match: new RouteSelector(prefix: '/'),
                require: new AuthRequirement(authenticated: true),
            ),
        ]);
        $context = $this->makeContext([
            $this->route('/zebra', 'zebra.index'),
            $this->route('/alpha', 'alpha.index'),
        ]);

        $items1 = $detector->detect($spec, $context);
        $items2 = $detector->detect($spec, $context);

        $this->assertCount(2, $items1);
        // ProjectContext sorts routes by URI, so alpha comes first
        $this->assertStringContainsString('/alpha', $items1[0]->message);
        $this->assertStringContainsString('/zebra', $items1[1]->message);
        // Deterministic
        $this->assertSame($items1[0]->targetId, $items2[0]->targetId);
        $this->assertSame($items1[1]->targetId, $items2[1]->targetId);
    }
}
