# IntentPHP — CANON (Single Source of Truth)

## Vision
IntentPHP is an intent-driven PHP/Laravel tooling ecosystem.
Goal: move from “write everything manually” → toward “declare intent + invariants → generate + guard code”.

IntentPHP is NOT a runtime framework and does not replace Laravel.
It is a CLI/build-time spec + tooling layer above Laravel apps.

## Current implemented package (today)
Package: intentphp/guard (Laravel security scanning CLI tool)

Capabilities (guard engine era):
- deterministic static analysis pipeline
- findings with stable fingerprints + baseline suppression
- incremental scanning + caching
- checks: route authorization, mass-assignment, dangerous inputs, etc.
- optional AI-assisted fix suggestions (proposal-only; never auto-modify)

## Core philosophy / hard rules (project contract)
1) Never auto-modify user code (only diffs/patches/suggestions)
2) Deterministic output only (CI-safe)
3) Stable fingerprints (no timestamps, no absolute paths, avoid line numbers)
4) Additive checks/features only; backward compatibility first
5) Intent Spec optional: missing intent file must not change Guard behavior
6) DTO/spec layer must be framework-free (no container/runtime coupling)
7) AI optional, never required (offline/testable without network)

## Intent Platform layers (mental model)
Guard pipeline:
1. Load project context
2. Run checks
3. Produce deterministic findings + fingerprints
4. Optional enrichment (deterministic)
5. Baseline suppression + incremental filtering
6. Render output + CI exit codes

Intent Platform adds an optional declarative layer:
- intent/intent.yaml (versioned)
- validated + normalized via Intent Core
- used by intent-aware checks (and later drift/mapping/generator/sync)

## Relationship: Guard vs Intent Core
- Guard = analysis + safety engine
- Intent Core = spec lifecycle (load/merge/normalize/validate)

Flow:
Intent Spec → Core validation → Guard checks → findings → baseline/CI

## Non-goals (until explicitly promoted)
- runtime enforcement layer
- middleware/request lifecycle hooks
- automatic code rewriting
- repo self-editing agents
- framework replacement