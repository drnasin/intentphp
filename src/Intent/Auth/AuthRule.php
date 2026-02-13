<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Auth;

use IntentPHP\Guard\Intent\Selector\RouteSelector;

final readonly class AuthRule
{
    public function __construct(
        public string $id,
        public RouteSelector $match,
        public AuthRequirement $require,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: trim((string) ($data['id'] ?? '')),
            match: RouteSelector::fromArray($data['match'] ?? []),
            require: AuthRequirement::fromArray($data['require'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'match' => ['routes' => $this->match->toArray()],
            'require' => $this->require->toArray(),
        ];
    }
}
