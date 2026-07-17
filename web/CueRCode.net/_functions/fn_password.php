<?php
/**
 * ==============================================================================
 * 🔑 CueRCode - Password Hashing & Policy (Epic E3 stage 1, F-013/B-011)
 * ==============================================================================
 *
 * Path: _functions/fn_password.php
 *
 * Provides the ONLY sanctioned password hashing surface for CueRCode user
 * accounts (tblUsers.passwordHash). Every password touch point in the
 * application (registration, login, password reset) MUST go through
 * hashPassword()/verifyPassword() here - never call password_hash()/
 * password_verify() directly elsewhere, so the algorithm and cost parameters
 * stay centrally controlled.
 *
 * Algorithm: Argon2id (PASSWORD_ARGON2ID), memory_cost 65536 KiB (64 MiB),
 * time_cost 4, threads 2 - per ProjectBrief section 7.1 / SECURITY.md F-013.
 * Argon2id is the OWASP-recommended default for password storage: it resists
 * both GPU-cracking (memory-hard) and side-channel/timing attacks (the
 * "id" variant blends Argon2i's side-channel resistance with Argon2d's
 * GPU resistance).
 *
 * @package     CueRCode
 * @subpackage  Security
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E3 stage 1)
 * @see         https://www.php.net/manual/en/function.password-hash.php
 * @see         https://www.php.net/manual/en/function.password-verify.php
 * @see         https://www.php.net/manual/en/function.password-needs-rehash.php
 * @see         https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html
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
 * @var array<string,int> Argon2id cost parameters used for EVERY password hash.
 *
 * memory_cost is in KiB (65536 KiB = 64 MiB). These values match the F-013
 * spec exactly (SECURITY.md, PROJECT_ROADMAP.md Epic E3). Changing these
 * later is safe and non-breaking: password_needs_rehash() (via
 * passwordNeedsRehash() below) detects any existing hash that was produced
 * with different parameters and flags it for a transparent rehash on next
 * successful login.
 *
 * @see https://www.php.net/manual/en/password.constants.php#constant.password-argon2-default-memory-cost
 */
if (!defined('CUERCODE_PASSWORD_ARGON2ID_OPTIONS')) {
    define('CUERCODE_PASSWORD_ARGON2ID_OPTIONS', [
        'memory_cost' => 65536, // 🧠 64 MiB
        'time_cost'   => 4,     // ⏱️ 4 iterations
        'threads'     => 2,     // 🧵 2 parallel lanes
    ]);
}

/**
 * @var int Default minimum password length, used when the 'password_min_length'
 * tblSettings value is unavailable (e.g. no DB connection). The seeded default
 * for that setting is also 8 (seed_data.sql, group 'security').
 */
if (!defined('CUERCODE_PASSWORD_MIN_LENGTH_DEFAULT')) {
    define('CUERCODE_PASSWORD_MIN_LENGTH_DEFAULT', 8);
}

/**
 * @var int Upper bound on accepted password length. Argon2id itself has no
 * meaningful length ceiling the way some legacy algorithms do (e.g. bcrypt's
 * 72-byte truncation), but an unbounded input still lets a caller submit a
 * pathologically large string that costs CPU/memory to hash for no benefit -
 * this cap is generous for any real passphrase while remaining defensive.
 */
if (!defined('CUERCODE_PASSWORD_MAX_LENGTH')) {
    define('CUERCODE_PASSWORD_MAX_LENGTH', 256);
}

/**
 * @var array<int,string> A small, deliberately short denylist of extremely
 * common/breached passwords (all lowercase for case-insensitive matching).
 * This is NOT a HaveIBeenPwned-style external lookup - CueRCode's shared-
 * hosting stack makes no outbound network call on a password-entry code
 * path for both latency and availability reasons (ProjectBrief - no
 * third-party dependency on the auth hot path). This denylist instead
 * catches the most obviously guessable values as a "breach-sane" baseline
 * per the E3 stage 1 spec, on top of the length/repetition checks below.
 */
if (!defined('CUERCODE_COMMON_PASSWORD_DENYLIST')) {
    define('CUERCODE_COMMON_PASSWORD_DENYLIST', [
        'password', 'password1', 'password123', '12345678', '123456789',
        '1234567890', 'qwertyui', 'qwerty123', 'letmein11', 'admin1234',
        'welcome1', 'welcome123', 'iloveyou1', 'football1', 'monkey123',
        'dragon123', 'sunshine1', 'princess1', 'trustno1', 'abc123456',
        'passw0rd', 'p@ssw0rd', 'p@ssword', '11111111', '00000000',
        'changeme', 'letmein123', 'superman1', 'baseball1', 'starwars1',
    ]);
}

// ==============================================================================
// 🔒 hashPassword() - Argon2id password hash
// ==============================================================================

/**
 * 🔒 Hash a plaintext password using PASSWORD_ARGON2ID
 *
 * @param string $plainPassword The plaintext password to hash
 *
 * @return string The Argon2id hash string (safe to store in tblUsers.passwordHash)
 *
 * @throws \RuntimeException If password_hash() fails (e.g. the Argon2i/Argon2id
 *                            PHP extension is unavailable on this build)
 *
 * @see https://www.php.net/manual/en/function.password-hash.php
 *
 * @example
 *   $hash = hashPassword('correct horse battery staple');
 *   // Store $hash in tblUsers.passwordHash
 */
function hashPassword(string $plainPassword): string
{
    // @see https://www.php.net/manual/en/function.password-hash.php
    $hash = password_hash($plainPassword, PASSWORD_ARGON2ID, CUERCODE_PASSWORD_ARGON2ID_OPTIONS);

    // 📌 password_hash() returns false (or, per its signature, could return
    // null in edge cases) on failure - most commonly because the running PHP
    // build lacks the Argon2 support (libargon2 missing at compile time).
    if ($hash === false || $hash === null || $hash === '') {
        throw new \RuntimeException(
            'Password hashing failed - PASSWORD_ARGON2ID may be unavailable on this PHP build '
            . '(requires PHP compiled with libargon2 support).'
        );
    }

    return $hash;
}

// ==============================================================================
// ✅ verifyPassword() - Timing-safe hash verification
// ==============================================================================

/**
 * ✅ Verify a plaintext password against a stored Argon2id hash
 *
 * Uses password_verify(), which is inherently timing-safe (it performs the
 * comparison in constant time relative to the hash, regardless of where a
 * mismatch occurs) - no separate hash_equals() wrapping is needed or
 * meaningful here, unlike a raw string/HMAC comparison.
 *
 * @param string $plainPassword The plaintext password attempt
 * @param string $hash          The stored hash to verify against (empty string is treated as "never matches")
 *
 * @return bool True if the password matches the hash, false otherwise
 *
 * @see https://www.php.net/manual/en/function.password-verify.php
 *
 * @example
 *   if (verifyPassword($_POST['password'], $userRow['passwordHash'])) {
 *       // Password is correct
 *   }
 */
function verifyPassword(string $plainPassword, string $hash): bool
{
    // 📌 An empty stored hash (e.g. an SSO-only account with no local
    // password set, Epic E2) can never be matched by any plaintext attempt.
    if ($hash === '') {
        return false;
    }

    // @see https://www.php.net/manual/en/function.password-verify.php
    return password_verify($plainPassword, $hash);
}

// ==============================================================================
// 🔁 passwordNeedsRehash() - Detect stale cost parameters
// ==============================================================================

/**
 * 🔁 Determine whether a stored hash should be regenerated with current cost parameters
 *
 * Call this immediately AFTER a successful verifyPassword() (never before -
 * rehashing requires knowing the plaintext, which is only available right
 * after a correct login). If this returns true, call hashPassword() again on
 * the SAME plaintext and persist the new hash, so accounts transparently
 * migrate to updated Argon2id parameters over time without a forced
 * password reset.
 *
 * @param string $hash The stored hash to check
 *
 * @return bool True if the hash was produced with different algorithm/cost
 *              parameters than CUERCODE_PASSWORD_ARGON2ID_OPTIONS specifies now
 *
 * @see https://www.php.net/manual/en/function.password-needs-rehash.php
 *
 * @example
 *   if (verifyPassword($plainPassword, $row['passwordHash'])) {
 *       if (passwordNeedsRehash($row['passwordHash'])) {
 *           updateStoredHash($row['userID'], hashPassword($plainPassword));
 *       }
 *       // ... proceed with login
 *   }
 */
function passwordNeedsRehash(string $hash): bool
{
    // @see https://www.php.net/manual/en/function.password-needs-rehash.php
    return password_needs_rehash($hash, PASSWORD_ARGON2ID, CUERCODE_PASSWORD_ARGON2ID_OPTIONS);
}

// ==============================================================================
// 📏 validatePasswordPolicy() - Min-length + breach-sane policy check
// ==============================================================================

/**
 * 📏 Validate a candidate plaintext password against the account password policy
 *
 * Checks (in order, first failure wins):
 *   1. Not empty
 *   2. At least $minLength characters (mb_strlen - full Unicode-aware count)
 *   3. No more than CUERCODE_PASSWORD_MAX_LENGTH characters
 *   4. Not a single character repeated throughout (e.g. "aaaaaaaa")
 *   5. Not present in the small common/breached-password denylist (case-insensitive)
 *
 * This is a policy/format check ONLY - it never touches the database or the
 * network, so it is fully unit-testable without either.
 *
 * @param string   $plainPassword The candidate password to validate
 * @param int|null $minLength     Minimum required length (defaults to
 *                                  CUERCODE_PASSWORD_MIN_LENGTH_DEFAULT when null
 *                                  or less than 1 - callers typically pass
 *                                  getConfig('password_min_length', ...) here)
 *
 * @return array{valid: bool, error: string|null} Validation result
 *
 * @see https://www.php.net/manual/en/function.mb-strlen.php
 * @see https://www.php.net/manual/en/function.preg-match.php
 *
 * @example
 *   $policy = validatePasswordPolicy($_POST['password'], getConfig('password_min_length', 8));
 *   if (!$policy['valid']) {
 *       errorResponse($policy['error'], 422, 'WEAK_PASSWORD');
 *   }
 */
function validatePasswordPolicy(string $plainPassword, ?int $minLength = null): array
{
    if ($minLength === null || $minLength < 1) {
        $minLength = CUERCODE_PASSWORD_MIN_LENGTH_DEFAULT;
    }

    $result = [
        'valid' => false,
        'error' => null,
    ];

    // 📌 mb_strlen(), not strlen(), so multi-byte UTF-8 passphrases are
    // counted by character, not by raw byte.
    // @see https://www.php.net/manual/en/function.mb-strlen.php
    $length = mb_strlen($plainPassword);

    if ($length === 0) {
        $result['error'] = 'Password is required.';
        return $result;
    }

    if ($length < $minLength) {
        $result['error'] = 'Password must be at least ' . $minLength . ' characters long.';
        return $result;
    }

    if ($length > CUERCODE_PASSWORD_MAX_LENGTH) {
        $result['error'] = 'Password must be no more than ' . CUERCODE_PASSWORD_MAX_LENGTH . ' characters long.';
        return $result;
    }

    // 📌 Reject a password consisting of a single repeated character
    // (e.g. "aaaaaaaa", "11111111") - trivially guessable regardless of length.
    // @see https://www.php.net/manual/en/function.preg-match.php
    if (preg_match('/^(.)\1*$/u', $plainPassword) === 1) {
        $result['error'] = 'Password must not consist of a single repeated character.';
        return $result;
    }

    // 📌 Breach-sane denylist check (case-insensitive exact match).
    // @see https://www.php.net/manual/en/function.mb-strtolower.php
    $lowerPassword = mb_strtolower($plainPassword);

    if (in_array($lowerPassword, CUERCODE_COMMON_PASSWORD_DENYLIST, true)) {
        $result['error'] = 'This password is too common and easily guessed. Please choose a different one.';
        return $result;
    }

    $result['valid'] = true;
    return $result;
}

// ==============================================================================
// 🕐 cuercodeDummyPasswordHash() - Fixed hash for timing-safe non-enumeration
// ==============================================================================

/**
 * 🕐 Return a fixed, precomputed Argon2id hash used ONLY for timing camouflage
 *
 * Used by loginUser() (fn_auth.php) when no matching tblUsers row exists for
 * the submitted email: password_verify() is still called against THIS dummy
 * hash so the response time for "no such account" is statistically
 * indistinguishable from "account exists, wrong password" - both pay the
 * same Argon2id verification cost. The literal value below was generated
 * once with the SAME cost parameters this file uses today
 * (CUERCODE_PASSWORD_ARGON2ID_OPTIONS) and hashes an arbitrary fixed string
 * that is never treated as a real credential.
 *
 * @return string A valid Argon2id hash string (never matches any real password)
 */
function cuercodeDummyPasswordHash(): string
{
    // 🔒 Precomputed once (not hashed fresh on every call - that would be a
    // needless, more expensive Argon2id HASH operation instead of the cheaper
    // VERIFY this function exists to support) via:
    //   password_hash('cuercode-timing-safety-dummy-password', PASSWORD_ARGON2ID,
    //       ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2]);
    return '$argon2id$v=19$m=65536,t=4,p=2$ZzZZMXREbVd3aWhjT0pXQw$3zQJQaXAUIXQDPdNwbk5aKPQfL8kUHMg/TGovtcjQ/g';
}
