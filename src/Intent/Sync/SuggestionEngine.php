<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Sync;

final class SuggestionEngine
{
    /** @var SuggestionProviderInterface[] */
    private readonly array $providers;

    /** @param SuggestionProviderInterface[] $providers */
    public function __construct(array $providers)
    {
        $this->providers = $providers;
    }

    /**
     * Run all providers and return suggestions in deterministic sorted order.
     *
     * @return Suggestion[]
     */
    public function suggest(): array
    {
        $suggestions = [];

        foreach ($this->providers as $provider) {
            array_push($suggestions, ...$provider->provide());
        }

        usort($suggestions, static fn (Suggestion $a, Suggestion $b): int => strcmp($a->sortKey(), $b->sortKey()));

        return $suggestions;
    }
}
