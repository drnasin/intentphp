<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Intent;

use IntentPHP\Guard\Intent\SpecLoader;
use PHPUnit\Framework\TestCase;

class SpecLoaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/guard_intent_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    // ── Single file ──────────────────────────────────────────────────

    public function test_load_single_file(): void
    {
        $yaml = <<<'YAML'
version: "0.1"
project:
  name: test-app
  framework: laravel
auth:
  guards:
    web: session
  rules:
    - id: auth.admin
      match:
        routes:
          prefix: "/admin"
      require:
        authenticated: true
YAML;

        file_put_contents($this->tempDir . '/intent.yaml', $yaml);

        $loader = new SpecLoader();
        $result = $loader->load($this->tempDir . '/intent.yaml');

        $this->assertSame([], $result['warnings']);
        $this->assertSame('0.1', $result['spec']->version);
        $this->assertSame('test-app', $result['spec']->project->name);
        $this->assertCount(1, $result['spec']->auth->rules);
        $this->assertSame('auth.admin', $result['spec']->auth->rules[0]->id);
    }

    // ── Includes ─────────────────────────────────────────────────────

    public function test_load_with_includes(): void
    {
        $manifest = <<<'YAML'
version: "0.1"
project:
  name: included-app
  framework: laravel
includes:
  - auth.yaml
  - data.yaml
YAML;

        $auth = <<<'YAML'
auth:
  guards:
    api: sanctum
  rules:
    - id: auth.api
      match:
        routes:
          prefix: "/api"
      require:
        authenticated: true
        guard: api
YAML;

        $data = <<<'YAML'
data:
  models:
    App\Models\Post:
      massAssignment:
        mode: explicit_allowlist
        allow: [title]
YAML;

        file_put_contents($this->tempDir . '/intent.yaml', $manifest);
        file_put_contents($this->tempDir . '/auth.yaml', $auth);
        file_put_contents($this->tempDir . '/data.yaml', $data);

        $loader = new SpecLoader();
        $result = $loader->load($this->tempDir . '/intent.yaml');

        $this->assertSame([], $result['warnings']);
        $this->assertCount(1, $result['spec']->auth->rules);
        $this->assertArrayHasKey('api', $result['spec']->auth->guards);
        $this->assertArrayHasKey('App\\Models\\Post', $result['spec']->data->models);
    }

    // ── Missing include ──────────────────────────────────────────────

    public function test_missing_include_produces_warning(): void
    {
        $manifest = <<<'YAML'
version: "0.1"
project:
  name: warn-app
  framework: laravel
includes:
  - nonexistent.yaml
YAML;

        file_put_contents($this->tempDir . '/intent.yaml', $manifest);

        $loader = new SpecLoader();
        $result = $loader->load($this->tempDir . '/intent.yaml');

        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('nonexistent.yaml', $result['warnings'][0]);
        $this->assertSame('warn-app', $result['spec']->project->name);
    }

    // ── Missing root file ────────────────────────────────────────────

    public function test_missing_root_throws_exception(): void
    {
        $loader = new SpecLoader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');
        $loader->load($this->tempDir . '/missing.yaml');
    }

    // ── Invalid YAML ─────────────────────────────────────────────────

    public function test_invalid_yaml_throws_exception(): void
    {
        file_put_contents($this->tempDir . '/intent.yaml', "version: \"0.1\"\n  bad indent: [");

        $loader = new SpecLoader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to parse');
        $loader->load($this->tempDir . '/intent.yaml');
    }

    // ── Duplicate ID ─────────────────────────────────────────────────

    public function test_duplicate_auth_rule_id_throws_exception(): void
    {
        $yaml = <<<'YAML'
version: "0.1"
project:
  name: dup-app
  framework: laravel
auth:
  rules:
    - id: auth.same
      match:
        routes:
          prefix: "/a"
      require:
        authenticated: true
    - id: auth.same
      match:
        routes:
          prefix: "/b"
      require:
        authenticated: true
YAML;

        file_put_contents($this->tempDir . '/intent.yaml', $yaml);

        $loader = new SpecLoader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Duplicate ID found: 'auth.same'");
        $loader->load($this->tempDir . '/intent.yaml');
    }

    public function test_cross_namespace_duplicate_id_throws_exception(): void
    {
        $yaml = <<<'YAML'
version: "0.1"
project:
  name: cross-dup
  framework: laravel
auth:
  rules:
    - id: shared.id
      match:
        routes:
          name: "*"
      require:
        authenticated: true
baseline:
  findings:
    - id: shared.id
      fingerprint: abc
      reason: test
YAML;

        file_put_contents($this->tempDir . '/intent.yaml', $yaml);

        $loader = new SpecLoader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Duplicate ID found: 'shared.id'");
        $loader->load($this->tempDir . '/intent.yaml');
    }

    // ── loadFromArray ────────────────────────────────────────────────

    public function test_load_from_array(): void
    {
        $loader = new SpecLoader();
        $spec = $loader->loadFromArray([
            'version' => '0.1',
            'project' => ['name' => 'array-app', 'framework' => 'laravel'],
        ]);

        $this->assertSame('array-app', $spec->project->name);
    }

    // ── Merge override for keyed maps ────────────────────────────────

    public function test_include_overrides_keyed_maps(): void
    {
        $manifest = <<<'YAML'
version: "0.1"
project:
  name: merge-app
  framework: laravel
includes:
  - override.yaml
auth:
  guards:
    web: session
YAML;

        $override = <<<'YAML'
auth:
  guards:
    web: token
    api: sanctum
YAML;

        file_put_contents($this->tempDir . '/intent.yaml', $manifest);
        file_put_contents($this->tempDir . '/override.yaml', $override);

        $loader = new SpecLoader();
        $result = $loader->load($this->tempDir . '/intent.yaml');

        $this->assertSame('token', $result['spec']->auth->guards['web']);
        $this->assertSame('sanctum', $result['spec']->auth->guards['api']);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*');

        if ($files !== false) {
            foreach ($files as $file) {
                is_dir($file) ? $this->removeDir($file) : @unlink($file);
            }
        }

        @rmdir($dir);
    }
}
