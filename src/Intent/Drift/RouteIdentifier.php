<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Drift;

use IntentPHP\Guard\Intent\Drift\Context\ObservedRoute;

final class RouteIdentifier
{
    /**
     * Route key: prefer name, fallback to normalized URI.
     */
    public static function routeKey(ObservedRoute $route): string
    {
        if ($route->name !== '') {
            return 'name:' . $route->name;
        }

        return 'uri:' . self::normalizeUri($route->uri);
    }

    /**
     * Sorted methods string (HEAD excluded).
     */
    public static function methodsString(ObservedRoute $route): string
    {
        $methods = array_values(array_filter(
            $route->methods,
            static fn (string $m): bool => $m !== 'HEAD',
        ));
        sort($methods);

        return implode(',', $methods);
    }

    /**
     * Composite identifier: "{routeKey}|{sortedMethods}".
     */
    public static function composite(ObservedRoute $route): string
    {
        return self::routeKey($route) . '|' . self::methodsString($route);
    }

    /**
     * Normalize URI: ensure leading slash, strip trailing slash (except root).
     */
    public static function normalizeUri(string $uri): string
    {
        $uri = '/' . ltrim($uri, '/');

        if ($uri !== '/' && str_ends_with($uri, '/')) {
            $uri = rtrim($uri, '/');
        }

        return $uri;
    }
}
