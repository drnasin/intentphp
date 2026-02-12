<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Checks;

use IntentPHP\Guard\Scan\Finding;
use Symfony\Component\Finder\Finder;

class DangerousQueryInputCheck implements CheckInterface
{
    /**
     * Patterns that indicate user input flowing directly into query builder methods.
     * Each key is a human-readable label, each value is a regex pattern.
     *
     * @var array<string, string>
     */
    private const PATTERNS = [
        'orderBy with request input' => '/->orderBy\s*\(\s*\$request\s*->/',
        'where with request input' => '/->where\s*\(\s*\$request\s*->/',
        'whereRaw with request input' => '/->whereRaw\s*\(\s*\$request\s*->/',
        'selectRaw with request input' => '/->selectRaw\s*\(\s*\$request\s*->/',
        'orderByRaw with request input' => '/->orderByRaw\s*\(\s*\$request\s*->/',
        'groupByRaw with request input' => '/->groupByRaw\s*\(\s*\$request\s*->/',
        'havingRaw with request input' => '/->havingRaw\s*\(\s*\$request\s*->/',
        'DB::raw with request input' => '/DB::raw\s*\(\s*.*\$request\s*->/',
        'whereColumn with request input' => '/->whereColumn\s*\(\s*\$request\s*->/',
        'orderBy with direct input variable' => '/->orderBy\s*\(\s*\$(?:sort|order|column|field|dir)/',
        'selectRaw with string concat' => '/->selectRaw\s*\(\s*[\'"].*\.\s*\$/',
        'whereRaw with string concat' => '/->whereRaw\s*\(\s*[\'"].*\.\s*\$/',
        'orderByRaw with string concat' => '/->orderByRaw\s*\(\s*[\'"].*\.\s*\$/',
        // request() helper function patterns
        'orderBy with request() helper' => '/->orderBy\s*\(\s*request\s*\(\)\s*->/',
        'where with request() helper' => '/->where\s*\(\s*request\s*\(\)\s*->/',
        'whereRaw with request() helper' => '/->whereRaw\s*\(\s*request\s*\(\)\s*->/',
        'selectRaw with request() helper' => '/->selectRaw\s*\(\s*request\s*\(\)\s*->/',
        'orderByRaw with request() helper' => '/->orderByRaw\s*\(\s*request\s*\(\)\s*->/',
        'DB::raw with request() helper' => '/DB::raw\s*\(\s*.*request\s*\(\)\s*->/',
    ];

    /**
     * @param string[]|null $onlyFiles When set, only scan these absolute paths
     */
    public function __construct(
        private readonly string $controllersPath,
        private readonly ?array $onlyFiles = null,
    ) {}

    public function name(): string
    {
        return 'dangerous-query-input';
    }

    /** @return Finding[] */
    public function run(): array
    {
        $findings = [];

        foreach ($this->filesToScan() as [$filePath, $contents]) {
            $lines = explode("\n", $contents);

            foreach ($lines as $lineNumber => $lineContent) {
                foreach (self::PATTERNS as $label => $pattern) {
                    if (preg_match($pattern, $lineContent)) {
                        $snippet = trim($lineContent);

                        $findings[] = Finding::high(
                            check: $this->name(),
                            message: "Dangerous query input detected: {$label}.",
                            file: $filePath,
                            line: $lineNumber + 1,
                            context: [
                                'pattern' => $label,
                                'snippet' => $snippet,
                            ],
                            fix_hint: "Never pass raw request input into query builder methods. Validate and whitelist allowed values before use.",
                        );
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * @return iterable<array{0: string, 1: string}>
     */
    private function filesToScan(): iterable
    {
        if ($this->onlyFiles !== null) {
            $controllerDir = rtrim(str_replace('\\', '/', $this->controllersPath), '/');

            foreach ($this->onlyFiles as $file) {
                $normalized = str_replace('\\', '/', $file);

                if (str_ends_with($normalized, '.php')
                    && str_starts_with($normalized, $controllerDir)
                    && is_readable($file)
                ) {
                    yield [$file, (string) file_get_contents($file)];
                }
            }

            return;
        }

        if (! is_dir($this->controllersPath)) {
            return;
        }

        $finder = new Finder();
        $finder->files()->in($this->controllersPath)->name('*.php');

        foreach ($finder as $file) {
            yield [$file->getRealPath(), $file->getContents()];
        }
    }
}
