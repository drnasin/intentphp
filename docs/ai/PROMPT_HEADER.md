# IntentPHP — Prompt Header (for Chat/IDE agents)

Read and follow these canonical docs:
- /docs/ai/CANON.md
- /docs/ai/ARCHITECTURE.md
- /docs/ai/ROADMAP.md
- /docs/ai/DECISIONS.md
- /docs/ai/MILESTONES.md

Ignore /docs/ai/_archive/* (historical snapshots; never canonical).

Rules:
1) Do NOT propose runtime hooks or framework replacement.
2) Do NOT propose any automatic code modifications (suggestions/diffs only; never apply).
3) Output must be deterministic and CI-safe.
4) Preserve backward compatibility: missing intent file → no behavior change.
5) If something is not specified in the canonical docs, ASK rather than assume.

When implementing, output in this order:
1) Plan (steps)
2) Files to add/change
3) Tests to add (unit + golden)
4) Minimal patch/diff per file
5) Notes proving determinism & fingerprint rules

Always separate: “Core phase scope” vs “vNext/AI scope”.