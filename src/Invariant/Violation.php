<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Invariant;

/**
 * A single invariant violation.
 *
 * Framework-free DTO. Carries the stable target identity plus the exact
 * presentation fields an {@see \IntentPHP\Guard\Checks\Invariant\InvariantCheck}
 * needs to build a parity-stable {@see \IntentPHP\Guard\Scan\Finding}.
 *
 * The fingerprint contract (see DECISIONS D-007): `invariantId` is the LEGACY
 * check name (e.g. "route-authorization", "mass-assignment") so migrated rules
 * emit findings with identical fingerprints and committed baselines do not
 * churn. `targetId` is the human-stable identity of the violated target; it is
 * part of this contract but is NOT consumed by the fingerprint — Fingerprint
 * derives the per-check primary identifier from `context`.
 */
final readonly class Violation
{
    /**
     * @param array<string, mixed> $context Stable, machine-readable context.
     *                                       Key insertion order is significant
     *                                       and must match the legacy check.
     */
    public function __construct(
        public string $invariantId,
        public string $targetId,
        public string $severity,
        public string $message,
        public ?string $file,
        public ?int $line,
        public array $context,
        public string $fixHint,
    ) {}
}
