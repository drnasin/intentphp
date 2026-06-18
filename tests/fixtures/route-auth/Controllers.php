<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Fixtures\RouteAuth;

/**
 * Fixture controllers for RouteAuthorizationCheck reflection-based detection.
 *
 * The check resolves the controller via reflection on the route's "Class@method"
 * action and reads the method's raw source lines, so these must be real, loadable
 * classes with method bodies that exercise the comment/string/substring cases from
 * issue #14. Do not reformat — line content is the test surface.
 */

/** Body contains the substring "can(" via rescan(), but no real authorization. */
class SubstringController
{
    public function index(int $id): int
    {
        return $this->rescan($id);
    }

    private function rescan(int $id): int
    {
        return $id;
    }
}

/** Genuinely authorized via $this->authorize(). */
class AuthorizeController
{
    public function index(int $id): int
    {
        $this->authorize('view', $id);

        return $id;
    }

    private function authorize(string $ability, mixed $arg): void
    {
        // no-op stand-in for the AuthorizesRequests trait
    }
}

/** Genuinely authorized via $this->can(). */
class CanController
{
    public function index(int $id): bool
    {
        return $this->can('update');
    }

    private function can(string $ability): bool
    {
        return true;
    }
}

/** "$this->authorize(" appears only inside a comment and a string literal. */
class CommentStringController
{
    public function index(int $id): int
    {
        // $this->authorize('view', $id);
        $note = 'see $this->authorize( in docs';

        return $this->rescan($id);
    }

    private function rescan(int $id): int
    {
        return $id;
    }
}

/** Constructor genuinely calls $this->authorizeResource(); action itself unprotected. */
class AuthorizeResourceController
{
    public function __construct()
    {
        $this->authorizeResource('App\\Models\\Post', 'post');
    }

    public function index(int $id): int
    {
        return $this->rescan($id);
    }

    private function rescan(int $id): int
    {
        return $id;
    }

    private function authorizeResource(string $model, string $param): void
    {
        // no-op stand-in for the AuthorizesRequests trait
    }
}

/** Constructor mentions authorizeResource only in a comment — must NOT suppress. */
class CommentedAuthorizeResourceController
{
    public function __construct()
    {
        // $this->authorizeResource('App\\Models\\Post', 'post');
        $value = 1;
    }

    public function index(int $id): int
    {
        return $this->rescan($id);
    }

    private function rescan(int $id): int
    {
        return $id;
    }
}
