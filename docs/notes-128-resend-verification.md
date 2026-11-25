# Notes for #128 - "send me a new verification link"

For the person who next updates DEV_NOTES.md, CHANGELOG.md and `.claude/HANDOFF.md`
(this file exists so those shared files were not edited in parallel).

## CHANGELOG line (suggested)
- Added a "Send me a new verification link" page (`/resend-verification/`),
  linked from the verify-email page and the sign-in page (#128). Same answer for
  every address; limits per account (60 s gap, 5 per day) and per IP (10 per hour),
  all settings with defaults; earlier unused links stop working.

## DEV_NOTES points
- One token path: `issueEmailVerificationLink()` in `fn_auth.php` is used by
  `registerUser()` and `requestNewVerificationLink()`.
- Settings (group `security`, NOT seeded - defaults live in code, clamped):
  `verification_resend_min_gap_seconds` (60), `verification_resend_daily_cap` (5),
  `verification_resend_ip_per_hour` (10). Seed rows/admin UI can be added later.
- The per-IP count reads `tblAPIRateLog` rows whose `endpoint` is
  `page/resend-verification`, written with `recordAPIRequest()`. It does not use
  `checkRateLimit()` because that counts ALL anonymous API traffic from an IP.
- `fn_api_auth.php` is loaded lazily by `requestNewVerificationLink()` and by the
  page (a test pins that it is not loaded globally).
- Existing quirk found, not changed: queueEmail() writes an `email_queued` activity
  row that names the recipient (design of the email audit trail). Other pages
  (login, forgot-password) call `getClientIP()` behind `function_exists()` but it is
  never loaded on those pages, so they use REMOTE_ADDR.
- No bot-protection widget: login/register/forgot-password have none.
- Known limits: two simultaneous requests could both pass the per-account gap; an
  older queued-but-unsent verification email would arrive with a now-dead link;
  a waiting account takes a few ms longer than an unknown address.
- No API route added, so OpenAPI is unchanged. The issue also asked for a JSON
  route "optionally"; left for an owner decision.

## Tests
`tests/test_resend_verification.php` (45 checks, in `php tests/run.php`);
`tests/integration/resend_verification_real_mysql.php` (7 scenarios, run on
mysql:8.4 in Docker, all passed).
