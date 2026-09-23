# 🤖 .OpenAI/ — Codex (OpenAI) memory & context for CueRCode

Copyright (c) 2025-2026 MWBM Partners Ltd

This folder is the Codex equivalent of `.claude/`. It exists so Codex (or any
other AI tool) can pick up CueRCode work with the same context Claude Code has
— part of the owner's **AI-tool fallback** rule (see `.claude/CLAUDE.md`).

| File | What it holds |
| --- | --- |
| `CONTEXT.md` | What the project is, where things live, the rules every AI tool must follow |
| `MEMORY.md` | Durable lessons + owner preferences (mirrors `.claude/memory/`) |

**Single source of truth for "where are we right now":** `.claude/HANDOFF.md` §0.
Don't keep a second copy of the live state here — read and update that one file,
so Claude and Codex never disagree about the current position.

The root `AGENTS.md` (which Codex loads automatically) points here.
