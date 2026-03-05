# IntentPHP — Architecture (Guard + Intent Platform)

## Big picture
Guard is a deterministic static analysis tool for Laravel/PHP projects.

Pipeline:
1) Load project context (routes, files, symbols, config)
2) Run checks
3) Produce findings (stable fingerprints)
4) Optional enrichers (deterministic; never create new findings)
5) Apply baseline + incremental filters
6) Render output + CI-safe exit codes

Intent Platform adds an optional declarative layer (intent/intent.yaml).
If intent file is missing → Guard behaves exactly as before.

## Core data contracts
### Finding
- check (string)
- severity (LOW|MEDIUM|HIGH)
- message (string)
- context (stable, machine-readable)
- fingerprint (stable ID for baseline)

### Fingerprints (rules)
- deterministic across machines
- avoid volatile inputs (timestamps, absolute paths, line numbers when possible)
- sort lists (routes, methods, rule IDs)
- use semantic identifiers (route + methods; model FQCN + pattern labels)

## Checks model
- stateless
- no side effects
- deterministic ordering
- stable context

## Intent platform (current: Spec v0.1 + intent-aware checks)
### Intent Spec
Location: intent/intent.yaml
Support: includes + deterministic merge + validation + normalization

CLI:
- guard:intent init
- guard:intent validate
- guard:intent show

### IntentContext
- holds validated IntentSpec
- collects warnings (deduped, deterministic)
- tryLoad() never throws

### Intent checks (examples)
- intent-auth: validate route middleware vs declared auth.rules
- intent-mass-assignment: validate model constraints vs actual model definition

### Shared components
- RouteProtectionDetector (standardized middleware detection)
- IntentEnricher (adds intent context to existing findings; no new findings)

## Drift categories

Drift findings detect divergence between declared intent and observed project state.
Check names use the prefix `intent-drift/{domain}`.

### intent-drift/auth
| Drift type | Severity | Trigger |
|---|---|---|
| `missing_auth_middleware` | HIGH | Route requires `authenticated: true` but has no auth middleware |
| `missing_guard_middleware` | HIGH | Route requires specific `guard` but `auth:{guard}` not in middleware |
| `public_but_protected` | MEDIUM | Route declared `public: true` but has auth middleware |

Public routes (`public: true`) with no auth middleware produce no drift.
Public routes with auth middleware emit `public_but_protected` (spec↔code divergence).

Fingerprint seed: `drift:auth:{rule_id}:{route_identifier}`
- `rule_id`: first sorted matched intent rule ID
- `route_identifier`: `name:{routeName}|{methods}` or `uri:{normalizedUri}|{methods}`

### intent-drift/mass-assignment
| Drift type | Severity | Trigger |
|---|---|---|
| `missing_fillable` | HIGH | Mode `explicit_allowlist` but no `$fillable` property |
| `forbidden_in_fillable:{attr}` | HIGH | Forbidden attribute found in `$fillable` |
| `guarded_empty` | HIGH | Mode `guarded` but `$guarded = []` |
| `unparseable_model` | LOW | `$fillable` uses non-static pattern, forbid list non-empty |

Fingerprint seed: `drift:mass-assignment:{model_fqcn}:{drift_type}`

### Drift layer architecture
- Pure DTOs: `ObservedRoute`, `ObservedModel`, `ProjectContext` (no Laravel types)
- `DriftDetectorInterface`: `detect(IntentSpec, ProjectContext) → DriftItem[]`
- `DriftEngine`: orchestrates detectors, sorts output deterministically
- `IntentDriftCheck`: adapter → `Finding[]` (integrates with baseline + incremental)
- `ProjectContextFactory` (Laravel layer): bridge from `Router` + `string[] $modelFqcns` → `ProjectContext` (no IntentSpec/IntentContext dependency)

## Future layers (platform core)
- Invariant Engine (generalize checks into invariants)
- Generator (scaffold; preview-first; --write to apply)
- Spec↔Code Mapping (rule_id → symbols)
- Sync Suggestions (suggest only; never auto-apply)