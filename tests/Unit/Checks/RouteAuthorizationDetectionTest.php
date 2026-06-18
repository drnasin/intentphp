<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Checks;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use IntentPHP\Guard\Checks\RouteAuthorizationCheck;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for issue #14: authorization detection over the controller
 * method's raw source must not be fooled by method names containing the substring
 * "can(" (scan/rescan/lifespan), nor by tokens inside comments or string literals.
 *
 * Uses real fixture controllers because the check resolves the action via
 * reflection (class_exists + ReflectionMethod) and reads the method's source file.
 */
class RouteAuthorizationDetectionTest extends TestCase
{
    private const NS = 'IntentPHP\\Guard\\Tests\\Fixtures\\RouteAuth\\';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../fixtures/route-auth/Controllers.php';
    }

    /** Build a router with one route whose action resolves to a fixture controller method. */
    private function routerFor(string $action): Router
    {
        $route = $this->createMock(Route::class);
        $route->method('uri')->willReturn('api/widgets');
        $route->method('methods')->willReturn(['GET']);
        $route->method('getActionName')->willReturn($action);
        $route->method('gatherMiddleware')->willReturn(['web']); // no auth middleware

        $router = $this->createMock(Router::class);
        $router->method('getRoutes')->willReturn([$route]);

        return $router;
    }

    /** @return \IntentPHP\Guard\Scan\Finding[] */
    private function findingsFor(string $controllerShortName, string $method = 'index'): array
    {
        $action = self::NS . $controllerShortName . '@' . $method;

        return (new RouteAuthorizationCheck($this->routerFor($action)))->run();
    }

    public function test_substring_can_in_method_name_does_not_suppress_finding(): void
    {
        // Body calls $this->rescan($id) — contains the substring "can(" but no auth.
        $findings = $this->findingsFor('SubstringController');

        $this->assertCount(1, $findings);
        $this->assertSame('route-authorization', $findings[0]->check);
        $this->assertSame('high', $findings[0]->severity);
        $this->assertStringContainsString('api/widgets', $findings[0]->message);
    }

    public function test_real_authorize_call_suppresses_finding(): void
    {
        $this->assertSame([], $this->findingsFor('AuthorizeController'));
    }

    public function test_real_can_call_suppresses_finding(): void
    {
        $this->assertSame([], $this->findingsFor('CanController'));
    }

    public function test_authorize_in_comment_or_string_does_not_suppress_finding(): void
    {
        // $this->authorize( appears only inside a comment and a string literal.
        $findings = $this->findingsFor('CommentStringController');

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_constructor_authorize_resource_suppresses_finding(): void
    {
        $this->assertSame([], $this->findingsFor('AuthorizeResourceController'));
    }

    public function test_commented_authorize_resource_does_not_suppress_finding(): void
    {
        // authorizeResource appears only in a constructor comment.
        $findings = $this->findingsFor('CommentedAuthorizeResourceController');

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }
}
