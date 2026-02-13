<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Console\Doctor;

use IntentPHP\Guard\AI\Cli\GenericAdapter;
use IntentPHP\Guard\AI\Cli\ProcessRunnerInterface;
use IntentPHP\Guard\AI\Cli\SymfonyProcessRunner;
use IntentPHP\Guard\AI\CliAiClient;

class EnvironmentChecker
{
    private readonly ProcessRunnerInterface $processRunner;

    /**
     * @param array<string, mixed> $aiConfig
     * @param array<string, mixed> $cacheConfig
     */
    public function __construct(
        private readonly string $basePath,
        private readonly string $storagePath,
        private readonly array $aiConfig = [],
        private readonly array $cacheConfig = [],
        ?ProcessRunnerInterface $processRunner = null,
    ) {
        $this->processRunner = $processRunner ?? new SymfonyProcessRunner();
    }

    public function checkLaravelContext(): CheckResult
    {
        $artisanPath = $this->basePath . DIRECTORY_SEPARATOR . 'artisan';

        if (file_exists($artisanPath)) {
            return CheckResult::ok('Laravel Context', "Artisan file found at {$artisanPath}");
        }

        return CheckResult::error('Laravel Context', "Artisan file not found at {$artisanPath}. Is this a Laravel project?");
    }

    /**
     * @return CheckResult[]
     */
    public function checkStorageWritable(): array
    {
        $results = [];
        $subdirs = ['guard', 'guard' . DIRECTORY_SEPARATOR . 'cache', 'guard' . DIRECTORY_SEPARATOR . 'patches'];

        foreach ($subdirs as $subdir) {
            $path = $this->storagePath . DIRECTORY_SEPARATOR . $subdir;

            if (is_dir($path) && is_writable($path)) {
                $results[] = CheckResult::ok('Storage / Writable', "{$path} exists and is writable");
                continue;
            }

            if (! is_dir($path)) {
                $created = @mkdir($path, 0755, true);

                if (! $created) {
                    $error = error_get_last();
                    $reason = $error['message'] ?? 'mkdir failed';
                    $results[] = CheckResult::error('Storage / Writable', "Cannot create {$path}: {$reason}");
                    continue;
                }
            }

            if (! is_writable($path)) {
                $results[] = CheckResult::error('Storage / Writable', "{$path} exists but is not writable");
                continue;
            }

            $results[] = CheckResult::ok('Storage / Writable', "{$path} is writable");
        }

        return $results;
    }

    /**
     * @return CheckResult[]
     */
    public function checkGit(): array
    {
        $results = [];

        // Check git binary
        try {
            $result = $this->processRunner->run(['git', '--version'], '', 5);

            if ($result->isSuccessful()) {
                $version = trim($result->stdout);
                $results[] = CheckResult::ok('Git', "git binary found ({$version})");
            } else {
                $results[] = CheckResult::warn('Git', 'git binary not found. --changed and --staged modes will not work.');
                return $results;
            }
        } catch (\Throwable) {
            $results[] = CheckResult::warn('Git', 'git binary not found. --changed and --staged modes will not work.');
            return $results;
        }

        // Check if inside a git repository
        try {
            $result = $this->processRunner->run(
                ['git', '-C', $this->basePath, 'rev-parse', '--is-inside-work-tree'],
                '',
                5,
            );

            if ($result->isSuccessful() && trim($result->stdout) === 'true') {
                $results[] = CheckResult::ok('Git', 'Repository detected — incremental scanning available.');
            } else {
                $results[] = CheckResult::warn('Git', 'Not a git repository. --changed and --staged modes will not work.');
            }
        } catch (\Throwable) {
            $results[] = CheckResult::warn('Git', 'Not a git repository. --changed and --staged modes will not work.');
        }

        return $results;
    }

    public function checkBaseline(): CheckResult
    {
        $baselinePath = $this->storagePath . DIRECTORY_SEPARATOR . 'guard' . DIRECTORY_SEPARATOR . 'baseline.json';

        if (file_exists($baselinePath)) {
            return CheckResult::ok('Baseline', "Baseline file found at {$baselinePath}");
        }

        return CheckResult::warn('Baseline', 'No baseline file found. Run: php artisan guard:baseline');
    }

    /**
     * @return CheckResult[]
     */
    public function checkAiDriver(): array
    {
        $enabled = (bool) ($this->aiConfig['enabled'] ?? false);
        $driver = (string) ($this->aiConfig['driver'] ?? 'null');

        if (! $enabled || $driver === 'null') {
            return [CheckResult::ok('AI Driver', 'AI is disabled. Enable with GUARD_AI_ENABLED=true.')];
        }

        $results = [];
        $results[] = CheckResult::ok('AI Driver', "AI enabled, driver: {$driver}");

        $cliAvailable = false;
        $openaiAvailable = false;

        // CLI check
        if ($driver === 'cli' || $driver === 'auto') {
            $cliConfig = (array) ($this->aiConfig['cli'] ?? []);
            $binary = (string) ($cliConfig['command'] ?? 'claude');

            $client = new CliAiClient(
                adapter: new GenericAdapter(false),
                runner: $this->processRunner,
                binary: $binary,
                args: '',
                timeout: 5,
            );

            $cliAvailable = $client->isAvailable();

            if ($cliAvailable) {
                $results[] = CheckResult::ok('AI Driver', "CLI tool '{$binary}' found in PATH.");
            } else {
                $results[] = CheckResult::warn('AI Driver', "CLI tool '{$binary}' not found in PATH.");
            }
        }

        // OpenAI check
        if ($driver === 'openai' || $driver === 'auto') {
            $openaiConfig = (array) ($this->aiConfig['openai'] ?? []);
            $apiKey = (string) ($openaiConfig['api_key'] ?? '');
            $model = (string) ($openaiConfig['model'] ?? 'gpt-4.1-mini');
            $baseUrl = (string) ($openaiConfig['base_url'] ?? 'https://api.openai.com/v1');

            $openaiAvailable = $apiKey !== '';

            if ($openaiAvailable) {
                $results[] = CheckResult::ok('AI Driver', "OpenAI API key is set (model: {$model}, endpoint: {$baseUrl}).");
            } else {
                $results[] = CheckResult::warn('AI Driver', 'OpenAI API key not set.');
            }
        }

        // Auto cascade summary
        if ($driver === 'auto') {
            $selected = 'Null';

            if ($cliAvailable) {
                $selected = 'CLI';
            } elseif ($openaiAvailable) {
                $selected = 'OpenAI';
            }

            $results[] = CheckResult::ok('AI Driver', "Auto cascade: CLI → OpenAI → Null. Selected: {$selected}.");
        }

        return $results;
    }

    /**
     * @return CheckResult[]
     */
    public function checkCache(): array
    {
        $enabled = (bool) ($this->cacheConfig['enabled'] ?? true);
        $cachePath = $this->storagePath . DIRECTORY_SEPARATOR . 'guard' . DIRECTORY_SEPARATOR . 'cache';

        $results = [];

        if ($enabled) {
            $results[] = CheckResult::ok('Cache', "Cache enabled. Path: {$cachePath}");
        } else {
            $results[] = CheckResult::ok('Cache', 'Cache disabled via GUARD_CACHE_ENABLED=false.');
        }

        $results[] = CheckResult::ok('Cache', 'Tip: use --no-cache with guard:scan to bypass cache for a single run.');

        return $results;
    }
}
