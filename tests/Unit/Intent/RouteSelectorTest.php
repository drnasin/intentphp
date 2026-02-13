<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Intent;

use IntentPHP\Guard\Intent\Selector\RouteSelector;
use PHPUnit\Framework\TestCase;

class RouteSelectorTest extends TestCase
{
    // ── Name pattern (fnmatch) ───────────────────────────────────────

    public function test_name_pattern_matches(): void
    {
        $selector = new RouteSelector(name: 'admin.*');

        $this->assertTrue($selector->matches('admin.dashboard', '/admin/dashboard', 'GET'));
        $this->assertTrue($selector->matches('admin.users.index', '/admin/users', 'GET'));
    }

    public function test_name_pattern_rejects_non_match(): void
    {
        $selector = new RouteSelector(name: 'admin.*');

        $this->assertFalse($selector->matches('api.users.index', '/api/users', 'GET'));
    }

    // ── Prefix ───────────────────────────────────────────────────────

    public function test_prefix_matches(): void
    {
        $selector = new RouteSelector(prefix: '/admin');

        $this->assertTrue($selector->matches('admin.index', '/admin/dashboard', 'GET'));
        $this->assertTrue($selector->matches('admin.users', '/admin/users', 'POST'));
    }

    public function test_prefix_rejects_non_match(): void
    {
        $selector = new RouteSelector(prefix: '/admin');

        $this->assertFalse($selector->matches('api.users', '/api/users', 'GET'));
    }

    // ── URI exact match ──────────────────────────────────────────────

    public function test_uri_exact_match(): void
    {
        $selector = new RouteSelector(uri: '/health');

        $this->assertTrue($selector->matches('health', '/health', 'GET'));
        $this->assertFalse($selector->matches('health.detail', '/health/detail', 'GET'));
    }

    // ── Methods ──────────────────────────────────────────────────────

    public function test_methods_filter(): void
    {
        $selector = new RouteSelector(name: 'api.*', methods: ['POST', 'PUT']);

        $this->assertTrue($selector->matches('api.posts.store', '/api/posts', 'POST'));
        $this->assertFalse($selector->matches('api.posts.index', '/api/posts', 'GET'));
    }

    public function test_methods_case_insensitive(): void
    {
        $selector = new RouteSelector(name: '*', methods: ['GET']);

        $this->assertTrue($selector->matches('test', '/test', 'get'));
    }

    // ── Any (OR logic) ───────────────────────────────────────────────

    public function test_any_or_logic(): void
    {
        $selector = new RouteSelector(any: [
            new RouteSelector(name: 'admin.*'),
            new RouteSelector(prefix: '/api'),
        ]);

        $this->assertTrue($selector->matches('admin.index', '/admin', 'GET'));
        $this->assertTrue($selector->matches('api.users', '/api/users', 'GET'));
        $this->assertFalse($selector->matches('web.home', '/', 'GET'));
    }

    // ── Combined criteria (AND logic) ────────────────────────────────

    public function test_combined_criteria_are_and_logic(): void
    {
        $selector = new RouteSelector(prefix: '/api', methods: ['DELETE']);

        $this->assertTrue($selector->matches('api.delete', '/api/posts/1', 'DELETE'));
        $this->assertFalse($selector->matches('api.index', '/api/posts', 'GET'));
        $this->assertFalse($selector->matches('web.delete', '/web/delete', 'DELETE'));
    }

    // ── Empty selector ───────────────────────────────────────────────

    public function test_empty_selector_matches_nothing(): void
    {
        $selector = new RouteSelector();

        $this->assertTrue($selector->isEmpty());
        $this->assertFalse($selector->matches('any', '/any', 'GET'));
    }

    // ── fromArray ────────────────────────────────────────────────────

    public function test_from_array(): void
    {
        $selector = RouteSelector::fromArray([
            'routes' => [
                'name' => 'posts.*',
                'methods' => ['get', 'post'],
            ],
        ]);

        $this->assertSame('posts.*', $selector->name);
        $this->assertSame(['GET', 'POST'], $selector->methods);
    }

    public function test_from_array_with_any(): void
    {
        $selector = RouteSelector::fromArray([
            'routes' => [
                'any' => [
                    ['name' => 'admin.*'],
                    ['prefix' => '/api'],
                ],
            ],
        ]);

        $this->assertNotNull($selector->any);
        $this->assertCount(2, $selector->any);
        $this->assertSame('admin.*', $selector->any[0]->name);
        $this->assertSame('/api', $selector->any[1]->prefix);
    }

    // ── toArray roundtrip ────────────────────────────────────────────

    public function test_to_array_roundtrip(): void
    {
        $selector = new RouteSelector(name: 'test.*', prefix: '/test', methods: ['GET']);
        $array = $selector->toArray();

        $this->assertSame('test.*', $array['name']);
        $this->assertSame('/test', $array['prefix']);
        $this->assertSame(['GET'], $array['methods']);
        $this->assertArrayNotHasKey('any', $array);
        $this->assertArrayNotHasKey('uri', $array);
    }
}
