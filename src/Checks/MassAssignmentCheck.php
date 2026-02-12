<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Checks;

use IntentPHP\Guard\Scan\Finding;
use Symfony\Component\Finder\Finder;

class MassAssignmentCheck implements CheckInterface
{
    /**
     * @param string[]|null $onlyFiles When set, only scan these controller files (models always get full scan)
     */
    public function __construct(
        private readonly string $modelsPath,
        private readonly string $controllersPath,
        private readonly ?array $onlyFiles = null,
    ) {}

    public function name(): string
    {
        return 'mass-assignment';
    }

    /** @return Finding[] */
    public function run(): array
    {
        $unsafeModels = $this->findUnsafeModels();
        $findings = $this->scanControllersForMassAssignment($unsafeModels);

        return $findings;
    }

    /**
     * Find models that are unprotected: no $fillable, or $guarded = [].
     *
     * @return array<string, array{file: string, reason: string}>
     */
    private function findUnsafeModels(): array
    {
        $unsafe = [];

        if (! is_dir($this->modelsPath)) {
            return $unsafe;
        }

        $finder = new Finder();
        $finder->files()->in($this->modelsPath)->name('*.php');

        foreach ($finder as $file) {
            $contents = $file->getContents();
            $className = $this->extractClassName($contents);

            if ($className === null) {
                continue;
            }

            if (! $this->extendsModel($contents)) {
                continue;
            }

            $hasFillable = (bool) preg_match('/\$fillable\s*=\s*\[/', $contents);
            $hasEmptyGuarded = (bool) preg_match('/\$guarded\s*=\s*\[\s*\]/', $contents);

            if ($hasEmptyGuarded) {
                $unsafe[$className] = [
                    'file' => $file->getRealPath(),
                    'reason' => '$guarded is set to an empty array — all attributes are mass assignable',
                ];
            } elseif (! $hasFillable) {
                $unsafe[$className] = [
                    'file' => $file->getRealPath(),
                    'reason' => 'No $fillable property defined',
                ];
            }
        }

        return $unsafe;
    }

    /**
     * Scan controllers for Model::create($request->all()), ->update($request->all()), ->fill($request->all()).
     *
     * @param array<string, array{file: string, reason: string}> $unsafeModels
     * @return Finding[]
     */
    private function scanControllersForMassAssignment(array $unsafeModels): array
    {
        $findings = [];

        if (! is_dir($this->controllersPath)) {
            return $findings;
        }

        if (empty($unsafeModels)) {
            return $findings;
        }

        $modelNames = array_keys($unsafeModels);
        $modelPattern = implode('|', array_map('preg_quote', $modelNames));

        $highPatterns = [
            "create with \$request->all()" => '/(' . $modelPattern . ')::create\s*\(\s*\$request->all\(\)/',
            "update with \$request->all()" => '/->update\s*\(\s*\$request->all\(\)/',
            "fill with \$request->all()" => '/->fill\s*\(\s*\$request->all\(\)/',
            "create with \$request->input()" => '/(' . $modelPattern . ')::create\s*\(\s*\$request->input\(\)/',
            "update with \$request->input()" => '/->update\s*\(\s*\$request->input\(\)/',
            "fill with \$request->input()" => '/->fill\s*\(\s*\$request->input\(\)/',
            "create with request()->all()" => '/(' . $modelPattern . ')::create\s*\(\s*request\(\)->all\(\)/',
            "update with request()->all()" => '/->update\s*\(\s*request\(\)->all\(\)/',
            "fill with request()->all()" => '/->fill\s*\(\s*request\(\)->all\(\)/',
        ];

        $mediumPatterns = [
            "create with \$request->validated()" => '/(' . $modelPattern . ')::create\s*\(\s*\$request->validated\(\)/',
            "update with \$request->validated()" => '/->update\s*\(\s*\$request->validated\(\)/',
            "fill with \$request->validated()" => '/->fill\s*\(\s*\$request->validated\(\)/',
            "create with request()->validated()" => '/(' . $modelPattern . ')::create\s*\(\s*request\(\)->validated\(\)/',
            "update with request()->validated()" => '/->update\s*\(\s*request\(\)->validated\(\)/',
            "fill with request()->validated()" => '/->fill\s*\(\s*request\(\)->validated\(\)/',
        ];

        $patterns = $highPatterns;

        foreach ($this->controllerFilesToScan() as [$filePath, $contents]) {
            $lines = explode("\n", $contents);

            foreach ($lines as $lineNumber => $lineContent) {
                // HIGH severity: $request->all(), $request->input(), request()->all()
                foreach ($patterns as $label => $pattern) {
                    if (preg_match($pattern, $lineContent, $matches)) {
                        $modelName = $matches[1] ?? $this->inferModelFromContext($lines, $lineNumber, $modelNames);

                        $modelInfo = $modelName && isset($unsafeModels[$modelName])
                            ? " Model {$modelName}: {$unsafeModels[$modelName]['reason']}."
                            : '';

                        $findings[] = Finding::high(
                            check: $this->name(),
                            message: "Mass assignment risk: {$label}.{$modelInfo}",
                            file: $filePath,
                            line: $lineNumber + 1,
                            context: [
                                'pattern' => $label,
                                'snippet' => trim($lineContent),
                                'model' => $modelName,
                                'model_file' => $modelName ? ($unsafeModels[$modelName]['file'] ?? null) : null,
                            ],
                            fix_hint: "Use \$request->only([...]) or \$request->validated() instead of \$request->all(). Define \$fillable on the model.",
                        );
                    }
                }

                // MEDIUM severity: $request->validated() — safer but model still unprotected
                foreach ($mediumPatterns as $label => $pattern) {
                    if (preg_match($pattern, $lineContent, $matches)) {
                        $modelName = $matches[1] ?? $this->inferModelFromContext($lines, $lineNumber, $modelNames);

                        $modelInfo = $modelName && isset($unsafeModels[$modelName])
                            ? " Model {$modelName}: {$unsafeModels[$modelName]['reason']}."
                            : '';

                        $findings[] = Finding::medium(
                            check: $this->name(),
                            message: "Mass assignment with validated(): {$label}.{$modelInfo} Using validated() is safer, but the model itself lacks protection.",
                            file: $filePath,
                            line: $lineNumber + 1,
                            context: [
                                'pattern' => $label,
                                'snippet' => trim($lineContent),
                                'model' => $modelName,
                                'model_file' => $modelName ? ($unsafeModels[$modelName]['file'] ?? null) : null,
                            ],
                            fix_hint: "validated() is a good practice, but also define \$fillable on the model for defense in depth.",
                        );
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * Try to figure out which model a ->update() or ->fill() call relates to
     * by scanning nearby lines for type hints or variable assignments.
     *
     * @param string[] $lines
     * @param string[] $modelNames
     */
    private function inferModelFromContext(array $lines, int $currentLine, array $modelNames): ?string
    {
        $searchStart = max(0, $currentLine - 10);
        $searchEnd = $currentLine;
        $modelPattern = implode('|', array_map('preg_quote', $modelNames));

        for ($i = $searchEnd; $i >= $searchStart; $i--) {
            if (preg_match('/(' . $modelPattern . ')\s/', $lines[$i], $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    private function extractClassName(string $contents): ?string
    {
        if (preg_match('/class\s+(\w+)/', $contents, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function extendsModel(string $contents): bool
    {
        return (bool) preg_match('/extends\s+(Model|Authenticatable|Pivot)\b/', $contents);
    }

    /**
     * @return iterable<array{0: string, 1: string}>
     */
    private function controllerFilesToScan(): iterable
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
