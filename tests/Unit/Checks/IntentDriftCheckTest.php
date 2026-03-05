<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Checks;

use IntentPHP\Guard\Checks\Intent\IntentDriftCheck;
use IntentPHP\Guard\Intent\Auth\AuthRequirement;
use IntentPHP\Guard\Intent\Auth\AuthRule;
use IntentPHP\Guard\Intent\Auth\AuthSpec;
use IntentPHP\Guard\Intent\Baseline\BaselineSpec;
use IntentPHP\Guard\Intent\Data\DataSpec;
use IntentPHP\Guard\Intent\Data\ModelSpec;
use IntentPHP\Guard\Intent\Defaults;
use IntentPHP\Guard\Intent\Drift\Context\ObservedModel;
use IntentPHP\Guard\Intent\Drift\Context\ObservedRoute;
use IntentPHP\Guard\Intent\Drift\Context\ProjectContext;
use IntentPHP\Guard\Intent\Drift\Detectors\AuthDriftDetector;
use IntentPHP\Guard\Intent\Drift\Detectors\MassAssignmentDriftDetector;
use IntentPHP\Guard\Intent\Drift\DriftEngine;
use IntentPHP\Guard\Intent\IntentSpec;
use IntentPHP\Guard\Intent\ProjectMeta;
use IntentPHP\Guard\Intent\Selector\RouteSelector;
use PHPUnit\Framework\TestCase;

class IntentDriftCheckTest extends TestCase
{
    private function makeSpec(array $rules = [], array $models = []): IntentSpec
    {
        return new IntentSpec(
            version: '0.1',
            project: new ProjectMeta('test', 'laravel'),
            defaults: new Defaults(),
            auth: new AuthSpec(rules: $rules),
            data: new DataSpec(models: $models),
            baseline: BaselineSpec::empty(),
        );
    }

    public function test_converts_drift_items_to_findings(): void
    {
        $engine = new DriftEngine([
            new AuthDriftDetector(),
            new MassAssignmentDriftDetector(),
        ]);

        $spec = $this->makeSpec(
            rules: [
                new AuthRule(
                    id: 'auth-orders',
                    match: new RouteSelector(prefix: '/orders'),
                    require: new AuthRequirement(authenticated: true),
                ),
            ],
            models: [
                'App\\Models\\User' => new ModelSpec(
                    fqcn: 'App\\Models\\User',
                    massAssignmentMode: 'explicit_allowlist',
                ),
            ],
        );

        $context = new ProjectContext(
            routes: [new ObservedRoute('/orders', 'orders.index', ['GET'], [], 'Closure')],
            models: [new ObservedModel('App\\Models\\User', '/app/Models/User.php', false, true, [], false)],
        );

        $check = new IntentDriftCheck($engine, $spec, $context);
        $findings = $check->run();

        $this->assertCount(2, $findings);
        $this->assertSame('intent-drift/auth', $findings[0]->check);
        $this->assertSame('intent-drift/mass-assignment', $findings[1]->check);
        $this->assertSame('high', $findings[0]->severity);
        $this->assertNull($findings[0]->line);
        $this->assertNull($findings[0]->file);
        $this->assertNotNull($findings[1]->file);
    }

    public function test_check_name(): void
    {
        $engine = new DriftEngine([]);
        $spec = $this->makeSpec();
        $context = new ProjectContext([], []);

        $check = new IntentDriftCheck($engine, $spec, $context);
        $this->assertSame('intent-drift', $check->name());
    }

    public function test_fingerprints_stable(): void
    {
        $engine = new DriftEngine([
            new AuthDriftDetector(),
        ]);

        $spec = $this->makeSpec(rules: [
            new AuthRule(
                id: 'rule-1',
                match: new RouteSelector(prefix: '/orders'),
                require: new AuthRequirement(authenticated: true),
            ),
        ]);

        $context = new ProjectContext(
            routes: [new ObservedRoute('/orders', 'orders.index', ['GET'], [], 'Closure')],
            models: [],
        );

        $check = new IntentDriftCheck($engine, $spec, $context);
        $findings1 = $check->run();
        $findings2 = $check->run();

        $this->assertCount(1, $findings1);
        $this->assertCount(1, $findings2);
        $this->assertSame($findings1[0]->fingerprint(), $findings2[0]->fingerprint());
        $this->assertNotEmpty($findings1[0]->fingerprint());
    }

    public function test_empty_spec_returns_no_findings(): void
    {
        $engine = new DriftEngine([
            new AuthDriftDetector(),
            new MassAssignmentDriftDetector(),
        ]);

        $spec = $this->makeSpec();
        $context = new ProjectContext(
            routes: [new ObservedRoute('/orders', '', ['GET'], [], 'Closure')],
            models: [],
        );

        $check = new IntentDriftCheck($engine, $spec, $context);
        $findings = $check->run();
        $this->assertSame([], $findings);
    }
}
