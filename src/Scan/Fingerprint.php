<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Scan;

class Fingerprint
{
    public static function compute(Finding $finding): string
    {
        $parts = [
            $finding->check,
            $finding->severity,
            self::normalizePath($finding->file),
            (string) $finding->line,
            self::primaryIdentifier($finding),
        ];

        return sha1(implode('|', $parts));
    }

    private static function primaryIdentifier(Finding $finding): string
    {
        return match ($finding->check) {
            'route-authorization' => self::routeIdentifier($finding),
            'mass-assignment' => self::modelIdentifier($finding),
            default => self::snippetIdentifier($finding),
        };
    }

    private static function routeIdentifier(Finding $finding): string
    {
        $methods = implode(',', $finding->context['methods'] ?? []);
        $uri = $finding->context['uri'] ?? '';
        $action = $finding->context['action'] ?? '';

        return "route:{$methods}:{$uri}:{$action}";
    }

    private static function modelIdentifier(Finding $finding): string
    {
        $model = $finding->context['model'] ?? '';
        $pattern = $finding->context['pattern'] ?? '';

        return "model:{$model}:{$pattern}";
    }

    private static function snippetIdentifier(Finding $finding): string
    {
        $snippet = trim($finding->context['snippet'] ?? '');

        return 'snippet:' . sha1($snippet);
    }

    public static function normalizePath(?string $path): string
    {
        if ($path === null || $path === '') {
            return '';
        }

        $path = str_replace('\\', '/', $path);

        // Extract relative path from common Laravel base patterns
        if (preg_match('#(app/.*)$#', $path, $m)) {
            return $m[1];
        }

        if (preg_match('#(tests/.*)$#', $path, $m)) {
            return $m[1];
        }

        if (preg_match('#(routes/.*)$#', $path, $m)) {
            return $m[1];
        }

        return basename($path);
    }
}
