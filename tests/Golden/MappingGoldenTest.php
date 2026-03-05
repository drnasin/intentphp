<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Golden;

use IntentPHP\Guard\Intent\Auth\AuthRequirement;
use IntentPHP\Guard\Intent\Auth\AuthRule;
use IntentPHP\Guard\Intent\Auth\AuthSpec;
use IntentPHP\Guard\Intent\Baseline\BaselineSpec;
use IntentPHP\Guard\Intent\Data\DataSpec;
use IntentPHP\Guard\Intent\Data\ModelSpec;
use IntentPHP\Guard\Intent\Defaults;
use IntentPHP\Guard\Intent\Drift\Context\ObservedModel;
use IntentPHP\Guard\Intent\Drift\Context\ObservedRoute;
use IntentPHP\Guard\Intent\Drift\Context\ProjectContext;
use IntentPHP\Guard\Intent\IntentSpec;
use IntentPHP\Guard\Intent\Mapping\MappingBuilder;
use IntentPHP\Guard\Intent\ProjectMeta;
use IntentPHP\Guard\Intent\Selector\RouteSelector;
use PHPUnit\Framework\TestCase;

class MappingGoldenTest extends TestCase
{
    private function buildFixtures(): array
    {
        $spec = new IntentSpec(
            version: '0.1',
            project: new ProjectMeta('golden-test', 'laravel'),
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
            data: new DataSpec(models: [
                'App\\Models\\Order' => new ModelSpec(
                    fqcn: 'App\\Models\\Order',
                    massAssignmentMode: 'explicit_allowlist',
                    forbid: ['id'],
                ),
            ]),
            baseline: BaselineSpec::empty(),
        );

        $context = new ProjectContext(
            routes: [
                new ObservedRoute('/orders', 'orders.index', ['GET'], ['auth'], 'App\\Http\\Controllers\\OrderController@index'),
                new ObservedRoute('/dashboard', 'dashboard', ['GET'], [], 'App\\Http\\Controllers\\DashboardController@index'),
            ],
            models: [
                new ObservedModel('App\\Models\\Order', '/app/Models/Order.php', true, true, ['name', 'total'], false),
                new ObservedModel('App\\Models\\User', '/app/Models/User.php', true, true, ['name', 'email'], false),
            ],
        );

        return [$spec, $context];
    }

    public function test_golden_output_matches_expected(): void
    {
        [$spec, $context] = $this->buildFixtures();

        $builder = new MappingBuilder();
        $index = $builder->build($spec, $context);

        $actualJson = $index->toJson();
        $actual = json_decode($actualJson, true);

        // Structural assertions
        $this->assertSame('1.0', $actual['version']);
        $this->assertCount(4, $actual['entries']);
        $this->assertArrayHasKey('checksum', $actual);
        $this->assertNotEmpty($actual['checksum']);

        // Snapshot comparison against stored fixture
        $expectedPath = __DIR__ . '/../fixtures/mapping/full/expected.json';

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

        $builder = new MappingBuilder();
        $index1 = $builder->build($spec, $context);
        $index2 = $builder->build($spec, $context);

        $this->assertSame($index1->toJson(), $index2->toJson());
        $this->assertSame($index1->checksum, $index2->checksum);
    }
}
