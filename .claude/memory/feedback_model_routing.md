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
