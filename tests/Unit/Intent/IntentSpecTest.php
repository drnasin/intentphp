<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Intent;

use IntentPHP\Guard\Intent\Auth\AuthRequirement;
use IntentPHP\Guard\Intent\Auth\AuthRule;
use IntentPHP\Guard\Intent\Auth\AuthSpec;
use IntentPHP\Guard\Intent\Baseline\BaselineFinding;
use IntentPHP\Guard\Intent\Baseline\BaselineSpec;
use IntentPHP\Guard\Intent\Data\DataSpec;
use IntentPHP\Guard\Intent\Data\ModelSpec;
use IntentPHP\Guard\Intent\Defaults;
use IntentPHP\Guard\Intent\IntentSpec;
use IntentPHP\Guard\Intent\ProjectMeta;
use IntentPHP\Guard\Intent\Selector\RouteSelector;
use PHPUnit\Framework\TestCase;

class IntentSpecTest extends TestCase
{
    // ── Full spec construction ───────────────────────────────────────

    public function test_from_array_with_full_spec(): void
    {
        $data = [
            'version' => '0.1',
            'project' => [
                'name' => 'test-app',
                'framework' => 'laravel',
                'php' => '8.2',
                'laravel' => '12',
            ],
            'defaults' => [
                'authMode' => 'deny_by_default',
                'baselineRequireExpiry' => true,
                'baselineExpiredIsError' => false,
            ],
            'auth' => [
                'guards' => ['web' => 'session', 'api' => 'sanctum'],
                'roles' => ['admin' => [], 'user' => []],
                'abilities' => ['post.view' => 'View posts'],
                'rules' => [
                    [
                        'id' => 'auth.admin',
                        'match' => ['routes' => ['prefix' => '/admin']],
                        'require' => ['authenticated' => true, 'guard' => 'web'],
                    ],
                ],
            ],
            'data' => [
                'models' => [
                    'App\\Models\\Post' => [
                        'massAssignment' => [
                            'mode' => 'explicit_allowlist',
                            'allow' => ['title', 'body'],
                            'forbid' => ['user_id'],
                        ],
                    ],
                ],
            ],
            'baseline' => [
                'findings' => [
                    [
                        'id' => 'baseline.legacy',
                        'fingerprint' => 'abc123',
                        'reason' => 'Legacy route',
                        'expires' => '2026-12-31',
                    ],
                ],
            ],
        ];

        $spec = IntentSpec::fromArray($data);

        $this->assertSame('0.1', $spec->version);
        $this->assertSame('test-app', $spec->project->name);
        $this->assertSame('laravel', $spec->project->framework);
        $this->assertTrue($spec->defaults->baselineRequireExpiry);
        $this->assertFalse($spec->defaults->baselineExpiredIsError);
        $this->assertCount(2, $spec->auth->guards);
        $this->assertCount(2, $spec->auth->roles);
        $this->assertCount(1, $spec->auth->abilities);
        $this->assertCount(1, $spec->auth->rules);
        $this->assertSame('auth.admin', $spec->auth->rules[0]->id);
        $this->assertCount(1, $spec->data->models);
        $this->assertSame('explicit_allowlist', $spec->data->models['App\\Models\\Post']->massAssignmentMode);
        $this->assertCount(1, $spec->baseline->findings);
        $this->assertSame('2026-12-31', $spec->baseline->findings[0]->expires);
    }

    // ── Minimal spec ─────────────────────────────────────────────────

    public function test_from_array_with_minimal_spec(): void
    {
        $spec = IntentSpec::fromArray([
            'version' => '0.1',
            'project' => ['name' => 'minimal'],
        ]);

        $this->assertSame('0.1', $spec->version);
        $this->assertSame('minimal', $spec->project->name);
        $this->assertSame('deny_by_default', $spec->defaults->authMode);
        $this->assertSame([], $spec->auth->rules);
        $this->assertSame([], $spec->data->models);
        $this->assertSame([], $spec->baseline->findings);
    }

    // ── Roundtrip ────────────────────────────────────────────────────

    public function test_to_array_roundtrip(): void
    {
        $data = [
            'version' => '0.1',
            'project' => ['name' => 'roundtrip', 'framework' => 'laravel', 'php' => '8.3', 'laravel' => '11'],
            'defaults' => ['authMode' => 'deny_by_default', 'baselineRequireExpiry' => false, 'baselineExpiredIsError' => true],
            'auth' => [
                'guards' => ['web' => 'session'],
                'rules' => [
                    ['id' => 'auth.test', 'match' => ['routes' => ['name' => 'admin.*']], 'require' => ['authenticated' => true]],
                ],
            ],
        ];

        $spec = IntentSpec::fromArray($data);
        $output = $spec->toArray();

        $this->assertSame('0.1', $output['version']);
        $this->assertSame('roundtrip', $output['project']['name']);
        $this->assertSame('web', array_key_first($output['auth']['guards']));
        $this->assertSame('auth.test', $output['auth']['rules'][0]['id']);
    }

    // ── ProjectMeta ──────────────────────────────────────────────────

    public function test_project_meta_defaults(): void
    {
        $meta = ProjectMeta::fromArray([]);

        $this->assertSame('', $meta->name);
        $this->assertSame('laravel', $meta->framework);
    }

    // ── Defaults ─────────────────────────────────────────────────────

    public function test_defaults_from_empty_array(): void
    {
        $defaults = Defaults::fromArray([]);

        $this->assertSame('deny_by_default', $defaults->authMode);
        $this->assertFalse($defaults->baselineRequireExpiry);
        $this->assertTrue($defaults->baselineExpiredIsError);
    }

    // ── AuthRule ─────────────────────────────────────────────────────

    public function test_auth_rule_from_array(): void
    {
        $rule = AuthRule::fromArray([
            'id' => 'auth.api_write',
            'match' => ['routes' => ['prefix' => '/api', 'methods' => ['POST', 'PUT']]],
            'require' => ['authenticated' => true, 'guard' => 'api', 'abilitiesAny' => ['write']],
        ]);

        $this->assertSame('auth.api_write', $rule->id);
        $this->assertSame('/api', $rule->match->prefix);
        $this->assertSame(['POST', 'PUT'], $rule->match->methods);
        $this->assertSame('api', $rule->require->guard);
        $this->assertSame(['write'], $rule->require->abilitiesAny);
    }

    // ── AuthRequirement public ───────────────────────────────────────

    public function test_auth_requirement_public_endpoint(): void
    {
        $req = AuthRequirement::fromArray([
            'public' => true,
            'reason' => 'Webhook verified by signature',
        ]);

        $this->assertTrue($req->public);
        $this->assertSame('Webhook verified by signature', $req->reason);
        $this->assertTrue($req->authenticated); // default
    }

    // ── ModelSpec ────────────────────────────────────────────────────

    public function test_model_spec_from_array(): void
    {
        $model = ModelSpec::fromArray('App\\Models\\Post', [
            'massAssignment' => [
                'mode' => 'guarded',
                'forbid' => ['password', 'role'],
            ],
        ]);

        $this->assertSame('App\\Models\\Post', $model->fqcn);
        $this->assertSame('guarded', $model->massAssignmentMode);
        $this->assertSame([], $model->allow);
        $this->assertSame(['password', 'role'], $model->forbid);
    }

    // ── BaselineFinding ──────────────────────────────────────────────

    public function test_baseline_finding_from_array(): void
    {
        $finding = BaselineFinding::fromArray([
            'id' => 'baseline.test',
            'fingerprint' => 'abc123',
            'reason' => 'Known issue',
            'expires' => '2026-06-01',
        ]);

        $this->assertSame('baseline.test', $finding->id);
        $this->assertSame('abc123', $finding->fingerprint);
        $this->assertSame('2026-06-01', $finding->expires);
    }

    public function test_baseline_finding_without_expiry(): void
    {
        $finding = BaselineFinding::fromArray([
            'id' => 'baseline.noexp',
            'fingerprint' => 'xyz',
            'reason' => 'No expiry',
        ]);

        $this->assertNull($finding->expires);
    }

    // ── Auth rules sorted by id ──────────────────────────────────────

    public function test_auth_rules_sorted_by_id(): void
    {
        $spec = AuthSpec::fromArray([
            'rules' => [
                ['id' => 'z.rule', 'match' => ['routes' => ['name' => '*']], 'require' => []],
                ['id' => 'a.rule', 'match' => ['routes' => ['name' => '*']], 'require' => []],
            ],
        ]);

        $this->assertSame('a.rule', $spec->rules[0]->id);
        $this->assertSame('z.rule', $spec->rules[1]->id);
    }
}
