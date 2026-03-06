<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Sync\Providers;

use IntentPHP\Guard\Intent\Mapping\MappingEntry;
use IntentPHP\Guard\Intent\Mapping\MappingResolver;
use IntentPHP\Guard\Intent\Sync\Suggestion;
use IntentPHP\Guard\Intent\Sync\SuggestionProviderInterface;

final class CodeToSpecProvider implements SuggestionProviderInterface
{
    public function __construct(
        private readonly MappingResolver $mapping,
    ) {}

    public function name(): string
    {
        return 'code_to_spec';
    }

    /** @return Suggestion[] */
    public function provide(): array
    {
        $suggestions = [];

        foreach ($this->mapping->observedOnly() as $entry) {
            if ($entry->targetType !== 'route') {
                continue;
            }

            $suggestions[] = $this->buildAddAuthRule($entry);
        }

        return $suggestions;
    }

    private function buildAddAuthRule(MappingEntry $entry): Suggestion
    {
        $targetId = $entry->targetId;
        $direction = 'code_to_spec';
        $actionType = 'add_auth_rule';

        $uri = $entry->targetDetail['uri'] ?? '';
        $methods = $entry->targetDetail['methods'] ?? [];
        $middleware = $entry->targetDetail['middleware'] ?? [];
        $methodsStr = implode(',', $methods);

        $ruleId = 'auto-' . self::normalizeForId($targetId);

        return new Suggestion(
            id: "{$direction}:{$actionType}:{$targetId}",
            direction: $direction,
            actionType: $actionType,
            targetId: $targetId,
            mappingIds: [$entry->sortKey()],
            confidence: 'medium',
            rationale: "Route [{$methodsStr}] {$uri} has no matching auth rule in intent spec.",
            patch: [
                'action_type' => $actionType,
                'spec_section' => 'auth.rules',
                'proposed_rule' => [
                    'id' => $ruleId,
                    'match' => ['routes' => ['prefix' => $uri]],
                    'require' => ['authenticated' => true],
                ],
                'instructions' => 'Add this rule to auth.rules in intent/intent.yaml.',
            ],
            context: [
                'uri' => $uri,
                'methods' => $methods,
                'middleware' => $middleware,
            ],
        );
    }

    private static function normalizeForId(string $targetId): string
    {
        $id = strtolower($targetId);
        $id = (string) preg_replace('/[^a-z0-9]+/', '-', $id);
        $id = trim($id, '-');

        return $id;
    }
}
