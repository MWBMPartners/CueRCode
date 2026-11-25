# 0018 — If the "starting data loaded" marker is present, the installer never re-seeds (lead decision, owner may overrule)

- **Date:** 2026-11-25
- **Question (plain words):** Suppose the installer's lock file is lost, the database still says "starting data was loaded" (a marker), but the owner had deliberately emptied the QR types, logos and plans tables. Should the installer put the default rows back?
- **Answer (lead, not the owner):** **No. The marker means seeding already happened, so the installer skips it.** It will not bring back rows the owner removed. A deliberate clean re-seed is done by removing the marker setting (`seed_data_loaded`) first; the installer's message says how.
- **Rules out:** treating "tables empty" as "seed needed" when the marker is present (the builder's earlier choice, overturned after Codex round 3).
- **Implemented in:** `_functions/fn_migrations.php` seed-state check; test in `tests/integration/migrations_real_mysql.php` changed to match.
- **Supersedes:** nothing in `docs/decisions/` (it overturns a choice noted in the round-2 fix report).
- **Issue:** #113.
