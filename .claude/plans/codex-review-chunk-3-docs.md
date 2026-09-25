You are reviewing changes that Claude Code made to the CueRCode repository: a PHP 8.4+/MySQL service that makes QR codes. You did not write them, and other Claude agents have already reviewed them, so look for what they MISSED. This is read-only: do not edit any files.

YOUR ALLOWANCE IS SMALL, so work economically:
- Read only what is named below. Use `git diff origin/alpha...origin/work-in-progress -- <file>` for one file at a time. Never print the whole diff.
- **Write each finding down the moment you are sure of it**, then carry on.

SCOPE (chunk 3 of 3), branch `work-in-progress`:

1. Developer-portal pages and the API specification. Check each claim against the API code (`web/CueRCode.net/public_html/api/v1/index.php` is the route table, and the handlers are in `web/CueRCode.net/_functions/fn_api_*.php`).
   - The pages are `web/CueRCode.net/public_html/developers/authentication/index.php`, `developers/samples/index.php` and `developers/recipes/index.php`.
   - The specification is `web/CueRCode.net/public_html/api/v1/openapi.json`. Its description text is on line 5.
   - Look at API-key scopes, which routes need a scope, how saved-code ownership and public codes work, what `/qrcodes/{uuid}/destination` expects, the key limit and key rotation, and the 403 error codes.

2. `web/CueRCode.net/public_html/api/docs/index.php` (#111). It loads Swagger UI from jsDelivr with SRI hashes and falls back to the copy in `public_html/assets/vendor/swagger-ui/` (do not review that folder). It also has a `<noscript>` link. The site's Content-Security-Policy is in `public_html/.htaccess`.

3. #117: every `imagedestroy()`/`curl_close()` call was removed (`git show eafb22a -- web tests`). Check that no control flow changed and that `unset()` is used correctly.

4. Documentation claims that are FALSE when checked against the code. Read the top 5 entries of `CHANGELOG.md` and the email and deploy sections of `README.md`, `DEV_NOTES.md` and `SECURITY.md`.

Project rules to check:
- Web addresses never end in `.php`.
- PHP files start with a header that has their path and the "MWBM Partners Ltd" copyright notice.
- The owner's real name never appears; only the GitHub username Salem874.

Ignore style and wording preferences.

OUTPUT: numbered findings (real defects only), or "NO FINDINGS". Finish with one line listing what you actually read.
