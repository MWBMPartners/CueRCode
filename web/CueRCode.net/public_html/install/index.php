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

// 🔧 Pure readiness-check helpers (PHP extensions, database version, writable
// folders) - kept in their own file so they can be unit tested (#113 B2, B7).
require_once $siteRoot . '_functions' . $ds . 'fn_install_checks.php';

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

        /** @var \mysqli|false Database connection */
        $mysqli = @new \mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, $dbPort);

        if ($mysqli->connect_error) {
            // 🔒 Log detailed error internally but show generic message to user
            error_log('CueRCode Installer: DB connection failed: ' . $mysqli->connect_error
                . ' (Error ' . $mysqli->connect_errno . ')');
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

            // ----------------------------------------------------------------
            // 📌 Step 4: Execute install.sql (table creation)
            // @see https://www.php.net/manual/en/mysqli.multi-query.php
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
                    // Execute multi-query SQL
                    if ($mysqli->multi_query($installSQL)) {
                        // Process all result sets from multi_query
                        do {
                            if ($result = $mysqli->store_result()) {
                                $result->free();
                            }
                        } while ($mysqli->next_result());

                        if ($mysqli->errno) {
                            error_log('CueRCode Installer: install.sql error: ' . $mysqli->error . ' (' . $mysqli->errno . ')');
                            $results[] = [
                                'status' => 'error',
                                'message' => 'Error executing install.sql. Check server error log for details.'
                            ];
                        } else {
                            $results[] = [
                                'status' => 'success',
                                'message' => 'Database tables created successfully (install.sql)'
                            ];
                        }
                    } else {
                        error_log('CueRCode Installer: Failed to execute install.sql: ' . $mysqli->error);
                        $results[] = [
                            'status' => 'error',
                            'message' => 'Failed to execute install.sql. Check server error log for details.'
                        ];
                    }
                }
            }

            // ----------------------------------------------------------------
            // 📌 Step 5: Execute seed_data.sql (initial data)
            // ----------------------------------------------------------------
            // ⚠️ KNOWN LIMITATION (task 7n Phase 1A, #113 item 5): unlike
            // install.sql (every CREATE TABLE is "IF NOT EXISTS"), seed_data.sql
            // is a set of plain INSERT statements with no "insert if missing"
            // protection, so running it twice against the same database throws
            // duplicate primary/unique key errors - or, with multi_query(),
            // could stop part-way through. This page is meant to run once (the
            // lock file above normally stops a second visit), but an admin
            // could still reach this code a second time - for example by
            // deleting the lock file to re-run the installer after a later
            // code update. Before ever touching seed_data.sql, ask
            // migrationDbSeedDataAlreadyPresent() (_functions/fn_migrations.php)
            // whether any of the tables ONLY seed_data.sql ever fills already
            // has rows (migrationSeedProtectionTables() - deliberately NOT
            // tblSettings, which a migration file can also insert into, see
            // review finding F1); if it does, skip the seed step and say so
            // plainly - nothing is duplicated and nothing already there is
            // overwritten.
            // ----------------------------------------------------------------
            // Loaded here (rather than only before Step 5b below) because this
            // check needs it first; migrationApplyPending() further down reuses
            // the same require_once (require_once never loads a file twice).
            require_once $siteRoot . '_functions' . $ds . 'fn_migrations.php';

            $seedPresenceCheck      = migrationDbSeedDataAlreadyPresent($mysqli);
            $seedDataAlreadyPresent = ($seedPresenceCheck['ok'] === true && $seedPresenceCheck['present'] === true);

            if ($seedDataAlreadyPresent) {
                $results[] = [
                    'status' => 'info',
                    'message' => 'Seed data skipped: this database already holds starting data (tblQRCodeTypes, '
                        . 'tblPredefinedLogos or tblSubscriptionPlans already has rows). Running seed_data.sql '
                        . 'again would fail or duplicate rows, so it was not run. Any database changes since the '
                        . 'first install are applied in the next step instead, by the upgrade tool.'
                ];
            } elseif (!file_exists($seedSQLPath)) {
                $results[] = [
                    'status' => 'warning',
                    'message' => 'Seed data SQL file not found: ' . $seedSQLPath
                        . ' (tables created but no initial data loaded)'
                ];
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
                    if ($mysqli->multi_query($seedSQL)) {
                        do {
                            if ($result = $mysqli->store_result()) {
                                $result->free();
                            }
                        } while ($mysqli->next_result());

                        if ($mysqli->errno) {
                            error_log('CueRCode Installer: seed_data.sql error: ' . $mysqli->error . ' (' . $mysqli->errno . ')');
                            $results[] = [
                                'status' => 'error',
                                'message' => 'Error executing seed_data.sql. Check server error log for details.'
                            ];
                            $seedStepFailed = true;
                        } else {
                            $results[] = [
                                'status' => 'success',
                                'message' => 'Seed data loaded successfully (seed_data.sql)'
                            ];
                        }
                    } else {
                        error_log('CueRCode Installer: Failed to execute seed_data.sql: ' . $mysqli->error);
                        $results[] = [
                            'status' => 'error',
                            'message' => 'Failed to execute seed_data.sql. Check server error log for details.'
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
            // was already loaded just above, for the seed-presence check.)
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
                        'message' => 'All ' . count($expectedTables) . ' tables verified: '
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
                                . 'Delete the install folder from the server now (or make _auth_keys writable and create _auth_keys/.installed.lock).'
                        ];
                    }
                }
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
                                <strong>Next steps:</strong> Remove or restrict access to the
                                <code>/install/</code> directory, then visit your site.
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
