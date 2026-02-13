<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class SpecLoader
{
    /**
     * Load the intent spec from a root YAML file.
     * Resolves `includes` relative to the root file's directory.
     *
     * @return array{spec: IntentSpec, warnings: string[]}
     * @throws \RuntimeException if root file missing, unparseable, or contains duplicate IDs
     */
    public function load(string $rootPath): array
    {
        if (! file_exists($rootPath)) {
            throw new \RuntimeException("Intent spec file not found: {$rootPath}");
        }

        try {
            $rootData = Yaml::parseFile($rootPath);
        } catch (ParseException $e) {
            throw new \RuntimeException("Failed to parse {$rootPath}: {$e->getMessage()}");
        }

        if (! is_array($rootData)) {
            throw new \RuntimeException("Intent spec root must be a YAML mapping, got " . gettype($rootData));
        }

        $warnings = [];
        $baseDir = dirname($rootPath);
        $includes = $rootData['includes'] ?? [];

        if (is_array($includes)) {
            foreach ($includes as $includePath) {
                $fullPath = $baseDir . DIRECTORY_SEPARATOR . $includePath;

                if (! file_exists($fullPath)) {
                    $warnings[] = "Include file not found: {$includePath}";
                    continue;
                }

                try {
                    $includeData = Yaml::parseFile($fullPath);
                } catch (ParseException $e) {
                    $warnings[] = "Failed to parse include {$includePath}: {$e->getMessage()}";
                    continue;
                }

                if (! is_array($includeData)) {
                    $warnings[] = "Include {$includePath} is not a YAML mapping, skipping.";
                    continue;
                }

                $rootData = $this->mergeData($rootData, $includeData);
            }
        }

        unset($rootData['includes']);

        $this->checkDuplicateIds($rootData);

        return [
            'spec' => IntentSpec::fromArray($rootData),
            'warnings' => $warnings,
        ];
    }

    /**
     * Load from a raw array (useful for testing).
     */
    public function loadFromArray(array $data): IntentSpec
    {
        $this->checkDuplicateIds($data);

        return IntentSpec::fromArray($data);
    }

    /**
     * Deep-merge include data into root data.
     * Keyed maps (guards, roles, abilities, models): override.
     * Indexed arrays with id (rules, findings): append.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overlay
     * @return array<string, mixed>
     */
    private function mergeData(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if (! isset($base[$key])) {
                $base[$key] = $value;
                continue;
            }

            if (is_array($value) && is_array($base[$key])) {
                if ($this->isIndexedArray($value)) {
                    // Indexed arrays (rules, findings): append
                    $base[$key] = array_merge($base[$key], $value);
                } else {
                    // Keyed maps: recursive merge (override)
                    $base[$key] = $this->mergeData($base[$key], $value);
                }
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Check for duplicate IDs across auth rules and baseline findings.
     * Duplicate ID is a non-recoverable error in v0.1.
     *
     * @throws \RuntimeException on duplicate ID
     */
    private function checkDuplicateIds(array $data): void
    {
        $allIds = [];

        foreach (($data['auth']['rules'] ?? []) as $rule) {
            if (is_array($rule) && isset($rule['id'])) {
                $id = (string) $rule['id'];
                if (isset($allIds[$id])) {
                    throw new \RuntimeException("Duplicate ID found: '{$id}'. IDs must be unique across auth rules and baseline findings.");
                }
                $allIds[$id] = true;
            }
        }

        foreach (($data['baseline']['findings'] ?? []) as $finding) {
            if (is_array($finding) && isset($finding['id'])) {
                $id = (string) $finding['id'];
                if (isset($allIds[$id])) {
                    throw new \RuntimeException("Duplicate ID found: '{$id}'. IDs must be unique across auth rules and baseline findings.");
                }
                $allIds[$id] = true;
            }
        }
    }

    private function isIndexedArray(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }

        return array_is_list($arr);
    }
}
