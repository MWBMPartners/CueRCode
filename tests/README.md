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

## Current suite (2026-11-25, after the Codex re-check of the review fixes, round 3 - 4 findings; before that round 2 - 6 findings; before that the independent Codex cross-system review F2 - 29 findings C1-C6; earlier the final review of 68ad1fb..bffdff3, the independent review of decisions 0013/0014, decision 0010 #31, #151, #112, #122/#123/#126)

Measured with `php tests/run.php` on PHP 8.5.12 (macOS), all passing. After round 2 the same suite also passed on PHP 8.4.27 with Imagick (ImageMagick 7.1.2, Alpine in Docker, run as a normal user): 6355/6355 (not repeated after round 3) - a few checks differ by machine (for example `test_qr_logo.php` takes its Imagick branch there, 205 checks). Counts on PHP 8.5.12:

| File | Assertions |
| --- | --- |
| `test_accessibility.php` | 43 |
| `test_account_data.php` | 116 |
| `test_account_recovery.php` | 119 |
| `test_account_ui.php` | 70 |
| `test_admin_access.php` | 611 |
| `test_admin_settings.php` | 1211 |
| `test_api_keys.php` | 130 |
| `test_api_parity.php` | 76 |
| `test_auth.php` | 62 |
| `test_barcode.php` | 120 |
| `test_braille.php` | 69 |
| `test_bulk.php` | 85 |
| `test_client_ip.php` | 246 |
| `test_core.php` | 146 |
| `test_credentials_template.php` | 9 |
| `test_curl_content_decoding_guard.php` | 3 |
| `test_dynamic_redirect_s0.php` | 97 |
| `test_dynamic_redirect_s1.php` | 41 |
| `test_email.php` | 69 |
| `test_email_queue_runner.php` | 479 |
| `test_emoji_logo.php` | 123 |
| `test_error_catalogue.php` | 137 |
| `test_features.php` | 91 |
| `test_full_notation.php` | 41 |
| `test_install_checks.php` | 101 |
| `test_installer_load_order.php` | 9 |
| `test_library_updates.php` | 177 |
| `test_log_mask.php` | 56 |
| `test_login_next_url.php` | 21 |
| `test_migrations.php` | 446 |
| `test_oauth.php` | 136 |
| `test_openapi_render.php` | 115 |
| `test_payloads.php` | 138 |
| `test_php86_adoption.php` | 34 |
| `test_php86_compat_scan.php` | 76 |
| `test_php_lint_script.php` | 6 |
| `test_php_runtime_guards.php` | 24 |
| `test_predefined_logo_png.php` | 264 |
| `test_pricing.php` | 107 |
| `test_pricing_flex.php` | 51 |
| `test_qr_logo.php` | 204 |
| `test_qrcodes.php` | 83 |
| `test_resend_verification.php` | 71 |
| `test_sessions_valid_from.php` | 81 |
| `test_vendored_libraries.php` | 15 |
| **Total** | **6409** |

Run `php tests/run.php` at any time to reproduce this table live.

### Real-database runs after Codex round 3 (2026-11-25)

Passed on throwaway Docker **MySQL 8.0.36** and **MariaDB 10.6.28**, one after the other: `migrations_real_mysql.php` 121 checks on each (M5 changed to decision 0018: marker present and the three tables emptied is "complete" and nothing is put back, then removing the marker allows a clean re-seed; new M seed settings: 68 rows with one starting setting missing is "partial"), `admin_access_real_mysql.php` 167 on each (B extended: a restricted database user that cannot read the change-log table reads "unknown" whether the table is missing or present, a full-rights user reads "no" then "yes"; the script creates and drops that user), `admin_settings_real_mysql.php` 232 on each. The other three scripts were not re-run after round 3 (nothing they cover changed).

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

## Optional real-database check (email queue)

`tests/test_email_queue_runner.php` proves the email queue runner's logic
with an in-memory stand-in for the queue table. The real SQL, the real MySQL
named lock, and two real runner processes working on one queue at the same
time can only be proven against a real database, so there is a separate,
optional script for that: `tests/integration/email_queue_real_mysql.php`.
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

## Optional real-database check (database upgrades)

`tests/test_migrations.php` proves the database upgrade runner's ("migrations")
LOGIC without a database, using a stand-in "does this already exist?" checker.
The real `INFORMATION_SCHEMA` questions, the real named lock, a real
`install.sql` run twice, a real upgrade of a database built by the OLD
(pre-task-7n) `install.sql`, and the real command-line tool
(`_includes/cli/migrate.php`) end to end can only be proven against a real
server, so there is a separate, optional script for that:
`tests/integration/migrations_real_mysql.php`. `run.php` does not run it (it
only picks up `tests/test_*.php`), but CI still syntax-checks it.

**Rules - it creates and drops whole databases:**

- Use a **throwaway container only**, named after the work, started with
  `--rm` so the container *and* its data are removed when it stops.
- `CUERCODE_IT_DB_NAME` is a **base name** here, not a single database: the
  script derives and manages several of its own (`<base>_a`, `<base>_b`,
  `<base>_f`, `<base>_g`, `<base>_h`, `<base>_i`, `<base>_j`, `<base>_k1`,
  `<base>_k2`) because several checks need independent databases (an "old"
  database, a "two runs at once" database, a latin1-default database, a
  database with its own throwaway migrations folder...). Every database name -
  base and derived - **must start with `cuercode_it_`**, or the script refuses
  (exit 2).
- **Remove the container afterwards**, and check nothing was left behind.
- It needs the pre-task-7n `install.sql` from **git history** (commit
  `76b1af7`, read with `git show`). An ordinary clone has it; a *shallow*
  clone (`git clone --depth 1`) or a shallow CI checkout might not - the
  script refuses (exit 2) rather than skip that check silently.

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

## Optional real-database check (admin settings editor)

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

## Optional real-database check (Global Admin access)

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
├── integration/       # OPTIONAL checks that need a real (throwaway) database - NOT run by run.php
│   ├── account_recovery_real_mysql.php
│   ├── admin_access_real_mysql.php
│   ├── admin_settings_real_mysql.php
│   ├── email_queue_real_mysql.php
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

## Optional real-database check (new verification link, #128)

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

## Optional real-database check (decisions 0013 and 0014)

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
