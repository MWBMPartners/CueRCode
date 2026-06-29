# 📊 CueRCode - Project Status

**📅 Last Updated:** 2026-03-23
**🔄 Current Phase:** Phase 1.5 - Live Server Testing & Verification
**📈 Overall Status:** In Development

---

## 📋 Phase Summary

| Phase | Name | Milestone | Issues | Status |
| --- | --- | --- | --- | --- |
| 1 | Core QR Engine | [#1](https://github.com/MWBMPartners/CueRCode/milestone/1) | #1-#9, #39-#43 | 🔄 Audit gaps open |
| 1.5 | Live Server Testing | [#2](https://github.com/MWBMPartners/CueRCode/milestone/2) | #13, #17, #18, #22 | ⬜ Pending |
| 2 | User Accounts & Organisations | [#3](https://github.com/MWBMPartners/CueRCode/milestone/3) | #11, #12, #14-#16, #19-#21, #23-#24 | 📋 Planned |
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
| 2 | Database Schema & SQL Installation | [#2](https://github.com/MWBMPartners/CueRCode/issues/2) | ⚠️ Stubs for tblUsers/tblOrganisations (Phase 2); 3 missing QR seed rows added (cycle 6) |
| 3 | Core PHP Framework | [#3](https://github.com/MWBMPartners/CueRCode/issues/3) | ⬜ Needs live server verification |
| 4 | QR Code Generation Engine | [#4](https://github.com/MWBMPartners/CueRCode/issues/4) | ⚠️ 24/24 QR types now in code (cycle 6); JPEG XL missing (host-dependent); needs live-server verification |
| 5 | Responsive Web UI | [#5](https://github.com/MWBMPartners/CueRCode/issues/5) | ⚠️ No colourblind mode; no i18n; no cookie consent |
| 6 | REST API | [#6](https://github.com/MWBMPartners/CueRCode/issues/6) | ⬜ Needs live server verification |
| 7 | Testing, Documentation & Polish | [#7](https://github.com/MWBMPartners/CueRCode/issues/7) | ⚠️ Branding PNGs; closing PHP tags; no screen reader testing |
| - | Project Rebrand (QRmy.Link -> CueRCode) | [#8](https://github.com/MWBMPartners/CueRCode/issues/8) | ✅ Complete (closed) |
| - | WCAG 2.1 AA Accessibility | [#9](https://github.com/MWBMPartners/CueRCode/issues/9) | ⚠️ Good ARIA foundation but no colourblind toggle or screen reader testing |

**Audit gap issues:**

| Issue | Gap | Priority | Autopilot Status |
| --- | --- | --- | --- |
| [#39](https://github.com/MWBMPartners/CueRCode/issues/39) | Missing QR types: Facebook Messenger, Instagram, Rating/Review | Medium | ✅ Addressed in code (cycle 6, 2026-06-29) — builders + seed rows added; needs live-server verification (#17) |
| [#40](https://github.com/MWBMPartners/CueRCode/issues/40) | JPEG XL output format support | Low (host-dependent) | ⬜ Queued — host limitation (GD/Dreamhost); document + WEBP fallback recommended |
| [#41](https://github.com/MWBMPartners/CueRCode/issues/41) | Remove closing PHP tags from include files | Low | ✅ Complete (verified clean) |
| [#42](https://github.com/MWBMPartners/CueRCode/issues/42) | Implement colourblind-friendly UI toggle | Medium | ⬜ Not started (FG-023, auto-bucket) |
| [#43](https://github.com/MWBMPartners/CueRCode/issues/43) | Export branding PNGs from SVGs | Low | ⬜ Not started (FG-024, auto-bucket) |

**Deliverables so far:** 29 PHP files, 3 JS files, 1 CSS file (1,301 lines), 25 SVG logo assets, 10 database tables, 21 QR code types, OpenAPI 3.0 spec, Swagger UI, PWA service worker, offline fallback page.

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
| [#11](https://github.com/MWBMPartners/CueRCode/issues/11) | Database schema: User accounts, organisations, and roles tables | 📋 |
| [#12](https://github.com/MWBMPartners/CueRCode/issues/12) | User registration with email verification | 📋 |
| [#14](https://github.com/MWBMPartners/CueRCode/issues/14) | User login, logout, and session management | 📋 |
| [#15](https://github.com/MWBMPartners/CueRCode/issues/15) | Password reset flow | 📋 |
| [#16](https://github.com/MWBMPartners/CueRCode/issues/16) | WebAuthn/Passkey support (Windows Hello, Touch ID, Face ID) | 📋 |
| [#19](https://github.com/MWBMPartners/CueRCode/issues/19) | OAuth SSO: Microsoft 365 login | 📋 |
| [#20](https://github.com/MWBMPartners/CueRCode/issues/20) | OAuth SSO: Google Workspace login | 📋 |
| [#21](https://github.com/MWBMPartners/CueRCode/issues/21) | Organisation creation and management | 📋 |
| [#23](https://github.com/MWBMPartners/CueRCode/issues/23) | User profile management and avatar support | 📋 |
| [#24](https://github.com/MWBMPartners/CueRCode/issues/24) | Saved QR codes and user QR code library | 📋 |

**Recommended build order:** #11 -> #12 -> #14 -> #15 -> #23 -> #16 -> #19 -> #20 -> #21 -> #24

---

## 📋 Phase 3: Premium Features & Payments

**Milestone:** [Phase 3: Premium Features & Payments](https://github.com/MWBMPartners/CueRCode/milestone/4)

| Issue | Task | Status |
| --- | --- | --- |
| [#25](https://github.com/MWBMPartners/CueRCode/issues/25) | Subscription tiers and premium feature gating | 📋 |
| [#26](https://github.com/MWBMPartners/CueRCode/issues/26) | PayPal payment integration | 📋 |

---

## 📋 Phase 4: Integrations

**Milestone:** [Phase 4: Integrations](https://github.com/MWBMPartners/CueRCode/milestone/5)

| Issue | Task | Status |
| --- | --- | --- |
| [#27](https://github.com/MWBMPartners/CueRCode/issues/27) | Go2My.Link URL shortener integration (dynamic QR codes) | 📋 |
| [#28](https://github.com/MWBMPartners/CueRCode/issues/28) | SIGNula account linking and SSO integration | 📋 |
| [#29](https://github.com/MWBMPartners/CueRCode/issues/29) | Microsoft Graph API email integration | 📋 |
| [#30](https://github.com/MWBMPartners/CueRCode/issues/30) | Google Workspace email integration (future-ready) | 📋 |

**Note:** #29 (email) should be prioritised early as it's needed by Phase 2 (registration, password reset, invitations).

---

## 📋 Phase 5: Advanced Features & Polish

**Milestone:** [Phase 5: Advanced Features & Polish](https://github.com/MWBMPartners/CueRCode/milestone/6)

| Issue | Task | Status |
| --- | --- | --- |
| [#31](https://github.com/MWBMPartners/CueRCode/issues/31) | Admin dashboard: Global Admin control panel | 📋 |
| [#32](https://github.com/MWBMPartners/CueRCode/issues/32) | Bulk QR code generation | 📋 |
| [#33](https://github.com/MWBMPartners/CueRCode/issues/33) | QR code analytics and scan tracking | 📋 |
| [#34](https://github.com/MWBMPartners/CueRCode/issues/34) | Internationalisation (i18n) and translation framework | 📋 |

---

## 📋 Phase 6: CI/CD, Legal & Compliance

**Milestone:** [Phase 6: CI/CD, Legal & Compliance](https://github.com/MWBMPartners/CueRCode/milestone/7)

| Issue | Task | Status |
| --- | --- | --- |
| [#35](https://github.com/MWBMPartners/CueRCode/issues/35) | GitHub Actions: automated version numbering and CHANGELOG | 📋 |
| [#36](https://github.com/MWBMPartners/CueRCode/issues/36) | GitHub Actions: auto-deploy via SFTP to Dreamhost | 📋 |
| [#37](https://github.com/MWBMPartners/CueRCode/issues/37) | Comprehensive data protection and privacy compliance | 🔄 In progress — cookie consent banner (B-003) still needed; legal-page content addressed in code |
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
