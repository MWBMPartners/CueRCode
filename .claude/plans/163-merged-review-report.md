# Independent security review: 66e0591..c95d1a5 (human check, #163; 7n recheck fixes)

Reviewer: Opus (independent, wrote none of this code). 2026-11-28. Not committed (on purpose).
Reviewed from a private clone at c95d1a5 in the reviewer's scratch folder. The working copy was not touched except for this file.
Normative documents checked: decisions 0024, 0037, 0038 (and 0013, 0014, 0016 where the route relies on them); `.claude/CLAUDE.md` code standards; the earlier review `163-review-report.md`.

## Status: complete. No High. One Medium, six Low, five Info.

I found no way to sign in as another person, to make a provider look "down" from outside, or to get anything except sign-in through the outage rule. The weak points are all in the no-JavaScript route (decision 0038): it is a way past the human check for every form, by design, and its limits are easier to abuse than the documents say.

## Evidence base (all run now)
- Unit suite: 8377/8377 (and 8378/8378 on a second run), PHP 8.5.12. `tools/ci/run-tests.sh`: "54 test file(s)". PHP 8.6 scan: 0 findings. Polish check: 0 problems.
- `human_check_real_mysql.php`: 115 ok, 0 FAIL, all 12 scenarios PASS on MySQL 8.0.36 and on MariaDB 10.6.28.
- `migrations_real_mysql.php`: 195 ok, 0 FAIL on both (scenario O: 0005 on a 0004 database, run again, owner choices kept).
- Reviewer probe (P1 to P8) over real HTTP against the real pages, on both databases, with curl switched off in the web server (no provider contacted). Results were the same on both.
- 18 mutations of the protections, each run through the unit suite and the real-database run.

## Findings

### M1 (Medium): the no-JavaScript sign-in tells a script whether a password is right, without any human check
- `_functions/fn_no_script_route.php` `noScriptSignInStartWork()` lines 445-451 and 505; `public_html/login/index.php` lines 164-178.
- How to reproduce: POST `/login/` with `human_check_email_route=1` and no token. A wrong password gets "Invalid email or password..." and a right one gets the "We have emailed you a link" box. Real-database run K shows both: "wrong password: the usual 'invalid' answer" and "right password: 'check your email'". You do not need the mailbox to see the difference.
- What it means: the human check exists mainly to stop scripts trying leaked email and password pairs. Through this route a script can test pairs with no check at all, up to the route limits. That is 30 an hour for the whole site, or about 720 a day, using many addresses (and IPv6 makes many addresses cheap). It then needs to solve only one check to sign in with a pair it has confirmed. The victim does get an email saying "someone typed the correct password", which helps.
- Fix: give the same answer whatever the password ("If the email and password are right, we have emailed you a link"). Always store a browser secret in the session, a dummy one when the password is wrong, so the waiting box and the Continue button look the same. Keep counting the attempt as now.

### L1 (Low): anyone can shut the no-JavaScript route for the whole site, at no cost, for as long as they like
- `fn_no_script_route.php` `noScriptRouteGate()` lines 288-312. The site-wide count includes every use that passed the gate, including forgot-password requests for addresses with no account.
- How to reproduce (P2): 5 forgot-password route posts each from 6 visitor addresses, all for made-up addresses, send 0 emails and use all 30 places for the hour. The real person (right password, a different address) then gets "Too many sign-in emails have been asked for just now, from your connection or for this address". Repeat every hour.
- Fix: count a sign-in against the per-email and site-wide limits only after the password was right, and count a forgot-password or new-link request only when an email was actually queued. Keep the per-address count before the password. Say "the site is busy" when the site-wide limit or the lock stopped it.

### L2 (Low): anyone can block one person's no-JavaScript sign-in for 24 hours and add to their wrong-password count, without a check
- Same gate. The per-email count runs before the password is looked at (that is on purpose), so a stranger can spend a victim's daily allowance.
- How to reproduce (P3): 3 route sign-ins with the victim's address and guessed passwords, from 3 addresses. The victim's own sign-in with the right password is refused ("limited") for 24 hours, and `failedLoginCount` goes up by 3 with no human check. The lock-out threshold is 5 and the count never goes down by itself. So 3 guesses a day through this route, plus the normal form, keep someone locked out for 15 minutes again and again.
- Fix: the same as L1 for sign-in (per-email count only after a right password). Consider letting a correct password through the per-email limit.

### L3 (Low): one inbox can be sent about 30 verification emails an hour through "+tag" spellings
- `noScriptEmailHash()` line 223 counts the address exactly as typed (only lower case and trimmed). `register/index.php` line 120 onwards.
- How to reproduce (P8): sign-ups through the route for `target+1@...` to `target+30@...` from 6 addresses queued 30 verification emails to what is one real mailbox. The per-address limit is 3 a day.
- What it means: email bombing of one person, and harm to the site's sending reputation, without any human check.
- Fix: count the hash of a standard form of the address (remove the `+tag`, and for Gmail the dots), or count per mail domain as well. Or set a tighter site-wide limit for sign-ups on this route.

### L4 (Low): when the saved keys cannot be read ("broken"), the forms are said to be refused, but a hand-made route post still works
- `fn_human_check.php` `humanCheckIsOn()` lines 662-669 returns true when broken. `humanCheckWidgetHtml()` line 1957 draws nothing, so people see no route button, but `login/index.php` line 164, `register/index.php` line 120, `forgot-password/index.php` line 80 and `resend-verification/index.php` line 78 still honour `human_check_email_route=1`.
- How to reproduce (P4): spoil both stored secrets. The plan state is "broken", the normal register form is refused and makes no account, and the page has no route button. A hand-made route post makes an account, and a route sign-in sends an email.
- What it means: the documents (file header, DEV_NOTES "if none is readable every protected form is refused", the admin status line "Refusing every protected form") are wrong. Scripts keep a way in that people do not have. The limits still apply.
- Fix: either refuse the route when the plan is "broken" too, or draw the route button in that state and correct the wording. I recommend the second (people without JavaScript, and everyone else, keep a way in while the keys are being fixed).

### L5 (Low): tests pass with important parts of the route removed
Each line below is a mutation in a scratch copy, run through the whole unit suite and the real-database run.
- The browser check made to compare nothing (`noScriptBindingMatches()` line 644: `return true;` in place of the `hash_equals`): unit 8377/8377, real-database run all PASS. In that broken state, any browser with ANY email sign-in waiting (for example a stranger's own account) is signed in as the victim by opening the victim's link (links are in the web server's access log, which DEV_NOTES accepts only because a link "can only approve"). No test opens a link in a browser that has its own different sign-in waiting.
- The sign-up, forgot-password and new-link route ignoring the gate's answer (`register` line 127, `forgot-password` line 86, `resend-verification` line 84): unit and real-database runs all pass. This would make the route an unlimited way past the check for sign-ups and emails. The unit test only checks that the text `noScriptRouteGate('register'` appears.
- The confirm page's security-token check: only that same "text appears" check catches its removal. A version that keeps the call but ignores the result would pass. The real-database run does not post without the token (my probe P6 did, and was refused).
- The minimum answer time on the route sign-in removed: not caught. The status guard in `noScriptSignInFinish()` removed: not caught (another guard covers it).
- Caught, for the record: login gate removed (real run K), "sessions valid from" compare removed (K), account-valid ignored (K), outage for every form (unit and real run), token record ignored (real run H only), probe 4xx counted as down (unit), "any provider down = all down" (unit and real run), Continue accepting "waiting" (unit only).
- The real-database scripts are not run by CI (`.github/workflows` has no database service), so the protections caught only there (token record, binding) are guarded only by hand.
- Fix: in `human_check_real_mysql.php` K and J, add (a) a stranger with their own waiting sign-in opens the victim's link: not signed in; (b) the 6th route sign-up, forgot-password and new-link post from one address: no account or email; (c) a confirm POST without, and with a wrong, security token: refused. Change the static checks to look for the gate's result being used, not just the name. Consider a CI job with a MySQL service.

### L6 (Low): approving from another browser leaves the first browser one click from signed in for up to 15 minutes, and the page gives the approver nothing to judge by
- `login/confirm/index.php` lines 147-158; `noScriptSignInApprove()` lines 677-686; `noScriptSignInContinue()` lines 721-759.
- Judgment on "approve from another browser": it is safe against taking over an account. A password is needed first, and anyone with the password could sign in through the normal form anyway (with one human check), so the approval gives an attacker nothing new. The email also warns plainly. Two risks are left. (1) Someone starts on a shared or library computer, approves on their phone and does not go back: anyone at that computer who presses Continue within 15 minutes is in (real run K shows Continue signs in after an approval made elsewhere). (2) The approve page does not show when, from where or from what browser the request came, although `requestIP` and `userAgent` are stored.
- Fix (keep the feature): show the time and the device on the approve page, and let an approval expire after about 3 minutes unless Continue is pressed. Removing it is also reasonable: a person without JavaScript is usually on one device, and opening the link on that device already works.

### Info
- I1: "Start again" leaves the request `waiting` (the `cancelled` status is never set). That browser's own link then says "This is not the browser where the password was typed" and offers Approve (P6). It is harmless, but the wording is wrong. Set `cancelled` on "Start again".
- I2: the route's "limited" wording says "from your connection or for this address" also when the site-wide limit or a busy lock stopped it (P2, P7: 3.01 s wait, then refused).
- I3: route posts skip the per-visitor form limit, and every route post waits for the lock (up to 3 s) and then pads to 0.4 s. A flood of route posts holds PHP workers for longer than refused normal posts do. Consider failing fast without padding once the per-address count is over the limit for sign-in, where the "limited" answer is visible anyway.
- I4: during a real outage in which the provider's script loads but its checking address does not answer, every post that carries a token waits the full time limit (5 s by default) before the health record is used. Skip the call to the provider while its fresh health record says "down".
- I5: per-address limits count whole IPv6 addresses, so one /64 is unlimited addresses. The site-wide limit is the real cap. This is older code and also applies to the existing link limits.

## Fixes 7eceacf..fe317f6 (installer, protected plans, deploy gate)
- Installer refusal (7eceacf): `migrationDbChangedAppliedUpgrades()` is asked before any change, and the installer refuses without locking. The real-database run (L "installer lock") passed on both databases.
- Protected plans (c140ea6): one rule, `adminPlansProtectedPlanProblems()`, used by the plan form and the trial setting. Reviewed by reading. The unit and admin-plans tests pass. No security issue.
- Deploy gate (e013df0, e49a41c): `run-tests.sh` refuses fewer than 45 files, a mismatch in the count of test files, or zero assertions. A real run printed "54 test file(s), 8377 assertion(s)". The concurrency group is per branch and never cancels a running deploy. Sound.
- F6/F7 migration comments and the ST_Collect refusal (1925ee5): covered by `test_migrations.php` (passes).

## What is sound (checked now)
- No visitor-controlled way to make a provider look down. "Down" comes only from the server's own test call with a fixed dummy token. A 4xx page counts as up. A missing table, a lock held elsewhere or a failed write counts as up. Posted fields claiming "down" change nothing (real run F).
- The outage rule only ever lets SIGN-IN through. Every caller passes a fixed purpose, and only `login` qualifies. Sign-up, forgot-password and new-link are refused during an outage on the pages and in the API (real run F). Activity rows are bounded (1 a minute, 1 per account an hour).
- Login CSRF and session fixation: starting, Continue and Approve all need the session's security token (session cookie SameSite=Lax, strict mode on). The session ID changes on sign-in by Continue (P6). Opening the link changes nothing.
- A reset, a password change and "sign out everywhere" all move the "sessions valid from" mark (`accountRemoveOtherWaysIn()`, `fn_api_account.php` lines 739 and 993). A waiting or approved link then stops working (real run K). The link is single use, lasts 15 minutes and is stored only as a hash.
- No account enumeration through the route: the same answer and about 0.41-0.43 s for no account, wrong password, existing and new sign-up (P5, both databases). The "limited" answer comes before any account lookup.
- Rate limits behind a proxy: X-Forwarded-For from a connection that is not trusted is ignored (P1). Cloudflare is trusted only from its ranges (decision 0014).
- Token replay record: 8 processes with one token give exactly one acceptance (real run H). The route gate's lock gives exactly the limit for 12 at once (real run J).
- Upgrade 0005: fresh, from 0004, and forced re-run on MySQL 8.0.36 and MariaDB 10.6.28. Owner choices are kept and no duplicate rows appear. The four tables exist and the foreign key builds on both.
- Documentation counts match fresh runs: human check run 115 checks in 12 scenarios, migrations 195, and the per-file table in tests/README matches `run.php` file by file.

## Could not check
- The real providers (not allowed), real provider outages, how the providers answer malformed requests, and the widgets in a real browser under the CSP.
- Apache and DreamHost routing (the built-in PHP web server was used). Real email delivery. Concurrency of whole HTTP requests under load (the lock was tested directly, P7).
- PHP 8.4 and 8.6 (only 8.5.12 was used). MySQL 8.4 and MariaDB 10.11.
- The Codex cross-review (still owed per project rules).
