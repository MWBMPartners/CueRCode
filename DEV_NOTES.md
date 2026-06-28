# 🛠️ CueRCode - Developer Notes

**📅 Last Updated:** 2026-03-23

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
