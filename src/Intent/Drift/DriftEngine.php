<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Drift;

use IntentPHP\Guard\Intent\Drift\Context\ProjectContext;
use IntentPHP\Guard\Intent\IntentSpec;
use IntentPHP\Guard\Intent\Mapping\MappingResolver;

final class DriftEngine
{
    /** @var DriftDetectorInterface[] */
    private readonly array $detectors;

    private readonly ?MappingResolver $mapping;

    /**
     * @param DriftDetectorInterface[] $detectors
     * @param MappingResolver|null     $mapping   Optional mapping for context enrichment
     */
    public function __construct(array $detectors, ?MappingResolver $mapping = null)
    {
        $this->detectors = $detectors;
        $this->mapping = $mapping;
    }

    /**
     * Run all detectors and return drift items in deterministic sorted order.
     *
     * @return DriftItem[]
     */
    public function detect(IntentSpec $spec, ProjectContext $context): array
    {
        $items = [];

        foreach ($this->detectors as $detector) {
            array_push($items, ...$detector->detect($spec, $context));
        }

        usort($items, static fn (DriftItem $a, DriftItem $b): int => strcmp($a->sortKey(), $b->sortKey()));

        if ($this->mapping !== null) {
            $items = array_map(fn (DriftItem $item): DriftItem => $this->enrichWithMapping($item), $items);
        }

        return $items;
    }

    private function enrichWithMapping(DriftItem $item): DriftItem
    {
        $mappingIds = [];

        $targetId = $item->targetId;
        $entries = $this->mapping->byRouteId($targetId);

        if ($entries === []) {
            $entries = $this->mapping->byModelFqcn($targetId);
        }

        foreach ($entries as $entry) {
            $mappingIds[] = $entry->sortKey();
        }

        sort($mappingIds);

        if ($mappingIds === []) {
            return $item;
        }

        return new DriftItem(
            detector: $item->detector,
            driftType: $item->driftType,
            targetId: $item->targetId,
            severity: $item->severity,
            message: $item->message,
            file: $item->file,
            context: array_merge($item->context, ['mapping_ids' => $mappingIds]),
            fixHint: $item->fixHint,
        );
    }
}
