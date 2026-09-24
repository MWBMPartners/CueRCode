# 📊 CueRCode - Project Status

**📅 Last Updated:** 2026-09-23 (issue audit + documentation refresh, #103)
**🔄 Current Phase:** Phase 1.5 - Live Server Testing & Verification (Phase 2 stage 1 code-complete, pending live-server verification)
**📈 Overall Status:** In Development
**🌿 Branch cadence:** `work-in-progress → alpha → beta → release-candidate → main` (four-tier; scanning + Dependabot + security-fix propagation cover all four branches, #64)

---

## 🧭 Current position at a glance (2026-09-23)

**What's built (in code, tests passing):** the whole QR engine (26 QR types + 11 barcode types, 6 output formats), the responsive/accessible web UI (dark/light mode, colourblind palettes, skip links), the full REST API (30 OpenAPI paths, 93 catalogued error codes), accounts (registration, login, password reset, sessions, saved codes, self-service API keys, data export/erasure), a subscription/plan gating engine, Microsoft 365 + Google SSO, the developer portal, and CI scanning (CodeQL/Semgrep/actionlint/Dependabot) on all four tier branches. All 2185 automated tests pass and all 77 tracked web PHP files pass `php -l`.

**Built but switched off** (code exists, kill-switches are off until the owner acts):
- SSO (Microsoft 365 #19, Google #20) — needs the owner to register OAuth apps and enter credentials.
- Email sending (Microsoft Graph, #29) — needs owner M365 setup, **and** nothing empties the send queue yet (no scheduled job, #112), so verification emails can't reach anyone on a live site.
- Flexible pricing (price points, discounts, lifetime plans, PAYG credits, #70) — tables and helpers built, three kill-switches seeded off.
- Dynamic-redirect QR codes (Stage S0/S1, #27/#71/#33) — database and per-code destination API exist, but the public `/r/{uuid}` route does not, so no dynamic code actually redirects anyone yet.
- Barcode generation (#69) — live via the API, but gated behind `barcode_generation` (currently on for every plan by default) and has no web UI tab yet.

**Blocked on the owner:** SSO app registration (#19/#20/#55), Microsoft 365 email setup (#29), PayPal credentials (#26), the redirect-hosting decision G-007 (#27/#71), WebAuthn go-ahead G-006 (#16), i18n go-ahead (#34), the JPEG XL hosting decision (#40), the contrast-warning-vs-block decision (#48), Dependabot/secret-scanning/Advanced Security toggles and the vendored-library watch method (#64), and the SFTP deploy secrets (#44).

---

## 🕳️ Known gaps found in the 2026-09-23 audit

- Nothing sends the email queue yet. There is no scheduled job and the auto-process hook is not wired, so email sign-ups can't be verified on a live site (#112; also #29/#52).
- There is no admin screen to enter encrypted settings (#31/#115).
- Predefined logos have no PNG copies (#65).
- `install.sql` can't safely run twice, and there is no upgrade script (#113; also #70/#11).
- Email/password sign-ups, logins and password resets are not recorded as their own events in the activity log — only a generic "API call" row with no user or outcome when they come through the API (#114; also #12/#14/#15).
- No bot protection (#114; also #47 DS-8/#12).
- Deploy doesn't upload the third-party libraries or the CDN fallback copies, and no credentials template is in the repo (#113; also #1/#7/#36/#44).
- The version number is never shown (#115; also #35).
- Footer copyright shows one year, not 2025-current (#115; also #38).
- The XML stylesheet file is missing (#115; also #3).
- A format of `'jxl'` quietly returns PNG (#115; also #40).
- The installer doesn't check for the zip/bcmath/curl add-ons (#113; also #13).
- `beta` and `main` have no `ci.yml` (#115 covers `beta`).

---

## 📋 Phase Summary

| Phase | Name | Milestone | Issues | Status |
| --- | --- | --- | --- | --- |
| 1 | Core QR Engine | [#1](https://github.com/MWBMPartners/CueRCode/milestone/1) | #1-#9, #39-#43 | 🔄 Audit gaps open |
| 1.5 | Live Server Testing | [#2](https://github.com/MWBMPartners/CueRCode/milestone/2) | #13, #17, #18, #22 | ⬜ Pending |
| 2 | User Accounts & Organisations | [#3](https://github.com/MWBMPartners/CueRCode/milestone/3) | #11, #12, #14-#16, #19-#21, #23-#24 | 🔄 Stage 1 (2026-07-10) — schema/Argon2id/auth engine/auth API for #11/#12/#14/#15; Stage 2a (2026-07-10) — saved-codes API (`/save`, `/qrcodes` CRUD) with ownership/IDOR enforcement for #24 (B-001/B-002); Stage 2b (2026-07-10) — account UI (register/login/verify/reset pages, account dashboard, navbar indicator) + 3 own-scoped endpoints for #12/#14/#23/#24; Epic E2 (2026-07-10) — SSO/OIDC client (Microsoft 365 + Google) for #19/#20, conservative account linking, SIGNula behind a flag pending upstream (#28); all needs live-server verification |
| 3 | Premium Features & Payments | [#4](https://github.com/MWBMPartners/CueRCode/milestone/4) | #25, #26 | 📋 Planned |
| 4 | Integrations | [#5](https://github.com/MWBMPartners/CueRCode/milestone/5) | #27-#30 | 📋 Planned |
| 5 | Advanced Features & Polish | [#6](https://github.com/MWBMPartners/CueRCode/milestone/6) | #31-#34 | 📋 Planned |
| 6 | CI/CD, Legal & Compliance | [#7](https://github.com/MWBMPartners/CueRCode/milestone/7) | #35-#38 | 📋 Planned |

---

## 🔄 Phase 1: Core QR Engine (AUDITED - gaps identified)

**Milestone:** [Phase 1: Core QR Engine](https://github.com/MWBMPartners/CueRCode/milestone/1)

Bulk development completed 2026-02-27 in commit `73fc61e`. Thorough audit against revised ProjectBrief conducted 2026-03-23 — gaps identified and tracked below.

| Stage | Description | Issues | Status |
| --- | --- | --- | --- |
| 1 | Project Foundation & Directory Structure | [#1](https://github.com/MWBMPartners/CueRCode/issues/1) | ⬜ Needs live server verification |
| 2 | Database Schema & SQL Installation | [#2](https://github.com/MWBMPartners/CueRCode/issues/2) | ✅ tblUsers fully implemented + 7 new accounts/sessions/orgs tables added (Epic E3 stage 1, 2026-07-10, 29 tables total as of the 2026-09-23 audit); 3 missing QR seed rows added (cycle 6); deeplink + gs1_product seed rows added (cycle 8) |
| 3 | Core PHP Framework | [#3](https://github.com/MWBMPartners/CueRCode/issues/3) | ⬜ Needs live server verification |
| 4 | QR Code Generation Engine | [#4](https://github.com/MWBMPartners/CueRCode/issues/4) | ⚠️ 26/26 QR types now in code (cycle 8); JPEG XL missing (host-dependent); needs live-server verification |
| 5 | Responsive Web UI | [#5](https://github.com/MWBMPartners/CueRCode/issues/5) | ⚠️ Colourblind mode + cookie-consent banner added in code (cycle 7); no i18n; needs live-server verification (#22) |
| 6 | REST API | [#6](https://github.com/MWBMPartners/CueRCode/issues/6) | 🔄 Addressed in code (cycle 9, 2026-06-29) — full API/UI feature parity confirmed; complete OpenAPI 3.0.3 spec; uniform endpoint security; error-code catalogue completeness + CI drift guard added (2026-07-18, #58, Epic E7 stage-2 catalogue item — `x-error-codes` machine-readable array on openapi.json's ErrorResponse schema, 83 codes, 7 newly-documented; `tests/test_error_catalogue.php` +119 assertions; 1561/1561 suite green); needs live-server verification (#18) before close |
| 7 | Testing, Documentation & Polish | [#7](https://github.com/MWBMPartners/CueRCode/issues/7) | ⚠️ Branding PNGs; closing PHP tags; no screen reader testing |
| - | Project Rebrand (QRmy.Link -> CueRCode) | [#8](https://github.com/MWBMPartners/CueRCode/issues/8) | ✅ Complete (closed) |
| - | WCAG 2.1 AA Accessibility | [#9](https://github.com/MWBMPartners/CueRCode/issues/9) | 🔄 Skip link, main landmark, colourblind toggle, accessible-QR presets and automated accessibility tests are all built and pass (2026-09-23 audit); only the real hands-on screen-reader/keyboard/device test is left (#22) |

**Audit gap issues:**

| Issue | Gap | Priority | Autopilot Status |
| --- | --- | --- | --- |
| [#39](https://github.com/MWBMPartners/CueRCode/issues/39) | Missing QR types: Facebook Messenger, Instagram, Rating/Review | Medium | 🔄 Addressed in code (cycle 6, 2026-06-29) — builders + seed rows added, API docs list 26 types, tests pass; keep open (2026-09-23 audit) — no Facebook Messenger logo and no review/star preset logo exist in the logo folder or database, so those two new types still lack a matching preset logo (follow-up #115); scanning on real phones tracked under #17 |
| [#40](https://github.com/MWBMPartners/CueRCode/issues/40) | JPEG XL output format support | Low (host-dependent) | ⬜ Blocked on the host — Dreamhost's image library can't write JPEG XL; needs an owner decision on a different host/VPS or dropping it. Small code gap: asking for format `'jxl'` quietly returns a PNG instead of an error (2026-09-23 audit, follow-up #115) |
| [#41](https://github.com/MWBMPartners/CueRCode/issues/41) | Remove closing PHP tags from include files | Low | ✅ Complete (closed, verified clean 2026-09-23) |
| [#42](https://github.com/MWBMPartners/CueRCode/issues/42) | Implement colourblind-friendly UI toggle | Medium | ✅ Complete (closed) — Okabe-Ito palettes, navbar dropdown, pre-paint script; the colourblind toggle IS built (the old Phase 1 "no colourblind toggle" note is stale); only a hands-on simulator check on the live site is left, tracked under #22 |
| [#43](https://github.com/MWBMPartners/CueRCode/issues/43) | Export branding PNGs from SVGs | Low | ✅ Complete (closed 2026-09-23 audit) — every app icon, favicon, Apple touch icon and social-sharing image exists at the right size and matches the current logo |
| [#45](https://github.com/MWBMPartners/CueRCode/issues/45) | API parity: expose all UI customisation params via REST | High | ✅ Complete (closed) — all customisation params confirmed in buildCustomizationFromRequest(); full OpenAPI 3.0.3 spec; uniform endpoint security confirmed; nothing left on this issue's own checklist (live testing tracked separately under #18). Note (2026-09-23 audit): image-based foreground/background fill from the project brief §3 is missing from BOTH the UI and the API, so parity holds; now tracked in #115 |

**Deliverables so far (2026-09-23 audit counts):** 77 tracked web PHP files (`php -l` clean), 5 site JS files, 1 CSS file (`cuercode.css`, 1,617 lines), 26 SVG predefined logo assets (0 PNG copies yet, #65), 29 database tables, 38 code-type rows (26 active QR + 11 barcode + 1 inactive `dynamic_url`), 400 emoji (SVG + PNG), 6 output formats, OpenAPI 3.0.3 spec with 30 paths and 93 catalogued error codes, Swagger UI (jsDelivr with a committed local fallback), PWA service worker, offline fallback page, and 2185/2185 tests passing across 23 test files.

---

## ⬜ Phase 1.5: Live Server Testing & Verification

**Milestone:** [Phase 1.5: Live Server Testing & Verification](https://github.com/MWBMPartners/CueRCode/milestone/2)

Verify all Phase 1 features work correctly on the live Dreamhost shared hosting server.

| Issue | Task | Status |
| --- | --- | --- |
| [#13](https://github.com/MWBMPartners/CueRCode/issues/13) | Test database installation on live Dreamhost server | ⬜ The `/install/` page is built (checks PHP version/add-ons, creates tables, seeds data, writes a lock file), but has never run on real Dreamhost. Note: this issue's original checklist numbers are out of date — it now needs to check for 29 tables, about 78 settings rows, 38 code types and 26 logos, not the smaller numbers it was written against. The installer also doesn't yet check for the `zip` and `bcmath` PHP add-ons (follow-up #113) |
| [#17](https://github.com/MWBMPartners/CueRCode/issues/17) | Test **26 QR types + 11 barcode types × 6 formats** on live server | ⬜ Originally written as "21 QR code types"; the catalogue has since grown to 26 QR types plus 11 barcode types, each in 6 output formats (JPEG XL was deliberately dropped, #40), so the live checklist needs to grow to match |
| [#18](https://github.com/MWBMPartners/CueRCode/issues/18) | Test REST API endpoints | ⬜ |
| [#22](https://github.com/MWBMPartners/CueRCode/issues/22) | Test responsive UI and accessibility on multiple devices | ⬜ |

---

## 📋 Phase 2: User Accounts & Organisations

**Milestone:** [Phase 2: User Accounts & Organisations](https://github.com/MWBMPartners/CueRCode/milestone/3)

| Issue | Task | Status |
| --- | --- | --- |
| [#11](https://github.com/MWBMPartners/CueRCode/issues/11) | Database schema: User accounts, organisations, and roles tables | 🔄 Addressed in code (Epic E3 stage 1, 2026-07-10) — `tblUsers` rebuilt + 7 new tables added to `install.sql`; needs live-server verification |
| [#12](https://github.com/MWBMPartners/CueRCode/issues/12) | User registration with email verification | 🔄 Addressed in code (Epic E3 stage 1, 2026-07-10) — `registerUser()`/`verifyEmailToken()` (`fn_auth.php`) + `POST /api/v1/auth/register`+`/verify-email`; UI added (Epic E3 stage 2b, 2026-07-10) — `public_html/register/` + `public_html/verify-email/` (AJAX + no-JS fallback, confirm-click to survive mail-scanner token prefetch); needs live-server verification |
| [#14](https://github.com/MWBMPartners/CueRCode/issues/14) | User login, logout, and session management | 🔄 Addressed in code (Epic E3 stage 1, 2026-07-10) — `loginUser()`/`logoutCurrentUser()`/`currentUser()` (`fn_auth.php`, Argon2id + lockout + database-backed sessions) + `POST /api/v1/auth/login`+`/logout`; UI added (Epic E3 stage 2b, 2026-07-10) — `public_html/login/` (allowlisted `?next=` redirect), navbar user indicator, account dashboard session list/revoke + `GET/DELETE /api/v1/account/sessions[/{id}]`; needs live-server verification |
| [#15](https://github.com/MWBMPartners/CueRCode/issues/15) | Password reset flow | 🔄 Addressed in code (Epic E3 stage 1, 2026-07-10) — `requestPasswordReset()`/`resetPasswordWithToken()` (`fn_auth.php`) + `POST /api/v1/auth/request-password-reset`+`/reset-password`; UI added (Epic E3 stage 2b, 2026-07-10) — `public_html/forgot-password/` + `public_html/reset-password/`; needs live-server verification |
| [#16](https://github.com/MWBMPartners/CueRCode/issues/16) | WebAuthn/Passkey support (Windows Hello, Touch ID, Face ID) | 📋 (schema stub `tblWebAuthnCredentials` added, Epic E3 stage 1; implementation deferred to E3 stage 7, G-006) |
| [#19](https://github.com/MWBMPartners/CueRCode/issues/19) | OAuth SSO: Microsoft 365 login | 🔄 Addressed in code (Epic E2, 2026-07-10, #55) — `_functions/fn_oauth.php` (PKCE+state+nonce, JWKS/RS256 id_token verification, conservative account linking), `public_html/auth/{microsoft}` + `/auth/callback/microsoft`, login/register buttons (rendered only when configured+enabled); inert by default (`sso_enabled`/`sso_microsoft_enabled` seed 0); needs owner IdP registration + live-server verification. Gaps (2026-09-23 audit): the Microsoft profile picture is not copied to the CueRCode account (follow-up #114), and there is no per-organisation SSO setting (waits on #21) |
| [#20](https://github.com/MWBMPartners/CueRCode/issues/20) | OAuth SSO: Google Workspace login | 🔄 Addressed in code (Epic E2, 2026-07-10, #55) — same `fn_oauth.php` engine, Google provider fully wired (`public_html/auth/google` + `/auth/callback/google`); inert by default (`sso_google_enabled` seeds 0); needs owner IdP registration + live-server verification. Gaps (2026-09-23 audit): the Google profile picture is not copied over (follow-up #114), and there is no per-organisation SSO setting (waits on #21) |
| [#21](https://github.com/MWBMPartners/CueRCode/issues/21) | Organisation creation and management | 📋 Not started (2026-09-23 audit) — only the database tables and an invitation email template exist. Everything else (create-organisation form, roles, settings page, invitations, member list, shared QR library, domain management) is still to build |
| [#23](https://github.com/MWBMPartners/CueRCode/issues/23) | User profile management and avatar support | 🔄 Addressed in code (Epic E3 stage 2b, 2026-07-10) — account dashboard profile section (display name + avatar upload/remove), `resolveAvatarUrl()` §11.4 priority chain (custom upload > Gravatar > default placeholder; MS365/Google tier unreachable pending E2), `PATCH /api/v1/account/profile`, session-gated `account/avatar/` streaming endpoint; needs live-server verification |
| [#24](https://github.com/MWBMPartners/CueRCode/issues/24) | Saved QR codes and user QR code library | 🔄 API addressed in code (Epic E3 stage 2a, 2026-07-10) — `POST /api/v1/save` + `GET/PATCH/DELETE /api/v1/qrcodes/{uuid}` + `GET /api/v1/qrcodes` (`fn_api_qrcodes.php`), ownership/IDOR-enforced (404, never 403), `saved_codes` plan-limit 402, soft-delete; UI added (Epic E3 stage 2b, 2026-07-10) — account dashboard saved-codes library (paginated grid, preview/download/delete, plan-usage badge + upgrade prompt), generator "Save to my account" button; needs live-server verification |

**Recommended build order:** #11 -> #12 -> #14 -> #15 -> #23 -> #16 -> #19 -> #20 -> #21 -> #24

---

## 📋 Phase 3: Premium Features & Payments

**Milestone:** [Phase 3: Premium Features & Payments](https://github.com/MWBMPartners/CueRCode/milestone/4)

| Issue | Task | Status |
| --- | --- | --- |
| [#25](https://github.com/MWBMPartners/CueRCode/issues/25) | Subscription tiers and premium feature gating | 🔄 Framework addressed in code (Epic E4 framework stage, 2026-07-10, #53) — `tblSubscriptionPlans`/`tblSubscriptions` schema + 4 seeded tiers, data-driven `fn_features.php` gating engine, real limits enforced on bulk generation, saved codes, API keys and dynamic codes; public plan-comparison page added (2026-07-17, #56) — `/pricing/` (`fn_pricing.php` + `public_html/pricing/index.php`), data-driven card grid sourced entirely from `tblSubscriptionPlans`, fail-safe when the DB is unavailable; in-account upgrade/checkout still pending PayPal integration (#26); needs live-server verification. Gaps (2026-09-23 audit): the account page never shows which plan a user is on or their subscription status, and there is no admin screen to edit plans (waits on #31) |
| [#26](https://github.com/MWBMPartners/CueRCode/issues/26) | PayPal payment integration | 📋 Blocked on owner-supplied PayPal business credentials (2026-09-23 audit) — only groundwork exists today (a payments table, a receipt email template, and a plan-change hook function) |

---

## 📋 Phase 4: Integrations

**Milestone:** [Phase 4: Integrations](https://github.com/MWBMPartners/CueRCode/milestone/5)

| Issue | Task | Status |
| --- | --- | --- |
| [#27](https://github.com/MWBMPartners/CueRCode/issues/27) | Go2My.Link URL shortener integration (dynamic QR codes) | 🔄 In-house redirect layer Stage S0/S1 addressed in code (2026-07-22, #27/#71/#33, #82) — schema (`tblQRRedirects`/`tblQRScans`/`tblResolveNonces`), `validateRedirectDestination()` (open-redirect/SSRF allowlist validator), `buildPayload_dynamic_url()` (domain-agnostic via `dynamic_redirect_base_url`), and an own-scoped `GET`/`PATCH /api/v1/qrcodes/{uuid}/destination` API — **all DARK/INERT**: no public `/r/{uuid}` route exists, `dynamic_url` type seeds `isActive=0`, kill-switch seeds `'0'`. Stage S2 (the live redirect route) gated on owner sign-off **G-007** — see `LAUNCH_DECISIONS.md` §H. Go2My.Link itself remains blocked on their public API (design-only, per PROJECT_ROADMAP.md). `tests/test_dynamic_redirect_s0.php`+`s1.php` (+138 assertions), 2185/2185 suite green |
| [#28](https://github.com/MWBMPartners/CueRCode/issues/28) | SIGNula account linking and SSO integration | 🔄 Partially addressed in code (Epic E2, 2026-07-10, #55) — the generic `fn_oauth.php` engine + `tblUserOAuth` account-linking machinery is BUILT and provider-agnostic (Microsoft/Google already use it, #19/#20); `signula` is present in the provider registry but stays structurally INERT (empty seeded discovery URL, `sso_signula_enabled=0`) because SIGNula.id is not yet an OIDC identity provider (credential-proxy API only - verified against its own repo) - blocked on upstream OIDC IdP work, SIGNula issue #88 |
| [#29](https://github.com/MWBMPartners/CueRCode/issues/29) | Microsoft Graph API email integration | 🔄 Addressed in code (Epic E8, 2026-07-10) - `fn_email.php` (client-credentials sendMail, cached token, queue+retry with exponential backoff), `tblEmailQueue`, 4 branded templates, kill-switch settings (seeded inert: `email_enabled=0`); needs owner M365 app registration + credentials before it can go live |
| [#30](https://github.com/MWBMPartners/CueRCode/issues/30) | Google Workspace email integration (future-ready) | 📋 Not started (2026-09-23 audit) — only a placeholder exists: an "email provider" setting and shared templates, but the sending code always uses Microsoft and ignores the setting. `fn_email.php` is written driver-shaped so this can slot in without a schema change |
| [#66](https://github.com/MWBMPartners/CueRCode/issues/66) | User- & org-uploaded centre-logo library (saved logos, tier-gated) | 📋 Not started (2026-09-23 audit) — no saved-logos table, no `/api/v1/logos` endpoint, no "My logos" area in the Logo tab. The pieces it will reuse (logo overlay engine, SVG safety check, avatar-upload pattern, plan-limit engine) already exist. Should wait for #65 to finish (so uploaded SVGs also show on PNG output); the organisation-shared half also waits on #21 |
| [#70](https://github.com/MWBMPartners/CueRCode/issues/70) | Extensible pricing model: multi price-points, discounts, lifetime & PAYG (built-but-disabled) | 🔄 Partly done (2026-09-23 audit) — new tables (price points, discounts, discount redemptions, one-off purchases, PAYG credit ledger) plus extra columns on subscriptions/payments, four helper functions in `fn_features.php`, and three kill-switches all seeded off; `tests/test_pricing_flex.php` shows a lifetime plan, a time-limited discount and a credit pack can be set up with data only. Gaps: the SQL has never run against a real database, running `install.sql` a second time is NOT clean (the two `ALTER TABLE ADD COLUMN` blocks have no existence guard, so a re-run fails with "duplicate column"), and there is no upgrade script for a database installed before this change |
| [#71](https://github.com/MWBMPartners/CueRCode/issues/71) | Dual-function QR — browser→web, sanctioned app→in-app action | 📋 Blocked on the owner (2026-09-23 audit) — only shared groundwork exists (spare columns on `tblQRRedirects`, a `dual_function_qr` plan switch (seeded on only for the Business plan, but inert because nothing uses it yet), the link-safety checker). Everything specific to this feature (public `/r/{uuid}` redirect, app "resolve" endpoint, approved-apps register, gating, docs, tests) is blocked on the owner's G-007 decision and on agreeing the ProjectEPass app contract |

**Note:** #29 (email) is prioritised early as it's needed by Phase 2 (registration, password reset, invitations) - code-complete and inert pending owner M365 setup; not yet live-server verified (needs #13/#18 style verification once deployed). Critical gap (2026-09-23 audit): nothing ever empties the email send queue — there is no scheduled job and the optional "process on page load" hook is never switched on — so queued emails (including sign-up verification) will not be sent even after the owner finishes the Microsoft 365 setup. Tracked as #112 (high-priority bug).

---

## 📋 Phase 5: Advanced Features & Polish

**Milestone:** [Phase 5: Advanced Features & Polish](https://github.com/MWBMPartners/CueRCode/milestone/6)

| Issue | Task | Status |
| --- | --- | --- |
| [#31](https://github.com/MWBMPartners/CueRCode/issues/31) | Admin dashboard: Global Admin control panel | 📋 Not started (2026-09-23 audit; the encrypted-settings screen is also tracked as #115). This is now blocking the owner from entering encrypted credentials for email (#29), SSO (#55), bot protection and PayPal — needed: admin-only dashboard, error/activity log viewers with filters, settings editor (including encrypted values), user/organisation/QR-type/API-key management, health view |
| [#32](https://github.com/MWBMPartners/CueRCode/issues/32) | Bulk QR code generation | 🔄 Addressed in code (cycle 8, 2026-06-29) — POST /api/v1/bulk-generate + accessible UI panel + ZIP streaming; accessibility-preset parity closed (#57, FG-033 close-out, 2026-07-18) — `preset`/`scan_distance_cm` now enforced per item in both the ZIP loop and the Avery-sheet fork (manifest failure / blank slot, not a batch 422), CSV reserved-column list completed to 20/20 keys; `tests/test_bulk.php` +33 assertions; 2185/2185 suite green; needs live-server verification (#18). Gaps (2026-09-23 audit, keep open): no `.xlsx` support (CSV only), no column-mapping screen, no real progress bar (single spinner for the whole batch), no per-item style overrides in the web screen beyond JSON, and no queue for large batches (they are simply capped) — follow-up #115 |
| [#33](https://github.com/MWBMPartners/CueRCode/issues/33) | QR code analytics and scan tracking | 📋 Not started (2026-09-23 audit) — schema-only. `tblQRScans` (install.sql Table 28: `ipHash` SHA-256+pepper never raw IP, coarse UA fields, `scanChannel`/`outcome`) exists but is completely unwritten — the write path is the future `/r/` route's fire-and-forget accounting step (Stage S2/S3), not built this cycle. No dashboard, no GeoLite2, no API endpoint yet |
| [#34](https://github.com/MWBMPartners/CueRCode/issues/34) | Internationalisation (i18n) and translation framework | 📋 Not started (2026-09-23 audit) — all page text is written directly in English; nothing built (translation function, language files/database, language picker, saved preference, admin translation editor, right-to-left layout). Blocked on owner go-ahead |
| [#44](https://github.com/MWBMPartners/CueRCode/issues/44) | GitHub Actions: SFTP auto-deploy for alpha, beta and main branches | 📋 Blocked on the owner (2026-09-23 audit) — the deploy workflow is fully written (picks the right site folder per branch, uploads shared and private folders, minifies files, SSH key with password fallback, summary report, stays off unless switched on), but every deploy run has been skipped because the switch and server login secrets are not set in GitHub (30 runs, all skipped). Also carries the follow-ups moved from #36: the upload doesn't include the third-party libraries or local CDN-fallback copies (#113), and it turns off SSH host-key and TLS certificate checking |
| [#54](https://github.com/MWBMPartners/CueRCode/issues/54) | [E7][EPIC] Platform-grade API — key lifecycle+scopes, pagination, idempotency, error catalogue, webhooks | 🔄 Partly done (2026-09-23 audit) — built: self-service API keys (create/list/rotate/delete, shown once, owner-only, plan limit, per-key permission checks), paging limits on list endpoints, and a machine-readable error-code catalogue kept in sync by a drift-guard test. Not built: Idempotency-Key support on generate/bulk/save, webhooks (also need scan events from #33/E11), per-key allowed-website (CORS) lists, and mobile-app sessions/device registration |
| [#64](https://github.com/MWBMPartners/CueRCode/issues/64) | [Infra][Security] Dependabot + CodeQL/secret-scanning across all tier branches | 🔄 Partly done, blocked on owner (2026-09-23 audit) — built: Dependabot checks GitHub Actions versions on all four tier branches, CodeQL + Semgrep run on all four branches plus weekly, a public security disclosure policy exists. Owner still needs to: (1) turn on Dependabot alerts, secret scanning and push protection in repo Settings; (2) turn on GitHub Advanced Security so CodeQL results upload (then remove two temporary `continue-on-error` lines); (3) choose how to watch the hand-copied PHP/JS libraries for updates. Also found: `beta` has no `ci.yml`, so tests and the secret scan don't run on `beta` pushes (follow-up #115) |
| [#65](https://github.com/MWBMPartners/CueRCode/issues/65) | [Bug][QR] Predefined centre-logos are dead at runtime (path mismatch + SVG can't rasterise) | 🔄 Partly done (2026-09-23 audit) — Bug 1 fixed (logos now found in their real folder with the same safety check); SVG output works. Still broken: none of the 26 PNG copies exist in the repo, so on PNG output (the default format) predefined logos are still missing unless the live server has Imagick; no test proves a real predefined logo changes a PNG QR code; the person generating isn't told a logo was dropped (server log only). Fix: run `tools/render_logo_pngs.php` once on a machine with an SVG rasteriser and commit the 26 PNGs, or confirm Imagick is enabled on Dreamhost |
| [#69](https://github.com/MWBMPartners/CueRCode/issues/69) | Non-QR barcode generation API (1D + 2D symbologies) | 🔄 Addressed in code (2026-07-22, #69) — `POST /api/v1/barcode` + `/barcode/preview`, 11 symbologies (Code128, Code39, EAN-13, EAN-8, UPC-A, UPC-E, ITF, Codabar, DataMatrix, PDF417, Aztec) via vendored `tecnickcom/tc-lib-barcode`; strict server-side check-digit/charset validation; reuses the QR pipeline's `tblQRCodes` persistence/download/saved-codes machinery unchanged; gated on `barcode_generation` (seeded true on all 4 plans — future tier-split is an open owner decision); `tests/test_barcode.php` (+120 assertions), 1918/1918 suite green; API-first only — a dedicated UI generator tab is a follow-up (see DEV_NOTES.md); needs live-server verification |
| [#67](https://github.com/MWBMPartners/CueRCode/issues/67) | Native UTF emoji centre-icon picker (vector-first Twemoji) | ✅ Complete (closed 2026-09-23 after the audit) — ~400 curated Twemoji (CC-BY-4.0) emoji vendored under `public_html/assets/img/emoji/` (SVG + PNG twin per emoji, 400 total); `tools/emoji_index.php` generates the committed `emoji-index.json`; strict `emoji:<codepoint-seq>` logo discriminator in `fn_qr_logo.php`'s `getEmojiLogoPath()`; searchable/categorised/keyboard-accessible picker in the Logo tab (`<noscript>` fallback); `/attributions` page + footer link (CC-BY-4.0 requirement); `tests/test_emoji_logo.php`; full suite (2185/2185) passes and `php -l` is clean everywhere. Optional follow-up only: grow beyond the ~400 starter set to the full ~3,700 emoji |
| [#68](https://github.com/MWBMPartners/CueRCode/issues/68) | Scheduled GitHub Action to ingest newly-released emoji | 🔄 Built, only runs once on `main` (2026-09-23 audit) — `.github/workflows/emoji-ingest.yml`, the repo's FIRST `schedule:` workflow (monthly + `workflow_dispatch`); compares the vendored Twemoji tag against the latest upstream release and opens a diff-report ISSUE (never a bot PR); least-privilege permissions + SHA-pinned actions; passes the workflow linter. It has never actually run: GitHub only runs scheduled workflows from the default branch (`main`), and this file currently lives only on `alpha`/`work-in-progress`, so the monthly timer is not active yet. Run it once by hand ("Run workflow") after it reaches `main` |

---

## 📋 Phase 6: CI/CD, Legal & Compliance

**Milestone:** [Phase 6: CI/CD, Legal & Compliance](https://github.com/MWBMPartners/CueRCode/milestone/7)

| Issue | Task | Status |
| --- | --- | --- |
| [#35](https://github.com/MWBMPartners/CueRCode/issues/35) | GitHub Actions: automated version numbering and CHANGELOG | 🔄 Partly done (2026-09-23 audit) — automatic version-number increase on push (based on commit message type) and automatic GitHub Releases on version tags are in place; deploy also stamps commit details into the version file. Gaps: CHANGELOG.md is not generated automatically, and the version file is never read by the app — the footer shows no version and `/api/v1/health` reads a separate database setting, so the shown version won't match the bumped one (follow-up #115) |
| [#36](https://github.com/MWBMPartners/CueRCode/issues/36) | GitHub Actions: auto-deploy via SFTP to Dreamhost | ✅ Complete (closed, duplicate of #44) — the automatic upload exists and runs on every push to `main`, `beta` and `alpha`, each to its own site folder, SSH key with password fallback, real credentials never in the repo, a short summary after each run. Follow-ups moved to #44: the upload doesn't include the third-party libraries or local CDN-fallback copies (#113); it doesn't check host-key/certificate authenticity; and nothing has been tested against the live server yet |
| [#37](https://github.com/MWBMPartners/CueRCode/issues/37) | Comprehensive data protection and privacy compliance | 🔄 Addressed in code (cookie-consent banner, cycle 7, 2026-06-29, FG-026; data export/erasure, epic #52, 2026-07-10) — `GET /api/v1/account/export` (session-only, complete own-scoped JSON export incl. saved codes/sessions/subscriptions+payments/linked SSO/API keys, never secrets) + a plain-navigable `account/export/` download link; `POST /api/v1/account/erase` (session-only, password-confirmed, `confirm:true`-gated, one DB transaction) anonymizes email/name/avatar/password + revokes sessions/keys/SSO links + soft-deletes saved codes while retaining subscription/payment rows for financial/audit obligations; account-dashboard "Your data" section; `privacy.php` documents the mechanism + retention model; owner-run hard-purge runbook documented (DEV_NOTES.md) rather than automated; `tests/test_account_data.php` (116 assertions) + live scratch-DB/HTTP two-account evidence (own-only export, API-key→403, wrong-password→401, missing-confirm→400, successful anonymization, unaffected second account); full suite green; needs live-server verification (#22). Gaps (2026-09-23 audit): no per-category cookie opt-in (notice-only banner), consent is not recorded in the database, no data-breach notification mechanism, retention periods are placeholders with no automatic deletion |
| [#38](https://github.com/MWBMPartners/CueRCode/issues/38) | Enhanced Terms of Use, Privacy Policy, and Copyright pages | 🔄 Blocked on the owner (2026-09-23 audit) — terms/privacy/cookie pages exist with sections for all listed regulations, are linked from every page footer, and use the shared site theme. 9 `[TEMPLATE: …]` placeholders (plus a TEMPLATE warning banner on each page) still need legal counsel to fill (liability cap, governing law, retention periods, EU/UK representatives, company/DPO contact details); needs legal-counsel review and live-server verification (#17) before close. Small code gap: the footer copyright shows one fixed year from the database ('2026') instead of an automatic 2025-current range (follow-up #115) |
| [#61](https://github.com/MWBMPartners/CueRCode/issues/61) | ci: add actionlint workflow-lint CI | ✅ Complete (closed 2026-09-23 after the audit) — `.github/workflows/lint.yml` runs actionlint on all four tier branches whenever a workflow file changes, and every current workflow file passes it |
| [#103](https://github.com/MWBMPartners/CueRCode/issues/103) | [Session] Merge Dependabot PRs #98-101, full issue/docs/memory sweep, and alpha-release work proposals | 🔄 Partly done (2026-09-23 audit) — Part 1 (Dependabot merges) reflected on every branch. Part 2b fixed: the security-copy workflow on `main` now quietly skips a fix that's already present instead of opening false "manual backport" issues (#93-#95 closed). Part 2c fixed: `release-candidate` is now set up with CodeQL, Semgrep and the full CI workflow (the files are there and correctly triggered) — but none of them have actually completed a run yet, because GitHub has refused to start any Actions job on this repository since 2026-09-23 (a billing/spending-limit problem, not a fault in the workflows). Part 2d fixed: the API docs page loads Swagger UI from jsDelivr with a committed local fallback. Remaining: Part 3 (this issue/docs/memory sweep and alpha-release proposals) is in progress; the main-branch protection rule change can only be confirmed in GitHub Settings; `beta` still has no `ci.yml` |

**Note:** #35 and #36 (CI/CD) can be implemented at any time and would benefit all phases.

---

## 📌 Status Legend

| Icon | Meaning |
| --- | --- |
| ✅ | Complete |
| 🔄 | In Progress |
| ⬜ | Not Started (requires live server or pending) |
| 📋 | Planned (future phase) |
| ❌ | Abandoned |
