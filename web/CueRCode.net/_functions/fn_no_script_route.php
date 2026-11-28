<?php
/**
 * ==============================================================================
 * ✉️ CueRCode - The email-confirmed route for visitors without JavaScript (#163, decision 0038)
 * ==============================================================================
 *
 * Path: _functions/fn_no_script_route.php
 *
 * WHY (plain English):
 *   The human check (fn_human_check.php) needs JavaScript. Decision 0038: a
 *   visitor without it gets a slower route, confirmed by email, with strict
 *   limits, for the four forms - sign up, sign in, forgot password and send a
 *   new verification link. The route is offered by a button inside the
 *   form's <noscript> part. The server cannot tell a person without
 *   JavaScript from a script that skips the check, so the route is built to
 *   be safe for BOTH: it never does anything that the mailbox's owner has not
 *   confirmed, and it can never send more than a few emails.
 *
 * THE LIMITS (noScriptRouteGate(), all four forms together, admin-editable):
 *   - per visitor address:  no_script_route_ip_per_hour   (default 5,  1-100)
 *   - per email address:    no_script_route_email_per_day (default 3,  1-20)
 *   - for the whole site:   no_script_route_site_per_hour (default 30, 1-1000)
 *   Counted in tblNoScriptRequests under one database lock, so requests sent
 *   at the same moment are counted exactly. Only requests that are let
 *   through are recorded, so the table can never hold more than the site-wide
 *   limit per hour. The email address is kept only as a keyed hash (HMAC with
 *   a key made from ENCRYPTION_KEY), never as text. The existing limits of
 *   each form (for example one reset link a minute per account) still apply.
 *
 * WHAT EACH FORM DOES ON THIS ROUTE:
 *   - sign up, forgot password, new link: the form's normal work, whose own
 *     email IS the confirmation (a waiting account becomes usable only from
 *     its verification link, which asks for the password - decision 0013; a
 *     password changes only from its reset link). The answer is the same
 *     generic one whether or not an account exists and whether or not a
 *     limit stopped the email, and takes at least the same minimum time.
 *   - sign in: see noScriptSignInStart(). The limits first, then the correct
 *     password (with the normal wrong-password counting and lock-out), and
 *     only then an email with a single-use link (stored only as a hash,
 *     15 minutes). Opening the link changes nothing (mail scanners open
 *     links); a button press on that page does. The link is bound to the
 *     browser that asked (a secret in its session, stored only as a hash):
 *       * pressed in THAT browser: it is signed in;
 *       * pressed in ANOTHER browser: the page says plainly that this browser
 *         will NOT be signed in, and the press only approves the first
 *         browser, which then presses "Continue".
 *     The link also carries the account's "sessions valid from" mark read
 *     with the password, so a password reset, a password change or "sign out
 *     everywhere" in between makes it stop working (the same rule as every
 *     other sign-in, decision 0013 and review finding 1).
 *
 * 📦 LOADING: bootstrap.php loads it after fn_human_check.php. Loading
 *    defines constants and functions only.
 *
 * @package     CueRCode
 * @subpackage  Security
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-27 (#163, decision 0038)
 * @see         docs/decisions/0038-no-javascript-visitors-get-an-email-confirmed-fallback.md
 * @see         _functions/fn_human_check.php - humanCheckWidgetHtml(), humanCheckEmailRouteChosen()
 * @see         public_html/login/confirm/index.php - the page the email links to
 */

// ==============================================================================
// 🚫 Direct access protection
// ==============================================================================
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $noScriptRouteScriptFilename = $_SERVER['SCRIPT_FILENAME'];
} else {
    $noScriptRouteScriptFilename = '';
}
if (basename($noScriptRouteScriptFilename) === basename(__FILE__)) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied.');
}

// 📏 cuercodeClampInt() (never a global clamp(), decision 0006).
if (!function_exists('cuercodeClampInt')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_compat.php';
}

// 🔐 The sign-in, link and timing helpers, and CUERCODE_NO_SCRIPT_SIGN_IN_TTL_MINUTES
//    (kept with the other link lifetimes), live in fn_auth.php. Pure on loading.
if (!function_exists('loginUserCheckPassword')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_auth.php';
}

// ==============================================================================
// 🔧 Constants
// ==============================================================================

/** @var int Shortest time (microseconds) a no-JavaScript request takes to answer, so timing shows nothing. */
if (!defined('CUERCODE_NO_SCRIPT_MIN_RESPONSE_MICROSECONDS')) {
    define('CUERCODE_NO_SCRIPT_MIN_RESPONSE_MICROSECONDS', 400000);
}

/** @var string Where the browser's sign-in secret is kept in the PHP session. */
if (!defined('CUERCODE_NO_SCRIPT_SESSION_KEY')) {
    define('CUERCODE_NO_SCRIPT_SESSION_KEY', 'cuercode_email_sign_in');
}

/** @var int Seconds to wait for the route's counting lock. */
if (!defined('CUERCODE_NO_SCRIPT_LOCK_SECONDS')) {
    define('CUERCODE_NO_SCRIPT_LOCK_SECONDS', 3);
}

/** @var string Shown under the generic answer whenever the route was used (identical in every case). */
if (!defined('CUERCODE_NO_SCRIPT_ROUTE_NOTE')) {
    define(
        'CUERCODE_NO_SCRIPT_ROUTE_NOTE',
        'You used the way that works without JavaScript. It is limited to a few emails an hour, so if nothing arrives within a few minutes, please try again later or switch JavaScript on.'
    );
}

/** @var string Shown when the email sign-in limits stop a request (says nothing about any account). */
if (!defined('CUERCODE_NO_SCRIPT_LIMIT_MESSAGE')) {
    define(
        'CUERCODE_NO_SCRIPT_LIMIT_MESSAGE',
        'Too many sign-in emails have been asked for just now, from your connection or for this address. Please try again later, or switch JavaScript on and use the normal form.'
    );
}

// ==============================================================================
// ⚙️ Limits (PURE)
// ==============================================================================

/**
 * 📏 The ONE place that says how big each route limit may be, and what it is
 *    when nothing is saved. The admin settings editor reads it too. PURE.
 *
 * @return array<string, array{min: int, max: int, default: int}>
 */
function noScriptRouteBounds(): array
{
    return [
        'ip_per_hour'   => ['min' => 1, 'max' => 100,  'default' => 5],
        'email_per_day' => ['min' => 1, 'max' => 20,   'default' => 3],
        'site_per_hour' => ['min' => 1, 'max' => 1000, 'default' => 30],
    ];
}

/**
 * ⚙️ Read the three route limits (settings no_script_route_*), each kept in
 *    its safe range; a missing, non-numeric or failing value means the default.
 *
 * @param callable|null $getSetting (string $key, mixed $default): mixed; getConfig when null
 * @return array{ipPerHour: int, emailPerDay: int, sitePerHour: int}
 */
function noScriptRouteSettings(?callable $getSetting = null): array
{
    if ($getSetting === null) {
        if (function_exists('getConfig')) {
            $getSetting = 'getConfig';
        } else {
            $getSetting = static function (string $key, mixed $default): mixed {
                return $default;
            };
        }
    }

    $values = [];
    foreach (noScriptRouteBounds() as $suffix => $bound) {
        try {
            $raw = $getSetting('no_script_route_' . $suffix, $bound['default']);
        } catch (\Throwable $e) {
            $raw = $bound['default'];
        }

        if (is_int($raw) || (is_string($raw) && preg_match('/^\d{1,9}$/', $raw) === 1)) {
            $number = (int) $raw;
        } else {
            $number = $bound['default'];
        }

        $values[$suffix] = cuercodeClampInt($number, $bound['min'], $bound['max']);
    }

    return [
        'ipPerHour'   => $values['ip_per_hour'],
        'emailPerDay' => $values['email_per_day'],
        'sitePerHour' => $values['site_per_hour'],
    ];
}

/**
 * 🧠 May this request go ahead? PURE. The counts are the requests ALREADY
 *    let through (not counting this one).
 *
 * @param array{ip: int, email: int, site: int} $counts
 * @param array{ipPerHour: int, emailPerDay: int, sitePerHour: int} $limits
 * @return array{allowed: bool, reason: string} reason: ok | ip | email | site
 */
function noScriptRouteDecide(array $counts, array $limits): array
{
    if ((int) $counts['ip'] >= (int) $limits['ipPerHour']) {
        return ['allowed' => false, 'reason' => 'ip'];
    }
    if ((int) $counts['email'] >= (int) $limits['emailPerDay']) {
        return ['allowed' => false, 'reason' => 'email'];
    }
    if ((int) $counts['site'] >= (int) $limits['sitePerHour']) {
        return ['allowed' => false, 'reason' => 'site'];
    }

    return ['allowed' => true, 'reason' => 'ok'];
}

/**
 * 🔑 The keyed hash an email address is counted under. Capitals and spaces
 *    do not matter. PURE when $key is given.
 *
 * @param string $email
 * @param string $key   '' = the site's own key (made from ENCRYPTION_KEY)
 * @return string 64 hex characters
 */
function noScriptEmailHash(string $email, #[\SensitiveParameter] string $key = ''): string
{
    if ($key === '') {
        $key = noScriptHashKey();
    }

    return hash_hmac('sha256', mb_strtolower(trim($email)), $key);
}

/**
 * 🔑 The site's key for noScriptEmailHash(): derived from ENCRYPTION_KEY, so
 *    the stored hashes cannot be matched against a list of addresses by
 *    anyone who only has the database.
 *
 * @return string
 */
function noScriptHashKey(): string
{
    if (defined('ENCRYPTION_KEY') && is_string(ENCRYPTION_KEY) && ENCRYPTION_KEY !== '') {
        return hash_hmac('sha256', 'cuercode-no-script-route', ENCRYPTION_KEY);
    }

    return 'cuercode-no-script-route-without-key';
}

// ==============================================================================
// 🚦 The gate (database)
// ==============================================================================

/**
 * 🚦 Count, decide and (when allowed) record ONE use of the route, inside a
 *    short database lock so requests at the same moment are counted exactly.
 *    Every problem (no database, the lock busy, an error) answers "not
 *    allowed": the route fails closed.
 *
 * @param string $purpose login | register | forgot | resend
 * @param string $email   The address typed into the form
 * @param string $ip      The visitor's address (getClientIP())
 * @return array{allowed: bool, reason: string} reason: ok | ip | email | site | busy | error
 */
function noScriptRouteGate(string $purpose, string $email, string $ip): array
{
    if (!function_exists('dbQuery')) {
        return ['allowed' => false, 'reason' => 'error'];
    }

    $limits    = noScriptRouteSettings();
    $emailHash = noScriptEmailHash($email);
    $ipText    = mb_substr($ip, 0, 45);

    $databaseName = '';
    if (defined('DB_NAME')) {
        $databaseName = (string) DB_NAME;
    }
    $lockName = 'cuercode_ns:' . substr(hash('sha256', $databaseName . '|no-script-route'), 0, 40);

    try {
        $lockStmt = dbQuery('SELECT GET_LOCK(?, ?) AS `lockResult`', 'si', [$lockName, CUERCODE_NO_SCRIPT_LOCK_SECONDS]);
        $lockRow  = $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();
    } catch (\Throwable $e) {
        return ['allowed' => false, 'reason' => 'error'];
    }

    if (!is_array($lockRow) || !isset($lockRow['lockResult']) || (int) $lockRow['lockResult'] !== 1) {
        return ['allowed' => false, 'reason' => 'busy'];
    }

    $decision = ['allowed' => false, 'reason' => 'error'];

    try {
        $countStmt = dbQuery(
            'SELECT '
            . '(SELECT COUNT(*) FROM `tblNoScriptRequests` WHERE `requestIP` = ? AND `createdAt` >= DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS `ipCount`, '
            . '(SELECT COUNT(*) FROM `tblNoScriptRequests` WHERE `emailHash` = ? AND `createdAt` >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS `emailCount`, '
            . '(SELECT COUNT(*) FROM `tblNoScriptRequests` WHERE `createdAt` >= DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS `siteCount`',
            'ss',
            [$ipText, $emailHash]
        );
        $counts = $countStmt->get_result()->fetch_assoc();
        $countStmt->close();

        $decision = noScriptRouteDecide([
            'ip'    => (int) $counts['ipCount'],
            'email' => (int) $counts['emailCount'],
            'site'  => (int) $counts['siteCount'],
        ], $limits);

        if ($decision['allowed']) {
            $insertStmt = dbQuery(
                'INSERT INTO `tblNoScriptRequests` (`purpose`, `emailHash`, `requestIP`) VALUES (?, ?, ?)',
                'sss',
                [mb_substr($purpose, 0, 16), $emailHash, $ipText]
            );
            $insertStmt->close();
        }

        // 🧹 Rows older than two days count for nothing; tidy a few each time.
        $tidyStmt = dbQuery('DELETE FROM `tblNoScriptRequests` WHERE `createdAt` < DATE_SUB(NOW(), INTERVAL 2 DAY) LIMIT 100', '', []);
        $tidyStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('warning', 'No-JavaScript route: the limits could not be checked, so the request was refused', get_class($e));
        }
        $decision = ['allowed' => false, 'reason' => 'error'];
    }

    try {
        $releaseStmt = dbQuery('SELECT RELEASE_LOCK(?) AS `releaseResult`', 's', [$lockName]);
        $releaseStmt->close();
    } catch (\Throwable $e) {
        // The lock belongs to this connection and ends with it anyway.
    }

    return $decision;
}

// ==============================================================================
// 🎫 Email sign-in: the decisions (PURE)
// ==============================================================================

/**
 * 🎫 What a button press on the emailed link's page does. PURE.
 *
 * @param array{status: string, secondsLeft: int}|null $row            The request (null = no such link)
 * @param bool                                         $bindingMatches This browser is the one that asked
 * @param bool                                         $accountValid   The account is still active, unlocked and unchanged
 * @return string sign_in_here | approve_other | invalid
 */
function noScriptSignInApproveDecision(?array $row, bool $bindingMatches, bool $accountValid): string
{
    if ($row === null || $row['status'] !== 'waiting' || (int) $row['secondsLeft'] <= 0 || !$accountValid) {
        return 'invalid';
    }

    if ($bindingMatches) {
        return 'sign_in_here';
    }

    return 'approve_other';
}

/**
 * 🎫 What "Continue" in the first browser does. PURE.
 *
 * @param array{status: string, secondsLeft: int}|null $row
 * @param bool                                         $accountValid
 * @return string sign_in | waiting | invalid
 */
function noScriptSignInContinueDecision(?array $row, bool $accountValid): string
{
    if ($row === null || (int) $row['secondsLeft'] <= 0 || !$accountValid) {
        return 'invalid';
    }
    if ($row['status'] === 'approved') {
        return 'sign_in';
    }
    if ($row['status'] === 'waiting') {
        return 'waiting';
    }

    return 'invalid';
}

// ==============================================================================
// 🎫 Email sign-in: the steps (database, session, email)
// ==============================================================================

/**
 * ✉️ Start an email sign-in for a visitor without JavaScript.
 *
 * Order (each step must pass before the next):
 *   1. the route's limits (per visitor address, per email, site-wide) - so
 *      this route can never be used to try passwords without limit;
 *   2. the password, by the SAME check as normal sign-in
 *      (loginUserCheckPassword(): wrong-password counting and lock-out);
 *   3. only then: a single-use link (hash stored, 15 minutes) bound to this
 *      browser, and the email.
 * The whole answer takes at least CUERCODE_NO_SCRIPT_MIN_RESPONSE_MICROSECONDS.
 *
 * @param string $email
 * @param string $password
 * @param string $ip
 * @param string $userAgent
 * @param string $nextUrl   A safe, same-site path (already checked by the page)
 * @return array{outcome: string, message: string} outcome: sent | invalid | limited | error
 */
function noScriptSignInStart(string $email, #[\SensitiveParameter] string $password, string $ip, string $userAgent, string $nextUrl): array
{
    $startedAtNanos = hrtime(true);
    $result         = noScriptSignInStartWork($email, $password, $ip, $userAgent, $nextUrl);

    if (function_exists('padResponseToMinimumDuration')) {
        padResponseToMinimumDuration($startedAtNanos, CUERCODE_NO_SCRIPT_MIN_RESPONSE_MICROSECONDS);
    }

    return $result;
}

/**
 * ✉️ The work of noScriptSignInStart() (without the minimum time).
 *
 * @param string $email
 * @param string $password
 * @param string $ip
 * @param string $userAgent
 * @param string $nextUrl
 * @return array{outcome: string, message: string}
 */
function noScriptSignInStartWork(string $email, #[\SensitiveParameter] string $password, string $ip, string $userAgent, string $nextUrl): array
{
    $invalid = ['outcome' => 'invalid', 'message' => 'Invalid email or password, or the account is not yet verified.'];
    if (function_exists('loginUserGenericFailure')) {
        $invalid['message'] = loginUserGenericFailure()['message'];
    }
    $error = ['outcome' => 'error', 'message' => 'Signing in by email is not available just now. Please try again in a few minutes.'];

    if (!function_exists('loginUserCheckPassword') || !function_exists('dbQuery')) {
        return $error;
    }

    // 1️⃣ The limits, before the password is even looked at.
    $gate = noScriptRouteGate('login', $email, $ip);
    if (!$gate['allowed']) {
        return ['outcome' => 'limited', 'message' => CUERCODE_NO_SCRIPT_LIMIT_MESSAGE];
    }

    // 2️⃣ The password, with every normal sign-in rule.
    $check = loginUserCheckPassword($email, $password);
    if (!$check['ok']) {
        if ((int) $check['result']['httpCode'] === 503) {
            return $error;
        }
        return $invalid;
    }

    $row    = $check['row'];
    $userID = (int) $row['userID'];
    loginUserAfterPassword($row, $password, false);

    // 3️⃣ Only now: the link, bound to this browser, and the email.
    $rawToken   = generateRandomToken();
    $rawBinding = generateRandomToken();

    try {
        $insertStmt = dbQuery(
            'INSERT INTO `tblEmailSignInRequests` (`userID`, `tokenHash`, `bindingHash`, `sessionsValidFromSeen`, `requestIP`, `userAgent`, `status`, `expiresAt`) '
            . 'VALUES (?, ?, ?, ?, ?, ?, \'waiting\', DATE_ADD(NOW(), INTERVAL ? MINUTE))',
            'isssssi',
            [$userID, hashToken($rawToken), hashToken($rawBinding), $check['sessionsValidFromSeen'], mb_substr($ip, 0, 45), mb_substr($userAgent, 0, 500), CUERCODE_NO_SCRIPT_SIGN_IN_TTL_MINUTES]
        );
        $insertStmt->close();

        // 🧹 Finished or expired requests older than a day are of no use.
        $tidyStmt = dbQuery('DELETE FROM `tblEmailSignInRequests` WHERE `expiresAt` < DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 100', '', []);
        $tidyStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Email sign-in request could not be saved', get_class($e));
        }
        return $error;
    }

    if (function_exists('queueEmail')) {
        $displayName = null;
        if (isset($row['displayName']) && is_string($row['displayName']) && $row['displayName'] !== '') {
            $displayName = $row['displayName'];
        }
        queueEmail(
            (string) $row['email'],
            'email_sign_in',
            [
                'recipientName' => $displayName,
                'confirmUrl'    => buildAccountActionUrl('login/confirm/', $rawToken),
                'expiryMinutes' => CUERCODE_NO_SCRIPT_SIGN_IN_TTL_MINUTES,
            ],
            null,
            $displayName
        );
    }

    noScriptSessionStart();
    $_SESSION[CUERCODE_NO_SCRIPT_SESSION_KEY] = [
        'binding'   => $rawBinding,
        'next'      => $nextUrl,
        'startedAt' => time(),
    ];

    return ['outcome' => 'sent', 'message' => 'We have emailed you a link. Open it and press the button on that page to finish signing in. The link works once, for ' . CUERCODE_NO_SCRIPT_SIGN_IN_TTL_MINUTES . ' minutes.'];
}

/**
 * 🧭 Make sure the PHP session is open (it holds this browser's secret).
 *
 * @return void
 */
function noScriptSessionStart(): void
{
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }
}

/**
 * 🧭 This browser's waiting email sign-in, or null (none, or too old).
 *
 * @return array{binding: string, next: string, startedAt: int}|null
 */
function noScriptSignInPending(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION[CUERCODE_NO_SCRIPT_SESSION_KEY]) || !is_array($_SESSION[CUERCODE_NO_SCRIPT_SESSION_KEY])) {
        return null;
    }

    $pending = $_SESSION[CUERCODE_NO_SCRIPT_SESSION_KEY];
    if (!isset($pending['binding'], $pending['next'], $pending['startedAt']) || !is_string($pending['binding']) || !is_string($pending['next'])) {
        return null;
    }
    if ((int) $pending['startedAt'] < time() - (CUERCODE_NO_SCRIPT_SIGN_IN_TTL_MINUTES * 60)) {
        return null;
    }

    return ['binding' => $pending['binding'], 'next' => $pending['next'], 'startedAt' => (int) $pending['startedAt']];
}

/**
 * 🧹 Forget this browser's waiting email sign-in.
 *
 * @return void
 */
function noScriptSignInForget(): void
{
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION[CUERCODE_NO_SCRIPT_SESSION_KEY])) {
        unset($_SESSION[CUERCODE_NO_SCRIPT_SESSION_KEY]);
    }
}

/**
 * 🔍 Load one request with what the decisions need, optionally locking it.
 *    $column is chosen by this file only (never visitor input).
 *
 * @param string $column 'tokenHash' or 'bindingHash'
 * @param string $hash
 * @param bool   $forUpdate
 * @return array<string, mixed>|null
 * @throws \Throwable On a database error
 */
function noScriptSignInLoad(string $column, string $hash, bool $forUpdate): ?array
{
    if ($column !== 'tokenHash' && $column !== 'bindingHash') {
        throw new \InvalidArgumentException('not a request column');
    }

    $lock = '';
    if ($forUpdate) {
        $lock = ' FOR UPDATE';
    }

    $stmt = dbQuery(
        'SELECT `r`.`requestID`, `r`.`userID`, `r`.`status`, `r`.`bindingHash`, `r`.`sessionsValidFromSeen`, `r`.`createdAt`, '
        . 'TIMESTAMPDIFF(SECOND, NOW(), `r`.`expiresAt`) AS `secondsLeft`, '
        . '(`u`.`status` = \'active\' AND (`u`.`lockedUntil` IS NULL OR `u`.`lockedUntil` <= NOW()) '
        . 'AND (`u`.`sessionsValidFrom` <=> `r`.`sessionsValidFromSeen`)) AS `accountValid` '
        . 'FROM `tblEmailSignInRequests` AS `r` INNER JOIN `tblUsers` AS `u` ON `u`.`userID` = `r`.`userID` '
        . 'WHERE `r`.`' . $column . '` = ? ORDER BY `r`.`requestID` DESC LIMIT 1' . $lock,
        's',
        [$hash]
    );
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!is_array($row)) {
        return null;
    }

    $row['secondsLeft']  = (int) $row['secondsLeft'];
    $row['accountValid'] = ((int) $row['accountValid'] === 1);

    return $row;
}

/**
 * 🔍 What the emailed link's page shows on OPENING (nothing is changed).
 *
 * @param string $rawToken From the link
 * @return array{state: string, bindingMatches: bool} state: usable | invalid
 */
function noScriptSignInPreview(string $rawToken): array
{
    $invalid = ['state' => 'invalid', 'bindingMatches' => false];

    if ($rawToken === '' || mb_strlen($rawToken) > 128 || !function_exists('dbQuery')) {
        return $invalid;
    }

    try {
        $row = noScriptSignInLoad('tokenHash', hashToken($rawToken), false);
    } catch (\Throwable $e) {
        return $invalid;
    }

    $bindingMatches = noScriptBindingMatches($row);
    if (noScriptSignInApproveDecision($row, $bindingMatches, is_array($row) && $row['accountValid']) === 'invalid') {
        return $invalid;
    }

    return ['state' => 'usable', 'bindingMatches' => $bindingMatches];
}

/**
 * 🔐 Is THIS browser the one that asked for the request? (hash_equals on the
 *    hash of the secret in this browser's session.)
 *
 * @param array<string, mixed>|null $row
 * @return bool
 */
function noScriptBindingMatches(?array $row): bool
{
    if (!is_array($row)) {
        return false;
    }

    $pending = noScriptSignInPending();
    if ($pending === null) {
        return false;
    }

    return hash_equals((string) $row['bindingHash'], hashToken($pending['binding']));
}

/**
 * ✅ The button on the emailed link's page was pressed (POST, security token
 *    checked by the page).
 *
 * @param string $rawToken
 * @param string $ip
 * @param string $userAgent
 * @return array{outcome: string, next: string} outcome: signed_in | approved | invalid
 */
function noScriptSignInApprove(string $rawToken, string $ip, string $userAgent): array
{
    $invalid = ['outcome' => 'invalid', 'next' => ''];

    if ($rawToken === '' || mb_strlen($rawToken) > 128 || !function_exists('dbQuery') || !function_exists('getDB')) {
        return $invalid;
    }

    $database = getDB();

    try {
        $database->begin_transaction();
        $row            = noScriptSignInLoad('tokenHash', hashToken($rawToken), true);
        $bindingMatches = noScriptBindingMatches($row);
        $decision       = noScriptSignInApproveDecision($row, $bindingMatches, is_array($row) && $row['accountValid']);

        if ($decision === 'invalid') {
            $database->rollback();
            return $invalid;
        }

        if ($decision === 'approve_other') {
            $stmt = dbQuery('UPDATE `tblEmailSignInRequests` SET `status` = \'approved\', `approvedAt` = NOW() WHERE `requestID` = ? AND `status` = \'waiting\'', 'i', [(int) $row['requestID']]);
            $changed = (int) $stmt->affected_rows;
            $stmt->close();
            if ($changed !== 1) {
                $database->rollback();
                return $invalid;
            }
            $database->commit();
            return ['outcome' => 'approved', 'next' => ''];
        }

        // ✅ The same browser: sign it in now.
        $result = noScriptSignInFinish($row, $ip, $userAgent);
        if ($result === false) {
            $database->rollback();
            return $invalid;
        }
        $database->commit();
    } catch (\Throwable $e) {
        noScriptRollbackQuietly($database);
        if (function_exists('logError')) {
            logError('warning', 'Email sign-in approval failed', get_class($e));
        }
        return $invalid;
    }

    $next = '/account/';
    $pending = noScriptSignInPending();
    if ($pending !== null) {
        $next = $pending['next'];
    }
    noScriptSignInForget();

    return ['outcome' => 'signed_in', 'next' => $next];
}

/**
 * ▶️ "Continue" in the first browser, after the link was approved elsewhere.
 *
 * @param string $ip
 * @param string $userAgent
 * @return array{outcome: string, next: string} outcome: signed_in | waiting | invalid
 */
function noScriptSignInContinue(string $ip, string $userAgent): array
{
    $invalid = ['outcome' => 'invalid', 'next' => ''];
    $pending = noScriptSignInPending();

    if ($pending === null || !function_exists('dbQuery') || !function_exists('getDB')) {
        noScriptSignInForget();
        return $invalid;
    }

    $database = getDB();

    try {
        $database->begin_transaction();
        $row      = noScriptSignInLoad('bindingHash', hashToken($pending['binding']), true);
        $decision = noScriptSignInContinueDecision($row, is_array($row) && $row['accountValid']);

        if ($decision === 'waiting') {
            $database->commit();
            return ['outcome' => 'waiting', 'next' => ''];
        }
        if ($decision !== 'sign_in' || noScriptSignInFinish($row, $ip, $userAgent) === false) {
            $database->rollback();
            noScriptSignInForget();
            return $invalid;
        }
        $database->commit();
    } catch (\Throwable $e) {
        noScriptRollbackQuietly($database);
        if (function_exists('logError')) {
            logError('warning', 'Email sign-in continue failed', get_class($e));
        }
        return $invalid;
    }

    noScriptSignInForget();

    return ['outcome' => 'signed_in', 'next' => $pending['next']];
}

/**
 * 🎫 Sign THIS browser in for the request and mark it used (inside the
 *    caller's transaction). The session is made only while the account's
 *    "sessions valid from" mark is still the one read with the password.
 *
 * @param array<string, mixed> $row
 * @param string               $ip
 * @param string               $userAgent
 * @return bool
 */
function noScriptSignInFinish(array $row, string $ip, string $userAgent): bool
{
    $stmt = dbQuery('UPDATE `tblEmailSignInRequests` SET `status` = \'used\', `usedAt` = NOW() WHERE `requestID` = ? AND `status` IN (\'waiting\', \'approved\')', 'i', [(int) $row['requestID']]);
    $changed = (int) $stmt->affected_rows;
    $stmt->close();
    if ($changed !== 1) {
        return false;
    }

    $seen = null;
    if ($row['sessionsValidFromSeen'] !== null) {
        $seen = (string) $row['sessionsValidFromSeen'];
    }

    $issuedSession = issueUserSession((int) $row['userID'], $ip, $userAgent, $seen);
    if ($issuedSession === '') {
        return false;
    }

    $lastStmt = dbQuery('UPDATE `tblUsers` SET `lastLoginAt` = NOW() WHERE `userID` = ?', 'i', [(int) $row['userID']]);
    $lastStmt->close();

    return true;
}

/**
 * ↩️ Roll back without letting a second error escape.
 *
 * @param \mysqli $database
 * @return void
 */
function noScriptRollbackQuietly(\mysqli $database): void
{
    try {
        $database->rollback();
    } catch (\Throwable $e) {
        // Nothing more can be done; the connection ends with the request.
    }
}
