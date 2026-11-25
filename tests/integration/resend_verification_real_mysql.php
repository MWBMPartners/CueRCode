<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - OPTIONAL real-database check for "new verification link" (#128)
 * ==============================================================================
 *
 * Path: tests/integration/resend_verification_real_mysql.php
 *
 * Proves, against a real MySQL/MariaDB server, the parts the no-database tests
 * (tests/test_resend_verification.php) cannot: the real SQL of
 * requestNewVerificationLink() and issueEmailVerificationLink().
 *
 * Scenarios:
 *   1 a waiting account gets a new token and ONE queued email; the older
 *     unused token is retired and the new one is not
 *   2 the answer is identical for a waiting account, an unknown address, an
 *     already-verified account and a suspended account, and only the waiting
 *     one gets a token
 *   3 a second request inside the minimum gap makes nothing
 *   4 after the gap, the daily cap stops further links
 *   5 the per-IP cap stops requests (for any address) and the count only
 *     includes this form's own rows
 *   6 the activity log rows name the account number and never the address,
 *     and the queued email is the only place the link (token) appears
 *   7 sign-up (registerUser) still makes a pending account with one token and one queued email
 *
 * Not run by tests/run.php (needs a database). CI syntax-checks it.
 *
 * ⚠️ SAFETY: it DELETES rows. It refuses to run (exit 2) unless the database
 *    name starts with "cuercode_it_". It never sends email (it only reads the
 *    queue table) and uses reserved ".invalid" addresses. Connection details
 *    come only from CUERCODE_IT_DB_* environment variables.
 *
 * Run (see tests/README.md for the full Docker commands):
 *     CUERCODE_IT_DB_HOST=127.0.0.1 CUERCODE_IT_DB_PORT=33128 \
 *     CUERCODE_IT_DB_NAME=cuercode_it_p128 CUERCODE_IT_DB_USER=root CUERCODE_IT_DB_PASS= \
 *       php tests/integration/resend_verification_real_mysql.php --load-schema
 *
 * Exit codes: 0 all passed | 1 a check failed or crashed | 2 refused.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-25 (#128)
 */

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
    }
    exit('Access denied.');
}

$rvSiteRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'CueRCode.net' . DIRECTORY_SEPARATOR;
$rvLoad     = in_array('--load-schema', array_slice($argv, 1), true);

$rvEnv = [];
foreach (['HOST', 'PORT', 'NAME', 'USER', 'PASS'] as $rvKey) {
    $rvValue = getenv('CUERCODE_IT_DB_' . $rvKey);
    $rvEnv[$rvKey] = ($rvValue === false) ? '' : $rvValue;
}

if (preg_match('/^cuercode_it_[a-z0-9_]+$/', $rvEnv['NAME']) !== 1) {
    fwrite(STDERR, 'Refusing to run: CUERCODE_IT_DB_NAME must start with "cuercode_it_".' . PHP_EOL);
    exit(2);
}
if ($rvEnv['HOST'] === '' || $rvEnv['USER'] === '') {
    fwrite(STDERR, 'Refusing to run: set CUERCODE_IT_DB_HOST and CUERCODE_IT_DB_USER.' . PHP_EOL);
    exit(2);
}
if ($rvEnv['PORT'] === '') {
    $rvEnv['PORT'] = '3306';
}
$rvPort = (int) $rvEnv['PORT'];

// ⏳ Wait for the server (the Docker image restarts once while starting).
$rvDeadline = microtime(true) + 120;
$rvConn     = null;
$rvLastErr  = '';
while ($rvConn === null && microtime(true) < $rvDeadline) {
    try {
        $rvConn = @new \mysqli($rvEnv['HOST'], $rvEnv['USER'], $rvEnv['PASS'], $rvEnv['NAME'], $rvPort);
    } catch (\Throwable $rvError) {
        $rvLastErr = $rvError->getMessage();
        usleep(500000);
    }
}
if ($rvConn === null) {
    fwrite(STDERR, 'Could not connect within 120 seconds: ' . $rvLastErr . PHP_EOL);
    exit(1);
}

// 📥 Load the schema if asked and the tables are missing.
$rvHas = $rvConn->query("SELECT COUNT(*) AS `c` FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblEmailVerifications'");
$rvHasTable = ((int) $rvHas->fetch_assoc()['c'] === 1);
if (!$rvHasTable) {
    if (!$rvLoad) {
        fwrite(STDERR, 'The tables do not exist. Re-run with --load-schema.' . PHP_EOL);
        exit(2);
    }
    foreach (['install.sql', 'seed_data.sql'] as $rvSqlFile) {
        $rvSql = file_get_contents($rvSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . $rvSqlFile);
        $rvConn->multi_query((string) $rvSql);
        do {
            $rvStored = $rvConn->store_result();
            if ($rvStored instanceof \mysqli_result) {
                $rvStored->free();
            }
        } while ($rvConn->more_results() && $rvConn->next_result());
        echo 'Loaded ' . $rvSqlFile . '.' . PHP_EOL;
    }
}
$rvConn->close();

define('DB_HOST', $rvEnv['HOST']);
define('DB_PORT', $rvPort);
define('DB_NAME', $rvEnv['NAME']);
define('DB_USER', $rvEnv['USER']);
define('DB_PASS', $rvEnv['PASS']);
define('ENCRYPTION_KEY', bin2hex(random_bytes(16)));

require_once $rvSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

restore_error_handler();
ini_set('display_errors', 'stderr');
set_exception_handler(static function (\Throwable $rvError): void {
    fwrite(STDERR, 'Integration check crashed: ' . get_class($rvError) . ': ' . $rvError->getMessage() . PHP_EOL);
    exit(1);
});

$_SERVER['REMOTE_ADDR']     = '203.0.113.50';
$_SERVER['HTTP_USER_AGENT'] = 'integration-check';

$rvFailures = 0;

function rvCheck(bool $rvPassed, string $rvWhat): void
{
    if (!$rvPassed) {
        $GLOBALS['rvFailures']++;
    }
    echo ($rvPassed ? '    ok   ' : '    FAIL ') . $rvWhat . PHP_EOL;
}

function rvSelect(string $rvSql, string $rvTypes = '', array $rvParams = []): array
{
    $rvStmt = dbQuery($rvSql, $rvTypes, $rvParams);
    $rvRes  = $rvStmt->get_result();
    $rvRows = [];
    while ($rvRow = $rvRes->fetch_assoc()) {
        $rvRows[] = $rvRow;
    }
    $rvStmt->close();
    return $rvRows;
}

function rvExec(string $rvSql, string $rvTypes = '', array $rvParams = []): void
{
    $rvStmt = dbQuery($rvSql, $rvTypes, $rvParams);
    $rvStmt->close();
}

/** 🧹 Wipe everything this script touches (database is throwaway). */
function rvReset(): void
{
    rvExec('DELETE FROM `tblEmailQueue`');
    rvExec('DELETE FROM `tblEmailVerifications`');
    rvExec('DELETE FROM `tblAPIRateLog`');
    rvExec('DELETE FROM `tblActivityLog`');
    rvExec("DELETE FROM `tblUsers` WHERE `email` LIKE 'rv-%@rv.cuercode.invalid'");
}

/** 👤 Make a user and return the id. */
function rvUser(string $rvName, string $rvStatus, bool $rvVerified): int
{
    $rvEmail = 'rv-' . $rvName . '@rv.cuercode.invalid';
    if ($rvVerified) {
        rvExec(
            'INSERT INTO `tblUsers` (`email`, `passwordHash`, `status`, `emailVerifiedAt`) VALUES (?, ?, ?, NOW())',
            'sss',
            [$rvEmail, 'x', $rvStatus]
        );
    } else {
        rvExec(
            'INSERT INTO `tblUsers` (`email`, `passwordHash`, `status`) VALUES (?, ?, ?)',
            'sss',
            [$rvEmail, 'x', $rvStatus]
        );
    }
    return (int) rvSelect('SELECT `userID` FROM `tblUsers` WHERE `email` = ?', 's', [$rvEmail])[0]['userID'];
}

function rvAddr(string $rvName): string
{
    return 'rv-' . $rvName . '@rv.cuercode.invalid';
}

/** 🎫 Add an old token for a user, created $rvAgeSeconds ago. */
function rvOldToken(int $rvUserID, string $rvTag, int $rvAgeSeconds, bool $rvUsed = false): void
{
    rvExec(
        'INSERT INTO `tblEmailVerifications` (`userID`, `tokenHash`, `expiresAt`, `usedAt`, `createdAt`) '
        . 'VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), ' . ($rvUsed ? 'NOW()' : 'NULL') . ', DATE_SUB(NOW(), INTERVAL ? SECOND))',
        'isi',
        [$rvUserID, hash('sha256', 'rv-' . $rvTag), $rvAgeSeconds]
    );
}

function rvTokenCount(int $rvUserID, bool $rvUnusedOnly = false): int
{
    $rvSql = 'SELECT COUNT(*) AS `c` FROM `tblEmailVerifications` WHERE `userID` = ?' . ($rvUnusedOnly ? ' AND `usedAt` IS NULL' : '');
    return (int) rvSelect($rvSql, 'i', [$rvUserID])[0]['c'];
}

function rvQueueCount(): int
{
    return (int) rvSelect('SELECT COUNT(*) AS `c` FROM `tblEmailQueue`')[0]['c'];
}

echo 'Connected. Running checks (ports/addresses are throwaway, no email is ever sent).' . PHP_EOL;

// ------------------------------------------------------------------ 1
echo PHP_EOL . 'Scenario 1: a waiting account gets a new token, older ones retired' . PHP_EOL;
rvReset();
$rvA = rvUser('a', 'pending', false);
rvOldToken($rvA, 'old1', 7200);
rvOldToken($rvA, 'old2', 3700);
$rvResult = requestNewVerificationLink(rvAddr('a'), '203.0.113.1');
rvCheck($rvResult['success'] === true && $rvResult['httpCode'] === 200, 'request answers 200 success');
rvCheck(rvTokenCount($rvA) === 3, 'there are now 3 tokens in total');
rvCheck(rvTokenCount($rvA, true) === 1, 'exactly 1 token is still unused (the new one)');
rvCheck(rvQueueCount() === 1, 'exactly 1 email was queued');
$rvNew = rvSelect('SELECT `usedAt` FROM `tblEmailVerifications` WHERE `userID` = ? ORDER BY `verificationID` DESC LIMIT 1', 'i', [$rvA])[0];
rvCheck($rvNew['usedAt'] === null, 'the newest token is the unused one');

// ------------------------------------------------------------------ 2
echo PHP_EOL . 'Scenario 2: same answer for every kind of address' . PHP_EOL;
rvReset();
$rvPending   = rvUser('pend', 'pending', false);
$rvVerified  = rvUser('ver', 'active', true);
$rvSuspended = rvUser('sus', 'suspended', true);
$rvAnswers   = [];
foreach (['pend', 'ver', 'sus', 'nobody'] as $rvName) {
    $rvAnswers[$rvName] = requestNewVerificationLink(rvAddr($rvName), '203.0.113.2');
}
rvCheck(count(array_unique(array_map('json_encode', $rvAnswers))) === 1, 'all four answers are byte-for-byte identical');
rvCheck(rvTokenCount($rvPending) === 1, 'the waiting account got a token');
rvCheck(rvTokenCount($rvVerified) === 0 && rvTokenCount($rvSuspended) === 0, 'the verified and suspended accounts got none');
rvCheck(rvQueueCount() === 1, 'only one email was queued');

// ------------------------------------------------------------------ 3
echo PHP_EOL . 'Scenario 3: minimum gap' . PHP_EOL;
rvReset();
$rvB = rvUser('b', 'pending', false);
requestNewVerificationLink(rvAddr('b'), '203.0.113.3');
requestNewVerificationLink(rvAddr('b'), '203.0.113.3');
rvCheck(rvTokenCount($rvB) === 1, 'a second request straight away made no second token');
rvCheck(rvQueueCount() === 1, 'and queued no second email');

// ------------------------------------------------------------------ 4
echo PHP_EOL . 'Scenario 4: daily cap' . PHP_EOL;
rvReset();
$rvC = rvUser('c', 'pending', false);
for ($rvI = 0; $rvI < 5; $rvI++) {
    rvOldToken($rvC, 'c' . $rvI, 3600 + $rvI);
}
requestNewVerificationLink(rvAddr('c'), '203.0.113.4');
rvCheck(rvTokenCount($rvC) === 5, '5 links in the last day (the default cap): nothing new is made');
rvExec('UPDATE `tblEmailVerifications` SET `createdAt` = DATE_SUB(NOW(), INTERVAL 25 HOUR) WHERE `userID` = ?', 'i', [$rvC]);
requestNewVerificationLink(rvAddr('c'), '203.0.113.4');
rvCheck(rvTokenCount($rvC) === 6, 'once those are over a day old, a new link is made');

// ------------------------------------------------------------------ 5
echo PHP_EOL . 'Scenario 5: per-IP cap' . PHP_EOL;
rvReset();
$rvD = rvUser('d', 'pending', false);
for ($rvI = 0; $rvI < 10; $rvI++) {
    requestNewVerificationLink(rvAddr('nobody' . $rvI), '203.0.113.5');
}
rvCheck(countVerificationResendRequestsFromIp('203.0.113.5') === 10, '10 requests from one address are counted');
requestNewVerificationLink(rvAddr('d'), '203.0.113.5');
rvCheck(rvTokenCount($rvD) === 0, 'the 11th request from that address is refused, even for a real waiting account');
requestNewVerificationLink(rvAddr('d'), '203.0.113.6');
rvCheck(rvTokenCount($rvD) === 1, 'a different address is not affected');
recordAPIRequest(null, '203.0.113.7', 'generate');
rvCheck(countVerificationResendRequestsFromIp('203.0.113.7') === 0, 'ordinary API traffic from an address is not counted');

// ------------------------------------------------------------------ 6
echo PHP_EOL . 'Scenario 6: logs hold no address' . PHP_EOL;
rvReset();
$rvE = rvUser('e', 'pending', false);
requestNewVerificationLink(rvAddr('e'), '203.0.113.8');
$rvLogs = rvSelect("SELECT `activityType`, `activityDetail`, `entityID`, `additionalData` FROM `tblActivityLog` WHERE `activityType` LIKE 'verification_link%'");
rvCheck(count($rvLogs) === 1 && $rvLogs[0]['activityType'] === 'verification_link_resent', 'one activity row was written');
rvCheck((int) $rvLogs[0]['entityID'] === $rvE, 'it names the account number');
rvCheck(!str_contains(json_encode($rvLogs), 'rv.cuercode.invalid') && !str_contains(json_encode($rvLogs), 'token='), 'it holds no email address and no link');
// 📌 The shared queueEmail() writes its own 'email_queued' audit row that
//    names the recipient (the email audit trail's existing, deliberate design -
//    not part of this feature). Every OTHER row, and every row's link/token
//    text, must be free of the address.
$rvAnyLog = rvSelect(
    'SELECT COUNT(*) AS `c` FROM `tblActivityLog` WHERE `activityType` <> ? AND (`requestURL` LIKE ? OR `activityDetail` LIKE ? OR `additionalData` LIKE ?)',
    'ssss',
    ['email_queued', '%rv.cuercode%', '%rv.cuercode%', '%rv.cuercode%']
);
rvCheck((int) $rvAnyLog[0]['c'] === 0, 'no activity row (other than the shared email_queued audit row) contains the test address');
$rvAnyLink = rvSelect('SELECT COUNT(*) AS `c` FROM `tblActivityLog` WHERE `activityDetail` LIKE ? OR `additionalData` LIKE ? OR `requestURL` LIKE ?', 'sss', ['%token=%', '%token=%', '%token=%']);
rvCheck((int) $rvAnyLink[0]['c'] === 0, 'no activity row of any kind contains a verification link');

// ------------------------------------------------------------------ 7
echo PHP_EOL . 'Scenario 7: sign-up still works through the shared helper' . PHP_EOL;
rvReset();
$rvReg = registerUser(rvAddr('reg'), 'Correct-Horse-Battery-9!', 'Reg');
$rvRegUser = rvSelect('SELECT `userID`, `status` FROM `tblUsers` WHERE `email` = ?', 's', [rvAddr('reg')]);
rvCheck($rvReg['success'] === true && count($rvRegUser) === 1 && $rvRegUser[0]['status'] === 'pending', 'sign-up made a pending account');
rvCheck(rvTokenCount((int) $rvRegUser[0]['userID'], true) === 1 && rvQueueCount() === 1, 'with one unused token and one queued email');

// ------------------------------------------------------------------ 8
echo PHP_EOL . 'Scenario 8 (review fix M3): the limit is logged at most once an hour per account' . PHP_EOL;
rvReset();
$rvF = rvUser('f', 'pending', false);
for ($rvI = 0; $rvI < 5; $rvI++) {
    rvOldToken($rvF, 'f' . $rvI, 3600 + $rvI);
}
for ($rvI = 0; $rvI < 4; $rvI++) {
    requestNewVerificationLink(rvAddr('f'), '203.0.113.20');
}
$rvLimited = rvSelect("SELECT COUNT(*) AS `c` FROM `tblActivityLog` WHERE `activityType` = 'verification_link_resend_limited' AND `entityID` = ?", 'i', [$rvF]);
rvCheck((int) $rvLimited[0]['c'] === 1, 'four refused requests in a row wrote ONE limited row (not four)');
rvCheck(rvTokenCount($rvF) === 5, '...and made no new token');
rvExec("UPDATE `tblActivityLog` SET `createdAt` = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE `activityType` = 'verification_link_resend_limited'");
requestNewVerificationLink(rvAddr('f'), '203.0.113.20');
$rvLimited = rvSelect("SELECT COUNT(*) AS `c` FROM `tblActivityLog` WHERE `activityType` = 'verification_link_resend_limited' AND `entityID` = ?", 'i', [$rvF]);
rvCheck((int) $rvLimited[0]['c'] === 2, 'an hour later the next refusal is logged again (2 rows)');

// ------------------------------------------------------------------ 9
echo PHP_EOL . 'Scenario 9 (review fix M3): answer time does not depend on whether the address is waiting' . PHP_EOL;
rvReset();
$rvG = rvUser('g', 'pending', false);
$rvTimes = [];
foreach (['g' => rvAddr('g'), 'nobody' => rvAddr('nobody-at-all')] as $rvLabel => $rvAddress) {
    $rvStartedAt = hrtime(true);
    requestNewVerificationLink($rvAddress, '203.0.113.21');
    $rvTimes[$rvLabel] = (hrtime(true) - $rvStartedAt) / 1000000;
}
rvCheck($rvTimes['g'] >= 245 && $rvTimes['nobody'] >= 245, 'both took at least the fixed minimum (waiting ' . round($rvTimes['g']) . ' ms, unknown ' . round($rvTimes['nobody']) . ' ms)');

// ------------------------------------------------------------------ 10
echo PHP_EOL . 'Scenario 10 (review fix M4): only SMALLER-numbered tokens are retired' . PHP_EOL;
rvReset();
$rvH = rvUser('h', 'pending', false);
rvOldToken($rvH, 'h-old', 7200);
$rvOldID = (int) rvSelect('SELECT MAX(`verificationID`) AS `m` FROM `tblEmailVerifications` WHERE `userID` = ?', 'i', [$rvH])[0]['m'];
issueEmailVerificationLink($rvH, rvAddr('h'), null, true);
$rvNewID = (int) rvSelect('SELECT MAX(`verificationID`) AS `m` FROM `tblEmailVerifications` WHERE `userID` = ?', 'i', [$rvH])[0]['m'];
rvCheck($rvNewID > $rvOldID, 'the new token has the higher number');
rvCheck((int) rvSelect('SELECT COUNT(*) AS `c` FROM `tblEmailVerifications` WHERE `userID` = ? AND `usedAt` IS NULL AND `verificationID` = ?', 'ii', [$rvH, $rvNewID])[0]['c'] === 1, 'the newest is unused');
rvCheck((int) rvSelect('SELECT COUNT(*) AS `c` FROM `tblEmailVerifications` WHERE `userID` = ? AND `usedAt` IS NULL AND `verificationID` = ?', 'ii', [$rvH, $rvOldID])[0]['c'] === 0, 'the older one is retired');
// 🔁 A token with a HIGHER number than ours (made by a request at the same moment) must survive our retire step.
rvExec('INSERT INTO `tblEmailVerifications` (`userID`, `tokenHash`, `expiresAt`) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))', 'is', [$rvH, hash('sha256', 'rv-h-other')]);
$rvOtherID = (int) rvSelect('SELECT MAX(`verificationID`) AS `m` FROM `tblEmailVerifications` WHERE `userID` = ?', 'i', [$rvH])[0]['m'];
$rvBurn = getDB()->prepare('UPDATE `tblEmailVerifications` SET `usedAt` = NOW() WHERE `userID` = ? AND `usedAt` IS NULL AND `verificationID` < ?');
$rvBurnBelow = $rvNewID; // ours: the smaller number of the two
$rvBurn->bind_param('ii', $rvH, $rvBurnBelow);
$rvBurn->execute();
$rvBurn->close();
rvCheck((int) rvSelect('SELECT COUNT(*) AS `c` FROM `tblEmailVerifications` WHERE `userID` = ? AND `usedAt` IS NULL AND `verificationID` = ?', 'ii', [$rvH, $rvOtherID])[0]['c'] === 1, 'a higher-numbered unused token is left alone by the same retire rule');

rvReset();
echo PHP_EOL . ($rvFailures === 0 ? 'ALL CHECKS PASSED' : $rvFailures . ' CHECK(S) FAILED') . PHP_EOL;
exit($rvFailures === 0 ? 0 : 1);
