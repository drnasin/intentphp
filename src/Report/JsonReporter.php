<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Report;

use Illuminate\Console\Command;
use IntentPHP\Guard\Scan\Finding;

class JsonReporter
{
    public function __construct(
        private readonly Command $command,
    ) {}

    /**
     * @param Finding[] $findings All findings including suppressed
     * @param array<string, mixed> $options
     */
    public function report(array $findings, array $options = []): void
    {
        $this->command->line($this->render($findings, $options));
    }

    /**
     * Render the full JSON report as a string.
     *
     * @param Finding[] $findings All findings including suppressed
     * @param array<string, mixed> $options
     */
    public function render(array $findings, array $options = []): string
    {
        $includeSuppressed = (bool) ($options['include_suppressed'] ?? false);
        $includeAiPatch = (bool) ($options['include_ai_patch'] ?? false);
        $scanMode = (string) ($options['scan_mode'] ?? 'full');

        $active = array_values(array_filter($findings, fn (Finding $f) => ! $f->isSuppressed()));
        $suppressed = array_values(array_filter($findings, fn (Finding $f) => $f->isSuppressed()));

        $hasAi = count(array_filter($active, fn (Finding $f) => $f->ai_suggestion !== null)) > 0;

        $routeScanMode = (string) ($options['route_scan_mode'] ?? 'full');

        $output = [
            'summary' => [
                'scan_mode' => $scanMode,
                'route_scan_mode' => $routeScanMode,
                'total' => count($findings),
                'active' => count($active),
                'high' => count(array_filter($active, fn (Finding $f) => $f->severity === 'high')),
                'medium' => count(array_filter($active, fn (Finding $f) => $f->severity === 'medium')),
                'low' => count(array_filter($active, fn (Finding $f) => $f->severity === 'low')),
                'suppressed' => count($suppressed),
                'baseline_suppressed' => count(array_filter($suppressed, fn (Finding $f) => $f->suppressed_reason === 'baseline')),
                'inline_suppressed' => count(array_filter($suppressed, fn (Finding $f) => $f->suppressed_reason === 'inline-ignore')),
            ],
            'ai_enhanced' => $hasAi,
            'findings' => array_map(
                fn (Finding $f) => $this->findingToArray($f, $includeAiPatch),
                $active,
            ),
        ];

        if ($includeSuppressed && ! empty($suppressed)) {
            $output['suppressed'] = array_map(
                fn (Finding $f) => $this->findingToArray($f, $includeAiPatch),
                $suppressed,
            );
        }

        return (string) json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private function findingToArray(Finding $finding, bool $includeAiPatch): array
    {
        $data = $finding->toArray();

        if (! $includeAiPatch && isset($data['context']['ai_patch'])) {
            unset($data['context']['ai_patch']);
        }

        return $data;
    }
}
