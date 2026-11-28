# Launch round 4: independent review of 35e87c2..d5dd065

Reviewer: Opus (independent; wrote none of this). 2026-11-28. Not committed, on purpose.
Reviewed from `git archive d5dd065` (scratch folder) and a scratch clone. The clone's HEAD was 550b1ed, which only adds docs on top of d5dd065 (decision 0043, the handoff and the decision index), so the code tested is d5dd065's. While this review ran, another session moved the repository to 91c5ace and was running its own `cuercode-it-wp4-*` containers. None of that is reviewed here, and none of it was touched.

Normative documents checked: decision 0043 (read from 550b1ed), 0038, 0040, 0041, 0042; `.claude/CLAUDE.md` code standards; `.claude/plans/launch-round3-review-report.md`.

## Result: no High. Two Medium, five Low, five Info.

In my judgement the branch is fit to go to the shared work-in-progress branch. It is not fit for the alpha pull request until Medium 1 is fixed. Medium 2 is older than this range and should be fixed at the same time. (Pushing still needs the owner's say-so under the workstation rules.)

## Evidence (all run during this review)
- Unit suite, macOS PHP 8.5.12: 9377 of 9377 pass (this matches tests/README). PHP 8.6 scan: 200 files, 0 findings. Polish check: 0 problems, 9 warnings.
- `tools/ci/run-integration-tests.sh`: all 9 scripts pass on Docker MySQL 8.0.36, MySQL 8.4.13 and MariaDB 10.6.28. site_timezone: 100 checks in five zones, including Pacific/Kiritimati (+14) and Pacific/Pago_Pago (-11). account_recovery: 203.
- Real browser: headless Microsoft Edge (Chromium), driven through its debugging port, with every host except 127.0.0.1 blocked, so nothing went to outside services. A Global Admin was signed in on PHP's built-in web server, using the repository's test router and MariaDB.
- 22 protections were removed one at a time in a scratch copy and run through the unit suite. Those that survived were then run through the matching real-database script on MySQL 8.0.36.

## Earlier findings (round 3), rerun now
- Medium 1 (route wrong passwords lock the account): closed. Scenario R passes over real HTTP on all three servers. In the reviewer's probe, 40+ wrong route posts left `failedLoginCount` at 0. A different way round the new limit is Medium 1 below.
- Low 2 (squash merge): closed. The d5dd065 tree committed on its own with no history: migrations script exit 0, "commit 76b1af7 is not in this copy's git history, so the comparison with it was skipped". With one byte added to the fixture it refuses (exit 2). In the clone the fixture is byte-identical to `git show 76b1af7:...install.sql`.
- Low 3: closed. Weakening the "already done" check (P1) and the Free-card rule (P2) both pass the unit suite but fail the real-database scripts (migrations P6, admin_plans N3b).
- Low 4 (guard): closed. On MariaDB, an account with only `cuercode\_it\_%` rights is refused (exit 2). Root with `cuercode_live` present is refused (exit 2). `--drop=cuercode_live` is refused (exit 2). A host that is not this machine is refused. A clean server is accepted.
- Info 4: closed. The runner removes each database afterwards. Info 3: still open at d5dd065 (0040-0042 still say "not yet built"). 91c5ace may address it; that commit was not reviewed.

## Findings

### MEDIUM 1: the new per-account limit on no-JavaScript password checks can be got round by typing the address a little differently
- Where: `_functions/fn_no_script_route.php` line 625 (the check place is counted against `noScriptEmailHash()` of the TYPED text, and `noScriptEmailCountingForm()` at line 306 only lower-cases it and folds +tags and Gmail dots). `_functions/fn_auth.php` lines 1923-1938 (`loginUserCheckPassword()` finds the account with `WHERE email = ?`). `tblUsers` uses `utf8mb4_unicode_ci`, which treats accented letters, zero-width spaces, control characters and full-width letters as equal to the plain letters. `/login/` does not check the address's format before the route.
- Reproduction (reviewer's `probe_variants.php`, through the real `noScriptSignInStartWork()`, MariaDB 10.6.28 and MySQL 8.4.13, same result on both). First the 10 daily checks for `alice@example.test` are used up, and the right password is then not checked (0 emails), as designed. Then each of `alicé@…`, `álice@…`, `al<U+200B>ice@…`, `al<0x01>ice@…` and full-width `ａlice@…` gets a fresh check. With the right password, each one queues a sign-in email to alice@example.test. 30 more wrong guesses, each with a different number of zero-width spaces, all got a password check against Alice's account (the database finds the account even with 30 of them). `failedLoginCount` stayed 0.
- Consequence: the limit that replaced the lock-out does not limit anything. Guessing one person's password through the route is limited only by the per-address limit (5 an hour per IPv4 address or IPv6 /64), so many addresses mean unlimited guesses, with no human check and no lock-out. The page never says whether a guess was right. But every right guess sends the real person a genuine "approve this sign-in" email. If they press approve, the guesser's browser is signed in when it presses Continue (scenario K's own flow).
- Fix: in `loginUserCheckPassword()`, after the SELECT, treat the row as "no such account" unless `hash_equals((string) $row['email'], $normalizedEmail)`. Keep the dummy-hash password check so the timing does not change. This closes the gap for every sign-in path at once. Do the same for the forgot-password and new-link lookups. Add a real-database check that `alicé@` and a zero-width spelling get no email and no check against the real account.

### MEDIUM 2 (older than this range): the offline worker keeps full copies of admin pages, and shows them after sign-out
- Where: `public_html/sw.js` line 113. `PRIVATE_PATH_PREFIXES` has no `/admin` (nor `/install`). Every successful page visit is saved into `cuercode-v4-pages`, whatever `Cache-Control: no-store` says.
- Reproduction (headless Edge): signed in as admin, visited the nine admin pages. `caches` then held `/admin/settings/`, `/admin/plans/`, `/admin/plans/new/`, `/admin/plans/edit/?id=3`, `/admin/plans/prices/?id=3`, `/admin/pricing-preview/`, `/admin/reauth/` and `/admin/upgrades/`; the cached `/admin/settings/` was 132,865 bytes with `cache-control: no-store`. Next, signed out with the real menu form. Online, `/admin/settings/` then sent the browser to `/login/`. With the web server stopped, the same address showed "Settings | CueRCode" with its form, from the cache. No sign-out step clears it (`Clear-Site-Data` and `caches.delete` appear nowhere outside sw.js).
- Consequence: on a shared or lost computer, the admin settings, plans and prices pages can be read after the admin has signed out. This breaks the promise in `admin/.htaccess` that browsers never keep a copy of an admin page. Version 3 of the worker was bumped to delete such copies of account pages, but admin pages were missed.
- Fix: add `'/admin'` and `'/install'` to `PRIVATE_PATH_PREFIXES`, and bump `CACHE_VERSION` so existing copies are deleted. Also consider sending `Clear-Site-Data: "cache"` on sign-out. Extend `tests/test_admin_csp.php` (or a worker test) to require it.

### LOW 1: ten posts a day keep a person without JavaScript from signing in at all
- Where: the same limit (`fn_no_script_route.php` line 625; decision 0043 item 1). Past 10 wrong checks in 24 hours, the right password is not checked either.
- Reproduction: scenario R (all three servers, passes) and the reviewer's probe. After 10 wrong posts (one address manages that in two hours at 5 an hour), the right password gives the same page and no email.
- Consequence: the route exists for people without JavaScript (decision 0038), and the normal form needs a human check, which needs JavaScript. So a stranger sending about one post every 2.4 hours keeps that person out for good. The admin help and DEV_NOTES say "nobody is locked out" and "the normal form is not affected". That is true only for people who have JavaScript. This is a lead decision (0043) that the owner may overrule, so it is reported, not presumed wrong.
- Fix (owner's choice): count wrong checks per mailbox AND per address (/64), with a larger per-mailbox ceiling, so that one stranger cannot use up the person's allowance. Either way, correct the "nobody is locked out" wording.

### LOW 2: the tighter admin policy still runs ANY file from cdn.jsdelivr.net
- Where: `public_html/admin/.htaccess` line 74 (`script-src 'self' https://cdn.jsdelivr.net`). `fn_admin.php` `adminSendSecurityHeaders()` says the same. `_includes/footer.php` line 151 loads jQuery with no integrity hash.
- Reproduction: a test page sent with the exact admin policy, in headless Edge. The inline script was refused ("Executing inline script violates ... script-src 'self' https://cdn.jsdelivr.net"). `https://cdn.jsdelivr.net/gh/someone-else/any-repo@main/payload.js` and `/npm/any-package-anyone-published@1.0.0/x.js` were NOT refused by the policy; they failed only because the reviewer blocked the network.
- Consequence: jsDelivr serves any public npm package or GitHub file. So anyone who can inject one `<script src>` tag into an admin page (the attack #154 is meant to stop) can still run their own code there. A compromised jQuery file on the CDN would also run, because it has no integrity hash.
- Fix: limit the source to the two exact paths, `https://cdn.jsdelivr.net/npm/jquery@3.7.1/` and `https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/` (CSP matches path prefixes), and add the jQuery integrity hash (F-004).

### LOW 3: the error log can still hold an email address, and live one-time links
- Where: `_functions/fn_error_handler.php` lines 498-499 mask only a literal `@` (`fn_log_mask.php` line 84). The page address and Referer are stored otherwise as they are.
- Reproduction (real `logError()`, MySQL 8.4.13): with `REQUEST_URI=/reset-password/?token=<64 hex>&email=alice%40example.test` and `Referer: https://webmail.example/?to=bob%40example.org`, the stored row holds both addresses in full and the whole token.
- Consequence: an address written as `%40` (which is how browsers send form fields in an address) is stored in clear for 90 days, although the privacy page (`privacy.php` line 451) now says the error log holds "never your email address". A fault on the reset, verify-email or login/confirm page stores a one-time link that may still be live, readable by anyone with access to `tblErrorLog`. The token part is not new in this range.
- Fix: before storing, decode the page address and Referer (or mask `%40` as well), and replace the values of `token` (and `email`) query parameters with a placeholder.

### LOW 4: the visitor-details clean-up re-reads every old row on every run
- Where: `_functions/fn_email.php` line 4057, `clearOldActivityVisitorDetails()`: `WHERE createdAt < cutoff AND (requestIP IS NOT NULL OR userAgent IS NOT NULL) LIMIT 500`.
- Reproduction (MySQL 8.0.36, 1,200,001 activity rows, 1,000,000 of them old and already cleared): EXPLAIN shows a range scan of about 598,800 rows. Two runs took 2.62 s and 1.63 s, each to change 1 row (then 0). The other two new clean-ups took under 1 ms.
- Consequence: the cost grows with every row ever cleared, and it is paid on every queue run. That includes the end-of-page run after a page queues an email (`maybeProcessEmailQueueOnShutdown()`), which delays that visitor's page wherever `fastcgi_finish_request()` is missing. On InnoDB's default isolation level the scan also locks the rows it walks for that time.
- Fix: keep a high-water mark (the largest `activityID` already cleared) in a setting and scan only above it, or clear by `activityID` ranges below the first row newer than the cutoff.

### LOW 5: one of the new protections is not tested by anything
- `fn_no_script_route.php` line 681, "a right password gives its check place back". Replaced with `if (false)`: unit suite 9377/9377 and `human_check_real_mysql.php` exit 0 with no FAIL. In that broken state, a person's own successful no-JavaScript sign-ins would count as wrong passwords, and after 10 in a day they could not sign in through the route. Fix: in scenario R or P, sign in correctly 11 times through the route (with the email limits raised) and expect an email on the 11th.
- Everything else removed was caught: route counting (A1), the stand-in check (A4), the per-account limit (A2, real database only), erasure of activity entries (E1, E2) and of finished queue records (E3, real database only), the three clean-up periods (R1-R3, real database only), error-log masking of the page address and headers (L1, L2, real database only), /64 counting (I1, I2), expiry in SQL (T1-T3, by the source scan), account dates (T4), and the server supervisor (W1).

## Info
- I1: `tests/lib/web_server.php` stops the server when the TEST process dies, kill -9 included (reproduced: no answer on the port 2 s after `kill -9` or `kill -15`). But if the SUPERVISOR itself is killed with -9, or the 10-second stop gives up and kills it (line 100), the PHP server is left running for good (reproduced: still answering after the test process had also exited). Rare. The fix would be to also kill the server's own process number from the test process.
- I2: `tests/test_admin_csp.php` line 55 knows only some event attributes. `ondblclick`, `onpointerdown`, `onanimationstart` and `oncontextmenu` added to an admin page all pass; `onclick` fails. The browser would refuse them, so the effect is a silently dead button, not a security hole. Fix: match `\son[a-z]+\s*=`.
- I3: `email_queue_real_mysql.php` line 934. The "admin change log is not touched by the clean-up" check never runs in CI (the script loads install.sql only, so the table never exists; "note ..." was printed on all three servers). Only the unit test's text check covers it.
- I4: all three clean-ups run only from the email queue (cron, or after a page queues an email once Graph is set up). If cron is not set up, the 90-day promises on the privacy page are not kept. INSTALL requires the cron job, so this needs only a sentence in the privacy or installation notes.
- I5: the erasure query on `tblEmailQueue` (`fn_api_account.php` line 2129) uses the same accent-blind comparison, so it would also anonymise records for an address that differs only by an accent. The activity-log step guards against this in PHP; the queue step does not. Harmless in practice (the unique key stops two such accounts existing). Not reproduced.

## What is sound (checked now)
- Expiry and lock-outs judged by the database: every remaining PHP time comparison found is session-held or Unix-time (zone-free). site_timezone passes in five zones on three servers. Removing any of the SQL checks fails the source scan.
- No-JavaScript route: the route no longer touches the lock-out. The bound is exact under concurrency (scenario J: 12 at once with the limit at 3, exactly 3, on all three servers). Over the bound, the stand-in check keeps the timing the same (A4 caught).
- Activity-log retention: only email entries are deleted (both filters must go before anything else is hit); a 400-day non-email row stays; the admin change log is never touched. Each statement is bounded by LIMIT 500.
- Erasure: the address is removed from sent, skipped and failed queue records and from activity entries (both "To:" and `{"to":...}`), inside the transaction. Other people's entries are left alone (E1 caught).
- Error log: title, detail, backtrace, page address, headers, browser name and extra data are masked for literal addresses, and it fails closed. Session ids are still hashed, and cookies and Authorization headers are still redacted.
- /64 counting: one rule, written and counted the same everywhere (request log, link limits, form limit, anonymous API, route). Mapped IPv4 is treated as IPv4. Only validated addresses reach it (`clientIpNormalise()` uses FILTER_VALIDATE_IP).
- Admin pages in a real browser: all nine admin pages ran with no policy violation, no inline script and no on...= attribute. jQuery and Bootstrap loaded from the local copies when the CDN was unreachable, and the theme script ran before paint. The offline worker precaches the five new script files.
- Account page dates: `siteFormatDatabaseTime()` reads UTC and refuses malformed values (T4 caught).
- CI scripts: the guard runs before anything else, the create and drop names are checked, a guard failure stops the run, and the ci.yml and deploy.yml changes are comments only.

## Could not check
- Apache itself (the `.htaccess` header lines): PHP's built-in server applies only the header the PHP code sends. The `.htaccess` policy text was checked separately, on a test page sent with exactly that header.
- The Linux PHP 8.4 container run (only macOS PHP 8.5.12 was used), PHP 8.6, DreamHost, real email, real human-check providers, browsers other than Chromium-based Edge.
- GitHub's runners and merge settings (nothing was sent to outside services).
- The Codex review (runs separately).
