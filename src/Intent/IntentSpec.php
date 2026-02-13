<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent;

use IntentPHP\Guard\Intent\Auth\AuthSpec;
use IntentPHP\Guard\Intent\Baseline\BaselineSpec;
use IntentPHP\Guard\Intent\Data\DataSpec;

final readonly class IntentSpec
{
    public function __construct(
        public string $version,
        public ProjectMeta $project,
        public Defaults $defaults,
        public AuthSpec $auth,
        public DataSpec $data,
        public BaselineSpec $baseline,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            version: trim((string) ($data['version'] ?? '')),
            project: ProjectMeta::fromArray(is_array($data['project'] ?? null) ? $data['project'] : []),
            defaults: Defaults::fromArray(is_array($data['defaults'] ?? null) ? $data['defaults'] : []),
            auth: isset($data['auth']) && is_array($data['auth'])
                ? AuthSpec::fromArray($data['auth'])
                : AuthSpec::empty(),
            data: isset($data['data']) && is_array($data['data'])
                ? DataSpec::fromArray($data['data'])
                : DataSpec::empty(),
            baseline: isset($data['baseline']) && is_array($data['baseline'])
                ? BaselineSpec::fromArray($data['baseline'])
                : BaselineSpec::empty(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'version' => $this->version,
            'project' => $this->project->toArray(),
            'defaults' => $this->defaults->toArray(),
        ];

        $auth = $this->auth->toArray();
        if ($auth !== []) {
            $result['auth'] = $auth;
        }

        $data = $this->data->toArray();
        if ($data !== []) {
            $result['data'] = $data;
        }

        $baseline = $this->baseline->toArray();
        if ($baseline !== []) {
            $result['baseline'] = $baseline;
        }

        return $result;
    }
}
