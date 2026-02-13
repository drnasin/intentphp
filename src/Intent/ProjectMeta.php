<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent;

final readonly class ProjectMeta
{
    public function __construct(
        public string $name = '',
        public string $framework = 'laravel',
        public string $php = '',
        public string $laravel = '',
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: trim((string) ($data['name'] ?? '')),
            framework: trim((string) ($data['framework'] ?? 'laravel')),
            php: trim((string) ($data['php'] ?? '')),
            laravel: trim((string) ($data['laravel'] ?? '')),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'framework' => $this->framework,
            'php' => $this->php,
            'laravel' => $this->laravel,
        ];
    }
}
