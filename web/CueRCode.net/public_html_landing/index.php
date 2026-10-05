<?php
/**
 * ==============================================================================
 * 🚀 CueRCode - Coming Soon Landing Page
 * ==============================================================================
 *
 * Path: public_html_landing/index.php
 *
 * Pre-launch landing page displayed while the service is under development.
 * This page is served from the public_html_landing web root.
 *
 * @package     CueRCode
 * @subpackage  Landing
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 1
 */

// ==============================================================================
// ⚙️ Page configuration
// ==============================================================================
$siteName    = 'CueRCode';
$tagline     = 'Advanced QR Code Generator';
$description = 'Free QR codes and barcodes with your own logo, colours and frames, '
    . 'plus print-ready accessible and tactile versions. Launching soon.';
// 📅 Copyright range "2025–<current year>" so it never goes stale (#137)
$copyrightStartYear = 2025;
$copyrightNowYear   = (int) date('Y');
if ($copyrightNowYear > $copyrightStartYear) {
    $copyrightRange = $copyrightStartYear . '–' . $copyrightNowYear;
} else {
    $copyrightRange = (string) $copyrightStartYear;
}
?>
<!DOCTYPE html>
<html lang="en-GB" data-bs-theme="auto">
<head>
    <?php /* ====================================================================
         📄 Meta Tags
         @see https://developer.mozilla.org/en-US/docs/Web/HTML/Element/meta
         ==================================================================== */ ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="<?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#2c7a7b" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#1a4a4a" media="(prefers-color-scheme: dark)">

    <title><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?> | Coming soon</title>

    <?php /* 🖼️ Icons and share preview (#137) - files live next to this page
             because the landing web root has no assets folder. */ ?>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <meta property="og:site_name" content="<?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($siteName . ' - ' . $tagline, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://cuercode.net/">
    <meta property="og:image" content="https://cuercode.net/og-image.png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:locale" content="en_GB">
    <meta name="twitter:card" content="summary_large_image">

    <?php /* ====================================================================
         🎨 Styles
         ==================================================================== */ ?>
    <?php /* Bootstrap 5.3 CSS via CDN */ ?>
    <?php /* @see https://getbootstrap.com/docs/5.3/getting-started/introduction/ */ ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">

    <style>
        <?php /* ================================================================
         * 🎨 Coming Soon Page Styles
         * ================================================================ */ ?>

        <?php /* 🌙 CSS Custom Properties for theming */ ?>
        :root {
            --cuercode-primary: #2c7a7b;
            --cuercode-primary-dark: #1a4a4a;
            --cuercode-gradient-start: #2c7a7b;
            --cuercode-gradient-end: #4fd1c5;
        }

        <?php /* 📐 Full-viewport centered layout */ ?>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            <?php /* ♿ Dark-to-standard teal so white text keeps >= 4.5:1 (#137); the old
               light end (#4fd1c5) left the faded text at well under 2:1. */ ?>
            background: linear-gradient(135deg, var(--cuercode-primary-dark) 0%, var(--cuercode-primary) 100%);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            color: #ffffff;
            text-align: center;
            padding: 2rem;
        }

        <?php /* 🎯 Main content container */ ?>
        .coming-soon-container {
            max-width: 600px;
            animation: fadeInUp 1s ease-out;
        }

        <?php /* ✨ Logo/brand area */ ?>
        .brand-icon {
            display: inline-block;
            width: 96px;
            height: 96px;
            margin-bottom: 1.5rem;
            padding: 10px;
            background-color: #ffffff;
            border-radius: 20px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
        }

        <?php /* 📝 Site name */ ?>
        .site-name {
            font-size: 3rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            margin-bottom: 0.5rem;
        }

        <?php /* 📝 Tagline */ ?>
        .tagline {
            font-size: 1.25rem;
            opacity: 0.95;
            margin-bottom: 2rem;
            font-weight: 300;
        }

        <?php /* 📝 Description */ ?>
        .description {
            font-size: 1rem;
            opacity: 0.92;
            line-height: 1.6;
            margin-bottom: 2rem;
        }

        <?php /* 🏷️ Badge */ ?>
        .status-badge {
            display: inline-block;
            padding: 0.5rem 1.5rem;
            border: 2px solid rgba(255, 255, 255, 0.6);
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }

        <?php /* 📝 Footer */ ?>
        .landing-footer {
            margin-top: 3rem;
            font-size: 0.8rem;
            opacity: 0.92;
        }

        <?php /* ✉️ Contact line */ ?>
        .landing-contact a {
            color: #ffffff;
            text-decoration: underline;
        }

        <?php /* 🎬 Fade-in animation */ ?>
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

        <?php /* 📱 Responsive adjustments */ ?>
        @media (max-width: 576px) {
            .site-name {
                font-size: 2rem;
            }
            .tagline {
                font-size: 1rem;
            }
            .brand-icon {
                width: 72px;
                height: 72px;
                padding: 8px;
            }
        }

        <?php /* 🌙 Dark mode adjustments */ ?>
        @media (prefers-color-scheme: dark) {
            body {
                background: linear-gradient(135deg, var(--cuercode-primary-dark) 0%, var(--cuercode-primary) 100%);
            }
        }
    </style>
</head>
<body>
    <?php /* ====================================================================
         📄 Main Content
         ==================================================================== */ ?>
    <main class="coming-soon-container">
        <?php /* 🎯 Real logo mark (#137) - decorative, the name follows as text. */ ?>
        <img class="brand-icon" src="/logo-mark.svg" alt="" width="96" height="96">

        <?php /* 📝 Site name and tagline */ ?>
        <h1 class="site-name"><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="tagline"><?php echo htmlspecialchars($tagline, ENT_QUOTES, 'UTF-8'); ?></p>

        <?php /* 📝 Description */ ?>
        <p class="description"><?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></p>

        <?php /* 🏷️ Status badge */ ?>
        <span class="status-badge">Coming soon</span>

        <p class="landing-contact mt-4">
            Questions? <a href="mailto:hello@cuercode.net">hello@cuercode.net</a>
        </p>

        <?php /* 📝 Footer */ ?>
        <footer class="landing-footer">
            <p>&copy; <?php echo htmlspecialchars($copyrightRange, ENT_QUOTES, 'UTF-8'); ?> MWBM Partners Ltd. All rights reserved.</p>
        </footer>
    </main>

</body>
</html>
