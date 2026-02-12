<?php

declare(strict_types=1);

namespace Tests\Unit;

use IntentPHP\Guard\AI\Cli\ArgParser;
use IntentPHP\Guard\AI\Cli\ClaudeAdapter;
use IntentPHP\Guard\AI\Cli\CodexAdapter;
use IntentPHP\Guard\AI\Cli\GenericAdapter;
use IntentPHP\Guard\AI\Cli\ProcessResult;
use IntentPHP\Guard\AI\Cli\ProcessRunnerInterface;
use IntentPHP\Guard\AI\CliAiClient;
use PHPUnit\Framework\TestCase;

class FakeProcessRunner implements ProcessRunnerInterface
{
    /** @var ProcessResult[] */
    private array $responses = [];

    public function willReturn(int $exitCode, string $stdout, string $stderr = ''): self
    {
        $this->responses[] = new ProcessResult($exitCode, $stdout, $stderr);

        return $this;
    }

    public function willFail(): self
    {
        return $this->willReturn(1, '', 'command not found');
    }

    public function run(array $command, string $stdin, int $timeout): ProcessResult
    {
        if (empty($this->responses)) {
            return new ProcessResult(1, '', 'No fake response configured');
        }

        return array_shift($this->responses);
    }
}

class CapturingProcessRunner implements ProcessRunnerInterface
{
    public ?string $lastStdin = null;

    public function run(array $command, string $stdin, int $timeout): ProcessResult
    {
        if ($stdin === '') {
            return new ProcessResult(0, '/usr/bin/claude', '');
        }

        $this->lastStdin = $stdin;

        return new ProcessResult(0, 'AI response', '');
    }
}

class CliAiClientTest extends TestCase
{
    // ── isAvailable ─────────────────────────────────────────────────

    public function test_is_available_returns_false_when_command_not_found(): void
    {
        $runner = new FakeProcessRunner();
        $runner->willReturn(1, '', 'not found');

        $client = new CliAiClient(
            adapter: new GenericAdapter(),
            runner: $runner,
            binary: 'nonexistent-binary',
            args: '',
            timeout: 10,
        );

        $this->assertFalse($client->isAvailable());
    }

    public function test_is_available_returns_true_when_command_exists(): void
    {
        $runner = new FakeProcessRunner();
        $runner->willReturn(0, '/usr/bin/claude');

        $client = new CliAiClient(
            adapter: new GenericAdapter(),
            runner: $runner,
            binary: 'claude',
            args: '',
            timeout: 10,
        );

        $this->assertTrue($client->isAvailable());
    }

    public function test_is_available_returns_false_on_exception(): void
    {
        $runner = $this->createMock(ProcessRunnerInterface::class);
        $runner->method('run')->willThrowException(new \RuntimeException('process error'));

        $client = new CliAiClient(
            adapter: new GenericAdapter(),
            runner: $runner,
            binary: 'claude',
            args: '',
            timeout: 10,
        );

        $this->assertFalse($client->isAvailable());
    }

    // ── generate ────────────────────────────────────────────────────

    public function test_generate_returns_error_when_unavailable(): void
    {
        $runner = new FakeProcessRunner();
        $runner->willFail(); // which/where fails

        $client = new CliAiClient(
            adapter: new GenericAdapter(),
            runner: $runner,
            binary: 'missing-cmd',
            args: '',
            timeout: 10,
        );

        $result = $client->generate('test prompt');

        $this->assertStringContainsString('not found in PATH', $result);
    }

    public function test_generate_returns_cli_output_on_success(): void
    {
        $runner = new FakeProcessRunner();
        $runner->willReturn(0, '/usr/bin/claude'); // which succeeds
        $runner->willReturn(0, 'Add $this->authorize() call.'); // CLI output

        $client = new CliAiClient(
            adapter: new GenericAdapter(),
            runner: $runner,
            binary: 'claude',
            args: '',
            timeout: 60,
        );

        $result = $client->generate('fix this vulnerability');

        $this->assertSame('Add $this->authorize() call.', $result);
    }

    public function test_generate_returns_error_on_nonzero_exit(): void
    {
        $runner = new FakeProcessRunner();
        $runner->willReturn(0, '/usr/bin/claude'); // which succeeds
        $runner->willReturn(2, '', 'API rate limit'); // CLI fails

        $client = new CliAiClient(
            adapter: new GenericAdapter(),
            runner: $runner,
            binary: 'claude',
            args: '',
            timeout: 60,
        );

        $result = $client->generate('fix this');

        $this->assertStringContainsString('exited with code 2', $result);
    }

    public function test_generate_returns_empty_output_message(): void
    {
        $runner = new FakeProcessRunner();
        $runner->willReturn(0, '/usr/bin/claude'); // which
        $runner->willReturn(0, '   '); // empty output

        $client = new CliAiClient(
            adapter: new GenericAdapter(),
            runner: $runner,
            binary: 'claude',
            args: '',
            timeout: 60,
        );

        $result = $client->generate('prompt');

        $this->assertStringContainsString('empty output', $result);
    }

    public function test_generate_prepends_prompt_prefix(): void
    {
        $runner = new CapturingProcessRunner();

        $client = new CliAiClient(
            adapter: new GenericAdapter(),
            runner: $runner,
            binary: 'claude',
            args: '',
            timeout: 60,
            promptPrefix: 'You are a security expert.',
        );

        $client->generate('Fix this bug');

        $this->assertNotNull($runner->lastStdin, 'Expected stdin to be captured');
        $this->assertStringStartsWith('You are a security expert.', $runner->lastStdin);
        $this->assertStringContainsString('Fix this bug', $runner->lastStdin);
    }

    // ── Adapter selection ───────────────────────────────────────────

    public function test_claude_adapter_strips_ansi_codes(): void
    {
        $adapter = new ClaudeAdapter();
        $input = "\033[32mSuggestion: add middleware\033[0m";

        $this->assertSame('Suggestion: add middleware', $adapter->parseOutput($input));
    }

    public function test_claude_adapter_extracts_json_when_expected(): void
    {
        $adapter = new ClaudeAdapter(expectsJson: true);
        $json = '{"suggestion": "Add auth", "patch": "--- a/file\\n+++ b/file"}';

        $result = $adapter->parseOutput($json);
        $decoded = json_decode($result, true);

        $this->assertIsArray($decoded);
        $this->assertSame('Add auth', $decoded['suggestion']);
    }

    public function test_codex_adapter_builds_command_with_args(): void
    {
        $adapter = new CodexAdapter();
        $cmd = $adapter->buildCommand('codex', '--model gpt-4');

        $this->assertSame(['codex', '--model', 'gpt-4'], $cmd);
    }

    public function test_generic_adapter_returns_trimmed_text(): void
    {
        $adapter = new GenericAdapter();

        $this->assertSame('hello world', $adapter->parseOutput("  hello world\n\n"));
    }

    public function test_generic_adapter_passes_json_through_when_expected(): void
    {
        $adapter = new GenericAdapter(expectsJson: true);
        $json = '{"suggestion": "Fix it", "patch": null}';

        $result = $adapter->parseOutput($json);
        $decoded = json_decode($result, true);

        $this->assertIsArray($decoded);
        $this->assertSame('Fix it', $decoded['suggestion']);
    }

    public function test_adapter_auto_selection_by_binary_name(): void
    {
        // This tests the selection logic that lives in ServiceProvider.
        // Here we just verify each adapter type works correctly.
        $claude = new ClaudeAdapter();
        $this->assertSame(['claude', '--print'], $claude->buildCommand('claude', '--print'));

        $codex = new CodexAdapter();
        $this->assertSame(['codex'], $codex->buildCommand('codex', ''));

        $generic = new GenericAdapter();
        $this->assertSame(['my-ai'], $generic->buildCommand('my-ai', ''));
    }

    // ── ArgParser ───────────────────────────────────────────────────

    public function test_arg_parser_empty_string(): void
    {
        $this->assertSame([], ArgParser::parse(''));
        $this->assertSame([], ArgParser::parse('   '));
    }

    public function test_arg_parser_simple_args(): void
    {
        $this->assertSame(
            ['--print', '--format', 'json'],
            ArgParser::parse('--print --format json'),
        );
    }

    public function test_arg_parser_double_quoted_args(): void
    {
        $this->assertSame(
            ['--model', 'gpt-4.1-mini', '--system', 'You are helpful'],
            ArgParser::parse('--model "gpt-4.1-mini" --system "You are helpful"'),
        );
    }

    public function test_arg_parser_single_quoted_args(): void
    {
        $this->assertSame(
            ['--prompt', 'hello world'],
            ArgParser::parse("--prompt 'hello world'"),
        );
    }

    public function test_arg_parser_mixed_quotes(): void
    {
        $this->assertSame(
            ['--a', 'double val', '--b', 'single val', '--c'],
            ArgParser::parse('--a "double val" --b \'single val\' --c'),
        );
    }

    public function test_arg_parser_tabs_as_separators(): void
    {
        $this->assertSame(
            ['--flag', 'value'],
            ArgParser::parse("--flag\tvalue"),
        );
    }
}
