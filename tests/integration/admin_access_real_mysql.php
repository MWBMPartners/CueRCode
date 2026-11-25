<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - OPTIONAL real-database check for Global Admin access (7n Phase 1B)
 * ==============================================================================
 *
 * Path: tests/integration/admin_access_real_mysql.php
 *
 * tests/test_admin_access.php proves the DECISIONS without a database. This
 * script proves the parts only a real MySQL/MariaDB server can, against one
 * throwaway database built from install.sql + seed_data.sql:
 *
 *   A. Before the upgrades: the command-line tool refuses to change a role
 *      while tblAdminAuditLog does not exist yet (exit 2, nothing changed).
 *   B. /admin/upgrades/ engine before the audit table exists: an audit entry
 *      falls back to the activity log; adminRunUpgrades() applies every
 *      waiting upgrade for a Global Admin, records "admin:<userID>" in
 *      tblSchemaMigrations, then writes its schema.migrate audit row into
 *      the table the upgrade has just created.
 *   C. CLI grant, with decision 0010 (a pending account gets a NEW password):
 *      C1 from a pipe (not a terminal) it is refused before anything is
 *         asked, even with a password piped in, and nothing changes;
 *      C2 one transaction: a database trigger forces a failure at the audit
 *         row (last step) and at removing linked sign-ins (middle step) -
 *         password, role, status, sessions, keys, links and audit are all
 *         exactly as before; no hash / a different account number refused;
 *      C3 the REAL command in a pretend terminal (the `script` tool): the
 *         account details and "stop" warning come first, the typed
 *         confirmation, then a weak password, a mismatch and a good one; the
 *         typing never appears; the old password stops working, the new one
 *         works, every session / API key / reset link / linked sign-in /
 *         passkey of that account is removed (another account's are not),
 *         and the audit row says password_reset: true with the counts and no
 *         password or hash. (Without a usable `script` tool C3 is reported
 *         as NOT RUN and the grant is made in-process instead.)
 *      Also: an ACTIVE account is granted through a pipe with NO reset; the
 *      typed-confirmation path works and a wrong confirmation is refused.
 *   D. CLI refusals (exit 2, nothing changed): suspended, deleted, password-
 *      less (SSO-only) and unknown accounts.
 *   E. CLI list shows the admins with their email addresses partly hidden.
 *   F. CLI revoke: removes access with an admin.revoke row; refuses the LAST
 *      working Global Admin; "not an admin" changes nothing.
 *   G. The gate's database read: adminLoadAccessRow() + adminAccessDecision()
 *      for an admin, role 2, a suspended admin and a pending admin;
 *      adminCountGlobalAdmins().
 *   H. admin.first_seen: a Global Admin made by hand (as in phpMyAdmin) gets
 *      exactly ONE row however often the check runs; an admin with an
 *      admin.grant row gets none.
 *   I. The erasure guard: eraseUserAccount() refuses a Global Admin with 409
 *      GLOBAL_ADMIN_CANNOT_ERASE (right or wrong password) and changes
 *      nothing; an ordinary account still erases normally.
 *   J. Password re-confirmation: a wrong password counts toward the normal
 *      account lock-out, the right one resets it, and a locked account is
 *      refused even with the right password.
 *   K. No password typed or piped during the run (including the owner's
 *      old one and the refused ones) and no new password HASH appears
 *      anywhere in the audit log, the activity log or the error log tables.
 *
 * Not covered (needs a browser and a web server): the pages' redirects, the
 * 404, the headers and the forms - see the independent review notes.
 *
 * It is NOT run by tests/run.php (which only picks up tests/test_*.php), as
 * it needs a database. How to run it (Docker example):
 *
 *     docker run -d --rm --name cuercode-it -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
 *       -p 127.0.0.1:33114:3306 mysql:8.0.36
 *     CUERCODE_IT_DB_HOST=127.0.0.1 CUERCODE_IT_DB_PORT=33114 \
 *     CUERCODE_IT_DB_NAME=cuercode_it_p1b CUERCODE_IT_DB_USER=root CUERCODE_IT_DB_PASS= \
 *       php tests/integration/admin_access_real_mysql.php
 *     docker stop cuercode-it
 *
 * ⚠️ SAFETY - read before changing anything here:
 *   - It creates and DROPS a whole database. It refuses to run (exit 2)
 *     unless the database name starts with "cuercode_it_".
 *   - It never reads _auth_keys/auth_creds.php: DB_HOST/DB_PORT/DB_USER/
 *     DB_PASS/DB_NAME are defined from the CUERCODE_IT_DB_* environment
 *     variables before the site start-up code is loaded. The encryption key
 *     is random for each run.
 *   - It runs the REAL _includes/cli/admin_access.php as a genuine child
 *     process (this same file re-invoked with --run-admin-cli), so the real
 *     exit codes and output are checked.
 *
 * Exit codes: 0 every scenario passed | 1 a scenario failed or crashed |
 * 2 refused (unsafe database name, missing settings, server unreachable).
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-24 (task 7n Phase 1B, #31)
 * @see         tests/test_admin_access.php - the database-free unit tests
 * @see         tests/integration/migrations_real_mysql.php - the pattern copied here
 * @see         web/CueRCode.net/_functions/fn_admin.php
 * @see         web/CueRCode.net/_includes/cli/admin_access.php
 * @see         .claude/plans/7n-admin-commerce-plan.md §6, §9 Phase 1B
 */

// ==============================================================================
// 🚫 Command line only - the first statement
// ==============================================================================
if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
    }
    exit('Access denied.');
}

$aiSiteRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'CueRCode.net' . DIRECTORY_SEPARATOR;

/**
 * 🔧 One CUERCODE_IT_DB_* value ('' when unset).
 */
function aiEnv(string $aiKey): string
{
    $aiValue = getenv('CUERCODE_IT_DB_' . $aiKey);
    if ($aiValue === false) {
        return '';
    }
    return $aiValue;
}

/**
 * 🗄️ Define the site's database constants for the test server (before any
 *    site code loads, so db_connect.php never looks for auth_creds.php).
 */
function aiDefineDatabase(string $aiDbName): void
{
    $aiPort = aiEnv('PORT');
    if ($aiPort === '') {
        $aiPort = '3306';
    }
    define('DB_HOST', aiEnv('HOST'));
    define('DB_PORT', (int) $aiPort);
    define('DB_USER', aiEnv('USER'));
    define('DB_PASS', aiEnv('PASS'));
    define('DB_NAME', $aiDbName);
    define('ENCRYPTION_KEY', bin2hex(random_bytes(16)));
}

// ==============================================================================
// 👶 Self-invoked CHILD mode: the REAL _includes/cli/admin_access.php
// ==============================================================================
// The parent runs: php <this file> --run-admin-cli --db=<name> <admin_access args...>
// ==============================================================================
$aiArguments = array_slice($argv, 1);

if (isset($aiArguments[0]) && $aiArguments[0] === '--run-admin-cli') {
    $aiChildDb = '';
    if (isset($aiArguments[1]) && str_starts_with($aiArguments[1], '--db=')) {
        $aiChildDb = substr($aiArguments[1], strlen('--db='));
    }

    if (preg_match('/^cuercode_it_[a-z0-9_]+$/', $aiChildDb) !== 1) {
        fwrite(STDERR, 'Refusing: --db must be a cuercode_it_ test database.' . PHP_EOL);
        exit(9);
    }

    aiDefineDatabase($aiChildDb);

    // 📣 admin_access.php reads $_SERVER['argv'] itself.
    $_SERVER['argv'] = array_merge(['admin_access.php'], array_slice($aiArguments, 2));

    require $aiSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'admin_access.php';

    // admin_access.php always exits itself.
    exit(99);
}

// ==============================================================================
// 👶 Self-invoked CHILD mode 2 (review fix M2): call adminRecordFirstSeen() at a
//    shared start time, so several processes really do race for the same admin.
// php <this file> --run-first-seen --db=<name> --user=<userID> --start=<unix time with decimals>
// ==============================================================================
if (isset($aiArguments[0]) && $aiArguments[0] === '--run-first-seen') {
    $aiRaceDb = '';
    $aiRaceUser = 0;
    $aiRaceAt = 0.0;
    foreach ($aiArguments as $aiRaceArgument) {
        if (str_starts_with($aiRaceArgument, '--db=')) {
            $aiRaceDb = substr($aiRaceArgument, 5);
        } elseif (str_starts_with($aiRaceArgument, '--user=')) {
            $aiRaceUser = (int) substr($aiRaceArgument, 7);
        } elseif (str_starts_with($aiRaceArgument, '--start=')) {
            $aiRaceAt = (float) substr($aiRaceArgument, 8);
        }
    }

    if (preg_match('/^cuercode_it_[a-z0-9_]+$/', $aiRaceDb) !== 1 || $aiRaceUser < 1) {
        fwrite(STDERR, 'Refusing: bad arguments.' . PHP_EOL);
        exit(9);
    }

    aiDefineDatabase($aiRaceDb);
    require_once $aiSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
    require_once $aiSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_admin.php';
    restore_error_handler();
    restore_exception_handler();

    // ⏱️ Everything is loaded; wait for the shared start time, then go.
    while (microtime(true) < $aiRaceAt) {
        usleep(1000);
    }
    adminRecordFirstSeen(['userID' => $aiRaceUser]);
    exit(0);
}

// ==============================================================================
// 👶 Self-invoked CHILD mode 3 (independent review, Codex C2 finding 1): record
//    a password re-confirmation in a process whose PHP session started before
//    anything was printed, and say whether editing was unlocked.
// php <this file> --run-reauth-success --db=<name> --user=<userID>
// Prints one line: REAUTH-RESULT:{"recorded":bool,"unlocked":bool,"session":bool}
// ==============================================================================
if (isset($aiArguments[0]) && $aiArguments[0] === '--run-reauth-success') {
    $aiReDb = '';
    $aiReUser = 0;
    foreach ($aiArguments as $aiReArgument) {
        if (str_starts_with($aiReArgument, '--db=')) {
            $aiReDb = substr($aiReArgument, 5);
        } elseif (str_starts_with($aiReArgument, '--user=')) {
            $aiReUser = (int) substr($aiReArgument, 7);
        }
    }

    if (preg_match('/^cuercode_it_[a-z0-9_]+$/', $aiReDb) !== 1 || $aiReUser < 1) {
        fwrite(STDERR, 'Refusing: bad arguments.' . PHP_EOL);
        exit(9);
    }

    // 🎫 A real PHP session, started before anything is printed (the site's
    //    start-up does not open one on the command line). No cookie headers.
    ini_set('session.use_cookies', '0');
    ini_set('session.cache_limiter', '');
    session_start();

    aiDefineDatabase($aiReDb);
    require_once $aiSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
    require_once $aiSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_admin.php';
    restore_error_handler();
    restore_exception_handler();

    $aiReAdmin = ['userID' => $aiReUser, 'email' => 'it@admin.cuercode.invalid', 'displayName' => 'IT', 'sessionHash' => hash('sha256', 'it-session'), 'hasPassword' => true];
    adminReauthClear();
    $aiReRecorded = adminReauthRecordSuccess($aiReAdmin);
    echo 'REAUTH-RESULT:' . json_encode([
        'recorded' => $aiReRecorded,
        'unlocked' => adminReauthStoredState() !== null,
        'session'  => session_status() === PHP_SESSION_ACTIVE,
    ]) . PHP_EOL;
    exit(0);
}

// ==============================================================================
// 🛡️ Step 1: safety - only ever a throwaway database named cuercode_it_*
// ==============================================================================
$aiBaseName = aiEnv('NAME');

if (preg_match('/^cuercode_it_[a-z0-9_]+$/', $aiBaseName) !== 1) {
    fwrite(STDERR, 'Refusing to run: CUERCODE_IT_DB_NAME must start with "cuercode_it_" (this script creates and drops a whole database).' . PHP_EOL);
    exit(2);
}

if (aiEnv('HOST') === '' || aiEnv('USER') === '') {
    fwrite(STDERR, 'Refusing to run: set CUERCODE_IT_DB_HOST and CUERCODE_IT_DB_USER (and CUERCODE_IT_DB_PORT / CUERCODE_IT_DB_PASS if needed).' . PHP_EOL);
    exit(2);
}

$aiDbName = $aiBaseName . '_admin';
if (preg_match('/^cuercode_it_[a-z0-9_]+$/', $aiDbName) !== 1 || strlen($aiDbName) > 64) {
    fwrite(STDERR, 'Refusing: the derived database name is not safe.' . PHP_EOL);
    exit(2);
}

$aiPort = aiEnv('PORT');
if ($aiPort === '') {
    $aiPort = '3306';
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ==============================================================================
// ⏳ Step 2: wait for the server (up to 120 seconds)
// ==============================================================================
$aiServer   = null;
$aiDeadline = microtime(true) + 120;
$aiLastError = '';

while ($aiServer === null && microtime(true) < $aiDeadline) {
    try {
        $aiServer = new \mysqli(aiEnv('HOST'), aiEnv('USER'), aiEnv('PASS'), '', (int) $aiPort);
    } catch (\Throwable $aiError) {
        $aiLastError = $aiError->getMessage();
        usleep(500000);
    }
}

if ($aiServer === null) {
    fwrite(STDERR, 'Refusing: the test database server could not be reached (' . $aiLastError . ').' . PHP_EOL);
    exit(2);
}

echo 'Server: ' . $aiServer->server_info . PHP_EOL;

// ==============================================================================
// 🧱 Step 3: a fresh database with install.sql + seed_data.sql ONLY
// ==============================================================================
$aiServer->query('DROP DATABASE IF EXISTS `' . $aiDbName . '`');
$aiServer->query('CREATE DATABASE `' . $aiDbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$aiServer->select_db($aiDbName);
$aiServer->query("SET time_zone = '+00:00'");

foreach (['install.sql', 'seed_data.sql'] as $aiSqlFile) {
    $aiSql = (string) file_get_contents($aiSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . $aiSqlFile);
    $aiServer->multi_query($aiSql);
    do {
        $aiStored = $aiServer->store_result();
        if ($aiStored instanceof \mysqli_result) {
            $aiStored->free();
        }
    } while ($aiServer->more_results() && $aiServer->next_result());
    echo 'Loaded ' . $aiSqlFile . '.' . PHP_EOL;
}

// 👥 Test accounts. Passwords are random per run and never printed.
$aiPasswords = [
    'owner'  => 'Owner-' . bin2hex(random_bytes(8)),
    'second' => 'Second-' . bin2hex(random_bytes(8)),
    'hand'   => 'Hand-' . bin2hex(random_bytes(8)),
];
$aiHash = static function (string $aiPlain): string {
    if (defined('PASSWORD_ARGON2ID')) {
        return password_hash($aiPlain, PASSWORD_ARGON2ID);
    }
    return password_hash($aiPlain, PASSWORD_DEFAULT);
};

$aiAccounts = [
    // key => [email, status, verified, password key or null, roleID]
    'owner'     => ['owner@it.cuercode.invalid', 'pending', false, 'owner', null],
    'second'    => ['second@it.cuercode.invalid', 'active', true, 'second', null],
    'suspended' => ['suspended@it.cuercode.invalid', 'suspended', true, 'second', null],
    'deleted'   => ['deleted@it.cuercode.invalid', 'deleted', true, 'second', null],
    'ssoonly'   => ['ssoonly@it.cuercode.invalid', 'active', true, null, null],
    'hand'      => ['hand@it.cuercode.invalid', 'active', true, 'hand', 1],
    'role2'     => ['role2@it.cuercode.invalid', 'active', true, 'second', 2],
    'plain'     => ['plain@it.cuercode.invalid', 'active', true, 'second', null],
];
$aiUserIDs = [];

foreach ($aiAccounts as $aiKey => $aiAccount) {
    $aiPasswordHash = null;
    if ($aiAccount[3] !== null) {
        $aiPasswordHash = $aiHash($aiPasswords[$aiAccount[3]]);
    }
    $aiVerifiedAt = null;
    if ($aiAccount[2]) {
        $aiVerifiedAt = '2026-11-01 09:00:00';
    }
    $aiInsert = $aiServer->prepare('INSERT INTO `tblUsers` (`email`, `emailVerifiedAt`, `passwordHash`, `displayName`, `status`, `roleID`) VALUES (?, ?, ?, ?, ?, ?)');
    $aiDisplay = 'IT ' . $aiKey;
    $aiRole = $aiAccount[4];
    $aiInsert->bind_param('sssssi', $aiAccount[0], $aiVerifiedAt, $aiPasswordHash, $aiDisplay, $aiAccount[1], $aiRole);
    $aiInsert->execute();
    $aiUserIDs[$aiKey] = (int) $aiServer->insert_id;
    $aiInsert->close();
}

// 🚪 Decision 0010: "earlier ways in" on the PENDING owner account, as if a
//    stranger had registered it first. (A pending account cannot sign in, so
//    these cannot normally exist - the grant must remove them anyway.) Plus a
//    few already-dead ones that must not be counted, and live ones on the
//    ACTIVE "second" account that a grant must NOT touch.
/**
 * 🧱 Insert test rows with one prepared statement per row.
 */
function aiInsertRows(\mysqli $aiDb, string $aiSql, string $aiTypes, array $aiRows): void
{
    foreach ($aiRows as $aiRowValues) {
        $aiStatement = $aiDb->prepare($aiSql);
        $aiStatement->bind_param($aiTypes, ...$aiRowValues);
        $aiStatement->execute();
        $aiStatement->close();
    }
}
$aiOwnerID  = $aiUserIDs['owner'];
$aiSecondID = $aiUserIDs['second'];
aiInsertRows($aiServer, 'INSERT INTO `tblUserSessions` (`sessionID`, `userID`, `expiresAt`, `revokedAt`) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY), ?)', 'sis', [
    [bin2hex(random_bytes(32)), $aiOwnerID, null],
    [bin2hex(random_bytes(32)), $aiOwnerID, null],
    [bin2hex(random_bytes(32)), $aiOwnerID, '2026-11-01 09:00:00'],
    [bin2hex(random_bytes(32)), $aiSecondID, null],
]);
aiInsertRows($aiServer, 'INSERT INTO `tblAPIKeys` (`apiKeyHash`, `apiKeyPrefix`, `userID`, `isActive`) VALUES (?, ?, ?, ?)', 'ssii', [
    [hash('sha256', random_bytes(16)), 'cuercode_it1', $aiOwnerID, 1],
    [hash('sha256', random_bytes(16)), 'cuercode_it2', $aiOwnerID, 0],
    [hash('sha256', random_bytes(16)), 'cuercode_it3', $aiSecondID, 1],
]);
aiInsertRows($aiServer, 'INSERT INTO `tblPasswordResets` (`userID`, `tokenHash`, `expiresAt`, `usedAt`) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), ?)', 'iss', [
    [$aiOwnerID, hash('sha256', random_bytes(16)), null],
    [$aiOwnerID, hash('sha256', random_bytes(16)), '2026-11-01 09:00:00'],
]);
aiInsertRows($aiServer, 'INSERT INTO `tblUserOAuth` (`userID`, `provider`, `providerSubjectID`) VALUES (?, ?, ?)', 'iss', [
    [$aiOwnerID, 'google', 'stranger-google-subject-' . bin2hex(random_bytes(4))],
]);
aiInsertRows($aiServer, 'INSERT INTO `tblWebAuthnCredentials` (`userID`, `credentialID`, `publicKey`) VALUES (?, ?, ?)', 'iss', [
    [$aiOwnerID, 'stranger-passkey-' . bin2hex(random_bytes(4)), 'not-a-real-key'],
]);

// ==============================================================================
// 🧰 Helpers
// ==============================================================================
$aiChecks = [];

/**
 * 📝 Record one check and print it.
 */
function aiCheck(string $aiScenario, bool $aiPassed, string $aiWhat): void
{
    if (!isset($GLOBALS['aiChecks'][$aiScenario])) {
        $GLOBALS['aiChecks'][$aiScenario] = true;
    }
    if (!isset($GLOBALS['aiCheckCount'])) {
        $GLOBALS['aiCheckCount'] = 0;
    }
    $GLOBALS['aiCheckCount']++;
    if (!$aiPassed) {
        $GLOBALS['aiChecks'][$aiScenario] = false;
        echo '    FAIL ';
    } else {
        echo '    ok   ';
    }
    echo $aiWhat . PHP_EOL;
}

/**
 * 💻 Run the REAL admin_access.php as a child process. $aiStdin is typed in.
 *
 * @return array{exit: int, out: string, err: string}
 */
function aiCli(string $aiDb, array $aiCliArguments, string $aiStdin = ''): array
{
    $aiCommand = array_merge([PHP_BINARY, __FILE__, '--run-admin-cli', '--db=' . $aiDb], $aiCliArguments);
    $aiProcess = proc_open($aiCommand, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $aiPipes);

    if (!is_resource($aiProcess)) {
        return ['exit' => -1, 'out' => '', 'err' => 'proc_open failed'];
    }

    fwrite($aiPipes[0], $aiStdin);
    fclose($aiPipes[0]);
    $aiOut = (string) stream_get_contents($aiPipes[1]);
    fclose($aiPipes[1]);
    $aiErr = (string) stream_get_contents($aiPipes[2]);
    fclose($aiPipes[2]);

    return ['exit' => proc_close($aiProcess), 'out' => $aiOut, 'err' => $aiErr];
}

/**
 * 🔎 One row from the test database (raw connection, prepared).
 */
function aiRow(\mysqli $aiDb, string $aiSql, string $aiTypes = '', array $aiParams = []): ?array
{
    $aiStatement = $aiDb->prepare($aiSql);
    if ($aiTypes !== '') {
        $aiStatement->bind_param($aiTypes, ...$aiParams);
    }
    $aiStatement->execute();
    $aiResult = $aiStatement->get_result();
    $aiFetched = $aiResult->fetch_assoc();
    $aiStatement->close();
    return $aiFetched;
}

/**
 * 🔢 A COUNT(*) from the test database.
 */
function aiCount(\mysqli $aiDb, string $aiSql, string $aiTypes = '', array $aiParams = []): int
{
    $aiFetched = aiRow($aiDb, $aiSql, $aiTypes, $aiParams);
    if ($aiFetched === null) {
        return -1;
    }
    return (int) array_values($aiFetched)[0];
}

/**
 * 🖥️ Run the REAL admin_access.php inside a PRETEND TERMINAL (a "pseudo-
 *    terminal", made by the standard `script` tool), answering its prompts
 *    one at a time, the way a person at the keyboard would. Each step waits
 *    for its prompt to appear AFTER the previous answer, then types.
 *
 * Returns null when this machine has no `script` tool this knows how to use
 * (macOS/BSD and Linux are supported).
 *
 * @param array<int, array{0: string, 1: string}> $aiSteps [prompt to wait for, line to type]
 * @return array{exit: int, out: string, stepsDone: int}|null
 */
function aiCliTty(string $aiDb, array $aiCliArguments, array $aiSteps): ?array
{
    $aiScriptTool = '';
    foreach (['/usr/bin/script', '/bin/script'] as $aiCandidate) {
        if (is_executable($aiCandidate)) {
            $aiScriptTool = $aiCandidate;
            break;
        }
    }
    if ($aiScriptTool === '') {
        return null;
    }

    $aiInner = array_merge([PHP_BINARY, __FILE__, '--run-admin-cli', '--db=' . $aiDb], $aiCliArguments);

    if (PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'BSD') {
        // BSD/macOS: script -q <transcript file> <command...>
        $aiCommand = array_merge([$aiScriptTool, '-q', '/dev/null'], $aiInner);
    } elseif (PHP_OS_FAMILY === 'Linux') {
        // util-linux: script -q -e (pass the exit code on) -c "<command>" <transcript file>
        $aiQuoted = [];
        foreach ($aiInner as $aiPart) {
            $aiQuoted[] = escapeshellarg($aiPart);
        }
        $aiCommand = [$aiScriptTool, '-q', '-e', '-c', implode(' ', $aiQuoted), '/dev/null'];
    } else {
        return null;
    }

    $aiProcess = proc_open($aiCommand, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $aiPipes);
    if (!is_resource($aiProcess)) {
        return null;
    }

    stream_set_blocking($aiPipes[1], false);
    stream_set_blocking($aiPipes[2], false);

    $aiOut        = '';
    $aiStep       = 0;
    $aiSearchFrom = 0;
    $aiDeadline   = microtime(true) + 90;

    while (microtime(true) < $aiDeadline) {
        $aiRead   = [$aiPipes[1], $aiPipes[2]];
        $aiWrite  = null;
        $aiExcept = null;
        if (stream_select($aiRead, $aiWrite, $aiExcept, 0, 100000) > 0) {
            foreach ($aiRead as $aiReadable) {
                $aiChunk = fread($aiReadable, 8192);
                if (is_string($aiChunk)) {
                    $aiOut .= $aiChunk;
                }
            }
        }

        if ($aiStep < count($aiSteps)) {
            $aiPromptAt = strpos($aiOut, $aiSteps[$aiStep][0], $aiSearchFrom);
            if ($aiPromptAt !== false) {
                fwrite($aiPipes[0], $aiSteps[$aiStep][1] . "\n");
                $aiSearchFrom = $aiPromptAt + strlen($aiSteps[$aiStep][0]);
                $aiStep++;
            }
        }

        $aiStatus = proc_get_status($aiProcess);
        if (!$aiStatus['running']) {
            $aiOut .= (string) stream_get_contents($aiPipes[1]);
            $aiOut .= (string) stream_get_contents($aiPipes[2]);
            break;
        }
    }

    fclose($aiPipes[0]);
    fclose($aiPipes[1]);
    fclose($aiPipes[2]);

    // proc_get_status() reports the exit code once; proc_close() after that gives -1.
    $aiExit = -1;
    if (isset($aiStatus) && !$aiStatus['running']) {
        $aiExit = (int) $aiStatus['exitcode'];
        proc_close($aiProcess);
    } else {
        proc_terminate($aiProcess);
        proc_close($aiProcess);
    }

    return ['exit' => $aiExit, 'out' => $aiOut, 'stepsDone' => $aiStep];
}

/**
 * 📸 Everything a grant could change about the owner account, so "nothing
 *    changed" can be checked exactly.
 */
function aiOwnerSnapshot(\mysqli $aiDb, int $aiUserID): array
{
    $aiSnapshot = aiRow($aiDb, 'SELECT `roleID`, `status`, `emailVerifiedAt`, `passwordHash`, `failedLoginCount`, `lockedUntil` FROM `tblUsers` WHERE `userID` = ?', 'i', [$aiUserID]);
    $aiSnapshot['liveSessions'] = aiCount($aiDb, 'SELECT COUNT(*) FROM `tblUserSessions` WHERE `userID` = ? AND `revokedAt` IS NULL', 'i', [$aiUserID]);
    $aiSnapshot['activeKeys']   = aiCount($aiDb, 'SELECT COUNT(*) FROM `tblAPIKeys` WHERE `userID` = ? AND `isActive` = 1', 'i', [$aiUserID]);
    $aiSnapshot['unusedResets'] = aiCount($aiDb, 'SELECT COUNT(*) FROM `tblPasswordResets` WHERE `userID` = ? AND `usedAt` IS NULL', 'i', [$aiUserID]);
    $aiSnapshot['oauthLinks']   = aiCount($aiDb, 'SELECT COUNT(*) FROM `tblUserOAuth` WHERE `userID` = ?', 'i', [$aiUserID]);
    $aiSnapshot['passkeys']     = aiCount($aiDb, 'SELECT COUNT(*) FROM `tblWebAuthnCredentials` WHERE `userID` = ?', 'i', [$aiUserID]);
    $aiSnapshot['grantRows']    = aiCount($aiDb, "SELECT COUNT(*) FROM `tblAdminAuditLog` WHERE `action` = 'admin.grant' AND `entityKey` = ?", 's', [(string) $aiUserID]);
    return $aiSnapshot;
}

$aiOwnerEmail = $aiAccounts['owner'][0];
$aiSkipped    = [];

// ==============================================================================
// A. Before the upgrades, the CLI refuses
// ==============================================================================
echo PHP_EOL . 'A. Command-line tool before the upgrades' . PHP_EOL;
$aiRun = aiCli($aiDbName, ['grant', $aiOwnerEmail, '--activate', '--yes']);
aiCheck('A', $aiRun['exit'] === 2, 'grant before tblAdminAuditLog exists: exit 2 (got ' . $aiRun['exit'] . ')');
aiCheck('A', str_contains($aiRun['err'], 'migrate.php --apply'), 'the refusal says to run the upgrades first');
aiCheck('A', aiRow($aiServer, 'SELECT `roleID` FROM `tblUsers` WHERE `userID` = ?', 'i', [$aiUserIDs['owner']])['roleID'] === null, 'nothing was changed (owner still has no role)');

// ==============================================================================
// 🚀 The real site code, in this process, against the test database
// ==============================================================================
aiDefineDatabase($aiDbName);
require_once $aiSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once $aiSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_admin.php';
require_once $aiSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_api_account.php';

restore_error_handler();
restore_exception_handler();
ini_set('display_errors', 'stderr');

$aiHandAdmin = [
    'userID'      => $aiUserIDs['hand'],
    'email'       => $aiAccounts['hand'][0],
    'displayName' => 'IT hand',
    'sessionHash' => hash('sha256', 'it-session'),
    'hasPassword' => true,
];

// ==============================================================================
// B. Upgrades from the admin area before the audit table exists
// ==============================================================================
echo PHP_EOL . 'B. Admin upgrades before tblAdminAuditLog exists' . PHP_EOL;
aiCheck('B', adminTableExists('tblAdminAuditLog') === false, 'tblAdminAuditLog does not exist yet');
aiCheck('B', adminDatabaseReadyForChanges() === false, 'adminDatabaseReadyForChanges() is false (other admin pages must refuse to save)');
$aiOverview = adminUpgradeOverview();
aiCheck('B', $aiOverview['ok'] && count($aiOverview['pending']) >= 2, 'the overview lists the waiting upgrades (' . count($aiOverview['pending']) . ')');
aiCheck('B', isset($aiOverview['pending'][0]['description']) && $aiOverview['pending'][0]['description'] !== '', 'each waiting upgrade has its plain-English description');

// 🧾 Codex round 2 finding R2-1 (2026-11-25): before the audit table exists,
//    the stand-in activity-log row must really be written. A trigger makes the
//    activity log refuse every row; then nothing may be reported as recorded,
//    editing must not unlock, and no upgrade may run.
$aiServer->query('DROP TRIGGER IF EXISTS `aiForcedFailure`');
$aiServer->query('CREATE TRIGGER `aiForcedFailure` BEFORE INSERT ON `tblActivityLog` FOR EACH ROW SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'forced failure for the integration test\'');
$aiFallbackRefused = adminAuditOrFallback(adminAuditEntryForAdmin($aiHandAdmin, 'reauth.success', 'user', (string) $aiUserIDs['hand'], 'success'));
aiCheck('B', $aiFallbackRefused === false, 'R2-1: with the activity log refusing rows, the stand-in entry is reported as NOT written');
$aiReauthChildB = aiReauthInChild($aiDbName, (int) $aiUserIDs['hand']);
aiCheck('B', isset($aiReauthChildB['session'], $aiReauthChildB['recorded'], $aiReauthChildB['unlocked']) && $aiReauthChildB['session'] === true && $aiReauthChildB['recorded'] === false && $aiReauthChildB['unlocked'] === false, 'R2-1: ...the password confirmation (a real PHP session, in a child process) does NOT unlock editing ' . json_encode($aiReauthChildB));
$aiUpgradeRefusedB = adminRunUpgrades($aiHandAdmin);
aiCheck('B', $aiUpgradeRefusedB['ok'] === false && $aiUpgradeRefusedB['status'] === 'audit_failed' && $aiUpgradeRefusedB['applied'] === [], 'R2-1: ...and the admin upgrade refuses to start (status ' . $aiUpgradeRefusedB['status'] . ')');
aiCheck('B', aiCount($aiServer, "SELECT COUNT(*) FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` IN ('tblAdminAuditLog', 'tblSchemaMigrations')") === 0, 'R2-1: ...so the database structure was NOT changed (no audit table, no upgrade ledger)');
$aiServer->query('DROP TRIGGER IF EXISTS `aiForcedFailure`');

$aiFallback = adminAuditOrFallback(adminAuditEntryForAdmin($aiHandAdmin, 'reauth.success', 'user', (string) $aiUserIDs['hand'], 'success'));
aiCheck('B', $aiFallback === true, 'an audit entry falls back to the activity log while the audit table is missing');
aiCheck('B', aiCount($aiServer, "SELECT COUNT(*) FROM `tblActivityLog` WHERE `activityType` = 'reauth.success' AND `entityType` = 'user' AND `entityID` = ?", 'i', [$aiUserIDs['hand']]) === 1, 'the fallback row is in tblActivityLog, naming the admin');

$aiUpgrade = adminRunUpgrades($aiHandAdmin);
aiCheck('B', $aiUpgrade['ok'] === true, 'adminRunUpgrades() applied every waiting upgrade (status ' . $aiUpgrade['status'] . ')');
aiCheck('B', in_array('0002_admin_audit_log_and_settings', $aiUpgrade['applied'], true), 'migration 0002 (the audit table) was applied');
aiCheck('B', aiCount($aiServer, 'SELECT COUNT(*) FROM `tblSchemaMigrations` WHERE `appliedBy` = ?', 's', ['admin:' . $aiUserIDs['hand']]) === count($aiUpgrade['applied']), 'tblSchemaMigrations records "admin:<userID>" for each upgrade');
$aiMigrateRow = aiRow($aiServer, "SELECT `actorUserID`, `actorType`, `outcome`, `changesJSON` FROM `tblAdminAuditLog` WHERE `action` = 'schema.migrate'");
aiCheck('B', $aiMigrateRow !== null && (int) $aiMigrateRow['actorUserID'] === $aiUserIDs['hand'] && $aiMigrateRow['actorType'] === 'admin' && $aiMigrateRow['outcome'] === 'success', 'schema.migrate audit row written into the table the upgrade just created');
aiCheck('B', $aiMigrateRow !== null && str_contains((string) $aiMigrateRow['changesJSON'], '0001_subscription_price_snapshot_columns'), 'the audit row lists the applied upgrades');
aiCheck('B', adminDatabaseReadyForChanges() === true, 'afterwards adminDatabaseReadyForChanges() is true');
$aiAgain = adminRunUpgrades($aiHandAdmin);
aiCheck('B', $aiAgain['ok'] === true && $aiAgain['applied'] === [], 'running it again changes nothing');

// ==============================================================================
// C. CLI grant
// ==============================================================================
echo PHP_EOL . 'C. Command-line grant (decision 0010: a pending account gets a new password)' . PHP_EOL;
$aiOwnerBefore = aiOwnerSnapshot($aiServer, $aiOwnerID);
aiCheck('C', $aiOwnerBefore['liveSessions'] === 2 && $aiOwnerBefore['activeKeys'] === 1 && $aiOwnerBefore['unusedResets'] === 1 && $aiOwnerBefore['oauthLinks'] === 1 && $aiOwnerBefore['passkeys'] === 1, 'set-up: the pending owner has 2 live sessions, 1 live API key, 1 unused reset link, 1 linked Google sign-in, 1 passkey');

// 📭 #113 B9 (findings 4 and 6b): emails waiting for the pending owner. Two verification
//    emails (one queued, one failed with attempts left) and one password-reset email are
//    "waiting" and must be cancelled by the grant. The others must NOT be touched: one being
//    sent right now, one already sent, one given up (no attempts left), another template, and
//    the same template for another person.
$aiQueueRows = [
    [$aiOwnerEmail, 'verification', 'queued', 0, 5],
    [$aiOwnerEmail, 'verification', 'failed', 2, 5],
    [$aiOwnerEmail, 'password_reset', 'queued', 0, 5],
    [$aiOwnerEmail, 'verification', 'sending', 1, 5],
    [$aiOwnerEmail, 'verification', 'sent', 1, 5],
    [$aiOwnerEmail, 'verification', 'failed', 5, 5],
    [$aiOwnerEmail, 'welcome', 'queued', 0, 5],
    [$aiAccounts['second'][0], 'verification', 'queued', 0, 5],
];
foreach ($aiQueueRows as $aiQueueIndex => $aiQueueRow) {
    $aiQueueInsert = $aiServer->prepare('INSERT INTO `tblEmailQueue` (`toAddress`, `templateKey`, `subject`, `paramsJSON`, `status`, `attempts`, `maxAttempts`) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $aiQueueSubject = 'IT queue row ' . $aiQueueIndex;
    $aiQueueParams = '{"verificationUrl":"https://it.invalid/x"}';
    $aiQueueInsert->bind_param('sssssii', $aiQueueRow[0], $aiQueueRow[1], $aiQueueSubject, $aiQueueParams, $aiQueueRow[2], $aiQueueRow[3], $aiQueueRow[4]);
    $aiQueueInsert->execute();
    $aiQueueInsert->close();
}
/**
 * 📭 The state of every test queue row, in insert order (status and whether its details are still stored).
 */
$aiQueueState = static function () use ($aiServer): array {
    $aiOut = [];
    $aiResult = $aiServer->query("SELECT `status`, (`paramsJSON` IS NULL) AS `cleared` FROM `tblEmailQueue` WHERE `subject` LIKE 'IT queue row %' ORDER BY `emailID`");
    while (($aiQueueRowOut = $aiResult->fetch_assoc()) !== null) {
        $aiOut[] = $aiQueueRowOut['status'] . ':' . $aiQueueRowOut['cleared'];
    }
    return $aiOut;
};
$aiQueueBefore = $aiQueueState();
aiCheck('C', count($aiQueueBefore) === 8 && $aiQueueBefore[0] === 'queued:0', 'set-up: eight queue rows, details stored');

// C1. No terminal: refused BEFORE anything is asked, even with a password piped in.
$aiPipedPassword = 'Piped-New-' . bin2hex(random_bytes(6));
$aiRun = aiCli($aiDbName, ['grant', $aiOwnerEmail, '--activate', '--yes'], $aiPipedPassword . "\n" . $aiPipedPassword . "\n");
aiCheck('C', $aiRun['exit'] === 2, 'C1 grant --activate --yes from a PIPE: exit 2, refused (got ' . $aiRun['exit'] . ')');
aiCheck('C', str_contains($aiRun['err'], 'not a terminal') && str_contains($aiRun['err'], 'decision 0010'), 'C1 the refusal says its input is not a terminal (decision 0010)');
aiCheck('C', str_contains($aiRun['out'], 'Registered at:') && str_contains($aiRun['out'], 'Display name:   IT owner') && str_contains($aiRun['out'], 'WARNING: if you did not register this account at that time, stop'), 'C1 the account details and the "stop" warning were still shown');
aiCheck('C', !str_contains($aiRun['out'] . $aiRun['err'], $aiPipedPassword), 'C1 the piped password is never echoed');
aiCheck('C', aiOwnerSnapshot($aiServer, $aiOwnerID) === $aiOwnerBefore, 'C1 nothing changed (role, status, password hash, sessions, keys, links, audit)');
$aiRun = aiCli($aiDbName, ['grant', $aiOwnerEmail, '--activate'], $aiOwnerEmail . "\n" . $aiPipedPassword . "\n" . $aiPipedPassword . "\n");
aiCheck('C', $aiRun['exit'] === 2 && !str_contains($aiRun['out'], 'Type the email address again'), 'C1 without --yes, from a pipe: refused before the typed confirmation is even asked');
aiCheck('C', aiOwnerSnapshot($aiServer, $aiOwnerID) === $aiOwnerBefore, 'C1 ...and nothing changed');

// C2. One transaction: force a failure part-way and prove NOTHING changed.
//     A database trigger makes one statement fail: first the audit row (the
//     LAST step, after the password, sessions, keys and links were changed),
//     then removing the linked sign-in (a middle step).
$aiForcedHash = hashPassword('Forced-' . bin2hex(random_bytes(8)));
$aiForcedFailures = [
    'the audit row (last step)'        => ['tblAdminAuditLog', 'INSERT'],
    'removing linked sign-ins (middle)' => ['tblUserOAuth', 'DELETE'],
];
foreach ($aiForcedFailures as $aiWhere => $aiTrigger) {
    $aiServer->query('DROP TRIGGER IF EXISTS `aiForcedFailure`');
    $aiServer->query('CREATE TRIGGER `aiForcedFailure` BEFORE ' . $aiTrigger[1] . ' ON `' . $aiTrigger[0] . '` FOR EACH ROW SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'forced failure for the integration test\'');
    $aiForced = adminCliGrant($aiOwnerEmail, true, 'cli:integration-test', $aiOwnerID, $aiForcedHash);
    $aiServer->query('DROP TRIGGER IF EXISTS `aiForcedFailure`');
    aiCheck('C', $aiForced['exitCode'] === 1 && str_contains($aiForced['message'], 'Nothing was changed'), 'C2 forced failure at ' . $aiWhere . ': exit 1, "Nothing was changed"');
    aiCheck('C', $aiQueueState() === $aiQueueBefore, 'C2 forced failure at ' . $aiWhere . ': the waiting emails are exactly as before too (the cancel was rolled back)');
    aiCheck('C', aiOwnerSnapshot($aiServer, $aiOwnerID) === $aiOwnerBefore, 'C2 forced failure at ' . $aiWhere . ': password, role, status, sessions, keys, links and audit are ALL exactly as before');
}
$aiForced = adminCliGrant($aiOwnerEmail, true, 'cli:integration-test', $aiOwnerID, null);
aiCheck('C', $aiForced['exitCode'] === 2 && str_contains($aiForced['message'], 'new password typed at the keyboard'), 'C2 adminCliGrant() with no new password for a pending account: refused (exit 2)');
$aiForced = adminCliGrant($aiOwnerEmail, true, 'cli:integration-test', $aiOwnerID + 1000, $aiForcedHash);
aiCheck('C', $aiForced['exitCode'] === 2 && str_contains($aiForced['message'], 'not the one you were shown'), 'C2 a different account number from the one shown: refused (exit 2)');
aiCheck('C', aiOwnerSnapshot($aiServer, $aiOwnerID) === $aiOwnerBefore, 'C2 ...and still nothing changed');

// C3. The real command in a (pretend) terminal: details, warning, typed
//     confirmation, then the new password - a weak one, a mismatch, then a
//     good one (three tries).
$aiOldOwnerPassword = $aiPasswords['owner'];
$aiNewOwnerPassword = 'New-Owner-' . bin2hex(random_bytes(8));
$aiWeakPassword     = 'password123';
$aiTty = aiCliTty($aiDbName, ['grant', $aiOwnerEmail, '--activate'], [
    ['Type the email address again to confirm: ', $aiOwnerEmail],
    ['New password: ', $aiWeakPassword],
    ['Type the new password again: ', $aiWeakPassword],
    ['New password: ', $aiNewOwnerPassword],
    ['Type the new password again: ', $aiNewOwnerPassword . 'x'],
    ['New password: ', $aiNewOwnerPassword],
    ['Type the new password again: ', $aiNewOwnerPassword],
]);

if ($aiTty === null) {
    // 🟡 No pseudo-terminal tool here: do the same grant in-process so the
    //    later scenarios still have an admin, and SAY that C3 did not run.
    $aiSkipped[] = 'C3 (the real command in a pretend terminal) - no usable `script` tool on this machine; the grant was made in-process instead';
    $aiFallback = adminCliGrant($aiOwnerEmail, true, 'cli:integration-test', $aiOwnerID, hashPassword($aiNewOwnerPassword));
    aiCheck('C', $aiFallback['exitCode'] === 0, 'C3 (fallback, in-process) grant with a new password hash: exit 0');
} else {
    $aiTranscript = $aiTty['out'];
    aiCheck('C', $aiTty['exit'] === 0 && $aiTty['stepsDone'] === 7, 'C3 in a terminal: all 7 prompts answered, exit 0 (got exit ' . $aiTty['exit'] . ', ' . $aiTty['stepsDone'] . ' prompts)');
    $aiStopAt    = strpos($aiTranscript, 'WARNING: if you did not register this account at that time, stop');
    $aiConfirmAt = strpos($aiTranscript, 'Type the email address again');
    aiCheck('C', $aiStopAt !== false && $aiConfirmAt !== false && $aiStopAt < $aiConfirmAt && strpos($aiTranscript, 'Registered at:') < $aiConfirmAt && strpos($aiTranscript, 'Display name:   IT owner') < $aiConfirmAt, 'C3 email, display name, registration time and the "stop" warning come BEFORE the typed confirmation');
    aiCheck('C', str_contains($aiTranscript, 'too common'), 'C3 a weak password is refused with the site\'s own rule ("too common")');
    aiCheck('C', str_contains($aiTranscript, 'did not match'), 'C3 two different typings are refused ("did not match")');
    aiCheck('C', !str_contains($aiTranscript, $aiNewOwnerPassword) && !str_contains($aiTranscript, $aiWeakPassword), 'C3 NOTHING typed as a password appears on the terminal (the typing was hidden)');
    aiCheck('C', str_contains($aiTranscript, 'Removed: 2 sign-in session(s) ended, 1 API key(s) switched off, 1 unused password-reset link(s) cancelled, 1 linked Microsoft/Google sign-in(s) removed, 1 passkey(s) removed.'), 'C3 the operator is told exactly what was removed');
}

// 📭 #113 B9: the waiting verification/reset emails were cancelled; nothing else was touched.
$aiQueueAfter = $aiQueueState();
aiCheck('C', $aiQueueAfter === ['skipped:1', 'skipped:1', 'skipped:1', 'sending:0', 'sent:0', 'failed:0', 'queued:0', 'queued:0'], 'B9: queued and retry-waiting verification emails and the reset email are skipped with details cleared; sending, sent, given-up, other-template and other-person rows are untouched (got ' . implode(' ', $aiQueueAfter) . ')');
aiCheck('C', aiCount($aiServer, "SELECT COUNT(*) FROM `tblEmailQueue` WHERE `subject` LIKE 'IT queue row %' AND `status` = 'skipped' AND `lastError` LIKE 'Cancelled:%'") === 3, 'B9: the three cancelled rows say why (lastError starts "Cancelled:")');
$aiOwnerAfter = aiOwnerSnapshot($aiServer, $aiOwnerID);
aiCheck('C', (int) $aiOwnerAfter['roleID'] === 1, 'the account is now roleID 1');
aiCheck('C', $aiOwnerAfter['status'] === 'active', 'the account is now active');
aiCheck('C', $aiOwnerAfter['emailVerifiedAt'] !== null, 'the account now has a checked email (emailVerifiedAt set)');
aiCheck('C', verifyPassword($aiOldOwnerPassword, (string) $aiOwnerAfter['passwordHash']) === false, 'the OLD password (possibly a stranger\'s) no longer works');
aiCheck('C', verifyPassword($aiNewOwnerPassword, (string) $aiOwnerAfter['passwordHash']) === true, 'the NEW password works');
aiCheck('C', str_starts_with((string) $aiOwnerAfter['passwordHash'], '$argon2id$') && passwordNeedsRehash((string) $aiOwnerAfter['passwordHash']) === false, 'the new password is stored with the site\'s own hashing (Argon2id, current settings)');
aiCheck('C', $aiOwnerAfter['liveSessions'] === 0, 'every signed-in session of the account was ended');
aiCheck('C', $aiOwnerAfter['activeKeys'] === 0, 'the account\'s API key was switched off');
aiCheck('C', $aiOwnerAfter['unusedResets'] === 0, 'the unused password-reset link was cancelled');
aiCheck('C', $aiOwnerAfter['oauthLinks'] === 0 && $aiOwnerAfter['passkeys'] === 0, 'the linked Google sign-in and the passkey were removed');
aiCheck('C', aiCount($aiServer, 'SELECT COUNT(*) FROM `tblUserSessions` WHERE `userID` = ? AND `revokedAt` IS NULL', 'i', [$aiSecondID]) === 1 && aiCount($aiServer, 'SELECT COUNT(*) FROM `tblAPIKeys` WHERE `userID` = ? AND `isActive` = 1', 'i', [$aiSecondID]) === 1, 'another account\'s session and API key were NOT touched');
$aiPasswords['owner'] = $aiNewOwnerPassword;

$aiGrantRow = aiRow($aiServer, "SELECT `actorUserID`, `actorType`, `actorLabel`, `outcome`, `changesJSON`, `reason` FROM `tblAdminAuditLog` WHERE `action` = 'admin.grant' AND `entityKey` = ?", 's', [(string) $aiUserIDs['owner']]);
aiCheck('C', $aiGrantRow !== null && $aiGrantRow['actorType'] === 'cli' && $aiGrantRow['actorUserID'] === null && str_starts_with((string) $aiGrantRow['actorLabel'], 'cli:'), 'admin.grant audit row: actor cli:<operating-system user>');
$aiGrantChanges = json_decode((string) $aiGrantRow['changesJSON'], true);
aiCheck('C', is_array($aiGrantChanges) && $aiGrantChanges['roleID']['new'] === 1 && $aiGrantChanges['status']['old'] === 'pending' && $aiGrantChanges['status']['new'] === 'active', 'the audit row records role and status before and after');
aiCheck('C', is_array($aiGrantChanges) && isset($aiGrantChanges['password_reset']['new']) && $aiGrantChanges['password_reset']['new'] === true, 'the audit row says password_reset: true');
aiCheck('C', is_array($aiGrantChanges) && isset($aiGrantChanges['access_removed']['new']) && $aiGrantChanges['access_removed']['new'] == ['sessions_ended' => 2, 'api_keys_turned_off' => 1, 'password_reset_links_cancelled' => 1, 'linked_sign_ins_removed' => 1, 'passkeys_removed' => 1], 'the audit row lists how many of each were removed (MySQL stores JSON keys in its own order, so order is not compared)');
aiCheck('C', is_array($aiGrantChanges) && isset($aiGrantChanges['emails_cancelled']['new']) && $aiGrantChanges['emails_cancelled']['new'] == ['verification_emails_cancelled' => 2, 'password_reset_emails_cancelled' => 1], 'B9: the audit row records only the counts of cancelled emails (2 verification, 1 password-reset)');
aiCheck('C', !str_contains((string) $aiGrantRow['changesJSON'], $aiOwnerEmail) && !str_contains((string) $aiGrantRow['changesJSON'], 'it.invalid'), 'B9: ...and no address or link');
aiCheck('C', str_contains((string) $aiGrantRow['reason'], 'Decision 0010') && str_contains((string) $aiGrantRow['reason'], 'Registered at'), 'the audit reason names decision 0010 and the registration time shown');
$aiAuditText = (string) $aiGrantRow['changesJSON'] . ' ' . (string) $aiGrantRow['reason'];
aiCheck('C', !str_contains($aiAuditText, $aiNewOwnerPassword) && !str_contains($aiAuditText, $aiOldOwnerPassword) && !str_contains($aiAuditText, '$argon2') && !str_contains($aiAuditText, (string) $aiOwnerAfter['passwordHash']), 'the audit row holds no password and no password hash');
$aiLogHashes = [(string) $aiOwnerAfter['passwordHash'], $aiForcedHash];

$aiRun = aiCli($aiDbName, ['grant', $aiAccounts['second'][0]], "someone-else@it.cuercode.invalid\n");
aiCheck('C', $aiRun['exit'] === 2 && str_contains($aiRun['err'], 'did not match'), 'a wrong typed confirmation is refused (exit 2)');
aiCheck('C', aiRow($aiServer, 'SELECT `roleID` FROM `tblUsers` WHERE `userID` = ?', 'i', [$aiUserIDs['second']])['roleID'] === null, 'nothing changed after the wrong confirmation');
$aiRun = aiCli($aiDbName, ['grant', $aiAccounts['second'][0]], ' SECOND@it.cuercode.invalid ' . "\n");
aiCheck('C', $aiRun['exit'] === 0, 'the right typed confirmation (any case, spaces) grants (exit 0)');
aiCheck('C', (int) aiRow($aiServer, 'SELECT `roleID` FROM `tblUsers` WHERE `userID` = ?', 'i', [$aiUserIDs['second']])['roleID'] === 1, 'second account is now a Global Admin');
// 🔐 Decision 0010: an ACTIVE account with a checked email is NOT reset - no
//    terminal needed (that grant came through a pipe), password unchanged,
//    sessions and keys untouched, no password_reset in its audit row.
aiCheck('C', !str_contains($aiRun['out'], 'NEW password') && !str_contains($aiRun['out'], 'WARNING: if you did not register'), 'active account: no new password asked, no "stop" warning');
aiCheck('C', str_contains($aiRun['out'], 'Registered at:'), 'active account: its details are still shown before confirming');
aiCheck('C', verifyPassword($aiPasswords['second'], (string) aiRow($aiServer, 'SELECT `passwordHash` FROM `tblUsers` WHERE `userID` = ?', 'i', [$aiSecondID])['passwordHash']) === true, 'active account: its own password still works (not reset)');
aiCheck('C', aiCount($aiServer, 'SELECT COUNT(*) FROM `tblUserSessions` WHERE `userID` = ? AND `revokedAt` IS NULL', 'i', [$aiSecondID]) === 1 && aiCount($aiServer, 'SELECT COUNT(*) FROM `tblAPIKeys` WHERE `userID` = ? AND `isActive` = 1', 'i', [$aiSecondID]) === 1, 'active account: its session and API key are untouched');
$aiSecondGrant = aiRow($aiServer, "SELECT `changesJSON`, `reason` FROM `tblAdminAuditLog` WHERE `action` = 'admin.grant' AND `entityKey` = ?", 's', [(string) $aiSecondID]);
aiCheck('C', $aiSecondGrant !== null && !str_contains((string) $aiSecondGrant['changesJSON'], 'password_reset') && $aiSecondGrant['reason'] === null, 'active account: its audit row has no password_reset');
$aiRun = aiCli($aiDbName, ['grant', $aiAccounts['second'][0], '--yes']);
aiCheck('C', $aiRun['exit'] === 0 && str_contains($aiRun['out'], 'already a Global Admin'), 'granting an existing admin again: exit 0, nothing to do');
aiCheck('C', aiCount($aiServer, "SELECT COUNT(*) FROM `tblAdminAuditLog` WHERE `action` = 'admin.grant' AND `entityKey` = ?", 's', [(string) $aiUserIDs['second']]) === 1, '...and no second audit row');

// ==============================================================================
// D. CLI refusals
// ==============================================================================
echo PHP_EOL . 'D. Command-line refusals' . PHP_EOL;
foreach (['suspended' => 'is suspended', 'deleted' => 'is deleted', 'ssoonly' => 'no password'] as $aiKey => $aiWords) {
    $aiRun = aiCli($aiDbName, ['grant', $aiAccounts[$aiKey][0], '--activate', '--yes']);
    aiCheck('D', $aiRun['exit'] === 2, $aiKey . ' account refused (exit ' . $aiRun['exit'] . ')');
    aiCheck('D', str_contains($aiRun['err'], $aiWords), $aiKey . ': the reason is given ("' . $aiWords . '")');
    aiCheck('D', aiRow($aiServer, 'SELECT `roleID` FROM `tblUsers` WHERE `userID` = ?', 'i', [$aiUserIDs[$aiKey]])['roleID'] === null, $aiKey . ': nothing changed');
}
$aiRun = aiCli($aiDbName, ['grant', 'nobody@it.cuercode.invalid', '--yes']);
aiCheck('D', $aiRun['exit'] === 2 && str_contains($aiRun['err'], 'No account has that email'), 'unknown email refused (exit 2)');

// ------------------------------------------------------------------------------
// D2. Review fix I1: a grant on an account that is not active with a checked
//     email is REFUSED without --activate, and an emailed link can never
//     activate an account that already holds the Global Admin role.
// ------------------------------------------------------------------------------
echo PHP_EOL . 'D2. Review fix I1 (no grant without --activate; links never activate an admin)' . PHP_EOL;
require_once $aiSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_auth.php';
$aiPendingEmail = 'pendingi1@it.cuercode.invalid';
$aiPendingPlain = 'Pending-' . bin2hex(random_bytes(8));
aiInsertRows($aiServer, 'INSERT INTO `tblUsers` (`email`, `passwordHash`, `displayName`, `status`) VALUES (?, ?, ?, ?)', 'ssss', [
    [$aiPendingEmail, $aiHash($aiPendingPlain), 'IT pending', 'pending'],
]);
$aiPendingID = (int) aiRow($aiServer, 'SELECT `userID` FROM `tblUsers` WHERE `email` = ?', 's', [$aiPendingEmail])['userID'];

$aiRun = aiCli($aiDbName, ['grant', $aiPendingEmail, '--yes']);
aiCheck('D2', $aiRun['exit'] === 2 && str_contains($aiRun['err'], '--activate'), 'grant on a pending account WITHOUT --activate: exit 2 and the message names --activate (got ' . $aiRun['exit'] . ')');
$aiPendingRow = aiRow($aiServer, 'SELECT `roleID`, `status`, `emailVerifiedAt` FROM `tblUsers` WHERE `userID` = ?', 'i', [$aiPendingID]);
aiCheck('D2', $aiPendingRow['roleID'] === null && $aiPendingRow['status'] === 'pending' && $aiPendingRow['emailVerifiedAt'] === null, '...and nothing changed (no role, still pending)');
aiCheck('D2', aiCount($aiServer, "SELECT COUNT(*) FROM `tblAdminAuditLog` WHERE `action` = 'admin.grant' AND `entityKey` = ?", 's', [(string) $aiPendingID]) === 0, '...and no grant row was written');

// 🛠️ A hand-made admin (as in phpMyAdmin) that is still pending, with a live emailed link.
$aiServer->query('UPDATE `tblUsers` SET `roleID` = 1 WHERE `userID` = ' . (int) $aiPendingID);
$aiRawLink = bin2hex(random_bytes(16));
aiInsertRows($aiServer, 'INSERT INTO `tblEmailVerifications` (`userID`, `tokenHash`, `expiresAt`) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))', 'is', [
    [$aiPendingID, hashToken($aiRawLink)],
]);
// 🔑 Decision 0013: the link now also needs the account's password - given
//    here (the RIGHT one), so the refusal below is the admin rule alone.
$aiVerify = verifyEmailToken($aiRawLink, $aiPendingPlain);
aiCheck('D2', $aiVerify['success'] === false, 'verifyEmailToken() refuses an account that already holds the Global Admin role (even with the right password)');
$aiPendingRow = aiRow($aiServer, 'SELECT `status`, `emailVerifiedAt` FROM `tblUsers` WHERE `userID` = ?', 'i', [$aiPendingID]);
aiCheck('D2', $aiPendingRow['status'] === 'pending' && $aiPendingRow['emailVerifiedAt'] === null, '...the account stays pending with no checked email');
aiCheck('D2', aiCount($aiServer, 'SELECT COUNT(*) FROM `tblEmailVerifications` WHERE `userID` = ? AND `usedAt` IS NULL', 'i', [$aiPendingID]) === 1, '...and the link was not used up');

// 👤 Without the role, the same link still works (the ordinary path is unharmed).
$aiServer->query('UPDATE `tblUsers` SET `roleID` = NULL WHERE `userID` = ' . (int) $aiPendingID);
$aiVerify = verifyEmailToken($aiRawLink, $aiPendingPlain);
aiCheck('D2', $aiVerify['success'] === true, 'the same link (with the account\'s password, decision 0013) activates an ordinary pending account');
aiCheck('D2', aiRow($aiServer, 'SELECT `status` FROM `tblUsers` WHERE `userID` = ?', 'i', [$aiPendingID])['status'] === 'active', '...which is now active');

// 🧹 Remove the temporary account so later counts are unchanged.
$aiServer->query('DELETE FROM `tblEmailVerifications` WHERE `userID` = ' . (int) $aiPendingID);
$aiServer->query('DELETE FROM `tblUsers` WHERE `userID` = ' . (int) $aiPendingID);

// ==============================================================================
// E. CLI list
// ==============================================================================
echo PHP_EOL . 'E. Command-line list' . PHP_EOL;
$aiRun = aiCli($aiDbName, ['list']);
aiCheck('E', $aiRun['exit'] === 0, 'list: exit 0');
aiCheck('E', str_contains($aiRun['out'], 'o***r@it.cuercode.invalid') && str_contains($aiRun['out'], 's***d@it.cuercode.invalid'), 'list shows the admins with partly hidden email addresses');
aiCheck('E', !str_contains($aiRun['out'], 'owner@') && !str_contains($aiRun['out'], 'second@'), 'list never prints a full email address');
aiCheck('E', str_contains($aiRun['out'], '3 working Global Admin(s).'), 'list counts the working admins (owner, second, hand)');

// ==============================================================================
// F. CLI revoke
// ==============================================================================
echo PHP_EOL . 'F. Command-line revoke' . PHP_EOL;
$aiRun = aiCli($aiDbName, ['revoke', $aiAccounts['second'][0], '--yes']);
aiCheck('F', $aiRun['exit'] === 0, 'revoke a second admin: exit 0');
aiCheck('F', aiRow($aiServer, 'SELECT `roleID` FROM `tblUsers` WHERE `userID` = ?', 'i', [$aiUserIDs['second']])['roleID'] === null, 'second account has no role any more');
aiCheck('F', aiCount($aiServer, "SELECT COUNT(*) FROM `tblAdminAuditLog` WHERE `action` = 'admin.revoke' AND `entityKey` = ? AND `actorType` = 'cli'", 's', [(string) $aiUserIDs['second']]) === 1, 'admin.revoke audit row written');
$aiRun = aiCli($aiDbName, ['revoke', $aiAccounts['hand'][0], '--yes']);
aiCheck('F', $aiRun['exit'] === 0, 'revoke the hand-made admin: exit 0');
$aiRun = aiCli($aiDbName, ['revoke', $aiOwnerEmail, '--yes']);
aiCheck('F', $aiRun['exit'] === 2 && str_contains($aiRun['err'], 'last working Global Admin'), 'revoking the LAST working admin is refused (exit 2)');
aiCheck('F', (int) aiRow($aiServer, 'SELECT `roleID` FROM `tblUsers` WHERE `userID` = ?', 'i', [$aiUserIDs['owner']])['roleID'] === 1, 'the last admin keeps the role');
$aiRun = aiCli($aiDbName, ['revoke', $aiAccounts['plain'][0], '--yes']);
aiCheck('F', $aiRun['exit'] === 0 && str_contains($aiRun['out'], 'not a Global Admin'), 'revoking an ordinary account: exit 0, nothing to do');

// ==============================================================================
// G. The gate's database read
// ==============================================================================
echo PHP_EOL . 'G. The gate reads access fresh from the database' . PHP_EOL;
aiCheck('G', adminAccessDecision(['userID' => $aiUserIDs['owner']], adminLoadAccessRow($aiUserIDs['owner'])) === 'admin', 'the owner (role 1, active, verified) is let in');
aiCheck('G', adminAccessDecision(['userID' => $aiUserIDs['role2']], adminLoadAccessRow($aiUserIDs['role2'])) === 'not_admin', 'role 2 ("Admin") is not let in');
aiCheck('G', adminAccessDecision(['userID' => $aiUserIDs['second']], adminLoadAccessRow($aiUserIDs['second'])) === 'not_admin', 'a revoked admin is not let in on the very next check');
$aiServer->query("UPDATE `tblUsers` SET `roleID` = 1 WHERE `userID` = " . (int) $aiUserIDs['suspended']);
aiCheck('G', adminAccessDecision(['userID' => $aiUserIDs['suspended']], adminLoadAccessRow($aiUserIDs['suspended'])) === 'not_admin', 'a suspended account with role 1 is not let in');
$aiServer->query("UPDATE `tblUsers` SET `roleID` = 1, `emailVerifiedAt` = NULL WHERE `userID` = " . (int) $aiUserIDs['plain']);
aiCheck('G', adminAccessDecision(['userID' => $aiUserIDs['plain']], adminLoadAccessRow($aiUserIDs['plain'])) === 'not_admin', 'an unverified account with role 1 is not let in');
aiCheck('G', adminLoadAccessRow(999999) === null, 'an unknown user has no access row');
aiCheck('G', adminCountGlobalAdmins() === 1, 'adminCountGlobalAdmins() counts only working admins (1)');

// ==============================================================================
// H. admin.first_seen
// ==============================================================================
echo PHP_EOL . 'H. First visit of a hand-made Global Admin' . PHP_EOL;
$aiServer->query("UPDATE `tblUsers` SET `roleID` = 1 WHERE `userID` = " . (int) $aiUserIDs['hand']);
adminRecordFirstSeen($aiHandAdmin);
adminRecordFirstSeen($aiHandAdmin);
adminRecordFirstSeen($aiHandAdmin);
aiCheck('H', aiCount($aiServer, "SELECT COUNT(*) FROM `tblAdminAuditLog` WHERE `action` = 'admin.first_seen' AND `entityKey` = ?", 's', [(string) $aiUserIDs['hand']]) === 1, 'exactly ONE admin.first_seen row, however often the check runs');
$aiFirstSeen = aiRow($aiServer, "SELECT `actorType`, `actorUserID` FROM `tblAdminAuditLog` WHERE `action` = 'admin.first_seen' AND `entityKey` = ?", 's', [(string) $aiUserIDs['hand']]);
aiCheck('H', $aiFirstSeen['actorType'] === 'system' && $aiFirstSeen['actorUserID'] === null, 'the row is written by "system"');
adminRecordFirstSeen(['userID' => $aiUserIDs['owner'], 'email' => $aiOwnerEmail]);
aiCheck('H', aiCount($aiServer, "SELECT COUNT(*) FROM `tblAdminAuditLog` WHERE `action` = 'admin.first_seen' AND `entityKey` = ?", 's', [(string) $aiUserIDs['owner']]) === 0, 'an admin with an admin.grant row gets no first_seen row');

// 🕰️ Review finding H: compare with the MOST RECENT grant/revoke row. A revoke
//    followed by a hand-made re-grant needs a NEW first_seen row, once.
$aiServer->query("INSERT INTO `tblAdminAuditLog` (`actorUserID`, `actorType`, `actorLabel`, `action`, `entityType`, `entityKey`, `outcome`) VALUES (NULL, 'cli', 'cli:test', 'admin.revoke', 'user', '" . (int) $aiUserIDs['hand'] . "', 'success')");
adminRecordFirstSeen($aiHandAdmin);
adminRecordFirstSeen($aiHandAdmin);
aiCheck('H', aiCount($aiServer, "SELECT COUNT(*) FROM `tblAdminAuditLog` WHERE `action` = 'admin.first_seen' AND `entityKey` = ?", 's', [(string) $aiUserIDs['hand']]) === 2, 'after a revoke and a hand-made re-grant, ONE more first_seen row is written (2 in total)');

// 🏁 Review fix M2: two PHP processes call adminRecordFirstSeen() at the SAME
//    moment for a Global Admin made by hand that has no grant row yet. The row
//    lock on the admin's own tblUsers row must let exactly ONE audit row in.
$aiRaceEmail = 'race@it.cuercode.invalid';
aiInsertRows($aiServer, 'INSERT INTO `tblUsers` (`email`, `emailVerifiedAt`, `passwordHash`, `displayName`, `status`, `roleID`) VALUES (?, NOW(), ?, ?, ?, ?)', 'ssssi', [
    [$aiRaceEmail, $aiHash('Race-' . bin2hex(random_bytes(8))), 'IT race', 'active', 1],
]);
$aiRaceID = (int) aiRow($aiServer, 'SELECT `userID` FROM `tblUsers` WHERE `email` = ?', 's', [$aiRaceEmail])['userID'];
$aiRaceStart = microtime(true) + 3.0;
$aiRaceProcs = [];
for ($aiRaceN = 0; $aiRaceN < 4; $aiRaceN++) {
    $aiRaceCommand = [PHP_BINARY, __FILE__, '--run-first-seen', '--db=' . $aiDbName, '--user=' . $aiRaceID, '--start=' . $aiRaceStart];
    $aiRaceProcs[] = proc_open($aiRaceCommand, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $aiRacePipes[$aiRaceN]);
}
foreach ($aiRaceProcs as $aiRaceN => $aiRaceProc) {
    if (is_resource($aiRaceProc)) {
        stream_get_contents($aiRacePipes[$aiRaceN][1]);
        stream_get_contents($aiRacePipes[$aiRaceN][2]);
        proc_close($aiRaceProc);
    }
}
aiCheck('H', aiCount($aiServer, "SELECT COUNT(*) FROM `tblAdminAuditLog` WHERE `action` = 'admin.first_seen' AND `entityKey` = ?", 's', [(string) $aiRaceID]) === 1, 'M2: four processes racing at the same moment write exactly ONE admin.first_seen row (row lock, no server-wide named lock)');
$aiServer->query('DELETE FROM `tblAdminAuditLog` WHERE `entityKey` = \'' . (int) $aiRaceID . '\'');
$aiServer->query('DELETE FROM `tblUsers` WHERE `userID` = ' . (int) $aiRaceID);

// ==============================================================================
// I. The erasure guard
// ==============================================================================
echo PHP_EOL . 'I. A Global Admin cannot erase their own account' . PHP_EOL;
$aiErase = eraseUserAccount($aiUserIDs['owner'], $aiPasswords['owner'], true);
aiCheck('I', $aiErase['success'] === false && $aiErase['httpCode'] === 409 && $aiErase['errorCode'] === 'GLOBAL_ADMIN_CANNOT_ERASE', 'right password: refused with 409 GLOBAL_ADMIN_CANNOT_ERASE');
$aiErase = eraseUserAccount($aiUserIDs['owner'], 'wrong-password', true);
aiCheck('I', $aiErase['errorCode'] === 'GLOBAL_ADMIN_CANNOT_ERASE', 'wrong password: the same refusal (checked before the password)');
// 🛡️ Review finding G: the erase UPDATE itself refuses a Global Admin (check and erase are one statement).
aiCheck('I', aiCount($aiServer, 'SELECT COUNT(*) FROM `tblUsers` WHERE `userID` = ? AND ' . adminSqlNotGlobalAdminCondition(), 'i', [$aiUserIDs['owner']]) === 0, 'the erase condition matches NO row for a Global Admin');
aiCheck('I', aiCount($aiServer, 'SELECT COUNT(*) FROM `tblUsers` WHERE `userID` = ? AND ' . adminSqlNotGlobalAdminCondition(), 'i', [$aiUserIDs['second']]) === 1, 'the erase condition matches an account with no admin role');
$aiOwnerAfter = aiRow($aiServer, 'SELECT `email`, `status`, `passwordHash` FROM `tblUsers` WHERE `userID` = ?', 'i', [$aiUserIDs['owner']]);
aiCheck('I', $aiOwnerAfter['email'] === $aiOwnerEmail && $aiOwnerAfter['status'] === 'active' && $aiOwnerAfter['passwordHash'] !== null, 'the admin account is untouched');
$aiErase = eraseUserAccount($aiUserIDs['second'], $aiPasswords['second'], true);
aiCheck('I', $aiErase['success'] === true, 'an ordinary (revoked) account still erases normally');
aiCheck('I', aiRow($aiServer, 'SELECT `status` FROM `tblUsers` WHERE `userID` = ?', 'i', [$aiUserIDs['second']])['status'] === 'deleted', '...and is anonymised');

// ==============================================================================
// J. Password re-confirmation against real rows
// ==============================================================================
echo PHP_EOL . 'J. Password re-confirmation' . PHP_EOL;
$aiServer->query('UPDATE `tblUsers` SET `failedLoginCount` = 0, `lockedUntil` = NULL WHERE `userID` = ' . (int) $aiUserIDs['owner']);
$aiVerify = adminVerifyReauthPassword($aiUserIDs['owner'], 'not-the-password');
aiCheck('J', $aiVerify['ok'] === false && $aiVerify['locked'] === false, 'a wrong password is refused');
aiCheck('J', (int) aiRow($aiServer, 'SELECT `failedLoginCount` FROM `tblUsers` WHERE `userID` = ?', 'i', [$aiUserIDs['owner']])['failedLoginCount'] === 1, 'a wrong password counts toward the account lock-out (failedLoginCount 1)');
$aiVerify = adminVerifyReauthPassword($aiUserIDs['owner'], $aiPasswords['owner']);
aiCheck('J', $aiVerify['ok'] === true, 'the right password is accepted');
aiCheck('J', (int) aiRow($aiServer, 'SELECT `failedLoginCount` FROM `tblUsers` WHERE `userID` = ?', 'i', [$aiUserIDs['owner']])['failedLoginCount'] === 0, 'the right password resets the counter');
$aiServer->query('UPDATE `tblUsers` SET `lockedUntil` = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE `userID` = ' . (int) $aiUserIDs['owner']);
$aiVerify = adminVerifyReauthPassword($aiUserIDs['owner'], $aiPasswords['owner']);
aiCheck('J', $aiVerify['ok'] === false && $aiVerify['locked'] === true, 'a locked-out account is refused even with the right password');
$aiServer->query('UPDATE `tblUsers` SET `lockedUntil` = NULL WHERE `userID` = ' . (int) $aiUserIDs['owner']);
$aiVerify = adminVerifyReauthPassword($aiUserIDs['ssoonly'], 'anything');
aiCheck('J', $aiVerify['ok'] === false, 'an account with no password can never confirm');

// ==============================================================================
// K. No password anywhere in the logs
// ==============================================================================
echo PHP_EOL . 'K. Passwords never reach a log' . PHP_EOL;
// 🔐 Decision 0010: also every password typed or piped during the new-password
//    runs, the owner's OLD password, and the new password HASHES.
$aiScanForLeaks = $aiPasswords;
$aiScanForLeaks['owner (old)']        = $aiOldOwnerPassword;
$aiScanForLeaks['piped (refused)']    = $aiPipedPassword;
$aiScanForLeaks['weak (refused)']     = $aiWeakPassword;
$aiScanForLeaks['owner new hash']     = $aiLogHashes[0];
$aiScanForLeaks['forced-failure hash'] = $aiLogHashes[1];
foreach ($aiScanForLeaks as $aiKey => $aiPlain) {
    foreach (['tblAdminAuditLog' => 'changesJSON', 'tblActivityLog' => 'additionalData', 'tblErrorLog' => 'errorDetail'] as $aiTable => $aiColumn) {
        $aiTableHere = aiCount($aiServer, 'SELECT COUNT(*) FROM `INFORMATION_SCHEMA`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ?', 's', [$aiTable]);
        if ($aiTableHere !== 1) {
            continue;
        }
        $aiColumns = [];
        $aiColumnResult = $aiServer->query('SHOW COLUMNS FROM `' . $aiTable . '`');
        while ($aiColumnRow = $aiColumnResult->fetch_assoc()) {
            $aiColumns[] = 'COALESCE(CAST(`' . $aiColumnRow['Field'] . '` AS CHAR), \'\')';
        }
        $aiFound = aiCount($aiServer, 'SELECT COUNT(*) FROM `' . $aiTable . '` WHERE CONCAT_WS(\' \', ' . implode(', ', $aiColumns) . ') LIKE ?', 's', ['%' . $aiPlain . '%']);
        aiCheck('K', $aiFound === 0, 'the ' . $aiKey . ' value is not in ' . $aiTable);
    }
}

// ==============================================================================
// L. Independent review (Codex C2 findings 1 and 2, 2026-11-25): nothing is
//    unlocked or upgraded unless its change-log row was written
// ==============================================================================
echo PHP_EOL . 'L. Change-log row first (Codex C2-1, C2-2)' . PHP_EOL;
/**
 * 🔓 Run adminReauthRecordSuccess() in a child process (it needs a PHP session,
 *    which this script cannot start once it has printed anything).
 *
 * @return array{recorded?: bool, unlocked?: bool, session?: bool, crashed?: string}
 */
function aiReauthInChild(string $aiDb, int $aiUser): array
{
    $aiOut = [];
    $aiCode = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --run-reauth-success --db=' . escapeshellarg($aiDb) . ' --user=' . $aiUser . ' 2>&1', $aiOut, $aiCode);
    foreach ($aiOut as $aiLine) {
        if (str_starts_with($aiLine, 'REAUTH-RESULT:')) {
            $aiDecoded = json_decode(substr($aiLine, strlen('REAUTH-RESULT:')), true);
            if (is_array($aiDecoded)) {
                return $aiDecoded;
            }
        }
    }
    return ['crashed' => substr(implode(' | ', $aiOut), 0, 400)];
}

// 🔓 C2-1: the change log refuses rows -> the password confirmation must NOT
//    unlock editing.
$aiServer->query('DROP TRIGGER IF EXISTS `aiForcedFailure`');
$aiServer->query('CREATE TRIGGER `aiForcedFailure` BEFORE INSERT ON `tblAdminAuditLog` FOR EACH ROW SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'forced failure for the integration test\'');
$aiReauthChild = aiReauthInChild($aiDbName, (int) $aiUserIDs['hand']);
aiCheck('L', isset($aiReauthChild['session']) && $aiReauthChild['session'] === true, 'sanity: the child process has a PHP session (so "not unlocked" means something) ' . json_encode($aiReauthChild));
aiCheck('L', isset($aiReauthChild['recorded']) && $aiReauthChild['recorded'] === false, 'C2-1: with the change log refusing rows, the confirmation reports failure');
aiCheck('L', isset($aiReauthChild['unlocked']) && $aiReauthChild['unlocked'] === false, 'C2-1: ...and editing is NOT unlocked');

// 🗄️ C2-2: an upgrade must not run before its "started" row is written.
//    Migration 0003's ledger row is removed so the runner sees it as waiting
//    (its steps all check first, so re-running it changes nothing else).
$aiServer->query("DELETE FROM `tblSchemaMigrations` WHERE `migrationKey` = '0003_sessions_valid_from'");
$aiUpgradeRefused = adminRunUpgrades($aiHandAdmin);
aiCheck('L', $aiUpgradeRefused['ok'] === false, 'C2-2: with the change log refusing rows, the upgrade does not report success (' . $aiUpgradeRefused['status'] . ')');
aiCheck('L', aiCount($aiServer, "SELECT COUNT(*) FROM `tblSchemaMigrations` WHERE `migrationKey` = '0003_sessions_valid_from'") === 0, 'C2-2: ...and NOTHING was upgraded (0003 is still waiting)');
aiCheck('L', adminUpgradeAuditWarning($aiUpgradeRefused) === '', 'C2-2: ...and the page does not claim "the upgrade ran"');
$aiServer->query('DROP TRIGGER IF EXISTS `aiForcedFailure`');

$aiReauthChild = aiReauthInChild($aiDbName, (int) $aiUserIDs['hand']);
aiCheck('L', isset($aiReauthChild['recorded'], $aiReauthChild['unlocked']) && $aiReauthChild['recorded'] === true && $aiReauthChild['unlocked'] === true, 'C2-1: once the change log works, the confirmation unlocks editing ' . json_encode($aiReauthChild));
$aiMaxAuditBefore = aiCount($aiServer, 'SELECT COALESCE(MAX(`auditID`), 0) FROM `tblAdminAuditLog`');
$aiUpgradeOk = adminRunUpgrades($aiHandAdmin);
aiCheck('L', $aiUpgradeOk['ok'] === true && aiCount($aiServer, "SELECT COUNT(*) FROM `tblSchemaMigrations` WHERE `migrationKey` = '0003_sessions_valid_from'") === 1, 'C2-2: once the change log works, the upgrade runs');
$aiStartedRow = aiRow($aiServer, "SELECT `auditID` FROM `tblAdminAuditLog` WHERE `action` = 'schema.migrate.started' AND `auditID` > ? ORDER BY `auditID` LIMIT 1", 'i', [$aiMaxAuditBefore]);
$aiFinalRow   = aiRow($aiServer, "SELECT `auditID` FROM `tblAdminAuditLog` WHERE `action` = 'schema.migrate' AND `auditID` > ? ORDER BY `auditID` LIMIT 1", 'i', [$aiMaxAuditBefore]);
aiCheck('L', $aiStartedRow !== null && $aiFinalRow !== null && (int) $aiStartedRow['auditID'] < (int) $aiFinalRow['auditID'], 'C2-2: a "schema.migrate.started" row is written BEFORE the upgrade, and the usual "schema.migrate" row after it');

// ==============================================================================
// 🏁 Clean up and report
// ==============================================================================
$aiServer->query('DROP DATABASE IF EXISTS `' . $aiDbName . '`');
$aiServer->close();

echo PHP_EOL . 'Summary (' . $GLOBALS['aiCheckCount'] . ' checks):' . PHP_EOL;
$aiAllPassed = true;
foreach ($aiChecks as $aiScenario => $aiPassed) {
    if ($aiPassed) {
        echo '  PASS ' . $aiScenario . PHP_EOL;
    } else {
        echo '  FAIL ' . $aiScenario . PHP_EOL;
        $aiAllPassed = false;
    }
}

// 🟡 Say plainly what did NOT run on this machine.
foreach ($aiSkipped as $aiSkippedWhat) {
    echo '  NOT RUN: ' . $aiSkippedWhat . PHP_EOL;
}

if ($aiAllPassed) {
    exit(0);
}

exit(1);
