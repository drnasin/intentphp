<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Baseline;

final readonly class BaselineFinding
{
    public function __construct(
        public string $id,
        public string $fingerprint,
        public string $reason,
        public ?string $expires = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: trim((string) ($data['id'] ?? '')),
            fingerprint: trim((string) ($data['fingerprint'] ?? '')),
            reason: trim((string) ($data['reason'] ?? '')),
            expires: isset($data['expires']) ? trim((string) $data['expires']) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'id' => $this->id,
            'fingerprint' => $this->fingerprint,
            'reason' => $this->reason,
        ];

        if ($this->expires !== null) {
            $result['expires'] = $this->expires;
        }

        return $result;
    }
}
