# ARCHIVED (do not edit)
Superseded by /docs/ai/* canonical docs. Kept for historical reference only.

# IntentPHP — Next Phases Roadmap (Post Guard v1.1)

This section defines the next development phases after Guard v1.1.  
These phases move the project from:

Guard (security scanning)
→ Intent-aware tooling
→ Intent-driven generation & invariant enforcement

Each phase includes:
- goal
- scope
- implementation rules
- design constraints
- acceptance criteria
- self-review checklist

Claude should follow these rules strictly and self-review against them before proposing implementation.

---

# Phase 10 — Intent Spec v0.2 (Richer Intent Model)

## Goal

Extend the Intent Spec so it can express more than auth + mass-assignment:
- domain invariants
- route/data coupling
- role/ability expectations
- data flow expectations

Guard remains a scanner — no code generation yet.

## Scope

Extend spec DTO + validator to support:

- route → model binding expectations
- role/ability requirements
- required validation rules (declared intent vs controller validation)
- forbidden field flows (e.g. input must not map to column X)

## Implementation Rules

- Spec remains YAML-first
- Backward compatible with v0.1 spec
- Versioned (`version: "0.2"`)
- Loader must support version switch
- Validator must produce structured errors
- Never auto-upgrade spec silently

## Constraints

- No runtime coupling
- No Laravel container dependency in DTO layer
- DTOs must be pure value objects
- Validator must be deterministic

## Acceptance Criteria

- guard:intent validate handles v0.1 and v0.2
- Version mismatch produces clear error
- Spec parsing remains exception-safe

## Self-Review Checklist

- No breaking change to v0.1 spec
- DTO layer has zero framework calls
- Validation errors are deterministic
- Version branching is explicit

---

# Phase 11 — Intent ↔ Code Drift Detection

## Goal

Detect when declared intent and actual code diverge over time.

This is **drift detection**, not generation.

## Scope

Add drift checks:

- intent rule exists but no matching code artifact
- route declared protected but route removed
- model declared but class missing
- invariant declared but not enforced

## Implementation Rules

- Drift findings use separate check names:
    - intent-drift-route
    - intent-drift-model
    - intent-drift-invariant

- Drift is MEDIUM severity by default
- Drift checks never block parsing

## Constraints

- Must reuse existing fingerprint system
- Deterministic identifiers only
- No file-content hashes in fingerprint
- No timestamps in identifiers

## Acceptance Criteria

- Removing a spec-declared model triggers drift finding
- Removing matching routes triggers drift finding
- Baseline suppression works normally

## Self-Review Checklist

- Drift checks are additive
- No duplicate findings with existing checks
- Fingerprints deterministic
- Works with incremental scan

---

# Phase 12 — Intent-Driven Code Generation (Scaffold Only)

## Goal

Introduce **optional** code generation from intent spec.

Generation is:
- opt-in
- scaffold-only
- never auto-applied
- diff-based

## Scope

Add generator commands:

- guard:generate policy
- guard:generate request
- guard:generate rule
- guard:generate test

Generate:
- policy skeletons
- request validation skeletons
- test skeletons

## Implementation Rules

- Generation produces files OR diffs — never silent edits
- Always preview-first mode
- `--write` flag required to write
- Default = dry-run

## Constraints

- Never overwrite existing files without --force
- Generated code must be minimal
- No AI dependency required
- Templates deterministic

## Acceptance Criteria

- Dry-run shows diff output
- --write writes files
- --force required to overwrite
- Generated code passes Pint/formatting

## Self-Review Checklist

- No silent writes
- No mutation without flags
- Diff mode default
- Deterministic templates

---

# Phase 13 — Invariant Engine (Static Enforcement)

## Goal

Support declared invariants and statically verify enforcement.

Examples:

- “Order must belong to current user”
- “Only admin can update X”
- “Field Y must be validated before persistence”

## Scope

Add invariant spec section + invariant checks.

## Implementation Rules

- Pattern-based detection
- No AST mutation
- Read-only scanning
- Findings include invariant_id

## Constraints

- Deterministic pattern detection
- No heuristic randomness
- No runtime simulation

## Acceptance Criteria

- Invariant declared → missing enforcement produces finding
- Fingerprints stable
- Baseline works

## Self-Review Checklist

- Pattern matching deterministic
- No false-positive explosion
- Rule IDs included in context

---

# Phase 14 — Intent Sync Engine (Spec ↔ Code Map)

## Goal

Build internal mapping layer between:

intent spec ↔ routes ↔ models ↔ controllers ↔ policies

This enables:
- richer drift checks
- future generation
- impact analysis

## Scope

Add IntentProjectMap builder.

## Implementation Rules

- Read-only analysis
- Cached per scan
- Deterministic mapping keys

## Constraints

- No container resolution required
- No runtime execution
- No reflection side effects

## Acceptance Criteria

- Map build time bounded
- Cached between checks
- No behavioral changes to existing checks

## Self-Review Checklist

- Map is immutable after build
- Keys deterministic
- No lazy mutation

---

# Phase AI-1 — Natural Language → Intent Spec Generator

## Goal

Enable developers to generate an initial Intent Spec from natural language descriptions using an AI-assisted CLI command.

This removes the need to memorize Intent Spec syntax and reduces onboarding friction.

AI is used only for **draft spec generation**, never for automatic code generation or runtime behavior.

All output is review-first and developer-controlled.

---

## Scope

This phase introduces:

- AI-assisted Intent Spec draft generation
- CLI command for spec generation from prompt or file
- deterministic normalization after generation
- strict AI sandbox boundaries
- validator pipeline integration

This phase does NOT introduce:

- runtime AI behavior
- automatic code generation
- automatic code modification
- background agents
- repo self-editing
- sync engine automation

AI is spec-authoring assistance only.

---

## CLI UX

New command:

```bash
php artisan intent:generate
```

##Input Modes

Interactive mode:
- asks for project type
- asks for domain description
- asks for entities 
- asks for auth model 
- asks for special invariants

Prompt mode:
```bash
php artisan intent:generate --prompt="E-commerce with products, variants, orders, checkout"
```

File mode:
```bash
php artisan intent:generate --file=domain.txt
```
## Output Rules

Command produces:

- intent/intent.generated.yaml
- never overwrites intent.yaml
- prints summary of generated sections
- automatically runs spec validator
- prints warnings and unresolved fields
- Generated file must include header:

```yaml
# GENERATED — review before use
# source: AI draft
```

Developer must manually review and adopt.
No automatic activation.

## Determinism Rules

After AI generation, output must pass through:

- Spec loader
- Spec normalizer
- canonical ordering
- semantic validator
- Final written spec must be normalized.
- 
Guarantee:

Same prompt + same generator version + same model version
→ same normalized spec output.

Normalization is mandatory.

## Security Restrictions

AI generator must be sandboxed.

AI is NOT allowed to:

- read repository automatically 
- scan project files 
- access secrets 
- read .env 
- execute code 
- modify code 
- modify spec after generation

Only allowed inputs:

- user prompt 
- user-provided file 
- explicit CLI flags

No implicit repo context.

## Data Redaction Rules

Before sending prompt to AI:

- strip credentials 
- strip tokens 
- strip connection strings 
- redact emails (configurable)

CLI flag:
```bash
--no-redact
```
must be explicit opt-in.
Default = redacted.

## AI Sandbox Rules

AI operates in proposal-only mode.

Allowed output:

- intent YAML 
- YAML comments 
- TODO markers 

Not allowed output:

- PHP code 
- patches 
- migrations 
- shell commands 
- executable instructions 
- Post-filter rejects invalid output types.

# Validation Pipeline

Generation flow:

AI → raw spec
→ schema validation
→ semantic validation
→ normalization
→ final generated spec file

If validation fails:
- file is still written 
- errors printed 
- exit code non-zero 
- developer fixes manually 
- No silent fixes allowed. 

## Test Requirements

Must include:

- prompt → spec generation tests (stubbed AI provider)
- normalization determinism tests 
- redaction tests 
- sandbox output filter tests 
- validator failure path tests 
- CLI exit code tests

AI provider must be mockable.
No real AI calls in unit tests.

## Acceptance Criteria

Phase AI-1 is complete when:

- CLI can generate spec from prompt or file 
- output is always normalized 
- validator runs automatically 
- sandbox restrictions enforced 
- redaction works by default 
- tests pass without AI network calls 
- no runtime behavior changed 
- no code generation introduced

# Phase AI-2 — Repo-Aware Intent Drafting

## Goal

Enable AI-assisted Intent Spec drafting based on an existing Laravel codebase.

This phase allows the CLI to analyze the repository structure and produce an Intent Spec draft that reflects the current domain model, routes, and security posture.

AI remains review-first and proposal-only.

No automatic code modification is allowed.

---

## Scope

This phase introduces:

- repository structure indexing
- deterministic project snapshot builder
- AI-assisted spec drafting from repo snapshot
- reverse-intent generation (code → intent)
- diffable spec drafts
- confidence markers in generated spec

This phase does NOT introduce:

- automatic code rewriting
- runtime AI decisions
- automatic migrations
- sync engine
- background agents
- continuous repo learning

Repo awareness is read-only and build-time only.

---

## New CLI Command

```bash
php artisan intent:reverse
```
Purpose:
Generate an Intent Spec draft from existing code.

## Snapshot Builder

Before calling AI, CLI builds a deterministic snapshot.

Snapshot includes:
-   model class names
-   model relations
-   fillable / guarded properties
-   route list
-   middleware on routes
-   controller names
-   policy classes
-   request validation classes
-   enums
-   migration table names
    Snapshot excludes:
-   secrets
-   env values
-   credentials
-   config secrets
-   business data
-   user data
    Snapshot is structure-only.
----------
## Snapshot Determinism Rules

Snapshot must be:
-   sorted
-   canonicalized
-   path-stable
-   environment-independent
-
Same repo state → same snapshot output.
Snapshot is stored as:
```pgsql
storage/intent/snapshot.json
```
for debugging and reproducibility.

----------

## AI Input Contract

AI receives:
-   normalized project snapshot
-   optional developer prompt
-   spec schema reference
-   generation constraints

AI must NOT receive:
-   full code files
-   secrets
-   vendor code
-   git history
-   commit messages

Only structured metadata.

----------

## AI Output Requirements

AI produces:
-   intent YAML draft
-   TODO markers for ambiguous areas
-   confidence annotations

Example:

```yaml
# confidence: medium
# inferred from model relations
```
Low-confidence sections must be marked explicitly.

---
## Confidence Markers

Allowed markers:
-   confidence: high
-   confidence: medium
-   confidence: low
-   inferred_from: route
-   inferred_from: model
-   inferred_from: middleware

These markers help developer review.
Markers are comments only — not schema fields.

----------

## Draft Output Rules

Command produces:

```
intent/intent.reverse.generated.yaml
```
Rules:

-   never overwrites existing intent.yaml
-   never merges automatically
-   developer must review manually
-   validator runs after generation
-   normalization always applied

----------

## Security Boundaries

Repo-aware AI must be sandboxed.

Not allowed:

-   code execution
-   mutation
-   patching
-   writing PHP files
-   editing models/controllers
-   editing migrations

Allowed:
-   reading metadata snapshot
-   generating spec draft only

----------

## CLI Flags

Optional flags:

```
--include-routes
--include-models
--include-auth
--prompt="domain hints"
--no-ai
```

`--no-ai` mode produces a heuristic-only spec draft without AI.

Must exist for offline usage.

---

## Heuristic Fallback Mode

System must support non-AI fallback.

Heuristic mode derives:
-   auth rules from middleware
-   model invariants from fillable/guarded
-   basic selectors from route prefixes

This ensures tool works without AI.

AI improves quality — not required.

----------

## Validation Pipeline

Flow:

Repo snapshot  
→ heuristic draft  
→ AI refinement (optional)  
→ spec validator  
→ normalization  
→ generated draft file

Validation errors:
-   printed
-   never auto-fixed
-   never hidden

----------

## Testing Requirements

Must include:

-   snapshot determinism tests
-   heuristic-only generation tests
-   AI stub generation tests
-   confidence marker tests
-   normalization tests
-   CLI exit code tests
-   redaction tests
-   no-AI fallback tests

AI provider must be mockable.
No network calls in unit tests.

---

## Acceptance Criteria

Phase AI-2 is complete when:

-   CLI can generate spec from repo snapshot
-   snapshot builder is deterministic
-   secrets are never included
-   heuristic-only mode works
-   AI mode produces draft spec
-   confidence markers appear
-   validator runs automatically
-   no code modification occurs
-   tests run offline

---

✅ **AI-3 — Intent → Code Scaffold Planner (diff-only)** — to je trenutak gdje tvoja originalna vizija “AI compiler” počinje stvarno dobivati zube — ali i dalje bez auto-pisanja koda. 🚀

## Phase AI-3 — Intent → Code Scaffold Planner
(već definirano, referenca)
AI generira plan i diff prijedlog iz Intent Speca:
- migracije koje trebaju nastati
- modeli / relacije
- request validacije
- policy skeletoni
- test skeletoni
- OpenAPI dijelovi (ili claude)
⚠️ Samo plan + diff.
Nikad auto-write.

## Phase AI-4 — Safe Code Diff Generator
Goal:

Pretvoriti scaffold plan u deterministične patch/diff prijedloge.

### Dodaje:
- AST-based code patch generator
- generated regions / protected regions model
- partial-class / trait scaffold pattern 
- diff preview CLI

```bash
php artisan intent:scaffold --diff
```

### Pravila

- nikad overwrite cijelog fajla
- samo generated blocks
- user blocks zaštićeni
- patch preview obavezan

---

## Phase AI-5 — Intent Sync Engine (Spec ↔ Code Drift Detection)
Goal

Detektirati kad se intent i kod raziđu.

### Dodaje:

- drift detector
- spec vs code graph compare
- missing invariant warnings
- stale scaffold warnings

### Primjeri:
- intent kaže field postoji — migracija nema
- intent kaže rule — validation ne postoji
- intent kaže state — enum nema value

CLI
```
php artisan intent:drift
```
## Phase AI-6 — Domain Rule Engine (Build-Time Invariants)
Goal:

Iz intent pravila izvesti provjerljive domenske invarijante.
Ne runtime — nego build/test-time guardrails.

### Dodaje:

- state machine invariant checks 
- transition rule validator 
- invariant test generation 
- property-based test templates

### Primjer:
```
paid → cannot delete
refunded → cannot ship
```
→ generirani testovi + guard warnings

---

## Phase AI-7 — Intent-Aware Test Generator
Goal: AI generira testove iz intenta + koda.

### Dodaje:

- feature test templates
- authorization test matrix
- state transition tests
- invariant tests
- edge-case suggestions

CLI
```
php artisan intent:test-draft
```
### Pravila
- testovi su prijedlog
- nikad auto-write bez potvrde
- diff only

---

## Phase AI-8 — Performance & Query Guard AI
Goal

AI lint sloj za:

- N+1
- missing indexes 
- heavy filters 
- unsafe dynamic queries

### Dodaje:

- query pattern analyzer
- migration index suggestions
- “performance invariant” warnings

### Primjer:
```
intent filter must be fast
→ suggest index
```

---

## Phase AI-9 — Security Reasoning Layer
Goal

AI radi contextual security review iz intenta + koda.

Iznad postojećeg Guard skenera.

### Dodaje:

- multi-signal auth gap detection
- trust boundary reasoning
- cross-layer risk hints
- policy mismatch hints

### Bitno
Ovo je advisory sloj — nikad enforcement.

---

## Phase AI-10 — Project Brain (Repo Style Model)
Goal: AI modelira stil projekta.

### Uči:

- naming pattern
- folder structure 
- architecture style 
- test style 
- DTO vs Action vs Controller pattern

### Koristi se za:

- scaffold stil
- diff stil 
- suggestion stil

#### Storage
```
storage/intent/project-brain.json
```

Deterministic + regenerable.

---

### Phase AI-11 — IDE Plugin (PhpStorm / VSCode)
Goal : Intent-aware IDE pomoć.

### Featurei:

- intent rule hover
- route intent badge
- model invariant badge
- drift inline warning
- spec quick-jump
- scaffold preview

### Bitno

IDE je read-only + suggestion-only.

---

## Phase AI-12 — Intent UI / Visualizer

Goal _ Vizualni prikaz domene iz intenta.

### Prikazi:

- entity graph
- state machines 
- auth matrix 
- invariant map 
- route → intent mapping

Koristi spec — ne runtime.

---

## Phase AI-13 — Reverse Intent from DB
Goal : enerirati intent iz postojeće baze.

### Dodaje:

- schema → intent generator
- relation inference
- invariant hints
- index reasoning

CLI
```
php artisan intent:from-db
```

---

## Phase AI-14 — Multi-Module Intent Projects
Goal: Podrška za veće sustave.

### Dodaje:

- intent modules
- bounded contexts
- spec namespaces
- cross-module invariants

---

## Phase AI-15 — AI-Assisted Refactor Planner
Goal: AI predlaže refactor plan iz intenta + koda.

Primjeri:

- split model
- extract state machine
- introduce policy
- normalize validation
- 
Plan + diff only.

---

## Phase AI-16 — IntentPHP Framework Layer (Optional)

### Ovo je ona velika početna vizija.

Tek nakon svega iznad.

### Dodaje:

- intent-first project template
- intent → scaffold by default
- sync-first workflow
- AI-first DX
- domain-driven scaffolding

Laravel ostaje runtime.
IntentPHP je “meta layer”.

---

# Global Design Rules (Apply To All Phases)

Claude must enforce these rules when designing features:

1. Never auto-modify user code
2. Deterministic output only
3. Stable fingerprints
4. Additive checks only
5. Backward compatibility first
6. No framework coupling in spec DTO layer
7. CLI-first UX
8. AI optional, never required
9. Baseline compatibility preserved
10. Incremental scan compatibility preserved

---

# Before Implementing Any Phase — Self Review Required

Claude must validate:

- Does this break existing guard:scan behavior?
- Are fingerprints deterministic?
- Is baseline suppression still valid?
- Is feature additive?
- Are DTOs framework-free?
- Are errors surfaced without throwing?
- Is default mode safe (dry-run / read-only)?
- Is behavior CI-safe?

If any answer is unclear → redesign before coding.