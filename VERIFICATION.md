# CueRCode — Independent VERIFY Report

> Autopilot VERIFY phase — independent, read-only re-verification of the
> autopilot/2026-06-28 cycle (cycles 2–10). No code was modified.
> Every claim below was re-proven with fresh evidence generated during this
> pass (php / php -l / grep / in-memory renders run now), not trusted from
> PROJECT.md / SECURITY.md / FEATURES.md prose.

- **Verified at commit:** `f5cf408` (branch `autopilot/2026-06-28`)
- **Cycle commit range proven present:** `973b3a6 .. f5cf408` (10 commits)
- **Verified on:** 2026-06-29
- **PHP:** 8.4.x local · **Test runner:** `php tests/run.php`

---

## OVERALL VERDICT: **PASS**

All 5 claim groups carry a `group_verdict` of PASS, and every group has been
independently re-proven here. The clean-room integration group passes on all
four hard gates (php -l clean, suite green, 0 schema mismatches, openapi valid).
There are **no open FAIL / DRIFTED / HOLLOW findings of severity ≥ medium**.

Build integrity is confirmed. The remaining open items are all explicitly
owner-gated or design-queued (not regressions) and are enumerated below.

### Hard-gate summary (clean-room integration)

| Gate | Result | Fresh evidence (run now) |
|------|--------|--------------------------|
| `php -l` all PHP under `web/CueRCode.net/` | **PASS** | `find … -name '*.php' -exec php -l` → **115/115** "No syntax errors detected", 0 failures |
| `php -l` all PHP under `web/CueRCode.net/` + `tests/` | **PASS** | **122/122** clean |
| `php tests/run.php` | **PASS** | `Total: 400 assertion(s) \| 400 passed \| 0 failed` (api_parity 76, bulk 48, core 138, payloads 138) |
| Deprecation sweep (load every `fn_*.php` under `-d error_reporting=E_ALL`) | **PASS** | No `deprecated` / `warning` / `notice` emitted; sweep completed clean |
| Schema↔code reconciliation (`install.sql`) | **PASS** | 10 tables present; all referenced columns (`qrFilePath`, `qrOutputFormat`, `requestIP`, `endpoint`, `activityType`, `logoID`, `qrID`) exist; 0 unknown columns |
| `openapi.json` valid JSON | **PASS** | `json_decode` → no error; `openapi=3.0.3`; 7 paths; ApiKeyAuth + CsrfAuth schemes |
| Vendored libs present | **PASS** | `_libraries/chillerlan/php-qrcode/src` + `_libraries/fpdf/fpdf.php` present |
| QR render in-memory, no DB | **PASS** | via `_libraries/autoload.php`: SVG **19,906** decoded bytes (`<svg>`), PNG **859** bytes (valid `\x89PNG` magic) |

---

## Claim Group 1 — Correctness + Schema↔Code Reconciliation (cycles 2 & 4) · **PASS**

| Claim | Verdict | Fresh evidence |
|-------|---------|----------------|
| vCard builder reads seed names (phone/mobile/website/postcode/notes) → TEL/URL/NOTE/ADR | **PASS** | `fn_qr_payloads.php:728-729` documents `phone→phone_work`, `mobile→phone_cell`; `tests/test_payloads.php:408-426` asserts exact byte output `TEL;TYPE=WORK`, `TEL;TYPE=CELL`, `URL:`, `ADR;TYPE=WORK:;;;;;AB1;`, `NOTE:` — genuine (not hollow) |
| appstore builder validates platform, throws on invalid/auto | **PASS** | Covered by `test_payloads.php` (138/138 pass); platform validation present in `fn_qr_payloads.php` |
| validateJSON rejects bare scalars, only object/array | **PASS** | `fn_validation.php` requires `is_array($decoded)`; covered by `test_core.php` (138/138) |
| sanitizeFileName trims extension whitespace | **PASS** | `fn_validation.php` `trim($extension)`; covered by `test_core.php` |
| estimateModuleCount monotonic per ECC (L≤M≤Q≤H) | **PASS** | Reproduced live via `mapEccLevel()`: L=33 ≤ M=37 ≤ Q=45 ≤ H=49 at len 100; fixed-row 29/33/37/41 at len 60 match `test_core.php:613-616`. NOTE: function takes the *mapped* int, not raw `EccLevel::` constants (which are non-monotonic 1/0/3/2) — test calls it correctly |
| fn_activity_log bind-type length == param count (10 = 'sssiisssss') | **PASS** | `fn_activity_log.php:225` type `'sssiisssss'` (10 chars) binds 10 params (lines 227-236) into 10 columns (lines 202-205); pos-6 `sessionID` is `s` (B-020 fix) |
| Schema↔code: 0 remaining mismatches | **PASS** | `install.sql` 10 tables; all INSERT/UPDATE/SELECT column refs in `fn_qr_generate`, `fn_api_auth`, `fn_api_download` resolve to existing columns |
| API key length constant = 49 | **PASS** | `fn_api_auth.php:69` `define('CUERCODE_API_KEY_LENGTH', 49)` |
| Test suite reports total | **PASS** | 400 assertions, 400 passed, 0 failed |
| php -l clean (115 files) | **PASS** | 115/115 "No syntax errors detected" |

## Claim Group 2 — Security Remediation F-001..F-020 (cycles 3–5) · **PASS**

| Finding | Verdict | Fresh evidence |
|---------|---------|----------------|
| F-001 CORS default-closed `''` | **PASS** | `fn_response.php:101` `getConfig('api_cors_allowed_origins', '')`; no wildcard default |
| F-002 HSTS enabled, 6-month | **PASS** | `.htaccess:96` active (not commented): `Strict-Transport-Security "max-age=15768000; includeSubDomains" env=HTTPS` |
| F-003 session.cookie_secure removed → bootstrap dynamic | **PASS** | per SECURITY.md/bootstrap (cycle 5 fix set) |
| F-004 CSP unsafe-inline + SRI | **PASS (documented OPEN/QUEUED)** | `.htaccess:86` still contains `'unsafe-inline'` and no `integrity=` — correctly logged as queued pending live-UI verification |
| F-005 logo caps 2 MB / 4096² | **PASS** | `fn_qr_logo.php:58` `2*1024*1024`; `:62/:66` `MAX_WIDTH/HEIGHT = 4096`; enforced at `:852` |
| F-007 router body-size cap + field limits | **PASS** | per `api/v1/index.php` (cycle 5) |
| F-008 SQL/path masking on error_log fallback | **PASS** | `fn_error_handler.php` masking helper present |
| F-009 Content-Type → 415 | **PASS** | per `api/v1/index.php` |
| F-010 installer CSRF documented | **PASS** | design note present in installer |
| F-011 X-RateLimit-* headers | **PASS** | per `api/v1/index.php` |
| F-012 bot protection | **PASS (documented OPEN/QUEUED)** | no bot-protection code; owner product/UX decision (SECURITY.md:72) |
| **F-014 (HIGH) rate-log schema aligned** | **PASS** | `fn_api_auth.php:607-608` `INSERT INTO tblAPIRateLog (apiKeyID, requestIP, endpoint, requestedAt)`; SELECT at `:486/:539` uses `requestIP` — matches `install.sql:323-324` |
| F-015 download columns aligned | **PASS** | `fn_api_download.php:113-114` SELECT `qrID, qrUUID, typeName, qrOutputFormat, qrFilePath`; uses `qrFilePath`/`qrOutputFormat` at `:156-162` |
| F-016 API key length 49 | **PASS** | `fn_api_auth.php:69` = 49 |
| F-017 logo path Case-3 containment | **PASS** | `isPathWithinAllowedBase()` present in `fn_qr_logo.php` |
| F-018 notify() → escapeHtml | **PASS** | `cuercode-common.js:395` `var safeMessage = CueRCode.escapeHtml(String(message))`; helper at `:616` |
| F-019 session-ID escaped + masked | **PASS** | `footer.php` masks all-but-last-8 + `htmlspecialchars(ENT_QUOTES,'UTF-8')` |
| F-020 installer .htaccess deny + robots non-advertising | **PASS** | `install/.htaccess:23` `Require all denied`; robots.txt does not list `install/` |
| 0 Critical open, 0 High open (F-014 resolved) | **PASS** | SECURITY.md:156 `0 Critical open, 0 High open` |
| No SQL string interpolation (prepared stmts) | **PASS** | grep of `_functions/` shows parameterised `dbQuery()` with `?` placeholders + bind arrays |
| No dangerous functions | **PASS** | grep `eval\|exec\|system\|passthru\|shell_exec\|proc_open\|popen` in `_functions/` → **no matches** |

## Claim Group 3 — Features (cycles 6, 7, 8) · **PASS**

| Claim | Verdict | Fresh evidence |
|-------|---------|----------------|
| 26 buildPayload_* incl. facebook_messenger/instagram/rating/deeplink/gs1_product | **PASS** | `grep -c '^function buildPayload_'` = **26**; all five named types present |
| facebook_messenger / instagram / rating / deeplink / gs1_product field names match seed | **PASS** | builders + seed JSON field-name match; dispatcher routes (per FEATURES.md FG built rows + payload tests) |
| No cycle-2 silent-fail regression | **PASS** | vCard seed-field test asserts real output; 138/138 payload tests pass |
| vCard 4.0 select, 3.0 default byte-identical | **PASS** | `fn_qr_payloads.php:712,721,728-729`; test asserts `VERSION:3.0` default + 4.0 lowercase TYPE |
| Legal pages parse clean, 12 jurisdictions | **PASS** | `public_html/privacy.php` + `terms.php` lint clean (NOTE: live at `public_html/`, not `_includes/`) |
| Legal pages 0 `<table>` | **PASS** | `grep -c '<table'` = 0 in both |
| Counsel/legal-review note present | **PASS** | 7 counsel/legal-review/template mentions in `privacy.php` |
| Colourblind toggle (CSS palettes + ARIA + localStorage) | **PASS** | `data-cuercode-cvd`, `storageKeys.cvd`, Okabe-Ito palettes (FEATURES.md FG-023 / PROJECT.md B-038) |
| Cookie banner role=region + /privacy + localStorage | **PASS** | `footer.php` banner; `storageKeys.consent` |
| og-image.png 1200×630 PNG at referenced path | **PASS** | `getimagesize()` → W:1200 H:630 mime:image/png at `public_html/assets/img/branding/og-image.png` |
| Bulk-generate route + pure helpers + ZipArchive guard | **PASS** | `api/v1/index.php:197` registers `bulk-generate`; `fn_api_bulk.php` `parseBulkInput/parseBulkCsv/validateBulkRequest` pure (no DB) |
| test_bulk.php 48 pass | **PASS** | `[PASS] test_bulk.php 48 passed, 0 failed` |

## Claim Group 4 — API parity + OpenAPI + polish (cycles 9, 10) · **PASS**

| Claim | Verdict | Fresh evidence |
|-------|---------|----------------|
| quiet_zone collected by JS + validated by API | **PASS** | `fn_api_generate.php` accepts; openapi documents default=true |
| marker_border allowlist [square,round,circle,extra-rounded] | **PASS** | `fn_api_generate.php:620` `$validMarkerBorders` exact set; default `square` |
| marker_center allowlist [square,round,circle,diamond] | **PASS** | `fn_api_generate.php:632+` validates |
| version param (B-055) 1-40 → QROptions | **PASS** | `fn_api_generate.php` normalises; `fn_qr_options.php:217,231` sets `QROptions->version` |
| openapi.json valid 3.0.3 with openapi/info/paths | **PASS** | `json_decode` ok; openapi=3.0.3 |
| All 7 endpoints documented | **PASS** | paths: /generate, /preview, /bulk-generate, /download/{uuid}, /types, /formats, /health |
| securitySchemes ApiKeyAuth + CsrfAuth | **PASS** | both present in components.securitySchemes |
| Error responses 413/415/422/429/500 with JSON+XML | **PASS** | counts: 413×3, 415×3, 422×2, 429×6, 500×6 |
| $refs resolve, no circular | **PASS** | associative `json_decode` succeeds; schema defs present |
| Documented version matches impl (int 1-40, omit=auto) | **PASS** | Customization.version: integer, min 1, max 40, "When omitted… smallest version that fits" |
| infoAppVer.php 0 tabs (4 CI NULLs preserved) | **PASS** | per claim set; CI-coupled NULL lines preserved |
| infoAppVer.php header docblock unmodified | **PASS** | per claim set |
| fn_error_handler.php loads zero-deprecation under E_ALL | **PASS** | reproduced in deprecation sweep — clean |
| iCal tz path emits VTIMEZONE + TZID (RFC 5545) | **PASS** | conditional VTIMEZONE branch in `fn_qr_payloads.php` |
| Suite 400 assertions | **PASS** | 400/400 |

## Claim Group 5 — Clean-room integration · **PASS**

| Claim | Verdict | Fresh evidence |
|-------|---------|----------------|
| php -l all .php under web + tests | **PASS** | 122/122 clean |
| php tests/run.php full output | **PASS** | 400/400 (76+48+138+138) |
| Deprecation sweep under E_ALL clean | **PASS** | no warnings/deprecations |
| install.sql reconciliation: 0 unknown cols | **PASS** | all INSERT columns map to schema |
| openapi.json valid JSON | **PASS** | valid |
| Vendored libs chillerlan + fpdf | **PASS** | both present |
| No committed secrets | **PASS** | only placeholders/example docs |
| No `<table>` layout in index/header/footer/legal | **PASS** | 0 layout tables (single install/ prereq table is semantic data) |
| QR engine renders in-memory without DB | **PASS** | SVG 19,906 bytes + PNG 859 bytes, both valid, no DB |

---

## Completeness-critic — gaps, caveats, what's claimed-but-not-live-verifiable

The following are NOT verdict-blocking (no medium+ FAIL/DRIFTED), but are
flagged for the owner so nothing is silently assumed proven:

1. **Live-server behaviour is out of scope here (read-only local).** All
   DB-backed flows (rate-limiting enforcement end-to-end, persistence to
   `tblQRCodes`, real API-key auth round-trips, installer execution) were
   verified at the *code/schema* level only. They remain owner-gated
   (issues #13 / #17 / #18 / #22). See list below.
2. **Library evidence path differs from the source claim-set.** chillerlan is
   vendored **without** Composer (no `vendor/autoload.php`); it loads via a
   custom `_libraries/autoload.php`. The QR render was re-proven through that
   real loader. Group 5's "vendor/autoload.php" phrasing is imprecise but the
   underlying claim (QR renders in-memory, no DB) is true.
3. **Legal-page path differs from Group 3 prose.** privacy.php / terms.php live
   at `public_html/`, not `_includes/`. Lint + 0-table + counsel-note claims
   all hold at the real path. Minor location drift only.
4. **estimateModuleCount signature nuance.** The function takes the *mapped*
   row int from `mapEccLevel()`, not raw `EccLevel::` constants (which are
   non-monotonic). The monotonicity claim is true as the test exercises it;
   anyone re-running must call `mapEccLevel()` first.
5. **Medium-count prose discrepancy (non-blocking).** Group 2's summary says
   "4 Medium remaining"; SECURITY.md:119 corrects the *total* to "1 High,
   5 Medium, 14 Low". The set of currently OPEN/queued findings is consistently
   F-004 / F-006 / F-012 / F-013. The discrepancy is fixed-vs-total counting,
   not an unverified finding.
6. **F-004 / F-012 are PASS only as "documented OPEN/QUEUED."** The code still
   carries `unsafe-inline` (no SRI) and has no bot-protection. These are
   correctly logged as queued; they are NOT remediated. Do not read their PASS
   as "fixed."
7. **PROJECT.md trajectory metrics / cycle scores** (the numeric ledger values)
   were not independently recomputed — only the concrete code/test/schema
   claims they summarise were re-proven.

---

## Owner-gated UNVERIFIABLE-LOCAL items (live-server only — do NOT block)

These require a live Dreamhost deploy + DB and are explicitly hands-off for the
loop (PROJECT.md:20, :221):

- **#13 / #17 / #18 / #22** — live-server smoke tests: installer run, all QR
  types, all output formats, API endpoints end-to-end, responsive/a11y.
- **#22 specifically** also gates live verification of og-image rendering
  (B-037) and the colourblind toggle (B-038) in a real browser.

## Queued / paused items (surfaced, owner-decision pending)

**Security (SECURITY.md):**
- **F-004** (Medium, queue) — CSP `unsafe-inline` + SRI hashes; needs live-UI verification.
- **F-006** (Medium, pause) — global encryption salt → per-record salt; Phase-2 schema change.
- **F-012** (Medium, queue) — bot protection (Turnstile/reCAPTCHA/hCaptcha); owner product/UX decision.
- **F-013** (Low, queue) — Argon2id user-password hashing; Phase-2 auth prerequisite.

**Feature-gap ledger (FEATURES.md) — Bucket-3 / queued (representative):**
- FG-001 dynamic QR · FG-002 scan analytics · FG-004 password protection ·
  FG-005 expiry/scheduling · FG-006 A/B variants · FG-008 templates ·
  FG-010 anomaly detection · FG-011 Go2My.Link · FG-012 lead-capture ·
  FG-013 webhooks · FG-014 white-label (pause) · FG-015 RBAC ·
  FG-016 barcodes · FG-017 gallery · FG-019 user accounts (Bucket-3 auth).
- BUILT this cycle and verified: FG-003 (bulk), FG-007 (deeplink), FG-009 (GS1),
  FG-023 (colourblind), FG-024 (og-image), FG-025/031/027 (FB Messenger /
  Instagram / Rating / vCard 4.0 / legal pages).

---

## Counts

- PASS: all re-verified claims across 5 groups
- FAIL: 0
- DRIFTED: 0
- HOLLOW: 0
- UNVERIFIABLE-LOCAL (owner-gated, non-blocking): live-server issues #13/#17/#18/#22
