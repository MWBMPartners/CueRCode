---
name: Commit AND push to work-in-progress; one PR later
description: Push is now allowed (to work-in-progress only); no stacked PRs; after-each-task steps
type: feedback
---

After each task: commit AND push to `work-in-progress`, update that task's GitHub issue, update `.claude/` memory/context, update `.OpenAI/` memory/context, update `.claude/HANDOFF.md`. Don't open extra PRs — one PR `work-in-progress → alpha` is created later.

**Why:** Owner directive 2026-09-23 (#108). Replaces the older "never push — owner pushes manually" rule. Single PR avoids merge race conditions.

**How to apply:** Never push to any branch other than `work-in-progress` without explicit permission. Dependabot PRs are automatic and exempt.
