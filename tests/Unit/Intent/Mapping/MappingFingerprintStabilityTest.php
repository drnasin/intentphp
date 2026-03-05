<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Intent\Mapping;

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
use IntentPHP\Guard\Intent\Mapping\MappingBuilder;
use IntentPHP\Guard\Intent\Mapping\MappingResolver;
use IntentPHP\Guard\Intent\ProjectMeta;
use IntentPHP\Guard\Intent\Selector\RouteSelector;
use IntentPHP\Guard\Scan\Finding;
use PHPUnit\Framework\TestCase;

/**
 * Proves that drift fingerprints are identical with and without MappingResolver enrichment.
 * The mapping_ids context key must NOT affect fingerprints.
 */
class MappingFingerprintStabilityTest extends TestCase
{
    public function test_auth_drift_fingerprints_identical_with_and_without_mapping(): void
    {
        $spec = new IntentSpec(
            version: '0.1',
            project: new ProjectMeta('test', 'laravel'),
            defaults: new Defaults(),
            auth: new AuthSpec(rules: [
                new AuthRule(
                    id: 'rule-orders',
                    match: new RouteSelector(prefix: '/orders'),
                    require: new AuthRequirement(authenticated: true),
                ),
            ]),
            data: DataSpec::empty(),
            baseline: BaselineSpec::empty(),
        );

        $context = new ProjectContext(
            routes: [new ObservedRoute('/orders', 'orders.index', ['GET'], [], 'OrderController@index')],
            models: [],
        );

        // Without mapping
        $engineNoMapping = new DriftEngine([new AuthDriftDetector()]);
        $checkNoMapping = new IntentDriftCheck($engineNoMapping, $spec, $context);
        $findingsNoMapping = $checkNoMapping->run();

        // With mapping
        $builder = new MappingBuilder();
        $index = $builder->build($spec, $context);
        $resolver = new MappingResolver($index);

        $engineWithMapping = new DriftEngine([new AuthDriftDetector()], $resolver);
        $checkWithMapping = new IntentDriftCheck($engineWithMapping, $spec, $context);
        $findingsWithMapping = $checkWithMapping->run();

        // Same number of findings
        $this->assertCount(1, $findingsNoMapping);
        $this->assertCount(1, $findingsWithMapping);

        // Fingerprints must be identical
        $this->assertSame(
            $findingsNoMapping[0]->fingerprint(),
            $findingsWithMapping[0]->fingerprint(),
            'Fingerprints must be identical regardless of mapping enrichment',
        );

        // Verify that with-mapping version actually has mapping_ids
        $this->assertArrayNotHasKey('mapping_ids', $findingsNoMapping[0]->context);
        $this->assertArrayHasKey('mapping_ids', $findingsWithMapping[0]->context);
    }

    public function test_mass_assignment_drift_fingerprints_identical_with_and_without_mapping(): void
    {
        $spec = new IntentSpec(
            version: '0.1',
            project: new ProjectMeta('test', 'laravel'),
            defaults: new Defaults(),
            auth: AuthSpec::empty(),
            data: new DataSpec(models: [
                'App\\Models\\User' => new ModelSpec(
                    fqcn: 'App\\Models\\User',
                    massAssignmentMode: 'explicit_allowlist',
                ),
            ]),
            baseline: BaselineSpec::empty(),
        );

        $context = new ProjectContext(
            routes: [],
            models: [
                new ObservedModel('App\\Models\\User', '/app/Models/User.php', false, true, [], false),
            ],
        );

        // Without mapping
        $engineNoMapping = new DriftEngine([new MassAssignmentDriftDetector()]);
        $checkNoMapping = new IntentDriftCheck($engineNoMapping, $spec, $context);
        $findingsNoMapping = $checkNoMapping->run();

        // With mapping
        $builder = new MappingBuilder();
        $index = $builder->build($spec, $context);
        $resolver = new MappingResolver($index);

        $engineWithMapping = new DriftEngine([new MassAssignmentDriftDetector()], $resolver);
        $checkWithMapping = new IntentDriftCheck($engineWithMapping, $spec, $context);
        $findingsWithMapping = $checkWithMapping->run();

        $this->assertCount(1, $findingsNoMapping);
        $this->assertCount(1, $findingsWithMapping);

        $this->assertSame(
            $findingsNoMapping[0]->fingerprint(),
            $findingsWithMapping[0]->fingerprint(),
            'Mass-assignment drift fingerprints must be identical regardless of mapping enrichment',
        );

        $this->assertArrayNotHasKey('mapping_ids', $findingsNoMapping[0]->context);
        $this->assertArrayHasKey('mapping_ids', $findingsWithMapping[0]->context);
    }
}
