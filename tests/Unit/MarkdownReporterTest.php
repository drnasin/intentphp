<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Console\Command;
use IntentPHP\Guard\Report\MarkdownReporter;
use IntentPHP\Guard\Scan\Finding;
use PHPUnit\Framework\TestCase;

class MarkdownReporterTest extends TestCase
{
    private string $capturedOutput = '';

    private function makeReporter(): MarkdownReporter
    {
        $command = $this->createMock(Command::class);
        $command->method('line')->willReturnCallback(function (string $text) {
            $this->capturedOutput .= $text . "\n";
        });

        return new MarkdownReporter($command);
    }

    public function test_renders_header_and_summary_table(): void
    {
        $reporter = $this->makeReporter();
        $findings = [
            Finding::high(
                check: 'route-authorization',
                message: 'Missing auth on /orders',
                file: '/app/Http/Controllers/OrderController.php',
                line: 25,
                context: [],
                fix_hint: 'Add auth middleware',
            ),
        ];

        $reporter->report($findings);

        $this->assertStringContainsString('## Guard Security Scan Report', $this->capturedOutput);
        $this->assertStringContainsString('| Total findings | 1 |', $this->capturedOutput);
        $this->assertStringContainsString('| Active | 1 |', $this->capturedOutput);
        $this->assertStringContainsString('| HIGH | 1 |', $this->capturedOutput);
    }

    public function test_groups_findings_by_check(): void
    {
        $reporter = $this->makeReporter();
        $findings = [
            Finding::high(
                check: 'route-authorization',
                message: 'Missing auth on /orders',
                file: '/app/Http/Controllers/OrderController.php',
                line: 25,
                context: [],
                fix_hint: 'Add auth',
            ),
            Finding::high(
                check: 'dangerous-query-input',
                message: 'Unsafe orderBy',
                file: '/app/Http/Controllers/OrderController.php',
                line: 30,
                context: ['snippet' => '->orderBy($request->input("sort"))'],
                fix_hint: 'Use allowlist',
            ),
        ];

        $reporter->report($findings);

        $this->assertStringContainsString('### route-authorization (1)', $this->capturedOutput);
        $this->assertStringContainsString('### dangerous-query-input (1)', $this->capturedOutput);
    }

    public function test_shows_all_clear_when_no_active_findings(): void
    {
        $reporter = $this->makeReporter();
        $suppressed = Finding::high(
            check: 'route-authorization',
            message: 'Suppressed finding',
            file: null,
            line: null,
            context: [],
            fix_hint: '',
        )->withSuppression('baseline');

        $reporter->report([$suppressed]);

        $this->assertStringContainsString('No active findings. All clear!', $this->capturedOutput);
    }

    public function test_includes_suppressed_section_when_requested(): void
    {
        $reporter = $this->makeReporter();
        $active = Finding::high(
            check: 'route-authorization',
            message: 'Active finding',
            file: null,
            line: null,
            context: [],
            fix_hint: '',
        );
        $suppressed = Finding::high(
            check: 'route-authorization',
            message: 'Suppressed finding',
            file: null,
            line: null,
            context: [],
            fix_hint: '',
        )->withSuppression('baseline');

        $reporter->report([$active, $suppressed], ['include_suppressed' => true]);

        $this->assertStringContainsString('Suppressed findings (1)', $this->capturedOutput);
        $this->assertStringContainsString('~~Suppressed finding~~', $this->capturedOutput);
    }

    public function test_renders_ai_suggestion_in_details_block(): void
    {
        $reporter = $this->makeReporter();
        $finding = Finding::high(
            check: 'route-authorization',
            message: 'Missing auth',
            file: '/app/Http/Controllers/OrderController.php',
            line: 10,
            context: [],
            fix_hint: 'Add auth',
        )->withAiSuggestion('Add $this->authorize("view") at line 10.');

        $reporter->report([$finding]);

        $this->assertStringContainsString('<details><summary>AI Suggested Fix</summary>', $this->capturedOutput);
        $this->assertStringContainsString('authorize', $this->capturedOutput);
    }

    public function test_renders_footer_with_attribution(): void
    {
        $reporter = $this->makeReporter();
        $findings = [
            Finding::high(
                check: 'route-authorization',
                message: 'Test',
                file: null,
                line: null,
                context: [],
                fix_hint: '',
            ),
        ];

        $reporter->report($findings);

        $this->assertStringContainsString('Generated by [IntentPHP Guard]', $this->capturedOutput);
    }
}
