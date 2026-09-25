<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Tests for the EMAIL QUEUE RUNNER (#112)
 * ==============================================================================
 *
 * Path: tests/test_email_queue_runner.php
 *
 * Covers the queue runner added to fn_email.php for #112 ("queued emails are
 * never sent, so sign-ups can never be verified"):
 *
 *   - The pure helpers: decideEmailQueueFailure(), classifyEmailSendError(),
 *     emailQueueSafeDetail(), checkEmailQueueRunnerSettings(),
 *     parseEmailQueueRunnerArgs(), emailQueueRunnerUsage(),
 *     emailQueueRunnerExitCode(), formatEmailQueueRunReport() and
 *     validateEmailQueueGateway().
 *   - runEmailQueueBatch() - the claim / send / record logic - driven through
 *     an IN-MEMORY STAND-IN for tblEmailQueue (the "fake store" below),
 *     including two runners interleaved in the middle of a send, with and
 *     without the named lock, to prove no email is ever sent twice.
 *   - emailQueueRunnerMain() - the command-line runner's flow, output and exit
 *     codes - with stand-ins for the database check, settings and output.
 *   - Source-text regression guards on the real SQL and on the runner file.
 *   - The real runner file started as a child process, with --help and bad
 *     options ONLY.
 *   - #122 / #123 (Section 6): the clean-up and expired-link helpers
 *     (parseEmailQueueRetentionDays(), emailQueueHousekeepingOptions(),
 *     emailQueueLinkLifetimes(), normaliseEmailQueueLinkLifetimes(),
 *     emailQueueLinkMaxAgeSeconds(), emailQueueLinkHasExpired(),
 *     emailQueueRequiredLinkParam(), formatEmailQueueTidyLines()); the batch
 *     clearing one-time links on send and skip, keeping them while an email
 *     can still be sent, the 24-hour window for given-up emails, deleting old
 *     finished records (and never waiting or in-flight ones), skipping
 *     expired links in bulk and per email, the "never send a cleared email"
 *     guard, tidy-only runs while email is switched off; the runner's
 *     reporting of all of that; and source-text guards on the new SQL.
 *
 * 🧰 How the stand-in works: a \stdClass "store" holds the rows (keyed by
 *    emailID), a simulated database clock (`now`, whole seconds), a simulated
 *    run clock for the time budget (`runClock`), who holds the lock, and a log
 *    of every send. The gateway built by $eqrMakeGateway() gives the batch the
 *    same 19 operations as emailQueueDatabaseGateway() (14 from #112, 5 from
 *    #122/#123), each written to mirror its real SQL WHERE clause exactly. The
 *    sender records each send and returns scripted results, and can run a
 *    second runner in the middle of a send ("onSend") to interleave two
 *    runners deterministically.
 *
 * ⚠️ NOT covered here (needs a real database): the real SQL and the real named
 *    lock. Those are covered by the optional
 *    tests/integration/email_queue_real_mysql.php (see tests/README.md).
 *
 * ⚠️ House rules for this file (it runs in the SAME global scope as
 *    tests/run.php and every other test file):
 *    - every variable is prefixed $eqr, so nothing clashes with the runner's
 *      own variables ($testFile, $countBefore, $useColor, ...);
 *    - it never defines dbQuery(), getDB(), getConfig(), logError() or
 *      logActivity() - five other test files assert those do NOT exist;
 *    - it never starts the real runner except with --help or an invalid
 *      option. A plain run on a machine that has a real
 *      _auth_keys/auth_creds.php could reach a real database and SEND REAL
 *      EMAIL.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.1.0
 * @since       2026-09-25 (#112)
 * @see         tests/bootstrap.php  - requireFn() loader (no DB connection)
 * @see         tests/lib/assert.php - the assert* functions used below
 * @see         web/CueRCode.net/_functions/fn_email.php
 * @see         web/CueRCode.net/_includes/cron/process_email_queue.php
 */

requireFn('fn_email.php');

// ⏳ #123: fn_auth.php holds the real link-lifetime constants
//    (CUERCODE_EMAIL_VERIFICATION_TTL_HOURS, CUERCODE_PASSWORD_RESET_TTL_MINUTES)
//    that emailQueueLinkLifetimes() reads. Loading it here gives this file the
//    real lifetimes whatever --filter is used. It is safe: fn_auth.php defines
//    none of the functions five other test files assert do not exist, and
//    test_auth.php already loads it earlier in a full run (require_once makes
//    the second load a no-op).
requireFn('fn_auth.php');

// ==============================================================================
// 🧰 Section 0: the in-memory stand-in for tblEmailQueue
// ==============================================================================

/**
 * A fresh, empty store. `now` is an arbitrary fixed moment so the tests never
 * depend on the real clock.
 */
$eqrNewStore = static function (): \stdClass {
    $eqrStore                 = new \stdClass();
    $eqrStore->rows           = [];
    $eqrStore->now            = 1800000000;
    $eqrStore->runClock       = 0.0;
    $eqrStore->lockHolder     = null;
    $eqrStore->sends          = [];
    $eqrStore->sendScript     = [];
    $eqrStore->onSend         = null;
    $eqrStore->secondsPerSend = 0;
    $eqrStore->failOps        = [];
    $eqrStore->claimCalls     = 0;
    $eqrStore->releaseCalls   = 0;
    $eqrStore->nested         = null;

    return $eqrStore;
};

/**
 * Add one row with the same defaults queueEmail() would give it, plus any
 * overrides.
 */
$eqrAddRow = static function (\stdClass $eqrStore, int $eqrId, array $eqrOverrides = []): void {
    $eqrRow = [
        'emailID'       => $eqrId,
        'toAddress'     => 'user' . $eqrId . '@example.com',
        'toName'        => 'User ' . $eqrId,
        'templateKey'   => 'verification',
        'paramsJSON'    => json_encode(['verificationUrl' => 'https://cuercode.net/verify-email?token=T' . $eqrId]),
        'subject'       => 'Verify your CueRCode email address',
        'status'        => 'queued',
        'attempts'      => 0,
        'maxAttempts'   => 5,
        'lastError'     => null,
        'nextAttemptAt' => $eqrStore->now,
        'createdAt'     => $eqrStore->now,
        'updatedAt'     => $eqrStore->now,
        'sentAt'        => null,
    ];

    foreach ($eqrOverrides as $eqrKey => $eqrValue) {
        $eqrRow[$eqrKey] = $eqrValue;
    }

    $eqrStore->rows[$eqrId] = $eqrRow;
};

/**
 * "Is this row due?" - mirrors the SQL used by findDue/claim/countDue:
 * status IN ('queued','failed') AND (nextAttemptAt IS NULL OR nextAttemptAt <= NOW())
 * AND attempts < maxAttempts.
 */
$eqrIsDue = static function (array $eqrRow, int $eqrNow): bool {
    if ($eqrRow['status'] !== 'queued' && $eqrRow['status'] !== 'failed') {
        return false;
    }

    if ($eqrRow['nextAttemptAt'] !== null && $eqrRow['nextAttemptAt'] > $eqrNow) {
        return false;
    }

    return $eqrRow['attempts'] < $eqrRow['maxAttempts'];
};

/**
 * Simulated database failure switch: failOps[op] = true makes that operation
 * return its failure value; failOps[op] = 'throw' makes it throw.
 */
$eqrShouldFail = static function (\stdClass $eqrStore, string $eqrOp): bool {
    if (!isset($eqrStore->failOps[$eqrOp])) {
        return false;
    }

    if ($eqrStore->failOps[$eqrOp] === 'throw') {
        throw new \RuntimeException('simulated database failure in ' . $eqrOp);
    }

    return true;
};

/**
 * The stand-in gateway for one runner. Every operation mirrors the WHERE
 * clause of its real SQL in fn_email.php (lettered A-L in the #112 plan).
 */
$eqrMakeGateway = static function (\stdClass $eqrStore, string $eqrRunner) use ($eqrIsDue, $eqrShouldFail): array {
    return [
        'lock' => static function () use ($eqrStore, $eqrRunner, $eqrShouldFail): ?bool {
            if ($eqrShouldFail($eqrStore, 'lock')) {
                return null;
            }

            if ($eqrStore->lockHolder === null) {
                $eqrStore->lockHolder = $eqrRunner;
                return true;
            }

            return false;
        },
        'unlock' => static function () use ($eqrStore, $eqrRunner): void {
            if ($eqrStore->lockHolder === $eqrRunner) {
                $eqrStore->lockHolder = null;
            }
        },
        // A: status = 'sending' AND updatedAt < NOW() - m minutes, oldest first.
        'findStuck' => static function (int $eqrMinutes, int $eqrLimit) use ($eqrStore, $eqrShouldFail): ?array {
            if ($eqrShouldFail($eqrStore, 'findStuck')) {
                return null;
            }

            $eqrCutoff = $eqrStore->now - ($eqrMinutes * 60);
            $eqrFound  = [];

            foreach ($eqrStore->rows as $eqrRow) {
                if ($eqrRow['status'] === 'sending' && $eqrRow['updatedAt'] < $eqrCutoff) {
                    $eqrFound[] = $eqrRow;
                }
            }

            usort($eqrFound, static function (array $eqrLeft, array $eqrRight): int {
                if ($eqrLeft['updatedAt'] !== $eqrRight['updatedAt']) {
                    return $eqrLeft['updatedAt'] <=> $eqrRight['updatedAt'];
                }

                return $eqrLeft['emailID'] <=> $eqrRight['emailID'];
            });

            $eqrList = [];

            foreach (array_slice($eqrFound, 0, $eqrLimit) as $eqrRow) {
                $eqrList[] = [
                    'emailID'     => $eqrRow['emailID'],
                    'attempts'    => $eqrRow['attempts'],
                    'maxAttempts' => $eqrRow['maxAttempts'],
                ];
            }

            return $eqrList;
        },
        // B1/B2: guarded by status = 'sending' AND attempts = seen AND still old.
        'recoverStuck' => static function (int $eqrId, int $eqrSeen, int $eqrAfter, ?int $eqrRetryIn, string $eqrNote, int $eqrMinutes) use ($eqrStore, $eqrShouldFail): bool {
            if ($eqrShouldFail($eqrStore, 'recoverStuck')) {
                return false;
            }

            if (!isset($eqrStore->rows[$eqrId])) {
                return false;
            }

            $eqrRow = $eqrStore->rows[$eqrId];

            if ($eqrRow['status'] !== 'sending'
                || $eqrRow['attempts'] !== $eqrSeen
                || $eqrRow['updatedAt'] >= $eqrStore->now - ($eqrMinutes * 60)
            ) {
                return false;
            }

            if ($eqrRetryIn !== null) {
                $eqrRow['status']        = 'queued';
                $eqrRow['nextAttemptAt'] = $eqrStore->now + ($eqrRetryIn * 60);
            } else {
                $eqrRow['status'] = 'failed';
            }

            $eqrRow['attempts']     = $eqrAfter;
            $eqrRow['lastError']    = $eqrNote;
            $eqrRow['updatedAt']    = $eqrStore->now;
            $eqrStore->rows[$eqrId] = $eqrRow;

            return true;
        },
        // C: due rows, ORDER BY nextAttemptAt ASC (NULL first, as in MySQL), emailID ASC.
        'findDue' => static function (int $eqrLimit) use ($eqrStore, $eqrIsDue, $eqrShouldFail): ?array {
            if ($eqrShouldFail($eqrStore, 'findDue')) {
                return null;
            }

            $eqrFound = [];

            foreach ($eqrStore->rows as $eqrRow) {
                if ($eqrIsDue($eqrRow, $eqrStore->now)) {
                    $eqrFound[] = $eqrRow;
                }
            }

            usort($eqrFound, static function (array $eqrLeft, array $eqrRight): int {
                $eqrLeftAt  = $eqrLeft['nextAttemptAt'];
                $eqrRightAt = $eqrRight['nextAttemptAt'];

                if ($eqrLeftAt === null) {
                    $eqrLeftAt = PHP_INT_MIN;
                }

                if ($eqrRightAt === null) {
                    $eqrRightAt = PHP_INT_MIN;
                }

                if ($eqrLeftAt !== $eqrRightAt) {
                    return $eqrLeftAt <=> $eqrRightAt;
                }

                return $eqrLeft['emailID'] <=> $eqrRight['emailID'];
            });

            $eqrIds = [];

            foreach (array_slice($eqrFound, 0, $eqrLimit) as $eqrRow) {
                $eqrIds[] = $eqrRow['emailID'];
            }

            return $eqrIds;
        },
        // D: the claim - only if the row is due RIGHT NOW.
        'claim' => static function (int $eqrId) use ($eqrStore, $eqrIsDue, $eqrShouldFail): ?bool {
            $eqrStore->claimCalls++;

            if ($eqrShouldFail($eqrStore, 'claim')) {
                return null;
            }

            if (!isset($eqrStore->rows[$eqrId]) || !$eqrIsDue($eqrStore->rows[$eqrId], $eqrStore->now)) {
                return false;
            }

            $eqrStore->rows[$eqrId]['status']    = 'sending';
            $eqrStore->rows[$eqrId]['updatedAt'] = $eqrStore->now;

            return true;
        },
        // E: only while 'sending'.
        'load' => static function (int $eqrId) use ($eqrStore, $eqrShouldFail): ?array {
            if ($eqrShouldFail($eqrStore, 'load')) {
                return null;
            }

            if (!isset($eqrStore->rows[$eqrId]) || $eqrStore->rows[$eqrId]['status'] !== 'sending') {
                return null;
            }

            $eqrRow = $eqrStore->rows[$eqrId];

            return [
                'emailID'     => $eqrRow['emailID'],
                'toAddress'   => $eqrRow['toAddress'],
                'toName'      => $eqrRow['toName'],
                'templateKey' => $eqrRow['templateKey'],
                'paramsJSON'  => $eqrRow['paramsJSON'],
                'subject'     => $eqrRow['subject'],
                'attempts'    => $eqrRow['attempts'],
                'maxAttempts' => $eqrRow['maxAttempts'],
                // E' (#123): TIMESTAMPDIFF(SECOND, createdAt, NOW()) on the database clock.
                'ageSeconds'  => $eqrStore->now - $eqrRow['createdAt'],
            ];
        },
        // F' (#122): only while 'sending'; clears paramsJSON in the same statement.
        'markSent' => static function (int $eqrId) use ($eqrStore, $eqrShouldFail): bool {
            if ($eqrShouldFail($eqrStore, 'markSent')) {
                return false;
            }

            if (!isset($eqrStore->rows[$eqrId]) || $eqrStore->rows[$eqrId]['status'] !== 'sending') {
                return false;
            }

            $eqrStore->rows[$eqrId]['status']     = 'sent';
            $eqrStore->rows[$eqrId]['sentAt']     = $eqrStore->now;
            $eqrStore->rows[$eqrId]['lastError']  = null;
            $eqrStore->rows[$eqrId]['paramsJSON'] = null;
            $eqrStore->rows[$eqrId]['updatedAt']  = $eqrStore->now;

            return true;
        },
        // G: only while 'sending'.
        'markRetry' => static function (int $eqrId, int $eqrAfter, int $eqrRetryIn, string $eqrDetail) use ($eqrStore, $eqrShouldFail): bool {
            if ($eqrShouldFail($eqrStore, 'markRetry')) {
                return false;
            }

            if (!isset($eqrStore->rows[$eqrId]) || $eqrStore->rows[$eqrId]['status'] !== 'sending') {
                return false;
            }

            $eqrStore->rows[$eqrId]['status']        = 'queued';
            $eqrStore->rows[$eqrId]['attempts']      = $eqrAfter;
            $eqrStore->rows[$eqrId]['lastError']     = $eqrDetail;
            $eqrStore->rows[$eqrId]['nextAttemptAt'] = $eqrStore->now + ($eqrRetryIn * 60);
            $eqrStore->rows[$eqrId]['updatedAt']     = $eqrStore->now;

            return true;
        },
        // H: only while 'sending'.
        'markGaveUp' => static function (int $eqrId, int $eqrAfter, string $eqrDetail) use ($eqrStore, $eqrShouldFail): bool {
            if ($eqrShouldFail($eqrStore, 'markGaveUp')) {
                return false;
            }

            if (!isset($eqrStore->rows[$eqrId]) || $eqrStore->rows[$eqrId]['status'] !== 'sending') {
                return false;
            }

            $eqrStore->rows[$eqrId]['status']    = 'failed';
            $eqrStore->rows[$eqrId]['attempts']  = $eqrAfter;
            $eqrStore->rows[$eqrId]['lastError'] = $eqrDetail;
            $eqrStore->rows[$eqrId]['updatedAt'] = $eqrStore->now;

            return true;
        },
        // I: only while 'sending'; attempts and nextAttemptAt NOT changed.
        'release' => static function (int $eqrId, string $eqrReason) use ($eqrStore, $eqrShouldFail): bool {
            $eqrStore->releaseCalls++;

            if ($eqrShouldFail($eqrStore, 'release')) {
                return false;
            }

            if (!isset($eqrStore->rows[$eqrId]) || $eqrStore->rows[$eqrId]['status'] !== 'sending') {
                return false;
            }

            $eqrStore->rows[$eqrId]['status']    = 'queued';
            $eqrStore->rows[$eqrId]['lastError'] = $eqrReason;
            $eqrStore->rows[$eqrId]['updatedAt'] = $eqrStore->now;

            return true;
        },
        // J
        'countDue' => static function () use ($eqrStore, $eqrIsDue, $eqrShouldFail): ?int {
            if ($eqrShouldFail($eqrStore, 'countDue')) {
                return null;
            }

            $eqrCount = 0;

            foreach ($eqrStore->rows as $eqrRow) {
                if ($eqrIsDue($eqrRow, $eqrStore->now)) {
                    $eqrCount++;
                }
            }

            return $eqrCount;
        },
        // K: due for more than m minutes (a NULL nextAttemptAt counts from
        //    createdAt), or 'sending' for more than m minutes.
        'countOverdue' => static function (int $eqrMinutes) use ($eqrStore, $eqrShouldFail): ?int {
            if ($eqrShouldFail($eqrStore, 'countOverdue')) {
                return null;
            }

            $eqrCutoff = $eqrStore->now - ($eqrMinutes * 60);
            $eqrCount  = 0;

            foreach ($eqrStore->rows as $eqrRow) {
                $eqrDueFrom = $eqrRow['nextAttemptAt'];

                if ($eqrDueFrom === null) {
                    $eqrDueFrom = $eqrRow['createdAt'];
                }

                $eqrWaitingTooLong = ($eqrRow['status'] === 'queued' || $eqrRow['status'] === 'failed')
                    && $eqrRow['attempts'] < $eqrRow['maxAttempts']
                    && $eqrDueFrom <= $eqrCutoff;
                $eqrSendingTooLong = $eqrRow['status'] === 'sending' && $eqrRow['updatedAt'] < $eqrCutoff;

                if ($eqrWaitingTooLong || $eqrSendingTooLong) {
                    $eqrCount++;
                }
            }

            return $eqrCount;
        },
        // L: every status key present.
        'countByStatus' => static function () use ($eqrStore, $eqrShouldFail): ?array {
            if ($eqrShouldFail($eqrStore, 'countByStatus')) {
                return null;
            }

            $eqrCounts = ['queued' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];

            foreach ($eqrStore->rows as $eqrRow) {
                $eqrCounts[$eqrRow['status']]++;
            }

            return $eqrCounts;
        },
        // --- #122 / #123 (statements N-R in the plan). Each result list is built
        //     by walking the rows in ascending emailID order. ---------------------
        // N: only while 'sending' - the claiming runner finishing its own row.
        'markSkipped' => static function (int $eqrId, string $eqrReason) use ($eqrStore, $eqrShouldFail): bool {
            if ($eqrShouldFail($eqrStore, 'markSkipped')) {
                return false;
            }

            if (!isset($eqrStore->rows[$eqrId]) || $eqrStore->rows[$eqrId]['status'] !== 'sending') {
                return false;
            }

            $eqrStore->rows[$eqrId]['status']     = 'skipped';
            $eqrStore->rows[$eqrId]['lastError']  = $eqrReason;
            $eqrStore->rows[$eqrId]['paramsJSON'] = null;
            $eqrStore->rows[$eqrId]['updatedAt']  = $eqrStore->now;

            return true;
        },
        // O: templateKey = ? AND status IN ('queued','failed') AND attempts < maxAttempts
        //    AND createdAt <= NOW() - maxAge seconds, at most $limit (no ORDER BY).
        'skipExpired' => static function (string $eqrTemplate, int $eqrMaxAge, string $eqrNote, int $eqrLimit) use ($eqrStore, $eqrShouldFail): ?int {
            if ($eqrShouldFail($eqrStore, 'skipExpired')) {
                return null;
            }

            $eqrIds = array_keys($eqrStore->rows);
            sort($eqrIds);
            $eqrChanged = 0;

            foreach ($eqrIds as $eqrId) {
                if ($eqrChanged >= $eqrLimit) {
                    break;
                }

                $eqrRow = $eqrStore->rows[$eqrId];

                if ($eqrRow['templateKey'] === $eqrTemplate
                    && ($eqrRow['status'] === 'queued' || $eqrRow['status'] === 'failed')
                    && $eqrRow['attempts'] < $eqrRow['maxAttempts']
                    && $eqrRow['createdAt'] <= $eqrStore->now - $eqrMaxAge
                ) {
                    $eqrStore->rows[$eqrId]['status']     = 'skipped';
                    $eqrStore->rows[$eqrId]['lastError']  = $eqrNote;
                    $eqrStore->rows[$eqrId]['paramsJSON'] = null;
                    $eqrStore->rows[$eqrId]['updatedAt']  = $eqrStore->now;
                    $eqrChanged++;
                }
            }

            return $eqrChanged;
        },
        // P: status = 'failed' AND attempts >= maxAttempts AND paramsJSON IS NOT NULL
        //    AND createdAt <= NOW() - hours; updatedAt is deliberately NOT changed.
        'clearGivenUp' => static function (int $eqrHours, int $eqrLimit) use ($eqrStore, $eqrShouldFail): ?int {
            if ($eqrShouldFail($eqrStore, 'clearGivenUp')) {
                return null;
            }

            $eqrIds = array_keys($eqrStore->rows);
            sort($eqrIds);
            $eqrChanged = 0;

            foreach ($eqrIds as $eqrId) {
                if ($eqrChanged >= $eqrLimit) {
                    break;
                }

                $eqrRow = $eqrStore->rows[$eqrId];

                if ($eqrRow['status'] === 'failed'
                    && $eqrRow['attempts'] >= $eqrRow['maxAttempts']
                    && $eqrRow['paramsJSON'] !== null
                    && $eqrRow['createdAt'] <= $eqrStore->now - ($eqrHours * 3600)
                ) {
                    $eqrStore->rows[$eqrId]['paramsJSON'] = null;
                    $eqrChanged++;
                }
            }

            return $eqrChanged;
        },
        // Q: DELETE status IN ('sent','skipped') AND createdAt < NOW() - days,
        //    ORDER BY createdAt, emailID, at most $limit.
        'purgeFinished' => static function (int $eqrDays, int $eqrLimit) use ($eqrStore, $eqrShouldFail): ?int {
            if ($eqrShouldFail($eqrStore, 'purgeFinished')) {
                return null;
            }

            $eqrFound = [];

            foreach ($eqrStore->rows as $eqrRow) {
                if (($eqrRow['status'] === 'sent' || $eqrRow['status'] === 'skipped')
                    && $eqrRow['createdAt'] < $eqrStore->now - ($eqrDays * 86400)
                ) {
                    $eqrFound[] = $eqrRow;
                }
            }

            usort($eqrFound, static function (array $eqrLeft, array $eqrRight): int {
                if ($eqrLeft['createdAt'] !== $eqrRight['createdAt']) {
                    return $eqrLeft['createdAt'] <=> $eqrRight['createdAt'];
                }

                return $eqrLeft['emailID'] <=> $eqrRight['emailID'];
            });

            $eqrDeleted = 0;

            foreach (array_slice($eqrFound, 0, $eqrLimit) as $eqrRow) {
                unset($eqrStore->rows[$eqrRow['emailID']]);
                $eqrDeleted++;
            }

            return $eqrDeleted;
        },
        // R: DELETE status = 'failed' AND attempts >= maxAttempts AND createdAt < NOW() - days,
        //    ORDER BY createdAt, emailID, at most $limit.
        'purgeGivenUp' => static function (int $eqrDays, int $eqrLimit) use ($eqrStore, $eqrShouldFail): ?int {
            if ($eqrShouldFail($eqrStore, 'purgeGivenUp')) {
                return null;
            }

            $eqrFound = [];

            foreach ($eqrStore->rows as $eqrRow) {
                if ($eqrRow['status'] === 'failed'
                    && $eqrRow['attempts'] >= $eqrRow['maxAttempts']
                    && $eqrRow['createdAt'] < $eqrStore->now - ($eqrDays * 86400)
                ) {
                    $eqrFound[] = $eqrRow;
                }
            }

            usort($eqrFound, static function (array $eqrLeft, array $eqrRight): int {
                if ($eqrLeft['createdAt'] !== $eqrRight['createdAt']) {
                    return $eqrLeft['createdAt'] <=> $eqrRight['createdAt'];
                }

                return $eqrLeft['emailID'] <=> $eqrRight['emailID'];
            });

            $eqrDeleted = 0;

            foreach (array_slice($eqrFound, 0, $eqrLimit) as $eqrRow) {
                unset($eqrStore->rows[$eqrRow['emailID']]);
                $eqrDeleted++;
            }

            return $eqrDeleted;
        },
    ];
};

/**
 * The stand-in sender for one runner: records the send, moves the run clock
 * on, runs the one-off onSend hook (used to interleave a second runner), and
 * returns the next scripted result for that address (default: success). A
 * scripted 'throw' makes the sender throw.
 */
$eqrMakeSender = static function (\stdClass $eqrStore, string $eqrRunner): \Closure {
    return static function (string $eqrTo, string $eqrSubject, string $eqrBody, ?string $eqrToName) use ($eqrStore, $eqrRunner): array {
        $eqrStore->sends[] = [
            'runner'  => $eqrRunner,
            'to'      => $eqrTo,
            'subject' => $eqrSubject,
            'body'    => $eqrBody,
        ];

        $eqrStore->runClock += $eqrStore->secondsPerSend;

        if ($eqrStore->onSend !== null) {
            $eqrHook          = $eqrStore->onSend;
            $eqrStore->onSend = null;
            $eqrHook();
        }

        if (isset($eqrStore->sendScript[$eqrTo]) && count($eqrStore->sendScript[$eqrTo]) > 0) {
            $eqrNext = array_shift($eqrStore->sendScript[$eqrTo]);

            if ($eqrNext === 'throw') {
                throw new \RuntimeException('simulated sender crash');
            }

            return $eqrNext;
        }

        return [true, null];
    };
};

/**
 * Run one batch as the named runner, always on the SIMULATED run clock (never
 * the real one), so the time-budget tests are exact.
 */
$eqrRun = static function (\stdClass $eqrStore, string $eqrRunner, array $eqrOptions = []) use ($eqrMakeGateway, $eqrMakeSender): array {
    $eqrOptions['clock'] = static function () use ($eqrStore): float {
        return (float) $eqrStore->runClock;
    };

    return runEmailQueueBatch($eqrMakeGateway($eqrStore, $eqrRunner), $eqrMakeSender($eqrStore, $eqrRunner), $eqrOptions);
};

/** How many times each address was sent to. */
$eqrSendCounts = static function (\stdClass $eqrStore): array {
    $eqrCounts = [];

    foreach ($eqrStore->sends as $eqrSend) {
        if (!isset($eqrCounts[$eqrSend['to']])) {
            $eqrCounts[$eqrSend['to']] = 0;
        }

        $eqrCounts[$eqrSend['to']]++;
    }

    return $eqrCounts;
};

/** True when exactly these addresses were each sent exactly once. */
$eqrEachSentOnce = static function (\stdClass $eqrStore, array $eqrAddresses) use ($eqrSendCounts): bool {
    $eqrCounts = $eqrSendCounts($eqrStore);

    if (count($eqrCounts) !== count($eqrAddresses)) {
        return false;
    }

    foreach ($eqrAddresses as $eqrAddress) {
        if (!isset($eqrCounts[$eqrAddress]) || $eqrCounts[$eqrAddress] !== 1) {
            return false;
        }
    }

    return true;
};

/** A settings reader over a fixed array (stands in for getConfig()). */
$eqrSettingsGetter = static function (array $eqrValues): \Closure {
    return static function (string $eqrKey, mixed $eqrDefault = null) use ($eqrValues): mixed {
        if (array_key_exists($eqrKey, $eqrValues)) {
            return $eqrValues[$eqrKey];
        }

        return $eqrDefault;
    };
};

// 🔑 Complete, obviously fake settings. The values are distinctive so the
//    tests can prove they are NEVER printed.
$eqrReadySettings = [
    'email_enabled'        => true,
    'email_provider'       => 'graph',
    'graph_tenant_id'      => 'tenant-value-123',
    'graph_client_id'      => 'client-value-456',
    'graph_client_secret'  => 'secret-value-789',
    'graph_sender_mailbox' => 'mailbox-value@example.com',
];
$eqrSettingValues = ['tenant-value-123', 'client-value-456', 'secret-value-789', 'mailbox-value'];

// ==============================================================================
// ✅ Section 1: pure helpers
// ==============================================================================

// --- decideEmailQueueFailure() ---------------------------------------------------
assertEquals(['attemptsAfter' => 1, 'giveUp' => false, 'retryInMinutes' => 2], decideEmailQueueFailure(0, 5), 'decideEmailQueueFailure: 1st failure of 5 -> retry in 2 minutes');
assertEquals(['attemptsAfter' => 4, 'giveUp' => false, 'retryInMinutes' => 16], decideEmailQueueFailure(3, 5), 'decideEmailQueueFailure: (3,5) -> retry in 16');
assertEquals(['attemptsAfter' => 5, 'giveUp' => true, 'retryInMinutes' => null], decideEmailQueueFailure(4, 5), 'decideEmailQueueFailure: (4,5) -> give up (5th attempt)');
assertEquals(['attemptsAfter' => 10, 'giveUp' => true, 'retryInMinutes' => null], decideEmailQueueFailure(9, 5), 'decideEmailQueueFailure: (9,5) -> give up');
assertEquals(['attemptsAfter' => 1, 'giveUp' => true, 'retryInMinutes' => null], decideEmailQueueFailure(0, 1), 'decideEmailQueueFailure: (0,1) -> give up');

// --- classifyEmailSendError() ----------------------------------------------------
$eqrClassifyCases = [
    ['skipped: email_enabled is 0', 'system'],
    ['skipped: no Graph access token available', 'system'],
    ['HTTP 401 | graph_error_code: InvalidAuthenticationToken', 'system'],
    ['HTTP 403', 'system'],
    ['HTTP 404 | graph_error_code: ErrorInvalidUser', 'system'],
    ['failed: could not initialise curl', 'system'],
    ['HTTP 429', 'transient'],
    ['HTTP 500', 'transient'],
    ['HTTP 503 | graph_error_code: ServiceUnavailable', 'transient'],
    ['HTTP 0 | transport error (errno 28): Operation timed out', 'transient'],
    ['failed: the sender stopped with an unexpected error (RuntimeException)', 'transient'],
    ['HTTP 400 | graph_error_code: ErrorInvalidRecipients', 'message'],
    ['failed: could not encode request payload', 'message'],
    ['HTTP 4290', 'message'],
    [null, 'message'],
    ['', 'message'],
];

foreach ($eqrClassifyCases as $eqrCase) {
    assertEquals(
        $eqrCase[1],
        classifyEmailSendError($eqrCase[0]),
        'classifyEmailSendError: ' . var_export($eqrCase[0], true) . ' -> ' . $eqrCase[1]
    );
}

// --- emailQueueSafeDetail() --------------------------------------------------------
assertEquals('Could not send to [address] today', emailQueueSafeDetail('Could not send to alice@example.com today'), 'emailQueueSafeDetail: masks alice@example.com to [address]');
assertEquals('line one line two end', emailQueueSafeDetail("line one\nline two\tend"), 'emailQueueSafeDetail: removes newlines and tabs');
assertEquals(str_repeat('a', 27) . '...', emailQueueSafeDetail(str_repeat('a', 100), 30), 'emailQueueSafeDetail: truncates to the limit with ...');
assertEquals('HTTP 503 | graph_error_code: X', emailQueueSafeDetail('HTTP 503 | graph_error_code: X'), 'emailQueueSafeDetail: leaves HTTP 503 | graph_error_code: X unchanged');
assertEquals(20, mb_strlen(emailQueueSafeDetail(str_repeat('b', 50), 5)), 'emailQueueSafeDetail: a limit below 20 is raised to 20');

// --- checkEmailQueueRunnerSettings() ---------------------------------------------
$eqrOffSettings                  = $eqrReadySettings;
$eqrOffSettings['email_enabled'] = false;
$eqrSettingsOff                  = checkEmailQueueRunnerSettings($eqrSettingsGetter($eqrOffSettings));
assertEquals('disabled', $eqrSettingsOff['state'], 'checkEmailQueueRunnerSettings: off -> disabled');

$eqrSettingsReady = checkEmailQueueRunnerSettings($eqrSettingsGetter($eqrReadySettings));
assertEquals('ready', $eqrSettingsReady['state'], 'checkEmailQueueRunnerSettings: on + all four set -> ready');
assertEquals([], $eqrSettingsReady['problems'], 'checkEmailQueueRunnerSettings: on + all four set -> no problems');

$eqrGappySettings                         = $eqrReadySettings;
$eqrGappySettings['graph_client_secret']  = '';
$eqrGappySettings['graph_sender_mailbox'] = "   \t ";
$eqrSettingsGappy                         = checkEmailQueueRunnerSettings($eqrSettingsGetter($eqrGappySettings));
$eqrGappyText                             = implode(' | ', $eqrSettingsGappy['problems']);
assertEquals('incomplete', $eqrSettingsGappy['state'], 'checkEmailQueueRunnerSettings: on + empty secret + whitespace-only mailbox -> incomplete');
assertContains('graph_client_secret', $eqrGappyText, 'checkEmailQueueRunnerSettings: names graph_client_secret');
assertContains('graph_sender_mailbox', $eqrGappyText, 'checkEmailQueueRunnerSettings: names graph_sender_mailbox');
assertFalse(str_contains($eqrGappyText, 'graph_tenant_id'), 'checkEmailQueueRunnerSettings: does not name a setting that is fine');

$eqrWorkspaceSettings                   = $eqrReadySettings;
$eqrWorkspaceSettings['email_provider'] = 'workspace';
$eqrSettingsWorkspace                   = checkEmailQueueRunnerSettings($eqrSettingsGetter($eqrWorkspaceSettings));
assertEquals('incomplete', $eqrSettingsWorkspace['state'], "checkEmailQueueRunnerSettings: on + provider 'workspace' -> incomplete");

$eqrAllProblemText = implode(' | ', array_merge($eqrSettingsOff['problems'], $eqrSettingsGappy['problems'], $eqrSettingsWorkspace['problems']));
$eqrLeaked         = false;

foreach (array_merge($eqrSettingValues, ['workspace']) as $eqrValue) {
    if (str_contains($eqrAllProblemText, $eqrValue)) {
        $eqrLeaked = true;
    }
}

assertFalse($eqrLeaked, 'checkEmailQueueRunnerSettings: no setting value (e.g. tenant-value-123) appears in any problem text');

// --- parseEmailQueueRunnerArgs() -------------------------------------------------
assertEquals(
    ['ok' => true, 'error' => null, 'help' => false, 'quiet' => false, 'check' => false, 'limit' => 20],
    parseEmailQueueRunnerArgs(['process_email_queue.php']),
    'parseEmailQueueRunnerArgs: no options -> defaults (ok, limit 20)'
);
assertTrue(parseEmailQueueRunnerArgs(['x', '--quiet'])['quiet'], 'parseEmailQueueRunnerArgs: --quiet sets quiet');
assertTrue(parseEmailQueueRunnerArgs(['x', '-q'])['quiet'], 'parseEmailQueueRunnerArgs: -q sets quiet');
assertTrue(parseEmailQueueRunnerArgs(['x', '--check'])['check'], 'parseEmailQueueRunnerArgs: --check sets check');
assertTrue(parseEmailQueueRunnerArgs(['x', '--help'])['help'], 'parseEmailQueueRunnerArgs: --help sets help');
assertTrue(parseEmailQueueRunnerArgs(['x', '-h'])['help'], 'parseEmailQueueRunnerArgs: -h sets help');
assertEquals(5, parseEmailQueueRunnerArgs(['x', '--limit=5'])['limit'], 'parseEmailQueueRunnerArgs: --limit=5 -> 5');
assertTrue(parseEmailQueueRunnerArgs(['x', '--limit=50'])['ok'], 'parseEmailQueueRunnerArgs: --limit=50 is ok');

foreach (['--limit=0', '--limit=51', '--limit=abc', '--limit='] as $eqrBadLimit) {
    assertFalse(parseEmailQueueRunnerArgs(['x', $eqrBadLimit])['ok'], 'parseEmailQueueRunnerArgs: ' . $eqrBadLimit . ' is not ok');
}

$eqrBogusArgs = parseEmailQueueRunnerArgs(['x', '--bogus']);
assertFalse($eqrBogusArgs['ok'], 'parseEmailQueueRunnerArgs: --bogus is not ok');
assertContains('unknown option', (string) $eqrBogusArgs['error'], 'parseEmailQueueRunnerArgs: --bogus error mentions unknown option');

$eqrTwoErrors = parseEmailQueueRunnerArgs(['x', '--bogus', '--limit=0']);
assertContains('unknown option', (string) $eqrTwoErrors['error'], 'parseEmailQueueRunnerArgs: the first error is kept');

// --- emailQueueRunnerExitCode() ----------------------------------------------------
$eqrExitCases = [
    ['ok', 0, 0],
    ['busy', 0, 0],
    ['stalled', 0, 4],
    ['lock_error', 0, 3],
    ['db_error', 0, 3],
    ['system_problem', 0, 4],
    ['ok', 2, 5],
    ['db_error', 1, 3],
];

foreach ($eqrExitCases as $eqrCase) {
    $eqrCaseResult           = emptyEmailQueueRunResult();
    $eqrCaseResult['status'] = $eqrCase[0];
    $eqrCaseResult['gaveUp'] = $eqrCase[1];
    assertEquals($eqrCase[2], emailQueueRunnerExitCode($eqrCaseResult), 'emailQueueRunnerExitCode: ' . $eqrCase[0] . ' with gaveUp ' . $eqrCase[1] . ' -> ' . $eqrCase[2]);
}

// --- emailQueueRunnerUsage() ---------------------------------------------------------
$eqrUsage = emailQueueRunnerUsage();

foreach (['--quiet', '--limit', '--check', '--help', '0 = ', '1 = ', '2 = ', '3 = ', '4 = ', '5 = '] as $eqrNeedle) {
    assertContains($eqrNeedle, $eqrUsage, 'emailQueueRunnerUsage mentions ' . trim($eqrNeedle));
}

// --- validateEmailQueueGateway() -----------------------------------------------------
$eqrIncompleteGateway = $eqrMakeGateway($eqrNewStore(), 'A');
unset($eqrIncompleteGateway['countByStatus']);
assertThrows(
    static function () use ($eqrIncompleteGateway): void {
        validateEmailQueueGateway($eqrIncompleteGateway);
    },
    'validateEmailQueueGateway throws InvalidArgumentException when an op is missing',
    \InvalidArgumentException::class
);

$eqrProductionGatewayThrew = false;

try {
    validateEmailQueueGateway(emailQueueDatabaseGateway());
} catch (\Throwable $eqrError) {
    $eqrProductionGatewayThrew = true;
}

assertFalse($eqrProductionGatewayThrew, 'the production gateway (emailQueueDatabaseGateway) provides every operation, each a real function');
assertEquals(CUERCODE_EMAIL_QUEUE_GATEWAY_KEYS, array_keys(emailQueueDatabaseGateway()), 'the production gateway maps exactly the documented operations, in order');

// ==============================================================================
// ✅ Section 2: runEmailQueueBatch() with the in-memory stand-in
// ==============================================================================

// --- sends every due email once and marks it sent ---------------------------------
$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1);
$eqrAddRow($eqrStore, 2);
$eqrAddRow($eqrStore, 3);
$eqrResult = $eqrRun($eqrStore, 'A');
assertEquals(3, $eqrResult['sent'], 'sends every due email once and marks it sent: 3 sent');
assertEquals(3, count($eqrStore->sends), 'sends every due email once and marks it sent: the sender was called 3 times');
assertTrue($eqrEachSentOnce($eqrStore, ['user1@example.com', 'user2@example.com', 'user3@example.com']), 'sends every due email once and marks it sent: each address exactly once');

$eqrAllSent  = true;
$eqrAllLinks = true;

foreach ($eqrStore->rows as $eqrRow) {
    if ($eqrRow['status'] !== 'sent') {
        $eqrAllSent = false;
    }
}

foreach ($eqrStore->sends as $eqrSend) {
    $eqrExpectedLink = 'https://cuercode.net/verify-email?token=T' . substr($eqrSend['to'], 4, 1);

    if (!str_contains($eqrSend['body'], $eqrExpectedLink)) {
        $eqrAllLinks = false;
    }
}

assertTrue($eqrAllSent, 'sends every due email once and marks it sent: every row is now sent');
assertTrue($eqrAllLinks, "sends every due email once and marks it sent: each body contains that row's verification link");
assertEquals(0, $eqrResult['waiting'], 'sends every due email once and marks it sent: nothing still waiting');
assertEquals(null, $eqrStore->lockHolder, 'sends every due email once and marks it sent: the lock is released');
assertEquals(0, emailQueueRunnerExitCode($eqrResult), 'sends every due email once and marks it sent: exit code 0');

// --- leaves alone rows that are not due, being sent, already sent, or out of attempts ---
$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['nextAttemptAt' => $eqrStore->now + 300]);
$eqrAddRow($eqrStore, 2, ['status' => 'sending']);
$eqrAddRow($eqrStore, 3, ['status' => 'sent']);
$eqrAddRow($eqrStore, 4, ['status' => 'failed', 'attempts' => 5]);
$eqrRowsBefore = $eqrStore->rows;
$eqrResult     = $eqrRun($eqrStore, 'A');
assertEquals(0, count($eqrStore->sends), 'leaves alone rows that are not due, being sent, already sent, or out of attempts: 0 sends');
assertEquals($eqrRowsBefore, $eqrStore->rows, 'leaves alone rows that are not due, being sent, already sent, or out of attempts: nothing changed');

// --- two runners at the same time never send the same email twice (with the lock) ---
$eqrStore = $eqrNewStore();

for ($eqrId = 1; $eqrId <= 5; $eqrId++) {
    $eqrAddRow($eqrStore, $eqrId);
}

$eqrStore->onSend = static function () use ($eqrStore, $eqrRun): void {
    $eqrStore->nested = $eqrRun($eqrStore, 'B');
};
$eqrResultA = $eqrRun($eqrStore, 'A');
assertEquals('busy', $eqrStore->nested['status'], 'two runners at the same time never send the same email twice (with the lock): B is busy');
assertEquals(0, $eqrStore->nested['sent'], 'two runners at the same time never send the same email twice (with the lock): B sent 0');
assertEquals(5, count($eqrStore->sends), 'two runners at the same time never send the same email twice (with the lock): 5 sends in total');
assertTrue(
    $eqrEachSentOnce($eqrStore, ['user1@example.com', 'user2@example.com', 'user3@example.com', 'user4@example.com', 'user5@example.com']),
    'two runners at the same time never send the same email twice (with the lock): each address exactly once'
);
assertEquals(null, $eqrStore->lockHolder, 'two runners at the same time never send the same email twice (with the lock): lock released afterwards');

// --- two runners at the same time never send the same email twice (lock bypassed - row claims alone) ---
$eqrStore = $eqrNewStore();

for ($eqrId = 1; $eqrId <= 5; $eqrId++) {
    $eqrAddRow($eqrStore, $eqrId);
}

$eqrStore->onSend = static function () use ($eqrStore, $eqrRun): void {
    $eqrStore->nested = $eqrRun($eqrStore, 'B', ['useLock' => false]);
};
$eqrResultA = $eqrRun($eqrStore, 'A', ['useLock' => false]);
assertTrue(
    $eqrEachSentOnce($eqrStore, ['user1@example.com', 'user2@example.com', 'user3@example.com', 'user4@example.com', 'user5@example.com']),
    'two runners at the same time never send the same email twice (lock bypassed - row claims alone): each address exactly once across A and B'
);
assertTrue($eqrStore->nested['sent'] >= 1, 'two runners at the same time never send the same email twice (lock bypassed - row claims alone): B sent at least 1');
assertTrue($eqrResultA['skipped'] >= 1, "two runners at the same time never send the same email twice (lock bypassed - row claims alone): A's skipped is at least 1");

// --- an out-of-date list cannot re-send an email another runner just rescheduled ---
$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1);
$eqrAddRow($eqrStore, 2);
$eqrStore->sendScript['user2@example.com'] = [[false, 'HTTP 400 | graph_error_code: ErrorInvalidRecipients']];
$eqrStore->onSend                          = static function () use ($eqrStore, $eqrRun): void {
    $eqrStore->nested = $eqrRun($eqrStore, 'B', ['useLock' => false]);
};
$eqrResultA  = $eqrRun($eqrStore, 'A', ['useLock' => false]);
$eqrYSenders = [];

foreach ($eqrStore->sends as $eqrSend) {
    if ($eqrSend['to'] === 'user2@example.com') {
        $eqrYSenders[] = $eqrSend['runner'];
    }
}

assertEquals(['B'], $eqrYSenders, 'an out-of-date list cannot re-send an email another runner just rescheduled (back-off respected): Y sent exactly once, by B');
assertEquals('queued', $eqrStore->rows[2]['status'], 'an out-of-date list cannot re-send an email another runner just rescheduled (back-off respected): Y is queued');
assertEquals(1, $eqrStore->rows[2]['attempts'], 'an out-of-date list cannot re-send an email another runner just rescheduled (back-off respected): Y attempts 1');
assertEquals($eqrStore->now + 120, $eqrStore->rows[2]['nextAttemptAt'], 'an out-of-date list cannot re-send an email another runner just rescheduled (back-off respected): Y retries in 2 minutes');
assertTrue($eqrResultA['skipped'] >= 1, "an out-of-date list cannot re-send an email another runner just rescheduled (back-off respected): A's claim of Y failed");

// --- a failed email is retried with back-off 2, 4, 8, 16 minutes, then given up ---
$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1);
$eqrStore->sendScript['user1@example.com'] = array_fill(0, 5, [false, 'HTTP 400 | graph_error_code: ErrorInvalidRecipients']);

for ($eqrRound = 1; $eqrRound <= 4; $eqrRound++) {
    $eqrResult = $eqrRun($eqrStore, 'A');
    $eqrLabel  = 'a failed email is retried with back-off 2, 4, 8, 16 minutes, then given up: after failure ' . $eqrRound;
    assertEquals('queued', $eqrStore->rows[1]['status'], $eqrLabel . ', back in the queue');
    assertEquals($eqrRound, $eqrStore->rows[1]['attempts'], $eqrLabel . ', attempts ' . $eqrRound);
    assertEquals($eqrStore->now + ((2 ** $eqrRound) * 60), $eqrStore->rows[1]['nextAttemptAt'], $eqrLabel . ', retry in ' . (2 ** $eqrRound) . ' minutes');
    assertEquals(0, emailQueueRunnerExitCode($eqrResult), $eqrLabel . ', exit code 0');

    if ($eqrRound === 1) {
        // ⏳ One minute later - before the 2-minute back-off expires.
        $eqrStore->now += 60;
        $eqrEarly = $eqrRun($eqrStore, 'A');
        assertEquals(1, count($eqrStore->sends), 'a failed email is retried with back-off 2, 4, 8, 16 minutes, then given up: a run before the back-off expires sends nothing');
        assertEquals(0, $eqrEarly['processed'], 'a failed email is retried with back-off 2, 4, 8, 16 minutes, then given up: a run before the back-off expires claims nothing');
    }

    $eqrStore->now = $eqrStore->rows[1]['nextAttemptAt'];
}

$eqrResult = $eqrRun($eqrStore, 'A');
assertEquals('failed', $eqrStore->rows[1]['status'], 'a failed email is retried with back-off 2, 4, 8, 16 minutes, then given up: after failure 5, status failed');
assertEquals(5, $eqrStore->rows[1]['attempts'], 'a failed email is retried with back-off 2, 4, 8, 16 minutes, then given up: after failure 5, attempts 5');
assertEquals(1, $eqrResult['gaveUp'], 'a failed email is retried with back-off 2, 4, 8, 16 minutes, then given up: gaveUp 1');
assertEquals(5, emailQueueRunnerExitCode($eqrResult), 'a failed email is retried with back-off 2, 4, 8, 16 minutes, then given up: exit code 5');
assertEquals(5, count($eqrStore->sends), 'a failed email is retried with back-off 2, 4, 8, 16 minutes, then given up: exactly 5 sends in total');

// --- one email's message-level failure does not stop the others ------------------
$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1);
$eqrAddRow($eqrStore, 2);
$eqrAddRow($eqrStore, 3);
$eqrStore->sendScript['user2@example.com'] = [[false, 'HTTP 400 | graph_error_code: ErrorInvalidRecipients']];
$eqrResult                                 = $eqrRun($eqrStore, 'A');
assertEquals('sent', $eqrStore->rows[1]['status'], "one email's message-level failure does not stop the others: row 1 sent");
assertEquals('sent', $eqrStore->rows[3]['status'], "one email's message-level failure does not stop the others: row 3 sent");
assertEquals('queued', $eqrStore->rows[2]['status'], "one email's message-level failure does not stop the others: row 2 queued");
assertEquals(1, $eqrStore->rows[2]['attempts'], "one email's message-level failure does not stop the others: row 2 attempts 1");
assertFalse($eqrResult['stoppedEarly'], "one email's message-level failure does not stop the others: the run was not stopped early");

// --- a template that cannot render counts as a failed attempt for that email only ---
$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1);
$eqrAddRow($eqrStore, 2, ['templateKey' => 'no_such_template']);
$eqrAddRow($eqrStore, 3);
$eqrResult = $eqrRun($eqrStore, 'A');
assertEquals('queued', $eqrStore->rows[2]['status'], 'a template that cannot render counts as a failed attempt for that email only: row queued');
assertEquals(1, $eqrStore->rows[2]['attempts'], 'a template that cannot render counts as a failed attempt for that email only: attempts 1');
assertContains('template', (string) $eqrStore->rows[2]['lastError'], 'a template that cannot render counts as a failed attempt for that email only: lastError mentions the template');
assertEquals('sent', $eqrStore->rows[1]['status'], 'a template that cannot render counts as a failed attempt for that email only: row 1 sent');
assertEquals('sent', $eqrStore->rows[3]['status'], 'a template that cannot render counts as a failed attempt for that email only: row 3 sent');
assertEquals(2, count($eqrStore->sends), 'a template that cannot render counts as a failed attempt for that email only: the broken one was never handed to the sender');

// --- sending blocked (no Graph token): email put back with no attempt used, run stopped, exit code 4 ---
$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1);
$eqrAddRow($eqrStore, 2);
$eqrAddRow($eqrStore, 3);
$eqrStore->sendScript['user1@example.com'] = [[false, 'skipped: no Graph access token available']];
$eqrResult                                 = $eqrRun($eqrStore, 'A');
assertEquals('queued', $eqrStore->rows[1]['status'], 'sending blocked (no Graph token): email put back with no attempt used, run stopped, exit code 4: row 1 queued');
assertEquals(0, $eqrStore->rows[1]['attempts'], 'sending blocked (no Graph token): email put back with no attempt used, run stopped, exit code 4: attempts still 0');
assertContains('blocked', (string) $eqrStore->rows[1]['lastError'], 'sending blocked (no Graph token): email put back with no attempt used, run stopped, exit code 4: lastError says blocked');
assertEquals(1, $eqrStore->claimCalls, 'sending blocked (no Graph token): email put back with no attempt used, run stopped, exit code 4: rows 2 and 3 never claimed');
assertEquals(0, $eqrStore->rows[2]['attempts'] + $eqrStore->rows[3]['attempts'], 'sending blocked (no Graph token): email put back with no attempt used, run stopped, exit code 4: rows 2 and 3 untouched');
assertEquals('system_problem', $eqrResult['status'], 'sending blocked (no Graph token): email put back with no attempt used, run stopped, exit code 4: status system_problem');
assertEquals(4, emailQueueRunnerExitCode($eqrResult), 'sending blocked (no Graph token): email put back with no attempt used, run stopped, exit code 4: exit code 4');
assertEquals(1, $eqrResult['released'], 'sending blocked (no Graph token): email put back with no attempt used, run stopped, exit code 4: released 1');

// --- Graph 401/403/404 block sending the same way -----------------------------------
$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1);
$eqrStore->sendScript['user1@example.com'] = [[false, 'HTTP 403']];
$eqrResult                                 = $eqrRun($eqrStore, 'A');
assertEquals(0, $eqrStore->rows[1]['attempts'], 'Graph 401/403/404 block sending the same way: HTTP 403 uses no attempt');
assertEquals('system_problem', $eqrResult['status'], 'Graph 401/403/404 block sending the same way: HTTP 403 -> system_problem');

// --- temporary Graph trouble uses an attempt, schedules a retry and stops the run early ---
$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1);
$eqrAddRow($eqrStore, 2);
$eqrAddRow($eqrStore, 3);
$eqrStore->sendScript['user1@example.com'] = [[false, 'HTTP 503']];
$eqrResult                                 = $eqrRun($eqrStore, 'A');
assertEquals('queued', $eqrStore->rows[1]['status'], 'temporary Graph trouble uses an attempt, schedules a retry and stops the run early: row 1 queued');
assertEquals(1, $eqrStore->rows[1]['attempts'], 'temporary Graph trouble uses an attempt, schedules a retry and stops the run early: attempts 1');
assertEquals($eqrStore->now + 120, $eqrStore->rows[1]['nextAttemptAt'], 'temporary Graph trouble uses an attempt, schedules a retry and stops the run early: retry in 2 minutes');
assertEquals(0, $eqrStore->rows[2]['attempts'] + $eqrStore->rows[3]['attempts'], 'temporary Graph trouble uses an attempt, schedules a retry and stops the run early: rows 2 and 3 untouched');
assertEquals('queued', $eqrStore->rows[2]['status'], 'temporary Graph trouble uses an attempt, schedules a retry and stops the run early: row 2 still queued');
assertTrue($eqrResult['stoppedEarly'], 'temporary Graph trouble uses an attempt, schedules a retry and stops the run early: stoppedEarly');
assertEquals('temporary_problem', $eqrResult['stopReason'], "temporary Graph trouble uses an attempt, schedules a retry and stops the run early: stopReason 'temporary_problem'");
assertEquals('ok', $eqrResult['status'], 'temporary Graph trouble uses an attempt, schedules a retry and stops the run early: status ok');
assertEquals(0, emailQueueRunnerExitCode($eqrResult), 'temporary Graph trouble uses an attempt, schedules a retry and stops the run early: exit code 0');

// --- a sender that throws is treated as temporary trouble ---------------------------
$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1);
$eqrAddRow($eqrStore, 2);
$eqrStore->sendScript['user1@example.com'] = ['throw'];
$eqrResult                                 = $eqrRun($eqrStore, 'A');
assertEquals('queued', $eqrStore->rows[1]['status'], 'a sender that throws is treated as temporary trouble: row queued');
assertEquals(1, $eqrStore->rows[1]['attempts'], 'a sender that throws is treated as temporary trouble: attempts 1');
assertTrue($eqrResult['stoppedEarly'], 'a sender that throws is treated as temporary trouble: the run was stopped early');
assertEquals('temporary_problem', $eqrResult['stopReason'], 'a sender that throws is treated as temporary trouble: stopReason temporary_problem');

// --- stuck recovery -------------------------------------------------------------------
$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['status' => 'sending', 'updatedAt' => $eqrStore->now - 660]);
$eqrResult = $eqrRun($eqrStore, 'A');
$eqrLabel  = 'stuck recovery: an email left "sending" for over 10 minutes is put back with one attempt used, and not re-sent in the same run';
assertEquals(1, $eqrResult['recovered'], $eqrLabel . ': recovered 1');
assertEquals('queued', $eqrStore->rows[1]['status'], $eqrLabel . ': queued');
assertEquals(1, $eqrStore->rows[1]['attempts'], $eqrLabel . ': attempts 1');
assertEquals($eqrStore->now + 120, $eqrStore->rows[1]['nextAttemptAt'], $eqrLabel . ': retry in 2 minutes');
assertEquals(0, count($eqrStore->sends), $eqrLabel . ': sender not called');
assertEquals(CUERCODE_EMAIL_QUEUE_NOTE_STUCK_RETRY, $eqrStore->rows[1]['lastError'], $eqrLabel . ': lastError is the recovery note');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['status' => 'sending', 'updatedAt' => $eqrStore->now - 300]);
$eqrRowsBefore = $eqrStore->rows;
$eqrResult     = $eqrRun($eqrStore, 'A');
assertEquals($eqrRowsBefore, $eqrStore->rows, 'an email "sending" for under 10 minutes is left alone (a live run may still be sending it)');
assertEquals(0, $eqrResult['recovered'], 'an email "sending" for under 10 minutes is left alone (a live run may still be sending it): recovered 0');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['status' => 'sending', 'updatedAt' => $eqrStore->now - 660, 'attempts' => 4]);
$eqrResult = $eqrRun($eqrStore, 'A');
assertEquals('failed', $eqrStore->rows[1]['status'], 'a stuck email on its last attempt is given up, not re-sent: failed');
assertEquals(5, $eqrStore->rows[1]['attempts'], 'a stuck email on its last attempt is given up, not re-sent: attempts 5');
assertEquals(1, $eqrResult['gaveUp'], 'a stuck email on its last attempt is given up, not re-sent: gaveUp 1');
assertEquals(CUERCODE_EMAIL_QUEUE_NOTE_STUCK_GAVE_UP, $eqrStore->rows[1]['lastError'], 'a stuck email on its last attempt is given up, not re-sent: lastError is the give-up note');
assertEquals(0, count($eqrStore->sends), 'a stuck email on its last attempt is given up, not re-sent: sender not called');

// --- database and lock problems --------------------------------------------------------
$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1);
$eqrStore->failOps['findDue'] = true;
$eqrResult                    = $eqrRun($eqrStore, 'A');
assertEquals('db_error', $eqrResult['status'], 'a database error while listing emails is reported, not mistaken for an empty queue: status db_error');
assertEquals(3, emailQueueRunnerExitCode($eqrResult), 'a database error while listing emails is reported, not mistaken for an empty queue: exit code 3');
assertEquals(null, $eqrStore->lockHolder, 'a database error while listing emails is reported, not mistaken for an empty queue: lock released');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1);
$eqrStore->failOps['markSent'] = 'throw';
$eqrResult                     = $eqrRun($eqrStore, 'A');
assertEquals('db_error', $eqrResult['status'], 'the lock is always released, even after an unexpected error: status db_error');
assertEquals(null, $eqrStore->lockHolder, 'the lock is always released, even after an unexpected error: lockHolder null afterwards');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1);
$eqrStore->failOps['lock'] = true;
$eqrResult                 = $eqrRun($eqrStore, 'A');
assertEquals('lock_error', $eqrResult['status'], 'lock error is reported as a database problem: lock_error');
assertEquals(3, emailQueueRunnerExitCode($eqrResult), 'lock error is reported as a database problem: exit code 3');
assertEquals(0, $eqrStore->claimCalls, 'lock error is reported as a database problem: nothing claimed');

$eqrStore             = $eqrNewStore();
$eqrStore->lockHolder = 'someone-else';
$eqrAddRow($eqrStore, 1, ['nextAttemptAt' => $eqrStore->now - 660]);
$eqrResult = $eqrRun($eqrStore, 'A');
assertEquals('stalled', $eqrResult['status'], 'another run holding the lock while emails are overdue is reported as stalled: stalled');
assertEquals(4, emailQueueRunnerExitCode($eqrResult), 'another run holding the lock while emails are overdue is reported as stalled: exit code 4');
assertContains('stuck', (string) $eqrResult['problem'], 'another run holding the lock while emails are overdue is reported as stalled: the problem mentions stuck');
assertEquals('someone-else', $eqrStore->lockHolder, "another run holding the lock while emails are overdue is reported as stalled: the other run's lock is never released by us");
assertEquals(0, $eqrStore->claimCalls, 'another run holding the lock while emails are overdue is reported as stalled: nothing claimed');

$eqrStore             = $eqrNewStore();
$eqrStore->lockHolder = 'someone-else';
$eqrAddRow($eqrStore, 1);
$eqrResult = $eqrRun($eqrStore, 'A');
assertEquals('busy', $eqrResult['status'], 'another run holding the lock with no overdue emails is just busy');
assertEquals(0, emailQueueRunnerExitCode($eqrResult), 'another run holding the lock with no overdue emails is just busy: exit code 0');

// --- time budget and batch limits -------------------------------------------------------
$eqrStore                 = $eqrNewStore();
$eqrStore->secondsPerSend = 30;

for ($eqrId = 1; $eqrId <= 5; $eqrId++) {
    $eqrAddRow($eqrStore, $eqrId);
}

$eqrResult = $eqrRun($eqrStore, 'A');
assertEquals(2, $eqrResult['sent'], 'time budget: stops claiming new emails once 45 seconds are used: 2 sent');
assertTrue($eqrResult['stoppedEarly'], 'time budget: stops claiming new emails once 45 seconds are used: stoppedEarly');
assertEquals('time_budget', $eqrResult['stopReason'], "time budget: stops claiming new emails once 45 seconds are used: stopReason 'time_budget'");

$eqrStore = $eqrNewStore();

for ($eqrId = 1; $eqrId <= 60; $eqrId++) {
    $eqrAddRow($eqrStore, $eqrId);
}

assertEquals(5, $eqrRun($eqrStore, 'A', ['limit' => 5])['sent'], 'batch limit respected and clamped: limit 5 -> 5 sent');
assertEquals(50, $eqrRun($eqrStore, 'A', ['limit' => 500])['sent'], 'batch limit respected and clamped: limit 500 -> 50 sent');
assertEquals(1, $eqrRun($eqrStore, 'A', ['limit' => 0])['sent'], 'batch limit respected and clamped: limit 0 -> 1 sent');

// --- result could not be recorded ---------------------------------------------------------
$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1);
$eqrStore->failOps['markSent'] = true;
$eqrResult                     = $eqrRun($eqrStore, 'A');
$eqrReport                     = strtolower(implode(' ', formatEmailQueueRunReport($eqrResult, 0.5)));
assertEquals(1, $eqrResult['sent'], 'result could not be recorded is counted and reported: sent 1');
assertEquals(1, $eqrResult['recordFailures'], 'result could not be recorded is counted and reported: recordFailures 1');
assertContains('could not record', $eqrReport, 'result could not be recorded is counted and reported: the report says could not record');

// ==============================================================================
// ✅ Section 3: emailQueueRunnerMain() with stand-ins
// ==============================================================================

/** Collects what the runner prints on each stream. */
$eqrNewIo = static function (): \stdClass {
    $eqrIo      = new \stdClass();
    $eqrIo->out = [];
    $eqrIo->err = [];

    return $eqrIo;
};

/** Run the runner's main with stand-ins; $eqrDbProblem simulates an unreachable database. */
$eqrMain = static function (\stdClass $eqrStore, \stdClass $eqrIo, array $eqrArgv, array $eqrSettings, ?string $eqrDbProblem = null) use ($eqrMakeGateway, $eqrMakeSender, $eqrSettingsGetter): int {
    $eqrEnvironment = [
        'dbCheck'    => static function () use ($eqrDbProblem): ?string {
            return $eqrDbProblem;
        },
        'getSetting' => $eqrSettingsGetter($eqrSettings),
        'gateway'    => $eqrMakeGateway($eqrStore, 'cli'),
        'sender'     => $eqrMakeSender($eqrStore, 'cli'),
        'out'        => static function (string $eqrLine) use ($eqrIo): void {
            $eqrIo->out[] = $eqrLine;
        },
        'err'        => static function (string $eqrLine) use ($eqrIo): void {
            $eqrIo->err[] = $eqrLine;
        },
        'clock'      => static function () use ($eqrStore): float {
            return (float) $eqrStore->runClock;
        },
    ];

    return emailQueueRunnerMain(parseEmailQueueRunnerArgs(array_merge(['process_email_queue.php'], $eqrArgv)), $eqrEnvironment);
};

$eqrStore = $eqrNewStore();
$eqrIo    = $eqrNewIo();
$eqrCode  = $eqrMain($eqrStore, $eqrIo, ['--help'], $eqrReadySettings);
assertEquals(0, $eqrCode, '--help prints usage to the normal stream and exits 0: exit 0');
assertTrue(in_array('Usage:', $eqrIo->out, true), '--help prints usage to the normal stream and exits 0: usage on the normal stream');
assertEquals([], $eqrIo->err, '--help prints usage to the normal stream and exits 0: nothing on the error stream');

$eqrStore = $eqrNewStore();
$eqrIo    = $eqrNewIo();
$eqrCode  = $eqrMain($eqrStore, $eqrIo, ['--nope'], $eqrReadySettings);
assertEquals(2, $eqrCode, 'a bad option exits 2 with the reason and usage on the error stream: exit 2');
assertContains('unknown option', (string) $eqrIo->err[0], 'a bad option exits 2 with the reason and usage on the error stream: the reason first');
assertTrue(in_array('Usage:', $eqrIo->err, true), 'a bad option exits 2 with the reason and usage on the error stream: usage on the error stream');
assertEquals([], $eqrIo->out, 'a bad option exits 2 with the reason and usage on the error stream: nothing on the normal stream');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1);
$eqrIo   = $eqrNewIo();
$eqrCode = $eqrMain($eqrStore, $eqrIo, [], $eqrReadySettings, 'could not reach the database or the email queue table (details are in the error log)');
assertEquals(3, $eqrCode, 'database unreachable: exit 3, one clear line on the error stream, nothing sent: exit 3');
assertEquals(1, count($eqrIo->err), 'database unreachable: exit 3, one clear line on the error stream, nothing sent: one line');
assertContains('could not reach the database', (string) $eqrIo->err[0], 'database unreachable: exit 3, one clear line on the error stream, nothing sent: the line says why');
assertEquals(0, count($eqrStore->sends) + $eqrStore->claimCalls, 'database unreachable: exit 3, one clear line on the error stream, nothing sent: nothing claimed or sent');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1);
$eqrAddRow($eqrStore, 2);
$eqrIo   = $eqrNewIo();
$eqrCode = $eqrMain($eqrStore, $eqrIo, ['--quiet'], $eqrOffSettings);
assertEquals(0, $eqrCode, 'email switched off, quiet: exit 0, prints nothing, claims nothing: exit 0');
assertEquals([], array_merge($eqrIo->out, $eqrIo->err), 'email switched off, quiet: exit 0, prints nothing, claims nothing: prints nothing');
assertEquals(0, $eqrStore->claimCalls, 'email switched off, quiet: exit 0, prints nothing, claims nothing: claims nothing');

$eqrIo   = $eqrNewIo();
$eqrCode = $eqrMain($eqrStore, $eqrIo, [], $eqrOffSettings);
assertEquals(0, $eqrCode, 'email switched off, normal: says it is switched off and how many are waiting: exit 0');
assertContains('switched off', implode(' ', $eqrIo->out), 'email switched off, normal: says it is switched off and how many are waiting: says switched off');
assertContains('2 email(s) are waiting', implode(' ', $eqrIo->out), 'email switched off, normal: says it is switched off and how many are waiting: says how many');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1);
$eqrIo   = $eqrNewIo();
$eqrCode = $eqrMain($eqrStore, $eqrIo, [], $eqrGappySettings);
$eqrText = implode(' ', array_merge($eqrIo->out, $eqrIo->err));
assertEquals(4, $eqrCode, 'email on but settings incomplete: exit 4, names the empty settings, never their values: exit 4');
assertContains('graph_client_secret', implode(' ', $eqrIo->err), 'email on but settings incomplete: exit 4, names the empty settings, never their values: names the setting');
assertFalse(str_contains($eqrText, 'tenant-value-123') || str_contains($eqrText, 'client-value-456'), 'email on but settings incomplete: exit 4, names the empty settings, never their values: no values');
assertEquals(0, $eqrStore->claimCalls, 'email on but settings incomplete: exit 4, names the empty settings, never their values: nothing claimed');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['toAddress' => 'alice@example.com', 'toName' => 'Alice Example']);
$eqrAddRow($eqrStore, 2, ['toAddress' => 'bob@example.com', 'toName' => 'Bob Example']);
$eqrIo    = $eqrNewIo();
$eqrCode  = $eqrMain($eqrStore, $eqrIo, ['--limit=5'], $eqrReadySettings);
$eqrLines = array_merge($eqrIo->out, $eqrIo->err);
$eqrText  = implode(' ', $eqrLines);
assertEquals(0, $eqrCode, 'normal run prints one summary line with counts and no addresses or subjects: exit 0');
assertEquals(1, count($eqrIo->out), 'normal run prints one summary line with counts and no addresses or subjects: one line');
assertContains('sent 2', $eqrText, 'normal run prints one summary line with counts and no addresses or subjects: counts');
assertFalse(str_contains($eqrText, '@'), 'normal run prints one summary line with counts and no addresses or subjects: no @ in any line');
assertFalse(str_contains($eqrText, 'Verify your CueRCode'), 'normal run prints one summary line with counts and no addresses or subjects: no subject');
assertFalse(str_contains($eqrText, 'Alice') || str_contains($eqrText, 'token=T'), 'normal run prints one summary line with counts and no addresses or subjects: no names or links');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1);
$eqrIo   = $eqrNewIo();
$eqrCode = $eqrMain($eqrStore, $eqrIo, ['--quiet'], $eqrReadySettings);
assertEquals(0, $eqrCode, 'quiet successful run prints nothing: exit 0');
assertEquals([], array_merge($eqrIo->out, $eqrIo->err), 'quiet successful run prints nothing: nothing printed');
assertEquals(1, count($eqrStore->sends), 'quiet successful run prints nothing: the email was still sent');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['attempts' => 4]);
$eqrStore->sendScript['user1@example.com'] = [[false, 'HTTP 400 | graph_error_code: ErrorInvalidRecipients']];
$eqrIo                                     = $eqrNewIo();
$eqrCode                                   = $eqrMain($eqrStore, $eqrIo, ['--quiet'], $eqrReadySettings);
assertEquals(5, $eqrCode, 'giving up on an email exits 5 and is reported on the error stream even when quiet: exit 5');
assertContains('Gave up on 1', implode(' ', $eqrIo->err), 'giving up on an email exits 5 and is reported on the error stream even when quiet: on the error stream');
assertEquals([], $eqrIo->out, 'giving up on an email exits 5 and is reported on the error stream even when quiet: nothing on the normal stream');

$eqrStore             = $eqrNewStore();
$eqrStore->lockHolder = 'other-run';
$eqrAddRow($eqrStore, 1);
$eqrIo   = $eqrNewIo();
$eqrCode = $eqrMain($eqrStore, $eqrIo, ['--quiet'], $eqrReadySettings);
assertEquals(0, $eqrCode, 'another run busy, quiet: exit 0, prints nothing: exit 0');
assertEquals([], array_merge($eqrIo->out, $eqrIo->err), 'another run busy, quiet: exit 0, prints nothing: nothing printed');

$eqrStore             = $eqrNewStore();
$eqrStore->lockHolder = 'other-run';
$eqrAddRow($eqrStore, 1, ['nextAttemptAt' => $eqrStore->now - 660]);
$eqrIo   = $eqrNewIo();
$eqrCode = $eqrMain($eqrStore, $eqrIo, ['--quiet'], $eqrReadySettings);
assertEquals(4, $eqrCode, 'stalled: exit 4, reported on the error stream: exit 4');
assertContains('stuck', implode(' ', $eqrIo->err), 'stalled: exit 4, reported on the error stream: on the error stream');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1);
$eqrStore->sendScript['user1@example.com'] = [[false, 'skipped: no Graph access token available']];
$eqrIo                                     = $eqrNewIo();
$eqrCode                                   = $eqrMain($eqrStore, $eqrIo, ['--quiet'], $eqrReadySettings);
assertEquals(4, $eqrCode, 'sending blocked: exit 4, reported on the error stream, attempts unchanged: exit 4');
assertContains('blocked', implode(' ', $eqrIo->err), 'sending blocked: exit 4, reported on the error stream, attempts unchanged: on the error stream');
assertEquals(0, $eqrStore->rows[1]['attempts'], 'sending blocked: exit 4, reported on the error stream, attempts unchanged: attempts 0');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['status' => 'sending', 'updatedAt' => $eqrStore->now - 660]);
$eqrIo   = $eqrNewIo();
$eqrCode = $eqrMain($eqrStore, $eqrIo, ['--quiet'], $eqrReadySettings);
assertEquals(0, $eqrCode, 'stuck emails put back are mentioned even when quiet (exit 0): exit 0');
assertContains('Put back 1', implode(' ', $eqrIo->err), 'stuck emails put back are mentioned even when quiet (exit 0): mentioned on the error stream');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1);
$eqrAddRow($eqrStore, 2);
$eqrAddRow($eqrStore, 3, ['status' => 'sent']);
$eqrIo   = $eqrNewIo();
$eqrCode = $eqrMain($eqrStore, $eqrIo, ['--check', '--quiet'], $eqrReadySettings);
$eqrText = implode(' ', $eqrIo->out);
assertEquals(0, $eqrCode, '--check reports settings and queue counts and sends nothing: exit 0');
assertContains('setup check', $eqrText, '--check reports settings and queue counts and sends nothing: says it is a check');
assertContains('Queue: 2 due now; 2 queued in total; 0 being sent; 1 sent; 0 given up.', $eqrText, '--check reports settings and queue counts and sends nothing: queue counts');
assertEquals(0, count($eqrStore->sends), '--check reports settings and queue counts and sends nothing: sender never called');
assertEquals(0, $eqrStore->claimCalls, '--check reports settings and queue counts and sends nothing: claimCalls 0');

$eqrStore = $eqrNewStore();
$eqrIo    = $eqrNewIo();
$eqrCode  = $eqrMain($eqrStore, $eqrIo, ['--check'], $eqrGappySettings);
assertEquals(4, $eqrCode, '--check with incomplete settings exits 4');
assertContains('NOT ready', implode(' ', $eqrIo->err), '--check with incomplete settings exits 4: explained on the error stream');

assertThrows(
    static function (): void {
        emailQueueRunnerMain(parseEmailQueueRunnerArgs(['x']), ['out' => 'strlen']);
    },
    'emailQueueRunnerMain throws InvalidArgumentException when its environment is incomplete (a programming error)',
    \InvalidArgumentException::class
);

// ==============================================================================
// ✅ Section 4: source-text regression guards
// ==============================================================================
// Each function is cut out from "\nfunction name(" to the first "\n}\n" after
// it (the function's own closing brace at the start of a line), so a phrase in
// the NEXT function's docblock can never make a check pass by accident.
// ==============================================================================

$eqrEmailSource  = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_email.php');
$eqrFunctionBody = static function (string $eqrSource, string $eqrName): string {
    $eqrStart = strpos($eqrSource, "\nfunction " . $eqrName . '(');

    if ($eqrStart === false) {
        return '';
    }

    $eqrEnd = strpos($eqrSource, "\n}\n", $eqrStart);

    if ($eqrEnd === false) {
        return '';
    }

    return substr($eqrSource, $eqrStart, ($eqrEnd - $eqrStart) + 3);
};

$eqrClaimBody = $eqrFunctionBody($eqrEmailSource, 'claimEmailQueueRow');
assertContains("`status` IN ('queued', 'failed')", $eqrClaimBody, 'claim guard: the claim re-checks the status');
assertContains('`nextAttemptAt` <= NOW()', $eqrClaimBody, 'claim guard: the claim re-checks that the row is due');
assertContains('`attempts` < `maxAttempts`', $eqrClaimBody, 'claim guard: the claim re-checks that attempts are left');
assertContains('emailQueueChangeOneRow(', $eqrClaimBody, 'claim guard: the claim goes through the exactly-one-row helper');
assertContains('affected_rows === 1', $eqrFunctionBody($eqrEmailSource, 'emailQueueChangeOneRow'), 'claim guard: the helper only reports success when affected_rows === 1');

foreach (['settleEmailQueueSuccess', 'settleEmailQueueRetry', 'settleEmailQueueGaveUp', 'releaseEmailQueueClaim'] as $eqrSettleName) {
    assertContains("AND `status` = 'sending'", $eqrFunctionBody($eqrEmailSource, $eqrSettleName), $eqrSettleName . " only applies while the row is still 'sending'");
}

$eqrStuckBody = $eqrFunctionBody($eqrEmailSource, 'findStuckEmailQueueRows');
assertContains("`status` = 'sending'", $eqrStuckBody, "findStuckEmailQueueRows looks only at 'sending' rows");
assertContains('`updatedAt` < DATE_SUB(NOW(), INTERVAL ? MINUTE)', $eqrStuckBody, 'findStuckEmailQueueRows uses updatedAt as the claim time');

$eqrRecoverBody = $eqrFunctionBody($eqrEmailSource, 'recoverStuckEmailQueueRow');
assertContains('`attempts` = ?', $eqrRecoverBody, 'recoverStuckEmailQueueRow is guarded by the attempts value it saw');
assertContains('`updatedAt` < DATE_SUB(NOW(), INTERVAL ? MINUTE)', $eqrRecoverBody, 'recoverStuckEmailQueueRow re-checks the row is still stuck');

$eqrLockBody    = $eqrFunctionBody($eqrEmailSource, 'acquireEmailQueueRunLock');
$eqrReleaseBody = $eqrFunctionBody($eqrEmailSource, 'releaseEmailQueueRunLock');
assertContains('GET_LOCK(', $eqrLockBody, 'acquireEmailQueueRunLock calls GET_LOCK');
assertContains("', 0)", $eqrLockBody, 'acquireEmailQueueRunLock never waits (timeout 0)');
assertContains('CUERCODE_EMAIL_QUEUE_LOCK_NAME_SQL', $eqrLockBody, 'acquireEmailQueueRunLock uses the per-database lock name');
assertContains('RELEASE_LOCK(', $eqrReleaseBody, 'releaseEmailQueueRunLock calls RELEASE_LOCK');
assertContains('CUERCODE_EMAIL_QUEUE_LOCK_NAME_SQL', $eqrReleaseBody, 'releaseEmailQueueRunLock uses the per-database lock name');
assertContains('DATABASE()', CUERCODE_EMAIL_QUEUE_LOCK_NAME_SQL, 'the lock name includes this database (lock names are shared by every database on a MySQL server)');
assertTrue(strlen(CUERCODE_EMAIL_QUEUE_LOCK_PREFIX) + 32 <= 64, 'the lock name fits MySQL\'s 64-character limit');

$eqrProcessBody = $eqrFunctionBody($eqrEmailSource, 'processEmailQueue');
assertContains('runEmailQueueBatch(', $eqrProcessBody, 'processEmailQueue is a wrapper over runEmailQueueBatch');
assertContains('emailQueueDatabaseGateway()', $eqrProcessBody, 'processEmailQueue uses the real database gateway');

$eqrShutdownBody     = $eqrFunctionBody($eqrEmailSource, 'maybeProcessEmailQueueOnShutdown');
$eqrAutoProcessPos   = strpos($eqrShutdownBody, 'email_queue_autoprocess');
$eqrEmailEnabledPos  = strpos($eqrShutdownBody, 'email_enabled');
$eqrFastcgiGuardPos  = strpos($eqrShutdownBody, "if (function_exists('fastcgi_finish_request')) {");
$eqrFastcgiCallPos   = strpos($eqrShutdownBody, 'fastcgi_finish_request();');
assertTrue($eqrAutoProcessPos !== false && $eqrEmailEnabledPos !== false && $eqrAutoProcessPos < $eqrEmailEnabledPos, 'maybeProcessEmailQueueOnShutdown reads email_queue_autoprocess before email_enabled');
assertContains('session_write_close()', $eqrShutdownBody, 'maybeProcessEmailQueueOnShutdown releases the session lock before sending');
assertTrue($eqrFastcgiGuardPos !== false && $eqrFastcgiCallPos !== false && $eqrFastcgiCallPos > $eqrFastcgiGuardPos && ($eqrFastcgiCallPos - $eqrFastcgiGuardPos) < 200, 'maybeProcessEmailQueueOnShutdown calls fastcgi_finish_request() only where it exists');
assertContains('static $registered', $eqrShutdownBody, 'maybeProcessEmailQueueOnShutdown registers at most once per request');

// --- the command-line runner file ------------------------------------------------------
$eqrCliPath = CUERCODE_SITE_ROOT . '_includes' . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'process_email_queue.php';
assertTrue(is_file($eqrCliPath), 'the command-line runner exists at _includes/cron/process_email_queue.php');
$eqrCliSource     = (string) file_get_contents($eqrCliPath);
$eqrGuardPos      = strpos($eqrCliSource, "if (PHP_SAPI !== 'cli')");
$eqrFirstRequire  = strpos($eqrCliSource, 'require_once');
$eqrBootstrapPos  = strpos($eqrCliSource, "'bootstrap.php'");
$eqrCouldNotStart = strpos($eqrCliSource, "'CueRCode email queue: could not start");
$eqrReadTimeout   = strpos($eqrCliSource, "ini_set('mysqlnd.net_read_timeout'");
assertTrue($eqrGuardPos !== false && $eqrFirstRequire !== false && $eqrGuardPos < $eqrFirstRequire, 'the runner refuses non-command-line runs before it loads anything');
assertTrue($eqrCouldNotStart !== false && $eqrBootstrapPos !== false && $eqrCouldNotStart < $eqrBootstrapPos, 'the runner sets up its "could not start" message before loading the start-up code');
assertTrue($eqrReadTimeout !== false && $eqrBootstrapPos !== false && $eqrReadTimeout < $eqrBootstrapPos, 'the runner shortens the database read timeout before connecting');
assertContains('set_exception_handler(', $eqrCliSource, 'the runner replaces the HTML crash page with a plain-text one');
assertFalse(str_contains($eqrCliSource, 'toAddress') || str_contains($eqrCliSource, 'paramsJSON'), 'the runner file never touches row fields itself');
assertFalse(str_contains($eqrCliSource, 'curl_close('), 'the runner file does not call curl_close()');
assertEquals(0, preg_match('/\bmail\s*\(/', $eqrCliSource), "the runner file does not call PHP's mail()");

$eqrBootstrapSource = (string) file_get_contents(CUERCODE_INCLUDES_DIR . 'bootstrap.php');
$eqrHookCallPos     = strpos($eqrBootstrapSource, 'maybeProcessEmailQueueOnShutdown();');
assertTrue($eqrHookCallPos !== false, 'bootstrap.php calls maybeProcessEmailQueueOnShutdown()');

if ($eqrHookCallPos === false) {
    $eqrHookCallPos = 0;
}

$eqrWindowStart = max(0, $eqrHookCallPos - 300);
assertContains("php_sapi_name() !== 'cli'", substr($eqrBootstrapSource, $eqrWindowStart, $eqrHookCallPos - $eqrWindowStart), 'bootstrap.php only calls the page-shutdown hook for web requests');

// ==============================================================================
// ✅ Section 5: the real runner file, as a child process (--help / bad options ONLY)
// ==============================================================================
// ⚠️ NEVER add a case here without --help or an invalid option: a plain run on
//    a machine with a real _auth_keys/auth_creds.php could send real email.
// The command is an ARRAY, so the spaces and "&" in the repository path are
// passed safely without a shell.
// @see https://www.php.net/manual/en/function.proc-open.php
// ==============================================================================

$eqrRunCli = static function (array $eqrCliArguments) use ($eqrCliPath): array {
    $eqrDescriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $eqrProcess = proc_open(array_merge([PHP_BINARY, $eqrCliPath], $eqrCliArguments), $eqrDescriptors, $eqrPipes);

    if (!is_resource($eqrProcess)) {
        return ['exit' => -1, 'out' => '', 'err' => ''];
    }

    fclose($eqrPipes[0]);
    $eqrOut = (string) stream_get_contents($eqrPipes[1]);
    fclose($eqrPipes[1]);
    $eqrErr = (string) stream_get_contents($eqrPipes[2]);
    fclose($eqrPipes[2]);

    return ['exit' => proc_close($eqrProcess), 'out' => $eqrOut, 'err' => $eqrErr];
};

$eqrCli = $eqrRunCli(['--help']);
assertEquals(0, $eqrCli['exit'], 'real runner --help: exit 0');
assertContains('Usage', $eqrCli['out'], 'real runner --help: usage on stdout');
assertEquals('', $eqrCli['err'], 'real runner --help: stderr is empty');

$eqrCli = $eqrRunCli(['--limit=0']);
assertEquals(2, $eqrCli['exit'], 'real runner --limit=0: exit 2');
assertContains('--limit', $eqrCli['err'], 'real runner --limit=0: the reason is on stderr');

$eqrCli = $eqrRunCli(['--frobnicate']);
assertEquals(2, $eqrCli['exit'], 'real runner --frobnicate: exit 2');
assertContains('unknown option', $eqrCli['err'], 'real runner --frobnicate: stderr says unknown option');

// ==============================================================================
// ✅ Section 6: #122 / #123 - clean-up, expired links, and the "never send" guard
// ==============================================================================
// Same in-memory stand-in as Sections 2-3. A row's age is set through its
// createdAt, relative to the simulated database clock ($eqrStore->now), which
// is exactly what the real SQL compares against NOW().
// ==============================================================================

/** One day in seconds, for readability. */
$eqrDay = 86400;

// --- parseEmailQueueRetentionDays() ------------------------------------------------
$eqrRetentionCases = [
    [30, 30],
    ['45', 45],
    [' 7 ', 7],
    [3650, 3650],
    [0, null],
    [-1, null],
    [3651, null],
    ['abc', null],
    ['', null],
    [null, null],
    [true, null],
    [30.0, null],
    ['1e3', null],
];

foreach ($eqrRetentionCases as $eqrCase) {
    assertEquals(
        $eqrCase[1],
        parseEmailQueueRetentionDays($eqrCase[0]),
        'parseEmailQueueRetentionDays: ' . var_export($eqrCase[0], true) . ' -> ' . var_export($eqrCase[1], true)
    );
}

// --- emailQueueHousekeepingOptions() ---------------------------------------------------
assertEquals(
    ['retentionDays' => 30, 'givenUpRetentionDays' => 90, 'invalidSettings' => []],
    emailQueueHousekeepingOptions($eqrSettingsGetter([])),
    'emailQueueHousekeepingOptions: missing settings -> the defaults, nothing reported'
);
assertEquals(
    ['retentionDays' => 45, 'givenUpRetentionDays' => 120, 'invalidSettings' => []],
    emailQueueHousekeepingOptions($eqrSettingsGetter(['email_queue_retention_days' => '45', 'email_queue_failed_retention_days' => 120])),
    'emailQueueHousekeepingOptions: valid values are used'
);
assertEquals(
    ['retentionDays' => 30, 'givenUpRetentionDays' => 90, 'invalidSettings' => ['email_queue_retention_days', 'email_queue_failed_retention_days']],
    emailQueueHousekeepingOptions($eqrSettingsGetter(['email_queue_retention_days' => 0, 'email_queue_failed_retention_days' => 'thirty'])),
    'emailQueueHousekeepingOptions: 0 / "thirty" -> the default, and the setting is named'
);
$eqrHousekeepingBad = emailQueueHousekeepingOptions($eqrSettingsGetter(['email_queue_retention_days' => 'retention-value-777']));
assertFalse(str_contains((string) json_encode($eqrHousekeepingBad), 'retention-value-777'), 'emailQueueHousekeepingOptions: an invalid value is never included in the result');
assertEquals(
    ['retentionDays' => 30, 'givenUpRetentionDays' => 90, 'invalidSettings' => []],
    emailQueueHousekeepingOptions(static function (string $eqrKey, mixed $eqrDefault = null): mixed {
        throw new \RuntimeException('simulated settings failure');
    }),
    'emailQueueHousekeepingOptions: a settings reader that throws -> the defaults'
);

// --- link lifetimes and the expiry rule --------------------------------------------------
assertEquals(
    ['verification' => CUERCODE_EMAIL_VERIFICATION_TTL_HOURS * 3600, 'password_reset' => CUERCODE_PASSWORD_RESET_TTL_MINUTES * 60],
    emailQueueLinkLifetimes(),
    "emailQueueLinkLifetimes() reads fn_auth.php's constants"
);
assertEquals(['verification' => 86400, 'password_reset' => 3600], emailQueueLinkLifetimes(), "emailQueueLinkLifetimes() reads fn_auth.php's constants: today's lifetimes are 24 hours and 60 minutes");
assertEquals(
    ['verification' => 86400],
    normaliseEmailQueueLinkLifetimes(['verification' => 86400, 'nope' => 5, 'password_reset' => 0, 'receipt' => '60']),
    'normaliseEmailQueueLinkLifetimes drops unknown templates and bad values'
);
assertEquals(86280, emailQueueLinkMaxAgeSeconds(86400), 'emailQueueLinkMaxAgeSeconds: 86400 -> 86280 (2-minute margin)');
assertEquals(3480, emailQueueLinkMaxAgeSeconds(3600), 'emailQueueLinkMaxAgeSeconds: 3600 -> 3480 (2-minute margin)');
assertEquals(60, emailQueueLinkMaxAgeSeconds(100), 'emailQueueLinkMaxAgeSeconds: 100 -> 60 (never below 60 seconds)');

$eqrLifetimes = ['verification' => 86400, 'password_reset' => 3600];
assertTrue(emailQueueLinkHasExpired('verification', 86280, $eqrLifetimes), 'emailQueueLinkHasExpired: verification at 86280 seconds -> expired');
assertFalse(emailQueueLinkHasExpired('verification', 86279, $eqrLifetimes), 'emailQueueLinkHasExpired: verification at 86279 seconds -> not expired');
assertTrue(emailQueueLinkHasExpired('password_reset', 3480, $eqrLifetimes), 'emailQueueLinkHasExpired: password_reset at 3480 seconds -> expired');
assertFalse(emailQueueLinkHasExpired('password_reset', 3479, $eqrLifetimes), 'emailQueueLinkHasExpired: password_reset at 3479 seconds -> not expired');
assertFalse(emailQueueLinkHasExpired('receipt', 999999, $eqrLifetimes), 'emailQueueLinkHasExpired: receipt (no link lifetime) never expires');
assertFalse(emailQueueLinkHasExpired('verification', 999999, []), 'emailQueueLinkHasExpired: an empty lifetimes map never expires anything');

foreach ([['verification', 'verificationUrl'], ['password_reset', 'resetUrl'], ['invitation', 'inviteUrl'], ['receipt', null], ['nope', null]] as $eqrCase) {
    assertEquals($eqrCase[1], emailQueueRequiredLinkParam($eqrCase[0]), 'emailQueueRequiredLinkParam: ' . $eqrCase[0] . ' -> ' . var_export($eqrCase[1], true));
}

// --- the result, exit code and report ------------------------------------------------------
$eqrEmptyResult  = emptyEmailQueueRunResult();
$eqrNewKeysZero  = true;

foreach (['expired', 'unsendable', 'detailsCleared', 'purged', 'housekeepingFailures'] as $eqrNewKey) {
    if (!array_key_exists($eqrNewKey, $eqrEmptyResult) || $eqrEmptyResult[$eqrNewKey] !== 0) {
        $eqrNewKeysZero = false;
    }
}

assertTrue($eqrNewKeysZero, 'emptyEmailQueueRunResult has the five new keys, each 0');

$eqrCaseResult                         = emptyEmailQueueRunResult();
$eqrCaseResult['housekeepingFailures'] = 1;
assertEquals(3, emailQueueRunnerExitCode($eqrCaseResult), 'emailQueueRunnerExitCode: ok + a failed clean-up step -> 3');
$eqrCaseResult['status'] = 'system_problem';
assertEquals(3, emailQueueRunnerExitCode($eqrCaseResult), 'emailQueueRunnerExitCode: system_problem + a failed clean-up step -> 3 (database outranks set-up)');
$eqrCaseResult            = emptyEmailQueueRunResult();
$eqrCaseResult['expired'] = 3;
assertEquals(0, emailQueueRunnerExitCode($eqrCaseResult), 'emailQueueRunnerExitCode: ok + 3 expired links skipped -> 0 (reported, not an error)');

$eqrCaseResult            = emptyEmailQueueRunResult();
$eqrCaseResult['expired'] = 2;
$eqrTidyText              = implode(' ', formatEmailQueueTidyLines($eqrCaseResult));
assertContains('Skipped 2', $eqrTidyText, 'formatEmailQueueTidyLines: expired 2 -> says Skipped 2');
assertContains('new link', $eqrTidyText, 'formatEmailQueueTidyLines: expired 2 -> says the person needs a new link');
$eqrCaseResult                   = emptyEmailQueueRunResult();
$eqrCaseResult['purged']         = 3;
$eqrCaseResult['detailsCleared'] = 1;
$eqrTidyText                     = implode(' ', formatEmailQueueTidyLines($eqrCaseResult));
assertContains('deleted 3', $eqrTidyText, 'formatEmailQueueTidyLines: purged 3 -> says deleted 3');
assertContains('details of 1', $eqrTidyText, 'formatEmailQueueTidyLines: detailsCleared 1 -> says details of 1');
$eqrCaseResult                         = emptyEmailQueueRunResult();
$eqrCaseResult['housekeepingFailures'] = 1;
$eqrTidyText                           = implode(' ', formatEmailQueueTidyLines($eqrCaseResult));
assertContains('Could not finish tidying up', $eqrTidyText, 'formatEmailQueueTidyLines: a failed step -> says it could not finish tidying up');
assertContains('Sending was not affected', $eqrTidyText, 'formatEmailQueueTidyLines: a failed step -> says sending was not affected');
assertEquals([], formatEmailQueueTidyLines(emptyEmailQueueRunResult()), 'formatEmailQueueTidyLines: all zero -> no lines');
assertEquals([], formatEmailQueueTidyLines(['status' => 'ok']), 'formatEmailQueueTidyLines: an older-shaped result without the new keys -> no lines, no crash');

// --- #122: the details (which can hold the one-time link) --------------------------------------
$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1);
$eqrResult = $eqrRun($eqrStore, 'A');
$eqrLabel  = 'details are cleared in the same step that marks an email sent';
assertEquals('sent', $eqrStore->rows[1]['status'], $eqrLabel . ': sent');
assertEquals(null, $eqrStore->rows[1]['paramsJSON'], $eqrLabel . ': paramsJSON is null afterwards');
assertContains('token=T1', (string) $eqrStore->sends[0]['body'], $eqrLabel . ': the email that went out still contained the link');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1);
$eqrParamsBefore                           = $eqrStore->rows[1]['paramsJSON'];
$eqrStore->sendScript['user1@example.com'] = [[false, 'HTTP 400 | graph_error_code: ErrorInvalidRecipients']];
$eqrRun($eqrStore, 'A');
$eqrLabel = 'a retry keeps the details, so the next attempt still has its link';
assertEquals('queued', $eqrStore->rows[1]['status'], $eqrLabel . ': queued for a retry');
assertEquals($eqrParamsBefore, $eqrStore->rows[1]['paramsJSON'], $eqrLabel . ': paramsJSON unchanged');
$eqrStore->now = $eqrStore->rows[1]['nextAttemptAt'];
$eqrRun($eqrStore, 'A');
assertEquals('sent', $eqrStore->rows[1]['status'], $eqrLabel . ': the retry is sent');
assertContains('token=T1', (string) $eqrStore->sends[1]['body'], $eqrLabel . ': the retry contained the link');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['attempts' => 4]);
$eqrParamsBefore                           = $eqrStore->rows[1]['paramsJSON'];
$eqrStore->sendScript['user1@example.com'] = [[false, 'HTTP 400 | graph_error_code: ErrorInvalidRecipients']];
$eqrRun($eqrStore, 'A');
assertEquals('failed', $eqrStore->rows[1]['status'], 'giving up keeps the details for the re-send window: failed');
assertEquals($eqrParamsBefore, $eqrStore->rows[1]['paramsJSON'], 'giving up keeps the details for the re-send window: paramsJSON unchanged');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1);
$eqrParamsBefore                           = $eqrStore->rows[1]['paramsJSON'];
$eqrStore->sendScript['user1@example.com'] = [[false, 'skipped: no Graph access token available']];
$eqrRun($eqrStore, 'A');
assertEquals('queued', $eqrStore->rows[1]['status'], 'sending blocked keeps the details: put back to queued');
assertEquals($eqrParamsBefore, $eqrStore->rows[1]['paramsJSON'], 'sending blocked keeps the details: paramsJSON unchanged');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['status' => 'sending', 'updatedAt' => $eqrStore->now - 660]);
$eqrParamsBefore = $eqrStore->rows[1]['paramsJSON'];
$eqrResult       = $eqrRun($eqrStore, 'A');
assertEquals(1, $eqrResult['recovered'], 'stuck recovery keeps the details: recovered 1');
assertEquals($eqrParamsBefore, $eqrStore->rows[1]['paramsJSON'], 'stuck recovery keeps the details: paramsJSON unchanged');

// --- #122: the "never send" guard ---------------------------------------------------------------
$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['paramsJSON' => null]);
$eqrResult = $eqrRun($eqrStore, 'A');
$eqrLabel  = 'a claimed email whose details were removed is skipped, never rendered or sent';
assertEquals('skipped', $eqrStore->rows[1]['status'], $eqrLabel . ': status skipped');
assertEquals(CUERCODE_EMAIL_QUEUE_NOTE_DETAILS_REMOVED, $eqrStore->rows[1]['lastError'], $eqrLabel . ': lastError explains why');
assertEquals(1, $eqrResult['unsendable'], $eqrLabel . ': unsendable 1');
assertEquals(0, count($eqrStore->sends), $eqrLabel . ': 0 sends');
assertEquals(0, emailQueueRunnerExitCode($eqrResult), $eqrLabel . ': exit code 0');
assertContains('can no longer be sent', implode(' ', formatEmailQueueRunReport($eqrResult, 0.5)), $eqrLabel . ': the report says it can no longer be sent');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['paramsJSON' => '{"recipientName":"X"}']);
$eqrAddRow($eqrStore, 2, ['paramsJSON' => '{"verificationUrl":"   "}']);
$eqrResult = $eqrRun($eqrStore, 'A');
$eqrLabel  = 'a verification email with no link is skipped rather than sent broken';
assertEquals('skipped', $eqrStore->rows[1]['status'], $eqrLabel . ': no link at all -> skipped');
assertEquals('skipped', $eqrStore->rows[2]['status'], $eqrLabel . ': a blank link -> skipped');
assertEquals(CUERCODE_EMAIL_QUEUE_NOTE_LINK_MISSING, $eqrStore->rows[1]['lastError'], $eqrLabel . ': lastError explains why');
assertEquals(CUERCODE_EMAIL_QUEUE_NOTE_LINK_MISSING, $eqrStore->rows[2]['lastError'], $eqrLabel . ': lastError explains why (blank link)');
assertEquals(2, $eqrResult['unsendable'], $eqrLabel . ': unsendable 2');
assertEquals(0, count($eqrStore->sends), $eqrLabel . ': 0 sends');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['templateKey' => 'receipt', 'subject' => 'Your CueRCode receipt', 'paramsJSON' => '{"orderNumber":"R-1","amount":"9.99"}']);
$eqrRun($eqrStore, 'A');
assertEquals('sent', $eqrStore->rows[1]['status'], 'a receipt (no link needed) is still sent with its details');
assertEquals(1, count($eqrStore->sends), 'a receipt (no link needed) is still sent with its details: 1 send');

// --- #122: given-up emails keep their details for 24 hours ------------------------------------
$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['status' => 'failed', 'attempts' => 5, 'createdAt' => $eqrStore->now - 86399, 'updatedAt' => $eqrStore->now - 86000]);
$eqrAddRow($eqrStore, 2, ['status' => 'failed', 'attempts' => 5, 'createdAt' => $eqrStore->now - 86400, 'updatedAt' => $eqrStore->now - 86000]);
$eqrResult = $eqrRun($eqrStore, 'A');
$eqrLabel  = 'given-up details are kept for 24 hours, then cleared (updatedAt kept)';
assertTrue($eqrStore->rows[1]['paramsJSON'] !== null, $eqrLabel . ': a row given up 1 second short of 24 hours keeps its details');
assertEquals(null, $eqrStore->rows[2]['paramsJSON'], $eqrLabel . ': a row queued 24 hours ago loses its details');
assertEquals($eqrStore->now - 86000, $eqrStore->rows[2]['updatedAt'], $eqrLabel . ': its updatedAt is unchanged');
assertEquals('failed', $eqrStore->rows[2]['status'], $eqrLabel . ': it is still failed');
assertEquals(1, $eqrResult['detailsCleared'], $eqrLabel . ': detailsCleared 1');

// --- #122: deleting old finished records ------------------------------------------------------
$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['status' => 'sent', 'createdAt' => $eqrStore->now - (30 * $eqrDay) - 1]);
$eqrAddRow($eqrStore, 2, ['status' => 'sent', 'createdAt' => $eqrStore->now - (30 * $eqrDay)]);
$eqrAddRow($eqrStore, 3, ['status' => 'skipped', 'createdAt' => $eqrStore->now - (40 * $eqrDay)]);
$eqrResult = $eqrRun($eqrStore, 'A');
$eqrLabel  = 'old sent and skipped records are deleted after 30 days; newer ones are kept';
assertFalse(isset($eqrStore->rows[1]), $eqrLabel . ': sent 30 days and 1 second ago -> deleted');
assertTrue(isset($eqrStore->rows[2]), $eqrLabel . ': sent exactly 30 days ago -> kept');
assertFalse(isset($eqrStore->rows[3]), $eqrLabel . ': skipped 40 days ago -> deleted');
assertEquals(2, $eqrResult['purged'], $eqrLabel . ': purged 2');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['status' => 'failed', 'attempts' => 5, 'createdAt' => $eqrStore->now - (31 * $eqrDay)]);
$eqrAddRow($eqrStore, 2, ['status' => 'failed', 'attempts' => 5, 'createdAt' => $eqrStore->now - (91 * $eqrDay)]);
$eqrRun($eqrStore, 'A');
assertTrue(isset($eqrStore->rows[1]), 'given-up records are kept for 90 days, then deleted: given up 31 days ago -> kept');
assertFalse(isset($eqrStore->rows[2]), 'given-up records are kept for 90 days, then deleted: given up 91 days ago -> deleted');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['templateKey' => 'receipt', 'paramsJSON' => '{"orderNumber":"R-1","amount":"9.99"}', 'createdAt' => $eqrStore->now - (100 * $eqrDay), 'nextAttemptAt' => $eqrStore->now + $eqrDay]);
$eqrAddRow($eqrStore, 2, ['status' => 'sending', 'updatedAt' => $eqrStore->now - 60, 'createdAt' => $eqrStore->now - (100 * $eqrDay)]);
$eqrAddRow($eqrStore, 3, ['templateKey' => 'receipt', 'paramsJSON' => '{"orderNumber":"R-3","amount":"1.00"}', 'status' => 'failed', 'attempts' => 2, 'createdAt' => $eqrStore->now - (100 * $eqrDay), 'nextAttemptAt' => $eqrStore->now + $eqrDay]);
$eqrRowsBefore = $eqrStore->rows;
$eqrRun($eqrStore, 'A');
assertEquals($eqrRowsBefore, $eqrStore->rows, 'the clean-up never deletes or changes an email that is waiting, being sent or still has attempts left');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['status' => 'sent', 'createdAt' => $eqrStore->now - (8 * $eqrDay)]);
$eqrAddRow($eqrStore, 2, ['status' => 'failed', 'attempts' => 5, 'createdAt' => $eqrStore->now - (11 * $eqrDay)]);
$eqrRun($eqrStore, 'A', ['retentionDays' => 7, 'givenUpRetentionDays' => 10]);
assertFalse(isset($eqrStore->rows[1]), 'retention options change the clean-up: retentionDays 7 deletes a record sent 8 days ago');
assertFalse(isset($eqrStore->rows[2]), 'retention options change the clean-up: givenUpRetentionDays 10 deletes a record given up 11 days ago');

foreach ([0, 'abc', -3] as $eqrBadRetention) {
    $eqrStore = $eqrNewStore();
    $eqrAddRow($eqrStore, 1, ['status' => 'sent', 'createdAt' => $eqrStore->now - (10 * $eqrDay)]);
    $eqrRun($eqrStore, 'A', ['retentionDays' => $eqrBadRetention]);
    assertTrue(isset($eqrStore->rows[1]), 'an invalid retention option falls back to the default (never "delete everything"): retentionDays ' . var_export($eqrBadRetention, true) . ' keeps a record sent 10 days ago');
}

$eqrStore = $eqrNewStore();

for ($eqrId = 1; $eqrId <= 3; $eqrId++) {
    $eqrAddRow($eqrStore, $eqrId, ['status' => 'sent', 'createdAt' => $eqrStore->now - (40 * $eqrDay)]);
}

assertEquals(2, $eqrRun($eqrStore, 'A', ['tidyLimit' => 2])['purged'], 'the clean-up is capped per run: tidyLimit 2 -> purged 2');
assertEquals(1, $eqrRun($eqrStore, 'A', ['tidyLimit' => 2])['purged'], 'the clean-up is capped per run: the next run deletes the last 1');

// --- #122: clean-up failures, and the lock ------------------------------------------------------
$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1);
$eqrStore->failOps['purgeFinished'] = true;
$eqrResult                          = $eqrRun($eqrStore, 'A');
$eqrLabel                           = 'a failing clean-up step is reported, does not stop sending, and gives exit code 3';
assertEquals(1, $eqrResult['sent'], $eqrLabel . ': sent 1');
assertEquals(1, $eqrResult['housekeepingFailures'], $eqrLabel . ': housekeepingFailures 1');
assertEquals(3, emailQueueRunnerExitCode($eqrResult), $eqrLabel . ': exit code 3');
assertContains('Could not finish tidying up', implode(' ', formatEmailQueueRunReport($eqrResult, 0.5)), $eqrLabel . ': the report says so');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1);
$eqrStore->failOps['skipExpired'] = 'throw';
$eqrResult                        = $eqrRun($eqrStore, 'A');
$eqrLabel                         = 'a clean-up step that throws is contained the same way';
assertEquals('sent', $eqrStore->rows[1]['status'], $eqrLabel . ': the due email is still sent');
assertTrue($eqrResult['housekeepingFailures'] >= 1, $eqrLabel . ': housekeepingFailures at least 1');
assertEquals('ok', $eqrResult['status'], $eqrLabel . ': status ok');
assertEquals(null, $eqrStore->lockHolder, $eqrLabel . ': the lock is released');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['status' => 'sent', 'createdAt' => $eqrStore->now - (40 * $eqrDay)]);
$eqrResult = $eqrRun($eqrStore, 'A', ['useLock' => false]);
assertTrue(isset($eqrStore->rows[1]), 'with the lock bypassed the clean-up never runs (it must never run alongside another runner): the old record is still there');
assertEquals(0, $eqrResult['purged'], 'with the lock bypassed the clean-up never runs (it must never run alongside another runner): purged 0');

// --- #123: expired links ---------------------------------------------------------------------------
$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['createdAt' => $eqrStore->now - 86280]);
$eqrAddRow($eqrStore, 2, ['createdAt' => $eqrStore->now - 86279]);
$eqrResult = $eqrRun($eqrStore, 'A', ['linkLifetimes' => ['verification' => 86400]]);
$eqrLabel  = 'expired verification link (24 hours): skipped at the boundary, sent just inside it';
assertEquals('skipped', $eqrStore->rows[1]['status'], $eqrLabel . ': A (2 minutes left) skipped');
assertEquals(null, $eqrStore->rows[1]['paramsJSON'], $eqrLabel . ': A lost its details');
assertEquals(CUERCODE_EMAIL_QUEUE_NOTE_LINK_EXPIRED, $eqrStore->rows[1]['lastError'], $eqrLabel . ': A lastError explains why');
assertEquals('sent', $eqrStore->rows[2]['status'], $eqrLabel . ': B (2 minutes and 1 second left) sent');
assertEquals(1, $eqrResult['expired'], $eqrLabel . ': expired 1');
assertTrue($eqrEachSentOnce($eqrStore, ['user2@example.com']), $eqrLabel . ': only B was sent');

$eqrStore      = $eqrNewStore();
$eqrResetExtra = ['templateKey' => 'password_reset', 'subject' => 'Reset your CueRCode password'];
$eqrAddRow($eqrStore, 1, $eqrResetExtra + ['paramsJSON' => '{"resetUrl":"https://cuercode.net/reset-password?token=R1"}', 'createdAt' => $eqrStore->now - 3480]);
$eqrAddRow($eqrStore, 2, $eqrResetExtra + ['paramsJSON' => '{"resetUrl":"https://cuercode.net/reset-password?token=R2"}', 'createdAt' => $eqrStore->now - 3479]);
$eqrResult = $eqrRun($eqrStore, 'A', ['linkLifetimes' => ['password_reset' => 3600]]);
$eqrLabel  = 'expired reset link (60 minutes): skipped at the boundary, sent just inside it';
assertEquals('skipped', $eqrStore->rows[1]['status'], $eqrLabel . ': A skipped');
assertEquals(CUERCODE_EMAIL_QUEUE_NOTE_LINK_EXPIRED, $eqrStore->rows[1]['lastError'], $eqrLabel . ': A lastError explains why');
assertEquals('sent', $eqrStore->rows[2]['status'], $eqrLabel . ': B sent');
assertTrue($eqrEachSentOnce($eqrStore, ['user2@example.com']), $eqrLabel . ': only B was sent');
assertContains('token=R2', (string) $eqrStore->sends[0]['body'], $eqrLabel . ': B went out with its reset link');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['attempts' => 2, 'nextAttemptAt' => $eqrStore->now + 600, 'createdAt' => $eqrStore->now - 90000]);
$eqrRun($eqrStore, 'A');
assertEquals('skipped', $eqrStore->rows[1]['status'], 'expiry also covers an email waiting for a retry');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['status' => 'sending', 'updatedAt' => $eqrStore->now - 60, 'createdAt' => $eqrStore->now - 90000]);
$eqrAddRow($eqrStore, 2, ['status' => 'sent', 'createdAt' => $eqrStore->now - 90000]);
$eqrAddRow($eqrStore, 3, ['status' => 'failed', 'attempts' => 5, 'createdAt' => $eqrStore->now - 90000]);
$eqrRun($eqrStore, 'A');
$eqrLabel = 'expiry never touches an email being sent, already sent, or given up';
assertEquals('sending', $eqrStore->rows[1]['status'], $eqrLabel . ': the email being sent is still sending');
assertEquals('sent', $eqrStore->rows[2]['status'], $eqrLabel . ': the sent email is still sent');
assertEquals('failed', $eqrStore->rows[3]['status'], $eqrLabel . ': the given-up email is still failed');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['templateKey' => 'receipt', 'paramsJSON' => '{"orderNumber":"R-1","amount":"9.99"}', 'createdAt' => $eqrStore->now - (10 * $eqrDay)]);
$eqrRun($eqrStore, 'A');
assertEquals('sent', $eqrStore->rows[1]['status'], 'no link lifetime (receipt) means no expiry: a 10-day-old receipt is sent');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['createdAt' => $eqrStore->now - (2 * $eqrDay)]);
$eqrRun($eqrStore, 'A', ['linkLifetimes' => []]);
assertEquals('sent', $eqrStore->rows[1]['status'], 'with no lifetimes known nothing is skipped for age');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['createdAt' => $eqrStore->now - 86400]);
$eqrResult = $eqrRun($eqrStore, 'A', ['useLock' => false]);
$eqrLabel  = 'the per-row check stops an expired link even without the clean-up step (lock bypassed)';
assertEquals('skipped', $eqrStore->rows[1]['status'], $eqrLabel . ': skipped');
assertEquals(CUERCODE_EMAIL_QUEUE_NOTE_LINK_EXPIRED, $eqrStore->rows[1]['lastError'], $eqrLabel . ': lastError explains why');
assertEquals(1, $eqrResult['expired'], $eqrLabel . ': expired 1');
assertEquals(0, count($eqrStore->sends), $eqrLabel . ': 0 sends');

// --- tidy-only runs (sendEmails = false) ------------------------------------------------------------
$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['status' => 'sent', 'createdAt' => $eqrStore->now - (40 * $eqrDay)]);
$eqrAddRow($eqrStore, 2);
$eqrAddRow($eqrStore, 3, ['createdAt' => $eqrStore->now - (2 * $eqrDay)]);
$eqrResult = $eqrRun($eqrStore, 'A', ['sendEmails' => false]);
$eqrLabel  = 'sendEmails=false tidies up but never claims or sends';
assertEquals(1, $eqrResult['purged'], $eqrLabel . ': purged 1');
assertEquals(1, $eqrResult['expired'], $eqrLabel . ': expired 1');
assertEquals(0, $eqrStore->claimCalls, $eqrLabel . ': claimCalls 0');
assertEquals(0, count($eqrStore->sends), $eqrLabel . ': 0 sends');
assertEquals('queued', $eqrStore->rows[2]['status'], $eqrLabel . ': the fresh email is still queued');
assertEquals(null, $eqrStore->lockHolder, $eqrLabel . ': the lock is released');
assertEquals('ok', $eqrResult['status'], $eqrLabel . ': status ok');

$eqrStore             = $eqrNewStore();
$eqrStore->lockHolder = 'other';
$eqrAddRow($eqrStore, 1, ['nextAttemptAt' => $eqrStore->now - 660]);
$eqrResult = $eqrRun($eqrStore, 'A', ['sendEmails' => false]);
assertEquals('busy', $eqrResult['status'], 'sendEmails=false with the lock held elsewhere is busy, never stalled');

// --- the runner's main, with the clean-up ----------------------------------------------------------
$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['status' => 'sent', 'createdAt' => $eqrStore->now - (40 * $eqrDay)]);
$eqrIo   = $eqrNewIo();
$eqrCode = $eqrMain($eqrStore, $eqrIo, ['--quiet'], $eqrOffSettings);
$eqrLabel = 'email switched off, quiet: the clean-up runs silently (exit 0)';
assertEquals(0, $eqrCode, $eqrLabel . ': exit 0');
assertFalse(isset($eqrStore->rows[1]), $eqrLabel . ': the 40-day-old record is gone');
assertEquals([], array_merge($eqrIo->out, $eqrIo->err), $eqrLabel . ': nothing printed');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['status' => 'sent', 'createdAt' => $eqrStore->now - (40 * $eqrDay)]);
$eqrIo   = $eqrNewIo();
$eqrCode = $eqrMain($eqrStore, $eqrIo, [], $eqrOffSettings);
$eqrText = implode(' ', $eqrIo->out);
assertEquals(0, $eqrCode, 'email switched off, normal: says what was tidied: exit 0');
assertContains('switched off', $eqrText, 'email switched off, normal: says what was tidied: says switched off');
assertContains('Tidied up: deleted 1', $eqrText, 'email switched off, normal: says what was tidied: says deleted 1');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['status' => 'sent', 'createdAt' => $eqrStore->now - (40 * $eqrDay)]);
$eqrStore->failOps['purgeFinished'] = true;
$eqrIo                              = $eqrNewIo();
$eqrCode                            = $eqrMain($eqrStore, $eqrIo, ['--quiet'], $eqrOffSettings);
$eqrLabel                           = 'email switched off: a failing clean-up is reported on the error stream with exit 3, even when quiet';
assertEquals(3, $eqrCode, $eqrLabel . ': exit 3');
assertContains('Could not finish tidying up', implode(' ', $eqrIo->err), $eqrLabel . ': on the error stream');
assertEquals([], $eqrIo->out, $eqrLabel . ': nothing on the normal stream');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['status' => 'sent', 'createdAt' => $eqrStore->now - (40 * $eqrDay)]);
$eqrAddRow($eqrStore, 2);
$eqrIo   = $eqrNewIo();
$eqrCode = $eqrMain($eqrStore, $eqrIo, ['--quiet'], $eqrGappySettings);
$eqrLabel = 'email on but settings incomplete: the clean-up still runs; exit 4';
assertEquals(4, $eqrCode, $eqrLabel . ': exit 4');
assertFalse(isset($eqrStore->rows[1]), $eqrLabel . ': the old record is gone');
assertEquals(0, $eqrStore->claimCalls, $eqrLabel . ': nothing claimed');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['createdAt' => $eqrStore->now - 90000]);
$eqrIo   = $eqrNewIo();
$eqrCode = $eqrMain($eqrStore, $eqrIo, ['--quiet'], $eqrReadySettings);
$eqrLabel = 'sending run: an expired email is reported on the error stream even when quiet (exit 0)';
assertEquals(0, $eqrCode, $eqrLabel . ': exit 0');
assertContains('Skipped 1', implode(' ', $eqrIo->err), $eqrLabel . ': on the error stream');
assertEquals(0, count($eqrStore->sends), $eqrLabel . ': nothing sent');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['paramsJSON' => null]);
$eqrIo   = $eqrNewIo();
$eqrCode = $eqrMain($eqrStore, $eqrIo, ['--quiet'], $eqrReadySettings);
$eqrLabel = 'sending run: an email whose details were removed is reported on the error stream even when quiet';
assertEquals(0, $eqrCode, $eqrLabel . ': exit 0');
assertContains('can no longer be sent', implode(' ', $eqrIo->err), $eqrLabel . ': on the error stream');

$eqrStore = $eqrNewStore();
$eqrAddRow($eqrStore, 1, ['status' => 'sent', 'createdAt' => $eqrStore->now - (40 * $eqrDay)]);
$eqrIo   = $eqrNewIo();
$eqrCode = $eqrMain($eqrStore, $eqrIo, ['--check'], $eqrReadySettings);
$eqrText = implode(' ', $eqrIo->out);
$eqrLabel = '--check shows the clean-up policy and the skipped count, and changes nothing';
assertEquals(0, $eqrCode, $eqrLabel . ': exit 0');
assertTrue(isset($eqrStore->rows[1]), $eqrLabel . ': the 40-day-old record is still there');

foreach (['Clean-up:', '30 days', '90 days', 'Skipped (not sent on purpose', 'Expired-link check: on', 'verification 24 hours', 'password_reset 60 minutes'] as $eqrNeedle) {
    assertContains($eqrNeedle, $eqrText, $eqrLabel . ': shows ' . $eqrNeedle);
}

$eqrCheckSettings                               = $eqrReadySettings;
$eqrCheckSettings['email_queue_retention_days'] = 'retention-value-777';
$eqrStore                                       = $eqrNewStore();
$eqrIo                                          = $eqrNewIo();
$eqrCode                                        = $eqrMain($eqrStore, $eqrIo, ['--check'], $eqrCheckSettings);
$eqrText                                        = implode(' ', array_merge($eqrIo->out, $eqrIo->err));
assertContains('email_queue_retention_days', $eqrText, '--check names an invalid retention setting but never its value: names it');
assertFalse(str_contains($eqrText, 'retention-value-777'), '--check names an invalid retention setting but never its value: no value');

// --- source-text guards on the new SQL (Section 4's cutter) -------------------------------------------
assertContains('`paramsJSON` = NULL', $eqrFunctionBody($eqrEmailSource, 'settleEmailQueueSuccess'), 'settleEmailQueueSuccess clears paramsJSON in the same statement that marks the email sent');

$eqrSkipClaimedBody = $eqrFunctionBody($eqrEmailSource, 'skipClaimedEmailQueueRow');

foreach (["`status` = 'skipped'", '`paramsJSON` = NULL', "AND `status` = 'sending'", 'emailQueueChangeOneRow('] as $eqrNeedle) {
    assertContains($eqrNeedle, $eqrSkipClaimedBody, 'skipClaimedEmailQueueRow contains ' . $eqrNeedle);
}

$eqrSkipExpiredBody = $eqrFunctionBody($eqrEmailSource, 'skipExpiredEmailQueueRows');

foreach (["`status` IN ('queued', 'failed')", '`attempts` < `maxAttempts`', '`createdAt` <= DATE_SUB(NOW(), INTERVAL ? SECOND)', '`paramsJSON` = NULL', 'LIMIT ?'] as $eqrNeedle) {
    assertContains($eqrNeedle, $eqrSkipExpiredBody, 'skipExpiredEmailQueueRows contains ' . $eqrNeedle);
}

assertFalse(str_contains($eqrSkipExpiredBody, "'sending'"), "skipExpiredEmailQueueRows never touches a 'sending' row");

$eqrClearBody = $eqrFunctionBody($eqrEmailSource, 'clearGivenUpEmailQueueDetails');

foreach (['`updatedAt` = `updatedAt`', '`attempts` >= `maxAttempts`', '`paramsJSON` IS NOT NULL', "`status` = 'failed'"] as $eqrNeedle) {
    assertContains($eqrNeedle, $eqrClearBody, 'clearGivenUpEmailQueueDetails contains ' . $eqrNeedle);
}

$eqrPurgeBody = $eqrFunctionBody($eqrEmailSource, 'purgeFinishedEmailQueueRows');

foreach (['DELETE FROM `tblEmailQueue`', "`status` IN ('sent', 'skipped')", '`createdAt` < DATE_SUB(NOW(), INTERVAL ? DAY)', 'LIMIT ?'] as $eqrNeedle) {
    assertContains($eqrNeedle, $eqrPurgeBody, 'purgeFinishedEmailQueueRows contains ' . $eqrNeedle);
}

assertFalse(str_contains($eqrPurgeBody, 'queued') || str_contains($eqrPurgeBody, 'sending'), 'purgeFinishedEmailQueueRows never mentions queued or sending (it only ever deletes finished rows)');

$eqrPurgeGivenUpBody = $eqrFunctionBody($eqrEmailSource, 'purgeGivenUpEmailQueueRows');

foreach (['DELETE FROM', "`status` = 'failed'", '`attempts` >= `maxAttempts`'] as $eqrNeedle) {
    assertContains($eqrNeedle, $eqrPurgeGivenUpBody, 'purgeGivenUpEmailQueueRows contains ' . $eqrNeedle);
}

assertFalse(str_contains($eqrPurgeGivenUpBody, 'queued') || str_contains($eqrPurgeGivenUpBody, 'sending'), 'purgeGivenUpEmailQueueRows never mentions queued or sending (it only ever deletes given-up rows)');
assertContains('TIMESTAMPDIFF(SECOND, `createdAt`, NOW()) AS `ageSeconds`', $eqrFunctionBody($eqrEmailSource, 'loadClaimedEmailQueueRow'), 'loadClaimedEmailQueueRow measures the age on the database clock');

$eqrBatchBody     = $eqrFunctionBody($eqrEmailSource, 'runEmailQueueBatch');
$eqrHaveLockPos   = strpos($eqrBatchBody, 'if ($haveLock) {');
$eqrHousekeepPos  = strpos($eqrBatchBody, 'runEmailQueueHousekeeping(');
$eqrFindDuePos    = strpos($eqrBatchBody, "\$gateway['findDue']");
assertTrue(
    $eqrHaveLockPos !== false && $eqrHousekeepPos !== false && $eqrFindDuePos !== false
        && $eqrHousekeepPos > $eqrHaveLockPos && ($eqrHousekeepPos - $eqrHaveLockPos) < 400 && $eqrHousekeepPos < $eqrFindDuePos,
    'runEmailQueueBatch runs the clean-up only while it holds the lock, and before it lists the emails to send'
);
assertContains("\$gateway['markSkipped']", $eqrBatchBody, 'runEmailQueueBatch marks unsendable emails skipped through the gateway');

$eqrLifetimesBody = $eqrFunctionBody($eqrEmailSource, 'emailQueueLinkLifetimes');
assertContains("defined('CUERCODE_EMAIL_VERIFICATION_TTL_HOURS')", $eqrLifetimesBody, 'emailQueueLinkLifetimes reads the verification lifetime at run time');
assertContains("defined('CUERCODE_PASSWORD_RESET_TTL_MINUTES')", $eqrLifetimesBody, 'emailQueueLinkLifetimes reads the reset lifetime at run time');

assertContains('cleanLogText(', $eqrFunctionBody($eqrEmailSource, 'emailQueueSafeDetail'), 'emailQueueSafeDetail delegates to cleanLogText (one copy of the rule)');
assertFalse(str_contains($eqrEmailSource, '[A-Za-z0-9._%+\-]+@'), 'fn_email.php no longer has its own copy of the address pattern');

$eqrInstallSource = (string) file_get_contents(CUERCODE_INCLUDES_DIR . 'sql' . DIRECTORY_SEPARATOR . 'install.sql');
$eqrSeedSource    = (string) file_get_contents(CUERCODE_INCLUDES_DIR . 'sql' . DIRECTORY_SEPARATOR . 'seed_data.sql');
assertFalse(str_contains($eqrInstallSource, 'NEVER stores rendered HTML or secrets'), 'install.sql no longer claims paramsJSON never stores secrets');
assertContains('one-time sign-up or password-reset link', $eqrInstallSource, 'install.sql says paramsJSON can hold a one-time link');
assertContains("('email', 'email_queue_retention_days', '30', 'integer'", $eqrSeedSource, 'seed_data.sql seeds email_queue_retention_days = 30');
assertContains("('email', 'email_queue_failed_retention_days', '90', 'integer'", $eqrSeedSource, 'seed_data.sql seeds email_queue_failed_retention_days = 90');
