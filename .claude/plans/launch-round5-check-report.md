# Round-5 pre-push check of d5dd065..1dba97d

- **Date:** 2026-11-28
- **Checked by:** an independent Opus checker. It wrote none of this code.
- **Range checked:** commits d5dd065..1dba97d. These are the fixes for `launch-round4-review-report.md` and the "Fix" rows of `launch-codex2-report.md`. The decision documents were not checked.
- **Copy checked:** `git archive 1dba97d`, exported to a scratch folder. The code before the fixes came from `git archive d5dd065`.
- **Decisions applied:** 0037, 0041 and 0044. Decision 0044 says it is an accepted risk that someone can use up the daily limit on the route for visitors without JavaScript. That is not counted as a finding here.

## Answer: YES, 1dba97d is safe to push to `work-in-progress`

Nothing at High or Medium was found. Both Medium findings from round 4 are fixed: I reproduced each one on the old code and showed it gone on the new code. The outage fix, the Free card fix and the upgrade 0006 fix all behave as intended. The unit suite and every real-database script pass on MySQL 8.4.13 and on MariaDB 10.6.28.

**A note on the branch:** the main working copy has moved on to fb2d323, which is not covered by this check. A plain push of `work-in-progress` would also send fb2d323. To push only what was checked, use `git push origin 1dba97d:work-in-progress`.

## Evidence (all produced during this check)

| Check | Result |
|---|---|
| `php tests/run.php` (PHP 8.5.12) | 9469 of 9469 checks passed, exit 0. The README says 9470. The one missing check is in `test_credentials_template.php`, which skips its `.gitignore` check when there is no `.git` folder, and the exported copy has none. This is not a defect. |
| `tools/ci/run-integration-tests.sh` on MySQL 8.4.13 | All 9 scripts passed: 1437 checks passed, 0 failed, exit 0 |
| The same on MariaDB 10.6.28 | All 9 scripts passed: 1437 checks passed, 0 failed, exit 0 |
| `php tools/php86-compat-scan.php` | 204 files, 0 findings |
| `php tools/check_site_polish.php` | 0 problems. There are 9 warnings, all older: unfilled legal wording in `terms.php` |
| `php -l` on every changed PHP file; `node --check` on `sw.js` and `cuercode-human-check.js` | No errors |
| SRI hash of the local `assets/vendor/jquery/jquery.min.js` | Recomputed as sha384-1H217gwSVyLSIfaLxHbE7dRb3v4mYCKbpQvzx0cegeju1MVsGrX5xXxAvs/HgeFs, which matches `footer.php`. Its sha256, /JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=, is the hash jQuery publishes for 3.7.1. The CDN copy was not fetched, because nothing was to be sent outside. |

### Medium 1: different spellings of an email address

I wrote my own test script, `email_probe.php`, kept outside the repository. It runs the real functions on a fresh `cuercode_it_r5probe` database built from install.sql, the starting data and every upgrade. I ran it against both the old and the new code, on both database servers.

**Spellings that only look like the real address.** Each spelling below differs from the account's address `alice@example.com`, yet the database's own comparison treated each as the same address, so the old code found Alice's account:

- an accented letter (`alicé@`);
- an invisible "zero-width" space;
- a control character;
- a full-width `ａ` (a wide form of the letter, used in East Asian text);
- a trailing non-breaking space.

On the new code, none of them:

- passes the sign-in password check (the step the route for visitors without JavaScript uses);
- queues a forgot-password email;
- queues a new verification-link email;
- is found by the admin command-line look-up;
- creates a second account at sign-up (the address is still reported as taken).

Account erasure also changes only queued emails for the exact address. A queued email for `alicé@` is left alone.

**Old code d5dd065:** 13 checks failed. Every look-alike signed in, `alicé@` was sent a reset email, the admin look-up found Alice, and erasure changed the look-alike's queued email.

**New code 1dba97d:** 48 of 48 passed, on MySQL 8.4 and on MariaDB 10.6.

**Real people still get in.** These ways of typing the address still sign in, still get one reset email, and still cannot make a second account:

- `ALICE@EXAMPLE.COM`
- `Alice@Example.Com`
- surrounding spaces
- a tab before and a new line after
- the admin command line with `  ALICE@Example.com `

Single sign-on (signing in with Google or Microsoft) lower-cases and trims the provider's address with the same `mb_strtolower(trim())` as registration (`fn_oauth.php`), so a provider that sends capitals still matches. A look-alike now ends in the existing "an account with this email already exists" answer when the account is created, rather than being linked to the account.

**Every place that looks up an address was converted.** I searched the site code for comparisons on any column whose name holds "email" or "address". The only plain ones left set a value (erasure's `SET` clauses). None of them look a row up.

### Medium 2: the offline worker keeping admin pages

I wrote `sw_probe.js`, kept outside the repository. It loads the real `sw.js` in Node with stand-in browser objects and records every time the worker stores a copy of a page.

- **Old worker (v4):** it kept a copy of every page tried: `/admin/` (even when marked no-store), `/admin/users/`, `/install/`, and a no-store `/pricing/`.
- **New worker (v5):**
  - `/admin/`, `/admin/users/` and `/install/` are never kept, even when no header is sent;
  - no page marked "no-store" or "private" is kept, in any mix of capitals;
  - pages and files marked "public" are still kept.
- **Old copies are removed:** the cache version moved to v5, and the worker's start-up step deletes every cache that is not current.
- **Where the no-store header comes from:**
  - `bootstrap.php` sets `session_cache_limiter('nocache')` before `session_start()`, so a host's `php.ini` cannot change it;
  - `admin/.htaccess` still sets `Cache-Control: no-store`.
- **Not measured end to end:** a local `php -S` server answered 503 without its credentials file, so I did not see the real header on a live page.

### Outage, Free card and upgrade 0006

**Outage (decision 0037).** The admin choice to "refuse" during an outage is gone. Choosing a provider now means "try it first", and the other providers with keys follow. When every provider is down, sign-in is let through only if a record of the outage is really in the activity log. If no record can be confirmed, sign-in is refused with "try again". This matches 0037's "Rules out: letting sign-in through without a record". Scenario F of `human_check` passed on both servers, including the checks that the wrong-password lock-out still applies during an outage.

**Free card (decision 0041).** On a real database I set `billing_price_points_enabled` to 1 and renamed `tblPlanPricePoints` so the price list could not be read.

- **New code:** the Free card has `priceUnavailable=false` and keeps its sign-up button. The Pro, Business and Enterprise cards are all marked "Price unavailable".
- **Old code:** the Free card was marked "Price unavailable", which removes its button. This reproduces the finding.
- **Servers:** passed on both.

**Upgrade 0006.** Scenarios P7, P8 and P9 of `migrations` passed on both servers. A column that someone changed by hand is refused and left exactly as it was, whether the change was a different default (5.00), an "unsigned" setting (no negative amounts), or a different description. The upgrade decides "skip" before it runs its safety question, so an interrupted upgrade that has already widened some columns is not blocked by their new descriptions. I checked by text search that each `expectComment` appears in the committed old schema `tests/integration/fixtures/install_76b1af7.sql`. Scenario P, which upgrades from that old schema, passed on both servers.

## Findings

No High, Medium or Low findings. There are three Info notes. None of them blocks the push.

**Info 1: an address stored with capitals or spaces can no longer be found.** The new match compares bytes, so a stored address must be exactly lower case and trimmed.

- **Reproduction:** I inserted `Bob@Example.com` directly into the database. The old code signed Bob in whether he typed it as stored or in lower case. The new code refuses both, because the typed address is lower-cased before the look-up.
- **Why it is not a defect:** no code path has ever stored such an address. Registration and single sign-on have lower-cased and trimmed since they were first written in d4bf885 and b86d852. Only a row added by hand or by an import could be affected.
- **Suggested fix:** before the first deploy that includes c80437b, run this on the live database and expect 0:

  ```sql
  SELECT COUNT(*) FROM `tblUsers` WHERE CAST(`email` AS BINARY) <> CAST(LOWER(TRIM(`email`)) AS BINARY);
  ```

  Optionally, add the same query as a warning in the upgrade check.

**Info 2: non-English addresses typed in a slightly different form.** An address with accented letters can be written in two byte forms that look identical (the accent built into the letter, or added as a separate mark). An address can also carry a pasted trailing non-breaking space. The database used to treat these forms as the same address; now they are different.

- **Why it is not a defect:** registration, forgot password and new-link requests accept ASCII addresses only (`validateEmail()` uses `FILTER_VALIDATE_EMAIL` without the Unicode option), so only an address from single sign-on could be stored in a non-ASCII form. The trailing non-breaking space is one of the look-alikes the fix is meant to refuse.
- **Possible later step (not required now):** convert addresses to one standard form when they are stored and when they are typed.

**Info 3: the Kelvin sign.** If someone types the Kelvin sign "K" (U+212A), `mb_strtolower` turns it into an ordinary `k`, so it still finds `kate@example.com`. This was measured. The no-JavaScript route counts under `noScriptEmailCountingForm()`, which uses the same `mb_strtolower`, so this spelling shares Kate's daily allowance of password checks and gets no extra guesses.

## Clean-up

- Only my own containers, `cuercode-it-r5-my84` and `cuercode-it-r5-ma106`, were started, and both are stopped.
- My probe database was dropped.
- No `php -S` server is left running.
- Nothing was sent outside.
- Nothing in the main working copy was changed, committed or switched, except this report, which is not committed.
