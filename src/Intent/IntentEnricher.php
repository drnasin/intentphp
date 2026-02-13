<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent;

use IntentPHP\Guard\Intent\Data\ModelSpec;
use IntentPHP\Guard\Scan\Finding;

final class IntentEnricher
{
    /**
     * Enrich existing mass-assignment findings with intent spec details.
     *
     * @param Finding[] $findings
     * @return Finding[]
     */
    public static function enrich(array $findings, IntentSpec $spec): array
    {
        if ($spec->data->models === []) {
            return $findings;
        }

        $shortNameMap = self::buildUniqueShortNameMap($spec);

        return array_map(
            fn (Finding $f) => self::enrichFinding($f, $spec, $shortNameMap),
            $findings,
        );
    }

    /**
     * @param array<string, string> $shortNameMap
     */
    private static function enrichFinding(Finding $finding, IntentSpec $spec, array $shortNameMap): Finding
    {
        if ($finding->check !== 'mass-assignment') {
            return $finding;
        }

        // 1. Try FQCN match
        $fqcn = $finding->context['model_fqcn'] ?? null;
        if ($fqcn !== null && isset($spec->data->models[$fqcn])) {
            return self::mergeIntent($finding, $spec->data->models[$fqcn]);
        }

        // 2. Try unique short name match
        $shortName = $finding->context['model'] ?? null;
        if ($shortName !== null && isset($shortNameMap[$shortName])) {
            return self::mergeIntent($finding, $spec->data->models[$shortNameMap[$shortName]]);
        }

        return $finding;
    }

    private static function mergeIntent(Finding $finding, ModelSpec $modelSpec): Finding
    {
        $extra = [
            'intent_mode' => $modelSpec->massAssignmentMode,
        ];

        if ($modelSpec->allow !== []) {
            $extra['intent_allow'] = $modelSpec->allow;
        }

        if ($modelSpec->forbid !== []) {
            $extra['intent_forbid'] = $modelSpec->forbid;
        }

        return $finding->withMergedContext($extra);
    }

    /**
     * Build a map of unique short names → FQCNs.
     * Only includes short names that appear exactly once among all model FQCNs.
     *
     * @return array<string, string>
     */
    private static function buildUniqueShortNameMap(IntentSpec $spec): array
    {
        $shortNameCounts = [];
        $shortNameToFqcn = [];

        foreach ($spec->data->models as $fqcn => $model) {
            $parts = explode('\\', $fqcn);
            $shortName = end($parts);

            if (! isset($shortNameCounts[$shortName])) {
                $shortNameCounts[$shortName] = 0;
                $shortNameToFqcn[$shortName] = $fqcn;
            }

            $shortNameCounts[$shortName]++;
        }

        // Only keep unique short names
        $result = [];
        foreach ($shortNameCounts as $shortName => $count) {
            if ($count === 1) {
                $result[$shortName] = $shortNameToFqcn[$shortName];
            }
        }

        return $result;
    }
}
