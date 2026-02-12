<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Laravel;

use Illuminate\Routing\Router;
use IntentPHP\Guard\Cache\ScanCache;
use IntentPHP\Guard\Scan\Finding;

class ProjectMap
{
    /** @var array<string, array<string, mixed>> */
    private array $map = [];

    private bool $built = false;

    public function __construct(
        private readonly Router $router,
        private readonly ?ScanCache $cache = null,
        private readonly ?string $cacheVersion = null,
    ) {}

    /**
     * Enrich findings with project context: model, policy, ability.
     *
     * @param Finding[] $findings
     * @return Finding[]
     */
    public function enrich(array $findings): array
    {
        $this->build();

        return array_map(fn (Finding $f) => $this->enrichFinding($f), $findings);
    }

    private function build(): void
    {
        if ($this->built) {
            return;
        }

        $this->built = true;

        // Try loading from cache
        if ($this->cache !== null && $this->cacheVersion !== null) {
            $cached = $this->cache->get('project_map', $this->cacheVersion);

            if (is_array($cached)) {
                $this->map = $cached;

                return;
            }
        }

        foreach ($this->router->getRoutes() as $route) {
            $action = $route->getActionName();

            if ($action === 'Closure' || ! str_contains($action, '@')) {
                continue;
            }

            [$controller, $method] = explode('@', $action);

            $model = $this->inferModel($controller);
            $policy = $model ? $this->inferPolicy($model) : null;
            $ability = $this->inferAbility($method);

            $this->map[$action] = [
                'controller' => $controller,
                'method' => $method,
                'model' => $model,
                'model_fqcn' => $model,
                'policy' => $policy,
                'ability' => $ability,
            ];
        }

        // Store in cache
        if ($this->cache !== null && $this->cacheVersion !== null) {
            $this->cache->put('project_map', $this->map, $this->cacheVersion);
        }
    }

    private function enrichFinding(Finding $finding): Finding
    {
        $action = $finding->context['action'] ?? null;

        if (! $action || ! isset($this->map[$action])) {
            return $finding;
        }

        $info = $this->map[$action];
        $context = $finding->context;
        $changed = false;

        if ($info['model'] && ! isset($context['model_fqcn'])) {
            $context['model_fqcn'] = $info['model'];
            $changed = true;
        }

        if ($info['policy'] && ! isset($context['policy'])) {
            $context['policy'] = $info['policy'];
            $changed = true;
        }

        if ($info['ability'] && ! isset($context['ability'])) {
            $context['ability'] = $info['ability'];
            $changed = true;
        }

        if (! $changed) {
            return $finding;
        }

        return new Finding(
            $finding->check,
            $finding->severity,
            $finding->message,
            $finding->file,
            $finding->line,
            $context,
            $finding->fix_hint,
            $finding->ai_suggestion,
            $finding->suppressed_reason,
        );
    }

    private function inferModel(string $controller): ?string
    {
        $shortName = class_basename($controller);
        $modelName = str_replace('Controller', '', $shortName);

        if ($modelName === '' || $modelName === $shortName) {
            return null;
        }

        $fqcn = 'App\\Models\\' . $modelName;

        if (class_exists($fqcn)) {
            return $fqcn;
        }

        return null;
    }

    private function inferPolicy(string $model): ?string
    {
        $modelShort = class_basename($model);
        $policyClass = 'App\\Policies\\' . $modelShort . 'Policy';

        if (class_exists($policyClass)) {
            return $policyClass;
        }

        return null;
    }

    private function inferAbility(string $method): ?string
    {
        return match ($method) {
            'index' => 'viewAny',
            'show' => 'view',
            'create', 'store' => 'create',
            'edit', 'update' => 'update',
            'destroy' => 'delete',
            default => null,
        };
    }
}
