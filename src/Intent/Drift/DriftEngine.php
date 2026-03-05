<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Drift;

use IntentPHP\Guard\Intent\Drift\Context\ProjectContext;
use IntentPHP\Guard\Intent\IntentSpec;

final class DriftEngine
{
    /** @var DriftDetectorInterface[] */
    private readonly array $detectors;

    /** @param DriftDetectorInterface[] $detectors */
    public function __construct(array $detectors)
    {
        $this->detectors = $detectors;
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

        return $items;
    }
}
