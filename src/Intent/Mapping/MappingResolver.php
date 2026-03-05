<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Mapping;

final class MappingResolver
{
    public function __construct(
        private readonly MappingIndex $index,
    ) {}

    /** @return MappingEntry[] */
    public function byRuleId(string $ruleId): array
    {
        return array_values(array_filter(
            $this->index->entries,
            static fn (MappingEntry $e): bool => $e->specId === $ruleId,
        ));
    }

    /** @return MappingEntry[] */
    public function byModelFqcn(string $fqcn): array
    {
        return array_values(array_filter(
            $this->index->entries,
            static fn (MappingEntry $e): bool => $e->targetType === 'model' && $e->targetId === $fqcn,
        ));
    }

    /** @return MappingEntry[] */
    public function byRouteId(string $routeId): array
    {
        return array_values(array_filter(
            $this->index->entries,
            static fn (MappingEntry $e): bool => $e->targetType === 'route' && $e->targetId === $routeId,
        ));
    }

    /** @return MappingEntry[] */
    public function observedOnly(): array
    {
        return array_values(array_filter(
            $this->index->entries,
            static fn (MappingEntry $e): bool => $e->isObservedOnly(),
        ));
    }

    /** @return MappingEntry[] */
    public function specLinked(): array
    {
        return array_values(array_filter(
            $this->index->entries,
            static fn (MappingEntry $e): bool => $e->isSpecLinked(),
        ));
    }

    public function hasSpecLink(string $targetId): bool
    {
        foreach ($this->index->entries as $entry) {
            if ($entry->targetId === $targetId && $entry->isSpecLinked()) {
                return true;
            }
        }

        return false;
    }

    /** @return MappingEntry[] */
    public function all(): array
    {
        return $this->index->entries;
    }
}
