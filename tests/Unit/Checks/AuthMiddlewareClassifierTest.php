<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Checks;

use IntentPHP\Guard\Checks\AuthMiddlewareClassifier;
use PHPUnit\Framework\TestCase;

class AuthMiddlewareClassifierTest extends TestCase
{
    public function test_defaults_include_auth_alias(): void
    {
        $c = AuthMiddlewareClassifier::defaults();

        $this->assertTrue($c->isAuth('auth'));
    }

    public function test_defaults_include_auth_sanctum(): void
    {
        $c = AuthMiddlewareClassifier::defaults();

        $this->assertTrue($c->isAuth('auth:sanctum'));
    }

    public function test_defaults_include_filament_fqcn(): void
    {
        $c = AuthMiddlewareClassifier::defaults();

        $this->assertTrue($c->isAuth('Filament\\Http\\Middleware\\Authenticate'));
    }

    public function test_prefix_match_with_colon(): void
    {
        $c = AuthMiddlewareClassifier::defaults();

        // 'auth' prefix matches 'auth:api', 'auth:web', etc.
        $this->assertTrue($c->isAuth('auth:api'));
        $this->assertTrue($c->isAuth('auth:web'));
    }

    public function test_prefix_does_not_match_without_colon(): void
    {
        $c = AuthMiddlewareClassifier::defaults();

        // 'authenticate' should not match 'auth' prefix
        $this->assertFalse($c->isAuth('authenticate'));
    }

    public function test_suffix_match(): void
    {
        $c = new AuthMiddlewareClassifier(
            exact: [],
            prefixes: [],
            suffixes: ['\\Http\\Middleware\\Authenticate'],
        );

        $this->assertTrue($c->isAuth('App\\Http\\Middleware\\Authenticate'));
        $this->assertTrue($c->isAuth('Vendor\\Http\\Middleware\\Authenticate'));
        $this->assertFalse($c->isAuth('auth'));
    }

    public function test_has_auth_returns_true_when_any_match(): void
    {
        $c = AuthMiddlewareClassifier::defaults();

        $this->assertTrue($c->hasAuth(['web', 'throttle:60,1', 'auth']));
    }

    public function test_has_auth_returns_false_when_none_match(): void
    {
        $c = AuthMiddlewareClassifier::defaults();

        $this->assertFalse($c->hasAuth(['web', 'throttle:60,1']));
    }

    public function test_matched_auth_returns_first_match(): void
    {
        $c = AuthMiddlewareClassifier::defaults();

        $this->assertSame('auth:sanctum', $c->matchedAuth(['web', 'auth:sanctum', 'auth']));
    }

    public function test_matched_auth_returns_null_when_no_match(): void
    {
        $c = AuthMiddlewareClassifier::defaults();

        $this->assertNull($c->matchedAuth(['web', 'throttle:60,1']));
    }

    public function test_from_config_reads_route_authorization(): void
    {
        $config = [
            'route_authorization' => [
                'auth_middleware_exact' => ['custom-auth'],
                'auth_middleware_prefixes' => ['custom-auth'],
                'auth_middleware_suffixes' => [],
            ],
        ];

        $c = AuthMiddlewareClassifier::fromConfig($config);

        $this->assertTrue($c->isAuth('custom-auth'));
        $this->assertTrue($c->isAuth('custom-auth:api'));
        $this->assertFalse($c->isAuth('auth'));
    }

    public function test_from_config_falls_back_to_legacy_auth_middlewares(): void
    {
        $config = [
            'auth_middlewares' => ['legacy-auth'],
        ];

        $c = AuthMiddlewareClassifier::fromConfig($config);

        $this->assertTrue($c->isAuth('legacy-auth'));
        $this->assertTrue($c->isAuth('legacy-auth:api'));
        $this->assertFalse($c->isAuth('auth'));
    }

    public function test_from_config_returns_defaults_when_empty(): void
    {
        $c = AuthMiddlewareClassifier::fromConfig([]);

        $this->assertTrue($c->isAuth('auth'));
        $this->assertTrue($c->isAuth('auth:sanctum'));
        $this->assertTrue($c->isAuth('Filament\\Http\\Middleware\\Authenticate'));
    }

    public function test_from_legacy_list_splits_aliases_and_fqcns(): void
    {
        $c = AuthMiddlewareClassifier::fromLegacyList([
            'auth',
            'auth:sanctum',
            'App\\Http\\Middleware\\CustomAuth',
        ]);

        // Exact matches
        $this->assertTrue($c->isAuth('auth'));
        $this->assertTrue($c->isAuth('auth:sanctum'));
        $this->assertTrue($c->isAuth('App\\Http\\Middleware\\CustomAuth'));

        // 'auth' short alias gets prefix matching too
        $this->assertTrue($c->isAuth('auth:api'));

        // FQCNs don't get prefix matching
        $this->assertFalse($c->isAuth('App\\Http\\Middleware\\CustomAuth:admin'));
    }

    public function test_to_flat_list_returns_exact_entries(): void
    {
        $c = new AuthMiddlewareClassifier(
            exact: ['auth', 'auth:sanctum'],
            prefixes: ['auth'],
            suffixes: [],
        );

        $this->assertSame(['auth', 'auth:sanctum'], $c->toFlatList());
    }
}
