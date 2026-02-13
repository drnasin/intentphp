<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent;

use IntentPHP\Guard\Intent\Auth\AuthRule;
use IntentPHP\Guard\Intent\Baseline\BaselineFinding;
use IntentPHP\Guard\Intent\Selector\RouteSelector;

class SpecValidator
{
    private const VALID_HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];

    private const VALID_AUTH_MODES = ['deny_by_default', 'allow_by_default'];

    private const VALID_MASS_ASSIGNMENT_MODES = ['explicit_allowlist', 'guarded'];

    /**
     * Validate a loaded IntentSpec for structural and semantic correctness.
     *
     * @return array{errors: string[], warnings: string[]}
     */
    public function validate(IntentSpec $spec): array
    {
        $errors = [];
        $warnings = [];

        // 1. Version
        if ($spec->version !== '0.1') {
            $errors[] = "Unsupported spec version '{$spec->version}'. Expected '0.1'.";
        }

        // 2. Project name
        if ($spec->project->name === '') {
            $errors[] = 'Project name is required.';
        }

        // 3. Project framework
        if ($spec->project->framework !== 'laravel') {
            $errors[] = "Unsupported framework '{$spec->project->framework}'. Expected 'laravel'.";
        }

        // 4. Auth mode
        if (! in_array($spec->defaults->authMode, self::VALID_AUTH_MODES, true)) {
            $errors[] = "Invalid defaults.authMode '{$spec->defaults->authMode}'. Expected: " . implode(' or ', self::VALID_AUTH_MODES) . '.';
        }

        // 5-7. ID uniqueness (already enforced by loader, but double-check)
        $authRuleIds = [];
        foreach ($spec->auth->rules as $rule) {
            if ($rule->id === '') {
                $errors[] = 'Auth rule has empty ID.';
                continue;
            }
            if (isset($authRuleIds[$rule->id])) {
                $errors[] = "Duplicate auth rule ID: '{$rule->id}'.";
            }
            $authRuleIds[$rule->id] = true;
        }

        $baselineIds = [];
        foreach ($spec->baseline->findings as $finding) {
            if ($finding->id === '') {
                $errors[] = 'Baseline finding has empty ID.';
                continue;
            }
            if (isset($baselineIds[$finding->id])) {
                $errors[] = "Duplicate baseline finding ID: '{$finding->id}'.";
            }
            if (isset($authRuleIds[$finding->id])) {
                $errors[] = "ID collision: '{$finding->id}' is used in both auth.rules and baseline.findings.";
            }
            $baselineIds[$finding->id] = true;
        }

        // 8. Selector validity
        foreach ($spec->auth->rules as $rule) {
            $this->validateSelector($rule->id, $rule->match, $errors);
        }

        // 9. HTTP methods
        foreach ($spec->auth->rules as $rule) {
            $this->validateMethods($rule->id, $rule->match, $errors);
        }

        // 10. Guard references
        foreach ($spec->auth->rules as $rule) {
            $this->validateGuardReference($rule->id, $rule->require->guard, $spec->auth->guards, $errors);
        }

        // 11-13. Baseline expiry
        $today = date('Y-m-d');
        foreach ($spec->baseline->findings as $finding) {
            if ($finding->expires !== null) {
                $date = \DateTimeImmutable::createFromFormat('Y-m-d', $finding->expires);
                if ($date === false || $date->format('Y-m-d') !== $finding->expires) {
                    $errors[] = "Baseline finding '{$finding->id}' has invalid expiry date: '{$finding->expires}'. Expected YYYY-MM-DD.";
                } elseif ($finding->expires < $today) {
                    if ($spec->defaults->baselineExpiredIsError) {
                        $errors[] = "Baseline finding '{$finding->id}' has expired ({$finding->expires}). Remove it or extend the expiry.";
                    } else {
                        $warnings[] = "Baseline finding '{$finding->id}' has expired ({$finding->expires}).";
                    }
                }
            } elseif ($spec->defaults->baselineRequireExpiry) {
                $errors[] = "Baseline finding '{$finding->id}' requires an expiry date (defaults.baselineRequireExpiry is true).";
            }
        }

        // 14. Mass assignment mode
        foreach ($spec->data->models as $fqcn => $model) {
            if (! in_array($model->massAssignmentMode, self::VALID_MASS_ASSIGNMENT_MODES, true)) {
                $errors[] = "Model '{$fqcn}' has invalid massAssignment.mode: '{$model->massAssignmentMode}'. Expected: " . implode(' or ', self::VALID_MASS_ASSIGNMENT_MODES) . '.';
            }

            if ($model->massAssignmentMode === 'explicit_allowlist' && $model->allow === []) {
                $warnings[] = "Model '{$fqcn}' uses explicit_allowlist mode but allow list is empty.";
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private function validateSelector(string $ruleId, RouteSelector $selector, array &$errors): void
    {
        if ($selector->any !== null) {
            foreach ($selector->any as $i => $child) {
                $this->validateSelector("{$ruleId}.any[{$i}]", $child, $errors);
            }

            return;
        }

        if ($selector->isEmpty()) {
            $errors[] = "Rule '{$ruleId}' has an empty match selector.";
        }
    }

    private function validateMethods(string $ruleId, RouteSelector $selector, array &$errors): void
    {
        if ($selector->methods !== null) {
            foreach ($selector->methods as $method) {
                if (! in_array($method, self::VALID_HTTP_METHODS, true)) {
                    $errors[] = "Rule '{$ruleId}' has invalid HTTP method: '{$method}'. Valid: " . implode(', ', self::VALID_HTTP_METHODS) . '.';
                }
            }
        }

        if ($selector->any !== null) {
            foreach ($selector->any as $child) {
                $this->validateMethods($ruleId, $child, $errors);
            }
        }
    }

    /**
     * @param array<string, string> $guards
     * @param string[] $errors
     */
    private function validateGuardReference(string $ruleId, ?string $guard, array $guards, array &$errors): void
    {
        if ($guard !== null && $guard !== '' && ! isset($guards[$guard])) {
            $errors[] = "Rule '{$ruleId}' references undefined guard '{$guard}'. Define it in auth.guards.";
        }
    }
}
