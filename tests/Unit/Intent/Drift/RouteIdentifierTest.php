<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Intent\Drift;

use IntentPHP\Guard\Intent\Drift\Context\ObservedRoute;
use IntentPHP\Guard\Intent\Drift\RouteIdentifier;
use PHPUnit\Framework\TestCase;

class RouteIdentifierTest extends TestCase
{
    private function route(
        string $uri = '/test',
        string $name = '',
        array $methods = ['GET'],
    ): ObservedRoute {
        return new ObservedRoute(
            uri: $uri,
            name: $name,
            methods: $methods,
            middleware: [],
            action: 'Closure',
        );
    }

    public function test_named_route_uses_name(): void
    {
        $route = $this->route(uri: '/orders', name: 'orders.index');
        $this->assertSame('name:orders.index', RouteIdentifier::routeKey($route));
    }

    public function test_unnamed_route_uses_uri(): void
    {
        $route = $this->route(uri: '/orders', name: '');
        $this->assertSame('uri:/orders', RouteIdentifier::routeKey($route));
    }

    public function test_uri_leading_slash_normalized(): void
    {
        $this->assertSame('/orders', RouteIdentifier::normalizeUri('orders'));
    }

    public function test_uri_trailing_slash_stripped(): void
    {
        $this->assertSame('/orders', RouteIdentifier::normalizeUri('/orders/'));
    }

    public function test_root_uri_preserved(): void
    {
        $this->assertSame('/', RouteIdentifier::normalizeUri('/'));
    }

    public function test_parameter_segments_preserved(): void
    {
        $this->assertSame(
            '/users/{id}/posts/{post}',
            RouteIdentifier::normalizeUri('/users/{id}/posts/{post}'),
        );
    }

    public function test_head_excluded(): void
    {
        $route = $this->route(methods: ['GET', 'HEAD']);
        $this->assertSame('GET', RouteIdentifier::methodsString($route));
    }

    public function test_methods_sorted(): void
    {
        $route = $this->route(methods: ['POST', 'DELETE', 'GET']);
        $this->assertSame('DELETE,GET,POST', RouteIdentifier::methodsString($route));
    }

    public function test_composite_named(): void
    {
        $route = $this->route(uri: '/orders', name: 'orders.index', methods: ['GET', 'HEAD']);
        $this->assertSame('name:orders.index|GET', RouteIdentifier::composite($route));
    }

    public function test_composite_unnamed(): void
    {
        $route = $this->route(uri: '/api/orders/{id}', name: '', methods: ['GET', 'DELETE']);
        $this->assertSame('uri:/api/orders/{id}|DELETE,GET', RouteIdentifier::composite($route));
    }

    public function test_deterministic_across_calls(): void
    {
        $route = $this->route(uri: '/orders', name: 'orders.index', methods: ['POST', 'GET']);
        $first = RouteIdentifier::composite($route);
        $second = RouteIdentifier::composite($route);
        $this->assertSame($first, $second);
    }
}
