<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent;

final class IntentContext
{
    /**
     * @param string[] $warnings Mutable — checks may append via addWarning()
     */
    public function __construct(
        public readonly IntentSpec $spec,
        public array $warnings = [],
    ) {}

    public function addWarning(string $warning): void
    {
        if (! in_array($warning, $this->warnings, true)) {
            $this->warnings[] = $warning;
        }
    }

    /**
     * Attempt to load and validate an intent spec from disk.
     *
     * @return array{context: self|null, errors: string[]}
     */
    public static function tryLoad(string $rootPath): array
    {
        if (! file_exists($rootPath)) {
            return ['context' => null, 'errors' => []];
        }

        $loader = new SpecLoader();

        try {
            $result = $loader->load($rootPath);
        } catch (\RuntimeException $e) {
            return ['context' => null, 'errors' => [$e->getMessage()]];
        }

        $spec = $result['spec'];
        $warnings = $result['warnings'];

        $validator = new SpecValidator();
        $validation = $validator->validate($spec);

        if ($validation['errors'] !== []) {
            return ['context' => null, 'errors' => $validation['errors']];
        }

        $warnings = array_merge($warnings, $validation['warnings']);

        return ['context' => new self($spec, $warnings), 'errors' => []];
    }
}
