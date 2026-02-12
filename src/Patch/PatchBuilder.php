<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Patch;

class PatchBuilder
{
    public function build(string $file, string $original, string $suggested, int $startLine = 1): Patch
    {
        $diff = $this->unifiedDiff($file, $original, $suggested, $startLine);

        return new Patch(
            file: $file,
            original: $original,
            suggested: $suggested,
            diff: $diff,
        );
    }

    private function unifiedDiff(string $file, string $original, string $suggested, int $startLine): string
    {
        $oldLines = $this->splitLines($original);
        $newLines = $this->splitLines($suggested);

        $oldCount = count($oldLines);
        $newCount = count($newLines);

        $header = "--- a/{$file}\n+++ b/{$file}\n";
        $header .= "@@ -{$startLine},{$oldCount} +{$startLine},{$newCount} @@\n";

        $body = '';
        $maxLines = max($oldCount, $newCount);

        // Simple line-by-line diff: show removals then additions
        foreach ($oldLines as $line) {
            $body .= '-' . $line . "\n";
        }

        foreach ($newLines as $line) {
            $body .= '+' . $line . "\n";
        }

        return $header . $body;
    }

    /**
     * @return string[]
     */
    private function splitLines(string $text): array
    {
        if ($text === '') {
            return [];
        }

        return explode("\n", rtrim($text, "\n"));
    }
}
