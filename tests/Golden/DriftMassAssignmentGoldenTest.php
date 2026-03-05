<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Golden;

use IntentPHP\Guard\Checks\Intent\IntentDriftCheck;
use IntentPHP\Guard\Intent\Auth\AuthSpec;
use IntentPHP\Guard\Intent\Baseline\BaselineSpec;
use IntentPHP\Guard\Intent\Data\DataSpec;
use IntentPHP\Guard\Intent\Data\ModelSpec;
use IntentPHP\Guard\Intent\Defaults;
use IntentPHP\Guard\Intent\Drift\Context\ObservedModel;
use IntentPHP\Guard\Intent\Drift\Context\ProjectContext;
use IntentPHP\Guard\Intent\Drift\Detectors\MassAssignmentDriftDetector;
use IntentPHP\Guard\Intent\Drift\DriftEngine;
use IntentPHP\Guard\Intent\IntentSpec;
use IntentPHP\Guard\Intent\ProjectMeta;
use IntentPHP\Guard\Scan\Finding;
use PHPUnit\Framework\TestCase;

class DriftMassAssignmentGoldenTest extends TestCase
{
    private function buildFixtures(): array
    {
        $spec = new IntentSpec(
            version: '0.1',
            project: new ProjectMeta('golden-test', 'laravel'),
            defaults: new Defaults(),
            auth: AuthSpec::empty(),
            data: new DataSpec(models: [
                'App\\Models\\Comment' => new ModelSpec(
                    fqcn: 'App\\Models\\Comment',
                    massAssignmentMode: 'guarded',
                ),
                'App\\Models\\Post' => new ModelSpec(
                    fqcn: 'App\\Models\\Post',
                    massAssignmentMode: 'explicit_allowlist',
                    allow: ['title', 'body'],
                    forbid: ['is_admin'],
                ),
                'App\\Models\\User' => new ModelSpec(
                    fqcn: 'App\\Models\\User',
                    massAssignmentMode: 'explicit_allowlist',
                    allow: ['name', 'email'],
                ),
            ]),
            baseline: BaselineSpec::empty(),
        );

        $context = new ProjectContext(
            routes: [],
            models: [
                // Comment: guarded = [] → guarded_empty
                new ObservedModel(
                    fqcn: 'App\\Models\\Comment',
                    filePath: '/project/app/Models/Comment.php',
                    hasFillable: false,
                    fillableParseable: true,
                    fillableAttrs: [],
                    guardedIsEmpty: true,
                ),
                // Post: has forbidden 'is_admin' in fillable → forbidden_in_fillable
                new ObservedModel(
                    fqcn: 'App\\Models\\Post',
                    filePath: '/project/app/Models/Post.php',
                    hasFillable: true,
                    fillableParseable: true,
                    fillableAttrs: ['title', 'body', 'is_admin'],
                    guardedIsEmpty: false,
                ),
                // User: compliant → no drift
                new ObservedModel(
                    fqcn: 'App\\Models\\User',
                    filePath: '/project/app/Models/User.php',
                    hasFillable: true,
                    fillableParseable: true,
                    fillableAttrs: ['name', 'email'],
                    guardedIsEmpty: false,
                ),
            ],
        );

        return [$spec, $context];
    }

    public function test_golden_output_matches_expected(): void
    {
        [$spec, $context] = $this->buildFixtures();

        $engine = new DriftEngine([new MassAssignmentDriftDetector()]);
        $check = new IntentDriftCheck($engine, $spec, $context);
        $findings = $check->run();

        $actual = array_map(fn (Finding $f) => $f->toArray(), $findings);

        // Verify structural properties
        $this->assertCount(2, $actual);

        // Sorted by targetId: Comment < Post
        $this->assertSame('intent-drift/mass-assignment', $actual[0]['check']);
        $this->assertSame('guarded_empty', $actual[0]['context']['drift_type']);
        $this->assertSame('App\\Models\\Comment', $actual[0]['context']['model_fqcn']);

        $this->assertSame('intent-drift/mass-assignment', $actual[1]['check']);
        $this->assertSame('forbidden_in_fillable:is_admin', $actual[1]['context']['drift_type']);
        $this->assertSame('App\\Models\\Post', $actual[1]['context']['model_fqcn']);

        // Snapshot comparison
        $expectedPath = __DIR__ . '/../fixtures/drift/mass-assignment/expected.json';

        if (getenv('UPDATE_GOLDEN') === '1' || ! file_exists($expectedPath)) {
            $dir = dirname($expectedPath);

            if (! is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            file_put_contents(
                $expectedPath,
                json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            );
            $this->markTestSkipped('Golden fixture generated: ' . $expectedPath);
        }

        $expected = json_decode(file_get_contents($expectedPath), true);
        $this->assertSame($expected, $actual);
    }

    public function test_determinism_run_twice_identical(): void
    {
        [$spec, $context] = $this->buildFixtures();

        $engine = new DriftEngine([new MassAssignmentDriftDetector()]);

        $check1 = new IntentDriftCheck($engine, $spec, $context);
        $findings1 = $check1->run();

        $check2 = new IntentDriftCheck($engine, $spec, $context);
        $findings2 = $check2->run();

        $json1 = json_encode(array_map(fn (Finding $f) => $f->toArray(), $findings1));
        $json2 = json_encode(array_map(fn (Finding $f) => $f->toArray(), $findings2));

        $this->assertSame($json1, $json2);
    }
}
