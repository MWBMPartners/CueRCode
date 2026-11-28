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
 *   form's <noscript> part (and, while the saved keys cannot be read, for
 *   everyone - review L4). The server cannot tell a person without
 *   JavaScript from a script that skips the check, so the route is built to
 *   be safe for BOTH: it never does anything that the mailbox's owner has not
 *   confirmed, it can never send more than a few emails, and it never tells
 *   a script whether a password was right.
 *
 * THE LIMITS (admin-editable; merged review L1, L2, L3, I5):
 *   - per visitor address:  no_script_route_ip_per_hour   (default 5,  1-100)
 *       every use of the route, all four forms together, checked FIRST and
 *       before any password is looked at. An IPv6 address counts as its
 *       whole /64 network (one connection usually has a whole /64).
 *   - per mailbox:          no_script_route_email_per_day (default 3,  1-20)
 *       EMAILS the route sent to one mailbox in 24 hours, whoever asked.
 *       "+tag" spellings, and dots and googlemail.com for Gmail, count as the
 *       same mailbox (only for counting; the address typed is the one stored
 *       and emailed).
 *   - per form, whole site: no_script_route_site_per_hour (default 30, 1-1000)
 *       EMAILS each of the four forms sent through the route in an hour, so
 *       a flood on one form (for example sign-ups for made-up addresses)
 *       cannot use up another's (for example the real person's sign-in).
 *   Only an email that was really queued counts against the last two: a
 *   forgot-password request for an address with no account, or a wrong
 *   password, uses up nothing (review L1, L2). Counted in
 *   tblNoScriptRequests under one database lock, so requests sent at the
 *   same moment are counted exactly. The email address is kept only as a
 *   keyed hash (HMAC with a key made from ENCRYPTION_KEY), never as text.
 *   The existing limits of each form (for example one reset link a minute
 *   per account, and the wrong-password lock-out) still apply.
 *
 * WHAT EACH FORM DOES ON THIS ROUTE:
 *   - sign up, forgot password, new link (noScriptRouteRunForm()): the form's
 *     normal work, whose own email IS the confirmation (a waiting account
 *     becomes usable only from its verification link, which asks for the
 *     password - decision 0013; a password changes only from its reset
 *     link). The answer is the same generic one whether or not an account
 *     exists and whether or not a limit stopped the email, and takes at
 *     least the same minimum time.
 *   - sign in: see noScriptSignInStart(). The per-address limit first, then
 *     the password (with the normal wrong-password counting and lock-out),
 *     then the email limits, and only for a right password an email with a
 *     single-use link (stored only as a hash, 15 minutes). The answer, the
 *     page drawn afterwards, Continue and Start again are THE SAME for a
 *     right password, a wrong one, an unknown, locked or waiting account
 *     (merged review M1): the browser always gets a secret in its session,
 *     a stand-in one when no link was made. Opening the link changes
 *     nothing (mail scanners open links); a button press on that page does.
 *     The link is bound to the browser that asked (a secret in its session,
 *     stored only as a hash):
 *       * pressed in THAT browser: it is signed in;
 *       * pressed in ANOTHER browser: the page says plainly that this browser
 *         will NOT be signed in, shows when, from what browser and from what
 *         address the sign-in was asked for, and the press only approves the
 *         first browser, which then has 3 minutes to press "Continue"
 *         (review L6).
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
 * @version     1.1.0
 * @since       2026-11-27 (#163, decision 0038; merged review fixes 2026-11-28)
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

// 🔧 humanCheckOption() (stand-ins for tests) lives in fn_human_check.php. Pure on loading.
if (!function_exists('humanCheckOption')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_human_check.php';
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

/** @var int Seconds an approval made in another browser lasts before Continue must be pressed (review L6). */
if (!defined('CUERCODE_NO_SCRIPT_APPROVAL_SECONDS')) {
    define('CUERCODE_NO_SCRIPT_APPROVAL_SECONDS', 180);
}

/** @var string Shown under the generic answer whenever the route was used (identical in every case). */
if (!defined('CUERCODE_NO_SCRIPT_ROUTE_NOTE')) {
    define(
        'CUERCODE_NO_SCRIPT_ROUTE_NOTE',
        'You used the way that works without JavaScript. It is limited to a few emails an hour, so if nothing arrives within a few minutes, please try again later or switch JavaScript on.'
    );
}

/** @var string The ONE answer to an email sign-in, whatever the password (review M1). */
if (!defined('CUERCODE_NO_SCRIPT_SIGN_IN_SENT_MESSAGE')) {
    define(
        'CUERCODE_NO_SCRIPT_SIGN_IN_SENT_MESSAGE',
        'If the email address and password are right, we have emailed you a link. Open it and press the button on that page to finish signing in. The link works once, for ' . CUERCODE_NO_SCRIPT_SIGN_IN_TTL_MINUTES . ' minutes. If nothing arrives within a few minutes, check the address and password, or try again later.'
    );
}

/** @var string Continue pressed before (or after) a usable approval - the same for every case (review M1). */
if (!defined('CUERCODE_NO_SCRIPT_NOT_YET_MESSAGE')) {
    define(
        'CUERCODE_NO_SCRIPT_NOT_YET_MESSAGE',
        'You are not signed in yet. Open the link in the email we sent and press the button on that page, then press Continue here within 3 minutes. If no email has arrived, or the link has run out, press Start again.'
    );
}

/** @var string Shown when this visitor address has used the route too often (says nothing about any account). */
if (!defined('CUERCODE_NO_SCRIPT_LIMIT_MESSAGE')) {
    define(
        'CUERCODE_NO_SCRIPT_LIMIT_MESSAGE',
        'Too many sign-in emails have been asked for from your connection in the last hour. Please try again later, or switch JavaScript on and use the normal form.'
    );
}

/** @var string Shown when the route's counting lock could not be had (review I2). */
if (!defined('CUERCODE_NO_SCRIPT_BUSY_MESSAGE')) {
    define(
        'CUERCODE_NO_SCRIPT_BUSY_MESSAGE',
        'Signing in by email is busy just now. Please try again in a minute, or switch JavaScript on and use the normal form.'
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
 * 🧠 May this go ahead? PURE. The counts are what was ALREADY recorded (not
 *    counting this request): 'ip' = uses of the route from this visitor
 *    address in the last hour; 'email' = emails the route sent to this
 *    mailbox in 24 hours; 'site' = emails this form sent through the route in
 *    the last hour. The gate asks about 'ip' for every request, and about
 *    'email' and 'site' only for a request that wants to send an email.
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
 * 📮 The form a mailbox is COUNTED under (merged review L3). PURE.
 *
 * Lower case and no spaces; everything from the first "+" in the part before
 * the "@" is dropped (almost every big provider delivers "name+tag" to
 * "name"); for Gmail, dots in that part are dropped and googlemail.com is
 * gmail.com. So target+1@... to target+30@... are ONE mailbox and share one
 * limit. This is used ONLY for counting: the address typed is the one stored
 * and the one emailed, unchanged.
 *
 * @param string $email
 * @return string
 */
function noScriptEmailCountingForm(string $email): string
{
    $address = mb_strtolower(trim($email));

    $atPosition = strrpos($address, '@');
    if ($atPosition === false) {
        return $address;
    }

    $localPart = substr($address, 0, $atPosition);
    $domain    = substr($address, $atPosition + 1);

    $plusPosition = strpos($localPart, '+');
    if ($plusPosition !== false) {
        $localPart = substr($localPart, 0, $plusPosition);
    }

    if ($domain === 'googlemail.com') {
        $domain = 'gmail.com';
    }
    if ($domain === 'gmail.com') {
        $localPart = str_replace('.', '', $localPart);
    }

    return $localPart . '@' . $domain;
}

/**
 * 🔑 The keyed hash an email address is counted under (its counting form,
 *    noScriptEmailCountingForm()). PURE when $key is given.
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

    return hash_hmac('sha256', noScriptEmailCountingForm($email), $key);
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

/**
 * 🌍 The visitor address the route COUNTS under (merged review I5). PURE.
 *
 * An IPv6 address counts as its /64 network ("2001:db8:1:2::/64"): one home
 * or phone connection is normally given a whole /64, so counting single
 * IPv6 addresses would give one visitor billions of fresh allowances. An
 * IPv4 address written in IPv6 form ("::ffff:198.51.100.7") is that IPv4
 * address. Anything else is counted as it is (at most 45 characters).
 *
 * @param string $ip getClientIP()
 * @return string
 */
function noScriptAddressKey(string $ip): string
{
    $ip = trim($ip);

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        $packed = inet_pton($ip);
        if ($packed !== false && strlen($packed) === 16) {
            // 🔁 ::ffff:a.b.c.d is an IPv4 visitor.
            if (substr($packed, 0, 12) === str_repeat("\x00", 10) . "\xff\xff") {
                $ipv4 = inet_ntop(substr($packed, 12));
                if ($ipv4 !== false) {
                    return $ipv4;
                }
            }

            $network = inet_ntop(substr($packed, 0, 8) . str_repeat("\x00", 8));
            if ($network !== false) {
                return $network . '/64';
            }
        }
    }

    return mb_substr($ip, 0, 45);
}

/**
 * 🖥️ A browser identification in plain words, for the approve page (review
 *    L6): "Firefox on Windows", "Safari on iPhone". Only common browsers and
 *    systems are named; anything else is "an unknown browser". PURE.
 *
 * @param string $userAgent The browser identification the asking browser sent
 * @return string
 */
function noScriptDescribeBrowser(string $userAgent): string
{
    // 📌 Order matters: Edge and Opera also say "Chrome", and Chrome also
    //    says "Safari"; iPhones say "like Mac OS X" and Android says "Linux".
    $browsers = [
        'Edg/'            => 'Edge',
        'EdgiOS/'         => 'Edge',
        'EdgA/'           => 'Edge',
        'OPR/'            => 'Opera',
        'SamsungBrowser/' => 'Samsung Internet',
        'Firefox/'        => 'Firefox',
        'FxiOS/'          => 'Firefox',
        'CriOS/'          => 'Chrome',
        'Chrome/'         => 'Chrome',
        'Safari/'         => 'Safari',
    ];
    $systems = [
        'iPhone'    => 'iPhone',
        'iPad'      => 'iPad',
        'Android'   => 'Android',
        'CrOS'      => 'ChromeOS',
        'Windows'   => 'Windows',
        'Macintosh' => 'a Mac',
        'Linux'     => 'Linux',
    ];

    $browser = '';
    foreach ($browsers as $marker => $name) {
        if (str_contains($userAgent, $marker)) {
            $browser = $name;
            break;
        }
    }
    if ($browser === '') {
        return 'an unknown browser';
    }

    foreach ($systems as $marker => $name) {
        if (str_contains($userAgent, $marker)) {
            return $browser . ' on ' . $name;
        }
    }

    return $browser;
}

// ==============================================================================
// 🚦 The gate (database)
// ==============================================================================

/**
 * 🔒 The name of the route's database lock (one per database).
 *
 * @return string
 */
function noScriptRouteLockName(): string
{
    $databaseName = '';
    if (defined('DB_NAME')) {
        $databaseName = (string) DB_NAME;
    }

    return 'cuercode_ns:' . substr(hash('sha256', $databaseName . '|no-script-route'), 0, 40);
}

/**
 * 🔒 Run $work inside the route's lock, so requests at the same moment are
 *    counted exactly. The lock is always released.
 *
 * @param callable $work (): mixed - may throw (a database error)
 * @return array{state: string, value: mixed} state: done | busy | error
 */
function noScriptRouteWithLock(callable $work): array
{
    $lockName = noScriptRouteLockName();

    try {
        $lockStmt = dbQuery('SELECT GET_LOCK(?, ?) AS `lockResult`', 'si', [$lockName, CUERCODE_NO_SCRIPT_LOCK_SECONDS]);
        $lockRow  = $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();
    } catch (\Throwable $e) {
        return ['state' => 'error', 'value' => null];
    }

    if (!is_array($lockRow) || !isset($lockRow['lockResult']) || (int) $lockRow['lockResult'] !== 1) {
        return ['state' => 'busy', 'value' => null];
    }

    $outcome = ['state' => 'error', 'value' => null];
    try {
        $outcome = ['state' => 'done', 'value' => $work()];
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('warning', 'No-JavaScript route: the limits could not be checked, so the request was refused', get_class($e));
        }
    }

    try {
        $releaseStmt = dbQuery('SELECT RELEASE_LOCK(?) AS `releaseResult`', 's', [$lockName]);
        $releaseStmt->close();
    } catch (\Throwable $e) {
        // The lock belongs to this connection and ends with it anyway.
    }

    return $outcome;
}

/**
 * 🔢 Uses of the route from one visitor address (its counting key) in the
 *    last hour.
 *
 * @param string $addressKey noScriptAddressKey()
 * @return int
 * @throws \Throwable On a database error
 */
function noScriptRouteAddressCount(string $addressKey): int
{
    $stmt = dbQuery(
        'SELECT COUNT(*) AS `uses` FROM `tblNoScriptRequests` WHERE `requestIP` = ? AND `createdAt` >= DATE_SUB(NOW(), INTERVAL 1 HOUR)',
        's',
        [$addressKey]
    );
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) $row['uses'];
}

/**
 * 🔢 Emails the route sent to one mailbox in 24 hours, and emails this form
 *    sent through the route in the last hour.
 *
 * @param string $purpose   login | register | forgot | resend
 * @param string $emailHash noScriptEmailHash()
 * @return array{email: int, site: int}
 * @throws \Throwable On a database error
 */
function noScriptRouteEmailCounts(string $purpose, string $emailHash): array
{
    $stmt = dbQuery(
        'SELECT '
        . '(SELECT COUNT(*) FROM `tblNoScriptRequests` WHERE `emailHash` = ? AND `emailSent` = 1 AND `createdAt` >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS `emailCount`, '
        . '(SELECT COUNT(*) FROM `tblNoScriptRequests` WHERE `purpose` = ? AND `emailSent` = 1 AND `createdAt` >= DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS `siteCount`',
        'ss',
        [$emailHash, $purpose]
    );
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ['email' => (int) $row['emailCount'], 'site' => (int) $row['siteCount']];
}

/**
 * 🚦 Count, decide and record ONE use of the route, inside the route's lock.
 *    Every problem (no database, the lock busy, an error) answers "not
 *    allowed": the route fails closed.
 *
 *   1. The per-address limit, first without the lock (an address already
 *      over it is answered at once: review I3), then again inside it.
 *   2. With $reserveEmail: the per-mailbox and this form's site-wide email
 *      limits, and if both have room, one email place is RESERVED for this
 *      request (given back by noScriptRouteReleaseEmail() if no email goes).
 *   3. The use is recorded (it counts against the per-address limit).
 *
 * Sign-in asks with $reserveEmail false and reserves its email place only
 * after the password (noScriptRouteClaimEmail()), so a stranger guessing a
 * person's password uses up nothing of theirs (review L2).
 *
 * @param string $purpose      login | register | forgot | resend
 * @param string $email        The address typed into the form
 * @param string $ip           The visitor's address (getClientIP())
 * @param bool   $reserveEmail Also reserve an email place now
 * @return array{allowed: bool, reason: string, requestID: int, emailReserved: bool}
 *         allowed: the request may go ahead; emailReserved: it may send ONE email.
 *         reason: ok | ip | email | site | busy | error
 */
function noScriptRouteGate(string $purpose, string $email, string $ip, bool $reserveEmail = false): array
{
    $refused = ['allowed' => false, 'reason' => 'error', 'requestID' => 0, 'emailReserved' => false];

    if (!function_exists('dbQuery')) {
        return $refused;
    }

    $limits      = noScriptRouteSettings();
    $emailHash   = noScriptEmailHash($email);
    $addressKey  = noScriptAddressKey($ip);
    $purposeText = mb_substr($purpose, 0, 16);
    $overAddress = ['allowed' => false, 'reason' => 'ip', 'requestID' => 0, 'emailReserved' => false];

    // ⚡ Review I3: an address already over its limit is answered at once.
    try {
        if (noScriptRouteAddressCount($addressKey) >= $limits['ipPerHour']) {
            return $overAddress;
        }
    } catch (\Throwable $e) {
        return $refused;
    }

    $locked = noScriptRouteWithLock(static function () use ($limits, $emailHash, $addressKey, $purposeText, $reserveEmail, $overAddress): array {
        if (noScriptRouteAddressCount($addressKey) >= $limits['ipPerHour']) {
            return $overAddress;
        }

        $reason   = 'ok';
        $reserved = false;
        if ($reserveEmail) {
            $counts   = noScriptRouteEmailCounts($purposeText, $emailHash);
            $decision = noScriptRouteDecide(['ip' => 0, 'email' => $counts['email'], 'site' => $counts['site']], $limits);
            $reason   = $decision['reason'];
            $reserved = $decision['allowed'];
        }

        $emailSent = 0;
        if ($reserved) {
            $emailSent = 1;
        }

        $insertStmt = dbQuery(
            'INSERT INTO `tblNoScriptRequests` (`purpose`, `emailHash`, `requestIP`, `emailSent`) VALUES (?, ?, ?, ?)',
            'sssi',
            [$purposeText, $emailHash, $addressKey, $emailSent]
        );
        $requestID = (int) $insertStmt->insert_id;
        $insertStmt->close();

        // 🧹 Rows older than two days count for nothing; tidy a few each time.
        $tidyStmt = dbQuery('DELETE FROM `tblNoScriptRequests` WHERE `createdAt` < DATE_SUB(NOW(), INTERVAL 2 DAY) LIMIT 100', '', []);
        $tidyStmt->close();

        return ['allowed' => true, 'reason' => $reason, 'requestID' => $requestID, 'emailReserved' => $reserved];
    });

    if ($locked['state'] === 'busy') {
        return ['allowed' => false, 'reason' => 'busy', 'requestID' => 0, 'emailReserved' => false];
    }
    if ($locked['state'] !== 'done' || !is_array($locked['value'])) {
        return $refused;
    }

    return $locked['value'];
}

/**
 * ✉️ After the password: reserve the one email place for a sign-in (review
 *    L1, L2). The lock is taken and the counts read WHETHER OR NOT $wanted,
 *    so a right and a wrong password take the same path (review M1); only a
 *    wanted claim within both limits reserves a place.
 *
 * @param int    $requestID From noScriptRouteGate()
 * @param string $purpose
 * @param string $email
 * @param bool   $wanted    True only when the password was right
 * @return bool True when the place was reserved
 */
function noScriptRouteClaimEmail(int $requestID, string $purpose, string $email, bool $wanted): bool
{
    if ($requestID <= 0 || !function_exists('dbQuery')) {
        return false;
    }

    $limits      = noScriptRouteSettings();
    $emailHash   = noScriptEmailHash($email);
    $purposeText = mb_substr($purpose, 0, 16);

    $locked = noScriptRouteWithLock(static function () use ($limits, $emailHash, $purposeText, $requestID, $wanted): bool {
        $counts   = noScriptRouteEmailCounts($purposeText, $emailHash);
        $decision = noScriptRouteDecide(['ip' => 0, 'email' => $counts['email'], 'site' => $counts['site']], $limits);
        if (!$wanted || !$decision['allowed']) {
            return false;
        }

        $stmt = dbQuery('UPDATE `tblNoScriptRequests` SET `emailSent` = 1 WHERE `requestID` = ? AND `emailSent` = 0', 'i', [$requestID]);
        $changed = (int) $stmt->affected_rows;
        $stmt->close();

        return $changed === 1;
    });

    return $locked['state'] === 'done' && $locked['value'] === true;
}

/**
 * ↩️ Give an email place back: no email went (review L1).
 *
 * @param int $requestID
 * @return void
 */
function noScriptRouteReleaseEmail(int $requestID): void
{
    if ($requestID <= 0 || !function_exists('dbQuery')) {
        return;
    }

    try {
        $stmt = dbQuery('UPDATE `tblNoScriptRequests` SET `emailSent` = 0 WHERE `requestID` = ?', 'i', [$requestID]);
        $stmt->close();
    } catch (\Throwable $e) {
        // 🔒 The place stays used: the safe direction (fewer emails, never more).
        if (function_exists('logError')) {
            logError('warning', 'No-JavaScript route: an unused email place could not be given back', get_class($e));
        }
    }
}

/**
 * 📝 Run sign-up, forgot password or new link on the route (THE one place
 *    the three pages use, so no page can ignore the limits - review L5).
 *
 *   - Over the per-address limit: nothing is done and the answer comes at
 *     once (review I3; it depends only on the visitor's own address).
 *   - Otherwise the form's work runs ONLY when an email place was reserved
 *     (the per-mailbox and this form's site-wide limits have room); if the
 *     work then queued no email (no such account, already verified ...),
 *     the place is given back (review L1).
 *   - The answer takes at least CUERCODE_NO_SCRIPT_MIN_RESPONSE_MICROSECONDS.
 *
 * @param string               $purpose register | forgot | resend
 * @param string               $email   The address typed
 * @param string               $ip      getClientIP()
 * @param callable             $work    (): void - the form's normal work
 * @param array<string, mixed> $deps    Stand-ins for tests: 'gate', 'release', 'queuedCount', 'pad'
 * @return array{ran: bool, reason: string}
 */
function noScriptRouteRunForm(string $purpose, string $email, string $ip, callable $work, array $deps = []): array
{
    $startedAtNanos = hrtime(true);

    $gate    = humanCheckOption($deps, 'gate', 'noScriptRouteGate');
    $release = humanCheckOption($deps, 'release', 'noScriptRouteReleaseEmail');
    $queued  = humanCheckOption($deps, 'queuedCount', static function (): int {
        if (function_exists('emailQueuedCount')) {
            return emailQueuedCount();
        }
        return 0;
    });
    $pad = humanCheckOption($deps, 'pad', static function (int $startedAt): void {
        if (function_exists('padResponseToMinimumDuration')) {
            padResponseToMinimumDuration($startedAt, CUERCODE_NO_SCRIPT_MIN_RESPONSE_MICROSECONDS);
        }
    });

    $answer = $gate($purpose, $email, $ip, true);

    if ($answer['allowed'] !== true && $answer['reason'] === 'ip') {
        return ['ran' => false, 'reason' => 'ip'];
    }

    $ran = false;
    if ($answer['allowed'] === true && $answer['emailReserved'] === true) {
        $queuedBefore = $queued();
        $work();
        $ran = true;
        if ($queued() === $queuedBefore) {
            $release((int) $answer['requestID']);
        }
    }

    $pad($startedAtNanos);

    return ['ran' => $ran, 'reason' => (string) $answer['reason']];
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
 * 🎫 What "Continue" in the first browser does. PURE. An approval counts for
 *    CUERCODE_NO_SCRIPT_APPROVAL_SECONDS only (review L6); one with no known
 *    time counts as run out.
 *
 * @param array{status: string, secondsLeft: int, approvedSecondsAgo?: int|null}|null $row
 * @param bool                                                                     $accountValid
 * @return string sign_in | waiting | invalid
 */
function noScriptSignInContinueDecision(?array $row, bool $accountValid): string
{
    if ($row === null || (int) $row['secondsLeft'] <= 0 || !$accountValid) {
        return 'invalid';
    }
    if ($row['status'] === 'approved') {
        if (isset($row['approvedSecondsAgo']) && (int) $row['approvedSecondsAgo'] <= CUERCODE_NO_SCRIPT_APPROVAL_SECONDS) {
            return 'sign_in';
        }
        return 'invalid';
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
 * Order (review M1, L1, L2):
 *   1. the per-address limit and the lock - the only answers that differ
 *      ("too many from your connection", "busy"), given before the password
 *      is looked at, so they say nothing about the account;
 *   2. the password, by the SAME check as normal sign-in
 *      (loginUserCheckPassword(): a wrong password is counted toward the
 *      lock-out, so guessing stays bounded);
 *   3. the email limits (per mailbox, and sign-in's own site-wide limit),
 *      read the same way whatever the password;
 *   4. only for a right password within those limits: a single-use link
 *      (hash stored, 15 minutes) bound to this browser, and the email;
 *   5. ALWAYS: a secret in this browser's session (a stand-in when no link
 *      was made) and the one answer CUERCODE_NO_SCRIPT_SIGN_IN_SENT_MESSAGE,
 *      in at least CUERCODE_NO_SCRIPT_MIN_RESPONSE_MICROSECONDS.
 *
 * @param string $email
 * @param string $password
 * @param string $ip
 * @param string $userAgent
 * @param string $nextUrl   A safe, same-site path (already checked by the page)
 * @return array{outcome: string, message: string} outcome: sent | limited | busy
 */
function noScriptSignInStart(string $email, #[\SensitiveParameter] string $password, string $ip, string $userAgent, string $nextUrl): array
{
    $startedAtNanos = hrtime(true);
    $result         = noScriptSignInStartWork($email, $password, $ip, $userAgent, $nextUrl);

    // ⏱️ Only "sent" follows the password; "limited" and "busy" come before
    //    it and are answered at once (review I3).
    if ($result['outcome'] === 'sent' && function_exists('padResponseToMinimumDuration')) {
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
    $sent = ['outcome' => 'sent', 'message' => CUERCODE_NO_SCRIPT_SIGN_IN_SENT_MESSAGE];
    $busy = ['outcome' => 'busy', 'message' => CUERCODE_NO_SCRIPT_BUSY_MESSAGE];

    if (!function_exists('loginUserCheckPassword') || !function_exists('dbQuery')) {
        return $busy;
    }

    // 1️⃣ The per-address limit and the lock, before the password.
    $gate = noScriptRouteGate('login', $email, $ip, false);
    if (!$gate['allowed']) {
        if ($gate['reason'] === 'ip') {
            return ['outcome' => 'limited', 'message' => CUERCODE_NO_SCRIPT_LIMIT_MESSAGE];
        }
        return $busy;
    }

    // 2️⃣ The password, with every normal sign-in rule.
    $check      = loginUserCheckPassword($email, $password);
    $passwordOk = ($check['ok'] === true);

    // 3️⃣ The email limits: the same lock and counts whatever the password.
    $emailClaimed = noScriptRouteClaimEmail($gate['requestID'], 'login', $email, $passwordOk);

    // 4️⃣ Only for a right password within the limits: the link and the email.
    $rawBinding = generateRandomToken();
    if ($passwordOk) {
        loginUserAfterPassword($check['row'], $password, false);
        if ($emailClaimed && !noScriptSignInCreate($check, $rawBinding, $ip, $userAgent)) {
            noScriptRouteReleaseEmail($gate['requestID']);
        }
    }

    // 5️⃣ ALWAYS a secret in this browser's session, so the page now and
    //    every page after it look the same for every case (review M1).
    noScriptSessionStart();
    $_SESSION[CUERCODE_NO_SCRIPT_SESSION_KEY] = [
        'binding'   => $rawBinding,
        'next'      => $nextUrl,
        'startedAt' => time(),
    ];

    return $sent;
}

/**
 * 🎫 Save the sign-in request (only hashes) and queue its email. A failure
 *    is logged and answered like every other case (review M1).
 *
 * @param array  $check      loginUserCheckPassword()'s result (ok)
 * @param string $rawBinding The browser's secret
 * @param string $ip
 * @param string $userAgent
 * @return bool True when the email was queued
 */
function noScriptSignInCreate(array $check, #[\SensitiveParameter] string $rawBinding, string $ip, string $userAgent): bool
{
    $row      = $check['row'];
    $rawToken = generateRandomToken();

    try {
        $insertStmt = dbQuery(
            'INSERT INTO `tblEmailSignInRequests` (`userID`, `tokenHash`, `bindingHash`, `sessionsValidFromSeen`, `requestIP`, `userAgent`, `status`, `expiresAt`) '
            . 'VALUES (?, ?, ?, ?, ?, ?, \'waiting\', DATE_ADD(NOW(), INTERVAL ? MINUTE))',
            'isssssi',
            [(int) $row['userID'], hashToken($rawToken), hashToken($rawBinding), $check['sessionsValidFromSeen'], mb_substr($ip, 0, 45), mb_substr($userAgent, 0, 500), CUERCODE_NO_SCRIPT_SIGN_IN_TTL_MINUTES]
        );
        $insertStmt->close();

        // 🧹 Finished or expired requests older than a day are of no use.
        $tidyStmt = dbQuery('DELETE FROM `tblEmailSignInRequests` WHERE `expiresAt` < DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 100', '', []);
        $tidyStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Email sign-in request could not be saved', get_class($e));
        }
        return false;
    }

    if (!function_exists('queueEmail')) {
        return false;
    }

    $displayName = null;
    if (isset($row['displayName']) && is_string($row['displayName']) && $row['displayName'] !== '') {
        $displayName = $row['displayName'];
    }

    $queued = queueEmail(
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

    return $queued !== false;
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
 * ✖️ "Start again": mark this browser's request cancelled (if there is one -
 *    a stand-in secret has none) and forget it (review I1).
 *
 * @return void
 */
function noScriptSignInCancel(): void
{
    $pending = noScriptSignInPending();

    if ($pending !== null && function_exists('dbQuery')) {
        try {
            $stmt = dbQuery(
                'UPDATE `tblEmailSignInRequests` SET `status` = \'cancelled\' WHERE `bindingHash` = ? AND `status` IN (\'waiting\', \'approved\')',
                's',
                [hashToken($pending['binding'])]
            );
            $stmt->close();
        } catch (\Throwable $e) {
            if (function_exists('logError')) {
                logError('warning', 'Email sign-in could not be marked cancelled', get_class($e));
            }
        }
    }

    noScriptSignInForget();
}

/**
 * 🔍 Load one request with what the decisions and the approve page need,
 *    optionally locking it. $column is chosen by this file only (never
 *    visitor input).
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
        . '`r`.`requestIP`, `r`.`userAgent`, '
        . 'TIMESTAMPDIFF(SECOND, NOW(), `r`.`expiresAt`) AS `secondsLeft`, '
        . 'TIMESTAMPDIFF(SECOND, `r`.`createdAt`, NOW()) AS `askedSecondsAgo`, '
        . 'TIMESTAMPDIFF(SECOND, `r`.`approvedAt`, NOW()) AS `approvedSecondsAgo`, '
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

    $row['secondsLeft']     = (int) $row['secondsLeft'];
    $row['askedSecondsAgo'] = (int) $row['askedSecondsAgo'];
    $row['accountValid']    = ((int) $row['accountValid'] === 1);
    if ($row['approvedSecondsAgo'] !== null) {
        $row['approvedSecondsAgo'] = (int) $row['approvedSecondsAgo'];
    }

    return $row;
}

/**
 * 🔍 What the emailed link's page shows on OPENING (nothing is changed),
 *    including when, from what browser and from what address the sign-in was
 *    asked for (review L6).
 *
 * @param string $rawToken From the link
 * @return array{state: string, bindingMatches: bool, askedSecondsAgo: int, askedFrom: string, askedBrowser: string}
 *         state: usable | invalid
 */
function noScriptSignInPreview(string $rawToken): array
{
    $invalid = ['state' => 'invalid', 'bindingMatches' => false, 'askedSecondsAgo' => 0, 'askedFrom' => '', 'askedBrowser' => ''];

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

    return [
        'state'           => 'usable',
        'bindingMatches'  => $bindingMatches,
        'askedSecondsAgo' => max(0, (int) $row['askedSecondsAgo']),
        'askedFrom'       => (string) $row['requestIP'],
        'askedBrowser'    => noScriptDescribeBrowser((string) $row['userAgent']),
    ];
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
 * Review M1: everything that is not a sign-in - not approved yet, the
 * approval ran out, the link expired or the account changed, and a browser
 * that only holds a stand-in secret - is the SAME answer ("not_yet"), and the
 * browser's waiting sign-in is kept until it runs out or "Start again" is
 * pressed. Only a browser with no waiting sign-in at all gets "expired".
 *
 * @param string $ip
 * @param string $userAgent
 * @return array{outcome: string, next: string} outcome: signed_in | not_yet | expired
 */
function noScriptSignInContinue(string $ip, string $userAgent): array
{
    $notYet  = ['outcome' => 'not_yet', 'next' => ''];
    $pending = noScriptSignInPending();

    if ($pending === null) {
        noScriptSignInForget();
        return ['outcome' => 'expired', 'next' => ''];
    }
    if (!function_exists('dbQuery') || !function_exists('getDB')) {
        return $notYet;
    }

    $database = getDB();

    try {
        $database->begin_transaction();
        $row      = noScriptSignInLoad('bindingHash', hashToken($pending['binding']), true);
        $decision = noScriptSignInContinueDecision($row, is_array($row) && $row['accountValid']);

        if ($decision !== 'sign_in') {
            $database->commit();
            return $notYet;
        }
        if (noScriptSignInFinish($row, $ip, $userAgent) === false) {
            $database->rollback();
            return $notYet;
        }
        $database->commit();
    } catch (\Throwable $e) {
        noScriptRollbackQuietly($database);
        if (function_exists('logError')) {
            logError('warning', 'Email sign-in continue failed', get_class($e));
        }
        return $notYet;
    }

    noScriptSignInForget();

    return ['outcome' => 'signed_in', 'next' => $pending['next']];
}

/**
 * 🎫 Sign THIS browser in for the request and mark it used (inside the
 *    caller's transaction). Only a waiting or approved request can be used
 *    (its own guard, whatever the caller decided). The session is made only
 *    while the account's "sessions valid from" mark is still the one read
 *    with the password.
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
