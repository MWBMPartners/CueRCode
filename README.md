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

- 📊 **21+ QR code types**: URLs, text, email, phone, SMS, WhatsApp, Zoom, geolocation, vCard, calendar events, PayPal, Bitcoin, media files, social media, Wi-Fi, and more
- 🎨 **7 output formats**: PNG, JPG, GIF, SVG, WEBP, PDF, JPEG XL
- 🖌️ **Full customisation**: colours, patterns, marker shapes, centre logos, frames with labels, gradients, transparency
- 🔌 **RESTful API**: JSON and XML responses, API key authentication (self-service key create/rotate/revoke + per-key scope enforcement), rate limiting, Swagger/OpenAPI documentation
- 📱 **Responsive Web UI**: Bootstrap 5, dark/light mode, AJAX live preview, WCAG accessibility

## ⚙️ Tech Stack

| Component | Technology |
| --- | --- |
| 🖥️ Backend | PHP 8.5 (backward-compatible with 8.4) |
| 🗄️ Database | MySQL via MySQLi (prepared statements only) |
| 📦 QR Library | [chillerlan/php-qrcode](https://github.com/chillerlan/php-qrcode) v5.x |
| 🎨 Frontend | HTML5, CSS3, Bootstrap 5.3, FontAwesome, jQuery |
| 📖 API Docs | Swagger/OpenAPI 3.0 |

## 📁 Project Structure

```text
CueRCode/
├── .claude/                        ← 🤖 Claude AI context and prompts
├── .openai/                        ← 🤖 OpenAI/ChatGPT context and prompts
├── web/                            ← 🌐 All web-deployable files
│   ├── _auth_keys/                 ← 🔐 Server-wide credentials (outside web root)
│   ├── _includes/                  ← 📦 Server-wide shared includes
│   ├── _functions/                 ← ⚙️ Server-wide shared functions
│   ├── _libraries/                 ← 📚 Server-wide shared libraries
│   ├── _uploads/                   ← 📤 Server-wide uploads
│   ├── _backups/                   ← 💾 Server-wide backups
│   └── CueRCode.net/              ← 🌐 Main CueRCode.net website
│       ├── _auth_keys/             ← 🔐 Site-specific credentials
│       ├── _includes/              ← 📦 Site-specific includes (bootstrap, SQL, templates)
│       ├── _functions/             ← ⚙️ Site-specific functions (QR engine, API handlers)
│       ├── _libraries/             ← 📚 Third-party libraries (chillerlan, FPDF)
│       ├── _uploads/               ← 📤 User uploads (logos, generated QR codes)
│       ├── _backups/               ← 💾 Site backups
│       ├── public_html/            ← 🌍 Production web root
│       ├── public_html_dev_alpha/  ← 🧪 Alpha development environment
│       ├── public_html_dev_beta/   ← 🔬 Beta development environment
│       ├── public_html_landing/    ← 🚀 Pre-launch landing page
│       └── public_html_redir/      ← ↩️ Redirection web root
├── CHANGELOG.md
├── PROJECT_STATUS.md
└── DEV_NOTES.md
```

> 🔒 **Note:** Directories prefixed with `_` (underscore) are private and not web-accessible, secured via `.htaccess` deny-all rules.

## 🚀 Getting Started

### Prerequisites

- 🐘 PHP 8.4 or 8.5 with extensions: `mbstring`, `gd`, `json`, `openssl`, `mysqli`
- 🗄️ MySQL 5.7+ or MariaDB 10.3+
- 🌐 Apache with `mod_rewrite` enabled

### Installation

1. **📥 Clone the repository:**

   ```bash
   git clone https://github.com/MWBMPartners/CueRCode.git
   ```

2. **📦 Download third-party libraries** (not included in repo):
   - [chillerlan/php-qrcode v5.x](https://github.com/chillerlan/php-qrcode/releases) → `web/CueRCode.net/_libraries/chillerlan/php-qrcode/`
   - [chillerlan/php-settings-container v3.x](https://github.com/chillerlan/php-settings-container/releases) → `web/CueRCode.net/_libraries/chillerlan/php-settings-container/`
   - [FPDF v1.86](https://www.fpdf.org/) → `web/CueRCode.net/_libraries/fpdf/`

3. **🔐 Configure credentials:**

   ```bash
   cp web/_auth_keys/auth_creds.example.php web/_auth_keys/auth_creds.php
   cp web/CueRCode.net/_auth_keys/auth_creds.example.php web/CueRCode.net/_auth_keys/auth_creds.php
   ```

   Edit the copied files with your actual database credentials and encryption keys.

4. **🗄️ Run the database installer:**
   Navigate to `https://your-domain/install/` in your browser.

5. **🌍 Point your web server** to `web/CueRCode.net/public_html/` as the document root.

### 🧪 Development Environments

| Environment | Directory | Access |
| --- | --- | --- |
| 🟢 Production | `public_html/` | Public |
| 🔬 Beta | `public_html_dev_beta/` | Restricted (`.htaccess` auth) |
| 🧪 Alpha | `public_html_dev_alpha/` | Restricted (`.htaccess` auth) |
| 🚀 Landing | `public_html_landing/` | Public (pre-launch only) |

## 📖 API Documentation

- 📚 **Developer Portal**: `/developers/` - quickstart, authentication guide, a live endpoint reference
  rendered directly from `openapi.json` (no hand-copied duplicate), an errors/rate-limit reference, code
  samples (curl/PHP/JavaScript/Python), and integration recipes.
- 🧪 **Interactive Swagger UI**: `/api/docs/` - explore and try out every endpoint against the live spec.
- ✅ **`openapi.json` is drift-free (2026-07-10 refresh)**: every one of the 27 documented paths (25 real `api/v1` routes + 2 SSO browser-redirect routes intentionally included for completeness) matches the router's route table exactly - a router↔spec diff and the API-key scope allowlist are both enforced by an automated drift-guard test (`tests/test_openapi_render.php`) that fails the build the moment either side changes without the other.

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

Registration verification, password resets, and organisation invitations are sent via the **Microsoft Graph API** (client-credentials grant, M365 shared mailbox Send-As) - never PHP's `mail()`. The whole subsystem (`_functions/fn_email.php`) is **inert by default**: the `email_enabled` kill-switch seeds to `0` and every Graph credential seeds empty, so no email can be sent (and no network call is attempted) until an owner completes the M365 app registration and fills the settings via the admin UI. See [DEV_NOTES.md](DEV_NOTES.md#email-subsystem-microsoft-graph---epic-e8-29) for the full setup runbook. Google Workspace (#30) is a future driver slot behind the same `email_provider` setting.

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

**Account linking is conservative by design** (the anti-account-takeover defence): a brand-new external identity only auto-creates a local account when the provider has verified the email address AND no local account already claims it; if a local account with that email already exists but was never explicitly linked, sign-in is refused and the visitor is guided to log in normally first and link the provider from account settings instead - an external identity is never silently attached to a pre-existing account it doesn't already own. The `/account/` dashboard's new "Linked accounts" section lists every linked provider and lets you link an additional one or unlink an existing one (you can never remove your only sign-in method without first setting a password). `signula` is present in the provider list but remains inert pending upstream OIDC support in the SIGNula.id project (its own repo currently exposes only a credential-proxy API, not a real identity-provider surface). See [DEV_NOTES.md](DEV_NOTES.md#sso--oidc-client-epic-e2-5519202028-2026-07-10) for the owner IdP-registration runbook (Entra ID + Google Cloud Console app setup, redirect URIs, and where to fill in the client credentials).

## 🔮 Future Integration

- 🔗 **Go2My.Link**: URL shortener integration for dynamic QR code links
- 🔑 **SIGNula**: Full account-management integration once its own OIDC identity-provider surface ships upstream (SSO sign-in itself is already built for Microsoft 365 + Google - see the section above)

## 👥 Contributing

This project is maintained by MWBM Partners Ltd. See [DEV_NOTES.md](DEV_NOTES.md) for development conventions and guidelines.

## 📄 License

Copyright &copy; 2026 MWBM Partners Ltd. All rights reserved.

---

*Built with 🐘 PHP, 🗄️ MySQL, 🎨 Bootstrap, and [📦 chillerlan/php-qrcode](https://github.com/chillerlan/php-qrcode)*
