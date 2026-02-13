# IntentPHP Roadmap

IntentPHP is an intent-driven PHP/Laravel tooling platform.

Goal: move from code-only analysis → toward declared intent + invariants + drift detection + safe generation.

This roadmap is organized in phases. Each phase is designed to be independently shippable, CI-safe, and non-breaking.

---

# ✅ Completed — Guard Engine Era

## Phase 1–7 — Guard Core
Foundation of the Guard static analysis engine.

Delivered:
- route authorization checks
- mass assignment checks
- dangerous input checks
- baseline system
- deterministic fingerprints
- incremental scanning
- CI-safe CLI behavior
- patch + test suggestion support

Constraints:
- no auto-modification of user code
- deterministic output
- baseline-stable fingerprints

---

## Phase 8 — Intent Spec v0.1
Introduced declarative intent spec.

Delivered:
- intent YAML format
- DTO layer
- loader + validator
- include + merge support
- `guard:intent` CLI command
- spec scaffold + validate + show

Constraints:
- spec optional
- validation errors never throw — only report
- spec versioned

---

## Phase 9 — Intent-Aware Checks
Guard can validate declared intent vs observed code.

Delivered:
- intent-auth check
- intent-mass-assignment check
- RouteProtectionDetector
- IntentContext
- IntentEnricher
- deterministic intent fingerprints
- additive check model

Constraints:
- no spec → identical behavior as before
- intent checks additive + suppressible
- no duplicate controller scanning
- stable grouping + dedup logic

---

# 🚧 Next — Intent Platform Core

## Phase 10 — Spec Drift Engine
Detect spec ↔ code divergence.

Goal:
Detect when declared intent no longer matches reality.

Implement:
- DriftCheck engine
- spec vs route/model diff logic
- drift finding type
- deterministic drift fingerprints

Must:
- be deterministic
- reuse scanner model
- never auto-fix
- CI-safe

Avoid:
- heuristics that depend on runtime state
- non-repeatable ordering

---

## Phase 11 — Invariant Engine v1
Reusable invariant rule system.

Goal:
Allow reusable rule definitions across checks.

Implement:
- invariant interface
- invariant registry
- invariant evaluation pipeline
- invariant fingerprint support

Examples:
- route must have middleware
- model must define property
- config must define key

Must:
- pure evaluation
- deterministic inputs
- no side effects

---

## Phase 12 — Intent Scaffold Generator
Generate safe starter code from intent spec.

Goal:
Intent → starter artifacts.

Generate:
- policies
- middleware stubs
- model fillable arrays
- route guards

Rules:
- generate only missing artifacts
- never overwrite existing files
- show diff preview
- require explicit confirmation

Must:
- be idempotent
- produce same output from same spec

---

## Phase 13 — Spec ↔ Code Mapping Layer
Track which spec rules map to which code elements.

Goal:
Create a stable mapping index.

Implement:
- SpecCodeMap
- rule_id → file → symbol mapping
- stored cache index
- mapping fingerprints

Used by:
- drift engine
- invariant engine
- generators

Must:
- be cacheable
- deterministic
- file-hash based

---

## Phase 14 — Intent Sync Engine v1
Safe spec/code synchronization suggestions.

Goal:
Suggest sync actions — never auto-apply.

Suggest:
- spec updates
- missing rules
- stale rules
- orphaned mappings

Must:
- produce suggestions only
- diff-style output
- CI-safe
- deterministic ordering

Result:
**Intent Platform Core complete**

---

# 🔮 After Core — Ecosystem & Intelligence

## Phase 15 — IDE Integration
Developer experience layer.

Ideas:
- PhpStorm plugin
- inline intent warnings
- quick navigation to spec rules
- intent rule references

---

## Phase 16 — Test Intelligence
Intent-aware testing assistance.

Ideas:
- invariant → test templates
- missing coverage hints
- security test suggestions

---

## Phase 17 — Policy Intelligence
Advanced auth analysis.

Ideas:
- policy graph analysis
- role drift detection
- privilege escalation warnings

---

## Phase 18 — AI Assist Layer (Optional)
AI-assisted intent workflows.

Ideas:
- spec suggestion
- rule refinement
- invariant proposals

Rules:
- always optional
- never required for core behavior
- deterministic fallback without AI

---

# Core Principles (All Phases)

- Never auto-modify user code
- Deterministic output
- CI-safe behavior
- Stable fingerprints
- Additive features
- Backward compatible by default
- AI optional, never required

---

# Status Legend

- ✅ Complete
- 🚧 In progress
- 🔮 Planned

---

IntentPHP is evolving from a guard tool → intent platform → developer safety layer.
