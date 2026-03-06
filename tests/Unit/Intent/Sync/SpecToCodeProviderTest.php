<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Intent\Sync;

use IntentPHP\Guard\Intent\Drift\DriftItem;
use IntentPHP\Guard\Intent\Sync\Providers\SpecToCodeProvider;
use PHPUnit\Framework\TestCase;

class SpecToCodeProviderTest extends TestCase
{
    public function test_missing_auth_middleware_produces_suggestion(): void
    {
        $drift = new DriftItem(
            detector: 'auth',
            driftType: 'missing_auth_middleware',
            targetId: 'name:orders.index|GET',
            severity: 'high',
            message: 'Route [GET] /orders requires authentication but has no auth middleware.',
            file: null,
            context: [
                'uri' => '/orders',
                'methods' => ['GET'],
                'middleware' => [],
                'mapping_ids' => ['route|name:orders.index|GET|spec_linked|auth_rule|require-auth-orders'],
            ],
            fixHint: 'Add auth middleware.',
        );

        $provider = new SpecToCodeProvider([$drift]);
        $suggestions = $provider->provide();

        $this->assertCount(1, $suggestions);
        $s = $suggestions[0];
        $this->assertSame('spec_to_code', $s->direction);
        $this->assertSame('add_middleware', $s->actionType);
        $this->assertSame('name:orders.index|GET', $s->targetId);
        $this->assertSame('high', $s->confidence);
        $this->assertSame(
            ['route|name:orders.index|GET|spec_linked|auth_rule|require-auth-orders'],
            $s->mappingIds,
        );

        // Patch shape
        $this->assertSame('add_middleware', $s->patch['action_type']);
        $this->assertSame(['auth'], $s->patch['middleware']);
        $this->assertSame('name:orders.index|GET', $s->patch['target_route_identifier']);
        $this->assertArrayHasKey('instructions', $s->patch);
    }

    public function test_missing_guard_middleware_produces_guard_specific_middleware(): void
    {
        $drift = new DriftItem(
            detector: 'auth',
            driftType: 'missing_guard_middleware',
            targetId: 'uri:/api/users|GET',
            severity: 'high',
            message: 'Route requires guard sanctum.',
            file: null,
            context: [
                'uri' => '/api/users',
                'methods' => ['GET'],
                'middleware' => ['auth'],
                'require' => ['guard' => 'sanctum'],
            ],
            fixHint: 'Add auth:sanctum.',
        );

        $provider = new SpecToCodeProvider([$drift]);
        $suggestions = $provider->provide();

        $this->assertCount(1, $suggestions);
        $this->assertSame(['auth:sanctum'], $suggestions[0]->patch['middleware']);
    }

    public function test_public_but_protected_drift_not_actionable(): void
    {
        $drift = new DriftItem(
            detector: 'auth',
            driftType: 'public_but_protected',
            targetId: 'uri:/health|GET',
            severity: 'medium',
            message: 'Public route has auth middleware.',
            file: null,
            context: [],
            fixHint: 'Remove auth middleware.',
        );

        $provider = new SpecToCodeProvider([$drift]);

        $this->assertSame([], $provider->provide());
    }

    public function test_mapping_ids_null_when_not_in_context(): void
    {
        $drift = new DriftItem(
            detector: 'auth',
            driftType: 'missing_auth_middleware',
            targetId: 'uri:/test|GET',
            severity: 'high',
            message: 'test',
            file: null,
            context: [
                'uri' => '/test',
                'methods' => ['GET'],
                'middleware' => [],
            ],
            fixHint: 'Add auth.',
        );

        $provider = new SpecToCodeProvider([$drift]);
        $suggestions = $provider->provide();

        $this->assertCount(1, $suggestions);
        $this->assertNull($suggestions[0]->mappingIds);
    }

    public function test_multiple_mapping_ids_sorted(): void
    {
        $drift = new DriftItem(
            detector: 'auth',
            driftType: 'missing_auth_middleware',
            targetId: 'uri:/test|GET',
            severity: 'high',
            message: 'test',
            file: null,
            context: [
                'uri' => '/test',
                'methods' => ['GET'],
                'middleware' => [],
                'mapping_ids' => [
                    'route|uri:/test|GET|spec_linked|auth_rule|z-rule',
                    'route|uri:/test|GET|spec_linked|auth_rule|a-rule',
                ],
            ],
            fixHint: 'Add auth.',
        );

        $provider = new SpecToCodeProvider([$drift]);
        $suggestions = $provider->provide();

        $this->assertSame(
            [
                'route|uri:/test|GET|spec_linked|auth_rule|a-rule',
                'route|uri:/test|GET|spec_linked|auth_rule|z-rule',
            ],
            $suggestions[0]->mappingIds,
        );
    }

    public function test_empty_drift_produces_nothing(): void
    {
        $provider = new SpecToCodeProvider([]);

        $this->assertSame([], $provider->provide());
    }
}
