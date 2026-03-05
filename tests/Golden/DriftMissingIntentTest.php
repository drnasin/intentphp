<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Golden;

use IntentPHP\Guard\Checks\Intent\IntentDriftCheck;
use IntentPHP\Guard\Intent\Auth\AuthSpec;
use IntentPHP\Guard\Intent\Baseline\BaselineSpec;
use IntentPHP\Guard\Intent\Data\DataSpec;
use IntentPHP\Guard\Intent\Defaults;
use IntentPHP\Guard\Intent\Drift\Context\ObservedModel;
use IntentPHP\Guard\Intent\Drift\Context\ObservedRoute;
use IntentPHP\Guard\Intent\Drift\Context\ProjectContext;
use IntentPHP\Guard\Intent\Drift\Detectors\AuthDriftDetector;
use IntentPHP\Guard\Intent\Drift\Detectors\MassAssignmentDriftDetector;
use IntentPHP\Guard\Intent\Drift\DriftEngine;
use IntentPHP\Guard\Intent\IntentSpec;
use IntentPHP\Guard\Intent\ProjectMeta;
use PHPUnit\Framework\TestCase;

class DriftMissingIntentTest extends TestCase
{
    /**
     * When the intent spec has no auth rules and no data models,
     * drift detectors must return zero items — even with observed routes/models.
     * This proves additive behavior: missing intent sections → no drift checks.
     */
    public function test_empty_spec_with_observed_data_produces_zero_drift(): void
    {
        $spec = new IntentSpec(
            version: '0.1',
            project: new ProjectMeta('test', 'laravel'),
            defaults: new Defaults(),
            auth: AuthSpec::empty(),
            data: DataSpec::empty(),
            baseline: BaselineSpec::empty(),
        );

        $context = new ProjectContext(
            routes: [
                new ObservedRoute('/orders', 'orders.index', ['GET'], [], 'OrderController@index'),
                new ObservedRoute('/api/items', '', ['GET', 'POST'], ['auth'], 'ItemController@index'),
            ],
            models: [
                new ObservedModel('App\\Models\\User', '/app/Models/User.php', true, true, ['name'], false),
                new ObservedModel('App\\Models\\Post', '/app/Models/Post.php', false, true, [], true),
            ],
        );

        $engine = new DriftEngine([
            new AuthDriftDetector(),
            new MassAssignmentDriftDetector(),
        ]);

        $check = new IntentDriftCheck($engine, $spec, $context);
        $findings = $check->run();

        $this->assertSame([], $findings);
    }

    /**
     * Verify determinism: same empty spec → same empty output.
     */
    public function test_determinism_empty_spec(): void
    {
        $spec = new IntentSpec(
            version: '0.1',
            project: new ProjectMeta('test', 'laravel'),
            defaults: new Defaults(),
            auth: AuthSpec::empty(),
            data: DataSpec::empty(),
            baseline: BaselineSpec::empty(),
        );

        $context = new ProjectContext(
            routes: [new ObservedRoute('/test', '', ['GET'], [], 'Closure')],
            models: [],
        );

        $engine = new DriftEngine([
            new AuthDriftDetector(),
            new MassAssignmentDriftDetector(),
        ]);

        $check1 = new IntentDriftCheck($engine, $spec, $context);
        $check2 = new IntentDriftCheck($engine, $spec, $context);

        $this->assertSame([], $check1->run());
        $this->assertSame([], $check2->run());
    }
}
