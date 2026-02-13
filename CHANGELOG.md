# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased] — Phase 9: Intent-Aware Checks

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
