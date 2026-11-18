# 0003 — Do not adopt PHP 8.6-only features without a measured benefit

- **Date:** 2026-11-18
- **Question (plain words):** PHP 8.6 adds some new built-in helpers. Should CueRCode start using them on 8.6 while 8.4 and 8.5 keep the old way?
- **Status: PROVISIONAL DEFAULT, NOT AN OWNER ANSWER.** The planner recommended this and it was applied as the safe default; the owner has not yet been asked. Confirm or overturn it by adding a new record that supersedes this one.
- **Contradicts the owner's stated wish:** in #151 the owner said *"adopt 8.6 methods on 8.6 using PHP_VERSION_ID checks while 8.4 and 8.5 keep working"*. This provisional default does the opposite (adopts none), so it must not be treated as the owner's decision. The planner's reason is a measured fact: the code has no calls that 8.6 discourages, so there is nothing to swap, and the only candidate, 36 `max(min())` lines that `clamp()` could replace, gives users no benefit. If the owner still wants 8.6 methods adopted, add a new record that supersedes this one and follow the `PHP_VERSION_ID` convention in `DEV_NOTES.md`.
- **Answer (provisional):** **No, not for now.** CueRCode uses nothing that 8.6 discourages, so there is nothing to swap, and the new helpers give users no benefit. Do not use an 8.6-only feature unless a measured benefit is shown first. All code stays compatible with 8.4 and 8.5. When a version-specific branch is ever needed, follow the convention in `DEV_NOTES.md` ("PHP 8.6 readiness and version guards"): test that a function exists rather than the version number, and never branch on version for stored data or security decisions.
- **Rules out:** replacing the 36 `max(a, min(b, x))` lines with `clamp()` on 8.6; using `quote_string()` (we use prepared statements only); a second URL validator built on the 8.6 `Uri` classes; new sodium functions that would make data stored on 8.6 unreadable on 8.4.
- **Implemented in:** no code change (that is the decision); the convention is in `DEV_NOTES.md`.
- **Supersedes:** nothing. Related: 0002.
- **Issue:** #151 (the plan's question 1, still to be put to the owner).
