# 0006 — PHP 8.6 adoption: what was adopted, what was skipped, and why

- **Date:** 2026-11-18
- **Question (plain words):** Decision 0004 said to use PHP 8.6 features where safe. Which ones are safe, and where?
- **Answer:** Adopt **one** feature: `clamp()` (keeping a number between a lowest and a highest allowed value), through a small helper `cuercodeClampInt()` in `web/CueRCode.net/_functions/fn_compat.php`. On PHP 8.6 it calls the built-in `clamp()`; on 8.4 and 8.5 it uses the old `max(min())`. It takes whole numbers only, and if the two limits are the wrong way round it returns the lower limit on every version (the built-in would throw an error). It replaces 35 whole-number sites. Everything else is skipped, for these reasons:
  - **Decimal clamp at `fn_email.php` (time budget):** skipped. Given "not a number", the old code gives 600.0 and 8.6's `clamp()` gives NaN.
  - **`ZipArchive::openString` / `closeString` (bulk upload):** skipped. It works, but a 25 MB archive peaked at 78 MB of memory against 50 MB through a temporary file; with the 128 MB limit, large batches could fail on 8.6 only.
  - **New `Uri` additions:** skipped. The URL checks in `fn_redirects.php` and `fn_validation.php` are security decisions, which must not branch on PHP version, and the output bytes must not change.
  - **`Time\Duration`, `ARRAY_FILTER_USE_VALUE`, `grapheme_strrev`, `quote_string`:** skipped. No use in the code (no `DateInterval`; the constant equals the default; one `strrev` on plain digits; prepared statements only).
  - **Sodium ipcrypt / XOF / KEM, `SpoofChecker`:** skipped. Stored data would be unreadable on 8.4, and these are security choices.
  - **New syntax (partial application, readonly defaults and so on):** skipped. 8.4 and 8.5 cannot read it, so no guard is possible.
- **Rules out:** a global `clamp()` stand-in function (it would take a name PHP now owns and would have to copy `clamp()`'s different number and NaN rules); using `clamp()` directly with no fallback; branching stored data or security on PHP version.
- **Implemented in:** `web/CueRCode.net/_functions/fn_compat.php`; the 35 sites in `fn_activity_log.php`, `fn_api_generate.php`, `fn_auth.php`, `fn_barcode_generate.php`, `fn_email.php`, `fn_qr_frames.php`, `fn_qr_logo.php`, `fn_qr_options.php`; loaded in `_includes/bootstrap.php`; tests in `tests/test_php86_adoption.php`; scanner rules in `tools/php86-compat-scan.php` (calls to 8.6-only functions and methods, and a global `function clamp(`).
- **Left open (owner questions, no code changed):** (1) bad-JSON error text gains a location on 8.6 ("near location 1:8"); the code `INVALID_JSON` and status 400 are the same everywhere. (2) On 8.6, `trim` also strips form feeds (243 calls use the default list). Each answer will be its own record.
- **Supersedes:** nothing. It implements 0004 (which superseded 0003).
- **Issue:** #151.
