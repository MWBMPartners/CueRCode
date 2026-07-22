# 📊 CueRCode - Project Status

**📅 Last Updated:** 2026-07-18 (public /pricing page (#56); bulk accessibility parity (#57); API error-code catalogue completeness + drift guard (#58))
**🔄 Current Phase:** Phase 1.5 - Live Server Testing & Verification (Phase 2 stage 1 code-complete, pending live-server verification)
**📈 Overall Status:** In Development

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
| 2 | Database Schema & SQL Installation | [#2](https://github.com/MWBMPartners/CueRCode/issues/2) | ✅ tblUsers fully implemented + 7 new accounts/sessions/orgs tables added (Epic E3 stage 1, 2026-07-10, 18 tables total); 3 missing QR seed rows added (cycle 6); deeplink + gs1_product seed rows added (cycle 8) |
| 3 | Core PHP Framework | [#3](https://github.com/MWBMPartners/CueRCode/issues/3) | ⬜ Needs live server verification |
| 4 | QR Code Generation Engine | [#4](https://github.com/MWBMPartners/CueRCode/issues/4) | ⚠️ 26/26 QR types now in code (cycle 8); JPEG XL missing (host-dependent); needs live-server verification |
| 5 | Responsive Web UI | [#5](https://github.com/MWBMPartners/CueRCode/issues/5) | ⚠️ Colourblind mode + cookie-consent banner added in code (cycle 7); no i18n; needs live-server verification (#22) |
| 6 | REST API | [#6](https://github.com/MWBMPartners/CueRCode/issues/6) | 🔄 Addressed in code (cycle 9, 2026-06-29) — full API/UI feature parity confirmed; complete OpenAPI 3.0.3 spec; uniform endpoint security; error-code catalogue completeness + CI drift guard added (2026-07-18, #58, Epic E7 stage-2 catalogue item — `x-error-codes` machine-readable array on openapi.json's ErrorResponse schema, 83 codes, 7 newly-documented; `tests/test_error_catalogue.php` +119 assertions; 1561/1561 suite green); needs live-server verification (#18) before close |
| 7 | Testing, Documentation & Polish | [#7](https://github.com/MWBMPartners/CueRCode/issues/7) | ⚠️ Branding PNGs; closing PHP tags; no screen reader testing |
| - | Project Rebrand (QRmy.Link -> CueRCode) | [#8](https://github.com/MWBMPartners/CueRCode/issues/8) | ✅ Complete (closed) |
| - | WCAG 2.1 AA Accessibility | [#9](https://github.com/MWBMPartners/CueRCode/issues/9) | ⚠️ Good ARIA foundation but no colourblind toggle or screen reader testing |

**Audit gap issues:**

| Issue | Gap | Priority | Autopilot Status |
| --- | --- | --- | --- |
| [#39](https://github.com/MWBMPartners/CueRCode/issues/39) | Missing QR types: Facebook Messenger, Instagram, Rating/Review | Medium | ✅ Addressed in code (cycle 6, 2026-06-29) — builders + seed rows added; needs live-server verification (#17) |
| [#40](https://github.com/MWBMPartners/CueRCode/issues/40) | JPEG XL output format support | Low (host-dependent) | ⬜ Queued — host limitation (GD/Dreamhost); document + WEBP fallback recommended |
| [#41](https://github.com/MWBMPartners/CueRCode/issues/41) | Remove closing PHP tags from include files | Low | ✅ Complete (verified clean) |
| [#42](https://github.com/MWBMPartners/CueRCode/issues/42) | Implement colourblind-friendly UI toggle | Medium | 🔄 Addressed in code (cycle 7, 2026-06-29) — Okabe-Ito palettes, navbar dropdown, pre-paint script; needs live-server verification (#22) |
| [#43](https://github.com/MWBMPartners/CueRCode/issues/43) | Export branding PNGs from SVGs | Low | 🔄 Addressed in code (cycle 7, 2026-06-29) — og-image.png created at /assets/img/branding/og-image.png; needs live-server verification (#22) |
| [#45](https://github.com/MWBMPartners/CueRCode/issues/45) | API parity: expose all UI customisation params via REST | High | 🔄 Addressed in code (cycle 9, 2026-06-29) — all 21 customisation params confirmed in buildCustomizationFromRequest(); full OpenAPI 3.0.3 spec completed; uniform endpoint security confirmed; pending live-server verification (#18) |

**Deliverables so far:** 154 PHP files, 3 JS files, 1 CSS file (1,301 lines), 25 SVG logo assets, 21 database tables, 26 QR code types (cycle 8), OpenAPI 3.0 spec, Swagger UI, PWA service worker, offline fallback page.

---

## ⬜ Phase 1.5: Live Server Testing & Verification

**Milestone:** [Phase 1.5: Live Server Testing & Verification](https://github.com/MWBMPartners/CueRCode/milestone/2)

Verify all Phase 1 features work correctly on the live Dreamhost shared hosting server.

| Issue | Task | Status |
| --- | --- | --- |
| [#13](https://github.com/MWBMPartners/CueRCode/issues/13) | Test database installation on live Dreamhost server | ⬜ |
| [#17](https://github.com/MWBMPartners/CueRCode/issues/17) | Test all 21 QR code types and output formats | ⬜ |
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
| [#19](https://github.com/MWBMPartners/CueRCode/issues/19) | OAuth SSO: Microsoft 365 login | 🔄 Addressed in code (Epic E2, 2026-07-10, #55) — `_functions/fn_oauth.php` (PKCE+state+nonce, JWKS/RS256 id_token verification, conservative account linking), `public_html/auth/{microsoft}` + `/auth/callback/microsoft`, login/register buttons (rendered only when configured+enabled); inert by default (`sso_enabled`/`sso_microsoft_enabled` seed 0); needs owner IdP registration + live-server verification |
| [#20](https://github.com/MWBMPartners/CueRCode/issues/20) | OAuth SSO: Google Workspace login | 🔄 Addressed in code (Epic E2, 2026-07-10, #55) — same `fn_oauth.php` engine, Google provider fully wired (`public_html/auth/google` + `/auth/callback/google`); inert by default (`sso_google_enabled` seeds 0); needs owner IdP registration + live-server verification |
| [#21](https://github.com/MWBMPartners/CueRCode/issues/21) | Organisation creation and management | 📋 |
| [#23](https://github.com/MWBMPartners/CueRCode/issues/23) | User profile management and avatar support | 🔄 Addressed in code (Epic E3 stage 2b, 2026-07-10) — account dashboard profile section (display name + avatar upload/remove), `resolveAvatarUrl()` §11.4 priority chain (custom upload > Gravatar > default placeholder; MS365/Google tier unreachable pending E2), `PATCH /api/v1/account/profile`, session-gated `account/avatar/` streaming endpoint; needs live-server verification |
| [#24](https://github.com/MWBMPartners/CueRCode/issues/24) | Saved QR codes and user QR code library | 🔄 API addressed in code (Epic E3 stage 2a, 2026-07-10) — `POST /api/v1/save` + `GET/PATCH/DELETE /api/v1/qrcodes/{uuid}` + `GET /api/v1/qrcodes` (`fn_api_qrcodes.php`), ownership/IDOR-enforced (404, never 403), `saved_codes` plan-limit 402, soft-delete; UI added (Epic E3 stage 2b, 2026-07-10) — account dashboard saved-codes library (paginated grid, preview/download/delete, plan-usage badge + upgrade prompt), generator "Save to my account" button; needs live-server verification |

**Recommended build order:** #11 -> #12 -> #14 -> #15 -> #23 -> #16 -> #19 -> #20 -> #21 -> #24

---

## 📋 Phase 3: Premium Features & Payments

**Milestone:** [Phase 3: Premium Features & Payments](https://github.com/MWBMPartners/CueRCode/milestone/4)

| Issue | Task | Status |
| --- | --- | --- |
| [#25](https://github.com/MWBMPartners/CueRCode/issues/25) | Subscription tiers and premium feature gating | 🔄 Framework addressed in code (Epic E4 framework stage, 2026-07-10, #53) — `tblSubscriptionPlans`/`tblSubscriptions` schema + 4 seeded tiers, data-driven `fn_features.php` gating engine, one real gate wired (`bulk_batch_size`); public plan-comparison page added (2026-07-17, #56) — `/pricing/` (`fn_pricing.php` + `public_html/pricing/index.php`), data-driven card grid sourced entirely from `tblSubscriptionPlans`, fail-safe when the DB is unavailable; in-account upgrade/checkout still pending PayPal integration (#26); needs live-server verification |
| [#26](https://github.com/MWBMPartners/CueRCode/issues/26) | PayPal payment integration | 📋 |

---

## 📋 Phase 4: Integrations

**Milestone:** [Phase 4: Integrations](https://github.com/MWBMPartners/CueRCode/milestone/5)

| Issue | Task | Status |
| --- | --- | --- |
| [#27](https://github.com/MWBMPartners/CueRCode/issues/27) | Go2My.Link URL shortener integration (dynamic QR codes) | 🔄 In-house redirect layer Stage S0/S1 addressed in code (2026-07-22, #27/#71/#33, #82) — schema (`tblQRRedirects`/`tblQRScans`/`tblResolveNonces`), `validateRedirectDestination()` (open-redirect/SSRF allowlist validator), `buildPayload_dynamic_url()` (domain-agnostic via `dynamic_redirect_base_url`), and an own-scoped `GET`/`PATCH /api/v1/qrcodes/{uuid}/destination` API — **all DARK/INERT**: no public `/r/{uuid}` route exists, `dynamic_url` type seeds `isActive=0`, kill-switch seeds `'0'`. Stage S2 (the live redirect route) gated on owner sign-off **G-007** — see `LAUNCH_DECISIONS.md` §H. Go2My.Link itself remains blocked on their public API (design-only, per PROJECT_ROADMAP.md). `tests/test_dynamic_redirect_s0.php`+`s1.php` (+138 assertions), 2185/2185 suite green |
| [#28](https://github.com/MWBMPartners/CueRCode/issues/28) | SIGNula account linking and SSO integration | 🔄 Partially addressed in code (Epic E2, 2026-07-10, #55) — the generic `fn_oauth.php` engine + `tblUserOAuth` account-linking machinery is BUILT and provider-agnostic (Microsoft/Google already use it, #19/#20); `signula` is present in the provider registry but stays structurally INERT (empty seeded discovery URL, `sso_signula_enabled=0`) because SIGNula.id is not yet an OIDC identity provider (credential-proxy API only - verified against its own repo) - blocked on upstream OIDC IdP work, SIGNula issue #88 |
| [#29](https://github.com/MWBMPartners/CueRCode/issues/29) | Microsoft Graph API email integration | 🔄 Addressed in code (Epic E8, 2026-07-10) - `fn_email.php` (client-credentials sendMail, cached token, queue+retry with exponential backoff), `tblEmailQueue`, 4 branded templates, kill-switch settings (seeded inert: `email_enabled=0`); needs owner M365 app registration + credentials before it can go live |
| [#30](https://github.com/MWBMPartners/CueRCode/issues/30) | Google Workspace email integration (future-ready) | 📋 `fn_email.php` is written driver-shaped (`email_provider` setting) so this can slot in without a schema change |

**Note:** #29 (email) is prioritised early as it's needed by Phase 2 (registration, password reset, invitations) - code-complete and inert pending owner M365 setup; not yet live-server verified (needs #13/#18 style verification once deployed).

---

## 📋 Phase 5: Advanced Features & Polish

**Milestone:** [Phase 5: Advanced Features & Polish](https://github.com/MWBMPartners/CueRCode/milestone/6)

| Issue | Task | Status |
| --- | --- | --- |
| [#31](https://github.com/MWBMPartners/CueRCode/issues/31) | Admin dashboard: Global Admin control panel | 📋 |
| [#32](https://github.com/MWBMPartners/CueRCode/issues/32) | Bulk QR code generation | 🔄 Addressed in code (cycle 8, 2026-06-29) — POST /api/v1/bulk-generate + accessible UI panel + ZIP streaming; accessibility-preset parity closed (#57, FG-033 close-out, 2026-07-18) — `preset`/`scan_distance_cm` now enforced per item in both the ZIP loop and the Avery-sheet fork (manifest failure / blank slot, not a batch 422), CSV reserved-column list completed to 20/20 keys; `tests/test_bulk.php` +33 assertions; 1442/1442 suite green; needs live-server verification (#18) |
| [#33](https://github.com/MWBMPartners/CueRCode/issues/33) | QR code analytics and scan tracking | 🔄 Schema-only addressed in code (2026-07-22, #33, part of the Stage S0 dynamic-redirect delta, #82) — `tblQRScans` (install.sql Table 28: `ipHash` SHA-256+pepper never raw IP, coarse UA fields, `scanChannel`/`outcome`) exists but is completely unwritten — the write path is the future `/r/` route's fire-and-forget accounting step (Stage S2/S3), not built this cycle. No dashboard, no GeoLite2, no API endpoint yet |
| [#34](https://github.com/MWBMPartners/CueRCode/issues/34) | Internationalisation (i18n) and translation framework | 📋 |
| [#69](https://github.com/MWBMPartners/CueRCode/issues/69) | Non-QR barcode generation API (1D + 2D symbologies) | 🔄 Addressed in code (2026-07-22, #69) — `POST /api/v1/barcode` + `/barcode/preview`, 11 symbologies (Code128, Code39, EAN-13, EAN-8, UPC-A, UPC-E, ITF, Codabar, DataMatrix, PDF417, Aztec) via vendored `tecnickcom/tc-lib-barcode`; strict server-side check-digit/charset validation; reuses the QR pipeline's `tblQRCodes` persistence/download/saved-codes machinery unchanged; gated on `barcode_generation` (seeded true on all 4 plans — future tier-split is an open owner decision); `tests/test_barcode.php` (+120 assertions), 1918/1918 suite green; API-first only — a dedicated UI generator tab is a follow-up (see DEV_NOTES.md); needs live-server verification |
| [#67](https://github.com/MWBMPartners/CueRCode/issues/67) | Native UTF emoji centre-icon picker (vector-first Twemoji) | 🔄 Addressed in code (2026-07-22, #67) — ~400 curated Twemoji (CC-BY-4.0) emoji vendored under `public_html/assets/img/emoji/` (SVG + 72×72 PNG twin per emoji, reuses the #65 PNG-twin lookup UNCHANGED); `tools/emoji_index.php` generates the committed `emoji-index.json` (Unicode `emoji-test.txt` when reachable, hand-curated `tools/emoji_curated_map.php` fallback — the fallback is what shipped this session, `unicode.org` was proxy-blocked in the sandbox); new `emoji:<codepoint-seq>` logo discriminator in `getLogoPath()` under a new F-017 allowed base; searchable/categorised/keyboard-accessible picker in the Logo tab (`<noscript>` fallback); `/attributions` page + footer link (CC-BY-4.0 requirement); `tests/test_emoji_logo.php` (+123 assertions), 2041/2041 suite green; needs live-server verification |
| [#68](https://github.com/MWBMPartners/CueRCode/issues/68) | Scheduled GitHub Action to ingest newly-released emoji | 🔄 Addressed in code (2026-07-22, #68) — `.github/workflows/emoji-ingest.yml`, the repo's FIRST `schedule:` workflow (monthly + `workflow_dispatch`); compares the vendored Twemoji tag (`tools/emoji_vendor_version.json`) against the latest upstream release, diffs the two tags' asset trees for genuinely new emoji, and opens a diff-report ISSUE (never a bot PR, per the 2026-07-18 working agreement — PR mode remains a documented future owner opt-in); least-privilege permissions + SHA-pinned actions; needs a live scheduled/manual-dispatch run to verify end-to-end (cannot be exercised from this sandbox — no outbound GitHub Actions runner here) |

---

## 📋 Phase 6: CI/CD, Legal & Compliance

**Milestone:** [Phase 6: CI/CD, Legal & Compliance](https://github.com/MWBMPartners/CueRCode/milestone/7)

| Issue | Task | Status |
| --- | --- | --- |
| [#35](https://github.com/MWBMPartners/CueRCode/issues/35) | GitHub Actions: automated version numbering and CHANGELOG | 📋 |
| [#36](https://github.com/MWBMPartners/CueRCode/issues/36) | GitHub Actions: auto-deploy via SFTP to Dreamhost | 📋 |
| [#37](https://github.com/MWBMPartners/CueRCode/issues/37) | Comprehensive data protection and privacy compliance | 🔄 Addressed in code (cookie-consent banner, cycle 7, 2026-06-29, FG-026; data export/erasure, epic #52, 2026-07-10) — `GET /api/v1/account/export` (session-only, complete own-scoped JSON export incl. saved codes/sessions/subscriptions+payments/linked SSO/API keys, never secrets) + a plain-navigable `account/export/` download link; `POST /api/v1/account/erase` (session-only, password-confirmed, `confirm:true`-gated, one DB transaction) anonymizes email/name/avatar/password + revokes sessions/keys/SSO links + soft-deletes saved codes while retaining subscription/payment rows for financial/audit obligations; account-dashboard "Your data" section; `privacy.php` documents the mechanism + retention model; owner-run hard-purge runbook documented (DEV_NOTES.md) rather than automated; `tests/test_account_data.php` (116 assertions) + live scratch-DB/HTTP two-account evidence (own-only export, API-key→403, wrong-password→401, missing-confirm→400, successful anonymization, unaffected second account); 1322/1322 suite green; needs live-server verification (#22) |
| [#38](https://github.com/MWBMPartners/CueRCode/issues/38) | Enhanced Terms of Use, Privacy Policy, and Copyright pages | 🔄 Addressed in code (cycle 6, 2026-06-29) — privacy.php + terms.php rewritten as multi-jurisdiction TEMPLATE pages; needs legal-counsel review and live-server verification (#17) before close |

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
