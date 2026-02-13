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
use IntentPHP\Guard\Intent\SpecValidator;
use PHPUnit\Framework\TestCase;

class SpecValidatorTest extends TestCase
{
    private function validSpec(): IntentSpec
    {
        return new IntentSpec(
            version: '0.1',
            project: new ProjectMeta(name: 'test', framework: 'laravel'),
            defaults: new Defaults(),
            auth: new AuthSpec(
                guards: ['web' => 'session'],
                rules: [
                    new AuthRule(
                        id: 'auth.test',
                        match: new RouteSelector(name: 'admin.*'),
                        require: new AuthRequirement(guard: 'web'),
                    ),
                ],
            ),
            data: DataSpec::empty(),
            baseline: BaselineSpec::empty(),
        );
    }

    // ── Valid spec passes ────────────────────────────────────────────

    public function test_valid_spec_returns_no_errors(): void
    {
        $validator = new SpecValidator();
        $result = $validator->validate($this->validSpec());

        $this->assertSame([], $result['errors']);
    }

    // ── Rule 1: Version ──────────────────────────────────────────────

    public function test_unsupported_version_returns_error(): void
    {
        $spec = new IntentSpec(
            version: '9.9',
            project: new ProjectMeta(name: 'test', framework: 'laravel'),
            defaults: new Defaults(),
            auth: AuthSpec::empty(),
            data: DataSpec::empty(),
            baseline: BaselineSpec::empty(),
        );

        $result = (new SpecValidator())->validate($spec);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('version', $result['errors'][0]);
    }

    // ── Rule 2: Project name ─────────────────────────────────────────

    public function test_missing_project_name_returns_error(): void
    {
        $spec = new IntentSpec(
            version: '0.1',
            project: new ProjectMeta(name: '', framework: 'laravel'),
            defaults: new Defaults(),
            auth: AuthSpec::empty(),
            data: DataSpec::empty(),
            baseline: BaselineSpec::empty(),
        );

        $result = (new SpecValidator())->validate($spec);
        $this->assertStringContainsString('name', $result['errors'][0]);
    }

    // ── Rule 3: Framework ────────────────────────────────────────────

    public function test_unsupported_framework_returns_error(): void
    {
        $spec = new IntentSpec(
            version: '0.1',
            project: new ProjectMeta(name: 'test', framework: 'symfony'),
            defaults: new Defaults(),
            auth: AuthSpec::empty(),
            data: DataSpec::empty(),
            baseline: BaselineSpec::empty(),
        );

        $result = (new SpecValidator())->validate($spec);
        $this->assertStringContainsString('framework', $result['errors'][0]);
    }

    // ── Rule 4: Auth mode ────────────────────────────────────────────

    public function test_invalid_auth_mode_returns_error(): void
    {
        $spec = new IntentSpec(
            version: '0.1',
            project: new ProjectMeta(name: 'test', framework: 'laravel'),
            defaults: new Defaults(authMode: 'yolo'),
            auth: AuthSpec::empty(),
            data: DataSpec::empty(),
            baseline: BaselineSpec::empty(),
        );

        $result = (new SpecValidator())->validate($spec);
        $this->assertStringContainsString('authMode', $result['errors'][0]);
    }

    // ── Rule 8: Empty selector ───────────────────────────────────────

    public function test_empty_selector_returns_error(): void
    {
        $spec = new IntentSpec(
            version: '0.1',
            project: new ProjectMeta(name: 'test', framework: 'laravel'),
            defaults: new Defaults(),
            auth: new AuthSpec(rules: [
                new AuthRule(id: 'auth.empty', match: new RouteSelector(), require: new AuthRequirement()),
            ]),
            data: DataSpec::empty(),
            baseline: BaselineSpec::empty(),
        );

        $result = (new SpecValidator())->validate($spec);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('empty match selector', $result['errors'][0]);
    }

    // ── Rule 9: Invalid HTTP methods ─────────────────────────────────

    public function test_invalid_http_method_returns_error(): void
    {
        $spec = new IntentSpec(
            version: '0.1',
            project: new ProjectMeta(name: 'test', framework: 'laravel'),
            defaults: new Defaults(),
            auth: new AuthSpec(rules: [
                new AuthRule(
                    id: 'auth.bad_method',
                    match: new RouteSelector(name: '*', methods: ['YEET']),
                    require: new AuthRequirement(),
                ),
            ]),
            data: DataSpec::empty(),
            baseline: BaselineSpec::empty(),
        );

        $result = (new SpecValidator())->validate($spec);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('YEET', $result['errors'][0]);
    }

    // ── Rule 10: Guard reference ─────────────────────────────────────

    public function test_undefined_guard_reference_returns_error(): void
    {
        $spec = new IntentSpec(
            version: '0.1',
            project: new ProjectMeta(name: 'test', framework: 'laravel'),
            defaults: new Defaults(),
            auth: new AuthSpec(
                guards: ['web' => 'session'],
                rules: [
                    new AuthRule(
                        id: 'auth.bad_guard',
                        match: new RouteSelector(name: '*'),
                        require: new AuthRequirement(guard: 'nonexistent'),
                    ),
                ],
            ),
            data: DataSpec::empty(),
            baseline: BaselineSpec::empty(),
        );

        $result = (new SpecValidator())->validate($spec);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('nonexistent', $result['errors'][0]);
    }

    // ── Rule 11: Invalid expiry date ─────────────────────────────────

    public function test_invalid_baseline_expiry_returns_error(): void
    {
        $spec = new IntentSpec(
            version: '0.1',
            project: new ProjectMeta(name: 'test', framework: 'laravel'),
            defaults: new Defaults(baselineExpiredIsError: false),
            auth: AuthSpec::empty(),
            data: DataSpec::empty(),
            baseline: new BaselineSpec(findings: [
                new BaselineFinding(id: 'bl.bad', fingerprint: 'abc', reason: 'test', expires: 'not-a-date'),
            ]),
        );

        $result = (new SpecValidator())->validate($spec);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('invalid expiry', $result['errors'][0]);
    }

    // ── Rule 12: Expired baseline ────────────────────────────────────

    public function test_expired_baseline_is_error_by_default(): void
    {
        $spec = new IntentSpec(
            version: '0.1',
            project: new ProjectMeta(name: 'test', framework: 'laravel'),
            defaults: new Defaults(baselineExpiredIsError: true),
            auth: AuthSpec::empty(),
            data: DataSpec::empty(),
            baseline: new BaselineSpec(findings: [
                new BaselineFinding(id: 'bl.expired', fingerprint: 'abc', reason: 'old', expires: '2020-01-01'),
            ]),
        );

        $result = (new SpecValidator())->validate($spec);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('expired', $result['errors'][0]);
    }

    public function test_expired_baseline_is_warning_when_configured(): void
    {
        $spec = new IntentSpec(
            version: '0.1',
            project: new ProjectMeta(name: 'test', framework: 'laravel'),
            defaults: new Defaults(baselineExpiredIsError: false),
            auth: AuthSpec::empty(),
            data: DataSpec::empty(),
            baseline: new BaselineSpec(findings: [
                new BaselineFinding(id: 'bl.expired', fingerprint: 'abc', reason: 'old', expires: '2020-01-01'),
            ]),
        );

        $result = (new SpecValidator())->validate($spec);
        $this->assertSame([], $result['errors']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('expired', $result['warnings'][0]);
    }

    // ── Rule 13: Require expiry ──────────────────────────────────────

    public function test_require_expiry_enforced(): void
    {
        $spec = new IntentSpec(
            version: '0.1',
            project: new ProjectMeta(name: 'test', framework: 'laravel'),
            defaults: new Defaults(baselineRequireExpiry: true),
            auth: AuthSpec::empty(),
            data: DataSpec::empty(),
            baseline: new BaselineSpec(findings: [
                new BaselineFinding(id: 'bl.noexp', fingerprint: 'abc', reason: 'test', expires: null),
            ]),
        );

        $result = (new SpecValidator())->validate($spec);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('requires an expiry', $result['errors'][0]);
    }

    // ── Rule 14: Mass assignment mode ────────────────────────────────

    public function test_invalid_mass_assignment_mode_returns_error(): void
    {
        $spec = new IntentSpec(
            version: '0.1',
            project: new ProjectMeta(name: 'test', framework: 'laravel'),
            defaults: new Defaults(),
            auth: AuthSpec::empty(),
            data: new DataSpec(models: [
                'App\\Models\\Post' => new ModelSpec('App\\Models\\Post', 'yolo'),
            ]),
            baseline: BaselineSpec::empty(),
        );

        $result = (new SpecValidator())->validate($spec);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('yolo', $result['errors'][0]);
    }

    public function test_empty_allowlist_produces_warning(): void
    {
        $spec = new IntentSpec(
            version: '0.1',
            project: new ProjectMeta(name: 'test', framework: 'laravel'),
            defaults: new Defaults(),
            auth: AuthSpec::empty(),
            data: new DataSpec(models: [
                'App\\Models\\Post' => new ModelSpec('App\\Models\\Post', 'explicit_allowlist', [], []),
            ]),
            baseline: BaselineSpec::empty(),
        );

        $result = (new SpecValidator())->validate($spec);
        $this->assertSame([], $result['errors']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('allow list is empty', $result['warnings'][0]);
    }
}
