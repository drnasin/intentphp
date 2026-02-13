<?php

declare(strict_types=1);

namespace IntentPHP\Guard;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use IntentPHP\Guard\AI\AiClientInterface;
use IntentPHP\Guard\AI\Cli\ClaudeAdapter;
use IntentPHP\Guard\AI\Cli\CliAdapterInterface;
use IntentPHP\Guard\AI\Cli\CodexAdapter;
use IntentPHP\Guard\AI\Cli\GenericAdapter;
use IntentPHP\Guard\AI\Cli\SymfonyProcessRunner;
use IntentPHP\Guard\AI\CliAiClient;
use IntentPHP\Guard\AI\NullAiClient;
use IntentPHP\Guard\AI\OpenAiClient;
use IntentPHP\Guard\Console\GuardApplyCommand;
use IntentPHP\Guard\Console\GuardBaselineCommand;
use IntentPHP\Guard\Console\GuardDoctorCommand;
use IntentPHP\Guard\Console\GuardFixCommand;
use IntentPHP\Guard\Console\GuardScanCommand;
use IntentPHP\Guard\Console\GuardTestGenCommand;

class GuardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/guard.php', 'guard');

        $this->app->bind(AiClientInterface::class, function () {
            /** @var array<string, mixed> $aiConfig */
            $aiConfig = config('guard.ai', []);

            $driver = (string) ($aiConfig['driver'] ?? 'null');
            $enabled = (bool) ($aiConfig['enabled'] ?? false);

            if (! $enabled || $driver === 'null') {
                return new NullAiClient();
            }

            if ($driver === 'cli') {
                return $this->buildCliClient($aiConfig);
            }

            if ($driver === 'openai') {
                return $this->buildOpenAiClient($aiConfig);
            }

            if ($driver === 'auto') {
                // 1. Try local CLI first (free, no API cost)
                $cliClient = $this->buildCliClient($aiConfig);

                if ($cliClient->isAvailable()) {
                    return $cliClient;
                }

                // 2. Try OpenAI API if key is configured
                $openAiClient = $this->buildOpenAiClient($aiConfig);

                if ($openAiClient->isAvailable()) {
                    return $openAiClient;
                }

                Log::info('Guard AI auto: no CLI tool in PATH and no API key set. Falling back to null driver.');

                return new NullAiClient();
            }

            Log::warning("Guard AI: unknown driver \"{$driver}\". Falling back to null driver.");

            return new NullAiClient();
        });
    }

    private function buildCliClient(array $aiConfig): CliAiClient
    {
        /** @var array<string, mixed> $cliConfig */
        $cliConfig = $aiConfig['cli'] ?? [];

        $binary = (string) ($cliConfig['command'] ?? 'claude');
        $args = (string) ($cliConfig['args'] ?? '');
        $timeout = (int) ($cliConfig['timeout'] ?? 60);
        $promptPrefix = (string) ($cliConfig['prompt_prefix'] ?? '');

        $adapter = $this->resolveAdapter($cliConfig);

        return new CliAiClient(
            adapter: $adapter,
            runner: new SymfonyProcessRunner(),
            binary: $binary,
            args: $args,
            timeout: $timeout,
            promptPrefix: $promptPrefix,
        );
    }

    private function buildOpenAiClient(array $aiConfig): OpenAiClient
    {
        /** @var array<string, mixed> $openaiConfig */
        $openaiConfig = $aiConfig['openai'] ?? [];

        return new OpenAiClient(
            baseUrl: (string) ($openaiConfig['base_url'] ?? 'https://api.openai.com/v1'),
            apiKey: (string) ($openaiConfig['api_key'] ?? ''),
            model: (string) ($openaiConfig['model'] ?? 'gpt-4.1-mini'),
            timeout: (int) ($openaiConfig['timeout'] ?? 30),
            maxTokens: (int) ($openaiConfig['max_tokens'] ?? 1024),
        );
    }

    /**
     * @param array<string, mixed> $cliConfig
     */
    private function resolveAdapter(array $cliConfig): CliAdapterInterface
    {
        $adapterName = (string) ($cliConfig['adapter'] ?? 'auto');
        $expectsJson = (bool) ($cliConfig['expects_json'] ?? false);

        if ($adapterName === 'auto') {
            $command = (string) ($cliConfig['command'] ?? 'claude');
            $adapterName = match (true) {
                str_contains($command, 'claude') => 'claude',
                str_contains($command, 'codex') => 'codex',
                default => 'generic',
            };
        }

        return match ($adapterName) {
            'claude' => new ClaudeAdapter($expectsJson),
            'codex' => new CodexAdapter($expectsJson),
            default => new GenericAdapter($expectsJson),
        };
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GuardScanCommand::class,
                GuardBaselineCommand::class,
                GuardFixCommand::class,
                GuardTestGenCommand::class,
                GuardApplyCommand::class,
                GuardDoctorCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/guard.php' => config_path('guard.php'),
            ], 'guard-config');
        }
    }
}
