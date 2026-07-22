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
 * @version     1.0.0
 * @since       Phase 1
 * @see         https://www.php.net/manual/en/book.mysqli.php
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

/** @var string Lock file path - prevents re-running the installer */
$lockFile = $installDir . '.installed.lock';

/** @var string Path to SQL installation script */
$installSQLPath = $siteRoot . '_includes' . $ds . 'sql' . $ds . 'install.sql';

/** @var string Path to SQL seed data script */
$seedSQLPath = $siteRoot . '_includes' . $ds . 'sql' . $ds . 'seed_data.sql';

// ==============================================================================
// 🔒 Lock File Check - Prevent Re-Execution
// ==============================================================================
$isLocked = file_exists($lockFile);

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
    // 📌 Step 1: Load database credentials
    // Site-specific credentials take priority over server-wide
    // ----------------------------------------------------------------
    if (file_exists($siteCredsPath)) {
        require_once $siteCredsPath;
        $results[] = ['status' => 'success', 'message' => 'Loaded site-specific credentials from _auth_keys/auth_creds.php'];
    }

    // Load server-wide credentials (constants won't be redefined if already set)
    if (file_exists($serverCredsPath)) {
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

    if (!empty($missingConstants)) {
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
            if (!file_exists($seedSQLPath)) {
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
                    }
                }
            }

            // ----------------------------------------------------------------
            // 📌 Step 6: Verify table creation
            // ----------------------------------------------------------------
            $expectedTables = [
                'tblSettings', 'tblErrorLog', 'tblActivityLog', 'tblQRCodeTypes',
                'tblQRCodes', 'tblPredefinedLogos', 'tblAPIKeys', 'tblAPIRateLog',
                'tblUsers', 'tblOrganisations'
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
                $overallSuccess = true;
            } else {
                $results[] = [
                    'status' => 'error',
                    'message' => 'Missing tables: ' . implode(', ', $missingTables)
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

                if (file_put_contents($lockFile, $lockContent) !== false) {
                    $results[] = [
                        'status' => 'success',
                        'message' => 'Installation lock file created. Installer is now disabled.'
                    ];
                    $isLocked = true;
                } else {
                    $results[] = [
                        'status' => 'warning',
                        'message' => 'Could not create lock file. Please manually restrict access to /install/'
                    ];
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
    'required' => '>= 8.4.0',
    'current' => $phpVersion,
    'ok' => $phpOk
];

// Required PHP Extensions
$requiredExtensions = ['mysqli', 'mbstring', 'gd', 'json', 'openssl'];
foreach ($requiredExtensions as $ext) {
    $extLoaded = extension_loaded($ext);
    if ($extLoaded) {
        $extCurrentStatus = 'Loaded';
    } else {
        $extCurrentStatus = 'Not loaded';
    }
    $prerequisites[] = [
        'name' => "PHP Extension: {$ext}",
        'required' => 'Loaded',
        'current' => $extCurrentStatus,
        'ok' => $extLoaded
    ];
}

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
                            is now locked. To re-run the installation, delete the lock file at:
                            <code><?php echo htmlspecialchars($lockFile, ENT_QUOTES, 'UTF-8'); ?></code>
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
                            All prerequisites met. Ready to install.
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
