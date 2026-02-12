<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Patch\Templates;

use IntentPHP\Guard\Patch\Patch;
use IntentPHP\Guard\Scan\Finding;

interface PatchTemplateInterface
{
    public function generate(Finding $finding): ?Patch;
}
