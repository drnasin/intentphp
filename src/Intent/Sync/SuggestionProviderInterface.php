<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Sync;

interface SuggestionProviderInterface
{
    /**
     * Generate sync suggestions.
     *
     * @return Suggestion[]
     */
    public function provide(): array;

    public function name(): string;
}
