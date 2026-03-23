---
name: Always audit before closing issues
description: Never close GitHub Issues without verifying each one against the actual codebase and project brief first
type: feedback
---

Never close GitHub Issues as complete without first auditing them against the actual codebase and project brief. Do not assume prior claims of completion are accurate.

**Why:** In the first session, all 9 Phase 1 issues were blindly closed as "complete" without checking the code. A thorough audit later revealed multiple gaps (missing QR types, missing JPEG XL, no colourblind mode, closing PHP tags, missing branding PNGs). The user correctly flagged this as wrong.

**How to apply:** Before closing ANY issue, read the issue description, check the codebase for the claimed deliverables, and compare against the current ProjectBrief. Create new issues for any gaps found rather than ignoring them. Only close an issue when every acceptance criterion is verifiably met.
