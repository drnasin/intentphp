<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Console;

use Illuminate\Console\Command;
use IntentPHP\Guard\Console\Doctor\CheckResult;
use IntentPHP\Guard\Console\Doctor\EnvironmentChecker;

class GuardDoctorCommand extends Command
{
    protected $signature = 'guard:doctor';

    protected $description = 'Run environment diagnostics and print actionable guidance.';

    public function handle(): int
    {
        $this->line('');
        $this->line('<options=bold>IntentPHP Guard — Environment Diagnostics</>');
        $this->line('==========================================');
        $this->line('');

        $checker = new EnvironmentChecker(
            basePath: (string) base_path(),
            storagePath: (string) storage_path(),
            aiConfig: (array) config('guard.ai', []),
            cacheConfig: (array) config('guard.cache', []),
        );

        $allResults = [];

        $this->printSection('Laravel Context', [$checker->checkLaravelContext()], $allResults);
        $this->printSection('Storage / Writable', $checker->checkStorageWritable(), $allResults);
        $this->printSection('Git', $checker->checkGit(), $allResults);
        $this->printSection('Baseline', [$checker->checkBaseline()], $allResults);
        $this->printSection('AI Driver', $checker->checkAiDriver(), $allResults);
        $this->printSection('Cache', $checker->checkCache(), $allResults);

        $errors = 0;
        $warnings = 0;

        foreach ($allResults as $result) {
            if ($result->status === 'ERROR') {
                $errors++;
            } elseif ($result->status === 'WARN') {
                $warnings++;
            }
        }

        $this->line('──────────────────────────────────────────');

        if ($errors > 0) {
            $this->line("<fg=red>Result: {$errors} error(s), {$warnings} warning(s) — fix errors above before scanning.</>");
        } else {
            $this->line("<fg=green>Result: 0 error(s), {$warnings} warning(s) — all clear!</>");
        }

        $this->line('');

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param CheckResult[] $results
     * @param CheckResult[] $allResults
     */
    private function printSection(string $header, array $results, array &$allResults): void
    {
        $this->line("<options=bold>{$header}</>");

        foreach ($results as $result) {
            $tag = match ($result->status) {
                'OK' => '<fg=green>[OK]</>',
                'WARN' => '<fg=yellow>[WARN]</>',
                'ERROR' => '<fg=red>[ERROR]</>',
                default => "[{$result->status}]",
            };

            $padding = match ($result->status) {
                'OK' => '   ',
                'WARN' => ' ',
                default => '',
            };

            $this->line("  {$tag}{$padding} {$result->message}");
            $allResults[] = $result;
        }

        $this->line('');
    }
}
