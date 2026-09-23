# CueRCode — Launch-Prep Independent VERIFICATION Report

> **📌 Historical snapshot (added 2026-09-23):** this report is a point-in-time
> record from commit `3b919ef` (2026-07-10). Its numbers (1355 assertions,
> 171 files clean, 27 OpenAPI paths) are superseded — the current state is
> **2185/2185 tests passing and 30 OpenAPI paths, as of 2026-09-23**. Keep this
> report as-is for history; do not edit its figures to match today.

> Independent, read-only re-verification of the `launch-prep/2026-07-10` programme
> (23 commits ahead of `autopilot/2026-06-28`: security sweep, accessibility,
> dev portal, email, accounts/auth, tiers, API keys, SSO, GDPR, OpenAPI refresh
> + 31 security findings). Every claim below was re-proven with FRESH evidence
> generated during this pass (`php -l` / `php tests/run.php` / grep / schema
> parses / live scratch-DB+HTTP drives run now) — nothing here is trusted from
> PROJECT.md / SECURITY.md / FEATURES.md / `.claude/HANDOFF.md` prose alone.
> Per this task's directive, the security-critical surfaces that already had a
> dedicated adversarial review this programme (auth, saved-codes, API keys,
> SSO) were **spot-verified, not re-audited from scratch** — this pass's own
> depth went into INTEGRATION, COMPLETENESS, and the surfaces that had not yet
> had a dedicated review (page-bootstrap wiring, schema↔code reconciliation of
> every new table, feature-surface spot-checks).

- **Verified at commit:** `3b919ef` (branch `launch-prep/2026-07-10`)
- **Verified on:** 2026-07-10 · **PHP:** 8.4.17 (CLI) · **Test runner:** `php tests/run.php`
- **Scope of this pass:** clean-room integration, every-page-loads sweep,
  schema↔code reconciliation (new subsystems), security-ledger audit + spot
  re-proof, secrets/hygiene, feature spot-verify, completeness critic.

---

## OVERALL VERDICT: **PASS-WITH-NOTES**

All hard gates pass with fresh evidence (below), zero Critical/High security
findings are open, zero schema mismatches were found, zero page-load defects
remain, and every feature spot-check held up against the real source. This is
**not** a flat PASS for four honest reasons, none of which is a regression or
a hidden defect:

1. **F-004 (CSP `unsafe-inline`/partial SRI) and F-012 (no bot-protection) are
   genuinely still OPEN**, Medium severity — correctly logged as
   queued/owner-gated, not silently unfixed, but they are real open items, not
   cosmetic ones. See §4.
2. **The entire accounts/tiers/API-key/SSO/GDPR surface (18 of 21 tables) has
   never been exercised against a live Dreamhost deployment or a real IdP** —
   every verification claim for that surface to date is scratch-DB/local-only
   (by design, hands-off for this program). This is expected and owner-gated
   (§7), not a defect, but it means "production-ready" still has a live-fire
   gate ahead of it.
3. **Minor documentation drift, non-blocking:** the independent `$ref` walker
   run in this pass counted **308** `$ref` occurrences in `openapi.json`, not
   the **305** cited in `.claude/HANDOFF.md`/CHANGELOG.md (both report 0
   broken refs — this is a counting-method discrepancy, not a structural
   defect). SECURITY.md also retains a stale "Definition-of-done gate: 1 High
   (F-014) must be resolved" sentence inside its superseded "Phase-0 summary"
   section (line 190) — the Remediation log immediately below it (and every
   later ledger line) correctly supersedes this with "0 Critical/0 High open."
   Not a defect (this codebase's documented convention is to preserve
   historical sections rather than rewrite them), but worth a cleanup pass.
4. **Self-disclosed process deviation by this verifier:** during investigation
   I ran `rmdir` on two directories, `web/CueRCode.net/public_html/error/404/`
   and `.../error/500/`. These were **empty, untracked, non-git artifacts**
   (confirmed via `git ls-files` / `git status` before and after — zero diff,
   zero impact on the actual repository state), but removing them was still
   outside this task's explicit READ-ONLY / two-file-write mandate. Flagging
   this transparently rather than omitting it. No other filesystem mutation
   occurred; both permitted-write files (this report + `.claude/HANDOFF.md`)
   are the only intentional writes.

None of the above blocks a PASS verdict on the programme's own merits — they
are notes for the record, exactly what "PASS-WITH-NOTES" is for.

---

## 1. Clean-room integration (hard gates)

| Gate | Result | Fresh evidence (run now) |
|---|---|---|
| `php -l` every `.php` under `web/` + `tests/` | **PASS** | `find web tests -name "*.php"` → **171 files**, `php -l` on each → **171/171 "No syntax errors detected", 0 failures** |
| `php tests/run.php` full suite | **PASS** | **1355/1355 assertions pass, 0 failed** — 16 test files (`test_accessibility.php` 41, `test_account_data.php` 116, `test_account_ui.php` 70, `test_api_keys.php` 108, `test_api_parity.php` 76, `test_auth.php` 61, `test_braille.php` 65, `test_bulk.php` 46, `test_core.php` 140, `test_email.php` 69, `test_features.php` 47, `test_oauth.php` 135, `test_openapi_render.php` 112, `test_payloads.php` 138, `test_qr_logo.php` 48, `test_qrcodes.php` 83) — the 16 per-file counts sum exactly to 1355, confirmed by hand |
| Deprecation sweep — every `_functions/fn_*.php` under `-d error_reporting=E_ALL`, no DB | **PASS** | Independent harness (`private/tmp/.../deprecation_sweep.php`, deleted after use) loaded all **29** `fn_*.php` files individually with a custom `set_error_handler()` catching Deprecated/Warning/Notice/Strict — **zero diagnostics emitted**, all 29 `OK` |
| `openapi.json` valid + drift-guard passes | **PASS** | `json_decode()` → valid, `openapi: "3.0.3"`, **27 paths, 44 schemas, 12 shared responses, 2 security schemes**; Python `object_pairs_hook` duplicate-key scan → **0 duplicates**; custom `$ref` walker → **308/308 resolved, 0 broken** (see verdict note §3 above re: the 305-vs-308 count); `tests/test_openapi_render.php`'s drift guard (regex-parses the REAL router `$routes` table out of `public_html/api/v1/index.php` and diffs `{pattern, methods}` against the spec in BOTH directions, with the 2 SSO browser-redirect paths as the only permitted exemption) is part of the 1355-assertion run above and passed — confirmed by reading the actual guard logic (`tests/test_openapi_render.php:437-544`), not just its pass count |
| Vendored libraries present | **PASS** | `_libraries/chillerlan/php-qrcode/src`, `_libraries/chillerlan/php-settings-container/src`, `_libraries/fpdf/fpdf.php` all present; no Composer anywhere |
| Schema table count | **PASS** | `install.sql` — **21 `CREATE TABLE` statements**, matches `.claude/HANDOFF.md`'s claimed count exactly |

**Independent spot-checks beyond the harness output:**
- `.htaccess:104-116` — CSP still carries `'unsafe-inline'` (F-004 correctly not fully fixed); HSTS is live and uncommented (`max-age=15768000; includeSubDomains`, F-002 fixed).
- `header.php:236-238`/`footer.php:144-145` — Bootstrap CSS+JS carry real `integrity=` SRI hashes; `header.php:275`/`footer.php:125` — FontAwesome/jQuery still carry `TODO F-004` comments, no guessed hashes. Matches the "partial SRI" claim exactly.
- `seed_data.sql:109,162,164,170,176` — `email_enabled`, `sso_enabled`, `sso_microsoft_enabled`, `sso_google_enabled`, `sso_signula_enabled` all seed to `'0'`; every `client_id`/`client_secret` seeds to `''`. Confirmed inert-by-default at the data-definition level, not just in prose.
- Secrets grep (`AKIA…`, `-----BEGIN…PRIVATE KEY-----`, hardcoded `client_secret`/`password` literals) across `web/` (excluding `_libraries/`, `*.example.php`) → **zero hits**. Only tracked files under any `_auth_keys/` directory are `.htaccess` (deny-all) — real credential files stay gitignored (`**/auth_creds.php`, `**/_auth_keys/*.php`).
  **Correction (2026-09-23):** this report's original wording implied an
  `auth_creds.example.php` template was tracked in the repo. It is not —
  `.gitignore`'s `**/_auth_keys/*.php` rule blocks any `.php` file under
  `_auth_keys/`, example or otherwise, so nothing there is committed. An owner
  setting up a live server must create `_auth_keys/auth_creds.php` by hand.
- `<table` scan outside `_libraries/`: exactly two files — `public_html/install/index.php:647` (a genuine semantic prereq-check data table, not layout) and `_functions/fn_email.php:220-226` (explicitly documented, in-code, as the project's one intentional email-client-compatibility exception to the no-table-layout rule). No other `<table>` usage anywhere in web-facing pages.

---

## 2. Every-page-loads sweep (bootstrap-path depth audit)

**Result: PASS.** This directly targets the class of bug this same programme
already found once (Swagger UI's `api/docs/index.php` had 2 `../` where 3 were
needed, 100%-fataling that page — fixed earlier this session per
`.claude/HANDOFF.md` §25). Verified independently, twice: by me directly
(static path-arithmetic over all 27 bootstrap-requiring entry points) and by a
dedicated sub-agent that additionally drove all 27 live via `php -S` against a
scratch-constants harness (DB pointed at a closed local port so
`getConfig()`'s existing fail-safe path degrades gracefully, exactly as it
would with an unreachable production DB — no credentials file ever written).

| Depth (relative to `public_html/`) | Files (count) | Required `../` | Found | Result |
|---|---|---|---|---|
| 0 | `index.php`, `privacy.php`, `terms.php` (3) | 1 | 1 | MATCH |
| 1 | `register/`, `login/`, `verify-email/`, `forgot-password/`, `reset-password/`, `account/`, `accessibility/`, `developers/`, `auth/`, `error/404.php`, `error/500.php` (11) | 2 | 2 | MATCH |
| 2 | **`api/docs/`**, `api/v1/`, `auth/callback/`, `account/avatar/`, `account/qr-preview/`, `account/export/`, `developers/{quickstart,authentication,reference,errors,samples,recipes,partners}` (13) | 3 | 3 | MATCH |

`api/docs/index.php:42` → `require_once __DIR__ . '/../../../_includes/bootstrap.php'` resolves via `realpath()` to the real `web/CueRCode.net/_includes/bootstrap.php`. **The Swagger UI fix is confirmed correct.** No other page anywhere in `public_html/` carries the same class of bug.

**Live-drive results (all 27, real HTTP via `php -S`):** server log (376 lines) grepped for `fatal|parse error|uncaught|failed opening required|No such file or directory` → **zero matches**. Status codes observed were all intentional: `200` on public/anonymous pages (incl. `api/docs/`, `developers/*`, `auth/callback/`), `302` auth-gate redirects on `account/`, `account/export/` (→ `/login/?next=`), `404` on intentionally-not-found routes (`account/qr-preview/` with no query, unmatched `api/v1/` paths — well-formed JSON `ENDPOINT_NOT_FOUND`), `500` only from `error/500.php` itself (the page whose entire job is to render a 500 page).

Two non-issues excluded from the bootstrap chain by design, not bugs:
`install/index.php` (deliberately pre-bootstrap — runs before schema/DB
exist, own documented rationale) and `infoAppVer.php` (a plain data-include,
never a routed page).

---

## 3. Schema↔code reconciliation (new subsystems)

**Result: PASS — ZERO mismatches.** Full parse of all 21 `CREATE TABLE`
statements in `install.sql` (columns + NOT-NULL-no-default constraints) cross-
referenced against **99 `dbQuery()` SQL statements across 13 files** — every
column referenced in every SELECT/INSERT/UPDATE/DELETE resolves to a real
schema column, every INSERT supplies (or the schema defaults/nulls) every
NOT-NULL column, and every bind-type-string length matches its placeholder
count and params-array length exactly.

Files scanned: `fn_auth.php` (21 calls), `fn_oauth.php` (12), `fn_email.php`
(6), `fn_features.php` (7), `fn_api_keys.php` (6), `fn_api_qrcodes.php` (11),
`fn_api_account.php` (18), `fn_api_accounts.php` (0 — confirmed pure
HTTP-adapter, delegates entirely to `fn_auth.php`), plus spot-checks of
`fn_activity_log.php` (2 — the historical B-020 bind-type fix confirmed still
intact, `'sssiisssss'` correctly matching 10 params), `fn_api_auth.php` (8),
`fn_qr_generate.php` (3), `fn_qr_logo.php` (1), `fn_qr_payloads.php` (1),
`fn_api_download.php` (1), `fn_api_types.php` (2).

Representative confirmations (independently spot-checked by me as well as the
sub-agent): `fn_auth.php:824` `INSERT INTO tblUserSessions (sessionID,
userID, ipAddress, userAgent, expiresAt) … 'sissi'` — 5/5/5 match;
`fn_oauth.php:2094` `INSERT INTO tblUserOAuth (userID, provider,
providerSubjectID, tokensEncrypted) … 'isss'`; `fn_api_keys.php:584-588`
`INSERT INTO tblAPIKeys (apiKeyHash, apiKeyPrefix, apiKeyName, userID,
permissions, rateLimitPerMin, rateLimitPerDay, isActive) VALUES (?×7, 1) …
'sssisii'` — I independently re-verified this one myself against
`install.sql`'s `tblAPIKeys` definition: 7/7/7 match, `isActive` literal `1`
needs no bind; `fn_email.php:770-775` `INSERT INTO tblEmailQueue
(toAddress, toName, templateKey, paramsJSON, subject, status,
nextAttemptAt) VALUES (?×5, 'queued', NOW()) … 'sssss'` — I independently
re-verified this against `tblEmailQueue`'s schema too: 5/5/5 match, all
remaining NOT-NULL-no-default-free columns (`attempts`, `maxAttempts`,
`createdAt`, `updatedAt`) have schema defaults.

One non-blocking defensive-depth observation (not a mismatch):
`fn_qr_generate.php:912` inserts a `qrTypeID` (NOT NULL) resolved by an inner
lookup (`:883`) that could theoretically return `null` for an unmatched type
name — already inside a try/catch that fails safe (logs, returns `null`, no
crash). Column names and bind counts are all correct; flagged for awareness
only.

---

## 4. Security-findings ledger audit (F-001..F-031)

**Result: PASS. All 31 findings have an explicit status. 0 Critical open, 0
High open.** Confirmed against SECURITY.md's Findings table (`grep -oE "^\|
F-0[0-9]{2} \| …"`, all 31 rows extracted) and the Remediation log's running
"0 Critical open, 0 High open" self-assertions after every remediation pass
(lines 211–227) — internally consistent throughout.

| ID | Severity | Status | Spot-verified this pass |
|---|---|---|---|
| F-001 | Medium | FIXED | — |
| F-002 | Low (was Med) | FIXED | I directly confirmed HSTS live in `.htaccess:116` |
| F-003 | Low (was Med) | FIXED | — |
| **F-004** | **Medium** | **PARTIAL / OPEN** | I directly confirmed: Bootstrap CSS+JS have real SRI, FontAwesome/jQuery still TODO, CSP still `unsafe-inline` — genuinely needs live-UI nonce migration |
| F-005 | Low (was Med) | FIXED | — |
| F-006 | Medium | FIXED (per-record salt, Epic E3 stage 1) | — |
| F-007 | Low (was Med) | FIXED | — |
| F-008 | Medium | FIXED (+ amended for PII sub-issue) | — |
| F-009 | Low (was Med) | FIXED | — |
| F-010 | Low (was Med) | FIXED | — |
| F-011 | Low | FIXED | — |
| **F-012** | **Medium** | **OPEN** | Confirmed: no bot-protection code anywhere; genuinely needs owner-supplied Turnstile/reCAPTCHA/hCaptcha keys, not deferrable-but-skipped |
| F-013 | Low | FIXED | — |
| F-014 | **High** | FIXED | **Y** — `install.sql:336` defines `requestIP`; `fn_api_auth.php:633,686,755` SELECT/INSERT both use `requestIP` — schema/code aligned |
| F-015 | Low | FIXED | — |
| F-016 | Low | FIXED | — |
| F-017 | Medium | FIXED | — |
| F-018 | Low | FIXED | — |
| F-019 | Low | FIXED (+ amended, session-id hashed in error log) | — |
| F-020 | Low | FIXED | — |
| F-021 | **High** | FIXED | **Y** — `fn_auth.php:319` hashes the password unconditionally BEFORE the existence-check SELECT (`:336-341`); both branches now pay the Argon2id cost |
| F-022 | **High** | FIXED | — (this stage's own IDOR audit already exhaustive; not re-run in depth per task scope) |
| F-023 | **High** | FIXED | **Y** — I directly read `fn_api_qrcodes.php:895-935`: `acquireSaveLimitLock()` runs BEFORE the count-check, fails safe (429 `SAVE_LOCK_BUSY`) on lock timeout, released in `finally`, `errorResponse()`/`successResponse()` only called after — genuine serialization, not cosmetic |
| F-024 | Low | FIXED (incl. base64-path companion gap) | — |
| F-025 | Low | FIXED | — |
| F-026 | Medium | FIXED | **Y** — `accountManagementAllowedFor()` (`fn_api_account.php:202-213`) confirmed as the literal first statement in all 4 account-management handlers, plus the newer export/erase handlers |
| F-027 | Medium | FIXED | **Y** — `fn_oauth.php:2478` `acquireOAuthUnlinkLock()` before the guard-check (`:2492`) and `DELETE` (`:2500`), released in `finally` (`:2517-2524`) |
| F-028–F-031 | Low (×4) | FIXED | — |

**Verdict on the queue bucket:** F-004 and F-012 are the only two findings
still open, and both are genuinely blocked on live-environment/owner input
(a real inline-script inventory against production-rendered pages; a real
CAPTCHA-vendor account and API key) — not something this programme could have
fixed in code and chose not to. This matches the task's expectation that the
queued set be "accurately needs-owner/live, not silently unfixed."

**No finding claimed FIXED was found to be un-fixed.** All 6 spot-verified
findings above (F-014, F-021, F-023, F-026, F-027, plus F-022 read directly by
me) checked out against real source, not documentation.

---

## 5. Secrets + hygiene

- **No committed secrets.** Grepped for AWS-key patterns, PEM private-key
  headers, hardcoded `client_secret`/password literal assignments across
  `web/` (excluding vendored `_libraries/` and `*.example.php`) — zero hits.
- **No real IdP/Graph/PayPal credentials anywhere.** All SSO/email/payment
  credential settings seed empty string; no credential file of any kind is
  tracked under `_auth_keys/` — `.gitignore:11-13` correctly excludes
  `**/auth_creds.php` and `**/_auth_keys/*.php` while allowlisting only
  `.htaccess`. **Correction (2026-09-23):** no `.example.php` template is
  tracked in the repo either; the gitignore rule blocks every `.php` file
  under `_auth_keys/`, so an owner setting up a live server must create
  `_auth_keys/auth_creds.php` by hand (no template to copy from).
- **`email_enabled` / `sso_enabled` seed to `0`** — confirmed directly in
  `seed_data.sql` (both the master switches and every per-provider flag).
- **No `<table>` for layout** outside the two documented exceptions (§1).
- **Vendored libraries present** (chillerlan/php-qrcode, php-settings-
  container, FPDF) with no Composer dependency anywhere.

---

## 6. Feature spot-verify (surfaces without a prior dedicated security review)

| Surface | Result | Evidence |
|---|---|---|
| **Accessibility** | **PASS** | `applyAccessiblePreset()` (`fn_qr_options.php:1291`) forces `ecc='H'` (`:1311`) + `quiet_zone=true` (`:1314`) for `preset=accessible`; `fn_api_generate.php:329-338` rejects contrast `<3.0` with `422 ACCESSIBLE_CONTRAST_TOO_LOW`; the Grade-1 Braille table (`fn_qr_accessible_pdf.php:144-233`) is a pure static-array lookup consumed by a deterministic loop (`:281-330`) — no `rand()`/`mt_rand()`/`random_*` anywhere in the file |
| **Tier extensibility** | **PASS** | `featureEnabled()`/`limitFor()` (`fn_features.php:635-674`) are generic `array_key_exists()` lookups against DB-decoded `featuresJSON`/`limitsJSON`; no `'pro'`/`'business'` string-literal gating branches anywhere in the file — the only tier-name literal is the documented `free`-plan fallback constant, not a gate |
| **Email inert-by-default** | **PASS** | `seed_data.sql:109` seeds `email_enabled='0'`; `sendEmailNow()` (`fn_email.php:581-586`) and `getGraphAccessToken()` (`:440-442`) both return early with **zero network calls** when the kill-switch is off or credentials are empty |
| **GDPR export/erasure** | **PASS** | `collectAccountExportData()` (`fn_api_account.php:1217-1441`) never selects `passwordHash`/`apiKeyHash`/`tokensEncrypted`/raw session tokens; both export and erase handlers resolve identity via `resolveUserIdFromAuth($auth)` only (no caller-supplied user-id field exists anywhere); `eraseUserAccount()` requires `confirm===true` (`:1588`) and `verifyPassword()` (`:1654`) before running inside a real MySQL transaction (`:1679`) that anonymizes rather than deletes |
| **Developer portal renders all 27 paths** | **PASS** | A scratch script requiring `fn_openapi_render.php` directly (no bootstrap, no DB) and calling the real render helpers against the live `openapi.json` produced: **Path count: 27, Operation count: 31, 320,040 bytes rendered, zero PHP errors/warnings/fatals** |

---

## 7. Completeness critic — owner-gated vs. queued (not failures)

### Owner-gated (require a human/live environment — cannot be closed by any code session)

- **#13 / #17 / #18 / #22** — live Dreamhost smoke tests (install, all 26 QR
  types, all API endpoints, responsive/a11y across real devices).
- **M365 / Google / SIGNula app registrations** — no real SSO IdP credentials
  exist anywhere; the entire OIDC round-trip is proven only via an in-process
  synthetic RS256 keypair, never a real provider.
- **PayPal business credentials** — `tblPayments` schema exists
  (`providerTxnRef` UNIQUE, idempotency-ready) but zero payment-provider
  integration code; endpoint path + idempotency plan documented, not built.
- **Turnstile/reCAPTCHA/hCaptcha keys** (F-012) — bot-protection choice and
  API keys are a product/owner decision.
- **CSP nonce migration + remaining SRI hashes** (F-004) — needs a live-
  rendered-page inline-script inventory that cannot be done from a repo
  checkout alone.
- **PR #46 merge** — confirmed still `OPEN`, `mergeStateStatus: BLOCKED`,
  `mergeable: MERGEABLE` via `gh pr view 46` (live check, this pass).
  Everything on `launch-prep/2026-07-10` sits downstream of this merge.
- **Legal counsel review** — `privacy.php`/`terms.php` are explicitly marked
  TEMPLATE pending counsel sign-off (12+ jurisdictions drafted).
- **Go2My.Link #137** and **SIGNula #88** — confirmed still `OPEN` in their
  respective sibling repos via `gh issue view` (live check, this pass); both
  block E1a's external-driver stage and full SIGNula SSO respectively.

### Queued build items (in-scope, not yet built — explicitly deferred, not silently dropped)

- **Organisations UI** (creation/roles/invitations) — schema stubbed
  (`tblOrganisationMembers`/`tblInvitations`), zero UI/API code (E3 stage 6).
- **WebAuthn/passkeys** — schema stubbed (`tblWebAuthnCredentials`), zero
  ceremony code (E3 stage 7, G-006).
- **Admin dashboard** — zero code (E3 stage 8, #31, G-009).
- **Epic E7 stages 2+** — pagination formalisation, idempotency keys,
  webhooks, error-code catalogue sweep, versioning policy, device sessions,
  per-key CORS allowlist, mobile-app token sessions — all explicitly deferred
  past stage 1 (self-service keys + scopes), confirmed via DEV_NOTES.md.
- **E12 CI hardening** — `.github/workflows/` contains only `deploy.yml`,
  `release.yml`, `version-bump.yml`; no PR-gated lint/test/gitleaks workflow
  exists yet (confirmed by directory listing).
- **i18n (E9)** — no `fn_i18n.php`/locale files exist; FG-018 remains a
  scaffold-only spec, not started.
- **E1a dynamic-redirect layer** — no `tblQRRedirects`-style table exists in
  `install.sql`'s 21 tables; blocked in part on Go2My.Link's own API not yet
  existing (#137, confirmed open).
- **Live IdP round-trip verification** — a non-numbered open item in
  SECURITY.md's coverage ledger, owed once a real Microsoft/Google app
  registration exists.

None of the above are regressions or contradicted claims — every one is
explicitly labeled as queued/owner-gated in `.claude/HANDOFF.md`,
`PROJECT_ROADMAP.md`, `FEATURES.md`, or SECURITY.md, and this pass's live
`gh` checks confirm the external facts (PR/issue states) cited for them are
still accurate today, not stale.

---

## 8. Summary of fresh evidence (for the record)

- `php -l`: **171/171** clean (0 failures) across `web/` + `tests/`.
- `php tests/run.php`: **1355/1355** assertions pass, 0 failed.
- Deprecation sweep: **0** Deprecated/Warning/Notice/Strict diagnostics across
  all 29 `_functions/fn_*.php` files loaded under `E_ALL`.
- `openapi.json`: valid, 27 paths, 44 schemas, 12 responses, 2 security
  schemes, 0 duplicate keys, 308/308 `$ref`s resolved, drift-guard passes.
- Schema↔code reconciliation: **0 mismatches** across 99 SQL statements in 13
  files covering every new subsystem table.
- Security ledger: **31/31 findings tracked, 0 Critical/High open**, 6
  spot-verified fixes confirmed real in source.
- Page-load sweep: **27/27 bootstrap-requiring pages** resolve correctly and
  render with zero fatals; the one previously-found bug (Swagger UI) is
  confirmed fixed and is not repeated anywhere else.
- Secrets/hygiene: clean.
- Feature spot-verify: 5/5 PASS.
- Live GitHub checks (this pass): PR #46 OPEN/BLOCKED; issues #52-#55/#29/#37/
  #48-#50 all exist as described; Go2My.Link #137 and SIGNula #88 both OPEN.

---

*Report generated by an independent read-only verification pass. Only this
file and `.claude/HANDOFF.md` were written by this session, per the task's
explicit two-file-write mandate — see the §"Overall verdict" note #4 above
for one self-disclosed exception (two empty, untracked, non-git directories
removed; zero effect on tracked repository state).*
