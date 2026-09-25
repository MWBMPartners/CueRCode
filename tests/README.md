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

## Current suite (2026-09-25, #112, #122/#123/#126)

| File | Assertions |
| --- | --- |
| `test_accessibility.php` | 41 |
| `test_account_data.php` | 116 |
| `test_account_ui.php` | 70 |
| `test_api_keys.php` | 108 |
| `test_api_parity.php` | 76 |
| `test_auth.php` | 61 |
| `test_barcode.php` | 120 |
| `test_braille.php` | 65 |
| `test_bulk.php` | 82 |
| `test_core.php` | 140 |
| `test_dynamic_redirect_s0.php` | 97 |
| `test_dynamic_redirect_s1.php` | 41 |
| `test_email.php` | 69 |
| `test_email_queue_runner.php` | 476 |
| `test_emoji_logo.php` | 123 |
| `test_error_catalogue.php` | 132 |
| `test_features.php` | 91 |
| `test_log_mask.php` | 46 |
| `test_oauth.php` | 135 |
| `test_openapi_render.php` | 115 |
| `test_payloads.php` | 138 |
| `test_pricing.php` | 54 |
| `test_pricing_flex.php` | 50 |
| `test_qr_logo.php` | 178 |
| `test_qrcodes.php` | 83 |
| **Total** | **2707** |

Run `php tests/run.php` at any time to reproduce this table live.

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
│   └── email_queue_real_mysql.php
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
