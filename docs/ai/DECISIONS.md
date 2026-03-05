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

## D-006: Fingerprints must avoid volatile inputs
Status: accepted
Rationale: baseline stability across machines/runs.
Consequence: no timestamps, no absolute paths; line numbers only if unavoidable.