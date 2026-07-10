# 📝 Changelog

All notable changes to the CueRCode project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added - User Accounts Foundation: Argon2id Auth, Sessions, Email Verify/Reset, Auth API (2026-07-10, #52/#11/#12/#14/#15, Epic E3 stage 1)

- **Schema (`install.sql`)**: `tblUsers` redesigned from the Phase-1 stub into the real E3 account table - `email` (UNIQUE, normalised lowercase), `emailVerifiedAt`, `passwordHash` (Argon2id, nullable for a future SSO-only account), `displayName`/`firstName`/`lastName`/`avatarPath`, `orgID`/`roleID` (unchanged quick-lookup columns), a new `status` ENUM(`active`/`suspended`/`pending`) replacing the old `isActive`/`isVerified` boolean pair, and `failedLoginCount`/`lockedUntil` for lockout. Seven new tables (12-18): `tblUserSessions` (database-backed, independently-revocable session tokens), `tblPasswordResets`/`tblEmailVerifications` (single-use, expiring, SHA-256-hashed tokens), `tblUserOAuth` (Epic E2 schema stub), `tblWebAuthnCredentials` (G-006 schema stub), `tblOrganisationMembers`/`tblInvitations` (Epic E3 organisations-stage schema stub). All InnoDB `utf8mb4_unicode_ci` with proper FKs/indexes; 18 tables total (was 11). App is not yet deployed (no live DB), so the schema was extended directly in `install.sql` rather than a separate migration file.
- **Per-record encryption salt (F-006/G-010/DS-7)**: `tblSettings` gained an `encryptionSalt BINARY(16) NULL` column. `fn_encryption.php`'s `encryptValue()`/`decryptValue()` were redesigned - every call that does not supply an explicit salt now generates a FRESH, cryptographically random 16-byte salt **per call**, embeds it (length-prefixed, so any explicit salt length still round-trips) directly in the returned ciphertext blob, and derives an independent AES key via HKDF from that salt - replacing the previous design where every encrypted value across the whole database shared one global `ENCRYPTION_SALT` constant. `decryptValue()` is now fully self-describing (reads the embedded salt; a `$salt` argument is accepted only for signature back-compat and is not used for derivation). An optional 4th by-reference `$usedSaltOut` parameter lets a caller capture the generated salt; `fn_config.php`'s `setConfig()` uses this to additionally mirror it into the new `tblSettings.encryptionSalt` column for DBA-visible auditability that per-record salting is genuinely unique per row (proven in the scratch-DB e2e pass below - two sensitive settings produced two different 16-byte salts and two different ciphertexts). Closes F-006/G-010/DS-7.
- **`_functions/fn_password.php`** (new): the sole sanctioned password-hashing surface - `hashPassword()`/`verifyPassword()`/`passwordNeedsRehash()` using `PASSWORD_ARGON2ID` (memory_cost 65536 KiB, time_cost 4, threads 2, per F-013), `validatePasswordPolicy()` (configurable min-length via `security.password_min_length`, a 256-char max, single-repeated-character rejection, and a small offline common/breached-password denylist - no external HaveIBeenPwned network call on the auth hot path), and `cuercodeDummyPasswordHash()` (a precomputed, never-matching Argon2id hash used for login timing-safety, below). Closes F-013.
- **`_functions/fn_auth.php`** (new, the auth engine - framework-agnostic, no HTTP/session-shaping): `registerUser()` (email+password validation upstream, Argon2id hash, pending account, single-use 24h verification token, queued verification email - anti-enumeration: an already-registered email returns the IDENTICAL response as a new one, no duplicate row, no re-sent email), `verifyEmailToken()` (atomic claim + activation, 400 invalid/expired vs. 409 already-used), `loginUser()` (timing-safe via `password_verify()` against a fixed dummy hash when no account row exists, lockout via `failedLoginCount`/`lockedUntil` driven by `security.max_login_attempts`/`security.lockout_duration`, transparent Argon2id rehash on stale cost params, session issuance - EVERY failure path, wrong password/no-such-account/locked/pending/suspended, returns the IDENTICAL generic 401), `logoutCurrentUser()`/`currentUser()`/`requireAuth()` (session guard helpers, reusable by the deferred `/save`+`/qrcodes` stage), `requestPasswordReset()` (always-identical 200 response regardless of account existence), `resetPasswordWithToken()` (atomic claim, revokes ALL existing sessions for the account on success). Session hardening: `issueUserSession()` both inserts a `tblUserSessions` row AND calls `session_regenerate_id(true)` on login (session-fixation defence) - two independent mechanisms, one database-revocable, one PHP-session-level.
- **`_functions/fn_api_accounts.php`** (new, HTTP handler layer): `handleAuthRegister()`/`handleAuthLogin()`/`handleAuthLogout()`/`handleAuthVerifyEmail()`/`handleAuthRequestPasswordReset()`/`handleAuthResetPassword()` - field presence/length/type validation, then delegate to the fn_auth.php engine. `requireCsrfOrApiKey()` enforces the same `X-CSRF-Token`/`X-API-Key` primitives the router already uses elsewhere, but replies **403** (not the router's blanket 401) for a missing/invalid credential - the semantically correct code, per `fn_csrf.php`'s own documented convention.
- **API routes** (`public_html/api/v1/index.php`): `POST /api/v1/auth/register|login|logout|verify-email|request-password-reset|reset-password`, all registered with `auth => false` (each handler does its own CSRF-or-API-key check for the 403 above) and `rateLimit => true` (reuses the existing `tblAPIRateLog`/`checkRateLimit()` per-IP/per-key mechanism - "Rate-limit login + register + reset-request using the existing pattern"). Status codes: 201 register (always identical shape), 200 login/logout/verify/reset success, 400 bad input or invalid/expired token, 401 login failure (one generic message for every cause), 403 CSRF/API-key rejected, 409 token-already-used (distinct from invalid/expired - does not leak account existence, since a token is only ever known to whoever received the email), 422 weak password/invalid email, 429 rate-limited. `fn_response.php`'s `successResponse()` gained an additive optional 3rd `$httpCode = 200` parameter (backward compatible) so 201 could be emitted through the existing helper.
- **OpenAPI 3.0.3** (`public_html/api/v1/openapi.json`): 6 new `/auth/*` paths (13 total, was 7), a new `Accounts` tag, new schemas (`RegisterRequest`/`LoginRequest`/`LoginResponse`/`VerifyEmailRequest`/`RequestPasswordResetRequest`/`ResetPasswordRequest`/`AuthGenericResponse`), two new reusable response components (`Forbidden` 403, `Conflict` 409), and an extended `ErrorResponse.error_code` catalogue.
- **`_includes/db_connect.php` - `mysqli::ping()` PHP 8.4 deprecation fix**: `getDB()`'s connection-reuse guard previously called the deprecated `mysqli::ping()` (its only prior purpose - triggering a reconnect - was removed in PHP 8.2) on EVERY call, not once per request. Replaced with a direct return of the cached connection; a dead mid-request connection now surfaces naturally as a mysqli exception on the next real query (already handled by `dbQuery()`'s try/catch) rather than needing a dedicated, now-deprecated pre-flight probe. Verified zero deprecations loading the full production bootstrap chain against a live database (evidence below). This was the one item flagged, but explicitly not fixed, by the prior Epic E8 session (`.claude/HANDOFF.md`).
- **Tests**: `tests/test_auth.php` (new, 48 assertions) - `fn_password.php` hash/verify/rehash/policy (incl. the Argon2id cost-parameter assertion and the denylist/repeated-character/length-boundary cases), `fn_auth.php`'s pure helpers (`generateRandomToken()`/`hashToken()`/`computeLockoutExpiry()`/`buildAccountActionUrl()`), and the session-dependent functions' fail-safe behaviour with no active PHP session/database (`currentUser()` → null, `logoutCurrentUser()` → idempotent 200, `requireAuth()` → throws `RuntimeException` in a dedicated subprocess where `errorResponse()` is genuinely undefined). `tests/test_core.php`'s crypto section extended for the per-record-salt redesign (salt-uniqueness-per-call assertion, updated tamper-test byte offsets for the new packed format). `tests/test_openapi_render.php` updated for the 13-path spec. Full suite: **690/690 assertions green** (642 pre-existing + 48 new).
- **Scratch-DB end-to-end verification** (throwaway local MySQL database, dropped after use, no credentials file ever written to disk): loaded the REAL production `bootstrap.php`/`db_connect.php`/`fn_*.php` chain against a live connection with **zero PHP warnings/notices/deprecations** (25x `getDB()` calls, confirming the ping fix); then drove the full engine path - register → duplicate-register anti-enumeration dedupe → verify-email (success/already-used/invalid) → login (success, wrong-password vs. no-such-account identical shape, lockout after the configured threshold, correct-password-while-locked still generic) → logout (session revoked) → request-password-reset (identical for real vs. non-existent email) → reset-password (success/already-used, old password rejected, new password accepted) → per-record salt round trip via `setConfig()`/`getConfig()` (two sensitive settings, two different 16-byte salts, two different ciphertexts). Separately drove the SAME flow through the REAL HTTP router (PHP's built-in server, a scratch `X-API-Key`) with `curl`: 403 with no credential → 201 register → 422 weak password → 401 pre-verification login → 400 bad verify token → 200 request-reset → 200 logout(no-op) → 200 verify-email (real token) → 200 login (`Set-Cookie: CUERCODE_SID; HttpOnly; SameSite=Lax`) → 200 logout (session confirmed revoked in the database). 43/43 engine-level checks + 10/10 HTTP-level checks passed; zero warnings/deprecations in either pass.
- **DEFERRED to a later E3 stage (explicitly out of scope this stage)**: account UI pages (`login.php`/`register.php`/`account/`), `/save` + `GET/PATCH/DELETE /qrcodes` (list/manage own codes), organisations (creation/roles/invitations UI - schema stubbed now), WebAuthn/passkeys (G-006, schema stubbed now), admin dashboard (G-009/#31). `requireAuth()`/`currentUser()` are already in place as the reusable guard those stages will call.
- **Scope note**: this closes PROJECT_ROADMAP.md Epic E3 stage 1 (schema + crypto + password + auth engine + auth API + email-verify/reset wiring) - children #11 (schema)/#12 (registration+verification)/#14 (login/session)/#15 (password reset), plus F-013 (Argon2id) and F-006/G-010 (per-record salt). Epic E3's later stages (UI, organisations, WebAuthn, admin dashboard) remain queued.

### Added - Microsoft Graph Email Subsystem: sendMail, Templates, Queue + Retry (2026-07-10, #29, Epic E8)

- **`_functions/fn_email.php`** - transactional email via a Microsoft 365 shared mailbox over the Graph API's OAuth2 **client-credentials** grant (application permission, no signed-in user), per ProjectBrief §13 (explicitly NOT PHP's `mail()`). `getGraphAccessToken()` requests/caches a bearer token (the cache lives in the encrypted `graph_token_cache` setting, JSON `{access_token, expires_at}`, refreshed within 60s of expiry); `sendEmailNow($to, $subject, $htmlBody, $toName=null)` posts to `/v1.0/users/{mailbox}/sendMail` (HTML content, `saveToSentItems`, ≤10s curl timeout, TLS verification never relaxed); `queueEmail()`/`processEmailQueue()` provide a `tblEmailQueue`-backed queue with **exponential backoff** (`computeEmailBackoffMinutes()`, a pure `2^attempts` minutes function) up to a configurable `maxAttempts` (default 5), after which a row is marked permanently `failed`; `renderEmailTemplate()` loads `_includes/email_templates/{key}.php` against a hard allowlist (defence against directory traversal via a caller-supplied key). Written **driver-shaped**: the `email_provider` setting is the seam a future Google Workspace driver (#30) will use without changing any caller.
- **INERT BY DEFAULT (kill-switch):** every function fails OPEN - `email_enabled` seeds to `0`, and `getGraphAccessToken()`/`sendEmailNow()` return `null`/`[false, "skipped: ..."]` **immediately, with zero network calls attempted**, whenever the config subsystem isn't loaded, the kill-switch is off, or any of the three Graph credentials (`graph_tenant_id`/`graph_client_id`/`graph_client_secret`) is unset - which is the default seeded state. Nothing in this subsystem can send a single email, or even attempt a request to Microsoft's login/Graph endpoints, until an owner explicitly configures and enables it.
- **`maybeProcessEmailQueueOnShutdown()`** - a gated, OPT-IN `register_shutdown_function()` hook that processes ≤2 queued emails opportunistically after a response has been sent, but ONLY when BOTH `email_enabled=1` AND a new `email_queue_autoprocess` setting are `1` (seeded `0`/OFF). Deliberately **NOT** wired into `bootstrap.php` - the core request path is untouched by default; a Dreamhost panel cron job hitting a small processor script is the documented, recommended production path (no SSH required).
- **Schema (`install.sql`):** new `tblEmailQueue` table (Table 11) - `emailID`, `toAddress`, `toName`, `templateKey`, `paramsJSON`, `subject`, `status` (`queued`/`sending`/`sent`/`failed`/`skipped`), `attempts`/`maxAttempts`, `lastError`, `nextAttemptAt`, `createdAt`/`updatedAt`/`sentAt` - InnoDB, `utf8mb4_unicode_ci`, indexed on `(status, nextAttemptAt)` for the queue-processor's due-row scan.
- **Settings (`seed_data.sql`, group `email`):** replaced the old, never-implemented SMTP-based stub settings (`smtp_host`/`smtp_port`/`smtp_username`/`smtp_password`/`smtp_encryption`/`email_from_address` - dead weight that contradicted the Graph-only requirement) with `email_enabled` (0), `email_provider` ('graph'), `email_from_name` (''), `graph_sender_mailbox` (''), `graph_tenant_id`/`graph_client_id`/`graph_client_secret` (all `isSensitive=1`, AES-256-GCM encrypted at rest, seeded EMPTY), `graph_token_cache` (`isSensitive=1`, `isEditable=0`, auto-managed runtime cache, seeded EMPTY), and `email_queue_autoprocess` (0). **Net effect: 54 total seeded settings (was 52).**
- **`_includes/email_templates/`** - four responsive, branded HTML templates (`verification.php`, `password_reset.php`, `invitation.php`, `receipt.php`), each returning `[$subject, $htmlBody]` and escaping every interpolated parameter via a shared `emailEscape()` helper (proven via an XSS-injection test - a `<script>` payload in a name field is asserted to appear ONLY in its escaped form, never raw, never double-escaped). All four share a common branded shell (`emailRenderShell()`) with a dark-mode-aware `@media (prefers-color-scheme: dark)` block and a hidden inbox preheader. **Uses nested `<table>` layout - a deliberate, documented, EMAIL-ONLY exception** to the project's no-`<table>`-for-layout house rule, since email clients (Outlook desktop's Word engine especially) have no reliable flexbox/grid support.
- **Tests:** `tests/test_email.php`, **69 new assertions** - template-key allowlist + path-traversal rejection, all four templates' subject/body/escaping (including the XSS-inject-and-assert-escaped-not-raw-not-double-escaped pattern for every template), the exponential backoff schedule (`computeEmailBackoffMinutes()`, including its defensive exponent clamp), and the fail-open contract for `getGraphAccessToken()`/`sendEmailNow()`/`queueEmail()`/`processEmailQueue()`/`maybeProcessEmailQueueOnShutdown()` when the config subsystem or database is unavailable (a wall-clock ceiling proves no network call is attempted when unconfigured). DB-touching queue internals (the actual `INSERT`/claim-row/settle-success/settle-failure `UPDATE` cycle) are documented as out of this dependency-free harness's scope, matching `test_core.php`'s existing `fn_config.php` DB-path exclusion convention - independently verified this cycle via a real, throwaway local MySQL scratch database (schema install + seed + a full `queueEmail()` → `processEmailQueue()` round trip through the real `fn_config.php`/`fn_encryption.php`/`db_connect.php` chain), then dropped. Full suite: **634/634 assertions green** (565 pre-existing + 69 new).
- **Owner setup required to activate (fully documented in DEV_NOTES.md):** register an M365 app (Entra ID) with the `Mail.Send` **application** permission (admin-consented) against a dedicated shared mailbox, configure DKIM/DMARC on the sending domain, fill the three `graph_*` credential settings plus `graph_sender_mailbox`/`email_from_name` via the admin settings UI (never committed - all seed EMPTY), then flip `email_enabled` to `1` and choose cron (recommended, Dreamhost panel - no SSH) vs. `email_queue_autoprocess` for queue processing.
- **Scope note:** this closes PROJECT_ROADMAP.md Epic E8 (root Phase-2 dependency for #29 - registration verification, password reset, and org invitations all need this before Epic E3 stages 3/6 can build on it). Google Workspace (#30) remains a future driver slot, not yet implemented.

### Added - Developer Portal, Integration Guides & Live OpenAPI-Rendered Reference (2026-07-10, #50)

- **New `/developers/` section** (`public_html/developers/`, same header/footer/nav chrome, dark/light + colourblind-aware, WCAG 2.1 AA, no `<table>` layout): a portal landing page (`index.php`) explaining the API-first / UI-parity principle with a shared left-nav sidebar (`_includes/developers_nav.php`) into seven sub-pages - **Quickstart** (`quickstart/`), **Authentication** (`authentication/`), **Endpoint Reference** (`reference/`), **Errors & Rate Limits** (`errors/`), **Code Samples** (`samples/`), **Integration Recipes** (`recipes/`), and **Partner Guide** (`partners/`) - each its own clean-URL directory + `index.php`, matching the existing `/accessibility/` convention.
- **Quickstart**: a genuinely anonymous, zero-setup curl (then Python) walkthrough that generates a real QR code PNG in under a minute via `POST /bulk-generate` (the one generation endpoint that allows anonymous access even for a single item), plus the same request once an API key is available via `POST /generate` - and an explicit anonymous-vs-API-key explanation so the quickstart doesn't misrepresent `/generate` as anonymous (it never is).
- **Authentication guide**: both auth modes (`X-API-Key` header for server-to-server; `X-CSRF-Token` header/session for web UI AJAX), the anonymous fallback and its limits, how to request a key today, and an explicit "coming with accounts" placeholder for self-service key create/rotate/revoke - correctly scoped OUT of this pass (issue #50 item 3) pending Epic E3 (accounts have zero auth code today).
- **Endpoint Reference - the anti-drift centrepiece** (`developers/reference/index.php` + new `_functions/fn_openapi_render.php`): loads and parses `api/v1/openapi.json` AT REQUEST TIME and renders every path/method/summary/description/auth requirement/parameters/request-body schema (including the full `Customization` schema and its accessibility params `preset`/`scan_distance_cm`/`pdf_layout`)/responses/examples/error codes - nothing is hand-copied, so the page cannot silently drift from the spec (DEV_NOTES.md §Architecture rule 7). All render helpers are pure and DB-independent (`oapiResolveRef`, `oapiResolveSchema` with `$ref`/`allOf` flattening, `oapiRenderSchemaProperties`, `oapiRenderResponses`, `oapiRenderAuthSummary`, `oapiFormatDescription`, …), escaping every dynamic string with `htmlspecialchars(ENT_QUOTES, 'UTF-8')` before output (`oapiFormatDescription()` applies only fixed-tag, already-escaped-text formatting - `**bold**`/`` `code` ``/paragraph breaks - so no new markup can be smuggled through spec text).
- **Errors & Rate Limits reference**: the machine-readable error envelope (`success`/`message`/`data`/`error_code`/`timestamp`, its full property list rendered live from the `ErrorResponse` schema so the `error_code` catalogue can't drift either), an HTTP status code table (200/400/401/402‑reserved/403‑reserved/404/405/413/415/422/429/500/501/503, honestly marking 402/403 as not yet returned by any endpoint - reserved for future Epic E4/E3 work), the shared response descriptions/examples resolved live from openapi.json's named + inline responses, and the `X-RateLimit-*`/`Retry-After` headers with their default limits by auth mode (anonymous/CSRF/per-key).
- **Code Samples**: working curl, PHP (`curl_init`/`curl_setopt_array`, `declare(strict_types=1)`), JavaScript (`fetch`), and Python (`requests`) samples for all four core endpoints (`/generate`, `/preview`, `/download/{uuid}`, `/bulk-generate`) - matching the CURRENT contract (correct auth per endpoint, correct response shapes, `Content-Type` sniffing between the ZIP/Avery-PDF bulk-generate response shapes). Samples are sequential (not JS-only tabs) so every language stays readable/searchable with JavaScript disabled.
- **Integration Recipes**: "QR for your website/CMS" (generate once at publish time, embed the `download_url` as a static `<img>`), "bulk generate from a spreadsheet" (CSV → ZIP via `/bulk-generate`'s `csv` field, plus the manifest.txt per-row result), and "accessible QR via the API" (`preset=accessible` + `scan_distance_cm` + the `accessible_caption`/`tactile` PDF layouts, cross-linked to the `/accessibility/` print guide). A stubbed "Dynamic QR via API" recipe is explicitly marked **coming soon**, pending Epic E1a (in-house redirect layer, owner-gated) and the upstream Go2My.Link integration contract (tracked as their own [Go2My.Link #137](https://github.com/MWBMPartners/Go2My.Link/issues/137)).
- **Partner Guide**: honest status page for the two named partner integrations being finalised upstream - Go2My.Link (dynamic short-link backend; blocked on [Go2My.Link #137](https://github.com/MWBMPartners/Go2My.Link/issues/137)) and SIGNula.id (suite SSO; blocked on [SIGNula.id #88](https://github.com/MWBMPartners/SIGNula.id/issues/88), since SIGNula's current API is a credential-proxy, not an OIDC IdP) - to be replaced with the real integration contract docs once each freezes.
- **Nav/footer wiring**: a new "Developers" link in the site navbar (`_includes/header.php`) and footer (`_includes/footer.php`), alongside the existing "API Docs" (Swagger UI) link.
- **Tests**: `tests/test_openapi_render.php` (59 new assertions) - proves the render helpers are correct AND that the real, shipped `openapi.json` still has the exact shape the reference page depends on (7 paths by name, the `Customization` schema's accessibility fields, `$ref`/`allOf` resolution against the real `BulkItem`/`Customization` schemas, the real `/generate` vs `/health` rate-limit-header presence/absence, and an adversarial description-formatting test proving a `<script>`/`<b>` payload embedded in spec text can never survive `oapiFormatDescription()` unescaped). Full suite: **565/565 assertions green** (506 pre-existing + 59 new).
- **Scope note**: this closes PROJECT_ROADMAP.md Epic E6 draft issue items 1, 2, and 4 (portal + integration guides + live openapi-rendered reference). Item 3 (self-service API-key management UI) is explicitly DEFERRED - it needs Epic E3 (accounts & organisations), which has zero authentication code today; a clear "coming with accounts" placeholder is in place on both the portal landing page and the Authentication guide until then.

### Added - Braille-Caption, Tactile-Frame & Avery Label-Sheet PDF Print Layouts (2026-07-10, #49)

- **New `pdf_layout` customisation param** (`format=pdf` only; enum `standard`|`accessible_caption`|`tactile`|`avery_5160`|`avery_l7160`, default `standard`): `standard` is a COMPLETE NO-OP — the existing `chillerlan\QRCode\Output\QRFpdf` byte output is untouched and proven byte-for-byte identical (absent vs. explicit `standard`) via a new subprocess test. All other values are composed by a new file, `_functions/fn_qr_accessible_pdf.php`, which draws the raw QR module matrix itself (via `QRCode::getQRMatrix()` + the library's own public segment-detection loop, bypassing the `QRFpdf` output class entirely) so it can lay out captions/frames/label grids alongside the code on a single FPDF canvas.
- **`accessible_caption` layout**: QR code + a large-print (≥18pt) human-readable caption (auto-derived from the type-specific input — preferring `url`/`text`/`label`/`phone`/`email`, falling back to the first scalar input value, then the raw payload; truncated to 140 chars) + a **Grade-1 (uncontracted) Braille caption line rendered as geometric dot patterns** drawn directly with FPDF `Rect()` primitives (a small strip-rasterised "filled circle" helper — core FPDF has no native circle/ellipse operator, and core fonts have no Braille glyphs, ruling out a Unicode Braille font). Covers a-z, 0-9 (digit run behind a single number-sign per run, dots 3-4-5-6), a single-letter capital indicator (dot 6), space, and a small common-punctuation set; any character outside that subset renders as a documented blank cell. Dot geometry uses commonly-cited approximate "Marburg Medium" print/embossing figures (⌀ 1.5mm dots, 2.5mm intra-cell pitch, 6.0mm inter-cell pitch, 10mm line pitch) — cited and caveated in the code as approximate, not an embosser-certified spec. **Grade-2 (contracted) Braille is explicitly out of scope.**
- **`tactile` layout**: everything in `accessible_caption` PLUS a second, clearly-delineated page containing CueRCode's own tactile locator-frame design — a thick registration-marked square frame (offset outside the QR's bounding box, positioned to register against page 1), four corner "L" registration marks, and a single bottom orientation dot. **Deliberately original — not a reproduction of Chulalongkorn University's Thai petty patent No. 17871** (Thailand-only, and not cloned regardless per PROJECT_ROADMAP.md's own directive). Meant to be reproduced via swell paper, a Braille embosser, or a UV-varnish/raised-ink pass aligned with page 1.
- **`avery_5160` (US Letter, 3×10) / `avery_l7160` (A4, 3×7) label-sheet layouts**: tile the QR module matrix into Avery address-label geometry (manufacturer-published template dimensions), with a light guide outline drawn for every label cell. A single `/generate` call fills exactly ONE label, leaving the rest of a full sheet blank as a printable template. Wired into `POST /api/v1/bulk-generate` (`fn_api_bulk.php`) too: when the REQUEST-LEVEL shared `customization` sets `format: pdf` + `pdf_layout: avery_5160`/`avery_l7160`, the whole response forks from the usual ZIP into a single combined `application/pdf` Avery sheet with every item tiled into successive labels across as many sheet pages as needed (per-item `pdf_layout` overrides are ignored — a mixed-layout sheet is not coherent; per-item failures leave that slot blank rather than aborting the batch).
- **API/UI wiring**: `pdf_layout` accepted/allowlisted in `buildCustomizationFromRequest()` (`fn_api_generate.php`) and the no-JS `index.php` POST fallback; a new "PDF Print Layout" `<select>` in the web UI's Options tab (shown only when Output Format = PDF, toggled by `handleFormatChange()`); `cuercode-generator.js` `collectCustomization()` sends it on every request. OpenAPI 3.0.3 `Customization` schema documents the full enum plus the `/bulk-generate` response-shape fork (new `application/pdf` alternative content + a `422 BULK_AVERY_ALL_FAILED` response), and a new `accessible_pdf_layout` request example.
- **New guidance page**: `public_html/accessibility/index.php` (`/accessibility`, linked from the site footer) — swell-paper/embosser workflow, minimum print size + the 10:1 scan-distance rule, quiet-zone/contrast requirements, placement/height/lighting best practice, and how the Braille-caption/tactile-frame/Avery layouts are meant to be used together end-to-end. WCAG AA, no `<table>` for layout, dynamic copyright year.
- **Tests**: `tests/test_braille.php` (65 new assertions) — the Grade-1 transliteration table (deterministic dot sets for letters/digits/a digit run's single number-sign/an ended-then-restarted digit run/punctuation/space/an unsupported-character blank cell), the dot-geometry helpers (`brailleCellDotPositionsMm()`, `brailleLineWidthMm()`), the pure circle-strip maths behind the FPDF "filled dot" helper (`circleStripHalfWidthMm()` — a 3-4-5 right-triangle spot check), the `pdf_layout` enum/param parsing (`normalisePdfLayout()`, `buildCustomizationFromRequest()`), Avery sheet geometry (`averySheetSpec()`), caption derivation/truncation, and the bulk Avery-sheet request detector (`bulkRequestWantsAveryLayout()`). Full PDF byte-output is impractical to assert (FPDF embeds a `CreationDate` timestamp), so real PDFs are generated in subprocesses and asserted `%PDF`-prefixed + non-trivial size, per this task's documented scope; the `standard`-parity guarantee (absent vs. explicit `pdf_layout=standard` → byte-identical) is asserted directly. Full suite: 506/506 assertions green (441 pre-existing + 65 new).
- **Scope note**: this closes PROJECT_ROADMAP.md E5 draft issue (b). Draft issue (c) (NaviLens/Zapvision partnership assessment — owner decision, no code) remains open; see FEATURES.md FG-033 (now fully BUILT).

### Added - Accessible-QR Preset, WCAG Contrast Validation & Physical-Size Calculator (2026-07-10, #48)

- **WCAG 2.1 contrast function** (`qrContrastRatio()`, `fn_qr_options.php`): pure, unit-tested implementation of the W3C relative-luminance contrast formula (sRGB linearisation → `L = 0.2126R + 0.7152G + 0.0722B` → `(L1+0.05)/(L2+0.05)`); range 1.0-21.0, order-independent. Backed by two new helpers, `qrLineariseSrgbChannel()` and `qrRelativeLuminance()`.
- **"Accessible" customisation preset** (`preset` param, values `none`|`accessible`): a new `applyAccessiblePreset()` in `fn_qr_options.php` forces `ecc=H`, forces the quiet zone on (≥4 modules per ISO/IEC 18004), and drops any pinned `version` so the smallest auto-fit version is used. Evaluates the request's `fg_color`/`bg_color` contrast and classifies it `fail` (< 3:1 — request REJECTED with HTTP 422, `error_code: ACCESSIBLE_CONTRAST_TOO_LOW`), `warning` (3:1 up to 4.5:1 — allowed through, surfaced as a non-blocking warning), or `pass` (≥ 4.5:1). `preset=none` (the default/absent case) is a byte-for-byte no-op — all existing, non-accessible generations are unaffected (400/400 pre-existing tests unchanged).
- **Physical print-size calculator** (`qrRecommendedPrintSizeMm()`, `fn_qr_options.php`): pure implementation of the 10:1 scan-distance rule (recommended print size ≈ scan distance / 10) with a 20mm practical floor. Accepted via a new `scan_distance_cm` customisation param; the recommendation is returned in `data.metadata.recommended_print_size_mm` on `/generate` and `/preview` responses whenever `preset=accessible`.
- **API**: `buildCustomizationFromRequest()` (`fn_api_generate.php`) now accepts/allowlists `preset` and `scan_distance_cm`; `handleGenerate()`/`handlePreview()` apply the preset immediately after loading the QR generation functions (moved ahead of `validateCustomization()` so the enforcement/contrast-rejection can run first) and add a `metadata` object (`accessible`, `contrast_ratio`, `contrast_warning`, `recommended_print_size_mm`) to successful accessible-preset responses. OpenAPI 3.0.3 `Customization` schema documents both new params; a new `AccessibleMetadata` schema documents the response shape; the shared `422 UnprocessableEntity` response gained a worked contrast-rejection example.
- **UI**: a new "Accessible QR" section in the customisation panel (`index.php`) — a keyboard-operable toggle switch (`aria-describedby` explaining exactly what it enforces), a live WCAG contrast read-out (`role="status"`, `aria-live="polite"`, plus a `role="alert"` warning banner below 4.5:1/3:1), and a scan-distance number input with a live recommended-print-size read-out (`role="status"`, `aria-live="polite"`). `cuercode-generator.js` mirrors `qrContrastRatio()`/`qrRecommendedPrintSizeMm()` client-side for instant feedback (no AJAX round-trip) and `collectCustomization()` now sends `preset` + `scan_distance_cm` (when set) alongside every preview/generate request. No `<table>` used for layout.
- **Tests**: `tests/test_accessibility.php` (41 new assertions) — `qrContrastRatio()` known pairs (black/white → 21.0, identical colours → 1.0, `#777`/white → ~4.48 borderline-AA), `qrRecommendedPrintSizeMm()` (10:1 rule + 20mm floor + negative/zero clamping), `applyAccessiblePreset()` (non-accessible parity/regression guarantee, forced ecc/quiet_zone/version, fail/warning/pass contrast classification), `buildCustomizationFromRequest()` parsing of both new params, and an end-to-end subprocess test proving `handleGenerate()` actually returns the 422/`ACCESSIBLE_CONTRAST_TOO_LOW` response for a real sub-3:1 request. Full suite: 441/441 assertions green.
- **Scope note**: this closes PROJECT_ROADMAP.md E5 draft issue (a) only. Draft issue (b) (Braille-caption + tactile-frame FPDF print templates, Avery label sheets) and draft issue (c) (NaviLens/Zapvision partnership assessment — owner decision) remain open; see FEATURES.md FG-033 (BUILT-partial).

### Security / Fixed - Deep Syntax/Lint/Security Sweep, Dev-Env Fail-Closed, SRI (2026-07-10, #47)

- **Dev-environment fail-closed access control** (DS-1): `public_html_dev_alpha/.htaccess` and `public_html_dev_beta/.htaccess` previously shipped with `Require valid-user` commented out and NO active deny rule — both environments were unprotected as committed. Both now default to `Require all denied` (Apache 2.4 `mod_authz_core`, with an `Order deny,allow`/`Deny from all` Apache 2.2 fallback) as the ACTIVE rule; the Basic-Auth block (`AuthUserFile`/`Require valid-user`) is retained, fully commented, with numbered instructions for the owner to create `.htpasswd` on the server and opt in. Net effect: both dev channels are denied to the public by default until the owner deliberately enables access.
- **dev_alpha/dev_beta `infoAppVer.php` standards pass** (DS-2): both files converted to full house style (4-space indent replacing tabs, doc header block with `Path:`/`@copyright`, `OR`/`AND` → `\|\|`/`&&`, uppercase `NULL` → lowercase `null`, section spacing, no trailing `?>`) — matching the treatment `public_html/infoAppVer.php` already received. All `$app` values preserved unchanged.
- **`deploy.yml` build-info injection hardened** (DS-3): replaced the fragile literal `' = NULL'` (uppercase) sed-match injection with a marker-comment scheme — each of the four Repo→Commit assignments in `infoAppVer.php` now carries a trailing `// @build-info: <key>` anchor comment that `deploy.yml` matches on, independent of the value's casing/formatting. This let all four lines, in all three `infoAppVer.php` copies (production + both dev channels), normalise to house-standard lowercase `null` — closing out the last uppercase-`NULL` exception. Verified with a simulated injection round-trip (`php -l` clean before/after, correct substitution).
- **Partial SRI hardening** (F-004 / DS-4): added `integrity=` + `crossorigin="anonymous"` to the Bootstrap CSS and Bootstrap JS bundle CDN tags in `_includes/header.php`/`footer.php` — hashes copied verbatim from `public_html_landing/index.php` after confirming the pinned version matches byte-for-byte. FontAwesome and jQuery tags are left without a hash (their versions aren't referenced on the landing page, so no verified hash exists to copy) and instead carry an explicit `<!-- TODO F-004: capture SRI hash ... at live deploy -->` comment — a guessed hash would block the resource entirely. CSP `unsafe-inline` → nonce migration remains open pending live-UI verification.
- **`fn_validation.php` `validateImageUpload()` annotated** (DS-5): docblock now documents this dormant (no call-sites) function as the reserved validator for the future account-era (E3) file-upload logo path, and cross-references `saveBase64LogoToTemp()` as the active anonymous-era base64 logo path today. Annotation only — not wired into any route.
- **`install.sql` password-hash comment corrected** (DS-6): `tblUsers.passwordHash` column comment changed from "bcrypt" to "Argon2id (PASSWORD_ARGON2ID)" to match the mandated algorithm (ProjectBrief §7.1). Comment-only, no schema change.
- **Repo hygiene**: removed the empty, untracked stray `.openai/` directory at repo root (DS-9). `.claude/ProjectcBrief_Chat.claude` (typo'd) vs `.claude/ProjectBrief_Chat.claude` investigated (DS-10) — confirmed genuinely different content (two distinct historical chat transcripts), left in place for an explicit owner decision rather than deleted.
- **Trailing `?>` closing tag removed** from `public_html/api/docs/index.php` — the one remaining `public_html/` entry page that still had one; brings it in line with every other page/error/install page in the tree.
- **Missing file-header fields added** across 32 non-vendor PHP files (`_functions/`, `_includes/`, `public_html*/`, both `_auth_keys/auth_creds.example.php` templates): each was missing the `Path:` and/or `@copyright` docblock fields required by `.claude/CLAUDE.md`. Every non-vendor `.php` file in the tree now carries both.
- **Full re-scan confirms zero new OWASP-relevant findings**: 123/123 `php -l` clean (up from 122), 400/400 tests green, zero deprecations across all 18 `_functions/fn_*.php` files, zero dangerous-function hits outside vendored libraries, zero hardcoded secrets (thorough manual grep — `gitleaks` CLI unavailable in this environment), no new SQLi/XSS/CSRF issues found. See SECURITY.md "Deep-sweep pass (#47)" for full evidence.
- **Deferred (as directed, tracked, not fixed this pass)**: F-006/DS-7 per-record encryption salt (schema change, folds into the E3/E4 accounts migration, owner gate G-010); DS-8 bot-protection hooks (F-012, owner must supply Turnstile/reCAPTCHA/hCaptcha keys); CSP `unsafe-inline` → nonce (needs live UI verification).

### Added - QR Version/Precision Selection and iCal Timezone Support (2026-06-29)

- **QR version/precision selection** (B-007 / API+UI): the `/generate` and `/preview` API endpoints now accept an optional `version` integer parameter (1–40) to pin the QR code module version, and a string literal `"auto"` (normalised to no-pin for automatic selection); OpenAPI 3.0.3 schema updated to document both forms.
- **iCalendar timezone (TZID/VTIMEZONE)** (B-007): `buildPayload_calendar` now accepts an optional `timezone` parameter; when provided, `DTSTART` and `DTEND` are emitted as `DTSTART;TZID=<tz>:…` with a `VTIMEZONE` component generated per RFC 5545; falls back to UTC `Z` suffix when timezone is omitted or empty.

### Changed - Secure-by-Default CORS on Fresh Install (2026-06-29)

- **Secure-by-default CORS allowlist** (B-053): `seed_data.sql` `api_cors_allowed_origins` seed value changed from `*` to `''` (empty string); `emitCorsHeaders()` already fails closed when the allowlist is empty (denies all cross-origin requests), so fresh installs are now secure-by-default and an admin must explicitly add origins to permit cross-origin access. Existing databases are unaffected (seed only runs on fresh install). Note: intentional behavioural change — confirm acceptable before deploying.

### Fixed - PHP 8.4 Deprecations, infoAppVer Standards, Config Cache, Logo Path, and OpenAPI (2026-06-29)

- **PHP 8.4 E_STRICT / E_DEPRECATED elimination** (B-051): all deprecation-triggering patterns across the codebase removed; `php -l` and runtime zero-deprecation bar now met — zero `E_DEPRECATED` or `E_STRICT` output on PHP 8.4/8.5.
- **infoAppVer.php standards pass** (B-022, B-023, B-024, B-025, B-026): `public_html/infoAppVer.php` converted to full house-style — tabs replaced with 4-space indentation; full file-header doc block added (path, copyright, `@package`/`@subpackage`/`@version`/`@since`/`@see`); `OR`/`AND` operators replaced with `||`/`&&`; uppercase `NULL` replaced with lowercase `null` (4 CI-coupled `' = NULL'` string literals on lines 68–71 intentionally retained for `deploy.yml` sed injection — verify before future reflow); section headers and consistent spacing added throughout.
- **Config-cache invalidation** (B-004): `clearConfigCache()` in `fn_config.php` now actually evicts entries from `getConfig()`'s static `$cache`; `setConfig()` invalidates the affected key on write; request-lifetime config cache is now coherent across set/get/clear within a single request.
- **Logo path containment annotation** (B-056): OpenAPI `logo` parameter description updated to document the server-side path-containment constraint (paths are validated via `realpath` under `CUERCODE_UPLOADS_DIR/logos`; any path resolving outside is rejected).
- **OpenAPI XML error examples** (B-052): correct XML error response examples added to all error response codes (`400`, `401`, `404`, `413`, `415`, `429`, `500`, `501`) in `openapi.json`; every response now has both JSON and XML examples.
- **OpenAPI `version` parameter doc/impl sync** (B-054): `version` schema in `openapi.json` updated to document both the integer form (1–40, pin module version) and the string literal `"auto"` (normalised to no-pin); the documented contract now fully reflects the implementation's accepted inputs.

### Changed - API Feature Parity, Complete OpenAPI Spec, and Uniform Endpoint Security (2026-06-29)

- **API is now a strict superset of the web UI** (GitHub #45, #6): every customisation parameter available in the web UI (colours, dot/marker patterns, logo overlay and position, frame styles and labels, output format, ECC level, size, quiet zone, and all type-specific input fields) is now fully exposed and accepted by the REST API (`POST /api/v1/generate` and `POST /api/v1/preview`). `buildCustomizationFromRequest()` in `fn_api_generate.php` audited and confirmed to accept all 21 customisation parameters; the API is the parity contract.
- **Complete OpenAPI 3.0.3 specification** (GitHub #45): `public_html/api/v1/openapi.json` now documents every endpoint (`/generate`, `/preview`, `/download/{uuid}`, `/types`, `/formats`, `/health`, `/bulk-generate`) with full request/response schemas, all parameter descriptions, all 2xx and error response codes, and JSON + XML response examples. `openapi.json` is the authoritative source of truth for the API surface; Swagger UI at `api/docs/` reflects the full spec.
- **Uniform endpoint security** (GitHub #45): auth and rate-limit coverage confirmed on all API routes; no endpoint is reachable without passing through the auth/rate-limit gate in the router.

### Added - Bulk QR Generation, App Deep-link, and GS1 Product QR Types (2026-06-29)

- **Bulk QR generation endpoint** (FG-003, Issue #32): synchronous, anonymous-capable `POST /api/v1/bulk-generate` endpoint accepts either a JSON `items` array or a `csv` string (with optional shared `customization`); loops the existing generate pipeline in-memory per item (`save_file=false`, no per-item DB save); assembles a ZIP via `ZipArchive` and streams it as `application/zip` (`cuercode-bulk.zip`). Per-item generation failures are collected into a `manifest.txt` inside the ZIP rather than aborting the batch; malformed item shape (missing `type`/`input`) and over-cap are hard 400/413 errors before generation begins; 501 returned if the `zip` extension is absent. Pure, DB-independent helpers (`parseBulkInput`, `parseBulkCsv`, `parseBulkItemsArray`, `validateBulkRequest`, `bulkBuildFilename`, `getBulkMaxItems`) were factored out and unit-tested in `tests/test_bulk.php` (48 new assertions). Route registered with `auth:false, rateLimit:true`; dispatcher passes `$auth`. OpenAPI 3.0.3 documents the endpoint plus `BulkGenerateRequest` and `BulkItem` component schemas (200 application/zip, 400/413/415/429/501). An accessible Bulk-generate UI panel (labels, ARIA live count, `noscript` fallback, no layout tables) plus a `fetch`-based blob-download handler were added to the web UI.
- **App deep-link QR type** (FG-007): new `deeplink` QR type encodes an https Universal/App Link URL with `app_scheme`, `fallback_url`, `ios_universal_link`, and `android_app_link` fields; builder function + matching `tblQRCodeTypes` seed row (field names matched to builder read keys) + happy-path and invalid-input tests including dispatcher routing.
- **GS1 product QR type** (FG-009): new `gs1_product` QR type generates a GS1 Digital Link URI from `gtin`, `batch`, `serial`, `expiry`, `lot`, and `quantity` fields; builder function + matching `tblQRCodeTypes` seed row + happy-path and invalid-input tests including dispatcher routing. QR type catalogue grows to 26 types.

### Added - Colourblind Toggle, Cookie Consent Banner, and Social OG Image (2026-06-29)

- **Colourblind-friendly UI toggle** (FG-023): CSS custom-property palettes for protanopia, deuteranopia, and tritanopia using Okabe-Ito-derived colours (each primary colour ≥4.5:1 contrast on white for WCAG AA compliance), applied via a `data-cuercode-cvd` attribute on `<html>`; keyboard-operable Bootstrap dropdown picker added to the navbar beside the existing theme toggle (options: None / Protanopia / Deuteranopia / Tritanopia); selection persisted to localStorage and re-applied before paint via a head script mirroring the existing theme-toggle pattern; shared `storageKeys` map and `storageGet`/`storageSet` helpers with try/catch factored into `cuercode-common.js`.
- **Cookie/storage-consent banner** (FG-026): dismissible, non-modal consent banner added in `footer.php` (`role="region"`, `aria-label`, links to `/privacy`); revealed by JS only when the consent localStorage key is absent (unacknowledged); honours `prefers-reduced-motion`; uses shared `storageGet`/`storageSet` helpers.
- **Social sharing OG image** (FG-024): 1200×630 `og-image.png` created at `/assets/img/branding/og-image.png` — the exact path already referenced in `header.php` — fixing broken social previews; built with PHP GD/FreeType compositing existing brand assets (brand-teal diagonal gradient background, white rounded panel with drop shadow, `logo-mark.png`, CueRCode wordmark with auto-fit font sizing, cyan accent rule, and tagline); generator script preserved at `tools/generate-og-image.php`.

### Added - QR Types, vCard 4.0, and Legal Pages (2026-06-29)

- **Facebook Messenger QR type** (FG-025): new `buildPayload_facebook_messenger` builder generates `m.me/{username}` URLs; strips leading `@`, validates handle format; matching `tblQRCodeTypes` seed row with `NULL` payloadTemplate so the builder runs (no template short-circuit).
- **Instagram QR type** (FG-025): new `buildPayload_instagram` builder generates `instagram.com/{username}` URLs; strips leading `@`, validates handle format; matching seed row.
- **Rating/Review QR type** (FG-025): new `buildPayload_rating` builder accepts a validated `http(s)` review URL; extensible for future per-platform fields; matching seed row.
- **vCard 4.0 support** (FG-031): `buildPayload_vcard` now accepts an optional `version` field (default `3.0`, accepts `4.0`); v4.0 emits `VERSION:4.0` with lowercase `TYPE` tokens (`TEL;TYPE=work`, `TEL;TYPE=cell`, `ADR;TYPE=work`) per RFC 6350; v3.0 path is byte-for-byte unchanged; `version` optional select added to vCard seed `formFieldsJSON`.
- **Multi-jurisdiction Privacy Policy** (FG-027): `privacy.php` rewritten as a comprehensive data-protection and cookies policy covering EU GDPR, UK GDPR + DPA 2018, USA (CCPA/CPRA, VCDPA, CPA, CTDPA, UCPA, HIPAA, COPPA), Canada PIPEDA, Brazil LGPD, Australia Privacy Act + NDB, New Zealand Privacy Act 2020, India DPDP, China PIPL/CSL/DSL, Japan APPI, South Korea PIPA, South Africa POPIA; dynamic `date('Y')` copyright year; editorial TEMPLATE note — review by qualified legal counsel before production launch.
- **Multi-jurisdiction Terms of Use** (FG-027): `terms.php` rewritten with comprehensive terms covering acceptance, description, eligibility/accounts, acceptable use, user content, IP/copyright, API usage, availability/termination, disclaimer of warranties, limitation of liability, indemnification, governing law and disputes, general provisions, and changes; same TEMPLATE caveat.

### Fixed - App Store app_id Validation (2026-06-29)

- **App Store app_id validated per platform** (B-050): `buildPayload_appstore` now validates `app_id` per platform — Apple IDs must be numeric (optional leading `id` prefix is normalised away, preventing doubled `idid`); Google IDs must be valid reverse-DNS package names; malformed IDs throw `InvalidArgumentException` with a clear message.

### Fixed - Security: Headers/CORS/Upload/Encoding Hardening (2026-06-29)

- **CORS allowlist enforced** (F-001): `Access-Control-Allow-Origin` now resolves against an explicit allowlist from `tblSettings`; wildcard `*` default removed; `.htaccess` hardcode replaced with allowlist-driven value.
- **HSTS and HTTPS redirect enabled** (F-002): `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload` wired in prod block; 301 HTTP→HTTPS redirect activated; local-dev comment preserved.
- **Secure-cookie `.htaccess` conflict resolved** (F-003): `php_value session.cookie_secure 0` removed from `.htaccess`; bootstrap HTTPS detection is now the sole authority for the secure-cookie flag.
- **Upload size/dimension guards added** (F-005): 8 MB decoded-byte cap added in `saveBase64LogoToTemp`; pixel-dimension guard (≤ 8000×8000) added via `getimagesize()` before GD load; same guards applied to `validateImageUpload()`.
- **API body-size cap and per-field length limits** (F-007): router-level `Content-Length` check added before `json_decode`; per-field length limits enforced in `handleGenerate()` (e.g. url ≤ 2048, vCard fields ≤ 500).
- **Error-log SQL/path masking** (F-008): masking helper added; `error_log()` fallback redacts SQL text and file paths before writing; technical detail split to DB-only log channel.
- **Content-Type 415 gate** (F-009): POST requests with non-empty body now require `Content-Type: application/json` (charset suffix allowed); 415 returned otherwise, before `json_decode`.
- **Installer standalone CSRF documented** (F-010): clear comment added to `install/index.php` explaining acceptability of the one-shot standalone CSRF in a pre-bootstrap context.
- **X-RateLimit-* headers surfaced** (F-011): `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` now emitted on all API responses using data from `checkRateLimit()`.
- **Logo path containment enforced** (F-017): `getLogoPath()` Case 3 now passes the request-supplied path through `isPathWithinAllowedBase()` constrained to `realpath`-under-`CUERCODE_UPLOADS_DIR/logos`; any path resolving outside is rejected.
- **notify() DOM XSS sink closed** (F-018): `message` now wrapped in `CueRCode.escapeHtml()` inside `notify()` in `cuercode-common.js`; sink closed unconditionally.
- **Session ID output escaping** (F-019): `htmlspecialchars($sessionId, ENT_QUOTES, 'UTF-8')` applied in debug-panel footer; last-8-chars masking added for baseline consistency.
- **Installer HTTP deny and robots.txt lockdown** (F-020): `Require all denied` `.htaccess` added to `public_html/install/`; `robots.txt` `Disallow:/install/` entry replaced with a non-advertising alternative.

### Fixed - Security: Schema↔Code Reconciliation (2026-06-29)

- **Rate-limiting restored** (F-014): `recordAPIRequest()` INSERT and SELECT statements in `fn_api_auth.php` aligned to canonical `tblAPIRateLog` schema columns (`requestIP`, `endpoint`, `requestMethod`); per-key and per-IP rate-limiting now functional end-to-end.
- **Download and generate persistence restored** (F-015): All `tblQRCodes` column references reconciled to canonical schema (`qrID`, `qrOutputFormat`, `qrFilePath`) across `fn_api_download.php`, `fn_qr_generate.php`, and `fn_qr_logo.php`; download endpoint and generate-save pipeline now operational; predefined-logo lookup corrected in `fn_qr_logo.php`.
- **API-key length constant corrected** (F-016): `CUERCODE_API_KEY_LENGTH` corrected from 45 to 49 to match the generated key length (`'cuercode_'` prefix 9 chars + `bin2hex(random_bytes(20))` 40 chars); API-key authentication now accepts correctly-generated keys; prefix doc comment updated.

### Fixed - QR Payload & Core Correctness (2026-06-28)

- **vCard contact fields now emitted correctly** (B-044): `buildPayload_vcard` now accepts the seed/form field names (`phone`, `mobile`, `website`, `postcode`, `notes`) as aliases for the legacy internal names, so TEL, URL, NOTE, and ADR lines are no longer silently dropped when generating a vCard QR from the web form.
- **App Store invalid-platform handling** (B-045): `buildPayload_appstore` now validates that `platform` is one of `apple`/`ios` or `google`/`android` (case-insensitive) and throws `InvalidArgumentException` for any other value, including the former `auto` option which produced a malformed Apple URL. The `auto` option has been removed from the seed `formFieldsJSON`.
- **validateJSON rejects bare scalar values** (B-046): `validateJSON()` now enforces its documented contract — only JSON objects and arrays pass; bare scalars (`null`, `true`, `false`, numbers, strings) are correctly rejected.
- **sanitizeFileName extension trimming** (B-047): `sanitizeFileName` now trims whitespace from the file extension and collapses an all-whitespace extension to none, so `'hello-world.png  '` yields `'hello-world.png'` and `'data.   '` yields `'data'`.
- **QR size-estimation ECC level mapping** (B-049): `estimateModuleCount` now maps the chillerlan `EccLevel` constant values (`L=1`, `M=0`, `Q=3`, `H=2`) to the correct capacity rows instead of using the raw integer as a row index, fixing a non-monotonic size estimate that affected all four ECC levels. Generated QR codes are unaffected; only the UI scale hint changes.
- **Activity-log session ID bind type** (B-020): `logActivity()` bind-param type string corrected from `'sssiiissss'` to `'sssiisssss'`; parameter 6 (`$sessionID`) is a VARCHAR(128) string, not an integer — the previous `'i'` binding silently coerced every session ID to `0` on insert.

### 🧪 Added - PHP Test Harness & Characterization Safety Net (2026-06-28)

- Self-hosted PHP test harness (`tests/run.php`) with characterization tests for QR payload builders and core functions — no-Composer, runnable via `php tests/run.php`. 218 assertions across `tests/test_payloads.php` (all 21 QR builders + escaping helpers + dispatcher) and `tests/test_core.php` (encryption, CSRF, validation, QR options, response formatting). All 218 pass; harness lives entirely under repo-root `tests/` and is never deployed.

### 📋 Added - Project Plan & GitHub Project Management (2026-03-23)

- 📄 Clean ProjectBrief (`.claude/ProjectBrief.claude`) - structured 20-section specification document
- 🏷️ GitHub Milestones created for Phases 1.5 through 6
- 🏷️ 13 new GitHub labels (phase-1.5 through phase-6, security, testing, infrastructure, legal, auth, payment, integration)
- 📝 28 new GitHub Issues across 6 phases with detailed descriptions, scope, and acceptance criteria
- 📊 Updated PROJECT_STATUS.md with comprehensive phase-by-phase project plan with issue links
- 🤖 Updated `.claude/` memory and CLAUDE.md for current project state

### 🔍 Added - Codebase Audit Against Revised ProjectBrief (2026-03-23)

- 🔎 Thorough audit of all Phase 1 code against the clean ProjectBrief
- 📝 Detailed review comments posted on all 9 Phase 1 GitHub Issues (#1-#9)
- ✅ Closed #8 (Project Rebrand) as genuinely complete after verification
- 🔄 Reopened #1-#7, #9 — audit identified gaps that need tracking
- 🆕 Created 5 new gap issues from audit findings:
  - #39: Missing QR types (Facebook Messenger, Instagram, Rating/Review)
  - #40: JPEG XL output format support
  - #41: Remove closing PHP tags from include files
  - #42: Implement colourblind-friendly UI toggle
  - #43: Export branding PNGs from SVGs
- 📊 Updated PROJECT_STATUS.md Phase 1 section to reflect actual audit status (not blindly marked complete)

### 🚀 Added - SFTP Auto-Deploy GitHub Actions (2026-03-23)

- 🔄 GitHub Actions workflow (`.github/workflows/deploy.yml`) for automated SFTP deployment
- 🌿 `alpha` branch created for alpha development (deploys `public_html_dev_alpha/`)
- 🌿 `beta` branch created for beta development (deploys `public_html_dev_beta/`)
- 🌿 `main` branch deploys `public_html/` (production)
- 📦 All branches deploy server-wide shared folders and site-specific private folders
- 🗜️ JS/CSS/HTML/XML minification before upload
- 🔐 SSH key authentication (primary) with password fallback
- 🔧 Controlled via `SFTP_ENABLED` GitHub variable and GitHub Secrets
- 📝 GitHub Issue [#44](https://github.com/MWBMPartners/CueRCode/issues/44) created for tracking

### 🔢 Added - Automated Version Numbering & Build Info (2026-03-23)

- 🔢 `version-bump.yml` — auto-increments semver in `infoAppVer.php` on push (conventional commits: feat→minor, fix→patch, breaking→major)
- 🏷️ `release.yml` — creates GitHub Release on `v*` tag push (stable, beta, alpha, RC detection)
- 📝 `deploy.yml` updated to inject commit SHA, short SHA, date, and URL into `$app["Application"]["Version"]["Repo"]["Commit"]` before SFTP upload
- 🏷️ Production builds (main) automatically clear Development Status to NULL
- 🔧 Fixed `infoAppVer.php` key from `["Version"]["Version"]` to `["Version"]["Number"]` across all public_html variants

### ♿ Added - WCAG 2.1 AA Accessibility Compliance (2026-02-27)

- 🔗 Skip-to-main-content link on every page
- 🏷️ `aria-hidden="true"` on 29+ decorative FontAwesome icons and 14 inline SVGs
- 🏷️ `aria-label` on hex colour inputs and tab buttons for small screens
- 🏷️ `aria-pressed` toggle on all selection/toggle buttons (QR types, patterns, markers, frames)
- 📢 `aria-live="polite"` on preview container for dynamic state announcements
- ✏️ `autocomplete` attributes on dynamically rendered form fields (email, tel, url)
- 🔔 Toast notification ARIA roles: `role="alert"` for errors, `role="status"` for success
- 🎭 `prefers-reduced-motion` media queries for all animations (smooth scroll, fade, pulse)
- 🎯 Focus-visible indicators on theme toggle and outline buttons
- 📑 Proper heading hierarchy on error pages (descriptive `<h1>`, visual-only error code)
- 🏠 `<main>` landmark on offline page and installer
- 🌐 `lang="en"` on all fallback HTML blocks
- 🔘 Replaced `javascript:history.back()` with proper `<button>` on 404 page
- ♿ Accessible installer: status column header, icon text labels, `<main>` landmark

### 📦 Added - Third-Party Libraries & CDN Fallbacks (2026-02-27)

- 🐘 chillerlan/php-qrcode v5.0.5 (62 PHP files)
- 🐘 chillerlan/php-settings-container v3.2.1 (2 PHP files)
- 🐘 FPDF v1.86 with font definitions
- 🎨 Bootstrap 5.3.3 CSS and JS (CDN fallback)
- 🎨 FontAwesome 6.5.1 CSS and webfonts (CDN fallback)
- 📜 jQuery 3.7.1 (CDN fallback)

### 🔄 Changed - Project Rebrand: QRmy.Link → CueRCode (2026-02-27)

- 🏷️ Renamed project from "QRmy.Link" to "CueRCode"
- 🌐 Primary domain: CueRCode.net
- 🌐 Secondary domain: CueRCode.me (redirects to CueRCode.net)
- 🌐 Tertiary domain: QRmy.Link (redirects to CueRCode.net with web link QR function)
- 📁 Renamed GitHub repository from "QRcreator" to "CueRCode"
- 📁 Renamed site directory from `web/QRmy.Link/` to `web/CueRCode.net/`
- 📁 Renamed CSS/JS files: `qrmy.css` → `cuercode.css`, `qrmy-common.js` → `cuercode-common.js`, `qrmy-generator.js` → `cuercode-generator.js`
- ⚙️ Updated all PHP constants prefix: `QRMY_` → `CUERCODE_`
- 🎨 Updated all CSS custom properties: `--qrmy-*` → `--cuercode-*`
- 📜 Updated JavaScript namespace: `QRmy` → `CueRCode`
- 🔐 Updated session name: `QRMY_SID` → `CUERCODE_SID`
- 🔑 Updated API key prefix: `qrmy_` → `cuercode_`
- 💾 Updated service worker cache prefix: `qrmy-v1` → `cuercode-v1`
- 📄 Updated all URLs, email addresses, and documentation references

### 🧪 Added - Phase 1, Stage 7: Testing, Documentation & Polish (2026-02-27)

- 📱 PWA service worker (`sw.js`, 461 lines) with intelligent caching strategies
  - 🌐 Network-first for HTML pages, cache-first for static assets and CDN resources
  - 🚫 API requests excluded from caching, offline fallback page
  - 💾 Precaches critical app shell assets on install, cleans old caches on activate
  - 🔄 Stale-while-revalidate background updates for static assets
- 📴 Offline fallback page (`offline.html`, 288 lines) with self-contained styling
  - 🌙 Dark mode support via `prefers-color-scheme`, brand theming, retry button
  - 📦 Zero external dependencies (inline SVG icons, inline CSS)
- ⚙️ Service worker registration in shared footer template (`footer.php`)
- 🔒 Security review: XSS, SQL injection, dangerous function usage, CSRF protection verified
- ✅ PHP syntax validation passed for all 29 PHP files
- ✅ JavaScript syntax validation passed for all 3 JS files
- ✅ JSON validation passed for `openapi.json` and `manifest.json`
- 📄 Updated CHANGELOG.md, PROJECT_STATUS.md documentation

### 🔌 Added - Phase 1, Stage 6: REST API (2026-02-27)

- 🛣️ API v1 router (`api/v1/index.php`) with CORS, route matching, method validation
- 🔐 API authentication: X-API-Key header (external) + X-CSRF-Token (web UI AJAX) + anonymous fallback
- ⏱️ Rate limiting via `tblAPIRateLog` with per-minute/per-day windows, periodic cleanup
- 📤 `POST /api/v1/generate` - Full QR code generation with UUID, base64 data, download URL
- 👁️ `POST /api/v1/preview` - Quick PNG preview (300px, no DB save)
- 📥 `GET /api/v1/download/{uuid}` - File streaming with Content-Disposition and cache headers
- 📋 `GET /api/v1/types` - List active QR types with parsed formFieldsJSON schemas
- 📋 `GET /api/v1/formats` - List supported output formats with feature flags
- 💓 `GET /api/v1/health` - Health check with DB status, app version, PHP version
- 🔑 API key validation with HMAC-SHA256 hashing and timing-safe comparison
- 📊 Rate limit response headers (`X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Retry-After`)
- 📖 OpenAPI 3.0.3 specification (`openapi.json`) with full schemas and examples
- 📖 Swagger UI documentation page (`api/docs/index.php`) with CDN loading and dark mode support
- 📝 Request/response logging via `logActivity()`

### 🎨 Added - Phase 1, Stage 5: Responsive Web UI (2026-02-27)

- 🖥️ Main QR code generator page (`index.php`) with two-column responsive layout
- 📝 Dynamic form rendering from database-driven `formFieldsJSON` schemas
- 🎛️ Customisation tabs: Colors, Design (patterns/markers), Logo, Frame, Options
- 🔲 QR type selector grid with 21 types loaded from database
- 👁️ Live AJAX preview with debounced updates (`cuercode-generator.js`, 1,302 lines)
- 🛠️ Common JavaScript utilities: CSRF, AJAX wrapper, toast notifications, debounce, clipboard (`cuercode-common.js`)
- 🎨 Full responsive CSS with dark/light mode, print styles (`cuercode.css`, 1,266 lines)
- 🎨 CSS custom properties for brand theming (`--cuercode-primary`, `--cuercode-accent`, etc.)
- 🔲 QR preview container with checkerboard background for transparent QR codes
- 🖼️ Logo upload with FileReader preview and predefined logo grid
- 🖼️ Frame style selector with 7 visual mini-previews
- 🔳 Pattern/marker border/marker centre selection grids with inline SVGs
- 📄 Output format selector (PNG, SVG, JPG, WebP, GIF, PDF)
- 📏 Size slider, error correction level selector, quiet zone toggle
- 📥 Download functionality with format-specific file handling
- 🔄 Server-side form POST fallback for graceful degradation without JavaScript
- ❌ Error pages: 404 Not Found, 500 Internal Server Error
- ⚖️ Legal pages: Terms of Use, Privacy Policy
- 🏷️ 26 predefined logo SVG icons (social, messaging, payment, platform, utility categories)
- 🎯 Branding SVG assets: `favicon.svg` (QR pattern), `logo.svg` (brand teal)
- ⚙️ Updated `.htaccess` ErrorDocument paths for error pages

### 🔲 Added - Phase 1, Stage 4: QR Code Generation Engine (2026-02-27)

- 📦 QR payload builders for all 21 types (`fn_qr_payloads.php`, 1,523 lines)
- ⚙️ QR options builder mapping UI customisation to chillerlan QROptions (`fn_qr_options.php`, 845 lines)
- 🏭 Main generation orchestrator with 11-step pipeline (`fn_qr_generate.php`, 833 lines)
- 🖼️ Logo overlay for GD raster and SVG outputs (`fn_qr_logo.php`, 811 lines)
- 🖼️ Frame rendering with 8 styles for GD and SVG (`fn_qr_frames.php`, 813 lines)
- 🆔 RFC 4122 UUID v4 generation for QR code identification
- 💾 Database record saving with full customisation snapshot
- 📁 File system saving to `_uploads/qrcodes/` directory
- 👁️ Preview generation (smaller, PNG-only, no database record)

### ⚙️ Added - Phase 1, Stage 3: Core PHP Framework (2026-02-27)

- 🗄️ Database connection manager with MySQLi singleton (`db_connect.php`)
- 🔐 AES-256-GCM encryption with HKDF key derivation (`fn_encryption.php`)
- ⚙️ Configuration manager with request-lifetime cache and type casting (`fn_config.php`)
- 🚨 Custom error/exception/shutdown handlers logging to `tblErrorLog` (`fn_error_handler.php`)
- 📝 Activity logging to `tblActivityLog` (`fn_activity_log.php`)
- 🐛 Debug mode via URL parameter with config validation (`fn_debug.php`)
- ✅ Input validation: URL, email, phone (E.164), colour, JSON, file upload (`fn_validation.php`)
- 📤 JSON and XML response formatting with XSLT support (`fn_response.php`)
- 🛡️ CSRF token generation/validation with timing-safe comparison (`fn_csrf.php`)
- 🚀 Master bootstrap file loading all components in dependency order (`bootstrap.php`)
- 🖥️ Shared HTML header template with Bootstrap/FontAwesome CDN fallback (`header.php`)
- 🖥️ Shared HTML footer template with jQuery/Bootstrap JS CDN fallback (`footer.php`)
- 🔐 Secure session configuration with custom session name `CUERCODE_SID`

### 🗄️ Added - Phase 1, Stage 2: Database Schema & SQL Installation (2026-02-27)

- 📊 MySQL schema with 10 tables: tblSettings, tblErrorLog, tblActivityLog, tblQRCodeTypes, tblQRCodes, tblPredefinedLogos, tblAPIKeys, tblAPIRateLog, tblUsers (stub), tblOrganisations (stub)
- 🌱 Seed data: 52 default settings across 6 groups, 21 QR code type definitions with formFieldsJSON schemas, 25 predefined logo entries
- 🛠️ Web-based installer with Bootstrap UI, prerequisite checking, SQL execution, and lock file protection (`install/index.php`)

### 📐 Added - Phase 1, Stage 1: Project Foundation (2026-02-27)

- 📁 Initial project directory structure following the CueRCode architecture specification
- 📁 Server-wide and site-specific `_auth_keys/`, `_includes/`, `_functions/`, `_libraries/`, `_uploads/`, `_backups/` directories
- 🔒 `.htaccess` deny-all security files for all private directories (13 files)
- 🔐 Authentication credential templates (`auth_creds.example.php`) for server-wide and site-specific use
- 🌐 Master `.htaccess` for `public_html/` with URL rewriting, security headers, PHP settings, compression, and caching
- 🛣️ API `.htaccess` for RESTful URL routing (`/api/v1/*` requests)
- 📦 Custom PSR-4 autoloader (`_libraries/autoload.php`) replacing Composer for shared hosting compatibility
- 🧪 Development environment `.htaccess` files for alpha and beta access control
- ↩️ Redirection `.htaccess` for `public_html_redir/`
- 🚀 "Coming Soon" landing page (`public_html_landing/index.php`) with responsive design
- 📱 PWA manifest (`manifest.json`) with CueRCode branding
- 🤖 `robots.txt` with appropriate allow/disallow rules
- 🙈 Comprehensive `.gitignore` for PHP/MySQL development on macOS/Windows/Linux
- 📄 Project documentation: `README.md`, `CHANGELOG.md`, `PROJECT_STATUS.md`, `DEV_NOTES.md`
- 📌 `.gitkeep` placeholder files for empty directories tracked by Git
