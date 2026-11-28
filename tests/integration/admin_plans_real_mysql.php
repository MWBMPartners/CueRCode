<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Real-database check for the admin PLANS AND PRICES editor (7n Phase 2, slice 2c)
 * ==============================================================================
 *
 * Path: tests/integration/admin_plans_real_mysql.php
 *
 * tests/test_admin_plans.php proves the DECISIONS without a database. This
 * script proves what only a real MySQL / MariaDB server can, against one
 * throwaway database built from install.sql + seed_data.sql + every upgrade:
 *
 *   A. The starting data from migration 0004 is there (draft GBP prices, the
 *      legacy column moved to 6.99, the Enterprise plan).
 *   B. Saving: make a plan (off sale, features copied, audited); a duplicate
 *      key is refused; prices in GBP and USD saved EXACTLY ("7.99", "6.9" →
 *      "6.90", round trip "£6.90"), audited, the mirror copies GBP into the
 *      older columns (by group, not by the recommended flag) with its own
 *      audit row; "recommended" moves between groups.
 *   C. P1 freezing: a bought price cannot be changed or deleted, even when
 *      the purchase lands AFTER the form was opened; it can be retired, and
 *      the new price gets the next key (monthly_gbp_2) and is mirrored.
 *   D. P1 race: a purchase being recorded (not yet committed) on another
 *      connection makes the save WAIT; when it commits, the save is refused;
 *      when it is rolled back instead, the save goes ahead.
 *   E. Lost updates: two forms opened at once - the second save is refused
 *      and nothing of it is written (plan form and price form).
 *   F. P4 / P5 / P6: delete refused for a used plan, allowed for an unused
 *      one (its prices go too); the fallback and setting-named plans cannot
 *      go off sale; off sale needs the tick; a changed key is refused.
 *   G. New version: copy with fresh unbought prices, the old one off sale,
 *      both audited; refused for a protected plan.
 *   H. Ownership: a price ID of another plan is refused.
 *   I. One transaction: a database trigger makes the audit row fail - the
 *      price change, the new price and the mirror are all undone.
 *   J. Settings: changing the main currency re-runs the mirror for every plan
 *      (audited); a plan with no price in it keeps its old figures.
 *   K. Charging stays off: the three billing_*_enabled switches are still 0.
 *   L. (7n Phase 2 review, F6) The pricing page follows each price's sale
 *      dates when it loads: an ended price disappears, a scheduled one appears,
 *      nothing on sale gives "Price coming soon", a deleted last price is gone.
 *   M. (7n Phase 2 review, the two races found by reading) A price save and a
 *      main-currency change at the same moment end in the new currency; the
 *      trial-plan setting saved while that plan is deleted, in both orders,
 *      never leaves the setting naming a deleted plan.
 *
 * It is NOT run by tests/run.php (which only picks up tests/test_*.php), as
 * it needs a database. How to run it (Docker example):
 *
 *     docker run -d --rm --name cuercode-it-plans -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
 *       -p 127.0.0.1:33121:3306 mysql:8.0.36
 *     CUERCODE_IT_DB_HOST=127.0.0.1 CUERCODE_IT_DB_PORT=33121 \
 *     CUERCODE_IT_DB_NAME=cuercode_it_p2c CUERCODE_IT_DB_USER=root CUERCODE_IT_DB_PASS= \
 *       php tests/integration/admin_plans_real_mysql.php
 *     docker stop cuercode-it-plans
 *
 * ⚠️ SAFETY: it creates and DROPS a whole database and refuses to run (exit 2)
 *    unless the database name starts with "cuercode_it_". It never reads
 *    _auth_keys/auth_creds.php: the database constants come from the
 *    CUERCODE_IT_DB_* environment variables and the encryption key is random.
 *
 * Exit codes: 0 every scenario passed | 1 a scenario failed | 2 refused.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-26 (task 7n Phase 2, slice 2c - #31)
 * @see         tests/test_admin_plans.php - the database-free unit tests
 * @see         tests/integration/admin_settings_real_mysql.php - the pattern copied here
 * @see         web/CueRCode.net/_functions/fn_admin_plans.php
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

$ipSiteRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'CueRCode.net' . DIRECTORY_SEPARATOR;

/**
 * 🔧 One CUERCODE_IT_DB_* value ('' when unset).
 */
function ipEnv(string $ipKey): string
{
    $ipValue = getenv('CUERCODE_IT_DB_' . $ipKey);
    if ($ipValue === false) {
        return '';
    }
    return $ipValue;
}

/**
 * 🗄️ Define the site's database constants for the test server (before any
 *    site code loads, so db_connect.php never looks for auth_creds.php).
 */
function ipDefineDatabase(string $ipDbName): void
{
    $ipPort = ipEnv('PORT');
    if ($ipPort === '') {
        $ipPort = '3306';
    }
    define('DB_HOST', ipEnv('HOST'));
    define('DB_PORT', (int) $ipPort);
    define('DB_USER', ipEnv('USER'));
    define('DB_PASS', ipEnv('PASS'));
    define('DB_NAME', $ipDbName);

    $ipKey = getenv('CUERCODE_IT_ENCRYPTION_KEY');
    if ($ipKey === false || $ipKey === '') {
        $ipKey = bin2hex(random_bytes(16));
    }
    define('ENCRYPTION_KEY', $ipKey);
}

$ipArguments = array_slice($argv, 1);

// ==============================================================================
// 👶 Self-invoked CHILD mode: save one price group (the race in scenario D)
// php <this file> --save-group <db> <base64 json {userID, email, planID, group, fp, post}>
// Prints one line "@@RESULT@@<json>".
// ==============================================================================
if (isset($ipArguments[0]) && $ipArguments[0] === '--save-group') {
    $ipChildDb = '';
    if (isset($ipArguments[1])) {
        $ipChildDb = $ipArguments[1];
    }
    if (preg_match('/^cuercode_it_[a-z0-9_]+$/', $ipChildDb) !== 1 || !isset($ipArguments[2])) {
        fwrite(STDERR, 'Refusing: bad arguments.' . PHP_EOL);
        exit(9);
    }
    $ipRequest = json_decode((string) base64_decode($ipArguments[2], true), true);
    if (!is_array($ipRequest)) {
        fwrite(STDERR, 'Refusing: bad request.' . PHP_EOL);
        exit(9);
    }

    ipDefineDatabase($ipChildDb);
    $_SERVER['REMOTE_ADDR']     = '203.0.113.20';
    $_SERVER['HTTP_USER_AGENT'] = 'integration-test-child';
    require_once $ipSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
    require_once $ipSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_admin_plans.php';
    restore_error_handler();
    restore_exception_handler();

    $ipChildAdmin = ['userID' => (int) $ipRequest['userID'], 'email' => (string) $ipRequest['email'], 'displayName' => 'IT admin', 'sessionHash' => '', 'hasPassword' => true];

    // 🧭 What to do (scenario M, the two races of the 7n Phase 2 review, adds
    //    three more; the default is the price-group save of scenario D).
    $ipChildAction = 'save-group';
    if (isset($ipRequest['action']) && is_string($ipRequest['action'])) {
        $ipChildAction = $ipRequest['action'];
    }

    if ($ipChildAction === 'setting') {
        // ⚙️ Save one setting through its settings section, as the settings
        //    page does (checked first outside the transaction, then saved).
        $ipChildSection = (string) $ipRequest['section'];
        $ipChildKeys = array_keys(adminSettingsFieldsForSection($ipChildSection));
        $ipChildStates = (array) adminSettingsLoadRows($ipChildKeys);
        $ipChildProcessed = adminSettingsProcessSection($ipChildSection, ['f' => [(string) $ipRequest['key'] => (string) $ipRequest['value']]], $ipChildStates, ['planKeys' => (array) adminSettingsTrialPlanKeys()]);
        $ipChildResult = ['ok' => false, 'message' => 'refused before saving', 'errors' => $ipChildProcessed['errors']];
        if ($ipChildProcessed['errors'] === []) {
            $ipChildResult = adminSettingsSaveSection($ipChildAdmin, $ipChildSection, adminSettingsSectionFingerprint($ipChildSection, $ipChildStates), $ipChildProcessed['changes']);
        }
    } elseif ($ipChildAction === 'delete-plan') {
        $ipChildLoaded = adminPlansLoadPlan((int) $ipRequest['planID'], false);
        $ipChildResult = adminPlansDelete($ipChildAdmin, (int) $ipRequest['planID'], adminPlansFormFingerprint((array) $ipChildLoaded['row']), true);
    } else {
        // 🧾 "@current" = use the price list's fingerprint as it is now (used by
        //    tests/integration/migrations_real_mysql.php, which cannot work it out).
        $ipChildFingerprint = (string) $ipRequest['fp'];
        if ($ipChildFingerprint === '@current') {
            $ipChildFingerprint = adminPlansPricesFingerprint((array) adminPlansLoadPrices((int) $ipRequest['planID'], false));
        }
        $ipChildResult = adminPricesSaveGroup($ipChildAdmin, (int) $ipRequest['planID'], (string) $ipRequest['group'], $ipChildFingerprint, ipFullPost((array) $ipRequest['post']));
    }
    echo '@@RESULT@@' . json_encode($ipChildResult) . PHP_EOL;
    exit(0);
}

// ==============================================================================
// 🛡️ Step 1: safety - only ever a throwaway database named cuercode_it_*
// ==============================================================================
$ipBaseName = ipEnv('NAME');

if (preg_match('/^cuercode_it_[a-z0-9_]+$/', $ipBaseName) !== 1) {
    fwrite(STDERR, 'Refusing to run: CUERCODE_IT_DB_NAME must start with "cuercode_it_" (this script creates and drops a whole database).' . PHP_EOL);
    exit(2);
}
if (ipEnv('HOST') === '' || ipEnv('USER') === '') {
    fwrite(STDERR, 'Refusing to run: set CUERCODE_IT_DB_HOST and CUERCODE_IT_DB_USER (and CUERCODE_IT_DB_PORT / CUERCODE_IT_DB_PASS if needed).' . PHP_EOL);
    exit(2);
}
$ipDbName = $ipBaseName . '_plans';
if (preg_match('/^cuercode_it_[a-z0-9_]+$/', $ipDbName) !== 1 || strlen($ipDbName) > 64) {
    fwrite(STDERR, 'Refusing: the derived database name is not safe.' . PHP_EOL);
    exit(2);
}
$ipPort = ipEnv('PORT');
if ($ipPort === '') {
    $ipPort = '3306';
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ==============================================================================
// ⏳ Step 2: wait for the server (up to 120 seconds)
// ==============================================================================
$ipServer    = null;
$ipDeadline  = microtime(true) + 120;
$ipLastError = '';
while ($ipServer === null && microtime(true) < $ipDeadline) {
    try {
        $ipServer = new \mysqli(ipEnv('HOST'), ipEnv('USER'), ipEnv('PASS'), '', (int) $ipPort);
    } catch (\Throwable $ipError) {
        $ipLastError = $ipError->getMessage();
        usleep(500000);
    }
}
if ($ipServer === null) {
    fwrite(STDERR, 'Refusing: the test database server could not be reached (' . $ipLastError . ').' . PHP_EOL);
    exit(2);
}
echo 'Server: ' . $ipServer->server_info . PHP_EOL;

// ==============================================================================
// 🧱 Step 3: a fresh database with install.sql + seed_data.sql
// ==============================================================================
$ipServer->query('DROP DATABASE IF EXISTS `' . $ipDbName . '`');
$ipServer->query('CREATE DATABASE `' . $ipDbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$ipServer->select_db($ipDbName);
$ipServer->query("SET time_zone = '+00:00'");

foreach (['install.sql', 'seed_data.sql'] as $ipSqlFile) {
    $ipSql = (string) file_get_contents($ipSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . $ipSqlFile);
    $ipServer->multi_query($ipSql);
    do {
        $ipStored = $ipServer->store_result();
        if ($ipStored instanceof \mysqli_result) {
            $ipStored->free();
        }
    } while ($ipServer->more_results() && $ipServer->next_result());
    echo 'Loaded ' . $ipSqlFile . '.' . PHP_EOL;
}

// ==============================================================================
// 🧰 Helpers
// ==============================================================================
$ipChecks = [];

/**
 * 📝 A price-group request as the real form sends it: the four date and time
 *    boxes and the "recommended" box are always there (empty unless the test
 *    gives them). Codex finding 1.2: a request without them is refused.
 *
 * @param array<string, mixed> $ipPost What the test wants to send
 * @return array<string, mixed> The complete request
 */
function ipFullPost(array $ipPost): array
{
    return array_merge(['valid_from_date' => '', 'valid_from_time' => '', 'valid_until_date' => '', 'valid_until_time' => '', 'recommended' => '0'], $ipPost);
}

/**
 * 📝 Record one check and print it.
 */
function ipCheck(string $ipScenario, bool $ipPassed, string $ipWhat): void
{
    if (!isset($GLOBALS['ipChecks'][$ipScenario])) {
        $GLOBALS['ipChecks'][$ipScenario] = true;
    }
    if (!isset($GLOBALS['ipCheckCount'])) {
        $GLOBALS['ipCheckCount'] = 0;
    }
    $GLOBALS['ipCheckCount']++;
    if (!$ipPassed) {
        $GLOBALS['ipChecks'][$ipScenario] = false;
        echo '    FAIL ';
    } else {
        echo '    ok   ';
    }
    echo $ipWhat . PHP_EOL;
}

/**
 * 🔎 One row from the test database (raw connection, prepared).
 */
function ipRow(\mysqli $ipDb, string $ipSql, string $ipTypes = '', array $ipParams = []): ?array
{
    $ipStatement = $ipDb->prepare($ipSql);
    if ($ipTypes !== '') {
        $ipStatement->bind_param($ipTypes, ...$ipParams);
    }
    $ipStatement->execute();
    $ipFetched = $ipStatement->get_result()->fetch_assoc();
    $ipStatement->close();
    return $ipFetched;
}

/**
 * 🔢 A COUNT(*) from the test database.
 */
function ipCount(\mysqli $ipDb, string $ipSql, string $ipTypes = '', array $ipParams = []): int
{
    $ipFetched = ipRow($ipDb, $ipSql, $ipTypes, $ipParams);
    if ($ipFetched === null) {
        return -1;
    }
    return (int) array_values($ipFetched)[0];
}

/**
 * 🏷️ A plan row by key.
 */
function ipPlan(\mysqli $ipDb, string $ipKey): ?array
{
    return ipRow($ipDb, 'SELECT * FROM `tblSubscriptionPlans` WHERE `planKey` = ?', 's', [$ipKey]);
}

/**
 * 💷 A price row by plan key and price key.
 */
function ipPrice(\mysqli $ipDb, string $ipPlanKey, string $ipPriceKey): ?array
{
    return ipRow(
        $ipDb,
        'SELECT `pp`.* FROM `tblPlanPricePoints` AS `pp` JOIN `tblSubscriptionPlans` AS `p` ON `p`.`planID` = `pp`.`planID` WHERE `p`.`planKey` = ? AND `pp`.`priceKey` = ?',
        'ss',
        [$ipPlanKey, $ipPriceKey]
    );
}

/**
 * 🧾 How many audit rows of an action (and entity key, if given).
 */
function ipAudits(\mysqli $ipDb, string $ipAction, string $ipEntityKey = ''): int
{
    if ($ipEntityKey === '') {
        return ipCount($ipDb, 'SELECT COUNT(*) FROM `tblAdminAuditLog` WHERE `action` = ?', 's', [$ipAction]);
    }
    return ipCount($ipDb, 'SELECT COUNT(*) FROM `tblAdminAuditLog` WHERE `action` = ? AND `entityKey` = ?', 'ss', [$ipAction, $ipEntityKey]);
}

/**
 * 🧾 The newest audit row of an action.
 */
function ipLastAudit(\mysqli $ipDb, string $ipAction): ?array
{
    return ipRow($ipDb, 'SELECT * FROM `tblAdminAuditLog` WHERE `action` = ? ORDER BY `auditID` DESC LIMIT 1', 's', [$ipAction]);
}

// 👤 The admin and a customer.
$ipAdminEmail = 'admin@it.cuercode.invalid';
$ipAdminHash  = password_hash('Admin-' . bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
$ipInsert = $ipServer->prepare("INSERT INTO `tblUsers` (`email`, `emailVerifiedAt`, `passwordHash`, `displayName`, `status`, `roleID`) VALUES (?, '2026-11-01 09:00:00', ?, 'IT admin', 'active', 1)");
$ipInsert->bind_param('ss', $ipAdminEmail, $ipAdminHash);
$ipInsert->execute();
$ipAdminID = (int) $ipServer->insert_id;
$ipInsert->close();
$ipCustomerEmail = 'customer@it.cuercode.invalid';
$ipInsert = $ipServer->prepare("INSERT INTO `tblUsers` (`email`, `emailVerifiedAt`, `passwordHash`, `displayName`, `status`) VALUES (?, '2026-11-01 09:00:00', ?, 'IT customer', 'active')");
$ipInsert->bind_param('ss', $ipCustomerEmail, $ipAdminHash);
$ipInsert->execute();
$ipCustomerID = (int) $ipServer->insert_id;
$ipInsert->close();

/**
 * 🛒 Record a "purchase" of a price on the raw connection: a subscription
 *    pointing at it (what checkout will write later).
 */
function ipBuy(\mysqli $ipDb, int $ipUserID, int $ipPricePointID): void
{
    $ipStatement = $ipDb->prepare("INSERT INTO `tblSubscriptions` (`userID`, `planID`, `pricePointID`, `status`) SELECT ?, `planID`, `pricePointID`, 'active' FROM `tblPlanPricePoints` WHERE `pricePointID` = ?");
    $ipStatement->bind_param('ii', $ipUserID, $ipPricePointID);
    $ipStatement->execute();
    $ipStatement->close();
}

// 🚀 The real site code, in this process, against the test database.
ipDefineDatabase($ipDbName);
require_once $ipSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once $ipSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_admin_plans.php';
// 🃏 The public pricing page's own functions (scenario L reads what it shows).
require_once $ipSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_pricing.php';
restore_error_handler();
restore_exception_handler();
ini_set('display_errors', 'stderr');

$_SERVER['REMOTE_ADDR'] = '203.0.113.30';
$_SERVER['HTTP_USER_AGENT'] = 'integration-test';

$ipAdmin = ['userID' => $ipAdminID, 'email' => $ipAdminEmail, 'displayName' => 'IT admin', 'sessionHash' => '', 'hasPassword' => true];

/**
 * 🧾 The price-list fingerprint of a plan as a freshly opened form would carry it.
 */
function ipPricesFp(int $ipPlanID): string
{
    return adminPlansPricesFingerprint((array) adminPlansLoadPrices($ipPlanID, false));
}

/**
 * 🧾 The plan-form fingerprint as a freshly opened form would carry it.
 */
function ipPlanFp(int $ipPlanID): string
{
    $ipLoaded = adminPlansLoadPlan($ipPlanID, false);
    return adminPlansFormFingerprint((array) $ipLoaded['row']);
}

// ==============================================================================
// A. Upgrades and the starting data
// ==============================================================================
echo PHP_EOL . 'A. Upgrades and the starting data' . PHP_EOL;
$ipUpgrade = adminRunUpgrades($ipAdmin);
ipCheck('A', $ipUpgrade['ok'] === true, 'the waiting upgrades were applied (' . implode(', ', $ipUpgrade['applied']) . ')');
ipCheck('A', adminDatabaseReadyForChanges() === true, 'the database is ready for changes');
$ipPro = ipPlan($ipServer, 'pro');
ipCheck('A', $ipPro !== null && $ipPro['priceMonthly'] === '6.99' && $ipPro['priceYearly'] === '49.99', 'pro shows 6.99 / 49.99 in the older columns (moved from the placeholder)');
$ipProMonthly = ipPrice($ipServer, 'pro', 'monthly_gbp');
ipCheck('A', $ipProMonthly !== null && $ipProMonthly['amount'] === '6.99' && $ipProMonthly['priceGroupKey'] === 'monthly', 'pro monthly_gbp is 6.99 in group monthly');
ipCheck('A', ipPlan($ipServer, 'enterprise') !== null, 'the Enterprise plan exists');
$ipCurrencies = adminPlansCurrencySettings();
ipCheck('A', $ipCurrencies['ok'] && $ipCurrencies['offered'] === ['GBP', 'USD', 'EUR'] && $ipCurrencies['default'] === 'GBP', 'offered GBP, USD, EUR; main GBP');

// ==============================================================================
// B. Saving a plan and its prices
// ==============================================================================
echo PHP_EOL . 'B. Saving' . PHP_EOL;
$ipBusiness = ipPlan($ipServer, 'business');
$ipMade = adminPlansCreate($ipAdmin, [
    'plan_key' => 'team_plus', 'plan_kind' => 'standard', 'copy_from' => (string) $ipBusiness['planID'],
    'plan_name' => 'Team Plus', 'plan_description' => 'For teams.', 'sort_order' => '5', 'cta_mode' => 'checkout', 'contact_url' => '',
    'available_from_date' => '', 'available_from_time' => '', 'available_until_date' => '2027-06-30', 'available_until_time' => '',
    'max_sales' => '', 'promise_mode' => 'fixed_months', 'promise_months' => '12',
    'isActive' => '1', 'featuresJSON' => '{"everything":true}',
]);
ipCheck('B', $ipMade['ok'] === true && $ipMade['planID'] > 0, 'a plan is made');
$ipTeam = ipPlan($ipServer, 'team_plus');
$ipTeamID = (int) $ipTeam['planID'];
ipCheck('B', (int) $ipTeam['isActive'] === 0, 'it starts OFF sale (a hand-made isActive=1 is ignored)');
ipCheck('B', json_decode((string) $ipTeam['featuresJSON'], true) === json_decode((string) $ipBusiness['featuresJSON'], true), 'features are copied from Business (a hand-made featuresJSON is ignored)');
ipCheck('B', (int) $ipTeam['basedOnPlanID'] === (int) $ipBusiness['planID'], 'it records which plan it was copied from');
ipCheck('B', $ipTeam['availableUntil'] === '2027-06-30 22:59:59', 'end 30 Jun 2027 (UK summer time) is stored as 22:59:59 UTC');
ipCheck('B', $ipTeam['pricePromiseMode'] === 'fixed_months' && (int) $ipTeam['pricePromiseMonths'] === 12, 'the price promise is stored');
ipCheck('B', ipAudits($ipServer, 'plan.create', 'team_plus') === 1, 'one plan.create audit row');
$ipDuplicate = adminPlansCreate($ipAdmin, ['plan_key' => 'team_plus', 'plan_kind' => 'standard', 'plan_name' => 'Again', 'sort_order' => '1']);
ipCheck('B', $ipDuplicate['ok'] === false && isset($ipDuplicate['errors']['plan_key']), 'the same key again is refused');

$ipSaved = adminPricesSaveGroup($ipAdmin, $ipTeamID, 'monthly', ipPricesFp($ipTeamID), ipFullPost(['amount' => ['GBP' => '7.99', 'USD' => '6.9', 'EUR' => ''], 'valid_from_date' => '', 'valid_from_time' => '', 'valid_until_date' => '', 'valid_until_time' => '', 'recommended' => '0']));
ipCheck('B', $ipSaved['ok'] === true && $ipSaved['changed'] === true, 'monthly GBP and USD prices saved');
$ipTeamGbp = ipPrice($ipServer, 'team_plus', 'monthly_gbp');
$ipTeamUsd = ipPrice($ipServer, 'team_plus', 'monthly_usd');
ipCheck('B', $ipTeamGbp !== null && $ipTeamGbp['amount'] === '7.99' && $ipTeamGbp['billingInterval'] === 'monthly' && (int) $ipTeamGbp['intervalCount'] === 1, 'GBP stored exactly as 7.99, monthly, every 1 month');
ipCheck('B', $ipTeamUsd !== null && $ipTeamUsd['amount'] === '6.90', 'typed "6.9" is stored exactly as 6.90');
ipCheck('B', moneyFormat((int) moneyStoredDecimalToMinor($ipTeamUsd['amount'], 'USD'), 'USD') === '$6.90', '...and shown as "$6.90" (round trip, no float)');
ipCheck('B', ipAudits($ipServer, 'price.create') === 2, 'two price.create audit rows');
$ipTeam = ipPlan($ipServer, 'team_plus');
ipCheck('B', $ipTeam['priceMonthly'] === '7.99' && $ipTeam['currency'] === 'GBP', 'the mirror copied 7.99 GBP into priceMonthly');
$ipMirrorAudit = ipLastAudit($ipServer, 'plan.update');
ipCheck('B', $ipMirrorAudit !== null && $ipMirrorAudit['entityKey'] === 'team_plus' && str_contains((string) $ipMirrorAudit['reason'], 'copied from the price list'), 'the mirror wrote its own plan.update audit row');

$ipSaved = adminPricesSaveGroup($ipAdmin, $ipTeamID, 'yearly', ipPricesFp($ipTeamID), ipFullPost(['amount' => ['GBP' => '79.99', 'USD' => '', 'EUR' => ''], 'recommended' => '1']));
ipCheck('B', $ipSaved['ok'] === true, 'yearly GBP saved as the recommended price');
$ipTeam = ipPlan($ipServer, 'team_plus');
ipCheck('B', $ipTeam['priceMonthly'] === '7.99' && $ipTeam['priceYearly'] === '79.99', 'mirror: monthly from the monthly group, yearly from the yearly group (the recommended flag is on yearly)');
ipCheck('B', (int) ipPrice($ipServer, 'team_plus', 'yearly_gbp')['isDefault'] === 1, 'yearly is flagged recommended');
$ipSaved = adminPricesSaveGroup($ipAdmin, $ipTeamID, 'monthly', ipPricesFp($ipTeamID), ipFullPost(['amount' => ['GBP' => '7.99', 'USD' => '6.90', 'EUR' => ''], 'recommended' => '1']));
ipCheck('B', $ipSaved['ok'] === true && (int) ipPrice($ipServer, 'team_plus', 'monthly_gbp')['isDefault'] === 1 && (int) ipPrice($ipServer, 'team_plus', 'yearly_gbp')['isDefault'] === 0, 'ticking monthly as recommended takes it off yearly');
ipCheck('B', ipPlan($ipServer, 'team_plus')['priceMonthly'] === '7.99', 'moving the recommended flag does not change what is mirrored');
$ipUnchanged = adminPricesSaveGroup($ipAdmin, $ipTeamID, 'monthly', ipPricesFp($ipTeamID), ipFullPost(['amount' => ['GBP' => '7.99', 'USD' => '6.90', 'EUR' => ''], 'recommended' => '1']));
ipCheck('B', $ipUnchanged['ok'] === true && $ipUnchanged['changed'] === false, 'saving the same again changes nothing');
$ipWrongGroup = adminPricesSaveGroup($ipAdmin, $ipTeamID, 'lifetime', ipPricesFp($ipTeamID), ipFullPost(['amount' => ['GBP' => '1.00']]));
ipCheck('B', $ipWrongGroup['ok'] === false && ipCount($ipServer, 'SELECT COUNT(*) FROM `tblPlanPricePoints` WHERE `planID` = ? AND `priceGroupKey` = \'lifetime\'', 'i', [$ipTeamID]) === 0, 'a hand-made group this kind of plan cannot have is refused');

// 🗓️ Codex finding 1.2 (7n Phase 2 review): a hand-made request with the
//    same amounts but WITHOUT the date boxes used to clear the group's sale
//    dates. It is now refused and the stored dates stay as they were.
$ipDated = adminPricesSaveGroup($ipAdmin, $ipTeamID, 'monthly', ipPricesFp($ipTeamID), ipFullPost(['amount' => ['GBP' => '7.99', 'USD' => '6.90', 'EUR' => ''], 'valid_until_date' => '2027-01-31', 'recommended' => '1']));
ipCheck('B', $ipDated['ok'] === true && ipPrice($ipServer, 'team_plus', 'monthly_gbp')['validUntil'] === '2027-01-31 23:59:59' && ipPrice($ipServer, 'team_plus', 'monthly_usd')['validUntil'] === '2027-01-31 23:59:59', 'finding 1.2 setup: an end date (31 Jan 2027, UK time) is saved on both monthly prices');
$ipNoDates = adminPricesSaveGroup($ipAdmin, $ipTeamID, 'monthly', ipPricesFp($ipTeamID), ['amount' => ['GBP' => '7.99', 'USD' => '6.90', 'EUR' => ''], 'recommended' => '1']);
ipCheck('B', $ipNoDates['ok'] === false && isset($ipNoDates['errors']['form']), 'finding 1.2: the same amounts without the date boxes are REFUSED: ' . json_encode($ipNoDates['errors']));
ipCheck('B', ipPrice($ipServer, 'team_plus', 'monthly_gbp')['validUntil'] === '2027-01-31 23:59:59' && ipPrice($ipServer, 'team_plus', 'monthly_usd')['validUntil'] === '2027-01-31 23:59:59', 'finding 1.2: ...and the stored end dates are untouched');
$ipCleared = adminPricesSaveGroup($ipAdmin, $ipTeamID, 'monthly', ipPricesFp($ipTeamID), ipFullPost(['amount' => ['GBP' => '7.99', 'USD' => '6.90', 'EUR' => ''], 'recommended' => '1']));
ipCheck('B', $ipCleared['ok'] === true && ipPrice($ipServer, 'team_plus', 'monthly_gbp')['validUntil'] === null && ipPrice($ipServer, 'team_plus', 'monthly_usd')['validUntil'] === null, 'finding 1.2: date boxes sent EMPTY on purpose still clear the end dates');

// 🌍 Codex finding 1.1: a currency beyond the first four (CAD, two decimal
//    places) and a zero-decimal one (KRW) are offered and saved exactly in
//    the DECIMAL(10,2) column; the offered list is put back afterwards.
$ipServer->query("UPDATE `tblSettings` SET `settingValue` = 'GBP,USD,EUR,CAD,KRW' WHERE `settingKey` = 'billing_currencies'");
$ipWider = adminPricesSaveGroup($ipAdmin, $ipTeamID, 'yearly', ipPricesFp($ipTeamID), ipFullPost(['amount' => ['GBP' => '79.99', 'USD' => '', 'EUR' => '', 'CAD' => '139.5', 'KRW' => '125000']]));
ipCheck('B', $ipWider['ok'] === true && ipPrice($ipServer, 'team_plus', 'yearly_cad')['amount'] === '139.50' && ipPrice($ipServer, 'team_plus', 'yearly_krw')['amount'] === '125000.00', 'finding 1.1: CAD 139.5 is stored exactly as 139.50 and KRW 125000 as 125000.00: ' . json_encode($ipWider['errors']));
ipCheck('B', moneyFormat((int) moneyStoredDecimalToMinor(ipPrice($ipServer, 'team_plus', 'yearly_cad')['amount'], 'CAD'), 'CAD') === "CAD\u{00A0}139.50" && moneyFormat((int) moneyStoredDecimalToMinor(ipPrice($ipServer, 'team_plus', 'yearly_krw')['amount'], 'KRW'), 'KRW') === "KRW\u{00A0}125,000", 'finding 1.1: ...and both read back and show exactly ("CAD 139.50", "KRW 125,000")');
foreach (['yearly_cad', 'yearly_krw'] as $ipWiderKey) {
    $ipWiderRow = ipPrice($ipServer, 'team_plus', $ipWiderKey);
    ipCheck('B', adminPricesDelete($ipAdmin, $ipTeamID, (int) $ipWiderRow['pricePointID'], ipPricesFp($ipTeamID), true)['ok'] === true, 'finding 1.1 tidy-up: the ' . $ipWiderKey . ' test price is deleted again');
}
$ipServer->query("UPDATE `tblSettings` SET `settingValue` = 'GBP,USD,EUR' WHERE `settingKey` = 'billing_currencies'");

// ==============================================================================
// C. P1 freezing
// ==============================================================================
echo PHP_EOL . 'C. P1 freezing' . PHP_EOL;
$ipFormFp = ipPricesFp($ipTeamID);               // the form is opened...
ipBuy($ipServer, $ipCustomerID, (int) $ipTeamGbp['pricePointID']);   // ...then somebody buys
$ipFrozen = adminPricesSaveGroup($ipAdmin, $ipTeamID, 'monthly', $ipFormFp, ipFullPost(['amount' => ['GBP' => '8.49', 'USD' => '6.90', 'EUR' => ''], 'recommended' => '1']));
ipCheck('C', $ipFrozen['ok'] === false && isset($ipFrozen['errors']['amount_GBP']), 'changing a price bought AFTER the form was opened is refused');
ipCheck('C', ipPrice($ipServer, 'team_plus', 'monthly_gbp')['amount'] === '7.99', '...the amount is still 7.99');
$ipUsdChange = adminPricesSaveGroup($ipAdmin, $ipTeamID, 'monthly', ipPricesFp($ipTeamID), ipFullPost(['amount' => ['GBP' => '7.99', 'USD' => '7.49', 'EUR' => ''], 'recommended' => '1']));
ipCheck('C', $ipUsdChange['ok'] === true && ipPrice($ipServer, 'team_plus', 'monthly_usd')['amount'] === '7.49', 'the unbought USD price in the same group can still change');
$ipDeleteFrozen = adminPricesDelete($ipAdmin, $ipTeamID, (int) $ipTeamGbp['pricePointID'], ipPricesFp($ipTeamID), true);
ipCheck('C', $ipDeleteFrozen['ok'] === false && ipPrice($ipServer, 'team_plus', 'monthly_gbp') !== null, 'a bought price cannot be deleted');
$ipRetireNoTick = adminPricesRetire($ipAdmin, $ipTeamID, (int) $ipTeamGbp['pricePointID'], ipPricesFp($ipTeamID), false);
ipCheck('C', $ipRetireNoTick['ok'] === false && (int) ipPrice($ipServer, 'team_plus', 'monthly_gbp')['isActive'] === 1, 'retiring needs the tick');
$ipRetired = adminPricesRetire($ipAdmin, $ipTeamID, (int) $ipTeamGbp['pricePointID'], ipPricesFp($ipTeamID), true);
$ipOld = ipPrice($ipServer, 'team_plus', 'monthly_gbp');
ipCheck('C', $ipRetired['ok'] === true && (int) $ipOld['isActive'] === 0 && $ipOld['validUntil'] !== null && $ipOld['amount'] === '7.99', 'a bought price can be retired (amount untouched, end date set)');
ipCheck('C', ipAudits($ipServer, 'price.retire') === 1, 'one price.retire audit row');
ipCheck('C', ipPlan($ipServer, 'team_plus')['priceMonthly'] === '7.99' && count($ipRetired['warnings']) === 1, 'no current monthly GBP price: the pricing page keeps 7.99 (never "Free"), with a warning');
$ipReplaced = adminPricesSaveGroup($ipAdmin, $ipTeamID, 'monthly', ipPricesFp($ipTeamID), ipFullPost(['amount' => ['GBP' => '8.49', 'USD' => '7.49', 'EUR' => ''], 'recommended' => '1']));
$ipNew = ipPrice($ipServer, 'team_plus', 'monthly_gbp_2');
ipCheck('C', $ipReplaced['ok'] === true && $ipNew !== null && $ipNew['amount'] === '8.49' && $ipNew['priceGroupKey'] === 'monthly', 'the replacement price is monthly_gbp_2 in the same group');
ipCheck('C', ipPlan($ipServer, 'team_plus')['priceMonthly'] === '8.49', 'the replacement is mirrored');
ipCheck('C', ipCount($ipServer, 'SELECT COUNT(*) FROM `tblSubscriptions` WHERE `pricePointID` = ?', 'i', [(int) $ipTeamGbp['pricePointID']]) === 1, 'the customer still points at the price they bought');

// ==============================================================================
// D. P1 race: a purchase being recorded at the same moment
// ==============================================================================
echo PHP_EOL . 'D. P1 race (locks)' . PHP_EOL;

/**
 * 🏁 Start a child process that saves a price group, while $ipOther holds an
 *    uncommitted purchase; then $ipFinish ('commit' or 'rollback') it.
 *
 * @return array{waited: bool, result: array|null, err: string}
 */
function ipRace(\mysqli $ipOther, string $ipDb, array $ipRequest, string $ipFinish): array
{
    $ipCommand = [PHP_BINARY, __FILE__, '--save-group', $ipDb, base64_encode((string) json_encode($ipRequest))];
    $ipProcess = proc_open($ipCommand, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $ipPipes, null, ['CUERCODE_IT_ENCRYPTION_KEY' => ENCRYPTION_KEY] + getenv());
    fclose($ipPipes[0]);
    usleep(2500000);
    $ipStatus = proc_get_status($ipProcess);
    $ipWaited = ($ipStatus['running'] === true);
    if ($ipFinish === 'commit') {
        $ipOther->commit();
    } else {
        $ipOther->rollback();
    }
    $ipOut = (string) stream_get_contents($ipPipes[1]);
    fclose($ipPipes[1]);
    $ipErr = (string) stream_get_contents($ipPipes[2]);
    fclose($ipPipes[2]);
    proc_close($ipProcess);
    $ipResult = null;
    $ipMarker = strrpos($ipOut, '@@RESULT@@');
    if ($ipMarker !== false) {
        $ipResult = json_decode(trim(substr($ipOut, $ipMarker + strlen('@@RESULT@@'))), true);
    }
    return ['waited' => $ipWaited, 'result' => $ipResult, 'err' => $ipErr];
}

$ipOther = new \mysqli(ipEnv('HOST'), ipEnv('USER'), ipEnv('PASS'), $ipDbName, (int) $ipPort);
$ipOther->query("SET time_zone = '+00:00'");
$ipUsdRow = ipPrice($ipServer, 'team_plus', 'monthly_usd');

// D1: the purchase commits → the waiting save is refused.
$ipOther->begin_transaction();
ipBuy($ipOther, $ipCustomerID, (int) $ipUsdRow['pricePointID']);
$ipRace = ipRace($ipOther, $ipDbName, ['userID' => $ipAdminID, 'email' => $ipAdminEmail, 'planID' => $ipTeamID, 'group' => 'monthly', 'fp' => ipPricesFp($ipTeamID), 'post' => ['amount' => ['GBP' => '8.49', 'USD' => '9.99', 'EUR' => ''], 'recommended' => '1']], 'commit');
ipCheck('D', $ipRace['waited'], 'the save WAITED while the purchase was being recorded (the plan / price rows were locked)');
$ipRaceNote = '';
if ($ipRace['err'] !== '') {
    $ipRaceNote = ' [child said: ' . trim($ipRace['err']) . ']';
}
ipCheck('D', is_array($ipRace['result']) && $ipRace['result']['ok'] === false && isset($ipRace['result']['errors']['amount_USD']), 'when the purchase committed, the save was refused (the price is now bought)' . $ipRaceNote);
ipCheck('D', ipPrice($ipServer, 'team_plus', 'monthly_usd')['amount'] === '7.49', '...the USD price is still 7.49');

// D2: the purchase is rolled back → the waiting save goes ahead.
$ipEurSaved = adminPricesSaveGroup($ipAdmin, $ipTeamID, 'yearly', ipPricesFp($ipTeamID), ipFullPost(['amount' => ['GBP' => '79.99', 'USD' => '', 'EUR' => '69.00'], 'recommended' => '0']));
$ipEurRow = ipPrice($ipServer, 'team_plus', 'yearly_eur');
ipCheck('D', $ipEurSaved['ok'] === true && $ipEurRow !== null, 'a yearly EUR price is added (for D2)');
$ipOther->begin_transaction();
ipBuy($ipOther, $ipCustomerID, (int) $ipEurRow['pricePointID']);
$ipRace = ipRace($ipOther, $ipDbName, ['userID' => $ipAdminID, 'email' => $ipAdminEmail, 'planID' => $ipTeamID, 'group' => 'yearly', 'fp' => ipPricesFp($ipTeamID), 'post' => ['amount' => ['GBP' => '79.99', 'USD' => '', 'EUR' => '71.00'], 'recommended' => '0']], 'rollback');
ipCheck('D', $ipRace['waited'], 'the save waited again');
ipCheck('D', is_array($ipRace['result']) && $ipRace['result']['ok'] === true && ipPrice($ipServer, 'team_plus', 'yearly_eur')['amount'] === '71.00', 'when the purchase was rolled back, the save went ahead (71.00)');

// ==============================================================================
// E. Lost updates
// ==============================================================================
echo PHP_EOL . 'E. Two admins at once' . PHP_EOL;
$ipFpBoth = ipPlanFp($ipTeamID);
$ipFirst = adminPlansUpdate($ipAdmin, $ipTeamID, $ipFpBoth, ['plan_name' => 'Team Plus (first)']);
$ipSecond = adminPlansUpdate($ipAdmin, $ipTeamID, $ipFpBoth, ['plan_name' => 'Team Plus (second)', 'sort_order' => '9']);
ipCheck('E', $ipFirst['ok'] === true, 'the first plan save works');
ipCheck('E', $ipSecond['ok'] === false && $ipSecond['stale'] === true, 'the second (opened at the same time) is refused as out of date');
$ipTeam = ipPlan($ipServer, 'team_plus');
ipCheck('E', $ipTeam['planName'] === 'Team Plus (first)' && (int) $ipTeam['sortOrder'] === 5, '...and nothing of it was written');
ipCheck('E', ipAudits($ipServer, 'plan.update', 'team_plus') >= 1 && ipLastAudit($ipServer, 'plan.update')['changesJSON'] !== null && str_contains((string) ipLastAudit($ipServer, 'plan.update')['changesJSON'], 'Team Plus (first)'), 'the first save is in the audit log with old and new name');
$ipPriceFpBoth = ipPricesFp($ipTeamID);
$ipFirst = adminPricesSaveGroup($ipAdmin, $ipTeamID, 'yearly', $ipPriceFpBoth, ipFullPost(['amount' => ['GBP' => '79.99', 'USD' => '89.00', 'EUR' => '71.00'], 'recommended' => '0']));
$ipSecond = adminPricesSaveGroup($ipAdmin, $ipTeamID, 'yearly', $ipPriceFpBoth, ipFullPost(['amount' => ['GBP' => '79.99', 'USD' => '99.00', 'EUR' => '71.00'], 'recommended' => '0']));
ipCheck('E', $ipFirst['ok'] === true && $ipSecond['ok'] === false && $ipSecond['stale'] === true, 'two price forms at once: the second is refused');
ipCheck('E', ipPrice($ipServer, 'team_plus', 'yearly_usd')['amount'] === '89.00', '...the first one\'s 89.00 stands');
$ipNoFp = adminPricesSaveGroup($ipAdmin, $ipTeamID, 'yearly', '', ipFullPost(['amount' => ['GBP' => '79.99', 'USD' => '99.00', 'EUR' => '71.00']]));
ipCheck('E', $ipNoFp['ok'] === false && $ipNoFp['stale'] === true, 'a form with no fingerprint is refused');
ipCheck('E', ipPlanFp($ipTeamID) === adminPlansFormFingerprint(ipPlan($ipServer, 'team_plus')), '(the plan fingerprint is computed the same way from a loaded row)');

// ==============================================================================
// F. P4 / P5 / P6
// ==============================================================================
echo PHP_EOL . 'F. P4 / P5 / P6' . PHP_EOL;
$ipDelUsed = adminPlansDelete($ipAdmin, $ipTeamID, ipPlanFp($ipTeamID), true);
ipCheck('F', $ipDelUsed['ok'] === false && ipPlan($ipServer, 'team_plus') !== null, 'P4: a plan somebody bought cannot be deleted');
$ipSpare = adminPlansCreate($ipAdmin, ['plan_key' => 'spare_plan', 'plan_kind' => 'standard', 'plan_name' => 'Spare', 'sort_order' => '0', 'cta_mode' => 'checkout', 'promise_mode' => '']);
$ipSpareID = (int) $ipSpare['planID'];
adminPricesSaveGroup($ipAdmin, $ipSpareID, 'monthly', ipPricesFp($ipSpareID), ipFullPost(['amount' => ['GBP' => '1.50', 'USD' => '', 'EUR' => '']]));
$ipDelNoTick = adminPlansDelete($ipAdmin, $ipSpareID, ipPlanFp($ipSpareID), false);
ipCheck('F', $ipDelNoTick['ok'] === false && ipPlan($ipServer, 'spare_plan') !== null, 'P4: deleting needs the tick');
$ipDel = adminPlansDelete($ipAdmin, $ipSpareID, ipPlanFp($ipSpareID), true);
ipCheck('F', $ipDel['ok'] === true && ipPlan($ipServer, 'spare_plan') === null, 'P4: an unused plan is deleted');
ipCheck('F', ipCount($ipServer, 'SELECT COUNT(*) FROM `tblPlanPricePoints` WHERE `planID` = ?', 'i', [$ipSpareID]) === 0, 'P4: ...with its unused prices');
ipCheck('F', ipAudits($ipServer, 'plan.delete', 'spare_plan') === 1, 'P4: one plan.delete audit row');
foreach (['anonymous', 'free', 'pro'] as $ipProtectedKey) {
    $ipProtectedID = (int) ipPlan($ipServer, $ipProtectedKey)['planID'];
    $ipOff = adminPlansSetOnSale($ipAdmin, $ipProtectedID, ipPlanFp($ipProtectedID), false, true);
    ipCheck('F', $ipOff['ok'] === false && (int) ipPlan($ipServer, $ipProtectedKey)['isActive'] === 1, 'P5: ' . $ipProtectedKey . ' cannot be taken off sale (' . $ipProtectedKey . ' is a fallback or the trial plan)');
    $ipDelProtected = adminPlansDelete($ipAdmin, $ipProtectedID, ipPlanFp($ipProtectedID), true);
    ipCheck('F', $ipDelProtected['ok'] === false && ipPlan($ipServer, $ipProtectedKey) !== null, 'P5: ' . $ipProtectedKey . ' cannot be deleted');
    // 🛡️ Review finding F3: nor hidden through the details form's sale dates.
    $ipDated = adminPlansUpdate($ipAdmin, $ipProtectedID, ipPlanFp($ipProtectedID), ['available_until_date' => '2026-01-01']);
    $ipDatedLater = adminPlansUpdate($ipAdmin, $ipProtectedID, ipPlanFp($ipProtectedID), ['available_from_date' => '2030-01-01']);
    $ipDatedRow = ipPlan($ipServer, $ipProtectedKey);
    ipCheck('F', $ipDated['ok'] === false && isset($ipDated['errors']['available_until_date']) && $ipDatedLater['ok'] === false && $ipDatedRow['availableUntil'] === null && $ipDatedRow['availableFrom'] === null, 'F3: ' . $ipProtectedKey . ' cannot be given an ended or a future sale date through the details form: ' . json_encode([$ipDated['errors'], $ipDatedLater['errors']]));
    // 🛡️ Recheck finding 2: nor hidden by display order 0 (the visitors' plan
    //    keeps its 0 - it never has a card), nor given "Contact us" in place of
    //    its own button, nor a sales cap.
    $ipBeforeRoutes = ipPlan($ipServer, $ipProtectedKey);
    $ipRoutes = ['cta_mode' => 'contact', 'max_sales' => '100'];
    if ($ipProtectedKey !== 'anonymous') {
        $ipRoutes['sort_order'] = '0';
    }
    foreach ($ipRoutes as $ipRouteBox => $ipRouteValue) {
        $ipRouted = adminPlansUpdate($ipAdmin, $ipProtectedID, ipPlanFp($ipProtectedID), [$ipRouteBox => $ipRouteValue]);
        ipCheck('F', $ipRouted['ok'] === false && isset($ipRouted['errors'][$ipRouteBox]) && ipPlan($ipServer, $ipProtectedKey) === $ipBeforeRoutes, 'recheck F2: ' . $ipProtectedKey . ' cannot be given ' . $ipRouteBox . ' = ' . $ipRouteValue . ' (nothing saved): ' . json_encode($ipRouted['errors'] ?? null));
    }
}
// 🎟️ Recheck finding 2, settings side: the trial setting cannot name a plan
//    whose sale dates have ended - not in the list, and refused when saved
//    even if the page offered it (an out-of-date page or a hand-made request).
$ipEndedMade = adminPlansCreate($ipAdmin, ['plan_key' => 'ended_it', 'plan_kind' => 'standard', 'plan_name' => 'Ended', 'sort_order' => '7', 'cta_mode' => 'checkout']);
$ipEndedID = (int) $ipEndedMade['planID'];
adminPricesSaveGroup($ipAdmin, $ipEndedID, 'monthly', ipPricesFp($ipEndedID), ipFullPost(['amount' => ['GBP' => '4.00']]));
adminPlansSetOnSale($ipAdmin, $ipEndedID, ipPlanFp($ipEndedID), true, false);
ipCheck('F', in_array('ended_it', (array) adminSettingsTrialPlanKeys(), true), 'recheck F2 setup: a paid plan on sale with no dates may be the trial plan');
$ipEndedDated = adminPlansUpdate($ipAdmin, $ipEndedID, ipPlanFp($ipEndedID), ['available_until_date' => '2026-01-01']);
ipCheck('F', $ipEndedDated['ok'] === true && !in_array('ended_it', (array) adminSettingsTrialPlanKeys(), true), 'recheck F2: once its sale dates have ended it is no longer offered for the trial setting: ' . json_encode(adminSettingsTrialPlanKeys()));
$ipTrialStatesF = adminSettingsLoadRows(array_keys(adminSettingsFieldsForSection('trials')));
$ipTrialStaleF = adminSettingsProcessSection('trials', ['f' => ['billing_trial_plan_key' => 'ended_it']], (array) $ipTrialStatesF, ['planKeys' => ['pro', 'ended_it']]);
$ipTrialSavedF = adminSettingsSaveSection($ipAdmin, 'trials', adminSettingsSectionFingerprint('trials', (array) $ipTrialStatesF), $ipTrialStaleF['changes']);
ipCheck('F', $ipTrialStaleF['changes'] !== [] && $ipTrialSavedF['ok'] === false && (string) ipRow($ipServer, "SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'billing_trial_plan_key'")['settingValue'] === 'pro', 'recheck F2: a save naming it is refused by the server even when the page offered it (the trial plan stays Pro): ' . (string) $ipTrialSavedF['message']);
adminPlansSetOnSale($ipAdmin, $ipEndedID, ipPlanFp($ipEndedID), false, true);
$ipGridKeys = array_column(pricingDisplayRowsFromPlans(listPublicPlansForDisplay()), 'planKey');
ipCheck('F', in_array('free', $ipGridKeys, true) && in_array('pro', $ipGridKeys, true), 'F3: the free plan (the only sign-up button) and the trial plan are still on the pricing page: ' . implode(',', $ipGridKeys));
$ipBusinessID = (int) $ipBusiness['planID'];
$ipOffNoTick = adminPlansSetOnSale($ipAdmin, $ipBusinessID, ipPlanFp($ipBusinessID), false, false);
ipCheck('F', $ipOffNoTick['ok'] === false && (int) ipPlan($ipServer, 'business')['isActive'] === 1, 'off sale needs the tick');
$ipOff = adminPlansSetOnSale($ipAdmin, $ipBusinessID, ipPlanFp($ipBusinessID), false, true);
ipCheck('F', $ipOff['ok'] === true && (int) ipPlan($ipServer, 'business')['isActive'] === 0 && ipAudits($ipServer, 'plan.retire', 'business') === 1, 'business taken off sale (audited)');
$ipOn = adminPlansSetOnSale($ipAdmin, $ipBusinessID, ipPlanFp($ipBusinessID), true, false);
ipCheck('F', $ipOn['ok'] === true && (int) ipPlan($ipServer, 'business')['isActive'] === 1 && ipAudits($ipServer, 'plan.restore', 'business') === 1, 'and back on sale (audited)');
$ipKeyChange = adminPlansUpdate($ipAdmin, $ipTeamID, ipPlanFp($ipTeamID), ['plan_key' => 'team_max', 'plan_name' => 'Renamed']);
ipCheck('F', $ipKeyChange['ok'] === false && ipPlan($ipServer, 'team_plus') !== null && ipPlan($ipServer, 'team_max') === null && ipPlan($ipServer, 'team_plus')['planName'] === 'Team Plus (first)', 'P6: a hand-made key change is refused and nothing (not even the name) is saved');
$ipKindChange = adminPlansUpdate($ipAdmin, $ipTeamID, ipPlanFp($ipTeamID), ['plan_kind' => 'lifetime_deal']);
ipCheck('F', $ipKindChange['ok'] === false && ipPlan($ipServer, 'team_plus')['planKind'] === 'standard', 'P6: a hand-made kind change is refused');
$ipCapLow = adminPlansUpdate($ipAdmin, $ipTeamID, ipPlanFp($ipTeamID), ['max_sales' => '1']);
ipCheck('F', $ipCapLow['ok'] === false && isset($ipCapLow['errors']['max_sales']), 'a cap below the number sold (2 subscriptions) is refused');

// ==============================================================================
// G. New version
// ==============================================================================
echo PHP_EOL . 'G. New version' . PHP_EOL;
$ipTeamOn = adminPlansSetOnSale($ipAdmin, $ipTeamID, ipPlanFp($ipTeamID), true, false);
$ipVersion = adminPlansNewVersion($ipAdmin, $ipTeamID, ipPlanFp($ipTeamID), 'team_plus_v2', true);
$ipV2 = ipPlan($ipServer, 'team_plus_v2');
ipCheck('G', $ipTeamOn['ok'] && $ipVersion['ok'] === true && $ipV2 !== null, 'a new version team_plus_v2 is made');
ipCheck('G', (int) $ipV2['isActive'] === 1 && (int) ipPlan($ipServer, 'team_plus')['isActive'] === 0, 'the new one is on sale, the old one off sale');
ipCheck('G', (int) $ipV2['basedOnPlanID'] === $ipTeamID && $ipV2['featuresJSON'] === ipPlan($ipServer, 'team_plus')['featuresJSON'], 'it points back at the old one and has the same features');
$ipV2Prices = ipCount($ipServer, 'SELECT COUNT(*) FROM `tblPlanPricePoints` WHERE `planID` = ? AND `isActive` = 1', 'i', [(int) $ipV2['planID']]);
$ipOldPrices = ipCount($ipServer, 'SELECT COUNT(*) FROM `tblPlanPricePoints` WHERE `planID` = ? AND `isActive` = 1', 'i', [$ipTeamID]);
ipCheck('G', $ipV2Prices === $ipOldPrices && $ipV2Prices >= 4, 'its current prices were copied (' . $ipV2Prices . ')');
ipCheck('G', ipPrice($ipServer, 'team_plus_v2', 'monthly_gbp') !== null && ipPrice($ipServer, 'team_plus_v2', 'monthly_gbp')['amount'] === '8.49', 'the copied monthly GBP price is the current one (8.49) under a fresh key');
ipCheck('G', ipCount($ipServer, 'SELECT COUNT(*) FROM `tblSubscriptions` WHERE `planID` = ?', 'i', [(int) $ipV2['planID']]) === 0, 'nobody is on the new version: its prices start unbought');
ipCheck('G', ipAudits($ipServer, 'plan.new_version', 'team_plus_v2') === 1 && ipAudits($ipServer, 'plan.retire', 'team_plus') === 1, 'plan.new_version and plan.retire audit rows');
$ipProVersion = adminPlansNewVersion($ipAdmin, (int) ipPlan($ipServer, 'pro')['planID'], ipPlanFp((int) ipPlan($ipServer, 'pro')['planID']), 'pro_v2', true);
ipCheck('G', $ipProVersion['ok'] === false && ipPlan($ipServer, 'pro_v2') === null, 'no new version of the trial plan (it could never be taken off sale)');

// ==============================================================================
// H. Ownership
// ==============================================================================
echo PHP_EOL . 'H. A price of another plan' . PHP_EOL;
$ipProMonthlyID = (int) $ipProMonthly['pricePointID'];
$ipCross = adminPricesRetire($ipAdmin, $ipTeamID, $ipProMonthlyID, ipPricesFp($ipTeamID), true);
ipCheck('H', $ipCross['ok'] === false && (int) ipPrice($ipServer, 'pro', 'monthly_gbp')['isActive'] === 1, 'retiring Pro\'s price through Team Plus\'s page is refused');
$ipCrossDelete = adminPricesDelete($ipAdmin, $ipTeamID, $ipProMonthlyID, ipPricesFp($ipTeamID), true);
ipCheck('H', $ipCrossDelete['ok'] === false && ipPrice($ipServer, 'pro', 'monthly_gbp') !== null, 'deleting Pro\'s price through Team Plus\'s page is refused');

// ==============================================================================
// I. One transaction: a failed audit row undoes everything
// ==============================================================================
echo PHP_EOL . 'I. One transaction' . PHP_EOL;
$ipV2ID = (int) $ipV2['planID'];
$ipServer->query("CREATE TRIGGER `it_refuse_price_audit` BEFORE INSERT ON `tblAdminAuditLog` FOR EACH ROW BEGIN IF NEW.`action` = 'price.create' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'integration test: audit refused'; END IF; END");
$ipBefore = ipCount($ipServer, 'SELECT COUNT(*) FROM `tblPlanPricePoints` WHERE `planID` = ?', 'i', [$ipV2ID]);
$ipFailed = adminPricesSaveGroup($ipAdmin, $ipV2ID, 'monthly', ipPricesFp($ipV2ID), ipFullPost(['amount' => ['GBP' => '9.49', 'USD' => '7.49', 'EUR' => '8.00'], 'recommended' => '1']));
ipCheck('I', $ipFailed['ok'] === false, 'the save is reported as not saved');
ipCheck('I', ipPrice($ipServer, 'team_plus_v2', 'monthly_gbp')['amount'] === '8.49', 'the amount change made before the failing audit row was undone (still 8.49)');
ipCheck('I', ipCount($ipServer, 'SELECT COUNT(*) FROM `tblPlanPricePoints` WHERE `planID` = ?', 'i', [$ipV2ID]) === $ipBefore, 'no new EUR price was left behind');
ipCheck('I', ipPlan($ipServer, 'team_plus_v2')['priceMonthly'] === '8.49', 'the older column was not changed');
$ipServer->query('DROP TRIGGER `it_refuse_price_audit`');

// ==============================================================================
// J. Changing the main currency re-runs the mirror
// ==============================================================================
echo PHP_EOL . 'J. Main currency' . PHP_EOL;
$ipPricesKeys = array_keys(adminSettingsFieldsForSection('prices'));
$ipStates = adminSettingsLoadRows($ipPricesKeys);
$ipProcessed = adminSettingsProcessSection('prices', ['f' => ['billing_default_currency' => 'USD']], (array) $ipStates);
ipCheck('J', $ipProcessed['errors'] === [] && count($ipProcessed['changes']) === 1, 'choosing USD as the main currency is accepted');
$ipSettingSaved = adminSettingsSaveSection($ipAdmin, 'prices', adminSettingsSectionFingerprint('prices', (array) $ipStates), $ipProcessed['changes']);
ipCheck('J', $ipSettingSaved['ok'] === true, 'saved');
$ipV2 = ipPlan($ipServer, 'team_plus_v2');
ipCheck('J', $ipV2['currency'] === 'USD' && $ipV2['priceMonthly'] === '7.49' && $ipV2['priceYearly'] === '89.00', 'team_plus_v2 now shows its USD prices (7.49 / 89.00)');
$ipPro = ipPlan($ipServer, 'pro');
ipCheck('J', $ipPro['currency'] === 'GBP' && $ipPro['priceMonthly'] === '6.99', 'pro has no USD price: it keeps showing its GBP prices');
ipCheck('J', ipCount($ipServer, "SELECT COUNT(*) FROM `tblAdminAuditLog` WHERE `action` = 'plan.update' AND `entityKey` = 'team_plus_v2' AND `reason` LIKE '%main currency USD%'") === 1, 'the mirror after the currency change is audited');
$ipNotOffered = adminSettingsProcessSection('prices', ['f' => ['billing_currencies' => 'GBP,EUR']], (array) adminSettingsLoadRows($ipPricesKeys));
ipCheck('J', isset($ipNotOffered['errors']['billing_default_currency']), 'removing USD while it is the main currency is refused');
$ipStates = adminSettingsLoadRows($ipPricesKeys);
$ipBack = adminSettingsProcessSection('prices', ['f' => ['billing_default_currency' => 'GBP']], (array) $ipStates);
$ipBackSaved = adminSettingsSaveSection($ipAdmin, 'prices', adminSettingsSectionFingerprint('prices', (array) $ipStates), $ipBack['changes']);
ipCheck('J', $ipBackSaved['ok'] === true && ipPlan($ipServer, 'team_plus_v2')['currency'] === 'GBP' && ipPlan($ipServer, 'team_plus_v2')['priceMonthly'] === '8.49', 'back to GBP: the GBP prices are shown again');

// ==============================================================================
// L. The pricing page follows each price's sale dates when it loads
//    (7n Phase 2 review, finding F6; decision 0021 for "nothing on sale")
// ==============================================================================
echo PHP_EOL . 'L. Pricing page follows sale dates (F6)' . PHP_EOL;

/**
 * 🃏 What the public pricing page would show right now, per plan key:
 *    the card's price words (pricingPriceBlock()) and whether it is "Free".
 */
function ipPublicCards(): array
{
    $ipCards = [];
    foreach (pricingDisplayRowsFromPlans(listPublicPlansForDisplay()) as $ipRow) {
        $ipBlock = pricingPriceBlock($ipRow, true);
        $ipCards[$ipRow['planKey']] = [
            'text' => trim($ipBlock['prefix'] . ' ' . $ipBlock['headline'] . ' ' . $ipBlock['suffix']) . ' | ' . $ipBlock['subline'],
            'free' => $ipRow['isZeroPrice'],
        ];
    }
    return $ipCards;
}

$ipBusinessID = (int) ipPlan($ipServer, 'business')['planID'];
$ipCards = ipPublicCards();
ipCheck('L', isset($ipCards['business']) && $ipCards['business']['text'] === '£24.99 /month | or £199.99 billed yearly', 'before: Business shows £24.99 a month or £199.99 a year: ' . json_encode($ipCards['business'] ?? null));

// L1. The monthly price's end date passes (nobody saves anything).
$ipServer->query("UPDATE `tblPlanPricePoints` SET `validUntil` = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE `planID` = " . $ipBusinessID . " AND `priceKey` = 'monthly_gbp'");
$ipCards = ipPublicCards();
ipCheck('L', $ipCards['business']['text'] === '£199.99 /year | Billed yearly', 'F6: once its end date has passed, the monthly price is no longer shown (only the yearly one): ' . json_encode($ipCards['business']));

// L2. A scheduled price: not shown before its start, shown once it starts.
$ipServer->query("INSERT INTO `tblPlanPricePoints` (`planID`, `priceKey`, `priceGroupKey`, `billingInterval`, `intervalCount`, `amount`, `currency`, `validFrom`, `isDefault`, `isActive`, `sortOrder`) VALUES (" . $ipBusinessID . ", 'monthly_gbp_2', 'monthly', 'monthly', 1, 26.99, 'GBP', UTC_TIMESTAMP() + INTERVAL 1 HOUR, 0, 1, 1)");
ipCheck('L', ipPublicCards()['business']['text'] === '£199.99 /year | Billed yearly', 'F6: a price that starts later is not shown yet');
$ipServer->query("UPDATE `tblPlanPricePoints` SET `validFrom` = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE `planID` = " . $ipBusinessID . " AND `priceKey` = 'monthly_gbp_2'");
ipCheck('L', ipPublicCards()['business']['text'] === '£26.99 /month | or £199.99 billed yearly', 'F6: once its start has passed it IS shown, without anyone saving');

// L2b. Codex finding 3.3: with billing_price_points_enabled ON, a second
//      monthly offer flagged "recommended" (later in the display order) must
//      not take over the card - the page and the admin preview both choose
//      by price group, on sale now, lowest display order first.
$ipServer->query("INSERT INTO `tblPlanPricePoints` (`planID`, `priceKey`, `priceGroupKey`, `billingInterval`, `intervalCount`, `amount`, `currency`, `isDefault`, `isActive`, `sortOrder`) VALUES (" . $ipBusinessID . ", 'monthly_gbp_3', 'monthly', 'monthly', 1, 29.99, 'GBP', 1, 1, 5)");
$ipServer->query("UPDATE `tblSettings` SET `settingValue` = '1' WHERE `settingKey` = 'billing_price_points_enabled'");
clearConfigCache();
$ipPreviewSelection = adminMirrorSelection((array) adminPlansLoadPrices($ipBusinessID, false), 'GBP', adminPlansNowUtc());
ipCheck('L', (bool) getConfig('billing_price_points_enabled', false) === true && ipPublicCards()['business']['text'] === '£26.99 /month | or £199.99 billed yearly' && $ipPreviewSelection['monthly'] === '26.99', 'finding 3.3: with price points switched on, the card still shows the group\'s £26.99 (not the recommended £29.99), the same as the admin preview\'s choice (' . (string) $ipPreviewSelection['monthly'] . ')');
$ipServer->query("UPDATE `tblSettings` SET `settingValue` = '0' WHERE `settingKey` = 'billing_price_points_enabled'");
clearConfigCache();
$ipServer->query("DELETE FROM `tblPlanPricePoints` WHERE `planID` = " . $ipBusinessID . " AND `priceKey` = 'monthly_gbp_3'");

// L3. Nothing on sale any more for a paid plan: "Price coming soon" (decision 0021).
$ipServer->query("UPDATE `tblPlanPricePoints` SET `validUntil` = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE `planID` = " . $ipBusinessID);
$ipCards = ipPublicCards();
ipCheck('L', $ipCards['business']['text'] === 'Price coming soon | ' && $ipCards['business']['free'] === false, 'F6: every price ended - "Price coming soon", never Free and never the old figure: ' . json_encode($ipCards['business']));

// ↩️ Put Business back as it was for the scenarios after this one.
$ipServer->query("DELETE FROM `tblPlanPricePoints` WHERE `planID` = " . $ipBusinessID . " AND `priceKey` = 'monthly_gbp_2'");
$ipServer->query("UPDATE `tblPlanPricePoints` SET `validUntil` = NULL WHERE `planID` = " . $ipBusinessID);
ipCheck('L', ipPublicCards()['business']['text'] === '£24.99 /month | or £199.99 billed yearly', 'Business is back to £24.99 / £199.99');

// L4. The only price of an on-sale plan is DELETED through the editor.
$ipSoloMade = adminPlansCreate($ipAdmin, ['plan_key' => 'solo_it', 'plan_kind' => 'standard', 'plan_name' => 'Solo', 'sort_order' => '6', 'cta_mode' => 'checkout']);
$ipSoloID = (int) $ipSoloMade['planID'];
$ipSoloSaved = adminPricesSaveGroup($ipAdmin, $ipSoloID, 'monthly', ipPricesFp($ipSoloID), ipFullPost(['amount' => ['GBP' => '5.00']]));
$ipSoloOnSale = adminPlansSetOnSale($ipAdmin, $ipSoloID, ipPlanFp($ipSoloID), true, false);
ipCheck('L', $ipSoloMade['ok'] && $ipSoloSaved['ok'] && $ipSoloOnSale['ok'] && ipPublicCards()['solo_it']['text'] === '£5.00 /month | Billed monthly', 'setup: a new plan on sale at £5.00 a month');
$ipSoloPrice = ipPrice($ipServer, 'solo_it', 'monthly_gbp');
$ipSoloDeleted = adminPricesDelete($ipAdmin, $ipSoloID, (int) $ipSoloPrice['pricePointID'], ipPricesFp($ipSoloID), true);
$ipCards = ipPublicCards();
ipCheck('L', $ipSoloDeleted['ok'] === true && $ipCards['solo_it']['text'] === 'Price coming soon | ' && $ipCards['solo_it']['free'] === false, 'F6: after its only price is deleted the card says "Price coming soon", not the deleted £5.00 (and not Free): ' . json_encode($ipCards['solo_it']));
$ipSoloOff = adminPlansSetOnSale($ipAdmin, $ipSoloID, ipPlanFp($ipSoloID), false, true);
ipCheck('L', $ipSoloOff['ok'] === true, 'tidy up: the test plan is taken off sale');

// ==============================================================================
// M. Two races the 7n Phase 2 review found by reading the code
// ==============================================================================
echo PHP_EOL . 'M. Races: price save against a main-currency change; trial plan against a delete' . PHP_EOL;

/**
 * 👶 Start this script as a child doing one action on the test database.
 *
 * @return array{process: resource, pipes: array<int, resource>}
 */
function ipChildStart(string $ipDb, array $ipRequest): array
{
    $ipCommand = [PHP_BINARY, __FILE__, '--save-group', $ipDb, base64_encode((string) json_encode($ipRequest))];
    $ipProcess = proc_open($ipCommand, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $ipPipes, null, ['CUERCODE_IT_ENCRYPTION_KEY' => ENCRYPTION_KEY] + getenv());
    fclose($ipPipes[0]);
    return ['process' => $ipProcess, 'pipes' => $ipPipes];
}

/**
 * 👶 Is the child still running (that is, waiting for a lock)?
 */
function ipChildRunning(array $ipChild): bool
{
    return proc_get_status($ipChild['process'])['running'] === true;
}

/**
 * 👶 Wait for the child and return its result (null when it printed none).
 */
function ipChildFinish(array $ipChild): ?array
{
    $ipOut = (string) stream_get_contents($ipChild['pipes'][1]);
    fclose($ipChild['pipes'][1]);
    $ipErr = (string) stream_get_contents($ipChild['pipes'][2]);
    fclose($ipChild['pipes'][2]);
    proc_close($ipChild['process']);
    $ipMarker = strrpos($ipOut, '@@RESULT@@');
    if ($ipMarker === false) {
        echo '    (child said: ' . trim($ipOut . ' ' . $ipErr) . ')' . PHP_EOL;
        return null;
    }
    return json_decode(trim(substr($ipOut, $ipMarker + strlen('@@RESULT@@'))), true);
}

$ipAdminChild = ['userID' => $ipAdminID, 'email' => $ipAdminEmail];
$ipProID = (int) ipPlan($ipServer, 'pro')['planID'];

// M1. A price save and a main-currency change at the same moment. The other
//     connection holds Pro's row so both have to queue: first the currency
//     change (which copies every plan's prices in the NEW currency), then the
//     price save. Before the fix the save had read the main currency before
//     its transaction, so it copied Pro back into the OLD currency.
$ipUsdForPro = adminPricesSaveGroup($ipAdmin, $ipProID, 'monthly', ipPricesFp($ipProID), ipFullPost(['amount' => ['GBP' => '6.99', 'USD' => '8.99', 'EUR' => '']]));
ipCheck('M', $ipUsdForPro['ok'] === true && ipPrice($ipServer, 'pro', 'monthly_usd')['amount'] === '8.99', 'setup: Pro gets a USD monthly price (8.99)');
$ipOther->begin_transaction();
$ipHold = $ipOther->query('SELECT `planID` FROM `tblSubscriptionPlans` WHERE `planID` = ' . $ipProID . ' FOR UPDATE');
$ipHold->free();
$ipCurrencyChild = ipChildStart($ipDbName, $ipAdminChild + ['action' => 'setting', 'section' => 'prices', 'key' => 'billing_default_currency', 'value' => 'USD']);
usleep(1500000);
$ipPriceChild = ipChildStart($ipDbName, $ipAdminChild + ['planID' => $ipProID, 'group' => 'monthly', 'fp' => '@current', 'post' => ['amount' => ['GBP' => '7.49', 'USD' => '8.99', 'EUR' => '']]]);
usleep(1500000);
ipCheck('M', ipChildRunning($ipCurrencyChild) && ipChildRunning($ipPriceChild), 'race: the currency change and the price save are both waiting');
$ipOther->commit();
$ipCurrencyResult = ipChildFinish($ipCurrencyChild);
$ipPriceResult = ipChildFinish($ipPriceChild);
ipCheck('M', is_array($ipCurrencyResult) && $ipCurrencyResult['ok'] === true && is_array($ipPriceResult) && $ipPriceResult['ok'] === true, 'race: both saved: ' . json_encode([$ipCurrencyResult, $ipPriceResult]));
$ipProAfter = ipPlan($ipServer, 'pro');
$ipMainAfter = ipRow($ipServer, "SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'billing_default_currency'");
ipCheck('M', $ipMainAfter['settingValue'] === 'USD' && $ipProAfter['currency'] === 'USD' && $ipProAfter['priceMonthly'] === '8.99', 'race: Pro\'s older price columns are in the NEW main currency (USD 8.99), not copied back into GBP: ' . json_encode([$ipMainAfter['settingValue'], $ipProAfter['currency'], $ipProAfter['priceMonthly']]));
ipCheck('M', ipPrice($ipServer, 'pro', 'monthly_gbp')['amount'] === '7.49', 'race: the price change itself was saved (GBP 7.49)');
// ↩️ Back to GBP and Pro's draft price for what follows.
$ipStates = adminSettingsLoadRows($ipPricesKeys);
$ipBack = adminSettingsProcessSection('prices', ['f' => ['billing_default_currency' => 'GBP']], (array) $ipStates);
adminSettingsSaveSection($ipAdmin, 'prices', adminSettingsSectionFingerprint('prices', (array) $ipStates), $ipBack['changes']);
adminPricesSaveGroup($ipAdmin, $ipProID, 'monthly', ipPricesFp($ipProID), ipFullPost(['amount' => ['GBP' => '6.99', 'USD' => '8.99', 'EUR' => '']]));
ipCheck('M', ipPlan($ipServer, 'pro')['currency'] === 'GBP' && ipPlan($ipServer, 'pro')['priceMonthly'] === '6.99', 'tidy up: main currency GBP, Pro at 6.99 again');

// M2. The trial-plan setting saved at the same moment the plan it names is
//     deleted. The other connection pauses whichever runs first just before
//     its change-log row (a lock at the end of tblAdminAuditLog), so the
//     second really overlaps it. Both orders must end with no setting naming
//     a deleted plan.
$ipLatestAudit = (int) ipRow($ipServer, 'SELECT COALESCE(MAX(`auditID`), 0) AS `m` FROM `tblAdminAuditLog`')['m'];
foreach (['delete first' => ['delete-plan', 'setting'], 'setting first' => ['setting', 'delete-plan']] as $ipOrderName => $ipOrder) {
    $ipRaceKey = 'race_trial_' . substr(md5($ipOrderName), 0, 6);
    $ipRaceMade = adminPlansCreate($ipAdmin, ['plan_key' => $ipRaceKey, 'plan_kind' => 'standard', 'plan_name' => 'Race ' . $ipOrderName, 'sort_order' => '9', 'cta_mode' => 'checkout']);
    $ipRaceID = (int) $ipRaceMade['planID'];
    adminPricesSaveGroup($ipAdmin, $ipRaceID, 'monthly', ipPricesFp($ipRaceID), ipFullPost(['amount' => ['GBP' => '3.00']]));
    adminPlansSetOnSale($ipAdmin, $ipRaceID, ipPlanFp($ipRaceID), true, false);
    ipCheck('M', in_array($ipRaceKey, (array) adminSettingsTrialPlanKeys(), true), $ipOrderName . ': setup: ' . $ipRaceKey . ' may be chosen as the trial plan');

    $ipLatestAudit = (int) ipRow($ipServer, 'SELECT COALESCE(MAX(`auditID`), 0) AS `m` FROM `tblAdminAuditLog`')['m'];
    $ipOther->begin_transaction();
    $ipGap = $ipOther->query('SELECT `auditID` FROM `tblAdminAuditLog` WHERE `auditID` > ' . $ipLatestAudit . ' FOR UPDATE');
    $ipGap->free();
    $ipChildren = [];
    foreach ($ipOrder as $ipAction) {
        if ($ipAction === 'setting') {
            $ipChildren[$ipAction] = ipChildStart($ipDbName, $ipAdminChild + ['action' => 'setting', 'section' => 'trials', 'key' => 'billing_trial_plan_key', 'value' => $ipRaceKey]);
        } else {
            $ipChildren[$ipAction] = ipChildStart($ipDbName, $ipAdminChild + ['action' => 'delete-plan', 'planID' => $ipRaceID]);
        }
        usleep(1500000);
    }
    ipCheck('M', ipChildRunning($ipChildren['setting']) && ipChildRunning($ipChildren['delete-plan']), $ipOrderName . ': both are in progress at the same moment');
    $ipOther->commit();
    $ipSettingResult = ipChildFinish($ipChildren['setting']);
    $ipDeleteResult = ipChildFinish($ipChildren['delete-plan']);
    $ipTrialNow = (string) ipRow($ipServer, "SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'billing_trial_plan_key'")['settingValue'];
    $ipPlanNow = ipPlan($ipServer, $ipRaceKey);
    ipCheck('M', ($ipTrialNow === $ipRaceKey && $ipPlanNow !== null) || ($ipTrialNow !== $ipRaceKey && $ipPlanNow === null), $ipOrderName . ': never a trial setting naming a deleted plan (setting: ' . $ipTrialNow . ', plan ' . ($ipPlanNow === null ? 'deleted' : 'kept') . '): ' . json_encode([$ipSettingResult['ok'] ?? null, $ipDeleteResult['ok'] ?? null]));
    ipCheck('M', is_array($ipSettingResult) && is_array($ipDeleteResult) && ($ipSettingResult['ok'] === true) !== ($ipDeleteResult['ok'] === true), $ipOrderName . ': exactly one of the two went ahead and the other was refused');

    // ↩️ The trial plan back to Pro, and the test plan off sale.
    if ($ipTrialNow === $ipRaceKey) {
        $ipTrialStates = adminSettingsLoadRows(array_keys(adminSettingsFieldsForSection('trials')));
        $ipTrialBack = adminSettingsProcessSection('trials', ['f' => ['billing_trial_plan_key' => 'pro']], (array) $ipTrialStates, ['planKeys' => (array) adminSettingsTrialPlanKeys()]);
        adminSettingsSaveSection($ipAdmin, 'trials', adminSettingsSectionFingerprint('trials', (array) $ipTrialStates), $ipTrialBack['changes']);
    }
    if (ipPlan($ipServer, $ipRaceKey) !== null) {
        adminPlansSetOnSale($ipAdmin, $ipRaceID, ipPlanFp($ipRaceID), false, true);
    }
}
ipCheck('M', (string) ipRow($ipServer, "SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'billing_trial_plan_key'")['settingValue'] === 'pro', 'tidy up: the trial plan is Pro again');

// ==============================================================================
// K. Charging stays off
// ==============================================================================
echo PHP_EOL . 'K. Charging stays off' . PHP_EOL;
foreach (['billing_price_points_enabled', 'billing_discounts_enabled', 'billing_payg_enabled'] as $ipSwitch) {
    $ipValue = ipRow($ipServer, 'SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ?', 's', [$ipSwitch]);
    ipCheck('K', $ipValue !== null && (string) $ipValue['settingValue'] === '0', $ipSwitch . ' is still 0');
}
ipCheck('K', ipCount($ipServer, "SELECT COUNT(*) FROM `tblAdminAuditLog` WHERE `entityKey` LIKE 'billing\\_%\\_enabled'") === 0, 'no audit row touches a charging switch');
ipCheck('K', ipCount($ipServer, "SELECT COUNT(*) FROM `tblPlanPricePoints` WHERE `amount` <> ROUND(`amount`, 2)") === 0, 'every stored amount has at most two decimals');

// ==============================================================================
// 🏁 Result
// ==============================================================================
$ipOther->close();
$ipServer->query('DROP DATABASE IF EXISTS `' . $ipDbName . '`');

$ipFailedScenarios = [];
foreach ($ipChecks as $ipScenario => $ipPassed) {
    if (!$ipPassed) {
        $ipFailedScenarios[] = $ipScenario;
    }
}

echo PHP_EOL . $ipCheckCount . ' checks, scenarios ' . implode(' ', array_keys($ipChecks)) . '.' . PHP_EOL;
if ($ipFailedScenarios !== []) {
    echo 'FAILED scenarios: ' . implode(', ', $ipFailedScenarios) . PHP_EOL;
    exit(1);
}
echo 'All scenarios passed.' . PHP_EOL;
exit(0);
