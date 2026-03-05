<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Intent\Drift;

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
use PHPUnit\Framework\TestCase;

class DriftEngineTest extends TestCase
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

    public function test_runs_all_detectors_and_sorts(): void
    {
        $engine = new DriftEngine([
            new AuthDriftDetector(),
            new MassAssignmentDriftDetector(),
        ]);

        $spec = $this->makeSpec(
            rules: [
                new AuthRule(
                    id: 'rule-1',
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
            routes: [
                new ObservedRoute('/orders', 'orders.index', ['GET'], [], 'Closure'),
            ],
            models: [
                new ObservedModel('App\\Models\\User', '/app/Models/User.php', false, true, [], false),
            ],
        );

        $items = $engine->detect($spec, $context);

        $this->assertCount(2, $items);
        // Sorted by detector|targetId|driftType: "auth|..." < "mass-assignment|..."
        $this->assertSame('auth', $items[0]->detector);
        $this->assertSame('mass-assignment', $items[1]->detector);
    }

    public function test_empty_spec_returns_empty(): void
    {
        $engine = new DriftEngine([
            new AuthDriftDetector(),
            new MassAssignmentDriftDetector(),
        ]);

        $spec = $this->makeSpec();
        $context = new ProjectContext(
            routes: [
                new ObservedRoute('/orders', '', ['GET'], [], 'Closure'),
            ],
            models: [],
        );

        $items = $engine->detect($spec, $context);
        $this->assertSame([], $items);
    }

    public function test_ordering_stable_across_runs(): void
    {
        $engine = new DriftEngine([
            new AuthDriftDetector(),
            new MassAssignmentDriftDetector(),
        ]);

        $spec = $this->makeSpec(
            rules: [
                new AuthRule(
                    id: 'rule-1',
                    match: new RouteSelector(prefix: '/'),
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
            routes: [
                new ObservedRoute('/beta', '', ['GET'], [], 'Closure'),
                new ObservedRoute('/alpha', '', ['GET'], [], 'Closure'),
            ],
            models: [
                new ObservedModel('App\\Models\\User', '/app/Models/User.php', false, true, [], false),
            ],
        );

        $items1 = $engine->detect($spec, $context);
        $items2 = $engine->detect($spec, $context);

        $this->assertCount(3, $items1);

        $keys1 = array_map(fn ($i) => $i->sortKey(), $items1);
        $keys2 = array_map(fn ($i) => $i->sortKey(), $items2);

        $this->assertSame($keys1, $keys2);
    }

    public function test_detect_without_mapping_unchanged(): void
    {
        $engine = new DriftEngine([new AuthDriftDetector()]);

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

        $items = $engine->detect($spec, $context);

        $this->assertCount(1, $items);
        $this->assertArrayNotHasKey('mapping_ids', $items[0]->context);
    }

    public function test_detect_with_mapping_adds_mapping_ids(): void
    {
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

        $builder = new MappingBuilder();
        $index = $builder->build($spec, $context);
        $resolver = new MappingResolver($index);

        $engine = new DriftEngine([new AuthDriftDetector()], $resolver);
        $items = $engine->detect($spec, $context);

        $this->assertCount(1, $items);
        $this->assertArrayHasKey('mapping_ids', $items[0]->context);
        $this->assertNotEmpty($items[0]->context['mapping_ids']);
    }

    public function test_existing_constructor_no_mapping_works(): void
    {
        // Backward compat: construct with only detectors array (no second arg)
        $engine = new DriftEngine([new AuthDriftDetector()]);

        $spec = $this->makeSpec();
        $context = new ProjectContext([], []);

        $items = $engine->detect($spec, $context);
        $this->assertSame([], $items);
    }
}
