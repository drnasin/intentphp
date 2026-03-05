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

## Future layers (platform core)
- Drift Engine (spec ↔ code divergence)
- Invariant Engine (generalize checks into invariants)
- Generator (scaffold; preview-first; --write to apply)
- Spec↔Code Mapping (rule_id → symbols)
- Sync Suggestions (suggest only; never auto-apply)