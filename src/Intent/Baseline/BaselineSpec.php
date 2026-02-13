<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Baseline;

final readonly class BaselineSpec
{
    /**
     * @param BaselineFinding[] $findings
     */
    public function __construct(
        public array $findings = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $findings = [];
        foreach (($data['findings'] ?? []) as $findingData) {
            if (is_array($findingData)) {
                $findings[] = BaselineFinding::fromArray($findingData);
            }
        }

        usort($findings, static fn(BaselineFinding $a, BaselineFinding $b): int => strcmp($a->id, $b->id));

        return new self(findings: $findings);
    }

    public static function empty(): self
    {
        return new self();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->findings === []) {
            return [];
        }

        return [
            'findings' => array_map(
                static fn(BaselineFinding $f): array => $f->toArray(),
                $this->findings,
            ),
        ];
    }
}
