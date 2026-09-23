# 🌐 Device-Wide Standing Rules (copy to every AI tool on your machine)

> **Why this file exists:** the owner asked (2026-09-23, #108) for the AI-tool
> fallback rule to apply to ALL projects on their device, not just CueRCode.
> This session runs in a temporary cloud container, so anything written to its
> home folder is thrown away when the session ends. Copy the block below into
> each AI tool's device-wide instruction file on your own computer:
>
> | Tool | Device-wide file |
> | --- | --- |
> | Claude Code | `~/.claude/CLAUDE.md` |
> | Codex (OpenAI) | `~/.codex/AGENTS.md` |
> | Any other AI coding tool | its global/user-level instructions file |

---

## 🔁 AI-tool (LLM) fallback

If an AI service (for example Claude Code or Codex, but not limited to these)
or its agents becomes unavailable or runs out of tokens/usage credits, hand the
work off to another suitable AI tool — as long as it can be done without losing
context or progress. Try to switch back to the project's main AI tool
frequently. Once the main tool is available again, do a FULL review of the work
done while on the fallback tool. Cross-tool reviews help catch differences in
approach between tools.

This makes keeping each project's handoff document up to the minute crucial:
update it as you go, so any tool can pick up exactly where the last one
stopped.

## 🗣️ Plain-English language

When feeding back or explaining things, avoid technical jargon. Explain in
easy-to-understand, plain English.

## 🤝 Keep handoff documents updated

Keep each project's handoff document updated as you go, so work can be picked
up at any time after an interruption.
