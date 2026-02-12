<?php

declare(strict_types=1);

namespace IntentPHP\Guard\AI\Cli;

interface ProcessRunnerInterface
{
    /**
     * Run a command with stdin input and timeout.
     *
     * @param string[] $command
     */
    public function run(array $command, string $stdin, int $timeout): ProcessResult;
}
