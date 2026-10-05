/**
 * ==============================================================================
 * 👤 CueRCode - Account Dashboard AJAX Enhancement (Epic E3 stage 2b, #52/#23/#24)
 * ==============================================================================
 *
 * Path: public_html/assets/js/cuercode-account-dashboard.js
 *
 * Progressive-enhancement layer for public_html/account/index.php: profile
 * updates (display name + avatar), password change, session revocation, and
 * saved-code deletion are all intercepted here and sent as AJAX JSON/PATCH/
 * DELETE calls to /api/v1/account/... and /api/v1/qrcodes/{uuid} (with the
 * X-CSRF-Token header via CueRCode.ajax()). Every action keeps working with
 * JavaScript disabled via that page's own server-rendered <form> POST
 * handling - this script only removes the full-page-reload round trip.
 *
 * "Sign out everywhere" is DELIBERATELY left un-intercepted: both the no-JS
 * and JS-enhanced outcomes are an identical full-page redirect to /login/,
 * so there is no UX benefit to an AJAX call for that one action.
 *
 * Epic E7 stage 1 (#54) additionally wires the API-keys section: create
 * (POST /keys), rotate (POST /keys/{id}/rotate), and revoke
 * (DELETE /keys/{id}) - each AJAX call mirrors this page's own no-JS <form>
 * fallback exactly (public_html/account/index.php). A newly-created or
 * newly-rotated key's ONE-TIME secret is rendered client-side (never fetched
 * again - the API never returns it a second time) with a copy-to-clipboard
 * affordance.
 *
 * @package     CueRCode
 * @subpackage  Frontend
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E3 stage 2b, #52/#23/#24; Epic E7 stage 1, #54)
 * @see         public_html/account/index.php
 */

(function ($) {
    'use strict';

    var API_BASE = (typeof CueRCodeAccountConfig !== 'undefined' && CueRCodeAccountConfig.apiBase) || '/api/v1';

    // ==========================================================================
    // 🔔 Shared alert helper (mirrors cuercode-account.js's own copy - kept
    //    duplicated rather than shared to avoid this page depending on
    //    load-order against cuercode-account.js, which it never includes).
    // ==========================================================================

    function showAlert(elementId, message, type) {
        var $el = $('#' + elementId);
        if ($el.length === 0) {
            return;
        }
        $el.removeClass('d-none alert-success alert-danger')
            .addClass('alert-' + type)
            .text(message)
            .attr('role', type === 'success' ? 'status' : 'alert');
    }

    function errorMessageFrom(xhr, fallback) {
        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
            return xhr.responseJSON.message;
        }
        return fallback;
    }

    function setBusy($btn, busy, busyLabel) {
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
    // 📝 Profile form (display name + optional avatar upload)
    // ==========================================================================

    /**
     * 📦 Read a <input type="file"> File as a base64 data URI.
     *
     * @param {File} file
     * @returns {Promise<string>}
     */
    function readFileAsDataUrl(file) {
        return new Promise(function (resolve, reject) {
            var reader = new FileReader();
            reader.onload = function () { resolve(reader.result); };
            reader.onerror = function () { reject(reader.error); };
            reader.readAsDataURL(file);
        });
    }

    function wireProfileForm() {
        var $form = $('#profile-form');
        if ($form.length === 0) {
            return;
        }

        $form.on('submit', function (event) {
            event.preventDefault();

            var $btn        = $('#profile-submit-btn');
            var displayName = $('#profile-display-name').val();
            var fileInput   = document.getElementById('profile-avatar');
            var file        = (fileInput && fileInput.files && fileInput.files[0]) || null;

            setBusy($btn, true, 'Saving...');

            var payloadPromise;
            if (file) {
                /* 🛡️ A 2 MB client-side pre-check (the server enforces the
                   real limit regardless - this only avoids a pointless
                   base64-encode-and-upload round trip for an obviously
                   oversized file). */
                if (file.size > 2 * 1024 * 1024) {
                    setBusy($btn, false, '');
                    showAlert('profile-ajax-alert', 'Avatar image is too large. Maximum allowed size is 2.0 MB.', 'danger');
                    return;
                }
                payloadPromise = readFileAsDataUrl(file).then(function (dataUrl) {
                    return { display_name: displayName, avatar_base64: dataUrl };
                });
            } else {
                payloadPromise = Promise.resolve({ display_name: displayName });
            }

            payloadPromise.then(function (payload) {
                CueRCode.ajax({
                    url: API_BASE + '/account/profile',
                    method: 'PATCH',
                    contentType: 'application/json',
                    data: JSON.stringify(payload),
                    success: function (response) {
                        showAlert('profile-ajax-alert', response.message, 'success');
                        if (file) {
                            /* 🔄 The avatar priority chain may now resolve
                               differently (custom upload now takes
                               precedence) - a full reload is the simplest
                               way to guarantee every avatar <img> on the
                               page (including the navbar) reflects it. */
                            window.setTimeout(function () { window.location.reload(); }, 600);
                        }
                    },
                    error: function (xhr, status) {
                        if (status === 'abort') {
                            return;
                        }
                        showAlert('profile-ajax-alert', errorMessageFrom(xhr, 'Your profile could not be updated. Please try again.'), 'danger');
                    },
                    complete: function () {
                        setBusy($btn, false, '');
                    }
                });
            }).catch(function () {
                setBusy($btn, false, '');
                showAlert('profile-ajax-alert', 'The selected image could not be read. Please try a different file.', 'danger');
            });
        });
    }

    function wireAvatarRemoveButton() {
        var $btn = $('#avatar-remove-btn');
        if ($btn.length === 0) {
            return;
        }

        $btn.closest('form').on('submit', function (event) {
            event.preventDefault();
            setBusy($btn, true, 'Removing...');

            CueRCode.ajax({
                url: API_BASE + '/account/profile',
                method: 'PATCH',
                contentType: 'application/json',
                data: JSON.stringify({ remove_avatar: true }),
                success: function (response) {
                    showAlert('profile-ajax-alert', response.message, 'success');
                    window.setTimeout(function () { window.location.reload(); }, 600);
                },
                error: function (xhr, status) {
                    if (status === 'abort') {
                        return;
                    }
                    showAlert('profile-ajax-alert', errorMessageFrom(xhr, 'Your avatar could not be removed. Please try again.'), 'danger');
                },
                complete: function () {
                    setBusy($btn, false, '');
                }
            });
        });
    }

    // ==========================================================================
    // 🔑 Change password form
    // ==========================================================================

    function wireChangePasswordForm() {
        var $form = $('#change-password-form');
        if ($form.length === 0) {
            return;
        }

        $form.on('submit', function (event) {
            event.preventDefault();

            var currentPassword = $('#cp-current').val();
            var newPassword     = $('#cp-new').val();
            var confirmPassword = $('#cp-confirm').val();

            if (newPassword !== confirmPassword) {
                showAlert('password-ajax-alert', 'New passwords do not match.', 'danger');
                return;
            }

            var $btn = $('#change-password-btn');
            setBusy($btn, true, 'Changing password...');

            CueRCode.ajax({
                url: API_BASE + '/account/change-password',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ current_password: currentPassword, new_password: newPassword }),
                success: function (response) {
                    showAlert('password-ajax-alert', response.message, 'success');
                    $form[0].reset();
                    refreshSessionsList();
                },
                error: function (xhr, status) {
                    if (status === 'abort') {
                        return;
                    }
                    showAlert('password-ajax-alert', errorMessageFrom(xhr, 'Your password could not be changed. Please try again.'), 'danger');
                },
                complete: function () {
                    setBusy($btn, false, '');
                }
            });
        });
    }

    // ==========================================================================
    // 🔐 Sessions list (revoke one; re-render after any change)
    // ==========================================================================

    /**
     * 🔄 Re-fetch and re-render the caller's own active sessions list.
     */
    function refreshSessionsList() {
        var $list = $('#sessions-list');
        if ($list.length === 0) {
            return;
        }

        CueRCode.ajax({
            url: API_BASE + '/account/sessions',
            method: 'GET',
            success: function (response) {
                if (!response.success || !response.data || !response.data.sessions) {
                    return;
                }
                renderSessionsList(response.data.sessions);
            }
        });
    }

    /**
     * 🖼️ Render the sessions <ul> from a JSON sessions array (fully
     * escaped - no raw HTML from the server is ever inserted directly).
     *
     * @param {Array} sessions
     */
    function renderSessionsList(sessions) {
        var $list = $('#sessions-list');
        $list.empty();

        if (sessions.length === 0) {
            $list.replaceWith('<p class="text-body-secondary" id="sessions-list">No active sessions found.</p>');
            return;
        }

        sessions.forEach(function (session) {
            var $li = $('<li class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2"></li>')
                .attr('data-session-id', session.id);

            var $info = $('<div></div>');
            var $line1 = $('<div></div>');
            if (session.is_current) {
                $line1.append($('<span class="badge text-bg-success me-1">This device</span>'));
            }
            $line1.append($('<code></code>').text(session.masked_id));
            var $line2 = $('<small class="text-body-secondary"></small>')
                .text((session.ip || 'Unknown IP') + ' · ' + (session.user_agent || 'Unknown device') + ' · Last seen ' + session.last_seen_at);

            $info.append($line1).append($line2);

            var $revokeBtn = $('<button type="button" class="btn btn-sm btn-outline-secondary revoke-session-btn"></button>')
                .attr('data-session-id', session.id)
                .attr('data-is-current', session.is_current ? '1' : '0')
                .html('<i class="fa-solid fa-xmark me-1" aria-hidden="true"></i> Revoke');

            $li.append($info).append($revokeBtn);
            $list.append($li);
        });
    }

    function wireSessionRevoke() {
        // 📌 Event delegation - both the server-rendered <form> buttons AND
        // any JS-re-rendered <button> (renderSessionsList()) are covered.
        $(document).on('submit', '.revoke-session-form', function (event) {
            event.preventDefault();
            var sessionId = $(this).find('input[name="session_id"]').val();
            performSessionRevoke(sessionId, $(this).find('button[type="submit"]'));
        });

        $(document).on('click', '.revoke-session-btn', function () {
            var $btn = $(this);
            performSessionRevoke($btn.data('session-id'), $btn);
        });
    }

    function performSessionRevoke(sessionId, $btn) {
        if (!sessionId) {
            return;
        }

        setBusy($btn, true, 'Revoking...');

        CueRCode.ajax({
            url: API_BASE + '/account/sessions/' + encodeURIComponent(sessionId),
            method: 'DELETE',
            success: function (response) {
                showAlert('session-ajax-alert', response.message, 'success');

                var $item = $('[data-session-id="' + sessionId + '"]').closest('li');
                var wasCurrent = $item.find('.badge.text-bg-success').length > 0
                    || $btn.data('is-current') === '1';

                if (wasCurrent) {
                    /* 🔐 Revoking THIS device's own session logs the browser
                       out server-side - follow with a redirect rather than
                       leaving a now-unauthenticated page interactive. */
                    window.location.href = '/login/';
                    return;
                }

                $item.fadeOut(200, function () { $(this).remove(); });
            },
            error: function (xhr, status) {
                if (status === 'abort') {
                    return;
                }
                showAlert('session-ajax-alert', errorMessageFrom(xhr, 'That session could not be revoked.'), 'danger');
            },
            complete: function () {
                setBusy($btn, false, '');
            }
        });
    }

    // ==========================================================================
    // 📚 Saved codes - delete
    // ==========================================================================

    /**
     * 📭 Tidy the saved-codes library after one card has been removed.
     *
     * - Lowers the "N / limit saved" number in the badge by one.
     * - If that was the LAST saved code, hides the (now empty) grid and
     *   reveals the server-rendered empty state (#saved-codes-empty - the same
     *   message and "Create a QR code" button visitors see on a fresh page
     *   load), so there is no blank gap and no need to reload.
     * - If this page of results is now empty but other pages still hold codes,
     *   reloads so the visitor lands on a page that has something on it.
     */
    function afterSavedCodeRemoved() {
        /* 🔢 Keep the usage badge honest (total saved, across all pages) */
        var $count = $('#saved-codes-count');
        var remainingTotal = null;
        if ($count.length > 0) {
            var current = parseInt($count.text(), 10);
            if (!isNaN(current)) {
                remainingTotal = Math.max(0, current - 1);
                $count.text(String(remainingTotal));
            }
        }

        /* 🔍 How many cards are still on this page? */
        var remainingOnPage = $('#saved-codes-list > .col').length;
        if (remainingTotal === null) {
            /* 📌 No badge number to read - fall back to this page's cards */
            remainingTotal = remainingOnPage;
        }

        if (remainingOnPage > 0) {
            return;
        }

        if (remainingTotal > 0) {
            /* 📄 This page is empty but later/earlier pages are not */
            window.location.reload();
            return;
        }

        /* 📭 Nothing left at all: swap the empty grid for the empty state */
        $('#saved-codes-list').remove();
        $('#library-section nav[aria-label="Saved codes pagination"]').remove();

        var $empty = $('#saved-codes-empty');
        if ($empty.length > 0) {
            $empty.removeClass('d-none');
            /* ♿ The focused Delete button just disappeared - move focus to the
               empty-state block so keyboard and screen-reader users are not
               dropped back at the top of the page. */
            $empty.trigger('focus');
        }
    }

    function wireDeleteQrForms() {
        $(document).on('submit', '.delete-qr-form', function (event) {
            event.preventDefault();

            var $form = $(this);
            var uuid  = $form.find('input[name="uuid"]').val();
            var $btn  = $form.find('button[type="submit"]');

            if (!uuid) {
                return;
            }

            if (!window.confirm('Delete this saved QR code? This cannot be undone.')) {
                return;
            }

            setBusy($btn, true, 'Deleting...');

            CueRCode.ajax({
                url: API_BASE + '/qrcodes/' + encodeURIComponent(uuid),
                method: 'DELETE',
                success: function (response) {
                    showAlert('library-ajax-alert', response.message, 'success');
                    $form.closest('.col').fadeOut(200, function () {
                        $(this).remove();
                        afterSavedCodeRemoved();
                    });
                },
                error: function (xhr, status) {
                    if (status === 'abort') {
                        return;
                    }
                    showAlert('library-ajax-alert', errorMessageFrom(xhr, 'That QR code could not be deleted.'), 'danger');
                },
                complete: function () {
                    setBusy($btn, false, '');
                }
            });
        });
    }

    // ==========================================================================
    // 🔑 API keys (Epic E7 stage 1, #54) - create, rotate, revoke
    // ==========================================================================
    // Every dynamic value below is inserted via jQuery .text()/.val() (never
    // .html()/innerHTML with server-supplied data), which already escapes
    // safely - no separate manual-escaping helper is needed in this file.
    // ==========================================================================

    /**
     * 🖼️ Render the "one-time secret" alert for a just-created/-rotated key.
     * The raw key is shown EXACTLY ONCE - this function is only ever called
     * with a response the server just sent back from POST /keys or
     * POST /keys/{id}/rotate; it is never re-fetched.
     *
     * @param {Object} keyData {id, api_key, prefix, name}
     */
    function renderApiKeyOneTimeSecret(keyData) {
        var $existing = $('#api-key-one-time-secret');
        if ($existing.length > 0) {
            $existing.remove();
        }

        var $alert = $(
            '<div class="alert alert-warning" role="alert" id="api-key-one-time-secret">'
                + '<p class="fw-bold mb-1">'
                + '<i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>'
                + 'Copy this key now - you will not be able to see it again.'
                + '</p>'
                + '<div class="input-group">'
                + '<input type="text" class="form-control font-monospace" readonly id="api-key-one-time-value" aria-label="Your new API key - copy it now">'
                + '<button type="button" class="btn btn-outline-secondary" id="api-key-copy-btn" data-copy-target="api-key-one-time-value" aria-label="Copy API key to clipboard">'
                + '<i class="fa-solid fa-copy me-1" aria-hidden="true"></i> Copy'
                + '</button>'
                + '</div>'
                + '<p class="form-text mb-0 mt-1" id="api-key-one-time-meta"></p>'
                + '</div>'
        );

        $alert.find('#api-key-one-time-value').val(keyData.api_key);
        $alert.find('#api-key-one-time-meta').text(
            'Name: ' + (keyData.name || '') + ' · Prefix: ' + (keyData.prefix || '')
        );

        $('#api-key-ajax-alert').after($alert);
        $alert[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    /**
     * 📭 The "no API keys yet" empty state, as an HTML string.
     *
     * Matches the block account/index.php renders on the server (icon, two
     * lines of text and - when the "Create key" form is on the page - a primary
     * button that jumps to the key-name field). It holds only fixed text, no
     * server-supplied values, so building it as a string is safe.
     *
     * @returns {string}
     */
    function apiKeysEmptyStateHtml() {
        var html = '<div class="text-center py-4" id="api-keys-list-empty">'
            + '<p class="mb-3"><i class="fa-solid fa-key fa-2x text-cuercode-primary opacity-75" aria-hidden="true"></i></p>'
            + '<p class="fw-semibold mb-1">You have not created any API keys yet.</p>'
            + '<p class="text-body-secondary mb-3">An API key lets your own software create QR codes through the CueRCode API.</p>';

        /* 📌 Only offer the button if there is a form to jump to (not at the plan limit) */
        if ($('#api-key-create-form').length > 0) {
            html += '<a href="#api-key-name" class="btn btn-primary">'
                + '<i class="fa-solid fa-plus me-1" aria-hidden="true"></i> Name your first key</a>';
        }

        html += '</div>';
        return html;
    }

    /**
     * 🖼️ Render the API-keys list from a JSON keys array (fully escaped - no
     * raw HTML from the server is ever inserted directly).
     *
     * @param {Array} keys
     */
    function renderApiKeysList(keys) {
        var $list = $('#api-keys-list');
        var $empty = $('#api-keys-list-empty');

        if (keys.length === 0) {
            if ($list.length > 0) {
                $list.replaceWith(apiKeysEmptyStateHtml());
            }
            return;
        }

        if ($list.length === 0 && $empty.length > 0) {
            $empty.replaceWith('<div class="d-flex flex-column gap-2" id="api-keys-list"></div>');
            $list = $('#api-keys-list');
        }

        $list.empty();

        keys.forEach(function (key) {
            var $item = $('<div class="border rounded p-3"></div>').attr('data-api-key-id', key.id);
            var $row = $('<div class="d-flex justify-content-between align-items-start flex-wrap gap-2"></div>');
            var $info = $('<div></div>');

            var $title = $('<div class="fw-semibold"></div>').text(key.name || '');
            if (key.is_active) {
                $title.append(' ').append($('<span class="badge text-bg-success ms-1">Active</span>'));
            } else {
                $title.append(' ').append($('<span class="badge text-bg-secondary ms-1">Revoked</span>'));
            }

            var $meta = $('<div class="small text-body-secondary"></div>');
            var lastUsedText = key.last_used_at ? key.last_used_at : 'never';
            $meta.append($('<code></code>').text(key.prefix + '…'));
            $meta.append(document.createTextNode(
                ' · Created ' + (key.created_at || '')
                + ' · Last used ' + lastUsedText
                + ' · ' + key.rate_limits.per_min + '/min, ' + key.rate_limits.per_day + '/day'
            ));

            var $scopes = $('<div class="mt-1"></div>');
            if (!key.scopes || key.scopes.length === 0) {
                $scopes.append($('<span class="badge text-bg-light border text-body-secondary">No scopes granted</span>'));
            } else {
                key.scopes.forEach(function (scope) {
                    $scopes.append($('<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle me-1"></span>').text(scope));
                });
            }

            $info.append($title).append($meta).append($scopes);
            $row.append($info);

            if (key.is_active) {
                var $actions = $('<div class="d-flex gap-2 flex-wrap"></div>');
                var $rotateBtn = $('<button type="button" class="btn btn-sm btn-outline-primary rotate-api-key-btn"></button>')
                    .attr('data-api-key-id', key.id)
                    .html('<i class="fa-solid fa-arrows-rotate me-1" aria-hidden="true"></i> Rotate');
                var $revokeBtn = $('<button type="button" class="btn btn-sm btn-outline-danger revoke-api-key-btn"></button>')
                    .attr('data-api-key-id', key.id)
                    .html('<i class="fa-solid fa-trash me-1" aria-hidden="true"></i> Revoke');
                $actions.append($rotateBtn).append($revokeBtn);
                $row.append($actions);
            }

            $item.append($row);
            $list.append($item);
        });
    }

    /**
     * 🔄 Re-fetch and re-render the caller's own API keys list.
     */
    function refreshApiKeysList() {
        CueRCode.ajax({
            url: API_BASE + '/keys',
            method: 'GET',
            success: function (response) {
                if (!response.success || !response.data || !response.data.keys) {
                    return;
                }
                renderApiKeysList(response.data.keys);
            }
        });
    }

    function wireApiKeyCreateForm() {
        var $form = $('#api-key-create-form');
        if ($form.length === 0) {
            return;
        }

        $form.on('submit', function (event) {
            event.preventDefault();

            var $btn  = $('#api-key-create-btn');
            var name  = $('#api-key-name').val();
            var scopes = $form.find('input[name="api_key_scopes[]"]:checked').map(function () {
                return $(this).val();
            }).get();

            setBusy($btn, true, 'Creating...');

            CueRCode.ajax({
                url: API_BASE + '/keys',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ name: name, scopes: scopes }),
                success: function (response) {
                    showAlert('api-key-ajax-alert', response.message, 'success');
                    if (response.data) {
                        renderApiKeyOneTimeSecret(response.data);
                    }
                    $form[0].reset();
                    refreshApiKeysList();
                },
                error: function (xhr, status) {
                    if (status === 'abort') {
                        return;
                    }
                    showAlert('api-key-ajax-alert', errorMessageFrom(xhr, 'Your API key could not be created. Please try again.'), 'danger');
                },
                complete: function () {
                    setBusy($btn, false, '');
                }
            });
        });
    }

    function wireApiKeyRotate() {
        $(document).on('submit', '.rotate-api-key-form', function (event) {
            event.preventDefault();
            var apiKeyId = $(this).find('input[name="api_key_id"]').val();
            performApiKeyRotate(apiKeyId, $(this).find('button[type="submit"]'));
        });

        $(document).on('click', '.rotate-api-key-btn', function () {
            var $btn = $(this);
            performApiKeyRotate($btn.data('api-key-id'), $btn);
        });
    }

    function performApiKeyRotate(apiKeyId, $btn) {
        if (!apiKeyId) {
            return;
        }

        if (!window.confirm('Rotate this key? The OLD key will stop working immediately.')) {
            return;
        }

        setBusy($btn, true, 'Rotating...');

        CueRCode.ajax({
            url: API_BASE + '/keys/' + encodeURIComponent(apiKeyId) + '/rotate',
            method: 'POST',
            success: function (response) {
                showAlert('api-key-ajax-alert', response.message, 'success');
                if (response.data) {
                    renderApiKeyOneTimeSecret(response.data);
                }
                refreshApiKeysList();
            },
            error: function (xhr, status) {
                if (status === 'abort') {
                    return;
                }
                showAlert('api-key-ajax-alert', errorMessageFrom(xhr, 'That API key could not be rotated.'), 'danger');
            },
            complete: function () {
                setBusy($btn, false, '');
            }
        });
    }

    function wireApiKeyRevoke() {
        $(document).on('submit', '.revoke-api-key-form', function (event) {
            event.preventDefault();
            var apiKeyId = $(this).find('input[name="api_key_id"]').val();
            performApiKeyRevoke(apiKeyId, $(this).find('button[type="submit"]'));
        });

        $(document).on('click', '.revoke-api-key-btn', function () {
            var $btn = $(this);
            performApiKeyRevoke($btn.data('api-key-id'), $btn);
        });
    }

    function performApiKeyRevoke(apiKeyId, $btn) {
        if (!apiKeyId) {
            return;
        }

        if (!window.confirm('Revoke this key? Anything using it will stop working immediately.')) {
            return;
        }

        setBusy($btn, true, 'Revoking...');

        CueRCode.ajax({
            url: API_BASE + '/keys/' + encodeURIComponent(apiKeyId),
            method: 'DELETE',
            success: function (response) {
                showAlert('api-key-ajax-alert', response.message, 'success');
                refreshApiKeysList();
            },
            error: function (xhr, status) {
                if (status === 'abort') {
                    return;
                }
                showAlert('api-key-ajax-alert', errorMessageFrom(xhr, 'That API key could not be revoked.'), 'danger');
            },
            complete: function () {
                setBusy($btn, false, '');
            }
        });
    }

    /**
     * 📋 Copy-to-clipboard affordance for the one-time-secret input. Uses the
     * async Clipboard API when available (secure contexts / modern
     * browsers), falling back to a select+execCommand path otherwise so the
     * feature degrades gracefully rather than silently doing nothing.
     */
    function wireApiKeyCopyButton() {
        $(document).on('click', '#api-key-copy-btn', function () {
            var $btn = $(this);
            var targetId = $btn.data('copy-target');
            var $input = $('#' + targetId);
            if ($input.length === 0) {
                return;
            }

            var value = $input.val();
            var originalHtml = $btn.html();

            function announceCopied() {
                $btn.html('<i class="fa-solid fa-check me-1" aria-hidden="true"></i> Copied');
                window.setTimeout(function () { $btn.html(originalHtml); }, 1500);
            }

            if (window.navigator.clipboard && window.navigator.clipboard.writeText) {
                window.navigator.clipboard.writeText(value).then(announceCopied, function () {
                    $input[0].select();
                    document.execCommand('copy');
                    announceCopied();
                });
            } else {
                $input[0].select();
                document.execCommand('copy');
                announceCopied();
            }
        });
    }

    // ==========================================================================
    // 🚀 Init
    // ==========================================================================

    $(document).ready(function () {
        wireProfileForm();
        wireAvatarRemoveButton();
        wireChangePasswordForm();
        wireSessionRevoke();
        wireDeleteQrForms();
        wireApiKeyCreateForm();
        wireApiKeyRotate();
        wireApiKeyRevoke();
        wireApiKeyCopyButton();
    });
}(jQuery));
