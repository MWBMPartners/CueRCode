# 🗺️ CueRCode — Master Launch Roadmap

> **Produced by:** Deep-Planning Round 1 (Fable planning lead) — 2026-07-09
> **Verified against:** branch `autopilot/2026-06-28` @ `f5cf408` (VERIFY overall PASS, re-confirmed locally on 2026-07-09: `php tests/run.php` → **400/400 assertions pass**), live GitHub state (issues #1–#46, milestones 1–7, Project #5, PR #46), and the public sibling repos `MWBMPartners/Go2My.Link` and `MWBMPartners/SIGNula.id`.
> **Scope:** Path from Phase-1 code-complete to FULL LAUNCH of the CueRCode service suite, plus major new scope (dynamic QR, SSO, accounts, premium tiers, accessibility systems, developer portal, i18n).
> **Nothing in this document was assumed** — every claim is cited to a file path, issue number, or repo URL. Where a prior doc's claim was found to be WRONG, it is flagged ⚠️ explicitly.

---

> ## 📌 Status update 2026-09-23 — read this first
>
> This document is a historical plan from 2026-07-09. It is **not rewritten** here — everything below this banner is kept as it was written, for the record — but several of its central claims are no longer true:
>
> - **"Zero authentication code" and "7 paths" are no longer true.** Since this plan was written, the following have all been built in code (with tests passing): accounts (E3 stages 1/2a/2b — registration, login, sessions, saved codes, profile), SSO (E2 — Microsoft 365 + Google, switched off pending owner setup), email (E8 — Microsoft Graph sender + queue, switched off), the tiers/gating framework (E4), accessibility (E5 — colourblind toggle, accessible-QR presets, print-size calculator), the developer portal (E6), API platform stage 1 (E7 — self-service API keys, pagination, error catalogue), and barcode generation (#69, 11 symbologies). The OpenAPI spec now has 30 paths, not 7.
> - **PR #46 and HB-1 are long done.** The branch model has moved on entirely — it is now a four-tier `work-in-progress → alpha → beta → release-candidate → main` cadence (see DEV_NOTES.md "Branch Model & CI/CD Cadence"), not the single-`main`-PR model this document was written against.
> - **The real blockers today** are the owner/live-server items tracked in `LAUNCH_DECISIONS.md`, plus the fresh gaps found in the 2026-09-23 issue audit and listed in `PROJECT_STATUS.md`'s "Known gaps found in the 2026-09-23 audit" section — not the items this document lists as blockers.
>
> **One-line status per epic (E1–E12), 2026-09-23:**
> - **E1a** (in-house redirect groundwork, Stage S0/S1) — built but switched off; no public `/r/` route yet, gated on owner decision G-007.
> - **E2** (SSO) — built but switched off; needs the owner to register OAuth apps with Microsoft/Google.
> - **E3** (accounts & orgs) — partly done; no organisations (#21) and no passkeys (#16) yet.
> - **E4** (tiers & pricing) — framework plus the #70 flexible-pricing extension are built but disabled; PayPal (#26) not started.
> - **E5** (accessibility) — built.
> - **E6** (developer portal) — built; the portal's API-key text was corrected 2026-09-23 (it used to say keys were "coming with accounts"/admin-issued, which is no longer true).
> - **E7** (platform-grade API, stage 1) — built; idempotency keys and webhooks are not built yet.
> - **E8** (email) — built; the queue runner was added 2026-09-25 (#112). Needs M365 setup, the DreamHost cron job and a live test.
> - **E9** — not started.
> - **E10** — not run (needs the live server).
> - **E11** (analytics) — schema only, nothing writes to it yet.
> - **E12** (CI/CD) — partly done; `beta` and `main` still lack `ci.yml` (`beta` tracked in #115), and the SFTP deploy has never actually run (secrets not set, #44).

---

## Launch Readiness Assessment

### Verdict

**(a) Soft-launch of the core anonymous generator: READY-MINUS-OPS.** The code side is genuinely production-ready for Phase-1 scope — independently verified (VERIFICATION.md, `verdict.json`: overall PASS @ `f5cf408`; 0 unresolved Critical/High security findings; 26 QR types; full UI↔API parity; complete OpenAPI 3.0.3 at `web/CueRCode.net/public_html/api/v1/openapi.json` with 7 paths; 400/400 tests re-run green today). What stands between the repo and a soft launch is **operations, not code**: merge PR #46 (currently OPEN, `mergeable: MERGEABLE`, `mergeStateStatus: BLOCKED` pending review/required checks), configure deploy secrets, run the first Dreamhost deploy, and execute the four owner-gated live-server verification issues (#13, #17, #18, #22). Legal template pages also need counsel review before public traffic (#38).

**(b) Full-suite launch: NOT READY — approximately 5 major workstreams away.** There is **zero authentication code** (tblUsers/tblOrganisations are schema stubs — `_includes/sql/install.sql:346-393`, comments literally say "Phase 2 stub - schema only in Phase 1"), no email capability, no payments/tiers, no dynamic QR/redirect layer, no analytics, and no SSO. ⚠️ Contrary to some prior doc phrasing, **no subscription, payment, session, OAuth, or WebAuthn tables exist in install.sql** — verified by grep: `tblUserSessions|tblSubscription|tblPayments|tblUserOAuth|tblWebAuthn|tblOrganisationMembers|tblPasswordResets|tblInvitations` → **zero matches** in `web/CueRCode.net/_includes/sql/`. The only monetisation artefact is a `subscriptionTier VARCHAR(50) DEFAULT 'free'` column on the tblOrganisations stub (install.sql:384). All of that schema must be designed and migrated, not merely "wired up".

### Hard blockers vs nice-to-haves

| # | Item | Type | Blocks | Evidence |
| --- | --- | --- | --- | --- |
| HB-1 | **Merge PR #46 → main** (20 commits, +11,584/−647 across 53 files) | Hard blocker | Everything (deploys track `main`) | `gh pr view 46`: OPEN/BLOCKED; branch `autopilot/2026-06-28` |
| HB-2 | **Deploy secrets + first live deploy** (`SFTP_HOST/PORT/USER/KEY/PASSWORD/REMOTE_BASE` secrets, `SFTP_ENABLED` var) | Hard blocker | Soft launch | `.github/workflows/deploy.yml:35-41` (triggers on main/beta/alpha); DEV_NOTES.md §CI/CD |
| HB-3 | **Live-server verification** #13 (DB install), #17 (all QR types/formats), #18 (API), #22 (responsive UI/a11y) | Hard blocker | Soft launch | verdict.json `owner_gated`; milestone 2 |
| HB-4 | **Legal counsel review** of privacy.php + terms.php (explicitly marked TEMPLATE) | Hard blocker | Public traffic | #38; `public_html/privacy.php`, `public_html/terms.php` |
| HB-5 | **Accounts + email foundation** (E3 + E8) | Hard blocker | Full-suite launch (accounts, saves, tiers, SSO all depend on it) | install.sql:346-393 stubs; gate ledger G-001/G-005 |
| HB-6 | **Bot protection decision** (F-012, Turnstile > reCAPTCHA > hCaptcha) — anonymous generate is an abuse surface once public | Hard blocker (soft launch, owner decision + API keys) | Soft launch hardening | SECURITY.md F-012 open; ProjectBrief §7.3 |
| NH-1 | F-004 CSP `unsafe-inline` removal + SRI on CDN tags | Near-hard (needs live UI to finalise hashes) | Launch hardening | SECURITY.md F-004 OPEN-QUEUED; `_includes/header.php` has **0** `integrity=` attrs (landing page already has them — `public_html_landing/index.php:48,197` — proving it's doable) |
| NH-2 | Alpha/beta env access control — `Require valid-user` is **commented out** in both dev `.htaccess` files | Near-hard (only if dev envs go live) | Dev-env exposure | `public_html_dev_alpha/.htaccess` + `public_html_dev_beta/.htaccess` (AuthUserFile + Require commented) |
| N2H-3 | JPEG XL format (#40), i18n (#34/G-012), scan analytics (#33), admin dashboard (#31), WebAuthn (#16) | Nice-to-have for launch | Full-suite completeness | Respective issues |

### Gap analysis mapped to milestones

| Milestone (GitHub) | State today | Gap to launch |
| --- | --- | --- |
| **1 — Phase 1: Core QR Engine** (6 closed / 8 open) | Code-complete + locally verified | Open issues #1–#5, #7, #9, #40 are stale-open (see reconciliation below) — mostly need close-with-evidence or live-test hand-off |
| **2 — Phase 1.5: Live Server Testing** (0/4) | Not started — owner-gated | THE soft-launch gate: #13/#17/#18/#22 |
| **3 — Phase 2: Accounts & Orgs** (0/10) | Schema stubs only; no code | Epic E3 (+ E8 email prerequisite) |
| **4 — Phase 3: Premium & Payments** (0/2) | Nothing (no tables, no gating) | Epic E4 |
| **5 — Phase 4: Integrations** (0/4) | Nothing; sibling repos assessed below | Epics E1 (Go2My.Link), E2 (SIGNula), E8 (Graph email) |
| **6 — Phase 5: Advanced/Polish** (1 closed / 3 open) | Bulk-gen done (#32 closed) | #31 admin, #33 analytics, #34 i18n → E3/E11/E9 |
| **7 — Phase 6: CI/CD, Legal** (0/5) | ⚠️ Workflows actually EXIST (`deploy.yml`, `version-bump.yml`, `release.yml`) — issues stale | #35/#36/#44 need live-verify + close; #37 partial; #38 needs counsel |

**Recommended new milestones:** "Soft Launch (anonymous generator live)" and "Full Launch (accounts + premium + dynamic)" — the existing phase milestones remain as workstream containers, but launch tracking needs the two cross-cutting gates.

---

## Reconciled Issue/Milestone State

True status established by cross-checking every issue against the actual code (not prior claims — per `.claude/memory/feedback_audit_first.md`). 46 issues total: 10 closed, 36 open.

| # | Title (abridged) | GitHub state | TRUE status | Evidence | Recommended action |
| --- | --- | --- | --- | --- | --- |
| 1 | Stage 1: Foundation & directory structure | OPEN (label `completed`) | done-in-code | Tree matches ProjectBrief §10; `bootstrap.php` verified | **Close with evidence**; live residue tracked by #13 |
| 2 | Stage 2: DB schema & SQL installation | OPEN (`completed`) | partial | 10 tables in install.sql; users/orgs are stubs (install.sql:346-393) | **Keep open**, relabel `phase-2` — closes when E3 schema lands |
| 3 | Stage 3: Core PHP framework | OPEN (`completed`) | done-in-code | 19 `fn_*.php` files; 122/122 `php -l`; 400/400 tests | **Close with evidence** |
| 4 | Stage 4: QR generation engine | OPEN (`completed`) | done-in-code (26/26 types; JPEG XL host-blocked → #40) | 26 `buildPayload_*` in `fn_qr_payloads.php` (counted today) | **Close**; JPEG XL residue stays in #40 |
| 5 | Stage 5: Responsive web UI | OPEN (`completed`) | done-in-code, needs live a11y test | index.php + colourblind toggle + cookie banner in code | **Close**; residue tracked by #22 |
| 6 | Stage 6: REST API | CLOSED | correctly closed | 7 routes in `api/v1/index.php:176-233`; openapi.json 3.0.3 valid | none |
| 7 | Stage 7: Testing, docs & polish | OPEN (`completed`) | done-in-code except screen-reader testing | tests/ suite (400 assertions); docs current | **Close**; fold screen-reader testing into #22 + E5 |
| 8 | Rebrand QRmy.Link → CueRCode | CLOSED | correct | — | none |
| 9 | WCAG 2.1 AA compliance | OPEN | partial — code-level AA foundation, no assistive-tech testing | ARIA/skip-link/reduced-motion present; no SR test | **Keep open**, needs-live-test (#22) + E5 |
| 10 | Project initialisation | CLOSED | correct | — | none |
| 11–16, 19–21, 23, 24 | Phase-2 accounts/auth family (schema, registration, login, reset, WebAuthn, M365 SSO, Google SSO, orgs, profile, saved library) | OPEN | not-started (no auth code exists) | grep: no `password_hash` anywhere in `_functions/` | **Keep**; parent them under epic issue E3 (E2 for #19/#20) |
| 13, 17, 18, 22 | Live-server verification suite | OPEN | needs-live-test (owner-run) | verdict.json owner_gated | **Keep** — this IS epic E10; execute at soft launch |
| 25 | Subscription tiers + gating | OPEN | not-started (⚠️ no tables exist, contrary to issue's implication) | grep tblSubscription → none | **Keep**; parent under E4 |
| 26 | PayPal integration | OPEN | not-started | no payment code | **Keep**; parent under E4 |
| 27 | Go2My.Link integration | OPEN | not-started; external API **does not exist yet** (see E1) | sibling repo inspected | **Keep**; re-scope per E1 (build local redirect layer first) |
| 28 | SIGNula linking + SSO | OPEN | not-started; SIGNula **not implemented yet** (see E2) | sibling repo inspected | **Keep**; re-scope per E2 (contract-first) |
| 29 | MS Graph email | OPEN | not-started — prerequisite for all of Phase 2 | no Graph client in code | **Keep**; promote to earliest Phase-2 slot (E8) |
| 30 | Google Workspace email (future) | OPEN | not-started, deliberately future | — | **Keep**, low priority |
| 31 | Admin dashboard | OPEN | not-started (needs auth) | gate G-009 | **Keep**; sequence after E3 |
| 32 | Bulk QR generation | CLOSED | correctly closed — verified in code | `fn_api_bulk.php` + `bulk-generate` route (index.php:197) + OpenAPI path | none |
| 33 | Scan analytics | OPEN | not-started; depends on redirect layer (E1) | no tblScanAnalytics | **Keep**; sequence after E1 (epic E11) |
| 34 | i18n framework | OPEN | not-started (G-012 queued spec exists) | hardcoded strings in index/header/footer | **Keep** → E9 |
| 35 | GH Actions: version numbering + CHANGELOG | OPEN | ⚠️ **done-in-code, stale** — `version-bump.yml` + `release.yml` exist and are configured | `.github/workflows/` listing | **Close after first live run proves them**; or relabel needs-live-test |
| 36 | GH Actions: SFTP auto-deploy | OPEN | ⚠️ done-in-code, stale — `deploy.yml` covers main/beta/alpha | deploy.yml:35-41 | **Close as duplicate of #44** (or vice-versa — keep exactly one, relabel needs-live-test) |
| 37 | Data-protection compliance | OPEN | partial — cookie banner shipped (footer.php); export/erasure needs accounts | FG-026 BUILT; erasure deferred | **Keep**; erasure/export folded into E3 acceptance criteria |
| 38 | Enhanced legal pages | OPEN | partial — multi-jurisdiction TEMPLATE pages shipped, counsel review pending | privacy.php/terms.php marked TEMPLATE | **Keep**, relabel `needs-legal-review` — hard launch blocker HB-4 |
| 39 | Missing QR types (Messenger/Instagram/Rating) | CLOSED | correctly closed — builders verified present | `buildPayload_facebook_messenger/instagram/rating` counted in fn_qr_payloads.php | none |
| 40 | JPEG XL output | OPEN | blocked-by-host (Dreamhost GD lacks JXL) | FG-030 analysis | **Keep**, relabel `blocked-host`; document WEBP fallback (already recommended) |
| 41 | Remove closing PHP tags | CLOSED | correct | verified clean | none |
| 42 | Colourblind toggle | CLOSED | correct — Okabe-Ito palettes in code | `assets/css/cuercode.css:46-71`, index.php:348-353 | none (live check in #22) |
| 43 | Branding PNGs / og-image | CLOSED | correct — file exists | `public_html/assets/img/branding/og-image.png` | none (live check in #22) |
| 44 | SFTP auto-deploy alpha/beta/main | OPEN | ⚠️ done-in-code, stale (same as #36) | deploy.yml | **Keep this one**, close #36 as dup; relabel needs-live-test |
| 45 | API parity + security + OpenAPI | CLOSED | correctly closed | `buildCustomizationFromRequest()` 21 params; openapi complete | none |
| 46 (PR) | Autopilot Phase-1 PR | OPEN/BLOCKED | ready to review+merge | MERGEABLE; 400/400 tests at head | **Owner: review + merge** (HB-1) |

**Gate-ledger (`.dev-team/autopilot.json` G-001…G-012) mapping:** G-001→E3, G-002→E4(payments), G-003→E4(tiers), G-004→E2, G-005→E8, G-006→E3(WebAuthn stage), G-007→E1(redirect layer), G-008→E11(analytics), G-009→E3(admin, late stage), G-010→deep-sweep item DS-7 (per-record salt schema change), G-011→owner decisions logged as `for consideration` issues, G-012→E9.

---

## Roadmap (Prioritised Epics)

Model key: **Haiku** = mechanical/boilerplate · **Sonnet** = standard feature work · **Opus** = genuinely complex reasoning only. Effort: S (≤1 day-equivalent) / M (multi-day) / L (multi-week workstream).

### Recommended build order & critical path

```
CRITICAL PATH ────────────────────────────────────────────────────────────────
  E10a merge PR#46 → E10b deploy+secrets → E10c live verify (#13/#17/#18/#22)
   → SOFT LAUNCH (anonymous generator, landing→production swap)
   → E8 email → E3 accounts (core) → E4 tiers+payments → FULL LAUNCH (paid)
PARALLEL TRACKS ──────────────────────────────────────────────────────────────
  Deep sweep (DS-1..10, F-004/F-012)            — anytime, before soft launch
  E1a local dynamic-redirect layer               — after G-007 approval; edit UI needs E3
  E1b Go2My.Link client                          — blocked on Go2My.Link #38/#39 (their repo)
  E11 scan analytics                             — after E1a
  E5 accessible presets/templates                — anytime (standalone); a11y landing pages after E1a
  E6 dev portal docs                             — start anytime; key self-service after E3
  E7 API robustness                              — after E3 (key lifecycle), before native apps
  E2 SIGNula SSO                                 — after E3 + upstream SIGNula contract work
  E9 i18n                                        — after UI stabilises, before full launch
```

Rationale: E8 (email) is the true root dependency of Phase 2 — registration verification, password reset, and org invitations all need it (PROJECT_STATUS.md already notes this for #29). E3 unlocks E4/E2/E6-key-management/E7. E1a is deliberately in-house first: Go2My.Link's public API does not exist yet (verified below), and dynamic QR is too central to CueRCode's premium proposition to gate on a sibling repo's timeline.

---

### E1 — Dynamic QR codes: local redirect layer + Go2My.Link integration

- **Goal:** QR codes whose printed image never changes while the destination is editable — the single highest-value premium feature (FG-001: ~65% of market; table-stakes per FEATURES.md).
- **Why it matters for launch:** it is the anchor of the Pro tier (see tier matrix) and the prerequisite for analytics (E11), expiry/scheduling (FG-005), password protection (FG-004), and A/B variants (FG-006).
- **What was verified about Go2My.Link (repo `MWBMPartners/Go2My.Link`):** it is a real, near-launch product (v1.0.0-rc, phases 0–6 complete, last commit 2026-07-04) that has **already pre-built CueRCode hooks**: `tblShortURLs.createdVia` ENUM contains `'cuercode'`, plus `qrCodeExternalID`/`qrCodeExternalUUID CHAR(36) UNIQUE`/`qrCodeLinkedAt` columns (their `web/_sql/migrations/009_cuercode_qr_integration.sql`), `tblActivityLog.scanSource` for QR attribution, and `cuercode.*` settings seeds (`web/_sql/seeds/013_cuercode_settings.sql`: `integration_enabled`, `allow_external_shortcode`, `api_base_url`, `scan_source_param=src`, `scan_source_value=qr`). **BUT the public REST API is design-only** — `docs/API.md` specifies `POST /api/v1/urls/`, `PUT /api/v1/urls/update/{code}`, `GET /api/v1/analytics/{code}` with `X-API-Key` auth, and the gating issues are open in their repo (#38 API framework/auth — priority-critical, #39 endpoints, #75 OpenAPI). The only implemented endpoint (`POST /api/create/`) is session/CSRF-bound and NOT usable server-to-server. Destination editing exists in their dashboard (`UPDATE tblShortURLs SET destinationURL…`), just not via API.
- **Approach (two stages):**
  - **E1a — In-house redirect layer (build now, gate G-007 approval needed):** new table `tblQRRedirects(redirectID, qrID FK, activeDestinationURL, previousDestinationURL, isActive, scheduledStartAt, expiresAt→410, updatedBy, createdAt/updatedAt)`; public route `public_html/r/index.php` (+ `.htaccess` rewrite `r/{uuid}`) doing UUID-v4 validation → lookup → 301/302 with anti-abuse checks; new `dynamic_url` QR type (builder wraps `https://cuercode.net/r/{uuid}`); CRUD API `PATCH /api/v1/qrcodes/{uuid}/destination` (auth required → depends E3 for user-facing edit; API-key edit possible sooner); destination validation (http/https only, no localhost/RFC-1918 targets, optional Safe-Browsing hook later). **Abuse posture (why G-007 is a real decision):** CueRCode becomes a redirect host — phishing/malware laundering surface; mitigations: per-plan dynamic-code quotas, destination-change audit log (tblActivityLog), abuse-report endpoint, interstitial option for free tier.
  - **E1b — Go2My.Link client (blocked upstream):** `_functions/fn_go2mylink.php` curl client implementing their documented contract (`X-API-Key`; `POST /api/v1/urls/` with `destination_url`, optional `custom_code`, and the agreed `qrCodeExternalUUID`+`createdVia=cuercode` params; `PUT /api/v1/urls/update/{code}`); `tblQRCodes.shortLinkID` column **already exists** for this (install.sql:199-257); config keys in tblSettings (`go2mylink_api_base`, `go2mylink_api_key` isSensitive). Behind a driver interface so a QR can be backed by either the local layer or Go2My.Link (`redirectProvider` ENUM('local','go2mylink')).
- **Dependencies:** G-007 owner approval (E1a); Go2My.Link issues #38/#39/#75 shipped + an API key issued (E1b); E3 for end-user destination-edit UI.
- **Blast radius / risk:** Medium-High — new public unauthenticated route (`/r/`), redirect abuse surface, new table. No changes to the existing generate pipeline beyond one new type.
- **Effort:** E1a M · E1b S-M (once upstream exists). **Model:** Sonnet (E1a includes security-sensitive route — include the abuse checklist in the spec; no Opus needed, design is settled here).
- **DRAFT ISSUE (CueRCode) — E1a:**
  > **Title:** [E1] Dynamic QR codes — in-house redirect layer (/r/{uuid}), dynamic_url type, destination CRUD
  > **Body:** Implement editable-destination QR codes with a local redirect layer. **Scope:** (1) migration `_includes/sql/upgrade_002.sql` adding `tblQRRedirects` (columns above) + `redirectProvider` ENUM on tblQRCodes; (2) `public_html/r/index.php` redirect endpoint: UUID-v4 regex, prepared-statement lookup, expiry→410 page, inactive→404, 302 redirect, activity-logged; (3) new `dynamic_url` QR type (seed row + `buildPayload_dynamic_url()` wrapping `https://cuercode.net/r/{uuid}`); (4) `PATCH /api/v1/qrcodes/{uuid}/destination` (API-key auth; ownership check when accounts land) + OpenAPI update; (5) destination validation: http/https only, deny private/loopback ranges, max 2048 chars; (6) audit trail in tblActivityLog; (7) tests for redirect resolution, expiry, validation, and payload builder. **Acceptance criteria:** printed QR unchanged while destination edits take effect ≤5 s; expired code returns 410 with accessible error page; all queries prepared; `php -l` clean; tests green; openapi.json updated first (DEV_NOTES rule §7). **Deps:** owner approval of gate G-007. **Refs:** FG-001, #27 (partially), #33 (enables), install.sql tblQRCodes.shortLinkID/expiresAt.
- **DRAFT ISSUE (CueRCode) — E1b:**
  > **Title:** [E1] Go2My.Link driver — API client for short-link-backed dynamic QR
  > **Body:** When Go2My.Link ships its public API (their #38/#39/#75), add `_functions/fn_go2mylink.php` (curl, no Composer): createShortLink (POST /api/v1/urls/ with `qrCodeExternalUUID`, `createdVia=cuercode`, optional `custom_code` when their `cuercode.allow_external_shortcode` is on), updateDestination (PUT /api/v1/urls/update/{code}), getAnalytics (GET /api/v1/analytics/{code}); map onto `tblQRCodes.shortLinkID`; settings `go2mylink_api_base`/`go2mylink_api_key` (isSensitive=1); graceful degradation to local driver when unreachable (#27 acceptance criterion); retry/backoff + error logging. **Acceptance:** create/edit/analytics round-trip against their staging; fallback verified; timeouts ≤3 s. **Deps:** BLOCKED upstream on Go2My.Link #38/#39; request an `sk`-style API key + staging URL from owner.
- **DRAFT ISSUE (for the Go2My.Link repo — conductor files it THERE):**
  > **Title:** CueRCode integration contract — public API endpoints needed (consumes #38/#39/#75)
  > **Body:** CueRCode needs, per your own `docs/API.md` design + `009_cuercode_qr_integration.sql` hooks: (1) `X-API-Key` auth over tblAPIKeys with `urls:write`/`urls:read`/`analytics:read` scopes; (2) `POST /api/v1/urls/` accepting `qrCodeExternalID`, `qrCodeExternalUUID`, `createdVia=cuercode`, optional caller shortcode; (3) `PUT /api/v1/urls/update/{code}`; (4) redirect-side `?src=qr` logging into `tblActivityLog.scanSource` (currently zero runtime references — schema only); (5) `GET /api/v1/analytics/{code}` incl. scanSource breakdown. Please confirm the request/response shapes so CueRCode can freeze its client (CueRCode E1b).

### E2 — SIGNula.id SSO sign-in

- **Goal:** "Sign in with SIGNula" across the MWBM suite (ProjectBrief §5.3/§15, issue #28), plus the M365/Google OAuth logins (#19/#20).
- **What was verified about SIGNula (repo `MWBMPartners/SIGNula.id`):** substantial, functional codebase at v2.6.0-beta (last main activity 2026-03-08; 42 open issues) with a real, documented REST API (OpenAPI 3.0 at `web/public_html/api/docs/openapi.yaml`, 40+ paths; router `web/public_html/api/v1/index.php`). **BUT it is NOT an OIDC/OAuth2 identity provider.** Its flow is a credential-proxy API: `POST /api/v1/auth/login {email,password}` → opaque bearer tokens (`bin2hex(random_bytes(32))` in their `AuthController.php:91,384,545` — not JWTs despite the spec claiming JWT), MFA step-up, refresh, partner `X-API-Key` keys (`sk_live_`/`sk_test_`, SHA-256, scopes, IP allowlists — their migration `008_partner_api_keys.sql`), HMAC-signed webhooks (`user.login`, `user.registered`, `security.*`). There is **no `/authorize`, no `/token`, no id_token, no discovery doc, no JWKS**. Their `oauth/authorize.php` is SIGNula acting as an OAuth *client* to Google/Microsoft, not an IdP. (Domain quirk: docs use signulo.id/.com.)
- **Consequence (owner decision needed):** true browser SSO via SIGNula requires **upstream work in SIGNula** — either (a) a standard OIDC IdP layer (authorization-code + PKCE `/authorize` + `/token`, `/userinfo`, discovery, JWKS, per-partner redirect-URI allowlisting) — recommended, future-proofs the whole suite; or (b) a lighter proprietary hosted-login redirect (SIGNula login page → one-time code → CueRCode server exchanges code for profile via partner key). Option (c) — CueRCode collecting the password and proxying to SIGNula's login API — works today but is a credential-sharing anti-pattern; acceptable only as an interim, first-party-suite measure if the owner explicitly accepts it.
- **Approach in CueRCode (whichever upstream lands):** `tblUserOAuth(userID, provider ENUM('signula','microsoft','google','apple','facebook'), providerSubjectID, tokensEncrypted, linkedAt)` (part of the E3 migration); generic `_functions/fn_oauth.php` authorization-code client (curl, state+PKCE, nonce); callback route `public_html/auth/callback/index.php`; provider config in tblSettings (isSensitive=1); every SSO identity MUST link to a local account (ProjectBrief §5.3). M365 (#19) and Google (#20) are standard OIDC and can ship before SIGNula.
- **Dependencies:** E3 (local accounts must exist first — hard); upstream SIGNula contract decision; IdP app registrations + secrets from owner (Bucket-3/G-004).
- **Blast radius / risk:** Medium — auth-adjacent code, but additive (new tables/routes). Credential-proxy option would be High reputational risk — flagged.
- **Effort:** M (M365+Google) + M (SIGNula once upstream exists). **Model:** Sonnet implementation; **Opus once** for the SIGNula contract design review (federation design across the suite is genuinely subtle: token lifetimes, logout propagation, account-merge rules).
- **DRAFT ISSUE (CueRCode):**
  > **Title:** [E2] SSO foundation — OIDC client (M365, Google) + SIGNula provider behind a flag
  > **Body:** Build the generic OAuth2/OIDC authorization-code+PKCE client (`fn_oauth.php`, curl-based, no Composer): state/nonce handling, token exchange, id_token validation (JWKS fetch+cache for real OIDC providers), account-linking rules (SSO identity must attach to a local account; multiple providers per user — ProjectBrief §5.3). Providers: Microsoft 365 (#19), Google (#20) now; `signula` provider stubbed behind `signula_sso_enabled` setting until upstream ships an IdP surface. Schema comes from the E3 migration (tblUserOAuth). **Acceptance:** login+link+unlink round-trips for M365/Google on live host; open-redirect-proof callback (exact-match allowlist); tokens AES-256-GCM at rest; CSRF-safe; per-provider enable flags. **Deps:** E3 core auth; owner-supplied IdP registrations. **Refs:** #19, #20, #28, G-004, FG-022.
- **DRAFT ISSUE (for the SIGNula.id repo — conductor files it THERE):**
  > **Title:** IdP capability request — OIDC authorization-code+PKCE surface for suite SSO (CueRCode + future apps)
  > **Body:** CueRCode (and any suite app) needs browser SSO. Today SIGNula exposes only a credential-proxy login API (opaque bearer tokens; no /authorize, /token, discovery, or JWKS — verified against `web/public_html/api/v1/index.php` + `openapi.yaml`). Request: implement an OIDC IdP layer — `/authorize` (code+PKCE), `/token`, `/userinfo`, `/.well-known/openid-configuration`, JWKS + signed id_tokens, per-partner client registration with exact redirect-URI allowlisting (extend existing tblPartners/tblAPIKeys). Alternative minimum: hosted-login redirect + one-time code exchange. Please advise which and the timeline; CueRCode will ship M365/Google SSO first and slot SIGNula in behind a flag.

### E3 — User accounts & organisations foundation

- **Goal:** Registration, login, sessions, profile, saved-codes library, `/save` + `/manage` API (FG-019/G-001; issues #11, #12, #14, #15, #16, #21, #23, #24; backlog B-001/B-002/B-011) — the gateway to every monetisable feature.
- **Verified starting point:** `tblUsers`/`tblOrganisations` schema stubs only (install.sql:346-393); **no session/reset/oauth/webauthn/members/invitation tables exist**; zero auth code (`grep password_hash _functions/` → nothing); session infrastructure (custom `CUERCODE_SID`, HttpOnly, SameSite=Lax) already in bootstrap.php; `tblQRCodes.userID/orgID` FKs reserved; API router has per-route `auth` flags ready to consume a real user context.
- **Technical approach (staged, one migration file `upgrade_001_accounts.sql`):**
  1. **Schema:** extend tblUsers (fix "bcrypt" comment → Argon2id, DS-6); add `tblUserSessions(sessionID CHAR(64), userID, ip, ua, expiresAt, revokedAt)`, `tblPasswordResets`, `tblEmailVerifications`, `tblUserOAuth` (for E2), `tblWebAuthnCredentials`, `tblOrganisationMembers(orgID,userID,roleID)`, `tblInvitations(orgID, email, token, roleID, expiresAt)`. Include per-record salt column for tblSettings here (G-010/F-006) so it's ONE migration event.
  2. **Core auth:** `fn_password.php` (`PASSWORD_ARGON2ID`, memory 65536/time 4/threads 2 — F-013 spec), `fn_auth.php` (register/login/logout/session issue+validate+rotate, lockout counters), rate-limited via existing tblAPIRateLog pattern.
  3. **Email-dependent flows:** verification + reset (BLOCKED on E8).
  4. **API:** `POST /api/v1/auth/register|login|logout`, `POST /api/v1/save` (B-001), `GET /api/v1/qrcodes` (list own), `PATCH /api/v1/qrcodes/{uuid}` (B-002, ownership-checked), `DELETE /api/v1/qrcodes/{uuid}`; **IDOR tests mandatory** (deep-sweep gate).
  5. **UI:** `login.php`, `register.php`, `account/` (profile, avatar per §11.4 priority chain, saved library, sessions list), navbar user indicator.
  6. **Orgs (second stage):** creation, roles (Global Admin/Admin/User §5.2), invitations (tokenised email — needs E8).
  7. **WebAuthn (third stage, G-006):** self-hosted lib evaluation (no Composer) — `web-auth/webauthn-framework` is too heavy; consider minimal FIDO2 attestation-none implementation; separate issue.
  8. **Admin dashboard (G-009/#31, last stage):** error-log viewer, activity viewer, settings UI, user/org CRUD.
- **Dependencies:** E8 (email) for stages 3+; gate G-001 approval; legal (#37 data export/erasure lands here).
- **Blast radius / risk:** High — touches bootstrap/session, API router auth, and adds the app's first real trust boundaries. Mitigate: stage-gated PRs, IDOR/authz test pack per stage, deep-sweep authz re-audit after.
- **Effort:** L (the largest single epic). **Model:** Sonnet throughout; Haiku for CRUD/UI boilerplate scaffolding under Sonnet-written specs.
- **DRAFT ISSUE:**
  > **Title:** [E3][EPIC] Accounts & orgs foundation — Argon2id auth, sessions, saved-codes library, /save + /manage API
  > **Body:** Parent epic for #11 #12 #14 #15 #16 #21 #23 #24, B-001/B-002/B-011, F-013, G-001/G-006/G-009. Staged scope (each stage = own PR + tests): (1) `upgrade_001_accounts.sql` — 7 new tables + tblUsers fixes + tblSettings per-record salt (G-010); (2) fn_password.php + fn_auth.php + session hardening (rotate on privilege change, absolute+idle timeouts); (3) email verification + password reset (needs E8); (4) API: /auth/*, /save, GET/PATCH/DELETE /qrcodes — with ownership checks + IDOR test pack; (5) UI: register/login/account/library + navbar indicator (§11.4); (6) organisations + roles + invitations; (7) WebAuthn/passkeys; (8) admin dashboard (#31). **Acceptance:** all Phase-2 issue criteria; GDPR export+erasure endpoints (#37); zero Critical/High on the post-epic authz audit; 400+ existing tests still green; OpenAPI updated per endpoint. **Deps:** E8 email for stages 3/6; owner gate G-001.

### E4 — Premium tiers + payments

- **Goal:** Monetisation: plan schema, feature gating, PayPal subscriptions (#25/#26; FG-020/FG-021; G-002/G-003).
- **Approach:** exactly the "Premium Tier Design" section below — `tblSubscriptionPlans`/`tblSubscriptions`/`tblPayments` migration, `fn_features.php` gate helper, router `requiredFeature` flags, PayPal Subscriptions REST + webhook listener with signature verification, upgrade UI. ⚠️ Correcting the record: **no payment/subscription tables are stubbed today** — this epic creates them.
- **Dependencies:** E3 (hard — plans attach to users/orgs); owner: PayPal business account + API credentials; legal: refund/cancellation terms into #38 review.
- **Blast radius / risk:** High (money) — but PayPal-hosted approval flow keeps card data entirely off-host (SAQ-A-level posture). Webhook endpoint is a new public route: signature verification + idempotent event processing (store `providerTxnRef` unique).
- **Effort:** L. **Model:** Sonnet, with the payment state machine reviewed by **Opus once** (subscription lifecycle edge cases: past_due→recovery, plan up/downgrade proration, webhook replay).
- **DRAFT ISSUE:**
  > **Title:** [E4][EPIC] Subscription tiers, feature gating & PayPal subscriptions
  > **Body:** Implements #25 + #26 per PROJECT_ROADMAP.md tier matrix. Scope: (1) `upgrade_003_billing.sql` (three tables + seed plan rows incl. `anonymous`); (2) `fn_features.php` (`featureEnabled`/`limitFor`, request-cache); (3) router + handler enforcement (402/403 + upgrade_url); (4) rate-limit tier sync onto tblAPIKeys rows; (5) PayPal Subscriptions integration (create/approve/capture, webhook listener with transmission-signature verification, idempotent event log); (6) plan management UI + upgrade prompts (aria-disabled pattern, no silent hiding); (7) admin plan editor (hangs off E3 stage 8). **Acceptance:** free→pro→business upgrade + cancel + past_due flows E2E on PayPal sandbox; gating enforced at API AND UI; no card data touches the host; webhook replay-safe; docs + OpenAPI updated. **Deps:** E3; owner PayPal credentials; gates G-002/G-003.

### E5 — Accessibility QR systems

- **Goal:** Make CueRCode the accessibility-serious QR generator — honest split between what we can build and what is proprietary.
- **Research verdict (verified 2026-07-09):**
  - **NaviLens:** proprietary colour-matrix markers by Neosistec; commercial use requires a negotiated licence; **no public generation API or self-service generator exists** (site funnels to sales; the ddTags SDK is for *scanning* integration, not generation; personal-use codes are pre-generated by Neosistec and emailed as PDFs). Partnerships (Kellogg's, Coca-Cola, Co-op, major transit systems) are bespoke B2B deals. Note: "EMPWR" appears to be a misreading of the Kellogg's press-release headline ("Kellogg *empowers*…") — no such entity. **Conclusion: CueRCode CANNOT generate NaviLens codes in-house — legally or technically.** Options: (a) owner-level partnership conversation with Neosistec/ddTags (info@ddtags.com), (b) a "NaviLens-aware" help page linking users to NaviLens's own personal-use kit, (c) our own accessible-landing alternative (below). Same applies to Zappar's **Zapvision AQR** (QR + proprietary D3 corner dots; generated only via their CMS; scanned by Seeing AI/Envision/Be My Eyes).
  - **QR + Braille (Chulalongkorn "QR Braille"):** a tactile **braille locator frame around a standard QR** — the frame tells a blind user *where* the code is; the phone does the reading. Universal (one frame fits any QR), embosser-printable. Thai petty patent No. 17871 covers the exact design (no force outside Thailand, but design our own variant anyway). Supporting evidence: embossed-QR study (EJIST 2022) shows tactile locators are the main win for blind users; Digit-Eyes' Avery-label QR sheets are AFB-recommended precedent for printable label output.
  - **Open, freely-implementable standards:** ISO/IEC 18004 quiet zone ≥4 modules; WCAG non-text contrast ≥3:1 (Section508.gov recommends 4.5:1); minimum 2×2 cm print size; **10:1 distance rule** (scan distance ≈ 10× code width); prefer low QR versions (larger modules); always pair with plain-language label + human-readable short URL fallback.
- **Buildable in-house on this stack (all PHP/GD/FPDF/SVG — no third party):**
  1. **"Accessible QR" preset group** in the generator UI: high-contrast enforced (compute WCAG contrast ratio of chosen fg/bg in `fn_qr_options.php`; warn <4.5:1, block <3:1 for the preset), quiet zone forced ≥4 modules, ECC H, version minimised, min-size guidance.
  2. **Physical-size calculator:** input intended scan distance → recommended print size (10:1 rule) shown in UI + embedded in PDF output.
  3. **Print templates (FPDF):** QR + large-print caption + **Unicode Braille caption line** (Grade 1 transliteration table in PHP — deterministic for a short label) positioned for embossing overlay; **tactile locator frame layer** as a separate print pass (swell paper/embosser/UV varnish guidance page); Avery-style label sheets (Digit-Eyes precedent, pairs with existing bulk-generate).
  4. **Screen-reader-optimised destination landing pages + audio QR:** requires E1a redirect layer — a `/r/{uuid}` "accessible landing" mode serving a WCAG-AA page with the payload as structured text, TTS via browser `SpeechSynthesis` (no server audio needed on shared hosting), large-text/high-contrast toggles.
  5. **Guidance content:** placement/height/lighting best-practice page (TetraLogical/Section508-sourced).
- **Needs third party / owner decision:** NaviLens or Zapvision partnership (licensing conversation, not code); server-side audio file generation (would need external TTS API — defer, browser TTS suffices).
- **Dependencies:** none for items 1–3 + 5 (standalone — can ship pre-soft-launch as a differentiator); item 4 depends on E1a.
- **Blast radius / risk:** Low — additive presets/templates.
- **Effort:** M total (items 1–3+5 ≈ M; item 4 S on top of E1a). **Model:** Sonnet (contrast math + FPDF layout); Haiku for the guidance/content pages.
- **DRAFT ISSUE (a):**
  > **Title:** [E5] Accessible-QR presets, WCAG contrast validation & physical-size calculator
  > **Body:** Add an "Accessible QR" preset group: enforced quiet zone ≥4 modules, ECC H, minimised version, WCAG contrast computation on fg/bg (warn <4.5:1, block <3:1 within preset), UI size calculator implementing the 10:1 scan-distance rule, and inline guidance. Files: `fn_qr_options.php` (contrast fn + preset), `public_html/index.php` + generator JS (preset UI, calculator), OpenAPI `preset` param. Tests for contrast math + preset locking. **Acceptance:** preset produces ISO-compliant codes; contrast warnings accessible (role=alert); API parity maintained.
- **DRAFT ISSUE (b):**
  > **Title:** [E5] Braille-caption & tactile-frame print templates (FPDF) + Avery label sheets
  > **Body:** FPDF output modes: (1) QR + large-print + Grade-1 Unicode Braille caption line (PHP transliteration table; document Grade-2 out of scope); (2) tactile locator frame as separate registration-marked layer for embosser/swell-paper/UV-varnish; (3) Avery 5160/L7160 label-sheet layouts fed by existing `/bulk-generate`; (4) an embossing-guidance help page. Design our own frame variant (Chula petty patent 17871 is Thailand-only but avoid cloning). **Acceptance:** PDFs print-verified at 100% scale; braille dot geometry to Marburg Medium spacing; docs page live.
- **DRAFT ISSUE (c):**
  > **Title:** [E5] NaviLens / Zapvision partnership assessment (owner decision — no code)
  > **Body:** Both systems are proprietary with no third-party generation route (verified 2026-07: navilens.com sales-only funnel; ddTags SDK is scan-side; Zapvision codes generated only in Zappar CMS). Decide: (a) approach Neosistec (info@ddtags.com) / Zappar for partnership terms; (b) ship a help page linking NaviLens personal-use kit; (c) rely on our open accessible-QR suite (E5a/E5b) as the differentiator. Recommendation: (b)+(c) now, (a) only if a flagship customer asks. Label: `for consideration`.

### E6 — Partner & user API integration guides + developer portal

- **Goal:** Make the (genuinely complete) API adoptable: docs, examples, keys, onboarding — needed for Go2My.Link/SIGNula partnership traffic and native apps (E7).
- **Verified base:** OpenAPI 3.0.3 complete (7 paths), Swagger UI at `public_html/api/docs/`, XML+JSON negotiation, X-RateLimit headers — the raw material is done; what's missing is narrative docs and self-service keys.
- **Approach:** `public_html/developers/` section (static PHP pages, same header/footer): quickstart (curl→PNG in 60 s), per-endpoint guides generated from openapi.json (single source of truth — DEV_NOTES rule), code samples (curl/PHP/JS/Python), rate-limit + error-model reference, integration recipes ("QR for your CMS", "bulk from spreadsheet", "dynamic QR via API"), partner guide documenting the Go2My.Link/SIGNula contracts as they land. Self-service API-key management UI (create/rotate/revoke against existing tblAPIKeys) — needs E3 login.
- **Dependencies:** docs — none (start now); key self-service — E3.
- **Blast radius:** Low. **Effort:** M. **Model:** **Haiku** for docs/samples generation (mechanical from openapi.json), Sonnet for the key-management UI.
- **DRAFT ISSUE:**
  > **Title:** [E6] Developer portal — /developers docs, samples, and self-service API keys
  > **Body:** Scope: (1) `public_html/developers/` — quickstart, auth guide (API key + CSRF modes), endpoint reference rendered from openapi.json, error/rate-limit reference, samples in curl/PHP/JS/Python, changelog page; (2) integration recipes incl. dynamic-QR-via-API once E1a ships; (3) API-key self-service in account area (create/rotate/revoke, prefix display, last-used — tblAPIKeys already has the columns) [needs E3]; (4) publish the partner contracts (Go2My.Link, SIGNula) as they freeze. **Acceptance:** a new developer reaches first generated QR ≤5 min using only the portal; docs regenerate from openapi.json (no drift); key CRUD audit-logged.

### E7 — API robustness for external integration + native management apps

- **Goal:** Harden the API from "web-UI backend with parity" to "platform API" ready for the companion mobile app (ProjectBrief §15) and third parties.
- **Approach:** (1) API-key lifecycle endpoints (`POST/DELETE /api/v1/keys`, rotate) + scopes honouring `tblAPIKeys.permissions` JSON (column exists, unenforced today); (2) pagination/filtering standard for list endpoints (`GET /qrcodes?page=&per_page=&type=`); (3) idempotency keys on `POST /generate` + `/bulk-generate` (header `Idempotency-Key`, response replay table with TTL); (4) consistent error-code catalogue (machine-readable `error_code` alongside message) — additive, documented in openapi; (5) webhooks (FG-013, after E11 events exist): `tblWebhooks` + HMAC-SHA256-signed POSTs + retry/backoff; (6) versioning policy doc (v1 stability contract, deprecation headers); (7) CORS per-key allowlist option; (8) mobile-app-oriented endpoints: token-based auth session for apps (long-lived refresh via tblUserSessions), device registration.
- **Dependencies:** E3 (keys attach to users; app sessions); E11 for webhook events.
- **Blast radius:** Medium — touches router + every handler for pagination/errors, but additive.
- **Effort:** M-L. **Model:** Sonnet; Haiku for the error-catalogue/documentation sweep.
- **DRAFT ISSUE:**
  > **Title:** [E7][EPIC] Platform-grade API — key lifecycle+scopes, pagination, idempotency, error catalogue, webhooks
  > **Body:** Scope items (1)–(8) above, each its own PR: scopes enforcement reads tblAPIKeys.permissions; idempotency table `tblIdempotencyKeys(key, requestHash, responseBody, expiresAt)`; error catalogue additive (`error_code` field, no breaking change); webhooks (FG-013) with HMAC-SHA256 signature + 3-retry backoff + delivery log; OpenAPI updated per item. **Acceptance:** external integrator can manage keys, page results, retry safely, and consume webhooks without reading source; zero breaking changes to existing v1 consumers (parity tests stay green). **Deps:** E3; E11 for scan events.

### E8 — Microsoft Graph email (root Phase-2 dependency)

- **Goal:** Transactional email via M365 shared mailbox with Send-As (ProjectBrief §13 — explicitly NOT `mail()`); blocks E3 stages 3/6 and E4 receipts (#29; FG-028/G-005).
- **Approach:** `_functions/fn_email.php`: OAuth2 client-credentials grant against Microsoft identity platform (tenant + client ID + secret in tblSettings isSensitive), token cache (tblSettings, expiry-aware), `POST /v1.0/users/{mailbox}/sendMail` via curl; responsive HTML templates in `_includes/email_templates/` (verification, reset, invitation, receipt); send log in tblActivityLog; **queue+retry:** `tblEmailQueue(emailID, toAddr, template, paramsJSON, attempts, nextAttemptAt, status)` — ⚠️ Dreamhost constraint: brief says no CLI, so process the queue opportunistically on request-shutdown (`register_shutdown_function`, ≤2 sends/request) AND document the Dreamhost panel cron option for the owner to enable (owner decision — panel cron exists on shared hosting even without SSH). *(Update 2026-09-25, #112: the queue is emptied by a command-line runner from a DreamHost panel cron job every minute; the request-shutdown hook is an off-by-default fallback, now actually wired in.)*
- **Dependencies:** owner: M365 tenant app registration (Mail.Send application permission, shared mailbox, DKIM/DMARC on sending domain). Nothing in-repo.
- **Blast radius:** Low-Medium (new subsystem, no existing-code changes). **Effort:** M. **Model:** Sonnet.
- **DRAFT ISSUE:**
  > **Title:** [E8] Microsoft Graph email subsystem — client-credentials sendMail, templates, queue+retry
  > **Body:** Implements #29 (Google Workspace #30 stays future-ready via a driver interface). Scope: fn_email.php Graph client (client-credentials token + cache, sendMail via shared mailbox), 4 responsive templates, tblEmailQueue with backoff retry (shutdown-hook processor + documented panel-cron alternative), activity logging, kill-switch setting `email_enabled`. **Acceptance:** DKIM/DMARC-passing mail received from live host to Gmail+Outlook test inboxes; failed sends retried; creds encrypted (isSensitive=1); zero PHP mail() usage. **Deps:** owner M365 app registration + shared mailbox (gate G-005). NOTE for owner: set the three Graph values via the GitHub-secrets-equivalent flow in tblSettings admin, never committed.

### E9 — Internationalisation (i18n)

- **Goal:** Multi-language UI (#34; FG-018/G-012 — queued spec already exists: `t()` + JSON locale files + selector + EN baseline).
- **Approach:** `_functions/fn_i18n.php` (`t(string $key, array $vars=[]): string`), `_includes/locales/en.json` baseline, negotiation order: user pref (account) → session/localStorage → `Accept-Language` → en; navbar selector (mirrors the theme-toggle pattern); extract strings from index/header/footer/legal/error pages; RTL readiness in CSS (logical properties audit); translation-contribution README. Defer the #34 "admin translation UI" to post-launch.
- **Dependencies:** none technically; sequence after major UI-changing epics (E3/E4 add many strings) to avoid double extraction — hence late slot.
- **Blast radius:** Wide-but-shallow (every template touched) — the exact reason G-012 was queued for approval. **Effort:** M. **Model:** **Haiku** for the mechanical string extraction (perfect fit), Sonnet for the framework + negotiation.
- **DRAFT ISSUE:**
  > **Title:** [E9] i18n scaffold — t() helper, JSON locales, language selector, EN baseline
  > **Body:** Per G-012 spec: fn_i18n.php with request-cached locale load + `t()` (htmlspecialchars-safe by default), en.json extracted from all public templates, navbar selector with localStorage/session persistence + Accept-Language default, RTL-readiness pass (CSS logical properties), contributor guide. Admin translation editor deferred (#34 note). **Acceptance:** zero hardcoded user-facing strings in templates (grep-gate), en renders byte-identical to today, adding a locale file requires no code change, selector keyboard-accessible. **Deps:** run AFTER E3/E4 UI lands. Model note: string extraction = Haiku; framework = Sonnet.

### E10 — Live-server launch verification & ops (THE soft-launch gate)

- **Goal:** Execute the owner-gated path: merge → deploy → verify (#13/#17/#18/#22) → soft launch.
- **Approach / runbook:**
  1. Owner reviews + merges **PR #46** (mergeStateStatus BLOCKED = needs review; content verified PASS at `f5cf408`).
  2. Owner sets deploy secrets via **GitHub web UI** (Repository → Settings → Secrets and variables → Actions): `SFTP_HOST`, `SFTP_PORT`, `SFTP_USER`, `SFTP_KEY` (paste entire PEM incl. BEGIN/END lines) or `SFTP_PASSWORD`, `SFTP_REMOTE_BASE`; Variables: `SFTP_ENABLED=true`. (deploy.yml consumes exactly these — DEV_NOTES.md §CI/CD.)
  3. Merge push to main fires `deploy.yml` (branch-push-triggered; merging via web UI counts as a push — no GITHUB_TOKEN anti-recursion issue on this path).
  4. Run `install/` on live DB (#13) → delete or verify deny-all on `install/` after (F-020 mitigations already in code).
  5. #17: matrix test 26 types × 6 formats (script the API loop; eyeball SVG/PDF); #18: API auth/rate-limit burst/download round-trip + X-RateLimit headers; #22: responsive+a11y device pass incl. og-image (B-037), colourblind toggle (B-038), VoiceOver/TalkBack smoke (closes the #9 gap).
  6. Live hardening: capture SRI hashes from live CDN responses → close F-004; confirm HSTS + HTTPS redirect; set `api_cors_allowed_origins` (seed is now secure-default empty — B-053); create `.htpasswd` + uncomment `Require valid-user` for dev_alpha/dev_beta (DS-1); configure Turnstile keys when decided (F-012).
  7. Swap `public_html_landing` → `public_html` serving on the primary domain (Dreamhost panel), point CueRCode.me/QRmy.Link redirects.
- **Dependencies:** owner only. **Blast radius:** production. **Effort:** M (mostly owner-driven; agent prepares scripts/checklists). **Model:** Haiku (runbook + test scripts generation); the verification itself is owner+assistant.
- **DRAFT ISSUE:**
  > **Title:** [E10] Soft-launch runbook — merge PR #46, first deploy, live verification suite (#13/#17/#18/#22), live hardening
  > **Body:** Tracking issue for the ordered runbook above (7 steps). Deliverables the agent prepares: type×format API test script (bash/curl, asserts content-type+magic bytes), rate-limit burst script, a11y checklist (WCAG 2.1 AA + SR smoke), SRI-hash capture script, post-install lockdown checklist. Owner executes deploy + panel steps. **Acceptance:** #13/#17/#18/#22 all closed with evidence; F-004 closed; DS-1 closed; landing page replaced; uptime monitor configured. **Closes the soft-launch milestone.**

### E11 — Scan analytics & dashboard (identified addition)

- **Goal:** #33/FG-002/G-008 — scan tracking + dashboards; the analytics half of the Pro tier.
- **Approach:** only meaningful after E1a (scan events exist only at the redirect hop): `tblScanAnalytics(scanID, qrID, scannedAt, ipHash SHA-256+pepper — no raw IP for GDPR, country/region via local MaxMind GeoLite2 mmdb in `_libraries/` (no external calls), deviceClass/os/browser from UA parse, referrer)`; recorded in `public_html/r/index.php`; dashboard page (account area) with totals/unique/timeline/geo/device; `GET /api/v1/qrcodes/{uuid}/analytics` + CSV export; retention per plan tier (event pruning job on the E8 queue-processor hook). Go2My.Link analytics merge later via E1b (`GET /analytics/{code}` + their `scanSource=qr` attribution once they implement runtime logging — currently schema-only on their side, verified).
- **Dependencies:** E1a (hard), E3 (dashboard auth), E4 (retention tiers). **Blast radius:** Medium (write on every redirect — keep it one prepared INSERT, fail-open). **Effort:** M. **Model:** Sonnet; dashboard charts Haiku from a spec.
- **DRAFT ISSUE:**
  > **Title:** [E11] Scan analytics — privacy-first event capture on /r/ + dashboard + API + export
  > **Body:** Implements #33 per approach above. Privacy: hash IPs (SHA-256 + server pepper), GeoLite2 local lookup only, no third-party beacons, retention per plan, document in privacy.php. **Acceptance:** scan → dashboard visible ≤1 min; redirect latency overhead <20 ms; CSV/JSON export; API documented; GDPR-reviewed. **Deps:** E1a, E3; retention gating E4.

### E12 — CI/CD & release hardening (identified addition)

- **Goal:** Make the pipeline trustworthy for a launched product (#35/#36/#44 are stale-open; workflows exist but unproven live).
- **Approach:** (1) add a PR quality workflow: `php -l` matrix (8.4+8.5), `php tests/run.php`, deprecation sweep, gitleaks CLI (NOT the org-licensed action — known licence trap); (2) fix DS-3 (deploy.yml marker-based injection, then normalise infoAppVer NULLs); (3) branch protection: require the new check AFTER verifying exact job-name context match (orphan-context trap); (4) verify version-bump/release on first real merges, then close #35 + one of #36/#44; (5) beta channel: create `beta` branch → auto-deploys `public_html_dev_beta` (deploy.yml ready) once DS-1 access control is enforced.
- **Dependencies:** none. **Effort:** S-M. **Model:** Haiku (workflow YAML is mechanical; the traps are documented here).
- **DRAFT ISSUE:**
  > **Title:** [E12] CI hardening — PR checks (lint/tests/deprecation/gitleaks-CLI), deploy.yml injection fix, protection contexts
  > **Body:** Scope per approach (1)–(5). Use gitleaks CLI tarball pinned version (the gitleaks-action requires an org licence and silently no-ops). Verify required-status context strings byte-match job `name:` fields. **Acceptance:** red PR on lint/test/secret failure; deploy.yml no longer sed-coupled to `' = NULL'`; #35/#36/#44 reconciled/closed with evidence.

---

## Premium Tier Design

### Honest baseline

⚠️ **There is no billing schema today.** `tblSubscriptionPlans`, `tblSubscriptions`, `tblPayments` do NOT exist (verified: zero matches in `_includes/sql/`). What exists and can be leveraged:

- `tblOrganisations.subscriptionTier VARCHAR(50) DEFAULT 'free'` (install.sql:384) — a placeholder column.
- `tblAPIKeys.permissions` (JSON), `rateLimitPerMin`, `rateLimitPerDay` (install.sql:285-318) — per-key gating/rate hooks already in schema and enforced by `fn_api_auth.php` rate limiting (restored in cycle 4, F-014).
- `tblQRCodes.userID / orgID / apiKeyID / isPublic / expiresAt / shortLinkID` (install.sql:199-257) — ownership + dynamic-QR + expiry columns reserved.
- `tblSettings` feature flags pattern + `getConfig()` cache (fn_config.php).

### Proposed tiers (Free / Pro / Business)

| Feature | Anonymous | Free (account) | Pro (individual, paid) | Business (org, paid) |
| --- | --- | --- | --- | --- |
| Static QR — all 26 types, all customisation | ✅ | ✅ | ✅ | ✅ |
| Output formats PNG/SVG/JPG/GIF/WEBP/PDF | ✅ | ✅ | ✅ | ✅ |
| Accessible-QR presets + print templates (E5) | ✅ basic | ✅ | ✅ full (Braille/tactile PDF layers) | ✅ full |
| Saved-codes library (E3) | — | 25 codes | 500 codes | Unlimited (fair use) |
| Bulk generate (`/bulk-generate`, exists today) | 25/batch | 50/batch | 500/batch | 2,000/batch + priority |
| Dynamic QR (editable destination, E1) | — | 3 codes | 100 codes | 1,000 codes |
| Scan analytics (E11) | — | totals only | 90-day detail | 3-year detail + CSV/JSON export |
| QR expiry & scheduling (FG-005) | — | — | ✅ | ✅ |
| Password-protected QR (FG-004) | — | — | ✅ | ✅ |
| Templates/presets (FG-008) | seeded only | personal ×5 | personal ×50 | org-shared + template locking |
| API keys + rate limits | IP-limited | 1 key, 30/min | 5 keys, 120/min | 20 keys, 600/min + burst |
| Webhooks (FG-013) | — | — | 2 endpoints | 20 endpoints |
| Organisations, roles, invitations (E3 org stage) | — | — | — | ✅ (Global Admin/Admin/User) |
| SSO (M365/Google/Apple/SIGNula, E2) | — | — | — | ✅ |
| White-label / custom domain (FG-014, later) | — | — | — | roadmap |
| GS1 product QR at volume + deep-link QR | ✅ single | ✅ | ✅ bulk | ✅ bulk + API |
| Support | community | community | email | priority email |

Anonymous stays generous (single static codes, full customisation) — that is the acquisition funnel and matches the current live behaviour; accounts add persistence; money buys *dynamism, analytics, volume, and org features*.

### Enforcement design (technical)

1. **Schema (part of E4):** `tblSubscriptionPlans(planID, planKey, planName, priceMonthly, priceYearly, currency, featuresJSON, limitsJSON, isActive)`; `tblSubscriptions(subscriptionID, userID|orgID, planID, status ENUM(active,trialing,past_due,cancelled,expired), currentPeriodStart/End, provider, providerSubscriptionRef, createdAt/updatedAt)`; `tblPayments(paymentID, subscriptionID, provider, providerTxnRef, amount, currency, status, rawWebhookJSON, createdAt)`. Seed 4 plan rows (anonymous/free/pro/business) with `featuresJSON`/`limitsJSON` as the single source of truth.
2. **Gate helper (`_functions/fn_features.php`):** `planFor($userID|$orgID|null): array` (cached per request like fn_config), `featureEnabled(string $featureKey, ?int $userID): bool`, `limitFor(string $limitKey, ?int $userID): int`. Anonymous → the `anonymous` plan row. Grace behaviour: `past_due` keeps read access, blocks creation of new gated objects.
3. **Enforcement points:** (a) API router (`api/v1/index.php`) — add per-route `requiredFeature` to the route table (same pattern as the existing `auth`/`rateLimit` route flags at index.php:176-233); (b) `fn_api_generate.php`/`fn_api_bulk.php` — limit checks (batch size, dynamic-code count) with 402/403 + `upgrade_url` in the error body; (c) UI — gated controls render with an upgrade tooltip (never hidden silently, WCAG-friendly `aria-disabled`).
4. **Rate-limit tiering:** on plan change, update the user's `tblAPIKeys.rateLimitPerMin/PerDay` rows (columns already enforced by `checkRateLimit()`), so no new rate-limit code path is needed.
5. **Payments (E4):** PayPal Subscriptions API (server-side REST + webhook listener `public_html/api/v1/webhooks/paypal/index.php` verifying transmission signature) writes tblPayments + flips tblSubscriptions.status. Apple/Google Pay deferred to native apps (E7 consumers). All provider credentials in `tblSettings` `isSensitive=1` (AES-256-GCM, fn_encryption.php).

---

## Security / Syntax / Lint Deep-Review Plan

Owner directive: fix **ALL** findings regardless of severity/age. Current register: SECURITY.md — 20 findings, 16 fixed, 4 open/queued (F-004, F-006, F-012, F-013). This plan re-sweeps everything, including the env copies the autopilot explicitly did NOT touch.

### Scan matrix (run in this order)

1. **Syntax:** `find web tests -name '*.php' -exec php -l {} \;` — must stay 122/122 clean (115 under `web/CueRCode.net/` + 7 test files). Run with BOTH PHP 8.4 and 8.5 CLIs if available (target is 8.5 w/ 8.4 back-compat, ProjectBrief §7.1).
2. **Deprecation/runtime sweep:** load every `fn_*.php` under `php -d error_reporting=E_ALL -d display_errors=1` (the VERIFICATION.md method) — zero E_DEPRECATED/E_STRICT bar (DEV_NOTES.md "Zero-Deprecation DoD Bar").
3. **House-standards sweep** (grep-driven): tabs (`grep -rPn '\t' --include='*.php'`), `OR|AND` operators, uppercase `NULL|TRUE|FALSE`, closing `?>` in include files, missing file headers, shorthand control structures. **Known offenders already spotted — fix these:**
   - `public_html_dev_alpha/infoAppVer.php` + `public_html_dev_beta/infoAppVer.php` — never received the cycle-10 standards pass (tabs, `OR/AND`, uppercase NULL, no doc header) — admitted in DEV_NOTES.md §"dev_alpha / dev_beta infoAppVer.php Copies".
   - `public_html/infoAppVer.php:68-71` — four `' = NULL'` literals are CI-coupled to `deploy.yml`'s sed injection (DEV_NOTES.md §"deploy.yml CI-Coupling"). Fix properly: change deploy.yml to a marker-comment-based injection, then normalise the four lines. Do these two edits in ONE commit.
4. **Secrets scan:** gitleaks **CLI** (not the org-licensed action) over full history: `gitleaks detect --source . --redact`. Today's status: only `.htaccess` files are git-tracked under `_auth_keys/` (verified `git ls-files`), `auth_creds.example.php` is untracked, and a targeted secret-pattern grep over `_includes/_functions/public_html` returned nothing — good baseline, keep it enforced in CI.
5. **OWASP-style checklist re-run** (each with the concrete probe):
   - **SQL injection:** `grep -rn '\$.*->query(' web/CueRCode.net --include='*.php'` + audit every `prepare()` for interpolated fragments. Baseline: prepared statements throughout (SECURITY.md "Strong baseline").
   - **XSS (PHP):** audit every `echo`/`printf` of non-literal for `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`. **XSS (JS):** re-grep `innerHTML|insertAdjacentHTML|jQuery(` sinks in `cuercode-common.js`/`cuercode-generator.js` (F-018 fixed the known one; re-verify no new sinks).
   - **CSRF:** every state-changing POST verifies session token (`fn_csrf.php`); installer's standalone token documented (F-010).
   - **AuthZ/IDOR:** currently no auth surface; becomes a MANDATORY re-audit gate when E3 lands (`/save`, `PATCH /qrcodes/{uuid}`, downloads of owned codes).
   - **Uploads:** ⚠️ `validateImageUpload()` (fn_validation.php:492-665) **has no call sites** (SECURITY.md coverage ledger) — dead security code. Wire it into the logo-upload path when built, or annotate why it's dormant.
   - **Crypto:** F-006 global `ENCRYPTION_SALT` → per-record salt (schema change, gate G-010, owner decision) — fold into the E3/E4 migration file so it ships with the next schema change. ⚠️ Also `tblUsers.passwordHash` column comment says "bcrypt" (install.sql:349) but the brief mandates **Argon2id** (ProjectBrief §7.1) — fix the comment in the E3 migration; implement `fn_password.php` with `PASSWORD_ARGON2ID` (F-013/B-011).
   - **Headers:** F-004 — add SRI `integrity=` + `crossorigin` to Bootstrap/FA/jQuery tags in `_includes/header.php`/`footer.php` (copy the pattern already present in `public_html_landing/index.php:46-48,196-197`), replace CSP `unsafe-inline` with nonces, verify HSTS/redirect on live host.
   - **Dangerous functions:** `grep -rnE '\b(eval|exec|shell_exec|system|passthru|popen|proc_open)\s*\(' web/ --include='*.php'` — expect zero outside vendored libs; flag any hit.
   - **Rate limiting:** burst-test on live server (part of #18) — code-level fix (F-014) verified, live behaviour never observed.
   - **Error-leak:** confirm `?debug=true` output is gated appropriately for production (`fn_debug.php`); verify `display_errors Off` on live host; F-008 masking is in code — spot-check live logs.
   - **Bot protection:** F-012 — implement Turnstile-first hooks (B-034) once owner supplies keys (HB-6).
6. **Env copies + auxiliary roots** (explicitly untouched by autopilot):
   - `public_html_dev_alpha/`, `public_html_dev_beta/` (2 files each): infoAppVer standards (above) **and** ⚠️ `.htaccess` basic-auth is scaffolding only — `AuthUserFile` and `Require valid-user` are commented out → **no access control** as committed. Enforcement requires the owner to create `.htpasswd` on the server and uncomment; add a launch-checklist item.
   - `public_html_landing/index.php` (1 file): reviewed — output correctly escaped, SRI present, `noindex,nofollow` set. Clean.
   - `public_html_redir/.htaccess` (1 file): review redirect target allowlisting when domains are wired.
   - `web/_auth_keys/`, `web/CueRCode.net/_auth_keys/`: only deny-all `.htaccess` tracked in git — correct.
7. **CI wiring:** add a workflow running items 1, 2, 4 + `php tests/run.php` on every PR (tests are dependency-free by design, DEV_NOTES.md §Testing); add a required status check AFTER verifying job-name/required-context match (branch-protection orphan trap).

### Already-spotted suspicious items (fix-pass input list)

| ID | Where | What |
| --- | --- | --- |
| DS-1 | `public_html_dev_alpha/.htaccess`, `public_html_dev_beta/.htaccess` | `Require valid-user` commented out — dev envs unprotected as shipped |
| DS-2 | `public_html_dev_alpha/infoAppVer.php`, `public_html_dev_beta/infoAppVer.php` | Pre-standards copies (tabs/NULL/OR-AND/no header) |
| DS-3 | `.github/workflows/deploy.yml` + `public_html/infoAppVer.php:68-71` | Fragile `' = NULL'` sed coupling — replace with marker-based injection |
| DS-4 | `_includes/header.php`, `_includes/footer.php` | 0 `integrity=` attributes on CDN tags (F-004); landing page proves the fix pattern |
| DS-5 | `_functions/fn_validation.php:492-665` | `validateImageUpload()` has no call sites — wire or annotate |
| DS-6 | `_includes/sql/install.sql:349` | tblUsers.passwordHash comment says "bcrypt"; brief mandates Argon2id |
| DS-7 | `_functions/fn_encryption.php` | Global ENCRYPTION_SALT (F-006/G-010) — fold per-record salt into next schema migration |
| DS-8 | `public_html/index.php`, `assets/js/cuercode-generator.js` | No bot-protection hooks (F-012/B-034) |
| DS-9 | `.openai/` at repo root | Empty stray directory — delete or explain |
| DS-10 | Repo hygiene | `.claude/ProjectcBrief_Chat.claude` (typo'd filename, Feb copy) alongside `ProjectBrief_Chat.claude` — dedupe |

**Model/effort for the whole sweep:** Sonnet, M (2 cycles: one repo-wide scan+fix commit series, one live-host pass after deploy). Haiku can run the purely mechanical items (DS-2, DS-9, DS-10, standards greps).

---

## Recommended Next Actions

Ordered first steps for the conductor (owner-gated items marked 👤):

| # | Action | Who/Model | Effort | Notes |
| --- | --- | --- | --- | --- |
| 1 | 👤 **Review + merge PR #46** into `main` | Owner | S | Everything downstream deploys from main; content verified PASS @ f5cf408 (re-confirmed 400/400 today) |
| 2 | **Create the drafted GitHub issues + 2 new milestones** ("Soft Launch", "Full Launch"); apply the reconciliation table (close #1/#3/#4/#5/#7, dedupe #36/#44, relabel #38/#40, close-with-evidence comments) | Conductor / Haiku | S | Drafts are in this file verbatim; also file the 2 upstream issues (Go2My.Link contract, SIGNula IdP request) in the sibling repos + `for consideration` issues for G-011 items |
| 3 | 👤 **Configure deploy secrets (GitHub web UI) + first deploy + run E10 verification** (#13/#17/#18/#22) with agent-prepared scripts | Owner + Haiku (scripts/runbook) | M | The soft-launch gate; includes live hardening (SRI/F-004, HSTS, CORS allowlist, dev-env htpasswd/DS-1) |
| 4 | **Run the Security/Syntax/Lint deep sweep** (DS-1..DS-10 + full scan matrix incl. dev_alpha/dev_beta copies) | Sonnet (Haiku for DS-2/9/10) | M | Fix ALL findings regardless of severity per owner directive; second pass post-deploy for live-only items |
| 5 | 👤 **Owner decisions batch:** approve G-007 (dynamic QR redirect layer), G-001 (accounts), G-005 (email; supply M365 app registration), bot-protection choice + keys (F-012/HB-6), legal counsel engagement (#38) | Owner | S | Unblocks E1a/E3/E8 in parallel |
| 6 | **Build E8 (Graph email)** — root dependency of Phase 2 | Sonnet | M | Can start the moment G-005 creds exist; nothing else blocks it |
| 7 | **Build E1a (local dynamic-redirect layer)** + E5a/E5b accessible presets & print templates in parallel | Sonnet (+Haiku on E5 content) | M | E1a is the premium anchor; E5a/b are standalone differentiators shippable before accounts |
| 8 | **Start E3 (accounts) stage 1–2** (schema migration incl. G-010 salt + fn_password/fn_auth) | Sonnet | L (staged) | Stages 3+ resume when E8 lands; IDOR/authz test pack mandatory per stage |

**Standing rules for the whole programme:** per-item GitHub issue → implement → `php -l` + tests + security check → issue update/close with commit refs → CHANGELOG/DEV_NOTES/README/PROJECT_STATUS + `.claude/` memory → commit, **never push** (owner pushes/merges). OpenAPI first for any API change. No secrets in the repo, ever.

