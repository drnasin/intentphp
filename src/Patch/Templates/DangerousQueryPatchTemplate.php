<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Patch\Templates;

use IntentPHP\Guard\Patch\Patch;
use IntentPHP\Guard\Patch\PatchBuilder;
use IntentPHP\Guard\Scan\Finding;

class DangerousQueryPatchTemplate implements PatchTemplateInterface
{
    public function __construct(
        private readonly PatchBuilder $patchBuilder,
    ) {}

    public function generate(Finding $finding): ?Patch
    {
        $file = $finding->file;
        $line = $finding->line;
        $snippet = $finding->context['snippet'] ?? '';
        $pattern = $finding->context['pattern'] ?? '';

        if (! $file || ! $line || ! is_readable($file)) {
            return null;
        }

        $lines = file($file);
        if ($lines === false) {
            return null;
        }

        $lineIndex = $line - 1;
        $originalLine = $lines[$lineIndex] ?? null;

        if ($originalLine === null) {
            return null;
        }

        $indent = '';
        if (preg_match('/^(\s+)/', $originalLine, $m)) {
            $indent = $m[1];
        }

        $suggested = $this->buildSuggestion($pattern, $originalLine, $indent);

        if ($suggested === null) {
            return null;
        }

        return $this->patchBuilder->build(
            $file,
            rtrim($originalLine, "\n"),
            $suggested,
            $line,
        );
    }

    private function buildSuggestion(string $pattern, string $originalLine, string $indent): ?string
    {
        if (str_contains($pattern, 'orderBy')) {
            return $this->buildOrderBySuggestion($originalLine, $indent);
        }

        if (str_contains($pattern, 'Raw') || str_contains($pattern, 'DB::raw')) {
            return $this->buildRawQuerySuggestion($originalLine, $indent);
        }

        if (str_contains($pattern, 'where')) {
            return $this->buildWhereSuggestion($originalLine, $indent);
        }

        return null;
    }

    private function buildOrderBySuggestion(string $originalLine, string $indent): string
    {
        $lines = [];
        $lines[] = "{$indent}\$allowedSorts = ['id', 'name', 'created_at']; // TODO: adjust allowed columns";
        $lines[] = "{$indent}\$allowedDirs = ['asc', 'desc'];";
        $lines[] = "{$indent}\$sortCol = in_array(\$request->input('sort'), \$allowedSorts, true) ? \$request->input('sort') : 'id';";
        $lines[] = "{$indent}\$sortDir = in_array(strtolower(\$request->input('direction', 'asc')), \$allowedDirs, true) ? \$request->input('direction') : 'asc';";
        $lines[] = "{$indent}->orderBy(\$sortCol, \$sortDir)";

        return implode("\n", $lines);
    }

    private function buildRawQuerySuggestion(string $originalLine, string $indent): string
    {
        $lines = [];
        $lines[] = "{$indent}// GUARD: Do not pass user input into raw queries.";
        $lines[] = "{$indent}// Use parameterized bindings instead:";
        $lines[] = "{$indent}// ->whereRaw('column = ?', [\$validated_value])";
        $lines[] = rtrim($originalLine, "\n") . ' // FIXME: unsafe raw input';

        return implode("\n", $lines);
    }

    private function buildWhereSuggestion(string $originalLine, string $indent): string
    {
        $lines = [];
        $lines[] = "{$indent}\$allowedFilters = ['status', 'type', 'category']; // TODO: adjust allowed columns";
        $lines[] = "{$indent}// Only apply where clause if column is in the allowlist";
        $lines[] = "{$indent}// if (in_array(\$column, \$allowedFilters, true)) {";
        $lines[] = rtrim($originalLine, "\n") . ' // FIXME: validate column name';
        $lines[] = "{$indent}// }";

        return implode("\n", $lines);
    }
}
