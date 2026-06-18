<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Invariant;

/**
 * A reusable invariant: a constraint over project context that, when violated,
 * yields {@see Violation}s.
 *
 * Invariants are deterministic and side-effect free. An {@see \IntentPHP\Guard\Checks\Invariant\InvariantCheck}
 * adapts an invariant + {@see InvariantInput} into the Guard check pipeline.
 */
interface Invariant
{
    /**
     * Stable identifier. For migrated rules this is the LEGACY check name so the
     * resulting findings keep identical fingerprints (see DECISIONS D-007).
     */
    public function id(): string;

    public function description(): string;

    /**
     * @return Violation[]
     */
    public function evaluate(InvariantInput $input): array;
}
