# Architecture — IntentPHP Guard + Intent Platform

This document explains how the Guard engine works today and how the Intent Platform layers build on top of it.

Audience: contributors, maintainers, and anyone extending checks, spec support, or output formats.

---

## 1) Big Picture

Guard is a **deterministic static analysis tool** for Laravel/PHP projects.

It runs a pipeline:
1. Load project context (routes, files, symbols, config)
2. Run a set of checks
3. Produce findings (with stable fingerprints)
4. Optionally enrich findings (post-processing)
5. Apply baseline suppression and filters (incremental scan, etc.)
6. Render output and exit with CI-safe codes

The Intent Platform adds an optional declarative layer (`intent/intent.yaml`) and tools to validate and compare intent vs reality.

Key principle:
- If `intent/intent.yaml` is missing → Guard behaves exactly as before (no behavior drift).

---

## 2) Core Data Structures

### Finding
A finding is a structured report item emitted by checks.

Typical fields:
- `check` (string): check name, e.g. `route-authorization`, `mass-assignment`, `intent-auth`
- `severity` (enum): `LOW|MEDIUM|HIGH`
- `message` (string): human explanation
- `context` (array): machine-readable details for rendering and enrichment
- `fingerprint` (string): stable identifier used for baseline suppression

Constraints:
- findings must be deterministic
- context should be stable (sorted arrays, stable keys, avoid line numbers unless required)

---

### Fingerprint
Fingerprints are the backbone of baseline stability.

Rules:
- deterministic across runs
- avoid volatile inputs (line numbers, file absolute paths, timestamps)
- sort lists (methods, rule IDs, etc.)
- prefer semantic identifiers (route name/URI + methods; model FQCN + pattern labels)

---

### ProjectMap / Enrichment
Guard may enrich findings after scanning (e.g., adding `model_fqcn` from symbol resolution).

Enrichment rules:
- enrichment should not create new findings
- enrichment must be deterministic
- enrichment should never change the check’s core detection logic

---

## 3) Scan Pipeline

### GuardScanCommand (CLI entry)
Responsibilities:
- parse CLI options (mode, output format, incremental options)
- build scanner
- load optional intent context (if present)
- run scan
- run enrichers
- apply incremental filtering and baseline suppression
- render output and set exit code

Key constraints:
- do not throw on intent parsing/validation → print errors and exit non-zero
- do not change behavior when intent file is missing

---

### Scanner
The scanner orchestrates:
- project discovery / parsing (routes, controllers, models, etc.)
- execution of checks
- aggregation of findings

Checks should be stateless except for dependencies injected at construction time.

---

## 4) Checks Model

### CheckInterface
Each check returns `Finding[]`.

Rules:
- no side effects
- no code modifications
- deterministic ordering
- stable context (sort methods, routes, rule IDs, etc.)

---

### Existing checks (examples)
- RouteAuthorizationCheck: config-driven route auth expectations
- MassAssignmentCheck: controller → model mass assignment patterns
- (others): unsafe inputs, insecure patterns, etc.

---

## 5) Intent Platform (Phases 8–9)

Intent is optional and additive.

### Intent Spec
Location:
- `intent/intent.yaml` at project root

Support:
- includes + deep merge
- validation via DTO schema

CLI:
- `guard:intent init` (scaffold)
- `guard:intent validate`
- `guard:intent show` (resolved spec summary)

---

### IntentContext
`IntentContext` holds:
- validated `IntentSpec`
- mutable warnings collector

Rules:
- `tryLoad()` never throws
- warnings are printable diagnostics, not findings
- warnings are deduplicated
- IntentContext is instantiated per scan run

---

### Intent Checks (Phase 9)

#### intent-auth
Purpose:
Validate route middleware vs declared `auth.rules` in intent spec.

Key mechanics:
- iterate routes
- normalize methods (exclude HEAD; sort)
- match rules to routes (selectors + optional method constraints)
- deduplicate overlapping rules by canonicalized requirement
- emit findings for mismatches:
    - authenticated required but missing auth middleware (HIGH)
    - guard required but missing correct guard middleware (HIGH)
    - public declared and route unprotected (MEDIUM, with hint)

Design constraints:
- one finding per route + requirement violation (dedup identical requirements)
- include `matched_rule_ids[]` in context
- fingerprint uses first sorted rule ID + route identifier + sorted methods

---

#### intent-mass-assignment
Purpose:
Validate declared model constraints vs actual model definitions.

Scope:
- model compliance only (no controller scanning)

Detect:
- explicit_allowlist but missing `$fillable` (HIGH)
- forbidden attribute appears in `$fillable` (HIGH)
- guarded mode but `$guarded = []` (HIGH)

Warnings:
- model file not found → warning only, no finding

Fingerprint:
- `intent:mass-assignment:{model_fqcn}:{pattern_label}`
- pattern labels are deterministic (no line numbers):
    - `missing_fillable`
    - `forbidden_in_fillable:{field}`
    - `guarded_empty`

---

### RouteProtectionDetector
Shared middleware detection extracted from RouteAuthorizationCheck.

Purpose:
- standardize middleware collection logic
- avoid duplicated auth detection logic

Rules:
- stateless
- deterministic output ordering
- injected into checks (RouteAuthorizationCheck, IntentAuthCheck)

---

### IntentEnricher
Post-scan enrichment for existing mass-assignment findings:
- merges `intent_mode`, `intent_allow`, `intent_forbid` into finding context

Matching strategy:
1. Prefer `model_fqcn` match
2. Fallback to short name only if unique among spec models

Constraints:
- enrichment only (no new findings)
- deterministic behavior
- skip ambiguous matches

---

## 6) Determinism Checklist (Must Follow)

Whenever adding a check or changing output:

- [ ] Sort route methods (exclude HEAD)
- [ ] Sort rule IDs used in context
- [ ] Use canonical requirement keys (stable ordering, stable arrays)
- [ ] Avoid file absolute paths in fingerprints
- [ ] Avoid line numbers in fingerprints unless unavoidable
- [ ] Ensure stable iteration order (routes, files, symbols)
- [ ] Make warnings deterministic and deduplicated
- [ ] Ensure baseline suppression works across machines

---

## 7) Exit Codes & CI Behavior

General rules:
- Scan returns non-zero when findings exist (depending on configured thresholds)
- Intent spec parse/validation errors:
    - print errors
    - exit non-zero
    - never throw uncaught exceptions

Warnings:
- do not affect exit code
- printed once per scan (after checks run and warnings collector is complete)

---

## 8) How Future Phases Fit (10–14)

The next platform layers build on top of:
- IntentSpec + IntentContext
- CheckInterface + Finding + Fingerprint
- ProjectMap + enrichment pipeline

### Phase 10 — Drift Engine
New check(s) compare:
- declared intent vs observed reality
  Produces drift findings with deterministic fingerprints.

### Phase 11 — Invariant Engine
Introduce invariant registry and evaluation pipeline.
Invariants can be evaluated from:
- code-only inputs
- intent-only inputs
- combined intent + code

### Phase 12 — Generator (Scaffold)
Generate missing artifacts based on intent spec.
Rules:
- never overwrite
- always preview diff
- idempotent output

### Phase 13 — Spec↔Code Mapping
Stable mapping index:
- rule_id → file/symbol
  Used by drift + generator.

### Phase 14 — Sync Suggestions
Suggest safe sync actions (spec updates or code actions).
Rules:
- suggestions only
- no auto-apply

---

## 9) Contribution Guidelines (Engineering Rules)

When implementing a new phase:
1. Add/extend DTOs only if necessary
2. Prefer reuse of existing scanners/parsers
3. Add tests first for determinism and fingerprints
4. Ensure backward compatibility (intent missing → no change)
5. Keep checks side-effect free
6. Never require runtime execution of the app

---

## 10) Glossary

- **Intent**: Declarative security spec describing expected properties of routes/models/etc.
- **Reality**: Observed code state discovered by scanning
- **Drift**: Spec != reality (either direction)
- **Finding**: Reportable issue from a check
- **Warning**: Non-fatal diagnostic about scan/spec state
- **Fingerprint**: Stable identifier for baseline suppression
- **Enrichment**: Post-processing that adds context but does not create new findings

---
