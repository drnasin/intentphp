<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Drift\Context;

final readonly class ProjectContext
{
    /** @var ObservedRoute[] */
    public array $routes;

    /** @var ObservedModel[] */
    public array $models;

    /**
     * Routes are sorted by (uri, methods). Models are sorted by fqcn.
     *
     * @param ObservedRoute[] $routes
     * @param ObservedModel[] $models
     */
    public function __construct(array $routes, array $models)
    {
        $sortedRoutes = $routes;
        usort($sortedRoutes, static fn (ObservedRoute $a, ObservedRoute $b): int => strcmp(
            $a->uri . '|' . implode(',', $a->methods),
            $b->uri . '|' . implode(',', $b->methods),
        ));
        $this->routes = $sortedRoutes;

        $sortedModels = $models;
        usort($sortedModels, static fn (ObservedModel $a, ObservedModel $b): int => strcmp($a->fqcn, $b->fqcn));
        $this->models = $sortedModels;
    }
}
