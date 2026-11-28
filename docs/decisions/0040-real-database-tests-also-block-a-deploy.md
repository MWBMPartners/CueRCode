# 0040 — The real-database tests also run automatically and block a deploy

- **Date:** 2026-11-28
- **Question (plain words):** Should the tests that need a real database run automatically and block an upload if they fail?
- **Answer (owner):** **Yes, block the upload.** They run on temporary MySQL and MariaDB servers before every upload and on pull requests.
- **Rules out:** leaving them manual; reporting without blocking.
- **Implemented in:** not yet built.
- **Supersedes:** nothing (widens what 0025 requires).
- **Issue:** #174.
