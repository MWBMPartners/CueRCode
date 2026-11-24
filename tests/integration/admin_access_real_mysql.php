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
 *   C. CLI grant: `grant --activate --yes` on a PENDING account makes it an
 *      active, verified Global Admin with an admin.grant audit row (actor
 *      cli:<user>), in one transaction; the typed-confirmation path (no
 *      --yes) works and a wrong confirmation is refused.
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
 *   K. No password typed during the run appears anywhere in the audit log,
 *      the activity log or the error log tables.
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

$aiOwnerEmail = $aiAccounts['owner'][0];

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
echo PHP_EOL . 'C. Command-line grant' . PHP_EOL;
$aiRun = aiCli($aiDbName, ['grant', $aiOwnerEmail, '--activate', '--yes']);
aiCheck('C', $aiRun['exit'] === 0, 'grant --activate --yes on a pending account: exit 0 (got ' . $aiRun['exit'] . ', ' . trim($aiRun['err']) . ')');
$aiOwnerRow = aiRow($aiServer, 'SELECT `roleID`, `status`, `emailVerifiedAt` FROM `tblUsers` WHERE `userID` = ?', 'i', [$aiUserIDs['owner']]);
aiCheck('C', (int) $aiOwnerRow['roleID'] === 1, 'the account is now roleID 1');
aiCheck('C', $aiOwnerRow['status'] === 'active', 'the account is now active');
aiCheck('C', $aiOwnerRow['emailVerifiedAt'] !== null, 'the account now has a checked email (emailVerifiedAt set)');
$aiGrantRow = aiRow($aiServer, "SELECT `actorUserID`, `actorType`, `actorLabel`, `outcome`, `changesJSON` FROM `tblAdminAuditLog` WHERE `action` = 'admin.grant' AND `entityKey` = ?", 's', [(string) $aiUserIDs['owner']]);
aiCheck('C', $aiGrantRow !== null && $aiGrantRow['actorType'] === 'cli' && $aiGrantRow['actorUserID'] === null && str_starts_with((string) $aiGrantRow['actorLabel'], 'cli:'), 'admin.grant audit row: actor cli:<operating-system user>');
$aiGrantChanges = json_decode((string) $aiGrantRow['changesJSON'], true);
aiCheck('C', is_array($aiGrantChanges) && $aiGrantChanges['roleID']['new'] === 1 && $aiGrantChanges['status']['old'] === 'pending' && $aiGrantChanges['status']['new'] === 'active', 'the audit row records role and status before and after');

$aiRun = aiCli($aiDbName, ['grant', $aiAccounts['second'][0]], "someone-else@it.cuercode.invalid\n");
aiCheck('C', $aiRun['exit'] === 2 && str_contains($aiRun['err'], 'did not match'), 'a wrong typed confirmation is refused (exit 2)');
aiCheck('C', aiRow($aiServer, 'SELECT `roleID` FROM `tblUsers` WHERE `userID` = ?', 'i', [$aiUserIDs['second']])['roleID'] === null, 'nothing changed after the wrong confirmation');
$aiRun = aiCli($aiDbName, ['grant', $aiAccounts['second'][0]], ' SECOND@it.cuercode.invalid ' . "\n");
aiCheck('C', $aiRun['exit'] === 0, 'the right typed confirmation (any case, spaces) grants (exit 0)');
aiCheck('C', (int) aiRow($aiServer, 'SELECT `roleID` FROM `tblUsers` WHERE `userID` = ?', 'i', [$aiUserIDs['second']])['roleID'] === 1, 'second account is now a Global Admin');
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
foreach ($aiPasswords as $aiKey => $aiPlain) {
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
        aiCheck('K', $aiFound === 0, 'the ' . $aiKey . ' password is not in ' . $aiTable);
    }
}

// ==============================================================================
// 🏁 Clean up and report
// ==============================================================================
$aiServer->query('DROP DATABASE IF EXISTS `' . $aiDbName . '`');
$aiServer->close();

echo PHP_EOL . 'Summary:' . PHP_EOL;
$aiAllPassed = true;
foreach ($aiChecks as $aiScenario => $aiPassed) {
    if ($aiPassed) {
        echo '  PASS ' . $aiScenario . PHP_EOL;
    } else {
        echo '  FAIL ' . $aiScenario . PHP_EOL;
        $aiAllPassed = false;
    }
}

if ($aiAllPassed) {
    exit(0);
}

exit(1);
