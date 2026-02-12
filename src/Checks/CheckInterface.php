<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Checks;

use IntentPHP\Guard\Scan\Finding;

interface CheckInterface
{
    /**
     * @return Finding[]
     */
    public function run(): array;

    public function name(): string;
}
