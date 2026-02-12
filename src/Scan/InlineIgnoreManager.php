<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Scan;

class InlineIgnoreManager
{
    /** @var array<string, string[]> Cache of file contents: path → lines */
    private array $fileCache = [];

    /**
     * Apply inline ignore suppressions to findings.
     *
     * @param Finding[] $findings
     * @return Finding[]
     */
    public function apply(array $findings): array
    {
        return array_map(function (Finding $finding) {
            if ($finding->isSuppressed()) {
                return $finding;
            }

            if ($this->isIgnored($finding)) {
                return $finding->withSuppression('inline-ignore');
            }

            return $finding;
        }, $findings);
    }

    private function isIgnored(Finding $finding): bool
    {
        if (! $finding->file || ! $finding->line) {
            return false;
        }

        $lines = $this->readFile($finding->file);
        if ($lines === null) {
            return false;
        }

        $lineIndex = $finding->line - 1;

        // Check the flagged line itself
        if (isset($lines[$lineIndex]) && $this->hasIgnoreComment($lines[$lineIndex], $finding->check)) {
            return true;
        }

        // Check the line above
        if ($lineIndex > 0 && isset($lines[$lineIndex - 1]) && $this->hasIgnoreComment($lines[$lineIndex - 1], $finding->check)) {
            return true;
        }

        return false;
    }

    private function hasIgnoreComment(string $line, string $check): bool
    {
        // Match: // guard:ignore <check-name>  OR  // guard:ignore all
        $escaped = preg_quote($check, '/');

        return (bool) preg_match('/\/\/\s*guard:ignore\s+(' . $escaped . '|all)\b/', $line);
    }

    /**
     * @return string[]|null
     */
    private function readFile(string $path): ?array
    {
        if (isset($this->fileCache[$path])) {
            return $this->fileCache[$path];
        }

        if (! is_readable($path)) {
            return null;
        }

        $lines = file($path);
        if ($lines === false) {
            return null;
        }

        $this->fileCache[$path] = $lines;

        return $lines;
    }
}
