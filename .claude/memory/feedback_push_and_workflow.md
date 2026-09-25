---
name: Commit AND push to work-in-progress; one PR later
description: Push is now allowed (to work-in-progress only); no stacked PRs; after-each-task steps
type: feedback
---

After each task: commit AND push to `work-in-progress`, update that task's GitHub issue, update `.claude/` memory/context, update `.OpenAI/` memory/context, update `.claude/HANDOFF.md`. Don't open extra PRs — one PR `work-in-progress → alpha` is created later.

**Why:** Owner directive 2026-09-23 (#108). Replaces the older "never push — owner pushes manually" rule. Single PR avoids merge race conditions.

**How to apply:** Never push to any branch other than `work-in-progress` without explicit permission. Dependabot PRs are automatic and exempt.

**Trap (found 2026-09-25, hit four times in one session):** the dev-team plugin's guard hook blocks ANY Bash command whose text contains both "push" and "main". It reads the whole command, including commit messages, heredocs, issue-comment text and even the phrase "main site". The blocked command runs nothing at all (nothing staged, nothing committed). So: run `git push -q origin work-in-progress` as a command **on its own**. Write commit messages to a file and use `git commit -F <file>`. Keep issue comments that mention the default branch in a separate command from any push. Don't work around the guard by disabling it: it protects the default branch.
