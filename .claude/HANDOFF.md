# 🤝 CueRCode — Session Handoff

> **Read this first when resuming from a cold start.** Current state only, overwritten as the work moves (owner's rule, 2026-11-04/17). History is in `.claude/plans/history/HANDOFF_archive_2026-11-25.md`; what shipped is in `CHANGELOG.md`; decisions are in `docs/decisions/` (one file each, README index); what is and is not done is in the GitHub issues. Cite issue numbers and commit hashes, not line numbers.
> Companion docs: `PROJECT_ROADMAP.md` (master plan), `.claude/plans/first-launch-plan.md` (the live plan to first launch, once written), `.claude/plans/new-install-readiness-queue.md` (install queue), `.claude/plans/7n-admin-commerce-plan.md` (admin and commerce plan), `docs/INSTALL.md` (fresh-install runbook), `LAUNCH_DECISIONS.md`.

## 0. ⚡ LATEST — 2026-11-27

### Goal
Owner (2026-11-27): work autonomously until the **first version** can be launched. First version = free service **plus dynamic QR codes**, nobody charged (decision 0023). 7n Phases 3-7 and payments come after launch.

### Where the work is
- Branch `work-in-progress`. GitHub is at `e594ec8` (already includes the unreviewed Phase 2 code, pushed 2026-11-26). Later commits are local only: `git log --oneline origin/work-in-progress..HEAD`.
- Phase 2: Opus review (6 findings) fixed `777148e..621835a`; Opus re-check (8 minor) fixed `7eceacf..fe317f6` (suite 7667/7667; MySQL 8.0.36 and MariaDB 10.6.28). Those last fixes are not yet independently re-checked; Codex is owed.
- Deploy test gate (#155) built `b7ce022`; Opus checked; not yet seen on GitHub.
- Owner decisions 0023-0039 recorded 2026-11-27. First-launch plan: `.claude/plans/first-launch-plan.md`; dynamic QR issues #164-#172; later second link checker #173.
- **Not verified anywhere:** real browser pass; DreamHost; real Microsoft mail; real human-check providers; HTTPS/HSTS; MySQL 8.4 for the newest scenarios.

### In flight (watched, this session) — 2026-11-28 about 03:15
Committed up to `b34d678`; nothing pushed. At `4efdcc2`: unit suite 9259/9259; 9 real-database scripts pass on MySQL 8.0.36 and MariaDB 10.6.
1. **Opus fix builder:** findings in `.claude/plans/launch-round3-review-report.md`, then #177, #178, #179, and orphaned test web servers.
2. **Codex review** (background script, waits until 04:52): human check, no-JavaScript route, pricing and CI changes. Default model, effort high.

### Next (order)
1. Opus check of `35e87c2..HEAD` (includes #158/#130, #129, #154) + Codex findings → fix → repeat until clean → push `work-in-progress` (decision 0008) → comment/close #31, #129, #130, #154, #155, #158-#161, #163, #174-#179 → move finished review reports to `.claude/plans/history/`.
2. Dynamic QR WP4 (database upgrade `0007` still free, #164 onwards) → real-browser and polish pass → **ask the owner**, then the one pull request into `alpha` (#162, decision 0026).
- Only GitHub can prove: the database-test jobs and the deploy gate (first push to `alpha`).
- Model tiers (decision 0001): gather = Sonnet/Haiku medium; plan = Opus high; build = Sonnet/Haiku medium (money and security = Opus); every check or review = Opus.

### Waiting on the owner
Deploy secrets `SFTP_KNOWN_HOSTS` (org) and `SFTP_REMOTE_BASE` (repo) and variable `SFTP_ENABLED`; re-run the failed secret-scan check on Dependabot PR #152; ask DreamHost about libbrotli (#150); after deploy open `/admin/` and read "Visitor address check" and compare the Cloudflare ranges with cloudflare.com/ips; apply the database upgrades (including `0003` and `0004`) straight after the first deploy; enter the Microsoft details on `/admin/settings/` and add the DreamHost cron job; #136 decisions; the 9 legal placeholders (#38); GitHub App for #121; `abuse@` mailbox before dynamic QR; approval to delete the old backup branch and stale Dependabot branches; whether the privacy page should name Cloudflare. Full list: queue file section F and the first-launch plan.

### Standing rules to keep following
Plain English; report at each commit and keep going in the same turn; every owner decision is written to `docs/decisions/` and the issue before moving on; search `docs/decisions/` before asking; one session at a time in this folder; owner reserves pushes (except decision 0008), PRs, merges, tags, releases, deletes; set a watchdog on anything that finishes later; Codex review is owed on everything and the owner's no-PR-stacking rule applies (one `work-in-progress → alpha` PR later).

### Cold start
1. `git status`, `git log --oneline origin/work-in-progress..HEAD`. 2. Read the first-launch plan. 3. `php tests/run.php`. 4. `gh issue list --state open`.
