# 0013 — Closing the "someone signed up first with my email" trick

- **Date:** 2026-11-25
- **Question (plain words):** A stranger can sign up first with someone else's email address. The real person then uses the "send me a new link" page, clicks the link, and the account becomes active with the stranger's password; the stranger can link a Google/Microsoft sign-in and make an API key that survive a later "Forgot password". How do we close it?
- **Answer (owner):** **Both parts.** (1) Clicking a verification link asks for the account's password; a person who did not choose that password is sent to "Forgot password". (2) "Forgot password" can also activate a waiting account, and a successful reset ends all sessions, switches off API keys, removes linked Google/Microsoft sign-ins and passkeys, and cancels other unused links.
- **Rules out:** one-click verification for an account with a password; a reset that only ends sessions; accepting the risk.
- **Implemented in:** `_functions/fn_auth.php` (`verifyEmailToken`, `resetPasswordWithToken`), `public_html/verify-email/`, `public_html/reset-password/`, tests. Builds on decision 0010 (first admin) and the Phase 1B review (findings I1, I2).
- **Supersedes:** nothing.
- **Issue:** #31 / #128.
