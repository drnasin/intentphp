<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Fixtures\InvariantParity;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Fixture for the route-authorization parity test (Phase 11).
 *
 * The check resolves the controller via reflection on the route action and
 * reads parameter types to detect a custom FormRequest. This controller's
 * action takes a real FormRequest subclass and has no authorization, so it
 * must produce a finding whose context carries has_form_request = true.
 */
class StoreWidgetRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}

class FormRequestController
{
    public function store(StoreWidgetRequest $request): int
    {
        return 1;
    }
}
