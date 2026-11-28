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

// ------------------------------------------------------------------------------
// ⏱️ Database times are UTC - let the DATABASE compare them (#158, #130)
// ------------------------------------------------------------------------------
// Every DATETIME in these tables is written by the database in UTC (the
// connection sets time_zone = '+00:00'). PHP's own clock follows the
// `site_timezone` setting, so reading such a value with strtotime() and
// comparing it with time() is wrong by the zone's offset: links, sessions and
// lock-outs ended hours early or late. Every "has it ended?" question is
// therefore asked in SQL (`expiresAt` > NOW() AS `isLive`, `lockedUntil` >
// NOW() AS `isLocked`), and a new lock-out is written with DATE_ADD(NOW(),
// ...) so one clock both writes and reads it.
// tests/test_expiry_clock.php fails if a PHP-side comparison comes back.
// ------------------------------------------------------------------------------

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
 * 🔁 Which emailed-link token to put back into a reset or verify form when the
 *    page is shown again after a mistake (independent review, Codex C3
 *    finding 1, 2026-11-25).
 *
 * The link opens the page with ?token=... in the web address, but the form
 * posts to the bare page address. After "passwords do not match", "too weak"
 * or "wrong password" the form is drawn again; if the token came only from
 * the web address it was now empty, and the next try failed as "invalid or
 * expired". The token typed into the form's hidden box is kept instead.
 *
 * @param string $tokenFromAddress Token from the web address ('' if none)
 * @param string $tokenFromForm    Token the form posted ('' if none)
 *
 * @return string The token to put in the hidden box ('' if none usable)
 */
function accountLinkTokenForForm(string $tokenFromAddress, string $tokenFromForm): string
{
    if ($tokenFromAddress !== '') {
        return $tokenFromAddress;
    }

    if ($tokenFromForm !== '' && mb_strlen($tokenFromForm) <= 128) {
        return $tokenFromForm;
    }

    return '';
}

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

/** @var int Minutes a no-JavaScript email sign-in link lasts (#163, decision 0038; fn_no_script_route.php). Kept with the other link lifetimes, which fn_email.php reads. */
if (!defined('CUERCODE_NO_SCRIPT_SIGN_IN_TTL_MINUTES')) {
    define('CUERCODE_NO_SCRIPT_SIGN_IN_TTL_MINUTES', 15);
}

/** @var string The one answer sign-up always gives (anti-enumeration), shared with the no-JavaScript route (#163, 0038). */
if (!defined('CUERCODE_REGISTER_GENERIC_MESSAGE')) {
    define(
        'CUERCODE_REGISTER_GENERIC_MESSAGE',
        'If this email address is available for registration, a verification link has been sent to it.'
    );
}

/**
 * 📝 The identical "if that address can be used, a link is on its way" result
 *    sign-up gives in every case. Also used by the no-JavaScript route (0038)
 *    when its limits stop the email, so that answer is the same too. PURE.
 *
 * @return array{success: bool, httpCode: int, errorCode: string|null, message: string, data: array}
 */
function registerUserGenericResult(): array
{
    return [
        'success'   => true,
        'httpCode'  => 201,
        'errorCode' => null,
        'message'   => CUERCODE_REGISTER_GENERIC_MESSAGE,
        'data'      => [],
    ];
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
    $genericSuccess = registerUserGenericResult();

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
    return authLinkRequestLimitSettings('verification_resend', $getSetting);
}

/**
 * 📏 The ONE place that says how big each "new emailed link" limit may be, and
 *    what it is when nothing is saved. Both the code that enforces the limits
 *    (authLinkRequestLimitSettings() below) and the admin settings editor
 *    (_functions/fn_admin_settings.php) read it, so the two can never disagree
 *    about what a safe value is. PURE.
 *
 * The three limits (each has its own setting, named PREFIX + "_" + the key
 * below, for each of the two forms):
 *   min_gap_seconds - the shortest wait between two links to the same account;
 *   daily_cap       - the most links one account may be sent in 24 hours;
 *   ip_per_hour     - the most requests one visitor address may make in an hour.
 *
 * @return array<string, array{min: int, max: int, default: int}>
 */
function authLinkRequestLimitBounds(): array
{
    return [
        'min_gap_seconds' => ['min' => 10, 'max' => 86400, 'default' => 60],
        'daily_cap'       => ['min' => 1,  'max' => 50,    'default' => 5],
        'ip_per_hour'     => ['min' => 1,  'max' => 1000,  'default' => 10],
    ];
}

/**
 * ⚙️ Read the three limits for an emailed-link form ("send me a new link" or
 *    "Forgot password") from the settings. Shared so both forms behave alike.
 *
 * Settings (group 'security'), with PREFIX = $prefix:
 *   PREFIX_min_gap_seconds (default 60, kept within 10-86400),
 *   PREFIX_daily_cap       (default 5,  kept within 1-50),
 *   PREFIX_ip_per_hour     (default 10, kept within 1-1000).
 * (The numbers come from authLinkRequestLimitBounds().)
 * A missing, non-numeric or throwing value falls back to the default; a silly
 * number is pulled into the safe range. None is commercial, so none is a
 * hard-coded rule: each can be stored in tblSettings to change it, and the
 * admin settings editor does exactly that.
 *
 * @param string        $prefix     'verification_resend' or 'password_reset_request'
 * @param callable|null $getSetting Called as $getSetting(string $key, mixed $default);
 *                                  getConfig when null (a stand-in in tests)
 * @return array{minGapSeconds: int, dailyCap: int, ipPerHour: int}
 */
function authLinkRequestLimitSettings(string $prefix, ?callable $getSetting = null): array
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

    $bounds = authLinkRequestLimitBounds();
    $clamped = [];

    foreach ($bounds as $suffix => $bound) {
        $key = $prefix . '_' . $suffix;

        try {
            $raw = $getSetting($key, $bound['default']);
        } catch (\Throwable $e) {
            $raw = $bound['default'];
        }

        if (is_int($raw) || (is_string($raw) && preg_match('/^\d{1,9}$/', $raw) === 1)) {
            $number = (int) $raw;
        } else {
            $number = $bound['default'];
        }

        $clamped[$suffix] = cuercodeClampInt($number, $bound['min'], $bound['max']);
    }

    return [
        'minGapSeconds' => $clamped['min_gap_seconds'],
        'dailyCap'      => $clamped['daily_cap'],
        'ipPerHour'     => $clamped['ip_per_hour'],
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
    if (isset($facts['ipRequestsInLastHour'])) {
        $ipRequests = (int) $facts['ipRequestsInLastHour'];
    } else {
        $ipRequests = 0;
    }

    if ($ipRequests > (int) $limits['ipPerHour']) {
        return ['send' => false, 'reason' => 'ip_limited'];
    }

    if (empty($facts['accountIsPending'])) {
        return ['send' => false, 'reason' => 'no_pending_account'];
    }

    if (isset($facts['secondsSinceLastLink'])) {
        $secondsSince = $facts['secondsSinceLastLink'];
    } else {
        $secondsSince = null;
    }

    if ($secondsSince !== null && (int) $secondsSince < (int) $limits['minGapSeconds']) {
        return ['send' => false, 'reason' => 'too_soon'];
    }

    if (isset($facts['linksInLast24Hours'])) {
        $linksInDay = (int) $facts['linksInLast24Hours'];
    } else {
        $linksInDay = 0;
    }

    if ($linksInDay >= (int) $limits['dailyCap']) {
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
    return countLinkRequestsFromIp($ipAddress, CUERCODE_RESEND_VERIFICATION_ENDPOINT);
}

/**
 * 🔢 Count this IP address's requests to ONE emailed-link form in the last
 *    hour (shared by "send me a new link" and "Forgot password").
 *
 * @param string $ipAddress Client IP address
 * @param string $endpoint  The name that form writes to the request log
 * @return int Requests in the last hour (0 when the log cannot be read - the
 *             check fails open, like checkRateLimit())
 */
function countLinkRequestsFromIp(string $ipAddress, string $endpoint): int
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
            [mb_substr($ipAddress, 0, 45), $endpoint]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('warning', 'Emailed-link request IP count failed', $e->getMessage());
        }
        return 0;
    }

    if (is_array($row) && isset($row['requestCount'])) {
        return (int) $row['requestCount'];
    }

    return 0;
}

/** @var int Seconds to wait for the per-address lock around "record, then count" (final review M-1). */
if (!defined('CUERCODE_LINK_REQUEST_IP_LOCK_SECONDS')) {
    define('CUERCODE_LINK_REQUEST_IP_LOCK_SECONDS', 3);
}

/**
 * 🌍 Record THIS request to an emailed-link form, THEN count this visitor
 *    address's requests in the last hour - the count includes this one.
 *
 * Final review M-1 (2026-11-25): the old order was "count, then record".
 * Twenty requests sent at the same moment could all count before any of them
 * had recorded, so every one saw a small number and all twenty got through.
 *
 * Now the two steps run one request at a time for each visitor address,
 * inside a short database "named lock" (GET_LOCK - the same technique as the
 * saved-codes limit in fn_api_qrcodes.php). The lock covers only these two
 * quick statements, and is named after a hash of the database, the form and
 * the address (never the address itself, and under the 64-character limit).
 * So the request recorded in place N sees exactly N: the first ones up to the
 * allowance get through, the rest are refused - however many arrive together.
 *
 * Every request takes this lock, known address or not, so an unknown address
 * does not take a different path (the time it takes says nothing).
 *
 * If the lock cannot be had (a busy or faulty database), the request still
 * records first and counts afterwards. That can only ever count MORE than the
 * real number (requests arriving together may all see each other), so it errs
 * on the safe side: it may refuse, it never lets extra requests through.
 * (Measured without the lock: twenty at once from one address on MariaDB 10.6
 * were ALL refused - safe, but the lock is what makes the answer exact.)
 *
 * @param string $ipAddress Client IP address
 * @param string $endpoint  The name the form writes to the request log
 * @return int Requests in the last hour INCLUDING this one (at least 1)
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/locking-functions.html#function_get-lock
 */
function authRecordThenCountLinkRequest(string $ipAddress, string $endpoint): int
{
    $databaseName = '';
    if (defined('DB_NAME')) {
        $databaseName = (string) DB_NAME;
    }

    // 🔐 "cuercode_lr:" + 40 hex characters = 52 characters (limit 64).
    $lockName = 'cuercode_lr:' . substr(hash('sha256', $databaseName . '|' . $endpoint . '|' . mb_substr($ipAddress, 0, 45)), 0, 40);
    $haveLock = false;

    try {
        $lockStmt = dbQuery('SELECT GET_LOCK(?, ?) AS `lockResult`', 'si', [$lockName, CUERCODE_LINK_REQUEST_IP_LOCK_SECONDS]);
        $lockRow  = $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();
        // 📌 1 = acquired; 0 = waited too long; NULL = error.
        $haveLock = (is_array($lockRow) && isset($lockRow['lockResult']) && (int) $lockRow['lockResult'] === 1);
    } catch (\Throwable $e) {
        $haveLock = false;
    }

    if (function_exists('recordAPIRequest')) {
        recordAPIRequest(null, $ipAddress, $endpoint);
    }

    // 📌 If recording failed, the count may not include this request; it
    //    is still at least 1 (this request), so the limit is never looser.
    $count = max(1, countLinkRequestsFromIp($ipAddress, $endpoint));

    if ($haveLock) {
        try {
            $releaseStmt = dbQuery('SELECT RELEASE_LOCK(?) AS `releaseResult`', 's', [$lockName]);
            $releaseStmt->close();
        } catch (\Throwable $e) {
            // The lock belongs to this connection and ends with it anyway.
        }
    } elseif (function_exists('logError')) {
        logError('warning', 'Emailed-link request: the per-address lock was not available; counted without it (errs on the safe side)', $endpoint);
    }

    return $count;
}

/**
 * ⏱️ One account's emailed-link history: how many links in the last 24 hours,
 *    and how many seconds since the newest one (null = never). Any database
 *    error is thrown (the caller is inside a transaction and rolls back).
 *
 * @param string $table  'tblPasswordResets' or 'tblEmailVerifications' (nothing else is accepted)
 * @param int    $userID The account
 * @return array{linksInDay: int, secondsSince: int|null}
 * @throws \InvalidArgumentException For any other table name
 */
function authLinkHistory(string $table, int $userID): array
{
    // 🛡️ The table name cannot be a placeholder, so only these two are allowed.
    if ($table !== 'tblPasswordResets' && $table !== 'tblEmailVerifications') {
        throw new \InvalidArgumentException('not an emailed-link table');
    }

    $stmt = dbQuery(
        'SELECT COUNT(CASE WHEN `createdAt` >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) AS `dayCount`, '
        . 'TIMESTAMPDIFF(SECOND, MAX(`createdAt`), NOW()) AS `secondsSince` '
        . 'FROM `' . $table . '` WHERE `userID` = ?',
        'i',
        [$userID]
    );
    $history = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $result = ['linksInDay' => 0, 'secondsSince' => null];

    if (is_array($history)) {
        $result['linksInDay'] = (int) $history['dayCount'];
        if ($history['secondsSince'] !== null) {
            $result['secondsSince'] = (int) $history['secondsSince'];
        }
    }

    return $result;
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
 * Requests at the same moment (final review M-1, 2026-11-25): the per-IP
 * allowance records the request before counting (authRecordThenCountLinkRequest()),
 * and the per-account checks run while holding the account row, so twenty
 * requests sent together still give one link (measured on real MySQL 8.0,
 * 8.4 and MariaDB 10.6 - see tests/integration/account_recovery_real_mysql.php).
 *
 * Known, accepted limit: the fixed minimum time only hides differences
 * smaller than itself (a slow database or mail queue could still take longer
 * than that for a waiting account).
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
    return limitedRowWrittenRecently($userID, 'verification_link_resend_limited');
}

/**
 * 🕐 Has an activity row of this "refused by the rate limit" type already
 *    been written for this account in the last hour? (Shared by both
 *    emailed-link forms.) Any database problem answers TRUE, so a fault can
 *    only ever mean "write nothing", never "write another row".
 *
 * @param int    $userID       Account number
 * @param string $activityType The limited-row type to look for
 * @return bool
 */
function limitedRowWrittenRecently(int $userID, string $activityType): bool
{
    try {
        $stmt = dbQuery(
            'SELECT COUNT(*) AS `rowCount` FROM `tblActivityLog` '
            . "WHERE `activityType` = ? AND `entityType` = 'user' AND `entityID` = ? "
            . 'AND `createdAt` >= DATE_SUB(NOW(), INTERVAL 1 HOUR)',
            'si',
            [$activityType, $userID]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        return true;
    }

    return (is_array($row) && (int) $row['rowCount'] > 0);
}

/**
 * 🔒 Lock one account row WITHOUT waiting, for the two emailed-link request
 *    forms (independent review, Codex C3 finding 5, 2026-11-25).
 *
 * "Forgot password" and "Send me a new link" answer in at least 250 ms
 * whatever happens, so the answer time does not show whether an address has
 * an account. But a KNOWN account's request locks its row, and while another
 * request holds that row it used to WAIT - measured 1.47 s against 0.25 s for
 * an unknown address on MySQL 8.0 - which did show it. With NOWAIT (MySQL
 * 8.0 and MariaDB 10.3+) a busy row is reported at once instead. The caller
 * then gives the usual answer and does nothing more: whoever holds the row
 * is already dealing with that account, and the one-link-a-minute rule would
 * refuse a second link anyway.
 *
 * Run inside the caller's transaction, on the site connection. The column
 * list is fixed text from the caller, never visitor input.
 *
 * @param int    $userID  The account
 * @param string $columns Back-quoted column list, e.g. "`status`"
 * @return array{busy: bool, row: array<string, mixed>|null}
 * @throws \Throwable Any database error other than "row is busy"
 */
function authLockAccountRowNoWait(int $userID, string $columns): array
{
    // 🔢 MySQL reports a NOWAIT miss as 3572 (ER_LOCK_NOWAIT); MariaDB as 1205.
    $busyCodes = [3572, 1205];
    $db        = getDB();

    $statement = $db->prepare('SELECT ' . $columns . ' FROM `tblUsers` WHERE `userID` = ? LIMIT 1 FOR UPDATE NOWAIT');
    if ($statement === false) {
        throw new \RuntimeException('Could not prepare the account lock: ' . $db->error);
    }

    try {
        $statement->bind_param('i', $userID);
        if (!$statement->execute()) {
            $errorNumber = $statement->errno;
            $errorText   = $statement->error;
            $statement->close();
            if (in_array($errorNumber, $busyCodes, true)) {
                return ['busy' => true, 'row' => null];
            }
            throw new \RuntimeException('Could not lock the account: ' . $errorText);
        }
    } catch (\mysqli_sql_exception $lockError) {
        $statement->close();
        if (in_array((int) $lockError->getCode(), $busyCodes, true)) {
            return ['busy' => true, 'row' => null];
        }
        throw $lockError;
    }

    $lockedRow = $statement->get_result()->fetch_assoc();
    $statement->close();

    if (!is_array($lockedRow)) {
        $lockedRow = null;
    }

    return ['busy' => false, 'row' => $lockedRow];
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

    if (!function_exists('dbQuery') || !function_exists('getDB')) {
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

    // 🌍 Per-IP allowance (final review M-1): record THIS request first, then
    //    count including it, so requests sent at the same moment cannot all
    //    slip under the allowance. Every attempt, known address or not, uses
    //    up the same allowance.
    $ipRequests = authRecordThenCountLinkRequest($ipAddress, CUERCODE_RESEND_VERIFICATION_ENDPOINT);

    // 🔍 Is there a waiting account? (Only ever used internally.) A plain
    //    read with no lock: an unknown address never takes a lock at all
    //    (see the note on locks below).
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

    if (!$isPending) {
        // 🚫 No waiting account: nothing is sent and no lock is taken. (The
        //    250 ms minimum time in requestNewVerificationLink() hides the
        //    few milliseconds the locked path below takes.)
        return $genericResult;
    }

    // 🔒 Final review M-1 (2026-11-25): the history read, the decision and the
    //    new link happen in ONE transaction holding the account row
    //    (SELECT ... FOR UPDATE - the same lock order as every other change to
    //    an account: the account row first). Twenty requests for one account
    //    sent together now queue on that row: the first sends a link, and each
    //    later one sees it and is "too soon". The lock is on the account's
    //    own row (its primary key), never on the email address: a locking
    //    read of an address that has no account would lock a GAP in the
    //    address index and could hold up sign-ups and other visitors, and
    //    would also make unknown addresses take a different path. Unknown
    //    addresses take no lock (above).
    $userID  = (int) $account['userID'];
    $db      = getDB();
    $started = false;

    try {
        $db->begin_transaction();
        $started = true;

        // ⏱️ Codex C3 finding 5: never WAIT for a busy account row (the wait
        //    would make a known address answer more slowly than an unknown
        //    one). Busy: the usual answer, nothing more.
        $lockAttempt = authLockAccountRowNoWait($userID, '`status`, `emailVerifiedAt`');
        if ($lockAttempt['busy']) {
            authRollbackQuietly($db);
            return $genericResult;
        }
        $locked = $lockAttempt['row'];

        // 🔁 Still waiting? (It may have been verified or reset meanwhile.)
        if (!is_array($locked) || (string) $locked['status'] !== 'pending' || $locked['emailVerifiedAt'] !== null) {
            $db->commit();
            return $genericResult;
        }

        $history = authLinkHistory('tblEmailVerifications', $userID);

        $decision = decideVerificationResend(
            [
                'ipRequestsInLastHour' => $ipRequests,
                'accountIsPending'     => true,
                'secondsSinceLastLink' => $history['secondsSince'],
                'linksInLast24Hours'   => $history['linksInDay'],
            ],
            $limits
        );

        $issued = false;

        if ($decision['send']) {
            $displayName = null;

            if ($account['displayName'] !== null && (string) $account['displayName'] !== '') {
                $displayName = (string) $account['displayName'];
            }

            // 📧 Saves the link, retires older ones and queues the email, all
            //    on this connection, so inside this transaction.
            $issued = issueEmailVerificationLink($userID, $normalizedEmail, $displayName, true);
        } elseif ($decision['reason'] !== 'too_soon' && function_exists('logActivity') && !resendLimitedRowWrittenRecently($userID)) {
            // 📝 A plain double-click inside the minimum gap is not worth a log row,
            //    and (review fix M3) neither is every further request once the
            //    limit is hit: at most one row per account per hour (decided
            //    under the lock, so simultaneous requests cannot each write one).
            logActivity(
                'verification_link_resend_limited',
                'A new verification link was refused by the rate limit (' . $decision['reason'] . ')',
                'user',
                $userID,
                []
            );
        }

        $db->commit();
    } catch (\Throwable $e) {
        if ($started) {
            authRollbackQuietly($db);
        }
        if (function_exists('logError')) {
            logError('error', 'Resend-verification request failed', $e->getMessage());
        }
        return $genericResult;
    }

    if ($issued && function_exists('logActivity')) {
        // 🙈 The account NUMBER only - never the address, link or token.
        logActivity('verification_link_resent', 'A new verification link was requested', 'user', $userID, []);
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
            'SELECT `v`.`tokenHash`, (`v`.`expiresAt` > NOW()) AS `isLive`, `v`.`usedAt`, `u`.`passwordHash` '
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

    // ⏱️ #158 / #130: the DATABASE says whether the link is still live (see
    //    the note on database times above computeLockoutExpiry()). A missing
    //    or NULL answer counts as ended.
    if (!isset($row['isLive']) || (int) $row['isLive'] !== 1) {
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
    // 📣 #113 B9 (finding 8): a used-up link can mean TWO things - it was used,
    //    or asking for a new link (resend) retired it. The token table keeps
    //    only "usedAt" for both, and a link also stops after too many wrong
    //    passwords, so the two cannot be told apart without a schema change.
    //    The wording therefore covers both and says what to do next.
    $alreadyUsedResult = [
        'success'   => false,
        'httpCode'  => 409,
        'errorCode' => 'VERIFICATION_ALREADY_USED',
        'message'   => 'This link has already been used or has been replaced by a newer one. Please use the newest email we sent you, or ask for a new link.',
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
            'SELECT `verificationID`, `userID`, `tokenHash`, (`expiresAt` > NOW()) AS `isLive`, `usedAt` '
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

    // ⏱️ #158 / #130: judged by the database's clock, in UTC.
    if (!isset($row['isLive']) || (int) $row['isLive'] !== 1) {
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
            'SELECT `passwordHash`, `failedLoginCount`, '
            . '(`lockedUntil` IS NOT NULL AND `lockedUntil` > NOW()) AS `isLocked` '
            . 'FROM `tblUsers` WHERE `userID` = ? LIMIT 1',
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

    // ⏱️ #158 / #130: "is it still locked?" is answered by the database.
    $accountLocked = ((int) $account['isLocked'] === 1);

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
        // 🔢 A wrong password counts toward the normal sign-in lock-out. The
        //    database adds the one and says the real new count (review
        //    finding 7), which may be higher than our guess if other wrong
        //    tries landed at the same moment - so the "stop the link" decision
        //    is taken again with the real number.
        $recordedCount = recordFailedLogin($userID);
        if ($recordedCount !== null) {
            $outcome = emailVerificationPasswordOutcome(true, true, false, false, $recordedCount, $maxAttempts);
        }

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

    // 📣 Review nit (2026-11-25): only a WAITING account is "verified, you
    //    can now log in". An account that is already active is told plainly
    //    that nothing changed; a suspended or deleted one gets the usual
    //    "invalid" answer, and nothing changes.
    $alreadyActiveResult = [
        'success'   => false,
        'httpCode'  => 409,
        'errorCode' => 'VERIFICATION_ALREADY_USED',
        'message'   => 'This email address had already been confirmed, so nothing was changed. You can sign in as usual.',
        'data'      => [],
    ];

    try {
        $db->begin_transaction();
        $transactionStarted = true;

        // 🔐 The account FIRST, locked until the end of the transaction -
        //    the same order as a password reset (review finding 5), so the
        //    two can never wait for each other in a circle.
        $statusStmt = dbQuery('SELECT `status`, `passwordHash` FROM `tblUsers` WHERE `userID` = ? LIMIT 1 FOR UPDATE', 'i', [$userID]);
        $statusRow  = $statusStmt->get_result()->fetch_assoc();
        $statusStmt->close();

        $accountStatus = '';
        if (is_array($statusRow)) {
            $accountStatus = (string) $statusRow['status'];
        }

        if ($accountStatus !== 'pending' && $accountStatus !== 'active') {
            // 🚫 Suspended, deleted or gone: nothing changes, the usual answer.
            authRollbackQuietly($db);
            return $invalidResult;
        }

        // 🔒 Final review I-1 (2026-11-25): the password was checked above,
        //    outside the lock (the check is slow on purpose). The stored
        //    password must still be EXACTLY the one checked (or still none);
        //    if a reset or change landed in between, the old password must
        //    not activate anything. Nothing changes and the link still works.
        $passwordSeen = null;
        if ($account['passwordHash'] !== null) {
            $passwordSeen = (string) $account['passwordHash'];
        }
        $passwordNow = null;
        if (is_array($statusRow) && $statusRow['passwordHash'] !== null) {
            $passwordNow = (string) $statusRow['passwordHash'];
        }
        $passwordUnchanged = ($passwordSeen === null && $passwordNow === null)
            || ($passwordSeen !== null && $passwordNow !== null && hash_equals($passwordSeen, $passwordNow));

        if (!$passwordUnchanged) {
            authRollbackQuietly($db);
            return $invalidResult;
        }

        // 🔒 Atomically claim the token (usedAt IS NULL guard) BEFORE
        // activating the account, closing the race window between two
        // simultaneous requests for the same token.
        // ⏰ Independent review (Codex C3 finding 3, 2026-11-25): the claim
        // also requires "not expired" (database clock), because the expiry
        // was otherwise only checked before the slow password check and the
        // wait for the account lock (reproduced on real MySQL).
        $claimStmt = dbQuery(
            'UPDATE `tblEmailVerifications` SET `usedAt` = NOW() '
            . 'WHERE `verificationID` = ? AND `usedAt` IS NULL AND `expiresAt` > NOW()',
            'i',
            [(int) $row['verificationID']]
        );
        $claimed = ($claimStmt->affected_rows === 1);
        $claimStmt->close();

        if (!$claimed) {
            // 📌 Lost a race to another concurrent verification attempt for
            // the same token - or the link expired while this one waited.
            $claimCheckStmt = dbQuery('SELECT `usedAt` FROM `tblEmailVerifications` WHERE `verificationID` = ? LIMIT 1', 'i', [(int) $row['verificationID']]);
            $claimCheckRow  = $claimCheckStmt->get_result()->fetch_assoc();
            $claimCheckStmt->close();
            authRollbackQuietly($db);

            if (is_array($claimCheckRow) && $claimCheckRow['usedAt'] !== null) {
                return $alreadyUsedResult;
            }
            return $invalidResult;
        }

        if ($accountStatus === 'active') {
            // ℹ️ Already active (for example a second, older link): the link
            //    is used up (it can do nothing more) and nothing else changes.
            $db->commit();
            return $alreadyActiveResult;
        }

        // 🧹 A successful password also clears the lock-out counter.
        $activateStmt = dbQuery(
            "UPDATE `tblUsers` SET `emailVerifiedAt` = NOW(), `status` = 'active', `failedLoginCount` = 0, `lockedUntil` = NULL "
            . "WHERE `userID` = ? AND `status` = 'pending' AND " . adminSqlNotGlobalAdminCondition(),
            'i',
            [$userID]
        );
        $activatedRows = $activateStmt->affected_rows;
        $activateStmt->close();

        if ($activatedRows !== 1) {
            // 🚫 Not activated (for example it holds the Global Admin role):
            //    undo the claim so nothing at all changed.
            authRollbackQuietly($db);
            if (function_exists('logError')) {
                logError('warning', 'Verification link did not activate the account; nothing was changed', 'userID ' . $userID);
            }
            return $invalidResult;
        }

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
 * 🚪 The one "invalid email or password" answer every failed sign-in gets
 *    (no enumeration). PURE.
 *
 * @return array{success: bool, httpCode: int, errorCode: string, message: string, data: array}
 */
function loginUserGenericFailure(): array
{
    return [
        'success'   => false,
        'httpCode'  => 401,
        'errorCode' => 'INVALID_CREDENTIALS',
        'message'   => 'Invalid email or password, or the account is not yet verified.',
        'data'      => [],
    ];
}

/**
 * 🔐 Check an email and password WITHOUT signing anyone in (#163, 0038).
 *
 * The first half of loginUser(), shared with the no-JavaScript email sign-in
 * (fn_no_script_route.php), so both apply exactly the same rules: the same
 * look-up, the same always-paid password check (a dummy hash when there is no
 * account, so the time taken does not show whether one exists), the same
 * refusal for a locked, waiting or suspended account, and the same
 * wrong-password counting and lock-out (recordFailedLogin()).
 *
 * @param string $email         Candidate email address
 * @param string $plainPassword Candidate plaintext password
 *
 * @return array{ok: bool, result: array, row: array<string, mixed>|null, sessionsValidFromSeen: string|null}
 *         ok=false: 'result' is the answer to give (generic, or 503 with no database)
 */
function loginUserCheckPassword(string $email, #[\SensitiveParameter] string $plainPassword): array
{
    $genericFailResult = loginUserGenericFailure();
    $refused           = ['ok' => false, 'result' => $genericFailResult, 'row' => null, 'sessionsValidFromSeen' => null];

    if (!function_exists('dbQuery')) {
        $refused['result'] = [
            'success'   => false,
            'httpCode'  => 503,
            'errorCode' => 'SERVICE_UNAVAILABLE',
            'message'   => 'Login is temporarily unavailable. Please try again later.',
            'data'      => [],
        ];
        return $refused;
    }

    $normalizedEmail = mb_strtolower(trim($email));

    // 🕐 Review finding 1: the account's "sessions valid from" mark is read in
    //    the SAME statement as the password hash, so the two always belong
    //    together. If a reset changes the password after this read, it also
    //    moves the mark, and the session is refused (issueUserSession()).
    $validFromColumn = '';
    if (authSessionsValidFromReady()) {
        $validFromColumn = ', `sessionsValidFrom`';
    }

    try {
        $stmt = dbQuery(
            'SELECT `userID`, `email`, `passwordHash`, `displayName`, `status`, '
            . '`failedLoginCount`, (`lockedUntil` IS NOT NULL AND `lockedUntil` > NOW()) AS `isLocked`' . $validFromColumn . ' '
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
        return $refused;
    }

    // 🕐 Timing-safe non-enumeration: always pay the Argon2id verification
    // cost, even for a non-existent account, so response latency cannot be
    // used to distinguish "no such account" from "wrong password".
    if ($row === null) {
        if (function_exists('verifyPassword') && function_exists('cuercodeDummyPasswordHash')) {
            verifyPassword($plainPassword, cuercodeDummyPasswordHash());
        }
        return $refused;
    }

    $userID = (int) $row['userID'];
    $status = (string) $row['status'];

    // ⏱️ #158 / #130: "is it still locked?" is answered by the database.
    $isLocked = ((int) $row['isLocked'] === 1);

    // 🔒 Always run verifyPassword() regardless of lock/status state, so a
    // locked/pending/suspended account does not produce a different timing
    // profile than a normal wrong-password check on an active account.
    $passwordOk = verifyPassword($plainPassword, (string) $row['passwordHash']);

    if ($isLocked || $status !== 'active' || !$passwordOk) {
        // 📌 Only extend the failure counter for a genuinely wrong password
        // against an ACTIVE, unlocked account - a probe against an
        // already-locked or not-yet-active account has nothing new to record.
        // (Review finding 7: the counter is raised by the database itself,
        // so wrong passwords sent at the same moment all count.)
        if (!$isLocked && $status === 'active' && !$passwordOk) {
            recordFailedLogin($userID);
        }
        return $refused;
    }

    $sessionsValidFromSeen = null;
    if (isset($row['sessionsValidFrom']) && $row['sessionsValidFrom'] !== null) {
        $sessionsValidFromSeen = (string) $row['sessionsValidFrom'];
    }

    return ['ok' => true, 'result' => [], 'row' => $row, 'sessionsValidFromSeen' => $sessionsValidFromSeen];
}

/**
 * ✅ After a correct password: reset the failure counter (and, for a real
 *    sign-in, note when it happened), and quietly re-hash an old-style
 *    password hash. Shared by loginUser() and the email sign-in (0038).
 *
 * @param array<string, mixed> $row           The row loginUserCheckPassword() read
 * @param string               $plainPassword The password just checked
 * @param bool                 $markSignedIn  Also set lastLoginAt (false while an email sign-in waits)
 *
 * @return void
 */
function loginUserAfterPassword(array $row, #[\SensitiveParameter] string $plainPassword, bool $markSignedIn): void
{
    $userID = (int) $row['userID'];

    if ($markSignedIn) {
        $resetSql = 'UPDATE `tblUsers` SET `failedLoginCount` = 0, `lockedUntil` = NULL, `lastLoginAt` = NOW() WHERE `userID` = ?';
    } else {
        $resetSql = 'UPDATE `tblUsers` SET `failedLoginCount` = 0, `lockedUntil` = NULL WHERE `userID` = ?';
    }

    try {
        $resetStmt = dbQuery($resetSql, 'i', [$userID]);
        $resetStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('warning', 'Post-login counter reset failed', $e->getMessage());
        }
    }

    // 🔁 Transparent Argon2id rehash if the stored hash used older cost
    // parameters than fn_password.php currently specifies.
    // 🕐 Review finding 1: only while the stored hash is STILL the one just
    //    checked. Otherwise a sign-in with the OLD password that was slow
    //    enough to straddle a password reset would write the old password
    //    back over the new one.
    if (function_exists('passwordNeedsRehash') && passwordNeedsRehash((string) $row['passwordHash'])) {
        try {
            $newHash    = hashPassword($plainPassword);
            $rehashStmt = dbQuery(
                'UPDATE `tblUsers` SET `passwordHash` = ? WHERE `userID` = ? AND `passwordHash` = ?',
                'sis',
                [$newHash, $userID, (string) $row['passwordHash']]
            );
            $rehashStmt->close();
        } catch (\Throwable $e) {
            if (function_exists('logError')) {
                logError('warning', 'Transparent password rehash failed', $e->getMessage());
            }
        }
    }
}

/**
 * 🚪 Authenticate a user by email + password and issue a session on success.
 *
 * ANTI-ENUMERATION: every failure path (no such account, wrong password,
 * locked account, unverified/pending account, suspended account) returns the
 * SAME generic 401 message and error code - none of these states are
 * distinguishable from the response. password_verify() is ALWAYS invoked
 * (against a fixed dummy hash when no account row exists) so response timing
 * does not leak account existence either. (#163: the checks live in
 * loginUserCheckPassword(), shared with the no-JavaScript email sign-in.)
 *
 * On success the result also carries 'userID' at the TOP level (never inside
 * 'data', which the JSON route sends to the browser), so the human check can
 * note a sign-in let through during an outage (decision 0037).
 *
 * @param string $email         Candidate email address
 * @param string $plainPassword Candidate plaintext password
 * @param string $ipAddress     Client IP address (for the session + lockout audit trail)
 * @param string $userAgent     Client user agent string (for the session audit trail)
 *
 * @return array{success: bool, httpCode: int, errorCode: string|null, message: string, data: array, userID?: int}
 *
 * @see loginUserCheckPassword()
 * @see issueUserSession()
 * @see recordFailedLogin()
 * @see fn_password.php - verifyPassword(), cuercodeDummyPasswordHash()
 */
function loginUser(string $email, #[\SensitiveParameter] string $plainPassword, string $ipAddress, string $userAgent): array
{
    $check = loginUserCheckPassword($email, $plainPassword);

    if (!$check['ok']) {
        return $check['result'];
    }

    $row    = $check['row'];
    $userID = (int) $row['userID'];

    // ✅ Success - reset the failure counter and record the login.
    loginUserAfterPassword($row, $plainPassword, true);

    // 🎫 Refused (empty) when the password was reset while this sign-in was
    //    being checked: the person sees the usual "invalid" answer.
    $issuedSession = issueUserSession($userID, $ipAddress, $userAgent, $check['sessionsValidFromSeen']);

    if ($issuedSession === '') {
        return loginUserGenericFailure();
    }

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
        'userID'    => $userID,
    ];
}

// ==============================================================================
// 🔢 recordFailedLogin() - Increment failure counter + apply lockout
// ==============================================================================

/**
 * 🔢 Add one to the account's failed-login count and, once the configured
 * threshold is reached, set the lock-out expiry.
 *
 * 🔢 Review finding 7 (2026-11-25): the database adds the one itself
 *    (`failedLoginCount` = `failedLoginCount` + 1) and the new value is read
 *    back, instead of writing a number worked out from an earlier read. With
 *    the old way, ten wrong passwords sent at the same moment could all read
 *    "3" and all write "4", so the lock-out never came. Now each one counts.
 *    The count stops at 65535 (the column's largest value) rather than fail.
 *
 * @param int $userID The tblUsers.userID whose counter to update
 *
 * @return int|null The count AFTER this failure, or null if it could not be
 *                  recorded or read back (the caller then uses its own guess)
 *
 * @see computeLockoutExpiry()
 */
function recordFailedLogin(int $userID): ?int
{
    if (!function_exists('dbQuery')) {
        return null;
    }

    $maxAttempts    = CUERCODE_DEFAULT_MAX_LOGIN_ATTEMPTS;
    $lockoutSeconds = CUERCODE_DEFAULT_LOCKOUT_DURATION_SECONDS;

    if (function_exists('getConfig')) {
        $maxAttempts    = (int) getConfig('max_login_attempts', $maxAttempts);
        $lockoutSeconds = (int) getConfig('lockout_duration', $lockoutSeconds);
    }

    try {
        // ➕ 1. The database adds one (no lost updates).
        $stmt = dbQuery(
            'UPDATE `tblUsers` SET `failedLoginCount` = LEAST(`failedLoginCount` + 1, 65535) WHERE `userID` = ?',
            'i',
            [$userID]
        );
        $stmt->close();

        // 👀 2. Read the count back (it includes any other failure that
        //    landed at the same moment - that is the point).
        $readStmt = dbQuery('SELECT `failedLoginCount` FROM `tblUsers` WHERE `userID` = ? LIMIT 1', 'i', [$userID]);
        $countRow = $readStmt->get_result()->fetch_assoc();
        $readStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('warning', 'Failed-login counter update failed', $e->getMessage());
        }
        return null;
    }

    if (!is_array($countRow)) {
        return null;
    }

    $newFailedCount = (int) $countRow['failedLoginCount'];

    // 🔒 3. At (or past) the limit: lock the account for the set time.
    $lockoutExpiry = computeLockoutExpiry($newFailedCount, $maxAttempts, $lockoutSeconds, time());

    if ($lockoutExpiry !== null) {
        try {
            $lockStmt = dbQuery(
                'UPDATE `tblUsers` SET `lockedUntil` = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE `userID` = ?',
                'ii',
                [max(0, $lockoutSeconds), $userID]
            );
            $lockStmt->close();
        } catch (\Throwable $e) {
            if (function_exists('logError')) {
                logError('warning', 'Failed-login lock-out update failed', $e->getMessage());
            }
        }
    }

    return $newFailedCount;
}

// ==============================================================================
// 🕐 authSessionsValidFromReady() - is migration 0003 in place?
// ==============================================================================

/**
 * 🕐 Do tblUsers.sessionsValidFrom and tblUserSessions.sessionsValidFromSeen
 *    exist (migration 0003)? Asked once per request and remembered.
 *
 * WHY THIS EXISTS: the code can reach the server before the database upgrade
 * is applied. Without this check every sign-in would fail until the upgrade
 * ran - including the Global Admin's, who applies upgrades from /admin/. So,
 * until 0003 is applied, sign-in works exactly as it did before (and the
 * admin overview lists 0003 as waiting); once it is applied, the "sessions
 * valid from" protection is on. The question is asked of INFORMATION_SCHEMA
 * (the same place the upgrade tool looks), so a missing column is a plain
 * "0" answer rather than an error. If the question itself fails, the answer
 * is "ready": a database fault can never quietly switch the protection off
 * (the queries that follow then fail and nobody is signed in).
 *
 * @param bool $forget Forget the remembered answer (after an upgrade, and in tests)
 * @return bool
 */
function authSessionsValidFromReady(bool $forget = false): bool
{
    static $ready = null;

    if ($forget) {
        $ready = null;
        return false;
    }

    if ($ready !== null) {
        return $ready;
    }

    if (!function_exists('dbQuery')) {
        return false;
    }

    try {
        $stmt = dbQuery(
            'SELECT COUNT(*) AS `found` FROM `information_schema`.`COLUMNS` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() AND ('
            . '(`TABLE_NAME` = ? AND `COLUMN_NAME` = ?) OR (`TABLE_NAME` = ? AND `COLUMN_NAME` = ?))',
            'ssss',
            ['tblUsers', 'sessionsValidFrom', 'tblUserSessions', 'sessionsValidFromSeen']
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $ready = (is_array($row) && (int) $row['found'] === 2);
    } catch (\Throwable $e) {
        $ready = true;
    }

    // 📝 Deliberately no log line while the upgrade is waiting: it would be
    //    written on every page view. The admin overview already lists 0003
    //    as a waiting database upgrade.
    return $ready;
}

/**
 * 🔒 The answer given when a password reset or change is asked for before
 *    database upgrade 0003 is applied (independent review, Codex C3
 *    finding 4, 2026-11-25).
 *
 * Without upgrade 0003 a reset or change cannot end a sign-in that is already
 * under way (one that checked the OLD password a moment before), so the two
 * are refused - plainly, and without using up the emailed link - until the
 * upgrade is applied. Sign-in itself keeps working (so the Global Admin can
 * apply the upgrade at /admin/upgrades/), and the command-line tool
 * (_includes/cli/migrate.php --apply) always works. A warning is logged each
 * time, so the owner can see why people could not reset.
 *
 * @param string $what 'reset' or 'change' (only used in the log line)
 * @return array{success: bool, httpCode: int, errorCode: string, message: string, data: array}
 */
function authUpgradeNeededResult(string $what): array
{
    if (function_exists('logError')) {
        logError(
            'warning',
            'Password ' . $what . ' refused: database upgrade 0003 (sessions valid from) is not applied yet',
            'Apply it at /admin/upgrades/ or run _includes/cli/migrate.php --apply'
        );
    }

    return [
        'success'   => false,
        'httpCode'  => 503,
        'errorCode' => 'DATABASE_UPGRADE_NEEDED',
        'message'   => 'Passwords cannot be reset or changed for a short while because the site is being updated. '
            . 'Nothing has been changed and your link still works. Please try again later.',
        'data'      => [],
    ];
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
 * 🕐 Review finding 1 (2026-11-25): $sessionsValidFromSeen is the account's
 *    tblUsers.sessionsValidFrom AS READ TOGETHER WITH the credential the caller
 *    checked (the password row in loginUser(), the linked-sign-in row in the
 *    Google/Microsoft callback). The session row is only written if the
 *    account's mark is STILL that value, and the value is stored on the
 *    session so currentUser() can refuse it later if the mark moves. So a
 *    sign-in that started before a password reset can never end with a
 *    working session, however slow it was. Every caller MUST pass the value it
 *    read; there is no default on purpose.
 *
 * @param int         $userID                The newly-authenticated tblUsers.userID
 * @param string      $ipAddress             Client IP address (audit trail)
 * @param string      $userAgent             Client user agent string (audit trail)
 * @param string|null $sessionsValidFromSeen The account's sessionsValidFrom read with the credential (NULL = never moved)
 *
 * @return string The new session token (also stored in $_SESSION), or '' when
 *                no session was made (the account changed meanwhile, or the
 *                database refused) - the caller must then report a failure
 *
 * @see https://www.php.net/manual/en/function.session-regenerate-id.php
 */
function issueUserSession(int $userID, string $ipAddress, string $userAgent, ?string $sessionsValidFromSeen): string
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
            if (authSessionsValidFromReady()) {
                // 🕐 Written ONLY while the account's mark is still the one
                //    the caller saw (<=> is "equal, counting NULL = NULL").
                //    The account row is read with a shared lock (InnoDB does
                //    that for INSERT ... SELECT), so a reset holding the row
                //    makes this wait and then see the new mark.
                $stmt = dbQuery(
                    'INSERT INTO `tblUserSessions` (`sessionID`, `userID`, `ipAddress`, `userAgent`, `expiresAt`, `sessionsValidFromSeen`) '
                    . 'SELECT ?, `u`.`userID`, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), `u`.`sessionsValidFrom` '
                    . 'FROM `tblUsers` AS `u` WHERE `u`.`userID` = ? AND `u`.`sessionsValidFrom` <=> ?',
                    'sssiis',
                    [$sessionID, mb_substr($ipAddress, 0, 45), mb_substr($userAgent, 0, 500), $lifetimeSeconds, $userID, $sessionsValidFromSeen]
                );
            } else {
                // 🕰️ Before migration 0003: exactly the old behaviour.
                $stmt = dbQuery(
                    'INSERT INTO `tblUserSessions` (`sessionID`, `userID`, `ipAddress`, `userAgent`, `expiresAt`) '
                    . 'VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))',
                    'sissi',
                    [$sessionID, $userID, mb_substr($ipAddress, 0, 45), mb_substr($userAgent, 0, 500), $lifetimeSeconds]
                );
            }
            $inserted = $stmt->affected_rows;
            $stmt->close();
        } catch (\Throwable $e) {
            if (function_exists('logError')) {
                logError('error', 'Session issuance failed', $e->getMessage());
            }
            return '';
        }

        if ($inserted !== 1) {
            // 🚫 The account's password was reset (or it was otherwise
            //    secured) while this sign-in was being checked.
            if (function_exists('logError')) {
                logError('warning', 'Sign-in refused: the account was secured while the sign-in was being checked', 'userID ' . $userID);
            }
            return '';
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
 * @return array{userID: int, email: string, displayName: string|null, avatarPath: string|null, roleID: int|null, sessionsValidFrom: string|null}|null
 *         The current user, or null if there is no valid authenticated session
 *         (sessionsValidFrom: the account's mark - review finding 1; NULL when
 *         never moved or before database upgrade 0003)
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

    // 🕐 Review finding 1: the session counts only while the account's
    //    "sessions valid from" mark is still the one this sign-in saw when it
    //    checked the password. The comparison is done by the database on the
    //    two stored values (<=> = "equal, NULL counting as equal to NULL"), so
    //    there is no time-zone or same-second question at all. This refuses
    //    every session created BEFORE a reset, and also one created AFTER it
    //    by a sign-in that started before it. The reset's own follow-up
    //    sign-in reads the new mark, so it is never locked out.
    $validFromSql = ', 1 AS `validFromMatches`, NULL AS `sessionsValidFrom` ';
    if (authSessionsValidFromReady()) {
        $validFromSql = ', (`s`.`sessionsValidFromSeen` <=> `u`.`sessionsValidFrom`) AS `validFromMatches`, `u`.`sessionsValidFrom` ';
    }

    try {
        $stmt = dbQuery(
            'SELECT `s`.`userID`, (`s`.`expiresAt` > NOW()) AS `isLive`, `s`.`revokedAt`, `u`.`email`, `u`.`displayName`, `u`.`avatarPath`, `u`.`status`, `u`.`roleID`'
            . $validFromSql
            . 'FROM `tblUserSessions` AS `s` '
            . 'INNER JOIN `tblUsers` AS `u` ON `u`.`userID` = `s`.`userID` '
            . 'WHERE `s`.`sessionID` = ? LIMIT 1',
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

    if ((int) $row['validFromMatches'] !== 1) {
        return null;
    }

    // ⏱️ #158 / #130: judged by the database's clock, in UTC.
    if (!isset($row['isLive']) || (int) $row['isLive'] !== 1) {
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

    // 🕐 The mark itself, for the Google/Microsoft "link" request (review
    //    finding 2): beginAuth() saves it, the callback refuses if it moved.
    $sessionsValidFrom = null;
    if ($row['sessionsValidFrom'] !== null) {
        $sessionsValidFrom = (string) $row['sessionsValidFrom'];
    }

    return [
        'userID'            => (int) $row['userID'],
        'email'             => (string) $row['email'],
        'displayName'       => $row['displayName'],
        'avatarPath'        => $row['avatarPath'],
        'roleID'            => $roleID,
        'sessionsValidFrom' => $sessionsValidFrom,
    ];
}

// ==============================================================================
// 🔐 authLockLiveSessionForUser() - "is this session STILL good?" under lock
// ==============================================================================

/**
 * 🔐 Inside the CALLER's transaction: lock the account row and then this
 *    browser's session row, and say whether the session is still good for
 *    that account RIGHT NOW. Any database error is thrown (the caller rolls
 *    back).
 *
 * Review finding 2 (2026-11-25), the "checked, then acted" gap: a page checks
 * currentUser() at the top and only writes something (for example a new API
 * key) a moment later. A password reset in between ends the session and
 * switches off every API key - and then the late write would create a fresh,
 * working key for whoever held the old session. Calling this just before the
 * write, in the same transaction, closes that gap: the reset locks the same
 * account row first, so the two happen strictly one after the other. Either
 * the write is first (and the reset then switches the new key off), or the
 * reset is first (and this says "no").
 *
 * "Still good" means: the account is active; the session belongs to it, is
 * not ended and not expired; and its "sessions valid from" copy still equals
 * the account's mark (review finding 1).
 *
 * 🔑 Final review I-1 (decision 0016): changeUserPassword() passes the
 *    session it is told to keep as $sessionID. With no $sessionID the session
 *    is this browser's own ($_SESSION), exactly as before.
 *
 * @param int         $userID    The account the caller is about to change
 * @param string|null $sessionID The session to check (null = this browser's own)
 * @return bool True when the write may go ahead
 * @throws \Throwable On a database error
 */
function authLockLiveSessionForUser(int $userID, ?string $sessionID = null): bool
{
    if (!function_exists('dbQuery')) {
        return false;
    }

    if ($sessionID === null) {
        // 🎫 This browser's own session (the original behaviour).
        if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION[CUERCODE_SESSION_TOKEN_KEY])) {
            return false;
        }
        $sessionID = (string) $_SESSION[CUERCODE_SESSION_TOKEN_KEY];
    }

    if ($sessionID === '') {
        return false;
    }

    $markReady = authSessionsValidFromReady();

    // 🔐 1. The account row FIRST (the same order as a password reset).
    $accountSql = 'SELECT `status`, NULL AS `sessionsValidFrom` FROM `tblUsers` WHERE `userID` = ? LIMIT 1 FOR UPDATE';
    if ($markReady) {
        $accountSql = 'SELECT `status`, `sessionsValidFrom` FROM `tblUsers` WHERE `userID` = ? LIMIT 1 FOR UPDATE';
    }
    $accountStmt = dbQuery($accountSql, 'i', [$userID]);
    $account     = $accountStmt->get_result()->fetch_assoc();
    $accountStmt->close();

    if (!is_array($account) || (string) $account['status'] !== 'active') {
        return false;
    }

    // 🎫 2. Then this browser's session row.
    $sessionSql = 'SELECT `userID`, (`expiresAt` > NOW()) AS `isLive`, `revokedAt`, NULL AS `sessionsValidFromSeen` FROM `tblUserSessions` WHERE `sessionID` = ? LIMIT 1 FOR UPDATE';
    if ($markReady) {
        $sessionSql = 'SELECT `userID`, (`expiresAt` > NOW()) AS `isLive`, `revokedAt`, `sessionsValidFromSeen` FROM `tblUserSessions` WHERE `sessionID` = ? LIMIT 1 FOR UPDATE';
    }
    $sessionStmt = dbQuery($sessionSql, 's', [$sessionID]);
    $session     = $sessionStmt->get_result()->fetch_assoc();
    $sessionStmt->close();

    if (!is_array($session) || (int) $session['userID'] !== $userID || $session['revokedAt'] !== null) {
        return false;
    }

    // ⏱️ #158 / #130: judged by the database's clock, in UTC.
    if (!isset($session['isLive']) || (int) $session['isLive'] !== 1) {
        return false;
    }

    // 🕐 Both values come from DATETIME(6) columns, so the same moment is
    //    always written the same way and a plain comparison is exact.
    return ($session['sessionsValidFromSeen'] === $account['sessionsValidFrom']);
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
// 🕐 authMoveSessionsValidFrom() - end every sign-in, even one under way
// ==============================================================================

/**
 * 🕐 Move the account's "sessions valid from" mark forward, inside the
 *    CALLER's transaction, and say what the new mark is. Any database error
 *    is thrown, so the caller rolls everything back.
 *
 * Every session carries a copy of the mark it was made under, and
 * currentUser() refuses a session whose copy is no longer the account's mark.
 * So moving the mark ends every session at once - including one being made by
 * a sign-in that checked the old password a moment ago (review finding 1).
 *
 * The new mark is "now" in UTC to the millionth of a second, or one millionth
 * of a second past the old mark when the clock has not moved on (or went
 * back), so the mark only ever goes forward and two moves in one instant
 * still differ.
 *
 * Used by: the password reset and the command-line admin grant (through
 * accountRemoveOtherWaysIn()), the voluntary password change
 * (changeUserPassword(), decision 0016) and "Sign out everywhere"
 * (accountSignOutEverywhere()). The caller must already hold the account row
 * (SELECT ... FOR UPDATE), so that the two steps here see the same row.
 *
 * Before database upgrade 0003 there is no mark: nothing is changed and the
 * answer is null.
 *
 * @param int $userID The account
 * @return string|null The new mark, or null before upgrade 0003
 * @throws \RuntimeException When the mark could not be moved or read back
 */
function authMoveSessionsValidFrom(int $userID): ?string
{
    if (!authSessionsValidFromReady()) {
        return null;
    }

    $markStmt = dbQuery(
        'UPDATE `tblUsers` SET `sessionsValidFrom` = CASE '
        . 'WHEN `sessionsValidFrom` IS NOT NULL AND `sessionsValidFrom` >= UTC_TIMESTAMP(6) '
        . 'THEN DATE_ADD(`sessionsValidFrom`, INTERVAL 1 MICROSECOND) '
        . 'ELSE UTC_TIMESTAMP(6) END '
        . 'WHERE `userID` = ?',
        'i',
        [$userID]
    );
    $marked = $markStmt->affected_rows;
    $markStmt->close();

    if ($marked !== 1) {
        throw new \RuntimeException('the sign-in mark could not be moved');
    }

    // 👀 Read the new value back (the same transaction sees its own write).
    $readStmt = dbQuery('SELECT `sessionsValidFrom` FROM `tblUsers` WHERE `userID` = ? LIMIT 1', 'i', [$userID]);
    $readRow  = $readStmt->get_result()->fetch_assoc();
    $readStmt->close();

    if (!is_array($readRow) || $readRow['sessionsValidFrom'] === null) {
        throw new \RuntimeException('the sign-in mark could not be read back');
    }

    return (string) $readRow['sessionsValidFrom'];
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
 * 🕐 Review finding 1: FIRST, the account's "sessions valid from" mark
 *    (tblUsers.sessionsValidFrom) is moved forward to now (UTC, to the
 *    millionth of a second). Ending the sessions that exist is not enough on
 *    its own: a sign-in that checked the OLD password a moment earlier could
 *    still write a new session after this transaction. Such a session carries
 *    the old mark, so currentUser() refuses it and issueUserSession() will
 *    not even write it. The new mark is always LATER than the old one (one
 *    millionth of a second later if the clock has not moved on, or has gone
 *    back), so two resets in the same instant still give two different marks.
 *    It is not one of the counts: decision 0010's record stays five counts.
 *    The caller must already hold the account row (SELECT ... FOR UPDATE).
 *
 * @param int  $userID                      The account
 * @param bool $alsoCancelVerificationLinks Also cancel unused email-check links
 * @return array<string, int> How many of each were removed (no secrets)
 * @throws \RuntimeException On any database error
 */
function accountRemoveOtherWaysIn(int $userID, bool $alsoCancelVerificationLinks): array
{
    // 🕐 FIRST: move the "sessions valid from" mark (shared with the
    //    voluntary password change and "Sign out everywhere").
    authMoveSessionsValidFrom($userID);

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

/** @var string The name "Forgot password" writes to the request log (page AND API share it). */
if (!defined('CUERCODE_PASSWORD_RESET_REQUEST_ENDPOINT')) {
    define('CUERCODE_PASSWORD_RESET_REQUEST_ENDPOINT', 'auth/request-password-reset');
}

/** @var int Shortest time (microseconds) a "Forgot password" request takes to answer (review finding 3). */
if (!defined('CUERCODE_PASSWORD_RESET_MIN_RESPONSE_MICROSECONDS')) {
    define('CUERCODE_PASSWORD_RESET_MIN_RESPONSE_MICROSECONDS', 250000);
}

/**
 * ⚙️ The three limits for "Forgot password" (review finding 3): settings
 *    `password_reset_request_min_gap_seconds` (default 60),
 *    `password_reset_request_daily_cap` (default 5) and
 *    `password_reset_request_ip_per_hour` (default 10), read and kept in safe
 *    ranges exactly like the "send me a new link" ones.
 *
 * @param callable|null $getSetting getConfig when null (a stand-in in tests)
 * @return array{minGapSeconds: int, dailyCap: int, ipPerHour: int}
 */
function passwordResetRequestSettings(?callable $getSetting = null): array
{
    return authLinkRequestLimitSettings('password_reset_request', $getSetting);
}

/**
 * 🧠 May a reset link be sent? PURE. The same checks, in the same order, as
 *    decideVerificationResend(): the IP address's hourly allowance, then
 *    whether there is an account that can be reset (active or waiting), then
 *    the minimum gap, then the daily cap.
 *
 * @param array $facts  Keys: ipRequestsInLastHour (int), accountIsEligible (bool),
 *                      secondsSinceLastLink (int|null), linksInLast24Hours (int)
 * @param array $limits The result of passwordResetRequestSettings()
 * @return array{send: bool, reason: string} reason: 'ip_limited',
 *         'no_eligible_account', 'too_soon', 'daily_cap' or 'send'
 */
function decidePasswordResetRequest(array $facts, array $limits): array
{
    $mapped = $facts;
    $mapped['accountIsPending'] = !empty($facts['accountIsEligible']);

    $decision = decideVerificationResend($mapped, $limits);
    if ($decision['reason'] === 'no_pending_account') {
        $decision['reason'] = 'no_eligible_account';
    }

    return $decision;
}

/**
 * 🔑 Request a password reset email for the given address.
 *
 * ANTI-ENUMERATION: ALWAYS returns the identical 200 success shape whether
 * or not the email address is registered - the only way this function's
 * response differs is on a malformed/empty $email, which the caller/handler
 * is expected to have already rejected before calling this (so in normal
 * operation this function's result is a constant).
 *
 * ⏱️ Review finding 3 (2026-11-25): it ALSO takes the same time for every
 *    address (at least CUERCODE_PASSWORD_RESET_MIN_RESPONSE_MICROSECONDS -
 *    measured before: 0.20 ms for an unknown address, 3.91 ms for a waiting
 *    one), and it has the same limits as "send me a new link": a minimum gap
 *    and a daily cap per account, an hourly cap per visitor address (from
 *    getClientIP(), decision 0014), and at most one "refused by the limit"
 *    activity row per account per hour. The page and the API both call this,
 *    so both get the limits and the even timing.
 *
 * @param string $email     Candidate email address
 * @param string $ipAddress Client IP address (from getClientIP())
 *
 * @return array{success: bool, httpCode: int, errorCode: string|null, message: string, data: array}
 *
 * @see resetPasswordWithToken()
 * @see requestNewVerificationLink() - the same protections for the other form
 */
function requestPasswordReset(string $email, string $ipAddress): array
{
    $startedAtNanos = hrtime(true);
    $result         = requestPasswordResetWork($email, $ipAddress);
    padResponseToMinimumDuration($startedAtNanos, CUERCODE_PASSWORD_RESET_MIN_RESPONSE_MICROSECONDS);

    return $result;
}

/**
 * 🔑 The real work of requestPasswordReset(), without the fixed minimum
 *    time. Call requestPasswordReset() instead.
 *
 * @param string $email     Candidate email address
 * @param string $ipAddress Client IP address
 * @return array{success: bool, httpCode: int, errorCode: string|null, message: string, data: array}
 */
function requestPasswordResetWork(string $email, string $ipAddress): array
{
    $genericMessage = 'If this email address is registered, a password reset link has been sent to it.';
    $genericResult  = [
        'success'   => true,
        'httpCode'  => 200,
        'errorCode' => null,
        'message'   => $genericMessage,
        'data'      => [],
    ];

    if (!function_exists('dbQuery') || !function_exists('getDB')) {
        return $genericResult;
    }

    // 🚦 recordAPIRequest() lives in fn_api_auth.php (constants and functions
    //    only - loading it here once is harmless; the API already has it).
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_api_auth.php';

    $normalizedEmail = mb_strtolower(trim($email));
    $limits          = passwordResetRequestSettings();

    // 🌍 Per-IP allowance (final review M-1): record THIS request first, then
    //    count including it, so requests sent at the same moment cannot all
    //    slip under the allowance. Every attempt (known address or not) uses
    //    up the same allowance.
    $ipRequests = authRecordThenCountLinkRequest($ipAddress, CUERCODE_PASSWORD_RESET_REQUEST_ENDPOINT);

    // 🔍 A plain read with no lock: an unknown address never takes a lock.
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

    // 📌 Only an ACTIVE or a waiting (PENDING) account gets an actual reset
    // email queued - this distinction is internal only and never observable
    // in the response. 🔐 Decision 0013: a pending account is included,
    // because the reset link proves the person owns the email address; using
    // it sets a new password AND activates the account. This is the way out
    // for someone whose address a stranger signed up with first.
    $status = '';
    if (is_array($row)) {
        $status = (string) $row['status'];
    }

    if ($status !== 'active' && $status !== 'pending') {
        // 🚫 No account that can be reset: nothing is sent and no lock is
        //    taken. The 250 ms minimum time in requestPasswordReset() hides
        //    the few milliseconds the locked path below takes.
        return $genericResult;
    }

    // 🔒 Final review M-1 (2026-11-25): twenty requests for one account sent at
    //    the same moment used to read the same empty history and all send a
    //    link (measured: 20 links on MySQL 8.0.36 against limits of one a
    //    minute and five a day). Now the history read, the decision and the
    //    new link happen in ONE transaction holding the account row
    //    (SELECT ... FOR UPDATE on its own primary key - the same lock order
    //    as every other change to an account, the account row first; the
    //    reset that later USES the link locks the same row first too). So the
    //    requests queue: the first sends a link and each later one sees it.
    //    The lock is never taken on the email address: a locking read of an
    //    address with no account would lock a GAP in the address index and
    //    could hold up sign-ups and other visitors. Unknown addresses take no
    //    lock at all (above).
    $userID  = (int) $row['userID'];
    $db      = getDB();
    $started = false;

    try {
        $db->begin_transaction();
        $started = true;

        // ⏱️ Codex C3 finding 5: never WAIT for a busy account row (see
        //    authLockAccountRowNoWait()). Busy: the usual answer, nothing more.
        $lockAttempt = authLockAccountRowNoWait($userID, '`status`');
        if ($lockAttempt['busy']) {
            authRollbackQuietly($db);
            return $genericResult;
        }
        $locked = $lockAttempt['row'];

        // 🔁 Still one that can be reset? (It may have been suspended meanwhile.)
        $lockedStatus = '';
        if (is_array($locked)) {
            $lockedStatus = (string) $locked['status'];
        }

        if ($lockedStatus !== 'active' && $lockedStatus !== 'pending') {
            $db->commit();
            return $genericResult;
        }

        $history = authLinkHistory('tblPasswordResets', $userID);

        $decision = decidePasswordResetRequest(
            [
                'ipRequestsInLastHour' => $ipRequests,
                'accountIsEligible'    => true,
                'secondsSinceLastLink' => $history['secondsSince'],
                'linksInLast24Hours'   => $history['linksInDay'],
            ],
            $limits
        );

        if (!$decision['send']) {
            // 📝 A double-click inside the minimum gap is not worth a row, and
            //    once a limit is hit, at most ONE row per account per hour
            //    (decided under the lock, so simultaneous requests cannot each
            //    write one).
            if ($decision['reason'] !== 'too_soon' && function_exists('logActivity') && !limitedRowWrittenRecently((int) $row['userID'], 'password_reset_request_limited')) {
                logActivity(
                    'password_reset_request_limited',
                    'A password reset link was refused by the rate limit (' . $decision['reason'] . ')',
                    'user',
                    $userID,
                    []
                );
            }
        } else {
            $rawToken  = generateRandomToken();
            $tokenHash = hashToken($rawToken);

            $insertStmt = dbQuery(
                'INSERT INTO `tblPasswordResets` (`userID`, `tokenHash`, `expiresAt`, `requestIP`) '
                . 'VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)',
                'isis',
                [$userID, $tokenHash, CUERCODE_PASSWORD_RESET_TTL_MINUTES, mb_substr($ipAddress, 0, 45)]
            );
            $insertStmt->close();

            // 📧 Queued on this connection, so the email row is part of the
            //    same transaction: never a link without its email or the
            //    other way round.
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
        }

        $db->commit();
    } catch (\Throwable $e) {
        if ($started) {
            authRollbackQuietly($db);
        }
        if (function_exists('logError')) {
            logError('error', 'Password reset token issue failed', $e->getMessage());
        }
        return $genericResult;
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
 *      1. locks the account row (review finding 5: the account FIRST, then
 *         the link, the same order as every other change to an account, so
 *         two resets of one account at the same moment queue up instead of
 *         deadlocking - one wins, the other is told its link was used);
 *      2. uses up this reset link;
 *      3. stores the new password and clears the sign-in lock-out; on a
 *         waiting ('pending') account, also activates it and marks the
 *         email address as checked (the reset link proves the person owns
 *         the address) - never for an account holding the Global Admin role
 *         (review fix I1: an emailed link never activates an admin);
 *      4. moves the account's "sessions valid from" mark forward, ends every
 *         signed-in session, switches off every API key, removes linked
 *         Microsoft/Google sign-ins and passkeys, and cancels every other
 *         unused reset and verification link (accountRemoveOtherWaysIn()).
 *    AFTER the commit (review finding 6) it writes an activity row with
 *    COUNTS only (never the password, the link or the address). It is
 *    outside the transaction on purpose: the log must never be able to undo
 *    or block the reset, and a row written inside a transaction that then
 *    failed would describe something that never happened.
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

    // 🔒 Codex C3 finding 4: refused until database upgrade 0003 is applied
    //    (see authUpgradeNeededResult()). Checked before the link is touched,
    //    so the link stays usable for afterwards.
    if (!authSessionsValidFromReady()) {
        return authUpgradeNeededResult('reset');
    }

    $tokenHash = hashToken($rawToken);

    try {
        $stmt = dbQuery(
            'SELECT `resetID`, `userID`, `tokenHash`, (`expiresAt` > NOW()) AS `isLive`, `usedAt` '
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

    // ⏱️ #158 / #130: judged by the database's clock, in UTC.
    if (!isset($row['isLive']) || (int) $row['isLive'] !== 1) {
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

        // 🔐 1. The account FIRST, locked until the end of the transaction
        //    (review finding 5 - see the docblock). The role is asked of
        //    fn_admin.php below, so this file never handles the role column
        //    in a statement that changes anything.
        $accountStmt = dbQuery(
            'SELECT `status` FROM `tblUsers` WHERE `userID` = ? LIMIT 1 FOR UPDATE',
            'i',
            [$userID]
        );
        $account = $accountStmt->get_result()->fetch_assoc();
        $accountStmt->close();

        // 🔒 2. Atomically claim the token BEFORE applying the password change.
        //    A second reset of the same account waited at step 1 for this one
        //    to finish, so here it finds its link already cancelled.
        //    Independent review (Codex C3 finding 3, 2026-11-25): the link's
        //    expiry was only checked BEFORE the slow password hashing and the
        //    wait for the account lock, so a link that ran out meanwhile still
        //    worked (reproduced on real MySQL). The claim now also requires
        //    "not expired", judged by the database clock that set expiresAt.
        $claimStmt = dbQuery(
            'UPDATE `tblPasswordResets` SET `usedAt` = NOW() WHERE `resetID` = ? AND `usedAt` IS NULL AND `expiresAt` > NOW()',
            'i',
            [(int) $row['resetID']]
        );
        $claimed = ($claimStmt->affected_rows === 1);
        $claimStmt->close();

        if (!$claimed) {
            // 🔎 Used meanwhile, or expired meanwhile? Each has its own answer.
            $claimCheckStmt = dbQuery('SELECT `usedAt` FROM `tblPasswordResets` WHERE `resetID` = ? LIMIT 1', 'i', [(int) $row['resetID']]);
            $claimCheckRow  = $claimCheckStmt->get_result()->fetch_assoc();
            $claimCheckStmt->close();
            authRollbackQuietly($db);

            if (is_array($claimCheckRow) && $claimCheckRow['usedAt'] !== null) {
                return $alreadyUsedResult;
            }
            return $invalidResult;
        }

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

        // 🔑 3. New password, lock-out cleared - and a waiting account
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

        // 🚪 4. The sign-in mark and every other way in, in THIS transaction.
        $removed = accountRemoveOtherWaysIn($userID, true);

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

    // 🧾 After the commit (review finding 6): what happened - counts only.
    //    logActivity() never throws; if it cannot write, the reset still stands.
    if (function_exists('logActivity')) {
        logActivity(
            'password_reset_completed',
            'Password reset with an emailed link; every other way into the account was removed',
            'user',
            $userID,
            ['account_activated' => $activated, 'removed' => $removed]
        );
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
