# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased] — Phase 13: Spec↔Code Mapping Layer

### Added

- Spec↔Code Mapping Layer (`MappingIndex` v1.0, `MappingBuilder`, `MappingResolver`): builds a versioned, deterministic mapping index linking intent spec entities (auth rules, model specs) to code targets (routes, models).
- `MappingEntry` DTO with `link_type` field (`spec_linked` or `observed_only`) for explicit entry classification. Consumers use `isSpecLinked()` / `isObservedOnly()` / `hasSpecLink()` — no null-checking needed.
- `MappingResolver` query API: `byRuleId()`, `byModelFqcn()`, `byRouteId()`, `observedOnly()`, `specLinked()`, `hasSpecLink()`, `all()`.
- CLI: `guard:intent map` builds and summarizes the mapping index. `guard:intent map --dump` outputs deterministic JSON (sorted entries, sha256 checksum).
- Drift engine integration: `DriftEngine` accepts optional `MappingResolver` via constructor. When present, drift items are enriched with `mapping_ids` context key. Fingerprints are unaffected.
- Golden test fixtures for mapping output (`tests/fixtures/mapping/full/expected.json`, `tests/fixtures/mapping/observed-only/expected.json`).
- Fingerprint stability test proving drift fingerprints are identical with and without mapping enrichment.

### Behavior

- Missing intent file: `guard:intent map` produces an observed-only index containing routes only (no models). No error, exit 0.
- `DriftEngine` backward compatible: existing `new DriftEngine([$detector])` call sites work unchanged (second param defaults to null).
- `DriftDetectorInterface` unchanged — no new methods added.
- `mapping_ids` context key only present when `MappingResolver` is provided; drift fingerprint seeds (`rule_id`, `route_identifier`, `model_fqcn`, `drift_type`) are never affected.
- Checksum: sha256 of compact canonical JSON (`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, no pretty-print) over sorted entries array. No timestamps or absolute paths.
- Selector methods normalized to uppercase before matching, consistent with `ObservedRoute.methods`.

---

## Phase 10: Drift Engine (Spec↔Code)

### Added

- Drift Engine (`DriftEngine`, `DriftDetectorInterface`, `DriftItem`): orchestrates drift detectors, sorts output deterministically.
- Auth drift detector (`intent-drift/auth`): detects `missing_auth_middleware`, `missing_guard_middleware`, `public_but_protected`.
- Mass-assignment drift detector (`intent-drift/mass-assignment`): detects `missing_fillable`, `forbidden_in_fillable`, `guarded_empty`, `unparseable_model`.
- Pure DTOs: `ObservedRoute`, `ObservedModel`, `ProjectContext` (no Laravel types).
- `ProjectContextFactory`: bridge from Laravel `Router` + model FQCNs to `ProjectContext`.
- `IntentDriftCheck` adapter: converts `DriftItem[]` to `Finding[]` with stable fingerprints.
- `RouteIdentifier`: stable composite route identifiers (`name:{routeName}|{methods}` or `uri:{normalizedUri}|{methods}`).
- Golden fixtures for auth and mass-assignment drift output.

### Behavior

- Drift checks run only when intent spec is present. Missing intent file → no drift checks executed, no behavior change.
- Drift fingerprints: `drift:auth:{rule_id}:{route_identifier}` and `drift:mass-assignment:{model_fqcn}:{drift_type}`. No timestamps, no absolute paths, no line numbers.
- Drift findings integrate with baseline suppression and incremental scanning.

---

## Phase 9: Intent-Aware Checks

### Added

- Intent-aware route authorization check (`intent-auth`): validates route middleware against auth rules declared in `intent/intent.yaml`.
- Intent-aware mass assignment check (`intent-mass-assignment`): validates model `$fillable`/`$guarded` against constraints declared in the intent spec.
- Optional `intent/intent.yaml` integration into `guard:scan`. If the file is absent, Guard behaves exactly as before.
- `RouteProtectionDetector` shared helper, extracted from `RouteAuthorizationCheck`. Used by both the existing route check and the new `IntentAuthCheck`.
- `IntentContext` value object for spec loading, validation, and warning collection. Uses a non-throwing `tryLoad()` factory.
- `IntentEnricher` for post-scan enrichment of existing `mass-assignment` findings with intent spec details (allow/forbid lists, mode).
- Deterministic fingerprints for intent findings: `intent-auth` fingerprints use sorted rule IDs and methods; `intent-mass-assignment` fingerprints use model FQCN and deterministic pattern labels (no line numbers).
- `AuthRequirement::toCanonicalArray()` for stable grouping of rules with identical requirements.

### Behavior

- Intent spec is optional. No changes to default scan behavior when `intent/intent.yaml` is absent.
- Intent checks are additive to existing checks. A route can receive both `route-authorization` and `intent-auth` findings; they are independently suppressible.
- Spec parse errors and validation failures print error messages and exit non-zero. They do not throw exceptions.
- Missing model files referenced in the spec produce warnings (printed after scan), not findings. The scan continues.
- Multiple auth rules matching the same route with identical requirements are deduplicated into a single finding with all `matched_rule_ids` listed.
- Incremental scan mode (`--changed`, `--staged`) filters `intent-auth` findings the same way as `route-authorization` findings.
