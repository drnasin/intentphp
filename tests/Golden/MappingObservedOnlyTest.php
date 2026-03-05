<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Golden;

use IntentPHP\Guard\Intent\Drift\Context\ObservedRoute;
use IntentPHP\Guard\Intent\Drift\Context\ProjectContext;
use IntentPHP\Guard\Intent\Mapping\MappingBuilder;
use PHPUnit\Framework\TestCase;

class MappingObservedOnlyTest extends TestCase
{
    private function buildFixtures(): ProjectContext
    {
        return new ProjectContext(
            routes: [
                new ObservedRoute('/orders', 'orders.index', ['GET'], [], 'App\\Http\\Controllers\\OrderController@index'),
                new ObservedRoute('/dashboard', 'dashboard', ['GET'], [], 'App\\Http\\Controllers\\DashboardController@index'),
            ],
            models: [],
        );
    }

    public function test_observed_only_routes_mapping(): void
    {
        $context = $this->buildFixtures();

        $builder = new MappingBuilder();
        $index = $builder->build(null, $context);

        $actualJson = $index->toJson();
        $actual = json_decode($actualJson, true);

        // Structural assertions
        $this->assertSame('1.0', $actual['version']);
        $this->assertCount(2, $actual['entries']);

        // All entries must be observed_only with target_type=route
        foreach ($actual['entries'] as $entry) {
            $this->assertSame('observed_only', $entry['link_type']);
            $this->assertNull($entry['spec_type']);
            $this->assertNull($entry['spec_id']);
            $this->assertSame('route', $entry['target_type']);
        }

        // No model entries when spec is null
        $modelEntries = array_filter($actual['entries'], fn ($e) => $e['target_type'] === 'model');
        $this->assertCount(0, $modelEntries);

        // Snapshot comparison against stored fixture
        $expectedPath = __DIR__ . '/../fixtures/mapping/observed-only/expected.json';

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

    public function test_determinism_observed_only(): void
    {
        $context = $this->buildFixtures();

        $builder = new MappingBuilder();
        $index1 = $builder->build(null, $context);
        $index2 = $builder->build(null, $context);

        $this->assertSame($index1->toJson(), $index2->toJson());
        $this->assertSame($index1->checksum, $index2->checksum);
    }
}
