---
name: CueRCode project overview
description: CueRCode QR code generation service - Phase 1 complete (7 stages), 38 GitHub Issues across 7 milestones, entering Phase 1.5 (live server testing)
type: project
---

# CueRCode Project Overview

CueRCode is a comprehensive QR code generation service with web UI and RESTful API. Primary domain: CueRCode.net. Previously named QRmy.Link, rebranded to CueRCode on 2026-02-27.

**Phase 1 (Core QR Engine) is COMPLETE** - 29 PHP files, 3 JS files, 1 CSS file, 10 DB tables, 21 QR types, OpenAPI spec, PWA. All in commit `73fc61e`.

**Current phase: 1.5 (Live Server Testing)** - 4 issues (#13, #17, #18, #22) requiring Dreamhost server deployment.

**GitHub Project Management (as of 2026-03-23):**

- 7 Milestones (Phase 1 closed, Phases 1.5-6 open)
- 38 Issues total (10 closed, 28 open)
- 20+ labels for categorisation
- Clean ProjectBrief at `.claude/ProjectBrief.claude`

**Phase roadmap:** 1.5 (Server Testing) -> 2 (User Accounts, 10 issues) -> 3 (Payments, 2 issues) -> 4 (Integrations, 4 issues) -> 5 (Advanced Features, 4 issues) -> 6 (CI/CD & Legal, 4 issues)

**Why:** MWBM Partners Ltd wants a flexible, self-hosted QR code platform.

**How to apply:** Check PROJECT_STATUS.md for current phase progress. Key constraints: no Composer (Dreamhost shared hosting), MySQLi only with prepared statements, PHP predefined constants for platform neutrality. Email integration (#29) should be prioritised alongside Phase 2 as it's needed for registration/password reset.
