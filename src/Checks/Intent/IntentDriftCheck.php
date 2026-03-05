<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Checks\Intent;

use IntentPHP\Guard\Checks\CheckInterface;
use IntentPHP\Guard\Intent\Drift\Context\ProjectContext;
use IntentPHP\Guard\Intent\Drift\DriftEngine;
use IntentPHP\Guard\Intent\Drift\DriftItem;
use IntentPHP\Guard\Intent\IntentSpec;
use IntentPHP\Guard\Scan\Finding;

class IntentDriftCheck implements CheckInterface
{
    public function __construct(
        private readonly DriftEngine $engine,
        private readonly IntentSpec $spec,
        private readonly ProjectContext $context,
    ) {}

    public function name(): string
    {
        return 'intent-drift';
    }

    /** @return Finding[] */
    public function run(): array
    {
        $items = $this->engine->detect($this->spec, $this->context);

        return array_map(fn (DriftItem $item): Finding => $this->toFinding($item), $items);
    }

    private function toFinding(DriftItem $item): Finding
    {
        return new Finding(
            check: "intent-drift/{$item->detector}",
            severity: $item->severity,
            message: $item->message,
            file: $item->file,
            line: null,
            context: $item->context,
            fix_hint: $item->fixHint,
        );
    }
}
