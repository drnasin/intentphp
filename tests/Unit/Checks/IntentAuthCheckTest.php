<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Checks;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use IntentPHP\Guard\Checks\IntentAuthCheck;
use IntentPHP\Guard\Checks\RouteProtectionDetector;
use IntentPHP\Guard\Intent\Auth\AuthRequirement;
use IntentPHP\Guard\Intent\Auth\AuthRule;
use IntentPHP\Guard\Intent\Auth\AuthSpec;
use IntentPHP\Guard\Intent\Data\DataSpec;
use IntentPHP\Guard\Intent\Baseline\BaselineSpec;
use IntentPHP\Guard\Intent\Defaults;
use IntentPHP\Guard\Intent\IntentSpec;
use IntentPHP\Guard\Intent\ProjectMeta;
use IntentPHP\Guard\Intent\Selector\RouteSelector;
use PHPUnit\Framework\TestCase;

class IntentAuthCheckTest extends TestCase
{
    private function makeRouter(): Router
    {
        $container = new Container();
        return new Router(new Dispatcher($container), $container);
    }

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

    public function test_no_rules_returns_no_findings(): void
    {
        $router = $this->makeRouter();
        $router->get('/orders', fn () => 'ok');

        $spec = $this->makeSpec();
        $detector = new RouteProtectionDetector();
        $check = new IntentAuthCheck($router, $spec, $detector);

        $findings = $check->run();
        $this->assertSame([], $findings);
    }

    public function test_unprotected_route_with_authenticated_rule_emits_finding(): void
    {
        $router = $this->makeRouter();
        $router->get('/orders', fn () => 'ok')->name('orders.index');

        $spec = $this->makeSpec([
            new AuthRule(
                id: 'require-auth-orders',
                match: new RouteSelector(prefix: '/orders'),
                require: new AuthRequirement(authenticated: true),
            ),
        ]);

        $detector = new RouteProtectionDetector();
        $check = new IntentAuthCheck($router, $spec, $detector);

        $findings = $check->run();
        $this->assertCount(1, $findings);
        $this->assertSame('intent-auth', $findings[0]->check);
        $this->assertSame('high', $findings[0]->severity);
        $this->assertStringContainsString('requires authentication', $findings[0]->message);
    }

    public function test_protected_route_with_authenticated_rule_emits_no_finding(): void
    {
        $router = $this->makeRouter();
        $router->get('/orders', fn () => 'ok')->middleware('auth');

        $spec = $this->makeSpec([
            new AuthRule(
                id: 'require-auth-orders',
                match: new RouteSelector(prefix: '/orders'),
                require: new AuthRequirement(authenticated: true),
            ),
        ]);

        $detector = new RouteProtectionDetector();
        $check = new IntentAuthCheck($router, $spec, $detector);

        $findings = $check->run();
        $this->assertSame([], $findings);
    }

    public function test_public_rule_with_unprotected_route_emits_medium_finding(): void
    {
        $router = $this->makeRouter();
        $router->get('/login', fn () => 'ok')->name('login');

        $spec = $this->makeSpec([
            new AuthRule(
                id: 'public-login',
                match: new RouteSelector(uri: '/login'),
                require: new AuthRequirement(public: true, authenticated: false, reason: 'Login page'),
            ),
        ]);

        $detector = new RouteProtectionDetector();
        $check = new IntentAuthCheck($router, $spec, $detector);

        $findings = $check->run();
        $this->assertCount(1, $findings);
        $this->assertSame('medium', $findings[0]->severity);
        $this->assertStringContainsString('public_routes', $findings[0]->fix_hint);
    }

    public function test_public_rule_with_protected_route_emits_no_finding(): void
    {
        $router = $this->makeRouter();
        $router->get('/login', fn () => 'ok')->middleware('auth');

        $spec = $this->makeSpec([
            new AuthRule(
                id: 'public-login',
                match: new RouteSelector(uri: '/login'),
                require: new AuthRequirement(public: true, authenticated: false, reason: 'Login page'),
            ),
        ]);

        $detector = new RouteProtectionDetector();
        $check = new IntentAuthCheck($router, $spec, $detector);

        $findings = $check->run();
        $this->assertSame([], $findings);
    }

    public function test_guard_mismatch_emits_finding(): void
    {
        $router = $this->makeRouter();
        $router->get('/api/orders', fn () => 'ok')->middleware('auth:sanctum');

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

        $detector = new RouteProtectionDetector();
        $check = new IntentAuthCheck($router, $spec, $detector);

        $findings = $check->run();
        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
        $this->assertStringContainsString("guard 'api'", $findings[0]->message);
    }

    public function test_multiple_rules_same_requirement_emit_one_finding(): void
    {
        $router = $this->makeRouter();
        $router->get('/orders', fn () => 'ok')->name('orders.index');

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

        $detector = new RouteProtectionDetector();
        $check = new IntentAuthCheck($router, $spec, $detector);

        $findings = $check->run();
        $this->assertCount(1, $findings);
        $this->assertSame(['rule-a', 'rule-b'], $findings[0]->context['matched_rule_ids']);
    }

    public function test_multiple_rules_different_requirements_emit_separate_findings(): void
    {
        $router = $this->makeRouter();
        $router->get('/orders', fn () => 'ok')->name('orders.index');

        $spec = $this->makeSpec(
            rules: [
                new AuthRule(
                    id: 'rule-auth',
                    match: new RouteSelector(prefix: '/orders'),
                    require: new AuthRequirement(authenticated: true),
                ),
                new AuthRule(
                    id: 'rule-guard',
                    match: new RouteSelector(name: 'orders.*'),
                    require: new AuthRequirement(authenticated: true, guard: 'api'),
                ),
            ],
            guards: ['api' => 'token'],
        );

        $detector = new RouteProtectionDetector();
        $check = new IntentAuthCheck($router, $spec, $detector);

        $findings = $check->run();
        $this->assertCount(2, $findings);

        $ruleIds = array_map(fn ($f) => $f->context['matched_rule_ids'], $findings);
        $this->assertContains(['rule-auth'], $ruleIds);
        $this->assertContains(['rule-guard'], $ruleIds);
    }

    public function test_head_excluded_from_methods_and_methods_sorted(): void
    {
        $router = $this->makeRouter();
        // GET routes automatically include HEAD in Laravel
        $router->get('/orders', fn () => 'ok');

        $spec = $this->makeSpec([
            new AuthRule(
                id: 'rule-1',
                match: new RouteSelector(prefix: '/orders'),
                require: new AuthRequirement(authenticated: true),
            ),
        ]);

        $detector = new RouteProtectionDetector();
        $check = new IntentAuthCheck($router, $spec, $detector);

        $findings = $check->run();
        $this->assertCount(1, $findings);
        $this->assertSame(['GET'], $findings[0]->context['methods']);
        $this->assertNotContains('HEAD', $findings[0]->context['methods']);
    }

    public function test_finding_has_correct_check_name_and_matched_rule_ids(): void
    {
        $router = $this->makeRouter();
        $router->post('/orders', fn () => 'ok');

        $spec = $this->makeSpec([
            new AuthRule(
                id: 'orders-auth',
                match: new RouteSelector(prefix: '/orders'),
                require: new AuthRequirement(authenticated: true),
            ),
        ]);

        $detector = new RouteProtectionDetector();
        $check = new IntentAuthCheck($router, $spec, $detector);

        $findings = $check->run();
        $this->assertCount(1, $findings);
        $this->assertSame('intent-auth', $findings[0]->check);
        $this->assertSame(['orders-auth'], $findings[0]->context['matched_rule_ids']);
    }

    public function test_fingerprint_stable_across_runs(): void
    {
        $router = $this->makeRouter();
        $router->match(['GET', 'POST'], '/orders', fn () => 'ok')->name('orders.index');

        $spec = $this->makeSpec([
            new AuthRule(
                id: 'rule-b',
                match: new RouteSelector(prefix: '/orders'),
                require: new AuthRequirement(authenticated: true),
            ),
            new AuthRule(
                id: 'rule-a',
                match: new RouteSelector(name: 'orders.*'),
                require: new AuthRequirement(authenticated: true),
            ),
        ]);

        $detector = new RouteProtectionDetector();
        $check = new IntentAuthCheck($router, $spec, $detector);

        $findings1 = $check->run();
        $findings2 = $check->run();

        $this->assertCount(1, $findings1);
        $this->assertCount(1, $findings2);
        $this->assertSame($findings1[0]->fingerprint(), $findings2[0]->fingerprint());
    }
}
