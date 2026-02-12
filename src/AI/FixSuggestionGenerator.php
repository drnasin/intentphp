<?php

declare(strict_types=1);

namespace IntentPHP\Guard\AI;

use IntentPHP\Guard\Scan\Finding;

class FixSuggestionGenerator
{
    public function __construct(
        private readonly AiClientInterface $client,
        private readonly PromptBuilder $promptBuilder,
    ) {}

    /**
     * Attach AI-generated fix suggestions to HIGH severity findings.
     * When the AI returns structured JSON with suggestion + patch keys,
     * the patch is stored in context['ai_patch'] for reporting.
     *
     * @param Finding[] $findings
     * @return Finding[]
     */
    public function enhance(array $findings): array
    {
        return array_map(function (Finding $finding) {
            if ($finding->severity !== 'high') {
                return $finding;
            }

            $prompt = $this->promptBuilder->buildFixPrompt($finding);
            $response = $this->client->generate($prompt);

            $parsed = $this->parseStructuredResponse($response);

            $finding = $finding->withAiSuggestion($parsed['suggestion']);

            if ($parsed['patch'] !== null) {
                $finding = $finding->withMergedContext(['ai_patch' => $parsed['patch']]);
            }

            return $finding;
        }, $findings);
    }

    /**
     * Try to extract structured suggestion + patch from a JSON response.
     * Falls back to treating the entire response as a plain text suggestion.
     *
     * @return array{suggestion: string, patch: ?string}
     */
    private function parseStructuredResponse(string $response): array
    {
        $json = json_decode($response, true);

        if (is_array($json) && isset($json['suggestion'])) {
            return [
                'suggestion' => (string) $json['suggestion'],
                'patch' => isset($json['patch']) ? (string) $json['patch'] : null,
            ];
        }

        return [
            'suggestion' => $response,
            'patch' => null,
        ];
    }
}
