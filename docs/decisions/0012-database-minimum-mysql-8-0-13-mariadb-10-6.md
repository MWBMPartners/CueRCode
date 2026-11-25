# 0012 — Minimum database is MySQL 8.0.13+ or MariaDB 10.6+

- **Date:** 2026-11-25
- **Question (plain words):** The README says MySQL 5.7 but the database setup file needs 8.0.13 or newer. Which minimum do we state and enforce?
- **Answer (owner):** **MySQL 8.0.13+ or MariaDB 10.6+.** The installer refuses older versions with a plain message; README and DEV_NOTES say the same.
- **Rules out:** keeping MySQL 5.7 / MariaDB 10.3 working.
- **Implemented in:** `install/index.php` (version check), README requirements. Real-database runs so far: MySQL 8.0.36, 8.4.13, MariaDB 11.4.13; MariaDB 10.6 is not yet tested.
- **Supersedes:** the README "MySQL 5.7+ or MariaDB 10.3+" line.
- **Issue:** #113.
