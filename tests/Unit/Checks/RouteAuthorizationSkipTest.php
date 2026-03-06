<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Checks;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use IntentPHP\Guard\Checks\RouteAuthorizationCheck;
use IntentPHP\Guard\Checks\RouteProtectionDetector;
use PHPUnit\Framework\TestCase;

class RouteAuthorizationSkipTest extends TestCase
{
    private function makeRouter(array $routes): Router
    {
        $router = $this->createMock(Router::class);
        $routeObjects = [];

        foreach ($routes as [$methods, $uri, $middlewares]) {
            $route = $this->createMock(Route::class);
            $route->method('uri')->willReturn($uri);
            $route->method('methods')->willReturn($methods);
            $route->method('getActionName')->willReturn('Closure');
            $route->method('gatherMiddleware')->willReturn($middlewares);
            $routeObjects[] = $route;
        }

        $router->method('getRoutes')->willReturn($routeObjects);

        return $router;
    }

    public function test_login_route_skipped_by_default(): void
    {
        $router = $this->makeRouter([
            [['GET'], 'login', ['web']],
        ]);

        $check = new RouteAuthorizationCheck($router);
        $findings = $check->run();

        $this->assertEmpty($findings);
    }

    public function test_register_route_skipped_by_default(): void
    {
        $router = $this->makeRouter([
            [['POST'], 'register', ['web']],
        ]);

        $check = new RouteAuthorizationCheck($router);
        $findings = $check->run();

        $this->assertEmpty($findings);
    }

    public function test_health_route_skipped_by_default(): void
    {
        $router = $this->makeRouter([
            [['GET'], 'up', ['web']],
            [['GET'], 'health', []],
        ]);

        $check = new RouteAuthorizationCheck($router);
        $findings = $check->run();

        $this->assertEmpty($findings);
    }

    public function test_livewire_wildcard_skipped(): void
    {
        $router = $this->makeRouter([
            [['POST'], 'livewire/message/some-component', ['web']],
        ]);

        $check = new RouteAuthorizationCheck($router);
        $findings = $check->run();

        $this->assertEmpty($findings);
    }

    public function test_reset_password_wildcard_skipped(): void
    {
        $router = $this->makeRouter([
            [['POST'], 'reset-password/abc123', ['web']],
        ]);

        $check = new RouteAuthorizationCheck($router);
        $findings = $check->run();

        $this->assertEmpty($findings);
    }

    public function test_business_route_not_skipped(): void
    {
        $router = $this->makeRouter([
            [['GET'], 'api/orders', ['web']],
        ]);

        $check = new RouteAuthorizationCheck($router);
        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('api/orders', $findings[0]->message);
    }

    public function test_custom_skip_lists_override_defaults(): void
    {
        $router = $this->makeRouter([
            [['GET'], 'login', ['web']],       // normally skipped
            [['GET'], 'custom-public', ['web']], // custom skip
        ]);

        // Empty guest skip = login no longer skipped; custom infra skip
        $check = new RouteAuthorizationCheck(
            router: $router,
            skipGuestRoutes: [],
            skipInfraRoutes: ['custom-public'],
        );
        $findings = $check->run();

        // login should now produce a finding, custom-public should be skipped
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('login', $findings[0]->message);
    }

    public function test_public_routes_checked_after_skip_lists(): void
    {
        $router = $this->makeRouter([
            [['GET'], 'api/public-page', ['web']],
        ]);

        $check = new RouteAuthorizationCheck(
            router: $router,
            publicRoutes: ['api/public-page'],
        );
        $findings = $check->run();

        $this->assertEmpty($findings);
    }

    public function test_debugbar_routes_skipped(): void
    {
        $router = $this->makeRouter([
            [['GET'], '_debugbar/open', ['web']],
        ]);

        $check = new RouteAuthorizationCheck($router);
        $findings = $check->run();

        $this->assertEmpty($findings);
    }
}
