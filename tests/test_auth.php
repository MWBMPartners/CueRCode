<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Characterization Tests for AUTHENTICATION (Epic E3 stage 1)
 * ==============================================================================
 *
 * Path: tests/test_auth.php
 *
 * Covers the DB-INDEPENDENT surface of fn_password.php and fn_auth.php:
 * Argon2id hashing/verification/rehash-detection, password policy, token
 * generation/hashing, the pure lockout-window calculation, session-token
 * shape, and the fail-open/fail-safe behaviour of the session-dependent
 * engine functions (currentUser()/requireAuth()/logoutCurrentUser()) when
 * no PHP session is active and no database connection is available - the
 * exact state of this bare CLI harness, which deliberately never calls
 * session_start() or loads db_connect.php (same convention as test_email.php).
 *
 * Units covered:
 *   - fn_password.php : hashPassword()/verifyPassword() round-trip,
 *                        passwordNeedsRehash() (matching vs. stale cost
 *                        params), validatePasswordPolicy() (length, repeated-
 *                        character, common-password denylist), and
 *                        cuercodeDummyPasswordHash() (a valid, never-matching
 *                        Argon2id hash used for login timing camouflage).
 *   - fn_auth.php     : generateRandomToken() (64 hex chars, uniqueness),
 *                        hashToken() (deterministic SHA-256, 64 hex chars),
 *                        computeLockoutExpiry() (pure lockout-window maths),
 *                        buildAccountActionUrl() (default-URL shape when
 *                        getConfig() is unavailable), and the session-
 *                        dependent functions' fail-safe/fail-open behaviour
 *                        (currentUser() -> null with no active PHP session;
 *                        logoutCurrentUser() -> idempotent success with no
 *                        session or DB; requireAuth() -> throws when
 *                        unauthenticated AND errorResponse() is unavailable).
 *
 * ⚠️ SKIPPED (DB-dependent - out of scope for this harness, same convention as
 *    test_core.php's fn_config.php exclusion and test_email.php's queue
 *    exclusions):
 *   - registerUser()/verifyEmailToken()/loginUser()/requestPasswordReset()/
 *     resetPasswordWithToken()/issueUserSession() all require a live
 *     tblUsers/tblUserSessions/tblEmailVerifications/tblPasswordResets
 *     table via a real MySQLi connection - these are exercised end-to-end
 *     against a throwaway scratch database (see the E3 stage 1 PR evidence),
 *     not in this dependency-free harness.
 *   - fn_api_accounts.php's HTTP handlers (requireCsrfOrApiKey(),
 *     handleAuth*()) need $_SERVER/$_POST superglobals + a live router
 *     context - covered by manual/curl verification against the scratch DB,
 *     not unit-tested here.
 *
 * Section 7 adds STATIC (source-text, no execution) regression guards for
 * the E3 stage 1 auth security review findings that fixed registerUser()'s
 * timing side-channel (F-021), a duplicate-registration-race PII leak into
 * tblErrorLog, session-id-at-rest masking in fn_error_handler.php, and a
 * dead-code cleanup - each guard pins the specific source-code shape the
 * fix requires so a future edit cannot silently reopen any of them. The
 * live scratch-DB timing measurement and race-condition proof for these
 * fixes are NOT in this harness (they need a real MySQL connection and, for
 * the race, real concurrent processes) - see SECURITY.md/CHANGELOG.md for
 * that evidence.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E3 stage 1, #11/#12/#14/#15)
 * @see         tests/bootstrap.php  - requireFn() loader (no DB connection)
 * @see         tests/lib/assert.php - the assert* functions used below
 * @see         web/CueRCode.net/_functions/fn_password.php
 * @see         web/CueRCode.net/_functions/fn_auth.php
 */

// ==============================================================================
// 🧰 Load the DB-independent production function files (NO database connection)
// ==============================================================================
// fn_auth.php calls hashPassword()/verifyPassword()/validatePasswordPolicy()/
// passwordNeedsRehash()/cuercodeDummyPasswordHash() from fn_password.php, so
// it must be loaded first (mirrors the router's apiFunctionFiles load order).
// ==============================================================================

requireFn('fn_password.php');
requireFn('fn_auth.php');

// ==============================================================================
// ✅ Section 1: fn_password.php - hashPassword() / verifyPassword() / passwordNeedsRehash()
// ==============================================================================

$plainPassword = 'correct horse battery staple 🔒';
$hash          = hashPassword($plainPassword);

assertTrue(
    is_string($hash) && $hash !== '',
    'hashPassword() returns a non-empty string'
);
assertTrue(
    str_starts_with($hash, '$argon2id$'),
    'hashPassword() produces an Argon2id hash (PASSWORD_ARGON2ID, not bcrypt/argon2i)'
);
assertContains(
    'm=65536,t=4,p=2',
    $hash,
    'hashPassword() uses the F-013 spec cost parameters (memory_cost=65536, time_cost=4, threads=2)'
);

assertTrue(
    verifyPassword($plainPassword, $hash),
    'verifyPassword() accepts the correct plaintext against its own hash'
);
assertFalse(
    verifyPassword('wrong-password', $hash),
    'verifyPassword() rejects an incorrect plaintext'
);
assertFalse(
    verifyPassword($plainPassword, ''),
    'verifyPassword() rejects an empty stored hash (e.g. a future SSO-only account) without erroring'
);

assertFalse(
    passwordNeedsRehash($hash),
    'passwordNeedsRehash() is false for a hash produced with the CURRENT cost parameters'
);

// 🔁 A hash produced with DIFFERENT (lower) cost parameters must be flagged for rehash.
$staleHash = password_hash($plainPassword, PASSWORD_ARGON2ID, [
    'memory_cost' => 1024,
    'time_cost'   => 1,
    'threads'     => 1,
]);
assertTrue(
    passwordNeedsRehash($staleHash),
    'passwordNeedsRehash() is true for a hash produced with STALE (lower) cost parameters'
);

// 🕐 cuercodeDummyPasswordHash() is a real, valid, never-matching Argon2id hash
// used to give login()'s "no such account" path the same verification cost
// as a genuine wrong-password check (timing-safe non-enumeration).
$dummyHash = cuercodeDummyPasswordHash();
assertTrue(
    str_starts_with($dummyHash, '$argon2id$'),
    'cuercodeDummyPasswordHash() returns a valid Argon2id hash string'
);
assertFalse(
    verifyPassword('any-random-guess', $dummyHash),
    'cuercodeDummyPasswordHash() never matches an arbitrary plaintext'
);
assertFalse(
    passwordNeedsRehash($dummyHash),
    'cuercodeDummyPasswordHash() was generated with the CURRENT cost parameters (so timing truly matches a real check)'
);

// ==============================================================================
// ✅ Section 2: fn_password.php - validatePasswordPolicy()
// ==============================================================================

assertFalse(validatePasswordPolicy('')['valid'], 'validatePasswordPolicy() rejects an empty password');
assertFalse(validatePasswordPolicy('short12')['valid'], 'validatePasswordPolicy() rejects a password below the default 8-char minimum');
assertTrue(validatePasswordPolicy('exactly8')['valid'], 'validatePasswordPolicy() accepts a password at exactly the 8-char minimum');

assertFalse(
    validatePasswordPolicy(str_repeat('a', 300))['valid'],
    'validatePasswordPolicy() rejects a password longer than CUERCODE_PASSWORD_MAX_LENGTH (256)'
);

// 📌 A 256-char string of the SAME repeated character would additionally be
// caught by the repeated-character rule below, so this length-boundary check
// uses an alternating two-character string that passes length AND is not a
// single repeated character - isolating the length check from the
// repeated-character check.
$exactly256NonRepeating = str_repeat('aB', 128); // 256 chars, alternating
assertTrue(
    validatePasswordPolicy($exactly256NonRepeating)['valid'],
    'validatePasswordPolicy() accepts a non-repeating password at exactly the 256-char maximum'
);

assertFalse(
    validatePasswordPolicy('aaaaaaaaaaaa')['valid'],
    'validatePasswordPolicy() rejects a password consisting of a single repeated character'
);
assertFalse(
    validatePasswordPolicy('11111111')['valid'],
    'validatePasswordPolicy() rejects a single-repeated-digit password'
);

assertFalse(
    validatePasswordPolicy('password123')['valid'],
    'validatePasswordPolicy() rejects a password on the common-password denylist'
);
assertFalse(
    validatePasswordPolicy('PASSWORD123')['valid'],
    'validatePasswordPolicy() denylist match is case-insensitive'
);

assertTrue(
    validatePasswordPolicy('Tr0ub4dor&3-xyz-unique')['valid'],
    'validatePasswordPolicy() accepts a reasonably strong, non-denylisted password'
);

// 📏 A caller-supplied minLength (e.g. from getConfig('password_min_length')) is honoured.
assertFalse(
    validatePasswordPolicy('short12', 12)['valid'],
    'validatePasswordPolicy() honours an explicit minLength argument over the 8-char default'
);
assertTrue(
    validatePasswordPolicy('exactly-twelve', 12)['valid'],
    'validatePasswordPolicy() accepts a password meeting an explicit longer minLength'
);
assertTrue(
    validatePasswordPolicy('short12', 0)['valid'] === validatePasswordPolicy('short12', null)['valid'],
    'validatePasswordPolicy() treats a non-positive minLength the same as null (falls back to the 8-char default)'
);

// ==============================================================================
// ✅ Section 3: fn_auth.php - generateRandomToken() / hashToken()
// ==============================================================================

$tokenA = generateRandomToken();
$tokenB = generateRandomToken();

assertEquals(64, strlen($tokenA), 'generateRandomToken() returns a 64-character string (32 random bytes, hex-encoded)');
assertMatches('/^[0-9a-f]{64}$/', $tokenA, 'generateRandomToken() output is lowercase hexadecimal only');
assertTrue($tokenA !== $tokenB, 'generateRandomToken() produces a different token on every call');

$tokenHashA = hashToken($tokenA);
$tokenHashA2 = hashToken($tokenA);
$tokenHashB = hashToken($tokenB);

assertEquals(64, strlen($tokenHashA), 'hashToken() returns a 64-character SHA-256 hex digest');
assertMatches('/^[0-9a-f]{64}$/', $tokenHashA, 'hashToken() output is lowercase hexadecimal only');
assertEquals($tokenHashA, $tokenHashA2, 'hashToken() is deterministic - the same input always hashes the same way');
assertTrue($tokenHashA !== $tokenHashB, 'hashToken() produces different hashes for different tokens');
assertEquals(hash('sha256', $tokenA), $tokenHashA, 'hashToken() matches a plain hash(\'sha256\', ...) call (no secret pepper - by design, tokens are already high-entropy)');

// ==============================================================================
// ✅ Section 4: fn_auth.php - computeLockoutExpiry() (pure lockout-window maths)
// ==============================================================================

$now = 1_700_000_000; // arbitrary fixed Unix timestamp for deterministic maths

assertEquals(
    null,
    computeLockoutExpiry(1, 5, 900, $now),
    'computeLockoutExpiry() returns null (not locked) while failures remain below the threshold'
);
assertEquals(
    null,
    computeLockoutExpiry(4, 5, 900, $now),
    'computeLockoutExpiry() returns null one failure short of the threshold'
);
assertEquals(
    $now + 900,
    computeLockoutExpiry(5, 5, 900, $now),
    'computeLockoutExpiry() locks for exactly lockoutDurationSeconds once the threshold is reached'
);
assertEquals(
    $now + 900,
    computeLockoutExpiry(9, 5, 900, $now),
    'computeLockoutExpiry() continues to lock for attempts beyond the threshold (does not un-lock)'
);
assertEquals(
    null,
    computeLockoutExpiry(100, 0, 900, $now),
    'computeLockoutExpiry() treats a non-positive maxAttempts as "lockout disabled"'
);
assertEquals(
    $now,
    computeLockoutExpiry(5, 5, -50, $now),
    'computeLockoutExpiry() clamps a negative lockoutDurationSeconds to 0 rather than locking into the past'
);

// ==============================================================================
// ✅ Section 5: fn_auth.php - buildAccountActionUrl() (getConfig() unavailable here)
// ==============================================================================
// This bare harness never loads fn_config.php, so getConfig() is undefined and
// buildAccountActionUrl() falls back to its hard-coded default base URL.
// ==============================================================================

assertFalse(function_exists('getConfig'), 'sanity check: getConfig() is NOT loaded in this harness (buildAccountActionUrl() must fall back safely)');

$verifyUrl = buildAccountActionUrl('verify-email', 'abc123token');
assertEquals(
    'https://cuercode.net/verify-email?token=abc123token',
    $verifyUrl,
    'buildAccountActionUrl() falls back to the default site URL when getConfig() is unavailable'
);

$resetUrl = buildAccountActionUrl('reset-password', 'token with spaces & symbols!');
assertContains(
    'token=token%20with%20spaces%20%26%20symbols%21',
    $resetUrl,
    'buildAccountActionUrl() rawurlencode()s the token so it survives as a single query parameter'
);

// ==============================================================================
// ✅ Section 6: fn_auth.php - session-dependent functions, fail-safe/fail-open paths
// ==============================================================================
// No session_start() has been called anywhere in this harness (session_status()
// is PHP_SESSION_NONE) and dbQuery()/getConfig() are undefined
// (fn_config.php/db_connect.php are never loaded here) - this is the exact
// combination currentUser()/logoutCurrentUser() must degrade gracefully
// against, never fatally.
//
// requireAuth() is deliberately tested in a SEPARATE SUBPROCESS below (via
// authTestRunPhpSnippet()), NOT in-process here: several test files that run
// earlier in the shared test-runner process (e.g. test_accessibility.php)
// requireFn('fn_response.php'), which makes the REAL errorResponse() ->
// jsonResponse() available process-wide for the rest of the run - and
// jsonResponse() calls exit() on success, which would silently terminate the
// entire test runner if invoked in-process here. A fresh child process
// loading ONLY fn_password.php + fn_auth.php (never fn_response.php)
// guarantees errorResponse() is genuinely undefined, exercising the
// documented RuntimeException fallback safely.
// ==============================================================================

assertEquals(PHP_SESSION_NONE, session_status(), 'sanity check: no PHP session is active in this harness');
assertFalse(function_exists('dbQuery'), 'sanity check: dbQuery() is NOT loaded in this harness');

assertEquals(
    null,
    currentUser(),
    'currentUser() returns null when there is no active PHP session (nothing to look up)'
);

$logoutResult = logoutCurrentUser();
assertTrue($logoutResult['success'], 'logoutCurrentUser() succeeds (idempotently) even with no active session or database');
assertEquals(200, $logoutResult['httpCode'], 'logoutCurrentUser() reports HTTP 200 for the no-op case');

// ==============================================================================
// 🛰️ authTestRunPhpSnippet() - Execute a PHP snippet in a clean SUBPROCESS
// ==============================================================================
// A locally-named (non-colliding) equivalent of test_core.php's
// runPhpSnippet() - test_auth.php sorts BEFORE test_core.php, so that
// function is not yet defined when this file runs, and declaring a
// same-named function here would fatal with "Cannot redeclare" once
// test_core.php runs later in the shared process.
// @see test_core.php - runPhpSnippet() (same technique, different name)
// ==============================================================================

/**
 * 🛰️ Run a self-contained PHP snippet in a fresh child process, loading ONLY
 * fn_password.php + fn_auth.php (never fn_response.php/fn_config.php/db_connect.php),
 * and return its STDOUT.
 *
 * @param string $snippet PHP code (without a leading "<?php" tag) to run after
 *                        the bootstrap + fn_password.php/fn_auth.php are loaded.
 *
 * @return string The child process's STDOUT (stderr is discarded).
 *
 * @see https://www.php.net/manual/en/function.proc-open.php
 */
function authTestRunPhpSnippet(string $snippet): string
{
    $bootstrapPath = CUERCODE_TEST_REPO_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'bootstrap.php';

    $program = '<?php' . PHP_EOL
        . 'error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);' . PHP_EOL
        . 'require ' . var_export($bootstrapPath, true) . ';' . PHP_EOL
        . "requireFn('fn_password.php');" . PHP_EOL
        . "requireFn('fn_auth.php');" . PHP_EOL
        . $snippet . PHP_EOL;

    $tmpFile = tempnam(sys_get_temp_dir(), 'cuercode_auth_test_') . '.php';
    file_put_contents($tmpFile, $program);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $stdout  = '';
    $command = [PHP_BINARY, $tmpFile];
    $process = proc_open($command, $descriptors, $pipes);

    if (is_resource($process)) {
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);
    }

    @unlink($tmpFile);

    if ($stdout === false) {
        return '';
    }

    return $stdout;
}

// 🛡️ requireAuth() must terminate the request when unauthenticated. In a
// fresh subprocess where errorResponse() is genuinely undefined, the
// documented fallback is a thrown RuntimeException (never a silent
// pass-through with a null user).
$requireAuthSnippet = <<<'PHPCODE'
var_dump(function_exists('errorResponse'));
try {
    requireAuth();
    echo 'NO_EXCEPTION_THROWN';
} catch (\RuntimeException $e) {
    echo 'RUNTIME_EXCEPTION_THROWN: ' . $e->getMessage();
}
PHPCODE;

$requireAuthOutput = authTestRunPhpSnippet($requireAuthSnippet);

assertContains(
    'bool(false)',
    $requireAuthOutput,
    'subprocess sanity check: errorResponse() is genuinely undefined when only fn_password.php/fn_auth.php are loaded'
);
assertContains(
    'RUNTIME_EXCEPTION_THROWN',
    $requireAuthOutput,
    'requireAuth() throws a RuntimeException when unauthenticated and errorResponse() is unavailable (never returns null silently)'
);

// ==============================================================================
// ✅ Section 7: Static regression guards - E3 stage 1 auth security review
//    (register() timing side-channel / duplicate-race PII / session-id
//    masking / dead-code cleanup)
// ==============================================================================
// registerUser()/loginUser()/etc. are DB-dependent (see the file-level
// docblock above) and are exercised end-to-end against a scratch database
// as part of the security-review evidence, NOT in this DB-independent
// harness. What CAN be verified here, cheaply and on every run, is that the
// SOURCE CODE still has the specific shape the fix requires - a static
// regression guard against someone innocently reordering registerUser()
// back to its pre-fix shape (or reintroducing the removed dead $_POST
// fallback) without realising the security implication.
//
// This reads the production files as plain text (no execution), so it adds
// zero DB dependency and zero runtime risk to the harness.
// ==============================================================================

$fnAuthSource = file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_auth.php');
assertTrue(is_string($fnAuthSource) && $fnAuthSource !== '', 'sanity check: fn_auth.php source was readable for the static regression guards below');

// 📌 Isolate registerUser()'s body (from its own `function` keyword up to the
// next function's `function` keyword) so the position checks below are
// scoped to THIS function only, not accidentally matching text elsewhere
// in the file.
$registerUserStart = strpos($fnAuthSource, 'function registerUser(');
$registerUserEnd    = strpos($fnAuthSource, 'function verifyEmailToken(');
assertTrue(
    $registerUserStart !== false && $registerUserEnd !== false && $registerUserStart < $registerUserEnd,
    'sanity check: registerUser() and verifyEmailToken() are both found in fn_auth.php, in the expected order'
);
$registerUserBody = substr($fnAuthSource, $registerUserStart, $registerUserEnd - $registerUserStart);

// --------------------------------------------------------------------------
// F-021 - register() timing side-channel regression guard
// --------------------------------------------------------------------------
// The fix's entire premise is ORDERING: hashPassword() must run BEFORE the
// tblUsers existence-check SELECT, so both the "already registered" and
// "brand new email" branches pay the identical Argon2id cost before either
// one can return. Assert that ordering directly against the source.
// --------------------------------------------------------------------------
$hashCallPos   = strpos($registerUserBody, 'hashPassword($plainPassword)');
$existingSqlPos = strpos($registerUserBody, 'SELECT `userID` FROM `tblUsers` WHERE `email` = ?');

assertTrue(
    $hashCallPos !== false,
    'F-021 regression guard: registerUser() still calls hashPassword($plainPassword) somewhere'
);
assertTrue(
    $existingSqlPos !== false,
    'F-021 regression guard: registerUser() still runs the tblUsers existence-check SELECT somewhere'
);
assertTrue(
    $hashCallPos !== false && $existingSqlPos !== false && $hashCallPos < $existingSqlPos,
    'F-021 regression guard: hashPassword() is called BEFORE the existence-check SELECT (both response '
    . 'branches now pay the identical Argon2id cost - see the live scratch-DB timing measurement in '
    . 'SECURITY.md/CHANGELOG.md for the ~81-89ms-gap-closed-to-~0-3ms proof)'
);

// --------------------------------------------------------------------------
// Finding 2 - duplicate-registration-race PII regression guard
// --------------------------------------------------------------------------
// The pre-fix code logged $e->getMessage() UNCONDITIONALLY in the INSERT
// catch block, which for a duplicate-key race embeds MySQL's own
// "Duplicate entry '<email>' for key ..." wording (verbatim plaintext
// email). Assert that exact vulnerable call is gone AND that a
// duplicate-key classification + generic log title now exists instead.
// --------------------------------------------------------------------------
assertFalse(
    str_contains(
        $registerUserBody,
        "logError('warning', 'Registration insert failed (possible duplicate race)', \$e->getMessage())"
    ),
    'Finding 2 regression guard: the pre-fix unconditional-raw-message logError() call for the duplicate-insert '
    . 'catch block (which could embed the plaintext email via MySQL\'s "Duplicate entry" wording) is gone'
);
assertContains(
    'mysqli_sql_exception',
    $registerUserBody,
    'Finding 2 regression guard: registerUser() classifies the duplicate-key case via \\mysqli_sql_exception (errno 1062) detection'
);
assertContains(
    "'Registration insert conflict (duplicate email)'",
    $registerUserBody,
    'Finding 2 regression guard: the duplicate-key branch logs the new GENERIC title (no PII) instead of the raw driver message'
);

// --------------------------------------------------------------------------
// Finding 3 - session-id-at-rest masking regression guard (fn_error_handler.php)
// --------------------------------------------------------------------------
// The pre-fix code stored `$sessionID = session_id();` verbatim into
// tblErrorLog. Assert that exact vulnerable assignment is gone and that the
// masked (SHA-256-prefix) form is computed instead.
// --------------------------------------------------------------------------
$fnErrorHandlerSource = file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_error_handler.php');
assertTrue(is_string($fnErrorHandlerSource) && $fnErrorHandlerSource !== '', 'sanity check: fn_error_handler.php source was readable for the Finding 3 regression guard');

assertFalse(
    str_contains($fnErrorHandlerSource, '$sessionID = session_id();'),
    'Finding 3 regression guard: logError() no longer assigns the raw session_id() return value directly to $sessionID (the value that gets persisted to tblErrorLog)'
);
assertContains(
    "substr(hash('sha256', \$rawSessionID), 0, 16)",
    $fnErrorHandlerSource,
    'Finding 3 regression guard: logError() masks the session id to a truncated SHA-256 hash before it is persisted to tblErrorLog'
);

// --------------------------------------------------------------------------
// Cleanup - dead $_POST['csrf_token'] fallback regression guard
// --------------------------------------------------------------------------
// Both requireCsrfOrApiKey() (fn_api_accounts.php) and authenticateAPIRequest()
// (fn_api_auth.php) previously fell back to $_POST['csrf_token'], which is
// unreachable in production (the router requires Content-Type:
// application/json and rejects everything else with 415 before either
// function runs, and PHP never populates $_POST for a JSON body). Assert
// the dead branch was actually removed from both files, not merely
// commented out.
// --------------------------------------------------------------------------
$fnApiAccountsSource = file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_api_accounts.php');
$fnApiAuthSource     = file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_api_auth.php');

// 📌 Check for the actual EXECUTABLE read (isset($_POST['csrf_token'])),
// not a bare mention - the surviving comments in both files intentionally
// still name $_POST['csrf_token'] in prose (documenting why it was removed),
// so a plain substring match on the bare superglobal expression would
// false-positive against those comments.
assertFalse(
    str_contains($fnApiAccountsSource, "isset(\$_POST['csrf_token'])"),
    'Dead-code cleanup regression guard: fn_api_accounts.php requireCsrfOrApiKey() no longer reads $_POST[\'csrf_token\'] (unreachable - router mandates JSON Content-Type, 415s form POSTs first)'
);
assertFalse(
    str_contains($fnApiAuthSource, "isset(\$_POST['csrf_token'])"),
    'Dead-code cleanup regression guard: fn_api_auth.php authenticateAPIRequest() no longer reads $_POST[\'csrf_token\'] (same reason)'
);
