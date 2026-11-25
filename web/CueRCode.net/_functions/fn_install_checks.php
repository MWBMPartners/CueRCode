<?php
/**
 * ==============================================================================
 * 🔧 CueRCode - Installer Readiness Checks (pure, testable helpers)
 * ==============================================================================
 *
 * Path: _functions/fn_install_checks.php
 *
 * The decision logic behind the installer's "is this server ready?" checks,
 * kept apart from public_html/install/index.php so it can be unit tested
 * without a web server or a database:
 *
 *   - installExtensionChecks()       - PHP extensions needed (decision 0012 list)
 *   - installCheckDatabaseVersion()  - MySQL 8.0.13+ / MariaDB 10.6+ (decision 0012)
 *   - installCheckWritableFolder()   - _uploads / _backups exist and PHP can write (B7)
 *   - installOpenDatabase()          - connect without ever throwing (Codex C1 finding 5)
 *
 * Apart from installOpenDatabase() (which only opens a connection), nothing
 * here touches the database, and nothing sends output. Messages are written in
 * plain English because the person reading them is setting up a server.
 *
 * @package     CueRCode
 * @subpackage  Installation
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       #113 (B2, B7)
 * @see         docs/decisions/0012-minimum-database-mysql-8-0-13-mariadb-10-6.md
 */

/** @var string Lowest MySQL version the database setup file works on. */
const INSTALL_MIN_MYSQL_VERSION = '8.0.13';

/** @var string Lowest MariaDB version supported. */
const INSTALL_MIN_MARIADB_VERSION = '10.6';

/**
 * 🧩 The PHP extensions CueRCode needs, with what each one is for in plain words.
 *
 * @return array<string, string> Extension name => what it is used for.
 */
function installRequiredExtensions(): array
{
    return [
        'mysqli'   => 'talks to the database',
        'mbstring' => 'handles text in any language and symbol (accents, emoji, non-Latin scripts)',
        'gd'       => 'draws and edits images (QR code pictures, logos, PNG and JPEG output)',
        'json'     => 'reads and writes the data format used by the API and settings',
        'openssl'  => 'encrypts sensitive data and makes secure connections',
        'zip'      => 'builds the ZIP downloads for bulk QR code and barcode exports',
        'bcmath'   => 'exact big-number maths, needed by the PDF417 barcode type',
        'curl'     => 'lets the site call other services (email delivery, bot checks, sign-in providers)',
    ];
}

/**
 * ✅ Check each required PHP extension against the list that is loaded.
 *
 * @param array<int, string> $loadedExtensions Names from get_loaded_extensions().
 *
 * @return array<int, array{name: string, purpose: string, ok: bool, message: string}>
 */
function installExtensionChecks(array $loadedExtensions): array
{
    $loaded = array_map('strtolower', $loadedExtensions);
    $rows   = [];

    foreach (installRequiredExtensions() as $name => $purpose) {
        $isLoaded = in_array($name, $loaded, true);
        if ($isLoaded) {
            $message = 'Loaded.';
        } else {
            $message = 'The PHP "' . $name . '" extension is missing. It ' . $purpose
                . '. In the DreamHost panel open Websites > Manage Websites > Manage for this domain'
                . ' and check the PHP version and extensions, or ask DreamHost support to enable it.';
        }
        $rows[] = [
            'name'    => $name,
            'purpose' => $purpose,
            'ok'      => $isLoaded,
            'message' => $message,
        ];
    }

    return $rows;
}

/**
 * 🔢 Work out which database server a version string describes.
 *
 * MariaDB often reports itself as "5.5.5-10.6.12-MariaDB-log" (a compatibility
 * prefix), which must be removed before reading the real number.
 *
 * @param string $serverInfo The text from mysqli::$server_info or SELECT VERSION().
 *
 * @return array{flavour: string, version: string} flavour is "mariadb", "mysql", "unsupported" (Aurora) or "unknown".
 */
function installParseDatabaseVersion(string $serverInfo): array
{
    $text = trim($serverInfo);

    // ☁️ Amazon Aurora (and similar look-alikes) report a MySQL number but are
    //    not what CueRCode was built and tested on, so they are refused with
    //    their own message rather than passed as plain MySQL.
    if (stripos($text, 'aurora') !== false) {
        return ['flavour' => 'unsupported', 'version' => ''];
    }

    $isMariaDb = stripos($text, 'mariadb') !== false;

    // 🧹 Review fix: a MariaDB server read through some client libraries says
    //    "5.5.5-10.6.12" (the compatibility prefix and the real number) WITHOUT
    //    the word MariaDB. A real MySQL never starts with "5.5.5-" followed by
    //    another version, so treat that shape as MariaDB 10.6.12.
    if (preg_match('/^5\.5\.5-(\d+\.\d+(?:\.\d+)?)/', $text) === 1) {
        $isMariaDb = true;
    }

    if ($isMariaDb) {
        // 🧹 Drop the fake "5.5.5-" prefix some client libraries put in front.
        $text = preg_replace('/^5\.5\.5-/', '', $text) ?? $text;
    }

    if (preg_match('/^(\d+\.\d+(?:\.\d+)?)/', $text, $matches) !== 1) {
        return ['flavour' => 'unknown', 'version' => ''];
    }

    if ($isMariaDb) {
        $flavour = 'mariadb';
    } else {
        $flavour = 'mysql';
    }

    return ['flavour' => $flavour, 'version' => $matches[1]];
}

/**
 * 🗄️ Decide whether the database server is new enough (decision 0012).
 *
 * @param string $serverInfo The server's version text.
 *
 * @return array{ok: bool, flavour: string, version: string, message: string}
 */
function installCheckDatabaseVersion(string $serverInfo): array
{
    $parsed  = installParseDatabaseVersion($serverInfo);
    $version = $parsed['version'];

    if ($parsed['flavour'] === 'unsupported') {
        return [
            'ok'      => false,
            'flavour' => 'unsupported',
            'version' => '',
            'message' => 'This database ("' . $serverInfo . '") is Amazon Aurora, which CueRCode does not support.'
                . ' CueRCode needs MySQL ' . INSTALL_MIN_MYSQL_VERSION . ' or newer, or MariaDB '
                . INSTALL_MIN_MARIADB_VERSION . ' or newer (a standard DreamHost MySQL database is fine).'
                . ' Nothing has been changed in the database.',
        ];
    }

    if ($parsed['flavour'] === 'unknown') {
        return [
            'ok'      => false,
            'flavour' => 'unknown',
            'version' => '',
            'message' => 'Could not tell which database version this server runs, so it is treated as unsupported ("' . $serverInfo
                . '"). CueRCode needs MySQL ' . INSTALL_MIN_MYSQL_VERSION . ' or newer, or MariaDB '
                . INSTALL_MIN_MARIADB_VERSION . ' or newer.',
        ];
    }

    if ($parsed['flavour'] === 'mariadb') {
        $minimum = INSTALL_MIN_MARIADB_VERSION;
        $label   = 'MariaDB';
    } else {
        $minimum = INSTALL_MIN_MYSQL_VERSION;
        $label   = 'MySQL';
    }

    if (version_compare($version, $minimum, '>=')) {
        return [
            'ok'      => true,
            'flavour' => $parsed['flavour'],
            'version' => $version,
            'message' => $label . ' ' . $version . ' is new enough.',
        ];
    }

    return [
        'ok'      => false,
        'flavour' => $parsed['flavour'],
        'version' => $version,
        'message' => 'This database is ' . $label . ' ' . $version . ', which is too old. CueRCode needs MySQL '
            . INSTALL_MIN_MYSQL_VERSION . ' or newer, or MariaDB ' . INSTALL_MIN_MARIADB_VERSION
            . ' or newer. In the DreamHost panel, create a new database on a newer version'
            . ' (MySQL > Manage MySQL Databases), point auth_creds.php at it and run the installer again.'
            . ' Nothing has been changed in the old database.',
    ];
}

/**
 * 📁 Check that a storage folder exists and PHP can write to it (B7).
 *
 * @param string $path    Absolute folder path.
 * @param string $name    Folder name as shown to people, e.g. "_uploads".
 * @param string $purpose What the folder is used for, in plain words.
 *
 * @return array{ok: bool, message: string}
 */
function installCheckWritableFolder(string $path, string $name, string $purpose): array
{
    if (!is_dir($path)) {
        return [
            'ok'      => false,
            'message' => 'The folder "' . $name . '" does not exist (' . $purpose . '). Create it next to'
                . ' public_html on the server (for example with your SFTP program) and run the check again.',
        ];
    }

    if (!is_writable($path)) {
        return [
            'ok'      => false,
            'message' => 'The folder "' . $name . '" exists but the website is not allowed to write to it ('
                . $purpose . '). Set its permissions so the website user can write, usually 755 or 775'
                . ' in your SFTP program, and run the check again.',
        ];
    }

    return ['ok' => true, 'message' => 'The folder "' . $name . '" exists and can be written to.'];
}

/**
 * 🚦 Everything that must be in place BEFORE the installer touches anything,
 *    as a list of plain-English problems (empty = good to go). PURE.
 *
 * The page shows the same checks as a list, but hiding the "Install" button
 * is not a safeguard: anyone can send the form by hand. The POST handler
 * therefore calls this too and refuses (review fix M7).
 *
 * @param string                                       $phpVersion       PHP_VERSION
 * @param array<int, string>                           $loadedExtensions get_loaded_extensions()
 * @param array<int, array{ok: bool, message: string}> $folderChecks     installCheckWritableFolder() results
 *
 * @return array<int, string> One message per problem
 */
function installPreflightProblems(string $phpVersion, array $loadedExtensions, array $folderChecks): array
{
    $problems = [];

    if (version_compare($phpVersion, '8.4.0', '<')) {
        $problems[] = 'PHP ' . $phpVersion . ' is too old. CueRCode needs PHP 8.4 or newer (8.4, 8.5 or 8.6).';
    }

    foreach (installExtensionChecks($loadedExtensions) as $extension) {
        if (!$extension['ok']) {
            $problems[] = $extension['message'];
        }
    }

    foreach ($folderChecks as $folder) {
        if (!$folder['ok']) {
            $problems[] = $folder['message'];
        }
    }

    return $problems;
}

/**
 * 🔒 Where the "installation is complete" lock file lives. PURE.
 *
 * Review fix M8: the lock used to live ONLY inside install/, so deleting that
 * folder (as the README once advised) removed the lock, and the next deploy
 * brought the installer back unlocked. The main lock now lives in
 * _auth_keys/ - a folder that is not on the web, is not part of the
 * repository and is never overwritten by a deploy (the deploy only adds and
 * updates files). The old place is still honoured so sites installed earlier
 * stay locked.
 *
 * @param string $siteRoot   Site root, with a trailing slash
 * @param string $installDir Installer folder, with a trailing slash
 *
 * @return array{primary: string, legacy: string}
 */
function installLockFilePaths(string $siteRoot, string $installDir): array
{
    return [
        'primary' => $siteRoot . '_auth_keys' . DIRECTORY_SEPARATOR . '.installed.lock',
        'legacy'  => $installDir . '.installed.lock',
    ];
}

/**
 * 🔒 Can the installer write its "already installed" lock? (Review finding 9.)
 *
 * The lock goes in _auth_keys/ (preferred) or, failing that, install/. If
 * NEITHER folder can be written, the installer must refuse to start: it would
 * otherwise build the database and then be unable to switch itself off,
 * leaving a working installer on the web. All the site's copies (public_html,
 * the alpha and beta copies) share the ONE _auth_keys/ folder, so one lock
 * there covers every copy.
 *
 * @param array{primary: string, legacy: string} $paths From installLockFilePaths()
 * @return array{ok: bool, message: string}
 */
function installLockFolderCheck(array $paths): array
{
    $primaryFolder = dirname($paths['primary']);
    $legacyFolder  = dirname($paths['legacy']);

    if (is_dir($primaryFolder) && is_writable($primaryFolder)) {
        return ['ok' => true, 'message' => 'The installer can write its lock file in _auth_keys/.'];
    }

    if (is_dir($legacyFolder) && is_writable($legacyFolder)) {
        return ['ok' => true, 'message' => 'The installer can write its lock file only inside install/ (the _auth_keys folder is not writable). Keep the install folder and its .htaccess on the server afterwards.'];
    }

    return [
        'ok'      => false,
        'message' => 'The installer cannot write its "already installed" lock file in _auth_keys/ or in install/, so it could not switch itself off afterwards. '
            . 'Let the website write to the _auth_keys folder (next to public_html, usually permissions 755 or 775 in your SFTP program) and run the check again.',
    ];
}

/**
 * 🔎 Is the installer locked? True when a lock file exists in either place.
 *
 * @param array{primary: string, legacy: string} $paths From installLockFilePaths()
 *
 * @return bool
 */
function installIsLocked(array $paths): bool
{
    return file_exists($paths['primary']) || file_exists($paths['legacy']);
}

/**
 * 🔌 Open the installer's database connection WITHOUT ever throwing
 *    (independent review, Codex C1 finding 5, 2026-11-25).
 *
 * Since PHP 8.1, MySQLi throws a mysqli_sql_exception when a connection fails
 * (the default error mode is "report and throw"). The installer used
 * `@new \mysqli(...)`, but "@" only hides warnings - it does not stop an
 * exception - so a wrong password or a stopped database server crashed the
 * page with an uncaught error instead of showing the plain message, and that
 * error could include connection details if the host displays errors.
 * Measured 2026-11-25 on PHP 8.4.27 and 8.5.12: both throw.
 *
 * This is the only function in this file that touches a database, and it is
 * still unit tested without one: a connection to a closed port fails at once.
 *
 * @param string $host     Database host
 * @param string $user     Database user
 * @param string $password Database password
 * @param string $name     Database name
 * @param int    $port     Database port
 *
 * @return array{ok: bool, db: \mysqli|null, error: string} error holds the
 *         technical detail - write it to the server log, never to the page.
 */
function installOpenDatabase(string $host, string $user, string $password, string $name, int $port): array
{
    try {
        $connection = new \mysqli($host, $user, $password, $name, $port);
    } catch (\mysqli_sql_exception $connectError) {
        return [
            'ok'    => false,
            'db'    => null,
            'error' => $connectError->getMessage() . ' (Error ' . $connectError->getCode() . ')',
        ];
    }

    // 🧯 If error reporting has been switched off elsewhere, a failed
    //    connection comes back as an object with connect_error set instead.
    if ($connection->connect_error) {
        return [
            'ok'    => false,
            'db'    => null,
            'error' => $connection->connect_error . ' (Error ' . $connection->connect_errno . ')',
        ];
    }

    return ['ok' => true, 'db' => $connection, 'error' => ''];
}
