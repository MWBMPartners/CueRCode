# 🛠️ CueRCode - Developer Notes

**📅 Last Updated:** 2026-07-10 (Epic E8 - Microsoft Graph email subsystem, #29)

---

## 📏 Coding Standards & Conventions

### 🐘 PHP

- **Minimum version:** PHP 8.5 (with PHP 8.4 backward compatibility via conditional statements)
- **Predefined constants:** Use `DIRECTORY_SEPARATOR`, `PHP_EOL`, `PHP_INT_MAX`, etc. for platform neutrality
- **Code blocks:** Always use full notation (no shorthand `if`/`else`/`for`)
- **Indentation:** 4 spaces (no tabs)
- **Comments:** Detailed inline comments with links to official documentation. Emojis encouraged for section call-outs
- **Modularity:** Shared code in `_functions/` and `_includes/`, loaded via `require_once` with existence checks
- **Database:** MySQLi only, prepared statements for ALL queries
- **Sensitive data:** AES-256-GCM encrypted with salt before database storage
- **URLs:** No `.php` extensions visible to users (use directory-based `index.php` or `.htaccess` rewriting)

### 📜 JavaScript

- **Libraries:** jQuery + Bootstrap 5.3 JS Bundle
- **CDN with fallback:** All CDN resources have local vendor fallback copies
- **AJAX:** Used for dynamic content loading; graceful degradation when JS disabled
- **Comments:** Detailed inline comments matching PHP standards

### 🎨 CSS

- **Framework:** Bootstrap 5.3
- **Custom properties:** CSS variables for theming (`--cuercode-primary`, etc.)
- **Dark mode:** Automatic via `prefers-color-scheme` + manual toggle via `data-bs-theme`
- **Responsiveness:** Mobile-first, Bootstrap grid + custom breakpoints

### 🗄️ SQL

- **Table naming:** `tblTableName` (camelCase with `tbl` prefix)
- **Column naming:** `camelCase` (e.g., `settingKey`, `createdAt`)
- **Engine:** InnoDB with `utf8mb4_unicode_ci` collation
- **Timestamps:** All tables include `createdAt` and `updatedAt` datetime columns

---

## 📚 Third-Party Libraries

### 🔲 QR Code Generation

| Library | Version | Source | Purpose |
| ------- | ------- | ------ | ------- |
| 📦 chillerlan/php-qrcode | v5.x | [GitHub](https://github.com/chillerlan/php-qrcode) | Core QR code generation |
| 📦 chillerlan/php-settings-container | v3.x | [GitHub](https://github.com/chillerlan/php-settings-container) | Dependency of php-qrcode |
| 📦 FPDF | v1.86 | [fpdf.org](https://www.fpdf.org/) | PDF output support |

### 🌐 Frontend (CDN with local fallback)

| Library | Version | CDN | Purpose |
| ------- | ------- | --- | ------- |
| 🎨 Bootstrap | 5.3.3 | jsDelivr | CSS framework, responsive grid, components |
| 🎨 FontAwesome | 6.x | cdnjs | Icons |
| 📜 jQuery | 3.7.x | jsDelivr | DOM manipulation, AJAX |

### 📥 Installation (No Composer)

Since Dreamhost shared hosting has no CLI/Composer access:

1. 📥 Download library ZIP from GitHub releases
2. 📁 Extract `src/` directory into `web/CueRCode.net/_libraries/` path
3. ⚙️ Custom PSR-4 autoloader in `_libraries/autoload.php` handles class loading

---

## 📂 Directory Convention

- `_` prefix = 🔒 private directory (not web-accessible, secured via `.htaccess`)
- `public_html` = 🟢 production web root
- `public_html_dev_alpha` / `public_html_dev_beta` = 🧪 restricted development environments
- `public_html_landing` = 🚀 pre-launch coming soon page
- `public_html_redir` = ↩️ redirection-only web root

---

## ⚠️ Known Caveats & Intentional Patterns

### Zero-Deprecation DoD Bar (cycle 10)

PHP 8.4/8.5 zero-deprecation is a hard Definition-of-Done gate. As of cycle 10, zero `E_DEPRECATED` or `E_STRICT` notices are emitted by any file under `web/CueRCode.net/`. Any future change must preserve this bar — run `php -l` and verify no deprecation output before committing.

### deploy.yml Build-Info Injection — Marker-Comment Scheme (resolved #47, 2026-07-10)

**Historical note (superseded):** earlier cycles coupled `deploy.yml`'s build-info injection to a literal, case-sensitive `' = NULL'` (uppercase) string match on the four `Repo.Commit.*` assignment lines in `infoAppVer.php`. This was fragile — any reflow/reindent of those lines would silently break the sed substitution and ship `NULL` commit info to production with no error — and it forced those four lines to stay uppercase `NULL` while every other null in the file was house-standard lowercase.

**Current scheme (deep-sweep #47):** each of the four Repo→Commit assignments now carries a stable trailing anchor comment instead of relying on the literal value:

```php
$app["Application"]["Version"]["Repo"]["Commit"]["SHA"]["Full"]  = null; // @build-info: commit-sha-full
$app["Application"]["Version"]["Repo"]["Commit"]["SHA"]["Short"] = null; // @build-info: commit-sha-short
$app["Application"]["Version"]["Repo"]["Commit"]["Date"]         = null; // @build-info: commit-date
$app["Application"]["Version"]["Repo"]["Commit"]["URL"]          = null; // @build-info: commit-url
```

`.github/workflows/deploy.yml`'s "Inject commit info into infoAppVer.php" step now matches the `// @build-info: <key>` anchor text (e.g. `s|= null; // @build-info: commit-sha-full|= "${COMMIT_SHA}"; // @build-info: commit-sha-full|`), not the assignment's casing or spacing. This means:

- All four values are now house-standard lowercase `null` in the committed source — no more retained-uppercase exception.
- The file can be reflowed/reindented freely as long as the anchor comment text (`commit-sha-full`, `commit-sha-short`, `commit-date`, `commit-url`) is preserved verbatim.
- The scheme applies identically to **all three** `infoAppVer.php` copies (`public_html/`, `public_html_dev_alpha/`, `public_html_dev_beta/`) — `deploy.yml`'s injection step already runs `find "$STAGING_DIR" -name "infoAppVer.php"` against whichever copy is staged for the branch being deployed (alpha/beta branches get their own commit metadata injected the same way main does).

**Do not remove the anchor comments** without updating `deploy.yml` to match — that is now the only load-bearing part of these four lines.

### dev_alpha / dev_beta infoAppVer.php Copies (resolved #47, 2026-07-10)

`web/CueRCode.net/public_html_dev_alpha/infoAppVer.php` and `web/CueRCode.net/public_html_dev_beta/infoAppVer.php` have now received the full standards pass (tabs→spaces, doc header with `Path:`/`@copyright`, `||`/`&&`, lowercase `null` incl. the build-info lines above, section spacing, no trailing `?>`) bringing them in sync with the production copy. All `$app` values (including each channel's own Version Number, `0.1.0`) were preserved unchanged.

### Dev-Environment Access Control — Fail-Closed by Default (resolved #47, 2026-07-10)

`public_html_dev_alpha/.htaccess` and `public_html_dev_beta/.htaccess` previously shipped with `Require valid-user` commented out and no active deny rule, leaving both dev environments unprotected as committed. Both now default to `Require all denied` (Apache 2.4+, with an Apache 2.2 `Order deny,allow`/`Deny from all` fallback) as the active rule. The Basic-Auth block is retained, fully commented, with numbered steps for the owner to create a `.htpasswd` file on the server and switch over. **Before either dev channel goes live, the owner must deliberately opt in** — it is denied-by-default, not open-by-default.

### Email Subsystem (Microsoft Graph) - Epic E8, #29

`_functions/fn_email.php` implements transactional email via a Microsoft 365 shared mailbox over the Microsoft Graph API, using the OAuth2 **client-credentials** grant (application permission, no signed-in user). Per ProjectBrief §13 this deliberately does **NOT** use PHP's `mail()` function.

**Inert by default.** `email_enabled` (tblSettings, group `email`) seeds to `0`. Every public function in `fn_email.php` fails OPEN the moment the kill-switch is off, the config subsystem isn't loaded, or any Graph credential is unset (the exact state of a fresh install) - `getGraphAccessToken()` returns `null` and `sendEmailNow()` returns `[false, "skipped: ..."]` with **zero network calls attempted**. Nothing sends until an owner completes the setup below.

**Owner setup to activate:**

1. **Register an M365 app** (Microsoft Entra ID / Azure AD → App registrations → New registration).
2. **Grant the `Mail.Send` APPLICATION permission** (not delegated) and have a Global Admin **grant admin consent**.
3. **Create/designate a shared mailbox** the app will send *as* (Send-As), e.g. `notifications@yourdomain.com`. The signed-in app has no user of its own - Graph's `sendMail` targets `/v1.0/users/{mailbox}/sendMail` where `{mailbox}` is this shared mailbox's address.
4. **Configure DKIM and DMARC** on the sending domain (M365 admin center → Exchange → mail flow, or your DNS provider) so mail sent via Graph passes authentication checks at the recipient (Gmail/Outlook increasingly reject/spam-fold unauthenticated bulk mail).
5. **Fill the settings via the admin UI** (never commit real values - all seed EMPTY):
   - `graph_tenant_id` - the Entra ID tenant ID (Directory (tenant) ID on the app registration's Overview page).
   - `graph_client_id` - the app registration's Application (client) ID.
   - `graph_client_secret` - a client secret VALUE (not the secret ID) created under the app registration's "Certificates & secrets".
   - `graph_sender_mailbox` - the shared mailbox address from step 3.
   - `email_from_name` - the display name recipients see (e.g. "CueRCode Notifications").
6. **Flip `email_enabled` to `1`** once the above is verified working (e.g. via a manual `sendEmailNow()` smoke test).
7. **Choose a queue-processing strategy** - `email_queue_autoprocess` (0 = OFF by default):
   - **Recommended: Dreamhost panel cron** (no SSH needed) - Dreamhost's panel → Cron Jobs → add a job that runs a small PHP script (e.g. `_includes/cron/process_email_queue.php`, to be added when the queue starts carrying real traffic) calling `processEmailQueue()` on a schedule (e.g. every 5 minutes).
   - **Alternative: opportunistic shutdown-hook** - set `email_queue_autoprocess=1` and call `maybeProcessEmailQueueOnShutdown()` from an entry point AFTER `fn_email.php` is loaded (NOT wired into `bootstrap.php` by default, so the core request path is untouched unless this is deliberately opted into). Processes ≤2 emails per request, after the response has already been sent - fail-open, never surfaces an error to the client.

**Queue + retry:** `queueEmail()` inserts a `tblEmailQueue` row (status `queued`, `nextAttemptAt = NOW()`); `processEmailQueue($max=2)` claims due rows (`status IN ('queued','failed')`, `nextAttemptAt <= NOW()`, `attempts < maxAttempts`), re-renders the template fresh (never trusts a stored, possibly-stale rendered body), and calls `sendEmailNow()`. On success the row becomes `sent`; on failure, `attempts` increments and the row is rescheduled with **exponential backoff** (`nextAttemptAt = NOW() + 2^attempts MINUTES`, computed by the pure, unit-tested `computeEmailBackoffMinutes()`) until `maxAttempts` (default 5) is reached, at which point the row is marked permanently `failed`. **Caveat:** a row queued while `email_enabled=0` (or otherwise unconfigured) will burn through its retry budget (~62 cumulative minutes at the default schedule/maxAttempts) and land on `failed` even though the "failure" was really just "not configured yet" - don't queue anything meaningful before `email_enabled=1` is set.

**Templates:** `_includes/email_templates/{key}.php` (currently `verification`, `password_reset`, `invitation`, `receipt` - the allowlist enforced by `isValidEmailTemplateKey()`) each `return [$subject, $htmlBody];` and use the shared `emailRenderShell()` wrapper for consistent branding. Every interpolated parameter is escaped via `emailEscape()` (`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`) - proven by an XSS-injection test per template. **These templates use nested `<table>` layout - a deliberate, EMAIL-ONLY exception** to the project's no-`<table>`-for-layout house rule: email clients (Outlook desktop's Word rendering engine especially) have extremely limited CSS support and effectively require table layout for reliable cross-client rendering. This does not relax the rule anywhere else in the codebase.

**Driver-shaped for #30:** the `email_provider` setting (currently only `'graph'`) is the seam a future Google Workspace driver will plug into without any caller (`queueEmail()`/`sendEmailNow()`) needing to change.

### Braille Dot-Geometry Approach & Grade-1 Scope (#49, 2026-07-10)

`fn_qr_accessible_pdf.php` draws Braille as **geometric dot patterns**, not a Unicode Braille font. Two reasons this is not optional:

1. **Core FPDF fonts have no Braille glyphs.** The library only ships Courier/Helvetica/Times/Symbol/ZapfDingbats (single-byte WinAnsi/CP1252 core fonts) — embedding a Unicode Braille TTF is out of scope for this feature.
2. **Embossing needs real dot geometry, not character rendering.** A swell-paper/embosser pass reproduces raised dots from actual coordinates, not from however a font renderer happens to draw a Unicode Braille codepoint glyph.

**How the dots are drawn:** core FPDF has no native circle/ellipse operator (only straight-edged `Rect()`/`Line()`), and reaching into FPDF's protected `_out()` method to draw true Bézier circles would require subclassing `\FPDF` — which introduces a load-order precondition (the FPDF library must be `require`d before the subclass is *declared*, not merely before it's *called*, since `class X extends Y` resolves `Y` immediately). Instead, `drawFilledCircleOnPdf()` approximates a filled circle as a small stack of full-width `Rect()` "strips" whose half-width follows the circle equation (`circleStripHalfWidthMm()`, a pure/unit-tested helper: `sqrt(r² - dy²)`). At the ~1.5mm dot size Braille actually needs, this reads as a clean round dot on any real printer/embosser, and it needs nothing beyond `\FPDF`'s public API — no subclass, no load-order precondition beyond the type-hint being resolvable *at call time* (which production's `bootstrap.php` always guarantees).

**Dot geometry constants** (approximate "Marburg Medium" print/embossing figures, commented at the top of `fn_qr_accessible_pdf.php`): dot ⌀ ~1.5mm, intra-cell dot pitch ~2.5mm, inter-cell (character) pitch ~6.0mm, line pitch ~10mm. These are widely-cited approximate figures, **not an embosser-certified spec** — real hardware (BANA "standard"/"jumbo" cells, etc.) varies slightly. Always print/emboss at 100% scale.

**Grade-1 (uncontracted) scope only.** Implemented: a-z, 0-9 (behind the Braille number sign, dots 3-4-5-6, one per digit *run* not per digit), a single-letter capital indicator (dot 6), space, and a small common-punctuation set (period/comma/semicolon/colon/question/exclamation/hyphen/apostrophe). **Grade-2 (contracted) Braille is explicitly out of scope** — whole-word/part-word contractions (e.g. a single cell standing in for "the") need a large, locale-specific contraction dictionary and disambiguation logic that a short QR caption does not warrant. Any character outside the implemented subset renders as a documented blank cell (`brailleTransliterateGrade1()`) rather than being silently dropped or mis-transliterated, keeping caption↔cell alignment predictable.

**Tactile locator frame is an original design**, not a reproduction of Chulalongkorn University's Thai petty patent No. 17871 (Thailand-only, and the roadmap directive was to design a distinct variant regardless of jurisdiction). See `drawTactileLocatorFrame()`'s docblock for the exact geometry (continuous frame + 4 corner "L" marks + 1 bottom orientation dot).

---

## 🧪 Testing

A dependency-free PHP characterization test harness lives under `tests/` at the repo root. It requires no Composer or external packages and can be run on any machine with PHP 8.4+.

**Run all tests:**

```bash
php tests/run.php
```

**Run a single file by name filter:**

```bash
php tests/run.php --filter=core
php tests/run.php --filter=payloads
```

Exits 0 if all assertions pass (or if there are no tests), 1 if any fail. Coloured per-file and grand-total output is printed to stdout.

**Structure:**

| Path | Purpose |
| ---- | ------- |
| `tests/run.php` | Test runner — discovers `tests/test_*.php`, supports `--filter` |
| `tests/bootstrap.php` | Test-only constants (ENCRYPTION_KEY/SALT, CUERCODE_* paths), `requireFn()` loader (includes `fn_*.php` without opening a DB), `requireLibraryAutoloader()` opt-in |
| `tests/lib/assert.php` | Assertion helpers: `assertEquals`, `assertTrue`, `assertFalse`, `assertContains`, `assertMatches`, `assertThrows` |
| `tests/test_payloads.php` | Characterization tests for all 21 QR payload builders and escaping helpers |
| `tests/test_core.php` | Characterization tests for encryption, CSRF, validation, QR options, and response formatting |
| `tests/test_email.php` | Microsoft Graph email subsystem (#29) - template rendering/escaping, backoff schedule maths, and the fail-open contract when unconfigured/no DB |

**Deployment boundary:** `tests/` is repo-root only and is excluded from all SFTP deploy workflows. It never reaches the Dreamhost server.

**DB-independent vs DB-dependent boundary:** `requireFn()` in `bootstrap.php` includes `fn_*.php` files directly without running `db_connect.php`, so any function that does not open a DB connection is testable. Functions that require a live MySQLi connection (`fn_config.php` `getConfig`/`setConfig`, `fn_activity_log.php`, etc.) are deliberately out of scope and documented as such in the test file headers.

---

## 🐛 Debug Mode

Add `?debug=true` to any URL to enable debug output (development use only).
This shows:

- ⚠️ PHP errors/warnings inline
- 🗄️ Query execution details
- ⏱️ Request timing information
- ⚙️ Configuration state

---

## 🚨 Error Handling

All errors are logged to `tblErrorLog` in the database, including:

- 📊 Severity level (emergency through debug)
- 🐘 PHP error code and backtrace
- 🌐 Request URL, method, headers, IP
- 🖥️ User agent and session information

---

## 🔐 Encryption

Sensitive values (API keys, SSO credentials, etc.) in `tblSettings` with `isSensitive = 1`:

- **Algorithm:** 🔒 AES-256-GCM
- **Key:** Defined in `_auth_keys/auth_creds.php` as `ENCRYPTION_KEY`
- **Salt:** Defined in `_auth_keys/auth_creds.php` as `ENCRYPTION_SALT`
- **IV:** 🎲 Random per encryption (stored with ciphertext)
- **Authentication:** ✅ GCM auth tag validates integrity

---

## 🏗️ Key Architecture Decisions

1. **🗄️ Database-driven QR types:** `tblQRCodeTypes.formFieldsJSON` stores input field schemas, allowing new QR types without code changes
2. **🔌 API-first:** Web UI AJAX calls the same API endpoints as external consumers
3. **📦 Custom PSR-4 autoloader:** Replaces Composer for shared hosting environments
4. **🏭 Post-processing pipeline:** Logo overlay and frame rendering after QR generation
5. **🎨 Progressive enhancement:** Full shape variety for SVG; square + round for raster (Phase 1)
6. **📐 API is the parity contract:** The REST API (`POST /api/v1/generate`, `POST /api/v1/preview`) is a strict superset of the web UI — every customisation parameter the UI can send is accepted by the API. The web frontend is itself built on the API. If a UI feature is added, a corresponding API parameter must be exposed in `buildCustomizationFromRequest()` (cycle 9, GitHub #45).
7. **📖 `openapi.json` is the source of truth for the API surface:** `public_html/api/v1/openapi.json` (OpenAPI 3.0.3) is the canonical definition of every endpoint, parameter, schema, and response. Swagger UI at `public_html/api/docs/` derives from it. Any API change must update `openapi.json` first.

---

## 🔢 Version Numbering

Uses [Semantic Versioning](https://semver.org/): `MAJOR.MINOR.PATCH`

Version is stored in `infoAppVer.php` as `$app["Application"]["Version"]["Number"]` and also embedded in:

- 📄 Code file headers (comment blocks)
- 🔌 API responses (`X-API-Version` header)
- 📱 `manifest.json`
- 🗄️ Database (`tblSettings.app_version`)

### Automated Version Bumping

GitHub Actions (`version-bump.yml`) auto-increments the version on push using [Conventional Commits](https://www.conventionalcommits.org/):

| Commit Prefix | Bump Type | Example |
| --- | --- | --- |
| `feat:` or `feat(scope):` | MINOR | `feat: add bulk QR generation` |
| `fix:`, `chore:`, `docs:`, etc. | PATCH | `fix: correct vCard encoding` |
| `...!:` or `BREAKING CHANGE` in body | MAJOR | `feat!: redesign API response format` |

Each branch bumps its own `infoAppVer.php`:

- `alpha` → `web/CueRCode.net/public_html_dev_alpha/infoAppVer.php`
- `beta` → `web/CueRCode.net/public_html_dev_beta/infoAppVer.php`
- `main` → `web/CueRCode.net/public_html/infoAppVer.php`

### Build Info Injection

The deploy workflow injects commit metadata into `infoAppVer.php` before SFTP upload:

```php
$app["Application"]["Version"]["Repo"]["Commit"]["SHA"]   = "abc1234...";
$app["Application"]["Version"]["Repo"]["Commit"]["Short"] = "abc1234";
$app["Application"]["Version"]["Repo"]["Commit"]["Date"]  = "2026-03-23 14:30";
$app["Application"]["Version"]["Repo"]["Commit"]["URL"]   = "https://github.com/.../commit/abc1234...";
```

For production (main) builds, `Development Status` is automatically set to `NULL`.

### GitHub Releases

Pushing a version tag creates a GitHub Release (`release.yml`):

- `v1.2.3` → Stable release
- `v1.2.3-beta` → Beta pre-release
- `v1.2.3-alpha` → Alpha pre-release
- `v1.2.3-rc.1` → Release candidate

---

## 🚀 CI/CD - SFTP Auto-Deployment

### Branch Deployment Strategy

| Branch | Channel | CueRCode.net public_html | QRmy.Link public_html |
| --- | --- | --- | --- |
| `alpha` | Alpha | `public_html_dev_alpha/` | `public_html_dev_alpha/` |
| `beta` | Beta | `public_html_dev_beta/` | `public_html_dev_beta/` |
| `main` | Production | `public_html/` | `public_html/` |

**All branches also deploy:**

- Server-wide shared folders (`web/_auth_keys/`, `web/_includes/`, `web/_functions/`, `web/_libraries/`, `web/_uploads/`, `web/_backups/`)
- Site-specific private folders (`web/CueRCode.net/_*`, `web/QRmy.Link/_*`) — everything that does NOT have `public_html` or `private_html` in the directory name

### Required GitHub Secrets

| Secret | Description | Example |
| --- | --- | --- |
| `SFTP_HOST` | SFTP server hostname | `myserver.dreamhost.com` |
| `SFTP_PORT` | SFTP port number | `22` |
| `SFTP_USER` | SFTP username | `deploy_user` |
| `SFTP_KEY` | SSH private key (primary auth) | PEM format private key |
| `SFTP_PASSWORD` | SFTP password (fallback if key empty) | `p@ssw0rd` |
| `SFTP_REMOTE_BASE` | Base remote directory on server | `/home/user/` |

### Required GitHub Variables

| Variable | Description | Value |
| --- | --- | --- |
| `SFTP_ENABLED` | Master switch for deployments | `true` |

### Authentication Priority

1. **SSH Key** (`SFTP_KEY` secret) — used when set and non-empty
2. **Password** (`SFTP_PASSWORD` secret) — fallback when SSH key is not available

### Pre-Upload Processing

- 🗜️ JavaScript minified via `terser`
- 🗜️ CSS minified via `clean-css-cli`
- 🗜️ HTML minified via `html-minifier-terser`
- 🗜️ XML whitespace stripped
- 📄 `.DS_Store`, `.gitkeep`, `.gitignore`, `README.md`, `CHANGELOG.md`, `DEV_NOTES.md` excluded from upload

### Workflow Location

`.github/workflows/deploy.yml` — modelled after [phpWhoIs deploy.yml](https://github.com/MWBMPartners/phpWhoIs/blob/main/.github/workflows/deploy.yml)
