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
| 🖥️ Backend | PHP 8.4, 8.5 or 8.6 (aim: 8.5; see `docs/decisions/0002-php-support-matrix-8-4-to-8-6.md`) |
| 🗄️ Database | MySQL 8.0.13+ or MariaDB 10.6+ via MySQLi (prepared statements only) |
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
│       ├── _libraries/             ← 📚 Third-party libraries (chillerlan, FPDF, tecnickcom - committed, decision 0011)
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

- 🐘 PHP 8.4, 8.5 or 8.6 with these extensions (the installer checks all eight and says in plain words what each is for): `mysqli` (database), `mbstring` (text in any language), `gd` (images), `json`, `openssl` (encryption), `zip` (bulk ZIP downloads), `bcmath` (PDF417 barcodes), `curl` (email, bot checks and sign-in providers). `imagick` is optional (only used for predefined logos on raster output).
- 🗄️ **MySQL 8.0.13 or newer, or MariaDB 10.6 or newer** (decision 0012). The installer refuses an older database with a plain message before it creates anything. Older MySQL 5.7 and MariaDB 10.3 are no longer supported.
- 🌐 Apache with `mod_rewrite` enabled (DreamHost shared hosting has it)
- 📁 Two storage folders that PHP can write to: `_uploads` and `_backups` (next to `public_html`). The deploy creates them; the installer checks they exist and are writable.

### Installation

This is how a fresh copy gets onto DreamHost. **Nothing is cloned on the server:** GitHub Actions uploads the files over SFTP (`.github/workflows/deploy.yml`) whenever the `alpha`, `beta` or `main` branch is pushed, and only when the repository variable `SFTP_ENABLED` is set. The repository secrets `SFTP_*`, `SFTP_REMOTE_BASE` (the server folder that holds `CueRCode.net/`, for example `/home/your-user`) and `SFTP_KNOWN_HOSTS` must be set first (see DEV_NOTES.md).

1. **📤 Get the files onto the server.** Each branch uploads the private folders (`_auth_keys`, `_functions`, `_includes`, `_libraries`, `_uploads`, `_backups`) plus ONE public folder:

   | Branch | Public folder it uploads | Point the matching DreamHost domain at it |
   | --- | --- | --- |
   | `main` | `public_html/` | the live site (production) |
   | `beta` | `public_html_dev_beta/` | the beta site |
   | `alpha` | `public_html_dev_alpha/` | the alpha site |

   In the DreamHost panel (Websites > Manage Websites), set each domain's "web directory" to the matching folder. Which hostname is used for alpha and beta is your choice; this repository does not fix it.

2. **📦 Third-party libraries need no separate step.** The QR, PDF and barcode libraries (chillerlan/php-qrcode 5.0.5, php-settings-container 3.2.1, FPDF 1.86, tc-lib-barcode 2.12.0, tc-lib-color 2.13.1) are committed under `web/CueRCode.net/_libraries/` (decision 0011) and ship with the normal deploy. Their pinned versions are in `_libraries/PINNED_VERSIONS.json`; `php tools/check_vendored_libraries.php` confirms the committed copies match. Bootstrap, jQuery, FontAwesome and Swagger UI fallback copies are committed too, under `public_html/assets/vendor/`.

3. **🔐 Create the credentials file on the server** (it is never in git). A template is committed at `web/CueRCode.net/_auth_keys/auth_creds.example.php.dist`. On the server, in the `_auth_keys` folder:

   ```bash
   cp auth_creds.example.php.dist auth_creds.php
   chmod 640 auth_creds.php
   ```

   Then edit `auth_creds.php` and replace every `REPLACE_ME` (database host, name, user and password, and an `ENCRYPTION_KEY` made with `openssl rand -hex 32`). Keep a safe copy of the key. (The site-specific file is loaded first; `web/_auth_keys/auth_creds.php` - the server-wide equivalent - is the fallback.)

4. **🗄️ Run the database installer.** The folder `public_html/install/` ships with an `.htaccess` that **denies all web access**, on purpose, so nobody can reach the installer by accident. To run it once:
   1. On the server, rename `install/.htaccess` to `install/.htaccess.off` (with your SFTP program or File Manager).
   2. Open `https://your-domain/install/`. Check every line of the prerequisites list is green (PHP version, the eight extensions, the credentials file, `_uploads` and `_backups`), then press **Install Database**. It checks the database version, creates the tables, loads the starting data and applies the numbered database upgrades, then writes a lock file at `_auth_keys/.installed.lock` (outside the `install/` folder) and disables itself. Sites installed earlier have the lock at `install/.installed.lock`, which still counts.
   3. **Close it again straight away:** rename `install/.htaccess.off` back to `install/.htaccess`. **Do not delete the `install/` folder:** the next deploy would upload it again, and an old setup kept the lock inside that folder, so a deleted folder meant an unlocked installer. Keep the folder on the server with its deny-all `.htaccess` in place, and check that `.htaccess` is there after each deploy. The lock is now kept in `_auth_keys/`, which a deploy never overwrites; keep that file too.

   **Upgrading an existing site later:** run `php web/CueRCode.net/_includes/cli/migrate.php --apply` on the server, or use **Database upgrades** in the admin area (`/admin/upgrades/`). See DEV_NOTES.md "Changing the database from now on".

5. **🌍 Check the domain points at the right folder** (step 1) and that `https://your-domain/` loads the home page.

6. **🛡️ Make yourself the first Global Admin:** sign up at `/register/`, then on the server run
   `php web/CueRCode.net/_includes/cli/admin_access.php grant you@example.com --activate`
   in an SSH terminal. It shows when the account was registered, so you can check it is
   yours. Then it asks you to type a **new password** (decision 0010: someone could have
   signed up with your email address before you). Sign in with that new password and open
   `/admin/`. With no SSH at all, DEV_NOTES.md "First Global Admin" has a phpMyAdmin
   **last resort**, which cannot set a safe password, so follow its steps exactly.

7. **🔑 Enter the email secrets, in this order** (sign in as the Global Admin, open `/admin/settings/`, press "Unlock editing" and type your password):
   1. In the **Email (Microsoft 365)** section fill in the tenant ID, the app (client) ID, the app secret value, the sending mailbox and the sender name, then press **Save email settings**. (A secret box is always empty; leave it empty to keep what is saved. Nothing secret is ever shown again.)
   2. Press **Check Microsoft connection**. It must say "Connected" (if not, re-check the three details; the page never shows Microsoft's error text).
   3. Turn on **Send emails**, tick the confirming box and save.
   4. Press **Send me a test email** and check it arrives in your own inbox.
   5. Do step 8 (the cron job) so queued emails are really sent.

8. **⏱️ Set up the email queue job (once email is configured):** add the DreamHost cron job described under "⏱️ The email queue job (DreamHost cron)" below. Without it, sign-up and password-reset emails are queued but never sent.

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
> 1. The Microsoft credentials are entered on the admin settings screen (`/admin/settings/`, Email section) - see "Installation" step 7 below.
> 2. Emails are only ever *queued* by the site. They are sent by the email queue runner (#112), which **must be set up as a DreamHost cron job** (below). Without that job, no verification or password-reset email is ever sent, so nobody can finish signing up.

### ⏱️ The email queue job (DreamHost cron)

The queue runner is `web/CueRCode.net/_includes/cron/process_email_queue.php`. It is a command-line script (it refuses web requests) that sends the waiting emails - up to 20 a run - and never sends the same email twice, even if two runs overlap. Add it in the DreamHost panel as a cron job that runs **every minute**:

```bash
/usr/local/php84/bin/php /home/<user>/<path>/CueRCode.net/_includes/cron/process_email_queue.php --quiet
```

- Run it once by hand with `--check` first: it tests the database and the email settings and shows the queue counts, without sending anything.
- `--quiet` means it prints nothing unless something needs attention, so cron only emails you about problems.
- It also tidies up: one-time links are removed from the database as soon as an email is sent; emails whose link has expired are skipped rather than sent; old email records are deleted after 30 days (given-up ones after 90). This runs even while email sending is switched off.
- Exit codes: `0` fine · `1` could not start or crashed · `2` bad option · `3` database problem, or the clean-up failed (sending not affected) · `4` sending blocked by the settings or Microsoft Graph, or an earlier run looks stuck · `5` gave up on at least one email · `255` PHP itself stopped the run part-way (for example out of memory; the runner prints a note, and any email it was sending is recovered after 10 minutes).
- The PHP path and the DreamHost menu names have **not** been checked against the live server yet - confirm them when setting up. Full steps: [DEV_NOTES.md → "Email queue runner"](DEV_NOTES.md#email-queue-runner-_includescronprocess_email_queuephp-112).

## 🔐 User Accounts (Epic E3 stages 1, 2a & 2b)

## 🔑 Self-Service API Keys & Scopes (Epic E7 stage 1)

Logged-in users can now create, list, rotate, and revoke their own API keys - `POST/GET /api/v1/keys`, `POST /api/v1/keys/{id}/rotate`, `DELETE /api/v1/keys/{id}` - subject to a per-plan `api_keys` cap (`402` at the limit). The full raw key is shown **exactly once**, on create or rotate, and is never retrievable again (only its HMAC-SHA256 hash is stored). Each key can be granted a subset of seven scopes (`generate`, `preview`, `download`, `bulk`, `qrcodes:read`, `qrcodes:write`, `save`) that gate the matching route when the request authenticates via `X-API-Key` - a key missing the required scope gets `403 INSUFFICIENT_SCOPE`. Session/CSRF-authenticated web UI requests are never scope-limited. **Owner/design decision:** key management itself (create/list/rotate/revoke) requires CSRF/session authentication - an API key cannot manage API keys, closing a path where a compromised low-scope key could mint itself a broader-scoped replacement. The account dashboard's new "API keys" section provides the same create/rotate/revoke flow with a one-time-secret copy affordance. See [DEV_NOTES.md](DEV_NOTES.md#self-service-api-keys--scope-enforcement-epic-e7-stage-1-54-2026-07-10) for the full design rationale.

The auth API foundation is now live in code (schema/crypto/password/engine/API), pending live-server verification: `POST /api/v1/auth/register|login|logout|verify-email|request-password-reset|reset-password`. Argon2id password hashing (`PASSWORD_ARGON2ID`), per-record encryption salt for sensitive settings, single-use hashed+expiring tokens, database-backed sessions with session-id rotation on login, account lockout after repeated failed logins, and strict anti-enumeration (register/login/reset-request return identical response shapes regardless of account existence) - see [DEV_NOTES.md](DEV_NOTES.md#authentication-model-epic-e3-stage-1-52111214-2026-07-10) for the full model.

CueRCode's **first ownership-scoped resource surface** also shipped in stage 2a: `POST /api/v1/save` (claim a generated code into your account, subject to a per-plan `saved_codes` limit), `GET /api/v1/qrcodes` (list your own saved codes, paginated), and `GET/PATCH/DELETE /api/v1/qrcodes/{uuid}` (fetch/edit/delete a code you own). Every route re-checks ownership from your authenticated identity on every request - a code that isn't yours (and isn't marked public) is reported as `404`, never `403`, so private UUIDs belonging to other accounts can never be enumerated. See [DEV_NOTES.md](DEV_NOTES.md#saved-codes-api--ownership-model-epic-e3-stage-2a-5224-2026-07-10) for the full ownership model.

**The browser UI now exists too (stage 2b):** `/register/`, `/login/`, `/verify-email/`, `/forgot-password/`, `/reset-password/`, and a full `/account/` dashboard (profile + avatar per the §11.4 priority chain - local upload > Gravatar > default icon, saved-codes library with plan-usage indicator, active-session list + revoke, change-password), plus a navbar sign-in/account-menu indicator on every page. Every form works with JavaScript disabled (a real `<form>` POST handled by the SAME `fn_auth.php` engine the JSON API calls) and is progressively enhanced with AJAX when JS is available. Three new own-account-scoped endpoints back the dashboard - `PATCH /api/v1/account/profile`, `GET/DELETE /api/v1/account/sessions[/{id}]`, `POST /api/v1/account/change-password` - all identity-derived server-side only, all reporting a not-yours target as `404` (never `403`). See [DEV_NOTES.md](DEV_NOTES.md#account-ui--own-account-dashboard-epic-e3-stage-2b-521214232026-07-10) for the full design rationale. **Organisations and WebAuthn are not yet built. The admin area has started (see "Admin area" below). Self-service API-key management, single sign-on, and GDPR/CCPA data-subject rights now ARE built - see the sections below.**

### 📦 GDPR/CCPA Data-Subject Rights (#37, epic #52)

The account dashboard's new "Your data" section gives every user two self-service tools: **Download my data** (a plain download link, `GET /api/v1/account/export` under the hood) produces a complete JSON export of your OWN profile, saved QR codes (payload included, never the rendered image), sessions (masked, never a replayable token), subscriptions + payments, linked SSO providers, and API keys - secrets/hashes/tokens are never included. **Delete my account** (`POST /api/v1/account/erase`) requires your current password and a typed `DELETE` confirmation, then **anonymizes** your account rather than hard-deleting it: your email/name/avatar/password are cleared, every session/API key/SSO link is revoked, and every saved code is removed - while subscription and payment records are retained (detached from your identity) for the legally-required financial/audit period. Both endpoints are session-only (an API key cannot export or erase an account, matching every other `account/*` route) and strictly own-account-only (there is no field anywhere in either request that can target a different account). See [DEV_NOTES.md](DEV_NOTES.md#account-erasure-anonymize-now-hard-purge-later-37-epic-52-2026-07-10) for the full anonymize-vs-hard-purge model and the owner-run purge runbook, and [privacy.php](web/CueRCode.net/public_html/privacy.php) Section 11.1 for the public-facing rights disclosure.

## 🛡️ Admin area (task 7n, #31) - started

A private control panel at `/admin/`, for **Global Admins** only. Today it has an overview (what is and is not set up - secrets are only ever shown as "Set" or "Not set"), a **Confirm password** page and a **Database upgrades** page. Anyone else gets the ordinary "page not found" page. Every change needs the password typed again within the last 15 minutes of activity (60 at most), every change is written to a permanent change log (`tblAdminAuditLog`, never holding secret values), and admin pages are never cached, framed or listed by search engines. There is no admin API. The first Global Admin is made on the server command line (`_includes/cli/admin_access.php`); a Global Admin cannot erase their own account. **Settings** (`/admin/settings/`) is where the Microsoft 365 email details, sign-in keys, bot-protection keys, PayPal keys, email record periods, VAT wording, free-trial settings and admin limits are entered. Secret boxes are always empty and never show a saved value; every change is saved together with its change-log row; the charging switches show "Off (locked)" and cannot be changed. Runbook: [DEV_NOTES.md](DEV_NOTES.md) "Admin area and the first Global Admin".

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

runs the full dependency-free characterization suite - 4046 assertions across 37 test files, no database or Composer required.

There is also an optional real-database check for the email queue runner (`tests/integration/email_queue_real_mysql.php`), run against a throwaway MySQL or MariaDB container - see [tests/README.md](tests/README.md).

## 📄 License

Copyright &copy; 2025-2026 MWBM Partners Ltd. All rights reserved.

Third-party assets and libraries are used under their own licences - see the
[`/attributions`](web/CueRCode.net/public_html/attributions/index.php) page
(also linked from every page's footer) for the full list, including the
Twemoji (CC-BY-4.0) emoji graphics used by the centre-icon picker.

---

*Built with 🐘 PHP, 🗄️ MySQL, 🎨 Bootstrap, and [📦 chillerlan/php-qrcode](https://github.com/chillerlan/php-qrcode)*
