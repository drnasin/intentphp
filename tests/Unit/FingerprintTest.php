<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit;

use IntentPHP\Guard\Scan\Finding;
use IntentPHP\Guard\Scan\Fingerprint;
use PHPUnit\Framework\TestCase;

class FingerprintTest extends TestCase
{
    public function test_same_finding_produces_same_fingerprint(): void
    {
        $finding = Finding::high(
            check: 'route-authorization',
            message: 'Route has no auth.',
            context: ['methods' => ['GET'], 'uri' => '/orders', 'action' => 'OrderController@index'],
        );

        $fp1 = $finding->fingerprint();
        $fp2 = $finding->fingerprint();

        $this->assertSame($fp1, $fp2);
        $this->assertSame(40, strlen($fp1)); // sha1 hex length
    }

    public function test_different_routes_produce_different_fingerprints(): void
    {
        $a = Finding::high(
            check: 'route-authorization',
            message: 'No auth.',
            context: ['methods' => ['GET'], 'uri' => '/orders', 'action' => 'OrderController@index'],
        );

        $b = Finding::high(
            check: 'route-authorization',
            message: 'No auth.',
            context: ['methods' => ['POST'], 'uri' => '/users', 'action' => 'UserController@store'],
        );

        $this->assertNotSame($a->fingerprint(), $b->fingerprint());
    }

    public function test_different_checks_produce_different_fingerprints(): void
    {
        $a = Finding::high(
            check: 'route-authorization',
            message: 'test',
            file: 'app/Http/Controllers/Foo.php',
            line: 10,
        );

        $b = Finding::high(
            check: 'dangerous-query-input',
            message: 'test',
            file: 'app/Http/Controllers/Foo.php',
            line: 10,
        );

        $this->assertNotSame($a->fingerprint(), $b->fingerprint());
    }

    public function test_path_normalization_handles_backslashes(): void
    {
        $this->assertSame(
            Fingerprint::normalizePath('C:\\laragon\\www\\myapp\\app\\Models\\User.php'),
            Fingerprint::normalizePath('/home/deploy/myapp/app/Models/User.php'),
        );
    }

    public function test_path_normalization_returns_empty_for_null(): void
    {
        $this->assertSame('', Fingerprint::normalizePath(null));
    }

    public function test_model_findings_use_model_identifier(): void
    {
        $a = Finding::high(
            check: 'mass-assignment',
            message: 'Mass assignment risk.',
            file: 'app/Http/Controllers/PostController.php',
            line: 15,
            context: ['model' => 'Post', 'pattern' => 'create with $request->all()'],
        );

        $b = Finding::high(
            check: 'mass-assignment',
            message: 'Mass assignment risk.',
            file: 'app/Http/Controllers/PostController.php',
            line: 30, // Different line, same model+pattern
            context: ['model' => 'Post', 'pattern' => 'create with $request->all()'],
        );

        // Line changed but model+pattern same — fingerprints differ because line is included
        // This is by design: each occurrence is tracked individually
        $this->assertNotSame($a->fingerprint(), $b->fingerprint());
    }

    public function test_snippet_findings_use_snippet_hash(): void
    {
        $a = Finding::high(
            check: 'dangerous-query-input',
            message: 'Dangerous.',
            file: 'app/Http/Controllers/Foo.php',
            line: 10,
            context: ['snippet' => '->orderBy($request->input("sort"))'],
        );

        $b = Finding::high(
            check: 'dangerous-query-input',
            message: 'Dangerous.',
            file: 'app/Http/Controllers/Foo.php',
            line: 10,
            context: ['snippet' => '->orderBy($request->input("sort"))'],
        );

        $this->assertSame($a->fingerprint(), $b->fingerprint());
    }

    public function test_intent_auth_fingerprint_with_sorted_methods(): void
    {
        $a = Finding::high(
            check: 'intent-auth',
            message: 'Route missing auth.',
            context: [
                'matched_rule_ids' => ['rule-b', 'rule-a'],
                'uri' => '/orders',
                'route_name' => 'orders.index',
                'methods' => ['POST', 'GET'],
            ],
        );

        $b = Finding::high(
            check: 'intent-auth',
            message: 'Route missing auth.',
            context: [
                'matched_rule_ids' => ['rule-a', 'rule-b'],
                'uri' => '/orders',
                'route_name' => 'orders.index',
                'methods' => ['GET', 'POST'],
            ],
        );

        $this->assertSame($a->fingerprint(), $b->fingerprint());
        $this->assertSame(40, strlen($a->fingerprint()));
    }

    public function test_intent_mass_assignment_fingerprint_with_model_fqcn(): void
    {
        $finding = Finding::high(
            check: 'intent-mass-assignment',
            message: 'Model missing $fillable.',
            context: [
                'model_fqcn' => 'App\\Models\\User',
                'pattern' => 'missing_fillable',
            ],
        );

        $fp = $finding->fingerprint();
        $this->assertSame(40, strlen($fp));

        // Same finding produces same fingerprint
        $this->assertSame($fp, $finding->fingerprint());
    }

    public function test_different_rule_ids_produce_different_intent_auth_fingerprints(): void
    {
        $a = Finding::high(
            check: 'intent-auth',
            message: 'Missing auth.',
            context: [
                'matched_rule_ids' => ['rule-alpha'],
                'uri' => '/orders',
                'route_name' => '',
                'methods' => ['GET'],
            ],
        );

        $b = Finding::high(
            check: 'intent-auth',
            message: 'Missing auth.',
            context: [
                'matched_rule_ids' => ['rule-beta'],
                'uri' => '/orders',
                'route_name' => '',
                'methods' => ['GET'],
            ],
        );

        $this->assertNotSame($a->fingerprint(), $b->fingerprint());
    }
}
