---
name: Watchdog every step that finishes later; don't move on until it's done
description: Arm a watchdog for CI runs, merges, deploys and background agents; silence isn't success; dependent steps wait (2026-09-24, #116)
type: feedback
---

Whenever a step starts something that finishes later (CI/check runs, PR merges, deploys, Dependabot or security-propagation runs, background agents/commands, long builds/tests), set up a watchdog that waits for it to finish and checks the result. Don't start any step that depends on it until the watchdog confirms it's done. Independent queued tasks can continue meanwhile.

**Why:** Owner directive 2026-09-24 (#116): "so that we don't miss them and not proceed to next steps in queues." Real misses motivated it. On 2026-08-04 a security-propagation run was reported as a clean no-op after a too-early, too-narrow check, but it had opened false-alarm issues #93–#95. On 2026-09-23 GitHub Actions jobs "failed" in about 2 seconds without ever starting, because of billing or minutes.

**How to apply:**
- Treat failed, cancelled, timed-out, skipped or never-started as finished-with-a-problem. Silence is not success.
- Check side effects too (issues, PRs, deleted branches), after giving them time to appear.
- Background work → `run_in_background` / `Monitor`, and wait for the completion notification. GitHub checks → re-check the check runs until all show completed (webhooks don't announce success), plus `subscribe_pr_activity`. Long or cross-session waits → `send_later` self check-in as a backstop.
- After a merge, watch the after-effects: `work-in-progress` gets auto-deleted (recreate it from `alpha`), and version-bump/deploy runs start.
- Jobs failing in about 2 seconds with no log mean GitHub didn't start them. Report that as BLOCKED, run the checks locally, and don't treat it as green.
- Before ending a turn or handing over, make sure nothing is unwatched, and record anything in flight in `.claude/HANDOFF.md` §0.
- Full rule: `.claude/CLAUDE.md` Standing Rules. Device-wide copy: `.claude/DEVICE_WIDE_RULES.md`.
