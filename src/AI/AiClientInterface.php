<?php

declare(strict_types=1);

namespace IntentPHP\Guard\AI;

interface AiClientInterface
{
    public function generate(string $prompt): string;

    public function isAvailable(): bool;
}
