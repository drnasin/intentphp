# IntentPHP — Prompt Header (for Chat/IDE agents)

Read and follow these canonical docs:
- /docs/ai/CANON.md
- /docs/ai/ARCHITECTURE.md
- /docs/ai/ROADMAP.md
- /docs/ai/DECISIONS.md

Rules:
1) Do NOT propose runtime hooks or framework replacement.
2) Do NOT propose any automatic code modifications.
3) Output must be deterministic and CI-safe.
4) Preserve backward compatibility: missing intent file → no behavior change.
5) If something is not specified in the docs, ASK rather than assume.

When implementing:
- Provide: plan → file list → tests → minimal patch/diff
- Keep patches small and review-first
- Separate “Core phase scope” vs “vNext/AI scope”