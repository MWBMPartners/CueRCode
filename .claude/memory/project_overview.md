---
name: CueRCode project overview
description: CueRCode QR code generation service - Phase 1 complete (7 stages), now entering Phase 2 (User Accounts & Organisations)
type: project
---

CueRCode is a comprehensive QR code generation service with web UI and RESTful API. Primary domain: CueRCode.net. Previously named QRmy.Link, rebranded to CueRCode on 2026-02-27.

**Phase 1 (Core QR Engine) is COMPLETE** with 7 stages:
1. Project Foundation & Directory Structure
2. Database Schema & SQL Installation (10 tables, 52 settings, 21 QR types, 25 logos)
3. Core PHP Framework (DB, encryption, config, error handler, CSRF, validation, etc.)
4. QR Code Generation Engine (21 types, all output formats, customisation)
5. Responsive Web UI (Bootstrap 5, dark/light mode, AJAX preview)
6. REST API (generate, preview, download, types, formats, health endpoints + OpenAPI/Swagger)
7. Testing, Documentation & Polish (PWA, offline page, WCAG accessibility, security review)

**Why:** MWBM Partners Ltd wants a flexible, self-hosted QR code platform.

**How to apply:** Next work should focus on Phase 2 (User Accounts & Organisations) or remaining Phase 1 items that require live server testing. Key constraints: no Composer (Dreamhost shared hosting), MySQLi only with prepared statements, PHP predefined constants for platform neutrality.
