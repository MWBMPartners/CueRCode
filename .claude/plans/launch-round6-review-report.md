# Round 6 independent review: dynamic QR upgrade 0007, the Go2My.Link connection and the review fixes

- **Commits reviewed:** `1dba97d..82855d0`. That covers `fb2d323` (cherry-pick of `c7687d3`), the fixes `7f34204..b7c6b7a`, the secret-scan allowlist `26578d3` (#180), and decisions 0045 and 0046 (committed earlier, in `2bc2c84`).
- **Reviewer:** Opus, independent. I wrote none of this code.
- **Date:** 2026-11-28.
- **Where I worked:** a clone at `82855d0` in the session scratchpad, plus a second exported copy for the mutation runs. Nothing in the main working copy was changed apart from this file, which is not committed.
- **Go2My.Link:** read only, with `git show`, on branch `feat/2026-09-21-linkspage-and-sweep` (`f69b197`).
- **Real services:** nothing was sent to any.
- **Clean-up:** my two Docker containers are stopped. No stand-in server or web server is left running.

## Verdict

**`82855d0` is safe to push to the shared `work-in-progress` branch.**

- There are no High or Medium findings.
- The site does not call any of the new Go2My.Link functions yet, and `dynamic_qr_enabled` is still locked off. So every finding below is about behaviour that WP4c (#166) will switch on later. None of them affects visitors today.
- The Low findings should become tracker issues before WP4c starts.
- The Codex review is still owed under the project rules.

## What was run (fresh evidence)

- **Unit suite, PHP 8.5:** `php tests/run.php` gave **10190 / 10190 passed**.
- **Real-database scripts:** `tools/ci/run-integration-tests.sh` on throwaway Docker **MySQL 8.4** and **MariaDB 10.6**. All **9 scripts passed on each** (1497 "ok" lines each, 0 failures). This includes:
  - scenario Q in `migrations_real_mysql.php`;
  - section Q in `admin_settings_real_mysql.php`;
  - scenario 12e in `email_queue_real_mysql.php`;
  - the earlier email-matching, error-log, human-check and 0006 checks.
- **PHP 8.4.27 (Docker):** the go2mylink, dynamic_qr, admin_settings, features and email_queue_runner unit tests all passed (469, 117, 1462, 91 and 510 checks).
- **PHP 8.6.0 (Docker):** `test_go2mylink.php` (469) and `test_features.php` passed. The other three stopped with "undefined function mb_substr". That is because the image has no mbstring module, not because of the code. See "Not checked".
- **Other checks:**
  - every changed PHP file passes `php -l`;
  - `php tools/php86-compat-scan.php` found 0 problems;
  - `php tools/check_site_polish.php` found 0 problems.
- **Secret scan:** `gitleaks 8.30.1 detect`, the same command CI uses, over all 374 commits.
  - New `.gitleaks.toml`: **no leaks**.
  - Old `.gitleaks.toml` (from `26578d3^`): **32 findings**, exactly in the three allowlisted test files.
  - Each new entry needs both the exact value and that one test file, so nothing else is hidden.
- **My own probes:** a script I wrote, `probe_g2.php`, in the session scratchpad. It used stubbed replies, and real HTTPS against `tests/lib/go2mylink_standin_server.php`.
- **Mutation runs:** 23 targeted changes against the unit tests, and 3 against a real database.

## The earlier findings, re-run

| Item | Re-run now | Result |
|---|---|---|
| **H1** | Stand-in server, scenario `wrongdomain`, same row twice. 1st try: `wrong_short_domain`, remoteExists=true, state `unconfirmed`. 2nd try: `domain_unconfirmed`, ok=false, state `unconfirmed`. Previously the 2nd try printed `QRmy.Link/Wrd7777`. A lost answer whose look-up has no short address also gives `domain_unconfirmed`. | **Closed** |
| **M1** | Stand-in scenarios `garbled`, `nostatus`, `bigheaders` and `badlength` now each give `bad_reply`, outcome unknown = true, state `pending`. | **Closed** |
| **M2** | Mutations K1 (ignore the website change) and K2 (stop requiring the key to be typed again) are each caught by 3 checks. Tricks with capital letters, `:443`, `@` and `.evil.com` are all blocked, or are the same website. | **Closed** |
| **M3** | Mutations: overdue ignored, -1 not treated as highest, replace instead of raise, and 0 accepted. All 4 are caught. | **Closed** (decision 0045 followed) |
| **L1** | Probe: a switched-off link is refused. A link going elsewhere is changed back to our destination, and only a confirmed change counts. Mutations G2 and G3 are caught. | **Closed** (but see Low 1 and Low 3) |
| **L2** | Rollback removed when the audit row fails: caught on real MariaDB ("still 10" check fails). | **Closed** |
| **L3** | Read cap: caught (G9). QR id compared: caught (G13). Ping must say pong: caught (G12). Published hash key: caught (A1). | **Closed** |
| **L4** | The hash refuses to work when `ENCRYPTION_KEY` is missing (A1 caught). | **Closed** |
| **L5** | Clean-up on any status (A2) and with no waiting period (A3) both survive the unit tests, but both are **caught by real-database scenario 12e** on MySQL 8.4. | **Closed** (decision 0046 followed) |
| **L6** | `syncState` is now `VARCHAR(16)`, starting value `unlinked`. | **Closed** |
| **L8** | Mutation G11 (the change handler's own wording no longer required) is caught. | **Closed** |
| **L11** | An alpha web copy with `https://QRmy.Link` gives `test_copy_live_domain`. | **Closed for web requests** (see Low 4) |

**Cherry-pick check.** `git range-diff d5dd065..c7687d3 1dba97d..fb2d323` shows only the commit message and three documents differing (CHANGELOG, INSTALL, tests/README). The code is byte-for-byte the same patch. Both sides' CHANGELOG sections are present. The email-matching, error-log, human-check and upgrade 0006 behaviour still pass on both database servers.

## Findings

### Low 1. A link found by look-up can be changed and accepted even when its code differs in capital letters, or when it may not be ours

This becomes possible once Go2My.Link issue #276 adds the short address to its look-up.

- **Where:** `_functions/fn_go2mylink.php`, the `$recovered` step of `go2mylinkCreateLinkSafely()`, lines 1343-1387. It compares against `$foundLink['shortCode']` (line 1354) and sends the change to that code (line 1367).
- **Reproduction:** `probe_g2.php` case C. The create answer is lost. The look-up of `Ab3dE9x` returns `ab3de9x`, with a short address and `https://someone-else.example/`. Result: `ok=true state=active code=ab3de9x`, and the requests were `POST urls | GET urls/Ab3dE9x | PUT urls/ab3de9x`.
  - Case C2 ("this QR is already linked", then a found link that points elsewhere) also ends in a change and `active`.
  - Go2My.Link's look-up is `WHERE s.shortCode = ? AND s.orgHandle = ?` on a column that ignores capitals, so it really does return another link's code in its own capitals.
- **Consequence:** suppose a link in the CueRCode organisation that CueRCode did not make shares the code (ignoring capitals). That could be a hand-made link, or the other environment if both share an organisation. The recovery then **overwrites that link's destination** and records it as ours.
  - Today the step is blocked by `domain_unconfirmed`.
  - The commit message says recovery "works with no further change" once #276 lands. Low only because such a clash is very unlikely.
- **Fix:**
  - Require `$foundLink['shortCode'] === $shortCode` (exact match) before anything else.
  - Ask Go2My.Link (#276) to also return `qr_code_external_uuid` in the look-up, and send the change only when it is ours.
  - Add a test for both.

### Low 2. A create reply is accepted when its short address carries a port, a user name or a "?" part

- **Where:** `go2mylinkCreateLink()` lines 1170-1175 and 1192, and `go2mylinkShortUrlIsOurs()` lines 1084-1092. Both compare only the website name and the path.
- **Reproduction:** probe case H. A 201 reply with any of these is `ok=true`, `active`, and `shortUrl` is passed through unchanged:
  - `https://QRmy.Link:8443/Ab3dE9x`
  - `https://x@QRmy.Link/Ab3dE9x`
  - `https://QRmy.Link/Ab3dE9x?u=1`
- **Consequence:** if WP4c prints `data.shortUrl`, a faulty or compromised Go2My.Link reply decides exactly what goes into a printed code.
- **Fix:** either accept only an exact match of `rtrim(shortLinkBase,'/') . '/' . code` (ignoring capitals in the website name), or have WP4c always print the address it builds from `shortLinkBase` and the confirmed code.

### Low 3. A destination with a space or a non-English letter can never be recovered

Like Low 1, this matters once #276 lands.

- **Where:** lines 1366-1374, and the same exact text comparison in the destination check.
- **Why:** Go2My.Link stores a cleaned-up copy of the address. `g2ml_sanitiseURL()` in `web/_functions/security.php` converts spaces and non-ASCII letters to their encoded forms, then applies `FILTER_SANITIZE_URL`.
  - CueRCode's `validateRedirectDestination()` accepts both (it refuses only control characters).
- **Reproduction:** probe case D. Our destination is `https://example.com`; Go2My.Link stores and returns `https://example.com/`. Result: `bad_reply`, outcome unknown, remoteExists, `unconfirmed`. A change that was sent and confirmed is still refused.
- **Consequence:** such a code is held for an admin every time an answer is lost. The planned daily comparison would also report it as different for ever. It never prints wrongly, so the code fails safe.
- **Fix:** store the destination in Go2My.Link's encoded form before sending (best, because both sides then agree). Otherwise, accept the destination returned by a successful change instead of comparing it with our text.

### Low 4. The alpha/beta guard cannot see a command-line (cron) run

- **Where:** `go2mylinkTestCopyName()` lines 354-377, used at line 431.
- **Reproduction:** probe "alpha CLI (cron)". Script path `.../_includes/cli/worker.php`, with the seeded `site_url` `https://cuercode.net` and `https://QRmy.Link`, gives `problem=(none - would send)`.
  - `deploy.yml` uploads `_includes` and `_functions` to the same `SFTP_REMOTE_BASE` for every channel. So the script path is the same on alpha and live. `site_url` is seeded `https://cuercode.net` on every database.
  - An alpha copy pointed at the production Go2My.Link organisation also makes a live, unprinted link on QRmy.Link before `wrong_short_domain` refuses it.
- **Consequence:** WP4c's tidy step or worker, run by cron on alpha, would not be stopped from making codes on the live QRmy.Link, against decision 0030. Nothing runs on the command line yet.
- **Fix:** before WP4c adds any command-line caller, give each copy a reliable marker of which copy it is (a setting or a value in the key file). For WP4c, record that the folder check alone does not cover cron.

### Low 5. Two new protections are still untested, and the CHANGELOG says otherwise

- **G4:** the check that a resent destination matches the reply (line 1368). If removed, 0 checks fail.
- **G7:** `remoteExists` after "already linked" plus a lost look-up (line 1397). If removed, 0 checks fail, and the state becomes `pending` instead of `unconfirmed`.
- **Untrue text:** CHANGELOG line 24 says "removing each protection again makes a test fail".
- **Fix:** add the two tests, or reword that line.

### Info

- **Abuse-report clean-up.** It selects on `handledAt` (`fn_email.php` line 4259), so a report set to actioned or dismissed without `handledAt` keeps its details for ever. WP4e must always set `handledAt` whenever it sets either of those statuses, and a test should prove it. As written, scenario 12e shows the clean-up removes neither too much nor too little (reopened, recent and open reports are kept).
- **Error codes Go2My.Link can send.** After a create, 408, 413 and 503 count as "not run". That matches Go2My.Link's own 503 coming before any work. A gateway in front of it could differ, but I found nothing that suggests one.
- **Code layout.** Alpha and live share one `_functions` and `_includes` upload target, and I found no per-copy database selection in the repository. This is older than these changes. It is worth confirming before WP4c relies on "an alpha database sets its own" settings.

## What is sound (re-proved now)

- **Every path to "active":** it needs a confirmed short address on our website name, a switched-on link, and our destination. `go2mylinkSyncStateFor()` checks "Go2My.Link holds a link" before "not known", so a held link is never retried (G5 caught).
- **The saved key:** it is never sent to a new website without being typed again or removed. A save that has an error saves nothing.
- **A person's own limit:** a raise only (the higher number wins, -1 is highest), ignored while a payment is overdue, 0 and limit names no plan has are refused, and the save function checks for a Global Admin itself.
- **Abuse reports:** a 12-month keeping period that a Global Admin can change; missing or invalid means 365 days; a bare record stays; nothing happens before 0007.
- **Upgrade 0007 and earlier behaviour:** all pass on MySQL 8.4 and MariaDB 10.6, and the cherry-pick lost no earlier behaviour.

## Mutation table

| Mutation | Result |
|---|---|
| G1 short-address check always true | caught (1) |
| G2 switched-off link accepted | caught (1) |
| G3 no resend | caught (3) |
| G4 resend reply not compared | **survived** |
| G5 sync-state order swapped | caught (3) |
| G6 wrong domain without remoteExists | caught (4) |
| G7 "already linked" + lost look-up without remoteExists | **survived** |
| G8 / G8b test copy never detected / check skipped | caught (8 / 4) |
| G9 header overflow not marked | caught (1) |
| G10 M1 reverted | caught (9) |
| G11 L8 wording not required | caught (2) |
| G12 pong not checked | caught (1) |
| G13 QR id not compared | caught (1) |
| K1, K2 key rule | caught (3 each) |
| L1m-L4m limits | caught (2, 2, 2, 1) |
| A1 published hash key | caught (1) |
| A2 clean-up on any status | unit: survived. **Real MySQL 8.4: caught (12e)** |
| A3 no waiting period | unit: survived. **Real MySQL 8.4: caught (12e)** |
| R2 no audit rollback | **Real MariaDB 10.6: caught (L2 check)** |

## Not checked

- The real Go2My.Link (it must not be called), or its alpha branch.
- MySQL 8.0, which CI also requires. It was not in this brief.
- The full suite on PHP 8.4 and 8.6. Only the five relevant files were run. Three of them could not run on the 8.6 image, which has no mbstring module.
- A browser pass of the admin section.
- How the alpha copy picks its database. That is not visible in the repository.
- The Codex review. It is still owed.
