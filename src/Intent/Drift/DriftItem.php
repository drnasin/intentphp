<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Drift;

final readonly class DriftItem
{
    /**
     * @param string $detector  Detector name (e.g. "auth", "mass-assignment")
     * @param string $driftType Semantic drift type (e.g. "missing_auth_middleware")
     * @param string $targetId  Stable target identifier for sorting/fingerprinting
     * @param string $severity  "high", "medium", or "low"
     * @param string $message   Human-readable description
     * @param string|null $file File path (null for route-level findings)
     * @param array<string, mixed> $context Machine-readable context
     * @param string $fixHint   Suggested remediation
     */
    public function __construct(
        public string $detector,
        public string $driftType,
        public string $targetId,
        public string $severity,
        public string $message,
        public ?string $file,
        public array $context,
        public string $fixHint,
    ) {}

    public function sortKey(): string
    {
        return "{$this->detector}|{$this->targetId}|{$this->driftType}";
    }
}
