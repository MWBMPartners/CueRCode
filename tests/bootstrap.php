<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Test Harness Bootstrap (Characterization Safety Net)
 * ==============================================================================
 *
 * Path: tests/bootstrap.php
 *
 * Initialises the test environment WITHOUT touching the production bootstrap
 * (web/CueRCode.net/_includes/bootstrap.php) and WITHOUT ever opening a MySQLi
 * database connection. The production bootstrap requires db_connect.php which
 * calls getDB()/mysqli_connect() on demand; we deliberately avoid that whole
 * chain so unit tests run on any machine with just the PHP CLI.
 *
 * What this file does:
 *   1. Defines TEST-ONLY encryption constants (ENCRYPTION_KEY / ENCRYPTION_SALT)
 *      so encryption round-trip tests are deterministic and self-contained.
 *   2. Defines the path constants the production fn_ files / their callers may
 *      expect (mirrors the CUERCODE_* path constants from the real bootstrap).
 *   3. Defines the encryption cipher constants the fn_encryption.php functions
 *      reference (it also self-defines these, but we pre-define for clarity).
 *   4. Sets error_reporting(E_ALL) so any deprecation/notice surfaces in tests.
 *   5. Provides requireFn() - a helper that safely includes ONE production
 *      fn_ file from web/CueRCode.net/_functions/ WITHOUT loading db_connect.php
 *      or anything that would open a DB connection.
 *
 * ⚠️ This file lives under repo-root tests/ and is NEVER deployed (it sits
 *    outside web/, so the Dreamhost deploy never sees it).
 *
 * 🎯 Goal of this cycle: a SAFETY NET only. We capture current behaviour; we
 *    do NOT modify any production code under web/CueRCode.net/.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 1 (test safety-net cycle)
 * @see         https://www.php.net/manual/en/function.error-reporting.php
 * @see         https://www.php.net/manual/en/function.require-once.php
 */

// ==============================================================================
// 🐘 Step 1: Strict Error Reporting
// ==============================================================================
// Surface every notice, warning, and deprecation while tests run so the safety
// net also catches PHP-version drift (the project targets PHP 8.5 with 8.4
// backward compatibility).
// @see https://www.php.net/manual/en/function.error-reporting.php
// ==============================================================================

error_reporting(E_ALL);
ini_set('display_errors', '1');

// ==============================================================================
// 📂 Step 2: Path Constants
// ==============================================================================
// Mirror the directory layout the production bootstrap establishes, but anchored
// at the REAL production source tree (web/CueRCode.net/) so requireFn() can find
// the fn_ files. We resolve everything relative to THIS file so the harness is
// portable regardless of the working directory it is launched from.
//
// Layout (repo root):
//   <repo>/tests/bootstrap.php   <- this file (__DIR__ == <repo>/tests)
//   <repo>/web/CueRCode.net/...  <- canonical production source
// @see https://www.php.net/manual/en/language.constants.predefined.php
// ==============================================================================

$ds = DIRECTORY_SEPARATOR;

// 📌 Repo root is the parent of the tests/ directory.
if (!defined('CUERCODE_TEST_REPO_ROOT')) {
    define('CUERCODE_TEST_REPO_ROOT', dirname(__DIR__) . $ds);
}

// 📌 Canonical production site root: <repo>/web/CueRCode.net/
if (!defined('CUERCODE_SITE_ROOT')) {
    define(
        'CUERCODE_SITE_ROOT',
        CUERCODE_TEST_REPO_ROOT . 'web' . $ds . 'CueRCode.net' . $ds
    );
}

// 📌 Production functions directory (where the fn_ files live).
if (!defined('CUERCODE_FUNCTIONS_DIR')) {
    define('CUERCODE_FUNCTIONS_DIR', CUERCODE_SITE_ROOT . '_functions' . $ds);
}

// 📌 Production includes directory (NOT loaded - defined for parity only).
if (!defined('CUERCODE_INCLUDES_DIR')) {
    define('CUERCODE_INCLUDES_DIR', CUERCODE_SITE_ROOT . '_includes' . $ds);
}

// 📌 Production libraries directory (chillerlan/php-qrcode, FPDF). Some fn_
//    files reference classes from here; tests that need those must require the
//    autoloader explicitly. We define the path but do NOT auto-load it, so
//    DB-independent + library-independent functions stay isolated.
if (!defined('CUERCODE_LIBRARIES_DIR')) {
    define('CUERCODE_LIBRARIES_DIR', CUERCODE_SITE_ROOT . '_libraries' . $ds);
}

// 📌 Uploads directory (defined for parity; not used by unit tests).
if (!defined('CUERCODE_UPLOADS_DIR')) {
    define('CUERCODE_UPLOADS_DIR', CUERCODE_SITE_ROOT . '_uploads' . $ds);
}

// ==============================================================================
// 🔑 Step 3: TEST-ONLY Encryption Constants
// ==============================================================================
// These are FIXED, NON-SECRET, test-only values used purely so that crypto
// round-trip tests (encryptValue -> decryptValue) are deterministic and
// reproducible. They must NEVER be used in production. fn_encryption.php reads
// ENCRYPTION_KEY / ENCRYPTION_SALT as defaults when no key/salt argument is
// passed.
//
// A 32-byte (256-bit) key is appropriate for the AES-256-GCM cipher the code
// uses (after HKDF derivation). We use clearly-labelled test material.
// @see web/CueRCode.net/_functions/fn_encryption.php - encryptValue()/decryptValue()
// ==============================================================================

// 🔑 Exactly 32 bytes (count them: 4 x 8-char groups = 32 ASCII chars).
if (!defined('ENCRYPTION_KEY')) {
    define('ENCRYPTION_KEY', 'TEST_KEY_0123456789abcdef_ABCDEF');
}

// 🧂 Exactly 32 bytes of fixed salt material for deterministic HKDF derivation.
if (!defined('ENCRYPTION_SALT')) {
    define('ENCRYPTION_SALT', 'TEST_SALT_fedcba9876543210_FEDCBA');
}

// ==============================================================================
// 🔒 Step 4: Encryption Cipher Constants
// ==============================================================================
// fn_encryption.php self-defines these (guarded by defined() checks), but we
// pre-define them here so the harness is explicit about the cipher contract
// the round-trip tests rely on. The values MUST match fn_encryption.php.
// @see web/CueRCode.net/_functions/fn_encryption.php
// ==============================================================================

if (!defined('CUERCODE_CIPHER_ALGO')) {
    define('CUERCODE_CIPHER_ALGO', 'aes-256-gcm');
}

if (!defined('CUERCODE_TAG_LENGTH')) {
    define('CUERCODE_TAG_LENGTH', 16);
}

// ==============================================================================
// 🧰 Step 5: requireFn() - Safe production-function loader (NO DATABASE)
// ==============================================================================

/**
 * 🧰 Safely require a single production fn_ file WITHOUT opening a DB connection.
 *
 * The production fn_ files declare standalone functions and only TOUCH the
 * database lazily (inside function bodies, via getDB()/dbQuery()). Simply
 * including a fn_ file therefore does NOT connect to any database — it just
 * registers the function definitions. This helper centralises that include and
 * guarantees we never pull in db_connect.php or the production bootstrap.
 *
 * Each fn_ file begins with a "direct access protection" guard that compares
 * basename($_SERVER['SCRIPT_FILENAME']) to basename(__FILE__). Under the CLI
 * test runner, SCRIPT_FILENAME is the runner script (run.php), so the basenames
 * differ and the guard correctly allows the include to proceed.
 *
 * @param string $fnFile The fn_ file name (e.g. 'fn_encryption.php') OR a
 *                        bare base name (e.g. 'encryption' / 'fn_encryption').
 *
 * @return void
 *
 * @throws \RuntimeException If the requested fn_ file does not exist.
 *
 * @see https://www.php.net/manual/en/function.require-once.php
 *
 * @example
 *   requireFn('fn_encryption.php');   // explicit
 *   requireFn('encryption');          // shorthand (normalised to fn_encryption.php)
 */
function requireFn(string $fnFile): void
{
    // 🧹 Normalise the requested name into a canonical 'fn_*.php' filename.
    $name = trim($fnFile);

    // Strip any directory components a caller might have included.
    $name = basename($name);

    // Drop a trailing .php so we can re-append it uniformly.
    if (str_ends_with($name, '.php')) {
        $name = substr($name, 0, -4);
    }

    // Prefix with fn_ if the caller used the bare topic (e.g. 'encryption').
    if (!str_starts_with($name, 'fn_')) {
        $name = 'fn_' . $name;
    }

    // 📌 Build the absolute path into the production _functions directory.
    $path = CUERCODE_FUNCTIONS_DIR . $name . '.php';

    // ❌ Fail loudly if the file is missing - the test author needs to know.
    if (!is_file($path)) {
        throw new \RuntimeException(
            'requireFn(): production function file not found: ' . $path
        );
    }

    // ✅ Include it once. This registers the function definitions only;
    //    no database connection is opened by the act of including.
    require_once $path;
}

// ==============================================================================
// 📚 Step 6: requireLibraryAutoloader() - Opt-in QR library loader (NO DATABASE)
// ==============================================================================

/**
 * 📚 Opt-in loader for the third-party QR library autoloader (NO DATABASE).
 *
 * A few production functions (e.g. buildQROptions() in fn_qr_options.php) need
 * the chillerlan\QRCode classes. Loading the autoloader does NOT open a DB
 * connection, but it IS a heavier dependency, so tests must request it
 * explicitly rather than have it always loaded. Tests that only exercise pure
 * helpers (hexToRgb, mapEccLevel, payload builders, etc.) never need this.
 *
 * @return bool True if the autoloader was found and loaded, false otherwise.
 *
 * @see web/CueRCode.net/_libraries/autoload.php
 */
function requireLibraryAutoloader(): bool
{
    $autoloaderPath = CUERCODE_LIBRARIES_DIR . 'autoload.php';

    if (is_file($autoloaderPath)) {
        require_once $autoloaderPath;
        return true;
    }

    return false;
}
