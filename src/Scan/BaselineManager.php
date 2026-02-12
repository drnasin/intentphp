<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Scan;

class BaselineManager
{
    /**
     * Save findings as a baseline file.
     *
     * @param Finding[] $findings
     * @return int Number of findings saved
     */
    public function save(array $findings, string $path): int
    {
        $entries = [];

        foreach ($findings as $finding) {
            $fp = $finding->fingerprint();

            // Deduplicate by fingerprint
            if (isset($entries[$fp])) {
                continue;
            }

            $entries[$fp] = [
                'fingerprint' => $fp,
                'check' => $finding->check,
                'severity' => $finding->severity,
                'message' => $finding->message,
                'file' => Fingerprint::normalizePath($finding->file),
                'line' => $finding->line,
            ];
        }

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $path,
            json_encode(array_values($entries), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        return count($entries);
    }

    /**
     * Load baseline fingerprints from file.
     *
     * @return string[]
     */
    public function load(string $path): array
    {
        if (! file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        if (! is_array($data)) {
            return [];
        }

        return array_map(
            fn (array $entry) => $entry['fingerprint'] ?? '',
            $data,
        );
    }

    /**
     * Suppress findings that match baseline fingerprints.
     *
     * @param Finding[] $findings
     * @param string[] $baselineFingerprints
     * @return Finding[]
     */
    public function suppress(array $findings, array $baselineFingerprints): array
    {
        if (empty($baselineFingerprints)) {
            return $findings;
        }

        $lookup = array_flip($baselineFingerprints);

        return array_map(function (Finding $finding) use ($lookup) {
            if ($finding->isSuppressed()) {
                return $finding;
            }

            if (isset($lookup[$finding->fingerprint()])) {
                return $finding->withSuppression('baseline');
            }

            return $finding;
        }, $findings);
    }
}
