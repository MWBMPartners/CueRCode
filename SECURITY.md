# CueRCode — Security Register

> Phase-0 (dev-team-security) threat-modelling and adversarial PoC pass is complete. This register reflects the full Phase-0 audit across six surfaces with 6+ independent adversarial scouts, a cross-validator, and a documentarian. Findings are cross-referenced to PROJECT.md backlog IDs (B-###). IDs are stable and zero-padded.

## Attack surface

Phase-0 audited the following surfaces in depth. For each surface the trust boundary is noted and the audit scope is summarised.

### 1. REST API — auth, authorization & access control

**Files:** `public_html/api/v1/index.php`, `_functions/fn_api_auth.php`, `_functions/fn_api_download.php`
**Trust boundary:** The internet (anonymous callers) to authenticated generate/preview/download endpoints. Anonymous callers may only reach health, types, and formats. CSRF token (session-bound, `hash_equals`, 3600 s TTL) is required for web-UI generate/preview. API keys (HMAC-SHA256, `hash_equals`) are a Phase-2 feature — not yet wired.
**Audited:** Timing-safe comparison paths, anonymous-fallback scope, API-key validation and length checks, rate limiting logic end-to-end (schema vs code), trusted-proxy XFF gating, download UUID/path-traversal guard.
**PoC method:** Static analysis only — no live instance available.

### 2. REST API — input handling, payload size, CORS, Content-Type

**Files:** `public_html/api/v1/index.php`, `_functions/fn_api_generate.php`, `_functions/fn_response.php`, `public_html/api/v1/.htaccess`
**Trust boundary:** Raw HTTP request body (JSON) from untrusted callers.
**Audited:** `php://input` read with no Content-Type gate, per-field length caps, CORS `Access-Control-Allow-Origin` default and `.htaccess` hardcode, header-injection via CORS config value (rejected — PHP header() blocks CR/LF since 5.1.2).
**PoC method:** Static analysis; PHP version behaviour for header() confirmed from PHP changelog.

### 3. File handling — QR generation & download pipeline

**Files:** `_functions/fn_qr_generate.php`, `_functions/fn_qr_logo.php`, `_functions/fn_api_download.php`, `_functions/fn_validation.php`
**Trust boundary:** Authenticated caller supplying customization JSON (logo path/data URI) → GD/FPDF → stored file → streaming download.
**Audited:** tblQRCodes column names vs schema (install.sql), download UUID+realpath guard, base64 logo decoded-size and pixel-dimension limits, file-path logo case with realpath base-dir containment, predefined-logo DB lookup column names, SVG polyglot/MIME, GD loadLogoImage safety.
**PoC method:** Static analysis; schema cross-checked against install.sql line by line.

### 4. Cryptography, secrets & session management

**Files:** `_functions/fn_encryption.php`, `_functions/fn_csrf.php`, `_includes/bootstrap.php`, `public_html/install/index.php`
**Trust boundary:** Internally trusted code paths; secrets stored in `_auth_keys/` (never committed, `.htaccess` deny-all, `.gitignore`).
**Audited:** AES-256-GCM with HKDF, random IV per encryption, GCM auth-tag verification, CSRF token entropy and timing-safe compare, session-cookie flags, API-key length constant vs generated key length.
**PoC method:** PHP calculation (string lengths verified): `strlen('cuercode_') + strlen(bin2hex(random_bytes(20)))` = 9 + 40 = 49; constant = 45 → mismatch confirmed.

### 5. Output encoding & XSS — web UI, templates, JavaScript, error pages

**Files:** `public_html/index.php`, `_includes/header.php`, `_includes/footer.php`, `assets/js/cuercode-common.js`, `assets/js/cuercode-generator.js`, error pages
**Trust boundary:** Server-controlled HTML output and client-side DOM manipulation from API responses.
**Audited:** `htmlspecialchars(ENT_QUOTES,'UTF-8')` coverage in all PHP templates, `CueRCode.escapeHtml()` usage in JS form-field rendering, jQuery(toastHtml) sink in `notify()`, API response field names (`message` vs `error`), session ID echo in debug panel.
**PoC method:** Static analysis; traced API response field names from `fn_response.php:269-275` to `cuercode-generator.js:1126-1127`.

### 6. HTTP headers, transport, installer & directory protection

**Files:** `public_html/.htaccess`, `public_html/api/v1/.htaccess`, `_*/.htaccess` (6 deny-all files), `public_html/install/index.php`, `sw.js`, `robots.txt`
**Trust boundary:** Network perimeter (HTTP/HTTPS) → Apache → PHP.
**Audited:** HSTS, HTTP→HTTPS redirect, `session.cookie_secure` vs bootstrap dynamic detection, CSP `unsafe-inline`, SRI on CDN assets, CORS default in `.htaccess`, installer re-run protection via `.installed.lock`, `robots.txt` advertising `/install/`, directory deny-all coverage, service-worker scope, security headers (`X-Content-Type-Options`, `X-Frame-Options`, `X-XSS-Protection`, `Referrer-Policy`, `Permissions-Policy`).
**PoC method:** Static analysis; `.htaccess` and `robots.txt` read directly.

**Strong baseline confirmed across all surfaces:** prepared statements throughout (`bind_param`, no string interpolation into SQL), `htmlspecialchars(ENT_QUOTES,'UTF-8')` on HTML output, CSRF + `hash_equals`, timing-safe API-key compare, UUID-v4 validation + `realpath` base-dir check on downloads, per-key/per-IP rate-limiting logic (inert due to schema bug — see F-014), secure session flags in bootstrap, deny-all `.htaccess` on all non-public directories, AES-256-GCM with random IV + auth-tag.

---

## Findings

Severity key: Critical → system-wide exploit; High → significant abuse/data exposure; Medium → meaningful security gap; Low → defence-in-depth / latent risk. Status: `open` unless marked fixed. Bucket: `auto-fix` = loop applies mechanically; `queue` = surfaces to owner for decision; `pause` = blocked on design choice / Phase-2 dependency.

| ID | Title | Severity | Surface | Evidence (file:line) | Status | Fix-approach | Bucket |
| --- | --- | --- | --- | --- | --- | --- | --- |
| F-001 | CORS policy overly permissive by default (`Access-Control-Allow-Origin: *`) | Medium | REST API / CORS | `public_html/api/v1/index.php:87-94`; `public_html/api/v1/.htaccess:35` (hardcoded `*`) | **FIXED (cycle 5)** — CORS allowlist enforced; `Access-Control-Allow-Origin` now resolves to explicit origin or denied; `.htaccess` hardcode removed. | Default to allowlist; require explicit prod origin list from tblSettings; validate origin format as defence-in-depth; also fold the `.htaccess` hardcode into the fix. (B-012) | queue |
| F-002 | HSTS header commented out; no runtime HTTPS enforcement | Low *(was Medium — confirmed intentionally deferred to prod cut-over; no live deployment)* | Transport / .htaccess | `public_html/.htaccess:87` (HSTS commented); `:25-26` (force-HTTPS commented) | **FIXED (cycle 5)** — `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload` enabled in prod block; 301 HTTP→HTTPS redirect wired; local-dev comment preserved. | Enable `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload` and 301 HTTP→HTTPS at production go-live; keep commented for local dev with a clear comment. (B-027) | queue |
| F-003 | `session.cookie_secure 0` in `.htaccess` conflicts with bootstrap HTTPS detection | Low *(was Medium — bootstrap dynamic override makes this cosmetic over HTTPS)* | Session / .htaccess | `public_html/.htaccess:132`; `_includes/bootstrap.php:216-223` | **FIXED (cycle 5)** — `php_value session.cookie_secure 0` removed from `.htaccess`; bootstrap dynamic HTTPS detection now authoritative; secure-cookie flag set correctly over HTTPS. | Remove `php_value session.cookie_secure 0` (or gate it `<IfDefine LOCAL_DEV>`) and rely solely on bootstrap dynamic value; pair with F-002. (B-028) | auto-fix |
| F-004 | CSP uses `unsafe-inline`; no SRI on CDN resources | Medium | HTTP headers / CDN supply-chain | `public_html/.htaccess:81` (`script-src 'unsafe-inline' https://cdn.jsdelivr.net`); `_includes/header.php`; `_includes/footer.php` | **PARTIALLY FIXED (deep-sweep #47)** — SRI added for Bootstrap CSS + Bootstrap JS bundle (both `@5.3.3`) in `header.php`/`footer.php`, hashes copied verbatim from `public_html_landing/index.php` (verified same pinned version before copying — an incorrect hash would block the resource). FontAwesome (`@6.5.1`) and jQuery (`@3.7.1`) are NOT referenced on the landing page, so no existing verified hash could be safely copied for those two — each carries an explicit `<!-- TODO F-004: capture SRI hash for <lib>@<ver> at live deploy -->` comment instead of a guessed hash. CSP `unsafe-inline` → nonce migration is **STILL OPEN** — genuinely needs live UI verification (inline `<script>` blocks must be audited against real rendered pages before nonce-ing them) and is deferred to the live-hardening pass (see PROJECT_ROADMAP.md E10 runbook step 6). | Capture FontAwesome@6.5.1 and jQuery@3.7.1 SRI hashes at live deploy (from the actual served CDN response, matching the pinned version exactly); replace CSP `unsafe-inline` with nonces once inline scripts are enumerated against live pages; add CSP report endpoint. (B-029, B-035) | queue |
| F-005 | Base64/GD logo processing lacks decoded-size and pixel-dimension limits (memory-exhaustion) | Low *(was Medium — endpoint requires CSRF auth; bounded by `post_max_size 12M`/`memory_limit 128M`)* | File upload / GD | `_functions/fn_qr_logo.php:637-710` (`saveBase64LogoToTemp` no decoded-size cap); `loadLogoImage` no pixel-dimension bound | **FIXED (cycle 5)** — decoded-byte cap (8 MB) added in `saveBase64LogoToTemp`; pixel-dimension guard (≤ 8000×8000) added via `getimagesize()` before GD load; same guards added to `validateImageUpload()`. | Add decoded-byte cap (e.g. 8 MB after base64-decode) in `saveBase64LogoToTemp`; add pixel-dimension cap (e.g. ≤ 8000×8000) checked via `getimagesize()` before GD load; add same guards to `validateImageUpload()` before any upload route ships. (B-031) | queue |
| F-006 | Global encryption salt (no per-record salt) | Medium | Crypto / tblSettings | `_functions/fn_encryption.php` (global `ENCRYPTION_SALT` for all rows) | **OPEN — QUEUED** (G-010 queued) — schema change requires owner decision on per-record salt migration. Phase 2. | Add `BINARY(16)` per-record salt column to `tblSettings`; generate unique salt per setting; alter `encrypt()`/`decrypt()` signatures. Schema change → owner decision. (B-019) | pause |
| F-007 | No max-payload / per-field length validation on API | Low *(was Medium — generate/preview require auth; Apache `post_max_size 12M`/`memory_limit 128M` limit blast radius)* | REST API / DoS | `public_html/api/v1/index.php:430` (no body-size check); `_functions/fn_api_generate.php:94-200` (no per-field length caps) | **FIXED (cycle 5)** — router-level `Content-Length` body-size cap added before `json_decode`; per-field length limits added in `handleGenerate()` (url ≤ 2048, vCard fields ≤ 500). | Add router-level body-size cap (PHP `Content-Length` check before `json_decode`); add per-field length limits in `handleGenerate()` (e.g. url ≤ 2048, vCard fields ≤ 500). (B-030) | queue |
| F-008 | Sensitive data (SQL text, file paths) written to error logs | Medium | Logging | `_includes/db_connect.php:244-245,262-263` (SQL+driver error in exception detail); `_functions/fn_error_handler.php:454-458` (`error_log()` fallback writes `$detail` unredacted) | **FIXED (cycle 5)** — SQL/path masking helper added; `error_log()` fallback now redacts SQL text and file paths before writing; technical detail split to DB-only log channel. | Add SQL/path masking helper (truncate/redact before `error_log()`); split technical detail (DB log only) from the fallback channel; schema-mismatch bugs above make routine DB errors frequent, raising leak volume. (B-032) | queue |
| F-009 | No Content-Type validation before JSON parsing | Low *(was Medium — invalid JSON already rejected with 400; no WAF bypass vector; REST-correctness gap)* | REST API | `public_html/api/v1/index.php:429-449` | **FIXED (cycle 5)** — POST requests with non-empty body now require `Content-Type: application/json` (charset suffix allowed); 415 returned otherwise before `json_decode`. | When method is POST and body is non-empty, require `Content-Type` to begin with `application/json` (allow `; charset=utf-8` suffix) else return 415. (B-005) | auto-fix |
| F-010 | Two independent CSRF implementations (installer vs fn_csrf) | Low *(was Medium — installer's standalone one-shot CSRF is sound; `random_bytes(32)` + `hash_equals`)* | CSRF | `public_html/install/index.php:86-116`; `_includes/bootstrap.php:169` | **FIXED (cycle 5)** — installer standalone CSRF documented with clear comment explaining pre-bootstrap one-shot context and acceptability; no functional change. | Document installer's standalone CSRF with a clear comment explaining why the one-shot standalone is acceptable (pre-bootstrap context, single-use token); optionally route through `fn_csrf`. (B-009) | auto-fix |
| F-011 | Missing `X-RateLimit-*` headers on API responses | Low | REST API (DX / info) | `_functions/fn_api_auth.php` (`checkRateLimit()` returns info, never surfaced in headers) | **FIXED (cycle 5)** — `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` now surfaced on all API responses from `checkRateLimit()` data; rate limiting functional (F-014 fixed in cycle 4). | Surface `limit`/`remaining`/`reset` via response headers (`X-RateLimit-Limit`, `-Remaining`, `-Reset`). Note: moot while rate limiting is inert (F-014). Fix F-014 first. (B-036) | queue |
| F-012 | No bot-protection hooks (Turnstile/reCAPTCHA/hCaptcha) | Medium | Web UI / API abuse | `public_html/index.php`; `assets/js/cuercode-generator.js` (ProjectBrief §7.3) | open | Detect config keys (Turnstile > reCAPTCHA > hCaptcha priority); inject widget on generate form; server-side token verify before generation. Owner product/UX decision. (B-034) | queue |
| F-013 | No Argon2id user-password hashing implemented | Low | AuthN (Phase 2) | `_functions/` (no `password_hash`/Argon2id); `tblUsers.passwordHash` unused | open | Implement `fn_password.php` with `PASSWORD_ARGON2ID` (memory 65536 / time 4 / threads 2) + `verifyPassword()`; Phase-2 prerequisite — build before any auth route ships. (B-011) | queue |
| F-014 | Rate limiting non-functional — code vs schema column mismatch in tblAPIRateLog | **High** | REST API / rate limiting | `_functions/fn_api_auth.php:606-614` (INSERT uses `ipAddress`,`requestPath`); `_includes/sql/install.sql:320-335` (schema has `requestIP` NOT NULL, `endpoint` NOT NULL, `requestMethod`, `responseCode`); `fn_api_auth.php:485,538` (SELECT `ipAddress`); `:509,562` (exception caught → `allowed=true`) | **FIXED (cycle 4)** — `recordAPIRequest()` INSERT/SELECT aligned to canonical schema columns (`requestIP`, `endpoint`, `requestMethod`); rate-limiting restored end-to-end. | Align `recordAPIRequest()` INSERT with schema: rename `ipAddress`→`requestIP`, `requestPath`→`endpoint`, add `requestMethod` bind; align both SELECT paths to `requestIP`; add `endpoint`+`requestMethod` params to the function signature. Add a burst-test (insert + check row count). Also fix F-015 (no rows persisted → per-key counting moot anyway). (B-012 adjacent; net-new) | auto-fix |
| F-015 | Download endpoint broken — tblQRCodes column mismatch (also breaks generate persistence and predefined-logo lookup) | Low *(correctness/availability; security relevance is it neutralises any future download IDOR)* | File download / QR generation | `_functions/fn_api_download.php:110` (SELECT `qrCodeID,typeName,qrFormat,filePath` — none exist in schema); `_includes/sql/install.sql:199-249` (columns: `qrID`,`qrOutputFormat`,`qrFilePath`; no `typeName`,`filePath`); `_functions/fn_qr_generate.php:851-854` (INSERT uses wrong column names); `:914` (`UPDATE filePath WHERE qrCodeID`); `_functions/fn_qr_logo.php:563` (SELECT `logoFilePath` — absent from `tblPredefinedLogos`) | **FIXED (cycle 4)** — all four call sites reconciled to install.sql canonical column names (`qrID`, `qrOutputFormat`, `qrFilePath`); download, generate persistence, and predefined-logo lookup restored. | Reconcile all four call sites to install.sql column names (`qrID`, `qrOutputFormat`, `qrFilePath`; join `tblQRCodeTypes` for type name); add a generate→download round-trip integration test. (net-new) | auto-fix |
| F-016 | API key length constant (45) mismatches generated key length (49) — all API-key auth fails (fail-closed) | Low *(fail-closed: valid key rejected, no bypass; API-key feature not yet wired in UI/routes)* | API key auth | `_functions/fn_api_auth.php:279` (`CUERCODE_API_KEY_LENGTH=45`); key generation: `'cuercode_'` (9) + `bin2hex(random_bytes(20))` (40) = 49 chars; also prefix doc comment incorrectly states 5-char prefix | **FIXED (cycle 4)** — `CUERCODE_API_KEY_LENGTH` corrected to 49; prefix doc comment fixed; API-key auth now accepts correctly-generated keys. | Set `CUERCODE_API_KEY_LENGTH=49`; fix prefix doc comment; confirm `tblAPIKeys.apiKeyHash` length is unaffected (stores 64-char HMAC); add generate/validate round-trip unit test. (net-new) | auto-fix |
| F-017 | Attacker-controlled logo file path resolved with no base-directory containment | Medium | File handling / logo pipeline | `_functions/fn_qr_logo.php:503-518` (`getLogoPath()` Case 3: request-supplied `customization.logo` string passed through `realpath()` with no base-dir check — unlike download handler); `fn_qr_logo.php:472-474` (`buildCustomizationFromRequest` passes logo unchanged) | **FIXED (cycle 5)** — Case 3 path now constrained via `isPathWithinAllowedBase()` to `realpath`-under-`CUERCODE_UPLOADS_DIR/logos`; any path resolving outside the allowed directory is rejected. Note: `getLogoPathFromDatabase()` (Case 1, predefined logos, admin-set DB filename) is lower risk (not directly request-controlled) but flagged for a future defence-in-depth pass. | Constrain Case 3 to `realpath`-under-`CUERCODE_UPLOADS_DIR/logos`; reject any path that does not resolve inside the allowed directory; owner to confirm whether temp dir (base64 path) should also be allowed. GD bounds the disclosure to image files only, but the containment gap is a genuine least-privilege defect. (net-new) | queue |
| F-018 | `notify()` DOM sink injects API message without HTML-escaping (latent DOM XSS) | Low *(no reachable exploit today — server emits in `message` field, not `error`; generator reads `response.error` which is always undefined; latent — future field-name alignment would weaponise)* | Web UI / JavaScript | `assets/js/cuercode-common.js:189` (`toastHtml` concat + `jQuery(toastHtml)`); `CueRCode.escapeHtml()` exists at `:404` but not applied | **FIXED (cycle 5)** — `message` now wrapped in `CueRCode.escapeHtml()` inside `notify()`; DOM XSS sink closed unconditionally. | Wrap `message` in `CueRCode.escapeHtml()` inside `notify()` — one-line fix, zero behaviour change, closes the sink unconditionally. (net-new) | auto-fix |
| F-019 | Unescaped session ID echoed in debug-panel footer | Low *(session_id() charset is `[a-z0-9,-]`; cannot contain HTML metacharacters; dual-gated debug panel)* | Output encoding | `_includes/footer.php:255` (`echo $sessionId;` with no `htmlspecialchars`) | **FIXED (cycle 5)** — `htmlspecialchars($sessionId, ENT_QUOTES, 'UTF-8')` applied; last-8-chars masking added for baseline consistency. | Apply `htmlspecialchars($sessionId, ENT_QUOTES, 'UTF-8')` (and optionally mask all but last 8 chars) for baseline consistency. (net-new) | auto-fix |
| F-020 | Installer directory has no HTTP deny rule and is advertised in robots.txt | Low *(re-execution guarded by `.installed.lock`; residual = info disclosure of install status)* | Installer / HTTP config | `public_html/install/` (no `.htaccess` deny); `public_html/robots.txt:15` (`Disallow:/install/` — advertises path to scanners) | **FIXED (cycle 5)** — `Require all denied` `.htaccess` added to `public_html/install/`; `robots.txt` `Disallow:/install/` entry replaced with a non-advertising alternative. | Owner decides: delete `install/` post-setup OR add a `Require all denied` `.htaccess`; either way remove or replace the `robots.txt` `Disallow` with a non-advertising entry (e.g. `Disallow:` or omit). (net-new) | queue |

---

## Coverage ledger

Phase-0 static analysis complete. PoC method for all surfaces: static code + schema review (no live instance). Rejected/false-positive items noted to prevent re-audit.

| Surface | Threat-modelled | PoC attempted | Result | Rejected / false-positives (do not re-audit) |
| --- | --- | --- | --- | --- |
| REST API auth (key/CSRF/anon) | DONE | Static | Sound with two new defects: F-014 (rate-log schema mismatch — rate limiting entirely inert, fail-open), F-016 (API key length constant off-by-4 — fail-closed, feature not yet wired). Timing-safe paths, CSRF, and trusted-proxy XFF all correct. | "auth bypass via key present-but-invalid" (fail-closed, not a bypass); "null/empty validateAPIKey" (confirmed secure by auditor); "X-Forwarded-For bypass" (requires admin misconfiguration, not a code defect). |
| REST API input/DoS (payload size, field length) | DONE | Static | F-007 confirmed (no per-field length limits); downgraded to Low — endpoint requires auth, Apache limits bound blast radius. F-009 confirmed (no Content-Type gate, Low). | "unauth DoS via generate/preview" (rejected — those endpoints require CSRF auth). |
| CORS / cross-origin abuse | DONE | Static | F-001 confirmed (permissive `*` default + `.htaccess` hardcode, Medium). Header-injection claim REJECTED — PHP header() blocks CR/LF since PHP 5.1.2; no user-controlled write path to tblSettings in current code. | "CRITICAL HTTP Response Splitting via CORS config" (PHP 5.1.2+ prevents CR/LF injection in header(); not exploitable). |
| File download (path traversal) | DONE | Static | Download UUID/realpath guard correct. F-015 identified (tblQRCodes column mismatch makes download endpoint return 500 on every request — primary defect is correctness). | "IDOR on /download/{uuid}" (rejected — ownership columns are Phase-2 stubs, no auth boundary exists; download itself broken by F-015). |
| File/image upload & logo pipeline | DONE | Static | F-017 identified (logo file-path Case 3 no base-dir containment, Medium). F-005 confirmed (no pixel/byte limits in base64 path, Low). SVG XSS mitigation present and correct. validateImageUpload() has no call sites yet. | "SVG logo overlay XSS via Content-Disposition inline" (mitigated: SVG content screened, `X-Content-Type-Options: nosniff`, `Content-Disposition: attachment`; residual within existing F-004); "SVG accepts image/svg+xml but not re-validated after GD" (GD cannot rasterise SVG → logo silently dropped; no exploit). |
| Crypto (AES-GCM, key/salt handling) | DONE | Static | F-016 identified (API key length constant mismatch, Low). AES-256-GCM + HKDF correct; random IV per call; GCM tag verified on decrypt; no weak RNG; F-006 (global salt) remains open design-decision. | "global salt causes identical ciphertext for identical plaintext" (rejected — random 12-byte IV per encryption means identical plaintext DOES NOT produce identical ciphertext); "no per-record salt → pattern leak" (same rejection). |
| CSRF (web + installer) | DONE | Static | F-010 confirmed (two implementations, Low — installer standalone is sound). No bypass found. | "authenticateAPIRequest() fallthrough when key present-but-invalid" (working as designed, fail-safe); "missing bot protection" (F-012, product decision, not a code defect). |
| Transport / headers (HSTS, cookie_secure, CSP/SRI) | DONE | Static | F-002 (HSTS commented, Low), F-003 (cookie_secure conflict cosmetic over HTTPS, Low), F-004 (unsafe-inline + no SRI, Medium) all confirmed. | "service worker without explicit scope" (default scope `/` is intended; sw.js excludes /api/; no same-origin attack path in single-tenant deployment). |
| Logging / info disclosure | DONE | Static | F-008 confirmed (SQL/path in error_log() fallback, Medium). Schema-mismatch bugs raise routine DB error volume. Error responses to clients are generic — no client-facing leak. | "High / unauth response leak via errorResponse()" (rejected — `display_errors Off`, error text never echoed to client, only at-rest log). |
| Output encoding / XSS / debug info | DONE | Static | F-018 (notify() latent DOM XSS sink, Low), F-019 (session ID unescaped in debug panel, Low) identified. All PHP template output correctly escaped. | "CRITICAL DOM XSS via unescaped API error messages" (rejected as Critical — server emits error text in `message` field, generator reads `response.error` which is always undefined → static fallback string used; no reachable XSS currently). |
| Bot/abuse protection | DONE | Static | F-012 confirmed (no bot-protection hooks, Medium — product/owner decision). | — |
| Auth (Phase 2: register/login/session/OAuth/WebAuthn) | DONE | Static | Not yet built — tblUsers is an unused stub. F-013 (Argon2id not wired, Low) confirmed. No auth surface to audit in Phase 1. | "missing Argon2id" as a present vulnerability (no authentication exists to harden yet). |
| Installer | DONE | Static | F-020 identified (no HTTP deny + robots.txt advertises path, Low). Re-run guarded by `.installed.lock`. | "installer re-execution via lock-file bypass" (lock check present and atomic within PHP; TOCTOU window is negligible). |

---

## Phase-0 summary

**Audit scope:** 6 independent adversarial scouts + 1 cross-validator (adversarial re-verification) + 1 documentarian. Static analysis only — no live instance available.

**Confirmed findings: 20** (F-001 through F-020)

| Severity | Count | IDs |
| --- | --- | --- |
| Critical | 0 | — |
| High | 1 | F-014 |
| Medium | 4 | F-001, F-004, F-008, F-012, F-017 *(5 Medium; F-012 included)* |
| Low | 15 | F-002, F-003, F-005, F-006, F-007, F-009, F-010, F-011, F-013, F-015, F-016, F-018, F-019, F-020 + F-006 |

> Corrected count: **1 High, 5 Medium, 14 Low** (F-006 is Medium/pause; F-012 is Medium/queue — total Medium = 5, total Low = 14, grand total = 20).

**Net-new findings (not in original F-001..F-013):** F-014, F-015, F-016, F-017, F-018, F-019, F-020 — **7 net-new**.

**Severity adjustments from Phase-0 validator:**

- F-001: retained Medium (header-injection claim rejected; CORS permissiveness confirmed)
- F-002: downgraded Medium → Low (intentionally deferred; no live deployment)
- F-003: downgraded Medium → Low (bootstrap dynamic override makes conflict cosmetic over HTTPS)
- F-005: downgraded Medium → Low (auth required; Apache limits bound blast)
- F-007: downgraded Medium → Low (auth required; bounded)
- F-009: downgraded Medium → Low (400 already returned on invalid JSON; REST-correctness gap)
- F-010: downgraded Medium → Low (installer standalone CSRF is sound)

**Remediation plan:**

*Auto-fix bucket (7 findings — loop applies mechanically):*
F-003, F-009, F-010, F-014, F-015, F-016, F-018, F-019
*(F-003 = remove conflicting cookie_secure directive; F-009 = 415 Content-Type gate; F-010 = add comment; F-014 = align rate-log column names; F-015 = align tblQRCodes column names across four call sites; F-016 = fix key length constant; F-018 = escapeHtml in notify(); F-019 = htmlspecialchars on session ID)*

*Queue bucket (requires owner decision or design input before fixing):*
F-001, F-002, F-004, F-005, F-006 (pause), F-007, F-008, F-011, F-012, F-013, F-017, F-020

*Pause bucket:*
F-006 (schema change — owner decision on per-record salt migration)

**Definition-of-done gate:** 1 High (F-014) must be resolved before production launch. All 5 Medium findings must be triaged with an owner decision.

---

## Remediation log

| Cycle | Findings fixed | Findings still open/queued |
| --- | --- | --- |
| 4 | F-014, F-015, F-016 | F-001..F-013, F-017..F-020 open |
| 5 | F-001, F-002, F-003, F-005, F-007, F-008, F-009, F-010, F-011, F-017, F-018, F-019, F-020 | F-004 QUEUED (needs live UI verification); F-006 QUEUED (G-010 queued; Phase-2 schema change); F-012 QUEUED (owner product/UX decision); F-013 Phase-2 (pre-auth Phase-1) |
| Deep-sweep (#47, 2026-07-10) | F-004 PARTIALLY FIXED (Bootstrap CSS+JS SRI added; FontAwesome/jQuery hashes deferred to live deploy — TODO comments in place, no guessed hashes). Full re-audit of all six Phase-0 surfaces re-confirmed the existing baseline with **zero new OWASP-relevant findings** (SQLi, XSS/JS-sink, CSRF, dangerous-function, secrets, path-traversal probes all clean — see deep-sweep evidence below). | F-004 (CSP unsafe-inline→nonce still open, needs live UI); F-006 (queued, G-010, per-record salt — schema change folds into E3/E4 migration); F-012 (queued, owner bot-protection decision); F-013 (Phase-2, pre-auth) |

After cycle 5: **0 Critical open, 0 High open**. Open/queued: F-004 (partially fixed — SRI done for 2/4 CDN tags, CSP nonce migration still open), F-006 (queued), F-012 (queued), F-013 (Phase-2).

---

## Deep-sweep pass (#47, 2026-07-10) — syntax/lint/security re-audit

Owner directive: fix ALL syntax/lint/security findings regardless of severity/age, **including the `public_html_dev_alpha`/`public_html_dev_beta` environment copies the prior cycles never touched**. Full detail and the DS-1..DS-10 worklist live in `PROJECT_ROADMAP.md` → "Security / Syntax / Lint Deep-Review Plan"; this section records the outcome against the security register specifically.

**Scan evidence (re-run after every fix in this pass):**

- `php -l` across `web/` + `tests/`: **123/123 clean** (up from 122 — one additional file now in the tree since the prior sweep).
- Deprecation sweep: all 18 `_functions/fn_*.php` files loaded individually under `php -d error_reporting=E_ALL -d display_errors=1` — **zero Deprecated/Warning/Notice** output.
- `php tests/run.php`: **400/400 assertions pass**.
- OWASP re-audit probes re-run against the full tree (not just the files touched this pass): dangerous functions (`eval`/`exec`/`shell_exec`/`system`/`passthru`/`popen`/`proc_open`/`assert`/`create_function`) — **zero hits outside vendored `_libraries/`**; SQL — the only string-interpolated query (`install/index.php:369`, `SELECT COUNT(*) FROM \`{$table}\``) uses a **hardcoded PHP array key**, never user input, and MySQLi cannot bind identifiers — confirmed safe, not a new finding; JS DOM sinks (`innerHTML`/`insertAdjacentHTML`/`jQuery()`) in `cuercode-common.js`/`cuercode-generator.js` — the one live sink (`jQuery(toastHtml)`) still passes through `CueRCode.escapeHtml()` per the existing F-018 fix, no new sink introduced; secrets — `gitleaks` CLI not available in this sandbox, so a thorough manual grep was run instead (tracked-file check, `.env` check, private-key-marker check, AWS-key-pattern check, high-entropy credential-assignment check) — **zero hits**; only `.htaccess` deny-all files are git-tracked under any `_auth_keys/` directory (`auth_creds.example.php` remains untracked/gitignored by the existing `**/_auth_keys/*.php` pattern — pre-existing, not a new item).
- **Result: zero new OWASP-relevant findings.** The findings fixed this pass (DS-1 through DS-10 below) are house-standards/hygiene items, not new security defects — except F-004's partial SRI completion, which is tracked in the findings table above.

**DS-1..DS-10 worklist outcome:**

| ID | What | Outcome |
| --- | --- | --- |
| DS-1 | `public_html_dev_alpha/.htaccess`, `public_html_dev_beta/.htaccess` — `Require valid-user` commented out, dev envs unprotected as shipped | **FIXED** — both files now default to `Require all denied` (Apache 2.4, `mod_authz_core`) with an `Order deny,allow` / `Deny from all` Apache 2.2 fallback as the ACTIVE default; the `AuthType`/`AuthUserFile`/`Require valid-user` Basic-Auth block is retained, fully commented, with numbered instructions for the owner to create `.htpasswd` on the server and switch over. Net effect: both dev environments are DENIED to the public until the owner deliberately opts in — fail-closed by default. |
| DS-2 | `public_html_dev_alpha/infoAppVer.php`, `public_html_dev_beta/infoAppVer.php` — never received the cycle-10 standards pass | **FIXED** — both rewritten to full house style (4-space indent, no tabs, doc header with `Path:`/`@copyright`, `\|\|`/`&&`, lowercase `null`, section spacing); all `$app` values preserved (Version Number `0.1.0` unchanged in both). |
| DS-3 | `.github/workflows/deploy.yml` + `public_html/infoAppVer.php:68-71` — fragile `' = NULL'` (uppercase) sed-match injection | **FIXED** — replaced with a marker-comment injection: each of the four Repo→Commit assignments now carries a trailing `// @build-info: <key>` anchor comment; `deploy.yml` matches on the anchor text, not the literal value/casing. This let all four lines (in all three `infoAppVer.php` copies — production + both dev channels) normalise to house-standard lowercase `null`. Verified end-to-end with a simulated injection run (`php -l` clean before and after, correct value substitution). |
| DS-4 | `_includes/header.php`, `_includes/footer.php` — zero `integrity=` attributes on CDN tags | **PARTIALLY FIXED** — see F-004 above. |
| DS-5 | `_functions/fn_validation.php:492-665` `validateImageUpload()` — no call sites | **ANNOTATED** — docblock now explicitly documents it as the reserved validator for the future account-era (E3) file-upload logo path, cross-references `saveBase64LogoToTemp()` as today's active anonymous-era base64 logo path, and instructs future implementers to apply the same size/dimension caps. Not wired into any route (none exists yet) — annotation only, no behaviour change. |
| DS-6 | `install.sql:349` — `tblUsers.passwordHash` comment says "bcrypt" | **FIXED** — comment corrected to "Argon2id (PASSWORD_ARGON2ID)" per ProjectBrief §7.1; comment-only, no schema change. |
| DS-7 | `fn_encryption.php` global `ENCRYPTION_SALT` (F-006/G-010) | **DEFERRED** (as directed) — schema change, folds into the E3/E4 migration; owner decision (G-010) still pending. |
| DS-8 | No bot-protection hooks (F-012/B-034) | **DEFERRED** (as directed) — owner must supply Turnstile/reCAPTCHA/hCaptcha keys before this can be wired up. |
| DS-9 | `.openai/` at repo root — empty stray directory | **FIXED** — confirmed empty and untracked (`git ls-files` returned nothing under it), removed with `rmdir`. |
| DS-10 | `.claude/ProjectcBrief_Chat.claude` (typo'd) vs `.claude/ProjectBrief_Chat.claude` | **REPORTED, NOT DELETED** — `diff` shows they are genuinely different content (two distinct historical chat-transcript exports — the typo'd Feb 2026 file and the correctly-named Jun 2026 file describe different QR-type lists and phrasing, not a superset/subset of each other). Per the instruction to report rather than guess when files differ, this is left for an explicit owner decision rather than deleted. |

**Additional finding fixed during the sweep (not on the original DS list):** `public_html/api/docs/index.php` ended with a trailing `?>` closing tag (the only entry-point page in `public_html/` that still had one — every other page/error/install page already omits it). Removed for consistency with the house no-trailing-close-tag standard; not a security defect (this file is never `include`d by another script), but a genuine standards inconsistency the sweep's `?>` scan surfaced.

**Also fixed during the sweep (missing file headers, house-standards item explicitly called out in the scan matrix but broader in scope than previously catalogued):** 32 non-vendor PHP files across `_functions/`, `_includes/`, `public_html*/`, and both `_auth_keys/auth_creds.example.php` templates were missing the `Path:` and `@copyright` docblock fields mandated by the project's own `.claude/CLAUDE.md` ("File headers must include: file path, copyright"). All 32 were fixed mechanically (script-verified idempotent, `php -l` clean, 400/400 tests green before/after). Every non-vendor `.php` file in the tree now has both fields.
