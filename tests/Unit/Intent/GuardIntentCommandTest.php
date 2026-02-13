<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Intent;

use IntentPHP\Guard\GuardServiceProvider;
use Orchestra\Testbench\TestCase;

class GuardIntentCommandTest extends TestCase
{
    private string $tempDir;

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [GuardServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/guard_intent_cmd_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    // ── validate ─────────────────────────────────────────────────────

    public function test_validate_with_missing_intent_dir_shows_error(): void
    {
        $this->artisan('guard:intent', [
            'action' => 'validate',
            '--path' => $this->tempDir . '/nonexistent',
        ])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    }

    public function test_validate_with_valid_spec_shows_ok(): void
    {
        $this->writeValidSpec();

        $this->artisan('guard:intent', [
            'action' => 'validate',
            '--path' => $this->tempDir,
        ])
            ->expectsOutputToContain('valid')
            ->assertExitCode(0);
    }

    public function test_validate_with_invalid_spec_shows_errors(): void
    {
        file_put_contents($this->tempDir . '/intent.yaml', "version: \"9.9\"\nproject:\n  name: bad\n  framework: laravel\n");

        $this->artisan('guard:intent', [
            'action' => 'validate',
            '--path' => $this->tempDir,
        ])
            ->expectsOutputToContain('error')
            ->assertExitCode(1);
    }

    // ── init ─────────────────────────────────────────────────────────

    public function test_init_creates_scaffold_files(): void
    {
        $this->artisan('guard:intent', [
            'action' => 'init',
            '--path' => $this->tempDir,
        ])
            ->expectsOutputToContain('initialized')
            ->assertExitCode(0);

        $this->assertFileExists($this->tempDir . '/intent.yaml');
        $this->assertFileExists($this->tempDir . '/auth.yaml');
        $this->assertFileExists($this->tempDir . '/data.yaml');
        $this->assertFileExists($this->tempDir . '/baselines.yaml');
    }

    public function test_init_does_not_overwrite_without_force(): void
    {
        file_put_contents($this->tempDir . '/intent.yaml', 'existing');

        $this->artisan('guard:intent', [
            'action' => 'init',
            '--path' => $this->tempDir,
        ])
            ->expectsOutputToContain('already exists')
            ->assertExitCode(1);

        $this->assertSame('existing', file_get_contents($this->tempDir . '/intent.yaml'));
    }

    public function test_init_with_force_overwrites(): void
    {
        file_put_contents($this->tempDir . '/intent.yaml', 'old');

        $this->artisan('guard:intent', [
            'action' => 'init',
            '--path' => $this->tempDir,
            '--force' => true,
        ])
            ->expectsOutputToContain('initialized')
            ->assertExitCode(0);

        $this->assertNotSame('old', file_get_contents($this->tempDir . '/intent.yaml'));
    }

    // ── show ─────────────────────────────────────────────────────────

    public function test_show_dumps_resolved_yaml(): void
    {
        $this->writeValidSpec();

        $exitCode = \Illuminate\Support\Facades\Artisan::call('guard:intent', [
            'action' => 'show',
            '--path' => $this->tempDir,
        ]);

        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('version', $output);
        $this->assertStringContainsString('project', $output);
    }

    // ── invalid action ───────────────────────────────────────────────

    public function test_invalid_action_shows_error(): void
    {
        $this->artisan('guard:intent', [
            'action' => 'nope',
            '--path' => $this->tempDir,
        ])
            ->expectsOutputToContain('Unknown action')
            ->assertExitCode(1);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function writeValidSpec(): void
    {
        $yaml = <<<'YAML'
version: "0.1"
project:
  name: test-app
  framework: laravel
defaults:
  authMode: deny_by_default
  baselineRequireExpiry: false
  baselineExpiredIsError: false
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
        guard: web
YAML;
        file_put_contents($this->tempDir . '/intent.yaml', $yaml);
    }

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
