/**
 * ==============================================================================
 * ⬅️ CueRCode - "Go back" button on the "page not found" page
 * ==============================================================================
 *
 * Path: public_html/assets/js/cuercode-error.js
 *
 * Loaded by public_html/error/404.php. That page is also what a signed-in
 * person without admin access gets for an admin address, so it must work
 * under the admin policy, which refuses inline scripts (#154).
 *
 * The button starts hidden and is shown only when there is a page to return
 * to: a non-empty referrer (a link was followed) and more than one history
 * entry. Direct visits (typed address, scanned QR code, new tab) get no dead
 * button; without JavaScript it stays hidden.
 *
 * @package     CueRCode
 * @subpackage  JavaScript
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (#154)
 */
(function () {
    var backButton = document.getElementById('error-go-back');
    if (!backButton) {
        return;
    }
    if (document.referrer !== '' && window.history.length > 1) {
        backButton.hidden = false;
        backButton.addEventListener('click', function () {
            window.history.back();
        });
    }
}());
