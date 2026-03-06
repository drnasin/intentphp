<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Golden;

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
use PHPUnit\Framework\TestCase;

class DriftFilamentAuthGoldenTest extends TestCase
{
    private function buildFixtures(): array
    {
        $spec = new IntentSpec(
            version: '0.1',
            project: new ProjectMeta('filament-test', 'laravel'),
            defaults: new Defaults(),
            auth: new AuthSpec(
                rules: [
                    new AuthRule(
                        id: 'require-auth-admin',
                        match: new RouteSelector(prefix: '/admin'),
                        require: new AuthRequirement(authenticated: true),
                    ),
                ],
            ),
            data: DataSpec::empty(),
            baseline: BaselineSpec::empty(),
        );

        $context = new ProjectContext(
            routes: [
                // Filament route: protected by FQCN middleware — should NOT drift
                new ObservedRoute(
                    '/admin/dashboard',
                    'filament.admin.pages.dashboard',
                    ['GET'],
                    [
                        'web',
                        'Filament\\Http\\Middleware\\Authenticate',
                        'Illuminate\\Auth\\Middleware\\Authenticate',
                    ],
                    'Filament\\Pages\\Dashboard',
                ),
                // Filament route: protected by FQCN middleware — should NOT drift
                new ObservedRoute(
                    '/admin/users',
                    'filament.admin.resources.users.index',
                    ['GET'],
                    [
                        'web',
                        'Filament\\Http\\Middleware\\Authenticate',
                    ],
                    'Filament\\Resources\\UserResource\\Pages\\ListUsers',
                ),
                // Unprotected admin route — SHOULD drift
                new ObservedRoute(
                    '/admin/public-api',
                    '',
                    ['GET'],
                    ['web'],
                    'App\\Http\\Controllers\\AdminApiController@index',
                ),
            ],
            models: [],
        );

        return [$spec, $context];
    }

    public function test_filament_routes_not_flagged_as_unprotected(): void
    {
        [$spec, $context] = $this->buildFixtures();

        $engine = new DriftEngine([new AuthDriftDetector(['auth', 'auth:sanctum', 'Filament\\Http\\Middleware\\Authenticate'])]);
        $items = $engine->detect($spec, $context);

        // Only the unprotected /admin/public-api should produce drift
        $this->assertCount(1, $items);
        $this->assertSame('missing_auth_middleware', $items[0]->driftType);
        $this->assertStringContainsString('/admin/public-api', $items[0]->message);
    }

    public function test_golden_output_deterministic(): void
    {
        [$spec, $context] = $this->buildFixtures();

        $run = function () use ($spec, $context): array {
            $engine = new DriftEngine([new AuthDriftDetector(['auth', 'auth:sanctum', 'Filament\\Http\\Middleware\\Authenticate'])]);
            $items = $engine->detect($spec, $context);

            return array_map(fn ($item) => [
                'detector' => $item->detector,
                'drift_type' => $item->driftType,
                'target_id' => $item->targetId,
                'severity' => $item->severity,
            ], $items);
        };

        $this->assertSame($run(), $run());
    }
}
