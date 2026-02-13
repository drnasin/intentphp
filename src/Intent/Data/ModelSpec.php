<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Data;

final readonly class ModelSpec
{
    /**
     * @param string   $fqcn                Fully-qualified class name
     * @param string   $massAssignmentMode  'explicit_allowlist' or 'guarded'
     * @param string[] $allow               Allowed mass-assignable attributes
     * @param string[] $forbid              Explicitly forbidden attributes
     */
    public function __construct(
        public string $fqcn,
        public string $massAssignmentMode,
        public array $allow = [],
        public array $forbid = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $fqcn, array $data): self
    {
        $ma = $data['massAssignment'] ?? [];
        if (! is_array($ma)) {
            $ma = [];
        }

        return new self(
            fqcn: trim($fqcn),
            massAssignmentMode: trim((string) ($ma['mode'] ?? 'explicit_allowlist')),
            allow: array_map(
                static fn($v): string => trim((string) $v),
                is_array($ma['allow'] ?? null) ? $ma['allow'] : [],
            ),
            forbid: array_map(
                static fn($v): string => trim((string) $v),
                is_array($ma['forbid'] ?? null) ? $ma['forbid'] : [],
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'massAssignment' => [
                'mode' => $this->massAssignmentMode,
            ],
        ];

        if ($this->allow !== []) {
            $result['massAssignment']['allow'] = $this->allow;
        }

        if ($this->forbid !== []) {
            $result['massAssignment']['forbid'] = $this->forbid;
        }

        return $result;
    }
}
