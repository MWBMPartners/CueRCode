# 0037 — If the human check provider is unreachable, try the others, then let only sign-in through

- **Date:** 2026-11-27
- **Question (plain words):** The human check relies on an outside company. If it cannot be reached, what happens on sign-up, sign-in and password reset?
- **Answer (owner):** **"Fall back to other configured providers. If all else fails, let sign in through only, but flag this in our internal activity log."**
- **Rules out:** refusing all forms during an outage; letting every form through; letting sign-in through without a record.
- **Implemented in:** not yet built.
- **Supersedes:** nothing (changes the default the builder chose).
- **Issue:** #163.
