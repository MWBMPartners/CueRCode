<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Real-database check: link safety, takedowns, abuse reports, monitoring (WP4e-WP4g)
 * ==============================================================================
 *
 * Path: tests/integration/dynamic_qr_abuse_real_mysql.php
 *
 * Proves, against a real MySQL or MariaDB server, the parts that need one:
 *
 *   A  set-up: install.sql, seed_data.sql and every upgrade (0008 included)
 *   B  the link-safety check at create (#168): an unsafe destination is
 *      refused, nothing is saved or sent to Go2My.Link, the hit is recorded
 *      with the person; a safe one is made with "safe" kept on the row; a
 *      provider that cannot answer lets it through ("unchecked") and is
 *      logged once; an address listed earlier is refused without asking again
 *   C  the check at a change and at switching back on: unsafe refused,
 *      nothing sent to Go2My.Link, nothing changed here
 *   D  the daily re-check: a code whose destination is now listed is
 *      suspended (here first, with a "system" row in the admin change log),
 *      Go2My.Link is pointed at the neutral page, the owner and the abuse
 *      mailbox are emailed; nothing is sent without a declared site copy or
 *      without a key; it stops early when the provider is down
 *   E  a takedown Go2My.Link could not take is sent again by the cron step,
 *      only with a declared site copy
 *   F  an owner's change in flight when the code is suspended: the change is
 *      refused and the takedown is sent again, so Go2My.Link ends on the
 *      neutral page (rule 12)
 *
 * Nothing is sent to Google, Go2My.Link or anywhere else: scripted stand-ins
 * answer in-process. (tests/test_url_reputation.php proves the real HTTPS
 * conversation with a stand-in Web Risk server.)
 *
 * ⚠️ SAFETY: it creates and DROPS a whole database. It refuses to run (exit 2)
 *    unless the database name starts with "cuercode_it_". Connection details
 *    come only from CUERCODE_IT_DB_* environment variables.
 *
 * Run (see tests/README.md):
 *     CUERCODE_IT_DB_HOST=127.0.0.1 CUERCODE_IT_DB_PORT=3306 \
 *     CUERCODE_IT_DB_NAME=cuercode_it_abuse CUERCODE_IT_DB_USER=root CUERCODE_IT_DB_PASS= \
 *       php tests/integration/dynamic_qr_abuse_real_mysql.php
 *
 * Exit codes: 0 all passed | 1 a check failed or crashed | 2 refused.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (WP4e, #168)
 */

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
    }
    exit('Access denied.');
}

$daSiteRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'CueRCode.net' . DIRECTORY_SEPARATOR;

/**
 * 🌍 One CUERCODE_IT_DB_* value ('' when unset).
 *
 * @param string $daKey
 * @return string
 */
function daEnv(string $daKey): string
{
    $daValue = getenv('CUERCODE_IT_DB_' . $daKey);
    if ($daValue === false) {
        return '';
    }
    return $daValue;
}

/**
 * 🔌 Define the database constants the site's start-up reads.
 *
 * @param string $daDbName
 * @return void
 */
function daDefineDatabase(string $daDbName): void
{
    $daPort = daEnv('PORT');
    if ($daPort === '') {
        $daPort = '3306';
    }
    define('DB_HOST', daEnv('HOST'));
    define('DB_PORT', (int) $daPort);
    define('DB_USER', daEnv('USER'));
    define('DB_PASS', daEnv('PASS'));
    define('DB_NAME', $daDbName);
    define('ENCRYPTION_KEY', bin2hex(random_bytes(16)));
}

// ==============================================================================
// 🎭 A scripted Go2My.Link and a scripted Google Web Risk, in-process
// ==============================================================================
$GLOBALS['daGo2'] = ['links' => [], 'modes' => [], 'requests' => [], 'onPut' => null];
$GLOBALS['daRisk'] = ['listed' => [], 'down' => false, 'requests' => 0];

/**
 * 🎭 The transport fn_go2mylink.php calls instead of the network.
 *
 * @param array $daRequest
 * @param int   $daTimeout
 * @return array
 */
function daGo2Transport(array $daRequest, int $daTimeout): array
{
    $daFake   = &$GLOBALS['daGo2'];
    $daRoute  = substr((string) $daRequest['url'], strlen('https://go2my.test/api/v1/'));
    $daMethod = (string) $daRequest['method'];
    $daFake['requests'][] = $daMethod . ' ' . $daRoute;

    $daJson = static function (int $daStatus, array $daBody): array {
        return ['ok' => true, 'status' => $daStatus, 'body' => (string) json_encode($daBody), 'retryAfter' => 0, 'errno' => 0, 'tooLarge' => false, 'sent' => true];
    };
    $daRow = static function (array $daLink): array {
        return ['short_code' => $daLink['code'], 'destination_url' => $daLink['destination'], 'title' => null, 'is_active' => true, 'click_count' => $daLink['clicks'],
                'created_at' => '2026-11-28 10:00:00', 'updated_at' => '2026-11-28 10:00:00', 'start_date' => null, 'end_date' => null];
    };

    if (in_array('down', $daFake['modes'], true)) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'retryAfter' => 0, 'errno' => 7, 'tooLarge' => false, 'sent' => false];
    }

    $daBody = json_decode((string) $daRequest['body'], true);
    $daCode = '';
    if (preg_match('#^urls/([A-Za-z0-9_-]+)$#', $daRoute, $daMatch) === 1) {
        $daCode = strtolower($daMatch[1]);
    }

    if ($daMethod === 'POST' && $daRoute === 'urls') {
        $daNew = (string) $daBody['custom_code'];
        $daFake['links'][strtolower($daNew)] = ['code' => $daNew, 'destination' => (string) $daBody['destination_url'], 'clicks' => 0];
        return $daJson(201, ['status' => 'success', 'data' => ['short_code' => $daNew, 'short_url' => 'https://QRmy.Link/' . $daNew, 'qr_code_external_uuid' => strtolower((string) $daBody['qr_external_uuid'])]]);
    }
    if ($daMethod === 'GET' && $daCode !== '') {
        if (!isset($daFake['links'][$daCode])) {
            return $daJson(404, ['status' => 'error', 'error' => ['code' => 404, 'message' => 'No such short URL in this account.', 'field' => null]]);
        }
        return $daJson(200, ['status' => 'success', 'data' => $daRow($daFake['links'][$daCode])]);
    }
    if ($daMethod === 'PUT' && $daCode !== '') {
        if (in_array('putDown', $daFake['modes'], true)) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'retryAfter' => 0, 'errno' => 7, 'tooLarge' => false, 'sent' => false];
        }
        if (!isset($daFake['links'][$daCode])) {
            return $daJson(404, ['status' => 'error', 'error' => ['code' => 404, 'message' => 'No such short URL in this account.', 'field' => null]]);
        }
        // 🏃 Something that happens while this PUT is "on its way" (scenario F).
        if (is_callable($daFake['onPut'])) {
            $daOnPut = $daFake['onPut'];
            $daFake['onPut'] = null;
            $daOnPut();
        }
        $daFake['links'][$daCode]['destination'] = (string) $daBody['destination_url'];
        return $daJson(200, ['status' => 'success', 'data' => $daRow($daFake['links'][$daCode])]);
    }

    return $daJson(404, ['status' => 'error', 'error' => ['code' => 404, 'message' => 'The requested endpoint does not exist.', 'field' => null]]);
}

/**
 * 🎭 The transport fn_url_reputation.php calls instead of Google.
 *
 * @param array $daRequest
 * @param int   $daTimeout
 * @return array
 */
function daRiskTransport(array $daRequest, int $daTimeout): array
{
    $GLOBALS['daRisk']['requests']++;
    if ($GLOBALS['daRisk']['down'] === true) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'errno' => 28, 'sent' => true];
    }
    $daUri = '';
    if (preg_match('/[?&]uri=([^&]*)/', (string) $daRequest['url'], $daMatch) === 1) {
        $daUri = rawurldecode($daMatch[1]);
    }
    if (in_array($daUri, $GLOBALS['daRisk']['listed'], true)) {
        return ['ok' => true, 'status' => 200, 'body' => (string) json_encode(['threat' => ['threatTypes' => ['SOCIAL_ENGINEERING'], 'expireTime' => gmdate('Y-m-d\TH:i:s\Z', time() + 600)]]), 'errno' => 0, 'sent' => true];
    }
    return ['ok' => true, 'status' => 200, 'body' => '{}', 'errno' => 0, 'sent' => true];
}

/**
 * ⚙️ The options every flow is given: the two stand-ins.
 *
 * @param array<string, mixed> $daExtra
 * @return array<string, mixed>
 */
function daOptions(array $daExtra = []): array
{
    return array_replace_recursive([
        'go2mylink'  => [
            'settings'  => [
                'apiBaseUrl'    => 'https://go2my.test/api/v1',
                'apiKey'        => 'g2ml_ItTestAb_' . str_repeat('k', 43),
                'keyUnreadable' => false,
                'timeout'       => 2,
                'shortLinkBase' => 'https://QRmy.Link',
                'problem'       => '',
            ],
            'transport' => 'daGo2Transport',
            'logger'    => static function (string $daLine): void {
                $GLOBALS['daLogged'][] = $daLine;
            },
        ],
        'linkSafety' => [
            'settings'  => ['providers' => ['webrisk' => ['key' => 'AIzaItTestKey' . str_repeat('r', 26), 'unreadable' => false]], 'timeout' => 2, 'listProblem' => ''],
            'transport' => 'daRiskTransport',
            'logger'    => static function (string $daLine): void {
                $GLOBALS['daLogged'][] = $daLine;
            },
        ],
        'server'      => ['CUERCODE_SITE_COPY' => 'live'],
        'commandLine' => true,
    ], $daExtra);
}
$GLOBALS['daLogged'] = [];

// ==============================================================================
// 🛡️ Safety, then a fresh database
// ==============================================================================
$daDbName = daEnv('NAME');
if (preg_match('/^cuercode_it_[a-z0-9_]+$/', $daDbName) !== 1) {
    fwrite(STDERR, 'Refusing to run: CUERCODE_IT_DB_NAME must start with "cuercode_it_" (this script creates and drops a whole database).' . PHP_EOL);
    exit(2);
}
if (daEnv('HOST') === '' || daEnv('USER') === '') {
    fwrite(STDERR, 'Refusing to run: set CUERCODE_IT_DB_HOST and CUERCODE_IT_DB_USER.' . PHP_EOL);
    exit(2);
}
$daPort = daEnv('PORT');
if ($daPort === '') {
    $daPort = '3306';
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$daServer   = null;
$daDeadline = microtime(true) + 120;
$daLastErr  = '';
while ($daServer === null && microtime(true) < $daDeadline) {
    try {
        $daServer = new \mysqli(daEnv('HOST'), daEnv('USER'), daEnv('PASS'), '', (int) $daPort);
    } catch (\Throwable $daError) {
        $daLastErr = $daError->getMessage();
        usleep(500000);
    }
}
if ($daServer === null) {
    fwrite(STDERR, 'Refusing: the test database server could not be reached (' . $daLastErr . ').' . PHP_EOL);
    exit(2);
}
echo 'Server: ' . $daServer->server_info . PHP_EOL;

$daServer->query('DROP DATABASE IF EXISTS `' . $daDbName . '`');
$daServer->query('CREATE DATABASE `' . $daDbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$daServer->select_db($daDbName);
foreach (['install.sql', 'seed_data.sql'] as $daSqlFile) {
    $daServer->multi_query((string) file_get_contents($daSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . $daSqlFile));
    do {
        $daStored = $daServer->store_result();
        if ($daStored instanceof \mysqli_result) {
            $daStored->free();
        }
    } while ($daServer->more_results() && $daServer->next_result());
}
$daServer->close();

$daErrorLog = tempnam(sys_get_temp_dir(), 'cuercode_it_da_');
ini_set('log_errors', '1');
ini_set('error_log', $daErrorLog);

daDefineDatabase($daDbName);
require_once $daSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once $daSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_migrations.php';
require_once $daSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_dynamic_qr_takedown.php';
restore_error_handler();
ini_set('display_errors', 'stderr');
set_exception_handler(static function (\Throwable $daError): void {
    fwrite(STDERR, 'Integration check crashed: ' . get_class($daError) . ': ' . $daError->getMessage() . ' at ' . $daError->getFile() . ':' . $daError->getLine() . PHP_EOL);
    exit(1);
});
$_SERVER['REMOTE_ADDR']     = '203.0.113.61';
$_SERVER['HTTP_USER_AGENT'] = 'integration-check';

// ==============================================================================
// 🧰 Helpers
// ==============================================================================
$GLOBALS['daFailures'] = 0;
$GLOBALS['daChecks']   = 0;

/**
 * ✅ One check.
 *
 * @param bool   $daPassed
 * @param string $daWhat
 * @return void
 */
function daCheck(bool $daPassed, string $daWhat): void
{
    $GLOBALS['daChecks']++;
    if (!$daPassed) {
        $GLOBALS['daFailures']++;
        echo '    FAIL ' . $daWhat . PHP_EOL;
        return;
    }
    echo '    ok   ' . $daWhat . PHP_EOL;
}

/**
 * 🗄️ Rows from a query.
 *
 * @param string $daSql
 * @param string $daTypes
 * @param array  $daParams
 * @return array<int, array<string, mixed>>
 */
function daRows(string $daSql, string $daTypes = '', array $daParams = []): array
{
    $daStmt = dbQuery($daSql, $daTypes, $daParams);
    $daOut  = $daStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $daStmt->close();
    return $daOut;
}

/**
 * 🗄️ Run a statement.
 *
 * @param string $daSql
 * @param string $daTypes
 * @param array  $daParams
 * @return void
 */
function daExec(string $daSql, string $daTypes = '', array $daParams = []): void
{
    $daStmt = dbQuery($daSql, $daTypes, $daParams);
    $daStmt->close();
}

/**
 * 👤 A new account; returns its number.
 *
 * @param string $daName
 * @param int    $daRole
 * @return int
 */
function daUser(string $daName, int $daRole = 3): int
{
    $daEmail = 'da-' . $daName . '@da.cuercode.invalid';
    daExec('INSERT INTO `tblUsers` (`email`, `passwordHash`, `status`, `emailVerifiedAt`, `roleID`, `displayName`) VALUES (?, ?, ?, ?, ?, ?)', 'ssssis', [$daEmail, password_hash('Da-pass-' . $daName, PASSWORD_DEFAULT), 'active', '2026-11-01 09:00:00', $daRole, 'DA ' . $daName]);
    return (int) daRows('SELECT `userID` FROM `tblUsers` WHERE `email` = ?', 's', [$daEmail])[0]['userID'];
}

/**
 * 🔢 A setting, straight into the table (and the request cache forgotten).
 *
 * @param string $daKey
 * @param string $daValue
 * @return void
 */
function daSetting(string $daKey, string $daValue): void
{
    daExec('UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingKey` = ?', 'ss', [$daValue, $daKey]);
    clearConfigCache();
}

/**
 * 🔎 One code's takedown columns.
 *
 * @param int $daId
 * @return array<string, mixed>
 */
function daCode(int $daId): array
{
    return daRows('SELECT `redirectID`, `redirectUUID`, `shortCode`, `syncState`, `destinationURL`, `isActive`, `suspendedAt`, `suspendedByUserID`, `suspensionNote`, `suspensionAppliedAt`, `safetyVerdict`, `safetyCheckedAt` FROM `tblQRRedirects` WHERE `redirectID` = ?', 'i', [$daId])[0];
}

/**
 * 🌐 Where the stand-in Go2My.Link points a code now.
 *
 * @param array $daRow
 * @return string
 */
function daRemote(array $daRow): string
{
    $daKey = strtolower((string) $daRow['shortCode']);
    if (!isset($GLOBALS['daGo2']['links'][$daKey])) {
        return '';
    }
    return (string) $GLOBALS['daGo2']['links'][$daKey]['destination'];
}

/**
 * 📬 Emails queued to an address since a queue id.
 *
 * @param string $daTo
 * @param int    $daAfter
 * @return array<int, array<string, mixed>>
 */
function daEmails(string $daTo, int $daAfter): array
{
    return daRows('SELECT `emailID`, `subject`, `paramsJSON` FROM `tblEmailQueue` WHERE `toAddress` = ? AND `emailID` > ? ORDER BY `emailID`', 'si', [$daTo, $daAfter]);
}

/**
 * 📬 The newest email queue id (0 when none).
 *
 * @return int
 */
function daLastEmailId(): int
{
    $daRow = daRows('SELECT COALESCE(MAX(`emailID`), 0) AS `last` FROM `tblEmailQueue`');
    return (int) $daRow[0]['last'];
}

echo 'Connected. Nothing is sent to Google, Go2My.Link or anywhere else.' . PHP_EOL;

// ==============================================================================
// A. Set-up
// ==============================================================================
echo PHP_EOL . 'A. Set-up' . PHP_EOL;
$daUpgrade = migrationRun(getDB(), 'apply', 'test', migrationDefaultDirectory(), migrationDefaultInstallSqlPath());
daCheck($daUpgrade['status'] === 'ok', 'every database upgrade applied, 0008 included (' . (string) $daUpgrade['status'] . ')');
dynamicQrUpgrade0008Ready(true);
daCheck(dynamicQrUpgrade0008Ready() === true && urlReputationHitsTableReady() === true, 'upgrade 0008: the takedown and safety columns and tblLinkSafetyHits exist');
daSetting('site_url', 'https://cuercode.net');
daSetting('dynamic_redirect_base_url', 'https://QRmy.Link');
daSetting('dynamic_qr_enabled', '1');
daSetting('email_enabled', '1');

$daAmy   = daUser('amy');
$daBob   = daUser('bob');
$daAdmin = daUser('admin', 1);
// 🎚️ Bob makes many codes in this script: his own raised number (0031, 0045).
daExec("INSERT INTO `tblUserLimitOverrides` (`userID`, `limitKey`, `limitValue`, `reason`) VALUES (?, 'dynamic_codes', 50, 'integration test')", 'i', [$daBob]);
featuresCacheStore('clear', 'limit_override:' . $daBob . ':dynamic_codes');

// ==============================================================================
// B. The link-safety check at create (#168)
// ==============================================================================
echo PHP_EOL . 'B. The link-safety check when a code is made' . PHP_EOL;
$GLOBALS['daRisk']['listed'] = ['https://evil.example/login'];
$GLOBALS['daGo2']['requests'] = [];
$daOut = createDynamicRedirectForOwner($daAmy, 'https://evil.example/login', daOptions());
daCheck(!$daOut['ok'] && $daOut['httpCode'] === 422 && $daOut['errorCode'] === 'DESTINATION_UNSAFE' && str_contains($daOut['message'], 'Google Web Risk'), 'a destination Web Risk lists: 422 DESTINATION_UNSAFE, naming the service');
daCheck(daRows('SELECT COUNT(*) AS `c` FROM `tblQRRedirects` WHERE `ownerUserID` = ?', 'i', [$daAmy])[0]['c'] === 0 && $GLOBALS['daGo2']['requests'] === [], '...nothing saved here, nothing sent to Go2My.Link, no place taken');
$daHits = daRows('SELECT `destinationURL`, `provider`, `threatTypes`, `occasion`, `redirectID`, `userID`, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), `expiresAt`) AS `left` FROM `tblLinkSafetyHits`');
daCheck(count($daHits) === 1 && $daHits[0]['destinationURL'] === 'https://evil.example/login' && $daHits[0]['provider'] === 'webrisk' && $daHits[0]['threatTypes'] === 'SOCIAL_ENGINEERING' && $daHits[0]['occasion'] === 'create' && (int) $daHits[0]['userID'] === $daAmy && $daHits[0]['redirectID'] === null && (int) $daHits[0]['left'] > 500, '...recorded in tblLinkSafetyHits: the address, Web Risk, the list, "create", Amy, kept until Google\'s expiry');

$GLOBALS['daRisk']['requests'] = 0;
$daOut = createDynamicRedirectForOwner($daAmy, 'https://evil.example/login', daOptions());
daCheck(!$daOut['ok'] && $daOut['errorCode'] === 'DESTINATION_UNSAFE' && $GLOBALS['daRisk']['requests'] === 0, 'the same address again: refused from the record, WITHOUT asking Google again (its caching rule)');

$daOut = createDynamicRedirectForOwner($daAmy, 'https://example.com/menu', daOptions());
$daMenu = (int) $daOut['row']['redirectID'];
$daRow  = daCode($daMenu);
daCheck($daOut['ok'] && $daRow['syncState'] === 'active' && $daRow['safetyVerdict'] === 'safe' && $daRow['safetyCheckedAt'] !== null, 'a safe destination: made, "safe" and the time kept on the row');

$GLOBALS['daRisk']['down'] = true;
$GLOBALS['daLogged']       = [];
$daOut = createDynamicRedirectForOwner($daAmy, 'https://example.com/while-down', daOptions(['linkSafety' => ['logDue' => 'humanCheckStateLogDue']]));
$daDown = (int) $daOut['row']['redirectID'];
daCheck($daOut['ok'] && daCode($daDown)['safetyVerdict'] === 'unchecked', 'Web Risk does not answer: the code is made anyway (fail-open, design 2.3), marked "unchecked"');
$daLogLines = count($GLOBALS['daLogged']);
createDynamicRedirectForOwner($daBob, 'https://example.com/bob-while-down', daOptions(['linkSafety' => ['logDue' => 'humanCheckStateLogDue']]));
daCheck($daLogLines === 1 && count($GLOBALS['daLogged']) === 1 && str_contains($GLOBALS['daLogged'][0], 'did not answer in time'), '...logged once in plain words; the second time within 10 minutes is not logged again (bounded, through tblHumanCheckState)');
$GLOBALS['daRisk']['down'] = false;

// ==============================================================================
// C. The check at a change
// ==============================================================================
echo PHP_EOL . 'C. The link-safety check when a destination changes' . PHP_EOL;
$daRow = daCode($daMenu);
$GLOBALS['daGo2']['requests'] = [];
$daOut = dynamicQrChangeForOwner($daAmy, (string) $daRow['redirectUUID'], ['destination_url' => 'https://evil.example/login'], daOptions());
daCheck(!$daOut['ok'] && $daOut['httpCode'] === 422 && $daOut['errorCode'] === 'DESTINATION_UNSAFE' && $GLOBALS['daGo2']['requests'] === [] && daCode($daMenu)['destinationURL'] === 'https://example.com/menu', 'changing to a listed address: 422, nothing sent to Go2My.Link, nothing changed here');
$daOut = dynamicQrChangeForOwner($daAmy, (string) $daRow['redirectUUID'], ['active' => false], daOptions());
daCheck($daOut['ok'] && daRemote($daRow) === 'https://cuercode.net/unavailable/?code=' . $daRow['shortCode'], 'switched off: Go2My.Link points at the neutral page');
$GLOBALS['daRisk']['listed'][] = 'https://example.com/menu';
$GLOBALS['daGo2']['requests'] = [];
$daOut = dynamicQrChangeForOwner($daAmy, (string) $daRow['redirectUUID'], ['active' => true], daOptions());
daCheck(!$daOut['ok'] && $daOut['errorCode'] === 'DESTINATION_UNSAFE' && $GLOBALS['daGo2']['requests'] === [] && (int) daCode($daMenu)['isActive'] === 0, 'switching back on when the saved destination is now listed: refused, it stays off');
$GLOBALS['daRisk']['listed'] = ['https://evil.example/login'];
daExec('DELETE FROM `tblLinkSafetyHits` WHERE `destinationURL` = ?', 's', ['https://example.com/menu']);
$daOut = dynamicQrChangeForOwner($daAmy, (string) $daRow['redirectUUID'], ['active' => true, 'destination_url' => 'https://example.com/menu-2'], daOptions());
daCheck($daOut['ok'] && daRemote($daRow) === 'https://example.com/menu-2' && daCode($daMenu)['safetyVerdict'] === 'safe', 'a safe new destination: changed, Go2My.Link points at it, "safe" kept');

// ==============================================================================
// D. The daily re-check
// ==============================================================================
echo PHP_EOL . 'D. The daily re-check suspends a code whose destination is now listed' . PHP_EOL;
daExec('UPDATE `tblQRRedirects` SET `safetyCheckedAt` = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)');
$GLOBALS['daRisk']['listed'][] = 'https://example.com/menu-2';
$GLOBALS['daRisk']['requests'] = 0;
$GLOBALS['daGo2']['requests']  = [];

$daNoCopy = dynamicQrRecheckSafety(daOptions(['server' => ['CUERCODE_SITE_COPY' => '']]));
daCheck($daNoCopy['ok'] && $daNoCopy['skipped'] === 'copy_unknown' && $daNoCopy['left'] >= 3 && $GLOBALS['daRisk']['requests'] === 0 && $GLOBALS['daGo2']['requests'] === [], 'a cron run that does not declare its site copy: nothing sent to Google or Go2My.Link (#181), and it says how many are due');
daCheck(str_contains(implode(' ', dynamicQrRecheckLines($daNoCopy)), 'CUERCODE_SITE_COPY'), '...and tells the owner how to fix the cron line');
$daNoKey = dynamicQrRecheckSafety(daOptions(['linkSafety' => ['settings' => ['providers' => ['webrisk' => ['key' => '', 'unreadable' => false]]]]]));
daCheck($daNoKey['ok'] && $daNoKey['skipped'] === 'not_configured' && $GLOBALS['daRisk']['requests'] === 0, 'no key: not run, nothing sent, and it says so');

$daMailbox  = 'abuse@cuercode.net';
$daAmyEmail = 'da-amy@da.cuercode.invalid';
$daBefore   = daLastEmailId();
$daRecheck  = dynamicQrRecheckSafety(daOptions());
$daRow      = daCode($daMenu);
daCheck($daRecheck['ok'] && $daRecheck['checked'] >= 3 && $daRecheck['unsafe'] === 1 && $daRecheck['suspended'] === 1 && $daRecheck['left'] === 0, 'the re-check asked about every due code: one is listed now, and was suspended (' . json_encode($daRecheck) . ')');
daCheck($daRow['suspendedAt'] !== null && $daRow['suspendedByUserID'] === null && str_contains((string) $daRow['suspensionNote'], 'Google Web Risk') && $daRow['safetyVerdict'] === 'unsafe', '...suspended here: when, "automatically" (no admin), why');
daCheck($daRow['suspensionAppliedAt'] !== null && daRemote($daRow) === 'https://cuercode.net/unavailable/?code=' . $daRow['shortCode'], '...and Go2My.Link now points it at the neutral page (confirmed, so suspensionAppliedAt is filled)');
$daAudit = daRows("SELECT `actorType`, `actorUserID`, `actorLabel`, `entityType`, `entityKey`, `outcome`, `reason` FROM `tblAdminAuditLog` WHERE `action` = 'dynamic_code.suspend'");
daCheck(count($daAudit) === 1 && $daAudit[0]['actorType'] === 'system' && $daAudit[0]['actorUserID'] === null && str_contains((string) $daAudit[0]['actorLabel'], 'link-safety') && $daAudit[0]['entityKey'] === $daRow['redirectUUID'] && $daAudit[0]['outcome'] === 'success', '...recorded in the admin change log as done by the system (link-safety check)');
$daOwnerMail = daEmails($daAmyEmail, $daBefore);
$daAbuseMail = daEmails($daMailbox, $daBefore);
daCheck(count($daOwnerMail) === 1 && str_contains((string) $daOwnerMail[0]['subject'], 'suspended') && str_contains((string) $daOwnerMail[0]['paramsJSON'], 'Google Web Risk'), '...the owner is emailed, with the reason');
daCheck(count($daAbuseMail) === 1 && str_contains((string) $daAbuseMail[0]['paramsJSON'], 'example[.]com') && !str_contains((string) $daAbuseMail[0]['paramsJSON'], 'https://example.com/menu-2'), '...the abuse mailbox is emailed, with the destination written so it is not a link');
$daBlocked = dynamicQrChangeForOwner($daAmy, (string) $daRow['redirectUUID'], ['destination_url' => 'https://example.com/fine'], daOptions());
daCheck(!$daBlocked['ok'] && $daBlocked['errorCode'] === 'REDIRECT_SUSPENDED', 'the owner cannot change it now (409 REDIRECT_SUSPENDED)');
$daBlocked = dynamicQrChangeForOwner($daAmy, (string) $daRow['redirectUUID'], ['active' => false], daOptions());
daCheck(!$daBlocked['ok'] && $daBlocked['errorCode'] === 'REDIRECT_SUSPENDED', '...nor switch it off and on around the takedown');
$daAgain = dynamicQrRecheckSafety(daOptions());
daCheck($daAgain['ok'] && $daAgain['checked'] === 0, 'run again the same day: nothing is due (every code was checked in the last 20 hours)');

// ⛔ The provider down: the run stops early instead of waiting on every code.
daExec('UPDATE `tblQRRedirects` SET `safetyCheckedAt` = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)');
for ($daI = 0; $daI < 4; $daI++) {
    createDynamicRedirectForOwner($daBob, 'https://example.com/bob-' . $daI, daOptions());
}
daExec('UPDATE `tblQRRedirects` SET `safetyCheckedAt` = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)');
$GLOBALS['daRisk']['down']     = true;
$GLOBALS['daRisk']['requests'] = 0;
$daStopped = dynamicQrRecheckSafety(daOptions());
daCheck($daStopped['ok'] && $daStopped['stoppedEarly'] === true && $daStopped['unchecked'] === 3 && $GLOBALS['daRisk']['requests'] === 3 && $daStopped['left'] > 0, 'Web Risk does not answer: the run stops after 3 in a row, and says how many are still due');
$GLOBALS['daRisk']['down'] = false;
daExec('UPDATE `tblQRRedirects` SET `safetyCheckedAt` = UTC_TIMESTAMP()');

// ==============================================================================
// E. A takedown Go2My.Link could not take is sent again
// ==============================================================================
echo PHP_EOL . 'E. A takedown Go2My.Link could not take is sent again by the cron step' . PHP_EOL;
$daOut  = createDynamicRedirectForOwner($daBob, 'https://example.com/bob-e', daOptions());
$daCodeE = (int) $daOut['row']['redirectID'];
$GLOBALS['daGo2']['modes'] = ['putDown'];
$daSuspendE = dynamicQrSuspend($daCodeE, ['type' => 'system', 'label' => 'link-safety check'], 'Test: Go2My.Link down at the time', daOptions(['source' => 'link_safety', 'notify' => false]));
$daRowE = daCode($daCodeE);
daCheck($daSuspendE['ok'] && $daSuspendE['applied'] === 'failed' && $daRowE['suspendedAt'] !== null && $daRowE['suspensionAppliedAt'] === null && daRemote($daRowE) === 'https://example.com/bob-e', 'Go2My.Link unreachable: suspended HERE at once (the owner can change nothing), Go2My.Link not told yet, so it waits');
$GLOBALS['daGo2']['modes'] = [];
$GLOBALS['daGo2']['requests'] = [];
$daPending = dynamicQrApplyPendingSuspensions(daOptions(['server' => ['CUERCODE_SITE_COPY' => '']]));
daCheck($daPending['ok'] && $daPending['skipped'] === 'copy_unknown' && $daPending['waiting'] === 1 && $GLOBALS['daGo2']['requests'] === [], 'the cron step without a declared site copy: nothing sent (#181), 1 waiting, and it says so');
$daPending = dynamicQrApplyPendingSuspensions(daOptions());
$daRowE = daCode($daCodeE);
daCheck($daPending['ok'] && $daPending['applied'] === 1 && $daRowE['suspensionAppliedAt'] !== null && daRemote($daRowE) === 'https://cuercode.net/unavailable/?code=' . $daRowE['shortCode'], 'the cron step with the copy declared: sent again, confirmed, Go2My.Link now on the neutral page');
$daPending = dynamicQrApplyPendingSuspensions(daOptions());
daCheck($daPending['ok'] && $daPending['looked'] === 0, '...and nothing is left waiting');

// ==============================================================================
// F. An owner's change in flight when the code is suspended (rule 12)
// ==============================================================================
echo PHP_EOL . 'F. An owner\'s change that lands after the takedown' . PHP_EOL;
$daOut  = createDynamicRedirectForOwner($daBob, 'https://example.com/bob-f', daOptions());
$daCodeF = (int) $daOut['row']['redirectID'];
$daRowF  = daCode($daCodeF);
// 🏃 While Bob's change is on its way to Go2My.Link, the code is suspended
//    here (its own Go2My.Link step left for later: the owner's lock is
//    busy). Bob's change then lands on Go2My.Link AFTER the takedown.
$GLOBALS['daGo2']['onPut'] = static function () use ($daCodeF): void {
    dynamicQrSuspend($daCodeF, ['type' => 'system', 'label' => 'abuse reports'], 'Test: suspended while the owner\'s change was in flight', daOptions(['source' => 'reports', 'applyNow' => false, 'notify' => false]));
};
$daOut = dynamicQrChangeForOwner($daBob, (string) $daRowF['redirectUUID'], ['destination_url' => 'https://example.com/bob-f-new'], daOptions());
$daRowF = daCode($daCodeF);
daCheck(!$daOut['ok'] && $daOut['httpCode'] === 409 && $daOut['errorCode'] === 'REDIRECT_SUSPENDED', 'the owner\'s change is refused (409 REDIRECT_SUSPENDED), not reported as done');
daCheck($daRowF['destinationURL'] === 'https://example.com/bob-f' && $daRowF['suspendedAt'] !== null, '...nothing of it was saved here; the code stays suspended');
daCheck(daRemote($daRowF) === 'https://cuercode.net/unavailable/?code=' . $daRowF['shortCode'] && $daRowF['suspensionAppliedAt'] !== null, '...and the takedown was SENT AGAIN, so Go2My.Link ends on the neutral page, not the owner\'s new destination');

// ==============================================================================
// 🔐 No secret anywhere, then clean up
// ==============================================================================
$daLogText = (string) file_get_contents($daErrorLog) . implode("\n", $GLOBALS['daLogged']);
daCheck(!str_contains($daLogText, str_repeat('k', 43)) && !str_contains($daLogText, str_repeat('r', 26)), 'no Go2My.Link or Web Risk key in any log line');
@unlink($daErrorLog);

$daCleanup = new \mysqli(daEnv('HOST'), daEnv('USER'), daEnv('PASS'), '', (int) $daPort);
$daCleanup->query('DROP DATABASE IF EXISTS `' . $daDbName . '`');
$daCleanup->close();

echo PHP_EOL . $GLOBALS['daChecks'] . ' checks. ';
if ($GLOBALS['daFailures'] === 0) {
    echo 'ALL CHECKS PASSED' . PHP_EOL;
    exit(0);
}
echo $GLOBALS['daFailures'] . ' CHECK(S) FAILED' . PHP_EOL;
exit(1);
