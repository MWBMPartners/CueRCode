<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - OPTIONAL real-database check for the email queue runner (#112)
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
 * @version     1.0.0
 * @since       2026-09-25 (#112)
 * @see         tests/README.md - "Optional real-database check (email queue)"
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
