/**
 * ==============================================================================
 * 🔧 CueRCode - Common JavaScript Utilities
 * ==============================================================================
 *
 * Shared utilities used across all pages of the CueRCode application.
 * Provides CSRF token management, AJAX wrappers, toast notifications,
 * debounce helper, file size formatting, and clipboard utilities.
 *
 * Dependencies: jQuery 3.x
 *
 * @package     CueRCode
 * @subpackage  JavaScript
 * @version     1.0.0
 * @since       Phase 1
 * @see         https://api.jquery.com/jQuery.ajax/
 * @see         https://getbootstrap.com/docs/5.3/components/toasts/
 */

/* global jQuery, bootstrap */

var CueRCode;
if (window.CueRCode) {
    CueRCode = window.CueRCode;
} else {
    CueRCode = {};
}

// ==============================================================================
// 🛡️ CSRF Token Management
// ==============================================================================

/**
 * 🛡️ Get the current CSRF token from the meta tag
 *
 * Reads the CSRF token from the <meta name="csrf-token"> tag placed in
 * the page <head> by header.php.
 *
 * @returns {string} The CSRF token value, or empty string if not found
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/API/Document/querySelector
 */
CueRCode.getCSRFToken = function () {
    /* 📌 First check the CueRCodeConfig global (set in index.php) */
    if (typeof CueRCodeConfig !== 'undefined' && CueRCodeConfig.csrfToken) {
        return CueRCodeConfig.csrfToken;
    }

    /* 📌 Fallback: read from meta tag */
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) {
        return meta.getAttribute('content');
    } else {
        return '';
    }
};

// ==============================================================================
// 📡 AJAX Wrapper
// ==============================================================================

/**
 * 📡 Make an AJAX request with CSRF token included automatically
 *
 * Wraps jQuery.ajax() with default settings including the CSRF token
 * in the X-CSRF-Token header for all requests.
 *
 * @param {Object} options - jQuery.ajax() options object
 * @returns {jqXHR} The jQuery XMLHttpRequest object
 *
 * @see https://api.jquery.com/jQuery.ajax/
 *
 * @example
 *   CueRCode.ajax({
 *       url: '/api/v1/preview',
 *       method: 'POST',
 *       data: JSON.stringify({ type: 'url', url: 'https://example.com' }),
 *       contentType: 'application/json',
 *       success: function(response) { console.log(response); },
 *       error: function(xhr) { console.error(xhr.responseText); }
 *   });
 */
CueRCode.ajax = function (options) {
    /* 📌 Default AJAX settings */
    var defaults = {
        method: 'POST',
        dataType: 'json',
        headers: {
            'X-CSRF-Token': CueRCode.getCSRFToken()
        },
        timeout: 30000,
        error: function (xhr, status, error) {
            /* 📌 Default error handler */
            if (status === 'timeout') {
                CueRCode.notify('Request timed out. Please try again.', 'warning');
            } else if (xhr.status === 403) {
                CueRCode.notify('Session expired. Please refresh the page.', 'danger');
            } else if (xhr.status === 429) {
                CueRCode.notify('Too many requests. Please wait a moment.', 'warning');
            } else if (xhr.status >= 500) {
                CueRCode.notify('Server error. Please try again later.', 'danger');
            }
        }
    };

    /* 📌 Merge defaults with provided options (deep merge for headers) */
    var merged = jQuery.extend(true, {}, defaults, options);

    return jQuery.ajax(merged);
};

// ==============================================================================
// 🔔 Toast Notification System
// ==============================================================================

/**
 * 🔔 Show a Bootstrap 5 toast notification
 *
 * Creates and displays a toast message at the top-right of the screen.
 * The toast container is created on first use and reused thereafter.
 *
 * @param {string} message - The notification message to display
 * @param {string} [type='info'] - Bootstrap colour type: success, danger, warning, info
 * @param {number} [duration=4000] - Auto-hide delay in milliseconds (0 = no auto-hide)
 *
 * @see https://getbootstrap.com/docs/5.3/components/toasts/
 *
 * @example
 *   CueRCode.notify('QR code generated successfully!', 'success');
 *   CueRCode.notify('Invalid URL format.', 'danger');
 *   CueRCode.notify('Generating preview...', 'info', 2000);
 */
CueRCode.notify = function (message, type, duration) {
    if (type) {
        type = type;
    } else {
        type = 'info';
    }
    if (typeof duration !== 'undefined') {
        duration = duration;
    } else {
        duration = 4000;
    }

    /* 📌 Icon mapping for toast types */
    var iconMap = {
        success: 'fa-solid fa-circle-check',
        danger:  'fa-solid fa-circle-xmark',
        warning: 'fa-solid fa-triangle-exclamation',
        info:    'fa-solid fa-circle-info'
    };

    var icon;
    if (iconMap[type]) {
        icon = iconMap[type];
    } else {
        icon = iconMap.info;
    }

    /* 📌 Ensure toast container exists */
    var $container = jQuery('#cuercode-toast-container');
    if ($container.length === 0) {
        $container = jQuery(
            '<div id="cuercode-toast-container" class="toast-container position-fixed top-0 end-0 p-3" '
            + 'style="z-index: 1090;"></div>'
        ).appendTo('body');
    }

    /* 📌 Build the toast HTML */
    /* 📌 Use role="alert" for danger/warning (assertive), role="status" with aria-live="polite" for success/info */
    var toastId = 'toast-' + Date.now();
    var roleAttr;
    if (type === 'danger' || type === 'warning') {
        roleAttr = 'role="alert"';
    } else {
        roleAttr = 'role="status" aria-live="polite"';
    }
    var durationAttr;
    if (duration > 0) {
        durationAttr = ' data-bs-delay="' + duration + '"';
    } else {
        durationAttr = ' data-bs-autohide="false"';
    }
    /* 🛡️ HTML-escape the message before building the toast markup so any
       HTML/script characters in the text are rendered as literal text
       rather than parsed into the DOM (closes the latent DOM-XSS sink
       documented in SECURITY.md F-018). escapeHtml() returns '' for
       non-string input, preserving the existing behaviour for plain text. */
    var safeMessage = CueRCode.escapeHtml(String(message));
    var toastHtml =
        '<div id="' + toastId + '" class="toast align-items-center text-bg-' + type + ' border-0" '
        + roleAttr + ' aria-atomic="true"'
        + durationAttr + '>'
        + '<div class="d-flex">'
        + '<div class="toast-body"><i class="' + icon + ' me-2"></i>' + safeMessage + '</div>'
        + '<button type="button" class="btn-close btn-close-white me-2 m-auto" '
        + 'data-bs-dismiss="toast" aria-label="Close"></button>'
        + '</div></div>';

    /* 📌 Add toast to container and show it */
    var $toast = jQuery(toastHtml).appendTo($container);

    /* @see https://getbootstrap.com/docs/5.3/components/toasts/#usage */
    if (typeof bootstrap !== 'undefined' && bootstrap.Toast) {
        var bsToast = new bootstrap.Toast($toast[0]);
        bsToast.show();
    }

    /* 📌 Remove toast element from DOM after it's hidden */
    $toast.on('hidden.bs.toast', function () {
        jQuery(this).remove();
    });
};

// ==============================================================================
// ⏱️ Debounce Helper
// ==============================================================================

/**
 * ⏱️ Create a debounced version of a function
 *
 * Returns a new function that delays invoking the provided function
 * until after the specified wait time has elapsed since the last
 * invocation. Useful for rate-limiting expensive operations like
 * AJAX preview calls triggered by user input.
 *
 * @param {Function} func - The function to debounce
 * @param {number} wait - The debounce delay in milliseconds
 * @returns {Function} The debounced function
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/API/setTimeout
 *
 * @example
 *   var debouncedSearch = CueRCode.debounce(function() {
 *       // Perform search
 *   }, 300);
 *
 *   jQuery('#search-input').on('input', debouncedSearch);
 */
CueRCode.debounce = function (func, wait) {
    var timeout;

    return function () {
        var context = this;
        var args = arguments;

        clearTimeout(timeout);

        timeout = setTimeout(function () {
            func.apply(context, args);
        }, wait);
    };
};

// ==============================================================================
// 📏 File Size Formatter
// ==============================================================================

/**
 * 📏 Format a byte count into a human-readable file size string
 *
 * Converts bytes to the most appropriate unit (B, KB, MB, GB)
 * with the specified number of decimal places.
 *
 * @param {number} bytes - The file size in bytes
 * @param {number} [decimals=1] - Number of decimal places
 * @returns {string} The formatted file size string (e.g., "1.5 MB")
 *
 * @example
 *   CueRCode.formatFileSize(1024);      // "1.0 KB"
 *   CueRCode.formatFileSize(5242880);   // "5.0 MB"
 *   CueRCode.formatFileSize(0);         // "0 Bytes"
 */
CueRCode.formatFileSize = function (bytes, decimals) {
    if (bytes === 0) {
        return '0 Bytes';
    }

    if (typeof decimals !== 'undefined') {
        decimals = decimals;
    } else {
        decimals = 1;
    }
    var k = 1024;
    var sizes = ['Bytes', 'KB', 'MB', 'GB'];
    var i = Math.floor(Math.log(bytes) / Math.log(k));

    return parseFloat((bytes / Math.pow(k, i)).toFixed(decimals)) + ' ' + sizes[i];
};

// ==============================================================================
// 📋 Clipboard Utility
// ==============================================================================

/**
 * 📋 Copy text to the system clipboard
 *
 * Uses the modern Clipboard API with a fallback to the legacy
 * document.execCommand('copy') method for older browsers.
 *
 * @param {string} text - The text to copy to the clipboard
 * @returns {Promise<boolean>} Resolves to true if copy succeeded
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/API/Clipboard/writeText
 * @see https://developer.mozilla.org/en-US/docs/Web/API/Document/execCommand
 *
 * @example
 *   CueRCode.copyToClipboard('https://cuercode.net').then(function(success) {
 *       if (success) {
 *           CueRCode.notify('Copied to clipboard!', 'success');
 *       }
 *   });
 */
CueRCode.copyToClipboard = function (text) {
    /* 📌 Modern Clipboard API */
    /* @see https://developer.mozilla.org/en-US/docs/Web/API/Clipboard/writeText */
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        return navigator.clipboard.writeText(text).then(
            function () { return true; },
            function () { return false; }
        );
    }

    /* 📌 Legacy fallback using a temporary textarea */
    /* @see https://developer.mozilla.org/en-US/docs/Web/API/Document/execCommand */
    return new Promise(function (resolve) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();

        try {
            var success = document.execCommand('copy');
            resolve(success);
        } catch (err) {
            resolve(false);
        } finally {
            document.body.removeChild(textarea);
        }
    });
};

// ==============================================================================
// ✅ Form Validation Helper
// ==============================================================================

/**
 * ✅ Validate a hex colour string
 *
 * Checks if the provided string is a valid CSS hex colour
 * in 3-digit (#fff) or 6-digit (#ffffff) format.
 *
 * @param {string} hex - The colour string to validate
 * @returns {boolean} True if valid hex colour
 *
 * @example
 *   CueRCode.isValidHexColor('#ff0000');  // true
 *   CueRCode.isValidHexColor('#abc');     // true
 *   CueRCode.isValidHexColor('red');      // false
 */
CueRCode.isValidHexColor = function (hex) {
    return /^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/.test(hex);
};

/**
 * ✅ Validate a URL string
 *
 * Basic URL validation - checks for http:// or https:// protocol.
 *
 * @param {string} url - The URL string to validate
 * @returns {boolean} True if the string appears to be a valid URL
 */
CueRCode.isValidURL = function (url) {
    try {
        var parsed = new URL(url);
        return parsed.protocol === 'http:' || parsed.protocol === 'https:';
    } catch (e) {
        return false;
    }
};

/**
 * ✅ Validate an email address string
 *
 * Basic email validation using a simple regex pattern.
 *
 * @param {string} email - The email string to validate
 * @returns {boolean} True if the string appears to be a valid email
 */
CueRCode.isValidEmail = function (email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
};

// ==============================================================================
// 🔧 Utility: Escape HTML
// ==============================================================================

/**
 * 🔧 Escape HTML special characters to prevent XSS
 *
 * Converts &, <, >, ", and ' to their HTML entity equivalents.
 *
 * @param {string} str - The string to escape
 * @returns {string} The HTML-escaped string
 *
 * @see https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html
 */
CueRCode.escapeHtml = function (str) {
    if (typeof str !== 'string') {
        return '';
    }

    var entityMap = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    };

    return str.replace(/[&<>"']/g, function (s) {
        return entityMap[s];
    });
};

// ==============================================================================
// 🔄 Initialisation
// ==============================================================================

/**
 * 🔄 Initialise common functionality when DOM is ready
 *
 * Sets up global AJAX defaults and any common event handlers.
 *
 * @see https://api.jquery.com/jQuery.ajaxSetup/
 * @see https://api.jquery.com/ready/
 */
jQuery(document).ready(function ($) {
    /* 📌 Set up global AJAX defaults with CSRF token */
    /* @see https://api.jquery.com/jQuery.ajaxSetup/ */
    $.ajaxSetup({
        headers: {
            'X-CSRF-Token': CueRCode.getCSRFToken()
        }
    });

    /* 📌 Sync colour picker inputs with hex text inputs */
    /* When colour picker changes, update the text input */
    $(document).on('input', '.form-control-color', function () {
        var hexInput = $(this).siblings('.color-hex-input');
        if (hexInput.length > 0) {
            hexInput.val(this.value);
        }
    });

    /* When hex text input changes, update the colour picker */
    $(document).on('change', '.color-hex-input', function () {
        var hex = $(this).val().trim();
        /* 📌 Add # prefix if missing */
        if (hex.length > 0 && hex.charAt(0) !== '#') {
            hex = '#' + hex;
            $(this).val(hex);
        }

        if (CueRCode.isValidHexColor(hex)) {
            var colorInput = $(this).siblings('.form-control-color');
            if (colorInput.length > 0) {
                colorInput.val(hex);
            }
        }
    });
});
