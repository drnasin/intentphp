<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Checks;

use IntentPHP\Guard\Analysis\AstParser;
use IntentPHP\Guard\Scan\Finding;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;
use Symfony\Component\Finder\Finder;

class MassAssignmentCheck implements CheckInterface
{
    /** Bulk request input → HIGH; validated() → MEDIUM. */
    private const BULK_METHODS = ['all', 'input', 'except'];

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
        $unsafeModels = $this->findUnsafeModels();

        if ($unsafeModels === []) {
            return [];
        }

        return $this->scanControllers($unsafeModels);
    }

    /**
     * Find models that are genuinely mass-assignable.
     *
     * A model is unsafe only when attributes are actually open to mass
     * assignment:
     *   - $guarded = []                         → every attribute assignable
     *   - $guarded = [...non-'*'...] + no $fillable
     *                                           → every non-guarded attribute assignable
     *
     * A bare model (no $fillable AND no $guarded) inherits Eloquent's default
     * $guarded = ['*'], so it is fully guarded and NOT mass-assignable — flagging
     * it was a false positive. $guarded = ['*'] and any $fillable allowlist are
     * likewise safe.
     *
     * Limitation: only the model's own file is inspected. Protection or opening
     * ($guarded = []) inherited from a parent class/trait, or a global
     * Model::unguard(), is not resolved — such a model reads as "bare" here.
     *
     * @return array<string, array{file: string, reason: string}>
     */
    private function findUnsafeModels(): array
    {
        $unsafe = [];

        if (! is_dir($this->modelsPath)) {
            return $unsafe;
        }

        $finder = new Finder();
        $finder->files()->in($this->modelsPath)->name('*.php');

        foreach ($finder as $file) {
            $contents = $file->getContents();
            $className = $this->extractClassName($contents);

            if ($className === null) {
                continue;
            }

            if (! $this->extendsModel($contents)) {
                continue;
            }

            $hasFillable = (bool) preg_match('/\$fillable\s*=\s*\[/', $contents);
            $hasGuarded = (bool) preg_match('/\$guarded\s*=/', $contents);
            $guardedEmpty = (bool) preg_match('/\$guarded\s*=\s*\[\s*\]/', $contents);
            // Matches $guarded = ['*'] / ["*"] (the explicit "guard everything" form).
            $guardedAll = (bool) preg_match('/\$guarded\s*=\s*\[\s*([\'"])\*\1\s*\]/', $contents);

            if ($guardedEmpty) {
                $unsafe[$className] = [
                    'file' => $file->getRealPath(),
                    'reason' => '$guarded is set to an empty array — all attributes are mass assignable',
                ];
            } elseif ($hasGuarded && ! $guardedAll && ! $hasFillable) {
                $unsafe[$className] = [
                    'file' => $file->getRealPath(),
                    'reason' => '$guarded is a partial allowlist with no $fillable — all non-guarded attributes are mass assignable',
                ];
            }
        }

        return $unsafe;
    }

    /**
     * Scan controllers for mass-assignment sinks fed directly by request input:
     *   Model::create($request->all())     — model resolved from the static call
     *   $x->update($request->all())        — loose; fires when any unsafe model exists
     *   $x->fill($request->validated())    — MEDIUM (validated, but model unguarded)
     *
     * AST-based, so multi-line calls are detected (a per-line regex cannot see
     * them). Tracking input through an intermediate variable is taint analysis,
     * intentionally out of scope here (C2 follow-up).
     *
     * @param array<string, array{file: string, reason: string}> $unsafeModels
     * @return Finding[]
     */
    private function scanControllers(array $unsafeModels): array
    {
        $findings = [];
        $nodeFinder = new NodeFinder();

        foreach ($this->controllerFilesToScan() as [$filePath, $contents]) {
            $stmts = $this->ast->parse($filePath, $contents);

            if ($stmts === null) {
                continue; // parse error recorded on the shared parser; surfaced by the command
            }

            $lines = explode("\n", $contents);

            /** @var array<Expr\StaticCall|Expr\MethodCall|Expr\NullsafeMethodCall> $calls */
            $calls = $nodeFinder->find(
                $stmts,
                static fn (Node $n): bool => $n instanceof Expr\StaticCall
                    || $n instanceof Expr\MethodCall
                    || $n instanceof Expr\NullsafeMethodCall,
            );

            foreach ($calls as $call) {
                $finding = $this->classify($call, $unsafeModels, $filePath, $lines);

                if ($finding !== null) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * @param array<string, array{file: string, reason: string}> $unsafeModels
     * @param string[] $lines
     */
    private function classify(Node $call, array $unsafeModels, string $filePath, array $lines): ?Finding
    {
        if (! $call->name instanceof Node\Identifier) {
            return null;
        }

        $method = $call->name->toString();

        if ($call instanceof Expr\StaticCall && $method === 'create') {
            $isStaticCreate = true;
            $model = $call->class instanceof Node\Name ? $call->class->getLast() : null;
            $valuesArg = $call->args[0]->value ?? null;
        } elseif (($call instanceof Expr\MethodCall || $call instanceof Expr\NullsafeMethodCall)
            && in_array($method, ['update', 'fill'], true)
        ) {
            $isStaticCreate = false;
            $model = $this->resolveReceiverModel($call->var);
            $valuesArg = $call->args[0]->value ?? null;
        } else {
            return null;
        }

        if (! $valuesArg instanceof Node) {
            return null;
        }

        $input = $this->describeInput($valuesArg);

        if ($input === null) {
            return null;
        }

        [$severity, $inputLabel] = $input;

        // Model-safety gate (run() already guaranteed $unsafeModels is non-empty):
        //  - resolved + unsafe → flag, enriched with the reason.
        //  - static Model::create on a model that is NOT in the unsafe set → skip
        //    (parity with the old regex, which anchored ::create to unsafe models).
        //  - ->update()/->fill(): stay LOOSE — flag regardless of the resolved
        //    receiver. findUnsafeModels can't see protection/opening inherited from
        //    a parent/trait, so suppressing "safe-looking" receivers here would
        //    drop real vulns (Model::query()->update($request->all()) where the
        //    parent sets $guarded=[]). Precision is the deferred model-confidence work.
        if ($model !== null && isset($unsafeModels[$model])) {
            $reason = $unsafeModels[$model]['reason'];
            $modelFile = $unsafeModels[$model]['file'];
        } elseif ($isStaticCreate) {
            return null;
        } else {
            $reason = null;
            $modelFile = null;
        }

        $label = "{$method} with {$inputLabel}";
        $modelInfo = ($model !== null && $reason !== null) ? " Model {$model}: {$reason}." : '';

        $context = [
            'pattern' => $label,
            'snippet' => $this->snippet($call, $lines),
            'model' => $model ?? '',
            'model_file' => $modelFile,
        ];

        if ($severity === 'high') {
            return Finding::high(
                check: $this->name(),
                message: "Mass assignment risk: {$label}.{$modelInfo}",
                file: $filePath,
                line: $call->name->getStartLine(),
                context: $context,
                fix_hint: "Use \$request->only([...]) or \$request->validated() instead of \$request->all(). Define \$fillable on the model.",
            );
        }

        return Finding::medium(
            check: $this->name(),
            message: "Mass assignment with validated(): {$label}.{$modelInfo} Using validated() is safer, but the model itself lacks protection.",
            file: $filePath,
            line: $call->name->getStartLine(),
            context: $context,
            fix_hint: "validated() is a good practice, but also define \$fillable on the model for defense in depth.",
        );
    }

    /**
     * Classify the value argument: bulk request input → HIGH, validated() → MEDIUM.
     * Returns [severity, normalized label] or null. The label is normalized to
     * '$request' / 'request()' so the fingerprint is stable across variable names.
     *
     * @return array{0: string, 1: string}|null
     */
    private function describeInput(Node $arg): ?array
    {
        if (! $arg instanceof Expr\MethodCall && ! $arg instanceof Expr\NullsafeMethodCall) {
            return null;
        }

        if (! $arg->name instanceof Node\Identifier) {
            return null;
        }

        $root = $this->requestRootLabel($arg->var);

        if ($root === null) {
            return null;
        }

        $method = $arg->name->toString();

        // $request->input('key') returns a SINGLE field, not the bulk payload —
        // only the no-argument form is mass assignment. all()/except() are bulk
        // regardless of arguments.
        if ($method === 'input' && $arg->args !== []) {
            return null;
        }

        if (in_array($method, self::BULK_METHODS, true)) {
            return ['high', "{$root}->{$method}()"];
        }

        if ($method === 'validated') {
            return ['medium', "{$root}->validated()"];
        }

        return null;
    }

    /** Normalized request-root label, or null if the expression isn't request-rooted. */
    private function requestRootLabel(Node $node): ?string
    {
        if ($node instanceof Expr\Variable && $node->name === 'request') {
            return '$request';
        }

        if ($node instanceof Expr\FuncCall
            && $node->name instanceof Node\Name
            && $node->name->toString() === 'request'
        ) {
            return 'request()';
        }

        return null;
    }

    /**
     * Best-effort model class for a method-call receiver: walk the fluent chain
     * to its root and read a static call (Model::query()/find()) or `new Model`.
     * Returns null for a plain variable (e.g. $user) — full resolution is a
     * follow-up; null keeps the legacy loose behavior.
     */
    private function resolveReceiverModel(Node $recv): ?string
    {
        while ($recv instanceof Expr\MethodCall || $recv instanceof Expr\NullsafeMethodCall) {
            $recv = $recv->var;
        }

        if ($recv instanceof Expr\StaticCall && $recv->class instanceof Node\Name) {
            return $recv->class->getLast();
        }

        if ($recv instanceof Expr\New_ && $recv->class instanceof Node\Name) {
            return $recv->class->getLast();
        }

        return null;
    }

    /**
     * @param string[] $lines
     */
    private function snippet(Node $call, array $lines): string
    {
        $start = max(0, $call->getStartLine() - 1);
        $end = min(count($lines) - 1, $call->getEndLine() - 1);

        $text = trim(implode(' ', array_map('trim', array_slice($lines, $start, $end - $start + 1))));

        return mb_strlen($text) > 200 ? mb_substr($text, 0, 200) . '…' : $text;
    }

    private function extractClassName(string $contents): ?string
    {
        if (preg_match('/class\s+(\w+)/', $contents, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function extendsModel(string $contents): bool
    {
        return (bool) preg_match('/extends\s+(Model|Authenticatable|Pivot)\b/', $contents);
    }

    /**
     * @return iterable<array{0: string, 1: string}>
     */
    private function controllerFilesToScan(): iterable
    {
        if ($this->onlyFiles !== null) {
            $controllerDir = rtrim(str_replace('\\', '/', $this->controllersPath), '/');

            foreach ($this->onlyFiles as $file) {
                $normalized = str_replace('\\', '/', $file);

                if (str_ends_with($normalized, '.php')
                    && str_starts_with($normalized, $controllerDir)
                    && is_readable($file)
                ) {
                    yield [$file, (string) file_get_contents($file)];
                }
            }

            return;
        }

        if (! is_dir($this->controllersPath)) {
            return;
        }

        $finder = new Finder();
        $finder->files()->in($this->controllersPath)->name('*.php');

        foreach ($finder as $file) {
            yield [$file->getRealPath(), $file->getContents()];
        }
    }
}
