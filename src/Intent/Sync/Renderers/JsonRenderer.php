<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Sync\Renderers;

use IntentPHP\Guard\Intent\Sync\Suggestion;

final class JsonRenderer
{
    public const VERSION = '1.0';

    /**
     * Render suggestions as deterministic JSON.
     *
     * @param Suggestion[] $suggestions Already sorted by sortKey()
     */
    public function render(array $suggestions): string
    {
        $codeToSpec = 0;
        $specToCode = 0;

        foreach ($suggestions as $suggestion) {
            if ($suggestion->direction === 'code_to_spec') {
                $codeToSpec++;
            } else {
                $specToCode++;
            }
        }

        $output = [
            'version' => self::VERSION,
            'suggestions' => array_map(
                static fn (Suggestion $s): array => $s->toArray(),
                $suggestions,
            ),
            'summary' => [
                'total' => count($suggestions),
                'code_to_spec' => $codeToSpec,
                'spec_to_code' => $specToCode,
            ],
        ];

        return json_encode(
            $output,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
