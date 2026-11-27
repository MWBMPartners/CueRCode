<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Router for PHP's built-in web server (human check run, #163)
 * ==============================================================================
 *
 * Path: tests/integration/human_check_http_router.php
 *
 * Used ONLY by tests/integration/human_check_real_mysql.php, which starts
 *     php -d disable_functions=curl_init -S 127.0.0.1:<port> tests/integration/human_check_http_router.php
 * so the real pages and the real JSON API answer real HTTP requests against
 * a throwaway database. curl_init is switched off in that server, so the
 * site can NEVER call Cloudflare, Google or hCaptcha from here: a token that
 * reaches the "ask the provider" step always gets "could not be reached".
 *
 * It stands in for the Apache rewrite rules: /api/v1/<route> goes to the API
 * router with ?route=<route>; a folder address goes to that folder's
 * index.php; a real file (CSS, JavaScript) is served as it is.
 *
 * The database constants and the encryption key come from environment
 * variables the parent script sets (CUERCODE_IT_DB_* and
 * CUERCODE_IT_HC_DB / CUERCODE_IT_ENCRYPTION_KEY); the database name must
 * start with cuercode_it_. It never reads _auth_keys/auth_creds.php.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-27 (#163)
 * @see         tests/integration/human_check_real_mysql.php
 */

// 🚫 Only for PHP's built-in web server.
if (PHP_SAPI !== 'cli-server') {
    exit('This router is only for PHP\'s built-in web server.');
}

$hcrSiteRoot   = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'CueRCode.net' . DIRECTORY_SEPARATOR;
$hcrPublicRoot = $hcrSiteRoot . 'public_html' . DIRECTORY_SEPARATOR;

// 🗄️ The throwaway database only.
$hcrDbName = (string) getenv('CUERCODE_IT_HC_DB');
if (preg_match('/^cuercode_it_[a-z0-9_]+$/', $hcrDbName) !== 1) {
    http_response_code(500);
    exit('Refusing: the database name must start with cuercode_it_.');
}
$hcrPort = (string) getenv('CUERCODE_IT_DB_PORT');
if ($hcrPort === '') {
    $hcrPort = '3306';
}
define('DB_HOST', (string) getenv('CUERCODE_IT_DB_HOST'));
define('DB_PORT', (int) $hcrPort);
define('DB_USER', (string) getenv('CUERCODE_IT_DB_USER'));
define('DB_PASS', (string) getenv('CUERCODE_IT_DB_PASS'));
define('DB_NAME', $hcrDbName);
define('ENCRYPTION_KEY', (string) getenv('CUERCODE_IT_ENCRYPTION_KEY'));

$hcrPath = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);

// 🌐 The JSON API.
if (str_starts_with($hcrPath, '/api/v1/')) {
    $_GET['route'] = substr($hcrPath, strlen('/api/v1/'));
    $_SERVER['SCRIPT_FILENAME'] = $hcrPublicRoot . 'api' . DIRECTORY_SEPARATOR . 'v1' . DIRECTORY_SEPARATOR . 'index.php';
    chdir(dirname($_SERVER['SCRIPT_FILENAME']));
    require $_SERVER['SCRIPT_FILENAME'];
    return true;
}

// 📄 A real file (CSS, JavaScript, images): served as it is.
if ($hcrPath !== '/' && !str_ends_with($hcrPath, '/') && is_file($hcrPublicRoot . ltrim($hcrPath, '/'))) {
    return false;
}

// 📁 A page: the folder's index.php.
$hcrRelative = trim($hcrPath, '/');
if (preg_match('/^[a-z0-9\/-]*$/', $hcrRelative) !== 1 || str_contains($hcrRelative, '..')) {
    http_response_code(404);
    exit('Not found.');
}
$hcrPage = $hcrPublicRoot . 'index.php';
if ($hcrRelative !== '') {
    $hcrPage = $hcrPublicRoot . str_replace('/', DIRECTORY_SEPARATOR, $hcrRelative) . DIRECTORY_SEPARATOR . 'index.php';
}
if (!is_file($hcrPage)) {
    http_response_code(404);
    exit('Not found.');
}
$_SERVER['SCRIPT_FILENAME'] = $hcrPage;
$_SERVER['SCRIPT_NAME']     = '/' . $hcrRelative . '/index.php';
chdir(dirname($hcrPage));
require $hcrPage;
return true;
