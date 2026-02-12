<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Patch;

final readonly class Patch
{
    public function __construct(
        public string $file,
        public string $original,
        public string $suggested,
        public string $diff,
    ) {}

    public function __toString(): string
    {
        return $this->diff;
    }
}
