<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Checks;

use IntentPHP\Guard\Checks\AuthMiddlewareClassifier;
use IntentPHP\Guard\Checks\RouteProtectionDetector;
use PHPUnit\Framework\TestCase;

class RouteProtectionDetectorTest extends TestCase
{
    public function test_recognizes_auth_alias(): void
    {
        $detector = new RouteProtectionDetector();

        $this->assertTrue($detector->hasAuthMiddleware(['web', 'auth']));
    }

    public function test_recognizes_auth_sanctum(): void
    {
        $detector = new RouteProtectionDetector();

        $this->assertTrue($detector->hasAuthMiddleware(['auth:sanctum']));
    }

    public function test_recognizes_filament_authenticate_fqcn(): void
    {
        $detector = new RouteProtectionDetector();

        $this->assertTrue($detector->hasAuthMiddleware([
            'web',
            'Filament\\Http\\Middleware\\Authenticate',
            'Illuminate\\Cookie\\Middleware\\AddQueuedCookiesToResponse',
        ]));
    }

    public function test_filament_fqcn_exact_match_only(): void
    {
        $detector = new RouteProtectionDetector();

        // A string that starts with the FQCN but isn't exact should NOT match
        $this->assertFalse($detector->hasAuthMiddleware([
            'Filament\\Http\\Middleware\\AuthenticateFoo',
        ]));
    }

    public function test_no_auth_middleware_returns_false(): void
    {
        $detector = new RouteProtectionDetector();

        $this->assertFalse($detector->hasAuthMiddleware(['web', 'throttle:60,1']));
    }

    public function test_custom_allowlist(): void
    {
        $detector = new RouteProtectionDetector(['custom-auth']);

        $this->assertTrue($detector->hasAuthMiddleware(['custom-auth']));
        $this->assertFalse($detector->hasAuthMiddleware(['auth']));
    }

    public function test_custom_fqcn_in_allowlist(): void
    {
        $detector = new RouteProtectionDetector([
            'auth',
            'App\\Http\\Middleware\\CustomAuth',
        ]);

        $this->assertTrue($detector->hasAuthMiddleware(['App\\Http\\Middleware\\CustomAuth']));
        $this->assertTrue($detector->hasAuthMiddleware(['auth']));
        $this->assertFalse($detector->hasAuthMiddleware(['web']));
    }

    public function test_accepts_classifier_instance(): void
    {
        $classifier = new AuthMiddlewareClassifier(
            exact: ['custom-auth'],
            prefixes: ['custom-auth'],
            suffixes: [],
        );
        $detector = new RouteProtectionDetector($classifier);

        $this->assertTrue($detector->hasAuthMiddleware(['custom-auth']));
        $this->assertTrue($detector->hasAuthMiddleware(['custom-auth:api']));
        $this->assertFalse($detector->hasAuthMiddleware(['auth']));
        $this->assertSame($classifier, $detector->getClassifier());
    }

    public function test_alias_prefix_match_does_not_apply_to_fqcn(): void
    {
        // A FQCN like 'Filament\Http\Middleware\Authenticate' should NOT match
        // via prefix against 'Filament\Http\Middleware\Authenticate:something'
        // because FQCNs don't use the colon-parameter convention.
        // But more importantly, the FQCN should not accidentally prefix-match other strings.
        $detector = new RouteProtectionDetector([
            'Filament\\Http\\Middleware\\Authenticate',
        ]);

        // Exact match works
        $this->assertTrue($detector->hasAuthMiddleware([
            'Filament\\Http\\Middleware\\Authenticate',
        ]));

        // Something that starts with the FQCN + colon should NOT match
        // (FQCNs don't use colon parameters)
        $this->assertFalse($detector->hasAuthMiddleware([
            'Filament\\Http\\Middleware\\Authenticate:admin',
        ]));
    }
}
