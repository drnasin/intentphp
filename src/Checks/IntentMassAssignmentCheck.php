<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Checks;

use IntentPHP\Guard\Intent\Data\ModelSpec;
use IntentPHP\Guard\Intent\IntentContext;
use IntentPHP\Guard\Intent\IntentSpec;
use IntentPHP\Guard\Scan\Finding;

class IntentMassAssignmentCheck implements CheckInterface
{
    public function __construct(
        private readonly string $modelsPath,
        private readonly IntentSpec $spec,
        private readonly IntentContext $intentContext,
    ) {}

    public function name(): string
    {
        return 'intent-mass-assignment';
    }

    /** @return Finding[] */
    public function run(): array
    {
        $findings = [];

        foreach ($this->spec->data->models as $fqcn => $modelSpec) {
            $modelFindings = $this->checkModel($fqcn, $modelSpec);
            array_push($findings, ...$modelFindings);
        }

        return $findings;
    }

    /**
     * @return Finding[]
     */
    private function checkModel(string $fqcn, ModelSpec $modelSpec): array
    {
        $filePath = $this->resolveModelFile($fqcn);

        if ($filePath === null) {
            $this->intentContext->addWarning("Model file not found for '{$fqcn}'. Cannot verify mass-assignment compliance.");
            return [];
        }

        $contents = file_get_contents($filePath);
        if ($contents === false) {
            $this->intentContext->addWarning("Could not read model file for '{$fqcn}': {$filePath}");
            return [];
        }

        $findings = [];

        if ($modelSpec->massAssignmentMode === 'explicit_allowlist') {
            $findings = $this->checkExplicitAllowlist($fqcn, $modelSpec, $filePath, $contents);
        } elseif ($modelSpec->massAssignmentMode === 'guarded') {
            $findings = $this->checkGuardedMode($fqcn, $filePath, $contents);
        }

        return $findings;
    }

    /**
     * @return Finding[]
     */
    private function checkExplicitAllowlist(string $fqcn, ModelSpec $modelSpec, string $filePath, string $contents): array
    {
        $findings = [];
        $hasFillable = (bool) preg_match('/\$fillable\s*=\s*\[/', $contents);

        if (! $hasFillable) {
            $findings[] = Finding::high(
                check: $this->name(),
                message: "Model '{$fqcn}' is declared as explicit_allowlist in intent spec but has no \$fillable property.",
                file: $filePath,
                context: [
                    'model_fqcn' => $fqcn,
                    'pattern' => 'missing_fillable',
                    'intent_mode' => 'explicit_allowlist',
                ],
                fix_hint: "Add a \$fillable property to the model listing allowed mass-assignable attributes.",
            );
            return $findings;
        }

        // Check for forbidden attributes in $fillable
        if ($modelSpec->forbid !== []) {
            $fillableAttrs = $this->extractFillableAttributes($contents);

            foreach ($modelSpec->forbid as $forbidden) {
                if (in_array($forbidden, $fillableAttrs, true)) {
                    $findings[] = Finding::high(
                        check: $this->name(),
                        message: "Model '{$fqcn}' has forbidden attribute '{$forbidden}' in \$fillable (per intent spec).",
                        file: $filePath,
                        context: [
                            'model_fqcn' => $fqcn,
                            'pattern' => "forbidden_in_fillable:{$forbidden}",
                            'intent_mode' => 'explicit_allowlist',
                            'forbidden_attribute' => $forbidden,
                        ],
                        fix_hint: "Remove '{$forbidden}' from \$fillable or update the intent spec.",
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @return Finding[]
     */
    private function checkGuardedMode(string $fqcn, string $filePath, string $contents): array
    {
        $hasEmptyGuarded = (bool) preg_match('/\$guarded\s*=\s*\[\s*\]/', $contents);

        if ($hasEmptyGuarded) {
            return [
                Finding::high(
                    check: $this->name(),
                    message: "Model '{$fqcn}' is declared as guarded in intent spec but has \$guarded = [] (all attributes mass-assignable).",
                    file: $filePath,
                    context: [
                        'model_fqcn' => $fqcn,
                        'pattern' => 'guarded_empty',
                        'intent_mode' => 'guarded',
                    ],
                    fix_hint: "Populate the \$guarded array with attributes that should not be mass-assignable, or switch to \$fillable.",
                ),
            ];
        }

        return [];
    }

    private function resolveModelFile(string $fqcn): ?string
    {
        // Convert FQCN to relative path within models directory
        // e.g., App\Models\User -> User.php, App\Models\Admin\User -> Admin/User.php
        $parts = explode('\\', $fqcn);

        // Strip common namespace prefixes to get the model-relative path
        // Look for 'Models' segment and take everything after it
        $modelsIndex = array_search('Models', $parts, true);
        if ($modelsIndex !== false) {
            $relativeParts = array_slice($parts, $modelsIndex + 1);
        } else {
            // Fallback: use just the class name
            $relativeParts = [end($parts)];
        }

        $relativePath = implode(DIRECTORY_SEPARATOR, $relativeParts) . '.php';
        $fullPath = $this->modelsPath . DIRECTORY_SEPARATOR . $relativePath;

        if (file_exists($fullPath)) {
            return $fullPath;
        }

        return null;
    }

    /**
     * Extract attribute names from $fillable array declaration.
     *
     * @return string[]
     */
    private function extractFillableAttributes(string $contents): array
    {
        if (! preg_match('/\$fillable\s*=\s*\[(.*?)\]/s', $contents, $match)) {
            return [];
        }

        $attrs = [];
        if (preg_match_all("/['\"]([^'\"]+)['\"]/", $match[1], $matches)) {
            $attrs = $matches[1];
        }

        return $attrs;
    }
}
