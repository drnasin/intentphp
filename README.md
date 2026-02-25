# IntentPHP Guard

[![Tests](https://github.com/drnasin/intentphp/actions/workflows/tests.yml/badge.svg)](https://github.com/drnasin/intentphp/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/github/v/release/drnasin/intentphp?display_name=tag&sort=semver)](https://github.com/drnasin/intentphp/releases)
[![PHP](https://img.shields.io/badge/php-8.2%2B-8892BF)](https://php.net)
[![Laravel](https://img.shields.io/badge/laravel-10%20%7C%2011%20%7C%2012-FF2D20)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

A Laravel CLI tool that scans your application for common security risks: authorization gaps, unsafe query input, and mass assignment vulnerabilities.

## Why Guard?

Most static analysis tools answer:  
**“Is this code valid?”**

Guard answers a different question:  
**“Does this code match the security and data-handling intent of this project?”**

Guard introduces an optional **intent specification** (`intent/intent.yaml`) where you declare expected security and data rules — for example:

- which routes must be authenticated
- which guards must be used
- which models must use explicit `$fillable`
- which attributes must never be mass-assignable

Guard then scans your code and reports mismatches between:

> declared intent vs actual implementation

This makes Guard especially useful in CI pipelines, security-sensitive Laravel applications, and multi-developer teams where architectural and security rules must stay enforced over time.

Guard is:

- ✅ additive — no breaking changes without intent spec
- ✅ deterministic — stable fingerprints and reproducible results
- ✅ CI-safe — predictable exit codes and output
- ✅ non-invasive — never modifies your code

## Comparison With Other Tools

| Tool type | Examples | What they focus on | How Guard differs |
|------------|------------|---------------------|-------------------|
| Static analysis | PHPStan, Larastan | Type safety, code correctness, API misuse | Guard does not check types — it enforces **security and data invariants** |
| Security scanners (SAST) | Semgrep, CodeQL, Sonar | Pattern-based vulnerability detection | Guard uses a **project intent spec** — rules come from your declared policy, not a global pattern database |
| Laravel linters | Pint, style tools | Code style and formatting | Guard does not enforce style — it focuses on **auth and data-safety rules** |
| Config / policy scanners | CI security checkers | Known misconfiguration patterns | Guard validates your **declared security model** against actual code behavior |
| Runtime protection | WAF, middleware | Runtime request filtering and blocking | Guard runs **before deploy**, in CI, as static policy validation |

## Quick Start

```bash
composer require intentphp/guard --dev
php artisan guard:baseline
php artisan guard:scan --baseline --strict
```

Add the last command to CI — Guard will now fail builds only on **new** security risks.

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12

## Installation

Install Guard as a dev dependency:

```bash
composer require intentphp/guard --dev
```

The service provider is auto-discovered. Optionally publish the config:

```bash
php artisan vendor:publish --tag=guard-config
```

## Commands

### `guard:scan` — Scan for security issues

```bash
# Default: console table output, all severities
php artisan guard:scan

# JSON output, high severity only
php artisan guard:scan --format=json --severity=high

# GitHub Actions annotation output (for CI)
php artisan guard:scan --format=github

# Markdown output (for PR comments)
php artisan guard:scan --format=md

# Include AI-generated fix suggestions
php artisan guard:scan --ai

# Incremental: scan only files changed vs base branch
php artisan guard:scan --changed

# Incremental: explicit base ref
php artisan guard:scan --changed --base=origin/main

# Incremental: staged files only (pre-commit hook)
php artisan guard:scan --staged

# Incremental: compare against a specific ref
php artisan guard:scan --changed-since=v1.2.0

# Save report to a file (json or md format)
php artisan guard:scan --format=md --output=storage/guard/report.md
php artisan guard:scan --format=json --output=storage/guard/report.json

# Use baseline to suppress known findings
php artisan guard:scan --baseline

# Strict mode: fail if baseline file is missing
php artisan guard:scan --baseline --strict

# Show suppressed findings in output
php artisan guard:scan --include-suppressed

# Limit displayed findings
php artisan guard:scan --max=20

# Include AI patch proposals in JSON output
php artisan guard:scan --format=json --ai --include-ai-patch
```

**Exit codes:**
- `0` — No active HIGH severity findings
- `1` — Active HIGH severity findings exist
- `2` — Baseline file missing (with `--strict`)

### `guard:baseline` — Save current findings as baseline

```bash
# Baseline all findings
php artisan guard:baseline

# Baseline only HIGH findings
php artisan guard:baseline --severity=high
```

Saves a fingerprint snapshot to `storage/guard/baseline.json`. Future scans with `--baseline` will suppress any findings that match the saved fingerprints.

This is the recommended workflow for adopting Guard on an existing codebase:

1. Run `php artisan guard:baseline` to snapshot current state
2. Add `php artisan guard:scan --baseline --strict` to CI
3. CI will now only fail on **new** findings, not existing ones
4. Fix existing findings at your own pace

### `guard:fix` — Generate safe patch proposals

```bash
# Template-based patches
php artisan guard:fix

# Include AI-assisted patches for findings where templates fail
php artisan guard:fix --ai

# Fix only changed files
php artisan guard:fix --changed
php artisan guard:fix --staged
php artisan guard:fix --changed --base=origin/main
```

Generates `.diff` files in `storage/guard/patches/` for each HIGH severity finding. With `--ai`, falls back to AI-generated patches when templates cannot produce a diff. AI patches are marked with a header comment. When AI returns guidance that cannot be structured as a diff, a `.md` note file is written instead.

> **Warning:** Always review patches before applying. Guard never guarantees semantic correctness of generated diffs.

Patches are never applied automatically — review and apply manually:

```bash
git apply storage/guard/patches/001_route_authorization_OrderController_L25.diff
```

**Exit codes:**
- `0` — No patches generated (clean scan)
- `1` — Patches were generated (findings exist)

### `guard:apply` — Validate and apply a patch

```bash
# Validate a patch file (dry-run)
php artisan guard:apply storage/guard/patches/001_route_authorization_OrderController_L25.diff

# Also accepts just the filename (resolves from storage/guard/patches/)
php artisan guard:apply 001_route_authorization_OrderController_L25.diff
```

Checks if a patch applies cleanly using `git apply --check` and shows the command to apply it. Never applies patches automatically.

### `guard:testgen` — Generate security tests

```bash
# Generate tests (skip existing files)
php artisan guard:testgen

# Overwrite existing generated tests
php artisan guard:testgen --overwrite
```

Generates PHPUnit tests in `tests/Feature/GuardGenerated/`:

- `RouteAuthorizationTest.php` — guest access assertions
- `DangerousInputValidationTest.php` — malicious input regression tests
- `MassAssignmentProtectionTest.php` — model protection assertions

### `guard:intent` — Manage the intent spec

```bash
# Scaffold a starter intent/intent.yaml
php artisan guard:intent init

# Validate an existing intent spec
php artisan guard:intent validate

# Show parsed spec summary
php artisan guard:intent show
```

Manages the optional `intent/intent.yaml` spec file. `init` generates a starter file with example auth rules and model declarations. `validate` checks the spec for parse and schema errors. `show` prints a summary of the parsed spec. The intent spec is optional — Guard works without it.

### `guard:doctor` — Environment diagnostics

```bash
php artisan guard:doctor
```

Runs a series of environment checks and prints a diagnostic report with actionable guidance. Useful for verifying your setup after installation or troubleshooting issues.

**Checks performed:**

| Section | What it verifies |
|---------|------------------|
| Laravel Context | `artisan` file exists (confirms Laravel project) |
| Storage / Writable | `storage/guard/`, `cache/`, and `patches/` directories are writable |
| Git | `git` binary available and project is a git repository |
| Baseline | Whether a baseline suppression file exists |
| AI Driver | AI configuration, CLI tool availability, API key presence |
| Cache | Cache enabled/disabled status and path |

**Exit codes:**

| Code | Meaning |
|------|---------|
| `0` | No blocking errors (warnings are OK) |
| `1` | Blocking errors found (e.g., storage not writable, not a Laravel app) |

**Example output:**

```
IntentPHP Guard — Environment Diagnostics
==========================================

Laravel Context
  [OK]    Artisan file found at /var/www/app/artisan

Storage / Writable
  [OK]    storage/guard/ is writable
  [OK]    storage/guard/cache/ is writable
  [OK]    storage/guard/patches/ is writable

Git
  [OK]    git binary found (git version 2.43.0)
  [OK]    Repository detected — incremental scanning available.

Baseline
  [WARN]  No baseline file found. Run: php artisan guard:baseline

AI Driver
  [OK]    AI is disabled. Enable with GUARD_AI_ENABLED=true.

Cache
  [OK]    Cache enabled. Path: storage/guard/cache
  [OK]    Tip: use --no-cache with guard:scan to bypass cache for a single run.

──────────────────────────────────────────
Result: 0 error(s), 1 warning(s) — all clear!
```

## Checks

### 1. Route Authorization Coverage

Detects routes missing auth middleware or authorization calls. Checks route-level and group-level middleware, `$this->authorize()`, `Gate::` calls, `authorizeResource()` in constructors, and FormRequest type hints. Enriched with Project Map context (model, policy, ability).

### 2. Dangerous Request Input in Queries

Detects patterns where `$request->input()`, `$request->get()`, or `request()` helper values flow directly into query builder methods like `orderBy`, `where`, `whereRaw`, `selectRaw`, or `DB::raw`.

### 3. Mass Assignment Risk

Detects `Model::create($request->all())`, `->update($request->all())`, and `->fill($request->all())` when the model has no `$fillable` or uses `$guarded = []`. Usage of `$request->validated()` is flagged as MEDIUM severity.

### 4. Intent Auth (`intent-auth`)

Compares actual route middleware against requirements declared in the intent spec. Detects routes that should be authenticated, require a specific guard, or are declared public but lack auth middleware. Only active when `intent/intent.yaml` is present and contains `auth.rules`.

### 5. Intent Mass Assignment (`intent-mass-assignment`)

Checks model files against mass-assignment constraints declared in the intent spec. Detects models missing `$fillable` when declared as `explicit_allowlist`, forbidden attributes present in `$fillable`, and empty `$guarded` when declared as `guarded` mode. Only active when `intent/intent.yaml` is present and contains `data.models`.

Intent checks are additive. A route can receive both a `route-authorization` finding and an `intent-auth` finding. They serve different purposes (config-driven vs spec-driven) and are independently suppressible via baseline or inline ignores.

## Intent Spec (optional)

Guard supports an optional `intent/intent.yaml` file at the project root. This file declares expected security properties (auth rules, model constraints) that Guard validates against your actual code.

- If the file is missing, Guard behaves exactly as before. No configuration needed.
- If the file is present and valid, additional `intent-auth` and `intent-mass-assignment` checks run alongside existing checks.
- If the file has parse errors or validation failures, Guard prints the errors and exits non-zero.
- This is fully additive. No existing behavior changes.

### Setup

```bash
# Scaffold a starter intent spec
php artisan guard:intent init

# Run scan (intent checks activate automatically if spec exists)
php artisan guard:scan
```

### Minimal example

```yaml
version: "0.1"
project:
  name: my-app
  framework: laravel

auth:
  guards:
    api: token
  rules:
    - id: api-protected
      match:
        routes:
          prefix: /api
      require:
        authenticated: true
        guard: api

data:
  models:
    App\Models\User:
      massAssignment:
        mode: explicit_allowlist
        allow: [name, email]
        forbid: [is_admin]
```

### Warnings

If the intent spec references a model whose file cannot be found on disk, Guard prints a warning and continues. Warnings do not produce findings and do not cause the scan to fail. The scan only fails if the spec itself is structurally invalid.

## Inline Suppressions

Suppress individual findings by adding a comment on the same line or the line above:

```php
// guard:ignore dangerous-query-input
->orderBy($request->input('sort'))

->orderBy($request->input('sort')) // guard:ignore dangerous-query-input

// guard:ignore all
->whereRaw($request->input('filter'))
```

Suppressed findings are tracked and shown in the summary. Disable inline ignores in config:

```php
'allow_inline_ignores' => false,
```

## Configuration

```php
// config/guard.php

return [
    'auth_middlewares' => ['auth', 'auth:sanctum'],

    'public_routes' => [
        'up',
        'health',
        'sanctum/csrf-cookie',
    ],

    'ai' => [
        'enabled' => env('GUARD_AI_ENABLED', false),
        'driver' => env('GUARD_AI_DRIVER', 'null'), // null|cli|openai|auto

        'cli' => [
            'command' => env('GUARD_AI_CLI', 'claude'),
            'args' => env('GUARD_AI_CLI_ARGS', ''),
            'timeout' => env('GUARD_AI_CLI_TIMEOUT', 60),
            'expects_json' => env('GUARD_AI_CLI_JSON', false),
            'adapter' => env('GUARD_AI_CLI_ADAPTER', 'auto'),
            'prompt_prefix' => env('GUARD_AI_CLI_PROMPT_PREFIX', ''),
        ],

        'openai' => [
            'base_url' => env('GUARD_AI_BASE_URL', 'https://api.openai.com/v1'),
            'api_key' => env('GUARD_AI_API_KEY', ''),
            'model' => env('GUARD_AI_MODEL', 'gpt-4.1-mini'),
            'timeout' => env('GUARD_AI_TIMEOUT', 30),
            'max_tokens' => env('GUARD_AI_MAX_TOKENS', 1024),
        ],
    ],

    'cache' => [
        'enabled' => env('GUARD_CACHE_ENABLED', true),
    ],

    'allow_inline_ignores' => true,
];
```

## Incremental Scanning

Guard can scan only files that changed in your working tree, dramatically speeding up scans in large projects and CI pipelines.

### Modes

| Flag | What it scans |
|------|---------------|
| `--changed` | Files changed vs auto-detected base branch |
| `--changed --base=REF` | Files changed vs the given ref |
| `--staged` | Only staged files (ideal for pre-commit hooks) |
| `--changed-since=REF` | Files changed since a specific commit/tag |

Base branch auto-detection tries: `origin/main` → `origin/master` → `main` → `master` → `HEAD~1`. If your default branch is `master`, use `--base=origin/master` to skip auto-detection.

Route authorization check runs in one of three modes:
- **full** — when route files changed or no incremental mode is active
- **filtered** — when only controller files changed (only related routes are checked)
- **skipped** — when neither routes nor controllers changed

File-based checks (dangerous query input, mass assignment) are filtered to only the changed files.

### Pre-commit hook example

```bash
#!/bin/sh
php artisan guard:scan --staged --severity=high --format=github
```

### CI with incremental scan

```yaml
- name: Run Guard scan (incremental)
  run: php artisan guard:scan --changed --base=origin/main --format=github --severity=high
```

## Caching

Guard caches expensive computations (Project Map, reflection results) to speed up repeated scans. The cache is stored in `storage/guard/cache/` and invalidated automatically when:

- The git HEAD SHA changes (new commits), when available
- The PHP or Laravel version changes
- Relevant file modification times change (routes, controllers, policies, kernel, auth provider) — used as fallback when git SHA is unavailable

### Configuration

Caching is enabled by default. To disable:

```env
GUARD_CACHE_ENABLED=false
```

### Clearing the cache

```bash
rm -rf storage/guard/cache/
```

The cache is safe to delete at any time — Guard will rebuild it on the next scan.

You can bypass the cache for a single run with `--no-cache`:

```bash
php artisan guard:scan --no-cache
```

## Performance Notes

- **Full scan**: analyzes routes + controllers + models (every PHP file in scope)
- **Incremental scan**: analyzes only changed files, with route check filtering based on what changed
- **With caching enabled**, repeated scans are typically 3-10x faster (Project Map and reflection results are cached)

## Report Output

### Saving reports to file

Use `--output` with `--format=json` or `--format=md` to save the report to a file:

```bash
# Markdown report file
php artisan guard:scan --format=md --output=report.md

# JSON report file
php artisan guard:scan --format=json --output=report.json
```

The `--output` option is supported for `json` and `md` formats. Console format (`--format=console`) does not support file output. When `--output` is set, Guard writes the report to the file and prints a short confirmation line to the console.

### AI Setup (Local CLI)

Guard uses locally installed AI CLI tools (e.g. `claude`, `codex`) to generate fix suggestions. No API keys needed for local usage.

**Quick start (macOS / Linux):**

```bash
# .env
GUARD_AI_ENABLED=true
GUARD_AI_DRIVER=auto
```

**Quick start (Windows PowerShell):**

```powershell
$env:GUARD_AI_ENABLED="true"
$env:GUARD_AI_DRIVER="auto"
php artisan guard:scan --ai
```

The `auto` driver tries in order: local CLI tool in PATH, then OpenAI API (if key set), then falls back gracefully to no AI (scan still works, just without suggestions).

**Explicit CLI configuration:**

```env
GUARD_AI_ENABLED=true
GUARD_AI_DRIVER=cli
GUARD_AI_CLI=claude
GUARD_AI_CLI_ARGS="--print"
GUARD_AI_CLI_TIMEOUT=60
```

**Using a different CLI tool:**

```env
GUARD_AI_CLI=codex
GUARD_AI_CLI_ADAPTER=codex
```

**Using any custom CLI:**

```env
GUARD_AI_CLI=/usr/local/bin/my-ai-tool
GUARD_AI_CLI_ADAPTER=generic
GUARD_AI_CLI_ARGS="--stdin --format text"
```

**JSON output mode** — If your CLI supports structured JSON output with `suggestion` and `patch` keys, enable it for richer results:

```env
GUARD_AI_CLI_JSON=true
GUARD_AI_CLI_ARGS="--output-format json"
```

### AI Setup (OpenAI API)

For CI pipelines or environments without a local CLI tool, use the OpenAI HTTP driver:

```env
GUARD_AI_ENABLED=true
GUARD_AI_DRIVER=openai
GUARD_AI_API_KEY=sk-your-key-here
```

**Compatible with any OpenAI-compatible API** — Azure OpenAI, Ollama, LM Studio, local vLLM, etc.:

```env
GUARD_AI_BASE_URL=http://localhost:11434/v1
GUARD_AI_MODEL=llama3
```

| Variable | Default | Description |
|----------|---------|-------------|
| `GUARD_AI_API_KEY` | *(empty)* | API key (required for `openai` driver) |
| `GUARD_AI_BASE_URL` | `https://api.openai.com/v1` | API base URL |
| `GUARD_AI_MODEL` | `gpt-4.1-mini` | Model name |
| `GUARD_AI_TIMEOUT` | `30` | Request timeout in seconds |
| `GUARD_AI_MAX_TOKENS` | `1024` | Max tokens in response |

The client retries once on 429 (rate limit) and 5xx errors. API key is never logged.

### AI Driver Priority (`auto`)

When using `GUARD_AI_DRIVER=auto`, Guard selects the best available driver:

1. **CLI** — local `claude`/`codex` binary in PATH (free, no API cost)
2. **OpenAI** — API key set in `GUARD_AI_API_KEY`
3. **Null** — no AI, scan works normally without suggestions

This means `auto` works everywhere: locally with a CLI tool, in CI with an API key, or gracefully without either.

### Troubleshooting AI

| Problem | Cause | Fix |
|---------|-------|-----|
| "CLI command not found in PATH" | Binary not installed or not in PATH | Install the CLI tool, or set full path in `GUARD_AI_CLI` |
| "exited with code N" | CLI tool returned an error | Check `storage/logs/laravel.log` for stderr output |
| Timeout | AI took too long | Increase `GUARD_AI_CLI_TIMEOUT` (default: 60s) |
| Empty output | CLI didn't write to stdout | Check that your CLI args are correct |
| Scan works but no suggestions | AI not enabled | Set `GUARD_AI_ENABLED=true` and `GUARD_AI_DRIVER=auto` |
| "AI request failed after retries" | API returned errors | Check `storage/logs/laravel.log` for HTTP status and body |
| "GUARD_AI_API_KEY not set" | Missing API key | Set `GUARD_AI_API_KEY` in `.env` |

### Security Note

The prompt sent to the AI includes only the finding context: check name, severity, message, file path, line number, and a short code snippet. Guard never sends your entire codebase or any secrets to the AI. All AI output is treated as suggestions — Guard never applies changes automatically.

## Non-Goals

Guard does not:
- Apply patches automatically — all fixes require manual review and `git apply`
- Upload your repository or source code to any external service
- Replace full security audits, penetration testing, or dedicated SAST/DAST tools

Guard is a lightweight first line of defense that catches common Laravel security patterns early in the development cycle.

## Recommended CI Setup

For most teams, this is the golden path:

1. Commit a baseline once:

```bash
php artisan guard:baseline
git add storage/guard/baseline.json
git commit -m "Add Guard baseline"
```

2. In CI, use:

```bash
php artisan guard:scan --baseline --changed --base=origin/main --format=github --severity=high
```

This gives you:
- Fast incremental scans (only changed files)
- No legacy noise (baseline suppresses known findings)
- PR annotations only for new issues

## CI Integration (GitHub Actions)

### Basic scan

```yaml
name: Security Scan
on: [push, pull_request]

jobs:
  guard:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'

      - name: Install dependencies
        run: composer install --no-interaction

      - name: Run Guard scan
        run: php artisan guard:scan --format=github --severity=high
```

### With baseline (recommended for existing projects)

```yaml
name: Security Scan
on: [push, pull_request]

jobs:
  guard:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'

      - name: Install dependencies
        run: composer install --no-interaction

      - name: Run Guard scan (baseline)
        run: php artisan guard:scan --format=github --severity=high --baseline --strict
```

Commit `storage/guard/baseline.json` to your repository. The scan will only fail on new findings.

### Incremental scan on PRs (fastest)

```yaml
name: Security Scan (Incremental)
on:
  pull_request:
    branches: [main]

jobs:
  guard:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'

      - name: Install dependencies
        run: composer install --no-interaction

      - name: Run Guard scan (changed files only)
        run: php artisan guard:scan --changed --base=origin/main --format=github --severity=high
```

Note: `fetch-depth: 0` is required so Guard can compare against the base branch.

### Markdown report as PR comment

```yaml
- name: Run Guard scan
  run: php artisan guard:scan --changed --base=origin/main --format=md --output=guard-report.md --severity=high
  continue-on-error: true

- name: Comment on PR
  if: always()
  uses: marocchino/sticky-pull-request-comment@v2
  with:
    path: guard-report.md
```

## License

MIT
