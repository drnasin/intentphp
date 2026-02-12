<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Git;

use Symfony\Component\Process\Process;

class GitHelper
{
    public function __construct(
        private readonly string $basePath,
    ) {}

    public function isGitRepo(): bool
    {
        return is_dir($this->basePath . DIRECTORY_SEPARATOR . '.git');
    }

    /**
     * Resolve the best default base ref for comparison.
     * Tries: origin/main → origin/master → main → master → HEAD~1
     */
    public function resolveBaseRef(): string
    {
        foreach (['origin/main', 'origin/master', 'main', 'master'] as $ref) {
            if ($this->refExists($ref)) {
                return $ref;
            }
        }

        return 'HEAD~1';
    }

    /**
     * Get files changed between a base ref and HEAD.
     *
     * @return string[] Absolute file paths
     */
    public function getChangedFiles(string $base): array
    {
        // Try three-dot syntax first (merge-base comparison)
        $output = $this->git(['diff', '--name-only', "{$base}...HEAD"]);

        if ($output === null) {
            // Fall back to two-ref syntax (e.g. for HEAD~1)
            $output = $this->git(['diff', '--name-only', $base, 'HEAD']);
        }

        return $output !== null
            ? self::parseFileList($output, $this->basePath)
            : [];
    }

    /**
     * Get staged (cached) files.
     *
     * @return string[] Absolute file paths
     */
    public function getStagedFiles(): array
    {
        $output = $this->git(['diff', '--cached', '--name-only']);

        return $output !== null
            ? self::parseFileList($output, $this->basePath)
            : [];
    }

    public function getHeadSha(): ?string
    {
        $output = $this->git(['rev-parse', 'HEAD']);

        return $output !== null ? trim($output) : null;
    }

    private function refExists(string $ref): bool
    {
        return $this->git(['rev-parse', '--verify', '--quiet', $ref]) !== null;
    }

    /**
     * @param string[] $args
     */
    private function git(array $args): ?string
    {
        try {
            $command = array_merge(['git'], $args);
            $process = new Process($command, $this->basePath);
            $process->setTimeout(15);
            $process->run();

            return $process->isSuccessful() ? $process->getOutput() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Parse git diff --name-only output into absolute paths.
     *
     * @return string[]
     */
    public static function parseFileList(string $output, string $basePath): array
    {
        $basePath = rtrim(str_replace('\\', '/', $basePath), '/');

        $files = array_filter(
            array_map('trim', explode("\n", $output)),
            fn (string $line) => $line !== '',
        );

        return array_values(array_map(function (string $file) use ($basePath) {
            $file = str_replace('\\', '/', $file);

            return $basePath . '/' . $file;
        }, $files));
    }

    /**
     * Check if any of the given files are route files.
     *
     * @param string[] $files Absolute paths
     */
    public static function containsRouteFiles(array $files): bool
    {
        foreach ($files as $file) {
            $normalized = str_replace('\\', '/', $file);

            if (preg_match('#/routes/[^/]+\.php$#', $normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if any of the given files are controller files.
     *
     * @param string[] $files Absolute paths
     */
    public static function containsControllerFiles(array $files): bool
    {
        foreach ($files as $file) {
            $normalized = str_replace('\\', '/', $file);

            if (preg_match('#/app/Http/Controllers/.+\.php$#', $normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine the route scan mode based on changed files.
     *
     * @param string[]|null $changedFiles
     * @return string 'full'|'filtered'|'skipped'
     */
    public static function determineRouteScanMode(?array $changedFiles): string
    {
        if ($changedFiles === null) {
            return 'full';
        }

        if (self::containsRouteFiles($changedFiles)) {
            return 'full';
        }

        if (self::containsControllerFiles($changedFiles)) {
            return 'filtered';
        }

        return 'skipped';
    }
}
