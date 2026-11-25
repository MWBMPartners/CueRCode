<?php
/**
 * ==============================================================================
 * 🔐 CueRCode - Authentication Engine (Epic E3 stage 1, #11/#12/#14/#15)
 * ==============================================================================
 *
 * Path: _functions/fn_auth.php
 *
 * The core, framework-agnostic authentication engine for CueRCode user
 * accounts: registration, email verification, login/session issuance,
 * logout, and password reset. This file deliberately contains NO knowledge
 * of HTTP request/response shaping (no jsonResponse()/errorResponse() calls,
 * no reading of $_POST/$_SERVER for input) - every function takes plain
 * scalar arguments and returns a plain result array, so the engine is fully
 * unit-testable and reusable from both the REST API handlers
 * (_functions/fn_api_accounts.php) and a future first-party UI (Epic E3
 * later stage: login.php/register.php/account/).
 *
 * Every DB-touching function fails gracefully (never fatals) when
 * dbQuery()/getConfig() are unavailable, matching the fn_email.php
 * fail-open convention used elsewhere in this codebase.
 *
 * SECURITY POSTURE (see SECURITY.md and PROJECT_ROADMAP.md Epic E3):
 *   - Argon2id password hashing exclusively (fn_password.php) - no bcrypt, no md5/sha1.
 *   - Tokens (email verification, password reset) are random_bytes(32), stored
 *     ONLY as their SHA-256 hash, single-use (usedAt guard), and expiring.
 *   - NO user enumeration: registerUser()/loginUser()/requestPasswordReset()
 *     all return an IDENTICAL response shape regardless of whether the
 *     target email exists, is verified, is locked, or is suspended.
 *   - Account lockout after a configurable number of failed logins
 *     (security.max_login_attempts / security.lockout_duration, already
 *     seeded in seed_data.sql).
 *   - Session ID is regenerated on login (mitigates session fixation) AND a
 *     SEPARATE, independently-revocable database-backed session token is
 *     issued (tblUserSessions) - see issueUserSession().
 *   - All queries are MySQLi prepared statements (dbQuery() from db_connect.php).
 *
 * @package     CueRCode
 * @subpackage  Security
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E3 stage 1)
 * @see         fn_password.php  - hashPassword()/verifyPassword()/validatePasswordPolicy()
 * @see         fn_encryption.php - not used directly here (tokens are hashed, not encrypted)
 * @see         fn_email.php     - queueEmail() (verification/reset emails)
 * @see         fn_api_accounts.php - the HTTP handler layer that calls into this engine
 * @see         https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html
 * @see         https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html
 * @see         https://www.php.net/manual/en/function.random-bytes.php
 * @see         https://www.php.net/manual/en/function.session-regenerate-id.php
 */

// ==============================================================================
// 🚫 Direct access protection
// ==============================================================================
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $scriptFilename = $_SERVER['SCRIPT_FILENAME'];
} else {
    $scriptFilename = '';
}
if (basename($scriptFilename) === basename(__FILE__)) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied.');
}

// 🔀 #151: cuercodeClampInt() (PHP 8.6 clamp() where it exists, max(min()) otherwise).
//    Loaded here as well as by bootstrap.php so this file works when loaded on its
//    own (the tests and the cron runner do that). require_once does nothing twice.
require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_compat.php';

// ==============================================================================
// 🔧 Constants
// ==============================================================================

/**
 * @var int Email verification token time-to-live, in hours. Matches the
 * verification.php email template's own default expiryHours (24).
 *
 * Also read by fn_email.php emailQueueLinkLifetimes(), so the queue runner
 * skips an email whose link has expired instead of sending it (#123). Change
 * the lifetime HERE only.
 */
if (!defined('CUERCODE_EMAIL_VERIFICATION_TTL_HOURS')) {
    define('CUERCODE_EMAIL_VERIFICATION_TTL_HOURS', 24);
}

/**
 * @var int Password reset token time-to-live, in minutes. Matches the
 * password_reset.php email template's own default expiryMinutes (60).
 *
 * Also read by fn_email.php emailQueueLinkLifetimes(), so the queue runner
 * skips an email whose link has expired instead of sending it (#123). Change
 * the lifetime HERE only.
 */
if (!defined('CUERCODE_PASSWORD_RESET_TTL_MINUTES')) {
    define('CUERCODE_PASSWORD_RESET_TTL_MINUTES', 60);
}

/**
 * @var int Fallback max failed-login attempts before lockout, used only when
 * the 'max_login_attempts' tblSettings value is unavailable. The seeded
 * default for that setting is also 5 (seed_data.sql, group 'security').
 */
if (!defined('CUERCODE_DEFAULT_MAX_LOGIN_ATTEMPTS')) {
    define('CUERCODE_DEFAULT_MAX_LOGIN_ATTEMPTS', 5);
}

/**
 * @var int Fallback lockout duration in seconds, used only when the
 * 'lockout_duration' tblSettings value is unavailable. The seeded default
 * for that setting is also 900 (15 minutes; seed_data.sql, group 'security').
 */
if (!defined('CUERCODE_DEFAULT_LOCKOUT_DURATION_SECONDS')) {
    define('CUERCODE_DEFAULT_LOCKOUT_DURATION_SECONDS', 900);
}

/**
 * @var int Fallback session lifetime in seconds, used only when the
 * 'session_lifetime' tblSettings value is unavailable. The seeded default
 * for that setting is also 3600 (seed_data.sql, group 'security').
 */
if (!defined('CUERCODE_DEFAULT_SESSION_LIFETIME_SECONDS')) {
    define('CUERCODE_DEFAULT_SESSION_LIFETIME_SECONDS', 3600);
}

/**
 * @var string $_SESSION key holding the current tblUserSessions.sessionID
 * (the database-backed session token) - deliberately namespaced to avoid
 * collisions with any other session data (mirrors the fn_csrf.php convention).
 */
if (!defined('CUERCODE_SESSION_TOKEN_KEY')) {
    define('CUERCODE_SESSION_TOKEN_KEY', 'cuercode_user_session_id');
}

// ==============================================================================
// 🎲 generateRandomToken() - Cryptographically random bearer token
// ==============================================================================

/**
 * 🎲 Generate a cryptographically secure random token (64 hex characters)
 *
 * Used for BOTH the database-backed session identifier (tblUserSessions.sessionID)
 * and single-use email-verification/password-reset bearer tokens. 32 random
 * bytes -> 64 hex characters, matching every CHAR(64) token/session column in
 * the E3 schema.
 *
 * @return string 64-character lowercase hexadecimal string
 *
 * @see https://www.php.net/manual/en/function.random-bytes.php
 * @see https://www.php.net/manual/en/function.bin2hex.php
 */
function generateRandomToken(): string
{
    return bin2hex(random_bytes(32));
}

// ==============================================================================
// 🔐 hashToken() - SHA-256 hash of a bearer token, for at-rest storage
// ==============================================================================

/**
 * 🔐 Hash a raw bearer token for storage (tblEmailVerifications.tokenHash,
 * tblPasswordResets.tokenHash, tblInvitations.tokenHash)
 *
 * The raw token is emailed to the user ONCE and never persisted - only this
 * hash is stored, so a database compromise alone cannot be used to forge a
 * valid verification/reset link.
 *
 * @param string $rawToken The raw token (as produced by generateRandomToken())
 *
 * @return string 64-character hex SHA-256 hash
 *
 * @see https://www.php.net/manual/en/function.hash.php
 */
function hashToken(string $rawToken): string
{
    return hash('sha256', $rawToken);
}

// ==============================================================================
// 🔢 computeLockoutExpiry() - Pure lockout-window calculation
// ==============================================================================

/**
 * 🔢 Compute the Unix timestamp an account should remain locked until, given
 * the failed-login count immediately AFTER this attempt.
 *
 * Pure function - no I/O, no session/database access - fully unit-testable.
 *
 * @param int $failedCountAfterThisAttempt The failedLoginCount value AFTER incrementing for this failure
 * @param int $maxAttempts                 The configured max_login_attempts threshold
 * @param int $lockoutDurationSeconds      The configured lockout_duration, in seconds
 * @param int $nowUnixTimestamp            The current Unix timestamp (injected for testability)
 *
 * @return int|null Unix timestamp the lock expires at, or null if the account should not (yet) be locked
 *
 * @example
 *   computeLockoutExpiry(5, 5, 900, 1000000000); // 1000000900 (locked)
 *   computeLockoutExpiry(4, 5, 900, 1000000000); // null (not yet locked)
 */
function computeLockoutExpiry(
    int $failedCountAfterThisAttempt,
    int $maxAttempts,
    int $lockoutDurationSeconds,
    int $nowUnixTimestamp
): ?int {
    // 📌 A non-positive threshold disables lockout entirely (defensive - the
    // seeded default is 5, but an admin could theoretically misconfigure 0).
    if ($maxAttempts <= 0) {
        return null;
    }

    if ($failedCountAfterThisAttempt < $maxAttempts) {
        return null;
    }

    return $nowUnixTimestamp + max(0, $lockoutDurationSeconds);
}

// ==============================================================================
// 🔗 buildAccountActionUrl() - Build a tokenised verification/reset URL
// ==============================================================================

/**
 * 🔗 Build the absolute URL for an emailed account-action link.
 *
 * ⚠️ The target pages themselves (public_html/verify-email.php,
 * public_html/reset-password.php) are UI - deferred to a later E3 stage per
 * this stage's explicit scope. This stage only needs to emit a URL of the
 * correct SHAPE for the email templates; the (deferred) UI stage must
 * implement routes matching $action below and call the corresponding
 * verifyEmailToken()/resetPasswordWithToken() engine function with the
 * `token` query parameter.
 *
 * @param string $action   'verify-email' or 'reset-password'
 * @param string $rawToken The raw (unhashed) token to embed
 *
 * @return string Absolute URL, e.g. 'https://cuercode.net/verify-email?token=...'
 */
function buildAccountActionUrl(string $action, string $rawToken): string
{
    $baseUrl = 'https://cuercode.net';

    if (function_exists('getConfig')) {
        $configuredUrl = getConfig('site_url', $baseUrl);
        if (is_string($configuredUrl) && $configuredUrl !== '') {
            $baseUrl = rtrim($configuredUrl, '/');
        }
    }

    // @see https://www.php.net/manual/en/function.rawurlencode.php
    return $baseUrl . '/' . $action . '?token=' . rawurlencode($rawToken);
}

// ==============================================================================
// 🎫 issueEmailVerificationLink() - ONE place that makes a verification link (#128)
// ==============================================================================

/**
 * 🎫 Create a fresh verification token for a user and queue the email carrying it.
 *
 * Used by registerUser() (first link) and requestNewVerificationLink() (a
 * replacement link, #128), so there is exactly one way a verification link is
 * made. Only the SHA-256 hash of the token is stored; the raw token travels
 * only inside the queued email and is never logged.
 *
 * When $invalidateEarlier is true, every OTHER still-unused token for the same
 * user is marked used straight after the new one is saved, so only the newest
 * link works. (The new row is saved first, so a failure part-way never leaves
 * the person with no working link at all.)
 *
 * @param int         $userID            The pending account
 * @param string      $email             Normalised (lower-case) address to send to
 * @param string|null $displayName       Display name for the greeting, if any
 * @param bool        $invalidateEarlier Burn the person's older unused tokens
 *
 * @return bool True when the token was saved (the email is then queued, if the
 *              email subsystem is loaded); false when saving the token failed.
 *
 * @see registerUser()
 * @see requestNewVerificationLink()
 */
function issueEmailVerificationLink(int $userID, string $email, ?string $displayName, bool $invalidateEarlier): bool
{
    if (!function_exists('dbQuery')) {
        return false;
    }

    $rawToken  = generateRandomToken();
    $tokenHash = hashToken($rawToken);

    try {
        $verifyStmt = dbQuery(
            'INSERT INTO `tblEmailVerifications` (`userID`, `tokenHash`, `expiresAt`) '
            . 'VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))',
            'isi',
            [$userID, $tokenHash, CUERCODE_EMAIL_VERIFICATION_TTL_HOURS]
        );
        $newVerificationID = (int) $verifyStmt->insert_id;
        $verifyStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            // 🙈 The driver text can echo values back, so only the fact is logged.
            logError('error', 'Verification token insert failed', $e->getMessage());
        }
        // The account row exists but has no way to self-verify yet - logged
        // for operator follow-up.
        return false;
    }

    // 🔥 #128: burn the earlier unused tokens (never the one just made).
    //    Review fix M4: only tokens with a SMALLER number are burned (`<`, not
    //    `<>`). Two requests at the same moment each make a token; with `<>`
    //    each would burn the other's, leaving the person with no working link.
    //    With `<` the higher-numbered one always survives.
    if ($invalidateEarlier) {
        try {
            $burnStmt = dbQuery(
                'UPDATE `tblEmailVerifications` SET `usedAt` = NOW() '
                . 'WHERE `userID` = ? AND `usedAt` IS NULL AND `verificationID` < ?',
                'ii',
                [$userID, $newVerificationID]
            );
            $burnStmt->close();
        } catch (\Throwable $e) {
            if (function_exists('logError')) {
                logError('warning', 'Earlier verification tokens could not be retired', $e->getMessage());
            }
        }
    }

    // 📧 Queue the verification email (fails open/silently if fn_email.php
    // is not loaded or the email subsystem is not configured - see fn_email.php).
    if (function_exists('queueEmail')) {
        $verificationUrl = buildAccountActionUrl('verify-email', $rawToken);

        queueEmail(
            $email,
            'verification',
            [
                'recipientName'   => $displayName,
                'verificationUrl' => $verificationUrl,
                'expiryHours'     => CUERCODE_EMAIL_VERIFICATION_TTL_HOURS,
            ],
            null,
            $displayName
        );
    }

    return true;
}

// ==============================================================================
// 📝 registerUser() - Register a new (pending) account
// ==============================================================================

/**
 * 📝 Register a new user account and queue an email-verification message.
 *
 * ANTI-ENUMERATION: this function ALWAYS returns the identical success shape
 * whether the email address was brand new, already registered, or a
 * concurrent duplicate race occurred - the caller (and thus any external
 * observer) cannot distinguish "new account created" from "that email
 * already has an account" from these two cases. No verification email is
 * sent for an already-registered email (we cannot confirm the requester
 * currently controls it, and re-sending would be a mild self-inflicted spam
 * vector), which is likewise unobservable from the response. TIMING is also
 * now equalised (F-021): the Argon2id hash is computed UNCONDITIONALLY
 * before the existence check runs, so the "already registered" branch pays
 * the identical ~80-90ms cost as the "new email" branch instead of
 * returning immediately after a sub-millisecond SELECT - closing a
 * previously measurable response-time side-channel.
 *
 * @param string      $email         Candidate email address (validated by the caller/handler
 *                                     via validateEmail() before this is called)
 * @param string      $plainPassword Candidate plaintext password (validated by the caller/handler
 *                                     via validatePasswordPolicy() before this is called)
 * @param string|null $displayName   Optional display name
 *
 * @return array{success: bool, httpCode: int, errorCode: string|null, message: string, data: array}
 *
 * @see verifyEmailToken()
 * @see fn_password.php - hashPassword()
 * @see fn_email.php - queueEmail()
 */
function registerUser(string $email, #[\SensitiveParameter] string $plainPassword, ?string $displayName = null): array
{
    $genericMessage = 'If this email address is available for registration, '
        . 'a verification link has been sent to it.';
    $genericSuccess = [
        'success'   => true,
        'httpCode'  => 201,
        'errorCode' => null,
        'message'   => $genericMessage,
        'data'      => [],
    ];

    if (!function_exists('dbQuery')) {
        return [
            'success'   => false,
            'httpCode'  => 503,
            'errorCode' => 'SERVICE_UNAVAILABLE',
            'message'   => 'Registration is temporarily unavailable. Please try again later.',
            'data'      => [],
        ];
    }

    $normalizedEmail = mb_strtolower(trim($email));

    if ($displayName !== null && trim($displayName) !== '') {
        $normalizedDisplayName = mb_substr(trim($displayName), 0, 100);
    } else {
        $normalizedDisplayName = null;
    }

    // 🔐 F-021 (register timing side-channel fix): hash the candidate
    // password (Argon2id, ~80-90ms at the configured cost parameters)
    // UNCONDITIONALLY here, BEFORE the existence check below - NOT only on
    // the "brand new email" branch as before. Previously the "email already
    // exists" branch returned immediately after a sub-millisecond indexed
    // SELECT while ONLY the "new email" branch paid the Argon2id cost,
    // producing an ~80-90ms measurable response-time gap over real HTTP
    // even though the two branches' RESPONSE BODIES were already identical
    // - an attacker could enumerate registered emails from timing alone.
    // Computing the hash first means BOTH branches now pay the identical
    // cost before either one returns, closing the side-channel while
    // preserving every other anti-enumeration property already in place.
    // See SECURITY.md F-021.
    try {
        $passwordHash = hashPassword($plainPassword);
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Password hashing failed during registration', $e->getMessage());
        }
        return [
            'success'   => false,
            'httpCode'  => 500,
            'errorCode' => 'HASH_FAILED',
            'message'   => 'Registration could not be completed. Please try again later.',
            'data'      => [],
        ];
    }

    // 🔍 Look up an existing account by email WITHOUT revealing the result
    // to the caller - only used internally to decide whether to insert.
    try {
        $existingStmt = dbQuery(
            'SELECT `userID` FROM `tblUsers` WHERE `email` = ? LIMIT 1',
            's',
            [$normalizedEmail]
        );
        $existingRow = $existingStmt->get_result()->fetch_assoc();
        $existingStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Registration lookup failed', $e->getMessage());
        }
        // 🛡️ Even an internal error must not distinguish itself from the
        // generic success shape at the response level - it is logged
        // server-side for operator follow-up instead.
        return $genericSuccess;
    }

    if ($existingRow !== null) {
        // ✅ Anti-enumeration: identical response AND (per the F-021 fix
        // above) identical timing - the Argon2id hash was already computed
        // before this check ran, so this branch's cost now matches the
        // "new email" branch below. No new email is sent.
        return $genericSuccess;
    }

    // 📌 New account - $passwordHash was already computed above (see the
    // F-021 comment), so it is reused here rather than re-hashed.
    try {
        $insertStmt = dbQuery(
            'INSERT INTO `tblUsers` (`email`, `passwordHash`, `displayName`, `status`) VALUES (?, ?, ?, ?)',
            'ssss',
            [$normalizedEmail, $passwordHash, $normalizedDisplayName, 'pending']
        );
        $newUserID = (int) $insertStmt->insert_id;
        $insertStmt->close();
    } catch (\Throwable $e) {
        // 📌 A duplicate-key race (two concurrent registrations for the same
        // email landing here simultaneously) is caught and treated
        // identically to the "already exists" branch above.
        //
        // 🔒 PII fix: MySQL's own duplicate-key error text is of the form
        // "Duplicate entry '<email>' for key ..." - logging $e->getMessage()
        // verbatim (as this branch previously did) would put the plaintext
        // email address into tblErrorLog. Detect the duplicate-key/
        // constraint-violation case specifically (mysqli's default PHP 8.1+
        // exception-reporting mode throws \mysqli_sql_exception with MySQL
        // errno 1062 for ER_DUP_ENTRY; a wrapped RuntimeException from
        // dbQuery()'s own execute-failure path would instead embed the same
        // "Duplicate entry" wording in its message) and log a GENERIC
        // message instead - never the raw driver error text for this
        // specific failure mode. Any OTHER unexpected insert failure still
        // logs its real message for operator follow-up (it is not expected
        // to carry the email in its wording).
        $isDuplicateKeyError = ($e instanceof \mysqli_sql_exception && $e->getCode() === 1062)
            || stripos($e->getMessage(), 'duplicate entry') !== false;

        if (function_exists('logError')) {
            if ($isDuplicateKeyError) {
                logError(
                    'warning',
                    'Registration insert conflict (duplicate email)',
                    'A concurrent registration attempt raced this one for the same email address '
                    . '(unique-key constraint violation) - no PII logged for this expected race condition.'
                );
            } else {
                logError('warning', 'Registration insert failed (unexpected error)', $e->getMessage());
            }
        }

        return $genericSuccess;
    }

    // 🎫 Issue the single-use, expiring verification token and queue the email.
    // 📌 #128: this is the SAME helper the "send me a new link" form uses, so
    //    there is only one path that makes a verification link. The helper logs
    //    its own failures; the RESPONSE stays generic either way.
    issueEmailVerificationLink($newUserID, $normalizedEmail, $normalizedDisplayName, false);

    return $genericSuccess;
}

// ==============================================================================
// 📨 Ask for a NEW verification link (#128)
// ==============================================================================
//
// A person who signed up with an email address and password stays "pending"
// until they click the link in the verification email. If that email never
// arrived, expired or was deleted, they used to be stuck for good. These
// functions let them ask for a replacement.
//
// 🙈 NO ACCOUNT ENUMERATION: requestNewVerificationLink() returns the SAME
//    message whether the address has a waiting account, has no account, is
//    already verified, is suspended, or the request was rate limited.
// ⏱️ RATE LIMITS (all settings, with the defaults below - nothing commercial
//    is hard-coded): a minimum gap between two sends for one account, a cap
//    per 24 hours for one account, and a cap per hour for one IP address.
// ==============================================================================

/**
 * @var string The one plain answer every request for a new link gets.
 */
if (!defined('CUERCODE_RESEND_VERIFICATION_MESSAGE')) {
    define(
        'CUERCODE_RESEND_VERIFICATION_MESSAGE',
        'If that address is waiting for verification, a new link is on its way. '
        . 'Please check your inbox and your spam folder.'
    );
}

/**
 * @var string The name written to the request log for this form, so its
 * requests can be counted separately from the rest of the site's traffic.
 */
/** @var int Shortest time (microseconds) the new-link request takes to answer, so timing does not show whether an address is waiting (review fix M3). */
if (!defined('CUERCODE_RESEND_MIN_RESPONSE_MICROSECONDS')) {
    define('CUERCODE_RESEND_MIN_RESPONSE_MICROSECONDS', 250000);
}

if (!defined('CUERCODE_RESEND_VERIFICATION_ENDPOINT')) {
    define('CUERCODE_RESEND_VERIFICATION_ENDPOINT', 'page/resend-verification');
}

/**
 * ⚙️ Read the three limits for "send me a new link" from the settings.
 *
 * Settings (group 'security'): `verification_resend_min_gap_seconds` (default
 * 60), `verification_resend_daily_cap` (default 5) and
 * `verification_resend_ip_per_hour` (default 10). A missing, non-numeric or
 * silly value falls back to the default or is pulled into a safe range.
 *
 * @param callable|null $getSetting Called as $getSetting(string $key, mixed $default);
 *                                  getConfig when null (a stand-in in tests).
 *
 * @return array{minGapSeconds: int, dailyCap: int, ipPerHour: int}
 */
function verificationResendSettings(?callable $getSetting = null): array
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

    $defaults = [
        'verification_resend_min_gap_seconds' => 60,
        'verification_resend_daily_cap'       => 5,
        'verification_resend_ip_per_hour'     => 10,
    ];
    $values = [];

    foreach ($defaults as $key => $default) {
        try {
            $raw = $getSetting($key, $default);
        } catch (\Throwable $e) {
            $raw = $default;
        }

        if (is_int($raw) || (is_string($raw) && preg_match('/^\d{1,9}$/', $raw) === 1)) {
            $values[$key] = (int) $raw;
        } else {
            $values[$key] = $default;
        }
    }

    return [
        'minGapSeconds' => cuercodeClampInt($values['verification_resend_min_gap_seconds'], 10, 86400),
        'dailyCap'      => cuercodeClampInt($values['verification_resend_daily_cap'], 1, 50),
        'ipPerHour'     => cuercodeClampInt($values['verification_resend_ip_per_hour'], 1, 1000),
    ];
}

/**
 * 🧠 Decide whether a new verification link may be sent (pure - no database).
 *
 * The checks run in this order, and the FIRST one that fails wins:
 *   1. the IP address has asked too often this hour  -> 'ip_limited'
 *   2. there is no waiting (pending, unverified) account -> 'no_pending_account'
 *   3. the last link was sent less than the minimum gap ago -> 'too_soon'
 *   4. the account has had its daily quota of links -> 'daily_cap'
 *   5. otherwise -> 'send'
 *
 * The caller never shows the reason to the visitor (they always get the same
 * message); it exists for the activity log and the tests.
 *
 * @param array $facts Keys: ipRequestsInLastHour (int), accountIsPending (bool),
 *                     secondsSinceLastLink (int|null, null = never),
 *                     linksInLast24Hours (int)
 * @param array $limits The result of verificationResendSettings()
 *
 * @return array{send: bool, reason: string}
 */
function decideVerificationResend(array $facts, array $limits): array
{
    $ipRequests = (int) ($facts['ipRequestsInLastHour'] ?? 0);

    if ($ipRequests > (int) $limits['ipPerHour']) {
        return ['send' => false, 'reason' => 'ip_limited'];
    }

    if (empty($facts['accountIsPending'])) {
        return ['send' => false, 'reason' => 'no_pending_account'];
    }

    $secondsSince = $facts['secondsSinceLastLink'] ?? null;

    if ($secondsSince !== null && (int) $secondsSince < (int) $limits['minGapSeconds']) {
        return ['send' => false, 'reason' => 'too_soon'];
    }

    if ((int) ($facts['linksInLast24Hours'] ?? 0) >= (int) $limits['dailyCap']) {
        return ['send' => false, 'reason' => 'daily_cap'];
    }

    return ['send' => true, 'reason' => 'send'];
}

/**
 * 🔢 Count this IP address's requests for a new link in the last hour.
 *
 * Reads the same request log (tblAPIRateLog) the API rate limiter uses, but
 * only the rows written by this form, so ordinary site traffic from the same
 * address never uses up the allowance.
 *
 * @param string $ipAddress Client IP address
 *
 * @return int Requests in the last hour (0 when the log cannot be read - the
 *             check fails open, like checkRateLimit()).
 */
function countVerificationResendRequestsFromIp(string $ipAddress): int
{
    if (!function_exists('dbQuery')) {
        return 0;
    }

    try {
        $stmt = dbQuery(
            'SELECT COUNT(*) AS `requestCount` FROM `tblAPIRateLog` '
            . 'WHERE `apiKeyID` IS NULL AND `requestIP` = ? AND `endpoint` = ? '
            . 'AND `requestedAt` >= DATE_SUB(NOW(), INTERVAL 1 HOUR)',
            'ss',
            [mb_substr($ipAddress, 0, 45), CUERCODE_RESEND_VERIFICATION_ENDPOINT]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('warning', 'Resend-verification IP count failed', $e->getMessage());
        }
        return 0;
    }

    if (is_array($row) && isset($row['requestCount'])) {
        return (int) $row['requestCount'];
    }

    return 0;
}

/**
 * 📨 Send a replacement verification link to a waiting account.
 *
 * Always returns the identical 200 result (see CUERCODE_RESEND_VERIFICATION_MESSAGE)
 * - the only different answer is 503 when the database is not available at all,
 * which says nothing about any account. The link is made by the same
 * issueEmailVerificationLink() that sign-up uses, and earlier unused links
 * for the account stop working.
 *
 * The activity rows THIS function writes carry no address, link or token: they
 * name only the account number. Be aware that the shared queueEmail() writes
 * its own `email_queued` activity row, and that row DOES contain the
 * recipient's address (it is the email audit trail's deliberate design, so an
 * operator can see who was sent what). Only the link and token are kept out
 * of every log. Review fix M5 corrected an earlier comment that claimed
 * "nothing written to a log" held the address.
 *
 * Time and noise limits (review fix M3): the answer is held back until at
 * least CUERCODE_RESEND_MIN_RESPONSE_MICROSECONDS have passed since the
 * request began, so how long it takes does not show whether the address is
 * waiting; and a person or program hammering one account's address writes at
 * most ONE `verification_link_resend_limited` row per account per hour.
 *
 * Known, accepted limits: two requests at exactly the same instant could both
 * pass the per-account gap check (the per-IP cap still applies), and could
 * both write the hourly limited row; and the fixed minimum time only hides
 * differences smaller than itself (a slow database or mail queue could still
 * take longer than that for a waiting account).
 *
 * @param string $email     Address typed into the form (checked by the caller too)
 * @param string $ipAddress Client IP address
 *
 * @return array{success: bool, httpCode: int, errorCode: string|null, message: string, data: array}
 *
 * @see decideVerificationResend()
 * @see issueEmailVerificationLink()
 */
function requestNewVerificationLink(string $email, string $ipAddress): array
{
    // ⏱️ Review fix M3: note when the request began, do the work, then wait
    //    out whatever is left of the fixed minimum time.
    $startedAtNanos = hrtime(true);
    $result         = requestNewVerificationLinkWork($email, $ipAddress);
    padResponseToMinimumDuration($startedAtNanos, CUERCODE_RESEND_MIN_RESPONSE_MICROSECONDS);

    return $result;
}

/**
 * ⏱️ Wait until at least $minimumMicroseconds have passed since $startedAtNanos.
 *
 * Used so a "we have sent a link if that address is waiting" answer takes the
 * same time whether or not the address is waiting. $nowNanos and $sleeper
 * exist so a test can check the arithmetic without really waiting.
 *
 * @param int           $startedAtNanos      hrtime(true) taken when the request began
 * @param int           $minimumMicroseconds Shortest total time allowed
 * @param callable|null $sleeper             Called with the microseconds to wait (default usleep)
 * @param int|null      $nowNanos            Current hrtime(true) (default: read the clock)
 * @return int Microseconds waited (0 when the minimum had already passed)
 */
function padResponseToMinimumDuration(int $startedAtNanos, int $minimumMicroseconds, ?callable $sleeper = null, ?int $nowNanos = null): int
{
    if ($nowNanos === null) {
        $nowNanos = hrtime(true);
    }

    $elapsedMicroseconds = intdiv($nowNanos - $startedAtNanos, 1000);
    $remaining           = $minimumMicroseconds - $elapsedMicroseconds;

    if ($remaining <= 0) {
        return 0;
    }

    if ($sleeper === null) {
        usleep($remaining);
    } else {
        $sleeper($remaining);
    }

    return $remaining;
}

/**
 * 🕐 Has a "link refused by the rate limit" activity row already been written
 *    for this account in the last hour? (Review fix M3.)
 *
 * On any database problem this answers TRUE, so a fault can only ever mean
 * "write nothing", never "write another row".
 *
 * @param int $userID Account number
 * @return bool
 */
function resendLimitedRowWrittenRecently(int $userID): bool
{
    try {
        $stmt = dbQuery(
            'SELECT COUNT(*) AS `rowCount` FROM `tblActivityLog` '
            . "WHERE `activityType` = 'verification_link_resend_limited' AND `entityType` = 'user' AND `entityID` = ? "
            . 'AND `createdAt` >= DATE_SUB(NOW(), INTERVAL 1 HOUR)',
            'i',
            [$userID]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        return true;
    }

    return (is_array($row) && (int) $row['rowCount'] > 0);
}

/**
 * 📨 The real work of requestNewVerificationLink(), without the fixed minimum
 *    time. Call requestNewVerificationLink() instead.
 *
 * @param string $email     Address typed into the form
 * @param string $ipAddress Client IP address
 * @return array{success: bool, httpCode: int, errorCode: string|null, message: string, data: array}
 */
function requestNewVerificationLinkWork(string $email, string $ipAddress): array
{
    $genericResult = [
        'success'   => true,
        'httpCode'  => 200,
        'errorCode' => null,
        'message'   => CUERCODE_RESEND_VERIFICATION_MESSAGE,
        'data'      => [],
    ];

    if (!function_exists('dbQuery')) {
        return [
            'success'   => false,
            'httpCode'  => 503,
            'errorCode' => 'SERVICE_UNAVAILABLE',
            'message'   => 'This is temporarily unavailable. Please try again later.',
            'data'      => [],
        ];
    }

    // 🚦 The request log (recordAPIRequest()) lives in fn_api_auth.php, which
    //    ordinary pages do not load; it only defines constants and functions,
    //    so loading it here (once) is harmless. (The visitor's address comes
    //    from getClientIP() in fn_client_ip.php, loaded on every page -
    //    decision 0014.)
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_api_auth.php';

    $normalizedEmail = mb_strtolower(trim($email));
    $limits          = verificationResendSettings();

    // 🌍 Per-IP allowance: count first, then record THIS request, so every
    //    attempt (known address or not) uses up the same allowance.
    $ipRequests = countVerificationResendRequestsFromIp($ipAddress) + 1;

    recordAPIRequest(null, $ipAddress, CUERCODE_RESEND_VERIFICATION_ENDPOINT);

    // 🔍 Is there a waiting account? (Only ever used internally.)
    $account = null;

    try {
        $stmt = dbQuery(
            'SELECT `userID`, `displayName`, `status`, `emailVerifiedAt` FROM `tblUsers` WHERE `email` = ? LIMIT 1',
            's',
            [$normalizedEmail]
        );
        $account = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Resend-verification lookup failed', $e->getMessage());
        }
        return $genericResult;
    }

    $isPending = is_array($account)
        && (string) $account['status'] === 'pending'
        && $account['emailVerifiedAt'] === null;

    // ⏱️ Per-account history, read from the tokens already issued.
    $secondsSince = null;
    $linksInDay   = 0;

    if ($isPending) {
        try {
            $stmt = dbQuery(
                'SELECT COUNT(CASE WHEN `createdAt` >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) AS `dayCount`, '
                . 'TIMESTAMPDIFF(SECOND, MAX(`createdAt`), NOW()) AS `secondsSince` '
                . 'FROM `tblEmailVerifications` WHERE `userID` = ?',
                'i',
                [(int) $account['userID']]
            );
            $history = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (\Throwable $e) {
            if (function_exists('logError')) {
                logError('error', 'Resend-verification history lookup failed', $e->getMessage());
            }
            return $genericResult;
        }

        if (is_array($history)) {
            $linksInDay = (int) $history['dayCount'];

            if ($history['secondsSince'] !== null) {
                $secondsSince = (int) $history['secondsSince'];
            }
        }
    }

    $decision = decideVerificationResend(
        [
            'ipRequestsInLastHour' => $ipRequests,
            'accountIsPending'     => $isPending,
            'secondsSinceLastLink' => $secondsSince,
            'linksInLast24Hours'   => $linksInDay,
        ],
        $limits
    );

    if ($decision['send'] && $isPending) {
        $displayName = null;

        if ($account['displayName'] !== null && (string) $account['displayName'] !== '') {
            $displayName = (string) $account['displayName'];
        }

        $issued = issueEmailVerificationLink((int) $account['userID'], $normalizedEmail, $displayName, true);

        if ($issued && function_exists('logActivity')) {
            // 🙈 The account NUMBER only - never the address, link or token.
            logActivity('verification_link_resent', 'A new verification link was requested', 'user', (int) $account['userID'], []);
        }
    } elseif ($isPending && $decision['reason'] !== 'too_soon' && function_exists('logActivity') && !resendLimitedRowWrittenRecently((int) $account['userID'])) {
        // 📝 A plain double-click inside the minimum gap is not worth a log row,
        //    and (review fix M3) neither is every further request once the
        //    limit is hit: at most one row per account per hour.
        logActivity(
            'verification_link_resend_limited',
            'A new verification link was refused by the rate limit (' . $decision['reason'] . ')',
            'user',
            (int) $account['userID'],
            []
        );
    }

    return $genericResult;
}

// ==============================================================================
// 🔑 emailVerificationPasswordOutcome() - may this link activate? (decision 0013)
// ==============================================================================

/**
 * 🔑 Decide what a verification-link click does, given the password check.
 *    PURE (decision 0013, docs/decisions/0013-signup-first-trick-verification-needs-password-reset-removes-all.md).
 *
 * WHY: a stranger can sign up first with someone else's email address. If the
 * real owner of that address then clicked a verification link and the account
 * became active, it would be active with the STRANGER's password. So a link
 * only activates an account that has a password when the person clicking it
 * also types that password. Someone who did not choose it is pointed to
 * "Forgot password", which proves the address and sets a new password.
 *
 * Wrong passwords count toward the account's normal sign-in lock-out; when
 * the count reaches the lock-out limit the link itself stops working too.
 *
 * @param bool $accountHasPassword    The account has a password (false = Microsoft/Google only)
 * @param bool $passwordGiven         A password was typed
 * @param bool $accountLocked         The account is locked out right now
 * @param bool $passwordCorrect       The typed password is the account's
 * @param int  $failedCountAfterWrong The failed-attempt count if this try is wrong
 * @param int  $maxAttempts           The lock-out limit (0 or less = no limit)
 * @return string 'activate', 'password_needed', 'locked', 'wrong_password' or 'wrong_password_link_stopped'
 */
function emailVerificationPasswordOutcome(
    bool $accountHasPassword,
    bool $passwordGiven,
    bool $accountLocked,
    bool $passwordCorrect,
    int $failedCountAfterWrong,
    int $maxAttempts
): string {
    // 👤 Microsoft/Google-only account: nothing to type - the old one-click way.
    if (!$accountHasPassword) {
        return 'activate';
    }

    // ⌨️ Nothing typed yet: ask, and do not count it as a wrong try.
    if (!$passwordGiven) {
        return 'password_needed';
    }

    // 🔒 Locked out: refused even with the right password (as at sign-in).
    if ($accountLocked) {
        return 'locked';
    }

    if ($passwordCorrect) {
        return 'activate';
    }

    if ($maxAttempts > 0 && $failedCountAfterWrong >= $maxAttempts) {
        return 'wrong_password_link_stopped';
    }

    return 'wrong_password';
}

// ==============================================================================
// 🔎 emailVerificationTokenNeedsPassword() - should the page show a password box?
// ==============================================================================

/**
 * 🔎 Does this verification link need the account's password? READ-ONLY - a
 *    mail scanner opening the page changes nothing (decision 0013).
 *
 * The answer is "yes" in every case except one: the link is valid, unused,
 * in date, and its account has no password (Microsoft/Google only). So the
 * page shows the password box unless it is sure it is not needed.
 *
 * @param string $rawToken The raw token from the link
 * @return bool
 */
function emailVerificationTokenNeedsPassword(string $rawToken): bool
{
    if ($rawToken === '' || !function_exists('dbQuery')) {
        return true;
    }

    try {
        $stmt = dbQuery(
            'SELECT `v`.`tokenHash`, `v`.`expiresAt`, `v`.`usedAt`, `u`.`passwordHash` '
            . 'FROM `tblEmailVerifications` AS `v` '
            . 'INNER JOIN `tblUsers` AS `u` ON `u`.`userID` = `v`.`userID` '
            . 'WHERE `v`.`tokenHash` = ? LIMIT 1',
            's',
            [hashToken($rawToken)]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        return true;
    }

    if ($row === null || $row['usedAt'] !== null) {
        return true;
    }

    $expiresAt = strtotime((string) $row['expiresAt']);
    if ($expiresAt === false || $expiresAt < time()) {
        return true;
    }

    return ($row['passwordHash'] !== null && (string) $row['passwordHash'] !== '');
}

// ==============================================================================
// ✅ verifyEmailToken() - Consume a single-use email verification token
// ==============================================================================

/**
 * ✅ Verify a raw email-verification token and activate the owning account.
 *
 * 🔑 Decision 0013: when the account has a password, the person clicking the
 *    link must type it ($password). A wrong or missing password, an invalid
 *    link and a locked account all get the SAME message, pointing to "Forgot
 *    password"; the link is used up only when the account is activated, or
 *    when wrong passwords reach the lock-out limit. Accounts with no password
 *    (Microsoft/Google only) are activated by the link alone, as before.
 *
 * @param string      $rawToken The raw token from the emailed verification link
 * @param string|null $password The account's password, as typed (null = not given)
 *
 * @return array{success: bool, httpCode: int, errorCode: string|null, message: string, data: array}
 *
 * @see registerUser()
 * @see emailVerificationPasswordOutcome()
 */
function verifyEmailToken(string $rawToken, #[\SensitiveParameter] ?string $password = null): array
{
    // 📣 ONE message for a bad link, a wrong password and a locked account.
    $invalidMessage = 'This verification link is invalid or has expired, or the password is not right. '
        . 'Type the password you chose when you signed up. If you did not choose it, or have forgotten it, '
        . 'use "Forgot password": it sends a link to this email address that sets a new password and activates the account.';
    $invalidResult  = [
        'success'   => false,
        'httpCode'  => 400,
        'errorCode' => 'VERIFICATION_TOKEN_INVALID',
        'message'   => $invalidMessage,
        'data'      => [],
    ];
    $passwordNeededResult = [
        'success'   => false,
        'httpCode'  => 400,
        'errorCode' => 'VERIFICATION_TOKEN_INVALID',
        'message'   => 'Please type the password you chose when you signed up. If you did not choose it, or have forgotten it, use "Forgot password" instead.',
        'data'      => [],
    ];
    $linkStoppedResult = [
        'success'   => false,
        'httpCode'  => 400,
        'errorCode' => 'VERIFICATION_TOKEN_INVALID',
        'message'   => 'The password was not right too many times, so this link has stopped working. '
            . 'Use "Forgot password" to set a new password; that also activates the account.',
        'data'      => [],
    ];
    $alreadyUsedResult = [
        'success'   => false,
        'httpCode'  => 409,
        'errorCode' => 'VERIFICATION_ALREADY_USED',
        'message'   => 'This verification link has already been used.',
        'data'      => [],
    ];

    if (!function_exists('dbQuery')) {
        return [
            'success'   => false,
            'httpCode'  => 503,
            'errorCode' => 'SERVICE_UNAVAILABLE',
            'message'   => 'Verification is temporarily unavailable. Please try again later.',
            'data'      => [],
        ];
    }

    if ($rawToken === '') {
        return $invalidResult;
    }

    $tokenHash = hashToken($rawToken);

    try {
        $stmt = dbQuery(
            'SELECT `verificationID`, `userID`, `tokenHash`, `expiresAt`, `usedAt` '
            . 'FROM `tblEmailVerifications` WHERE `tokenHash` = ? LIMIT 1',
            's',
            [$tokenHash]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Verification token lookup failed', $e->getMessage());
        }
        return $invalidResult;
    }

    // 🛡️ Defence-in-depth timing-safe compare on top of the indexed lookup
    // (mirrors validateAPIKey()'s Step 5 in fn_api_auth.php).
    if ($row === null || !hash_equals((string) $row['tokenHash'], $tokenHash)) {
        return $invalidResult;
    }

    if ($row['usedAt'] !== null) {
        return $alreadyUsedResult;
    }

    $expiresAtTimestamp = strtotime((string) $row['expiresAt']);

    if ($expiresAtTimestamp === false || $expiresAtTimestamp < time()) {
        return $invalidResult;
    }

    $userID = (int) $row['userID'];

    // 🛑 Review fix I1 (belt and braces, decision 0010): an emailed link must
    // never activate an account that already holds the Global Admin role. The
    // only way to activate one is `admin_access.php grant --activate`, which
    // asks for a NEW password. fn_admin.php is loaded here only when needed
    // (require_once does nothing twice); the role number stays in that file.
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_admin.php';
    if (adminUserHoldsGlobalAdminRole($userID)) {
        if (function_exists('logError')) {
            logError('warning', 'Verification link refused for a Global Admin account', 'userID ' . $userID);
        }
        return $invalidResult;
    }

    // 👤 Decision 0013: the account's password and lock-out state.
    try {
        $accountStmt = dbQuery(
            'SELECT `passwordHash`, `failedLoginCount`, `lockedUntil` FROM `tblUsers` WHERE `userID` = ? LIMIT 1',
            'i',
            [$userID]
        );
        $account = $accountStmt->get_result()->fetch_assoc();
        $accountStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Verification account lookup failed', $e->getMessage());
        }
        return $invalidResult;
    }

    if ($account === null) {
        return $invalidResult;
    }

    $accountHasPassword = ($account['passwordHash'] !== null && (string) $account['passwordHash'] !== '');
    $passwordGiven      = ($password !== null && $password !== '');
    $failedCount        = (int) $account['failedLoginCount'];

    $accountLocked = false;
    if ($account['lockedUntil'] !== null) {
        $lockedUntilTimestamp = strtotime((string) $account['lockedUntil']);
        $accountLocked = ($lockedUntilTimestamp !== false && $lockedUntilTimestamp > time());
    }

    // 🔐 Only check the password when it can matter (Argon2id is slow on purpose).
    //    A password longer than any the site accepts is simply wrong.
    $passwordCorrect = false;
    if ($accountHasPassword && $passwordGiven && !$accountLocked) {
        if (mb_strlen((string) $password) <= 1024 && function_exists('verifyPassword')) {
            $passwordCorrect = verifyPassword((string) $password, (string) $account['passwordHash']);
        }
    }

    $maxAttempts = CUERCODE_DEFAULT_MAX_LOGIN_ATTEMPTS;
    if (function_exists('getConfig')) {
        $maxAttempts = (int) getConfig('max_login_attempts', $maxAttempts);
    }

    $outcome = emailVerificationPasswordOutcome(
        $accountHasPassword,
        $passwordGiven,
        $accountLocked,
        $passwordCorrect,
        $failedCount + 1,
        $maxAttempts
    );

    if ($outcome === 'password_needed') {
        return $passwordNeededResult;
    }

    if ($outcome === 'locked') {
        return $invalidResult;
    }

    if ($outcome === 'wrong_password' || $outcome === 'wrong_password_link_stopped') {
        // 🔢 A wrong password counts toward the normal sign-in lock-out.
        recordFailedLogin($userID, $failedCount + 1);

        if ($outcome === 'wrong_password') {
            return $invalidResult;
        }

        // 🛑 Too many wrong passwords: this link stops working as well.
        try {
            $stopStmt = dbQuery(
                'UPDATE `tblEmailVerifications` SET `usedAt` = NOW() WHERE `verificationID` = ? AND `usedAt` IS NULL',
                'i',
                [(int) $row['verificationID']]
            );
            $stopStmt->close();
        } catch (\Throwable $e) {
            if (function_exists('logError')) {
                logError('warning', 'Verification link could not be stopped after too many wrong passwords', $e->getMessage());
            }
        }

        if (function_exists('logActivity')) {
            // 🙈 The account NUMBER only - never the address, link or password.
            logActivity('verification_link_stopped', 'A verification link stopped working after too many wrong passwords', 'user', $userID, []);
        }

        return $linkStoppedResult;
    }

    // ✅ 'activate': claim the token and activate the account in ONE
    //    transaction, so a failure part-way leaves the link usable.
    $db = getDB();
    $transactionStarted = false;

    try {
        $db->begin_transaction();
        $transactionStarted = true;

        // 🔒 Atomically claim the token (usedAt IS NULL guard) BEFORE
        // activating the account, closing the race window between two
        // simultaneous requests for the same token.
        $claimStmt = dbQuery(
            'UPDATE `tblEmailVerifications` SET `usedAt` = NOW() '
            . 'WHERE `verificationID` = ? AND `usedAt` IS NULL',
            'i',
            [(int) $row['verificationID']]
        );
        $claimed = ($claimStmt->affected_rows === 1);
        $claimStmt->close();

        if (!$claimed) {
            // 📌 Lost a race to another concurrent verification attempt for
            // the same token.
            authRollbackQuietly($db);
            return $alreadyUsedResult;
        }

        // 🧹 A successful password also clears the lock-out counter.
        $activateStmt = dbQuery(
            "UPDATE `tblUsers` SET `emailVerifiedAt` = NOW(), `status` = 'active', `failedLoginCount` = 0, `lockedUntil` = NULL "
            . "WHERE `userID` = ? AND `status` = 'pending' AND " . adminSqlNotGlobalAdminCondition(),
            'i',
            [$userID]
        );
        $activateStmt->close();

        $db->commit();
    } catch (\Throwable $e) {
        if ($transactionStarted) {
            authRollbackQuietly($db);
        }
        if (function_exists('logError')) {
            logError('error', 'Account activation failed after verification', $e->getMessage());
        }
        // Nothing was changed (the link still works) - surfaced to the
        // caller as a generic failure rather than a false success.
        return [
            'success'   => false,
            'httpCode'  => 500,
            'errorCode' => 'ACTIVATION_FAILED',
            'message'   => 'Your email address could not be verified just now. Please try again in a few minutes.',
            'data'      => [],
        ];
    }

    return [
        'success'   => true,
        'httpCode'  => 200,
        'errorCode' => null,
        'message'   => 'Your email address has been verified. You can now log in.',
        'data'      => [],
    ];
}

// ==============================================================================
// 🚪 loginUser() - Authenticate and issue a session
// ==============================================================================

/**
 * 🚪 Authenticate a user by email + password and issue a session on success.
 *
 * ANTI-ENUMERATION: every failure path (no such account, wrong password,
 * locked account, unverified/pending account, suspended account) returns the
 * SAME generic 401 message and error code - none of these states are
 * distinguishable from the response. password_verify() is ALWAYS invoked
 * (against a fixed dummy hash when no account row exists) so response timing
 * does not leak account existence either.
 *
 * @param string $email         Candidate email address
 * @param string $plainPassword Candidate plaintext password
 * @param string $ipAddress     Client IP address (for the session + lockout audit trail)
 * @param string $userAgent     Client user agent string (for the session audit trail)
 *
 * @return array{success: bool, httpCode: int, errorCode: string|null, message: string, data: array}
 *
 * @see issueUserSession()
 * @see recordFailedLogin()
 * @see fn_password.php - verifyPassword(), cuercodeDummyPasswordHash()
 */
function loginUser(string $email, #[\SensitiveParameter] string $plainPassword, string $ipAddress, string $userAgent): array
{
    $genericFailMessage = 'Invalid email or password, or the account is not yet verified.';
    $genericFailResult  = [
        'success'   => false,
        'httpCode'  => 401,
        'errorCode' => 'INVALID_CREDENTIALS',
        'message'   => $genericFailMessage,
        'data'      => [],
    ];

    if (!function_exists('dbQuery')) {
        return [
            'success'   => false,
            'httpCode'  => 503,
            'errorCode' => 'SERVICE_UNAVAILABLE',
            'message'   => 'Login is temporarily unavailable. Please try again later.',
            'data'      => [],
        ];
    }

    $normalizedEmail = mb_strtolower(trim($email));

    try {
        $stmt = dbQuery(
            'SELECT `userID`, `email`, `passwordHash`, `displayName`, `status`, '
            . '`failedLoginCount`, `lockedUntil` '
            . 'FROM `tblUsers` WHERE `email` = ? LIMIT 1',
            's',
            [$normalizedEmail]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Login lookup failed', $e->getMessage());
        }
        return $genericFailResult;
    }

    // 🕐 Timing-safe non-enumeration: always pay the Argon2id verification
    // cost, even for a non-existent account, so response latency cannot be
    // used to distinguish "no such account" from "wrong password".
    if ($row === null) {
        if (function_exists('verifyPassword') && function_exists('cuercodeDummyPasswordHash')) {
            verifyPassword($plainPassword, cuercodeDummyPasswordHash());
        }
        return $genericFailResult;
    }

    $userID      = (int) $row['userID'];
    $status      = (string) $row['status'];
    $failedCount = (int) $row['failedLoginCount'];

    $lockedUntilRaw       = $row['lockedUntil'];
    $lockedUntilTimestamp = $lockedUntilRaw !== null ? strtotime((string) $lockedUntilRaw) : false;
    $isLocked             = ($lockedUntilTimestamp !== false && $lockedUntilTimestamp > time());

    // 🔒 Always run verifyPassword() regardless of lock/status state, so a
    // locked/pending/suspended account does not produce a different timing
    // profile than a normal wrong-password check on an active account.
    $passwordOk = verifyPassword($plainPassword, (string) $row['passwordHash']);

    if ($isLocked || $status !== 'active' || !$passwordOk) {
        // 📌 Only extend the failure counter for a genuinely wrong password
        // against an ACTIVE, unlocked account - a probe against an
        // already-locked or not-yet-active account has nothing new to record.
        if (!$isLocked && $status === 'active' && !$passwordOk) {
            recordFailedLogin($userID, $failedCount + 1);
        }
        return $genericFailResult;
    }

    // ✅ Success - reset the failure counter and record the login.
    try {
        $resetStmt = dbQuery(
            'UPDATE `tblUsers` SET `failedLoginCount` = 0, `lockedUntil` = NULL, `lastLoginAt` = NOW() '
            . 'WHERE `userID` = ?',
            'i',
            [$userID]
        );
        $resetStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('warning', 'Post-login counter reset failed', $e->getMessage());
        }
    }

    // 🔁 Transparent Argon2id rehash if the stored hash used older cost
    // parameters than fn_password.php currently specifies.
    if (function_exists('passwordNeedsRehash') && passwordNeedsRehash((string) $row['passwordHash'])) {
        try {
            $newHash      = hashPassword($plainPassword);
            $rehashStmt   = dbQuery('UPDATE `tblUsers` SET `passwordHash` = ? WHERE `userID` = ?', 'si', [$newHash, $userID]);
            $rehashStmt->close();
        } catch (\Throwable $e) {
            if (function_exists('logError')) {
                logError('warning', 'Transparent password rehash failed', $e->getMessage());
            }
        }
    }

    issueUserSession($userID, $ipAddress, $userAgent);

    return [
        'success'   => true,
        'httpCode'  => 200,
        'errorCode' => null,
        'message'   => 'Logged in successfully.',
        'data'      => [
            'user' => [
                'email'        => (string) $row['email'],
                'display_name' => $row['displayName'],
            ],
        ],
    ];
}

// ==============================================================================
// 🔢 recordFailedLogin() - Increment failure counter + apply lockout
// ==============================================================================

/**
 * 🔢 Persist an incremented failed-login count and, once the configured
 * threshold is reached, an account lockout expiry.
 *
 * @param int $userID         The tblUsers.userID whose counter to update
 * @param int $newFailedCount The failedLoginCount value AFTER this failure
 *
 * @return void
 *
 * @see computeLockoutExpiry()
 */
function recordFailedLogin(int $userID, int $newFailedCount): void
{
    if (!function_exists('dbQuery')) {
        return;
    }

    $maxAttempts    = CUERCODE_DEFAULT_MAX_LOGIN_ATTEMPTS;
    $lockoutSeconds = CUERCODE_DEFAULT_LOCKOUT_DURATION_SECONDS;

    if (function_exists('getConfig')) {
        $maxAttempts    = (int) getConfig('max_login_attempts', $maxAttempts);
        $lockoutSeconds = (int) getConfig('lockout_duration', $lockoutSeconds);
    }

    $lockoutExpiry = computeLockoutExpiry($newFailedCount, $maxAttempts, $lockoutSeconds, time());

    try {
        if ($lockoutExpiry !== null) {
            $stmt = dbQuery(
                'UPDATE `tblUsers` SET `failedLoginCount` = ?, `lockedUntil` = FROM_UNIXTIME(?) WHERE `userID` = ?',
                'iii',
                [$newFailedCount, $lockoutExpiry, $userID]
            );
        } else {
            $stmt = dbQuery(
                'UPDATE `tblUsers` SET `failedLoginCount` = ? WHERE `userID` = ?',
                'ii',
                [$newFailedCount, $userID]
            );
        }
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('warning', 'Failed-login counter update failed', $e->getMessage());
        }
    }
}

// ==============================================================================
// 🎫 issueUserSession() - Create a database-backed session + rotate the PHP session id
// ==============================================================================

/**
 * 🎫 Issue a new database-backed session for a just-authenticated user.
 *
 * Two independent things happen here:
 *   1. A fresh, random 64-hex-char token is inserted into tblUserSessions -
 *      this is the durable, independently-revocable session record (used by
 *      currentUser()/logoutCurrentUser() and, in a future stage, a "sign out
 *      this device" sessions-list UI).
 *   2. The PHP session ID itself is REGENERATED (session_regenerate_id(true))
 *      - standard session-fixation mitigation: any session id an attacker
 *      may have fixed onto the victim's browser BEFORE login stops being
 *      valid the moment login succeeds.
 *
 * @param int    $userID    The newly-authenticated tblUsers.userID
 * @param string $ipAddress Client IP address (audit trail)
 * @param string $userAgent Client user agent string (audit trail)
 *
 * @return string The new session token (also stored in $_SESSION)
 *
 * @see https://www.php.net/manual/en/function.session-regenerate-id.php
 */
function issueUserSession(int $userID, string $ipAddress, string $userAgent): string
{
    $sessionID = generateRandomToken();

    $lifetimeSeconds = CUERCODE_DEFAULT_SESSION_LIFETIME_SECONDS;

    if (function_exists('getConfig')) {
        $configuredLifetime = (int) getConfig('session_lifetime', $lifetimeSeconds);
        if ($configuredLifetime > 0) {
            $lifetimeSeconds = $configuredLifetime;
        }
    }

    if (function_exists('dbQuery')) {
        try {
            $stmt = dbQuery(
                'INSERT INTO `tblUserSessions` (`sessionID`, `userID`, `ipAddress`, `userAgent`, `expiresAt`) '
                . 'VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))',
                'sissi',
                [$sessionID, $userID, mb_substr($ipAddress, 0, 45), mb_substr($userAgent, 0, 500), $lifetimeSeconds]
            );
            $stmt->close();
        } catch (\Throwable $e) {
            if (function_exists('logError')) {
                logError('error', 'Session issuance failed', $e->getMessage());
            }
        }
    }

    // 🔐 Session-fixation defence: rotate the PHP session id on every
    // successful login, in addition to our own independent database token.
    // @see https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $_SESSION[CUERCODE_SESSION_TOKEN_KEY] = $sessionID;

    return $sessionID;
}

// ==============================================================================
// 🚪 logoutCurrentUser() - Revoke the current session
// ==============================================================================

/**
 * 🚪 Log out the current session (idempotent).
 *
 * Revokes the tblUserSessions row referenced by the current PHP session (if
 * any), clears the relevant $_SESSION keys, and rotates the PHP session id
 * again. Calling this with NO active session is a harmless no-op that still
 * returns success (200) - logging out of "nothing" is not an error, and
 * treating it as one would let a caller probe for session-state via status
 * code differences.
 *
 * @return array{success: bool, httpCode: int, errorCode: string|null, message: string, data: array}
 */
function logoutCurrentUser(): array
{
    if (isset($_SESSION[CUERCODE_SESSION_TOKEN_KEY])) {
        $sessionID = (string) $_SESSION[CUERCODE_SESSION_TOKEN_KEY];

        if (function_exists('dbQuery')) {
            try {
                $stmt = dbQuery(
                    'UPDATE `tblUserSessions` SET `revokedAt` = NOW() '
                    . 'WHERE `sessionID` = ? AND `revokedAt` IS NULL',
                    's',
                    [$sessionID]
                );
                $stmt->close();
            } catch (\Throwable $e) {
                if (function_exists('logError')) {
                    logError('warning', 'Session revoke failed during logout', $e->getMessage());
                }
            }
        }

        unset($_SESSION[CUERCODE_SESSION_TOKEN_KEY]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    return [
        'success'   => true,
        'httpCode'  => 200,
        'errorCode' => null,
        'message'   => 'Logged out successfully.',
        'data'      => [],
    ];
}

// ==============================================================================
// 👤 currentUser() - Resolve the authenticated user from the current session
// ==============================================================================

/**
 * 👤 Resolve the currently authenticated user (if any) from the PHP session.
 *
 * Looks up the database-backed session referenced by
 * $_SESSION[CUERCODE_SESSION_TOKEN_KEY], verifying it is neither revoked nor
 * expired, and that the owning account is still 'active'. On success,
 * opportunistically bumps tblUserSessions.lastSeenAt (best-effort, failure
 * is non-fatal).
 *
 * @return array{userID: int, email: string, displayName: string|null, avatarPath: string|null, roleID: int|null}|null
 *         The current user, or null if there is no valid authenticated session
 *
 * 🎭 'roleID' (task 7n Phase 1B, #31) is the platform-wide role from
 *    tblUsers.roleID (NULL for ordinary accounts). It is returned ONLY so
 *    header.php can show an "Admin" menu item without a second query. It is
 *    a convenience, never a permission: every admin page re-checks the role,
 *    status and email verification fresh with requireGlobalAdminPage()
 *    (fn_admin.php).
 *
 * @see resolveAvatarUrl() - turns 'avatarPath' (added Epic E3 stage 2b, #23)
 *      into a displayable <img src> per the ProjectBrief §11.4 priority chain
 */
function currentUser(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    if (!isset($_SESSION[CUERCODE_SESSION_TOKEN_KEY])) {
        return null;
    }

    if (!function_exists('dbQuery')) {
        return null;
    }

    $sessionID = (string) $_SESSION[CUERCODE_SESSION_TOKEN_KEY];

    try {
        $stmt = dbQuery(
            'SELECT s.`userID`, s.`expiresAt`, s.`revokedAt`, u.`email`, u.`displayName`, u.`avatarPath`, u.`status`, u.`roleID` '
            . 'FROM `tblUserSessions` s '
            . 'INNER JOIN `tblUsers` u ON u.`userID` = s.`userID` '
            . 'WHERE s.`sessionID` = ? LIMIT 1',
            's',
            [$sessionID]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        return null;
    }

    if ($row === null) {
        return null;
    }

    if ($row['revokedAt'] !== null) {
        return null;
    }

    $expiresAtTimestamp = strtotime((string) $row['expiresAt']);

    if ($expiresAtTimestamp === false || $expiresAtTimestamp < time()) {
        return null;
    }

    if ((string) $row['status'] !== 'active') {
        return null;
    }

    // 📌 Best-effort activity touch - a failure here must never break
    // currentUser() itself.
    try {
        $touchStmt = dbQuery('UPDATE `tblUserSessions` SET `lastSeenAt` = NOW() WHERE `sessionID` = ?', 's', [$sessionID]);
        $touchStmt->close();
    } catch (\Throwable $e) {
        // non-fatal
    }

    // 🎭 NULL stays NULL (an ordinary account); anything else becomes a whole number.
    $roleID = null;
    if (isset($row['roleID']) && $row['roleID'] !== null) {
        $roleID = (int) $row['roleID'];
    }

    return [
        'userID'      => (int) $row['userID'],
        'email'       => (string) $row['email'],
        'displayName' => $row['displayName'],
        'avatarPath'  => $row['avatarPath'],
        'roleID'      => $roleID,
    ];
}

// ==============================================================================
// 🖼️ resolveAvatarUrl() - ProjectBrief §11.4 avatar priority chain
// ==============================================================================

/**
 * 🖼️ Resolve a displayable avatar <img src> URL for a user.
 *
 * Implements the ProjectBrief §11.4 priority chain:
 *   1. Local custom upload (tblUsers.avatarPath is set) - served through the
 *      session-gated streaming endpoint public_html/account/avatar/index.php
 *      (never a direct link into the non-web `_uploads/` directory).
 *   2. MS365/Google SSO-provided avatar - Epic E2 (OAuth/SSO), NOT built yet;
 *      no linked-identity table is populated today, so this tier is
 *      deliberately unreachable until that epic ships (documented owner
 *      decision - see DEV_NOTES.md).
 *   3. Gravatar (https://gravatar.com) - computed from an MD5 hash of the
 *      lower-cased, trimmed email address per Gravatar's documented request
 *      format. This is a CLIENT-side <img> fetch (the browser talks to
 *      Gravatar directly) - the server never makes an outbound network call
 *      to Gravatar, consistent with this codebase's "no outbound call on a
 *      hot request path" posture. `d=404` asks Gravatar to return a 404
 *      instead of its own generic default image when no Gravatar is set for
 *      that email, so the caller's `onerror` handler can fall through to
 *      tier 4 - see cuercode-account.js `wireAvatarFallback()`.
 *   4. A local static default placeholder icon.
 *
 * Pure/deterministic - no I/O - safe to unit test directly.
 *
 * @param string|null $avatarPath The tblUsers.avatarPath column value (relative
 *                                 to CUERCODE_UPLOADS_DIR, e.g. 'avatars/42.png'),
 *                                 or null/empty when no custom avatar is set.
 * @param string      $email      The user's email address (for the Gravatar hash).
 *
 * @return string An absolute-path URL suitable for an <img src>.
 *
 * @see https://en.gravatar.com/site/implement/images/
 * @see https://www.php.net/manual/en/function.md5.php
 *
 * @example
 *   resolveAvatarUrl('avatars/42.png', 'user@example.com');
 *   // '/account/avatar/?u=42' (via a stable per-session lookup, no PII in the URL)
 *
 *   resolveAvatarUrl(null, 'user@example.com');
 *   // 'https://www.gravatar.com/avatar/b58996c504c5638798eb6b511e6f49af?s=128&d=404'
 */
function resolveAvatarUrl(?string $avatarPath, string $email, int $size = 128): string
{
    // 🎯 Tier 1: local custom upload. The actual file streaming endpoint
    // resolves the CURRENT SESSION's own avatarPath server-side (never trusts
    // a client-supplied path/userID) - see public_html/account/avatar/index.php.
    // A cache-busting-free, stable URL is fine here: the endpoint always
    // serves the CALLER's own current avatar, so there is no cross-user
    // parameter to manipulate.
    if ($avatarPath !== null && trim($avatarPath) !== '') {
        return '/account/avatar/';
    }

    // 🎯 Tier 2 (MS365/Google SSO avatar) - Epic E2, not built. No linked
    // identity/avatar-URL column exists yet, so this tier is unreachable
    // until that epic ships; falls through to Gravatar below by design.

    // 🎯 Tier 3: Gravatar, computed from the email's MD5 hash.
    $normalizedEmail = mb_strtolower(trim($email));

    if ($normalizedEmail !== '') {
        // @see https://en.gravatar.com/site/implement/images/ - "the email
        // address hash: trim leading/trailing whitespace, lower-case, MD5".
        $emailHash = md5($normalizedEmail);

        return 'https://www.gravatar.com/avatar/' . $emailHash
            . '?s=' . cuercodeClampInt($size, 16, 2048)
            . '&d=404';
    }

    // 🎯 Tier 4: local default placeholder (no usable email at all).
    return '/assets/img/branding/avatar-default.svg';
}

// ==============================================================================
// 🛡️ requireAuth() - Guard helper for authenticated-only routes/pages
// ==============================================================================

/**
 * 🛡️ Require an authenticated user, terminating the request with a 401 if absent.
 *
 * Mirrors the house convention already used across the codebase (e.g.
 * validateAPIKey()'s callers) of a guard function that either returns a
 * usable result or terminates the request via errorResponse() (which itself
 * calls exit). NOT exercised by this stage's endpoints directly (register/
 * login/logout/verify/reset are all pre-authentication or self-contained),
 * but provided now as the reusable guard the DEFERRED /save + /qrcodes
 * list/manage endpoints (later E3 stage) will call.
 *
 * @return array{userID: int, email: string, displayName: string|null} The current user (never null - errorResponse() exits first)
 *
 * @see currentUser()
 */
function requireAuth(): array
{
    $user = currentUser();

    if ($user === null) {
        if (function_exists('errorResponse')) {
            errorResponse('Authentication required. Please log in.', 401, 'AUTH_REQUIRED');
        }
        // 📌 errorResponse() calls exit(); this throw is an unreachable
        // safety net for the (test-only) case where errorResponse() is not loaded.
        throw new \RuntimeException('Authentication required and errorResponse() is unavailable.');
    }

    return $user;
}

// ==============================================================================
// ↩️ authRollbackQuietly() - undo a transaction without throwing
// ==============================================================================

/**
 * ↩️ Roll a transaction back, ignoring any error from the rollback itself
 *    (the original error is the one worth reporting).
 *
 * @param \mysqli $db The connection
 * @return void
 */
function authRollbackQuietly(\mysqli $db): void
{
    try {
        $db->rollback();
    } catch (\Throwable $e) {
        // Nothing more can be done; the caller reports the original error.
    }
}

// ==============================================================================
// 🚪 accountRemoveOtherWaysIn() - ONE place that shuts every other door
// ==============================================================================

/**
 * 🚪 Remove every OTHER way into an account, inside the caller's transaction.
 *    Any database error is thrown, so the caller rolls EVERYTHING back.
 *
 * Shared by the password reset (decision 0013) and the command-line
 * `admin_access.php grant --activate` (decision 0010, adminCliGrant() in
 * fn_admin.php), so the list of doors is written down once.
 *
 *   - tblUserSessions: signed-in sessions -> ended (revokedAt set);
 *   - tblAPIKeys: the account's API keys -> switched off (isActive = 0, the
 *     same as the "revoke" button);
 *   - tblPasswordResets: unused reset links -> cancelled (usedAt set; the
 *     link being used right now was already used up by the caller);
 *   - tblUserOAuth: linked Microsoft/Google sign-ins -> removed (a link has
 *     no "off" switch; the owner can link their own again afterwards);
 *   - tblWebAuthnCredentials: passkeys -> removed (not in use yet, but
 *     removed so nothing is left behind when they are);
 *   - tblEmailVerifications (only when $alsoCancelVerificationLinks): unused
 *     email-check links -> cancelled. The command-line grant leaves them:
 *     they only work on a 'pending' account, and it activates the account in
 *     the same transaction (decision 0010 recorded exactly five counts).
 * There are no "remember me" tokens on this site.
 *
 * @param int  $userID                      The account
 * @param bool $alsoCancelVerificationLinks Also cancel unused email-check links
 * @return array<string, int> How many of each were removed (no secrets)
 * @throws \RuntimeException On any database error
 */
function accountRemoveOtherWaysIn(int $userID, bool $alsoCancelVerificationLinks): array
{
    $statements = [
        'sessions_ended'                 => 'UPDATE `tblUserSessions` SET `revokedAt` = NOW() WHERE `userID` = ? AND `revokedAt` IS NULL',
        'api_keys_turned_off'            => 'UPDATE `tblAPIKeys` SET `isActive` = 0 WHERE `userID` = ? AND `isActive` = 1',
        'password_reset_links_cancelled' => 'UPDATE `tblPasswordResets` SET `usedAt` = NOW() WHERE `userID` = ? AND `usedAt` IS NULL',
        'linked_sign_ins_removed'        => 'DELETE FROM `tblUserOAuth` WHERE `userID` = ?',
        'passkeys_removed'               => 'DELETE FROM `tblWebAuthnCredentials` WHERE `userID` = ?',
    ];

    if ($alsoCancelVerificationLinks) {
        $statements['verification_links_cancelled'] = 'UPDATE `tblEmailVerifications` SET `usedAt` = NOW() WHERE `userID` = ? AND `usedAt` IS NULL';
    }

    $removed = [];

    foreach ($statements as $key => $sql) {
        $stmt  = dbQuery($sql, 'i', [$userID]);
        $count = $stmt->affected_rows;
        $stmt->close();

        if (!is_int($count) || $count < 0) {
            throw new \RuntimeException('earlier access could not be removed');
        }

        $removed[$key] = $count;
    }

    return $removed;
}

// ==============================================================================
// 🔑 requestPasswordReset() - Always-identical-response reset request
// ==============================================================================

/**
 * 🔑 Request a password reset email for the given address.
 *
 * ANTI-ENUMERATION: ALWAYS returns the identical 200 success shape whether
 * or not the email address is registered - the only way this function's
 * response differs is on a malformed/empty $email, which the caller/handler
 * is expected to have already rejected before calling this (so in normal
 * operation this function's result is a constant).
 *
 * @param string $email     Candidate email address
 * @param string $ipAddress Client IP address (audit trail on the reset row)
 *
 * @return array{success: bool, httpCode: int, errorCode: string|null, message: string, data: array}
 *
 * @see resetPasswordWithToken()
 */
function requestPasswordReset(string $email, string $ipAddress): array
{
    $genericMessage = 'If this email address is registered, a password reset link has been sent to it.';
    $genericResult  = [
        'success'   => true,
        'httpCode'  => 200,
        'errorCode' => null,
        'message'   => $genericMessage,
        'data'      => [],
    ];

    if (!function_exists('dbQuery')) {
        return $genericResult;
    }

    $normalizedEmail = mb_strtolower(trim($email));

    try {
        $stmt = dbQuery(
            'SELECT `userID`, `displayName`, `status` FROM `tblUsers` WHERE `email` = ? LIMIT 1',
            's',
            [$normalizedEmail]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Password reset lookup failed', $e->getMessage());
        }
        return $genericResult;
    }

    if ($row === null) {
        return $genericResult;
    }

    // 📌 Only an ACTIVE or a waiting (PENDING) account gets an actual reset
    // email queued - this distinction is internal only and never observable
    // in the response. 🔐 Decision 0013: a pending account is included,
    // because the reset link proves the person owns the email address; using
    // it sets a new password AND activates the account. This is the way out
    // for someone whose address a stranger signed up with first.
    $status = (string) $row['status'];
    if ($status === 'active' || $status === 'pending') {
        $userID    = (int) $row['userID'];
        $rawToken  = generateRandomToken();
        $tokenHash = hashToken($rawToken);

        try {
            $insertStmt = dbQuery(
                'INSERT INTO `tblPasswordResets` (`userID`, `tokenHash`, `expiresAt`, `requestIP`) '
                . 'VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)',
                'isis',
                [$userID, $tokenHash, CUERCODE_PASSWORD_RESET_TTL_MINUTES, mb_substr($ipAddress, 0, 45)]
            );
            $insertStmt->close();

            if (function_exists('queueEmail')) {
                $resetUrl = buildAccountActionUrl('reset-password', $rawToken);

                queueEmail(
                    $normalizedEmail,
                    'password_reset',
                    [
                        'recipientName' => $row['displayName'],
                        'resetUrl'      => $resetUrl,
                        'expiryMinutes' => CUERCODE_PASSWORD_RESET_TTL_MINUTES,
                    ],
                    null,
                    $row['displayName']
                );
            }
        } catch (\Throwable $e) {
            if (function_exists('logError')) {
                logError('error', 'Password reset token issue failed', $e->getMessage());
            }
        }
    }

    return $genericResult;
}

// ==============================================================================
// 🔁 resetPasswordWithToken() - Consume a single-use reset token
// ==============================================================================

/**
 * 🔁 Verify a password-reset token and apply the new password.
 *
 * On success, ALL existing sessions for the account are revoked (a
 * possibly-compromised session must not survive a reset intended to lock an
 * attacker out) and the failed-login/lockout counters are cleared.
 *
 * 🔐 Decision 0013 (the "someone signed up first with my email" trick): a
 *    successful reset - on an active OR a waiting account - does ALL of this
 *    in ONE database transaction, so either all of it happens or none of it:
 *      1. uses up this reset link;
 *      2. stores the new password and clears the sign-in lock-out;
 *      3. on a waiting ('pending') account, also activates it and marks the
 *         email address as checked (the reset link proves the person owns
 *         the address) - never for an account holding the Global Admin role
 *         (review fix I1: an emailed link never activates an admin);
 *      4. ends every signed-in session, switches off every API key, removes
 *         linked Microsoft/Google sign-ins and passkeys, and cancels every
 *         other unused reset and verification link
 *         (accountRemoveOtherWaysIn());
 *      5. writes an activity row with COUNTS only (never the password, the
 *         link or the address).
 *    The slow password hashing happens BEFORE the transaction starts, so the
 *    account row is locked only for a moment.
 *
 * @param string $rawToken    The raw token from the emailed reset link
 * @param string $newPassword The candidate new plaintext password
 *
 * @return array{success: bool, httpCode: int, errorCode: string|null, message: string, data: array}
 *
 * @see requestPasswordReset()
 * @see accountRemoveOtherWaysIn()
 * @see fn_password.php - validatePasswordPolicy(), hashPassword()
 */
function resetPasswordWithToken(string $rawToken, #[\SensitiveParameter] string $newPassword): array
{
    $invalidMessage = 'This password reset link is invalid or has expired.';
    $invalidResult  = [
        'success'   => false,
        'httpCode'  => 400,
        'errorCode' => 'RESET_TOKEN_INVALID',
        'message'   => $invalidMessage,
        'data'      => [],
    ];
    $alreadyUsedResult = [
        'success'   => false,
        'httpCode'  => 409,
        'errorCode' => 'RESET_TOKEN_ALREADY_USED',
        'message'   => 'This password reset link has already been used.',
        'data'      => [],
    ];

    if (!function_exists('dbQuery')) {
        return [
            'success'   => false,
            'httpCode'  => 503,
            'errorCode' => 'SERVICE_UNAVAILABLE',
            'message'   => 'Password reset is temporarily unavailable. Please try again later.',
            'data'      => [],
        ];
    }

    if ($rawToken === '') {
        return $invalidResult;
    }

    $tokenHash = hashToken($rawToken);

    try {
        $stmt = dbQuery(
            'SELECT `resetID`, `userID`, `tokenHash`, `expiresAt`, `usedAt` '
            . 'FROM `tblPasswordResets` WHERE `tokenHash` = ? LIMIT 1',
            's',
            [$tokenHash]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Password reset token lookup failed', $e->getMessage());
        }
        return $invalidResult;
    }

    if ($row === null || !hash_equals((string) $row['tokenHash'], $tokenHash)) {
        return $invalidResult;
    }

    if ($row['usedAt'] !== null) {
        return $alreadyUsedResult;
    }

    $expiresAtTimestamp = strtotime((string) $row['expiresAt']);

    if ($expiresAtTimestamp === false || $expiresAtTimestamp < time()) {
        return $invalidResult;
    }

    // 📏 Validate the new password policy only once we know the token itself
    // is genuinely valid and unused - avoids doing extra work for a bad token.
    $minLength = CUERCODE_PASSWORD_MIN_LENGTH_DEFAULT;
    if (function_exists('getConfig')) {
        $minLength = (int) getConfig('password_min_length', CUERCODE_PASSWORD_MIN_LENGTH_DEFAULT);
    }

    $policy = validatePasswordPolicy($newPassword, $minLength);

    if (!$policy['valid']) {
        return [
            'success'   => false,
            'httpCode'  => 422,
            'errorCode' => 'WEAK_PASSWORD',
            'message'   => $policy['error'],
            'data'      => [],
        ];
    }

    $userID = (int) $row['userID'];

    // 🔑 Hash BEFORE the transaction: Argon2id is slow on purpose, and the
    //    account row should be locked only for a moment.
    try {
        $newHash = hashPassword($newPassword);
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Password hashing failed during reset', $e->getMessage());
        }
        return [
            'success'   => false,
            'httpCode'  => 500,
            'errorCode' => 'HASH_FAILED',
            'message'   => 'Password reset could not be completed. Please try again later.',
            'data'      => [],
        ];
    }

    // 🛑 Review fix I1 applies here too: an emailed link never activates an
    //    account holding the Global Admin role. fn_admin.php is loaded only
    //    when needed and keeps the role number to itself.
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_admin.php';

    $failedResult = [
        'success'   => false,
        'httpCode'  => 500,
        'errorCode' => 'RESET_FAILED',
        'message'   => 'Password reset could not be completed. Please try again later.',
        'data'      => [],
    ];

    $db = getDB();
    $transactionStarted = false;
    $activated = false;
    $removed   = [];

    try {
        $db->begin_transaction();
        $transactionStarted = true;

        // 🔒 1. Atomically claim the token BEFORE applying the password change.
        $claimStmt = dbQuery(
            'UPDATE `tblPasswordResets` SET `usedAt` = NOW() WHERE `resetID` = ? AND `usedAt` IS NULL',
            'i',
            [(int) $row['resetID']]
        );
        $claimed = ($claimStmt->affected_rows === 1);
        $claimStmt->close();

        if (!$claimed) {
            authRollbackQuietly($db);
            return $alreadyUsedResult;
        }

        // 🔐 The account, locked until the end of the transaction. (The role
        //    is asked of fn_admin.php below, so this file never handles the
        //    role column in a statement that changes anything.)
        $accountStmt = dbQuery(
            'SELECT `status` FROM `tblUsers` WHERE `userID` = ? LIMIT 1 FOR UPDATE',
            'i',
            [$userID]
        );
        $account = $accountStmt->get_result()->fetch_assoc();
        $accountStmt->close();

        $status = '';
        if (is_array($account)) {
            $status = (string) $account['status'];
        }

        // 🚫 Only an active or a waiting account can be reset (a suspended or
        //    deleted one cannot, even with a link issued before that). The
        //    link is NOT used up - nothing at all changes.
        if ($status !== 'active' && $status !== 'pending') {
            authRollbackQuietly($db);
            return $invalidResult;
        }

        if ($status === 'pending' && adminUserHoldsGlobalAdminRole($userID)) {
            authRollbackQuietly($db);
            if (function_exists('logError')) {
                logError('warning', 'Password reset link refused for a waiting Global Admin account', 'userID ' . $userID);
            }
            return $invalidResult;
        }

        // 🔑 2 + 3. New password, lock-out cleared - and a waiting account
        //    becomes active with a checked email address.
        if ($status === 'pending') {
            $updateStmt = dbQuery(
                "UPDATE `tblUsers` SET `passwordHash` = ?, `failedLoginCount` = 0, `lockedUntil` = NULL, "
                . "`status` = 'active', `emailVerifiedAt` = COALESCE(`emailVerifiedAt`, NOW()) "
                . "WHERE `userID` = ? AND `status` = 'pending' AND " . adminSqlNotGlobalAdminCondition(),
                'si',
                [$newHash, $userID]
            );
            $activated = true;
        } else {
            $updateStmt = dbQuery(
                "UPDATE `tblUsers` SET `passwordHash` = ?, `failedLoginCount` = 0, `lockedUntil` = NULL "
                . "WHERE `userID` = ? AND `status` = 'active'",
                'si',
                [$newHash, $userID]
            );
        }
        $changedRows = $updateStmt->affected_rows;
        $updateStmt->close();

        if ($changedRows !== 1) {
            throw new \RuntimeException('the account changed while it was being updated');
        }

        // 🚪 4. Every other way in, in THIS transaction.
        $removed = accountRemoveOtherWaysIn($userID, true);

        // 🧾 5. What happened - counts only.
        if (function_exists('logActivity')) {
            logActivity(
                'password_reset_completed',
                'Password reset with an emailed link; every other way into the account was removed',
                'user',
                $userID,
                ['account_activated' => $activated, 'removed' => $removed]
            );
        }

        $db->commit();
    } catch (\Throwable $e) {
        if ($transactionStarted) {
            authRollbackQuietly($db);
        }
        if (function_exists('logError')) {
            logError('error', 'Password reset failed; nothing was changed', $e->getMessage());
        }
        return $failedResult;
    }

    // 📣 Say plainly what changed, so nobody is surprised that an API key
    //    stopped working or a Google/Microsoft button no longer signs them in.
    $message = 'Your password has been reset.';
    if ($activated) {
        $message = 'Your password has been set and your account is now active.';
    }
    $message .= ' For your safety you have been signed out everywhere, any API keys on the account have been switched off,'
        . ' and any linked Google or Microsoft sign-in has been removed. Sign in with your new password,'
        . ' then make new API keys and link Google or Microsoft again on your account page if you need them.';

    return [
        'success'   => true,
        'httpCode'  => 200,
        'errorCode' => null,
        'message'   => $message,
        'data'      => [],
    ];
}
