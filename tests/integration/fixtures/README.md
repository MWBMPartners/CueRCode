# Test fixtures for the real-database scripts

Files here are read by `tests/integration/*_real_mysql.php`. They are never uploaded to the site.

## `install_76b1af7.sql`

- **What it is:** `web/CueRCode.net/_includes/sql/install.sql` exactly as it was at commit `76b1af7a9ab9f5d910971a93d8acc875e5897c69` (2026-11-23), the last commit before task 7n Phase 1A moved the #70 price-snapshot columns out of it. Byte for byte: no header was added, so it can be compared with history.
- **Who reads it:** `tests/integration/migrations_real_mysql.php`, scenario B ("an old install.sql upgrades to the same schema as a new one").
- **Why it is committed** (third-round review, Low 2): the script used to read it with `git show 76b1af7:...`. After a squash or rebase merge into `alpha`, that commit is no longer in the branch's history, so every deploy would have been blocked.
- **How it is checked:** the script refuses to run unless the file's SHA-256 is `531d98788dd0d4c8d0ddbab2c5dd30582fe060ac771dfd7edc990e6ab9990bfe`, and, wherever git history still holds commit `76b1af7`, unless it is identical to that commit's file. Never edit it; if scenario B needs a different old file, add a new fixture.
