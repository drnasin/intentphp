<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Checks;

use IntentPHP\Guard\Analysis\AstParser;
use IntentPHP\Guard\Checks\Invariant\InvariantCheck;
use IntentPHP\Guard\Invariant\Invariants\MassAssignmentInvariant;
use IntentPHP\Guard\Invariant\InvariantInput;
use IntentPHP\Guard\Scan\Finding;

/**
 * Thin shim over {@see MassAssignmentInvariant} (Phase 11 migration).
 *
 * The detection logic now lives in the invariant; this class preserves the
 * legacy public API (constructor signature + name()) and delegates run() to an
 * {@see InvariantCheck}, so existing callers and tests are unchanged and output
 * is byte-identical (see DECISIONS D-007).
 */
class MassAssignmentCheck implements CheckInterface
{
    private readonly AstParser $ast;

    /**
     * @param string[]|null $onlyFiles When set, only scan these controller files (models always get full scan)
     */
    public function __construct(
        private readonly string $modelsPath,
        private readonly string $controllersPath,
        private readonly ?array $onlyFiles = null,
        ?AstParser $ast = null,
    ) {
        $this->ast = $ast ?? new AstParser();
    }

    public function name(): string
    {
        return 'mass-assignment';
    }

    /** @return Finding[] */
    public function run(): array
    {
        // The mass-assignment invariant consumes modelsPath, controllersPath,
        // ast and changedFiles; router + detector are not needed (null).
        $input = new InvariantInput(
            router: null,
            controllersPath: $this->controllersPath,
            modelsPath: $this->modelsPath,
            ast: $this->ast,
            changedFiles: $this->onlyFiles,
            detector: null,
        );

        return (new InvariantCheck(new MassAssignmentInvariant(), $input))->run();
    }
}
