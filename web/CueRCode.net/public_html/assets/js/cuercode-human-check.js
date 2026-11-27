/**
 * ==============================================================================
 * 🤖 CueRCode - Human check widget (Turnstile, reCAPTCHA or hCaptcha) (#163)
 * ==============================================================================
 *
 * Path: public_html/assets/js/cuercode-human-check.js
 *
 * The server draws an empty box inside each protected form (only while the
 * check is on - see humanCheckWidgetHtml() in _functions/fn_human_check.php):
 *
 *   <div class="cuercode-human-check" data-provider="turnstile"
 *        data-sitekey="..." data-script="https://.../api.js?render=explicit&onload=cuercodeHumanCheckLoaded">
 *
 * This file:
 *   1. defines window.cuercodeHumanCheckLoaded (the provider calls it once its
 *      script has loaded), THEN adds the provider's script to the page - so
 *      the callback always exists first and no third-party script tag sits in
 *      the page itself;
 *   2. draws the widget in each box, in the site's light or dark colours;
 *   3. offers window.CueRCodeHumanCheck.token(form) and .reset(form) to
 *      cuercode-account.js, which puts the token in its JSON request and
 *      resets the widget after every answer (a token works only once).
 *
 * A normal form POST (no account script, for example /resend-verification/)
 * also works: each provider puts its own hidden field (cf-turnstile-response,
 * g-recaptcha-response, h-captcha-response) inside the box, so inside the form.
 *
 * With JavaScript off nothing here runs; the page's <noscript> note explains
 * that the check needs JavaScript.
 *
 * The content security policy (public_html/.htaccess) allows exactly these
 * three providers' addresses. No other script address may be added here
 * without changing that policy.
 *
 * @package     CueRCode
 * @subpackage  Frontend
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-27 (#163, decision 0024)
 * @see         _functions/fn_human_check.php
 * @see         https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/
 * @see         https://developers.google.com/recaptcha/docs/display#explicit_render
 * @see         https://docs.hcaptcha.com/configuration#explicitly-render-hcaptcha
 */

(function () {
    'use strict';

    /**
     * 🧭 Each provider's global object, by the name the server uses.
     *    All three offer render(), getResponse() and reset().
     */
    var PROVIDER_GLOBALS = {
        'turnstile': 'turnstile',
        'recaptcha': 'grecaptcha',
        'hcaptcha': 'hcaptcha'
    };

    /** 📦 Every widget box on the page. */
    var boxes = [];

    /**
     * 🎨 The site's current colour scheme, for the widget's theme.
     *
     * @returns {string} 'dark' or 'light'
     */
    function currentTheme() {
        var theme = document.documentElement.getAttribute('data-bs-theme');
        if (theme === 'dark' || theme === 'light') {
            return theme;
        }
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            return 'dark';
        }
        return 'light';
    }

    /**
     * 🔌 The provider's global object, or null if its script has not loaded.
     *
     * @param {string} provider
     * @returns {Object|null}
     */
    function providerApi(provider) {
        var globalName = PROVIDER_GLOBALS[provider];
        if (!globalName || !window[globalName] || typeof window[globalName].render !== 'function') {
            return null;
        }
        return window[globalName];
    }

    /**
     * 🖌️ Draw the widget in every box that does not have one yet.
     */
    function renderAll() {
        var i;
        for (i = 0; i < boxes.length; i++) {
            var box = boxes[i];
            var api = providerApi(box.getAttribute('data-provider'));
            if (api === null || box.cuercodeWidgetId !== undefined) {
                continue;
            }
            // 📌 Kept on the element as it was returned (reCAPTCHA gives a
            //    number, Turnstile a string), so getResponse() and reset()
            //    get exactly what they expect.
            box.cuercodeWidgetId = api.render(box, {
                sitekey: box.getAttribute('data-sitekey'),
                theme: currentTheme()
            });
        }
    }

    /**
     * 🔎 The widget box inside a form (a DOM element or a jQuery object).
     *
     * @param {Element|Object} form
     * @returns {Element|null}
     */
    function boxInForm(form) {
        var element = form;
        if (form && form.jquery) {
            element = form[0];
        }
        if (!element || typeof element.querySelector !== 'function') {
            return null;
        }
        return element.querySelector('.cuercode-human-check');
    }

    /**
     * 🎫 The token for the form's widget, or '' when there is none (the check
     *    is off, not finished, or its script did not load).
     *
     * @param {Element|Object} form
     * @returns {string}
     */
    function token(form) {
        var box = boxInForm(form);
        if (box === null) {
            return '';
        }
        var api = providerApi(box.getAttribute('data-provider'));
        if (api === null || box.cuercodeWidgetId === undefined) {
            return '';
        }
        var value = api.getResponse(box.cuercodeWidgetId);
        if (typeof value !== 'string') {
            return '';
        }
        return value;
    }

    /**
     * 🔁 Reset the form's widget, so the next try gets a fresh token.
     *
     * @param {Element|Object} form
     */
    function reset(form) {
        var box = boxInForm(form);
        if (box === null) {
            return;
        }
        var api = providerApi(box.getAttribute('data-provider'));
        if (api !== null && box.cuercodeWidgetId !== undefined) {
            api.reset(box.cuercodeWidgetId);
        }
    }

    /**
     * 📥 Called by the provider's script once it has loaded (onload=...).
     */
    window.cuercodeHumanCheckLoaded = function () {
        renderAll();
    };

    window.CueRCodeHumanCheck = {
        token: token,
        reset: reset
    };

    /**
     * 🚀 Find the boxes and load the provider's script once.
     */
    function start() {
        var found = document.querySelectorAll('.cuercode-human-check[data-provider][data-script]');
        var i;
        for (i = 0; i < found.length; i++) {
            boxes.push(found[i]);
        }
        if (boxes.length === 0) {
            return;
        }

        // 📌 Every box on a page uses the same provider; the first one's address is used.
        var source = boxes[0].getAttribute('data-script');
        if (!source || source.indexOf('https://') !== 0) {
            return;
        }
        var script = document.createElement('script');
        script.src = source;
        script.async = true;
        script.defer = true;
        document.head.appendChild(script);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
