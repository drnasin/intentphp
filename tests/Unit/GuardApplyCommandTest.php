<?php

declare(strict_types=1);

namespace Tests\Unit;

use IntentPHP\Guard\GuardServiceProvider;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Process\Process;

class GuardApplyCommandTest extends TestCase
{
    private string $tmpDir;

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [GuardServiceProvider::class];
    }

    /**
     * Called by Orchestra Testbench before setUp() — must not access $tmpDir
     * unless it is already set, so we set it here and create the directory.
     */
    protected function getEnvironmentSetUp($app): void
    {
        // Skip when git is absent — we cannot configure a real path
        $check = new Process(['git', '--version']);
        $check->run();
        if (! $check->isSuccessful()) {
            return;
        }

        if (! isset($this->tmpDir)) {
            $this->tmpDir = sys_get_temp_dir() . '/guard_apply_test_' . spl_object_id($this);
        }

        $app->setBasePath($this->tmpDir);
    }

    protected function setUp(): void
    {
        // Skip when git is absent
        $check = new Process(['git', '--version']);
        $check->run();
        if (! $check->isSuccessful()) {
            $this->markTestSkipped('git not available');
        }

        parent::setUp();

        // Ensure tmpDir exists (getEnvironmentSetUp may have set it already)
        if (! isset($this->tmpDir)) {
            $this->tmpDir = sys_get_temp_dir() . '/guard_apply_test_' . spl_object_id($this);
        }

        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }

        // Init a real git repo with one committed file
        foreach ([
            ['git', 'init'],
            ['git', 'config', 'user.email', 'test@example.com'],
            ['git', 'config', 'user.name', 'Test'],
        ] as $cmd) {
            $p = new Process($cmd, $this->tmpDir);
            $p->mustRun();
        }

        file_put_contents($this->tmpDir . '/hello.txt', "line one\nline two\n");
        (new Process(['git', 'add', 'hello.txt'], $this->tmpDir))->mustRun();
        (new Process(['git', 'commit', '-m', 'init'], $this->tmpDir))->mustRun();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (isset($this->tmpDir) && is_dir($this->tmpDir)) {
            $this->removeDirectory($this->tmpDir);
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function removeDirectory(string $dir): void
    {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                // Git object files are read-only on Windows; chmod before unlink
                chmod($path, 0666);
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /** Write a patch file inside tmpDir and return its absolute path. */
    private function writePatch(string $content): string
    {
        $path = $this->tmpDir . '/test.diff';
        file_put_contents($path, $content);
        return $path;
    }

    // ── tests ──────────────────────────────────────────────────────────────

    public function test_valid_patch_reports_applies_cleanly(): void
    {
        $patch = implode("\n", [
            'diff --git a/hello.txt b/hello.txt',
            'index 0000000..1111111 100644',
            '--- a/hello.txt',
            '+++ b/hello.txt',
            '@@ -1,2 +1,3 @@',
            ' line one',
            ' line two',
            '+line three',
            '',
        ]);

        $patchPath = $this->writePatch($patch);

        $this->artisan('guard:apply', ['patch' => $patchPath])
            ->expectsOutputToContain('Patch applies cleanly')
            ->assertExitCode(0);
    }

    public function test_invalid_patch_reports_failure_message(): void
    {
        // References a file that was never added to the repo
        $patch = implode("\n", [
            'diff --git a/does-not-exist.txt b/does-not-exist.txt',
            'index 0000000..1111111 100644',
            '--- a/does-not-exist.txt',
            '+++ b/does-not-exist.txt',
            '@@ -1,0 +1,1 @@',
            '+new content',
            '',
        ]);

        $patchPath = $this->writePatch($patch);

        $this->artisan('guard:apply', ['patch' => $patchPath])
            ->expectsOutputToContain('Patch may not apply cleanly')
            ->assertExitCode(0);
    }
}
