<?php
/**
 * ==============================================================================
 * 🛡️ CueRCode - Admin Overview (Global Admins only)
 * ==============================================================================
 *
 * Path: public_html/admin/index.php
 *
 * The front page of the private admin area (task 7n Phase 1B, plan §7.1). It
 * only SHOWS things - it changes nothing:
 *   - who you are, and whether editing is unlocked (and until when);
 *   - whether database upgrades are waiting;
 *   - email: on or off, whether the Microsoft 365 details are filled in
 *     ("Set" / "Not set" - the values themselves are never shown), and the
 *     state of the email queue, with a warning if emails have been waiting
 *     long enough to suggest the cron job is not running;
 *   - the "Sign in with Microsoft / Google" providers: ready or not;
 *   - the three charging switches, which stay Off (locked) until checkout is
 *     built;
 *   - how many working Global Admins there are.
 *
 * 🚪 requireGlobalAdminPage() is the first application call (a test checks
 *    this for every admin page): not signed in → sign-in page; signed in but
 *    not a Global Admin → the ordinary 404 page.
 *
 * @package     CueRCode
 * @subpackage  Admin
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-24 (task 7n Phase 1B, #31)
 * @see         _functions/fn_admin.php - requireGlobalAdminPage() and the helpers used here
 * @see         .claude/plans/7n-admin-commerce-plan.md §7.1 (/admin/ row)
 */

// ==============================================================================
// 🚀 Load the application, then the admin functions, then the gate - FIRST
// ==============================================================================
require_once __DIR__ . '/../../_includes/bootstrap.php';
require_once CUERCODE_FUNCTIONS_DIR . 'fn_admin.php';

$adminUser = requireGlobalAdminPage('/admin/');

// ==============================================================================
// 🔓 Editing state
// ==============================================================================
$adminTimezone      = adminDisplayTimezone();
$adminTimezoneLabel = adminTimezoneLabel($adminTimezone);
$adminSecondsLeft   = adminReauthSecondsLeftForAdmin($adminUser);
$adminUnlockedUntil = '';
if ($adminSecondsLeft > 0) {
    $adminUnlockedUntil = adminFormatClockTime(time() + $adminSecondsLeft, $adminTimezone);
}

// ==============================================================================
// 🗄️ Database upgrades
// ==============================================================================
$adminUpgrade = adminUpgradeOverview();
$adminWaitingCount = count($adminUpgrade['pending']) + count($adminUpgrade['missingTables']);

// ==============================================================================
// 📧 Email
// ==============================================================================
$adminEmailEnabled = false;
if (function_exists('getConfig') && (int) getConfig('email_enabled', 0) === 1) {
    $adminEmailEnabled = true;
}

$adminEmailSecrets = [
    'graph_tenant_id'     => 'Microsoft 365 tenant ID',
    'graph_client_id'     => 'App (client) ID',
    'graph_client_secret' => 'App secret',
];
$adminEmailSecretState = [];
foreach ($adminEmailSecrets as $adminSecretKey => $adminSecretLabel) {
    $adminEmailSecretState[$adminSecretKey] = adminSettingHasValue($adminSecretKey);
}
$adminSenderSet = adminSettingHasValue('graph_sender_mailbox');

$adminQueueCounts = null;
if (function_exists('countEmailQueueRowsByStatus')) {
    $adminQueueCounts = countEmailQueueRowsByStatus();
}

$adminOverdueCount = null;
if (function_exists('countOverdueEmailQueueRows')) {
    $adminOverdueCount = countOverdueEmailQueueRows(CUERCODE_ADMIN_EMAIL_OVERDUE_MINUTES);
}

// ==============================================================================
// 🔐 Sign-in providers (never shows a secret - only "ready" or not)
// ==============================================================================
$adminSsoMasterOn = false;
if (function_exists('getConfig') && (int) getConfig('sso_enabled', 0) === 1) {
    $adminSsoMasterOn = true;
}

$adminSsoProviders = [];
if (function_exists('ssoProviderUiMeta')) {
    foreach (['microsoft', 'google'] as $adminSsoProvider) {
        $adminSsoProviders[] = ssoProviderUiMeta($adminSsoProvider);
    }
}

// ==============================================================================
// 💳 Charging switches - shown, never changed here (plan R8)
// ==============================================================================
$adminChargingSwitches = [
    'billing_price_points_enabled' => 'Prices from the price list',
    'billing_discounts_enabled'    => 'Discount codes',
    'billing_payg_enabled'         => 'Pay-as-you-go credits',
];
$adminChargingState = [];
foreach ($adminChargingSwitches as $adminSwitchKey => $adminSwitchLabel) {
    $adminSwitchOn = false;
    if (function_exists('getConfig') && (int) getConfig($adminSwitchKey, 0) === 1) {
        $adminSwitchOn = true;
    }
    $adminChargingState[$adminSwitchKey] = $adminSwitchOn;
}

// ==============================================================================
// 👥 Global Admins
// ==============================================================================
$adminCount = adminCountGlobalAdmins();

// ==============================================================================
// 📄 Page variables
// ==============================================================================
$pageTitle       = 'Admin overview';
$pageDescription = 'Private overview of the CueRCode site settings for Global Admins.';
$pageNoIndex     = true;
$bodyClass       = 'page-admin page-admin-overview';
$pageCSS         = [];
$pageJS          = ['/assets/js/cuercode-admin.js'];

$adminActive   = 'overview';
$adminMessages = adminFlashTake();

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <section class="py-5">
        <div class="container">
            <div class="row">
                <div class="col-lg-3">
                    <?php require_once CUERCODE_INCLUDES_DIR . 'admin_nav.php'; ?>
                </div>

                <div class="col-lg-9">
                    <h1 class="h2 fw-bold mb-3">Admin overview</h1>
                    <p class="text-body-secondary mb-4">
                        A summary of how the site is set up. Nothing on this page changes anything.
                    </p>

                    <?php echo adminRenderMessages($adminMessages); ?>

                    <?php /* 👤 Your access */ ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-transparent">
                            <h2 class="h5 mb-0"><i class="fa-solid fa-user-shield me-2" aria-hidden="true"></i>Your access</h2>
                        </div>
                        <div class="card-body">
                            <dl class="row mb-0">
                                <dt class="col-sm-4">Signed in as</dt>
                                <dd class="col-sm-8 text-break">
                                    <?php if ($adminUser['displayName'] !== '') { ?>
                                        <?php echo adminEscape($adminUser['displayName']); ?> (<?php echo adminEscape($adminUser['email']); ?>)
                                    <?php } else { ?>
                                        <?php echo adminEscape($adminUser['email']); ?>
                                    <?php } ?>
                                </dd>
                                <dt class="col-sm-4">Role</dt>
                                <dd class="col-sm-8">Global Admin</dd>
                                <dt class="col-sm-4">Editing</dt>
                                <dd class="col-sm-8">
                                    <?php if ($adminSecondsLeft > 0) { ?>
                                        <?php echo adminStatusHtml('good', 'Unlocked until ' . $adminUnlockedUntil . ' (' . $adminTimezoneLabel . ')'); ?>
                                    <?php } else { ?>
                                        <?php echo adminStatusHtml('locked', 'Locked - confirm your password to make changes'); ?>
                                    <?php } ?>
                                </dd>
                            </dl>
                            <?php if ($adminSecondsLeft <= 0) { ?>
                            <a class="btn btn-primary mt-3" href="/admin/reauth/?next=<?php echo rawurlencode('/admin/'); ?>">
                                <i class="fa-solid fa-unlock me-1" aria-hidden="true"></i> Unlock editing
                            </a>
                            <?php } ?>
                        </div>
                    </div>

                    <?php /* 🗄️ Database upgrades */ ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-transparent">
                            <h2 class="h5 mb-0"><i class="fa-solid fa-database me-2" aria-hidden="true"></i>Database upgrades</h2>
                        </div>
                        <div class="card-body">
                            <?php if (!$adminUpgrade['ok']) { ?>
                                <p class="mb-2"><?php echo adminStatusHtml('warning', 'The upgrade status could not be checked.'); ?></p>
                            <?php } elseif ($adminWaitingCount === 0) { ?>
                                <p class="mb-2"><?php echo adminStatusHtml('good', 'Up to date'); ?></p>
                            <?php } else { ?>
                                <p class="mb-2"><?php echo adminStatusHtml('warning', $adminWaitingCount . ' upgrade(s) waiting. Until they are applied, settings cannot be changed.'); ?></p>
                            <?php } ?>
                            <a href="/admin/upgrades/">See database upgrades</a>
                        </div>
                    </div>

                    <?php /* 📧 Email */ ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-transparent">
                            <h2 class="h5 mb-0"><i class="fa-solid fa-envelope me-2" aria-hidden="true"></i>Email</h2>
                        </div>
                        <div class="card-body">
                            <dl class="row mb-0">
                                <dt class="col-sm-5">Sending emails</dt>
                                <dd class="col-sm-7">
                                    <?php if ($adminEmailEnabled) { ?>
                                        <?php echo adminStatusHtml('good', 'On'); ?>
                                    <?php } else { ?>
                                        <?php echo adminStatusHtml('bad', 'Off - nobody can verify a new account yet'); ?>
                                    <?php } ?>
                                </dd>
                                <?php foreach ($adminEmailSecrets as $adminSecretKey => $adminSecretLabel) { ?>
                                <dt class="col-sm-5"><?php echo adminEscape($adminSecretLabel); ?></dt>
                                <dd class="col-sm-7">
                                    <?php if ($adminEmailSecretState[$adminSecretKey]) { ?>
                                        <?php echo adminStatusHtml('good', 'Set'); ?>
                                    <?php } else { ?>
                                        <?php echo adminStatusHtml('bad', 'Not set'); ?>
                                    <?php } ?>
                                </dd>
                                <?php } ?>
                                <dt class="col-sm-5">Sending mailbox</dt>
                                <dd class="col-sm-7">
                                    <?php if ($adminSenderSet) { ?>
                                        <?php echo adminStatusHtml('good', 'Set'); ?>
                                    <?php } else { ?>
                                        <?php echo adminStatusHtml('bad', 'Not set'); ?>
                                    <?php } ?>
                                </dd>
                                <dt class="col-sm-5">Emails in the queue</dt>
                                <dd class="col-sm-7">
                                    <?php if ($adminQueueCounts === null) { ?>
                                        <?php echo adminStatusHtml('warning', 'Could not be counted'); ?>
                                    <?php } else { ?>
                                        <ul class="list-unstyled mb-0">
                                            <li>Waiting to send: <?php echo (int) ($adminQueueCounts['queued'] + $adminQueueCounts['sending']); ?></li>
                                            <li>Sent: <?php echo (int) $adminQueueCounts['sent']; ?></li>
                                            <li>Failed or given up: <?php echo (int) $adminQueueCounts['failed']; ?></li>
                                            <li>Skipped (link expired before sending): <?php echo (int) $adminQueueCounts['skipped']; ?></li>
                                        </ul>
                                    <?php } ?>
                                </dd>
                            </dl>
                            <?php if ($adminOverdueCount !== null && $adminOverdueCount > 0) { ?>
                            <div class="alert alert-warning mt-3 mb-0" role="status">
                                <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                                <?php echo (int) $adminOverdueCount; ?> email(s) have been waiting more than <?php echo (int) CUERCODE_ADMIN_EMAIL_OVERDUE_MINUTES; ?> minutes.
                                The email cron job may not be running.
                            </div>
                            <?php } ?>
                            <p class="small text-body-secondary mt-3 mb-0">
                                The email details are entered on the settings screen, which is the next part of the admin area to be built.
                            </p>
                        </div>
                    </div>

                    <?php /* 🔐 Sign-in providers */ ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-transparent">
                            <h2 class="h5 mb-0"><i class="fa-solid fa-right-to-bracket me-2" aria-hidden="true"></i>Sign in with Microsoft or Google</h2>
                        </div>
                        <div class="card-body">
                            <dl class="row mb-0">
                                <dt class="col-sm-5">Main switch</dt>
                                <dd class="col-sm-7">
                                    <?php if ($adminSsoMasterOn) { ?>
                                        <?php echo adminStatusHtml('good', 'On'); ?>
                                    <?php } else { ?>
                                        <?php echo adminStatusHtml('neutral', 'Off'); ?>
                                    <?php } ?>
                                </dd>
                                <?php foreach ($adminSsoProviders as $adminSsoMeta) { ?>
                                <dt class="col-sm-5"><?php echo adminEscape($adminSsoMeta['displayName']); ?></dt>
                                <dd class="col-sm-7">
                                    <?php if ($adminSsoMeta['usable']) { ?>
                                        <?php echo adminStatusHtml('good', 'Ready - the button shows on the sign-in page'); ?>
                                    <?php } else { ?>
                                        <?php echo adminStatusHtml('neutral', 'Not set up'); ?>
                                    <?php } ?>
                                </dd>
                                <?php } ?>
                            </dl>
                        </div>
                    </div>

                    <?php /* 💳 Charging */ ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-transparent">
                            <h2 class="h5 mb-0"><i class="fa-solid fa-sterling-sign me-2" aria-hidden="true"></i>Charging</h2>
                        </div>
                        <div class="card-body">
                            <dl class="row mb-0">
                                <?php foreach ($adminChargingSwitches as $adminSwitchKey => $adminSwitchLabel) { ?>
                                <dt class="col-sm-5"><?php echo adminEscape($adminSwitchLabel); ?></dt>
                                <dd class="col-sm-7">
                                    <?php if ($adminChargingState[$adminSwitchKey]) { ?>
                                        <?php echo adminStatusHtml('warning', 'On - this should be off until checkout is built'); ?>
                                    <?php } else { ?>
                                        <?php echo adminStatusHtml('locked', 'Off (locked)'); ?>
                                    <?php } ?>
                                </dd>
                                <?php } ?>
                            </dl>
                            <p class="small text-body-secondary mt-3 mb-0">
                                Charging switches on when checkout is built, after dynamic QR codes. Nobody can be charged until then.
                            </p>
                        </div>
                    </div>

                    <?php /* 👥 Global Admins */ ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-transparent">
                            <h2 class="h5 mb-0"><i class="fa-solid fa-users-gear me-2" aria-hidden="true"></i>Global Admins</h2>
                        </div>
                        <div class="card-body">
                            <?php if ($adminCount < 0) { ?>
                                <p class="mb-0"><?php echo adminStatusHtml('warning', 'Could not be counted'); ?></p>
                            <?php } else { ?>
                                <p class="mb-0"><?php echo (int) $adminCount; ?> working Global Admin(s).</p>
                            <?php } ?>
                            <p class="small text-body-secondary mt-2 mb-0">
                                Admins are added or removed on the server command line for now
                                (see "First Global Admin" in the developer notes).
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
