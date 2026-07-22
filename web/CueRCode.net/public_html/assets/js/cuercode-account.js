/**
 * ==============================================================================
 * 👤 CueRCode - Account Auth Flow AJAX Enhancement (Epic E3 stage 2b, #52/#12/#14)
 * ==============================================================================
 *
 * Path: public_html/assets/js/cuercode-account.js
 *
 * Progressive-enhancement layer for register/login/verify-email/forgot-
 * password/reset-password: when this script runs, each page's <form> submit
 * is intercepted and POSTed as JSON to the corresponding /api/v1/auth/...
 * endpoint (with the X-CSRF-Token header via CueRCode.ajax()) instead of
 * doing a full-page reload. Every page keeps working identically with
 * JavaScript disabled - the form's normal POST-to-self action, handled
 * server-side by that page's own PHP, is the fallback (see each page's
 * "Handle Non-JS Form POST" section).
 *
 * Depends on cuercode-common.js (CueRCode.ajax/notify/escapeHtml/isValidEmail).
 *
 * @package     CueRCode
 * @subpackage  Frontend
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E3 stage 2b, #52/#12/#14)
 * @see         public_html/register/index.php
 * @see         public_html/login/index.php
 * @see         public_html/verify-email/index.php
 * @see         public_html/forgot-password/index.php
 * @see         public_html/reset-password/index.php
 */

(function ($) {
    'use strict';

    // 📌 Fixed API root - these pages do not define a page-specific
    // CueRCodeConfig object (unlike public_html/index.php), so the base path
    // is a local constant rather than a dependency on a global that may not
    // exist on this page.
    var CUERCODE_API_BASE = '/api/v1';

    // ==========================================================================
    // 🔔 showAjaxAlert() - Populate/reveal a page's role="alert" live region
    // ==========================================================================

    /**
     * 🔔 Show a message in a page's dedicated AJAX alert <div>.
     *
     * @param {string} elementId - The target element's id (e.g. 'register-ajax-alert').
     * @param {string} message   - The message text (HTML-escaped before insertion).
     * @param {string} type      - 'success' or 'danger'.
     */
    function showAjaxAlert(elementId, message, type) {
        var $el = $('#' + elementId);
        if ($el.length === 0) {
            return;
        }

        $el.removeClass('d-none alert-success alert-danger')
            .addClass('alert-' + type)
            .html(CueRCode.escapeHtml(message));

        // 📌 role="alert" on the element already (set in the page markup) -
        // re-inserting text into an already-present live region is enough
        // for most screen readers to announce it; toggling role for a
        // "polite" success message vs. an "assertive" error keeps the
        // urgency appropriate.
        if (type === 'success') {
            $el.attr('role', 'status');
        } else {
            $el.attr('role', 'alert');
        }

        // 📌 Scroll the alert into view for long forms.
        if ($el[0] && typeof $el[0].scrollIntoView === 'function') {
            $el[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    /**
     * 🔧 Extract a human-readable error message from a failed jqXHR.
     *
     * @param {jqXHR} xhr
     * @param {string} fallback
     * @returns {string}
     */
    function extractErrorMessage(xhr, fallback) {
        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
            return xhr.responseJSON.message;
        }
        return fallback;
    }

    /**
     * 🔒 Toggle a submit button's busy state (disabled + spinner icon swap).
     *
     * @param {jQuery} $btn
     * @param {boolean} busy
     * @param {string} busyLabel
     */
    function setButtonBusy($btn, busy, busyLabel) {
        if ($btn.length === 0) {
            return;
        }
        if (busy) {
            $btn.data('original-html', $btn.html());
            $btn.prop('disabled', true).html(
                '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' + busyLabel
            );
        } else {
            var original = $btn.data('original-html');
            if (original) {
                $btn.html(original);
            }
            $btn.prop('disabled', false);
        }
    }

    // ==========================================================================
    // 📊 Password strength hint (client-side UX ONLY - the server remains the
    //    single source of truth via validatePasswordPolicy()).
    // ==========================================================================

    /**
     * 📊 Compute a rough 0-4 password strength score for a live UX hint.
     *
     * Deliberately simple (length + character-class variety) - this is NOT a
     * security check (the server's validatePasswordPolicy() is authoritative
     * and is re-run regardless of what this hint shows).
     *
     * @param {string} password
     * @returns {{score: number, label: string}}
     */
    function computePasswordStrengthHint(password) {
        var score = 0;

        if (password.length >= 8) { score++; }
        if (password.length >= 14) { score++; }
        if (/[a-z]/.test(password) && /[A-Z]/.test(password)) { score++; }
        if (/[0-9]/.test(password) && /[^a-zA-Z0-9]/.test(password)) { score++; }

        var labels = ['Too short', 'Weak', 'Fair', 'Good', 'Strong'];
        return { score: score, label: labels[score] || labels[0] };
    }

    /**
     * 🔌 Wire a live strength hint onto a password <input>, writing into a
     * sibling form-text element.
     *
     * @param {string} inputId - e.g. 'reg-password'.
     * @param {string} hintId  - e.g. 'reg-password-strength'.
     */
    function wirePasswordStrengthHint(inputId, hintId) {
        var $input = $('#' + inputId);
        var $hint  = $('#' + hintId);

        if ($input.length === 0 || $hint.length === 0) {
            return;
        }

        $input.on('input', function () {
            var value = $input.val();
            if (!value) {
                $hint.text('');
                return;
            }
            var result = computePasswordStrengthHint(value);
            $hint.text('Password strength: ' + result.label);
        });
    }

    // ==========================================================================
    // 📝 Register form
    // ==========================================================================

    function wireRegisterForm() {
        var $form = $('#register-form');
        if ($form.length === 0) {
            return;
        }

        wirePasswordStrengthHint('reg-password', 'reg-password-strength');

        $form.on('submit', function (event) {
            event.preventDefault();

            var email       = $('#reg-email').val();
            var displayName = $('#reg-display-name').val();
            var password    = $('#reg-password').val();
            var confirm     = $('#reg-password-confirm').val();

            if (!CueRCode.isValidEmail(email)) {
                showAjaxAlert('register-ajax-alert', 'Please enter a valid email address.', 'danger');
                return;
            }
            if (password !== confirm) {
                showAjaxAlert('register-ajax-alert', 'Passwords do not match.', 'danger');
                return;
            }

            var $btn = $('#register-submit-btn');
            setButtonBusy($btn, true, 'Creating account...');

            CueRCode.ajax({
                url: CUERCODE_API_BASE + '/auth/register',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    email: email,
                    password: password,
                    display_name: displayName || undefined
                }),
                success: function (response) {
                    $form.find('input, button').prop('disabled', true);
                    showAjaxAlert('register-ajax-alert', response.message, 'success');
                },
                error: function (xhr, status) {
                    if (status === 'abort') {
                        return;
                    }
                    showAjaxAlert('register-ajax-alert', extractErrorMessage(xhr, 'Registration failed. Please try again.'), 'danger');
                },
                complete: function () {
                    setButtonBusy($btn, false, '');
                }
            });
        });
    }

    // ==========================================================================
    // 🚪 Login form
    // ==========================================================================

    function wireLoginForm() {
        var $form = $('#login-form');
        if ($form.length === 0) {
            return;
        }

        var nextUrl = $form.data('next-url') || '/account/';

        $form.on('submit', function (event) {
            event.preventDefault();

            var email    = $('#login-email').val();
            var password = $('#login-password').val();

            if (!email || !password) {
                showAjaxAlert('login-ajax-alert', 'Email and password are both required.', 'danger');
                return;
            }

            var $btn = $('#login-submit-btn');
            setButtonBusy($btn, true, 'Signing in...');

            CueRCode.ajax({
                url: CUERCODE_API_BASE + '/auth/login',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ email: email, password: password }),
                success: function () {
                    /* ✅ Full navigation (not a fetch) - the server has
                       already rotated the session; a real page load ensures
                       every other script/page state picks it up cleanly. */
                    window.location.href = nextUrl;
                },
                error: function (xhr, status) {
                    if (status === 'abort') {
                        return;
                    }
                    showAjaxAlert('login-ajax-alert', extractErrorMessage(xhr, 'Sign in failed. Please try again.'), 'danger');
                },
                complete: function () {
                    setButtonBusy($btn, false, '');
                }
            });
        });
    }

    // ==========================================================================
    // ✅ Verify-email form
    // ==========================================================================

    function wireVerifyForm() {
        var $form = $('#verify-form');
        if ($form.length === 0) {
            return;
        }

        $form.on('submit', function (event) {
            event.preventDefault();

            var token = $('#verify-token').val();
            var $btn  = $('#verify-submit-btn');
            setButtonBusy($btn, true, 'Verifying...');

            CueRCode.ajax({
                url: CUERCODE_API_BASE + '/auth/verify-email',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ token: token }),
                success: function (response) {
                    $form.hide();
                    showAjaxAlert('verify-ajax-alert', response.message, 'success');
                    $('#verify-ajax-alert').after(
                        '<a href="/login/" class="btn btn-primary mt-2">'
                        + '<i class="fa-solid fa-right-to-bracket me-1" aria-hidden="true"></i> Sign in</a>'
                    );
                },
                error: function (xhr, status) {
                    if (status === 'abort') {
                        return;
                    }
                    showAjaxAlert('verify-ajax-alert', extractErrorMessage(xhr, 'This verification link is invalid or has expired.'), 'danger');
                },
                complete: function () {
                    setButtonBusy($btn, false, '');
                }
            });
        });
    }

    // ==========================================================================
    // 🔑 Forgot-password form
    // ==========================================================================

    function wireForgotForm() {
        var $form = $('#forgot-form');
        if ($form.length === 0) {
            return;
        }

        $form.on('submit', function (event) {
            event.preventDefault();

            var email = $('#forgot-email').val();

            if (!CueRCode.isValidEmail(email)) {
                showAjaxAlert('forgot-ajax-alert', 'Please enter a valid email address.', 'danger');
                return;
            }

            var $btn = $('#forgot-submit-btn');
            setButtonBusy($btn, true, 'Sending...');

            CueRCode.ajax({
                url: CUERCODE_API_BASE + '/auth/request-password-reset',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ email: email }),
                success: function (response) {
                    $form.hide();
                    /* 🔐 Always the identical anti-enumeration message from
                       the server - this handler never adds its own detail. */
                    showAjaxAlert('forgot-ajax-alert', response.message, 'success');
                },
                error: function (xhr, status) {
                    if (status === 'abort') {
                        return;
                    }
                    showAjaxAlert('forgot-ajax-alert', extractErrorMessage(xhr, 'Please enter a valid email address.'), 'danger');
                },
                complete: function () {
                    setButtonBusy($btn, false, '');
                }
            });
        });
    }

    // ==========================================================================
    // 🔁 Reset-password form
    // ==========================================================================

    function wireResetForm() {
        var $form = $('#reset-form');
        if ($form.length === 0) {
            return;
        }

        wirePasswordStrengthHint('reset-new-password', 'reset-password-strength');

        $form.on('submit', function (event) {
            event.preventDefault();

            var token          = $('#reset-token').val();
            var newPassword    = $('#reset-new-password').val();
            var confirmPassword = $('#reset-new-password-confirm').val();

            if (newPassword !== confirmPassword) {
                showAjaxAlert('reset-ajax-alert', 'Passwords do not match.', 'danger');
                return;
            }

            var $btn = $('#reset-submit-btn');
            setButtonBusy($btn, true, 'Resetting...');

            CueRCode.ajax({
                url: CUERCODE_API_BASE + '/auth/reset-password',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ token: token, new_password: newPassword }),
                success: function (response) {
                    $form.hide();
                    showAjaxAlert('reset-ajax-alert', response.message, 'success');
                    $('#reset-ajax-alert').after(
                        '<a href="/login/" class="btn btn-primary mt-2">'
                        + '<i class="fa-solid fa-right-to-bracket me-1" aria-hidden="true"></i> Sign in</a>'
                    );
                },
                error: function (xhr, status) {
                    if (status === 'abort') {
                        return;
                    }
                    showAjaxAlert('reset-ajax-alert', extractErrorMessage(xhr, 'This password reset link is invalid or has expired.'), 'danger');
                },
                complete: function () {
                    setButtonBusy($btn, false, '');
                }
            });
        });
    }

    // ==========================================================================
    // 🚀 Init
    // ==========================================================================

    $(document).ready(function () {
        wireRegisterForm();
        wireLoginForm();
        wireVerifyForm();
        wireForgotForm();
        wireResetForm();
    });
}(jQuery));
