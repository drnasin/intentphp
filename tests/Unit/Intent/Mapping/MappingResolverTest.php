<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Intent\Mapping;

use IntentPHP\Guard\Intent\Mapping\MappingEntry;
use IntentPHP\Guard\Intent\Mapping\MappingIndex;
use IntentPHP\Guard\Intent\Mapping\MappingResolver;
use PHPUnit\Framework\TestCase;

class MappingResolverTest extends TestCase
{
    private function makeResolver(): MappingResolver
    {
        $entries = [
            new MappingEntry(
                linkType: MappingEntry::LINK_SPEC_LINKED,
                specType: 'auth_rule',
                specId: 'rule-orders',
                targetType: 'route',
                targetId: 'name:orders.index|GET',
                targetDetail: ['uri' => '/orders', 'route_name' => 'orders.index', 'methods' => ['GET'], 'action' => 'OrderController@index'],
            ),
            new MappingEntry(
                linkType: MappingEntry::LINK_SPEC_LINKED,
                specType: 'model_spec',
                specId: 'App\\Models\\Order',
                targetType: 'model',
                targetId: 'App\\Models\\Order',
                targetDetail: ['fqcn' => 'App\\Models\\Order', 'has_fillable' => true, 'fillable_attrs' => ['name'], 'guarded_is_empty' => false],
            ),
            new MappingEntry(
                linkType: MappingEntry::LINK_OBSERVED_ONLY,
                specType: null,
                specId: null,
                targetType: 'route',
                targetId: 'name:dashboard|GET',
                targetDetail: ['uri' => '/dashboard', 'route_name' => 'dashboard', 'methods' => ['GET'], 'action' => 'DashboardController@index'],
            ),
            new MappingEntry(
                linkType: MappingEntry::LINK_OBSERVED_ONLY,
                specType: null,
                specId: null,
                targetType: 'model',
                targetId: 'App\\Models\\User',
                targetDetail: ['fqcn' => 'App\\Models\\User', 'has_fillable' => true, 'fillable_attrs' => ['name'], 'guarded_is_empty' => false],
            ),
        ];

        return new MappingResolver(new MappingIndex($entries));
    }

    public function test_by_rule_id(): void
    {
        $resolver = $this->makeResolver();
        $entries = $resolver->byRuleId('rule-orders');

        $this->assertCount(1, $entries);
        $this->assertSame('name:orders.index|GET', $entries[0]->targetId);
    }

    public function test_by_model_fqcn(): void
    {
        $resolver = $this->makeResolver();
        $entries = $resolver->byModelFqcn('App\\Models\\Order');

        $this->assertCount(1, $entries);
        $this->assertSame('model_spec', $entries[0]->specType);
    }

    public function test_by_route_id(): void
    {
        $resolver = $this->makeResolver();
        $entries = $resolver->byRouteId('name:dashboard|GET');

        $this->assertCount(1, $entries);
        $this->assertTrue($entries[0]->isObservedOnly());
    }

    public function test_observed_only(): void
    {
        $resolver = $this->makeResolver();
        $entries = $resolver->observedOnly();

        $this->assertCount(2, $entries);

        foreach ($entries as $entry) {
            $this->assertTrue($entry->isObservedOnly());
            $this->assertNull($entry->specType);
            $this->assertNull($entry->specId);
        }
    }

    public function test_spec_linked(): void
    {
        $resolver = $this->makeResolver();
        $entries = $resolver->specLinked();

        $this->assertCount(2, $entries);

        foreach ($entries as $entry) {
            $this->assertTrue($entry->isSpecLinked());
            $this->assertNotNull($entry->specType);
            $this->assertNotNull($entry->specId);
        }
    }

    public function test_has_spec_link_true(): void
    {
        $resolver = $this->makeResolver();
        $this->assertTrue($resolver->hasSpecLink('name:orders.index|GET'));
        $this->assertTrue($resolver->hasSpecLink('App\\Models\\Order'));
    }

    public function test_has_spec_link_false(): void
    {
        $resolver = $this->makeResolver();
        $this->assertFalse($resolver->hasSpecLink('name:dashboard|GET'));
        $this->assertFalse($resolver->hasSpecLink('App\\Models\\User'));
    }

    public function test_unknown_id_returns_empty(): void
    {
        $resolver = $this->makeResolver();
        $this->assertSame([], $resolver->byRuleId('nonexistent'));
        $this->assertSame([], $resolver->byModelFqcn('App\\Models\\Nope'));
        $this->assertSame([], $resolver->byRouteId('name:nope|GET'));
    }

    public function test_all_returns_sorted_entries(): void
    {
        $resolver = $this->makeResolver();
        $all = $resolver->all();

        $this->assertCount(4, $all);

        // Verify sorted by sortKey
        for ($i = 1; $i < count($all); $i++) {
            $this->assertLessThanOrEqual(0, strcmp($all[$i - 1]->sortKey(), $all[$i]->sortKey()));
        }
    }
}
