# 📱 CueRCode - Professional QR Code Generator

A web-based service for generating customisable, downloadable QR codes via a fully responsive web UI and RESTful API.

## 🌐 Domains

| Domain | Purpose |
| --- | --- |
| 🟢 **CueRCode.net** | Primary domain - main application |
| 🔵 **CueRCode.me** | Secondary domain - redirects to CueRCode.net ("QR Code me!") |
| 🟣 **QRmy.Link** | Tertiary domain - redirects to CueRCode.net with web link QR function |

## 📋 Overview

**CueRCode** is a comprehensive QR code generation platform that supports:

- 📊 **26 QR code types**: URLs, text, email, phone, SMS, WhatsApp, Zoom, geolocation, vCard, calendar events, PayPal, Bitcoin, media files, social media, Wi-Fi, and more
- 📏 **Non-QR barcodes** (#69): `POST /api/v1/barcode` generates 11 additional 1D/2D symbologies - Code 128, Code 39, EAN-13, EAN-8, UPC-A, UPC-E, ITF, Codabar, Data Matrix, PDF417, Aztec Code - with strict server-side check-digit/charset validation
- 🎨 **6 output formats**: PNG, JPG, GIF, SVG, WEBP, PDF (JPEG XL isn't available on the host, #40)
- 🖌️ **Full customisation**: colours, patterns, marker shapes, centre logos, frames with labels, gradients, transparency
- 🙂 **Native emoji centre-icons** (#67): a searchable, categorised picker offering ~400 curated Twemoji (CC-BY-4.0) emoji as a QR-centre logo source (`logo: "emoji:1f600"`), alongside file-upload and predefined-brand logos
- 🔌 **RESTful API**: JSON and XML responses, API key authentication (self-service key create/rotate/revoke + per-key scope enforcement), rate limiting, Swagger/OpenAPI documentation
- 📱 **Responsive Web UI**: Bootstrap 5, dark/light mode, AJAX live preview, WCAG accessibility
- 💷 **Public pricing page** (`/pricing/`): a data-driven plan-comparison grid sourced entirely from `tblSubscriptionPlans` - no account needed to generate, free-tier registration, Pro/Business tiers shown ahead of checkout (#56)

## ⚙️ Tech Stack

| Component | Technology |
| --- | --- |
| 🖥️ Backend | PHP 8.5 (backward-compatible with 8.4) |
| 🗄️ Database | MySQL via MySQLi (prepared statements only) |
| 📦 QR Library | [chillerlan/php-qrcode](https://github.com/chillerlan/php-qrcode) v5.x |
| 📊 Barcode Library | [tecnickcom/tc-lib-barcode](https://github.com/tecnickcom/tc-lib-barcode) v2.12.0 (#69) |
| 🙂 Emoji Assets | [Twemoji](https://github.com/jdecked/twemoji) v17.0.3, CC-BY-4.0 (#67) |
| 🎨 Frontend | HTML5, CSS3, Bootstrap 5.3, FontAwesome, jQuery |
| 📖 API Docs | Swagger/OpenAPI 3.0 |

## 📁 Project Structure

```text
CueRCode/
├── .claude/                        ← 🤖 Claude AI context and prompts
├── .OpenAI/                        ← 🤖 OpenAI/ChatGPT context and prompts
├── web/                            ← 🌐 All web-deployable files
│   ├── _auth_keys/                 ← 🔐 Server-wide credentials (outside web root)
│   ├── _includes/                  ← 📦 Server-wide shared includes
│   ├── _functions/                 ← ⚙️ Server-wide shared functions
│   ├── _libraries/                 ← 📚 Server-wide shared libraries
│   ├── _uploads/                   ← 📤 Server-wide uploads
│   ├── _backups/                   ← 💾 Server-wide backups
│   └── CueRCode.net/              ← 🌐 Main CueRCode.net website
│       ├── _auth_keys/             ← 🔐 Site-specific credentials
│       ├── _includes/              ← 📦 Site-specific includes (bootstrap, SQL, templates, cron jobs)
│       ├── _functions/             ← ⚙️ Site-specific functions (QR engine, API handlers)
│       ├── _libraries/             ← 📚 Third-party libraries (chillerlan, FPDF, tecnickcom)
│       ├── _uploads/               ← 📤 User uploads (logos, generated QR codes)
│       ├── _backups/               ← 💾 Site backups
│       ├── public_html/            ← 🌍 Production web root
│       ├── public_html_dev_alpha/  ← 🧪 Alpha development environment
│       ├── public_html_dev_beta/   ← 🔬 Beta development environment
│       ├── public_html_landing/    ← 🚀 Pre-launch landing page
│       └── public_html_redir/      ← ↩️ Redirection web root
├── tests/                          ← 🧪 Dependency-free PHP test suite (never deployed)
├── tools/                          ← 🛠️ Maintenance/dev scripts (emoji ingest, logo rendering)
├── CHANGELOG.md
├── PROJECT_STATUS.md
└── DEV_NOTES.md
```

> 🔒 **Note:** Directories prefixed with `_` (underscore) are private and not web-accessible, secured via `.htaccess` deny-all rules.

## 🚀 Getting Started

### Prerequisites

- 🐘 PHP 8.4 or 8.5 with extensions: `mbstring`, `gd`, `json`, `openssl`, `mysqli`, `curl` (email/SSO), `zip` (bulk downloads), `bcmath` (PDF417 barcodes). `imagick` is optional (only used for predefined logos on raster output) - the installer itself only checks for `mysqli`, `mbstring`, `gd`, `json` and `openssl`.
- 🗄️ MySQL 5.7+ or MariaDB 10.3+
- 🌐 Apache with `mod_rewrite` enabled

### Installation

1. **📥 Clone the repository:**

   ```bash
   git clone https://github.com/MWBMPartners/CueRCode.git
   ```

2. **📦 Download third-party libraries** (not included in repo, and the auto-deploy workflow does NOT upload them - they must be put on the server by hand, once):
   - [chillerlan/php-qrcode v5.0.5](https://github.com/chillerlan/php-qrcode/releases) → `web/CueRCode.net/_libraries/chillerlan/php-qrcode/`
   - [chillerlan/php-settings-container v3.2.1](https://github.com/chillerlan/php-settings-container/releases) → `web/CueRCode.net/_libraries/chillerlan/php-settings-container/`
   - [FPDF v1.86](https://www.fpdf.org/) → `web/CueRCode.net/_libraries/fpdf/`
   - [tecnickcom/tc-lib-barcode v2.12.0](https://github.com/tecnickcom/tc-lib-barcode/releases) → `web/CueRCode.net/_libraries/tecnickcom/tc-lib-barcode/`
   - [tecnickcom/tc-lib-color v2.13.1](https://github.com/tecnickcom/tc-lib-color/releases) → `web/CueRCode.net/_libraries/tecnickcom/tc-lib-color/`
   - Local copies of Bootstrap 5.3.3, jQuery 3.7.1 and FontAwesome 6.5.1 → `web/CueRCode.net/public_html/assets/vendor/bootstrap/`, `.../assets/vendor/jquery/`, `.../assets/vendor/fontawesome/` respectively (these three are gitignored; the Swagger UI fallback, by contrast, IS committed to the repo already, so it needs no manual step - #111). **Bootstrap's and jQuery's copies are genuine CDN fallbacks** - `header.php`/`footer.php` already check whether the CDN version loaded and, if not, switch to these local files, so uploading them does something real. **FontAwesome's copy currently does nothing** - `header.php` loads FontAwesome from cdnjs only, with no fallback check wired up yet (tracked in #119), so uploading it is harmless but has no effect until that is built.

3. **🔐 Configure credentials:**

   There is no `auth_creds.example.php` template in the repo - `.gitignore`'s `**/_auth_keys/*.php` rule deliberately blocks any PHP file under `_auth_keys/` from ever being committed, template or otherwise. Instead, create the file yourself on the server:

   ```bash
   touch web/CueRCode.net/_auth_keys/auth_creds.php
   ```

   (The site-specific file is loaded first; `web/_auth_keys/auth_creds.php` - the server-wide equivalent - is used as a fallback if the site-specific one is missing a value.)

   Give it real values in this shape (these are placeholders - never commit real secrets):

   ```php
   <?php
   // web/CueRCode.net/_auth_keys/auth_creds.php - NOT committed to git

   define('DB_HOST', 'localhost');           // required
   define('DB_NAME', 'your_database_name');  // required
   define('DB_USER', 'your_database_user');  // required
   define('DB_PASS', 'your_database_password'); // required
   define('DB_PORT', 3306);                  // optional
   define('DB_CHARSET', 'utf8mb4');          // optional

   // A long random value used to derive the AES-256-GCM encryption key -
   // e.g. generate one with: openssl rand -hex 32
   define('ENCRYPTION_KEY', 'REPLACE_WITH_A_LONG_RANDOM_VALUE');
   ```

4. **🗄️ Run the database installer:**
   Navigate to `https://your-domain/install/` in your browser.

5. **🌍 Point your web server** to `web/CueRCode.net/public_html/` as the document root.

6. **⏱️ Set up the email queue job (once email is configured):** add the DreamHost cron job described under "⏱️ The email queue job (DreamHost cron)" below. Without it, sign-up and password-reset emails are queued but never sent.

### 🧪 Development Environments

| Environment | Directory | Access |
| --- | --- | --- |
| 🟢 Production | `public_html/` | Public |
| 🔬 Beta | `public_html_dev_beta/` | Restricted (`.htaccess` auth) |
| 🧪 Alpha | `public_html_dev_alpha/` | Restricted (`.htaccess` auth) |
| 🚀 Landing | `public_html_landing/` | Public (pre-launch only) |

#### 🌿 Git branch cadence

Development follows a **four-tier promotion cadence**, fed by a single
integration branch:

```
work-in-progress → alpha → beta → release-candidate → main
```

`work-in-progress` is the single long-lived integration branch (cut topic
branches from it; promote with one PR to `alpha`). The four tiers are permanent
and each up-chain promotion is owner-gated. Automated code scanning
(CodeQL/Semgrep/CI), Dependabot version updates, and automatic security-fix
propagation all cover **all four** branches. See DEV_NOTES.md ("Branch Model &
CI/CD Cadence") for the full workflow.

## 📖 API Documentation

- 📚 **Developer Portal**: `/developers/` - quickstart, authentication guide, a live endpoint reference
  rendered directly from `openapi.json` (no hand-copied duplicate), an errors/rate-limit reference, code
  samples (curl/PHP/JavaScript/Python), and integration recipes.
- 🧪 **Interactive Swagger UI**: `/api/docs/` - explore and try out every endpoint against the live spec.
  Loads from jsDelivr (pinned version, integrity-checked) with a committed local fallback copy, so the
  page keeps working even if the CDN can't be reached (#111).
- ✅ **`openapi.json` is drift-free**: every one of the 30 documented paths (28 real `api/v1` routes + 2 SSO browser-redirect routes intentionally included for completeness) matches the router's route table exactly - a router↔spec diff and the API-key scope allowlist are both enforced by an automated drift-guard test (`tests/test_openapi_render.php`) that fails the build the moment either side changes without the other.

### 💡 Quick Example

`/generate` always requires authentication (an `X-API-Key` header, or a session `X-CSRF-Token` for the web
UI's own AJAX calls) - see the [Authentication guide](https://cuercode.net/developers/authentication/) for
how to obtain a key, or the [Quickstart](https://cuercode.net/developers/quickstart/) for a genuinely
anonymous zero-setup path via `/bulk-generate`:

```bash
curl -X POST https://cuercode.net/api/v1/generate \
  -H "Content-Type: application/json" \
  -H "X-API-Key: cuercode_your_api_key_here" \
  -d '{
    "type": "url",
    "input": {"url": "https://example.com"},
    "customization": {"size": 800, "ecc": "H", "format": "png"}
  }'
```

## 📧 Transactional Email (Epic E8)

Registration verification, password resets, and organisation invitations are sent via the **Microsoft Graph API** (client-credentials grant, M365 shared mailbox Send-As) - never PHP's `mail()`. The whole subsystem (`_functions/fn_email.php`) is **inert by default**: the `email_enabled` kill-switch seeds to `0` and every Graph credential seeds empty, so no email can be sent (and no network call is attempted) until an owner completes the M365 app registration and fills in the settings. See [DEV_NOTES.md](DEV_NOTES.md#email-subsystem-microsoft-graph---epic-e8-29) for the full setup runbook. Google Workspace (#30) is a future driver slot behind the same `email_provider` setting.

> ⚠️ **Two things to know, even once M365 is configured and `email_enabled` is on:**
> 1. There is no admin screen for entering the Microsoft credentials (#31) - they currently have to be set from a one-off script (see DEV_NOTES.md).
> 2. Emails are only ever *queued* by the site. They are sent by the email queue runner (#112), which **must be set up as a DreamHost cron job** (below). Without that job, no verification or password-reset email is ever sent, so nobody can finish signing up.

### ⏱️ The email queue job (DreamHost cron)

The queue runner is `web/CueRCode.net/_includes/cron/process_email_queue.php`. It is a command-line script (it refuses web requests) that sends the waiting emails - up to 20 a run - and never sends the same email twice, even if two runs overlap. Add it in the DreamHost panel as a cron job that runs **every minute**:

```bash
/usr/local/php84/bin/php /home/<user>/<path>/CueRCode.net/_includes/cron/process_email_queue.php --quiet
```

- Run it once by hand with `--check` first: it tests the database and the email settings and shows the queue counts, without sending anything.
- `--quiet` means it prints nothing unless something needs attention, so cron only emails you about problems.
- Exit codes: `0` fine · `1` could not start or crashed · `2` bad option · `3` database problem · `4` sending blocked by the settings or Microsoft Graph, or an earlier run looks stuck · `5` gave up on at least one email · `255` PHP itself stopped the run part-way (for example out of memory; the runner prints a note, and any email it was sending is recovered after 10 minutes).
- The PHP path and the DreamHost menu names have **not** been checked against the live server yet - confirm them when setting up. Full steps: [DEV_NOTES.md → "Email queue runner"](DEV_NOTES.md#email-queue-runner-_includescronprocess_email_queuephp-112).

## 🔐 User Accounts (Epic E3 stages 1, 2a & 2b)

## 🔑 Self-Service API Keys & Scopes (Epic E7 stage 1)

Logged-in users can now create, list, rotate, and revoke their own API keys - `POST/GET /api/v1/keys`, `POST /api/v1/keys/{id}/rotate`, `DELETE /api/v1/keys/{id}` - subject to a per-plan `api_keys` cap (`402` at the limit). The full raw key is shown **exactly once**, on create or rotate, and is never retrievable again (only its HMAC-SHA256 hash is stored). Each key can be granted a subset of seven scopes (`generate`, `preview`, `download`, `bulk`, `qrcodes:read`, `qrcodes:write`, `save`) that gate the matching route when the request authenticates via `X-API-Key` - a key missing the required scope gets `403 INSUFFICIENT_SCOPE`. Session/CSRF-authenticated web UI requests are never scope-limited. **Owner/design decision:** key management itself (create/list/rotate/revoke) requires CSRF/session authentication - an API key cannot manage API keys, closing a path where a compromised low-scope key could mint itself a broader-scoped replacement. The account dashboard's new "API keys" section provides the same create/rotate/revoke flow with a one-time-secret copy affordance. See [DEV_NOTES.md](DEV_NOTES.md#self-service-api-keys--scope-enforcement-epic-e7-stage-1-54-2026-07-10) for the full design rationale.

The auth API foundation is now live in code (schema/crypto/password/engine/API), pending live-server verification: `POST /api/v1/auth/register|login|logout|verify-email|request-password-reset|reset-password`. Argon2id password hashing (`PASSWORD_ARGON2ID`), per-record encryption salt for sensitive settings, single-use hashed+expiring tokens, database-backed sessions with session-id rotation on login, account lockout after repeated failed logins, and strict anti-enumeration (register/login/reset-request return identical response shapes regardless of account existence) - see [DEV_NOTES.md](DEV_NOTES.md#authentication-model-epic-e3-stage-1-52111214-2026-07-10) for the full model.

CueRCode's **first ownership-scoped resource surface** also shipped in stage 2a: `POST /api/v1/save` (claim a generated code into your account, subject to a per-plan `saved_codes` limit), `GET /api/v1/qrcodes` (list your own saved codes, paginated), and `GET/PATCH/DELETE /api/v1/qrcodes/{uuid}` (fetch/edit/delete a code you own). Every route re-checks ownership from your authenticated identity on every request - a code that isn't yours (and isn't marked public) is reported as `404`, never `403`, so private UUIDs belonging to other accounts can never be enumerated. See [DEV_NOTES.md](DEV_NOTES.md#saved-codes-api--ownership-model-epic-e3-stage-2a-5224-2026-07-10) for the full ownership model.

**The browser UI now exists too (stage 2b):** `/register/`, `/login/`, `/verify-email/`, `/forgot-password/`, `/reset-password/`, and a full `/account/` dashboard (profile + avatar per the §11.4 priority chain - local upload > Gravatar > default icon, saved-codes library with plan-usage indicator, active-session list + revoke, change-password), plus a navbar sign-in/account-menu indicator on every page. Every form works with JavaScript disabled (a real `<form>` POST handled by the SAME `fn_auth.php` engine the JSON API calls) and is progressively enhanced with AJAX when JS is available. Three new own-account-scoped endpoints back the dashboard - `PATCH /api/v1/account/profile`, `GET/DELETE /api/v1/account/sessions[/{id}]`, `POST /api/v1/account/change-password` - all identity-derived server-side only, all reporting a not-yours target as `404` (never `403`). See [DEV_NOTES.md](DEV_NOTES.md#account-ui--own-account-dashboard-epic-e3-stage-2b-521214232026-07-10) for the full design rationale. **Organisations, WebAuthn, and the admin dashboard are not yet built. Self-service API-key management, single sign-on, and GDPR/CCPA data-subject rights now ARE built - see the sections below.**

### 📦 GDPR/CCPA Data-Subject Rights (#37, epic #52)

The account dashboard's new "Your data" section gives every user two self-service tools: **Download my data** (a plain download link, `GET /api/v1/account/export` under the hood) produces a complete JSON export of your OWN profile, saved QR codes (payload included, never the rendered image), sessions (masked, never a replayable token), subscriptions + payments, linked SSO providers, and API keys - secrets/hashes/tokens are never included. **Delete my account** (`POST /api/v1/account/erase`) requires your current password and a typed `DELETE` confirmation, then **anonymizes** your account rather than hard-deleting it: your email/name/avatar/password are cleared, every session/API key/SSO link is revoked, and every saved code is removed - while subscription and payment records are retained (detached from your identity) for the legally-required financial/audit period. Both endpoints are session-only (an API key cannot export or erase an account, matching every other `account/*` route) and strictly own-account-only (there is no field anywhere in either request that can target a different account). See [DEV_NOTES.md](DEV_NOTES.md#account-erasure-anonymize-now-hard-purge-later-37-epic-52-2026-07-10) for the full anonymize-vs-hard-purge model and the owner-run purge runbook, and [privacy.php](web/CueRCode.net/public_html/privacy.php) Section 11.1 for the public-facing rights disclosure.

## 🔓 Single Sign-On (Epic E2)

"Sign in with Microsoft 365" and "Sign in with Google" are available on the login and register pages - each button is rendered ONLY once an owner has configured and enabled that provider (both are **inert by default**: a master `sso_enabled` kill-switch plus a per-provider `sso_<provider>_enabled` flag, both seeded `0`, and empty client credentials). The underlying client (`_functions/fn_oauth.php`) is a generic OAuth2/OIDC authorization-code flow with mandatory PKCE (S256): `state` + `nonce` are cryptographically random, session-bound, and single-use; the returned `id_token` is cryptographically verified against the provider's own published signing keys (RS256 only - `alg: none`/`HS256` are explicitly rejected) before any claim (issuer, audience, expiry, email) is trusted.

**Account linking is conservative by design** (the anti-account-takeover defence): a brand-new external identity only auto-creates a local account when the provider has verified the email address AND no local account already claims it; if a local account with that email already exists but was never explicitly linked, sign-in is refused and the visitor is guided to log in normally first and link the provider from account settings instead - an external identity is never silently attached to a pre-existing account it doesn't already own. The `/account/` dashboard's new "Linked accounts" section lists every linked provider and lets you link an additional one or unlink an existing one (you can never remove your only sign-in method without first setting a password). There is no profile-picture sync from the provider yet - a linked account's avatar still follows the normal upload/Gravatar/default priority chain, never the provider's own photo. `signula` is present in the provider list but remains inert pending upstream OIDC support in the SIGNula.id project (its own repo currently exposes only a credential-proxy API, not a real identity-provider surface). See [DEV_NOTES.md](DEV_NOTES.md#sso--oidc-client-epic-e2-5519202028-2026-07-10) for the owner IdP-registration runbook (Entra ID + Google Cloud Console app setup, redirect URIs, and where to fill in the client credentials).

## 🔗 Dynamic Redirect + Dual-Function QR (Epic E1a, #27/#71/#33) - built dark, gated

The schema and an owner-facing destination-management API (`GET`/`PATCH /api/v1/qrcodes/{uuid}/destination`) for editable-destination "dynamic" QR codes are built (Stages S0/S1) but **entirely dark** - there is no public `/r/{uuid}` redirect endpoint anywhere in this codebase, the `dynamic_url` QR type is seeded invisible (`isActive=0`), and the master kill-switch seeds off. Going live (Stage S2, the public redirect route) is gated on an explicit owner sign-off (**G-007** - CueRCode becoming a public redirect host is a real reputational/abuse-surface decision) - see [LAUNCH_DECISIONS.md](LAUNCH_DECISIONS.md#h-dynamic--dual-function-qr-gated--issues-27--71--33-gate-g-007) for the full owner-decision checklist and `.dev-team/dynamic_dualfn_design.md` for the complete phased design.

## 🔮 Future Integration

- 🔗 **Go2My.Link**: URL shortener integration for dynamic QR code links
- 🔑 **SIGNula**: Full account-management integration once its own OIDC identity-provider surface ships upstream (SSO sign-in itself is already built for Microsoft 365 + Google - see the section above)

## 👥 Contributing

This project is maintained by MWBM Partners Ltd. See [DEV_NOTES.md](DEV_NOTES.md) for development conventions and guidelines.

AI coding tools working on this repo should start with [`.claude/CLAUDE.md`](.claude/CLAUDE.md) (rules), [`.claude/HANDOFF.md`](.claude/HANDOFF.md) §0 (current position) and, for Codex, [`AGENTS.md`](AGENTS.md) / [`.OpenAI/`](.OpenAI/).

## 🧪 Testing

```bash
php tests/run.php
```

runs the full dependency-free characterization suite - 2466 assertions across 24 test files, no database or Composer required.

There is also an optional real-database check for the email queue runner (`tests/integration/email_queue_real_mysql.php`), run against a throwaway MySQL container - see [tests/README.md](tests/README.md).

## 📄 License

Copyright &copy; 2025-2026 MWBM Partners Ltd. All rights reserved.

Third-party assets and libraries are used under their own licences - see the
[`/attributions`](web/CueRCode.net/public_html/attributions/index.php) page
(also linked from every page's footer) for the full list, including the
Twemoji (CC-BY-4.0) emoji graphics used by the centre-icon picker.

---

*Built with 🐘 PHP, 🗄️ MySQL, 🎨 Bootstrap, and [📦 chillerlan/php-qrcode](https://github.com/chillerlan/php-qrcode)*
