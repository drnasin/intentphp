<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Console;

use Illuminate\Console\Command;
use IntentPHP\Guard\Intent\IntentScaffold;
use IntentPHP\Guard\Intent\SpecLoader;
use IntentPHP\Guard\Intent\SpecValidator;
use Symfony\Component\Yaml\Yaml;

class GuardIntentCommand extends Command
{
    protected $signature = 'guard:intent
        {action : Action to perform (validate, init, show)}
        {--path= : Path to intent directory (default: base_path("intent"))}
        {--force : Overwrite existing files when running init}';

    protected $description = 'Manage the IntentPHP security spec.';

    public function handle(): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'validate' => $this->runValidate(),
            'init' => $this->runInit(),
            'show' => $this->runShow(),
            default => $this->invalidAction($action),
        };
    }

    private function runValidate(): int
    {
        $rootPath = $this->resolveRootPath();

        if (! file_exists($rootPath)) {
            $this->error("Intent spec not found at {$rootPath}");
            $this->line('Run <fg=cyan>php artisan guard:intent init</> to create one.');
            return self::FAILURE;
        }

        $loader = new SpecLoader();

        try {
            $result = $loader->load($rootPath);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        foreach ($result['warnings'] as $warning) {
            $this->warn("  [WARN] {$warning}");
        }

        $validator = new SpecValidator();
        $validation = $validator->validate($result['spec']);

        foreach ($validation['warnings'] as $warning) {
            $this->warn("  [WARN] {$warning}");
        }

        if ($validation['errors'] !== []) {
            $this->newLine();
            foreach ($validation['errors'] as $error) {
                $this->line("  <fg=red>[ERROR]</> {$error}");
            }
            $this->newLine();
            $this->error(count($validation['errors']) . ' error(s) found.');
            return self::FAILURE;
        }

        $warningCount = count($result['warnings']) + count($validation['warnings']);
        $this->info("Intent spec is valid. ({$warningCount} warning(s))");

        return self::SUCCESS;
    }

    private function runInit(): int
    {
        $intentDir = $this->resolveIntentDir();
        $rootPath = $intentDir . DIRECTORY_SEPARATOR . 'intent.yaml';
        $force = (bool) $this->option('force');

        if (file_exists($rootPath) && ! $force) {
            $this->error("Intent spec already exists at {$intentDir}");
            $this->line('Use <fg=cyan>--force</> to overwrite.');
            return self::FAILURE;
        }

        if (! is_dir($intentDir)) {
            @mkdir($intentDir, 0755, true);
        }

        $scaffold = new IntentScaffold();

        foreach ($scaffold->getFiles() as $filename => $content) {
            $filePath = $intentDir . DIRECTORY_SEPARATOR . $filename;
            file_put_contents($filePath, $content . "\n");
            $this->line("  <fg=green>Created</> {$filePath}");
        }

        $this->newLine();
        $this->info("Intent spec initialized at {$intentDir}");
        $this->line('Run <fg=cyan>php artisan guard:intent validate</> to check it.');

        return self::SUCCESS;
    }

    private function runShow(): int
    {
        $rootPath = $this->resolveRootPath();

        if (! file_exists($rootPath)) {
            $this->error("Intent spec not found at {$rootPath}");
            return self::FAILURE;
        }

        $loader = new SpecLoader();

        try {
            $result = $loader->load($rootPath);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $yaml = Yaml::dump($result['spec']->toArray(), 10, 2);
        $this->line($yaml);

        return self::SUCCESS;
    }

    private function invalidAction(string $action): int
    {
        $this->error("Unknown action: {$action}");
        $this->line('Valid actions: validate, init, show');

        return self::FAILURE;
    }

    private function resolveIntentDir(): string
    {
        return $this->option('path') ?: base_path('intent');
    }

    private function resolveRootPath(): string
    {
        return $this->resolveIntentDir() . DIRECTORY_SEPARATOR . 'intent.yaml';
    }
}
