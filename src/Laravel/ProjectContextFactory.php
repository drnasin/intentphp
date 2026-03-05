<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Laravel;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use IntentPHP\Guard\Intent\Drift\Context\ObservedModel;
use IntentPHP\Guard\Intent\Drift\Context\ObservedRoute;
use IntentPHP\Guard\Intent\Drift\Context\ProjectContext;

final class ProjectContextFactory
{
    /**
     * @param string[] $modelFqcns  FQCNs of models to observe (caller extracts from spec)
     * @return array{context: ProjectContext, warnings: string[]}
     */
    public static function fromLaravel(
        Router $router,
        array $modelFqcns = [],
        string $modelsPath = '',
    ): array {
        $modelResult = self::buildModels($modelFqcns, $modelsPath);

        return [
            'context' => new ProjectContext(
                routes: self::buildRoutes($router),
                models: $modelResult['models'],
            ),
            'warnings' => $modelResult['warnings'],
        ];
    }

    /** @return ObservedRoute[] */
    private static function buildRoutes(Router $router): array
    {
        $routes = [];

        foreach ($router->getRoutes() as $route) {
            /** @var Route $route */
            $rawMethods = $route->methods();
            $methods = array_values(array_filter(
                array_map('strtoupper', $rawMethods),
                static fn (string $m): bool => $m !== 'HEAD',
            ));
            sort($methods);

            $routes[] = new ObservedRoute(
                uri: '/' . ltrim($route->uri(), '/'),
                name: $route->getName() ?? '',
                methods: $methods,
                middleware: self::collectMiddleware($route),
                action: $route->getActionName(),
            );
        }

        return $routes;
    }

    /** @return string[] */
    private static function collectMiddleware(Route $route): array
    {
        $middleware = $route->gatherMiddleware();

        $strings = array_map(
            static fn ($m): string => is_string($m) ? $m : (is_object($m) ? get_class($m) : (string) $m),
            $middleware,
        );

        $unique = array_unique($strings);
        sort($unique);

        return array_values($unique);
    }

    /**
     * @param string[] $modelFqcns
     * @return array{models: ObservedModel[], warnings: string[]}
     */
    private static function buildModels(array $modelFqcns, string $modelsPath): array
    {
        $models = [];
        $warnings = [];

        foreach ($modelFqcns as $fqcn) {
            $filePath = self::resolveModelFile($fqcn, $modelsPath);

            if ($filePath === null) {
                $warnings[] = "Model file not found for '{$fqcn}'. Cannot verify drift compliance.";

                continue;
            }

            $contents = file_get_contents($filePath);

            if ($contents === false) {
                $warnings[] = "Could not read model file for '{$fqcn}': {$filePath}";

                continue;
            }

            $models[] = ObservedModel::fromFileContents($fqcn, $filePath, $contents);
        }

        return ['models' => $models, 'warnings' => $warnings];
    }

    private static function resolveModelFile(string $fqcn, string $modelsPath): ?string
    {
        $parts = explode('\\', $fqcn);
        $modelsIndex = array_search('Models', $parts, true);

        if ($modelsIndex !== false) {
            $relativeParts = array_slice($parts, $modelsIndex + 1);
        } else {
            $relativeParts = [end($parts)];
        }

        $relativePath = implode(DIRECTORY_SEPARATOR, $relativeParts) . '.php';
        $fullPath = $modelsPath . DIRECTORY_SEPARATOR . $relativePath;

        if (file_exists($fullPath)) {
            return $fullPath;
        }

        return null;
    }
}
