<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Intent\Mapping;

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
use IntentPHP\Guard\Intent\IntentSpec;
use IntentPHP\Guard\Intent\Mapping\MappingBuilder;
use IntentPHP\Guard\Intent\Mapping\MappingEntry;
use IntentPHP\Guard\Intent\ProjectMeta;
use IntentPHP\Guard\Intent\Selector\RouteSelector;
use PHPUnit\Framework\TestCase;

class MappingBuilderTest extends TestCase
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

    public function test_auth_rule_linked_to_matching_routes(): void
    {
        $spec = $this->makeSpec(rules: [
            new AuthRule(
                id: 'auth-orders',
                match: new RouteSelector(prefix: '/orders'),
                require: new AuthRequirement(authenticated: true),
            ),
        ]);

        $context = new ProjectContext(
            routes: [
                new ObservedRoute('/orders', 'orders.index', ['GET'], ['auth'], 'OrderController@index'),
                new ObservedRoute('/dashboard', 'dashboard', ['GET'], [], 'DashboardController@index'),
            ],
            models: [],
        );

        $builder = new MappingBuilder();
        $index = $builder->build($spec, $context);

        $linked = array_filter($index->entries, fn (MappingEntry $e) => $e->isSpecLinked());
        $this->assertCount(1, $linked);

        $entry = array_values($linked)[0];
        $this->assertSame('auth_rule', $entry->specType);
        $this->assertSame('auth-orders', $entry->specId);
        $this->assertSame('route', $entry->targetType);
        $this->assertSame('name:orders.index|GET', $entry->targetId);
    }

    public function test_model_spec_linked_to_observed_model(): void
    {
        $spec = $this->makeSpec(models: [
            'App\\Models\\Order' => new ModelSpec(
                fqcn: 'App\\Models\\Order',
                massAssignmentMode: 'explicit_allowlist',
            ),
        ]);

        $context = new ProjectContext(
            routes: [],
            models: [
                new ObservedModel('App\\Models\\Order', '/app/Models/Order.php', true, true, ['name'], false),
            ],
        );

        $builder = new MappingBuilder();
        $index = $builder->build($spec, $context);

        $linked = array_filter($index->entries, fn (MappingEntry $e) => $e->isSpecLinked());
        $this->assertCount(1, $linked);

        $entry = array_values($linked)[0];
        $this->assertSame('model_spec', $entry->specType);
        $this->assertSame('App\\Models\\Order', $entry->specId);
        $this->assertSame('model', $entry->targetType);
        $this->assertSame('App\\Models\\Order', $entry->targetId);
    }

    public function test_unmatched_route_is_observed_only(): void
    {
        $spec = $this->makeSpec(rules: [
            new AuthRule(
                id: 'auth-orders',
                match: new RouteSelector(prefix: '/orders'),
                require: new AuthRequirement(authenticated: true),
            ),
        ]);

        $context = new ProjectContext(
            routes: [
                new ObservedRoute('/dashboard', 'dashboard', ['GET'], [], 'DashboardController@index'),
            ],
            models: [],
        );

        $builder = new MappingBuilder();
        $index = $builder->build($spec, $context);

        $this->assertCount(1, $index->entries);
        $this->assertTrue($index->entries[0]->isObservedOnly());
        $this->assertSame('route', $index->entries[0]->targetType);
        $this->assertSame('name:dashboard|GET', $index->entries[0]->targetId);
    }

    public function test_unmatched_model_is_observed_only(): void
    {
        $spec = $this->makeSpec();

        $context = new ProjectContext(
            routes: [],
            models: [
                new ObservedModel('App\\Models\\User', '/app/Models/User.php', true, true, ['name'], false),
            ],
        );

        $builder = new MappingBuilder();
        $index = $builder->build($spec, $context);

        $this->assertCount(1, $index->entries);
        $this->assertTrue($index->entries[0]->isObservedOnly());
        $this->assertSame('model', $index->entries[0]->targetType);
        $this->assertSame('App\\Models\\User', $index->entries[0]->targetId);
    }

    public function test_null_spec_produces_routes_only(): void
    {
        $context = new ProjectContext(
            routes: [
                new ObservedRoute('/orders', 'orders.index', ['GET'], [], 'OrderController@index'),
            ],
            models: [
                new ObservedModel('App\\Models\\User', '/app/Models/User.php', true, true, ['name', 'email'], false),
                new ObservedModel('App\\Models\\Post', '/app/Models/Post.php', false, true, [], true),
            ],
        );

        $builder = new MappingBuilder();
        $index = $builder->build(null, $context);

        // Models in context must be ignored when spec is null
        $this->assertCount(1, $index->entries);
        $this->assertTrue($index->entries[0]->isObservedOnly());
        $this->assertSame('route', $index->entries[0]->targetType);
        $this->assertSame('name:orders.index|GET', $index->entries[0]->targetId);

        $modelEntries = array_filter($index->entries, fn ($e) => $e->targetType === 'model');
        $this->assertCount(0, $modelEntries);
    }

    public function test_empty_spec_produces_observed_only(): void
    {
        $spec = $this->makeSpec();

        $context = new ProjectContext(
            routes: [
                new ObservedRoute('/test', '', ['GET'], [], 'Closure'),
            ],
            models: [],
        );

        $builder = new MappingBuilder();
        $index = $builder->build($spec, $context);

        $this->assertCount(1, $index->entries);
        $this->assertTrue($index->entries[0]->isObservedOnly());
    }

    public function test_deterministic_ordering(): void
    {
        $spec = $this->makeSpec(rules: [
            new AuthRule(
                id: 'rule-b',
                match: new RouteSelector(prefix: '/b'),
                require: new AuthRequirement(authenticated: true),
            ),
            new AuthRule(
                id: 'rule-a',
                match: new RouteSelector(prefix: '/a'),
                require: new AuthRequirement(authenticated: true),
            ),
        ]);

        $context = new ProjectContext(
            routes: [
                new ObservedRoute('/b/items', '', ['GET'], [], 'Closure'),
                new ObservedRoute('/a/items', '', ['POST'], [], 'Closure'),
                new ObservedRoute('/c/items', '', ['GET'], [], 'Closure'),
            ],
            models: [],
        );

        $builder = new MappingBuilder();
        $index1 = $builder->build($spec, $context);
        $index2 = $builder->build($spec, $context);

        $this->assertSame($index1->toJson(), $index2->toJson());
    }

    public function test_checksum_stable_across_calls(): void
    {
        $spec = $this->makeSpec(rules: [
            new AuthRule(
                id: 'auth-x',
                match: new RouteSelector(prefix: '/x'),
                require: new AuthRequirement(authenticated: true),
            ),
        ]);

        $context = new ProjectContext(
            routes: [
                new ObservedRoute('/x/test', 'x.test', ['GET'], [], 'Closure'),
            ],
            models: [],
        );

        $builder = new MappingBuilder();
        $this->assertSame(
            $builder->build($spec, $context)->checksum,
            $builder->build($spec, $context)->checksum,
        );
    }

    public function test_checksum_changes_on_different_input(): void
    {
        $spec = $this->makeSpec();

        $context1 = new ProjectContext(
            routes: [new ObservedRoute('/a', '', ['GET'], [], 'Closure')],
            models: [],
        );

        $context2 = new ProjectContext(
            routes: [new ObservedRoute('/b', '', ['GET'], [], 'Closure')],
            models: [],
        );

        $builder = new MappingBuilder();
        $this->assertNotSame(
            $builder->build($spec, $context1)->checksum,
            $builder->build($spec, $context2)->checksum,
        );
    }
}
