# 7n Phase 2 recheck (e594ec8..cee5689) - report

Reviewer: Opus (independent, did not write the code). 2026-11-27. Not committed.
The repository HEAD moved on to 83a1795 during the review (docs only; nothing under web/, tests/, tools/ or .github/ changed since cee5689), so the code checked is cee5689's.

## Status: complete. No High or Medium findings. Five Low, three Info.

## The original findings, re-run on the new code
- F1 closed. Real installer over HTTP, MySQL 8.0.36 and MariaDB 10.6.28: migrate.php first, lock held -> "nothing was changed", 0 plans, no marker, no lock file; retry -> 5 plans, 5 price points, coming-soon wording, locked. Same data as a normal install (checksums match). Failing replay step (trigger) -> all rolled back, retry succeeds. PHP killed with kill -9 mid-load -> nothing kept, lock released, no open transaction.
- F2 closed for the reported shapes (both samples refused; mutation caught by test_migrations).
- F3 closed for date edits on free, anonymous and the trial plan (mutation caught by admin_plans_real_mysql).
- F4 closed (both plants caught).
- F5 closed (Pro 5.49 survives 0004 and a yearly save; mutation caught by unit and real-database tests).
- F6 closed (ended price hidden, started price shown, pure and HTTP; mutation caught by admin_plans_real_mysql).
- Currency race fix: mutation caught by scenario M.

## Findings (ranked)
1. Low - installer locks itself as successful when a recorded upgrade's file has changed (F1 outcome via another door); made reachable by editing 0004 in place, against DEV_NOTES rule 5. Reproduced.
2. Low - Free card can still be removed from /pricing: display order 0, or "Contact us"; trial setting accepts a plan with ended sale dates. Reproduced.
3. Low - deploy-gate test passes with the gate defeated (job continue-on-error true, step continue-on-error, `|| true`); zero test files passes the gate. Reproduced.
4. Low - pricing header says each plan states its promise when none does (before 0004). Reproduced over HTTP.
5. Low - databases that applied the 26 Nov 0004 keep draft price points and old wording forever, warning only; no note. Reproduced.
6. Info - ST_Collect() not on the aggregate list; fills an empty table on MySQL 8.0.36, validator accepts it.
7. Info - comment says seed_data.sql sets its own SQL mode; it does not.
8. Info - no `concurrency:` in deploy.yml (outside this range).

## Checked and sound
See the hand-back message.

## Could not check
- A real GitHub run of the new deploy job (used Linux Docker PHP 8.4/8.5 instead); PHP 8.6; MySQL 8.4 / MariaDB 10.11; real browser pass; Codex cross-review.
