# 0015 — The trusted-forwarding-servers setting refuses very wide ranges

- **Date:** 2026-11-25
- **Question (plain words):** The setting that lists which forwarding servers the site trusts to report a visitor's real address currently accepts "every address on the internet". How should it treat very wide ranges?
- **Answer (owner):** **Refuse ranges wider than a sensible size (wider than /8 for the older address style, or /32 for the newer style) with a plain-English message saying why.**
- **Rules out:** accepting wide ranges with only a warning; leaving it open.
- **Implemented in:** `_functions/fn_client_ip.php` range parser and the Phase 1C settings editor validator (`ip_ranges` kind), the visitor address check tool. Phase 1B/0014 review finding 8.
- **Supersedes:** nothing (refines 0014).
- **Issue:** #31.
