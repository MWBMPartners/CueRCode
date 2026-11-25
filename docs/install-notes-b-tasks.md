# Install-readiness B1, B2, B3, B4, B7: notes for DEV_NOTES, CHANGELOG and HANDOFF

Written 2026-11-25 for issue #113. The lead merges these into DEV_NOTES.md, CHANGELOG.md and `.claude/HANDOFF.md`; then this file can be deleted.

## What landed

- **B1 (decision 0011).** The five libraries are committed in `web/CueRCode.net/_libraries/`: chillerlan/php-qrcode 5.0.5, php-settings-container 3.2.1, FPDF 1.86 (commit `0838e0e`, "Update to 1.86"), tc-lib-barcode 2.12.0, tc-lib-color 2.13.1. Only the runtime code and licence files are kept (no tests, docs, examples, nested `.git`). `.gitignore` library rules removed. The old untracked local copy of FPDF was version 1.9 (cloned from the newest commit), which did not match the pin; it is now the pinned 1.86.
- `_libraries/PINNED_VERSIONS.json` holds each library's version and a fingerprint (SHA-256 over every file). `php tools/check_vendored_libraries.php` checks them (exit 1 with plain messages on any difference); `--update` rewrites the fingerprints after a deliberate upgrade. Test: `tests/test_vendored_libraries.php`.
- `ci.yml`: the five `git clone` steps are replaced by one step running that check.
- `/attributions`: tc-lib-barcode/tc-lib-color entry now says source and full licence text ship with the site and links to the pinned tags. Licence files are kept next to each library (`LICENSE`, `LICENSE-MIT`, `LICENSE-ASL-2.0`, `NOTICE`, `license.txt`).
- `.claude/CLAUDE.md` Libraries lines point at decision 0011. The monthly watch (#139) still applies: when it proposes an upgrade, replace the folder, run `--update`, review the diff.
- **B2 (decision 0012).** `_functions/fn_install_checks.php` holds the testable rules. The installer now checks `mysqli, mbstring, gd, json, openssl, zip, bcmath, curl` with a plain-words purpose for each, and, after connecting but before running any SQL, refuses MySQL below 8.0.13 and MariaDB below 10.6 (also handles the `5.5.5-` MariaDB prefix and refuses a version it cannot read). Test: `tests/test_install_checks.php`. MariaDB 10.6 itself has still not been run against (B9).
- **B3.** `web/CueRCode.net/_auth_keys/auth_creds.example.php.dist` (placeholders only; `git check-ignore` confirms it is not ignored while `auth_creds.php` still is). Test: `tests/test_credentials_template.php`.
- **B4.** README install section rewritten: SFTP deploy (not a clone), branch to public folder table, exact steps to open and close `install/.htaccess`, minimum versions, test counts.
- **B7.** The installer's prerequisites list also checks `_uploads` and `_backups` exist and are writable, with plain messages (helper `installCheckWritableFolder`).

## Runbook note (for B8)

Step order for a fresh install: deploy; create `auth_creds.php` from the `.dist` template; open `install/` by renaming `install/.htaccess` to `.htaccess.off`; run the installer (all rows green); rename it back or delete `install/`. Each later deploy re-uploads `install/` with its deny-all `.htaccess`, so confirm it is in place.

## Limits and open points

- The installer page itself was syntax-checked and its logic unit tested; it was not clicked through in a browser or run against a live database in this task (B9 covers the end-to-end dry run).
- DreamHost's real hostnames for alpha and beta are not recorded in the repository, so the README says to choose them in the panel (open question on #150).
- The deploy re-uploads `install/` every time. A follow-up could exclude it after first install; not done (not asked).
