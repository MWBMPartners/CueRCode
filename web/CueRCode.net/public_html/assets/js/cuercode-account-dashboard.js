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
 * @package     CueRCode
 * @subpackage  Frontend
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E3 stage 2b, #52/#23/#24)
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
                    $form.closest('.col').fadeOut(200, function () { $(this).remove(); });
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
    // 🚀 Init
    // ==========================================================================

    $(document).ready(function () {
        wireProfileForm();
        wireAvatarRemoveButton();
        wireChangePasswordForm();
        wireSessionRevoke();
        wireDeleteQrForms();
    });
}(jQuery));
