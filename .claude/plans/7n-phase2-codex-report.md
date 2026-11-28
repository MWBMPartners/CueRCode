# Codex review of 7n Phase 2, range c487dab..fe317f6 (2026-11-27)

Model gpt-6.1-terra (the account default; two stronger models were refused for this account), effort high, read-only, three pieces. Codex ran no database tests.

## Piece 1: money and admin screens

1. **Medium — Currency support is hard-coded.** [fn_money.php](web/CueRCode.net/_functions/fn_money.php:86) recognizes only GBP, USD, EUR and JPY. A Global Admin cannot offer a valid currency such as CAD without a code change, despite the project requirement that prices in any currency be admin-editable. Store currency definitions as editable data and support their required decimal precision throughout the money and database handling. [test_money.php](tests/test_money.php:32) enshrines the four-currency restriction.

2. **Medium — An incomplete price request clears sale dates.** [fn_admin_plans.php](web/CueRCode.net/_functions/fn_admin_plans.php:1282) treats omitted date fields as empty, then [writes the resulting null dates](web/CueRCode.net/_functions/fn_admin_plans.php:2764) to every active price in the group. For example, a valid hand-made request containing the unchanged amounts but omitting the date fields can put a future price on sale immediately or extend an expiring offer indefinitely. Reject missing date fields or preserve their existing values; require explicitly submitted empty fields to clear them.

The money and admin-plan unit tests passed on PHP 8.5: **757 assertions**. **NOT RUN:** real MySQL integration and race tests, browser checks, and tests on PHP 8.4 or 8.6.

## Piece 2: upgrade 0004 and installer

- **[P1] An unavailable upgrade can leave a fresh install incomplete while the installer reports success.** [fn_migrations.php](web/CueRCode.net/_functions/fn_migrations.php:998), [install/index.php](web/CueRCode.net/public_html/install/index.php:530). If the migrate command applied 0004 before seed data existed, and its file is subsequently missing or cannot be validated, replay skips it. The installer loads the seed, warns, and can still lock itself as successful. The Enterprise plan and other 0004 data changes are then missing, while the ledger says 0004 was applied. Refuse to seed or finish whenever an applied upgrade cannot be replayed; require its matching file or an explicit repair.

- **[P2] Description updates can overwrite an owner’s edits.** [0004_plans_prices_currencies.php](web/CueRCode.net/_includes/sql/migrations/0004_plans_prices_currencies.php:394). The comparisons use the table’s `utf8mb4_unicode_ci` collation, which ignores case and accents. An owner’s edited description differing from the seed only in case or accents can therefore match and be replaced. Use a byte-exact comparison for both description updates. The file’s claim that only *exactly* unchanged descriptions are replaced is untrue.

**NOT RUN:** Database integration tests and installer execution. They write to databases, and this review was restricted to read-only work.

## Piece 3: pricing page, guard test, workflows

Reviewed `c487dab..fe317f6`. Findings:

1. **High — Upload can proceed after PHP 8.6 tests fail.** [deploy.yml](.github/workflows/deploy.yml:130) allows the 8.6 test job to fail, so the upload can proceed if 8.4 and 8.5 pass. Decision 0025 explicitly makes 8.6 report-only, but its statement that it rules out uploading code whose tests fail is therefore too broad. To require *all* supported-version tests to pass, remove the 8.6 exception; otherwise correct the document’s claim.

2. **High — Database integration test failures cannot block upload.** [run-tests.sh](tools/ci/run-tests.sh:48) runs `tests/run.php`, which discovers only top-level `tests/test_*.php`. The real-database tests under `tests/integration/` are excluded. A failing pricing or migration integration test can therefore coexist with a successful upload. The script’s “full test suite” claim is inaccurate; [tests/README.md](tests/README.md:397) correctly identifies those tests as excluded. Run the applicable integration tests against disposable databases in the deploy gate, or describe the gate as a partial suite.

3. **High — Enabled price points can override the current group price with a different offer.** [fn_pricing.php](web/CueRCode.net/_functions/fn_pricing.php:1707) first calculates current prices by price group, then, when `billing_price_points_enabled` is on, replaces them with a price selected by billing interval and `isDefault`. If two active monthly offers have different group ordering or defaults, the public card can show a different amount from the group selection and the admin preview. Use the same group-based selection for the public card and preview, including sale dates and currency.

4. **Medium — The preview drops valid legacy prices.** [fn_pricing.php](web/CueRCode.net/_functions/fn_pricing.php:880) clears a price whenever the preview has no selected price-point row. A plan priced through its older monthly or yearly column in the selected currency can therefore show a figure publicly but “No price … yet” in the preview. Preserve the legacy column when that group has no price-point rows and its currency matches, as the public selection does.

5. **Medium — A price-list read failure can show stale prices.** [fn_pricing.php](web/CueRCode.net/_functions/fn_pricing.php:233) catches a failed price-list query and leaves the older mirrored prices in place. If a sale has since ended or started, visitors can see the outdated figure; a no-payment plan can also be labelled free despite having a current price point that could not be read. Show an unavailable-price state when current price data cannot be read, while logging the failure.

6. **Low — The commercial-value guard does not guard all commercial values.** [test_commercial_literals.php](tests/test_commercial_literals.php:60) checks a fixed list of starting prices and plan keys. A newly hard-coded amount such as `12.50` passes, despite the file’s title claiming a project-wide guard against commercial values in code. Expand the check to cover price-bearing output and code paths, and narrow the claim to what the test can actually prove.

**NOT RUN:** Tests, real-database checks, browser checks and GitHub Actions. The review was read-only; the test gate creates temporary files, and no live deployment was attempted.

## Lead triage

| Ref | Verdict (from reading; the builder reproduces each) | Action |
|---|---|---|
| 1.1 four currencies only | Fix | Accept every ISO 4217 currency with its standard decimal places (reference data, not a commercial rule); the admin still chooses which are offered |
| 1.2 missing date fields clear dates | Fix | Refuse a request without them |
| 2.P1 applied upgrade cannot be replayed | Fix | Installer refuses to load starting data or finish |
| 2.P2 case-blind description match | Fix | Byte-exact comparison |
| 3.1 PHP 8.6 may fail | By design | Decisions 0005 and 0025: 8.6 is report-only |
| 3.2 database tests not in the gate | Wording fix now; owner question | Documents must say the gate excludes `tests/integration/` |
| 3.3 price points override group price | Fix | One selection rule for page and preview |
| 3.4 preview drops old-column prices | Fix | Match the public selection |
| 3.5 read failure shows stale or Free | Fix | Show price unavailable, never Free; log it |
| 3.6 guard claims too much | Wording fix | Narrow the claim |
