<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Auth;

final readonly class AuthRequirement
{
    /**
     * @param bool $authenticated  Whether the endpoint requires authentication
     * @param bool $public         Whether the endpoint is intentionally public
     * @param string|null $reason  Reason for public access (required when public=true)
     * @param string|null $guard   Guard name (must exist in auth.guards)
     * @param string[]|null $rolesAny     At least one of these roles required
     * @param string[]|null $abilitiesAny At least one of these abilities required
     */
    public function __construct(
        public bool $authenticated = true,
        public bool $public = false,
        public ?string $reason = null,
        public ?string $guard = null,
        public ?array $rolesAny = null,
        public ?array $abilitiesAny = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $rolesAny = isset($data['rolesAny']) && is_array($data['rolesAny'])
            ? array_map(static fn($r): string => trim((string) $r), $data['rolesAny'])
            : null;

        $abilitiesAny = isset($data['abilitiesAny']) && is_array($data['abilitiesAny'])
            ? array_map(static fn($a): string => trim((string) $a), $data['abilitiesAny'])
            : null;

        return new self(
            authenticated: (bool) ($data['authenticated'] ?? true),
            public: (bool) ($data['public'] ?? false),
            reason: isset($data['reason']) ? trim((string) $data['reason']) : null,
            guard: isset($data['guard']) ? trim((string) $data['guard']) : null,
            rolesAny: $rolesAny,
            abilitiesAny: $abilitiesAny,
        );
    }

    /**
     * Return a sorted, deterministic representation for stable grouping/comparison.
     *
     * @return array<string, mixed>
     */
    public function toCanonicalArray(): array
    {
        $result = [
            'authenticated' => $this->authenticated,
            'public' => $this->public,
        ];

        if ($this->guard !== null) {
            $result['guard'] = $this->guard;
        }

        if ($this->reason !== null) {
            $result['reason'] = $this->reason;
        }

        if ($this->rolesAny !== null) {
            $sorted = $this->rolesAny;
            sort($sorted);
            $result['rolesAny'] = $sorted;
        }

        if ($this->abilitiesAny !== null) {
            $sorted = $this->abilitiesAny;
            sort($sorted);
            $result['abilitiesAny'] = $sorted;
        }

        ksort($result);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = ['authenticated' => $this->authenticated];

        if ($this->public) {
            $result['public'] = true;
        }

        if ($this->reason !== null) {
            $result['reason'] = $this->reason;
        }

        if ($this->guard !== null) {
            $result['guard'] = $this->guard;
        }

        if ($this->rolesAny !== null) {
            $result['rolesAny'] = $this->rolesAny;
        }

        if ($this->abilitiesAny !== null) {
            $result['abilitiesAny'] = $this->abilitiesAny;
        }

        return $result;
    }
}
