# 0004 — Use PHP 8.6 features on 8.6 where it is safe

- **Date:** 2026-11-18
- **Question (plain words):** Should CueRCode use PHP 8.6-only helpers when running on 8.6, while 8.4 and 8.5 keep the old way?
- **Answer (owner):** **Yes, adopt where safe.** On 8.6 use the 8.6 method; on 8.4 and 8.5 keep the existing code. Choose the branch by testing whether the function exists (or the PHP version), never branch stored data or security decisions on version, and keep results identical across versions.
- **Rules out:** staying on one code path only; using an 8.6 feature with no fallback for 8.4/8.5.
- **Implemented in:** pending (see issue #151).
- **Supersedes:** 0003 (which was a provisional planner default, never an owner answer).
- **Issue:** #151.
