# IntentPHP — Decisions log (Canonical)

## D-001: Guard is deterministic static analysis (CLI/build-time only)
Status: accepted
Rationale: CI-safe, stable fingerprints, reproducible baseline.
Consequence: no runtime hooks, no request lifecycle coupling.

## D-002: Never auto-modify user code
Status: accepted
Rationale: trust + safety + review-first workflow.
Consequence: only diffs/patches/suggestions; --write must be explicit.

## D-003: Intent Spec is optional; missing intent file must not change Guard behavior
Status: accepted
Rationale: progressive adoption.
Consequence: all intent features additive and suppressible.

## D-004: DTO/spec layer must be framework-free
Status: accepted
Rationale: determinism + testability + portability.
Consequence: no container access or Laravel runtime coupling in DTO layer.

## D-005: AI is always optional and sandboxed
Status: accepted
Rationale: offline usage + reproducibility + security boundaries.
Consequence: mockable provider; no network calls in unit tests; redaction default.

## D-006: Fingerprints must avoid machine-volatile inputs
Status: accepted
Rationale: baseline stability across machines/runs.
Consequence: no timestamps, no absolute paths (paths normalized to a repo-relative form). The finding's line number IS included as part of identity so each occurrence is tracked individually — it is stable across machines but shifts when surrounding code moves, so large edits may require a re-baseline (see issue #17). Per-check primary identifiers stay line-independent (route methods+uri+action, model FQCN, dangerous-query sink+pattern).

## D-007: Invariant Engine v1 — framework-coupled input + legacy-name fingerprint parity
Status: accepted
Context: Phase 11 introduces a reusable invariant layer (`Invariant`, `Violation`, `InvariantRegistry`, `InvariantCheck`) and migrates the route-authorization and mass-assignment rules into it.

Decision (a) — input shape: v1 invariants evaluate over a deliberately framework-coupled `InvariantInput` that holds live framework objects (`Illuminate\Routing\Router`, the shared `AstParser`, `RouteProtectionDetector`) rather than a pure, framework-free snapshot. This is a time-boxed exception to D-004 (the DTO/spec layer stays framework-free; the invariant *input* does not). It exists to MOVE the prior checks' logic verbatim and guarantee byte-identical output. A framework-free snapshot (observed-routes/observed-models DTOs feeding invariants) is deferred to Invariant Engine v2. `router` and `detector` on `InvariantInput` are nullable: each invariant is only ever handed an input carrying the dependencies it needs (route needs router+detector; mass-assignment needs paths+ast+changedFiles), avoiding throwaway framework objects.

Decision (b) — fingerprint mapping: MILESTONES P11 specifies fingerprints derived from `(invariant_id + target_id + semantic keys)`. This is realized as `invariant_id` = the LEGACY check name (`route-authorization`, `mass-assignment`), and the `InvariantCheck` adapter sets `Finding.check = invariant_id` and passes the Violation `context` straight through (preserving key insertion order). Because `Fingerprint::compute()` keys off `check` + `context`, migrated rules emit byte-identical findings and committed user `baseline.json` files do not churn. The legacy `check` string is therefore a PARITY CONTRACT, not an oversight or a value free to change. `Violation.targetId` carries the human-stable target identity for the Violation contract but is intentionally NOT consumed by the fingerprint (the per-check primary identifier is derived from `context`).
Consequence: the golden parity test (`tests/Unit/Invariant/InvariantCheckParityTest.php`) and the unchanged legacy check tests are the parity oracle; any change to an invariant's emitted `check`/`severity`/`message`/`file`/`line`/`context` (incl. key order)/`fix_hint` is a baseline-breaking change.