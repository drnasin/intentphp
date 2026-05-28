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
            'dangerous-query-input' => self::dangerousQueryIdentifier($finding),
            'intent-auth' => self::intentAuthIdentifier($finding),
            'intent-mass-assignment' => self::intentMassAssignmentIdentifier($finding),
            'intent-drift/auth' => self::intentDriftAuthIdentifier($finding),
            'intent-drift/mass-assignment' => self::intentDriftMassAssignmentIdentifier($finding),
            default => self::snippetIdentifier($finding),
        };
    }

    private static function routeIdentifier(Finding $finding): string
    {
        $methods = self::normalizeMethods($finding->context['methods'] ?? []);
        $uri = $finding->context['uri'] ?? '';
        $action = $finding->context['action'] ?? '';

        return "route:{$methods}:{$uri}:{$action}";
    }

    /**
     * Canonical method string for fingerprints: HEAD excluded, sorted.
     * Mirrors IntentAuthCheck / RouteIdentifier so the same route yields the
     * same fingerprint regardless of HTTP-method registration order.
     *
     * @param string[] $methods
     */
    private static function normalizeMethods(array $methods): string
    {
        $methods = array_values(array_filter(
            $methods,
            static fn (string $m): bool => $m !== 'HEAD',
        ));
        sort($methods);

        return implode(',', $methods);
    }

    private static function modelIdentifier(Finding $finding): string
    {
        $model = $finding->context['model'] ?? '';
        $pattern = $finding->context['pattern'] ?? '';

        return "model:{$model}:{$pattern}";
    }

    private static function intentAuthIdentifier(Finding $finding): string
    {
        $ruleIds = $finding->context['matched_rule_ids'] ?? [];
        sort($ruleIds);
        $firstRuleId = $ruleIds[0] ?? '';

        $routeName = $finding->context['route_name'] ?? '';
        $uri = $finding->context['uri'] ?? '';
        $routeKey = $routeName !== '' ? $routeName : $uri;

        $methods = $finding->context['methods'] ?? [];
        sort($methods);
        $methodsStr = implode(',', $methods);

        return "intent:auth:{$firstRuleId}:route:{$routeKey}:methods:{$methodsStr}";
    }

    private static function intentMassAssignmentIdentifier(Finding $finding): string
    {
        $fqcn = $finding->context['model_fqcn'] ?? '';
        $pattern = $finding->context['pattern'] ?? '';

        return "intent:mass-assignment:{$fqcn}:{$pattern}";
    }

    private static function intentDriftAuthIdentifier(Finding $finding): string
    {
        $ruleId = $finding->context['rule_id'] ?? '';
        $routeIdentifier = $finding->context['route_identifier'] ?? '';

        return "drift:auth:{$ruleId}:{$routeIdentifier}";
    }

    private static function intentDriftMassAssignmentIdentifier(Finding $finding): string
    {
        $fqcn = $finding->context['model_fqcn'] ?? '';
        $driftType = $finding->context['drift_type'] ?? '';

        return "drift:mass-assignment:{$fqcn}:{$driftType}";
    }

    /**
     * Dangerous-query identity is the sink method + pattern, NOT the raw source
     * snippet — so reformatting the flagged line (or wrapping a long call across
     * lines) does not churn the fingerprint and break baseline suppression.
     */
    private static function dangerousQueryIdentifier(Finding $finding): string
    {
        $sink = $finding->context['sink'] ?? '';
        $pattern = $finding->context['pattern'] ?? '';

        return "dangerous-query:{$sink}:{$pattern}";
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

        // Extract the relative path from a common Laravel base directory.
        // (?:^|.*/) requires the segment to sit at the string start or right
        // after a slash, so "myapp/" is never mistaken for an "app/" segment.
        // The .*/ alternative is greedy, so the LAST app/|tests/|routes/
        // segment wins — a machine-specific prefix (e.g. /home/app/project/app/)
        // is stripped rather than leaked into the fingerprint.
        if (preg_match('#(?:^|.*/)((?:app|tests|routes)/.*)$#', $path, $m)) {
            return $m[1];
        }

        return basename($path);
    }
}
