<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Invariant;

/**
 * Holds the registered invariants and returns them in a deterministic order.
 *
 * Ordering: invariants are sorted ascending by {@see Invariant::id()} using
 * strcmp (byte-wise, locale-independent), so the same set of invariants always
 * yields the same order regardless of registration order. Emission order does
 * not affect output (GuardScanCommand::sortFindings imposes a total order on
 * findings), but a stable, documented order keeps the engine itself
 * deterministic and testable.
 */
final class InvariantRegistry
{
    /** @var Invariant[] */
    private readonly array $invariants;

    /**
     * @param Invariant[] $invariants
     */
    public function __construct(array $invariants = [])
    {
        $this->invariants = $invariants;
    }

    /**
     * Registered invariants sorted deterministically by id() (ascending strcmp).
     *
     * @return Invariant[]
     */
    public function all(): array
    {
        $sorted = $this->invariants;

        usort(
            $sorted,
            static fn (Invariant $a, Invariant $b): int => strcmp($a->id(), $b->id()),
        );

        return $sorted;
    }
}
