# 🤝 CueRCode — Session Handoff

> **Read this first when resuming from a cold start.** Current state only, overwritten as the work moves (owner's rule, 2026-11-04/17). History is in `.claude/plans/history/HANDOFF_archive_2026-11-25.md`; what shipped is in `CHANGELOG.md`; decisions are in `docs/decisions/` (one file each, README index); what is and is not done is in the GitHub issues. Cite issue numbers and commit hashes, not line numbers.
> Companion docs: `PROJECT_ROADMAP.md` (master plan), `.claude/plans/new-install-readiness-queue.md` (the live queue to "ready for a new install"), `.claude/plans/7n-admin-commerce-plan.md` (admin and commerce plan), `docs/INSTALL.md` (fresh-install runbook), `LAUNCH_DECISIONS.md`.

## 0. ⚡ LATEST — 2026-11-25

### Where the work is
- **Branch `work-in-progress`:** everything is pushed to GitHub at `8b4ee2f` (owner decision 0008: push this branch only, after each reviewed phase). Nothing local is unpushed apart from this handoff note. No other branch has been touched. Dependabot PR #152 (alpha) waits for the owner.
- **Built, independently reviewed (Opus and Codex) and pushed:** 7n Phases 1A-1C (database upgrade tool, Global Admin access, settings editor), #128 resend verification link, decisions 0010-0018, install readiness (committed libraries, installer checks, credentials template, `docs/INSTALL.md`), predefined logo PNGs (#65), library watch (#139; runs only from `main`).
- **Codex cross-review loop is CLOSED (2026-11-25):** round 1 29 findings (27 real, fixed), round 2 6 (all real, fixed), round 3 4 (all real, fixed), round 4 1 (real, fixed), round 5 on the last fix: NO FINDINGS. Details: queue file F2 tables, `SECURITY.md` F-041..F-052, issues #157 (183 older shorthand PHP lines) and #158 (time zone) track what was deliberately left.
- **Tests:** `php tests/run.php` 6405/6405 (45 files, PHP 8.5.12); real-database scripts in `tests/integration/*real_mysql.php` pass on MySQL 8.0.36 and MariaDB 10.6.28; a fresh-install dry run passed on both (curl only, Microsoft faked). The PHP 8.4.27 + Imagick run was 6355/6355 after round 2 and was not repeated.
- **Not verified anywhere:** a real browser pass (six widths, light/dark, keyboard) on any new or changed page; DreamHost itself; real Microsoft mail; HTTPS/HSTS; MySQL 8.4 for the newest scenarios.

### In flight (watched)
- Nothing running. Throwaway Docker containers are stopped; the local image `cuercode-r2-imagick` is a reusable PHP 8.4 + Imagick test image.

### Next (order)
1. Owner: the steps in "Waiting on the owner" below (nothing more can be proven without them). 2. Real-browser pass on the new pages (admin overview/settings/re-confirm, verify, resend, reset, installer, developer docs) per the polish rule. 3. Close issues once proven live: #112 (real Microsoft send), #124 #127 #138 (a real deploy), #117 #125 (Codex chunk C6 covered #117/#111; confirm and close), #139 (first green run, needs `main`), #128, #113. 4. #156 and #157 leftovers; #155 should the full tests gate deploy. 5. One `work-in-progress -> alpha` PR (task 9; needs a workflow-file conflict check first; GitHub auto-deletes the source branch afterwards, so recreate it from alpha). 6. After the install gate: 7n Phases 2-7, dynamic QR (7j), passkeys/translations/image fills/JPEG XL, 7m, 7q.
- Model tiers (decision 0001): gather = Sonnet/Haiku medium; plan = Opus high; build = Sonnet/Haiku medium (security core Opus); every check or review = Opus.

### Waiting on the owner
Deploy secrets `SFTP_KNOWN_HOSTS` (org) and `SFTP_REMOTE_BASE` (repo) and variable `SFTP_ENABLED`; re-run the failed secret-scan check on Dependabot PR #152; ask DreamHost about libbrotli (#150); after deploy open `/admin/` and read "Visitor address check" (behind Cloudflare?) and compare the Cloudflare ranges with cloudflare.com/ips; apply the database upgrade `0003` straight after the first deploy; enter the real Microsoft details on `/admin/settings/` and the DreamHost cron job; #136 instruction-audit decisions; #155 should the full tests gate deploy; the 9 legal placeholders (#38); GitHub App for #121; `abuse@` mailbox before dynamic QR; approval to delete the old backup branch and stale Dependabot branches; whether the privacy page should name Cloudflare. The full deduplicated list is in the queue file, section F.

### Standing rules to keep following
Plain English; report at each commit and keep going in the same turn; every owner decision is written to `docs/decisions/` and the issue before moving on; search `docs/decisions/` before asking; owner reserves pushes (except decision 0008), PRs, merges, tags, releases, deletes; set a watchdog on anything that finishes later; Codex review is owed on everything and the owner's no-PR-stacking rule applies (one `work-in-progress → alpha` PR later).

### Cold start
1. `git status`, `git log --oneline origin/work-in-progress..HEAD`. 2. Read the queue file's sections A, B, F. 3. `php tests/run.php`. 4. `gh issue list --state open`.
