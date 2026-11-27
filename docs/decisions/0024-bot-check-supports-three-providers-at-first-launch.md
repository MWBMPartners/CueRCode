# 0024 — The first launch has a human check, supporting all three providers

- **Date:** 2026-11-27
- **Question (plain words):** Must the first launch check that a visitor is a real person rather than a script?
- **Answer (owner):** **Yes: build it, supporting Cloudflare Turnstile, Google reCAPTCHA and hCaptcha**, as the original brief describes. It stays off until keys are entered in the admin screen.
- **Rules out:** launching with only the rate limits; supporting Turnstile alone.
- **Implemented in:** not yet built.
- **Supersedes:** nothing.
- **Issue:** #163.
