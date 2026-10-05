# 🌐 Device-Wide Standing Rules (copy to every AI tool on your machine)

> **Why this file exists:** the owner asked (2026-09-23, #108) for the AI-tool
> fallback rule to apply to ALL projects on their device, not just CueRCode.
> The watchdog rule was added the same way on 2026-09-24 (#116), and the
> "polish check: no AI-made tells" rule on 2026-10-05 (#137).
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

## ⏱️ Watchdog for anything that finishes later (added 2026-09-24, CueRCode #116)

Some steps don't finish straight away: automatic checks (CI) runs,
pull-request merges, deploys, helper agents or commands running in the
background, and long builds or test runs. **Every time a step like that
starts, set up a "watchdog" — something that waits for it to finish and then
checks the result — and don't start any later step that depends on it until
the watchdog says it's done.** Tasks that don't depend on it can carry on in
the meantime.

- **"Finished" includes going wrong.** Failed, cancelled, timed out, skipped
  or never started all count as finished-with-a-problem. Silence is not
  success.
- **Check the whole result, not just the headline.** A run can report
  "success" and still have side effects (issues opened, PRs created, branches
  deleted). Give them time to appear, then look for them properly.
- **If it went wrong:** find out why, fix it, run it again, and set the
  watchdog again before moving on.
- **Use whatever your tool offers to wait properly:** background tasks with a
  "finished" notification, repeatedly re-checking a status until it's final,
  or a scheduled self check-in for long waits. Never guess or assume a
  result. (Automatic GitHub notifications usually report failures but not
  successes, so actively re-check.)
- **Never leave anything running unwatched.** Before stopping or handing
  over, write down in the handoff document anything still in progress and how
  it's being watched, so the next session or tool can take over the watch.

## 🔍 Polish check: no "AI-made" tells (added 2026-10-05, CueRCode #137)

For every project with a website or app that people see, check regularly, and
always before a release, for the small details that make it look unfinished or
machine-made, and fix them:

- No default, preview or development addresses anywhere (hosting-provider
  preview domains, `localhost`, staging links).
- Every page has its own title, description, favicon, share image, canonical
  address and a working social-share preview.
- The "boring" parts exist and are helpful: real 404/403/500 pages, loading
  states, empty states and error messages that say what went wrong and what to do.
- Headings in order, alt text on images, a real sitemap and robots file.
- No console errors, leftover `console.log`, exposed development files or
  oversized scripts; CDN integrity hashes checked against the real file.
- It works on phones, tablets and odd screen sizes, with very long text, empty
  data and error states.
- Spacing, fonts, buttons, corner rounding, icons and components stay
  consistent from page to page.
- Every button and link really works.
- Nothing visitors can see, including "View source", contains developer notes,
  ticket numbers, TODOs or stock marketing phrases.

Automate what can be automated: a check script in the repo, run in CI on every
push. Do the rest by eye in a real browser at several screen sizes. Record what
you checked and what you couldn't.
