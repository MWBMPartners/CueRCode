# CueRCode — Dynamic Redirect + Dual-Function QR: Design Spec

> **Scope:** E1a redirect layer (#27 partial, FG-001, gate **G-007**), dual-function QR (#71 / ProjectEPass#18), scan analytics (#33 / E11 / gate **G-008**), tier gating, FG-006 posture.
> **Grounding:** branch `claude/emoji-picker` @ `e5e3743`. Every claim cites file:line or a live GitHub issue (CueRCode #27/#33/#71 all OPEN; ProjectEPass#18 OPEN, read 2026-07-22).
> **Status:** DESIGN ONLY — no code. Supersedes/refines the drafted spec in `PROJECT_ROADMAP.md:120-139` (E1a) and `:273-280` (E11), and `fable_analysis.md` §B(iii)/D-9/D-10.

---

## 1. E1a — Redirect layer

### 1.1 Schema: `tblQRRedirects` (new; migration `_includes/sql/upgrade_XXX_redirects.sql` + install.sql Table 27)

House conventions per install.sql:19-21 (tbl prefix, camelCase, createdAt/updatedAt), InnoDB/utf8mb4.

| Column | Type | Notes |
| --- | --- | --- |
| `redirectID` | BIGINT UNSIGNED PK AI | |
| `redirectUUID` | CHAR(36) NOT NULL UNIQUE | UUID v4; **the identifier printed in the QR** (`/r/{uuid}`). Deliberately NOT `tblQRCodes.qrUUID` (install.sql:202) — that UUID names a generated *image* (download URLs); this one names a *redirect resource*. A redirect survives image re-generation; one redirect may back several rendered images (re-styled reprints) |
| `qrID` | BIGINT UNSIGNED NULL FK→tblQRCodes ON DELETE SET NULL | Link to the generated record; nullable so the redirect outlives a soft-deleted/pruned QR row |
| `ownerUserID` | INT UNSIGNED NULL FK→tblUsers | XOR with ownerOrgID (mirror tblSubscriptions:809-811 convention). App-level invariant: **exactly one owner set — dynamic codes are never anonymous** (enforced anyway by the `anonymous` plan's `dynamic_codes=0` seed, Pricing_Strategy.md §3) |
| `ownerOrgID` | INT UNSIGNED NULL FK→tblOrganisations | |
| `destinationURL` | VARCHAR(2048) NOT NULL | Validated per §1.4 before every write |
| `previousDestinationURL` | VARCHAR(2048) NULL | Last value before the most recent PATCH (roadmap draft :126); full history lives in tblActivityLog |
| `destinationUpdatedAt` / `updatedByUserID` | DATETIME NULL / INT NULL | Audit convenience columns |
| `isActive` | TINYINT(1) NOT NULL DEFAULT 1 | 0 ⇒ `/r/` serves 410 (see §1.3) |
| `disabledReason` | ENUM('owner','abuse','admin') NULL | Set when isActive=0; 'abuse' drives the takedown page copy and blocks owner re-enable via API (admin-only re-enable) |
| `scheduledStartAt` | DATETIME NULL | FG-005 hook (roadmap draft): before this ⇒ "not yet active" page (409-style 404) |
| `expiresAt` | DATETIME NULL | After this ⇒ 410 (FG-005; column mirrors tblQRCodes.expiresAt:245) |
| `isDualFunction` | TINYINT(1) NOT NULL DEFAULT 0 | #71: 1 ⇒ resolvable via §3 |
| `appID` | INT UNSIGNED NULL FK→tblSanctionedApps | Which sanctioned app may resolve this code (NULL + isDualFunction=1 is invalid — app-level check) |
| `resolvePayloadJSON` | JSON NULL | Owner-supplied structured in-app payload template (event_id, pass_ref, attendee_ref…, §3.4). Not secret (it's business data), so plaintext JSON; the *secret* is the app HMAC key in tblSanctionedApps |
| `hitCount` | BIGINT UNSIGNED NOT NULL DEFAULT 0 | Cheap denormalised counter (`UPDATE … SET hitCount=hitCount+1`) — powers Free-tier "totals only" (tier matrix PROJECT_ROADMAP.md:314) even if tblQRScans insert fails (fail-open, §4) |
| `createdAt` / `updatedAt` | DATETIME | House standard |

Indexes: `UNIQUE(redirectUUID)`, `INDEX(ownerUserID, isActive)`, `INDEX(ownerOrgID, isActive)`, `INDEX(qrID)`.
No `redirectProvider` ENUM yet — E1b (Go2My.Link driver, blocked upstream on their #38/#39/#75, PROJECT_ROADMAP.md:127) adds it additively when their API exists.

### 1.2 The `dynamic_url` QR type

- New `tblQRCodeTypes` seed row `dynamic_url` (category `dynamic`), consumed by the existing dispatcher `buildQRPayload()` → `buildPayload_dynamic_url()` (`fn_qr_payloads.php:49-190` dispatch pattern; 26 builders today).
- `buildPayload_dynamic_url(array $inputData): string` returns `https://{redirect_base_host}/r/{redirectUUID}` — the UUID is created by the handler *before* payload build (the builder itself stays pure: it receives `redirect_uuid` in `$inputData` and validates it against the v4 regex, same strict-reject style as `buildPayload_gs1_product()`'s GTIN check, fn_qr_payloads.php:2609-2614). `redirect_base_host` is a tblSettings key (default `cuercode.net`; see owner decision O-11 re QRmy.Link).
- `handleGenerate()` (`fn_api_generate.php:248`) flow for this type: (1) require an authenticated owner (`resolveUserIdFromAuth()`, fn_features.php:776); (2) `featureEnabled('dynamic_qr')` else 402 via `requireFeatureOr402()` (fn_features.php:710); (3) count active redirects vs `limitFor('dynamic_codes')` under the **F-023 advisory-lock pattern** (`GET_LOCK`, fn_api_qrcodes.php:75-93,159-182) — the saved-codes cap TOCTOU is proven exploitable in this codebase, don't reintroduce it; (4) INSERT tblQRRedirects (destination validated §1.4), then generate the QR image exactly as any `url` type; (5) stamp `tblQRRedirects.qrID`.
- The `deeplink` type's accepted-but-never-encoded `app_scheme`/`fallback_url` (fn_qr_payloads.php:2428-2432, 2534-2537) stay untouched in this epic; they become meaningful only if a deeplink is later backed by a redirect (backlog item, fable_analysis E-6).

### 1.3 The `/r/{uuid}` route — `public_html/r/index.php` (+ `.htaccess` rewrite `^r/([0-9a-fA-F-]{36})$`)

Deliberately **dumb and small** — every request:

1. **Shape gate first, no DB:** UUID-v4 regex (reuse `CUERCODE_QR_UUID_REGEX`, fn_api_qrcodes.php:134-135). Fail ⇒ static 404, no query.
2. **Per-IP rate check** (§2.1). Fail ⇒ 429 + `Retry-After`.
3. One prepared SELECT by `redirectUUID` (MySQLi prepared — house rule).
4. **Outcome decision via a pure function** `redirectOutcomeFor(array $row, DateTimeImmutable $now): string` returning one of `redirect | gone_expired | gone_disabled | not_yet_active | not_found` — unit-testable with zero DB (§7):
   - row missing ⇒ 404 (indistinguishable from never-existed — same non-disclosure rationale as the saved-codes 404-not-403 posture, fn_api_qrcodes.php:31-49);
   - `expiresAt` past ⇒ **410** accessible error page (roadmap acceptance :133);
   - `isActive=0` ⇒ **410** neutral "link disabled" page (never reveals the destination; reason-specific copy only for `disabledReason='owner'`);
   - `scheduledStartAt` future ⇒ 404-shaped "not available" page (don't confirm future existence);
   - else ⇒ redirect.
5. **302** (never 301 — destinations are editable; a cached 301 would freeze them) with `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`, and `X-Robots-Tag: noindex`. **The Location value comes ONLY from the DB row.** No request parameter is ever read into the Location header; the inbound query string is dropped (logged length only) — this is the whole open-redirect defence: `/r/` has no reflective input (§1.4-e).
6. Fire-and-forget accounting: `hitCount` UPDATE + one prepared INSERT into tblQRScans (§4), both wrapped in try/catch **fail-open** (roadmap :277 — "one prepared INSERT, fail-open"); the redirect must never 500 because analytics hiccuped.
7. All error pages are WCAG-AA static HTML (E5 item 4's "accessible landing" mode later extends this same handler).

Latency budget: ≤20 ms overhead (E11 acceptance, roadmap :280) — 1 SELECT + 2 writes + rate check is within that on Dreamhost provided the rate check is the lean variant in §2.1.

### 1.4 Destination CRUD + validation

**Routes** (added to the router table, `public_html/api/v1/index.php:213-233` field conventions):

| Route | Method | auth | rateLimit | requiredScope | requiredFeature |
| --- | --- | --- | --- | --- | --- |
| `qrcodes/{uuid}/destination` | GET | true | true | `qrcodes:read` | null |
| `qrcodes/{uuid}/destination` | PATCH | true | true | `qrcodes:write` | null |

`{uuid}` here is the **redirectUUID**. Handler (`_functions/fn_api_redirects.php`, new):
- Fetch by UUID; ownership via a pure predicate `redirectIsWritableBy(?int $ownerUserId, ?int $ownerOrgId, ?int $callerUserId): bool` mirroring `qrCodeIsWritableBy()` (fn_api_qrcodes.php:237-266 — isPublic deliberately irrelevant to writes).
- **404-not-403 on any non-owned or missing row** (the established IDOR convention, fn_api_qrcodes.php:42-49) — a 403 would confirm the UUID exists.
- `disabledReason='abuse'` rows: PATCH returns 409 `REDIRECT_SUSPENDED` (owner cannot edit around a takedown; admin-only re-enable).
- On success: copy destinationURL→previousDestinationURL, write new value, stamp destinationUpdatedAt/updatedByUserID, and log `tblActivityLog` `activityType='redirect_destination_changed'` with old+new (install.sql:130-145). Feature gating is NOT re-checked on PATCH (editing an existing code isn't a new allocation; a downgraded past_due owner keeps edit access per the grace posture, fn_features.php `effectivePlanForGating()` :575 — creation is where the cap bites).
- No DELETE of the redirect row itself in this epic: deactivation (`isActive=0`, PATCH `{"active": false}`) is the supported off-switch; hard delete would break printed codes silently. Soft-delete semantics follow the qrLabel/deletedAt precedent (install.sql:226-235) if ever added.

**Destination validator** — `validateRedirectDestination(string $url, array $opts = []): array{valid: bool, normalized: string, error: ?string}` in `_functions/fn_redirects.php`, pure (unit-testable, §7). Ordered checks, **exact allowlist, fail-closed**:

a. Length ≤ 2048 (column width); reject any control chars, CR/LF (`Location` header-injection belt-and-braces even though we build the header from the validated value only).
b. `parse_url()` must succeed AND `FILTER_VALIDATE_URL` pass. **Scheme allowlist: `https` only** at launch; `http` accepted only when tblSettings `redirect_allow_http=1` (default 0 — owner decision O-2). Everything else — `javascript:`, `data:`, `file:`, `vbscript:`, `intent:`, custom app schemes — is rejected *by omission* (allowlist, never a denylist).
c. Reject userinfo (`user:pass@host`) — classic URL-spoofing/phishing obfuscation.
d. Host rules: non-empty; if the host is an IP literal (v4 or v6), reject loopback/private/link-local/reserved via `filter_var(FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)` (+ explicit `::1`, `fc00::/7`, `fe80::/10` checks — PHP's flags miss some v6 cases). **Why, given the server never fetches the URL:** (i) a `/r/` link is a laundering primitive for intranet-pivot phishing (mail victim on a corporate LAN → `http://192.168.1.1/admin`); (ii) the §2.3 reputation hook and any future link-preview/screenshot feature WILL fetch destinations server-side — validating now means SSRF is structurally impossible later rather than "remember to add it". Note honestly: DNS-rebinding (public hostname → private A record) is NOT fully preventable at validation time; it only matters if we ever fetch, and the fetch-side hook must re-resolve+pin (documented in §2.3).
e. Reject self-referential targets: destination host ∈ {cuercode.net, cuercode.me, qrmy.link, www.*} with path starting `/r/` — kills redirect loops and nested-laundering chains.
f. Non-ASCII/IDN hosts: normalise via `idn_to_ascii()` when ext-intl exists, else **reject non-ASCII hosts** (homograph-phishing surface; fail-closed beats fail-fancy on shared hosting). Store the punycode form.
g. Optional reputation check (§2.3) — a seam, not a launch dependency.

Same validator runs at create (§1.2 step 4) and PATCH. It is intentionally stricter than `buildPayload_url()`'s static-QR validation: a static QR is the *user's* string; a `/r/` destination is served **under CueRCode's domain reputation**.

---

## 2. Abuse posture — the G-007 crux

**The decision being made:** `/r/` makes CueRCode a public, unauthenticated redirect host. The failure mode is not a server compromise — it is *reputational*: phishers register, point dynamic codes at malicious pages, and burn the domain (Safe-Browsing blocklisting of `cuercode.net` would simultaneously break every customer's printed dynamic code — the single existential risk of this feature). G-007 is recorded `awaiting-user` at `.dev-team/autopilot.json:41`.

### 2.1 Rate limiting on `/r/`
- **Per-IP:** reuse the tblAPIRateLog window-count pattern (`checkRateLimit()`, fn_api_auth.php:582 — anonymous branch counts by `requestIP`) with a dedicated, lower ceiling from tblSettings (`redirect_rate_per_ip_min`, default 60/min). Client IP via the existing trusted-proxy-aware resolver (fn_api_auth.php:841-901).
- **Miss-throttling (enumeration defence):** UUIDv4 = 122 random bits, so brute-force enumeration is statistically dead; the residual is nuisance scanning. Count 404/invalid-shape outcomes per IP separately and block the IP (429) after `redirect_miss_limit_min` (default 10/min) — invalid-shape requests never even reach the DB (§1.3 step 1).
- **Per-uuid velocity ceiling:** `redirect_rate_per_uuid_min` (default 0 = off). Not an anti-abuse control for browsers so much as a circuit-breaker if one code is weaponised (e.g. embedded in a spam blast); tripping it serves a static 429 and flags the row for review.
- **Cost note (shared hosting):** the per-IP check is 1 SELECT COUNT + 1 INSERT per scan. If live latency testing (#18-style burst test) shows this blowing the 20 ms budget, the documented fallback is Cloudflare rate-limiting rules in front (the org has Cloudflare accounts) with the PHP check retained at a looser ceiling as defence-in-depth.

### 2.2 Abuse reporting + rapid takedown
- Public page `public_html/report/index.php` (`/report?uuid=…`, linked from the 410/404 pages and footer): a small form (reporter email optional, category, free text) writing `tblActivityLog` `activityType='redirect_abuse_report'` + queueing a notification through the existing email queue (tblEmailQueue, install.sql Table 11 / E8) to the owner. Bot-protected with the same Turnstile-first hook as F-012 once keys exist.
- **Takedown = one UPDATE:** `isActive=0, disabledReason='abuse'` — effective on the very next scan (no cache to purge; 302s are `no-store`). Admin re-enable only (§1.4).
- **Auto-suspend tripwire:** ≥ `redirect_abuse_report_threshold` (default 3) distinct-IP reports on one uuid within 24 h ⇒ automatic suspend pending review. Fail-safe direction: false-positive suspends a customer link for hours; false-negative leaves phishing live — suspend wins. Threshold + go/no-go is owner decision O-4.
- **Documented SLA** (Terms + abuse page): acknowledgement target for reports (owner decision O-4) — required before production, not for alpha.

### 2.3 URL-reputation seam (design now, wire later)
- `_functions/fn_url_reputation.php`: `checkDestinationReputation(string $url): array{verdict: 'ok'|'malicious'|'unknown', provider: string}` behind a tblSettings driver key `url_reputation_provider` ENUM-ish string (`none` default | `google_safe_browsing` | `web_risk`), API key stored `isSensitive=1` (AES-256-GCM via fn_encryption.php — the established secret-at-rest pattern, install.sql:59).
- Called (a) synchronously at create/PATCH — verdict `malicious` ⇒ reject 422 `DESTINATION_REJECTED`; provider unreachable ⇒ **fail-open with logging** (availability of a third party must not gate customer edits; the sync check is best-effort); and (b) as a periodic re-scan of active destinations on the E8 queue-processor hook — because the **primary residual risk is time-of-check/time-of-use**: a benign destination edited or compromised *after* validation. Re-scan hits ⇒ auto-suspend + owner notification.
- If this seam ever fetches destination bodies itself (it shouldn't — use the lookup APIs), it must re-resolve DNS and re-apply §1.4-d at fetch time (rebinding note).
- Needs an owner-supplied key + privacy note in privacy.php (URLs are shared with the provider) — owner decision O-3.

### 2.4 Logging & attribution
- Every destination change audited (§1.4); every scan recorded (§4); every dynamic code owned by an authenticated account (no anonymous dynamic codes — `anonymous.dynamic_codes=0` seed) with per-plan quotas (3/100/1000, Pricing_Strategy.md §3) so abuse always has an accountable subject and bounded blast radius per account.

### 2.5 Residual risk — stated honestly
1. TOCTOU destination flips (mitigated by re-scan 2.3-b + reports 2.2; not eliminated).
2. Compromised *legitimate* destination sites (same mitigations; not our code, still our domain reputation).
3. Domain-level blocklisting remains possible if takedown is slower than abuse velocity — the reason quotas + accounts + tripwire exist, and the reason O-11 (separate redirect domain `qrmy.link`) is worth deciding: it firewall-partitions redirect reputation away from the primary product domain.
4. Free-tier economics: 3 free dynamic codes per account is still enough for a determined phisher with many accounts; email verification (E3, exists) + per-IP registration limits are the current answer; paid-only dynamic is the escalation lever if abused in practice.

### 2.6 Alpha vs production go-live bar
**Acceptable on alpha now** (small invited test group, `public_html_dev_alpha` behind DS-1 htpasswd once enforced): Stages 0-2 with quotas, per-IP limiting, `X-Robots-Tag: noindex`, manual abuse handling via email, no reputation provider.
**MUST be true before `/r/` serves production traffic on main:** (a) abuse-report page + takedown runbook + SLA published; (b) auto-suspend tripwire live; (c) rate limits burst-tested on the live host (#18 method); (d) Terms of Service covering redirect abuse/takedown (feeds legal review #38); (e) reputation-provider decision made (even if the decision is "none at launch", it must be a decision); (f) monitoring: daily anomaly report (top codes by scan volume + new-destination diff) via the E8 queue; (g) owner G-007 sign-off recorded in LAUNCH_DECISIONS.md; (h) recommended: Cloudflare proxy in front of the redirect host.

---

## 3. Dual-function QR (#71 ↔ ProjectEPass#18)

### 3.1 `tblSanctionedApps` (new)

| Column | Type | Notes |
| --- | --- | --- |
| `appID` | INT UNSIGNED PK AI | |
| `appUUID` | CHAR(36) NOT NULL UNIQUE | Public identifier; basis of the signature `key_id` |
| `orgID` | INT UNSIGNED NOT NULL FK→tblOrganisations | Org-scoped registration (PE#18 dependency note: "sanctioned-app registration likely org-scoped", their #17) |
| `appName` | VARCHAR(100) NOT NULL | |
| `appSecretEncrypted` | TEXT NOT NULL | 32 bytes from `random_bytes()`, encrypted **AES-256-GCM via `encryptValue()`** (fn_encryption.php:120 — HKDF-derived key, per-record random salt embedded in the blob, :147,165). Encrypted-not-hashed **because HMAC signing requires recovery** — the deliberate inverse of API keys, which are SHA-256-hashed (install.sql:300) because they only need comparison |
| `secretVersion` | INT UNSIGNED NOT NULL DEFAULT 1 | Rotation: new secret ⇒ version+1; old version honoured for a configurable overlap window |
| `allowedCapabilitiesJSON` | JSON NOT NULL | e.g. `["resolve"]`; fail-closed capability check per request (absent ⇒ deny), same generic-lookup philosophy as featuresJSON (fn_features.php:635-708) |
| `linkedApiKeyID` | INT UNSIGNED NULL FK→tblAPIKeys | The `resolve`-scoped transport key issued alongside the app |
| `isActive` / `revokedAt` | TINYINT(1) / DATETIME NULL | Kill-switch |
| `createdAt` / `updatedAt` | DATETIME | |

Registry CRUD is **session-only** (org admin dashboard) — the same anti-self-escalation posture as API-key management (`apiKeyManagementAllowedFor()`, fn_api_keys.php:143-158: an X-API-Key caller may never mint credentials). Secret displayed exactly once at creation (API-key precedent).

### 3.2 Signature scheme — recommendation: **HMAC-SHA256, verified server-side by ProjectEPass; Ed25519-JWS deferred to the offline variant**

- **Why HMAC-SHA256:** `hash_hmac()` is core PHP — zero extension risk on Dreamhost (contrast the bcmath caveat CLAUDE.md already carries for PDF417). Symmetric fits the existing secret-at-rest machinery (§3.1). The verifying party is the **ProjectEPass backend**, not the mobile binary: the organiser app relays the resolve response to its own server, which verifies the HMAC and burns the nonce (PE#18 explicitly recommends "check-in is authoritative in ProjectEPass"). Server-to-server symmetric keys are safe; the classic HMAC weakness (verifier can also forge) is inert because both holders are the two platforms' servers.
- **Hard contract constraint (must be written into PE#18):** the app secret NEVER ships in the mobile app binary. If PE instead wants on-device verification, HMAC is wrong and the answer below applies early.
- **Why not Ed25519-JWS now:** it buys on-device verification (device holds only a public key), which matters solely for the **offline `/e/{segments}` parse variant** (PE#18 point 5 — fast door scanning without network). That variant is deferred (Stage 5); when it ships, sign the offline micro-payload with Ed25519 (`sodium_crypto_sign_detached` — sodium is bundled since PHP 7.2 but **verify enabled on the production host**, same checklist item pattern as bcmath). The envelope carries an `alg` field from day one so this is an addition, not a migration.
- **Replay defence:** single-use `check_in_nonce` (32 bytes, base64url) + short TTL. CueRCode issues and records nonces (`tblResolveNonces`: nonceHash CHAR(64), redirectID, appID, issuedAt, expiresAt — hash-only at rest, comparison-only need); ProjectEPass burns at check-in (contract ownership per PE#18: "CueRCode issues nonce; ProjectEPass burns it"). Defaults: TTL 120 s, clock-skew tolerance ±30 s (owner/contract decision O-7). Every resolve is also logged as a tblQRScans event `scanChannel='resolve'`, so replay *attempts* are at least visible on both sides.

### 3.3 `GET /api/v1/resolve/{uuid}` — router entry: `auth=true, rateLimit=true, requiredScope='resolve', requiredFeature=null`

- New scope `resolve` appended to `CUERCODE_API_KEY_SCOPE_ALLOWLIST` (fn_api_keys.php:116-126 — documented one-line change + route wiring; becomes the 8th scope) with a label in `apiKeyScopeLabel()`. Wrong/missing scope ⇒ the existing 403 `INSUFFICIENT_SCOPE` path.
- Handler checks, in order (404 `NOT_FOUND` for every identity-revealing failure, per the IDOR convention): uuid shape → row exists → `isDualFunction=1` → row's `appID` corresponds to the calling key's sanctioned app (`linkedApiKeyID` join) → app `isActive` and capability `resolve` present → redirect row active/not-expired (expired dual codes resolve to 410-semantics JSON error `RESOLVE_GONE`).
- Feature gating is at **create** time (`dual_function_qr`, §5); resolve-time re-check only that the *owner org's* effective plan still has the feature (fail-closed `featureEnabled()` against the owner, so a downgraded org's dual codes stop resolving — matches the past_due grace model where read continues but gated *function* stops; explicitly documented behaviour).
- **Response envelope (JWS-compact-style to dodge JSON canonicalisation entirely — sign bytes, not structures):**

```json
{
  "success": true,
  "data": {
    "payload_b64": "<base64url(JSON payload bytes)>",
    "signature":   { "alg": "HS256", "key_id": "app_{appUUID}_v{secretVersion}", "value": "<base64url(HMAC-SHA256(payload_b64))>" }
  }
}
```

- **Payload fields — exact PE#18 mapping** (their proposed schema `{event_id, organiser_id, pass_ref, attendee_ref, check_in_nonce, issued_at, expires_at, signature}`):

| PE#18 field | Source | Notes |
| --- | --- | --- |
| `event_id`, `organiser_id`, `pass_ref`, `attendee_ref` | `tblQRRedirects.resolvePayloadJSON` | Owner-supplied at create/PATCH; CueRCode treats them as opaque strings (PE semantics) |
| `check_in_nonce` | minted per resolve (§3.2) | single-use, PE burns |
| `issued_at` / `expires_at` | server clock / +TTL | Unix seconds |
| `signature` | **moved out of the payload** into the envelope above | signing bytes-over-the-wire is strictly safer than PE's inline-field sketch (no canonicalisation ambiguity) — propose as a friendly counter on PE#18 |
| *(additions)* `version:1`, `uuid`, `web_destination` | ours | lets the app fall back to the web page gracefully |

### 3.4 Content negotiation on `/r/{uuid}` — **defer (Stage 5, optional)**
PE#18 offers "resolve API **or** content-negotiation"; their app must recognise domain+path shape and branch anyway (their build item 1), so a separate resolve endpoint costs the app nothing. Keeping `/r/` **entirely unauthenticated and logic-free** is a real security property: no credential handling, no signature verification, no JSON emission on the public hot path; all authenticated machinery stays behind the audited API router (auth/scope/rate stack). If later adopted: `Authorization: CueRCode-App keyId=…, ts=…, sig=HMAC(method|path|ts)` → internally delegate to the resolve handler; else 302. One URL, two behaviours, zero new trust surface on the anonymous branch.

### 3.5 Offline `/e/{segments}` variant — **defer with reserved shape**
Reserve the URL shape `…/r/{uuid}/e/{segments}` now (rewrite rule tolerates and strips `/e/…` for browsers ⇒ same 302 — so printed offline-capable codes degrade perfectly for consumers). Actual offline parse + Ed25519 signing ships only after PE confirms need + O-6; segments would carry `{event_id, pass_ref, exp, ed25519_sig}` in base64url.

---

## 4. Scan analytics (#33 / E11 / G-008)

### 4.1 `tblQRScans` (new; the E11 spec's table, named per roadmap draft "tblScanAnalytics" → prefer tblQRScans for consistency with tblQRCodes/tblQRRedirects)

| Column | Type | Notes |
| --- | --- | --- |
| `scanID` | BIGINT UNSIGNED PK AI | |
| `redirectID` | BIGINT UNSIGNED NOT NULL FK→tblQRRedirects | Misses (404s) are NOT rows here — they go to the §2.1 miss counter; keeps this table clean + FK NOT NULL |
| `scannedAt` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | |
| `scanChannel` | ENUM('redirect','resolve') NOT NULL DEFAULT 'redirect' | Dual-function visibility (§3.2) |
| `outcome` | ENUM('redirected','expired','disabled','not_yet_active') NOT NULL | 410-page hits still count — owners want to see scans of dead codes |
| `ipHash` | CHAR(64) NOT NULL | SHA-256(ip + server pepper) — **never raw IP** (GDPR posture, roadmap :280); pepper is a tblSettings `isSensitive=1` value; uniques are approximate by design |
| `country` | CHAR(2) NULL | NULL until GeoLite2 mmdb lands in `_libraries/` (E11 full; local lookup only, no external calls) |
| `deviceClass` / `os` / `browser` | VARCHAR(20/30/30) NULL | Coarse UA parse (pure function); raw UA is NOT stored |
| `referrerHost` | VARCHAR(255) NULL | Host only, never full referrer URL |

Indexes: `INDEX(redirectID, scannedAt)`, `INDEX(scannedAt)` (retention pruning).
Write path: the single fail-open prepared INSERT in §1.3 step 6.

### 4.2 Scope split
**In-scope NOW (Stage 3):** event capture on `/r/` + `hitCount`; `GET /api/v1/qrcodes/{uuid}/analytics` (`auth=true, requiredScope='qrcodes:read'`, 404-not-403 own-scope) returning `{total_scans, last_scanned_at, scans_by_day (last N), outcomes}` where N = `limitFor('analytics_days')` — free tier's 0 ⇒ totals only (tier matrix, PROJECT_ROADMAP.md:314); UA coarse-parse.
**LATER (E11 full, after E3 dashboard + E4 retention):** GeoLite2 country/region, dashboard charts, CSV/JSON export (`csv_export` feature flag already seeded — seed_data.sql per fable_analysis A2), retention pruning job per `analytics_days` on the E8 queue hook, Go2My.Link analytics merge (E1b).

---

## 5. Tier gating

- **featuresJSON keys (both fail-closed — absent key ⇒ `featureEnabled()` false, fn_features.php:635-708):**
  - `dynamic_qr`: seeded `true` for free/pro/business (+enterprise); count still capped by the existing `dynamic_codes` limit key (already seeded: anonymous 0 / free 3 / pro 100 / business 1000 — Pricing_Strategy.md §3.1-3.4 / seed_data.sql:478-497). Anonymous is doubly excluded (flag absent AND limit 0).
  - `dual_function_qr`: seeded `true` for **business + enterprise only** (Pricing_Strategy.md:96 and :104; Enterprise adds "sanctioned-app registry allowance" — model as a new limit key `sanctioned_apps`, e.g. business 1 / enterprise −1).
- **Enforcement points:** create-time in `handleGenerate()` via `requireFeatureOr402()` (402 + `upgrade_url`, fn_features.php:710,738); count caps under the F-023 advisory lock; resolve-time owner-plan re-check (§3.3). Zero engine changes — these are seed edits + two call sites, exactly the "new tiers/keys are seed edits, not deploys" property (Pricing_Strategy.md:30).
- **API scope:** `resolve` added to the 7-scope allowlist (§3.3). No other scope changes — destination CRUD rides `qrcodes:read`/`qrcodes:write`.

## 6. Geo/platform variant rules (FG-006) — **recommend: defer, ship the seam**

Defer `tblQRVariants`/`tblQRRules` (FEATURES.md:12) out of this epic. Reasons: (1) geo needs GeoLite2, itself deferred to E11-full; (2) a rules engine on the unauthenticated hot path is exactly the complexity §3.4 keeps off `/r/`, and it widens the G-007 surface before the base posture is proven; (3) FG-006 is `queue`, not committed, and no tier sells it yet. **Ship now instead:** the destination resolution in §1.3 goes through one function — `resolveRedirectDestination(array $row, array $scanContext): string` — which today returns `destinationURL` and is the *only* place rules will ever plug in; `$scanContext` (uaPlatform, country-when-available) is already assembled for tblQRScans, so FG-006 later becomes a new function body + two tables, zero route changes.

## 7. Testability in this environment (no MySQL — mirror the pricing-schema approach)

**DB-independent pure units (add to `tests/` — dependency-free by design, DEV_NOTES §Testing; 400+ existing assertions style):**
- `validateRedirectDestination()` — full matrix: schemes (https ok; http per-flag; javascript/data/file/intent rejected), userinfo, private/loopback v4+v6 literals, CR/LF/control chars, length, IDN/punycode, self-referential `/r/` loop rejection.
- `buildPayload_dynamic_url()` — UUID regex accept/reject, base-host composition (pattern: `tests/test_payloads.php`).
- `redirectOutcomeFor()` — expiry/inactive/scheduled/missing decision table against fixed clocks.
- HMAC envelope: sign→verify round-trip, tamper detection, `key_id` composition, base64url codec, nonce TTL + clock-skew window evaluation (`issued_at`/`expires_at` math), secretVersion rotation-overlap acceptance.
- Ownership predicates `redirectIsWritableBy()` / resolve-eligibility predicate (pattern: fn_api_qrcodes.php:211-273 pure predicates).
- UA coarse-parser; rate-limit decision math as a pure function over counts.
**Structural-SQL-only (no live MySQL here — same technique as the pricing-flex schema tests, `tests/test_pricing_flex.php`):** parse install.sql/migration text asserting tblQRRedirects/tblSanctionedApps/tblQRScans/tblResolveNonces columns, indexes, FK actions, and the XOR-owner comment conventions.
**Live-host-only (E10-style scripts, cannot run here):** actual 302 round-trip + header assertions, 410/404 pages, rate-limit burst (#18 method), advisory-lock contention, end-to-end resolve with a scoped key.

## 8. Phased build plan (each stage = one shippable PR; commit-not-push per house rule)

| Stage | Contents | Gate |
| --- | --- | --- |
| **S0 — Schema + pure core (dark)** | Migration + install.sql: tblQRRedirects, tblQRScans, tblResolveNonces (tblSanctionedApps may ride along or wait for S4); `fn_redirects.php` validators + outcome/ownership pure functions; `buildPayload_dynamic_url()`; `dynamic_url` seed row **`isActive=0`** (type invisible in `/types` until flipped); full §7 unit + structural tests | none — inert |
| **S1 — Owner-facing API (still dark)** | `fn_api_redirects.php`: create path in `handleGenerate()` (feature+limit+lock), GET/PATCH destination, activity audit; OpenAPI + `x-error-codes` (the #58 drift guard fails closed if skipped); IDOR test pack | none — `/r/` still doesn't exist; codes generated in alpha testing only |
| **S2 — `/r/` goes live** ⚠️ | `public_html/r/index.php` + rewrite; rate limiting + miss-throttle; 410/404/429 accessible pages; scan INSERT + hitCount; abuse-report page + takedown UPDATE path + tripwire; flip `dynamic_url` `isActive=1` on alpha | **THE G-007 LINE.** Alpha deploy needs owner G-007 approval for the *test group*; production/main promotion additionally requires every §2.6 MUST item. Owner sign-off gates exactly this PR's deployment |
| **S3 — Analytics basics (#33 partial, G-008)** | `GET /qrcodes/{uuid}/analytics` totals/by-day per `analytics_days`; UA coarse-parse; anomaly digest email via E8 queue | rides the S2 approval |
| **S4 — Dual-function (#71)** | tblSanctionedApps + org-admin registry (session-only CRUD); `resolve` scope (8th); `/api/v1/resolve/{uuid}` + HMAC envelope + nonce mint/store; `dual_function_qr` + `sanctioned_apps` seeds; PE#18 contract points confirmed (URL shape ✓, HMAC-SHA256 server-verified, envelope counter-proposal §3.3, nonce ownership, PE-authoritative check-in) | PE#18 agreement; owner O-6/O-7 |
| **S5 — Deferred options** | `/r/` content-negotiation alias; offline `/e/{segments}` + Ed25519 (sodium verified on host); FG-006 rules engine; reputation provider wiring; FG-004 password / FG-005 scheduling UI on the redirect layer; E1b Go2My.Link driver (`redirectProvider` ENUM) when their #38/#39/#75 ship | per-item owner decisions |

## 9. Open owner decisions (→ LAUNCH_DECISIONS.md, new section "E1a/#71 — Dynamic & dual-function QR")

- **O-1** G-007 go/no-go: approve CueRCode as a redirect host — split sign-off: (a) alpha test group at S2, (b) production promotion once §2.6 MUST-list is evidenced.
- **O-2** Destination schemes: https-only at launch (recommended) vs allow http behind `redirect_allow_http`.
- **O-3** URL-reputation provider: none / Google Safe Browsing / Web Risk — needs owner API key + privacy.php disclosure; sync-check fail-open stance confirmation.
- **O-4** Abuse ops: auto-suspend threshold (default 3 distinct reports/24 h), takedown SLA wording, abuse@ mailbox.
- **O-5** Cloudflare proxy in front of the redirect host before production (recommended; accounts exist).
- **O-6** Signature contract with ProjectEPass: HMAC-SHA256 with server-side verification + "secret never in the app binary" clause (recommended); Ed25519 deferred to the offline variant — confirm on PE#18, incl. per-organiser-org vs per-platform registration.
- **O-7** Nonce TTL 120 s / clock skew ±30 s defaults — confirm with PE.
- **O-8** `dual_function_qr` tier placement: business+enterprise (per Pricing_Strategy.md §3.4/§3.5) vs enterprise-only; `sanctioned_apps` limits (1 / −1 proposed).
- **O-9** FG-006 deferral confirmation (seam-only now).
- **O-10** Check-in authority: ProjectEPass authoritative, CueRCode logs resolve events only (recommended; PE#18 last contract point).
- **O-11** Printed redirect domain: `cuercode.net/r/` vs the tertiary **`QRmy.Link`** (shorter URL ⇒ lower QR version/larger modules, and — decisive for §2.5 — partitions redirect-abuse reputation away from the primary product domain). Affects `redirect_base_host` seed before any code is printed; hard to change after.
