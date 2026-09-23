# 🧠 CueRCode — Durable memory for Codex / OpenAI tools

Copyright (c) 2025-2026 MWBM Partners Ltd — mirrors `.claude/memory/` (2026-09-23)

- **Audit before closing issues.** Never close a GitHub issue on the strength of
  a commit message or earlier claim — check the actual code against the issue.
- **Keep AI config in the repo.** Claude → `.claude/`, Codex → `.OpenAI/`.
- **Plain English.** No jargon in feedback or explanations.
- **Push policy.** Commit AND push to `work-in-progress` after each task; never
  push other branches without permission; one PR to `alpha` later.
- **Model routing.** Deep analysis/planning: Opus agents, one after another.
  Building: Sonnet or Haiku (Opus if complex). Reviewing: the *other* AI tool.
- **AI-tool fallback.** If one tool runs out or is down, switch to another
  without losing context, switch back often, then do a FULL review.
- **After each task:** push → update the GitHub issue → update `.claude/` and
  `.OpenAI/` → update `.claude/HANDOFF.md`.
- **House style:** full multi-line PHP, detailed comments (emojis fine), PHP
  predefined constants, MySQLi prepared statements, no layout `<table>`,
  WCAG 2.1 AA, file headers with path + copyright (2025–current year, MWBM
  Partners Ltd), all GitHub Actions jobs on `ubuntu-latest`.
- **Known trap:** the repo auto-deletes a PR's source branch when merged, so
  `work-in-progress` disappears after each promotion to `alpha` — recreate it
  from `alpha`.
- **Direct pushes to tier branches** need explicit owner permission each time.
  On 2026-09-23 the owner OK'd one-off direct fixes on `main` (#109) and
  `release-candidate` (#110) only.
- **If GitHub Actions jobs fail in ~2 seconds with no log**, GitHub isn't starting
  them (billing/minutes), and the code isn't at fault. Run the same checks
  locally (actionlint, shellcheck, gitleaks, `php -l`) and flag it to the owner.
  This happened from ~20:20 UTC 2026-09-23.
