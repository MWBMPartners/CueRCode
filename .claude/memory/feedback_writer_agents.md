---
name: Brief writer agents tightly
description: Give delegated writer agents an explicit file allow-list, and warn them that handoff task numbers aren't theirs
type: feedback
---

When delegating doc or code edits to Sonnet/Haiku writers, give each an explicit file allow-list and a pointer file with ALL instructions. State that the task numbers in `.claude/HANDOFF.md` are project-level, not items in their brief.

**Why:** On 2026-09-23 two writers read "tasks 5-6" in HANDOFF as their own sub-items, did only part of their brief, and edited HANDOFF against instructions. The conductor had to revert and re-dispatch.

**How to apply:** Always check `git status` and the scope of each writer's report before committing, and re-dispatch any files that were skipped.
