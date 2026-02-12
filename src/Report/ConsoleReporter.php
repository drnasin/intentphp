<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Report;

use Illuminate\Console\Command;
use IntentPHP\Guard\Scan\Finding;

class ConsoleReporter
{
    public function __construct(
        private readonly Command $command,
    ) {}

    /**
     * @param Finding[] $findings All findings including suppressed
     * @param array{max?: int, include_suppressed?: bool} $options
     */
    public function report(array $findings, array $options = []): void
    {
        $max = (int) ($options['max'] ?? 0);
        $includeSuppressed = (bool) ($options['include_suppressed'] ?? false);

        $active = array_values(array_filter($findings, fn (Finding $f) => ! $f->isSuppressed()));
        $suppressed = array_values(array_filter($findings, fn (Finding $f) => $f->isSuppressed()));

        // Summary header
        $this->printSummary($active, $suppressed, $options);

        if (empty($active) && ! $includeSuppressed) {
            $this->command->info('No active security findings. Your application looks good!');
            return;
        }

        // Active findings grouped by check
        $displayFindings = $active;
        if ($includeSuppressed) {
            $displayFindings = $findings;
        }

        if ($max > 0) {
            $displayFindings = array_slice($displayFindings, 0, $max);
        }

        $this->printTable($displayFindings);
        $this->command->newLine();
        $this->printDetails($displayFindings);

        if ($max > 0 && count($active) > $max) {
            $remaining = count($active) - $max;
            $this->command->line("<comment>... and {$remaining} more finding(s). Use --max=0 to show all.</comment>");
            $this->command->newLine();
        }

        $activeHigh = count(array_filter($active, fn (Finding $f) => $f->severity === 'high'));
        if ($activeHigh > 0) {
            $this->command->error("{$activeHigh} active HIGH severity finding(s) require attention.");
        }
    }

    /**
     * @param Finding[] $active
     * @param Finding[] $suppressed
     * @param array<string, mixed> $options
     */
    private function printSummary(array $active, array $suppressed, array $options = []): void
    {
        $highCount = count(array_filter($active, fn (Finding $f) => $f->severity === 'high'));
        $mediumCount = count(array_filter($active, fn (Finding $f) => $f->severity === 'medium'));
        $lowCount = count(array_filter($active, fn (Finding $f) => $f->severity === 'low'));

        $baselineCount = count(array_filter($suppressed, fn (Finding $f) => $f->suppressed_reason === 'baseline'));
        $inlineCount = count(array_filter($suppressed, fn (Finding $f) => $f->suppressed_reason === 'inline-ignore'));

        $total = count($active) + count($suppressed);

        $routeScanMode = (string) ($options['route_scan_mode'] ?? 'full');
        $scanMode = (string) ($options['scan_mode'] ?? 'full');
        $changedFileCount = $options['changed_file_count'] ?? null;

        $this->command->line('<fg=white;options=bold>Summary</>');
        $this->command->line("  Total findings:  {$total}");
        $this->command->line("  Active:          " . count($active) . " (<fg=red>{$highCount} HIGH</>, <fg=yellow>{$mediumCount} MEDIUM</>, <fg=green>{$lowCount} LOW</>)");

        if ($scanMode !== 'full' && $changedFileCount !== null) {
            $this->command->line("  Scan mode:       {$scanMode} ({$changedFileCount} file(s))");
        }

        if ($routeScanMode !== 'full') {
            $this->command->line("  Route scan:      {$routeScanMode}");
        }

        if (! empty($suppressed)) {
            $parts = [];
            if ($baselineCount > 0) {
                $parts[] = "{$baselineCount} baseline";
            }
            if ($inlineCount > 0) {
                $parts[] = "{$inlineCount} inline-ignore";
            }
            $this->command->line("  Suppressed:      " . count($suppressed) . " (" . implode(', ', $parts) . ")");
        }

        $this->command->newLine();
    }

    /**
     * @param Finding[] $findings
     */
    private function printTable(array $findings): void
    {
        $rows = [];

        foreach ($findings as $i => $finding) {
            $location = $finding->file
                ? basename($finding->file) . ($finding->line ? ":{$finding->line}" : '')
                : '—';

            $status = $finding->isSuppressed()
                ? '<fg=gray>[suppressed]</>'
                : '';

            $rows[] = [
                $i + 1,
                $this->formatSeverity($finding->severity),
                $finding->check,
                $this->truncate($finding->message, 55),
                $location,
                $status,
            ];
        }

        $this->command->table(
            ['#', 'Severity', 'Check', 'Message', 'Location', ''],
            $rows,
        );
    }

    /**
     * @param Finding[] $findings
     */
    private function printDetails(array $findings): void
    {
        foreach ($findings as $i => $finding) {
            $prefix = $finding->isSuppressed() ? '<fg=gray>' : '<fg=white>';
            $suffix = $finding->isSuppressed() ? '</>' : '</>';

            $this->command->line(sprintf(
                '<comment>[%d]</comment> %s%s%s',
                $i + 1,
                $prefix,
                $finding->message,
                $suffix,
            ));

            if ($finding->isSuppressed()) {
                $this->command->line(sprintf('    Suppressed: %s', $finding->suppressed_reason));
            }

            if ($finding->file) {
                $this->command->line(sprintf(
                    '    File: %s%s',
                    $finding->file,
                    $finding->line ? ":{$finding->line}" : '',
                ));
            }

            if (! empty($finding->context['snippet'])) {
                $this->command->line(sprintf('    Code: %s', $finding->context['snippet']));
            }

            if (! empty($finding->context['policy'])) {
                $this->command->line(sprintf('    Policy: %s', $finding->context['policy']));
            }

            if (! empty($finding->context['ability'])) {
                $this->command->line(sprintf('    Ability: %s', $finding->context['ability']));
            }

            if ($finding->fix_hint && ! $finding->isSuppressed()) {
                $this->command->line(sprintf('    Fix:  %s', $finding->fix_hint));
            }

            if ($finding->ai_suggestion !== null) {
                $this->command->newLine();
                $this->command->line('    <fg=cyan>=== AI Suggested Fix ===</>');
                foreach (explode("\n", $finding->ai_suggestion) as $suggestionLine) {
                    $this->command->line('    ' . $suggestionLine);
                }
                $this->command->line('    <fg=cyan>=== End AI Suggestion ===</>');
            }

            if (! empty($finding->context['ai_patch'])) {
                $this->command->line('    <fg=cyan>AI provided a patch proposal (not applied). Run guard:fix to generate deterministic patches, or copy from output.</>');
            }

            $this->command->newLine();
        }
    }

    private function formatSeverity(string $severity): string
    {
        return match ($severity) {
            'high' => '<fg=red;options=bold>HIGH</>',
            'medium' => '<fg=yellow>MEDIUM</>',
            'low' => '<fg=green>LOW</>',
            default => strtoupper($severity),
        };
    }

    private function truncate(string $text, int $length): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length - 3) . '...';
    }
}
