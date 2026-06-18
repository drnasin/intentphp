<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Checks;

use IntentPHP\Guard\Analysis\AstParser;
use IntentPHP\Guard\Analysis\FunctionScope;
use IntentPHP\Guard\Scan\Finding;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\FunctionLike;
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
            $filePath = $file->getRealPath();
            $stmts = $this->ast->parse($filePath, $file->getContents());

            if ($stmts === null) {
                continue; // parse error recorded on the shared parser; surfaced by the command
            }

            $class = $this->findModelClass($stmts);

            if ($class === null || $class->name === null) {
                continue;
            }

            if (! $this->extendsModel($class)) {
                continue;
            }

            $className = $class->name->toString();

            $hasFillable = $this->propertyDefault($class, 'fillable') !== null;
            $guarded = $this->propertyDefault($class, 'guarded');
            $hasGuarded = $guarded !== null;
            $guardedEmpty = $hasGuarded && $this->isEmptyArray($guarded);
            // $guarded = ['*'] / ["*"] — the explicit "guard everything" form.
            $guardedAll = $hasGuarded && $this->isGuardAllArray($guarded);

            if ($guardedEmpty) {
                $unsafe[$className] = [
                    'file' => $filePath,
                    'reason' => '$guarded is set to an empty array — all attributes are mass assignable',
                ];
            } elseif ($hasGuarded && ! $guardedAll && ! $hasFillable) {
                $unsafe[$className] = [
                    'file' => $filePath,
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

            $scopes = [];

            foreach ($calls as $call) {
                $scope = $this->scopeFor($call, $scopes);
                $finding = $this->classify($call, $unsafeModels, $filePath, $lines, $scope);

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
    private function classify(Node $call, array $unsafeModels, string $filePath, array $lines, FunctionScope $scope): ?Finding
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

        $input = $this->describeInput($valuesArg, $scope);

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
     * Classify the value argument:
     *   - bulk request input → HIGH (direct call), MEDIUM (validated())
     *   - a tainted variable (assigned from a bulk request expression earlier
     *     in the same function — C2 indirection)
     *
     * Returns [severity, normalized label] or null. The label is normalized to
     * '$request' (or '$var' for tainted vars) so the fingerprint is stable
     * across param renames but still distinguishes the trigger source.
     *
     * @return array{0: string, 1: string}|null
     */
    private function describeInput(Node $arg, FunctionScope $scope): ?array
    {
        // C2: $d = $request->all(); $m->fill($d);  →  $d is in $scope->taintedBulk.
        if ($arg instanceof Expr\Variable && is_string($arg->name) && isset($scope->taintedBulk[$arg->name])) {
            return [$scope->taintedBulk[$arg->name], "tainted variable \${$arg->name}"];
        }

        if (! $arg instanceof Expr\MethodCall && ! $arg instanceof Expr\NullsafeMethodCall) {
            return null;
        }

        if (! $arg->name instanceof Node\Identifier) {
            return null;
        }

        $root = $this->requestRootLabel($arg->var, $scope);

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

    /**
     * Normalized request-root label, or null if the expression isn't request-rooted.
     * Recognises the literal $request, request() helper, and any FormRequest
     * parameter alias declared in the enclosing function's scope.
     */
    private function requestRootLabel(Node $node, FunctionScope $scope): ?string
    {
        if ($node instanceof Expr\Variable && is_string($node->name)) {
            if ($node->name === 'request' || isset($scope->aliases[$node->name])) {
                return '$request';
            }
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
     * Find the enclosing function's scope, caching one per FunctionLike node.
     *
     * @param array<int,FunctionScope> $scopes
     */
    private function scopeFor(Node $call, array &$scopes): FunctionScope
    {
        $fn = $this->enclosingFunction($call);

        if ($fn === null) {
            return FunctionScope::empty();
        }

        $key = spl_object_id($fn);

        return $scopes[$key] ??= FunctionScope::for($fn);
    }

    private function enclosingFunction(Node $node): ?FunctionLike
    {
        $p = $node->getAttribute('parent');

        while ($p !== null) {
            if ($p instanceof FunctionLike) {
                return $p;
            }
            $p = $p->getAttribute('parent');
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

    /**
     * First non-anonymous class declaration in the file. Reading the AST node
     * (rather than the first `class\s+(\w+)` text match) means a `class` keyword
     * in a comment/string, a `Foo::class` constant, or an anonymous `new class`
     * no longer mis-identifies the model.
     *
     * @param list<Node\Stmt> $stmts
     */
    private function findModelClass(array $stmts): ?Node\Stmt\Class_
    {
        foreach ((new NodeFinder())->findInstanceOf($stmts, Node\Stmt\Class_::class) as $class) {
            /** @var Node\Stmt\Class_ $class */
            if ($class->name !== null) {
                return $class;
            }
        }

        return null;
    }

    /**
     * True when the class directly extends a known Eloquent base by short name
     * (Model / Authenticatable / Pivot). NameResolver gives the resolved parent,
     * so the short-name check is robust to aliasing.
     *
     * Bounded by design: a project base class (`extends BaseModel`) is NOT
     * resolved across files — see findUnsafeModels()'s docblock. Such a model
     * reads as a non-Eloquent class here, preserving the documented limitation.
     */
    private function extendsModel(Node\Stmt\Class_ $class): bool
    {
        if ($class->extends === null) {
            return false;
        }

        return in_array($class->extends->getLast(), ['Model', 'Authenticatable', 'Pivot'], true);
    }

    /**
     * The default-value expression of the named property ($fillable / $guarded),
     * or null when the class does not declare it. Reading the property node skips
     * any occurrence inside a comment, docblock, or string literal.
     */
    private function propertyDefault(Node\Stmt\Class_ $class, string $name): ?Expr
    {
        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() === $name) {
                    return $prop->default;
                }
            }
        }

        return null;
    }

    private function isEmptyArray(Expr $expr): bool
    {
        return $expr instanceof Expr\Array_ && $expr->items === [];
    }

    /**
     * True for the explicit "guard everything" form $guarded = ['*'].
     */
    private function isGuardAllArray(Expr $expr): bool
    {
        if (! $expr instanceof Expr\Array_ || count($expr->items) !== 1) {
            return false;
        }

        $value = $expr->items[0]->value;

        return $value instanceof Node\Scalar\String_ && $value->value === '*';
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
