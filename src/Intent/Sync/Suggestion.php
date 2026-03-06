<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Sync;

final readonly class Suggestion
{
    /**
     * @param string        $id           Stable ID: "{direction}:{actionType}:{targetId}"
     * @param string        $direction    "code_to_spec" or "spec_to_code"
     * @param string        $actionType   "add_auth_rule" or "add_middleware"
     * @param string        $targetId     RouteIdentifier::composite() value
     * @param string[]|null $mappingIds   MappingEntry::sortKey() values (sorted), null if unresolved
     * @param string        $confidence   "high", "medium", or "low"
     * @param string        $rationale    Deterministic human-readable explanation
     * @param array<string, mixed> $patch Structured action patch
     * @param array<string, mixed> $context Machine-readable metadata
     */
    public function __construct(
        public string $id,
        public string $direction,
        public string $actionType,
        public string $targetId,
        public ?array $mappingIds,
        public string $confidence,
        public string $rationale,
        public array $patch,
        public array $context,
    ) {}

    public function sortKey(): string
    {
        return "{$this->direction}|{$this->actionType}|{$this->targetId}";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'direction' => $this->direction,
            'action_type' => $this->actionType,
            'target_id' => $this->targetId,
            'mapping_ids' => $this->mappingIds,
            'confidence' => $this->confidence,
            'rationale' => $this->rationale,
            'patch' => $this->patch,
            'context' => $this->context,
        ];
    }
}
