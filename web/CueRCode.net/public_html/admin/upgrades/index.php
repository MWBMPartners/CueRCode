<?php
/**
 * ==============================================================================
 * 🗄️ CueRCode - Admin: Database upgrades
 * ==============================================================================
 *
 * Path: public_html/admin/upgrades/index.php
 *
 * For owners without command-line access to the server (task 7n Phase 1B,
 * plan §5.1 and §7.1): lists the numbered database upgrades that are waiting,
 * in plain English, warns about upgrade files that changed after they were
 * applied, and applies the waiting ones with one button. It is the same
 * engine the command-line tool (_includes/cli/migrate.php --apply) and the
 * web installer use (_functions/fn_migrations.php).
 *
 * Safety:
 *   - Global Admins only (requireGlobalAdminPage(), first), and applying
 *     needs a fresh password confirmation, the CSRF token and a same-site
 *     form, like every admin change.
 *   - Applying also needs the "I have a backup" box ticked - checked by the
 *     server, so it works the same with JavaScript off (plan §7.5).
 *   - It works even before the admin change log table exists (the gate only
 *     needs the user tables): the upgrade is recorded in tblSchemaMigrations
 *     as "admin:<userID>", and the audit row (schema.migrate) is written
 *     once the upgrade has created the change log table.
 *   - Raw database error text is never shown on the page; the full detail
 *     goes to the server error log (as the installer does).
 *
 * @package     CueRCode
 * @subpackage  Admin
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-24 (task 7n Phase 1B, #31)
 * @see         _functions/fn_admin.php - adminUpgradeOverview(), adminRunUpgrades()
 * @see         _functions/fn_migrations.php - the upgrade engine
 * @see         .claude/plans/7n-admin-commerce-plan.md §5.1, §7.1
 */

// ==============================================================================
// 🚀 Load the application, then the admin functions, then the gate - FIRST
// ==============================================================================
require_once __DIR__ . '/../../../_includes/bootstrap.php';
require_once CUERCODE_FUNCTIONS_DIR . 'fn_admin.php';

$adminUser = requireGlobalAdminPage('/admin/upgrades/');

$upgradesMessages = [];

// ==============================================================================
// 📤 POST: apply the waiting upgrades
// ==============================================================================
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_upgrades'])) {
    $upgradesCheck = adminCheckPost($adminUser, true);

    if (!$upgradesCheck['ok']) {
        $upgradesMessages[] = ['type' => 'danger', 'text' => $upgradesCheck['message']];
    } elseif (!isset($_POST['confirm_backup']) || $_POST['confirm_backup'] !== '1') {
        $upgradesMessages[] = [
            'type' => 'danger',
            'text' => 'Tick the box to say you have a recent backup of the database (or accept the risk) before applying the upgrades.',
        ];
    } else {
        $upgradesRun = adminRunUpgrades($adminUser);
        adminReauthTouch($adminUser);

        $upgradesLines = [];
        foreach ($upgradesRun['lines'] as $upgradesLine) {
            $upgradesLines[] = (string) $upgradesLine;
        }

        if ($upgradesRun['ok']) {
            adminFlashAdd('success', 'Database upgrades finished.', $upgradesLines);
        } elseif ($upgradesRun['status'] === 'busy') {
            adminFlashAdd('warning', 'Another upgrade is running right now. Wait a minute and try again.', $upgradesLines);
        } else {
            adminFlashAdd('danger', 'The upgrades did not finish. Nothing that failed was recorded, so it is safe to try again after the problem is fixed.', $upgradesLines);
        }

        adminRedirect('/admin/upgrades/');
    }
}

// ==============================================================================
// 📋 Current state
// ==============================================================================
$upgradesOverview     = adminUpgradeOverview();
$upgradesWaiting      = count($upgradesOverview['pending']) + count($upgradesOverview['missingTables']);
$upgradesCanEdit      = adminReauthIsCurrent($adminUser);
$upgradesTimezone     = adminDisplayTimezone();
$upgradesTimezoneText = adminTimezoneLabel($upgradesTimezone);

// ==============================================================================
// 📄 Page variables
// ==============================================================================
$pageTitle       = 'Database upgrades';
$pageDescription = 'Apply waiting CueRCode database upgrades from the admin area.';
$pageNoIndex     = true;
$bodyClass       = 'page-admin page-admin-upgrades';
$pageCSS         = [];
$pageJS          = ['/assets/js/cuercode-admin.js'];

$adminActive      = 'upgrades';
$upgradesMessages = array_merge(adminFlashTake(), $upgradesMessages);

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <section class="py-5">
        <div class="container">
            <div class="row">
                <div class="col-lg-3">
                    <?php require_once CUERCODE_INCLUDES_DIR . 'admin_nav.php'; ?>
                </div>

                <div class="col-lg-9">
                    <h1 class="h2 fw-bold mb-3">Database upgrades</h1>
                    <p class="text-body-secondary mb-4">
                        New versions of the site sometimes need extra tables, columns or starting settings in the
                        database. Each upgrade is numbered, is safe to run twice, and never removes or overwrites
                        anything you have changed.
                    </p>

                    <?php echo adminRenderMessages($upgradesMessages); ?>

                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-transparent">
                            <h2 class="h5 mb-0"><i class="fa-solid fa-list-check me-2" aria-hidden="true"></i>Waiting upgrades</h2>
                        </div>
                        <div class="card-body">
                            <?php if (!$upgradesOverview['ok']) { ?>
                                <p><?php echo adminStatusHtml('warning', 'The upgrade status could not be checked.'); ?></p>
                                <?php if ($upgradesOverview['lines'] !== []) { ?>
                                <ul class="small mb-0">
                                    <?php foreach ($upgradesOverview['lines'] as $upgradesLine) { ?>
                                    <li><?php echo adminEscape($upgradesLine); ?></li>
                                    <?php } ?>
                                </ul>
                                <?php } ?>
                            <?php } elseif ($upgradesWaiting === 0) { ?>
                                <p class="mb-0"><?php echo adminStatusHtml('good', 'Up to date - nothing is waiting.'); ?></p>
                            <?php } else { ?>
                                <?php if ($upgradesOverview['missingTables'] !== []) { ?>
                                <p class="mb-2">These starting tables are missing and will be created:</p>
                                <ul>
                                    <?php foreach ($upgradesOverview['missingTables'] as $upgradesTable) { ?>
                                    <li><code><?php echo adminEscape($upgradesTable); ?></code></li>
                                    <?php } ?>
                                </ul>
                                <?php } ?>

                                <?php if ($upgradesOverview['pending'] !== []) { ?>
                                <ol class="mb-0">
                                    <?php foreach ($upgradesOverview['pending'] as $upgradesPending) { ?>
                                    <li class="mb-2">
                                        <span class="fw-semibold"><?php echo adminEscape($upgradesPending['key']); ?></span>
                                        <?php if ($upgradesPending['description'] !== '') { ?>
                                        <br><span class="text-body-secondary"><?php echo adminEscape($upgradesPending['description']); ?></span>
                                        <?php } ?>
                                    </li>
                                    <?php } ?>
                                </ol>
                                <?php } ?>
                            <?php } ?>

                            <?php if ($upgradesOverview['warnings'] !== []) { ?>
                            <div class="alert alert-warning mt-3 mb-0" role="status">
                                <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i> Please look at these:
                                <ul class="mb-0 mt-2">
                                    <?php foreach ($upgradesOverview['warnings'] as $upgradesWarning) { ?>
                                    <li><?php echo adminEscape($upgradesWarning); ?></li>
                                    <?php } ?>
                                </ul>
                            </div>
                            <?php } ?>
                        </div>
                    </div>

                    <?php if ($upgradesOverview['ok'] && $upgradesWaiting > 0) { ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-transparent">
                            <h2 class="h5 mb-0"><i class="fa-solid fa-play me-2" aria-hidden="true"></i>Apply the upgrades</h2>
                        </div>
                        <div class="card-body">
                            <?php if ($upgradesCanEdit) { ?>
                            <form method="POST" action="/admin/upgrades/"
                                  data-admin-confirm="Apply <?php echo (int) $upgradesWaiting; ?> database upgrade(s) now?">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="apply_upgrades" value="1">
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" value="1" id="confirm-backup" name="confirm_backup"
                                           required aria-describedby="confirm-backup-help">
                                    <label class="form-check-label" for="confirm-backup">
                                        I have a recent backup of the database, or I accept the risk of upgrading without one (required)
                                    </label>
                                    <div class="form-text" id="confirm-backup-help">
                                        DreamHost's panel can make a database backup. Upgrades only add things, but a backup is still wise.
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fa-solid fa-play me-1" aria-hidden="true"></i> Apply <?php echo (int) $upgradesWaiting; ?> upgrade(s)
                                </button>
                            </form>
                            <?php } else { ?>
                            <p class="mb-3"><?php echo adminStatusHtml('locked', 'Editing is locked. Confirm your password to apply the upgrades.'); ?></p>
                            <a class="btn btn-primary" href="/admin/reauth/?next=<?php echo rawurlencode('/admin/upgrades/'); ?>">
                                <i class="fa-solid fa-unlock me-1" aria-hidden="true"></i> Unlock editing - confirm your password
                            </a>
                            <?php } ?>
                        </div>
                    </div>
                    <?php } ?>

                    <?php if ($upgradesOverview['ok'] && $upgradesOverview['applied'] !== []) { ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-transparent">
                            <h2 class="h5 mb-0"><i class="fa-solid fa-clock-rotate-left me-2" aria-hidden="true"></i>Already applied</h2>
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($upgradesOverview['applied'] as $upgradesApplied) { ?>
                                <li class="mb-2">
                                    <span class="fw-semibold"><?php echo adminEscape($upgradesApplied['key']); ?></span>
                                    <br><span class="small text-body-secondary">
                                        <?php echo adminEscape(adminFormatUtcForDisplay((string) $upgradesApplied['appliedAt'], $upgradesTimezone)); ?>
                                        (<?php echo adminEscape($upgradesTimezoneText); ?>), by <?php echo adminEscape($upgradesApplied['appliedBy']); ?>
                                    </span>
                                </li>
                                <?php } ?>
                            </ul>
                        </div>
                    </div>
                    <?php } ?>
                </div>
            </div>
        </div>
    </section>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
