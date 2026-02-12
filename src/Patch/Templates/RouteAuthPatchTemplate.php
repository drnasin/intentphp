<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Patch\Templates;

use IntentPHP\Guard\Patch\Patch;
use IntentPHP\Guard\Patch\PatchBuilder;
use IntentPHP\Guard\Scan\Finding;

class RouteAuthPatchTemplate implements PatchTemplateInterface
{
    private const ABILITY_MAP = [
        'index' => 'viewAny',
        'show' => 'view',
        'create' => 'create',
        'store' => 'create',
        'edit' => 'update',
        'update' => 'update',
        'destroy' => 'delete',
    ];

    public function __construct(
        private readonly PatchBuilder $patchBuilder,
    ) {}

    public function generate(Finding $finding): ?Patch
    {
        $action = $finding->context['action'] ?? null;

        if (! $action || $action === 'Closure' || ! str_contains($action, '@')) {
            return null;
        }

        [$class, $method] = explode('@', $action);

        if (! class_exists($class)) {
            return null;
        }

        try {
            $ref = new \ReflectionMethod($class, $method);
        } catch (\ReflectionException) {
            return null;
        }

        $file = $ref->getFileName();
        if ($file === false || ! is_readable($file)) {
            return null;
        }

        $lines = file($file);
        if ($lines === false) {
            return null;
        }

        $startLine = $ref->getStartLine();
        $endLine = $ref->getEndLine();

        if ($startLine === false || $endLine === false) {
            return null;
        }

        // Find the opening brace of the method body
        $braceLineIndex = null;
        for ($i = $startLine - 1; $i < $endLine; $i++) {
            if (str_contains($lines[$i], '{')) {
                $braceLineIndex = $i;
                break;
            }
        }

        if ($braceLineIndex === null) {
            return null;
        }

        // Detect indentation from the line after the brace
        $nextLineIndex = $braceLineIndex + 1;
        $indent = '        ';
        if (isset($lines[$nextLineIndex]) && preg_match('/^(\s+)/', $lines[$nextLineIndex], $m)) {
            $indent = $m[1];
        }

        $ability = self::ABILITY_MAP[$method] ?? $method;
        $model = str_replace('Controller', '', class_basename($class));

        $authLine = "{$indent}\$this->authorize('{$ability}'); // TODO: adjust ability — model: {$model}\n";

        $braceLine = $lines[$braceLineIndex];
        $nextLine = $lines[$nextLineIndex] ?? '';

        $original = rtrim($braceLine . $nextLine, "\n");
        $suggested = rtrim($braceLine . $authLine . $nextLine, "\n");

        return $this->patchBuilder->build(
            $file,
            $original,
            $suggested,
            $braceLineIndex + 1,
        );
    }
}
