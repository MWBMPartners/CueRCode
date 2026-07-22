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
- **`fn_error_handler.php`**, **`fn_api_*.php`**, **`fn_qr_generate.php`**,
  **`fn_qr_logo.php`**, **`fn_qr_frames.php`** — require the DB and/or the QR
  rendering pipeline; out of scope for pure unit tests.
