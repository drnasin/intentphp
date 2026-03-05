<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Mapping;

final readonly class MappingEntry
{
    public const LINK_SPEC_LINKED = 'spec_linked';
    public const LINK_OBSERVED_ONLY = 'observed_only';

    /**
     * @param string      $linkType     "spec_linked" or "observed_only"
     * @param string|null $specType     "auth_rule", "model_spec", or null (observed-only)
     * @param string|null $specId       Rule ID or model FQCN, or null (observed-only)
     * @param string      $targetType   "route" or "model"
     * @param string      $targetId     Stable identifier (RouteIdentifier::composite() or FQCN)
     * @param array<string, mixed> $targetDetail Machine-readable detail
     */
    public function __construct(
        public string $linkType,
        public ?string $specType,
        public ?string $specId,
        public string $targetType,
        public string $targetId,
        public array $targetDetail,
    ) {}

    public function isSpecLinked(): bool
    {
        return $this->linkType === self::LINK_SPEC_LINKED;
    }

    public function isObservedOnly(): bool
    {
        return $this->linkType === self::LINK_OBSERVED_ONLY;
    }

    public function sortKey(): string
    {
        return implode('|', [
            $this->targetType,
            $this->targetId,
            $this->linkType,
            $this->specType ?? '',
            $this->specId ?? '',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'link_type' => $this->linkType,
            'spec_type' => $this->specType,
            'spec_id' => $this->specId,
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'target_detail' => $this->targetDetail,
        ];
    }
}
