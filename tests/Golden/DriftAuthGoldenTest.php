<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Golden;

use IntentPHP\Guard\Checks\Intent\IntentDriftCheck;
use IntentPHP\Guard\Intent\Auth\AuthRequirement;
use IntentPHP\Guard\Intent\Auth\AuthRule;
use IntentPHP\Guard\Intent\Auth\AuthSpec;
use IntentPHP\Guard\Intent\Baseline\BaselineSpec;
use IntentPHP\Guard\Intent\Data\DataSpec;
use IntentPHP\Guard\Intent\Defaults;
use IntentPHP\Guard\Intent\Drift\Context\ObservedRoute;
use IntentPHP\Guard\Intent\Drift\Context\ProjectContext;
use IntentPHP\Guard\Intent\Drift\Detectors\AuthDriftDetector;
use IntentPHP\Guard\Intent\Drift\DriftEngine;
use IntentPHP\Guard\Intent\IntentSpec;
use IntentPHP\Guard\Intent\ProjectMeta;
use IntentPHP\Guard\Intent\Selector\RouteSelector;
use IntentPHP\Guard\Scan\Finding;
use PHPUnit\Framework\TestCase;

class DriftAuthGoldenTest extends TestCase
{
    private function buildFixtures(): array
    {
        $spec = new IntentSpec(
            version: '0.1',
            project: new ProjectMeta('golden-test', 'laravel'),
            defaults: new Defaults(),
            auth: new AuthSpec(
                guards: ['api' => 'token'],
                rules: [
                    new AuthRule(
                        id: 'require-auth-orders',
                        match: new RouteSelector(prefix: '/orders'),
                        require: new AuthRequirement(authenticated: true),
                    ),
                    new AuthRule(
                        id: 'api-guard-items',
                        match: new RouteSelector(prefix: '/api/items'),
                        require: new AuthRequirement(authenticated: true, guard: 'api'),
                    ),
                ],
            ),
            data: DataSpec::empty(),
            baseline: BaselineSpec::empty(),
        );

        $context = new ProjectContext(
            routes: [
                // Unprotected — should trigger missing_auth_middleware
                new ObservedRoute('/orders', 'orders.index', ['GET'], [], 'App\\Http\\Controllers\\OrderController@index'),
                // Wrong guard — should trigger missing_guard_middleware
                new ObservedRoute('/api/items', '', ['GET', 'POST'], ['auth:sanctum'], 'App\\Http\\Controllers\\ItemController@index'),
                // Compliant — no drift
                new ObservedRoute('/dashboard', 'dashboard', ['GET'], ['auth'], 'App\\Http\\Controllers\\DashboardController@index'),
            ],
            models: [],
        );

        return [$spec, $context];
    }

    public function test_golden_output_matches_expected(): void
    {
        [$spec, $context] = $this->buildFixtures();

        $engine = new DriftEngine([new AuthDriftDetector()]);
        $check = new IntentDriftCheck($engine, $spec, $context);
        $findings = $check->run();

        $actual = array_map(fn (Finding $f) => $f->toArray(), $findings);

        // Verify structural properties
        $this->assertCount(2, $actual);

        // First: missing_auth_middleware for /orders (sort key: "auth|name:orders.index|..." < "auth|uri:...")
        $this->assertSame('intent-drift/auth', $actual[0]['check']);
        $this->assertSame('high', $actual[0]['severity']);
        $this->assertSame('missing_auth_middleware', $actual[0]['context']['drift_type']);
        $this->assertStringContainsString('/orders', $actual[0]['message']);

        // Second: missing_guard_middleware for /api/items
        $this->assertSame('intent-drift/auth', $actual[1]['check']);
        $this->assertSame('high', $actual[1]['severity']);
        $this->assertSame('missing_guard_middleware', $actual[1]['context']['drift_type']);
        $this->assertStringContainsString('/api/items', $actual[1]['message']);

        // Snapshot comparison against stored fixture
        $expectedPath = __DIR__ . '/../fixtures/drift/auth/expected.json';

        if (getenv('UPDATE_GOLDEN') === '1' || ! file_exists($expectedPath)) {
            $dir = dirname($expectedPath);

            if (! is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            file_put_contents(
                $expectedPath,
                json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            );
            $this->markTestSkipped('Golden fixture generated: ' . $expectedPath);
        }

        $expected = json_decode(file_get_contents($expectedPath), true);
        $this->assertSame($expected, $actual);
    }

    public function test_determinism_run_twice_identical(): void
    {
        [$spec, $context] = $this->buildFixtures();

        $engine = new DriftEngine([new AuthDriftDetector()]);

        $check1 = new IntentDriftCheck($engine, $spec, $context);
        $findings1 = $check1->run();

        $check2 = new IntentDriftCheck($engine, $spec, $context);
        $findings2 = $check2->run();

        $json1 = json_encode(array_map(fn (Finding $f) => $f->toArray(), $findings1));
        $json2 = json_encode(array_map(fn (Finding $f) => $f->toArray(), $findings2));

        $this->assertSame($json1, $json2);
    }
}
