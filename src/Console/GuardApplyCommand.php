<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Console;

use Illuminate\Console\Command;

class GuardApplyCommand extends Command
{
    protected $signature = 'guard:apply
        {patch : Path to the .diff patch file}
        {--dry-run : Only check if the patch applies cleanly (default behavior)}';

    protected $description = 'Validate and show instructions for applying a Guard patch file.';

    public function handle(): int
    {
        $patchPath = $this->argument('patch');

        // Resolve relative paths from storage/guard/patches/
        if (! file_exists($patchPath)) {
            $storagePath = storage_path('guard/patches/' . $patchPath);
            if (file_exists($storagePath)) {
                $patchPath = $storagePath;
            }
        }

        if (! file_exists($patchPath)) {
            $this->error("Patch file not found: {$patchPath}");
            $this->line('Available patches:');
            $this->listPatches();
            return self::FAILURE;
        }

        if (! str_ends_with($patchPath, '.diff') && ! str_ends_with($patchPath, '.patch')) {
            $this->error('Expected a .diff or .patch file.');
            return self::FAILURE;
        }

        $this->info('IntentPHP Guard — patch validator');
        $this->newLine();

        // Show patch contents
        $contents = file_get_contents($patchPath);
        $lineCount = substr_count($contents, "\n");
        $this->line("Patch: {$patchPath} ({$lineCount} lines)");
        $this->newLine();

        // Check if we're in a git repo
        $isGitRepo = is_dir(base_path('.git'));

        if (! $isGitRepo) {
            $this->warn('Not a git repository. Cannot validate patch application.');
            $this->newLine();
            $this->line('To apply manually, review the diff and make the changes by hand:');
            $this->newLine();
            $this->line($contents);
            return self::SUCCESS;
        }

        // Dry-run: check if patch applies cleanly
        $escapedPath = escapeshellarg($patchPath);
        $basePath = escapeshellarg(base_path());
        $checkCmd = "git -C {$basePath} apply --check {$escapedPath} 2>&1";

        exec($checkCmd, $output, $exitCode);

        if ($exitCode === 0) {
            $this->info('Patch applies cleanly.');
        } else {
            $this->warn('Patch may not apply cleanly:');
            foreach ($output as $line) {
                $this->line("  {$line}");
            }
        }

        $this->newLine();
        $this->line('<fg=white;options=bold>To apply this patch, run:</>');
        $this->newLine();
        $this->line("  git apply {$escapedPath}");
        $this->newLine();
        $this->line('Review the changes with: git diff');

        return self::SUCCESS;
    }

    private function listPatches(): void
    {
        $dir = storage_path('guard/patches');

        if (! is_dir($dir)) {
            $this->line('  (no patches directory found)');
            return;
        }

        $files = glob($dir . '/*.diff');

        if (empty($files)) {
            $this->line('  (no patches found — run guard:fix first)');
            return;
        }

        foreach ($files as $file) {
            $this->line('  ' . basename($file));
        }
    }
}
