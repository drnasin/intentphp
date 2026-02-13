<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent;

final readonly class Defaults
{
    public function __construct(
        public string $authMode = 'deny_by_default',
        public bool $baselineRequireExpiry = false,
        public bool $baselineExpiredIsError = true,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            authMode: trim((string) ($data['authMode'] ?? 'deny_by_default')),
            baselineRequireExpiry: (bool) ($data['baselineRequireExpiry'] ?? false),
            baselineExpiredIsError: (bool) ($data['baselineExpiredIsError'] ?? true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'authMode' => $this->authMode,
            'baselineRequireExpiry' => $this->baselineRequireExpiry,
            'baselineExpiredIsError' => $this->baselineExpiredIsError,
        ];
    }
}
