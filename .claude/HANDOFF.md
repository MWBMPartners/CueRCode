# 🤝 CueRCode — Session Handoff

> **Read this first when resuming from a cold start.** Current state only, overwritten as the work moves (owner's rule, 2026-11-04/17). History is in `.claude/plans/history/HANDOFF_archive_2026-11-25.md`; what shipped is in `CHANGELOG.md`; decisions are in `docs/decisions/` (one file each, README index); what is and is not done is in the GitHub issues. Cite issue numbers and commit hashes, not line numbers.
> Companion docs: `PROJECT_ROADMAP.md` (master plan), `.claude/plans/new-install-readiness-queue.md` (the live queue to "ready for a new install"), `.claude/plans/7n-admin-commerce-plan.md` (admin and commerce plan), `docs/INSTALL.md` (fresh-install runbook), `LAUNCH_DECISIONS.md`.

## 0. ⚡ LATEST — 2026-11-26

### Where the work is
- **Owner (2026-11-26, decision 0019):** build the later order now, keep this note current (the session may stop).
- **Branch `work-in-progress`:** GitHub is at `e849928`. Local is 11+ commits ahead (all of 7n Phase 2, up to `07a6f55`), NOT pushed because it is unreviewed (decision 0008). Working tree was clean at 2026-11-26 close. Tests: `php tests/run.php` 7513/7513.
- **New-install work is finished** (queue file; Codex loop closed, round 5 clean). Tests then: `php tests/run.php` 6405/6405 on PHP 8.5.12.
- **Not verified anywhere:** a real browser pass on new or changed pages; DreamHost; real Microsoft mail; HTTPS/HSTS; MySQL 8.4 for the newest scenarios; PHP 8.4 + Imagick since round 2.

### In flight (watched)
- **7n Phase 2 (plans, prices, currencies), issue #31**, plan `.claude/plans/7n-admin-commerce-plan.md` §9. Built in slices, each committed locally, one agent at a time:
  - 2a money rules — **built** `87cbaf1` (unreviewed)
  - 2b migration `0004` (plan/price columns, settings, starting prices) — **built** `97cbaf0` (unreviewed). It changed two upgrade-tool/installer rules (plans-table writes; installer repeats data steps): reviewers must check those. `/pricing` shows Enterprise wrongly until 2d.
  - 2c plan and price screens — **built** `30f08cd`, `c853f76` (unreviewed; 7390/7390; real MySQL 8.0 and MariaDB 10.6)
  - 2d public pricing page, preview, no-hard-coded-prices guard, docs — **built** `7bf5603`, `61df78b`, `4d5de13`, `07a6f55` (unreviewed). CHANGELOG, README, install guide and `.OpenAI/` pointers done in `4d5de13`.
  - **Independent Opus review of `c487dab..07a6f55` — started 2026-11-26 about 21:59 UTC in the VS Code session; report not yet in.** It changes nothing in the repository. If that session closes first, the report is lost: start the review again with the same range. It must check the two upgrade-tool/installer rule changes from 2b. Then fixes, Codex review, push `work-in-progress`, comment on #31.
  - Filed from the build, owner choice wanted: #159 (pricing page promises to honour prices for ever, but the promise is configurable), #160 (paid plan with no price shows as Free), #161 (starting plan descriptions advertise unbuilt features).
- If a slice's agent is gone after a restart: `git status` shows its uncommitted files; finish or re-brief that slice only.

### Next (order)
Phase 2 → 3 features → 4 discounts → 5 lifetime deals → 6 pay-as-you-go → 7 housekeeping → dynamic QR (7j) → passkeys/translations/image fills/JPEG XL (7r) → 7m, 7q. Alongside, when possible: real-browser pass; close issues once proven live (#112 #124 #127 #138 #117 #125 #139 #128 #113); #155, #156, #157; the one `work-in-progress → alpha` pull request (owner approval; workflow-file conflict check first).
- Model tiers (decision 0001): gather = Sonnet/Haiku medium; plan = Opus high; build = Sonnet/Haiku medium (money and security = Opus); every check or review = Opus.

### Waiting on the owner
Deploy secrets `SFTP_KNOWN_HOSTS` (org) and `SFTP_REMOTE_BASE` (repo) and variable `SFTP_ENABLED`; re-run the failed secret-scan check on Dependabot PR #152; ask DreamHost about libbrotli (#150); after deploy open `/admin/` and read "Visitor address check" (behind Cloudflare?) and compare the Cloudflare ranges with cloudflare.com/ips; apply the database upgrade `0003` straight after the first deploy; enter the real Microsoft details on `/admin/settings/` and the DreamHost cron job; #136 instruction-audit decisions; #155 should the full tests gate deploy; the 9 legal placeholders (#38); GitHub App for #121; `abuse@` mailbox before dynamic QR; approval to delete the old backup branch and stale Dependabot branches; whether the privacy page should name Cloudflare. The full deduplicated list is in the queue file, section F.

### Standing rules to keep following
Plain English; report at each commit and keep going in the same turn; every owner decision is written to `docs/decisions/` and the issue before moving on; search `docs/decisions/` before asking; owner reserves pushes (except decision 0008), PRs, merges, tags, releases, deletes; set a watchdog on anything that finishes later; Codex review is owed on everything and the owner's no-PR-stacking rule applies (one `work-in-progress → alpha` PR later).

### Cold start
1. `git status`, `git log --oneline origin/work-in-progress..HEAD`. 2. Read the queue file's sections A, B, F. 3. `php tests/run.php`. 4. `gh issue list --state open`.
