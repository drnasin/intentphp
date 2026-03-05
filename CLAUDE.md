## IMPORTANT
You are contributing to the IntentPHP / Guard project.

Canonical project docs (authoritative):
- /docs/ai/CANON.md
- /docs/ai/ARCHITECTURE.md
- /docs/ai/ROADMAP.md
- /docs/ai/MILESTONES.md
- /docs/ai/DECISIONS.md

Ignore /docs/ai/_archive/* (historical snapshots; not canonical).

Do not expand scope beyond defined phases.
Do not introduce runtime hooks.
All features must be CLI-only, deterministic, additive, CI-safe.

When asked to implement changes, output in this order:
1) Plan (steps)
2) Files to add/change
3) Tests to add (unit + golden)
4) Minimal patch/diff per file
5) Notes proving determinism & fingerprint rules

Before proposing architecture, self-check against:
- scope rules (ROADMAP + MILESTONES)
- determinism rules (CANON + DECISIONS)
- backward compatibility rules (CANON + DECISIONS)
- DTO stability rules (ARCHITECTURE + DECISIONS)