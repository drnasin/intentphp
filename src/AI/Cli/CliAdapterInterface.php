<?php

declare(strict_types=1);

namespace IntentPHP\Guard\AI\Cli;

interface CliAdapterInterface
{
    /**
     * Build the command array for execution.
     *
     * @return string[]
     */
    public function buildCommand(string $binary, string $userArgs): array;

    /**
     * Parse/clean the raw stdout from the CLI tool.
     */
    public function parseOutput(string $rawOutput): string;
}
