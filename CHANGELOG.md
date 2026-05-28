# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

_No entries yet._

---

## [2.1.0] — 2026-05-28

Additive expansion of the supported runtime range. No code changes required; the package's existing Laravel API surface continues to work without modification on the new combinations. All 13 matrix combinations passed CI on first run.

### Added

- **Laravel 13** support. `illuminate/{support,console,routing}` constraints now include `^13.0`. `orchestra/testbench` dev dep bumped to include `^11.0` (testbench 11.x tracks Laravel 13).
- **PHP 8.5** support in CI matrix.
- CI matrix expanded from 8 jobs to 13:
  - PHP 8.2 × Laravel {10, 11, 12}
  - PHP 8.3 × Laravel {10, 11, 12, 13}
  - PHP 8.4 × Laravel {11, 12, 13}
  - PHP 8.5 × Laravel {11, 12, 13}
  - Excluded combinations are upstream-incompatible (Laravel 10 maintenance tracks PHP 8.1–8.3; Laravel 13 requires PHP ^8.3).
- `fail-fast: false` in the workflow matrix — every matrix failure surfaces in one run instead of cancelling after the first.

### Behavior

- No code changes. The package uses stable Laravel APIs (`Router`, `Console\Command`, `Eloquent\Model`, `ServiceProvider`) that haven't shifted across the supported majors.

---

## [2.0.0] — 2026-05-28

Major release. Two batches of work land together: the previously-unreleased Spec↔Code Mapping (Phase 13) + Sync Suggestions (Phase 14), and a 2026-05 sweep that closes most of an external security/correctness review. Read the upgrade notes before bumping.

### 🔴 Upgrade Notes

- **Re-baseline required.** Several finding identities changed. Route-authorization fingerprints normalize HTTP methods (HEAD stripped, sorted) and stop including a snippet line shift; dangerous-query identity moved from `sha1(snippet)` to `{sink}:{pattern}` so reformatting no longer churns the baseline; mass-assignment context records resolved receiver models and tainted-variable patterns. Run `php artisan guard:baseline` once after upgrading.
- **New runtime dependency: `nikic/php-parser` ^5** (pulled automatically by `composer update`). Requires `ext-tokenizer` — default in PHP ≥7.4, so no consumer action needed beyond install.
- **`symfony/yaml` floor raised** from `^6.0|^7.0` to `^6.4.40|^7.4.12` to exclude release lines affected by three unfixed YAML-parser CVEs (`CVE-2026-45304/45305/45133`: billion-laughs, ReDoS, stack-exhaustion). The excluded lines (6.0–6.3, 7.0–7.3) are EOL and have no fix backported.
- **New findings will fire on existing code.** The AST rewrite catches multi-line calls, raw-SQL interpolation/concatenation, the `DB` facade family, and request-input flowing through an intermediate variable (`$d = $request->all(); $m->fill($d);`) that the per-line regex missed. CI runs that were green will surface new HIGH findings until you triage or re-baseline.
- **Severity downgrade:** the name-only `orderBy($sort|order|column|field|dir)` heuristic now emits MEDIUM instead of HIGH; it never gates CI at the default severity. Findings that previously failed your build because of this pattern will no longer do so.

### Added

- AST-based detection in `MassAssignmentCheck` and `DangerousQueryInputCheck`, via `nikic/php-parser`. Catches multi-line calls and raw-SQL string interpolation/concatenation the per-line regex missed.
- Variable-indirection taint tracking: per-function, monotonic, flow-insensitive. Catches `$d = $request->all(); $m->fill($d);`, `$sql = '…' . $request->input('id'); DB::select($sql);`, and the same buried in `??` / ternary / match arm / cast / array literal / chained assign / wrapped foreach source / nested list destructure. FormRequest-typed parameters are recognised as request aliases when the type FQCN is `Illuminate\Http\Request`, `Illuminate\Foundation\Http\FormRequest`, or under a `*\Http\Requests\*` namespace.
- New raw-SQL sinks: `havingRaw`, `groupByRaw`, `fromRaw`, and the full `DB` facade family (`raw`, `statement`, `select`, `selectOne`, `insert`, `update`, `delete`, `unprepared`).
- New finding type `scan/parse-error` (MEDIUM): an unparseable PHP file is surfaced as a coverage gap finding instead of being silently skipped.
- `composer audit --no-dev` step in CI on every matrix combination — runtime dependencies are checked on every build, dev tree is scoped out (host-app-controlled).
- Spec↔Code Mapping Layer (`MappingIndex` v1.0, `MappingBuilder`, `MappingResolver`): versioned, deterministic mapping index linking intent spec entities to code targets. `guard:intent map` CLI; `--dump` outputs deterministic JSON with a sha256 checksum.
- `MappingEntry` DTO with explicit `link_type` (`spec_linked` / `observed_only`) and a `MappingResolver` query API (`byRuleId`/`byModelFqcn`/`byRouteId`/`observedOnly`/`specLinked`/`hasSpecLink`/`all`).
- Drift engine integration with the mapping layer: `DriftEngine` accepts an optional `MappingResolver` and enriches items with a `mapping_ids` context key without changing fingerprints.
- Sync Suggestions Engine (Phase 14): code↔spec providers and multiple renderers for guided spec construction.

### Changed

- `Fingerprint::routeIdentifier` normalizes HTTP methods (HEAD excluded, sorted), so the same route yields the same fingerprint regardless of registration order.
- `Fingerprint::normalizePath` is boundary-anchored — `myapp/` is no longer mistaken for an `app/` segment — and prefers the last matching segment so machine-specific prefixes don't leak.
- `BaselineManager::save` writes entries sorted by fingerprint (`SORT_STRING`); baseline JSON is byte-stable regardless of emission order.
- `GuardScanCommand` sorts findings globally by `(file, check, line, fingerprint)` before reporting, using `strcmp` so sha1 hashes of the magic-hash form aren't compared numerically.
- `MassAssignmentCheck` no longer flags a bare Eloquent model as "no `$fillable`"; that was a false positive (the framework default is `$guarded = ['*']`). New rule: unsafe iff `$guarded = []` OR partial `$guarded` (non-`['*']`) without `$fillable`. `$guarded = ['*']` and any `$fillable` allowlist are safe.
- `AuthMiddlewareClassifier` accepts both `:` and `.` as alias separators, so `auth.basic` / `auth.session` are recognised as auth protection.
- Default `auth_middleware_suffixes` is now `['\Authenticate']` so any namespace-anchored `…\Authenticate` middleware class is recognised. Set `[]` in config to opt out.
- `orderBy($sort|order|column|field|dir)` name heuristic emits MEDIUM with a "verify" message instead of HIGH (it can't tell a validated variable from a tainted one). Coverage breadth unchanged.
- `DangerousQueryInputCheck` treats only the FIRST argument of `where`/`orderBy`/`whereColumn` as an injection sink — a request value in a later argument is a parameterized binding and is safe.
- `Fingerprint::dangerousQueryIdentifier` (new) uses `{sink}:{pattern}` instead of `sha1(snippet)`, so reformatting the flagged line no longer churns the baseline.
- `ScanCache` payload is JSON instead of `serialize()`d. `ScanCache::VERSION` bumped `1.1.0` → `1.2.0` to positively invalidate leftover serialize-format files via `clear()` on the first scan after upgrade.

### Security

- `unserialize()` removed from the scan cache, eliminating a PHP object-injection gadget surface (low severity — local FS-write threat — but gratuitous).
- `symfony/yaml` floor raised to `^6.4.40|^7.4.12` (see Upgrade Notes). Guard parses untrusted `intent/intent.yaml`, so this directly closes the exposure.

### Fixed

- Route-authorization fingerprints were unstable across HTTP-method registration order. Now stable.
- Path normalization could leak a machine-specific prefix into the fingerprint when the path contained an earlier `app/`/`tests/`/`routes/` segment.
- A `*Request`-suffixed domain class typed parameter is no longer falsely treated as a Laravel request alias — alias detection now resolves the type's FQCN via `NameResolver` and matches Laravel patterns.
- Findings were never globally sorted before output, producing noisy JSON/baseline diffs.

### Behavior

- Missing intent file: `guard:intent map` produces an observed-only index containing routes only (no models). No error, exit 0.
- `DriftEngine` backward compatible: existing `new DriftEngine([$detector])` call sites work unchanged (second param defaults to null).
- `DriftDetectorInterface` unchanged — no new methods added.
- `mapping_ids` context key only present when `MappingResolver` is provided; drift fingerprint seeds (`rule_id`, `route_identifier`, `model_fqcn`, `drift_type`) are never affected.

### Documentation

- README updated to reflect AST detection (multi-line, interpolation, taint, FormRequest alias), the corrected mass-assignment rule, and the structured `route_authorization` config block.

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
