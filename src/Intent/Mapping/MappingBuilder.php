<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Mapping;

use IntentPHP\Guard\Intent\Auth\AuthRule;
use IntentPHP\Guard\Intent\Drift\Context\ObservedModel;
use IntentPHP\Guard\Intent\Drift\Context\ObservedRoute;
use IntentPHP\Guard\Intent\Drift\Context\ProjectContext;
use IntentPHP\Guard\Intent\Drift\RouteIdentifier;
use IntentPHP\Guard\Intent\IntentSpec;

final class MappingBuilder
{
    public function build(?IntentSpec $spec, ProjectContext $context): MappingIndex
    {
        $entries = [];

        $linkedRouteIds = [];
        $linkedModelFqcns = [];

        if ($spec !== null) {
            // Auth rules → routes
            foreach ($spec->auth->rules as $rule) {
                foreach ($context->routes as $route) {
                    if ($this->ruleMatchesRoute($rule, $route)) {
                        $routeId = RouteIdentifier::composite($route);
                        $linkedRouteIds[$routeId] = true;

                        $entries[] = new MappingEntry(
                            linkType: MappingEntry::LINK_SPEC_LINKED,
                            specType: 'auth_rule',
                            specId: $rule->id,
                            targetType: 'route',
                            targetId: $routeId,
                            targetDetail: self::routeDetail($route),
                        );
                    }
                }
            }

            // Model specs → observed models
            foreach ($spec->data->models as $fqcn => $modelSpec) {
                foreach ($context->models as $observed) {
                    if ($observed->fqcn === $fqcn) {
                        $linkedModelFqcns[$fqcn] = true;

                        $entries[] = new MappingEntry(
                            linkType: MappingEntry::LINK_SPEC_LINKED,
                            specType: 'model_spec',
                            specId: $fqcn,
                            targetType: 'model',
                            targetId: $fqcn,
                            targetDetail: self::modelDetail($observed),
                        );

                        break;
                    }
                }
            }
        }

        // Observed-only routes
        foreach ($context->routes as $route) {
            $routeId = RouteIdentifier::composite($route);

            if (!isset($linkedRouteIds[$routeId])) {
                $entries[] = new MappingEntry(
                    linkType: MappingEntry::LINK_OBSERVED_ONLY,
                    specType: null,
                    specId: null,
                    targetType: 'route',
                    targetId: $routeId,
                    targetDetail: self::routeDetail($route),
                );
            }
        }

        // Observed-only models (only when spec is present; no FQCN discovery source without spec)
        if ($spec !== null) {
            foreach ($context->models as $observed) {
                if (!isset($linkedModelFqcns[$observed->fqcn])) {
                    $entries[] = new MappingEntry(
                        linkType: MappingEntry::LINK_OBSERVED_ONLY,
                        specType: null,
                        specId: null,
                        targetType: 'model',
                        targetId: $observed->fqcn,
                        targetDetail: self::modelDetail($observed),
                    );
                }
            }
        }

        return new MappingIndex($entries);
    }

    private function ruleMatchesRoute(AuthRule $rule, ObservedRoute $route): bool
    {
        $selector = $rule->match;

        if ($selector->methods !== null) {
            $normalizedSelectorMethods = array_map('strtoupper', $selector->methods);
            $intersection = array_intersect($route->methods, $normalizedSelectorMethods);

            if ($intersection === []) {
                return false;
            }
        }

        foreach ($route->methods as $method) {
            if ($selector->matches($route->name, $route->uri, $method)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function routeDetail(ObservedRoute $route): array
    {
        return [
            'uri' => $route->uri,
            'route_name' => $route->name,
            'methods' => $route->methods,
            'action' => $route->action,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function modelDetail(ObservedModel $model): array
    {
        return [
            'fqcn' => $model->fqcn,
            'has_fillable' => $model->hasFillable,
            'fillable_attrs' => $model->fillableAttrs,
            'guarded_is_empty' => $model->guardedIsEmpty,
        ];
    }
}
