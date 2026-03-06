<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Intent\Sync;

use IntentPHP\Guard\Intent\Sync\Suggestion;
use IntentPHP\Guard\Intent\Sync\SuggestionEngine;
use IntentPHP\Guard\Intent\Sync\SuggestionProviderInterface;
use PHPUnit\Framework\TestCase;

class SuggestionEngineTest extends TestCase
{
    public function test_empty_providers_return_empty(): void
    {
        $engine = new SuggestionEngine([]);

        $this->assertSame([], $engine->suggest());
    }

    public function test_single_provider_sorted(): void
    {
        $s1 = $this->makeSuggestion('code_to_spec', 'add_auth_rule', 'uri:/zebra|GET');
        $s2 = $this->makeSuggestion('code_to_spec', 'add_auth_rule', 'uri:/alpha|GET');

        $provider = $this->createMock(SuggestionProviderInterface::class);
        $provider->method('provide')->willReturn([$s1, $s2]);

        $engine = new SuggestionEngine([$provider]);
        $result = $engine->suggest();

        $this->assertCount(2, $result);
        $this->assertStringContainsString('/alpha', $result[0]->targetId);
        $this->assertStringContainsString('/zebra', $result[1]->targetId);
    }

    public function test_multiple_providers_merged_and_sorted(): void
    {
        $codeToSpec = $this->makeSuggestion('code_to_spec', 'add_auth_rule', 'uri:/dashboard|GET');
        $specToCode = $this->makeSuggestion('spec_to_code', 'add_middleware', 'uri:/admin|GET');

        $provider1 = $this->createMock(SuggestionProviderInterface::class);
        $provider1->method('provide')->willReturn([$codeToSpec]);

        $provider2 = $this->createMock(SuggestionProviderInterface::class);
        $provider2->method('provide')->willReturn([$specToCode]);

        $engine = new SuggestionEngine([$provider2, $provider1]);
        $result = $engine->suggest();

        $this->assertCount(2, $result);
        // code_to_spec sorts before spec_to_code
        $this->assertSame('code_to_spec', $result[0]->direction);
        $this->assertSame('spec_to_code', $result[1]->direction);
    }

    public function test_determinism_run_twice_identical(): void
    {
        $s1 = $this->makeSuggestion('code_to_spec', 'add_auth_rule', 'uri:/b|GET');
        $s2 = $this->makeSuggestion('spec_to_code', 'add_middleware', 'uri:/a|GET');

        $provider = $this->createMock(SuggestionProviderInterface::class);
        $provider->method('provide')->willReturn([$s1, $s2]);

        $engine = new SuggestionEngine([$provider]);

        $run1 = $engine->suggest();
        $run2 = $engine->suggest();

        $this->assertCount(2, $run1);
        $this->assertCount(2, $run2);

        for ($i = 0; $i < count($run1); $i++) {
            $this->assertSame($run1[$i]->sortKey(), $run2[$i]->sortKey());
            $this->assertSame($run1[$i]->id, $run2[$i]->id);
        }
    }

    private function makeSuggestion(string $direction, string $actionType, string $targetId): Suggestion
    {
        return new Suggestion(
            id: "{$direction}:{$actionType}:{$targetId}",
            direction: $direction,
            actionType: $actionType,
            targetId: $targetId,
            mappingIds: null,
            confidence: 'medium',
            rationale: 'test',
            patch: [],
            context: [],
        );
    }
}
