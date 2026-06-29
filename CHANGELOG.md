# 📝 Changelog

All notable changes to the CueRCode project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
