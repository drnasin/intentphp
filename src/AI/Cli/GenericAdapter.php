<?php

declare(strict_types=1);

namespace IntentPHP\Guard\AI\Cli;

class GenericAdapter implements CliAdapterInterface
{
    public function __construct(
        private readonly bool $expectsJson = false,
    ) {}

    public function buildCommand(string $binary, string $userArgs): array
    {
        $cmd = [$binary];

        if ($userArgs !== '') {
            $cmd = array_merge($cmd, ArgParser::parse($userArgs));
        }

        return $cmd;
    }

    public function parseOutput(string $rawOutput): string
    {
        if ($this->expectsJson) {
            $trimmed = trim($rawOutput);
            $json = json_decode($trimmed, true);
            if (is_array($json)) {
                return json_encode($json, JSON_UNESCAPED_SLASHES) ?: $trimmed;
            }
        }

        return trim($rawOutput);
    }
}
