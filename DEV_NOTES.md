# 🛠️ CueRCode - Developer Notes

**📅 Last Updated:** 2026-09-24 (watchdog standing rule, #116); previously 2026-09-23 (documentation refresh, #103); previously 2026-09-23 (CI fixes #109/#110 — empty cherry-picks skipped in `security-propagate.yml`; `release-candidate` now fully scanned; AI-assisted working rules, #108); previously 2026-08-04 (Four-tier branch cadence + multi-branch security/Dependabot propagation, #64)

---

## 🤖 AI-Assisted Working Rules (2026-09-23, #108)

Work on this repo is done with AI coding tools (Claude Code, Codex). The full
rules live in `.claude/CLAUDE.md` ("Standing Rules"); the live position and task
queue live in `.claude/HANDOFF.md` §0. In short:

- Commit **and push** to `work-in-progress` only; one PR `work-in-progress → alpha` later (no stacked PRs).
- Deep planning with Opus agents one after another; building with Sonnet/Haiku (Opus if complex).
- Every change is reviewed by the *other* AI tool (Codex reviews Claude's work and vice versa) until no issues remain.
- After each task: push, update its GitHub issue, update `.claude/` and `.OpenAI/` memory, update the handoff.
- If one AI tool is unavailable, another can take over from the handoff; switch back and do a full review.
- **Watchdog (2026-09-24, #116):** anything that finishes later (CI runs, merges, deploys, background agents) is watched until it has really finished and its result (including side effects) has been checked. Only then do dependent steps start. Failed, cancelled, timed-out or never-started all count as a problem.
- Codex context: `.OpenAI/` (root `AGENTS.md` points there).

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
- **Scanning:** `codeql.yml` (JS) and `semgrep.yml` (PHP+JS) trigger on
  push/PR to **all four** permanent tiers. `ci.yml` (PHP lint + test suite +
  gitleaks secret scan) is a separate, narrower-reaching workflow file - see
  the correction directly below for exactly which branches actually have it.
  - **Correction (2026-09-25 audit, stand-in review):** an earlier version of
    this note had the `ci.yml` branch list backwards. Checked directly
    against the repository (`git cat-file -e <branch>:.github/workflows/ci.yml`):
    the file exists on `work-in-progress`, `alpha` and `release-candidate`
    ONLY - it has NOT been copied onto `beta` or `main`. (Having the file on
    `work-in-progress` does not make it run on pushes there: its trigger list
    only names the four permanent tiers, so it runs on the
    `work-in-progress` → `alpha` pull request, not on the branch itself.) So a push to `beta`
    or `main` gets no automated tests, no PHP syntax check, and no secret
    scan today; those three checks start running on a branch only once it is
    promoted up the chain (`alpha` → `beta` → `release-candidate` → `main`)
    and carries the file with it. (`codeql.yml`/`semgrep.yml` above are
    unaffected by this mistake - they are unchanged and genuinely do trigger
    on all four tiers.) Separately: since 2026-09-23 GitHub has refused to
    start ANY Actions job on this repository (a billing/spending-limit
    problem, not a fault in the workflows), so even where `ci.yml` already
    exists - `release-candidate`, for example - it has never actually
    completed a run yet.
  - **`emoji-ingest.yml`'s schedule only fires once the workflow is on `main`.**
    GitHub only runs a `schedule:`-triggered workflow from whatever the
    repository's default branch is (currently `main`) - a copy of the workflow
    sitting on `work-in-progress`/`alpha`/`beta`/`release-candidate` will not
    fire on its cron until it has been promoted all the way to `main`. This is
    a GitHub platform behaviour, not a bug in this repo's config.
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

- **Supported versions:** PHP 8.4, 8.5 and 8.6; the target stays 8.5 until the host offers 8.6 (decision 0002, `docs/decisions/`). Minimum is 8.4. See "PHP 8.6 readiness and version guards" below.
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
| 📖 Swagger UI | 5.33.0 | jsDelivr (SRI-pinned) | Interactive API docs at `/api/docs/` - local fallback also **committed** (not gitignored) under `public_html/assets/vendor/swagger-ui/`, so the page works with no CDN reachable (#111) |

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

PHP 8.4/8.5 zero-deprecation is a hard Definition-of-Done gate. As of cycle 10, zero `E_DEPRECATED` or `E_STRICT` notices are emitted by any file under `web/CueRCode.net/`. Any future change must preserve this bar — run `php -l` and verify no deprecation output before committing. (PHP 8.6 is reported but not yet a hard gate; see "PHP 8.6 readiness and version guards" below and decision 0002.)

### PHP 8.6 readiness and version guards (#151, decisions 0002, 0004 and 0006, 2026-11-18)

**Position:** CueRCode's own code needs no changes for PHP 8.6 (checked against php-src `PHP-8.6/UPGRADING`). The work is guard rails.

- **Scanner:** `php tools/php86-compat-scan.php` reads all PHP in `web/`, `tests/` and `tools/` with the PHP tokenizer and reports code that 8.6 deprecates or changes (full rule list in the file header). Exit 0 clean, 1 findings, 2 usage error. `--path=<dir>` limits scope; `--include-vendored` lists library hits as warnings. Opt out one line with `// php86-scan-ok: <reason>`. Its test (`tests/test_php86_compat_scan.php`) uses bad examples held as strings, never as separate `.php` files, and also checks the whole repo is clean.
- **Deprecations fail the checks:** `tests/bootstrap.php` records any PHP deprecation during the test run as a failure, including one raised inside a child PHP process that a test spawns (the child writes a `CUERCODE-DEPRECATION:` line to STDERR and exits 255; the five spawning helpers turn that into a failed assertion, proved in `tests/test_php_runtime_guards.php`); `tools/ci/php-lint.sh` (shared by `ci.yml` and `deploy.yml`) fails on compile-time "Deprecated:" output, which `php -l` alone hides because it still exits 0.
- **Session settings must stay explicit.** PHP 8.6 turns on strict session mode, HttpOnly and SameSite=Lax by default; 8.4 and 8.5 do not. `_includes/bootstrap.php` sets strict mode and cookies-only with `ini_set()` before `session_start()` (the `.htaccess` `php_value` lines are skipped under FastCGI, so they cannot be the only copy) and sets HttpOnly and SameSite=Lax in `session_set_cookie_params()`. `tests/test_php_runtime_guards.php` reads the source as text and fails if a line is removed; it proves the settings are written in the code, not what a live server sends.
- **CI:** lint and tests run on 8.4, 8.5 and 8.6; `deploy.yml` has a `php-checks` gate (lint plus scan on all three) that the `deploy` job needs. Since #155 (decision 0025) `deploy.yml` also has a `tests` job that runs the unit test suite (`tools/ci/run-tests.sh`, shared with `ci.yml`: every `tests/test_*.php`, but not the real-database tests in `tests/integration/`); the upload waits for both. The 8.6 jobs are `continue-on-error` until the two-green-runs rule in decision 0002 is met (8.6 general availability is due 2026-11-19; until setup-php's `latest` is 8.6, it may serve a release candidate or nightly build). All CI jobs have a 20-minute timeout. **To make 8.6 blocking:** once setup-php's `latest` is 8.6 or higher and 8.6 has passed two runs in a row, remove the `continue-on-error` lines in `ci.yml` and `deploy.yml` in one commit and record a new decision. A red 8.6 result is reported and followed up, not ignored.
- **Scanner known gaps (not detected):** objects passed to `http_build_query()` or `mb_convert_variables()`, session handler methods, and functions passed as strings to callables (e.g. `array_map('is_double', ...)`). `array_walk`, `is_a` and `is_subclass_of` are flagged only when the first argument plausibly is an object or a string literal.
- **JSON error text:** on 8.6 `json_last_error_msg()` adds where the error is, so API error text for bad JSON gains detail. Cosmetic; no test or spec quotes the text.
- **Adopted 8.6 feature (decisions 0004 and 0006):** `clamp()`, through `cuercodeClampInt()` in `_functions/fn_compat.php`, at 35 whole-number sites (files that use it also `require_once` it, and `_includes/bootstrap.php` loads it early). Convention: version helpers live in `fn_compat.php`, carry the `cuercode` prefix, test that the feature exists (`function_exists`), and never declare a global function PHP owns (the scanner flags a global `function clamp(`). `tests/test_php86_adoption.php` proves identical results on every version.
- **Skipped on purpose (reasons in decision 0006):** the decimal clamp in `fn_email.php` (NaN differs); `ZipArchive::openString`/`closeString` (measured 78 MB against 50 MB peak for a 25 MB archive, risky under the 128 MB limit); the new `Uri` classes (URL checks are security); `Time\Duration`, `grapheme_strrev`, `quote_string` (no use); sodium ipcrypt/XOF/KEM (stored data / security); new syntax (8.4/8.5 cannot parse it).
- **Trim change (open, owner question):** on 8.6, `trim`/`ltrim`/`rtrim` also strip form feeds; 8.4/8.5 keep them. 243 calls use the default character list. No code changed yet.
- **Version-guard convention for future code (decisions 0004 and 0006):**
  1. For a new function or class, test it exists, for example `if (function_exists('clamp'))`.
  2. For a behaviour change with no new name to test, use `if (PHP_VERSION_ID >= 80600)` (integer comparison). Leave the existing `version_compare` lines alone.
  3. Never branch on version for anything that writes stored data or makes a security decision.
  4. Every branch needs a test that runs on each version in the matrix.
  5. House style: multi-line, emoji comment block, `@see` link to php.net.

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
5. **Enter the settings in the admin screen** (never commit real values - all seed EMPTY). Sign in as a Global Admin, open **`/admin/settings/`**, unlock editing with your password, and in the **Email (Microsoft 365)** section fill in (see "Entering secrets" below for how the boxes behave):
   - **Microsoft 365 tenant ID** (`graph_tenant_id`) - the Entra ID tenant ID (Directory (tenant) ID on the app registration's Overview page), or your domain name.
   - **App (client) ID** (`graph_client_id`) - the app registration's Application (client) ID.
   - **App secret** (`graph_client_secret`) - a client secret VALUE (not the secret ID) created under the app registration's "Certificates & secrets".
   - **Sending mailbox** (`graph_sender_mailbox`) - the shared mailbox address from step 3.
   - **Sender name** (`email_from_name`) - the display name recipients see (e.g. "CueRCode Notifications").
   Press "Save email settings", then press **"Check Microsoft connection"** - it must say Connected before you go on.
6. **Turn on "Send emails"** (`email_enabled`) in the same section - tick the confirming box (emails already waiting start sending), save, then press **"Send me a test email"** to send one to your own address (needs email on; at most 5 in 10 minutes).
7. **Set up the queue runner (required)** - the site only ever *queues* emails; nothing is sent until this job exists (#112). *The DreamHost menu names, the PHP path and the shortest cron interval below are **not checked against the live panel/server - confirm when setting up**.*
   - In the DreamHost panel: **Advanced → Cron Jobs → Add New Cron Job**. Choose the site's shell user, give it a title such as "CueRCode email queue", and pick **Custom → every minute** (if the shortest interval the panel offers is longer, emails simply wait up to that long).
   - Command (use the command-line `php`, never `./file` - the deploy does not keep execute permissions; adjust `php84` to the site's PHP version):
     `/usr/local/php84/bin/php /home/<user>/<path>/CueRCode.net/_includes/cron/process_email_queue.php --quiet`
   - Set **"Email output"** to your own address. With `--quiet`, the job prints nothing on a normal run, so you only get an email when something needs attention. While sending is blocked (exit code 4) that means **an email every minute** - an intended outage alarm; pause the job while you fix the cause if it gets too noisy.
   - If the panel offers **"Use locking"** (or "prevent overlapping"), **leave it unticked**. The runner has its own lock, and the panel's lock would stop a new run from even starting - so the runner could never report that an earlier run is stuck.
   - **Run it once by hand with `--check` first** (from SSH, or as a one-off cron job): it tests the database and the settings and shows the queue counts, without sending anything.
   - Fallback for a host without cron: set `email_queue_autoprocess=1`. Since #112 this really works - `bootstrap.php` calls `maybeProcessEmailQueueOnShutdown()` on web requests only - and it sends up to 2 emails at the end of an ordinary page visit, using the same duplicate-proof code as the cron job. It is OFF by default because it adds work to page visits, only runs when people visit (retries stall on a quiet site), and on a server without `fastcgi_finish_request()` the visitor's page keeps loading while the emails send. It is safe (just pointless) to leave it on alongside cron. The fallback also runs the clean-up (#122/#123, under the same lock), but only when sending is switched on - so a host with **no cron job and email switched off gets no clean-up** at all.

**Queue + retry (#112):** `queueEmail()` inserts a `tblEmailQueue` row (status `queued`, `nextAttemptAt = NOW()`). The runner (`runEmailQueueBatch()`, reached from the cron script or from `processEmailQueue()`) then:

- **Claims each due row with one guarded `UPDATE`** that re-checks *everything* at the same moment - still `queued`/`failed`, due now (`nextAttemptAt <= NOW()`), attempts left (`attempts < maxAttempts`). Before #112 the claim checked only the status, so a runner working from an out-of-date list could re-send an email another runner had just rescheduled (ignoring the back-off and losing its attempt count).
- **Re-reads the row after claiming** and uses only those values, re-renders the template fresh (never trusts a stored body), and calls `sendEmailNow()`.
- **Records the result only while the row is still `sending`**: `sent`, or back to `queued` with **exponential backoff** (`nextAttemptAt = NOW() + 2^attempts MINUTES`, via the unit-tested `computeEmailBackoffMinutes()`), or `failed` once `maxAttempts` (default 5) is used up. With the defaults the waits are 2, 4, 8 and 16 minutes, so an email that keeps failing is **given up about 30 minutes after its first failure** (the old "~62 minutes" figure was wrong).
- **Puts back stuck rows**: a row left `sending` for more than **10 minutes** (the runner died part-way) is put back and **counted as one attempt**, because we cannot know whether Microsoft accepted it.
- **"Sending is blocked" problems no longer use up attempts.** The switch being off, missing settings, no Graph token, Graph answering 401/403/404 (bad credentials, missing permission, wrong sender mailbox) or curl not starting: the email is put back unchanged, the run stops, and the runner exits with code 4. (Before #112, every queued email burned through its retries while email was "not configured yet" and ended up permanently `failed`.)
- **Temporary Graph trouble** (HTTP 429, 408, 5xx, a network error or timeout) uses an attempt with back-off and **stops the run early**, so a struggling service is not hammered. Problems with one message (e.g. HTTP 400, a template that will not render) use an attempt and the run carries on.
- **Takes a named database lock** (`GET_LOCK`, one per database) so only one runner works at a time, and raises a **stall alarm** (exit 4) if the lock is held while emails have been waiting more than 10 minutes.
- **Stays inside its minute**: at most 20 emails per run (`--limit`, 1-50) and no new email is claimed after 45 seconds.
- **Skips expired links (#123)**: a waiting email whose one-time link has expired, or has under 2 minutes left, is marked `skipped`, not sent - it would only deliver a dead link. The age is measured on the **database's** clock from `createdAt`, against `fn_auth.php`'s own lifetimes (24 hours for verification, 60 minutes for password reset). It is done in bulk before sending, with a per-email check as a backstop.
- **Keeps only what it needs (#122)**: the stored details (`paramsJSON`, which can include the one-time link) are cleared when an email is sent or skipped, and 24 hours after it is given up. Old records are deleted (30 days for sent/skipped, 90 for given up). All of this happens under the lock, before sending - see "Email queue clean-up and privacy" below.

#### Email queue runner (`_includes/cron/process_email_queue.php`, #112)

**What it is:** a thin command-line wrapper around `emailQueueRunnerMain()` in `fn_email.php`, where all of the logic and its tests live. It refuses to run under anything but the command-line PHP (a web request, or `php-cgi`, gets `403 Access denied.`) before it loads anything; it also lives in `_includes/`, which has no web address and is deny-all.

**How it stops duplicates, in plain English (three layers):**

1. **The row claim** - the real guarantee. Marking a row "I am sending this" re-checks every condition in one statement, and MySQL makes two simultaneous claims of the same row queue up, so only one can win. Every later update only applies while the row is still "sending", so only the runner that claimed a row can finish it. This works even with no lock.
2. **One lock per database** - a MySQL named lock (`cuercode_emailq:` + a hash of the database name, because lock names are shared by every database on a shared MySQL server). A second run that finds it taken reports "another run is working" and stops. MySQL lets go of the lock by itself if the process dies.
3. **Stuck-row recovery** - uses the existing `updatedAt` column as "claimed at" (no schema change), after 10 minutes.

**Options:** `--quiet` / `-q` (print nothing unless something needs attention - use this in cron), `--limit=N` (1-50, default 20), `--check` (test the database and settings and show queue counts; sends and claims nothing), `--help` / `-h`.

**Exit codes** (when several apply, the most serious wins: 3 > 4 > 5 > 0):

| Code | Meaning |
| --- | --- |
| 0 | Finished normally - including nothing to send, email switched off, and another run already working (the clean-up still runs while email is switched off). **It also covers an email that failed this time but has attempts left and will be retried**: exit 0 does not mean everything was delivered. The summary line says "will retry N"; with `--quiet` nothing is printed for it. |
| 1 | Could not start (for example a missing `_auth_keys/auth_creds.php`) or stopped by an unexpected error. |
| 2 | Bad command-line option. |
| 3 | Database problem (cannot connect, queue table unreadable, lock error), or the clean-up of old email records failed (sending is not affected). |
| 4 | Sending is blocked by the settings or by Microsoft Graph, or an earlier run seems stuck ("stalled"). |
| 5 | Finished, but gave up on at least one email after all its attempts. |
| 255 | PHP itself stopped the run part-way (a fatal error such as running out of memory). The runner prints a one-line note; any email it was sending is put back automatically after 10 minutes. Check the PHP error log. |

**What it prints:** one summary line of counts only, for example `CueRCode email queue: sent 3, will retry 1, gave up on 0, 5 still waiting (2.4 seconds).`, plus a plain-English line for anything unusual. With `--quiet` it prints nothing unless the exit code is not 0, stuck emails were put back (they may arrive twice), a result could not be recorded, or (since #122/#123) emails were **skipped because their link expired** or **because their details were already removed** - people did not get an email they asked for. Those go to the error stream so cron emails them. **Its own printed output never includes** an email address, subject, body, one-time link, secret, setting value or anybody's ID; every line also passes through an address-masking helper as a backstop. **Two things outside the runner's control can still reach the cron email**, because on the command line PHP's error log is the terminal: (1) if the database misbehaves, the site's shared error logger falls back to PHP's error log, and a database driver message there can include the database user and host (never the password); (2) if writing the activity log fails mid-run, the shared activity logger's fallback line includes the subject; **email addresses are hidden** (`[address]`) since #126. This is only about what the runner prints: the activity log in the database (`tblActivityLog`, `email_queued` rows) keeps the full recipient address (see "What it cannot do").

**What it cannot do:**

- It is **"at least once", not "exactly once"**: if Microsoft accepts an email but the runner dies (or the database fails) before recording "sent", the row is recovered after 10 minutes and sent again. Recovery counts that as an attempt, so repeats are capped - in practice at most one extra copy.
- Graph's `202 Accepted` means accepted, not delivered; bounces are not tracked.
- The named lock relies on MySQL named locks working on DreamHost's MySQL servers - not verified live, but four existing features already rely on the same feature, and the row claim is the independent backstop.
- A given-up email keeps its one-time link for 24 hours after it was queued - deliberately, so it can still be re-sent after an outage (#122, owner decision).
- The clean-up needs the queue lock, so only one run does it at a time: once per cron run (every minute), plus on page visits if the off-by-default page-shutdown fallback is switched on. Each clean-up step handles at most 500 rows per run (the rest follow on the next run). One edge case: if a run is cut off mid-send and email is then switched off, that email keeps its details until sending is switched back on, because stuck-row recovery only runs when sending is on.
- Since #129 it also deletes old email entries from the activity log (`tblActivityLog`, rows with entityType `email`, which name the recipient): queued, sent and skipped ones after the sent/skipped period, failed ones after the given-up period, at most 500 of each per run. Other activity-log rows are never deleted. Without the cron job (and with email off) nothing is deleted.
- On existing installs the live table's column comments keep their old wording (including the wrong "never stores secrets" note on `paramsJSON`) until the upgrade mechanism exists (#113). The code never reads those comments.

**Re-sending emails that were given up** (phpMyAdmin → the site's database → SQL):

```sql
UPDATE tblEmailQueue
SET status = 'queued', attempts = 0, nextAttemptAt = NULL, lastError = NULL
WHERE status = 'failed' AND createdAt > UTC_TIMESTAMP() - INTERVAL 1 DAY;
```

`nextAttemptAt = NULL` means "due now", and `UTC_TIMESTAMP()` is used because the site stores times in UTC while phpMyAdmin's `NOW()` uses the server's own time zone. Verification links last 24 hours and reset links 60 minutes, so re-sending older emails is pointless - the person needs to ask for a new one.

Since #122/#123: a re-queued email whose link has expired is skipped automatically (not sent), and a given-up email's details are removed 24 hours after it was queued, so re-queuing anything older does nothing useful - the person must ask for a new link.

#### Email queue clean-up and privacy (#122, #123)

A queued sign-up or password-reset email carries a **one-time link** (with its secret token) in its stored details, `tblEmailQueue.paramsJSON`. The token tables only ever store a hash of the token, so before #122 the queue table quietly undid that protection by keeping the usable link - forever, because nothing ever deleted a row. The queue runner now keeps only what it needs:

| When | `paramsJSON` (may hold the link) | The row itself |
| --- | --- | --- |
| queued, waiting for a retry, put back because sending is blocked, or recovered after a crash | **kept** (still needed to send) | kept |
| **sent** | **cleared** in the same database update that marks it sent | deleted **30 days** after it was queued (setting) |
| **skipped** (link expired, or its details were already gone) | cleared in the same update | deleted after 30 days |
| **given up** (failed, no attempts left) | kept **24 hours** after it was queued, so it can be re-sent after an outage, then cleared | deleted after **90 days** (setting) |

What stays on a finished row, for support: the recipient's address and name, the template, the subject, the status, the attempts, the last error and the times. That answers "did my email go out, when, and if not, why?". The address is deliberately **not** masked on the row (that would make the question unanswerable); the deletion limits how long it is kept instead.

- **Why `createdAt`** is the clock for deleting: it never changes, it has an index (so the delete only walks the old end of the table), and every email reaches its final state within about a day of being queued. `updatedAt` changes whenever anything touches a row, and `sentAt` only exists for sent rows.
- **Nothing waiting or in flight is ever deleted.** The two delete statements only match `sent`/`skipped` rows, and `failed` rows with no attempts left. Rows that are `queued`, `sending`, or `failed` with attempts left (code from before #112 wrote that for rows still being retried) are never deleted. The tests check the delete statements never mention those statuses, and a real-database check proves it on MySQL and MariaDB.
- **When it runs:** inside every queue run, only while that run holds the queue lock, and **before** anything is claimed (so an expired link is skipped, never sent). It also runs while email sending is **switched off**, or switched on with settings missing - keeping data only as long as needed must not depend on whether sending is on. At most 500 rows per step per run. A failed clean-up step is reported with exit code 3 but never stops sending.
- **What "skipped" means:** the email was deliberately not sent. The `lastError` column says why, in one of three texts:
  - `Not sent: its one-time link had expired, or had less than 2 minutes left, before it could be sent. The person needs to ask for a new link.`
  - `Not sent: its stored details (including any one-time link) had already been removed, so it can no longer be sent. The person needs to ask for a new email.`
  - `Not sent: the one-time link this email needs is missing, so it would only have delivered a broken email.`

  The last two protect against a cleared email being put back to `queued` by hand (for example by the re-send runbook above): the templates quietly show an empty link when it is missing, so without this guard such an email would go out broken and be reported as sent.
- **Expired links:** measured on the database's clock (`createdAt` against `NOW()`), never PHP's - PHP's clock follows the `site_timezone` setting while the database stores UTC. The lifetimes come from `fn_auth.php`'s own constants, so there is one place to change them. An email is skipped once its link has **under 2 minutes left**, because a run can take over a minute to reach it.
- **The two settings** (`tblSettings`, group `email`, type `integer`): `email_queue_retention_days` (default **30**) for sent and skipped records, and `email_queue_failed_retention_days` (default **90**) for given-up ones. Only whole numbers from 1 to 3650 are accepted; anything else (including 0, which is what a typo becomes) means the default is used. There is deliberately no "keep forever" value. `php process_email_queue.php --check` shows the periods in force, whether the expired-link check is on, how many emails were skipped, and the **name** (never the value) of any setting that is not valid.
- **Existing installs** (there is no upgrade script yet, #113): nothing needs doing - the defaults apply when the two setting rows are missing. To change a period, first add the rows (phpMyAdmin → the site's database → SQL; a "Duplicate entry" error just means the row is already there):

  ```sql
  INSERT INTO tblSettings (settingGroup, settingKey, settingValue, settingType, isSensitive, isEditable, description) VALUES
  ('email', 'email_queue_retention_days', '30', 'integer', 0, 1, 'Days to keep a SENT or SKIPPED email record in tblEmailQueue before the queue runner deletes it (#122), counted from when it was queued. Whole number 1-3650; anything else means the default of 30 is used.'),
  ('email', 'email_queue_failed_retention_days', '90', 'integer', 0, 1, 'Days to keep a GIVEN-UP (failed) email record before the queue runner deletes it (#122) - longer than sent ones, because support is asked about these. Whole number 1-3650; anything else means the default of 90 is used.');
  ```

  Then change a value with, for example, `UPDATE tblSettings SET settingValue = '60' WHERE settingKey = 'email_queue_retention_days';`.

- **Rows sent before this change** that still hold their details: normally there are none, because #112 (the first code that ever sent the queue) and this change ship together. If there are any, this one-off SQL clears them (`updatedAt = updatedAt` keeps the times as they were):

  ```sql
  UPDATE tblEmailQueue SET paramsJSON = NULL, updatedAt = updatedAt WHERE status IN ('sent', 'skipped') AND paramsJSON IS NOT NULL;
  ```

- **The live column comment** on `paramsJSON` (which wrongly says it never stores secrets) is unchanged on existing installs. It is documentation only - no code reads it - and is tracked for the upgrade mechanism (#113). Fresh installs get the corrected comment.
- **Limits:** email addresses in PHP's error log are masked (`_functions/fn_log_mask.php`, #126), including ones with non-English letters. Other personal data is not recognised, so nothing else personal may be logged. Since #129 (2026-11-28) the activity log's email entries are deleted after the same two periods, and deleting an account replaces its address in those entries and in the queue records. Not covered: error-log rows (`tblErrorLog`) have no time limit, and the other activity-log rows (with visitor addresses and browser names) are kept.

**Testing:** `tests/test_email_queue_runner.php` (part of `php tests/run.php`) drives the whole flow with an in-memory stand-in for the table, including two runners interleaved mid-send with and without the lock, and (since #122/#123) the clean-up, the expired-link checks and the "never send a cleared email" guard. `tests/test_log_mask.php` proves the four error-log fallbacks hide addresses (#126), running each for real in a separate PHP process. The optional `tests/integration/email_queue_real_mysql.php` proves the real SQL, the real lock and two real runner processes against a throwaway MySQL or MariaDB container - including, since #122/#123, the real clean-up updates and deletes (scenarios 9-14 and 10b) - see `tests/README.md`.

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

**Why `verify-email` needs an explicit confirm click but `reset-password` does not.** Both pages read a single-use token from `?token=`. A corporate mail gateway / antivirus link scanner routinely GET-prefetches every URL inside an email BEFORE the human recipient opens it. `verify-email`'s token is consumed by TOKEN ALONE (no other user input required) - if the page auto-verified on GET, a scanner's passive prefetch would silently burn the token and the real user would see a confusing "already used" error. `reset-password`'s token is only ever consumed TOGETHER WITH a new password the visitor must actively type into a form and submit - a scanner's bodyless GET can never trigger `resetPasswordWithToken()` at all, so no extra confirm-click precaution is needed there. (Since decision 0013, 2026-11-25, the verify-email form also asks for the account's password - see "Verification link needs the password" below.)

**Avatar storage mirrors the `_uploads/qrcodes/` pattern, not a public_html upload.** `_uploads/` is a non-web directory (`Require all denied` in its own `.htaccess`) - exactly like generated QR code images, an uploaded avatar is stored under `_uploads/avatars/{userID}.{ext}` and served through a small, session-cookie-gated streaming endpoint (`public_html/account/avatar/index.php`) rather than a direct link. This was a deliberate consistency choice with this codebase's ALREADY-established user-content security posture (`fn_api_download.php`'s pattern for QR files), not a new pattern invented for avatars. The one deviation: avatars explicitly reject `image/svg+xml` (the QR-logo pipeline's base64 decoder, reused here for validation, does accept SVG) - see `fn_api_account.php`'s header docblock for the specific self-stored-XSS reasoning (a direct, non-`<img>` navigation to an SVG avatar's URL renders it as a full document, executing any embedded script).

**Why `account/avatar/` and `account/qr-preview/` sit OUTSIDE the JSON API router.** Every `'auth' => true` JSON API route requires an `X-CSRF-Token` header (or API key) - a plain `<img src="...">` cannot attach a custom header, so these two read-only image endpoints authenticate purely via the PHP session cookie (`currentUser()`), with no CSRF token at all. This is safe specifically BECAUSE they are side-effect-free GETs of already-own-or-public content: the worst a forged cross-site request could achieve is causing the victim's own browser to re-fetch something it could already display - there is no state change for CSRF to protect against. Both still apply the SAME ownership check the JSON API uses (`qrCodeIsReadableBy()` for saved codes; `currentUser()`'s own resolved `avatarPath` for avatars - never a client-supplied target) and both 404 identically for "does not exist" vs. "not yours."

**Session IDs are shown (masked) to their own owner - the "is this a credential" question, resolved.** `GET /api/v1/account/sessions` returns each session's FULL `sessionID` (needed as the `{id}` for the DELETE route) alongside a masked `masked_id` for on-screen display (`maskTokenForDisplay()`, mirroring `footer.php`'s existing debug-panel masking). This was a deliberate call: nowhere in this codebase does any code path accept a client-supplied `sessionID` as a bearer credential - `currentUser()` ONLY ever reads it from `$_SESSION` (server-side, tied to the PHP session cookie). A leaked `sessionID` value therefore cannot be used to impersonate the account on its own; exposing it to the account's OWN authenticated owner (for the express purpose of letting them identify which "device" to revoke) carries no additional privilege-escalation surface beyond what an already-logged-in owner can already do.

**"Sign out everywhere" is a full-page redirect either way - no AJAX was built for it.** Both the no-JS `<form>` submit and the JS-enhanced path end at the identical outcome (every session revoked, including the current one, redirect to `/login/`; since the final review (I-1) this is `accountSignOutEverywhere()`, which also moves the "sessions valid from" mark so a sign-in still being checked elsewhere cannot survive it) - there is no partial/AJAX version of "log this browser out" that makes sense, so `cuercode-account-dashboard.js` deliberately leaves this one form un-intercepted.

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

**Owner-run hard-purge runbook (NOT automated - a deliberate, separate, manual step).** This codebase does not schedule or auto-run a job that permanently deletes anonymized rows. DreamHost's panel cron is now used for the email queue (#112), but this purge deliberately stays a manual step, because an irreversible bulk-delete of financial-adjacent rows deserves a conscious, logged, owner-initiated action rather than a silent background timer. Once a confirmed retention window has elapsed (**owner/counsel to set the exact number of days - see privacy.php Section 7.1's `[TEMPLATE]` marker**), the owner runs the following via the Dreamhost panel's phpMyAdmin (or an equivalent authenticated MySQL client - never exposed as a public endpoint):

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

This is intentionally a runbook, not code, for this stage - if a future stage automates it (e.g. mirroring the email queue runner (`_includes/cron/process_email_queue.php`, #112): a command-line script run by DreamHost's panel cron, protected by a named database lock), it must reuse this exact eligibility predicate (`status = 'deleted' AND erasedAt < <retention window> AND no remaining tblSubscriptions/tblPayments dependency`) and must itself be logged to `tblActivityLog` before any row is removed.

**Why the UI requires a TYPED confirmation phrase on top of the password.** `eraseUserAccount()`'s own contract only requires `confirm: true` (a single boolean) - the account dashboard's no-JS `<form>` additionally requires ticking an "I understand this is irreversible" checkbox AND typing the literal word `DELETE` (case-sensitive, enforced BOTH client-side via the `pattern="DELETE"` HTML5 attribute on a `required` input, which blocks submission even with JavaScript disabled, AND server-side in `public_html/account/index.php`'s own POST handler before `eraseUserAccount()` is ever called) - the SAME "UI adds its own extra friction on top of the engine's minimal contract" pattern the account page already uses for "new passwords match" before calling `changeUserPassword()`. The JSON API's documented contract deliberately stays password + `confirm: true` only - the typed-phrase friction is a UI/UX safety net for a human clicking through a web page, not a security control the API needs to additionally enforce for a programmatic caller who already had to supply the correct password.

**Why `GET /account/export/` exists as a second, cookie-only route alongside the CSRF-gated `GET /api/v1/account/export`.** Exactly the same rationale as `account/avatar/`/`account/qr-preview/` (see the note above): a plain `<a href>` "Download my data" link cannot attach the `X-CSRF-Token` header the JSON API's session-only auth model requires, and a data EXPORT (a pure read, no state change) does not need CSRF protection for the same reason those two endpoints don't - the worst a forged cross-site request could achieve is causing the victim's own already-authenticated browser to download something it could already see. Both routes call the identical `collectAccountExportData()` engine function - there is exactly one place that decides what an export contains.

### Per-Record Encryption Salt (F-006/G-010/DS-7, Epic E3 stage 1, 2026-07-10)

`fn_encryption.php`'s `encryptValue()`/`decryptValue()` no longer rely on a single global `ENCRYPTION_SALT` constant shared by every encrypted value in the database. Every call to `encryptValue()` that does not supply an explicit `$salt` now generates a fresh, cryptographically random 16-byte salt for THAT VALUE ONLY (`random_bytes(16)`), derives an independent AES-256 key via HKDF from it, and embeds the salt directly in the returned ciphertext blob (packed format: `[salt length (1 byte)][salt][IV (12 bytes)][GCM tag (16 bytes)][ciphertext]`, base64-encoded) - this makes `decryptValue()` fully self-describing from the master key alone, with no separate salt to track or pass around. An explicit `$salt` argument is still honoured verbatim (any length) for deterministic/reproducible testing.

An optional 4th by-reference parameter, `encryptValue($plaintext, $key, $salt, &$usedSaltOut)`, lets a caller capture the salt that was generated. `fn_config.php`'s `setConfig()` uses this to ALSO mirror the salt into a new `tblSettings.encryptionSalt BINARY(16) NULL` column, purely for DBA-visible auditability that per-record salting is genuinely happening (it is not read back by `getConfig()` - the embedded, self-describing blob is authoritative for decryption). Since all `isSensitive` seed values ship EMPTY, no re-encryption of real data was needed for this change.

`ENCRYPTION_SALT` remains definable in `_auth_keys/auth_creds.php` (note: no `auth_creds.example.php` template is actually committed — the `**/_auth_keys/*.php` ignore rule blocks it; tracked in #113) for backward-compatibility of the constant's existence, but is no longer consulted by the default (no-explicit-salt) code path - it is effectively legacy/vestigial. Verified via a scratch-DB round trip: two different `isSensitive` settings produced two different 16-byte salts and two different ciphertexts for otherwise-similar plaintext.

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

### Library watch (#139, 2026-11-25)

The libraries are downloaded by hand and committed (decision 0011), so Dependabot cannot see them. Instead, `.github/workflows/library-watch.yml` runs `php tools/check_library_updates.php` on the 1st of each month (07:17 UTC) and on demand.

- **The list:** `web/CueRCode.net/_libraries/PINNED_VERSIONS.json`. `libraries` (PHP libraries, fingerprinted by `check_vendored_libraries.php`) and `frontend` (Bootstrap, jQuery, Font Awesome, Swagger UI, Twemoji) hold the versions; `watch` says where each one's releases (GitHub tags or npm) and advisories (OSV ecosystem and name) live. The fingerprint check reads only `libraries`. A `frontend` entry's `marker` is checked against the committed file by `tests/test_library_updates.php`, so the list cannot drift. FPDF is called 1.86 but its GitHub tag is 1.8.6 (`upstreamVersion`).
- **Exit codes:** 0 up to date (or lookups failed, see the report), 10 a newer release exists, 20 a known advisory affects a pinned version. The workflow goes red on 20 (and if the tool itself crashes), never on a plain outage.
- **The issue:** exactly one open issue titled "Library watch: updates / advisories" (labels `security`, `infrastructure`). It is edited each month, never duplicated, never closed by the job. If a person closes it, the next month that has something to say opens a new one.
- **Never auto-upgrades.** The report lists the manual steps: replace the folder, `php tools/check_vendored_libraries.php --update`, read the diff. Front-end libraries also have CDN addresses with integrity hashes and a Content Security Policy to update.
- **Try it:** `php tools/check_library_updates.php` (real network, read-only), `--offline` (shows the outage wording), `--report=FILE --json=FILE`. Set `GITHUB_TOKEN` for a higher GitHub rate limit; it is only ever sent to `api.github.com`.
- **Limits:** the OSV answer's second page is not followed (no pinned library has near the hundreds of advisories needed); a library whose advisories only list git commit ranges is treated as affected (fail closed). The job only runs from `main`, so it starts after the first promotion.

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

**No checkout flow.** Since task 7n Phase 2: a "Contact us" plan links to its contact address; a free plan (nothing to pay and no price of its own) links to `/register/`; any other plan shows a disabled "Coming soon" control - checkout is owner-gated behind the deferred PayPal webhook above, so this page never invents one.

### Plans and prices runbook (task 7n Phase 2, 2026-11-26, #31)

This replaces editing plan rows by hand in SQL (above), which is now a last resort.

**After deploying this code, apply database upgrade `0004_plans_prices_currencies`** (`/admin/upgrades/`, or `php _includes/cli/migrate.php --apply`). Until then the pricing page shows the old single-price columns (no Enterprise card) and the plan screens and preview say an upgrade is needed.

| Task | Where |
| --- | --- |
| Change a price | `/admin/plans/` → Prices. If somebody has bought it, press Retire and add the new one. |
| Add a plan | `/admin/plans/new/` (it starts off sale: add prices, then put it on sale). A plan on sale with no price on sale shows "Price coming soon" and no sign-up button (decision 0021); only the visitors' plan and the free plan new accounts get can read "Free". |
| Take a plan off sale | Its Details page. People already on it keep it. |
| Change a plan people pay for | "Make a new version". |
| Offered and main currency, price promise, show or hide prices | `/admin/settings/`, "Prices, VAT & refunds". Any current ISO 4217 currency can be offered (`moneyCurrencyInfo()` in `fn_money.php` holds all 155, from ISO 4217 List One of 2026-09-17), including the seven with three decimal places (KWD, BHD, OMR, JOD, IQD, LYD, TND): since upgrade 0006 (decision 0042, #176) every money column is `DECIMAL(15,3)`, so their prices are saved exactly (`moneyStoredDecimalPlaces()` is 3; before, the columns were `DECIMAL(10,2)` and those seven were refused). The largest price that can be typed is 999,999,999,999 of the currency's smallest unit (`moneyMaxTypedMinor()`: 9,999,999,999.99 in pounds, 999,999,999.999 in dinars, 999,999,999,999 yen), chosen to suit currencies with very small units such as IDR or VND while staying inside the columns' 12 whole-number digits and far inside PHP's 64-bit integers (the largest sum worked out, a percentage discount, is about 10^16). The database gives amounts back with three decimals ("6.990"); every reader goes through `moneyStoredDecimalToMinor()`, the pricing page through `pricingFormatPrice()`, and the account export writes each amount in its currency's own decimal places (`moneyStoredDecimalForOutput()`). Prices cannot be saved until 0006 is applied (the editors wait for every upgrade). Not changed: `pricingApplyDiscount()` in `fn_pricing.php`, an older display-only helper that works in floats and rounds to two decimals; no page uses it, only its tests. Currencies other than pounds, dollars, euros and yen are shown with their code ("CAD 12.50"). |
| See $ and € before they are public | `/admin/pricing-preview/`. |

**Plans the site relies on** (the visitors' plan, the free plan, and any plan a setting names, such as the free-trial plan; `adminPlansProtectedReason()`) must stay on the pricing page with their own button. They cannot be taken off sale, deleted or replaced by a new version, and (review finding F3 and recheck finding 2) their details form refuses sale dates, "Contact us", a sales cap and display order 0 (the visitors' plan keeps its 0, because it never has a card). The rules are one function, `adminPlansProtectedPlanProblems()`, also used by the settings screen: the free-trial setting lists and accepts only paid ordinary plans on sale that pass it (`adminPlansTrialPlanChoices()`).

**The Free plan never has a price (decision 0041, #175, 2026-11-28).** The Free plan is the plan every new account is given (`CUERCODE_PLAN_KEY_FREE` in `fn_features.php`: a fact about the code, not a setting; no setting chooses it). Its card's "Create free account" button is how people sign up, and a price would replace that button, so no route can give it one: the price editor refuses every group for it on the locked row, whatever the request says (`adminPricesSaveGroup()`, with the plain reason from `adminPlansFreePlanPriceReason()`); its prices screen shows that reason instead of the price forms; a new version, which copies prices, cannot be made under its key; the copy into the older price columns only ever writes zero to it (`adminMirrorFreePlanDecision()`, which also puts back to zero any figure older data left there, for example after a change of main currency); the free-trial setting never offers it; and the starting data (`seed_data.sql` and every upgrade) gives it none, checked by `tests/test_admin_plans.php`. If older data still holds a price row or figure on it, the public pricing page ignores it (`listPublicPlansForDisplay()`), so the button always stays; the rows can be retired or deleted on its prices screen. Proved on MySQL 8.0.36, 8.4 and MariaDB 10.6 by scenario N of `tests/integration/admin_plans_real_mysql.php`. One case is unchanged: if the price list cannot be read at all, every card, the Free one too, says "Price unavailable" (Codex finding 3.5).

**What the public page shows while charging is off:** plans that are on sale, ordinary, inside their sale dates and with a display order above 0. Since finding F6 of the Phase 2 review (2026-11-27) the figures are worked out **from the price list every time the page loads** (`pricingApplyPriceList()` in `fn_pricing.php`): for each group (monthly, yearly) that has price rows, the price on sale at that moment in the main currency (or, if the plan has no monthly price in the main currency, in its own older currency), so "on sale from" and "on sale until" dates are followed without anyone saving again. A group with no price rows at all falls back to the older `priceMonthly` / `priceYearly` columns (a plan priced the old way); the admin preview does the same for the currency the plan is priced in (Codex finding 3.4). The card and the preview use this one rule whatever `billing_price_points_enabled` says: the price points that switch attaches never change the figures (Codex finding 3.3). If the price list cannot be read, the failure is logged and every card says "Price unavailable" - never an old copied figure and never "Free" (Codex finding 3.5). Every price save still copies the current prices into those columns (`adminMirrorLegacyPlanPrices()`, used by older code such as the free-trial plan list), and deleting a group's last price clears its column. After upgrade 0004, change prices in the editor, not by editing those columns in SQL. Each paid card states its plan's own price promise (`pricingPromiseText()`, decision 0020): the plan's `pricePromiseMode` / `pricePromiseMonths`, or the site-wide `billing_price_promise_default_*` settings when the plan sets none; an unknown value promises nothing. The note above the cards says "Each plan says how long you keep the price" only when every priced card states a promise, "Where a plan promises ..." when only some do, and nothing about promises when none does (for example before upgrade 0004; `pricingPromiseNote()`, recheck finding 4). A "Contact us" plan shows "From <monthly>/month" and links to its contact address, or to the `site_email` setting. Cards are drawn by `_includes/pricing_grid.php`, shared with the preview.

**No commercial values in code:** `tests/test_commercial_literals.php` fails on a starting paid-plan key or starting price in any PHP file outside `_libraries/` and the migrations (one named exception: the installer's list of starting rows). Since finding F4 of the Phase 2 review it also reads page text outside PHP tags and prices inside longer strings ("Pro is £6.99 a month", "From £99/month"), and whole numbers equal to a starting price in pence (`moneyFormat(699, 'GBP')`). It is a text check of the ways found so far, not a proof.

### Flexible pricing (#70, 2026-07-22) - BUILT BUT DISABLED

Implements the additive schema/helper delta specified in `Pricing_Strategy.md` §5 on top of the tier-gating engine above, proving five pricing STRUCTURES are representable **by data alone** - zero code change to add a real instance of any of them. Everything is **inert by default**, mirroring this codebase's existing `email_enabled`/`sso_enabled` kill-switch pattern exactly.

**Kill-switches (`tblSettings`, `billing` group - all seeded `0`):**

| Setting | Gates |
| --- | --- |
| `billing_price_points_enabled` | `pricePointsForPlan()`, and whether `fn_pricing.php` attaches each plan's price points to its card data. Since task 7n Phase 2 it no longer changes the figures shown: the pricing page reads the price list by price group either way (Codex finding 3.3) |
| `billing_discounts_enabled` | `resolveDiscountForCheckout()` - `0` = never resolves a discount, regardless of what rows exist in `tblDiscounts` |
| `billing_payg_enabled` | `creditBalanceFor()`/`consumeCreditsOr402()` - `0` = balance always reads `0`, consumption always denies |

Flip a switch to `1` (plus populate the matching new table) to bring a structure live - **no deploy needed**, the same "config, not code" discipline the tier engine already established.

**New tables (`install.sql`, Tables 22-26):**

- **`tblPlanPricePoints`** - kills the "exactly two fixed price columns, one currency" limitation. `priceKey` unique PER PLAN (not globally); `billingInterval` ENUM `monthly`/`yearly`/`lifetime`/`one_off`/`metered`; `grantsJSON` names what a one-off purchase grants (PAYG packs); `validFrom`/`validUntil` for launch-offer windows. Since task 7n Phase 2 the pricing page shows the row on sale now in each price group (`priceGroupKey` monthly / yearly), and the legacy columns only for a group with no rows (see "Plans and prices runbook").
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

**⚠️ Re-run caveat (2026-09-23, #103) - FIXED (2026-11-23, task 7n Phase 1A, #113 item 5):** this note originally said the two `ALTER TABLE ... ADD COLUMN` blocks were not safe to run a second time, and that there was no upgrade/migration script for an older database. Both of those are now fixed: the two `ALTER TABLE` blocks were moved out of `install.sql` entirely into numbered migration `0001_subscription_price_snapshot_columns.php`, `install.sql` itself is now safe to run as many times as you like (every table is `CREATE TABLE IF NOT EXISTS`, there are no more `ALTER TABLE` statements in it at all), and there IS now an upgrade tool for a database that was installed before this (or any later) delta shipped. See "🧱 Changing the database from now on" below for the full runbook - do **not** apply the two columns "by hand" any more; run the upgrade tool instead.

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
5. **Set the settings** (never commit real values - all seed EMPTY): `sso_microsoft_client_id` (the app registration's Application (client) ID), `sso_microsoft_client_secret` (the secret VALUE from step 4). Enter them on `/admin/settings/` in the **Sign in with Microsoft or Google** section (the page also shows the exact callback address to register). No script is needed.
6. **Switch it on** on `/admin/settings/` (the **Sign in with Microsoft or Google** section): tick "Show Sign in with Microsoft" and the main switch "Main switch for signing in with Microsoft or Google" (if not already on for another provider), then save. No database edit is needed.

**Owner setup to activate Google:**

1. **Create OAuth credentials** (Google Cloud Console → APIs & Services → Credentials → Create Credentials → OAuth client ID → Web application).
2. **Add an Authorized redirect URI**: exactly `https://cuercode.net/auth/callback/google`.
3. **Configure the OAuth consent screen** with `openid`, `email`, `profile` scopes (Google's non-sensitive standard scopes - no verification review needed for these alone).
4. **Set the settings**: `sso_google_client_id`, `sso_google_client_secret`. Enter them on `/admin/settings/` (**Sign in with Microsoft or Google** section), the same way as Microsoft.
5. **Switch it on** on `/admin/settings/` (the same section): tick "Show Sign in with Google" (plus the main switch), then save.

**SIGNula - pending upstream (SIGNula issue #88).** `sso_signula_discovery_url` seeds EMPTY on purpose: SIGNula.id (as verified against its own repo - see PROJECT_ROADMAP.md Epic E2) is currently a credential-proxy REST API (opaque bearer tokens via `POST /api/v1/auth/login`) with **no `/authorize`, `/token`, `/userinfo`, discovery document, or JWKS** - it is not yet an OIDC identity provider. `ssoProviderIsUsable('signula')` fails closed on the empty discovery URL regardless of its own `sso_signula_enabled` flag, so no special-case code is needed to keep it inert - flipping the flag alone does nothing until an owner also fills in a real discovery URL, which cannot happen until SIGNula ships that surface upstream.

**Why the redirect_uri is per-provider, not query-param-based.** Each IdP's app registration allowlists an EXACT redirect URI string; `https://cuercode.net/auth/callback/{provider}` gives each provider its own distinct, registerable URL (via a `.htaccess` clean-URL rewrite to a single `auth/callback/index.php?provider={provider}` dispatcher) rather than one shared callback URL disambiguated by a query parameter that an IdP's allowlist could not distinguish from another provider's callback anyway.

**Account-linking is the core security decision - see `decideOAuthAccountLinkAction()`'s docblock in `fn_oauth.php` for the full table.** Summary: an identity ALREADY linked always logs in as that user; a BRAND NEW identity only auto-creates a local account when the IdP asserts `email_verified=true` AND no local account already claims that email; a verified email that COLLIDES with an existing (not-yet-linked) local account is REFUSED (never auto-linked - the account-takeover defence) and the visitor is told to sign in normally first and link the provider from account settings instead; an unverified email is refused outright, full stop. Linking an ADDITIONAL provider onto an already-authenticated session is a separate code path that does not re-check email verification at all, because the session itself already proves account ownership.

**Why the account-settings "Link" buttons are POST forms, not plain links (the "CSRF on the initiation" requirement).** The anonymous "Sign in with ..." buttons on login/register are ordinary `<a href>` links (a plain GET) - standard OAuth-client UX, and safe because there is no existing account session for a forged request to attack; the `state` parameter is the real defence for that flow. The account-settings "Link" buttons, in contrast, affect an ALREADY-authenticated account, so `public_html/auth/index.php` requires a CSRF-token-validated POST for any authenticated caller and renders a one-click confirmation page (containing that same POST form) if it instead receives a bare GET while authenticated.

**Not built this stage (deferred):** avatar sourcing from the IdP's own profile picture (`fn_auth.php`'s `resolveAvatarUrl()` still documents this as an unreachable Tier 2, by design - out of THIS stage's explicit scope, which is account-linking + session issuance, not profile enrichment); an admin "force logout of all SSO sessions" action; SIGNula (pending upstream, see above); organisation-level SSO enforcement (the `sso` feature flag already exists per-tier in `tblSubscriptionPlans.featuresJSON` from Epic E4, seeded `true` only for the `business` tier - that flag is reserved for a FUTURE "require SSO for this organisation's members" enforcement feature and is deliberately NOT used to gate whether an individual can use the Microsoft/Google sign-in buttons built here, which is a suite-wide convenience available regardless of plan).

---

## 🧱 Changing the database from now on (task 7n Phase 1A, 2026-11-23, #113 item 5, #70)

**In plain English:** `install.sql` and `seed_data.sql` are now a **frozen
starting point** - the exact set of tables and starting rows a brand-new
database gets. From now on, **every later change to the database - a new
table, a new column, a new index, a new starting setting - is a small
numbered file**, not an edit to `install.sql`. This is what finally makes
"upgrade an existing database" a real, repeatable, safe thing to do, instead
of a one-off manual job (closing #113 item 5 and the #70 "re-run caveat" above
for good).

### Why this changed

Before this task, `install.sql` was a single, linear script. Two `ALTER
TABLE` statements had been added to the end of it (for #70) to add columns to
tables that already existed earlier in the same file. That meant:

- `install.sql` could only ever be run **once**, against a **brand-new**
  database - running it a second time failed at the first `ALTER TABLE` with
  a "duplicate column" error, and everything after that point in the file
  (more tables!) was never created either.
- There was no tool at all for bringing an **older, already-installed**
  database up to date with a newer one - the only options were "apply the
  change by hand" or "throw the database away and start again", neither of
  which is acceptable once real customer data exists.

### How it works now

1. **`install.sql` is frozen.** It only ever contains `CREATE TABLE IF NOT
   EXISTS` blocks and `SET` lines - **never** another `ALTER TABLE` or a
   second `CREATE TABLE IF NOT EXISTS \`sometable\`` added later. It is safe
   to run against the same database as many times as you like; every table
   it defines is checked, never re-created or changed, once it exists.
2. **`seed_data.sql` is also frozen, but is NOT safe to re-run.** It is a flat
   list of plain `INSERT` statements for a brand-new database's starting
   rows. Running it twice fails (duplicate key) or, with `multi_query()`,
   could stop part-way through. **Run it once, on a new database only** - the
   web installer (`public_html/install/index.php`) now checks this for you:
   before it ever touches `seed_data.sql`, it asks
   `migrationDbSeedDataAlreadyPresent()` (`_functions/fn_migrations.php`)
   whether `tblQRCodeTypes`, `tblPredefinedLogos` or `tblSubscriptionPlans`
   already has rows, and skips the seed step with a plain-English message if
   so. **Fixed 2026-11-23 (review finding F1):** this check used to also look
   at `tblSettings` - but migration `0002` (and any later settings migration)
   also inserts rows into `tblSettings`, so running `migrate.php --apply` on
   a brand-new, empty database (before the web installer ever ran) made
   `tblSettings` non-empty, which then made the web installer wrongly believe
   `seed_data.sql` had already run - it would skip it **forever**, and the QR
   code types, predefined logos and subscription plans would never be
   loaded. The check now only ever looks at tables `seed_data.sql` fills
   (`migrationSeedProtectionTables()`). **Made precise 2026-11-26 (task 7n
   Phase 2, migration `0004`):** a migration MAY now write to those tables,
   but only in a way that cannot put a row into an EMPTY one - by copying
   a row already in the same table (`INSERT INTO t (...) SELECT ... FROM
   (SELECT ... FROM t WHERE ...) AS x ON DUPLICATE KEY UPDATE ...`; the inner
   query is needed because MySQL 8.0 and MariaDB 10.6 both reject the plain
   form as ambiguous) or by an `update_if_unchanged` step. **No aggregate
   function, `GROUP BY` or `HAVING` in such a step** (review finding F2,
   2026-11-27): `MAX()` or `COUNT()` with no `GROUP BY` returns one row even
   from an empty table, so it would fill it. The runner's validator refuses
   anything else (`migrationStepCouldFillEmptySeedTable()` - a careful text
   check of the shapes found so far, not a full SQL parser),
   and `tests/test_migrations.php` checks every real migration file against
   it. So `migrate.php --apply` on a brand-new database still leaves those
   tables empty. **The other half:** in that order the upgrades' starting-data
   steps found no plans to work on (for example `0004` copies Business into
   Enterprise), and they are already recorded as applied. So when the web
   installer loads `seed_data.sql` it also runs the `insert_if_missing` and
   `update_if_unchanged` steps of every migration already recorded, once more,
   which gives exactly the result of a normal install. Since finding F1 of the
   Phase 2 review (2026-11-27) both happen in ONE transaction, under the
   upgrade lock (`migrationDbRunSeedSql($db, $seedSql, $migrationsDir)`): if
   another upgrade is running nothing is loaded, and if a repeated step fails
   the starting data is rolled back too, so the reload the installer asks for
   loads both again (before, a failed repeat left the starting data committed,
   the reload skipped the repeat and the installer locked itself without the
   Enterprise plan). Data steps must therefore stay plain `INSERT`/`UPDATE`
   on InnoDB tables (checked before anything is written). Write every data
   step so that running it again is harmless - the rules below already
   require that.
3. **Every later change is a migration file**, one file per change, at
   `_includes/sql/migrations/NNNN_short_name.php` (four digits, then an
   underscore, then lower-case words - e.g.
   `0003_plan_price_points.php`). Each file `return`s an array:

   ```php
   <?php
   // _includes/sql/migrations/0003_short_name.php (+ full house file header)
   return [
       'key'         => '0003_short_name',                 // must match the file name
       'description' => 'One sentence saying what this does and why',
       'steps'       => [
           [
               'type'  => 'add_column',                     // see the step types below
               'table' => 'tblSomething',
               'name'  => 'newColumnName',
               'sql'   => 'ALTER TABLE `tblSomething` ADD COLUMN `newColumnName` INT NULL AFTER `existingColumn`',
           ],
           // ... one step per column / index / foreign key / starting row
       ],
   ];
   ```

4. **Step types** (anything else is refused - nothing runs):

   | Type | What it does | The SQL must... |
   | --- | --- | --- |
   | `create_table` | A brand-new table | start with `CREATE TABLE IF NOT EXISTS` |
   | `add_column` | One new column on an existing table | be a single `ALTER TABLE ... ADD COLUMN ...` (exactly one column) |
   | `add_index` | One new index/unique key | be a single `ALTER TABLE ... ADD INDEX`/`ADD UNIQUE KEY ...` |
   | `add_foreign_key` | One new foreign key | be a single `ALTER TABLE ... ADD CONSTRAINT ... FOREIGN KEY ...` |
   | `insert_if_missing` | One new starting row | start with `INSERT INTO ...` and end with `ON DUPLICATE KEY UPDATE` `` `x` = `x` `` (a no-op, so a row the owner already edited is never touched) |
   | `update_if_unchanged` | Changing a seeded value, but only if nobody has touched it since | start with `UPDATE ... SET ...` and have a `WHERE` that only matches rows still at their OLD seeded value |
   | `widen_decimal_column` | Making a money column hold MORE digits (decision 0042, upgrade 0006) | be a single `ALTER TABLE ... MODIFY COLUMN ... DECIMAL(p,s) NOT NULL [DEFAULT n] [COMMENT '...']` (or `NULL`, `DEFAULT NULL`), restating the column's default and description, and nothing else after them (third-round review, Info 2: no `INVISIBLE`, no computed-column words, no text default, no `AFTER`/`FIRST`). Skipped when the live column already has at least `s` decimal places and `p - s` whole digits; **refused (not run, the upgrade not recorded)** unless the live column is a DECIMAL with no more of either and the same NOT NULL - so it can only ever widen |

   Every step is checked against the live database first ("does this column
   already exist?") and skipped if the work is already done - that is what
   makes every migration safe to run more than once. Words that undo
   something (`DROP`, `TRUNCATE`, `RENAME`, `MODIFY`, `CHANGE`, `DELETE FROM`)
   are refused outright: **migrations only ever add** (the one exception is
   `MODIFY` in a `widen_decimal_column` step, above, which only ever widens). If an existing column,
   row or table genuinely needs to change shape, write a NEW migration that
   adds the new shape alongside the old one, rather than editing history.

5. **Never edit a migration file once it has been applied anywhere** (even
   just on your own dev database) - the runner fingerprints every file
   (SHA-256) the moment it applies it, and reports an edited file as a
   warning ("changed after it was applied") without ever running it again.
   If you need to fix a mistake, write a new, higher-numbered migration.
   The one exception so far is upgrade 0004, corrected in place on
   27 November 2026 before any deploy (decision 0036 in `docs/decisions/`).
   **The web installer refuses a database like that** (recheck finding 1 of
   the 7n Phase 2 review): before it changes anything it asks
   `migrationDbChangedAppliedUpgrades()` whether any applied upgrade's file
   has changed since, and if so it stops, changes nothing, does not lock
   itself and says to start again with an empty database.
   **Rebuild any database that applied the 26 November 2026 version of
   0004** (a developer's own or a throwaway one; no live database exists).
   It keeps the draft price points and the old starting plan descriptions
   for ever, because the corrected 0004 never runs on it again, and the
   upgrade tool only warns. To rebuild: drop the database, create it again
   empty, then run the web installer (or `migrate.php --apply` followed by
   the installer).

6. **Running the upgrade tool** - the command line (`_includes/cli/migrate.php`,
   run with the command-line `php`, never from a URL - it lives outside
   `public_html/` and is also blocked by `_includes/.htaccess`):

   ```bash
   php _includes/cli/migrate.php --status     # list what is applied and what is waiting; does not change anything
   php _includes/cli/migrate.php --dry-run    # show exactly what --apply would do; changes nothing
   php _includes/cli/migrate.php --apply      # apply every waiting migration, in number order
   php _includes/cli/migrate.php --help       # usage
   ```

   The web installer (`public_html/install/index.php`) also calls this
   automatically, straight after loading `install.sql`/`seed_data.sql`, using
   `appliedBy = 'installer'` - so a fresh install always ends up fully
   up to date, and re-visiting the installer later (if its lock file is ever
   removed, e.g. to re-run after a later code update) safely tops up an
   existing database too.

   **Exit codes:** `0` up to date / applied / dry run finished | `1` a step
   (or the database) failed - fix the problem and run `--apply` again, every
   step is safe to repeat | `2` refused, **no upgrades were applied** (not run
   from the command line, another upgrade already running, the database is
   older than the supported starting point, a broken migration file, or a bad
   option) | `3` (`--status` only) there are migrations waiting.

   **Wording note (review finding F5):** a refusal says "no upgrades were
   applied", never "nothing was changed" - the starting tables (from
   `install.sql`) or the `tblSchemaMigrations` ledger table itself may
   already have been created in the same run, just before the refusal
   happened, so "nothing changed" was not always strictly true.

7. **If a step fails part-way through a migration:** the whole migration is
   NOT recorded as applied (even the steps that did succeed stay changed in
   the database - that is fine, because every step re-checks "is this already
   done?" on the next run). Fix the underlying problem, then run `--apply`
   again; it picks up exactly where it left off.

8. **If `--apply`/`--status` refuses with "this database does not match the
   supported starting point"** (exit code 2), it means the database predates
   `install.sql`'s current shape by more than migrations can bridge - one of
   `install.sql`'s own starting **columns** is missing (not just a later
   migration's column). The missing columns are listed by name; that database
   needs a genuine one-off catch-up before the normal migration tool can run
   against it again. (A missing **table**, by contrast, is recreated
   automatically from `install.sql`.)

   **⚠️ A re-created table is still EMPTY (review finding F1, fixed
   2026-11-23):** re-creating a missing table only gives it back its
   *structure* - never its *starting data*, which only ever comes from
   `seed_data.sql`, run once by the web installer. If `migrate.php --apply`
   ever creates a table this way, it prints "Starting data not loaded: ...
   run the web installer" straight away - this is exactly what happens the
   first time `--apply` is run against a brand-new, completely empty database
   (every one of `install.sql`'s tables counts as "missing" and gets
   created), which is why running `migrate.php --apply` on its own is never a
   substitute for running the web installer at least once.

9. **Two upgrade runs at the same time:** the tool takes a MySQL named lock
   before doing anything; a second run while one is already in progress is
   refused ("busy") rather than risking two runs changing the same table at
   once. **The lock name includes the database (fixed 2026-11-23, review
   finding F6):** named locks are server-wide, not per-database, so without
   this an upgrade running against one database on a shared MySQL server
   could needlessly block - or be confused with - an upgrade running against
   a completely unrelated database on that same server. See
   `migrationLockNameSqlExpression()` in `_functions/fn_migrations.php` for
   exactly how the per-database name is built (and kept under MySQL's
   64-character limit).

**Where to look:** `_functions/fn_migrations.php` (the engine - read its own
file header first, it explains the two-halves design: pure file/SQL-text
checks vs. the small set of database functions), `_includes/sql/migrations/`
(the migration files themselves, in order - `0001` moved the #70 snapshot
columns out of `install.sql`; `0002` added `tblAdminAuditLog` and the task 7n
Phase 1A admin settings), `tests/test_migrations.php` (the DB-independent
unit tests - every refused SQL word, every step type, the "what is waiting"
plan, exit codes, report text), `tests/integration/migrations_real_mysql.php`
(the real-database check - every acceptance bullet above, against a real
MySQL server; see `tests/README.md` for how to run it).

---

## 🛡️ Admin area and the first Global Admin (task 7n Phase 1B, 2026-11-24, #31)

**In plain English:** there is now a private admin area at `/admin/`. Only a
**Global Admin** can open it. Today it has three pages: an **overview** (what
is set up and what is not), **Confirm password** (unlocks editing) and
**Database upgrades** (applies waiting upgrades without needing the server
command line). The settings screen for the email, sign-in, bot-protection and
PayPal keys is the next phase (1C). Plan: `.claude/plans/7n-admin-commerce-plan.md` §6.

### First Global Admin

No web page can create the first admin: a "first visitor becomes admin" page
would let a stranger claim a fresh site before you do. So the first one is
made on the server:

1. Install as usual (`/install/`, which also applies the database upgrades),
   or on an existing site run `php _includes/cli/migrate.php --apply`.
2. Sign up normally at `/register/`. The account stays **pending**, because
   email sending is not set up yet, so no verification email can arrive.
3. Over SSH, from the site folder:

   ```sh
   php _includes/cli/admin_access.php grant you@example.com --activate
   ```

   It shows the account's email address, display name and **when it was
   registered**, then asks you to type the email address again (add `--yes`
   to skip that). `--activate` also marks the account active with a checked
   email address - allowed here because whoever can run commands on the
   server already controls the site.

   **A waiting (`pending`) account gets a NEW password (decision 0010).** A
   stranger could sign up with your email address before you do (the sign-up
   page gives the same reply either way), and their password would then open
   your admin account. So when `--activate` vouches for an email address
   nobody has proved, the tool shows the warning *"if you did not register
   this account at that time, stop"*, then asks you to type a new password
   twice. Your typing is hidden. The same password rules as sign-up apply,
   and you get three tries. In the **same transaction** as the role change, it then:
   - stores the new password; the old one stops working;
   - ends every signed-in session of that account;
   - switches off its API keys;
   - cancels any unused password-reset links;
   - removes any linked Microsoft/Google sign-ins and passkeys.

   The change-log row says `password_reset: true` and how many of each
   were removed. It never holds the password. This needs a **real terminal**
   (an SSH session you are typing in). From a script, a pipe or a scheduled
   job it refuses before asking anything. There is deliberately no option to
   give the password any other way. An account that is already active, with a
   checked email address, is not reset.

   **`grant` without `--activate` is refused** for an account that is not yet
   active with a checked email address (exit 2, nothing changed). Otherwise it
   would hand the Global Admin role to an account whose password may be a
   stranger's, and the ordinary emailed verification link could then activate
   it without the new-password step. As a second lock, `verifyEmailToken()`
   also refuses to activate any account that already holds the Global Admin
   role, so that link can never be the way in.

   It refuses (exit 2, nothing changed) when no account or more than one has
   that email, when the account is suspended or deleted, when it has no
   password (a Global Admin must re-type a password before every change), and
   when the database has not been upgraded yet (it needs the admin change log
   table). The role change and its change-log row are written together in one
   transaction.
4. Sign in with the **new** password, open `/admin/` (or "Admin" in your
   account menu), and confirm your password to unlock editing.

Other commands: `list` (the Global Admins, email addresses partly hidden),
`revoke <email>` (refuses to remove the **last** working Global Admin) and
`--help`. Exit codes: **0** done or nothing to do, **1** a database problem
(nothing half-done; run it again), **2** refused (see the message). On
DreamHost the command-line PHP is probably `/usr/local/php84/bin/php` (not yet
checked on the live server).

**LAST RESORT ONLY - no SSH at all: phpMyAdmin** (DreamHost panel). Use the
command line above whenever you possibly can. This route **cannot set a safe
password**. It keeps the password the account was registered with, so it
has the weakness decision 0010 fixes: if a stranger registered your email
address first, their password becomes an admin password. It also does not
end earlier sign-ins. If you must use it:

1. Check the account is really yours **before** changing anything. Look at
   `createdAt` (UTC) and `displayName`. If you did not register it at that
   time, or the name is not yours, **stop**.

   ```sql
   SELECT `userID`, `email`, `displayName`, `createdAt`, `status` FROM `tblUsers` WHERE `email` = 'you@example.com';
   ```

2. Type the email address carefully; this changes whichever account has it:

   ```sql
   UPDATE `tblUsers` SET `roleID` = 1, `status` = 'active', `emailVerifiedAt` = COALESCE(`emailVerifiedAt`, UTC_TIMESTAMP()) WHERE `email` = 'you@example.com' AND `status` IN ('pending','active') AND `passwordHash` IS NOT NULL;
   ```

   It should say "1 row affected".
   Then **look for anything a stranger could have added** while the account
   was open to them, and remove it. Use the `userID` from step 1:

   ```sql
   -- API keys (anything you did not create yourself is the stranger's)
   SELECT `apiKeyID`, `apiKeyPrefix`, `isActive`, `createdAt` FROM `tblAPIKeys` WHERE `userID` = 123;
   DELETE FROM `tblAPIKeys` WHERE `userID` = 123;

   -- Linked Google / Microsoft sign-ins
   SELECT `provider`, `linkedAt` FROM `tblUserOAuth` WHERE `userID` = 123;
   DELETE FROM `tblUserOAuth` WHERE `userID` = 123;

   -- Passkeys, other sign-ins and unused password-reset links
   DELETE FROM `tblWebAuthnCredentials` WHERE `userID` = 123;
   UPDATE `tblUserSessions` SET `revokedAt` = UTC_TIMESTAMP() WHERE `userID` = 123 AND `revokedAt` IS NULL;
   UPDATE `tblPasswordResets` SET `usedAt` = UTC_TIMESTAMP() WHERE `userID` = 123 AND `usedAt` IS NULL;
   ```

   (Replace `123` with the real `userID`. These names were checked against
   `install.sql`; if one is rejected, `SHOW COLUMNS FROM` that table.)
3. **Straight after, sign in and set a fresh password.** Use "Change
   password" at `/account/`, which also ends every other sign-in. Or use the
   "Forgot password" email, once email sending works. If your own password
   does **not** sign you in, somebody else registered the account first.
   Remove the role at once, then use the command line as soon as you have
   SSH access:

   ```sql
   UPDATE `tblUsers` SET `roleID` = NULL WHERE `email` = 'you@example.com';
   ```

A Global Admin made this way has no "grant" row in the change log, so the
first time they open the admin area the site writes one `admin.first_seen`
row (actor "system"). The log is never silent about how someone became an
admin.

### How the admin area protects itself

- **Who gets in** - checked fresh from the database on every request, never
  remembered in the PHP session: `roleID = 1`, account `active`, email
  checked, and a valid signed-in session. `roleID = 2` ("Admin") gets no
  admin access yet. Removing someone's role takes effect on their next click.
- **Not signed in** → sent to the sign-in page, then back. That includes
  `pending` and `suspended` accounts, because `currentUser()` returns nothing
  for an account that is not `active`. **Signed in (active) but not a Global
  Admin** → the ordinary "page not found" page, with the same headers as any
  missing page, and at most one `admin_denied` activity-log row per browser
  session. The sign-in redirect means `/admin/` is not secret; the gate is the
  protection (decision 0009).
- **Every change needs your password again** if you have not typed it in the
  last 15 minutes of activity, and at most every 60 minutes (settings
  `admin_reauth_idle_minutes` 5-60 and `admin_reauth_max_minutes` 15-480).
  The unlock lives in the PHP session the cookie points at (and is tied to
  that sign-in's database session): a cookie stolen while editing is unlocked
  can make changes for up to the idle limit (15 min) and at most the absolute
  limit (60 min). Tying it to the database session only defeats signing in
  again, which starts locked. Five wrong passwords in one session
  sign you out, and wrong passwords also count toward the normal account
  lock-out. "Lock editing now" on the Confirm password page locks it at once.
- **Every change form** carries the CSRF token, is refused if the browser
  says it came from another website (the `Origin` header), and never changes
  anything from a plain link (GET).
- **Change log** (`tblAdminAuditLog`): who, when, what it was and what it
  became, written in the same transaction as the change. Secret values are
  never written - only "secret set / replaced / cleared". Nothing in the code
  edits or deletes these rows (the database cannot enforce that on shared
  hosting, where there is only one database user). Action names are a fixed
  list in `adminAuditActionNames()`.
- **Headers**: `public_html/admin/.htaccess` and the PHP code both send
  `Cache-Control: no-store`, `X-Frame-Options: DENY`, `X-Robots-Tag: noindex,
  nofollow` and a content policy with `frame-ancestors 'none'`. Each is sent
  ONCE: the site file uses plain `Header set` (kept separately by Apache and
  applied to successful answers only), so on a normal page "Header always
  set" alone sent the site value AND the admin value. The admin file now
  removes each one (`Header unset` and `Header always unset`) before
  `Header always set`, and switches `mod_expires` off so static files get no
  second `Cache-Control`. Checked on a real Apache 2.4.71 in Docker (200 and
  404 inside `/admin/`: one of each header; a page outside `/admin/` is
  unchanged). The earlier claim that "always set replaces the site value" was
  wrong. The admin
  content policy is the site policy with that one change; a test keeps the
  two in step. `/admin/` is deliberately not in `robots.txt`.
- **No API**: there is no `/api/v1/admin` route and none in `openapi.json`
  (a test checks); admin pages ignore API keys and use only the browser
  session.
- **A Global Admin cannot erase their own account** at `/account/` (or with
  `POST /api/v1/account/erase`): it is refused with `409
  GLOBAL_ADMIN_CANNOT_ERASE` and nothing changes. Remove the admin role first
  (another admin, or `revoke` on the command line); the last admin can never
  be removed.

### Database upgrades from the admin area

`/admin/upgrades/` lists waiting upgrades in plain English and applies them
with one button (after confirming your password and ticking "I have a recent
backup"). It is the same engine as `migrate.php` and works even before the
change log table exists (the gate only needs the user tables). It records
`admin:<userID>` in `tblSchemaMigrations` and a `schema.migrate` row in the
change log. Raw database errors are never shown on the page; the full detail
goes to the error log. **While any upgrade is waiting, every other admin page
must refuse to save** (`adminDatabaseReadyForChanges()`, used from Phase 1C),
so no change can ever happen without its change-log row.

### If you lose access

- Forgot the password: use "Forgot password" once email works; before that,
  ask another Global Admin, or reset it over SSH/phpMyAdmin the usual way.
- No working Global Admin at all: run `grant` again on the command line (or
  the phpMyAdmin line above).

**Where to look:** `_functions/fn_admin.php` (everything: the gate, the
password re-confirmation, the change log, the UK-time date helpers, the
command-line decisions - read its file header first),
`_includes/cli/admin_access.php`, `public_html/admin/`,
`_includes/admin_nav.php`, `public_html/assets/js/cuercode-admin.js` (comfort
only; every page works with JavaScript off). Tests: `tests/test_admin_access.php`
(no database) and `tests/integration/admin_access_real_mysql.php` (real
database; see `tests/README.md`).

---

## 🌐 Website address, waiting emails and other fresh-install dry-run fixes (2026-11-25, queue B9, #113)

A dry run of a fresh install on MySQL 8.0 and MariaDB 10.6 found these; each is fixed with tests.

- **`site_url` is now on `/admin/settings/`** (first section, "Website address"; registry kind `site_url`, validator `adminSettingsValidateSiteAddress()`). Emailed links (`buildAccountActionUrl()` in `fn_auth.php`) and the sign-in callback addresses (`ssoRedirectUri()` in `fn_oauth.php`, shown on the settings page) are built from it, and it was seeded as `https://cuercode.net` with no screen to change it. NOTE: alpha, beta and live all load the same `_includes/bootstrap.php` and one `_auth_keys/auth_creds.php`, so they share ONE database and ONE `site_url`; it must stay the live address unless a copy has its own database. It reuses the discovery-address checks (https only, a proper public name with at least two parts, no IP number, no `localhost`/`.local`/`.internal`/`nip.io` style names, no user name, port 443 only) and adds "nothing after the name": a path, `?` or `#` part is refused, one trailing `/` and `:443` are removed, and the name is lower-cased, so the stored value is always `https://name.example`. Saved and audited like every other setting (`setting.update`, old and new value). The section count is now nine. The readers still fall back to `https://cuercode.net` when the row is missing, and `INSTALL.md` step 6.4 tells the owner to set it before turning email on.
- **`admin_access.php grant --activate` cancels the account's waiting emails** (`adminCliCancelUnsentAccountEmails()` in `fn_admin.php`). Registering queues a verification email; activating at the command line made it pointless. In the grant's own transaction, rows for that address with template `verification` or `password_reset` that are `queued`, or `failed` with attempts left (the same rule as `skipExpiredEmailQueueRows()`), become `skipped` with their stored details cleared. A row being sent (`sending`), already sent, given up, of another template or for another address is untouched. The audit row gets a new `emails_cancelled` entry with the two counts only (`verification_emails_cancelled`, `password_reset_emails_cancelled`); the five-count `access_removed` entry (decision 0010) is unchanged. A forced failure rolls the cancel back with everything else (tested).
- **Verify page wording.** A used-up link now says "This link has already been used or has been replaced by a newer one. Please use the newest email we sent you, or ask for a new link." The token table keeps only `usedAt` for "used", "retired by a newer link (resend)" and "stopped after too many wrong passwords", so the three cannot be told apart without a schema change; the wording covers all of them. The error code `VERIFICATION_ALREADY_USED` is unchanged.
- **Cron runner help** now says exit 0 also covers "failed this time, will retry" (the summary line says "will retry"; `--quiet` prints nothing for it), and that it is the runner's printed output that has no addresses, while the activity log in the database keeps full recipient addresses (logging not changed).
- **API keys.** A key created with no scopes still works as before (no scope means no access is deliberate, fail-closed; the default was NOT widened), but the create message now carries a warning, and the account page and the developer guide say plainly that a key needs at least one scope, scopes cannot be edited, and the one-key free plan means revoke-then-create.

## 🔑 Entering secrets: the settings editor (task 7n Phase 1C, 2026-11-25, #31, #29)

`/admin/settings/` is how the owner enters the email, sign-in, bot-protection and PayPal details, the email clean-up periods, the VAT wording, the free-trial settings and the admin limits. Code: `_functions/fn_admin_settings.php` (the registry, validators, saving, the two test actions, the HTML builders) and `public_html/admin/settings/index.php`. Plan: `.claude/plans/7n-admin-commerce-plan.md` §6.6, §6.7, §7.2.

**How a secret box behaves.** It is always drawn EMPTY and never receives the saved value (the page code only ever learns "has a value" and the last-changed time; `adminSettingsStateFromRow()` drops the stored text for a secret row on load). An empty box means "keep what is saved". Typing replaces it. Ticking "Remove this secret" clears it (typing and ticking together is refused). The text is trimmed; control characters and line breaks inside it are refused; at most 2,048 characters. An error message never repeats what was typed, and the page is never drawn again with a typed secret in it. The tenant ID must be a GUID or a domain name; the app ID must be a GUID. JavaScript only adds a "Show what I typed" button (hidden without JavaScript).

**One save = one transaction.** Each section is one form. The changes of one section are saved together: every setting plus its audit row, or none. The audit action is `setting.update` for ordinary settings and `setting.secret_set` / `setting.secret_replace` / `setting.secret_clear` for secrets; a secret's audit row only says `{"secret":"set"}`, `replaced` or `cleared`. Unchanged boxes write nothing. After the commit the section's `afterSave` actions run: a change to any `graph_*` setting clears `graph_token_cache`; a change to a discovery address clears that provider's `sso_<provider>_discovery_cache` and `_jwks_cache` (and only that provider's).

**Every POST**: CSRF token, then the Origin header, then a fresh password confirmation, then validation, then the transaction, then a redirect with a one-time message. While upgrades are pending or `tblAdminAuditLog` is missing the page shows "Database upgrade needed" and refuses to save.

**What can never be changed here (even by a hand-made request):** `billing_price_points_enabled`, `billing_discounts_enabled`, `billing_payg_enabled`, `dynamic_qr_enabled` (shown "Off (locked)"), every key ending `_cache`, `graph_token_cache`, `app_version`, `app_phase`, `api_version`, `encryption_algorithm`, any row with `isEditable = 0`, and any key that is not in the registry (`adminSettingsRegistry()`). A secret whose row is not flagged sensitive is refused (it must never be stored as plain text).

**Adding a setting to the screen:** add a seed row (a migration, `insert_if_missing`) AND an entry in `adminSettingsRegistry()` with the SAME group, type and sensitivity - `tests/test_admin_settings.php` compares the registry with `seed_data.sql` and migration 0002.

**The two test buttons.** "Check Microsoft connection" clears `graph_token_cache`, calls `getGraphAccessToken()` and says Connected or Could not connect (never the token, never Microsoft's error text; it works while email is off; audit `setting.test_graph`). "Send me a test email" sends a fixed message to the signed-in admin's own address only and needs `email_enabled = 1` (audit `setting.test_email`). Both take a stand-in function in the tests, so they are tested without Microsoft. Limits and audit order are in "Second independent review of the settings editor" below.

**Deviation from the plan (decision for the reviewer).** `trusted_proxy_ips` (decision 0014) has no seeded row and no "Security" section in plan §7.2, so it is in the **Admin security** section as "Trusted forwarding servers"; the screen creates the row on first save (group `security`, string). Each entry is checked with `clientIpParseRangeList()` and the saved list is tidied to `a, b, c`.

**VAT wording.** `pricingVatNote()` (`fn_pricing.php`) turns `billing_vat_mode` into "Prices include VAT" / "Prices exclude VAT" / nothing; `/pricing` shows it straight away.

**Tests.** `tests/test_admin_settings.php` (1,074 checks, no database) and `tests/integration/admin_settings_real_mysql.php` (213 checks; see `tests/README.md`). Not covered: a real browser, and any real Microsoft call.

### Second independent review of the settings editor (2026-11-25, #31)

- **Lost updates (plan §7.5).** Every section form carries a hidden `fp` (a SHA-256 of the section name plus, for each setting, its saved value or - for a secret - only "set / not set", plus a per-setting **change marker**: a hash of the stored value (for a secret, the ciphertext, which is new on every save) and `updatedAt`). `updatedAt` alone is second-precision, so it is not trusted by itself. `adminSettingsSaveSection($admin, $sectionId, $fingerprint, $changes)` opens the transaction, locks the section's rows with `SELECT ... FOR UPDATE` (ordered by key, so two saves lock in the same order), recomputes the fingerprint and, if it differs (or is missing), refuses with `stale = true` and writes nothing. The page then re-draws the form with what was typed (never a secret) and keeps the OLD fingerprint, so pressing Save again is refused again until the page is reloaded. A plain hash (not an HMAC) is deliberate: the admin is already signed in and re-confirmed; this guards against honest overlap. Rows that do not exist yet count as "missing", so the first save of a row the screen creates also changes the fingerprint. `adminSaveSetting()` now opens its own transaction BEFORE it reads the row and reads it `FOR UPDATE`, so "set" versus "replaced" is decided from the row as it really is.
- **Test limits and audit order.** Both test actions write an audit row FIRST (outcome `failed`, reason "Test started (the result is in the next entry)"; since the final review (N-2) its action is `setting.test_graph.started` / `setting.test_email.started`, and (N-1) the limit is counted AFTER it is written, including it - a press refused by the limit adds a `refused` row and counts); if that row cannot be written the action is not run (no email sent) and the answer says so. A second row records how it ended (reason "Test finished"); if only that one fails the answer says the result is not in the change log. The limits count the FIRST kind of row from the admin's own `tblAdminAuditLog` rows (so signing in again does not reset them): 10 connection checks and 5 test emails per 10 minutes (the test email also still keeps its session list; the stricter of the two applies). The age of a row is worked out by the database (`TIMESTAMPDIFF(SECOND, createdAt, NOW())`), so there is no time-zone sum in PHP. The detail of a failed email is in the activity log (type `email_failed`, written by `logEmailAttempt()`), and the messages now say so.
- **Discovery addresses** (`adminSettingsValidateHttpsAddress()`): https only; a website NAME with at least two labels whose last label starts with a letter (so `127.1`, `0x7f.1`, bare IPv4/IPv6 and `[::1]` are refused); no port except `:443`; no user name or password; no backslash; ASCII only (an international name must be written `xn--...`).
- **Other tidy-ups.** Every section has its own save-button words (`saveLabel`). A drop-down whose saved value is not in the list shows `Current value (not in list): ...` selected (so saving never silently changes it); an empty required choice leads with "Choose...". Required boxes say "(required)" and carry `aria-required`. Every settings form has `data-admin-dirty-check`; the "Remove this secret" tick has `data-admin-confirm-check` (asks "Remove this stored secret?"; with JavaScript off the tick simply works). Text boxes and secrets refuse control characters, invisible formatting characters and the Unicode line/paragraph separators (`[\p{Cc}\p{Cf}\x{2028}\x{2029}]`, `u` flag) and invalid UTF-8 (the multi-line forwarding box still allows ordinary line breaks and tabs). Whole numbers and decimals are canonical only: `01`, `000030` and `030` are refused with the same message (a stored older spelling is drawn the one allowed way). A sender address written with an IP number in brackets (`a@[127.0.0.1]`) is refused. `#[\SensitiveParameter]` is now also on `adminSettingsIsGuid`, `adminSettingsIsDomainName`, `adminSettingsProcessSection($post)`, `adminSettingsSaveSection($changes)`, `convertValueForStorage()` and `oauthExchangeCodeForTokens($clientSecret)`.
- **Emailed-link limits are editable** (Admin security section): `verification_resend_*` and `password_reset_request_*` (`_min_gap_seconds`, `_daily_cap`, `_ip_per_hour`). Their allowed range and default come from ONE place, `authLinkRequestLimitBounds()` in `fn_auth.php` (10-86400 / 1-50 / 1-1000; defaults 60 / 5 / 10), read by both the code that enforces them and the editor, so they cannot disagree. Rows are not seeded (`rowOptional`): with no row the code uses its default; the first save creates the row (group `security`, integer).
- **Registry completeness test** now reads EVERY migration (not only 0002), and checks that a `rowOptional` key's name appears nowhere in `seed_data.sql` or any migration.
- **Migration numbers:** a one-line note at the top of plan §5 says later migrations are numbered from 0004 (0003 is `0003_sessions_valid_from`).

---

## 📦 Fresh install: committed libraries, installer checks, credentials template (2026-11-25, #113, decisions 0011 and 0012)

- **Libraries are committed** in `web/CueRCode.net/_libraries/` (chillerlan/php-qrcode 5.0.5, php-settings-container 3.2.1, FPDF 1.86, tc-lib-barcode 2.12.0, tc-lib-color 2.13.1): runtime code and licence files only. `_libraries/PINNED_VERSIONS.json` holds each version and a fingerprint of every file; `php tools/check_vendored_libraries.php` checks them (CI runs it instead of cloning) and `--update` rewrites the fingerprints after a deliberate upgrade (the monthly watch #139 proposes upgrades: replace the folder, run `--update`, review the diff). Test: `tests/test_vendored_libraries.php`.
- **Installer checks** (`_functions/fn_install_checks.php`, tested by `tests/test_install_checks.php`): PHP extensions `mysqli, mbstring, gd, json, openssl, zip, bcmath, curl` each with a plain-words purpose; after connecting but BEFORE any SQL it refuses MySQL below 8.0.13 and MariaDB below 10.6 (handles the `5.5.5-` MariaDB prefix, refuses an unreadable version); `_uploads` and `_backups` must exist and be writable. MariaDB 10.6 itself has not been run against yet (B9).
- **Credentials template:** `_auth_keys/auth_creds.example.php.dist` (placeholders only, not ignored; `auth_creds.php` still is). Test: `tests/test_credentials_template.php`.
- **Fresh install order:** deploy; create `auth_creds.php` from the `.dist` template; open `install/` by renaming `install/.htaccess` to `.htaccess.off`; run the installer (all rows green); rename it back (do NOT delete `install/`, see below). Each later deploy re-uploads `install/` with its deny-all `.htaccess`, so confirm it is in place.
- **Installer lock (review fix M8):** the lock file is now `_auth_keys/.installed.lock` (outside `install/`, not in git - `.gitignore` lists it since review finding 9 - and never overwritten by a deploy, because the SFTP mirror only adds and updates files). The old `install/.installed.lock` still counts, so earlier installs stay locked. If `_auth_keys/` is not writable the installer falls back to the old place and says so; **if neither `_auth_keys/` nor `install/` can be written, it refuses to start** (review finding 9, `installLockFolderCheck()`), rather than installing and then being unable to switch itself off. **All copies of the site share ONE lock:** `public_html`, `public_html_dev_alpha` and `public_html_dev_beta` all sit in `web/CueRCode.net/` and use the same `_auth_keys/`, so installing any one of them locks the installer in all of them (to re-run it for another copy against another database, rename the lock aside first and put it back afterwards). Chosen over a database marker because a partly finished install (seed loaded, upgrades failed) has database rows too, and a database check would then wrongly refuse the legitimate retry. Deleting `install/` is advised against: a deploy brings it back, and on an old install it took the lock with it.
- **Installer server-side re-check (review fix M7):** the POST handler runs `installPreflightProblems()` (PHP version, the eight extensions, `_uploads` and `_backups` writable) and refuses with the same messages the page shows, before any credentials load or database call. Hiding the button was not a safeguard.
- **Not checked:** the installer page in a browser or on a live database (B9 dry run); DreamHost's alpha/beta hostnames are not recorded in the repo (open on #150); deploy re-uploads `install/` every time (a follow-up could exclude it).

## ✉️ Asking for a new verification link (2026-11-25, #128)

- One token path: `issueEmailVerificationLink()` in `fn_auth.php` is used by `registerUser()` and `requestNewVerificationLink()`; the page is `/resend-verification/` (linked from verify-email and sign-in). Same answer for every address; earlier unused links stop working.
- Settings (group `security`, NOT seeded: defaults live in code and are clamped; editable on `/admin/settings/`, Admin security section, since the second settings review): `verification_resend_min_gap_seconds` (60), `verification_resend_daily_cap` (5), `verification_resend_ip_per_hour` (10).
- The per-IP count reads `tblAPIRateLog` rows with `endpoint = 'page/resend-verification'` (written by `recordAPIRequest()`), not `checkRateLimit()`, which would count all anonymous API traffic from that address. `fn_api_auth.php` is loaded lazily by `requestNewVerificationLinkWork()` for `recordAPIRequest()` (a test pins that it is not loaded globally); the visitor address itself now comes from the always-loaded `fn_client_ip.php` (decision 0014, below).
- Quirks left as found: `queueEmail()` writes an `email_queued` activity row that names the recipient. No bot-protection widget (the other forms have none). No API route (OpenAPI unchanged). (Fixed 2026-11-25 by decision 0014: login and forgot-password used to fall back to `REMOTE_ADDR` because `getClientIP()` was never loaded there.)

## 🔑 Verification link needs the password; a reset removes every other way in (2026-11-25, decision 0013, #31 / #128)

**The problem (Phase 1B review finding I2):** a stranger can sign up first with someone else's email address (the sign-up page answers the same either way). The real owner then asks for a new verification link, clicks it, and the account became active with the STRANGER's password; the stranger could then sign in, link a Google/Microsoft sign-in and make an API key that survived a later "Forgot password".

**What happens now:**
- **Verification link:** `verifyEmailToken($rawToken, $password)` - an account that has a password is activated only when the person also types that password. A missing or wrong password, a bad link and a locked account all get ONE message that points to "Forgot password". Nothing typed does not count as a try; each wrong password counts toward the normal sign-in lock-out (`recordFailedLogin()`), and the wrong password that reaches `max_login_attempts` also stops the link (a `verification_link_stopped` activity row, account number only). The link is used up only when the account is activated; claiming it and activating are one transaction. An account with no password (Microsoft/Google only - SSO accounts are in fact created active, so this is belt and braces) still activates with the link alone. The decision itself is the pure `emailVerificationPasswordOutcome()`.
- **The page** (`/verify-email/`) shows a password box unless `emailVerificationTokenNeedsPassword()` - a READ-ONLY lookup, so a mail scanner opening the page still uses nothing up - says the account has no password. `POST /auth/verify-email` takes `password` too (OpenAPI updated; no new error code - all failures stay `400 VERIFICATION_TOKEN_INVALID`, a used link `409`).
- **Forgot password** now also sends a link to a WAITING (`pending`) account - the way out for the real owner.
- **`resetPasswordWithToken()`**, for active and waiting accounts alike, in ONE transaction: locks the account row FIRST (review finding 5, below); uses the link; refuses (link NOT used up) a suspended/deleted account or a waiting Global Admin (review fix I1: an emailed link never activates an admin); stores the new password and clears the lock-out; activates a waiting account and sets `emailVerifiedAt`; calls `accountRemoveOtherWaysIn($userID, true)` (moves the account's "sessions valid from" mark, ends sessions, switches off API keys, removes linked sign-ins and passkeys, cancels other unused reset AND verification links). AFTER the commit it writes a `password_reset_completed` activity row with counts only (review finding 6). The slow password hashing happens before the transaction. The success message and the reset page say in plain words what was removed and that keys and Google/Microsoft links must be set up again.
- **One list of "doors":** `accountRemoveOtherWaysIn()` in `fn_auth.php` is now the ONLY copy of that SQL; the admin CLI grant (decision 0010) calls it with `false` (it leaves verification links, exactly as before - its audit row still records five counts). `roleID` is still written only in `fn_admin.php`; the reset asks `adminUserHoldsGlobalAdminRole()` rather than reading the role itself.

**Deliberate choices:**
- **A failed activity-log write does not block a reset.** Since review finding 6 (2026-11-25) `logActivity()` runs AFTER the commit, so the log can neither undo nor block the reset, and a row is only ever written for a reset that really happened. `logActivity()` swallows its own errors; if the insert fails, the person still gets back into their account and the failure goes to the PHP error log. Rejected: making the row mandatory like the admin audit row - a broken log table would then lock every user out of password resets.
- **No new error code** for "password needed" / "wrong password", so the API error catalogue is unchanged and a wrong password reads exactly like a bad link.
- **The per-link try limit uses the account's existing lock-out counter** rather than a new column (no database upgrade needed). Limit: tries are counted per account, so wrong passwords on two links for the same account add up - which is the safe direction.

**Not checked:** a real browser (the page and its script were checked by tests and by reading only); DreamHost.

**Where to look:** `_functions/fn_auth.php` (`emailVerificationPasswordOutcome()`, `emailVerificationTokenNeedsPassword()`, `verifyEmailToken()`, `accountRemoveOtherWaysIn()`, `requestPasswordReset()`, `resetPasswordWithToken()`), `public_html/verify-email/`, `public_html/reset-password/`, `public_html/forgot-password/`, `public_html/assets/js/cuercode-account.js`, `_functions/fn_api_accounts.php`. Tests: `tests/test_account_recovery.php`, `tests/integration/account_recovery_real_mysql.php`.

## 🌍 Working out the visitor's address (2026-11-25, decision 0014, #31)

**The problem (Phase 1B review finding I3):** per-visitor limits (sign-in lock-outs, "send me a new link", API limits) count visitors by internet address. `getClientIP()` read the FIRST X-Forwarded-For entry from a trusted proxy - exactly the part a visitor writes themselves - and lived only in the API file, so ordinary pages used `REMOTE_ADDR` (behind Cloudflare that would be Cloudflare's address, putting every visitor in one bucket).

**The rule now** (`_functions/fn_client_ip.php`, loaded on EVERY page by `bootstrap.php` and by `fn_api_auth.php`):
1. Start from `REMOTE_ADDR`.
2. Inside one of Cloudflare's published ranges? Use `CF-Connecting-IP` - only if it is exactly one valid address; otherwise keep `REMOTE_ADDR`.
3. Inside a `trusted_proxy_ips` range? Read X-Forwarded-For from the RIGHT, skip the forwarders the OWNER listed, use the first that is not (at most 20 entries; ports and `[IPv6]` accepted; junk stops the walk and the nearest trusted address is used). **A Cloudflare address in the chain is not skipped (review finding 4, 2026-11-25):** the walk STOPS there. Since the final review (M-2) the entry to its left is not used on its own: Cloudflare's own CF-Connecting-IP is used when it holds one valid address, otherwise the Cloudflare address itself. A Cloudflare Worker on anybody's account can call our forwarder directly and write any X-Forwarded-For it likes.
4. Otherwise ignore every forwarded header. `X-Real-IP` is no longer read at all.

`resolveClientAddress($server, $trustedRanges, $cloudflareRanges)` is pure; `getClientIP()` feeds it the real request, remembers the parsed setting for the request, and has a re-entry guard (reading the setting can log an error, which asks for the address again).

**Settings and data:**
- `trusted_proxy_ips` (not seeded; set it on `/admin/settings/` (Admin security) only if the host puts its own forwarding server in front): exact addresses or ranges, IPv4 or IPv6, separated by commas, spaces or new lines (for example `10.0.0.0/8, 2001:db8::/32`). **Decision 0015:** ranges wider than /8 (IPv4) or /32 (IPv6) and "/024"-style numbers are refused, each with a plain reason - by the settings editor (it will not save), and, for a value already stored, ignored and explained on the admin check and the command-line check. Bad entries are never trusted.
- **Cloudflare's ranges** are committed in `_includes/data/cloudflare_ip_ranges.php` (fetched 2026-11-25 from `https://www.cloudflare.com/ips-v4`, `ips-v6` and the API, which agreed). Refresh: `php tools/refresh_cloudflare_ranges.php` (compares, changes nothing) then `--write` (only if both of Cloudflare's forms agree and every entry is valid), and commit. A stale list is safe: a new Cloudflare range is simply not trusted. The tool and the loader refuse any Cloudflare entry wider than /8 (IPv4) or /24 (IPv6) - Cloudflare's widest real ranges are /12 and /29 - so a broken answer or a tampered file can never mean "trust everybody" (review nit, `clientIpCheckCloudflareList()`).

**Checking it on the live host:** the admin overview (`/admin/`) has a read-only **"Visitor address check"**: connecting address, which header was used, the address the site will use, whether the connection is inside a Cloudflare range, headers that arrived but were ignored, the trusted forwarders, the list date, and plain warnings (for example "a CF-Connecting-IP header arrived from outside Cloudflare's ranges"). Compare "address the site will use" with what a "what is my IP" site shows. A command line has no visitor, so `php _includes/cli/visitor_address.php` shows the settings in force and, with `--remote=... [--cf=...] [--xff=...] [--trusted=...]`, what the site would decide for a pretend request.

**Residual risk:** anyone can put their own Cloudflare account in front of this server's address; those requests do come from Cloudflare, but Cloudflare writes `CF-Connecting-IP` itself, so it is still the real sender. Closing that side door fully needs Cloudflare "Authenticated Origin Pulls" or a firewall that only accepts Cloudflare - hosting settings, not code.

**Tests:** `tests/test_client_ip.php` (spoofed headers from an ordinary connection, Cloudflare with and without a good header, CIDR matching for IPv4/IPv6 including ranges that do not end on a whole byte, malformed values, multiple hops, the data file, the admin/CLI check, page wiring); `tests/integration/account_recovery_real_mysql.php` section H (a real saved setting).
- Known limits: two simultaneous requests could both pass the per-account gap (the IP cap still applies); an older queued-but-unsent verification email arrives with a dead link; a waiting account answers a few milliseconds slower than an unknown address.
- Tests: `tests/test_resend_verification.php` (45 checks); `tests/integration/resend_verification_real_mysql.php` (7 scenarios on mysql:8.4 in Docker).

## 🔒 Final review of 68ad1fb..bffdff3: "checked, then written" password gaps, simultaneous link requests, and other fixes (2026-11-25, #31)

An independent Opus review found one important and several minor problems. All are fixed and proved by tests (unit, and real MySQL 8.0.36, MySQL 8.4.13 and MariaDB 10.6.28 in Docker - see `tests/README.md`).

**I-1 - a password change already under way could overwrite a finished password reset (decision 0016).** `changeUserPassword()` read the password, checked it (slow on purpose), then wrote the new one with no condition. The reviewer reproduced it: someone with an old session and the old password started a change, the owner's reset finished in between, and the change then put the other person's password over the owner's new one. **The pattern, for every "check the password, then act" place:** do the slow checking OUTSIDE any lock, then in ONE short transaction lock the account row (always first, then the session row - the order everything else uses), read the stored password again `FOR UPDATE`, require it to be byte-for-byte the one checked, and make the write itself conditional on it (`... WHERE passwordHash = <checked>`). If it changed, refuse and change nothing. Applied to:
- `changeUserPassword()` (`_functions/fn_api_account.php`): also moves the "sessions valid from" mark with the new shared `authMoveSessionsValidFrom()` (fn_auth.php), ends every other session, and gives the kept session the new mark so the person stays signed in. `authLockLiveSessionForUser()` can now check a named session (the one being kept). Refusals: `401 AUTH_REQUIRED` (the session was ended meanwhile - normally by the reset) or `409 PASSWORD_CHANGED_MEANWHILE` (for example two tabs of one session). Per decision 0016 it does not touch API keys or linked sign-ins.
- `eraseUserAccount()`: locks the account, requires the checked password, and the erase `UPDATE` is conditional on it (`409 PASSWORD_CHANGED_MEANWHILE`).
- `verifyEmailToken()`: the locked `SELECT` now also reads the password; if it is not the one checked, nothing is activated and the link still works.
- `adminVerifyReauthPassword()` (fn_admin.php): the counter reset is conditional on the checked password and a changed password confirms nothing (it also no longer passes a count to `recordFailedLogin()`).
- `loginUser()` already had it (mark read with the password, conditional session write and re-hash) - unchanged.
- "Sign out everywhere" on `/account/` is now `accountSignOutEverywhere()`: one locked transaction that moves the mark, so a sign-in still being checked elsewhere cannot survive it (before, it ended the listed sessions one by one).

**Why "optimistic" (check outside, re-check inside):** checking a password takes tens of milliseconds on purpose. Doing that while holding the account row would make every other request for that account wait. Re-reading the stored value under the lock and refusing if it moved gives the same safety with the lock held only for a few quick statements. The test proves it with the reviewer's own race, using the real functions in two processes: the integration script holds the account row, starts the reset (it waits), starts the change (it checks the OLD password, hashes the new one, then waits), then lets go. The reset goes first; the change is refused; the owner's password stands. The same scenarios FAIL on the previous code.

**M-1 - twenty simultaneous "Forgot password" requests gave twenty links** (limits: one a minute, five a day). Two separate fixes, in `requestPasswordResetWork()` and `requestNewVerificationLinkWork()`:
- **Per account:** the history read, the decision and the new link (and its queued email) happen in ONE transaction holding the account row (`SELECT ... FOR UPDATE` on its primary key). Requests queue; the first sends, the rest see it. At most one "refused by the limit" row per hour is also decided under that lock.
- **Per visitor address:** `authRecordThenCountLinkRequest()` records the request, then counts including it, one request at a time per address inside a short `GET_LOCK` (named after a hash of database + form + address). **Lesson, measured:** "record first, then count" WITHOUT the lock is safe but wrong under a burst - on MariaDB 10.6 and MySQL 8.4 all twenty recorded before any counted, so all twenty saw 20 and were refused (not even the one legitimate link went out). With the lock the count is exact: 20 at once from one address for 20 accounts gives exactly 10 links. If the lock cannot be had, it still records first (errs on the safe side) and logs a warning.
- **Unknown addresses take no row lock.** A locking read of an email address with no account would lock a GAP in the address index (InnoDB), holding up sign-ups. Known and unknown addresses both take the per-address named lock, and the 250 ms minimum answer time still applies (measured: shortest answer 250-251 ms under a burst).

**M-2 - the visitor address next to a Cloudflare hop** (`resolveClientAddress()`, fn_client_ip.php). When the walk from a trusted forwarder meets a Cloudflare address it stops and uses Cloudflare's own `CF-Connecting-IP` when that holds one valid address; otherwise the Cloudflare address that connected to the forwarder. The entry to the left of the hop is never used alone: a Cloudflare Worker on anybody's account can call the forwarder directly and write it. The earlier claim that a Worker "cannot choose the address" holds only for traffic through the site's OWN Cloudflare zone; for a Worker's own requests, Cloudflare's documentation says CF-Connecting-IP holds a Cloudflare address (not measured here).

**M-3 / M-4 - settings editor numbers.** A limit with no saved row (`rowOptional`) is no longer "(required)": the box shows the default as a placeholder and says it is in use; an empty box saves nothing. A stored number not written the allowed way (`-5`, `30.0`, `1e3`) is shown as stored and skipped while untouched, so the rest of the section still saves; editing it gives "Type a whole number from X to Y." for that box only.

**N-1 / N-2 - the two test buttons.** The attempt row is written first and the limit counted including it (`adminSettingsAttemptWithinLimit()`); a press refused by the limit is recorded (a "refused" row) and counts. The attempt row is now named `setting.test_graph.started` / `setting.test_email.started`. **Why not a migration 0004 to add `started` to the `outcome` list:** the database upgrade tool only ever adds (`migrationRefusedWordPatterns()` refuses `MODIFY`, and no step type can widen an ENUM); widening it means rewriting the whole column definition, which would loosen that guard for a label in an admin-only log. The outcome on the started row stays `failed`; the action name says "started".

**N-3 - discovery addresses** also refuse single-label names and `*.localhost`, `*.local`, `*.internal`, `*.localdomain`, `*.home.arpa`, `*.nip.io`, `*.sslip.io`, `*.xip.io`. **This is hygiene, not protection** against the site being steered to a private address (any public name can point anywhere). The real controls are now explicit in `oauthApplyCurlSafety()` (fn_oauth.php), used by all three sign-in provider calls: certificates checked, redirects never followed, https only (also for the token, key and user-details addresses a discovery document names); a call is not made if those cannot be set.

**Known limits / not checked:** a real browser for the changed account and settings pages; DreamHost; the Cloudflare Worker behaviour itself (relies on Cloudflare's documentation). The PHP 8.4 and 8.6 container images used for a cross-version run lack some extensions: they show the same 14 / 31 environmental failures before and after these changes.

## 🕐 A reset also stops sign-ins already under way, and other review fixes (2026-11-25, independent review of decisions 0013/0014, #31)

An independent Opus review of commits `a3e28fb..68ad1fb` found ten problems. All are fixed; each is proved by tests (unit, and real MySQL 8.0.36 / MariaDB 10.6.28 in Docker - see `tests/README.md`).

**Finding 1 - a sign-in already under way survived a password reset.** `loginUser()` read the password, checked it (slow on purpose), then made the session. A reset that ended every session in between could not end one that did not exist yet. **Fix: a "sessions valid from" mark.**
- Migration `0003_sessions_valid_from` adds `tblUsers.sessionsValidFrom` and `tblUserSessions.sessionsValidFromSeen`, both `DATETIME(6) NULL` (UTC, to the millionth of a second). `install.sql` is NOT changed (frozen baseline).
- `accountRemoveOtherWaysIn()` - so the password reset AND `admin_access.php grant --activate` - first moves the mark to `UTC_TIMESTAMP(6)`, or one microsecond past the old mark if the clock has not moved on, so the mark only ever goes forward and two resets in one instant still differ.
- Every place that makes a session reads the mark **in the same statement as the credential it checks** (the password row in `loginUser()`; the linked-sign-in row in `applyOAuthAccountLink()` for Google/Microsoft) and passes it to `issueUserSession()` (a REQUIRED parameter). The session row is written only while the account mark is still that value (`INSERT ... SELECT ... WHERE sessionsValidFrom <=> ?`), and the value is stored on the session. `currentUser()` refuses any session whose stored copy is not the account's current mark. Passkeys are not built yet; `tests/test_sessions_valid_from.php` fails if a new session maker appears without passing a mark.
- **Why copies and not times:** the brief suggested refusing sessions "created before" the mark. The late session in this fault is created AFTER the reset, so a time comparison cannot catch it; comparing the mark the sign-in SAW with the current mark catches it whatever the timing, and has no same-second edge: the reset's own follow-up sign-in reads the new mark, so it is never locked out (tested straight after a reset).
- The automatic Argon2 re-hash after sign-in now only replaces the hash it checked (`WHERE passwordHash = ?`), so a slow old-password sign-in can never write the old password back over a new one.
- **Before migration 0003 is applied** (`authSessionsValidFromReady()` asks INFORMATION_SCHEMA once per request), sign-in works exactly as before, so a Global Admin can still sign in to apply the upgrade at `/admin/upgrades/`. **Deploying this change: apply the waiting database upgrade (0003) straight after the code** - until then the new protection is off. If the readiness question itself fails, the answer is "ready" (fail closed).

**Finding 2 - a Google/Microsoft "link" request started before a reset could finish after it.** `beginAuth()` now saves the account mark with the request; the callback requires that someone is signed in NOW, that it is the SAME account (`oauthLinkRequestCheck()`), and that the mark has not moved; and `applyOAuthAccountLink()` re-checks the mark with the account row LOCKED and writes the link in the same transaction. A request saved before this change carries no mark and is refused (start the link again). **The same "checked, then acted" gap for API keys:** `createApiKeyForUser()` locks the account, then this browser's session (`authLockLiveSessionForUser()`), checks the session is still good, and writes the key in that transaction.

**Finding 3 - "Forgot password" had no limits and its timing showed whether an address exists** (0.20 ms unknown vs 3.91 ms waiting). It now has the same protections as "send me a new link": at least 250 ms for every answer, a minimum gap (60 s) and a daily cap (5) per account, an hourly cap (10) per visitor address (`getClientIP()`), the same 200 answer, and at most one `password_reset_request_limited` row per account per hour. Settings (group `security`, NOT seeded, clamped like the resend ones): `password_reset_request_min_gap_seconds`, `password_reset_request_daily_cap`, `password_reset_request_ip_per_hour`. The page and `POST /api/v1/auth/request-password-reset` share the function, so both are covered. The shared pieces are `authLinkRequestLimitSettings()`, `countLinkRequestsFromIp()` and `limitedRowWrittenRecently()`.

**Finding 4 / decision 0015 (finding 8):** the X-Forwarded-For walk stops at the first Cloudflare hop (see "Working out the visitor's address" above); `trusted_proxy_ips` refuses ranges wider than /8 or /32 and "/024"-style numbers with a plain reason, in the settings editor, the admin check and the command-line check.

**Finding 5 - two resets of one account at the same moment deadlocked** (each used up its own link, then waited for the other's). The reset and the verification link now lock the account row FIRST, then use up the link - the same order as every other change to an account (the admin grant, the Google/Microsoft link, the API-key check). Measured: with the old order 5 rounds out of 5 ended in a deadlock; now 5 out of 5 have one winner and the other is told its link was already used.

**Finding 6:** the reset's activity row is written after the commit (the docblock no longer calls it a step of the transaction).

**Finding 7:** `recordFailedLogin($userID)` lets the database add one (`failedLoginCount = LEAST(failedLoginCount + 1, 65535)`) and reads the count back; the verification link decides "stop the link" from that real count. Eight wrong passwords sent at the same moment now count as 8.

**Finding 9 - installer lock:** see "Installer lock" under "Fresh install" above (refuses without a writable lock folder; `.gitignore`; one shared lock for all copies).

**Finding 10:** the `/admin/.htaccess` comment claimed the admin headers are sent "also on error pages". Measured on Apache 2.4.71: they ARE sent when an admin page itself answers with an error (a PHP 403), but NOT on Apache's own 404/403 page for a file under `/admin/`, which `/error/404.php` and `/error/403.php` serve with the site's ordinary headers. Those pages show no admin content, so the comment was corrected rather than the behaviour changed.

**Nit:** a verification link for an account that is already active now says "already confirmed, nothing was changed" (409, the link is used up); for a suspended or deleted account it gives the usual invalid answer and changes nothing. Only a real activation (one changed row) says "verified, you can now log in".

**Known limits / not checked:** a real browser for the changed pages (callback error page, forgot-password, verify-email); DreamHost; MySQL 8.4 for the new scenarios (8.0.36 and MariaDB 10.6.28 only). The plan's future migration numbers (`0003`-`0006` in `.claude/plans/7n-admin-commerce-plan.md`) now take the next free numbers, because `0003` is used here.

## 🔁 Independent Codex review: what changed and why (2026-11-25, queue F2, #113)

Codex (OpenAI) reviewed the new-install work read-only (29 findings). Each was reproduced or disproved against the real code before anything changed; the full table is in the F2 row of `.claude/plans/new-install-readiness-queue.md`. What a developer needs to know:

- **Installer.** `seed_data.sql` is loaded in ONE transaction by `migrationDbRunSeedSql()`, so a failure leaves nothing behind. This works only because every starting-data table is InnoDB and the file holds only `SET` and `INSERT` statements (MySQL commits silently before any `CREATE`/`ALTER`/`DROP`/`TRUNCATE`); `tests/test_migrations.php` checks that. `migrationDbSeedDataState()` answers none / partial / complete, and the installer refuses "partial" instead of skipping, and checks "complete" again before writing its lock file. A named lock per database (`CUERCODE_INSTALLER_LOCK_NAME`) means one installer at a time. **The settings insert in `seed_data.sql` now ends `` ON DUPLICATE KEY UPDATE `settingKey` = `settingKey` ``** because migration 0002 adds some of the same settings: without it, the seed could never load after `migrate.php --apply` had run (reproduced). Only that statement skips existing rows.
- **mysqli throws.** Since PHP 8.1 a failed `new \mysqli(...)` THROWS; `@` does not stop it (measured on 8.4.27 and 8.5.12). Use `installOpenDatabase()` in the installer, and catch `mysqli_sql_exception` anywhere a database error must become a plain message.
- **Logos.** A request-supplied logo path may only point into `uploads/logos/` (never the temp folder). Internally created temp files are passed straight through and deleted afterwards with `cleanUpLogoPath()` in a `finally` block - call it after every `getLogoPath()` (a test counts the pairs). The SVG overlay embeds only content that is a real image (`detectEmbeddableLogoMime()`). SVG logos are refused above 4096 x 4096 px (`measureSvgLogoCanvas()`, at intake and before Imagick). On ImageMagick 7, `setFormat('svg')` before reading also fixes the OUTPUT format, so `loadLogoImage()` sets `setFormat('png32')` after reading.
- **Emailed links.** The final "use the link" step checks expiry in SQL (`expiresAt > NOW()`), with the clock that set it. The two request forms lock the account row with `FOR UPDATE NOWAIT` (`authLockAccountRowNoWait()`; MySQL reports a busy row as 3572, MariaDB as 1205): a busy row gets the usual answer, so a known account is never slower than an unknown one.
- **Before upgrade 0003,** password resets and changes are refused (`authUpgradeNeededResult()`, `503 DATABASE_UPGRADE_NEEDED`, link not used up). Sign-in still works the old way, so the admin can apply the upgrade.
- **Erase** also deletes passkeys and emailed links and stops waiting emails to the erased address (`skipped`, details removed). Since #129 sent and skipped queue records keep their status and times but lose the address, name and details, and the activity log's email entries have the address replaced by `[address removed]` (`activityLogEraseEmailAddress()`), all in the same transaction. An email being sent at that very moment keeps its record until the clean-up deletes it. Accounts erased before this change keep their old entries until the periods run out.
- **Admin audit order.** Unlock editing only through `adminReauthRecordSuccess()` (row first, fail closed). `adminRunUpgrades()` writes `schema.migrate.started` before changing anything. Settings `afterSave` cache clearing runs inside the save's transaction and a failure refuses the save.
- **Full notation.** `tests/test_full_notation.php` (with `tests/lib/shorthand_scan.php`) fails if shorthand appears in a file that has none, or grows in one of the 21 older files still listed (#157).
- **Not a fault:** PHP's own web client drops the Authorization header when a redirect goes to another host or port (measured), so the library watch's token cannot leak that way.

**Round 2 (Codex re-check of the fixes, 2026-11-25):**

- **"Complete" starting data.** `seed_data.sql` ends with a completion marker (`tblSettings.seed_data_loaded`, group `system`, not editable). It MUST stay the last statement. Without the marker, `migrationDbSeedDataState()` calls the data complete only when every row in `migrationSeedExpectedRows()` (38 code types, 26 logos, 4 plans) is there; a test reads `seed_data.sql` and fails if that list drifts. "One row per table" is never enough.
- **Transactions need InnoDB.** `migrationDbRunSeedSql()` asks `INFORMATION_SCHEMA.TABLES` for the ENGINE of every table in `migrationSeedTables()` before loading, and refuses unless all are InnoDB base tables. Any future all-or-nothing load should do the same: a MyISAM table silently ignores the rollback (measured).
- **Ordering between seed and migrations.** When a migration changes a row the seed also writes, make the seed write the NEW value, and leave the already-applied migration file alone (the runner warns when an applied file changes; `test_migrations.php` pins 0002's checksum). Then the result is the same whichever runs first.
- **Checked writes where a record is the permission.** `logActivity()` never reports failure, which is right for ordinary events. Where a log row stands in for an admin change-log row, use `logActivityChecked()` (true only when one row was written), and ask `adminTableExistsState()` (yes / no / unknown) rather than `adminTableExists()`: "could not tell" must refuse, never fall back.
- **Tests that accept "either outcome" prove nothing.** Where the machine can do the real thing (here: Imagick with a working SVG delegate, checked independently first), assert the real result; test the fallback separately with an input that forces it. Prove a new test fails on the pre-fix code (a scratch copy) before trusting it.

## 🤖 Human check: Turnstile, reCAPTCHA or hCaptcha (2026-11-27, #163, decision 0024)

**What it is:** a short "are you a person" check on the forms a script could abuse: create an account (`/register/`), sign in (`/login/`), forgot password (`/forgot-password/`), send a new verification link (`/resend-verification/`), and the JSON routes that do the same jobs (`POST /api/v1/auth/register`, `/auth/login`, `/auth/request-password-reset`). The later abuse-report form (#169) uses the same two calls.

**How to use it on a form (one call each way):** `echo humanCheckWidgetHtml(null, 'login');` inside the `<form>` (the second argument names the form - `login`, `register`, `forgot` or `resend` - and gives the `<noscript>` part its email-route button) and `'/assets/js/cuercode-human-check.js'` in `$pageJS`; on submit `$check = humanCheckVerifyRequest($_POST, ['purpose' => 'login', 'formRateLimit' => true]);` and show `$check['message']` when `$check['ok']` is false. A JSON route calls `requireHumanCheckForApi($requestBody, 'login')` (`fn_api_accounts.php`). Put the call after the cheap field checks (a token works only once) and before anything is looked up, saved or emailed. Only `'purpose' => 'login'` can ever use the outage rule below. After a successful sign-in, call `humanCheckNoteOutageSignIn($check, $loginResult)` (it writes the activity row when the outage rule let it through). Everything lives in `_functions/fn_human_check.php` and `_functions/fn_no_script_route.php`; `bootstrap.php` loads both on every page.

**Which providers:** setting `bot_protection_provider`. "auto" (the starting value since upgrade 0005) uses EVERY provider with both its site key and secret key entered, in the order Turnstile, reCAPTCHA, hCaptcha: the first is drawn, the others are its fallbacks. A named provider uses only that one (off while its keys are missing, never quietly another). "none" is off. An unknown stored value behaves as "auto", so a damaged value never switches protection off. Keys are entered on `/admin/settings/` ("Human check"), stored encrypted, never shown; that section says which providers are in use, the last test result of each, and warns when a provider's published test key is saved. reCAPTCHA means version 2 (the tick box); version 3 (scores) is not supported.

**Choices made (the owner may overrule):**
- **A token is used once only (review M1).** Cloudflare's own documentation says reuse detection is "eventually consistent", so several requests sent at the same moment with one token could all be accepted. When a provider accepts a token, its SHA-256 hash is written to `tblHumanCheckTokens` (primary key, kept 10 minutes; every provider's tokens last at most 5). The same token again is refused (403, "expired or was already used"). If the row cannot be written (no database, or upgrade 0005 not applied) the form is refused (503): never let through unrecorded. Measured: eight processes with one token at the same moment, exactly one accepted (MySQL 8.0.36 and MariaDB 10.6).
- **Keys that cannot be read (review M2).** `getConfig()` gives back the caller's default when a row is missing, the database read fails, OR an encrypted value cannot be decrypted. The check therefore reads each key with a stand-in default and, when it comes back, asks `configStoredValueState()` (`fn_config.php`, reads only the stored length, never decrypts) whether the row holds anything. A row that holds something, or a question that fails, means "saved but unreadable": that provider is skipped (another readable one is used), and if none is readable the protected forms sent the normal way are refused, the admin status says "the saved keys cannot be read", and the forms show a plain note with the email-confirmed route's button for EVERYONE (not only inside `<noscript>`, merged review L4), so people keep a way in, with that route's limits, while the keys are entered again (`humanCheckBrokenWidgetHtml()`; `cuercode-account.js` sends a press of that button as a normal form post, never as JSON). Before the fix a changed `ENCRYPTION_KEY` switched the check off without warning.
- **Our own set-up wrong** (`invalid-input-secret`, `missing-input-secret`, `sitekey-secret-mismatch`; and `not-using-dummy-passcode` only when OUR saved hCaptcha site key is a published test key - review L5, otherwise a visitor using hCaptcha's public test key simply fails), and **faults on our own server** (curl missing, the safe request settings not applied, certificate or TLS-library errors: `humanCheckCurlFaultIsOurs()` - review L1): **always refused** (503), logged as an error at most once every 10 minutes per provider. They never count as "the provider is down".
- **The documented reply shape is followed whatever the HTTP status (review L1).** A "400" with `{"success": false}` is a failed check, not an unreachable provider. Only a reply of another shape (an error page, nothing) is "could not check" - and even then see the next point.
- **A provider cannot be reached (decision 0037).** "Down" is decided ONLY by the server's own test call (`humanCheckProviderHealth()`): it posts our secret and a fixed dummy token (`cuercode-health-check`), so nothing from any visitor is in it. A normal answer ("that token is invalid") means up; our-fault codes mean "our set-up" (never down); no answer, a server error page (5xx) or `internal-error` means down. A 4xx page that is not the documented shape (429 "too many requests", a firewall's 403) counts as UP: a flood of junk tokens sent through this server could make a provider slow it down, and that must only ever cause refusals, never open the outage rule. The result is kept in `tblHumanCheckState` for 60 seconds (15 seconds after an odd reply to a visitor's token), and only one request at a time runs the test (a database lock that is never waited for; a request that cannot have it counts the provider as up). A site with nowhere to keep the result (upgrade 0005 not applied) never counts a provider as down. So: a strange reply to a visitor's token while the test says up is a failed check (403). While one provider is down, the page draws the next configured one first (`data-fallbacks`; the widget script also moves on if a provider's script will not load, or the server answers `HUMAN_CHECK_UNAVAILABLE`), and the server accepts a token from any CONFIGURED provider, never one that is not set up (`human_check_provider` naming another is refused). Only when EVERY configured provider is down does `bot_protection_when_unreachable` apply: **"signin_only"** (the 0037 default) lets SIGN-IN through without a token - the password, the wrong-password lock-out and the rate limits still apply (measured: five wrong passwords during an outage still lock the account) - and writes `human_check_outage_signin` to the activity log at most once a minute for the whole site, plus `human_check_outage_signin_success` naming the account at most once per account an hour; sign-up, forgot password and new links are still refused. **"refuse"** refuses sign-in too. The old "allow" (let every form through) is gone: decision 0037 rules it out, and a stored "allow" means the default.
- **Rate limit before the provider is called.** The four web forms count each send that carries a token, per visitor address, BEFORE any call to the provider (`human_check_form_ip_per_hour`, default 30, 1-1000; recorded then counted under a lock, `authRecordThenCountLinkRequest()`, endpoint `page/human-check`). A send without a token makes no call and is not counted. The JSON routes already have the API's own per-visitor limits.
- **Lock-out of the only Global Admin** (keys wrong or unreadable, or "refuse" during an outage): on the server, `php _includes/cli/human_check.php off` stores "none" (one row in the admin change log, actor `cli:<user>`); `... on` stores "auto"; with no word it shows the status. Never prints a key.
- **JavaScript off (decision 0038):** all three widgets need JavaScript, so the box cannot be drawn. The `<noscript>` part explains this and offers a button (`human_check_email_route=1`) for the slower email-confirmed route below. A form sent without a token any other way is refused (400 `HUMAN_CHECK_REQUIRED`). (reCAPTCHA's old no-JavaScript fallback is no longer offered by Google.)
- **API keys do not skip it.** The three JSON routes create accounts, sign in or send emails, so a key holder could otherwise script them. An app that uses these routes must show a provider's widget and send its token as `human_check_token` (and `human_check_provider` when it is not the first configured one). The email route is only for the web pages.
- **Host name (review L2):** a passed token must have been solved on one of the site's own names - the host of the `site_url` setting (each copy of the site - live, alpha, beta - has its own) and the product domains cuercode.net, cuercode.me and qrmy.link, each with and without `www.` (`humanCheckAllowedHosts()`). It is NOT compared with the request's `Host` header, which the visitor sends: on a server with a default or wildcard virtual host that header can be anything. A missing host name, or hCaptcha's documented `not-provided`, is accepted. With a provider's **published test secret** the host check is skipped (Turnstile's always answers `example.com`); never use test keys on the live site. If a copy of the site is reached on a name not in that list, set its `site_url` to that name.
- **Replayed or expired token** (`timeout-or-duplicate`, `already-seen-response`, `invalid-or-already-seen-response`, `expired-input-response`, or our own record of it): 403 `HUMAN_CHECK_FAILED` with "expired or was already used".
- **What is sent to the provider:** only the secret key and the token (and, for hCaptcha, our site key so it can confirm the token is ours). Not the visitor's address (`remoteip` is optional for all three and the browser already talks to the provider directly), email or anything typed. Requests use `oauthApplyCurlSafety()` (no redirects, https only, certificate checked) and never unpack compressed replies (#150).
- **A provider that is known to be down is not asked (merged review I4).** While the server's own test result for a provider says "down" and is less than a minute old (`humanCheckFreshlyDown()`), a visitor's token is not sent to that provider at all, so nobody waits the full time limit for a call that will not be answered; the outage rule then decides exactly as if the call had failed. Only the server writes that record, so a visitor cannot cause the skip.
- **Log floods are bounded:** "our set-up is wrong" and "accepted token could not be recorded" at most once every 10 minutes per kind (`humanCheckStateLogDue()`, decided by the database in one statement); a provider's test result is logged only when it changes; the outage rows as above; a refusal for a missing, failed, reused or rate-limited token writes nothing. (The JSON routes' general `errorResponse()` logging of every 4xx/5xx is older and unchanged.)

**The email-confirmed route for visitors without JavaScript (decision 0038, `fn_no_script_route.php`).** The server cannot tell a person without JavaScript from a script that skips the widget, so the route is built to be safe for both: nothing happens that the mailbox's owner has not confirmed, and it can never send more than a few emails.
- **Limits, admin-editable** (section "Human check", group "Without JavaScript"; reworked after the merged review, findings L1, L2, L3, I5):
  - **per visitor address** `no_script_route_ip_per_hour` (default 5, 1-100): every use of the route, all four forms together, checked first and before any password is looked at. An IPv6 address counts as its whole /64 network (`noScriptAddressKey()`), because one connection is normally given a whole /64.
  - **per mailbox** `no_script_route_email_per_day` (default 3, 1-20): EMAILS the route sent to one mailbox in 24 hours, whoever asked. `target+1@...` to `target+30@...`, and for Gmail dots and `googlemail.com`, count as one mailbox (`noScriptEmailCountingForm()`; only for counting - the address typed is the one stored and emailed).
  - **per form, whole site** `no_script_route_site_per_hour` (default 30, 1-1000): EMAILS each of the four forms sent through the route in an hour, counted separately, so a flood of sign-ups for made-up addresses cannot block a real person's sign-in. At most four times this number of route emails an hour in all.
  - **per account** `no_script_route_password_per_day` (default 10, 1-100; third-round review, Medium 1, 2026-11-28): WRONG passwords the route checks for one mailbox (its counting form) in 24 hours. The route's wrong passwords are **not** counted toward the account's lock-out: the route has no human check and its answer never says whether a password was right, so when they counted, five posts from one address locked any account for 15 minutes and one more post every quarter of an hour kept it locked. This number bounds guessing per account instead (the per-address limit still bounds it per address). Under the lock, `noScriptRouteGate(..., reservePasswordCheck: true)` records the use with purpose `login_wrong` (`CUERCODE_NO_SCRIPT_WRONG_PASSWORD_PURPOSE`) while the mailbox has checks left; a right password changes it back to `login` (`noScriptRouteClaimEmail()`), so only wrong ones count. Past the number the password is not checked at all (the same time is spent on a stand-in check) and the answer is the one answer as always, so a stranger who used up the day's checks stops the person's route sign-in for that mailbox until the day is over - the normal form is not affected, and nobody is locked out. The setting has no seeded row (it came after upgrade 0005); the code uses 10 until a Global Admin saves one. Measured: twelve processes at once with the number set to 3 - exactly 3 password checks.
  - Only an email that was really queued counts against the email limits (`tblNoScriptRequests`.`emailSent`): a forgot-password request for an address with no account, a sign-up for an address that already has one, or a wrong password uses up nothing. `noScriptRouteGate()` reserves an email place under one database lock (measured: twelve processes at once for one mailbox, exactly 3 places), `noScriptRouteReleaseEmail()` gives it back when the form queued nothing (`emailQueuedCount()` in `fn_email.php` counts what this request queued), the email is kept only as an HMAC keyed from `ENCRYPTION_KEY`, and any problem refuses. A use over the per-address limit is not recorded, so one address cannot make the table grow. Each form's own limits (one reset link a minute per account, and so on) still apply.
  - What is left: someone with many visitor addresses can still fill ONE form's hourly email allowance with sign-ups for made-up addresses (each a real queued email); that blocks only route sign-ups for up to an hour. Sign-in's own allowance can be used up only with accounts whose passwords are known.
- **Sign-up, forgot password, new verification link** (`noScriptRouteRunForm()`, the one helper all three pages use, merged review L5): the form's normal work runs only when an email place was reserved, whose own email is the confirmation (a waiting account becomes usable only from its verification link, which asks for the password - decision 0013; a password changes only from its reset link, which also removes other ways in). The page gives the same generic answer whether or not an account exists and whether or not a limit stopped the email, plus one fixed note about the limits, and takes at least 0.4 seconds (`padResponseToMinimumDuration()`). A visitor address over its limit is answered at once instead (merged review I3): nothing about any account is looked at, and the answer depends only on the visitor's own address.
- **Sign-in, step by step:** (1) the per-address limit and the route's lock, before the password is looked at - the only answers that differ ("too many from your connection", answered at once; "busy just now" when the lock could not be had, merged review I2), and neither says anything about the account; (2) the password, by the SAME check as normal sign-in (`loginUserCheckPassword()`, split out of `loginUser()`: dummy hash for an unknown address, a locked or waiting account refused) except that a wrong one is NOT counted toward the lock-out (`$countWrongPassword` false), and only within the per-account number of checks above; past it, a stand-in check on the dummy hash instead (third-round review, Medium 1); (3) the per-mailbox and sign-in's site-wide email limits, read under the lock the same way whatever the password (`noScriptRouteClaimEmail()`), so a stranger's wrong guesses use up none of the person's emails (merged review L2) - though more than the per-account number of them stops the route checking that account's passwords for the day; (4) only for a right password within those limits a single-use link (64 random hex characters; only its SHA-256 is stored in `tblEmailSignInRequests`, 15 minutes) bound to this browser by a second random secret kept in its PHP session (only its hash stored), the account's "sessions valid from" mark read with the password, and the `email_sign_in` email; (5) ALWAYS a secret in the browser's session (a stand-in when no link was made) and the ONE answer "If the email address and password are right, we have emailed you a link" in at least 0.4 seconds. **The answer, its headers, the cookies, the time taken, the sign-in page drawn afterwards, Continue ("You are not signed in yet ...") and Start again are the same for a right password, a wrong one, and an unknown, locked or waiting account** (merged review M1, proved over real HTTP: `human_check_real_mysql.php` scenario M), so the route tells a script nothing about a password. Before the fix a wrong password got "Invalid email or password" and a right one "we have emailed you a link". Opening the link (`/login/confirm/`) changes nothing - mail scanners open links - and only a POSTed button press with the page's security token counts. Pressed in the SAME browser: that browser is signed in. Pressed in ANOTHER browser: the page says in bold that this browser will NOT be signed in and shows when ("about 2 minutes ago"), from what browser ("Firefox on Windows", `noScriptDescribeBrowser()`) and from what internet address the sign-in was asked for; the press only approves, and the first browser then has **3 minutes** (`CUERCODE_NO_SCRIPT_APPROVAL_SECONDS`) to press Continue on `/login/` - after that the approval has run out, so someone who sits down later at a shared computer cannot finish it (merged review L6). "Start again" marks the waiting request cancelled (merged review I1), so that browser's own link then says it is no longer valid. Either way the session is made only while the account is still active and unlocked and its mark is still the one read with the password, so a password reset, a password change or "sign out everywhere" in between makes the link stop working (the same rule as every sign-in; decision 0013's reset rules are kept - no new "ways in" list). Someone who reads the link (for example in a web server's access log, which lists every address requested, as it does for verification and reset links) can only approve the browser that typed the password, never sign themselves in.
- **No new cookie:** the browser secret lives in the existing session cookie `CUERCODE_SID`.

**Deliberately exempt routes, and why there is no way round:** `/auth/logout` (needs a signed-in session); `/auth/verify-email` and `/auth/reset-password` and their pages, and `/login/confirm/` (need the 64-character secret link from an email - nothing to guess, nothing sent); Microsoft and Google sign-in (`/auth/<provider>/`: those companies run their own checks, and CueRCode sends no email); `/admin/reauth/` (only for a signed-in Global Admin). Every route that can create an account, try a password or send an email is covered, on the page AND in the API; `tests/test_human_check.php` fails if a new `/auth/*` route appears without being listed, and its checks ignore comments (review L3: a commented-out call used to pass).

**Content security policy:** `public_html/.htaccess` allows exactly what each provider documents: Turnstile `challenges.cloudflare.com` (script, frame); reCAPTCHA `www.google.com/recaptcha/` and `www.gstatic.com/recaptcha/` (script), `www.google.com/recaptcha/` and `recaptcha.google.com/recaptcha/` (frame), `www.google.com/recaptcha/` (connect); hCaptcha `hcaptcha.com` and `*.hcaptcha.com` (script, frame, style, connect). All three are listed so a fallback provider needs no file change; only the one being drawn is loaded. The admin area's policy (`public_html/admin/.htaccess`) leaves them out.

**Privacy page:** section 5.1 (`/privacy/#human-check`) names the providers in use and links to their privacy policies, or says none is in use; section 4 says the provider may set its own cookie and section 6 that the providers are US companies. The legal wording still needs the owner's review (#38).

**Database upgrade 0005** (`0005_human_check_settings`, changed in place before it was applied anywhere or pushed): moves an untouched "none" to "auto" (only when the admin change log has no row for that setting, so an admin's or the command line's own choice is kept), updates the description, adds `bot_protection_when_unreachable` ("signin_only"), `bot_protection_timeout_seconds` (5), `human_check_form_ip_per_hour` (30) and the three `no_script_route_*` limits (5, 3, 30), and creates `tblHumanCheckTokens`, `tblHumanCheckState`, `tblNoScriptRequests` (with `emailSent`) and `tblEmailSignInRequests`. "Still the starting value" is compared byte for byte (`CAST(... AS BINARY)`, as in 0004): a stored "NONE", "none " or a description that differs only in capitals or a trailing space is someone's own and is kept. A developer database that applied the earlier version of 0005 must be rebuilt (the installer and the upgrade tool refuse a changed applied file, decision 0036's rule).

**Tests:** `tests/test_human_check.php` (each provider with stand-in replies: success, failure, unreachable, error page, not JSON, missing or bad token, keys absent, wrong host, replayed token, wrong keys, test keys; the widget; the command-line options; comment-proof static checks that every page and route makes the call; the exact CSP; the migration rules), `tests/test_human_check_review_fixes.php` (M1, M2, L1, L2, L3, L5 and the form limit, each shown failing on the code before the fix), `tests/test_no_script_route_review_fixes.php` (the merged review's M1, L1, L3-L6, I2-I5 and 0005: the one sign-in answer, the helper never doing a form's work when the limits say no and giving back an unused email place, the mailbox counting form, the /64 key, the approval time limit and browser names, the broken-keys button, no call to a provider freshly tested "down", 0005's byte-for-byte compare) and `tests/test_human_check_fallbacks.php` (decision 0037: who decides "down", bounded test calls and log rows, only sign-in and only with every provider down, fallback providers, no unconfigured provider; decision 0038: the limits, the keyed email hash, the `<noscript>` button, the sign-in link decisions, the pages' order of checks). No real provider is ever called. `tests/integration/human_check_real_mysql.php` (real database and real HTTP through PHP's built-in server with `curl_init` switched off in it, so it cannot reach a provider): scenarios A-Q as listed in its header, on MySQL 8.0.36 and MariaDB 10.6. `tests/integration/migrations_real_mysql.php` scenario O (0005 on a database at 0004; owner choices kept). Not covered: a real browser with a real provider widget (it would send data to the provider), a real provider's outage, and the providers' real reply to a malformed request.

**Lesson (found by the real-HTTP run, 2026-11-27):** the form rate limit first called `authRecordThenCountLinkRequest()` without loading `fn_api_auth.php`, which the ordinary pages do not load on their own; the request was never recorded, so the limit never bit. Only the real-HTTP scenario L caught it - the unit test used a stand-in counter. When a page relies on a helper that lives in an API-only file, load it where it is used (as `fn_auth.php` does).

## 🔍 Site Polish Check & Template Comments (2026-10-05, #137)

- **`php tools/check_site_polish.php`** - source-only (no database, no web
  server). CI job `site-polish` runs it on every push; exit 1 = a problem.
  Warnings (exit 0) are things only the owner can fix, today the nine
  `[TEMPLATE: ...]` legal placeholders in `terms.php`/`privacy.php`.
- **CDN integrity hashes are verified against the committed copies** in
  `public_html/assets/vendor/` (Bootstrap 5.3.3, jQuery 3.7.1, FontAwesome
  6.5.1, Swagger UI 5.33.0). When you bump a library, update the copy and the
  `integrity="..."` value together; the check fails if they disagree. Never type
  or "remember" a hash: compute it (`openssl dgst -sha384 -binary FILE | openssl
  base64 -A`) or copy it from the project's own published value. A wrong hash
  silently blocks the file in every browser (this is what left the site
  unstyled before #137). FontAwesome has no `integrity` yet - add it once the
  value is confirmed from two sources.
- **Comments in page templates are PHP comments** (`<?php /* ... */ ?>`), not
  HTML `<!-- -->` or JS comments inside inline `<script>` blocks, which ship to
  every visitor's "View source". The check fails on any HTML comment in a
  template. Note that a PHP `//` comment ends at `?>`, so never put `?>` inside one.
- **Bootstrap 5.3 class names:** there is no `text-bg-*-subtle`; use
  `bg-*-subtle text-*-emphasis` (the made-up class rendered white-on-white).
- **CSS grids:** use `repeat(N, minmax(0, 1fr))`, never `repeat(N, 1fr)` - the
  latter lets long labels push columns out of their card (it clipped the QR-type
  grid on every screen size).
- **Page variables:** every page sets `$pageTitle` and `$pageDescription`;
  private, one-time and error pages also set `$pageNoIndex = true` (noindex, no
  canonical). Public pages belong in `public_html/sitemap.xml`.
- **Seeing the real pages in a cloud session:** install MariaDB, load
  `_includes/sql/install.sql` + `seed_data.sql` into a test database, write a
  local-only `_auth_keys/auth_creds.php` (git-ignored), and serve
  `public_html` with `php -S` plus a small router that mimics `.htaccess`
  (clean URLs, API/auth routes, custom 404). jsDelivr/cdnjs may be blocked by
  the container's proxy; route them to the committed vendor copies in
  Playwright. Check widths 320/375/540/768/1024/1366/1920, light and dark.

## ⏱️ Database times are UTC: compare them in SQL (2026-11-28, #158, #130)

The connection sets `time_zone = '+00:00'`, so every `DATETIME` the database writes is UTC. PHP's clock follows the `site_timezone` setting. **Never** read a stored time with `strtotime()` and compare it with `time()`: ask the database (`(`expiresAt` > NOW()) AS `isLive``, `(`lockedUntil` IS NOT NULL AND `lockedUntil` > NOW()) AS `isLocked``) and write future times with `DATE_ADD(NOW(), ...)`. If PHP really must compare (the pricing page does), use a UTC string from `gmdate()`. `tests/test_expiry_clock.php` guards `_functions/`; `tests/integration/site_timezone_real_mysql.php` proves the behaviour in five zones. Session values that PHP both writes and reads (`time()` numbers) are not affected.

Not changed: the "Saved", "Linked", "Created" and "last used" dates on `/account/` still print the stored UTC time without converting it to the site's zone (a display matter; no expiry depends on it).

## 🛡️ No inline scripts in the admin area (2026-11-28, #154)

`public_html/admin/.htaccess` allows scripts only from this site's files and cdn.jsdelivr.net (`adminSendSecurityHeaders()` says the same). So anything an admin address can show - admin pages, `header.php`, `footer.php`, the admin menu, `error/404.php` - must have **no** inline `<script>`, no `on...="..."` attribute and no `javascript:` address: put the code in a file under `assets/js/`, pass values in `data-` attributes, and add the file to `sw.js`. `tests/test_admin_csp.php` checks this. `cuercode-head.js` and the two `cuercode-fallback-*.js` files must stay plain blocking scripts. The public policy still allows inline scripts (home, account and API docs pages use them).

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
| `tests/test_payloads.php` | Characterization tests for all 26 QR payload builders and escaping helpers (`dynamic_url`'s destination checks are covered by `test_dynamic_redirect_s0.php` instead) |
| `tests/test_core.php` | Characterization tests for encryption, CSRF, validation, QR options, and response formatting |
| `tests/test_email.php` | Microsoft Graph email subsystem (#29) - template rendering/escaping, backoff schedule maths, and the fail-open contract when unconfigured/no DB |
| `tests/test_auth.php` | Authentication (Epic E3 stage 1, #52) - Argon2id hash/verify/rehash/policy, token generation/hashing, pure lockout-window maths, and session-dependent functions' fail-safe behaviour with no PHP session/database |
| `tests/test_features.php` | Premium tier / feature gating engine (Epic E4 framework stage, #25/#26/#53) - fail-safe plan resolution, the per-request cache, generic fail-closed `featureEnabled()`/`limitFor()` lookups, `requireFeatureOr402()`'s denial shape, `resolveUserIdFromAuth()`, and a static source-text regression guard proving zero per-tier ('pro'/'business') hardcoding |
| `tests/test_qrcodes.php` | Saved-codes API (Epic E3 stage 2a, #52/#24) - exhaustive truth-table coverage of the three ownership predicates (`qrCodeIsReadableBy()`/`qrCodeIsWritableBy()`/`qrCodeIsDownloadableBy()` - the entire IDOR-enforcement surface), UUID/label/type-filter/pagination validation helpers. The live scratch-DB + real-HTTP-router IDOR pack (cross-user 404s, plan-limit 402, pagination, PATCH regeneration) is documented in CHANGELOG.md/SECURITY.md, not re-run inside this dependency-free harness (same convention as `test_auth.php`'s DB-dependent exclusions) |
| `tests/test_account_ui.php` | Account UI (Epic E3 stage 2b, #52/#12/#14/#23/#24) - `resolveAvatarUrl()`'s full §11.4 priority-chain truth table, `maskTokenForDisplay()` (incl. the `substr(-0)` footgun regression this stage's own testing caught), `decodeAndValidateAvatarBase64()` (malformed input, explicit SVG rejection, and a genuine 1×1 PNG decode round-trip via the reused `saveBase64LogoToTemp()`), and static own-scope/no-SVG-allowlist regression guards. The live scratch-DB + real-HTTP end-to-end drive (both the AJAX-JSON and no-JS `<form>` paths, cross-account IDOR proof, plan-limit 402/upgrade-prompt proof, open-redirect proof) is documented in CHANGELOG.md, not re-run inside this dependency-free harness |
| `tests/test_api_keys.php` | Self-service API keys (Epic E7 stage 1, #54) - `apiKeyIsOwnedBy()`'s full IDOR truth table (mirrors `qrCodeIsWritableBy()`), `apiKeyManagementAllowedFor()`'s CSRF-only guard, name/scope validators (incl. the fail-closed-by-omission vs. required-field distinction), the scope allowlist + label helper, and static source-text regression guards (the CSRF-only guard runs first in every handler, the hash column is never SELECTed, the router's scope-enforcement wiring exists). The live scratch-DB + real-HTTP two-account pack (cross-user rotate/revoke 404s, scope-missing 403s, the `api_keys` plan-cap 402, create/rotate-only one-time-secret exposure, list never leaking the hash, expired-key rejection) is documented in CHANGELOG.md/SECURITY.md, not re-run inside this dependency-free harness |
| `tests/test_accessibility.php` | Accessible-QR presets (E5, #48) - WCAG contrast-ratio maths, the 10:1 recommended-print-size calculator, and the preset-enforcement (ecc=H, quiet zone, contrast pass/fail/warning classification) |
| `tests/test_account_data.php` | GDPR/CCPA data-subject rights (#37, epic #52) - the DB-independent slice of `collectAccountExportData()`/`eraseUserAccount()`: confirmation-required short-circuit, fail-safe behaviour with no DB, and the PII-free erasure email/audit-log shape |
| `tests/test_api_parity.php` | API⇄UI parity audit - proves customisation parameters the web UI can send (`quiet_zone`, marker-shape selectors, etc.) are all accepted, validated, and mapped by the REST API, closing the "api-has-ui-missing" gaps found by the audit |
| `tests/test_barcode.php` | Non-QR barcode generation (#69) - GTIN/UPC check-digit maths, UPC-E→UPC-A expansion, the strict per-symbology validator/dispatcher for all 11 symbologies, and real tc-lib-barcode SVG/PNG rendering |
| `tests/test_braille.php` | Braille/tactile/Avery-label PDF output (E5, #49) - the Grade-1 Braille transliteration table and per-dot cell positioning maths |
| `tests/test_bulk.php` | Bulk QR generation helpers (#32) - bulk-input parsing (items-array and CSV shapes), CSV boolean coercion, cap/shape validation, and safe ZIP filename building |
| `tests/test_dynamic_redirect_s0.php` | Dynamic redirect Stage S0 (Epic E1a, #27/#71/#33) - the pure `validateRedirectDestination()` SSRF/open-redirect accept-reject matrix (scheme allowlist, IP-literal loopback/private/link-local checks for IPv4 and bracketed IPv6, self-referential-loop rejection) |
| `tests/test_dynamic_redirect_s1.php` | Dynamic redirect Stage S1 (Epic E1a, #27) - the owner-facing destination-API ownership predicate (`redirectIsWritableBy()`), still entirely dark (no public `/r/` route exists yet) |
| `tests/test_emoji_logo.php` | Emoji centre-icon picker (#67) - resolving an `emoji:<codepoint>` logo purely from the committed Twemoji asset tree, no database or upload involved |
| `tests/test_error_catalogue.php` | API error-code catalogue drift guard (#58) - statically scans production source for every emitted `errorResponse(..., 'CODE')` and diffs it against `openapi.json`'s documented catalogue, so a new/removed error code can never go undocumented |
| `tests/test_oauth.php` | SSO/OIDC client (Epic E2, #55/#19/#20/#28) - PKCE code_verifier/challenge (incl. the official RFC 7636 worked example), state/nonce generation and single-use consumption, and the open-redirect-proof allowlist |
| `tests/test_openapi_render.php` | Developer-portal OpenAPI render helpers (#50) - proves the helpers behind the live "Endpoint Reference" page are correct AND that the real `openapi.json` still has the shape the page depends on (30 documented paths) |
| `tests/test_pricing.php` | Public pricing page display helpers (#56) - price/limit formatting, key humanising, and the pure JSON-decode transform that turns plan rows into display rows |
| `tests/test_pricing_flex.php` | Flexible-pricing schema delta (#70, built but disabled) - proves multiple price points, discounts, lifetime tiers, and PAYG credits are representable by data alone, with every kill-switch off by default |
| `tests/test_pricing_page.php` | Pricing page and admin preview (#31) - kind filter and sale dates, "Contact us" card, hidden prices, rendered cards, preview access |
| `tests/test_commercial_literals.php` | Whole-site guard: none of the STARTING plan keys or prices written into the code (#31). It checks a fixed list, so a different hard-coded amount is not caught (Codex finding 3.6) |
| `tests/test_qr_logo.php` | SVG logo screener (F-024, #52) - the shared `screenSvgLogoContent()` active-content screener used by both the base64 and multipart logo-upload paths, plus the Issue #65 predefined-logo path resolver against the real asset tree |

**Deployment boundary:** `tests/` is repo-root only and is excluded from all SFTP deploy workflows. It never reaches the Dreamhost server.

**Current total (2026-09-23, #103):** 2185 assertions across 23 test files - see `tests/README.md` for the full per-file breakdown.

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
- 📱 `manifest.json`
- 🗄️ Database (`tblSettings.app_version`)

> **Documentation correction (2026-09-23, #103):** an earlier version of this
> note also claimed the version is sent in an `X-API-Version` response header.
> No code anywhere in this repo sets that header - it does not exist. Also,
> `infoAppVer.php` is not `require`/`include`d by anything else in the
> codebase; it is a standalone file. `GET /api/v1/health` reports a version
> too, but it reads it from the SEPARATE `tblSettings.app_version` setting, not
> from `infoAppVer.php` - so bumping `infoAppVer.php`'s version number does
> NOT change what `/api/v1/health` reports until `app_version` is updated to
> match (#35).

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
| `SFTP_KNOWN_HOSTS` | The server's SSH identity (host key lines). Without it both upload steps refuse to connect (#127) | output of `ssh-keyscan -p 22 host` |

### Required GitHub Variables

| Variable | Description | Value |
| --- | --- | --- |
| `SFTP_ENABLED` | Master switch for deployments | `true` |

### `SFTP_KNOWN_HOSTS`: what it is and how to make it (B5, #127)

**In plain English:** when GitHub connects to DreamHost it must be sure it is talking to the real server and not an impostor, because it sends the whole site and a key or password. The server's identity is its **host key**. `SFTP_KNOWN_HOSTS` is a saved copy of that key, so GitHub can compare. Both upload steps in `deploy.yml` stop with a plain message if it is empty, and connect with `StrictHostKeyChecking=yes` against only that copy. ("Trust on first use" was removed on purpose.)

**How to produce the value:**

1. On a computer you trust (not on a GitHub runner), run `ssh-keyscan -p 22 your-sftp-host` with your real host and port (the same as `SFTP_HOST` and `SFTP_PORT`). It prints one or more lines such as `your-sftp-host ssh-ed25519 AAAA...`.
2. Check the fingerprint against DreamHost's own records for that server (for example `ssh-keyscan -p 22 your-sftp-host | ssh-keygen -lf -` prints it) and compare it with what DreamHost's panel or support gives you. Do not skip this: the whole point is that the first look was not done by an attacker.
3. Paste **all the printed lines** as the secret value.

**Where to store it:** GitHub, Settings, Secrets and variables, Actions. Put it at **organisation** level next to `SFTP_HOST` when several repositories deploy to the same server (one value to keep right), or at **repository** level when only this one does. `SFTP_REMOTE_BASE` is different: it is a path specific to this site, so it belongs at repository level. Update the value only when DreamHost changes the server (a wrong or stale value makes the deploy refuse to connect, which is the safe failure).

### The unit test suite gates the deploy (#155, decision 0025)

`deploy.yml` has three checks the upload waits for: `php-checks` (syntax and the PHP 8.6 scan), `tests` (the unit test suite: every `tests/test_*.php`) and `integration` (the real-database tests, below). The first two run on PHP 8.4, 8.5 and 8.6; an 8.6 failure is reported but does not block (decision 0005). Both `ci.yml` and `deploy.yml` run the suite with `tools/ci/run-tests.sh`, which also checks the committed library versions and fails on deprecation text. It fails if the test runner is missing, so a deploy never skips the tests silently, and (recheck finding 3, 2026-11-27) if the suite did not really run: fewer than `MIN_TEST_FILES` (45; raise it as the suite grows) test files, the runner running fewer files than `tests/` holds, or no assertion at all. `tests/test_deploy_test_gate.php` guards this, and also fails if either gate job in `deploy.yml` could let a failure through (a job-level `continue-on-error` other than the PHP 8.6 one, a step-level `continue-on-error`, `|| true` or `set +e` in a run line, or PHP 8.4 / 8.5 dropped); each of those weakenings is applied to a copy of the real file and shown to be caught. Deploys of one branch queue (recheck finding 8): `deploy.yml` is in the concurrency group `deploy-<branch>` with `cancel-in-progress: false`, so a running upload is never cancelled or overlapped; a newer push replaces a deploy that is still waiting to start. Not yet seen running on GitHub: the first push to `alpha` will be the first proof.

### The real-database tests also gate the deploy (#174, decision 0040)

Every `tests/integration/*_real_mysql.php` script runs automatically in a job called `integration`, in `ci.yml` (pull requests and pushes) and in `deploy.yml` (the upload waits for it: `needs: [php-checks, tests, integration]`). Each run is three jobs, one per database: **MySQL 8.0.36, MySQL 8.4 and MariaDB 10.6**, each a throwaway server GitHub starts for the job (a "service container", empty, root with no password, reached on `127.0.0.1:3306`) and deletes afterwards. PHP 8.4 only (the lowest supported version); PHP 8.6 stays report-only in the other jobs (decision 0005). Every database blocks: there is no `continue-on-error` on this job.

Both workflows run the same script, `tools/ci/run-integration-tests.sh`. It runs the scripts one after the other (some check timings), each with its own database `cuercode_it_ci_<script>` (removed again after the script by the guard's `--drop`, third-round review Info 4), and fails when a required script is missing (the list `REQUIRED_SCRIPTS` in the script; add a new script there in the same commit), when no script ran, when any fails, when PHP deprecation text appears, or when part of a script could not run (it sets `CUERCODE_IT_REQUIRE_ALL=1`, so `admin_access_real_mysql.php` fails instead of printing "NOT RUN" when the `script` tool for its pretend-terminal step is missing). Before anything runs, `tools/ci/integration_db_guard.php` refuses a server that is not on this machine (127.0.0.1, localhost or ::1), an account that `INFORMATION_SCHEMA.USER_PRIVILEGES` does not list with the server-wide `SHOW DATABASES` right (without it, `SHOW DATABASES` hides every database the account has no rights to, so a live one could be missed - third-round review, Low 4; a right given only through a role is not listed there, so such an account is refused too), or a server that holds any database other than the server's own four and `cuercode_it_` ones, so it can never be pointed at a real site. What the scripts need from the machine, all present on `ubuntu-latest`: the `mysqli`, `intl`, `gd`, `mbstring`, `bcmath` and `curl` extensions, PHP's built-in web server (`php -S`, for the human-check and no-JavaScript route scenarios), the `script` tool (util-linux), `git` with the full history (the migrations script reads a committed, byte-for-byte copy of the old `install.sql`, `tests/integration/fixtures/install_76b1af7.sql`, refuses it unless its SHA-256 is the pinned one, and compares it with commit `76b1af7` wherever history holds that commit - so the checkout keeps `fetch-depth: 0`, but a squash or rebase merge that drops the commit no longer blocks the deploy; third-round review, Low 2), and a non-root user (the runner's own). Docker itself is not needed on the runner beyond the service container.

Measured on 2026-11-28 by running the shared script inside a Linux PHP 8.4.27 container as a non-root user, sharing the network of a throwaway database container (the same shape as the GitHub job): all eight scripts passed on MySQL 8.0.36, MySQL 8.4.13 and MariaDB 10.6, about 2.5 minutes each. `tests/test_deploy_test_gate.php` fails if the job is weakened (any `continue-on-error`, `|| true`, a skipped step, a dropped database, the shared script replaced, a shallow checkout, or the upload no longer waiting for it) and proves the script's own failure cases with stand-in scripts. Not yet seen running on GitHub.

### HTTPS redirect and HSTS: switch-on and rollback (B6)

`public_html/.htaccess` has a "Force HTTPS" block with two lines commented out:

```apache
# RewriteCond %{HTTPS} off
# RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

If a visit is not over HTTPS they send the visitor to the same address on `https://` with a **permanent (301)** redirect. They are off so local plain-HTTP testing works, and because a redirect to a broken HTTPS endpoint would lock visitors out. **Switch on at go-live only**, once the domain has a valid certificate and `https://` loads without a warning: remove the leading `# ` on the two lines, deploy, then check `curl -I http://host/` (301 and a `Location: https://` line) and `curl -I https://host/` (200 and a `Strict-Transport-Security` header). **Rollback:** put the `# ` back and deploy; browsers that already followed a 301 may keep going to `https://` for a while. **HSTS caution:** the active line `Header always set Strict-Transport-Security "max-age=15768000; includeSubDomains" env=HTTPS` already tells browsers that have seen it over HTTPS to refuse plain HTTP for 6 months, for every subdomain, and removing the redirect does not undo that in those browsers; an expired certificate then becomes a hard error for them. The commented-out `preload` variant is much harder to reverse (shipped inside browsers) and needs an owner decision. Step-by-step wording for the owner is in `docs/INSTALL.md` step 8.

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

### Deploy status caveats (2026-09-23, #103)

- **Trigger branches:** `deploy.yml` only triggers on push to `main`, `beta`, and
  `alpha` - **not** `release-candidate` (a staging tier that currently gets no
  automatic deploy target of its own).
- **Never actually run:** as of this refresh, `deploy.yml` has never executed a
  real deployment - the `SFTP_ENABLED` repository variable is not set to
  `true`, so every run so far (30 of them) has finished as "skipped". Whether
  the SFTP secrets (`SFTP_HOST`/`SFTP_USER`/etc.) have been filled in can't be
  seen from the repo - check them in the repository settings before enabling.
- **Libraries and CDN fallbacks ARE uploaded (corrected 2026-11-25).** The
  QR/PDF/barcode libraries are committed in `_libraries/` (decision 0011) and
  the Bootstrap, jQuery, FontAwesome and Swagger UI fallback copies are
  committed under `public_html/assets/vendor/`, so the normal deploy carries
  them. (Older notes here said they had to be placed by hand; that was true
  before decision 0011.)
- **Host-key checking is on (#127, #44).** Both upload steps use
  `StrictHostKeyChecking=yes` against `SFTP_KNOWN_HOSTS` and refuse to run
  without it; `set ssl:verify-certificate no` was removed. See the
  `SFTP_KNOWN_HOSTS` section above. Still unproven live.

### Known documentation-level gaps

See `PROJECT_STATUS.md` for the up-to-date list of what is built vs. still
outstanding (admin UI, deploy activation, etc.) - this file
covers HOW things work technically, not the current project status.

### Predefined-logo PNG generation (#65)

`tools/render_logo_pngs.php` renders the 26 predefined-logo PNG copies used as
centre-logo choices (a one-off/occasional maintenance script, not run on every
request or every deploy - re-run it by hand whenever the source logo set
changes).
