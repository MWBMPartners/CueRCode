# 0043 — Starting limits and keeping periods chosen by the lead

- **Date:** 2026-11-28
- **Question (plain words):** Fixing the third review needed starting values that no earlier decision gives. Which should they be?
- **Answer (lead; the owner may overrule; each is a Global Admin setting unless said):**
  1. The sign-in route for visitors without JavaScript checks at most **10 wrong passwords per account in 24 hours**; wrong passwords there no longer count towards the account's lock-out.
  2. **Error log entries are deleted after 90 days.**
  3. **Visitor internet address and browser name are cleared from activity entries after 90 days**; the entries themselves stay. The admin change log keeps administrators' addresses with no time limit (not a setting).
  4. Email addresses in the activity log follow the existing email-record periods (30 days; 90 for failed) and are masked when an account is erased.
  5. The per-address limits count an IPv6 address as its /64 network (not a setting).
- **Rules out:** keeping the error log or visitor details for ever.
- **Implemented in:** 93eba50, bba4e0a, 0614986, 39220cd.
- **Supersedes:** nothing.
- **Issue:** #179, #129, #163, #177.
