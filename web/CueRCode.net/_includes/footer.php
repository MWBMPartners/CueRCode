<?php
/**
 * ==============================================================================
 * 📄 CueRCode - Shared HTML Footer Template
 * ==============================================================================
 *
 * Outputs the page footer, JavaScript resources (jQuery, Bootstrap JS,
 * custom scripts), debug panel (if debug mode active), and closing HTML tags.
 *
 * Usage: Include this file at the end of any page that renders HTML.
 *   require_once CUERCODE_INCLUDES_DIR . 'footer.php';
 *
 * Expected variables (set before including):
 *   $pageJS (array) Additional JavaScript file paths to include
 *
 * @package     CueRCode
 * @subpackage  Templates
 * @version     1.0.0
 * @since       Phase 1
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
// ⚙️ Footer Variables
// ==============================================================================
if (function_exists('getConfig')) {
    $result = getConfig('site_name', 'CueRCode');
    if (isset($result)) {
        $siteName = $result;
    } else {
        $siteName = 'CueRCode';
    }
} else {
    $siteName = 'CueRCode';
}

if (function_exists('getConfig')) {
    $result = getConfig('copyright_holder', 'MWBM Partners Ltd');
    if (isset($result)) {
        $copyrightHolder = $result;
    } else {
        $copyrightHolder = 'MWBM Partners Ltd';
    }
} else {
    $copyrightHolder = 'MWBM Partners Ltd';
}

if (function_exists('getConfig')) {
    $result = getConfig('copyright_year', date('Y'));
    if (isset($result)) {
        $copyrightYear = $result;
    } else {
        $copyrightYear = date('Y');
    }
} else {
    $copyrightYear = date('Y');
}

if (isset($pageJS)) {
    // $pageJS already set
} else {
    $pageJS = [];
}

if (function_exists('isDebugMode')) {
    $isDebug = isDebugMode();
} else {
    $isDebug = false;
}
?>

    </main>
    <!-- ====================================================================
         📄 End of Main Content
         ==================================================================== -->

    <!-- ====================================================================
         🦶 Footer
         ==================================================================== -->
    <footer class="mt-5 py-4 border-top">
        <div class="container">
            <div class="row align-items-center">
                <!-- 📝 Copyright -->
                <div class="col-md-6 text-center text-md-start mb-2 mb-md-0">
                    <small class="text-body-secondary">
                        &copy; <?php echo htmlspecialchars($copyrightYear, ENT_QUOTES, 'UTF-8'); ?>
                        <?php echo htmlspecialchars($copyrightHolder, ENT_QUOTES, 'UTF-8'); ?>.
                        All rights reserved.
                    </small>
                </div>

                <!-- 🔗 Footer Links -->
                <div class="col-md-6 text-center text-md-end">
                    <small>
                        <a href="/terms" class="text-body-secondary text-decoration-none me-3">Terms of Use</a>
                        <a href="/privacy" class="text-body-secondary text-decoration-none me-3">Privacy Policy</a>
                        <a href="/api/docs" class="text-body-secondary text-decoration-none">API</a>
                    </small>
                </div>
            </div>
        </div>
    </footer>

    <!-- ====================================================================
         📦 JavaScript: jQuery (CDN with local fallback)
         @see https://jquery.com/
         ==================================================================== -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"
            crossorigin="anonymous"></script>
    <script>
        /* 🔄 jQuery CDN fallback to local copy */
        if (typeof jQuery === 'undefined') {
            document.write('<script src="/assets/vendor/jquery/jquery.min.js"><\/script>');
        }
    </script>

    <!-- ====================================================================
         📦 JavaScript: Bootstrap 5.3 Bundle (CDN with local fallback)
         @see https://getbootstrap.com/docs/5.3/getting-started/javascript/
         ==================================================================== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
            crossorigin="anonymous"></script>
    <script>
        /* 🔄 Bootstrap JS CDN fallback to local copy */
        if (typeof bootstrap === 'undefined') {
            document.write('<script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"><\/script>');
        }
    </script>

    <!-- ====================================================================
         📦 JavaScript: CueRCode Common Utilities
         ==================================================================== -->
    <script src="/assets/js/cuercode-common.js"></script>

    <!-- 📦 Additional page-specific JavaScript -->
    <?php foreach ($pageJS as $jsFile) { ?>
    <script src="<?php echo htmlspecialchars($jsFile, ENT_QUOTES, 'UTF-8'); ?>"></script>
    <?php } ?>

    <!-- ====================================================================
         🌙 Dark/Light Mode Toggle Script
         ==================================================================== -->
    <script>
        /* 🌙 Theme toggle functionality */
        (function() {
            var toggleBtn = document.getElementById('theme-toggle');
            if (toggleBtn) {
                toggleBtn.addEventListener('click', function() {
                    var currentTheme = document.documentElement.getAttribute('data-bs-theme');
                    var newTheme;
                    if (currentTheme === 'dark') {
                        newTheme = 'light';
                    } else {
                        newTheme = 'dark';
                    }
                    document.documentElement.setAttribute('data-bs-theme', newTheme);
                    localStorage.setItem('cuercode-theme', newTheme);

                    /* Update toggle icon */
                    var icon = this.querySelector('i');
                    if (icon) {
                        if (newTheme === 'dark') {
                            icon.className = 'fa-solid fa-sun';
                        } else {
                            icon.className = 'fa-solid fa-circle-half-stroke';
                        }
                    }
                });

                /* Set initial icon based on current theme */
                var currentTheme = document.documentElement.getAttribute('data-bs-theme');
                var icon = toggleBtn.querySelector('i');
                if (icon && currentTheme === 'dark') {
                    icon.className = 'fa-solid fa-sun';
                }
            }
        })();
    </script>

    <!-- ====================================================================
         📱 PWA Service Worker Registration
         @see https://developer.mozilla.org/en-US/docs/Web/API/ServiceWorkerContainer/register
         ==================================================================== -->
    <script>
        /* 📱 Register service worker for offline support and caching */
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('/sw.js').catch(function() {
                    /* ⚠️ Service worker registration failed - not critical */
                });
            });
        }
    </script>

    <?php if ($isDebug) { ?>
    <!-- ====================================================================
         🐛 Debug Panel (visible only in debug mode)
         ==================================================================== -->
    <div class="container mt-3 mb-3">
        <div class="card border-warning">
            <div class="card-header bg-warning text-dark">
                <strong><i class="fa-solid fa-bug me-1" aria-hidden="true"></i> Debug Mode Active</strong>
            </div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-sm-3">PHP Version</dt>
                    <dd class="col-sm-9"><code><?php echo PHP_VERSION; ?></code></dd>

                    <dt class="col-sm-3">Request Time</dt>
                    <dd class="col-sm-9"><code><?php echo date('Y-m-d H:i:s T'); ?></code></dd>

                    <dt class="col-sm-3">Execution Time</dt>
                    <dd class="col-sm-9">
                        <code><?php
                        if (defined('CUERCODE_REQUEST_START')) {
                            $startTime = CUERCODE_REQUEST_START;
                        } else {
                            $startTime = $_SERVER['REQUEST_TIME_FLOAT'];
                        }
                        echo number_format((microtime(true) - $startTime) * 1000, 2);
                        ?>ms</code>
                    </dd>

                    <dt class="col-sm-3">Memory Usage</dt>
                    <dd class="col-sm-9">
                        <code><?php echo number_format(memory_get_peak_usage(true) / 1024 / 1024, 2); ?>MB peak</code>
                    </dd>

                    <dt class="col-sm-3">Request URI</dt>
                    <dd class="col-sm-9"><code><?php
                    if (isset($_SERVER['REQUEST_URI'])) {
                        $requestUri = $_SERVER['REQUEST_URI'];
                    } else {
                        $requestUri = '';
                    }
                    echo htmlspecialchars($requestUri, ENT_QUOTES, 'UTF-8');
                    ?></code></dd>

                    <dt class="col-sm-3">Session ID</dt>
                    <dd class="col-sm-9"><code><?php
                    $sessionId = session_id();
                    if ($sessionId) {
                        // 🛡️ Mask all but the last 8 characters so the full
                        //    session token is never rendered, then HTML-escape
                        //    the result before output (SECURITY.md F-019).
                        if (strlen($sessionId) > 8) {
                            $maskedSessionId = str_repeat('•', strlen($sessionId) - 8)
                                . substr($sessionId, -8);
                        } else {
                            $maskedSessionId = $sessionId;
                        }
                        echo htmlspecialchars($maskedSessionId, ENT_QUOTES, 'UTF-8');
                    } else {
                        echo 'N/A';
                    }
                    ?></code></dd>
                </dl>
            </div>
        </div>
    </div>
    <?php } ?>

    <!-- ====================================================================
         🍪 Cookie / Storage Consent Notice (FG-026)
         A dismissible, non-modal banner (role="region") shown until the
         user acknowledges it. Gated on the 'cuercode-consent' localStorage
         key by cuercode-common.js (initCookieBanner). The banner starts
         hidden and is revealed by JS only when no acknowledgement exists,
         so users who have already dismissed it never see a flash. It does
         NOT trap focus and the rest of the page stays usable.
         @see https://www.w3.org/WAI/ARIA/apg/patterns/
         ==================================================================== -->
    <div id="cuercode-cookie-banner" role="region" aria-label="Cookie notice" hidden>
        <div class="container">
            <div class="cuercode-cookie-inner">
                <p class="cuercode-cookie-text">
                    <i class="fa-solid fa-cookie-bite me-1" aria-hidden="true"></i>
                    CueRCode uses essential browser storage only (your theme,
                    colourblind-palette and this notice preference). No
                    non-essential or tracking storage is set. See our
                    <a href="/privacy" class="text-cuercode-primary">Privacy Policy</a>
                    for details.
                </p>
                <div class="cuercode-cookie-actions">
                    <button type="button" class="btn btn-sm" id="cuercode-cookie-accept">
                        Got it
                    </button>
                    <button type="button" class="btn btn-sm btn-link text-body-secondary"
                            id="cuercode-cookie-close" aria-label="Dismiss cookie notice">
                        Dismiss
                    </button>
                </div>
            </div>
        </div>
    </div>

</body>
</html>
