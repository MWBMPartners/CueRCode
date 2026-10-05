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
- **Trap found 2026-09-25: never point `watchdog.sh quiet` at a background agent's `tasks/<id>.output` file.** That file is a symbolic link (a shortcut) to the real transcript, and the script's age check (`stat -c %Y` / `stat -f %m`, with no `-L`) reads the shortcut's own date, which never changes. So it always reports "unchanged, look now" after the idle time, even while the agent is busy. Point it at the real file instead: `ls -la` the `.output` file and use the `…/subagents/agent-<id>.jsonl` path it links to. The real fix is adding `-L` to both `stat` calls in `~/.claude/bin/watchdog.sh` and WebMS-Intra's `tools/watchdog.sh` (the two must stay identical); the owner APPROVED this for both copies on 2026-09-25. The `~/.claude/bin` copy must be fixed by a session on the owner's Mac (a cloud session can't reach it); WebMS-Intra's copy is tracked in that repo.
- **Can't trigger GitHub re-runs or bot commands from a cloud session (2026-10-05):** re-run requests get 403 "resource not accessible by integration", and comments like `@dependabot recreate` are deliberately defanged (dots inserted + a footer), so Dependabot ignores them. It's a safety feature — don't try to work round it; ask the owner to comment the command or press "Re-run all jobs".
- Full rule: `.claude/CLAUDE.md` Standing Rules. Device-wide copy: `.claude/DEVICE_WIDE_RULES.md`.
