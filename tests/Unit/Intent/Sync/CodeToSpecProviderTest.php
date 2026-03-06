<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Intent\Sync;

use IntentPHP\Guard\Intent\Mapping\MappingEntry;
use IntentPHP\Guard\Intent\Mapping\MappingIndex;
use IntentPHP\Guard\Intent\Mapping\MappingResolver;
use IntentPHP\Guard\Intent\Sync\Providers\CodeToSpecProvider;
use PHPUnit\Framework\TestCase;

class CodeToSpecProviderTest extends TestCase
{
    public function test_observed_only_routes_produce_suggestions(): void
    {
        $entries = [
            new MappingEntry(
                linkType: MappingEntry::LINK_OBSERVED_ONLY,
                specType: null,
                specId: null,
                targetType: 'route',
                targetId: 'uri:/admin|GET',
                targetDetail: ['uri' => '/admin', 'methods' => ['GET'], 'middleware' => [], 'action' => 'AdminController@index'],
            ),
            new MappingEntry(
                linkType: MappingEntry::LINK_OBSERVED_ONLY,
                specType: null,
                specId: null,
                targetType: 'route',
                targetId: 'uri:/dashboard|GET',
                targetDetail: ['uri' => '/dashboard', 'methods' => ['GET'], 'middleware' => ['web'], 'action' => 'DashController@index'],
            ),
        ];

        $index = new MappingIndex($entries);
        $resolver = new MappingResolver($index);
        $provider = new CodeToSpecProvider($resolver);

        $suggestions = $provider->provide();

        $this->assertCount(2, $suggestions);

        // Sorted by MappingIndex (entries already sorted by sortKey)
        $this->assertSame('code_to_spec', $suggestions[0]->direction);
        $this->assertSame('add_auth_rule', $suggestions[0]->actionType);
        $this->assertSame('medium', $suggestions[0]->confidence);
        $this->assertNotNull($suggestions[0]->mappingIds);
        $this->assertCount(1, $suggestions[0]->mappingIds);
        $this->assertSame($entries[0]->sortKey(), $suggestions[0]->mappingIds[0]);

        // Patch shape
        $patch = $suggestions[0]->patch;
        $this->assertSame('add_auth_rule', $patch['action_type']);
        $this->assertSame('auth.rules', $patch['spec_section']);
        $this->assertArrayHasKey('proposed_rule', $patch);
        $this->assertArrayHasKey('instructions', $patch);
        $this->assertSame('/admin', $patch['proposed_rule']['match']['routes']['prefix']);
    }

    public function test_spec_linked_routes_produce_no_suggestions(): void
    {
        $entries = [
            new MappingEntry(
                linkType: MappingEntry::LINK_SPEC_LINKED,
                specType: 'auth_rule',
                specId: 'require-auth',
                targetType: 'route',
                targetId: 'name:orders.index|GET',
                targetDetail: ['uri' => '/orders', 'methods' => ['GET'], 'middleware' => ['auth'], 'action' => 'OrderController@index'],
            ),
        ];

        $index = new MappingIndex($entries);
        $resolver = new MappingResolver($index);
        $provider = new CodeToSpecProvider($resolver);

        $this->assertSame([], $provider->provide());
    }

    public function test_observed_only_models_are_skipped(): void
    {
        $entries = [
            new MappingEntry(
                linkType: MappingEntry::LINK_OBSERVED_ONLY,
                specType: null,
                specId: null,
                targetType: 'model',
                targetId: 'App\\Models\\User',
                targetDetail: ['fqcn' => 'App\\Models\\User'],
            ),
        ];

        $index = new MappingIndex($entries);
        $resolver = new MappingResolver($index);
        $provider = new CodeToSpecProvider($resolver);

        $this->assertSame([], $provider->provide());
    }

    public function test_deterministic_ordering(): void
    {
        $entries = [
            new MappingEntry(
                linkType: MappingEntry::LINK_OBSERVED_ONLY,
                specType: null,
                specId: null,
                targetType: 'route',
                targetId: 'uri:/zebra|GET',
                targetDetail: ['uri' => '/zebra', 'methods' => ['GET'], 'middleware' => [], 'action' => 'Closure'],
            ),
            new MappingEntry(
                linkType: MappingEntry::LINK_OBSERVED_ONLY,
                specType: null,
                specId: null,
                targetType: 'route',
                targetId: 'uri:/alpha|GET',
                targetDetail: ['uri' => '/alpha', 'methods' => ['GET'], 'middleware' => [], 'action' => 'Closure'],
            ),
        ];

        $index = new MappingIndex($entries);
        $resolver = new MappingResolver($index);
        $provider = new CodeToSpecProvider($resolver);

        $suggestions = $provider->provide();

        $this->assertCount(2, $suggestions);
        // MappingIndex sorts entries, so /alpha comes first
        $this->assertStringContainsString('/alpha', $suggestions[0]->targetId);
        $this->assertStringContainsString('/zebra', $suggestions[1]->targetId);
    }

    public function test_mapping_ids_match_entry_sort_key(): void
    {
        $entry = new MappingEntry(
            linkType: MappingEntry::LINK_OBSERVED_ONLY,
            specType: null,
            specId: null,
            targetType: 'route',
            targetId: 'uri:/test|POST',
            targetDetail: ['uri' => '/test', 'methods' => ['POST'], 'middleware' => [], 'action' => 'Closure'],
        );

        $index = new MappingIndex([$entry]);
        $resolver = new MappingResolver($index);
        $provider = new CodeToSpecProvider($resolver);

        $suggestions = $provider->provide();

        $this->assertCount(1, $suggestions);
        $this->assertSame([$entry->sortKey()], $suggestions[0]->mappingIds);
        // Verify it matches the format: target_type|target_id|link_type|spec_type|spec_id
        $this->assertSame('route|uri:/test|POST|observed_only||', $entry->sortKey());
    }
}
