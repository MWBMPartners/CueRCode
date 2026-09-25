You are reviewing code that Claude Code wrote for the CueRCode repository (PHP 8.4+/MySQL QR-code web service on DreamHost shared hosting). You did not write any of it. Other Claude agents have already reviewed it; look for what they MISSED. READ-ONLY: do not edit any files.

YOUR ALLOWANCE IS SMALL. Work economically:
- Do NOT print whole files or whole diffs. Read only the functions named below, with `grep -n "^function NAME" FILE` then `sed -n 'START,ENDp' FILE`.
- **Write each finding down the moment you are sure of it** (severity, file:line, what's wrong, a concrete scenario, the fix), then carry on. If you run out of allowance, the findings you already wrote are still useful.

SCOPE (chunk 1 of 3): the email queue runner, branch `work-in-progress`.
A DreamHost cron job runs `web/CueRCode.net/_includes/cron/process_email_queue.php` every minute. It sends queued emails (sign-up verification, password reset) through Microsoft Graph. All logic is in `web/CueRCode.net/_functions/fn_email.php`.

Design, in brief:
- One UPDATE claims a row, re-checking in the same statement that the status is queued/failed, that it is due now, and that it has attempts left. The row is then re-read.
- Every final UPDATE requires `status='sending'`.
- A per-database MySQL `GET_LOCK` (no waiting) means only one runner works at a time.
- Rows left in `sending` for more than 10 minutes (judged by `updatedAt`) are recovered, and that counts as one used attempt.
- "Blocked" problems (sending switched off, no Graph token, Graph 401/403/404) put the row back without using an attempt, and the run stops with exit code 4.
- Retention (#122): `paramsJSON` holds the one-time links. It is cleared in the same UPDATE that marks a row sent or skipped. Given-up rows keep it for 24 hours and are then cleared. Finished rows are purged after 30 days (sent or skipped) and 90 days (given up). All of this happens only while the lock is held, before any sending, and also when sending is switched off.
- A claimed row with missing details, or a missing link, is skipped and never sent.
- Expiry (#123): an email whose link has less than 2 minutes left is skipped. Its age is measured with the database clock.
- Masking (#126): `web/CueRCode.net/_functions/fn_log_mask.php` masks addresses in every fallback that writes to PHP's error log.

READ THESE FUNCTIONS in fn_email.php: claimEmailQueueRow, loadClaimedEmailQueueRow, settleEmailQueueSuccess, settleEmailQueueRetry, settleEmailQueueGaveUp, releaseEmailQueueClaim, settleEmailQueueFailure, findStuckEmailQueueRows, recoverStuckEmailQueueRow, findDueEmailQueueIDs, acquireEmailQueueRunLock, releaseEmailQueueRunLock, emailQueueChangeOneRow, emailQueueChangeRows, skipClaimedEmailQueueRow, skipExpiredEmailQueueRows, clearGivenUpEmailQueueDetails, purgeFinishedEmailQueueRows, purgeGivenUpEmailQueueRows, runEmailQueueHousekeeping, runEmailQueueBatch (long — read the lock/claim/send/settle parts), emailQueueLinkMaxAgeSeconds, emailQueueLinkHasExpired, parseEmailQueueRetentionDays, decideEmailQueueFailure, classifyEmailSendError. Then fn_log_mask.php (short), and `maybeProcessEmailQueueOnShutdown` + `processEmailQueue`, and the `bootstrap.php` lines that call it (`grep -n maybeProcessEmailQueueOnShutdown web/CueRCode.net/_includes/bootstrap.php`).

LOOK FOR, most important first:
1. The same email sent twice, or one lost. Examples: a stale read; a final UPDATE that isn't guarded; the lock not released on some path; stuck-row recovery racing a live sender.
2. **Data loss.** Could a purge or a `paramsJSON = NULL` ever touch a row that still needs sending (queued, sending, or failed with attempts left)?
3. **Wrong sends.** Could an email go out with an empty link or an expired link?
4. **Time mistakes.** PHP's clock versus the database clock, or UTC versus the site time zone.
5. **SQL injection.** Every query should be prepared through `dbQuery()` with `?` placeholders.
6. **Personal data leaking.** Any address, subject, body, link or token reaching output or the logs.
7. **PHP 8.5-only syntax.** CI runs PHP 8.4.

Ignore style and wording.

OUTPUT: the numbered findings (only real defects), or "NO FINDINGS". Finish with one line listing the functions you actually read.
