<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Auth Middlewares (deprecated — use route_authorization instead)
    |--------------------------------------------------------------------------
    |
    | Legacy flat list. If route_authorization.auth_middleware_exact is set,
    | this key is ignored. Kept for backward compatibility.
    |
    */
    'auth_middlewares' => [
        'auth',
        'auth:sanctum',
        'Filament\\Http\\Middleware\\Authenticate',
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Authorization (smart defaults)
    |--------------------------------------------------------------------------
    |
    | Structured configuration for the route-authorization check.
    | Preferred over the legacy 'auth_middlewares' key above.
    |
    */
    'route_authorization' => [

        // Exact middleware strings that count as "protected"
        'auth_middleware_exact' => [
            'auth',
            'auth:sanctum',
            'Filament\\Http\\Middleware\\Authenticate',
        ],

        // Alias prefixes — 'auth' matches 'auth:api', 'auth:web', etc.
        'auth_middleware_prefixes' => ['auth'],

        // FQCN suffix patterns (opt-in, default empty to avoid false negatives).
        // Example: ['\\Http\\Middleware\\Authenticate'] would match any
        // middleware FQCN ending with that string.
        'auth_middleware_suffixes' => [],

        // Guest auth routes skipped by default (framework standard routes).
        // Override with an empty array to disable.
        'skip_guest_routes' => [
            'login',
            'register',
            'forgot-password',
            'reset-password/*',
            'two-factor-challenge',
            'email/verify',
            'email/verify/*',
            'confirm-password',
        ],

        // Infrastructure routes skipped by default.
        // Override with an empty array to disable.
        'skip_infra_routes' => [
            'up',
            'health',
            'sanctum/csrf-cookie',
            'livewire/*',
            '_ignition/*',
            '_debugbar/*',
            '_boost/*',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Public Routes
    |--------------------------------------------------------------------------
    |
    | User-declared URI patterns that are intentionally public.
    | These are checked AFTER the built-in skip lists above.
    | Add your business-specific public routes here.
    |
    */
    'public_routes' => [],

    /*
    |--------------------------------------------------------------------------
    | AI Configuration
    |--------------------------------------------------------------------------
    |
    | Enable AI-powered fix suggestions. When disabled or when the driver
    | is 'null', the scanner works fully without any AI dependency.
    |
    */
    'ai' => [
        'enabled' => (bool) env('GUARD_AI_ENABLED', false),
        'driver' => env('GUARD_AI_DRIVER', 'null'), // null|cli|openai|auto

        'cli' => [
            'command' => env('GUARD_AI_CLI', 'claude'),
            'args' => env('GUARD_AI_CLI_ARGS', ''),
            'timeout' => (int) env('GUARD_AI_CLI_TIMEOUT', 60),
            'expects_json' => (bool) env('GUARD_AI_CLI_JSON', false),
            'adapter' => env('GUARD_AI_CLI_ADAPTER', 'auto'), // auto|claude|codex|generic
            'prompt_prefix' => env('GUARD_AI_CLI_PROMPT_PREFIX', ''),
        ],

        'openai' => [
            'base_url' => env('GUARD_AI_BASE_URL', 'https://api.openai.com/v1'),
            'api_key' => env('GUARD_AI_API_KEY', ''),
            'model' => env('GUARD_AI_MODEL', 'gpt-4.1-mini'),
            'timeout' => (int) env('GUARD_AI_TIMEOUT', 30),
            'max_tokens' => (int) env('GUARD_AI_MAX_TOKENS', 1024),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Inline Ignores
    |--------------------------------------------------------------------------
    |
    | Allow developers to suppress individual findings with inline comments:
    | // guard:ignore <check-name>
    | // guard:ignore all
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Scan Cache
    |--------------------------------------------------------------------------
    |
    | Cache expensive computations (ProjectMap, reflection) to speed up
    | repeated scans. Cache is stored in storage/guard/cache/ and
    | invalidated automatically when git HEAD or app version changes.
    |
    */
    'cache' => [
        'enabled' => (bool) env('GUARD_CACHE_ENABLED', true),
    ],

    'allow_inline_ignores' => true,

];
