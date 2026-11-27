<?php
/**
 * ==============================================================================
 * 🔧 CueRCode - Web-Based Database Installer
 * ==============================================================================
 *
 * Path: public_html/install/index.php
 *
 * This script provides a web-based interface for installing the CueRCode
 * database schema and seed data. It checks prerequisites, creates tables,
 * and populates initial data.
 *
 * ⚠️  SECURITY: This installer should be removed or access-restricted after
 *     successful installation. A lock file is created to prevent re-execution.
 *
 * @package     CueRCode
 * @subpackage  Installation
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.1.0
 * @since       Phase 1
 * @since       2026-11-23 (task 7n Phase 1A, #113 item 5, #70) - after seeding,
 *              the installer now also applies the numbered database upgrades
 *              ("migrations") in _includes/sql/migrations/, and refuses to
 *              re-run seed_data.sql (which is not safe to run twice) against
 *              a database that already has starting data.
 * @since       2026-11-25 (independent review, Codex C1 findings 1-5) - one
 *              installer at a time (a database named lock), starting data
 *              loaded all-or-nothing and checked complete before locking, a
 *              missing seed file is an error, and a failed database connection
 *              shows the plain message instead of crashing.
 * @see         https://www.php.net/manual/en/book.mysqli.php
 * @see         ../../_functions/fn_migrations.php - migrationApplyPending(), the database upgrade engine
 * @see         .claude/plans/7n-admin-commerce-plan.md §5.1 (the migration runner specification)
 */

// ==============================================================================
// ⚙️ Configuration and Path Constants
// Uses PHP predefined constants for platform neutrality
// @see https://www.php.net/manual/en/language.constants.predefined.php
// ==============================================================================

/** @var string Directory separator shorthand for readability */
$ds = DIRECTORY_SEPARATOR;

/** @var string Path to this installer directory */
$installDir = __DIR__ . $ds;

/** @var string Path to the CueRCode site root (two levels up from public_html/install/) */
$siteRoot = dirname(dirname($installDir)) . $ds;

/** @var string Path to the server-wide root (three levels up) */
$serverRoot = dirname($siteRoot) . $ds;

// 🔧 Pure readiness-check helpers (PHP extensions, database version, writable
// folders, lock-file paths) - kept in their own file so they can be unit tested
// (#113 B2, B7). IMPORTANT: this must be loaded BEFORE the first helper call
// below (installLockFilePaths). Loading it later made every request a fatal
// error found by the fresh-install dry run (B9, 2026-11-25).
require_once $siteRoot . '_functions' . $ds . 'fn_install_checks.php';

/**
 * @var string Lock file path - prevents re-running the installer. Review fix
 *             M8: it now lives in _auth_keys/ (outside install/ and outside
 *             the repository), so removing install/ or deploying again cannot
 *             lose it. The old place (install/.installed.lock) still counts.
 */
$lockPaths  = installLockFilePaths($siteRoot, $installDir);
$lockFile   = $lockPaths['primary'];
$legacyLockFile = $lockPaths['legacy'];

/** @var string Path to SQL installation script */
$installSQLPath = $siteRoot . '_includes' . $ds . 'sql' . $ds . 'install.sql';

/** @var string Path to SQL seed data script */
$seedSQLPath = $siteRoot . '_includes' . $ds . 'sql' . $ds . 'seed_data.sql';

// ==============================================================================
// 🔒 Lock File Check - Prevent Re-Execution
// ==============================================================================
$isLocked = installIsLocked($lockPaths);

// 🔒 If installation is already complete, refuse to load the page entirely.
// This prevents information disclosure about the installer's existence.
if ($isLocked) {
    header('HTTP/1.1 403 Forbidden');
    echo '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body>'
        . '<h1>403 Forbidden</h1>'
        . '<p>Installation has already been completed.</p>'
        . '</body></html>';
    exit;
}

// ==============================================================================
// 📊 Results tracking
// ==============================================================================
$results = [];
$overallSuccess = false;
$installRequested = false;

/** @var bool Whether seed_data.sql itself failed to run (F1 - this must stop
 *            the installer BEFORE the lock file is written, even though the
 *            tables from install.sql exist and the upgrade tool succeeded) */
$seedStepFailed = false;

// ==============================================================================
// 🔍 Check for credential files
// ==============================================================================

/** @var string Site-specific credentials path */
$siteCredsPath = $siteRoot . '_auth_keys' . $ds . 'auth_creds.php';

/** @var string Server-wide credentials path (fallback) */
$serverCredsPath = $serverRoot . '_auth_keys' . $ds . 'auth_creds.php';

/** @var bool Whether credentials are available */
$credsAvailable = file_exists($siteCredsPath) || file_exists($serverCredsPath);

// ==============================================================================
// 🛡️ CSRF Protection for Installer
// ==============================================================================
// 📌 DESIGN NOTE (F-010 — intentional, do NOT "consolidate" into fn_csrf):
//
// The installer deliberately uses its OWN standalone, one-shot CSRF token
// rather than the application-wide fn_csrf helper. This is a conscious choice,
// not an oversight or inconsistency — please leave it as-is:
//
//   1. PRE-BOOTSTRAP CONTEXT. The installer runs BEFORE the database schema
//      exists and before the normal application bootstrap (bootstrap.php /
//      fn_csrf.php) is guaranteed to be loadable. It must stand entirely on
//      its own with zero dependency on app internals it is about to create.
//      Routing through fn_csrf would couple a chicken-and-egg first-run page
//      to a stack that may not yet be wired up.
//
//   2. SINGLE-USE / ONE-SHOT. The token is regenerated immediately after a
//      successful validation (see below), so it cannot be replayed. After a
//      successful install the lock file (.installed.lock) hard-disables the
//      whole page, so the installer's CSRF surface exists only for the brief
//      one-time setup window.
//
//   3. CRYPTOGRAPHICALLY SOUND. The token is bin2hex(random_bytes(32)) (256
//      bits of CSPRNG entropy) and verified with hash_equals() (timing-safe
//      comparison) — the same primitives fn_csrf uses. Security posture is
//      equivalent; only the plumbing is local.
//
// Per SECURITY.md F-010 this is rated Low and accepted as sound. This block
// documents the rationale so future audits do not re-flag the two-implementation
// pattern as an inconsistency.
//
// Uses PHP sessions directly since the bootstrap/fn_csrf.php may not be
// available during installation (see point 1 above).
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['install_csrf_token'])) {
    $_SESSION['install_csrf_token'] = bin2hex(random_bytes(32));
}
$installCsrfToken = $_SESSION['install_csrf_token'];

// ==============================================================================
// 🐘 Process Installation Request
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isLocked && $credsAvailable) {
    // 🛡️ Validate CSRF token before processing installation
    $submittedToken = '';
    if (isset($_POST['install_csrf_token'])) {
        $submittedToken = $_POST['install_csrf_token'];
    }
    if (!hash_equals($installCsrfToken, $submittedToken)) {
        header('HTTP/1.1 403 Forbidden');
        echo '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body>'
            . '<h1>403 Forbidden</h1>'
            . '<p>Invalid or missing CSRF token. Please reload the page and try again.</p>'
            . '</body></html>';
        exit;
    }

    // 🔄 Regenerate CSRF token after validation to prevent reuse
    // (this is the "one-shot / single-use" property described in the design
    //  note above — F-010): each accepted token is consumed and replaced, so
    //  it can never be replayed.
    $_SESSION['install_csrf_token'] = bin2hex(random_bytes(32));

    $installRequested = true;

    // ----------------------------------------------------------------
    // 🚦 Step 0 (review fix M7): re-check the prerequisites on the SERVER
    // side. The page hides the Install button when something is missing, but
    // a form can be sent by hand, so a missing PHP extension or an unwritable
    // folder must refuse here too, with the same plain messages.
    // ----------------------------------------------------------------
    $postFolderChecks = [];
    foreach (['_uploads' => 'user-uploaded logos and files', '_backups' => 'database and file backups'] as $postFolderName => $postFolderPurpose) {
        $postFolderChecks[] = installCheckWritableFolder($siteRoot . $postFolderName, $postFolderName, $postFolderPurpose);
    }
    // 🔒 Review finding 9: refuse to start unless the lock can be written
    //    afterwards (in _auth_keys/ or, failing that, install/).
    $postFolderChecks[] = installLockFolderCheck($lockPaths);
    $preflightProblems = installPreflightProblems(PHP_VERSION, get_loaded_extensions(), $postFolderChecks);

    foreach ($preflightProblems as $preflightProblem) {
        $results[] = ['status' => 'error', 'message' => $preflightProblem];
    }
    if ($preflightProblems !== []) {
        $results[] = [
            'status' => 'error',
            'message' => 'Installation was refused before anything was changed. Fix the problems above, then try again.'
        ];
    }

    // ----------------------------------------------------------------
    // 📌 Step 1: Load database credentials
    // Site-specific credentials take priority over server-wide
    // ----------------------------------------------------------------
    if ($preflightProblems === [] && file_exists($siteCredsPath)) {
        require_once $siteCredsPath;
        $results[] = ['status' => 'success', 'message' => 'Loaded site-specific credentials from _auth_keys/auth_creds.php'];
    }

    // Load server-wide credentials (constants won't be redefined if already set)
    if ($preflightProblems === [] && file_exists($serverCredsPath)) {
        require_once $serverCredsPath;
        $results[] = ['status' => 'success', 'message' => 'Loaded server-wide credentials from _auth_keys/auth_creds.php'];
    }

    // ----------------------------------------------------------------
    // 📌 Step 2: Verify required constants are defined
    // ----------------------------------------------------------------
    $requiredConstants = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'];
    $missingConstants = [];

    foreach ($requiredConstants as $constant) {
        if (!defined($constant)) {
            $missingConstants[] = $constant;
        }
    }

    if ($preflightProblems !== []) {
        // 🚫 Already refused at Step 0 above; nothing more runs (this empty
        //    branch is what stops the `else` below from running the install).
    } elseif (!empty($missingConstants)) {
        $results[] = [
            'status' => 'error',
            'message' => 'Missing required database constants: ' . implode(', ', $missingConstants)
                . '. Please check your auth_creds.php file.'
        ];
    } else {
        $results[] = ['status' => 'success', 'message' => 'All required database constants are defined'];

        // ----------------------------------------------------------------
        // 📌 Step 3: Test database connection
        // @see https://www.php.net/manual/en/mysqli.construct.php
        // ----------------------------------------------------------------
        if (defined('DB_PORT')) {
            $dbPort = DB_PORT;
        } else {
            $dbPort = 3306;
        }
        if (defined('DB_CHARSET')) {
            $dbCharset = DB_CHARSET;
        } else {
            $dbCharset = 'utf8mb4';
        }

        // ----------------------------------------------------------------
        // 🧯 Last-resort safety net (independent review, Codex C1 finding 5,
        //    2026-11-25). Since PHP 8.1 MySQLi THROWS on a database error by
        //    default. Every database call below that is expected to fail now
        //    and then catches that itself; this handler only catches anything
        //    unexpected, so the visitor sees a plain message (never raw
        //    database text) and the full detail goes to the server log.
        //    Nothing is half-done by it: the lock file is the very last step,
        //    so an install interrupted here simply has not finished, and the
        //    installer's database lock is freed when the connection closes.
        // ----------------------------------------------------------------
        set_exception_handler(static function (\Throwable $unexpected): void {
            error_log('CueRCode Installer: stopped by an unexpected error: ' . get_class($unexpected) . ': ' . $unexpected->getMessage());
            if (!headers_sent()) {
                header('HTTP/1.1 500 Internal Server Error');
            }
            echo '<!DOCTYPE html><html lang="en-GB"><head><meta charset="UTF-8"><title>Installation stopped</title></head><body>'
                . '<h1>Installation stopped</h1>'
                . '<p>The installer stopped because of an unexpected database error. Nothing has been marked as installed. '
                . 'The details are in the server error log. Fix the problem there, then reload this page to try again.</p>'
                . '</body></html>';
        });

        // 🔌 Codex C1 finding 5: `@new \mysqli(...)` does not stop the
        //    exception PHP 8.1+ throws on a failed connection, so a wrong
        //    password crashed the page. installOpenDatabase() catches it.
        $connection = installOpenDatabase(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int) $dbPort);

        /** @var \mysqli|null Database connection (null when it failed) */
        $mysqli = $connection['db'];

        if ($connection['ok'] !== true) {
            // 🔒 Log detailed error internally but show generic message to user
            error_log('CueRCode Installer: DB connection failed: ' . $connection['error']);
            $results[] = [
                'status' => 'error',
                'message' => 'Database connection failed. Please verify your credentials in _auth_keys/auth_creds.php and ensure the database server is running.'
            ];
        } elseif (!($dbVersionCheck = installCheckDatabaseVersion((string) $mysqli->server_info))['ok']) {
            // 🗄️ Decision 0012: refuse a database older than MySQL 8.0.13 /
            // MariaDB 10.6 BEFORE running any SQL, so nothing is half-created.
            $results[] = [
                'status' => 'error',
                'message' => $dbVersionCheck['message']
            ];
        } else {
            // Set charset and SQL mode
            $mysqli->set_charset($dbCharset);
            $mysqli->query("SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

            $results[] = [
                'status' => 'success',
                'message' => 'Database connection established successfully ('
                    . DB_HOST . ':' . $dbPort . '/' . DB_NAME . ')'
            ];

            // 🧰 The upgrade engine: the installer's lock, the starting-data
            //    checks and loader, and the upgrades themselves (Step 5b).
            require_once $siteRoot . '_functions' . $ds . 'fn_migrations.php';

            // ----------------------------------------------------------------
            // 🔒 Step 3b: one installer at a time (independent review, Codex C1
            //    finding 2, 2026-11-25). The lock-file check at the top of the
            //    page happens before anything else, so two people pressing
            //    "Install" at the same moment could both get past it and both
            //    load the starting data. A database "named lock" (one per
            //    database) is now held from here until the lock file is
            //    written; a second installer is told to wait, and once it has
            //    the lock it checks the lock file AGAIN, in case the first one
            //    finished in the meantime.
            // ----------------------------------------------------------------
            $installerLockState = migrationDbAcquireInstallerLock($mysqli);

            if ($installerLockState === 'busy') {
                $results[] = [
                    'status' => 'error',
                    'message' => 'Another installation is running against this database at this moment, so this one has not '
                        . 'started and nothing was changed. Wait a minute, then reload this page.'
                ];
            } elseif ($installerLockState !== 'got') {
                error_log('CueRCode Installer: could not take the installer lock (GET_LOCK returned an error).');
                $results[] = [
                    'status' => 'error',
                    'message' => 'The installer could not make sure it is the only installation running, so it has not '
                        . 'started and nothing was changed. Check the server error log, then reload this page.'
                ];
            } elseif (installIsLocked($lockPaths)) {
                $results[] = [
                    'status' => 'info',
                    'message' => 'Installation has already been completed (it finished while this request was waiting). Nothing was changed.'
                ];
                $isLocked = true;
            } elseif (($upgradeFileCheck = migrationDbChangedAppliedUpgrades($mysqli, migrationDefaultDirectory()))['ok'] !== true) {
                // ----------------------------------------------------------------
                // 🛑 Step 3c: upgrade files changed since this database applied
                //    them (recheck finding 1 of the 7n Phase 2 review, decision
                //    0036). Asked BEFORE anything is changed. A database like
                //    that is not what a normal install gives, and before this
                //    check the installer only warned and then locked itself as
                //    "successful". The full detail goes to the error log only.
                // ----------------------------------------------------------------
                error_log('CueRCode Installer: could not check the applied database upgrades: ' . $upgradeFileCheck['error']);
                $results[] = [
                    'status' => 'error',
                    'message' => 'The installer could not check which database upgrades this database already has, so it '
                        . 'has not started and nothing was changed. Check the server error log, then reload this page.'
                ];
            } elseif ($upgradeFileCheck['changed'] !== []) {
                // 🛑 Stop: nothing changed, no lock file; the message says to
                //    start again with an empty database.
                $results[] = [
                    'status' => 'error',
                    'message' => migrationChangedUpgradesRefusal($upgradeFileCheck['changed'])
                ];
            } else {
                // ----------------------------------------------------------------
                // 📌 Step 4: Execute install.sql (table creation)
                // Run through migrationDbRunInstallSql() (the same function the
                // upgrade tool uses), which catches the exception PHP 8.1+ throws
                // on a database error (Codex C1 finding 5) and puts the
                // connection's settings back afterwards.
                // ----------------------------------------------------------------
                if (!file_exists($installSQLPath)) {
                    $results[] = [
                        'status' => 'error',
                        'message' => 'Installation SQL file not found: ' . $installSQLPath
                    ];
                } else {
                    $installSQL = file_get_contents($installSQLPath);

                    if ($installSQL === false) {
                        $results[] = [
                            'status' => 'error',
                            'message' => 'Failed to read installation SQL file'
                        ];
                    } else {
                        $installRun = migrationDbRunInstallSql($mysqli, $installSQL);

                        if ($installRun['ok'] === true) {
                            $results[] = [
                                'status' => 'success',
                                'message' => 'Database tables created successfully (install.sql)'
                            ];
                        } else {
                            error_log('CueRCode Installer: install.sql error: ' . $installRun['error']);
                            $results[] = [
                                'status' => 'error',
                                'message' => 'Error executing install.sql. Check server error log for details.'
                            ];
                        }
                    }
                }

                // ----------------------------------------------------------------
                // 📌 Step 5: Execute seed_data.sql (initial data)
                // ----------------------------------------------------------------
                // seed_data.sql is a set of plain INSERT statements, so it must
                // never run twice. Before touching it, ask how much starting data
                // is already here (migrationDbSeedDataState(), fn_migrations.php),
                // looking only at the tables ONLY seed_data.sql fills
                // (migrationSeedProtectionTables() - deliberately NOT tblSettings,
                // which migrations also insert into, review finding F1):
                //   - "complete": skip it and say so plainly (a present
                //     completion marker always means complete - decision 0018);
                //   - "none": no marker and nothing loaded: load it, ALL-OR-NOTHING (one transaction), so a
                //     failure leaves nothing behind and a retry starts clean;
                //   - "partial", or the check itself failed: STOP. Before the
                //     independent review (Codex C1 findings 1 and 4, 2026-11-25)
                //     any row at all meant "skip", and a failed check meant "no
                //     data", so a half-loaded database could be locked in as done.
                // A missing seed_data.sql is now an error too (Codex C1 finding
                // 3): the installer must not lock itself with no starting data.
                // ----------------------------------------------------------------
                $seedState = migrationDbSeedDataState($mysqli);

                if ($seedState['ok'] !== true) {
                    error_log('CueRCode Installer: could not check for existing starting data: ' . $seedState['error']);
                    $results[] = [
                        'status' => 'error',
                        'message' => 'The installer could not check whether the starting data is already loaded, so it did '
                            . 'not load it. Check the server error log for details.'
                    ];
                    $seedStepFailed = true;
                } elseif ($seedState['state'] === 'complete') {
                    // 📌 Decision 0018 (Codex round 3 finding 1): a present
                    //    completion marker means seeding already happened, even
                    //    if the three starting tables have since been emptied on
                    //    purpose. Say so, and say how to force a clean re-seed.
                    if ($seedState['marker'] === true) {
                        $results[] = [
                            'status' => 'info',
                            'message' => 'Seed data skipped: this database says the starting data was already loaded (the '
                                . 'seed_data_loaded setting is there), so it was not loaded again and any rows you have '
                                . 'removed or changed since are left alone. If you really want a clean re-seed of the '
                                . 'starting QR code types, logos and plans, delete the seed_data_loaded row from the '
                                . 'tblSettings table first (and empty tblQRCodeTypes, tblPredefinedLogos and '
                                . 'tblSubscriptionPlans), then reload this page. Any database changes since the first '
                                . 'install are applied in the next step instead, by the upgrade tool.'
                        ];
                    } else {
                        $results[] = [
                            'status' => 'info',
                            'message' => 'Seed data skipped: this database already holds all of the starting data. Running '
                                . 'seed_data.sql again would fail or duplicate rows, so it was not run. Any database changes '
                                . 'since the first install are applied in the next step instead, by the upgrade tool.'
                        ];
                    }
                } elseif ($seedState['state'] === 'partial') {
                    // 🔎 Codex round 2 (finding R1-1): "partial" now also covers
                    //    a table holding only SOME of its starting rows, and the
                    //    summary says exactly what is there. The advice says what
                    //    to do in both real situations, because emptying tables
                    //    on a site that is already in use would lose its data.
                    $results[] = [
                        'status' => 'error',
                        'message' => 'This database holds only PART of the starting data (' . $seedState['summary'] . '). '
                            . 'seed_data.sql cannot safely be run a second time, so it was not run, and the installer will '
                            . 'not mark itself as finished. If this is a NEW database, empty tblQRCodeTypes, '
                            . 'tblPredefinedLogos and tblSubscriptionPlans (or start again with an empty database), then '
                            . 'reload this page. If this site is ALREADY IN USE, do not empty anything: the installer has '
                            . 'run here before, so put its lock file (.installed.lock in _auth_keys/) back instead.'
                    ];
                    $seedStepFailed = true;
                } elseif (!file_exists($seedSQLPath)) {
                    $results[] = [
                        'status' => 'error',
                        'message' => 'Seed data SQL file not found: ' . $seedSQLPath
                            . ' (tables created but no starting data loaded, so the installer will not mark itself as finished)'
                    ];
                    $seedStepFailed = true;
                } else {
                    $seedSQL = file_get_contents($seedSQLPath);

                    if ($seedSQL === false) {
                        $results[] = [
                            'status' => 'error',
                            'message' => 'Failed to read seed data SQL file'
                        ];
                        // 🔒 F1: a seed_data.sql failure must stop the installer
                        //    before the lock file is ever written - see Step 6
                        //    below, where this flag is checked.
                        $seedStepFailed = true;
                    } else {
                        // 🔁 Review finding F1 (7n Phase 2 review, 2026-11-27):
                        //    the starting data AND the starting-data parts of any
                        //    upgrades that ran before it (migrate.php used first)
                        //    are loaded together, in one transaction, under the
                        //    upgrade lock - all of it, or none of it. Before, the
                        //    repeat ran afterwards on its own: when it failed, the
                        //    reload found the starting data "complete", skipped the
                        //    repeat and locked the installer as successful.
                        $seedRun = migrationDbRunSeedSql($mysqli, $seedSQL, migrationDefaultDirectory());

                        if ($seedRun['ok'] === true) {
                            $results[] = [
                                'status' => 'success',
                                'message' => 'Seed data loaded successfully (seed_data.sql)'
                            ];
                            if ($seedRun['stepsRun'] > 0) {
                                $results[] = [
                                    'status' => 'info',
                                    'message' => 'Database upgrades had already been applied before the starting data was loaded, so '
                                        . 'their starting-data parts were repeated with it (' . count($seedRun['migrations'])
                                        . ' upgrade(s), ' . $seedRun['stepsRun'] . ' step(s)). Nothing you had changed was overwritten.'
                                ];
                            }
                            if ($seedRun['skipped'] !== []) {
                                $results[] = [
                                    'status' => 'warning',
                                    'message' => 'Some upgrades recorded as applied were not repeated because their files are missing '
                                        . 'or have changed since they were applied: ' . implode(', ', $seedRun['skipped'])
                                ];
                            }
                        } elseif ($seedRun['refusal'] !== '') {
                            // 🧱 Codex round 2 (finding R1-2): refused BEFORE
                            //    anything was loaded (for example a table that is
                            //    not InnoDB, so the load could not be undone). The
                            //    refusal is plain English and names no secrets.
                            error_log('CueRCode Installer: ' . $seedRun['error']);
                            $results[] = [
                                'status' => 'error',
                                'message' => $seedRun['refusal']
                            ];
                            $seedStepFailed = true;
                        } else {
                            error_log('CueRCode Installer: ' . $seedRun['error']);
                            $results[] = [
                                'status' => 'error',
                                'message' => 'Error loading the starting data (seed_data.sql, with the starting-data parts of any '
                                    . 'database upgrades already applied). Nothing from it was kept, so the installer will NOT '
                                    . 'lock itself in and it is safe to try again: reload this page. Check server error log for details.'
                            ];
                            $seedStepFailed = true;
                        }
                    }
                }
                // ----------------------------------------------------------------
                // 📌 Step 5a: Put the connection back the way it was set up
                //    (review findings F2/F4)
                // ----------------------------------------------------------------
                // install.sql and seed_data.sql each contain their own
                // "SET CHARACTER SET utf8mb4" and the baseline's own SQL-mode
                // line (NO_AUTO_VALUE_ON_ZERO) - both of which this page's own
                // multi_query() calls above have just run directly, bypassing
                // migrationDbRunInstallSql()'s own save/restore (that function is
                // only used when the UPGRADE TOOL itself has to re-run
                // install.sql, in Step 5b below, for a table the tool finds
                // missing - not for this page's own first run of it). Without
                // putting the charset and strict mode back here, every migration
                // step applied in Step 5b would run in whatever state those two
                // files happened to leave the connection in - exactly the same
                // mismatch migrationDbRunInstallSql() guards against. Setting
                // these again is simply repeating exactly what this page set up
                // in Step 3 above.
                // ----------------------------------------------------------------
                $mysqli->set_charset($dbCharset);
                $mysqli->query("SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

                // (Step 5a2, the repeat of the starting-data parts of upgrades
                //  that ran before the starting data existed, is now part of the
                //  seed load itself in Step 5 above - review finding F1.)

                // ----------------------------------------------------------------
                // 📌 Step 5b: Apply database upgrades ("migrations")
                // ----------------------------------------------------------------
                // From task 7n Phase 1A onwards, install.sql and seed_data.sql are
                // a frozen starting point: every later database change - the #70
                // price-point/discount snapshot columns, the admin change log
                // table, the Phase 1A admin settings - lives in small numbered
                // files under _includes/sql/migrations/ and is applied by the same
                // engine the command-line tool (_includes/cli/migrate.php) uses.
                // $dryRun is false here so the upgrades are genuinely applied; every
                // step checks the live database first, so running this page again
                // later is exactly as safe as every other step above. (fn_migrations.php
                // was already loaded just after connecting, in Step 3.)
                // ----------------------------------------------------------------
                $migrationResult = migrationApplyPending($mysqli, 'installer', false);
                $migrationsOk    = ($migrationResult['status'] === 'ok');

                // 🔒 F3: the FULL technical detail (which can include raw
                //    MySQL/PHP error text) goes only to the server's error log,
                //    never to this page - the installer sits in public_html/ and
                //    can be reached by anyone until its lock file exists. The
                //    command-line tool (_includes/cli/migrate.php) is a trusted
                //    operator at a terminal, so IT keeps showing full detail
                //    (migrationFormatReport() called there with its default
                //    $includeErrorDetail of true).
                if (!$migrationsOk) {
                    error_log(
                        'CueRCode Installer: database upgrades did not finish - full detail: '
                        . implode(' | ', migrationFormatReport('apply', $migrationResult, true))
                    );
                }

                // 📝 Turn the plain-English report lines into the same kind of
                //    result rows the rest of this page already shows, so the
                //    screen keeps its existing look. migrationReportLineSeverity()
                //    is the one shared classifier (also used by the Phase 1B admin
                //    "Upgrades" page later), so the two screens never disagree on
                //    what counts as a warning or an error. $includeErrorDetail is
                //    false here (F3): this is the page a member of the public can
                //    reach, so only generic, plain-English lines are shown - the
                //    full detail just went to the error log above.
                foreach (migrationFormatReport('apply', $migrationResult, false) as $migrationLine) {
                    $results[] = [
                        'status'  => migrationReportLineSeverity($migrationLine),
                        'message' => $migrationLine
                    ];
                }

                if (!$migrationsOk) {
                    $results[] = [
                        'status' => 'error',
                        'message' => 'Database upgrades did not finish, so installation has stopped here. Fix the '
                            . 'problem shown above, then reload this page to try again - every upgrade step is safe '
                            . 'to repeat.'
                    ];
                }

                // 🚦 Everything from here on only runs once the upgrades are in a
                //    known-good state ("ok": up to date, or just applied).
                if ($migrationsOk) {
                    // ----------------------------------------------------------------
                    // 📌 Step 6: Verify table creation
                    // ----------------------------------------------------------------
                    $expectedTables = [
                        'tblSettings', 'tblErrorLog', 'tblActivityLog', 'tblQRCodeTypes',
                        'tblQRCodes', 'tblPredefinedLogos', 'tblAPIKeys', 'tblAPIRateLog',
                        'tblUsers', 'tblOrganisations',
                        // 🧱 Added by the database-upgrade tool in Step 5b above
                        // (task 7n Phase 1A): tblSchemaMigrations is the runner's
                        // own ledger, tblAdminAuditLog is created by migration 0002.
                        'tblSchemaMigrations', 'tblAdminAuditLog'
                    ];

                    $verifyResult = $mysqli->query("SHOW TABLES");
                    $existingTables = [];

                    if ($verifyResult) {
                        while ($row = $verifyResult->fetch_row()) {
                            $existingTables[] = $row[0];
                        }
                        $verifyResult->free();
                    }

                    $missingTables = array_diff($expectedTables, $existingTables);

                    if (empty($missingTables)) {
                        $results[] = [
                            'status' => 'success',
                            'message' => 'All ' . count($expectedTables) . ' core tables verified (database upgrades add the rest): '
                                . implode(', ', $expectedTables)
                        ];

                        // 🔒 F1: tables existing and the upgrade tool succeeding
                        //    is NOT enough on its own - if seed_data.sql itself
                        //    failed above, the starting data (QR code types,
                        //    predefined logos, subscription plans) never loaded,
                        //    so the installer must not lock itself in as "done".
                        if (!$seedStepFailed) {
                            $overallSuccess = true;
                        }

                        // 🔎 Independent review (Codex C1 finding 1, 2026-11-25):
                        //    check the starting data really is ALL there before
                        //    the lock file is written - every table only
                        //    seed_data.sql fills must have rows. This catches
                        //    anything the steps above did not report.
                        if ($overallSuccess) {
                            $finalSeedState = migrationDbSeedDataState($mysqli);

                            if ($finalSeedState['ok'] !== true) {
                                error_log('CueRCode Installer: final starting-data check failed: ' . $finalSeedState['error']);
                                $results[] = [
                                    'status' => 'error',
                                    'message' => 'The installer could not confirm that all the starting data is loaded, so it '
                                        . 'will NOT lock itself in. Check the server error log, then reload this page.'
                                ];
                                $overallSuccess = false;
                            } elseif ($finalSeedState['state'] !== 'complete') {
                                // 🔎 Codex round 2 (finding R1-1): say exactly what
                                //    is missing, from the per-row check.
                                if ($finalSeedState['summary'] !== '') {
                                    $missingWhat = $finalSeedState['summary'];
                                } else {
                                    $missingWhat = 'none of it is loaded';
                                }
                                $results[] = [
                                    'status' => 'error',
                                    'message' => 'Some starting data is missing (' . $missingWhat . '), so the installer will '
                                        . 'NOT lock itself in. Fix the problem shown above, then reload this page.'
                                ];
                                $overallSuccess = false;
                            }
                        }
                    } else {
                        $results[] = [
                            'status' => 'error',
                            'message' => 'Missing tables: ' . implode(', ', $missingTables)
                        ];
                    }

                    if ($seedStepFailed) {
                        $results[] = [
                            'status' => 'error',
                            'message' => 'Installation has stopped here: seed data failed to load (see above), so the '
                                . 'installer will NOT lock itself in. Fix the problem shown above, then reload this '
                                . 'page to try again.'
                        ];
                    }

                    // ----------------------------------------------------------------
                    // 📌 Step 7: Verify seed data counts
                    // ----------------------------------------------------------------
                    if ($overallSuccess) {
                        $countChecks = [
                            'tblSettings'       => 'Settings',
                            'tblQRCodeTypes'    => 'QR Code Types',
                            'tblPredefinedLogos' => 'Predefined Logos'
                        ];

                        foreach ($countChecks as $table => $label) {
                            $countResult = $mysqli->query("SELECT COUNT(*) as cnt FROM `{$table}`");
                            if ($countResult) {
                                $count = $countResult->fetch_assoc()['cnt'];
                                $countResult->free();
                                if ($count > 0) {
                                    $countStatus = 'success';
                                } else {
                                    $countStatus = 'warning';
                                }
                                $results[] = [
                                    'status' => $countStatus,
                                    'message' => "{$label}: {$count} records loaded"
                                ];
                            }
                        }
                    }

                    // ----------------------------------------------------------------
                    // 📌 Step 8: Create lock file to prevent re-execution
                    // ----------------------------------------------------------------
                    if ($overallSuccess) {
                        if (isset($_SERVER['SERVER_NAME'])) {
                            $serverName = $_SERVER['SERVER_NAME'];
                        } else {
                            $serverName = 'unknown';
                        }
                        $lockContent = 'Installed on: ' . date('Y-m-d H:i:s T') . PHP_EOL
                            . 'PHP Version: ' . PHP_VERSION . PHP_EOL
                            . 'Server: ' . $serverName . PHP_EOL;

                        // 🔒 Review fix M8: write the lock OUTSIDE install/ first
                        //    (_auth_keys/), so deleting or re-deploying install/
                        //    cannot lose it. If that folder is not writable, fall
                        //    back to the old place inside install/ and say so.
                        if (@file_put_contents($lockFile, $lockContent) !== false) {
                            $results[] = [
                                'status' => 'success',
                                'message' => 'Installation lock file created in _auth_keys/. Installer is now disabled.'
                            ];
                            $isLocked = true;
                        } elseif (@file_put_contents($legacyLockFile, $lockContent) !== false) {
                            $results[] = [
                                'status' => 'warning',
                                'message' => 'The lock file could only be written inside install/ (the _auth_keys folder is not writable). '
                                    . 'Keep the install folder and its .htaccess on the server: a later deploy that adds the folder again would not restore this lock.'
                            ];
                            $isLocked = true;
                        } else {
                            // 🛑 The folder check before the install said this
                            //    would work; something changed meanwhile. Say so
                            //    as an ERROR, not a warning: the installer is
                            //    still reachable until this is fixed.
                            $results[] = [
                                'status' => 'error',
                                'message' => 'The database is installed, but the installer could not write its lock file, so it is NOT switched off. '
                                    . 'Make the _auth_keys folder writable and create an empty file called _auth_keys/.installed.lock now. Keep the install folder and its .htaccess file in place: deleting the folder does not help, because the next deploy puts it back.'
                            ];
                        }
                    }
                }

                // 🔓 Free the installer lock (the connection closing below would
                //    free it anyway; done explicitly so the intent is clear).
                migrationDbReleaseInstallerLock($mysqli);
            }

            // Close database connection
            $mysqli->close();
        }
    }
}

// ==============================================================================
// 🔍 Prerequisite Checks (displayed on initial page load)
// ==============================================================================
$prerequisites = [];

// PHP Version
$phpVersion = PHP_VERSION;
$phpOk = version_compare($phpVersion, '8.4.0', '>=');
$prerequisites[] = [
    'name' => 'PHP Version',
    'required' => '>= 8.4.0 (8.4, 8.5 or 8.6)',
    'current' => $phpVersion,
    'ok' => $phpOk
];

// Required PHP Extensions (decision 0012 list; each row says what it is for)
foreach (installExtensionChecks(get_loaded_extensions()) as $extCheck) {
    if ($extCheck['ok']) {
        $extCurrentStatus = 'Loaded';
    } else {
        $extCurrentStatus = 'Not loaded - ' . $extCheck['message'];
    }
    $prerequisites[] = [
        'name' => 'PHP Extension: ' . $extCheck['name'],
        'required' => 'Loaded (' . $extCheck['purpose'] . ')',
        'current' => $extCurrentStatus,
        'ok' => $extCheck['ok']
    ];
}

// Storage folders PHP must be able to write to (#113 B7)
$storageFolders = [
    '_uploads' => 'user-uploaded logos and files',
    '_backups' => 'database and file backups',
];
foreach ($storageFolders as $folderName => $folderPurpose) {
    $folderCheck = installCheckWritableFolder($siteRoot . $folderName, $folderName, $folderPurpose);
    if ($folderCheck['ok']) {
        $folderCurrentStatus = 'Exists and writable';
    } else {
        $folderCurrentStatus = $folderCheck['message'];
    }
    $prerequisites[] = [
        'name' => 'Folder: ' . $folderName,
        'required' => 'Exists and writable',
        'current' => $folderCurrentStatus,
        'ok' => $folderCheck['ok']
    ];
}

// 🔒 Review finding 9: somewhere to write the "already installed" lock
$lockFolderCheck = installLockFolderCheck($lockPaths);
if ($lockFolderCheck['ok']) {
    $lockFolderStatus = 'Writable';
} else {
    $lockFolderStatus = $lockFolderCheck['message'];
}
$prerequisites[] = [
    'name' => 'Folder for the install lock (_auth_keys, or install as a fallback)',
    'required' => 'One of them writable',
    'current' => $lockFolderStatus,
    'ok' => $lockFolderCheck['ok']
];

// Credential files
if ($credsAvailable) {
    $credsCurrentStatus = 'Found';
} else {
    $credsCurrentStatus = 'Not found';
}
$prerequisites[] = [
    'name' => 'Credential File',
    'required' => 'auth_creds.php exists',
    'current' => $credsCurrentStatus,
    'ok' => $credsAvailable
];

// SQL files
$installSQLExists = file_exists($installSQLPath);
if ($installSQLExists) {
    $installSQLStatus = 'Found';
} else {
    $installSQLStatus = 'Not found';
}
$prerequisites[] = [
    'name' => 'Installation SQL',
    'required' => 'install.sql exists',
    'current' => $installSQLStatus,
    'ok' => $installSQLExists
];

$seedSQLExists = file_exists($seedSQLPath);
if ($seedSQLExists) {
    $seedSQLStatus = 'Found';
} else {
    $seedSQLStatus = 'Not found';
}
$prerequisites[] = [
    'name' => 'Seed Data SQL',
    'required' => 'seed_data.sql exists',
    'current' => $seedSQLStatus,
    'ok' => $seedSQLExists
];

// Check if all prerequisites pass
$allPrereqsMet = true;
foreach ($prerequisites as $prereq) {
    if (!$prereq['ok']) {
        $allPrereqsMet = false;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <!-- ====================================================================
         📄 Meta Tags
         ==================================================================== -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>CueRCode - Database Installer</title>

    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet"
          crossorigin="anonymous">

    <style>
        /* 🎨 Installer page styles */
        body {
            background-color: #f8f9fa;
            padding-top: 2rem;
            padding-bottom: 2rem;
        }
        .installer-container {
            max-width: 800px;
            margin: 0 auto;
        }
        .brand-header {
            background: linear-gradient(135deg, #2c7a7b, #4fd1c5);
            color: white;
            border-radius: 0.5rem 0.5rem 0 0;
            padding: 2rem;
            text-align: center;
        }
        .prereq-icon {
            font-size: 1.2rem;
            width: 1.5rem;
            text-align: center;
        }
    </style>
</head>
<body>
    <main class="installer-container">
        <!-- ================================================================
             🎯 Header
             ================================================================ -->
        <div class="brand-header">
            <h1 class="mb-1">CueRCode</h1>
            <p class="mb-0 opacity-75">Database Installation Wizard</p>
        </div>

        <div class="card border-0 shadow-sm" style="border-radius: 0 0 0.5rem 0.5rem;">
            <div class="card-body p-4">

                <?php if ($isLocked && !$installRequested) { ?>
                    <!-- ========================================================
                         🔒 Already Installed
                         ======================================================== -->
                    <div class="alert alert-info" role="alert">
                        <h5 class="alert-heading">Installation Complete</h5>
                        <p class="mb-0">
                            The database has already been installed. For security, this installer
                            is now locked. To re-run the installation, delete the lock file at
                            <code><?php echo htmlspecialchars($lockFile, ENT_QUOTES, 'UTF-8'); ?></code>
                            (or, on a site installed earlier, <code><?php echo htmlspecialchars($legacyLockFile, ENT_QUOTES, 'UTF-8'); ?></code>).
                        </p>
                    </div>
                    <div class="text-center mt-3">
                        <a href="/" class="btn btn-primary">Go to CueRCode</a>
                    </div>

                <?php } elseif ($installRequested) { ?>
                    <!-- ========================================================
                         📊 Installation Results
                         ======================================================== -->
                    <h4 class="mb-3">Installation Results</h4>

                    <?php foreach ($results as $result) { ?>
                        <?php
                        switch ($result['status']) {
                            case 'success':
                                $alertClass = 'alert-success';
                                break;
                            case 'warning':
                                $alertClass = 'alert-warning';
                                break;
                            case 'error':
                                $alertClass = 'alert-danger';
                                break;
                            default:
                                $alertClass = 'alert-secondary';
                                break;
                        }
                        switch ($result['status']) {
                            case 'success':
                                $icon = '&#10004;';
                                break;
                            case 'warning':
                                $icon = '&#9888;';
                                break;
                            case 'error':
                                $icon = '&#10008;';
                                break;
                            default:
                                $icon = '&#8226;';
                                break;
                        }
                        ?>
                        <div class="alert <?php echo $alertClass; ?> py-2" role="alert">
                            <span class="me-2" aria-hidden="true"><?php echo $icon; ?></span>
                            <?php echo htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php } ?>

                    <?php if ($overallSuccess) { ?>
                        <div class="alert alert-success mt-4" role="alert">
                            <h5 class="alert-heading">Installation Successful!</h5>
                            <p>
                                All database tables have been created and seed data loaded.
                                The installer is now locked for security.
                            </p>
                            <hr>
                            <p class="mb-0">
                                <strong>Next steps:</strong> Put the <code>.htaccess</code> file back in the
                                <code>/install/</code> folder (rename <code>.htaccess.off</code> to
                                <code>.htaccess</code>) and keep the folder, then visit your site.
                                Do not delete the folder: the next deploy puts it back.
                            </p>
                        </div>
                        <div class="text-center mt-3">
                            <a href="/" class="btn btn-primary btn-lg">Go to CueRCode</a>
                        </div>
                    <?php } else { ?>
                        <div class="alert alert-danger mt-4" role="alert">
                            <h5 class="alert-heading">Installation Had Errors</h5>
                            <p class="mb-0">
                                Please review the errors above, fix the issues, and try again.
                            </p>
                        </div>
                        <div class="text-center mt-3">
                            <a href="install" class="btn btn-warning">Try Again</a>
                        </div>
                    <?php } ?>

                <?php } else { ?>
                    <!-- ========================================================
                         🔍 Prerequisites Check
                         ======================================================== -->
                    <h4 class="mb-3">Prerequisites Check</h4>

                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th style="width: 2rem;"><span class="visually-hidden">Status</span></th>
                                <th>Requirement</th>
                                <th>Required</th>
                                <th>Current</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($prerequisites as $prereq) { ?>
                                <tr>
                                    <td class="prereq-icon">
                                        <?php
                                        if ($prereq['ok']) {
                                            echo '<span class="text-success" aria-hidden="true">&#10004;</span> <span class="visually-hidden">Pass</span>';
                                        } else {
                                            echo '<span class="text-danger" aria-hidden="true">&#10008;</span> <span class="visually-hidden">Fail</span>';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($prereq['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><code><?php echo htmlspecialchars($prereq['required'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                                    <td>
                                        <code class="<?php if ($prereq['ok']) { echo 'text-success'; } else { echo 'text-danger'; } ?>">
                                            <?php echo htmlspecialchars($prereq['current'], ENT_QUOTES, 'UTF-8'); ?>
                                        </code>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>

                    <?php if ($allPrereqsMet) { ?>
                        <div class="alert alert-success" role="alert">
                            All prerequisites met. Ready to install. The database version
                            (MySQL 8.0.13 or newer, or MariaDB 10.6 or newer) is checked when
                            you press the button, before anything is created.
                        </div>
                        <form method="POST" action="">
                            <input type="hidden" name="install_csrf_token" value="<?php echo htmlspecialchars($installCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="text-center">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    Install Database
                                </button>
                            </div>
                        </form>
                    <?php } else { ?>
                        <div class="alert alert-warning" role="alert">
                            <strong>Prerequisites not met.</strong> Please resolve the issues
                            marked with <span class="text-danger" aria-hidden="true">&#10008;</span><span class="visually-hidden">X</span> above before proceeding.
                        </div>
                    <?php } ?>

                <?php } ?>

            </div>

            <!-- ================================================================
                 📝 Footer
                 ================================================================ -->
            <div class="card-footer text-center text-muted small">
                CueRCode Installer v1.0.0 &middot; PHP <?php echo PHP_VERSION; ?>
                &middot; &copy; <?php echo date('Y'); ?> MWBM Partners Ltd
            </div>
        </div>
    </main>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
            crossorigin="anonymous"></script>
</body>
</html>
