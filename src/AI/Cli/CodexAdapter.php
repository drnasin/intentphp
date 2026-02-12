<?php

declare(strict_types=1);

namespace IntentPHP\Guard\AI\Cli;

class CodexAdapter implements CliAdapterInterface
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
        $clean = $this->stripAnsi($rawOutput);

        if ($this->expectsJson) {
            return $this->tryExtractJson($clean);
        }

        return trim($clean);
    }

    private function stripAnsi(string $text): string
    {
        return preg_replace('/\033\[[0-9;]*[a-zA-Z]/', '', $text) ?? $text;
    }

    private function tryExtractJson(string $text): string
    {
        $trimmed = trim($text);

        $json = json_decode($trimmed, true);
        if (is_array($json)) {
            return json_encode($json, JSON_UNESCAPED_SLASHES) ?: $trimmed;
        }

        if (preg_match('/(\{[\s\S]*\})/', $trimmed, $m)) {
            $json = json_decode($m[1], true);
            if (is_array($json)) {
                return json_encode($json, JSON_UNESCAPED_SLASHES) ?: $trimmed;
            }
        }

        return $trimmed;
    }
}
