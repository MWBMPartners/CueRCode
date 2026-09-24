# Plan for #112 — make queued emails actually get sent (email queue runner)

Planner: Opus (deep planning). Builder: **Opus** (the lead changed this from Sonnet on 2026-09-25: duplicate-proof claims, a named lock and stuck-row recovery count as "genuinely complex" under the model-routing rule). Verifier: a separate Opus agent. Reviewer: Codex.

> Saved into the repository (`.claude/plans/`) so the work survives a session ending or a hand-over to another AI tool. Paths that were specific to one machine have been replaced with `$REPO` / `<scratchpad>` placeholders.
Written 2026-09-25 against `work-in-progress` at `005d232` (which already contains the
#117 `curl_close()` removal, commit `eafb22a`).

Plain-English glossary used throughout:
- **Queue table**: `tblEmailQueue`, where emails wait to be sent.
- **Runner**: the new command-line script that a DreamHost scheduled job ("cron job") runs every minute to send waiting emails.
- **Claim**: a runner marking an email as "I am sending this one" so that no other runner touches it.
- **Named lock**: a MySQL feature (`GET_LOCK`) that lets exactly one database connection hold a named "talking stick" at a time. It is let go automatically if that connection closes, for example when the process dies.
- **Stuck row**: an email left marked "sending" because the runner died part-way through.
- **Back-off**: waiting longer after each failure before trying again (2, 4, 8, 16 minutes).

---

## 0. Read this first (builder)

### 0.1 Preconditions — check before editing anything
1. Run `git status --short`. Other people's uncommitted work was sitting in the tree while this plan was written: the stand-in review fixes in `DEV_NOTES.md`, `README.md`, `.github/SECURITY.md`, `ci.yml`, `deploy.yml`, `openapi.json` and `developers/authentication/index.php`.
   - This plan edits `DEV_NOTES.md` and `README.md` too. There is no way to stage part of a file without interactive git, which is not available here.
   - **So the lead must commit that other work before this build starts.** If it is still uncommitted when you start, carry on, but:
     - never revert, rewrite or "tidy" any change you did not make;
     - edit only the sections this plan names;
     - tell the lead in your report that the files are mixed.
2. `git log --oneline -3` should show `eafb22a fix: remove imagedestroy()/curl_close() ... (#117)` or later. `fn_email.php` must contain **no** real `curl_close(` call lines. Check with `grep -nE '^\s*curl_close\(' web/CueRCode.net/_functions/fn_email.php`, which should print nothing. Do not add any, and do not touch the two "No curl_close($ch) here" comments.
3. Record the baseline by running `php tests/run.php` and reading its exit code directly. It was **2185/2185, exit 0** on 2026-09-25.

### 0.2 House rules that apply to every line you write (from `.claude/CLAUDE.md` and the existing code)
- **Full multi-line PHP, no shorthand, in production code.**
  - Do not use `??`, `?:`, ternaries (`a ? b : c`), arrow functions (`fn () =>`), first-class callable syntax (`foo(...)`), or `match`.
  - Write `if`/`else` blocks instead. `fn_email.php` currently has zero of these. Keep it that way.
- **PHP 8.4 compatibility.** CI runs the tests on PHP 8.4. Do **not** use anything only PHP 8.5 has: the pipe operator `|>`, `clone with`, `array_first()`/`array_last()`, or the `#[\NoDiscard]` attribute.
- **MySQLi prepared statements for every query**, always through `dbQuery($sql, $types, $params)`.
  - Never glue a variable into SQL text.
  - The one allowed exception is the fixed constant `CUERCODE_EMAIL_QUEUE_LOCK_NAME_SQL` defined below. It contains no user input.
- **Use PHP's built-in constants**: `DIRECTORY_SEPARATOR`, `PHP_EOL`.
- **Heavy explanatory comments.**
  - Every new function gets a docblock with `@param`/`@return`, WHY it exists, what it CANNOT do, and what was tried and rejected where relevant. Section 3 gives the key "why" points to carry into comments.
  - Emojis in comments are fine; the existing file uses them.
- **Match the existing file-header format.** See the top of `fn_email.php` (lines 1-42): banner, `Path:` relative to the site root, description, `@package CueRCode`, `@subpackage`, `@copyright 2025–2026 MWBM Partners Ltd` (note the en dash), `@version`, `@since`, `@see`.
- **No classes or interfaces.** The `_functions/` code is procedural and has none. Use functions and arrays of callables.
- **Never print or log** email bodies, `paramsJSON`, the one-time link tokens, Graph tokens, secrets or setting VALUES. Keep full email addresses out of anything the runner prints.
- **Do not commit or push.** The lead commits after the Opus verification and the Codex review.

---

## 1. What the code does today (evidence)

Line numbers are from `HEAD` = `005d232`.

### 1.1 Nothing ever sends the queue
- The only callers of `processEmailQueue()` and `maybeProcessEmailQueueOnShutdown()` are in `tests/test_email.php` (lines 300 and 326).
  - Grep proof: `grep -rn "processEmailQueue\|maybeProcessEmailQueueOnShutdown" --include='*.php' web tests tools`.
  - `fn_email.php:1070` says the hook is "NOT wired into bootstrap.php by design".
- `_includes/cron/` does not exist. `DEV_NOTES.md` step 7 of the email setup says the script is "to be added".
- Emails are queued by `registerUser()` (`fn_auth.php` ~431-445) and `requestPasswordReset()` (~1173-1187).
  - New users are inserted `'pending'` (`fn_auth.php:367`) and only become `'active'` on verification (`fn_auth.php:558`).
  - Result: an email-and-password sign-up can never complete.

### 1.2 How `processEmailQueue()` picks rows today — the concurrency finding
`fn_email.php:852-958` (the function body) works in three steps:
1. A plain `SELECT` (no locking) of up to `$max` rows where `status IN ('queued','failed') AND (nextAttemptAt IS NULL OR nextAttemptAt <= NOW()) AND attempts < maxAttempts` (the query around lines 872-882).
2. For each row, a claim: `UPDATE ... SET status='sending' WHERE emailID = ? AND status IN ('queued','failed')`, succeeding only if `affected_rows === 1` (lines ~911-917).
3. Render, send, then call `settleEmailQueueSuccess()` or `settleEmailQueueFailure()`.

**What is right:** step 2 is a genuine atomic claim.
- MySQL makes two simultaneous `UPDATE`s of the same row queue up behind each other.
- The second one re-checks its `WHERE` against the first one's committed change, so it matches 0 rows.
- So two runners can never both claim the same row **at the same instant**.

**What is wrong: four real holes.**

1. **The claim does not re-check "is it due?" or "attempts left?".** The row list in step 1 can be out of date by the time step 2 runs (each earlier row in the batch can take up to about 20 seconds). Example:
   - Runner A claims row X, the send fails, and A puts X back to `queued` with a retry time 2 minutes away.
   - Runner B, working from its older list, then claims X. The status is `queued` again, so the claim succeeds.
   - B re-sends X **immediately**, ignoring the back-off.
   - B then writes back its *stale* `attempts` value, losing A's increment, so the retry count goes wrong.
   - The same path lets B send a row A has just marked permanently `failed` (one send beyond the limit).
   - If A's "failure" was really a timeout after Microsoft had already accepted the email, B's re-send is a true duplicate.
2. **The final updates are not guarded.** `settleEmailQueueSuccess()` (`fn_email.php:971-990`, `WHERE emailID = ?` at line 980) and `settleEmailQueueFailure()` (1009-1053, `WHERE emailID = ?` at 1022 and 1043) update the row whatever state it is now in. A late or confused writer can overwrite a row it no longer owns.
3. **No recovery for stuck rows.** If the process dies between the claim and the final update, the row stays `sending` forever. Causes include a PHP fatal error, the host killing the process, or the database dropping at the wrong moment (the settle functions catch the error, log it, and leave the row as it is). That email is never sent. For a verification email, the user is locked out for good.
4. **"Not configured" burns the retry budget.** `sendEmailNow()` returns `[false, 'skipped: ...']` when email is switched off, when there is no Graph token, and so on (`fn_email.php:580, 588, 596, 604`). `processEmailQueue()` treats every one of those as a failed attempt.
   - Every queued email therefore ends up permanently `failed` after about 30 minutes of "not set up yet".
   - The same happens during a Microsoft token outage.
   - `DEV_NOTES.md` admits this as a "Caveat". It also says "~62 cumulative minutes", which is wrong: the waits are 2+4+8+16 = **30 minutes**, because the 5th failure gives up without waiting.

### 1.3 Queue table facts (`_includes/sql/install.sql`)
- `tblEmailQueue` is defined at lines 446-474.
- `status` is an ENUM of `queued`, `sending`, `sent`, `failed`, `skipped`. `skipped` is never used by any code.
- `attempts` and `maxAttempts` (default 5), `lastError` (TEXT), `nextAttemptAt` (DATETIME, line 463).
- **`updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` (line 465).** MySQL refreshes this automatically on every change, so after a claim it records *when the row was claimed*. That is exactly what stuck-row recovery needs, **with no schema change**. We will also set it explicitly (`updatedAt = NOW()`) in every write, so we do not rely only on the automatic behaviour.
- Index `idx_email_status_next (status, nextAttemptAt)` (line 469) serves the "which rows are due" query.
- There is **no migrations folder and no upgrade script**. `install.sql` is `CREATE TABLE IF NOT EXISTS` only (#113). A schema change would need a manual `ALTER` on every existing install. That is a strong reason to avoid one, and we can.
- **Side finding (not fixed here):** the `paramsJSON` column comment at line 451 says it "NEVER stores ... secrets". That is false.
  - `registerUser()` and `requestPasswordReset()` store the full verification and reset links, **including the one-time token**, in `paramsJSON`.
  - Sent rows are never deleted.
  - Meanwhile `tblEmailVerifications` and `tblPasswordResets` deliberately store only hashes.
  - → follow-up issue (section 9).

### 1.4 Starting the app from the command line (`_includes/bootstrap.php`, `_includes/db_connect.php`)
- Every function file has a direct-access guard (`basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)`). This passes when the entry script has a different name, so a script named `process_email_queue.php` is fine.
- `bootstrap.php:224` skips the PHP session when `php_sapi_name() === 'cli'`. Good.
- **Trap 1: missing credentials.** `db_connect.php:70-87`: if `DB_HOST` and the other connection settings are not defined (no `_auth_keys/auth_creds.php`), it calls `error_log()` and `exit(1)` **during the `require`**. The runner cannot catch that. So it must register its own "could not start" shutdown message *before* loading bootstrap. Locally there is no `auth_creds.php` (checked: `_auth_keys/` holds only `.htaccess`), so this is the path a plain local run takes.
- **Trap 2: unreachable database.**
  - On PHP 8.1+, `new mysqli()` to an unreachable database **throws** `mysqli_sql_exception`. This was probed on PHP 8.5.10: "Connection refused" is thrown by the constructor.
  - That exception is a subclass of `RuntimeException` (checked). `getDB()`'s static cache is therefore never set, and each later call retries.
  - `getConfig()` catches `RuntimeException` and **returns the default**. So "email is switched off" and "database is down" look identical through `getConfig()`. The runner needs its own explicit database check before it reads any setting.
  - Bootstrap itself calls `getConfig()` (timezone, debug mode). With a dead database those calls log noise through `error_log()`, which goes to the terminal on the CLI when `error_log` is unset (checked: it is `''` locally). This is expected noise, not a bug in the runner.
- **Trap 3: HTML crash page.** `fn_error_handler.php:153-199` installs a crash handler that prints an **HTML** "Server Error" page for any uncaught exception (debug mode can never be on from the CLI: `isDebugMode()` needs `?debug=true` in a web address). The runner must catch every `Throwable` itself, and also install its own plain-text crash handler after bootstrap.
- `logError()` and `logActivity()` both cope with the CLI. They fall back to `'CLI'`/`'unknown'` for request details.
- **Trap 4: very long database read timeout.** The default `mysqlnd.net_read_timeout` is **86400 seconds (24 hours)**. A hung database read would hold the named lock for a day. The setting *can* be changed at run time: `ini_set('mysqlnd.net_read_timeout','60')` was checked on 8.5.10 and returned the old value. The runner sets it (and `default_socket_timeout`) **before** loading bootstrap, so its own connection picks it up.
- Templates (`_includes/email_templates/*.php`) use nothing from a web request. The links inside them were built when the email was queued. So rendering from the CLI works.

### 1.5 Test harness facts (`tests/run.php`, `tests/bootstrap.php`)
- `run.php` finds `tests/test_*.php` (top-level only; the pattern is at line 131) and `require`s each one **inside its own loop, in the global scope, in one PHP process** (lines 171-221).
  - A test file's variables therefore share scope with the runner's. `$testFile`, `$countBefore`, `$filePass`, `$fileFail`, `$useColor` and similar are all live.
  - **Prefix every variable in the new test file with `$eqr`.**
- There is no database in tests. `tests/bootstrap.php` never loads `db_connect.php`.
  - Five other test files **assert that `dbQuery()`/`getConfig()` do NOT exist**: `test_auth.php:276,314`, `test_emoji_logo.php:260`, `test_features.php:117,285`, `test_pricing.php:195` and `test_qr_logo.php:490`.
  - The new test file sorts between `test_email.php` and `test_emoji_logo.php`. **It must not define global `dbQuery`, `getConfig`, `getDB`, `logError` or `logActivity`.** That is why the runner logic takes its database operations as passed-in callables (a "gateway").
- The precedent for checking source code as text is `tests/test_qrcodes.php:395-440` (asserts that `GET_LOCK(`/`RELEASE_LOCK(` are present in a function body).
- The precedent for starting a child PHP process is `tests/test_core.php:110` (`runPhpSnippet()` with `proc_open`).
- Existing `tests/test_email.php:326-331` asserts that `processEmailQueue(2)` returns exactly `['processed'=>0,'sent'=>0,'failed'=>0,'skipped'=>0]` when `dbQuery()` is missing. **Keep `processEmailQueue()`'s name, parameters and return shape**, so this assertion stays green without being edited.

### 1.6 Hosting and deploy facts
- `.github/workflows/deploy.yml` (Part B, lines ~128-149) copies **every** non-`public_html*` folder of `web/CueRCode.net/`, including all subfolders of `_includes/`, on every deploy (alpha, beta and main).
  - It mirrors `web/` into `${SFTP_REMOTE_BASE}`, so the script lands at `<SFTP_REMOTE_BASE>/CueRCode.net/_includes/cron/process_email_queue.php`.
  - Alpha, beta and production share one set of private folders and one `auth_creds.php`, and so **one database**. So exactly **one** cron job is needed per server.
  - lftp does not keep execute permissions, so the cron command must be `php <file>`, not `./file`.
- `_includes/` sits next to `public_html/` (the web root), so it has no web address at all. `_includes/.htaccess` is also deny-all, and that applies to subfolders. We still add a command-line-only guard in the script as a third layer.
- DreamHost home folders are on network storage, where file locks (`flock`) are unreliable. That matters for the lock choice in section 2.
- Local tools checked:
  - PHP 8.5.10 CLI, and `php-cgi` at `/opt/homebrew/bin/php-cgi`. Probed: `php-cgi -f` on a guard script prints the refusal.
  - Docker 29.8 with images `mysql:8.0`, `mysql:8.4`, `mariadb:10.6/10.11/11.4`, and a PHP 8.4 image `g2ml-php:8.4-mysqli` (another project's image; use it read-only with `--rm`).

### 1.7 Existing named-lock precedent
`fn_api_qrcodes.php:510-606` (`acquireSaveLimitLock()`/`releaseSaveLimitLock()`), and the same shape in `fn_api_redirects.php`, `fn_features.php` and `fn_oauth.php`:
- `SELECT GET_LOCK(?, ?) AS lockResult`
- 1 means got it; 0 means someone else has it; NULL or an exception means error
- release in a best-effort way with `RELEASE_LOCK`

Their prefixes are global (for example `'cuercode_save:'`). MySQL lock names are shared by **every database on the same MySQL server**, and DreamHost's MySQL servers are shared between customers. So the new global queue lock must include this database's identity (section 3.1.6).

---

## 2. The design

### 2.1 Chosen design: three layers, no schema change

**Layer 1 — a safer row claim (the real guarantee against duplicates).**
- The claim `UPDATE` re-checks **everything** in the same statement: status is queued or failed, it is due now, and it has attempts left.
- Every final update (sent, retry, gave up, put back) only applies `WHERE status = 'sending'`. Only the runner that claimed the row can finish it.
- After claiming, the runner **re-reads** the row. It then uses current values (`attempts`), never the possibly stale list.

This closes holes 1 and 2. It works even if the lock (layer 2) is missing or broken, and it does not depend on which program is calling.

**Layer 2 — one named database lock per database (only one runner works at a time).**
- `GET_LOCK('cuercode_emailq:' + first 32 hex characters of SHA-256 of the database name, 0)`.
- The `0` means "don't wait". A second runner that finds the lock taken simply reports "another run is working" and stops.
- Benefits:
  - two overlapping cron runs, or cron plus the page-shutdown fallback, never work in parallel;
  - it keeps the sending rate at one batch per minute, under Exchange Online's roughly 30-per-minute limit;
  - stuck-row recovery can never race a slow-but-alive runner.
- MySQL drops the lock automatically when the holder's connection closes, including when the process dies. Every write is made on that same connection, so a runner that has lost its lock has also lost its ability to write.
- **Stall alarm.** If the lock is taken **and** emails have been waiting more than 10 minutes (or a row has been "sending" more than 10 minutes), the result is "stalled", exit code 4. A hung runner holding the lock then becomes a loud alert instead of a silent stop.

**Layer 3 — stuck-row recovery using the existing `updatedAt` column.**
- At the start of each run (inside the lock), any row that has been `sending` for more than **10 minutes** is put back.
- It is counted as one attempt, because we cannot know whether Microsoft accepted it, and counting it caps possible repeats. It then gets the normal back-off.
- If it was the last allowed attempt, it is marked `failed` instead.

**Plus two behaviour fixes inside the same batch logic:**
- **"Sending is blocked" problems do not use up attempts.** These are:
  - the switch is off, settings are missing, or no Graph token (any `skipped:` result);
  - Graph answers 401, 403 or 404 (bad credentials, missing permission, or wrong sender mailbox);
  - `curl` cannot start.

  The email is put back unchanged, the run stops, and the runner exits with code 4 so the owner hears about it. (Fixes hole 4.)
- **Temporary Graph trouble** (HTTP 429, 408 or 5xx, a network or timeout error, HTTP 0) uses an attempt with back-off, and **stops the run early**. This avoids hammering a struggling service and burning one attempt on every queued email per run.
- **Message-specific failures** (for example HTTP 400, or a template that will not render) use an attempt and back-off, and the batch carries on.

**The runner itself** is a thin command-line file. It sits around testable functions in `fn_email.php`, which take their database operations and their sender as passed-in callables. Tests use an in-memory stand-in for the table. An optional script checks the real SQL against a throwaway MySQL.

### 2.2 Alternatives considered and rejected (put the short reasons into code comments)

| Alternative | Why rejected |
| --- | --- |
| New `claimToken`/`lockedUntil` column (claim-by-token) | Needs a schema change, and there is no upgrade script (#113), so every existing install would need a manual `ALTER`. `updatedAt` plus status guards plus the lock give the same safety. |
| `SELECT ... FOR UPDATE SKIP LOCKED` batch claim | Needs MySQL 8.0.1+ or MariaDB 10.6+, but the README promises MySQL 5.7+ and MariaDB 10.3+. Holding a transaction open across a 10-second web call would also be wrong. |
| `flock()` on a lock file | Only works on one machine. DreamHost home folders are network storage, where `flock` is unreliable. The web fallback and cron may not share a file system view. |
| Named lock only (no row-claim hardening) | Any caller that forgets the lock, or a host where named locks misbehave, would re-open the duplicate hole. The row claim must stand on its own. |
| Row claim only (no lock) | Safe from duplicates, but parallel runners double the sending rate, and stuck recovery could race a slow live runner. The lock is cheap. |
| Reuse `nextAttemptAt` as the "lease" while sending | Works, but overloads the column's meaning and needs exact-timestamp fencing. `updatedAt` already records the claim time. |
| Loop for about 50 seconds inside each cron minute (poll every ~10 s) | Cuts the worst-case wait from about 60 s to about 10 s, but keeps a process and a database connection busy about 80% of the time on shared hosting (DreamHost reaps long-running processes). It also needs rate-tracking across loops and is harder to test. A 60-second wait for a sign-up email is acceptable. |

### 2.3 Decisions requested by the brief

- **Batch size:** default **20** per run (`--limit=N`, 1 to 50). One run a minute at up to 20 emails stays under Exchange Online's 30-messages-per-minute sending limit, and clears any realistic burst quickly.
- **Cadence:** **every minute** (DreamHost panel → Custom). If the panel's shortest option is longer, emails simply wait up to that long.
- **One pass per run, no loop**, for the reasons in 2.2. Two soft limits keep a run inside the minute:
  - a **time budget of 45 seconds**: no new email is claimed after that;
  - **stop early on temporary Graph trouble**.
- **Exit codes** (so cron's email shows problems):

  | Code | Meaning |
  | --- | --- |
  | 0 | Finished normally. Includes nothing to send, email switched off, and another run already working. |
  | 1 | Could not start (for example missing credentials file) or crashed unexpectedly. |
  | 2 | Bad command-line option. |
  | 3 | Database problem (cannot connect, queue table unreadable, lock error). |
  | 4 | Sending is blocked by settings or Microsoft Graph, or an earlier run seems stuck ("stalled"). |
  | 5 | Finished, but gave up on at least one email after all its attempts. |

  When several apply, the most serious wins: 3 > 4 > 5 > 0.
- **Output:**
  - One summary line of counts only, for example `CueRCode email queue: sent 3, will retry 1, gave up on 0, 5 still waiting (2.4 seconds).`
  - **Never** addresses, subjects, bodies, tokens, setting values or IDs of people. Every printed line also passes through an address-masking helper, as a backstop.
  - `--quiet` (the recommended cron form) prints nothing unless something needs attention. Output goes to STDERR (the error stream) when:
    - the exit code is not 0;
    - stuck emails were put back (they may arrive twice, worth knowing);
    - a result could not be recorded.
  - `--check` tests the database and settings and shows queue counts, **without sending anything**. It is useful for the owner's first set-up test.
- **Page-shutdown fallback: OFF by default** (the `email_queue_autoprocess` setting stays seeded at `0`). **This build does wire it into `bootstrap.php`**, for web requests only. Reasons:
  - Today the switch is a trap. Setting it to 1 does nothing, because nothing calls the hook. A documented switch that silently does nothing is the worst kind of failure.
  - Once wired, turning it on really works, for hosts without cron. It uses the same locked, duplicate-proof code as cron, so leaving it on alongside cron is safe (just pointless).
  - It is off by default because it:
    - adds work to ordinary page visits;
    - only runs when people visit, so retries stall on a quiet site;
    - can keep a visitor waiting up to about 20-40 seconds where the server lacks `fastcgi_finish_request()`.

    Cron is available on DreamHost and is the dependable path.
  - Cost when off: one small cached settings read per web request (`email_queue_autoprocess`).
  - The old docblock's claim that it runs "AFTER the response has been sent" is **not true** by itself. PHP-FPM keeps the connection open until shutdown functions finish. The fixed hook therefore calls `session_write_close()` and then `fastcgi_finish_request()` (when available) before sending.

### 2.4 What the design cannot do (say so in comments and in the docs)
- **"At least once", not "exactly once".** If Microsoft accepts an email but the runner dies, or the database fails, before recording "sent", the row is recovered after 10 minutes and sent again. Recovery counts that as an attempt, so repeats are capped. In practice it is at most once more.
- Graph's HTTP 202 means "accepted", not "delivered". Bounces are not tracked.
- The named lock relies on MySQL named locks working on DreamHost's server. This is not verified live, but the project already relies on the same feature in four places. The row claim is the independent backstop.
- The runner does not check whether a link inside a queued email has already expired (verification links last 24 hours, reset links 60 minutes). After a long outage it may send dead links. → follow-up.
- It does not delete old rows or scrub `paramsJSON`. → follow-up.

---

## 3. Files to create or change, in detail

### 3.1 `web/CueRCode.net/_functions/fn_email.php` (change)

Keep everything already there, including the #117 comments. Add the new code in clearly headed sections after `computeEmailBackoffMinutes()`, and rewrite the queue functions as described. Also update the file header:
- Change `@version 1.0.0` to `1.1.0`.
- Add a paragraph saying the queue is emptied by `_includes/cron/process_email_queue.php` (run by cron every minute, #112), with the page-shutdown hook as an off-by-default fallback.
- Add `@see _includes/cron/process_email_queue.php`.

#### 3.1.1 New constants (each inside `if (!defined(...))`, like the existing ones, each with a "why" comment)

```text
CUERCODE_EMAIL_QUEUE_DEFAULT_BATCH              = 20
CUERCODE_EMAIL_QUEUE_MAX_BATCH                  = 50
CUERCODE_EMAIL_QUEUE_SHUTDOWN_BATCH             = 2      (page-shutdown fallback; matches old behaviour)
CUERCODE_EMAIL_QUEUE_STUCK_MINUTES              = 10     (see 2.1 layer 3; worst legitimate "sending" time is ~20 s
                                                          typical / ~80 s absolute: two 10 s curl timeouts + a 60 s DB
                                                          read timeout - 10 minutes is a wide safety margin)
CUERCODE_EMAIL_QUEUE_STUCK_BATCH                = 50     (max stuck rows recovered per run)
CUERCODE_EMAIL_QUEUE_TIME_BUDGET_SECONDS        = 45
CUERCODE_EMAIL_QUEUE_SHUTDOWN_TIME_BUDGET_SECONDS = 20
CUERCODE_EMAIL_QUEUE_LOCK_PREFIX                = 'cuercode_emailq:'
CUERCODE_EMAIL_QUEUE_LOCK_NAME_SQL              = "CONCAT(?, LEFT(SHA2(COALESCE(DATABASE(), ''), 256), 32))"
CUERCODE_EMAIL_QUEUE_GATEWAY_KEYS               = ['lock','unlock','findStuck','recoverStuck','findDue','claim','load',
                                                   'markSent','markRetry','markGaveUp','release','countDue',
                                                   'countOverdue','countByStatus']
CUERCODE_EMAIL_QUEUE_NOTE_STUCK_RETRY           = 'Recovered: an earlier run stopped part-way through sending this email, so it may or may not have been delivered. Counted as one attempt; retrying.'
CUERCODE_EMAIL_QUEUE_NOTE_STUCK_GAVE_UP         = 'Gave up: an earlier run stopped part-way through sending this email on its last allowed attempt.'
CUERCODE_EMAIL_RUNNER_DB_READ_TIMEOUT_SECONDS   = 60
CUERCODE_EMAIL_RUNNER_CONNECT_TIMEOUT_SECONDS   = 15
CUERCODE_EMAIL_RUNNER_EXIT_OK          = 0
CUERCODE_EMAIL_RUNNER_EXIT_CRASH       = 1
CUERCODE_EMAIL_RUNNER_EXIT_USAGE       = 2
CUERCODE_EMAIL_RUNNER_EXIT_DATABASE    = 3
CUERCODE_EMAIL_RUNNER_EXIT_EMAIL_SETUP = 4
CUERCODE_EMAIL_RUNNER_EXIT_GAVE_UP     = 5
```

Explain the lock-name expression in a comment:
- MySQL lock names are shared by every database on the server, and DreamHost MySQL is shared between customers, so the name must include this database's identity.
- The SHA-256 hash keeps the name under MySQL's 64-character limit: 16 + 32 = 48 characters.
- `DATABASE()` is read from the live connection, so it is always correct.
- It is safe to join this constant into SQL text because it holds no user input; the prefix is still passed as a bound `?`.

#### 3.1.2 Pure helpers (no database, no network, fully unit-tested)

`decideEmailQueueFailure(int $attemptsBefore, int $maxAttempts): array`
- Returns `['attemptsAfter' => int, 'giveUp' => bool, 'retryInMinutes' => int|null]`.
- `attemptsAfter = max(0, attemptsBefore) + 1`.
- If `attemptsAfter >= maxAttempts`, then `giveUp = true` and `retryInMinutes = null`.
- Otherwise `giveUp = false` and `retryInMinutes = computeEmailBackoffMinutes(attemptsAfter)`.
- The rule is identical to today's `settleEmailQueueFailure()`, pulled out so it can be tested.

`classifyEmailSendError(?string $error): string` returns `'system' | 'transient' | 'message'`. Check in this exact order:
1. `null`, or empty after `trim` → `'message'`
2. starts with `'skipped:'` → `'system'`
3. starts with `'failed: could not initialise curl'` → `'system'`
4. `preg_match('/^HTTP (401|403|404)\b/', $text) === 1` → `'system'`
5. `preg_match('/^HTTP (0|408|429|5[0-9][0-9])\b/', $text) === 1` → `'transient'`
6. `str_contains($text, 'transport error')` → `'transient'`
7. starts with `'failed: the sender stopped with an unexpected error'` → `'transient'`
8. anything else → `'message'`

Comment on why 404 counts as "system": for `sendMail`, 404 means the *sender* mailbox in the web address is wrong, not the recipient. The strings are exactly what `sendEmailNow()` produces (`fn_email.php:580-604` and 689-703).

`emailQueueSafeDetail(string $text, int $maxLength = 200): string`
1. Replace control characters: `preg_replace('/[\x00-\x1F\x7F]+/', ' ', ...)`.
2. Mask anything that looks like an email address: `preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+/', '[address]', ...)`.
3. Collapse runs of whitespace to one space, then `trim`.
4. Clamp `$maxLength` to at least 20. If `mb_strlen` is longer, cut with `mb_substr($clean, 0, $maxLength - 3) . '...'`.
5. If `preg_replace` returns non-string, fall back to `''`.

Used for every stored `lastError` (limit 500) and every printed line (limit 500).

`checkEmailQueueRunnerSettings(callable $getSetting): array` returns `['state' => 'disabled'|'incomplete'|'ready', 'enabled' => bool, 'problems' => list<string>]`.
- `enabled = (bool) $getSetting('email_enabled', false)`.
- `problems` is **always** worked out (so `--check` can show them even when email is off):
  - provider = `strtolower(trim((string) $getSetting('email_provider', 'graph')))`, with empty treated as `'graph'`. If it is not `'graph'`, add `'email_provider is not "graph" (Microsoft Graph is the only sender built so far)'`. Do not print the value.
  - For each of `graph_tenant_id`, `graph_client_id`, `graph_client_secret`, `graph_sender_mailbox`: read with default `''`. If `!is_scalar($value) || trim((string) $value) === ''`, add `"<key> is empty or could not be read"`.
- State: if not enabled → `'disabled'`; else if there are problems → `'incomplete'`; else → `'ready'`.
- Comment: values are **never** returned or printed, only names. An undecryptable sensitive value comes back from `getConfig()` as the default `''`, which is why the wording says "or could not be read". Suggest checking that `ENCRYPTION_KEY` has not changed.

`parseEmailQueueRunnerArgs(array $argv): array` returns `['ok'=>bool,'error'=>?string,'help'=>bool,'quiet'=>bool,'check'=>bool,'limit'=>int]`.
- Skip index 0 (the script path): `array_slice(array_values($argv), 1)`.
- Accepted options: `--help`/`-h`, `--quiet`/`-q`, `--check`, and `--limit=N`.
  - `N` must match `/^[0-9]{1,3}$/` and lie between 1 and `CUERCODE_EMAIL_QUEUE_MAX_BATCH`. Otherwise set `ok=false` with error `'the --limit value must be a whole number from 1 to 50'`.
- Anything else sets `ok=false` with error `'unknown option: ' . emailQueueSafeDetail($arg, 40)`.
- Only the **first** error is kept.
- Default `limit = CUERCODE_EMAIL_QUEUE_DEFAULT_BATCH`.

`emailQueueRunnerUsage(): string`
- Plain-English help: what it does; usage lines; each option; the exit-code table 0-5 in words; "Run it from a DreamHost cron job every minute - see DEV_NOTES.md, 'Email queue runner'".
- Build the numbers from the constants by concatenation.
- Join lines with `PHP_EOL`.

`emptyEmailQueueRunResult(): array` returns this exact shape (tests depend on the keys):
```text
status        'ok'   ('ok'|'busy'|'stalled'|'lock_error'|'db_error'|'system_problem')
problem       null   (safe one-line text when status is not ok/busy)
processed     0   claimed 0   sent 0   retrying 0   gaveUp 0   released 0
skipped       0   recovered 0   recordFailures 0
waiting       null (int after a successful count)
stoppedEarly  false   stopReason null ('time_budget'|'temporary_problem')   stopDetail null
stuckMinutes  CUERCODE_EMAIL_QUEUE_STUCK_MINUTES (echo of the value actually used)
```

`emailQueueRunnerExitCode(array $result): int`
1. `lock_error` or `db_error` → 3
2. `system_problem` or `stalled` → 4
3. `gaveUp > 0` → 5
4. otherwise → 0

`formatEmailQueueRunReport(array $result, float $seconds): array` returns a list of lines. Plain English; wording may be polished but must keep these facts; counts only.
- `busy`: `'CueRCode email queue: another run is already working through the queue - nothing to do this time.'`
- `stalled`: `'CueRCode email queue: ' . problem . '. It clears by itself when that run ends or its database connection closes; if this keeps happening, look for a stuck PHP process on the server.'`
- `lock_error` / `db_error`: `'CueRCode email queue: ' . problem . ' - nothing more was sent (details are in the error log).'`, then the counts line.
- `system_problem`: `'CueRCode email queue: sending is blocked - ' . problem . '. The email was put back without using up an attempt; nothing more will be sent until this is fixed.'`, then the counts line.
- Counts line (always, for `ok` and after the two lines above): `sprintf('CueRCode email queue: sent %d, will retry %d, gave up on %d, %s (%.1f seconds).', ...)`.
  - The `%s` is `'<n> still waiting'`, or `'number still waiting unknown'` when `waiting` is null. Use `if`/`else`, not a ternary.
- Extra lines when relevant:
  - `recovered > 0`: `'Put back <n> email(s) that an earlier run left part-way through sending (they may arrive twice).'`
  - `stopReason === 'temporary_problem'`: `'Stopped early: Microsoft Graph did not accept an email (' . stopDetail . '); it will be retried automatically.'`
  - `stopReason === 'time_budget'`: `'Stopped early to stay within the time limit; the rest will go on the next run.'`
  - `gaveUp > 0`: `'Gave up on <n> email(s) after all their attempts - they now have status "failed" in tblEmailQueue (DEV_NOTES.md explains how to re-send them).'`
  - `recordFailures > 0`: `'Could not record the result for <n> email(s); they may be sent again after <stuckMinutes> minutes (details are in the error log).'`

#### 3.1.3 The core batch function

`validateEmailQueueGateway(array $gateway): void`
- For each key in `CUERCODE_EMAIL_QUEUE_GATEWAY_KEYS`: if it is not set or not `is_callable`, throw `\InvalidArgumentException('Email queue gateway is missing the "<key>" operation')`.
- Comment: this is a programming error, not a runtime condition.

`runEmailQueueBatch(array $gateway, callable $sender, array $options = []): array` — near-final logic below. Transcribe it with full comments. Keep the order of steps exactly.

```php
validateEmailQueueGateway($gateway);

// ⚙️ options (each clamped)
$limit = CUERCODE_EMAIL_QUEUE_DEFAULT_BATCH;
if (isset($options['limit'])) { $limit = (int) $options['limit']; }
$limit = max(1, min($limit, CUERCODE_EMAIL_QUEUE_MAX_BATCH));
$stuckMinutes = CUERCODE_EMAIL_QUEUE_STUCK_MINUTES;
if (isset($options['stuckMinutes'])) { $stuckMinutes = (int) $options['stuckMinutes']; }
$stuckMinutes = max(1, min($stuckMinutes, 1440));
$timeBudget = (float) CUERCODE_EMAIL_QUEUE_TIME_BUDGET_SECONDS;
if (isset($options['timeBudgetSeconds'])) { $timeBudget = (float) $options['timeBudgetSeconds']; }
$timeBudget = max(1.0, min($timeBudget, 600.0));
$useLock = true;                                   // tests + the real-DB check may pass false; production never does
if (array_key_exists('useLock', $options) && $options['useLock'] === false) { $useLock = false; }
if (isset($options['clock']) && is_callable($options['clock'])) { $clock = $options['clock']; }
else { $clock = static function (): float { return microtime(true); }; }

$result = emptyEmailQueueRunResult();
$result['stuckMinutes'] = $stuckMinutes;
$startedAt = (float) $clock();
$haveLock = false;

// 🔒 Layer 2: the named lock
if ($useLock) {
    try { $lockOutcome = $gateway['lock'](); } catch (\Throwable $e) { $lockOutcome = null; }
    if ($lockOutcome === false) {
        $result['status'] = 'busy';
        try { $overdue = $gateway['countOverdue']($stuckMinutes); } catch (\Throwable $e) { $overdue = null; }
        if (is_int($overdue) && $overdue > 0) {
            $result['status']  = 'stalled';
            $result['problem'] = 'another run is holding the queue lock, but ' . $overdue
                . ' email(s) have been waiting for more than ' . $stuckMinutes
                . ' minutes - that run may be stuck, or sending may be falling behind';
        }
        return $result;                              // we do NOT hold the lock -> never unlock here
    }
    if ($lockOutcome !== true) {
        $result['status']  = 'lock_error';
        $result['problem'] = 'could not take the email queue lock (database error)';
        return $result;
    }
    $haveLock = true;
}

try {
    // 🧯 Layer 3: recover stuck rows (inside the lock)
    $stuckRows = $gateway['findStuck']($stuckMinutes, CUERCODE_EMAIL_QUEUE_STUCK_BATCH);
    if (!is_array($stuckRows)) { db_error('could not read the email queue (database error)'); return $result; }
    $recoveredIDs = [];
    foreach ($stuckRows as $stuckRow) {
        $emailID      = (int) $stuckRow['emailID'];
        $attemptsSeen = (int) $stuckRow['attempts'];
        $decision     = decideEmailQueueFailure($attemptsSeen, (int) $stuckRow['maxAttempts']);
        if ($decision['giveUp']) { $retryIn = null; $note = CUERCODE_EMAIL_QUEUE_NOTE_STUCK_GAVE_UP; }
        else { $retryIn = $decision['retryInMinutes']; $note = CUERCODE_EMAIL_QUEUE_NOTE_STUCK_RETRY; }
        if ($gateway['recoverStuck']($emailID, $attemptsSeen, $decision['attemptsAfter'], $retryIn, $note, $stuckMinutes) === true) {
            $recoveredIDs[] = $emailID;
            if ($decision['giveUp']) { $result['gaveUp']++; } else { $result['recovered']++; }
        }
    }
    if (count($recoveredIDs) > 0 && function_exists('logError')) {
        logError('warning', 'Email queue: put back emails left part-way through sending', 'emailIDs: ' . implode(', ', $recoveredIDs));
    }

    // 📋 Due rows
    $dueIDs = $gateway['findDue']($limit);
    if (!is_array($dueIDs)) { db_error('could not read the email queue (database error)'); return $result; }

    foreach ($dueIDs as $dueID) {
        $emailID = (int) $dueID;
        if (((float) $clock() - $startedAt) >= $timeBudget) { stoppedEarly='time_budget'; break; }
        $result['processed']++;

        $claimed = $gateway['claim']($emailID);            // Layer 1
        if ($claimed === null) { db_error('could not update the email queue (database error)'); break; }
        if ($claimed !== true) { $result['skipped']++; continue; }   // another runner got it / no longer due
        $result['claimed']++;

        $row = $gateway['load']($emailID);                  // authoritative, post-claim values
        if (!is_array($row)) { $result['skipped']++; $result['recordFailures']++; log warning; continue; } // left 'sending' -> recovered later

        $attemptsBefore = (int) $row['attempts'];
        $maxAttempts    = (int) $row['maxAttempts'];
        $toName = null;
        if (isset($row['toName']) && $row['toName'] !== null && $row['toName'] !== '') { $toName = (string) $row['toName']; }

        $params = json_decode((string) $row['paramsJSON'], true);
        if (!is_array($params)) { $params = []; }
        try {
            $rendered = renderEmailTemplate((string) $row['templateKey'], $params);
            $htmlBody = $rendered[1];
        } catch (\Throwable $e) {
            // message-level: this email's own template/params are broken
            recordMessageFailure($emailID, $attemptsBefore, $maxAttempts,
                'failed: the template could not be rendered (' . emailQueueSafeDetail($e->getMessage(), 300) . ')');
            continue;
        }

        $sendOk = false; $sendError = null;
        try {
            $sendOutcome = $sender((string) $row['toAddress'], (string) $row['subject'], $htmlBody, $toName);
            if (is_array($sendOutcome) && array_key_exists(0, $sendOutcome) && $sendOutcome[0] === true) { $sendOk = true; }
            elseif (is_array($sendOutcome) && array_key_exists(1, $sendOutcome) && is_string($sendOutcome[1]) && $sendOutcome[1] !== '') { $sendError = $sendOutcome[1]; }
            else { $sendError = 'failed: the sender gave no reason'; }
        } catch (\Throwable $e) {
            $sendError = 'failed: the sender stopped with an unexpected error (' . get_class($e) . ')';
        }

        if ($sendOk) {
            $result['sent']++;
            if ($gateway['markSent']($emailID) !== true) { $result['recordFailures']++; log warning 'sent but could not record'; }
            continue;
        }

        $kind = classifyEmailSendError($sendError);
        $safe = emailQueueSafeDetail((string) $sendError, 500);
        if ($kind === 'system') {
            if ($gateway['release']($emailID, 'Not sent yet - sending is blocked: ' . $safe) === true) { $result['released']++; }
            else { $result['recordFailures']++; }
            $result['status']  = 'system_problem';
            $result['problem'] = emailQueueSafeDetail((string) $sendError, 200);
            break;
        }
        recordMessageFailure($emailID, $attemptsBefore, $maxAttempts, $safe);   // retry or give up
        if ($kind === 'transient') {
            $result['stoppedEarly'] = true;
            $result['stopReason']   = 'temporary_problem';
            $result['stopDetail']   = emailQueueSafeDetail((string) $sendError, 200);
            break;
        }
    }

    if ($result['status'] === 'ok' || $result['status'] === 'system_problem') {
        try { $waiting = $gateway['countDue'](); } catch (\Throwable $e) { $waiting = null; }
        if (is_int($waiting)) { $result['waiting'] = $waiting; }
    }
} catch (\Throwable $e) {
    $result['status']  = 'db_error';
    $result['problem'] = 'unexpected error while working through the queue (' . get_class($e) . ')';
    if (function_exists('logError')) { logError('error', 'Email queue batch stopped by an unexpected error', $e->getMessage()); }
} finally {
    if ($haveLock) {
        try { $gateway['unlock'](); } catch (\Throwable $e) { /* the lock is released anyway when the connection closes */ }
    }
}
return $result;
```

Notes for the transcription:
- `db_error(...)` and `stoppedEarly=...` above are shorthand in this plan only. Write them out as plain assignments to `$result['status']`, `$result['problem']`, `$result['stoppedEarly']` and `$result['stopReason']`.
- `recordMessageFailure(...)` is an **inline block**, not a new global function:
  - call `decideEmailQueueFailure()`;
  - if `giveUp`, call `$gateway['markGaveUp']($emailID, attemptsAfter, $safe)` and add 1 to `gaveUp` (on false, add 1 to `recordFailures`);
  - otherwise call `$gateway['markRetry']($emailID, attemptsAfter, retryInMinutes, $safe)` and add 1 to `retrying` (on false, add 1 to `recordFailures`).
- A `return` inside `try` still runs `finally`, so the lock is always released. Say so in a comment.
- "log warning" means `if (function_exists('logError')) { logError('warning', ..., 'emailID ' . $emailID); }`. Never include an address.

#### 3.1.4 Database functions (the real "gateway" operations)

Each function follows the same pattern:
- It starts with `if (!function_exists('dbQuery')) { return <failure value>; }`.
- It wraps its `dbQuery()` in `try/catch (\Throwable $e)`, calls `logError('error', '<short title>', $e->getMessage())` when that exists, and returns the failure value.
- It closes every statement: `$stmt->close()`. For SELECTs, read with `get_result()`/`fetch_assoc()` first.
- It uses exactly the SQL below. Keep the backticks and the `status` values exactly as written, because the tests check the text.

| Function | Returns | SQL (types / params) |
| --- | --- | --- |
| `acquireEmailQueueRunLock(): ?bool` | true = got it, false = taken, null = error | `'SELECT GET_LOCK(' . CUERCODE_EMAIL_QUEUE_LOCK_NAME_SQL . ', 0) AS `lockResult`'` · `'s'` · `[CUERCODE_EMAIL_QUEUE_LOCK_PREFIX]`. The result is `1` → true, `0` → false, anything else → null. |
| `releaseEmailQueueRunLock(): void` | best effort, logs a warning on failure | `'SELECT RELEASE_LOCK(' . CUERCODE_EMAIL_QUEUE_LOCK_NAME_SQL . ') AS `releaseResult`'` · `'s'` · `[CUERCODE_EMAIL_QUEUE_LOCK_PREFIX]` |
| `findStuckEmailQueueRows(int $stuckMinutes, int $limit): ?array` | a list of `['emailID'=>int,'attempts'=>int,'maxAttempts'=>int]`, or null | see A |
| `recoverStuckEmailQueueRow(int $emailID, int $attemptsSeen, int $attemptsAfter, ?int $retryInMinutes, string $note, int $stuckMinutes): bool` | true when `affected_rows === 1` | B1 when `$retryInMinutes !== null`, else B2 |
| `findDueEmailQueueIDs(int $limit): ?array` | a list of ints, or null | see C |
| `claimEmailQueueRow(int $emailID): ?bool` | true when `affected_rows === 1`; false otherwise; null on error | see D |
| `loadClaimedEmailQueueRow(int $emailID): ?array` | the row (string/int values as MySQL gives them), or null | see E |
| `settleEmailQueueSuccess(int $emailID): bool` (**existing name**; change `void` to `bool`) | true when `affected_rows === 1` | see F |
| `settleEmailQueueRetry(int $emailID, int $attemptsAfter, int $retryInMinutes, string $errorDetail): bool` | same | see G |
| `settleEmailQueueGaveUp(int $emailID, int $attemptsAfter, string $errorDetail): bool` | same | see H |
| `releaseEmailQueueClaim(int $emailID, string $reason): bool` | same | see I |
| `countDueEmailQueueRows(): ?int` | a count, or null | see J |
| `countOverdueEmailQueueRows(int $minutes): ?int` | a count, or null | see K |
| `countEmailQueueRowsByStatus(): ?array` | `['queued'=>n,'sending'=>n,'sent'=>n,'failed'=>n,'skipped'=>n]` with every key present (default 0), or null | see L |
| `checkEmailQueueDatabase(): ?string` | null = fine; otherwise a safe sentence | see M |
| `settleEmailQueueFailure(int $emailID, int $attemptsBefore, int $maxAttempts, string $errorDetail): bool` (**existing, kept for compatibility**; `void` → `bool`) | uses `decideEmailQueueFailure()`, then `settleEmailQueueGaveUp()` or `settleEmailQueueRetry()`. The docblock says it is kept only so any outside caller keeps working, and that the batch no longer uses it. | — |

In every write, store error text through `emailQueueSafeDetail($text, 500)`, so no address ends up in `lastError`.

```sql
-- A  findStuckEmailQueueRows   types 'ii'  [$stuckMinutes, $limit]
SELECT `emailID`, `attempts`, `maxAttempts`
FROM `tblEmailQueue`
WHERE `status` = 'sending'
  AND `updatedAt` < DATE_SUB(NOW(), INTERVAL ? MINUTE)
ORDER BY `updatedAt` ASC, `emailID` ASC
LIMIT ?

-- B1 recover -> retry   types 'isiiii'  [$attemptsAfter, $note, $retryInMinutes, $emailID, $attemptsSeen, $stuckMinutes]
UPDATE `tblEmailQueue`
SET `status` = 'queued', `attempts` = ?, `lastError` = ?,
    `nextAttemptAt` = DATE_ADD(NOW(), INTERVAL ? MINUTE), `updatedAt` = NOW()
WHERE `emailID` = ? AND `status` = 'sending' AND `attempts` = ?
  AND `updatedAt` < DATE_SUB(NOW(), INTERVAL ? MINUTE)

-- B2 recover -> give up  types 'isiii'  [$attemptsAfter, $note, $emailID, $attemptsSeen, $stuckMinutes]
UPDATE `tblEmailQueue`
SET `status` = 'failed', `attempts` = ?, `lastError` = ?, `updatedAt` = NOW()
WHERE `emailID` = ? AND `status` = 'sending' AND `attempts` = ?
  AND `updatedAt` < DATE_SUB(NOW(), INTERVAL ? MINUTE)

-- C  findDueEmailQueueIDs   types 'i'  [$limit]
SELECT `emailID`
FROM `tblEmailQueue`
WHERE `status` IN ('queued', 'failed')
  AND (`nextAttemptAt` IS NULL OR `nextAttemptAt` <= NOW())
  AND `attempts` < `maxAttempts`
ORDER BY `nextAttemptAt` ASC, `emailID` ASC
LIMIT ?

-- D  claimEmailQueueRow   types 'i'  [$emailID]     (THE duplicate guard - comment it heavily)
UPDATE `tblEmailQueue`
SET `status` = 'sending', `updatedAt` = NOW()
WHERE `emailID` = ?
  AND `status` IN ('queued', 'failed')
  AND (`nextAttemptAt` IS NULL OR `nextAttemptAt` <= NOW())
  AND `attempts` < `maxAttempts`

-- E  loadClaimedEmailQueueRow   types 'i'  [$emailID]
SELECT `emailID`, `toAddress`, `toName`, `templateKey`, `paramsJSON`, `subject`, `attempts`, `maxAttempts`
FROM `tblEmailQueue`
WHERE `emailID` = ? AND `status` = 'sending'
LIMIT 1

-- F  settleEmailQueueSuccess   types 'i'  [$emailID]
UPDATE `tblEmailQueue`
SET `status` = 'sent', `sentAt` = NOW(), `lastError` = NULL, `updatedAt` = NOW()
WHERE `emailID` = ? AND `status` = 'sending'

-- G  settleEmailQueueRetry   types 'isii'  [$attemptsAfter, $errorDetail, $retryInMinutes, $emailID]
UPDATE `tblEmailQueue`
SET `status` = 'queued', `attempts` = ?, `lastError` = ?,
    `nextAttemptAt` = DATE_ADD(NOW(), INTERVAL ? MINUTE), `updatedAt` = NOW()
WHERE `emailID` = ? AND `status` = 'sending'

-- H  settleEmailQueueGaveUp   types 'isi'  [$attemptsAfter, $errorDetail, $emailID]
UPDATE `tblEmailQueue`
SET `status` = 'failed', `attempts` = ?, `lastError` = ?, `updatedAt` = NOW()
WHERE `emailID` = ? AND `status` = 'sending'

-- I  releaseEmailQueueClaim   types 'si'  [$reason, $emailID]   (attempts NOT changed)
UPDATE `tblEmailQueue`
SET `status` = 'queued', `lastError` = ?, `updatedAt` = NOW()
WHERE `emailID` = ? AND `status` = 'sending'

-- J  countDueEmailQueueRows   (no params)
SELECT COUNT(*) AS `dueCount`
FROM `tblEmailQueue`
WHERE `status` IN ('queued', 'failed')
  AND (`nextAttemptAt` IS NULL OR `nextAttemptAt` <= NOW())
  AND `attempts` < `maxAttempts`

-- K  countOverdueEmailQueueRows   types 'ii'  [$minutes, $minutes]
SELECT COUNT(*) AS `overdueCount`
FROM `tblEmailQueue`
WHERE (
        `status` IN ('queued', 'failed')
    AND `attempts` < `maxAttempts`
    AND COALESCE(`nextAttemptAt`, `createdAt`) <= DATE_SUB(NOW(), INTERVAL ? MINUTE)
) OR (
        `status` = 'sending'
    AND `updatedAt` < DATE_SUB(NOW(), INTERVAL ? MINUTE)
)

-- L  countEmailQueueRowsByStatus   (no params)
SELECT `status`, COUNT(*) AS `rowCount` FROM `tblEmailQueue` GROUP BY `status`

-- M  checkEmailQueueDatabase   (no params) - proves the connection AND that the table exists
SELECT 1 AS `ok` FROM `tblEmailQueue` LIMIT 1
```

Comments to include:
- On **D**: this used to check only the status. A runner working from an out-of-date list could then re-send an email another runner had just rescheduled, ignoring the back-off and losing the attempt count (#112). Re-checking "due" and "attempts left" in the same statement closes that. MySQL applies the whole `WHERE` atomically against the latest committed row.
- On **F-I**: `AND status = 'sending'` means only the runner that claimed the row can finish it. A late or confused writer changes nothing (`affected_rows` 0), which is reported as a record failure.
- On `affected_rows`: MySQL reports *changed* rows by default (mysqli is not opened with `MYSQLI_CLIENT_FOUND_ROWS`). Every guarded write here changes `status`, so 1 reliably means "we did it".
- On **M**: failures print a generic sentence only, `'could not reach the database or the email queue table (details are in the error log)'`. Never print the driver message, which can contain the database user and host.

`emailQueueDatabaseGateway(): array` returns the production gateway as **string function names** (clear and easy to grep):
```php
[
  'lock' => 'acquireEmailQueueRunLock',      'unlock' => 'releaseEmailQueueRunLock',
  'findStuck' => 'findStuckEmailQueueRows',  'recoverStuck' => 'recoverStuckEmailQueueRow',
  'findDue' => 'findDueEmailQueueIDs',       'claim' => 'claimEmailQueueRow',
  'load' => 'loadClaimedEmailQueueRow',      'markSent' => 'settleEmailQueueSuccess',
  'markRetry' => 'settleEmailQueueRetry',    'markGaveUp' => 'settleEmailQueueGaveUp',
  'release' => 'releaseEmailQueueClaim',     'countDue' => 'countDueEmailQueueRows',
  'countOverdue' => 'countOverdueEmailQueueRows', 'countByStatus' => 'countEmailQueueRowsByStatus',
]
```

#### 3.1.5 The testable "main" for the command line

`emailQueueRunnerMain(array $args, array $env): int`

`$env` must contain these keys, or an `\InvalidArgumentException` is thrown (programming error):
- `dbCheck` (callable, returns ?string)
- `getSetting` (callable($key, $default))
- `gateway` (array)
- `sender` (callable)
- `out` (callable(string))
- `err` (callable(string))
- `clock` (optional callable, returns float)

Every line is written through `emailQueueSafeDetail($line, 500)` before being passed to `out`/`err`. Flow:
1. If `!$args['ok']`: `err('CueRCode email queue: ' . $args['error'] . '.')`, then every line of the usage text to `err`. Return 2.
2. If `$args['help']`: every usage line to `out`. Return 0.
3. Read `$started` from the clock. If `dbCheck()` gives a non-null `$problem`: `err('CueRCode email queue: ' . $problem . ' - nothing was sent.')`. Return 3.
4. `$settings = checkEmailQueueRunnerSettings($env['getSetting'])`.
5. If `$args['check']`: return `emailQueueRunnerCheck($settings, $env['gateway'], $out, $err)` (below).
6. If state is `'disabled'`:
   - unless quiet, `out('CueRCode email queue: email sending is switched off (email_enabled = 0), so nothing was sent. ' . <n> . ' email(s) are waiting.')`, where n comes from `gateway['countDue']()`; if that is null, say "an unknown number of";
   - return 0;
   - never claim anything.
7. If state is `'incomplete'`: `err('CueRCode email queue: email is switched on, but it cannot send yet: ' . implode('; ', problems) . '. Nothing was sent.')`. Return 4.
8. `$result = runEmailQueueBatch($env['gateway'], $env['sender'], ['limit' => $args['limit'], 'clock' => $clock])`.
9. `$code = emailQueueRunnerExitCode($result)` and `$lines = formatEmailQueueRunReport($result, clock() - $started)`.
10. Output:
    - if `$code !== 0` **or** `recovered > 0` **or** `recordFailures > 0` → all lines to `err`, even with `--quiet`;
    - else, unless quiet → lines to `out`;
    - quiet and fine → print nothing.
11. Return `$code`.

`emailQueueRunnerCheck(array $settings, array $gateway, callable $out, callable $err): int` (check mode — sends nothing and claims nothing)
- Read `$counts = gateway['countByStatus']()` and `$due = gateway['countDue']()`. If either is null: `err(...could not read the queue...)`, return 3.
- Lines:
  - `'CueRCode email queue - setup check (nothing is sent in this mode)'`
  - `'Database: reachable, and the email queue table was found.'`
  - `'Email sending: switched on'` or `'Email sending: switched off (email_enabled = 0)'`
  - `'Microsoft Graph settings: complete'` or `'Microsoft Graph settings: NOT ready - ' . implode('; ', problems)`
  - `'Queue: <due> due now; <queued> queued in total; <sending> being sent; <sent> sent; <failed> given up.'`
- If `state === 'incomplete'`: all lines to `err`, return 4. Otherwise: all lines to `out`, return 0. (Check mode ignores `--quiet`, because its whole purpose is to show something.)

`emailQueueProductionEnvironment(): array` (used only by the CLI script):
```php
[
  'dbCheck'    => 'checkEmailQueueDatabase',
  'getSetting' => 'getConfig',
  'gateway'    => emailQueueDatabaseGateway(),
  'sender'     => 'sendEmailNow',
  'out'        => static function (string $line): void { if (defined('STDOUT')) { fwrite(STDOUT, $line . PHP_EOL); } else { echo $line . PHP_EOL; } },
  'err'        => static function (string $line): void { if (defined('STDERR')) { fwrite(STDERR, $line . PHP_EOL); } else { error_log($line); } },
  'clock'      => static function (): float { return microtime(true); },
]
```

#### 3.1.6 Existing functions to rewrite

**`processEmailQueue(int $max = 2): array`**
- Same name, parameters and return shape (`['processed','sent','failed','skipped']`). It becomes a thin wrapper:
  - keep the first check `if (!function_exists('dbQuery')) { return $summary; }` unchanged (the existing test depends on it);
  - then, inside `try`, call `runEmailQueueBatch(emailQueueDatabaseGateway(), 'sendEmailNow', ['limit' => $max, 'timeBudgetSeconds' => CUERCODE_EMAIL_QUEUE_SHUTDOWN_TIME_BUDGET_SECONDS])`;
  - map: `processed = processed`, `sent = sent`, `failed = retrying + gaveUp`, `skipped = skipped + released`;
  - `catch (\Throwable)`: log it and return the zeroed summary.
- Rewrite the docblock to describe the three layers, and say it is used by the page-shutdown fallback while the cron runner calls `runEmailQueueBatch()` via `emailQueueRunnerMain()`.

**`maybeProcessEmailQueueOnShutdown(): void`**
1. Keep `if (!function_exists('getConfig')) { return; }` first. The existing test at `test_email.php:300` depends on it.
2. Inside `try`: read `email_queue_autoprocess` **first** and return if it is off. Then read `email_enabled` and return if it is off. `catch (\Throwable)` → return. (Reading the opt-in switch first keeps the everyday cost to one cached settings read.)
3. `static $registered = false; if ($registered) { return; } $registered = true;` so it can register at most once per request.
4. `register_shutdown_function(static function (): void { try { ... } catch (\Throwable $e) { logError(...) } })`, where the body:
   - checks `checkEmailQueueRunnerSettings('getConfig')` and returns unless the state is `'ready'`;
   - calls `session_write_close()` if `session_status() === PHP_SESSION_ACTIVE`;
   - calls `fastcgi_finish_request()` if `function_exists('fastcgi_finish_request')`;
   - calls `processEmailQueue(CUERCODE_EMAIL_QUEUE_SHUTDOWN_BATCH)`.
5. Docblock updates:
   - replace the false "AFTER the response has been sent" and "NOT wired into bootstrap.php by design" text;
   - explain why it is now wired (the switch previously did nothing);
   - explain why it is still off by default (section 2.3);
   - explain why `session_write_close()` is called: otherwise the visitor's *next* page waits on the session file lock until sending finishes;
   - explain why `fastcgi_finish_request()` is called: otherwise the visitor's page itself waits.

### 3.2 `web/CueRCode.net/_includes/cron/process_email_queue.php` (NEW)

A thin wrapper. All logic lives in `fn_email.php`. Target: under about 120 lines of code, plus comments.

Header in the house format:
- `Path: _includes/cron/process_email_queue.php`
- description (what, why, and how DreamHost runs it)
- usage and exit-code summary
- "command-line only"
- `@package CueRCode`, `@subpackage Email`, `@copyright 2025–2026 MWBM Partners Ltd`, `@version 1.0.0`, `@since 2026-09-25 (#112)`
- `@see ../../_functions/fn_email.php` and `DEV_NOTES.md`

Statements, in this order:

0. **CLI guard, as the first executable statement, before any `require`:**
   ```php
   if (PHP_SAPI !== 'cli') {
       if (!headers_sent()) {
           header('HTTP/1.1 403 Forbidden');
       }
       exit('Access denied.');
   }
   ```
   Comment: the folder already has no web address and is deny-all. This is the third layer. `php-cgi` also counts as "not CLI", on purpose: the cron job must use the command-line `php`.
1. `require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_functions' . DIRECTORY_SEPARATOR . 'fn_email.php';`
   - Comment: loading only this file lets `--help` and bad options work on a machine with no database, before anything connects.
   - `fn_email.php` has no load-time dependencies.
   - Bootstrap later `require_once`s the same real path, which is a harmless no-op.
2. Read arguments. `$arguments = []; if (isset($_SERVER['argv']) && is_array($_SERVER['argv'])) { $arguments = $_SERVER['argv']; }`, then `$parsed = parseEmailQueueRunnerArgs($arguments);`.
   - Use `$_SERVER['argv']`, **not** `global $argv`, so the verifier's wrapper script can supply arguments.
3. If `!$parsed['ok']`: write the error line and the usage text to STDERR, then `exit(CUERCODE_EMAIL_RUNNER_EXIT_USAGE)`. If `$parsed['help']`: write the usage text to STDOUT, then `exit(CUERCODE_EMAIL_RUNNER_EXIT_OK)`.
4. `register_shutdown_function(static function (): void { if (!defined('CUERCODE_EMAIL_RUNNER_READY')) { fwrite(STDERR, 'CueRCode email queue: could not start - the site start-up code stopped before the runner could run (usually a missing or unreadable _auth_keys/auth_creds.php). Check the PHP error log.' . PHP_EOL); } });`
   - Comment: `db_connect.php` calls `exit(1)` inside the `require` when credentials are missing, so this is the only way to say something helpful. The exit code stays 1.
5. `ini_set('mysqlnd.net_read_timeout', (string) CUERCODE_EMAIL_RUNNER_DB_READ_TIMEOUT_SECONDS);` and `ini_set('default_socket_timeout', (string) CUERCODE_EMAIL_RUNNER_CONNECT_TIMEOUT_SECONDS);`
   - Comment: the default read timeout is 24 hours. A hung read would hold the queue lock that long, and the new value only applies to connections opened after this line.
6. `require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap.php';`
7. `set_exception_handler(static function (\Throwable $e): void { fwrite(STDERR, 'CueRCode email queue: stopped by an unexpected error (' . get_class($e) . ') - see the error log.' . PHP_EOL); if (function_exists('logError')) { logError('critical', 'Email queue runner crashed', $e->getMessage()); } exit(CUERCODE_EMAIL_RUNNER_EXIT_CRASH); });`
   - Comment: this replaces the web crash handler, which prints an HTML page.
8. `define('CUERCODE_EMAIL_RUNNER_READY', true);`
9. `try { $exitCode = emailQueueRunnerMain($parsed, emailQueueProductionEnvironment()); } catch (\Throwable $e) { <same plain-text message + logError>; $exitCode = CUERCODE_EMAIL_RUNNER_EXIT_CRASH; }`, then `exit($exitCode);`.

The file must **not** mention any row field (`toAddress`, `paramsJSON`, `subject`), and must not echo anything except through the helpers above.

Do **not** add a separate `.htaccess` in `cron/`. The parent's deny-all already applies, and a duplicate invites the two drifting apart. (Say this in the header comment.)

### 3.3 `web/CueRCode.net/_includes/bootstrap.php` (change)
- Add "Step 7" after Step 6 (before "Bootstrap Complete"), with a full comment block (why it is wired, why it is off by default, why web-only):
  ```php
  if (php_sapi_name() !== 'cli' && function_exists('maybeProcessEmailQueueOnShutdown')) {
      maybeProcessEmailQueueOnShutdown();
  }
  ```
- Add a line to the "Bootstrap Complete" summary list.
- In the comment on the `'fn_email.php'` entry in `$functionFiles` (lines 182-189), add one line saying it also provides the queue runner functions.
- Change nothing else.

### 3.4 `web/CueRCode.net/_includes/sql/install.sql` (comments only)
Update only the `--` comment block above `tblEmailQueue` (lines 421-445):
- The production path is now `_includes/cron/process_email_queue.php`, run by a DreamHost panel cron job every minute (#112). The page-shutdown hook is an off-by-default fallback.
- The claim re-checks "due" and "attempts left" in the same `UPDATE`, and every final update requires `status = 'sending'`.
- Add the lifecycle lines:
  - `sending -> queued (put back unchanged when sending is blocked by settings/Graph problems - no attempt used)`
  - `sending -> queued/failed (left 'sending' > 10 minutes, e.g. the runner stopped mid-send - counted as one attempt)`
- `updatedAt` doubles as "claimed at" for stuck detection.

**Do not change the `CREATE TABLE` statement or any column `COMMENT`** (not even the false "NEVER stores secrets" one; that goes to the follow-up issue).

### 3.5 `tests/test_email.php` (comments only)
- In the header's "⚠️ SKIPPED" block (lines 46-55) and the closing "NOT covered here" block (lines 333-342), add a note:
  - the claim, settle and recovery *logic* is now covered by `tests/test_email_queue_runner.php`, using an in-memory stand-in for the table;
  - the real SQL is covered by the optional `tests/integration/email_queue_real_mysql.php`.
- **Change no assertion.**

---

## 4. Schema migration
**None.** Everything uses existing columns (`status`, `attempts`, `maxAttempts`, `nextAttemptAt`, `updatedAt`, `lastError`, `sentAt`). The reasons are in 1.3 and 2.2.

---

## 5. Tests to add

### 5.1 `tests/test_email_queue_runner.php` (NEW, auto-discovered by `run.php`)

Header in the style of `test_email.php`: what is covered, what is not, and how the stand-in works. Start with `requireFn('fn_email.php');`.

**Rules for this file:**
- Every variable is prefixed `$eqr`.
- Any global helper function is prefixed `emailQueueRunnerTest`. Prefer closures in `$eqr...` variables.
- **Never define** `dbQuery`, `getDB`, `getConfig`, `logError` or `logActivity`.
- Never start the CLI except with `--help` or an invalid option. A plain run on a machine that has a real `auth_creds.php` could reach a real database and **send real email**. Put this warning in a comment.

**In-memory stand-in (the "fake store").** An `\stdClass` `$store` with:
- `rows` (keyed by `emailID`)
- `now` (int seconds, the "database clock", e.g. `1800000000`)
- `runClock` (float, used by the time budget)
- `lockHolder` (null or a runner name)
- `sends` (a list of `['runner','to','subject','body']`)
- `sendScript` (map from `toAddress` to a list of `[ok, err]` results, consumed in order; default `[true, null]`)
- `onSend` (an optional callable, run once, for interleaving)
- `secondsPerSend` (default 0)
- `failOps` (map of op name to true, making that op return null or false)
- counters `claimCalls` and `releaseCalls`

A row-factory closure makes rows with these defaults:
- `status 'queued'`, `attempts 0`, `maxAttempts 5`, `nextAttemptAt now`, `updatedAt now`, `createdAt now`
- `templateKey 'verification'`
- `paramsJSON` = `json_encode(['verificationUrl' => 'https://cuercode.net/verify-email?token=T' . $id])`
- `toAddress "user{$id}@example.com"`, `toName "User {$id}"`
- `subject 'Verify your CueRCode email address'`
- `lastError null`, `sentAt null`

A gateway-factory closure `($store, string $runnerName)` returns all 14 ops, mirroring the SQL in 3.1.4 exactly. For example:
- due = status in queued/failed, and (nextAttemptAt null or ≤ now), and attempts < maxAttempts
- `lock` sets `lockHolder` to the runner when it is null (true); else returns false
- `unlock` clears it only if this runner holds it
- `findStuck` = status sending and `updatedAt < now - m*60`
- `countOverdue` follows K

Every `runEmailQueueBatch()` call in this file passes `'clock' => static function () use ($store): float { return $store->runClock; }` so the time budget uses the simulated clock, never the real one.

A sender-factory closure `($store, string $runnerName)` does this for each send:
1. records the send;
2. advances `runClock` by `secondsPerSend`;
3. runs `onSend` once if set;
4. returns the next scripted result.

A result-counting helper checks that each `toAddress` appears exactly once in `sends`.

**Named cases.** Each bullet is one or more assertions with exactly this message text, so failures read clearly.

Pure helpers:
- `decideEmailQueueFailure: 1st failure of 5 -> retry in 2 minutes`; `(3,5) -> retry in 16`; `(4,5) -> give up (5th attempt)`; `(9,5) -> give up`; `(0,1) -> give up`
- `classifyEmailSendError`, one assertion per input:
  - `'skipped: email_enabled is 0'`→system
  - `'skipped: no Graph access token available'`→system
  - `'HTTP 401 | graph_error_code: InvalidAuthenticationToken'`→system
  - `'HTTP 403'`→system
  - `'HTTP 404 | graph_error_code: ErrorInvalidUser'`→system
  - `'failed: could not initialise curl'`→system
  - `'HTTP 429'`→transient
  - `'HTTP 500'`→transient
  - `'HTTP 503 | graph_error_code: ServiceUnavailable'`→transient
  - `'HTTP 0 | transport error (errno 28): Operation timed out'`→transient
  - `'failed: the sender stopped with an unexpected error (RuntimeException)'`→transient
  - `'HTTP 400 | graph_error_code: ErrorInvalidRecipients'`→message
  - `'failed: could not encode request payload'`→message
  - `'HTTP 4290'`→message (no word boundary)
  - `null`→message
  - `''`→message
- `emailQueueSafeDetail`:
  - masks `alice@example.com` to `[address]`
  - removes newlines and tabs
  - truncates to the limit with `...`
  - leaves `HTTP 503 | graph_error_code: X` unchanged
- `checkEmailQueueRunnerSettings`:
  - off → disabled
  - on + all four set → ready
  - on + empty secret + whitespace-only mailbox → incomplete, naming `graph_client_secret` and `graph_sender_mailbox`
  - on + provider `'workspace'` → incomplete
  - **no setting value** (for example the fake tenant ID `tenant-value-123`) appears in any problem text
- `parseEmailQueueRunnerArgs`:
  - `[]` → defaults (ok, limit 20)
  - `--quiet`, `-q`, `--check`, `--help`, `-h` set their flags
  - `--limit=5` → 5; `--limit=50` ok
  - `--limit=0`, `--limit=51`, `--limit=abc`, `--limit=` are each not ok
  - `--bogus` not ok, with an error mentioning `unknown option`
  - the first error is kept
- `emailQueueRunnerExitCode`: ok→0, busy→0, stalled→4, lock_error→3, db_error→3, system_problem→4, ok with gaveUp 2→5, db_error with gaveUp 1→3
- `emailQueueRunnerUsage` mentions `--quiet`, `--limit`, `--check`, `--help` and each exit code 0-5
- `validateEmailQueueGateway` throws `InvalidArgumentException` when an op is missing (assertThrows)

Batch behaviour (`runEmailQueueBatch` with the fake):
- **`sends every due email once and marks it sent`**: 3 due rows; all `sent`; the sender is called 3 times; each body contains that row's verification link.
- **`leaves alone rows that are not due, being sent, already sent, or out of attempts`**:
  - queued with `nextAttemptAt now+300`;
  - sending with a fresh `updatedAt`;
  - sent;
  - failed with attempts 5/5;
  - → 0 sends, and nothing changed.
- **`two runners at the same time never send the same email twice (with the lock)`**: 5 rows. Runner A's `onSend` (on its first send) runs Runner B's whole batch with its own gateway and sender.
  - B's status is `busy`, and B sent 0;
  - total sends = 5, each address exactly once;
  - after both, `lockHolder` is null.
- **`two runners at the same time never send the same email twice (lock bypassed - row claims alone)`**: same set-up with `useLock=false` for both.
  - Each address is sent exactly once across A and B;
  - B sent at least 1;
  - A's `skipped` is at least 1.
- **`an out-of-date list cannot re-send an email another runner just rescheduled (back-off respected)`**: `useLock=false`, rows X and Y. A's `onSend` (while sending X) runs B, and B's scripted result for Y is `[false, 'HTTP 400 | graph_error_code: ErrorInvalidRecipients']`.
  - Y was sent exactly **once** (by B);
  - Y is `queued`, attempts 1, `nextAttemptAt === now + 120`;
  - A's claim of Y failed (A `skipped` ≥ 1).
- **`a failed email is retried with back-off 2, 4, 8, 16 minutes, then given up`**: one row that always fails with HTTP 400. Loop 5 times:
  - run, assert state, and set `now = nextAttemptAt`;
  - after runs 1-4: queued, attempts n, `nextAttemptAt = now + 2^n*60`;
  - after run 5: `failed`, attempts 5, `gaveUp` 1 and exit code 5;
  - also, a run *before* the back-off expires sends nothing.
- **`one email's message-level failure does not stop the others`**: 3 rows, the middle one fails with HTTP 400. The other two are sent; the middle one is queued with attempts 1.
- **`a template that cannot render counts as a failed attempt for that email only`**: one row has `templateKey 'no_such_template'`. That row is queued with attempts 1 and a `lastError` containing `template`; the others are sent.
- **`sending blocked (no Graph token): email put back with no attempt used, run stopped, exit code 4`**: 3 rows, the first scripted `[false, 'skipped: no Graph access token available']`.
  - The first row is `queued`, attempts **0**, with `lastError` containing `blocked`;
  - rows 2 and 3 were never claimed (`claimCalls` 1);
  - status `system_problem`; exit code 4.
- **`Graph 401/403/404 block sending the same way`**: one case with `'HTTP 403'`, giving attempts 0 and status `system_problem`.
- **`temporary Graph trouble uses an attempt, schedules a retry and stops the run early`**: 3 rows, the first `[false, 'HTTP 503']`.
  - First row queued, attempts 1, retry in 2 minutes;
  - rows 2 and 3 untouched;
  - `stoppedEarly` true, `stopReason 'temporary_problem'`, status ok, exit code 0.
- **`a sender that throws is treated as temporary trouble`**: the sender throws `RuntimeException`. The row is queued with attempts 1, and the run is stopped early.
- **`stuck recovery: an email left "sending" for over 10 minutes is put back with one attempt used, and not re-sent in the same run`**: `updatedAt now-660`, attempts 0.
  - `recovered` 1; queued; attempts 1; `nextAttemptAt now+120`;
  - sender not called;
  - `lastError` equals `CUERCODE_EMAIL_QUEUE_NOTE_STUCK_RETRY`.
- **`an email "sending" for under 10 minutes is left alone (a live run may still be sending it)`**: `updatedAt now-300` → unchanged.
- **`a stuck email on its last attempt is given up, not re-sent`**: attempts 4 of 5 → `failed`, `gaveUp` 1.
- **`a database error while listing emails is reported, not mistaken for an empty queue`**: `failOps['findDue']` → status `db_error`, exit code 3, lock released.
- **`the lock is always released, even after an unexpected error`**: `markSent` throws → status `db_error`, and `lockHolder` null afterwards.
- **`lock error is reported as a database problem`**: `lock` returns null → `lock_error`, exit code 3, and nothing claimed.
- **`another run holding the lock while emails are overdue is reported as stalled`**: `lockHolder 'someone-else'` plus a due row with `nextAttemptAt now-660` → `stalled`, exit code 4, and the problem text mentions `stuck`. With no overdue rows → `busy`, exit code 0.
- **`time budget: stops claiming new emails once 45 seconds are used`**: `secondsPerSend 30` and 5 rows → 2 sent, `stoppedEarly` true, `stopReason 'time_budget'`.
- **`batch limit respected and clamped`**: 60 rows. `limit 5` → 5 sent; `limit 500` → 50; `limit 0` → 1.
- **`result could not be recorded is counted and reported`**: `markSent` returns false → `sent` 1, `recordFailures` 1, and the report mentions `could not record`.

`emailQueueRunnerMain` with fakes (`out`/`err` collect lines into arrays):
- `--help prints usage to the normal stream and exits 0`
- `a bad option exits 2 with the reason and usage on the error stream`
- `database unreachable: exit 3, one clear line on the error stream, nothing sent`
- `email switched off, quiet: exit 0, prints nothing, claims nothing`
- `email switched off, normal: says it is switched off and how many are waiting`
- `email on but settings incomplete: exit 4, names the empty settings, never their values`
- `normal run prints one summary line with counts and no addresses or subjects` (rows use `alice@example.com` and similar): assert no `@` in any line, and that the subject text is absent
- `quiet successful run prints nothing`
- `giving up on an email exits 5 and is reported on the error stream even when quiet`
- `another run busy, quiet: exit 0, prints nothing`
- `stalled: exit 4, reported on the error stream`
- `sending blocked: exit 4, reported on the error stream, attempts unchanged`
- `stuck emails put back are mentioned even when quiet (exit 0)`
- `--check reports settings and queue counts and sends nothing` (sender never called; `claimCalls` 0; exit 0)
- `--check with incomplete settings exits 4`

Source-text regression guards: read `fn_email.php` and cut out each function body with `strpos` between `'function name('` and the next `"\nfunction "`, following the style of `test_qrcodes.php:410-440`.
- The `claimEmailQueueRow` body contains `` `status` IN ('queued', 'failed') ``, `` `nextAttemptAt` <= NOW() ``, `` `attempts` < `maxAttempts` `` and `affected_rows === 1`.
- The bodies of `settleEmailQueueSuccess`, `settleEmailQueueRetry`, `settleEmailQueueGaveUp` and `releaseEmailQueueClaim` each contain `` AND `status` = 'sending' ``.
- The `findStuckEmailQueueRows` body contains `` `status` = 'sending' `` and `` `updatedAt` < DATE_SUB(NOW(), INTERVAL ? MINUTE) ``.
- The `recoverStuckEmailQueueRow` body contains `` `attempts` = ? `` and `` `updatedAt` < DATE_SUB(NOW(), INTERVAL ? MINUTE) ``.
- `acquireEmailQueueRunLock` contains `GET_LOCK(` and `', 0)`. `releaseEmailQueueRunLock` contains `RELEASE_LOCK(`. Both use `CUERCODE_EMAIL_QUEUE_LOCK_NAME_SQL`. The constant's value contains `DATABASE()` and, once the prefix is added, is at most 64 characters: `strlen('cuercode_emailq:') + 32 <= 64`.
- `processEmailQueue` contains `runEmailQueueBatch(` and `emailQueueDatabaseGateway()`.
- `maybeProcessEmailQueueOnShutdown`:
  - `email_queue_autoprocess` appears before `email_enabled`;
  - it contains `session_write_close()` and `fastcgi_finish_request()` inside `function_exists('fastcgi_finish_request')`;
  - it contains `static $registered`.
- The CLI file (`CUERCODE_SITE_ROOT . '_includes/cron/process_email_queue.php'`, built with `DIRECTORY_SEPARATOR`):
  - it exists;
  - the position of `if (PHP_SAPI !== 'cli')` is before the first `require_once`;
  - the `could not start` shutdown message and the `mysqlnd.net_read_timeout` line both come before `'bootstrap.php'`;
  - it contains `set_exception_handler(`;
  - it does **not** contain `toAddress`, `paramsJSON`, `curl_close(` or `mail(`.
- `bootstrap.php` contains `maybeProcessEmailQueueOnShutdown();` within 300 characters after a `php_sapi_name() !== 'cli'`.

Real CLI process checks (proc_open with an **array** command `[PHP_BINARY, $cliPath, ...]`, so the path's spaces and `&` are safe; capture stdout, stderr and the exit code from `proc_close`):
- `--help`: exit 0, stdout contains `Usage`, stderr is empty.
- `--limit=0`: exit 2, stderr contains `--limit`.
- `--frobnicate`: exit 2, stderr contains `unknown option`.

### 5.2 `tests/integration/email_queue_real_mysql.php` (NEW, optional, **not** run by `run.php`)

Proves the real SQL and the real named lock against a throwaway MySQL. A subfolder file is not matched by `run.php`'s top-level glob, but CI's `php -l` still lints it.

Header: purpose, how to run it (tests/README section), and a **safety** note. CLI guard as in 3.2 step 0.

1. **Safety.** Read the environment variables `CUERCODE_IT_DB_HOST`, `CUERCODE_IT_DB_PORT`, `CUERCODE_IT_DB_NAME`, `CUERCODE_IT_DB_USER` and `CUERCODE_IT_DB_PASS` (the password may be empty). **Refuse (exit 2)** unless the database name matches `/^cuercode_it_[a-z0-9_]+$/`. The script deletes rows, so it must never touch a real database.
2. **Wait for the database (parent only).** Try `new mysqli(...)` every 500 ms (`usleep`) for up to 120 s. The MySQL Docker image's temporary start-up server does not listen on TCP, so the first successful TCP connection means the real server is ready.
3. **Schema.** If `tblEmailQueue` is missing (`SHOW TABLES LIKE ?` via a prepared statement):
   - with `--load-schema`: load `install.sql` then `seed_data.sql` using `multi_query`, draining every result and stopping on the first error. This follows the installer's own precedent (`public_html/install/index.php:246,294`); they are trusted repository files, not user input. Say this in a comment;
   - otherwise: exit 2 with instructions.
4. Define `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` and `DB_PASS` from the environment, and `ENCRYPTION_KEY` as `bin2hex(random_bytes(16))` (no key in the file). Then `require_once` the real `_includes/bootstrap.php`.
5. **Scenarios.** Use addresses `it-NN@it.cuercode.invalid` (the reserved `.invalid` domain). Before each scenario, delete rows with `DELETE ... WHERE toAddress LIKE ?` (prepared). Insert rows with `queueEmail()`. **Never call `sendEmailNow()`**: every scenario passes a fake sender closure.
   1. **Claim rules on real SQL.**
      - A due row: first `claimEmailQueueRow()` → true, second → false.
      - A row with `nextAttemptAt` in the future → false.
      - A row with attempts = maxAttempts → false.
      - A `sent` row → false.
      - Set up states with prepared `UPDATE`s.
   2. **Two runners, lock ON.**
      - Queue 16 rows and start 2 child processes: `proc_open([PHP_BINARY, __FILE__, '--child=A', '--use-lock=1', '--go-file=...', '--sends-file=...', '--result-file=...'], ...)`.
      - Children skip steps 2 and 3, bootstrap, and poll for the go-file (`usleep` 10 ms, 10 s cap).
      - Each child runs `runEmailQueueBatch(emailQueueDatabaseGateway(), $fakeSender, ['limit' => 50, 'timeBudgetSeconds' => 120, 'useLock' => true])`.
      - The fake sender `usleep(150000)`s, appends `$to` to the sends-file with `FILE_APPEND | LOCK_EX`, and returns `[true, null]`. The child writes its result as JSON to its result-file.
      - The parent creates the go-file, waits for both children, and asserts: all 16 addresses sent, **no address twice**, all rows `sent`. It prints which child was `busy`.
   3. **Two runners, lock OFF** (`--use-lock=0`). Same assertions: 16 sent, no duplicates. It prints how many each child sent.
   4. **Retry with back-off.** The sender returns `[false, 'HTTP 400 | graph_error_code: ErrorInvalidRecipients']`.
      - The row is `queued`, attempts 1, and `TIMESTAMPDIFF(SECOND, NOW(), nextAttemptAt)` is between 100 and 125.
      - An immediate second run does not call the sender.
   5. **Stuck recovery.**
      - Set row 1 to `status='sending', updatedAt = DATE_SUB(NOW(), INTERVAL 11 MINUTE)` and row 2 to `... INTERVAL 5 MINUTE`.
      - After a run: row 1 is `queued`, attempts 1, `lastError` starting `Recovered`; row 2 is still `sending`.
   6. **Sending blocked.** The sender returns `[false, 'skipped: no Graph access token available']`. The row is back to `queued` with attempts 0, and the status is `system_problem`.
   7. **Busy and stalled.**
      - Open a **second** raw mysqli connection and take the same lock with `SELECT GET_LOCK(<same CUERCODE_EMAIL_QUEUE_LOCK_NAME_SQL>, 0)` (prepared, same prefix).
      - An in-process run is `busy`.
      - Then set a due row's `nextAttemptAt = DATE_SUB(NOW(), INTERVAL 11 MINUTE)`: the run is `stalled`.
      - Release with `RELEASE_LOCK` on the second connection. A run then succeeds.
   8. **Counts.** `countEmailQueueRowsByStatus()` and `countDueEmailQueueRows()` match known set-ups.
6. **Clean up.** Delete the test rows and print `PASS`/`FAIL` per scenario. Exit 0 only if everything passed.

---

## 6. Documentation updates (builder does these; line numbers are at `005d232` — find them by the quoted text)

1. **`README.md`**
   - **"📧 Transactional Email (Epic E8)"** (around lines 182-188).
     - Replace warning item 2 ("There is no scheduled job that sends queued emails yet (#29)...") with a plain statement: the queue runner now exists (#112), and **must be set up as a DreamHost cron job** or nothing is sent.
     - Add a short subsection **"⏱️ The email queue job (DreamHost cron)"** containing:
       - the command `/usr/local/php84/bin/php /home/<user>/<path>/CueRCode.net/_includes/cron/process_email_queue.php --quiet`;
       - every minute;
       - run it once with `--check` first;
       - the exit codes in one line;
       - a link to the DEV_NOTES section.
     - Keep warning item 1 (no admin screen, #31).
   - **Installation**: add step 6, "⏱️ Set up the email queue job (once email is configured)", pointing to that subsection.
   - **Project structure tree**: change the `_includes/` comment to "(bootstrap, SQL, templates, cron jobs)".
   - **"🧪 Testing"**:
     - update "2185 assertions across 23 test files" to the new totals the builder measures (24 files);
     - add one sentence on the optional real-MySQL check (`tests/README.md`).
   - Do **not** touch the in-flight changes to the "Download third-party libraries" bullet.
2. **`DEV_NOTES.md`** — the "### Email Subsystem (Microsoft Graph) - Epic E8, #29" section.
   - **Step 7** ("7. **Choose a queue-processing strategy**..."): rewrite as **"7. Set up the queue runner (required)"**:
     - the DreamHost panel steps;
     - the command, the PHP path note and the frequency;
     - "Email output" set to your address, with `--quiet`, so you only hear about problems;
     - if the panel offers "Use locking", **leave it unticked**. Reason: the runner has its own lock, and the panel's lock would stop the runner from starting and reporting a stuck earlier run;
     - run `--check` first.
     - Keep the fallback as a sub-bullet: `email_queue_autoprocess=1` now really works (wired in `bootstrap.php`, web requests only, off by default), and it processes up to 2 emails at the end of ordinary page visits. Say honestly that without `fastcgi_finish_request()` the visitor waits.
     - Delete "This script does not exist yet, and nothing else ... calls processEmailQueue()".
     - Label the DreamHost menu names and PHP path as **"not checked against the live panel/server - confirm when setting up"**.
   - **"Queue + retry"** paragraph: rewrite it to describe:
     - the safer claim;
     - the guarded final updates;
     - stuck recovery (10 minutes, one attempt used);
     - "blocked" problems not using attempts, and "temporary" problems stopping the run;
     - the named lock and the stall alarm;
     - the batch of 20 and the 45-second budget.
     - Replace the old "**Caveat:** ... burn through its retry budget (~62 cumulative minutes...)" with the new behaviour, and correct the timing: a message that keeps failing is given up after 5 attempts, about **30 minutes** after its first failure.
   - Add a new sub-heading after it: **"#### Email queue runner (`_includes/cron/process_email_queue.php`, #112)"**, covering:
     - how it works (the three layers in plain English);
     - options and the exit-code table;
     - what it prints and never prints;
     - what it cannot do (section 2.4);
     - the "re-send emails that were given up" runbook, for phpMyAdmin:

       `UPDATE tblEmailQueue SET status = 'queued', attempts = 0, nextAttemptAt = NOW(), lastError = NULL WHERE status = 'failed' AND createdAt > NOW() - INTERVAL 1 DAY;`

       with a note that verification links last 24 hours and reset links 60 minutes, so re-sending older ones is pointless;
     - testing: the unit tests and the optional real-MySQL check.
   - The line starting "**Owner-run hard-purge runbook**" says "per the Dreamhost shared-hosting "no CLI/cron guarantee" constraint already documented under "Email Subsystem"". Change it to say DreamHost's panel cron is now used for the email queue (#112), but this purge stays a deliberate manual step because an irreversible bulk delete deserves a conscious owner action.
   - The line starting "This is intentionally a runbook, not code" mentions the queue processor's "opportunistic ... + documented panel-cron alternative" pattern. Change it to "mirroring the email queue runner (`_includes/cron/process_email_queue.php`, #112): a command-line script run by DreamHost's panel cron, protected by a named database lock".
   - "### Known documentation-level gaps": remove "email queue cron" from the list.
3. **`web/CueRCode.net/README.md`**
   - In the `_includes/` table row, add "and `cron/` (command-line jobs, e.g. the email queue runner)".
   - Add a short "Scheduled jobs" note: it must be run by a DreamHost cron job every minute once email is set up; it refuses web requests; see the root README.
4. **`SECURITY.md`**
   - Section "### 6. HTTP headers, transport, installer & directory protection":
     - add `_includes/cron/process_email_queue.php` to **Files**;
     - add to **Audited**: "email queue runner refuses any non-command-line run (403) before loading anything; lives outside the web root under deny-all `_includes/`".
   - Add a new section after "## 2026-09-23 audit ...", titled **"## 2026-09-25 — email queue runner (#112)"**:
     - what was checked: the CLI-only guard, no addresses or secrets in output, lock plus guarded claims, and the scan evidence the builder measures;
     - two **new open items**:
       - (a) `tblEmailQueue.paramsJSON` holds the full one-time verification and reset links in plain text, contradicting the column comment and the hash-only design of `tblEmailVerifications`/`tblPasswordResets`;
       - (b) sent rows are never deleted, so recipient addresses are kept indefinitely;
     - both → follow-up issue (the lead fills in the number).
   - Do not edit `.github/SECURITY.md` (another task has uncommitted changes there).
5. **`PROJECT_STATUS.md`**
   - Line 16 (Email sending ...) → "needs the owner's M365 setup and the DreamHost cron job for the new queue runner (#112, built 2026-09-25); not yet live-tested."
   - Line 27 ("Nothing sends the email queue yet...") → replace with "The email queue runner exists (#112) but has never run on the live server - the owner still needs to add the DreamHost cron job and do a test send."
   - Line 151: append "Update 2026-09-25: queue runner built (#112); remaining: M365 setup, cron job, live test send."
6. **`FEATURES.md`**
   - **FG-028** row: status → "**BUILT, switched off** (needs owner M365 setup + DreamHost cron job)". Replace the "MISSING: nothing ever empties the queue..." sentence with a one-line description of the runner (#112).
   - **FG-040** row: status → "**BUILT** (2026-09-25, #112) — needs the owner to add the cron job + a live test". Update the description (the "or a GitHub Actions scheduled hit against a processing endpoint" idea was not used; say DreamHost panel cron).
7. **`LAUNCH_DECISIONS.md`** line 72 → "- [ ] **Add the DreamHost cron job for the email queue runner** (#112, built 2026-09-25) — without it no sign-up or reset email is sent. Steps: DEV_NOTES.md → 'Email queue runner'. Then do a live test send."
8. **`PROJECT_ROADMAP.md`**
   - Line 26 (E8 note) → "built; the queue runner was added 2026-09-25 (#112). Needs M365 setup, the DreamHost cron job and a live test."
   - Line 264: append "*(Update 2026-09-25, #112: the queue is emptied by a command-line runner from a DreamHost panel cron job every minute; the request-shutdown hook is an off-by-default fallback, now actually wired in.)*"
9. **`CHANGELOG.md`**: a new entry at the **top** of `## [Unreleased]`, above the #117 entry: "### Fixed - Emails now actually get sent: new scheduled email queue runner (2026-09-25, #112)". Plain-English bullets:
   - the new runner file and how DreamHost runs it;
   - no duplicates (safer claim + lock);
   - stuck recovery;
   - "blocked" problems no longer use up attempts, and temporary problems stop the run;
   - the fallback switch now works (still off);
   - the stall alarm;
   - tests (numbers) and the optional real-MySQL check;
   - **owner action needed** (add the cron job);
   - review status: leave a placeholder line for the lead, "Review: ...".
10. **`tests/README.md`**
    - Change the table heading to "Current suite (2026-09-25, #112)" and add the `test_email_queue_runner.php` row with its count; update the total.
    - Add a section **"Optional real-database check (email queue)"** with the exact Docker commands from 7.6. It must say: throwaway container only, the database name must start with `cuercode_it_`, and remove the container afterwards.
11. **No change** to `openapi.json`, the developer portal, `seed_data.sql` or `web/CueRCode.net/CHANGELOG.md` (frozen since Feb 2026, per its own note). The API is unchanged. State this in the report.
12. **Lead, not builder**: `.claude/HANDOFF.md`, `.claude/memory/*`, `.OpenAI/*`, an update note on `.claude/ISSUE_AUDIT_2026-09-23.md` (#29/#52 rows and bug list item 1), and the GitHub issue updates.

---

## 7. Verification steps (the verifier runs all of these and reads exit codes directly — never pipe a check into `grep`/`tail` and then trust `&&`)

Set once, and quote paths everywhere: `REPO="<path to your CueRCode checkout>"`.

1. **Scope.** Run `git -C "$REPO" status --short` and `git -C "$REPO" diff --stat`. Only the files in sections 3, 5 and 6 changed (plus any pre-existing in-flight files noted in 0.1). New files:
   - `web/CueRCode.net/_includes/cron/process_email_queue.php`
   - `tests/test_email_queue_runner.php`
   - `tests/integration/email_queue_real_mysql.php`
2. **Syntax.** Run `php -l` on each changed or new PHP file: `fn_email.php`, `bootstrap.php`, the cron script, both new test files and `tests/test_email.php`. All must print "No syntax errors".
3. **Full suite.** `php "$REPO/tests/run.php" > /tmp/.../suite.txt 2>&1; echo "exit=$?"` must print **exit=0**. The total must equal 2185 + the new file's count, with 0 failed. `test_email.php` must still show 69 passed.
4. **House-style scan of added lines.**
   - `git -C "$REPO" diff -U0 -- web | grep '^+' | grep -nE '\?\?|\?:|\bfn\s*\(|\bmatch\s*\(|curl_close\(|\bmail\('` must print **nothing**.
   - Review any ternary `? ... :` by eye.
   - `grep -nE '^\s*curl_close\(' "$REPO/web/CueRCode.net/_functions/fn_email.php"` must print nothing.
5. **Runner by hand** (from `$REPO`; first confirm neither `web/CueRCode.net/_auth_keys/auth_creds.php` nor `web/_auth_keys/auth_creds.php` exists — **if either exists, skip 5c and 5d** so nothing can reach a real database or send real email):
   - a. `php web/CueRCode.net/_includes/cron/process_email_queue.php --help; echo "exit=$?"` → help text, exit=0.
   - b. `php web/CueRCode.net/_includes/cron/process_email_queue.php --limit=0; echo "exit=$?"` → reason on stderr, exit=2.
   - c. `php web/CueRCode.net/_includes/cron/process_email_queue.php; echo "exit=$?"` → the "could not start ... auth_creds.php" line, exit=1, and no HTML.
   - d. **Unreachable database, clean error exit.** Create `<scratchpad>/no_db_wrapper.php`, which:
     - defines `DB_HOST='127.0.0.1'`, `DB_PORT=1`, `DB_NAME='cuercode_nowhere'`, `DB_USER='nobody'`, `DB_PASS='nothing'` and `ENCRYPTION_KEY` (any 32-plus characters);
     - sets `$_SERVER['argv'] = ['process_email_queue.php', '--quiet'];`;
     - then `require`s the cron script by absolute path.

     Run `php <scratchpad>/no_db_wrapper.php > out.txt 2> err.txt; echo "exit=$?"`. Expect:
     - **exit=3**;
     - `err.txt` contains "could not reach the database or the email queue table";
     - `out.txt` is empty;
     - neither file contains `<html` (check each with `grep -c`, reading the count, not a pipe exit code).

     Other `CueRCode [error]: Config fetch failed` lines from bootstrap are expected noise (see 1.4).
   - e. **Web access refused.** `php-cgi -f web/CueRCode.net/_includes/cron/process_email_queue.php; echo "exit=$?"` → output contains `Access denied.`, and no database or "could not start" text. This proves the guard runs before anything loads.
6. **Real MySQL (strongly recommended; the only proof of the real SQL and the lock).** Follow the device rule: a named throwaway, removed with its data.
   ```bash
   BEFORE=$(docker volume ls -q -f dangling=true | wc -l | tr -d ' ')
   docker run --rm -d --name p112-emailq-mysql \
     -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE=cuercode_it_p112 \
     -p 127.0.0.1:33112:3306 mysql:8.0 --default-authentication-plugin=mysql_native_password
   CUERCODE_IT_DB_HOST=127.0.0.1 CUERCODE_IT_DB_PORT=33112 CUERCODE_IT_DB_NAME=cuercode_it_p112 \
   CUERCODE_IT_DB_USER=root CUERCODE_IT_DB_PASS= \
     php "$REPO/tests/integration/email_queue_real_mysql.php" --load-schema; echo "exit=$?"
   docker stop p112-emailq-mysql          # --rm removes the container AND its unnamed volume
   docker ps -a --filter name=p112-emailq-mysql --format '{{.Names}}'     # must print nothing
   AFTER=$(docker volume ls -q -f dangling=true | wc -l | tr -d ' '); echo "dangling before=$BEFORE after=$AFTER"   # after must equal before
   ```
   - Expect all scenarios PASS and exit=0. Report which child was busy in scenario 2, and the send split in scenario 3.
   - The script waits for the database itself (up to 120 s), so no shell `sleep` is needed. If it is run in the background, set a watchdog (`~/.claude/bin/watchdog.sh pid <pid>`) as the device rules require.
   - Optionally repeat with `mariadb:10.11` (`-e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 -e MARIADB_DATABASE=cuercode_it_p112`, no auth flag).
7. **PHP 8.4 compatibility.**
   - `docker run --rm -v "$REPO":/app -w /app g2ml-php:8.4-mysqli php -l web/CueRCode.net/_functions/fn_email.php` (and the same for the other changed PHP files).
   - `docker run --rm -v "$REPO":/app -w /app g2ml-php:8.4-mysqli php tests/run.php --filter=email; echo "exit=$?"` → exit=0.
   - If the image is missing an extension, say so rather than claiming a pass.
8. **Security read of the diff.**
   - Every new query goes through `dbQuery` with `?` placeholders.
   - The only string joined into SQL is `CUERCODE_EMAIL_QUEUE_LOCK_NAME_SQL`.
   - Nothing prints or logs a body, `paramsJSON`, a token, a secret or a setting value.
   - CLI output lines pass through `emailQueueSafeDetail()`.
   - The CLI guard is the first statement.
   - No new web-reachable file.
9. **Docs.**
   - `grep -n "to be added when the queue\|does not exist yet" "$REPO/DEV_NOTES.md"` → no email-queue hits.
   - `grep -n "62 cumulative" "$REPO/DEV_NOTES.md"` → nothing.
   - The test totals in `README.md` and `tests/README.md` match step 3's real numbers.
   - `CHANGELOG.md`'s top Unreleased entry is #112.
10. **Report honestly.** Say which steps were run, which were skipped and why (for example, Docker not available), and that DreamHost panel details and live GET_LOCK behaviour are **not** verified.

---

## 8. Risks, and what the build must NOT do

**Risks (state them in the report and the docs)**
- DreamHost specifics are not verified against the live panel or server: menu names, the minimum cron interval, the exact PHP path (`/usr/local/php8X/bin/php`) and whether "Use locking" exists.
- MySQL named locks on DreamHost's shared MySQL are assumed to work, as four existing features already assume. The row claim is the independent guarantee.
- Delivery is "at least once" (2.4).
- Wiring the fallback adds one cached settings read to every web page. With the fallback on, a host without `fastcgi_finish_request()` makes a visitor wait while up to 2 emails send.
- While sending is blocked (exit 4), cron will email the owner **every minute** if "Email output" is set. This is intended ("an outage alert"), and the docs must say so and suggest pausing the job while fixing it.
- `fn_email.php` grows substantially, which means more to review. Keep the new sections clearly headed.
- Mixed working tree (0.1): a commit could accidentally include unrelated work.

**Must NOT**
- Must not change the `CREATE TABLE`, column comments, `seed_data.sql` values, or add a migration.
- Must not revert or alter the #117 changes, and must not add `curl_close()`/`imagedestroy()`.
- Must not change `processEmailQueue()`'s name, parameters or return shape, or edit any assertion in `test_email.php`.
- Must not define global `dbQuery`, `getDB`, `getConfig`, `logError` or `logActivity` anywhere in `tests/`. Must not use un-prefixed variable names in the new test file.
- Must not start the real CLI in tests without `--help` or an invalid option. Must not run the integration script against a database not named `cuercode_it_*`. Must never call `sendEmailNow()` from any test.
- Must not print or log email bodies, `paramsJSON`, tokens, secrets, setting values or full addresses from the runner.
- Must not use shorthand (`??`, `?:`, ternary, `fn`, first-class callables, `match`) in production code, or PHP-8.5-only features anywhere.
- Must not add a `.htaccess` in `cron/`, a new setting, a new API route, or change `openapi.json`.
- Must not commit, push or open issues/PRs. The lead does that after verification and the Codex review.
- Must not leave Docker containers or data volumes behind.

---

## 9. For the lead (after the build)

- **Order:** commit the in-flight stand-in-review work first (0.1), then Sonnet builds this, then Opus verifies (section 7), then Codex reviews (`codex review --uncommitted -c model="gpt-6-astra"`; the Codex allowance is small but this touches possible data loss and duplicate sending, so it is worth it), then commit `fix: send queued emails - scheduled queue runner with duplicate-proof claims (#112)` and push to `work-in-progress`.
- **Follow-up issues to open** (suggestions, not built):
  1. **Privacy/security:** `tblEmailQueue.paramsJSON` keeps full one-time links in plain text, and sent rows are never deleted. Scrub `paramsJSON` after a successful send, purge sent rows after N days, and correct the column comment. Link SECURITY.md's new open items.
  2. **Stale links:** skip (mark `skipped`) queued verification or reset emails older than their link lifetime (24 h / 60 min) instead of sending dead links after an outage.
  3. **Admin visibility:** there is no screen to see or re-send `failed` emails (relates to #31).
  4. Add "create the DreamHost cron job and confirm the panel details and PHP path" to #113's server set-up checklist.
- **Owner actions (not decisions):** add the DreamHost cron job once M365 is set up; choose an "Email output" address for alerts.
- **No up-front owner decision is needed.** The one judgement call is wiring the off-by-default fallback into `bootstrap.php`. It is reversible, costs one cached settings read per page, and is flagged for the owner's awareness.
- Update `.claude/HANDOFF.md` §0 (task 7a) before the build starts, and again after each stage.
