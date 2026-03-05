<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Drift\Detectors;

use IntentPHP\Guard\Intent\Data\ModelSpec;
use IntentPHP\Guard\Intent\Drift\Context\ObservedModel;
use IntentPHP\Guard\Intent\Drift\Context\ProjectContext;
use IntentPHP\Guard\Intent\Drift\DriftDetectorInterface;
use IntentPHP\Guard\Intent\Drift\DriftItem;
use IntentPHP\Guard\Intent\IntentSpec;

final class MassAssignmentDriftDetector implements DriftDetectorInterface
{
    public function name(): string
    {
        return 'mass-assignment';
    }

    /** @return DriftItem[] */
    public function detect(IntentSpec $spec, ProjectContext $context): array
    {
        $observedByFqcn = [];

        foreach ($context->models as $model) {
            $observedByFqcn[$model->fqcn] = $model;
        }

        $items = [];

        foreach ($spec->data->models as $fqcn => $modelSpec) {
            $observed = $observedByFqcn[$fqcn] ?? null;

            if ($observed === null) {
                // Model file not found — handled upstream (factory adds warning)
                continue;
            }

            array_push($items, ...$this->checkModel($fqcn, $modelSpec, $observed));
        }

        return $items;
    }

    /**
     * @return DriftItem[]
     */
    private function checkModel(string $fqcn, ModelSpec $modelSpec, ObservedModel $observed): array
    {
        if ($modelSpec->massAssignmentMode === 'explicit_allowlist') {
            return $this->checkExplicitAllowlist($fqcn, $modelSpec, $observed);
        }

        if ($modelSpec->massAssignmentMode === 'guarded') {
            return $this->checkGuardedMode($fqcn, $observed);
        }

        return [];
    }

    /**
     * @return DriftItem[]
     */
    private function checkExplicitAllowlist(string $fqcn, ModelSpec $modelSpec, ObservedModel $observed): array
    {
        if (! $observed->hasFillable) {
            return [
                new DriftItem(
                    detector: $this->name(),
                    driftType: 'missing_fillable',
                    targetId: $fqcn,
                    severity: 'high',
                    message: "Model '{$fqcn}' is declared as explicit_allowlist in intent spec but has no \$fillable property.",
                    file: $observed->filePath,
                    context: [
                        'model_fqcn' => $fqcn,
                        'drift_type' => 'missing_fillable',
                        'intent_mode' => 'explicit_allowlist',
                    ],
                    fixHint: 'Add a $fillable property to the model listing allowed mass-assignable attributes.',
                ),
            ];
        }

        // Fillable exists but can't be parsed — and we need attribute-level checks
        if (! $observed->fillableParseable && $modelSpec->forbid !== []) {
            return [
                new DriftItem(
                    detector: $this->name(),
                    driftType: 'unparseable_model',
                    targetId: $fqcn,
                    severity: 'low',
                    message: "Model '{$fqcn}' uses a non-static \$fillable pattern that cannot be parsed. Drift detection skipped for attribute-level checks.",
                    file: $observed->filePath,
                    context: [
                        'model_fqcn' => $fqcn,
                        'drift_type' => 'unparseable_model',
                        'intent_mode' => 'explicit_allowlist',
                    ],
                    fixHint: 'Refactor to use a literal array for $fillable, or suppress this finding via baseline.',
                ),
            ];
        }

        // Check for forbidden attributes in $fillable
        $items = [];

        if ($observed->fillableParseable) {
            foreach ($modelSpec->forbid as $forbidden) {
                if (in_array($forbidden, $observed->fillableAttrs, true)) {
                    $items[] = new DriftItem(
                        detector: $this->name(),
                        driftType: "forbidden_in_fillable:{$forbidden}",
                        targetId: $fqcn,
                        severity: 'high',
                        message: "Model '{$fqcn}' has forbidden attribute '{$forbidden}' in \$fillable (per intent spec).",
                        file: $observed->filePath,
                        context: [
                            'model_fqcn' => $fqcn,
                            'drift_type' => "forbidden_in_fillable:{$forbidden}",
                            'intent_mode' => 'explicit_allowlist',
                            'forbidden_attribute' => $forbidden,
                        ],
                        fixHint: "Remove '{$forbidden}' from \$fillable or update the intent spec.",
                    );
                }
            }
        }

        return $items;
    }

    /**
     * @return DriftItem[]
     */
    private function checkGuardedMode(string $fqcn, ObservedModel $observed): array
    {
        if ($observed->guardedIsEmpty) {
            return [
                new DriftItem(
                    detector: $this->name(),
                    driftType: 'guarded_empty',
                    targetId: $fqcn,
                    severity: 'high',
                    message: "Model '{$fqcn}' is declared as guarded in intent spec but has \$guarded = [] (all attributes mass-assignable).",
                    file: $observed->filePath,
                    context: [
                        'model_fqcn' => $fqcn,
                        'drift_type' => 'guarded_empty',
                        'intent_mode' => 'guarded',
                    ],
                    fixHint: 'Populate the $guarded array with attributes that should not be mass-assignable, or switch to $fillable.',
                ),
            ];
        }

        return [];
    }
}
