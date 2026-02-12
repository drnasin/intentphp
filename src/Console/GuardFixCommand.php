<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Console;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use IntentPHP\Guard\AI\AiClientInterface;
use IntentPHP\Guard\AI\PromptBuilder;
use IntentPHP\Guard\Git\GitHelper;
use IntentPHP\Guard\Checks\DangerousQueryInputCheck;
use IntentPHP\Guard\Checks\MassAssignmentCheck;
use IntentPHP\Guard\Checks\RouteAuthorizationCheck;
use IntentPHP\Guard\Patch\AiPatchGenerator;
use IntentPHP\Guard\Patch\Patch;
use IntentPHP\Guard\Patch\PatchBuilder;
use IntentPHP\Guard\Patch\Templates\DangerousQueryPatchTemplate;
use IntentPHP\Guard\Patch\Templates\MassAssignmentPatchTemplate;
use IntentPHP\Guard\Patch\Templates\PatchTemplateInterface;
use IntentPHP\Guard\Patch\Templates\RouteAuthPatchTemplate;
use IntentPHP\Guard\Scan\Finding;
use IntentPHP\Guard\Scan\Scanner;

class GuardFixCommand extends Command
{
    protected $signature = 'guard:fix
        {--ai : Use AI to generate patches when templates cannot}
        {--changed : Fix only changed files vs git base}
        {--base= : Git base ref for --changed (default: auto-detect)}
        {--staged : Fix only staged files}
        {--changed-since= : Git ref to compare against}
        {--no-cache : Disable caching for this run}';

    protected $description = 'Generate safe patch proposals for HIGH severity findings.';

    public function handle(Router $router, AiClientInterface $aiClient): int
    {
        $useAi = (bool) $this->option('ai');

        $this->info('IntentPHP Guard — generating fix patches...');
        $this->newLine();

        $changedFiles = $this->resolveChangedFiles();
        $scannerResult = $this->buildScanner($router, $changedFiles);
        $scanner = $scannerResult['scanner'];
        $routeScanMode = $scannerResult['route_scan_mode'];
        $findings = $scanner->runAndFilter('high');

        // Apply route findings filter when in filtered mode
        if ($routeScanMode === 'filtered' && $changedFiles !== null) {
            $findings = $this->filterRouteFindings($findings, $changedFiles);
        } elseif ($routeScanMode === 'skipped') {
            $findings = array_values(array_filter(
                $findings,
                fn (Finding $f) => $f->check !== 'route-authorization',
            ));
        }

        if (empty($findings)) {
            $this->info('No HIGH severity findings — nothing to patch.');
            return self::SUCCESS;
        }

        $this->line(count($findings) . ' HIGH finding(s) found. Building patches...');
        $this->newLine();

        $patchBuilder = new PatchBuilder();
        $templates = $this->buildTemplates($patchBuilder);

        $aiGenerator = null;
        if ($useAi) {
            $aiGenerator = new AiPatchGenerator($aiClient, new PromptBuilder(), $patchBuilder);

            if (! $aiClient->isAvailable()) {
                $this->warn('AI requested but no API key configured. Falling back to templates only.');
                $this->newLine();
                $aiGenerator = null;
            }
        }

        $outputDir = storage_path('guard/patches');
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $patchCount = 0;
        $noteCount = 0;

        foreach ($findings as $i => $finding) {
            $template = $templates[$finding->check] ?? null;
            $patch = $template?->generate($finding);

            // Fall back to AI patch generation if template couldn't produce a patch
            if ($patch === null && $aiGenerator !== null) {
                $noteOut = null;
                $patch = $aiGenerator->generate($finding, $noteOut);

                if ($patch !== null) {
                    // Mark AI-generated patches
                    $filename = $this->patchFilename($finding, $i);
                    $path = $outputDir . DIRECTORY_SEPARATOR . $filename;

                    file_put_contents($path, $patch->diff);
                    $patchCount++;

                    $this->line(sprintf(
                        '  <fg=cyan>[ai-patch]</> %s',
                        $this->describeFinding($finding),
                    ));
                    $this->line("          → storage/guard/patches/{$filename}");
                    continue;
                }

                // AI returned a note instead of a structured patch
                if ($noteOut !== null) {
                    $noteFilename = $this->patchFilename($finding, $i);
                    $noteFilename = str_replace('.diff', '.md', $noteFilename);
                    $notePath = $outputDir . DIRECTORY_SEPARATOR . $noteFilename;

                    file_put_contents($notePath, $noteOut);
                    $noteCount++;

                    $this->line(sprintf(
                        '  <fg=magenta>[ai-note]</> %s',
                        $this->describeFinding($finding),
                    ));
                    $this->line("          → storage/guard/patches/{$noteFilename}");
                    continue;
                }
            }

            if ($patch === null) {
                $this->line(sprintf(
                    '  <fg=yellow>[skip]</> %s — could not generate patch',
                    $this->describeFinding($finding),
                ));
                continue;
            }

            $filename = $this->patchFilename($finding, $i);
            $path = $outputDir . DIRECTORY_SEPARATOR . $filename;

            file_put_contents($path, $patch->diff);
            $patchCount++;

            $this->line(sprintf(
                '  <fg=green>[patch]</> %s',
                $this->describeFinding($finding),
            ));
            $this->line("          → storage/guard/patches/{$filename}");
        }

        $this->newLine();

        if ($patchCount === 0 && $noteCount === 0) {
            $this->warn('No patches could be generated for the current findings.');
            return self::SUCCESS;
        }

        if ($patchCount > 0) {
            $this->info("{$patchCount} patch(es) written to storage/guard/patches/");
            $this->line('Review each .diff file and apply manually: git apply storage/guard/patches/<file>.diff');
        }

        if ($noteCount > 0) {
            $this->line("{$noteCount} AI note(s) written — review the .md files for guidance.");
        }

        return self::FAILURE;
    }

    private function describeFinding(Finding $finding): string
    {
        $location = $finding->file
            ? basename($finding->file) . ($finding->line ? ":{$finding->line}" : '')
            : '';

        return "[{$finding->check}] {$finding->message}" . ($location ? " ({$location})" : '');
    }

    private function patchFilename(Finding $finding, int $index): string
    {
        $parts = [
            str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
            str_replace('-', '_', $finding->check),
        ];

        if ($finding->file) {
            $parts[] = pathinfo(basename($finding->file), PATHINFO_FILENAME);
        }

        if ($finding->line) {
            $parts[] = 'L' . $finding->line;
        }

        return implode('_', $parts) . '.diff';
    }

    /**
     * @return array<string, PatchTemplateInterface>
     */
    private function buildTemplates(PatchBuilder $patchBuilder): array
    {
        return [
            'route-authorization' => new RouteAuthPatchTemplate($patchBuilder),
            'dangerous-query-input' => new DangerousQueryPatchTemplate($patchBuilder),
            'mass-assignment' => new MassAssignmentPatchTemplate($patchBuilder),
        ];
    }

    /**
     * @return string[]|null
     */
    private function resolveChangedFiles(): ?array
    {
        if (! $this->option('staged') && ! $this->option('changed') && ! $this->option('changed-since')) {
            return null;
        }

        $git = new GitHelper(base_path());

        if (! $git->isGitRepo()) {
            $this->warn('Not a git repository. Using full scan.');

            return null;
        }

        if ($this->option('staged')) {
            $files = $git->getStagedFiles();
            $this->line('Scanning ' . count($files) . ' staged file(s).');
            $this->newLine();

            return $files;
        }

        $base = (string) ($this->option('changed-since') ?? $this->option('base') ?? '');

        if ($base === '') {
            $base = $git->resolveBaseRef();
        }

        $files = $git->getChangedFiles($base);
        $this->line("Scanning " . count($files) . " changed file(s) vs {$base}.");
        $this->newLine();

        return $files;
    }

    /**
     * @param string[]|null $changedFiles
     * @return array{scanner: Scanner, route_scan_mode: string}
     */
    private function buildScanner(Router $router, ?array $changedFiles = null): array
    {
        /** @var array<string, mixed> $config */
        $config = config('guard', []);

        $authMiddlewares = $config['auth_middlewares'] ?? ['auth', 'auth:sanctum'];
        $publicRoutes = $config['public_routes'] ?? [];
        $controllersPath = app_path('Http/Controllers');
        $modelsPath = app_path('Models');

        $routeScanMode = GitHelper::determineRouteScanMode($changedFiles);

        $scanner = new Scanner([
            new RouteAuthorizationCheck($router, $authMiddlewares, $publicRoutes),
            new DangerousQueryInputCheck($controllersPath, $changedFiles),
            new MassAssignmentCheck($modelsPath, $controllersPath, $changedFiles),
        ]);

        return ['scanner' => $scanner, 'route_scan_mode' => $routeScanMode];
    }

    /**
     * Filter route-authorization findings to only those whose controller file is in the changed set.
     *
     * @param Finding[] $findings
     * @param string[] $changedFiles
     * @return Finding[]
     */
    private function filterRouteFindings(array $findings, array $changedFiles): array
    {
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
            if ($finding->check !== 'route-authorization') {
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

        return array_values(array_filter($findings, function (Finding $finding) use ($classFileMap, $changedRelative) {
            if ($finding->check !== 'route-authorization') {
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
}
