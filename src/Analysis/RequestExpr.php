<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\InterpolatedString;

/**
 * Recognises expressions that read user-controlled HTTP request data.
 *
 * When called with a {@see FunctionScope}, also honours request-aliasing
 * parameters (FormRequest-typed args) and variables tainted by an earlier
 * assignment within the function (C2). With no scope, only the literal
 * $request variable and request() helper count.
 */
final class RequestExpr
{
    /**
     * Any expression bottoming at the request object: $request, $request->q,
     * $request->input('q'), request()->get('q'), $request['q'], or — when a
     * scope is provided — the same against a FormRequest-aliasing parameter.
     *
     * Note: this is "syntactic" request access. A variable that holds a
     * value previously read from the request is NOT matched here — use
     * {@see embedsRequest} for the broader "any request-derived data" test.
     */
    public static function isAnyRequestAccess(Node $node, ?FunctionScope $scope = null): bool
    {
        $scope ??= FunctionScope::empty();

        if (self::isRequestRoot($node, $scope)) {
            return true;
        }

        if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
            return self::isAnyRequestAccess($node->var, $scope);
        }

        if ($node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch) {
            return self::isAnyRequestAccess($node->var, $scope);
        }

        if ($node instanceof Expr\ArrayDimFetch) {
            return self::isAnyRequestAccess($node->var, $scope);
        }

        return false;
    }

    /**
     * Does $node embed request-derived data? Catches:
     *   - direct request access (see {@see isAnyRequestAccess});
     *   - the same access embedded in an interpolated string or concat;
     *   - a tainted variable (recorded by {@see FunctionScope}) — this is
     *     what closes the C2 indirection gap: $dir = $request->input('dir');
     *     ->whereRaw("name $dir");
     *   - the same buried in wrappers (?? / ?: / match / cast / array literal)
     *     so e.g. DB::select($cond ? $safe : $request->input('q')) is caught.
     */
    public static function embedsRequest(Node $node, ?FunctionScope $scope = null): bool
    {
        $scope ??= FunctionScope::empty();

        if ($node instanceof InterpolatedString) {
            foreach ($node->parts as $part) {
                if (! $part instanceof Node\InterpolatedStringPart && self::embedsRequest($part, $scope)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof Expr\BinaryOp\Concat || $node instanceof Expr\BinaryOp\Coalesce) {
            return self::embedsRequest($node->left, $scope) || self::embedsRequest($node->right, $scope);
        }

        if ($node instanceof Expr\Ternary) {
            return ($node->if !== null && self::embedsRequest($node->if, $scope))
                || self::embedsRequest($node->else, $scope);
        }

        if ($node instanceof Expr\Match_) {
            foreach ($node->arms as $arm) {
                if (self::embedsRequest($arm->body, $scope)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof Expr\Cast) {
            return self::embedsRequest($node->expr, $scope);
        }

        if ($node instanceof Expr\Array_) {
            foreach ($node->items as $item) {
                if ($item !== null && self::embedsRequest($item->value, $scope)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof Expr\Variable
            && is_string($node->name)
            && isset($scope->taintedValue[$node->name])
        ) {
            return true;
        }

        return self::isAnyRequestAccess($node, $scope);
    }

    /**
     * The request object itself: the $request variable, the request() helper,
     * or — when a scope is provided — a FormRequest-aliasing parameter.
     */
    private static function isRequestRoot(Node $node, FunctionScope $scope): bool
    {
        if ($node instanceof Expr\Variable && is_string($node->name)) {
            if ($node->name === 'request' || isset($scope->aliases[$node->name])) {
                return true;
            }
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
