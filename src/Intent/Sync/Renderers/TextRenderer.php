<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Sync\Renderers;

use IntentPHP\Guard\Intent\Sync\Suggestion;

final class TextRenderer
{
    /**
     * Render suggestions as human-readable text preview.
     *
     * @param Suggestion[] $suggestions Already sorted by sortKey()
     */
    public function render(array $suggestions): string
    {
        if ($suggestions === []) {
            return 'No sync suggestions.';
        }

        $lines = [];
        $lines[] = sprintf('Sync Suggestions (%d found)', count($suggestions));
        $lines[] = str_repeat('=', 60);

        $index = 0;

        foreach ($suggestions as $suggestion) {
            $index++;
            $lines[] = '';
            $lines[] = sprintf('[%d] %s', $index, $this->directionLabel($suggestion->direction));
            $lines[] = sprintf('    Action:     %s', $suggestion->actionType);
            $lines[] = sprintf('    Target:     %s', $suggestion->targetId);
            $lines[] = sprintf('    Confidence: %s', $suggestion->confidence);
            $lines[] = sprintf('    Rationale:  %s', $suggestion->rationale);

            if ($suggestion->mappingIds !== null) {
                $lines[] = sprintf('    Mapping:    %s', implode(', ', $suggestion->mappingIds));
            }

            $lines[] = '';
            $lines[] = '    Patch:';

            foreach ($this->formatPatch($suggestion) as $patchLine) {
                $lines[] = '      ' . $patchLine;
            }
        }

        $lines[] = '';
        $lines[] = str_repeat('-', 60);

        $codeToSpec = 0;
        $specToCode = 0;

        foreach ($suggestions as $s) {
            if ($s->direction === 'code_to_spec') {
                $codeToSpec++;
            } else {
                $specToCode++;
            }
        }

        $lines[] = sprintf(
            'Total: %d  (code_to_spec: %d, spec_to_code: %d)',
            count($suggestions),
            $codeToSpec,
            $specToCode,
        );

        return implode("\n", $lines);
    }

    private function directionLabel(string $direction): string
    {
        return match ($direction) {
            'code_to_spec' => 'Code -> Spec',
            'spec_to_code' => 'Spec -> Code',
            default => $direction,
        };
    }

    /** @return string[] */
    private function formatPatch(Suggestion $suggestion): array
    {
        $patch = $suggestion->patch;
        $lines = [];

        if ($suggestion->actionType === 'add_auth_rule' && isset($patch['proposed_rule'])) {
            $rule = $patch['proposed_rule'];
            $lines[] = '# auth.rules entry:';
            $lines[] = sprintf('- id: %s', $rule['id'] ?? '');

            if (isset($rule['match']['routes']['prefix'])) {
                $lines[] = '  match:';
                $lines[] = '    routes:';
                $lines[] = sprintf('      prefix: %s', $rule['match']['routes']['prefix']);
            }

            if (isset($rule['require'])) {
                $lines[] = '  require:';

                foreach ($rule['require'] as $key => $value) {
                    $lines[] = sprintf('    %s: %s', $key, var_export($value, true));
                }
            }
        } elseif ($suggestion->actionType === 'add_middleware' && isset($patch['middleware'])) {
            $lines[] = sprintf('middleware: [%s]', implode(', ', $patch['middleware']));
            $lines[] = sprintf('target:     %s', $patch['target_route_identifier'] ?? $suggestion->targetId);
        }

        if (isset($patch['instructions'])) {
            $lines[] = '';
            $lines[] = $patch['instructions'];
        }

        return $lines;
    }
}
