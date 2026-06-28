# CueRCode — Security Register

> Seed register from the DISCOVER security scout. Phase-0 (dev-team-security) will threat-model, expand the attack surface, attempt PoC exploitation, and complete the coverage ledger. Findings are cross-referenced to PROJECT.md backlog IDs (B-###). IDs are stable and zero-padded.

## Attack surface (preliminary)

- **Public REST API** (`public_html/api/v1/index.php` + `fn_api_*`): unauthenticated/anonymous endpoints (health, types, formats), authenticated generate/preview/download; raw `php://input` JSON parsing; regex route-param extraction; CORS; rate limiting via tblAPIRateLog.
- **Web UI generator** (`public_html/index.php` + `cuercode-*.js`): CSRF-protected AJAX, dynamic per-type form fields, logo/image upload, no bot-protection hook yet.
- **File handling**: QR file generation/streaming (`fn_api_download.php` UUID+realpath traversal guard), image-upload validation (`fn_validation.php`), user-supplied logos (base64/upload).
- **Crypto & secrets**: AES-256-GCM+HKDF for tblSettings sensitive rows (global salt today); `_auth_keys/auth_creds.php` (DB creds, ENCRYPTION_KEY/SALT — never committed); API keys HMAC-SHA256 hashed.
- **Auth (Phase 2, not yet built)**: tblUsers/orgs/sessions/oauth/webauthn stubs; Argon2id not yet wired — future surface to harden before launch.
- **Installer** (`public_html/install/index.php`): one-shot schema/seed, own CSRF, `.installed.lock`.
- **HTTP headers / transport** (`public_html/.htaccess`): security headers present; HSTS commented out; session.cookie_secure default 0; CSP uses unsafe-inline; no SRI on CDN assets.
- **Logging**: tblErrorLog/tblActivityLog; error_log() fallback may include SQL/file paths.

Strong baseline already in place: prepared statements throughout, output `htmlspecialchars(ENT_QUOTES,UTF-8)`, CSRF + `hash_equals`, timing-safe API-key compare, UUID-v4 validation + realpath base-dir check, per-key/per-IP rate limiting, secure session flags via bootstrap.

## Findings

Severity mapping: P1→High, P2→Medium, P3→Low (no Critical found at discovery). Status: all `open` pending Phase-0 verification/remediation.

| ID | Title | Severity | Surface | Evidence (file:line) | Status | Fix-approach |
| --- | --- | --- | --- | --- | --- | --- |
| F-001 | CORS policy overly permissive by default (`Access-Control-Allow-Origin: *`) | Medium | REST API / CORS | public_html/api/v1/index.php:87-94 (default via getConfig('api_cors_allowed_origins','*')); _functions/fn_api_auth.php | open | Default to deny/allowlist; require explicit prod origins; consider disabling CORS for generate/download. (B-012) |
| F-002 | HSTS header commented out; no runtime HTTPS enforcement | Medium | Transport / .htaccess | public_html/.htaccess:103-107 (commented) | open | Conditionally enable HSTS (max-age 1yr, includeSubDomains, preload) in prod and/or 301 HTTP→HTTPS. (B-027) |
| F-003 | session.cookie_secure default 0 may override bootstrap HTTPS detection | Medium | Session / .htaccess | public_html/.htaccess:232; _includes/bootstrap.php:211-222 | open | Set cookie_secure 1 in prod / rely on bootstrap dynamic detection; remove conflicting .htaccess directive. (B-028) |
| F-004 | CSP uses `unsafe-inline`; no SRI on CDN resources | Medium | HTTP headers / CDN supply-chain | public_html/.htaccess:92; _includes/header.php; footer.php | open | Add SRI hashes (Bootstrap/FA/jQuery); move inline JS/CSS to files or nonce them; add CSP report endpoint. (B-029, B-035) |
| F-005 | Image-upload validation not behind a rate-limited endpoint | Medium | File upload | _functions/fn_validation.php:492-665 (validateImageUpload) | open | Rate-limit upload endpoints; alert on repeated validation failures; document call sites. (B-031) |
| F-006 | Global encryption salt (no per-record salt) | Medium | Crypto / tblSettings | _functions/fn_encryption.php (global ENCRYPTION_SALT) | open | Add BINARY(16) per-record salt to tblSettings; generate unique salt per setting; alter encrypt/decrypt signatures (schema change → owner decision). (B-019) |
| F-007 | No max-payload / per-field length validation on API | Medium | REST API / DoS | _functions/fn_api_generate.php:94-200 | open | Router-level body-size cap (LimitRequestBody + PHP check); per-field length limits in handleGenerate(). (B-030) |
| F-008 | Sensitive data exposure risk in error logs (SQL/file paths) | Medium | Logging | _functions/fn_error_handler.php:244-245 (`SQL:`+substr); _functions/fn_api_auth.php | open | Mask SQL/paths in error_log() output; bootstrap-level try/catch for pre-init errors; add masking helper. (B-032) |
| F-009 | No Content-Type validation before JSON parsing | Medium | REST API | public_html/api/v1/index.php:429-441 | open | Reject non-`application/json` POST with 415 before json_decode. (B-005) |
| F-010 | Two independent CSRF implementations (installer vs fn_csrf) | Medium | CSRF | public_html/install/index.php:86-95; _includes/bootstrap.php:169 | open | Document installer's standalone one-shot CSRF (acceptable) with a comment, or route through fn_csrf. (B-009) |
| F-011 | Missing X-RateLimit-* headers on API responses | Low | REST API (info disclosure / DX) | _functions/fn_api_auth.php (checkRateLimit returns unused info) | open | Surface limit/remaining/reset via json/xmlResponse headers. (B-036) |
| F-012 | No bot-protection hooks (Turnstile/reCAPTCHA/hCaptcha) | Medium | Web UI / API abuse | public_html/index.php; assets/js/cuercode-generator.js (ProjectBrief §7.3) | open | Detect config keys (priority order); inject widget; server-side token verify before generation. (B-034) |
| F-013 | No Argon2id user-password hashing implemented | Low | AuthN (Phase 2) | _functions/ (no password_hash/Argon2id); tblUsers.passwordHash unused | open | Implement fn_password.php with PASSWORD_ARGON2ID (memory 65536/time 4/threads 2) + verify; integration test asserting Argon2id. Pre-Phase-2. (B-011) |

## Coverage ledger (TODO Phase-0)

To be completed by the dev-team-security threat-modelling pass. Each surface gets: assets at risk · trust boundaries · STRIDE categories considered · PoC attempted (Y/N) · result.

| Surface | Threat-modelled | PoC attempted | Result | Notes |
| --- | --- | --- | --- | --- |
| REST API auth (key/CSRF/anon) | TODO | TODO | — | Verify timing-safe path, anon-fallback scope, key-leak handling. |
| REST API input/DoS (payload size, field length) | TODO | TODO | — | F-007 — attempt memory-exhaustion / oversized payload. |
| CORS / cross-origin abuse | TODO | TODO | — | F-001 — confirm default '*' exploitability. |
| File download (path traversal) | TODO | TODO | — | Attempt traversal past realpath base; non-v4 UUID fuzz. |
| File/image upload | TODO | TODO | — | F-005 — polyglot/MIME-spoof/SVG-XSS upload; rate-limit abuse. |
| Crypto (AES-GCM, key/salt handling) | TODO | TODO | — | F-006 — global-salt impact; tag-tamper rejection. |
| CSRF (web + installer) | TODO | TODO | — | F-010 — token reuse / cross-impl bypass. |
| Transport / headers (HSTS, cookie_secure, CSP/SRI) | TODO | TODO | — | F-002/F-003/F-004 — downgrade + CDN-tamper scenarios. |
| Logging / info disclosure | TODO | TODO | — | F-008 — confirm SQL/path leakage in error_log + log access control. |
| Bot/abuse protection | TODO | TODO | — | F-012 — automated generation abuse without captcha. |
| Auth (Phase 2: register/login/session/OAuth/WebAuthn) | TODO | TODO | — | Not yet built; harden before launch (F-013). |
| Installer | TODO | TODO | — | Re-run protection, partial-install state, lock-file bypass. |
