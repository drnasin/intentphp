<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Console;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use IntentPHP\Guard\AI\AiClientInterface;
use IntentPHP\Guard\AI\FixSuggestionGenerator;
use IntentPHP\Guard\AI\PromptBuilder;
use IntentPHP\Guard\Cache\ScanCache;
use IntentPHP\Guard\Checks\DangerousQueryInputCheck;
use IntentPHP\Guard\Checks\IntentAuthCheck;
use IntentPHP\Guard\Checks\IntentMassAssignmentCheck;
use IntentPHP\Guard\Checks\MassAssignmentCheck;
use IntentPHP\Guard\Checks\RouteAuthorizationCheck;
use IntentPHP\Guard\Checks\RouteProtectionDetector;
use IntentPHP\Guard\Git\GitHelper;
use IntentPHP\Guard\Intent\IntentContext;
use IntentPHP\Guard\Intent\IntentEnricher;
use IntentPHP\Guard\Laravel\ProjectMap;
use IntentPHP\Guard\Report\ConsoleReporter;
use IntentPHP\Guard\Report\GitHubReporter;
use IntentPHP\Guard\Report\JsonReporter;
use IntentPHP\Guard\Report\MarkdownReporter;
use IntentPHP\Guard\Scan\BaselineManager;
use IntentPHP\Guard\Scan\Finding;
use IntentPHP\Guard\Scan\InlineIgnoreManager;
use IntentPHP\Guard\Scan\Scanner;

class GuardScanCommand extends Command
{
    protected $signature = 'guard:scan
        {--format=console : Output format (console, json, github, or md)}
        {--severity=all : Filter by severity (high or all)}
        {--ai : Include AI-generated fix suggestions for HIGH findings}
        {--baseline : Suppress findings that match the saved baseline}
        {--strict : Exit 2 if baseline file is missing (use with --baseline)}
        {--include-suppressed : Include suppressed findings in output}
        {--include-ai-patch : Include AI patch proposals in JSON/MD output}
        {--max=0 : Limit number of displayed findings (0 = unlimited)}
        {--changed : Scan only files changed vs git base}
        {--base= : Git base ref for --changed (default: auto-detect)}
        {--staged : Scan only staged files}
        {--changed-since= : Git ref to compare against}
        {--output= : Write report to file instead of stdout (json, md)}
        {--no-cache : Disable caching for this run}';

    protected $description = 'Scan your Laravel application for common security risks.';

    public function handle(Router $router, AiClientInterface $aiClient): int
    {
        $format = $this->option('format');
        $severity = $this->option('severity');
        $useAi = (bool) $this->option('ai');
        $useBaseline = (bool) $this->option('baseline');
        $strict = (bool) $this->option('strict');
        $includeSuppressed = (bool) $this->option('include-suppressed');
        $includeAiPatch = (bool) $this->option('include-ai-patch');
        $max = (int) $this->option('max');
        $outputPath = $this->option('output');

        if (! in_array($format, ['console', 'json', 'github', 'md'], true)) {
            $this->error("Invalid format: {$format}. Use 'console', 'json', 'github', or 'md'.");
            return self::FAILURE;
        }

        if (! in_array($severity, ['all', 'high'], true)) {
            $this->error("Invalid severity: {$severity}. Use 'all' or 'high'.");
            return self::FAILURE;
        }

        if ($outputPath && ! in_array($format, ['json', 'md'], true)) {
            $this->error("--output is only supported for 'json' and 'md' formats.");
            return self::FAILURE;
        }

        $isQuiet = $format === 'json' || $format === 'github' || $format === 'md';

        // 0. Determine scan mode (full vs incremental)
        $scanMode = 'full';
        $changedFiles = null;

        if ($this->option('staged')) {
            $resolved = $this->resolveChangedFiles('staged');
            $scanMode = $resolved['mode'];
            $changedFiles = $resolved['files'];
        } elseif ($this->option('changed-since')) {
            $resolved = $this->resolveChangedFiles('changed-since', (string) $this->option('changed-since'));
            $scanMode = $resolved['mode'];
            $changedFiles = $resolved['files'];
        } elseif ($this->option('changed')) {
            $base = $this->option('base') ? (string) $this->option('base') : null;
            $resolved = $this->resolveChangedFiles('changed', $base);
            $scanMode = $resolved['mode'];
            $changedFiles = $resolved['files'];
        }

        if (! $isQuiet) {
            $this->info('IntentPHP Guard — scanning your application...');
            if ($scanMode !== 'full') {
                $fileCount = $changedFiles !== null ? count($changedFiles) : 0;
                $this->line("  Mode: {$scanMode} ({$fileCount} file(s))");
            }
            $this->newLine();
        }

        // 0b. Load intent spec (optional)
        $intentContext = $this->loadIntentContext($isQuiet);
        if ($intentContext === false) {
            return self::FAILURE;
        }

        // 1. Run scanner
        $scannerResult = $this->buildScanner($router, $changedFiles, $intentContext);
        $scanner = $scannerResult['scanner'];
        $routeScanMode = $scannerResult['route_scan_mode'];

        if (! $isQuiet && $routeScanMode !== 'full' && $changedFiles !== null) {
            $controllerCount = count(array_filter($changedFiles, function (string $f) {
                $n = str_replace('\\', '/', $f);
                return (bool) preg_match('#/app/Http/Controllers/.+\.php$#', $n);
            }));
            $this->line("  Route scan: {$routeScanMode}" . ($routeScanMode === 'filtered' ? " ({$controllerCount} controller(s) changed)" : ''));
        }

        $findings = $scanner->runAndFilter($severity);

        // Apply route findings filter when in filtered mode
        $routeChecks = ['route-authorization', 'intent-auth'];
        if ($routeScanMode === 'filtered' && $changedFiles !== null) {
            $findings = $this->filterRouteFindings($findings, $changedFiles);
        } elseif ($routeScanMode === 'skipped') {
            $findings = array_values(array_filter(
                $findings,
                fn (Finding $f) => ! in_array($f->check, $routeChecks, true),
            ));
        }

        // 2. Enrich with ProjectMap (with optional cache)
        $cache = $this->buildCache();
        $cacheVersion = $this->computeCacheVersion();

        $projectMap = new ProjectMap($router, $cache, $cacheVersion);
        $findings = $projectMap->enrich($findings);

        // 2b. Enrich mass-assignment findings with intent spec details
        if ($intentContext instanceof IntentContext) {
            $findings = IntentEnricher::enrich($findings, $intentContext->spec);
        }

        // 2c. Print intent context warnings
        if ($intentContext instanceof IntentContext && $intentContext->warnings !== [] && ! $isQuiet) {
            foreach ($intentContext->warnings as $warning) {
                $this->warn("Intent: {$warning}");
            }
            $this->newLine();
        }

        // 3. Apply inline ignores
        /** @var array<string, mixed> $config */
        $config = config('guard', []);
        $allowInlineIgnores = $config['allow_inline_ignores'] ?? true;

        if ($allowInlineIgnores) {
            $inlineManager = new InlineIgnoreManager();
            $findings = $inlineManager->apply($findings);
        }

        // 4. Apply baseline
        if ($useBaseline) {
            $baselinePath = storage_path('guard/baseline.json');
            $baselineManager = new BaselineManager();

            if ($strict && ! file_exists($baselinePath)) {
                $this->error('Baseline file not found: ' . $baselinePath);
                $this->line('Run "php artisan guard:baseline" first to create one.');
                return 2;
            }

            $baselineFingerprints = $baselineManager->load($baselinePath);
            $findings = $baselineManager->suppress($findings, $baselineFingerprints);

            if (! $isQuiet) {
                $suppressedCount = count(array_filter($findings, fn (Finding $f) => $f->suppressed_reason === 'baseline'));
                $this->line("Baseline loaded: {$suppressedCount} finding(s) suppressed.");
                $this->newLine();
            }
        }

        // 5. AI suggestions
        if ($useAi && ! empty($findings)) {
            if (! $isQuiet) {
                $this->line('Running AI fix suggestion engine...');
                $this->newLine();
            }

            $generator = new FixSuggestionGenerator($aiClient, new PromptBuilder());
            $activeFindings = array_filter($findings, fn (Finding $f) => ! $f->isSuppressed());
            $enhanced = $generator->enhance(array_values($activeFindings));

            // Merge enhanced back: replace active findings with enhanced versions
            $enhancedByFp = [];
            foreach ($enhanced as $ef) {
                $enhancedByFp[$ef->fingerprint()] = $ef;
            }

            $findings = array_map(function (Finding $f) use ($enhancedByFp) {
                return $enhancedByFp[$f->fingerprint()] ?? $f;
            }, $findings);
        }

        // 6. Report
        $options = [
            'max' => $max,
            'include_suppressed' => $includeSuppressed,
            'include_ai_patch' => $includeAiPatch,
            'scan_mode' => $scanMode,
            'route_scan_mode' => $routeScanMode,
            'baseline_used' => $useBaseline,
            'changed_file_count' => $changedFiles !== null ? count($changedFiles) : null,
        ];

        if ($outputPath && in_array($format, ['json', 'md'], true)) {
            $this->writeReportToFile($findings, $format, $options, (string) $outputPath);
        } else {
            $this->reportFindings($findings, $format, $options);
        }

        // 7. Exit code: based on active HIGH findings only
        $activeHigh = count(array_filter(
            $findings,
            fn (Finding $f) => $f->severity === 'high' && ! $f->isSuppressed(),
        ));

        return $activeHigh > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{mode: string, files: string[]|null}
     */
    private function resolveChangedFiles(string $type, ?string $ref = null): array
    {
        $git = new GitHelper(base_path());

        if (! $git->isGitRepo()) {
            $this->warn('Not a git repository. Falling back to full scan.');

            return ['mode' => 'full', 'files' => null];
        }

        if ($type === 'staged') {
            return ['mode' => 'staged', 'files' => $git->getStagedFiles()];
        }

        $base = $ref ?? $git->resolveBaseRef();

        return ['mode' => 'changed', 'files' => $git->getChangedFiles($base)];
    }

    /**
     * Load and validate the intent spec, if present.
     *
     * @return IntentContext|null|false  IntentContext on success, null if file missing, false on error
     */
    private function loadIntentContext(bool $isQuiet): IntentContext|null|false
    {
        $specPath = base_path('intent/intent.yaml');
        $result = IntentContext::tryLoad($specPath);

        if ($result['errors'] !== []) {
            foreach ($result['errors'] as $error) {
                $this->error("Intent spec error: {$error}");
            }

            return false;
        }

        $context = $result['context'];

        if ($context === null) {
            return null;
        }

        if (! $isQuiet) {
            $this->line('  Intent spec loaded.');
        }

        return $context;
    }

    /**
     * @param string[]|null $changedFiles
     * @return array{scanner: Scanner, route_scan_mode: string}
     */
    private function buildScanner(Router $router, ?array $changedFiles = null, ?IntentContext $intentContext = null): array
    {
        /** @var array<string, mixed> $config */
        $config = config('guard', []);

        $authMiddlewares = $config['auth_middlewares'] ?? ['auth', 'auth:sanctum'];
        $publicRoutes = $config['public_routes'] ?? [];
        $controllersPath = app_path('Http/Controllers');
        $modelsPath = app_path('Models');

        $routeScanMode = GitHelper::determineRouteScanMode($changedFiles);

        $detector = new RouteProtectionDetector($authMiddlewares);

        $checks = [
            new RouteAuthorizationCheck($router, $authMiddlewares, $publicRoutes, $detector),
            new DangerousQueryInputCheck($controllersPath, $changedFiles),
            new MassAssignmentCheck($modelsPath, $controllersPath, $changedFiles),
        ];

        if ($intentContext !== null) {
            $spec = $intentContext->spec;

            if ($spec->auth->rules !== []) {
                $checks[] = new IntentAuthCheck($router, $spec, $detector);
            }

            if ($spec->data->models !== []) {
                $checks[] = new IntentMassAssignmentCheck($modelsPath, $spec, $intentContext);
            }
        }

        $scanner = new Scanner($checks);

        return ['scanner' => $scanner, 'route_scan_mode' => $routeScanMode];
    }

    /**
     * Filter route-authorization and intent-auth findings to only those whose controller file is in the changed set.
     *
     * @param Finding[] $findings
     * @param string[] $changedFiles
     * @return Finding[]
     */
    private function filterRouteFindings(array $findings, array $changedFiles): array
    {
        $routeChecks = ['route-authorization', 'intent-auth'];
        $basePath = str_replace('\\', '/', rtrim(base_path(), '/\\'));

        // Normalize changed files to repo-relative paths with forward slashes
        $changedRelative = [];
        foreach ($changedFiles as $file) {
            $normalized = str_replace('\\', '/', $file);
            $relative = str_starts_with($normalized, $basePath . '/')
                ? substr($normalized, strlen($basePath) + 1)
                : $normalized;
            $changedRelative[$relative] = true;
        }

        // Build class→repo-relative-file map (one reflection per unique class)
        $classFileMap = [];

        foreach ($findings as $finding) {
            if (! in_array($finding->check, $routeChecks, true)) {
                continue;
            }

            $className = $this->extractControllerClass($finding);

            if ($className === null || isset($classFileMap[$className])) {
                continue;
            }

            try {
                $ref = new \ReflectionClass($className);
                $absPath = $ref->getFileName();

                if ($absPath !== false) {
                    $normalized = str_replace('\\', '/', $absPath);
                    $relative = str_starts_with($normalized, $basePath . '/')
                        ? substr($normalized, strlen($basePath) + 1)
                        : $normalized;
                    $classFileMap[$className] = $relative;
                } else {
                    $classFileMap[$className] = null;
                }
            } catch (\ReflectionException) {
                $classFileMap[$className] = null;
            }
        }

        return array_values(array_filter($findings, function (Finding $finding) use ($classFileMap, $changedRelative, $routeChecks) {
            if (! in_array($finding->check, $routeChecks, true)) {
                return true;
            }

            $className = $this->extractControllerClass($finding);

            // Closures and unresolvable actions are always kept
            if ($className === null) {
                return true;
            }

            $relativeFile = $classFileMap[$className] ?? null;

            if ($relativeFile === null) {
                return true;
            }

            return isset($changedRelative[$relativeFile]);
        }));
    }

    /**
     * Extract the controller FQCN from a finding's action context.
     */
    private function extractControllerClass(Finding $finding): ?string
    {
        $action = $finding->context['action'] ?? null;

        if ($action === null) {
            return null;
        }

        // Array format: [FQCN, method]
        if (is_array($action)) {
            return is_string($action[0] ?? null) ? $action[0] : null;
        }

        if (! is_string($action)) {
            return null;
        }

        // Closure string
        if ($action === 'Closure' || str_starts_with($action, 'Closure')) {
            return null;
        }

        // "FQCN@method" format
        if (str_contains($action, '@')) {
            return explode('@', $action, 2)[0];
        }

        // Invokable controller (class name with no @)
        return $action;
    }

    private function buildCache(): ?ScanCache
    {
        if ($this->option('no-cache')) {
            return null;
        }

        /** @var array<string, mixed> $config */
        $config = config('guard', []);
        $cacheEnabled = (bool) ($config['cache']['enabled'] ?? true);

        if (! $cacheEnabled) {
            return null;
        }

        return new ScanCache(
            cacheDir: storage_path('guard/cache'),
            enabled: true,
        );
    }

    private function computeCacheVersion(): ?string
    {
        $cache = $this->buildCache();

        if ($cache === null) {
            return null;
        }

        $git = new GitHelper(base_path());
        $sha = $git->isGitRepo() ? $git->getHeadSha() : null;

        $mtimesHash = null;
        if ($sha === null) {
            $mtimesPaths = array_filter([
                base_path('routes'),
                app_path('Http/Controllers'),
                app_path('Policies'),
                base_path('app/Providers/AuthServiceProvider.php'),
                base_path('app/Http/Kernel.php'),
            ], fn (string $p) => file_exists($p));

            $mtimesHash = ScanCache::computeMtimesHash($mtimesPaths);
        }

        return ScanCache::computeVersion(
            laravelVersion: app()->version(),
            gitSha: $sha,
            mtimesHash: $mtimesHash,
        );
    }

    /**
     * @param Finding[] $findings
     * @param array<string, mixed> $options
     */
    private function writeReportToFile(array $findings, string $format, array $options, string $outputPath): void
    {
        $content = match ($format) {
            'json' => (new JsonReporter($this))->render($findings, $options),
            'md' => (new MarkdownReporter($this))->render($findings, $options),
            default => '',
        };

        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($outputPath, $content);
        $this->info("Report written to {$outputPath}");
    }

    /**
     * @param Finding[] $findings
     * @param array<string, mixed> $options
     */
    private function reportFindings(array $findings, string $format, array $options = []): void
    {
        match ($format) {
            'json' => (new JsonReporter($this))->report($findings, $options),
            'github' => (new GitHubReporter($this))->report($findings),
            'md' => (new MarkdownReporter($this))->report($findings, $options),
            default => (new ConsoleReporter($this))->report($findings, $options),
        };
    }
}
