# Codex review, second round (HEAD 87ecdae, 2026-11-28 about 04:55)

Model: the account default, effort high, read-only, three pieces. No tests run by Codex.

## human check and outage rule

Reviewed committed content from `26799e9` through starting HEAD `87ecdaea5a071a3430e9e2beb93651c5dc34dc7b`.

- **P1 — The outage rule can refuse sign-in contrary to decision 0037.** [fn_human_check.php:411](web/CueRCode.net/_functions/fn_human_check.php:411) offers a `refuse` setting, and line 1416 applies it. If an admin selects it and every provider is down, normal sign-in is refused, although decision 0037 explicitly rules that out. Remove that option and apply the recorded sign-in rule; migrate any saved `refuse` value. [tests/test_human_check_fallbacks.php:162](tests/test_human_check_fallbacks.php:162) incorrectly presents this option as part of decision 0037.

- **P1 — Selecting a provider prevents fallback to other configured providers.** [fn_human_check.php:366](web/CueRCode.net/_functions/fn_human_check.php:366) puts only the selected provider in the plan. For example, with Turnstile selected and reCAPTCHA keys also saved, a Turnstile outage never tries reCAPTCHA; sign-in can instead pass without a check. Decision 0037 requires trying the other configured providers first. Treat the selection as the preferred provider and append the other usable providers.

- **P2 — A late provider callback can stop fallback loading.** [cuercode-human-check.js:288](web/CueRCode.net/public_html/assets/js/cuercode-human-check.js:288) clears the shared timer for whichever provider is currently loading. If the first script times out, the second starts, and the first then calls back late, it cancels the second provider’s timeout. If the second never calls back, the next fallback is never tried. Track each load separately and ignore callbacks and errors from superseded loads.

- **P2 — An outage sign-in can proceed without the required activity record.** [fn_human_check.php:1489](web/CueRCode.net/_functions/fn_human_check.php:1489) silently skips the attempt record if the log throttle cannot be written, and the logging callback does not confirm that the activity row was saved. The check still returns an allowed result. A logging failure can therefore leave an outage sign-in unrecorded, contrary to decision 0037. Confirm a record is available before allowing the bypass, and handle logging failure explicitly.

- **P3 — Both decision documents have stale implementation status.** [Decision 0024:7](docs/decisions/0024-bot-check-supports-three-providers-at-first-launch.md:7) and [decision 0037:7](docs/decisions/0037-human-check-outage-falls-back-to-other-providers-then-sign-in-only.md:7) say “not yet built”, although the committed implementation exists. Update their status with the implementing commits, while recording the remaining faults above.

**NOT RUN:** Automated tests, database tests, browser tests and live provider checks. Running the working-tree tests would not verify committed content alone, and this review was read-only.

## no-JavaScript route

Reviewed committed HEAD `87ecdaea5a071a3430e9e2beb93651c5dc34dc7b`.

- **Medium — Targeted lockout of no-JavaScript sign-in.** [fn_no_script_route.php](web/CueRCode.net/_functions/fn_no_script_route.php:625) reserves a daily password-check allowance by email address. An attacker using two source addresses can submit the default ten wrong passwords for a victim; subsequent correct passwords are checked only against a dummy hash for up to 24 hours. Repeating this keeps the victim off the email-confirmed route. Use an abuse control that does not let unauthenticated requests exhaust the victim’s ability to authenticate, such as an email-first challenge before password verification, while retaining limits on guesses.

- **Medium — Unauthenticated requests can exhaust a victim’s email allowance.** [fn_no_script_route.php](web/CueRCode.net/_functions/fn_no_script_route.php:515) shares one daily mailbox allowance across all four forms. An attacker can request three password-reset emails for a known account, spaced past the existing per-account minimum interval. The victim then cannot receive a no-JavaScript sign-in or recovery email until an allowance expires. Separate protection against email flooding from the ability to recover or sign in, and provide a recovery path that an unauthenticated sender cannot exhaust.

- **Medium — Distributed requests can disable a whole fallback form.** [fn_no_script_route.php](web/CueRCode.net/_functions/fn_no_script_route.php:516) counts emails against a site-wide, per-form hourly ceiling. With the defaults, requests from six source addresses can register 30 arbitrary new email addresses and exhaust sign-up for every no-JavaScript visitor for the remainder of the hour; repeating the flood sustains the denial. Introduce a fairer admission mechanism that reserves capacity for legitimate visitors without allowing unlimited email.

- **Low — Decision 0038 is stale.** [Decision 0038](docs/decisions/0038-no-javascript-visitors-get-an-email-confirmed-fallback.md:7) says the fallback is “not yet built”, but the committed account pages and confirmation route implement it. Update the implementation reference.

The claim in [test_no_script_route_review_fixes.php](tests/test_no_script_route_review_fixes.php:326) that the route can never lock an account out is misleading: it does avoid the ordinary account lock, but the first finding shows that it can deny access through the fallback itself.

**NOT RUN:** Tests, real-database or browser checks. The request required a read-only review of committed content, and the working tree is being edited by another process.

## pricing, currencies, installer

Reviewed committed content through `87ecdae`.

- **Medium — Free sign-up disappears when the price-list query fails.** In [fn_pricing.php](web/CueRCode.net/_functions/fn_pricing.php:244), a query failure marks every plan’s price unavailable. That makes the Free plan fail the `isZeroPrice` check, so [pricing_grid.php](web/CueRCode.net/_includes/pricing_grid.php:253) replaces its registration link with a disabled “Coming soon” button. The Free plan is expressly guaranteed to have no price. Handle it independently of the price-list query and retain its registration button during this failure.

- **Low — Upgrade 0006 can change other column properties.** The safety check in [fn_migrations.php](web/CueRCode.net/_functions/fn_migrations.php:1022) checks decimal width and nullability, but not the existing default, unsigned attribute or comment. For example, a narrower column with a custom default passes the check, then [upgrade 0006](web/CueRCode.net/_includes/sql/migrations/0006_three_decimal_money_columns.php:60) replaces that default with zero. Its statement at line 34 that nothing else changes is therefore untrue for such schemas. Preserve the existing properties or refuse columns whose properties differ from the expected schema.

- **Low — Two decision records claim implemented work is unbuilt.** [Decision 0041](docs/decisions/0041-free-plan-cannot-be-given-a-price.md:7) and [decision 0042](docs/decisions/0042-support-three-decimal-currencies-before-launch.md:7) both say “not yet built”, although the Free price refusal and three-decimal migration are in the reviewed commit. Update their implementation references.

**NOT RUN:** Unit tests, real-database tests and GitHub Actions. The review was read-only and used committed content; database and CI execution were not verified.

## Lead triage

| Finding | Action |
|---|---|
| The outage setting offers `refuse`, which decision 0037 rules out | Fix: remove it; a saved value means the 0037 rule |
| A selected provider stops fallback to the others | Fix: selected means tried first |
| A late provider script can cancel the next one's timer | Fix |
| Outage sign-in can happen with no activity record | Fix: no record, no bypass |
| Free card loses its sign-up button when prices cannot be read | Fix |
| Upgrade 0006 can drop a hand-set default, unsigned or comment | Fix: refuse a column that differs from the expected shape |
| Three ways to use up the no-JavaScript route's limits (one person's password checks, one person's emails, a whole form) | Owner question: a limit that stops floods can always be used up |
| Decision records say "not yet built" | Records are never edited; note the commits in the index |
