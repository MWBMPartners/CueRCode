# Launch round 3: independent review of c95d1a5..35e87c2

Reviewer: Opus (independent; wrote none of this). 2026-11-28. Not committed, on purpose.
Reviewed from `git archive 35e87c2` and a scratch clone at 35e87c2, both in the reviewer's scratch folder. Nothing in the repository was changed except this file.
Note: while this review ran, another session changed the repository (HEAD moved to 39220cd, and there are uncommitted edits to the CSP and page files). None of that is mine, and none of it is reviewed here.

Normative documents checked: decisions 0025, 0037, 0038, 0040, 0041, 0042; `.claude/CLAUDE.md` code standards; `.claude/plans/7n-phase2-codex-report.md` and `.claude/plans/163-merged-review-report.md`.

## Result: no High. One Medium, three Low, four Info.

## Evidence (all run during this review)
- Unit suite, macOS PHP 8.5.12: 55 files, 9197 assertions, all pass in the exported copy; 9198 in a git clone (one check depends on git being present, so the documented 9198 is right). PHP 8.6 scan: 0 findings. Polish check: 0 problems.
- `tools/ci/run-integration-tests.sh` on macOS PHP 8.5.12: all 8 scripts pass on Docker MySQL 8.0.36, MySQL 8.4.13 and MariaDB 10.6.28.
- The same script in a Linux PHP 8.4.27 container (Debian, util-linux `script`, user 1000, CI's `error_reporting=-1`, `display_errors=On`): all 8 pass on all three servers. Check counts: account recovery 192, admin access 167, admin plans 169, admin settings 232, email queue 77, human check 171, migrations 215, resend verification 32. These match the documents.
- 20 protections removed one at a time in a scratch copy and run through the unit suite. The 7 that survived were then run through the matching real-database script.

## Earlier findings, rechecked now
- Codex 7n Phase 2 report: 1.1 (any ISO currency; CAD and KWD accepted), 1.2 (a request missing the date boxes is refused), 2.P1 (the installer refuses), 2.P2 (exact-byte compare), 3.3, 3.4 and 3.5 (pricing): closed. Each is covered by tests that fail when the protection is removed (mutation M06, plus the existing runs).
- Merged review 163: M1 (same answer) is closed over real HTTP (scenario M: status, headers, page, the later page, Continue, Start again, cookies and timing all match). L1, L3, L4, L5, L6, I1, I2, I3 and I5 are closed. L2 is closed for the mailbox allowance, but it reopened as the lock-out problem below.

## Findings

### MEDIUM 1: anyone can keep any account locked out, from one internet address, with no human check
- Where: `_functions/fn_no_script_route.php` `noScriptSignInStartWork()` (the gate is called with `$reserveEmail = false`, then `loginUserCheckPassword()` runs), and `_functions/fn_auth.php` `recordFailedLogin()` (5 failures lock the account for 15 minutes, and the count never goes down on its own).
- How to reproduce: run the reviewer's `probe_lockout.php` against MySQL 8.0.36. Five no-JavaScript sign-in posts with wrong passwords from ONE address (203.0.113.50, inside the default limit of 5 an hour) give "failedLoginCount=5 locked=1". The real person's RIGHT password from their own address then gets no email, and the normal sign-in (after a human check) says "Invalid email or password, or the account is not yet verified." When the 15 minutes are up, ONE more wrong post locks the account again (count 6). So one address sending about 4 posts an hour keeps one person locked out for good. A few addresses can do the same to many people.
- Why it is new: before the L2 fix, the per-mailbox limit (3 a day) was checked before the password, so the route could add at most 3 failures a day to one account. The fix moved that limit after the password. Now only the per-address limit applies to wrong guesses, and the JSON sign-in and the normal form both need a human check.
- Documents that are now untrue: DEV_NOTES "guessing through this route stays bounded by the lock-out" and "a stranger's wrong guesses use up nothing of the person's"; INSTALL.md "The normal form with JavaScript is not affected".
- Fix: the route already gives the same answer whatever happens, so it can quietly stop counting. Keep a per-mailbox count of WRONG passwords tried through the route (for example 3 a day, stored with `emailHash`). Over that count, skip `recordFailedLogin()` (or skip the password check altogether) and still give the same answer. The lock-out from the normal form stays as it is. Add a real-HTTP check: 6+ wrong route posts from one address, then the right password through the normal form still signs in.

### LOW 2: the deploy gate fails every time if work-in-progress is squash-merged or rebase-merged
- Where: `tests/integration/migrations_real_mysql.php` runs `git show 76b1af7:web/CueRCode.net/_includes/sql/install.sql`. `deploy.yml` and `ci.yml` use `fetch-depth: 0`.
- How to reproduce: commit 76b1af7 is not in origin/alpha, origin/beta or origin/main today (`git merge-base --is-ancestor` says NOT for each). The reviewer cloned alpha only and committed the 35e87c2 tree on top (what a squash merge produces). Running the script then gave exit 2: "could not read the pre-task-7n install.sql from git history (commit 76b1af7) ... fatal: invalid object name '76b1af7'".
- What it means: alpha's history shows both merge commits (#78-#83) and squash merges (Dependabot), so both methods are switched on. After a squash or rebase merge, the source branch is deleted automatically, the commit can no longer be reached, and every deploy is blocked until someone works out why. That is the safe direction (nothing broken is uploaded), but the gate stays shut.
- Fix: commit that old install.sql as a test file (for example `tests/integration/fixtures/install_76b1af7.sql`, with a header saying where it came from) and read it from there. Or record a rule "merge work-in-progress into alpha with a merge commit only" and switch off squash and rebase for that pull request. The first is sturdier.

### LOW 3: two protections are not tested by anything
- A. The "already done" check of `widen_decimal_column` (`migrationStepExistsQuery()`, the whole-number-digits part): with `(NUMERIC_PRECISION - NUMERIC_SCALE) >= ?` replaced by a check that always passes, the unit suite (9197/9197) and `migrations_real_mysql.php` (exit 0, no FAIL) both pass. In that broken state, a column already at DECIMAL(13,3) (10 whole digits) would be skipped as done. Large typed prices would then be refused by the database (strict mode, so loudly, not silently). Fix: in scenario P, set one column to DECIMAL(13,3) by hand and expect it to be widened to (15,3).
- B. `fn_pricing.php` `listPublicPlansForDisplay()`: the rule that keeps price points off the Free plan's card. With it removed, the unit suite and `admin_plans_real_mysql.php` both pass. No page shows `pricePoints` today, so nothing visible changes yet. Fix: in admin_plans scenario N, switch `billing_price_points_enabled` on and check that the Free card has no price points.

### LOW 4: the database guard trusts what the account is allowed to see
- Where: `tools/ci/integration_db_guard.php` uses `SHOW DATABASES`, which lists only the databases the account has rights to.
- How to reproduce: on the reviewer's MariaDB container, with a `cuercode_live` database present and an account granted only `cuercode\_it\_%`, the guard printed "(throwaway: no other databases)" and exited 0. As root, the same server is refused (exit 2), and a host that is not this machine is refused (exit 2).
- What it means: the guard's promise is wider than what it actually checks. The harm is small, because every script also refuses any database not named `cuercode_it_...`, and the scripts' other server-wide step (admin_access's `CREATE USER`) fails without that right.
- Fix: also require the account to see every database. For example, refuse unless `INFORMATION_SCHEMA.USER_PRIVILEGES` lists `SHOW DATABASES` (or `ALL`) for the current user, and say in the guard's header that it relies on this.

## Info
- I1: `moneyMinorToDecimalString()` has a "the column would round this" refusal that cannot be reached today, because no ISO currency has more than 3 decimal places. Removing it passes every test. That is fine, but no test can prove it until such a currency exists.
- I2: a `widen_decimal_column` step's SQL is free after `NOT NULL`, so for example `INVISIBLE` passes the checks and runs on MySQL 8.4 (MariaDB refuses it). The reviewer tried `AS (0) STORED` and `GENERATED ALWAYS AS (0) STORED` on both servers: both are syntax errors there, so no step can wipe values. Steps are written by trusted developers, so this is acceptable.
- I3: decisions 0040, 0041 and 0042 still say "Implemented in: not yet built", but all three are built (4cdb38d, ef21eb2, cbf96eb). The register's rule is "never edit one", so the index (README "Current") or a note in the issue should carry the commit.
- I4: the runner leaves `cuercode_it_ci_account_recovery`, `cuercode_it_ci_email_queue` and `cuercode_it_ci_resend_verification` on the server afterwards. That is harmless on a throwaway server, and the guard allows them.

## What is sound (checked now)
- Upgrade 0006: widening only. It keeps existing values exactly (scenario P on all three servers), finishes after a half-run, refuses a hand-widened DECIMAL(20,2) or a column made nullable by hand without changing it (mutation M18 is caught by the real run), and it is not recorded when a step is refused. Widening never cuts digits, in strict or non-strict mode. The reviewer showed that the old DECIMAL(10,2) columns round 1.234 to 1.23 silently even in strict mode, and that non-strict mode clamps a value that is too large to 99999999.99. But every write of money is on admin screens that refuse while any upgrade is waiting (`adminDatabaseReadyForChanges()`), and those are the only places that write money, so the gap between upload and upgrade cannot lose data.
- Money code: parsing, the 12-digit limit, reading "6.990" back, and refusing "6.995" GBP are all exercised directly. The overflow reasoning holds on 64-bit PHP.
- Free plan (0041): the editor, a new version under the key `free`, the copy into the older columns, and the pricing card all refuse or ignore a price (M07 and M08 are caught).
- No-JavaScript route: the same answer over real HTTP. Unpadded timing differs by about 3 ms (right 63.8 ms, wrong 60.7 ms, median of 8, Argon2id verify 57.6 ms), well inside the 0.4 s minimum. Session contents are the same shape. Continue and Start again are the same. Concurrency is exact under the lock (12 at once, exactly 3). Removing the claim, the stand-in session secret or the per-address gate is caught (M02, M03, M19). The approval limit and the release of unused places are caught (M10, M11).
- Deploy gate: `deploy` needs `php-checks`, `tests` and `integration`. The integration job has no continue-on-error, and every database blocks. No other workflow uploads anything. The guard runs before any script. The shared script fails on a missing script, a missing guard, a guard refusal, no scripts run, a failed script or deprecation text (M13 and M20 are caught). The runner works on Linux with util-linux `script` and PHP 8.4 with CI's settings.

## Could not check
- GitHub itself: whether the runner accepts `ports: '127.0.0.1:3306:3306'` (Docker does), whether setup-php's `mysqli` loads, and how long the service containers take to start (the guard waits up to 180 s). Only the first real push to a tier branch can prove this.
- The repository's merge settings on GitHub (not asked, so that nothing was sent to outside services).
- DreamHost: real Argon2 timing, its sql_mode, and real email. Real browsers and real human-check providers. PHP 8.6.
- The Codex cross-review (still owed under the project rules).
