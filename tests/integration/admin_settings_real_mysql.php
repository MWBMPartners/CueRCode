<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Real-database check for the admin SETTINGS editor (7n Phase 1C)
 * ==============================================================================
 *
 * Path: tests/integration/admin_settings_real_mysql.php
 *
 * tests/test_admin_settings.php proves the DECISIONS without a database. This
 * script proves what only a real MySQL/MariaDB server can, against one
 * throwaway database built from install.sql + seed_data.sql + every upgrade:
 *
 *   A. Before the upgrades: nothing can be saved (no audit table).
 *   B. Secrets: save → the stored value is ciphertext (never the typed text),
 *      getConfig() returns the typed text, every save gets a FRESH
 *      encryptionSalt and a different ciphertext (even for the same text);
 *      "replace" and "remove" work; each is audited as set / replaced /
 *      cleared and the audit row holds no secret.
 *   C. Ordinary settings: retention periods, VAT mode (read back by the
 *      pricing page's own getConfig() call), unchanged saves leave no audit
 *      row, out-of-range values are refused.
 *   D. Locked keys: the charging switches, dynamic_qr_enabled, caches and
 *      not-editable rows cannot be changed even by hand-made requests (the
 *      section processor never reads them; adminSaveSetting() refuses them by
 *      name); a row whose "secret" flag does not match is refused.
 *   E. One transaction: a database trigger forces the audit row (and, in a
 *      second run, the setting update) to fail - the whole section is left
 *      exactly as it was; no audit row; nothing is half-saved.
 *   F. afterSave: changing a Graph detail clears graph_token_cache; changing
 *      a discovery URL clears only that provider's discovery and JWKS caches.
 *   G. The two test actions with stand-ins (NO real Microsoft credentials
 *      exist here): "Check connection" clears the token cache first, shows
 *      Connected / Could not connect with no token and no error text, and
 *      audits setting.test_graph; "Send me a test email" refuses while email
 *      is off, sends only to the admin's own address, is limited to 5 in 10
 *      minutes and audits setting.test_email.
 *   H. Trusted forwarding servers: saved (the row did not exist), read back by
 *      clientIpTrustedProxySetting().
 *   I. Failure leaks nothing: a forced database failure while saving a secret
 *      (trigger) and a forced exception inside encryptValue() leave no secret
 *      in tblErrorLog, tblActivityLog, tblAdminAuditLog, the PHP error log or
 *      the exception's own stack trace.
 *   J. The REAL page /admin/settings/ in a child PHP process with a forged
 *      signed-in session: GET shows "Set" for a stored secret and contains
 *      neither plaintext nor ciphertext; a locked screen is read-only; a
 *      POST with a wrong CSRF token or without a fresh password confirmation
 *      saves nothing; a good POST saves, audits and the page then still holds
 *      no secret. (A real browser is NOT used.)
 *   K. No secret typed during the run is in any log table, the audit log or
 *      the PHP error log.
 *   L. Lost updates (plan §7.5): two forms opened at the same time, the second
 *      one is refused after the first is saved (nothing of it is written); a
 *      replaced secret is noticed; a missing fingerprint is refused; and a REAL
 *      overlap - a second process is made to wait on the section's row lock
 *      while another connection changes the row - ends in a refusal.
 *   M. The emailed-link limits (verification and forgot-password) can be
 *      saved from the screen (the rows are created) and the code reads them.
 *   N. The test actions write their audit row BEFORE acting (a forced audit
 *      failure means nothing is sent), and the limits are counted from the
 *      audit rows (10 connection checks, 5 test emails per 10 minutes).
 *
 * It is NOT run by tests/run.php (which only picks up tests/test_*.php), as
 * it needs a database. How to run it (Docker example):
 *
 *     docker run -d --rm --name cuercode-it-settings -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
 *       -p 127.0.0.1:33116:3306 mysql:8.0.36
 *     CUERCODE_IT_DB_HOST=127.0.0.1 CUERCODE_IT_DB_PORT=33116 \
 *     CUERCODE_IT_DB_NAME=cuercode_it_p1c CUERCODE_IT_DB_USER=root CUERCODE_IT_DB_PASS= \
 *       php tests/integration/admin_settings_real_mysql.php
 *     docker stop cuercode-it-settings
 *
 * ⚠️ SAFETY: it creates and DROPS a whole database and refuses to run (exit 2)
 *    unless the database name starts with "cuercode_it_". It never reads
 *    _auth_keys/auth_creds.php: the database constants come from the
 *    CUERCODE_IT_DB_* environment variables and the encryption key is random
 *    for each run. No real Microsoft, Google or PayPal call is ever made.
 *
 * Exit codes: 0 every scenario passed | 1 a scenario failed | 2 refused
 * (unsafe database name, missing settings, server unreachable).
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-25 (task 7n Phase 1C, #31)
 * @see         tests/test_admin_settings.php - the database-free unit tests
 * @see         tests/integration/admin_access_real_mysql.php - the pattern copied here
 * @see         web/CueRCode.net/_functions/fn_admin_settings.php
 * @see         .claude/plans/7n-admin-commerce-plan.md §6.6, §6.7, §9 Phase 1C
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

$asSiteRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'CueRCode.net' . DIRECTORY_SEPARATOR;

/**
 * 🔧 One CUERCODE_IT_DB_* value ('' when unset).
 */
function asEnv(string $asKey): string
{
    $asValue = getenv('CUERCODE_IT_DB_' . $asKey);
    if ($asValue === false) {
        return '';
    }
    return $asValue;
}

/**
 * 🗄️ Define the site's database constants for the test server (before any
 *    site code loads, so db_connect.php never looks for auth_creds.php).
 *    The encryption key comes from the environment when a child process is
 *    started by the parent (so both use the same key).
 */
function asDefineDatabase(string $asDbName): void
{
    $asPort = asEnv('PORT');
    if ($asPort === '') {
        $asPort = '3306';
    }
    define('DB_HOST', asEnv('HOST'));
    define('DB_PORT', (int) $asPort);
    define('DB_USER', asEnv('USER'));
    define('DB_PASS', asEnv('PASS'));
    define('DB_NAME', $asDbName);

    $asKey = getenv('CUERCODE_IT_ENCRYPTION_KEY');
    if ($asKey === false || $asKey === '') {
        $asKey = bin2hex(random_bytes(16));
    }
    define('ENCRYPTION_KEY', $asKey);
}

$asArguments = array_slice($argv, 1);

// ==============================================================================
// 👶 Self-invoked CHILD mode: save a section from a fingerprint (the lost-update race)
// php <this file> --save-section <db> <base64 json {userID, email, section, fp, changes}>
// Prints one line "@@RESULT@@<json>" with the answer (no secret is ever passed).
// ==============================================================================
if (isset($asArguments[0]) && $asArguments[0] === '--save-section') {
    $asChildDb = '';
    if (isset($asArguments[1])) {
        $asChildDb = $asArguments[1];
    }
    if (preg_match('/^cuercode_it_[a-z0-9_]+$/', $asChildDb) !== 1 || !isset($asArguments[2])) {
        fwrite(STDERR, 'Refusing: bad arguments.' . PHP_EOL);
        exit(9);
    }
    $asRequest = json_decode((string) base64_decode($asArguments[2], true), true);
    if (!is_array($asRequest)) {
        fwrite(STDERR, 'Refusing: bad request.' . PHP_EOL);
        exit(9);
    }

    asDefineDatabase($asChildDb);
    $_SERVER['REMOTE_ADDR']     = '203.0.113.10';
    $_SERVER['HTTP_USER_AGENT'] = 'integration-test-child';
    require_once $asSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
    require_once $asSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_admin_settings.php';
    restore_error_handler();
    restore_exception_handler();

    $asChildAdmin = ['userID' => (int) $asRequest['userID'], 'email' => (string) $asRequest['email'], 'displayName' => 'IT admin', 'sessionHash' => '', 'hasPassword' => true];
    $asChildResult = adminSettingsSaveSection($asChildAdmin, (string) $asRequest['section'], (string) $asRequest['fp'], $asRequest['changes']);
    echo '@@RESULT@@' . json_encode($asChildResult) . PHP_EOL;
    exit(0);
}

// ==============================================================================
// 👶 Self-invoked CHILD mode: render the REAL /admin/settings/ page
// php <this file> --render-page <db> <base64 json {userID, token, method, post, reauth, csrf}>
// Prints the page, then a line "@@STATE@@<json>" with the flash messages left in the session.
// ==============================================================================
if (isset($asArguments[0]) && $asArguments[0] === '--render-page') {
    $asChildDb = '';
    if (isset($asArguments[1])) {
        $asChildDb = $asArguments[1];
    }
    if (preg_match('/^cuercode_it_[a-z0-9_]+$/', $asChildDb) !== 1 || !isset($asArguments[2])) {
        fwrite(STDERR, 'Refusing: bad arguments.' . PHP_EOL);
        exit(9);
    }
    $asRequest = json_decode((string) base64_decode($asArguments[2], true), true);
    if (!is_array($asRequest)) {
        fwrite(STDERR, 'Refusing: bad request.' . PHP_EOL);
        exit(9);
    }

    asDefineDatabase($asChildDb);

    $_SERVER['HTTP_HOST']      = 'cuercode.test';
    $_SERVER['REQUEST_METHOD'] = $asRequest['method'];
    $_SERVER['REQUEST_URI']    = '/admin/settings/';
    $_SERVER['SCRIPT_NAME']    = '/admin/settings/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $asSiteRoot . 'public_html/admin/settings/index.php';
    $_SERVER['REMOTE_ADDR']    = '203.0.113.9';
    $_SERVER['HTTP_USER_AGENT'] = 'integration-test';
    if (isset($asRequest['origin']) && is_string($asRequest['origin'])) {
        $_SERVER['HTTP_ORIGIN'] = $asRequest['origin'];
    }

    require_once $asSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
    restore_error_handler();
    restore_exception_handler();

    // 🍪 A forged signed-in session: the real tblUserSessions row was made by the parent.
    ini_set('session.save_path', sys_get_temp_dir());
    session_id('cuercode' . bin2hex(random_bytes(8)));
    session_start();
    $_SESSION[CUERCODE_SESSION_TOKEN_KEY] = $asRequest['token'];
    if ($asRequest['csrf'] !== '') {
        $_SESSION[CUERCODE_CSRF_SESSION_KEY]   = $asRequest['csrf'];
        $_SESSION[CUERCODE_CSRF_TIMESTAMP_KEY] = time();
    }
    if ($asRequest['reauth'] === true) {
        $_SESSION['cuercode_admin_reauth'] = [
            'userID'      => (int) $asRequest['userID'],
            'sessionHash' => hash('sha256', (string) $asRequest['token']),
            'confirmedAt' => time(),
            'lastUsedAt'  => time(),
        ];
    }
    $_POST = $asRequest['post'];

    register_shutdown_function(static function (): void {
        $asFlash = [];
        if (isset($_SESSION['cuercode_admin_flash'])) {
            $asFlash = $_SESSION['cuercode_admin_flash'];
        }
        echo PHP_EOL . '@@STATE@@' . json_encode(['flash' => $asFlash, 'status' => http_response_code()]) . PHP_EOL;
    });

    require $asSiteRoot . 'public_html' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'settings' . DIRECTORY_SEPARATOR . 'index.php';
    exit(0);
}

// ==============================================================================
// 🛡️ Step 1: safety - only ever a throwaway database named cuercode_it_*
// ==============================================================================
$asBaseName = asEnv('NAME');

if (preg_match('/^cuercode_it_[a-z0-9_]+$/', $asBaseName) !== 1) {
    fwrite(STDERR, 'Refusing to run: CUERCODE_IT_DB_NAME must start with "cuercode_it_" (this script creates and drops a whole database).' . PHP_EOL);
    exit(2);
}
if (asEnv('HOST') === '' || asEnv('USER') === '') {
    fwrite(STDERR, 'Refusing to run: set CUERCODE_IT_DB_HOST and CUERCODE_IT_DB_USER (and CUERCODE_IT_DB_PORT / CUERCODE_IT_DB_PASS if needed).' . PHP_EOL);
    exit(2);
}
$asDbName = $asBaseName . '_settings';
if (preg_match('/^cuercode_it_[a-z0-9_]+$/', $asDbName) !== 1 || strlen($asDbName) > 64) {
    fwrite(STDERR, 'Refusing: the derived database name is not safe.' . PHP_EOL);
    exit(2);
}
$asPort = asEnv('PORT');
if ($asPort === '') {
    $asPort = '3306';
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// 🍪 A PHP session for the "5 test emails in 10 minutes" limit. It must start
//    BEFORE this script prints anything (PHP refuses session settings once
//    output has been sent), and it uses no cookie.
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
ini_set('session.save_path', sys_get_temp_dir());
session_id('cuercodeittestmail' . bin2hex(random_bytes(4)));
session_start();

// ==============================================================================
// ⏳ Step 2: wait for the server (up to 120 seconds)
// ==============================================================================
$asServer    = null;
$asDeadline  = microtime(true) + 120;
$asLastError = '';
while ($asServer === null && microtime(true) < $asDeadline) {
    try {
        $asServer = new \mysqli(asEnv('HOST'), asEnv('USER'), asEnv('PASS'), '', (int) $asPort);
    } catch (\Throwable $asError) {
        $asLastError = $asError->getMessage();
        usleep(500000);
    }
}
if ($asServer === null) {
    fwrite(STDERR, 'Refusing: the test database server could not be reached (' . $asLastError . ').' . PHP_EOL);
    exit(2);
}
echo 'Server: ' . $asServer->server_info . PHP_EOL;

// ==============================================================================
// 🧱 Step 3: a fresh database with install.sql + seed_data.sql ONLY
// ==============================================================================
$asServer->query('DROP DATABASE IF EXISTS `' . $asDbName . '`');
$asServer->query('CREATE DATABASE `' . $asDbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$asServer->select_db($asDbName);
$asServer->query("SET time_zone = '+00:00'");

foreach (['install.sql', 'seed_data.sql'] as $asSqlFile) {
    $asSql = (string) file_get_contents($asSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . $asSqlFile);
    $asServer->multi_query($asSql);
    do {
        $asStored = $asServer->store_result();
        if ($asStored instanceof \mysqli_result) {
            $asStored->free();
        }
    } while ($asServer->more_results() && $asServer->next_result());
    echo 'Loaded ' . $asSqlFile . '.' . PHP_EOL;
}

// ==============================================================================
// 🧰 Helpers
// ==============================================================================
$asChecks = [];

/**
 * 📝 Record one check and print it.
 */
function asCheck(string $asScenario, bool $asPassed, string $asWhat): void
{
    if (!isset($GLOBALS['asChecks'][$asScenario])) {
        $GLOBALS['asChecks'][$asScenario] = true;
    }
    if (!isset($GLOBALS['asCheckCount'])) {
        $GLOBALS['asCheckCount'] = 0;
    }
    $GLOBALS['asCheckCount']++;
    if (!$asPassed) {
        $GLOBALS['asChecks'][$asScenario] = false;
        echo '    FAIL ';
    } else {
        echo '    ok   ';
    }
    echo $asWhat . PHP_EOL;
}

/**
 * 🔎 One row from the test database (raw connection, prepared).
 */
function asRow(\mysqli $asDb, string $asSql, string $asTypes = '', array $asParams = []): ?array
{
    $asStatement = $asDb->prepare($asSql);
    if ($asTypes !== '') {
        $asStatement->bind_param($asTypes, ...$asParams);
    }
    $asStatement->execute();
    $asFetched = $asStatement->get_result()->fetch_assoc();
    $asStatement->close();
    return $asFetched;
}

/**
 * 🔢 A COUNT(*) from the test database.
 */
function asCount(\mysqli $asDb, string $asSql, string $asTypes = '', array $asParams = []): int
{
    $asFetched = asRow($asDb, $asSql, $asTypes, $asParams);
    if ($asFetched === null) {
        return -1;
    }
    return (int) array_values($asFetched)[0];
}

/**
 * 💾 The raw stored row of one setting (ciphertext stays ciphertext).
 */
function asSetting(\mysqli $asDb, string $asKey): ?array
{
    return asRow($asDb, 'SELECT `settingValue`, `isSensitive`, `isEditable`, `settingType`, `settingGroup`, HEX(`encryptionSalt`) AS `saltHex` FROM `tblSettings` WHERE `settingKey` = ?', 's', [$asKey]);
}

/**
 * 🧾 Audit rows of one action (and optionally one entity key), newest last.
 *
 * @return array<int, array<string, mixed>>
 */
function asAuditRows(\mysqli $asDb, string $asAction, string $asEntityKey = ''): array
{
    // 🧾 Final review N-2: a test's "about to run" row is named ACTION.started,
    //    so the rows of a test action include those.
    $asSql = 'SELECT `action`, `entityType`, `entityKey`, `outcome`, `changesJSON`, `actorUserID`, `reason` FROM `tblAdminAuditLog` WHERE (`action` = ? OR `action` = ?)';
    $asTypes = 'ss';
    $asParams = [$asAction, $asAction . '.started'];
    if ($asEntityKey !== '') {
        $asSql .= ' AND `entityKey` = ?';
        $asTypes .= 's';
        $asParams[] = $asEntityKey;
    }
    $asSql .= ' ORDER BY `auditID`';
    $asStatement = $asDb->prepare($asSql);
    $asStatement->bind_param($asTypes, ...$asParams);
    $asStatement->execute();
    $asRows = $asStatement->get_result()->fetch_all(MYSQLI_ASSOC);
    $asStatement->close();
    return $asRows;
}

/**
 * 🔤 Does text appear in any column of the given tables, or in the PHP error log?
 */
function asFoundInLogs(\mysqli $asDb, string $asNeedle, string $asErrorLogPath): array
{
    $asWhere = [];
    foreach (['tblAdminAuditLog', 'tblActivityLog', 'tblErrorLog'] as $asTable) {
        $asExists = asCount($asDb, 'SELECT COUNT(*) FROM `INFORMATION_SCHEMA`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ?', 's', [$asTable]);
        if ($asExists !== 1) {
            continue;
        }
        $asColumns = [];
        $asColumnResult = $asDb->query('SHOW COLUMNS FROM `' . $asTable . '`');
        while ($asColumnRow = $asColumnResult->fetch_assoc()) {
            $asColumns[] = 'COALESCE(CAST(`' . $asColumnRow['Field'] . '` AS CHAR), \'\')';
        }
        $asFound = asCount($asDb, 'SELECT COUNT(*) FROM `' . $asTable . '` WHERE CONCAT_WS(\' \', ' . implode(', ', $asColumns) . ') LIKE ?', 's', ['%' . $asNeedle . '%']);
        if ($asFound !== 0) {
            $asWhere[] = $asTable;
        }
    }
    if (is_file($asErrorLogPath) && str_contains((string) file_get_contents($asErrorLogPath), $asNeedle)) {
        $asWhere[] = 'PHP error log';
    }
    return $asWhere;
}

/**
 * 🖼️ Run the REAL /admin/settings/ page in a child process.
 *
 * @return array{exit: int, out: string, err: string, html: string, state: array}
 */
function asRenderPage(string $asDb, array $asRequest): array
{
    $asCommand = [PHP_BINARY, __FILE__, '--render-page', $asDb, base64_encode((string) json_encode($asRequest))];
    $asProcess = proc_open($asCommand, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $asPipes, null, ['CUERCODE_IT_ENCRYPTION_KEY' => ENCRYPTION_KEY] + getenv());
    if (!is_resource($asProcess)) {
        return ['exit' => -1, 'out' => '', 'err' => 'proc_open failed', 'html' => '', 'state' => []];
    }
    fclose($asPipes[0]);
    $asOut = (string) stream_get_contents($asPipes[1]);
    fclose($asPipes[1]);
    $asErr = (string) stream_get_contents($asPipes[2]);
    fclose($asPipes[2]);
    $asExit = proc_close($asProcess);

    $asState = [];
    $asHtml = $asOut;
    $asMarker = strrpos($asOut, '@@STATE@@');
    if ($asMarker !== false) {
        $asHtml = substr($asOut, 0, $asMarker);
        $asDecoded = json_decode(trim(substr($asOut, $asMarker + strlen('@@STATE@@'))), true);
        if (is_array($asDecoded)) {
            $asState = $asDecoded;
        }
    }

    return ['exit' => $asExit, 'out' => $asOut, 'err' => $asErr, 'html' => $asHtml, 'state' => $asState];
}

// 👤 The admin (an ordinary active, verified account with role 1) and a second user.
$asAdminPassword = 'Admin-' . bin2hex(random_bytes(8));
$asAdminEmail    = 'admin@it.cuercode.invalid';
$asAdminHash     = password_hash($asAdminPassword, PASSWORD_DEFAULT);
$asInsert = $asServer->prepare("INSERT INTO `tblUsers` (`email`, `emailVerifiedAt`, `passwordHash`, `displayName`, `status`, `roleID`) VALUES (?, '2026-11-01 09:00:00', ?, 'IT admin', 'active', 1)");
$asInsert->bind_param('ss', $asAdminEmail, $asAdminHash);
$asInsert->execute();
$asAdminID = (int) $asServer->insert_id;
$asInsert->close();

// 🍪 A real database session for that admin (used by the page child).
$asSessionToken = bin2hex(random_bytes(32));
$asInsert = $asServer->prepare('INSERT INTO `tblUserSessions` (`sessionID`, `userID`, `expiresAt`) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))');
$asInsert->bind_param('si', $asSessionToken, $asAdminID);
$asInsert->execute();
$asInsert->close();

// 📜 The PHP error log is captured so it can be searched for secrets.
$asErrorLogPath = tempnam(sys_get_temp_dir(), 'cuercode_it_errorlog_');
ini_set('log_errors', '1');
ini_set('error_log', $asErrorLogPath);

// 🚀 The real site code, in this process, against the test database.
asDefineDatabase($asDbName);
require_once $asSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once $asSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_admin_settings.php';
restore_error_handler();
restore_exception_handler();
ini_set('display_errors', 'stderr');
ini_set('error_log', $asErrorLogPath);

$asAdmin = [
    'userID'      => $asAdminID,
    'email'       => $asAdminEmail,
    'displayName' => 'IT admin',
    'sessionHash' => hash('sha256', $asSessionToken),
    'hasPassword' => true,
];

// 🤫 Every secret typed during the run (searched for in every log at the end).
$asSecrets = [
    'client'   => 'IT-Client-Secret-' . bin2hex(random_bytes(10)),
    'client2'  => 'IT-Client-Secret-Two-' . bin2hex(random_bytes(10)),
    'tenant'   => '11111111-2222-3333-4444-' . bin2hex(random_bytes(6)),
    'clientid' => 'aaaaaaaa-bbbb-cccc-dddd-' . bin2hex(random_bytes(6)),
    'paypal'   => 'IT-PayPal-Secret-' . bin2hex(random_bytes(10)),
    'trigger'  => 'IT-Trigger-Secret-' . bin2hex(random_bytes(10)),
    'page'     => 'IT-Page-Secret-' . bin2hex(random_bytes(10)),
    'exception' => 'IT-Exception-Secret-' . bin2hex(random_bytes(10)),
];

/**
 * 💾 Read a setting back through the site's own getConfig() (fresh).
 */
function asConfig(string $asKey): mixed
{
    clearConfigCache($asKey);
    return getConfig($asKey, null);
}

/**
 * 🧾 The fingerprint of a section as the database holds it right now (what a
 *    freshly loaded form would carry).
 */
function asFingerprint(string $asSection): string
{
    $asStates = adminSettingsLoadRows(array_keys(adminSettingsFieldsForSection($asSection)));
    return adminSettingsSectionFingerprint($asSection, (array) $asStates);
}

// ==============================================================================
// A. Before the upgrades: nothing can be saved
// ==============================================================================
echo PHP_EOL . 'A. Before the upgrades' . PHP_EOL;
asCheck('A', adminTableExists('tblAdminAuditLog') === false, 'tblAdminAuditLog does not exist yet');
$asResult = adminSaveSetting(['key' => 'email_queue_retention_days'], '45', $asAdmin);
asCheck('A', $asResult['ok'] === false && $asResult['outcome'] === 'refused', 'adminSaveSetting() refuses while the audit table is missing');
asCheck('A', asSetting($asServer, 'email_queue_retention_days') === null || true, '(the retention row may not exist before upgrade 0002)');
$asUpgrade = adminRunUpgrades($asAdmin);
asCheck('A', $asUpgrade['ok'] === true, 'the waiting upgrades were applied (' . count($asUpgrade['applied']) . ')');
asCheck('A', adminDatabaseReadyForChanges() === true, 'afterwards the database is ready for changes');

// ==============================================================================
// B. Secrets
// ==============================================================================
echo PHP_EOL . 'B. Secrets' . PHP_EOL;
$asResult = adminSaveSetting(['key' => 'graph_client_secret'], $asSecrets['client'], $asAdmin);
asCheck('B', $asResult['ok'] === true && $asResult['outcome'] === 'saved', 'a secret is saved');
$asStored = asSetting($asServer, 'graph_client_secret');
asCheck('B', $asStored !== null && (int) $asStored['isSensitive'] === 1, 'the row is a sensitive row');
asCheck('B', $asStored !== null && !str_contains((string) $asStored['settingValue'], $asSecrets['client']) && strlen((string) $asStored['settingValue']) > 40, 'the stored value is ciphertext (the typed text is not in it)');
asCheck('B', asConfig('graph_client_secret') === $asSecrets['client'], 'getConfig() returns exactly what was typed');
asCheck('B', $asStored !== null && strlen((string) $asStored['saltHex']) === 32, 'a per-record salt was stored (16 bytes)');
$asFirstSalt = (string) $asStored['saltHex'];
$asFirstCipher = (string) $asStored['settingValue'];
$asAudit = asAuditRows($asServer, 'setting.secret_set', 'graph_client_secret');
asCheck('B', count($asAudit) === 1 && $asAudit[0]['outcome'] === 'success' && (int) $asAudit[0]['actorUserID'] === $asAdminID, 'one setting.secret_set audit row, by this admin');
asCheck('B', count($asAudit) === 1 && json_decode((string) $asAudit[0]['changesJSON'], true) === ['graph_client_secret' => ['secret' => 'set']], 'the audit row says only {"secret":"set"}');

// 🔁 Same text again: still a new salt and a different ciphertext.
$asResult = adminSaveSetting(['key' => 'graph_client_secret'], $asSecrets['client'], $asAdmin);
$asStored = asSetting($asServer, 'graph_client_secret');
asCheck('B', $asResult['outcome'] === 'saved' && $asStored['saltHex'] !== $asFirstSalt, 'saving again gives a FRESH encryptionSalt');
asCheck('B', $asStored['settingValue'] !== $asFirstCipher, '... and a different ciphertext for the same text');
asCheck('B', asConfig('graph_client_secret') === $asSecrets['client'], '... which still decrypts to the same text');

$asResult = adminSaveSetting(['key' => 'graph_client_secret'], $asSecrets['client2'], $asAdmin);
asCheck('B', $asResult['outcome'] === 'saved' && asConfig('graph_client_secret') === $asSecrets['client2'], 'a secret is replaced');
$asAudit = asAuditRows($asServer, 'setting.secret_replace', 'graph_client_secret');
asCheck('B', count($asAudit) === 2 && json_decode((string) $asAudit[1]['changesJSON'], true) === ['graph_client_secret' => ['secret' => 'replaced']], 'replacing is audited as {"secret":"replaced"}');

$asResult = adminSaveSetting(['key' => 'graph_client_secret'], '', $asAdmin);
$asStored = asSetting($asServer, 'graph_client_secret');
asCheck('B', $asResult['outcome'] === 'saved' && $asStored['settingValue'] === '' && $asStored['saltHex'] === null, 'removing clears the value and the salt');
asCheck('B', asConfig('graph_client_secret') === '' && adminSettingHasValue('graph_client_secret') === false, 'the site now sees it as not set');
$asAudit = asAuditRows($asServer, 'setting.secret_clear', 'graph_client_secret');
asCheck('B', count($asAudit) === 1 && json_decode((string) $asAudit[0]['changesJSON'], true) === ['graph_client_secret' => ['secret' => 'cleared']], 'removing is audited as {"secret":"cleared"}');
$asResult = adminSaveSetting(['key' => 'graph_client_secret'], '', $asAdmin);
asCheck('B', $asResult['outcome'] === 'unchanged' && count(asAuditRows($asServer, 'setting.secret_clear', 'graph_client_secret')) === 1, 'removing something already empty changes nothing and writes no audit row');

$asResult = adminSaveSetting(['key' => 'graph_client_secret'], "bad\nline", $asAdmin);
asCheck('B', $asResult['ok'] === false && !str_contains(json_encode($asResult), 'bad'), 'a secret with a line break is refused, and the refusal does not repeat it');
$asResult = adminSaveSetting(['key' => 'graph_tenant_id'], 'not a tenant', $asAdmin);
asCheck('B', $asResult['ok'] === false, 'a wrong-looking tenant ID is refused');
$asResult = adminSaveSetting(['key' => 'paypal_client_secret'], $asSecrets['paypal'], $asAdmin);
asCheck('B', $asResult['outcome'] === 'saved' && asConfig('paypal_client_secret') === $asSecrets['paypal'], 'a PayPal secret (a row added by upgrade 0002) is saved the same way');

// ==============================================================================
// C. Ordinary settings
// ==============================================================================
echo PHP_EOL . 'C. Ordinary settings' . PHP_EOL;
$asResult = adminSaveSetting(['key' => 'email_queue_retention_days'], '45', $asAdmin);
asCheck('C', $asResult['outcome'] === 'saved' && asConfig('email_queue_retention_days') === 45, 'a retention period of 45 days is saved (getConfig() reads 45)');
$asAudit = asAuditRows($asServer, 'setting.update', 'email_queue_retention_days');
asCheck('C', count($asAudit) === 1 && json_decode((string) $asAudit[0]['changesJSON'], true) == ['email_queue_retention_days' => ['old' => '30', 'new' => '45']], 'audited as setting.update with old 30 and new 45 (got ' . (count($asAudit) > 0 ? (string) $asAudit[0]['changesJSON'] : 'no row') . ')');

// 🏠 #113 B9: the website address (site_url) is on the screen, is stored in its one plain form, is audited, and the emailed links follow it.
asCheck('C', asConfig('site_url') === 'https://cuercode.net', 'before any change, site_url holds the seeded production address');
asCheck('C', str_starts_with(buildAccountActionUrl('verify-email', 'tok'), 'https://cuercode.net/verify-email?token='), 'before any change, an emailed link points at the seeded address');
$asSiteStates = (array) adminSettingsLoadRows(array_keys(adminSettingsFieldsForSection('website')));
$asSitePost = adminSettingsProcessSection('website', ['f' => ['site_url' => ' HTTPS://Alpha.Example.Net/ ']], $asSiteStates, []);
asCheck('C', $asSitePost['errors'] === [] && count($asSitePost['changes']) === 1, 'the website address box is accepted through the section form');
$asSiteSaved = adminSettingsSaveSection($asAdmin, 'website', asFingerprint('website'), $asSitePost['changes']);
asCheck('C', $asSiteSaved['ok'] === true, 'the website address section saves');
asCheck('C', (string) asSetting($asServer, 'site_url')['settingValue'] === 'https://alpha.example.net', 'it is stored lower-case with no trailing slash');
asConfig('site_url');
asCheck('C', str_starts_with(buildAccountActionUrl('verify-email', 'tok'), 'https://alpha.example.net/verify-email?token='), 'an emailed link now points at the new address');
asCheck('C', ssoRedirectUri('microsoft') === 'https://alpha.example.net/auth/callback/microsoft', 'the Microsoft callback address follows it too');
$asSiteAudit = asAuditRows($asServer, 'setting.update', 'site_url');
asCheck('C', count($asSiteAudit) === 1 && json_decode((string) $asSiteAudit[0]['changesJSON'], true) == ['site_url' => ['old' => 'https://cuercode.net', 'new' => 'https://alpha.example.net']], 'the change is audited with old and new');
$asSiteBad = adminSaveSetting(['key' => 'site_url'], 'https://alpha.example.net/path', $asAdmin);
asCheck('C', $asSiteBad['ok'] === false && asConfig('site_url') === 'https://alpha.example.net', 'an address with a path is refused and the stored one is unchanged');
$asSiteBad = adminSaveSetting(['key' => 'site_url'], 'https://192.168.0.1', $asAdmin);
asCheck('C', $asSiteBad['ok'] === false, 'an IP number is refused');
adminSaveSetting(['key' => 'site_url'], 'https://cuercode.net', $asAdmin);
$asResult = adminSaveSetting(['key' => 'email_queue_retention_days'], '45', $asAdmin);
asCheck('C', $asResult['outcome'] === 'unchanged' && count(asAuditRows($asServer, 'setting.update', 'email_queue_retention_days')) === 1, 'saving the same value again writes no new audit row');
foreach (['0', '3651', 'abc', ''] as $asBad) {
    $asResult = adminSaveSetting(['key' => 'email_queue_retention_days'], $asBad, $asAdmin);
    asCheck('C', $asResult['ok'] === false && asConfig('email_queue_retention_days') === 45, 'a retention period of ' . var_export($asBad, true) . ' is refused and 45 stays');
}
$asResult = adminSaveSetting(['key' => 'email_queue_failed_retention_days'], '3650', $asAdmin);
asCheck('C', $asResult['outcome'] === 'saved' && asConfig('email_queue_failed_retention_days') === 3650, '3650 days is accepted');

$asResult = adminSaveSetting(['key' => 'billing_vat_mode'], 'exclusive', $asAdmin);
asCheck('C', $asResult['outcome'] === 'saved' && asConfig('billing_vat_mode') === 'exclusive', 'VAT mode is saved and read back');
requireFn_pricing($asSiteRoot);
asCheck('C', pricingVatNote((string) asConfig('billing_vat_mode')) === 'Prices exclude VAT', 'the pricing page would now say "Prices exclude VAT"');
adminSaveSetting(['key' => 'billing_vat_mode'], 'none', $asAdmin);
asCheck('C', pricingVatNote((string) asConfig('billing_vat_mode')) === '', 'and with "none" no VAT wording at all');
$asResult = adminSaveSetting(['key' => 'billing_vat_mode'], 'sometimes', $asAdmin);
asCheck('C', $asResult['ok'] === false && asConfig('billing_vat_mode') === 'none', 'an unknown VAT mode is refused');

$asResult = adminSaveSetting(['key' => 'billing_vat_rate_percent'], '17.5', $asAdmin);
asCheck('C', $asResult['outcome'] === 'saved' && asConfig('billing_vat_rate_percent') === 17.5, 'the VAT rate 17.5 is saved as a number (type float)');
$asResult = adminSaveSetting(['key' => 'billing_trials_enabled'], '1', $asAdmin);
asCheck('C', $asResult['outcome'] === 'saved' && asConfig('billing_trials_enabled') === true, 'a switch is stored as 1 and read back as true');
$asResult = adminSaveSetting(['key' => 'billing_trials_enabled'], '0', $asAdmin);
asCheck('C', $asResult['outcome'] === 'saved' && asConfig('billing_trials_enabled') === false, '... and turned off again (0, never an empty text)');
$asRaw = asSetting($asServer, 'billing_trials_enabled');
asCheck('C', $asRaw['settingValue'] === '0', 'false is stored as 0');
$asResult = adminSaveSetting(['key' => 'billing_trial_plan_key'], 'pro', $asAdmin);
asCheck('C', $asResult['outcome'] === 'unchanged' || $asResult['outcome'] === 'saved', 'the trial plan "pro" (an active paid plan) is accepted');
$asResult = adminSaveSetting(['key' => 'billing_trial_plan_key'], 'free', $asAdmin);
asCheck('C', $asResult['ok'] === false, 'the free plan is refused as a trial plan');
$asResult = adminSaveSetting(['key' => 'admin_display_timezone'], 'Europe/Paris', $asAdmin);
asCheck('C', $asResult['outcome'] === 'saved' && adminDisplayTimezone() === 'Europe/Paris', 'the admin time zone is saved and used');
adminSaveSetting(['key' => 'admin_display_timezone'], 'Europe/London', $asAdmin);

// ==============================================================================
// D. Locked keys
// ==============================================================================
echo PHP_EOL . 'D. Locked keys' . PHP_EOL;
$asAuditBefore = asCount($asServer, 'SELECT COUNT(*) FROM `tblAdminAuditLog`');
foreach (['billing_price_points_enabled', 'billing_discounts_enabled', 'billing_payg_enabled', 'dynamic_qr_enabled', 'graph_token_cache', 'sso_microsoft_discovery_cache', 'app_version', 'app_phase', 'api_version', 'encryption_algorithm', 'site_name', 'debug_mode', 'no_such_setting'] as $asLocked) {
    $asBefore = asSetting($asServer, $asLocked);
    $asResult = adminSaveSetting(['key' => $asLocked, 'kind' => 'bool', 'isEditable' => true], '1', $asAdmin);
    $asAfter = asSetting($asServer, $asLocked);
    asCheck('D', $asResult['ok'] === false && $asResult['outcome'] === 'refused' && $asBefore === $asAfter, $asLocked . ': refused and unchanged');
}
asCheck('D', asCount($asServer, 'SELECT COUNT(*) FROM `tblAdminAuditLog`') === $asAuditBefore, 'refused saves wrote no audit rows');

// 🧪 A hand-made request for the prices section that also tries the locked switches.
$asCurrent = adminSettingsLoadRows(array_keys(adminSettingsFieldsForSection('prices')));
$asHostile = adminSettingsProcessSection('prices', ['f' => [
    'billing_payg_enabled' => '1', 'billing_price_points_enabled' => '1', 'billing_discounts_enabled' => '1',
    'dynamic_qr_enabled' => '1', 'billing_vat_mode' => 'inclusive',
]], $asCurrent);
$asSaved = adminSettingsSaveSection($asAdmin, 'prices', asFingerprint('prices'), $asHostile['changes']);
asCheck('D', $asSaved['ok'] === true && $asSaved['saved'] === 1, 'a hand-made prices request saves ONLY the one real change');
asCheck('D', asConfig('billing_payg_enabled') === false && asConfig('billing_price_points_enabled') === false && asConfig('billing_discounts_enabled') === false && asConfig('dynamic_qr_enabled') === false, 'all four charging switches are still off');

// 🔧 A row marked not editable.
$asServer->query("UPDATE `tblSettings` SET `isEditable` = 0 WHERE `settingKey` = 'email_from_name'");
$asResult = adminSaveSetting(['key' => 'email_from_name'], 'Changed', $asAdmin);
asCheck('D', $asResult['ok'] === false && asSetting($asServer, 'email_from_name')['settingValue'] === '', 'a row marked not editable is refused');
$asServer->query("UPDATE `tblSettings` SET `isEditable` = 1 WHERE `settingKey` = 'email_from_name'");

// 🔧 A secret whose row says "not sensitive" must never be written in plain text.
$asServer->query("UPDATE `tblSettings` SET `isSensitive` = 0 WHERE `settingKey` = 'hcaptcha_secret_key'");
$asResult = adminSaveSetting(['key' => 'hcaptcha_secret_key'], 'would-be-plain-text', $asAdmin);
asCheck('D', $asResult['ok'] === false && asSetting($asServer, 'hcaptcha_secret_key')['settingValue'] === '', 'a secret whose row is not flagged sensitive is refused (never stored in plain text)');
$asServer->query("UPDATE `tblSettings` SET `isSensitive` = 1 WHERE `settingKey` = 'hcaptcha_secret_key'");

// ==============================================================================
// E. One transaction
// ==============================================================================
echo PHP_EOL . 'E. One transaction for a whole section' . PHP_EOL;
$asCurrent = adminSettingsLoadRows(array_keys(adminSettingsFieldsForSection('email')));
$asProcessed = adminSettingsProcessSection('email', ['f' => [
    'email_from_name' => 'Section Test Name',
    'graph_sender_mailbox' => 'sender@example.com',
]], $asCurrent);
asCheck('E', count($asProcessed['changes']) === 2 && $asProcessed['errors'] === [], 'set-up: a section with two changes');

$asForced = [
    'the audit row (second change)' => ['tblAdminAuditLog', 'INSERT', "NEW.`entityKey` = 'graph_sender_mailbox'"],
    'the setting update (second change)' => ['tblSettings', 'UPDATE', "NEW.`settingKey` = 'graph_sender_mailbox'"],
];
foreach ($asForced as $asLabel => $asTrigger) {
    $asServer->query('DROP TRIGGER IF EXISTS `asForcedFailure`');
    $asServer->query('CREATE TRIGGER `asForcedFailure` BEFORE ' . $asTrigger[1] . ' ON `' . $asTrigger[0] . '` FOR EACH ROW BEGIN IF ' . $asTrigger[2] . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure for the integration test'; END IF; END");
    $asAuditBefore = asCount($asServer, 'SELECT COUNT(*) FROM `tblAdminAuditLog`');
    $asSaved = adminSettingsSaveSection($asAdmin, 'email', asFingerprint('email'), $asProcessed['changes']);
    $asServer->query('DROP TRIGGER IF EXISTS `asForcedFailure`');
    asCheck('E', $asSaved['ok'] === false, 'failure at ' . $asLabel . ': the save reports "not saved"');
    asCheck('E', asSetting($asServer, 'email_from_name')['settingValue'] === '' && asSetting($asServer, 'graph_sender_mailbox')['settingValue'] === '', 'failure at ' . $asLabel . ': BOTH settings are exactly as before (the first was rolled back)');
    asCheck('E', asCount($asServer, 'SELECT COUNT(*) FROM `tblAdminAuditLog`') === $asAuditBefore, 'failure at ' . $asLabel . ': no audit row was kept');
    asCheck('E', asConfig('email_from_name') === '', 'failure at ' . $asLabel . ': getConfig() does not show the rolled-back value');
}
$asSaved = adminSettingsSaveSection($asAdmin, 'email', asFingerprint('email'), $asProcessed['changes']);
asCheck('E', $asSaved['ok'] === true && $asSaved['saved'] === 2 && asConfig('email_from_name') === 'Section Test Name', 'without the fault both changes are saved');
asCheck('E', count(asAuditRows($asServer, 'setting.update', 'email_from_name')) === 1 && count(asAuditRows($asServer, 'setting.update', 'graph_sender_mailbox')) === 1, '... with one audit row each');

// ==============================================================================
// F. afterSave: caches
// ==============================================================================
echo PHP_EOL . 'F. Caches are cleared after a change' . PHP_EOL;
$asCacheOptions = ['group' => 'email', 'type' => 'json', 'isSensitive' => true, 'isEditable' => false, 'description' => 'it'];
setConfig('graph_token_cache', '{"access_token":"cached-token-value","expires_at":9999999999}', $asCacheOptions);
asCheck('F', asSetting($asServer, 'graph_token_cache')['settingValue'] !== '', 'set-up: a token is cached');
adminSaveSetting(['key' => 'graph_tenant_id'], $asSecrets['tenant'], $asAdmin);
asCheck('F', asSetting($asServer, 'graph_token_cache')['settingValue'] === '', 'saving the tenant ID cleared graph_token_cache');
setConfig('graph_token_cache', '{"access_token":"cached-token-value","expires_at":9999999999}', $asCacheOptions);
adminSaveSetting(['key' => 'graph_client_id'], $asSecrets['clientid'], $asAdmin);
asCheck('F', asSetting($asServer, 'graph_token_cache')['settingValue'] === '', 'saving the app ID cleared graph_token_cache');
setConfig('graph_token_cache', '{"access_token":"cached-token-value","expires_at":9999999999}', $asCacheOptions);
adminSaveSetting(['key' => 'email_queue_autoprocess'], '1', $asAdmin);
asCheck('F', asSetting($asServer, 'graph_token_cache')['settingValue'] !== '', 'a change that is not a Graph detail leaves the token cache alone');
adminSaveSetting(['key' => 'email_queue_autoprocess'], '0', $asAdmin);

// 🔒 Independent review (Codex C4 finding 1, 2026-11-25): clearing the cache
//    happens INSIDE the save, and a save whose cache could not be cleared is
//    refused with nothing changed (before, it reported success and the old
//    Microsoft token stayed in use). Both ways in: one setting, and a section.
setConfig('graph_token_cache', '{"access_token":"cached-token-value","expires_at":9999999999}', $asCacheOptions);
$asServer->query('DROP TRIGGER IF EXISTS `asForcedFailure`');
$asServer->query("CREATE TRIGGER `asForcedFailure` BEFORE UPDATE ON `tblSettings` FOR EACH ROW BEGIN IF NEW.`settingKey` = 'graph_token_cache' AND NEW.`settingValue` = '' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure for the integration test'; END IF; END");
$asClientIdBefore = asSetting($asServer, 'graph_client_id')['settingValue'];
$asAuditBeforeC4  = asCount($asServer, 'SELECT COUNT(*) FROM `tblAdminAuditLog`');
$asResult = adminSaveSetting(['key' => 'graph_client_id'], '11111111-2222-3333-4444-555555555555', $asAdmin);
asCheck('F', $asResult['ok'] === false, 'C4-1: a Graph detail whose token cache cannot be cleared is NOT saved (' . $asResult['outcome'] . ')');
asCheck('F', asSetting($asServer, 'graph_client_id')['settingValue'] === $asClientIdBefore && asCount($asServer, 'SELECT COUNT(*) FROM `tblAdminAuditLog`') === $asAuditBeforeC4, 'C4-1: ...the setting and the audit log are exactly as before');
asCheck('F', asSetting($asServer, 'graph_token_cache')['settingValue'] !== '', 'C4-1: ...and the old token is still there, so nothing claims it was replaced');
$asProcessedC4 = adminSettingsProcessSection('email', ['f' => ['graph_sender_mailbox' => 'c4-sender@example.com']], adminSettingsLoadRows(array_keys(adminSettingsFieldsForSection('email')), false));
$asSenderBefore = asSetting($asServer, 'graph_sender_mailbox')['settingValue'];
$asSavedC4 = adminSettingsSaveSection($asAdmin, 'email', asFingerprint('email'), $asProcessedC4['changes']);
asCheck('F', $asSavedC4['ok'] === false && asSetting($asServer, 'graph_sender_mailbox')['settingValue'] === $asSenderBefore, 'C4-1: a SECTION save whose cache cannot be cleared is refused too, with nothing changed');
$asServer->query('DROP TRIGGER IF EXISTS `asForcedFailure`');
$asResult = adminSaveSetting(['key' => 'graph_client_id'], $asSecrets['clientid'], $asAdmin);
asCheck('F', asSetting($asServer, 'graph_token_cache')['settingValue'] === '', 'C4-1: without the fault the save clears the cache as before');

$asDiscoveryOptions = ['group' => 'sso', 'type' => 'json', 'isSensitive' => false, 'isEditable' => false, 'description' => 'it'];
foreach (['microsoft', 'google'] as $asProvider) {
    setConfig('sso_' . $asProvider . '_discovery_cache', '{"fetched_at":1,"data":{"issuer":"x"}}', $asDiscoveryOptions);
    setConfig('sso_' . $asProvider . '_jwks_cache', '{"fetched_at":1,"data":{"keys":[]}}', $asDiscoveryOptions);
}
$asResult = adminSaveSetting(['key' => 'sso_microsoft_discovery_url'], 'https://login.example.com/tenant/v2.0/.well-known/openid-configuration', $asAdmin);
asCheck('F', $asResult['outcome'] === 'saved', 'a new Microsoft discovery address is saved');
asCheck('F', asSetting($asServer, 'sso_microsoft_discovery_cache')['settingValue'] === '' && asSetting($asServer, 'sso_microsoft_jwks_cache')['settingValue'] === '', 'the Microsoft discovery and JWKS caches were cleared');
asCheck('F', asSetting($asServer, 'sso_google_discovery_cache')['settingValue'] !== '' && asSetting($asServer, 'sso_google_jwks_cache')['settingValue'] !== '', 'the Google caches were NOT touched');
$asResult = adminSaveSetting(['key' => 'sso_google_discovery_url'], 'http://insecure.example.com/x', $asAdmin);
asCheck('F', $asResult['ok'] === false && asSetting($asServer, 'sso_google_discovery_cache')['settingValue'] !== '', 'an http discovery address is refused and nothing is cleared');

// ==============================================================================
// G. The two test actions (stand-ins: there are no real Microsoft credentials)
// ==============================================================================
echo PHP_EOL . 'G. Test actions (with stand-ins)' . PHP_EOL;
$asSeenCache = null;
$asGoodFetcher = static function () use ($asServer, &$asSeenCache): string {
    $asRow = asSetting($asServer, 'graph_token_cache');
    $asSeenCache = $asRow['settingValue'];
    return 'FAKE-ACCESS-TOKEN-' . bin2hex(random_bytes(6));
};
setConfig('graph_token_cache', '{"access_token":"old-cached-token","expires_at":9999999999}', $asCacheOptions);
$asResult = adminSettingsTestGraph($asAdmin, $asGoodFetcher);
asCheck('G', $asResult['ok'] === true && str_contains($asResult['message'], 'Connected'), 'a working connection says Connected');
asCheck('G', $asSeenCache === '', 'the saved token was cleared BEFORE asking for a new one');
asCheck('G', !str_contains(json_encode($asResult), 'FAKE-ACCESS-TOKEN'), 'the token is not in the answer');
$asAudit = asAuditRows($asServer, 'setting.test_graph');
asCheck('G', count($asAudit) === 2 && $asAudit[0]['action'] === 'setting.test_graph.started' && $asAudit[1]['action'] === 'setting.test_graph' && $asAudit[0]['outcome'] === 'failed' && $asAudit[0]['reason'] === CUERCODE_ADMIN_TEST_ATTEMPT_REASON && $asAudit[1]['outcome'] === 'success' && $asAudit[1]['reason'] === CUERCODE_ADMIN_TEST_RESULT_REASON && $asAudit[1]['changesJSON'] === null, 'audited: a setting.test_graph.started row first (N-2), then a setting.test_graph success row, no details');

$asBadFetcher = static function (): ?string {
    return null;
};
$asResult = adminSettingsTestGraph($asAdmin, $asBadFetcher);
asCheck('G', $asResult['ok'] === false && str_contains($asResult['message'], 'Could not connect'), 'a failing connection says Could not connect');
$asThrowingFetcher = static function (): ?string {
    throw new \RuntimeException('Microsoft said: AADSTS7000215 invalid client secret provided ' . 'SECRET-IN-ERROR-BODY');
};
$asResult = adminSettingsTestGraph($asAdmin, $asThrowingFetcher);
asCheck('G', $asResult['ok'] === false && !str_contains(json_encode($asResult), 'AADSTS') && !str_contains(json_encode($asResult), 'SECRET-IN-ERROR-BODY'), 'an error from Microsoft is never shown');
asCheck('G', asCount($asServer, "SELECT COUNT(*) FROM `tblErrorLog` WHERE CONCAT_WS(' ', COALESCE(`errorTitle`, ''), COALESCE(`errorDetail`, '')) LIKE '%SECRET-IN-ERROR-BODY%'") === 0, '... and not in the error log either');
asCheck('G', count(asAuditRows($asServer, 'setting.test_graph')) === 6, 'every check was audited before and after (3 checks, 6 rows)');

// With nothing filled in, the REAL fetcher returns null without any network call.
foreach (['graph_tenant_id', 'graph_client_id'] as $asClearKey) {
    adminSaveSetting(['key' => $asClearKey], '', $asAdmin);
}
$asResult = adminSettingsTestGraph($asAdmin);
asCheck('G', $asResult['ok'] === false && str_contains($asResult['message'], 'not all filled in'), 'with the details missing, the real check says so (and makes no network call)');
asCheck('G', str_contains($asResult['message'], 'activity log') === false && str_contains($asResult['message'], 'server error log') === false, 'the "not filled in" answer does not point at any log (nothing was tried)');

// ✉️ Test email.
asCheck('G', session_status() === PHP_SESSION_ACTIVE, 'set-up: a PHP session is active for the 5-per-10-minutes limit');
$asSentTo = [];
$asSender = static function (string $asTo, string $asSubject, string $asBody, ?string $asName) use (&$asSentTo): array {
    $asSentTo[] = ['to' => $asTo, 'subject' => $asSubject, 'body' => $asBody];
    return [true, null];
};
setConfig('email_enabled', '0', ['group' => 'email', 'type' => 'boolean']);
$asResult = adminSettingsSendTestEmail($asAdmin, $asSender, 1800000000);
asCheck('G', $asResult['ok'] === false && str_contains($asResult['message'], 'switched off') && $asSentTo === [], 'with email off it says so and sends nothing');
setConfig('email_enabled', '1', ['group' => 'email', 'type' => 'boolean']);
$asResult = adminSettingsSendTestEmail($asAdmin, $asSender, 1800000000);
asCheck('G', $asResult['ok'] === true && count($asSentTo) === 1 && $asSentTo[0]['to'] === $asAdminEmail, 'with email on it sends exactly one message, to the admin\'s own address');
asCheck('G', !str_contains($asResult['message'], $asAdminEmail) && str_contains($asResult['message'], 'a***n@it.cuercode.invalid'), 'the answer shows the address only partly hidden');
for ($asI = 1; $asI < 5; $asI++) {
    adminSettingsSendTestEmail($asAdmin, $asSender, 1800000000 + $asI);
}
asCheck('G', count($asSentTo) === 5, 'five test emails are allowed');
$asResult = adminSettingsSendTestEmail($asAdmin, $asSender, 1800000010);
asCheck('G', $asResult['ok'] === false && count($asSentTo) === 5 && str_contains($asResult['message'], 'Too many'), 'the sixth within 10 minutes is refused');
asCheck('G', count(asAuditRows($asServer, 'setting.test_email')) === 10, 'N-1: refused by THIS browser\'s own count: nothing more is written (5 attempts, 5 results)');
// 🍪 Signing in again (an empty session list) does not reset the limit: it is counted from the admin's own audit rows.
$_SESSION[CUERCODE_ADMIN_TEST_EMAIL_SESSION_KEY] = [];
$asResult = adminSettingsSendTestEmail($asAdmin, $asSender, 1800000010);
asCheck('G', $asResult['ok'] === false && count($asSentTo) === 5 && str_contains($asResult['message'], 'Too many'), 'with a brand-new session the sixth is STILL refused (counted from the audit rows)');
$asRefusedRows = array_values(array_filter(asAuditRows($asServer, 'setting.test_email'), static function (array $asRow): bool {
    return $asRow['reason'] === CUERCODE_ADMIN_TEST_LIMITED_REASON;
}));
asCheck('G', count(asAuditRows($asServer, 'setting.test_email')) === 12 && count($asRefusedRows) === 1 && $asRefusedRows[0]['outcome'] === 'refused' && $asRefusedRows[0]['action'] === 'setting.test_email', 'N-1: the press refused by the audit-log count is recorded (a started row, then a "refused" row)');
// ⏩ The audit rows are made 11 minutes older (the test cannot wait; the clock stand-in only moves PHP's time).
$asServer->query("UPDATE `tblAdminAuditLog` SET `createdAt` = DATE_SUB(`createdAt`, INTERVAL 11 MINUTE) WHERE `action` IN ('setting.test_email', 'setting.test_email.started')");
$asResult = adminSettingsSendTestEmail($asAdmin, $asSender, 1800000000 + 601);
asCheck('G', $asResult['ok'] === true && count($asSentTo) === 6, 'after 10 minutes it is allowed again');
$asFailingSender = static function (): array {
    return [false, 'Graph returned HTTP 401: {"error":"SECRET-IN-SEND-ERROR"}'];
};
$asResult = adminSettingsSendTestEmail($asAdmin, $asFailingSender, 1800005000);
asCheck('G', $asResult['ok'] === false && !str_contains(json_encode($asResult), 'SECRET-IN-SEND-ERROR'), 'a send failure never shows the error text');
$asAudit = asAuditRows($asServer, 'setting.test_email');
$asEmailAttempts = array_values(array_filter($asAudit, static function (array $asRow): bool {
    return $asRow['reason'] === CUERCODE_ADMIN_TEST_ATTEMPT_REASON;
}));
$asEmailResults = array_values(array_filter($asAudit, static function (array $asRow): bool {
    return $asRow['reason'] === CUERCODE_ADMIN_TEST_RESULT_REASON;
}));
asCheck('G', count($asAudit) === 16 && count($asEmailAttempts) === 8 && count($asEmailResults) === 7 && $asEmailResults[0]['outcome'] === 'success' && $asEmailResults[6]['outcome'] === 'failed', 'test emails are audited before and after (8 attempts - one refused by the limit - 7 results; success and failed)');
asCheck('G', str_contains($asResult['message'], 'activity log') && !str_contains($asResult['message'], 'server error log'), 'a failed test email points at the activity log (where the detail really is)');
setConfig('email_enabled', '0', ['group' => 'email', 'type' => 'boolean']);

// ==============================================================================
// N. The test actions: audit row first, limits from the audit rows
// ==============================================================================
echo PHP_EOL . 'N. Test actions: audit first, limits from the audit log' . PHP_EOL;
$asFetcherCalls = 0;
$asCountingFetcher = static function () use (&$asFetcherCalls): string {
    $asFetcherCalls++;
    return 'FAKE-ACCESS-TOKEN-' . bin2hex(random_bytes(4));
};
$asAgeRows = static function (string $asAction) use ($asServer): void {
    $asServer->query("UPDATE `tblAdminAuditLog` SET `createdAt` = DATE_SUB(`createdAt`, INTERVAL 11 MINUTE) WHERE `action` IN ('" . $asAction . "', '" . $asAction . ".started')");
};
$asAgeRows('setting.test_graph');
$asRows = count(asAuditRows($asServer, 'setting.test_graph'));
for ($asI = 1; $asI <= 10; $asI++) {
    $asAnswer = adminSettingsTestGraph($asAdmin, $asCountingFetcher);
    if ($asAnswer['ok'] !== true) {
        break;
    }
}
asCheck('N', $asFetcherCalls === 10 && count(asAuditRows($asServer, 'setting.test_graph')) === $asRows + 20, 'ten connection checks in 10 minutes are allowed (20 audit rows: before and after)');
$asAnswer = adminSettingsTestGraph($asAdmin, $asCountingFetcher);
asCheck('N', $asAnswer['ok'] === false && str_contains($asAnswer['message'], 'Too many connection checks') && $asFetcherCalls === 10 && count(asAuditRows($asServer, 'setting.test_graph')) === $asRows + 22, 'the 11th is refused: no check was run; the refused press is recorded (N-1: a started row, then a "refused" row)');
$asAnswer = adminSettingsTestGraph($asAdmin, $asCountingFetcher);
asCheck('N', $asAnswer['ok'] === false && $asFetcherCalls === 10, 'N-1: pressing again while refused is refused again (every press counts)');
$asAgeRows('setting.test_graph');
$asAnswer = adminSettingsTestGraph($asAdmin, $asCountingFetcher);
asCheck('N', $asAnswer['ok'] === true && $asFetcherCalls === 11, 'ten minutes later it is allowed again');
$asAgeRows('setting.test_graph');

// 🧾 If the audit row cannot be written first, the check is NOT run.
$asServer->query('DROP TRIGGER IF EXISTS `asForcedFailure`');
$asServer->query('CREATE TRIGGER `asForcedFailure` BEFORE INSERT ON `tblAdminAuditLog` FOR EACH ROW BEGIN IF NEW.`action` LIKE \'setting.test_graph%\' THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'forced failure for the integration test\'; END IF; END');
$asBeforeCalls = $asFetcherCalls;
$asAnswer = adminSettingsTestGraph($asAdmin, $asCountingFetcher);
$asServer->query('DROP TRIGGER IF EXISTS `asForcedFailure`');
asCheck('N', $asAnswer['ok'] === false && $asFetcherCalls === $asBeforeCalls && str_contains($asAnswer['message'], 'could not be recorded'), 'Check connection: an audit row that cannot be written means the check is not run, and it says so');

// 🧾 If only the RESULT row fails, the check ran and the answer says the record is missing.
$asServer->query('CREATE TRIGGER `asForcedFailure` BEFORE INSERT ON `tblAdminAuditLog` FOR EACH ROW BEGIN IF NEW.`action` = \'setting.test_graph\' AND NEW.`reason` = \'' . CUERCODE_ADMIN_TEST_RESULT_REASON . '\' THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'forced failure for the integration test\'; END IF; END');
$asAnswer = adminSettingsTestGraph($asAdmin, $asCountingFetcher);
$asServer->query('DROP TRIGGER IF EXISTS `asForcedFailure`');
asCheck('N', $asAnswer['ok'] === true && $asFetcherCalls === $asBeforeCalls + 1 && str_contains($asAnswer['message'], 'could not be written to the admin change log'), 'a result row that cannot be written: the check still ran and the answer says the result is not in the change log');
$asAgeRows('setting.test_graph');

// ✉️ The same for the test email.
setConfig('email_enabled', '1', ['group' => 'email', 'type' => 'boolean']);
$_SESSION[CUERCODE_ADMIN_TEST_EMAIL_SESSION_KEY] = [];
$asAgeRows('setting.test_email');
$asSentBefore = count($asSentTo);
$asServer->query('CREATE TRIGGER `asForcedFailure` BEFORE INSERT ON `tblAdminAuditLog` FOR EACH ROW BEGIN IF NEW.`action` LIKE \'setting.test_email%\' THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'forced failure for the integration test\'; END IF; END');
$asAnswer = adminSettingsSendTestEmail($asAdmin, $asSender, 1800009000);
$asServer->query('DROP TRIGGER IF EXISTS `asForcedFailure`');
asCheck('N', $asAnswer['ok'] === false && count($asSentTo) === $asSentBefore && str_contains($asAnswer['message'], 'so no email was sent'), 'Send test email: an audit row that cannot be written means NO email is sent, and it says so');
$asSessionTimes = [];
if (isset($_SESSION[CUERCODE_ADMIN_TEST_EMAIL_SESSION_KEY]) && is_array($_SESSION[CUERCODE_ADMIN_TEST_EMAIL_SESSION_KEY])) {
    $asSessionTimes = $_SESSION[CUERCODE_ADMIN_TEST_EMAIL_SESSION_KEY];
}
asCheck('N', $asSessionTimes === [], 'and the failed attempt is not counted against the session limit');
setConfig('email_enabled', '0', ['group' => 'email', 'type' => 'boolean']);

// ==============================================================================
// H. Trusted forwarding servers (the row does not exist until saved)
// ==============================================================================
echo PHP_EOL . 'H. Trusted forwarding servers' . PHP_EOL;
asCheck('H', asSetting($asServer, 'trusted_proxy_ips') === null, 'set-up: the trusted_proxy_ips row does not exist');
$asResult = adminSaveSetting(['key' => 'trusted_proxy_ips'], "10.0.0.0/8\n2001:db8::/32, 192.0.2.7", $asAdmin);
asCheck('H', $asResult['outcome'] === 'saved', 'saved although the row did not exist');
$asRaw = asSetting($asServer, 'trusted_proxy_ips');
asCheck('H', $asRaw !== null && $asRaw['settingValue'] === '10.0.0.0/8, 2001:db8::/32, 192.0.2.7' && $asRaw['settingGroup'] === 'security' && (int) $asRaw['isSensitive'] === 0, 'the row was created in group security, not sensitive, tidied');
clientIpTrustedProxySetting(true);
$asTrusted = clientIpTrustedProxySetting();
asCheck('H', count($asTrusted['ranges']) === 3 && $asTrusted['invalid'] === [], 'clientIpTrustedProxySetting() reads the three ranges');
$asResult = adminSaveSetting(['key' => 'trusted_proxy_ips'], '10.0.0.0/8, nonsense', $asAdmin);
asCheck('H', $asResult['ok'] === false && asSetting($asServer, 'trusted_proxy_ips')['settingValue'] === '10.0.0.0/8, 2001:db8::/32, 192.0.2.7', 'a bad entry is refused and the saved list stays');
$asResult = adminSaveSetting(['key' => 'trusted_proxy_ips'], '10.0.0.0/8, 0.0.0.0/0', $asAdmin);
asCheck('H', $asResult['ok'] === false && asSetting($asServer, 'trusted_proxy_ips')['settingValue'] === '10.0.0.0/8, 2001:db8::/32, 192.0.2.7', 'decision 0015: a /0 range is refused and the saved list stays');

// ==============================================================================
// M. The emailed-link limits (rows created on first save)
// ==============================================================================
echo PHP_EOL . 'M. Emailed-link limits' . PHP_EOL;
foreach (['verification_resend', 'password_reset_request'] as $asPrefix) {
    asCheck('M', asSetting($asServer, $asPrefix . '_daily_cap') === null, 'set-up: ' . $asPrefix . '_daily_cap has no row yet');
}
$asResult = adminSaveSetting(['key' => 'password_reset_request_daily_cap'], '7', $asAdmin);
$asRaw = asSetting($asServer, 'password_reset_request_daily_cap');
asCheck('M', $asResult['outcome'] === 'saved' && $asRaw !== null && $asRaw['settingGroup'] === 'security' && $asRaw['settingType'] === 'integer' && (int) $asRaw['isSensitive'] === 0 && (int) $asRaw['isEditable'] === 1, 'the row is created (group security, integer, not sensitive) on first save');
clearConfigCache('password_reset_request_daily_cap');
asCheck('M', authLinkRequestLimitSettings('password_reset_request')['dailyCap'] === 7, 'the code now reads 7 for the forgot-password daily cap');
asCheck('M', authLinkRequestLimitSettings('verification_resend')['dailyCap'] === 5, 'the verification link limit is untouched (still the default 5)');
$asResult = adminSaveSetting(['key' => 'verification_resend_min_gap_seconds'], '120', $asAdmin);
clearConfigCache('verification_resend_min_gap_seconds');
asCheck('M', $asResult['outcome'] === 'saved' && authLinkRequestLimitSettings('verification_resend')['minGapSeconds'] === 120, 'the verification-link gap is saved and read by the code');
foreach (['password_reset_request_daily_cap' => ['0', '51', '07', 'x'], 'verification_resend_min_gap_seconds' => ['9', '86401'], 'verification_resend_ip_per_hour' => ['0', '1001']] as $asLimitKey => $asBadValues) {
    foreach ($asBadValues as $asBadValue) {
        $asResult = adminSaveSetting(['key' => $asLimitKey], $asBadValue, $asAdmin);
        asCheck('M', $asResult['ok'] === false, $asLimitKey . ' refuses ' . var_export($asBadValue, true));
    }
}
asCheck('M', authLinkRequestLimitSettings('password_reset_request')['dailyCap'] === 7, '... and the saved 7 stays');
$asAudit = asAuditRows($asServer, 'setting.update', 'password_reset_request_daily_cap');
asCheck('M', count($asAudit) === 1, 'the first save is audited (setting.update)');

// ==============================================================================
// L. Lost updates: a form opened earlier cannot undo a newer change
// ==============================================================================
echo PHP_EOL . 'L. Lost updates' . PHP_EOL;
adminSaveSetting(['key' => 'billing_trial_days'], '14', $asAdmin);
$asTrialKeys = array_keys(adminSettingsFieldsForSection('trials'));
$asFpA = asFingerprint('trials');
$asFpB = asFingerprint('trials');
asCheck('L', $asFpA === $asFpB && strlen($asFpA) === 64, 'two forms opened together carry the same fingerprint');
$asProcess = static function (array $asFields) use ($asTrialKeys): array {
    return adminSettingsProcessSection('trials', ['f' => $asFields], (array) adminSettingsLoadRows($asTrialKeys), ['planKeys' => []]);
};

// Tab A: keep-days 14 -> 40.
$asTabA = $asProcess(['billing_trial_days' => '40']);
asCheck('L', count($asTabA['changes']) === 1, 'tab A: one change (14 to 40)');
$asSavedA = adminSettingsSaveSection($asAdmin, 'trials', $asFpA, $asTabA['changes']);
asCheck('L', $asSavedA['ok'] === true && $asSavedA['saved'] === 1 && $asSavedA['stale'] === false && asConfig('billing_trial_days') === 40, 'tab A saves (40)');

// Tab B (opened earlier) posts the old 14 and turns the card switch on. Against the NEW database
// that looks like a change from 40 back to 14: the silent undo.
$asTabB = $asProcess(['billing_trial_days' => '14', 'billing_trial_requires_card' => '1']);
asCheck('L', count($asTabB['changes']) === 2, 'tab B on its own looks like two changes, one of them undoing tab A');
$asAuditBefore = asCount($asServer, 'SELECT COUNT(*) FROM `tblAdminAuditLog`');
$asSavedB = adminSettingsSaveSection($asAdmin, 'trials', $asFpB, $asTabB['changes']);
asCheck('L', $asSavedB['ok'] === false && $asSavedB['stale'] === true && $asSavedB['saved'] === 0, 'tab B is refused as out of date');
asCheck('L', $asSavedB['message'] === 'Someone changed these settings while you were editing. Nothing was saved. Reload the page and try again.', 'tab B gets the agreed plain-English message');
asCheck('L', asConfig('billing_trial_days') === 40 && asConfig('billing_trial_requires_card') === false, 'nothing of tab B was written (still 40 days, card switch untouched)');
asCheck('L', asCount($asServer, 'SELECT COUNT(*) FROM `tblAdminAuditLog`') === $asAuditBefore, 'and no audit row was written for it');
// Reloaded: the new fingerprint works.
$asSavedB2 = adminSettingsSaveSection($asAdmin, 'trials', asFingerprint('trials'), $asProcess(['billing_trial_days' => '40', 'billing_trial_requires_card' => '1'])['changes']);
asCheck('L', $asSavedB2['ok'] === true && $asSavedB2['saved'] === 1 && asConfig('billing_trial_requires_card') === true, 'after reloading, the same admin can save (fresh fingerprint)');
adminSaveSetting(['key' => 'billing_trial_requires_card'], '0', $asAdmin);
adminSaveSetting(['key' => 'billing_trial_days'], '14', $asAdmin);

// A missing or made-up fingerprint is refused too.
$asNoFp = adminSettingsSaveSection($asAdmin, 'trials', '', $asProcess(['billing_trial_days' => '30'])['changes']);
asCheck('L', $asNoFp['ok'] === false && $asNoFp['stale'] === true && asConfig('billing_trial_days') === 14, 'a form with no fingerprint is refused and nothing is saved');
$asMadeUp = adminSettingsSaveSection($asAdmin, 'trials', str_repeat('0', 64), $asProcess(['billing_trial_days' => '30'])['changes']);
asCheck('L', $asMadeUp['ok'] === false && $asMadeUp['stale'] === true, 'a made-up fingerprint is refused');
$asOtherSection = adminSettingsSaveSection($asAdmin, 'trials', asFingerprint('email-records'), $asProcess(['billing_trial_days' => '30'])['changes']);
asCheck('L', $asOtherSection['ok'] === false && $asOtherSection['stale'] === true, 'another section\'s fingerprint is refused');

// Same-second change: the value differs, so the fingerprint does, with no waiting.
$asFpC = asFingerprint('trials');
adminSaveSetting(['key' => 'billing_trial_days'], '15', $asAdmin);
asCheck('L', asFingerprint('trials') !== $asFpC, 'a change made within the same second still changes the fingerprint');
adminSaveSetting(['key' => 'billing_trial_days'], '14', $asAdmin);

// A secret replaced by someone else is noticed (the "set" state is the same, the marker is not).
adminSaveSetting(['key' => 'paypal_webhook_id'], $asSecrets['paypal'] . '-hook-one', $asAdmin);
$asFpPaypal = asFingerprint('paypal');
adminSaveSetting(['key' => 'paypal_webhook_id'], $asSecrets['paypal'] . '-hook-two', $asAdmin);
asCheck('L', asFingerprint('paypal') !== $asFpPaypal, 'a secret replaced by another secret changes the fingerprint');
$asModeChange = adminSettingsProcessSection('paypal', ['f' => ['paypal_mode' => 'live']], (array) adminSettingsLoadRows(array_keys(adminSettingsFieldsForSection('paypal'))));
$asStaleSecret = adminSettingsSaveSection($asAdmin, 'paypal', $asFpPaypal, $asModeChange['changes']);
asCheck('L', $asStaleSecret['ok'] === false && $asStaleSecret['stale'] === true && asConfig('paypal_mode') === 'sandbox' && asConfig('paypal_webhook_id') === $asSecrets['paypal'] . '-hook-two', 'a form from before the secret was replaced is refused and the replaced secret is untouched');
asCheck('L', !str_contains(json_encode($asStaleSecret), $asSecrets['paypal']), '... and the refusal holds no secret');

// A row the screen creates on first save: the form opened before it existed is stale after it exists.
$asFpLimits = asFingerprint('admin-security');
adminSaveSetting(['key' => 'verification_resend_ip_per_hour'], '12', $asAdmin);
asCheck('L', asFingerprint('admin-security') !== $asFpLimits, 'creating a row (first save of a limit) changes the section fingerprint');

// 🏁 A REAL overlap: another connection holds the section's rows locked while a second process tries to save.
$asFpOld = asFingerprint('trials');
$asChildChanges = $asProcess(['billing_trial_days' => '33'])['changes'];
$asLocker = new \mysqli(asEnv('HOST'), asEnv('USER'), asEnv('PASS'), $asDbName, (int) $asPort);
$asLocker->query("SET time_zone = '+00:00'");
$asLocker->begin_transaction();
$asLocker->query("SELECT `settingKey` FROM `tblSettings` WHERE `settingKey` IN ('billing_trial_days', 'billing_trial_plan_key', 'billing_trials_enabled', 'billing_trial_requires_card') FOR UPDATE");
$asChildCommand = [PHP_BINARY, __FILE__, '--save-section', $asDbName, base64_encode((string) json_encode(['userID' => $asAdminID, 'email' => $asAdminEmail, 'section' => 'trials', 'fp' => $asFpOld, 'changes' => $asChildChanges]))];
$asChildProcess = proc_open($asChildCommand, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $asChildPipes, null, ['CUERCODE_IT_ENCRYPTION_KEY' => ENCRYPTION_KEY] + getenv());
fclose($asChildPipes[0]);
usleep(3000000);
$asChildStatus = proc_get_status($asChildProcess);
asCheck('L', $asChildStatus['running'] === true, 'the second process is still waiting for the locked rows after 3 seconds (it did not slip past the lock)');
// The first connection changes the days (as another admin would) and lets go.
$asLocker->query("UPDATE `tblSettings` SET `settingValue` = '21' WHERE `settingKey` = 'billing_trial_days'");
$asLocker->commit();
$asChildOut = (string) stream_get_contents($asChildPipes[1]);
$asChildErr = (string) stream_get_contents($asChildPipes[2]);
fclose($asChildPipes[1]);
fclose($asChildPipes[2]);
$asChildExit = proc_close($asChildProcess);
$asLocker->close();
$asChildJson = null;
$asChildMarker = strrpos($asChildOut, '@@RESULT@@');
if ($asChildMarker !== false) {
    $asChildJson = json_decode(trim(substr($asChildOut, $asChildMarker + strlen('@@RESULT@@'))), true);
}
asCheck('L', is_array($asChildJson) && $asChildJson['ok'] === false && $asChildJson['stale'] === true, 'once the lock is released the waiting save sees the NEW value and is refused as out of date' . ($asChildErr !== '' ? ' (stderr: ' . substr($asChildErr, 0, 200) . ')' : ''));
clearConfigCache('billing_trial_days');
asCheck('L', asConfig('billing_trial_days') === 21, 'the other admin\'s value (21) is what remains; the waiting form did not overwrite it');
adminSaveSetting(['key' => 'billing_trial_days'], '14', $asAdmin);
adminSaveSetting(['key' => 'paypal_webhook_id'], '', $asAdmin);

// ==============================================================================
// I. A failure leaks no secret
// ==============================================================================
echo PHP_EOL . 'I. Failures leak nothing' . PHP_EOL;
$asBeforeSecret = asSetting($asServer, 'graph_client_secret');
$asAuditBefore = asCount($asServer, 'SELECT COUNT(*) FROM `tblAdminAuditLog`');
$asServer->query('DROP TRIGGER IF EXISTS `asForcedFailure`');
$asServer->query('CREATE TRIGGER `asForcedFailure` BEFORE UPDATE ON `tblSettings` FOR EACH ROW BEGIN IF NEW.`settingKey` = \'graph_client_secret\' THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'forced failure for the integration test\'; END IF; END');
$asResult = adminSaveSetting(['key' => 'graph_client_secret'], $asSecrets['trigger'], $asAdmin);
$asDirect = setConfig('graph_client_secret', $asSecrets['trigger']);
$asServer->query('DROP TRIGGER IF EXISTS `asForcedFailure`');
asCheck('I', $asResult['ok'] === false && $asResult['outcome'] === 'failed', 'a forced database failure while saving a secret reports "not saved"');
asCheck('I', $asDirect === false, 'setConfig() itself returns false (no exception escapes)');
asCheck('I', asSetting($asServer, 'graph_client_secret') === $asBeforeSecret, 'the stored secret is exactly as before');
asCheck('I', asCount($asServer, 'SELECT COUNT(*) FROM `tblAdminAuditLog`') === $asAuditBefore, 'no audit row was kept');
asCheck('I', asCount($asServer, "SELECT COUNT(*) FROM `tblErrorLog` WHERE CONCAT_WS(' ', COALESCE(`errorTitle`, ''), COALESCE(`errorDetail`, '')) LIKE '%forced failure%'") >= 1, 'the failure WAS logged (so the "no secret" check below is meaningful)');
asCheck('I', asFoundInLogs($asServer, $asSecrets['trigger'], $asErrorLogPath) === [], 'the typed secret is in no log table and not in the PHP error log');

// 🧵 An exception whose stack trace carries the arguments.
$asTrace = '';
try {
    encryptValue($asSecrets['exception'], null, str_repeat('s', 300));
} catch (\Throwable $asThrown) {
    $asTrace = $asThrown->getTraceAsString() . $asThrown->getMessage();
}
asCheck('I', $asTrace !== '', 'set-up: encryptValue() with a bad key throws');
asCheck('I', !str_contains($asTrace, $asSecrets['exception']) && str_contains($asTrace, 'SensitiveParameterValue'), 'the stack trace hides the secret (#[\\SensitiveParameter])');
$asTrace = '';
try {
    adminSaveSetting(['key' => 'graph_client_secret'], new \stdClass(), $asAdmin);
} catch (\Throwable $asThrown) {
    $asTrace = $asThrown->getTraceAsString();
}
asCheck('I', $asTrace === '', 'an object instead of text is refused without throwing');
$asTrace = '';
try {
    setConfig('graph_client_secret', new \stdClass());
} catch (\Throwable $asThrown) {
    $asTrace = $asThrown->getTraceAsString();
}
asCheck('I', $asTrace !== '' && str_contains($asTrace, 'SensitiveParameterValue'), 'an uncaught error inside setConfig() shows the value only as SensitiveParameterValue');

// ==============================================================================
// J. The REAL page (child process, forged signed-in session)
// ==============================================================================
echo PHP_EOL . 'J. The real /admin/settings/ page' . PHP_EOL;
adminSaveSetting(['key' => 'graph_client_secret'], $asSecrets['page'], $asAdmin);
$asStoredCipher = (string) asSetting($asServer, 'graph_client_secret')['settingValue'];
$asBase = ['userID' => $asAdminID, 'token' => $asSessionToken, 'method' => 'GET', 'post' => [], 'reauth' => true, 'csrf' => str_repeat('a', 64)];

$asPage = asRenderPage($asDbName, $asBase);
if (getenv('CUERCODE_IT_DUMP_PAGE') !== false) {
    file_put_contents(getenv('CUERCODE_IT_DUMP_PAGE'), $asPage['html']);
}
asCheck('J', $asPage['exit'] === 0 && str_contains($asPage['html'], '<h1'), 'GET (unlocked): the page renders (exit ' . $asPage['exit'] . ', ' . strlen($asPage['html']) . ' bytes)' . ($asPage['err'] !== '' ? ' stderr: ' . substr($asPage['err'], 0, 300) : ''));
asCheck('J', substr_count($asPage['html'], '<h1') === 1, 'exactly one h1');
asCheck('J', substr_count($asPage['html'], '<section id=') === 0 && substr_count($asPage['html'], 'id="section-') === 9, 'nine sections');
asCheck('J', !str_contains($asPage['html'], $asSecrets['page']), 'the page does not contain the stored secret text');
asCheck('J', !str_contains($asPage['html'], $asStoredCipher) && !str_contains($asPage['html'], substr($asStoredCipher, 0, 24)), 'the page does not contain the stored ciphertext');
asCheck('J', str_contains($asPage['html'], 'Set - last changed'), 'the page says the secret is Set, and when it was last changed');
asCheck('J', preg_match('/type="password"[^>]*value="[^"]/', $asPage['html']) === 0, 'no secret box has a value');
asCheck('J', str_contains($asPage['html'], 'Editing unlocked until') && !str_contains($asPage['html'], '<fieldset disabled'), 'unlocked: the forms are editable');
// 🧾 Every section form carries its own fingerprint, the unsaved-changes warning and its own button words.
foreach (array_keys(adminSettingsSections()) as $asSectionId) {
    asCheck('J', str_contains($asPage['html'], '<input type="hidden" name="fp" value="' . asFingerprint($asSectionId) . '">'), 'the ' . $asSectionId . ' form carries the current fingerprint');
}
asCheck('J', substr_count($asPage['html'], 'data-admin-dirty-check') === 9, 'all nine forms have the unsaved-changes warning');
asCheck('J', str_contains($asPage['html'], 'Save PayPal settings') && str_contains($asPage['html'], 'Save prices, VAT and refunds') && str_contains($asPage['html'], 'Save email settings') && !str_contains($asPage['html'], 'Save email (microsoft'), 'each section has its own button words');
asCheck('J', str_contains($asPage['html'], '(required)') && str_contains($asPage['html'], 'data-admin-confirm-check="Remove this stored secret?"'), 'required boxes say so and the Remove tick asks for confirmation');
asCheck('J', str_contains($asPage['html'], 'name="f[password_reset_request_daily_cap]"') && str_contains($asPage['html'], 'name="f[verification_resend_min_gap_seconds]"'), 'the emailed-link limits are on the page');
asCheck('J', str_contains($asPage['html'], 'Off (locked)') && !str_contains($asPage['html'], 'name="f[billing_payg_enabled]"') && !str_contains($asPage['html'], 'name="f[dynamic_qr_enabled]"'), 'the charging switches show "Off (locked)" and have no input');
asCheck('J', str_contains($asPage['html'], 'Check Microsoft connection') && str_contains($asPage['html'], 'Send me a test email'), 'both test buttons are on the page');
$asMainStart = strpos($asPage['html'], 'id="section-email"');
$asMainEnd   = strrpos($asPage['html'], '</datalist>');
$asMainHtml  = '';
if ($asMainStart !== false && $asMainEnd !== false && $asMainEnd > $asMainStart) {
    $asMainHtml = substr($asPage['html'], $asMainStart, $asMainEnd - $asMainStart);
}
asCheck('J', strlen($asMainHtml) > 5000 && !str_contains($asMainHtml, '<script') && preg_match('/\son[a-z]+="/', $asMainHtml) === 0, 'the settings content itself has no inline script or inline event handler');

$asLocked = asRenderPage($asDbName, array_merge($asBase, ['reauth' => false]));
asCheck('J', $asLocked['exit'] === 0 && str_contains($asLocked['html'], '<fieldset disabled') && str_contains($asLocked['html'], 'Unlock editing') && !str_contains($asLocked['html'], 'Save email'), 'locked: the forms are read-only and there is an Unlock button but no Save button');
asCheck('J', !str_contains($asLocked['html'], $asSecrets['page']), 'locked: still no secret on the page');

// 🚫 A POST with a wrong CSRF token.
$asAuditBefore = asCount($asServer, 'SELECT COUNT(*) FROM `tblAdminAuditLog`');
$asPost = ['csrf_token' => 'wrong-token', 'settings_action' => 'save_section', 'section' => 'email-records', 'fp' => asFingerprint('email-records'), 'f' => ['email_queue_retention_days' => '77']];
$asBad = asRenderPage($asDbName, array_merge($asBase, ['method' => 'POST', 'post' => $asPost]));
asCheck('J', asConfig('email_queue_retention_days') === 45 && asCount($asServer, 'SELECT COUNT(*) FROM `tblAdminAuditLog`') === $asAuditBefore, 'a wrong CSRF token saves nothing');
$asBadStatus = 0;
if (isset($asBad['state']['status'])) {
    $asBadStatus = (int) $asBad['state']['status'];
}
asCheck('J', $asBadStatus === 403 && str_contains($asBad['html'], 'had expired'), 'a wrong CSRF token answers 403 with a plain message');

// 🚫 A POST from another site.
$asPost['csrf_token'] = $asBase['csrf'];
$asBad = asRenderPage($asDbName, array_merge($asBase, ['method' => 'POST', 'post' => $asPost, 'origin' => 'https://evil.example']));
asCheck('J', asConfig('email_queue_retention_days') === 45 && str_contains($asBad['html'], 'another website'), 'a POST from another site saves nothing');

// 🚫 A POST without a fresh password confirmation.
$asBad = asRenderPage($asDbName, array_merge($asBase, ['method' => 'POST', 'post' => $asPost, 'reauth' => false]));
asCheck('J', asConfig('email_queue_retention_days') === 45 && str_contains($asBad['html'], 'confirm your password again'), 'a POST without a fresh password confirmation saves nothing');

// 🚫 A hand-made POST naming a locked switch in a real section.
$asHostilePost = ['csrf_token' => $asBase['csrf'], 'settings_action' => 'save_section', 'section' => 'prices', 'fp' => asFingerprint('prices'), 'f' => ['billing_payg_enabled' => '1', 'dynamic_qr_enabled' => '1', 'billing_vat_mode' => 'none']];
asRenderPage($asDbName, array_merge($asBase, ['method' => 'POST', 'post' => $asHostilePost]));
asCheck('J', asConfig('billing_payg_enabled') === false && asConfig('dynamic_qr_enabled') === false, 'a hand-made POST cannot turn on a charging switch or dynamic QR codes');

// ❌ A POST with an invalid value: error summary, nothing saved, typed secret not echoed.
$asInvalidPost = ['csrf_token' => $asBase['csrf'], 'settings_action' => 'save_section', 'section' => 'email', 'fp' => asFingerprint('email'), 'f' => ['graph_client_id' => 'not-a-guid ' . $asSecrets['page'], 'email_from_name' => 'Kept Name']];
$asInvalid = asRenderPage($asDbName, array_merge($asBase, ['method' => 'POST', 'post' => $asInvalidPost]));
asCheck('J', str_contains($asInvalid['html'], 'id="settings-error-summary"') && str_contains($asInvalid['html'], 'role="alert"') && str_contains($asInvalid['html'], 'aria-invalid="true"'), 'a mistake shows an error summary and marks the box invalid');
asCheck('J', !str_contains($asInvalid['html'], $asSecrets['page']), 'the typed secret is NOT echoed back on the error page');
asCheck('J', str_contains($asInvalid['html'], 'value="Kept Name"') && asConfig('email_from_name') === 'Section Test Name', 'ordinary typed values are kept in the boxes; nothing was saved');

// ✅ A good POST.
$asGoodPost = ['csrf_token' => $asBase['csrf'], 'settings_action' => 'save_section', 'section' => 'email-records', 'fp' => asFingerprint('email-records'), 'f' => ['email_queue_retention_days' => '77', 'email_queue_failed_retention_days' => '3650']];
$asGood = asRenderPage($asDbName, array_merge($asBase, ['method' => 'POST', 'post' => $asGoodPost]));
asCheck('J', asConfig('email_queue_retention_days') === 77, 'a good POST saves the change');
$asFlashList = [];
if (isset($asGood['state']['flash'])) {
    $asFlashList = $asGood['state']['flash'];
}
$asFlash = json_encode($asFlashList);
asCheck('J', str_contains($asFlash, 'Saved') && str_contains($asFlash, 'Email records'), 'and leaves a one-time "Saved" message for the redirected page');
asCheck('J', count(asAuditRows($asServer, 'setting.update', 'email_queue_retention_days')) === 2, 'and exactly one more audit row');
asCheck('J', $asGood['html'] === '' || !str_contains($asGood['html'], '<h1'), 'a good POST redirects instead of drawing the page again');

// 🕰️ A POST from a form that is now out of date (the settings changed since it was drawn).
$asOldFp = asFingerprint('email-records');
adminSaveSetting(['key' => 'email_queue_retention_days'], '78', $asAdmin);
$asStalePost = ['csrf_token' => $asBase['csrf'], 'settings_action' => 'save_section', 'section' => 'email-records', 'fp' => $asOldFp, 'f' => ['email_queue_retention_days' => '77', 'email_queue_failed_retention_days' => '3650']];
$asStaleAuditBefore = asCount($asServer, 'SELECT COUNT(*) FROM `tblAdminAuditLog`');
$asStale = asRenderPage($asDbName, array_merge($asBase, ['method' => 'POST', 'post' => $asStalePost]));
asCheck('J', str_contains($asStale['html'], 'Someone changed these settings while you were editing. Nothing was saved. Reload the page and try again.'), 'an out-of-date form shows the plain-English message');
asCheck('J', asConfig('email_queue_retention_days') === 78 && asCount($asServer, 'SELECT COUNT(*) FROM `tblAdminAuditLog`') === $asStaleAuditBefore, 'the newer value (78) stays and no audit row is written');
asCheck('J', str_contains($asStale['html'], 'value="77"') && str_contains($asStale['html'], '<input type="hidden" name="fp" value="' . $asOldFp . '">'), 'the typed value is shown again and the form keeps its OLD fingerprint (pressing Save again is refused again until the page is reloaded)');
asCheck('J', !str_contains($asStale['html'], $asSecrets['page']), 'the out-of-date page holds no secret');
adminSaveSetting(['key' => 'email_queue_retention_days'], '77', $asAdmin);

// ✅ A good secret POST through the real page.
$asSecretPost = ['csrf_token' => $asBase['csrf'], 'settings_action' => 'save_section', 'section' => 'bot', 'fp' => asFingerprint('bot'), 'f' => ['turnstile_secret_key' => $asSecrets['page'] . '-turnstile']];
asRenderPage($asDbName, array_merge($asBase, ['method' => 'POST', 'post' => $asSecretPost]));
asCheck('J', asConfig('turnstile_secret_key') === $asSecrets['page'] . '-turnstile', 'a secret typed on the real page is saved and decrypts to what was typed');
asCheck('J', !str_contains((string) asSetting($asServer, 'turnstile_secret_key')['settingValue'], $asSecrets['page']), '... and is stored as ciphertext');

// ✅ Test connection through the page (no credentials: the REAL check, no network).
adminSaveSetting(['key' => 'graph_client_secret'], '', $asAdmin);
$asGraphRowsBeforeJ = count(asAuditRows($asServer, 'setting.test_graph'));
$asTestPost = ['csrf_token' => $asBase['csrf'], 'settings_action' => 'test_graph'];
$asTested = asRenderPage($asDbName, array_merge($asBase, ['method' => 'POST', 'post' => $asTestPost]));
$asTestedFlash = [];
if (isset($asTested['state']['flash'])) {
    $asTestedFlash = $asTested['state']['flash'];
}
asCheck('J', str_contains(json_encode($asTestedFlash), 'Could not connect') && count(asAuditRows($asServer, 'setting.test_graph')) === $asGraphRowsBeforeJ + 2, 'the page\'s connection check says Could not connect (nothing is filled in) and is audited (an attempt row and a result row)');

// ==============================================================================
// K. No secret anywhere in the logs
// ==============================================================================
echo PHP_EOL . 'K. No secret in any log' . PHP_EOL;
foreach ($asSecrets as $asName => $asSecretValue) {
    asCheck('K', asFoundInLogs($asServer, $asSecretValue, $asErrorLogPath) === [], 'the "' . $asName . '" secret is in no log table, the audit log or the PHP error log');
}
asCheck('K', asFoundInLogs($asServer, 'FAKE-ACCESS-TOKEN', $asErrorLogPath) === [] && asFoundInLogs($asServer, 'cached-token-value', $asErrorLogPath) === [], 'no access token is in any log');

// ==============================================================================
// 🏁 Clean up and report
// ==============================================================================
@unlink($asErrorLogPath);
$asServer->query('DROP DATABASE IF EXISTS `' . $asDbName . '`');
$asServer->close();

echo PHP_EOL . 'Summary (' . $GLOBALS['asCheckCount'] . ' checks):' . PHP_EOL;
$asAllPassed = true;
foreach ($asChecks as $asScenario => $asPassed) {
    if ($asPassed) {
        echo '  PASS ' . $asScenario . PHP_EOL;
    } else {
        echo '  FAIL ' . $asScenario . PHP_EOL;
        $asAllPassed = false;
    }
}
echo '  NOT RUN: a real browser; any real Microsoft call (the connection and test-email actions were tested with stand-ins only)' . PHP_EOL;

if ($asAllPassed) {
    exit(0);
}
exit(1);

/**
 * 📦 Load fn_pricing.php (not loaded by bootstrap.php).
 */
function requireFn_pricing(string $asSiteRoot): void
{
    require_once $asSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_pricing.php';
}
