# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

IntentPHP Guard is a Laravel-native CLI security scanner that detects authorization and data handling vulnerabilities in Laravel applications. It's a composer library (`intentphp/guard`) targeting Laravel 10/11/12 on PHP 8.2+.

It scans for three categories of security issues:
- **Route Authorization** — routes missing authentication/authorization middleware or gate checks
- **Dangerous Query Input** — user input flowing directly into query builders (SQL injection risk)
- **Mass Assignment** — unsafe model creation/updates with unvalidated request data

## Common Commands

```bash
# Run all tests
vendor/bin/phpunit --colors=always

# Run a single test file
vendor/bin/phpunit tests/Unit/ScanCacheTest.php

# Run a specific test method
vendor/bin/phpunit --filter test_method_name
```

There are no configured lint or static analysis tools in this project.

CI runs a matrix of PHP 8.2/8.3/8.4 × Laravel 10/11/12 (excluding PHP 8.4 + Laravel 10).

## Architecture

### Core Scanning Pipeline

`GuardScanCommand` is the main entry point. It orchestrates scanning through:

1. **Scanner** (`Scan/Scanner.php`) — holds an array of `CheckInterface` implementations, runs each, merges findings
2. **Checks** (`Checks/`) — strategy pattern implementations:
   - `RouteAuthorizationCheck` — inspects routes via Laravel Router, uses reflection to detect authorize calls
   - `DangerousQueryInputCheck` — regex-scans controller files via Symfony Finder
   - `MassAssignmentCheck` — scans models for unprotected attributes and controllers for unsafe create/update patterns
3. **Finding** (`Scan/Finding.php`) — immutable DTO with `withSuppression()` and `withAiSuggestion()` methods returning new instances
4. **Suppression** — two layers: `BaselineManager` (fingerprint-based, stored in `storage/guard/baseline.json`) and `InlineIgnoreManager` (parses `// guard:ignore <check>` comments)
5. **Reporters** (`Report/`) — strategy pattern: Console, JSON, GitHub Actions annotations, Markdown

### Incremental Scanning

`GitHelper` detects changed files and determines a **route scan mode** (`full`/`filtered`/`skipped`) to optimize scanning. `ScanCache` stores expensive computations (ProjectMap, reflection) in `storage/guard/cache/`, versioned by package version + PHP version + Laravel version + git SHA.

### Patch Generation

`GuardFixCommand` generates patches via two mechanisms:
- **PatchTemplates** (`Patch/Templates/`) — deterministic, template-based fixes per check type
- **AiPatchGenerator** — AI fallback when templates return null, outputs unified diffs or `.md` notes

### AI Integration

`AiClientInterface` with three implementations selected via factory in `GuardServiceProvider`:
- **CliAiClient** — invokes local CLI tools (Claude, Codex) through adapter pattern (`CliAdapterInterface` + `ProcessRunnerInterface`)
- **OpenAiClient** — HTTP calls to OpenAI-compatible APIs with retry logic
- **NullAiClient** — graceful no-op fallback

Auto-selection order when `driver=auto`: CLI available → OpenAI key set → NullAiClient.

### ProjectMap

`Laravel/ProjectMap.php` infers model, policy, and ability names from routes/controllers to enrich findings with context for reporting and AI prompts.

## Code Conventions

- All files use `declare(strict_types=1);`
- Namespace: `IntentPHP\Guard\*` (PSR-4 from `src/`)
- Test namespace: `Tests\Unit\*` (PSR-4 from `tests/`)
- Test methods use `test_` prefix (not `@test` annotation)
- Readonly properties and full type hints throughout
- Immutable data models — modifications return new instances
- Interfaces for all pluggable components (`CheckInterface`, `AiClientInterface`, `PatchTemplateInterface`, `CliAdapterInterface`, `ProcessRunnerInterface`)
- Configuration in `config/guard.php`, published via service provider

## Artisan Commands

| Command | Purpose |
|---------|---------|
| `guard:scan` | Main scanner (options: `--format`, `--severity`, `--ai`, `--baseline`, `--strict`, `--changed`, `--staged`) |
| `guard:baseline` | Create baseline suppression file |
| `guard:fix` | Generate patches (template-based, `--ai` for AI fallback) |
| `guard:apply` | Validate patches via `git apply --check` |
| `guard:test-gen` | Auto-generate feature tests for findings |

---

## Project Direction (Important)

IntentPHP Guard is the **first building block** of a larger IntentPHP ecosystem.

Guard is not just a scanner — it is the safety and analysis layer that will later support:

- intent-driven code generation
- invariant checking
- AI-assisted domain modeling
- safe code transformation via patch proposals

When adding new features, prefer designs that are:

- deterministic first, AI second
- patch/diff based, never auto-modifying user code
- CI-friendly and incremental
- interface-driven and replaceable

Avoid hard-coding Laravel internals where abstraction is reasonable.

---

## Design Constraints (Do Not Break)

- Never automatically modify user source code — only generate diffs or suggestions
- All new behavior must be behind flags or new commands (no breaking default behavior)
- Public CLI command behavior is considered API — avoid breaking changes
- All expensive operations must support caching or incremental mode
- All new logic should be testable without full Laravel boot where possible

---

## AI Usage Rules

When implementing AI-related features:

- AI output is always treated as untrusted input
- Prefer structured formats (JSON with keys) when parsing AI responses
- Never assume AI patch correctness — always validate or mark as proposal
- Never send full repository contents — only minimal context snippets
- AI drivers must have a Null fallback

---

## Next Planned Feature Areas

Near-term roadmap (safe to implement when requested):

1. GitHub Action improvements
   - outputs / summary support
   - PR comment mode
   - optional markdown artifact upload

2. Additional Checks
   - validation coverage gaps
   - missing policy bindings
   - unsafe file upload handling
   - missing rate limits on auth endpoints

3. Developer Experience
   - richer guard:doctor checks
   - config self-validation
   - guard:init setup command

4. Intent Layer (future, experimental)
   - intent spec file format (YAML/JSON)
   - invariant definitions
   - intent → code scaffold generator
   - intent → guard rule generation

Do not implement intent-layer features unless explicitly requested in prompt.

---

## When Unsure

If a requested change:
- touches CLI behavior
- changes report formats
- affects exit codes
- modifies patch generation

→ propose the plan first before coding.

## IMPORTANT
You are contributing to the IntentPHP / Guard project.

[PROJECT_CONTEXT.md]() and [PROJECT_ROADMAP.md]() are authoritative.
Do not expand scope beyond defined phases.
Do not introduce runtime hooks.
All features must be CLI-only, deterministic, additive, CI-safe.

Before proposing architecture, self-check against:
- scope rules
- determinism rules
- backward compatibility rules
- DTO stability rules
