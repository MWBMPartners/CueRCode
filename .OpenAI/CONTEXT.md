# 🧭 CueRCode — Context for Codex / OpenAI tools

Copyright (c) 2025-2026 MWBM Partners Ltd — last updated 2026-09-23 (#108)

## Read first
1. `.claude/HANDOFF.md` §0 — current position, task queue, cold-start steps.
2. `.claude/CLAUDE.md` — code standards, file layout, libraries, and the
   **Standing Rules** (they apply to Codex exactly as they apply to Claude).

## Project in one paragraph
CueRCode is a QR-code (and barcode) generator website plus REST API, written in
PHP 8.4/8.5 with MySQL (MySQLi prepared statements only), hosted on Dreamhost
shared hosting (no Docker, no Composer — libraries are downloaded and hosted
manually). Domains: CueRCode.net (main), CueRCode.me, QRmy.Link (used for
dynamic-redirect links). The API spec is
`web/CueRCode.net/public_html/api/v1/openapi.json`, browsable at `/api/docs/`.

## Branches
`work-in-progress → alpha → beta → release-candidate → main`.
All work is committed and pushed to **`work-in-progress`** only. One pull
request `work-in-progress → alpha` is opened later. No extra/stacked PRs.

## Checks to run before committing
- `find web tests -name '*.php' -print0 | xargs -0 -n1 php -l` — no errors
- `php tests/run.php` — all pass (needs the gitignored libraries under
  `web/CueRCode.net/_libraries/`; CI downloads them automatically)

## Codex's role in the review loop
Claude Code plans and builds → **Codex reviews** → issues fixed → Codex reviews
again until nothing is found. When Codex builds (fallback), Claude does a FULL
review once it's available again. Record every review outcome in
`.claude/HANDOFF.md` §0.

## Owner preferences (short)
- Plain English, no jargon, in all feedback.
- Progress shown as a table of queued tasks with a status for each.
- Work autonomously; ask questions up front; keep the handoff up to the minute.
- GIRFT — Get It Right First Time.
