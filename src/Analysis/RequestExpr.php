<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\InterpolatedString;

/**
 * Recognises expressions that read user-controlled HTTP request data.
 *
 * This PR detects DIRECT request access only (e.g. $request->all(),
 * request()->input(), or $request->q inlined inside a string). Tracking a
 * request value through an intermediate variable ($d = $request->all(); ...$d)
 * is taint analysis and is intentionally out of scope here — see the C2
 * follow-up.
 */
final class RequestExpr
{
    /**
     * Any expression bottoming at the request object: $request, $request->q,
     * $request->input('q'), request()->get('q'), $request['q'].
     */
    public static function isAnyRequestAccess(Node $node): bool
    {
        if (self::isRequestRoot($node)) {
            return true;
        }

        if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
            return self::isAnyRequestAccess($node->var);
        }

        if ($node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch) {
            return self::isAnyRequestAccess($node->var);
        }

        if ($node instanceof Expr\ArrayDimFetch) {
            return self::isAnyRequestAccess($node->var);
        }

        return false;
    }

    /**
     * Does an interpolated string ("... $x ...") or a string concatenation
     * embed a direct request access? This is the raw-SQL injection shape that
     * a per-line regex cannot see (C3).
     */
    public static function embedsRequest(Node $node): bool
    {
        if ($node instanceof InterpolatedString) {
            foreach ($node->parts as $part) {
                // Skip the literal text chunks; inspect interpolated expressions.
                if (! $part instanceof Node\InterpolatedStringPart && self::embedsRequest($part)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof Expr\BinaryOp\Concat) {
            return self::embedsRequest($node->left) || self::embedsRequest($node->right);
        }

        return self::isAnyRequestAccess($node);
    }

    /**
     * The request object itself: the $request variable or the request() helper.
     * Matched by the exact name 'request' to avoid flagging unrelated domain
     * objects ($pullRequest, $friendRequest, ...).
     */
    private static function isRequestRoot(Node $node): bool
    {
        if ($node instanceof Expr\Variable && $node->name === 'request') {
            return true;
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
