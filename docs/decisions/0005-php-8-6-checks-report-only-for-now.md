# 0005 — PHP 8.6 check failures report but do not block, for now

- **Date:** 2026-11-18
- **Question (plain words):** Should a failure on PHP 8.6 stop a merge or deploy straight away?
- **Answer (owner):** **Report only for now.** An 8.6 failure shows in red but does not block; 8.4 and 8.5 failures always block. Promote 8.6 to blocking once GitHub's setup tool serves 8.6 as the normal stable version and it has passed two runs in a row.
- **Rules out:** blocking on 8.6 today.
- **Implemented in:** `.github/workflows/ci.yml` and `deploy.yml` (`continue-on-error` for 8.6).
- **Supersedes:** confirms the provisional parts of 0002.
- **Issue:** #151.
