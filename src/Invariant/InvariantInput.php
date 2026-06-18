<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Invariant;

use Illuminate\Routing\Router;
use IntentPHP\Guard\Analysis\AstParser;
use IntentPHP\Guard\Checks\RouteProtectionDetector;

/**
 * Evaluation input for {@see Invariant} implementations.
 *
 * NOTE: this is deliberately NOT a pure, framework-free snapshot. v1 invariants
 * evaluate over the live Laravel {@see Router} and a shared {@see AstParser},
 * mirroring the prior checks verbatim to guarantee byte-identical output parity.
 * A framework-free snapshot is deferred to v2 (see DECISIONS D-007).
 *
 * `router` and `detector` are nullable because the mass-assignment invariant
 * does not need them, and `controllersPath`/`modelsPath`/`ast`/`changedFiles`
 * carry the route invariant's unused defaults. Each invariant is only ever
 * handed an input that already carries the dependencies it requires; an
 * invariant that needs a missing dependency cannot run.
 */
final readonly class InvariantInput
{
    /**
     * @param string[]|null $changedFiles When non-null, controller scanning is
     *                                     restricted to these files (incremental
     *                                     scan). `null` means a full scan — this
     *                                     null-vs-empty distinction mirrors the
     *                                     legacy MassAssignmentCheck `$onlyFiles`
     *                                     contract exactly and must be preserved
     *                                     for output parity.
     */
    public function __construct(
        public ?Router $router,
        public string $controllersPath,
        public string $modelsPath,
        public AstParser $ast,
        public ?array $changedFiles,
        public ?RouteProtectionDetector $detector,
    ) {}
}
