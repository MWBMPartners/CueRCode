/**
 * ==============================================================================
 * 🛡️ CueRCode - Admin area comfort features (skeleton, task 7n Phase 1B)
 * ==============================================================================
 *
 * Path: public_html/assets/js/cuercode-admin.js
 *
 * The ONE script file for every page under /admin/ (plan §7.5). Admin pages
 * have no inline scripts and load no new libraries; this file only ADDS
 * comfort on top of pages that already work completely with JavaScript off
 * (every check that matters - CSRF, password confirmation, "I have a backup"
 * tick boxes - is done again by the server).
 *
 * What it does today (Phase 1B):
 *   1. 🎯 Moves keyboard focus to the first error message on the page
 *      (an element with data-admin-focus), so screen-reader and keyboard
 *      users hear the problem straight away.
 *   2. ❓ Asks "are you sure?" before a form marked data-admin-confirm="..."
 *      is sent (the server still requires its own confirmation).
 *   3. 👁️ "Show what I typed" for a password/secret box: a button with
 *      data-admin-reveal="<input id>" switches that ONE box between hidden
 *      and visible text (used by the settings screen from Phase 1C).
 *   4. 📝 Warns before leaving a page with unsaved changes in a form marked
 *      data-admin-dirty-check.
 *
 * No framework is needed (plain DOM), so it works whether or not jQuery has
 * loaded.
 *
 * @package     CueRCode
 * @subpackage  Admin
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-24 (task 7n Phase 1B, #31)
 * @see         public_html/admin/index.php
 * @see         _functions/fn_admin.php - adminRenderMessages() adds data-admin-focus
 */

(function () {
    'use strict';

    // ==========================================================================
    // 🎯 1. Focus the first error message
    // ==========================================================================
    function focusFirstError() {
        var target = document.querySelector('[data-admin-focus]');

        if (target && typeof target.focus === 'function') {
            target.focus();
        }
    }

    // ==========================================================================
    // ❓ 2. "Are you sure?" before risky forms
    // ==========================================================================
    function wireConfirmForms() {
        var forms = document.querySelectorAll('form[data-admin-confirm]');
        var index;

        for (index = 0; index < forms.length; index++) {
            forms[index].addEventListener('submit', function (event) {
                var question = this.getAttribute('data-admin-confirm');

                if (question && !window.confirm(question)) {
                    event.preventDefault();
                }
            });
        }
    }

    // ==========================================================================
    // 👁️ 3. Show / hide what was typed in ONE password or secret box
    // ==========================================================================
    function wireRevealButtons() {
        var buttons = document.querySelectorAll('[data-admin-reveal]');
        var index;

        for (index = 0; index < buttons.length; index++) {
            buttons[index].hidden = false;
            buttons[index].setAttribute('aria-pressed', 'false');

            buttons[index].addEventListener('click', function () {
                var input = document.getElementById(this.getAttribute('data-admin-reveal'));

                if (!input) {
                    return;
                }

                if (input.type === 'password') {
                    input.type = 'text';
                    this.setAttribute('aria-pressed', 'true');
                } else {
                    input.type = 'password';
                    this.setAttribute('aria-pressed', 'false');
                }
            });
        }
    }

    // ==========================================================================
    // 📝 4. Warn before leaving with unsaved changes
    // ==========================================================================
    function wireDirtyCheck() {
        var forms = document.querySelectorAll('form[data-admin-dirty-check]');
        var hasUnsavedChanges = false;
        var index;

        if (forms.length === 0) {
            return;
        }

        for (index = 0; index < forms.length; index++) {
            forms[index].addEventListener('input', function () {
                hasUnsavedChanges = true;
            });
            forms[index].addEventListener('change', function () {
                hasUnsavedChanges = true;
            });
            forms[index].addEventListener('submit', function () {
                hasUnsavedChanges = false;
            });
        }

        window.addEventListener('beforeunload', function (event) {
            if (hasUnsavedChanges) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
    }

    // ==========================================================================
    // 🚀 Start when the page is ready
    // ==========================================================================
    function start() {
        focusFirstError();
        wireConfirmForms();
        wireRevealButtons();
        wireDirtyCheck();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
