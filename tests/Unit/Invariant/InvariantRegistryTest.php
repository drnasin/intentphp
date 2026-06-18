<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Invariant;

use IntentPHP\Guard\Invariant\Invariant;
use IntentPHP\Guard\Invariant\InvariantInput;
use IntentPHP\Guard\Invariant\InvariantRegistry;
use IntentPHP\Guard\Invariant\Invariants\MassAssignmentInvariant;
use IntentPHP\Guard\Invariant\Invariants\RouteAuthorizationInvariant;
use PHPUnit\Framework\TestCase;

class InvariantRegistryTest extends TestCase
{
    /** @return string[] */
    private function ids(InvariantRegistry $registry): array
    {
        return array_map(static fn (Invariant $i): string => $i->id(), $registry->all());
    }

    public function test_invariants_are_sorted_by_id_ascending(): void
    {
        $registry = new InvariantRegistry([
            new RouteAuthorizationInvariant(),
            new MassAssignmentInvariant(),
        ]);

        // 'mass-assignment' < 'route-authorization' (strcmp).
        $this->assertSame(['mass-assignment', 'route-authorization'], $this->ids($registry));
    }

    public function test_ordering_is_independent_of_registration_order(): void
    {
        $forward = new InvariantRegistry([
            new RouteAuthorizationInvariant(),
            new MassAssignmentInvariant(),
        ]);

        $reversed = new InvariantRegistry([
            new MassAssignmentInvariant(),
            new RouteAuthorizationInvariant(),
        ]);

        $this->assertSame($this->ids($forward), $this->ids($reversed));
        $this->assertSame(['mass-assignment', 'route-authorization'], $this->ids($reversed));
    }

    public function test_ordering_is_deterministic_with_arbitrary_ids(): void
    {
        $registry = new InvariantRegistry([
            $this->stub('zeta'),
            $this->stub('alpha'),
            $this->stub('mike'),
        ]);

        $this->assertSame(['alpha', 'mike', 'zeta'], $this->ids($registry));
    }

    public function test_empty_registry_returns_empty(): void
    {
        $this->assertSame([], (new InvariantRegistry())->all());
    }

    private function stub(string $id): Invariant
    {
        return new class($id) implements Invariant {
            public function __construct(private readonly string $id) {}

            public function id(): string
            {
                return $this->id;
            }

            public function description(): string
            {
                return '';
            }

            public function evaluate(InvariantInput $input): array
            {
                return [];
            }
        };
    }
}
