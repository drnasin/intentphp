# IntentPHP — Milestones (Acceptance Criteria)

This document contains the detailed “Definition of Done” specs for Platform Core phases (10–14).
Roadmap ordering lives in `/docs/ai/ROADMAP.md`.

Hard rules (apply to all milestones):
- Never auto-modify user code (preview/diff only; `--write` must be explicit where applicable)
- Deterministic output (CI-safe)
- Stable fingerprints (avoid timestamps/absolute paths; line numbers only if unavoidable)
- Additive + backward compatible (missing intent file → no behavior change)
- AI is optional and must be mockable (not required for core phases)

---

## Table of contents
- [P10 — Drift Engine (Spec ↔ Code)](#p10--drift-engine-spec--code)
- [P11 — Invariant Engine v1](#p11--invariant-engine-v1)
- [P12 — Intent Scaffold Generator (Preview-first)](#p12--intent-scaffold-generator-preview-first)
- [P13 — Spec ↔ Code Mapping Layer](#p13--spec--code-mapping-layer)
- [P14 — Sync Suggestions Engine (Suggestion-only)](#p14--sync-suggestions-engine-suggestion-only)
- [Ticket template (copy/paste)](#ticket-template-copypaste)

---

## P10 — Drift Engine (Spec ↔ Code)

### Goal
Detect divergence between declared Intent Spec and observed project reality, producing drift findings with deterministic fingerprints.

### Scope
- Compare *declared intent* vs *observed* (initially: auth rules, route middleware, model constraints).
- Output: drift findings (e.g. `intent-drift/*` checks or `DRIFT_*` types).
- No auto-fix. Report only.

### Non-goals
- Automatic modifications to spec/code
- Runtime enforcement
- Any AI usage

### Deliverables
- `DriftEngine` (pure + deterministic)
- Drift detectors (by domain)
- Drift findings that:
    - integrate with baseline suppression
    - integrate with incremental scanning
    - maintain stable ordering and stable fingerprints
- CLI behavior: drift checks run only when intent is present (additive)

### Definition of Done
- ✅ If `intent/intent.yaml` does not exist: **no behavior change** (no drift checks executed)
- ✅ If intent exists: drift findings are deterministic (same inputs → same outputs)
- ✅ Fingerprints are stable across machines (no abs paths, no timestamps)
- ✅ Findings integrate with baseline + incremental (same as existing findings)
- ✅ Tests include golden fixtures proving determinism and ordering
- ✅ Drift categories documented and mapped to spec sections

### Acceptance checklist
- [ ] Two drift scenarios implemented end-to-end (at least auth + mass-assignment)
- [ ] Output ordering is stable (sorted)
- [ ] Exit code behavior defined for drift severities
- [ ] Baseline suppression works for drift findings (fingerprints stable)

### Suggested file layout
- `src/Intent/Drift/DriftEngine.php`
- `src/Intent/Drift/DriftDetectorInterface.php`
- `src/Intent/Drift/Detectors/*.php`
- `src/Checks/Intent/IntentDriftCheck.php` (or multiple drift checks)

### Test plan
- Unit: detector input → drift items
- Golden: fixture snapshot → exact findings JSON (stable order)
- Regression: “missing intent” → zero drift checks executed

---

## P11 — Invariant Engine v1

### Goal
Introduce a reusable invariant layer so checks can become thin evaluators and rules can be expressed as constraints (“invariants”) over project context and optional intent config.

### Scope
- Add `Invariant` evaluation model (pure)
- Add `Violation` model (stable fields + fingerprint seeds)
- Add registry + deterministic ordering
- Migrate at least 2 rules to invariants (recommended: auth + mass-assignment)

### Non-goals
- Complex DSL / rule language (v2)
- Runtime hooks or middleware enforcement
- AI usage for core evaluation

### Deliverables
- `Invariant` interface (id, description, evaluate(context, intent?) → violations)
- `Violation` contract (stable target identifiers + semantic keys)
- `InvariantRegistry` (deterministic ordering)
- `InvariantCheck` adapter: violations → findings (fingerprints stable)

### Definition of Done
- ✅ Invariants evaluate deterministically and are testable offline
- ✅ Fingerprints derived from: `(invariant_id + target_id + semantic keys)`
- ✅ At least 2 migrated rules produce parity or improved output compared to prior checks
- ✅ Registry ordering stable and documented
- ✅ Golden tests validate stable output across runs

### Acceptance checklist
- [ ] Same project + same invariants → identical output (golden)
- [ ] Migrated rule output parity verified (before/after)
- [ ] Deterministic ordering: invariants and violations sorted

### Suggested file layout
- `src/Invariant/Invariant.php`
- `src/Invariant/Violation.php`
- `src/Invariant/InvariantRegistry.php`
- `src/Checks/Invariant/*.php`

### Test plan
- Golden: fixture → violations → findings (stable JSON)
- Migration: compare old check vs invariant adapter output

---

## P12 — Intent Scaffold Generator (Preview-first)

### Goal
Generate deterministic scaffolding for intent adoption (starter intent files/sections/templates) with preview-first behavior.

### Scope
- CLI: `guard:intent scaffold` (or `intent:scaffold`)
- Default: preview (stdout diff / file content)
- Write mode: explicit `--write`
- Safe semantics:
    - no overwrite unless `--force`
    - atomic writes

### Non-goals
- Auto-edit existing user files without explicit flags
- AI generator (belongs to AI phases)

### Deliverables
- `ScaffoldGenerator` + deterministic templates
- Normalization always on
- File writing policy:
    - if target exists → abort unless `--force`
    - never silently overwrite
    - atomic write to avoid partial files
- Integration: generated output must validate via `guard:intent validate`

### Definition of Done
- ✅ Default run performs zero filesystem writes
- ✅ `--write` creates expected file(s) only
- ✅ `--force` required for overwrite; behavior documented
- ✅ Output is deterministic (sorted keys, stable comments)
- ✅ Generated spec passes validation command
- ✅ Tests cover preview-only and write behavior

### Acceptance checklist
- [ ] Scaffold works on empty project and existing intent adoption project
- [ ] Preview output stable and diff-friendly
- [ ] Write mode creates same content as preview
- [ ] Validation passes on generated file

### Suggested file layout
- `src/Intent/Scaffold/ScaffoldGenerator.php`
- `src/Intent/Scaffold/Templates/*.stub` (or PHP template classes)
- `src/Console/Commands/IntentScaffoldCommand.php`

### Test plan
- Golden: generator output matches fixture
- FS tests: verify no writes without `--write`; verify atomic write with temp dir

---

## P13 — Spec ↔ Code Mapping Layer

### Goal
Create a stable mapping index between spec rules/sections and code symbols (routes, controllers, models, policies). This becomes the backbone for drift, generator, and sync suggestions.

### Scope
- `MappingIndex` (versioned, serializable, deterministic)
- `MappingBuilder` (build from scan context + intent)
- `MappingResolver` (query API)
- Optional cache file output (but deterministic path handling and schema)

### Non-goals
- Runtime symbol server / persistent daemon
- Complex graph databases or network services

### Deliverables
- Mapping schema v1 (versioned + forward-compatible)
- Builder producing stable ordering and stable identifiers
- Optional CLI: `guard:intent map --dump` (deterministic JSON)
- Drift engine updated to use mapping where applicable

### Definition of Done
- ✅ Mapping stable across machines (no volatile IDs)
- ✅ Schema versioned and documented
- ✅ Drift uses mapping (reduces heuristics where possible)
- ✅ Unit tests for builder/resolver
- ✅ Golden test ensures mapping JSON is deterministic

### Acceptance checklist
- [ ] Mapping includes at minimum: routes + models (and their identifiers)
- [ ] `--dump` output is stable and sorted
- [ ] Same project → identical mapping hash across runs

### Suggested file layout
- `src/Intent/Mapping/MappingIndex.php`
- `src/Intent/Mapping/MappingBuilder.php`
- `src/Intent/Mapping/MappingResolver.php`

### Test plan
- Golden: mapping dump JSON matches fixture
- Regression: no intent file → mapping still builds “observed-only” index

---

## P14 — Sync Suggestions Engine (Suggestion-only)

### Goal
Based on drift + mapping, propose safe sync actions (Spec→Code and Code→Spec) as preview-only diffs/suggestions. Deterministic ordering, optional machine-readable JSON.

### Scope
- Suggestion model:
    - `action_type`
    - `target` (mapping IDs)
    - `patch/diff` (text snippet)
    - `confidence`
    - `rationale`
- CLI: `guard:intent sync --preview` (default)
- Output formats:
    - human-readable preview
    - JSON (stable, sorted)

### Non-goals
- Auto-apply / write changes
- AI-driven reasoning (later phase)
- Modifying baseline/finding channel (suggestions are separate)

### Deliverables
- `SuggestionEngine`
- Renderers:
    - text preview
    - deterministic JSON
- Category allow/deny config (optional)
- At least 2 suggestion types implemented end-to-end:
    1) Propose adding missing intent sections based on observed routes
    2) Propose route middleware changes to match declared policy (preview patch only)

### Definition of Done
- ✅ Default is preview-only (no filesystem writes)
- ✅ Suggestions deterministically ordered
- ✅ Suggestions reference stable mapping IDs (no fragile file/line references)
- ✅ Suggestions do not create findings; they are separate output channel
- ✅ Golden tests prove stable output and ordering

### Acceptance checklist
- [ ] Suggestions reference stable mapping index IDs
- [ ] JSON output stable for CI consumption
- [ ] Two suggestion types implemented and tested
- [ ] No baseline/incremental behavior changes

### Suggested file layout
- `src/Intent/Sync/Suggestion.php`
- `src/Intent/Sync/SuggestionEngine.php`
- `src/Console/Commands/IntentSyncCommand.php`

### Test plan
- Golden: preview output matches fixture
- Determinism: run twice → identical suggestions + order

---

## Ticket template (copy/paste)

## Phase <P10..P14> — <name>

### Input
- Canon docs: /docs/ai/CANON.md, ARCHITECTURE.md, ROADMAP.md, DECISIONS.md, MILESTONES.md
- Constraints: deterministic, CI-safe, stable fingerprints, additive, never auto-modify

### Task
Implement Phase <X> exactly as defined in `/docs/ai/MILESTONES.md` for that phase.

### Output format
1) Plan (steps)
2) Files to add/change
3) Tests to add (unit + golden)
4) Minimal patch/diff per file
5) Notes proving determinism & fingerprint rules

### Acceptance
- Provide ≥2 fixtures/golden tests
- Prove “missing intent file → no behavior change”
- Prove “same inputs → same outputs” (ordering + fingerprints)