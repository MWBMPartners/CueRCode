<?php
/**
 * ==============================================================================
 * 📄 CueRCode - Shared HTML Footer Template
 * ==============================================================================
 *
 * Path: _includes/footer.php
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
 * @copyright   2025–2026 MWBM Partners Ltd
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

// ==============================================================================
// 📅 Copyright range (#137)
// ==============================================================================
// The stored copyright_year setting is a single fixed year (seeded "2026"),
// which goes stale every 1 January. Show "start–current" instead, using the
// stored year as the start when it is a sensible four-digit year (2025 is the
// company's first year, as in every file header), so it never needs editing.
// ==============================================================================
$copyrightStartYear = 2025;
if (preg_match('/^[0-9]{4}$/', (string) $copyrightYear) === 1 && (int) $copyrightYear < $copyrightStartYear) {
    $copyrightStartYear = (int) $copyrightYear;
}
$copyrightCurrentYear = (int) date('Y');
if ($copyrightCurrentYear > $copyrightStartYear) {
    $copyrightYear = $copyrightStartYear . '–' . $copyrightCurrentYear;
} else {
    $copyrightYear = (string) $copyrightStartYear;
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
    <?php /* ====================================================================
         📄 End of Main Content
         ==================================================================== */ ?>

    <?php /* ====================================================================
         🦶 Footer
         ==================================================================== */ ?>
    <footer class="mt-5 py-4 border-top">
        <div class="container">
            <div class="row align-items-center">
                <?php /* 📝 Copyright */ ?>
                <div class="col-md-6 text-center text-md-start mb-2 mb-md-0">
                    <small class="text-body-secondary">
                        &copy; <?php echo htmlspecialchars($copyrightYear, ENT_QUOTES, 'UTF-8'); ?>
                        <?php echo htmlspecialchars($copyrightHolder, ENT_QUOTES, 'UTF-8'); ?>.
                        All rights reserved.
                    </small>
                </div>

                <?php /* 🔗 Footer Links */ ?>
                <div class="col-md-6 text-center text-md-end">
                    <small>
                        <a href="/terms" class="text-body-secondary text-decoration-none me-3">Terms of Use</a>
                        <a href="/privacy" class="text-body-secondary text-decoration-none me-3">Privacy Policy</a>
                        <a href="/accessibility/" class="text-body-secondary text-decoration-none me-3">Accessible printing</a>
                        <a href="/attributions/" class="text-body-secondary text-decoration-none me-3">Attributions</a>
                        <a href="/report/" class="text-body-secondary text-decoration-none me-3">Report a QR code</a>
                        <a href="/developers/" class="text-body-secondary text-decoration-none me-3">Developers</a>
                        <a href="/api/docs/" class="text-body-secondary text-decoration-none me-3">API</a>
                        <a href="/pricing/" class="text-body-secondary text-decoration-none me-3">Pricing</a>
                        <a href="mailto:hello@cuercode.net" class="text-body-secondary text-decoration-none">Contact</a>
                    </small>
                </div>
            </div>
        </div>
    </footer>

    <?php /* ====================================================================
         📦 JavaScript: jQuery (CDN with local fallback)
         @see https://jquery.com/
         ==================================================================== */ ?>
    <?php /* 🔒 F-004 (fourth-round review, Low 2): the hash of the committed
         local copy assets/vendor/jquery/jquery.min.js, which is byte for byte
         the jsDelivr file (compared on 2026-11-28; its SHA-256 is the one
         jQuery publishes for 3.7.1). A changed file on the CDN is refused,
         and the fallback below then loads the local copy. */ ?>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"
            integrity="sha384-1H217gwSVyLSIfaLxHbE7dRb3v4mYCKbpQvzx0cegeju1MVsGrX5xXxAvs/HgeFs"
            crossorigin="anonymous"></script>
    <?php /* 🔄 jQuery CDN fallback to the local copy (a file, not inline: #154) */ ?>
    <script src="/assets/js/cuercode-fallback-jquery.js"></script>

    <?php /* ====================================================================
         📦 JavaScript: Bootstrap 5.3 Bundle (CDN with local fallback)
         @see https://getbootstrap.com/docs/5.3/getting-started/javascript/
         ==================================================================== */ ?>
    <?php /* 🔒 F-004 (SRI, DS-4): hash copied verbatim from
         public_html_landing/index.php (same pinned version, bootstrap@5.3.3) */ ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
            crossorigin="anonymous"></script>
    <?php /* 🔄 Bootstrap JS CDN fallback to the local copy */ ?>
    <script src="/assets/js/cuercode-fallback-bootstrap.js"></script>

    <?php /* ====================================================================
         📦 JavaScript: CueRCode Common Utilities
         ==================================================================== */ ?>
    <script src="/assets/js/cuercode-common.js"></script>

    <?php /* 📦 Additional page-specific JavaScript */ ?>
    <?php foreach ($pageJS as $jsFile) { ?>
    <script src="<?php echo htmlspecialchars($jsFile, ENT_QUOTES, 'UTF-8'); ?>"></script>
    <?php } ?>

    <?php /* ====================================================================
         🌙 Dark/light button and 📱 service worker registration
         ==================================================================== */ ?>
    <script src="/assets/js/cuercode-page.js"></script>

    <?php if ($isDebug) { ?>
    <?php /* ====================================================================
         🐛 Debug Panel (visible only in debug mode)
         ==================================================================== */ ?>
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

    <?php /* ====================================================================
         🍪 Cookie / Storage Consent Notice (FG-026)
         A dismissible, non-modal banner (role="region") shown until the
         user acknowledges it. Gated on the 'cuercode-consent' localStorage
         key by cuercode-common.js (initCookieBanner). The banner starts
         hidden and is revealed by JS only when no acknowledgement exists,
         so users who have already dismissed it never see a flash. It does
         NOT trap focus and the rest of the page stays usable.
         @see https://www.w3.org/WAI/ARIA/apg/patterns/
         ==================================================================== */ ?>
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
                    <?php /* ✅ One button only (#137): the notice is information,
                             not a choice - "Got it" and a second "Dismiss" button
                             both did exactly the same thing. */ ?>
                    <button type="button" class="btn btn-sm" id="cuercode-cookie-accept">
                        Got it
                    </button>
                </div>
            </div>
        </div>
    </div>

</body>
</html>
