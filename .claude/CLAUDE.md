# CueRCode - Project Instructions

## Project Overview

CueRCode is a web-based QR Code generation service with a responsive web UI and RESTful API. Built with PHP 8.4+/MySQL, hosted on Dreamhost Shared Hosting. Primary domain: CueRCode.net. Secondary: CueRCode.me. Tertiary: QRmy.Link.

## Memory

All Claude memory, context, and configuration should be stored in this `.claude/` directory within the repo.
Codex/OpenAI memory and context live in `.OpenAI/` (root `AGENTS.md` points Codex there).

> 🔁 **Resuming from a fresh session?** Read `.claude/HANDOFF.md` §0 FIRST — it holds the current position, the task queue, and the cold-start steps.

## Standing Instructions

These instructions MUST be followed for EVERY prompt/action:

### Code Standards

- PHP: Full multi-line notation (no shorthand), detailed inline comments, use PHP predefined constants (DIRECTORY_SEPARATOR, PHP_EOL, etc.)
- PHP: Target PHP 8.5 with backward compatibility to PHP 8.4
- SQL: MySQLi only, prepared statements for ALL queries
- HTML5: Fully compliant, responsive, no `<table>` tags for layout
- CSS3+: Modern CSS with graceful fallbacks
- All code fully commented/annotated with inline comments and comment blocks (emojis welcome in comments)
- File headers must include: file path, copyright (2025-current year, MWBM Partners Ltd)
- Sensitive data encrypted with salt before database storage (AES-256-GCM with HKDF)
- All user inputs sanitised to prevent injections

### File Structure

- `web/CueRCode.net/` - Main site files
- `web/CueRCode.net/public_html/` - Production site root
- `web/CueRCode.net/public_html_dev_alpha/` - Alpha development build
- `web/CueRCode.net/public_html_dev_beta/` - Beta development build
- `web/CueRCode.net/public_html_landing/` - Public landing/coming soon page
- `web/CueRCode.net/public_html_redir/` - Redirect placeholder
- `web/CueRCode.net/_auth_keys/` - Site-specific auth credentials (non-web)
- `web/CueRCode.net/_includes/` - Site-specific includes (non-web)
- `web/CueRCode.net/_functions/` - Site-specific functions (non-web)
- `web/CueRCode.net/_libraries/` - Third-party PHP libraries (non-web)
- `web/CueRCode.net/_uploads/` - User uploads (non-web)
- `web/CueRCode.net/_backups/` - Backups (non-web)
- `web/_auth_keys/`, `web/_includes/`, etc. - Server-wide shared equivalents

### Per-Prompt Checklist

1. Create detailed GitHub Issue for the task (description, scope, acceptance criteria)
2. Implement the work with full comments/annotations
3. Run syntax, lint, and security checks - fix ALL issues until zero remain
4. Update GitHub Issue with status, close if complete, link commit numbers
5. Update: CHANGELOG.md, DEV_NOTES.md, README.md, PROJECT_STATUS.md
6. Update `.claude/` memory and context, `.OpenAI/` memory and context, and `.claude/HANDOFF.md`
7. COMMIT **and PUSH** to `work-in-progress` (owner directive 2026-09-23 — replaces the old "do not push" rule). Never push to any other branch without explicit permission.

### Libraries (CDN-first with local fallback)

- Bootstrap 5.3.3, FontAwesome 6.5.1, jQuery 3.7.1
- PHP: chillerlan/php-qrcode v5.0.5, php-settings-container v3.2.1, FPDF v1.86
- PHP: tecnickcom/tc-lib-barcode v2.12.0 + tc-lib-color v2.13.1 (non-QR 1D/2D barcode generation, #69) - LGPL-3.0-or-later; PDF417's numeric-compaction encode mode requires the `bcmath` PHP extension (verify present on the production host)
- Assets: Twemoji (jdecked/twemoji) v17.0.3 (emoji centre-icon picker, #67) - CC-BY-4.0, attribution REQUIRED (`/attributions` page, footer-linked); curated ~400-emoji starter set committed under `public_html/assets/img/emoji/`, NOT gitignored (unlike the QR/barcode PHP libraries above)
- Swagger UI (swagger-ui-dist) v5.33.0 (API docs page `/api/docs/`, #111) - Apache-2.0 (SmartBear; credited on `/attributions`); jsDelivr pinned + SRI, with the local fallback COMMITTED under `public_html/assets/vendor/swagger-ui/` (not gitignored) so it works on shared hosting without a CDN. The site CSP (`public_html/.htaccess`) only allows scripts/styles from self + jsDelivr (+ cdnjs for styles) - never load a library from any other CDN without updating the CSP
- Graphics: SVG-first with PNG/APNG fallback
- No Composer dependency - all PHP libraries manually downloaded/hosted

### UI Requirements

- Dark/Light mode (system default, manual toggle)
- Colourblind-friendly option
- WCAG 2.1 AA accessibility compliant
- AJAX with graceful JS-disabled fallback
- Bot protection: Cloudflare Turnstile > reCAPTCHA > hCaptcha (based on available API keys)

### CI/CD & GitHub Actions

- **Runners:** ALL GitHub Actions workflow jobs MUST use `runs-on: ubuntu-latest` — including the SFTP deploy (`.github/workflows/deploy.yml`) and any future deploy/build/test workflows. Do NOT introduce macOS, Windows, or self-hosted runners without an explicit, documented reason.
- **Branch cadence:** four-tier `work-in-progress → alpha → beta → release-candidate → main` (see DEV_NOTES.md "Branch Model & CI/CD Cadence"). Scanning, Dependabot, and security-fix propagation cover all four tier branches.

## Standing Rules (owner directive, revised 2026-09-23, #108)

These apply to EVERY session and EVERY task, alongside everything above. Nothing earlier in this file has been removed.

### 🗣️ Plain-English language
When feeding back or explaining anything, avoid technical jargon. Explain in easy, plain English — even technically skilled readers can find jargon confusing. If a technical term can't be avoided, explain it in a few words.

### 🤝 Keep the handoff document updated — as you go
Update `.claude/HANDOFF.md` continuously (not only at the end), so work can be picked up at any moment if the session is interrupted, restarted, or handed to another AI tool. Keep its §0 "LATEST" section accurate and up to the minute.

### 🧠 Processing & analysis
- Think hard ("ultrathink") about the work required, and use workflows to help plan and do the work.
- **Deep analysis and deep planning:** Opus agents, run **one after another (sequential, not parallel)**. Reason: the latest Opus is cheaper than, and at least as good as, the latest Fable at time of writing. (Replaces the older "Fable plans" routing.)
- **Implementation:** Sonnet or Haiku, whichever fits best. If the implementation is complex, use Opus.
- Philosophy: use tokens/credits efficiently while still producing top-quality, correct code — **GIRFT (Get It Right First Time)**.

### 🔌 Use plugins to help
Use the dev-team plugins freely for any of this work, and for suggesting fixes, tweaks, enhancements and new features. Use them to bring in a different AI system to check work: plan and build with Claude Code, then check/review with Codex (and vice versa).

### ✅ Steps after EACH task
1. Commit and push to `work-in-progress` (the integration branch that later goes to `alpha`), and update that task's GitHub issue(s) — one update per task.
2. Update Claude memory and context in `.claude/`.
3. Update OpenAI/Codex memory and context in `.OpenAI/`.
4. Update the handoff document (`.claude/HANDOFF.md`).

### ⏱️ Watchdog for anything that finishes later (standing task, #116)
Some steps don't finish straight away: CI (automatic checks) runs, pull-request merges, deploys, Dependabot and security-propagation runs, helper agents or commands running in the background, and long builds or test runs. **Every time a step like that starts, set up a "watchdog" — something that waits for it to finish and then checks the result — and don't start any later step that depends on it until the watchdog says it's done.** Queued tasks that don't depend on it can carry on in the meantime.
- **"Finished" includes going wrong.** Failed, cancelled, timed out, skipped, or never started all count as finished-with-a-problem. Silence is not success: a watchdog that only listens for "passed" isn't a watchdog.
- **Check the whole result, not just the headline.** A run can say "success" and still have side effects (issues it opened, PRs it created, branches it deleted). Wait until those have had time to appear, then look for them properly. (Lesson from 2026-08-04: a propagation run was reported as "did nothing" after a too-early, too-narrow check. It had actually opened false-alarm issues #93–#95.)
- **If it went wrong:** work out why, fix it, start it again, and set the watchdog again. Only then move down the queue.
- **How to watch (Claude Code):**
  - Background helper agents or commands → start them in the background and wait for their "finished" notification (`run_in_background`, or the `Monitor` tool for a stream of updates). Never guess or assume the result.
  - GitHub checks on a PR or branch → keep re-checking the check runs until **every** check shows as completed. GitHub's automatic notifications report failures but **not** successes, so don't wait for a "passed" message. Also subscribe to the PR's activity to hear about failures and review comments.
  - Anything that could take a while or outlast this session → schedule a self check-in (`send_later`) as a backstop, so the session wakes up and carries on with the queue even if nothing else prompts it.
  - After a merge, the watchdog also covers what happens next: the repo auto-deletes the PR's source branch (recreate `work-in-progress` from `alpha`), and version-bump/deploy runs start.
- **Known trap:** if GitHub Actions jobs "fail" in about 2 seconds with no log, GitHub didn't start them (usually billing or used-up minutes); the code isn't at fault. The watchdog must report that as **blocked**, not done, and must not let dependent steps go ahead as if the checks were green (for example, the `work-in-progress → alpha` PR). Run the same checks locally and tell the owner.
- **Never leave anything running unwatched.** Before ending a turn or handing over, make sure nothing is still running without a watchdog. Write down anything still in progress, and how it's being watched, in `.claude/HANDOFF.md` §0 so a fresh session can take over the watch.
- A tool-agnostic copy of this rule is in `.claude/DEVICE_WIDE_RULES.md`.

### 📚 Thorough documentation update (standing task)
Keep ALL documentation current: every `.md` file, in-app help, guides and the developer portal, and all Claude info in `.claude/`. Update the OpenAPI/Swagger spec (`web/CueRCode.net/public_html/api/v1/openapi.json`) for every API change. Swagger UI is already included (`/api/docs/`) — it must work on shared hosting with no Docker, which means it needs a **local copy of Swagger UI as a fallback** when the CDN can't be reached (still outstanding — see #103 item 2d).

### ⚡ Efficient/smart processing
Re-order and bundle the above steps as sensible to work efficiently.

### 🤖 Autonomy
Work through everything autonomously. Only stop when an EXPLICIT decision or approval is needed from the owner — say clearly, in simple words, what is needed and why. Raise all questions/decisions **up front**, not as they crop up. After asking, carry on with ALL other queued tasks.
- **Ask decisions as proper questions (owner request, 2026-09-25):** put every decision or clarification to the owner as a real question (use the question tool, in batches), not buried in prose. Describe each option in plain English, put the **recommended option first**, and say **why** it is recommended, so the owner can simply pick an answer. Record every answer straight away in `.claude/HANDOFF.md` §0 (and `LAUNCH_DECISIONS.md` where relevant).

### 📊 Progress updates
Give frequent progress updates as a **table of queued tasks with the status of each**.

### 🔍 Code review loop
All code goes through review by Codex. Issues it finds get fixed automatically, then Codex reviews again — repeat until it finds nothing. (If Codex isn't available in the current environment, e.g. a cloud container without the Codex CLI, record the review as owed in the handoff and run it at the next opportunity.)
- **When Codex runs out of allowance (owner decision, 2026-09-25):** split the review into small pieces and retry each after the allowance resets. If a piece is still stuck, a separate Opus reviewer fills in — recorded as a *stand-in*, not the usual cross-system review — and Codex re-checks that piece later. Don't buy extra allowance without asking the owner.

### 🚫 No PR stacking
Don't create multiple pull requests. Commit everything to `work-in-progress`; ONE pull request `work-in-progress → alpha` gets created later. This avoids merge race conditions between PRs. (Dependabot's own PRs are the exception — they're automatic.)

### 🔁 AI-tool (LLM) fallback
If an AI service (Claude Code, Codex, or any other) or its agents becomes unavailable or runs out of tokens/credits, hand the work to another suitable AI tool — as long as that can be done without losing context or progress. Switch back to the main tool for the project (Claude Code here) as often as possible. Once the main tool is available again, do a FULL review of work done by the fallback tool. The cross-tool reviews help catch differences in approach. This is why the handoff document must always be up to the minute. This rule is deliberately tool-agnostic. A device-wide copy of this rule is in `.claude/DEVICE_WIDE_RULES.md`.
