---
name: CueRCode project overview
description: CueRCode QR code generation service - Phase 1 audited with gaps, 43 GitHub Issues across 7 milestones
type: project
---

# CueRCode Project Overview

CueRCode is a comprehensive QR code generation service with web UI and RESTful API. Primary domain: CueRCode.net. Rebranded from QRmy.Link on 2026-02-27.

**Phase 1 (Core QR Engine) - AUDITED, gaps identified:**

- Bulk dev done in commit `73fc61e` (2026-02-27)
- Thorough audit conducted 2026-03-23 against revised ProjectBrief
- Only #8 (rebrand) confirmed fully complete and closed
- #1-#7, #9 reopened with audit review comments documenting specific gaps
- 5 new gap issues created: #39 (missing QR types), #40 (JPEG XL), #41 (closing PHP tags), #42 (colourblind mode), #43 (branding PNGs)

**GitHub Project (as of 2026-03-23):**

- 7 Milestones (all open except none closed yet)
- 43 Issues total (1 closed: #8 rebrand, 42 open)
- Clean ProjectBrief at `.claude/ProjectBrief.claude`

**Phase roadmap:** Fix Phase 1 gaps -> 1.5 (Server Testing) -> 2 (User Accounts, 10 issues) -> 3 (Payments) -> 4 (Integrations) -> 5 (Advanced Features) -> 6 (CI/CD & Legal)

**Key constraint:** Email integration (#29) should be prioritised alongside Phase 2 as it's needed for registration/password reset.

**How to apply:** Always audit issues against codebase before closing. Check PROJECT_STATUS.md for current status. Never assume prior completion claims are accurate.
