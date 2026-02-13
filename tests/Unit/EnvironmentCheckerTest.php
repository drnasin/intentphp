<?php

declare(strict_types=1);

namespace Tests\Unit;

use IntentPHP\Guard\AI\Cli\ProcessResult;
use IntentPHP\Guard\AI\Cli\ProcessRunnerInterface;
use IntentPHP\Guard\Console\Doctor\EnvironmentChecker;
use PHPUnit\Framework\TestCase;

class DoctorFakeProcessRunner implements ProcessRunnerInterface
{
    /** @var ProcessResult[] */
    private array $responses = [];

    public function willReturn(int $exitCode, string $stdout, string $stderr = ''): self
    {
        $this->responses[] = new ProcessResult($exitCode, $stdout, $stderr);

        return $this;
    }

    public function run(array $command, string $stdin, int $timeout): ProcessResult
    {
        if (empty($this->responses)) {
            return new ProcessResult(1, '', 'No fake response configured');
        }

        return array_shift($this->responses);
    }
}

class EnvironmentCheckerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/guard_doctor_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);

        parent::tearDown();
    }

    // ── Laravel Context ──────────────────────────────────────────────

    public function test_laravel_context_ok_when_artisan_exists(): void
    {
        file_put_contents($this->tempDir . '/artisan', '<?php // artisan');

        $checker = new EnvironmentChecker(
            basePath: $this->tempDir,
            storagePath: $this->tempDir,
        );

        $result = $checker->checkLaravelContext();

        $this->assertSame('OK', $result->status);
        $this->assertSame('Laravel Context', $result->label);
        $this->assertFalse($result->isError());
    }

    public function test_laravel_context_error_when_artisan_missing(): void
    {
        $checker = new EnvironmentChecker(
            basePath: $this->tempDir,
            storagePath: $this->tempDir,
        );

        $result = $checker->checkLaravelContext();

        $this->assertSame('ERROR', $result->status);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('not found', $result->message);
    }

    // ── Storage / Writable ───────────────────────────────────────────

    public function test_storage_writable_ok(): void
    {
        $checker = new EnvironmentChecker(
            basePath: $this->tempDir,
            storagePath: $this->tempDir,
        );

        $results = $checker->checkStorageWritable();

        $this->assertCount(3, $results);

        foreach ($results as $result) {
            $this->assertSame('OK', $result->status);
            $this->assertSame('Storage / Writable', $result->label);
        }
    }

    public function test_storage_error_when_path_is_a_file(): void
    {
        $filePath = $this->tempDir . '/not_a_dir';
        file_put_contents($filePath, 'block');

        $checker = new EnvironmentChecker(
            basePath: $this->tempDir,
            storagePath: $filePath,
        );

        $results = $checker->checkStorageWritable();

        $hasError = false;

        foreach ($results as $result) {
            if ($result->isError()) {
                $hasError = true;
                $this->assertStringContainsString('Cannot create', $result->message);
            }
        }

        $this->assertTrue($hasError, 'Expected at least one ERROR result');
    }

    // ── Baseline ─────────────────────────────────────────────────────

    public function test_baseline_ok_when_exists(): void
    {
        $guardDir = $this->tempDir . '/guard';
        mkdir($guardDir, 0755, true);
        file_put_contents($guardDir . '/baseline.json', '[]');

        $checker = new EnvironmentChecker(
            basePath: $this->tempDir,
            storagePath: $this->tempDir,
        );

        $result = $checker->checkBaseline();

        $this->assertSame('OK', $result->status);
        $this->assertStringContainsString('Baseline file found', $result->message);
    }

    public function test_baseline_warn_when_missing(): void
    {
        $checker = new EnvironmentChecker(
            basePath: $this->tempDir,
            storagePath: $this->tempDir,
        );

        $result = $checker->checkBaseline();

        $this->assertSame('WARN', $result->status);
        $this->assertStringContainsString('guard:baseline', $result->message);
    }

    // ── AI Driver ────────────────────────────────────────────────────

    public function test_ai_disabled_reports_ok(): void
    {
        $checker = new EnvironmentChecker(
            basePath: $this->tempDir,
            storagePath: $this->tempDir,
            aiConfig: ['enabled' => false, 'driver' => 'null'],
        );

        $results = $checker->checkAiDriver();

        $this->assertCount(1, $results);
        $this->assertSame('OK', $results[0]->status);
        $this->assertStringContainsString('disabled', $results[0]->message);
    }

    public function test_ai_driver_null_reports_disabled(): void
    {
        $checker = new EnvironmentChecker(
            basePath: $this->tempDir,
            storagePath: $this->tempDir,
            aiConfig: ['enabled' => true, 'driver' => 'null'],
        );

        $results = $checker->checkAiDriver();

        $this->assertCount(1, $results);
        $this->assertStringContainsString('disabled', $results[0]->message);
    }

    public function test_ai_driver_cli_available(): void
    {
        $runner = new DoctorFakeProcessRunner();
        $runner->willReturn(0, '/usr/bin/claude'); // which succeeds

        $checker = new EnvironmentChecker(
            basePath: $this->tempDir,
            storagePath: $this->tempDir,
            aiConfig: [
                'enabled' => true,
                'driver' => 'cli',
                'cli' => ['command' => 'claude'],
            ],
            processRunner: $runner,
        );

        $results = $checker->checkAiDriver();

        $this->assertGreaterThanOrEqual(2, count($results));
        $this->assertSame('OK', $results[1]->status);
        $this->assertStringContainsString('claude', $results[1]->message);
        $this->assertStringContainsString('found in PATH', $results[1]->message);
    }

    public function test_ai_driver_cli_unavailable(): void
    {
        $runner = new DoctorFakeProcessRunner();
        $runner->willReturn(1, '', 'not found'); // which fails

        $checker = new EnvironmentChecker(
            basePath: $this->tempDir,
            storagePath: $this->tempDir,
            aiConfig: [
                'enabled' => true,
                'driver' => 'cli',
                'cli' => ['command' => 'claude'],
            ],
            processRunner: $runner,
        );

        $results = $checker->checkAiDriver();

        $this->assertGreaterThanOrEqual(2, count($results));
        $this->assertSame('WARN', $results[1]->status);
        $this->assertStringContainsString('not found', $results[1]->message);
    }

    public function test_ai_driver_auto_cascade_shows_selected(): void
    {
        $runner = new DoctorFakeProcessRunner();
        $runner->willReturn(0, '/usr/bin/claude'); // CLI available

        $checker = new EnvironmentChecker(
            basePath: $this->tempDir,
            storagePath: $this->tempDir,
            aiConfig: [
                'enabled' => true,
                'driver' => 'auto',
                'cli' => ['command' => 'claude'],
                'openai' => ['api_key' => ''],
            ],
            processRunner: $runner,
        );

        $results = $checker->checkAiDriver();

        $lastResult = $results[count($results) - 1];
        $this->assertStringContainsString('Auto cascade', $lastResult->message);
        $this->assertStringContainsString('Selected: CLI', $lastResult->message);
    }

    public function test_ai_driver_auto_falls_back_to_openai(): void
    {
        $runner = new DoctorFakeProcessRunner();
        $runner->willReturn(1, '', 'not found'); // CLI unavailable

        $checker = new EnvironmentChecker(
            basePath: $this->tempDir,
            storagePath: $this->tempDir,
            aiConfig: [
                'enabled' => true,
                'driver' => 'auto',
                'cli' => ['command' => 'claude'],
                'openai' => ['api_key' => 'sk-test-key', 'model' => 'gpt-4.1-mini'],
            ],
            processRunner: $runner,
        );

        $results = $checker->checkAiDriver();

        $lastResult = $results[count($results) - 1];
        $this->assertStringContainsString('Selected: OpenAI', $lastResult->message);
    }

    public function test_ai_driver_auto_falls_back_to_null(): void
    {
        $runner = new DoctorFakeProcessRunner();
        $runner->willReturn(1, '', 'not found'); // CLI unavailable

        $checker = new EnvironmentChecker(
            basePath: $this->tempDir,
            storagePath: $this->tempDir,
            aiConfig: [
                'enabled' => true,
                'driver' => 'auto',
                'cli' => ['command' => 'claude'],
                'openai' => ['api_key' => ''],
            ],
            processRunner: $runner,
        );

        $results = $checker->checkAiDriver();

        $lastResult = $results[count($results) - 1];
        $this->assertStringContainsString('Selected: Null', $lastResult->message);
    }

    public function test_ai_missing_config_does_not_crash(): void
    {
        $checker = new EnvironmentChecker(
            basePath: $this->tempDir,
            storagePath: $this->tempDir,
            aiConfig: [],
        );

        $results = $checker->checkAiDriver();

        $this->assertNotEmpty($results);
        $this->assertStringContainsString('disabled', $results[0]->message);
    }

    // ── Cache ────────────────────────────────────────────────────────

    public function test_cache_enabled_includes_path(): void
    {
        $checker = new EnvironmentChecker(
            basePath: $this->tempDir,
            storagePath: $this->tempDir,
            cacheConfig: ['enabled' => true],
        );

        $results = $checker->checkCache();

        $this->assertCount(2, $results);
        $this->assertSame('OK', $results[0]->status);
        $this->assertStringContainsString('Cache enabled', $results[0]->message);
        $this->assertStringContainsString('cache', $results[0]->message);
    }

    public function test_cache_disabled_message(): void
    {
        $checker = new EnvironmentChecker(
            basePath: $this->tempDir,
            storagePath: $this->tempDir,
            cacheConfig: ['enabled' => false],
        );

        $results = $checker->checkCache();

        $this->assertSame('OK', $results[0]->status);
        $this->assertStringContainsString('disabled', $results[0]->message);
    }

    public function test_cache_tip_mentions_no_cache(): void
    {
        $checker = new EnvironmentChecker(
            basePath: $this->tempDir,
            storagePath: $this->tempDir,
            cacheConfig: ['enabled' => true],
        );

        $results = $checker->checkCache();

        $this->assertSame('OK', $results[1]->status);
        $this->assertStringContainsString('--no-cache', $results[1]->message);
    }

    // ── Git ──────────────────────────────────────────────────────────

    public function test_git_available_and_repo(): void
    {
        $runner = new DoctorFakeProcessRunner();
        $runner->willReturn(0, 'git version 2.43.0'); // git --version
        $runner->willReturn(0, 'true'); // rev-parse

        $checker = new EnvironmentChecker(
            basePath: $this->tempDir,
            storagePath: $this->tempDir,
            processRunner: $runner,
        );

        $results = $checker->checkGit();

        $this->assertCount(2, $results);
        $this->assertSame('OK', $results[0]->status);
        $this->assertStringContainsString('git binary found', $results[0]->message);
        $this->assertSame('OK', $results[1]->status);
        $this->assertStringContainsString('Repository detected', $results[1]->message);
    }

    public function test_git_not_available(): void
    {
        $runner = new DoctorFakeProcessRunner();
        $runner->willReturn(1, '', 'not found');

        $checker = new EnvironmentChecker(
            basePath: $this->tempDir,
            storagePath: $this->tempDir,
            processRunner: $runner,
        );

        $results = $checker->checkGit();

        $this->assertCount(1, $results);
        $this->assertSame('WARN', $results[0]->status);
        $this->assertFalse($results[0]->isError());
    }

    public function test_git_available_but_not_repo(): void
    {
        $runner = new DoctorFakeProcessRunner();
        $runner->willReturn(0, 'git version 2.43.0'); // git --version
        $runner->willReturn(128, '', 'fatal: not a git repository'); // rev-parse fails

        $checker = new EnvironmentChecker(
            basePath: $this->tempDir,
            storagePath: $this->tempDir,
            processRunner: $runner,
        );

        $results = $checker->checkGit();

        $this->assertCount(2, $results);
        $this->assertSame('OK', $results[0]->status);
        $this->assertSame('WARN', $results[1]->status);
        $this->assertFalse($results[1]->isError());
        $this->assertStringContainsString('Not a git repository', $results[1]->message);
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
