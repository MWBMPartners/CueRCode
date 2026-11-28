<?php
/**
 * ==============================================================================
 * 🛡️ CueRCode - Throwaway-database guard for the real-database tests (#174)
 * ==============================================================================
 *
 * Path: tools/ci/integration_db_guard.php
 *
 * Used by tools/ci/run-integration-tests.sh (decision 0040). The scripts in
 * tests/integration/ create, empty and DROP whole databases, so before any of
 * them runs this guard proves the server is a throwaway one:
 *
 *   1. the host is this machine (127.0.0.1, localhost or ::1) - a shared or
 *      live server is always somewhere else;
 *   2. the server holds NO database except the four the server makes for
 *      itself (information_schema, mysql, performance_schema, sys) and ones
 *      whose names start with "cuercode_it_". A live site's database, or any
 *      other project's, makes it refuse.
 *
 * It waits up to 180 seconds for the server (a database container restarts
 * once while it starts) and, with --create=NAME, also creates one empty
 * cuercode_it_ database for a script that expects it to exist already.
 *
 * Connection details come ONLY from CUERCODE_IT_DB_HOST, CUERCODE_IT_DB_PORT,
 * CUERCODE_IT_DB_USER and CUERCODE_IT_DB_PASS; it never reads the site's
 * _auth_keys/auth_creds.php.
 *
 * Exit codes: 0 safe to run | 1 could not connect | 2 refused.
 *
 * Usage:  php tools/ci/integration_db_guard.php [--create=cuercode_it_name]
 *
 * @package     CueRCode
 * @subpackage  CI
 * @copyright   2025-2026 MWBM Partners Ltd. All rights reserved.
 * @version     1.0.0
 * @since       2026-11-28 (#174, decision 0040)
 * @see         tools/ci/run-integration-tests.sh
 */

// 🚫 Command line only.
if (PHP_SAPI !== 'cli') {
    exit('Access denied.');
}

/** 🏷️ Every database a test may touch starts with this. */
const CUERCODE_IT_GUARD_PREFIX = 'cuercode_it_';

/** 🗄️ The databases a MySQL or MariaDB server makes for itself. */
const CUERCODE_IT_GUARD_SYSTEM_DATABASES = ['information_schema', 'mysql', 'performance_schema', 'sys'];

/**
 * 🔎 Why a server is NOT safe for the real-database tests ([] = safe).
 *
 * Pure function (no connection), so the unit tests can prove every refusal.
 *
 * @param string        $host      The host the tests would connect to.
 * @param array<string> $databases Every database name the server lists.
 *
 * @return array<int, string> Plain-English problems; empty when it is safe.
 */
function cuercodeItGuardProblems(string $host, array $databases): array
{
    $problems = [];

    // 🏠 1. Only this machine. A throwaway container is reached on loopback.
    if (!in_array(strtolower(trim($host)), ['127.0.0.1', 'localhost', '::1'], true)) {
        $problems[] = 'the database host "' . $host . '" is not this machine (127.0.0.1, localhost or ::1), so it cannot be a throwaway test server';
    }

    // 🗄️ 2. Nothing on the server but its own databases and test ones.
    foreach ($databases as $database) {
        $name = (string) $database;
        if (in_array(strtolower($name), CUERCODE_IT_GUARD_SYSTEM_DATABASES, true)) {
            continue;
        }
        if (str_starts_with($name, CUERCODE_IT_GUARD_PREFIX)) {
            continue;
        }
        $problems[] = 'the server holds the database "' . $name . '", which is not a test database, so it is not a throwaway server';
    }

    return $problems;
}

/**
 * 🏷️ Whether a name is an allowed test database name.
 *
 * @param string $name The database name.
 *
 * @return bool True for cuercode_it_ followed by lower-case letters, digits or _.
 */
function cuercodeItGuardNameAllowed(string $name): bool
{
    return preg_match('/^cuercode_it_[a-z0-9_]{1,40}$/', $name) === 1;
}

// ==============================================================================
// ▶️ Main (skipped when a test loads this file for its functions)
// ==============================================================================
if (defined('CUERCODE_IT_GUARD_NO_MAIN')) {
    return;
}

$guardCreate = '';
foreach (array_slice($argv, 1) as $guardArgument) {
    if (str_starts_with($guardArgument, '--create=')) {
        $guardCreate = substr($guardArgument, strlen('--create='));
    } else {
        fwrite(STDERR, 'Unknown option: ' . $guardArgument . PHP_EOL);
        exit(2);
    }
}
if ($guardCreate !== '' && !cuercodeItGuardNameAllowed($guardCreate)) {
    fwrite(STDERR, 'Refusing: a database to create must be named cuercode_it_ followed by lower-case letters, digits or _.' . PHP_EOL);
    exit(2);
}

$guardHost = (string) getenv('CUERCODE_IT_DB_HOST');
$guardUser = (string) getenv('CUERCODE_IT_DB_USER');
$guardPass = (string) getenv('CUERCODE_IT_DB_PASS');
$guardPort = (int) getenv('CUERCODE_IT_DB_PORT');
if ($guardPort <= 0) {
    $guardPort = 3306;
}
if ($guardHost === '' || $guardUser === '') {
    fwrite(STDERR, 'Refusing: set CUERCODE_IT_DB_HOST and CUERCODE_IT_DB_USER.' . PHP_EOL);
    exit(2);
}

// 🏠 Refuse a far-away host before even trying to connect to it.
$guardHostProblems = cuercodeItGuardProblems($guardHost, []);
if ($guardHostProblems !== []) {
    fwrite(STDERR, 'Refusing: ' . implode('; ', $guardHostProblems) . '.' . PHP_EOL);
    exit(2);
}

// ⏳ Wait for the server.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$guardDeadline = microtime(true) + 180;
$guardConn     = null;
$guardLastErr  = '';
while ($guardConn === null && microtime(true) < $guardDeadline) {
    try {
        $guardConn = new mysqli($guardHost, $guardUser, $guardPass, '', $guardPort);
        $guardConn->query('SELECT 1');
    } catch (Throwable $guardError) {
        $guardConn    = null;
        $guardLastErr = $guardError->getMessage();
        usleep(500000);
    }
}
if ($guardConn === null) {
    fwrite(STDERR, 'Could not connect to the test database server within 180 seconds: ' . $guardLastErr . PHP_EOL);
    exit(1);
}

// 🗄️ Every database the server lists. (A user without rights to a database
//    does not see it, which is one reason the tests connect as the throwaway
//    server's own root user.)
$guardDatabases = [];
$guardResult    = $guardConn->query('SHOW DATABASES');
while ($guardRow = $guardResult->fetch_row()) {
    $guardDatabases[] = (string) $guardRow[0];
}
$guardResult->free();

$guardProblems = cuercodeItGuardProblems($guardHost, $guardDatabases);
if ($guardProblems !== []) {
    fwrite(STDERR, 'Refusing: ' . implode('; ', $guardProblems) . '.' . PHP_EOL);
    $guardConn->close();
    exit(2);
}

if ($guardCreate !== '') {
    // 🧱 The name was checked above (letters, digits and _ only), so it is
    //    safe inside the backticks; a name cannot be a bound parameter.
    $guardConn->query('CREATE DATABASE IF NOT EXISTS `' . $guardCreate . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    echo 'Created test database ' . $guardCreate . '.' . PHP_EOL;
} else {
    echo 'Test database server: ' . $guardConn->server_info . ' on ' . $guardHost . ':' . $guardPort . ' (throwaway: no other databases).' . PHP_EOL;
}

$guardConn->close();
exit(0);
