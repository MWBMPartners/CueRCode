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
- **Watchdog (2026-09-24, #116).** Anything that finishes later (CI runs, PR
  merges, deploys, background agents/commands, long builds or tests) gets a
  watchdog: wait for it to finish and check the result, including side
  effects like issues or PRs it opened, before starting any step that depends
  on it. Failed, cancelled, timed out or never started all count as a problem;
  silence is not success. Re-check GitHub check runs until every one is
  complete, because notifications don't announce success. Never leave
  anything running unwatched; record in-flight work in `.claude/HANDOFF.md`
  §0. **Trap (2026-09-25):** `watchdog.sh quiet` reads a file's age without
  following symbolic links (shortcuts). Point it at a shortcut and it always
  reports "unchanged", even while the work is busy. Point it at the real file
  instead (`ls -la` shows where a shortcut leads). The fix is `-L` on both
  `stat` calls in the script — approved by the owner 2026-09-25 (Mac session to apply).
- **GitHub Actions shell (2026-09-25, #124).** A step with no `shell:` runs as
  `bash -e {0}`, WITHOUT `pipefail`; only `shell: bash` adds `-o pipefail`.
  Test `run:` scripts locally with the matching flags, and confirm any claim
  about shell behaviour in a real run log. A wrong claim about this went into
  commit 5e2005c's message; the CHANGELOG carries the correction.
- **Codex allowance (2026-09-25).** A whole-branch review ran out of allowance in about 3 minutes, before writing any finding. Review in chunks (`.claude/plans/codex-review-chunk-*.md`), read only the named functions, and write each finding as soon as it is found. **Owner decision (2026-09-25 pm):** keep reviewing in small pieces and retry after each reset; if a piece is still stuck, an Opus reviewer fills in as a recorded *stand-in*, and Codex re-checks that piece later. No extra spend without asking.
- **Ask decisions as proper questions (2026-09-25).** Every owner decision goes to the owner as a real question: options described in plain English, the recommended one first, and why. Record answers at once in `.claude/HANDOFF.md` §0.
- **Dynamic + dual-function QR approved for alpha (2026-09-25).** Next big task (HANDOFF task 7j). Must reuse Go2My.Link's short-code system on the QRmy.Link address; link checks: Google Web Risk, then Safe Browsing, then pluggable providers (e.g. Microsoft).
- **Pricing page allow-list (#125, 2026-09-25):** `/pricing` only shows plan features/limits listed in `pricingAllowedDisplayKeys()` (`_functions/fn_pricing.php`), each with a label and a status (available / coming soon). A new plan key stays hidden until it's added there on purpose. When dynamic QR ships (HANDOFF 7j), switch `dynamic_qr`, `dual_function_qr`, `dynamic_codes` (and any other newly built ones) to available.
- **Flexibility in everything commercial (owner directive, 2026-10-05):** every tier and pricing mechanism must be highly flexible and configurable by a **Global Admin** — plans, features and limits per plan, prices in any currency and billing period, VAT display, discounts and codes, lifetime deals, trials, pay-as-you-go, price promises. Today's plans may not be the plans in 12 months or 5 years: **never hard-code a commercial rule in code**; store it as admin-editable data, with sensible defaults.
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
- **Site CSP** (`public_html/.htaccess`) only allows scripts from self +
  jsDelivr (styles also cdnjs). Never load a library from another CDN (e.g.
  unpkg) without updating the CSP. That exact mistake left `/api/docs/` blank
  on the live server until #111.
- **Issue audit 2026-09-23:** `.claude/ISSUE_AUDIT_2026-09-23.md` is the latest
  code-verified status of every issue. Top open bug: **#112** — the email queue is
  never sent, so sign-ups can't be verified. Follow-ups: #113 deploy/setup,
  #114 accounts, #115 polish.
- **Writer agents and the handoff:** when delegating doc edits, give writers an
  explicit file allow-list and tell them the handoff's task numbers aren't theirs.
  On 2026-09-23 two writers misread "tasks 5-6" in HANDOFF, skipped most of their
  work and edited HANDOFF against instructions.
