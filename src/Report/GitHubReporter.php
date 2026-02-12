<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Report;

use Illuminate\Console\Command;
use IntentPHP\Guard\Scan\Finding;

class GitHubReporter
{
    public function __construct(
        private readonly Command $command,
    ) {}

    /**
     * Output findings as GitHub Actions workflow annotations.
     * Suppressed findings are skipped.
     *
     * @param Finding[] $findings
     */
    public function report(array $findings): void
    {
        foreach ($findings as $finding) {
            if ($finding->isSuppressed()) {
                continue;
            }

            $level = $this->mapSeverity($finding->severity);
            $params = $this->buildParams($finding);
            $message = $this->sanitize($finding->message);

            $this->command->line("::{$level} {$params}::{$message}");
        }
    }

    private function mapSeverity(string $severity): string
    {
        return match ($severity) {
            'high' => 'error',
            'medium' => 'warning',
            default => 'notice',
        };
    }

    private function buildParams(Finding $finding): string
    {
        $params = [];

        if ($finding->file) {
            $params[] = 'file=' . $this->relativePath($finding->file);
        }

        if ($finding->line) {
            $params[] = 'line=' . $finding->line;
        }

        $title = 'Guard ' . strtoupper($finding->severity) . ': ' . $finding->check;
        $params[] = 'title=' . $this->sanitize($title);

        return implode(',', $params);
    }

    private function relativePath(string $absolutePath): string
    {
        $base = base_path() . DIRECTORY_SEPARATOR;

        if (str_starts_with($absolutePath, $base)) {
            return str_replace(DIRECTORY_SEPARATOR, '/', substr($absolutePath, strlen($base)));
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', $absolutePath);
    }

    private function sanitize(string $text): string
    {
        return str_replace(
            ["\n", "\r", '%'],
            [' ', '', '%25'],
            $text,
        );
    }
}
