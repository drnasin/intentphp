<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Console;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use IntentPHP\Guard\Checks\DangerousQueryInputCheck;
use IntentPHP\Guard\Checks\MassAssignmentCheck;
use IntentPHP\Guard\Checks\RouteAuthorizationCheck;
use IntentPHP\Guard\Scan\BaselineManager;
use IntentPHP\Guard\Scan\Scanner;

class GuardBaselineCommand extends Command
{
    protected $signature = 'guard:baseline
        {--severity=all : Which severities to include in baseline (high or all)}';

    protected $description = 'Save current scan findings as a baseline for future comparison.';

    public function handle(Router $router): int
    {
        $severity = $this->option('severity');

        if (! in_array($severity, ['all', 'high'], true)) {
            $this->error("Invalid severity: {$severity}. Use 'all' or 'high'.");
            return self::FAILURE;
        }

        $this->info('IntentPHP Guard — creating baseline...');
        $this->newLine();

        $scanner = $this->buildScanner($router);
        $findings = $scanner->runAndFilter($severity);

        $baselinePath = storage_path('guard/baseline.json');
        $manager = new BaselineManager();
        $count = $manager->save($findings, $baselinePath);

        $this->info("{$count} finding(s) saved to baseline.");
        $this->line("  Path: {$baselinePath}");
        $this->newLine();
        $this->line('Future scans with --baseline will suppress these findings and only report new ones.');

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
