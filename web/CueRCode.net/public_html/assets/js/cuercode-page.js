/**
 * ==============================================================================
 * 🌙 CueRCode - Every page: light/dark button and service worker
 * ==============================================================================
 *
 * Path: public_html/assets/js/cuercode-page.js
 *
 * Loaded at the end of every page (_includes/footer.php). These were inline
 * scripts there; they are a file so that the admin area can refuse every
 * inline script (#154).
 *
 * @package     CueRCode
 * @subpackage  JavaScript
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (#154)
 * @see         https://developer.mozilla.org/en-US/docs/Web/API/ServiceWorkerContainer/register
 */

/* 🌙 Theme toggle functionality */
(function () {
    var toggleBtn = document.getElementById('theme-toggle');
    if (!toggleBtn) {
        return;
    }
    toggleBtn.addEventListener('click', function () {
        var currentTheme = document.documentElement.getAttribute('data-bs-theme');
        var newTheme;
        if (currentTheme === 'dark') {
            newTheme = 'light';
        } else {
            newTheme = 'dark';
        }
        document.documentElement.setAttribute('data-bs-theme', newTheme);
        try {
            window.localStorage.setItem('cuercode-theme', newTheme);
        } catch (e) {
            /* 📌 Storage blocked: the choice lasts for this page only */
        }

        /* Update the toggle icon */
        var icon = this.querySelector('i');
        if (icon) {
            if (newTheme === 'dark') {
                icon.className = 'fa-solid fa-sun';
            } else {
                icon.className = 'fa-solid fa-moon';
            }
        }
    });

    /* Set the first icon to match the current theme */
    var currentTheme = document.documentElement.getAttribute('data-bs-theme');
    var icon = toggleBtn.querySelector('i');
    if (icon && currentTheme === 'dark') {
        icon.className = 'fa-solid fa-sun';
    }
}());

/* 📱 Register the service worker for offline support and caching */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('/sw.js').catch(function () {
            /* ⚠️ Registration failed - not critical */
        });
    });
}
