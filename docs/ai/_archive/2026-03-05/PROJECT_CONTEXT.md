# ARCHIVED (do not edit)
Superseded by /docs/ai/* canonical docs. Kept for historical reference only.

# IntentPHP — Project Context

## Vision

IntentPHP is an intent-driven PHP/Laravel tooling ecosystem.

Goal:
Move from “write everything manually” → toward “declare intent + invariants → generate + guard code”.

Long-term components:
- intent-driven code generation
- AI-assisted project understanding
- guardrails & runtime/static safety checks
- repo-aware AI tooling

## Current Implemented Package

Package: intentphp/guard

Laravel security scanning CLI tool that provides:

- route authorization coverage checks
- dangerous query input checks
- mass assignment checks
- baseline system
- incremental git-based scanning
- caching
- AI-assisted fix suggestions (CLI or API)
- patch generation
- test generation
- guard:doctor diagnostics command
- GitHub Action (intentphp-guard-action)

## Repositories

Main package:
- drnasin/intentphp
- Composer name: intentphp/guard

GitHub Action:
- drnasin/intentphp-guard-action
- Composite action wrapping `php artisan guard:scan`

## Current Development Phase

Post v1.0.0.
Working on:
- GitHub Action integration
- DX improvements
- foundation for intent-driven tooling

## Design Rules

- Never auto-modify user code — only propose diffs/patches
- Deterministic CLI first, AI optional
- Works without AI
- CI-friendly
- Incremental + cache-aware
- Test-first features

## Next Big Direction

Bridge from:
Guard (analysis + safety)
→ toward
IntentPHP (intent specs + codegen + invariants + sync engine)

## IntentPHP Core — Scope Clarification (v0.1)

IntentPHP Core is a tooling/spec layer that sits ABOVE Laravel applications.

It is NOT a runtime framework and does not replace Laravel.
It defines developer intent and invariants in a machine-readable form
that Guard and future tooling consume.

IntentPHP Core provides:

- canonical Intent Spec (YAML)
- spec loader with includes + normalization
- typed spec model (DTO layer)
- deterministic merge semantics
- strict spec validation
- CLI-only commands for spec lifecycle (init / validate / show)

It must have:

- zero runtime side effects
- no request lifecycle hooks
- no automatic code modification
- no dependency on AI to function
- deterministic CI-safe behavior
- diff-stable normalized output

## Relationship: Guard vs Core

Guard = analysis and safety engine  
IntentPHP Core = intent + invariant specification engine

Flow:

Intent Spec → Core validation → Guard checks → CI results

Guard must work without Intent Spec.
Intent Spec enhances Guard guarantees but is optional.

## Adoption Model

IntentPHP must support progressive adoption:

Stage 1 — Guard only:
composer require intentphp/guard
php artisan guard:scan

Stage 2 — Intent layer:
php artisan guard:intent init
php artisan guard:intent validate

No Intent Spec → system still works.

## Non-Goals (v0.1)

IntentPHP Core v0.1 must NOT introduce:

- a new PHP framework
- runtime enforcement layer
- DI container
- router
- auto code generator
- automatic code rewriting
- runtime policy engine

All features are CLI/build-time only.

## v0.1 Functional Scope

Intent Spec v0.1 includes only:

- manifest + includes + defaults
- auth rules with selectors + requirements
- auth guards/roles/abilities (maps)
- model mass-assignment invariants
- baseline findings with expiry

Deferred to later versions:

- route/resource intent mapping
- request validation intent
- code generation
- sync engine
- repo AI reasoning
- runtime enforcement

## Core Philosophy

Intent first.
Code second.
Automation third.

Never mutate user code automatically.
Always propose — never impose.

## AI Operating Rules for IntentPHP Development

These rules guide AI-assisted design and implementation decisions in this project.

They override generic framework-building or abstraction-heavy recommendations.

### Priority Order for Decisions

When proposing solutions, always prioritize:

1. Determinism over cleverness
2. DX simplicity over abstraction purity
3. CLI/build-time behavior over runtime behavior
4. Explicit configuration over implicit magic
5. Small composable components over large systems
6. Backward compatibility over architectural perfection

### Scope Discipline

AI must NOT expand scope beyond the declared v0.x scope.

If a feature belongs to:
- code generation
- runtime enforcement
- sync engines
- AI repo reasoning
- framework-like behavior

→ mark it as **vNext** and do not include it in current implementation plans.

Avoid speculative architecture for future phases unless explicitly requested.

### Runtime Safety Rule

IntentPHP Core and Guard must remain:

- CLI-only
- build-time only
- CI-safe
- runtime-neutral

AI must not propose:
- middleware injection
- request lifecycle hooks
- automatic policy wiring
- runtime mutation of behavior

### Code Modification Rule

User code must never be automatically modified.

Allowed:
- diffs
- patches
- suggestions
- generated examples

Not allowed:
- auto-applied edits
- silent rewrites
- hidden refactors

### Spec & Schema Design Rules

When extending Intent Spec:

- prefer additive fields
- avoid breaking schema changes
- require explicit IDs for mergeable structures
- design for diff readability
- design for deterministic normalization
- avoid deep nesting when a flat structure works

### DTO & Structure Rules

Introduce a dedicated DTO class only when:

- it contains logic, OR
- it is reused across subsystems, OR
- validation depends on its behavior

Otherwise prefer typed maps inside parent DTOs.

Avoid DTO explosion.

### Validation Philosophy

Loader:
- lenient parsing
- never crash on missing optional fields
- collect warnings

Validator:
- strict semantic checks
- explicit errors
- no silent acceptance of invalid intent

Never hide semantic errors behind defaults.

### CLI UX Rules

All CLI commands must:

- have deterministic output
- support CI usage
- return correct exit codes
- avoid interactive prompts unless explicitly requested
- avoid color-only signaling (must be machine-readable)

### Testing Rules

New components must include:

- unit tests first
- pure logic isolation where possible
- no Laravel runtime dependency unless necessary
- snapshot-safe normalized outputs where applicable

### AI Suggestion Style

When proposing changes:

- keep patches minimal
- avoid large rewrites
- explain tradeoffs briefly
- mark optional improvements clearly
- separate v0.x vs vNext ideas explicitly

## Architecture Stability Rules

These rules protect architectural consistency and prevent unnecessary churn
in IntentPHP Core and Guard.

AI must follow these rules when proposing refactors or structural changes.

### Stability Levels

Components are classified by stability level:

Stable:
- Intent Spec schema (released versions)
- DTO public structure
- CLI command names and arguments
- Finding ID formats
- Baseline fingerprint semantics
- Merge semantics

Evolving:
- Internal loader implementation
- Validator rule set (additive only)
- Normalization steps (if backward compatible)

Experimental:
- Future spec sections marked vNext
- Optional extensions behind flags

AI must not propose breaking changes to Stable components.

### Spec Versioning Rules

Intent Spec is versioned.

Rules:

- Schema changes must be additive within the same version
- Field removal requires spec version bump
- Field semantic change requires version bump
- Validator may add new checks, but not reinterpret old fields

If a change would alter meaning → require new spec version.

### Merge Semantics Stability

Merge behavior is part of the contract.

AI must not change:

- map merge = override by later file
- id-based structures = duplicate ID is error (or declared policy)
- normalization ordering rules

Any merge semantic change requires explicit version note.

### DTO Contract Stability

DTO public constructors / fromArray / toArray structure are treated as stable API.

AI may:

- add new optional fields
- add helper methods
- add validators

AI must not:

- rename existing fields
- change array shapes
- change required keys
- reorder canonical output keys

Without version bump + migration note.

### CLI Contract Stability

CLI behavior is part of user contract.

Do not change without strong reason:

- command names
- exit codes
- default output format
- non-interactive behavior

New flags are allowed.
Behavioral changes require explicit note.

### Guard–Core Boundary Rule

Guard and Intent Core must remain loosely coupled.

AI must not:

- embed Guard logic into Core DTOs
- make Core depend on Guard scanners
- create circular dependencies

Core defines intent.
Guard analyzes code.

### Refactor Threshold Rule

AI should only propose structural refactors when at least one is true:

- removes duplicated logic across modules
- reduces spec ambiguity
- improves determinism
- improves testability
- fixes contract inconsistency

Do NOT refactor for style or preference alone.

### vNext Proposal Rule

When AI sees a good but out-of-scope idea:

- label it clearly as vNext
- do not mix it into current implementation
- do not expand current DTO/schema for it
- keep current scope clean

### Simplicity Bias Rule

When two designs are valid:

Choose the one that is:

- easier to explain
- easier to test
- easier to diff
- easier to validate
- easier to remove later

Prefer boring over clever.

---

# Next Phases — Execution Roadmap (Guard → Intent Platform)

This section defines the next implementation phases after Intent Spec v0.1 and Intent-Aware Guard Checks.

Purpose:
Allow AI contributors to implement next phases WITHOUT redesigning architecture and WITHOUT scope drift.

Rules:
- All phases are CLI/build-time only
- No runtime hooks
- No automatic code modification
- Deterministic outputs required
- Backward compatibility required

---

## Phase 10 — Drift Detection Engine

### Goal

Detect drift between:

Declared intent (Intent Spec)
vs
Observed reality (scan results, project map)

Drift means:
- intent rule exists but code does not satisfy it
- code behavior exists but intent does not declare it

---

### Implementation Model

Add new check family:

Check name prefix:
intent-drift-*

Reuse:
- IntentSpec DTOs
- ProjectMap
- existing scan outputs
- Fingerprint system

Do NOT:
- re-scan files again
- duplicate existing checks

Instead:
compare spec model vs discovered model.

---

### Example Drift Types

Auth drift:
- intent declares rule → no matching route exists
- intent declares guard → no route uses that guard

Model drift:
- intent declares model invariant → model missing
- model exists → not declared in intent

---

### Output Rules

- Emit Findings (not warnings)
- Deterministic fingerprints:
  intent:drift:{type}:{stable_id}

- Severity:
  default MEDIUM (configurable later)

---

### Tests Required

- same spec + same code → same drift findings
- missing spec → no drift checks run
- fingerprint stability tests

---

## Phase 11 — Invariant Engine

### Goal

Generalize from:
checks
to
invariants

Invariant = rule that must always hold.

Examples:
- “All /api routes must be authenticated”
- “User model must forbid is_admin mass assignment”

---

### Implementation

Introduce:

InvariantInterface

InvariantEvaluator

Input sources:
- IntentSpec
- ProjectMap
- Findings (optional)

Checks become:
Invariant adapters.

---

### Constraints

Do NOT:
- create runtime enforcement
- attach to request lifecycle

Only evaluate at scan time.

---

### Output

Invariant violations → Findings

Check name:
invariant-*

Fingerprint:
invariant:{id}:{scope}

---

## Phase 12 — Intent-Based Scaffold Generator

### Goal

Generate missing artifacts from intent spec.

Examples:
- missing policy class
- missing FormRequest
- missing $fillable skeleton

---

### Implementation Rules

CLI only:
guard:generate

Modes:
- preview (default)
- --write (explicit only)

Output:
- diff-style
- never overwrite
- deterministic templates

---

### Safety Rules

Generator MUST:

- never modify existing files
- only create new files
- fail if file exists (unless --force)

---

## Phase 13 — Spec ↔ Code Mapping Layer

### Goal

Create stable mapping between:

intent rules
and
code symbols

---

### Implementation

Build mapping index:

rule_id → file → symbol → fingerprint

Reuse:
- ProjectMap
- Route map
- Model map

Store:
in-memory per scan

Optional later:
cacheable snapshot

---

### Used By

- drift engine
- generator
- sync suggestions

---

## Phase 14 — Sync Suggestion Engine

### Goal

Suggest safe sync actions:

Spec → Code
Code → Spec

Examples:
- “Add this route to intent spec”
- “Add this field to forbid list”

---

### Implementation

New CLI:
guard:intent:sync

Output:
- suggestions only
- diff text
- machine-readable JSON mode

---

### Hard Rules

Sync engine MUST NOT:

- auto apply changes
- rewrite files
- modify spec automatically

Only suggestions.

---

## Phase Boundary Rules

AI must NOT merge phases.

Each phase must:
- compile
- pass tests
- preserve behavior without intent spec
- maintain deterministic fingerprints

---

## Determinism Checklist (Always Apply)

When implementing any new phase:

- sort arrays
- canonicalize IDs
- avoid timestamps
- avoid line numbers in fingerprints
- avoid file absolute paths
- ensure stable iteration order
- add fingerprint tests

---

## Scope Guardrail

If a feature involves:

- runtime middleware
- request interception
- automatic policy enforcement
- framework behavior
- auto code rewriting

→ mark as vNext 

→ DO NOT implement in current phases.

---

## IntentPHP is built bottom-up

- Guard → Spec → Mapping → Drift → Sync → AI.
- AI is the last layer, not the first.
  
## Guard scan engine
  → Intent Spec
  → Intent-aware checks
  → Drift
  → Mapping
  → Generator
  → Sync
  → AI intent authoring
  → AI project brain

---

# Next Phases — Execution Roadmap (Guard → Intent Platform)

This section defines the next implementation phases after Intent Spec v0.1 and Intent-Aware Guard Checks.

Purpose:
Allow AI contributors to implement next phases WITHOUT redesigning architecture and WITHOUT scope drift.

Rules:
- All phases are CLI/build-time only
- No runtime hooks
- No automatic code modification
- Deterministic outputs required
- Backward compatibility required

---

## Phase 10 — Drift Detection Engine

### Goal

Detect drift between:

Declared intent (Intent Spec)
vs
Observed reality (scan results, project map)

Drift means:
- intent rule exists but code does not satisfy it
- code behavior exists but intent does not declare it

---

### Implementation Model

Add new check family:

Check name prefix:
intent-drift-*

Reuse:
- IntentSpec DTOs
- ProjectMap
- existing scan outputs
- Fingerprint system

Do NOT:
- re-scan files again
- duplicate existing checks

Instead:
compare spec model vs discovered model.

---

### Example Drift Types

Auth drift:
- intent declares rule → no matching route exists
- intent declares guard → no route uses that guard

Model drift:
- intent declares model invariant → model missing
- model exists → not declared in intent

---

### Output Rules

- Emit Findings (not warnings)
- Deterministic fingerprints:
  intent:drift:{type}:{stable_id}

- Severity:
  default MEDIUM (configurable later)

---

### Tests Required

- same spec + same code → same drift findings
- missing spec → no drift checks run
- fingerprint stability tests

---

## Phase 11 — Invariant Engine

### Goal

Generalize from:
checks
to
invariants

Invariant = rule that must always hold.

Examples:
- “All /api routes must be authenticated”
- “User model must forbid is_admin mass assignment”

---

### Implementation

Introduce:

InvariantInterface

InvariantEvaluator

Input sources:
- IntentSpec
- ProjectMap
- Findings (optional)

Checks become:
Invariant adapters.

---

### Constraints

Do NOT:
- create runtime enforcement
- attach to request lifecycle

Only evaluate at scan time.

---

### Output

Invariant violations → Findings

Check name:
invariant-*

Fingerprint:
invariant:{id}:{scope}

---

## Phase 12 — Intent-Based Scaffold Generator

### Goal

Generate missing artifacts from intent spec.

Examples:
- missing policy class
- missing FormRequest
- missing $fillable skeleton

---

### Implementation Rules

CLI only:
guard:generate

Modes:
- preview (default)
- --write (explicit only)

Output:
- diff-style
- never overwrite
- deterministic templates

---

### Safety Rules

Generator MUST:

- never modify existing files
- only create new files
- fail if file exists (unless --force)

---

## Phase 13 — Spec ↔ Code Mapping Layer

### Goal

Create stable mapping between:

intent rules
and
code symbols

---

### Implementation

Build mapping index:

rule_id → file → symbol → fingerprint

Reuse:
- ProjectMap
- Route map
- Model map

Store:
in-memory per scan

Optional later:
cacheable snapshot

---

### Used By

- drift engine
- generator
- sync suggestions

---

## Phase 14 — Sync Suggestion Engine

### Goal

Suggest safe sync actions:

Spec → Code
Code → Spec

Examples:
- “Add this route to intent spec”
- “Add this field to forbid list”

---

### Implementation

New CLI:
guard:intent:sync

Output:
- suggestions only
- diff text
- machine-readable JSON mode

---

### Hard Rules

Sync engine MUST NOT:

- auto apply changes
- rewrite files
- modify spec automatically

Only suggestions.

---

## Phase Boundary Rules

AI must NOT merge phases.

Each phase must:
- compile
- pass tests
- preserve behavior without intent spec
- maintain deterministic fingerprints

---

## Determinism Checklist (Always Apply)

When implementing any new phase:

- sort arrays
- canonicalize IDs
- avoid timestamps
- avoid line numbers in fingerprints
- avoid file absolute paths
- ensure stable iteration order
- add fingerprint tests

---

## Scope Guardrail

If a feature involves:

- runtime middleware
- request interception
- automatic policy enforcement
- framework behavior
- auto code rewriting

→ mark as vNext
→ DO NOT implement in current phases.
