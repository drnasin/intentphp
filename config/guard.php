<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Auth Middlewares
    |--------------------------------------------------------------------------
    |
    | Middleware names that count as "authorization protection" on a route.
    | Routes missing all of these will be flagged.
    |
    */
    'auth_middlewares' => ['auth', 'auth:sanctum'],

    /*
    |--------------------------------------------------------------------------
    | Public Routes
    |--------------------------------------------------------------------------
    |
    | URI patterns that are intentionally public. These will be skipped
    | during the route authorization check.
    |
    */
    'public_routes' => [
        'up',
        'health',
        'sanctum/csrf-cookie',
    ],

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
