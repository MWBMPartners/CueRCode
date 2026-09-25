---
name: Model routing + cross-AI review
description: Opus (sequential) for deep analysis/planning; Sonnet/Haiku to build (Opus if complex); Codex reviews Claude's work until clean
type: feedback
---

- Deep analysis + deep planning: Opus agents, one after another (not in parallel). Agent: `deep-architect`.
- Implementation: Sonnet (`implementer`) or Haiku (`quick-edits`); Opus if complex.
- Review: Codex reviews, issues auto-fixed, Codex re-reviews until nothing found (and vice versa — Codex-built work gets a Claude review).
- If an AI tool is unavailable/out of credits, fall back to another, switch back often, then do a FULL review.

**Why:** Owner directive 2026-09-23 (#108): latest Opus is cheaper than and at least as good as latest Fable; aim is GIRFT (Get It Right First Time). Replaces the 2026-07-22 "Fable plans" routing.

**How to apply:** Every task. The Codex CLI is NOT installed in the cloud container — log owed Codex reviews in HANDOFF §0 and run them locally.

**Codex allowance (measured 2026-09-25):** one Codex review of a whole multi-day branch used up the entire allowance in about 3 minutes of file reading and produced no findings. Split big reviews into chunks (one per reset, most important first), name the exact functions or steps to read, forbid printing whole files or diffs, and ask for each finding to be written the moment it is found, so an interrupted run still leaves something. The chunk briefs live in `.claude/plans/codex-review-chunk-*.md`.

**Codex out of allowance (owner decision 2026-09-25):** review in small pieces and retry after each reset; if a piece is still stuck, a separate Opus reviewer fills in (recorded as a stand-in, not the usual cross-system review) and Codex re-checks it later. Don't buy more allowance without asking.
