# 🤝 CueRCode — Session Handoff

> **Read this first when resuming from a cold start.** Current state only, overwritten as the work moves (owner's rule, 2026-11-04/17). History is in `.claude/plans/history/HANDOFF_archive_2026-11-25.md`; what shipped is in `CHANGELOG.md`; decisions are in `docs/decisions/` (one file each, README index); what is and is not done is in the GitHub issues. Cite issue numbers and commit hashes, not line numbers.
> Companion docs: `PROJECT_ROADMAP.md` (master plan), `.claude/plans/new-install-readiness-queue.md` (the live queue to "ready for a new install"), `.claude/plans/7n-admin-commerce-plan.md` (admin and commerce plan), `docs/INSTALL.md` (fresh-install runbook), `LAUNCH_DECISIONS.md`.

## 0. ⚡ LATEST — 2026-11-25

### Where the work is
- **Branch `work-in-progress`.** Pushed to GitHub at `5fa3892` (owner decision 0008: push this branch only, after each reviewed phase). Nothing local is unpushed. No other branch has been touched; Dependabot PR #152 (alpha) is open and waits for the owner.
- **Built, reviewed by Opus and pushed:** 7n Phase 1A (database upgrade tool, migrations 0001-0003), 1B (Global Admin access, re-confirm password, first admin from the command line with a forced new password, decision 0010), 1C (settings editor incl. editable website address `site_url`), #128 (ask for a new verification link), decisions 0013/0014/0015/0016 (sign-up-first fix, visitor address, password change), install readiness (committed libraries, installer checks, credentials template, `docs/INSTALL.md`), predefined logo PNGs (#65), library watch job (#139, cannot run until promoted to `main`: GitHub only runs schedules from the default branch). #153 closed; #122 #123 #126 closed.
- **Tests:** `php tests/run.php` 6141/6141 at `5fa3892`; real-database scripts in `tests/integration/*real_mysql.php` pass on MySQL 8.0 and MariaDB 10.6 (some also on 8.4). A fresh-install dry run (docs/INSTALL.md, MySQL 8.0 and MariaDB 10.6, Docker, curl only, Microsoft faked) passed after one installer crash was fixed.
- **Not verified anywhere:** a real browser pass (six widths, light/dark, keyboard) on any new page; DreamHost itself; real Microsoft mail; HTTPS/HSTS; Codex review of anything since 2026-09-25 (running now, see below).

### In flight (watched)
- Codex review driver (background agent): six small read-only chunks C1-C6 against an export of `5fa3892`, results saved in the session scratchpad and summarised on completion. If Codex runs out of allowance it stops and reports the reset time (owner rule: never buy extra allowance; split small and retry later). If this session dies: re-run the chunks listed in the queue file, item F2.

### Next (order)
1. Triage Codex findings, fix real ones, re-check. 2. Close built-and-verified issues with a commit link and the `completed` label: #117 #125 (after Codex), #124 #127 #138 (after a real deploy), #112 (after a real Microsoft send). 3. #156 review leftovers, #155 deploy gate decision. 4. Browser pass on the new admin, verify, resend and settings pages (owner-visible polish rule). 6. After the install gate: 7n Phases 2-7, then dynamic QR (7j), then passkeys/translations/image fills/JPEG XL, 7m, 7q (owner's build order).
- Model tiers (decision 0001): gather = Sonnet/Haiku medium; plan = Opus high; build = Sonnet/Haiku medium (security core Opus); every check or review = Opus.

### Waiting on the owner
Deploy secrets `SFTP_KNOWN_HOSTS` (org) and `SFTP_REMOTE_BASE` (repo) and variable `SFTP_ENABLED`; re-run the failed secret-scan check on Dependabot PR #152; ask DreamHost about libbrotli (#150); after deploy open `/admin/` and read "Visitor address check" (behind Cloudflare?) and compare the Cloudflare ranges with cloudflare.com/ips; apply the database upgrade `0003` straight after the first deploy; enter the real Microsoft details on `/admin/settings/` and the DreamHost cron job; #136 instruction-audit decisions; #155 should the full tests gate deploy; the 9 legal placeholders (#38); GitHub App for #121; `abuse@` mailbox before dynamic QR; approval to delete the old backup branch and stale Dependabot branches; whether the privacy page should name Cloudflare. The full deduplicated list is in the queue file, section F.

### Standing rules to keep following
Plain English; report at each commit and keep going in the same turn; every owner decision is written to `docs/decisions/` and the issue before moving on; search `docs/decisions/` before asking; owner reserves pushes (except decision 0008), PRs, merges, tags, releases, deletes; set a watchdog on anything that finishes later; Codex review is owed on everything and the owner's no-PR-stacking rule applies (one `work-in-progress → alpha` PR later).

### Cold start
1. `git status`, `git log --oneline origin/work-in-progress..HEAD`. 2. Read the queue file's sections A, B, F. 3. `php tests/run.php`. 4. `gh issue list --state open`.
