<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Intent\Sync;

use IntentPHP\Guard\Intent\Sync\Suggestion;
use PHPUnit\Framework\TestCase;

class SuggestionTest extends TestCase
{
    public function test_sort_key_format(): void
    {
        $suggestion = new Suggestion(
            id: 'code_to_spec:add_auth_rule:uri:/admin|GET',
            direction: 'code_to_spec',
            actionType: 'add_auth_rule',
            targetId: 'uri:/admin|GET',
            mappingIds: ['route|uri:/admin|GET|observed_only||'],
            confidence: 'medium',
            rationale: 'test',
            patch: [],
            context: [],
        );

        $this->assertSame('code_to_spec|add_auth_rule|uri:/admin|GET', $suggestion->sortKey());
    }

    public function test_to_array_includes_all_fields(): void
    {
        $suggestion = new Suggestion(
            id: 'spec_to_code:add_middleware:name:orders.index|GET',
            direction: 'spec_to_code',
            actionType: 'add_middleware',
            targetId: 'name:orders.index|GET',
            mappingIds: ['route|name:orders.index|GET|spec_linked|auth_rule|require-auth'],
            confidence: 'high',
            rationale: 'Needs auth.',
            patch: ['action_type' => 'add_middleware', 'middleware' => ['auth']],
            context: ['uri' => '/orders', 'methods' => ['GET']],
        );

        $array = $suggestion->toArray();

        $this->assertSame('spec_to_code:add_middleware:name:orders.index|GET', $array['id']);
        $this->assertSame('spec_to_code', $array['direction']);
        $this->assertSame('add_middleware', $array['action_type']);
        $this->assertSame('name:orders.index|GET', $array['target_id']);
        $this->assertSame(['route|name:orders.index|GET|spec_linked|auth_rule|require-auth'], $array['mapping_ids']);
        $this->assertSame('high', $array['confidence']);
        $this->assertSame('Needs auth.', $array['rationale']);
        $this->assertSame(['action_type' => 'add_middleware', 'middleware' => ['auth']], $array['patch']);
        $this->assertSame(['uri' => '/orders', 'methods' => ['GET']], $array['context']);
    }

    public function test_to_array_mapping_ids_null(): void
    {
        $suggestion = new Suggestion(
            id: 'spec_to_code:add_middleware:uri:/test|GET',
            direction: 'spec_to_code',
            actionType: 'add_middleware',
            targetId: 'uri:/test|GET',
            mappingIds: null,
            confidence: 'high',
            rationale: 'test',
            patch: [],
            context: [],
        );

        $this->assertNull($suggestion->toArray()['mapping_ids']);
    }

    public function test_sort_key_deterministic(): void
    {
        $s1 = new Suggestion(
            id: 'code_to_spec:add_auth_rule:uri:/a|GET',
            direction: 'code_to_spec',
            actionType: 'add_auth_rule',
            targetId: 'uri:/a|GET',
            mappingIds: null,
            confidence: 'medium',
            rationale: 'test',
            patch: [],
            context: [],
        );

        $s2 = new Suggestion(
            id: 'code_to_spec:add_auth_rule:uri:/a|GET',
            direction: 'code_to_spec',
            actionType: 'add_auth_rule',
            targetId: 'uri:/a|GET',
            mappingIds: null,
            confidence: 'medium',
            rationale: 'test',
            patch: [],
            context: [],
        );

        $this->assertSame($s1->sortKey(), $s2->sortKey());
    }
}
