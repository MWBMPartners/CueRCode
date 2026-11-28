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
 * Decision 0037 (a provider cannot be reached): the box may also carry
 * data-fallbacks, a list of the other configured providers. If the
 * provider's script does not load (an error, or nothing after a few
 * seconds), or the server answers "could not be completed" (next()), the box
 * is switched to the next provider and its script is loaded. This only
 * changes which widget is DRAWN: the server decides on its own whether a
 * provider is down, and only ever accepts a token from a provider that is
 * set up. tokenProvider(form) says which provider made the token, so the
 * JSON routes can send it as "human_check_provider".
 *
 * With JavaScript off nothing here runs; the page's <noscript> part explains
 * that the check needs JavaScript and offers the email-confirmed route
 * (decision 0038).
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

    /** ⏱️ How long to wait for a provider's script before trying the next one. */
    var SCRIPT_WAIT_MS = 8000;

    /** 🔁 Timer for the current script, so a late load does not switch twice. */
    var scriptTimer = null;

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
     * 🏷️ Which provider made the form's token ('' when there is no widget).
     *
     * @param {Element|Object} form
     * @returns {string}
     */
    function tokenProvider(form) {
        var box = boxInForm(form);
        if (box === null) {
            return '';
        }
        return box.getAttribute('data-provider') || '';
    }

    /**
     * 📋 The other configured providers still to try (data-fallbacks).
     *
     * @param {Element} box
     * @returns {Array}
     */
    function fallbacksOf(box) {
        var raw = box.getAttribute('data-fallbacks');
        if (!raw) {
            return [];
        }
        try {
            var list = JSON.parse(raw);
            if (Object.prototype.toString.call(list) === '[object Array]') {
                return list;
            }
        } catch (e) {
            return [];
        }
        return [];
    }

    /**
     * 🔁 Switch every box to the next configured provider and load its
     *    script. Returns false when there is none left (the form can still
     *    be sent: the server decides).
     *
     * @returns {boolean}
     */
    function switchToNextProvider() {
        if (boxes.length === 0) {
            return false;
        }
        var list = fallbacksOf(boxes[0]);
        if (list.length === 0) {
            return false;
        }
        var next = list.shift();
        if (!next || typeof next.provider !== 'string' || typeof next.script !== 'string' || next.script.indexOf('https://') !== 0) {
            return false;
        }
        var i;
        for (i = 0; i < boxes.length; i++) {
            boxes[i].setAttribute('data-provider', next.provider);
            boxes[i].setAttribute('data-sitekey', String(next.sitekey || ''));
            boxes[i].setAttribute('data-script', next.script);
            boxes[i].setAttribute('data-fallbacks', JSON.stringify(list));
            /* 🧹 The old provider's widget is removed before the next is drawn. */
            boxes[i].innerHTML = '';
            delete boxes[i].cuercodeWidgetId;
        }
        loadScript(next.script);
        return true;
    }

    /**
     * 📜 Add a provider's script to the page, moving on to the next provider
     *    if it fails to load or does not call back in time.
     *
     * @param {string} source
     */
    function loadScript(source) {
        var script = document.createElement('script');
        script.src = source;
        script.async = true;
        script.defer = true;
        script.onerror = function () {
            if (scriptTimer !== null) {
                window.clearTimeout(scriptTimer);
                scriptTimer = null;
            }
            switchToNextProvider();
        };
        if (scriptTimer !== null) {
            window.clearTimeout(scriptTimer);
        }
        scriptTimer = window.setTimeout(function () {
            scriptTimer = null;
            if (boxes.length > 0 && providerApi(boxes[0].getAttribute('data-provider')) === null) {
                switchToNextProvider();
            }
        }, SCRIPT_WAIT_MS);
        document.head.appendChild(script);
    }

    /**
     * 📥 Called by the provider's script once it has loaded (onload=...).
     */
    window.cuercodeHumanCheckLoaded = function () {
        if (scriptTimer !== null) {
            window.clearTimeout(scriptTimer);
            scriptTimer = null;
        }
        renderAll();
    };

    window.CueRCodeHumanCheck = {
        token: token,
        tokenProvider: tokenProvider,
        reset: reset,
        next: switchToNextProvider
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
        loadScript(source);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
