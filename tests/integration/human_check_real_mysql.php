<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - OPTIONAL real-database and real-HTTP check of the human check (#163)
 * ==============================================================================
 *
 * Path: tests/integration/human_check_real_mysql.php
 *
 * tests/test_human_check.php proves every decision with stand-in provider
 * replies. This script proves what only a real database and real HTTP can,
 * against one throwaway database built from install.sql + seed_data.sql +
 * every upgrade:
 *
 *   A. After the upgrades the provider setting is "auto" with no keys: the
 *      check is OFF and the one call answers "off".
 *   B. Keys typed through setConfig() are stored encrypted (the typed text is
 *      not in the row) and the check reads them back decrypted; the order is
 *      Turnstile, then reCAPTCHA, then hCaptcha.
 *   C. The command-line switch (_includes/cli/human_check.php, run for real in
 *      a child process): "off" stores "none" with ONE admin change-log row
 *      (actor cli:...); "off" again changes nothing and writes no row; "on"
 *      stores "auto"; a bad option exits 2; no key is ever printed.
 *   D. Real HTTP (PHP's built-in web server, with curl_init SWITCHED OFF in
 *      that server so it can never reach Cloudflare, Google or hCaptcha):
 *      with the check off, the sign-up form has no widget and works.
 *   E. With Turnstile keys entered: the four forms show the widget with the
 *      site key (never the secret); each form sent WITHOUT a token is refused
 *      and nothing is done (no account, no email queued, no failed sign-in
 *      counted); WITH a token the provider cannot be reached, so it is
 *      refused with "try again"; the JSON routes register, login and
 *      request-password-reset answer 400 HUMAN_CHECK_REQUIRED without a
 *      token - also with a valid API key, so a key is no way round - and 503
 *      HUMAN_CHECK_UNAVAILABLE with one; reset-password (exempt, needs an
 *      emailed link) is unaffected.
 *   F. "Let people through" chosen: a token with the provider unreachable is
 *      let through (the account is made) and it is logged; no token is still
 *      refused.
 *   G. No key typed during the run is in any log table or the server's log.
 *
 * It is NOT run by tests/run.php. How to run it (Docker example):
 *     docker run -d --rm --name cuercode-it-hc -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
 *       -p 127.0.0.1:33163:3306 mysql:8.0.36
 *     CUERCODE_IT_DB_HOST=127.0.0.1 CUERCODE_IT_DB_PORT=33163 \
 *     CUERCODE_IT_DB_NAME=cuercode_it_hc CUERCODE_IT_DB_USER=root CUERCODE_IT_DB_PASS= \
 *       php tests/integration/human_check_real_mysql.php
 *     docker stop cuercode-it-hc
 *
 * ⚠️ SAFETY: it creates and DROPS a whole database and refuses to run (exit 2)
 *    unless the database name starts with "cuercode_it_". It never reads
 *    _auth_keys/auth_creds.php; the encryption key is random for each run. It
 *    never calls a real human-check provider: the keys are made up, and the
 *    web server runs with curl_init switched off.
 *
 * Exit codes: 0 every scenario passed | 1 a scenario failed | 2 refused.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-27 (#163)
 * @see         tests/test_human_check.php - the database-free tests
 * @see         tests/integration/human_check_http_router.php - the web server's router
 * @see         web/CueRCode.net/_functions/fn_human_check.php
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

$hcSiteRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'CueRCode.net' . DIRECTORY_SEPARATOR;

/**
 * 🔧 One CUERCODE_IT_DB_* value ('' when unset).
 */
function hcEnv(string $hcKey): string
{
    $hcValue = getenv('CUERCODE_IT_DB_' . $hcKey);
    if ($hcValue === false) {
        return '';
    }
    return $hcValue;
}

/**
 * 🗄️ The site's database constants for the test server (before any site code).
 */
function hcDefineDatabase(string $hcDbName): void
{
    $hcPort = hcEnv('PORT');
    if ($hcPort === '') {
        $hcPort = '3306';
    }
    define('DB_HOST', hcEnv('HOST'));
    define('DB_PORT', (int) $hcPort);
    define('DB_USER', hcEnv('USER'));
    define('DB_PASS', hcEnv('PASS'));
    define('DB_NAME', $hcDbName);
    $hcKey = getenv('CUERCODE_IT_ENCRYPTION_KEY');
    if ($hcKey === false || $hcKey === '') {
        $hcKey = bin2hex(random_bytes(16));
    }
    define('ENCRYPTION_KEY', $hcKey);
}

$hcArguments = array_slice($argv, 1);

// ==============================================================================
// 👶 CHILD mode: run the REAL command-line switch against the test database
// php <this file> --run-cli <db> [words...]
// ==============================================================================
if (isset($hcArguments[0]) && $hcArguments[0] === '--run-cli') {
    $hcChildDb = '';
    if (isset($hcArguments[1])) {
        $hcChildDb = $hcArguments[1];
    }
    if (preg_match('/^cuercode_it_[a-z0-9_]+$/', $hcChildDb) !== 1) {
        fwrite(STDERR, 'Refusing: bad database name.' . PHP_EOL);
        exit(9);
    }
    hcDefineDatabase($hcChildDb);
    $_SERVER['argv'] = array_merge(['human_check.php'], array_slice($hcArguments, 2));
    require $hcSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'human_check.php';
    exit(0);
}

// ==============================================================================
// 🛡️ Step 1: safety - only a throwaway database named cuercode_it_*
// ==============================================================================
$hcBaseName = hcEnv('NAME');
if (preg_match('/^cuercode_it_[a-z0-9_]+$/', $hcBaseName) !== 1) {
    fwrite(STDERR, 'Refusing to run: CUERCODE_IT_DB_NAME must start with "cuercode_it_" (this script creates and drops a whole database).' . PHP_EOL);
    exit(2);
}
if (hcEnv('HOST') === '' || hcEnv('USER') === '') {
    fwrite(STDERR, 'Refusing to run: set CUERCODE_IT_DB_HOST and CUERCODE_IT_DB_USER.' . PHP_EOL);
    exit(2);
}
$hcDbName = $hcBaseName . '_humancheck';
if (preg_match('/^cuercode_it_[a-z0-9_]+$/', $hcDbName) !== 1 || strlen($hcDbName) > 64) {
    fwrite(STDERR, 'Refusing: the derived database name is not safe.' . PHP_EOL);
    exit(2);
}
$hcPort = hcEnv('PORT');
if ($hcPort === '') {
    $hcPort = '3306';
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ==============================================================================
// ⏳ Step 2: wait for the server, then a fresh database
// ==============================================================================
$hcServer   = null;
$hcDeadline = microtime(true) + 120;
$hcLastError = '';
while ($hcServer === null && microtime(true) < $hcDeadline) {
    try {
        // 🔕 @: the "still starting" warnings while the server boots are expected.
        $hcServer = @new \mysqli(hcEnv('HOST'), hcEnv('USER'), hcEnv('PASS'), '', (int) $hcPort);
    } catch (\Throwable $hcError) {
        $hcLastError = $hcError->getMessage();
        usleep(500000);
    }
}
if ($hcServer === null) {
    fwrite(STDERR, 'Refusing: the test database server could not be reached (' . $hcLastError . ').' . PHP_EOL);
    exit(2);
}
echo 'Server: ' . $hcServer->server_info . PHP_EOL;

$hcServer->query('DROP DATABASE IF EXISTS `' . $hcDbName . '`');
$hcServer->query('CREATE DATABASE `' . $hcDbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$hcServer->select_db($hcDbName);
$hcServer->query("SET time_zone = '+00:00'");
foreach (['install.sql', 'seed_data.sql'] as $hcSqlFile) {
    $hcServer->multi_query((string) file_get_contents($hcSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . $hcSqlFile));
    do {
        $hcStored = $hcServer->store_result();
        if ($hcStored instanceof \mysqli_result) {
            $hcStored->free();
        }
    } while ($hcServer->more_results() && $hcServer->next_result());
}

// ==============================================================================
// 🧰 Helpers
// ==============================================================================
$hcChecks = [];

/**
 * 📝 Record one check and print it.
 */
function hcCheck(string $hcScenario, bool $hcPassed, string $hcWhat): void
{
    if (!isset($GLOBALS['hcChecks'][$hcScenario])) {
        $GLOBALS['hcChecks'][$hcScenario] = true;
    }
    if (!$hcPassed) {
        $GLOBALS['hcChecks'][$hcScenario] = false;
        echo '    FAIL ';
    } else {
        echo '    ok   ';
    }
    echo $hcWhat . PHP_EOL;
}

/**
 * 🔤 One value from the test database ('' when no row).
 */
function hcValue(\mysqli $hcDb, string $hcSql): string
{
    $hcRow = $hcDb->query($hcSql)->fetch_row();
    if (!is_array($hcRow) || $hcRow[0] === null) {
        return '';
    }
    return (string) $hcRow[0];
}

/**
 * 🖥️ Run the real command-line switch in a child process.
 *
 * @return array{exit: int, out: string, err: string}
 */
function hcCli(string $hcDb, array $hcWords): array
{
    $hcCommand = array_merge([PHP_BINARY, __FILE__, '--run-cli', $hcDb], $hcWords);
    $hcProcess = proc_open($hcCommand, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $hcPipes, null, ['CUERCODE_IT_ENCRYPTION_KEY' => ENCRYPTION_KEY] + getenv());
    fclose($hcPipes[0]);
    $hcOut = (string) stream_get_contents($hcPipes[1]);
    fclose($hcPipes[1]);
    $hcErr = (string) stream_get_contents($hcPipes[2]);
    fclose($hcPipes[2]);
    return ['exit' => proc_close($hcProcess), 'out' => $hcOut, 'err' => $hcErr];
}

/**
 * 🌐 One HTTP request to the test web server (cookies kept in $jar).
 *
 * @return array{status: int, body: string, json: array|null}
 */
function hcHttp(string $hcMethod, string $hcUrl, string $hcJar, array $hcForm = [], ?array $hcJson = null, array $hcHeaders = []): array
{
    $hcHandle = curl_init($hcUrl);
    $hcOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE     => $hcJar,
        CURLOPT_COOKIEJAR      => $hcJar,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($hcMethod === 'POST') {
        $hcOptions[CURLOPT_POST] = true;
        if ($hcJson !== null) {
            $hcOptions[CURLOPT_POSTFIELDS] = (string) json_encode($hcJson);
            $hcHeaders[] = 'Content-Type: application/json';
        } else {
            $hcOptions[CURLOPT_POSTFIELDS] = http_build_query($hcForm);
        }
    }
    $hcOptions[CURLOPT_HTTPHEADER] = $hcHeaders;
    curl_setopt_array($hcHandle, $hcOptions);
    $hcBody = (string) curl_exec($hcHandle);
    $hcStatus = (int) curl_getinfo($hcHandle, CURLINFO_HTTP_CODE);
    $hcDecoded = json_decode($hcBody, true);
    if (!is_array($hcDecoded)) {
        $hcDecoded = null;
    }
    return ['status' => $hcStatus, 'body' => $hcBody, 'json' => $hcDecoded];
}

/**
 * 🔑 The CSRF token printed in a page.
 */
function hcCsrf(string $hcHtml): string
{
    if (preg_match('/name="csrf_token" value="([0-9a-f]+)"/', $hcHtml, $hcMatch) === 1) {
        return $hcMatch[1];
    }
    return '';
}

// ==============================================================================
// 🚀 The real site code in this process, against the test database
// ==============================================================================
$hcServerLog = tempnam(sys_get_temp_dir(), 'cuercode_it_hc_server_');
hcDefineDatabase($hcDbName);
$_SERVER['HTTP_HOST'] = '127.0.0.1';
require_once $hcSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once $hcSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_admin.php';
require_once $hcSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_migrations.php';
restore_error_handler();
restore_exception_handler();

$hcUpgrade = migrationRun(getDB(), 'apply', 'test', migrationDefaultDirectory(), migrationDefaultInstallSqlPath());

// 🤫 Every key typed in this run (searched for in every log at the end).
$hcSecrets = [
    'turnstileSecret' => 'IT-Turnstile-Secret-' . bin2hex(random_bytes(10)),
    'recaptchaSecret' => 'IT-Recaptcha-Secret-' . bin2hex(random_bytes(10)),
];
$hcTurnstileSite = 'IT-Turnstile-Site-' . bin2hex(random_bytes(6));

/**
 * ⚙️ Save one setting through the site's own setConfig() and forget the cache.
 */
function hcSet(string $hcKey, string $hcValue): bool
{
    $hcSaved = setConfig($hcKey, $hcValue);
    clearConfigCache();
    return $hcSaved;
}

// ==============================================================================
// A. After the upgrades: automatic, no keys, off
// ==============================================================================
echo PHP_EOL . 'A. After the upgrades' . PHP_EOL;
$hcAppliedKeys = [];
foreach ($hcUpgrade['migrations'] as $hcMigrationResult) {
    if ($hcMigrationResult['outcome'] === 'applied') {
        $hcAppliedKeys[] = $hcMigrationResult['key'];
    }
}
hcCheck('A', $hcUpgrade['status'] === 'ok' && count($hcAppliedKeys) === 5 && end($hcAppliedKeys) === '0005_human_check_settings', 'every upgrade applied, 0005 last: ' . implode(', ', $hcAppliedKeys));
hcCheck('A', hcValue($hcServer, "SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'bot_protection_provider'") === 'auto', 'the provider setting is "auto"');
hcCheck('A', humanCheckActive() === null, 'no keys: the check is off');
$hcOff = humanCheckVerifyRequest([]);
hcCheck('A', $hcOff['ok'] === true && $hcOff['reason'] === 'off', 'the one call answers "off" and lets the form through');
hcCheck('A', humanCheckReadSettings()['whenUnreachable'] === 'refuse' && humanCheckReadSettings()['timeout'] === 5, 'refuse when unreachable, wait 5 seconds (from the real rows)');

// ==============================================================================
// B. Keys stored encrypted; the order Turnstile, reCAPTCHA, hCaptcha
// ==============================================================================
echo PHP_EOL . 'B. Keys' . PHP_EOL;
hcSet('recaptcha_site_key', 'IT-Recaptcha-Site');
hcSet('recaptcha_secret_key', $hcSecrets['recaptchaSecret']);
$hcActive = humanCheckActive();
hcCheck('B', is_array($hcActive) && $hcActive['provider'] === 'recaptcha', 'only reCAPTCHA keys: reCAPTCHA is used');
hcSet('turnstile_site_key', $hcTurnstileSite);
hcSet('turnstile_secret_key', $hcSecrets['turnstileSecret']);
$hcActive = humanCheckActive();
hcCheck('B', is_array($hcActive) && $hcActive['provider'] === 'turnstile' && $hcActive['secret'] === $hcSecrets['turnstileSecret'] && $hcActive['siteKey'] === $hcTurnstileSite, 'Turnstile keys too: Turnstile comes first, read back decrypted');
$hcStoredSecret = hcValue($hcServer, "SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'turnstile_secret_key'");
hcCheck('B', $hcStoredSecret !== '' && !str_contains($hcStoredSecret, $hcSecrets['turnstileSecret']), 'the secret key is stored as ciphertext (the typed text is not in the row)');
hcCheck('B', hcValue($hcServer, "SELECT `isSensitive` FROM `tblSettings` WHERE `settingKey` = 'turnstile_site_key'") === '1', 'the site key row is a sensitive (encrypted) row too');

// ==============================================================================
// C. The command-line switch
// ==============================================================================
echo PHP_EOL . 'C. Command line' . PHP_EOL;
$hcCliRun = hcCli($hcDbName, ['status']);
hcCheck('C', $hcCliRun['exit'] === 0 && str_contains($hcCliRun['out'], 'In use now: Cloudflare Turnstile.'), 'status: names Turnstile: ' . trim($hcCliRun['out'] . $hcCliRun['err']));
$hcCliRun = hcCli($hcDbName, ['off']);
hcCheck('C', $hcCliRun['exit'] === 0 && str_contains($hcCliRun['out'], 'Saved') && str_contains($hcCliRun['out'], 'switched off'), 'off: saved, and the status says switched off');
hcCheck('C', hcValue($hcServer, "SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'bot_protection_provider'") === 'none', 'off: the provider setting is "none"');
hcCheck('C', hcValue($hcServer, "SELECT CONCAT(`actorType`, '|', LEFT(`actorLabel`, 4), '|', `action`, '|', `outcome`, '|', `changesJSON`) FROM `tblAdminAuditLog` WHERE `entityKey` = 'bot_protection_provider'") !== '' && hcValue($hcServer, "SELECT COUNT(*) FROM `tblAdminAuditLog` WHERE `entityKey` = 'bot_protection_provider' AND `actorType` = 'cli' AND `action` = 'setting.update'") === '1', 'off: one admin change-log row, actor cli: ' . hcValue($hcServer, "SELECT CONCAT(`actorType`, '|', `action`, '|', `changesJSON`) FROM `tblAdminAuditLog` WHERE `entityKey` = 'bot_protection_provider'"));
clearConfigCache();
hcCheck('C', humanCheckActive() === null, 'off: the site now has no human check');
$hcCliRun = hcCli($hcDbName, ['off']);
hcCheck('C', $hcCliRun['exit'] === 0 && str_contains($hcCliRun['out'], 'Nothing to change') && hcValue($hcServer, "SELECT COUNT(*) FROM `tblAdminAuditLog` WHERE `entityKey` = 'bot_protection_provider'") === '1', 'off again: nothing to change, no second row');
$hcCliRun = hcCli($hcDbName, ['on']);
hcCheck('C', $hcCliRun['exit'] === 0 && hcValue($hcServer, "SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'bot_protection_provider'") === 'auto' && str_contains($hcCliRun['out'], 'In use now: Cloudflare Turnstile.'), 'on: back to "auto", Turnstile in use');
$hcCliRun = hcCli($hcDbName, ['delete']);
hcCheck('C', $hcCliRun['exit'] === 2, 'a bad option: exit 2');
$hcCliAll = $hcCliRun['out'] . $hcCliRun['err'];
foreach (['status', 'off', 'on'] as $hcWord) {
    $hcCliAll .= implode('', hcCli($hcDbName, [$hcWord]));
}
hcCheck('C', !str_contains($hcCliAll, $hcSecrets['turnstileSecret']) && !str_contains($hcCliAll, $hcTurnstileSite), 'no key is ever printed');
hcCli($hcDbName, ['off']);
clearConfigCache();

// ==============================================================================
// 🌐 Start the real web server (curl_init switched off in it)
// ==============================================================================
$hcHttpPort = 0;
$hcProbe = stream_socket_server('tcp://127.0.0.1:0');
if ($hcProbe !== false) {
    $hcName = stream_socket_get_name($hcProbe, false);
    $hcHttpPort = (int) substr((string) $hcName, strrpos((string) $hcName, ':') + 1);
    fclose($hcProbe);
}
$hcRouter = __DIR__ . DIRECTORY_SEPARATOR . 'human_check_http_router.php';
$hcWebEnv = ['CUERCODE_IT_HC_DB' => $hcDbName, 'CUERCODE_IT_ENCRYPTION_KEY' => ENCRYPTION_KEY] + getenv();
$hcWeb = proc_open(
    [PHP_BINARY, '-d', 'disable_functions=curl_init', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $hcServerLog, '-S', '127.0.0.1:' . $hcHttpPort, '-t', $hcSiteRoot . 'public_html', $hcRouter],
    [0 => ['pipe', 'r'], 1 => ['file', $hcServerLog . '.out', 'a'], 2 => ['file', $hcServerLog . '.out', 'a']],
    $hcWebPipes,
    null,
    $hcWebEnv
);
$hcBase = 'http://127.0.0.1:' . $hcHttpPort;
$hcUp = false;
$hcWait = microtime(true) + 15;
while (!$hcUp && microtime(true) < $hcWait) {
    $hcSocket = @fsockopen('127.0.0.1', $hcHttpPort, $hcErrNo, $hcErrStr, 0.2);
    if ($hcSocket !== false) {
        fclose($hcSocket);
        $hcUp = true;
    } else {
        usleep(100000);
    }
}
$hcUpWord = 'NOT up';
if ($hcUp) {
    $hcUpWord = 'up';
}
echo 'Web server on ' . $hcBase . ': ' . $hcUpWord . PHP_EOL;

// 🔢 Generous API limits, so the run never trips the per-visitor rate limit.
$hcServer->query("UPDATE `tblSettings` SET `settingValue` = '1000' WHERE `settingKey` IN ('api_rate_limit_anonymous_per_min', 'api_rate_limit_anonymous_per_day')");

// 👤 An existing, active account (for sign-in and forgot password) and an API key.
$hcUserEmail = 'person@it.cuercode.invalid';
$hcUserHash  = password_hash('Correct-Horse-' . bin2hex(random_bytes(4)), PASSWORD_DEFAULT);
$hcInsert = $hcServer->prepare("INSERT INTO `tblUsers` (`email`, `emailVerifiedAt`, `passwordHash`, `displayName`, `status`, `roleID`) VALUES (?, '2026-11-01 09:00:00', ?, 'IT person', 'active', 3)");
$hcInsert->bind_param('ss', $hcUserEmail, $hcUserHash);
$hcInsert->execute();
$hcUserID = (int) $hcServer->insert_id;
$hcInsert->close();
$hcApiKey = 'cuercode_' . bin2hex(random_bytes(20));
$hcApiHash = hashAPIKey($hcApiKey);
$hcApiPrefix = substr($hcApiKey, 0, 12);
$hcInsert = $hcServer->prepare("INSERT INTO `tblAPIKeys` (`apiKeyHash`, `apiKeyPrefix`, `apiKeyName`, `userID`, `permissions`) VALUES (?, ?, 'IT key', ?, JSON_ARRAY('generate'))");
$hcInsert->bind_param('ssi', $hcApiHash, $hcApiPrefix, $hcUserID);
$hcInsert->execute();
$hcInsert->close();

$hcCount = static function (string $hcSql) use ($hcServer): int {
    return (int) hcValue($hcServer, $hcSql);
};

// ==============================================================================
// D. Check off: no widget, the form works
// ==============================================================================
echo PHP_EOL . 'D. Real HTTP, check off' . PHP_EOL;
$hcJar = tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_');
$hcPage = hcHttp('GET', $hcBase . '/register/', $hcJar);
hcCheck('D', $hcPage['status'] === 200 && !str_contains($hcPage['body'], 'cuercode-human-check"') && hcCsrf($hcPage['body']) !== '', 'GET /register/: 200, no widget');
$hcUsersBefore = $hcCount('SELECT COUNT(*) FROM `tblUsers`');
$hcPost = hcHttp('POST', $hcBase . '/register/', $hcJar, ['csrf_token' => hcCsrf($hcPage['body']), 'register_submit' => '1', 'email' => 'off@it.cuercode.invalid', 'password' => 'Plenty-Long-Pass-41', 'password_confirm' => 'Plenty-Long-Pass-41']);
hcCheck('D', str_contains($hcPost['body'], 'a verification link has been sent') && $hcCount('SELECT COUNT(*) FROM `tblUsers`') === $hcUsersBefore + 1, 'POST /register/ with no token: accepted, the account is made');

// ==============================================================================
// E. Check on (Turnstile): widget shown, no way round it
// ==============================================================================
echo PHP_EOL . 'E. Real HTTP, check on' . PHP_EOL;
hcCli($hcDbName, ['on']);
$hcJar = tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_');
$hcForms = [
    'register'            => ['register_submit' => '1', 'email' => 'new@it.cuercode.invalid', 'password' => 'Plenty-Long-Pass-41', 'password_confirm' => 'Plenty-Long-Pass-41'],
    'login'               => ['login_submit' => '1', 'email' => $hcUserEmail, 'password' => 'wrong-password-123'],
    'forgot-password'     => ['forgot_submit' => '1', 'email' => $hcUserEmail],
    'resend-verification' => ['resend_submit' => '1', 'email' => 'off@it.cuercode.invalid'],
];
$hcCsrfToken = '';
foreach ($hcForms as $hcPath => $hcFields) {
    $hcPage = hcHttp('GET', $hcBase . '/' . $hcPath . '/', $hcJar);
    $hcCsrfToken = hcCsrf($hcPage['body']);
    hcCheck('E', $hcPage['status'] === 200 && str_contains($hcPage['body'], 'class="cuercode-human-check"') && str_contains($hcPage['body'], 'data-sitekey="' . $hcTurnstileSite . '"') && str_contains($hcPage['body'], '/assets/js/cuercode-human-check.js'), 'GET /' . $hcPath . '/: the widget with the site key, and its script');
    hcCheck('E', !str_contains($hcPage['body'], $hcSecrets['turnstileSecret']) && str_contains($hcPage['body'], '<noscript>'), 'GET /' . $hcPath . '/: no secret on the page; a note for JavaScript off');

    $hcUsersBefore  = $hcCount('SELECT COUNT(*) FROM `tblUsers`');
    $hcEmailsBefore = $hcCount('SELECT COUNT(*) FROM `tblEmailQueue`');
    $hcFailsBefore  = $hcCount("SELECT `failedLoginCount` FROM `tblUsers` WHERE `userID` = " . $hcUserID);
    $hcPost = hcHttp('POST', $hcBase . '/' . $hcPath . '/', $hcJar, array_merge(['csrf_token' => $hcCsrfToken], $hcFields));
    hcCheck('E', str_contains($hcPost['body'], 'Please complete the human check'), 'POST /' . $hcPath . '/ with NO token: refused with the plain message');
    hcCheck('E', $hcCount('SELECT COUNT(*) FROM `tblUsers`') === $hcUsersBefore && $hcCount('SELECT COUNT(*) FROM `tblEmailQueue`') === $hcEmailsBefore && $hcCount("SELECT `failedLoginCount` FROM `tblUsers` WHERE `userID` = " . $hcUserID) === $hcFailsBefore, 'POST /' . $hcPath . '/ with NO token: nothing done (no account, no email queued, no failed sign-in counted)');

    $hcPage = hcHttp('GET', $hcBase . '/' . $hcPath . '/', $hcJar);
    $hcPost = hcHttp('POST', $hcBase . '/' . $hcPath . '/', $hcJar, array_merge(['csrf_token' => hcCsrf($hcPage['body']), 'cf-turnstile-response' => 'XXXX.DUMMY.TOKEN.XXXX'], $hcFields));
    hcCheck('E', str_contains($hcPost['body'], 'could not be completed just now') && $hcCount('SELECT COUNT(*) FROM `tblEmailQueue`') === $hcEmailsBefore, 'POST /' . $hcPath . '/ WITH a token, provider unreachable: refused ("try again"), nothing done');
}

// 🌐 The JSON routes.
$hcPage = hcHttp('GET', $hcBase . '/login/', $hcJar);
$hcCsrfToken = hcCsrf($hcPage['body']);
$hcApiCalls = [
    'auth/register'               => ['email' => 'api@it.cuercode.invalid', 'password' => 'Plenty-Long-Pass-41'],
    'auth/login'                  => ['email' => $hcUserEmail, 'password' => 'wrong-password-123'],
    'auth/request-password-reset' => ['email' => $hcUserEmail],
];
foreach ($hcApiCalls as $hcRoute => $hcBody) {
    $hcEmailsBefore = $hcCount('SELECT COUNT(*) FROM `tblEmailQueue`');
    $hcAnswer = hcHttp('POST', $hcBase . '/api/v1/' . $hcRoute, $hcJar, [], $hcBody, ['X-CSRF-Token: ' . $hcCsrfToken]);
    hcCheck('E', $hcAnswer['status'] === 400 && is_array($hcAnswer['json']) && $hcAnswer['json']['error_code'] === 'HUMAN_CHECK_REQUIRED', 'API ' . $hcRoute . ' (security token, no human_check_token): 400 HUMAN_CHECK_REQUIRED (' . $hcAnswer['status'] . ')');
    $hcAnswer = hcHttp('POST', $hcBase . '/api/v1/' . $hcRoute, $hcJar, [], $hcBody, ['X-API-Key: ' . $hcApiKey]);
    hcCheck('E', $hcAnswer['status'] === 400 && is_array($hcAnswer['json']) && $hcAnswer['json']['error_code'] === 'HUMAN_CHECK_REQUIRED', 'API ' . $hcRoute . ' with a VALID API KEY and no token: still 400 HUMAN_CHECK_REQUIRED (a key is no way round) (' . $hcAnswer['status'] . ')');
    $hcAnswer = hcHttp('POST', $hcBase . '/api/v1/' . $hcRoute, $hcJar, [], array_merge($hcBody, ['human_check_token' => 'XXXX.DUMMY.TOKEN.XXXX']), ['X-CSRF-Token: ' . $hcCsrfToken]);
    hcCheck('E', $hcAnswer['status'] === 503 && is_array($hcAnswer['json']) && $hcAnswer['json']['error_code'] === 'HUMAN_CHECK_UNAVAILABLE', 'API ' . $hcRoute . ' with a token, provider unreachable: 503 HUMAN_CHECK_UNAVAILABLE (' . $hcAnswer['status'] . ')');
    hcCheck('E', $hcCount('SELECT COUNT(*) FROM `tblEmailQueue`') === $hcEmailsBefore, 'API ' . $hcRoute . ': no email queued');
}
hcCheck('E', $hcCount("SELECT COUNT(*) FROM `tblUsers` WHERE `email` = 'api@it.cuercode.invalid'") === 0, 'API: no account was made');
$hcAnswer = hcHttp('POST', $hcBase . '/api/v1/auth/reset-password', $hcJar, [], ['token' => str_repeat('a', 64), 'new_password' => 'Plenty-Long-Pass-41'], ['X-CSRF-Token: ' . $hcCsrfToken]);
hcCheck('E', is_array($hcAnswer['json']) && $hcAnswer['json']['error_code'] === 'RESET_TOKEN_INVALID', 'API auth/reset-password (exempt, needs the emailed link): answers about the link, not the human check (' . $hcAnswer['status'] . ')');
hcCheck('E', $hcCount("SELECT COUNT(*) FROM `tblErrorLog` WHERE `errorTitle` LIKE 'Human check provider could not be reached%'") > 0, 'each "could not be reached" is written to the error log');

// ==============================================================================
// F. "Let people through" when the provider cannot be reached
// ==============================================================================
echo PHP_EOL . 'F. Let people through' . PHP_EOL;
$hcServer->query("UPDATE `tblSettings` SET `settingValue` = 'allow' WHERE `settingKey` = 'bot_protection_when_unreachable'");
$hcPage = hcHttp('GET', $hcBase . '/register/', $hcJar);
$hcUsersBefore = $hcCount('SELECT COUNT(*) FROM `tblUsers`');
$hcPost = hcHttp('POST', $hcBase . '/register/', $hcJar, array_merge(['csrf_token' => hcCsrf($hcPage['body']), 'cf-turnstile-response' => 'XXXX.DUMMY.TOKEN.XXXX'], $hcForms['register']));
hcCheck('F', str_contains($hcPost['body'], 'a verification link has been sent') && $hcCount('SELECT COUNT(*) FROM `tblUsers`') === $hcUsersBefore + 1, 'a token with the provider unreachable: let through, the account is made');
$hcPage = hcHttp('GET', $hcBase . '/register/', $hcJar);
$hcPost = hcHttp('POST', $hcBase . '/register/', $hcJar, array_merge(['csrf_token' => hcCsrf($hcPage['body'])], $hcForms['register'], ['email' => 'second@it.cuercode.invalid']));
hcCheck('F', str_contains($hcPost['body'], 'Please complete the human check'), 'no token at all: still refused');

// ==============================================================================
// G. No key in any log
// ==============================================================================
echo PHP_EOL . 'G. Logs' . PHP_EOL;
proc_terminate($hcWeb);
proc_close($hcWeb);
$hcLogText = (string) @file_get_contents($hcServerLog) . (string) @file_get_contents($hcServerLog . '.out');
foreach ($hcSecrets as $hcSecretName => $hcSecret) {
    $hcWhere = [];
    foreach (['tblErrorLog', 'tblActivityLog', 'tblAdminAuditLog'] as $hcTable) {
        $hcColumns = [];
        $hcColumnResult = $hcServer->query('SHOW COLUMNS FROM `' . $hcTable . '`');
        while ($hcColumnRow = $hcColumnResult->fetch_assoc()) {
            $hcColumns[] = 'COALESCE(CAST(`' . $hcColumnRow['Field'] . '` AS CHAR), \'\')';
        }
        $hcStatement = $hcServer->prepare('SELECT COUNT(*) FROM `' . $hcTable . '` WHERE CONCAT_WS(\' \', ' . implode(', ', $hcColumns) . ') LIKE ?');
        $hcLike = '%' . $hcSecret . '%';
        $hcStatement->bind_param('s', $hcLike);
        $hcStatement->execute();
        if ((int) $hcStatement->get_result()->fetch_row()[0] !== 0) {
            $hcWhere[] = $hcTable;
        }
        $hcStatement->close();
    }
    if (str_contains($hcLogText, $hcSecret)) {
        $hcWhere[] = 'web server log';
    }
    hcCheck('G', $hcWhere === [], $hcSecretName . ' is in no log ' . implode(', ', $hcWhere));
}
hcCheck('G', !str_contains($hcLogText, 'Fatal error') && !str_contains($hcLogText, 'Warning:') && !str_contains($hcLogText, 'Deprecated:'), 'the web server logged no PHP fatal error, warning or deprecation');

// ==============================================================================
// 🧹 Clean up and report
// ==============================================================================
$hcServer->query('DROP DATABASE IF EXISTS `' . $hcDbName . '`');
@unlink($hcServerLog);
@unlink($hcServerLog . '.out');

echo PHP_EOL . 'Results:' . PHP_EOL;
$hcAllPassed = true;
foreach ($hcChecks as $hcScenario => $hcPassed) {
    if ($hcPassed) {
        echo '  PASS  ' . $hcScenario . PHP_EOL;
    } else {
        echo '  FAIL  ' . $hcScenario . PHP_EOL;
        $hcAllPassed = false;
    }
}
if ($hcAllPassed) {
    exit(0);
}
exit(1);
