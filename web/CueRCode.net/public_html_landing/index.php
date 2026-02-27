<?php
/**
 * ==============================================================================
 * 🚀 CueRCode - Coming Soon Landing Page
 * ==============================================================================
 *
 * Pre-launch landing page displayed while the service is under development.
 * This page is served from the public_html_landing web root.
 *
 * @package     CueRCode
 * @subpackage  Landing
 * @version     1.0.0
 * @since       Phase 1
 */

// ==============================================================================
// ⚙️ Page configuration
// ==============================================================================
$siteName    = 'CueRCode';
$tagline     = 'Professional QR Code Generator';
$description = 'Create beautiful, customisable QR codes for any purpose. '
    . 'URL links, vCards, Wi-Fi, payments, social media and more. Coming soon!';
$launchYear  = 2026;
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="auto">
<head>
    <!-- ====================================================================
         📄 Meta Tags
         @see https://developer.mozilla.org/en-US/docs/Web/HTML/Element/meta
         ==================================================================== -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="<?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#2c7a7b" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#1a4a4a" media="(prefers-color-scheme: dark)">

    <title><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?> - Coming Soon</title>

    <!-- ====================================================================
         🎨 Styles
         ==================================================================== -->
    <!-- Bootstrap 5.3 CSS via CDN -->
    <!-- @see https://getbootstrap.com/docs/5.3/getting-started/introduction/ -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YcnS/1Wtrhbf+PbQz4jN4dVFTJOPHcR1VVIwQ"
          crossorigin="anonymous">

    <style>
        /* ================================================================
         * 🎨 Coming Soon Page Styles
         * ================================================================ */

        /* 🌙 CSS Custom Properties for theming */
        :root {
            --cuercode-primary: #2c7a7b;
            --cuercode-primary-dark: #1a4a4a;
            --cuercode-gradient-start: #2c7a7b;
            --cuercode-gradient-end: #4fd1c5;
        }

        /* 📐 Full-viewport centered layout */
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--cuercode-gradient-start) 0%, var(--cuercode-gradient-end) 100%);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            color: #ffffff;
            text-align: center;
            padding: 2rem;
        }

        /* 🎯 Main content container */
        .coming-soon-container {
            max-width: 600px;
            animation: fadeInUp 1s ease-out;
        }

        /* ✨ Logo/brand area */
        .brand-icon {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            opacity: 0.9;
        }

        /* 📝 Site name */
        .site-name {
            font-size: 3rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            margin-bottom: 0.5rem;
        }

        /* 📝 Tagline */
        .tagline {
            font-size: 1.25rem;
            opacity: 0.85;
            margin-bottom: 2rem;
            font-weight: 300;
        }

        /* 📝 Description */
        .description {
            font-size: 1rem;
            opacity: 0.75;
            line-height: 1.6;
            margin-bottom: 2rem;
        }

        /* 🏷️ Badge */
        .status-badge {
            display: inline-block;
            padding: 0.5rem 1.5rem;
            border: 2px solid rgba(255, 255, 255, 0.4);
            border-radius: 50px;
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }

        /* 📝 Footer */
        .landing-footer {
            margin-top: 3rem;
            font-size: 0.8rem;
            opacity: 0.5;
        }

        /* 🎬 Fade-in animation */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* 📱 Responsive adjustments */
        @media (max-width: 576px) {
            .site-name {
                font-size: 2rem;
            }
            .tagline {
                font-size: 1rem;
            }
            .brand-icon {
                font-size: 3rem;
            }
        }

        /* 🌙 Dark mode adjustments */
        @media (prefers-color-scheme: dark) {
            body {
                background: linear-gradient(135deg, var(--cuercode-primary-dark) 0%, var(--cuercode-primary) 100%);
            }
        }
    </style>
</head>
<body>
    <!-- ====================================================================
         📄 Main Content
         ==================================================================== -->
    <main class="coming-soon-container" role="main">
        <!-- 🎯 Brand icon (placeholder - will be replaced with SVG logo) -->
        <div class="brand-icon" aria-hidden="true">
            &#x25A3;
        </div>

        <!-- 📝 Site name and tagline -->
        <h1 class="site-name"><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="tagline"><?php echo htmlspecialchars($tagline, ENT_QUOTES, 'UTF-8'); ?></p>

        <!-- 📝 Description -->
        <p class="description"><?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></p>

        <!-- 🏷️ Status badge -->
        <span class="status-badge">Coming Soon</span>

        <!-- 📝 Footer -->
        <footer class="landing-footer">
            <p>&copy; <?php echo $launchYear; ?> MWBM Partners Ltd. All rights reserved.</p>
        </footer>
    </main>

    <!-- ====================================================================
         📦 Scripts
         ==================================================================== -->
    <!-- Bootstrap 5.3 JS Bundle via CDN (includes Popper) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
            crossorigin="anonymous"></script>
</body>
</html>
