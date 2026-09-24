# AGENTS.md — CueRCode

Instructions for Codex and other AI coding agents. Full detail lives in:

- `.OpenAI/CONTEXT.md` and `.OpenAI/MEMORY.md` — Codex context and memory
- `.claude/CLAUDE.md` — code standards and **Standing Rules** (apply to every AI tool)
- `.claude/HANDOFF.md` §0 — **current position and next steps (read first)**

Key rules: plain-English feedback; commit and push only to `work-in-progress`;
no stacked PRs; keep `.claude/HANDOFF.md` up to the minute; cross-review with
the other AI tool until no issues remain; set a watchdog on anything that
finishes later (CI, merges, deploys, background work) and don't move on until
it has really finished (#116).
