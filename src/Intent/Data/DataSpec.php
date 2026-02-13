<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Data;

final readonly class DataSpec
{
    /**
     * @param array<string, ModelSpec> $models  keyed by FQCN
     */
    public function __construct(
        public array $models = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $models = [];
        foreach (($data['models'] ?? []) as $fqcn => $modelData) {
            $models[trim((string) $fqcn)] = ModelSpec::fromArray((string) $fqcn, is_array($modelData) ? $modelData : []);
        }

        ksort($models);

        return new self(models: $models);
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
        if ($this->models === []) {
            return [];
        }

        $result = [];
        foreach ($this->models as $fqcn => $model) {
            $result['models'][$fqcn] = $model->toArray();
        }

        return $result;
    }
}
