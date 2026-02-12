<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Patch\Templates;

use IntentPHP\Guard\Patch\Patch;
use IntentPHP\Guard\Patch\PatchBuilder;
use IntentPHP\Guard\Scan\Finding;

class MassAssignmentPatchTemplate implements PatchTemplateInterface
{
    public function __construct(
        private readonly PatchBuilder $patchBuilder,
    ) {}

    public function generate(Finding $finding): ?Patch
    {
        $modelFile = $finding->context['model_file'] ?? null;

        if (! $modelFile || ! is_readable($modelFile)) {
            return null;
        }

        $lines = file($modelFile);
        if ($lines === false) {
            return null;
        }

        $contents = implode('', $lines);

        // Case 1: $guarded = [] exists — replace it with $fillable
        if (preg_match('/\$guarded\s*=\s*\[\s*\]/', $contents)) {
            return $this->replaceGuardedWithFillable($modelFile, $lines);
        }

        // Case 2: no $fillable — insert it after the class opening / use traits
        return $this->insertFillable($modelFile, $lines);
    }

    private function replaceGuardedWithFillable(string $file, array $lines): ?Patch
    {
        foreach ($lines as $i => $line) {
            if (preg_match('/\$guarded\s*=\s*\[\s*\]/', $line)) {
                $indent = '';
                if (preg_match('/^(\s+)/', $line, $m)) {
                    $indent = $m[1];
                }

                $original = rtrim($line, "\n");
                $suggested = implode("\n", [
                    "{$indent}protected \$fillable = [",
                    "{$indent}    // TODO: list the attributes that should be mass assignable",
                    "{$indent}];",
                ]);

                return $this->buildPatch($file, $original, $suggested, $i + 1);
            }
        }

        return null;
    }

    private function insertFillable(string $file, array $lines): ?Patch
    {
        // Find best insertion point: after last `use` trait line, or after class opening brace
        $insertAfter = null;
        $indent = '    ';

        for ($i = 0, $count = count($lines); $i < $count; $i++) {
            // Detect class opening brace
            if (preg_match('/^class\s+\w+/', $lines[$i])) {
                // Find the opening brace (might be on same line or next)
                for ($j = $i; $j < $count; $j++) {
                    if (str_contains($lines[$j], '{')) {
                        $insertAfter = $j;
                        break;
                    }
                }
            }

            // Detect `use SomeTrait;` inside the class body — insert after the last one
            if ($insertAfter !== null && preg_match('/^\s+use\s+\w+/', $lines[$i]) && str_contains($lines[$i], ';')) {
                $insertAfter = $i;
                if (preg_match('/^(\s+)/', $lines[$i], $m)) {
                    $indent = $m[1];
                }
            }
        }

        if ($insertAfter === null) {
            return null;
        }

        $anchorLine = $lines[$insertAfter];
        $original = rtrim($anchorLine, "\n");

        $suggested = $original . "\n" . "\n" . implode("\n", [
            "{$indent}protected \$fillable = [",
            "{$indent}    // TODO: list the attributes that should be mass assignable",
            "{$indent}];",
        ]);

        return $this->buildPatch($file, $original, $suggested, $insertAfter + 1);
    }

    private function buildPatch(string $file, string $original, string $suggested, int $startLine): Patch
    {
        return (new PatchBuilder())->build($file, $original, $suggested, $startLine);
    }
}
