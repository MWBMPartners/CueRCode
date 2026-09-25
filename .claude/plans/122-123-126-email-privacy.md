# Plan for #122, #123 and #126 — keep one-time links and addresses out of storage and logs

**#122** (privacy, medium): the email queue keeps one-time sign-up and password-reset links in plain text, and never deletes rows.
**#123** (low): do not send verification or reset emails whose link has already expired.
**#126** (privacy, low): the activity logger's fallback writes recipients' email addresses into PHP's error log (on the command line, that is the cron email).

Planner: Opus (deep planning). **Recommended builder: Opus.** This work changes the core batch logic, adds automatic deletes, and touches the shared loggers, so it counts as "genuinely complex" under the model-routing rule. The #126 part alone would suit Sonnet. Verifier: a separate Opus agent. Reviewer: Codex. The Codex allowance is small, but this touches privacy and permanent data deletion, which is where a second opinion is worth most.

Written 2026-09-25 against `work-in-progress` at **`a266854`**. Paths use `$REPO` for the repository root and `<scratchpad>` for a scratch folder. The three issues are planned together because they share one code path: `fn_email.php`'s batch and its logging.

**Plain-English glossary**
- **Queue table**: `tblEmailQueue`, where emails wait to be sent.
- **Runner / batch**: the cron script `_includes/cron/process_email_queue.php` and the function `runEmailQueueBatch()` that it (and the page-shutdown fallback) calls. Built in #112.
- **Details**: the `paramsJSON` column, which holds the template parameters. For sign-up and password-reset emails these include the **one-time link**, with its secret token.
- **Clear (scrub)**: set `paramsJSON` to `NULL`.
- **Purge**: delete old rows.
- **Waiting row**: status `queued` or `failed` **and** attempts left (`attempts < maxAttempts`), whether it is due now or waiting out a back-off.
- **Given-up row**: status `failed` **and** no attempts left (`attempts >= maxAttempts`).
- **Finished row**: status `sent`, `skipped`, or given up.
- **Clean-up (housekeeping)**: the three new steps: skip expired links, clear the details of old given-up rows, and delete old finished rows.
- **Named lock**: the per-database MySQL lock from #112 (`GET_LOCK`). Only one runner holds it at a time.

---

## 0. Read this first (builder)

### 0.1 Preconditions — check before editing anything
1. Run `git -C "$REPO" status --short`.
   - Another agent may be editing **`.github/workflows/deploy.yml`**. Do not touch that file, and never revert or "tidy" anything you did not change.
   - `.claude/worktrees/` holds another agent's stale copy of the repository. **Exclude it from every search**: search only `web tests tools`, or use `grep --exclude-dir=worktrees`.
2. `git -C "$REPO" log --oneline -3` should show `a266854` (#127) or later.
3. Record the baseline by running `php "$REPO/tests/run.php" > <scratchpad>/baseline.txt 2>&1; echo "exit=$?"`. It should give **exit=0** and **2466/2466** assertions across 24 files. Read the exit code directly; never pipe the run into `grep`/`tail` and then trust `&&`.
4. Read `.claude/plans/112-email-queue-runner.md` §0.2 (house rules) and §3 (how the runner is built). This plan changes that code.

### 0.2 House rules (from the #112 plan §0.2, still binding), plus four additions
- **Full multi-line PHP in production code.** No `??`, `?:`, ternaries, arrow functions (`fn`), first-class callables (`foo(...)`) or `match`. Write `if`/`else`.
- **PHP 8.4 compatible.** Nothing only PHP 8.5 has: no pipe operator, `clone with`, `array_first()`/`array_last()`, or `#[\NoDiscard]`.
- **Every query is a prepared statement through `dbQuery()`** with `?` placeholders. The only text ever joined into SQL is the existing `CUERCODE_EMAIL_QUEUE_LOCK_NAME_SQL` constant. **No new joined text.**
- Use `DIRECTORY_SEPARATOR` and `PHP_EOL`.
- **Heavy "why" comments.** Every new function gets a docblock with `@param`/`@return`, why it exists, what it CANNOT do, and what was rejected (§2.4 gives the reasons). Emojis in comments are fine.
- **File headers** follow `fn_email.php` lines 1-53: banner, `Path:`, description, `@package CueRCode`, `@subpackage`, `@copyright 2025–2026 MWBM Partners Ltd` (with an en dash), `@version`, `@since`, `@see`.
- No classes. Procedural functions only.
- **Never print or log** email bodies, `paramsJSON`, links or tokens, secrets, setting values, or full email addresses.
- **Do not commit or push.** The lead does that after verification and review.
- **NEW 1:** never write the literal text ``ALTER TABLE ` `` (with the backtick) or `CREATE TABLE IF NOT EXISTS` anywhere new in `install.sql`, **including comments**. `tests/test_dynamic_redirect_s0.php:321` and `:330` count those exact texts across the whole file (29 and 2 today).
- **NEW 2:** never write `toAddress` or `paramsJSON` in `_includes/cron/process_email_queue.php`, **even in a comment**. `tests/test_email_queue_runner.php:1291` fails if you do.
- **NEW 3:** no test file may define `dbQuery`, `getDB`, `getConfig`, `logError` or `logActivity` in the **main** test process. Five other test files assert those functions do not exist. A **child** PHP process started by a test may define them, because it is a separate process. Say so in a comment next to each one.
- **NEW 4:** every new write to the queue must keep the #112 guarantees:
  - a write that finishes a claimed row keeps `AND \`status\` = 'sending'`;
  - no clean-up statement may ever touch a row that is `sending`, or a waiting row with attempts left (except the expired-link skip, which applies only to waiting rows, by design).

---

## 1. Findings (evidence at `a266854`)

### 1.1 Where the one-time links come from, and who reads them
- `registerUser()` (`fn_auth.php:431-445`) queues template `verification` with params `recipientName`, `verificationUrl` and `expiryHours`. `verificationUrl` is the full link with the raw token (`buildAccountActionUrl()`, `:227-240`).
- `requestPasswordReset()` (`fn_auth.php:1173-1187`) queues `password_reset` with `recipientName`, `resetUrl` and `expiryMinutes`.
- **These are the only two callers of `queueEmail()`** (grep over `web tests tools`). The `invitation` and `receipt` templates exist, but nothing queues them yet.
- `queueEmail()` (`fn_email.php:750-807`) stores `json_encode($params)` in `paramsJSON`. If encoding fails it writes `'{}'`, so it **never writes NULL** (`:780-784`).
- The token tables store only hashes (`fn_auth.php:410-418`, `:1161-1170`). The queue table undoes that by keeping the usable link.
- **The only reader of `paramsJSON` is `runEmailQueueBatch()`** (`fn_email.php:1892`, via `loadClaimedEmailQueueRow()` at `:2383-2400`). Nothing else reads it (grep over `web`, `tests` and the docs). So clearing it once a row can no longer be sent cannot break anything.
- Some rows can still be sent later, so they **must keep their details**:
  - rows waiting for a retry (`settleEmailQueueRetry()`, `:2439-2452`);
  - rows put back because sending is blocked (`releaseEmailQueueClaim()`, `:2492-2504`);
  - recovered stuck rows (`recoverStuckEmailQueueRow()`, `:2266-2296`);
  - given-up rows the owner may re-send by hand (`DEV_NOTES.md:296-304`: that runbook re-queues `failed` rows from the last day).
- **The templates quietly render an EMPTY link when the link parameter is missing.** They do not fail: `verification.php:51-55`, `password_reset.php:51-55`, `invitation.php:66-70`. So a row whose details were cleared must never be rendered. It would send a broken email and report success.
- **There is no "send me a new verification link" feature** (grep for `resend`/`re-send` finds none). `registerUser()` returns the generic success and sends nothing for an address that already exists, including a still-`pending` one (`:353-359`). So a person whose verification email is lost cannot get another one, and cannot sign up again with that address. This drives owner decision 2.

### 1.2 Link lifetimes (#123)
- `CUERCODE_EMAIL_VERIFICATION_TTL_HOURS = 24` (`fn_auth.php:74-76`) and `CUERCODE_PASSWORD_RESET_TTL_MINUTES = 60` (`:82-84`).
  - The database sets each token's expiry: `DATE_ADD(NOW(), INTERVAL ? HOUR/MINUTE)` (`:414-418`, `:1166-1170`).
  - The queue row is inserted **in the same request, after** the token row. So `tblEmailQueue.createdAt` is the same moment or a fraction later, and "createdAt + lifetime" is never earlier than the real expiry. Using `createdAt` can never skip a link that still has more than the safety margin left.
- #123's text says `verify_email`. **The real template key is `verification`.**
- **Clocks.** `db_connect.php:191-193` sets the database session time zone to UTC, and `createdAt` is `DEFAULT CURRENT_TIMESTAMP`. PHP's clock follows the `site_timezone` setting (`bootstrap.php:273-286`). So **the age check must be done in SQL** (`NOW()` against `createdAt`), never with PHP's `time()`.
  - Side finding, not fixed here: `fn_auth.php:523-527` and `:1275-1279` compare the UTC database time with PHP's `time()` via `strtotime()`. If `site_timezone` is ever set to anything but UTC, links expire at the wrong time. In summer, `Europe/London` would make a 60-minute reset link look expired at once. → follow-up, §10.
- `fn_auth.php` is loaded by `bootstrap.php:193` before any batch can run. But `fn_email.php` is **loaded on its own** by the cron script (`process_email_queue.php:87`), before bootstrap, and by the tests. So `fn_email.php` must read the lifetime constants **at run time**, inside a function with `defined()` checks, never when the file loads.

### 1.3 Table and settings facts
- `tblEmailQueue` is at `install.sql:421-481`. `paramsJSON` is **`JSON NULL`** (`:461`), so NULL is allowed. Its comment says "NEVER stores rendered HTML or secrets", which is false.
- The `status` ENUM already includes `skipped`, which no code uses today.
- Indexes (`:476-478`): `idx_email_status_next (status, nextAttemptAt)`, `idx_email_template (templateKey)` and **`idx_email_created (createdAt)`**. The purge can walk `idx_email_created`.
- `updatedAt` has `ON UPDATE CURRENT_TIMESTAMP` (`:470`). Any UPDATE refreshes it, unless the statement sets it to itself.
- **There is no migration mechanism** (#113). `install.sql` is `CREATE TABLE IF NOT EXISTS`, so comment changes only reach fresh installs.
- Settings (`tblSettings`, `install.sql:43-68`):
  - `settingType` includes `'integer'`; `settingKey` is UNIQUE; `description` is `VARCHAR(500)`.
  - `getConfig()` (`fn_config.php:86-179`) returns the default you give it when the row is missing.
  - For an `integer` setting it returns `(int)` of the stored text (`castSettingValue()`, `:627-629`). **So a typo like "thirty" becomes 0.** The code must treat 0 as "not valid, use the default", never as "delete straight away".
- `seed_data.sql`: the email group is at `:86-117`. The summary comment at `:676` says "78 default settings", which is right today (counted: 78 rows). It becomes **80**. No test pins that count.

### 1.4 Checked on real database servers (planner, 2026-09-25)
Throwaway containers `p122-probe-mariadb106` and `p122-probe-mysql84` were started with `--rm` and stopped afterwards. Check afterwards: 0 containers left, 0 leftover data volumes (0 before, 0 after).

The probe script is `<scratchpad>/probe_limit.php` (mysqli prepared statements). It ran against **MariaDB 10.6.28** and **MySQL 8.4.11**, with the same results on both:
- `UPDATE ... ORDER BY ... LIMIT ?` and `DELETE ... ORDER BY ... LIMIT ?` accept a bound LIMIT (affected rows 3 and 2, as expected).
- `SET ..., \`updatedAt\` = \`updatedAt\`` **stops** the automatic refresh. A control UPDATE without it did refresh.
- Setting the `JSON` column to NULL works (MariaDB's JSON is text with a validity check).
- `createdAt <= DATE_SUB(NOW(), INTERVAL ? SECOND)` and `TIMESTAMPDIFF(SECOND, createdAt, NOW())` behave as expected.

**Not checked:** MySQL 5.7 and MariaDB 10.3, the README's minimums. They are believed to behave the same, but say so in the report.

**Environment notes:**
- `mysql:8.0` is **no longer on this machine**; `mysql:8.4`, `mariadb:10.6`, `mariadb:10.11` and the PHP 8.4 image `g2ml-php:8.4-mysqli` are.
- MySQL 8.4 removed the `--default-authentication-plugin` option that `tests/README.md`'s command uses. **Leave it off with 8.4.** The probe connected fine without it.

### 1.5 Everything that writes to PHP's error log (#126)
Every `error_log()` call in the site code (grep, excluding `_libraries`):

| Where | What it writes | Verdict |
| --- | --- | --- |
| `fn_activity_log.php:197-200` (no database function) | `'CueRCode Activity [' . $type . ']: ' . $detail` | **Leak.** For email events `$detail` is `To: <address> \| Subject: ...` (`fn_email.php:398`). → fix |
| `fn_activity_log.php:248-251` (insert failed) | `... $detail . ' \| DB Error: ' . $e->getMessage()` | **Leak.** This is the #126 line. It also prints raw SQL text from the database error. → fix |
| `fn_email.php:406`, `logEmailAttempt()`'s fallback when `logActivity()` is missing | `... $toAddress . ' \| ' . $detail` | **Leak of the raw address.** Not in the issue, but the same problem. → fix |
| `fn_error_handler.php:306-309` and `:507-511`, `logError()`'s fallbacks | `maskSensitiveForLog()` of the title, detail and database error. It hides SQL and paths, **not addresses** | A driver message can quote a value, e.g. `Duplicate entry '<address>'`. → fix inside `maskSensitiveForLog()` |
| `fn_email.php:776` | fixed text | OK |
| `fn_email.php:2925` | runner lines already cleaned by `emailQueueSafeDetail()` | OK |
| `db_connect.php:73`, `:179` | fixed text / character-set error | OK |
| `db_connect.php:162` | connection error. Can include the database **user name and host** (`Access denied for user 'x'@'host'`), never the password. Not address-shaped | Leave. Already documented in DEV_NOTES and SECURITY.md |
| `bootstrap.php:125`, `:143`, `:213`; `api/v1/index.php:129`; `developers/errors/index.php:43`; `developers/reference/index.php:49` | file paths | OK (not personal data) |
| `public_html/install/index.php:210`, `255`, `267`, `302`, `314` | installer database errors | OK (installer only, technical) |

- **No `error_log()` call writes a one-time link or token.** `logEmailAttempt()` never receives template parameters, and render errors do not include them.
- **Load order.**
  - `bootstrap.php:164-203` loads `fn_encryption`, `fn_config`, **`fn_error_handler`**, **`fn_activity_log`**, and later **`fn_email`**. So the activity logger cannot rely on anything in `fn_email.php`.
  - The cron script loads `fn_email.php` on its own first, and the tests load single files with `requireFn()` (`tests/bootstrap.php:178`). So `fn_email.php` cannot rely on anything bootstrap loads either.
- `maskSensitiveForLog()` (`fn_error_handler.php:558-616`) calls `preg_replace()` without checking for failure, and is declared to return `string`. The new step must guard against a failed replace (§4.4).
- **Tests never load `fn_error_handler.php` or `fn_activity_log.php` in the main test process.** `fn_error_handler.php` installs PHP error handlers the moment it loads (see `test_core.php:42` and `test_email.php:269`). So #126 is tested in **child PHP processes**. The precedent is `runPhpSnippet()` in `tests/test_core.php:110-165`.

### 1.6 Existing tests that pin current behaviour — must stay green, unedited
- `test_email_queue_runner.php:752-761` ("leaves alone rows that are not due, being sent, already sent, or out of attempts: nothing changed") compares every row before and after a run. All its rows have `createdAt = now`, and row 3 is `sent` **with details still present**. → The clean-up must leave young rows untouched and must **not** clear details on rows already marked `sent`. That is why there is no "clear old sent rows" step (§2.4).
- `:1192` asserts the exact `--check` line `Queue: 2 due now; 2 queued in total; 0 being sent; 1 sent; 0 given up.` → Keep that line exactly. Add new lines after it.
- `:713` asserts `CUERCODE_EMAIL_QUEUE_GATEWAY_KEYS === array_keys(emailQueueDatabaseGateway())`. → Append the new operations to both, in the same order.
- `:1095-1118` (switched off / settings incomplete): exit codes 0 and 4, the text "2 email(s) are waiting", `claimCalls` 0, and no setting values printed.
- `:1291`: the cron file must not contain `toAddress`/`paramsJSON`.
- `test_email.php:300` and `:326-331`: `maybeProcessEmailQueueOnShutdown()` and `processEmailQueue()` with no database. Keep their early returns.
- `test_email_queue_runner.php:600-604`: the `emailQueueSafeDetail()` cases. The shared helper must give identical results.

---

## 2. The design

### 2.1 #122 — what is kept, cleared and deleted, and when

| When | `paramsJSON` (may hold the link) | The row itself |
| --- | --- | --- |
| queued, waiting for a retry, put back because sending is blocked, or recovered after a crash | **kept** (still needed to send) | kept |
| **sent** | **cleared** in the SAME statement that marks it sent | deleted **30 days** after it was queued (setting) |
| **skipped** (link expired, or its details were already gone) | cleared in the same statement | deleted after 30 days |
| **given up** (failed, no attempts left) | kept **24 hours** after it was queued, so the owner can re-send it after an outage (owner decision 2), then cleared | deleted after **90 days** (setting) |

**What stays on a finished row for support:**
- stays: `emailID`, `toAddress`, `toName`, `templateKey`, `subject`, `status`, `attempts`, `lastError`, `nextAttemptAt`, `createdAt`, `updatedAt` and `sentAt`;
- that answers "did my email go out, when, and if not, why?";
- `toAddress` is **not** masked on the row, because that would make "did this person's email go out?" impossible to answer. The purge limits how long it is kept instead.

**2.1.1 Clear on send.**
- `settleEmailQueueSuccess()` gains `paramsJSON = NULL` in its existing guarded UPDATE.
- It is one statement, so a sent row never holds its link, even for a moment.
- If recording "sent" fails, the row stays `sending` with its details. Stuck-row recovery puts it back, and the re-send still has its link. That is the same "at least once" behaviour as #112.

**2.1.2 Clear on skip.** Both new skip paths set NULL in the same UPDATE that sets `skipped`.

**2.1.3 Given-up rows keep their details for 24 hours, then lose them.**
- A clean-up UPDATE clears `paramsJSON` on given-up rows queued 24 or more hours ago.
- It sets `updatedAt = updatedAt`, so the time the email was given up stays readable in phpMyAdmin (checked in §1.4).
- 24 hours equals the longest link lifetime. After that no re-send could help anyway.

**2.1.4 A guard that makes clearing safe.**
- After a row is claimed and re-read, it is marked **`skipped`** with a plain-English `lastError`, and **never rendered**, if either:
  - `paramsJSON` is NULL; or
  - its template needs a link and the link is missing or empty.
- Why this matters: the owner's re-send runbook, or any hand edit in phpMyAdmin, can put a cleared row back to `queued`. Without the guard it would go out with an empty link and report success.

**2.1.5 Purge.**
- Two DELETE statements, finished rows only, counted from `createdAt`, at most 500 rows each per run, oldest first:
  - `sent` and `skipped` older than `email_queue_retention_days` (default 30);
  - given up (`status = 'failed' AND attempts >= maxAttempts`) older than `email_queue_failed_retention_days` (default 90).
- **Never deleted:** `queued` rows, `sending` rows, and `failed` rows that still have attempts left. Code from before #112 wrote `failed` for rows that were still being retried, and `findDue` still treats those as waiting.
- **Why `createdAt`**, not `sentAt` or `updatedAt`:
  - it never changes;
  - it has an index, so the delete only walks the old end of the table;
  - every email reaches its final state within about a day of being queued, so the difference is at most a day;
  - `updatedAt` changes whenever anything touches the row, and `sentAt` only exists for sent rows.

**2.1.6 Retention settings.**
- Two new `tblSettings` rows (group `email`, type `integer`), read through `getConfig()`, with defaults in the code.
  - Existing installs will not get the new seed rows (there is no upgrade script). They still purge, using the defaults.
- A pure parser accepts only whole numbers from 1 to 3650. Anything else falls back to the default, and `--check` names the setting (never its value):
  - this includes 0, which is what `getConfig()` returns for a typo;
  - there is deliberately no "keep forever" value.

**2.1.7 Column comment.**
- Correct the `paramsJSON` column COMMENT (and the `status` and `createdAt` comments) inside the CREATE TABLE in `install.sql`, and the block comment above it.
- **Existing installs are not altered.** Reasons:
  - `CREATE TABLE IF NOT EXISTS` does not touch an existing table;
  - there is no upgrade mechanism (#113);
  - the comment is documentation only (no code reads it);
  - asking the owner to hand-run a column change on a live table is more risk than a cosmetic comment is worth;
  - an `ALTER` inside `install.sql` would also break `test_dynamic_redirect_s0.php:330`.
- Recorded as an item for #113 instead (§10).

**2.1.8 Where and when the clean-up runs.**
It runs inside `runEmailQueueBatch()`, **only while this run holds the named lock**, and **before any email is claimed**.
- **Only under the lock.** The clean-up's range UPDATE/DELETE statements must never run at the same time as another runner's claims. InnoDB (the MySQL storage engine) could deadlock a range statement walking an index against a claim that holds the row, and the lock already guarantees one runner at a time.
  - Consequence: the `useLock => false` option (used only by tests and the integration check) **skips the clean-up**. The per-row guards (2.1.4 and 2.2.3) still apply.
- **Before sending**, so expired links are skipped before the batch can pick them.
- **A failing clean-up step is counted and reported (exit code 3), but never stops sending.**
- **It also runs when email sending is switched off, or switched on with settings missing.**
  - `emailQueueRunnerMain()` then runs the batch with `sendEmails => false`: lock, clean-up, count, unlock.
  - That mode does no stuck-row recovery, no claims, and **no stall alarm**. While sending is off, emails are expected to wait.
  - Why: keeping data only as long as needed must not depend on whether sending is on. The site can take sign-ups while email is off, and those rows would otherwise keep their links and addresses indefinitely.
- **The page-shutdown fallback** (`processEmailQueue()`) gets the clean-up too, because it calls the same batch under the same lock. It reads the retention settings through `getConfig()`.
  - It only runs when sending is on (its gating is unchanged). So a host with no cron **and** email switched off gets no clean-up. Document this.

### 2.2 #123 — expired links
**2.2.1 Lifetimes come from `fn_auth.php`'s own constants, read at run time.**
- `emailQueueLinkLifetimes()` returns `['verification' => 24 × 3600, 'password_reset' => 60 × 60]`, including only the entries whose constant is defined.
- There is one place to change a lifetime. The two constants' docblocks in `fn_auth.php` get an "also read by fn_email.php" note.
- `invitation` has no lifetime yet (nothing issues invitations), and `receipt` has no link.

**2.2.2 A 2-minute safety margin.**
- An email is skipped once its link has **less than 2 minutes left**, i.e. its age is at least the lifetime minus 120 seconds (`CUERCODE_EMAIL_QUEUE_LINK_EXPIRY_MARGIN_SECONDS = 120`).
- Why: the run that would send it can take over a minute (a 45-second claim budget, plus up to two 10-second Graph calls). An email that arrives with seconds left is as useless as one that arrives dead.
- The maximum age never goes below 60 seconds, so a tiny lifetime can never turn into "skip everything".

**2.2.3 Two checks, using the same rule.**
- **Clean-up step** (in bulk, under the lock, before sending): one UPDATE per template that has a lifetime.
  - It targets waiting rows (status `queued`/`failed` with attempts left, whether due now or in back-off) created at or before `NOW()` minus (lifetime − margin).
  - It sets status `skipped`, a `lastError` note, `paramsJSON` NULL and `updatedAt` NOW().
  - At most 500 per template per run. It does not use a send slot, so after an outage a backlog of dead emails cannot hold up fresh sign-ups.
- **Per-row backstop** (after a row is claimed and re-read):
  - the re-read also returns `TIMESTAMPDIFF(SECOND, createdAt, NOW()) AS ageSeconds`, using the database's own clock;
  - if the link has expired by the same rule, the row is marked `skipped` with the same note;
  - this catches rows the bulk step did not reach: its 500 cap, a failed clean-up step, or a run without the lock.
- Both count into `result['expired']`.

**2.2.4 What the bulk step never touches.**
- It never touches `sending` rows: only the runner that claimed a row may finish it.
- Given-up rows keep status `failed`. That they failed matters more to support than that their link later expired.

**2.2.5 Reporting.**
- During a **sending** run, `expired > 0` is an "attention" line. It goes to the error stream even with `--quiet`, so cron emails it, and the exit code stays 0. It means people did not get an email they asked for, which only happens after an outage.
- During a **switched-off** run it is shown only without `--quiet`, because there it is the expected result of the owner's choice.

### 2.3 #126 — keep addresses out of the error log
**2.3.1 A new dependency-free file, `_functions/fn_log_mask.php`,** with two functions:
- `maskEmailAddresses()`;
- `cleanLogText()`: exactly the algorithm `emailQueueSafeDetail()` uses today. Control characters become spaces, address-shaped text becomes `[address]`, runs of whitespace collapse, the ends are trimmed, and long text is cut with `...`.

That gives one copy of the rule.

**2.3.2 How it is loaded.**
- It is **first** in bootstrap's function list, **and** `require_once`d at the top of `fn_email.php`, `fn_activity_log.php` and `fn_error_handler.php` (after each file's direct-access guard).
- Why: each of those files can be loaded without bootstrap (the cron runner, the tests, child processes).
- `require_once` checks the real file path, so the repeated loads do nothing.
- This is the first `_functions` file that loads a sibling file itself. Explain that in each comment.

**2.3.3 Where it is used.**
- Both `fn_activity_log.php` fallbacks: the type, the detail and the database error.
- `logEmailAttempt()`'s fallback.
- `maskSensitiveForLog()` gains an address step, so **every** `logError()` fallback line is covered.
- `emailQueueSafeDetail()` becomes a one-line wrapper round `cleanLogText()`. Its results are identical, so its tests stay green.

**2.3.4 Subjects stay in the fallback line.**
- They are fixed text, except the invitation subject (organisation name) and the receipt subject (order number).
- They are useful for diagnosis.

### 2.4 Alternatives considered and rejected (put the short reasons into code comments)

| Alternative | Why rejected |
| --- | --- |
| Keep only "non-secret" parameters after sending (remove just the link keys) | Needs a per-template list of which keys are secret, which somebody must keep up to date. The other keys (display name, expiry number) are copies of `toName` or constants. NULL is simpler, total, and easy to test. |
| Replace `paramsJSON` with a marker object such as `{"removed":true}` | A marker key could clash with a future template parameter. NULL is unambiguous (`queueEmail()` never writes NULL) and easy to query (`paramsJSON IS NULL`). |
| Clear the details at give-up too | Anyone whose verification email was lost in an outage could never be sent it again: there is no "send a new link" feature, and they cannot sign up again (owner decision 2). |
| Mask `toAddress` on finished rows | Makes "did this person's email go out?" unanswerable. The purge limits how long addresses are kept instead. |
| A clean-up step that clears the details of **old sent** rows | It would only ever find rows sent before this change. There are none: nothing sent the queue before #112, which ships in the same release. It would scan every sent row each minute, and it would break the existing "nothing changed" test. DEV_NOTES gets a one-off SQL for any stray rows instead. |
| Purge by `sentAt`/`updatedAt` | See 2.1.5. |
| Skip only when the link has already expired (no margin) | The runner could still send a link that dies seconds later, mid-run. |
| Per-row expiry check only | Dead emails would use the 20 send slots per run, so after a long outage fresh sign-ups would wait behind them. |
| Bulk expiry step only | Its per-run cap, a failed step, or a lock-bypassed run could still let a dead link through. |
| Check the age in PHP with `time()` | PHP's clock follows `site_timezone`, but the database stores UTC. SQL on both sides avoids any mismatch. |
| Copy the lifetimes into `fn_email.php` as its own constants | Two places to change one number; they would drift apart. |
| Move the lifetime constants into `fn_email.php` | `fn_email.php` loads before `fn_auth.php` in bootstrap, so `fn_auth.php`'s own definition would silently stop taking effect. |
| Run the clean-up only when sending is on | Data would be kept indefinitely whenever the owner switches email off. |
| Run the clean-up without the lock | Possible InnoDB deadlocks with another runner's claims. A deadlocked claim would stop that run. |
| A new "last clean-up time" setting, to run the clean-up only hourly | An extra settings write every hour and more moving parts. Each statement is cheap and uses an index. |
| An `ALTER` for the live column comment | See 2.1.7. |
| #126: have the activity logger call `emailQueueSafeDetail()` (the HANDOFF note on 7i suggests this) | `fn_activity_log.php` loads before `fn_email.php`, so a `function_exists()` check would **silently skip masking** whenever `fn_email.php` was not loaded. |
| #126: copy the regular expression into each logger | The copies would drift apart. |
| #126: leave `$detail` out of the fallback line for email events | Loses the useful part (what happened). Masking removes only the personal part. |
| #126: send the cron runner's PHP error log to a private file | The cron email is the owner's only alert channel on shared hosting (the runner's "could not start" message relies on it). A private log also needs a safe place outside the web root, and rotation. After masking, what remains is technical. |
| An address pattern that understands non-English letters (the `/u` flag) | `preg_replace()` returns null for any invalid UTF-8 byte, which would blank whole log lines. The plain ASCII pattern never fails. The limitation is documented instead. |

---

## 3. OWNER DECISIONS (ask these up front; the build goes ahead meanwhile with the recommended answers)

1. **How long should email records be kept?**
   - **Recommended: sent and skipped, 30 days; given up (failed), 90 days.**
   - Why it matters: these rows hold people's email addresses. UK GDPR says personal data should be kept no longer than needed. `privacy.php` §7 promises that technical logs are purged after "a limited diagnostic period"; the exact periods there are a `[TEMPLATE]` placeholder waiting for legal review (#38).
   - 30 days covers "I never got my email" questions. Given-up emails are the ones support gets asked about later, and there are few of them.
   - Meanwhile: build with 30 and 90. Both are settings, so they can be changed later without a code change.
2. **Keep a given-up email's one-time link for 24 hours, so it can still be re-sent after an outage?**
   - **Recommended: yes.**
   - Why it matters: removing it straight away is slightly more private. But then anyone whose sign-up email was caught by an outage is locked out for good. There is no "send me a new link" button, and they cannot sign up again with the same address.
   - The link is only usable during its own 24-hour life anyway.
   - Meanwhile: build with 24 hours. It is one constant, so switching to "remove at once" later is a small change.

**Not owner decisions.** They are explained above so nobody reopens them:
- the clean-up runs while email is switched off (2.1.8);
- the 2-minute margin (2.2.2);
- no database change for existing installs (2.1.7);
- the cron runner's error log stays on the terminal (2.4).

---

## 4. Files to change — in detail

**Build order.** Run the suite after each step, and read the exit code directly.
- **Step A (#126):** 4.1 → 4.2 → 4.3 → 4.4 → 4.5.1, then the new test file (6.2).
- **Step B (#122/#123):** 4.5.2 to 4.5.12, 4.6, 4.7, 4.8, then the test additions (6.1) and the integration scenarios (6.3).
- **Step C:** documentation (7), then verification (8).

### 4.1 NEW `web/CueRCode.net/_functions/fn_log_mask.php`
Near-final code. Keep the logic exactly as below; expand the comments.

```php
<?php
/**
 * ==============================================================================
 * 🙈 CueRCode - Log masking helpers (keep email addresses out of plain-text logs)
 * ==============================================================================
 *
 * Path: _functions/fn_log_mask.php
 *
 * Small helpers with NO dependencies that hide anything shaped like an email
 * address before text is written somewhere less protected than the database -
 * PHP's error log above all. On a web request that log is a server file; on
 * the command line (the email queue runner, run by cron) it is the terminal,
 * which cron emails to the owner (#126).
 *
 * Used by fn_activity_log.php (its error_log() fallbacks), fn_error_handler.php
 * (maskSensitiveForLog()) and fn_email.php (emailQueueSafeDetail() and
 * logEmailAttempt()'s fallback).
 *
 * 📌 WHY a separate file: fn_activity_log.php loads BEFORE fn_email.php and
 * must not depend on it, and fn_email.php is loaded ON ITS OWN by the cron
 * runner (before the site start-up code) and by the tests. A file with no
 * dependencies that each of them require_once's gives ONE copy of the rule
 * and no load-order trap.
 *   Rejected: copying the regular expression into each logger (copies drift
 *   apart); calling emailQueueSafeDetail() from the activity logger (it would
 *   silently not mask whenever fn_email.php had not been loaded yet).
 *
 * ⚠️ What it CANNOT do: it recognises plain ASCII addresses only. An address
 * with non-English letters before the "@" may be left partly visible (the
 * letters before the first ASCII one). A Unicode-aware pattern was rejected
 * because preg_replace() fails on any invalid UTF-8 byte, which would blank
 * whole log lines. It does not recognise other personal data or secrets -
 * callers must still never log bodies, links, tokens or passwords.
 *
 * @package     CueRCode
 * @subpackage  Logging
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-09-25 (#126)
 * @see         fn_activity_log.php, fn_error_handler.php, fn_email.php
 */

// 🚫 Direct access protection - the same pattern as every other _functions file.
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $scriptFilename = $_SERVER['SCRIPT_FILENAME'];
} else {
    $scriptFilename = '';
}
if (basename($scriptFilename) === basename(__FILE__)) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied.');
}

/** @var string The address pattern - unchanged from emailQueueSafeDetail() (#112), so its tests stay green. */
if (!defined('CUERCODE_LOG_MASK_EMAIL_PATTERN')) {
    define('CUERCODE_LOG_MASK_EMAIL_PATTERN', '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+/');
}

/** @var string What an address is replaced with. */
if (!defined('CUERCODE_LOG_MASK_EMAIL_REPLACEMENT')) {
    define('CUERCODE_LOG_MASK_EMAIL_REPLACEMENT', '[address]');
}

function maskEmailAddresses(string $text): string
{
    if ($text === '') {
        return '';
    }

    $masked = preg_replace(CUERCODE_LOG_MASK_EMAIL_PATTERN, CUERCODE_LOG_MASK_EMAIL_REPLACEMENT, $text);

    if (!is_string($masked)) {
        // 🛡️ A failed replace must NEVER let the raw text through - return nothing instead.
        return '';
    }

    return $masked;
}

function cleanLogText(string $text, int $maxLength = 1000): string
{
    // 🧹 Control characters (including newlines and tabs) become spaces, so a
    //    crafted value cannot forge extra lines in a log or a cron email.
    $clean = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);

    if (!is_string($clean)) {
        return '';
    }

    // 🙈 Hide anything shaped like an email address.
    $clean = maskEmailAddresses($clean);

    // 📏 Collapse runs of whitespace to one space.
    $clean = preg_replace('/\s+/', ' ', $clean);

    if (!is_string($clean)) {
        return '';
    }

    $clean = trim($clean);

    // ✂️ Cut to length, marking the cut with "..." (never below 20 characters).
    $maxLength = max(20, $maxLength);

    if (mb_strlen($clean) > $maxLength) {
        $clean = mb_substr($clean, 0, $maxLength - 3) . '...';
    }

    return $clean;
}
```
Docblocks:
- `maskEmailAddresses(string $text): string`: returns the text with every address-shaped piece replaced by `[address]`, or `''` if the replace failed.
- `cleanLogText(string $text, int $maxLength = 1000): string`: explain each step and why, and say it is the same algorithm `emailQueueSafeDetail()` has used since #112.

### 4.2 `web/CueRCode.net/_includes/bootstrap.php`
1. Make `'fn_log_mask.php'` the **first** entry of `$functionFiles` (`:164`), with this comment:

   ```php
   // 🙈 #126: hides email addresses in text before it is written to PHP's
   //    error log. It has no dependencies, so it loads first. fn_error_handler.php,
   //    fn_activity_log.php and fn_email.php ALSO require_once it themselves,
   //    because each can be loaded without this file (the cron runner loads
   //    fn_email.php before bootstrap; the tests load single files). require_once
   //    resolves the real path, so the repeat loads do nothing.
   'fn_log_mask.php',
   ```
2. In the "Step 3" comment above it (`:155-160`), add "0. Log masking (no dependencies)" to the dependency-order list.
3. Change nothing else.

### 4.3 `web/CueRCode.net/_functions/fn_activity_log.php`
1. Header: `@version 1.0.0` → `1.1.0`. Add a paragraph saying that its error-log fallbacks hide email addresses since #126, and why (the error log is less protected; on the command line it is the cron email). Add `@see fn_log_mask.php`.
2. Immediately **after** the direct-access guard (`:29-37`):

   ```php
   // 🙈 #126: the error-log fallbacks below mask email addresses with
   //    cleanLogText(). Loaded here as well as by bootstrap.php, because this
   //    file must work even when loaded on its own.
   require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_log_mask.php';
   ```
3. Fallback 1 (`:197-200`) becomes:

   ```php
   error_log(
       'CueRCode Activity [' . cleanLogText($type, 100) . ']: ' . cleanLogText($detail, 1000)
       . ' | Entity: ' . $entityTypeStr . ':' . $entityIDStr
   );
   ```
4. Fallback 2 (`:243-251`) becomes:

   ```php
   // 🔒 F-008 + #126: this is the plain-text channel (on the command line, the
   //    cron email). The database error can carry SQL text, so it goes through
   //    maskSensitiveForLog() when the error handler is loaded; everything goes
   //    through cleanLogText(), which hides email addresses and control characters.
   $safeError = $e->getMessage();
   if (function_exists('maskSensitiveForLog')) {
       $safeError = maskSensitiveForLog($safeError);
   }
   error_log(
       'CueRCode Activity Log FAILED [' . cleanLogText($type, 100) . ']: ' . cleanLogText($detail, 1000)
       . ' | DB Error: ' . cleanLogText($safeError, 1000)
   );
   ```
   In the comment, record what was rejected: leaving `$detail` out (it loses what happened; only the address is personal), and reusing `emailQueueSafeDetail()` (it silently would not exist whenever `fn_email.php` was not loaded).
5. Change nothing else. **The database path is unchanged:** `tblActivityLog` still stores the full detail, and that is a separate follow-up (§10).

### 4.4 `web/CueRCode.net/_functions/fn_error_handler.php`
1. Header: `@version 1.0.0` → `1.1.0`, plus one line: "maskSensitiveForLog() also hides email addresses (#126)".
2. After the direct-access guard (`:34-42`), add the same `require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_log_mask.php';` with a comment.
3. In `maskSensitiveForLog()` (`:558-616`), add a third step just before `return $masked;`:

   ```php
   // ----------------------------------------------------------------------
   // 3️⃣ Hide email addresses (#126)
   // ----------------------------------------------------------------------
   // A database driver message can quote a value that is an email address
   // (for example "Duplicate entry '<address>' for key ..."), and this is the
   // plain-text channel - on the command line it is the cron email.
   // The steps above can hand back null if a regular expression fails; never
   // pass that on (this function must return a string) and never return raw text.
   if (!is_string($masked)) {
       return '';
   }
   $masked = maskEmailAddresses($masked);
   ```
4. Update its docblock: three reductions now, not two.
5. Do **not** touch the session-id masking. `tests/test_auth.php:512-523` pins that code.

### 4.5 `web/CueRCode.net/_functions/fn_email.php`

#### 4.5.1 Step A parts (#126)
- After the direct-access guard (`:58-66`), add `require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_log_mask.php';` with a comment. The comment must say: the cron runner loads this file BEFORE bootstrap and the tests load it alone, so it loads its own helper; `require_once` makes the later bootstrap load a no-op.
- `emailQueueSafeDetail()` (`:1246-1279`): keep the name, parameters, default (200) and docblock. Replace the body with `return cleanLogText($text, $maxLength);`, and add to the docblock: "#126: now delegates to cleanLogText() (fn_log_mask.php), so the activity logger, the error logger and the queue runner share ONE copy of the rule. The behaviour is unchanged."
- `logEmailAttempt()`'s fallback (`:403-407`) becomes
  `error_log('CueRCode Email [' . cleanLogText($activityType, 50) . ']: ' . cleanLogText($toAddress . ' | ' . $detail, 1000));`
  with a comment explaining why.

#### 4.5.2 File header
- `@version 1.1.0` → `1.2.0`.
- Add a paragraph "🧹 KEEPING ONLY WHAT IS NEEDED (#122, #123)" in plain words:
  - the details are cleared when an email is sent or skipped, and 24 hours after it is given up;
  - old records are deleted after 30/90 days (settings);
  - emails whose link has expired are skipped, not sent;
  - all of this is done by the runner, under its lock.
- Add `@see fn_log_mask.php`.

#### 4.5.3 New constants
Put them in a new block headed `// 🧹 Clean-up and expired links (#122, #123)`, right after `CUERCODE_EMAIL_QUEUE_NOTE_STUCK_GAVE_UP` (`:1035-1040`). Use the same `if (!defined())` pattern, each with a "why" comment. **Order matters:** the margin must be defined before the expired-link note that uses it.

```text
CUERCODE_EMAIL_QUEUE_LINK_EXPIRY_MARGIN_SECONDS      = 120    (2.2.2)
CUERCODE_EMAIL_QUEUE_RESEND_WINDOW_HOURS             = 24     (2.1.3; owner decision 2)
CUERCODE_EMAIL_QUEUE_TIDY_BATCH                      = 500    (most rows one clean-up statement touches per run - keeps each statement short, so row locks are held only briefly)
CUERCODE_EMAIL_QUEUE_RETENTION_DAYS_DEFAULT          = 30     (owner decision 1)
CUERCODE_EMAIL_QUEUE_GIVEN_UP_RETENTION_DAYS_DEFAULT = 90     (owner decision 1)
CUERCODE_EMAIL_QUEUE_RETENTION_DAYS_MAX              = 3650   (ten years - anything above is treated as a typo)
CUERCODE_EMAIL_QUEUE_SETTING_RETENTION_DAYS          = 'email_queue_retention_days'
CUERCODE_EMAIL_QUEUE_SETTING_GIVEN_UP_RETENTION_DAYS = 'email_queue_failed_retention_days'   ("failed" because that is the status the owner sees in phpMyAdmin)
CUERCODE_EMAIL_QUEUE_REQUIRED_LINK_PARAMS            = ['verification' => 'verificationUrl', 'password_reset' => 'resetUrl', 'invitation' => 'inviteUrl']
                                                        (the parameter each template REQUIRES - from each template's own docblock; keep in step with isValidEmailTemplateKey())
CUERCODE_EMAIL_QUEUE_NOTE_LINK_EXPIRED    = 'Not sent: its one-time link had expired, or had less than ' . intdiv(CUERCODE_EMAIL_QUEUE_LINK_EXPIRY_MARGIN_SECONDS, 60) . ' minutes left, before it could be sent. The person needs to ask for a new link.'
CUERCODE_EMAIL_QUEUE_NOTE_DETAILS_REMOVED = 'Not sent: its stored details (including any one-time link) had already been removed, so it can no longer be sent. The person needs to ask for a new email.'
CUERCODE_EMAIL_QUEUE_NOTE_LINK_MISSING    = 'Not sent: the one-time link this email needs is missing, so it would only have delivered a broken email.'
```
Also append 5 operations to `CUERCODE_EMAIL_QUEUE_GATEWAY_KEYS` (`:1001-1018`), **at the end, in this order**: `'markSkipped', 'skipExpired', 'clearGivenUp', 'purgeFinished', 'purgeGivenUp'`.

#### 4.5.4 New pure helpers
Add a new section after `formatEmailQueueRunReport()`, headed `// 🧮 Clean-up and expired-link helpers (#122, #123)`. Each gets a full docblock.

```php
function parseEmailQueueRetentionDays(mixed $value): ?int
{
    // 🔢 Only a whole number from 1 to CUERCODE_EMAIL_QUEUE_RETENTION_DAYS_MAX.
    //    getConfig() turns a typo into 0 for an 'integer' setting, so 0 MUST mean
    //    "not valid" - never "delete everything now". No "keep forever" value on purpose.
    if (is_int($value)) {
        $days = $value;
    } elseif (is_string($value) && preg_match('/^\s*[0-9]{1,5}\s*$/', $value) === 1) {
        $days = (int) trim($value);
    } else {
        return null;
    }

    if ($days < 1 || $days > CUERCODE_EMAIL_QUEUE_RETENTION_DAYS_MAX) {
        return null;
    }

    return $days;
}
```

`emailQueueHousekeepingOptions(callable $getSetting): array` returns `['retentionDays' => int, 'givenUpRetentionDays' => int, 'invalidSettings' => list<string>]`:
- Loop over the two settings (key constant → option name → default).
- `$rawValue = $getSetting($key, null);` inside `try`/`catch (\Throwable)` → treat a throw as `null`.
- `null` means the row is missing (the normal state on installs from before #122) → keep the default, **and do not note it**.
- Otherwise `$parsed = parseEmailQueueRetentionDays($rawValue)`. If `null` → keep the default and append the setting **name** to `invalidSettings`. Otherwise use `$parsed`.
- **Never** put the value anywhere in the result.

`emailQueueLinkLifetimes(): array` (seconds by template key; the `defined()` checks run at call time):
```php
$lifetimes = [];
if (defined('CUERCODE_EMAIL_VERIFICATION_TTL_HOURS')) {
    $lifetimes['verification'] = (int) CUERCODE_EMAIL_VERIFICATION_TTL_HOURS * 3600;
}
if (defined('CUERCODE_PASSWORD_RESET_TTL_MINUTES')) {
    $lifetimes['password_reset'] = (int) CUERCODE_PASSWORD_RESET_TTL_MINUTES * 60;
}
return $lifetimes;
```
- Docblock: these are `fn_auth.php`'s constants, which set the real token expiry. They are read at RUN time because this file can load before `fn_auth.php` (cron runner, tests).
- `invitation` has no entry yet; add one when invitations are built (`tblInvitations.expiresAt`).
- What it cannot do: if `fn_auth.php` were not loaded it returns `[]`, and nothing is skipped for age. `--check` shows that loudly (4.5.11). Bootstrap always loads `fn_auth.php` before any batch runs.

`normaliseEmailQueueLinkLifetimes(array $lifetimes): array`: keeps only entries whose key is a string that passes `isValidEmailTemplateKey()` and whose value `is_int` and `>= 1`.

`emailQueueLinkMaxAgeSeconds(int $lifetimeSeconds): int` → `max(60, $lifetimeSeconds - CUERCODE_EMAIL_QUEUE_LINK_EXPIRY_MARGIN_SECONDS)`.

`emailQueueLinkHasExpired(string $templateKey, int $ageSeconds, array $linkLifetimes): bool`:
- `false` if `!array_key_exists($templateKey, $linkLifetimes)`;
- otherwise `$ageSeconds >= emailQueueLinkMaxAgeSeconds((int) $linkLifetimes[$templateKey])`.
- This is **the same rule as SQL statement O** (`createdAt <= NOW() - maxAge` is the same as `age >= maxAge`).

`emailQueueRequiredLinkParam(string $templateKey): ?string` → the value from `CUERCODE_EMAIL_QUEUE_REQUIRED_LINK_PARAMS`, or `null`.

`formatEmailQueueTidyLines(array $result): array` (read each count with `isset()` + `(int)`, so an older-shaped result cannot crash it). Lines, in this order, only when the count is above 0:
- `expired > 0`: `'Skipped ' . n . ' email(s) whose one-time link had expired, or had less than ' . intdiv(MARGIN, 60) . ' minutes left, before it could be sent - the person needs to ask for a new link.'`
- `purged > 0 || detailsCleared > 0`: `'Tidied up: deleted ' . purged . ' old email record(s) and removed the stored details of ' . detailsCleared . ' given-up email(s).'`
- `housekeepingFailures > 0`: `'Could not finish tidying up old email records (database error - details are in the error log). Sending was not affected.'`

#### 4.5.5 The result, exit code, report and usage
- `emptyEmailQueueRunResult()` (`:1472-1492`): **append** these keys (never rename existing ones), each with a comment:
  - `'expired' => 0` — waiting emails skipped because their link had expired (clean-up step + per-row check);
  - `'unsendable' => 0` — claimed emails skipped because their details were removed or their link is missing;
  - `'detailsCleared' => 0` — given-up rows whose details were cleared;
  - `'purged' => 0` — old finished rows deleted;
  - `'housekeepingFailures' => 0` — clean-up steps that failed.

  Update the docblock's shape.
- `emailQueueRunnerExitCode()` (`:1504-1519`): the first test becomes
  `if ($result['status'] === 'lock_error' || $result['status'] === 'db_error' || (isset($result['housekeepingFailures']) && (int) $result['housekeepingFailures'] > 0)) { return CUERCODE_EMAIL_RUNNER_EXIT_DATABASE; }`.
  Docblock: a failed clean-up is a database problem that needs fixing (otherwise data would be kept longer than promised), but sending still happened.
- `formatEmailQueueRunReport()` (`:1533-1602`): after the `recordFailures` line, add:
  - if `unsendable > 0`: `'Skipped ' . n . ' email(s) that can no longer be sent because their stored details (including the one-time link) were already removed or are missing.'`;
  - then append every line of `formatEmailQueueTidyLines($result)`.

  The early returns for `busy`/`stalled` stay as they are (no clean-up runs in those cases).
- `emailQueueRunnerUsage()` (`:1428-1456`):
  - change the exit-code-3 line to `... = a database problem (cannot connect, queue table unreadable, lock error), or the clean-up of old email records failed (sending is not affected).`;
  - before the final "Run it from a DreamHost cron job..." line, add `'It also tidies up: one-time links are removed once an email is sent, emails whose link has expired are skipped, and old email records are deleted - see DEV_NOTES.md, "Email queue clean-up".'` and an empty line.

#### 4.5.6 `runEmailQueueHousekeeping()` (new; place it just before `runEmailQueueBatch()`)
```php
function runEmailQueueHousekeeping(array $gateway, array $linkLifetimes, int $retentionDays, int $givenUpRetentionDays, int $tidyLimit): array
{
    $counts = [
        'expired'        => 0,
        'detailsCleared' => 0,
        'purged'         => 0,
        'failures'       => 0,
    ];

    // 1️⃣ #123: skip WAITING emails whose link has expired (or nearly). Done
    //    first, so the batch below can never pick one of them.
    foreach ($linkLifetimes as $templateKey => $lifetimeSeconds) {
        try {
            $changed = $gateway['skipExpired'](
                (string) $templateKey,
                emailQueueLinkMaxAgeSeconds((int) $lifetimeSeconds),
                CUERCODE_EMAIL_QUEUE_NOTE_LINK_EXPIRED,
                $tidyLimit
            );
        } catch (\Throwable $e) {
            $changed = null;
        }

        if (is_int($changed) && $changed >= 0) {
            $counts['expired'] = $counts['expired'] + $changed;
        } else {
            $counts['failures']++;
        }
    }

    // 2️⃣ #122: clear the details of given-up emails once the re-send window has passed.
    try {
        $changed = $gateway['clearGivenUp'](CUERCODE_EMAIL_QUEUE_RESEND_WINDOW_HOURS, $tidyLimit);
    } catch (\Throwable $e) {
        $changed = null;
    }

    if (is_int($changed) && $changed >= 0) {
        $counts['detailsCleared'] = $changed;
    } else {
        $counts['failures']++;
    }

    // 3️⃣ #122: delete old FINISHED records (never waiting or in-flight ones).
    $purges = [
        'purgeFinished' => $retentionDays,
        'purgeGivenUp'  => $givenUpRetentionDays,
    ];

    foreach ($purges as $operationName => $olderThanDays) {
        try {
            $changed = $gateway[$operationName]($olderThanDays, $tidyLimit);
        } catch (\Throwable $e) {
            $changed = null;
        }

        if (is_int($changed) && $changed >= 0) {
            $counts['purged'] = $counts['purged'] + $changed;
        } else {
            $counts['failures']++;
        }
    }

    return $counts;
}
```
Docblock:
- The caller MUST hold the queue lock (2.1.8: why).
- Each step is independent: a failure is counted and the next step still runs.
- The real database functions log their own errors.
- What it cannot do: each statement handles at most `$tidyLimit` rows per run, and the rest follow on the next run (a minute later).

#### 4.5.7 Changes inside `runEmailQueueBatch()` (`:1665-2033`)
Keep every existing step and comment. Change only these places.

(a) **Options** (after the existing `clock` option, `:1703-1709`). Write each out in full, with no shorthand:
```php
$sendEmails = true;
if (array_key_exists('sendEmails', $options) && $options['sendEmails'] === false) {
    $sendEmails = false;   // 🔕 tidy-only run (email switched off / settings incomplete) - see emailQueueRunnerMain()
}

$retentionDays = CUERCODE_EMAIL_QUEUE_RETENTION_DAYS_DEFAULT;
if (array_key_exists('retentionDays', $options)) {
    $parsedDays = parseEmailQueueRetentionDays($options['retentionDays']);
    if ($parsedDays !== null) {
        $retentionDays = $parsedDays;
    }
}
// ... the same for 'givenUpRetentionDays' with CUERCODE_EMAIL_QUEUE_GIVEN_UP_RETENTION_DAYS_DEFAULT

$tidyLimit = CUERCODE_EMAIL_QUEUE_TIDY_BATCH;
if (isset($options['tidyLimit'])) {
    $tidyLimit = (int) $options['tidyLimit'];
}
$tidyLimit = max(1, min($tidyLimit, CUERCODE_EMAIL_QUEUE_TIDY_BATCH));

if (isset($options['linkLifetimes']) && is_array($options['linkLifetimes'])) {
    $linkLifetimes = normaliseEmailQueueLinkLifetimes($options['linkLifetimes']);
} else {
    $linkLifetimes = normaliseEmailQueueLinkLifetimes(emailQueueLinkLifetimes());
}
```
Comment: a bad option can never mean "delete everything now". An invalid retention falls back to the default.

(b) **Busy block** (`:1726-1748`): wrap only the overdue/stalled lines in `if ($sendEmails) { ... }`. Status `busy` and the `return` stay outside. Comment: while sending is switched off, emails are expected to wait, so a held lock is never a "stall".

(c) **Stuck recovery** (`:1766-1772`): change the start of the block to
```php
if ($sendEmails) {
    $stuckRows = $gateway['findStuck']($stuckMinutes, CUERCODE_EMAIL_QUEUE_STUCK_BATCH);
    if (!is_array($stuckRows)) { /* existing db_error lines + return */ }
} else {
    $stuckRows = [];   // 🔕 tidy-only run: nothing is recovered or claimed
}
```
The existing `foreach ($stuckRows ...)` and the log line after it stay as they are. This keeps the change small: no re-indenting.

(d) **Clean-up call.** Insert it immediately **after** the stuck-recovery log block (`:1815-1818`) and **before** "📋 The due rows":
```php
// ======================================================================
// 🧹 #122 / #123: the clean-up - ONLY while this run holds the queue lock,
//    and BEFORE anything is claimed (so an expired link is skipped, never sent)
// ======================================================================
if ($haveLock) {
    $housekeeping = runEmailQueueHousekeeping($gateway, $linkLifetimes, $retentionDays, $givenUpRetentionDays, $tidyLimit);

    $result['expired']              = $result['expired'] + $housekeeping['expired'];
    $result['detailsCleared']       = $housekeeping['detailsCleared'];
    $result['purged']               = $housekeeping['purged'];
    $result['housekeepingFailures'] = $housekeeping['failures'];
}
```
Comment (2.1.8): why only under the lock (InnoDB deadlock risk with another runner's claims), and why `useLock => false` therefore skips it.

(e) **Due rows** (`:1824-1832`) become:
```php
if ($sendEmails) {
    $dueIDs = $gateway['findDue']($limit);
    if (!is_array($dueIDs)) { /* existing db_error lines + return */ }
} else {
    $dueIDs = [];      // 🔕 tidy-only run: nothing is claimed or sent
}
```
The `foreach ($dueIDs ...)` loop stays unindented and otherwise unchanged, except for (f).

(f) **Per-row guards.** In the loop, right after the `$toName` block (`:1882-1888`), **replace** the existing `$params = json_decode(...)` lines (`:1890-1896`) with:
```php
// 🛑 #122 / #123: emails that must never be rendered or sent. Each is marked
//    'skipped' (a final state; its details are cleared in the same statement)
//    with a plain-English reason. No network call is made.
$skipReason = null;
$skipKind   = null;

// 1️⃣ Its stored details were already removed (they are cleared when an email is
//    sent or skipped, and 24 hours after it is given up). The owner's re-send
//    runbook, or a hand edit, can put such a row back to 'queued'. Rendering it
//    would send an email with an EMPTY link, because the templates quietly
//    default a missing link to ''.
if (!array_key_exists('paramsJSON', $row) || $row['paramsJSON'] === null || $row['paramsJSON'] === '') {
    $skipReason = CUERCODE_EMAIL_QUEUE_NOTE_DETAILS_REMOVED;
    $skipKind   = 'unsendable';
}

// 2️⃣ #123 backstop: its link has expired (or nearly), measured on the DATABASE's
//    clock. The clean-up step normally skips these first; this catches any it did
//    not reach (its per-run cap, a failed clean-up step, or a run without the lock).
if ($skipReason === null
    && isset($row['ageSeconds'])
    && is_numeric($row['ageSeconds'])
    && emailQueueLinkHasExpired((string) $row['templateKey'], (int) $row['ageSeconds'], $linkLifetimes)
) {
    $skipReason = CUERCODE_EMAIL_QUEUE_NOTE_LINK_EXPIRED;
    $skipKind   = 'expired';
}

// 🎨 The template parameters (only decoded when the email may still be sent).
$params = [];

if ($skipReason === null) {
    $decodedParams = json_decode((string) $row['paramsJSON'], true);

    if (is_array($decodedParams)) {
        $params = $decodedParams;
    }

    // 3️⃣ A template that needs a link, with no link.
    $requiredLinkParam = emailQueueRequiredLinkParam((string) $row['templateKey']);

    if ($requiredLinkParam !== null
        && (!isset($params[$requiredLinkParam]) || !is_string($params[$requiredLinkParam]) || trim($params[$requiredLinkParam]) === '')
    ) {
        $skipReason = CUERCODE_EMAIL_QUEUE_NOTE_LINK_MISSING;
        $skipKind   = 'unsendable';
    }
}

if ($skipReason !== null) {
    if ($gateway['markSkipped']($emailID, $skipReason) === true) {
        if ($skipKind === 'expired') {
            $result['expired']++;
        } else {
            $result['unsendable']++;
        }
    } else {
        // ⚠️ Left 'sending'; stuck-row recovery looks at it again later.
        $result['recordFailures']++;

        if (function_exists('logError')) {
            logError('warning', 'Email queue: could not mark an email as skipped', 'emailID ' . $emailID);
        }
    }

    continue;
}
```
The existing `try { $rendered = renderEmailTemplate(...$params) ...` code that follows stays as it is. It now uses this `$params`.

(g) **Docblock** (`:1629-1664`):
- add the five new operations to the operation list;
- add the new options (`sendEmails`, `retentionDays`, `givenUpRetentionDays`, `linkLifetimes`, `tidyLimit`);
- replace "It also does not check whether a link inside an email has expired" with a sentence on the clean-up, the guards, and the "useLock false skips the clean-up" rule.

#### 4.5.8 Database functions (new ones go after `settleEmailQueueFailure()`, `:2652-2661`; the SQL is in §5)
- New helper `emailQueueChangeRows(string $sql, string $types, array $params, string $errorTitle): ?int`.
  - Same shape as `emailQueueChangeOneRow()` (`:2116-2139`): return `null` if `dbQuery` is missing; `try`/`catch` → `logError('error', $errorTitle, $e->getMessage())` → `null`; `finally` → close the statement.
  - Returns `$stmt->affected_rows` when it is `>= 0`, otherwise `null`.
  - Docblock: affected rows = rows actually changed or deleted. Every statement that uses it changes a column or deletes, so the count is exact.
- `skipClaimedEmailQueueRow(int $emailID, string $reason): bool`: statement N through `emailQueueChangeOneRow()`; `true` only when exactly one row changed. Store the reason through `emailQueueSafeDetail($reason, 500)`.
- `skipExpiredEmailQueueRows(string $templateKey, int $maxAgeSeconds, string $note, int $limit): ?int`: statement O through `emailQueueChangeRows()`; the note goes through `emailQueueSafeDetail($note, 500)`.
- `clearGivenUpEmailQueueDetails(int $olderThanHours, int $limit): ?int`: statement P.
- `purgeFinishedEmailQueueRows(int $olderThanDays, int $limit): ?int`: statement Q.
- `purgeGivenUpEmailQueueRows(int $olderThanDays, int $limit): ?int`: statement R.

**Changed:**
- `settleEmailQueueSuccess()` (`:2413-2425`) uses statement F′. Update its docblock: why the details go in the same statement.
- `loadClaimedEmailQueueRow()` (`:2383-2400`) uses statement E′ (adds `ageSeconds`). Update its docblock.

Every new function's docblock must include:
- what it may never touch (sending rows; waiting rows for the purges);
- why there is no ORDER BY in O/P but there is one in Q/R (§5 notes);
- that it is only ever called under the queue lock.

#### 4.5.9 `emailQueueDatabaseGateway()` (`:2669-2687`)
Append, in this order: `'markSkipped' => 'skipClaimedEmailQueueRow'`, `'skipExpired' => 'skipExpiredEmailQueueRows'`, `'clearGivenUp' => 'clearGivenUpEmailQueueDetails'`, `'purgeFinished' => 'purgeFinishedEmailQueueRows'`, `'purgeGivenUp' => 'purgeGivenUpEmailQueueRows'`.

#### 4.5.10 `emailQueueRunnerMain()` (`:2714-2836`)
Steps 1-3 are unchanged. Then:
```php
// 4️⃣ The email settings, and the clean-up settings (#122).
$settings     = checkEmailQueueRunnerSettings($env['getSetting']);
$housekeeping = emailQueueHousekeepingOptions($env['getSetting']);

// 5️⃣ --check: report, change nothing.
if ($args['check'] === true) {
    return emailQueueRunnerCheck($settings, $env['gateway'], $out, $err, $housekeeping);
}

$batchOptions = [
    'limit'                => $args['limit'],
    'clock'                => $clock,
    'retentionDays'        => $housekeeping['retentionDays'],
    'givenUpRetentionDays' => $housekeeping['givenUpRetentionDays'],
];

// 6️⃣ / 7️⃣ Not ready to send (switched off, or switched on with settings missing):
//    still run the clean-up (#122/#123) - keeping data only as long as needed must
//    not depend on whether sending is on - but claim and send nothing.
if ($settings['state'] !== 'ready') {
    $tidyOptions               = $batchOptions;
    $tidyOptions['sendEmails'] = false;
    $tidyResult                = runEmailQueueBatch($env['gateway'], $env['sender'], $tidyOptions);
    $tidyExitCode              = emailQueueRunnerExitCode($tidyResult);   // 0, or 3 when the clean-up/lock failed
    $tidyLines                 = [];

    if ($tidyResult['status'] === 'lock_error' || $tidyResult['status'] === 'db_error') {
        $tidyLines[] = 'CueRCode email queue: ' . (string) $tidyResult['problem']
            . ' - the clean-up of old emails did not run (details are in the error log).';
    }

    foreach (formatEmailQueueTidyLines($tidyResult) as $tidyLine) {
        $tidyLines[] = $tidyLine;
    }

    if ($settings['state'] === 'disabled') {
        if ($tidyExitCode !== CUERCODE_EMAIL_RUNNER_EXIT_OK) {
            foreach ($tidyLines as $tidyLine) {
                $err($tidyLine);
            }

            return $tidyExitCode;
        }

        if ($args['quiet'] !== true) {
            // (the EXISTING "switched off ... N email(s) are waiting." lines, unchanged,
            //  including the direct $env['gateway']['countDue']() call)
            foreach ($tidyLines as $tidyLine) {
                $out($tidyLine);
            }
        }

        return CUERCODE_EMAIL_RUNNER_EXIT_OK;
    }

    // Switched on but not ready: say which settings (names only), as before.
    $err('CueRCode email queue: email is switched on, but it cannot send yet: '
        . implode('; ', $settings['problems']) . '. Nothing was sent.');

    foreach ($tidyLines as $tidyLine) {
        $err($tidyLine);
    }

    if ($tidyExitCode !== CUERCODE_EMAIL_RUNNER_EXIT_OK) {
        return $tidyExitCode;          // 3 outranks 4
    }

    return CUERCODE_EMAIL_RUNNER_EXIT_EMAIL_SETUP;
}

// 8️⃣ Send.
$result = runEmailQueueBatch($env['gateway'], $env['sender'], $batchOptions);
```
Steps 9 and 10 are unchanged, except the "attention" condition, which becomes:
`$exitCode !== OK || recovered > 0 || recordFailures > 0 || expired > 0 || unsendable > 0`.
Comment: skipped or unsendable emails during a sending run mean people did not get an email they asked for.

Update the docblock and the numbered step comments to match.

#### 4.5.11 `emailQueueRunnerCheck()` (`:2851-2896`)
- New signature: `emailQueueRunnerCheck(array $settings, array $gateway, callable $out, callable $err, array $housekeeping = []): int`. When a key is missing from `$housekeeping`, use the constants' defaults.
- It **still changes nothing**: no clean-up, no claims.
- Keep every existing line exactly (`test_email_queue_runner.php:1192`). Add these lines **after** the `Queue:` line:
  1. `'Skipped (not sent on purpose - link expired or details removed): ' . (int) $counts['skipped'] . '.'`
  2. Expired-link check, using `normaliseEmailQueueLinkLifetimes(emailQueueLinkLifetimes())`:
     - if not empty: `'Expired-link check: on (' . implode(', ', parts) . '); an email is skipped once its link has less than ' . intdiv(MARGIN, 60) . ' minutes left.'`
       - each part is `<templateKey> <n> hours` when `lifetime >= 7200 && lifetime % 3600 === 0`, otherwise `<templateKey> <n> minutes`;
       - today that gives `verification 24 hours, password_reset 60 minutes`;
     - if empty: `'Expired-link check: OFF - the link lifetimes are unknown because fn_auth.php is not loaded, so an expired link would still be sent. The cron runner loads it through bootstrap.php, so this means a set-up fault.'`
  3. `'Clean-up: one-time links are removed as soon as an email is sent or skipped, and ' . RESEND_WINDOW_HOURS . ' hours after it is given up; sent and skipped records are deleted after ' . N . ' days and given-up ones after ' . M . ' days. This also runs while sending is switched off.'`
  4. Only if `invalidSettings` is not empty: `'Clean-up settings that are not whole numbers from 1 to ' . MAX . ', so the default is used instead: ' . implode(', ', names) . '.'` — **names only, never values.**
- The exit codes are unchanged: 0, 3 or 4.

#### 4.5.12 `processEmailQueue()`, `queueEmail()`'s docblock, and section comments
- `processEmailQueue()` (`:2961-2994`): inside the existing `try`, build `$batchOptions` as today (`limit`, `timeBudgetSeconds`).
  - Then `if (function_exists('getConfig')) { $housekeeping = emailQueueHousekeepingOptions('getConfig'); ... }` sets `retentionDays` and `givenUpRetentionDays`.
  - Pass `$batchOptions`.
  - Mapping: `skipped = skipped + released + unsendable` (clean-up counts are not rows this call processed).
  - Keep the `!function_exists('dbQuery')` early return first (`test_email.php:326-331`).
  - The body must still contain `runEmailQueueBatch(` and `emailQueueDatabaseGateway()` (`test_email_queue_runner.php:1264-1266`).
- `queueEmail()`'s docblock (`:730-735`): add "The parameters can include a one-time link, so they are cleared when the email is sent or skipped, and 24 hours after it is given up (#122)."
- The #112 section comment (`:869-875`): replace "Links inside very old queued emails may have expired..." with the #123 behaviour. Add the clean-up to the layer list (`:843-856`) as "6. Clean-up (#122/#123) — under the lock, before sending".

### 4.6 `web/CueRCode.net/_functions/fn_auth.php` — comments only
- Add to the docblocks of `CUERCODE_EMAIL_VERIFICATION_TTL_HOURS` (`:70-73`) and `CUERCODE_PASSWORD_RESET_TTL_MINUTES` (`:78-81`): "Also read by fn_email.php emailQueueLinkLifetimes(), so the queue runner skips an email whose link has expired instead of sending it (#123). Change the lifetime HERE only."
- **No code change.** Leave the `strtotime()` time-zone issue alone (a follow-up, §10).

### 4.7 `web/CueRCode.net/_includes/sql/install.sql` — comments only
**Remember house rule NEW 1:** never write ``ALTER TABLE ` `` or `CREATE TABLE IF NOT EXISTS` in any new text.

1. **Block comment above `tblEmailQueue`** (`:421-455`):
   - Add to the lifecycle list:

     ```
     --   queued/failed -> skipped   (its one-time link expired, or had under 2 minutes left, before it could be sent - #123)
     --   sending -> skipped         (its stored details were already removed, or its one-time link is missing - it can never be sent)
     ```
   - Then add a paragraph "🧹 Clean-up (#122), done by the queue runner inside its lock". Plain words; it must mention each of these:
     - `paramsJSON` is set to NULL in the same UPDATE that marks an email sent or skipped, and 24 hours after an email is given up (the window in which the owner may still re-send it);
     - finished rows are deleted, counted from `createdAt`: sent and skipped after `email_queue_retention_days` (default 30), given up after `email_queue_failed_retention_days` (default 90);
     - rows still waiting or being sent are never deleted;
     - existing installs keep their old column comments until the upgrade mechanism (#113) exists; the code does not read the comments.
2. **Column COMMENTs inside the CREATE TABLE** (text only; keep the types, NULL rules, defaults and indexes exactly):
   - `paramsJSON` (`:461`) →
     `'📦 Template parameters as JSON, used only to render the email at send time (never rendered HTML). They can include a one-time sign-up or password-reset link, so they are set to NULL as soon as the email is sent or skipped, and 24 hours after it is given up (#122)'`
   - `status` (`:462-468`) →
     `'📊 Current lifecycle status of this queued email (skipped = deliberately not sent: its one-time link had expired, or its details were already removed - #122/#123)'`
   - `createdAt` (`:473`) →
     `'📅 Row creation (queue) timestamp - also the clock for expired links (#123) and for deleting old records (#122)'`
3. Change nothing else. No new statement, no `ALTER`.

### 4.8 `web/CueRCode.net/_includes/sql/seed_data.sql`
1. In the email block, directly **after** the `email_queue_autoprocess` row (`:117`), add two rows. Each ends with `,` exactly like its neighbours; check that the row before the next group still ends correctly.

   ```sql
   ('email', 'email_queue_retention_days', '30', 'integer', 0, 1, 'Days to keep a SENT or SKIPPED email record in tblEmailQueue before the queue runner deletes it (#122), counted from when it was queued. Whole number 1-3650; anything else means the default of 30 is used.'),
   ('email', 'email_queue_failed_retention_days', '90', 'integer', 0, 1, 'Days to keep a GIVEN-UP (failed) email record before the queue runner deletes it (#122) - longer than sent ones, because support is asked about these. Whole number 1-3650; anything else means the default of 90 is used.'),
   ```
   Each description is under 500 characters (the column is `VARCHAR(500)`).
2. Add to the email block's comment (`:86-107`) one paragraph "🧹 Clean-up settings (#122)". Say that existing installs do not get these rows (no upgrade script, #113); the code uses the same defaults when the rows are missing; DEV_NOTES has the SQL to add them.
3. Optional (fresh installs only; the same block): correct the stale `email_queue_autoprocess` description to `'Page-visit fallback for sending queued email (up to 2 per page request) - 0 = OFF (recommended: use the DreamHost cron job that runs _includes/cron/process_email_queue.php, #112); 1 = ON'`.
4. Summary comment `:676`: "78 default settings" → **"80 default settings"**, and add "(the email group gained the two #122 clean-up settings)".

### 4.9 `web/CueRCode.net/_includes/cron/process_email_queue.php` — optional, comment only
You may add one header line: "It also runs the queue clean-up (#122/#123) - see fn_email.php runEmailQueueHousekeeping()". **Do not write `toAddress` or `paramsJSON` in this file** (house rule NEW 2). No code change.

---

## 5. SQL — exact text (every statement is prepared, through `dbQuery()`)
Keep the backticks and the status values exactly as written. The source-text tests check this text. The letters carry on from the #112 plan's A-M.

```sql
-- E' loadClaimedEmailQueueRow   (CHANGED - adds ageSeconds, measured on the database clock)   types 'i'  [$emailID]
SELECT `emailID`, `toAddress`, `toName`, `templateKey`, `paramsJSON`, `subject`, `attempts`, `maxAttempts`,
       TIMESTAMPDIFF(SECOND, `createdAt`, NOW()) AS `ageSeconds`
FROM `tblEmailQueue`
WHERE `emailID` = ? AND `status` = 'sending'
LIMIT 1

-- F' settleEmailQueueSuccess   (CHANGED - clears the details in the same statement)   types 'i'  [$emailID]
UPDATE `tblEmailQueue`
SET `status` = 'sent', `sentAt` = NOW(), `lastError` = NULL, `paramsJSON` = NULL, `updatedAt` = NOW()
WHERE `emailID` = ? AND `status` = 'sending'

-- N  skipClaimedEmailQueueRow   (only the runner that claimed the row)   types 'si'  [emailQueueSafeDetail($reason, 500), $emailID]
UPDATE `tblEmailQueue`
SET `status` = 'skipped', `lastError` = ?, `paramsJSON` = NULL, `updatedAt` = NOW()
WHERE `emailID` = ? AND `status` = 'sending'

-- O  skipExpiredEmailQueueRows   (waiting rows only)   types 'ssii'  [emailQueueSafeDetail($note, 500), $templateKey, $maxAgeSeconds, $limit]
UPDATE `tblEmailQueue`
SET `status` = 'skipped', `lastError` = ?, `paramsJSON` = NULL, `updatedAt` = NOW()
WHERE `templateKey` = ?
  AND `status` IN ('queued', 'failed')
  AND `attempts` < `maxAttempts`
  AND `createdAt` <= DATE_SUB(NOW(), INTERVAL ? SECOND)
LIMIT ?

-- P  clearGivenUpEmailQueueDetails   (given-up rows only; keeps updatedAt)   types 'ii'  [$olderThanHours, $limit]
UPDATE `tblEmailQueue`
SET `paramsJSON` = NULL, `updatedAt` = `updatedAt`
WHERE `status` = 'failed'
  AND `attempts` >= `maxAttempts`
  AND `paramsJSON` IS NOT NULL
  AND `createdAt` <= DATE_SUB(NOW(), INTERVAL ? HOUR)
LIMIT ?

-- Q  purgeFinishedEmailQueueRows   (sent and skipped only)   types 'ii'  [$olderThanDays, $limit]
DELETE FROM `tblEmailQueue`
WHERE `status` IN ('sent', 'skipped')
  AND `createdAt` < DATE_SUB(NOW(), INTERVAL ? DAY)
ORDER BY `createdAt` ASC, `emailID` ASC
LIMIT ?

-- R  purgeGivenUpEmailQueueRows   (given up only)   types 'ii'  [$olderThanDays, $limit]
DELETE FROM `tblEmailQueue`
WHERE `status` = 'failed'
  AND `attempts` >= `maxAttempts`
  AND `createdAt` < DATE_SUB(NOW(), INTERVAL ? DAY)
ORDER BY `createdAt` ASC, `emailID` ASC
LIMIT ?
```

**Comments to carry into the code:**
- **N** and **F′** keep `AND \`status\` = 'sending'`: only the runner that claimed a row can finish it (#112).
- **O**: it never touches `sending` rows (only the claimer may finish those). It covers waiting rows **whether due now or in back-off**. `failed` with attempts left is kept because code from before #112 wrote that for rows still being retried.
- **O and P have no ORDER BY on purpose.** Every matching row is dealt with eventually, so the order does not matter. An ORDER BY with a LIMIT can lead MySQL to walk the `createdAt` index across most of the table. Without one it can use the small `status` index range.
  - MySQL may note an "unsafe for statement-based replication" warning for a LIMIT with no ORDER BY. That is a warning, not an error: `dbQuery()` only throws on errors, and the rows affected do not depend on the order.
- **Q and R** order by `createdAt, emailID` (oldest first). That matches `idx_email_created`, whose entries are stored as (createdAt, emailID), so the delete walks only the old end of the index.
- **P**: `updatedAt = updatedAt` stops MySQL's automatic refresh, so the time the email was given up stays readable (checked on MariaDB 10.6 and MySQL 8.4, §1.4).
- **`LIMIT ?` in UPDATE/DELETE** was checked on MariaDB 10.6 and MySQL 8.4 (§1.4). MySQL 5.7 and MariaDB 10.3 were **not** checked.
- **Q and R never match** `queued`, `sending`, or `failed` rows with attempts left. Say so, because this is the statement that deletes data.
- **Locking:** these range statements briefly lock index ranges. A web request queuing an email at the same moment may wait a few milliseconds. It cannot deadlock with them, because the insert holds no lock the clean-up needs. Another *runner* could deadlock with them, which is why they only ever run under the named lock.

---

## 6. Tests

### 6.1 `tests/test_email_queue_runner.php` — additions
**Rules (unchanged):**
- every variable starts with `$eqr`;
- **never** define `dbQuery`/`getDB`/`getConfig`/`logError`/`logActivity`;
- never start the real CLI except with `--help` or an invalid option;
- **do not edit or delete any existing assertion.**

Header: `@version 1.1.0`. Add the #122/#123 coverage to the "Covers" list.

**At the top**, after `requireFn('fn_email.php');`, add `requireFn('fn_auth.php');`. Comment: this gives the real link-lifetime constants whatever `--filter` is used. It is safe because `fn_auth.php` defines none of the forbidden functions, and `test_auth.php` already loads it earlier in a full run.

**Stand-in changes (Section 0):**
- `markSent`: also set `$eqrStore->rows[$eqrId]['paramsJSON'] = null;` (mirrors F′).
- `load`: also return `'ageSeconds' => $eqrStore->now - $eqrRow['createdAt'],` (mirrors E′).
- New operations, each mirroring its SQL exactly and honouring `failOps` (`true` → failure value `null`/`false`; `'throw'` → throw). Build each result list by walking the rows in ascending `emailID` order:
  - `markSkipped(int $id, string $reason): bool` — only if the row exists and is `sending`; sets `skipped`, `lastError`, `paramsJSON` null, `updatedAt` now.
  - `skipExpired(string $template, int $maxAge, string $note, int $limit): ?int` — rows with that template, status queued/failed, attempts < maxAttempts, `createdAt <= now - maxAge`; at most `$limit`; sets `skipped`/note/null/`updatedAt` now; returns the count.
  - `clearGivenUp(int $hours, int $limit): ?int` — status failed, attempts >= maxAttempts, `paramsJSON !== null`, `createdAt <= now - hours*3600`; at most `$limit`; sets `paramsJSON` null and **does not change `updatedAt`**.
  - `purgeFinished(int $days, int $limit): ?int` — status sent/skipped and `createdAt < now - days*86400`; sort oldest first (`createdAt`, then `emailID`); `unset()` at most `$limit`.
  - `purgeGivenUp(int $days, int $limit): ?int` — status failed, attempts >= maxAttempts, `createdAt < now - days*86400`; same ordering and limit.
- Add a helper `$eqrDay = 86400;` for readability.

**New named cases.** Use these exact message texts, grouped in a new "Section 6".

Pure helpers:
- `parseEmailQueueRetentionDays`:
  - `30`→30, `'45'`→45, `' 7 '`→7, `3650`→3650;
  - `0`, `-1`, `3651`, `'abc'`, `''`, `null`, `true`, `30.0`, `'1e3'` → null.
  - Message: `parseEmailQueueRetentionDays: <input> -> <out>`.
- `emailQueueHousekeepingOptions`:
  - `missing settings -> the defaults, nothing reported`;
  - `valid values are used`;
  - `0 / "thirty" -> the default, and the setting is named`;
  - `an invalid value is never included in the result` — use the distinctive value `'retention-value-777'` and assert it appears nowhere in `json_encode($result)`.
- `emailQueueLinkLifetimes() reads fn_auth.php's constants` — equals `['verification' => CUERCODE_EMAIL_VERIFICATION_TTL_HOURS * 3600, 'password_reset' => CUERCODE_PASSWORD_RESET_TTL_MINUTES * 60]`, **and** `=== ['verification' => 86400, 'password_reset' => 3600]` (pins today's lifetimes, so a change is noticed).
- `normaliseEmailQueueLinkLifetimes drops unknown templates and bad values` — `['verification' => 86400, 'nope' => 5, 'password_reset' => 0, 'receipt' => '60']` → `['verification' => 86400]`.
- `emailQueueLinkMaxAgeSeconds`: 86400→86280, 3600→3480, 100→60 (floor).
- `emailQueueLinkHasExpired`:
  - verification at 86280 → true, at 86279 → false;
  - password_reset at 3480 → true, at 3479 → false;
  - receipt at 999999 → false;
  - an empty lifetimes map → false.
- `emailQueueRequiredLinkParam`: verification→verificationUrl, password_reset→resetUrl, invitation→inviteUrl, receipt→null, nope→null.
- `emptyEmailQueueRunResult has the five new keys, each 0`.
- `emailQueueRunnerExitCode`: ok + housekeepingFailures 1 → 3; system_problem + housekeepingFailures 1 → 3; ok + expired 3 → 0.
- `formatEmailQueueTidyLines`:
  - expired 2 → contains `Skipped 2` and `new link`;
  - purged 3 + detailsCleared 1 → contains `deleted 3` and `details of 1`;
  - failures 1 → contains `Could not finish tidying up` and `Sending was not affected`;
  - all zero → `[]`.

Batch, #122:
1. `details are cleared in the same step that marks an email sent` — `paramsJSON === null` afterwards; the sent body contained the link.
2. `a retry keeps the details, so the next attempt still has its link` — HTTP 400 → queued, `paramsJSON` unchanged. Then set `now = nextAttemptAt`, run again: sent, and the body contains the link.
3. `giving up keeps the details for the re-send window` — attempts 4 + HTTP 400 → failed, `paramsJSON` unchanged.
4. `sending blocked keeps the details` — `skipped: no Graph access token available` → `paramsJSON` unchanged.
5. `stuck recovery keeps the details`.
6. `a claimed email whose details were removed is skipped, never rendered or sent` — row `paramsJSON => null`:
   - status `skipped`, `lastError === CUERCODE_EMAIL_QUEUE_NOTE_DETAILS_REMOVED`, `unsendable` 1, 0 sends, exit code 0;
   - `formatEmailQueueRunReport` contains `can no longer be sent`.
7. `a verification email with no link is skipped rather than sent broken` — `paramsJSON` `{"recipientName":"X"}`, and separately `{"verificationUrl":"   "}` → `skipped`, `CUERCODE_EMAIL_QUEUE_NOTE_LINK_MISSING`, 0 sends.
8. `a receipt (no link needed) is still sent with its details` — `templateKey 'receipt'`, `paramsJSON` `{"orderNumber":"R-1","amount":"9.99"}` → sent.
9. `given-up details are kept for 24 hours, then cleared (updatedAt kept)`:
   - two given-up rows (failed, 5/5): one with `createdAt now-86399` (kept), one with `createdAt now-86400` (cleared);
   - the cleared row's `updatedAt`, set beforehand to `now-86000`, is unchanged;
   - `detailsCleared` 1.
10. `old sent and skipped records are deleted after 30 days; newer ones are kept`:
    - sent at `now-30*day-1` → gone; sent at `now-30*day` → kept (strict `<`); skipped at `now-40*day` → gone;
    - `purged` 2.
11. `given-up records are kept for 90 days, then deleted` — given up at 31 days → kept; at 91 days → gone.
12. `the clean-up never deletes or changes an email that is waiting, being sent or still has attempts left` — these rows are unchanged (compare the whole arrays before and after):
    - receipt queued, 100 days old, `nextAttemptAt now+day`;
    - `sending`, `updatedAt now-60`, `createdAt` 100 days;
    - receipt failed with attempts 2, 100 days, `nextAttemptAt now+day`.
13. `retention options change the clean-up` — `retentionDays 7`: sent at 8 days → gone; `givenUpRetentionDays 10`: given up at 11 days → gone.
14. `an invalid retention option falls back to the default (never "delete everything")` — each of `0`, `'abc'` and `-3` for `retentionDays`: sent at 10 days → kept.
15. `the clean-up is capped per run` — `tidyLimit 2`, 3 old sent rows → `purged` 2; the next run → 1.
16. `a failing clean-up step is reported, does not stop sending, and gives exit code 3` — `failOps['purgeFinished'] = true` + 1 due row → `sent` 1, `housekeepingFailures` 1, exit 3; the report contains `Could not finish tidying up`.
17. `a clean-up step that throws is contained the same way` — `failOps['skipExpired'] = 'throw'` → the due row is still sent, `housekeepingFailures >= 1`, status `ok`.
18. `with the lock bypassed the clean-up never runs (it must never run alongside another runner)` — `useLock false`: an old sent row (40 days) is still there.

Batch, #123 (pass explicit `linkLifetimes` for the boundary cases):
19. `expired verification link (24 hours): skipped at the boundary, sent just inside it` — `linkLifetimes ['verification' => 86400]`; row A `createdAt now-86280` → `skipped`, `paramsJSON` null, `lastError === CUERCODE_EMAIL_QUEUE_NOTE_LINK_EXPIRED`; row B `now-86279` → sent; `expired` 1; only B was sent.
20. `expired reset link (60 minutes): skipped at the boundary, sent just inside it` — `password_reset` rows with `paramsJSON` `{"resetUrl":"https://cuercode.net/reset-password?token=R<id>"}` and subject `Reset your CueRCode password`; A at `now-3480` → skipped; B at `now-3479` → sent.
21. `expiry also covers an email waiting for a retry` — verification, attempts 2, `nextAttemptAt now+600`, `createdAt now-90000` → skipped.
22. `expiry never touches an email being sent, already sent, or given up` — `createdAt now-90000` rows: `sending` (`updatedAt now-60`), `sent`, and failed 5/5 → statuses unchanged.
23. `no link lifetime (receipt) means no expiry` — receipt, 10 days old, due → sent.
24. `with no lifetimes known nothing is skipped for age` — `linkLifetimes []`, verification row 2 days old → sent.
25. `the per-row check stops an expired link even without the clean-up step (lock bypassed)` — `useLock false`, verification `createdAt now-86400` → skipped through `markSkipped`; `expired` 1; 0 sends.

Batch, switched off (`sendEmails => false`):
26. `sendEmails=false tidies up but never claims or sends` — rows: sent 40 days old; one fresh due row; one verification row 2 days old → `purged` 1, `expired` 1, `claimCalls` 0, 0 sends, the fresh row still `queued`, the lock released, status `ok`.
27. `sendEmails=false with the lock held elsewhere is busy, never stalled` — `lockHolder 'other'` + a row due for 11 minutes → status `busy`.

Runner main (the existing `$eqrMain` helper):
28. `email switched off, quiet: the clean-up runs silently (exit 0)` — a sent row 40 days old is gone; nothing printed.
29. `email switched off, normal: says what was tidied` — out contains `switched off` and `Tidied up: deleted 1`.
30. `email switched off: a failing clean-up is reported on the error stream with exit 3, even when quiet` — `failOps['purgeFinished'] = true`.
31. `email on but settings incomplete: the clean-up still runs; exit 4` — the old sent row is gone; exit 4.
32. `sending run: an expired email is reported on the error stream even when quiet (exit 0)` — err contains `Skipped 1`.
33. `sending run: an email whose details were removed is reported on the error stream even when quiet`.
34. `--check shows the clean-up policy and the skipped count, and changes nothing`:
    - an old sent row (40 days) is still there afterwards;
    - out contains `Clean-up:`, `30 days`, `90 days`, `Skipped (not sent on purpose`, `Expired-link check: on`, `verification 24 hours` and `password_reset 60 minutes`.
35. `--check names an invalid retention setting but never its value` — settings with `'email_queue_retention_days' => 'retention-value-777'` → out contains `email_queue_retention_days`; nothing printed contains `retention-value-777`.

Source-text guards (Section 4; use the existing `$eqrFunctionBody` cutter):
36. The `settleEmailQueueSuccess` body contains ``\`paramsJSON\` = NULL``.
37. The `skipClaimedEmailQueueRow` body contains ``\`status\` = 'skipped'``, ``\`paramsJSON\` = NULL``, ``AND \`status\` = 'sending'`` and `emailQueueChangeOneRow(`.
38. The `skipExpiredEmailQueueRows` body contains ``\`status\` IN ('queued', 'failed')``, ``\`attempts\` < \`maxAttempts\` ``, ``\`createdAt\` <= DATE_SUB(NOW(), INTERVAL ? SECOND)``, ``\`paramsJSON\` = NULL`` and `LIMIT ?`, and does **not** contain `'sending'`.
39. The `clearGivenUpEmailQueueDetails` body contains ``\`updatedAt\` = \`updatedAt\` ``, ``\`attempts\` >= \`maxAttempts\` ``, ``\`paramsJSON\` IS NOT NULL`` and ``\`status\` = 'failed'``.
40. The `purgeFinishedEmailQueueRows` body contains ``DELETE FROM \`tblEmailQueue\` ``, ``\`status\` IN ('sent', 'skipped')``, ``\`createdAt\` < DATE_SUB(NOW(), INTERVAL ? DAY)`` and `LIMIT ?`, and contains **neither** `queued` nor `sending`.
41. The `purgeGivenUpEmailQueueRows` body contains ``DELETE FROM``, ``\`status\` = 'failed'`` and ``\`attempts\` >= \`maxAttempts\` ``, and contains neither `queued` nor `sending`.
42. The `loadClaimedEmailQueueRow` body contains ``TIMESTAMPDIFF(SECOND, \`createdAt\`, NOW()) AS \`ageSeconds\` ``.
43. The `runEmailQueueBatch` body:
    - the position of `runEmailQueueHousekeeping(` is after `if ($haveLock) {`, less than 400 characters later, and before `$gateway['findDue']`;
    - it contains `$gateway['markSkipped']`.
44. The `emailQueueLinkLifetimes` body contains `defined('CUERCODE_EMAIL_VERIFICATION_TTL_HOURS')` and `defined('CUERCODE_PASSWORD_RESET_TTL_MINUTES')`.
45. The `emailQueueSafeDetail` body contains `cleanLogText(`, and fn_email.php no longer contains the address pattern text `[A-Za-z0-9._%+\-]+@`. **One copy of the rule.**
46. `install.sql` no longer contains `NEVER stores rendered HTML or secrets`, and contains `one-time sign-up or password-reset link`. `seed_data.sql` contains `('email', 'email_queue_retention_days', '30', 'integer'` and `('email', 'email_queue_failed_retention_days', '90', 'integer'`.

### 6.2 NEW `tests/test_log_mask.php` (#126)
It sorts between `test_features.php` and `test_oauth.php`.
- Header in the house style: what it covers, what it does not, and why the logger checks run in **child processes** (§1.5).
- Every variable starts with `$lgm`.
- **The main process never defines** `dbQuery`/`getDB`/`getConfig`/`logError`/`logActivity`, and never loads `fn_error_handler.php` or `fn_activity_log.php`.

**In-process** (`requireFn('fn_log_mask.php'); requireFn('fn_email.php');`):
- `maskEmailAddresses`:
  - `'alice@example.com'` → `'[address]'`;
  - `'To: alice.o+tag@mail.example.co.uk | Subject: Hi'` → `'To: [address] | Subject: Hi'`;
  - `'two: a@b.com, c@d.org'` → `'two: [address], [address]'`;
  - `'no address here'` → unchanged;
  - `''` → `''`;
  - `"Access denied for user 'cuercode'@'localhost'"` → **unchanged**. This documents that a database user@host is not address-shaped; that line is covered in the docs, and the password never appears.
- `cleanLogText`:
  - the four `emailQueueSafeDetail` cases from `test_email_queue_runner.php:600-604`, with the same expected results;
  - the default limit: `str_repeat('x', 1500)` → length 1000, ending `...`;
  - a limit below 20 is raised to 20.
- `emailQueueSafeDetail is now a wrapper round cleanLogText (one copy of the rule)` — for 6 sample strings, `emailQueueSafeDetail($s) === cleanLogText($s, 200)` and `emailQueueSafeDetail($s, 500) === cleanLogText($s, 500)`.
- Source-text guards:
  - `fn_activity_log.php`: `substr_count($source, 'cleanLogText($detail, 1000)') === 2` (both fallbacks);
  - `fn_email.php`, `fn_activity_log.php` and `fn_error_handler.php` each contain `require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_log_mask.php';`;
  - in `bootstrap.php`, the position of `'fn_log_mask.php'` is before `'fn_error_handler.php'`, `'fn_activity_log.php'` and `'fn_email.php'`;
  - `fn_email.php`'s `logEmailAttempt` body contains `cleanLogText(`;
  - `fn_error_handler.php`'s `maskSensitiveForLog` body contains `maskEmailAddresses(` and `is_string($masked)`.

**Child processes.** Write a closure `$lgmRunChild(string $programBody): array` returning `['exit' => int, 'stdout' => string, 'log' => string]`:
- Create two temp files with `tempnam(sys_get_temp_dir(), 'cuercode_lgm_')`: one for the program (write `'<?php' . PHP_EOL . 'require ' . var_export(<repo tests/bootstrap.php path>, true) . ';' . PHP_EOL . $programBody`) and one for the log.
- Run `proc_open([PHP_BINARY, '-d', 'error_log=' . $lgmLogFile, '-d', 'log_errors=1', $lgmProgramFile], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes)`.
  - It is an **array command**, so the spaces and `&` in the repository path are passed safely without a shell.
  - Setting `error_log` explicitly means a developer's own php.ini cannot send the lines elsewhere.
- Read stdout and stderr fully, `proc_close()`, read the log file, then **unlink both temp files**.

Programs (each ends by printing `done`). Assert exit 0 and stdout containing `done` for each.
- **A. `activity-log fallback without a database hides the address`:**

  ```php
  requireFn('fn_activity_log.php');
  logActivity('email_failed', 'To: alice.example+tag@example.com | Subject: Verify your CueRCode email address | HTTP 400', 'email', null, ['to' => 'alice.example+tag@example.com']);
  ```
  The log contains `CueRCode Activity [email_failed]: To: [address] | Subject: Verify your CueRCode email address`, and contains neither `alice.example` nor `@example.com`.
- **B. `activity-log fallback after a failed insert hides addresses and SQL`.** In this CHILD process only, and say so in a comment:

  ```php
  requireFn('fn_error_handler.php');
  requireFn('fn_activity_log.php');
  function dbQuery(string $sql, string $types = '', array $params = []): \mysqli_stmt
  {
      throw new \RuntimeException("Duplicate entry 'bob@example.org' for key 'x' | SQL: INSERT INTO `tblActivityLog` (`activityType`) VALUES (?)");
  }
  logActivity('email_sent', 'To: carol@example.net | Subject: Reset your CueRCode password | Graph sendMail accepted (HTTP 202)', 'email');
  ```
  The log contains `CueRCode Activity Log FAILED [email_sent]`, `[address]` and `[SQL REDACTED]`, and contains none of `carol@`, `bob@` or `INSERT INTO`.
- **C. `logEmailAttempt fallback (no activity logger) hides the address`:** `requireFn('fn_email.php'); logEmailAttempt('email_failed', 'dave@example.com', 'Subject', 'HTTP 400 | graph_error_code: ErrorInvalidRecipients');` → the log contains `CueRCode Email [email_failed]: [address] | HTTP 400`, and not `dave@`.
- **D. `logError fallback (no database) hides the address`:** `requireFn('fn_error_handler.php'); logError('error', 'Registration insert failed (unexpected error)', 'Incorrect value for erin@example.com | SQL: SELECT 1');` → the log contains `CueRCode [error]:`, `[address]` and `[SQL REDACTED]`, and not `erin@`.
- **E. `maskSensitiveForLog hides addresses and still redacts SQL`:** `requireFn('fn_error_handler.php'); echo json_encode(maskSensitiveForLog('a frank@example.com b | SQL: SELECT 1')), PHP_EOL;` → stdout contains `a [address] b` and `[SQL REDACTED]`.

### 6.3 `tests/integration/email_queue_real_mysql.php` — new scenarios (real SQL)
- Header: `@version 1.1.0`. Update the scenario list.
- Add a helper `itQueueOne(string $address, string $templateKey, array $params): int` (like `itQueue`, one row, any template).
- Keep the safety rules: `cuercode_it_*` only; never `sendEmailNow()`; `.invalid` addresses.
- In **Scenario 0**, add these checks:
  - `emailQueueLinkLifetimes() === ['verification' => 86400, 'password_reset' => 3600]` under the **real** bootstrap (this proves `fn_auth.php`'s constants reach the runner);
  - `emailQueueHousekeepingOptions('getConfig')` gives 30/90 and no invalid settings (this proves the seed rows and the `integer` type cast).

Scenarios (set rows up with prepared `itExec()` UPDATEs, e.g. ``SET `createdAt` = DATE_SUB(NOW(), INTERVAL 24 HOUR)``):
- **9. Details cleared on send.**
  - Queue 1 row; run with `$itOkSender` → `sent`, and `paramsJSON` is `NULL` in the database.
  - Queue another; run with `$itBadRecipientSender` → `queued`, and `paramsJSON` still contains `token=IT`.
- **10. Expired links skipped.** Rows:
  - verification V1 created 24 HOUR ago;
  - V2 fresh;
  - reset R1 (`itQueueOne('it-03@it.cuercode.invalid', 'password_reset', ['resetUrl' => 'https://cuercode.invalid/reset-password?token=ITR1'])`) created 59 MINUTE ago;
  - R2 fresh;
  - R3 created **57 MINUTE** ago (3 minutes left, more than the margin).

  Run with a counting ok sender. Expect: V1 and R1 `skipped`, with `paramsJSON` NULL and `lastError` starting `Not sent: its one-time link had expired`; V2, R2 and R3 `sent`; `result['expired'] === 2`; 3 sends.
- **10b. Per-row check on real SQL.**
  - Verification row created 24 HOUR ago; run with `['useLock' => false]` (so no clean-up step).
  - Expect: `skipped` via the re-read's `ageSeconds`; `expired` 1; 0 sends.
- **11. Given-up details cleared after 24 hours; updatedAt kept.**
  - Two rows set to `status='failed', attempts=5`. Row 1: `createdAt` and `updatedAt` 25 HOUR ago. Row 2: both 23 HOUR ago.
  - Run. Expect: row 1 `paramsJSON` NULL, and `TIMESTAMPDIFF(HOUR, updatedAt, NOW()) >= 24` (the given-up time was kept); row 2 `paramsJSON` NOT NULL; `detailsCleared` 1.
- **12. Old records deleted (real DELETE).** Rows:
  - sent 31 DAY;
  - sent 29 DAY;
  - skipped 31 DAY;
  - failed 5/5 31 DAY;
  - failed 5/5 91 DAY;
  - a **receipt** row (`itQueueOne(..., 'receipt', ['orderNumber' => 'IT-1', 'amount' => '1.00'])`) left `queued`, 100 DAY old, `nextAttemptAt` +1 DAY;
  - a receipt row `failed` with attempts 2, 100 DAY, `nextAttemptAt` +1 DAY.

  Run. Expect gone: sent 31, skipped 31, failed 91 (`purged === 3`). Expect kept: sent 29, failed 31, the queued receipt, and the retryable failed receipt.
- **13. Cleared details are never sent.** Queue 1; set `paramsJSON = NULL`; run → `skipped`, `lastError` starts `Not sent: its stored details`, 0 sends.
- **14. Switched off: the clean-up still runs, nothing is sent.**
  - `email_enabled` is seeded 0. Rows: one sent 31 DAY old, one fresh queued.
  - Build `$itEnvironment = emailQueueProductionEnvironment();`, then **replace `sender` with a counting stand-in** (never `sendEmailNow`) and `out`/`err` with collectors.
  - Call `emailQueueRunnerMain(parseEmailQueueRunnerArgs(['process_email_queue.php']), $itEnvironment)`.
  - Expect: exit 0; the old row gone; the fresh row still `queued`; 0 sends; out contains `switched off` and `Tidied up: deleted 1`.

Existing scenarios 1-8 stay as they are. The clean-up does nothing to their rows (they are fresh), and scenarios 2 and 3's children run with the lock on and off as before. Clean-up per scenario is still `itClear()`.

---

## 7. Documentation (the builder does these; line numbers are at `a266854`, so find each place by the quoted text)

1. **`DEV_NOTES.md`**, section "### Email Subsystem (Microsoft Graph) - Epic E8, #29" (`:219-310`):
   - **Step 7, the fallback bullet** (`:249`): add one sentence. The fallback also runs the clean-up (under the same lock), but only when sending is on, so a host with no cron **and** email switched off gets no clean-up.
   - **"Queue + retry (#112)" list** (`:251-260`): add two bullets:
     - "**Skips expired links (#123)**": waiting emails whose link has expired, or has under 2 minutes left, are marked `skipped`, not sent. This is measured on the database's clock from `createdAt`, against `fn_auth.php`'s lifetimes (24 h verification, 60 min reset). It is done in bulk before sending, with a per-email check as a backstop.
     - "**Keeps only what it needs (#122)**": the details (which can include the one-time link) are cleared when an email is sent or skipped, and 24 hours after it is given up. Old records are deleted. All of this happens under the lock, before sending.
   - **Exit-code table** (`:276-284`):
     - row 0 → add "(the clean-up still runs while email is switched off)";
     - row 3 → "Database problem (cannot connect, queue table unreadable, lock error), or the clean-up of old email records failed (sending is not affected)."
   - **"What it prints"** (`:286`):
     - add that, even with `--quiet`, it also reports emails **skipped because their link expired** or **whose details were already removed** (people did not get an email they asked for);
     - replace "(2) if writing the *activity log* fails mid-run, the shared activity logger's fallback line includes the recipient's **email address and subject** (tracked in #126)" with "(2) if writing the activity log fails mid-run, the shared activity logger's fallback line includes the subject; **email addresses are hidden** (`[address]`) since #126";
     - keep (1), the database user and host.
   - **"What it cannot do"** (`:288-294`):
     - **replace** the last two bullets ("It does not check whether a link ... has expired" and "It does not delete old rows or clear the stored template parameters") with:
       - a given-up email keeps its link for 24 hours (deliberate, so it can be re-sent);
       - the clean-up needs the queue lock, so it runs at most once a minute and at most 500 rows per step per run;
       - it does not touch the activity log, which still keeps addresses (follow-up);
       - the live table's column comments keep their old wording on existing installs until #113.
   - **Re-send runbook** (`:296-304`): add after the SQL: "Since #122/#123: a re-queued email whose link has expired is skipped automatically (not sent), and a given-up email's details are removed 24 hours after it was queued, so re-queuing anything older does nothing useful — the person must ask for a new link."
   - **New sub-heading after the runbook: "#### Email queue clean-up and privacy (#122, #123)".** Plain English, covering:
     - the table from §2.1 (kept / cleared / deleted, and when);
     - why `createdAt`;
     - that nothing waiting or in flight is ever deleted;
     - that the clean-up only runs under the lock, before sending, and also while sending is switched off;
     - what "skipped" means, and the three `lastError` texts;
     - the two settings, their defaults, the 1-3650 rule and what `--check` shows;
     - **for existing installs** (no upgrade script, #113): the defaults apply without doing anything. To change them, add the rows first. Give this SQL, which is the same as the seed rows:

       ```sql
       INSERT INTO tblSettings (settingGroup, settingKey, settingValue, settingType, isSensitive, isEditable, description) VALUES
       ('email', 'email_queue_retention_days', '30', 'integer', 0, 1, '<same description as seed_data.sql>'),
       ('email', 'email_queue_failed_retention_days', '90', 'integer', 0, 1, '<same description as seed_data.sql>');
       ```
       A "Duplicate entry" error just means the row is already there. Then change a value with `UPDATE tblSettings SET settingValue = '60' WHERE settingKey = 'email_queue_retention_days';`.
     - a one-off SQL for any rows **sent before this change** that still hold details. Normally there are none, because #112 and this change ship together:

       ```sql
       UPDATE tblEmailQueue SET paramsJSON = NULL, updatedAt = updatedAt WHERE status IN ('sent', 'skipped') AND paramsJSON IS NOT NULL;
       ```
     - the live column comment: unchanged on existing installs, cosmetic only, tracked for #113;
     - the limits: the ASCII-only address masking, and the activity log (follow-up).
   - **"Testing"** (`:306`): mention `tests/test_log_mask.php` and the new integration scenarios.
2. **`SECURITY.md`**:
   - **§4 "Cryptography, secrets & session management"**:
     - **Files**: add `_functions/fn_log_mask.php` and `_functions/fn_activity_log.php`;
     - **Audited**: add "error-log fallbacks (activity log, `logError()`, `logEmailAttempt()`) mask email addresses and control characters through one shared helper (#126)".
   - **"## 2026-09-25 — email queue runner (#112)"** (`:277-290`):
     - change "**Known exception (open, #126):**" to "**Known exception (fixed 2026-09-25, #126):**", and say the fallback now masks every address-shaped piece of text. Database driver messages can still include the database user name and host, never the password;
     - change the "**New open items ...**" heading to say both items are **closed by #122** (keep the original text as history);
     - append to (a): "→ **Closed**: the details are cleared when the email is sent or skipped, and 24 hours after it is given up (see the next section)";
     - append to (b): "→ **Closed**: finished rows are deleted after 30/90 days (settings)".
   - **New section after it: "## 2026-09-25 — email queue privacy clean-up (#122, #123, #126)":**
     - **What changed and what was checked:** clearing in the same statement as "sent"; the guard that never sends a cleared row; the purge touches finished rows only (the source-text tests prove the DELETE statements never mention `queued`/`sending`); clean-up only under the lock; expiry measured on the database clock; masking in all four error-log fallbacks; every new query prepared, with no new text joined into SQL. Scan evidence: the test counts and lint results the builder measures, and the real-database results (which servers).
     - **Still open:**
       - (c) `tblActivityLog` keeps email events with the full recipient address (`activityDetail` `To: ...` and `additionalData.to`) with no time limit, and account erasure (#37) touches neither those rows nor `tblEmailQueue` → follow-up issue (the lead fills in the number);
       - (d) a given-up email keeps its live link for up to 24 hours, deliberately (owner decision 2);
       - (e) existing installs keep the old, wrong `paramsJSON` column comment until #113;
       - (f) addresses with non-English letters before the `@` may be only partly masked in logs.
3. **`CHANGELOG.md`**: a new entry at the **top** of `## [Unreleased]`, above the #127 entry: "### Fixed - Email privacy: one-time links are removed after sending, old email records are deleted, expired links are not sent, and addresses stay out of the error log (2026-09-25, #122, #123, #126)". Plain-English bullets:
   - links removed after sending (given-up emails keep them 24 h, and why);
   - old records deleted (30/90 days, two settings);
   - a cleared email can never go out with an empty link;
   - expired links skipped (2-minute margin, the database clock);
   - the clean-up runs even while email is off, only under the lock, and a failure is reported with exit code 3 but never stops sending;
   - no addresses in the error log (new `fn_log_mask.php`, used by all four fallbacks);
   - tests (the numbers) and the real-database check (which servers);
   - **existing installs**: no database change needed; the defaults apply; DEV_NOTES has the SQL to add the settings; the live column comment keeps its old wording until #113;
   - "Review: ..." — a placeholder for the lead.
4. **`README.md`**:
   - **"⏱️ The email queue job (DreamHost cron)"** (`:192-203`):
     - add a bullet: "It also tidies up: one-time links are removed from the database as soon as an email is sent; emails whose link has expired are skipped rather than sent; old email records are deleted after 30 days (given-up ones after 90). This runs even while email sending is switched off.";
     - in the exit-codes bullet, extend `3` to "database problem, or the clean-up failed (sending not affected)".
   - **"🧪 Testing"** (`:248`): the new totals ("N assertions across 25 test files"), exactly as measured.
5. **`tests/README.md`**:
   - heading → "## Current suite (2026-09-25, #112, #122/#123/#126)";
   - update the `test_email_queue_runner.php` count; add a `test_log_mask.php` row; update the total;
   - "runs eight scenarios" (or similar) → the new count "runs fifteen scenarios" (1-14 plus 10b, after the set-up checks) — confirm the number from the script's own output;
   - after the Docker block, add a note: "mysql:8.4 also works — leave off `--default-authentication-plugin`, which 8.4 no longer accepts".
6. **`PROJECT_STATUS.md`**:
   - line 16 (the email bullet): append "Since #122/#123/#126 (2026-09-25) the queue also removes one-time links after sending, deletes old records (30/90 days), skips expired links and keeps addresses out of the error log.";
   - under "Known gaps" add: "- The activity log keeps email events, with recipients' addresses, with no time limit, and deleting an account does not touch them (follow-up #<new>)."
7. **`FEATURES.md`**, the FG-040 row: append "(2026-09-25, #122/#123: also clears one-time links after sending, skips expired links, deletes old records)".
8. **`LAUNCH_DECISIONS.md`**, "## D. Legal": add "- [ ] **Confirm how long email records are kept** (#122 — built with 30 days for sent/skipped, 90 for given up; one-time links on given-up emails kept 24 h for re-sending), then put the confirmed periods into `privacy.php` §7 with legal review (#38)."
9. **`web/CueRCode.net/README.md`** (the scheduled-jobs note, `:40-44`): add "It also deletes old email records and removes one-time links after sending (#122)."
10. **`install.sql` / `seed_data.sql` comments**: as in §4.7 and §4.8.
11. **No change** to `openapi.json`, the developer portal, `privacy.php` (legal text, #38 — the owner and counsel), `.github/SECURITY.md`, or `web/CueRCode.net/CHANGELOG.md` (frozen). The API is unchanged. Say so in the report.
12. **The lead, not the builder:** `.claude/HANDOFF.md`, `.claude/memory/*`, `.OpenAI/*` (and its sync), and the GitHub issues.

---

## 8. Verification (the verifier runs all of these and reads each exit code directly — never pipe a check into `grep`/`tail` and then trust `&&`)

Set these once, and quote them everywhere: `REPO="<path to the CueRCode checkout>"`, and `SP="<scratchpad>"`.

1. **Scope.**
   - `git -C "$REPO" status --short` and `git -C "$REPO" diff --stat`.
   - Only these files changed: `fn_email.php`, `fn_activity_log.php`, `fn_error_handler.php`, `fn_auth.php` (comments only), `bootstrap.php`, `install.sql` (comments only), `seed_data.sql`, `tests/test_email_queue_runner.php`, `tests/integration/email_queue_real_mysql.php`, and the docs in §7 (optionally the cron script's header).
   - New files: `web/CueRCode.net/_functions/fn_log_mask.php` and `tests/test_log_mask.php`.
   - `.github/workflows/deploy.yml` may show as changed by **another agent**. Leave it alone.
   - `git -C "$REPO" diff -- web/CueRCode.net/_functions/fn_auth.php web/CueRCode.net/_includes/sql/install.sql` must show comment text only.
2. **Syntax.** `php -l` on each changed or new PHP file, reading each exit code. Then the same under PHP 8.4: `docker run --rm -v "$REPO":/app -w /app g2ml-php:8.4-mysqli php -l <file>`.
3. **Full suite.**
   - `php "$REPO/tests/run.php" > "$SP/suite.txt" 2>&1; echo "exit=$?"` → **exit=0**.
   - The total equals 2466 plus the new assertions, 0 failed, 25 files.
   - `test_email.php` still shows 69 passed. The existing `test_email_queue_runner.php` assertions all pass **unedited** (check with `git diff` that no existing `assert` line changed).
4. **Filter independence.**
   - `php "$REPO/tests/run.php" --filter=email_queue_runner; echo "exit=$?"` → exit 0. This proves the file loads `fn_auth.php` itself.
   - Also `--filter=log_mask` → exit 0.
5. **PHP 8.4.** `docker run --rm -v "$REPO":/app -w /app g2ml-php:8.4-mysqli php tests/run.php > "$SP/suite84.txt" 2>&1; echo "exit=$?"` → exit 0. If the image lacks an extension, say so; do not claim a pass.
6. **House-style scan.**
   - Run `git -C "$REPO" diff -U0 -- web | grep '^+' | grep -nE '\?\?|\?:|\bfn\s*\(|\bmatch\s*\(|curl_close\(|\bmail\('; echo "grep exit=$?"`. Expect **grep exit=1**, meaning no match.
   - Then the same pattern directly on the two new files (they are untracked, so not in `git diff`): `grep -nE '<same pattern>' "$REPO/web/CueRCode.net/_functions/fn_log_mask.php"; echo "grep exit=$?"` → 1.
   - Read any `? ... :` by eye.
7. **Literal-count guards.**
   - `grep -c 'ALTER TABLE \`' "$REPO/web/CueRCode.net/_includes/sql/install.sql"` must print **2**;
   - `grep -c 'CREATE TABLE IF NOT EXISTS' ...` must print **29**. (`grep -c` counts lines, which matches here; the test's `substr_count` is the real check and runs in step 3.)
   - `grep -c 'toAddress\|paramsJSON' "$REPO/web/CueRCode.net/_includes/cron/process_email_queue.php"` must print **0**.
8. **Runner by hand.** First confirm that neither `web/CueRCode.net/_auth_keys/auth_creds.php` nor `web/_auth_keys/auth_creds.php` exists. If either exists, skip 8c, so nothing can reach a real database.
   - a. `php web/CueRCode.net/_includes/cron/process_email_queue.php --help; echo "exit=$?"` → the help mentions the clean-up; exit=0.
   - b. `... --limit=0; echo "exit=$?"` → exit=2.
   - c. The #112 "unreachable database" wrapper (`$SP/no_db_wrapper.php`, as in the #112 plan §7 step 5d) → **exit=3**; stderr says "could not reach the database"; no `<html`. This proves the clean-up changes nothing before the database check.
   - d. `php-cgi -f web/CueRCode.net/_includes/cron/process_email_queue.php; echo "exit=$?"` → the output contains `Access denied.`.
9. **Real databases (strongly recommended; the only proof of the real DELETE/UPDATE and of the seed rows).**
   - Named throwaway containers, removed with their data. Run it once per server. `mysql:8.0` is not on this machine; use **`mysql:8.4` without `--default-authentication-plugin`**, plus **`mariadb:10.11`**, and optionally `mariadb:10.6`.

   ```bash
   BEFORE=$(docker volume ls -q -f dangling=true | wc -l | tr -d ' ')
   docker run --rm -d --name p122-emailq-mysql84 -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
     -e MYSQL_DATABASE=cuercode_it_p122 -p 127.0.0.1:33122:3306 mysql:8.4
   CUERCODE_IT_DB_HOST=127.0.0.1 CUERCODE_IT_DB_PORT=33122 CUERCODE_IT_DB_NAME=cuercode_it_p122 \
   CUERCODE_IT_DB_USER=root CUERCODE_IT_DB_PASS= \
     php "$REPO/tests/integration/email_queue_real_mysql.php" --load-schema > "$SP/it_mysql84.txt" 2>&1; echo "exit=$?"
   docker stop p122-emailq-mysql84
   # MariaDB: --name p122-emailq-mariadb1011 -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 -e MARIADB_DATABASE=cuercode_it_p122 -p 127.0.0.1:33123:3306 mariadb:10.11
   docker ps -a --filter name=p122-emailq --format '{{.Names}}'    # must print nothing (--rm removal can take a second; re-check)
   AFTER=$(docker volume ls -q -f dangling=true | wc -l | tr -d ' '); echo "dangling before=$BEFORE after=$AFTER"   # must be equal
   ```
   - Expect every scenario (0-14 and 10b) to PASS, with exit=0.
   - The script waits up to 120 seconds for the database by itself. If you run it in the background, set a watchdog (`~/.claude/bin/watchdog.sh pid <pid>`), as the device rules require.
10. **Security read of the diff.**
    - Every new query goes through `dbQuery` with `?`; no new text is joined into SQL.
    - The two DELETEs only match finished rows.
    - Nothing prints or logs a body, `paramsJSON`, a link, a token, a setting value or a full address.
    - All four fallbacks mask.
    - The new file has the direct-access guard and sits under `_functions/` (deny-all).
11. **Docs.**
    - `grep -n "NEVER stores rendered HTML or secrets" "$REPO/web/CueRCode.net/_includes/sql/install.sql"` → nothing.
    - `grep -n "does not check whether a link\|does not delete old rows" "$REPO/DEV_NOTES.md"` → nothing.
    - `grep -n "80 default settings" "$REPO/web/CueRCode.net/_includes/sql/seed_data.sql"` → 1 hit.
    - The test totals in `README.md` and `tests/README.md` match step 3's real numbers.
    - `CHANGELOG.md`'s top Unreleased entry is this one.
    - `SECURITY.md` (a)/(b) are marked closed.
12. **Report honestly.** Say which steps ran, which were skipped and why. State that MySQL 5.7 and MariaDB 10.3 were not tried, and that nothing was run against the live server.

---

## 9. Risks, and what the build must NOT do

**Risks** (state them in the report and the docs):
- **Deleting data cannot be undone.** A wrong purge condition could delete emails still waiting to be sent. Mitigations:
  - finished-only conditions;
  - source-text tests proving neither DELETE mentions `queued`/`sending`;
  - stand-in tests and real-database tests;
  - a 500-row cap per statement;
  - clean-up only under the lock.
- The retention periods are a policy choice waiting for the owner and legal review (owner decision 1, #38).
- A given-up email keeps a live link for up to 24 hours (owner decision 2).
- `LIMIT ?` in UPDATE/DELETE is not verified on MySQL 5.7 or MariaDB 10.3.
- The clean-up's range statements briefly lock index ranges. A sign-up queuing an email at that moment may wait a few milliseconds (no deadlock: see §5).
- With the page-visit fallback switched on, each page visit runs a few more cheap, index-driven statements.
- Existing installs keep the wrong column comment until #113.
- The activity log still keeps addresses with no time limit (follow-up).
- The ASCII-only address pattern may leave non-English letters before the `@` partly visible in logs.
- New pattern: three `_functions` files now `require_once` a sibling. Harmless, but new.

**Must NOT:**
- Must not change `processEmailQueue()`'s name, parameters or return shape, or its early return without a database.
- Must not edit or delete any existing assertion in `tests/test_email.php` or `tests/test_email_queue_runner.php` (only additions, the stand-in operation changes in §6.1, and the header).
- Must not define `dbQuery`/`getDB`/`getConfig`/`logError`/`logActivity` in the main test process, or load `fn_error_handler.php`/`fn_activity_log.php` there. Child processes may, with a comment.
- Must not run the clean-up without the named lock, or let a clean-up failure stop sending.
- Must not clear the details of a row that can still be sent (queued, retrying, released, recovered), or of a given-up row younger than 24 hours.
- Must not let any statement other than O change a waiting row, and must not let any clean-up statement touch a `sending` row.
- Must not use PHP's `time()` for the expiry check (clock mismatch, §1.2).
- Must not write ``ALTER TABLE ` `` or `CREATE TABLE IF NOT EXISTS` in new `install.sql` text, and must not add any ALTER, migration or schema change.
- Must not write `toAddress`/`paramsJSON` in the cron script.
- Must not use shorthand (`??`, `?:`, ternary, `fn`, first-class callables, `match`), or PHP-8.5-only features.
- Must not join any new text into SQL.
- Must not touch `.github/workflows/deploy.yml`, `privacy.php`, `openapi.json` or `.github/SECURITY.md`.
- Must not start the real CLI in tests except with `--help` or an invalid option. Must not point the integration script at a database not named `cuercode_it_*`. Must never call `sendEmailNow()` from any test.
- Must not print or log bodies, `paramsJSON`, links, tokens, secrets, setting values or full addresses.
- Must not commit, push, or open issues/PRs.
- Must not leave Docker containers or data volumes behind.

---

## 10. For the lead (after the build)

- **Before the build:**
  - Ask owner decisions 1 and 2 (§3) up front, in plain words.
  - Save this plan into the repository as `.claude/plans/122-123-126-email-privacy.md` (as was done for #112), so it survives a session ending.
  - Update `.claude/HANDOFF.md` §0: rows 7g (#122) and 7i (#126); add #123.
  - Note in the handoff that 7i's "reuse `emailQueueSafeDetail()`" idea was replaced by a shared file, and why (§2.4).
- **Order:** Opus builds → a separate Opus verifies (§8) → Codex reviews (`codex review --uncommitted -c model="gpt-6-astra"`) → fix → review again until a round is clean.
- **Commit:** **one** commit, because `fn_email.php` carries changes for all three issues and interactive staging is not available. For example: `fix(email): clear one-time links after sending, delete old queue records, skip expired links, keep addresses out of the error log (#122, #123, #126)`. Push to `work-in-progress`.
- **Issues:**
  - close #122, #123 and #126 with the commit (#123's text says `verify_email`; the key is `verification`);
  - comment on **#113**: the upgrade script should also update `tblEmailQueue`'s `paramsJSON`/`status`/`createdAt` column comments and insert the two new settings rows;
  - comment on **#38**: the retention periods now in code are 30/90 days; `privacy.php` §7 needs the confirmed figures.
- **Follow-up issues to open** (suggestions, not built):
  1. **[Accounts] "Send me a new verification link"**, and let an unverified sign-up try again. Today a person whose verification email is lost or expires is locked out for good: `registerUser()` returns the generic success and sends nothing for an existing `pending` address (`fn_auth.php:353-359`). The #123 skip does not change that outcome. **High user impact.** Related: #114/#52.
  2. **[Privacy] Activity-log retention.** `tblActivityLog` keeps email events with full addresses (`fn_email.php:393-402`) with no time limit, and account erasure (#37) touches neither those rows nor `tblEmailQueue`. Related: #38.
  3. **[Auth][Bug, latent] Link expiry uses PHP's clock against UTC database times.** `fn_auth.php:523-527` and `:1275-1279` use `strtotime()` + `time()`; the database session is UTC (`db_connect.php:191-193`). If `site_timezone` is ever not UTC, links expire at the wrong time (in summer, Europe/London makes a 60-minute reset link dead on arrival). The fix is to compare in SQL (`expiresAt > NOW()`).
  4. A note for the invitations work (no new issue needed): when invitations are built, add their lifetime to `emailQueueLinkLifetimes()`.
- **Progress table / handoff:** record that the planner verified the SQL behaviour on MariaDB 10.6 and MySQL 8.4 (containers removed, 0 leftover volumes), and that MySQL 5.7 and MariaDB 10.3 were not tried.
