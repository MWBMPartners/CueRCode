# 0009 — Signed-out visitors to /admin/ are sent to sign-in; the gate is the protection

- **Date:** 2026-11-24
- **Question (plain words):** Should someone who is not signed in see the same "page not found" for `/admin/` as for any made-up address, or be sent to the sign-in page?
- **Answer:** **Sent to the sign-in page** (then back to the admin page after signing in). So the fact that `/admin/` exists is NOT secret. The protection is the gate itself (Global Admin role, active account, checked email, valid session, password re-typed for every change), not hiding the address. Accounts that are `pending` or `suspended` are also sent to sign-in, because the site treats them as not signed in; only an active, signed-in account that is not a Global Admin sees the ordinary "page not found".
- **Status:** **Decision by the lead, from the independent review of Phase 1B; the owner has not approved it and may overrule it** (a later record would then supersede this one).
- **Rules out:** treating "hiding the admin address" as a security measure; claiming in the docs that pending or suspended accounts get a 404.
- **Implemented in:** `requireGlobalAdminPage()` in `web/CueRCode.net/_functions/fn_admin.php`; wording in `DEV_NOTES.md` ("How the admin area protects itself"); a note on the Phase 1B acceptance line in `.claude/plans/7n-admin-commerce-plan.md`.
- **Supersedes:** nothing.
- **Issue:** #31.
