# Queue: from today to "ready for a new install"

Written 2026-11-25. Sources: `PROJECT_ROADMAP.md` (hard blockers HB-1..6, epic E10), `LAUNCH_DECISIONS.md` §E/§E1, the open GitHub issues, and `.claude/plans/7n-admin-commerce-plan.md`.
Status: gap report folded in 2026-11-25. Owner answers: libraries are committed (decision 0011); database minimum MySQL 8.0.13+/MariaDB 10.6+ (decision 0012). Model tiers follow decision 0001: plan = Opus high; build = Sonnet/Haiku medium (security core = Opus); every check or review = Opus.

**"Ready for a new install" means:** someone can set up a fresh copy on DreamHost, register, become the first Global Admin, enter the email secrets in the admin screen, turn email on, receive and use a sign-up verification link, and generate a QR code, with the instructions written down and tested on a throwaway database. It does NOT mean public launch (legal review, money-taking and dynamic QR come later).

## A. Finish what is in flight (agent work)
| # | Task | Issue | Builder | Check |
|---|---|---|---|---|
| A1 | Phase 1B: first-admin sets a new password (decision 0010) | #31 | Opus (building) | Opus re-review of all 1B + fixes, then push `work-in-progress` (decision 0008) |
| A2 | Phase 1C: settings editor (enter email secrets, test connection, test email, turn email on) | #31, #29 | Sonnet | Opus |
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
| B6 | HTTPS redirect: ready-to-switch step in the install guide (still off until go-live) | S | |
| B7 | Installer/app check that `_uploads` and `_backups` are writable | S | #113 |
| B8 | One ordered fresh-install runbook: register, first admin, secrets, email on, verify, generate | S-M | #113 |
| B9 | Fresh-install dry run end to end on a throwaway MySQL and MariaDB 10.6; fix what breaks | M | #13 |
| B10 | Predefined brand logos: commit PNG copies or document the Imagick need (#65) | M | #65 |
| B11 | Close or comment stale issues with evidence once verified: #112, #117, #122, #124, #125, #127, #138 (only after a check, never on assumption) | S | |

## C. Owner-only steps (written as one checklist; I cannot do these)
DreamHost: upload `_libraries` (if B1 is not automated), create `_auth_keys/auth_creds.php`, confirm PHP extensions, add the email cron job, create the Microsoft 365 app registration and shared mailbox, set GitHub secrets (`SFTP_*`, `SFTP_REMOTE_BASE`, `SFTP_KNOWN_HOSTS`) and `SFTP_ENABLED`, answer the hosting questions on #150, re-run the failed secret-scan check on `alpha`. Then the live checks #13, #17, #18, #22.

## D. Needed before PUBLIC use, not before an install (queued, after the install gate)
Legal review of Terms/Privacy (#38, owner + counsel); bot protection keys (Turnstile, F-012); HTTPS redirect and HSTS at go-live; dev alpha/beta password lock (DS-1); inline-script tightening (#154); privacy items #129; Codex reviews owed (1A, 1B, 1C and earlier work); one `work-in-progress → alpha` PR (task 9).

## E. After the install gate (owner's build order)
7n Phases 2-7 (plans and prices, features, discounts, lifetime deals, pay-as-you-go, housekeeping) → 7j dynamic + dual-function QR → 7r passkeys, translations, image fills, JPEG XL → 7m prompt-cache add-on → 7q SSO plan wording.
