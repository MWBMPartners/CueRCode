# 🧾 AI instruction audit — 2026-10-05 (#136)

> Copyright (c) 2025-2026 MWBM Partners Ltd. Report only. **No instruction file
> has been changed.** The owner decides what to apply.

## 0. What was checked, against what

**Anthropic sources, fetched live on 2026-10-05** (not from memory):

| Short name | Page |
| --- | --- |
| **BP** | Prompting best practices — `platform.claude.com/docs/en/build-with-claude/prompt-engineering/claude-prompting-best-practices` |
| **O55** | Prompting Claude Opus 5.5 (the model this session and the `deep-architect` agent run on) — `…/prompting-claude-opus-5-5` |
| **O5** | Prompting Claude Opus 5 (O55 says these patterns "remain a reasonable starting point") — `…/prompting-claude-opus-5` |
| **S55** | Prompting Claude Sonnet 5.5 (the current Sonnet; your `implementer` agent) — `…/prompting-claude-sonnet-5-5` |
| **CC-MEM** | Claude Code docs, "CLAUDE.md files" — `code.claude.com/docs/en/memory` |
| **CC-BP** | Claude Code docs, "Best practices" — `code.claude.com/docs/en/best-practices` |
| **CC-MODEL / CC-SUB** | Claude Code docs, model configuration and subagents |
| **PA** | Anthropic's prompt-audit guide, bundled with Claude Code 2.1.289 (what `/doctor prompt-audit` runs) |

**NOT RUN:** the three blog posts you linked (`claude.dev/blog/getting-the-most-out-of-opus-5-5/`,
`…/building-with-claude-sonnet-5-5/`, `…/how-we-made-claude-ai-faster/`). This cloud
environment's network settings block `claude.dev`, so I could not read them and I have
not guessed what they say. To include them: add `claude.dev` to the environment's
allowed domains (cloud environment menu → Edit → Network access), or run this audit from
your Mac.

**Files in scope (811 lines).** Who reads each one matters:

| File | Lines | Who actually reads it |
| --- | --- | --- |
| `.claude/CLAUDE.md` | 139 | Claude, every session |
| `.claude/DEVICE_WIDE_RULES.md` | 67 | Claude + Codex in **every project** on your Mac, once pasted into `~/.claude/CLAUDE.md` |
| `.claude/agents/*.md` (3) | 28 | The helper agents |
| `.claude/memory/*.md` (11) | 186 | **Nobody automatically** — see finding F1 |
| `.agents/skills/prompt-polish/SKILL.md` | 88 | Claude, when the skill is used (third-party skill) |
| `AGENTS.md` | 14 | Codex only (Claude Code skips it because `.claude/CLAUDE.md` exists) |
| `.OpenAI/*.md` (3) | 124 | Codex only |
| `.claude/plans/codex-*.md` (5 saved prompts) | 165 | Codex only |

Not audited line by line: `.claude/skills/prompt-cache-control/` (a screen add-on with no
instructions to the AI), the three `ProjectBrief*.claude` files (product requirements, not
behaviour rules), `HANDOFF.md` (state, not rules — but see "document length").

**Codex-only files:** Anthropic's guidance is about Claude models, so it can't judge
prompts written for an OpenAI model. For those files I checked only facts and clashes.
Judging them against OpenAI's own prompting guide is **NOT RUN**.

---

## 1. The honest summary

**Most of your CLAUDE.md should stay.** It is 139 lines, which is under the 200-line target in
CC-MEM. Most of it is project facts and your own way of working, and AI models can't work
those out for themselves. Your proudest scaffolding mostly survives: the **watchdog** core
and the **Codex review loop** are truth and independent-check rules, and they stay.

What should go or change is smaller than you might expect, but it matters:

1. **One "think harder" line** (`ultrathink`). It sits on top of a session already set to
   `xhigh` effort, so you pay twice for extra thinking (F2, plus settings item S-1).
2. **Rules that stop work to ask you things.** "Raise all questions up front" plus "ask every
   decision or clarification through the question tool" makes the AI stop and wait on
   choices that don't block anything. The question tool **pauses the whole session** until
   you answer, so "after asking, carry on" can't happen in practice (F3).
3. **Five instructions are missing**, and current models need them: short answers, document
   length, how to update you, holding scope, and limiting helpers. Today your rules push the
   other way ("use workflows", "use the dev-team plugins freely", "Opus agents for deep
   analysis").
4. **The same rules are copied into 5–7 files.** The copies have started to disagree (F5),
   and two hold facts that are now wrong (F6, F7).
5. **Your carefully kept `.claude/memory/` folder is never loaded.** Claude Code reads its
   memory from your home folder, not from the repo (F1).
6. **Documents, not rules, are the real bloat.** `HANDOFF.md` is 210 KB, `CHANGELOG.md`
   258 KB and `DEV_NOTES.md` 153 KB. The rule "update CHANGELOG, DEV_NOTES, README and
   PROJECT_STATUS on every prompt" helps them grow.

**The count:** 811 lines in, covering **83 instructions**: **7 deletes, 19 rewrites, 54 keeps,
3 not judged (Codex-only)**, plus **6 additions** (the 5 missing instructions and the
"Blocked on me / Changed / Found" rule) and **2 settings changes** for you to decide. Section
3 has the per-file breakdown.

---

## 2. Findings by type (requests A and C)

### A1 — Lines that push the AI to think more
| Where | Your line | Effect | Smallest change |
| --- | --- | --- | --- |
| `CLAUDE.md:90` | "Think hard ("ultrathink") about the work required" | Opus 5.5 already decides how much to think. Its effort setting is the control, and this session runs at `xhigh`. A standing "think hard" adds time and cost on every task, including simple ones. The word `ultrathink` is a keyword Claude Code acts on **in a prompt you type**. Whether it fires from inside CLAUDE.md is not documented (**NOT RUN**). | Delete the clause. Type `ultrathink` in a message when one task really needs it. Set effort per session (S-1). |
| `CLAUDE.md:93`, `implementer.md:10`, `deep-architect.md:9`, `.OpenAI/CONTEXT.md:42` | "GIRFT (Get It Right First Time)" | A slogan, not a check. Models already aim to be right; this adds nothing they can act on, while a concrete "done" test does. | Replace it with the definition of done in §4 (the checks to run). Keep "prefer correctness over speed" in `deep-architect`: that is a real trade-off. |
| — | No "take a deep breath", "think step by step" or "don't overthink" anywhere | — | Nothing to do. |

### A2 — Rules that hand out work one step at a time, or don't define "done"
| Where | Your line | Effect | Smallest change |
| --- | --- | --- | --- |
| `CLAUDE.md:46-54` + `:98-102` | Two numbered checklists: "Per-**Prompt** Checklist" (7 steps) and "Steps after EACH **task**" (4 steps). They overlap, and they disagree on when they apply. | BP supports numbered steps "when the order or completeness of steps matters", and it does here, so **keep a checklist**. The problems are that a question you ask also counts as a "prompt", so it triggers an issue, four doc updates and a push; and that two lists say nearly the same thing. | Merge them into **one "Definition of done (per task)"** (exact wording in §4). |
| `CLAUDE.md:50` | "Run syntax, lint, and security checks - fix ALL issues until zero remain" | Good intent, but it doesn't name the checks, so each session guesses. CC-MEM: write "'Run `npm test` before committing' instead of 'Test your changes'". | Name them: `php -l` on changed PHP, `php tests/run.php`, `actionlint` + `shellcheck` on changed workflows, `gitleaks`, and (new, #137) `tools/check_site_polish.php`. |
| `CLAUDE.md:119` | "Keep ALL documentation current: every `.md` file, in-app help, guides and the developer portal, and all Claude info" | There is no finish line, so it invites every doc to be touched on every task, and documents grow (O5: written files "are often longer than on prior models"). | "Update the docs that describe what you changed, in the same commit; `openapi.json` for every API change." |
| `CLAUDE.md:129` | "Give frequent progress updates as a table of queued tasks" | "Frequent" isn't defined, so it either floods you or goes quiet. | Say *when* (exact wording in §4, missing instruction 3). |
| `CLAUDE.md:90`, `:96` | "use workflows to help plan and do the work"; "Use the dev-team plugins freely for any of this work" | This sends work to helpers by default. O5: delegation "multiplies cost and time when applied to small tasks". It also clashes with `:91` (Opus agents one at a time) and `:93` (use tokens efficiently). | Replace with missing instruction 5 (helper limits) in §4. |
| Codex briefs `.claude/plans/codex-*.md` | Step lists for Codex | Written for a different model, on purpose (to save Codex allowance). Not judged against Anthropic's guidance. | Keep. See stale facts F7 for two that can no longer run. |

### A3 — Rules that stop for confirmation when they don't need to
| Where | Your line | Effect | Smallest change |
| --- | --- | --- | --- |
| `CLAUDE.md:125` | "Raise all questions/decisions **up front**, not as they crop up. After asking, carry on with ALL other queued tasks." | The question tool **pauses the session** until you answer, so "carry on" can't happen in the same run. It also pushes the AI to invent questions at the start so it doesn't break the rule later. O55 names this exact stop as unwanted: "a list of decisions for the user when, by your own account, none of them blocks the rest of the work." | "Ask only when nothing can move without you. For anything else, take the recommended option if it can be undone, say so in your update, and list it under **Blocked on me** at the end." |
| `CLAUDE.md:126`, `memory/feedback_questions.md:7,11` | "put **every** decision or clarification to the owner as a real question (use the question tool, in batches)" | "Every" includes the small ones. S55: "only stop to ask when you can't go on without the user or before a risky step." | Change "every decision or clarification" to "every decision that blocks work". **Keep** your format: options described, recommended first, and why. That part is good. |
| `memory/feedback_watchdog.md:14`, `CLAUDE.md:111` | "keep re-checking the check runs until **every** check shows as completed" | This means repeated checking (polling). In cloud sessions, a PR subscription now reports results as they happen (see F6), so most of this waiting is unnecessary. | See F6. |

### Approval rules about safety, permissions or destructive actions — **for you to decide** (not changed)
| # | Rule (where) | My recommendation |
| --- | --- | --- |
| S1 | Push only to `work-in-progress`; "Never push to any other branch without explicit permission" (`CLAUDE.md:54`, memory, `.OpenAI`, `AGENTS.md`) | **Keep as is.** Only drop the history in brackets. |
| S2 | Direct pushes to `alpha`/`beta`/`release-candidate`/`main` need your OK **each time** (`.OpenAI/MEMORY.md:46`) | **Keep.** Also add it to `CLAUDE.md` — today it lives only in the Codex file. |
| S3 | One PR `work-in-progress → alpha`, opened "later"; no PR stacking (`CLAUDE.md:136`) | **Keep.** Consider saying who opens it and when ("when the queue is green and you say go"). |
| S4 | "Don't buy extra allowance without asking the owner" (`CLAUDE.md:133`) | **Keep.** It's a spending rule. |
| S5 | Never disable the default-branch guard hook (`memory/feedback_push_and_workflow.md:13`) | **Keep.** Add it to `CLAUDE.md`: memory isn't loaded (F1), so today the AI never sees it. |
| S6 | Codex briefs: "READ-ONLY: do not edit any files" | **Keep.** |
| S7 | AI-tool fallback sends the work to another AI company's tool (`CLAUDE.md:139`, device-wide) | **Keep** (your decision of 2026-09-23). Just be aware that it shares code with that company. |
| S8 | **Missing:** no rule says which actions need your OK first: deleting files or branches, force-push, dropping database tables, deploying, sending real emails, posting on other projects' issues. "Only stop when an EXPLICIT decision or approval is needed" leaves it to judgement. | **Your call.** If you want one, here is Anthropic's wording (BP, "Balancing autonomy and safety"), shortened: *"Take local, reversible actions (editing files, running tests) without asking. Ask before actions that are hard to undo or affect shared systems: deleting files or branches, force-pushing or rewriting history, dropping or changing database tables, deploying, sending emails or messages, or posting on issues and PRs outside this repo. Never use a destructive action as a shortcut past an obstacle (e.g. skipping a safety check)."* |

### Verify-twice rules (request C item 4)
- **None of your rules tell Claude to re-check its own answer** (no "double-check", "re-verify" or "final verification step").
- Your checks are *real* checks (lint, tests, CI) or *independent* reviews (Codex). Anthropic supports both: S55 wants "a real check that exercises the change", and CC-BP endorses "a second opinion… so the agent doing the work isn't the one grading it". **Keep them.**
- **Where you are paying twice is the habit, not a rule.** The handoff shows a routine of *plan Opus → build Sonnet → **verify Opus** → then Codex review* (e.g. #112, #122). That is two independent reviews of the same change. O5: "do not use subagents to verify or double-check your own work". Your rule (`CLAUDE.md:133`) already says Opus should review only as a **stand-in when Codex can't**. Following your own rule as written saves one full Opus review per task.

### "Only flag the big issues" (item 5)
- **None found** in any Claude-facing file.
- The Codex briefs say "Only real defects" but still ask for high/medium/low severity, so they filter out *false alarms*, not *small issues*. Keep.
- I also applied this in my own work: the site sweep (#137) asks its helper to "report everything… the owner filters".

### "Don't overthink" rules (item 6)
- **None found.**

### Role padding (item 7)
- **None found.** The three agent files open with one task-specific role sentence each ("You handle deep analysis…", "You implement planned work…", "You handle fast, mechanical changes"). PA keep-list: "A one-line role statement is fine." Keep.

### Stale examples (item 8)
- **`prompt-polish` skill (third-party).** It knows only Fable 5 and Opus 4.8. Typing "polish this for Opus" today applies **Opus 4.8** advice to **Opus 5.5**, and on some points that advice is now backwards. Anthropic's migration guide: Opus 5 "Delegates to subagents more readily — the opposite of Opus 4.8… any 'delegate more' guidance you added for Opus 4.8 should come out." Fix: update it from upstream (`mfarzanansari/prompt-polish`) or add an Opus 5.5 reference. Until then, treat "opus" as unsupported.
- **Dated incident stories inside rules** (e.g. "Lesson from 2026-08-04…", "#93–#95"). These are reasons, and reasons are good. BP: "Providing context or motivation behind your instructions… can help Claude better understand your goals". Keep the reason; the dates and issue numbers are optional trimming (low priority).

### Clashing or duplicated rules (your follow-up answer)
| # | Clash | Where | Fix |
| --- | --- | --- | --- |
| F5a | Swagger UI local copy is "COMMITTED" vs "still outstanding" | `CLAUDE.md:62` vs `CLAUDE.md:119` (same file!) | Delete the "still outstanding" clause; #111 finished it. |
| F5b | "Per-Prompt" checklist vs "after EACH task" | `CLAUDE.md:46` vs `:98` | One merged list (§4). |
| F5c | "use workflows… use plugins freely" vs "Opus agents one after another" vs "use tokens efficiently" | `CLAUDE.md:90`, `:96` vs `:91`, `:93` | Missing instruction 5. |
| F5d | "After asking, carry on" vs a question tool that pauses the session | `CLAUDE.md:125-126` | A3 fix. |
| F5e | Agent model pins vs the routing rule. CLAUDE.md says "Sonnet", but `implementer.md` pins `claude-sonnet-5`, the **previous** Sonnet (current is Sonnet 5.5). | `.claude/agents/implementer.md:4` | Use the alias `model: sonnet` (CC-SUB lists `sonnet`, `opus`, `haiku`), which follows the newest model automatically. Do the same for the other two (`opus`, `haiku`) so they never go stale. |
| Dups | The same rule appears in 4–7 files: plain English (7), push policy (6), watchdog (6), model routing (4), questions format (4). Today the copies *mostly* agree. | CLAUDE.md, DEVICE_WIDE_RULES, memory, AGENTS.md, .OpenAI ×2 | CC-MEM: "if two instructions contradict each other, Claude may pick one arbitrarily". Suggest **one home per rule**: project rules in `CLAUDE.md`, cross-project rules in `DEVICE_WIDE_RULES.md`. `AGENTS.md` and `.OpenAI/` then *point* to them instead of restating them. Optional; only the clashes above need fixing now. |

### Out-of-date facts (your follow-up answer)
| # | Fact | Where | What the repo shows |
| --- | --- | --- | --- |
| F1 | "Keep all Claude memory… within `.claude/`" | `CLAUDE.md:9`, `memory/feedback_local_claude.md` | Claude Code loads memory from `~/.claude/projects/<project>/memory/` unless `autoMemoryDirectory` is set (CC-MEM). `.claude/settings.json` doesn't set it, so **none of the 11 memory files load on their own**. Rules that live *only* there (the push-guard trap, the GitHub Actions shell trap, the writer-agent briefing rule) are invisible unless a session goes looking. Fix (decision S-2): set `"autoMemoryDirectory"` to the repo's `.claude/memory`, or move the 3 rules that matter into `CLAUDE.md`. |
| F6 | "GitHub's automatic notifications report failures but **not** successes" | `CLAUDE.md:111`, `memory/feedback_watchdog.md:14` | For Claude cloud sessions this is now wrong: the PR subscription tool says it delivers "CI failures, and successful check-suite rollups". It's still true on your Mac and for Codex. Rewrite: "In cloud sessions, subscribe to the PR — it reports passes and failures; elsewhere, re-check until every check is complete." |
| F7 | Codex briefs reference `.claude/plans/121-security-propagate-fix.patch` | `codex-catch-up-review-brief.md:5`, `codex-review-chunk-2-workflows.md:28` | That file was removed after the fix went onto `main`. Chunks 1a/1b/2 are done and the catch-up brief was replaced by the chunks; only **chunk 3 is still owed**. Archive or delete the four finished briefs so nobody re-runs them. |
| F8 | "43 GitHub Issues across 7 milestones", "GitHub Project (as of 2026-03-23)" | `memory/project_overview.md:3,19-23` | There are now 137 issues. Rewrite to point at `ISSUE_AUDIT_2026-09-23.md` and the handoff instead of holding counts. |
| F9 | "FPDF v1.86" | `CLAUDE.md:59` | CI clones FPDF **without a version pin** (`.github/workflows/ci.yml:167`), so CI tests whatever is newest, not 1.86. This is a code fix, not a rule fix: pin the tag in CI. |
| F10 | "working on multiple web projects (phpWhoIs, CueRCode, SIGNula)" | `memory/user_role.md:7` | Low confidence: the handoff also names Go2My.Link, ProjectEPass and WebMS-Intra. Update if you like. |
| F11 | Three copies of the original brief, one with a typo name (`ProjectcBrief_Chat.claude`) | `.claude/` | Not behaviour rules. Consider moving the two chat versions into an `archive/` folder so no session mistakes them for the current brief. |
| ✅ | Library versions (Bootstrap 5.3.3, FontAwesome 6.5.1, jQuery 3.7.1, php-qrcode 5.0.5, settings-container 3.2.1, tc-lib-barcode 2.12.0, tc-lib-color 2.13.1, Swagger 5.33.0), all folder paths, the DEV_NOTES "Branch Model" heading, "Codex CLI not installed in the cloud container" | `CLAUDE.md` | **Checked and correct.** |

### Two settings items (not instruction text) — for you to decide
- **S-1 — Effort level.** This cloud session runs at **`xhigh`** effort. O55: "Start at `medium`, the default on Claude Opus 5.5… Reserve `xhigh` and `max` for work where you've measured a quality gain." It also notes that Opus 5.5 "tends to think more per turn than Claude Opus 5, especially at `xhigh` and `max`." Recommendation: `high` as your everyday level, and `xhigh` for big planning sessions (or type `ultrathink` for a single hard task). This is the biggest lever on your Claude bill. You change it with `/effort` or `/model`.
- **S-2 — Wire up the repo memory folder** (F1).

---

## 3. Line-by-line table (request C)

Verdicts: **DELETE** (quote required, else KEEP), **REWRITE**, **KEEP**. "Truth rule" = it stops made-up facts or unchecked claims, so it is never cut.

### `.claude/CLAUDE.md` (139 lines → 57 instructions: 4 DELETE, 12 REWRITE, 41 KEEP)
| Line | Your line (short quote) | Verdict | Why (plain English) | Anthropic's line |
| --- | --- | --- | --- | --- |
| 5 | Project overview, domains | KEEP | Facts only you know | PA: "Context is never cruft." |
| 9 | "All Claude memory… stored in this `.claude/` directory" | KEEP + fix wiring | Right rule, but nothing loads that folder (F1) | CC-MEM: auto memory lives at `~/.claude/projects/<project>/memory/` unless `autoMemoryDirectory` is set |
| 10 | Codex memory lives in `.OpenAI/` | KEEP | Fact | — |
| 12 | "Resuming… Read `.claude/HANDOFF.md` §0 FIRST" | KEEP | A good cold-start pointer | BP: "Review progress.txt, tests.json, and the git logs." |
| 16 | "These instructions MUST be followed for EVERY prompt/action" | REWRITE → "These apply to all work in this repo." | Capitals make everything shout, so nothing stands out | BP: "dial back any aggressive language… you can use more normal prompting"; CC-BP: "If you emphasize many lines, none of them stands out." |
| 20 | Full multi-line PHP, detailed comments, PHP constants | KEEP | Your house style | PA: "Keep what only the author knows" |
| 21 | PHP 8.5 with 8.4 compatibility | KEEP | Fact | — |
| 22 | MySQLi prepared statements for ALL queries | KEEP | Security rule | — |
| 23 | HTML5, responsive, no layout tables | KEEP | House style | — |
| 24 | Modern CSS with fallbacks | KEEP | House style | — |
| 25 | All code fully commented (emojis welcome) | KEEP | Your stated preference | — |
| 26 | File headers: path + copyright | KEEP | House rule | — |
| 27 | Sensitive data AES-256-GCM + HKDF | KEEP | Security rule | — |
| 28 | All inputs sanitised | KEEP | Security rule | — |
| 32-44 | File structure (13 paths) | KEEP | All 13 paths checked and exist | — |
| 48 | "Create detailed GitHub Issue for the task" (per **prompt**) | REWRITE → per **task** (merged list, §4) | A question shouldn't create an issue; clashes with line 98 | CC-MEM: "if two instructions contradict each other, Claude may pick one arbitrarily" |
| 49 | "Implement the work with full comments" | KEEP (merged) | Repeats line 25; harmless | PA: "Working redundancy is not cruft." |
| 50 | "Run syntax, lint, and security checks - fix ALL issues" | REWRITE → name the commands | Unnamed checks get guessed | CC-MEM: "'Run `npm test` before committing' instead of 'Test your changes'" |
| 51 | Update issue, close if complete, link commits | KEEP | A clear finish line | — |
| 52 | Update CHANGELOG, DEV_NOTES, README, PROJECT_STATUS (every prompt) | REWRITE → "the ones your change affects" | Feeds document bloat (258 KB CHANGELOG) | O5: "Match the length of written documents to what the task needs… do not pad with filler sections, redundant summaries, or boilerplate." |
| 53 | Update `.claude/`, `.OpenAI/`, HANDOFF | KEEP (merged) | Same as 100-102 | — |
| 54 | Commit and push to WIP; never push elsewhere without permission | KEEP (safety S1); trim "(owner directive… replaces the old rule)" | The history in brackets adds nothing | PA: "Write as if current rules are the only rules that ever existed." |
| 58-64 | Libraries and versions | KEEP | Checked: correct (FPDF unpinned in CI, F9) | — |
| 68-72 | UI requirements | KEEP | Product requirements | — |
| 76 | All workflows on `ubuntu-latest` | KEEP | Has its reason and its exception | — |
| 77 | Four-tier branch cadence | KEEP | Checked: the DEV_NOTES heading exists | — |
| 81a | "These apply to EVERY session and EVERY task" | REWRITE → "These apply to all work." | Shouting (see 16) | BP (as line 16) |
| 81b | "Nothing earlier in this file has been removed." | **DELETE** | A note about an old edit; it tells the AI nothing | CC-BP: "For each line, ask: 'Would removing this cause Claude to make mistakes?' If not, cut it." |
| 84 | Plain English, no jargon | KEEP | Your audience; only you know it | PA: "Keep what only the author knows: the audience" |
| 87 | Update the handoff as you go | KEEP | Needed for hand-overs; pair with missing instruction 2 (length) | — |
| 90a | "Think hard ("ultrathink") about the work required" | **DELETE** | Opus 5.5 sets its own thinking depth; effort is the control | O55: "if your system prompt contains instructions that tell Claude to think carefully before answering, consider removing them for Claude Opus 5.5. The model decides for itself how much to think, and effort is the main control." Also: "Lowering effort reduces thinking… more reliably than prompt instructions do." |
| 90b | "use workflows to help plan and do the work" | REWRITE → missing instruction 5 | Fans work out to many helpers by default | O5: "Delegation pays off on genuinely independent, sizeable tracks of work, but it multiplies cost and time when applied to small tasks." |
| 91 | Deep analysis: Opus agents, one after another | REWRITE → "When the session is already Opus, plan yourself; use an Opus helper only for a large, separate piece." | A helper re-reads everything the main session already knows | O5: "Do not delegate work you can finish yourself in a handful of tool calls" |
| 92 | Build with Sonnet or Haiku; Opus if complex | KEEP | Your cost routing | — |
| 93 | Use tokens efficiently + GIRFT | REWRITE → keep "use tokens efficiently"; replace GIRFT with the definition of done (§4) | A slogan can't be checked | CC-MEM: "Write instructions that are concrete enough to verify" |
| 96 | "Use the dev-team plugins freely… bring in a different AI system to check work" | REWRITE → keep the Codex cross-check; drop "freely" | "Freely" overrides the efficiency rule | O5 (as 90b) |
| 99-102 | Steps after EACH task | KEEP (merged with 46-54) | A good finish line | BP: numbered steps "when the order or completeness of steps matters" |
| 105 | Watchdog: don't start a dependent step until it's really done | KEEP — truth rule | Stops claims about results nobody has seen | — |
| 106 | "Finished" includes going wrong; silence is not success | KEEP — truth rule | — | — |
| 107 | Check the whole result + 2026-08-04 lesson | KEEP — truth rule (dates optional) | — | BP: "Providing context or motivation… can help" |
| 108 | If it went wrong: fix, restart, re-watch | KEEP | — | — |
| 110 | Background work → wait for the "finished" notice; never guess | KEEP — truth rule | — | — |
| 111 | "notifications report failures but **not** successes… keep re-checking" | REWRITE (F6) | Out of date for cloud sessions; makes the AI poll | PR-subscription tool: delivers "CI failures, and successful check-suite rollups" |
| 112 | `send_later` backstop | KEEP | — | — |
| 113 | After a merge, recreate WIP from alpha | KEEP | A repo fact | — |
| 114 | Jobs "failing" in ~2 s = not started → report as blocked | KEEP — truth rule | — | — |
| 115 | Never leave anything unwatched; record in §0 | KEEP | — | — |
| 116 | Pointer to device-wide copy | KEEP | — | — |
| 119a | "Keep ALL documentation current: every `.md` file…" | REWRITE → "Update the docs that describe what you changed, in the same commit." | No finish line; invites touching every doc | O5: "Match the length of written documents to what the task needs" |
| 119b | "Update the OpenAPI spec for every API change" | KEEP | Clear and checkable | — |
| 119c | "(still outstanding — see #103 item 2d)" | **DELETE** | Wrong: line 62 and #111 show it's done | CC-MEM: "Review your CLAUDE.md files… periodically to remove outdated or conflicting instructions." |
| 122 | Re-order and bundle steps sensibly | KEEP | Gives room for judgement | — |
| 125a | Work autonomously; stop only for an explicit decision | KEEP | Matches current guidance | O55: "The stops the user does want are the ones where nothing can move without them" |
| 125b | "Raise all questions/decisions **up front**… After asking, carry on" | REWRITE (A3) | The question tool pauses the session | O55: unwanted stop "Three: a list of decisions for the user when, by your own account, none of them blocks the rest of the work." |
| 126 | "put **every** decision or clarification… as a real question" | REWRITE → "every decision that blocks work"; keep the format | Too many stops | S55: "only stop to ask when you can't go on without the user or before a risky step." |
| 129 | "frequent progress updates as a table" | REWRITE → missing instruction 3 | "Frequent" is undefined | O55: "if you want more frequent or predictable updates… say so in the system prompt; the model is responsive to such instructions." |
| 132 | Codex reviews until it finds nothing; record as owed if unavailable | KEEP | An independent check, not double work | CC-BP: "a second opinion… so the agent doing the work isn't the one grading it" |
| 133 | Codex allowance: small pieces; Opus stand-in; no extra spend | KEEP (safety S4) | Stand-in only, so follow it as written (see "verify-twice") | — |
| 136 | No PR stacking | KEEP | Has its reason | — |
| 139 | AI-tool fallback + full review afterwards | KEEP (S7) | Your policy; a cross-check, not self-checking | — |

### `.claude/DEVICE_WIDE_RULES.md` (67 lines → 10 instructions: 0 DELETE, 1 REWRITE, 9 KEEP)
| Line | Your line | Verdict | Why | Anthropic's line |
| --- | --- | --- | --- | --- |
| 1-15 | Note to you about where to paste it | KEEP | Not copied into tools | — |
| 20-26 | AI-tool fallback | KEEP | Agrees with CLAUDE.md | — |
| 28-30 | Handoff up to the minute | KEEP | — | — |
| 34-35 | Plain English | KEEP | — | — |
| 39-40 | Keep handoff documents updated | KEEP (repeats 28-30; harmless) | — | PA: "Working redundancy is not cruft." |
| 44-50 | Watchdog | KEEP — truth rule | — | — |
| 52-54 | Silence is not success | KEEP — truth rule | — | — |
| 55-57 | Check the whole result | KEEP — truth rule | — | — |
| 60-64 | "(Automatic GitHub notifications usually report failures but not successes…)" | REWRITE → add "where your tool can subscribe to PR results, use that instead" | Partly out of date (F6); "usually" keeps it true for other tools | as CLAUDE.md:111 |
| 65-67 | Never leave anything unwatched | KEEP | — | — |
| *(new)* | — | ADD | The #137 polish-check rule (you asked for it device-wide) and the "Blocked on me / Changed / Found" rule | — |

### `.claude/agents/` (28 lines → 9 instructions: 0 DELETE, 3 REWRITE, 6 KEEP)
| Where | Your line | Verdict | Why | Anthropic's line |
| --- | --- | --- | --- | --- |
| `deep-architect.md:3` | Description; "one after another" | KEEP | Routing text | — |
| `deep-architect.md:4` | `model: claude-opus-5-5` | REWRITE → `model: opus` | Correct today; the alias keeps it current | CC-SUB: "Model alias: use one of the available aliases: `sonnet`, `opus`, `haiku`, or `fable`" |
| `deep-architect.md:7-10` | Weigh trade-offs, explain the decision, correctness over speed, plain English | KEEP | A short explanation is allowed | O55: "You can still ask for a short explanation of the answer" |
| `implementer.md:3` | Description | KEEP | — | — |
| `implementer.md:4` | `model: claude-sonnet-5` | REWRITE → `model: sonnet` | Pins the **previous** Sonnet | Claude API skill: "previous-generation IDs such as `claude-sonnet-5` are only for users who name that version." |
| `implementer.md:7-10` | House style; run `php -l` + tests until clean; plain-English summary | KEEP | A real check | S55: "run a real check that exercises the change before reporting it done" |
| `implementer.md` *(gap)* | — | ADD one line | Sonnet 5.5 adds tests, docs and files nobody asked for | S55: "When the work the user asked for is done and checked, stop and report. Don't add features, tests, files, docs or refactors that weren't asked for. If you think one would help, mention it at the end instead of doing it." |
| `quick-edits.md:3-4` | "Use PROACTIVELY…"; `claude-haiku-4-5` | KEEP (or `haiku`) | Routing text may carry urgency; model is current | PA: "Trigger/routing text may carry calibrated urgency" |
| `quick-edits.md:7-8` | Make the edit, check it builds, stop; don't redesign | KEEP | A good scope rule | — |

### `.claude/memory/` (186 lines → 11 instructions: 0 DELETE, 3 REWRITE, 8 KEEP) — none load automatically (F1)
| File | Verdict | Why |
| --- | --- | --- |
| `MEMORY.md` | KEEP | Index |
| `feedback_audit_first.md` | KEEP — truth rule | "Never assume prior completion claims" stops made-up status |
| `feedback_gha_shell_testing.md` | KEEP — truth rule | "confirm it in a real run log before stating it as fact" |
| `feedback_local_claude.md` | KEEP + fix wiring (F1) | — |
| `feedback_model_routing.md` | KEEP | Agrees with CLAUDE.md; "Codex not installed in the cloud" checked: true |
| `feedback_plain_english.md` | KEEP | — |
| `feedback_push_and_workflow.md` | KEEP (S1, S5) | Holds the guard-hook trap; copy it into CLAUDE.md |
| `feedback_questions.md` | REWRITE (as CLAUDE.md:126) | "every decision or clarification… up front" |
| `feedback_watchdog.md` | REWRITE line 14 only (F6) | The rest is a truth rule |
| `feedback_writer_agents.md` | KEEP | Matches O5's "Brief the subagent precisely the first time." |
| `project_overview.md` | REWRITE (F8) | "43 GitHub Issues…" is wrong; CC-MEM: "remove outdated or conflicting instructions" |
| `user_role.md` | KEEP (F10 optional) | — |

### `.agents/skills/prompt-polish/SKILL.md` (88 lines → 3 instructions: 1 DELETE, 0 REWRITE, 2 KEEP)
| Line | Your line | Verdict | Why | Anthropic's line |
| --- | --- | --- | --- | --- |
| 3, 25-27 | "Supports Claude Fable 5 and Claude Opus 4.8"; `opus` → `opus-4-8.md` | **DELETE the `opus` → 4.8 routing** (route "opus" to "not supported yet" until upstream adds Opus 5.5) | Gives Opus 5.5 the wrong advice | Migration guide: Opus 5 "Delegates to subagents more readily - the opposite of Opus 4.8… any 'delegate more' guidance you added for Opus 4.8 should come out" |
| 8-62 | Workflow + hard gate | KEEP | Already matches current guidance ("polishing is mostly subtraction") | — |
| 64-83 | Output contract + anti-patterns | KEEP | — | — |

### Codex-only files — Anthropic verdict **NOT RUN** (3 groups; checked only for facts and clashes)
| File | Result |
| --- | --- |
| `AGENTS.md` | Agrees with CLAUDE.md. No fact problems. |
| `.OpenAI/CONTEXT.md`, `MEMORY.md`, `README.md` | Agree. Its "notifications don't announce success" is still true **for Codex**. Holds the only copy of S2 (add it to CLAUDE.md). |
| `.claude/plans/codex-*.md` | F7: two briefs point at a deleted patch file; four of the five are finished. **2 DELETEs** (archive `codex-catch-up-review-brief.md` and the finished chunks 1a/1b/2; keep chunk 3). Justification: CC-MEM "remove outdated or conflicting instructions". |

### The count
| | Instructions | DELETE | REWRITE | KEEP | Not judged |
| --- | --- | --- | --- | --- | --- |
| CLAUDE.md | 57 | 4 (81b, 90a, 119c, + 49/53 merged away counted as KEEP) | 12 | 41 | — |
| DEVICE_WIDE_RULES.md | 10 | 0 | 1 | 9 | — |
| agents | 9 | 0 | 3 | 6 | — |
| memory | 11 | 0 | 3 | 8 | — |
| prompt-polish skill | 3 | 1 | 0 | 2 | — |
| Codex-only | 3 groups | 2 (finished briefs) | 0 | — | 3 |
| **Total** | **83** | **7** | **19** | **54** (+ the 3 not judged) | **3** |

**811 lines in. 7 deletes, 19 rewrites, 6 additions** (5 missing instructions + the "Blocked on me / Changed / Found" rule), **2 settings decisions** (S-1 effort, S-2 memory folder).
CLAUDE.md alone: 139 lines in, 4 deletes, 12 rewrites. The rewrites mostly *shorten*, so the
file would end up about the same length once the additions go in.

> Count note: CLAUDE.md's DELETE column lists three line items (81b, 90a, 119c). The fourth
> is the duplicated "after each task" wording, which disappears when the two checklists merge.

---

## 4. Exact wording to paste in (when you approve)

**Merged checklist — replaces `CLAUDE.md:46-54` and `:98-102`:**
```text
### ✅ Definition of done (each task — not each question)
1. A GitHub issue exists for the task (description, scope, acceptance criteria).
2. The checks pass: `php -l` on changed PHP, `php tests/run.php`, `actionlint` +
   `shellcheck` on changed workflows, `gitleaks`, and `php tools/check_site_polish.php`.
   If a check can't run here, say which one and why instead of calling the task done.
3. Docs that describe what changed are updated in the same commit (CHANGELOG always;
   DEV_NOTES / README / PROJECT_STATUS / openapi.json when they're affected).
4. `.claude/` memory, `.OpenAI/` and `.claude/HANDOFF.md` §0 updated.
5. Committed and pushed to `work-in-progress` only; the issue updated with the commit
   links, and closed only when every acceptance criterion is checked.
```

**The five missing instructions** (each adapted from the Anthropic sample named under it):

1. **Keep answers short** *(O5 "Response length and verbosity")*
```text
Keep replies short and to the point: lead with the answer, keep caveats brief, and give a
high-level summary unless I ask for detail. Short means choosing what to include — still
write full plain-English sentences, not fragments, arrows or jargon.
```
2. **Cap document length** *(O5 "Written deliverable length")*
```text
Match the length of every document you write or update — reports, plans, CHANGELOG and
DEV_NOTES entries, the handoff — to what its reader needs: cover the substance, with no
filler sections, repeated summaries or boilerplate. Keep HANDOFF.md §0 to what a fresh
session needs to carry on (aim for one screen per topic); move finished items out of §0.
```
*Note: Anthropic advises **against** hard word counts for the AI's own answers ("output caps starve reasoning on hard problems" — PA). "One screen per topic" is a guide for a file that is re-read every session, following CC-MEM's "target under 200 lines" logic. Drop it if you prefer no numbers.*

3. **How to update me while you work** *(O5 "User-facing progress updates" + O55)*
```text
Before your first action, say in one sentence what you're about to do. While working,
give a short update only when you find something important, change direction, or finish
a task in the queue — then show the queue table (task, status). When you finish, lead
with the outcome, then end with the three headings below.
```
4. **Hold the task scope** *(O5 "Task scope and over-verification")*
```text
Deliver what was asked, at the scope intended. Make routine judgment calls yourself, and
check with me only when different readings of the request would lead to materially
different work. If the request looks mistaken or a better approach exists, say so in a
sentence and carry on with the task as asked — don't quietly narrow, widen or change it.
Finish the whole task; if part of it can't be done, do the rest and say plainly what's
missing and why. Other problems you notice go into an issue or the end report, not into
this change.
```
5. **Limit the helpers you spawn** *(O5 "Controlling subagent spawning")*
```text
Use a helper agent only for a large piece of work that is genuinely separate and can run
alongside you (for example a wide multi-file investigation), or to hand clearly planned
building work to a cheaper model (Sonnet or Haiku). Don't use one for work you can finish
yourself in a handful of steps, and don't use one to re-check your own work — the Codex
review is the independent check. If one helper can do it, use one. Run at most 3 at a time
unless I say otherwise.
```
*Optional hard cap, which Claude Code enforces even if the AI forgets:* set the environment
variable `CLAUDE_CODE_MAX_CONCURRENT_SUBAGENTS=3` (O5 names it as "the deterministic caps").
The number 3 is my suggestion, not Anthropic's; their sample uses 20 as an upper bound.

**The new end-of-run rule (request A):**
```text
### 🏁 End-of-run report
At the end of every long run (one that used helper agents, ran unattended, or took more
than about 30 minutes), finish with exactly these three headings, one line per item:
- **Blocked on me** — each decision or action only I can take, with your recommendation
  and why. Write "Nothing" if there is none.
- **Changed** — what you changed, with commit and issue links.
- **Found** — problems or facts you discovered but didn't fix, and where each is recorded.
```

**The replacement for the "ask up front" rule (A3):**
```text
Work through everything autonomously. Stop and ask only when nothing can move without me,
or before an action on the approval list. For other choices, take the recommended option
if it can be undone, say so in your update, and list it under **Blocked on me** at the end.
When you do ask, use the question tool: options in plain English, recommended first, and why.
```

---

## 5. What I could not check (NOT RUN)
- The three `claude.dev` blog posts (blocked by this environment's network settings).
- Whether Claude Code acts on the word `ultrathink` when it sits inside CLAUDE.md, rather than in a typed prompt (not documented; can't test it from inside this session).
- Your Mac's `~/.claude/CLAUDE.md` and `~/.codex/AGENTS.md`. I audited the repo copy (`DEVICE_WIDE_RULES.md`) and assumed it matches; the handoff says it was confirmed on 2026-09-25.
- Codex-facing files against OpenAI's own prompting guide.
- **Before/after behaviour tests.** Anthropic's audit guide says "removal is a hypothesis, not a conclusion": each change should be tried and watched. Nothing here has been tried yet, because nothing has been changed.
- Codex review of this report (the Codex CLI isn't installed in the cloud container; logged as owed in the handoff).
