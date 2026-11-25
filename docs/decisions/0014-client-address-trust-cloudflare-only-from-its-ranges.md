# 0014 — Working out the visitor's address: trust Cloudflare's header only from Cloudflare's ranges

- **Date:** 2026-11-25
- **Question (plain words):** Per-visitor limits (sign-in, resend link, API) need the visitor's real internet address. Behind Cloudflare, the address PHP sees is Cloudflare's, and the current code's way of reading the forwarded header can be faked by a visitor. Is the live site behind Cloudflare?
- **Answer (owner):** **Not sure: build the safe version for both and add a check to see on the live host.** Trust `CF-Connecting-IP` only when the connection really comes from a published Cloudflare range; otherwise use the connecting address; never trust the first entry of a visitor-writable forwarded header. Add a one-line check (admin overview or CLI) showing which address the site sees.
- **Rules out:** assuming Cloudflare; trusting forwarded headers from any source; exact-address-only trusted proxy lists.
- **Implemented in:** `_functions/fn_api_auth.php` `getClientIP()`, `trusted_proxy_ips` setting (now accepts ranges), admin overview check, tests. Phase 1B review finding I3.
- **Supersedes:** nothing.
- **Issue:** #31.
