# 0010 — Making the first Global Admin from a waiting account sets a new password

- **Date:** 2026-11-24
- **Question (plain words):** A stranger could register with the owner's email before the owner does (the sign-up page gives the same reply either way). If the owner then runs the command that makes that account an administrator, the stranger's password would give them the admin account. How should the command guard against this?
- **Answer (owner):** **When the command activates an account that is still waiting for verification (`pending`), it asks the person at the keyboard for a new password (typed hidden), replaces the old one, signs out all existing sessions of that account, and shows when the account was created. All in the same transaction as the role change.**
- **Rules out:** relying on the operator noticing a creation date; keeping the password the account was registered with.
- **Implemented in:** `_includes/cli/admin_access.php` and `_functions/fn_admin.php` (grant with `--activate`); the phpMyAdmin fallback in DEV_NOTES must be changed to match (it cannot set a safe password, so it is to be marked as a last resort with a "reset the password on first sign-in" step). Phase 1B review finding 1.
- **Supersedes:** refines plan §6.2 steps 3-4 (`.claude/plans/7n-admin-commerce-plan.md`).
- **Issue:** #31.
