# 0001 — Which AI model tier does which stage of a task

- **Date:** 2026-11-18
- **Question (plain words):** For a task here, which strength of AI model should gather facts, which should plan, and which should write the code?
- **Answer:**
  1. **Gather information** (reading docs, searching the code, listing facts): a Sonnet-class or Haiku-class model, at **medium** effort.
  2. **Deep planning and analysis** (deciding the approach, weighing risks): an Opus-class model, at **high** effort, run one after another, not in parallel.
  3. **Implementation** (writing the changes): a Sonnet-class or Haiku-class model, at **medium** effort.
  4. Checks and reviews stay on the strongest tier (Opus-class), per the owner's device-wide rule.
- **Applies to:** every AI tool used on this repository (Claude, Codex, Ollama, Kimi, Grok, Gemini and any other). Names are examples; the rule is about tiers: cheap and quick to gather, strongest to plan and review, mid-tier to build. Where a tool has no effort setting, ask for the equivalent (a short answer for medium, a careful one for high).
- **Rules out:** using the strongest tier to build routine changes; using a cheap tier to plan or to review; switching model part-way through a started task.
- **Implemented in:** `AGENTS.md` (Standing Rules, "Model tiers"), `.claude/CLAUDE.md` (pointer), `.OpenAI/README.md` (pointer).
- **Supersedes:** the older "Fable plans" routing and the line in `.claude/CLAUDE.md` that left effort unspecified.
- **Issue:** #151 (first use).
