<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Intent\Drift;

use IntentPHP\Guard\Intent\Auth\AuthSpec;
use IntentPHP\Guard\Intent\Baseline\BaselineSpec;
use IntentPHP\Guard\Intent\Data\DataSpec;
use IntentPHP\Guard\Intent\Data\ModelSpec;
use IntentPHP\Guard\Intent\Defaults;
use IntentPHP\Guard\Intent\Drift\Context\ObservedModel;
use IntentPHP\Guard\Intent\Drift\Context\ProjectContext;
use IntentPHP\Guard\Intent\Drift\Detectors\MassAssignmentDriftDetector;
use IntentPHP\Guard\Intent\IntentSpec;
use IntentPHP\Guard\Intent\ProjectMeta;
use PHPUnit\Framework\TestCase;

class MassAssignmentDriftDetectorTest extends TestCase
{
    private function makeSpec(array $models = []): IntentSpec
    {
        return new IntentSpec(
            version: '0.1',
            project: new ProjectMeta('test', 'laravel'),
            defaults: new Defaults(),
            auth: AuthSpec::empty(),
            data: new DataSpec(models: $models),
            baseline: BaselineSpec::empty(),
        );
    }

    private function makeContext(array $models): ProjectContext
    {
        return new ProjectContext([], $models);
    }

    private function observedModel(
        string $fqcn,
        bool $hasFillable = false,
        bool $fillableParseable = true,
        array $fillableAttrs = [],
        bool $guardedIsEmpty = false,
    ): ObservedModel {
        return new ObservedModel(
            fqcn: $fqcn,
            filePath: "/app/Models/{$this->shortName($fqcn)}.php",
            hasFillable: $hasFillable,
            fillableParseable: $fillableParseable,
            fillableAttrs: $fillableAttrs,
            guardedIsEmpty: $guardedIsEmpty,
        );
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }

    public function test_missing_fillable_emits_high(): void
    {
        $detector = new MassAssignmentDriftDetector();
        $spec = $this->makeSpec([
            'App\\Models\\User' => new ModelSpec(
                fqcn: 'App\\Models\\User',
                massAssignmentMode: 'explicit_allowlist',
                allow: ['name'],
            ),
        ]);
        $context = $this->makeContext([
            $this->observedModel('App\\Models\\User', hasFillable: false),
        ]);

        $items = $detector->detect($spec, $context);

        $this->assertCount(1, $items);
        $this->assertSame('missing_fillable', $items[0]->driftType);
        $this->assertSame('high', $items[0]->severity);
    }

    public function test_forbidden_attr_in_fillable_emits_high(): void
    {
        $detector = new MassAssignmentDriftDetector();
        $spec = $this->makeSpec([
            'App\\Models\\User' => new ModelSpec(
                fqcn: 'App\\Models\\User',
                massAssignmentMode: 'explicit_allowlist',
                allow: ['name', 'email'],
                forbid: ['is_admin'],
            ),
        ]);
        $context = $this->makeContext([
            $this->observedModel('App\\Models\\User', hasFillable: true, fillableAttrs: ['name', 'email', 'is_admin']),
        ]);

        $items = $detector->detect($spec, $context);

        $this->assertCount(1, $items);
        $this->assertSame('forbidden_in_fillable:is_admin', $items[0]->driftType);
        $this->assertSame('high', $items[0]->severity);
    }

    public function test_empty_guarded_emits_high(): void
    {
        $detector = new MassAssignmentDriftDetector();
        $spec = $this->makeSpec([
            'App\\Models\\Post' => new ModelSpec(
                fqcn: 'App\\Models\\Post',
                massAssignmentMode: 'guarded',
            ),
        ]);
        $context = $this->makeContext([
            $this->observedModel('App\\Models\\Post', guardedIsEmpty: true),
        ]);

        $items = $detector->detect($spec, $context);

        $this->assertCount(1, $items);
        $this->assertSame('guarded_empty', $items[0]->driftType);
        $this->assertSame('high', $items[0]->severity);
    }

    public function test_unparseable_with_forbid_list_emits_low(): void
    {
        $detector = new MassAssignmentDriftDetector();
        $spec = $this->makeSpec([
            'App\\Models\\User' => new ModelSpec(
                fqcn: 'App\\Models\\User',
                massAssignmentMode: 'explicit_allowlist',
                allow: ['name'],
                forbid: ['is_admin'],
            ),
        ]);
        $context = $this->makeContext([
            $this->observedModel('App\\Models\\User', hasFillable: true, fillableParseable: false),
        ]);

        $items = $detector->detect($spec, $context);

        $this->assertCount(1, $items);
        $this->assertSame('unparseable_model', $items[0]->driftType);
        $this->assertSame('low', $items[0]->severity);
    }

    public function test_unparseable_guarded_mode_still_detects_empty_guarded(): void
    {
        $detector = new MassAssignmentDriftDetector();
        $spec = $this->makeSpec([
            'App\\Models\\Post' => new ModelSpec(
                fqcn: 'App\\Models\\Post',
                massAssignmentMode: 'guarded',
            ),
        ]);
        $context = $this->makeContext([
            $this->observedModel('App\\Models\\Post', hasFillable: true, fillableParseable: false, guardedIsEmpty: true),
        ]);

        $items = $detector->detect($spec, $context);

        $this->assertCount(1, $items);
        $this->assertSame('guarded_empty', $items[0]->driftType);
    }

    public function test_compliant_model_returns_empty(): void
    {
        $detector = new MassAssignmentDriftDetector();
        $spec = $this->makeSpec([
            'App\\Models\\User' => new ModelSpec(
                fqcn: 'App\\Models\\User',
                massAssignmentMode: 'explicit_allowlist',
                allow: ['name', 'email'],
            ),
        ]);
        $context = $this->makeContext([
            $this->observedModel('App\\Models\\User', hasFillable: true, fillableAttrs: ['name', 'email']),
        ]);

        $items = $detector->detect($spec, $context);
        $this->assertSame([], $items);
    }

    public function test_model_not_found_returns_empty(): void
    {
        $detector = new MassAssignmentDriftDetector();
        $spec = $this->makeSpec([
            'App\\Models\\Missing' => new ModelSpec(
                fqcn: 'App\\Models\\Missing',
                massAssignmentMode: 'explicit_allowlist',
                allow: ['name'],
            ),
        ]);
        $context = $this->makeContext([]); // No observed models

        $items = $detector->detect($spec, $context);
        $this->assertSame([], $items);
    }

    public function test_output_sorted_by_fqcn(): void
    {
        $detector = new MassAssignmentDriftDetector();
        $spec = $this->makeSpec([
            'App\\Models\\Zebra' => new ModelSpec(
                fqcn: 'App\\Models\\Zebra',
                massAssignmentMode: 'explicit_allowlist',
            ),
            'App\\Models\\Alpha' => new ModelSpec(
                fqcn: 'App\\Models\\Alpha',
                massAssignmentMode: 'explicit_allowlist',
            ),
        ]);
        $context = $this->makeContext([
            $this->observedModel('App\\Models\\Zebra', hasFillable: false),
            $this->observedModel('App\\Models\\Alpha', hasFillable: false),
        ]);

        $items = $detector->detect($spec, $context);

        $this->assertCount(2, $items);
        // Detector returns in spec iteration order; DriftEngine sorts globally.
        // DataSpec::fromArray() does ksort, but direct constructor preserves order.
        $targetIds = array_map(fn ($i) => $i->targetId, $items);
        $this->assertContains('App\\Models\\Alpha', $targetIds);
        $this->assertContains('App\\Models\\Zebra', $targetIds);
    }
}
