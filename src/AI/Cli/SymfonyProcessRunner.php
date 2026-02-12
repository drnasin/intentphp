<?php

declare(strict_types=1);

namespace IntentPHP\Guard\AI\Cli;

use Symfony\Component\Process\Process;

class SymfonyProcessRunner implements ProcessRunnerInterface
{
    private const MAX_OUTPUT_BYTES = 51200; // 50 KB

    public function run(array $command, string $stdin, int $timeout): ProcessResult
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->setInput($stdin);

        $process->run();

        $stdout = $process->getOutput();

        if (strlen($stdout) > self::MAX_OUTPUT_BYTES) {
            $stdout = substr($stdout, 0, self::MAX_OUTPUT_BYTES) . "\n[output truncated at 50 KB]";
        }

        return new ProcessResult(
            $process->getExitCode() ?? 1,
            $stdout,
            $process->getErrorOutput(),
        );
    }
}
