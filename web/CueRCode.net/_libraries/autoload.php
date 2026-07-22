<?php
/**
 * ==============================================================================
 * 📦 CueRCode - Custom PSR-4 Autoloader
 * ==============================================================================
 *
 * This autoloader replaces Composer's vendor/autoload.php for environments
 * where Composer CLI is not available (e.g., Dreamhost Shared Hosting).
 *
 * It maps PHP namespace prefixes to local directory paths, following the
 * PSR-4 autoloading standard. When a class is instantiated, PHP calls
 * this autoloader to locate and include the corresponding file.
 *
 * 📌 Libraries must be manually downloaded and placed in the correct directories:
 *     - chillerlan/php-qrcode v5.x → _libraries/chillerlan/php-qrcode/src/
 *     - chillerlan/php-settings-container v3.x → _libraries/chillerlan/php-settings-container/src/
 *     - setasign/fpdf v1.8.x → _libraries/fpdf/
 *
 * @package     CueRCode
 * @subpackage  Libraries
 * @version     1.0.0
 * @since       Phase 1
 * @see         https://www.php-fig.org/psr/psr-4/ PSR-4 Autoloading Standard
 * @see         https://www.php.net/manual/en/function.spl-autoload-register.php
 * @see         https://github.com/chillerlan/php-qrcode chillerlan/php-qrcode
 * @see         https://github.com/chillerlan/php-settings-container chillerlan/php-settings-container
 * @see         https://www.fpdf.org/ FPDF Library
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
// 📂 Base directory for all libraries (this file's directory)
// Uses DIRECTORY_SEPARATOR for platform neutrality
// @see https://www.php.net/manual/en/language.constants.predefined.php
// ==============================================================================
$libraryBaseDir = __DIR__ . DIRECTORY_SEPARATOR;

// ==============================================================================
// 🗺️ PSR-4 Namespace Prefix to Directory Mappings
// Each key is a namespace prefix (with trailing backslash).
// Each value is the base directory containing the classes for that namespace.
// @see https://www.php-fig.org/psr/psr-4/#2-specification
// ==============================================================================
$psr4Prefixes = [
    // 📦 chillerlan/php-qrcode - QR Code generation library
    // @see https://github.com/chillerlan/php-qrcode
    'chillerlan\\QRCode\\'    => $libraryBaseDir
        . 'chillerlan' . DIRECTORY_SEPARATOR
        . 'php-qrcode' . DIRECTORY_SEPARATOR
        . 'src' . DIRECTORY_SEPARATOR,

    // 📦 chillerlan/php-settings-container - Settings/options container (dependency of php-qrcode)
    // @see https://github.com/chillerlan/php-settings-container
    'chillerlan\\Settings\\'  => $libraryBaseDir
        . 'chillerlan' . DIRECTORY_SEPARATOR
        . 'php-settings-container' . DIRECTORY_SEPARATOR
        . 'src' . DIRECTORY_SEPARATOR,
];

// ==============================================================================
// ⚙️ Register PSR-4 Autoloader
// This function is called automatically by PHP whenever a class is used
// that hasn't been loaded yet. It translates the fully-qualified class name
// into a file path and includes it.
// @see https://www.php.net/manual/en/function.spl-autoload-register.php
// ==============================================================================
spl_autoload_register(function (string $class) use ($psr4Prefixes): void {

    // 🔍 Iterate through registered namespace prefixes to find a match
    foreach ($psr4Prefixes as $prefix => $baseDir) {

        // 📏 Get the length of the namespace prefix for comparison
        $prefixLength = strlen($prefix);

        // ❌ Skip if the class doesn't start with this namespace prefix
        if (strncmp($prefix, $class, $prefixLength) !== 0) {
            continue;
        }

        // ✅ Class matches this prefix - extract the relative class name
        // Remove the namespace prefix to get the path relative to the base directory
        $relativeClass = substr($class, $prefixLength);

        // 🔧 Build the file path:
        // Replace namespace separators (\) with directory separators
        // Append .php extension
        // @see https://www.php.net/manual/en/function.str-replace.php
        $filePath = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

        // 📄 Check if the file exists and include it
        if (file_exists($filePath)) {
            require $filePath;
            return;
        }
    }

    // 📌 Note: If no prefix matches or file not found, PHP will continue
    // to the next registered autoloader (or throw a class-not-found error
    // if no autoloaders can resolve the class).

}, true, true);
// 📌 Parameters: $callback, $throw = true (throw on failure), $prepend = true (run first)

// ==============================================================================
// 📦 Load Non-Namespaced Libraries
// FPDF is a legacy library that does not use PHP namespaces.
// It must be loaded separately via require_once.
// @see https://www.fpdf.org/
// ==============================================================================
$fpdfPath = $libraryBaseDir . 'fpdf' . DIRECTORY_SEPARATOR . 'fpdf.php';

if (file_exists($fpdfPath)) {
    require_once $fpdfPath;
} else {
    // ⚠️ FPDF not found - PDF output will not be available
    // This is not fatal; the application will check for FPDF availability
    // before attempting PDF generation and return an appropriate error.
    // Log this as a notice if error logging is available.
    if (function_exists('logError')) {
        logError(
            'notice',
            'FPDF library not found',
            'PDF output format will be unavailable. Download FPDF from https://www.fpdf.org/ '
            . 'and place fpdf.php in: ' . $fpdfPath
        );
    }
}

// ==============================================================================
// ✅ Autoloader Registration Complete
// Classes from the following namespaces can now be used:
//   - chillerlan\QRCode\*    (QR code generation)
//   - chillerlan\Settings\*  (Settings container)
//   - FPDF                   (PDF generation - if installed)
// ==============================================================================
