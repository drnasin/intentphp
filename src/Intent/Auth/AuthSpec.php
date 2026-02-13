<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Auth;

final readonly class AuthSpec
{
    /**
     * @param array<string, string> $guards     name → driver
     * @param array<string, array>  $roles      name → metadata
     * @param array<string, string> $abilities  name → description
     * @param AuthRule[]            $rules
     */
    public function __construct(
        public array $guards = [],
        public array $roles = [],
        public array $abilities = [],
        public array $rules = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $guards = [];
        foreach (($data['guards'] ?? []) as $name => $driver) {
            $guards[trim((string) $name)] = is_string($driver) ? trim($driver) : (string) ($driver['driver'] ?? '');
        }

        $roles = [];
        foreach (($data['roles'] ?? []) as $name => $meta) {
            $roles[trim((string) $name)] = is_array($meta) ? $meta : [];
        }

        $abilities = [];
        foreach (($data['abilities'] ?? []) as $name => $desc) {
            $abilities[trim((string) $name)] = trim((string) ($desc['description'] ?? $desc ?? ''));
        }

        $rules = [];
        foreach (($data['rules'] ?? []) as $ruleData) {
            if (is_array($ruleData)) {
                $rules[] = AuthRule::fromArray($ruleData);
            }
        }

        // Sort rules by id for canonical ordering
        usort($rules, static fn(AuthRule $a, AuthRule $b): int => strcmp($a->id, $b->id));

        return new self(
            guards: $guards,
            roles: $roles,
            abilities: $abilities,
            rules: $rules,
        );
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
        $result = [];

        if ($this->guards !== []) {
            $result['guards'] = $this->guards;
        }

        if ($this->roles !== []) {
            $result['roles'] = $this->roles;
        }

        if ($this->abilities !== []) {
            $result['abilities'] = $this->abilities;
        }

        if ($this->rules !== []) {
            $result['rules'] = array_map(
                static fn(AuthRule $r): array => $r->toArray(),
                $this->rules,
            );
        }

        return $result;
    }
}
