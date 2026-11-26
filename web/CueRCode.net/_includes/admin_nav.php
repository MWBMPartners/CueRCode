<?php
/**
 * ==============================================================================
 * 🛡️ CueRCode - Admin Area Section Navigation
 * ==============================================================================
 *
 * Path: _includes/admin_nav.php
 *
 * The left-hand menu shared by every page under public_html/admin/ (task 7n,
 * plan §7.1), in the same style as _includes/developers_nav.php. Only pages
 * that exist are listed - later phases add their own links here (Settings
 * in 1C, Plans in 2, and so on), so the menu never offers a dead end.
 *
 * Usage: set $adminActive to the current page's key BEFORE including this
 * file (after requireGlobalAdminPage() has let the visitor in):
 *   $adminActive = 'upgrades';
 *   require_once CUERCODE_INCLUDES_DIR . 'admin_nav.php';
 *
 * Recognised $adminActive values: 'overview', 'settings', 'plans' (every page
 * under /admin/plans/, task 7n Phase 2), 'upgrades', 'reauth'. Anything else
 * leaves every link inactive.
 *
 * @package     CueRCode
 * @subpackage  Admin
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-24 (task 7n Phase 1B, #31)
 * @see         _includes/developers_nav.php - the pattern this follows
 * @see         public_html/admin/index.php
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
// ⚙️ Active page
// ==============================================================================
if (isset($adminActive) && is_string($adminActive)) {
    $adminNavActive = $adminActive;
} else {
    $adminNavActive = '';
}

// ==============================================================================
// 📋 Link table: key => [label, href, icon] (labels are plain text, escaped below)
// ==============================================================================
$adminNavLinks = [
    'overview' => ['Overview',          '/admin/',          'fa-solid fa-gauge'],
    'settings' => ['Settings',          '/admin/settings/', 'fa-solid fa-gear'],
    'plans'    => ['Plans and prices',  '/admin/plans/',    'fa-solid fa-tags'],
    'upgrades' => ['Database upgrades', '/admin/upgrades/', 'fa-solid fa-database'],
    'reauth'   => ['Confirm password',  '/admin/reauth/',   'fa-solid fa-key'],
];
?>
<nav aria-label="Admin sections" class="mb-4 mb-lg-0">
    <div class="list-group">
        <?php foreach ($adminNavLinks as $adminNavKey => $adminNavLink) {
            $adminNavIsActive = ($adminNavKey === $adminNavActive);
            $adminNavClasses  = 'list-group-item list-group-item-action d-flex align-items-center gap-2';
            if ($adminNavIsActive) {
                $adminNavClasses .= ' active';
            }
        ?>
        <a href="<?php echo htmlspecialchars($adminNavLink[1], ENT_QUOTES, 'UTF-8'); ?>"
           class="<?php echo htmlspecialchars($adminNavClasses, ENT_QUOTES, 'UTF-8'); ?>"
           <?php if ($adminNavIsActive) { ?>aria-current="page"<?php } ?>>
            <i class="<?php echo htmlspecialchars($adminNavLink[2], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i>
            <?php echo htmlspecialchars($adminNavLink[0], ENT_QUOTES, 'UTF-8'); ?>
        </a>
        <?php } ?>
    </div>
</nav>
