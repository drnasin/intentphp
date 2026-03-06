<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Golden;

use IntentPHP\Guard\Intent\Auth\AuthRequirement;
use IntentPHP\Guard\Intent\Auth\AuthRule;
use IntentPHP\Guard\Intent\Auth\AuthSpec;
use IntentPHP\Guard\Intent\Baseline\BaselineSpec;
use IntentPHP\Guard\Intent\Data\DataSpec;
use IntentPHP\Guard\Intent\Defaults;
use IntentPHP\Guard\Intent\Drift\Context\ObservedRoute;
use IntentPHP\Guard\Intent\Drift\Context\ProjectContext;
use IntentPHP\Guard\Intent\Drift\Detectors\AuthDriftDetector;
use IntentPHP\Guard\Intent\Drift\DriftEngine;
use IntentPHP\Guard\Intent\IntentSpec;
use IntentPHP\Guard\Intent\Mapping\MappingBuilder;
use IntentPHP\Guard\Intent\Mapping\MappingResolver;
use IntentPHP\Guard\Intent\ProjectMeta;
use IntentPHP\Guard\Intent\Selector\RouteSelector;
use IntentPHP\Guard\Intent\Sync\Providers\CodeToSpecProvider;
use IntentPHP\Guard\Intent\Sync\Providers\SpecToCodeProvider;
use IntentPHP\Guard\Intent\Sync\Renderers\JsonRenderer;
use IntentPHP\Guard\Intent\Sync\SuggestionEngine;
use PHPUnit\Framework\TestCase;

class SyncSuggestionsGoldenTest extends TestCase
{
    private function buildFixtures(): array
    {
        $spec = new IntentSpec(
            version: '0.1',
            project: new ProjectMeta('golden-sync-test', 'laravel'),
            defaults: new Defaults(),
            auth: new AuthSpec(
                rules: [
                    new AuthRule(
                        id: 'require-auth-orders',
                        match: new RouteSelector(prefix: '/orders'),
                        require: new AuthRequirement(authenticated: true),
                    ),
                ],
            ),
            data: DataSpec::empty(),
            baseline: BaselineSpec::empty(),
        );

        $context = new ProjectContext(
            routes: [
                // Matched by spec rule but MISSING auth middleware → drift → spec_to_code suggestion
                new ObservedRoute('/orders', 'orders.index', ['GET'], [], 'App\\Http\\Controllers\\OrderController@index'),
                // Not matched by any spec rule → observed_only → code_to_spec suggestion
                new ObservedRoute('/dashboard', 'dashboard', ['GET'], ['web'], 'App\\Http\\Controllers\\DashboardController@index'),
                // Not matched by any spec rule → observed_only → code_to_spec suggestion
                new ObservedRoute('/admin', '', ['GET', 'POST'], [], 'App\\Http\\Controllers\\AdminController@index'),
            ],
            models: [],
        );

        return [$spec, $context];
    }

    public function test_golden_json_output_matches_expected(): void
    {
        [$spec, $context] = $this->buildFixtures();

        $builder = new MappingBuilder();
        $index = $builder->build($spec, $context);
        $resolver = new MappingResolver($index);

        $driftEngine = new DriftEngine(
            [new AuthDriftDetector()],
            $resolver,
        );
        $driftItems = $driftEngine->detect($spec, $context);

        $engine = new SuggestionEngine([
            new CodeToSpecProvider($resolver),
            new SpecToCodeProvider($driftItems),
        ]);

        $suggestions = $engine->suggest();
        $renderer = new JsonRenderer();
        $actualJson = $renderer->render($suggestions);
        $actual = json_decode($actualJson, true);

        // Structural assertions
        $this->assertSame('1.0', $actual['version']);
        $this->assertArrayHasKey('suggestions', $actual);
        $this->assertArrayHasKey('summary', $actual);

        // Must have code_to_spec + spec_to_code suggestions
        $this->assertGreaterThanOrEqual(2, count($actual['suggestions']));
        $this->assertGreaterThanOrEqual(1, $actual['summary']['code_to_spec']);
        $this->assertGreaterThanOrEqual(1, $actual['summary']['spec_to_code']);

        // Verify ordering: code_to_spec before spec_to_code
        $directions = array_column($actual['suggestions'], 'direction');
        $codeToSpecIdx = array_search('code_to_spec', $directions, true);
        $specToCodeIdx = array_search('spec_to_code', $directions, true);
        $this->assertLessThan($specToCodeIdx, $codeToSpecIdx);

        // All suggestions must have mapping_ids or null
        foreach ($actual['suggestions'] as $s) {
            $this->assertArrayHasKey('mapping_ids', $s);
        }

        // Snapshot comparison
        $expectedPath = __DIR__ . '/../fixtures/sync/full/expected.json';

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

    public function test_determinism_run_twice_identical(): void
    {
        [$spec, $context] = $this->buildFixtures();

        $run = function () use ($spec, $context): string {
            $builder = new MappingBuilder();
            $index = $builder->build($spec, $context);
            $resolver = new MappingResolver($index);

            $driftEngine = new DriftEngine(
                [new AuthDriftDetector()],
                $resolver,
            );
            $driftItems = $driftEngine->detect($spec, $context);

            $engine = new SuggestionEngine([
                new CodeToSpecProvider($resolver),
                new SpecToCodeProvider($driftItems),
            ]);

            $suggestions = $engine->suggest();
            $renderer = new JsonRenderer();

            return $renderer->render($suggestions);
        };

        $json1 = $run();
        $json2 = $run();

        $this->assertSame($json1, $json2);
    }
}
