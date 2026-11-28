/**
 * ==============================================================================
 * 🔄 CueRCode - jQuery local fallback
 * ==============================================================================
 *
 * Path: public_html/assets/js/cuercode-fallback-jquery.js
 *
 * Loaded straight after the CDN copy of jQuery (_includes/footer.php). If that
 * copy did not arrive, the committed copy is written into the page here, so it
 * loads before the scripts that need it. A file rather than an inline script
 * so that the admin area can refuse every inline script (#154).
 *
 * Must stay a plain (blocking) script: document.write() only works while the
 * page is still being read.
 *
 * @package     CueRCode
 * @subpackage  JavaScript
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (#154)
 */
if (typeof window.jQuery === 'undefined') {
    document.write('<script src="/assets/vendor/jquery/jquery.min.js"><\/script>');
}
