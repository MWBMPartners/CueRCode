<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Real-database check for the email queue runner (#112)
 * ==============================================================================
 *
 * Path: tests/integration/email_queue_real_mysql.php
 *
 * The unit tests (tests/test_email_queue_runner.php) prove the queue LOGIC with
 * an in-memory stand-in for the table. This script proves the parts only a
 * real MySQL/MariaDB server can: the real SQL of every claim and update, the
 * real named lock (GET_LOCK), and two real runner PROCESSES working on the
 * same queue at the same time without ever sending an email twice.
 *
 * Scenarios: 0 set-up checks (including, since #122/#123, that the real
 * start-up code gives the runner fn_auth.php's link lifetimes and the seeded
 * clean-up settings); 1 claim rules; 2 two runners, lock on; 3 two runners,
 * lock off; 4 retry; 5 stuck recovery; 6 sending blocked; 7 busy and stalled;
 * 8 counts; and for #122/#123: 9 details cleared on send; 10 expired links
 * skipped (the real bulk UPDATE); 10b the per-email expiry check (real
 * TIMESTAMPDIFF); 11 given-up details cleared after 24 hours with updatedAt
 * kept; 12 old records deleted (the real DELETEs - and nothing waiting is
 * touched); 13 cleared details are never sent; 14 the runner's main with
 * email switched off still tidies up and sends nothing.
 *
 * It is NOT run by tests/run.php (which only picks up tests/test_*.php at the
 * top level), because it needs a database. CI still syntax-checks it.
 *
 * How to run it (full Docker commands are in tests/README.md, "Optional
 * real-database check (email queue)"):
 *
 *     CUERCODE_IT_DB_HOST=127.0.0.1 CUERCODE_IT_DB_PORT=33112 \
 *     CUERCODE_IT_DB_NAME=cuercode_it_p112 CUERCODE_IT_DB_USER=root CUERCODE_IT_DB_PASS= \
 *       php tests/integration/email_queue_real_mysql.php --load-schema
 *
 * ⚠️ SAFETY - read before changing anything here:
 *   - It DELETES rows. It refuses to run (exit 2) unless the database name
 *     starts with "cuercode_it_", so it can never be pointed at a real site
 *     database by accident. Use a throwaway container and remove it afterwards.
 *   - It NEVER calls sendEmailNow(): every scenario passes its own stand-in
 *     sender, so no email can ever leave the machine. Test addresses use the
 *     reserved ".invalid" domain as a second guard.
 *   - It never reads _auth_keys/auth_creds.php: the connection details come
 *     only from the CUERCODE_IT_DB_* environment variables, and the site's
 *     encryption key is a fresh random value for each run.
 *
 * Exit codes: 0 every scenario passed | 1 a scenario failed or crashed |
 * 2 refused (unsafe database name, missing settings, or no schema).
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.1.0
 * @since       2026-09-25 (#112)
 * @see         tests/README.md - "Real-database check (email queue)"
 * @see         web/CueRCode.net/_functions/fn_email.php - runEmailQueueBatch() and the queue SQL
 */

// ==============================================================================
// 🚫 Command line only - the first statement, as in the real runner
// ==============================================================================
if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
    }
    exit('Access denied.');
}

$itSiteRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'CueRCode.net' . DIRECTORY_SEPARATOR;

// ==============================================================================
// 🧾 Options. The parent is run with at most --load-schema; the two child
//    runner processes it starts are given the --child/... options.
// ==============================================================================
$itOptions = [
    'load-schema' => false,
    'child'       => null,
    'use-lock'    => '1',
    'ready-file'  => null,
    'go-file'     => null,
    'sends-file'  => null,
    'result-file' => null,
];

foreach (array_slice($argv, 1) as $itArgument) {
    if ($itArgument === '--load-schema') {
        $itOptions['load-schema'] = true;
    } elseif (preg_match('/^--(child|use-lock|ready-file|go-file|sends-file|result-file)=(.*)$/s', $itArgument, $itMatch) === 1) {
        $itOptions[$itMatch[1]] = $itMatch[2];
    } else {
        fwrite(STDERR, 'Unknown option: ' . $itArgument . PHP_EOL);
        exit(2);
    }
}

// ==============================================================================
// 🛡️ Step 1: safety - only ever a throwaway database named cuercode_it_*
// ==============================================================================
$itEnv = [];

foreach (['HOST', 'PORT', 'NAME', 'USER', 'PASS'] as $itKey) {
    $itValue = getenv('CUERCODE_IT_DB_' . $itKey);

    if ($itValue === false) {
        $itValue = '';
    }

    $itEnv[$itKey] = $itValue;
}

if (preg_match('/^cuercode_it_[a-z0-9_]+$/', $itEnv['NAME']) !== 1) {
    fwrite(STDERR, 'Refusing to run: CUERCODE_IT_DB_NAME must start with "cuercode_it_" (this script deletes rows, so it only ever touches a throwaway database).' . PHP_EOL);
    exit(2);
}

if ($itEnv['HOST'] === '' || $itEnv['USER'] === '') {
    fwrite(STDERR, 'Refusing to run: set CUERCODE_IT_DB_HOST and CUERCODE_IT_DB_USER (and CUERCODE_IT_DB_PORT / CUERCODE_IT_DB_PASS if needed).' . PHP_EOL);
    exit(2);
}

if ($itEnv['PORT'] === '') {
    $itEnv['PORT'] = '3306';
}

$itPort = (int) $itEnv['PORT'];

/**
 * 🔌 Open a plain mysqli connection to the test database (throws on failure).
 *
 * The @ hides PHP's own warning while the server is still starting (Docker
 * accepts the connection before MySQL answers, which PHP reports as "Error
 * while reading greeting packet" on every retry). The failure itself is still
 * thrown and handled by the caller - the same pattern getDB() uses.
 */
function itConnect(array $itEnv, int $itPort): \mysqli
{
    return @new \mysqli($itEnv['HOST'], $itEnv['USER'], $itEnv['PASS'], $itEnv['NAME'], $itPort);
}

// ==============================================================================
// ⏳ Steps 2-3 (parent only): wait for the database, load the schema if asked
// ==============================================================================
if ($itOptions['child'] === null) {
    // ⏳ The MySQL Docker image first starts a temporary server that does not
    //    listen on TCP, so the first successful TCP connection means the real
    //    server is ready. Up to 120 seconds, trying every half second.
    $itDeadline   = microtime(true) + 120;
    $itConnection = null;
    $itLastError  = '';

    while ($itConnection === null && microtime(true) < $itDeadline) {
        try {
            $itConnection = itConnect($itEnv, $itPort);
        } catch (\Throwable $itError) {
            $itLastError = $itError->getMessage();
            usleep(500000);
        }
    }

    if ($itConnection === null) {
        fwrite(STDERR, 'Could not connect to the test database within 120 seconds: ' . $itLastError . PHP_EOL);
        exit(1);
    }

    echo 'Connected to the test database.' . PHP_EOL;

    // 🔎 Does the queue table exist? (MySQL does not accept a ? placeholder in
    //    "SHOW TABLES LIKE ?", so ask information_schema, which does.)
    $itTableCheck = $itConnection->prepare(
        'SELECT COUNT(*) AS `tableCount` FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $itTableName  = 'tblEmailQueue';
    $itTableCheck->bind_param('s', $itTableName);
    $itTableCheck->execute();
    $itHasTable = ((int) $itTableCheck->get_result()->fetch_assoc()['tableCount'] === 1);
    $itTableCheck->close();

    if (!$itHasTable) {
        if (!$itOptions['load-schema']) {
            fwrite(STDERR, 'tblEmailQueue does not exist. Re-run with --load-schema to load install.sql and seed_data.sql into this throwaway database.' . PHP_EOL);
            exit(2);
        }

        // 📥 Load the schema the same way the installer does
        //    (public_html/install/index.php): multi_query over the two trusted
        //    repository files, draining every result, stopping at the first
        //    error. These are repository files, not user input.
        foreach (['install.sql', 'seed_data.sql'] as $itSqlFile) {
            $itSql = file_get_contents($itSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . $itSqlFile);

            if ($itSql === false) {
                fwrite(STDERR, 'Could not read ' . $itSqlFile . PHP_EOL);
                exit(1);
            }

            try {
                $itConnection->multi_query($itSql);

                do {
                    $itStored = $itConnection->store_result();

                    if ($itStored instanceof \mysqli_result) {
                        $itStored->free();
                    }
                } while ($itConnection->more_results() && $itConnection->next_result());
            } catch (\Throwable $itError) {
                fwrite(STDERR, 'Loading ' . $itSqlFile . ' failed: ' . $itError->getMessage() . PHP_EOL);
                exit(1);
            }

            echo 'Loaded ' . $itSqlFile . '.' . PHP_EOL;
        }
    }

    $itConnection->close();
}

// ==============================================================================
// 🚀 Step 4: start the real application code against the test database
// ==============================================================================
// The DB_* constants are defined here, so db_connect.php never needs (or
// reads) an auth_creds.php. The encryption key is random for each run.
// ==============================================================================
define('DB_HOST', $itEnv['HOST']);
define('DB_PORT', $itPort);
define('DB_NAME', $itEnv['NAME']);
define('DB_USER', $itEnv['USER']);
define('DB_PASS', $itEnv['PASS']);
define('ENCRYPTION_KEY', bin2hex(random_bytes(16)));

require_once $itSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

// 🧯 Plain-text errors for this script (the start-up code installs web-style
//    handlers that print HTML and hide warnings).
restore_error_handler();
ini_set('display_errors', 'stderr');
set_exception_handler(static function (\Throwable $itError): void {
    fwrite(STDERR, 'Integration check crashed: ' . get_class($itError) . ': ' . $itError->getMessage() . PHP_EOL);
    exit(1);
});

// ==============================================================================
// 👶 Child mode: one of two runner processes working on the same queue
// ==============================================================================
if ($itOptions['child'] !== null) {
    $itChildName = (string) $itOptions['child'];
    $itSendsFile = (string) $itOptions['sends-file'];

    // 🙋 Tell the parent we have finished starting up, then wait for "go" so
    //    both children begin at (almost) the same moment.
    file_put_contents((string) $itOptions['ready-file'], 'ready');
    $itGoDeadline = microtime(true) + 10;

    while (!is_file((string) $itOptions['go-file'])) {
        if (microtime(true) > $itGoDeadline) {
            file_put_contents((string) $itOptions['result-file'], json_encode(['status' => 'no_go_signal']));
            exit(1);
        }

        usleep(10000);
    }

    // 📤 Stand-in sender: takes a little time (like a real web call), records
    //    who sent what, and always succeeds. NEVER the real sendEmailNow().
    $itFakeSender = static function (string $itTo, string $itSubject, string $itBody, ?string $itToName) use ($itSendsFile, $itChildName): array {
        usleep(150000);
        file_put_contents($itSendsFile, $itChildName . ' ' . $itTo . PHP_EOL, FILE_APPEND | LOCK_EX);
        return [true, null];
    };

    $itUseLock = ((string) $itOptions['use-lock'] === '1');
    $itResult  = runEmailQueueBatch(emailQueueDatabaseGateway(), $itFakeSender, [
        'limit'             => 50,
        'timeBudgetSeconds' => 120,
        'useLock'           => $itUseLock,
    ]);

    file_put_contents((string) $itOptions['result-file'], json_encode($itResult));
    exit(0);
}

// ==============================================================================
// 🧰 Parent helpers
// ==============================================================================

$itAddressPattern = 'it-%@it.cuercode.invalid';
$itChecks         = [];

/**
 * 📝 Record one check for a scenario and print it.
 */
function itCheck(string $itScenario, bool $itPassed, string $itWhat): void
{
    if (!isset($GLOBALS['itChecks'][$itScenario])) {
        $GLOBALS['itChecks'][$itScenario] = true;
    }

    if (!$itPassed) {
        $GLOBALS['itChecks'][$itScenario] = false;
    }

    if ($itPassed) {
        echo '    ok   ';
    } else {
        echo '    FAIL ';
    }

    echo $itWhat . PHP_EOL;
}

/**
 * 📥 Run a prepared SELECT through the application's own dbQuery() and return the rows.
 */
function itSelect(string $itSql, string $itTypes = '', array $itParams = []): array
{
    $itStmt   = dbQuery($itSql, $itTypes, $itParams);
    $itResult = $itStmt->get_result();
    $itRows   = [];

    while ($itRow = $itResult->fetch_assoc()) {
        $itRows[] = $itRow;
    }

    $itStmt->close();

    return $itRows;
}

/**
 * ✏️ Run a prepared UPDATE/DELETE through dbQuery() (used to set up states).
 */
function itExec(string $itSql, string $itTypes, array $itParams): void
{
    $itStmt = dbQuery($itSql, $itTypes, $itParams);
    $itStmt->close();
}

/**
 * 🧹 Delete every row this script created (and only those).
 */
function itClear(): void
{
    itExec('DELETE FROM `tblEmailQueue` WHERE `toAddress` LIKE ?', 's', [$GLOBALS['itAddressPattern']]);
}

/**
 * 📥 Queue $itCount emails through the real queueEmail(); returns their IDs.
 */
function itQueue(int $itCount): array
{
    $itIds = [];

    for ($itIndex = 1; $itIndex <= $itCount; $itIndex++) {
        $itAddress = sprintf('it-%02d@it.cuercode.invalid', $itIndex);
        $itId      = queueEmail($itAddress, 'verification', ['verificationUrl' => 'https://cuercode.invalid/verify-email?token=IT' . $itIndex]);

        if ($itId === false) {
            throw new \RuntimeException('queueEmail() failed for test row ' . $itIndex);
        }

        $itIds[] = $itId;
    }

    return $itIds;
}

/**
 * 📥 Queue ONE email of any template through the real queueEmail(); returns its ID.
 * (#122/#123 scenarios need password-reset and receipt rows, not just
 * verification ones.) The address must still be a ".invalid" test address
 * matching itAddressPattern, so itClear() removes it.
 */
function itQueueOne(string $itAddress, string $itTemplateKey, array $itParams): int
{
    $itId = queueEmail($itAddress, $itTemplateKey, $itParams);

    if ($itId === false) {
        throw new \RuntimeException('queueEmail() failed for a ' . $itTemplateKey . ' test row');
    }

    return $itId;
}

/**
 * 📄 Read one queue row.
 */
function itRow(int $itId): array
{
    $itRows = itSelect('SELECT * FROM `tblEmailQueue` WHERE `emailID` = ?', 'i', [$itId]);

    if (count($itRows) !== 1) {
        return [];
    }

    return $itRows[0];
}

/**
 * 🏷️ Read one queue row's status ('' when the row does not exist - for
 * example because the clean-up deleted it).
 */
function itStatus(int $itId): string
{
    $itRow = itRow($itId);

    if (!isset($itRow['status'])) {
        return '';
    }

    return (string) $itRow['status'];
}

/**
 * 👶👶 Start two child runner processes on the same queue, release them
 * together, wait for both, and return their results and every send.
 */
function itRunTwoChildren(bool $itUseLock): array
{
    $itWorkDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cuercode_it_' . bin2hex(random_bytes(6));
    mkdir($itWorkDir, 0700);

    $itGoFile    = $itWorkDir . DIRECTORY_SEPARATOR . 'go';
    $itSendsFile = $itWorkDir . DIRECTORY_SEPARATOR . 'sends.txt';
    $itChildren  = [];

    if ($itUseLock) {
        $itUseLockFlag = '1';
    } else {
        $itUseLockFlag = '0';
    }

    foreach (['A', 'B'] as $itName) {
        $itCommand = [
            PHP_BINARY,
            __FILE__,
            '--child=' . $itName,
            '--use-lock=' . $itUseLockFlag,
            '--ready-file=' . $itWorkDir . DIRECTORY_SEPARATOR . 'ready-' . $itName,
            '--go-file=' . $itGoFile,
            '--sends-file=' . $itSendsFile,
            '--result-file=' . $itWorkDir . DIRECTORY_SEPARATOR . 'result-' . $itName . '.json',
        ];
        // 📄 Output goes to files, not pipes, so a chatty child can never block.
        $itDescriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $itWorkDir . DIRECTORY_SEPARATOR . 'out-' . $itName . '.txt', 'w'],
            2 => ['file', $itWorkDir . DIRECTORY_SEPARATOR . 'err-' . $itName . '.txt', 'w'],
        ];
        $itProcess = proc_open($itCommand, $itDescriptors, $itPipes);
        fclose($itPipes[0]);
        $itChildren[$itName] = $itProcess;
    }

    // ⏳ Wait (up to 30 seconds) until both children have started up.
    $itReadyDeadline = microtime(true) + 30;

    while (microtime(true) < $itReadyDeadline) {
        if (is_file($itWorkDir . DIRECTORY_SEPARATOR . 'ready-A') && is_file($itWorkDir . DIRECTORY_SEPARATOR . 'ready-B')) {
            break;
        }

        usleep(20000);
    }

    // 🏁 Go.
    file_put_contents($itGoFile, 'go');

    $itOutcome = ['results' => [], 'exits' => [], 'sends' => []];

    foreach ($itChildren as $itName => $itProcess) {
        $itOutcome['exits'][$itName] = proc_close($itProcess);
        $itResultText                = @file_get_contents($itWorkDir . DIRECTORY_SEPARATOR . 'result-' . $itName . '.json');
        $itDecoded                   = json_decode((string) $itResultText, true);

        if (!is_array($itDecoded)) {
            $itDecoded = ['status' => 'no_result'];
        }

        $itOutcome['results'][$itName] = $itDecoded;

        if ($itOutcome['exits'][$itName] !== 0) {
            echo '    child ' . $itName . ' stderr: ' . trim((string) @file_get_contents($itWorkDir . DIRECTORY_SEPARATOR . 'err-' . $itName . '.txt')) . PHP_EOL;
        }
    }

    $itSendLines = @file($itSendsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if (is_array($itSendLines)) {
        foreach ($itSendLines as $itLine) {
            $itParts              = explode(' ', $itLine, 2);
            $itOutcome['sends'][] = ['child' => $itParts[0], 'to' => $itParts[1]];
        }
    }

    // 🧹 Remove the work files.
    foreach ((array) glob($itWorkDir . DIRECTORY_SEPARATOR . '*') as $itWorkFile) {
        @unlink((string) $itWorkFile);
    }

    @rmdir($itWorkDir);

    return $itOutcome;
}

/**
 * ✅ Check a two-runner outcome: every queued address sent exactly once and
 * every row marked sent.
 */
function itCheckTwoRunners(string $itScenario, array $itOutcome, array $itIds): void
{
    $itCounts = [];

    foreach ($itOutcome['sends'] as $itSend) {
        if (!isset($itCounts[$itSend['to']])) {
            $itCounts[$itSend['to']] = 0;
        }

        $itCounts[$itSend['to']]++;
    }

    $itTwice = 0;

    foreach ($itCounts as $itTimes) {
        if ($itTimes > 1) {
            $itTwice++;
        }
    }

    $itSentRows = itSelect(
        "SELECT COUNT(*) AS `n` FROM `tblEmailQueue` WHERE `toAddress` LIKE ? AND `status` = 'sent'",
        's',
        [$GLOBALS['itAddressPattern']]
    );

    itCheck($itScenario, $itOutcome['exits']['A'] === 0 && $itOutcome['exits']['B'] === 0, 'both child processes finished cleanly');
    itCheck($itScenario, count($itCounts) === count($itIds), 'all ' . count($itIds) . ' addresses were sent (' . count($itCounts) . ' distinct)');
    itCheck($itScenario, $itTwice === 0 && count($itOutcome['sends']) === count($itIds), 'no address was sent twice (' . count($itOutcome['sends']) . ' sends in total)');
    itCheck($itScenario, (int) $itSentRows[0]['n'] === count($itIds), 'every row is marked sent');
}

// 📤 Stand-in senders for the in-process scenarios - NEVER sendEmailNow().
$itSendCalls = 0;
$itOkSender  = static function (string $itTo, string $itSubject, string $itBody, ?string $itToName) use (&$itSendCalls): array {
    $itSendCalls++;
    return [true, null];
};
$itBadRecipientSender = static function (string $itTo, string $itSubject, string $itBody, ?string $itToName) use (&$itSendCalls): array {
    $itSendCalls++;
    return [false, 'HTTP 400 | graph_error_code: ErrorInvalidRecipients'];
};
$itBlockedSender = static function (string $itTo, string $itSubject, string $itBody, ?string $itToName) use (&$itSendCalls): array {
    $itSendCalls++;
    return [false, 'skipped: no Graph access token available'];
};

$itGateway = emailQueueDatabaseGateway();

echo 'Server: ' . itSelect('SELECT VERSION() AS `v`')[0]['v'] . PHP_EOL;
itCheck('0 set-up', checkEmailQueueDatabase() === null, 'checkEmailQueueDatabase() finds the database and the queue table');
$itLockName = itSelect('SELECT ' . CUERCODE_EMAIL_QUEUE_LOCK_NAME_SQL . ' AS `lockName`', 's', [CUERCODE_EMAIL_QUEUE_LOCK_PREFIX])[0]['lockName'];
itCheck('0 set-up', strlen((string) $itLockName) === 48 && str_starts_with((string) $itLockName, CUERCODE_EMAIL_QUEUE_LOCK_PREFIX), 'the lock name is 48 characters and starts with the prefix');
// ⏳ #123: under the REAL start-up code the runner gets fn_auth.php's lifetimes.
itCheck('0 set-up', emailQueueLinkLifetimes() === ['verification' => 86400, 'password_reset' => 3600, 'email_sign_in' => 900], "emailQueueLinkLifetimes() gives fn_auth.php's lifetimes (24 hours, 60 minutes, and 15 minutes for the no-JavaScript email sign-in, #163) under the real bootstrap");
// 🧹 #122: the two seeded clean-up settings, read through the real getConfig() (integer type).
itCheck(
    '0 set-up',
    emailQueueHousekeepingOptions('getConfig') === ['retentionDays' => 30, 'givenUpRetentionDays' => 90, 'errorLogRetentionDays' => 90, 'visitorDetailsRetentionDays' => 90, 'abuseReportRetentionDays' => 365, 'invalidSettings' => []],
    'emailQueueHousekeepingOptions(getConfig) reads the seeded 30 / 90 days, with no invalid settings'
);

// ==============================================================================
// 1️⃣ Claim rules on the real SQL
// ==============================================================================
echo PHP_EOL . 'Scenario 1: claim rules on the real SQL' . PHP_EOL;
itClear();
$itIds = itQueue(4);
itCheck('1 claim rules', claimEmailQueueRow($itIds[0]) === true, 'a due row can be claimed');
itCheck('1 claim rules', claimEmailQueueRow($itIds[0]) === false, 'the same row cannot be claimed a second time');
itExec('UPDATE `tblEmailQueue` SET `nextAttemptAt` = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE `emailID` = ?', 'i', [$itIds[1]]);
itCheck('1 claim rules', claimEmailQueueRow($itIds[1]) === false, 'a row that is not due yet cannot be claimed');
itExec('UPDATE `tblEmailQueue` SET `attempts` = `maxAttempts` WHERE `emailID` = ?', 'i', [$itIds[2]]);
itCheck('1 claim rules', claimEmailQueueRow($itIds[2]) === false, 'a row with no attempts left cannot be claimed');
itExec("UPDATE `tblEmailQueue` SET `status` = 'sent' WHERE `emailID` = ?", 'i', [$itIds[3]]);
itCheck('1 claim rules', claimEmailQueueRow($itIds[3]) === false, 'a sent row cannot be claimed');
itCheck('1 claim rules', settleEmailQueueSuccess($itIds[0]) === true, 'the claimed row can be marked sent');
itCheck('1 claim rules', settleEmailQueueSuccess($itIds[0]) === false, 'a row that is no longer "sending" cannot be settled again');
itCheck('1 claim rules', releaseEmailQueueClaim($itIds[1], 'test') === false, 'a row that was never claimed cannot be released');

// ==============================================================================
// 2️⃣ Two runner processes at once, lock ON
// ==============================================================================
echo PHP_EOL . 'Scenario 2: two runner processes at once, lock ON' . PHP_EOL;
itClear();
$itIds     = itQueue(16);
$itOutcome = itRunTwoChildren(true);
itCheckTwoRunners('2 two runners, lock on', $itOutcome, $itIds);
$itBusy = [];

foreach ($itOutcome['results'] as $itName => $itChildResult) {
    $itChildSent = 0;

    if (isset($itChildResult['sent'])) {
        $itChildSent = (int) $itChildResult['sent'];
    }

    echo '    child ' . $itName . ': status ' . (string) $itChildResult['status'] . ', sent ' . $itChildSent . PHP_EOL;

    if ($itChildResult['status'] === 'busy') {
        $itBusy[] = $itName;
    }
}

if (count($itBusy) > 0) {
    echo '    busy child: ' . implode(', ', $itBusy) . PHP_EOL;
} else {
    echo '    busy child: none' . PHP_EOL;
}
itCheck('2 two runners, lock on', count($itBusy) === 1, 'exactly one child found the lock taken and stood down');

// ==============================================================================
// 3️⃣ Two runner processes at once, lock OFF (the row claim alone)
// ==============================================================================
echo PHP_EOL . 'Scenario 3: two runner processes at once, lock OFF (row claims alone)' . PHP_EOL;
itClear();
$itIds     = itQueue(16);
$itOutcome = itRunTwoChildren(false);
itCheckTwoRunners('3 two runners, lock off', $itOutcome, $itIds);
$itSplit = ['A' => 0, 'B' => 0];

foreach ($itOutcome['sends'] as $itSend) {
    $itSplit[$itSend['child']]++;
}

echo '    send split: A sent ' . $itSplit['A'] . ', B sent ' . $itSplit['B'] . PHP_EOL;

// ==============================================================================
// 4️⃣ Retry with back-off
// ==============================================================================
echo PHP_EOL . 'Scenario 4: retry with back-off' . PHP_EOL;
itClear();
$itIds       = itQueue(1);
$itSendCalls = 0;
$itResult    = runEmailQueueBatch($itGateway, $itBadRecipientSender, []);
$itRow       = itRow($itIds[0]);
$itWait      = itSelect('SELECT TIMESTAMPDIFF(SECOND, NOW(), `nextAttemptAt`) AS `waitSeconds` FROM `tblEmailQueue` WHERE `emailID` = ?', 'i', [$itIds[0]]);
itCheck('4 retry', $itRow['status'] === 'queued' && (int) $itRow['attempts'] === 1, 'after one failure the row is queued with attempts 1');
itCheck('4 retry', (int) $itWait[0]['waitSeconds'] >= 100 && (int) $itWait[0]['waitSeconds'] <= 125, 'the retry is about 2 minutes away (' . (int) $itWait[0]['waitSeconds'] . ' seconds)');
itCheck('4 retry', $itResult['retrying'] === 1, 'the batch reports 1 retrying');
$itCallsBefore = $itSendCalls;
runEmailQueueBatch($itGateway, $itBadRecipientSender, []);
itCheck('4 retry', $itSendCalls === $itCallsBefore, 'an immediate second run does not send it again');

// ==============================================================================
// 5️⃣ Stuck-row recovery
// ==============================================================================
echo PHP_EOL . 'Scenario 5: stuck-row recovery' . PHP_EOL;
itClear();
$itIds = itQueue(2);
itExec("UPDATE `tblEmailQueue` SET `status` = 'sending', `updatedAt` = DATE_SUB(NOW(), INTERVAL 11 MINUTE) WHERE `emailID` = ?", 'i', [$itIds[0]]);
itExec("UPDATE `tblEmailQueue` SET `status` = 'sending', `updatedAt` = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE `emailID` = ?", 'i', [$itIds[1]]);
$itSendCalls = 0;
$itResult    = runEmailQueueBatch($itGateway, $itOkSender, []);
$itRowOld    = itRow($itIds[0]);
$itRowNew    = itRow($itIds[1]);
itCheck('5 stuck recovery', $itRowOld['status'] === 'queued' && (int) $itRowOld['attempts'] === 1, 'a row "sending" for 11 minutes is put back with one attempt used');
itCheck('5 stuck recovery', str_starts_with((string) $itRowOld['lastError'], 'Recovered'), 'its lastError explains what happened');
itCheck('5 stuck recovery', $itRowNew['status'] === 'sending', 'a row "sending" for 5 minutes is left alone');
itCheck('5 stuck recovery', $itResult['recovered'] === 1 && $itSendCalls === 0, 'recovered 1, and nothing re-sent in the same run');

// ==============================================================================
// 6️⃣ Sending blocked
// ==============================================================================
echo PHP_EOL . 'Scenario 6: sending blocked (no Graph token)' . PHP_EOL;
itClear();
$itIds    = itQueue(1);
$itResult = runEmailQueueBatch($itGateway, $itBlockedSender, []);
$itRow    = itRow($itIds[0]);
itCheck('6 blocked', $itRow['status'] === 'queued' && (int) $itRow['attempts'] === 0, 'the row is put back with no attempt used');
itCheck('6 blocked', $itResult['status'] === 'system_problem', 'the batch reports system_problem');

// ==============================================================================
// 7️⃣ Busy and stalled (the lock held by a second, separate connection)
// ==============================================================================
echo PHP_EOL . 'Scenario 7: busy and stalled' . PHP_EOL;
itClear();
$itIds          = itQueue(1);
$itOther        = itConnect($itEnv, $itPort);
$itLockStmt     = $itOther->prepare('SELECT GET_LOCK(' . CUERCODE_EMAIL_QUEUE_LOCK_NAME_SQL . ', 0) AS `lockResult`');
$itLockPrefix   = CUERCODE_EMAIL_QUEUE_LOCK_PREFIX;
$itLockStmt->bind_param('s', $itLockPrefix);
$itLockStmt->execute();
$itOtherGotLock = (int) $itLockStmt->get_result()->fetch_assoc()['lockResult'] === 1;
$itLockStmt->close();
itCheck('7 busy and stalled', $itOtherGotLock, 'a second connection takes the queue lock');
$itResult = runEmailQueueBatch($itGateway, $itOkSender, []);
itCheck('7 busy and stalled', $itResult['status'] === 'busy', 'a run while the lock is held is busy');
itExec('UPDATE `tblEmailQueue` SET `nextAttemptAt` = DATE_SUB(NOW(), INTERVAL 11 MINUTE) WHERE `emailID` = ?', 'i', [$itIds[0]]);
$itResult = runEmailQueueBatch($itGateway, $itOkSender, []);
itCheck('7 busy and stalled', $itResult['status'] === 'stalled', 'with an email overdue by 11 minutes the run reports stalled');
$itReleaseStmt = $itOther->prepare('SELECT RELEASE_LOCK(' . CUERCODE_EMAIL_QUEUE_LOCK_NAME_SQL . ') AS `releaseResult`');
$itReleaseStmt->bind_param('s', $itLockPrefix);
$itReleaseStmt->execute();
$itReleaseStmt->get_result();
$itReleaseStmt->close();
$itResult = runEmailQueueBatch($itGateway, $itOkSender, []);
itCheck('7 busy and stalled', $itResult['status'] === 'ok' && $itResult['sent'] === 1, 'once the lock is released a run sends the email');
$itOther->close();

// ==============================================================================
// 8️⃣ Counts
// ==============================================================================
echo PHP_EOL . 'Scenario 8: counts' . PHP_EOL;
itClear();
$itCountsBefore = countEmailQueueRowsByStatus();
$itDueBefore    = countDueEmailQueueRows();
$itIds          = itQueue(3);
itExec("UPDATE `tblEmailQueue` SET `status` = 'sent' WHERE `emailID` = ?", 'i', [$itIds[1]]);
itExec("UPDATE `tblEmailQueue` SET `status` = 'failed', `attempts` = 5 WHERE `emailID` = ?", 'i', [$itIds[2]]);
$itCountsAfter = countEmailQueueRowsByStatus();
$itDueAfter    = countDueEmailQueueRows();
itCheck('8 counts', is_array($itCountsBefore) && is_array($itCountsAfter), 'countEmailQueueRowsByStatus() works');
itCheck(
    '8 counts',
    $itCountsAfter['queued'] - $itCountsBefore['queued'] === 1
        && $itCountsAfter['sent'] - $itCountsBefore['sent'] === 1
        && $itCountsAfter['failed'] - $itCountsBefore['failed'] === 1
        && $itCountsAfter['sending'] === $itCountsBefore['sending'],
    'status counts moved by exactly the rows set up (queued +1, sent +1, failed +1)'
);
itCheck('8 counts', is_int($itDueBefore) && $itDueAfter - $itDueBefore === 1, 'the due count moved by exactly 1');
$itOverdueBefore = countOverdueEmailQueueRows(10);
itExec('UPDATE `tblEmailQueue` SET `nextAttemptAt` = DATE_SUB(NOW(), INTERVAL 11 MINUTE) WHERE `emailID` = ?', 'i', [$itIds[0]]);
$itOverdueAfter = countOverdueEmailQueueRows(10);
itCheck('8 counts', is_int($itOverdueBefore) && $itOverdueAfter - $itOverdueBefore === 1, 'an email due for 11 minutes counts as overdue (stall alarm)');

// ==============================================================================
// 9️⃣ #122: the details (which hold the one-time link) are cleared on send
// ==============================================================================
echo PHP_EOL . 'Scenario 9: details cleared on send (#122)' . PHP_EOL;
itClear();
$itIds       = itQueue(1);
$itSendCalls = 0;
runEmailQueueBatch($itGateway, $itOkSender, []);
$itRow = itRow($itIds[0]);
itCheck('9 details cleared on send', $itRow['status'] === 'sent', 'the email is sent');
itCheck('9 details cleared on send', $itRow['paramsJSON'] === null, 'its paramsJSON is NULL in the database');
$itIds = itQueue(1);
runEmailQueueBatch($itGateway, $itBadRecipientSender, []);
$itRow = itRow($itIds[0]);
itCheck('9 details cleared on send', $itRow['status'] === 'queued', 'a failed attempt puts the email back for a retry');
itCheck('9 details cleared on send', str_contains((string) $itRow['paramsJSON'], 'token=IT'), 'a retry keeps its paramsJSON (the link is still there for the next attempt)');

// ==============================================================================
// 🔟 #123: expired links are skipped by the real bulk UPDATE
// ==============================================================================
echo PHP_EOL . 'Scenario 10: expired links skipped (#123)' . PHP_EOL;
itClear();
$itV1 = itQueueOne('it-01@it.cuercode.invalid', 'verification', ['verificationUrl' => 'https://cuercode.invalid/verify-email?token=ITV1']);
$itV2 = itQueueOne('it-02@it.cuercode.invalid', 'verification', ['verificationUrl' => 'https://cuercode.invalid/verify-email?token=ITV2']);
$itR1 = itQueueOne('it-03@it.cuercode.invalid', 'password_reset', ['resetUrl' => 'https://cuercode.invalid/reset-password?token=ITR1']);
$itR2 = itQueueOne('it-04@it.cuercode.invalid', 'password_reset', ['resetUrl' => 'https://cuercode.invalid/reset-password?token=ITR2']);
$itR3 = itQueueOne('it-05@it.cuercode.invalid', 'password_reset', ['resetUrl' => 'https://cuercode.invalid/reset-password?token=ITR3']);
itExec('UPDATE `tblEmailQueue` SET `createdAt` = DATE_SUB(NOW(), INTERVAL 24 HOUR) WHERE `emailID` = ?', 'i', [$itV1]);
itExec('UPDATE `tblEmailQueue` SET `createdAt` = DATE_SUB(NOW(), INTERVAL 59 MINUTE) WHERE `emailID` = ?', 'i', [$itR1]);
itExec('UPDATE `tblEmailQueue` SET `createdAt` = DATE_SUB(NOW(), INTERVAL 57 MINUTE) WHERE `emailID` = ?', 'i', [$itR3]);
$itSendCalls = 0;
$itResult    = runEmailQueueBatch($itGateway, $itOkSender, []);

foreach (['V1 (24 hours old)' => $itV1, 'R1 (59 minutes old)' => $itR1] as $itName => $itId) {
    $itRow = itRow($itId);
    itCheck('10 expired links', $itRow['status'] === 'skipped', $itName . ' is skipped');
    itCheck('10 expired links', $itRow['paramsJSON'] === null, $itName . ' has its paramsJSON cleared');
    itCheck('10 expired links', str_starts_with((string) $itRow['lastError'], 'Not sent: its one-time link had expired'), $itName . ' has a plain-English lastError');
}

foreach (['V2 (fresh)' => $itV2, 'R2 (fresh)' => $itR2, 'R3 (57 minutes old, 3 minutes left)' => $itR3] as $itName => $itId) {
    itCheck('10 expired links', itRow($itId)['status'] === 'sent', $itName . ' is sent');
}

itCheck('10 expired links', $itResult['expired'] === 2 && $itSendCalls === 3, 'expired 2 and exactly 3 sends (expired ' . (int) $itResult['expired'] . ', sends ' . $itSendCalls . ')');

// ==============================================================================
// 🔟b #123: the per-email check on real SQL (the clean-up is skipped without the lock)
// ==============================================================================
echo PHP_EOL . 'Scenario 10b: per-email expiry check on real SQL (#123)' . PHP_EOL;
itClear();
$itIds = itQueue(1);
itExec('UPDATE `tblEmailQueue` SET `createdAt` = DATE_SUB(NOW(), INTERVAL 24 HOUR) WHERE `emailID` = ?', 'i', [$itIds[0]]);
$itSendCalls = 0;
$itResult    = runEmailQueueBatch($itGateway, $itOkSender, ['useLock' => false]);
$itRow       = itRow($itIds[0]);
itCheck('10b per-email expiry', $itRow['status'] === 'skipped' && $itRow['paramsJSON'] === null, 'skipped through the re-read (ageSeconds from TIMESTAMPDIFF), details cleared');
itCheck('10b per-email expiry', $itResult['expired'] === 1 && $itSendCalls === 0, 'expired 1 and nothing sent');

// ==============================================================================
// 1️⃣1️⃣ #122: given-up details are cleared after 24 hours; updatedAt is kept
// ==============================================================================
echo PHP_EOL . 'Scenario 11: given-up details cleared after 24 hours (#122)' . PHP_EOL;
itClear();
$itIds = itQueue(2);
itExec(
    "UPDATE `tblEmailQueue` SET `status` = 'failed', `attempts` = 5, `createdAt` = DATE_SUB(NOW(), INTERVAL 25 HOUR), `updatedAt` = DATE_SUB(NOW(), INTERVAL 25 HOUR) WHERE `emailID` = ?",
    'i',
    [$itIds[0]]
);
itExec(
    "UPDATE `tblEmailQueue` SET `status` = 'failed', `attempts` = 5, `createdAt` = DATE_SUB(NOW(), INTERVAL 23 HOUR), `updatedAt` = DATE_SUB(NOW(), INTERVAL 23 HOUR) WHERE `emailID` = ?",
    'i',
    [$itIds[1]]
);
$itResult  = runEmailQueueBatch($itGateway, $itOkSender, []);
$itRowOld  = itRow($itIds[0]);
$itRowNew  = itRow($itIds[1]);
$itAgeRows = itSelect('SELECT TIMESTAMPDIFF(HOUR, `updatedAt`, NOW()) AS `hoursSince` FROM `tblEmailQueue` WHERE `emailID` = ?', 'i', [$itIds[0]]);
itCheck('11 given-up details', $itRowOld['paramsJSON'] === null && $itRowOld['status'] === 'failed', 'a row given up 25 hours ago loses its details and stays failed');
itCheck('11 given-up details', (int) $itAgeRows[0]['hoursSince'] >= 24, 'its updatedAt was kept (' . (int) $itAgeRows[0]['hoursSince'] . ' hours ago), so the give-up time is still readable');
itCheck('11 given-up details', $itRowNew['paramsJSON'] !== null, 'a row given up 23 hours ago keeps its details (the re-send window)');
itCheck('11 given-up details', $itResult['detailsCleared'] === 1, 'detailsCleared 1');

// ==============================================================================
// 1️⃣2️⃣ #122: old records are deleted (the real DELETEs); nothing waiting is touched
// ==============================================================================
echo PHP_EOL . 'Scenario 12: old records deleted (#122)' . PHP_EOL;
itClear();
$itIds       = itQueue(5);
$itReceiptQ  = itQueueOne('it-06@it.cuercode.invalid', 'receipt', ['orderNumber' => 'IT-1', 'amount' => '1.00']);
$itReceiptF  = itQueueOne('it-07@it.cuercode.invalid', 'receipt', ['orderNumber' => 'IT-2', 'amount' => '2.00']);

// 📌 Each row is aged with its own prepared statement (the status, attempts and
//    age are bound as parameters; nothing is joined into the SQL text).
foreach ([[$itIds[0], 'sent', 0, 31], [$itIds[1], 'sent', 0, 29], [$itIds[2], 'skipped', 0, 31], [$itIds[3], 'failed', 5, 31], [$itIds[4], 'failed', 5, 91]] as $itSetup) {
    itExec(
        'UPDATE `tblEmailQueue` SET `status` = ?, `attempts` = ?, `createdAt` = DATE_SUB(NOW(), INTERVAL ? DAY) WHERE `emailID` = ?',
        'siii',
        [$itSetup[1], $itSetup[2], $itSetup[3], $itSetup[0]]
    );
}

foreach ([[$itReceiptQ, 'queued', 0], [$itReceiptF, 'failed', 2]] as $itSetup) {
    itExec(
        'UPDATE `tblEmailQueue` SET `status` = ?, `attempts` = ?, `createdAt` = DATE_SUB(NOW(), INTERVAL 100 DAY), `nextAttemptAt` = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE `emailID` = ?',
        'sii',
        [$itSetup[1], $itSetup[2], $itSetup[0]]
    );
}

$itSendCalls = 0;
$itResult    = runEmailQueueBatch($itGateway, $itOkSender, []);
itCheck('12 old records deleted', itRow($itIds[0]) === [], 'a record sent 31 days ago is deleted');
itCheck('12 old records deleted', itRow($itIds[2]) === [], 'a record skipped 31 days ago is deleted');
itCheck('12 old records deleted', itRow($itIds[4]) === [], 'a record given up 91 days ago is deleted');
itCheck('12 old records deleted', $itResult['purged'] === 3, 'purged 3 (' . (int) $itResult['purged'] . ')');
itCheck('12 old records deleted', itRow($itIds[1]) !== [], 'a record sent 29 days ago is kept');
itCheck('12 old records deleted', itRow($itIds[3]) !== [], 'a record given up 31 days ago is kept (90-day period)');
itCheck('12 old records deleted', itStatus($itReceiptQ) === 'queued', 'a 100-day-old QUEUED email is never deleted or changed');
itCheck('12 old records deleted', itStatus($itReceiptF) === 'failed', 'a 100-day-old failed email that still has attempts left is never deleted');
itCheck('12 old records deleted', $itSendCalls === 0, 'nothing was sent (nothing was due)');

// ------------------------------------------------------------------------------
// #129: the activity log's email entries (they name the recipient) are deleted
// after the same periods. Everything else in the log is left alone.
// ------------------------------------------------------------------------------
itExec("DELETE FROM `tblActivityLog` WHERE `entityType` = ? OR `activityType` = 'it_other'", 's', ['email']);
$itLogCases = [
    'sent-old'    => ['email_sent', 'email', 31, false],
    'sent-new'    => ['email_sent', 'email', 29, true],
    'queued-old'  => ['email_queued', 'email', 31, false],
    'skipped-old' => ['email_skipped', 'email', 31, false],
    'failed-31'   => ['email_failed', 'email', 31, true],
    'failed-old'  => ['email_failed', 'email', 91, false],
    'other-old'   => ['it_other', 'user', 400, true],
];
foreach ($itLogCases as $itTag => $itCase) {
    itExec(
        'INSERT INTO `tblActivityLog` (`activityType`, `activityDetail`, `entityType`, `createdAt`) VALUES (?, ?, ?, DATE_SUB(NOW(), INTERVAL ? DAY))',
        'sssi',
        [$itCase[0], 'To: it-' . $itTag . '@it.cuercode.invalid | Subject: x | y', $itCase[1], $itCase[2]]
    );
}
$itResult = runEmailQueueBatch($itGateway, $itOkSender, []);
foreach ($itLogCases as $itTag => $itCase) {
    $itLeft = (int) itSelect('SELECT COUNT(*) AS `n` FROM `tblActivityLog` WHERE `activityDetail` = ?', 's', ['To: it-' . $itTag . '@it.cuercode.invalid | Subject: x | y'])[0]['n'];
    itCheck('12b old email log entries deleted', ($itLeft === 1) === $itCase[3], 'activity log: ' . $itCase[0] . ' entry ' . $itCase[2] . ' days old is ' . ($itCase[3] ? 'kept' : 'deleted'));
}
itCheck('12b old email log entries deleted', ($itResult['logPurged'] ?? null) === 4, 'the run reports 4 activity-log entries removed');
itCheck('12b old email log entries deleted', $itResult['housekeepingFailures'] === 0, 'no clean-up step failed');
$itResult = runEmailQueueBatch($itGateway, $itOkSender, ['retentionDays' => 7, 'givenUpRetentionDays' => 10]);
itCheck('12b old email log entries deleted', ($itResult['logPurged'] ?? null) === 2, 'shorter periods set by the admin apply to the log too (the 29-day sent and 31-day failed entries go)');
itExec("DELETE FROM `tblActivityLog` WHERE `entityType` = ? OR `activityType` = 'it_other'", 's', ['email']);

// ------------------------------------------------------------------------------
// #179: the error log is deleted after its period; other activity entries keep
// their visitor address and browser name only for theirs (the entries stay);
// the admin change log is never touched.
// ------------------------------------------------------------------------------
itExec('DELETE FROM `tblErrorLog` WHERE `errorTitle` LIKE ?', 's', ['it-179-%']);
// 📌 Fourth-round review, Low 4: the clean-up now works through the activity
//    log in row-number order and never moves past an entry that is not old
//    yet. On a real site row numbers follow time; here the earlier scenarios
//    wrote recent entries first, so they are removed (the database is this
//    script's own throwaway one) and the old entry is written first.
itExec('DELETE FROM `tblActivityLog` WHERE `activityID` > ?', 'i', [0]);
itExec('DELETE FROM `tblSettings` WHERE `settingKey` = ?', 's', ['activity_visitor_details_cleared_to']);
foreach (['old' => 91, 'new' => 89] as $itTag => $itDays) {
    itExec(
        "INSERT INTO `tblErrorLog` (`errorSeverity`, `errorTitle`, `requestIP`, `userAgent`, `createdAt`) VALUES ('warning', ?, '203.0.113.179', 'IT browser', DATE_SUB(NOW(), INTERVAL ? DAY))",
        'si',
        ['it-179-' . $itTag, $itDays]
    );
    itExec(
        "INSERT INTO `tblActivityLog` (`activityType`, `activityDetail`, `entityType`, `requestIP`, `userAgent`, `createdAt`) VALUES (?, 'IT 179', 'user', '203.0.113.179', 'IT browser', DATE_SUB(NOW(), INTERVAL ? DAY))",
        'si',
        ['it_179_' . $itTag, $itDays]
    );
}
// 📌 The admin change log comes from upgrade 0002, and this script loads only
//    install.sql. Fourth-round review, Info 3: so the check below never ran in
//    CI. The upgrades are now applied here when the table is missing (the
//    database is this script's own throwaway one), and its absence is a
//    failure, so the check always runs.
$itAuditLogExists = static function (): bool {
    return (int) itSelect("SELECT COUNT(*) AS `n` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ?", 's', ['tblAdminAuditLog'])[0]['n'] === 1;
};
$itHasAuditLog = $itAuditLogExists();
if (!$itHasAuditLog) {
    require_once $itSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_migrations.php';
    $itUpgrade     = migrationRun(getDB(), 'apply', 'test', migrationDefaultDirectory(), migrationDefaultInstallSqlPath());
    $itHasAuditLog = $itAuditLogExists();
    itCheck('12c error log and visitor details (#179)', $itUpgrade['status'] === 'ok' && $itHasAuditLog, 'set-up (fourth-round review Info 3): the database upgrades were applied, so the admin change log exists and the "not touched" check runs (' . $itUpgrade['status'] . ')');
}
if ($itHasAuditLog) {
    itExec(
        "INSERT INTO `tblAdminAuditLog` (`actorType`, `actorLabel`, `action`, `entityType`, `entityKey`, `outcome`, `requestIP`, `userAgent`, `createdAt`) VALUES ('cli', 'cli:it', ?, 'setting', 'it_179', 'success', '203.0.113.179', 'IT browser', DATE_SUB(NOW(), INTERVAL 400 DAY))",
        's',
        ['it_179']
    );
}
$itCount179 = static function (string $itSql, string $itValue): int {
    return (int) itSelect($itSql, 's', [$itValue])[0]['n'];
};
$itResult = runEmailQueueBatch($itGateway, $itOkSender, []);
itCheck('12c error log and visitor details (#179)', $itCount179('SELECT COUNT(*) AS `n` FROM `tblErrorLog` WHERE `errorTitle` = ?', 'it-179-old') === 0 && $itCount179('SELECT COUNT(*) AS `n` FROM `tblErrorLog` WHERE `errorTitle` = ?', 'it-179-new') === 1, 'an error-log row 91 days old is deleted, one 89 days old is kept (default 90 days)');
$itOldEntry = itSelect('SELECT `requestIP`, `userAgent`, `activityDetail` FROM `tblActivityLog` WHERE `activityType` = ?', 's', ['it_179_old']);
$itNewEntry = itSelect('SELECT `requestIP`, `userAgent` FROM `tblActivityLog` WHERE `activityType` = ?', 's', ['it_179_new']);
itCheck('12c error log and visitor details (#179)', count($itOldEntry) === 1 && $itOldEntry[0]['requestIP'] === null && $itOldEntry[0]['userAgent'] === null && $itOldEntry[0]['activityDetail'] === 'IT 179', 'an activity entry 91 days old is KEPT, without its visitor address and browser name');
itCheck('12c error log and visitor details (#179)', count($itNewEntry) === 1 && $itNewEntry[0]['requestIP'] === '203.0.113.179' && $itNewEntry[0]['userAgent'] === 'IT browser', 'an activity entry 89 days old keeps both');
if ($itHasAuditLog) {
    itCheck('12c error log and visitor details (#179)', $itCount179("SELECT COUNT(*) AS `n` FROM `tblAdminAuditLog` WHERE `action` = ? AND `requestIP` = '203.0.113.179' AND `userAgent` = 'IT browser'", 'it_179') === 1, 'the admin change log is not touched (a 400-day-old row keeps its address and browser name)');
} else {
    itCheck('12c error log and visitor details (#179)', false, 'the admin change log is missing even after the upgrades, so the "not touched" check could not run');
}
itCheck('12c error log and visitor details (#179)', ($itResult['errorLogPurged'] ?? 0) >= 1 && ($itResult['visitorDetailsCleared'] ?? 0) >= 1 && $itResult['housekeepingFailures'] === 0, 'the run reports what it removed, and no clean-up step failed: ' . json_encode([$itResult['errorLogPurged'] ?? null, $itResult['visitorDetailsCleared'] ?? null]));
$itResult = runEmailQueueBatch($itGateway, $itOkSender, ['errorLogRetentionDays' => 30, 'visitorDetailsRetentionDays' => 30]);
$itNewEntry = itSelect('SELECT `requestIP` FROM `tblActivityLog` WHERE `activityType` = ?', 's', ['it_179_new']);
itCheck('12c error log and visitor details (#179)', $itCount179('SELECT COUNT(*) AS `n` FROM `tblErrorLog` WHERE `errorTitle` = ?', 'it-179-new') === 0 && count($itNewEntry) === 1 && $itNewEntry[0]['requestIP'] === null, 'shorter periods set by an admin (30 days) apply to both');
itExec('DELETE FROM `tblErrorLog` WHERE `errorTitle` LIKE ?', 's', ['it-179-%']);
itExec('DELETE FROM `tblActivityLog` WHERE `activityType` LIKE ?', 's', ['it_179_%']);
if ($itHasAuditLog) {
    itExec('DELETE FROM `tblAdminAuditLog` WHERE `action` = ?', 's', ['it_179']);
}

// 🙈 #179: no email address reaches the error log in clear - not in the
//    title, the detail (a database error can quote a value), the backtrace,
//    the page address, the headers or the extra data.
$itServerBefore = $_SERVER;
$_SERVER['REQUEST_URI']  = '/forgot-password/?email=it-179-url@it.cuercode.invalid';
$_SERVER['HTTP_REFERER'] = 'https://example.invalid/?who=it-179-referer@it.cuercode.invalid';
logError('warning', 'it-179-mask for it-179-title@it.cuercode.invalid', "Duplicate entry 'it-179-detail@it.cuercode.invalid' for key 'uq_email'", ['to' => 'it-179-extra@it.cuercode.invalid', 'backtrace' => '#0 send(it-179-trace@it.cuercode.invalid)']);
$_SERVER = $itServerBefore;
$itMasked = itSelect("SELECT CONCAT_WS('|', `errorTitle`, `errorDetail`, `errorBacktrace`, `requestURL`, `requestHeaders`, `userAgent`, CAST(`additionalData` AS CHAR)) AS `everything` FROM `tblErrorLog` WHERE `errorTitle` LIKE ?", 's', ['it-179-mask%']);
itCheck('12c error log and visitor details (#179)', count($itMasked) === 1 && !str_contains((string) $itMasked[0]['everything'], '@it.cuercode.invalid') && str_contains((string) $itMasked[0]['everything'], '[address]'), 'an error-log row written with addresses in its title, detail, backtrace, page address, headers and extra data holds none of them, only "[address]": ' . substr((string) ($itMasked[0]['everything'] ?? ''), 0, 400));
// 🎫 Fourth-round review, Low 3: the reviewer's case - an address written out
//    as %40 (how a browser sends a form field in a page address) and a live
//    one-time link, in the page address and the Referer header.
$itLinkToken = bin2hex(random_bytes(32));
$_SERVER['REQUEST_URI']  = '/reset-password/?token=' . $itLinkToken . '&email=it-low3-url%40it.cuercode.invalid';
$_SERVER['HTTP_REFERER'] = 'https://webmail.example/?to=it-low3-referer%40it.cuercode.invalid&next=%2Fverify-email%2F%3Ftoken%3D' . $itLinkToken;
logError('warning', 'it-179-low3', 'a fault on the reset page');
$_SERVER = $itServerBefore;
$itLow3 = itSelect("SELECT CONCAT_WS('|', `requestURL`, `requestHeaders`) AS `everything` FROM `tblErrorLog` WHERE `errorTitle` = ?", 's', ['it-179-low3']);
$itLow3Text = (string) ($itLow3[0]['everything'] ?? '');
itCheck('12c error log and visitor details (#179)', count($itLow3) === 1 && !str_contains($itLow3Text, $itLinkToken) && !str_contains($itLow3Text, 'it-low3-') && str_contains($itLow3Text, '?token=[removed]') && str_contains($itLow3Text, '[address]'), 'fourth-round review Low 3: a page address and Referer with a %40 address and a one-time link are stored with neither (only "[removed]" and "[address]"): ' . substr($itLow3Text, 0, 400));
itExec('DELETE FROM `tblErrorLog` WHERE `errorTitle` LIKE ?', 's', ['it-179-%']);

// 📈 Fourth-round review, Low 4: entries whose visitor details were already
//    cleared are not read again on every run. 16,384 old entries with no
//    details, then one old entry with them and one recent one. After the
//    clean-up has worked through the table once, ONE more run must clear the
//    new old entry while reading far fewer rows than the table holds (the
//    database's own count of rows read, Handler_read_*).
$itHandlerReads = static function (): int {
    $itTotal  = 0;
    $itStatus = getDB()->query("SHOW SESSION STATUS LIKE 'Handler_read%'");
    while ($itStatusRow = $itStatus->fetch_row()) {
        $itTotal += (int) $itStatusRow[1];
    }
    $itStatus->free();
    return $itTotal;
};
itExec('DELETE FROM `tblActivityLog` WHERE `activityID` > ?', 'i', [0]);
itExec('DELETE FROM `tblSettings` WHERE `settingKey` = ?', 's', ['activity_visitor_details_cleared_to']);
itExec("INSERT INTO `tblActivityLog` (`activityType`, `activityDetail`, `createdAt`) VALUES (?, 'IT low 4', DATE_SUB(NOW(), INTERVAL 200 DAY))", 's', ['it_low4_cleared']);
for ($itDouble = 0; $itDouble < 14; $itDouble++) {
    itExec('INSERT INTO `tblActivityLog` (`activityType`, `activityDetail`, `createdAt`) SELECT `activityType`, `activityDetail`, `createdAt` FROM `tblActivityLog` WHERE `activityType` = ?', 's', ['it_low4_cleared']);
}
$itLow4Cleared = (int) itSelect('SELECT COUNT(*) AS `n` FROM `tblActivityLog` WHERE `activityType` = ?', 's', ['it_low4_cleared'])[0]['n'];
for ($itPass = 0; $itPass < 40; $itPass++) {
    clearOldActivityVisitorDetails(90, CUERCODE_EMAIL_QUEUE_TIDY_BATCH);
}
foreach (['it_low4_old' => 100, 'it_low4_new' => 10] as $itLow4Type => $itLow4Days) {
    itExec("INSERT INTO `tblActivityLog` (`activityType`, `activityDetail`, `requestIP`, `userAgent`, `createdAt`) VALUES (?, 'IT low 4', '203.0.113.4', 'IT browser', DATE_SUB(NOW(), INTERVAL ? DAY))", 'si', [$itLow4Type, $itLow4Days]);
}
$itReadsBefore = $itHandlerReads();
$itLow4Changed = clearOldActivityVisitorDetails(90, CUERCODE_EMAIL_QUEUE_TIDY_BATCH);
$itLow4Reads   = $itHandlerReads() - $itReadsBefore;
$itLow4Rows    = itSelect("SELECT `activityType`, `requestIP` FROM `tblActivityLog` WHERE `activityType` IN ('it_low4_old', 'it_low4_new') ORDER BY `activityType`");
itCheck('12d visitor-details clean-up cost (fourth-round review Low 4)', $itLow4Cleared === 16384, 'set-up: ' . $itLow4Cleared . ' old entries already without details');
itCheck('12d visitor-details clean-up cost (fourth-round review Low 4)', $itLow4Changed === 1 && count($itLow4Rows) === 2 && $itLow4Rows[1]['requestIP'] === null && $itLow4Rows[0]['requestIP'] === '203.0.113.4', 'one run clears the new old entry (and only it): the recent entry keeps its details (' . json_encode($itLow4Rows) . ')');
itCheck('12d visitor-details clean-up cost (fourth-round review Low 4)', $itLow4Reads < 2000, 'that run read ' . $itLow4Reads . ' rows, not the ' . $itLow4Cleared . ' already-cleared old entries again');
itExec('DELETE FROM `tblActivityLog` WHERE `activityType` LIKE ?', 's', ['it_low4_%']);

// ==============================================================================
// 📣 12e. Decision 0046: an abuse report dealt with more than 12 months ago
//    loses the reporter's email, the description and the address hash; the
//    bare record stays. (Upgrade 0007's table, applied in 12c.)
// ==============================================================================
echo PHP_EOL . 'Scenario 12e: abuse reports lose their personal details 12 months after they are dealt with (decision 0046)' . PHP_EOL;
$itHasAbuse = (int) itSelect("SELECT COUNT(*) AS `n` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ?", 's', ['tblAbuseReports'])[0]['n'] === 1;
itCheck('12e abuse reports kept 12 months (decision 0046)', $itHasAbuse, 'set-up: upgrade 0007\'s tblAbuseReports exists');
if ($itHasAbuse) {
    itExec('DELETE FROM `tblAbuseReports` WHERE `reportID` > ?', 'i', [0]);
    // [code, status, days since it was dealt with (null = not dealt with)]
    foreach ([['it-old-act', 'actioned', 400], ['it-old-dis', 'dismissed', 366], ['it-recent', 'actioned', 300], ['it-open', 'new', null], ['it-review', 'reviewing', null]] as $itAbuse) {
        itExec(
            "INSERT INTO `tblAbuseReports` (`shortCode`, `category`, `details`, `reporterEmailEncrypted`, `reporterAddressHash`, `status`, `createdAt`) VALUES (?, 'phishing', 'Call me on 07700 900123', 'IT-ENCRYPTED-EMAIL', REPEAT('a', 64), ?, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 500 DAY))",
            'ss',
            [$itAbuse[0], $itAbuse[1]]
        );
        if ($itAbuse[2] !== null) {
            itExec('UPDATE `tblAbuseReports` SET `handledAt` = DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY) WHERE `shortCode` = ?', 'is', [$itAbuse[2], $itAbuse[0]]);
        }
    }
    $itAbuseState = static function (): array {
        $itOut = [];
        foreach (itSelect('SELECT `shortCode`, `category`, `status`, `details`, `reporterEmailEncrypted`, `reporterAddressHash`, `personalDetailsRemovedAt` FROM `tblAbuseReports` ORDER BY `shortCode`') as $itRow) {
            $itKept = ($itRow['details'] !== null && $itRow['reporterEmailEncrypted'] !== null && $itRow['reporterAddressHash'] !== null && $itRow['personalDetailsRemovedAt'] === null);
            $itGone = ($itRow['details'] === null && $itRow['reporterEmailEncrypted'] === null && $itRow['reporterAddressHash'] === null && $itRow['personalDetailsRemovedAt'] !== null);
            $itOut[$itRow['shortCode']] = $itRow['status'] . '|' . $itRow['category'] . '|' . ($itKept ? 'kept' : ($itGone ? 'removed' : 'MIXED'));
        }
        return $itOut;
    };
    $itAbuseChanged = clearOldAbuseReportDetails(365, CUERCODE_EMAIL_QUEUE_TIDY_BATCH);
    itCheck('12e abuse reports kept 12 months (decision 0046)', $itAbuseChanged === 2 && $itAbuseState() === [
        'it-old-act' => 'actioned|phishing|removed',
        'it-old-dis' => 'dismissed|phishing|removed',
        'it-open'    => 'new|phishing|kept',
        'it-recent'  => 'actioned|phishing|kept',
        'it-review'  => 'reviewing|phishing|kept',
    ], 'reports dealt with 400 and 366 days ago lose the email, description and address hash (the bare record stays); one dealt with 300 days ago and two not yet dealt with keep them: ' . json_encode([$itAbuseChanged, $itAbuseState()]));
    itCheck('12e abuse reports kept 12 months (decision 0046)', clearOldAbuseReportDetails(365, CUERCODE_EMAIL_QUEUE_TIDY_BATCH) === 0, 'run again: nothing more to do');
    $itResult = runEmailQueueBatch($itGateway, $itOkSender, ['abuseReportRetentionDays' => 200]);
    itCheck('12e abuse reports kept 12 months (decision 0046)', ($itResult['abuseReportDetailsCleared'] ?? null) === 1 && $itResult['housekeepingFailures'] === 0 && $itAbuseState()['it-recent'] === 'actioned|phishing|removed', 'the cron clean-up uses the period an admin sets (200 days): the 300-day-old report is done too, and no step failed: ' . json_encode([$itResult['abuseReportDetailsCleared'] ?? null, $itResult['housekeepingFailures']]));
    itExec('DELETE FROM `tblAbuseReports` WHERE `reportID` > ?', 'i', [0]);
}

// ==============================================================================
// 1️⃣3️⃣ #122: an email whose details were cleared is never sent
// ==============================================================================
echo PHP_EOL . 'Scenario 13: cleared details are never sent (#122)' . PHP_EOL;
itClear();
$itIds = itQueue(1);
itExec('UPDATE `tblEmailQueue` SET `paramsJSON` = NULL WHERE `emailID` = ?', 'i', [$itIds[0]]);
$itSendCalls = 0;
$itResult    = runEmailQueueBatch($itGateway, $itOkSender, []);
$itRow       = itRow($itIds[0]);
itCheck('13 cleared details never sent', $itRow['status'] === 'skipped', 'the email is marked skipped');
itCheck('13 cleared details never sent', str_starts_with((string) $itRow['lastError'], 'Not sent: its stored details'), 'its lastError explains why');
itCheck('13 cleared details never sent', $itSendCalls === 0 && $itResult['unsendable'] === 1, 'nothing was sent, unsendable 1');

// ==============================================================================
// 1️⃣4️⃣ #122: the runner's main with email switched off still tidies up
// ==============================================================================
echo PHP_EOL . 'Scenario 14: email switched off - the clean-up still runs, nothing is sent (#122)' . PHP_EOL;
itClear();
$itIds = itQueue(2);
itExec("UPDATE `tblEmailQueue` SET `status` = 'sent', `createdAt` = DATE_SUB(NOW(), INTERVAL 31 DAY) WHERE `emailID` = ?", 'i', [$itIds[0]]);
$itOutLines  = [];
$itErrLines  = [];
$itSendCalls = 0;

// 🧰 The REAL production environment (real database check, real getConfig,
//    real gateway), with ONLY the sender and the output replaced: a counting
//    stand-in instead of sendEmailNow(), and collectors instead of the terminal.
$itEnvironment           = emailQueueProductionEnvironment();
$itEnvironment['sender'] = $itOkSender;
$itEnvironment['out']    = static function (string $itLine) use (&$itOutLines): void {
    $itOutLines[] = $itLine;
};
$itEnvironment['err']    = static function (string $itLine) use (&$itErrLines): void {
    $itErrLines[] = $itLine;
};
$itExit    = emailQueueRunnerMain(parseEmailQueueRunnerArgs(['process_email_queue.php']), $itEnvironment);
$itOutText = implode(' ', $itOutLines);
itCheck('14 switched off still tidies', (int) getConfig('email_enabled', 0) === 0, 'email_enabled is 0 (as seeded)');
itCheck('14 switched off still tidies', $itExit === 0, 'exit code 0 (' . $itExit . ')');
itCheck('14 switched off still tidies', itRow($itIds[0]) === [], 'the 31-day-old sent record is gone');
itCheck('14 switched off still tidies', itStatus($itIds[1]) === 'queued', 'the fresh email is still queued');
itCheck('14 switched off still tidies', $itSendCalls === 0, 'nothing was sent');
itCheck('14 switched off still tidies', str_contains($itOutText, 'switched off') && str_contains($itOutText, 'Tidied up: deleted 1'), 'the output says it is switched off and what was tidied');

// ==============================================================================
// 🧹 Clean up and report
// ==============================================================================
itClear();

echo PHP_EOL . 'Results:' . PHP_EOL;
$itAllPassed = true;

foreach ($itChecks as $itScenario => $itPassed) {
    if ($itPassed) {
        echo '  PASS  ' . $itScenario . PHP_EOL;
    } else {
        echo '  FAIL  ' . $itScenario . PHP_EOL;
        $itAllPassed = false;
    }
}

if ($itAllPassed) {
    exit(0);
}

exit(1);
