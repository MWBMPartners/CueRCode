<?php
/**
 * ==============================================================================
 * 🛟 CueRCode - Bare Fallback Page (shared helper)
 * ==============================================================================
 *
 * Path: _includes/fallback_page.php
 *
 * Builds one small, self-contained HTML page for the moments when the normal
 * site cannot be shown: the bootstrap failed, the database could not be
 * reached, or PHP crashed before the real error pages could be drawn.
 *
 * ⚠️ THIS FILE RUNS WHEN THINGS ARE BROKEN, SO IT MUST STAY SELF-CONTAINED:
 *   - 🚫 No database, no config lookups, no other includes, no constants from
 *        bootstrap.php (those may not exist yet).
 *   - 🚫 No external files: the CSS is inline, so the page still looks right
 *        when the stylesheet CDN, the assets folder or the database is down.
 *   - 🔒 It NEVER prints error details - callers log those themselves. The
 *        visitor only sees a short, calm, plain-English message.
 *   - 🧩 It sets NO headers and NO status code. Each caller keeps its own
 *        `http_response_code()` / `header()` call exactly as before, so
 *        404 stays 404, 500 stays 500 and 503 stays 503.
 *
 * Callers must still work if this file fails to load, e.g.:
 *
 *     $fallbackHelper = __DIR__ . DIRECTORY_SEPARATOR . 'fallback_page.php';
 *     if (is_file($fallbackHelper)) {
 *         require_once $fallbackHelper;
 *     }
 *     if (function_exists('cuercodeFallbackPageHtml')) {
 *         echo cuercodeFallbackPageHtml('Title', 'Heading', 'Message.');
 *     } else {
 *         echo '<!DOCTYPE html>...minimal inline page...';
 *     }
 *
 * @package     CueRCode
 * @subpackage  Error Handling
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 1
 * @see         https://www.php.net/manual/en/function.htmlspecialchars.php
 * @see         https://www.w3.org/WAI/WCAG21/Understanding/focus-visible.html
 */

// ==============================================================================
// 🚫 Direct access protection
// ==============================================================================
// This file only defines a function. If someone requests it by address, send
// a plain 403 (same pattern as the other files in _includes/).
// ==============================================================================
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $fallbackScriptFilename = $_SERVER['SCRIPT_FILENAME'];
} else {
    $fallbackScriptFilename = '';
}
if (basename($fallbackScriptFilename) === basename(__FILE__)) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied.');
}

// ==============================================================================
// 🧱 Helper function (declared once, safe to load several times)
// ==============================================================================
if (!function_exists('cuercodeFallbackPageHtml')) {

    /**
     * 🛟 Build the bare fallback page as a string of HTML.
     *
     * Returns the page rather than echoing it, so the caller decides when to
     * send it (and has already sent its own status code / headers).
     *
     * @param string $pageTitle Text for the browser tab, e.g. "Technical problem"
     *                          (" | CueRCode" is added automatically)
     * @param string $heading   The visible <h1>, e.g. "We're having a technical problem"
     * @param string $message   One short plain sentence under the heading
     *
     * @return string A complete HTML5 document (inline CSS only, no external files)
     */
    function cuercodeFallbackPageHtml(
        string $pageTitle = 'Technical problem',
        string $heading = 'We\'re having a technical problem',
        string $message = 'Please try again in a few minutes.'
    ): string {

        // 🛡️ Escape all three strings. The callers pass fixed text today, but
        // escaping here means the helper stays safe if that ever changes.
        $safeTitle   = htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeHeading = htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // 📧 The public contact address (also used in terms.php / privacy.php)
        $contactEmail = 'hello@cuercode.net';

        // 🎨 Inline CSS. Colours follow the site's Bootstrap light/dark palette
        // and the brand teal. Contrast was checked against WCAG 2.1 AA (4.5:1):
        //   light: text #212529 on #ffffff = 15.4:1, link #1d6869 on #ffffff = 6.5:1,
        //          button text #ffffff on #1d6869 = 6.5:1
        //   dark:  text #f8f9fa on #212529 = 14.6:1, link #4fd1c5 on #212529 = 8.3:1,
        //          button text #212529 on #4fd1c5 = 8.3:1
        $css = ':root{color-scheme:light dark;--bg:#ffffff;--text:#212529;--muted:#495057;'
            . '--link:#1d6869;--btn-bg:#1d6869;--btn-text:#ffffff;--focus:#212529;}'
            . '@media (prefers-color-scheme:dark){:root{--bg:#212529;--text:#f8f9fa;'
            . '--muted:#ced4da;--link:#4fd1c5;--btn-bg:#4fd1c5;--btn-text:#212529;'
            . '--focus:#f8f9fa;}}'
            . '*,*::before,*::after{box-sizing:border-box;}'
            . 'html{-webkit-text-size-adjust:100%;}'
            . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
            . 'padding:1.5rem;background:var(--bg);color:var(--text);line-height:1.6;'
            . 'font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;}'
            . 'main{max-width:30rem;width:100%;text-align:center;}'
            . '.brand{margin:0 0 1.5rem;font-size:1.25rem;font-weight:700;letter-spacing:.02em;}'
            . 'h1{margin:0 0 .75rem;font-size:1.75rem;line-height:1.25;}'
            . 'p{margin:0 0 1.25rem;}'
            . '.muted{color:var(--muted);font-size:.95rem;}'
            . 'a{color:var(--link);text-decoration:underline;text-underline-offset:.15em;}'
            . 'a:hover{text-decoration-thickness:2px;}'
            . 'a:focus-visible{outline:3px solid var(--focus);outline-offset:3px;border-radius:2px;}'
            . 'a.button{display:inline-block;padding:.65rem 1.5rem;border-radius:.5rem;'
            . 'background:var(--btn-bg);color:var(--btn-text);font-weight:600;text-decoration:none;}'
            . 'a.button:hover{text-decoration:underline;}';

        // 🧱 Assemble the page. A real <main> landmark, one <h1>, UK English,
        // viewport meta so it is readable on phones, and noindex so search
        // engines never keep a temporary error page.
        $html = '<!DOCTYPE html>' . PHP_EOL
            . '<html lang="en-GB">' . PHP_EOL
            . '<head>' . PHP_EOL
            . '<meta charset="UTF-8">' . PHP_EOL
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . PHP_EOL
            . '<meta name="robots" content="noindex">' . PHP_EOL
            . '<title>' . $safeTitle . ' | CueRCode</title>' . PHP_EOL
            . '<style>' . $css . '</style>' . PHP_EOL
            . '</head>' . PHP_EOL
            . '<body>' . PHP_EOL
            . '<main>' . PHP_EOL
            . '<p class="brand">CueRCode</p>' . PHP_EOL
            . '<h1>' . $safeHeading . '</h1>' . PHP_EOL
            . '<p>' . $safeMessage . '</p>' . PHP_EOL
            . '<p><a class="button" href="/">Go to the CueRCode home page</a></p>' . PHP_EOL
            . '<p class="muted">If this keeps happening, email '
            . '<a href="mailto:' . $contactEmail . '">' . $contactEmail . '</a>.</p>' . PHP_EOL
            . '</main>' . PHP_EOL
            . '</body>' . PHP_EOL
            . '</html>' . PHP_EOL;

        return $html;
    }
}
