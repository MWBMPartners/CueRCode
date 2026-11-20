# AGENTS.md — CueRCode

Instructions for Codex and other AI coding agents. Full detail lives in:

- `.OpenAI/CONTEXT.md` and `.OpenAI/MEMORY.md` — Codex context and memory
- `.claude/CLAUDE.md` — code standards and **Standing Rules** (apply to every AI tool)
- `.claude/HANDOFF.md` §0 — **current position and next steps (read first)**

Key rules: plain-English feedback; commit and push only to `work-in-progress`;
no stacked PRs; keep `.claude/HANDOFF.md` up to the minute; cross-review with
the other AI tool until no issues remain; set a watchdog on anything that
finishes later (CI, merges, deploys, background work) and don't move on until
it has really finished (#116); ask the owner every decision as a proper
question (options described, recommendation first, and why).
Commercial rules (tiers, prices, discounts, trials, lifetime deals, PAYG, VAT)
are always Global-Admin-editable data, never hard-coded.
Before any alpha PR (and after page/CSS/JS changes) run `php tools/check_site_polish.php`
(0 problems) plus a real-browser pass at several screen sizes - no "AI-made" tells (#137).

## Model tiers for every task (all AI tools) — decision 0001

Applies to Claude, Codex, Ollama, Kimi, Grok, Gemini and any other tool. Full record: `docs/decisions/0001-model-tiers-for-gather-plan-build.md`.

1. **Gather information:** Sonnet-class or Haiku-class model, **medium** effort.
2. **Deep planning and analysis:** Opus-class (strongest) model, **high** effort, one run after another.
3. **Implement:** Sonnet-class or Haiku-class model, **medium** effort.
4. Checks and reviews use the strongest tier. Do not switch model part-way through a task.

## Supported PHP versions — decisions 0002, 0004 and 0006

PHP 8.4, 8.5 and 8.6 are all supported and checked by CI (`ci.yml`: lint and tests) on pull requests into `main`, `release-candidate`, `beta` and `alpha`. Before a deploy, `deploy.yml` runs lint and the compatibility scanner only; the test suite is not run there. Full record: `docs/decisions/0002-php-support-matrix-8-4-to-8-6.md`. Use 8.6-only features on 8.6 only where safe, behind a test that the feature exists, with the old code kept for 8.4/8.5 (`docs/decisions/0004-adopt-php-8-6-features-where-safe.md`; what was adopted and skipped: `docs/decisions/0006-php-8-6-adoption-what-and-where.md`; 0003 is superseded). Use `cuercodeClampInt()` from `_functions/fn_compat.php`, never a global `clamp()`. Run `php tools/php86-compat-scan.php` before finishing PHP work.

Search `docs/decisions/` before asking the owner any question.
