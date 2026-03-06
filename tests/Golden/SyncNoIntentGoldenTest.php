<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Golden;

use IntentPHP\Guard\Intent\Drift\Context\ObservedRoute;
use IntentPHP\Guard\Intent\Drift\Context\ProjectContext;
use IntentPHP\Guard\Intent\Mapping\MappingBuilder;
use IntentPHP\Guard\Intent\Mapping\MappingResolver;
use IntentPHP\Guard\Intent\Sync\Providers\CodeToSpecProvider;
use IntentPHP\Guard\Intent\Sync\Renderers\JsonRenderer;
use IntentPHP\Guard\Intent\Sync\SuggestionEngine;
use PHPUnit\Framework\TestCase;

class SyncNoIntentGoldenTest extends TestCase
{
    private function buildFixtures(): ProjectContext
    {
        return new ProjectContext(
            routes: [
                new ObservedRoute('/dashboard', 'dashboard', ['GET'], ['web'], 'DashboardController@index'),
                new ObservedRoute('/api/status', '', ['GET'], [], 'StatusController@index'),
            ],
            models: [],
        );
    }

    public function test_no_intent_produces_code_to_spec_only(): void
    {
        $context = $this->buildFixtures();

        // Build mapping with null spec (no intent file)
        $builder = new MappingBuilder();
        $index = $builder->build(null, $context);
        $resolver = new MappingResolver($index);

        // No drift possible without spec
        $engine = new SuggestionEngine([
            new CodeToSpecProvider($resolver),
            // No SpecToCodeProvider — no drift items without spec
        ]);

        $suggestions = $engine->suggest();
        $renderer = new JsonRenderer();
        $actualJson = $renderer->render($suggestions);
        $actual = json_decode($actualJson, true);

        // Only code_to_spec suggestions
        $this->assertSame(0, $actual['summary']['spec_to_code']);
        $this->assertGreaterThanOrEqual(1, $actual['summary']['code_to_spec']);

        foreach ($actual['suggestions'] as $s) {
            $this->assertSame('code_to_spec', $s['direction']);
            $this->assertSame('add_auth_rule', $s['action_type']);
            $this->assertNotNull($s['mapping_ids']);
        }

        // Snapshot comparison
        $expectedPath = __DIR__ . '/../fixtures/sync/no-intent/expected.json';

        if (getenv('UPDATE_GOLDEN') === '1' || !file_exists($expectedPath)) {
            $dir = dirname($expectedPath);

            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            file_put_contents($expectedPath, $actualJson . "\n");
            $this->markTestSkipped('Golden fixture generated: ' . $expectedPath);
        }

        $expected = json_decode(file_get_contents($expectedPath), true);
        $this->assertSame($expected, $actual);
    }

    public function test_determinism_no_intent_run_twice(): void
    {
        $context = $this->buildFixtures();

        $run = function () use ($context): string {
            $builder = new MappingBuilder();
            $index = $builder->build(null, $context);
            $resolver = new MappingResolver($index);

            $engine = new SuggestionEngine([
                new CodeToSpecProvider($resolver),
            ]);

            $suggestions = $engine->suggest();
            $renderer = new JsonRenderer();

            return $renderer->render($suggestions);
        };

        $this->assertSame($run(), $run());
    }
}
