<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Real-database and real-HTTP check of the human check (#163)
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
 *      that server so it can never reach Cloudflare, Google or hCaptcha;
 *      started through tests/lib/web_server.php, so it is stopped however
 *      this script ends, kill -9 included):
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
 *   F. Decision 0037, every configured provider down by the SERVER'S OWN
 *      test (its result row written as the test call would - a visitor
 *      cannot): sign-in without a token is let through and written to the
 *      activity log ONCE (bounded); wrong passwords still count and still
 *      lock the account; sign-up and forgot password are still refused; the
 *      JSON sign-in too; "refuse" refuses sign-in; an OLD "down" is tested
 *      again (here: curl is off, so "our set-up", never "down").
 *   K. Decision 0038 over real HTTP: the no-JavaScript email sign-in (wrong
 *      password and unknown address: the same answer as a right one, no
 *      email; right password: one email; opening the link in another browser
 *      changes nothing; its button only approves; the link works once;
 *      Continue signs in the first browser; the same browser is signed in
 *      directly; a moved "sessions valid from" mark and an expired link are
 *      refused; the per-mailbox limit stops the email without saying so);
 *      the no-JavaScript sign-up and forgot password give the same answer for
 *      an existing and a new address, in at least the minimum time.
 *   M. Merged review M1: right password, wrong password, unknown, locked and
 *      not-yet-verified accounts give byte-for-byte the same answer (status,
 *      headers apart from the date, page), the same cookies, about the same
 *      time, and the same sign-in page, Continue and Start again afterwards.
 *   N. Merged review L1, L2, L3, L5, I2, I3 over real HTTP: made-up addresses
 *      and a sign-up flood do not use up a real person's sign-in; a
 *      stranger's wrong guesses do not block them (and, since the
 *      third-round review's Medium 1, do NOT count toward the lock-out);
 *      "+tag" sign-ups share one mailbox's limit; over the per-address limit
 *      the other three forms do nothing and sign-in is answered at once
 *      about "your connection"; the lock held elsewhere is "busy".
 *   R. Third-round review, Medium 1: wrong passwords through the route never
 *      lock the account (five from one address, then the per-address limit;
 *      thirteen in all); only the per-account daily number of them (10) is
 *      checked at all, and past it the right password gets the same answer
 *      and no email; the person then signs in with the right password
 *      through the NORMAL form; the normal form still locks after five.
 *      Fourth-round review, Medium 1: an accented, zero-width,
 *      control-character or full-width spelling of the address is not a way
 *      round that number (right password: no email).
 *   P. Merged review L5, L6, I1: a stranger with their own waiting sign-in
 *      opening someone else's link is not signed in; the approve page shows
 *      when, from what browser and address; an approval runs out after 3
 *      minutes; the confirm button without, or with a wrong, security token
 *      is refused; "Start again" cancels; the last step refuses a cancelled
 *      request on its own.
 *   Q. Merged review L4: with the saved keys unreadable, the email-route
 *      button is drawn for everyone, the normal form is refused and the
 *      route works.
 *   L. The four web forms' per-visitor limit stops the call to the provider.
 *   G. No key or sign-in link typed during the run is in any log table or
 *      the server's log.
 *   H. Review M1 on the real table: a token accepted once is refused the
 *      second time; eight processes with the same token at the same moment:
 *      exactly one is accepted.
 *   I. Review M2 with real setConfig(): a damaged stored secret makes the
 *      check refuse (not "off"), the status say the keys cannot be read, and
 *      only the email-route button be drawn.
 *   J. The no-JavaScript route's limits on the real table: emails per
 *      mailbox (+tags count as one), uses per visitor address (IPv6 per
 *      /64), emails per form site-wide, a place given back, and twelve
 *      processes at the same moment for one mailbox: exactly the limit gets
 *      an email place; twelve for one account with password checks set to
 *      3: exactly 3 have their password checked.
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
// 👶 CHILD mode: one request at the same moment as its siblings (H and J)
// php <this file> --race claim|gate <db>
// ==============================================================================
if (isset($hcArguments[0]) && $hcArguments[0] === '--race') {
    $hcRaceMode = '';
    if (isset($hcArguments[1])) {
        $hcRaceMode = $hcArguments[1];
    }
    $hcChildDb = '';
    if (isset($hcArguments[2])) {
        $hcChildDb = $hcArguments[2];
    }
    if (preg_match('/^cuercode_it_[a-z0-9_]+$/', $hcChildDb) !== 1) {
        fwrite(STDERR, 'Refusing: bad database name.' . PHP_EOL);
        exit(9);
    }
    hcDefineDatabase($hcChildDb);
    require_once $hcSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
    restore_error_handler();
    restore_exception_handler();
    $hcStartAt = (float) getenv('CUERCODE_IT_CHILD_START');
    while ($hcStartAt > 0 && microtime(true) < $hcStartAt) {
        usleep(1000);
    }
    if ($hcRaceMode === 'claim') {
        echo humanCheckClaimToken((string) getenv('CUERCODE_IT_CHILD_HASH'), 'turnstile');
    } elseif ($hcRaceMode === 'password') {
        // 🔑 Third-round review Medium 1: asks for a password-check place only.
        echo json_encode(noScriptRouteGate('login', (string) getenv('CUERCODE_IT_CHILD_EMAIL'), (string) getenv('CUERCODE_IT_CHILD_IP'), false, true));
    } elseif ($hcRaceMode === 'gate') {
        // ✉️ Asks for an email place too (the per-mailbox limit is on emails).
        echo json_encode(noScriptRouteGate('forgot', (string) getenv('CUERCODE_IT_CHILD_EMAIL'), (string) getenv('CUERCODE_IT_CHILD_IP'), true));
    }
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
 * @return array{status: int, body: string, json: array|null, headers: array<int, string>}
 */
function hcHttp(string $hcMethod, string $hcUrl, string $hcJar, array $hcForm = [], ?array $hcJson = null, array $hcHeaders = []): array
{
    $hcHandle = curl_init($hcUrl);
    $hcResponseHeaders = [];
    $hcOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE     => $hcJar,
        CURLOPT_COOKIEJAR      => $hcJar,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => false,
        // 📋 Every response header line (M1: the answers must be the same).
        CURLOPT_HEADERFUNCTION => static function ($hcCurl, string $hcLine) use (&$hcResponseHeaders): int {
            $hcTrimmed = trim($hcLine);
            if ($hcTrimmed !== '') {
                $hcResponseHeaders[] = $hcTrimmed;
            }
            return strlen($hcLine);
        },
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
    return ['status' => $hcStatus, 'body' => $hcBody, 'json' => $hcDecoded, 'headers' => $hcResponseHeaders];
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
// 📋 Every upgrade file there is (0006 and later ones too), 0005 among them.
$hcUpgradeFiles = count((array) glob($hcSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '[0-9][0-9][0-9][0-9]_*.php'));
hcCheck('A', $hcUpgrade['status'] === 'ok' && count($hcAppliedKeys) === $hcUpgradeFiles && $hcUpgradeFiles >= 5 && in_array('0005_human_check_settings', $hcAppliedKeys, true), 'every upgrade applied, 0005 among them: ' . implode(', ', $hcAppliedKeys));
hcCheck('A', hcValue($hcServer, "SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'bot_protection_provider'") === 'auto', 'the provider setting is "auto"');
hcCheck('A', humanCheckActive() === null, 'no keys: the check is off');
$hcOff = humanCheckVerifyRequest([]);
hcCheck('A', $hcOff['ok'] === true && $hcOff['reason'] === 'off', 'the one call answers "off" and lets the form through');
hcCheck('A', humanCheckReadSettings()['whenUnreachable'] === 'signin_only' && humanCheckReadSettings()['timeout'] === 5, 'every provider down: only sign-in (decision 0037); wait 5 seconds (from the real rows)');
hcCheck('A', hcValue($hcServer, "SELECT GROUP_CONCAT(CONCAT(`settingKey`, '=', `settingValue`) ORDER BY `settingKey`) FROM `tblSettings` WHERE `settingKey` IN ('human_check_form_ip_per_hour', 'no_script_route_ip_per_hour', 'no_script_route_email_per_day', 'no_script_route_site_per_hour')") === 'human_check_form_ip_per_hour=30,no_script_route_email_per_day=3,no_script_route_ip_per_hour=5,no_script_route_site_per_hour=30', 'the limit settings start at 30, 3, 5 and 30: ' . hcValue($hcServer, "SELECT GROUP_CONCAT(CONCAT(`settingKey`, '=', `settingValue`)) FROM `tblSettings` WHERE `settingKey` LIKE 'no_script_route_%' OR `settingKey` = 'human_check_form_ip_per_hour'"));
hcCheck('A', hcValue($hcServer, "SELECT COUNT(*) FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` IN ('tblHumanCheckTokens', 'tblHumanCheckState', 'tblNoScriptRequests', 'tblEmailSignInRequests')") === '4', 'the four new tables exist');

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
hcCheck('C', $hcCliRun['exit'] === 0 && str_contains($hcCliRun['out'], 'In use now: Cloudflare Turnstile, then Google reCAPTCHA.'), 'status: names Turnstile, then reCAPTCHA: ' . trim($hcCliRun['out'] . $hcCliRun['err']));
$hcCliRun = hcCli($hcDbName, ['off']);
hcCheck('C', $hcCliRun['exit'] === 0 && str_contains($hcCliRun['out'], 'Saved') && str_contains($hcCliRun['out'], 'switched off'), 'off: saved, and the status says switched off');
hcCheck('C', hcValue($hcServer, "SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'bot_protection_provider'") === 'none', 'off: the provider setting is "none"');
hcCheck('C', hcValue($hcServer, "SELECT CONCAT(`actorType`, '|', LEFT(`actorLabel`, 4), '|', `action`, '|', `outcome`, '|', `changesJSON`) FROM `tblAdminAuditLog` WHERE `entityKey` = 'bot_protection_provider'") !== '' && hcValue($hcServer, "SELECT COUNT(*) FROM `tblAdminAuditLog` WHERE `entityKey` = 'bot_protection_provider' AND `actorType` = 'cli' AND `action` = 'setting.update'") === '1', 'off: one admin change-log row, actor cli: ' . hcValue($hcServer, "SELECT CONCAT(`actorType`, '|', `action`, '|', `changesJSON`) FROM `tblAdminAuditLog` WHERE `entityKey` = 'bot_protection_provider'"));
clearConfigCache();
hcCheck('C', humanCheckActive() === null, 'off: the site now has no human check');
$hcCliRun = hcCli($hcDbName, ['off']);
hcCheck('C', $hcCliRun['exit'] === 0 && str_contains($hcCliRun['out'], 'Nothing to change') && hcValue($hcServer, "SELECT COUNT(*) FROM `tblAdminAuditLog` WHERE `entityKey` = 'bot_protection_provider'") === '1', 'off again: nothing to change, no second row');
$hcCliRun = hcCli($hcDbName, ['on']);
hcCheck('C', $hcCliRun['exit'] === 0 && hcValue($hcServer, "SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'bot_protection_provider'") === 'auto' && str_contains($hcCliRun['out'], 'In use now: Cloudflare Turnstile, then Google reCAPTCHA.'), 'on: back to "auto", Turnstile in use (reCAPTCHA next)');
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
// 🛑 Started through tests/lib/web_server.php, so the server is stopped
//    however this script ends: a failed check, a crash, Ctrl+C or kill -9.
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'web_server.php';
$hcWeb = itWebServerStart(
    ['-d', 'disable_functions=curl_init', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $hcServerLog, '-S', '127.0.0.1:' . $hcHttpPort, '-t', $hcSiteRoot . 'public_html', $hcRouter],
    $hcServerLog . '.out',
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
$hcUserPassword = 'Correct-Horse-' . bin2hex(random_bytes(4));
$hcUserHash  = password_hash($hcUserPassword, PASSWORD_DEFAULT);
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
    // 📌 Turnstile first, reCAPTCHA as the fallback - or the other way round
    //    once the server's own test (curl is off here) has found Turnstile
    //    unusable: either way Turnstile's PUBLIC site key is on the page.
    hcCheck('E', $hcPage['status'] === 200 && str_contains($hcPage['body'], 'class="cuercode-human-check"') && str_contains($hcPage['body'], $hcTurnstileSite) && str_contains($hcPage['body'], 'data-fallbacks="') && str_contains($hcPage['body'], '/assets/js/cuercode-human-check.js'), 'GET /' . $hcPath . '/: the widget with the site keys (one provider, the other as its fallback), and its script');
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
hcCheck('E', $hcCount("SELECT COUNT(*) FROM `tblErrorLog` WHERE `errorTitle` LIKE 'Human check: this server cannot make a safe request%'") === 1, 'curl is off in this server, so every token counted as OUR set-up being wrong (review L1, never "down") - and after 7 such requests the error log holds ONE line (bounded): ' . $hcCount("SELECT COUNT(*) FROM `tblErrorLog` WHERE `errorTitle` LIKE 'Human check: this server cannot make a safe request%'"));

// ==============================================================================
// F. Decision 0037: every configured provider down (by the server's own test)
// ==============================================================================
echo PHP_EOL . 'F. Every provider down' . PHP_EOL;
// 🩺 The result row a test call writes when Turnstile cannot be reached. Only
//    the server writes this table; nothing a visitor sends can.
//    Both configured providers (Turnstile and reCAPTCHA) must be down.
foreach (['turnstile', 'recaptcha'] as $hcDownProvider) {
    $hcServer->query("INSERT INTO `tblHumanCheckState` (`stateKey`, `stateValue`, `checkedAt`) VALUES ('health:" . $hcDownProvider . "', 'down', NOW()) ON DUPLICATE KEY UPDATE `stateValue` = 'down', `checkedAt` = NOW()");
}
$hcJar = tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_');
$hcServer->query("UPDATE `tblHumanCheckState` SET `stateValue` = 'up' WHERE `stateKey` = 'health:recaptcha'");
$hcPage = hcHttp('GET', $hcBase . '/login/', $hcJar);
$hcPost = hcHttp('POST', $hcBase . '/login/', $hcJar, ['csrf_token' => hcCsrf($hcPage['body']), 'login_submit' => '1', 'email' => $hcUserEmail, 'password' => $hcUserPassword]);
hcCheck('F', $hcPost['status'] === 200 && str_contains($hcPost['body'], 'Please complete the human check'), 'Turnstile down but reCAPTCHA up: sign-in without a token is still refused (the other provider is used)');
$hcServer->query("UPDATE `tblHumanCheckState` SET `stateValue` = 'down', `checkedAt` = NOW() WHERE `stateKey` = 'health:recaptcha'");
$hcJar = tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_');
$hcPage = hcHttp('GET', $hcBase . '/login/', $hcJar);
$hcPost = hcHttp('POST', $hcBase . '/login/', $hcJar, ['csrf_token' => hcCsrf($hcPage['body']), 'login_submit' => '1', 'email' => $hcUserEmail, 'password' => $hcUserPassword]);
hcCheck('F', $hcPost['status'] === 302, 'sign-in with NO token and the right password while every provider is down: let through (HTTP ' . $hcPost['status'] . ')');
hcCheck('F', $hcCount("SELECT COUNT(*) FROM `tblActivityLog` WHERE `activityType` = 'human_check_outage_signin'") === 1 && $hcCount("SELECT COUNT(*) FROM `tblActivityLog` WHERE `activityType` = 'human_check_outage_signin_success' AND `entityType` = 'user' AND `entityID` = " . $hcUserID) === 1, 'written to the activity log: one "let through" row and one "signed in" row naming the account');
$hcJar = tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_');
for ($hcTry = 1; $hcTry <= 5; $hcTry++) {
    $hcPage = hcHttp('GET', $hcBase . '/login/', $hcJar);
    $hcPost = hcHttp('POST', $hcBase . '/login/', $hcJar, ['csrf_token' => hcCsrf($hcPage['body']), 'login_submit' => '1', 'email' => $hcUserEmail, 'password' => 'wrong-password-' . $hcTry]);
}
hcCheck('F', str_contains($hcPost['body'], 'Invalid email or password') && $hcCount('SELECT `failedLoginCount` FROM `tblUsers` WHERE `userID` = ' . $hcUserID) === 5 && hcValue($hcServer, 'SELECT `lockedUntil` FROM `tblUsers` WHERE `userID` = ' . $hcUserID) !== '', 'five wrong passwords during the outage: each counted, and the account is LOCKED (the wrong-password limit still applies)');
$hcPage = hcHttp('GET', $hcBase . '/login/', $hcJar);
$hcPost = hcHttp('POST', $hcBase . '/login/', $hcJar, ['csrf_token' => hcCsrf($hcPage['body']), 'login_submit' => '1', 'email' => $hcUserEmail, 'password' => $hcUserPassword]);
hcCheck('F', $hcPost['status'] === 200 && str_contains($hcPost['body'], 'Invalid email or password'), 'locked: even the right password is refused during the outage');
hcCheck('F', $hcCount("SELECT COUNT(*) FROM `tblActivityLog` WHERE `activityType` = 'human_check_outage_signin'") === 1, 'seven let-through attempts within a minute: still ONE activity row (bounded)');
$hcServer->query('UPDATE `tblUsers` SET `failedLoginCount` = 0, `lockedUntil` = NULL WHERE `userID` = ' . $hcUserID);
$hcUsersBefore  = $hcCount('SELECT COUNT(*) FROM `tblUsers`');
$hcEmailsBefore = $hcCount('SELECT COUNT(*) FROM `tblEmailQueue`');
foreach (['register', 'forgot-password', 'resend-verification'] as $hcPath) {
    $hcPage = hcHttp('GET', $hcBase . '/' . $hcPath . '/', $hcJar);
    $hcPost = hcHttp('POST', $hcBase . '/' . $hcPath . '/', $hcJar, array_merge(['csrf_token' => hcCsrf($hcPage['body'])], $hcForms[$hcPath]));
    hcCheck('F', str_contains($hcPost['body'], 'Please complete the human check'), '/' . $hcPath . '/ with no token during the outage: still REFUSED (only sign-in is let through)');
}
hcCheck('F', $hcCount('SELECT COUNT(*) FROM `tblUsers`') === $hcUsersBefore && $hcCount('SELECT COUNT(*) FROM `tblEmailQueue`') === $hcEmailsBefore, '... no account made, no email queued');
$hcPage = hcHttp('GET', $hcBase . '/login/', $hcJar);
$hcCsrfToken = hcCsrf($hcPage['body']);
$hcAnswer = hcHttp('POST', $hcBase . '/api/v1/auth/login', $hcJar, [], ['email' => $hcUserEmail, 'password' => $hcUserPassword], ['X-CSRF-Token: ' . $hcCsrfToken]);
hcCheck('F', $hcAnswer['status'] === 200, 'JSON sign-in with no token during the outage: 200 (' . $hcAnswer['status'] . ')');
$hcAnswer = hcHttp('POST', $hcBase . '/api/v1/auth/register', $hcJar, [], ['email' => 'outage@it.cuercode.invalid', 'password' => 'Plenty-Long-Pass-41'], ['X-CSRF-Token: ' . $hcCsrfToken]);
hcCheck('F', $hcAnswer['status'] === 400 && is_array($hcAnswer['json']) && $hcAnswer['json']['error_code'] === 'HUMAN_CHECK_REQUIRED', 'JSON sign-up with no token during the outage: still 400 HUMAN_CHECK_REQUIRED');
$hcServer->query("UPDATE `tblSettings` SET `settingValue` = 'refuse' WHERE `settingKey` = 'bot_protection_when_unreachable'");
$hcJar = tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_');
$hcPage = hcHttp('GET', $hcBase . '/login/', $hcJar);
$hcPost = hcHttp('POST', $hcBase . '/login/', $hcJar, ['csrf_token' => hcCsrf($hcPage['body']), 'login_submit' => '1', 'email' => $hcUserEmail, 'password' => $hcUserPassword]);
hcCheck('F', $hcPost['status'] === 200 && str_contains($hcPost['body'], 'Please complete the human check'), '"refuse" chosen: sign-in is refused during the outage too');
$hcServer->query("UPDATE `tblSettings` SET `settingValue` = 'signin_only' WHERE `settingKey` = 'bot_protection_when_unreachable'");
$hcServer->query("UPDATE `tblHumanCheckState` SET `checkedAt` = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE `stateKey` IN ('health:turnstile', 'health:recaptcha')");
$hcPage = hcHttp('GET', $hcBase . '/login/', $hcJar);
$hcPost = hcHttp('POST', $hcBase . '/login/', $hcJar, ['csrf_token' => hcCsrf($hcPage['body']), 'login_submit' => '1', 'email' => $hcUserEmail, 'password' => $hcUserPassword, 'human_check_provider' => 'turnstile', 'provider_status' => 'down']);
hcCheck('F', $hcPost['status'] === 200 && str_contains($hcPost['body'], 'Please complete the human check'), 'an OLD "down" (10 minutes) is tested again; posted fields claiming "down" change nothing: refused');
hcCheck('F', hcValue($hcServer, "SELECT CONCAT(`stateValue`, '|', TIMESTAMPDIFF(SECOND, `checkedAt`, NOW()) < 30) FROM `tblHumanCheckState` WHERE `stateKey` = 'health:turnstile'") === 'misconfigured|1', 'the new test result is "our set-up" (curl is off here), never "down": ' . hcValue($hcServer, "SELECT `stateValue` FROM `tblHumanCheckState` WHERE `stateKey` = 'health:turnstile'"));
$hcServer->query("DELETE FROM `tblHumanCheckState` WHERE `stateKey` LIKE 'health:%'");

// ==============================================================================
// K. Decision 0038 over real HTTP: the no-JavaScript route
// ==============================================================================
echo PHP_EOL . 'K. No JavaScript: the email-confirmed route' . PHP_EOL;
$hcServer->query("UPDATE `tblSettings` SET `settingValue` = '100' WHERE `settingKey` = 'no_script_route_ip_per_hour'");
$hcServer->query("UPDATE `tblSettings` SET `settingValue` = '20' WHERE `settingKey` = 'no_script_route_email_per_day'");
$hcServer->query("UPDATE `tblSettings` SET `settingValue` = '1000' WHERE `settingKey` = 'no_script_route_site_per_hour'");
$hcSignInTokens = [];

/**
 * 🎫 The raw token in the newest email sign-in email ('' when none).
 */
function hcLatestSignInToken(\mysqli $hcDb): string
{
    $hcUrl = hcValue($hcDb, "SELECT JSON_UNQUOTE(JSON_EXTRACT(`paramsJSON`, '$.confirmUrl')) FROM `tblEmailQueue` WHERE `templateKey` = 'email_sign_in' ORDER BY `emailID` DESC LIMIT 1");
    if (preg_match('/[?&]token=([0-9a-f]{64})/', $hcUrl, $hcMatch) === 1) {
        return $hcMatch[1];
    }
    return '';
}

/**
 * ✉️ Start an email sign-in in a cookie jar ("browser"); returns the response.
 */
function hcStartEmailSignIn(string $hcBase, string $hcJar, string $hcEmail, string $hcPassword, array $hcHeaders = []): array
{
    $hcPage = hcHttp('GET', $hcBase . '/login/', $hcJar, [], null, $hcHeaders);
    return hcHttp('POST', $hcBase . '/login/', $hcJar, ['csrf_token' => hcCsrf($hcPage['body']), 'login_submit' => '1', 'email' => $hcEmail, 'password' => $hcPassword, 'human_check_email_route' => '1'], null, $hcHeaders);
}

/**
 * 👤 Add an account straight into the test database; returns its userID.
 */
function hcAddUser(\mysqli $hcDb, string $hcEmail, string $hcPassword, string $hcStatus, ?string $hcLockedUntilSql): int
{
    $hcHash = password_hash($hcPassword, PASSWORD_DEFAULT);
    $hcVerified = null;
    if ($hcStatus === 'active') {
        $hcVerified = '2026-11-01 09:00:00';
    }
    $hcStmt = $hcDb->prepare("INSERT INTO `tblUsers` (`email`, `emailVerifiedAt`, `passwordHash`, `displayName`, `status`, `roleID`) VALUES (?, ?, ?, 'IT person', ?, 3)");
    $hcStmt->bind_param('ssss', $hcEmail, $hcVerified, $hcHash, $hcStatus);
    $hcStmt->execute();
    $hcNewID = (int) $hcDb->insert_id;
    $hcStmt->close();
    if ($hcLockedUntilSql !== null) {
        $hcDb->query('UPDATE `tblUsers` SET `failedLoginCount` = 5, `lockedUntil` = ' . $hcLockedUntilSql . ' WHERE `userID` = ' . $hcNewID);
    }
    return $hcNewID;
}

/**
 * 🧽 A page or header list with what may differ between two browsers taken
 *    out: security tokens and other 64-character secrets, the email typed,
 *    and the Date header.
 *
 * @param array<int, string>|string $hcWhat
 */
function hcSameShape(array|string $hcWhat, string $hcTypedEmail): string
{
    if (is_array($hcWhat)) {
        $hcKept = [];
        foreach ($hcWhat as $hcLine) {
            if (stripos($hcLine, 'Date:') !== 0) {
                $hcKept[] = $hcLine;
            }
        }
        $hcWhat = implode("\n", $hcKept);
    }
    $hcWhat = str_replace(htmlspecialchars($hcTypedEmail, ENT_QUOTES, 'UTF-8'), 'EMAIL', $hcWhat);
    return (string) preg_replace('/[0-9a-f]{64}/', 'TOKEN', $hcWhat);
}

$hcSignInMails = static function () use ($hcCount): int {
    return $hcCount("SELECT COUNT(*) FROM `tblEmailQueue` WHERE `templateKey` = 'email_sign_in'");
};
$hcJarA = tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_');
$hcJarB = tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_');
$hcPage = hcHttp('GET', $hcBase . '/login/', $hcJarA);
hcCheck('K', str_contains($hcPage['body'], 'name="human_check_email_route"') && strpos($hcPage['body'], '<noscript>') < strpos($hcPage['body'], 'name="human_check_email_route"'), 'GET /login/: the "Sign in by email confirmation instead" button is inside <noscript>');
$hcFailsBefore = $hcCount('SELECT `failedLoginCount` FROM `tblUsers` WHERE `userID` = ' . $hcUserID);
$hcPost = hcStartEmailSignIn($hcBase, $hcJarA, $hcUserEmail, 'wrong-password-email');
hcCheck('K', str_contains($hcPost['body'], 'If the email address and password are right') && !str_contains($hcPost['body'], 'Invalid email or password') && $hcSignInMails() === 0 && $hcCount('SELECT `failedLoginCount` FROM `tblUsers` WHERE `userID` = ' . $hcUserID) === $hcFailsBefore, 'wrong password: the SAME "if the email address and password are right" answer as a right one (review M1), NO email, and NOT counted toward the lock-out (third-round review Medium 1)');
$hcPost = hcStartEmailSignIn($hcBase, $hcJarA, 'nobody@it.cuercode.invalid', 'whatever-password');
hcCheck('K', str_contains($hcPost['body'], 'If the email address and password are right') && $hcSignInMails() === 0, 'no such account: the same answer, no email');
hcHttp('POST', $hcBase . '/login/', $hcJarA, ['csrf_token' => hcCsrf(hcHttp('GET', $hcBase . '/login/', $hcJarA)['body']), 'login_email_cancel' => '1']);
$hcStarted = microtime(true);
$hcPost = hcStartEmailSignIn($hcBase, $hcJarA, $hcUserEmail, $hcUserPassword);
$hcTook = microtime(true) - $hcStarted;
$hcToken1 = hcLatestSignInToken($hcServer);
$hcSignInTokens[] = $hcToken1;
hcCheck('K', str_contains($hcPost['body'], 'id="login-email-pending"') && $hcSignInMails() === 1 && $hcToken1 !== '', 'right password: "check your email", ONE sign-in email with a link (' . round($hcTook, 2) . ' s)');
hcCheck('K', $hcCount("SELECT COUNT(*) FROM `tblEmailSignInRequests` WHERE `status` = 'waiting' AND `tokenHash` = SHA2('" . $hcToken1 . "', 256)") === 1 && $hcCount("SELECT COUNT(*) FROM `tblEmailSignInRequests` WHERE `tokenHash` = '" . $hcToken1 . "'") === 0, 'the request is stored with only the HASH of the link');
hcCheck('K', hcHttp('GET', $hcBase . '/account/', $hcJarA)['status'] !== 200, 'the first browser is NOT signed in yet');
$hcConfirm = hcHttp('GET', $hcBase . '/login/confirm/?token=' . $hcToken1, $hcJarB);
hcCheck('K', $hcConfirm['status'] === 200 && str_contains($hcConfirm['body'], 'This browser will not be signed in') && $hcCount("SELECT COUNT(*) FROM `tblEmailSignInRequests` WHERE `status` = 'waiting' AND `tokenHash` = SHA2('" . $hcToken1 . "', 256)") === 1, 'link opened in ANOTHER browser: it says plainly that browser will not be signed in, and opening it changed nothing');
$hcPost = hcHttp('POST', $hcBase . '/login/confirm/', $hcJarB, ['csrf_token' => hcCsrf($hcConfirm['body']), 'confirm_submit' => '1', 'token' => $hcToken1]);
hcCheck('K', str_contains($hcPost['body'], 'This browser has not been signed in') && hcHttp('GET', $hcBase . '/account/', $hcJarB)['status'] !== 200, 'its button: "approved", and that other browser is still NOT signed in');
$hcPost = hcHttp('POST', $hcBase . '/login/confirm/', $hcJarB, ['csrf_token' => hcCsrf($hcConfirm['body']), 'confirm_submit' => '1', 'token' => $hcToken1]);
hcCheck('K', str_contains($hcPost['body'], 'expired, has already been used'), 'the same link a second time: refused (single use)');
$hcPage = hcHttp('GET', $hcBase . '/login/', $hcJarA);
$hcPost = hcHttp('POST', $hcBase . '/login/', $hcJarA, ['csrf_token' => hcCsrf($hcPage['body']), 'login_email_continue' => '1']);
hcCheck('K', $hcPost['status'] === 302 && hcHttp('GET', $hcBase . '/account/', $hcJarA)['status'] === 200, 'the first browser presses Continue: it is signed in');
hcCheck('K', $hcCount("SELECT COUNT(*) FROM `tblEmailSignInRequests` WHERE `status` = 'used' AND `tokenHash` = SHA2('" . $hcToken1 . "', 256)") === 1, '... and the request is marked used');

// 🖥️ The same browser opens the link: signed in after the button press.
$hcJarC = tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_');
hcStartEmailSignIn($hcBase, $hcJarC, $hcUserEmail, $hcUserPassword);
$hcToken2 = hcLatestSignInToken($hcServer);
$hcSignInTokens[] = $hcToken2;
$hcConfirm = hcHttp('GET', $hcBase . '/login/confirm/?token=' . $hcToken2, $hcJarC);
hcCheck('K', str_contains($hcConfirm['body'], 'Sign in on this browser') && hcHttp('GET', $hcBase . '/account/', $hcJarC)['status'] !== 200, 'link opened in the SAME browser: a "Sign in on this browser" button, nobody signed in by opening it');
$hcPost = hcHttp('POST', $hcBase . '/login/confirm/', $hcJarC, ['csrf_token' => hcCsrf($hcConfirm['body']), 'confirm_submit' => '1', 'token' => $hcToken2]);
hcCheck('K', $hcPost['status'] === 302 && hcHttp('GET', $hcBase . '/account/', $hcJarC)['status'] === 200, '... the button signs that browser in');

// 🕐 A password reset (the "sessions valid from" mark moves) kills a waiting link.
$hcJarD = tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_');
hcStartEmailSignIn($hcBase, $hcJarD, $hcUserEmail, $hcUserPassword);
$hcToken3 = hcLatestSignInToken($hcServer);
$hcSignInTokens[] = $hcToken3;
authMoveSessionsValidFrom($hcUserID);
$hcConfirm = hcHttp('GET', $hcBase . '/login/confirm/?token=' . $hcToken3, $hcJarD);
$hcPost = hcHttp('POST', $hcBase . '/login/confirm/', $hcJarD, ['csrf_token' => hcCsrf($hcConfirm['body']), 'confirm_submit' => '1', 'token' => $hcToken3]);
hcCheck('K', str_contains($hcConfirm['body'], 'no longer valid') && $hcPost['status'] === 200 && hcHttp('GET', $hcBase . '/account/', $hcJarD)['status'] !== 200, 'after a password reset moved the account\'s mark: the waiting link no longer works');

// ⏳ An expired link.
$hcJarE = tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_');
hcStartEmailSignIn($hcBase, $hcJarE, $hcUserEmail, $hcUserPassword);
$hcToken4 = hcLatestSignInToken($hcServer);
$hcSignInTokens[] = $hcToken4;
$hcServer->query("UPDATE `tblEmailSignInRequests` SET `expiresAt` = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE `tokenHash` = SHA2('" . $hcToken4 . "', 256)");
$hcConfirm = hcHttp('GET', $hcBase . '/login/confirm/?token=' . $hcToken4, $hcJarE);
hcCheck('K', str_contains($hcConfirm['body'], 'has expired'), 'an expired link is refused');

// 🚦 The per-mailbox limit (back to the default of 3 a day; four sign-in
//    emails have gone to this mailbox already). Shown only AFTER the
//    password, as the same answer, so it says nothing about the account.
$hcServer->query("UPDATE `tblSettings` SET `settingValue` = '3' WHERE `settingKey` = 'no_script_route_email_per_day'");
$hcMailsBefore = $hcSignInMails();
$hcPost = hcStartEmailSignIn($hcBase, tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_'), $hcUserEmail, $hcUserPassword);
hcCheck('K', str_contains($hcPost['body'], 'If the email address and password are right') && str_contains($hcPost['body'], 'id="login-email-pending"') && $hcSignInMails() === $hcMailsBefore, 'over the per-mailbox limit: the same answer as always (nothing about the account), and no email');

// 🙈 Sign-up and forgot password without JavaScript: the same answer for an
//    existing and a new address, in at least the minimum time.
$hcServer->query("UPDATE `tblSettings` SET `settingValue` = '20' WHERE `settingKey` = 'no_script_route_email_per_day'");
$hcAnswers = [];
foreach (['existing' => $hcUserEmail, 'new' => 'brand-new@it.cuercode.invalid'] as $hcWhich => $hcAddress) {
    $hcJarF = tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_');
    $hcPage = hcHttp('GET', $hcBase . '/register/', $hcJarF);
    $hcStarted = microtime(true);
    $hcPost = hcHttp('POST', $hcBase . '/register/', $hcJarF, ['csrf_token' => hcCsrf($hcPage['body']), 'register_submit' => '1', 'email' => $hcAddress, 'password' => 'Plenty-Long-Pass-41', 'password_confirm' => 'Plenty-Long-Pass-41', 'human_check_email_route' => '1']);
    $hcSeconds = microtime(true) - $hcStarted;
    $hcMessage = '';
    if (preg_match('/<div class="alert alert-success" role="status">(.*?)<\/div>/s', $hcPost['body'], $hcMatch) === 1) {
        $hcMessage = trim((string) preg_replace('/\s+/', ' ', strip_tags($hcMatch[1])));
    }
    $hcAnswers[$hcWhich] = ['message' => $hcMessage, 'seconds' => $hcSeconds];
}
hcCheck('K', $hcAnswers['existing']['message'] !== '' && $hcAnswers['existing']['message'] === $hcAnswers['new']['message'], 'no-JavaScript sign-up: the SAME answer for an existing and a new address');
hcCheck('K', $hcAnswers['existing']['seconds'] >= 0.4 && $hcAnswers['new']['seconds'] >= 0.4, 'no-JavaScript sign-up: both take at least the minimum 0.4 s (existing ' . round($hcAnswers['existing']['seconds'], 3) . ' s, new ' . round($hcAnswers['new']['seconds'], 3) . ' s)');
hcCheck('K', $hcCount("SELECT COUNT(*) FROM `tblUsers` WHERE `email` = 'brand-new@it.cuercode.invalid' AND `status` = 'pending'") === 1, '... the new address got a WAITING account (usable only from its emailed link)');
$hcResetsBefore = $hcCount("SELECT COUNT(*) FROM `tblEmailQueue` WHERE `templateKey` = 'password_reset'");
$hcForgotAnswers = [];
foreach (['existing' => $hcUserEmail, 'unknown' => 'nobody-here@it.cuercode.invalid'] as $hcWhich => $hcAddress) {
    $hcJarF = tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_');
    $hcPage = hcHttp('GET', $hcBase . '/forgot-password/', $hcJarF);
    $hcPost = hcHttp('POST', $hcBase . '/forgot-password/', $hcJarF, ['csrf_token' => hcCsrf($hcPage['body']), 'forgot_submit' => '1', 'email' => $hcAddress, 'human_check_email_route' => '1']);
    $hcForgotAnswers[$hcWhich] = (string) preg_replace('/\s+/', ' ', strip_tags($hcPost['body']));
}
hcCheck('K', str_contains($hcForgotAnswers['existing'], 'If that account exists') && str_contains($hcForgotAnswers['unknown'], 'If that account exists') && $hcCount("SELECT COUNT(*) FROM `tblEmailQueue` WHERE `templateKey` = 'password_reset'") === $hcResetsBefore + 1, 'no-JavaScript forgot password: the same answer either way; one reset email, for the real account only');
hcCheck('K', $hcCount('SELECT COUNT(*) FROM `tblNoScriptRequests`') > 0 && $hcCount("SELECT COUNT(*) FROM `tblNoScriptRequests` WHERE `emailHash` LIKE '%@%'") === 0, 'the route\'s record holds only keyed hashes, never an address');

// ==============================================================================
// M. Review M1: right password, wrong password, unknown, locked and waiting
//    accounts look EXACTLY the same, on the first answer and every later page
// ==============================================================================
echo PHP_EOL . 'M. No-JavaScript sign-in: the same answer whatever the password (review M1)' . PHP_EOL;
$hcServer->query("UPDATE `tblSettings` SET `settingValue` = '100' WHERE `settingKey` = 'no_script_route_ip_per_hour'");
$hcServer->query("UPDATE `tblSettings` SET `settingValue` = '20' WHERE `settingKey` = 'no_script_route_email_per_day'");
$hcServer->query("UPDATE `tblSettings` SET `settingValue` = '1000' WHERE `settingKey` = 'no_script_route_site_per_hour'");
$hcServer->query('UPDATE `tblUsers` SET `failedLoginCount` = 0, `lockedUntil` = NULL WHERE `userID` = ' . $hcUserID);
$hcLockedPassword  = 'Locked-Pass-' . bin2hex(random_bytes(4));
$hcPendingPassword = 'Waiting-Pass-' . bin2hex(random_bytes(4));
$hcLockedID  = hcAddUser($hcServer, 'locked@it.cuercode.invalid', $hcLockedPassword, 'active', 'DATE_ADD(NOW(), INTERVAL 1 HOUR)');
$hcPendingID = hcAddUser($hcServer, 'waiting@it.cuercode.invalid', $hcPendingPassword, 'pending', null);
$hcCases = [
    'unknown account'                  => ['nobody-at-all@it.cuercode.invalid', 'Some-Password-1'],
    'wrong password'                   => [$hcUserEmail, 'wrong-password-m1'],
    'locked account (right password)'  => ['locked@it.cuercode.invalid', $hcLockedPassword],
    'waiting account (right password)' => ['waiting@it.cuercode.invalid', $hcPendingPassword],
    'right password'                   => [$hcUserEmail, $hcUserPassword],
];
$hcSeen = [];
foreach ($hcCases as $hcCase => $hcPair) {
    $hcMailsBefore = $hcSignInMails();
    $hcJarM = tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_');
    $hcPage = hcHttp('GET', $hcBase . '/login/', $hcJarM);
    $hcCookieBefore = (string) file_get_contents($hcJarM);
    $hcStarted = microtime(true);
    $hcFirst = hcHttp('POST', $hcBase . '/login/', $hcJarM, ['csrf_token' => hcCsrf($hcPage['body']), 'login_submit' => '1', 'email' => $hcPair[0], 'password' => $hcPair[1], 'human_check_email_route' => '1']);
    $hcSeconds = microtime(true) - $hcStarted;
    $hcCookieAfter = (string) file_get_contents($hcJarM);
    $hcLater = hcHttp('GET', $hcBase . '/login/', $hcJarM);
    $hcContinue = hcHttp('POST', $hcBase . '/login/', $hcJarM, ['csrf_token' => hcCsrf($hcLater['body']), 'login_email_continue' => '1']);
    $hcAccount = hcHttp('GET', $hcBase . '/account/', $hcJarM);
    $hcAgain = hcHttp('POST', $hcBase . '/login/', $hcJarM, ['csrf_token' => hcCsrf($hcContinue['body']), 'login_email_cancel' => '1']);
    $hcSeen[$hcCase] = [
        'answer'   => $hcFirst['status'] . "\n" . hcSameShape($hcFirst['headers'], $hcPair[0]) . "\n" . hcSameShape($hcFirst['body'], $hcPair[0]),
        'later'    => $hcLater['status'] . "\n" . hcSameShape($hcLater['headers'], $hcPair[0]) . "\n" . hcSameShape($hcLater['body'], $hcPair[0]),
        'continue' => $hcContinue['status'] . "\n" . hcSameShape($hcContinue['headers'], $hcPair[0]) . "\n" . hcSameShape($hcContinue['body'], $hcPair[0]),
        'again'    => $hcAgain['status'] . "\n" . hcSameShape($hcAgain['headers'], $hcPair[0]) . "\n" . hcSameShape($hcAgain['body'], $hcPair[0]),
        'account'  => (string) $hcAccount['status'],
        'cookie'   => hcSameShape(preg_replace('/\t\d{9,}\t/', "\tTIME\t", $hcCookieAfter), '') === hcSameShape(preg_replace('/\t\d{9,}\t/', "\tTIME\t", $hcCookieBefore), ''),
        'seconds'  => $hcSeconds,
        'mails'    => $hcSignInMails() - $hcMailsBefore,
    ];
}
$hcRight = $hcSeen['right password'];
hcCheck('M', $hcRight['mails'] === 1 && str_contains($hcRight['answer'], 'If the email address and password are right'), 'right password: one sign-in email, and the answer "if the email address and password are right"');
foreach ($hcSeen as $hcCase => $hcWhat) {
    if ($hcCase === 'right password') {
        continue;
    }
    hcCheck('M', $hcWhat['mails'] === 0, $hcCase . ': no email');
    hcCheck('M', $hcWhat['answer'] === $hcRight['answer'], $hcCase . ': the first answer is byte for byte the right password\'s (status, headers apart from the date, page)');
    hcCheck('M', $hcWhat['later'] === $hcRight['later'], $hcCase . ': the sign-in page opened again afterwards is the same (the same waiting box)');
    hcCheck('M', $hcWhat['continue'] === $hcRight['continue'], $hcCase . ': pressing Continue gives the same answer ("not signed in yet")');
    hcCheck('M', $hcWhat['again'] === $hcRight['again'], $hcCase . ': pressing Start again gives the same answer');
    hcCheck('M', $hcWhat['account'] === $hcRight['account'] && $hcWhat['cookie'] === $hcRight['cookie'], $hcCase . ': nobody is signed in, and the browser\'s cookies changed in the same way');
}
$hcTimes = [];
foreach ($hcSeen as $hcCase => $hcWhat) {
    $hcTimes[$hcCase] = round($hcWhat['seconds'], 3);
}
hcCheck('M', min($hcTimes) >= 0.4 && max($hcTimes) - min($hcTimes) < 0.15, 'every case takes at least the minimum 0.4 s and they are within 0.15 s of each other: ' . json_encode($hcTimes));
hcCheck('M', $hcCount('SELECT `failedLoginCount` FROM `tblUsers` WHERE `userID` = ' . $hcUserID) === 0 && $hcCount('SELECT `failedLoginCount` FROM `tblUsers` WHERE `userID` = ' . $hcLockedID) === 5, 'the wrong password was not counted toward the lock-out (third-round review Medium 1); the locked account\'s count was not changed');

// ==============================================================================
// N. Review L1, L2, L3 and the per-address limit over real HTTP
// ==============================================================================
echo PHP_EOL . 'N. No-JavaScript route limits over real HTTP (review L1, L2, L3, L5, I2, I3)' . PHP_EOL;
$hcServer->query('DELETE FROM `tblNoScriptRequests`');
$hcServer->query("UPDATE `tblSettings` SET `settingValue` = '3' WHERE `settingKey` = 'no_script_route_email_per_day'");
$hcServer->query("UPDATE `tblSettings` SET `settingValue` = '2' WHERE `settingKey` = 'no_script_route_site_per_hour'");
// 🔢 The older per-address limits of the forgot-password and new-link forms
//    (10 an hour) have been used up by the scenarios above; raised so that
//    only the route's own limits are being tested here.
hcCheck('N', hcSet('password_reset_request_ip_per_hour', '1000') && hcSet('verification_resend_ip_per_hour', '1000'), 'set-up: the older per-address limits of the forgot-password and new-link forms raised (their rows are added if missing)');
$hcVictimEmail = 'victim@it.cuercode.invalid';
$hcVictimPassword = 'Victim-Pass-' . bin2hex(random_bytes(4));
$hcVictimID = hcAddUser($hcServer, $hcVictimEmail, $hcVictimPassword, 'active', null);
$hcVictimMails = static function () use ($hcCount, $hcVictimEmail): int {
    return $hcCount("SELECT COUNT(*) FROM `tblEmailQueue` WHERE `templateKey` = 'email_sign_in' AND `toAddress` = '" . $hcVictimEmail . "'");
};
$hcRouteForm = static function (string $hcPath, array $hcFields) use ($hcBase): array {
    $hcJarN = tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_');
    $hcPage = hcHttp('GET', $hcBase . '/' . $hcPath . '/', $hcJarN);
    return hcHttp('POST', $hcBase . '/' . $hcPath . '/', $hcJarN, array_merge(['csrf_token' => hcCsrf($hcPage['body']), 'human_check_email_route' => '1'], $hcFields));
};
$hcResetsBefore = $hcCount("SELECT COUNT(*) FROM `tblEmailQueue` WHERE `templateKey` = 'password_reset'");
for ($hcI = 1; $hcI <= 5; $hcI++) {
    $hcRouteForm('forgot-password', ['forgot_submit' => '1', 'email' => 'made-up-' . $hcI . '@it.cuercode.invalid']);
}
for ($hcI = 1; $hcI <= 3; $hcI++) {
    $hcRouteForm('register', ['register_submit' => '1', 'email' => 'flood-' . $hcI . '@it.cuercode.invalid', 'password' => 'Plenty-Long-Pass-41', 'password_confirm' => 'Plenty-Long-Pass-41']);
}
hcCheck('N', $hcCount("SELECT COUNT(*) FROM `tblEmailQueue` WHERE `templateKey` = 'password_reset'") === $hcResetsBefore && $hcCount("SELECT COUNT(*) FROM `tblUsers` WHERE `email` LIKE 'flood-%'") === 2, 'L1 set-up: five forgot-password requests for made-up addresses sent nothing; three sign-ups stopped at this form\'s own limit of 2');
hcAddUser($hcServer, 'forgot-check@it.cuercode.invalid', 'Forgot-Check-' . bin2hex(random_bytes(4)), 'active', null);
$hcRouteForm('forgot-password', ['forgot_submit' => '1', 'email' => 'forgot-check@it.cuercode.invalid']);
hcCheck('N', $hcCount("SELECT COUNT(*) FROM `tblEmailQueue` WHERE `templateKey` = 'password_reset' AND `toAddress` = 'forgot-check@it.cuercode.invalid'") === 1, 'L1: after five requests for made-up addresses, a real forgot-password request (this form\'s limit is 2 an hour) still gets its email: places that sent nothing were given back');
$hcPost = hcStartEmailSignIn($hcBase, tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_'), $hcVictimEmail, $hcVictimPassword);
hcCheck('N', $hcVictimMails() === 1, 'L1: after that flood the real person\'s sign-in still gets its email (made-up addresses use up nothing, and a sign-up flood only uses up the sign-up form\'s own limit)');
$hcFailsBefore = $hcCount('SELECT `failedLoginCount` FROM `tblUsers` WHERE `userID` = ' . $hcVictimID);
for ($hcI = 1; $hcI <= 3; $hcI++) {
    hcStartEmailSignIn($hcBase, tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_'), $hcVictimEmail, 'stranger-guess-' . $hcI);
}
$hcFailsAfterGuesses = $hcCount('SELECT `failedLoginCount` FROM `tblUsers` WHERE `userID` = ' . $hcVictimID);
hcStartEmailSignIn($hcBase, tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_'), $hcVictimEmail, $hcVictimPassword);
hcCheck('N', $hcVictimMails() === 2, 'L2: three wrong guesses by a stranger (the daily per-mailbox number) do not block the person: their right password still gets an email');
hcCheck('N', $hcFailsAfterGuesses === $hcFailsBefore, 'L2 and third-round review Medium 1: ... and the wrong guesses are NOT counted toward the lock-out (the route has its own per-account number of password checks instead, scenario R): ' . $hcFailsBefore . ' -> ' . $hcFailsAfterGuesses);
$hcServer->query("UPDATE `tblSettings` SET `settingValue` = '100' WHERE `settingKey` = 'no_script_route_site_per_hour'");
for ($hcI = 1; $hcI <= 4; $hcI++) {
    $hcRouteForm('register', ['register_submit' => '1', 'email' => 'Target+' . $hcI . '@it.cuercode.invalid', 'password' => 'Plenty-Long-Pass-41', 'password_confirm' => 'Plenty-Long-Pass-41']);
}
hcCheck('N', $hcCount("SELECT COUNT(*) FROM `tblEmailQueue` WHERE `templateKey` = 'verification' AND `toAddress` LIKE 'target+%'") === 3 && $hcCount("SELECT COUNT(*) FROM `tblUsers` WHERE `email` LIKE 'target+%'") === 3, 'L3: four sign-ups for target+1 to target+4 (one real mailbox): exactly 3 emails (the per-mailbox limit), no 4th account');
hcCheck('N', hcValue($hcServer, "SELECT GROUP_CONCAT(`email` ORDER BY `email`) FROM `tblUsers` WHERE `email` LIKE 'target+%'") === 'target+1@it.cuercode.invalid,target+2@it.cuercode.invalid,target+3@it.cuercode.invalid' && hcValue($hcServer, "SELECT GROUP_CONCAT(`toAddress` ORDER BY `toAddress`) FROM `tblEmailQueue` WHERE `templateKey` = 'verification' AND `toAddress` LIKE 'target+%'") === 'target+1@it.cuercode.invalid,target+2@it.cuercode.invalid,target+3@it.cuercode.invalid', 'L3: the stored and emailed addresses keep their +tags (only sign-up\'s usual lower-casing); only the counting ignores the tags');
// 🚦 L5 (b): over the per-address limit, the three forms do nothing at all.
$hcServer->query('DELETE FROM `tblNoScriptRequests`');
$hcServer->query("UPDATE `tblSettings` SET `settingValue` = '1' WHERE `settingKey` = 'no_script_route_ip_per_hour'");
$hcRouteForm('forgot-password', ['forgot_submit' => '1', 'email' => 'uses-the-one-place@it.cuercode.invalid']);
$hcUsersBefore = $hcCount('SELECT COUNT(*) FROM `tblUsers`');
$hcEmailsBefore = $hcCount('SELECT COUNT(*) FROM `tblEmailQueue`');
$hcRouteForm('register', ['register_submit' => '1', 'email' => 'over-the-limit@it.cuercode.invalid', 'password' => 'Plenty-Long-Pass-41', 'password_confirm' => 'Plenty-Long-Pass-41']);
$hcRouteForm('forgot-password', ['forgot_submit' => '1', 'email' => $hcVictimEmail]);
$hcRouteForm('resend-verification', ['resend_submit' => '1', 'email' => 'waiting@it.cuercode.invalid']);
hcCheck('N', $hcCount('SELECT COUNT(*) FROM `tblUsers`') === $hcUsersBefore && $hcCount('SELECT COUNT(*) FROM `tblEmailQueue`') === $hcEmailsBefore, 'L5: the 2nd use from one address (limit 1): sign-up makes NO account, forgot password and new link queue NO email');
$hcStarted = microtime(true);
$hcPost = hcStartEmailSignIn($hcBase, tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_'), $hcVictimEmail, $hcVictimPassword);
$hcSeconds = microtime(true) - $hcStarted;
hcCheck('N', str_contains($hcPost['body'], 'from your connection') && !str_contains($hcPost['body'], 'for this address') && $hcVictimMails() === 2, 'I2: over the per-address limit, sign-in says it is about "your connection" (and not the email address); no email');
hcCheck('N', $hcSeconds < 0.4, 'I3: ... answered at once, without the lock wait or the minimum time (' . round($hcSeconds, 3) . ' s for the page and the post)');
$hcServer->query("UPDATE `tblSettings` SET `settingValue` = '100' WHERE `settingKey` = 'no_script_route_ip_per_hour'");
$hcRouteForm('register', ['register_submit' => '1', 'email' => 'over-the-limit@it.cuercode.invalid', 'password' => 'Plenty-Long-Pass-41', 'password_confirm' => 'Plenty-Long-Pass-41']);
$hcRouteForm('forgot-password', ['forgot_submit' => '1', 'email' => $hcVictimEmail]);
$hcRouteForm('resend-verification', ['resend_submit' => '1', 'email' => 'waiting@it.cuercode.invalid']);
hcCheck('N', $hcCount('SELECT COUNT(*) FROM `tblUsers`') === $hcUsersBefore + 1 && $hcCount('SELECT COUNT(*) FROM `tblEmailQueue`') === $hcEmailsBefore + 3, 'L5 control: with the limit raised the same three requests DO make the account and queue the three emails (so the check above can see them): ' . ($hcCount('SELECT COUNT(*) FROM `tblEmailQueue`') - $hcEmailsBefore) . ' email(s)');
// 🔒 I2: the lock held elsewhere: "busy", not "too many from your connection".
$hcLockName = '';
if (function_exists('noScriptRouteLockName')) {
    $hcLockName = noScriptRouteLockName();
}
$hcPost = ['body' => ''];
if ($hcLockName !== '') {
    $hcServer->query("SELECT GET_LOCK('" . $hcServer->real_escape_string($hcLockName) . "', 5)");
    $hcPost = hcStartEmailSignIn($hcBase, tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_'), $hcVictimEmail, $hcVictimPassword);
    $hcServer->query("SELECT RELEASE_LOCK('" . $hcServer->real_escape_string($hcLockName) . "')");
}
hcCheck('N', $hcLockName !== '' && str_contains($hcPost['body'], 'busy just now') && !str_contains($hcPost['body'], 'from your connection'), 'I2: the route\'s lock held elsewhere: the answer says the route is busy, not that the visitor sent too many');

// ==============================================================================
// R. Third-round review Medium 1: the route can never lock an account out
// ==============================================================================
echo PHP_EOL . 'R. No-JavaScript sign-in cannot lock anyone out (third-round review, Medium 1)' . PHP_EOL;
$hcServer->query('DELETE FROM `tblNoScriptRequests`');
$hcServer->query("DELETE FROM `tblSettings` WHERE `settingKey` = 'no_script_route_password_per_day'");
foreach (['no_script_route_ip_per_hour' => '5', 'no_script_route_email_per_day' => '3', 'no_script_route_site_per_hour' => '100'] as $hcLimitKey => $hcLimitValue) {
    hcSet($hcLimitKey, $hcLimitValue);
}
$hcTargetEmail    = 'lockout-target@it.cuercode.invalid';
$hcTargetPassword = 'Target-Pass-' . bin2hex(random_bytes(4));
$hcTargetID       = hcAddUser($hcServer, $hcTargetEmail, $hcTargetPassword, 'active', null);
$hcTargetMails    = static function () use ($hcCount, $hcTargetEmail): int {
    return $hcCount("SELECT COUNT(*) FROM `tblEmailQueue` WHERE `templateKey` = 'email_sign_in' AND `toAddress` = '" . $hcTargetEmail . "'");
};
$hcTargetLockState = static function () use ($hcServer, $hcTargetID): string {
    return hcValue($hcServer, "SELECT CONCAT(`failedLoginCount`, '|', `lockedUntil` IS NULL) FROM `tblUsers` WHERE `userID` = " . $hcTargetID);
};
// 1️⃣ The reviewer's case: five wrong posts from ONE address, inside the default per-address limit of 5.
$hcWrongAnswers = [];
for ($hcI = 1; $hcI <= 5; $hcI++) {
    $hcWrongAnswers[] = hcStartEmailSignIn($hcBase, tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_'), $hcTargetEmail, 'stranger-guess-' . $hcI)['body'];
}
hcCheck('R', $hcTargetLockState() === '0|1', 'five wrong passwords through the route from one address: not counted toward the lock-out and the account is NOT locked (count|not locked = ' . $hcTargetLockState() . ')');
$hcPost = hcStartEmailSignIn($hcBase, tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_'), $hcTargetEmail, 'stranger-guess-6');
hcCheck('R', str_contains($hcPost['body'], 'from your connection'), 'the 6th post from that address is stopped by the per-address limit (guessing stays bounded per address)');
// 2️⃣ More addresses (here: a higher per-address limit) still cannot lock it, and the
//    route checks at most the per-account daily number of wrong passwords (default 10).
hcSet('no_script_route_ip_per_hour', '100');
for ($hcI = 7; $hcI <= 14; $hcI++) {
    $hcWrongAnswers[] = hcStartEmailSignIn($hcBase, tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_'), $hcTargetEmail, 'stranger-guess-' . $hcI)['body'];
}
hcCheck('R', $hcTargetLockState() === '0|1', 'thirteen wrong passwords through the route in all: still not counted, still not locked (' . $hcTargetLockState() . ')');
hcCheck('R', $hcCount("SELECT COUNT(*) FROM `tblNoScriptRequests` WHERE `purpose` = 'login_wrong'") === 10, 'only the first 10 wrong passwords (the per-account daily number) were checked at all, so guessing stays bounded per account (' . $hcCount("SELECT COUNT(*) FROM `tblNoScriptRequests` WHERE `purpose` = 'login_wrong'") . ')');
$hcRightJar = tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_');
$hcPost = hcStartEmailSignIn($hcBase, $hcRightJar, $hcTargetEmail, $hcTargetPassword);
$hcSameAsWrong = true;
foreach ($hcWrongAnswers as $hcWrongBody) {
    if (hcSameShape($hcWrongBody, $hcTargetEmail) !== hcSameShape($hcPost['body'], $hcTargetEmail)) {
        $hcSameAsWrong = false;
    }
}
hcCheck('R', $hcSameAsWrong && str_contains($hcPost['body'], 'If the email address and password are right') && $hcTargetMails() === 0, 'over that number the right password is not checked either: the same page as every wrong guess, and no email');
// 🔤 Fourth-round review, Medium 1: a differently spelled address (an accent,
//    a zero-width space, a control character, a full-width letter) is the
//    same address to the database's own comparison, and is counted under its
//    own spelling. It must NOT get a fresh set of checks against this account:
//    with the right password, no email.
$hcVariantSpellings = [
    'accent'     => "lockout-targ\u{00E9}t@it.cuercode.invalid",
    'zero-width' => "lockout-tar\u{200B}get@it.cuercode.invalid",
    'control'    => "lockout-tar\x01get@it.cuercode.invalid",
    'full-width' => "\u{FF4C}ockout-target@it.cuercode.invalid",
];
foreach ($hcVariantSpellings as $hcSpellingName => $hcSpelling) {
    $hcPost = hcStartEmailSignIn($hcBase, tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_'), $hcSpelling, $hcTargetPassword);
    hcCheck('R', hcValue($hcServer, "SELECT COUNT(*) FROM `tblUsers` WHERE `email` = '" . $hcServer->real_escape_string($hcSpelling) . "'") === '1', 'set-up: this server\'s own comparison treats the ' . $hcSpellingName . ' spelling as the account\'s address');
    hcCheck('R', str_contains($hcPost['body'], 'If the email address and password are right') && $hcTargetMails() === 0, 'fourth-round review, Medium 1: the ' . $hcSpellingName . ' spelling with the RIGHT password gets the usual answer and NO email (it is not a way round the per-account number)');
}
// 3️⃣ The real person signs in through the normal form straight away.
hcCli($hcDbName, ['off']);
$hcNormalJar = tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_');
$hcPage = hcHttp('GET', $hcBase . '/login/', $hcNormalJar);
$hcPost = hcHttp('POST', $hcBase . '/login/', $hcNormalJar, ['csrf_token' => hcCsrf($hcPage['body']), 'login_submit' => '1', 'email' => $hcTargetEmail, 'password' => $hcTargetPassword]);
hcCheck('R', $hcPost['status'] === 302 && hcHttp('GET', $hcBase . '/account/', $hcNormalJar)['status'] === 200, 'after all those wrong route posts, the right password through the NORMAL form signs the person in (HTTP ' . $hcPost['status'] . ')');
hcCli($hcDbName, ['on']);
// 4️⃣ The normal form's own lock-out is unchanged: five wrong passwords there still lock.
hcCli($hcDbName, ['off']);
$hcNormalJar = tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_');
for ($hcI = 1; $hcI <= 5; $hcI++) {
    $hcPage = hcHttp('GET', $hcBase . '/login/', $hcNormalJar);
    hcHttp('POST', $hcBase . '/login/', $hcNormalJar, ['csrf_token' => hcCsrf($hcPage['body']), 'login_submit' => '1', 'email' => $hcTargetEmail, 'password' => 'normal-form-wrong-' . $hcI]);
}
hcCheck('R', $hcTargetLockState() === '5|0', 'the normal form still counts wrong passwords and locks after five (' . $hcTargetLockState() . ')');
hcCli($hcDbName, ['on']);
$hcServer->query('DELETE FROM `tblNoScriptRequests`');

// ==============================================================================
// P. Review L5 (a, c), L6 and I1: binding, security token, approvals, Start again
// ==============================================================================
echo PHP_EOL . 'P. Email sign-in links: other browsers, security token, approvals (review L5, L6, I1)' . PHP_EOL;
$hcServer->query("UPDATE `tblSettings` SET `settingValue` = '20' WHERE `settingKey` = 'no_script_route_email_per_day'");
$hcServer->query("UPDATE `tblSettings` SET `settingValue` = '1000' WHERE `settingKey` = 'no_script_route_site_per_hour'");
$hcStrangerPassword = 'Stranger-Pass-' . bin2hex(random_bytes(4));
hcAddUser($hcServer, 'stranger@it.cuercode.invalid', $hcStrangerPassword, 'active', null);
$hcFirefox = 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0';
$hcJarS = tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_');
hcStartEmailSignIn($hcBase, $hcJarS, 'stranger@it.cuercode.invalid', $hcStrangerPassword);
$hcSignInTokens[] = hcLatestSignInToken($hcServer);
$hcJarV = tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_');
hcStartEmailSignIn($hcBase, $hcJarV, $hcVictimEmail, $hcVictimPassword, [$hcFirefox]);
$hcTokenV = hcLatestSignInToken($hcServer);
$hcSignInTokens[] = $hcTokenV;
$hcConfirm = hcHttp('GET', $hcBase . '/login/confirm/?token=' . $hcTokenV, $hcJarS);
hcCheck('P', str_contains($hcConfirm['body'], 'This is not the browser where the password was typed'), 'L5 (a): a stranger whose OWN email sign-in is waiting opens the victim\'s link: told it is not the browser that asked');
hcCheck('P', str_contains($hcConfirm['body'], 'Firefox on Windows') && str_contains($hcConfirm['body'], '127.0.0.1') && str_contains($hcConfirm['body'], 'minute'), 'L6: the approve page shows when, from what browser and from what address the sign-in was asked for');
$hcPost = hcHttp('POST', $hcBase . '/login/confirm/', $hcJarS, ['csrf_token' => hcCsrf($hcConfirm['body']), 'confirm_submit' => '1', 'token' => $hcTokenV]);
hcCheck('P', $hcPost['status'] === 200 && hcHttp('GET', $hcBase . '/account/', $hcJarS)['status'] !== 200, 'L5 (a): ... its button only approves: the stranger\'s browser is NOT signed in (as anyone)');
$hcServer->query("UPDATE `tblEmailSignInRequests` SET `approvedAt` = DATE_SUB(NOW(), INTERVAL 4 MINUTE) WHERE `tokenHash` = SHA2('" . $hcTokenV . "', 256)");
$hcPage = hcHttp('GET', $hcBase . '/login/', $hcJarV);
$hcPost = hcHttp('POST', $hcBase . '/login/', $hcJarV, ['csrf_token' => hcCsrf($hcPage['body']), 'login_email_continue' => '1']);
hcCheck('P', $hcPost['status'] === 200 && hcHttp('GET', $hcBase . '/account/', $hcJarV)['status'] !== 200 && str_contains($hcPost['body'], 'not signed in yet'), 'L6: Continue 4 minutes after the approval: NOT signed in (the approval ran out after 3 minutes)');
$hcJarV2 = tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_');
hcStartEmailSignIn($hcBase, $hcJarV2, $hcVictimEmail, $hcVictimPassword);
$hcTokenV2 = hcLatestSignInToken($hcServer);
$hcSignInTokens[] = $hcTokenV2;
$hcJarX = tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_');
$hcConfirm = hcHttp('GET', $hcBase . '/login/confirm/?token=' . $hcTokenV2, $hcJarX);
$hcNoToken = hcHttp('POST', $hcBase . '/login/confirm/', $hcJarX, ['confirm_submit' => '1', 'token' => $hcTokenV2]);
$hcBadToken = hcHttp('POST', $hcBase . '/login/confirm/', $hcJarX, ['csrf_token' => str_repeat('ab', 32), 'confirm_submit' => '1', 'token' => $hcTokenV2]);
hcCheck('P', str_contains($hcNoToken['body'], 'Your session has expired') && str_contains($hcBadToken['body'], 'Your session has expired') && $hcCount("SELECT COUNT(*) FROM `tblEmailSignInRequests` WHERE `status` = 'waiting' AND `tokenHash` = SHA2('" . $hcTokenV2 . "', 256)") === 1, 'L5 (c): the confirm button pressed without, and with a wrong, security token: refused, and the link is still only waiting');
$hcPage = hcHttp('GET', $hcBase . '/login/', $hcJarV2);
hcHttp('POST', $hcBase . '/login/', $hcJarV2, ['csrf_token' => hcCsrf($hcPage['body']), 'login_email_cancel' => '1']);
hcCheck('P', $hcCount("SELECT COUNT(*) FROM `tblEmailSignInRequests` WHERE `status` = 'cancelled' AND `tokenHash` = SHA2('" . $hcTokenV2 . "', 256)") === 1, 'I1: "Start again" marks the request cancelled');
$hcConfirm = hcHttp('GET', $hcBase . '/login/confirm/?token=' . $hcTokenV2, $hcJarV2);
hcCheck('P', str_contains($hcConfirm['body'], 'no longer valid') && !str_contains($hcConfirm['body'], 'This is not the browser'), 'I1: ... so that browser\'s own link now says it is no longer valid (not "this is not the browser")');
$hcSessionsBefore = $hcCount('SELECT COUNT(*) FROM `tblUserSessions`');
$hcFinished = null;
try {
    $hcFinished = noScriptSignInFinish((array) noScriptSignInLoad('tokenHash', hashToken($hcTokenV2), false), '127.0.0.1', 'it');
} catch (\Throwable $hcError) {
    $hcFinished = 'error: ' . get_class($hcError);
}
hcCheck('P', $hcFinished === false && $hcCount('SELECT COUNT(*) FROM `tblUserSessions`') === $hcSessionsBefore, 'L5: the last step itself refuses a cancelled request (its own status guard), and no session is made (' . var_export($hcFinished, true) . ')');

// ==============================================================================
// Q. Review L4: keys that cannot be read leave the email route for everyone
// ==============================================================================
echo PHP_EOL . 'Q. Keys that cannot be read: the email route is offered (review L4)' . PHP_EOL;
$hcServer->query("UPDATE `tblSettings` SET `settingValue` = 'damaged-not-a-real-ciphertext' WHERE `settingKey` IN ('turnstile_secret_key', 'recaptcha_secret_key')");
$hcJarQ = tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_');
$hcPage = hcHttp('GET', $hcBase . '/register/', $hcJarQ);
$hcButtonAt = strpos($hcPage['body'], 'name="human_check_email_route"');
hcCheck('Q', $hcButtonAt !== false && !str_contains($hcPage['body'], 'class="cuercode-human-check"') && !str_contains($hcPage['body'], '<noscript><div class="alert alert-warning'), 'GET /register/ with both saved secrets unreadable: no provider widget, and the email-route button is drawn for everyone (not only inside <noscript>)');
$hcPost = hcHttp('POST', $hcBase . '/register/', $hcJarQ, ['csrf_token' => hcCsrf($hcPage['body']), 'register_submit' => '1', 'email' => 'broken-keys-normal@it.cuercode.invalid', 'password' => 'Plenty-Long-Pass-41', 'password_confirm' => 'Plenty-Long-Pass-41']);
hcCheck('Q', str_contains($hcPost['body'], 'could not be completed just now') && $hcCount("SELECT COUNT(*) FROM `tblUsers` WHERE `email` = 'broken-keys-normal@it.cuercode.invalid'") === 0, 'the normal form is still refused (the check is not switched off)');
$hcRouteForm('register', ['register_submit' => '1', 'email' => 'broken-keys-route@it.cuercode.invalid', 'password' => 'Plenty-Long-Pass-41', 'password_confirm' => 'Plenty-Long-Pass-41']);
hcCheck('Q', $hcCount("SELECT COUNT(*) FROM `tblUsers` WHERE `email` = 'broken-keys-route@it.cuercode.invalid' AND `status` = 'pending'") === 1, 'the email route works, with its limits (a WAITING account, usable only from its emailed link) - as the page and the documents now say');
hcSet('turnstile_secret_key', $hcSecrets['turnstileSecret']);
hcSet('recaptcha_secret_key', $hcSecrets['recaptchaSecret']);

// ==============================================================================
// L. The web forms' per-visitor limit stops the call to the provider
// ==============================================================================
echo PHP_EOL . 'L. Rate limit before the provider is asked' . PHP_EOL;
$hcServer->query("DELETE FROM `tblAPIRateLog` WHERE `endpoint` = 'page/human-check'");
$hcServer->query("UPDATE `tblSettings` SET `settingValue` = '3' WHERE `settingKey` = 'human_check_form_ip_per_hour'");
$hcJar = tempnam(sys_get_temp_dir(), 'cuercode_it_hc_jar_');
$hcLimitBodies = [];
for ($hcTry = 1; $hcTry <= 4; $hcTry++) {
    $hcPage = hcHttp('GET', $hcBase . '/forgot-password/', $hcJar);
    $hcPost = hcHttp('POST', $hcBase . '/forgot-password/', $hcJar, ['csrf_token' => hcCsrf($hcPage['body']), 'forgot_submit' => '1', 'email' => $hcUserEmail, 'cf-turnstile-response' => 'junk-token-' . $hcTry]);
    $hcLimitBodies[] = $hcPost['body'];
}
hcCheck('L', str_contains($hcLimitBodies[2], 'could not be completed just now') && str_contains($hcLimitBodies[3], 'Too many tries from your connection'), 'limit 3: the 3rd junk token is still checked, the 4th is refused before any call');
hcCheck('L', $hcCount("SELECT COUNT(*) FROM `tblAPIRateLog` WHERE `endpoint` = 'page/human-check'") === 4, 'each form send with a token is counted for the limit');
$hcServer->query("UPDATE `tblSettings` SET `settingValue` = '30' WHERE `settingKey` = 'human_check_form_ip_per_hour'");

// ==============================================================================
// G. No key in any log
// ==============================================================================
echo PHP_EOL . 'G. Logs' . PHP_EOL;
if ($hcWeb !== null) {
    itWebServerStop($hcWeb);
}
$hcLogText = (string) @file_get_contents($hcServerLog) . (string) @file_get_contents($hcServerLog . '.out');
// 🎫 The email sign-in links: in no log TABLE. (The web server's own access
//    log lists every address it was asked for, so it holds each emailed link,
//    exactly as it does for the verification and reset links; a link from it
//    can only APPROVE the browser that typed the password, never sign the
//    reader in - see DEV_NOTES.)
$hcDbSecrets = [];
foreach ($hcSignInTokens as $hcTokenIndex => $hcSignInToken) {
    if ($hcSignInToken !== '') {
        $hcDbSecrets['emailSignInLink' . ($hcTokenIndex + 1)] = $hcSignInToken;
    }
}
foreach (array_merge($hcSecrets, $hcDbSecrets) as $hcSecretName => $hcSecret) {
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
    if (!isset($hcDbSecrets[$hcSecretName]) && str_contains($hcLogText, $hcSecret)) {
        $hcWhere[] = 'web server log';
    }
    hcCheck('G', $hcWhere === [], $hcSecretName . ' is in no log ' . implode(', ', $hcWhere));
}
hcCheck('G', !str_contains($hcLogText, 'Fatal error') && !str_contains($hcLogText, 'Warning:') && !str_contains($hcLogText, 'Deprecated:'), 'the web server logged no PHP fatal error, warning or deprecation');

// ==============================================================================
// H. Review M1 on the real table
// ==============================================================================
echo PHP_EOL . 'H. A token is never accepted twice' . PHP_EOL;
clearConfigCache();
$hcYes = static function (string $hcUrl, array $hcFields, int $hcTimeout): array {
    return ['ok' => true, 'status' => 200, 'body' => (string) json_encode(['success' => true, 'hostname' => 'cuercode.net']), 'error' => '', 'fault' => ''];
};
$hcFirst  = humanCheckVerifyRequest(['cf-turnstile-response' => 'it-same-token'], ['transport' => $hcYes, 'allowedHosts' => ['cuercode.net']]);
$hcSecond = humanCheckVerifyRequest(['cf-turnstile-response' => 'it-same-token'], ['transport' => $hcYes, 'allowedHosts' => ['cuercode.net']]);
hcCheck('H', $hcFirst['reason'] === 'passed' && $hcSecond['reason'] === 'expired_or_reused', 'the same token twice (provider says yes both times): passed, then refused (' . $hcFirst['reason'] . ', ' . $hcSecond['reason'] . ')');
hcCheck('H', $hcCount("SELECT COUNT(*) FROM `tblHumanCheckTokens` WHERE `tokenHash` = SHA2('it-same-token', 256) AND `expiresAt` > NOW()") === 1 && $hcCount("SELECT COUNT(*) FROM `tblHumanCheckTokens` WHERE `tokenHash` = 'it-same-token'") === 0, 'one row, holding only the SHA-256 hash, kept for a while');
$hcRaceHash  = hash('sha256', 'it-race-token-' . bin2hex(random_bytes(4)));
$hcRaceStart = sprintf('%.6F', microtime(true) + 2.0);
$hcRacers = [];
for ($hcI = 0; $hcI < 8; $hcI++) {
    $hcRacers[] = proc_open([PHP_BINARY, __FILE__, '--race', 'claim', $hcDbName], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $hcRacePipes[$hcI], null, ['CUERCODE_IT_ENCRYPTION_KEY' => ENCRYPTION_KEY, 'CUERCODE_IT_CHILD_START' => $hcRaceStart, 'CUERCODE_IT_CHILD_HASH' => $hcRaceHash] + getenv());
}
$hcRaceResults = [];
foreach ($hcRacers as $hcI => $hcRacer) {
    fclose($hcRacePipes[$hcI][0]);
    $hcRaceResults[] = trim((string) stream_get_contents($hcRacePipes[$hcI][1]));
    fclose($hcRacePipes[$hcI][1]);
    fclose($hcRacePipes[$hcI][2]);
    proc_close($hcRacer);
}
$hcRaceCounts = array_count_values($hcRaceResults);
hcCheck('H', isset($hcRaceCounts['claimed']) && $hcRaceCounts['claimed'] === 1 && isset($hcRaceCounts['reused']) && $hcRaceCounts['reused'] === 7, 'eight processes with the same token at the same moment: exactly ONE accepted (' . json_encode($hcRaceCounts) . ')');

// ==============================================================================
// I. Review M2 with the real settings code
// ==============================================================================
echo PHP_EOL . 'I. Keys that cannot be read' . PHP_EOL;
hcSet('recaptcha_site_key', '');
hcSet('recaptcha_secret_key', '');
hcCli($hcDbName, ['on']);
clearConfigCache();
$hcBefore = humanCheckVerifyRequest([]);
hcCheck('I', $hcBefore['reason'] === 'missing_token', 'Turnstile keys saved: a form with no token is refused (' . $hcBefore['reason'] . ')');
$hcServer->query("UPDATE `tblSettings` SET `settingValue` = 'damaged-not-a-real-ciphertext' WHERE `settingKey` = 'turnstile_secret_key'");
clearConfigCache();
$hcAfter = humanCheckVerifyRequest([]);
hcCheck('I', $hcAfter['ok'] === false && $hcAfter['reason'] === 'misconfigured', 'the stored secret damaged: still REFUSED as "set-up wrong", not switched off (' . $hcAfter['reason'] . ')');
$hcStatus = implode(' ', humanCheckStatusLines(humanCheckReadSettings()));
hcCheck('I', str_contains($hcStatus, 'cannot be read') && !str_contains($hcStatus, 'not both entered'), 'the admin status says the keys cannot be read');
$hcBrokenWidget = humanCheckWidgetHtml(null, 'login');
hcCheck('I', !str_contains($hcBrokenWidget, 'data-sitekey') && str_contains($hcBrokenWidget, 'name="human_check_email_route"') && humanCheckIsOn() === true, 'no provider widget is drawn, only the email-route button (review L4), and the check counts as ON (the normal form is refused)');
hcSet('turnstile_secret_key', $hcSecrets['turnstileSecret']);
clearConfigCache();
hcCheck('I', is_array(humanCheckActive()) && humanCheckActive()['provider'] === 'turnstile', 'keys entered again: working again');

// ==============================================================================
// J. The no-JavaScript route's limits on the real table
// ==============================================================================
echo PHP_EOL . 'J. No-JavaScript route limits' . PHP_EOL;
$hcServer->query('DELETE FROM `tblNoScriptRequests`');
foreach (['no_script_route_ip_per_hour' => '5', 'no_script_route_email_per_day' => '3', 'no_script_route_site_per_hour' => '30'] as $hcLimitKey => $hcLimitValue) {
    hcSet($hcLimitKey, $hcLimitValue);
}
/**
 * 🚦 One gate call asking for an email place; 'ok' | 'email' | 'site' | 'ip' | ...
 */
function hcGateWord(string $hcPurpose, string $hcEmail, string $hcIp): string
{
    $hcAnswer = noScriptRouteGate($hcPurpose, $hcEmail, $hcIp, true);
    if ($hcAnswer['allowed'] === true && isset($hcAnswer['emailReserved']) && $hcAnswer['emailReserved'] !== true && $hcAnswer['reason'] === 'ok') {
        return 'no-place';
    }
    return $hcAnswer['reason'];
}

$hcGate = [];
for ($hcI = 1; $hcI <= 4; $hcI++) {
    $hcGate[] = hcGateWord('forgot', 'One.Person+' . $hcI . '@it.cuercode.invalid', '198.51.100.' . $hcI);
}
hcCheck('J', $hcGate === ['ok', 'ok', 'ok', 'email'], 'one mailbox (written with four different +tags) from four visitor addresses: the 4th email is refused (' . implode(',', $hcGate) . ')');
$hcGate = [];
for ($hcI = 1; $hcI <= 6; $hcI++) {
    $hcGate[] = hcGateWord('forgot', 'person' . $hcI . '@it.cuercode.invalid', '192.0.2.77');
}
hcCheck('J', $hcGate === ['ok', 'ok', 'ok', 'ok', 'ok', 'ip'], 'one visitor address, six email addresses: the 6th is refused (' . implode(',', $hcGate) . ')');
$hcGate = [];
for ($hcI = 1; $hcI <= 6; $hcI++) {
    $hcGate[] = hcGateWord('forgot', 'v6-' . $hcI . '@it.cuercode.invalid', '2001:db8:77:1::' . dechex($hcI * 4099));
}
hcCheck('J', $hcGate === ['ok', 'ok', 'ok', 'ok', 'ok', 'ip'], 'review I5: six different IPv6 addresses in ONE /64 network count as one visitor: the 6th is refused (' . implode(',', $hcGate) . ')');
$hcServer->query('DELETE FROM `tblNoScriptRequests`');
hcSet('no_script_route_site_per_hour', '3');
$hcGate = [];
for ($hcI = 1; $hcI <= 4; $hcI++) {
    $hcGate[] = hcGateWord('forgot', 'site' . $hcI . '@it.cuercode.invalid', '203.0.113.' . $hcI);
}
$hcGate[] = hcGateWord('login', 'site9@it.cuercode.invalid', '203.0.113.9');
hcCheck('J', $hcGate === ['ok', 'ok', 'ok', 'site', 'ok'], 'the site-wide email limit (3) is per form: the 4th forgot-password email is refused, a sign-in email still has its own places (' . implode(',', $hcGate) . ')');
$hcReleaseID = 0;
if (function_exists('noScriptRouteReleaseEmail')) {
    $hcReleaseID = (int) hcValue($hcServer, "SELECT MIN(`requestID`) FROM `tblNoScriptRequests` WHERE `purpose` = 'forgot' AND `emailSent` = 1");
    noScriptRouteReleaseEmail($hcReleaseID);
}
hcCheck('J', $hcReleaseID > 0 && hcGateWord('forgot', 'site5@it.cuercode.invalid', '203.0.113.5') === 'ok', 'review L1: a place given back (no email was queued) can be used again');
hcCheck('J', $hcCount('SELECT COUNT(*) FROM `tblNoScriptRequests`') === 6, 'only requests within the per-address limit are recorded (6 rows): one address over its limit cannot make the table grow');
$hcServer->query('DELETE FROM `tblNoScriptRequests`');
hcSet('no_script_route_site_per_hour', '30');
$hcRaceStart = sprintf('%.6F', microtime(true) + 2.0);
$hcRacers = [];
$hcRacePipes = [];
for ($hcI = 0; $hcI < 12; $hcI++) {
    $hcRacers[] = proc_open([PHP_BINARY, __FILE__, '--race', 'gate', $hcDbName], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $hcRacePipes[$hcI], null, ['CUERCODE_IT_ENCRYPTION_KEY' => ENCRYPTION_KEY, 'CUERCODE_IT_CHILD_START' => $hcRaceStart, 'CUERCODE_IT_CHILD_EMAIL' => 'race@it.cuercode.invalid', 'CUERCODE_IT_CHILD_IP' => '198.51.100.' . (100 + $hcI)] + getenv());
}
$hcRaceAllowed = 0;
foreach ($hcRacers as $hcI => $hcRacer) {
    fclose($hcRacePipes[$hcI][0]);
    $hcRaceAnswer = json_decode(trim((string) stream_get_contents($hcRacePipes[$hcI][1])), true);
    fclose($hcRacePipes[$hcI][1]);
    fclose($hcRacePipes[$hcI][2]);
    proc_close($hcRacer);
    if (is_array($hcRaceAnswer) && isset($hcRaceAnswer['emailReserved']) && $hcRaceAnswer['emailReserved'] === true) {
        $hcRaceAllowed++;
    }
}
hcCheck('J', $hcRaceAllowed === 3 && $hcCount('SELECT COUNT(*) FROM `tblNoScriptRequests` WHERE `emailSent` = 1') === 3, 'twelve processes at the same moment for ONE email address: exactly 3 get an email place (' . $hcRaceAllowed . ')');
$hcServer->query('DELETE FROM `tblNoScriptRequests`');
hcSet('no_script_route_password_per_day', '3');
$hcRaceStart = sprintf('%.6F', microtime(true) + 2.0);
$hcRacers = [];
$hcRacePipes = [];
for ($hcI = 0; $hcI < 12; $hcI++) {
    $hcRacers[] = proc_open([PHP_BINARY, __FILE__, '--race', 'password', $hcDbName], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $hcRacePipes[$hcI], null, ['CUERCODE_IT_ENCRYPTION_KEY' => ENCRYPTION_KEY, 'CUERCODE_IT_CHILD_START' => $hcRaceStart, 'CUERCODE_IT_CHILD_EMAIL' => 'race-password@it.cuercode.invalid', 'CUERCODE_IT_CHILD_IP' => '198.51.100.' . (130 + $hcI)] + getenv());
}
$hcPasswordPlaces = 0;
foreach ($hcRacers as $hcI => $hcRacer) {
    fclose($hcRacePipes[$hcI][0]);
    $hcRaceAnswer = json_decode(trim((string) stream_get_contents($hcRacePipes[$hcI][1])), true);
    fclose($hcRacePipes[$hcI][1]);
    fclose($hcRacePipes[$hcI][2]);
    proc_close($hcRacer);
    if (is_array($hcRaceAnswer) && isset($hcRaceAnswer['passwordCheck']) && $hcRaceAnswer['passwordCheck'] === true) {
        $hcPasswordPlaces++;
    }
}
hcCheck('J', $hcPasswordPlaces === 3 && $hcCount("SELECT COUNT(*) FROM `tblNoScriptRequests` WHERE `purpose` = 'login_wrong'") === 3, 'third-round review Medium 1: twelve sign-ins at the same moment for ONE account (password checks a day set to 3): exactly 3 may have their password checked (' . $hcPasswordPlaces . ')');

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
