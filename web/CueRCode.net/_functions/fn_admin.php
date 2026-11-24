<?php
/**
 * ==============================================================================
 * 🛡️ CueRCode - Global Admin access (task 7n Phase 1B)
 * ==============================================================================
 *
 * Path: _functions/fn_admin.php
 *
 * Everything that decides who may use the private admin area at /admin/, and
 * how a change made there is protected and recorded:
 *
 *   1. 🚪 The gate. requireGlobalAdminPage() is the FIRST call on every admin
 *      page. A visitor who is not signed in is sent to the sign-in page; a
 *      signed-in person who is not a Global Admin gets the ordinary "page not
 *      found" page (so the area does not advertise itself); a Global Admin
 *      gets through. The decision is read FRESH from the database on every
 *      request - nothing about admin status is kept in the PHP session - so
 *      removing someone's admin access takes effect on their very next click.
 *   2. 🔑 Password re-confirmation ("re-authentication"). Looking at admin
 *      pages needs only Global Admin status. Making ANY change also needs the
 *      password to have been typed again recently: within the idle limit
 *      (default 15 minutes since the last change) and the absolute limit
 *      (default 60 minutes since the password was typed). The confirmation is
 *      tied to the user AND to the database session it was made in, so a copy
 *      of the session cookie used with a different sign-in does not carry it.
 *   3. 🧾 The audit trail. Every admin change writes one row to
 *      tblAdminAuditLog (who, when, what it was, what it became). Secret
 *      values are NEVER written - only "secret set / replaced / cleared".
 *   4. 🕰️ Date helpers. Times are stored in UTC and shown/typed in UK time
 *      (the admin_display_timezone setting), with the clock-change edge cases
 *      (a time that does not exist, or happens twice) refused in plain words.
 *   5. 💻 The command-line helpers behind _includes/cli/admin_access.php
 *      (grant / list / revoke), which is how the FIRST Global Admin is made.
 *
 * WHO IS A GLOBAL ADMIN (plan §6.1) - all of these, checked fresh each time:
 *   - tblUsers.roleID = 1 (CUERCODE_ROLE_GLOBAL_ADMIN, install.sql "1=Global
 *     Admin, 2=Admin, 3=Regular User"). roleID 2 gets NO admin access yet;
 *   - tblUsers.status = 'active' and tblUsers.emailVerifiedAt is set;
 *   - the database session found by currentUser() is valid (not revoked, not
 *     expired).
 *
 * 📦 LOADING: this file is NOT loaded by bootstrap.php. Admin pages and the
 *    command-line tool load it themselves. header.php and eraseUserAccount()
 *    load it only for an account that has SOME role set (rare - staff only),
 *    to ask adminRoleIsGlobalAdmin(), so ordinary visitors never load it.
 *    Loading it defines constants and functions only: no database call, no
 *    output, no session change happens on load (the tests rely on that).
 *
 * 🧪 TESTABLE BY DESIGN: the decisions are pure functions with no database
 *    and no globals (adminAccessDecision(), adminReauthIsFresh(),
 *    adminSafeNextPath(), adminAuditChanges(), adminLocalToUtc(), the CLI
 *    parser and grant/revoke decisions...). The database functions are thin
 *    wrappers round them. See tests/test_admin_access.php and
 *    tests/integration/admin_access_real_mysql.php.
 *
 * House rules kept here: full multi-line PHP (no ??, ternaries, arrow
 * functions or match), MySQLi prepared statements for every query (through
 * dbQuery()), back-quoted identifiers in every SQL string, and secret values
 * and passwords never printed, logged or audited.
 *
 * @package     CueRCode
 * @subpackage  Admin
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-24 (task 7n Phase 1B, #31)
 * @see         .claude/plans/7n-admin-commerce-plan.md §6 (access model), §7.4, §8.4
 * @see         _includes/cli/admin_access.php - grant / list / revoke from the command line
 * @see         public_html/admin/ - the admin pages (each calls requireGlobalAdminPage() first)
 * @see         _functions/fn_auth.php - currentUser(), recordFailedLogin(), logoutCurrentUser()
 * @see         _functions/fn_migrations.php - the database upgrade engine used by /admin/upgrades/
 * @see         https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html#reauthentication-after-risk-events
 */

// ==============================================================================
// 🚫 Direct access protection
// ==============================================================================
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $adminScriptFilename = $_SERVER['SCRIPT_FILENAME'];
} else {
    $adminScriptFilename = '';
}
if (basename($adminScriptFilename) === basename(__FILE__)) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied.');
}

// ==============================================================================
// 🔧 Constants
// ==============================================================================

/**
 * @var int tblUsers.roleID value of a Global Admin (install.sql, tblUsers:
 *          "1=Global Admin, 2=Admin, 3=Regular User"). The ONLY place this
 *          number is named; everything else asks adminRoleIsGlobalAdmin().
 */
if (!defined('CUERCODE_ROLE_GLOBAL_ADMIN')) {
    define('CUERCODE_ROLE_GLOBAL_ADMIN', 1);
}

/** @var string $_SESSION key holding the password re-confirmation state (plan §6.4). */
if (!defined('CUERCODE_ADMIN_REAUTH_SESSION_KEY')) {
    define('CUERCODE_ADMIN_REAUTH_SESSION_KEY', 'cuercode_admin_reauth');
}

/** @var string $_SESSION key counting wrong passwords on the confirm page in THIS session. */
if (!defined('CUERCODE_ADMIN_REAUTH_FAILURES_KEY')) {
    define('CUERCODE_ADMIN_REAUTH_FAILURES_KEY', 'cuercode_admin_reauth_failures');
}

/** @var int Wrong passwords in one session before the admin is signed out (plan §6.4). */
if (!defined('CUERCODE_ADMIN_REAUTH_MAX_FAILURES')) {
    define('CUERCODE_ADMIN_REAUTH_MAX_FAILURES', 5);
}

/** @var string $_SESSION key for one-time "flash" messages shown after a redirect. */
if (!defined('CUERCODE_ADMIN_FLASH_KEY')) {
    define('CUERCODE_ADMIN_FLASH_KEY', 'cuercode_admin_flash');
}

/** @var string $_SESSION key remembering that the "first seen" check already ran for a user. */
if (!defined('CUERCODE_ADMIN_FIRST_SEEN_KEY')) {
    define('CUERCODE_ADMIN_FIRST_SEEN_KEY', 'cuercode_admin_first_seen_checked');
}

/**
 * ⏱️ Re-confirmation limits, in minutes (plan §6.4). The settings
 *    admin_reauth_idle_minutes / admin_reauth_max_minutes (migration 0002)
 *    choose the values; these constants are the defaults and the allowed
 *    ranges, so a mistyped setting can never switch the protection off.
 */
if (!defined('CUERCODE_ADMIN_REAUTH_IDLE_DEFAULT')) {
    define('CUERCODE_ADMIN_REAUTH_IDLE_DEFAULT', 15);
}
if (!defined('CUERCODE_ADMIN_REAUTH_IDLE_MIN')) {
    define('CUERCODE_ADMIN_REAUTH_IDLE_MIN', 5);
}
if (!defined('CUERCODE_ADMIN_REAUTH_IDLE_MAX')) {
    define('CUERCODE_ADMIN_REAUTH_IDLE_MAX', 60);
}
if (!defined('CUERCODE_ADMIN_REAUTH_MAX_DEFAULT')) {
    define('CUERCODE_ADMIN_REAUTH_MAX_DEFAULT', 60);
}
if (!defined('CUERCODE_ADMIN_REAUTH_MAX_MIN')) {
    define('CUERCODE_ADMIN_REAUTH_MAX_MIN', 15);
}
if (!defined('CUERCODE_ADMIN_REAUTH_MAX_MAX')) {
    define('CUERCODE_ADMIN_REAUTH_MAX_MAX', 480);
}

/** @var int Largest changesJSON the audit log stores before truncating it (plan §6.7: 60 KB). */
if (!defined('CUERCODE_ADMIN_AUDIT_CHANGES_MAX_BYTES')) {
    define('CUERCODE_ADMIN_AUDIT_CHANGES_MAX_BYTES', 61440);
}

/** @var int Longest "next page" path accepted after a password confirmation (plan §6.4). */
if (!defined('CUERCODE_ADMIN_NEXT_PATH_MAX_LENGTH')) {
    define('CUERCODE_ADMIN_NEXT_PATH_MAX_LENGTH', 512);
}

/** @var string Default display time zone for the admin area (plan §8.4). */
if (!defined('CUERCODE_ADMIN_DEFAULT_TIMEZONE')) {
    define('CUERCODE_ADMIN_DEFAULT_TIMEZONE', 'Europe/London');
}

/** @var int Minutes after which waiting emails suggest the cron job is not running (overview page). */
if (!defined('CUERCODE_ADMIN_EMAIL_OVERDUE_MINUTES')) {
    define('CUERCODE_ADMIN_EMAIL_OVERDUE_MINUTES', 15);
}

/**
 * 🚦 Exit codes of _includes/cli/admin_access.php (plan §6.2):
 *    0 done (or nothing needed doing) | 1 failed (database problem - safe to
 *    run again) | 2 refused (bad option, unknown/ambiguous email, wrong
 *    account state, last Global Admin, confirmation not typed, not the
 *    command line, database not upgraded yet).
 */
if (!defined('CUERCODE_ADMIN_CLI_EXIT_OK')) {
    define('CUERCODE_ADMIN_CLI_EXIT_OK', 0);
}
if (!defined('CUERCODE_ADMIN_CLI_EXIT_FAILED')) {
    define('CUERCODE_ADMIN_CLI_EXIT_FAILED', 1);
}
if (!defined('CUERCODE_ADMIN_CLI_EXIT_REFUSED')) {
    define('CUERCODE_ADMIN_CLI_EXIT_REFUSED', 2);
}

// ==============================================================================
// 🧠 PURE: who is a Global Admin
// ==============================================================================

/**
 * 🎭 Is this tblUsers.roleID value the Global Admin role?
 *
 * Accepts what MySQLi hands back (an int, a numeric string, or null) and
 * never treats anything odd as "admin": only the exact whole number 1 counts.
 *
 * @param mixed $roleID The roleID column value
 * @return bool True only for the Global Admin role
 */
function adminRoleIsGlobalAdmin(mixed $roleID): bool
{
    if (is_int($roleID)) {
        return $roleID === CUERCODE_ROLE_GLOBAL_ADMIN;
    }

    if (is_string($roleID) && preg_match('/^[0-9]+$/', $roleID) === 1) {
        return (int) $roleID === CUERCODE_ROLE_GLOBAL_ADMIN;
    }

    return false;
}

/**
 * 🚦 The access decision for one request (plan §6.1). PURE - no database.
 *
 * @param array|null $currentUser What currentUser() returned (null = not signed in)
 * @param array|null $accessRow   What adminLoadAccessRow() returned for that
 *                                user (null = could not be read, which is
 *                                treated as "not an admin" - fail closed)
 * @return string 'not_signed_in', 'not_admin' or 'admin'
 */
function adminAccessDecision(?array $currentUser, ?array $accessRow): string
{
    if ($currentUser === null || !isset($currentUser['userID'])) {
        return 'not_signed_in';
    }

    if ($accessRow === null) {
        return 'not_admin';
    }

    // 🔗 The row must be about the same person the session belongs to.
    if (!isset($accessRow['userID']) || (int) $accessRow['userID'] !== (int) $currentUser['userID']) {
        return 'not_admin';
    }

    if (!array_key_exists('roleID', $accessRow) || !adminRoleIsGlobalAdmin($accessRow['roleID'])) {
        return 'not_admin';
    }

    if (!isset($accessRow['status']) || (string) $accessRow['status'] !== 'active') {
        return 'not_admin';
    }

    if (!isset($accessRow['emailVerifiedAt']) || trim((string) $accessRow['emailVerifiedAt']) === '') {
        return 'not_admin';
    }

    return 'admin';
}

// ==============================================================================
// 🧠 PURE: password re-confirmation (plan §6.4)
// ==============================================================================

/**
 * 🔢 Turn a setting value into a whole number of minutes, or the default.
 *
 * @param mixed $value   The raw value (int, numeric string, anything else)
 * @param int   $default Used when the value is not a whole number
 * @return int
 */
function adminWholeMinutes(mixed $value, int $default): int
{
    if (is_int($value)) {
        return $value;
    }

    if (is_string($value) && preg_match('/^\s*[0-9]{1,6}\s*$/', $value) === 1) {
        return (int) trim($value);
    }

    if (is_float($value) && floor($value) === $value && $value >= 0 && $value < 1000000) {
        return (int) $value;
    }

    return $default;
}

/**
 * 🔒 Clamp the two re-confirmation limits to their allowed ranges (plan §6.4):
 *    idle 5-60 minutes (default 15), absolute 15-480 (default 60), and the
 *    absolute limit is never below the idle one.
 *
 * @param int $idleMinutes Requested idle limit
 * @param int $maxMinutes  Requested absolute limit
 * @return array{idle: int, max: int} Safe limits, in minutes
 */
function adminReauthLimits(int $idleMinutes, int $maxMinutes): array
{
    $idle = max(CUERCODE_ADMIN_REAUTH_IDLE_MIN, min(CUERCODE_ADMIN_REAUTH_IDLE_MAX, $idleMinutes));
    $max  = max(CUERCODE_ADMIN_REAUTH_MAX_MIN, min(CUERCODE_ADMIN_REAUTH_MAX_MAX, $maxMinutes));

    if ($max < $idle) {
        $max = $idle;
    }

    return ['idle' => $idle, 'max' => $max];
}

/**
 * 🧾 Is a stored re-confirmation still good? PURE - every input is passed in.
 *
 * Fresh ONLY when all of these hold:
 *   - the state belongs to this user and to this database session (compared
 *     with hash_equals(), so the comparison takes the same time either way);
 *   - neither stored time is in the future, and the last use is not before
 *     the confirmation (a tampered or clock-skewed state is not trusted);
 *   - now - lastUsedAt  <= idle limit  (inclusive: exactly on the limit is
 *     still fresh, one second over is not);
 *   - now - confirmedAt <= absolute limit (inclusive, the same way).
 *
 * @param array|null $state       $_SESSION[CUERCODE_ADMIN_REAUTH_SESSION_KEY], or null
 * @param int        $userID      The signed-in admin's userID
 * @param string     $sessionHash hash('sha256', <tblUserSessions token>) for this request
 * @param int        $now         Current Unix time
 * @param int        $idleMinutes Idle limit (clamped by adminReauthLimits())
 * @param int        $maxMinutes  Absolute limit (clamped by adminReauthLimits())
 * @return bool
 */
function adminReauthIsFresh(?array $state, int $userID, string $sessionHash, int $now, int $idleMinutes, int $maxMinutes): bool
{
    if ($state === null) {
        return false;
    }

    foreach (['userID', 'sessionHash', 'confirmedAt', 'lastUsedAt'] as $requiredKey) {
        if (!array_key_exists($requiredKey, $state)) {
            return false;
        }
    }

    if (!is_int($state['userID']) || !is_int($state['confirmedAt']) || !is_int($state['lastUsedAt']) || !is_string($state['sessionHash'])) {
        return false;
    }

    if ($userID <= 0 || $state['userID'] !== $userID) {
        return false;
    }

    // 🔗 Tied to the database session (plan §6.4): a new sign-in has a new
    //    tblUserSessions token, so its hash can never match.
    if ($sessionHash === '' || $state['sessionHash'] === '' || !hash_equals($state['sessionHash'], $sessionHash)) {
        return false;
    }

    $confirmedAt = $state['confirmedAt'];
    $lastUsedAt  = $state['lastUsedAt'];

    if ($confirmedAt > $now || $lastUsedAt > $now || $lastUsedAt < $confirmedAt) {
        return false;
    }

    $limits = adminReauthLimits($idleMinutes, $maxMinutes);

    if (($now - $lastUsedAt) > ($limits['idle'] * 60)) {
        return false;
    }

    if (($now - $confirmedAt) > ($limits['max'] * 60)) {
        return false;
    }

    return true;
}

/**
 * ⏳ How many seconds of editing are left before the next change asks for the
 *    password again. PURE. Returns 0 for a state that is not fresh.
 *
 * @param array|null $state       See adminReauthIsFresh()
 * @param int        $userID      See adminReauthIsFresh()
 * @param string     $sessionHash See adminReauthIsFresh()
 * @param int        $now         See adminReauthIsFresh()
 * @param int        $idleMinutes See adminReauthIsFresh()
 * @param int        $maxMinutes  See adminReauthIsFresh()
 * @return int Seconds left (0 when locked)
 */
function adminReauthSecondsLeft(?array $state, int $userID, string $sessionHash, int $now, int $idleMinutes, int $maxMinutes): int
{
    if (!adminReauthIsFresh($state, $userID, $sessionHash, $now, $idleMinutes, $maxMinutes)) {
        return 0;
    }

    $limits    = adminReauthLimits($idleMinutes, $maxMinutes);
    $idleLeft  = ($limits['idle'] * 60) - ($now - (int) $state['lastUsedAt']);
    $totalLeft = ($limits['max'] * 60) - ($now - (int) $state['confirmedAt']);

    return max(0, min($idleLeft, $totalLeft));
}

/**
 * 🧮 After one more wrong password on the confirm page, what happens? PURE.
 *
 * @param int $failuresIncludingThisOne Wrong passwords in this session, counting this one
 * @return string 'retry' (show the form again) or 'lockout' (sign the admin out)
 */
function adminReauthFailureOutcome(int $failuresIncludingThisOne): string
{
    if ($failuresIncludingThisOne >= CUERCODE_ADMIN_REAUTH_MAX_FAILURES) {
        return 'lockout';
    }

    return 'retry';
}

// ==============================================================================
// 🧠 PURE: safe places to go next, and same-site checks
// ==============================================================================

/**
 * 🔗 A safe admin path to redirect to after a password confirmation (plan
 *    §6.4, security checklist S10). PURE.
 *
 * Accepted only when ALL of these hold, both for the value as given and for
 * the value with %-escapes decoded once (so "%2F%2F", "%5C" and "%3A" cannot
 * sneak the same tricks past):
 *   - it is "/admin/" or starts with "/admin/";
 *   - no "//" anywhere (a browser reads "//host" as another site);
 *   - no "\" anywhere (some browsers read "/\host" as another site);
 *   - no ":" anywhere (no "https:", "javascript:" and the like);
 *   - no control characters (no line breaks that could add a header);
 *   - no "." or ".." path segments (cannot climb out of /admin/);
 *   - at most 512 characters.
 * Anything else gives $default.
 *
 * @param mixed  $raw     The untrusted value (usually from a form or query)
 * @param string $default The safe fallback
 * @return string
 */
function adminSafeNextPath(mixed $raw, string $default = '/admin/'): string
{
    if (!is_string($raw) || $raw === '') {
        return $default;
    }

    if (strlen($raw) > CUERCODE_ADMIN_NEXT_PATH_MAX_LENGTH) {
        return $default;
    }

    $decoded = rawurldecode($raw);

    foreach ([$raw, $decoded] as $candidate) {
        if ($candidate !== '/admin/' && !str_starts_with($candidate, '/admin/')) {
            return $default;
        }

        if (str_contains($candidate, '//') || str_contains($candidate, '\\') || str_contains($candidate, ':')) {
            return $default;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
            return $default;
        }

        // 🧗 Only the path part matters for dot segments ("?a=.." is harmless).
        $pathPart = $candidate;
        $queryPosition = strpos($pathPart, '?');
        if ($queryPosition !== false) {
            $pathPart = substr($pathPart, 0, $queryPosition);
        }

        if (preg_match('#(^|/)\.{1,2}(/|$)#', $pathPart) === 1) {
            return $default;
        }
    }

    return $raw;
}

/**
 * 🌐 Is a browser "Origin" header this site's own? PURE (plan §6.3: defence in
 *    depth on top of the CSRF token).
 *
 * - No Origin header at all is allowed (older browsers and some form posts
 *   do not send one; the CSRF token still protects them).
 * - The literal "null" origin (sandboxed frames, some redirects) is refused.
 * - Otherwise its host (and port, when not the default for its scheme) must
 *   equal this request's Host header. The scheme is not compared, because a
 *   site behind a proxy (Cloudflare) cannot always tell http from https.
 *
 * @param string|null $origin The Origin header, or null when absent
 * @param string      $host   The request's Host header (HTTP_HOST)
 * @return bool
 */
function adminOriginIsAllowed(?string $origin, string $host): bool
{
    if ($origin === null || trim($origin) === '') {
        return true;
    }

    $origin = trim($origin);

    if (strtolower($origin) === 'null') {
        return false;
    }

    $parts = parse_url($origin);
    if (!is_array($parts) || !isset($parts['scheme']) || !isset($parts['host'])) {
        return false;
    }

    $scheme = strtolower((string) $parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return false;
    }

    $originHost = strtolower((string) $parts['host']);
    if (isset($parts['port'])) {
        $originPort = (int) $parts['port'];
        $isDefaultPort = false;
        if ($scheme === 'http' && $originPort === 80) {
            $isDefaultPort = true;
        }
        if ($scheme === 'https' && $originPort === 443) {
            $isDefaultPort = true;
        }
        if (!$isDefaultPort) {
            $originHost .= ':' . $originPort;
        }
    }

    $requestHost = strtolower(trim($host));
    if ($requestHost === '') {
        return false;
    }

    // 🔌 A Host header can carry the default port too ("site:443").
    if (str_ends_with($requestHost, ':80') || str_ends_with($requestHost, ':443')) {
        $requestHost = substr($requestHost, 0, (int) strrpos($requestHost, ':'));
    }

    return hash_equals($requestHost, $originHost);
}

// ==============================================================================
// 🧠 PURE: the audit trail (plan §6.7)
// ==============================================================================

/**
 * 📜 Every action name the audit log accepts (plan §6.7 - use exactly these).
 *    adminAuditBuildRow() refuses any other name, so a typo can never create
 *    a new, unsearchable kind of row.
 *
 * @return array<int, string>
 */
function adminAuditActionNames(): array
{
    return [
        'reauth.success', 'reauth.failed', 'reauth.lockout',
        'admin.grant', 'admin.revoke', 'admin.first_seen',
        'schema.migrate',
        'setting.update', 'setting.secret_set', 'setting.secret_replace', 'setting.secret_clear',
        'setting.test_graph', 'setting.test_email',
        'plan.create', 'plan.update', 'plan.update_reduction', 'plan.new_version', 'plan.retire', 'plan.restore', 'plan.delete',
        'price.create', 'price.update', 'price.retire', 'price.delete',
        'entitlement.update', 'apikeys.resync',
        'discount.create', 'discount.update', 'discount.disable', 'discount.eligibility_grant', 'discount.eligibility_revoke',
        'customer.lookup',
        'deal.create', 'deal.start', 'deal.update', 'deal.end',
        'payg.pack_create', 'payg.pack_update', 'payg.cap_update',
    ];
}

/**
 * 🔤 A value turned into something comparable: null stays null, true/false
 *    become "1"/"0", arrays become JSON, everything else becomes a string.
 *    So 1 and "1" (what MySQLi often returns) count as "unchanged".
 *
 * @param mixed $value Any value
 * @return string|null
 */
function adminAuditComparable(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    if (is_bool($value)) {
        if ($value) {
            return '1';
        }
        return '0';
    }

    if (is_array($value)) {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false) {
            return '';
        }
        return $encoded;
    }

    if (is_scalar($value)) {
        return (string) $value;
    }

    return get_debug_type($value);
}

/**
 * 🤫 What happened to a secret, described WITHOUT the secret. PURE.
 *
 * @param mixed $old The stored value before (only tested for "empty or not" / "same or not")
 * @param mixed $new The value after
 * @return string|null 'set', 'replaced', 'cleared', or null when nothing changed
 */
function adminAuditSecretMarker(mixed $old, mixed $new): ?string
{
    $oldText = adminAuditComparable($old);
    $newText = adminAuditComparable($new);

    $oldEmpty = ($oldText === null || $oldText === '');
    $newEmpty = ($newText === null || $newText === '');

    if ($oldEmpty && $newEmpty) {
        return null;
    }

    if ($oldEmpty) {
        return 'set';
    }

    if ($newEmpty) {
        return 'cleared';
    }

    if (hash_equals($oldText, $newText)) {
        return null;
    }

    return 'replaced';
}

/**
 * 🧾 The "what changed" record for one audit row (plan §6.7). PURE.
 *
 * Lists only the fields whose value changed, as
 *   {"field": {"old": x, "new": y}}
 * EXCEPT a field named in $secretFields, which is written as
 *   {"field": {"secret": "set" | "replaced" | "cleared"}}
 * so a secret value can never reach the audit log, whatever the caller passes.
 *
 * @param array<string, mixed> $before       Field => value before the change
 * @param array<string, mixed> $after        Field => value after the change
 * @param array<int, string>   $secretFields Names of fields that hold secrets
 * @return array<string, array<string, mixed>>
 */
function adminAuditChanges(array $before, array $after, array $secretFields): array
{
    $changes = [];

    // 📋 Every field mentioned on either side, in a stable order.
    $fieldNames = [];
    foreach (array_keys($before) as $fieldName) {
        $fieldNames[(string) $fieldName] = true;
    }
    foreach (array_keys($after) as $fieldName) {
        $fieldNames[(string) $fieldName] = true;
    }

    $secretLookup = [];
    foreach ($secretFields as $secretField) {
        $secretLookup[(string) $secretField] = true;
    }

    foreach (array_keys($fieldNames) as $fieldName) {
        $oldValue = null;
        if (array_key_exists($fieldName, $before)) {
            $oldValue = $before[$fieldName];
        }

        $newValue = null;
        if (array_key_exists($fieldName, $after)) {
            $newValue = $after[$fieldName];
        }

        if (isset($secretLookup[$fieldName])) {
            $marker = adminAuditSecretMarker($oldValue, $newValue);
            if ($marker !== null) {
                $changes[$fieldName] = ['secret' => $marker];
            }
            continue;
        }

        if (adminAuditComparable($oldValue) === adminAuditComparable($newValue)) {
            continue;
        }

        $changes[$fieldName] = ['old' => $oldValue, 'new' => $newValue];
    }

    return $changes;
}

/**
 * 📦 Encode a changes array for the changesJSON column. PURE.
 *
 * Over CUERCODE_ADMIN_AUDIT_CHANGES_MAX_BYTES (60 KB) the record is replaced
 * by a short marker that still names the changed fields (as many as fit), so
 * the row is never lost and never breaks the column.
 *
 * @param array $changes From adminAuditChanges() (or a hand-built equivalent)
 * @return string|null JSON text, or null when there is nothing to record
 */
function adminAuditEncodeChanges(array $changes): ?string
{
    if ($changes === []) {
        return null;
    }

    $flags   = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
    $encoded = json_encode($changes, $flags);

    if (is_string($encoded) && strlen($encoded) <= CUERCODE_ADMIN_AUDIT_CHANGES_MAX_BYTES) {
        return $encoded;
    }

    // ✂️ Too big (or not encodable): keep the field names only.
    $marker = ['_truncated' => true, 'fields' => []];
    $budget = CUERCODE_ADMIN_AUDIT_CHANGES_MAX_BYTES - 200;
    $used   = 0;

    foreach (array_keys($changes) as $fieldName) {
        $name = mb_substr((string) $fieldName, 0, 100);
        $used += strlen($name) + 4;
        if ($used > $budget) {
            break;
        }
        $marker['fields'][] = $name;
    }

    $markerJson = json_encode($marker, $flags);
    if ($markerJson === false) {
        return '{"_truncated":true,"fields":[]}';
    }

    return $markerJson;
}

/**
 * ✂️ Trim a text value to a column length (characters), or null when empty.
 *
 * @param mixed $value     Any value
 * @param int   $maxLength Column length in characters
 * @return string|null
 */
function adminAuditShortText(mixed $value, int $maxLength): ?string
{
    if ($value === null) {
        return null;
    }

    if (!is_scalar($value)) {
        return null;
    }

    $text = trim((string) $value);

    // 🧽 No control characters in the log (no forged line breaks).
    $text = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);

    if ($text === '') {
        return null;
    }

    return mb_substr($text, 0, $maxLength);
}

/**
 * 🧱 Check and tidy one audit entry into the exact columns of
 *    tblAdminAuditLog. PURE. Returns null (nothing is written) when the entry
 *    is not acceptable: an unknown action or actor type, a missing label or
 *    entity type, or an unknown outcome.
 *
 * @param array $entry Keys: actorType ('admin'|'cli'|'system'), actorUserID (int|null),
 *                     actorLabel, action, entityType, entityKey (optional),
 *                     outcome ('success'|'refused'|'failed', default 'success'),
 *                     changes (array, optional), reason (optional),
 *                     requestIP (optional), userAgent (optional)
 * @return array|null Column => value, ready to bind
 */
function adminAuditBuildRow(array $entry): ?array
{
    $actorType = '';
    if (isset($entry['actorType']) && is_string($entry['actorType'])) {
        $actorType = $entry['actorType'];
    }
    if (!in_array($actorType, ['admin', 'cli', 'system'], true)) {
        return null;
    }

    $action = '';
    if (isset($entry['action']) && is_string($entry['action'])) {
        $action = $entry['action'];
    }
    if (!in_array($action, adminAuditActionNames(), true)) {
        return null;
    }

    $outcome = 'success';
    if (isset($entry['outcome'])) {
        $outcome = (string) $entry['outcome'];
    }
    if (!in_array($outcome, ['success', 'refused', 'failed'], true)) {
        return null;
    }

    $actorLabel = null;
    if (isset($entry['actorLabel'])) {
        $actorLabel = adminAuditShortText($entry['actorLabel'], 255);
    }
    if ($actorLabel === null) {
        return null;
    }

    $entityType = null;
    if (isset($entry['entityType'])) {
        $entityType = adminAuditShortText($entry['entityType'], 50);
    }
    if ($entityType === null) {
        return null;
    }

    $actorUserID = null;
    if (isset($entry['actorUserID']) && is_int($entry['actorUserID']) && $entry['actorUserID'] > 0) {
        $actorUserID = $entry['actorUserID'];
    }

    $entityKey = null;
    if (isset($entry['entityKey'])) {
        $entityKey = adminAuditShortText($entry['entityKey'], 100);
    }

    $changesJson = null;
    if (isset($entry['changes']) && is_array($entry['changes'])) {
        $changesJson = adminAuditEncodeChanges($entry['changes']);
    }

    $reason = null;
    if (isset($entry['reason'])) {
        $reason = adminAuditShortText($entry['reason'], 500);
    }

    $requestIP = null;
    if (isset($entry['requestIP'])) {
        $requestIP = adminAuditShortText($entry['requestIP'], 45);
    }

    $userAgent = null;
    if (isset($entry['userAgent'])) {
        $userAgent = adminAuditShortText($entry['userAgent'], 500);
    }

    return [
        'actorUserID' => $actorUserID,
        'actorType'   => $actorType,
        'actorLabel'  => $actorLabel,
        'action'      => $action,
        'entityType'  => $entityType,
        'entityKey'   => $entityKey,
        'outcome'     => $outcome,
        'changesJSON' => $changesJson,
        'reason'      => $reason,
        'requestIP'   => $requestIP,
        'userAgent'   => $userAgent,
    ];
}

// ==============================================================================
// 🧠 PURE: dates and time zones (plan §8.4)
// ==============================================================================
// Stored values are ALWAYS UTC (the database session runs in UTC). The admin
// area shows and accepts UK time (the admin_display_timezone setting). Every
// conversion names its time zone explicitly - never date()/strtotime() with
// PHP's global zone, which bootstrap.php sets from a different setting.
// ==============================================================================

/**
 * 🌍 Is this a time zone name PHP knows (for example "Europe/London", "UTC")?
 *
 * @param string $timezone Candidate name
 * @return bool
 */
function adminTimezoneIsValid(string $timezone): bool
{
    if ($timezone === '') {
        return false;
    }

    return in_array($timezone, \DateTimeZone::listIdentifiers(), true);
}

/**
 * 🏷️ The words shown after a time, for example "UK time". PURE.
 *
 * @param string $timezone Time zone name
 * @return string
 */
function adminTimezoneLabel(string $timezone): string
{
    if ($timezone === 'Europe/London') {
        return 'UK time';
    }

    if ($timezone === 'UTC') {
        return 'UTC';
    }

    return $timezone . ' time';
}

/**
 * 🔁 Is this local wall-clock time ambiguous (it happens twice, when the
 *    clocks go back)? Checks the instants up to two hours either side, in
 *    15-minute steps, which covers every real clock change (30, 60 and 120
 *    minutes). PURE.
 *
 * @param string        $localText "Y-m-d H:i:s" as typed
 * @param int           $timestamp The instant PHP chose for it
 * @param \DateTimeZone $zone      The zone it was typed in
 * @return int|null The OTHER instant with the same wall-clock time, or null if unique
 */
function adminLocalTimeOtherInstant(string $localText, int $timestamp, \DateTimeZone $zone): ?int
{
    for ($offset = -7200; $offset <= 7200; $offset += 900) {
        if ($offset === 0) {
            continue;
        }

        $candidate = (new \DateTimeImmutable('@' . ($timestamp + $offset)))->setTimezone($zone);
        if ($candidate->format('Y-m-d H:i:s') === $localText) {
            return $timestamp + $offset;
        }
    }

    return null;
}

/**
 * 🕰️ Check a typed local date (and optional time) and convert it to UTC.
 *    PURE (plan §8.4).
 *
 * Accepts "YYYY-MM-DD", "YYYY-MM-DD HH:MM", "YYYY-MM-DDTHH:MM" (what an
 * <input type="datetime-local"> sends) and the same with ":SS". A date with
 * no time means 00:00:00 for a start and 23:59:59 for an end.
 *
 * Problems, in plain words for the caller to show:
 *   - 'invalid'     not a real date/time in that shape;
 *   - 'timezone'    the time zone name is not one PHP knows;
 *   - 'nonexistent' the clocks went forward over it (for example 01:30 on the
 *                   last Sunday of March in the UK) - suggestion = the first
 *                   valid time after it;
 *   - 'ambiguous'   it happens twice because the clocks went back (for
 *                   example 01:30 on the last Sunday of October in the UK) -
 *                   suggestion = the first time after the repeated hour.
 *
 * @param string $local    The typed value
 * @param string $timezone The zone it was typed in (for example "Europe/London")
 * @param bool   $endOfDay True for an END date (a bare date means 23:59:59)
 * @return array{ok: bool, utc: string|null, problem: string, suggestion: string|null}
 *         utc is "Y-m-d H:i:s" in UTC; suggestion is "Y-m-d H:i:s" in the local zone
 */
function adminLocalTimeCheck(string $local, string $timezone, bool $endOfDay): array
{
    $result = ['ok' => false, 'utc' => null, 'problem' => 'invalid', 'suggestion' => null];

    if (!adminTimezoneIsValid($timezone)) {
        $result['problem'] = 'timezone';
        return $result;
    }

    $local = trim($local);
    $matches = [];

    if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $local, $matches) === 1) {
        if ($endOfDay) {
            $timePart = '23:59:59';
        } else {
            $timePart = '00:00:00';
        }
        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];
    } elseif (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})[ T]([0-9]{2}):([0-9]{2})(?::([0-9]{2}))?$/', $local, $matches) === 1) {
        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];
        $hour = (int) $matches[4];
        $minute = (int) $matches[5];
        $second = 0;
        if (isset($matches[6]) && $matches[6] !== '') {
            $second = (int) $matches[6];
        }
        if ($hour > 23 || $minute > 59 || $second > 59) {
            return $result;
        }
        $timePart = sprintf('%02d:%02d:%02d', $hour, $minute, $second);
    } else {
        return $result;
    }

    if ($year < 1970 || $year > 9999 || !checkdate($month, $day, $year)) {
        return $result;
    }

    $localText = sprintf('%04d-%02d-%02d', $year, $month, $day) . ' ' . $timePart;
    $zone      = new \DateTimeZone($timezone);
    $dateTime  = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $localText, $zone);

    if ($dateTime === false) {
        return $result;
    }

    // ⏩ Spring forward: PHP quietly moves a missing time on by the size of
    //    the jump (01:30 → 02:30); converting back shows it. The FIRST valid
    //    time after the gap is the moment the clocks changed (02:00), found
    //    from the zone's own list of clock changes.
    if ($dateTime->format('Y-m-d H:i:s') !== $localText) {
        $result['problem']    = 'nonexistent';
        $result['suggestion'] = $dateTime->format('Y-m-d H:i:s');

        $movedTimestamp = $dateTime->getTimestamp();
        $transitions    = $zone->getTransitions($movedTimestamp - 10800, $movedTimestamp);
        if (is_array($transitions) && count($transitions) > 1) {
            $lastChange = $transitions[count($transitions) - 1];
            if (isset($lastChange['ts']) && (int) $lastChange['ts'] > $movedTimestamp - 10800) {
                $result['suggestion'] = (new \DateTimeImmutable('@' . (int) $lastChange['ts']))->setTimezone($zone)->format('Y-m-d H:i:s');
            }
        }

        return $result;
    }

    // ⏪ Fall back: the same wall-clock time at two different instants.
    $timestamp = $dateTime->getTimestamp();
    $otherInstant = adminLocalTimeOtherInstant($localText, $timestamp, $zone);
    if ($otherInstant !== null) {
        $result['problem'] = 'ambiguous';

        // 🔎 Step forward a minute at a time from the LATER instant until the
        //    wall-clock time is no longer repeated (at most three hours).
        $probe = max($timestamp, $otherInstant);
        for ($step = 0; $step < 180; $step++) {
            $probe += 60;
            $probeLocal = (new \DateTimeImmutable('@' . $probe))->setTimezone($zone)->format('Y-m-d H:i:s');
            if (adminLocalTimeOtherInstant($probeLocal, $probe, $zone) === null) {
                $result['suggestion'] = $probeLocal;
                break;
            }
        }

        return $result;
    }

    $result['ok']      = true;
    $result['problem'] = '';
    $result['utc']     = $dateTime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

    return $result;
}

/**
 * 🕰️ Local (typed) date/time → UTC "Y-m-d H:i:s", or null when it cannot be
 *    used (see adminLocalTimeCheck() for why). PURE (plan §8.4).
 *
 * Examples (Europe/London): end 2026-12-31 → "2026-12-31 23:59:59" (GMT);
 * end 2027-06-30 → "2027-06-30 22:59:59" (British Summer Time).
 *
 * @param string $local    The typed value
 * @param string $timezone The zone it was typed in
 * @param bool   $endOfDay True for an END date
 * @return string|null
 */
function adminLocalToUtc(string $local, string $timezone, bool $endOfDay): ?string
{
    $check = adminLocalTimeCheck($local, $timezone, $endOfDay);

    if ($check['ok'] !== true) {
        return null;
    }

    return $check['utc'];
}

/**
 * 🕰️ UTC "Y-m-d H:i:s" (a database value) → the same instant as local
 *    "Y-m-d H:i:s". PURE. Returns '' for anything that is not a valid UTC
 *    date-time or an unknown zone (never a guess).
 *
 * @param string $utc      Stored value
 * @param string $timezone Zone to show it in
 * @return string
 */
function adminUtcToLocal(string $utc, string $timezone): string
{
    if (!adminTimezoneIsValid($timezone)) {
        return '';
    }

    $utc = trim($utc);
    if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/', $utc) !== 1) {
        return '';
    }

    $dateTime = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new \DateTimeZone('UTC'));
    if ($dateTime === false || $dateTime->format('Y-m-d H:i:s') !== $utc) {
        return '';
    }

    return $dateTime->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d H:i:s');
}

/**
 * 🗓️ A stored UTC value written for people, for example "5 Oct 2026, 14:02".
 *    PURE. '' when the value cannot be read.
 *
 * @param string|null $utc      Stored value (null = nothing to show)
 * @param string      $timezone Zone to show it in
 * @return string
 */
function adminFormatUtcForDisplay(?string $utc, string $timezone): string
{
    if ($utc === null) {
        return '';
    }

    $local = adminUtcToLocal($utc, $timezone);
    if ($local === '') {
        return '';
    }

    $dateTime = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $local, new \DateTimeZone($timezone));
    if ($dateTime === false) {
        return '';
    }

    return $dateTime->format('j M Y, H:i');
}

/**
 * ⏰ A Unix time written as a local clock time, for example "14:32". PURE.
 *
 * @param int    $timestamp Unix time
 * @param string $timezone  Zone to show it in (falls back to UTC if unknown)
 * @return string
 */
function adminFormatClockTime(int $timestamp, string $timezone): string
{
    if (!adminTimezoneIsValid($timezone)) {
        $timezone = 'UTC';
    }

    return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone($timezone))->format('H:i');
}

// ==============================================================================
// 🧠 PURE: the command-line tool's options and decisions (plan §6.2)
// ==============================================================================

/**
 * 🙈 An email address partly hidden, for example "o***r@example.com", for
 *    the `list` command (plan §6.2). PURE. The domain is kept so the operator
 *    can still tell accounts apart.
 *
 * @param string $email Address
 * @return string
 */
function adminMaskEmail(string $email): string
{
    $email = trim($email);
    $atPosition = strrpos($email, '@');

    if ($atPosition === false || $atPosition === 0) {
        return '***';
    }

    $localPart = substr($email, 0, $atPosition);
    $domain    = substr($email, $atPosition + 1);

    if (mb_strlen($localPart) <= 2) {
        $maskedLocal = mb_substr($localPart, 0, 1) . '***';
    } else {
        $maskedLocal = mb_substr($localPart, 0, 1) . '***' . mb_substr($localPart, -1);
    }

    return $maskedLocal . '@' . $domain;
}

/**
 * 📧 Tidy an email address the way registration stores it (trimmed, lower
 *    case), or '' when it is not a plausible address. PURE.
 *
 * @param string $email Typed address
 * @return string
 */
function adminNormaliseEmail(string $email): string
{
    $email = mb_strtolower(trim($email));

    if ($email === '' || mb_strlen($email) > 255) {
        return '';
    }

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return '';
    }

    return $email;
}

/**
 * 🧾 Read the command line of admin_access.php. PURE.
 *
 *   grant  <email> [--activate] [--yes]
 *   revoke <email> [--yes]
 *   list
 *   --help | -h | help
 *
 * @param array<int, string> $argv Full argv (argv[0] is the script name)
 * @return array{ok: bool, command: string, email: string, activate: bool, yes: bool, error: string}
 */
function adminCliParseArgs(array $argv): array
{
    $result = ['ok' => false, 'command' => '', 'email' => '', 'activate' => false, 'yes' => false, 'error' => ''];

    $arguments  = array_values(array_slice($argv, 1));
    $positional = [];

    foreach ($arguments as $argument) {
        $argument = (string) $argument;

        if ($argument === '--help' || $argument === '-h') {
            $result['ok'] = true;
            $result['command'] = 'help';
            return $result;
        } elseif ($argument === '--activate') {
            $result['activate'] = true;
        } elseif ($argument === '--yes') {
            $result['yes'] = true;
        } elseif (str_starts_with($argument, '-')) {
            $result['error'] = 'Unknown option: ' . adminCliSafeEcho($argument);
            return $result;
        } else {
            $positional[] = $argument;
        }
    }

    if ($positional === []) {
        $result['error'] = 'Say what to do: grant, list or revoke.';
        return $result;
    }

    $command = strtolower($positional[0]);

    if ($command === 'help') {
        $result['ok'] = true;
        $result['command'] = 'help';
        return $result;
    }

    if ($command === 'list') {
        if (count($positional) !== 1) {
            $result['error'] = 'list takes no email address.';
            return $result;
        }
        if ($result['activate'] || $result['yes']) {
            $result['error'] = 'list takes no options.';
            return $result;
        }
        $result['ok'] = true;
        $result['command'] = 'list';
        return $result;
    }

    if ($command !== 'grant' && $command !== 'revoke') {
        $result['error'] = 'Unknown command: ' . adminCliSafeEcho($positional[0]) . ' (use grant, list or revoke).';
        return $result;
    }

    if (count($positional) !== 2) {
        $result['error'] = $command . ' needs exactly one email address.';
        return $result;
    }

    if ($command === 'revoke' && $result['activate']) {
        $result['error'] = '--activate only goes with grant.';
        return $result;
    }

    $email = adminNormaliseEmail($positional[1]);
    if ($email === '') {
        $result['error'] = 'That does not look like an email address.';
        return $result;
    }

    $result['ok'] = true;
    $result['command'] = $command;
    $result['email'] = $email;

    return $result;
}

/**
 * 🧽 Make an untrusted command-line word safe to print back (no control
 *    characters, at most 60 characters). PURE.
 *
 * @param string $text Untrusted text
 * @return string
 */
function adminCliSafeEcho(string $text): string
{
    $clean = (string) preg_replace('/[\x00-\x1F\x7F]+/', '?', $text);

    return mb_substr($clean, 0, 60);
}

/**
 * 📖 The help text of admin_access.php.
 *
 * @return array<int, string> Lines
 */
function adminCliUsage(): array
{
    return [
        'CueRCode Global Admin access (command line only)',
        '',
        'Usage:',
        '  php admin_access.php grant <email> [--activate] [--yes]',
        '      Make an existing account a Global Admin.',
        '      --activate  also mark the account active and its email as checked',
        '                  (for the very first admin, before email sending works)',
        '      --yes       do not ask you to type the email address again',
        '  php admin_access.php list',
        '      List the Global Admins (email addresses partly hidden).',
        '  php admin_access.php revoke <email> [--yes]',
        '      Remove Global Admin access. The last Global Admin cannot be removed.',
        '  php admin_access.php --help',
        '',
        'The account must already exist (sign up at /register/ first), must have a',
        'password, and must not be suspended or deleted.',
        '',
        'Exit codes:',
        '  0  done (or nothing needed doing)',
        '  1  failed - a database problem; nothing was half-done, so run it again',
        '  2  refused - see the message (nothing was changed)',
    ];
}

/**
 * ✅ Does the re-typed confirmation match the email? PURE. Case and the
 *    spaces at either end do not matter.
 *
 * @param string|false $typed What the operator typed (false = nothing could be read)
 * @param string       $email The normalised email from the command line
 * @return bool
 */
function adminCliConfirmationMatches(string|false $typed, string $email): bool
{
    if ($typed === false || $email === '') {
        return false;
    }

    return hash_equals($email, mb_strtolower(trim($typed)));
}

/**
 * 🚦 Decide what `grant` may do with the account(s) found for an email
 *    (plan §6.2). PURE.
 *
 * @param array<int, array> $rows     Accounts with that email (keys userID, roleID,
 *                                    status, emailVerifiedAt, hasPassword)
 * @param bool              $activate --activate was given
 * @return array{outcome: string, code: string, message: string, activates: bool, warnings: array<int, string>}
 *         outcome 'grant' (change something), 'nothing' (already done) or 'refuse'
 */
function adminGrantDecision(array $rows, bool $activate): array
{
    $decision = ['outcome' => 'refuse', 'code' => '', 'message' => '', 'activates' => false, 'warnings' => []];

    if (count($rows) === 0) {
        $decision['code']    = 'unknown_email';
        $decision['message'] = 'No account has that email address. Sign up at /register/ first, then run this again.';
        return $decision;
    }

    if (count($rows) > 1) {
        $decision['code']    = 'ambiguous_email';
        $decision['message'] = 'More than one account has that email address, so nothing was changed. Check tblUsers by hand.';
        return $decision;
    }

    $row    = $rows[0];
    $status = '';
    if (isset($row['status'])) {
        $status = (string) $row['status'];
    }

    if ($status !== 'pending' && $status !== 'active') {
        $decision['code']    = 'bad_status';
        $decision['message'] = 'That account is ' . adminCliSafeEcho($status) . ', so it cannot be made a Global Admin.';
        return $decision;
    }

    $hasPassword = false;
    if (isset($row['hasPassword']) && (int) $row['hasPassword'] === 1) {
        $hasPassword = true;
    }

    if (!$hasPassword) {
        $decision['code']    = 'no_password';
        $decision['message'] = 'That account has no password (it signs in with Microsoft or Google only). A Global Admin needs a password to confirm changes. Set one with "Forgot password" first.';
        return $decision;
    }

    $isVerified = (isset($row['emailVerifiedAt']) && trim((string) $row['emailVerifiedAt']) !== '');
    $needsActivation = ($status !== 'active' || !$isVerified);
    $alreadyAdmin = (array_key_exists('roleID', $row) && adminRoleIsGlobalAdmin($row['roleID']));

    if ($needsActivation && $activate) {
        $decision['activates'] = true;
    }

    if ($needsActivation && !$activate) {
        $decision['warnings'][] = 'This account is not active with a checked email address yet, so it cannot open the admin area until it is. Add --activate to do that now.';
    }

    if ($alreadyAdmin && !$decision['activates']) {
        $decision['outcome'] = 'nothing';
        $decision['code']    = 'already_admin';
        $decision['message'] = 'That account is already a Global Admin. Nothing was changed.';
        return $decision;
    }

    $decision['outcome'] = 'grant';
    $decision['code']    = 'ok';
    if ($alreadyAdmin) {
        $decision['message'] = 'Already a Global Admin; the account will be activated.';
    } else {
        $decision['message'] = 'The account will be made a Global Admin.';
    }

    return $decision;
}

/**
 * 🚦 Decide what `revoke` may do (plan §6.2, §6.5: the last Global Admin can
 *    never be removed). PURE.
 *
 * @param array<int, array> $rows                Accounts with that email
 * @param int               $effectiveAdminCount How many accounts are working
 *                                               Global Admins right now (role 1,
 *                                               active, email checked)
 * @return array{outcome: string, code: string, message: string}
 *         outcome 'revoke', 'nothing' or 'refuse'
 */
function adminRevokeDecision(array $rows, int $effectiveAdminCount): array
{
    $decision = ['outcome' => 'refuse', 'code' => '', 'message' => ''];

    if (count($rows) === 0) {
        $decision['code']    = 'unknown_email';
        $decision['message'] = 'No account has that email address.';
        return $decision;
    }

    if (count($rows) > 1) {
        $decision['code']    = 'ambiguous_email';
        $decision['message'] = 'More than one account has that email address, so nothing was changed.';
        return $decision;
    }

    $row = $rows[0];

    if (!array_key_exists('roleID', $row) || !adminRoleIsGlobalAdmin($row['roleID'])) {
        $decision['outcome'] = 'nothing';
        $decision['code']    = 'not_admin';
        $decision['message'] = 'That account is not a Global Admin. Nothing was changed.';
        return $decision;
    }

    $isEffective = (
        isset($row['status']) && (string) $row['status'] === 'active'
        && isset($row['emailVerifiedAt']) && trim((string) $row['emailVerifiedAt']) !== ''
    );

    if ($isEffective && $effectiveAdminCount <= 1) {
        $decision['code']    = 'last_admin';
        $decision['message'] = 'That is the last working Global Admin. Make another account a Global Admin first.';
        return $decision;
    }

    $decision['outcome'] = 'revoke';
    $decision['code']    = 'ok';
    $decision['message'] = 'Global Admin access will be removed.';

    return $decision;
}

// ==============================================================================
// 🗄️ DATABASE + REQUEST: the gate (plan §6.1, §7.4)
// ==============================================================================

/**
 * 🧱 Security headers for every admin response (plan §7.4). public_html/
 *    admin/.htaccess sends the same ones; these are the copy that still works
 *    when the host ignores .htaccess header lines.
 *
 * @return void
 */
function adminSendSecurityHeaders(): void
{
    if (headers_sent()) {
        return;
    }

    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    header('X-Frame-Options: DENY');
    header('X-Robots-Tag: noindex, nofollow');
    // 🖼️ Only the frame rule: the full site policy comes from .htaccess, and
    //    two policies are both enforced, so this can only ever tighten it.
    header("Content-Security-Policy: frame-ancestors 'none'");
}

/**
 * 🔑 hash('sha256', <this request's tblUserSessions token>), or '' when there
 *    is no signed-in session. The raw token itself is never stored anywhere
 *    by the admin code.
 *
 * @return string
 */
function adminCurrentSessionHash(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }

    if (!defined('CUERCODE_SESSION_TOKEN_KEY') || !isset($_SESSION[CUERCODE_SESSION_TOKEN_KEY])) {
        return '';
    }

    $token = (string) $_SESSION[CUERCODE_SESSION_TOKEN_KEY];
    if ($token === '') {
        return '';
    }

    return hash('sha256', $token);
}

/**
 * 👤 The columns the access decision needs, read fresh (plan §6.1).
 *
 * @param int $userID tblUsers.userID
 * @return array|null userID, roleID, status, emailVerifiedAt, hasPassword (0/1),
 *                    email, displayName - or null when it cannot be read
 */
function adminLoadAccessRow(int $userID): ?array
{
    if ($userID <= 0 || !function_exists('dbQuery')) {
        return null;
    }

    try {
        $stmt = dbQuery(
            'SELECT `userID`, `roleID`, `status`, `emailVerifiedAt`, '
            . "(`passwordHash` IS NOT NULL AND `passwordHash` <> '') AS `hasPassword`, "
            . '`email`, `displayName` '
            . 'FROM `tblUsers` WHERE `userID` = ? LIMIT 1',
            'i',
            [$userID]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Admin access check could not read the account', 'userID: ' . $userID . ' | ' . get_class($e));
        }
        return null;
    }

    if (!is_array($row)) {
        return null;
    }

    return $row;
}

/**
 * 🚫 Show the ordinary "page not found" page and stop (plan §6.1: the admin
 *    area does not reveal itself to signed-in people who are not admins).
 *
 * @return never
 */
function adminRenderNotFoundAndExit(): never
{
    http_response_code(404);

    $notFoundPage = '';
    if (defined('CUERCODE_PUBLIC_ROOT')) {
        $notFoundPage = CUERCODE_PUBLIC_ROOT . 'error' . DIRECTORY_SEPARATOR . '404.php';
    }

    if ($notFoundPage !== '' && is_file($notFoundPage)) {
        require $notFoundPage;
    } else {
        echo 'Page not found.';
    }

    exit;
}

/**
 * 🚪 THE GATE - the first call on every admin page (plan §6.1, §7.1).
 *
 *   - not signed in      → 302 to /login/?next=<this admin page>;
 *   - signed in, not a Global Admin (any other role, pending, suspended,
 *     unverified, or the account could not be read) → the normal 404 page,
 *     plus one activity-log row "admin_denied" naming the user;
 *   - Global Admin       → returns who they are (and sends the §7.4 headers).
 *
 * Nothing is read from the request (no form fields, no query values) before
 * this decision, and nothing here trusts an API key: only the browser session.
 *
 * @param string $selfPath This page's own path, for example "/admin/upgrades/"
 * @return array{userID: int, email: string, displayName: string, sessionHash: string, hasPassword: bool}
 */
function requireGlobalAdminPage(string $selfPath): array
{
    adminSendSecurityHeaders();

    $safeSelfPath = adminSafeNextPath($selfPath, '/admin/');

    $currentUserRow = null;
    if (function_exists('currentUser')) {
        $currentUserRow = currentUser();
    }

    $accessRow = null;
    if ($currentUserRow !== null && isset($currentUserRow['userID'])) {
        $accessRow = adminLoadAccessRow((int) $currentUserRow['userID']);
    }

    $decision = adminAccessDecision($currentUserRow, $accessRow);

    if ($decision === 'not_signed_in') {
        header('Location: /login/?next=' . rawurlencode($safeSelfPath), true, 302);
        exit;
    }

    if ($decision !== 'admin') {
        // 📝 F1 (plan §3): logActivity() cannot see the signed-in user by
        //    itself, so the user is passed as the entity instead.
        if (function_exists('logActivity')) {
            logActivity(
                'admin_denied',
                'A signed-in account without Global Admin access asked for an admin page',
                'user',
                (int) $currentUserRow['userID'],
                ['path' => $safeSelfPath]
            );
        }
        adminRenderNotFoundAndExit();
    }

    $displayName = '';
    if (isset($accessRow['displayName']) && $accessRow['displayName'] !== null) {
        $displayName = (string) $accessRow['displayName'];
    }

    $admin = [
        'userID'      => (int) $accessRow['userID'],
        'email'       => (string) $accessRow['email'],
        'displayName' => $displayName,
        'sessionHash' => adminCurrentSessionHash(),
        'hasPassword' => ((int) $accessRow['hasPassword'] === 1),
    ];

    adminRecordFirstSeen($admin);

    return $admin;
}

/**
 * 👥 How many WORKING Global Admins there are (role 1, active, email
 *    checked).
 *
 * @return int The count, or -1 when it could not be read
 */
function adminCountGlobalAdmins(): int
{
    if (!function_exists('dbQuery')) {
        return -1;
    }

    try {
        $stmt = dbQuery(
            'SELECT COUNT(*) AS `adminCount` FROM `tblUsers` '
            . "WHERE `roleID` = ? AND `status` = 'active' AND `emailVerifiedAt` IS NOT NULL",
            'i',
            [CUERCODE_ROLE_GLOBAL_ADMIN]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        return -1;
    }

    if (!is_array($row)) {
        return -1;
    }

    return (int) $row['adminCount'];
}

// ==============================================================================
// 🗄️ DATABASE + REQUEST: password re-confirmation (plan §6.4)
// ==============================================================================

/**
 * ⚙️ The two limits from the settings, made safe.
 *
 * @return array{idle: int, max: int} Minutes
 */
function adminReauthConfiguredLimits(): array
{
    $idle = CUERCODE_ADMIN_REAUTH_IDLE_DEFAULT;
    $max  = CUERCODE_ADMIN_REAUTH_MAX_DEFAULT;

    if (function_exists('getConfig')) {
        $idle = adminWholeMinutes(getConfig('admin_reauth_idle_minutes', CUERCODE_ADMIN_REAUTH_IDLE_DEFAULT), CUERCODE_ADMIN_REAUTH_IDLE_DEFAULT);
        $max  = adminWholeMinutes(getConfig('admin_reauth_max_minutes', CUERCODE_ADMIN_REAUTH_MAX_DEFAULT), CUERCODE_ADMIN_REAUTH_MAX_DEFAULT);
    }

    return adminReauthLimits($idle, $max);
}

/**
 * 🧾 The stored re-confirmation state, or null.
 *
 * @return array|null
 */
function adminReauthStoredState(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    if (!isset($_SESSION[CUERCODE_ADMIN_REAUTH_SESSION_KEY]) || !is_array($_SESSION[CUERCODE_ADMIN_REAUTH_SESSION_KEY])) {
        return null;
    }

    return $_SESSION[CUERCODE_ADMIN_REAUTH_SESSION_KEY];
}

/**
 * 🔓 Is editing unlocked for this admin right now?
 *
 * @param array $admin From requireGlobalAdminPage()
 * @return bool
 */
function adminReauthIsCurrent(array $admin): bool
{
    $limits = adminReauthConfiguredLimits();

    return adminReauthIsFresh(
        adminReauthStoredState(),
        (int) $admin['userID'],
        (string) $admin['sessionHash'],
        time(),
        $limits['idle'],
        $limits['max']
    );
}

/**
 * ⏳ Seconds of editing left for this admin (0 = locked).
 *
 * @param array $admin From requireGlobalAdminPage()
 * @return int
 */
function adminReauthSecondsLeftForAdmin(array $admin): int
{
    $limits = adminReauthConfiguredLimits();

    return adminReauthSecondsLeft(
        adminReauthStoredState(),
        (int) $admin['userID'],
        (string) $admin['sessionHash'],
        time(),
        $limits['idle'],
        $limits['max']
    );
}

/**
 * ✅ Remember a successful password confirmation for this user + session.
 *
 * @param array $admin From requireGlobalAdminPage()
 * @return void
 */
function adminReauthStore(array $admin): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $now = time();
    $_SESSION[CUERCODE_ADMIN_REAUTH_SESSION_KEY] = [
        'userID'      => (int) $admin['userID'],
        'sessionHash' => (string) $admin['sessionHash'],
        'confirmedAt' => $now,
        'lastUsedAt'  => $now,
    ];
}

/**
 * 🔄 After each successful save: restart the idle clock (never the absolute
 *    one). Does nothing unless the state is still fresh.
 *
 * @param array $admin From requireGlobalAdminPage()
 * @return void
 */
function adminReauthTouch(array $admin): void
{
    if (!adminReauthIsCurrent($admin)) {
        return;
    }

    $_SESSION[CUERCODE_ADMIN_REAUTH_SESSION_KEY]['lastUsedAt'] = time();
}

/**
 * 🔒 Forget the password confirmation (lock editing again).
 *
 * @return void
 */
function adminReauthClear(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    unset($_SESSION[CUERCODE_ADMIN_REAUTH_SESSION_KEY]);
}

/**
 * 🔢 Wrong passwords typed on the confirm page so far in THIS session, for
 *    this user. A count saved for a different user or session counts as 0.
 *
 * @param array $admin From requireGlobalAdminPage()
 * @return int
 */
function adminReauthFailureCount(array $admin): int
{
    if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION[CUERCODE_ADMIN_REAUTH_FAILURES_KEY])) {
        return 0;
    }

    $stored = $_SESSION[CUERCODE_ADMIN_REAUTH_FAILURES_KEY];

    if (!is_array($stored) || !isset($stored['userID'], $stored['sessionHash'], $stored['count'])) {
        return 0;
    }

    if ((int) $stored['userID'] !== (int) $admin['userID'] || !hash_equals((string) $stored['sessionHash'], (string) $admin['sessionHash'])) {
        return 0;
    }

    return max(0, (int) $stored['count']);
}

/**
 * 📝 Save the wrong-password count for this user + session.
 *
 * @param array $admin From requireGlobalAdminPage()
 * @param int   $count New count (0 clears it)
 * @return void
 */
function adminReauthSetFailureCount(array $admin, int $count): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    if ($count <= 0) {
        unset($_SESSION[CUERCODE_ADMIN_REAUTH_FAILURES_KEY]);
        return;
    }

    $_SESSION[CUERCODE_ADMIN_REAUTH_FAILURES_KEY] = [
        'userID'      => (int) $admin['userID'],
        'sessionHash' => (string) $admin['sessionHash'],
        'count'       => $count,
    ];
}

/**
 * 🔑 Check a re-typed password against the account (plan §6.4).
 *
 * A wrong password also counts toward the ordinary account lock-out
 * (recordFailedLogin()), exactly like a wrong password at sign-in. While the
 * account is locked out, even the right password is refused. The password is
 * never stored, logged or returned - only "right" or "wrong".
 *
 * @param int    $userID   The admin's userID
 * @param string $password What was typed
 * @return array{ok: bool, locked: bool, unavailable: bool}
 */
function adminVerifyReauthPassword(int $userID, #[\SensitiveParameter] string $password): array
{
    $result = ['ok' => false, 'locked' => false, 'unavailable' => false];

    if ($userID <= 0 || !function_exists('dbQuery') || !function_exists('verifyPassword')) {
        $result['unavailable'] = true;
        return $result;
    }

    try {
        $stmt = dbQuery(
            'SELECT `passwordHash`, `failedLoginCount`, `lockedUntil`, '
            . '(`lockedUntil` IS NOT NULL AND `lockedUntil` > NOW()) AS `isLocked` '
            . "FROM `tblUsers` WHERE `userID` = ? AND `status` = 'active' LIMIT 1",
            'i',
            [$userID]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Admin password confirmation could not read the account', 'userID: ' . $userID . ' | ' . get_class($e));
        }
        $result['unavailable'] = true;
        return $result;
    }

    if (!is_array($row)) {
        $result['unavailable'] = true;
        return $result;
    }

    $storedHash = '';
    if ($row['passwordHash'] !== null) {
        $storedHash = (string) $row['passwordHash'];
    }

    // 🕐 Always pay the hashing cost, even when locked, so timing says nothing.
    $passwordOk = verifyPassword($password, $storedHash);
    $isLocked   = ((int) $row['isLocked'] === 1);

    if ($isLocked) {
        $result['locked'] = true;
        return $result;
    }

    if (!$passwordOk) {
        if (function_exists('recordFailedLogin')) {
            recordFailedLogin($userID, (int) $row['failedLoginCount'] + 1);
        }
        return $result;
    }

    // ✅ Right password: reset the sign-in failure counter, as sign-in does.
    try {
        $resetStmt = dbQuery(
            'UPDATE `tblUsers` SET `failedLoginCount` = 0, `lockedUntil` = NULL WHERE `userID` = ?',
            'i',
            [$userID]
        );
        $resetStmt->close();
    } catch (\Throwable $e) {
        // Non-fatal: the confirmation itself still succeeded.
    }

    $result['ok'] = true;

    return $result;
}

// ==============================================================================
// 🗄️ DATABASE + REQUEST: checking a POST (plan §6.3, §6.4)
// ==============================================================================

/**
 * 🛡️ Is this POST's CSRF token right? FAILS CLOSED when the CSRF functions
 *    are not loaded (copied from accountPagePostCsrfValid()).
 *
 * @return bool
 */
function adminPostCsrfValid(): bool
{
    $token = '';
    if (isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])) {
        $token = $_POST['csrf_token'];
    }

    if (!function_exists('validateCSRFToken')) {
        return false;
    }

    return validateCSRFToken($token);
}

/**
 * 🛡️ Every admin POST goes through this, in this order (plan §6.3, §6.4):
 *    1. the CSRF token (fails closed);
 *    2. the Origin header, when the browser sent one, must be this site;
 *    3. when $needsFreshPassword: a fresh password confirmation.
 *
 * @param array $admin              From requireGlobalAdminPage()
 * @param bool  $needsFreshPassword True for every change (false only for the
 *                                  confirm-password form itself and "lock editing")
 * @return array{ok: bool, reason: string, message: string}
 */
function adminCheckPost(array $admin, bool $needsFreshPassword): array
{
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return ['ok' => false, 'reason' => 'method', 'message' => 'Changes can only be sent with the form on this page.'];
    }

    if (!adminPostCsrfValid()) {
        return ['ok' => false, 'reason' => 'csrf', 'message' => 'This form had expired. Please reload the page and try again.'];
    }

    $origin = null;
    if (isset($_SERVER['HTTP_ORIGIN']) && is_string($_SERVER['HTTP_ORIGIN'])) {
        $origin = $_SERVER['HTTP_ORIGIN'];
    }

    $host = '';
    if (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])) {
        $host = $_SERVER['HTTP_HOST'];
    }

    if (!adminOriginIsAllowed($origin, $host)) {
        return ['ok' => false, 'reason' => 'origin', 'message' => 'This form was sent from another website, so it was refused.'];
    }

    if ($needsFreshPassword && !adminReauthIsCurrent($admin)) {
        return ['ok' => false, 'reason' => 'reauth', 'message' => 'Please confirm your password again and re-enter your changes.'];
    }

    return ['ok' => true, 'reason' => '', 'message' => ''];
}

// ==============================================================================
// 🗄️ REQUEST: one-time messages after a redirect (Post/Redirect/Get)
// ==============================================================================

/**
 * 💬 Keep a message to show on the next page view.
 *
 * @param string             $type  'success', 'danger', 'warning' or 'info'
 * @param string             $text  Plain text (escaped when shown)
 * @param array<int, string> $lines Optional extra lines (escaped when shown)
 * @return void
 */
function adminFlashAdd(string $type, string $text, array $lines = []): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    if (!in_array($type, ['success', 'danger', 'warning', 'info'], true)) {
        $type = 'info';
    }

    if (!isset($_SESSION[CUERCODE_ADMIN_FLASH_KEY]) || !is_array($_SESSION[CUERCODE_ADMIN_FLASH_KEY])) {
        $_SESSION[CUERCODE_ADMIN_FLASH_KEY] = [];
    }

    $cleanLines = [];
    foreach ($lines as $line) {
        $cleanLines[] = (string) $line;
    }

    $_SESSION[CUERCODE_ADMIN_FLASH_KEY][] = ['type' => $type, 'text' => $text, 'lines' => $cleanLines];
}

/**
 * 💬 Take (and forget) every waiting message.
 *
 * @return array<int, array{type: string, text: string, lines: array<int, string>}>
 */
function adminFlashTake(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION[CUERCODE_ADMIN_FLASH_KEY]) || !is_array($_SESSION[CUERCODE_ADMIN_FLASH_KEY])) {
        return [];
    }

    $messages = $_SESSION[CUERCODE_ADMIN_FLASH_KEY];
    unset($_SESSION[CUERCODE_ADMIN_FLASH_KEY]);

    return $messages;
}

/**
 * ↪️ Redirect (303 "see other", so a reload never re-sends a form) and stop.
 *
 * @param string $path A path already checked by adminSafeNextPath() (or "/login/...")
 * @return never
 */
function adminRedirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

// ==============================================================================
// 🗄️ DATABASE: the audit trail (plan §6.7)
// ==============================================================================

/**
 * 🔎 Does a table exist in this database? Remembered for the rest of the
 *    request (so a page asks the database at most once per table).
 *
 * @param string $table Plain table name
 * @param bool   $forget True to drop the remembered answer (after an upgrade)
 * @return bool False also when the question could not be answered
 */
function adminTableExists(string $table, bool $forget = false): bool
{
    static $known = [];

    if ($forget) {
        unset($known[$table]);
    }

    if (array_key_exists($table, $known)) {
        return $known[$table];
    }

    if (!function_exists('dbQuery')) {
        return false;
    }

    try {
        $stmt = dbQuery(
            'SELECT COUNT(*) AS `tableCount` FROM `INFORMATION_SCHEMA`.`TABLES` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ?',
            's',
            [$table]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        return false;
    }

    $exists = (is_array($row) && (int) $row['tableCount'] > 0);
    $known[$table] = $exists;

    return $exists;
}

/**
 * 🌍 The requester's address and browser, for an audit row.
 *
 * @return array{requestIP: string|null, userAgent: string|null}
 */
function adminAuditRequestContext(): array
{
    $requestIP = null;
    if (function_exists('getClientIP')) {
        $requestIP = getClientIP();
    } elseif (isset($_SERVER['REMOTE_ADDR'])) {
        $requestIP = (string) $_SERVER['REMOTE_ADDR'];
    }

    $userAgent = null;
    if (isset($_SERVER['HTTP_USER_AGENT'])) {
        $userAgent = (string) $_SERVER['HTTP_USER_AGENT'];
    }

    return ['requestIP' => $requestIP, 'userAgent' => $userAgent];
}

/**
 * 🧾 An audit entry for something a signed-in admin did.
 *
 * @param array       $admin      From requireGlobalAdminPage()
 * @param string      $action     One of adminAuditActionNames()
 * @param string      $entityType For example 'user', 'schema', 'setting'
 * @param string|null $entityKey  For example the userID or a setting key
 * @param string      $outcome    'success', 'refused' or 'failed'
 * @param array       $changes    From adminAuditChanges() (never raw secrets)
 * @param string|null $reason     Admin-typed reason, when one is needed
 * @return array
 */
function adminAuditEntryForAdmin(array $admin, string $action, string $entityType, ?string $entityKey, string $outcome, array $changes = [], ?string $reason = null): array
{
    $context = adminAuditRequestContext();

    return [
        'actorType'   => 'admin',
        'actorUserID' => (int) $admin['userID'],
        'actorLabel'  => (string) $admin['email'],
        'action'      => $action,
        'entityType'  => $entityType,
        'entityKey'   => $entityKey,
        'outcome'     => $outcome,
        'changes'     => $changes,
        'reason'      => $reason,
        'requestIP'   => $context['requestIP'],
        'userAgent'   => $context['userAgent'],
    ];
}

/**
 * ✍️ Write one row to tblAdminAuditLog. Uses the SAME database connection as
 *    the rest of the request (dbQuery()), so when the caller has started a
 *    transaction the row is part of it (plan §6.7: a change never exists
 *    without its audit row). Append-only: nothing in the code updates or
 *    deletes these rows.
 *
 * @param array $entry See adminAuditBuildRow()
 * @return bool True when the row was written
 */
function adminAuditWrite(array $entry): bool
{
    $row = adminAuditBuildRow($entry);

    if ($row === null || !function_exists('dbQuery')) {
        if (function_exists('logError')) {
            logError('error', 'Admin audit entry refused (unknown action, actor or missing label)', '');
        }
        return false;
    }

    try {
        $stmt = dbQuery(
            'INSERT INTO `tblAdminAuditLog` '
            . '(`actorUserID`, `actorType`, `actorLabel`, `action`, `entityType`, `entityKey`, `outcome`, `changesJSON`, `reason`, `requestIP`, `userAgent`) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'issssssssss',
            [
                $row['actorUserID'],
                $row['actorType'],
                $row['actorLabel'],
                $row['action'],
                $row['entityType'],
                $row['entityKey'],
                $row['outcome'],
                $row['changesJSON'],
                $row['reason'],
                $row['requestIP'],
                $row['userAgent'],
            ]
        );
        $written = ($stmt->affected_rows === 1);
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Admin audit row could not be written', 'action: ' . $row['action'] . ' | ' . get_class($e));
        }
        return false;
    }

    return $written;
}

/**
 * ✍️ Write an audit row, or - ONLY while tblAdminAuditLog does not exist yet
 *    (a database still waiting for its upgrades) - an activity-log row
 *    instead (plan §6.7: only /admin/reauth/ and /admin/upgrades/ may act
 *    then, and they use this).
 *
 * @param array $entry See adminAuditBuildRow()
 * @return bool True when either row was written (the activity log cannot say, so true)
 */
function adminAuditOrFallback(array $entry): bool
{
    if (adminTableExists('tblAdminAuditLog')) {
        return adminAuditWrite($entry);
    }

    $row = adminAuditBuildRow($entry);
    if ($row === null) {
        return false;
    }

    if (!function_exists('logActivity')) {
        return false;
    }

    $entityID = null;
    if ($row['actorUserID'] !== null) {
        $entityID = (int) $row['actorUserID'];
    }

    logActivity(
        $row['action'],
        'Admin action recorded here because the admin change log table does not exist yet (database upgrade waiting)',
        'user',
        $entityID,
        ['auditFallback' => true, 'outcome' => $row['outcome'], 'entityType' => $row['entityType'], 'entityKey' => $row['entityKey']]
    );

    return true;
}

/**
 * 👀 "How did this person become an admin?" (plan §6.2 step 4). A Global
 *    Admin made by hand in phpMyAdmin has no admin.grant row, so on their
 *    first admin page an admin.first_seen row (actor: system) is written -
 *    the log is never silent about it. One single INSERT ... WHERE NOT
 *    EXISTS, so two pages opened at once cannot normally write two. Checked
 *    once per session; skipped while the audit table does not exist yet.
 *
 * @param array $admin From requireGlobalAdminPage()
 * @return void
 */
function adminRecordFirstSeen(array $admin): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (isset($_SESSION[CUERCODE_ADMIN_FIRST_SEEN_KEY]) && (int) $_SESSION[CUERCODE_ADMIN_FIRST_SEEN_KEY] === (int) $admin['userID']) {
            return;
        }
    }

    if (!function_exists('dbQuery') || !adminTableExists('tblAdminAuditLog')) {
        return;
    }

    $userKey = (string) (int) $admin['userID'];
    $context = adminAuditRequestContext();
    $row     = adminAuditBuildRow([
        'actorType'  => 'system',
        'actorLabel' => 'system',
        'action'     => 'admin.first_seen',
        'entityType' => 'user',
        'entityKey'  => $userKey,
        'outcome'    => 'success',
        'reason'     => 'Global Admin with no recorded grant (for example made in phpMyAdmin) opened the admin area for the first time',
        'requestIP'  => $context['requestIP'],
        'userAgent'  => $context['userAgent'],
    ]);

    if ($row === null) {
        return;
    }

    try {
        $stmt = dbQuery(
            'INSERT INTO `tblAdminAuditLog` (`actorUserID`, `actorType`, `actorLabel`, `action`, `entityType`, `entityKey`, `outcome`, `reason`, `requestIP`, `userAgent`) '
            . 'SELECT NULL, ?, ?, ?, ?, ?, ?, ?, ?, ? FROM DUAL '
            . 'WHERE NOT EXISTS ('
            . 'SELECT 1 FROM `tblAdminAuditLog` '
            . "WHERE `entityType` = 'user' AND `entityKey` = ? AND `action` IN ('admin.grant', 'admin.first_seen')"
            . ')',
            'ssssssssss',
            [
                $row['actorType'],
                $row['actorLabel'],
                $row['action'],
                $row['entityType'],
                $row['entityKey'],
                $row['outcome'],
                $row['reason'],
                $row['requestIP'],
                $row['userAgent'],
                $userKey,
            ]
        );
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('warning', 'Admin first-seen audit row could not be written', get_class($e));
        }
        return;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION[CUERCODE_ADMIN_FIRST_SEEN_KEY] = (int) $admin['userID'];
    }
}

// ==============================================================================
// 🗄️ DATABASE: database upgrades from the admin area (plan §7.1 /admin/upgrades/)
// ==============================================================================

/**
 * 📦 Load the upgrade engine (not loaded by bootstrap.php).
 *
 * @return bool True when its functions are available
 */
function adminLoadMigrationEngine(): bool
{
    if (!function_exists('migrationStatus')) {
        $path = __DIR__ . DIRECTORY_SEPARATOR . 'fn_migrations.php';
        if (is_file($path)) {
            require_once $path;
        }
    }

    return function_exists('migrationStatus') && function_exists('migrationApplyPending');
}

/**
 * 📋 What the database upgrades look like right now, in a page-friendly shape.
 *    Changes nothing.
 *
 * @return array{ok: bool, pending: array<int, array{key: string, description: string}>,
 *               missingTables: array<int, string>, applied: array<int, array>,
 *               warnings: array<int, string>, lines: array<int, string>}
 */
function adminUpgradeOverview(): array
{
    $overview = ['ok' => false, 'pending' => [], 'missingTables' => [], 'applied' => [], 'warnings' => [], 'lines' => []];

    if (!adminLoadMigrationEngine() || !function_exists('getDB')) {
        $overview['lines'][] = 'The database upgrade tool could not be loaded.';
        return $overview;
    }

    try {
        $status = migrationStatus(getDB());
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Admin upgrades page could not read the upgrade status', get_class($e));
        }
        $overview['lines'][] = 'The upgrade status could not be read. Full detail was written to the server error log.';
        return $overview;
    }

    $overview['lines'] = migrationFormatReport('status', $status, false);

    if (!isset($status['status']) || $status['status'] !== 'ok') {
        return $overview;
    }

    // 📝 Plain-English descriptions come from each migration file.
    $descriptions = [];
    if (function_exists('migrationLoadAll') && function_exists('migrationDefaultDirectory')) {
        $loaded = migrationLoadAll(migrationDefaultDirectory());
        foreach ($loaded['migrations'] as $key => $migration) {
            if (is_array($migration['definition']) && isset($migration['definition']['description'])) {
                $descriptions[$key] = (string) $migration['definition']['description'];
            }
        }
    }

    foreach ($status['pending'] as $pendingKey) {
        $description = '';
        if (isset($descriptions[$pendingKey])) {
            $description = $descriptions[$pendingKey];
        }
        $overview['pending'][] = ['key' => (string) $pendingKey, 'description' => $description];
    }

    if (isset($status['baseline']['missingTables']) && is_array($status['baseline']['missingTables'])) {
        $overview['missingTables'] = $status['baseline']['missingTables'];
    }

    $overview['applied']  = $status['applied'];
    $overview['warnings'] = $status['warnings'];
    $overview['ok']       = true;

    return $overview;
}

/**
 * 🧰 Is the database ready for admin CHANGES (plan §6.7)? Only when the
 *    audit table exists and no upgrade is waiting - otherwise a change could
 *    happen without its audit row. Used by every admin page except
 *    /admin/reauth/ and /admin/upgrades/ (Phase 1C onwards).
 *
 * @return bool
 */
function adminDatabaseReadyForChanges(): bool
{
    if (!adminTableExists('tblAdminAuditLog')) {
        return false;
    }

    $overview = adminUpgradeOverview();

    return $overview['ok'] && $overview['pending'] === [] && $overview['missingTables'] === [];
}

/**
 * 🚀 Apply every waiting upgrade for a signed-in Global Admin and record it
 *    (audit action schema.migrate). Works before tblAdminAuditLog exists:
 *    the upgrade itself is recorded in tblSchemaMigrations ("admin:<userID>"),
 *    and the audit row is written to the new table once the upgrade has
 *    created it (or to the activity log if it still does not exist).
 *
 * The caller must already have checked the POST (CSRF, origin, fresh
 * password) with adminCheckPost().
 *
 * @param array $admin From requireGlobalAdminPage()
 * @return array{ok: bool, status: string, lines: array<int, string>, applied: array<int, string>}
 */
function adminRunUpgrades(array $admin): array
{
    $outcome = ['ok' => false, 'status' => 'failed', 'lines' => [], 'applied' => []];

    if (!adminLoadMigrationEngine() || !function_exists('getDB')) {
        $outcome['lines'][] = 'The database upgrade tool could not be loaded.';
        return $outcome;
    }

    try {
        $result = migrationApplyPending(getDB(), 'admin:' . (int) $admin['userID'], false);
    } catch (\Throwable $e) {
        $result = ['status' => 'failed', 'error' => get_class($e), 'migrations' => []];
    }

    $status = 'failed';
    if (isset($result['status']) && is_string($result['status'])) {
        $status = $result['status'];
    }

    // 🧾 The FULL technical detail goes to the error log only; the page shows
    //    the plain lines without raw database errors (as the installer does).
    if ($status !== 'ok' && function_exists('logError')) {
        logError('error', 'Database upgrade from the admin area did not finish', implode(' | ', migrationFormatReport('apply', $result, true)));
    }

    $appliedKeys = [];
    if (isset($result['migrations']) && is_array($result['migrations'])) {
        foreach ($result['migrations'] as $migration) {
            if (isset($migration['outcome']) && $migration['outcome'] === 'applied') {
                $appliedKeys[] = (string) $migration['key'];
            }
        }
    }

    // 🔄 The upgrade may just have created the audit table.
    adminTableExists('tblAdminAuditLog', true);

    $auditOutcome = 'failed';
    if ($status === 'ok') {
        $auditOutcome = 'success';
    } elseif ($status === 'busy' || $status === 'invalid' || $status === 'baseline_refused') {
        $auditOutcome = 'refused';
    }

    adminAuditOrFallback(adminAuditEntryForAdmin(
        $admin,
        'schema.migrate',
        'schema',
        'migrations',
        $auditOutcome,
        ['applied' => ['old' => null, 'new' => $appliedKeys]]
    ));

    $outcome['ok']      = ($status === 'ok');
    $outcome['status']  = $status;
    $outcome['lines']   = migrationFormatReport('apply', $result, false);
    $outcome['applied'] = $appliedKeys;

    return $outcome;
}

/**
 * 🌍 The admin display time zone (setting admin_display_timezone), checked.
 *
 * @return string A zone PHP knows; Europe/London when the setting is unusable
 */
function adminDisplayTimezone(): string
{
    $timezone = CUERCODE_ADMIN_DEFAULT_TIMEZONE;

    if (function_exists('getConfig')) {
        $configured = getConfig('admin_display_timezone', CUERCODE_ADMIN_DEFAULT_TIMEZONE);
        if (is_string($configured) && adminTimezoneIsValid(trim($configured))) {
            $timezone = trim($configured);
        }
    }

    return $timezone;
}

/**
 * 🤫 Does a setting hold a non-empty value? Used by the overview to say
 *    "Set" / "Not set" for secrets WITHOUT ever showing them. The value is
 *    only held in this function for the length of the test.
 *
 * @param string $key Setting key
 * @return bool
 */
function adminSettingHasValue(string $key): bool
{
    if (!function_exists('getConfig')) {
        return false;
    }

    $value = getConfig($key, '');

    if ($value === null || $value === false) {
        return false;
    }

    if (is_string($value)) {
        return trim($value) !== '';
    }

    return true;
}

// ==============================================================================
// 🗄️ DATABASE: the command-line tool (plan §6.2)
// ==============================================================================

/**
 * 👤 Who is running the command-line tool, as "cli:<name>" for the audit log.
 *    Same method as _includes/cli/migrate.php (the process owner first, then
 *    $USER, then the script file's owner).
 *
 * @return string
 */
function adminCliActorLabel(): string
{
    $osUser = '';

    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $userInfo = posix_getpwuid(posix_geteuid());
        if (is_array($userInfo) && isset($userInfo['name'])) {
            $osUser = (string) $userInfo['name'];
        }
    }

    if ($osUser === '') {
        $environmentUser = getenv('USER');
        if (is_string($environmentUser)) {
            $osUser = $environmentUser;
        }
    }

    if ($osUser === '') {
        $osUser = get_current_user();
    }

    $osUser = (string) preg_replace('/[^A-Za-z0-9._@-]/', '', $osUser);
    if ($osUser === '') {
        $osUser = 'unknown';
    }

    return 'cli:' . mb_substr($osUser, 0, 100);
}

/**
 * 🔎 Every account with this (normalised) email - normally 0 or 1.
 *
 * @param string $email Normalised email
 * @param bool   $forUpdate True inside a transaction: lock the row(s) until commit
 * @return array<int, array>|null Rows, or null on a database error
 */
function adminCliFindAccounts(string $email, bool $forUpdate = false): ?array
{
    if (!function_exists('dbQuery')) {
        return null;
    }

    $sql = 'SELECT `userID`, `email`, `roleID`, `status`, `emailVerifiedAt`, '
        . "(`passwordHash` IS NOT NULL AND `passwordHash` <> '') AS `hasPassword` "
        . 'FROM `tblUsers` WHERE `email` = ? LIMIT 2';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    try {
        $stmt = dbQuery($sql, 's', [$email]);
        $result = $stmt->get_result();
        $rows = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $rows[] = $row;
        }
        $stmt->close();
    } catch (\Throwable $e) {
        return null;
    }

    return $rows;
}

/**
 * 📋 Every account with the Global Admin role (working or not).
 *
 * @return array<int, array>|null Rows (userID, email, status, emailVerifiedAt), or null on error
 */
function adminCliListAdmins(): ?array
{
    if (!function_exists('dbQuery')) {
        return null;
    }

    try {
        $stmt = dbQuery(
            'SELECT `userID`, `email`, `status`, `emailVerifiedAt` FROM `tblUsers` WHERE `roleID` = ? ORDER BY `userID` ASC',
            'i',
            [CUERCODE_ROLE_GLOBAL_ADMIN]
        );
        $result = $stmt->get_result();
        $rows = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $rows[] = $row;
        }
        $stmt->close();
    } catch (\Throwable $e) {
        return null;
    }

    return $rows;
}

/**
 * ↩️ Roll back quietly (a failed rollback cannot make things worse).
 *
 * @param \mysqli $db Connection
 * @return void
 */
function adminRollbackQuietly(\mysqli $db): void
{
    try {
        $db->rollback();
    } catch (\Throwable $e) {
        // Nothing more can be done; the caller reports the original failure.
    }
}

/**
 * ✅ `grant`: make the account a Global Admin (and, with --activate, active
 *    with a checked email), and write the admin.grant audit row - in ONE
 *    transaction, so neither can happen without the other (plan §6.2).
 *
 * The account is read again, locked, inside the transaction and the decision
 * is re-made on that fresh copy, so nothing that changed in the meantime can
 * slip through.
 *
 * @param string $email      Normalised email
 * @param bool   $activate   --activate was given
 * @param string $actorLabel From adminCliActorLabel()
 * @return array{exitCode: int, message: string, warnings: array<int, string>}
 */
function adminCliGrant(string $email, bool $activate, string $actorLabel): array
{
    if (!function_exists('getDB') || !function_exists('dbQuery')) {
        return ['exitCode' => CUERCODE_ADMIN_CLI_EXIT_FAILED, 'message' => 'The database is not available.', 'warnings' => []];
    }

    $db = getDB();
    $transactionStarted = false;

    try {
        $db->begin_transaction();
        $transactionStarted = true;

        $rows = adminCliFindAccounts($email, true);
        if ($rows === null) {
            throw new \RuntimeException('account lookup failed');
        }

        $decision = adminGrantDecision($rows, $activate);
        if ($decision['outcome'] !== 'grant') {
            adminRollbackQuietly($db);
            $exitCode = CUERCODE_ADMIN_CLI_EXIT_REFUSED;
            if ($decision['outcome'] === 'nothing') {
                $exitCode = CUERCODE_ADMIN_CLI_EXIT_OK;
            }
            return ['exitCode' => $exitCode, 'message' => $decision['message'], 'warnings' => $decision['warnings']];
        }

        $row    = $rows[0];
        $userID = (int) $row['userID'];

        $before = ['roleID' => $row['roleID'], 'status' => (string) $row['status'], 'emailVerifiedAt' => $row['emailVerifiedAt']];
        $after  = ['roleID' => CUERCODE_ROLE_GLOBAL_ADMIN, 'status' => (string) $row['status'], 'emailVerifiedAt' => $row['emailVerifiedAt']];

        if ($decision['activates']) {
            // ✳️ --activate: whoever can run commands on the server already
            //    controls the site, so marking this email as checked is safe.
            $stmt = dbQuery(
                "UPDATE `tblUsers` SET `roleID` = ?, `status` = 'active', `emailVerifiedAt` = COALESCE(`emailVerifiedAt`, UTC_TIMESTAMP()) "
                . "WHERE `userID` = ? AND `status` IN ('pending', 'active') AND `passwordHash` IS NOT NULL",
                'ii',
                [CUERCODE_ROLE_GLOBAL_ADMIN, $userID]
            );
            $after['status'] = 'active';
            if ($row['emailVerifiedAt'] === null) {
                $after['emailVerifiedAt'] = 'set to now (UTC)';
            }
        } else {
            $stmt = dbQuery(
                "UPDATE `tblUsers` SET `roleID` = ? WHERE `userID` = ? AND `status` IN ('pending', 'active') AND `passwordHash` IS NOT NULL",
                'ii',
                [CUERCODE_ROLE_GLOBAL_ADMIN, $userID]
            );
        }
        $changedRows = $stmt->affected_rows;
        $stmt->close();

        if ($changedRows !== 1) {
            throw new \RuntimeException('the account changed while it was being updated');
        }

        $audited = adminAuditWrite([
            'actorType'   => 'cli',
            'actorUserID' => null,
            'actorLabel'  => $actorLabel,
            'action'      => 'admin.grant',
            'entityType'  => 'user',
            'entityKey'   => (string) $userID,
            'outcome'     => 'success',
            'changes'     => adminAuditChanges($before, $after, []),
        ]);

        if (!$audited) {
            throw new \RuntimeException('the audit row could not be written');
        }

        $db->commit();
    } catch (\Throwable $e) {
        if ($transactionStarted) {
            adminRollbackQuietly($db);
        }
        if (function_exists('logError')) {
            logError('error', 'Command-line Global Admin grant failed', get_class($e) . ': ' . $e->getMessage());
        }
        return ['exitCode' => CUERCODE_ADMIN_CLI_EXIT_FAILED, 'message' => 'Nothing was changed: ' . $e->getMessage() . '. Run it again.', 'warnings' => []];
    }

    $message = 'Done: the account is now a Global Admin.';
    if ($decision['activates']) {
        $message = 'Done: the account is now an active Global Admin with a checked email address.';
    }

    return ['exitCode' => CUERCODE_ADMIN_CLI_EXIT_OK, 'message' => $message, 'warnings' => $decision['warnings']];
}

/**
 * ❎ `revoke`: remove Global Admin access and write the admin.revoke audit
 *    row in ONE transaction. Every working admin row is locked first (SELECT
 *    ... FOR UPDATE), so two revokes at the same moment cannot both pass the
 *    "not the last admin" check (plan §6.5).
 *
 * @param string $email      Normalised email
 * @param string $actorLabel From adminCliActorLabel()
 * @return array{exitCode: int, message: string, warnings: array<int, string>}
 */
function adminCliRevoke(string $email, string $actorLabel): array
{
    if (!function_exists('getDB') || !function_exists('dbQuery')) {
        return ['exitCode' => CUERCODE_ADMIN_CLI_EXIT_FAILED, 'message' => 'The database is not available.', 'warnings' => []];
    }

    $db = getDB();
    $transactionStarted = false;

    try {
        $db->begin_transaction();
        $transactionStarted = true;

        // 🔒 Lock every working admin row until commit.
        $countStmt = dbQuery(
            'SELECT `userID` FROM `tblUsers` '
            . "WHERE `roleID` = ? AND `status` = 'active' AND `emailVerifiedAt` IS NOT NULL FOR UPDATE",
            'i',
            [CUERCODE_ROLE_GLOBAL_ADMIN]
        );
        $countResult = $countStmt->get_result();
        $effectiveCount = 0;
        while ($countResult->fetch_assoc() !== null) {
            $effectiveCount++;
        }
        $countStmt->close();

        $rows = adminCliFindAccounts($email, true);
        if ($rows === null) {
            throw new \RuntimeException('account lookup failed');
        }

        $decision = adminRevokeDecision($rows, $effectiveCount);
        if ($decision['outcome'] !== 'revoke') {
            adminRollbackQuietly($db);
            $exitCode = CUERCODE_ADMIN_CLI_EXIT_REFUSED;
            if ($decision['outcome'] === 'nothing') {
                $exitCode = CUERCODE_ADMIN_CLI_EXIT_OK;
            }
            return ['exitCode' => $exitCode, 'message' => $decision['message'], 'warnings' => []];
        }

        $userID = (int) $rows[0]['userID'];

        $stmt = dbQuery(
            'UPDATE `tblUsers` SET `roleID` = NULL WHERE `userID` = ? AND `roleID` = ?',
            'ii',
            [$userID, CUERCODE_ROLE_GLOBAL_ADMIN]
        );
        $changedRows = $stmt->affected_rows;
        $stmt->close();

        if ($changedRows !== 1) {
            throw new \RuntimeException('the account changed while it was being updated');
        }

        $audited = adminAuditWrite([
            'actorType'   => 'cli',
            'actorUserID' => null,
            'actorLabel'  => $actorLabel,
            'action'      => 'admin.revoke',
            'entityType'  => 'user',
            'entityKey'   => (string) $userID,
            'outcome'     => 'success',
            'changes'     => adminAuditChanges(['roleID' => CUERCODE_ROLE_GLOBAL_ADMIN], ['roleID' => null], []),
        ]);

        if (!$audited) {
            throw new \RuntimeException('the audit row could not be written');
        }

        $db->commit();
    } catch (\Throwable $e) {
        if ($transactionStarted) {
            adminRollbackQuietly($db);
        }
        if (function_exists('logError')) {
            logError('error', 'Command-line Global Admin revoke failed', get_class($e) . ': ' . $e->getMessage());
        }
        return ['exitCode' => CUERCODE_ADMIN_CLI_EXIT_FAILED, 'message' => 'Nothing was changed: ' . $e->getMessage() . '. Run it again.', 'warnings' => []];
    }

    return ['exitCode' => CUERCODE_ADMIN_CLI_EXIT_OK, 'message' => 'Done: Global Admin access was removed. It stops working on their next click.', 'warnings' => []];
}

// ==============================================================================
// 🎨 PURE: small shared pieces of page HTML (plan §7.5)
// ==============================================================================
// Every status is TEXT plus an icon - never colour alone - so it reads the
// same in dark mode, in each colourblind palette and with a screen reader.
// Everything printed is escaped with htmlspecialchars(..., ENT_QUOTES).
// ==============================================================================

/**
 * 🔤 Escape text for HTML (the one escaping rule every admin page uses).
 *
 * @param mixed $text Any value
 * @return string
 */
function adminEscape(mixed $text): string
{
    if ($text === null) {
        return '';
    }

    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

/**
 * 🚦 A status as text plus an icon, for example "✓ Set" or "✗ Not set". PURE.
 *
 * @param string $kind 'good', 'bad', 'warning', 'locked' or 'neutral'
 * @param string $text The words (escaped here)
 * @return string HTML
 */
function adminStatusHtml(string $kind, string $text): string
{
    $icon = 'fa-circle-info';
    $tone = 'text-body-secondary';

    if ($kind === 'good') {
        $icon = 'fa-circle-check';
        $tone = 'text-success-emphasis';
    } elseif ($kind === 'bad') {
        $icon = 'fa-circle-xmark';
        $tone = 'text-danger-emphasis';
    } elseif ($kind === 'warning') {
        $icon = 'fa-triangle-exclamation';
        $tone = 'text-warning-emphasis';
    } elseif ($kind === 'locked') {
        $icon = 'fa-lock';
        $tone = 'text-body-secondary';
    }

    return '<span class="' . $tone . '"><i class="fa-solid ' . $icon . ' me-1" aria-hidden="true"></i>' . adminEscape($text) . '</span>';
}

/**
 * 💬 The one-time messages (from adminFlashTake() or built on the page) as
 *    Bootstrap alerts. Errors use role="alert" and the first one is given
 *    focus by cuercode-admin.js (data-admin-focus); everything else uses
 *    role="status". PURE.
 *
 * @param array<int, array{type: string, text: string, lines?: array<int, string>}> $messages
 * @return string HTML ('' when there are none)
 */
function adminRenderMessages(array $messages): string
{
    $html = '';
    $focusGiven = false;

    foreach ($messages as $message) {
        $type = 'info';
        if (isset($message['type']) && in_array($message['type'], ['success', 'danger', 'warning', 'info'], true)) {
            $type = $message['type'];
        }

        $role = 'status';
        $icon = 'fa-circle-info';
        $focusAttribute = '';

        if ($type === 'success') {
            $icon = 'fa-circle-check';
        } elseif ($type === 'danger') {
            $role = 'alert';
            $icon = 'fa-triangle-exclamation';
            if (!$focusGiven) {
                $focusAttribute = ' tabindex="-1" data-admin-focus="1"';
                $focusGiven = true;
            }
        } elseif ($type === 'warning') {
            $icon = 'fa-triangle-exclamation';
        }

        $text = '';
        if (isset($message['text'])) {
            $text = (string) $message['text'];
        }

        $html .= '<div class="alert alert-' . $type . '" role="' . $role . '"' . $focusAttribute . '>';
        $html .= '<i class="fa-solid ' . $icon . ' me-1" aria-hidden="true"></i>' . adminEscape($text);

        if (isset($message['lines']) && is_array($message['lines']) && $message['lines'] !== []) {
            $html .= '<ul class="mb-0 mt-2 small">';
            foreach ($message['lines'] as $line) {
                $html .= '<li>' . adminEscape($line) . '</li>';
            }
            $html .= '</ul>';
        }

        $html .= '</div>';
    }

    return $html;
}
