# 🤝 CueRCode — Session Handoff

> **Read this first when resuming from a cold start.** Current state only, overwritten as the work moves (owner's rule, 2026-11-04/17). History is in `.claude/plans/history/HANDOFF_archive_2026-11-25.md`; what shipped is in `CHANGELOG.md`; decisions are in `docs/decisions/` (one file each, README index); what is and is not done is in the GitHub issues. Cite issue numbers and commit hashes, not line numbers.
> Companion docs: `PROJECT_ROADMAP.md` (master plan), `.claude/plans/new-install-readiness-queue.md` (the live queue to "ready for a new install"), `.claude/plans/7n-admin-commerce-plan.md` (admin and commerce plan), `docs/INSTALL.md` (fresh-install runbook), `LAUNCH_DECISIONS.md`.

## 0. ⚡ LATEST — 2026-11-25

### Where the work is
- **Branch `work-in-progress`.** GitHub has `5fa3892`. **Local only, not pushed (`5fa3892..HEAD`: the handoff note 641a4da plus 10 review commits from d9d95e2 onwards):** the fixes from the Codex review (F2), waiting for the lead's check and push (decision 0008). No other branch touched; Dependabot PR #152 (alpha) waits for the owner.
- **Built, reviewed by Opus and pushed:** 7n Phase 1A-1C, #128, decisions 0013-0016, install readiness, predefined logo PNGs (#65), library watch (#139, runs only from `main`).
- **Codex review F2 DONE (29 findings, chunks C1-C6):** 27 real and fixed with tests, C3-6 fixed in the named files with the rest in #157, C5-4 not a fault (measured). Full table: queue file, "F2 outcome". New issues: #157 (remaining shorthand), #158 (expiry checks if `site_timezone` is not UTC).
- **Tests:** `php tests/run.php` 6302/6302 (45 files, PHP 8.5.12); php86 scan 0; polish check 0 problems (9 legal-placeholder warnings). All six `tests/integration/*real_mysql.php` pass on MySQL 8.0.36 and MariaDB 10.6.28 (counts in tests/README.md). Real installer page run end to end on both (5 scenarios). Real Imagick 7 checked for the SVG fixes.
- **Not verified anywhere:** a real browser pass on any changed page (developer samples/authentication, admin re-confirm, reset password); DreamHost; real Microsoft mail; HTTPS/HSTS; a Codex re-check of these fixes.

### In flight (watched)
- Nothing running. (The throwaway Docker containers `f2review-*` were removed at the end of the session.)

### Next (order)
1. Lead: check and push `5fa3892..HEAD`; owner answers the two "choices" (C3-4 resets refused before upgrade 0003; C3-5 a busy known account gets the usual answer without a link). 2. Codex re-check of the fixes, and chunk 3 (docs/portal/spec). 3. Close built-and-verified issues with a commit link and the `completed` label. 4. Browser pass on the changed pages. 5. #156, #155, #157, #158. 6. After the install gate: 7n Phases 2-7, then 7j, 7r, 7m, 7q.
- Model tiers (decision 0001): gather = Sonnet/Haiku medium; plan = Opus high; build = Sonnet/Haiku medium (security core Opus); every check or review = Opus.

### Waiting on the owner
Deploy secrets `SFTP_KNOWN_HOSTS` (org) and `SFTP_REMOTE_BASE` (repo) and variable `SFTP_ENABLED`; re-run the failed secret-scan check on Dependabot PR #152; ask DreamHost about libbrotli (#150); after deploy open `/admin/` and read "Visitor address check" (behind Cloudflare?) and compare the Cloudflare ranges with cloudflare.com/ips; apply the database upgrade `0003` straight after the first deploy; enter the real Microsoft details on `/admin/settings/` and the DreamHost cron job; #136 instruction-audit decisions; #155 should the full tests gate deploy; the 9 legal placeholders (#38); GitHub App for #121; `abuse@` mailbox before dynamic QR; approval to delete the old backup branch and stale Dependabot branches; whether the privacy page should name Cloudflare. The full deduplicated list is in the queue file, section F.

### Standing rules to keep following
Plain English; report at each commit and keep going in the same turn; every owner decision is written to `docs/decisions/` and the issue before moving on; search `docs/decisions/` before asking; owner reserves pushes (except decision 0008), PRs, merges, tags, releases, deletes; set a watchdog on anything that finishes later; Codex review is owed on everything and the owner's no-PR-stacking rule applies (one `work-in-progress → alpha` PR later).

### Cold start
1. `git status`, `git log --oneline origin/work-in-progress..HEAD`. 2. Read the queue file's sections A, B, F. 3. `php tests/run.php`. 4. `gh issue list --state open`.
