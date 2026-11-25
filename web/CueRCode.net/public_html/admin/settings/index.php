<?php
/**
 * ==============================================================================
 * ⚙️ CueRCode - Admin Settings (Global Admins only)
 * ==============================================================================
 *
 * Path: public_html/admin/settings/index.php
 *
 * The settings editor (task 7n Phase 1C, plan §7.1 and §7.2). Eight sections,
 * ONE form each, built from the registry in _functions/fn_admin_settings.php:
 *   1. Email (Microsoft 365) - with "Check Microsoft connection" and "Send me
 *      a test email"
 *   2. Email records       3. Sign in with Microsoft or Google
 *   4. Bot protection      5. PayPal
 *   6. Prices, VAT and refunds (the charging switches are shown "Off (locked)")
 *   7. Free trials         8. Admin security
 *
 * 🔒 Every change is a POST that goes, in this order: CSRF token, same-site
 *    (Origin) check, fresh password confirmation, validation, then ONE
 *    transaction holding the settings and their audit rows, then a redirect
 *    with a one-time message. While database upgrades are waiting (or the
 *    audit table is missing) nothing can be saved.
 *
 * 🤫 A secret box is always empty. An empty box keeps what is saved; the
 *    "Remove" tick clears it. The saved value is never put on the page.
 *
 * 🧾 Every section form carries a hidden fingerprint of what it showed. If the
 *    settings changed since (another tab, another admin), the save is refused
 *    and nothing is written (plan §7.5).
 *
 * 🚪 requireGlobalAdminPage() is the first application call (a test checks
 *    this for every admin page).
 *
 * @package     CueRCode
 * @subpackage  Admin
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-25 (task 7n Phase 1C, #31)
 * @see         _functions/fn_admin_settings.php - the registry, validators and saving
 * @see         _functions/fn_admin.php - the gate, password confirmation and audit trail
 * @see         .claude/plans/7n-admin-commerce-plan.md §7.1, §7.2
 */

// ==============================================================================
// 🚀 Load the application, then the admin functions, then the gate - FIRST
// ==============================================================================
require_once __DIR__ . '/../../../_includes/bootstrap.php';
require_once CUERCODE_FUNCTIONS_DIR . 'fn_admin.php';

$adminUser = requireGlobalAdminPage('/admin/settings/');

// ⚙️ The settings editor itself (loaded after the gate).
require_once CUERCODE_FUNCTIONS_DIR . 'fn_admin_settings.php';

// ==============================================================================
// 📋 What the page is built from
// ==============================================================================
$settingsRegistry = adminSettingsRegistry();
$settingsSections = adminSettingsSections();
$settingsTimezone = adminDisplayTimezone();
$settingsTimezoneLabel = adminTimezoneLabel($settingsTimezone);

$settingsDatabaseReady = adminDatabaseReadyForChanges();
$settingsSecondsLeft   = adminReauthSecondsLeftForAdmin($adminUser);
$settingsCanEdit       = ($settingsDatabaseReady && $settingsSecondsLeft > 0);

$settingsMessages     = [];
$settingsErrors       = [];      // setting key => message (shown beside the box)
$settingsPosted       = [];      // setting key => what was typed (NEVER a secret)
$settingsConfirmed    = [];      // setting key => true when a "confirm" tick was ticked
$settingsErrorSection = '';
$settingsPostedFingerprint = '';   // the fingerprint the submitted form carried (kept when the form is shown again)

$settingsPlanKeys = adminSettingsTrialPlanKeys();
if ($settingsPlanKeys === null) {
    $settingsPlanKeys = [];
}

// ==============================================================================
// 📤 POST: save a section, check the connection, or send a test email
// ==============================================================================
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ CSRF → same site → fresh password (plan §6.3, §6.4).
    $settingsCheck = adminCheckPost($adminUser, true);

    $settingsAction = '';
    if (isset($_POST['settings_action']) && is_string($_POST['settings_action'])) {
        $settingsAction = $_POST['settings_action'];
    }

    if (!$settingsCheck['ok']) {
        if ($settingsCheck['reason'] !== 'reauth') {
            http_response_code(403);
        }
        $settingsMessages[] = ['type' => 'danger', 'text' => $settingsCheck['message']];
    } elseif (!$settingsDatabaseReady) {
        // 🧾 Plan §6.7: no change without its audit row.
        $settingsMessages[] = [
            'type' => 'danger',
            'text' => 'Database upgrade needed, so nothing was saved. Apply the waiting upgrades on the database upgrades page first.',
        ];
    } elseif ($settingsAction === 'test_graph') {
        $settingsResult = adminSettingsTestGraph($adminUser);
        adminReauthTouch($adminUser);
        $settingsType = 'danger';
        if ($settingsResult['ok']) {
            $settingsType = 'success';
        }
        adminFlashAdd($settingsType, $settingsResult['message']);
        adminRedirect('/admin/settings/#section-email');
    } elseif ($settingsAction === 'test_email') {
        $settingsResult = adminSettingsSendTestEmail($adminUser);
        adminReauthTouch($adminUser);
        $settingsType = 'danger';
        if ($settingsResult['ok']) {
            $settingsType = 'success';
        }
        adminFlashAdd($settingsType, $settingsResult['message']);
        adminRedirect('/admin/settings/#section-email');
    } elseif ($settingsAction === 'save_section') {
        $settingsSectionID = '';
        if (isset($_POST['section']) && is_string($_POST['section'])) {
            $settingsSectionID = $_POST['section'];
        }

        $settingsCurrent = adminSettingsLoadRows(array_keys(adminSettingsFieldsForSection($settingsSectionID)));

        if (!isset($settingsSections[$settingsSectionID])) {
            $settingsMessages[] = ['type' => 'danger', 'text' => 'That section was not recognised, so nothing was saved.'];
        } elseif ($settingsCurrent === null) {
            $settingsMessages[] = ['type' => 'danger', 'text' => 'The saved settings could not be read, so nothing was saved. Please try again.'];
        } else {
            $settingsProcessed = adminSettingsProcessSection($settingsSectionID, $_POST, $settingsCurrent, ['planKeys' => $settingsPlanKeys]);

            // 🧾 The fingerprint of what this form showed (plan §7.5). The save
            //    compares it with the database inside its transaction.
            if (isset($_POST['fp']) && is_string($_POST['fp'])) {
                $settingsPostedFingerprint = $_POST['fp'];
            }

            if ($settingsProcessed['errors'] !== []) {
                // ❌ Show the problems; save nothing. Secrets are never sent back.
                $settingsErrors       = $settingsProcessed['errors'];
                $settingsErrorSection = $settingsSectionID;
                foreach (adminSettingsFieldsForSection($settingsSectionID) as $settingsFieldKey => $settingsFieldDef) {
                    if (adminSettingsFieldIsSecret($settingsFieldDef)) {
                        continue;
                    }
                    if (isset($_POST['f'][$settingsFieldKey]) && is_string($_POST['f'][$settingsFieldKey])) {
                        $settingsPosted[$settingsFieldKey] = $_POST['f'][$settingsFieldKey];
                    }
                    if (isset($_POST['confirm'][$settingsFieldKey]) && $_POST['confirm'][$settingsFieldKey] === '1') {
                        $settingsConfirmed[$settingsFieldKey] = true;
                    }
                }
            } elseif ($settingsProcessed['changes'] === []) {
                adminFlashAdd('info', 'Nothing was different, so nothing was saved.');
                adminRedirect('/admin/settings/#section-' . $settingsSectionID);
            } else {
                $settingsSaved = adminSettingsSaveSection($adminUser, $settingsSectionID, $settingsPostedFingerprint, $settingsProcessed['changes']);
                if ($settingsSaved['ok']) {
                    adminReauthTouch($adminUser);
                    adminFlashAdd('success', 'Saved: ' . $settingsSections[$settingsSectionID]['title'] . ' (' . (int) $settingsSaved['saved'] . ' change(s) recorded in the admin change log).');
                    adminRedirect('/admin/settings/#section-' . $settingsSectionID);
                }
                $settingsMessages[] = ['type' => 'danger', 'text' => $settingsSaved['message']];

                // 📋 Out of date: show the form again with what was typed (never a
                //    secret), keeping the OLD fingerprint so sending it again is
                //    refused again until the page is reloaded.
                if ($settingsSaved['stale']) {
                    $settingsErrorSection = $settingsSectionID;
                    foreach (adminSettingsFieldsForSection($settingsSectionID) as $settingsFieldKey => $settingsFieldDef) {
                        if (adminSettingsFieldIsSecret($settingsFieldDef)) {
                            continue;
                        }
                        if (isset($_POST['f'][$settingsFieldKey]) && is_string($_POST['f'][$settingsFieldKey])) {
                            $settingsPosted[$settingsFieldKey] = $_POST['f'][$settingsFieldKey];
                        }
                        if (isset($_POST['confirm'][$settingsFieldKey]) && $_POST['confirm'][$settingsFieldKey] === '1') {
                            $settingsConfirmed[$settingsFieldKey] = true;
                        }
                    }
                }
            }
        }
    } else {
        $settingsMessages[] = ['type' => 'danger', 'text' => 'That request was not understood, so nothing was saved.'];
    }

    // 🔓 The password confirmation may have run out while the form was open.
    $settingsSecondsLeft = adminReauthSecondsLeftForAdmin($adminUser);
    $settingsCanEdit     = ($settingsDatabaseReady && $settingsSecondsLeft > 0);
}

// ==============================================================================
// 📥 Read the saved state for the page (secrets: only "set" / "not set")
// ==============================================================================
$settingsStates = adminSettingsLoadRows(array_keys($settingsRegistry));
$settingsUnlockedUntil = '';
if ($settingsSecondsLeft > 0) {
    $settingsUnlockedUntil = adminFormatClockTime(time() + $settingsSecondsLeft, $settingsTimezone);
}

$settingsSsoCallbacks = [];
if (function_exists('ssoRedirectUri')) {
    $settingsSsoCallbacks = [
        'Microsoft' => ssoRedirectUri('microsoft'),
        'Google'    => ssoRedirectUri('google'),
    ];
}

// ==============================================================================
// 📄 Page variables
// ==============================================================================
$pageTitle       = 'Settings';
$pageDescription = 'Private settings editor for CueRCode Global Admins.';
$pageNoIndex     = true;
$bodyClass       = 'page-admin page-admin-settings';
$pageCSS         = [];
$pageJS          = ['/assets/js/cuercode-admin.js'];

$adminActive = 'settings';
$settingsMessages = array_merge(adminFlashTake(), $settingsMessages);

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <section class="py-5">
        <div class="container">
            <div class="row">
                <div class="col-lg-3">
                    <?php require_once CUERCODE_INCLUDES_DIR . 'admin_nav.php'; ?>
                </div>

                <div class="col-lg-9">
                    <h1 class="h2 fw-bold mb-3">Settings</h1>
                    <p class="text-body-secondary mb-4">
                        Secrets (passwords, keys) are stored encrypted and are never shown on this page. A secret box
                        is always empty: leave it empty to keep what is saved.
                    </p>

                    <?php echo adminRenderMessages($settingsMessages); ?>

                    <?php if ($settingsErrors !== []) { ?>
                    <?php /* ❌ Error summary: receives focus, links to each box with a problem */ ?>
                    <div class="alert alert-danger" role="alert" tabindex="-1" data-admin-focus="1" id="settings-error-summary">
                        <p class="fw-bold mb-2"><i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>There is a problem. Nothing was saved.</p>
                        <ul class="mb-0">
                            <?php foreach ($settingsErrors as $settingsErrorKey => $settingsErrorText) { ?>
                            <li>
                                <a href="#f-<?php echo adminEscape($settingsErrorKey); ?>"><?php echo adminEscape($settingsRegistry[$settingsErrorKey]['label']); ?></a>:
                                <?php echo adminEscape($settingsErrorText); ?>
                            </li>
                            <?php } ?>
                        </ul>
                    </div>
                    <?php } ?>

                    <?php /* 🔓 Editing state */ ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-body">
                            <?php if (!$settingsDatabaseReady) { ?>
                                <p class="mb-2"><?php echo adminStatusHtml('warning', 'Database upgrade needed - settings cannot be changed until the waiting upgrades are applied.'); ?></p>
                                <a href="/admin/upgrades/">See database upgrades</a>
                            <?php } elseif ($settingsSecondsLeft > 0) { ?>
                                <p class="mb-0"><?php echo adminStatusHtml('good', 'Editing unlocked until ' . $settingsUnlockedUntil . ' (' . $settingsTimezoneLabel . ')'); ?></p>
                            <?php } else { ?>
                                <p class="mb-2"><?php echo adminStatusHtml('locked', 'Locked - the values below are read-only. Confirm your password to make changes.'); ?></p>
                                <a class="btn btn-primary" href="/admin/reauth/?next=<?php echo rawurlencode('/admin/settings/'); ?>">
                                    <i class="fa-solid fa-unlock me-1" aria-hidden="true"></i> Unlock editing - confirm your password
                                </a>
                            <?php } ?>
                        </div>
                    </div>

                    <?php if ($settingsStates === null) { ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                        The saved settings could not be read just now, so the forms are not shown. Please reload the page.
                    </div>
                    <?php } else { ?>

                    <?php foreach ($settingsSections as $settingsSectionKey => $settingsSection) { ?>
                    <?php $settingsSectionFields = adminSettingsFieldsForSection($settingsSectionKey); ?>
                    <div class="card shadow-sm mb-4" id="section-<?php echo adminEscape($settingsSectionKey); ?>">
                        <div class="card-header bg-transparent">
                            <h2 class="h5 mb-0"><i class="<?php echo adminEscape($settingsSection['icon']); ?> me-2" aria-hidden="true"></i><?php echo adminEscape($settingsSection['title']); ?></h2>
                        </div>
                        <div class="card-body">
                            <p class="text-body-secondary"><?php echo adminEscape($settingsSection['intro']); ?></p>

                            <?php if ($settingsSectionKey === 'signin' && $settingsSsoCallbacks !== []) { ?>
                            <div class="mb-3">
                                <p class="fw-bold mb-1">Callback addresses to register with each provider</p>
                                <ul class="mb-0">
                                    <?php foreach ($settingsSsoCallbacks as $settingsCallbackName => $settingsCallbackUrl) { ?>
                                    <li><?php echo adminEscape($settingsCallbackName); ?>: <code class="text-break"><?php echo adminEscape($settingsCallbackUrl); ?></code></li>
                                    <?php } ?>
                                </ul>
                            </div>
                            <?php } ?>

                            <?php
                            // 🧾 What this form shows, as a fingerprint (lost-update check). When the
                            //    form is shown again after a problem, it keeps the fingerprint it
                            //    was sent with, so an out-of-date form cannot be saved by pressing
                            //    the button again.
                            $settingsFormFingerprint = adminSettingsSectionFingerprint($settingsSectionKey, $settingsStates);
                            if ($settingsErrorSection === $settingsSectionKey && $settingsPostedFingerprint !== '') {
                                $settingsFormFingerprint = $settingsPostedFingerprint;
                            }
                            ?>
                            <form method="POST" action="/admin/settings/" novalidate data-admin-dirty-check>
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="settings_action" value="save_section">
                                <input type="hidden" name="section" value="<?php echo adminEscape($settingsSectionKey); ?>">
                                <input type="hidden" name="fp" value="<?php echo adminEscape($settingsFormFingerprint); ?>">

                                <fieldset <?php if (!$settingsCanEdit) { ?>disabled<?php } ?>>
                                    <legend class="visually-hidden"><?php echo adminEscape($settingsSection['title']); ?> settings</legend>
                                    <?php
                                    $settingsOpenGroup = '';
                                    foreach ($settingsSectionFields as $settingsFieldKey => $settingsFieldDef) {
                                        // 🧱 A named sub-group (for example "Microsoft") gets its own legend.
                                        if ($settingsFieldDef['subgroup'] !== $settingsOpenGroup) {
                                            if ($settingsOpenGroup !== '') {
                                                echo '</fieldset>';
                                            }
                                            $settingsOpenGroup = $settingsFieldDef['subgroup'];
                                            if ($settingsOpenGroup !== '') {
                                                echo '<fieldset class="border rounded p-3 mb-4"><legend class="h6 float-none w-auto px-2">' . adminEscape($settingsOpenGroup) . '</legend>';
                                            }
                                        }

                                        $settingsFieldView = [
                                            'state'       => $settingsStates[$settingsFieldKey],
                                            'error'       => '',
                                            'timezone'    => $settingsTimezone,
                                            'planChoices' => $settingsPlanKeys,
                                            'confirmed'   => isset($settingsConfirmed[$settingsFieldKey]),
                                        ];
                                        if (isset($settingsErrors[$settingsFieldKey]) && $settingsErrorSection === $settingsSectionKey) {
                                            $settingsFieldView['error'] = $settingsErrors[$settingsFieldKey];
                                        }
                                        if (isset($settingsPosted[$settingsFieldKey]) && $settingsErrorSection === $settingsSectionKey) {
                                            $settingsFieldView['posted'] = $settingsPosted[$settingsFieldKey];
                                        }
                                        echo adminSettingsRenderField($settingsFieldDef, $settingsFieldView);
                                    }
                                    if ($settingsOpenGroup !== '') {
                                        echo '</fieldset>';
                                    }
                                    ?>
                                </fieldset>

                                <?php if ($settingsSectionKey === 'prices') { ?>
                                <?php /* 🔒 The charging switches are shown, never changed here (plan R8) */ ?>
                                <div class="mb-4">
                                    <p class="fw-bold mb-1">Charging switches</p>
                                    <dl class="row mb-1">
                                        <?php foreach (adminSettingsChargingSwitches() as $settingsSwitchKey => $settingsSwitchLabel) { ?>
                                        <dt class="col-sm-5"><?php echo adminEscape($settingsSwitchLabel); ?></dt>
                                        <dd class="col-sm-7"><?php echo adminStatusHtml('locked', 'Off (locked)'); ?></dd>
                                        <?php } ?>
                                    </dl>
                                    <p class="small text-body-secondary mb-0">These switch on when checkout is built. They cannot be changed from this screen.</p>
                                </div>
                                <?php } ?>

                                <?php if ($settingsCanEdit) { ?>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fa-solid fa-floppy-disk me-1" aria-hidden="true"></i> <?php echo adminEscape($settingsSection['saveLabel']); ?>
                                </button>
                                <?php } ?>
                            </form>

                            <?php if ($settingsSectionKey === 'email') { ?>
                            <hr>
                            <h3 class="h6">Try it out</h3>
                            <p class="small text-body-secondary">
                                "Check Microsoft connection" asks Microsoft for a fresh access pass using the saved details and says whether it worked
                                (it works even while email is off). "Send me a test email" sends one fixed message to your own address only, and needs
                                "Send emails" to be on (at most 5 in 10 minutes). Save your changes first.
                            </p>
                            <div class="d-flex flex-wrap gap-2">
                                <form method="POST" action="/admin/settings/">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="settings_action" value="test_graph">
                                    <button type="submit" class="btn btn-outline-primary" <?php if (!$settingsCanEdit) { ?>disabled<?php } ?>>
                                        <i class="fa-solid fa-plug me-1" aria-hidden="true"></i> Check Microsoft connection
                                    </button>
                                </form>
                                <form method="POST" action="/admin/settings/">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="settings_action" value="test_email">
                                    <button type="submit" class="btn btn-outline-primary" <?php if (!$settingsCanEdit) { ?>disabled<?php } ?>>
                                        <i class="fa-solid fa-paper-plane me-1" aria-hidden="true"></i> Send me a test email
                                    </button>
                                </form>
                            </div>
                            <?php } ?>
                        </div>
                    </div>
                    <?php } ?>

                    <?php /* 🌍 The list behind the time-zone box (suggestions only; the server checks the answer) */ ?>
                    <datalist id="timezone-list">
                        <?php foreach (\DateTimeZone::listIdentifiers() as $settingsZoneName) { ?>
                        <option value="<?php echo adminEscape($settingsZoneName); ?>"></option>
                        <?php } ?>
                    </datalist>

                    <?php } ?>
                </div>
            </div>
        </div>
    </section>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
