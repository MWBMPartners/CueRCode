<?php
/**
 * ==============================================================================
 * 📚 CueRCode - Developer Portal Shared Section Navigation
 * ==============================================================================
 *
 * Path: _includes/developers_nav.php
 *
 * Renders the left-hand navigation shared by every page under
 * `public_html/developers/` (E6 developer portal, #50), so a visitor can move
 * between the portal landing page and its sub-pages from anywhere in the
 * section without going back to the index.
 *
 * Usage: set $developersActive to the current section's key BEFORE including
 * this file, e.g.:
 *   $developersActive = 'quickstart';
 *   require_once CUERCODE_INCLUDES_DIR . 'developers_nav.php';
 *
 * Recognised $developersActive values: 'overview', 'quickstart',
 * 'authentication', 'reference', 'errors', 'samples', 'recipes', 'partners'.
 * An unrecognised or missing value simply leaves every link inactive.
 *
 * @package     CueRCode
 * @subpackage  Developer Portal
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (E6 developer portal cycle, #50)
 * @see         public_html/developers/index.php
 */

// ==============================================================================
// 🚫 Direct access protection
// ==============================================================================
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $scriptFilename = $_SERVER['SCRIPT_FILENAME'];
} else {
    $scriptFilename = '';
}
if (basename($scriptFilename) === basename(__FILE__)) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied.');
}

// ==============================================================================
// ⚙️ Active section (defaults to 'overview' if unset by the including page)
// ==============================================================================
if (isset($developersActive) && is_string($developersActive)) {
    $devNavActive = $developersActive;
} else {
    $devNavActive = 'overview';
}

// ==============================================================================
// 📋 Section link table: key => [label, href, icon]
// ==============================================================================
$devNavLinks = [
    'overview'       => ['Overview',              '/developers/',                'fa-solid fa-house'],
    'quickstart'     => ['Quickstart',             '/developers/quickstart/',     'fa-solid fa-bolt'],
    'authentication' => ['Authentication',         '/developers/authentication/', 'fa-solid fa-key'],
    'reference'      => ['Endpoint Reference',     '/developers/reference/',      'fa-solid fa-list-check'],
    'errors'         => ['Errors &amp; Rate Limits', '/developers/errors/',       'fa-solid fa-triangle-exclamation'],
    'samples'        => ['Code Samples',           '/developers/samples/',        'fa-solid fa-code'],
    'recipes'        => ['Integration Recipes',    '/developers/recipes/',        'fa-solid fa-flask'],
    'partners'       => ['Planned integrations',   '/developers/partners/',       'fa-solid fa-handshake'],
];
?>
<nav aria-label="Developer portal sections" class="mb-4 mb-lg-0">
    <div class="list-group">
        <?php foreach ($devNavLinks as $devNavKey => $devNavLink) {
            $devNavIsActive = ($devNavKey === $devNavActive);
            $devNavClasses  = 'list-group-item list-group-item-action d-flex align-items-center gap-2';
            if ($devNavIsActive) {
                $devNavClasses .= ' active';
            }
        ?>
        <a href="<?php echo htmlspecialchars($devNavLink[1], ENT_QUOTES, 'UTF-8'); ?>"
           class="<?php echo htmlspecialchars($devNavClasses, ENT_QUOTES, 'UTF-8'); ?>"
           <?php if ($devNavIsActive) { ?>aria-current="page"<?php } ?>>
            <i class="<?php echo htmlspecialchars($devNavLink[2], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i>
            <?php
            // 📌 $devNavLink[0] contains a small, fixed set of hand-written
            // labels that intentionally include one HTML entity (&amp;) - it
            // is NOT dynamic/user data, so it is emitted as-is rather than
            // re-escaped (which would double-encode the entity).
            echo $devNavLink[0];
            ?>
        </a>
        <?php } ?>
        <a href="/api/docs/" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
            <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
            Swagger UI
        </a>
        <a href="/api/v1/openapi.json" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
            <i class="fa-solid fa-file-code" aria-hidden="true"></i>
            Raw openapi.json
        </a>
    </div>
</nav>
