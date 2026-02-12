<?php

declare(strict_types=1);

namespace IntentPHP\Guard\AI;

class NullAiClient implements AiClientInterface
{
    public function generate(string $prompt): string
    {
        return '[AI suggestions unavailable — no AI driver configured. Set guard.ai.enabled = true and configure a driver.]';
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
