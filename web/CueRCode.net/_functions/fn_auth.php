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

// ==============================================================================
// 🔧 Constants
// ==============================================================================

/**
 * @var int Email verification token time-to-live, in hours. Matches the
 * verification.php email template's own default expiryHours (24).
 */
if (!defined('CUERCODE_EMAIL_VERIFICATION_TTL_HOURS')) {
    define('CUERCODE_EMAIL_VERIFICATION_TTL_HOURS', 24);
}

/**
 * @var int Password reset token time-to-live, in minutes. Matches the
 * password_reset.php email template's own default expiryMinutes (60).
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
function registerUser(string $email, string $plainPassword, ?string $displayName = null): array
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

    // 🎫 Issue a single-use, expiring email verification token.
    $rawToken  = generateRandomToken();
    $tokenHash = hashToken($rawToken);

    try {
        $verifyStmt = dbQuery(
            'INSERT INTO `tblEmailVerifications` (`userID`, `tokenHash`, `expiresAt`) '
            . 'VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))',
            'isi',
            [$newUserID, $tokenHash, CUERCODE_EMAIL_VERIFICATION_TTL_HOURS]
        );
        $verifyStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Verification token insert failed', $e->getMessage());
        }
        // The account row exists but has no way to self-verify yet - logged
        // for operator follow-up; the RESPONSE stays generic either way.
        return $genericSuccess;
    }

    // 📧 Queue the verification email (fails open/silently if fn_email.php
    // is not loaded or the email subsystem is not configured - see fn_email.php).
    if (function_exists('queueEmail')) {
        $verificationUrl = buildAccountActionUrl('verify-email', $rawToken);

        queueEmail(
            $normalizedEmail,
            'verification',
            [
                'recipientName'   => $normalizedDisplayName,
                'verificationUrl' => $verificationUrl,
                'expiryHours'     => CUERCODE_EMAIL_VERIFICATION_TTL_HOURS,
            ],
            null,
            $normalizedDisplayName
        );
    }

    return $genericSuccess;
}

// ==============================================================================
// ✅ verifyEmailToken() - Consume a single-use email verification token
// ==============================================================================

/**
 * ✅ Verify a raw email-verification token and activate the owning account.
 *
 * @param string $rawToken The raw token from the emailed verification link
 *
 * @return array{success: bool, httpCode: int, errorCode: string|null, message: string, data: array}
 *
 * @see registerUser()
 */
function verifyEmailToken(string $rawToken): array
{
    $invalidMessage = 'This verification link is invalid or has expired.';
    $invalidResult  = [
        'success'   => false,
        'httpCode'  => 400,
        'errorCode' => 'VERIFICATION_TOKEN_INVALID',
        'message'   => $invalidMessage,
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

    // 🔒 Atomically claim the token (usedAt IS NULL guard) BEFORE activating
    // the account, closing the race window between two simultaneous requests
    // for the same token.
    try {
        $claimStmt = dbQuery(
            'UPDATE `tblEmailVerifications` SET `usedAt` = NOW() '
            . 'WHERE `verificationID` = ? AND `usedAt` IS NULL',
            'i',
            [(int) $row['verificationID']]
        );
        $claimed = ($claimStmt->affected_rows === 1);
        $claimStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Verification token claim failed', $e->getMessage());
        }
        return $invalidResult;
    }

    if (!$claimed) {
        // 📌 Lost a race to another concurrent verification attempt for the
        // same token.
        return $alreadyUsedResult;
    }

    try {
        $activateStmt = dbQuery(
            "UPDATE `tblUsers` SET `emailVerifiedAt` = NOW(), `status` = 'active' "
            . "WHERE `userID` = ? AND `status` = 'pending'",
            'i',
            [$userID]
        );
        $activateStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Account activation failed after verification', $e->getMessage());
        }
        // The token was consumed but activation failed - surfaced to the
        // caller as a generic failure rather than a false success.
        return [
            'success'   => false,
            'httpCode'  => 500,
            'errorCode' => 'ACTIVATION_FAILED',
            'message'   => 'Your email was verified but activation could not complete. Please contact support.',
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
function loginUser(string $email, string $plainPassword, string $ipAddress, string $userAgent): array
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
 * @return array{userID: int, email: string, displayName: string|null, avatarPath: string|null}|null
 *         The current user, or null if there is no valid authenticated session
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
            'SELECT s.`userID`, s.`expiresAt`, s.`revokedAt`, u.`email`, u.`displayName`, u.`avatarPath`, u.`status` '
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

    return [
        'userID'      => (int) $row['userID'],
        'email'       => (string) $row['email'],
        'displayName' => $row['displayName'],
        'avatarPath'  => $row['avatarPath'],
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
            . '?s=' . max(16, min(2048, $size))
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

    // 📌 Only an ACTIVE account gets an actual reset email queued - this
    // distinction is internal only and never observable in the response.
    if ((string) $row['status'] === 'active') {
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
 * @param string $rawToken    The raw token from the emailed reset link
 * @param string $newPassword The candidate new plaintext password
 *
 * @return array{success: bool, httpCode: int, errorCode: string|null, message: string, data: array}
 *
 * @see requestPasswordReset()
 * @see fn_password.php - validatePasswordPolicy(), hashPassword()
 */
function resetPasswordWithToken(string $rawToken, string $newPassword): array
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

    // 🔒 Atomically claim the token BEFORE applying the password change.
    try {
        $claimStmt = dbQuery(
            'UPDATE `tblPasswordResets` SET `usedAt` = NOW() WHERE `resetID` = ? AND `usedAt` IS NULL',
            'i',
            [(int) $row['resetID']]
        );
        $claimed = ($claimStmt->affected_rows === 1);
        $claimStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Password reset token claim failed', $e->getMessage());
        }
        return $invalidResult;
    }

    if (!$claimed) {
        return $alreadyUsedResult;
    }

    $userID = (int) $row['userID'];

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

    try {
        $updateStmt = dbQuery(
            'UPDATE `tblUsers` SET `passwordHash` = ?, `failedLoginCount` = 0, `lockedUntil` = NULL '
            . 'WHERE `userID` = ?',
            'si',
            [$newHash, $userID]
        );
        $updateStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Password update failed during reset', $e->getMessage());
        }
        return [
            'success'   => false,
            'httpCode'  => 500,
            'errorCode' => 'RESET_FAILED',
            'message'   => 'Password reset could not be completed. Please try again later.',
            'data'      => [],
        ];
    }

    // 🔐 Revoke ALL existing sessions for this account - a compromised
    // session must not survive a reset meant to lock an attacker out.
    try {
        $revokeStmt = dbQuery(
            'UPDATE `tblUserSessions` SET `revokedAt` = NOW() WHERE `userID` = ? AND `revokedAt` IS NULL',
            'i',
            [$userID]
        );
        $revokeStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('warning', 'Session revoke-on-reset failed', $e->getMessage());
        }
    }

    return [
        'success'   => true,
        'httpCode'  => 200,
        'errorCode' => null,
        'message'   => 'Your password has been reset. You can now log in with your new password.',
        'data'      => [],
    ];
}
