<?php

declare(strict_types=1);

namespace IntentPHP\Guard\AI;

use Illuminate\Support\Facades\Log;
use IntentPHP\Guard\AI\Cli\CliAdapterInterface;
use IntentPHP\Guard\AI\Cli\ProcessRunnerInterface;

class CliAiClient implements AiClientInterface
{
    public function __construct(
        private readonly CliAdapterInterface $adapter,
        private readonly ProcessRunnerInterface $runner,
        private readonly string $binary,
        private readonly string $args,
        private readonly int $timeout,
        private readonly string $promptPrefix = '',
    ) {}

    public function isAvailable(): bool
    {
        try {
            $which = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
            $result = $this->runner->run([$which, $this->binary], '', 5);

            return $result->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function generate(string $prompt): string
    {
        if (! $this->isAvailable()) {
            return "[AI unavailable — CLI command '{$this->binary}' not found in PATH.]";
        }

        $fullPrompt = $this->promptPrefix !== ''
            ? $this->promptPrefix . "\n\n" . $prompt
            : $prompt;

        $command = $this->adapter->buildCommand($this->binary, $this->args);

        try {
            $result = $this->runner->run($command, $fullPrompt, $this->timeout);
        } catch (\Throwable $e) {
            Log::warning('Guard CLI AI process error', [
                'binary' => $this->binary,
                'message' => $e->getMessage(),
            ]);

            return "[AI CLI error: {$e->getMessage()}]";
        }

        if (! $result->isSuccessful()) {
            Log::warning('Guard CLI AI command failed', [
                'binary' => $this->binary,
                'exit_code' => $result->exitCode,
                'stderr' => mb_substr($result->stderr, 0, 500),
            ]);

            return "[AI CLI command '{$this->binary}' exited with code {$result->exitCode}. Check logs for details.]";
        }

        if (trim($result->stdout) === '') {
            return '[AI CLI returned empty output.]';
        }

        return $this->adapter->parseOutput($result->stdout);
    }
}
