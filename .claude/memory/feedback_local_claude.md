---
name: Keep Claude data in repo
description: User wants all Claude memory, context, history, and prompts stored within .claude/ in the repo, not in the global user directory.
type: feedback
---

Keep all Claude memory, context, history, prompts, and configuration within the repo's `.claude/` directory rather than the global `~/.claude/projects/` path.

**Why:** User prefers project-specific Claude data to live alongside the code for portability and visibility.

**How to apply:** Always use `.claude/` at the repo root for storing memory files, CLAUDE.md, and any other Claude-related configuration for this project.
