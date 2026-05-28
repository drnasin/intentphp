<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * Per-function-scope context for taint analysis.
 *
 * Tracks two things, both monotonically (no flow-sensitivity): a variable is
 * tainted iff ANY tainting assignment exists in the function body. The model
 * is deliberately conservative — once `$x` is tainted, every read of `$x` in
 * that function is treated as tainted even if a later branch reassigns it.
 * This loses some precision in exchange for not needing a sound dataflow
 * engine, and avoids the unsound "assignment-line < sink-line" alternative
 * that misses loop-carried taint.
 *
 *  - $aliases: parameter names whose declared type is a Laravel Request /
 *    FormRequest (resolved by NameResolver during parsing). Within this
 *    function `$r->all()` is treated as request input when `$r` is such an
 *    alias.
 *  - $taintedBulk: variables that can hold the BULK request payload — direct
 *    assignment from $request->all()/except()/argless input()/validated(),
 *    OR wrapped through ?? / ternary / match / cast / array literal.
 *    Used by mass-assignment.
 *  - $taintedValue: variables holding ANY value derived from the request
 *    (bulk OR a single field OR a property fetch, including foreach element).
 *    Used by raw-SQL interpolation.
 *
 * Wrapper coverage: `$d = $x ?? $request->input('d')`, `(string) $request->q`,
 * `$d = match (...) { default => $request->all() }`, `$d = ['x' => $req->q]`,
 * `$sql = 'SELECT ' . $req->input('id')` all taint `$d` / `$sql`. Chained
 * `$a = $b = $request->all()` taints both. The visitor iterates to fixed
 * point, so a taint introduced after a downstream use also poisons it.
 */
final class FunctionScope
{
    /** @param array<string,true> $aliases param name => true */
    /** @param array<string,string> $taintedBulk var name => 'high'|'medium' */
    /** @param array<string,true> $taintedValue var name => true */
    public function __construct(
        public readonly array $aliases,
        public readonly array $taintedBulk,
        public readonly array $taintedValue,
    ) {}

    public static function empty(): self
    {
        return new self([], [], []);
    }

    public static function for(FunctionLike $fn): self
    {
        $aliases = self::collectAliases($fn);

        $body = $fn->getStmts() ?? [];
        $collector = new TaintCollectorVisitor($aliases);

        if ($body !== []) {
            // Iterate to a fixed point so taint flowing through variables is
            // captured regardless of source order (e.g. loop-carried taint).
            // Capped at a small bound — real code converges in 1–2 passes.
            for ($pass = 0; $pass < 8; $pass++) {
                $beforeBulk = $collector->taintedBulk;
                $beforeValue = $collector->taintedValue;

                $traverser = new NodeTraverser();
                $traverser->addVisitor($collector);
                $traverser->traverse($body);

                if ($beforeBulk === $collector->taintedBulk && $beforeValue === $collector->taintedValue) {
                    break;
                }
            }
        }

        return new self($aliases, $collector->taintedBulk, $collector->taintedValue);
    }

    /** @return array<string,true> */
    private static function collectAliases(FunctionLike $fn): array
    {
        $aliases = [];

        foreach ($fn->getParams() as $param) {
            if (! $param->var instanceof Expr\Variable || ! is_string($param->var->name)) {
                continue;
            }

            if (self::typeIsLaravelRequest($param->type)) {
                $aliases[$param->var->name] = true;
            }
        }

        return $aliases;
    }

    /**
     * True for Laravel's Illuminate\Http\Request and any class under
     * \Http\Requests\ (the FormRequest convention). The check uses the
     * fully-qualified name produced by NameResolver, so a domain object
     * named e.g. App\Domain\MergeRequest is correctly NOT treated as an
     * HTTP request.
     */
    private static function typeIsLaravelRequest(?Node $type): bool
    {
        if ($type instanceof Node\NullableType) {
            return self::typeIsLaravelRequest($type->type);
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $sub) {
                if (self::typeIsLaravelRequest($sub)) {
                    return true;
                }
            }

            return false;
        }

        if (! $type instanceof Node\Name) {
            return false;
        }

        // NameResolver populates this with the FQCN; fall back to toString
        // for the unresolved case (no namespace context in fixtures).
        $resolved = $type->getAttribute('resolvedName') ?? $type;
        $fqcn = ltrim($resolved instanceof Node\Name ? $resolved->toString() : (string) $resolved, '\\');

        if ($fqcn === '') {
            return false;
        }

        // Laravel's base Request class.
        if ($fqcn === 'Illuminate\\Http\\Request') {
            return true;
        }

        // FormRequest base + any class under a \Http\Requests\ namespace.
        if ($fqcn === 'Illuminate\\Foundation\\Http\\FormRequest') {
            return true;
        }

        return str_contains($fqcn, '\\Http\\Requests\\');
    }
}

/**
 * Walks a function body (skipping nested functions / closures, which have
 * their own scope) and records the assignments that taint variables.
 *
 * Designed to be run repeatedly until a fixed point — see {@see FunctionScope::for}.
 *
 * @internal
 */
final class TaintCollectorVisitor extends NodeVisitorAbstract
{
    /** @var array<string,string> */
    public array $taintedBulk = [];

    /** @var array<string,true> */
    public array $taintedValue = [];

    /** @param array<string,true> $aliases */
    public function __construct(private readonly array $aliases) {}

    public function enterNode(Node $node)
    {
        // Nested closures / arrow functions have their own scope — don't bleed.
        if ($node instanceof FunctionLike) {
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        if ($node instanceof Expr\Assign || $node instanceof Expr\AssignRef) {
            $this->taintFromAssign($node->var, $node->expr);
        } elseif ($node instanceof Expr\AssignOp\Coalesce || $node instanceof Expr\AssignOp\Concat) {
            // $x ??= ... / $x .= ...  →  treat as a taint-bearing assignment.
            $this->taintFromAssign($node->var, $node->expr);
        } elseif ($node instanceof Stmt\Foreach_) {
            // foreach ($request->all() as $v) — each element is request-derived.
            // Note: containsRequestAny unwraps ??/cast/etc on the source expr,
            // so `foreach (($r->input('ids') ?? []) as $id)` taints $id too.
            if ($this->containsRequestAny($node->expr)) {
                $this->taintLhsValueOnly($node->valueVar);
                if ($node->keyVar !== null) {
                    $this->taintLhsValueOnly($node->keyVar);
                }
            }
        }

        return null;
    }

    private function taintFromAssign(Node $lhs, Node $rhs): void
    {
        // [$a, $b] = ... — recurse so [$a, [$b, $c]] = $request->all() taints
        // every leaf variable, not just the top-level ones.
        if ($lhs instanceof Expr\Array_ || $lhs instanceof Expr\List_) {
            if (! $this->containsRequestAny($rhs)) {
                return;
            }
            foreach ($lhs->items as $item) {
                if ($item === null) {
                    continue;
                }
                $this->taintLhsValueOnly($item->value);
            }

            return;
        }

        if (! $lhs instanceof Expr\Variable || ! is_string($lhs->name)) {
            return;
        }

        $bulk = $this->bulkSeverity($rhs);
        if ($bulk !== null) {
            $existing = $this->taintedBulk[$lhs->name] ?? null;
            if ($existing === null || ($existing === 'medium' && $bulk === 'high')) {
                $this->taintedBulk[$lhs->name] = $bulk;
            }
            $this->taintedValue[$lhs->name] = true;

            return;
        }

        if ($this->containsRequestAny($rhs)) {
            $this->taintedValue[$lhs->name] = true;
        }
    }

    /**
     * Apply value-only taint to an LHS that's a Variable or a nested destructure
     * pattern. Used for foreach values and array-destructure items.
     */
    private function taintLhsValueOnly(Node $lhs): void
    {
        if ($lhs instanceof Expr\Variable && is_string($lhs->name)) {
            $this->taintedValue[$lhs->name] = true;

            return;
        }

        if ($lhs instanceof Expr\Array_ || $lhs instanceof Expr\List_) {
            foreach ($lhs->items as $item) {
                if ($item === null) {
                    continue;
                }
                $this->taintLhsValueOnly($item->value);
            }
        }
    }

    /**
     * Classifies an RHS as 'high' (bulk request payload) or 'medium' (validated)
     * or null. Recurses through expression wrappers (??, ?:, match, cast, array
     * literal, concat, chained assign, parens) so the leaf is what matters,
     * not the wrapper. The worst severity across branches wins.
     */
    private function bulkSeverity(Node $expr): ?string
    {
        // Direct bulk-request method call.
        if ($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall) {
            if (! $expr->name instanceof Node\Identifier) {
                return null;
            }
            if (! $this->isRequestRoot($expr->var)) {
                return null;
            }
            $method = $expr->name->toString();
            if ($method === 'input' && $expr->args !== []) {
                return null;
            }
            if ($method === 'all' || $method === 'except' || $method === 'input') {
                return 'high';
            }
            if ($method === 'validated') {
                return 'medium';
            }

            return null;
        }

        // Variable referring to an already-tainted bulk variable.
        if ($expr instanceof Expr\Variable && is_string($expr->name)) {
            return $this->taintedBulk[$expr->name] ?? null;
        }

        if ($expr instanceof Expr\Assign || $expr instanceof Expr\AssignRef) {
            return $this->bulkSeverity($expr->expr);
        }

        if ($expr instanceof Expr\BinaryOp\Coalesce) {
            return $this->maxSeverity(
                $this->bulkSeverity($expr->left),
                $this->bulkSeverity($expr->right),
            );
        }

        if ($expr instanceof Expr\Ternary) {
            return $this->maxSeverity(
                $expr->if !== null ? $this->bulkSeverity($expr->if) : null,
                $this->bulkSeverity($expr->else),
            );
        }

        if ($expr instanceof Expr\Match_) {
            $sev = null;
            foreach ($expr->arms as $arm) {
                $sev = $this->maxSeverity($sev, $this->bulkSeverity($arm->body));
            }

            return $sev;
        }

        if ($expr instanceof Expr\Cast) {
            return $this->bulkSeverity($expr->expr);
        }

        if ($expr instanceof Expr\Array_) {
            $sev = null;
            foreach ($expr->items as $item) {
                if ($item !== null) {
                    $sev = $this->maxSeverity($sev, $this->bulkSeverity($item->value));
                }
            }

            return $sev;
        }

        return null;
    }

    private function maxSeverity(?string $a, ?string $b): ?string
    {
        if ($a === 'high' || $b === 'high') {
            return 'high';
        }
        if ($a === 'medium' || $b === 'medium') {
            return 'medium';
        }

        return null;
    }

    /**
     * True if $expr embeds ANY request-derived data — direct access on the
     * request, a tainted variable, OR the same buried inside wrappers
     * (?? / ?: / match / cast / concat / array / interpolation / chained assign).
     */
    private function containsRequestAny(Node $expr): bool
    {
        if ($this->isRequestRoot($expr)) {
            return true;
        }

        if ($expr instanceof Expr\Variable && is_string($expr->name)) {
            return isset($this->taintedValue[$expr->name]);
        }

        if ($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall) {
            return $this->containsRequestAny($expr->var);
        }

        if ($expr instanceof Expr\PropertyFetch || $expr instanceof Expr\NullsafePropertyFetch) {
            return $this->containsRequestAny($expr->var);
        }

        if ($expr instanceof Expr\ArrayDimFetch) {
            return $this->containsRequestAny($expr->var);
        }

        if ($expr instanceof Expr\BinaryOp\Coalesce
            || $expr instanceof Expr\BinaryOp\Concat
        ) {
            return $this->containsRequestAny($expr->left) || $this->containsRequestAny($expr->right);
        }

        if ($expr instanceof Expr\Ternary) {
            return ($expr->if !== null && $this->containsRequestAny($expr->if))
                || $this->containsRequestAny($expr->else);
        }

        if ($expr instanceof Expr\Match_) {
            foreach ($expr->arms as $arm) {
                if ($this->containsRequestAny($arm->body)) {
                    return true;
                }
            }

            return false;
        }

        if ($expr instanceof Expr\Cast) {
            return $this->containsRequestAny($expr->expr);
        }

        if ($expr instanceof Expr\Array_) {
            foreach ($expr->items as $item) {
                if ($item !== null && $this->containsRequestAny($item->value)) {
                    return true;
                }
            }

            return false;
        }

        if ($expr instanceof InterpolatedString) {
            foreach ($expr->parts as $part) {
                if (! $part instanceof Node\InterpolatedStringPart && $this->containsRequestAny($part)) {
                    return true;
                }
            }

            return false;
        }

        if ($expr instanceof Expr\Assign || $expr instanceof Expr\AssignRef) {
            return $this->containsRequestAny($expr->expr);
        }

        return false;
    }

    private function isRequestRoot(Node $node): bool
    {
        if ($node instanceof Expr\Variable && is_string($node->name)) {
            return $node->name === 'request' || isset($this->aliases[$node->name]);
        }

        if ($node instanceof Expr\FuncCall
            && $node->name instanceof Node\Name
            && $node->name->toString() === 'request'
        ) {
            return true;
        }

        return false;
    }
}
