# Queue: from today to "ready for a new install"

Written 2026-11-25. Sources: `PROJECT_ROADMAP.md` (hard blockers HB-1..6, epic E10), `LAUNCH_DECISIONS.md` §E/§E1, the open GitHub issues, and `.claude/plans/7n-admin-commerce-plan.md`.
Status: gap report folded in 2026-11-25. Owner answers: libraries are committed (decision 0011); database minimum MySQL 8.0.13+/MariaDB 10.6+ (decision 0012). Model tiers follow decision 0001: plan = Opus high; build = Sonnet/Haiku medium (security core = Opus); every check or review = Opus.

**"Ready for a new install" means:** someone can set up a fresh copy on DreamHost, register, become the first Global Admin, enter the email secrets in the admin screen, turn email on, receive and use a sign-up verification link, and generate a QR code, with the instructions written down and tested on a throwaway database. It does NOT mean public launch (legal review, money-taking and dynamic QR come later).

## A. Finish what is in flight (agent work)
| # | Task | Issue | Builder | Check |
|---|---|---|---|---|
| A1 | Phase 1B: first-admin sets a new password (decision 0010) | #31 | Opus (building) | Opus re-review of all 1B + fixes, then push `work-in-progress` (decision 0008) |
| A2 | Phase 1C: settings editor (enter email secrets, test connection, test email, turn email on) | #31, #29 | Sonnet | Opus |
|  | ↳ **LANDED 2026-11-25, UNREVIEWED** (local commits `bf339c4`, `b98314e`, `7c09680` + docs; not pushed). Opus review still owed. | | | |
| A3 | #128 ask for a new verification link (a lost email must not lock someone out) | #128 | Sonnet | Opus |

## B. Install gaps (agent work; confirmed by the read-only audit of 2026-11-25)
Already fine (no work): Bootstrap/jQuery/FontAwesome fallback copies are committed (#113 item 2 is stale); `install.sql` re-run safety and migrations (1A); installer self-lock; host-key checking and `SFTP_REMOTE_BASE` refusal in `deploy.yml` (#127, #138, #124 look fixed in code but are still open and unproven live); email queue runner exists (#112); first-admin CLI exists; deny-all `.htaccess` on folders outside the web root.
| # | Task | Size | Issue |
|---|---|---|---|
| B1 | Commit the QR/PDF/barcode libraries (decision 0011): un-ignore, add files, make CI check pinned versions instead of cloning, attributions, update CLAUDE.md libraries line | M | #113 |
| B2 | Installer checks `zip`, `bcmath`, `curl` and the database version (decision 0012); README requirements corrected | S | #113 |
| B3 | Commit a credentials template (`.dist` name the ignore rule allows) and fix README | S | #113 |
| B4 | `/install/` is deny-all by default (`install/.htaccess`) but README never says so: write the exact open/close steps; README step 1 (git clone) corrected for the SFTP deploy, with branch folder to domain mapping | S | #113 |
| B5 | Document `SFTP_KNOWN_HOSTS` in DEV_NOTES; decide whether the full test suite should gate deploy | S | #44 |
|  | ↳ **LANDED 2026-11-25, UNREVIEWED.** DEV_NOTES CI/CD documents it; the gate question is written down with a recommendation and opened as #155 (workflow not changed; owner decides). | | |
| B6 | HTTPS redirect: ready-to-switch step in the install guide (still off until go-live) | S | |
|  | ↳ **LANDED 2026-11-25, UNREVIEWED.** `docs/INSTALL.md` step 8 and DEV_NOTES CI/CD: switch-on, rollback, HSTS caution. Redirect itself still off. | | |
| B7 | Installer/app check that `_uploads` and `_backups` are writable | S | #113 |
| B8 | One ordered fresh-install runbook: register, first admin, secrets, email on, verify, generate | S-M | #113 |
|  | ↳ **LANDED 2026-11-25, UNREVIEWED.** `docs/INSTALL.md`, linked from README. Checked against code, not run live (B9 still owed). | | |
| B9 | Fresh-install dry run end to end on a throwaway MySQL and MariaDB 10.6; fix what breaks | M | #13 |
|  | ↳ **DRY RUN DONE, FINDINGS 3, 4, 6b, 7, 8, 9 FIXED 2026-11-25, UNREVIEWED.** Fixed: `site_url` now on `/admin/settings/` (3); `grant --activate` cancels the account's waiting verification/reset emails (4, 6b; INSTALL.md also says the command line is the owner's only way to the first admin); exit-0-can-mean-retry wording in the cron help, INSTALL.md and DEV_NOTES (7); verify page wording for a replaced link (8); no-scope API keys now warned about, default not widened (9); cron help wording about addresses (9). Open: a dry run on a LIVE DreamHost server (the whole guide is still not run there). | | |
| B10 | Predefined brand logos: commit PNG copies or document the Imagick need (#65) | M | #65 |
|  | ↳ **LANDED 2026-11-25, UNREVIEWED.** 26 PNG copies (512 px, transparent) committed beside the SVGs, made with rsvg-convert 2.63.4 via `tools/render_logo_pngs.php`; trademark statement on `/attributions`; `tests/test_predefined_logo_png.php`. Not verified: a real scanner decode, a real browser. Issue #65 left open. The official-logos and retire-Skype items stay with #137. | | |
| B11 | Close or comment stale issues with evidence once verified: #112, #117, #122, #124, #125, #127, #138 (only after a check, never on assumption) | S | |

## C. Owner-only steps (written as one checklist; I cannot do these)
DreamHost: upload `_libraries` (if B1 is not automated), create `_auth_keys/auth_creds.php`, confirm PHP extensions, add the email cron job, create the Microsoft 365 app registration and shared mailbox, set GitHub secrets (`SFTP_*`, `SFTP_REMOTE_BASE`, `SFTP_KNOWN_HOSTS`) and `SFTP_ENABLED`, answer the hosting questions on #150, re-run the failed secret-scan check on `alpha`. Then the live checks #13, #17, #18, #22.

## D. Needed before PUBLIC use, not before an install (queued, after the install gate)
Legal review of Terms/Privacy (#38, owner + counsel); bot protection keys (Turnstile, F-012); HTTPS redirect and HSTS at go-live; dev alpha/beta password lock (DS-1); inline-script tightening (#154); privacy items #129; Codex reviews owed (1A, 1B, 1C and earlier work); one `work-in-progress → alpha` PR (task 9).

## E. After the install gate (owner's build order)
7n Phases 2-7 (plans and prices, features, discounts, lifetime deals, pay-as-you-go, housekeeping) → 7j dynamic + dual-function QR → 7r passkeys, translations, image fills, JPEG XL → 7m prompt-cache add-on → 7q SSO plan wording.

## F. Carried over from earlier sessions (audit of 2026-11-25; sessions named: 2026-10-05 AI-rules/polish session, 2026-11-22 resumed cloud session; two further sessions could not be matched to any record)
Facts: local `work-in-progress` is 30 commits ahead of `origin/work-in-progress` (`b25a3d1`); nothing since 2026-08 has gone through alpha; no stray branches; only open PR is Dependabot #152; `security-propagate.yml` exists only on `main`; `codeql.yml`/`semgrep.yml`/`ci.yml` on WIP lag `main`/`release-candidate`.
| # | Item | Home | Who |
|---|---|---|---|
| F1 | Push local `work-in-progress` after the Opus re-check (decision 0008) | now | lead |
| F2 | Codex review owed for everything since 2026-09-25 (7n 1A-1C, #128, #113 B-tasks, decisions 0010-0015, #151, #137, #138, #145) plus the never-finished chunk 3 (docs/portal/spec/#117/#111). Split into small pieces (decision from 2026-09-25) | after push | Codex or Opus stand-in, recorded as stand-in |
| F3 | Close issues that are built and reviewed, with a commit link and the done label, only after a check: #112 #117 #122 #123 #124 #125 #126 #127 #153 (and #128, #113, #138 once verified) | B11 | lead |
| F4 | Reconcile stale handoff rows: task 7 (superseded), 7a ("waiting to move"), 7i vs 7g (#126 done), Q6 (answered by decision 0007) | handoff rewrite | lead |
| F5 | Plans need a terminal line and a move to a history folder: `112-email-queue-runner.md`, `122-123-126-email-privacy.md`, `codex-review-chunk-1a/1b/2`, `codex-catch-up-review-brief.md` (keep chunk 3 and the 7n plan live) | docs | lead |
| F6 | Before the one `work-in-progress -> alpha` PR (task 9): conflict check on `.github/workflows/*`; bring `security-propagate.yml` and the newer codeql/semgrep/ci across; check release-candidate is in the triggers (#110); after merge the source branch is auto-deleted, recreate WIP from alpha | task 9 | lead |
| F7 | `.OpenAI/` memory refresh owed (last 2026-11-18) | docs | Haiku |
| F8 | #139 monthly watch job for hand-downloaded libraries: now more important because the libraries are committed (decision 0011) | queue | Sonnet |
| F9 | #137 optional follow-ups: official brand logos + retire Skype (overlaps B10/#65), accessibility statement page, Light/Dark/Auto menu, styled confirm dialogs, "why CueRCode" section; live DB settings still hold the old tagline; FontAwesome SRI second source | after install gate | Sonnet |
| F10 | 7m prompt-cache add-on as our own reusable copy (owner 2026-11-22), 7q SSO plan wording (#131), 7r passkeys/translations/image fills/JPEG XL, 7j dynamic + dual-function QR, 7n Phases 2-7 | section E | per plan |
| F11 | #154 admin inline-script tightening; #129/#130 privacy and clock items | section D | Sonnet |

### Owner-only items collected (one list, deduplicated)
Add secrets `SFTP_KNOWN_HOSTS` (org) and `SFTP_REMOTE_BASE` (repo); set `SFTP_ENABLED`; re-run the failed secret-scan check on Dependabot PR #152 (alpha); GitHub App for #121 (7o); `abuse@` mailbox (before dynamic QR is public); ask DreamHost about libbrotli (#150); after deploy open `/admin/` and read "Visitor address check" (behind Cloudflare?) and compare the Cloudflare ranges with cloudflare.com/ips; decide whether the privacy page names Cloudflare; enter the real Microsoft details on `/admin/settings/`; decide #136 (instruction audit report); the 9 legal placeholders (#38); approve deleting the old backup branch and leftover Dependabot branches; the `~/.claude/bin/watchdog.sh` `-L` fix (WebMS-Intra #564) was approved but cannot be verified from here.

Sessions the owner named but that left no trace in the repository, git, the handoff or local Claude records (links starting `cshk_019ejJ4A` and `cshk_013pdKYC`): owner decided 2026-11-25 to treat them as covered by this audit.
F5 done 2026-11-25: finished plans moved to `.claude/plans/history/` with an outcome line.
