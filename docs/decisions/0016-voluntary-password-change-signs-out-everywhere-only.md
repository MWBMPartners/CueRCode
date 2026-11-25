# 0016 — A voluntary password change signs out everywhere and does nothing more

- **Date:** 2026-11-25
- **Question (plain words):** When a signed-in person changes their own password on purpose, how much should the site clean up afterwards? (After a "Forgot password" reset it also switches off API keys and removes linked Google/Microsoft sign-ins, decision 0013.)
- **Answer (owner):** **Sign out everywhere, nothing else.** A voluntary change ends every other sign-in, including any sign-in still in progress, and keeps the current one signed in. API keys and linked Google/Microsoft sign-ins stay.
- **Rules out:** the full 0013 clean-up on a voluntary change; a tick-box choice.
- **Implemented in:** `changeUserPassword()` in `_functions/fn_api_account.php` (one transaction; lock account then session; password checked under lock; write conditional on the checked hash; sign-in mark moved and the kept session's copy updated). Final review finding I-1.
- **Supersedes:** nothing (limits the scope of 0013).
- **Issue:** #31.
