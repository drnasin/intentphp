<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Console;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use IntentPHP\Guard\AI\AiClientInterface;
use IntentPHP\Guard\AI\TestGenerator;
use IntentPHP\Guard\Checks\DangerousQueryInputCheck;
use IntentPHP\Guard\Checks\MassAssignmentCheck;
use IntentPHP\Guard\Checks\RouteAuthorizationCheck;
use IntentPHP\Guard\Scan\Scanner;

class GuardTestGenCommand extends Command
{
    protected $signature = 'guard:testgen
        {--overwrite : Overwrite existing generated test files}';

    protected $description = 'Generate PHPUnit security tests based on guard:scan findings.';

    public function handle(Router $router, AiClientInterface $aiClient): int
    {
        $this->info('IntentPHP Guard — generating security tests...');
        $this->newLine();

        $scanner = $this->buildScanner($router);
        $findings = $scanner->runAndFilter('high');

        if (empty($findings)) {
            $this->info('No HIGH severity findings — nothing to generate.');
            return self::SUCCESS;
        }

        $this->line(count($findings) . ' HIGH finding(s) found. Generating test files...');
        $this->newLine();

        $generator = new TestGenerator($aiClient);
        $files = $generator->generate($findings);

        if (empty($files)) {
            $this->warn('No test templates could be generated for the current findings.');
            return self::SUCCESS;
        }

        $outputDir = base_path('tests/Feature/GuardGenerated');

        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $overwrite = (bool) $this->option('overwrite');
        $written = 0;

        foreach ($files as $filename => $content) {
            $path = $outputDir . DIRECTORY_SEPARATOR . $filename;
            $exists = file_exists($path);

            if ($exists && ! $overwrite) {
                $this->line("  [skipped] tests/Feature/GuardGenerated/{$filename} (already exists, use --overwrite)");
                continue;
            }

            file_put_contents($path, $content);
            $written++;

            $status = $exists ? 'overwritten' : 'created';
            $this->line("  [{$status}] tests/Feature/GuardGenerated/{$filename}");
        }

        $this->newLine();

        if ($written === 0) {
            $this->warn('No test files were written. Use --overwrite to replace existing files.');
        } else {
            $this->info("{$written} test file(s) generated in tests/Feature/GuardGenerated/");
            $this->line('Review the generated tests and adapt to your application.');
        }

        return self::SUCCESS;
    }

    private function buildScanner(Router $router): Scanner
    {
        /** @var array<string, mixed> $config */
        $config = config('guard', []);

        $authMiddlewares = $config['auth_middlewares'] ?? ['auth', 'auth:sanctum'];
        $publicRoutes = $config['public_routes'] ?? [];
        $controllersPath = app_path('Http/Controllers');
        $modelsPath = app_path('Models');

        return new Scanner([
            new RouteAuthorizationCheck($router, $authMiddlewares, $publicRoutes),
            new DangerousQueryInputCheck($controllersPath),
            new MassAssignmentCheck($modelsPath, $controllersPath),
        ]);
    }
}
