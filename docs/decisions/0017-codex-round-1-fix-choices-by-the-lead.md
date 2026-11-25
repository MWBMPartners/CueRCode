# 0017 — Choices made while fixing the Codex round-1 findings (lead decision, owner may overrule)

- **Date:** 2026-11-25
- **Question (plain words):** Seven of the fixes for the Codex review findings are small design choices that change what a visitor or the owner sees. They were not put to the owner one by one because each is the safest simple option and each can be reversed.
- **Answer (lead, not the owner):**
  1. Password resets and password changes are refused with a plain "try again later" (`503 DATABASE_UPGRADE_NEEDED`) until database upgrade 0003 is applied. The emailed link is not used up. Sign-in, `/admin/upgrades/` and `migrate.php` keep working. A fresh install applies 0003 itself.
  2. A "forgot password" or "send me a new link" request that arrives while that same account is busy gets the usual answer but no link; the visitor asks again.
  3. Erasing an account deletes its reset and verification links (including the address each request came from) and cancels emails still waiting to go to it. Sent emails and the activity log stay with issue #129.
  4. If a setting already exists when the starting data loads, the existing value is kept.
  5. Behind a proxy that hides whether the visitor used HTTPS, the form-origin check is unchanged.
  6. A failed log entry for a wrong password or a lock-out still lets the lock-out happen; only the success entry blocks unlocking editing.
  7. The sample that put an API key in browser code was rated Medium, not High (example code, not a hole in the site).
- **Rules out:** nothing the owner has decided; none of these touches decisions 0001-0016.
- **Implemented in:** commits d9d95e2..e1e2f1f; `.claude/plans/new-install-readiness-queue.md` F2 table; SECURITY.md F-041..F-047.
- **Supersedes:** nothing. The owner can overrule any item by a new decision file naming this one.
- **Issue:** #113 (and #157, #158 for the leftovers).
