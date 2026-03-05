<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Drift;

use IntentPHP\Guard\Intent\Drift\Context\ProjectContext;
use IntentPHP\Guard\Intent\IntentSpec;

interface DriftDetectorInterface
{
    /**
     * Detect divergence between declared intent and observed project state.
     *
     * @return DriftItem[]
     */
    public function detect(IntentSpec $spec, ProjectContext $context): array;

    public function name(): string;
}
