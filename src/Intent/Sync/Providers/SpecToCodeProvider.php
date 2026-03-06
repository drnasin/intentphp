<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Sync\Providers;

use IntentPHP\Guard\Intent\Drift\DriftItem;
use IntentPHP\Guard\Intent\Sync\Suggestion;
use IntentPHP\Guard\Intent\Sync\SuggestionProviderInterface;

final class SpecToCodeProvider implements SuggestionProviderInterface
{
    private const ACTIONABLE_DRIFT_TYPES = [
        'missing_auth_middleware',
        'missing_guard_middleware',
    ];

    /** @var DriftItem[] */
    private readonly array $driftItems;

    /** @param DriftItem[] $driftItems */
    public function __construct(array $driftItems)
    {
        $this->driftItems = $driftItems;
    }

    public function name(): string
    {
        return 'spec_to_code';
    }

    /** @return Suggestion[] */
    public function provide(): array
    {
        $suggestions = [];

        foreach ($this->driftItems as $item) {
            if (!in_array($item->driftType, self::ACTIONABLE_DRIFT_TYPES, true)) {
                continue;
            }

            $suggestions[] = $this->buildAddMiddleware($item);
        }

        return $suggestions;
    }

    private function buildAddMiddleware(DriftItem $item): Suggestion
    {
        $direction = 'spec_to_code';
        $actionType = 'add_middleware';
        $targetId = $item->targetId;

        $middleware = $this->resolveMiddleware($item);
        $mappingIds = $this->resolveMappingIds($item);

        $uri = $item->context['uri'] ?? $targetId;
        $methods = $item->context['methods'] ?? [];
        $currentMiddleware = $item->context['middleware'] ?? [];
        $methodsStr = implode(',', $methods);

        $middlewareStr = implode(', ', $middleware);

        return new Suggestion(
            id: "{$direction}:{$actionType}:{$targetId}",
            direction: $direction,
            actionType: $actionType,
            targetId: $targetId,
            mappingIds: $mappingIds,
            confidence: 'high',
            rationale: "Route [{$methodsStr}] {$uri} requires authentication per intent spec but has no auth middleware.",
            patch: [
                'action_type' => $actionType,
                'middleware' => $middleware,
                'target_route_identifier' => $targetId,
                'instructions' => "Add [{$middlewareStr}] middleware to route group or route definition for {$uri}.",
            ],
            context: [
                'uri' => $uri,
                'methods' => $methods,
                'middleware' => $currentMiddleware,
                'drift_type' => $item->driftType,
            ],
        );
    }

    /** @return string[] */
    private function resolveMiddleware(DriftItem $item): array
    {
        if ($item->driftType === 'missing_guard_middleware') {
            $guard = $item->context['require']['guard'] ?? null;

            if ($guard !== null) {
                return ["auth:{$guard}"];
            }
        }

        return ['auth'];
    }

    /** @return string[]|null */
    private function resolveMappingIds(DriftItem $item): ?array
    {
        $ids = $item->context['mapping_ids'] ?? null;

        if (!is_array($ids) || $ids === []) {
            return null;
        }

        $sorted = $ids;
        sort($sorted);

        return $sorted;
    }
}
