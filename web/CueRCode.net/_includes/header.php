<?php
/**
 * ==============================================================================
 * 📄 CueRCode - Shared HTML Header Template
 * ==============================================================================
 *
 * Path: _includes/header.php
 *
 * Outputs the HTML document head, meta tags, CSS resources (Bootstrap,
 * FontAwesome, custom CSS), and the responsive navigation bar.
 *
 * Usage: Include this file after bootstrap.php in any page that renders HTML.
 *   require_once CUERCODE_INCLUDES_DIR . 'header.php';
 *
 * Expected variables (set before including):
 *   $pageTitle       (string) Page-specific title (appended to site name)
 *   $pageDescription (string) Meta description for this page
 *   $bodyClass       (string) Additional CSS classes for <body> tag
 *   $pageCSS         (array)  Additional CSS file paths to include
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
// ⚙️ Page Variables (with defaults)
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
    $result = getConfig('site_tagline', 'Professional QR Code Generator');
    if (isset($result)) {
        $siteTagline = $result;
    } else {
        $siteTagline = 'Professional QR Code Generator';
    }
} else {
    $siteTagline = 'Professional QR Code Generator';
}

if (function_exists('getConfig')) {
    $result = getConfig('site_url', 'https://cuercode.net');
    if (isset($result)) {
        $siteURL = $result;
    } else {
        $siteURL = 'https://cuercode.net';
    }
} else {
    $siteURL = 'https://cuercode.net';
}

if (function_exists('getConfig')) {
    $result = getConfig('primary_color', '#2c7a7b');
    if (isset($result)) {
        $primaryColor = $result;
    } else {
        $primaryColor = '#2c7a7b';
    }
} else {
    $primaryColor = '#2c7a7b';
}

if (isset($pageTitle)) {
    $pageTitle = $pageTitle . ' | ' . $siteName;
} else {
    $pageTitle = $siteName . ' - ' . $siteTagline;
}

if (isset($pageDescription)) {
    // $pageDescription already set
} else {
    if (function_exists('getConfig')) {
        $result = getConfig('site_description', '');
        if (isset($result)) {
            $pageDescription = $result;
        } else {
            $pageDescription = '';
        }
    } else {
        $pageDescription = '';
    }
}

if (isset($bodyClass)) {
    // $bodyClass already set
} else {
    $bodyClass = '';
}

if (isset($pageCSS)) {
    // $pageCSS already set
} else {
    $pageCSS = [];
}

// 🔤 CSRF meta tag for AJAX requests
if (function_exists('csrfMeta')) {
    $csrfMeta = csrfMeta();
} else {
    $csrfMeta = '';
}

// 🐛 Debug mode check
if (function_exists('isDebugMode')) {
    $isDebug = isDebugMode();
} else {
    $isDebug = false;
}

// ==============================================================================
// 👤 Logged-in user indicator (ProjectBrief §11.4, Epic E3 stage 2b, #52/#14)
// ==============================================================================
// currentUser() is now loaded globally by bootstrap.php (fn_auth.php) for
// every page - not just the JSON API - specifically so this navbar can show
// the correct state on every request without each page remembering its own
// require_once boilerplate.
// ==============================================================================
if (function_exists('currentUser')) {
    $headerCurrentUser = currentUser();
} else {
    $headerCurrentUser = null;
}

if ($headerCurrentUser !== null) {
    $headerUserEmail = $headerCurrentUser['email'];
    if (!empty($headerCurrentUser['displayName'])) {
        $headerUserDisplayName = $headerCurrentUser['displayName'];
    } else {
        $headerUserDisplayName = $headerUserEmail;
    }
    if (function_exists('resolveAvatarUrl')) {
        $headerAvatarUrl = resolveAvatarUrl($headerCurrentUser['avatarPath'] ?? null, $headerUserEmail, 40);
    } else {
        $headerAvatarUrl = '/assets/img/branding/avatar-default.svg';
    }
} else {
    $headerUserEmail       = '';
    $headerUserDisplayName = '';
    $headerAvatarUrl       = '/assets/img/branding/avatar-default.svg';
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="auto">
<head>
    <!-- ====================================================================
         📄 Character Encoding and Viewport
         @see https://developer.mozilla.org/en-US/docs/Web/HTML/Element/meta
         ==================================================================== -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">

    <!-- ====================================================================
         📝 Page Title and Description
         ==================================================================== -->
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">

    <!-- ====================================================================
         🎨 Theme Colour (status bar colour on mobile)
         @see https://developer.mozilla.org/en-US/docs/Web/HTML/Element/meta/name/theme-color
         ==================================================================== -->
    <meta name="theme-color" content="<?php echo htmlspecialchars($primaryColor, ENT_QUOTES, 'UTF-8'); ?>" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#1a4a4a" media="(prefers-color-scheme: dark)">

    <!-- ====================================================================
         🔗 Canonical URL and Open Graph
         @see https://ogp.me/
         ==================================================================== -->
    <link rel="canonical" href="<?php
    if (isset($_SERVER['REQUEST_URI'])) {
        $requestUri = $_SERVER['REQUEST_URI'];
    } else {
        $requestUri = '/';
    }
    echo htmlspecialchars($siteURL . $requestUri, ENT_QUOTES, 'UTF-8');
    ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo htmlspecialchars($siteURL, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars($siteURL, ENT_QUOTES, 'UTF-8'); ?>/assets/img/branding/og-image.png">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">

    <!-- ====================================================================
         🖼️ Favicon and Icons
         @see https://developer.mozilla.org/en-US/docs/Web/HTML/Attributes/rel#icon
         ==================================================================== -->
    <link rel="icon" href="/assets/img/branding/favicon.ico" sizes="any">
    <link rel="icon" href="/assets/img/branding/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/assets/img/branding/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="icon" href="/assets/img/branding/favicon-16.png" type="image/png" sizes="16x16">
    <link rel="apple-touch-icon" href="/assets/img/branding/apple-touch-icon.png">
    <link rel="manifest" href="/manifest.json">

    <!-- ====================================================================
         🛡️ CSRF Token (for AJAX requests)
         ==================================================================== -->
    <?php echo $csrfMeta . PHP_EOL; ?>

    <!-- ====================================================================
         🎨 CSS: Bootstrap 5.3 (CDN with local fallback)
         @see https://getbootstrap.com/docs/5.3/getting-started/introduction/
         ==================================================================== -->
    <!-- 🔒 F-004 (SRI, DS-4): hash copied verbatim from
         public_html_landing/index.php (same pinned version, bootstrap@5.3.3) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YcnS/1Wtrhbf+PbQz4jN4dVFTJOPHcR1VVIwQ"
          crossorigin="anonymous"
          id="bootstrap-css">
    <script>
        /* 🔄 Bootstrap CSS CDN fallback to local copy */
        (function() {
            var testEl = document.createElement('div');
            testEl.className = 'visually-hidden';
            if (document.body) {
                document.body.appendChild(testEl);
            } else {
                document.addEventListener('DOMContentLoaded', function() {
                    document.body.appendChild(testEl);
                });
            }
            setTimeout(function() {
                var style;
                if (window.getComputedStyle) {
                    style = window.getComputedStyle(testEl);
                } else {
                    style = testEl.currentStyle;
                }
                if (!style || style.position !== 'absolute') {
                    var link = document.createElement('link');
                    link.rel = 'stylesheet';
                    link.href = '/assets/vendor/bootstrap/css/bootstrap.min.css';
                    document.head.appendChild(link);
                }
                if (testEl.parentNode) { testEl.parentNode.removeChild(testEl); }
            }, 3000);
        })();
    </script>

    <!-- ====================================================================
         🎨 CSS: FontAwesome (CDN with local fallback)
         @see https://fontawesome.com/docs
         ==================================================================== -->
    <!-- TODO F-004: capture SRI hash for font-awesome@6.5.1 at live deploy —
         public_html_landing/index.php does not reference FontAwesome, so no
         existing verified hash can be safely copied for this version. Do NOT
         guess a hash: an incorrect integrity value BLOCKS the resource. -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
          rel="stylesheet"
          crossorigin="anonymous"
          id="fontawesome-css">

    <!-- ====================================================================
         🎨 CSS: Custom CueRCode Styles
         ==================================================================== -->
    <link href="/assets/css/cuercode.css" rel="stylesheet">

    <!-- 🎨 Additional page-specific CSS -->
    <?php foreach ($pageCSS as $cssFile) { ?>
    <link href="<?php echo htmlspecialchars($cssFile, ENT_QUOTES, 'UTF-8'); ?>" rel="stylesheet">
    <?php } ?>

    <!-- ====================================================================
         🎨 CSS: Dark/Light Mode Auto-Detection
         @see https://developer.mozilla.org/en-US/docs/Web/CSS/@media/prefers-color-scheme
         ==================================================================== -->
    <script>
        /* 🌙 Auto-detect and apply colour scheme preference */
        (function() {
            var theme = localStorage.getItem('cuercode-theme');
            if (theme === 'dark' || theme === 'light') {
                document.documentElement.setAttribute('data-bs-theme', theme);
            } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                document.documentElement.setAttribute('data-bs-theme', 'dark');
            } else {
                document.documentElement.setAttribute('data-bs-theme', 'light');
            }
        })();
    </script>

    <!-- ====================================================================
         ♿ Colourblind (CVD) Palette - apply persisted choice before paint
         Mirrors the theme block above so the chosen colourblind-safe palette
         is applied to <html> before first paint, avoiding a flash of the
         default brand colours. The data-cuercode-cvd attribute is consumed
         by the [data-cuercode-cvd="..."] rules in cuercode.css. (FG-023)
         @see https://developer.mozilla.org/en-US/docs/Web/CSS/Attribute_selectors
         ==================================================================== -->
    <script>
        /* ♿ Apply saved colourblind palette preference pre-paint */
        (function() {
            var cvd = localStorage.getItem('cuercode-cvd');
            var valid = ['protanopia', 'deuteranopia', 'tritanopia'];
            if (valid.indexOf(cvd) !== -1) {
                document.documentElement.setAttribute('data-cuercode-cvd', cvd);
            }
            /* 'none' or absent leaves the attribute off => default brand palette */
        })();
    </script>
</head>
<body class="<?php echo htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8'); ?>">
    <a href="#main-content" class="visually-hidden-focusable">Skip to main content</a>

    <!-- ====================================================================
         🧭 Navigation Bar
         @see https://getbootstrap.com/docs/5.3/components/navbar/
         ==================================================================== -->
    <nav class="navbar navbar-expand-lg sticky-top" data-bs-theme="dark"
         style="background: linear-gradient(135deg, #2c7a7b, #38a89d);">
        <div class="container">
            <!-- 🏠 Brand / Logo -->
            <a class="navbar-brand fw-bold" href="/">
                <span class="me-1">&#x25A3;</span>
                <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>
            </a>

            <!-- 📱 Mobile toggle button -->
            <button class="navbar-toggler" type="button"
                    data-bs-toggle="collapse" data-bs-target="#navbarMain"
                    aria-controls="navbarMain" aria-expanded="false"
                    aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <!-- 📋 Navigation Links -->
            <div class="collapse navbar-collapse" id="navbarMain">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link" href="/">
                            <i class="fa-solid fa-qrcode me-1" aria-hidden="true"></i> Generator
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/developers/">
                            <i class="fa-solid fa-code me-1" aria-hidden="true"></i> Developers
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/api/docs">
                            <i class="fa-solid fa-book me-1" aria-hidden="true"></i> API Docs
                        </a>
                    </li>
                </ul>

                <!-- 🔧 Right-side items -->
                <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                    <!-- ====================================================
                         ♿ Colourblind-friendly palette picker (FG-023)
                         A Bootstrap dropdown of menuitemradio options.
                         JS (cuercode-common.js) persists the choice in
                         localStorage and toggles the data-cuercode-cvd
                         attribute on <html>; the pre-paint block in <head>
                         re-applies it on load. Keyboard operable via the
                         native <button>s + Bootstrap's dropdown arrow keys.
                         @see https://getbootstrap.com/docs/5.3/components/dropdowns/
                         ==================================================== -->
                    <li class="nav-item dropdown">
                        <button class="btn btn-link nav-link dropdown-toggle" id="cvd-toggle"
                                type="button" data-bs-toggle="dropdown" aria-expanded="false"
                                title="Colourblind-friendly palette"
                                aria-label="Choose colourblind-friendly palette">
                            <i class="fa-solid fa-eye-low-vision" aria-hidden="true"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" role="menu"
                            aria-label="Colourblind-friendly palette">
                            <li role="presentation">
                                <button class="dropdown-item cvd-option" type="button"
                                        role="menuitemradio" aria-checked="true"
                                        data-cvd="none">
                                    <i class="fa-solid fa-check cvd-check me-2" aria-hidden="true"></i>None (default)
                                </button>
                            </li>
                            <li role="presentation">
                                <button class="dropdown-item cvd-option" type="button"
                                        role="menuitemradio" aria-checked="false"
                                        data-cvd="protanopia">
                                    <i class="fa-solid fa-check cvd-check me-2" aria-hidden="true"></i>Protanopia (red-blind)
                                </button>
                            </li>
                            <li role="presentation">
                                <button class="dropdown-item cvd-option" type="button"
                                        role="menuitemradio" aria-checked="false"
                                        data-cvd="deuteranopia">
                                    <i class="fa-solid fa-check cvd-check me-2" aria-hidden="true"></i>Deuteranopia (green-blind)
                                </button>
                            </li>
                            <li role="presentation">
                                <button class="dropdown-item cvd-option" type="button"
                                        role="menuitemradio" aria-checked="false"
                                        data-cvd="tritanopia">
                                    <i class="fa-solid fa-check cvd-check me-2" aria-hidden="true"></i>Tritanopia (blue-blind)
                                </button>
                            </li>
                        </ul>
                    </li>

                    <!-- 🌙 Dark/Light Mode Toggle -->
                    <li class="nav-item">
                        <button class="btn btn-link nav-link" id="theme-toggle"
                                title="Toggle dark/light mode" aria-label="Toggle dark/light mode">
                            <i class="fa-solid fa-circle-half-stroke" aria-hidden="true"></i>
                        </button>
                    </li>

                    <!-- ====================================================
                         👤 Logged-in user indicator (ProjectBrief §11.4,
                         Epic E3 stage 2b, #52/#14/#23) - avatar + name +
                         dropdown when authenticated; Sign in / Sign up links
                         when anonymous. Mirrors the CVD picker's Bootstrap
                         dropdown markup above for consistent keyboard/aria
                         behaviour (native <button> + data-bs-toggle, arrow-key
                         navigation handled by Bootstrap's bundled JS).
                         @see https://getbootstrap.com/docs/5.3/components/dropdowns/
                         ==================================================== -->
                    <?php if ($headerCurrentUser !== null) { ?>
                    <li class="nav-item dropdown">
                        <button class="btn btn-link nav-link dropdown-toggle d-flex align-items-center gap-2"
                                id="account-nav-toggle" type="button" data-bs-toggle="dropdown"
                                aria-expanded="false" aria-label="Account menu for <?php echo htmlspecialchars($headerUserDisplayName, ENT_QUOTES, 'UTF-8'); ?>">
                            <img src="<?php echo htmlspecialchars($headerAvatarUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                 alt="" width="28" height="28" class="rounded-circle"
                                 onerror="this.onerror=null;this.src='/assets/img/branding/avatar-default.svg';">
                            <span class="d-none d-md-inline"><?php echo htmlspecialchars($headerUserDisplayName, ENT_QUOTES, 'UTF-8'); ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" role="menu" aria-label="Account menu">
                            <li role="presentation">
                                <a class="dropdown-item" role="menuitem" href="/account/">
                                    <i class="fa-solid fa-user me-2" aria-hidden="true"></i>Account
                                </a>
                            </li>
                            <li role="presentation">
                                <a class="dropdown-item" role="menuitem" href="/account/#library-section">
                                    <i class="fa-solid fa-layer-group me-2" aria-hidden="true"></i>Saved codes
                                </a>
                            </li>
                            <li role="presentation"><hr class="dropdown-divider"></li>
                            <li role="presentation">
                                <form method="POST" action="/account/" class="px-3">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="logout_submit" value="1">
                                    <button type="submit" class="dropdown-item ps-0" role="menuitem">
                                        <i class="fa-solid fa-right-from-bracket me-2" aria-hidden="true"></i>Sign out
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </li>
                    <?php } else { ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/login/">
                            <i class="fa-solid fa-right-to-bracket me-1" aria-hidden="true"></i> Sign in
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/register/">
                            <i class="fa-solid fa-user-plus me-1" aria-hidden="true"></i> Sign up
                        </a>
                    </li>
                    <?php } ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- ====================================================================
         📄 Main Content Container
         ==================================================================== -->
    <main id="main-content">
