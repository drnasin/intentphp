Note: Detailed acceptance criteria live in [/docs/ai/MILESTONES.md]()
    
# IntentPHP — Roadmap (Canonical)

Goal: move from code-only analysis → toward declared intent + invariants + drift detection + safe generation.

Hard rules (apply to all phases):
- never auto-modify user code
- deterministic output
- CI-safe behavior
- stable fingerprints
- additive + backward compatible
- AI optional, never required

## ✅ Completed (Guard Engine Era)
- Phase 1–7: Guard core (checks, baseline, incremental, fingerprints, CI behavior)
- Phase 8: Intent Spec v0.1 (DTOs, loader/validator, includes/merge, guard:intent CLI)
- Phase 9: Intent-aware checks (intent-auth, intent-mass-assignment, enrichers)
- Phase 10: Drift Engine (auth + mass-assignment drift detection, deterministic fingerprints, golden tests, regression-tested wiring)
- Phase 13: Spec ↔ Code Mapping Layer (MappingIndex v1, builder, resolver, CLI `guard:intent map`, drift integration)
- Phase 14: Sync Suggestions Engine (SuggestionEngine, CodeToSpec + SpecToCode providers, text/JSON renderers, CLI `guard:intent sync`)

### DX improvements (cross-cutting, shipped alongside P13/P14)
- Filament auth fix: `Filament\Http\Middleware\Authenticate` recognized as auth middleware
- Smart defaults: `AuthMiddlewareClassifier` (exact/prefix/suffix matching), built-in skip lists for guest auth and infrastructure routes, structured `route_authorization` config key

---

## 🚧 Platform Core (Must-ship backbone)

### Phase 11 — Invariant Engine v1
Goal: generalize checks into reusable invariants.
Pure evaluation, deterministic inputs, no side effects.

### Phase 12 — Intent Scaffold Generator (Preview-first)
Goal: generate missing starter artifacts from intent.
Rules: preview/diff default, --write required, never overwrite without --force, deterministic templates.

Result: **Intent Platform Core complete** (P11 + P12 remaining)

---

## 🔮 Optional AI & Ecosystem layers (still CLI/build-time, proposal-only)

### Phase AI-1 — Natural language → Intent Spec draft
CLI: intent:generate
- produces intent/intent.generated.yaml (never overwrites intent.yaml)
- normalization mandatory (same prompt + same version → same normalized output)
- sandboxed + redaction by default
- tests must run offline (mock AI provider)

### Phase AI-2 — Repo-aware intent drafting (read-only snapshot)
CLI: intent:reverse
- deterministic snapshot builder (structure only)
- heuristic-only fallback (--no-ai)
- confidence markers in comments
- never sends full code files

### AI-3..AI-6 (high-level)
- AI-3: Intent → scaffold planner (plan + diff only)
- AI-4: Safe diff generator (generated blocks, preview required)
- AI-5: Drift detection improvements via mapping graph compare
- AI-6: Build-time domain invariant checks + test templates

### Later ecosystem ideas
- IDE integration
- visualizer (intent UI)
- performance/query guard
- security reasoning layer (advisory only)
- “project brain” (deterministic + regenerable style model)