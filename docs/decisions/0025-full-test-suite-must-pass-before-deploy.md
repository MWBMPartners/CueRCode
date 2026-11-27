# 0025 — The full test suite must pass before anything is uploaded

- **Date:** 2026-11-27
- **Question (plain words):** Should a failing automatic test block the upload to the web host?
- **Answer (owner):** **Yes, block it.** The deploy waits for the full test suite on PHP 8.4 and 8.5; PHP 8.6 stays report-only (decision 0005).
- **Rules out:** uploading code whose tests fail.
- **Implemented in:** `.github/workflows/deploy.yml` (to be changed).
- **Supersedes:** the provisional position written in decision 0002 and `deploy.yml` that the tests are not run before a deploy.
- **Issue:** #155.
