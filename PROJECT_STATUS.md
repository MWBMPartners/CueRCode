# 📊 CueRCode - Project Status

**📅 Last Updated:** 2026-02-27
**🔄 Current Phase:** Phase 1 - Core QR Engine
**📈 Overall Status:** In Development

---

## 🏗️ Phase 1: Core QR Engine

### 📐 Stage 1: Project Foundation & Directory Structure

**Status:** ✅ Complete

- ✅ Create full directory scaffold (server-wide + site-specific)
- ✅ Create `.htaccess` deny-all security files for all private directories
- ✅ Create authentication credential templates (`auth_creds.example.php`)
- ✅ Create master `.htaccess` for `public_html/` (URL rewriting, security headers, PHP settings)
- ✅ Create API `.htaccess` for RESTful URL routing
- ✅ Create custom PSR-4 autoloader (`_libraries/autoload.php`)
- ✅ Create development environment `.htaccess` files (alpha, beta)
- ✅ Create "Coming Soon" landing page
- ✅ Create PWA manifest and robots.txt
- ✅ Update `.gitignore` for PHP/MySQL development
- ✅ Create project documentation (README.md, CHANGELOG.md, PROJECT_STATUS.md, DEV_NOTES.md)
- ⬜ Download and place third-party libraries (chillerlan/php-qrcode, FPDF)
- ⬜ Verify autoloader loads library classes successfully

### 🗄️ Stage 2: Database Schema & SQL Installation

**Status:** ✅ Complete

- ✅ Create `install.sql` with all 10 table definitions (InnoDB, utf8mb4_unicode_ci)
- ✅ Create `seed_data.sql` with 52 settings, 21 QR types with formFieldsJSON, 25 predefined logos
- ✅ Create web-based installer (`install/index.php`) with prerequisite checks and lock file
- ⬜ Test database installation on live server

### ⚙️ Stage 3: Core PHP Framework

**Status:** ✅ Complete

- ✅ Create `db_connect.php` (MySQLi singleton with STRICT_TRANS_TABLES, UTC timezone)
- ✅ Create `fn_encryption.php` (AES-256-GCM with HKDF key derivation)
- ✅ Create `fn_config.php` (request-lifetime cache, type casting, decrypt sensitive)
- ✅ Create `fn_error_handler.php` (custom error/exception/shutdown handlers to tblErrorLog)
- ✅ Create `fn_activity_log.php` (activity logging to tblActivityLog)
- ✅ Create `fn_debug.php` (URL param + config gate, debug output helpers)
- ✅ Create `fn_validation.php` (URL, email, phone E.164, colour, JSON, file upload)
- ✅ Create `fn_response.php` (JSON/XML with XSLT, error/success helpers)
- ✅ Create `fn_csrf.php` (timing-safe token validation, meta tag for AJAX)
- ✅ Create `bootstrap.php` (path constants, autoloader, DB, functions, session, timezone)
- ✅ Create `header.php` (meta tags, OG, CDN with fallback, dark mode, navbar)
- ✅ Create `footer.php` (jQuery/Bootstrap JS CDN fallback, debug panel)
- ⬜ Test framework initialisation on live server

### 🔲 Stage 4: QR Code Generation Engine

**Status:** ✅ Complete

- ✅ Create `fn_qr_payloads.php` (21 type-specific payload builders, 1,523 lines)
- ✅ Create `fn_qr_options.php` (UI to QROptions mapping, 845 lines)
- ✅ Create `fn_qr_generate.php` (11-step orchestrator with UUID, DB save, 833 lines)
- ✅ Create `fn_qr_logo.php` (GD raster + SVG logo overlay, 811 lines)
- ✅ Create `fn_qr_frames.php` (8 frame styles for GD + SVG, 813 lines)
- ⬜ Test all QR types and output formats on live server

### 🎨 Stage 5: Responsive Web UI

**Status:** ✅ Complete

- ✅ Create `index.php` (two-column generator page, dynamic forms, customisation tabs)
- ✅ Create `cuercode.css` (responsive styles, dark mode, print styles, 1,266 lines)
- ✅ Create `cuercode-generator.js` (interactive generator, AJAX preview, download, 1,302 lines)
- ✅ Create `cuercode-common.js` (CSRF, AJAX wrapper, toasts, debounce, clipboard, 429 lines)
- ✅ Create SVG assets (26 predefined logos + 2 branding icons)
- ✅ Create error pages (404, 500 with full header/footer integration)
- ✅ Create legal pages (Terms of Use, Privacy Policy)
- ⬜ Test responsive behaviour and accessibility on multiple devices

### 🔌 Stage 6: REST API

**Status:** ✅ Complete

- ✅ Create API router (`api/v1/index.php`) with CORS, route matching, method validation
- ✅ Create API handlers: generate, preview, download, types, formats, health
- ✅ Create API authentication (`fn_api_auth.php`): API key + CSRF + anonymous
- ✅ Create rate limiting via `tblAPIRateLog` with periodic cleanup
- ✅ Create OpenAPI 3.0.3 specification (`openapi.json`) with full schemas
- ✅ Create Swagger UI documentation page (`api/docs/index.php`)
- ✅ Web UI AJAX wired to `/api/v1/preview` and `/api/v1/generate`
- ⬜ Test all API endpoints on live server

### 🧪 Stage 7: Testing, Documentation & Polish

**Status:** ✅ Complete

- ✅ Create branding assets (logo, favicon in SVG format)
- ✅ Create PWA service worker (`sw.js`, 461 lines with multi-cache strategy)
- ✅ Create offline fallback page (`offline.html`, self-contained dark mode support)
- ✅ Add service worker registration to footer template
- ✅ Security review (XSS, SQL injection, dangerous functions, CSRF)
- ✅ PHP syntax validation (all 29 files pass)
- ✅ JavaScript syntax validation (all 3 files pass)
- ✅ JSON validation (openapi.json, manifest.json)
- ✅ Update CHANGELOG.md, PROJECT_STATUS.md, DEV_NOTES.md
- ✅ Download third-party PHP libraries (chillerlan/php-qrcode v5.0.5, php-settings-container v3.2.1, FPDF v1.86)
- ✅ Download CDN fallback vendor copies (Bootstrap 5.3.3, FontAwesome 6.5.1, jQuery 3.7.1)
- ✅ WCAG 2.1 AA accessibility audit (27 issues found and fixed)
- ✅ Project rebrand: QRmy.Link → CueRCode (all references, files, directories, GitHub repo)
- ✅ GitHub Issues (9 issues), Project board, Wiki (8 pages), Milestone created
- ⬜ Export branding PNGs from SVGs (logo-192.png, logo-512.png, og-image.png)
- ⬜ Cross-browser testing on live server

---

## 🔮 Future Phases

### 🚀 Phase 2: User Accounts & Organisations

| Feature | Status |
| --- | --- |
| 👤 User registration and authentication (local accounts) | 📋 Planned |
| 🏢 Organisation management (Global Admin, Admin, Regular User roles) | 📋 Planned |
| 🔑 SSO integration (Microsoft 365, Google Workspace) | 📋 Planned |
| 💾 Saved QR codes and user logo management | 📋 Planned |

### 💳 Phase 3: Premium Features & Payments

| Feature | Status |
| --- | --- |
| 📊 Subscription tiers management | 📋 Planned |
| 💰 PayPal payment integration (Apple Pay/Google Pay on supported devices) | 📋 Planned |
| 🔒 Advanced features gating | 📋 Planned |

### 🔗 Phase 4: Integrations

| Feature | Status |
| --- | --- |
| 🔗 Go2My.Link URL shortener integration (dynamic QR codes) | 📋 Planned |
| 🔐 SIGNula account linking | 📋 Planned |
| 📱 Companion mobile app support | 📋 Planned |

### ⭐ Phase 5: Advanced Features

| Feature | Status |
| --- | --- |
| 🖌️ WYSIWYG LinksPage template editor | 📋 Planned |
| 📦 Bulk QR code generation | 📋 Planned |
| 📈 Analytics and tracking | 📋 Planned |
| 🎨 Advanced customisation (custom GD output classes for all raster patterns) | 📋 Planned |

---

### 📌 Status Legend

| Icon | Meaning |
| --- | --- |
| ✅ | Complete |
| 🔄 | In Progress |
| ⬜ | Not Started (requires live server) |
| 📋 | Planned (future phase) |
| ❌ | Abandoned |
