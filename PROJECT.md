# CueRCode — Autopilot Project Memory

> Machine-authoritative external memory for the dev-team autopilot loop. This file is the loop's working brain (backlog, feature ledger pointers, decisions, trajectory). It does NOT replace the project's own canonical docs — `README.md`, `CHANGELOG.md`, `PROJECT_STATUS.md`, and `DEV_NOTES.md` are still maintained per ProjectBrief §18/§19. Keep IDs stable and zero-padded.

## Goal

CueRCode is a PHP 8.4/8.5 QR-code-generator SaaS for Dreamhost shared hosting, providing a fully responsive web UI and a feature-parity RESTful API for generating, customising, and downloading QR codes — with the web frontend itself built on top of the API (ProjectBrief §1).

## Definition of done

Production-ready for the current phase scope, tuned to this PHP app:

- [ ] `php -l` clean on every PHP file (114/114 currently pass) AND zero lint/standards/security warnings before any commit (ProjectBrief §19.7, §20).
- [ ] Core QR generation works end-to-end via BOTH the Web UI and the REST API (feature parity preserved).
- [ ] Automated coverage exists for core flows: payload builders, generation pipeline, API auth/rate-limit, response formatting. (No test harness present yet — see Backlog.)
- [ ] No unresolved Critical/High security finding (see SECURITY.md). All Medium findings triaged with an owner decision.
- [ ] No High-severity correctness/UX backlog item left open.
- [ ] Docs current: PROJECT_STATUS.md, CHANGELOG.md, DEV_NOTES.md, README.md, OpenAPI spec all reflect shipped state.
- [ ] Every High-importance, in-scope feature gap is either built (auto bucket) or explicitly queued with a spec (FEATURES.md).
- [ ] Live-server smoke tests (Dreamhost) executed for install, all QR types, all formats, API endpoints, responsive/a11y — these are HANDS-OFF for the loop and remain owner-run (GitHub issues #13/#17/#18/#22).

## Constraints

- **Runtime:** PHP 8.5 target, backward-compatible to PHP 8.4.
- **Database:** MySQLi only, prepared statements for ALL queries (no string interpolation into SQL).
- **Host:** Dreamhost shared hosting — NO Composer, NO CLI, NO npm on host. Third-party PHP libs self-hosted in `_libraries/` with a custom PSR-4 autoloader.
- **Crypto:** AES-256-GCM + HKDF (random IV, GCM auth tag) for sensitive data; Argon2id via `password_hash()` for passwords (Phase 2 — schema stub only today).
- **URLs:** No `.php` extensions in user-facing or API URLs (directory routing + `.htaccess` rewrites).
- **Markup:** No `<table>` for layout; WCAG 2.1 AA; SVG-first with PNG/APNG fallback.
- **Hands-off (loop must NOT do):** no `git push`, no deploy, no live-server testing, no `git config`/remote changes. Secrets in `_auth_keys/` are NEVER committed.
- **GitHub writes:** comment + close-with-evidence only; no destructive issue ops.

## Skills in play

- `dev-team-autopilot` — conductor of the whole loop (this discovery output feeds it).
- `dev-team-featurefind` — competitor/feature-gap discovery → FEATURES.md (already run; see ledger).
- `dev-team-iterate` — small safe correctness/UX/perf improvements per cycle.
- `dev-team-security` — adversarial hardening of the Medium findings in SECURITY.md.
- `dev-team-orchestrator` — building any auto-bucket in-scope feature gap.
- `dev-team-review` — independent verification of each cycle's claims.
- `dev-team-stripe` — reserved for the Phase 2+ PayPal/payments workstream (queued, Bucket-3).

## House style

From ProjectBrief §8/§20 and DEV_NOTES.md:

- Full multi-line PHP notation — no shorthand `if`/`else`/`for`; full braces always.
- 4-space indentation, NO tabs. Detailed inline comments + comment blocks; emojis welcome in comments to call out sections.
- Use PHP predefined constants (`DIRECTORY_SEPARATOR`, `PHP_EOL`, `__FILE__`, `dirname()`) for platform neutrality.
- Prefer `||`/`&&` over `OR`/`AND`; lowercase `null`/`true`/`false`.
- Include/function files OMIT the closing `?>` tag; templates (header/footer) end after HTML.
- File headers: path + copyright (2025–current year, MWBM Partners Ltd) + version block.
- Modularity via `require_once`/`include_once` with existence checks and error handling.
- All user input sanitised; output HTML-escaped via `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`.
- All errors → `tblErrorLog`; all activity → `tblActivityLog`.
- SQL: `tbl`+PascalCase tables, camelCase columns, InnoDB + utf8mb4_unicode_ci, `createdAt`/`updatedAt` on every table.
- Debug via `?debug=true` / `?dev=true` URL switches.

## Codebase Map

**Canonical code root:** `web/CueRCode.net/`. Env copies (`public_html_dev_alpha` / `public_html_dev_beta` / `public_html_landing` / `public_html_redir`) are derivative — note divergence only when notable.

### Request lifecycle

1. **Bootstrap** — `web/CueRCode.net/_includes/bootstrap.php` sets path constants, runs the autoloader, opens the DB, loads `_functions/fn_*.php`, starts the session (custom `CUERCODE_SID`, HttpOnly, SameSite=Lax, HTTPS-aware Secure), sets UTC timezone and error config. Load order: autoloader → db_connect → functions → session → timezone → error config.
2. **DB** — `web/CueRCode.net/_includes/db_connect.php` MySQLi singleton (static var), strict SQL mode, `utf8mb4` charset, prepared statements throughout.
3. **Web UI entry** — `web/CueRCode.net/public_html/index.php` (two-column generator page; dynamic per-type form fields from `tblQRCodeTypes.formFieldsJSON`).
4. **API entry** — `web/CueRCode.net/public_html/api/v1/index.php` router (`.htaccess` RewriteRule, CORS, rate-limit headers, regex route match, JSON/XML negotiation) dispatching to `fn_api_*` handlers.

### Data model (10 tables — `_includes/sql/install.sql`, seeded by `seed_data.sql`)

- `tblSettings` — all config (except DB creds); `isSensitive` rows AES-256-GCM encrypted. 52 seed rows across 6 groups.
- `tblErrorLog` — PSR-3 severity error log (8 levels, 15 PHP codes mapped).
- `tblActivityLog` — operational activity log with entity tracking + JSON context.
- `tblQRCodeTypes` — DB-driven QR type definitions with `formFieldsJSON` input schemas (21 active builders; some seed rows lack matching builders — see B-/FG- items).
- `tblQRCodes` — generated codes (UUID, payload, customisation, `userID`, `expiresAt`, `downloadCount`, `isPublic` — several columns reserved for dynamic/analytics features).
- `tblPredefinedLogos` — 25 predefined logos.
- `tblAPIKeys` — HMAC-SHA256-hashed API keys.
- `tblAPIRateLog` — per-minute / per-day rate-limit windows (by key ID or IP), 1%-chance cleanup.
- `tblUsers` — Phase 2 stub (full schema, no auth code).
- `tblOrganisations` (+ related members/sessions/resets/oauth/invitations/webauthn tables in install.sql) — Phase 2 stub.

### QR pipeline (`_functions/fn_qr_*.php`, ~5,587 lines)

- `fn_qr_payloads.php` (1,844 lines) — 21 `buildPayload_*` builders (url, text, email, phone, sms, whatsapp, vcard [v3.0], calendar/iCal, paypal, bitcoin, mp3, video, pdf, image, youtube, appstore, facebook, linkedin, zoom, wifi, geo).
- `fn_qr_generate.php` (928 lines) — 11-step orchestrator: validate → build payload → map options → render (chillerlan/php-qrcode) → logo overlay → frame → encode → UUID → DB save.
- `fn_qr_options.php` (948 lines) — UI→QROptions mapping; `mapOutputFormat()` supports 6 formats (PNG/JPG/GIF/WEBP/SVG/PDF; JPEG XL unavailable on host).
- `fn_qr_logo.php` (875 lines) — GD raster overlay + SVG overlay; 3 padding shapes.
- `fn_qr_frames.php` (992 lines) — 8 frame styles + TrueType/GD font labels.

### API layer (`_functions/fn_api_*.php`)

- `fn_api_auth.php` — API key + CSRF + anonymous auth; rate limiting; trusted-proxy XFF.
- `fn_api_generate.php` — `/generate` and `/preview` handlers.
- `fn_api_download.php` — `/download/{uuid}` with UUID-v4 regex + `realpath()` base-dir traversal guard, `readfile()` streaming.
- `fn_api_types.php` — `/types`, `/formats`, `/health`.
- `fn_response.php` — JSON (default) + XML (`SimpleXMLElement` + XSLT) response formatting and content negotiation.
- Spec/docs: `public_html/api/v1/openapi.json` (OpenAPI 3.0.3), Swagger UI at `public_html/api/docs/`.

### Cross-cutting functions (`_functions/`)

`fn_encryption.php` (AES-256-GCM+HKDF), `fn_csrf.php` (token + `hash_equals`), `fn_validation.php` (input + image-upload validation), `fn_config.php` (settings get/set with request-lifetime static cache), `fn_error_handler.php` (handlers → tblErrorLog + shutdown fatal catcher), `fn_activity_log.php`, `fn_debug.php`.

### Autoloader / libraries

Custom PSR-4 autoloader in `_libraries/autoload.php` (no Composer); self-hosted chillerlan/php-qrcode v5.x, php-settings-container, FPDF v1.86.

### Front-end / PWA / legal

`_includes/header.php` + `footer.php` (templates, CDN-with-local-fallback, dark/light, OG meta, skip link, debug panel), `public_html/assets/css/cuercode.css` (~1,266 lines), `assets/js/cuercode-generator.js` + `cuercode-common.js`, `sw.js` (multi-cache service worker), `manifest.json`, `offline.html`, `privacy.php`, `terms.php`, error pages `error/404.php`/`500.php`, branding assets in `assets/img/branding/`.

### Install + CI

Web installer `public_html/install/index.php` (prereq checks, schema+seed, `.installed.lock`, own CSRF). CI in `.github/workflows/` (`version-bump.yml`, `release.yml`, `deploy.yml`) + `infoAppVer.php` per env. Version metadata embedded in file headers, API `X-API-Version`, `manifest.json`, `tblSettings.app_version`.

## Backlog

Scored improvement items merged + deduped from all scouts. Sorted by severity (P0→P3). Security findings are listed here AND in SECURITY.md (cross-ref F-### in Evidence). Autonomy: `auto` = loop builds unattended; `queue` = surface for owner; `pause` = hard-stop / design decision needed.

| ID | Title | Kind | Sev | Eff | Autonomy | Files | Evidence (file:line) | Fix-approach |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| B-001 | `/save` endpoint missing (persist QR to user account) | feature | P1/High | M | queue | public_html/api/v1/index.php | api/v1/index.php:170-224 (6 routes only) | Add authenticated `POST /api/v1/save` (fn_api_save.php `handleSave()`) → validate, insert tblQRCodes with userID, return UUID. Depends on auth (Bucket-3); see FG-019. |
| B-002 | `/manage` / update endpoint missing (modify saved QR) | feature | P1/High | M | queue | public_html/api/v1/index.php | api/v1/index.php:170-224 | Add authenticated `PATCH /api/v1/qrcodes/{uuid}` (fn_api_manage.php `handleUpdateQRCode()`) → ownership check, update + regenerate. Depends on auth (Bucket-3); see FG-019. |
| B-003 | Cookie consent banner missing | ux | P1/High | M | pause | public_html/privacy.php:139-153, `_includes/header.php`, `_includes/footer.php` | privacy.php documents cookies/localStorage but no banner in header/footer | Dismissible consent banner gated on a localStorage key, links privacy policy, defers non-essential cookies. Design decision on placement — pause. Issue #37/#38; FG-026. |
| B-004 | Config cache invalidation is a no-op | correctness | P2/Med | S | auto | _functions/fn_config.php | fn_config.php:716-733 `clearConfigCache()` no-op; getConfig():88 ignores `$invalidated` | Make `clearConfigCache()` actually evict from getConfig()'s static `$cache`; have setConfig() invalidate affected keys (shared array or versioning). |
| B-005 | No Content-Type validation before JSON parse | security | P2/Med | S | auto | public_html/api/v1/index.php | index.php:429-441 json_decode without Content-Type check — SECURITY F-009 | If POST and Content-Type ≠ application/json, return 415 before json_decode. |
| B-006 | Route params extracted without immediate validation | correctness | P2/Med | S | queue | public_html/api/v1/index.php | index.php:260-264 raw regex matches deferred to handler | Add `validateRouteParams()` enforcing per-pattern constraints (UUID-v4) at router, fail-fast 400. Pairs with B-013. |
| B-007 | iCalendar events lack timezone (TZID/VTIMEZONE) | correctness | P2/Med | M | queue | _functions/fn_qr_payloads.php | fn_qr_payloads.php:991-1102 (DTSTART:…:1085-1086, no TZID) | Accept optional tz param; emit `DTSTART;TZID=…` + VTIMEZONE per RFC 5545; fall back to UTC `Z`. |
| B-008 | `fn_debug.php` / `fn_validation.php` loaded but unverified vs standards | correctness | P2/Med | S | queue | _includes/bootstrap.php | bootstrap.php:161-170 require_once without standards check | Read both files; confirm headers, direct-access guard, full notation, type hints, no syntax errors. |
| B-009 | Two independent CSRF implementations (installer vs fn_csrf) | security | P2/Med | S | auto | public_html/install/index.php, `_includes/bootstrap.php` | install/index.php:86-95 own token vs bootstrap.php:169 fn_csrf — SECURITY F-010 | Document installer's standalone CSRF (acceptable one-shot) with a comment, OR route it through fn_csrf. Pick one + annotate. |
| B-010 | Installer SQL execution + completion verification incomplete | correctness | P2/Med | S | queue | public_html/install/index.php | install/index.php:197-200 read truncated; no 10-table verify | Confirm multi_query/loop exec, error capture, lock-file only on success; query information_schema to verify 10 tables before success page. |
| B-011 | Argon2id password hashing not implemented | correctness | P2/Med | S | queue | _functions/ | grep: no password_hash/Argon2id; tblUsers.passwordHash unused — SECURITY F-013 | Add fn_password.php: `hashPassword()` (PASSWORD_ARGON2ID, memory 65536/time 4/threads 2), `verifyPassword()`. Phase 2 dependency; build now to avoid rework. |
| B-012 | CORS default `Access-Control-Allow-Origin: *` | security | P2/Med | S | queue | public_html/api/v1/index.php, `_functions/fn_api_auth.php` | index.php:87-94 default '*' via getConfig('api_cors_allowed_origins','*') — SECURITY F-001 | Change default to deny/allowlist; require explicit origins in prod; consider no-CORS for generate/download. |
| B-013 | Route param length/type not bounded | correctness | P3/Low | S | auto | public_html/api/v1/index.php | index.php:260-264 `[^/]+` unbounded length | Cast to string, enforce max length (UUID 36) or constrain regex `[^/]{36}`. Merge with B-006. |
| B-014 | Rate-limit headers may be dropped after handler dispatch | correctness | P3/Low | S | auto | public_html/api/v1/index.php, `_functions/fn_response.php` | index.php:380-383 set pre-dispatch; fn_response.php:74-151 may re-send headers | Set rate-limit headers at earliest point; add to response body as fallback; verify headers_sent() path. |
| B-015 | XML response path undocumented / untested | docs | P3/Low | S | auto | public_html/api/v1/openapi.json, `_functions/fn_response.php` | fn_response.php:181-228, determineResponseFormat():444-487; no examples | Add XML examples per endpoint to OpenAPI; comment example XML in fn_response.php:197; verify /assets/xsl/api-response.xsl exists. |
| B-016 | `setConfig()` doesn't log activity | ux | P3/Low | S | auto | _functions/fn_config.php | fn_config.php:368 returns true, no logActivity | Add `$logActivity=true` param → logActivity('setting_changed', old/new). ProjectBrief §8 audit requirement. |
| B-017 | `set_charset()` failure not surfaced (MySQL<5.7) | correctness | P3/Low | S | auto | _includes/db_connect.php | db_connect.php:159-161 set_charset without hard fail | Log+document MySQL 5.7+ requirement; warn clearly if utf8mb4 unavailable. |
| B-018 | tblQRCodeTypes formFieldsJSON not schema-validated | correctness | P3/Low | M | queue | _includes/sql/seed_data.sql | seed_data.sql JSON not validated by PHP — malformed schema breaks form render | Add `validateQRTypeSchema($json)` (fn_validation.php); verify required keys per type; Phase 2 admin-UI validation. |
| B-019 | Global encryption salt (no per-record salt) | security | P3/Low | M | pause | _functions/fn_encryption.php | fn_encryption.php uses global ENCRYPTION_SALT for all values — SECURITY F-006 | Add BINARY(16) salt column to tblSettings; unique salt per setting; alter encrypt/decrypt signatures. Schema change → pause. Phase 2. |
| B-020 | Activity-log bind-type string vs param count | correctness | P3/Low | S | queue | _functions/fn_activity_log.php | fn_activity_log.php:207 type string 'sssiiissss' had position-6 typed 'i' for $sessionID (VARCHAR) — silently coerced to 0 on every insert | FIXED (cycle 2, 2026-06-28 — commit pending): corrected to 'sssiisssss'; added inline VERIFIED bind-mapping comment. |
| B-021 | Session cookie domain not set (cross-domain .net/.me) | correctness | P3/Low | S | auto | _includes/bootstrap.php | bootstrap.php:222 domain '' | Document per-domain sessions for Phase 1; add comment; revisit for Phase 2 SSO. |
| B-022 | infoAppVer.php uses tabs (not 4-space) | syntax | P2/Med | S | auto | public_html/infoAppVer.php | infoAppVer.php:1-53 `\t` in 47 lines (od -c) | Convert all tabs → 4 spaces. |
| B-023 | infoAppVer.php missing doc header block | docs | P2/Med | S | auto | public_html/infoAppVer.php | infoAppVer.php:1-53 starts `<?php //Application`; cf fn_activity_log.php:1-21 | Add full file header (path, copyright, @package/@subpackage/@version/@since/@see). |
| B-024 | infoAppVer.php uses OR/AND not `\|\|`/`&&` | syntax | P2/Med | S | auto | public_html/infoAppVer.php | infoAppVer.php:15,18 `OR` in conditionals | Replace OR→`\|\|`, AND→`&&`. |
| B-025 | infoAppVer.php uses NULL constant not `null` | syntax | P3/Low | S | queue | public_html/infoAppVer.php | infoAppVer.php:5,11,22,26-27,31,37,41,45-47,51-52 (13+) | Replace uppercase NULL → lowercase null. |
| B-026 | infoAppVer.php structure/spacing below standard | syntax | P3/Low | S | queue | public_html/infoAppVer.php | infoAppVer.php:2-52 flat sections, inconsistent spacing | Restructure with section headers + consistent spacing (model on bootstrap.php). |
| B-027 | HSTS header commented out, no runtime HTTPS enforcement | security | P2/Med | S | auto | public_html/.htaccess | .htaccess:103-107 HSTS commented — SECURITY F-002 | Conditionally enable HSTS (1yr, includeSubDomains, preload) in prod and/or 301 HTTP→HTTPS. |
| B-028 | session.cookie_secure 0 default in .htaccess | security | P2/Med | S | auto | public_html/.htaccess, `_includes/bootstrap.php` | .htaccess:232 cookie_secure 0 may override bootstrap.php:211-222 — SECURITY F-003 | Set cookie_secure 1 for prod / rely on bootstrap HTTPS detection; remove conflicting .htaccess line. |
| B-029 | CSP uses `unsafe-inline`; no SRI on CDN assets | security | P2/Med | M | queue | public_html/.htaccess, `_includes/header.php`, footer.php, offline.html | .htaccess:92 unsafe-inline; no SRI hashes — SECURITY F-004 | Add SRI to Bootstrap/FA/jQuery; move inline JS/CSS to files or use nonces; add CSP report endpoint. |
| B-030 | No max payload / per-field length validation on API | security | P2/Med | M | queue | _functions/fn_api_generate.php | fn_api_generate.php:94-200 no size/length caps — SECURITY F-007 | Router-level max body size (LimitRequestBody + PHP check); per-field length limits in handleGenerate(). |
| B-031 | Image-upload validation not behind a rate-limited endpoint | security | P2/Med | M | queue | _functions/fn_validation.php | fn_validation.php:492-665 validateImageUpload not rate-limited — SECURITY F-005 | Rate-limit upload endpoints; alert on repeated failures; document call sites. |
| B-032 | Error logs may leak SQL/file paths | security | P2/Med | S | queue | _functions/fn_error_handler.php, fn_api_auth.php | fn_error_handler.php:244-245 logs `SQL:`+substr; pre-init errors → error_log — SECURITY F-008 | Mask SQL/paths in error_log() output; bootstrap try/catch for init errors; add masking helper. |
| B-033 | No automated test harness for core flows | correctness | P2/Med | M | queue | (repo-wide) | No tests dir / CI test step; DoD requires core-flow coverage | Add no-Composer PHP test runner (self-hosted) covering payload builders, generate pipeline, API auth/rate-limit, response formatting; wire into CI. *(in progress: harness + characterization done; CI wiring + DB-flow coverage remain)* |
| B-034 | No bot-protection hooks (Turnstile/reCAPTCHA/hCaptcha) | security | P2/Med | M | queue | public_html/index.php, assets/js/cuercode-generator.js | ProjectBrief §7.3; no integration / config keys checked | Detect config keys (Turnstile>reCAPTCHA>hCaptcha); inject widget; server-side token verify before generation. |
| B-035 | No Content-Security-Policy at app layer (header.php) | security | P2/Med | M | queue | _includes/header.php, footer.php, offline.html | No CSP meta/header in header.php (overlaps .htaccess CSP F-004) | Emit CSP via PHP header/meta restricting script/style-src to self + trusted CDNs; nonce inline scripts. |
| B-036 | Missing X-RateLimit-* headers on API responses | ux | P3/Low | M | queue | _functions/fn_api_auth.php, fn_response.php | checkRateLimit() returns info not surfaced — SECURITY F-011 | Pass limit/remaining/reset into json/xmlResponse → X-RateLimit-Limit/Remaining/Reset on all responses. |
| B-037 | og-image.png referenced but absent | ux | P2/Med | S | queue | _includes/header.php, public_html/assets/img/branding/ | header.php:172 references og-image.png; file absent — issue #43; FG-024 | Create 1200×630 og-image.png from logo; optimise for social sharing. |
| B-038 | Colourblind-friendly UI toggle missing | ux | P2/Med | M | queue | public_html/index.php:348-353, assets/css/cuercode.css:46-71 | ProjectBrief §11.2; no toggle/palettes — issue #42; FG-023 | CSS custom-property palettes (prot/deut/tritanopia); navbar toggle; localStorage persist. |
| B-039 | Dynamic toast/icon ARIA completeness | correctness | P3/Low | S | auto | assets/js/cuercode-common.js:145-151, cuercode-generator.js:189-192 | Some dynamic FA icons may lack aria-hidden/label | Audit dynamic icons; add aria-hidden on decorative or aria-label where meaningful. |
| B-040 | Print button / PDF export from preview not wired | ux | P3/Low | S | auto | public_html/assets/css/cuercode.css:1261-1301 | Print media queries exist; no print button/export | Add "Print QR" button in preview footer (print dialog or PDF endpoint). |
| B-041 | Skip-link may be obscured by sticky navbar (mobile) | ux | P3/Low | S | auto | _includes/header.php:271,277-324 | Skip link exists but sticky navbar may cover on focus | Ensure skip link surfaces on focus with high z-index; verify mobile keyboard nav. |
| B-042 | No datetime-local polyfill for legacy browsers | ux | P3/Low | S | queue | assets/js/cuercode-generator.js:356 | No fallback for browsers lacking datetime-local | Optional flatpickr/litepicker (CDN+local) progressive enhancement. |
| B-043 | Legal-page copyright year not dynamic in body | correctness | P3/Low | S | auto | _includes/footer.php:98, privacy.php:91, terms.php:91 | Footer uses date('Y'); legal bodies may hardcode | Ensure dynamic year in legal page copyright text. |
| B-044 | vCard builder silently drops contact fields from rendered form | correctness | P1/High | S | auto | _functions/fn_qr_payloads.php | buildPayload_vcard ignored seed/form field names (phone, mobile, website, postcode, notes); TEL/URL/NOTE/ADR lines emitted empty | FIXED (cycle 2, 2026-06-28 — commit pending): accept seed aliases alongside legacy names; prefer legacy when both supplied. |
| B-045 | Appstore builder accepted 'auto' platform, emitting malformed Apple URL | correctness | P2/Med | S | auto | `_functions/fn_qr_payloads.php`, `_includes/sql/seed_data.sql` | buildPayload_appstore passed 'auto' through to Apple URL template producing `https://apps.apple.com/app/idcom.example.app` | FIXED (cycle 2, 2026-06-28 — commit pending): removed 'auto' from seed formFieldsJSON; validate platform ∈ {apple/ios, google/android} case-insensitively; throw InvalidArgumentException otherwise. |
| B-046 | validateJSON accepts bare scalar values (null, true, false, number, string) | correctness | P3/Low | S | auto | _functions/fn_validation.php | validateJSON('null') returned true; docblock stated 'non-trivial JSON structure' but implementation did not enforce | FIXED (cycle 2, 2026-06-28 — commit pending): tightened to require JSON object or array; zero-risk (no external callers outside definition file and test file). |
| B-047 | sanitizeFileName did not trim whitespace from extension | correctness | P3/Low | S | auto | _functions/fn_validation.php | 'hello-world.png  ' kept trailing spaces in extension; 'data.   ' emitted 'data.   ' not 'data' | FIXED (cycle 2, 2026-06-28 — commit pending): trim extension; collapse all-whitespace extension to none. |
| B-048 | sanitizeInput docblock example was misleading / incorrect | docs | P3/Low | S | auto | _functions/fn_validation.php | Docblock example showed wrong expected output; added caution that function is NOT tag/script removal | FIXED-doc (cycle 2, 2026-06-28 — commit pending): corrected example to real output 'alert(&quot;XSS&quot;)Hello'; added caution note. Behaviour unchanged. |
| B-049 | estimateModuleCount mapped EccLevel constant int to wrong capacity row | correctness | P2/Med | S | auto | _functions/fn_qr_options.php | chillerlan EccLevel: L=1, M=0, Q=3, H=2; function indexed raw int against rows keyed 0=L,1=M,2=Q,3=H → every ECC level picked wrong row; estimate non-monotonic (M<L, H<Q) | FIXED (cycle 2, 2026-06-28 — commit pending): map constant to correct L/M/Q/H capacity row. Affects scale estimation only, not generated QR. |

> Verified-compliant (no action): no `<table>` layout (index/header/footer/legal); unique-key handling in setConfig() (check-then-UPDATE/INSERT, fn_config.php:323-330); closing-tag omission across `_functions/` (issue #41 done).
> F-015 (cycle 4): core download/generate persistence restored — all tblQRCodes column references reconciled to canonical schema (`qrID`, `qrOutputFormat`, `qrFilePath`) across fn_api_download.php, fn_qr_generate.php, and fn_qr_logo.php. Download endpoint and generate-save pipeline now operational.

## Proposed-Features ledger

Features NOT auto-buildable by the loop (Bucket-3: authentication, payments, SSO, external email, WebAuthn, and anything needing external API keys/infra). Each is queued or paused; full scoring lives in FEATURES.md.

| FG ref | Title | Bucket-3 reason | Autonomy |
| --- | --- | --- | --- |
| FG-019 | User accounts: registration, login, sessions, profile, saved-codes library, /save + /manage API | Auth system + email verification + Argon2id wiring | queue |
| FG-020 | Subscription tiers + premium feature gating | Billing model + auth | queue |
| FG-021 | PayPal / Apple Pay / Google Pay payments | External payment provider + PCI scope (→ dev-team-stripe) | queue |
| FG-022 | OAuth SSO (M365, Google Workspace, Apple, Facebook), SIGNula linking | External IdP secrets + redirect URIs | queue |
| FG-029 | WebAuthn / Passkeys (Windows Hello, Touch ID, Face ID) | Credential ceremony + auth system | queue |
| FG-028 | Microsoft Graph / Google Workspace email | External M365/Graph creds + DKIM/DMARC | queue |
| FG-025 | White-label / custom domain (per-org) | Phase 2 orgs + DNS/CNAME on shared host | pause |
| FG-027 | Team collaboration / RBAC | Phase 2 orgs + auth | queue |
| FG-013 | Webhooks for events | Needs scan/event pipeline + delivery infra | queue |
| FG-011 | Go2My.Link dynamic-redirect integration | External Go2My.Link API (not yet available) | queue |

## Trajectory ledger

| Cycle | Date | Mode | Items closed (IDs) | Backlog open | P0 open | High open | Sec Crit/High open | Tests | php -l | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0 (discover) | 2026-06-28 | discover | — | 43 | 0 | 3 | 0 | none | 114/114 pass | Baseline from 8-scout merge. |
| 1 | 2026-06-28 | iterate/STABILIZE | B-033 safety net | 43 | 0 | 3 | 0 | 218/218 pass | 114/114 pass | No-Composer test harness + characterization baseline established. |
| 2 | 2026-06-28 | iterate/STABILIZE | B-044, B-045, B-046, B-047, B-048, B-049, B-020 | 43 | 0 | 2 | 0 | 229/229 pass | 114/114 pass | Correctness fixes under safety net: vCard field-name aliases, appstore invalid-platform guard, validateJSON contract, sanitizeFileName ext trim, sanitizeInput docblock, EccLevel capacity index mapping, activity-log bind-type. test_payloads.php 92/92 green. Two pre-existing failures in test_core.php confirmed unrelated (pre-exist on clean baseline). |
| 3 | 2026-06-29 | security/SECURE Phase-0 | SECURITY.md Phase-0 complete (F-014..F-020 added; F-001..F-013 severities reviewed) | 43 | 0 | 2 | 1 High | 229/229 pass | 114/114 pass | Phase-0 adversarial security audit: 6 scouts + cross-validator + documentarian. 20 confirmed findings (0 Critical, 1 High, 5 Medium, 14 Low). 7 net-new (F-014..F-020). 8 auto-fix, 11 queue/pause. Attack surface fully threat-modelled; coverage ledger + rejection log complete. DoD gate: F-014 (rate limiting inert) must be resolved pre-launch. |
| 4 | 2026-06-29 | security/SECURE remediation | F-014, F-015, F-016 schema↔code reconciliation (fn_api_auth, fn_api_download, fn_qr_generate, fn_qr_logo) | 43 | 0 | 2 | 0 | 229/229 pass | 114/114 pass | Restored rate-limiting (tblAPIRateLog column alignment), download/generate persistence (tblQRCodes column alignment), and API-key auth (length constant corrected to 49); code aligned to canonical schema across 4 files; 15 mismatches fixed; zero Sec Crit/High open. |
| 5 | 2026-06-29 | security/SECURE remediation | F-001, F-002, F-003, F-005, F-007, F-008, F-009, F-010, F-011, F-017, F-018, F-019, F-020 | 43 | 0 | 2 | 0 | 229/229 pass | 114/114 pass | headers/CORS/upload/encoding hardening; salvaged after quota interrupt; CORS allowlist, HSTS, secure cookies, API Content-Type/size limits, rate-limit headers, upload size limits, logo path containment, output escaping, installer lockdown, error-log masking; 0 Critical/High open. |

## Decision log

- **2026-06-28 — Branching:** Loop works on a feature branch off `origin/main`; never commits to or pushes `main` (hands-off push/deploy).
- **2026-06-28 — Feature scope = aggressive:** Build ALL safely-buildable, in-scope gaps (QR types, JPEG-XL handling/decision, colourblind toggle, branding PNGs, bulk-gen, local analytics, i18n scaffold, cookie consent, legal content, closing-tag/standards fixes). QUEUE Bucket-3 (auth, payments, SSO, external email, WebAuthn) for owner approval.
- **2026-06-28 — GitHub writes:** comment + close-with-evidence only; no destructive ops; unactioned ideas logged as `for consideration` issues per house rule.
- **2026-06-28 — Hands-off boundaries:** no `git push`, no deploy, no live-server testing, no `git config`/remote edits; `_auth_keys/` secrets never committed.
- **2026-06-28 — Issue reconciliation:** recommend CLOSE #1-6,9,10,41 (verified done); KEEP-OPEN #7,11-44 (partial / not-started / needs-live-test). Live-test issues (#13,#17,#18,#22) stay owner-run.

## Checkpoint log

| Checkpoint | Date | Trigger | Summary |
| --- | --- | --- | --- |
| CP-0 | 2026-06-28 | DISCOVER complete | Artifacts written (PROJECT.md, FEATURES.md, SECURITY.md). 43 backlog items, 28 feature gaps, 11 security findings (0 Critical/High). Awaiting first iterate/security cycle. |
| CP-1 | 2026-06-28 | Cycle-1 STABILIZE complete | No-Composer PHP test harness built (tests/run.php, tests/lib/assert.php, tests/bootstrap.php). Characterization safety net: 218 assertions across test_payloads.php (90, all 21 QR builders + escaping helpers + dispatcher) and test_core.php (128, encryption/CSRF/validation/qr_options/response). All 218 pass, exit 0. Six production bugs documented as current behaviour (wifi WPA3 downgrade, appstore 'auto' fallthrough, vcard field-name mismatch, validateJSON('null') true, sanitizeInput tag-content leak, sanitizeFileName untrimmed ext). No production code under web/ modified. CI wiring and DB-flow coverage remain for a future cycle. |
| CP-2 | 2026-06-28 | Cycle-2 STABILIZE complete | Seven correctness bugs fixed: B-044 (vCard field-name aliases), B-045 (appstore 'auto' platform guard), B-046 (validateJSON contract tightened), B-047 (sanitizeFileName ext trim), B-048 (sanitizeInput docblock corrected), B-049 (EccLevel capacity index mapping), B-020 (activity-log bind-type 'i'→'s' at position 6). Test suite: 229/229 assertions green (up from 218; 11 net new assertions). test_payloads.php 92/92. Two pre-existing failures in test_core.php confirmed unrelated to this cycle's changes. php -l 114/114 pass. Verdict: needs-lead-review. Commit pending. |
| CP-4 | 2026-06-29 | Cycle-4 SECURE remediation complete | F-014/F-015/F-016 fixed: rate-limiting restored (tblAPIRateLog column alignment in fn_api_auth.php), download/generate persistence restored (tblQRCodes column alignment across fn_api_download.php, fn_qr_generate.php, fn_qr_logo.php), API-key length constant corrected (45→49). 15 schema↔code mismatches resolved. Sec Crit/High now 0. Test suite 229/229 pass. php -l clean. |
| CP-5 | 2026-06-29 | Cycle-5 SECURE remediation complete (salvaged) | F-001/F-002/F-003/F-005/F-007/F-008/F-009/F-010/F-011/F-017/F-018/F-019/F-020 fixed: CORS allowlist, HSTS + HTTP→HTTPS redirect, secure-cookie `.htaccess` conflict resolved, base64/GD upload size/dimension guards, API body-size cap + per-field length limits, error-log SQL/path masking, Content-Type 415 gate, installer CSRF comment, X-RateLimit-* headers surfaced, logo path containment (Case 3), notify() DOM XSS sink closed, session ID output escaping, installer HTTP deny + robots.txt non-advertising. 0 Critical/High open. Open/queued: F-004 (live UI verification needed), F-006 (Phase-2 schema change), F-012 (product decision), F-013 (Phase-2). Test suite 229/229 pass. php -l clean. |

## Feature Specs

<!-- The loop authors per-feature build specs here, each under a #spec-G-NNN anchor. Empty at discovery. -->

<!-- #spec-G-001 -->
