<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Checks\Invariant;

use IntentPHP\Guard\Checks\CheckInterface;
use IntentPHP\Guard\Invariant\Invariant;
use IntentPHP\Guard\Invariant\InvariantInput;
use IntentPHP\Guard\Scan\Finding;

/**
 * Adapter that runs a single {@see Invariant} as a Guard check.
 *
 * Each {@see \IntentPHP\Guard\Invariant\Violation} maps 1:1 to a {@see Finding}.
 * The mapping is intentionally transparent: `check` = the invariant id (the
 * legacy check name), and `context` is passed straight through so key insertion
 * order — and therefore the fingerprint and committed baseline — is preserved
 * exactly (see DECISIONS D-007).
 */
final class InvariantCheck implements CheckInterface
{
    public function __construct(
        private readonly Invariant $invariant,
        private readonly InvariantInput $input,
    ) {}

    public function name(): string
    {
        return $this->invariant->id();
    }

    /** @return Finding[] */
    public function run(): array
    {
        $findings = [];

        foreach ($this->invariant->evaluate($this->input) as $violation) {
            $findings[] = new Finding(
                check: $violation->invariantId,
                severity: $violation->severity,
                message: $violation->message,
                file: $violation->file,
                line: $violation->line,
                context: $violation->context,
                fix_hint: $violation->fixHint,
            );
        }

        return $findings;
    }
}
