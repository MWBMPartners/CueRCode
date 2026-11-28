# CueRCode — Characterization Test Suite

A minimal, **dependency-free** PHP test harness that captures the **current**
behaviour of CueRCode's DB-independent functions. There is no Composer / PHPUnit
on the Dreamhost shared host, so this is a hand-rolled runner that needs nothing
but the PHP CLI.

> 🎯 **Purpose of this suite is a SAFETY NET, not bug-fixing.** Tests assert the
> behaviour the code has *today* (even if a given behaviour is arguably wrong),
> so that a future change which alters that behaviour is caught. If you find a
> bug, characterize the **current** behaviour and note it for a later cycle —
> do **not** change production code under `web/CueRCode.net/` to make a test
> "look right".

Everything lives under repo-root `tests/` (outside `web/`), so it is **never
deployed**.

## Running the tests

From the **repository root**:

```bash
php tests/run.php
```

Run only the test files whose name contains a given substring:

```bash
php tests/run.php --filter=encryption
php tests/run.php --filter=payload
```

The runner:

- discovers every `tests/test_*.php` (sorted),
- prints a per-file `PASS` / `FAIL` line plus a coloured grand-total summary,
- exits **0** when all assertions pass (or there are zero tests),
- exits **1** when any assertion fails (so CI can gate on it).

Colour is auto-disabled when output is piped/redirected or when `NO_COLOR` is
set.

## The real-database tests run automatically (decision 0040, #174)

`run.php` never opens a database, so the scripts in `tests/integration/` are
run separately: GitHub runs every `tests/integration/*_real_mysql.php` in the
`integration` job of `ci.yml` (pull requests and pushes) and of `deploy.yml`
(the upload waits for it), on throwaway **MySQL 8.0.36**, **MySQL 8.4** and
**MariaDB 10.6** servers, with PHP 8.4. Both use one script:

```bash
CUERCODE_IT_DB_HOST=127.0.0.1 CUERCODE_IT_DB_PORT=3306 \
CUERCODE_IT_DB_USER=root CUERCODE_IT_DB_PASS= \
  bash tools/ci/run-integration-tests.sh
```

It refuses (through `tools/ci/integration_db_guard.php`) any server that is
not on this machine, an account without the server-wide `SHOW DATABASES`
right (it could not see every database), or a server that holds a database
other than the server's own and `cuercode_it_` ones, gives each script its own
`cuercode_it_ci_<script>` database and removes it afterwards, and fails when a required script is missing, none ran, any failed,
deprecation text appeared or part of a script could not run. To run it by hand
the same way GitHub does, start an empty throwaway server (for example
`docker run -d --rm --name cuercode-it-ci -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -p 127.0.0.1:3306:3306 mysql:8.4`;
for MariaDB `-e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 mariadb:10.6`), run the
command above, then `docker stop cuercode-it-ci`. The sections below describe
each script and how to run one on its own.

## Real-database check (link safety and takedowns, #168)

`tests/integration/dynamic_qr_abuse_real_mysql.php` creates and drops its own `cuercode_it_` database and checks, with scripted stand-ins for Go2My.Link and Google Web Risk (nothing leaves the machine): the check at create and change (listed = refused and recorded; no answer = let through and logged once), Google's caching rule, the daily re-check (suspends, emails the owner and the abuse mailbox, a system row in the admin change log; nothing sent without a declared site copy or without a key; stops early when Google is down), a takedown Go2My.Link could not take being sent again by the cron step, and an owner's change that lands after the takedown. Run it with the usual `CUERCODE_IT_DB_*` variables; the shared script includes it.

## Real-database check (dynamic QR flows, #166)

`tests/integration/dynamic_qr_real_mysql.php` creates and drops its own `cuercode_it_` database and runs the dynamic-code flows on it (127 checks, scenarios A-N): who may make a code, what counts towards the limit, six processes at once against a limit of 3 (a trigger slows each insert to widen the race), the busy lock, lost answers and the cron tidy step (including its refusal without `CUERCODE_SITE_COPY`, #181), taken codes, changes and switching off and on, which pictures may be drawn or downloaded, the admin switch, the real `/admin/limits/` page in a child process, real HTTPS against `tests/lib/go2mylink_standin_server.php`, and the real HTTP routes through PHP's built-in web server (whose curl trusts only the stand-in's throwaway certificate authority). Nothing is sent to the real Go2My.Link. Run it with the usual `CUERCODE_IT_DB_*` variables; the shared script includes it.

## Real-database check (site time zone, #158, #130)

`tests/integration/site_timezone_real_mysql.php` runs the real link, session,
lock-out and API-key checks with PHP's clock in UTC, America/New_York,
Europe/London, Pacific/Kiritimati and Pacific/Pago_Pago, each half an hour
before and half an hour after the limit (100 checks). Run it with
`--load-schema` and the usual `CUERCODE_IT_DB_*` variables; the shared script
includes it. On 2026-11-28 it failed 23 checks on the old code and passed all
100 afterwards, on MySQL 8.0.36 and MariaDB 10.6.28 (PHP 8.5.12, macOS).

## Activity-log email addresses (#129)

`tests/test_email_log_privacy.php` checks the rule that removes an address
from one log entry; `email_queue_real_mysql.php` (scenario 12b) checks the
time limits and `account_recovery_real_mysql.php` (section CX) checks account
erasure, on a real database.

## Admin script policy (#154)

`tests/test_admin_csp.php` fails if the admin policy allows inline scripts
again, or if a page the admin area can show contains one.

## Current suite (2026-11-28, link-safety check and takedowns #168: the table below was re-measured; before that 2026-11-28, dynamic QR flows #166 and the round-six fixes #181: the table below was re-measured; before that 2026-11-28, dynamic QR groundwork #164/#165; before that 2026-11-28, Codex review fixes of task 7n Phase 2; before that 2026-11-28, human check #163 review fixes and decisions 0037/0038; before that 2026-11-27, human check #163; before that 2026-11-26, task 7n Phase 2: slices 2a to 2d; earlier rounds are in git history)

Measured with `php tests/run.php` on PHP 8.5.12 (macOS), all passing. After round 2 the same suite also passed on PHP 8.4.27 with Imagick (ImageMagick 7.1.2, Alpine in Docker, run as a normal user): 6355/6355 (not repeated after round 3) - a few checks differ by machine (for example `test_qr_logo.php` takes its Imagick branch there, 205 checks). Counts on PHP 8.5.12:

| File | Assertions |
| --- | --- |
| `test_accessibility.php` | 43 |
| `test_account_data.php` | 116 |
| `test_account_recovery.php` | 119 |
| `test_account_ui.php` | 82 |
| `test_admin_access.php` | 680 |
| `test_admin_csp.php` | 50 |
| `test_admin_plans.php` | 665 |
| `test_admin_settings.php` | 1513 |
| `test_api_keys.php` | 130 |
| `test_api_parity.php` | 76 |
| `test_auth.php` | 62 |
| `test_barcode.php` | 120 |
| `test_braille.php` | 69 |
| `test_bulk.php` | 85 |
| `test_client_ip.php` | 260 |
| `test_commercial_literals.php` | 9 |
| `test_core.php` | 146 |
| `test_credentials_template.php` | 9 |
| `test_curl_content_decoding_guard.php` | 3 |
| `test_deploy_test_gate.php` | 109 |
| `test_dynamic_qr_flows.php` | 173 |
| `test_dynamic_qr_schema.php` | 117 |
| `test_dynamic_qr_takedown.php` | 52 |
| `test_dynamic_redirect_s0.php` | 96 |
| `test_dynamic_redirect_s1.php` | 43 |
| `test_email.php` | 69 |
| `test_email_exact_match.php` | 18 |
| `test_email_log_privacy.php` | 15 |
| `test_email_queue_runner.php` | 510 |
| `test_emoji_logo.php` | 123 |
| `test_error_catalogue.php` | 158 |
| `test_expiry_clock.php` | 6 |
| `test_features.php` | 91 |
| `test_full_notation.php` | 38 |
| `test_go2mylink.php` | 503 |
| `test_human_check.php` | 410 |
| `test_human_check_fallbacks.php` | 129 |
| `test_human_check_review_fixes.php` | 84 |
| `test_human_check_widget_script.php` | 9 |
| `test_install_checks.php` | 101 |
| `test_installer_load_order.php` | 9 |
| `test_integration_web_server.php` | 15 |
| `test_library_updates.php` | 177 |
| `test_log_mask.php` | 73 |
| `test_login_next_url.php` | 21 |
| `test_migrations.php` | 584 |
| `test_money.php` | 709 |
| `test_no_script_route_review_fixes.php` | 80 |
| `test_oauth.php` | 136 |
| `test_offline_worker_privacy.php` | 16 |
| `test_openapi_render.php` | 115 |
| `test_payloads.php` | 138 |
| `test_php86_adoption.php` | 34 |
| `test_php86_compat_scan.php` | 76 |
| `test_php_lint_script.php` | 6 |
| `test_php_runtime_guards.php` | 24 |
| `test_plans_prices_migration.php` | 168 |
| `test_predefined_logo_png.php` | 264 |
| `test_pricing.php` | 132 |
| `test_pricing_flex.php` | 51 |
| `test_pricing_page.php` | 163 |
| `test_qr_logo.php` | 204 |
| `test_qrcodes.php` | 83 |
| `test_resend_verification.php` | 71 |
| `test_sessions_valid_from.php` | 83 |
| `test_url_reputation.php` | 134 |
| `test_vendored_libraries.php` | 15 |
| **Total** | **10672** |

Run `php tests/run.php` at any time to reproduce this table live.

**Fourth-round review and second Codex review fixes (2026-11-28)** added three files: `test_email_exact_match.php` (18 checks: an email address is matched byte for byte, and no bare `email = ?` comparison is left in the site code), `test_offline_worker_privacy.php` (16: the offline worker never keeps a no-store or private page, `/admin` and `/install` are private, the cache version moved on) and `test_human_check_widget_script.php` (9: the real widget script under Node.js - a late provider answer cannot stop the fallback; skipped with a note where Node.js is missing). Checks were added to `test_admin_csp.php`, `test_log_mask.php`, `test_integration_web_server.php`, `test_human_check_fallbacks.php`, `test_human_check.php`, `test_pricing_page.php` and `test_migrations.php`, and old wording checks were updated in `test_account_recovery.php`, `test_admin_access.php`, `test_auth.php`, `test_admin_settings.php` and `test_human_check_review_fixes.php`. Real-database scripts gained scenario EX (`account_recovery`), 12d and the Low 3 check (`email_queue`), the look-alike spellings and eleven right sign-ins in R plus three outage checks in F (`human_check`), and P7-P9 (`migrations`). Each was shown failing on the code before its fix. Suite: **9470** in 62 files.

**Dynamic QR flows (2026-11-28, WP4c #166) and round-six fixes (#181)** added `test_dynamic_qr_flows.php` (173: which codes may be drawn, suspended codes, who may make codes, short-link destinations, the switched-off page, the link-safety hook, the change planner, the tidy step's choice, member answers, the drawing permission, and source checks of the order of the create and change steps), more of `test_go2mylink.php` (503: exact code and short-address matches, the stored destination form, the declared site copy, and the two protections that had no test), `test_admin_settings.php` (the switch is no longer locked; switching on needs a passing test) and `test_error_catalogue.php` (13 new codes; fn_redirects.php scanned). New real-database script `dynamic_qr_real_mysql.php` (127 checks). The new file failed 30 of 35 checks before the code existed; the #181 checks failed on the old code; removing each protection made a check fail. Suite: **10450** in 65 files.

**Dynamic QR review fixes (2026-11-28, H1, M1-M3, L1-L11; decisions 0045, 0046)** added checks to `test_go2mylink.php` (now 469: a link counts only on a confirmed short domain, found links switched off or pointing elsewhere, broken answers after sending, the alpha/beta block, the write-permission wording, ping must say pong, the 64 KB limit while reading), `test_dynamic_qr_schema.php` (117: the sync state word, raise-only limits, the abuse-report table for decision 0046, no published hash key), `test_admin_settings.php` (the key never follows the API address to another website; the abuse-report period) and `test_email_queue_runner.php` (the abuse-report clean-up). The stand-in server gained the scenarios `slowurl`, `garbled`, `nostatus`, `bigheaders` and `badlength`. Real-database parts: `email_queue_real_mysql.php` scenario 12e and more of `admin_settings_real_mysql.php` section Q (raise only, overdue payment, unknown limit name, not an admin, an audit row that cannot be written). Each was shown failing on the code before its fix, and removing each protection again makes a check fail. Suite: **10190** in 64 files.

**Dynamic QR groundwork (2026-11-28, #164, #165)** added `test_go2mylink.php` (389 checks, about 10 seconds) and `test_dynamic_qr_schema.php` (97). `test_go2mylink.php` starts `tests/lib/go2mylink_standin_server.php`, a small HTTPS server on 127.0.0.1 that answers like Go2My.Link's API, with a throwaway certificate authority from `tests/lib/test_tls.php` (PHP's own OpenSSL functions; nothing installed). It proves success, 403, 409, 422, 429 (Retry-After), a time limit, a bad certificate (no request reaches the server), a web page or wrong-shaped JSON, a reply too large, a redirect not followed and the safe retry, then stops the server (closing its input stops it) and deletes the certificates. Nothing is sent to the real Go2My.Link. Both files failed first (the code did not exist yet); weakening the certificate check and following redirects in the code made 3 checks fail. Real-database parts: `migrations_real_mysql.php` scenario Q (upgrade 0007) and `admin_settings_real_mysql.php` section Q. Suite: **9972** in 61 files.

**Test web servers always stopped (2026-11-28)** added `test_integration_web_server.php` (13 checks, about 10 seconds): with a real `php -S` server on a free port, the helper `tests/lib/web_server.php` stops it when the script calls `itWebServerStop()`, exits with a failure code, throws, stops on a fatal error, or is sent SIGTERM, SIGINT or SIGKILL; a control case starts the server the old way and shows it still running after its script is killed (then stops it); and every `*_real_mysql.php` script that starts a server must use the helper. On the old `human_check_real_mysql.php` those last checks failed (3), and killing that script with kill -9 left its server running (process 43974 in the run on 2026-11-28); after the change kill -9, SIGINT and SIGTERM each left none. Suite: **9377** in 59 files.

**#179: time limits for the error log and visitor details, no email address in the error log (2026-11-28)** added 17 checks to `test_email_queue_runner.php` (the two new clean-up steps with the default 90 days, admin periods, bad periods falling back to 90, no clean-up without the lock, a failing step reported, the report lines, the two steps never naming the admin change log and never deleting other activity entries; the four option checks now include the two periods), 4 to `test_email_log_privacy.php` (the two admin-editable settings; the privacy page states both periods) and 10 to `test_admin_settings.php` (the registry's own checks for each new field; the two may lack a seeded row). On the old code 12 runner checks and 4 privacy checks failed. `email_queue_real_mysql.php` gained scenario 12c (7 checks): an error-log row 91 days old deleted and one 89 days old kept; an activity entry 91 days old kept without its address and browser name, one 89 days old untouched; 30-day admin periods apply; the run reports what it removed; and a real `logError()` call with addresses in the title, detail, backtrace, page address, Referer header and extra data stores only `[address]` - on MySQL 8.4.13 that check FAILED before the masking change (every address was stored). The admin change-log part of 12c runs only where that table exists (this script loads `install.sql` alone, without upgrade 0002); the unit code check covers it there. Suite: **9364** in 58 files.

**#178: account page dates in the site's time zone (2026-11-28)** added 12 checks to `test_account_ui.php` (`siteFormatDatabaseTime()` across Tokyo, New York, London summer time and UTC; nothing shown for an unreadable or impossible value; an unknown zone falls back to UTC; the page uses the helper for its four dates and no longer calls `strtotime()`). On the old code 3 failed (the helper did not exist; the page still used `strtotime()`). Suite: **9331** in 58 files.

**#177: per-address limits count an IPv6 address as its /64 (2026-11-28)** added 14 checks to `test_client_ip.php` (the shared `clientAddressCountingKey()` rule, and that the request log, the API's anonymous limit, the emailed-link and form limits, their lock and the no-JavaScript route all use it); `test_no_script_route_review_fixes.php` now checks the route uses the shared rule instead of its own (6 checks moved). On the old code 7 of the new unit checks failed. `account_recovery_real_mysql.php` grew to 203 checks with scenario V6 (ten forgot-password requests from ten addresses in one /64, then an eleventh: refused; another /64 is let through and its reset record keeps the real address; the API's anonymous limit is shared by one /64; IPv4 recorded as itself): 4 failed on the old code on MySQL 8.4.13. Suite: **9319** in 58 files.

**Third-round review, Low 2-4 and Info 1, 2, 4 (2026-11-28).** Low 2: `migrations_real_mysql.php` reads the old `install.sql` from the committed copy `tests/integration/fixtures/install_76b1af7.sql` (pinned SHA-256; compared with commit `76b1af7` where history holds it). Shown failing first in a copy of the tree committed on its own (as a squash merge leaves it): the old script refused, exit 2, "invalid object name '76b1af7'"; the new one passed there ("comparison skipped") and in the real clone ("identical to commit 76b1af7"), and refused a copy with one byte added (exit 2). Low 3: new P6 in `migrations_real_mysql.php` (a column already DECIMAL(13,3) is widened, not skipped) and N3b in `admin_plans_real_mysql.php` (with price points switched on, the Free card has none; Business keeps its own). Each failed with its protection removed in a scratch copy (P6: 5 steps run, the column left at decimal(13,3); N3b: the Free card carried 1 price point) and passed without; migrations now 216 checks, admin plans 170. Low 4: 7 checks in `test_deploy_test_gate.php` (`cuercodeItGuardPrivilegeProblems()`, `cuercodeItGuardGrantee()`); on MySQL 8.0.36, MySQL 8.4.13 and MariaDB 10.6.28 an account granted only `cuercode\_it\_%`, with a `cuercode_live` database on the server, was accepted by the old guard (exit 0) and is refused by the new one (exit 2); root is still accepted. Info 1: 6 checks in `test_money.php` (`moneyMinorFitsStoredPlaces()` with a made-up 4- and 6-decimal currency). Info 2: 8 checks in `test_migrations.php` (INVISIBLE, a computed column, a text default and a word after COMMENT refused; the allowed shapes still valid). Info 4: 13 checks in `test_deploy_test_gate.php` (the runner asks the guard to remove every script's database; a bad name to remove is refused). Suite: **9310** in 58 files.

**Third-round review, Medium 1: the no-JavaScript route can no longer lock an account (2026-11-28)** added 10 checks to `test_no_script_route_review_fixes.php` (the per-account number of password checks and its bounds, the route checking passwords without counting a wrong one, the stand-in check past the number, the normal sign-in still counting), 3 to `test_admin_settings.php` (the new setting's bounds; it may lack a seeded row) and changed one in `test_human_check_fallbacks.php` (the defaults). `human_check_real_mysql.php` grew to 179 checks: new scenario R (five wrong route posts from one address, then the per-address limit, thirteen in all: never counted, never locked; only 10 checked; the right password through the NORMAL form then signs in; the normal form still locks after five) and a race in J (twelve sign-ins at once, password checks set to 3: exactly 3 checked); K, M and N now expect wrong route passwords NOT to be counted. On the code before the fix, on MySQL 8.0.36, K, N, R and J failed - the right password through the normal form got HTTP 200 "Invalid email or password" after five wrong route posts. Suite: **9277** in 58 files.

**Admin status wording during an outage (2026-11-28)** added 6 checks to `test_human_check_fallbacks.php`: the status lines in both outage modes and the "refuse" choice say the email-confirmed route for visitors without JavaScript stays open (it asks no provider); 5 of them failed on the old wording. Suite: **9198**.

**Three decimal places and larger prices (#176, decision 0042, 2026-11-28)** changed and added checks in `test_money.php` (all seven three-decimal currencies offered, the new limit 999,999,999,999 and why it cannot overflow, the DECIMAL(15,3) text such as "6.990" read back, the export text), `test_migrations.php` (the new `widen_decimal_column` step: valid shape, every refused shape, skip/run/refuse decisions, and upgrade 0006 widening exactly the DECIMAL columns install.sql has), `test_admin_plans.php`, `test_pricing.php` and `test_pricing_page.php` (KWD prices, "6.990" in every display path, "From KWD 99"). On the code before the change 36 of them failed, with `test_money.php` and `test_migrations.php` stopping early at a function that did not exist yet. Suite: **9192**. Real databases (Linux PHP 8.4.27 container, non-root): `migrations_real_mysql.php` with the new scenario P and `admin_plans_real_mysql.php` (169 checks) with the new scenario O passed on MySQL 8.0.36, MySQL 8.4.13 and MariaDB 10.6.28, with every other script; on the old code they failed (on MySQL 8.4: KWD refused, KWD 2.125 not stored, the columns still DECIMAL(10,2), and the migrations script stopping where it loads 0006). The first MariaDB run of scenario P failed one check because MariaDB reports a column's missing default as the text NULL where MySQL gives a real NULL; the test now reads both as "none" (a test fault, not an upgrade fault).

**The Free plan can never have a price (#175, decision 0041, 2026-11-28)** added 21 checks to `test_admin_plans.php` (the Free-plan rule, the reason, the trial-plan list, the copy into the older columns, where each server-side refusal sits, the prices screen, and the starting data, its guard first proved on a bad sample) and 2 to `test_pricing_page.php` (a price older data left on the Free plan is ignored). Against the code before the change 10 of them failed (with only the new one-line `pricingIsFreePlan()` added, so the file could run) and the pricing-page one failed. Suite: **9088**. `admin_plans_real_mysql.php` grew to 157 checks with scenario N: on the old code 6 failed (a monthly and a yearly price were saved on the Free plan, a new version under the key free was made), then the script stopped; on the new code all 157 passed on MySQL 8.0.36, MySQL 8.4.13 and MariaDB 10.6.28 (Linux PHP 8.4.27 container, non-root).

**Real-database tests in the deploy gate (#174, decision 0040, 2026-11-28)** added 56 checks to `test_deploy_test_gate.php` (the shared script `tools/ci/run-integration-tests.sh` with stand-in scripts, the server guard, and 13 weakenings of `deploy.yml`'s new job). Run against the code before the change, 24 of them failed. Suite: **9065** in 55 files on PHP 8.5.12. The shared script then ran all eight real-database scripts inside a Linux PHP 8.4.27 container (non-root) against throwaway MySQL 8.0.36, MySQL 8.4.13 and MariaDB 10.6.28: every one passed on each.

**Merged security review of the no-JavaScript route (#163, 2026-11-28)** added `test_no_script_route_review_fixes.php` (76 assertions: findings M1, L1, L3, L4, L5, L6, I2, I3, I4, I5 and upgrade 0005's byte-for-byte compare; the route helper is driven with stand-ins for its gate, its "give the place back" step, the queued-email counter and the minimum time, so the check is on what the helper does, not on a function name). First run against the old code it failed 37 of 57 (the rest stopped on functions that did not exist yet). `test_human_check_fallbacks.php` lost 3 checks (the pages no longer read the limits themselves; the helper's own checks replace them) and `test_human_check.php` now looks for 0005's byte-for-byte compare. Suite: **9009** assertions in 55 files on PHP 8.5.12, all passing. `tests/integration/human_check_real_mysql.php` grew to 171 checks in 16 scenarios (new M, N, P, Q; K, I and J changed): run against the old code (commit 17eeda7) 39 of them failed, in K, M, N, P, Q, I and J. `migrations_real_mysql.php` grew to 200 (O: a stored "NONE", or a description differing only in capitals or a trailing space, is kept; failed on the old code on both servers). Every protection the review found untested was then removed in a scratch copy, one at a time (19 removals, including the new ones), and each made at least one test fail; 18 were caught by the real-database run, the "do not ask a provider freshly tested down" skip only by the unit tests (the real run has no provider to time). On throwaway Docker **MySQL 8.0.36** and **MariaDB 10.6.28** all eight scripts in `tests/integration/` passed: human check 171, migrations 200, account recovery 192, admin access 167, admin plans 144, admin settings 232, email queue 77, resend verification 32 on each.

**Task 7n Phase 2 slice 2c (2026-11-26)** added `test_admin_plans.php` (566 assertions: the plan-key rule, UK-time dates, every freezing rule P1/P4/P5/P6 shown to refuse and to allow, new-version and price keys, fingerprints, the price-group form, the mirror, the five new settings, and static guards each proved against a bad sample first) and 54 assertions in `test_admin_settings.php`. The whole suite was then **7390** on PHP 8.5.12.

**Task 7n Phase 2 slice 2d (2026-11-26)** added `test_pricing_page.php` (the kind filter and sale dates, a stand-in database in a child process including one without upgrade 0004, the "Contact us" card and its link, hidden prices, the rendered cards, the preview and its access rules) and `test_commercial_literals.php` (the whole-site guard against hard-coded plan keys and prices). Each new rule was broken on purpose (28 changes) and the tests failed every time. The whole suite is then **7513** on PHP 8.5.12.

**Human check review fixes and decisions 0037/0038 (#163, 2026-11-28)** added `test_human_check_review_fixes.php` (review findings M1, M2, L1, L2, L3, L5 and the per-visitor form limit, with stand-ins for the token record, the stored-row question and the counter) and `test_human_check_fallbacks.php` (decision 0037: only the server's own test call decides "down", bounded test calls and log rows, only sign-in and only with every configured provider down, fallback providers, no unconfigured provider; decision 0038: the route's limits, the keyed email hash, the `<noscript>` button, the email sign-in link decisions and the pages' order of checks). Both were first run against the code before the change: the review-fixes file failed 33 checks (M1 7, M2 5, L1 5, L5 1, form limit 4, L3 11; four sections also stopped on functions that did not exist yet, and the L2 case was reproduced on its own: a token from evil.example passed when the request claimed Host: evil.example) and the fallbacks file stopped at once (its function file was missing). Commenting out the human-check call on `/login/` and in `handleAuthLogin()` (the review's own L3 mutation) now fails 11 checks; before the fix it failed none. `test_human_check.php`, `test_admin_settings.php`, `test_sessions_valid_from.php`, `test_email_queue_runner.php` and `test_resend_verification.php` (L4: the double-quoted string) were updated for the new behaviour. The whole suite is then **8378** on PHP 8.5.12, with no PHP warning. On PHP 8.4.27 (the bare `php:8.4-cli` Docker image, which has no gd, intl, bcmath or mysqli and runs as root) every human-check file and every file this change touched passed; the 20 failures there are all in files that need those missing extensions or a non-root user.

**Human check (#163, 2026-11-27)** added `test_human_check.php` (377 assertions: each of the three providers with stand-in replies - success, failure, provider unreachable, error page, reply that is not JSON, missing or bad token, keys absent, wrong host name, replayed or expired token, wrong keys, published test keys - plus the widget, the command-line options, static checks that every page and JSON route makes the call, the exact content security policy and the migration rules). No real provider is called. It was first run before the code existed (it failed: the function file was missing), then with the functions but before the pages were wired (49 of 377 checks failed). The table above was re-measured on PHP 8.5.12 the same day: **8026** assertions, all passing.

### Codex review fixes of task 7n Phase 2 (2026-11-28, #31)

Every finding was first shown failing on the old code (the unit tests on PHP 8.5.12; the real-database checks by putting the old file back for one run and then restoring the new one). Unit suite: **8936** assertions in 54 files, all passing. New or changed checks: `test_money.php` (all 155 current ISO 4217 currencies with 0, 2 or 3 decimal places; the three-decimal ones cannot be offered because the price columns hold two; an amount the column would round is refused), `test_admin_plans.php` (CAD offered and saved; a price form without its date or "recommended" boxes refused), `test_migrations.php` and `test_plans_prices_migration.php` (an applied upgrade whose file is missing refuses the installer; 0004 compares descriptions byte for byte), `test_pricing_page.php` and `test_pricing.php` (one selection rule for the card and the preview; the preview keeps older-column prices; "Price unavailable" when the price list cannot be read).

On throwaway Docker **MySQL 8.0.36** and **MariaDB 10.6.28**, one after the other, every script in `tests/integration/` passed: `migrations_real_mysql.php` 198 checks on each (new: an applied upgrade with no file is reported and the starting-data load is refused with nothing written; owner descriptions that differ only by a trailing space or capitals are kept - on the old code the first FAILED on MariaDB and the second on both servers), `admin_plans_real_mysql.php` 144 (new: the date boxes left out are refused and the stored end dates stay; CAD 139.50 and KRW 125,000 saved and read back exactly; with `billing_price_points_enabled` on, the card still shows the price group's figure - each FAILED on the old code on MySQL), `admin_settings_real_mysql.php` 232, `admin_access_real_mysql.php` 167, `account_recovery_real_mysql.php` 192, `email_queue_real_mysql.php` 77, `human_check_real_mysql.php` 115 and `resend_verification_real_mysql.php` 32 on each. Not run: a real browser; a failing price-list read on a real database (unit-tested only).

### Real-database and HTTP runs for the human check review fixes and decisions 0037/0038 (#163, 2026-11-28)

On throwaway Docker **MySQL 8.0.36** and **MariaDB 10.6.28**, one after the other, every script in `tests/integration/` passed: `human_check_real_mysql.php` 115 checks in 12 scenarios on each (A to L: the new settings and tables after the upgrades; keys; the command line; real HTTP with `curl_init` switched off in the web server - every token counted as "our set-up is wrong", never "down", with ONE error-log line for seven such requests; F, every configured provider down by the server's own test row: sign-in without a token let through with one activity row and one per-account row, five wrong passwords during the outage still lock the account, sign-up, forgot password and new link still refused, the JSON sign-in let through and the JSON sign-up refused, "refuse" refuses sign-in, an old "down" is tested again; K, the no-JavaScript route over real HTTP: wrong password and unknown address give the same answer and no email, the right password gives one email whose link is stored only as a hash, opening the link in another browser changes nothing and its button only approves, the link works once, Continue signs the first browser in, the same browser is signed in directly, a moved "sessions valid from" mark and an expired link are refused, the per-address limit stops the email before the password, sign-up gives the same answer for an existing and a new address in at least 0.4 seconds, forgot password the same answer with one reset email for the real account only; L, the fourth junk token from one address is refused before any call with a limit of 3; G, no key or sign-in link in any log table; H, a token accepted once is refused the second time and eight processes with one token at the same moment give exactly one acceptance; I, a damaged stored secret makes the forms refuse instead of switching the check off; J, the route's limits per email address, per visitor address and site-wide, and twelve processes at once for one address let exactly 3 through); `migrations_real_mysql.php` 195 on each (0005 now 12 steps, its four tables allowed for in the schema comparison); `email_queue_real_mysql.php` 77 in 16 scenarios (the new 15-minute link lifetime); `account_recovery_real_mysql.php` 192, `admin_access_real_mysql.php` 167, `admin_plans_real_mysql.php` 135, `admin_settings_real_mysql.php` 232 and `resend_verification_real_mysql.php` 32 on each. Scenario L first FAILED: the form limit was never recorded on the web pages (a helper file was not loaded there); fixed before commit. No real browser and no real provider were used.

### Real-database and HTTP runs for the human check (#163, 2026-11-27)

On throwaway Docker **MySQL 8.0.36** and **MariaDB 10.6.28**, one after the other: the new `human_check_real_mysql.php` passed 60 checks on each (scenarios A to G: keys stored encrypted and read back, the order Turnstile then reCAPTCHA then hCaptcha, the command-line off/on switch with its admin change-log row, and real HTTP through PHP's built-in web server with `curl_init` switched off in it so no provider can be reached: all four forms and the three JSON routes refused without a token, a valid API key no way round it, a token refused as "could not be reached", "let people through" letting the account be made, no key in any log); `migrations_real_mysql.php` passed 190 checks on each (A and J extended for 0005 in both install orders, N extended, new O: an untouched "none" becomes "auto", a choice an admin or the command line made is kept, an owner's own waiting time is kept, a forced re-run changes nothing); `admin_settings_real_mysql.php` 232, `admin_access_real_mysql.php` 167, `admin_plans_real_mysql.php` 124, `account_recovery_real_mysql.php` 192 and `resend_verification_real_mysql.php` 32 on each, unchanged. `email_queue_real_mysql.php` was not re-run (it does not use the upgrades or the forms). No real browser and no real provider widget were used.

### Real-database and HTTP runs for the 7n Phase 2 review fixes (2026-11-27)

Every fix was first shown failing on the old code. Passed on throwaway Docker **MySQL 8.0.36** and **MariaDB 10.6.28**, one after the other: `migrations_real_mysql.php` 174 checks on each (J: the installer's load is refused with nothing written while an upgrade holds the lock, a repeated step that fails rolls the starting data back too, and the retry gives a fresh install's data; N2: the owner's own Pro price is the price-list price and a save of the yearly price leaves the monthly one alone; N: the two starting descriptions say "coming soon", an owner's own wording is kept); `admin_plans_real_mysql.php` 124 checks on each (F: protected plans cannot be given sale dates; L: the pricing page follows sale dates when it loads and a deleted last price is not shown; M: a price save and a main-currency change at the same moment, and the trial-plan setting against a plan delete in both orders); `admin_settings_real_mysql.php` 232 and `admin_access_real_mysql.php` 167 on each, unchanged. Over real HTTP (PHP's built-in server, MySQL 8.0.36): the F1 case (upgrade lock held during the install, then a reload) was reproduced on the old code (installer locked itself, no Enterprise, 0 price points) and after the fix ends with Enterprise and 5 price points; `/pricing/`, `/admin/plans/prices/` and `/admin/pricing-preview/` load with no PHP warnings, the per-plan price promise lines and "Price coming soon" for an ended price. No real browser was used (screen sizes, dark mode and keyboard not checked).

### Recheck of the 7n Phase 2 review fixes (2026-11-27)

Each of the eight recheck findings got a test that was first shown failing on the old code (66e0591, in a scratch clone with only the new tests copied in). The suite is now **7667** assertions in 51 files, passing on PHP 8.5.12 and 8.4.27 (Docker); `tools/ci/run-tests.sh` now also refuses a run with fewer than 45 test files, a runner that skipped files, or no assertions. New: the installer's "upgrade file changed since applied" refusal (`test_migrations.php`), protected plans refusing "Contact us", a sales cap and display order 0, and the trial-plan choice rule (`test_admin_plans.php`), the note above the price cards (`test_pricing_page.php`), `ST_Collect()` and the SQL-mode comment fact (`test_migrations.php`), and the deploy gate (`test_deploy_test_gate.php`: seven weakenings of a copy of `deploy.yml`, empty or short runs, and the per-branch deploy queue). Real databases, throwaway Docker MySQL 8.0.36 and MariaDB 10.6.28: `migrations_real_mysql.php` 179 checks and `admin_plans_real_mysql.php` 135 checks on each (on the old code the new checks fail: a fatal at the missing installer check, and the free plan's card removed from the pricing page), `admin_settings_real_mysql.php` unchanged and passing. Over real HTTP (PHP's built-in server): an empty database upgraded with `migrate.php --apply` and 0004 recorded with an earlier fingerprint made the OLD installer lock itself with no Enterprise plan and 0 price points; the new installer stops with the plain message, writes nothing and no lock file, on both engines, and a normal fresh install still finishes. `/pricing/` without upgrade 0004 no longer claims each plan states a promise. Not checked: a real browser, PHP 8.6 with the full extension set, a real GitHub run of `deploy.yml`.

### Real HTTP and browser run for task 7n Phase 2 slice 2d (2026-11-26)

PHP's built-in web server on a throwaway copy of the site, MySQL 8.0.36 in Docker (`cuercode_it_2d`): the web installer on an empty database (all four upgrades applied); `/pricing/` with the £ drafts and the "From £99/month" card; a contact address of the plan's own; prices hidden; the preview signed out (sign-in page), as an ordinary user (404) and as a Global Admin in each currency and with an unknown one; a database with only `install.sql` and `seed_data.sql` (old prices, no Enterprise, no error); the preview while an upgrade was waiting. Headless Microsoft Edge: `/pricing/` and the preview at 320, 375, 768, 1024, 1366 and 1920 px, no sideways scrolling, light (and dark on a second pass), very long names and prices. Not checked: colourblind palettes, keyboard-only use, a real phone, Apache `.htaccess`, MariaDB or MySQL 8.4 for these pages.

### Real-database and HTTP runs for task 7n Phase 2 slice 2c (2026-11-26, plans and prices screens)

`admin_plans_real_mysql.php` passed **96/96** on throwaway Docker **MySQL 8.0.36** and **MariaDB 10.6.28**, one after the other. The pages were also driven once over real HTTP (PHP's built-in web server against a throwaway MySQL 8.0.36 database, curl with a cookie jar): signed out → sign-in page; real sign-in; the admin headers (`no-store`, `DENY`, `noindex`); a change refused before the password is confirmed; password confirmed; a plan made; a price group saved (£12.50 / $14.00, mirrored into the older column); a change and a hand-made delete of a BOUGHT price refused; a hand-made key change refused; a wrong CSRF token and a foreign Origin answered 403. No browser was used.

### Real-database runs for task 7n Phase 2 slice 2b (2026-11-26, migration 0004)

Passed on throwaway Docker **MySQL 8.0.46** and **MariaDB 10.6.28**, one after the other: `migrations_real_mysql.php` 165 checks on each (A and B extended for 0004; J extended: 0004 leaves the plans tables empty on an empty database, then the installer's replay gives exactly a fresh install's plans, prices and settings, a second replay changes nothing, and the replay is refused while the upgrade lock is held; new N: a database at 0003 upgrades to 0004 identical to a fresh install, and the owner's own prices, price point, hand-made Enterprise plan and settings are kept, also when 0004 is forced to run again), `admin_access_real_mysql.php` 167, `admin_settings_real_mysql.php` 232 and `account_recovery_real_mysql.php` 192 on each (unchanged counts; re-run because they apply every migration). `email_queue_real_mysql.php` and `resend_verification_real_mysql.php` were not re-run (they do not use the migrations).

### Real-database runs after Codex round 3 (2026-11-25)

Passed on throwaway Docker **MySQL 8.0.36** and **MariaDB 10.6.28**, one after the other: `migrations_real_mysql.php` 124 checks on each after round 4 (M seed settings now checks EVERY starting setting by name: one missing plus one unrelated extra row is "partial" and names it; many missing shows five names and a count) and 121 after round 3 (M5 changed to decision 0018: marker present and the three tables emptied is "complete" and nothing is put back, then removing the marker allows a clean re-seed; new M seed settings: 68 rows with one starting setting missing is "partial"), `admin_access_real_mysql.php` 167 on each (B extended: a restricted database user that cannot read the change-log table reads "unknown" whether the table is missing or present, a full-rights user reads "no" then "yes"; the script creates and drops that user), `admin_settings_real_mysql.php` 232 on each. The other three scripts were not re-run after round 3 (nothing they cover changed).

### Real-database runs after Codex round 2 (2026-11-25, re-check of the fixes)

All six scripts passed again on throwaway Docker **MySQL 8.0.36** and **MariaDB 10.6.28**, one after the other: `migrations_real_mysql.php` 111 on each (new Scenario M: "complete" needs every starting row or the completion marker; MyISAM, missing and view tables are refused with nothing written, plus a reproduction of the MyISAM rollback fault; A and J extended: the bot-protection description names hCaptcha in both orders), `admin_access_real_mysql.php` 163 on each (B extended: with the activity log refusing rows before upgrade 0002, nothing unlocks and no upgrade runs), `admin_settings_real_mysql.php` 232, `account_recovery_real_mysql.php` 192, `resend_verification_real_mysql.php` 32, and `email_queue_real_mysql.php` all 16 scenarios, on each. The scripts that build their own tables need the database created first and `--load-schema` (see each section below).

### Real-database runs after the Codex review (2026-11-25, queue F2)

Every `tests/integration/*real_mysql.php` script passed on throwaway Docker
servers **MySQL 8.0.36** and **MariaDB 10.6.28**, run one after the other
(not in parallel, so the timing checks were not disturbed):

| Script | MySQL 8.0.36 | MariaDB 10.6.28 | New in this review |
| --- | --- | --- | --- |
| `migrations_real_mysql.php` | 90 | 90 | Scenario L (seed all-or-nothing, part-loaded and unreadable states, installer lock) and J extended (seed loads after `migrate.php --apply`) |
| `account_recovery_real_mysql.php` | 192 | 192 | P (resets/changes refused before 0003, same link works after) and CX (erase removes passkeys/links/waiting emails; a link expiring while it waits is refused; a busy known account answers in 0.25 s) |
| `admin_access_real_mysql.php` | 159 | 159 | L (no unlock and no upgrade unless the change-log row is written; a child process with a real PHP session) |
| `admin_settings_real_mysql.php` | 232 | 232 | F (a save whose token cache cannot be cleared is refused) |
| `email_queue_real_mysql.php` | all scenarios | all scenarios | none |
| `resend_verification_real_mysql.php` | 32 | 32 | none |

The real installer page (`public_html/install/index.php`) was also run end
to end with PHP's built-in web server on both servers (wrong password,
missing seed file, a seed failing part-way then a retry, a part-loaded
database, a second installer while one holds the lock). That harness lived in
the session scratchpad and is not part of the suite; the same behaviour is
covered by the scripts above.

## Real-database check (email queue)

`tests/test_email_queue_runner.php` proves the email queue runner's logic
with an in-memory stand-in for the queue table. The real SQL, the real MySQL
named lock, and two real runner processes working on one queue at the same
time can only be proven against a real database, so there is a separate
script for that (run automatically, see above): `tests/integration/email_queue_real_mysql.php`.
`run.php` does not run it (it only picks up `tests/test_*.php`), but CI still
syntax-checks it.

**Rules - it deletes rows:**

- Use a **throwaway container only**, named after the work, started with
  `--rm` so the container *and* its data are removed when it stops.
- The database name **must start with `cuercode_it_`** - the script refuses
  (exit 2) to touch anything else.
- **Remove the container afterwards**, and check nothing was left behind.
- It never sends real email: every scenario uses a stand-in sender, and the
  test addresses use the reserved `.invalid` domain.

```bash
REPO="/path/to/CueRCode"   # quote it - the path may contain spaces
BEFORE=$(docker volume ls -q -f dangling=true | wc -l | tr -d ' ')
docker run --rm -d --name p112-emailq-mysql \
  -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE=cuercode_it_p112 \
  -p 127.0.0.1:33112:3306 mysql:8.0 --default-authentication-plugin=mysql_native_password
CUERCODE_IT_DB_HOST=127.0.0.1 CUERCODE_IT_DB_PORT=33112 CUERCODE_IT_DB_NAME=cuercode_it_p112 \
CUERCODE_IT_DB_USER=root CUERCODE_IT_DB_PASS= \
  php "$REPO/tests/integration/email_queue_real_mysql.php" --load-schema; echo "exit=$?"
docker stop p112-emailq-mysql          # --rm removes the container AND its unnamed data volume
docker ps -a --filter name=p112-emailq-mysql --format '{{.Names}}'     # must print nothing
AFTER=$(docker volume ls -q -f dangling=true | wc -l | tr -d ' '); echo "dangling before=$BEFORE after=$AFTER"   # must be equal
```

`mysql:8.4` also works - leave off `--default-authentication-plugin`, which
8.4 no longer accepts (the server refuses to start with it).

The script waits for the database by itself (up to 120 seconds), loads
`install.sql` and `seed_data.sql` when `--load-schema` is given, runs its
set-up checks and then fifteen scenarios (1-14 plus 10b: claim rules, two
runner processes with and without the lock, retry with back-off, stuck-row
recovery, blocked sending, busy/stalled, counts, and - since #122/#123 -
details cleared on send, expired links skipped in bulk and per email,
given-up details cleared after 24 hours, old records deleted without touching
waiting ones, cleared emails never sent, and the runner tidying up while email
is switched off), deletes its own rows and prints PASS/FAIL per scenario. Exit
code 0 means every scenario passed. For MariaDB, use
`mariadb:10.11 -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 -e MARIADB_DATABASE=cuercode_it_p112`
(and no authentication-plugin option). Last run 2026-09-25 (#122/#123/#126):
all scenarios passed on MySQL 8.4.11, MySQL 8.0.46, MariaDB 10.11.19 and
MariaDB 10.6.28.

## Real-database check (database upgrades)

`tests/test_migrations.php` proves the database upgrade runner's ("migrations")
LOGIC without a database, using a stand-in "does this already exist?" checker.
The real `INFORMATION_SCHEMA` questions, the real named lock, a real
`install.sql` run twice, a real upgrade of a database built by the OLD
(pre-task-7n) `install.sql`, and the real command-line tool
(`_includes/cli/migrate.php`) end to end can only be proven against a real
server, so there is a separate script for that (run automatically, see above):
`tests/integration/migrations_real_mysql.php`. `run.php` does not run it (it
only picks up `tests/test_*.php`), but CI still syntax-checks it.

**Rules - it creates and drops whole databases:**

- Use a **throwaway container only**, named after the work, started with
  `--rm` so the container *and* its data are removed when it stops.
- `CUERCODE_IT_DB_NAME` is a **base name** here, not a single database: the
  script derives and manages several of its own (`<base>_a`, `<base>_b`,
  `<base>_f`, `<base>_g`, `<base>_h`, `<base>_i`, `<base>_j`, `<base>_k1`,
  `<base>_k2`, `<base>_l`, `<base>_l2`, `<base>_m`, `<base>_n`, `<base>_n2`) because several checks need independent databases (an "old"
  database, a "two runs at once" database, a latin1-default database, a
  database with its own throwaway migrations folder...). Every database name -
  base and derived - **must start with `cuercode_it_`**, or the script refuses
  (exit 2).
- **Remove the container afterwards**, and check nothing was left behind.
- It needs the pre-task-7n `install.sql` (commit `76b1af7`). Since the
  third-round review (Low 2) that is a committed, byte-for-byte copy,
  `tests/integration/fixtures/install_76b1af7.sql`, so a squash or rebase
  merge (which drops the commit from the branch's history) no longer stops
  it. The script refuses (exit 2) if the copy's SHA-256 is not the pinned
  one, or if git history holds commit `76b1af7` and the copy differs from
  it; with no such commit in history it says the comparison was skipped.

```bash
REPO="/path/to/CueRCode"   # quote it - the path may contain spaces
BEFORE=$(docker volume ls -q -f dangling=true | wc -l | tr -d ' ')
docker run --rm -d --name p113-migrations-mysql \
  -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
  -p 127.0.0.1:33113:3306 mysql:8.0 --default-authentication-plugin=mysql_native_password
CUERCODE_IT_DB_HOST=127.0.0.1 CUERCODE_IT_DB_PORT=33113 CUERCODE_IT_DB_NAME=cuercode_it_p113 \
CUERCODE_IT_DB_USER=root CUERCODE_IT_DB_PASS= \
  php "$REPO/tests/integration/migrations_real_mysql.php"; echo "exit=$?"
docker stop p113-migrations-mysql     # --rm removes the container AND its unnamed data volume
docker ps -a --filter name=p113-migrations-mysql --format '{{.Names}}'     # must print nothing
AFTER=$(docker volume ls -q -f dangling=true | wc -l | tr -d ' '); echo "dangling before=$BEFORE after=$AFTER"   # must be equal
```

`mysql:8.4` also works - leave off `--default-authentication-plugin`, which
8.4 no longer accepts. For MariaDB, use
`mariadb:10.11 -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1` (and no
authentication-plugin option) - no `MYSQL_DATABASE`/`MARIADB_DATABASE` is
needed either way, because the script creates every database it uses itself.

The script waits for the server by itself (up to 120 seconds), then runs
scenarios A-K: a fresh database (install.sql + seed_data.sql + migrations
0001/0002, install.sql run a second time with no error, a second apply
reporting "already up to date", the real command-line tool's exit codes); a
database built by the OLD install.sql upgrading cleanly (0001 skips every
step, 0002 applies, the finished schema matches the fresh path's exactly);
owner-edited setting values surviving a re-run; a missing baseline table being
re-created and a missing baseline column being refused (exit 2); two upgrade
runs at the same time (the second is refused "busy", nothing half-applied);
a failing step stopping the run without being recorded (so the next run
resumes and finishes it), an edited-after-applied file being reported as a
warning and never re-run, and a destructive (`DROP`) step being refused before
anything runs; the web installer's seed-data protection check
(`migrationDbSeedDataAlreadyPresent()`); a latin1-default database not ending
up with a mismatched connection character set after install.sql's own `SET
CHARACTER SET utf8mb4` statement runs, with an emoji/£ written by a migration
step afterwards round-tripping byte-for-byte (review finding F2); running
`migrate.php --apply` directly against a COMPLETELY EMPTY database still
leaving the installer's seed-protection check correctly saying "NOT seeded"
afterwards (review finding F1 - this is the exact bug it fixes); and the
upgrade lock name including the database, so two different databases never
block each other's upgrades while a database still correctly blocks a second
run against itself (review finding F6). It drops its own databases, then
prints PASS/FAIL per scenario. Exit code 0 means every scenario passed. Last
run 2026-11-23 (task 7n Phase 1A part 2 code review fixes, #113 item 5, #70):
all scenarios passed on MySQL 8.0.46.

Since decision 0042 (#176, 2026-11-28) it also runs scenario P, upgrade 0006
on a database at 0005: every money column becomes DECIMAL(15,3) with its NOT
NULL and default kept; every stored amount (the largest old value
99,999,999.99 and the smallest 0.01 included) is exactly the same; KWD 1.234
and 999,999,999,999 yen are stored exactly; forced to run again it skips all
six steps; with three columns already widened by hand it does the other
three; a column someone widened by hand to DECIMAL(20,2) is refused at its
step and left alone (0006 not recorded, exit code 1); a column made NULL-able
by hand is refused too, and once put right the next run finishes; the
finished structure is byte for byte the fresh install's.

## Real-database check (admin settings editor)

`tests/test_admin_settings.php` (count in the table above; no database) proves the
settings editor's decisions. `tests/integration/admin_settings_real_mysql.php`
(213 checks, scenarios A-N, including the lost-update overlap with a second process waiting on the row lock) proves the rest on a throwaway database: secrets
stored as ciphertext with a fresh salt per save and audited as set / replaced /
cleared; ordinary settings and the VAT wording; locked keys refused even by
hand-made requests; one transaction per section (triggers force a failure);
cache clearing after a change; the two test actions with stand-ins (no real
Microsoft call exists); the trusted-forwarders row; no secret in any log table,
the audit log, the PHP error log or a stack trace; and the REAL page rendered
in a child PHP process with a forged signed-in session (GET, locked, wrong
CSRF, other-site, no fresh password, hand-made locked-switch POST, error
summary, a good save). No browser is used.

```bash
docker run -d --rm --name cuercode-it-settings -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
  -p 127.0.0.1:33116:3306 mysql:8.0.36      # also mysql:8.4 and mariadb:10.6
CUERCODE_IT_DB_HOST=127.0.0.1 CUERCODE_IT_DB_PORT=33116 \
CUERCODE_IT_DB_NAME=cuercode_it_p1c CUERCODE_IT_DB_USER=root CUERCODE_IT_DB_PASS= \
  php tests/integration/admin_settings_real_mysql.php
docker stop cuercode-it-settings
```

Run 2026-11-25 (after the final review: test buttons record each press before counting it, `.started` rows, optional limits show their default, odd stored numbers, internal-only discovery names): 216/216 on MySQL 8.0.36, MySQL 8.4.13 and MariaDB 10.6.28. (Earlier the same day, after the second independent review: 213/213 on MySQL 8.0.36, 8.4.11 and MariaDB 10.6.28.)

## Real-database check (admin plans and prices)

`tests/test_admin_plans.php` proves the plans editor's decisions. `tests/integration/admin_plans_real_mysql.php`
(169 checks, scenarios A-O) proves the rest on a throwaway database: the 0004 starting data; making a plan
(off sale, features copied, audited, duplicate key refused); prices saved exactly as text and mirrored by
price group; P1 a bought price cannot change or be deleted even when bought after the form was opened, but
can be retired and replaced (`monthly_gbp_2`); a REAL race - a second process saving while another
connection holds an uncommitted purchase waits, then is refused (commit) or goes ahead (rollback); two
admins at once (the second save refused, nothing written); P4/P5/P6; new version; a price of another plan
refused; one transaction (a trigger refusing the audit row undoes the whole save); changing the main
currency re-runs the mirror; the pricing page follows sale dates when it loads (L); a price save racing a
main-currency change, and the trial-plan setting racing a plan delete (M); the Free plan can never have a price (N, decision 0041); three-decimal and large prices saved and read back exactly, KWD as the main currency (O, decision 0042); the charging switches stay 0.

```bash
docker run -d --rm --name cuercode-it-plans -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
  -p 127.0.0.1:33121:3306 mysql:8.0.36      # also mariadb:10.6
CUERCODE_IT_DB_HOST=127.0.0.1 CUERCODE_IT_DB_PORT=33121 \
CUERCODE_IT_DB_NAME=cuercode_it_p2c CUERCODE_IT_DB_USER=root CUERCODE_IT_DB_PASS= \
  php tests/integration/admin_plans_real_mysql.php
docker stop cuercode-it-plans
```

## Real-database check (Global Admin access)

`tests/test_admin_access.php` proves the admin access DECISIONS without a
database. `tests/integration/admin_access_real_mysql.php` proves the rest
against a real server, using one throwaway database built from
`install.sql` + `seed_data.sql`: the command-line tool refusing to change a
role before the upgrades; `/admin/upgrades/`'s engine applying the upgrades
before the change log table exists (audit entry falls back to the activity
log, then `schema.migrate` lands in the new table); `grant --activate` on a
pending account under decision 0010. That covers: refused from a pipe; one
transaction, proved by database triggers that force failures part-way; the
real command driven in a pretend terminal (the `script` tool), where typed
passwords never appear; the old password stopping and the new one working;
sessions, API keys, reset links, linked sign-ins and passkeys removed; and
`password_reset: true` in its `admin.grant` row. Also: an active account
granted with no reset, the typed confirmation, the
refusals (suspended, deleted, no password, unknown), `list` with partly
hidden addresses, `revoke` and the last-admin rule (all through the REAL
`_includes/cli/admin_access.php` as a child process); the gate's fresh
database read; exactly one `admin.first_seen` row; the erasure guard (409
`GLOBAL_ADMIN_CANNOT_ERASE`); the password re-confirmation counting toward
the account lock-out; and no typed password in any log table. Same rules as
above: throwaway container only, the database name must start with
`cuercode_it_` (it uses `<name>_admin` and drops it afterwards), and it never
reads `_auth_keys/auth_creds.php`.

```bash
docker run -d --rm --name cuercode-it -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
  -p 127.0.0.1:33114:3306 mysql:8.0.36
CUERCODE_IT_DB_HOST=127.0.0.1 CUERCODE_IT_DB_PORT=33114 \
CUERCODE_IT_DB_NAME=cuercode_it_p1b CUERCODE_IT_DB_USER=root CUERCODE_IT_DB_PASS= \
  php tests/integration/admin_access_real_mysql.php
docker stop cuercode-it
```

Exit code 0 means every scenario passed. Last run 2026-11-25 (after
decisions 0013/0014 moved the "remove every way in" SQL into the shared
`accountRemoveOtherWaysIn()`, and D2 now passes the account's password to the
verification link): 143 checks passed on MySQL 8.0.36 and MariaDB 10.6.28,
on macOS. Earlier 2026-11-25 (decision 0010): all 11 scenarios (134 checks)
passed on MySQL 8.0.36, MySQL 8.4.11 and MariaDB 11.4.13. The pretend-terminal step has a Linux form
(`script -q -e -c`) that has not been run yet; without a usable `script`
tool, that step is reported as NOT RUN. (2026-11-24, Phase 1B: 79 checks on
MySQL 8.0.36, 8.4.13 and MariaDB 11.4.13.) MariaDB 10.11 (named in the plan) was not available locally
and was not run.

## Syntax-checking the harness

```bash
php -l tests/run.php
php -l tests/bootstrap.php
php -l tests/lib/assert.php
```

## Layout

```
tests/
├── README.md          # this file
├── run.php            # discovers + runs test_*.php, prints summary, sets exit code
├── bootstrap.php      # test-only constants + requireFn() loader (NEVER opens a DB)
├── lib/
│   └── assert.php     # tiny assertion library + global results collector
├── integration/       # checks that need a real (throwaway) database - NOT run by run.php; run by tools/ci/run-integration-tests.sh
│   ├── account_recovery_real_mysql.php
│   ├── admin_access_real_mysql.php
│   ├── admin_plans_real_mysql.php
│   ├── admin_settings_real_mysql.php
│   ├── email_queue_real_mysql.php
│   ├── human_check_http_router.php   # the web server's router for human_check (not a test itself)
│   ├── human_check_real_mysql.php
│   ├── migrations_real_mysql.php
│   └── resend_verification_real_mysql.php
└── test_*.php         # individual characterization test files (added per cycle)
```

## Writing a test file

Create `tests/test_<topic>.php`. The runner has already loaded
`bootstrap.php` and `lib/assert.php` before including your file, so the
assertions and `requireFn()` are available. Load only the production function
file(s) you need — **never** include the production bootstrap or
`db_connect.php**.

```php
<?php
// tests/test_example.php

// Load ONLY the production function file under test (no DB connection opened).
requireFn('fn_encryption.php');   // or 'encryption' — both normalise the same

// Deterministic crypto round-trip using the test-only keys from bootstrap.php.
$cipher    = encryptValue('hello world');
$plaintext = decryptValue($cipher);
assertEquals('hello world', $plaintext, 'encrypt/decrypt round-trips');

// Explicit key/salt args also work (and are fully DB-independent).
$c = encryptValue('secret', 'a-key', 'a-salt');
assertEquals('secret', decryptValue($c, 'a-key', 'a-salt'), 'explicit key round-trip');

// A wrong key must fail to decrypt (throws RuntimeException).
assertThrows(
    function () use ($cipher) {
        decryptValue($cipher, 'wrong-key', 'wrong-salt');
    },
    'decrypt with wrong key throws'
);
```

### Available assertions (`tests/lib/assert.php`)

| Assertion | Checks |
| --- | --- |
| `assertEquals($expected, $actual, $msg)` | strict `===` equality |
| `assertTrue($actual, $msg)` | value is exactly `true` |
| `assertFalse($actual, $msg)` | value is exactly `false` |
| `assertContains($needle, $haystack, $msg)` | `$haystack` contains substring `$needle` |
| `assertMatches($regex, $actual, $msg)` | `$actual` matches the PCRE `$regex` |
| `assertThrows($fn, $msg, $expectedClass = null)` | invoking `$fn` throws (optionally of a given class) |

All assertions record a pass/fail into the global collector and **never abort**
the file on failure, so one bad expectation does not hide later results.

## What is unit-testable vs DB-dependent

The harness deliberately **never opens a MySQLi connection**. Use these
boundaries when deciding what to test:

### ✅ DB-independent (unit-testable)

- **`fn_encryption.php`** — `encryptValue`, `decryptValue` (round-trips with the
  test keys or explicit args), `hashAPIKey` (needs `ENCRYPTION_KEY` — defined in
  bootstrap), `generateAPIKey`, `getAPIKeyPrefix`.
- **`fn_validation.php`** — `sanitizeInput`, `validateEmail`, `validateURL`,
  `validatePhone`, `validateColor`, `validateJSON`, `sanitizeFileName`. (Note:
  `validateImageUpload` inspects a real uploaded file via `$_FILES`/filesystem.)
- **`fn_qr_payloads.php`** — every `buildPayload_*` builder and the escape
  helpers (`vcardEscape`, `icalEscape`, `wifiEscape`) are pure. The dispatcher
  `buildQRPayload` / `buildPayloadByType` *attempts* a `dbQuery` lookup but
  degrades gracefully when no DB is present, so the builders themselves are
  testable directly.
- **`fn_qr_options.php`** — `hexToRgb`, `rgbToHex`, `calculateScale`,
  `estimateModuleCount`, `mapEccLevel`, `mapOutputFormat` are pure. The
  `buildQROptions` / `buildModuleValues` / `buildSvgGradientDefs` functions need
  the `chillerlan\QRCode` library — call `requireLibraryAutoloader()` first (no
  DB, but a heavier dependency).
- **`fn_config.php`** — only the pure helpers `castSettingValue` and
  `convertValueForStorage` (the rest hit the DB).

### ⛔ DB-dependent or environment-dependent (out of scope for unit tests)

- **`fn_config.php`** — `getConfig`, `setConfig`, `getConfigGroup`,
  `getAllConfig` (all query `tblSettings`).
- **`fn_activity_log.php`** — `logActivity`, `getRecentActivity`.
- **`fn_csrf.php`** — needs an active PHP `$_SESSION`; `getCSRFTokenLifetime`
  also calls `getConfig` (DB). Session-based tests would need a session harness.
- **`fn_debug.php`** — `isDebugMode` reads config/DB; `debugOutput` emits output.
- **`fn_response.php`** — emits HTTP headers / `exit`s and reads config (DB).
- **`fn_error_handler.php`**, **`fn_qr_generate.php`**, **`fn_qr_frames.php`**
  — require the DB and/or the QR rendering pipeline; out of scope for pure
  unit tests. (Exception: `test_log_mask.php` runs the plain-text error-log
  fallbacks of `fn_error_handler.php` and `fn_activity_log.php` in separate
  child PHP processes, because loading either file installs error handlers
  and other test files assert the logging functions do not exist in the main
  process - #126.)
- **`fn_api_*.php`** and **`fn_qr_logo.php`** — mostly require the DB and/or
  real HTTP request handling, but each now has a pure-function slice that IS
  unit-tested: `fn_qr_logo.php`'s SVG-content screener and predefined-logo
  path resolver (`test_qr_logo.php`), `fn_api_keys.php`'s ownership/scope
  validators (`test_api_keys.php`), and `fn_api_qrcodes.php`'s ownership
  predicates (`test_qrcodes.php`). Their **DB/render-dependent parts** remain
  out of scope for this dependency-free harness.

## Real-database check (new verification link, #128)

`tests/test_resend_verification.php` proves the decisions, settings and page
wiring without a database. `tests/integration/resend_verification_real_mysql.php`
proves the real SQL: old links retired, one email queued, the same answer for
every kind of address, the minimum gap, the daily cap, the per-IP cap, and no
address or link in the activity log. `run.php` does not run it; CI
syntax-checks it. It deletes rows, so it refuses any database not named
`cuercode_it_*`. It never sends email.

```sh
docker run --rm -d --name p128-resend-mysql \
  -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE=cuercode_it_p128 \
  -p 127.0.0.1:33128:3306 mysql:8.4
CUERCODE_IT_DB_HOST=127.0.0.1 CUERCODE_IT_DB_PORT=33128 CUERCODE_IT_DB_NAME=cuercode_it_p128 \
CUERCODE_IT_DB_USER=root CUERCODE_IT_DB_PASS= \
  php "$REPO/tests/integration/resend_verification_real_mysql.php" --load-schema; echo "exit=$?"
docker stop p128-resend-mysql
```

## Real-database check (decisions 0013 and 0014)

`tests/test_account_recovery.php` and `tests/test_client_ip.php` prove the
decisions and the visitor-address rules without a database.
`tests/integration/account_recovery_real_mysql.php` proves the real SQL:

- **0013** - a verification link needs the account's password (nothing
  typed, wrong, right); wrong passwords count toward the lock-out and the one
  that reaches the limit stops the link; an account with no password still
  activates with the link alone; "Forgot password" now sends a link to a
  waiting account and using it activates the account and, in ONE transaction,
  ends sessions, switches off API keys, removes linked sign-ins and passkeys
  and cancels other unused links, with a counts-only activity row; the same on
  an active account; a database trigger forcing a failure part-way leaves
  everything (including the link) unchanged; a failed activity-log write does
  NOT block a reset (deliberate); a waiting Global Admin, a suspended account
  and a used link are refused.
- **0014** - with a real saved `trusted_proxy_ips` setting (a range),
  `getClientIP()` reads X-Forwarded-For from the right only from that range,
  trusts CF-Connecting-IP only from a Cloudflare range, and the activity log
  records the same address.

Since the independent review of 0013/0014 (2026-11-25) the same script also
applies the waiting database upgrades (so migration 0003 is in place) and
proves:

- **P** - before upgrade 0003 sign-in still works the old way; after it the
  "sessions valid from" protection is on.
- **R (finding 1)** - a sign-in that read the OLD password before a reset
  never ends with a working session. Done twice: with **two real processes**
  (the sign-in is paused inside the database by a trigger that sleeps 3
  seconds on its "last sign-in" update, while this process runs the reset),
  and step by step in the bad order with the real functions. The reset's own
  follow-up sign-in, straight away, works (the same-second boundary); two
  marks set in one instant still differ; a Google/Microsoft sign-in in the
  same gap is refused too.
- **L (finding 2)** - a Google/Microsoft "link" request started before a
  reset is refused afterwards (nobody signed in; signed in again but the mark
  moved; the locked re-check writes no link), and an API key cannot be made
  with a session the reset ended.
- **D2 (finding 5)** - two resets of one account at the same moment (two
  processes, five rounds): one wins, the other is told its link was used, no
  deadlock.
- **W (finding 7)** - eight wrong passwords at the same moment (eight
  processes) give a count of 8.
- **FP (finding 3)** - "Forgot password": the same answer and at least
  250 ms for a registered and an unknown address, the one-minute gap, the
  daily cap of 5, the hourly cap of 10 per visitor address, and at most one
  "refused by the limit" row per account per hour.
- **V (nit)** - a verification link for an already-active account says
  nothing changed; for a suspended account, the usual invalid answer.

Since the final review of 68ad1fb..bffdff3 (2026-11-25) it also proves:

- **CP (I-1, decision 0016)** - a voluntary password change keeps the
  session that made it, signs out the others, stops a sign-in still being
  checked, and leaves API keys and linked sign-ins alone; THE REVIEWER'S
  RACE with the real functions in two processes (this script holds the
  account row, the reset waits, the change checks the OLD password and waits
  too, then both go: the reset wins, the change is refused, the owner's
  password stands); a forced reset while the change waits; two tabs of one
  session (one wins, the other gets `PASSWORD_CHANGED_MEANWHILE`).
- **ER (I-1)** - the same forced gap for account erase (nothing erased),
  the verification link (nothing activated, the link still works) and the
  admin password re-confirm (confirms nothing, counter untouched).
- **SO (I-1)** - "Sign out everywhere" also stops a sign-in still being
  checked.
- **M1 (M-1)** - bursts of 20 processes: "Forgot password" for one account
  from one address and from 20 addresses gives ONE link; an account at the
  daily cap gets none and exactly one "refused" row; 20 accounts from one
  address get exactly 10 links; "Send me a new link" the same; every answer
  (also for unknown addresses) still takes at least 250 ms.

The way it holds a process half-way: its own separate connection locks the
account row, starts the child, and watches `information_schema.PROCESSLIST`
until the child is seen waiting for that row.

The script runs the extra processes itself (it starts copies of itself with
`--child=...`); it needs nothing else installed.

It deletes rows, so it refuses any database not named `cuercode_it_*`; it
creates the database if it is missing. It never sends email.

```sh
docker run --rm -d --name p131-recovery-mysql \
  -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -p 127.0.0.1:33131:3306 mysql:8.4
CUERCODE_IT_DB_HOST=127.0.0.1 CUERCODE_IT_DB_PORT=33131 CUERCODE_IT_DB_NAME=cuercode_it_p131 \
CUERCODE_IT_DB_USER=root CUERCODE_IT_DB_PASS= \
  php "$REPO/tests/integration/account_recovery_real_mysql.php" --load-schema; echo "exit=$?"
docker stop p131-recovery-mysql
```

Last run 2026-11-25 (after the final review): all 170 checks passed on
MySQL 8.0.36, MySQL 8.4.13 and MariaDB 10.6.28 (Docker, macOS). In the same
session `admin_access_real_mysql.php` (143), `admin_settings_real_mysql.php`
(216), `email_queue_real_mysql.php`, `migrations_real_mysql.php` and
`resend_verification_real_mysql.php` (32) all passed on all three. The new
CP/ER scenarios were also run against the code before the fix: they fail
there (the other person's password overwrote the reset; the old password
erased the account, activated the link and confirmed the admin). (Before
that: 122/122 on MySQL 8.0.36 and MariaDB 10.6.28 after the earlier review.) Note: `resend_verification`
and `email_queue` expect the database to exist already (create it first, or
use the `docker run ... -e MYSQL_DATABASE=...` form above); the others
create their own.
To prove the race checks really test something, they were also run once
against a scratch copy with the protection switched off (9 failures) and
with the old lock order (every round deadlocked).
