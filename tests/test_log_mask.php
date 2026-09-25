<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Tests for LOG MASKING (keep email addresses out of the error log, #126)
 * ==============================================================================
 *
 * Path: tests/test_log_mask.php
 *
 * Covers #126 ("the activity logger's fallback writes recipients' email
 * addresses into PHP's error log - on the command line, that is the cron
 * email"):
 *
 *   - The two helpers in _functions/fn_log_mask.php: maskEmailAddresses() and
 *     cleanLogText().
 *   - That emailQueueSafeDetail() (fn_email.php, #112) is now a thin wrapper
 *     round cleanLogText(), giving identical results - ONE copy of the rule.
 *   - Source-text guards: every logger loads the helper itself, bootstrap.php
 *     loads it first, and each fallback line goes through it.
 *   - The four real error-log fallbacks, run for real in CHILD PHP processes
 *     with PHP's error log pointed at a temporary file, proving that no email
 *     address reaches that file:
 *       A. logActivity() with no database;
 *       B. logActivity() when the database insert fails (also hides SQL text);
 *       C. logEmailAttempt() when the activity logger is not loaded;
 *       D. logError() with no database;
 *       E. maskSensitiveForLog() directly.
 *
 * 🧒 WHY the logger checks run in child processes: fn_error_handler.php
 *    installs PHP error handlers the moment it is loaded, and five other test
 *    files assert that dbQuery(), getDB(), getConfig(), logError() and
 *    logActivity() do NOT exist in the main test process (they prove code
 *    degrades safely without a database). So this file NEVER loads
 *    fn_error_handler.php or fn_activity_log.php, and never defines any of
 *    those functions, in the main process. A child process is a separate PHP
 *    run, so it may - and its error log can be captured on its own.
 *
 * ⚠️ NOT covered here: the database side of logging (tblActivityLog and
 *    tblErrorLog still store the full, unmasked detail by design - that is a
 *    separate retention follow-up), and non-English letters in addresses (the
 *    pattern is ASCII-only on purpose; see fn_log_mask.php).
 *
 * ⚠️ House rules for this file (it runs in the SAME global scope as
 *    tests/run.php and every other test file): every variable is prefixed
 *    $lgm, so nothing clashes with the runner's own variables.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-09-25 (#126)
 * @see         tests/bootstrap.php  - requireFn() loader (no DB connection)
 * @see         tests/lib/assert.php - the assert* functions used below
 * @see         web/CueRCode.net/_functions/fn_log_mask.php
 */

// 📌 Only these two in the MAIN process - neither defines a database, config
//    or logging function, and neither installs error handlers.
requireFn('fn_log_mask.php');
requireFn('fn_email.php');

// ==============================================================================
// ✅ Section 1: maskEmailAddresses()
// ==============================================================================
assertEquals('[address]', maskEmailAddresses('alice@example.com'), 'maskEmailAddresses: alice@example.com -> [address]');
assertEquals('To: [address] | Subject: Hi', maskEmailAddresses('To: alice.o+tag@mail.example.co.uk | Subject: Hi'), 'maskEmailAddresses: a tagged, multi-part address inside a log line');
assertEquals('two: [address], [address]', maskEmailAddresses('two: a@b.com, c@d.org'), 'maskEmailAddresses: every address in the text');
assertEquals('no address here', maskEmailAddresses('no address here'), 'maskEmailAddresses: text with no address is unchanged');
assertEquals('', maskEmailAddresses(''), 'maskEmailAddresses: empty text stays empty');

// 📌 Documents a known limit on purpose: a database login quoted as
//    'user'@'host' is NOT address-shaped (the quote marks break the pattern),
//    so it is left alone. It carries a database user name and host, never a
//    password; DEV_NOTES.md and SECURITY.md say so.
assertEquals(
    "Access denied for user 'cuercode'@'localhost'",
    maskEmailAddresses("Access denied for user 'cuercode'@'localhost'"),
    "maskEmailAddresses: a quoted database login 'user'@'host' is not address-shaped and is left unchanged"
);

// ==============================================================================
// ✅ Section 2: cleanLogText()
// ==============================================================================
// The first four are the emailQueueSafeDetail() cases from
// tests/test_email_queue_runner.php (#112), with the same expected results.
assertEquals('Could not send to [address] today', cleanLogText('Could not send to alice@example.com today'), 'cleanLogText: masks alice@example.com to [address]');
assertEquals('line one line two end', cleanLogText("line one\nline two\tend"), 'cleanLogText: removes newlines and tabs');
assertEquals(str_repeat('a', 27) . '...', cleanLogText(str_repeat('a', 100), 30), 'cleanLogText: truncates to the limit with ...');
assertEquals('HTTP 503 | graph_error_code: X', cleanLogText('HTTP 503 | graph_error_code: X'), 'cleanLogText: leaves HTTP 503 | graph_error_code: X unchanged');

$lgmLong = cleanLogText(str_repeat('x', 1500));
assertEquals(1000, mb_strlen($lgmLong), 'cleanLogText: the default limit is 1000 characters');
assertTrue(str_ends_with($lgmLong, '...'), 'cleanLogText: a cut at the default limit ends with ...');
assertEquals(20, mb_strlen(cleanLogText(str_repeat('b', 50), 5)), 'cleanLogText: a limit below 20 is raised to 20');

// ==============================================================================
// ✅ Section 3: emailQueueSafeDetail() is a wrapper round cleanLogText()
// ==============================================================================
$lgmSamples = [
    'Could not send to alice@example.com today',
    "line one\nline two\tend\r\n",
    str_repeat('z', 700),
    'HTTP 400 | graph_error_code: ErrorInvalidRecipients',
    "  lots   of\t\tspace  bob@example.org  ",
    '',
];
$lgmWrapperMatches = true;

foreach ($lgmSamples as $lgmSample) {
    if (emailQueueSafeDetail($lgmSample) !== cleanLogText($lgmSample, 200)) {
        $lgmWrapperMatches = false;
    }

    if (emailQueueSafeDetail($lgmSample, 500) !== cleanLogText($lgmSample, 500)) {
        $lgmWrapperMatches = false;
    }
}

assertTrue($lgmWrapperMatches, 'emailQueueSafeDetail is now a wrapper round cleanLogText (one copy of the rule)');

// ==============================================================================
// ✅ Section 4: source-text guards
// ==============================================================================
// Each function is cut out from "\nfunction name(" to the first "\n}\n" after
// it, so a phrase in the NEXT function's docblock can never make a check pass.
$lgmFunctionBody = static function (string $lgmSource, string $lgmName): string {
    $lgmStart = strpos($lgmSource, "\nfunction " . $lgmName . '(');

    if ($lgmStart === false) {
        return '';
    }

    $lgmEnd = strpos($lgmSource, "\n}\n", $lgmStart);

    if ($lgmEnd === false) {
        return '';
    }

    return substr($lgmSource, $lgmStart, ($lgmEnd - $lgmStart) + 3);
};

$lgmActivitySource  = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_activity_log.php');
$lgmErrorSource     = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_error_handler.php');
$lgmEmailSource     = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_email.php');
$lgmBootstrapSource = (string) file_get_contents(CUERCODE_INCLUDES_DIR . 'bootstrap.php');
$lgmRequireLine     = "require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_log_mask.php';";

assertEquals(2, substr_count($lgmActivitySource, 'cleanLogText($detail, 1000)'), 'fn_activity_log.php: both error-log fallbacks pass the detail through cleanLogText()');

foreach (['fn_email.php' => $lgmEmailSource, 'fn_activity_log.php' => $lgmActivitySource, 'fn_error_handler.php' => $lgmErrorSource] as $lgmFileName => $lgmSource) {
    assertContains($lgmRequireLine, $lgmSource, $lgmFileName . ' loads fn_log_mask.php itself (it can be loaded without bootstrap.php)');
}

$lgmMaskPos = strpos($lgmBootstrapSource, "'fn_log_mask.php'");
$lgmFirst   = $lgmMaskPos !== false;

foreach (["'fn_error_handler.php'", "'fn_activity_log.php'", "'fn_email.php'"] as $lgmLaterFile) {
    $lgmLaterPos = strpos($lgmBootstrapSource, $lgmLaterFile);

    if ($lgmLaterPos === false || $lgmMaskPos === false || $lgmMaskPos > $lgmLaterPos) {
        $lgmFirst = false;
    }
}

assertTrue($lgmFirst, 'bootstrap.php loads fn_log_mask.php before fn_error_handler.php, fn_activity_log.php and fn_email.php');
assertContains('cleanLogText(', $lgmFunctionBody($lgmEmailSource, 'logEmailAttempt'), "fn_email.php: logEmailAttempt()'s fallback goes through cleanLogText()");

$lgmMaskBody = $lgmFunctionBody($lgmErrorSource, 'maskSensitiveForLog');
assertContains('maskEmailAddresses(', $lgmMaskBody, 'fn_error_handler.php: maskSensitiveForLog() hides email addresses');
assertContains('is_string($masked)', $lgmMaskBody, 'fn_error_handler.php: maskSensitiveForLog() never passes on a failed replace');

// ==============================================================================
// ✅ Section 5: the real fallbacks, in CHILD processes
// ==============================================================================
// ⚠️ Each child is a separate PHP run. That is the ONLY place this file loads
//    fn_error_handler.php / fn_activity_log.php or defines dbQuery() - see the
//    header for why the main process must never do either.
// The command is an ARRAY, so the spaces and "&" in the repository path are
// passed safely without a shell. error_log is set explicitly so a developer's
// own php.ini cannot send the lines somewhere else.
// @see https://www.php.net/manual/en/function.proc-open.php
// ==============================================================================

/**
 * Run $lgmProgramBody in a fresh PHP process (after the test bootstrap) with
 * PHP's error log pointed at a temporary file. Returns the exit code, stdout
 * and the error-log file's contents; both temporary files are removed.
 */
$lgmRunChild = static function (string $lgmProgramBody): array {
    $lgmProgramFile = tempnam(sys_get_temp_dir(), 'cuercode_lgm_');
    $lgmLogFile     = tempnam(sys_get_temp_dir(), 'cuercode_lgm_');

    if ($lgmProgramFile === false || $lgmLogFile === false) {
        return ['exit' => -1, 'stdout' => '', 'log' => ''];
    }

    $lgmBootstrapPath = CUERCODE_TEST_REPO_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'bootstrap.php';
    file_put_contents(
        $lgmProgramFile,
        '<?php' . PHP_EOL . 'require ' . var_export($lgmBootstrapPath, true) . ';' . PHP_EOL . $lgmProgramBody . PHP_EOL
    );

    $lgmDescriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $lgmProcess = proc_open(
        [PHP_BINARY, '-d', 'error_log=' . $lgmLogFile, '-d', 'log_errors=1', $lgmProgramFile],
        $lgmDescriptors,
        $lgmPipes
    );
    $lgmOutcome = ['exit' => -1, 'stdout' => '', 'log' => ''];

    if (is_resource($lgmProcess)) {
        fclose($lgmPipes[0]);
        $lgmOutcome['stdout'] = (string) stream_get_contents($lgmPipes[1]);
        fclose($lgmPipes[1]);
        stream_get_contents($lgmPipes[2]);
        fclose($lgmPipes[2]);
        $lgmOutcome['exit'] = proc_close($lgmProcess);
    }

    $lgmOutcome['log'] = (string) file_get_contents($lgmLogFile);

    // 🧹 Remove both temporary files.
    @unlink($lgmProgramFile);
    @unlink($lgmLogFile);

    return $lgmOutcome;
};

// --- A. the activity logger with no database ------------------------------------
$lgmChild = $lgmRunChild(<<<'PHP'
requireFn('fn_activity_log.php');
logActivity('email_failed', 'To: alice.example+tag@example.com | Subject: Verify your CueRCode email address | HTTP 400', 'email', null, ['to' => 'alice.example+tag@example.com']);
echo 'done', PHP_EOL;
PHP);
$lgmLabel = 'activity-log fallback without a database hides the address';
assertEquals(0, $lgmChild['exit'], $lgmLabel . ': the child exits 0');
assertContains('done', $lgmChild['stdout'], $lgmLabel . ': the child ran to the end');
assertContains('CueRCode Activity [email_failed]: To: [address] | Subject: Verify your CueRCode email address', $lgmChild['log'], $lgmLabel . ': the line is written, with [address]');
assertFalse(str_contains($lgmChild['log'], 'alice.example') || str_contains($lgmChild['log'], '@example.com'), $lgmLabel . ': no part of the address is in the log');

// --- B. the activity logger when the database insert fails -------------------------
// ⚠️ dbQuery() is defined HERE, inside the CHILD process only (a separate PHP
//    run) - never in the main test process, where five other test files assert
//    it does not exist.
$lgmChild = $lgmRunChild(<<<'PHP'
requireFn('fn_error_handler.php');
requireFn('fn_activity_log.php');
function dbQuery(string $sql, string $types = '', array $params = []): \mysqli_stmt
{
    throw new \RuntimeException("Duplicate entry 'bob@example.org' for key 'x' | SQL: INSERT INTO `tblActivityLog` (`activityType`) VALUES (?)");
}
logActivity('email_sent', 'To: carol@example.net | Subject: Reset your CueRCode password | Graph sendMail accepted (HTTP 202)', 'email');
echo 'done', PHP_EOL;
PHP);
$lgmLabel = 'activity-log fallback after a failed insert hides addresses and SQL';
assertEquals(0, $lgmChild['exit'], $lgmLabel . ': the child exits 0');
assertContains('done', $lgmChild['stdout'], $lgmLabel . ': the child ran to the end');
assertContains('CueRCode Activity Log FAILED [email_sent]', $lgmChild['log'], $lgmLabel . ': the line is written');
assertContains('[address]', $lgmChild['log'], $lgmLabel . ': addresses become [address]');
assertContains('[SQL REDACTED]', $lgmChild['log'], $lgmLabel . ': the SQL text is redacted');
assertFalse(
    str_contains($lgmChild['log'], 'carol@') || str_contains($lgmChild['log'], 'bob@') || str_contains($lgmChild['log'], 'INSERT INTO'),
    $lgmLabel . ': neither address nor the SQL text is in the log'
);

// --- C. logEmailAttempt() when the activity logger is not loaded --------------------
$lgmChild = $lgmRunChild(<<<'PHP'
requireFn('fn_email.php');
logEmailAttempt('email_failed', 'dave@example.com', 'Subject', 'HTTP 400 | graph_error_code: ErrorInvalidRecipients');
echo 'done', PHP_EOL;
PHP);
$lgmLabel = 'logEmailAttempt fallback (no activity logger) hides the address';
assertEquals(0, $lgmChild['exit'], $lgmLabel . ': the child exits 0');
assertContains('done', $lgmChild['stdout'], $lgmLabel . ': the child ran to the end');
assertContains('CueRCode Email [email_failed]: [address] | HTTP 400', $lgmChild['log'], $lgmLabel . ': the line is written, with [address]');
assertFalse(str_contains($lgmChild['log'], 'dave@'), $lgmLabel . ': the address is not in the log');

// --- D. logError() with no database -----------------------------------------------------
$lgmChild = $lgmRunChild(<<<'PHP'
requireFn('fn_error_handler.php');
logError('error', 'Registration insert failed (unexpected error)', 'Incorrect value for erin@example.com | SQL: SELECT 1');
echo 'done', PHP_EOL;
PHP);
$lgmLabel = 'logError fallback (no database) hides the address';
assertEquals(0, $lgmChild['exit'], $lgmLabel . ': the child exits 0');
assertContains('done', $lgmChild['stdout'], $lgmLabel . ': the child ran to the end');
assertContains('CueRCode [error]:', $lgmChild['log'], $lgmLabel . ': the line is written');
assertContains('[address]', $lgmChild['log'], $lgmLabel . ': the address becomes [address]');
assertContains('[SQL REDACTED]', $lgmChild['log'], $lgmLabel . ': the SQL text is still redacted');
assertFalse(str_contains($lgmChild['log'], 'erin@'), $lgmLabel . ': the address is not in the log');

// --- E. maskSensitiveForLog() directly ----------------------------------------------------
$lgmChild = $lgmRunChild(<<<'PHP'
requireFn('fn_error_handler.php');
echo json_encode(maskSensitiveForLog('a frank@example.com b | SQL: SELECT 1')), PHP_EOL;
echo 'done', PHP_EOL;
PHP);
$lgmLabel = 'maskSensitiveForLog hides addresses and still redacts SQL';
assertEquals(0, $lgmChild['exit'], $lgmLabel . ': the child exits 0');
assertContains('done', $lgmChild['stdout'], $lgmLabel . ': the child ran to the end');
assertContains('a [address] b', $lgmChild['stdout'], $lgmLabel . ': the address becomes [address]');
assertContains('[SQL REDACTED]', $lgmChild['stdout'], $lgmLabel . ': the SQL text is still redacted');
