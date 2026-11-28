/**
 * ==============================================================================
 * 🎨 CueRCode - Early page setup (loaded in <head>)
 * ==============================================================================
 *
 * Path: public_html/assets/js/cuercode-head.js
 *
 * Runs before the page is drawn, so there is no flash of the wrong colours.
 * These were inline scripts in _includes/header.php; they are a file so that
 * the admin area can refuse every inline script (#154).
 *
 *   1. Load the committed copies of the Bootstrap and Font Awesome styles if
 *      the CDN copies did not arrive.
 *   2. Apply the saved (or system) light/dark mode.
 *   3. Apply the saved colourblind palette (FG-023).
 *   4. Show the default avatar if a profile picture cannot be loaded (this
 *      replaces an onerror="" attribute, which the admin policy refuses).
 *
 * Must stay a plain (blocking) script: no "defer" or "async".
 *
 * @package     CueRCode
 * @subpackage  JavaScript
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (#154)
 * @see         https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Security-Policy/script-src
 */

/* 🔄 Bootstrap CSS CDN fallback to local copy */
(function () {
    var testEl = document.createElement('div');
    testEl.className = 'visually-hidden';
    if (document.body) {
        document.body.appendChild(testEl);
    } else {
        document.addEventListener('DOMContentLoaded', function () {
            document.body.appendChild(testEl);
        });
    }
    setTimeout(function () {
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
        if (testEl.parentNode) {
            testEl.parentNode.removeChild(testEl);
        }
    }, 3000);
}());

/* 🔄 Font Awesome CSS CDN fallback to local copy (#119, #137) - the same
   approach: if the CDN stylesheet loaded, an icon element's font-family names
   "Font Awesome"; if not, load the committed copy. */
(function () {
    var testIcon = document.createElement('i');
    testIcon.className = 'fa-solid fa-check';
    testIcon.setAttribute('aria-hidden', 'true');
    testIcon.style.position = 'absolute';
    testIcon.style.left = '-9999px';
    if (document.body) {
        document.body.appendChild(testIcon);
    } else {
        document.addEventListener('DOMContentLoaded', function () {
            document.body.appendChild(testIcon);
        });
    }
    setTimeout(function () {
        var iconStyle;
        if (window.getComputedStyle) {
            iconStyle = window.getComputedStyle(testIcon);
        } else {
            iconStyle = testIcon.currentStyle;
        }
        if (!iconStyle || String(iconStyle.fontFamily).indexOf('Font Awesome') === -1) {
            var faLink = document.createElement('link');
            faLink.rel = 'stylesheet';
            faLink.href = '/assets/vendor/fontawesome/css/all.min.css';
            document.head.appendChild(faLink);
        }
        if (testIcon.parentNode) {
            testIcon.parentNode.removeChild(testIcon);
        }
    }, 3000);
}());

/* 🌙 Auto-detect and apply colour scheme preference
   @see https://developer.mozilla.org/en-US/docs/Web/CSS/@media/prefers-color-scheme */
(function () {
    var theme = null;
    try {
        theme = window.localStorage.getItem('cuercode-theme');
    } catch (e) {
        /* 📌 Storage blocked: follow the system setting */
    }
    if (theme === 'dark' || theme === 'light') {
        document.documentElement.setAttribute('data-bs-theme', theme);
    } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        document.documentElement.setAttribute('data-bs-theme', 'dark');
    } else {
        document.documentElement.setAttribute('data-bs-theme', 'light');
    }
}());

/* ♿ Apply the saved colourblind palette before first paint. The attribute is
   used by the [data-cuercode-cvd="..."] rules in cuercode.css; 'none' or no
   saved choice leaves the default brand colours. */
(function () {
    var cvd = null;
    try {
        cvd = window.localStorage.getItem('cuercode-cvd');
    } catch (e) {
        /* 📌 Storage blocked: default colours */
    }
    var valid = ['protanopia', 'deuteranopia', 'tritanopia'];
    if (valid.indexOf(cvd) !== -1) {
        document.documentElement.setAttribute('data-cuercode-cvd', cvd);
    }
}());

/* 👤 A picture with data-cuercode-fallback-src shows that address instead if
   it cannot be loaded. "error" does not bubble, so it is caught on the way
   down (capture), which also covers pictures that appear later. */
document.addEventListener('error', function (event) {
    var image = event.target;
    if (!image || image.tagName !== 'IMG') {
        return;
    }
    var fallback = image.getAttribute('data-cuercode-fallback-src');
    if (fallback) {
        /* 🛑 Once only, so a broken fallback cannot loop */
        image.removeAttribute('data-cuercode-fallback-src');
        image.src = fallback;
    }
}, true);
