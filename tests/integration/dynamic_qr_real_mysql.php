<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Real-database check: dynamic QR create, change, switch off (WP4c, #166)
 * ==============================================================================
 *
 * Path: tests/integration/dynamic_qr_real_mysql.php
 *
 * Proves, against a real MySQL or MariaDB server, the parts that need one:
 *
 *   A  set-up: install.sql, seed_data.sql and every upgrade (0007 included)
 *   B  who may make a code: a confirmed email, an active account, the master
 *      switch on, Go2My.Link set up - and nothing is saved or sent otherwise
 *   C  a working create: the row, the chosen short code, the destination in
 *      Go2My.Link's stored form, "active", what Go2My.Link was sent
 *   D  the limit: 3 on Free; failures never count, waiting and held codes
 *      and switched-off codes do; a person's own number only raises it
 *   E  requests at the same moment (6 processes) never pass the limit
 *   F  the per-person lock busy: refused, nothing saved
 *   G  lost answers: held ("unconfirmed") or waiting ("pending"), never
 *      printed; the cron tidy step settles waiting codes; it refuses on the
 *      command line without a declared site copy (#181)
 *   H  a taken short code is replaced and tried again
 *   I  changes: other people's codes 404 and nothing is sent; Go2My.Link is
 *      changed FIRST; a lost answer is confirmed by a look-up; switch off
 *      points at /unavailable/; switch on goes back; a suspended or waiting
 *      code refuses changes
 *   J  the picture: only an "active" code is ever drawn or downloaded, only
 *      by its owner, and never from typed-in details
 *   K  the admin switch: on only after a passing connection test in the
 *      last hour with no change since; off always
 *   L  the /admin/limits/ screen: find, see, set and remove one person's own
 *      number (the real page, in a child process)
 *   M  real HTTPS against tests/lib/go2mylink_standin_server.php
 *   N  the real HTTP routes, through PHP's built-in web server (stopped at
 *      the end): POST /generate type dynamic_url, GET /download, POST /save,
 *      POST /preview, GET and PATCH /qrcodes/{uuid}/destination - the web
 *      server's curl trusts only the stand-in's throwaway authority
 *
 * Nothing is sent to the real Go2My.Link or anywhere else: a scripted
 * stand-in answers in-process, and section M uses the stand-in server on
 * 127.0.0.1 with a throwaway certificate (stopped at the end).
 *
 * ⚠️ SAFETY: it creates and DROPS a whole database. It refuses to run (exit 2)
 *    unless the database name starts with "cuercode_it_". Connection details
 *    come only from CUERCODE_IT_DB_* environment variables.
 *
 * Run (see tests/README.md):
 *     CUERCODE_IT_DB_HOST=127.0.0.1 CUERCODE_IT_DB_PORT=3306 \
 *     CUERCODE_IT_DB_NAME=cuercode_it_dynamic CUERCODE_IT_DB_USER=root CUERCODE_IT_DB_PASS= \
 *       php tests/integration/dynamic_qr_real_mysql.php
 *
 * Exit codes: 0 all passed | 1 a check failed or crashed | 2 refused.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (WP4c, #166)
 */

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
    }
    exit('Access denied.');
}

$dqSiteRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'CueRCode.net' . DIRECTORY_SEPARATOR;

/**
 * 🌍 One CUERCODE_IT_DB_* value ('' when unset).
 *
 * @param string $dqKey
 * @return string
 */
function dqEnv(string $dqKey): string
{
    $dqValue = getenv('CUERCODE_IT_DB_' . $dqKey);
    if ($dqValue === false) {
        return '';
    }
    return $dqValue;
}

/**
 * 🔌 Define the database constants the site's start-up reads.
 *
 * @param string $dqDbName
 * @return void
 */
function dqDefineDatabase(string $dqDbName): void
{
    $dqPort = dqEnv('PORT');
    if ($dqPort === '') {
        $dqPort = '3306';
    }
    define('DB_HOST', dqEnv('HOST'));
    define('DB_PORT', (int) $dqPort);
    define('DB_USER', dqEnv('USER'));
    define('DB_PASS', dqEnv('PASS'));
    define('DB_NAME', $dqDbName);
    $dqKey = getenv('CUERCODE_IT_ENCRYPTION_KEY');
    if ($dqKey === false || $dqKey === '') {
        $dqKey = bin2hex(random_bytes(16));
    }
    define('ENCRYPTION_KEY', $dqKey);
}

// ==============================================================================
// 🎭 A scripted Go2My.Link, in-process (answers in Go2My.Link's own shapes)
// ==============================================================================
$GLOBALS['dqFake'] = ['links' => [], 'uuids' => [], 'modes' => [], 'requests' => [], 'delayMs' => 0];

/**
 * 🎭 Set the stand-in's behaviour (and optionally forget its links).
 *
 * @param array<int, string> $dqModes
 * @param bool               $dqForget
 * @return void
 */
function dqFakeModes(array $dqModes, bool $dqForget = false): void
{
    $GLOBALS['dqFake']['modes']    = $dqModes;
    $GLOBALS['dqFake']['requests'] = [];
    if ($dqForget) {
        $GLOBALS['dqFake']['links'] = [];
        $GLOBALS['dqFake']['uuids'] = [];
    }
}

/**
 * 🧾 The requests the stand-in received, as "METHOD route".
 *
 * @return array<int, string>
 */
function dqFakeSeen(): array
{
    return $GLOBALS['dqFake']['requests'];
}

/**
 * 🎭 The transport fn_go2mylink.php calls instead of the network.
 *
 * @param array $dqRequest From go2mylinkBuildRequest()
 * @param int   $dqTimeout
 * @return array A transport reply
 */
function dqFakeTransport(array $dqRequest, int $dqTimeout): array
{
    $dqFake   = &$GLOBALS['dqFake'];
    $dqModes  = $dqFake['modes'];
    $dqRoute  = substr((string) $dqRequest['url'], strlen('https://go2my.test/api/v1/'));
    $dqMethod = (string) $dqRequest['method'];
    $dqFake['requests'][] = $dqMethod . ' ' . $dqRoute;

    $dqJson = static function (int $dqStatus, array $dqBody): array {
        return ['ok' => true, 'status' => $dqStatus, 'body' => (string) json_encode($dqBody), 'retryAfter' => 0, 'errno' => 0, 'tooLarge' => false, 'sent' => true];
    };
    $dqOk = static function (array $dqData) use ($dqJson): array {
        return $dqJson(200, ['status' => 'success', 'data' => $dqData]);
    };
    $dqErr = static function (int $dqStatus, string $dqMessage, ?string $dqField = null) use ($dqJson): array {
        return $dqJson($dqStatus, ['status' => 'error', 'error' => ['code' => $dqStatus, 'message' => $dqMessage, 'field' => $dqField]]);
    };
    $dqLost = ['ok' => false, 'status' => 0, 'body' => '', 'retryAfter' => 0, 'errno' => 28, 'tooLarge' => false, 'sent' => true];
    $dqDown = ['ok' => false, 'status' => 0, 'body' => '', 'retryAfter' => 0, 'errno' => 7, 'tooLarge' => false, 'sent' => false];
    $dqRow = static function (array $dqLink) use ($dqModes): array {
        $dqOut = ['short_code' => $dqLink['code'], 'destination_url' => $dqLink['destination'], 'title' => null, 'is_active' => true, 'click_count' => 5,
                  'created_at' => '2026-11-28 10:00:00', 'updated_at' => '2026-11-28 10:00:00', 'start_date' => null, 'end_date' => null];
        if (in_array('lookupGivesUrl', $dqModes, true)) {
            $dqOut['short_url'] = 'https://QRmy.Link/' . $dqLink['code'];
        }
        return $dqOut;
    };

    if ($dqFake['delayMs'] > 0) {
        usleep($dqFake['delayMs'] * 1000);
    }
    if (in_array('down', $dqModes, true)) {
        return $dqDown;
    }

    $dqBody = json_decode((string) $dqRequest['body'], true);
    $dqCode = '';
    if (preg_match('#^urls/([A-Za-z0-9_-]+)$#', $dqRoute, $dqMatch) === 1) {
        $dqCode = $dqMatch[1];
    }

    if ($dqMethod === 'POST' && $dqRoute === 'urls') {
        if (in_array('rejectCreate', $dqModes, true)) {
            return $dqErr(422, 'That destination is not permitted.', 'destination_url');
        }
        if (in_array('takenOnce', $dqModes, true)) {
            $dqFake['modes'] = array_values(array_diff($dqModes, ['takenOnce']));
            return $dqErr(409, 'That alias is already taken. Please choose another.', 'custom_code');
        }
        $dqUuid = strtolower((string) $dqBody['qr_external_uuid']);
        if (isset($dqFake['uuids'][$dqUuid])) {
            return $dqErr(409, 'That QR code is already linked to a short URL.', 'qr_external_uuid');
        }
        $dqNew = (string) $dqBody['custom_code'];
        if (isset($dqFake['links'][strtolower($dqNew)])) {
            return $dqErr(409, 'That alias is already taken. Please choose another.', 'custom_code');
        }
        $dqFake['links'][strtolower($dqNew)] = ['code' => $dqNew, 'destination' => (string) $dqBody['destination_url'], 'uuid' => $dqUuid, 'qrId' => (int) $dqBody['qr_external_id']];
        $dqFake['uuids'][$dqUuid] = $dqNew;
        if (in_array('loseCreate', $dqModes, true)) {
            return $dqLost;
        }
        return $dqJson(201, ['status' => 'success', 'data' => ['short_code' => $dqNew, 'short_url' => 'https://QRmy.Link/' . $dqNew, 'qr_code_external_uuid' => $dqUuid]]);
    }

    if ($dqMethod === 'GET' && $dqCode !== '') {
        if (in_array('lookupDown', $dqModes, true)) {
            return $dqLost;
        }
        if (!isset($dqFake['links'][strtolower($dqCode)])) {
            return $dqErr(404, 'No such short URL in this account.');
        }
        return $dqOk($dqRow($dqFake['links'][strtolower($dqCode)]));
    }

    if ($dqMethod === 'PUT' && $dqCode !== '') {
        if (in_array('updateDown', $dqModes, true)) {
            return $dqDown;
        }
        if (!isset($dqFake['links'][strtolower($dqCode)])) {
            return $dqErr(404, 'No such short URL in this account.');
        }
        $dqFake['links'][strtolower($dqCode)]['destination'] = (string) $dqBody['destination_url'];
        if (in_array('loseUpdate', $dqModes, true)) {
            return $dqLost;
        }
        return $dqOk($dqRow($dqFake['links'][strtolower($dqCode)]));
    }

    return $dqErr(404, 'The requested endpoint does not exist.');
}

/**
 * ⚙️ The options every flow is given in this script: the stand-in's settings.
 *
 * @param array<string, mixed> $dqExtra
 * @return array<string, mixed>
 */
function dqOptions(array $dqExtra = []): array
{
    return array_merge([
        'go2mylink' => [
            'settings'  => [
                'apiBaseUrl'    => 'https://go2my.test/api/v1',
                'apiKey'        => 'g2ml_ItTestAb_' . str_repeat('k', 43),
                'keyUnreadable' => false,
                'timeout'       => 2,
                'shortLinkBase' => 'https://QRmy.Link',
                'problem'       => '',
            ],
            'transport' => 'dqFakeTransport',
            'logger'    => static function (string $dqLine): void {
                $GLOBALS['dqLogged'][] = $dqLine;
            },
        ],
    ], $dqExtra);
}
$GLOBALS['dqLogged'] = [];

$dqArguments = array_slice($argv, 1);

// ==============================================================================
// 👶 CHILD mode 1: one create, at the same moment as others (scenario E)
// php <this file> --child-create <db> <userID>
// ==============================================================================
if (isset($dqArguments[0]) && $dqArguments[0] === '--child-create') {
    if (!isset($dqArguments[1], $dqArguments[2]) || preg_match('/^cuercode_it_[a-z0-9_]+$/', $dqArguments[1]) !== 1) {
        exit(9);
    }
    dqDefineDatabase($dqArguments[1]);
    require_once $dqSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
    require_once $dqSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_api_redirects.php';
    restore_error_handler();
    restore_exception_handler();
    // 🐢 Each Go2My.Link answer takes 300 ms, so the requests overlap.
    $GLOBALS['dqFake']['delayMs'] = 300;
    $dqChild = createDynamicRedirectForOwner((int) $dqArguments[2], 'https://example.com/race', dqOptions());
    echo '@@RESULT@@' . json_encode(['ok' => $dqChild['ok'], 'errorCode' => $dqChild['errorCode']]) . PHP_EOL;
    exit(0);
}

// ==============================================================================
// 👶 CHILD mode 2: render the REAL /admin/limits/ page (scenario L)
// php <this file> --render-limits <db> <base64 json {userID, token, method, get, post, reauth, csrf}>
// ==============================================================================
if (isset($dqArguments[0]) && $dqArguments[0] === '--render-limits') {
    if (!isset($dqArguments[1], $dqArguments[2]) || preg_match('/^cuercode_it_[a-z0-9_]+$/', $dqArguments[1]) !== 1) {
        exit(9);
    }
    $dqRequest = json_decode((string) base64_decode($dqArguments[2], true), true);
    dqDefineDatabase($dqArguments[1]);
    $_SERVER['HTTP_HOST']       = 'cuercode.test';
    $_SERVER['REQUEST_METHOD']  = $dqRequest['method'];
    $_SERVER['REQUEST_URI']     = '/admin/limits/';
    $_SERVER['SCRIPT_NAME']     = '/admin/limits/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $dqSiteRoot . 'public_html/admin/limits/index.php';
    $_SERVER['REMOTE_ADDR']     = '203.0.113.9';
    $_SERVER['HTTP_USER_AGENT'] = 'integration-test';
    require_once $dqSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
    restore_error_handler();
    restore_exception_handler();
    ini_set('session.save_path', sys_get_temp_dir());
    session_id('cuercode' . bin2hex(random_bytes(8)));
    session_start();
    $_SESSION[CUERCODE_SESSION_TOKEN_KEY]  = $dqRequest['token'];
    $_SESSION[CUERCODE_CSRF_SESSION_KEY]   = $dqRequest['csrf'];
    $_SESSION[CUERCODE_CSRF_TIMESTAMP_KEY] = time();
    if ($dqRequest['reauth'] === true) {
        $_SESSION['cuercode_admin_reauth'] = ['userID' => (int) $dqRequest['userID'], 'sessionHash' => hash('sha256', (string) $dqRequest['token']), 'confirmedAt' => time(), 'lastUsedAt' => time()];
    }
    $_GET  = $dqRequest['get'];
    $_POST = $dqRequest['post'];
    register_shutdown_function(static function (): void {
        $dqFlash = [];
        if (isset($_SESSION['cuercode_admin_flash'])) {
            $dqFlash = $_SESSION['cuercode_admin_flash'];
        }
        echo PHP_EOL . '@@STATE@@' . json_encode(['flash' => $dqFlash, 'status' => http_response_code()]) . PHP_EOL;
    });
    require $dqSiteRoot . 'public_html' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'limits' . DIRECTORY_SEPARATOR . 'index.php';
    exit(0);
}

// ==============================================================================
// 🛡️ Safety, then a fresh database
// ==============================================================================
$dqDbName = dqEnv('NAME');
if (preg_match('/^cuercode_it_[a-z0-9_]+$/', $dqDbName) !== 1) {
    fwrite(STDERR, 'Refusing to run: CUERCODE_IT_DB_NAME must start with "cuercode_it_" (this script creates and drops a whole database).' . PHP_EOL);
    exit(2);
}
if (dqEnv('HOST') === '' || dqEnv('USER') === '') {
    fwrite(STDERR, 'Refusing to run: set CUERCODE_IT_DB_HOST and CUERCODE_IT_DB_USER.' . PHP_EOL);
    exit(2);
}
$dqPort = dqEnv('PORT');
if ($dqPort === '') {
    $dqPort = '3306';
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dqServer   = null;
$dqDeadline = microtime(true) + 120;
$dqLastErr  = '';
while ($dqServer === null && microtime(true) < $dqDeadline) {
    try {
        $dqServer = new \mysqli(dqEnv('HOST'), dqEnv('USER'), dqEnv('PASS'), '', (int) $dqPort);
    } catch (\Throwable $dqError) {
        $dqLastErr = $dqError->getMessage();
        usleep(500000);
    }
}
if ($dqServer === null) {
    fwrite(STDERR, 'Refusing: the test database server could not be reached (' . $dqLastErr . ').' . PHP_EOL);
    exit(2);
}
echo 'Server: ' . $dqServer->server_info . PHP_EOL;

$dqServer->query('DROP DATABASE IF EXISTS `' . $dqDbName . '`');
$dqServer->query('CREATE DATABASE `' . $dqDbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$dqServer->select_db($dqDbName);
foreach (['install.sql', 'seed_data.sql'] as $dqSqlFile) {
    $dqServer->multi_query((string) file_get_contents($dqSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . $dqSqlFile));
    do {
        $dqStored = $dqServer->store_result();
        if ($dqStored instanceof \mysqli_result) {
            $dqStored->free();
        }
    } while ($dqServer->more_results() && $dqServer->next_result());
}
$dqServer->close();

$dqErrorLog = tempnam(sys_get_temp_dir(), 'cuercode_it_dq_');
ini_set('log_errors', '1');
ini_set('error_log', $dqErrorLog);

dqDefineDatabase($dqDbName);
require_once $dqSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once $dqSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_migrations.php';
require_once $dqSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_api_redirects.php';
require_once $dqSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_admin_settings.php';
require_once $dqSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_admin_limits.php';
require_once $dqSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_api_generate.php';
restore_error_handler();
ini_set('display_errors', 'stderr');
set_exception_handler(static function (\Throwable $dqError): void {
    fwrite(STDERR, 'Integration check crashed: ' . get_class($dqError) . ': ' . $dqError->getMessage() . ' at ' . $dqError->getFile() . ':' . $dqError->getLine() . PHP_EOL);
    exit(1);
});
$_SERVER['REMOTE_ADDR']     = '203.0.113.60';
$_SERVER['HTTP_USER_AGENT'] = 'integration-check';

// ==============================================================================
// 🧰 Helpers
// ==============================================================================
$GLOBALS['dqFailures'] = 0;
$GLOBALS['dqChecks']   = 0;

/**
 * ✅ One check.
 *
 * @param bool   $dqPassed
 * @param string $dqWhat
 * @return void
 */
function dqCheck(bool $dqPassed, string $dqWhat): void
{
    $GLOBALS['dqChecks']++;
    if (!$dqPassed) {
        $GLOBALS['dqFailures']++;
        echo '    FAIL ' . $dqWhat . PHP_EOL;
        return;
    }
    echo '    ok   ' . $dqWhat . PHP_EOL;
}

/**
 * 🗄️ Rows from a query.
 *
 * @param string $dqSql
 * @param string $dqTypes
 * @param array  $dqParams
 * @return array<int, array<string, mixed>>
 */
function dqRows(string $dqSql, string $dqTypes = '', array $dqParams = []): array
{
    $dqStmt = dbQuery($dqSql, $dqTypes, $dqParams);
    $dqOut  = $dqStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dqStmt->close();
    return $dqOut;
}

/**
 * 🗄️ Run a statement.
 *
 * @param string $dqSql
 * @param string $dqTypes
 * @param array  $dqParams
 * @return void
 */
function dqExec(string $dqSql, string $dqTypes = '', array $dqParams = []): void
{
    $dqStmt = dbQuery($dqSql, $dqTypes, $dqParams);
    $dqStmt->close();
}

/**
 * 👤 A new account; returns its number.
 *
 * @param string $dqName
 * @param string $dqStatus
 * @param bool   $dqVerified
 * @param int    $dqRole
 * @return int
 */
function dqUser(string $dqName, string $dqStatus = 'active', bool $dqVerified = true, int $dqRole = 3): int
{
    $dqEmail = 'dq-' . $dqName . '@dq.cuercode.invalid';
    $dqVerifiedAt = null;
    if ($dqVerified) {
        $dqVerifiedAt = '2026-11-01 09:00:00';
    }
    dqExec('INSERT INTO `tblUsers` (`email`, `passwordHash`, `status`, `emailVerifiedAt`, `roleID`, `displayName`) VALUES (?, ?, ?, ?, ?, ?)', 'ssssis', [$dqEmail, password_hash('Dq-pass-' . $dqName, PASSWORD_DEFAULT), $dqStatus, $dqVerifiedAt, $dqRole, 'DQ ' . $dqName]);
    return (int) dqRows('SELECT `userID` FROM `tblUsers` WHERE `email` = ?', 's', [$dqEmail])[0]['userID'];
}

/**
 * 🔢 This person's rows by state.
 *
 * @param int $dqUserID
 * @return array<string, int>
 */
function dqStates(int $dqUserID): array
{
    $dqOut = [];
    foreach (dqRows('SELECT `syncState`, COUNT(*) AS `c` FROM `tblQRRedirects` WHERE `ownerUserID` = ? GROUP BY `syncState`', 'i', [$dqUserID]) as $dqRow) {
        $dqOut[(string) $dqRow['syncState']] = (int) $dqRow['c'];
    }
    ksort($dqOut);
    return $dqOut;
}

/**
 * 🔢 A setting, straight into the table (and the request cache forgotten).
 *
 * @param string $dqKey
 * @param string $dqValue
 * @return void
 */
function dqSetting(string $dqKey, string $dqValue): void
{
    dqExec('UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingKey` = ?', 'ss', [$dqValue, $dqKey]);
    clearConfigCache();
}

/**
 * 🧹 Forget every cached limit and own number for this request.
 *
 * @param int $dqUserID
 * @return void
 */
function dqForgetLimits(int $dqUserID): void
{
    featuresCacheStore('clear', 'limit_override:' . $dqUserID . ':dynamic_codes');
}

echo 'Connected. Nothing is sent to the real Go2My.Link.' . PHP_EOL;

// ==============================================================================
// A. Set-up
// ==============================================================================
echo PHP_EOL . 'A. Set-up' . PHP_EOL;
$dqUpgrade = migrationRun(getDB(), 'apply', 'test', migrationDefaultDirectory(), migrationDefaultInstallSqlPath());
dqCheck($dqUpgrade['status'] === 'ok', 'every database upgrade applied (' . (string) $dqUpgrade['status'] . ')');
dqCheck(dynamicQrMasterSwitchOn() === false, 'dynamic codes are switched OFF on a new install');
dqSetting('site_url', 'https://cuercode.net');
dqSetting('dynamic_redirect_base_url', 'https://QRmy.Link');

$dqAmy     = dqUser('amy');
$dqBob     = dqUser('bob');
$dqPending = dqUser('pend', 'pending', false);
$dqNoEmail = dqUser('noemail', 'active', false);
$dqGone    = dqUser('gone', 'suspended', true);

// ==============================================================================
// B. Who may make a code
// ==============================================================================
echo PHP_EOL . 'B. Who may make a code' . PHP_EOL;
dqFakeModes([], true);
$dqOut = createDynamicRedirectForOwner($dqAmy, 'https://example.com/menu', dqOptions());
dqCheck(!$dqOut['ok'] && $dqOut['httpCode'] === 503 && $dqOut['errorCode'] === 'DYNAMIC_QR_UNAVAILABLE' && dqFakeSeen() === [] && dqStates($dqAmy) === [], 'switched off (the default): 503 DYNAMIC_QR_UNAVAILABLE, nothing saved, nothing sent');
dqSetting('dynamic_qr_enabled', '1');
foreach (['pending account, unconfirmed' => $dqPending, 'confirmed? no' => $dqNoEmail, 'suspended account' => $dqGone] as $dqLabel => $dqWho) {
    dqFakeModes([]);
    $dqOut = createDynamicRedirectForOwner($dqWho, 'https://example.com/menu', dqOptions());
    dqCheck(!$dqOut['ok'] && $dqOut['httpCode'] === 403 && $dqOut['errorCode'] === 'EMAIL_NOT_CONFIRMED' && dqFakeSeen() === [] && dqStates($dqWho) === [], $dqLabel . ': 403 EMAIL_NOT_CONFIRMED, nothing saved, nothing sent');
}
dqFakeModes([]);
$dqOut = createDynamicRedirectForOwner($dqAmy, 'https://example.com/menu', dqOptions(['go2mylink' => ['settings' => ['problem' => 'not_configured']]]));
dqCheck(!$dqOut['ok'] && $dqOut['errorCode'] === 'DYNAMIC_QR_UNAVAILABLE' && dqStates($dqAmy) === [], 'Go2My.Link not set up: 503 DYNAMIC_QR_UNAVAILABLE before any place is taken');
$dqOut = createDynamicRedirectForOwner($dqAmy, 'https://qrmy.link/Zz9yY8x', dqOptions());
dqCheck(!$dqOut['ok'] && $dqOut['httpCode'] === 400 && $dqOut['errorCode'] === 'INVALID_DESTINATION' && dqFakeSeen() === [] && dqStates($dqAmy) === [], 'another short link as the destination: 400 INVALID_DESTINATION, nothing saved or sent');

// ==============================================================================
// C. A working create
// ==============================================================================
echo PHP_EOL . 'C. A working create' . PHP_EOL;
dqFakeModes([]);
$dqOut = createDynamicRedirectForOwner($dqAmy, 'https://Example.com/caf é?x=1', dqOptions());
$dqC = $dqOut['row'];
if (!$dqOut['ok']) {
    echo '    (outcome: ' . json_encode([$dqOut['errorCode'], $dqOut['message'], $dqOut['extra'], dqFakeSeen(), $GLOBALS['dqLogged']]) . ')' . PHP_EOL;
}
dqCheck($dqOut['ok'] === true && is_array($dqC) && $dqC['syncState'] === 'active' && dynamicQrRowIsPrintable($dqC), 'made: the row is "active" and may be printed');
dqCheck(preg_match('/^[A-Za-z0-9]{7}$/', (string) $dqC['shortCode']) === 1, 'CueRCode chose a 7-character short code (L1): ' . $dqC['shortCode']);
dqCheck($dqC['destinationURL'] === 'https://example.com/caf%20%C3%A9?x=1', 'the destination is stored in Go2My.Link\'s form (#181 Low 3): ' . $dqC['destinationURL']);
dqCheck(dqFakeSeen() === ['POST urls'], 'Go2My.Link was asked once');
$dqFakeLink = $GLOBALS['dqFake']['links'][strtolower((string) $dqC['shortCode'])];
dqCheck($dqFakeLink['uuid'] === $dqC['redirectUUID'] && $dqFakeLink['qrId'] === (int) $dqC['redirectID'] && $dqFakeLink['destination'] === $dqC['destinationURL'], 'Go2My.Link holds it with our QR id and the same destination');
dqCheck((int) $dqC['ownerUserID'] === $dqAmy && $dqC['lastSyncError'] === null, 'owned by Amy, no error noted');
$dqView = dynamicQrPublicView($dqC);
dqCheck($dqView['state'] === 'active' && $dqView['short_url'] === 'https://QRmy.Link/' . $dqC['shortCode'], 'what Amy sees: active, https://QRmy.Link/' . $dqC['shortCode']);

// ==============================================================================
// D. The limit
// ==============================================================================
echo PHP_EOL . 'D. The limit (Free: 3)' . PHP_EOL;
dqFakeModes(['rejectCreate']);
$dqOut = createDynamicRedirectForOwner($dqAmy, 'https://example.com/refused', dqOptions());
dqCheck(!$dqOut['ok'] && $dqOut['httpCode'] === 422 && $dqOut['errorCode'] === 'LINK_SERVICE_REJECTED', 'Go2My.Link refuses: 422 LINK_SERVICE_REJECTED');
dqFakeModes(['down']);
for ($dqI = 0; $dqI < 5; $dqI++) {
    createDynamicRedirectForOwner($dqAmy, 'https://example.com/down' . $dqI, dqOptions());
}
dqCheck(dqStates($dqAmy) === ['active' => 1, 'failed' => 6], 'one refusal and five "cannot connect" tries are all "failed" (' . json_encode(dqStates($dqAmy)) . ')');
dqCheck(dynamicQrCountHeldCodes($dqAmy) === 1, '...and none of them count: Amy still holds 1');
dqFakeModes([]);
$dqOut2 = createDynamicRedirectForOwner($dqAmy, 'https://example.com/two', dqOptions());
$dqOut3 = createDynamicRedirectForOwner($dqAmy, 'https://example.com/three', dqOptions());
dqCheck($dqOut2['ok'] && $dqOut3['ok'] && dynamicQrCountHeldCodes($dqAmy) === 3, 'two more made: 3 held');
$dqOut = createDynamicRedirectForOwner($dqAmy, 'https://example.com/four', dqOptions());
dqCheck(!$dqOut['ok'] && $dqOut['httpCode'] === 402 && $dqOut['errorCode'] === 'DYNAMIC_CODES_LIMIT_REACHED' && $dqOut['extra']['plan_limit'] === 3 && dqFakeSeen() === ['POST urls', 'POST urls'], 'the 4th: 402 DYNAMIC_CODES_LIMIT_REACHED (limit 3), and Go2My.Link was not asked');
// 🔘 Switching one off does not free a place.
$dqSwitched = dynamicQrChangeForOwner($dqAmy, (string) $dqOut3['row']['redirectUUID'], ['active' => false], dqOptions());
$dqOut = createDynamicRedirectForOwner($dqAmy, 'https://example.com/four', dqOptions());
dqCheck($dqSwitched['ok'] && !$dqOut['ok'] && $dqOut['errorCode'] === 'DYNAMIC_CODES_LIMIT_REACHED', 'a switched-off code still counts: still 402');
dynamicQrChangeForOwner($dqAmy, (string) $dqOut3['row']['redirectUUID'], ['active' => true], dqOptions());
// 🎚️ A person's own number only raises it (decisions 0031, 0045).
$dqAdminID = dqUser('admin', 'active', true, 1);
$dqAdmin   = ['userID' => $dqAdminID, 'email' => 'dq-admin@dq.cuercode.invalid', 'displayName' => 'DQ admin', 'sessionHash' => '', 'hasPassword' => true];
adminSetUserLimitOverride($dqAdmin, $dqAmy, 'dynamic_codes', 2, 'Lower than the plan');
dqForgetLimits($dqAmy);
$dqOut = createDynamicRedirectForOwner($dqAmy, 'https://example.com/four', dqOptions());
dqCheck(!$dqOut['ok'] && $dqOut['extra']['plan_limit'] === 3, 'an own number of 2 never lowers the plan\'s 3');
adminSetUserLimitOverride($dqAdmin, $dqAmy, 'dynamic_codes', 4, 'Approved for the pilot');
dqForgetLimits($dqAmy);
$dqOut = createDynamicRedirectForOwner($dqAmy, 'https://example.com/four', dqOptions());
dqCheck($dqOut['ok'] && dynamicQrCountHeldCodes($dqAmy) === 4, 'an own number of 4: the 4th is made');
$dqOut = createDynamicRedirectForOwner($dqAmy, 'https://example.com/five', dqOptions());
dqCheck(!$dqOut['ok'] && $dqOut['errorCode'] === 'DYNAMIC_CODES_LIMIT_REACHED' && $dqOut['extra']['plan_limit'] === 4, '...and the 5th is refused (limit 4)');
// 🚫 A plan without dynamic codes.
dqExec("UPDATE `tblSubscriptionPlans` SET `featuresJSON` = JSON_SET(`featuresJSON`, '$.dynamic_qr', false) WHERE `planKey` = 'free'");
featuresCacheStore('clearAll');
$dqOut = createDynamicRedirectForOwner($dqBob, 'https://example.com/bob', dqOptions());
dqCheck(!$dqOut['ok'] && $dqOut['httpCode'] === 402 && $dqOut['errorCode'] === 'FEATURE_NOT_AVAILABLE' && dqStates($dqBob) === [], 'a plan without dynamic_qr: 402 FEATURE_NOT_AVAILABLE, nothing saved');
dqExec("UPDATE `tblSubscriptionPlans` SET `featuresJSON` = JSON_SET(`featuresJSON`, '$.dynamic_qr', true) WHERE `planKey` = 'free'");
featuresCacheStore('clearAll');

// ==============================================================================
// E. Requests at the same moment
// ==============================================================================
echo PHP_EOL . 'E. Six requests at the same moment, limit 3' . PHP_EOL;
$dqRacer = dqUser('racer');
// 🐢 Test database only: every new code row takes 0.4 s to save, so without
//    the per-person lock several requests would count "0 held" at once and
//    all get through. (Removed straight after this scenario.)
getDB()->query('CREATE TRIGGER `trg_dq_slow_insert` BEFORE INSERT ON `tblQRRedirects` FOR EACH ROW SET @dqSlow = SLEEP(0.4)');
$dqProcs = [];
for ($dqI = 0; $dqI < 6; $dqI++) {
    $dqPipes = [];
    $dqProc = proc_open([PHP_BINARY, __FILE__, '--child-create', $dqDbName, (string) $dqRacer], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $dqPipes, null, getenv());
    fclose($dqPipes[0]);
    $dqProcs[] = [$dqProc, $dqPipes];
}
$dqChildResults = [];
foreach ($dqProcs as $dqEntry) {
    $dqChildOut = (string) stream_get_contents($dqEntry[1][1]);
    $dqChildErr = (string) stream_get_contents($dqEntry[1][2]);
    fclose($dqEntry[1][1]);
    fclose($dqEntry[1][2]);
    proc_close($dqEntry[0]);
    $dqMarker = strrpos($dqChildOut, '@@RESULT@@');
    if ($dqMarker === false) {
        $dqChildResults[] = 'crash:' . substr($dqChildErr, 0, 200);
        continue;
    }
    $dqDecoded = json_decode(trim(substr($dqChildOut, $dqMarker + 10)), true);
    $dqChildResults[] = $dqDecoded['ok'] ? 'made' : (string) $dqDecoded['errorCode'];
}
sort($dqChildResults);
$dqMade = count(array_filter($dqChildResults, static function (string $dqR): bool {
    return $dqR === 'made';
}));
dqCheck($dqMade === 3 && dynamicQrCountHeldCodes($dqRacer) === 3, 'exactly 3 made, never more (' . implode(', ', $dqChildResults) . ')');
dqCheck(count(array_diff($dqChildResults, ['made', 'DYNAMIC_CODES_LIMIT_REACHED', 'DYNAMIC_LOCK_BUSY'])) === 0, 'the others were refused by the limit or the busy lock');
getDB()->query('DROP TRIGGER `trg_dq_slow_insert`');

// ==============================================================================
// F. The lock busy
// ==============================================================================
echo PHP_EOL . 'F. The per-person lock is busy' . PHP_EOL;
$dqCarl  = dqUser('carl');
$dqOther = new \mysqli(dqEnv('HOST'), dqEnv('USER'), dqEnv('PASS'), $dqDbName, (int) $dqPort);
$dqOther->query("SELECT GET_LOCK('" . CUERCODE_DYNAMIC_LOCK_NAME_PREFIX . $dqCarl . "', 0)");
dqFakeModes([]);
$dqStarted = microtime(true);
$dqOut = createDynamicRedirectForOwner($dqCarl, 'https://example.com/carl', dqOptions());
dqCheck(!$dqOut['ok'] && $dqOut['httpCode'] === 429 && $dqOut['errorCode'] === 'DYNAMIC_LOCK_BUSY' && $dqOut['retryAfter'] === 2 && dqStates($dqCarl) === [] && dqFakeSeen() === [], 'another request holds the lock: 429 DYNAMIC_LOCK_BUSY (Retry-After 2) after waiting ' . round(microtime(true) - $dqStarted, 1) . ' s; nothing saved or sent');
$dqOther->query("SELECT RELEASE_LOCK('" . CUERCODE_DYNAMIC_LOCK_NAME_PREFIX . $dqCarl . "')");
$dqOther->close();
$dqOut = createDynamicRedirectForOwner($dqCarl, 'https://example.com/carl', dqOptions());
dqCheck($dqOut['ok'], 'once it is let go, the create works');

// ==============================================================================
// G. Lost answers, and the tidy step
// ==============================================================================
echo PHP_EOL . 'G. Lost answers and the cron tidy step' . PHP_EOL;
$dqDee = dqUser('dee');
dqFakeModes(['loseCreate']);
$dqOut = createDynamicRedirectForOwner($dqDee, 'https://example.com/held', dqOptions());
dqCheck(!$dqOut['ok'] && $dqOut['httpCode'] === 503 && $dqOut['errorCode'] === 'LINK_NOT_CONFIRMED' && dqStates($dqDee) === ['unconfirmed' => 1], 'answer lost, look-up finds it but cannot confirm the domain (#276): 503, held as "unconfirmed"');
dqCheck(dqFakeSeen() === ['POST urls', 'GET urls/' . dqRows('SELECT `shortCode` FROM `tblQRRedirects` WHERE `ownerUserID` = ?', 'i', [$dqDee])[0]['shortCode']], 'it was looked up by its code before anything was tried again');
dqFakeModes(['loseCreate', 'lookupDown']);
$dqOut = createDynamicRedirectForOwner($dqDee, 'https://example.com/waiting', dqOptions());
$dqWaiting = fetchRedirectRowByUuid((string) $dqOut['extra']['redirect_uuid']);
dqCheck(!$dqOut['ok'] && $dqOut['errorCode'] === 'LINK_NOT_CONFIRMED' && $dqWaiting['syncState'] === 'pending', 'answer lost and the look-up lost too: "pending" (not known)');
dqCheck(dynamicQrCountHeldCodes($dqDee) === 2, 'held and waiting codes both count (2)');
dqCheck(!dynamicQrRowIsPrintable($dqWaiting), '...and neither may be printed');
// 🧹 A pending row that Go2My.Link never made.
dqExec("INSERT INTO `tblQRRedirects` (`redirectUUID`, `shortCode`, `syncState`, `ownerUserID`, `destinationURL`) VALUES (?, 'NeverMd', 'pending', ?, 'https://example.com/never')", 'si', [dynamicQrNewUuid(), $dqDee]);
dqFakeModes([]);
$dqTidy = dynamicQrTidyPendingRows(dqOptions(['server' => ['CUERCODE_SITE_COPY' => 'live']]));
dqCheck($dqTidy['ok'] && $dqTidy['looked'] === 0 && dqFakeSeen() === [], 'codes waiting for less than 10 minutes are left alone');
dqExec("UPDATE `tblQRRedirects` SET `updatedAt` = DATE_SUB(NOW(), INTERVAL 11 MINUTE) WHERE `syncState` = 'pending'");
$dqTidy = dynamicQrTidyPendingRows(dqOptions(['server' => []]));
dqCheck($dqTidy['ok'] && $dqTidy['skipped'] === 'copy_unknown' && $dqTidy['waiting'] === 2 && dqFakeSeen() === [], '#181 Low 4: on the command line with no declared site copy, nothing is sent (copy_unknown)');
dqCheck(str_contains(implode(' ', dynamicQrTidyLines($dqTidy)), 'CUERCODE_SITE_COPY=live'), '...and the cron output says how to declare it');
dqFakeModes(['lookupDown']);
$dqTidy = dynamicQrTidyPendingRows(dqOptions(['server' => ['CUERCODE_SITE_COPY' => 'live']]));
dqCheck($dqTidy['looked'] === 2 && $dqTidy['waiting'] === 2 && dqStates($dqDee)['pending'] === 2, 'the look-up still cannot answer: both stay pending (still counted)');
dqExec("UPDATE `tblQRRedirects` SET `updatedAt` = DATE_SUB(NOW(), INTERVAL 11 MINUTE) WHERE `syncState` = 'pending'");
dqFakeModes(['lookupGivesUrl']);
$dqTidy = dynamicQrTidyPendingRows(dqOptions(['server' => ['CUERCODE_SITE_COPY' => 'live']]));
dqCheck($dqTidy['looked'] === 2 && $dqTidy['failed'] === 1 && $dqTidy['active'] === 1, 'settled: the one Go2My.Link never made is "failed", the one it made is "active" (' . json_encode($dqTidy) . ')');
dqCheck(fetchRedirectRowByUuid((string) $dqWaiting['redirectUUID'])['syncState'] === 'active' && count(array_keys($GLOBALS['dqFake']['uuids'], (string) $dqWaiting['shortCode'], true)) === 1, '...without making a second link (the same code and QR id)');
dqCheck(dynamicQrCountHeldCodes($dqDee) === 2, 'the code that was never made no longer counts');
dqCheck(str_contains(implode(' ', dynamicQrTidyLines($dqTidy)), '1 working') && !str_contains(implode(' ', dynamicQrTidyLines($dqTidy)), 'example.com'), 'the cron line gives counts only, never an address');

// ==============================================================================
// H. A taken code
// ==============================================================================
echo PHP_EOL . 'H. A short code already taken on Go2My.Link' . PHP_EOL;
$dqEve = dqUser('eve');
dqFakeModes(['takenOnce']);
$dqOut = createDynamicRedirectForOwner($dqEve, 'https://example.com/eve', dqOptions());
$dqSeenH = dqFakeSeen();
dqCheck($dqOut['ok'] && count($dqSeenH) === 2 && $dqSeenH === ['POST urls', 'POST urls'], 'refused once as taken, a NEW code was stored and sent: made');
dqCheck(dqStates($dqEve) === ['active' => 1], 'one row, active');

// ==============================================================================
// I. Changes
// ==============================================================================
echo PHP_EOL . 'I. Change, switch off, switch on' . PHP_EOL;
$dqCode = fetchRedirectRowById((int) $dqC['redirectID']);
$dqUuid = (string) $dqCode['redirectUUID'];
$dqKey  = strtolower((string) $dqCode['shortCode']);
dqFakeModes([]);
$dqOut = dynamicQrChangeForOwner($dqBob, $dqUuid, ['destination_url' => 'https://evil.example/'], dqOptions());
dqCheck(!$dqOut['ok'] && $dqOut['httpCode'] === 404 && $dqOut['errorCode'] === 'NOT_FOUND' && dqFakeSeen() === [], 'Bob changing Amy\'s code: 404 NOT_FOUND, nothing sent');
$dqOut = dynamicQrChangeForOwner($dqBob, dynamicQrNewUuid(), ['destination_url' => 'https://evil.example/'], dqOptions());
dqCheck(!$dqOut['ok'] && $dqOut['httpCode'] === 404, 'an unknown code: the same 404');
dqFakeModes(['updateDown']);
$dqOut = dynamicQrChangeForOwner($dqAmy, $dqUuid, ['destination_url' => 'https://example.com/new'], dqOptions());
dqCheck(!$dqOut['ok'] && $dqOut['httpCode'] === 503 && $dqOut['errorCode'] === 'LINK_SERVICE_UNAVAILABLE' && fetchRedirectRowById((int) $dqC['redirectID'])['destinationURL'] === $dqCode['destinationURL'], 'Go2My.Link down: 503 "try again later" and NOTHING changed here');
dqFakeModes([]);
$dqOut = dynamicQrChangeForOwner($dqAmy, $dqUuid, ['destination_url' => 'https://example.com/new'], dqOptions());
dqCheck($dqOut['ok'] && dqFakeSeen() === ['PUT urls/' . $dqCode['shortCode']] && $GLOBALS['dqFake']['links'][$dqKey]['destination'] === 'https://example.com/new', 'a new destination: sent to Go2My.Link first');
dqCheck($dqOut['row']['destinationURL'] === 'https://example.com/new' && $dqOut['row']['previousDestinationURL'] === $dqCode['destinationURL'], '...then saved here, with the previous one kept');
dqFakeModes(['loseUpdate']);
$dqOut = dynamicQrChangeForOwner($dqAmy, $dqUuid, ['destination_url' => 'https://example.com/newer'], dqOptions());
dqCheck($dqOut['ok'] && dqFakeSeen() === ['PUT urls/' . $dqCode['shortCode'], 'GET urls/' . $dqCode['shortCode']] && $dqOut['row']['destinationURL'] === 'https://example.com/newer', 'the change\'s answer was lost: a look-up confirmed it, then it was saved');
dqFakeModes(['loseUpdate', 'lookupDown']);
$dqOut = dynamicQrChangeForOwner($dqAmy, $dqUuid, ['destination_url' => 'https://example.com/unsure'], dqOptions());
dqCheck(!$dqOut['ok'] && $dqOut['errorCode'] === 'LINK_NOT_CONFIRMED' && fetchRedirectRowById((int) $dqC['redirectID'])['destinationURL'] === 'https://example.com/newer', 'answer and look-up both lost: 503 LINK_NOT_CONFIRMED, nothing changed here');
dqCheck(fetchRedirectRowById((int) $dqC['redirectID'])['lastSyncError'] === 'timeout', '...and the reason is noted on the row');
dqFakeModes([]);
dynamicQrChangeForOwner($dqAmy, $dqUuid, ['destination_url' => 'https://example.com/newer'], dqOptions());
$dqOut = dynamicQrChangeForOwner($dqAmy, $dqUuid, ['active' => false], dqOptions());
dqCheck($dqOut['ok'] && (int) $dqOut['row']['isActive'] === 0 && $dqOut['row']['disabledReason'] === 'owner' && $dqOut['row']['destinationURL'] === 'https://example.com/newer', 'switched off: stored off by its owner, the destination kept');
dqCheck($GLOBALS['dqFake']['links'][$dqKey]['destination'] === 'https://cuercode.net/unavailable/?code=' . $dqCode['shortCode'], 'L2: Go2My.Link now points at https://cuercode.net/unavailable/?code=' . $dqCode['shortCode']);
dqFakeModes([]);
$dqOut = dynamicQrChangeForOwner($dqAmy, $dqUuid, ['destination_url' => 'https://example.com/while-off'], dqOptions());
dqCheck($dqOut['ok'] && dqFakeSeen() === [] && $dqOut['row']['destinationURL'] === 'https://example.com/while-off' && str_contains($GLOBALS['dqFake']['links'][$dqKey]['destination'], '/unavailable/'), 'a new destination while off: saved here only; scans still see the switched-off page');
$dqOut = dynamicQrChangeForOwner($dqAmy, $dqUuid, ['active' => true], dqOptions());
dqCheck($dqOut['ok'] && (int) $dqOut['row']['isActive'] === 1 && $dqOut['row']['disabledReason'] === null && $GLOBALS['dqFake']['links'][$dqKey]['destination'] === 'https://example.com/while-off', 'switched on: Go2My.Link points at the saved destination again');
$dqOut = dynamicQrChangeForOwner($dqAmy, $dqUuid, ['active' => 'false'], dqOptions());
dqCheck(!$dqOut['ok'] && $dqOut['errorCode'] === 'INVALID_ACTIVE', '"active": "false" (text) is refused, not read as true');
dqExec("UPDATE `tblQRRedirects` SET `suspendedAt` = NOW(), `isActive` = 0, `disabledReason` = 'abuse' WHERE `redirectID` = ?", 'i', [(int) $dqC['redirectID']]);
dqFakeModes([]);
foreach ([['destination_url' => 'https://example.com/x'], ['active' => true]] as $dqBody) {
    $dqOut = dynamicQrChangeForOwner($dqAmy, $dqUuid, $dqBody, dqOptions());
    dqCheck(!$dqOut['ok'] && $dqOut['httpCode'] === 409 && $dqOut['errorCode'] === 'REDIRECT_SUSPENDED' && dqFakeSeen() === [], 'suspended: ' . json_encode($dqBody) . ' refused with 409, nothing sent');
}
dqExec("UPDATE `tblQRRedirects` SET `suspendedAt` = NULL, `isActive` = 1, `disabledReason` = NULL WHERE `redirectID` = ?", 'i', [(int) $dqC['redirectID']]);
$dqHeld = dqRows("SELECT `redirectUUID` FROM `tblQRRedirects` WHERE `ownerUserID` = ? AND `syncState` = 'unconfirmed'", 'i', [$dqDee])[0]['redirectUUID'];
$dqOut = dynamicQrChangeForOwner($dqDee, (string) $dqHeld, ['destination_url' => 'https://example.com/x'], dqOptions());
dqCheck(!$dqOut['ok'] && $dqOut['httpCode'] === 409 && $dqOut['errorCode'] === 'REDIRECT_NOT_READY', 'a held code cannot be changed: 409 REDIRECT_NOT_READY');
dqExec("UPDATE `tblUsers` SET `emailVerifiedAt` = NULL WHERE `userID` = ?", 'i', [$dqEve]);
$dqEveUuid = dqRows('SELECT `redirectUUID` FROM `tblQRRedirects` WHERE `ownerUserID` = ?', 'i', [$dqEve])[0]['redirectUUID'];
$dqOut = dynamicQrChangeForOwner($dqEve, (string) $dqEveUuid, ['active' => false], dqOptions());
dqCheck(!$dqOut['ok'] && $dqOut['httpCode'] === 403 && $dqOut['errorCode'] === 'EMAIL_NOT_CONFIRMED', 'changing needs a confirmed email too: 403');
$dqActivity = dqRows("SELECT `additionalData` FROM `tblActivityLog` WHERE `activityType` = 'redirect_destination_changed' AND `entityID` = ? ORDER BY `activityID`", 'i', [(int) $dqC['redirectID']]);
dqCheck(count($dqActivity) >= 5 && str_contains((string) $dqActivity[0]['additionalData'], 'example.com/new'), 'every change is in the activity log with the old and new destination (' . count($dqActivity) . ' rows)');

// ==============================================================================
// J. The picture
// ==============================================================================
echo PHP_EOL . 'J. The picture: only "active", only its owner' . PHP_EOL;
loadQRGenerationFunctions();
$dqActive = fetchRedirectRowById((int) $dqC['redirectID']);
$dqPic = generateQRCode('dynamic_url', ['redirect_uuid' => (string) $dqActive['redirectUUID']], ['format' => 'png', 'size' => 300]);
dqCheck($dqPic['success'] === false, 'typed-in details (no permission from the create flow) never draw a dynamic code: ' . substr((string) $dqPic['error'], 0, 80));
dynamicQrPayloadPermit('grant', (string) $dqActive['redirectUUID']);
$dqPic = generateQRCode('dynamic_url', ['redirect_uuid' => (string) $dqActive['redirectUUID']], ['format' => 'png', 'size' => 300]);
dynamicQrPayloadPermit('revoke', (string) $dqActive['redirectUUID']);
dqCheck($dqPic['success'] === true && $dqPic['payload'] === 'https://QRmy.Link/' . $dqActive['shortCode'], 'with permission, an active code is drawn as https://QRmy.Link/<code> (' . $dqPic['payload'] . ')');
dynamicQrLinkImage((int) $dqActive['redirectID'], (string) $dqPic['uuid']);
$dqQrId = (int) dqRows('SELECT `qrID` FROM `tblQRCodes` WHERE `qrUUID` = ?', 's', [(string) $dqPic['uuid']])[0]['qrID'];
dqCheck((int) fetchRedirectRowById((int) $dqActive['redirectID'])['qrID'] === $dqQrId, 'the picture is linked to the code');
dqCheck(dynamicQrImageGate($dqQrId, $dqAmy)['allowed'] === true, 'download gate: Amy may have it');
$dqGate = dynamicQrImageGate($dqQrId, $dqBob);
dqCheck($dqGate['allowed'] === false && $dqGate['httpCode'] === 404 && $dqGate['ownerUserID'] === null, 'download gate: Bob gets 404 (and is not told whose it is)');
dqCheck(dynamicQrImageGate($dqQrId, null)['httpCode'] === 404, 'download gate: nobody signed in gets 404');
dqFakeModes([]);
dynamicQrChangeForOwner($dqAmy, (string) $dqActive['redirectUUID'], ['active' => false], dqOptions());
$dqGate = dynamicQrImageGate($dqQrId, $dqAmy);
dqCheck($dqGate['allowed'] === false && $dqGate['httpCode'] === 409 && $dqGate['errorCode'] === 'DYNAMIC_CODE_NOT_ACTIVE', 'switched off: even Amy gets 409 DYNAMIC_CODE_NOT_ACTIVE');
dynamicQrPayloadPermit('grant', (string) $dqActive['redirectUUID']);
$dqPic = generateQRCode('dynamic_url', ['redirect_uuid' => (string) $dqActive['redirectUUID']], ['format' => 'png']);
dynamicQrPayloadPermit('revoke', (string) $dqActive['redirectUUID']);
dqCheck($dqPic['success'] === false, 'switched off: not drawn even with permission');
dynamicQrChangeForOwner($dqAmy, (string) $dqActive['redirectUUID'], ['active' => true], dqOptions());
dqExec('UPDATE `tblQRRedirects` SET `suspendedAt` = NOW() WHERE `redirectID` = ?', 'i', [(int) $dqActive['redirectID']]);
dqCheck(dynamicQrImageGate($dqQrId, $dqAmy)['errorCode'] === 'REDIRECT_SUSPENDED', 'suspended: 409 REDIRECT_SUSPENDED');
dqExec('UPDATE `tblQRRedirects` SET `suspendedAt` = NULL WHERE `redirectID` = ?', 'i', [(int) $dqActive['redirectID']]);
foreach (['pending', 'unconfirmed', 'failed', 'unlinked'] as $dqState) {
    dqExec('UPDATE `tblQRRedirects` SET `syncState` = ? WHERE `redirectID` = ?', 'si', [$dqState, (int) $dqActive['redirectID']]);
    dynamicQrPayloadPermit('grant', (string) $dqActive['redirectUUID']);
    $dqPic = generateQRCode('dynamic_url', ['redirect_uuid' => (string) $dqActive['redirectUUID']], ['format' => 'png']);
    dynamicQrPayloadPermit('revoke', (string) $dqActive['redirectUUID']);
    dqCheck($dqPic['success'] === false && dynamicQrImageGate($dqQrId, $dqAmy)['allowed'] === false, '"' . $dqState . '": never drawn, never downloaded');
}
dqExec("UPDATE `tblQRRedirects` SET `syncState` = 'active' WHERE `redirectID` = ?", 'i', [(int) $dqActive['redirectID']]);
$dqPreview = generatePreview('dynamic_url', ['redirect_uuid' => (string) $dqActive['redirectUUID']], []);
dqCheck($dqPreview['success'] === false, 'the preview route cannot draw it either');

// ==============================================================================
// K. The admin switch
// ==============================================================================
echo PHP_EOL . 'K. The admin switch needs a passing connection test' . PHP_EOL;
dqSetting('dynamic_qr_enabled', '0');
$dqSave = adminSaveSetting(['key' => 'dynamic_qr_enabled'], '1', $dqAdmin);
dqCheck($dqSave['ok'] === false && $dqSave['outcome'] === 'refused' && str_contains($dqSave['message'], 'Test connection') && dynamicQrMasterSwitchOn() === false, 'never tested: switching on is refused');
$dqFailing = static function (): array {
    return ['ok' => false, 'reason' => 'auth_failed', 'lines' => ['Could not connect.']];
};
$dqPassing = static function (): array {
    return ['ok' => true, 'reason' => '', 'lines' => ['Connected.']];
};
adminSettingsTestGo2MyLink($dqAdmin, $dqFailing);
$dqSave = adminSaveSetting(['key' => 'dynamic_qr_enabled'], '1', $dqAdmin);
dqCheck($dqSave['ok'] === false && str_contains($dqSave['message'], 'did not pass'), 'the last test failed: refused');
adminSettingsTestGo2MyLink($dqAdmin, $dqPassing);
$dqSave = adminSaveSetting(['key' => 'dynamic_qr_enabled'], '1', $dqAdmin);
clearConfigCache();
dqCheck($dqSave['ok'] === true && dynamicQrMasterSwitchOn() === true, 'a passing test just now: switched on');
dqCheck(count(dqRows("SELECT `auditID` FROM `tblAdminAuditLog` WHERE `action` = 'setting.update' AND `entityKey` = 'dynamic_qr_enabled'")) === 1, '...and it is in the admin change log');
$dqSave = adminSaveSetting(['key' => 'dynamic_qr_enabled'], '0', $dqAdmin);
clearConfigCache();
dqCheck($dqSave['ok'] === true && dynamicQrMasterSwitchOn() === false, 'switching off is always allowed');
adminSaveSetting(['key' => 'go2mylink_timeout_seconds'], '7', $dqAdmin);
$dqSave = adminSaveSetting(['key' => 'dynamic_qr_enabled'], '1', $dqAdmin);
dqCheck($dqSave['ok'] === false && str_contains($dqSave['message'], 'changed after'), 'a Go2My.Link setting changed after the test: refused');
adminSettingsTestGo2MyLink($dqAdmin, $dqPassing);
dqExec("UPDATE `tblAdminAuditLog` SET `createdAt` = DATE_SUB(`createdAt`, INTERVAL 61 MINUTE) WHERE `action` LIKE 'setting.test_go2mylink%'");
$dqSave = adminSaveSetting(['key' => 'dynamic_qr_enabled'], '1', $dqAdmin);
dqCheck($dqSave['ok'] === false && str_contains($dqSave['message'], 'more than 60 minutes'), 'a passing test 61 minutes old: refused');
adminSettingsTestGo2MyLink($dqAdmin, $dqPassing);
$dqCurrent = adminSettingsLoadRows(array_keys(adminSettingsFieldsForSection('dynamic')));
$dqProcessed = adminSettingsProcessSection('dynamic', ['f' => ['dynamic_qr_enabled' => '1', 'go2mylink_timeout_seconds' => '8'], 'confirm' => ['dynamic_qr_enabled' => '1']], $dqCurrent);
dqCheck(isset($dqProcessed['errors']['dynamic_qr_enabled']), 'switching on in the same save as a connection change: refused on the form');
$dqForged = adminSettingsSaveSection($dqAdmin, 'dynamic', adminSettingsSectionFingerprint('dynamic', $dqCurrent), [['key' => 'go2mylink_timeout_seconds', 'action' => 'update', 'value' => '8'], ['key' => 'dynamic_qr_enabled', 'action' => 'update', 'value' => '1']]);
clearConfigCache();
dqCheck($dqForged['ok'] === false && dynamicQrMasterSwitchOn() === false && (int) getConfig('go2mylink_timeout_seconds', 0) === 7, '...and a hand-made save that skips the form is refused as a whole (nothing saved)');
$dqProcessed = adminSettingsProcessSection('dynamic', ['f' => ['dynamic_qr_enabled' => '1'], 'confirm' => ['dynamic_qr_enabled' => '1']], $dqCurrent);
$dqSaved = adminSettingsSaveSection($dqAdmin, 'dynamic', adminSettingsSectionFingerprint('dynamic', $dqCurrent), $dqProcessed['changes']);
clearConfigCache();
dqCheck($dqProcessed['errors'] === [] && $dqSaved['ok'] === true && dynamicQrMasterSwitchOn() === true, 'switched on through the section save after a fresh passing test');

// ==============================================================================
// L. The /admin/limits/ screen
// ==============================================================================
echo PHP_EOL . 'L. The /admin/limits/ screen' . PHP_EOL;
dqCheck(adminLimitsFindUserIdByEmail('dq-bob@dq.cuercode.invalid') === $dqBob && adminLimitsFindUserIdByEmail('DQ-BOB@dq.cuercode.invalid ') === $dqBob, 'find by email (trimmed, any capitals as registration stores them)');
dqCheck(adminLimitsFindUserIdByEmail('dq-bo@dq.cuercode.invalid') === null, 'a near-miss address finds nobody');
$dqPerson = adminLimitsFindPerson($dqAmy);
dqCheck($dqPerson['planNumber'] === 3 && (int) $dqPerson['own']['limitValue'] === 4 && $dqPerson['applies'] === 4 && $dqPerson['held'] === 4 && $dqPerson['own']['reason'] === 'Approved for the pilot', 'Amy: plan 3, own 4, applies 4, holds 4, with the reason');
$dqList = adminLimitsListOverrides();
dqCheck(count($dqList) === 1 && (int) $dqList[0]['userID'] === $dqAmy, 'the list shows the one person with their own number');
$dqToken = bin2hex(random_bytes(32));
dqExec('INSERT INTO `tblUserSessions` (`sessionID`, `userID`, `expiresAt`) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))', 'si', [$dqToken, $dqAdminID]);
$dqRender = static function (array $dqRequest) use ($dqDbName): array {
    $dqProc = proc_open([PHP_BINARY, __FILE__, '--render-limits', $dqDbName, base64_encode((string) json_encode($dqRequest))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $dqPipes, null, getenv());
    fclose($dqPipes[0]);
    $dqOut = (string) stream_get_contents($dqPipes[1]);
    $dqErr = (string) stream_get_contents($dqPipes[2]);
    fclose($dqPipes[1]);
    fclose($dqPipes[2]);
    $dqExit = proc_close($dqProc);
    $dqState = [];
    $dqHtml  = $dqOut;
    $dqAt = strrpos($dqOut, '@@STATE@@');
    if ($dqAt !== false) {
        $dqHtml  = substr($dqOut, 0, $dqAt);
        $dqState = (array) json_decode(trim(substr($dqOut, $dqAt + 9)), true);
    }
    return ['exit' => $dqExit, 'html' => $dqHtml, 'err' => $dqErr, 'state' => $dqState];
};
$dqBase = ['userID' => $dqAdminID, 'token' => $dqToken, 'method' => 'GET', 'get' => [], 'post' => [], 'reauth' => true, 'csrf' => str_repeat('b', 64)];
$dqPage = $dqRender($dqBase);
dqCheck($dqPage['exit'] === 0 && str_contains($dqPage['html'], 'People\'s own limits') && substr_count($dqPage['html'], '<h1') === 1 && $dqPage['err'] === '', 'the page renders for a Global Admin (one h1, no errors)' . substr($dqPage['err'], 0, 300));
dqCheck(str_contains($dqPage['html'], adminEscape(adminMaskEmail('dq-amy@dq.cuercode.invalid'))) && !str_contains($dqPage['html'], 'dq-amy@dq.cuercode.invalid'), 'the list shows Amy with her address masked');
dqCheck(!str_contains($dqPage['html'], '<script>') && preg_match('/\son[a-z]+="/', $dqPage['html']) === 0, 'no inline script or inline event handler');
$dqPage = $dqRender(array_merge($dqBase, ['get' => ['person' => (string) $dqAmy]]));
dqCheck(str_contains($dqPage['html'], 'dq-amy@dq.cuercode.invalid') && str_contains($dqPage['html'], 'Number that applies now') && str_contains($dqPage['html'], 'Approved for the pilot') && str_contains($dqPage['html'], 'name="own_number"'), 'a person\'s card: their numbers, the reason and the form');
$dqPage = $dqRender(array_merge($dqBase, ['method' => 'POST', 'post' => ['csrf_token' => str_repeat('b', 64), 'limits_action' => 'save', 'person' => (string) $dqBob, 'own_number' => '10', 'reason' => 'Shop pilot']]));
dqForgetLimits($dqBob);
dqCheck(limitFor('dynamic_codes', $dqBob) === 10 && str_contains(json_encode($dqPage['state']), 'own limit is now 10'), 'saving through the real page: Bob now has 10');
$dqPage = $dqRender(array_merge($dqBase, ['method' => 'POST', 'post' => ['csrf_token' => str_repeat('b', 64), 'limits_action' => 'save', 'person' => (string) $dqBob, 'own_number' => '', 'remove' => '1', 'reason' => 'Pilot over']]));
dqForgetLimits($dqBob);
dqCheck(limitFor('dynamic_codes', $dqBob) === 3, 'removing through the real page: Bob is back to the plan\'s 3');
$dqPage = $dqRender(array_merge($dqBase, ['method' => 'POST', 'reauth' => false, 'post' => ['csrf_token' => str_repeat('b', 64), 'limits_action' => 'save', 'person' => (string) $dqBob, 'own_number' => '50', 'reason' => 'No password']]));
dqForgetLimits($dqBob);
dqCheck(limitFor('dynamic_codes', $dqBob) === 3 && str_contains($dqPage['html'], 'confirm your password'), 'without a fresh password confirmation nothing is saved');
$dqPage = $dqRender(array_merge($dqBase, ['method' => 'POST', 'post' => ['csrf_token' => 'wrong', 'limits_action' => 'save', 'person' => (string) $dqBob, 'own_number' => '50', 'reason' => 'Bad token']]));
dqForgetLimits($dqBob);
dqCheck(limitFor('dynamic_codes', $dqBob) === 3 && (int) ($dqPage['state']['status'] ?? 0) === 403, 'a wrong form token: 403, nothing saved');
$dqPage = $dqRender(array_merge($dqBase, ['method' => 'POST', 'post' => ['csrf_token' => str_repeat('b', 64), 'limits_action' => 'save', 'person' => (string) $dqBob, 'own_number' => '0', 'reason' => 'Zero']]));
dqForgetLimits($dqBob);
dqCheck(limitFor('dynamic_codes', $dqBob) === 3 && str_contains($dqPage['html'], 'raises'), '0 is refused (a raise only, decision 0045)');
$dqNotAdminToken = bin2hex(random_bytes(32));
dqExec('INSERT INTO `tblUserSessions` (`sessionID`, `userID`, `expiresAt`) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))', 'si', [$dqNotAdminToken, $dqBob]);
$dqPage = $dqRender(array_merge($dqBase, ['userID' => $dqBob, 'token' => $dqNotAdminToken]));
dqCheck(!str_contains($dqPage['html'], 'People\'s own limits') && (int) ($dqPage['state']['status'] ?? 0) === 404, 'an ordinary member gets the same 404 as a missing page');

// ==============================================================================
// M. Real HTTPS against the stand-in Go2My.Link server
// ==============================================================================
echo PHP_EOL . 'M. Real HTTPS against the stand-in server' . PHP_EOL;
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'test_tls.php';
$dqTlsDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cuercode_dq_' . bin2hex(random_bytes(6));
mkdir($dqTlsDir, 0700);
$dqTls = testTlsMakeCertificates($dqTlsDir);
$dqKeyReal = 'g2ml_ItTestAb_' . str_repeat('r', 43);
$dqLog = $dqTlsDir . DIRECTORY_SEPARATOR . 'requests.log';
touch($dqLog);
$dqStandPipes = [];
$dqStand = proc_open([PHP_BINARY, dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'go2mylink_standin_server.php', $dqTls['certFile'], $dqTls['keyFile'], $dqLog, $dqKeyReal], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $dqStandPipes);
$dqStandPort = 0;
if ($dqTls['ok'] && is_resource($dqStand)) {
    stream_set_timeout($dqStandPipes[1], 10);
    if (preg_match('/^PORT (\d+)/', (string) fgets($dqStandPipes[1]), $dqPortMatch) === 1) {
        $dqStandPort = (int) $dqPortMatch[1];
    }
}
dqCheck($dqStandPort > 0, 'the stand-in server listens on 127.0.0.1 (port ' . $dqStandPort . ')');
$dqReal = static function (string $dqScenario) use ($dqStandPort, $dqKeyReal, $dqTls): array {
    return ['go2mylink' => ['settings' => ['apiBaseUrl' => 'https://127.0.0.1:' . $dqStandPort . '/' . $dqScenario . '/api/v1', 'apiKey' => $dqKeyReal, 'keyUnreadable' => false, 'timeout' => 2, 'shortLinkBase' => 'https://QRmy.Link', 'problem' => ''], 'caFile' => $dqTls['caFile']]];
};
if ($dqStandPort > 0) {
    $dqFay = dqUser('fay');
    adminSetUserLimitOverride($dqAdmin, $dqFay, 'dynamic_codes', 10, 'HTTPS checks');
    dqForgetLimits($dqFay);
    $dqOut = createDynamicRedirectForOwner($dqFay, 'https://example.com/https', $dqReal('ok'));
    dqCheck($dqOut['ok'] && $dqOut['row']['syncState'] === 'active', 'HTTPS create: active');
    $dqFayUuid = (string) $dqOut['row']['redirectUUID'];
    $dqOut = dynamicQrChangeForOwner($dqFay, $dqFayUuid, ['active' => false], $dqReal('ok'));
    $dqLook = go2mylinkLookupLink((string) $dqOut['row']['shortCode'], $dqReal('ok')['go2mylink']);
    dqCheck($dqOut['ok'] && $dqLook['ok'] && str_contains($dqLook['data']['destinationUrl'], '/unavailable/?code='), 'HTTPS switch off: the stand-in now points at the switched-off page');
    $dqOut = dynamicQrChangeForOwner($dqFay, $dqFayUuid, ['active' => true, 'destination_url' => 'https://example.com/back'], $dqReal('ok'));
    $dqLook = go2mylinkLookupLink((string) $dqOut['row']['shortCode'], $dqReal('ok')['go2mylink']);
    dqCheck($dqOut['ok'] && $dqLook['data']['destinationUrl'] === 'https://example.com/back', 'HTTPS switch on with a new destination: confirmed on the stand-in');
    $dqOut = createDynamicRedirectForOwner($dqFay, 'https://example.com/slow', $dqReal('slowcreate'));
    dqCheck(!$dqOut['ok'] && fetchRedirectRowByUuid((string) $dqOut['extra']['redirect_uuid'])['syncState'] === 'unconfirmed', 'HTTPS create answered too late: looked up, held as "unconfirmed" (domain not confirmable today)');
    $dqOut = createDynamicRedirectForOwner($dqFay, 'https://example.com/slowurl', $dqReal('slowurl'));
    dqCheck($dqOut['ok'] && $dqOut['row']['syncState'] === 'active', 'HTTPS create answered too late, look-up WITH the short address (Go2My.Link #276): recovered, active');
    $dqOut = createDynamicRedirectForOwner($dqFay, 'https://example.com/wrong', $dqReal('wrongdomain'));
    dqCheck(!$dqOut['ok'] && fetchRedirectRowByUuid((string) $dqOut['extra']['redirect_uuid'])['syncState'] === 'unconfirmed', 'HTTPS link made on another short domain: held, never printed');
    dqCheck(!str_contains((string) file_get_contents($dqLog), $dqKeyReal), 'the key never appears in what the stand-in recorded');
}
// ==============================================================================
// N. The real HTTP routes
// ==============================================================================
echo PHP_EOL . 'N. The real HTTP routes (built-in web server, stand-in Go2My.Link)' . PHP_EOL;
if ($dqStandPort > 0) {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'web_server.php';
    $dqGus = dqUser('gus');
    $dqHal = dqUser('hal');
    $dqScopes = ['generate', 'preview', 'download', 'save', 'qrcodes:read', 'qrcodes:write'];
    // 🔑 Keys are stored the way createApiKeyForUser() stores them (that
    //    function needs a signed-in browser session, which this script has not).
    $dqMakeKey = static function (int $dqUserID) use ($dqScopes): string {
        $dqRawKey = generateAPIKey();
        dqExec(
            'INSERT INTO `tblAPIKeys` (`apiKeyHash`, `apiKeyPrefix`, `apiKeyName`, `userID`, `permissions`, `rateLimitPerMin`, `rateLimitPerDay`, `isActive`) VALUES (?, ?, ?, ?, ?, 1000, 100000, 1)',
            'sssis',
            [hashAPIKey($dqRawKey), getAPIKeyPrefix($dqRawKey), 'IT', $dqUserID, (string) json_encode($dqScopes)]
        );
        return $dqRawKey;
    };
    $dqGusKey = $dqMakeKey($dqGus);
    $dqHalKey = $dqMakeKey($dqHal);
    // 🔌 The site's own settings point at the stand-in (the address by hand:
    //    the admin screen rightly refuses 127.0.0.1); the key through the
    //    admin save, so it is stored encrypted as on a real site.
    dqSetting('go2mylink_api_base_url', 'https://127.0.0.1:' . $dqStandPort . '/ok/api/v1');
    adminSaveSetting(['key' => 'go2mylink_api_key'], $dqKeyReal, $dqAdmin);
    dqSetting('dynamic_qr_enabled', '1');
    dqExec("UPDATE `tblSettings` SET `settingValue` = '1000' WHERE `settingKey` IN ('api_rate_limit_anonymous_per_min', 'api_rate_limit_anonymous_per_day')");

    $dqProbe = stream_socket_server('tcp://127.0.0.1:0');
    $dqHttpPort = (int) substr((string) stream_socket_get_name($dqProbe, false), strrpos((string) stream_socket_get_name($dqProbe, false), ':') + 1);
    fclose($dqProbe);
    $dqWebLog = tempnam(sys_get_temp_dir(), 'cuercode_it_dqweb_');
    $dqWeb = itWebServerStart(
        ['-d', 'curl.cainfo=' . $dqTls['caFile'], '-d', 'openssl.cafile=' . $dqTls['caFile'], '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $dqWebLog, '-S', '127.0.0.1:' . $dqHttpPort, '-t', $dqSiteRoot . 'public_html', __DIR__ . DIRECTORY_SEPARATOR . 'human_check_http_router.php'],
        $dqWebLog . '.out',
        ['CUERCODE_IT_HC_DB' => $dqDbName, 'CUERCODE_IT_ENCRYPTION_KEY' => ENCRYPTION_KEY] + getenv()
    );
    $dqUp = false;
    $dqWait = microtime(true) + 15;
    while (!$dqUp && microtime(true) < $dqWait) {
        $dqSock = @fsockopen('127.0.0.1', $dqHttpPort, $dqEn, $dqEs, 0.2);
        if ($dqSock !== false) {
            fclose($dqSock);
            $dqUp = true;
        } else {
            usleep(100000);
        }
    }
    dqCheck($dqWeb !== null && $dqUp, 'the test web server is up on 127.0.0.1:' . $dqHttpPort);

    $dqHttp = static function (string $dqMethod, string $dqPath, string $dqKey, ?array $dqBody = null) use ($dqHttpPort): array {
        $dqHeaders = "X-API-Key: " . $dqKey . "\r\nAccept: application/json\r\n";
        $dqContent = '';
        if ($dqBody !== null) {
            $dqContent = (string) json_encode($dqBody);
            $dqHeaders .= "Content-Type: application/json\r\n";
        }
        $dqContext = stream_context_create(['http' => ['method' => $dqMethod, 'header' => $dqHeaders, 'content' => $dqContent, 'ignore_errors' => true, 'timeout' => 30]]);
        $dqRaw = @file_get_contents('http://127.0.0.1:' . $dqHttpPort . $dqPath, false, $dqContext);
        $dqStatus = 0;
        $dqHead = http_get_last_response_headers();
        if (is_array($dqHead) && isset($dqHead[0]) && preg_match('/^HTTP\/\S+ (\d{3})/', $dqHead[0], $dqM) === 1) {
            $dqStatus = (int) $dqM[1];
        }
        $dqJson = json_decode((string) $dqRaw, true);
        if (!is_array($dqJson)) {
            $dqJson = [];
        }
        return ['status' => $dqStatus, 'json' => $dqJson, 'raw' => (string) $dqRaw];
    };

    $dqResp = $dqHttp('POST', '/api/v1/generate', $dqGusKey, ['type' => 'dynamic_url', 'input' => ['destination_url' => 'https://example.com/http'], 'customization' => ['format' => 'png']]);
    $dqDyn = [];
    if (isset($dqResp['json']['data']['dynamic'])) {
        $dqDyn = $dqResp['json']['data']['dynamic'];
    }
    $dqGusRows = dqRows('SELECT `syncState`, `lastSyncError` FROM `tblQRRedirects` WHERE `ownerUserID` = ?', 'i', [$dqGus]);
    dqCheck($dqResp['status'] === 200 && isset($dqDyn['state']) && $dqDyn['state'] === 'active' && str_starts_with((string) $dqResp['json']['data']['image'], 'data:image/png;base64,'), 'POST /generate dynamic_url: 200, an active code and its picture (' . $dqResp['status'] . ' ' . json_encode($dqGusRows) . ')');
    dqCheck(isset($dqDyn['short_url']) && preg_match('#^https://QRmy\.Link/[A-Za-z0-9]{7}$#', (string) $dqDyn['short_url']) === 1, '...its short address is https://QRmy.Link/<code>: ' . ($dqDyn['short_url'] ?? ''));
    $dqPicUuid = (string) ($dqResp['json']['data']['uuid'] ?? '');
    $dqCodeUuid = (string) ($dqDyn['uuid'] ?? '');

    $dqResp = $dqHttp('GET', '/api/v1/download/' . $dqPicUuid, $dqGusKey);
    dqCheck($dqResp['status'] === 200 && str_starts_with($dqResp['raw'], "\x89PNG"), 'GET /download: the owner gets the PNG');
    $dqResp = $dqHttp('GET', '/api/v1/download/' . $dqPicUuid, $dqHalKey);
    dqCheck($dqResp['status'] === 404 && ($dqResp['json']['error_code'] ?? '') === 'NOT_FOUND', 'GET /download: someone else gets 404');
    $dqResp = $dqHttp('POST', '/api/v1/save', $dqHalKey, ['uuid' => $dqPicUuid]);
    dqCheck($dqResp['status'] === 404, 'POST /save: someone else gets 404');
    $dqResp = $dqHttp('POST', '/api/v1/save', $dqGusKey, ['uuid' => $dqPicUuid]);
    dqCheck($dqResp['status'] === 409 && ($dqResp['json']['error_code'] ?? '') === 'DYNAMIC_CODE_NOT_SAVEABLE', 'POST /save: the owner cannot add it to saved codes (409 DYNAMIC_CODE_NOT_SAVEABLE)');
    $dqResp = $dqHttp('GET', '/api/v1/qrcodes/' . $dqCodeUuid . '/destination', $dqHalKey);
    dqCheck($dqResp['status'] === 404, 'GET destination: someone else gets 404');
    $dqResp = $dqHttp('PATCH', '/api/v1/qrcodes/' . $dqCodeUuid . '/destination', $dqHalKey, ['active' => false]);
    dqCheck($dqResp['status'] === 404 && ($dqResp['json']['error_code'] ?? '') === 'NOT_FOUND', 'PATCH destination: someone else gets 404');
    $dqResp = $dqHttp('PATCH', '/api/v1/qrcodes/' . $dqCodeUuid . '/destination', $dqGusKey, ['destination_url' => 'https://example.com/http-new']);
    dqCheck($dqResp['status'] === 200 && ($dqResp['json']['data']['destination_url'] ?? '') === 'https://example.com/http-new', 'PATCH destination: the owner changes it (Go2My.Link first, over HTTPS)');
    $dqResp = $dqHttp('PATCH', '/api/v1/qrcodes/' . $dqCodeUuid . '/destination', $dqGusKey, ['active' => false]);
    dqCheck($dqResp['status'] === 200 && ($dqResp['json']['data']['state'] ?? '') === 'switched_off', 'PATCH active=false: switched off');
    $dqResp = $dqHttp('GET', '/api/v1/download/' . $dqPicUuid, $dqGusKey);
    dqCheck($dqResp['status'] === 409 && ($dqResp['json']['error_code'] ?? '') === 'DYNAMIC_CODE_NOT_ACTIVE', 'GET /download while switched off: 409 DYNAMIC_CODE_NOT_ACTIVE, even for the owner');
    $dqResp = $dqHttp('POST', '/api/v1/preview', $dqGusKey, ['type' => 'dynamic_url', 'input' => ['redirect_uuid' => $dqCodeUuid]]);
    dqCheck($dqResp['status'] === 422, 'POST /preview with dynamic_url and the code\'s id: refused (422)');
    $dqResp = $dqHttp('POST', '/api/v1/generate', $dqGusKey, ['type' => 'dynamic_url', 'input' => ['redirect_uuid' => $dqCodeUuid]]);
    dqCheck($dqResp['status'] === 400 && ($dqResp['json']['error_code'] ?? '') === 'MISSING_FIELD', 'POST /generate dynamic_url with a typed-in id instead of a destination: 400, nothing drawn');
    $dqResp = $dqHttp('PATCH', '/api/v1/qrcodes/' . $dqCodeUuid . '/destination', $dqGusKey, ['active' => true]);
    $dqResp2 = $dqHttp('GET', '/api/v1/download/' . $dqPicUuid, $dqGusKey);
    dqCheck($dqResp['status'] === 200 && $dqResp2['status'] === 200, 'switched back on: the owner can download it again');
    dqExec("UPDATE `tblUsers` SET `emailVerifiedAt` = NULL WHERE `userID` = ?", 'i', [$dqHal]);
    $dqResp = $dqHttp('POST', '/api/v1/generate', $dqHalKey, ['type' => 'dynamic_url', 'input' => ['destination_url' => 'https://example.com/hal']]);
    dqCheck($dqResp['status'] === 403 && ($dqResp['json']['error_code'] ?? '') === 'EMAIL_NOT_CONFIRMED' && dqStates($dqHal) === [], 'POST /generate by an account with no confirmed email: 403, nothing saved');
    $dqResp = $dqHttp('POST', '/api/v1/generate', '', ['type' => 'dynamic_url', 'input' => ['destination_url' => 'https://example.com/anon']]);
    dqCheck($dqResp['status'] === 401, 'POST /generate with no sign-in: 401');

    if ($dqWeb !== null) {
        itWebServerStop($dqWeb);
    }
    $dqWebLogText = (string) @file_get_contents($dqWebLog) . (string) @file_get_contents($dqWebLog . '.out');
    dqCheck(!str_contains($dqWebLogText, $dqKeyReal) && !str_contains($dqWebLogText, 'Fatal'), 'the web server log has no key and no fatal error');
    @unlink($dqWebLog);
    @unlink($dqWebLog . '.out');
}

if (is_resource($dqStand)) {
    fclose($dqStandPipes[0]);
    fclose($dqStandPipes[1]);
    fclose($dqStandPipes[2]);
    proc_close($dqStand);
}
foreach ((array) glob($dqTlsDir . DIRECTORY_SEPARATOR . '*') as $dqFile) {
    @unlink((string) $dqFile);
}
@rmdir($dqTlsDir);

// ==============================================================================
// 🔐 No secret anywhere, then clean up
// ==============================================================================
$dqLogText = (string) file_get_contents($dqErrorLog) . implode("\n", $GLOBALS['dqLogged']);
dqCheck(!str_contains($dqLogText, str_repeat('k', 43)) && !str_contains($dqLogText, str_repeat('r', 43)), 'no Go2My.Link key in any log line');
@unlink($dqErrorLog);

$dqCleanup = new \mysqli(dqEnv('HOST'), dqEnv('USER'), dqEnv('PASS'), '', (int) $dqPort);
$dqCleanup->query('DROP DATABASE IF EXISTS `' . $dqDbName . '`');
$dqCleanup->close();

echo PHP_EOL . $GLOBALS['dqChecks'] . ' checks. ';
if ($GLOBALS['dqFailures'] === 0) {
    echo 'ALL CHECKS PASSED' . PHP_EOL;
    exit(0);
}
echo $GLOBALS['dqFailures'] . ' CHECK(S) FAILED' . PHP_EOL;
exit(1);
