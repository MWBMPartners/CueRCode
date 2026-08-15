# 🛠️ CueRCode - Developer Notes

**📅 Last Updated:** 2026-08-04 (Four-tier branch cadence + multi-branch security/Dependabot propagation, #64)

---

## 🌿 Branch Model & CI/CD Cadence (2026-08-04, #64)

CueRCode uses a **four-tier promotion cadence** (ascending maturity):

```
work-in-progress  ──►  alpha  ──►  beta  ──►  release-candidate  ──►  main
 (integration)       (leading dev)          (staging)            (production)
```

- **`work-in-progress`** is the **single long-lived integration branch** (renamed
  from the earlier `working`). Cut short-lived topic branches as needed,
  consolidate them into `work-in-progress`, and promote with **one** PR
  `work-in-progress → alpha`. Keep it synced with `alpha` (merge `alpha` in
  before starting new work) so its promotion PRs show only the new diff.
- **`alpha → beta → release-candidate → main`** are the four permanent tiers.
  Each up-chain promotion is **owner-gated**.
- **Scanning** (`ci.yml` php-lint + test suite + gitleaks, `codeql.yml` JS,
  `semgrep.yml` PHP+JS) triggers on push/PR to **all four** tiers. The full
  `ci.yml` suite currently lives richest on `alpha` and propagates down as tiers
  are promoted.
- **Dependabot** (`.github/dependabot.yml`) declares one `target-branch` block
  per tier, so github-actions version-update PRs open against **all four**
  branches (config is read only from the default branch `main`).
- **Security-fix propagation** (`.github/workflows/security-propagate.yml`):
  Dependabot *security* updates only ever target `main`. When a
  security/dependency PR merges into `main`, this workflow cherry-picks it onto
  `alpha`/`beta`/`release-candidate` and opens a PR against each (conflict →
  tracking issue). It only cherry-picks an already-merged `main` commit — no
  untrusted PR code runs — and no-ops cleanly when a tier is already patched.

- **Runners — always `ubuntu-latest`:** every workflow job (including the SFTP
  deploy in `deploy.yml`) runs on **`ubuntu-latest`** — the project standard. Any
  new or edited workflow MUST set `runs-on: ubuntu-latest`; do **not** use macOS,
  Windows, or self-hosted runners without an explicit, documented reason. As of
  2026-08-04 all 8 workflows / 10 jobs comply.

See `.claude/HANDOFF.md` §0 for the current branch tips, the stale-branch
cleanup list, and the outstanding owner actions (protect `release-candidate`,
delete stale branches).

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

### 📊 Non-QR Barcode Generation (#69)

| Library | Version | Source | Purpose |
| ------- | ------- | ------ | ------- |
| 📦 tecnickcom/tc-lib-barcode | v2.12.0 | [GitHub](https://github.com/tecnickcom/tc-lib-barcode) | 1D + 2D non-QR barcode generation (Code128, Code39, EAN-13/8, UPC-A/E, ITF, Codabar, DataMatrix, PDF417, Aztec) |
| 📦 tecnickcom/tc-lib-color | v2.13.1 | [GitHub](https://github.com/tecnickcom/tc-lib-color) | Colour-model dependency of tc-lib-barcode |

License: LGPL-3.0-or-later (server-side SaaS use only, source never distributed to end users - compatible with the house MIT/proprietary posture; distinct from chillerlan's MIT license, noted here for clarity).

⚠️ **`bcmath` PHP extension**: tc-lib-barcode's PDF417 encoder uses its "numeric compaction" text mode for long consecutive-digit runs, which calls `bcmod()`/`bcdiv()`/`bcmul()`/`bcadd()`. Code128, Code39, EAN/UPC/ITF/Codabar, DataMatrix, and Aztec do NOT use `bcmath` anywhere in their encode path (verified by reading the vendored source - the only call sites are `Type/Linear/Imb.php`, not exposed by this integration, and `Type/Square/PdfFourOneSeven/Compaction.php`'s numeric-compaction branch). `bcmath` is a near-universal PHP extension and CI now installs it explicitly (`shivammathur/setup-php` `extensions: ..., bcmath`) - **verify it is enabled on the Dreamhost production PHP build before relying on PDF417 with long numeric payloads**; if it is ever missing, such a request fails CLOSED with a caught, logged, generic `422 GENERATION_FAILED` (fn_barcode_generate.php's `renderBarcodeToOutput()` try/catch) rather than a raw fatal - every OTHER symbology is completely unaffected.

### 🙂 Emoji Centre-Icon Assets (#67)

| Asset set | Version | Source | Purpose |
| ------- | ------- | ------ | ------- |
| 🖼️ Twemoji (jdecked/twemoji) | v17.0.3 (`b6b55fe`) | [GitHub](https://github.com/jdecked/twemoji) | Curated ~400-emoji starter set for the emoji centre-icon picker (SVG + 72×72 PNG) |

License: **CC-BY-4.0** (Creative Commons Attribution 4.0 International) - unlike every other vendored dependency above, this licence LEGALLY REQUIRES attribution wherever the graphics are used. Satisfied via `/attributions` (footer-linked) + an inline credit under the picker itself. Assets are committed as-is (unmodified, a curated subset only) under `web/CueRCode.net/public_html/assets/img/emoji/` - NOT gitignored, unlike the QR/barcode libraries above, since they are small, static, web-servable image files rather than a PHP source tree. `tools/emoji_vendor_version.json` tracks the exact pinned tag/commit for the scheduled drift-check workflow (#68) to compare against.

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

### Authentication Model (Epic E3 stage 1, #52/#11/#12/#14/#15, 2026-07-10)

`_functions/fn_password.php` (Argon2id hashing/policy) + `_functions/fn_auth.php` (the framework-agnostic engine: register/verify/login/logout/session/reset) + `_functions/fn_api_accounts.php` (the HTTP handler layer) implement CueRCode's first real trust boundary. Read all three docblocks first - each is heavily annotated with the design rationale.

**Layering, and why:** `fn_auth.php` takes plain scalars and returns plain result arrays (`['success', 'httpCode', 'errorCode', 'message', 'data']`) - it never reads `$_POST`/`$_SERVER` or calls `jsonResponse()`/`errorResponse()`. This keeps it reusable from BOTH the REST API (`fn_api_accounts.php`, built now) and a future first-party UI (`login.php`/`register.php`/`account/` - deferred to a later E3 stage). `fn_api_accounts.php` is the thin adapter that reads the parsed JSON body, validates field shape/length, enforces CSRF-or-API-key, and calls the engine.

**Argon2id only (F-013).** `fn_password.php`'s `CUERCODE_PASSWORD_ARGON2ID_OPTIONS` constant (`memory_cost=65536` KiB, `time_cost=4`, `threads=2`) is the ONLY place cost parameters are set - never call `password_hash()`/`password_verify()` directly elsewhere. `passwordNeedsRehash()` lets an account transparently upgrade to updated parameters on its next successful login (call it immediately after `verifyPassword()` returns true, hash the SAME plaintext again, persist the new hash - `loginUser()` already does this).

**No user enumeration - a hard requirement, not a style choice.** `registerUser()`, `loginUser()`, and `requestPasswordReset()` ALWAYS return the identical response shape (same HTTP code, same `error_code`, same message) regardless of whether the target account exists, is verified, is locked, or is suspended. `loginUser()` additionally pays the SAME Argon2id verification cost for a non-existent account as for a wrong password (`verifyPassword()` against `cuercodeDummyPasswordHash()`, a precomputed hash of a fixed dummy string) so response TIMING cannot be used to distinguish the two either. The one place a distinction IS surfaced is `verifyEmailToken()`/`resetPasswordWithToken()`'s 409 "already used" vs. 400 "invalid/expired" - this does not leak account existence, since a token is only ever known to whoever received the email in the first place.

**Tokens.** Verification/reset/invitation tokens are `random_bytes(32)` → 64-hex-char raw tokens (`generateRandomToken()`), emailed ONCE, and stored ONLY as their SHA-256 hash (`hashToken()`) in `tblEmailVerifications`/`tblPasswordResets`/`tblInvitations`. Consumption is atomic (`UPDATE ... SET usedAt = NOW() WHERE ... AND usedAt IS NULL`, checked via `affected_rows`) so two concurrent requests for the same token cannot both succeed.

**Sessions - two independent mechanisms.** On login, `issueUserSession()` (1) inserts a `tblUserSessions` row (a random 64-hex `sessionID`, independently revocable - e.g. a future "sign out this device" UI) referenced from `$_SESSION['cuercode_user_session_id']`, AND (2) calls `session_regenerate_id(true)` to rotate the PHP session id itself (session-fixation defence - any pre-login session id an attacker fixed onto the victim's browser stops being valid the moment login succeeds). `currentUser()` looks up the `tblUserSessions` row, checking `revokedAt IS NULL` and `expiresAt > NOW()` and the owning account's `status = 'active'`. `logoutCurrentUser()` is idempotent (200 even with no active session - never leaks session state via a status-code difference) and revokes the row. `resetPasswordWithToken()` additionally revokes ALL of an account's sessions on a successful reset (a possibly-compromised session must not survive a reset meant to lock an attacker out).

**Lockout.** `security.max_login_attempts` (seeded 5) / `security.lockout_duration` (seeded 900s) drive `computeLockoutExpiry()` - a pure function (`fn_auth.php`), fully unit-tested without a database. Only a genuinely wrong password against an ACTIVE, unlocked account increments the counter; probing an already-locked/pending account does not extend its own lockout further.

**CSRF / API-key model for these six routes specifically.** The router's generic `authenticateAPIRequest()`/`'auth'` route flag (used by `/generate`, `/preview`, etc.) always replies 401 on any auth failure and would block a not-yet-authenticated visitor from reaching `/auth/register`/`/auth/login`. The six new routes are therefore registered with `'auth' => false`, and each handler in `fn_api_accounts.php` calls its own `requireCsrfOrApiKey()` - reusing the EXACT SAME `validateCSRFToken()`/`validateAPIKey()` primitives, but replying **403** (not 401) on failure, matching `fn_csrf.php`'s own documented convention for a rejected CSRF token. `'rateLimit' => true` is still set on all six routes, so the existing `tblAPIRateLog`/`checkRateLimit()` per-IP/per-key mechanism applies unchanged.

**Not built this stage (deferred, tracked for the next E3 stage):** account UI pages (`login.php`/`register.php`/`account/`, navbar user indicator), `/save` + `GET/PATCH/DELETE /qrcodes` (list/manage own codes - `requireAuth()` is already the guard those routes will call) - **built in Epic E3 stage 2a, see below** - organisations (creation/roles/invitations UI - schema stubbed now in `tblOrganisationMembers`/`tblInvitations`), WebAuthn/passkeys (G-006 - schema stubbed now in `tblWebAuthnCredentials`), admin dashboard (G-009/#31). `fn_password.php`/`fn_email.php`/`fn_auth.php`/`fn_api_accounts.php` are loaded via the API router's own `$apiFunctionFiles` list (`public_html/api/v1/index.php`), NOT added to `bootstrap.php` - this keeps this stage's blast radius additive (mirrors the Epic E8 precedent of not touching the core request path); once UI pages land, whichever stage builds them should either add these to `bootstrap.php`'s core function list or `require_once` them directly.

> **Update (Epic E3 stage 2b, 2026-07-10):** the UI pages predicted above are now built (see "Account UI & Own-Account Dashboard" below), and the deferred `bootstrap.php` decision was made the way this note anticipated - `fn_password.php`, `fn_email.php`, `fn_auth.php`, and `fn_features.php` are now in `bootstrap.php`'s own core function-file list (loaded on EVERY page, not just the API router), because the navbar user indicator needs `currentUser()`/`resolveAvatarUrl()` on every single page, not only account-specific ones. `fn_api_accounts.php`/`fn_api_qrcodes.php`/`fn_api_account.php` remain API-router-only (or page-specific `require_once`, mirroring `public_html/index.php`'s own QR-function-file convention) since they are JSON-API HTTP handlers, not needed sitewide.

### Saved-Codes API & Ownership Model (Epic E3 stage 2a, #52/#24, 2026-07-10)

`_functions/fn_api_qrcodes.php` is CueRCode's first **ownership-scoped resource surface** - `POST /save`, `GET /qrcodes`, `GET/PATCH/DELETE /qrcodes/{uuid}` - plus a retroactive hardening of the pre-existing `GET /download/{uuid}`. Read the file's own docblock first (it documents the IDOR posture, public-code policy, and soft-delete decision in full); this section records the OWNER-FACING rationale and a few things a future stage needs to know.

**Why ownership didn't exist before this stage.** `tblQRCodes.userID` has existed since Phase 1 ("future-proofed for Phase 2+"), but nothing ever POPULATED it - `saveQRCodeRecord()` (`fn_qr_generate.php`) reads `$_SESSION['userID']`, a key NOTHING in this codebase ever sets (the real auth session key, established by `fn_auth.php`'s `issueUserSession()`, is `$_SESSION['cuercode_user_session_id']` - a deliberately different, namespaced key; see that file's own docblock). So every code generated via `/generate` before this stage has `userID = NULL` regardless of who was logged in. This stage does NOT touch that (out of scope - fixing `saveQRCodeRecord()` to stamp the real `currentUser()` id is a separate, smaller follow-up); instead, `POST /save` is the explicit, intentional mechanism by which a caller CLAIMS an already-generated (anonymous-owned) code into their account. This also explains why `qrCodeIsDownloadableBy()` treats `userID IS NULL` as "downloadable by anyone" - EVERY code generated to date is in that state, and changing that rule would have broken the existing generate→download flow for the entire pre-stage QR history.

**Ownership predicates are the whole security surface.** `qrCodeIsReadableBy()` / `qrCodeIsWritableBy()` / `qrCodeIsDownloadableBy()` (`fn_api_qrcodes.php`) are pure, 2-3-argument functions with no DB/session access - every route (including the hardened download) calls exactly one of them, immediately after a FRESH row fetch, immediately before acting. There is deliberately no shared "is this request authorized" middleware object or cached decision - the three functions ARE the contract, and `tests/test_qrcodes.php` table-tests their full truth tables (including a `ReflectionFunction` check that `qrCodeIsWritableBy()`'s signature has no `isPublic` parameter at all, so a future refactor cannot accidentally wire public-read into write access).

**Why 404, never 403, for "not yours."** A 403 on a private object confirms three things to an attacker: the UUID is syntactically valid, a row exists for it, and it belongs to someone else. All three are the enumeration surface deep-sweep gate G-0xx / SECURITY.md's IDOR audit exists to close. 404 (the same code returned for a genuinely unknown UUID) discloses none of that. The ONE deliberate exception anywhere in this stage is `POST /save`'s conflict path when two requests race to claim the SAME unowned code - that returns `409 SAVE_CONFLICT`, which discloses nothing about ownership (both requests were racing for an UNCLAIMED code).

**Soft delete, not hard delete - the decision, spelled out.** Hard-deleting a `tblQRCodes` row would also need to decide, atomically, what happens to: the file on disk (`qrFilePath`), any `tblActivityLog` rows referencing `qrID`, and a (not-yet-built) analytics/scan-history table (Epic E11) that will eventually FK to `qrID`. Soft delete (`deletedAt`) sidesteps all of that by never actually removing the row - every query in this stage's code filters `deletedAt IS NULL`, so a deleted code is functionally gone from the caller's experience (can't list it, get it, download it, or have it count against their plan limit) while nothing downstream breaks. The accepted cost: disk usage and row count grow monotonically until a future retention-cleanup job (not built this stage - a natural E9/analytics-adjacent follow-up) reaps genuinely old soft-deleted rows. If a future owner decision prefers hard delete (e.g. for storage-cost or GDPR-erasure reasons - see the deferred #37 GDPR export/erasure item), the change is confined to `handleDeleteQRCode()`'s single UPDATE-vs-DELETE-plus-unlink statement; every OTHER read path already treats a soft-deleted row as invisible, so no other file would need to change.

**Public-code policy - opt-in, not opt-out.** A newly-saved code defaults to `isPublic = 0`. The caller must explicitly pass `is_public: true` to widen READ access to any authenticated caller. This mirrors the "private by default" posture the rest of the account system uses (e.g. registration requires explicit email verification before the account can do anything) and avoids a silent-widening footgun where an API client that doesn't know about `is_public` accidentally ships every saved code world-readable.

**PATCH regenerates in place; it does not re-create.** `regenerateQrOutputForPatch()` deliberately duplicates (rather than refactors) `generateQRCode()`'s render/logo/frame steps from `fn_qr_generate.php`, because `generateQRCode()`'s entire contract is "create a NEW row with a NEW uuid" - reusing it for an in-place visual update would either break the saved code's stable `download_url` or require a much larger refactor of an already-audited, heavily-exercised pipeline, neither of which fit this stage's scope. The trade-off (documented in the function's own docblock) is that E5/#49's accessible/tactile/Avery PDF composition step is NOT re-applied on a PATCH regeneration - a PATCH that changes `pdf_layout` to one of those values still renders the STANDARD chillerlan PDF. A future stage that wants full parity should extract `fn_qr_accessible_pdf.php`'s composition call into a shared helper both `generateQRCode()` and `regenerateQrOutputForPatch()` can call.

**Not built this stage:** the "destination" field for a future dynamic-QR type (Epic E1a) is explicitly excluded from `PATCH`'s scope (roadmap: "destination-later"); a retention/cleanup job for old soft-deleted rows; refactoring `saveQRCodeRecord()` to stamp real ownership at `/generate` time (still `$_SESSION['userID']`, still always `NULL` - unaffected by this stage, noted above).

### Account UI & Own-Account Dashboard (Epic E3 stage 2b, #52/#12/#14/#23/#24, 2026-07-10)

The browser UI over stage 1 (accounts) and stage 2a (saved codes) - `public_html/register|login|verify-email|forgot-password|reset-password|account/`, plus three new own-account-scoped API endpoints (`_functions/fn_api_account.php`). Read that file's header docblock first (own-scope IDOR posture); this section records the UI-specific design decisions.

**Why the no-JS fallback calls `fn_auth.php` directly, never the JSON API.** The JSON API router (`public_html/api/v1/index.php`) rejects any non-empty POST body whose `Content-Type` is not `application/json` (F-009) - a genuine, JS-disabled `<form method="POST">` submits `application/x-www-form-urlencoded`, which the router would `415` immediately. `fn_auth.php`'s engine functions (`registerUser()`/`loginUser()`/`verifyEmailToken()`/`requestPasswordReset()`/`resetPasswordWithToken()`) are HTTP-context-agnostic by design (see that file's own docblock) SPECIFICALLY so a UI page can call them directly from its own `$_POST`-reading, `validateCSRFToken($_POST['csrf_token'])`-gating code - this is not a workaround, it is the reason that engine/handler split exists. The JS-enhanced layer (`cuercode-account.js`) instead calls the JSON API with the `X-CSRF-Token` header, for a no-reload experience - both paths call the identical underlying engine function, so there is exactly one code path that actually mutates an account, not two that could drift.

**Why `verify-email` needs an explicit confirm click but `reset-password` does not.** Both pages read a single-use token from `?token=`. A corporate mail gateway / antivirus link scanner routinely GET-prefetches every URL inside an email BEFORE the human recipient opens it. `verify-email`'s token is consumed by TOKEN ALONE (no other user input required) - if the page auto-verified on GET, a scanner's passive prefetch would silently burn the token and the real user would see a confusing "already used" error. `reset-password`'s token is only ever consumed TOGETHER WITH a new password the visitor must actively type into a form and submit - a scanner's bodyless GET can never trigger `resetPasswordWithToken()` at all, so no extra confirm-click precaution is needed there.

**Avatar storage mirrors the `_uploads/qrcodes/` pattern, not a public_html upload.** `_uploads/` is a non-web directory (`Require all denied` in its own `.htaccess`) - exactly like generated QR code images, an uploaded avatar is stored under `_uploads/avatars/{userID}.{ext}` and served through a small, session-cookie-gated streaming endpoint (`public_html/account/avatar/index.php`) rather than a direct link. This was a deliberate consistency choice with this codebase's ALREADY-established user-content security posture (`fn_api_download.php`'s pattern for QR files), not a new pattern invented for avatars. The one deviation: avatars explicitly reject `image/svg+xml` (the QR-logo pipeline's base64 decoder, reused here for validation, does accept SVG) - see `fn_api_account.php`'s header docblock for the specific self-stored-XSS reasoning (a direct, non-`<img>` navigation to an SVG avatar's URL renders it as a full document, executing any embedded script).

**Why `account/avatar/` and `account/qr-preview/` sit OUTSIDE the JSON API router.** Every `'auth' => true` JSON API route requires an `X-CSRF-Token` header (or API key) - a plain `<img src="...">` cannot attach a custom header, so these two read-only image endpoints authenticate purely via the PHP session cookie (`currentUser()`), with no CSRF token at all. This is safe specifically BECAUSE they are side-effect-free GETs of already-own-or-public content: the worst a forged cross-site request could achieve is causing the victim's own browser to re-fetch something it could already display - there is no state change for CSRF to protect against. Both still apply the SAME ownership check the JSON API uses (`qrCodeIsReadableBy()` for saved codes; `currentUser()`'s own resolved `avatarPath` for avatars - never a client-supplied target) and both 404 identically for "does not exist" vs. "not yours."

**Session IDs are shown (masked) to their own owner - the "is this a credential" question, resolved.** `GET /api/v1/account/sessions` returns each session's FULL `sessionID` (needed as the `{id}` for the DELETE route) alongside a masked `masked_id` for on-screen display (`maskTokenForDisplay()`, mirroring `footer.php`'s existing debug-panel masking). This was a deliberate call: nowhere in this codebase does any code path accept a client-supplied `sessionID` as a bearer credential - `currentUser()` ONLY ever reads it from `$_SESSION` (server-side, tied to the PHP session cookie). A leaked `sessionID` value therefore cannot be used to impersonate the account on its own; exposing it to the account's OWN authenticated owner (for the express purpose of letting them identify which "device" to revoke) carries no additional privilege-escalation surface beyond what an already-logged-in owner can already do.

**"Sign out everywhere" is a full-page redirect either way - no AJAX was built for it.** Both the no-JS `<form>` submit and the JS-enhanced path end at the identical outcome (every session revoked, including the current one, redirect to `/login/`) - there is no partial/AJAX version of "log this browser out" that makes sense, so `cuercode-account-dashboard.js` deliberately leaves this one form un-intercepted.

**Bug found by this stage's OWN scratch-DB verification, not by review:** `bootstrap.php` did not load `fn_email.php` globally before this stage - only the API router's `$apiFunctionFiles` list did. `register/index.php`'s and `forgot-password/index.php`'s no-JS handlers call `registerUser()`/`requestPasswordReset()` directly, and both fail OPEN (by design) when `queueEmail()` is undefined - so the no-JS registration path silently created the account but never queued the verification email. Fixed by adding `fn_email.php` to `bootstrap.php`'s global list (see the "Authentication Model" update note above). A live curl-driven no-JS registration, BEFORE and AFTER the fix, is the evidence trail in CHANGELOG.md.

**Not built this stage:** organisations UI (stage 6), WebAuthn/passkeys UI (stage 7), admin dashboard (stage 8, #31), GDPR export/erasure (#37 - see the next section, now built), self-service API-key management (needs E6). The generator's "Save to my account" button always calls a FRESH `POST /generate` before `POST /save` (preview generation never persists a row - `save_file: false` in `handlePreview()`) rather than reusing a cached uuid from the live preview; this mirrors `#btn-download`'s own existing behaviour (every download click already creates a new `tblQRCodes` row today) rather than introducing a new "reuse the last generated uuid" state-tracking concept this stage did not need.

### Account Erasure: Anonymize Now, Hard-Purge Later (#37, epic #52, 2026-07-10)

`POST /api/v1/account/erase` (`eraseUserAccount()`, `_functions/fn_api_account.php`) implements GDPR "right to erasure" / CCPA "right to delete" by **anonymizing the `tblUsers` row in place, never by a hard `DELETE`**. This is a deliberate, two-phase model - read this note before "simplifying" it to a real delete.

**Why anonymize instead of delete outright.** `tblSubscriptions`/`tblPayments` (Epic E4) carry financial/audit obligations that outlive the account relationship - tax records, dispute evidence, fraud-prevention history. `tblPayments.fk_payment_subscription` is deliberately `ON DELETE RESTRICT` (see install.sql's own comment on that table) specifically so a payment record can never silently vanish as a side effect of deleting its parent. If `eraseUserAccount()` hard-deleted the `tblUsers` row, `tblSubscriptions.userID`'s `ON DELETE CASCADE` FK would cascade-delete the subscription too - and that CASCADE would then be BLOCKED by `tblPayments`'s `RESTRICT`, so the whole erasure would fail with a foreign-key constraint violation the moment the account had ever paid for anything. Anonymizing the `tblUsers` row instead of deleting it sidesteps this entirely: the FK stays satisfied (the row still exists), the subscription/payment history stays queryable for legal/audit purposes, and the person's PII is genuinely gone (email/name/avatar/password all cleared or replaced with a non-reversible placeholder) - the row that remains no longer identifies anyone.

**What is anonymized vs. retained, and why each choice:**

| Table | Action | Rationale |
| --- | --- | --- |
| `tblUsers` | ANONYMIZE in place: `email`→`deleted-<userID>@deleted.invalid` (RFC 2606 `.invalid` TLD - never resolvable, never re-registrable, deterministic per userID for a future purge job to find), `displayName`→`'Deleted user'`, `firstName`/`lastName`/`avatarPath`→`NULL` (avatar FILE also deleted from disk), `passwordHash`→`NULL` (login becomes permanently impossible), `status`→`'deleted'`, `erasedAt`→`NOW()` | The row must survive (see FK rationale above) but must no longer identify or authenticate anyone |
| `tblUserSessions` | Revoke ALL (`revokedAt = NOW()`) | Same reversible mechanism `logoutCurrentUser()`/`revokeUserSession()` already use elsewhere - no new state model introduced |
| `tblAPIKeys` | Deactivate ALL (`isActive = 0`) | Matches `revokeApiKeyForUser()`'s own soft-revoke convention exactly - a future admin audit can still see how many keys the account had |
| `tblUserOAuth` | `DELETE` all rows | These rows carry no independent audit value once the local account they point at is anonymized - nothing legitimate references them afterwards |
| `tblQRCodes` | Soft-delete ALL (`deletedAt = NOW()`) | The SAME mechanism `DELETE /api/v1/qrcodes/{uuid}` already uses - no new state model |
| `tblSubscriptions` / `tblPayments` | **UNTOUCHED** | Financial/audit retention requirement (see above) - these rows carry no PII of their own beyond the (now-anonymized) `userID` FK, so nothing further needs to change on them |
| `tblActivityLog` | New `account_erased`/`account_data_exported` rows, PII-FREE | `logActivity()` is called with `entityID = userID` only - never the email/display name that was just cleared, so the audit trail itself never leaks the erased PII |

Every mutating statement above runs inside ONE MySQL transaction (`getDB()->begin_transaction()`/`commit()`/`rollback()`) - a partial failure rolls back everything, never leaving a half-anonymized account. The anonymizing `UPDATE`'s `WHERE` clause re-checks `status != 'deleted'` in the SAME statement (a concurrent double-erase attempt affects 0 rows and is reported `409 ALREADY_ERASED`, not silently re-run or thrown).

**Owner-run hard-purge runbook (NOT automated - a deliberate, separate, manual step).** This codebase does not schedule or auto-run a job that permanently deletes anonymized rows - per the Dreamhost shared-hosting "no CLI/cron guarantee" constraint already documented under "Email Subsystem (Microsoft Graph)" above, and because an irreversible bulk-delete of financial-adjacent rows deserves a conscious, logged, owner-initiated action rather than a silent background timer. Once a confirmed retention window has elapsed (**owner/counsel to set the exact number of days - see privacy.php Section 7.1's `[TEMPLATE]` marker**), the owner runs the following via the Dreamhost panel's phpMyAdmin (or an equivalent authenticated MySQL client - never exposed as a public endpoint):

```sql
-- 1. Preview which rows a purge would affect (READ ONLY - run this first)
SELECT userID, email, erasedAt
FROM tblUsers
WHERE status = 'deleted'
  AND erasedAt < DATE_SUB(NOW(), INTERVAL 90 DAY); -- adjust the retention window to the confirmed policy

-- 2. Only after reviewing the preview above, and only for rows with NO
--    remaining subscription/payment history that still needs the userID FK
--    (LEFT JOIN confirms this), permanently remove the anonymized row.
--    tblSubscriptions.userID is ON DELETE CASCADE, so if a purged account DID
--    have subscriptions, review those independently first - most anonymized
--    accounts erased under this endpoint will have none.
DELETE u FROM tblUsers u
LEFT JOIN tblSubscriptions s ON s.userID = u.userID
WHERE u.status = 'deleted'
  AND u.erasedAt < DATE_SUB(NOW(), INTERVAL 90 DAY)
  AND s.subscriptionID IS NULL;
```

This is intentionally a runbook, not code, for this stage - if a future stage automates it (e.g. a panel-cron-triggered maintenance endpoint, mirroring the `fn_email.php` queue-processor's own "opportunistic on request-shutdown + documented panel-cron alternative" pattern), it must reuse this exact eligibility predicate (`status = 'deleted' AND erasedAt < <retention window> AND no remaining tblSubscriptions/tblPayments dependency`) and must itself be logged to `tblActivityLog` before any row is removed.

**Why the UI requires a TYPED confirmation phrase on top of the password.** `eraseUserAccount()`'s own contract only requires `confirm: true` (a single boolean) - the account dashboard's no-JS `<form>` additionally requires ticking an "I understand this is irreversible" checkbox AND typing the literal word `DELETE` (case-sensitive, enforced BOTH client-side via the `pattern="DELETE"` HTML5 attribute on a `required` input, which blocks submission even with JavaScript disabled, AND server-side in `public_html/account/index.php`'s own POST handler before `eraseUserAccount()` is ever called) - the SAME "UI adds its own extra friction on top of the engine's minimal contract" pattern the account page already uses for "new passwords match" before calling `changeUserPassword()`. The JSON API's documented contract deliberately stays password + `confirm: true` only - the typed-phrase friction is a UI/UX safety net for a human clicking through a web page, not a security control the API needs to additionally enforce for a programmatic caller who already had to supply the correct password.

**Why `GET /account/export/` exists as a second, cookie-only route alongside the CSRF-gated `GET /api/v1/account/export`.** Exactly the same rationale as `account/avatar/`/`account/qr-preview/` (see the note above): a plain `<a href>` "Download my data" link cannot attach the `X-CSRF-Token` header the JSON API's session-only auth model requires, and a data EXPORT (a pure read, no state change) does not need CSRF protection for the same reason those two endpoints don't - the worst a forged cross-site request could achieve is causing the victim's own already-authenticated browser to download something it could already see. Both routes call the identical `collectAccountExportData()` engine function - there is exactly one place that decides what an export contains.

### Per-Record Encryption Salt (F-006/G-010/DS-7, Epic E3 stage 1, 2026-07-10)

`fn_encryption.php`'s `encryptValue()`/`decryptValue()` no longer rely on a single global `ENCRYPTION_SALT` constant shared by every encrypted value in the database. Every call to `encryptValue()` that does not supply an explicit `$salt` now generates a fresh, cryptographically random 16-byte salt for THAT VALUE ONLY (`random_bytes(16)`), derives an independent AES-256 key via HKDF from it, and embeds the salt directly in the returned ciphertext blob (packed format: `[salt length (1 byte)][salt][IV (12 bytes)][GCM tag (16 bytes)][ciphertext]`, base64-encoded) - this makes `decryptValue()` fully self-describing from the master key alone, with no separate salt to track or pass around. An explicit `$salt` argument is still honoured verbatim (any length) for deterministic/reproducible testing.

An optional 4th by-reference parameter, `encryptValue($plaintext, $key, $salt, &$usedSaltOut)`, lets a caller capture the salt that was generated. `fn_config.php`'s `setConfig()` uses this to ALSO mirror the salt into a new `tblSettings.encryptionSalt BINARY(16) NULL` column, purely for DBA-visible auditability that per-record salting is genuinely happening (it is not read back by `getConfig()` - the embedded, self-describing blob is authoritative for decryption). Since all `isSensitive` seed values ship EMPTY, no re-encryption of real data was needed for this change.

`ENCRYPTION_SALT` remains definable in `_auth_keys/auth_creds.php` (both `auth_creds.example.php` templates still document it) for backward-compatibility of the constant's existence, but is no longer consulted by the default (no-explicit-salt) code path - it is effectively legacy/vestigial. Verified via a scratch-DB round trip: two different `isSensitive` settings produced two different 16-byte salts and two different ciphertexts for otherwise-similar plaintext.

### Braille Dot-Geometry Approach & Grade-1 Scope (#49, 2026-07-10)

`fn_qr_accessible_pdf.php` draws Braille as **geometric dot patterns**, not a Unicode Braille font. Two reasons this is not optional:

1. **Core FPDF fonts have no Braille glyphs.** The library only ships Courier/Helvetica/Times/Symbol/ZapfDingbats (single-byte WinAnsi/CP1252 core fonts) — embedding a Unicode Braille TTF is out of scope for this feature.
2. **Embossing needs real dot geometry, not character rendering.** A swell-paper/embosser pass reproduces raised dots from actual coordinates, not from however a font renderer happens to draw a Unicode Braille codepoint glyph.

**How the dots are drawn:** core FPDF has no native circle/ellipse operator (only straight-edged `Rect()`/`Line()`), and reaching into FPDF's protected `_out()` method to draw true Bézier circles would require subclassing `\FPDF` — which introduces a load-order precondition (the FPDF library must be `require`d before the subclass is *declared*, not merely before it's *called*, since `class X extends Y` resolves `Y` immediately). Instead, `drawFilledCircleOnPdf()` approximates a filled circle as a small stack of full-width `Rect()` "strips" whose half-width follows the circle equation (`circleStripHalfWidthMm()`, a pure/unit-tested helper: `sqrt(r² - dy²)`). At the ~1.5mm dot size Braille actually needs, this reads as a clean round dot on any real printer/embosser, and it needs nothing beyond `\FPDF`'s public API — no subclass, no load-order precondition beyond the type-hint being resolvable *at call time* (which production's `bootstrap.php` always guarantees).

**Dot geometry constants** (approximate "Marburg Medium" print/embossing figures, commented at the top of `fn_qr_accessible_pdf.php`): dot ⌀ ~1.5mm, intra-cell dot pitch ~2.5mm, inter-cell (character) pitch ~6.0mm, line pitch ~10mm. These are widely-cited approximate figures, **not an embosser-certified spec** — real hardware (BANA "standard"/"jumbo" cells, etc.) varies slightly. Always print/emboss at 100% scale.

**Grade-1 (uncontracted) scope only.** Implemented: a-z, 0-9 (behind the Braille number sign, dots 3-4-5-6, one per digit *run* not per digit), a single-letter capital indicator (dot 6), space, and a small common-punctuation set (period/comma/semicolon/colon/question/exclamation/hyphen/apostrophe). **Grade-2 (contracted) Braille is explicitly out of scope** — whole-word/part-word contractions (e.g. a single cell standing in for "the") need a large, locale-specific contraction dictionary and disambiguation logic that a short QR caption does not warrant. Any character outside the implemented subset renders as a documented blank cell (`brailleTransliterateGrade1()`) rather than being silently dropped or mis-transliterated, keeping caption↔cell alignment predictable.

**Tactile locator frame is an original design**, not a reproduction of Chulalongkorn University's Thai petty patent No. 17871 (Thailand-only, and the roadmap directive was to design a distinct variant regardless of jurisdiction). See `drawTactileLocatorFrame()`'s docblock for the exact geometry (continuous frame + 4 corner "L" marks + 1 bottom orientation dot).

### Bulk-Pipeline Accessibility Parity (#57, 2026-07-18)

`fn_api_bulk.php` now applies `applyAccessiblePreset()` (fn_qr_options.php) **per item** in both `handleBulkGenerate()`'s ZIP loop and `handleBulkAverySheet()`'s Avery-fork loop, closing the gap flagged (and deliberately left open) when #48 shipped. Two things worth knowing if you touch this code:

- **Manifest `accessible:` detail-line format.** A successful accessible item's `[NNN] OK type=... file=...` manifest line is immediately followed by a 6-space-indented detail line reading `accessible: contrast=X:1 (level)`, with `recommended_print_size_mm=N` appended (2-space-separated) only when that item also supplied `scan_distance_cm`. This line NEVER appears for `preset=none`/absent items (`applyAccessiblePreset()`'s no-op guarantee) and never follows a FAILED line (a sub-3:1 item never reaches the OK line at all — it `continue`s to a `[NNN] FAILED ... reason=Accessible preset rejected: contrast ratio X:1 is below the WCAG minimum of 3:1.` line instead, contributing no ZIP entry). Real captured values: default black-on-white → `contrast=21:1 (pass)`; `#777777` on `#888888` → `1.26:1` (below the 3:1 floor). Note the ratio is `(string)`-cast from a `round(..., 2)` float, so a whole number like `21.0` prints as `21`, not `21.0` — don't hardcode a trailing `.0` in any future assertion here.
- **Per-item failure, not a batch 422 — deliberate, not an oversight.** `/generate` rejects a sub-3:1 pair with a single `422 ACCESSIBLE_CONTRAST_TOO_LOW`. Bulk does NOT replicate that HTTP-level rejection: a failing item is a manifest FAILED line (ZIP pipeline) or a blank label slot (Avery fork) — the pre-existing "individual failures never abort the batch" invariant wins, and the pre-existing `422 BULK_AVERY_ALL_FAILED` (Avery, all-items-failed) is the only new-request-level status this touches, unchanged.
- **CSV boolean cell semantics** (`bulkCsvCoerceBoolean()`, `fn_api_bulk.php`) - the two boolean reserved CSV columns, `transparent` and `quiet_zone`, are coerced case-insensitively: `false`/`0`/`no`/`off` → `false`; anything else non-empty (including `true`/`1`/`yes`/`on`) → `true`. This exists because a bare `(bool)` cast of the raw CSV string `"false"` evaluates to PHP `true` (only `''` and `'0'` are falsy strings) — `buildCustomizationFromRequest()` downstream still does that bare cast, so the coercion has to happen in `parseBulkCsv()` before the value ever reaches it. The CSV reserved-column list (`CUERCODE_API_BULK_CSV_RESERVED`) is now the full 20-key set matching every key `buildCustomizationFromRequest()` recognises (previously missing `marker_border`/`marker_center`/`quiet_zone`/`version`/`preset`/`scan_distance_cm`/`pdf_layout` — a CSV-only caller had no route to the accessible preset or any PDF layout at all).
- **Testable body injection**: `handleBulkGenerate(array $auth, ?array $requestData = null)` — the second parameter exists purely so tests can drive the function end-to-end without a live HTTP server (`php://input` is a read-once stream, unusable from CLI test code). `null` (the router's unchanged call site) preserves the original php://input-reading behaviour exactly.

### Non-QR Barcode API (#69, 2026-07-22)

`POST /api/v1/barcode` + `POST /api/v1/barcode/preview` generate NON-QR barcode symbologies via the vendored `tecnickcom/tc-lib-barcode` library, alongside (not inside) the existing chillerlan/php-qrcode QR pipeline. A few design decisions worth knowing if you touch this feature:

- **Library choice: tc-lib-barcode, not a fallback.** The STEP 1 validation probe (Code128 + DataMatrix, SVG+PNG, `php -d error_reporting=E_ALL -d display_errors=1`) produced ZERO deprecations/errors on PHP 8.4.19, so the picqer/php-barcode-generator (1D-only) fallback path was never needed — this integration covers the FULL 11-symbology set (8×1D + 3×2D) with one library. See `fn_barcode_generate.php`'s file header for the exhaustive symbology list and the exact `Barcode::BARCODETYPES` type strings used.
- **Separate endpoints, not an overload of `/generate`.** Most QR customisation (colours, module/marker shapes, logos, frames) does not apply to a barcode symbol, and `/generate`/`/preview` are heavily audited surfaces — a distinct pair of routes (`handleBarcodeGenerate()`/`handleBarcodePreview()`, `fn_api_generate.php`) keeps that pipeline byte-for-byte untouched. Both new handlers ARE added to `fn_api_generate.php` itself (not a new `fn_api_barcode.php` file) specifically so the `tests/test_error_catalogue.php` 13-file scanned allowlist needs zero changes — the file was already in it.
- **Reuses the `generate`/`preview` API-key scopes**, not new ones — barcode generation is the same CAPABILITY as QR generation from a scope-taxonomy point of view. This keeps `fn_api_keys.php`'s `CUERCODE_API_KEY_SCOPE_ALLOWLIST` at exactly 7 entries (a `test_openapi_render.php` drift guard asserts this count) — do NOT add `barcode`/`barcode:preview` scopes without also reviewing/updating that guard.
- **Zero new `error_code`s.** `handleBarcodeGenerate()`/`handleBarcodePreview()` emit only `MISSING_FIELD`, `FIELD_TOO_LONG`, `SERVICE_UNAVAILABLE`, and `GENERATION_FAILED` — all pre-existing, already-documented codes, mirroring exactly how `buildPayload_gs1_product()`'s GTIN validation failures surface as a generic `422 GENERATION_FAILED` rather than a bespoke `INVALID_GTIN` code. This means `openapi.json`'s `x-error-codes` catalogue and `tests/test_error_catalogue.php`'s entry-count assertion needed ZERO changes for this feature — only the two new `paths` entries + 4 new `components.schemas` entries + the additive `PaymentRequired.feature` property were added.
- **Tier gating: `barcode_generation` featuresJSON key, seeded `true` on ALL FOUR plans** (anonymous/free/pro/business, `seed_data.sql`) — this is a framework-readiness gate (proves `requiredFeature` on the router actually works end-to-end, its first live use since the E4 framework stage) rather than a live restriction. **Owner decision needed**: whether/how to split barcode access by tier in the future (e.g. limit anonymous callers to 1D-only, or cap symbologies-per-day) is intentionally left open — flip individual plans' `barcode_generation` to `false` and/or add a new `limitsJSON` key (e.g. `barcode_formats_allowed`) whenever that decision is made; zero code change either way, per the existing tier-gating engine's design.
- **STRICT validation lives in `fn_barcode_generate.php`, not the library.** tc-lib-barcode itself is LENIENT — it silently zero-pads and recomputes malformed EAN/UPC input rather than rejecting it (verified empirically while vendoring: `getBarcodeObj('EAN13', '40063813339', ...)` — an 11-digit input — pads and encodes rather than erroring). `normalizeBarcodeData()`/`normalizeGtinLikeData()`/`gtinCheckDigit()`/`upcEExpandToUpcA11()` are the ONLY thing standing between a malformed request and a barcode that LOOKS valid but decodes to the wrong product/value. The universal GTIN check-digit algorithm (reverse the digits, alternate weight 3/1 from the rightmost) is verified against the same weighting for EAN-8/UPC-A/EAN-13 alike; UPC-E expansion is verified against a real, publicly documented UPC-A/UPC-E pair (Cheerios cereal: `042100005264` ⇄ `04252614`) in `tests/test_barcode.php`, not merely self-consistency.
- **`bcmath` / PDF417** — see the Third-Party Libraries table above for the full caveat. Every symbology OTHER than PDF417's numeric-compaction path is completely unaffected by `bcmath`'s presence or absence.
- **`show_text` (HRI caption) is hand-rolled, not a library feature.** tc-lib-barcode's SVG/PNG renderers draw bars/modules only — no human-readable-interpretation text. `wrapBarcodeSvgWithText()` nests the library's own `<svg>` output inside a taller wrapper `<svg>` with an appended `<text>` element (SVG natively supports nested `<svg>` elements, so the inner markup's own geometry never needs parsing/rewriting); `appendBarcodeTextToPng()` uses GD's built-in bitmap font (`imagestring()`, font index 3) — no TrueType dependency, unlike `fn_qr_frames.php`'s more elaborate TTF-first frame-label renderer (a barcode caption is a small, secondary annotation; the built-in font is adequate). Both fail OPEN (return the unmodified barcode) on any rendering error, mirroring the QR pipeline's logo-overlay failure posture — a caption is a nice-to-have, never worth failing the whole request over.
- **2D follow-up: none needed.** Unlike a picqer fallback scenario (which would have been 1D-only, deferring DataMatrix/PDF417/Aztec), tc-lib-barcode covers the full 2D set from day one — there is no "2D follow-up" backlog item for this feature.

### Emoji Centre-Icon Picker + Ingest Watch (#67/#68, 2026-07-22)

Native, vector-first emoji centre-icon picker (`emoji:<codepoint-seq>` logo source) + the repo's first scheduled GitHub Action to watch for upstream drift. Design decisions worth knowing if you touch this feature:

- **Same-directory, same-basename SVG+PNG twins — reuses `loadLogoImage()`'s Issue #65 PNG-twin lookup UNCHANGED.** Every vendored emoji ships as `<seq>.svg` + `<seq>.png` living in the SAME flat directory (`public_html/assets/img/emoji/`), e.g. `1f600.svg` + `1f600.png`. `resolveEmojiLogoPath()` only ever RETURNS the `.svg` path; `loadLogoImage()` already looks for a same-basename `.png` twin next to any `.svg` it is handed (the #65 fix for predefined logos) and transparently substitutes it for raster (PNG/JPG/GIF/WEBP) QR output. Net effect: `overlayLogoOnRaster()`/`overlayLogoOnSVG()`/`loadLogoImage()` needed **zero code changes** for this feature — only the new path-resolution layer (`getEmojiLogoPath()`/`resolveEmojiLogoPath()`, `fn_qr_logo.php`) is new. This is a deliberate DEVIATION from the original issue #67 scope note (which sketched separate `{svg,72}/` subdirectories) once the #65 mechanism was re-read closely during implementation — the flat/twin layout gets the exact same raster+vector coverage for less code.
- **Twemoji, not Noto — the PNG side solves GD's "cannot rasterise SVG" limitation with zero server work.** `jdecked/twemoji` (the community-maintained continuation of Twitter's original open-source emoji set) ships BOTH per-codepoint SVGs and pre-rendered 72×72 PNGs upstream — unlike Noto Emoji (SVG-only, would need a build-time rasteriser this sandboxed dev/CI environment does not have, mirroring the exact `tools/render_logo_pngs.php`/Imagick gap documented for predefined logos). Vendored at tag `v17.0.3` (commit `b6b55fe`), tracked in `tools/emoji_vendor_version.json`.
- **Curated STARTER set (~400 of ~4,000 upstream emoji), not the full catalogue.** `tools/emoji_curated_map.php` hand-curates a name/group/keywords entry for each vendored emoji, spanning 9 categories (Smileys & Emotion, People & Body, Animals & Nature, Food & Drink, Travel & Places, Activities, Objects, Symbols, Flags) and deliberately including every QR-type use-case the owner named (📍 location, ✉️ email, ☎️/📞 phone, 💬 message, 🔗 link, 📅 calendar, 🎵 music, 📷 camera, ⭐ rating). Expanding the set later is a pure asset-vendoring + curation exercise (copy the SVG+PNG twin, add a `tools/emoji_curated_map.php` entry, re-run `tools/emoji_index.php`) — no code change.
- **`emoji-test.txt` was UNREACHABLE from this session's sandbox (proxy blocks `unicode.org`, verified `403`/`connect_rejected`) — the documented fallback path is what actually shipped.** `tools/emoji_index.php` tries Unicode's `emoji-test.txt` FIRST (the authoritative name/group source) and falls back to `tools/emoji_curated_map.php` on any fetch failure, never blocking or failing the build either way. **On a real CI runner (no such proxy restriction) the fetch is expected to succeed**, giving the index fully Unicode-authoritative names/groups the next time it regenerates there — this is a genuine environment difference, not a design compromise; verify by running `php tools/emoji_index.php` (no `--no-fetch`) in CI and checking the output's `"source"` field says `unicode-emoji-test.txt + ...` rather than the fallback string.
- **Filesystem-indexed, NOT `tblPredefinedLogos` rows.** `emoji-index.json` (generated, committed) is the sole source of truth for the picker UI and `getLogoPath()`'s resolution — no database involvement anywhere in the emoji path, keeping `tblPredefinedLogos` a curated BRAND list (26 rows) rather than growing to thousands of filesystem-indexed rows.
- **No upload, no SVG active-content screening for emoji.** Unlike user-supplied logos (`screenSvgLogoContent()`/F-024), emoji assets are exclusively repo-vendored — `getEmojiLogoPath()`'s STRICT `^[0-9a-f]{2,6}(-[0-9a-f]{2,6}){0,6}$` regex + `resolveEmojiLogoPath()`'s realpath-under-base containment guard (the SAME `isPathWithinAllowedBase()` primitive every other logo-path resolver in `fn_qr_logo.php` uses) are the only gates — a malformed/unknown sequence resolves to `null` (silent no-overlay, QR still generates) rather than a 422, matching the existing "unresolvable logo" fail-open convention.
- **Emoji-ingest workflow opens a diff-report ISSUE, never a bot PR** (`.github/workflows/emoji-ingest.yml`) — the 2026-07-18 working agreement (see `.claude/HANDOFF.md`) has agents/automation not opening PRs; issue #68 explicitly left the PR-vs-issue choice open for a scheduled Action and this session defaulted to the issue mode. It compares `tools/emoji_vendor_version.json`'s pinned tag against the latest upstream release tag, diffs the two tags' `assets/svg/` trees via the GitHub Git Trees API (no full clone) to list genuinely NEW codepoint sequences, and de-duplicates against any already-open report. **The owner can opt into PR mode later** by replacing the final `gh issue create` step with a commit+`gh pr create` sequence — the drift-detection/delta-computation logic upstream of that step needs no change.
- **CC-BY-4.0 attribution satisfied two ways**: a dedicated `/attributions` page (footer-linked from every page) plus an inline credit directly under the picker grid in the Logo tab — belt-and-braces, since the licence requires attribution wherever the graphics are actually used, not merely somewhere on the site.

### API Error-Code Catalogue Drift Guard — Token Scanner, Not Regex (#58, 2026-07-18)

`tests/test_error_catalogue.php` statically re-derives the REAL set of error codes the API emits by scanning the 13 production files a request can actually reach, and diffs that set (both directions) against `openapi.json`'s new `ErrorResponse.x-error-codes` array. If you ever touch this test - or write a similar static-scan drift guard elsewhere - the one rule worth internalising:

- **Use `token_get_all()`, never a delimiter-bounded regex, to find a literal argument inside a function call whose OTHER arguments are free-form human text.** The obvious-looking regex `/errorResponse\(\s*'[^;]+',\s*\d+,\s*'([A-Z_]+)'/` (bounding the first argument on "no semicolon before the next comma") looks reasonable right up until a real error MESSAGE contains a semicolon of its own - which it does, for real, today: `fn_api_account.php:895` reads `'Account management requires an interactive session; API keys cannot manage the account.'` as `errorResponse()`'s first argument. A `[^;]`-bounded regex stops dead at that semicolon and never reaches the real `403 ACCOUNT_MANAGEMENT_FORBIDDEN` third argument a few lines later - a silent under-count, not a crash, which is the worst kind of scanner bug (it looks like it worked). `errcatExtractLiteralThirdArgCodes()` instead tokenizes the file with `token_get_all()` and walks the argument list at real parenthesis depth, splitting on TOP-LEVEL commas only (depth === 1) - a semicolon (or a comma, or a nested function call) inside a `T_CONSTANT_ENCAPSED_STRING` token is just string content to a tokenizer, never mistaken for a boundary. The other four emission idioms this file scans (`'errorCode' => 'X'`, `'code' => 'X'`, `$errorCode = 'X'`/`$authErrorCode = 'X'`, `'error_code' => 'X'`) are all simple `key => literal` / `var = literal` shapes with no free-form text anywhere near the literal, so a plain regex over `php_strip_whitespace()` output (comments stripped, string literals intact) is simpler and perfectly safe there - the lesson is specific to "a regex boundary character that can also appear inside adjacent free-form text", not a blanket "always tokenize" rule.
- **Static scan, never `include()`/`require()`.** Every scanned file is read as TEXT (`file_get_contents()` / `token_get_all()` / `php_strip_whitespace()`), never executed - several (the router especially) have real side effects (CORS headers, session touches, `dbQuery()` calls) the instant they run, which would either fatal under the CLI test harness (no DB) or corrupt later tests' state.
- **The negative-proof convention applies here too.** Before shipping, one documented code (`WEAK_PASSWORD`) was deliberately deleted from `x-error-codes`, the suite re-run to confirm the guard failed with the expected `DRIFT GUARD: ... (emitted-but-undocumented: WEAK_PASSWORD)` message, then restored and re-verified green - the standard "prove the guard can actually fail" check this repo applies to every new drift guard (see also `test_openapi_render.php`'s router/scope guards).

### Premium Tier & Feature Gating Engine (Epic E4 framework stage, #25/#26/#53, 2026-07-10)

**OWNER REQUIREMENT this whole subsystem exists to satisfy:** the tier system is FULLY EXTENSIBLE and DATA-DRIVEN. Plans, feature assignments, limits, and price points live entirely in `tblSubscriptionPlans` JSON columns, admin-editable (via SQL/phpMyAdmin today; the E3-admin-stage plan editor later) - **adding a new tier, moving a feature between tiers, or changing a price never requires a code change or a deploy.**

#### How to add or modify a premium tier

To **add a brand-new tier** (e.g. a 5th "enterprise" plan), run one INSERT:

```sql
INSERT INTO `tblSubscriptionPlans`
    (`planKey`, `planName`, `priceMonthly`, `priceYearly`, `currency`, `featuresJSON`, `limitsJSON`, `sortOrder`)
VALUES (
    'enterprise', 'Enterprise', 199.99, 1999.99, 'GBP',
    '{"expiry_scheduling":true,"password_protected_qr":true,"sso":true,"organisations":true,"webhooks":true,"csv_export":true}',
    '{"saved_codes":-1,"bulk_batch_size":9999,"dynamic_codes":-1,"api_keys":100,"analytics_days":-1,"api_rate_limit_per_min":5000,"api_rate_limit_per_day":1000000}',
    4
);
```

The moment a `tblSubscriptions` row points a user/org at `planKey = 'enterprise'`, `fn_features.php` resolves it - **zero PHP file changes**. This was proven live this session: a synthetic 5th plan was inserted into a scratch database, a user subscribed to it, and a real HTTP `POST /api/v1/bulk-generate` request with 2,001 items (over every SEEDED tier's cap) succeeded because the new plan's `bulk_batch_size` was 9999 - see CHANGELOG.md/`.claude/HANDOFF.md` for the pasted evidence.

To **move a feature between tiers** or **change a limit**, `UPDATE` the relevant plan row(s):

```sql
UPDATE `tblSubscriptionPlans`
   SET `featuresJSON` = JSON_SET(`featuresJSON`, '$.webhooks', true)
 WHERE `planKey` = 'free';
```

To **change a price point**, `UPDATE priceMonthly`/`priceYearly`/`currency` - nothing else reads those columns except a future pricing page and the (deferred) PayPal integration, neither of which hardcodes a value.

To **gate a brand-new feature or limit** in application code, just call `featureEnabled('your_new_key', $userID)` / `limitFor('your_new_key', $userID)` at the point that needs it - **no plan row needs the key yet**; every plan simply reads as denied/0 (fail-closed) until an admin adds it to whichever plan rows should have it, making rollout opt-in per tier with zero migration.

#### `featuresJSON` / `limitsJSON` schema

Both columns are flat JSON objects - no nesting, no per-tier PHP branches read them:

- **`featuresJSON`** - `{ "key": true|false, ... }`. Seeded keys: `expiry_scheduling` (FG-005), `password_protected_qr` (FG-004), `sso` (Epic E2), `organisations` (Epic E3 org stage), `webhooks` (FG-013), `csv_export` (Epic E11). `featureEnabled($key, $userID)` returns `false` for any key absent from the resolved plan - fail-closed, not fail-open.
- **`limitsJSON`** - `{ "key": <int>, ... }` where **`-1` means unlimited**. Seeded keys: `saved_codes`, `bulk_batch_size` (the ONE limit this framework stage wires to a live enforcement point - `fn_api_bulk.php`), `dynamic_codes`, `api_keys`, `analytics_days`, `api_rate_limit_per_min`, `api_rate_limit_per_day` (the latter two are pushed onto `tblAPIKeys.rateLimitPerMin`/`PerDay` by `syncApiKeyLimitsForUser()` whenever a subscription's plan changes - see "Enforcement design" point 4 in PROJECT_ROADMAP.md). `limitFor($key, $userID)` returns `0` for any key absent from the resolved plan - fail-closed, not unlimited.

#### Plan resolution rules (`_functions/fn_features.php`)

- **No user/org (anonymous visitor)** → the `anonymous` plan.
- **A logged-in user/org with no active/trialing/past_due subscription row** → the `free` plan. **This is an owner decision made without an explicit sign-off** (the task left it open) - the alternative would be defaulting an unsubscribed account to the `anonymous` plan instead. `free` was chosen because an account already earns persistence/saved-codes value over a true anonymous visitor, matching the tier matrix's intent; flag for an owner opinion if this should differ.
- **`past_due` subscription** → `planForUser()`/`planForOrg()` still report the subscriber's REAL paid plan (for display: "your Pro plan payment is past due") but `featureEnabled()`/`limitFor()` (the actual gating calls) transparently downgrade to the plan named by the `billing_past_due_downgrade_plan_key` setting (seeded `free`) for every check - existing gated objects are never hidden/deleted (there is no code path that does that), only NEW creation checks see the downgraded, more restrictive plan. Verified live: a scratch-DB user with an active `pro` subscription but `status = 'past_due'` was rejected at 51 bulk items (the `free` cap) and accepted at 49, over real HTTP.
- **`cancelled`/`expired`** subscriptions simply stop being resolved by `resolveActiveSubscription()` (it only selects `active`/`trialing`/`past_due`), so the subject falls back to `free` (or the org's legacy `tblOrganisations.subscriptionTier` hint, or `free`) on the very next request - no cleanup job needed.
- **Fail-safe defaults:** if the database is unreachable, or even the fallback plan row is missing (an unseeded install), `fn_features.php` degrades to a hard-coded, conservative shape (`cuercodeFailSafePlan()` - `bulk_batch_size: 25`, no features) rather than granting unlimited access or fataling.

#### Wired gate this stage: `bulk_batch_size`

`fn_api_bulk.php`'s `handleBulkGenerate()` replaced its old fixed `getBulkMaxItems()`/`api_bulk_max_items`-setting cap (removed entirely, along with the now-pointless setting) with `limitFor('bulk_batch_size', $currentUserId)`, where `$currentUserId` comes from the new `resolveUserIdFromAuth($auth)` helper (maps the router's session/CSRF user or an API key's owning user to a userID; anonymous stays `null`). Exceeding the limit still returns `413` (unchanged HTTP semantics - this is a request-size-style limit, not a boolean feature) but now also includes an `upgrade_url` + `plan_limit` field in the JSON body, via a new additive 4th parameter on `errorResponse()` (`fn_response.php`) that merges extra top-level fields into the standard error payload without touching any existing call site's behaviour.

#### Router `requiredFeature` route flag (scaffolded, unused this stage)

`public_html/api/v1/index.php`'s route table gained a `'requiredFeature' => null` field on every route (alongside the existing `'auth'`/`'rateLimit'` flags), plus a fully-wired "Feature Gate" block in the dispatch pipeline that calls `requireFeatureOr402()` whenever a route sets this to a non-null `featuresJSON` key. No route uses it yet (this stage's one live gate, `bulk_batch_size`, is a numeric LIMIT, not a boolean feature, so it is enforced inside the handler instead). Adding a future gated route (e.g. a webhooks CRUD endpoint requiring `'requiredFeature' => 'webhooks'`) is then a one-line change to that route's definition - the router logic needs no further edits.

#### Deferred: PayPal Subscriptions webhook (owner-gated, next E4 stage)

**Not built this stage - `tblPayments` is schema-only.** The next E4 stage implements:

- **Endpoint:** `public_html/api/v1/webhooks/paypal/index.php` - a new PUBLIC route (no `X-API-Key`/CSRF - PayPal calls it directly), so it needs its OWN authentication: PayPal transmission-signature verification (the `PAYPAL-TRANSMISSION-SIG`/`PAYPAL-TRANSMISSION-ID`/`PAYPAL-CERT-URL`/`PAYPAL-AUTH-ALGO` headers, verified server-side against PayPal's public certificate - never trust an unverified webhook body).
- **Idempotency:** every processed webhook event INSERTs one `tblPayments` row keyed by `providerTxnRef` (UNIQUE) - a re-delivered event (PayPal retries until it gets a 2xx) hits the unique constraint and is treated as a no-op, never double-processed.
- **State transitions:** successful payment events flip the matching `tblSubscriptions.status` (`active` on a successful renewal, `past_due` on a failed one, `cancelled` on a cancellation event, with `currentPeriodEnd` advanced on each successful cycle) and call `onSubscriptionPlanChanged()` (already implemented THIS stage in `fn_features.php`, just not called by anything yet) to re-sync the user's `tblAPIKeys` rate limits.
- **Owner gate:** needs a PayPal BUSINESS account + API credentials (client ID/secret, webhook ID) before any of this can go live - `#25`/`#26`, gates G-002/G-003 in `.claude/HANDOFF.md`. All credentials would be stored via `tblSettings` with `isSensitive = 1` (AES-256-GCM, `fn_encryption.php`), never committed.
- **Also deferred (needs E3's admin stage):** the admin plan editor (a UI over `tblSubscriptionPlans` - SQL/phpMyAdmin is the interim editing path). The public plan-COMPARISON page shipped separately - see "Pricing page" below - but the in-account UPGRADE/checkout flow still needs this webhook.

#### Pricing page (#56, 2026-07-17)

`public_html/pricing/index.php` + `_functions/fn_pricing.php` - the public plan-comparison page `resolveUpgradeUrl()`'s default `billing_upgrade_url` setting already pointed at (`/pricing`) before this page existed. Deliberately a separate, page-scoped helper file (`fn_pricing.php` is `require_once`'d by the page only, NOT added to `bootstrap.php`'s always-loaded set) mirroring how `fn_openapi_render.php` is scoped to the developer reference page.

**Data-driven contract (same discipline as `fn_features.php`):** `fn_pricing.php` contains NO hardcoded plan-key literals anywhere - not even the two fallback keys `fn_features.php` itself is documented to reference by name - enforced by a static regression guard in `tests/test_pricing.php` that scans the file's own source for every seeded plan-key string and asserts none appear quoted, including inside comments. Every card, every feature/limit row, and every label is discovered generically from the decoded `featuresJSON`/`limitsJSON` of whatever rows `listPublicPlansForDisplay()` returns.

**Which plans appear on the grid:** a plan qualifies when `isActive = 1` AND `sortOrder > 0`. The seeded no-account gating tier (`sortOrder = 0`) is intentionally excluded - it exists purely as `fn_features.php`'s anonymous-visitor bookkeeping, not a tier a visitor "chooses" from a comparison grid. This is a **design decision made without an explicit owner sign-off** (flag for an owner opinion if that tier should ever be surfaced as its own "no account needed" card): the convention is `sortOrder` 0 = resolvable for gating but hidden from the pricing page, `sortOrder >= 1` = shown on the grid in that order - an owner who wants it surfaced later need only give it a positive `sortOrder`, no code change.

**Owner price-edit runbook:** changing a price, renaming a plan, or moving a feature between tiers is the SAME one-row `UPDATE`/`INSERT` documented in "How to add or modify a premium tier" above - the pricing page re-reads `tblSubscriptionPlans` on every request, so there is no cache to bust and no deploy needed. Example - change the Pro plan's monthly price:

```sql
UPDATE `tblSubscriptionPlans`
   SET `priceMonthly` = 6.99
 WHERE `planKey` = 'pro';
```

**Fail-safe branch:** `listPublicPlansForDisplay()` returns `[]` (not a warning/exception) when `dbQuery()` is unavailable or the query throws, mirroring `fn_features.php::planByKey()`'s own availability check. The page detects an empty plan list and renders an accessible "Pricing is temporarily unavailable" panel instead of the card grid - still a valid `200` response, zero PHP warnings. Verified live via `php -S` against a deliberately-unreachable database host (a temporary, gitignored `_auth_keys/auth_creds.php` pointing at `127.0.0.1:1` so `getDB()` throws its documented `RuntimeException` rather than bootstrap.php's separate "credentials file entirely missing" 503 hard-fail): `HTTP 200`, the fail-safe panel rendered, zero PHP warnings/notices/fatals in the output, no `<table>` tag.

**No checkout flow.** CTA logic is purely price-driven (zero-price plans link to `/register/`; any paid plan shows a disabled "Coming soon" control) - checkout is owner-gated behind the deferred PayPal webhook above, so this page never invents one.

### Flexible pricing (#70, 2026-07-22) - BUILT BUT DISABLED

Implements the additive schema/helper delta specified in `Pricing_Strategy.md` §5 on top of the tier-gating engine above, proving five pricing STRUCTURES are representable **by data alone** - zero code change to add a real instance of any of them. Everything is **inert by default**, mirroring this codebase's existing `email_enabled`/`sso_enabled` kill-switch pattern exactly.

**Kill-switches (`tblSettings`, `billing` group - all seeded `0`):**

| Setting | Gates |
| --- | --- |
| `billing_price_points_enabled` | `pricePointsForPlan()` and `fn_pricing.php`'s price-point-preferring query - `0` = every plan renders its legacy `priceMonthly`/`priceYearly` columns exactly as before this delta shipped |
| `billing_discounts_enabled` | `resolveDiscountForCheckout()` - `0` = never resolves a discount, regardless of what rows exist in `tblDiscounts` |
| `billing_payg_enabled` | `creditBalanceFor()`/`consumeCreditsOr402()` - `0` = balance always reads `0`, consumption always denies |

Flip a switch to `1` (plus populate the matching new table) to bring a structure live - **no deploy needed**, the same "config, not code" discipline the tier engine already established.

**New tables (`install.sql`, Tables 22-26):**

- **`tblPlanPricePoints`** - kills the "exactly two fixed price columns, one currency" limitation. `priceKey` unique PER PLAN (not globally); `billingInterval` ENUM `monthly`/`yearly`/`lifetime`/`one_off`/`metered`; `grantsJSON` names what a one-off purchase grants (PAYG packs); `validFrom`/`validUntil` for launch-offer windows. `fn_pricing.php` PREFERS these rows over the legacy columns when present for a plan.
- **`tblDiscounts` + `tblDiscountRedemptions`** - `code` NULL = automatic/sitewide (no voucher entry needed); `discountType` `percent`/`fixed`; `durationType` `once`/`repeating`/`forever` covers one-off through lifetime discounts with the SAME shape; redemptions are one-per-user-per-discount via a UNIQUE key.
- **`tblOneOffPurchases`** - a sibling to `tblPayments` for lifetime-deal/PAYG-pack purchases NOT tied to a recurring subscription - kept as its own table specifically so `tblPayments`' `NOT NULL subscriptionID` + `ON DELETE RESTRICT` audit posture never had to weaken.
- **`tblCreditLedger`** - append-only PAYG credit ledger; balance = `SUM(delta)` per (subject, meterKey), never stored directly.
- **Additive columns** - `tblSubscriptions.pricePointID`/`discountID` (snapshot which offer was bought) and `tblPayments.pricePointID`, both added via `ALTER TABLE` blocks placed AFTER all five new tables so the original two `CREATE TABLE` blocks stay byte-for-byte the schema already shipped/tested.

**Helpers (`fn_features.php`) - all fail-closed, all no-op when the table is empty or the kill-switch is off:**

- `pricePointsForPlan($planID)` - request-cached like `planByKey()`.
- `resolveDiscountForCheckout($code, $planID, $priceKey, $userID)` - two static queries (code-supplied vs. automatic lookup, never one dynamic SQL fragment), scope/redemption-cap/one-per-user checks, all fail closed on any error.
- `creditBalanceFor($subjectType, $subjectId, $meterKey)` / `creditSpentThisMonth(...)` - `$subjectType` is `'user'`/`'org'`, mirroring the XOR convention `tblSubscriptions` already established.
- `consumeCreditsOr402($meterKey, $qty, $subjectType, $subjectId, ...)` - advisory-lock-wrapped EXACTLY like SECURITY.md F-023's `acquireSaveLimitLock()`/`releaseSaveLimitLock()` TOCTOU fix (new `acquireCreditLock()`/`releaseCreditLock()`, `CUERCODE_CREDIT_LOCK_NAME_PREFIX` + an `md5()`-hashed lock name so the lock name stays short regardless of `meterKey` length). Enforces `credit_spend_cap_monthly` via the EXISTING, unmodified `limitFor()` (a plan that has never seeded that key resolves to `0` - "PAYG not permitted on this plan at all", distinct from "cap reached this month"). The pure decision core, `evaluateCreditSpend($qty, $currentBalance, $spendCapMonthly, $spentThisMonth)`, has NO I/O at all - unit-tested in complete isolation.
- **Error codes**: `consumeCreditsOr402()`'s 8 denial codes are all fully-literal `'errorCode' => 'X'` shapes inside a `match()` block, deliberately NEVER built via string concatenation - the #58 error-catalogue drift guard (`tests/test_error_catalogue.php`) statically scans for exactly that literal shape, and a concatenated value would either confuse the scanner or hide a real code from the documented catalogue. `openapi.json`'s `x-error-codes` grew 83 → 91 entries accordingly.
- `resolveCreditLimitForSubject()` deliberately does NOT add an org parameter to the public `limitFor()` - it is a pure pass-through to `limitFor()` for a `'user'` subject, and only duplicates `limitFor()`'s own two-line fail-closed lookup (against `effectivePlanForGating(null, $orgID)`) for the `'org'` case that function does not support. `limitFor()`/`featureEnabled()`/`effectivePlanForGating()`/`planByKey()` themselves are UNCHANGED - proven by every pre-existing `test_features.php` assertion (Sections 1-8) still passing byte-for-byte.

**Pricing display (`fn_pricing.php`):** `listPublicPlansForDisplay()` gained ONE additive, fully-static query (no dynamic `IN (...)` list - the pricing page only ever shows a handful of plans, so fetching every active price point across every plan in one round trip and bucketing by `planID` in PHP stays cheap) gated behind the SAME `billing_price_points_enabled` switch. `pricingDisplayRowsFromPlans()` gained a pure-transform branch (`pricingFindPricePointByInterval()`) that prefers a plan's `monthly`/`yearly` price point over the legacy columns when present, and exposes every price point (grants decoded) on a new `pricePoints` output key. `pricingApplyDiscount($amount, $discountType, $value)` (new, pure) does the percent/fixed discount arithmetic. **No behaviour change whatsoever when the switch is off or the table is empty** - every existing `test_pricing.php` assertion passes byte-for-byte unmodified.

**What deliberately does NOT change:** `featuresJSON`/`limitsJSON` semantics, `effectivePlanForGating()`'s past_due model, the 4 seeded plans, `/pricing`'s fail-safe rendering. `credit_balance_cap` (also mentioned in Pricing_Strategy.md §4) is resolvable via `resolveCreditLimitForSubject()` but not yet actively enforced anywhere - that is a GRANT-time concern (capping how large a purchased-credits balance may reach) belonging to the future credit-grant/purchase-webhook code, which this delta does not build; only the SPEND side (`consumeCreditsOr402()`) ships this stage.

**Verification caveat:** no local MySQL server was available in the implementing session (`apt-get install mariadb-server` failed - the sandboxed proxy could not fetch `libmysqlclient21`/`rsync`/`iproute2`), so `install.sql` was NOT loaded into a live scratch database this session. Verification relied on `php -l`, a JSON-validity check on `openapi.json`, and structural sanity checks (balanced parentheses/backticks, exact `CREATE TABLE`/`ALTER TABLE` counts). Load `install.sql`/`seed_data.sql` into a real MySQL/MariaDB instance before relying on this schema in production.

### Dynamic Redirect + Dual-Function QR: Stage S0/S1 (#27/#71/#33, 2026-07-22) - BUILT BUT DARK/INERT

Implements Stages **S0** (schema + pure core) and **S1** (owner-facing destination API) ONLY of the dynamic-redirect / dual-function QR design (`.dev-team/dynamic_dualfn_design.md`, copied into the repo this session, read fully before implementing). **Nothing is live** - no public `public_html/r/` redirect route exists anywhere in this codebase, the `dynamic_url` QR type seeds `isActive=0`, and the `dynamic_qr_enabled` kill-switch seeds `'0'`. Stage S2 (the live `/r/{uuid}` route) and Stage S4 (dual-function QR, `GET /api/v1/resolve/{uuid}`) are fully designed and BUILT-READY but explicitly NOT started - gated on the G-007 owner sign-off (`LAUNCH_DECISIONS.md` §H).

**New tables (`install.sql`, Tables 27-29 - pure additive, zero `ALTER TABLE`):**

- **`tblQRRedirects`** - one row per dynamic redirect RESOURCE. `redirectUUID` is a DELIBERATELY DIFFERENT identifier namespace from `tblQRCodes.qrUUID` (Table 5) - the latter names a rendered IMAGE (download URLs), the former names a redirect resource that survives image re-generation/reprints; several rendered images could in principle share one redirect resource across a reprint. `ownerUserID`/`ownerOrgID` XOR-owner, mirroring `tblSubscriptions`' own convention. `isDualFunction`/`appID`/`resolvePayloadJSON` are Stage-S4 (#71) fields shipped now so no later `ALTER` is ever needed for them - completely unread/unwritten by anything in S0/S1. `appID` intentionally has NO FK yet (`tblSanctionedApps` does not exist until Stage S4 - the FK is added additively via `ALTER TABLE` then, the same "ALTER after" pattern #70 established).
- **`tblQRScans`** - scan-analytics event log (E11, #33), written by the future `/r/` route's fire-and-forget accounting step. FK `NOT NULL` to `tblQRRedirects` - a miss (404/invalid-shape request) is deliberately NOT a row here (counted separately by a future per-IP miss-throttle), keeping this table exclusively "a real redirect resource was resolved" events.
- **`tblResolveNonces`** - single-use dual-function-QR resolve nonces (#71, Stage S4). `nonceHash` hash-only at rest (mirrors `tblPasswordResets.tokenHash`'s established convention), a `usedAt` burn marker for single-use semantics. `appID` has no FK yet, same reason as `tblQRRedirects.appID` above.

**`validateRedirectDestination()` (`_functions/fn_redirects.php`, new)** - the single security-critical function this whole redirect layer rests on: a PURE, DB-independent, fully unit-tested allowlist validator (never a denylist). Ordered checks: length/control-char guard → scheme allowlist (`https` only by default; `http` gated behind an explicit `$opts['allow_http']`, never read from `tblSettings` directly - stays pure) → userinfo rejection → IP-literal loopback/private/link-local/reserved rejection (exact CIDR arithmetic via `cuercodeIpInCidr()`, `inet_pton()`-based - deliberately NOT relying solely on PHP's `FILTER_FLAG_NO_PRIV_RANGE`/`FILTER_FLAG_NO_RES_RANGE`, which miss some IPv6 cases) → IDN→Punycode normalisation (fail-closed without `ext-intl`) → self-referential-`/r/`-loop rejection (`cuercode.net`/`cuercode.me`/`qrmy.link` + `www.` variants).

- **⚠️ Real bug caught + fixed during this session's own testing**: PHP's `parse_url()` preserves the square brackets around a bracketed IPv6 host VERBATIM - `parse_url("https://[::1]/")['host']` is the literal string `"[::1]"`, NOT `"::1"`. A naive `filter_var($host, FILTER_VALIDATE_IP)` on that bracketed string returns `false` (brackets are not valid IP-address characters), so without stripping the brackets FIRST, every single bracketed-IPv6 private/loopback/link-local literal would silently fall through to the "hostname" branch and NEVER be checked against the disallowed-IP-ranges list at all - a real SSRF-defence bypass, caught only because the accept/reject test matrix explicitly exercised `https://[::1]/`, `https://[fe80::1]/`, etc. and one failed. Fixed by detecting a `[...]`-wrapped host, stripping the brackets for the IP-literal test + disallow-range check, and re-attaching them for the final reconstructed/normalized URL (bare IPv6 without brackets is not valid URL syntax). Left documented inline in `fn_redirects.php` as a permanent warning for any future URL-parsing code in this codebase.
- **Why validate at all, given nothing fetches a destination server-side yet**: (a) a `/r/` link is itself a laundering primitive for intranet-pivot phishing even with zero server-side fetch (the destination is what the VICTIM's browser follows); (b) the future §2.3 reputation-check/link-preview seam WILL fetch destinations server-side, and validating now means SSRF is structurally impossible then rather than "remember to add it" - see the design doc §1.4-d for the full rationale.

**`buildPayload_dynamic_url()` (`fn_qr_payloads.php`)** - encodes `{base}/r/{redirect_uuid}`. `base` is read from a NEW `tblSettings` key `dynamic_redirect_base_url` via `getConfig()` (seeded `https://QRmy.Link` - the TERTIARY domain, not the primary `cuercode.net` - per owner-decision O-11: a shorter printed URL AND, decisively, it partitions redirect-abuse reputation away from the primary product domain, so a future Safe-Browsing blocklist event on the redirect domain never takes the main site down with it). Zero hard-coded domain - fully admin-editable, no deploy needed to change it (until real codes are printed, at which point it becomes hard to change - flagged explicitly in `LAUNCH_DECISIONS.md`). The `redirect_uuid` is minted by the (future) creation handler, never user-typed - this builder strict-rejects a malformed one, mirroring `buildPayload_gs1_product()`'s GTIN-check posture (an allowlist regex, never a best-effort guess).

**Owner destination API (`_functions/fn_api_redirects.php`, new)** - `GET`/`PATCH /api/v1/qrcodes/{uuid}/destination`, riding the EXISTING `qrcodes:read`/`qrcodes:write` scopes (no new scope minted - the 7-entry `CUERCODE_API_KEY_SCOPE_ALLOWLIST` is unchanged). Own-scoped with the IDENTICAL 404-not-403 IDOR convention `fn_api_qrcodes.php` established for saved codes - `redirectIsWritableBy()` (`fn_redirects.php`) is the single ownership predicate both handlers call, mirroring `qrCodeIsWritableBy()`'s contract exactly. PATCH re-runs `validateRedirectDestination()` on every write (a destination accepted at create time is never assumed valid forever) and writes a `tblActivityLog` audit row (`activityType='redirect_destination_changed'`, old+new values) on every change. A row suspended for abuse (`disabledReason='abuse'`) rejects the WHOLE PATCH with `409 REDIRECT_SUSPENDED`, even from the true owner - only a (not-yet-built) admin re-enable path can lift it, so an owner can never edit around a takedown.

- **`createDynamicRedirectForOwner()`** - the create-dynamic-with-quota helper: `featureEnabled('dynamic_qr')` gate → `validateRedirectDestination()` → an F-023-style per-user MySQL `GET_LOCK()` advisory lock (`CUERCODE_DYNAMIC_LOCK_NAME_PREFIX`, a DIFFERENT lock-name prefix from the saved-codes lock so the two critical sections never contend) serialising the `dynamic_codes` plan-limit count-check + `INSERT` - the EXACT TOCTOU pattern SECURITY.md F-023 closed for saved codes applies identically here (N concurrent creates from the same user must not all pass the count check before any create lands). **Deliberately left UNROUTED this stage** - no route in `public_html/api/v1/index.php` calls it, and it is NOT wired into `handleGenerate()` either, even though the `dynamic_url` type row already exists (`isActive=0` keeps it invisible to `GET /api/v1/types`, but a direct `POST /generate {"type":"dynamic_url",...}` call would not have been rejected by `isActive` alone - wiring the helper in would have made real `tblQRRedirects` rows creatable via a today-reachable endpoint, which conflicts with "nothing may go live" this stage). Fully built, fully tested, ready for Stage S2 to call.

**Tier gating (`seed_data.sql`)** - `dynamic_qr`/`dual_function_qr` featuresJSON keys added to all 4 plans (fail-closed - an absent key already resolves `false` via `featureEnabled()`'s existing default): `dynamic_qr` true for free/pro/business (anonymous stays false, doubly excluded alongside its PRE-EXISTING `dynamic_codes: 0` limit - that limit key already existed from the original Epic E4 tier design and needed no change); `dual_function_qr` true for `business` ONLY (Pricing_Strategy.md §3.4/§3.5 - no `enterprise` plan is seeded yet, so this is currently a single-tier flag). New `redirects` `tblSettings` group (3 settings, all inert): `dynamic_qr_enabled` (master kill-switch, `'0'`), `dynamic_redirect_base_url` (`'https://QRmy.Link'`), `redirect_allow_http` (`'0'`, https-only default - `validateRedirectDestination()` itself never reads this; a future caller must wire it through via `$opts['allow_http']`, keeping the validator pure).

**OpenAPI/error-catalogue** - 2 new paths (`/qrcodes/{uuid}/destination` GET+PATCH, 29→30), 2 new schemas, a new "Dynamic Redirects" tag, 2 new error codes registered in `x-error-codes` (`INVALID_DESTINATION`, `REDIRECT_SUSPENDED`, 91→93) - `fn_api_redirects.php` was added to `tests/test_error_catalogue.php`'s 13-file scanned allowlist (now 14; the pure `fn_redirects.php` is deliberately NOT scanned - it never calls `errorResponse()` itself).

**Verification caveat:** identical to the #70 note above - no local MySQL server was available in the implementing session, so `install.sql`/`seed_data.sql` were NOT loaded into a live scratch database. Verification relied on `php -l`, a JSON-validity check on `openapi.json`, structural sanity checks (balanced parens/backticks, exact `CREATE TABLE`/`ALTER TABLE` counts, verified via a dedicated new test file rather than ad hoc), and a hand-run accept/reject matrix against `validateRedirectDestination()` directly via `php -r` (which is what surfaced the bracketed-IPv6 bug above). Load `install.sql`/`seed_data.sql` into a real MySQL/MariaDB instance and re-verify the DB-touching halves (`fetchRedirectRowByUuid()`, the advisory lock, `createDynamicRedirectForOwner()`, the two HTTP handlers) before Stage S2 work begins.

### Self-Service API Keys & Scope Enforcement (Epic E7 stage 1, #54, 2026-07-10)

`_functions/fn_api_keys.php` is the FIRST code in this codebase that can actually CREATE a `tblAPIKeys` row - `validateAPIKey()` (`fn_api_auth.php`) has read-validated keys since Phase 1, but nothing ever minted one outside a direct `INSERT`. Read that new file's own docblock first (it documents the engine/handler split, the IDOR posture, and the "owner/design decision" below in full); this section records the OWNER-FACING rationale.

**Owner/design decision - API keys cannot manage API keys.** Every one of the four `/keys*` routes (create/list/rotate/revoke) requires the caller to be authenticated via CSRF/session (`apiKeyManagementAllowedFor($auth)`) - an X-API-Key-authenticated request gets `403 API_KEY_MANAGEMENT_FORBIDDEN` even if that key holds every scope in the allowlist. **Why:** the scope-validation logic (`validateApiKeyScopesInput()`) has no way to cap a NEWLY-requested key's scopes to the CALLING key's own scopes - without this restriction, a compromised low-scope key (e.g. only `generate`) could call `POST /keys` and mint itself a brand-new key with the FULL allowlist, silently escalating its own blast radius. Requiring an interactive session for all key-management actions (including read-only listing - a compromised key should not get reconnaissance over the account's other keys either) closes that path entirely at the cost of not supporting automated/machine-to-machine key provisioning for now. This is a deliberate, reversible launch-time choice, not a technical limitation - a future stage could relax it (e.g. gate self-key-creation behind a dedicated `keys:manage` scope with an explicit "only a key with this scope may create keys with a SUBSET of its own scopes" rule) if a real use case needs it.

**Scope taxonomy - `CUERCODE_API_KEY_SCOPE_ALLOWLIST` (fn_api_keys.php).** Seven scopes, one per meaningfully-distinct capability the API already exposes: `generate`, `preview`, `download`, `bulk`, `qrcodes:read`, `qrcodes:write`, `save`. Deliberately flat (no hierarchy/wildcards) and deliberately mirrors the EXISTING route surface 1:1 rather than inventing a coarser "read"/"write" split - a key scoped only to `download` genuinely cannot call `/generate`, which is the point. `qrcodes:read`/`qrcodes:write` is the one route (`/qrcodes/{uuid}`, shared across GET/PATCH/DELETE via one router table entry) that needed a PER-METHOD scope, not a single value - the router's `'requiredScope'` field now accepts either a plain string (applies to every method the route allows) or an array keyed by HTTP method; every pre-existing route defines it as `null` (unaffected, opt-in exactly like `'requiredFeature'` before it).

**Scope enforcement lives in the auth layer, not a separate middleware.** `validateAPIKey()` (`fn_api_auth.php`) now also `SELECT`s `permissions`/`expiresAt` and decodes the JSON scopes array into `$result['scopes']`; `authenticateAPIRequest()` carries that through to its own return array. The router's new "Scope Enforcement" block (between Authentication and the existing Feature Gate block) checks `$auth['method'] === 'api_key'` (session/CSRF requests are NEVER scope-limited - full user rights, matching the router's existing `'requiredFeature'` convention of gating only what a route opts into) and, if the matched route's `'requiredScope'` resolves non-null for the current HTTP method, calls the new `apiKeyRequestHasScope($auth, $scope)` helper (also in `fn_api_auth.php`, since it operates purely on the `$auth` array with no DB access). A missing scope returns `403 INSUFFICIENT_SCOPE` with a `required_scope` field naming exactly what to grant.

**`expiresAt` enforcement - a latent gap closed as a drive-by fix, not the headline change.** `tblAPIKeys.expiresAt` has existed since the original schema but was never checked by `validateAPIKey()` - any key with an expiry date set (by direct DB manipulation, since nothing could set one via the API before this stage either) would authenticate forever regardless. Since this stage is already touching `validateAPIKey()`'s `SELECT`/decode logic to add scopes, the one-line `expiresAt > NOW()` check was added alongside it (fail-closed: an expired key returns the same generic "invalid" result as a wrong/inactive key). Self-service `POST /keys` does NOT currently expose an expiry INPUT (creation always leaves `expiresAt` NULL, i.e. never expires) - that remains a future enhancement; this fix only ensures the COLUMN is honoured the moment anything (a future feature, an admin, a manual `UPDATE`) ever sets one.

**Soft revoke (`isActive = 0`), not hard delete - same rationale as the saved-codes stage.** Mirrors `tblQRCodes.deletedAt`/`tblUserSessions.revokedAt`: the row (and its `createdAt`/`requestCount`/name history) survives for the owner's own audit trail and any future admin review, and `validateAPIKey()` already refuses `isActive = 0` rows, so a revoked key stops authenticating immediately with zero additional code.

**Rotate and revoke both re-check `isActive = 1` in the SAME mutating statement as ownership**, mirroring `fn_api_account.php`'s `revokeUserSession()` convention exactly: `UPDATE ... WHERE apiKeyID = ? AND userID = ? AND isActive = 1`. This means rotating or re-revoking an ALREADY-revoked key (even the genuine owner's own) reports the same `404 NOT_FOUND` as a nonexistent/not-owned key - simpler and more consistent than inventing a distinct "already revoked" response, at the minor cost of a slightly less specific message to the owner in that one edge case.

**Per-plan `api_keys` cap uses a plain COUNT-then-INSERT, NOT an advisory lock (unlike `POST /save`'s F-023 fix).** `fn_api_qrcodes.php`'s `saved_codes` TOCTOU race (F-023) was closed with a per-user `GET_LOCK()` because that endpoint's realistic threat model includes automated/bulk callers hammering `/save` concurrently. Key creation has no equivalent realistic concurrency profile (a human clicking "Create key" in the dashboard, or a script provisioning ONE key at setup time) and - critically - even a successful race would only let the SAME user create a FEW extra keys past their own cap, a low-severity, same-account inconvenience rather than a cross-user boundary violation like F-023 was. Accepted as a documented, low-severity risk rather than adding lock complexity to a low-value target; revisit if a future integration pattern makes bulk key-creation-by-the-same-user a realistic scenario.

**Not built this stage:** an expiry-date INPUT on `POST /keys` (the column IS enforced - see above - just not settable via this stage's own UI/API); per-key IP allowlisting; a `keys:manage` scope for machine-to-machine key provisioning (see the "owner/design decision" above); org-owned keys (`tblAPIKeys.orgID` exists but nothing in this codebase populates it yet, unchanged from `resolveUserIdFromAuth()`'s pre-existing note). This stage ALSO closes the `FG-019`/README.md "self-service API-key management (needs E6)" note from Epic E3 stage 2b - it shipped as Epic E7 stage 1 instead, since the actual key-lifecycle work (not developer-portal docs) was the real dependency, not E6.

### SSO / OIDC Client (Epic E2, #55/#19/#20/#28, 2026-07-10)

`_functions/fn_oauth.php` is a generic, provider-agnostic OAuth2/OIDC authorization-code + PKCE client (Microsoft 365 + Google now; SIGNula present but structurally inert - see below). Read its own docblock first (it documents the full security posture); this section records the OWNER-FACING setup runbook and the design decisions.

**Inert by default, same convention as `fn_email.php`'s `email_enabled`.** A MASTER `sso_enabled` switch (tblSettings, group `sso`) AND a per-provider `sso_<provider>_enabled` switch both default to `0`, and every provider's `client_id`/`client_secret` seed EMPTY. `ssoProviderIsUsable($provider)` is the single gate every entry point (login/register button rendering, `beginAuth()`, `completeAuth()`) checks - it fails closed the instant ANY of these is unset, so this whole subsystem attempts ZERO outbound network calls on a fresh install.

**Owner setup to activate Microsoft 365:**

1. **Register an app** (Microsoft Entra ID / Azure AD → App registrations → New registration). Choose "Accounts in any organizational directory and personal Microsoft accounts" (multi-tenant) to match the seeded `common` discovery endpoint, or a single-tenant option if you intend to override `sso_microsoft_discovery_url` with your own tenant ID.
2. **Add a Web platform redirect URI**: exactly `https://cuercode.net/auth/callback/microsoft` (must match byte-for-byte - Entra ID rejects a mismatched `redirect_uri` outright).
3. **Add the `openid`, `email`, `profile` delegated Microsoft Graph permissions** (these are the OIDC standard scopes - no admin consent should be required for them).
4. **Create a client secret** (App registration → Certificates & secrets → New client secret) - copy the VALUE (not the secret ID) immediately, it is shown once.
5. **Fill the settings via the admin UI** (never commit real values - all seed EMPTY): `sso_microsoft_client_id` (the app registration's Application (client) ID), `sso_microsoft_client_secret` (the secret VALUE from step 4).
6. **Flip `sso_microsoft_enabled` to `1`**, and the master **`sso_enabled` to `1`** (if not already on for another provider).

**Owner setup to activate Google:**

1. **Create OAuth credentials** (Google Cloud Console → APIs & Services → Credentials → Create Credentials → OAuth client ID → Web application).
2. **Add an Authorized redirect URI**: exactly `https://cuercode.net/auth/callback/google`.
3. **Configure the OAuth consent screen** with `openid`, `email`, `profile` scopes (Google's non-sensitive standard scopes - no verification review needed for these alone).
4. **Fill the settings via the admin UI**: `sso_google_client_id`, `sso_google_client_secret`.
5. **Flip `sso_google_enabled` to `1`** (+ the master switch).

**SIGNula - pending upstream (SIGNula issue #88).** `sso_signula_discovery_url` seeds EMPTY on purpose: SIGNula.id (as verified against its own repo - see PROJECT_ROADMAP.md Epic E2) is currently a credential-proxy REST API (opaque bearer tokens via `POST /api/v1/auth/login`) with **no `/authorize`, `/token`, `/userinfo`, discovery document, or JWKS** - it is not yet an OIDC identity provider. `ssoProviderIsUsable('signula')` fails closed on the empty discovery URL regardless of its own `sso_signula_enabled` flag, so no special-case code is needed to keep it inert - flipping the flag alone does nothing until an owner also fills in a real discovery URL, which cannot happen until SIGNula ships that surface upstream.

**Why the redirect_uri is per-provider, not query-param-based.** Each IdP's app registration allowlists an EXACT redirect URI string; `https://cuercode.net/auth/callback/{provider}` gives each provider its own distinct, registerable URL (via a `.htaccess` clean-URL rewrite to a single `auth/callback/index.php?provider={provider}` dispatcher) rather than one shared callback URL disambiguated by a query parameter that an IdP's allowlist could not distinguish from another provider's callback anyway.

**Account-linking is the core security decision - see `decideOAuthAccountLinkAction()`'s docblock in `fn_oauth.php` for the full table.** Summary: an identity ALREADY linked always logs in as that user; a BRAND NEW identity only auto-creates a local account when the IdP asserts `email_verified=true` AND no local account already claims that email; a verified email that COLLIDES with an existing (not-yet-linked) local account is REFUSED (never auto-linked - the account-takeover defence) and the visitor is told to sign in normally first and link the provider from account settings instead; an unverified email is refused outright, full stop. Linking an ADDITIONAL provider onto an already-authenticated session is a separate code path that does not re-check email verification at all, because the session itself already proves account ownership.

**Why the account-settings "Link" buttons are POST forms, not plain links (the "CSRF on the initiation" requirement).** The anonymous "Sign in with ..." buttons on login/register are ordinary `<a href>` links (a plain GET) - standard OAuth-client UX, and safe because there is no existing account session for a forged request to attack; the `state` parameter is the real defence for that flow. The account-settings "Link" buttons, in contrast, affect an ALREADY-authenticated account, so `public_html/auth/index.php` requires a CSRF-token-validated POST for any authenticated caller and renders a one-click confirmation page (containing that same POST form) if it instead receives a bare GET while authenticated.

**Not built this stage (deferred):** avatar sourcing from the IdP's own profile picture (`fn_auth.php`'s `resolveAvatarUrl()` still documents this as an unreachable Tier 2, by design - out of THIS stage's explicit scope, which is account-linking + session issuance, not profile enrichment); an admin "force logout of all SSO sessions" action; SIGNula (pending upstream, see above); organisation-level SSO enforcement (the `sso` feature flag already exists per-tier in `tblSubscriptionPlans.featuresJSON` from Epic E4, seeded `true` only for the `business` tier - that flag is reserved for a FUTURE "require SSO for this organisation's members" enforcement feature and is deliberately NOT used to gate whether an individual can use the Microsoft/Google sign-in buttons built here, which is a suite-wide convenience available regardless of plan).

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
| `tests/test_auth.php` | Authentication (Epic E3 stage 1, #52) - Argon2id hash/verify/rehash/policy, token generation/hashing, pure lockout-window maths, and session-dependent functions' fail-safe behaviour with no PHP session/database |
| `tests/test_features.php` | Premium tier / feature gating engine (Epic E4 framework stage, #25/#26/#53) - fail-safe plan resolution, the per-request cache, generic fail-closed `featureEnabled()`/`limitFor()` lookups, `requireFeatureOr402()`'s denial shape, `resolveUserIdFromAuth()`, and a static source-text regression guard proving zero per-tier ('pro'/'business') hardcoding |
| `tests/test_qrcodes.php` | Saved-codes API (Epic E3 stage 2a, #52/#24) - exhaustive truth-table coverage of the three ownership predicates (`qrCodeIsReadableBy()`/`qrCodeIsWritableBy()`/`qrCodeIsDownloadableBy()` - the entire IDOR-enforcement surface), UUID/label/type-filter/pagination validation helpers. The live scratch-DB + real-HTTP-router IDOR pack (cross-user 404s, plan-limit 402, pagination, PATCH regeneration) is documented in CHANGELOG.md/SECURITY.md, not re-run inside this dependency-free harness (same convention as `test_auth.php`'s DB-dependent exclusions) |
| `tests/test_account_ui.php` | Account UI (Epic E3 stage 2b, #52/#12/#14/#23/#24) - `resolveAvatarUrl()`'s full §11.4 priority-chain truth table, `maskTokenForDisplay()` (incl. the `substr(-0)` footgun regression this stage's own testing caught), `decodeAndValidateAvatarBase64()` (malformed input, explicit SVG rejection, and a genuine 1×1 PNG decode round-trip via the reused `saveBase64LogoToTemp()`), and static own-scope/no-SVG-allowlist regression guards. The live scratch-DB + real-HTTP end-to-end drive (both the AJAX-JSON and no-JS `<form>` paths, cross-account IDOR proof, plan-limit 402/upgrade-prompt proof, open-redirect proof) is documented in CHANGELOG.md, not re-run inside this dependency-free harness |
| `tests/test_api_keys.php` | Self-service API keys (Epic E7 stage 1, #54) - `apiKeyIsOwnedBy()`'s full IDOR truth table (mirrors `qrCodeIsWritableBy()`), `apiKeyManagementAllowedFor()`'s CSRF-only guard, name/scope validators (incl. the fail-closed-by-omission vs. required-field distinction), the scope allowlist + label helper, and static source-text regression guards (the CSRF-only guard runs first in every handler, the hash column is never SELECTed, the router's scope-enforcement wiring exists). The live scratch-DB + real-HTTP two-account pack (cross-user rotate/revoke 404s, scope-missing 403s, the `api_keys` plan-cap 402, create/rotate-only one-time-secret exposure, list never leaking the hash, expired-key rejection) is documented in CHANGELOG.md/SECURITY.md, not re-run inside this dependency-free harness |

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
- **Salt:** 🧂 Per-record (F-006/G-010, Epic E3 stage 1) - a fresh random 16-byte salt is generated PER CALL to `encryptValue()` and embedded in the returned ciphertext blob itself (self-describing); no longer a single global `ENCRYPTION_SALT` shared by every row. See "Per-Record Encryption Salt" above for the full design.
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
7. **📖 `openapi.json` is the source of truth for the API surface:** `public_html/api/v1/openapi.json` (OpenAPI 3.0.3) is the canonical definition of every endpoint, parameter, schema, and response. Swagger UI at `public_html/api/docs/` derives from it (client-side, fetched from `/api/v1/openapi.json`); the developer portal's `public_html/developers/reference/index.php` derives from it too, but server-side (via `_functions/fn_openapi_render.php`, loaded + parsed at request time — nothing on that page is a hand-copied duplicate of the spec). Any API change (new route, new parameter, new error code, new status code) must update `openapi.json` in the SAME change, not as a follow-up. **Drift guard (2026-07-10 OpenAPI refresh, `tests/test_openapi_render.php`):** a suite of assertions parses the REAL router route table out of `public_html/api/v1/index.php` (text-level, not `include()`) and diffs it against `openapi.json`'s `paths` — every router route must have a matching spec path+method, and every spec path must have a matching router route EXCEPT the two documented-as-out-of-router-scope SSO browser-redirect paths (`/auth/{provider}`, `/auth/callback/{provider}`, which live at `public_html/auth/*` outside `api/v1`, each carrying its own `servers` override). The same file also diffs `fn_api_keys.php`'s `CUERCODE_API_KEY_SCOPE_ALLOWLIST` constant against `openapi.json`'s `ApiKeyCreateRequest.scopes.items.enum` (must match exactly, same order) and spot-checks that every route the router marks `rateLimit => true` declares a `429` response, and that every handler with a real pre-flight `dbQuery()`-unavailable guard (`errorResponse(..., 503, 'SERVICE_UNAVAILABLE')`) has a matching `503` response in the spec. **If this test suite fails, the spec has drifted from the real API — fix `openapi.json`, never loosen the test.**

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
