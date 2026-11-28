<?php
/**
 * ==============================================================================
 * ⚙️ CueRCode - Admin settings editor (task 7n Phase 1C)
 * ==============================================================================
 *
 * Path: _functions/fn_admin_settings.php
 *
 * Everything behind /admin/settings/: the owner can type in the Microsoft 365
 * email details, the retention periods, the VAT switch, the free-trial switch
 * and the other settings listed in plan §7.2 - without a secret ever being
 * shown, logged or written to the change log.
 *
 *   1. 📋 The REGISTRY (adminSettingsRegistry()). One entry per setting the
 *      screen may touch: its words, its kind (secret / text / email / ...),
 *      its limits and the row it belongs to. Nothing outside the registry can
 *      be saved from this screen, so a hand-made form cannot reach any other
 *      setting.
 *   2. ✅ VALIDATORS (pure, no database): adminSettingsValidateValue(),
 *      adminSettingsSecretIntent(), adminSettingsProcessSection().
 *   3. 💾 adminSaveSetting() (plan §6.6): refuses locked and non-editable
 *      keys, saves through setConfig() and writes the audit row IN THE SAME
 *      TRANSACTION, so a change never exists without its audit row.
 *   4. 🔌 Two actions: "Check Microsoft connection" and "Send me a test
 *      email" (each audited).
 *   5. 🧱 Small HTML builders for the settings forms (the page owns the
 *      <form> tags and the CSRF field; these build what is inside).
 *
 * 🤫 SECRETS (plan §7.2): a secret box is ALWAYS drawn empty. An empty box
 *    means "keep what is stored". A separate "Remove" tick clears it. The
 *    stored value is never read into the page: only "Set" / "Not set" and the
 *    last-changed time. The audit log records only "set", "replaced" or
 *    "cleared". Error messages never repeat what was typed.
 *
 * 🔒 LOCKED: the three charging switches, dynamic_qr_enabled, every
 *    "*_cache" row and the rows marked not-editable can never be changed from
 *    here, even by a hand-made request (adminSettingsKeyIsLocked()).
 *
 * House rules kept here: full multi-line PHP (no ??, ternaries, arrow
 * functions, match or first-class callables), MySQLi prepared statements only
 * (through dbQuery()), back-quoted SQL identifiers, #[\SensitiveParameter] on
 * every parameter that can hold a secret.
 *
 * 📦 LOADING: not loaded by bootstrap.php. /admin/settings/ loads it after the
 *    gate. Loading defines constants and functions only (no database call, no
 *    output) - the tests rely on that.
 *
 * @package     CueRCode
 * @subpackage  Admin
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-25 (task 7n Phase 1C, #31)
 * @see         .claude/plans/7n-admin-commerce-plan.md §6.6, §6.7, §7.1, §7.2, §9 Phase 1C
 * @see         _functions/fn_admin.php - the gate, re-confirmation, audit trail and HTML helpers used here
 * @see         _functions/fn_config.php - setConfig() / getConfig()
 * @see         public_html/admin/settings/index.php
 */

// ==============================================================================
// 🚫 Direct access protection
// ==============================================================================
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $adminSettingsScriptFilename = $_SERVER['SCRIPT_FILENAME'];
} else {
    $adminSettingsScriptFilename = '';
}
if (basename($adminSettingsScriptFilename) === basename(__FILE__)) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied.');
}

// 📦 The admin functions this file builds on (audit helpers, HTML helpers).
require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_admin.php';

// 📏 The sizes of the "emailed link" limits live in ONE place (fn_auth.php), so
//    this editor and the code that enforces them cannot disagree. The site's
//    start-up loads fn_auth.php already; this only matters for a test or tool
//    that loads this file alone.
if (!function_exists('authLinkRequestLimitBounds')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_auth.php';
}

// 🌍 The trusted-forwarding-server range reader (decision 0015) is used for
//    the trusted_proxy_ips box. Never copied here.
if (!function_exists('clientIpParseRangeList')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_client_ip.php';
}

// 🤖 The human-check waiting-time limits, outage rules and form limit (#163)
//    live in ONE place, so this editor and the code that uses them cannot
//    disagree. Pure on loading.
if (!defined('CUERCODE_HUMAN_CHECK_MIN_TIMEOUT')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_human_check.php';
}

// ✉️ The no-JavaScript route's limits (decision 0038), from ONE place. Pure on loading.
if (!function_exists('noScriptRouteBounds')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_no_script_route.php';
}

// 💷 Currency facts and the currency-list reader (billing_currencies, task 7n
//    Phase 2). Pure: loading it defines functions only.
if (!function_exists('moneyParseCurrencyList')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_money.php';
}

// 🔗 #165: the Go2My.Link waiting-time limits and the "Test connection"
//    check live in ONE place. Pure on loading.
if (!function_exists('go2mylinkTestConnection')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_go2mylink.php';
}

// 🗑️ #179: the error-log and visitor-details periods' defaults
//    (CUERCODE_ERROR_LOG_RETENTION_DAYS_DEFAULT and friends) live in
//    fn_email.php with the clean-up that uses them. That file defines
//    constants and functions only on loading.
if (!defined('CUERCODE_ERROR_LOG_RETENTION_DAYS_DEFAULT')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_email.php';
}

// ==============================================================================
// 🔧 Constants
// ==============================================================================

/** @var int Longest secret accepted, in characters (plan §7.2). */
if (!defined('CUERCODE_ADMIN_SECRET_MAX_LENGTH')) {
    define('CUERCODE_ADMIN_SECRET_MAX_LENGTH', 2048);
}

/** @var int Test emails allowed per window (plan §7.1). */
if (!defined('CUERCODE_ADMIN_TEST_EMAIL_MAX')) {
    define('CUERCODE_ADMIN_TEST_EMAIL_MAX', 5);
}

/** @var int The test-email window, in seconds (10 minutes). */
if (!defined('CUERCODE_ADMIN_TEST_EMAIL_WINDOW_SECONDS')) {
    define('CUERCODE_ADMIN_TEST_EMAIL_WINDOW_SECONDS', 600);
}

/** @var int "Check Microsoft connection" attempts allowed per window, per admin (counted from the audit log). */
if (!defined('CUERCODE_ADMIN_TEST_GRAPH_MAX')) {
    define('CUERCODE_ADMIN_TEST_GRAPH_MAX', 10);
}

/** @var int Go2My.Link "Test connection" attempts allowed per window, per admin (counted from the audit log, #165). */
if (!defined('CUERCODE_ADMIN_TEST_GO2MYLINK_MAX')) {
    define('CUERCODE_ADMIN_TEST_GO2MYLINK_MAX', 10);
}

/**
 * @var string The reason text on the audit row written BEFORE a test runs.
 *             The limits count these rows (one per attempt); the row written
 *             afterwards carries CUERCODE_ADMIN_TEST_RESULT_REASON instead.
 */
if (!defined('CUERCODE_ADMIN_TEST_ATTEMPT_REASON')) {
    define('CUERCODE_ADMIN_TEST_ATTEMPT_REASON', 'Test started (the result is in the next entry)');
}

/** @var string The reason text on the audit row that records how a test ended. */
if (!defined('CUERCODE_ADMIN_TEST_RESULT_REASON')) {
    define('CUERCODE_ADMIN_TEST_RESULT_REASON', 'Test finished');
}

/** @var string The reason text on the audit row of a test refused by the limit (final review N-1). */
if (!defined('CUERCODE_ADMIN_TEST_LIMITED_REASON')) {
    define('CUERCODE_ADMIN_TEST_LIMITED_REASON', 'Refused: too many tests in 10 minutes (the test was not run)');
}

/**
 * @var string Added to a test's action name on the row written BEFORE it runs
 *             (final review N-2): "setting.test_graph.started". The row's
 *             outcome column can only say success, refused or failed - the
 *             database upgrade tool only ever adds, and widening that list
 *             would mean rewriting the column - so the action name is what
 *             says "started".
 */
if (!defined('CUERCODE_ADMIN_TEST_STARTED_SUFFIX')) {
    define('CUERCODE_ADMIN_TEST_STARTED_SUFFIX', '.started');
}

/** @var string $_SESSION key holding the times of recent test emails. */
if (!defined('CUERCODE_ADMIN_TEST_EMAIL_SESSION_KEY')) {
    define('CUERCODE_ADMIN_TEST_EMAIL_SESSION_KEY', 'cuercode_admin_test_email_times');
}

// ==============================================================================
// 🧠 PURE: locked keys (plan §6.6 step 2)
// ==============================================================================

/**
 * 🔒 The settings nobody can change from the admin screens, by name.
 *
 * @return array<int, string>
 */
function adminSettingsLockedKeys(): array
{
    return [
        'billing_price_points_enabled',
        'billing_discounts_enabled',
        'billing_payg_enabled',
        'dynamic_qr_enabled',
        'graph_token_cache',
        'app_version',
        'app_phase',
        'api_version',
        'encryption_algorithm',
    ];
}

/**
 * 🔒 Is this setting locked? True for the names above and for every key that
 *    ends in "_cache" (runtime caches nobody should hand-edit). PURE.
 *
 * @param string $key Setting key
 * @return bool
 */
function adminSettingsKeyIsLocked(string $key): bool
{
    if (in_array($key, adminSettingsLockedKeys(), true)) {
        return true;
    }

    if (str_ends_with($key, '_cache')) {
        return true;
    }

    return false;
}

/**
 * 💳 The charging switches shown "Off (locked)" on the prices section.
 *
 * @return array<string, string> key => plain label
 */
function adminSettingsChargingSwitches(): array
{
    return [
        'billing_price_points_enabled' => 'Prices from the price list',
        'billing_discounts_enabled'    => 'Discount codes',
        'billing_payg_enabled'         => 'Pay-as-you-go credits',
        'dynamic_qr_enabled'           => 'Dynamic QR codes',
    ];
}

// ==============================================================================
// 📋 PURE: the sections and the registry (plan §7.2)
// ==============================================================================

/**
 * 📑 The nine sections, in page order.
 *
 * @return array<string, array{saveLabel: string, title: string, icon: string, intro: string}> saveLabel = the words on the section's own Save button
 */
function adminSettingsSections(): array
{
    return [
        'website' => [
            'saveLabel' => 'Save website address',
            'title' => 'Website address',
            'icon'  => 'fa-solid fa-globe',
            'intro' => 'The address people use to reach the site. Links in emails (verify your email, reset your password), the sign-in callback addresses below, the main-address and share-preview tags of every page and the example addresses on the developer pages are all built from it, so set it BEFORE you turn email on. It is stored in the database, so every copy of the site that uses the same database shares this one value. With the standard layout (one folder and one credentials file) it must stay the LIVE address. A test site may have its own value only if it has its own database (its own folder and credentials file). If Microsoft or Google sign-in is switched on, register the new callback addresses with them first, or sign-in will stop working; sign-ins in progress during the change also fail.',
        ],
        'email' => [
            'saveLabel' => 'Save email settings',
            'title' => 'Email (Microsoft 365)',
            'icon'  => 'fa-solid fa-envelope',
            'intro' => 'The site sends its emails (account verification, password resets) through a Microsoft 365 mailbox. Fill in the details from your Microsoft Entra app registration, use "Check Microsoft connection", then turn email on. Also make sure the cron job that sends queued emails is set up (see the install notes).',
        ],
        'email-records' => [
            'saveLabel' => 'Save record periods',
            'title' => 'Email records and logs',
            'icon'  => 'fa-solid fa-broom',
            'intro' => 'How long the record of each sent or given-up email is kept before it is deleted (the same two periods apply to the email activity-log entries, which name the recipient), how long error-log entries are kept, and how long activity entries keep the visitor\'s internet address and browser name. Whole days, 1 to 3650. The scheduled email queue job does the clean-up. The privacy page shows the periods set here.',
        ],
        'signin' => [
            'saveLabel' => 'Save sign-in provider settings',
            'title' => 'Sign in with Microsoft or Google',
            'icon'  => 'fa-solid fa-right-to-bracket',
            'intro' => 'Lets people sign in with a Microsoft or Google account. Everything stays off until the main switch and the provider switch are both on and the client ID and secret are set.',
        ],
        'bot' => [
            'saveLabel' => 'Save human check settings',
            'title' => 'Human check (bot protection)',
            'icon'  => 'fa-solid fa-robot',
            'intro' => 'A short check that a visitor is a person, on the forms to create an account, sign in, reset a password and ask for a new verification link (and the same JSON routes). It stays off until a provider\'s site key AND secret key are both entered. On "Automatic", every provider with both keys is used, in the order Cloudflare Turnstile, Google reCAPTCHA, hCaptcha: the next one takes over while one is down. Get the keys from the provider\'s own dashboard and add this site\'s address there. The privacy page names the providers in use. If the check ever locks everyone out (including you), run php _includes/cli/human_check.php off on the server.',
        ],
        'dynamic' => [
            'saveLabel' => 'Save dynamic QR settings',
            'title' => 'Dynamic QR codes (Go2My.Link)',
            'icon'  => 'fa-solid fa-arrows-rotate',
            'intro' => 'A dynamic code prints a short address on QRmy.Link, and Go2My.Link (the sister link service) sends each scan on to the code\'s destination. Enter the Go2My.Link API address and key, save, then use "Test connection": it only reads, and changes nothing on Go2My.Link. Dynamic codes stay switched off for everyone until the rest of the feature is built and checked.',
        ],
        'paypal' => [
            'saveLabel' => 'Save PayPal settings',
            'title' => 'PayPal',
            'icon'  => 'fa-brands fa-paypal',
            'intro' => 'Stored safely now; used when checkout is built (task #26). Charging stays off.',
        ],
        'prices' => [
            'saveLabel' => 'Save prices, VAT and refunds',
            'title' => 'Prices, VAT and refunds',
            'icon'  => 'fa-solid fa-sterling-sign',
            'intro' => 'How prices are described on the pricing page, the money-back period, which currencies prices can be set in, and the price promise for existing customers. The Terms page must say the same refund period (task #38). The prices themselves are set on the Plans screen.',
        ],
        'trials' => [
            'saveLabel' => 'Save free trial settings',
            'title' => 'Free trials',
            'icon'  => 'fa-solid fa-gift',
            'intro' => 'Saved now; nothing changes for visitors until the trial sign-up flow is built.',
        ],
        'admin-security' => [
            'saveLabel' => 'Save admin security settings',
            'title' => 'Admin security',
            'icon'  => 'fa-solid fa-user-shield',
            'intro' => 'How long "confirm your password" lasts in the admin area, the time zone used to show dates, which forwarding servers the site trusts when working out a visitor\'s address, and how often someone may ask for a new verification link or a password-reset link.',
        ],
    ];
}

/**
 * 🧱 One registry entry, with every optional piece given its default. PURE.
 *
 * @param array<string, mixed> $field The pieces that differ from the defaults
 * @return array<string, mixed>
 */
function adminSettingsField(array $field): array
{
    $defaults = [
        'key'          => '',
        'section'      => '',
        'subgroup'     => '',
        'label'        => '',
        'help'         => '',
        'kind'         => 'text',
        'group'        => 'general',
        'type'         => 'string',
        'description'  => '',
        'min'          => null,
        'max'          => null,
        'maxLength'    => 255,
        'options'      => [],
        'secretFormat' => '',
        'allowEmpty'   => true,
        'afterSave'    => '',
        'confirmText'  => '',
        'rowOptional'  => false,
        // 📌 Final review M-3: for a 'rowOptional' setting, the value the
        //    code uses while no row is saved (shown to the admin, never saved
        //    unless they type it). Null = none to show.
        'default'      => null,
    ];

    return array_merge($defaults, $field);
}

/**
 * 📋 The registry: every setting the screen may change (plan §7.2). The ORDER
 *    here is the order on the page. Keyed by setting key.
 *
 * "group", "type" and "description" must match the seeded row (a test checks
 * this against seed_data.sql and migration 0002); they are used only when the
 * row is missing.
 *
 * @return array<string, array<string, mixed>>
 */
function adminSettingsRegistry(): array
{
    $fields = [];

    // ------------------------------------------------------ 0. Website address
    $fields[] = adminSettingsField([
        'key' => 'site_url', 'section' => 'website', 'kind' => 'site_url', 'group' => 'general', 'type' => 'url',
        'label' => 'Website address used in emailed links',
        'help' => 'Start with https:// and give only the website name, for example https://www.example.com. No page path, no port, no IP number and no localhost; a trailing slash is removed for you. Emailed links, the Microsoft and Google callback addresses, the main-address and share-preview tags of every page, and the example addresses on the developer pages are all built from this.',
        'allowEmpty' => false, 'maxLength' => 253,
        'description' => 'The base URL of the website',
    ]);

    // ---------------------------------------------------------------- 1. Email
    $fields[] = adminSettingsField([
        'key' => 'email_enabled', 'section' => 'email', 'kind' => 'bool', 'group' => 'email', 'type' => 'boolean',
        'label' => 'Send emails',
        'help' => 'The main on/off switch for email. While it is off, nobody can verify a new account.',
        'description' => 'Master kill-switch for the email subsystem',
        'confirmText' => 'I understand that any emails already waiting will start sending as soon as this is on.',
    ]);
    $fields[] = adminSettingsField([
        'key' => 'graph_tenant_id', 'section' => 'email', 'kind' => 'secret', 'group' => 'email', 'type' => 'string',
        'label' => 'Microsoft 365 tenant ID',
        'help' => 'From your Entra app registration: the "Directory (tenant) ID" (letters and numbers with dashes), or your domain name such as example.com.',
        'secretFormat' => 'guid_or_domain', 'afterSave' => 'graph_token_cache',
        'description' => 'Microsoft Entra ID tenant ID',
    ]);
    $fields[] = adminSettingsField([
        'key' => 'graph_client_id', 'section' => 'email', 'kind' => 'secret', 'group' => 'email', 'type' => 'string',
        'label' => 'App (client) ID',
        'help' => 'From your Entra app registration: the "Application (client) ID" (letters and numbers with dashes).',
        'secretFormat' => 'guid', 'afterSave' => 'graph_token_cache',
        'description' => 'Microsoft Entra ID app registration client ID',
    ]);
    $fields[] = adminSettingsField([
        'key' => 'graph_client_secret', 'section' => 'email', 'kind' => 'secret', 'group' => 'email', 'type' => 'string',
        'label' => 'App secret',
        'help' => 'The secret VALUE (not the secret ID) from "Certificates and secrets". It is stored encrypted and is never shown again.',
        'afterSave' => 'graph_token_cache',
        'description' => 'Microsoft Entra ID app registration client secret value',
    ]);
    $fields[] = adminSettingsField([
        'key' => 'graph_sender_mailbox', 'section' => 'email', 'kind' => 'email', 'group' => 'email', 'type' => 'string',
        'label' => 'Sending mailbox',
        'help' => 'The email address emails are sent from (a shared mailbox the app is allowed to send as).',
        'afterSave' => 'graph_token_cache',
        'description' => 'Microsoft 365 mailbox used as the sender',
    ]);
    $fields[] = adminSettingsField([
        'key' => 'email_from_name', 'section' => 'email', 'kind' => 'text', 'group' => 'email', 'type' => 'string',
        'label' => 'Sender name',
        'help' => 'The name people see on the email, for example "CueRCode Notifications". Up to 100 characters.',
        'maxLength' => 100,
        'description' => 'Display name used as the From name on outgoing email',
    ]);
    $fields[] = adminSettingsField([
        'key' => 'email_queue_autoprocess', 'section' => 'email', 'kind' => 'bool', 'group' => 'email', 'type' => 'boolean',
        'label' => 'Also send queued emails when someone visits a page',
        'help' => 'Recommended OFF. Use the cron job instead (it sends emails on time without slowing page visits).',
        'description' => 'Page-visit fallback for sending queued email',
    ]);

    // -------------------------------------------------------- 2. Email records
    $fields[] = adminSettingsField([
        'key' => 'email_queue_retention_days', 'section' => 'email-records', 'kind' => 'int', 'group' => 'email', 'type' => 'integer',
        'label' => 'Keep sent and skipped email records for (days)',
        'help' => 'Whole days, 1 to 3650. The default is 30. Also covers activity-log entries for queued, sent and skipped emails.',
        'min' => 1, 'max' => 3650,
        'description' => 'Days to keep a sent or skipped email record',
    ]);
    $fields[] = adminSettingsField([
        'key' => 'email_queue_failed_retention_days', 'section' => 'email-records', 'kind' => 'int', 'group' => 'email', 'type' => 'integer',
        'label' => 'Keep given-up (failed) email records for (days)',
        'help' => 'Whole days, 1 to 3650. The default is 90. Longer, because support is asked about these. Also covers activity-log entries for failed emails.',
        'min' => 1, 'max' => 3650,
        'description' => 'Days to keep a given-up email record',
    ]);
    // 🗑️ #179: the error log, and the visitor details of activity entries.
    //    No seeded rows: the code uses the default until an admin saves one.
    $fields[] = adminSettingsField([
        'key' => CUERCODE_SETTING_ERROR_LOG_RETENTION_DAYS, 'section' => 'email-records', 'kind' => 'int', 'group' => 'security', 'type' => 'integer',
        'label' => 'Keep error-log entries for (days)',
        'help' => 'Whole days, 1 to 3650. The default is ' . CUERCODE_ERROR_LOG_RETENTION_DAYS_DEFAULT . '. Each entry holds the page address, the visitor\'s internet address and browser name; after this many days it is deleted. Email addresses are never stored in it (they are replaced with [address]).',
        'min' => 1, 'max' => 3650, 'rowOptional' => true, 'default' => CUERCODE_ERROR_LOG_RETENTION_DAYS_DEFAULT,
        'description' => 'Days to keep an error-log entry (#179)',
    ]);
    $fields[] = adminSettingsField([
        'key' => CUERCODE_SETTING_ACTIVITY_VISITOR_DETAILS_RETENTION_DAYS, 'section' => 'email-records', 'kind' => 'int', 'group' => 'security', 'type' => 'integer',
        'label' => 'Keep visitor address and browser name in activity entries for (days)',
        'help' => 'Whole days, 1 to 3650. The default is ' . CUERCODE_ACTIVITY_VISITOR_DETAILS_RETENTION_DAYS_DEFAULT . '. After this many days the internet address and browser name are removed from activity-log entries; the entries themselves (what happened, and when) are kept. The admin change log keeps its own record.',
        'min' => 1, 'max' => 3650, 'rowOptional' => true, 'default' => CUERCODE_ACTIVITY_VISITOR_DETAILS_RETENTION_DAYS_DEFAULT,
        'description' => 'Days activity entries keep the visitor address and browser name (#179)',
    ]);

    // ------------------------------------------------------------ 3. Sign in
    $fields[] = adminSettingsField([
        'key' => 'sso_enabled', 'section' => 'signin', 'kind' => 'bool', 'group' => 'sso', 'type' => 'boolean',
        'label' => 'Main switch for signing in with Microsoft or Google',
        'help' => 'Off means no provider works, whatever its own switch says.',
        'description' => 'Master kill-switch for the SSO subsystem',
    ]);
    foreach (['microsoft' => 'Microsoft', 'google' => 'Google'] as $provider => $providerName) {
        $fields[] = adminSettingsField([
            'key' => 'sso_' . $provider . '_enabled', 'section' => 'signin', 'subgroup' => $providerName, 'kind' => 'bool', 'group' => 'sso', 'type' => 'boolean',
            'label' => 'Show "Sign in with ' . $providerName . '"',
            'help' => 'Needs the main switch, a client ID and a client secret as well.',
            'description' => 'Enable Sign in with ' . $providerName,
        ]);
        $fields[] = adminSettingsField([
            'key' => 'sso_' . $provider . '_client_id', 'section' => 'signin', 'subgroup' => $providerName, 'kind' => 'secret', 'group' => 'sso', 'type' => 'string',
            'label' => $providerName . ' client ID',
            'help' => 'From the ' . $providerName . ' app registration.',
            'description' => $providerName . ' client ID',
        ]);
        $fields[] = adminSettingsField([
            'key' => 'sso_' . $provider . '_client_secret', 'section' => 'signin', 'subgroup' => $providerName, 'kind' => 'secret', 'group' => 'sso', 'type' => 'string',
            'label' => $providerName . ' client secret',
            'help' => 'Stored encrypted and never shown again.',
            'description' => $providerName . ' client secret',
        ]);
        $fields[] = adminSettingsField([
            'key' => 'sso_' . $provider . '_discovery_url', 'section' => 'signin', 'subgroup' => $providerName, 'kind' => 'https_url', 'group' => 'sso', 'type' => 'url',
            'label' => $providerName . ' discovery address',
            'help' => 'Must start with https://. Changing it makes the site forget what it learned from the old address.',
            'allowEmpty' => false, 'maxLength' => 500, 'afterSave' => 'sso_discovery:' . $provider,
            'description' => $providerName . ' OIDC discovery document URL',
        ]);
        $fields[] = adminSettingsField([
            'key' => 'sso_' . $provider . '_scopes', 'section' => 'signin', 'subgroup' => $providerName, 'kind' => 'scopes', 'group' => 'sso', 'type' => 'string',
            'label' => $providerName . ' scopes',
            'help' => 'Separated by spaces. Must include openid. Letters, numbers and . : / _ - only.',
            'allowEmpty' => false, 'maxLength' => 500,
            'description' => 'Space-separated OIDC scopes requested from ' . $providerName,
        ]);
    }

    // ------------------------------------------------------- 4. Bot protection
    $fields[] = adminSettingsField([
        'key' => 'bot_protection_provider', 'section' => 'bot', 'kind' => 'choice', 'group' => 'security', 'type' => 'string',
        'label' => 'Which provider to use',
        'help' => 'Automatic is recommended. Choosing a provider tries it first; the others with both keys follow if it cannot be reached (decision 0037). Off switches the check off whatever keys are saved.',
        'options' => [
            'auto'      => 'Automatic (the first with both keys: Turnstile, then reCAPTCHA, then hCaptcha)',
            'turnstile' => 'Cloudflare Turnstile first',
            'recaptcha' => 'Google reCAPTCHA first (version 2, the "I\'m not a robot" box)',
            'hcaptcha'  => 'hCaptcha first',
            'none'      => 'Off (no human check)',
        ],
        'description' => 'Human check provider: auto (the first with both keys, in the order turnstile, recaptcha, hcaptcha), turnstile, recaptcha or hcaptcha (tried first, the others with keys follow), or none (off)',
    ]);
    // 🚦 Second Codex review: there is no "if every provider cannot be
    //    reached" choice any more. Decision 0037 is the only rule (the status
    //    box above describes it); the old "refuse" choice could refuse
    //    sign-in, which 0037 rules out. Upgrade 0005's row is left in place
    //    and is not read.
    $fields[] = adminSettingsField([
        'key' => 'bot_protection_timeout_seconds', 'section' => 'bot', 'kind' => 'int', 'group' => 'security', 'type' => 'integer',
        'label' => 'How long to wait for the provider (seconds)',
        'help' => 'Whole seconds, ' . CUERCODE_HUMAN_CHECK_MIN_TIMEOUT . ' to ' . CUERCODE_HUMAN_CHECK_MAX_TIMEOUT . '. The default is ' . CUERCODE_HUMAN_CHECK_DEFAULT_TIMEOUT . '. After this the provider counts as "cannot be reached".',
        'min' => CUERCODE_HUMAN_CHECK_MIN_TIMEOUT, 'max' => CUERCODE_HUMAN_CHECK_MAX_TIMEOUT,
        'description' => 'Human check: seconds to wait for the provider before treating it as unreachable. Whole number 2-15; anything else means the default of 5 is used.',
    ]);
    $botKeys = [
        'turnstile_site_key'   => 'Turnstile site key',
        'turnstile_secret_key' => 'Turnstile secret key',
        'recaptcha_site_key'   => 'reCAPTCHA site key',
        'recaptcha_secret_key' => 'reCAPTCHA secret key',
        'hcaptcha_site_key'    => 'hCaptcha site key',
        'hcaptcha_secret_key'  => 'hCaptcha secret key',
    ];
    foreach ($botKeys as $botKey => $botLabel) {
        $fields[] = adminSettingsField([
            'key' => $botKey, 'section' => 'bot', 'kind' => 'secret', 'group' => 'security', 'type' => 'string',
            'label' => $botLabel,
            'help' => 'From the provider\'s dashboard. Stored encrypted and never shown again.',
            'description' => $botLabel,
        ]);
    }

    // ⏱️ #163: the limits that go with the human check (after the keys; the
    //    no-JavaScript route's limits are the last group of the section).
    $formLimitBounds = humanCheckFormLimitBounds();
    $fields[] = adminSettingsField([
        'key' => 'human_check_form_ip_per_hour', 'section' => 'bot', 'kind' => 'int', 'group' => 'security', 'type' => 'integer',
        'label' => 'Most form sends with a human check from one visitor address in an hour',
        'help' => 'Counted before the provider is asked, across the four account forms, so a script cannot make this server call the provider without limit. Whole numbers, ' . $formLimitBounds['min'] . ' to ' . $formLimitBounds['max'] . '. The default is ' . $formLimitBounds['default'] . '.',
        'min' => $formLimitBounds['min'], 'max' => $formLimitBounds['max'],
        'description' => 'Human check: the most times one visitor address may send the four account forms with a token in an hour, before the provider is asked. Whole number 1-1000; anything else means the default of 30.',
    ]);
    $noScriptBounds = noScriptRouteBounds();
    $noScriptWords  = [
        'ip_per_hour'   => ['Most uses from one visitor address in an hour', 'Whatever the email address, all four forms together, counted before any password is looked at. An IPv6 address counts as its whole /64 network.'],
        'email_per_day' => ['Most emails to one mailbox in 24 hours', 'Whoever asks, so nobody can fill one person\'s inbox. "+tag" spellings (and, for Gmail, dots) count as the same mailbox. A wrong password or an address with no account uses up nothing.'],
        'site_per_hour' => ['Most emails each form sends in an hour, for the whole site', 'A ceiling on the emails this route can cause, counted for each of the four forms separately, so a flood on one form cannot block another (for example a real person\'s sign-in).'],
        'password_per_day' => ['Most wrong passwords checked for one account in 24 hours', 'Wrong passwords typed through this route never lock the account, so this number bounds guessing instead. Past it the route stops checking passwords for that account until the day is over, with the same answer as always. The normal form is not affected, but a person who cannot use JavaScript has only this route, so a stranger who uses up this number keeps them out of it for the day (an accepted risk, decision 0044).'],
    ];
    foreach ($noScriptWords as $noScriptSuffix => $noScriptText) {
        $noScriptBound = $noScriptBounds[$noScriptSuffix];
        // 📌 Third-round review, Medium 1: the per-account number of password
        //    checks came after upgrade 0005, so it has no seeded row; the code
        //    uses its default until an admin saves one (like the link limits).
        $noScriptRowOptional = ($noScriptSuffix === 'password_per_day');
        $noScriptDefault     = null;
        if ($noScriptRowOptional) {
            $noScriptDefault = $noScriptBound['default'];
        }
        $fields[] = adminSettingsField([
            'key' => 'no_script_route_' . $noScriptSuffix, 'section' => 'bot', 'subgroup' => 'Without JavaScript: the email-confirmed route (decision 0038)',
            'kind' => 'int', 'group' => 'security', 'type' => 'integer',
            'label' => $noScriptText[0],
            'help' => $noScriptText[1] . ' Visitors without JavaScript cannot see the human check, so they confirm by email instead; these limits stop that from being used to send unlimited email or try passwords. Whole numbers, ' . $noScriptBound['min'] . ' to ' . $noScriptBound['max'] . '. The default is ' . $noScriptBound['default'] . '. A smaller number is stricter.',
            'min' => $noScriptBound['min'], 'max' => $noScriptBound['max'], 'rowOptional' => $noScriptRowOptional, 'default' => $noScriptDefault,
            'description' => 'No-JavaScript email route (decision 0038): ' . strtolower($noScriptText[0]) . '.',
        ]);
    }

    // ------------------------------------- 4b. Dynamic QR codes (Go2My.Link, #165)
    $fields[] = adminSettingsField([
        'key' => 'go2mylink_api_base_url', 'section' => 'dynamic', 'kind' => 'https_url', 'group' => 'redirects', 'type' => 'url',
        'label' => 'Go2My.Link API address',
        'help' => 'Normally https://go2my.link/api/v1. A test (alpha) database that uses a different Go2My.Link sets that address here (decision 0030). Must start with https://.',
        'allowEmpty' => false, 'maxLength' => 255,
        'description' => 'Go2My.Link API address used for dynamic codes (production by default; an alpha test database sets its own, decision 0030)',
    ]);
    $fields[] = adminSettingsField([
        'key' => 'go2mylink_api_key', 'section' => 'dynamic', 'kind' => 'secret', 'group' => 'redirects', 'type' => 'string',
        'label' => 'Go2My.Link API key',
        'help' => 'Made on the Go2My.Link dashboard for the CueRCode organisation, with the permissions qr:link, urls:read and urls:write (urls:delete and analytics:read are planned; account:read lets "Test connection" list them). It starts with g2ml_. Stored encrypted and never shown again.',
        'secretFormat' => 'go2mylink_key',
        'description' => 'Go2My.Link API key (scopes qr:link, urls:read, urls:write) - stored encrypted and never shown back',
    ]);
    $fields[] = adminSettingsField([
        'key' => 'go2mylink_timeout_seconds', 'section' => 'dynamic', 'kind' => 'int', 'group' => 'redirects', 'type' => 'integer',
        'label' => 'How long to wait for Go2My.Link (seconds)',
        'help' => 'Whole seconds, ' . CUERCODE_GO2MYLINK_MIN_TIMEOUT . ' to ' . CUERCODE_GO2MYLINK_MAX_TIMEOUT . '. The default is ' . CUERCODE_GO2MYLINK_DEFAULT_TIMEOUT . '. After this a new code or a change is refused with "try again later"; scans are not affected (Go2My.Link answers them).',
        'min' => CUERCODE_GO2MYLINK_MIN_TIMEOUT, 'max' => CUERCODE_GO2MYLINK_MAX_TIMEOUT,
        'description' => 'Go2My.Link: seconds to wait for an answer. Whole number 2-15; anything else means the default of 5.',
    ]);
    $fields[] = adminSettingsField([
        'key' => 'dynamic_redirect_base_url', 'section' => 'dynamic', 'kind' => 'site_url', 'group' => 'redirects', 'type' => 'url',
        'label' => 'Short-link address printed in dynamic codes',
        'help' => 'https://QRmy.Link for real codes; https://alpha.QRmy.Link on the alpha test database (decision 0030). It must be the CueRCode organisation\'s default short domain on Go2My.Link: a link Go2My.Link makes on any other domain is refused, never printed. Only the website name, no path.',
        'allowEmpty' => false, 'maxLength' => 253,
        'description' => 'The short-link address printed in dynamic codes ({address}/{short code}); must be the CueRCode organisation\'s default short domain on Go2My.Link',
    ]);
    $fields[] = adminSettingsField([
        'key' => 'link_safety_webrisk_api_key', 'section' => 'dynamic', 'subgroup' => 'Link-safety check (Google Web Risk)', 'kind' => 'secret', 'group' => 'link_safety', 'type' => 'string',
        'label' => 'Google Web Risk API key',
        'help' => 'From a Google Cloud project with the Web Risk API switched on; restrict the key to that API. Saved now, used when the link-safety check is built (#168). Stored encrypted and never shown again.',
        'description' => 'Google Web Risk API key (restricted to the Web Risk API) - stored encrypted and never shown back',
    ]);

    // ----------------------------------------------------------------- 5. PayPal
    $fields[] = adminSettingsField([
        'key' => 'paypal_mode', 'section' => 'paypal', 'kind' => 'choice', 'group' => 'paypal', 'type' => 'string',
        'label' => 'PayPal mode',
        'help' => 'Sandbox is for testing with no real money. Charging stays off either way.',
        'options' => ['sandbox' => 'Sandbox (testing)', 'live' => 'Live'],
        'description' => 'PayPal environment',
    ]);
    $paypalKeys = [
        'paypal_client_id'     => 'PayPal client ID',
        'paypal_client_secret' => 'PayPal client secret',
        'paypal_webhook_id'    => 'PayPal webhook ID',
    ];
    foreach ($paypalKeys as $paypalKey => $paypalLabel) {
        $fields[] = adminSettingsField([
            'key' => $paypalKey, 'section' => 'paypal', 'kind' => 'secret', 'group' => 'paypal', 'type' => 'string',
            'label' => $paypalLabel,
            'help' => 'Stored encrypted and never shown again.',
            'description' => $paypalLabel,
        ]);
    }

    // -------------------------------------------------- 6. Prices, VAT, refunds
    $fields[] = adminSettingsField([
        'key' => 'billing_vat_mode', 'section' => 'prices', 'kind' => 'choice', 'group' => 'billing', 'type' => 'string',
        'label' => 'How prices are described',
        'help' => 'Changes the wording on the pricing page straight away.',
        'options' => [
            'inclusive' => 'Prices include VAT',
            'exclusive' => 'Prices exclude VAT',
            'none'      => 'Not VAT-registered (no VAT wording)',
        ],
        'description' => 'How prices are shown: inclusive, exclusive or none',
    ]);
    $fields[] = adminSettingsField([
        'key' => 'billing_vat_rate_percent', 'section' => 'prices', 'kind' => 'decimal', 'group' => 'billing', 'type' => 'float',
        'label' => 'VAT rate (percent)',
        'help' => '0 to 100, up to two decimal places. For example 20 for the UK standard rate.',
        'min' => 0, 'max' => 100,
        'description' => 'VAT rate in percent',
    ]);
    $fields[] = adminSettingsField([
        'key' => 'billing_refund_window_days', 'section' => 'prices', 'kind' => 'int', 'group' => 'billing', 'type' => 'integer',
        'label' => 'Money-back period (days)',
        'help' => '0 to 365. The Terms page must say the same (task #38).',
        'min' => 0, 'max' => 365,
        'description' => 'Money-back period in days',
    ]);

    // 💱 Task 7n Phase 2 (migration 0004): currencies, the price promise and
    //    whether the pricing page shows prices. Group and type match the
    //    migration's rows (tests/test_admin_settings.php checks this).
    // 🌍 Codex finding 1.1: every current ISO 4217 currency whose prices the
    //    database can hold exactly - since upgrade 0006 (decision 0042) that is
    //    all of them, the three-decimal ones such as KWD included.
    $currencyChoices = [];
    foreach (moneyCurrencyInfo() as $currencyCode => $currencyFacts) {
        if (moneyCurrencyCanBeOffered($currencyCode)) {
            $currencyChoices[$currencyCode] = $currencyCode . ' - ' . $currencyFacts['name'];
        }
    }
    $fields[] = adminSettingsField([
        'key' => 'billing_currencies', 'section' => 'prices', 'subgroup' => 'Currencies, price promise and the pricing page', 'kind' => 'currency_list', 'group' => 'billing', 'type' => 'string',
        'label' => 'Currencies prices can be set in',
        'help' => 'Three-letter ISO 4217 codes separated by commas, for example GBP,USD,EUR,CAD. Any current currency can be used, including those with three decimal places (such as KWD) and none (such as JPY). The plan price screen shows one box for each. Removing a currency hides its boxes; prices already saved in it are kept.',
        'allowEmpty' => false, 'maxLength' => 100,
        'description' => 'Currencies prices can be set in: three-letter codes separated by commas, for example GBP,USD,EUR',
    ]);
    $fields[] = adminSettingsField([
        'key' => 'billing_default_currency', 'section' => 'prices', 'subgroup' => 'Currencies, price promise and the pricing page', 'kind' => 'choice', 'group' => 'billing', 'type' => 'string',
        'label' => 'Main currency',
        'help' => 'Must be one of the currencies above. While charging is off, the pricing page shows each plan\'s monthly and yearly prices in this currency. Changing it copies those prices across straight away.',
        'options' => $currencyChoices, 'afterSave' => 'legacy_price_mirror',
        'description' => 'The main currency (a three-letter code such as GBP). Its prices are the ones the public pricing page shows while charging is off',
    ]);
    $fields[] = adminSettingsField([
        'key' => 'billing_price_promise_default_mode', 'section' => 'prices', 'subgroup' => 'Currencies, price promise and the pricing page', 'kind' => 'choice', 'group' => 'billing', 'type' => 'string',
        'label' => 'Price promise for plans that do not set their own',
        'help' => 'How long existing customers keep the price they signed up at when a price goes up. Each plan can choose its own on the Plans screen.',
        'options' => [
            'while_subscribed' => 'For as long as they stay subscribed',
            'fixed_months'     => 'For a set number of months (below)',
            'none'             => 'No promise (a price change applies at their next payment)',
        ],
        'description' => 'Price promise for plans that do not set their own: while_subscribed (customers keep their price for as long as they stay subscribed), fixed_months (for a set number of months) or none',
    ]);
    $fields[] = adminSettingsField([
        'key' => 'billing_price_promise_default_months', 'section' => 'prices', 'subgroup' => 'Currencies, price promise and the pricing page', 'kind' => 'int', 'group' => 'billing', 'type' => 'integer',
        'label' => 'Months the price is kept (for "a set number of months")',
        'help' => '1 to 120. Used only when the promise is "for a set number of months". The default is 12.',
        'min' => 1, 'max' => 120,
        'description' => 'How many months customers keep their price when the price promise is fixed_months',
    ]);
    $fields[] = adminSettingsField([
        'key' => 'pricing_show_prices', 'section' => 'prices', 'subgroup' => 'Currencies, price promise and the pricing page', 'kind' => 'bool', 'group' => 'billing', 'type' => 'boolean',
        'label' => 'Show prices on the pricing page',
        'help' => 'Off hides the price figures; the plans and what they include still show.',
        'description' => 'Pricing page: 1 = show the prices, 0 = hide the price figures (the plans and what they include still show)',
    ]);

    // ------------------------------------------------------------ 7. Free trials
    $fields[] = adminSettingsField([
        'key' => 'billing_trials_enabled', 'section' => 'trials', 'kind' => 'bool', 'group' => 'billing', 'type' => 'boolean',
        'label' => 'Offer free trials',
        'help' => 'Saved now; nothing changes for visitors until the trial sign-up flow is built.',
        'description' => 'Free-trial switch',
    ]);
    $fields[] = adminSettingsField([
        'key' => 'billing_trial_plan_key', 'section' => 'trials', 'kind' => 'plan_key', 'group' => 'billing', 'type' => 'string',
        'label' => 'Plan a trial gives',
        'help' => 'Only paid plans on sale are listed, and only those with no sale dates, no sales cap, a display order above 0 and their own buy button (the plan chosen here must stay on the pricing page).',
        'description' => 'Which plan a free trial gives',
    ]);
    $fields[] = adminSettingsField([
        'key' => 'billing_trial_days', 'section' => 'trials', 'kind' => 'int', 'group' => 'billing', 'type' => 'integer',
        'label' => 'Trial length (days)',
        'help' => '1 to 90.',
        'min' => 1, 'max' => 90,
        'description' => 'Length of a free trial in days',
    ]);
    $fields[] = adminSettingsField([
        'key' => 'billing_trial_requires_card', 'section' => 'trials', 'kind' => 'bool', 'group' => 'billing', 'type' => 'boolean',
        'label' => 'A payment card is needed to start a trial',
        'help' => 'Off means no card is needed.',
        'description' => 'Free trial needs a payment card',
    ]);

    // ------------------------------------------------------- 8. Admin security
    $fields[] = adminSettingsField([
        'key' => 'admin_reauth_idle_minutes', 'section' => 'admin-security', 'kind' => 'int', 'group' => 'admin', 'type' => 'integer',
        'label' => 'Ask for the password again after (minutes without a change)',
        'help' => '5 to 60. The default is 15.',
        'min' => 5, 'max' => 60,
        'description' => 'Admin password confirmation idle limit in minutes',
    ]);
    $fields[] = adminSettingsField([
        'key' => 'admin_reauth_max_minutes', 'section' => 'admin-security', 'kind' => 'int', 'group' => 'admin', 'type' => 'integer',
        'label' => 'The longest a password confirmation lasts (minutes)',
        'help' => '15 to 480, and never shorter than the number above. The default is 60.',
        'min' => 15, 'max' => 480,
        'description' => 'Admin password confirmation absolute limit in minutes',
    ]);
    $fields[] = adminSettingsField([
        'key' => 'admin_display_timezone', 'section' => 'admin-security', 'kind' => 'timezone', 'group' => 'admin', 'type' => 'string',
        'label' => 'Time zone for dates in the admin area',
        'help' => 'For example Europe/London. Stored times stay in UTC.',
        'allowEmpty' => false,
        'description' => 'Time zone for dates shown in the admin area',
    ]);
    $fields[] = adminSettingsField([
        'key' => 'trusted_proxy_ips', 'section' => 'admin-security', 'subgroup' => 'Visitor address', 'kind' => 'ip_ranges', 'group' => 'security', 'type' => 'string',
        'label' => 'Trusted forwarding servers (addresses or ranges)',
        'help' => 'Leave empty unless your host puts its own forwarding server in front of the site. Exact addresses or ranges such as 10.0.0.0/8, separated by commas, spaces or new lines. See "Visitor address check" on the overview page.',
        'maxLength' => 2000, 'rowOptional' => true,
        'description' => 'Forwarding servers trusted to report the visitor address (addresses or CIDR ranges)',
    ]);

    // The two "send me an emailed link" forms share three limits each. The
    // allowed range and the starting value come from fn_auth.php (the same
    // numbers the code enforces), so the editor and the code cannot disagree.
    // The rows are not seeded: with no row the code uses its default, and the
    // first save from this screen creates the row.
    $linkBounds = authLinkRequestLimitBounds();
    $linkForms = [
        'verification_resend'    => 'Asking for a new verification link',
        'password_reset_request' => 'Forgot password',
    ];
    $linkLimitWords = [
        'min_gap_seconds' => [
            'label' => 'Shortest wait between two links to the same account (seconds)',
            'what'  => 'The shortest time, in whole seconds, before the same account can be sent another link.',
            'text'  => 'Seconds between links to the same account',
        ],
        'daily_cap' => [
            'label' => 'Most links one account can be sent in 24 hours',
            'what'  => 'The most links one account can be sent in 24 hours.',
            'text'  => 'Most links per account in 24 hours',
        ],
        'ip_per_hour' => [
            'label' => 'Most requests from one visitor address in an hour',
            'what'  => 'The most requests one visitor address can make in an hour, whoever the account is.',
            'text'  => 'Most requests per visitor address per hour',
        ],
    ];
    foreach ($linkForms as $linkPrefix => $linkFormName) {
        foreach ($linkLimitWords as $linkSuffix => $linkWords) {
            $linkBound = $linkBounds[$linkSuffix];
            $fields[] = adminSettingsField([
                'key' => $linkPrefix . '_' . $linkSuffix, 'section' => 'admin-security', 'subgroup' => $linkFormName,
                'kind' => 'int', 'group' => 'security', 'type' => 'integer',
                'label' => $linkWords['label'],
                'help' => $linkWords['what'] . ' Whole numbers, ' . $linkBound['min'] . ' to ' . $linkBound['max'] . '. The default is ' . $linkBound['default'] . '. A bigger gap or a smaller number is stricter.',
                'min' => $linkBound['min'], 'max' => $linkBound['max'], 'rowOptional' => true, 'default' => $linkBound['default'],
                'description' => $linkFormName . ': ' . $linkWords['text'],
            ]);
        }
    }

    $registry = [];
    foreach ($fields as $field) {
        $registry[$field['key']] = $field;
    }

    return $registry;
}

/**
 * 📑 The registry entries of one section, in page order. PURE.
 *
 * @param string $sectionId One of adminSettingsSections()'s keys
 * @return array<string, array<string, mixed>> Empty for an unknown section
 */
function adminSettingsFieldsForSection(string $sectionId): array
{
    $found = [];

    foreach (adminSettingsRegistry() as $key => $field) {
        if ($field['section'] === $sectionId) {
            $found[$key] = $field;
        }
    }

    return $found;
}

/**
 * 🤫 Does this registry entry hold a secret? PURE.
 *
 * @param array<string, mixed> $field A registry entry
 * @return bool
 */
function adminSettingsFieldIsSecret(array $field): bool
{
    return isset($field['kind']) && $field['kind'] === 'secret';
}

// ==============================================================================
// ✅ PURE: validators (plan §7.2)
// ==============================================================================

/**
 * 🔎 Is this text a GUID (8-4-4-4-12 hexadecimal)? PURE.
 *
 * @param string $text Text to test
 * @return bool
 */
function adminSettingsIsGuid(#[\SensitiveParameter] string $text): bool
{
    return preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $text) === 1;
}

/**
 * 🔎 Is this text a domain name such as example.com? PURE.
 *
 * @param string $text Text to test
 * @return bool
 */
function adminSettingsIsDomainName(#[\SensitiveParameter] string $text): bool
{
    if (strlen($text) > 253) {
        return false;
    }

    return preg_match('/^([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$/', $text) === 1;
}

/**
 * 🙈 Does this text hold a hidden character? PURE. "Hidden" means a control
 *    character (so a line break or tab too), an invisible formatting
 *    character (zero-width space, direction marks, the byte-order mark) or
 *    the Unicode line and paragraph separators. The text must already be
 *    valid UTF-8 (the callers check that first); invalid text counts as
 *    hidden-character trouble too, so it is never let through.
 *
 * @param string $text            Text to test
 * @param bool   $allowLineBreaks True to let ordinary tab, line feed and carriage return through
 * @return bool
 */
function adminSettingsHasHiddenCharacter(#[\SensitiveParameter] string $text, bool $allowLineBreaks): bool
{
    if ($allowLineBreaks) {
        $text = str_replace(["\t", "\n", "\r"], ' ', $text);
    }

    $found = preg_match('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', $text);

    // preg_match() gives false for invalid UTF-8: treat that as a problem.
    return ($found !== 0);
}

/**
 * 🤫 Check a secret VALUE and tidy it (trim). PURE. Error messages never
 *    repeat the value.
 *
 * @param string $typed  What was typed (may be empty)
 * @param string $format '', 'guid' or 'guid_or_domain'
 * @return array{ok: bool, value: string, error: string}
 */
function adminSettingsCheckSecretValue(#[\SensitiveParameter] string $typed, string $format): array
{
    $value = trim($typed);

    if ($value === '') {
        return ['ok' => true, 'value' => '', 'error' => ''];
    }

    // 🔤 Invalid UTF-8 is refused first (the pattern below needs valid text).
    if (preg_match('//u', $value) !== 1) {
        return ['ok' => false, 'value' => '', 'error' => 'That contains characters that are not valid text. Copy and paste it again.'];
    }

    // 🚫 Control characters (so line breaks and tabs), invisible formatting
    //    characters (zero-width spaces, direction marks) and the Unicode line
    //    and paragraph separators inside the value.
    if (adminSettingsHasHiddenCharacter($value, false)) {
        return ['ok' => false, 'value' => '', 'error' => 'That contains a line break or another hidden character. Copy and paste it again, without anything around it.'];
    }

    if (mb_strlen($value, 'UTF-8') > CUERCODE_ADMIN_SECRET_MAX_LENGTH) {
        return ['ok' => false, 'value' => '', 'error' => 'That is too long (the limit is ' . CUERCODE_ADMIN_SECRET_MAX_LENGTH . ' characters).'];
    }

    if ($format === 'guid') {
        if (!adminSettingsIsGuid($value)) {
            return ['ok' => false, 'value' => '', 'error' => 'That does not look right. It should be letters and numbers in groups separated by dashes (8-4-4-4-12).'];
        }
    } elseif ($format === 'guid_or_domain') {
        if (!adminSettingsIsGuid($value) && !adminSettingsIsDomainName($value)) {
            return ['ok' => false, 'value' => '', 'error' => 'That does not look right. It should be the tenant ID (letters and numbers in groups separated by dashes) or a domain name such as example.com.'];
        }
    } elseif ($format === 'go2mylink_key') {
        // 🔗 #165: Go2My.Link's key shape (g2ml_apiGenerateKey(): "g2ml_",
        //    8 characters, "_", 43 characters, all letters, digits, - or _).
        if (preg_match('/^g2ml_[A-Za-z0-9_-]{8}_[A-Za-z0-9_-]{43}$/D', $value) !== 1) {
            return ['ok' => false, 'value' => '', 'error' => 'That does not look like a Go2My.Link API key. It starts with g2ml_ and is 57 characters long. Copy it again from the Go2My.Link dashboard.'];
        }
    }

    return ['ok' => true, 'value' => $value, 'error' => ''];
}

/**
 * 🤫 What should happen to a secret, from its box and its "Remove" tick.
 *    PURE.
 *
 *   - tick + typed text  → refused (which one was meant?);
 *   - tick               → 'clear';
 *   - empty box          → 'keep' (the stored value stays);
 *   - typed text         → 'set' (after checks) or 'invalid'.
 *
 * @param string $typed  The box
 * @param bool   $remove The "Remove" tick
 * @param string $format '', 'guid' or 'guid_or_domain'
 * @return array{action: string, value: string, error: string}
 */
function adminSettingsSecretIntent(#[\SensitiveParameter] string $typed, bool $remove, string $format): array
{
    if ($remove && trim($typed) !== '') {
        return ['action' => 'invalid', 'value' => '', 'error' => 'Either type a new value or tick "Remove", not both.'];
    }

    if ($remove) {
        return ['action' => 'clear', 'value' => '', 'error' => ''];
    }

    if (trim($typed) === '') {
        return ['action' => 'keep', 'value' => '', 'error' => ''];
    }

    $checked = adminSettingsCheckSecretValue($typed, $format);
    if (!$checked['ok']) {
        return ['action' => 'invalid', 'value' => '', 'error' => $checked['error']];
    }

    return ['action' => 'set', 'value' => $checked['value'], 'error' => ''];
}

/**
 * 🌐 Check a discovery address (an https web address the site will fetch from
 *    the server). PURE. Because the SITE visits this address, it is kept
 *    narrow: https only; a website NAME (never an IP number, written plainly
 *    or in square brackets, and never "localhost"); no port except the
 *    standard 443; no user name or password; and ASCII only (an international
 *    name must be written in its "xn--" form).
 *
 * 🧹 Final review N-3 (2026-11-25): also refused - a name with only one part
 *    ("intranet"), and names that only ever point inside a network or are
 *    made to point anywhere: *.localhost, *.local, *.internal, *.localdomain,
 *    *.home.arpa, and the "address inside the name" services *.nip.io,
 *    *.sslip.io and *.xip.io (where 10.0.0.1.nip.io means 10.0.0.1).
 *
 *    ⚠️ This is HYGIENE, not protection against the site being tricked into
 *    calling something private: any public name can be pointed at a private
 *    address by whoever controls it, and no list of names can stop that. The
 *    real controls are in fn_oauth.php's fetches: the certificate must be
 *    valid for the name (TLS checks on), redirects are never followed, and
 *    only https is spoken. This list just stops obvious mistakes and makes a
 *    plainly wrong address fail here, with a clear message, rather than later.
 *
 * @param string $text The trimmed address
 * @return array{ok: bool, value: string, error: string}
 */
function adminSettingsValidateHttpsAddress(string $text): array
{
    $refuse = static function (string $message): array {
        return ['ok' => false, 'value' => '', 'error' => $message];
    };

    if (preg_match('/\s/', $text) === 1) {
        return $refuse('An address cannot contain spaces.');
    }

    // A backslash is read differently by browsers and servers: never allowed.
    if (str_contains($text, '\\')) {
        return $refuse('Type a web address that starts with https:// (use / and not \\).');
    }

    // Split off the part between "https://" and the first / ? or #.
    if (preg_match('~^https://([^/?#]*)~i', $text, $match) !== 1 || $match[1] === '') {
        return $refuse('Type a web address that starts with https:// followed by a website name.');
    }
    $authority = $match[1];

    if (str_contains($authority, '@')) {
        return $refuse('Type a web address with no user name or password in it.');
    }

    if (str_starts_with($authority, '[')) {
        return $refuse('The address must name a website (such as login.example.com), not an IP number.');
    }

    $host = $authority;
    $colon = strpos($authority, ':');
    if ($colon !== false) {
        $host = substr($authority, 0, $colon);
        if (substr($authority, $colon + 1) !== '443') {
            return $refuse('Leave the port out (or use :443). No other port is allowed.');
        }
    }

    if (preg_match('/^[\x21-\x7E]+$/', $host) !== 1) {
        return $refuse('Write the website name with letters, numbers and dashes only. An international name must be written in its "xn--" form.');
    }

    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return $refuse('The address must name a website (such as login.example.com), not an IP number.');
    }

    // Labels of letters, numbers and dashes, at least two of them, and a last
    // label that starts with a letter (so "127.1" and "0x7f.1" never pass).
    if (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $host) !== 1) {
        return $refuse('The address must name a website with a proper domain, such as login.example.com.');
    }

    // 🧹 N-3: names that only work inside a network, or that turn any
    //    address into a name (hygiene - see the docblock).
    $lowerHost = strtolower($host);
    foreach (adminSettingsRefusedHostSuffixes() as $suffix) {
        if ($lowerHost === $suffix || str_ends_with($lowerHost, '.' . $suffix)) {
            return $refuse('The address must be a public website name, such as login.example.com. Names ending in .' . $suffix . ' are not accepted.');
        }
    }

    if (parse_url($text) === false) {
        return $refuse('That does not look like a web address.');
    }

    return ['ok' => true, 'value' => $text, 'error' => ''];
}

/**
 * 🧹 The name endings a discovery address may not have (final review N-3).
 *    PURE. Names that only ever point inside a network (localhost, local,
 *    internal, localdomain, home.arpa) and services that turn an address
 *    written into the name into that address (nip.io, sslip.io, xip.io).
 *    Hygiene only - see adminSettingsValidateHttpsAddress().
 *
 * @return array<int, string> Lower-case endings, without the leading dot
 */
function adminSettingsRefusedHostSuffixes(): array
{
    return ['localhost', 'local', 'internal', 'localdomain', 'home.arpa', 'nip.io', 'sslip.io', 'xip.io'];
}

/**
 * 🏠 Check the website's own address (the "site_url" setting). PURE.
 *
 *    It is the discovery-address check (https only, a proper public website
 *    name, no IP number, no localhost or internal names, no user name or
 *    password, no odd port) PLUS: nothing after the website name. A path, a
 *    query, a "#" part or a doubled slash is refused, because the code adds
 *    its own path ("/verify-email?token=..."). One trailing slash is accepted
 *    and removed, ":443" is removed, and the scheme and name are written in
 *    lower case, so the saved value is always the one plain form
 *    "https://name.example".
 *
 * @param string $text The trimmed address
 * @return array{ok: bool, value: string, error: string}
 */
function adminSettingsValidateSiteAddress(string $text): array
{
    // 1. The shared rules first (https, public-looking name, no IP, no port but 443 ...).
    $shared = adminSettingsValidateHttpsAddress($text);
    if ($shared['ok'] !== true) {
        return $shared;
    }

    // 2. Split the name from whatever follows it. The shared check proved the
    //    text starts with "https://" and a non-empty name.
    if (preg_match('~^https://([^/?#]*)(.*)$~is', $text, $parts) !== 1) {
        return ['ok' => false, 'value' => '', 'error' => 'That does not look like a web address.'];
    }
    $authority = strtolower($parts[1]);
    $rest      = $parts[2];

    // 3. Only a single "/" may follow (a trailing slash, which is removed).
    if ($rest !== '' && $rest !== '/') {
        return ['ok' => false, 'value' => '', 'error' => 'Give only the website name, for example https://alpha.example.com. Leave out any page path, "?" part or "#" part.'];
    }

    // 4. ":443" is the normal HTTPS port: drop it so the value is written one way.
    if (str_ends_with($authority, ':443')) {
        $authority = substr($authority, 0, -4);
    }

    return ['ok' => true, 'value' => 'https://' . $authority, 'error' => ''];
}

/**
 * ✅ Validate and tidy ONE non-secret value for its registry entry (and, for
 *    secrets, the value mode: '' means "clear"). PURE.
 *
 * @param array<string, mixed> $field   A registry entry
 * @param string               $raw     What was submitted
 * @param array<string, mixed> $context Extra facts: 'planKeys' (list of plan keys a trial may use)
 * @return array{ok: bool, value: string, error: string} value is the text to store
 */
function adminSettingsValidateValue(array $field, #[\SensitiveParameter] string $raw, array $context = []): array
{
    $kind = (string) $field['kind'];

    if ($kind === 'secret') {
        return adminSettingsCheckSecretValue($raw, (string) $field['secretFormat']);
    }

    $text = trim($raw);

    // 🔤 Invalid UTF-8 is refused first (the hidden-character test needs valid text).
    if (preg_match('//u', $text) !== 1) {
        return ['ok' => false, 'value' => '', 'error' => 'That contains characters that are not valid text.'];
    }

    // 🚫 No control characters, line breaks, invisible formatting characters
    //    or Unicode line separators in any single-line value. The list of
    //    trusted forwarding servers is a multi-line box, so ordinary line
    //    breaks and tabs are fine there (and only there).
    if ($kind !== 'ip_ranges') {
        if (adminSettingsHasHiddenCharacter($text, false)) {
            return ['ok' => false, 'value' => '', 'error' => 'That contains a line break or another hidden character.'];
        }
    } elseif (adminSettingsHasHiddenCharacter($text, true)) {
        return ['ok' => false, 'value' => '', 'error' => 'That contains a hidden character.'];
    }

    $maxLength = (int) $field['maxLength'];
    if (mb_strlen($text, 'UTF-8') > $maxLength) {
        return ['ok' => false, 'value' => '', 'error' => 'That is too long (the limit is ' . $maxLength . ' characters).'];
    }

    // 🈳 Empty is allowed for some entries and refused for others.
    if ($text === '' && $kind !== 'bool') {
        // Only free-text style entries may be empty; a number, a choice, a
        // time zone and so on must always have a value.
        if ($field['allowEmpty'] === true && in_array($kind, ['text', 'email', 'ip_ranges'], true)) {
            return ['ok' => true, 'value' => '', 'error' => ''];
        }
        return ['ok' => false, 'value' => '', 'error' => 'This cannot be empty.'];
    }

    if ($kind === 'bool') {
        if ($text === '1') {
            return ['ok' => true, 'value' => '1', 'error' => ''];
        }
        if ($text === '' || $text === '0') {
            return ['ok' => true, 'value' => '0', 'error' => ''];
        }
        return ['ok' => false, 'value' => '', 'error' => 'Choose on or off.'];
    }

    if ($kind === 'int') {
        // 🔢 Digits only: no sign, no decimal point, no "1e3" - and no leading
        //    zero ("01" and "000030" are refused the same way, so a number is
        //    only ever written one way).
        // 📣 Final review M-4: the message names the allowed range (and only
        //    mentions a leading zero when there is one), because a value like
        //    "-5", "30.0" or "1e3" may have been stored by hand rather than
        //    typed by the admin now.
        if (!adminSettingsNumberTextIsCanonical('int', $text)) {
            $intRange = 'Type a whole number from ' . (int) $field['min'] . ' to ' . (int) $field['max'];
            if (preg_match('/^0[0-9]+$/', $text) === 1) {
                return ['ok' => false, 'value' => '', 'error' => $intRange . ', with no zero at the start.'];
            }
            return ['ok' => false, 'value' => '', 'error' => $intRange . '.'];
        }
        $number = (int) $text;
        if ($number < (int) $field['min'] || $number > (int) $field['max']) {
            return ['ok' => false, 'value' => '', 'error' => 'Type a whole number from ' . (int) $field['min'] . ' to ' . (int) $field['max'] . '.'];
        }
        // 🛡️ The email clean-up periods are also checked by the rule the
        //    clean-up job itself uses, so the two can never disagree.
        if (str_starts_with((string) $field['key'], 'email_queue_') && function_exists('parseEmailQueueRetentionDays')) {
            if (parseEmailQueueRetentionDays($text) === null) {
                return ['ok' => false, 'value' => '', 'error' => 'Type a whole number from 1 to 3650.'];
            }
        }
        return ['ok' => true, 'value' => (string) $number, 'error' => ''];
    }

    if ($kind === 'decimal') {
        if (!adminSettingsNumberTextIsCanonical('decimal', $text)) {
            $decimalRange = 'Type a number from ' . (int) $field['min'] . ' to ' . (int) $field['max'] . ' with up to two decimal places';
            if (preg_match('/^0[0-9]/', $text) === 1) {
                return ['ok' => false, 'value' => '', 'error' => $decimalRange . ' and no zero at the start, for example 20 or 17.5.'];
            }
            return ['ok' => false, 'value' => '', 'error' => $decimalRange . ', for example 20 or 17.5.'];
        }
        $number = (float) $text;
        if ($number < (float) $field['min'] || $number > (float) $field['max']) {
            return ['ok' => false, 'value' => '', 'error' => 'Type a number from ' . (int) $field['min'] . ' to ' . (int) $field['max'] . '.'];
        }
        return ['ok' => true, 'value' => number_format($number, 2, '.', ''), 'error' => ''];
    }

    if ($kind === 'choice') {
        if (!array_key_exists($text, $field['options'])) {
            return ['ok' => false, 'value' => '', 'error' => 'Choose one of the options listed.'];
        }
        return ['ok' => true, 'value' => $text, 'error' => ''];
    }

    if ($kind === 'email') {
        if (preg_match('/\s/', $text) === 1 || strlen($text) > 254 || filter_var($text, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'value' => '', 'error' => 'Type a full email address, for example notifications@example.com.'];
        }
        // 🚫 PHP accepts "a@[127.0.0.1]" (an address written with an IP number
        //    in square brackets). A mailbox is never given that way.
        if (preg_match('/@\[/', $text) === 1) {
            return ['ok' => false, 'value' => '', 'error' => 'Type an email address that ends in a domain name such as example.com, not in an IP number.'];
        }
        return ['ok' => true, 'value' => $text, 'error' => ''];
    }

    if ($kind === 'https_url') {
        return adminSettingsValidateHttpsAddress($text);
    }

    if ($kind === 'site_url') {
        return adminSettingsValidateSiteAddress($text);
    }

    if ($kind === 'scopes') {
        $tokens = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($tokens) || $tokens === []) {
            return ['ok' => false, 'value' => '', 'error' => 'Type the scopes, separated by spaces. They must include openid.'];
        }
        $clean = [];
        foreach ($tokens as $token) {
            if (preg_match('/^[a-z0-9.:\/_-]+$/', $token) !== 1) {
                return ['ok' => false, 'value' => '', 'error' => 'Scopes can only use lower-case letters, numbers and . : / _ - characters.'];
            }
            if (!in_array($token, $clean, true)) {
                $clean[] = $token;
            }
        }
        if (!in_array('openid', $clean, true)) {
            return ['ok' => false, 'value' => '', 'error' => 'The scopes must include openid.'];
        }
        return ['ok' => true, 'value' => implode(' ', $clean), 'error' => ''];
    }

    if ($kind === 'timezone') {
        if (!in_array($text, \DateTimeZone::listIdentifiers(), true)) {
            return ['ok' => false, 'value' => '', 'error' => 'Type a time zone name such as Europe/London.'];
        }
        return ['ok' => true, 'value' => $text, 'error' => ''];
    }

    if ($kind === 'plan_key') {
        $planKeys = [];
        if (isset($context['planKeys']) && is_array($context['planKeys'])) {
            $planKeys = $context['planKeys'];
        }
        if (!in_array($text, $planKeys, true)) {
            return ['ok' => false, 'value' => '', 'error' => 'Choose one of the plans listed.'];
        }
        return ['ok' => true, 'value' => $text, 'error' => ''];
    }

    if ($kind === 'ip_ranges') {
        if (!function_exists('clientIpParseRangeList')) {
            return ['ok' => false, 'value' => '', 'error' => 'The address checker is not available, so this cannot be saved.'];
        }
        $entries = preg_split('/[\s,]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($entries)) {
            $entries = [];
        }
        $parsed = clientIpParseRangeList(implode(' ', $entries));
        if ($parsed['invalid'] !== []) {
            // 📏 Decision 0015: say WHY each entry is refused (not an address,
            //    a "/024"-style number, or a range wider than /8 or /32). The
            //    first three reasons are shown so the message stays readable.
            $reasons = [];
            if (isset($parsed['problems']) && is_array($parsed['problems'])) {
                $reasons = array_slice($parsed['problems'], 0, 3);
            }
            if ($reasons === []) {
                return ['ok' => false, 'value' => '', 'error' => 'These are not valid addresses or ranges: ' . implode(', ', array_slice($parsed['invalid'], 0, 3)) . '.'];
            }
            $more = '';
            if (count($parsed['invalid']) > 3) {
                $more = ' (' . (count($parsed['invalid']) - 3) . ' more entries have problems too.)';
            }
            return ['ok' => false, 'value' => '', 'error' => implode(' ', $reasons) . $more];
        }
        return ['ok' => true, 'value' => implode(', ', $entries), 'error' => ''];
    }

    if ($kind === 'currency_list') {
        // 💱 billing_currencies: known codes only, each once, stored as
        //    "GBP,USD,EUR" (upper case, commas, no spaces).
        $parsed = moneyParseCurrencyList($text);
        if (!$parsed['ok']) {
            return ['ok' => false, 'value' => '', 'error' => $parsed['error']];
        }
        return ['ok' => true, 'value' => implode(',', $parsed['codes']), 'error' => ''];
    }

    // 🔤 'text': plain text.
    return ['ok' => true, 'value' => $text, 'error' => ''];
}

/**
 * 🔢 Is this number written in the one allowed way? PURE. A whole number is
 *    "0" or digits that do not start with 0 (up to 6); a decimal is the same
 *    (up to 3 digits) with an optional "." and one or two more digits. So
 *    "01", "000030", "+5", "1e3" and ".5" are all refused, and "30" and
 *    "030" can never be treated differently.
 *
 * @param string $kind 'int' or 'decimal'
 * @param string $text The (already trimmed) text
 * @return bool
 */
function adminSettingsNumberTextIsCanonical(string $kind, string $text): bool
{
    if ($kind === 'int') {
        return preg_match('/^(?:0|[1-9][0-9]{0,5})$/', $text) === 1;
    }

    if ($kind === 'decimal') {
        return preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,2})?$/', $text) === 1;
    }

    return false;
}

/**
 * 🔄 A stored value in the same shape the validator produces, so "unchanged"
 *    can be told reliably (the database keeps '1' for on, '30' for 30 and so
 *    on, but may hold older spellings). PURE.
 *
 * @param array<string, mixed> $field A registry entry
 * @param string|null          $raw   What the row holds
 * @return string
 */
function adminSettingsCanonicalCurrent(array $field, ?string $raw): string
{
    if ($raw === null) {
        $raw = '';
    }

    $kind = (string) $field['kind'];

    if ($kind === 'bool') {
        if (filter_var($raw, FILTER_VALIDATE_BOOLEAN)) {
            return '1';
        }
        return '0';
    }

    if ($kind === 'int') {
        if (preg_match('/^\s*[0-9]+\s*$/', $raw) === 1) {
            return (string) ((int) trim($raw));
        }
        return trim($raw);
    }

    if ($kind === 'decimal') {
        if (is_numeric(trim($raw))) {
            return number_format((float) trim($raw), 2, '.', '');
        }
        return trim($raw);
    }

    if ($kind === 'ip_ranges') {
        $entries = preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($entries)) {
            return implode(', ', $entries);
        }
    }

    if ($kind === 'currency_list') {
        $parsed = moneyParseCurrencyList($raw);
        if ($parsed['ok']) {
            return implode(',', $parsed['codes']);
        }
    }

    return trim($raw);
}

/**
 * 🧮 Work out what a submitted section would change, and what is wrong with
 *    it. PURE (no database): the caller passes the current state and the
 *    plan keys. Reads only the registry entries of THIS section, so a field
 *    from another section - or a locked key - in a hand-made request is never
 *    even looked at.
 *
 * Form field names: f[<key>] for every box; rm[<key>] for a secret's "Remove"
 * tick; confirm[<key>] for a "confirm" tick. A box that is missing from the
 * request is left alone.
 *
 * @param string                                  $sectionId Section id
 * @param array<string, mixed>                    $post      The $_POST array
 * @param array<string, array<string, mixed>>     $current   key => state from adminSettingsStateFromRow()
 * @param array<string, mixed>                    $context   'planKeys' => list
 * @return array{unknownSection: bool, errors: array<string, string>, changes: array<int, array<string, mixed>>}
 */
function adminSettingsProcessSection(string $sectionId, #[\SensitiveParameter] array $post, array $current, array $context = []): array
{
    $outcome = ['unknownSection' => false, 'errors' => [], 'changes' => []];

    $fields = adminSettingsFieldsForSection($sectionId);
    if ($fields === []) {
        $outcome['unknownSection'] = true;
        return $outcome;
    }

    $posted = [];
    if (isset($post['f']) && is_array($post['f'])) {
        $posted = $post['f'];
    }
    $removeTicks = [];
    if (isset($post['rm']) && is_array($post['rm'])) {
        $removeTicks = $post['rm'];
    }
    $confirmTicks = [];
    if (isset($post['confirm']) && is_array($post['confirm'])) {
        $confirmTicks = $post['confirm'];
    }

    // 📌 What each box will hold after saving (for the idle/max rule below).
    $newCanonical = [];

    foreach ($fields as $key => $field) {
        $state = ['exists' => false, 'hasValue' => false, 'value' => null];
        if (isset($current[$key]) && is_array($current[$key])) {
            $state = array_merge($state, $current[$key]);
        }

        // ---------------------------------------------------------------- secret
        if (adminSettingsFieldIsSecret($field)) {
            $typed = '';
            if (isset($posted[$key])) {
                if (!is_string($posted[$key])) {
                    $outcome['errors'][$key] = 'That was not understood.';
                    continue;
                }
                $typed = $posted[$key];
            }
            $remove = (isset($removeTicks[$key]) && $removeTicks[$key] === '1');

            $intent = adminSettingsSecretIntent($typed, $remove, (string) $field['secretFormat']);
            // 🧹 The typed copy is not needed any more.
            $typed = '';

            if ($intent['action'] === 'invalid') {
                $outcome['errors'][$key] = $intent['error'];
                continue;
            }
            if ($intent['action'] === 'keep') {
                continue;
            }
            if ($intent['action'] === 'clear' && !$state['hasValue']) {
                // Nothing stored, nothing to remove.
                continue;
            }
            $outcome['changes'][] = [
                'key'    => $key,
                'action' => $intent['action'],
                'value'  => $intent['value'],
            ];
            continue;
        }

        // ------------------------------------------------------------ not a secret
        if (!array_key_exists($key, $posted)) {
            continue;
        }
        if (!is_string($posted[$key])) {
            $outcome['errors'][$key] = 'That was not understood.';
            continue;
        }

        $currentCanonical = adminSettingsCanonicalCurrent($field, $state['value']);
        $newCanonical[$key] = $currentCanonical;

        // ➖ Same as what is stored: nothing to check, nothing to save. A
        //    number only counts as "the same" when it is written the one
        //    allowed way (so "030" is refused like "000030", never waved
        //    through because it happens to equal the stored 30).
        $postedTrimmed = trim($posted[$key]);

        // ➖ Final review M-4: exactly what is stored, sent back untouched -
        //    skip it, even when the stored text is not written the allowed
        //    way (a value such as "-5", "30.0" or "1e3" put in the database
        //    by hand). The form draws the stored value as it is, so saving
        //    the section untouched must never fail because of it. (A row
        //    must exist for this: an empty box for a missing row is handled
        //    just below.)
        if ($state['exists'] === true && $postedTrimmed === $currentCanonical) {
            continue;
        }

        // 📌 Final review M-3: a setting that is allowed to have no row (the
        //    code then uses its own default) and has none: an empty box means
        //    "keep using the default" - nothing to save, nothing to refuse.
        if ($field['rowOptional'] === true && $state['exists'] !== true && $postedTrimmed === '') {
            continue;
        }

        $writtenProperly = true;
        if ($field['kind'] === 'int' || $field['kind'] === 'decimal') {
            $writtenProperly = adminSettingsNumberTextIsCanonical((string) $field['kind'], $postedTrimmed);
        }
        if ($writtenProperly && adminSettingsCanonicalCurrent($field, $posted[$key]) === $currentCanonical) {
            continue;
        }

        $checked = adminSettingsValidateValue($field, $posted[$key], $context);
        if (!$checked['ok']) {
            $outcome['errors'][$key] = $checked['error'];
            continue;
        }

        $newCanonical[$key] = $checked['value'];

        if ($checked['value'] === $currentCanonical) {
            continue;
        }

        // ✋ "Are you sure?" ticks that the server checks itself (works with JavaScript off).
        if ($field['confirmText'] !== '' && $checked['value'] === '1') {
            if (!isset($confirmTicks[$key]) || $confirmTicks[$key] !== '1') {
                $outcome['errors'][$key] = 'Tick the box to confirm: ' . $field['confirmText'];
                continue;
            }
        }

        $outcome['changes'][] = [
            'key'    => $key,
            'action' => 'update',
            'value'  => $checked['value'],
        ];
    }

    // ⏱️ The two password-confirmation limits must make sense together,
    //    counting the saved value for a box that was not in the request.
    if ($sectionId === 'admin-security') {
        foreach (['admin_reauth_idle_minutes', 'admin_reauth_max_minutes'] as $limitKey) {
            if (!isset($newCanonical[$limitKey]) && isset($current[$limitKey]['value'])) {
                $newCanonical[$limitKey] = adminSettingsCanonicalCurrent($fields[$limitKey], (string) $current[$limitKey]['value']);
            }
        }
        $idleRaw = CUERCODE_ADMIN_REAUTH_IDLE_DEFAULT;
        $maxRaw  = CUERCODE_ADMIN_REAUTH_MAX_DEFAULT;
        if (isset($newCanonical['admin_reauth_idle_minutes']) && ctype_digit($newCanonical['admin_reauth_idle_minutes'])) {
            $idleRaw = (int) $newCanonical['admin_reauth_idle_minutes'];
        }
        if (isset($newCanonical['admin_reauth_max_minutes']) && ctype_digit($newCanonical['admin_reauth_max_minutes'])) {
            $maxRaw = (int) $newCanonical['admin_reauth_max_minutes'];
        }
        if (!isset($outcome['errors']['admin_reauth_max_minutes']) && !isset($outcome['errors']['admin_reauth_idle_minutes']) && $maxRaw < $idleRaw) {
            $outcome['errors']['admin_reauth_max_minutes'] = 'This cannot be shorter than the "without a change" time above (' . $idleRaw . ' minutes).';
        }
    }

    // 💱 The main currency must be one of the offered currencies, counting
    //    the saved value for a box that was not in the request. Checked only
    //    when both are known (a request about something else in this section
    //    is not refused because of them).
    if ($sectionId === 'prices') {
        foreach (['billing_currencies', 'billing_default_currency'] as $currencyKey) {
            if (!isset($newCanonical[$currencyKey]) && isset($current[$currencyKey]['value']) && is_string($current[$currencyKey]['value'])) {
                $newCanonical[$currencyKey] = adminSettingsCanonicalCurrent($fields[$currencyKey], $current[$currencyKey]['value']);
            }
        }
        if (
            isset($newCanonical['billing_currencies'], $newCanonical['billing_default_currency'])
            && !isset($outcome['errors']['billing_currencies'])
            && !isset($outcome['errors']['billing_default_currency'])
            && $newCanonical['billing_default_currency'] !== ''
        ) {
            $offeredCheck = moneyParseCurrencyList($newCanonical['billing_currencies']);
            if ($offeredCheck['ok'] && !in_array($newCanonical['billing_default_currency'], $offeredCheck['codes'], true)) {
                $outcome['errors']['billing_default_currency'] = 'The main currency must be one of the currencies prices can be set in (' . implode(', ', $offeredCheck['codes']) . ').';
            }
        }
    }

    return $outcome;
}

// ==============================================================================
// 🧠 PURE: the VAT note and the test-email limit
// ==============================================================================

/**
 * 📨 May another test email be sent? At most CUERCODE_ADMIN_TEST_EMAIL_MAX in
 *    CUERCODE_ADMIN_TEST_EMAIL_WINDOW_SECONDS. PURE.
 *
 * @param array<int, int> $times Unix times of earlier test emails in this session
 * @param int             $now   Now (Unix time)
 * @param int             $max   Most allowed in the window
 * @param int             $window Window length in seconds
 * @return array{allowed: bool, times: array<int, int>, waitSeconds: int} times = the ones still inside the window
 */
function adminSettingsTestEmailWindow(array $times, int $now, int $max = CUERCODE_ADMIN_TEST_EMAIL_MAX, int $window = CUERCODE_ADMIN_TEST_EMAIL_WINDOW_SECONDS): array
{
    $recent = [];
    foreach ($times as $time) {
        if (is_int($time) && $time <= $now && ($now - $time) < $window) {
            $recent[] = $time;
        }
    }
    sort($recent);

    if (count($recent) < $max) {
        return ['allowed' => true, 'times' => $recent, 'waitSeconds' => 0];
    }

    // The oldest one inside the window ends first; a place frees up then.
    $freesAt = $recent[count($recent) - $max] + $window;

    return ['allowed' => false, 'times' => $recent, 'waitSeconds' => max(1, $freesAt - $now)];
}

// ==============================================================================
// 🗄️ DATABASE: reading the rows
// ==============================================================================

/**
 * 🧱 Turn a tblSettings row into the state the page and the validators use.
 *    PURE. A sensitive row's value (the ciphertext) is DROPPED here: only
 *    "has a value" survives, so a secret can never reach the page by accident.
 *
 * @param array<string, mixed> $row A row with settingKey, settingType, isSensitive, isEditable, settingValue, updatedAt
 * @return array<string, mixed>
 */
function adminSettingsStateFromRow(#[\SensitiveParameter] array $row): array
{
    $isSensitive = ((int) $row['isSensitive'] === 1);

    $rawValue = null;
    if (isset($row['settingValue'])) {
        $rawValue = (string) $row['settingValue'];
    }

    $hasValue = ($rawValue !== null && trim($rawValue) !== '');

    $value = null;
    if (!$isSensitive) {
        $value = $rawValue;
    }

    $updatedAt = null;
    if (isset($row['updatedAt'])) {
        $updatedAt = (string) $row['updatedAt'];
    }

    // 🔖 A change marker for the lost-update check (plan §7.5): a short hash
    //    of the stored value (for a secret, of its ciphertext, which is new on
    //    every save) and the last-changed time. The value itself is dropped
    //    below; only this hash is kept, and it cannot be turned back into it.
    $marker = substr(hash('sha256', (string) $rawValue . "\x1f" . (string) $updatedAt), 0, 24);

    return [
        'exists'      => true,
        'marker'      => $marker,
        'type'        => (string) $row['settingType'],
        'isSensitive' => $isSensitive,
        'isEditable'  => ((int) $row['isEditable'] === 1),
        'hasValue'    => $hasValue,
        'value'       => $value,
        'updatedAt'   => $updatedAt,
    ];
}

/**
 * 🧾 The fingerprint of what one section's form showed (plan §7.5, lost-update
 *    protection). PURE. Every section form carries this in a hidden box; the
 *    save recomputes it from the database INSIDE its transaction (with the
 *    rows locked) and refuses when it differs, so a form opened earlier can
 *    never silently undo a newer change.
 *
 * What goes in: the section's name, then for each registry entry in the
 * section its key and either "set" / "not set" (a secret - never its value)
 * or its saved value, plus its change marker (a hash of the stored value and
 * its last-changed time; see adminSettingsStateFromRow()). The marker is why
 * a change within the same second, or a secret replaced by another secret,
 * still shows up. A row that does not exist yet counts as "missing", so the
 * first save of a row that the screen creates changes the fingerprint too.
 *
 * A plain hash is enough: the admin is already signed in and re-confirmed;
 * this guards against honest overlap, not against an attacker.
 *
 * @param string                               $sectionId Section id
 * @param array<string, array<string, mixed>>  $states    key => state from adminSettingsLoadRows()
 * @return string 64 hexadecimal characters
 */
function adminSettingsSectionFingerprint(string $sectionId, array $states): string
{
    $parts = [];

    foreach (adminSettingsFieldsForSection($sectionId) as $key => $field) {
        $state = ['exists' => false, 'hasValue' => false, 'value' => null, 'marker' => ''];
        if (isset($states[$key]) && is_array($states[$key])) {
            $state = array_merge($state, $states[$key]);
        }

        if ($state['exists'] !== true) {
            $parts[$key] = 'missing';
            continue;
        }

        if (adminSettingsFieldIsSecret($field)) {
            $shown = 'not-set';
            if ($state['hasValue']) {
                $shown = 'set';
            }
        } else {
            $shown = adminSettingsCanonicalCurrent($field, $state['value']);
        }

        $parts[$key] = [$shown, (string) $state['marker']];
    }

    $encoded = json_encode($parts, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        $encoded = '';
    }

    return hash('sha256', 'cuercode-settings-form-v1' . "\n" . $sectionId . "\n" . $encoded);
}

/**
 * 💬 What the admin is told when a form turned out to be out of date. Plain
 *    English, and it says nothing was saved. PURE.
 *
 * @return string
 */
function adminSettingsStaleMessage(): string
{
    return 'Someone changed these settings while you were editing. Nothing was saved. Reload the page and try again.';
}

/**
 * 📥 Read the rows for some setting keys in one query. A secret's value is
 *    not kept (see adminSettingsStateFromRow()).
 *
 * @param array<int, string> $keys      Setting keys
 * @param bool               $forUpdate True inside a transaction to LOCK the rows until it ends
 *                                      (a second save of the same rows waits, then sees the first
 *                                      one's result - used by the lost-update check and the
 *                                      "set or replaced" decision)
 * @return array<string, array<string, mixed>>|null key => state ('exists' false when missing); null when the database could not be read
 */
function adminSettingsLoadRows(array $keys, bool $forUpdate = false): ?array
{
    $states = [];
    foreach ($keys as $key) {
        $states[$key] = ['exists' => false, 'hasValue' => false, 'value' => null, 'updatedAt' => null, 'isSensitive' => false, 'isEditable' => true, 'type' => 'string', 'marker' => ''];
    }

    if ($keys === []) {
        return $states;
    }

    if (!function_exists('dbQuery')) {
        return null;
    }

    // The placeholder list is built from the NUMBER of keys only.
    $placeholders = implode(', ', array_fill(0, count($keys), '?'));

    // 🔒 The lock clause is fixed text, never built from input.
    $lockClause = '';
    if ($forUpdate) {
        $lockClause = ' ORDER BY `settingKey` FOR UPDATE';
    }

    try {
        $stmt = dbQuery(
            'SELECT `settingKey`, `settingType`, `isSensitive`, `isEditable`, `settingValue`, `updatedAt` '
            . 'FROM `tblSettings` WHERE `settingKey` IN (' . $placeholders . ')' . $lockClause,
            str_repeat('s', count($keys)),
            array_values($keys)
        );
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $states[(string) $row['settingKey']] = adminSettingsStateFromRow($row);
            // 🧹 The raw row (which may hold ciphertext) is not kept.
            unset($row);
        }
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Admin settings could not be read', get_class($e));
        }
        return null;
    }

    return $states;
}

/**
 * 📋 The plan keys a free trial may give: paid ordinary plans on sale that
 *    can stay on the pricing page with their own button (recheck finding 2 of
 *    the 7n Phase 2 review - the rule is adminPlansTrialPlanChoices(), shared
 *    with the plan form's protection rules). Used both to list the choices
 *    and to check a save.
 *
 * Every column is read (`SELECT *`), so this works before upgrade 0004 adds
 * the sale-date, button and cap columns as well as after.
 *
 * @return array<int, string>|null null when they could not be read
 */
function adminSettingsTrialPlanKeys(): ?array
{
    if (!function_exists('dbQuery')) {
        return null;
    }

    // 📦 The shared rule lives with the other plan rules (loads functions only).
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_admin_plans.php';

    try {
        $stmt = dbQuery(
            'SELECT * FROM `tblSubscriptionPlans` WHERE `isActive` = 1 ORDER BY `sortOrder`, `planKey`',
            '',
            []
        );
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        $keys = adminPlansTrialPlanChoices($rows);
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Admin settings could not read the plan list', get_class($e));
        }
        return null;
    }

    return $keys;
}

// ==============================================================================
// 💾 DATABASE: saving (plan §6.6)
// ==============================================================================

/**
 * 🧹 Empty a runtime cache setting (graph_token_cache, sso_<provider>_
 *    discovery_cache / _jwks_cache). Only names ending in "_cache" are
 *    accepted. A missing row is fine (nothing to clear).
 *
 * @param string $key Cache setting key
 * @return bool True when cleared (or nothing to clear)
 */
function adminSettingsClearCache(string $key): bool
{
    if (!str_ends_with($key, '_cache') || !function_exists('dbQuery')) {
        return false;
    }

    try {
        $stmt = dbQuery(
            "UPDATE `tblSettings` SET `settingValue` = '', `encryptionSalt` = NULL WHERE `settingKey` = ?",
            's',
            [$key]
        );
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Admin settings could not clear a cache', 'key: ' . $key . ' | ' . get_class($e));
        }
        return false;
    }

    if (function_exists('clearConfigCache')) {
        clearConfigCache($key);
    }

    return true;
}

/**
 * 🔁 Run a registry entry's "afterSave" action (plan §6.6 step 5).
 *
 *   graph_token_cache     → forget the saved Microsoft access token;
 *   sso_discovery:<name>  → forget that provider's discovery and key caches;
 *   legacy_price_mirror   → the main currency changed: copy every plan's
 *                           prices in it into the columns the pricing page
 *                           reads (fn_admin_plans.php, task 7n Phase 2).
 *
 * 🔒 Independent review (Codex C4 finding 1, 2026-11-25): this now runs
 *    INSIDE the save's transaction, before the commit, and says whether it
 *    worked. Before, it ran after the commit and a failure was ignored, so a
 *    new Microsoft secret could be "saved" while the old access token stayed
 *    in use. A save whose cache cannot be cleared is now refused, with
 *    nothing changed.
 *
 * @param string               $name  The entry's afterSave text
 * @param array<string, mixed> $admin The admin saving (needed by legacy_price_mirror, which writes audit rows)
 * @return bool True when every cache it names was cleared (or had nothing in it), or the copy worked
 */
function adminSettingsRunAfterSave(string $name, array $admin = []): bool
{
    // 🪞 Task 7n Phase 2: the main currency changed, so copy every plan's
    //    monthly and yearly prices in the NEW main currency into the older
    //    columns the pricing page reads while charging is off. Runs inside the
    //    save's transaction (like the cache clearing), reads the new value
    //    from the database (this transaction sees its own change), and writes
    //    its own audit rows for the admin who saved. Any failure refuses the
    //    whole save.
    if ($name === 'legacy_price_mirror') {
        if (!isset($admin['userID'], $admin['email'])) {
            return false;
        }
        $currencyRows = adminSettingsLoadRows(['billing_default_currency']);
        if ($currencyRows === null || !is_string($currencyRows['billing_default_currency']['value'])) {
            return false;
        }
        $mainCurrency = strtoupper(trim($currencyRows['billing_default_currency']['value']));
        if (!moneyCurrencyCanBeOffered($mainCurrency)) {
            return false;
        }
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_admin_plans.php';
        try {
            adminMirrorAllLegacyPlanPrices($admin, $mainCurrency);
        } catch (\Throwable $e) {
            if (function_exists('logError')) {
                logError('error', 'Admin settings: the pricing page prices could not be copied after the main currency changed', get_class($e));
            }
            return false;
        }
        return true;
    }

    if ($name === 'graph_token_cache') {
        return adminSettingsClearCache('graph_token_cache');
    }

    if (str_starts_with($name, 'sso_discovery:')) {
        $provider = substr($name, strlen('sso_discovery:'));
        if (preg_match('/^[a-z0-9]{1,30}$/', $provider) !== 1) {
            return false;
        }
        $discoveryCleared = adminSettingsClearCache('sso_' . $provider . '_discovery_cache');
        $keysCleared      = adminSettingsClearCache('sso_' . $provider . '_jwks_cache');
        return $discoveryCleared && $keysCleared;
    }

    // An unknown action name is a registry mistake: refuse rather than guess.
    return false;
}

/**
 * 💾 Save ONE registry setting, with its audit row (plan §6.6).
 *
 * Refuses when: the audit table is missing; the key is locked; the key is not
 * in the registry; the stored row is marked not editable or its "secret" flag
 * does not match the registry (a secret must never be stored as plain text);
 * the value fails validation. Otherwise setConfig() and the audit row are
 * written in ONE transaction (unless the caller already opened one), and a
 * failure of either rolls both back.
 *
 * The value of a secret is never put in the result, the audit row or any log.
 *
 * @param array<string, mixed> $spec     At least ['key' => ...]; everything else comes from the registry
 * @param mixed                $newValue The new value as text ('' on a secret means "remove it")
 * @param array<string, mixed> $admin    From requireGlobalAdminPage()
 * @param string|null          $reason   Optional reason for the audit row
 * @param bool                 $ownTransaction False when the caller has already started a transaction (and will commit it and run afterSave)
 * @return array{ok: bool, outcome: string, message: string, afterSave: string, auditAction: string} outcome: saved | unchanged | refused | failed
 */
function adminSaveSetting(array $spec, #[\SensitiveParameter] mixed $newValue, array $admin, ?string $reason = null, bool $ownTransaction = true): array
{
    // 🔓 Set to true while THIS call has a transaction open and has not ended it.
    $transactionOpen = false;

    // 🚪 Every early exit goes through here, so an open transaction (and the
    //    row lock it holds) is always ended.
    $leave = function (array $result) use (&$transactionOpen): array {
        if ($transactionOpen) {
            adminRollbackQuietly(getDB());
            $transactionOpen = false;
        }
        return $result;
    };

    $refuse = function (string $message) use ($leave): array {
        return $leave(['ok' => false, 'outcome' => 'refused', 'message' => $message, 'afterSave' => '', 'auditAction' => '']);
    };

    $key = '';
    if (isset($spec['key']) && is_string($spec['key'])) {
        $key = $spec['key'];
    }

    // 🔒 1. Locked keys: never, whatever the request says.
    if ($key === '' || adminSettingsKeyIsLocked($key)) {
        return $refuse('That setting is locked and cannot be changed here.');
    }

    // 📋 2. Only registry keys. Everything else about the setting comes from
    //       the registry, never from the $spec the caller passed.
    $registry = adminSettingsRegistry();
    if (!isset($registry[$key])) {
        return $refuse('That setting cannot be changed from this screen.');
    }
    $field = $registry[$key];
    $isSecret = adminSettingsFieldIsSecret($field);

    // 🧾 3. No change without its audit row: the audit table must exist.
    if (!function_exists('adminTableExists') || !adminTableExists('tblAdminAuditLog')) {
        return $refuse('Database upgrades are waiting, so nothing can be saved yet.');
    }

    // 🔐 4. When this call owns the transaction, open it BEFORE reading the
    //       row, and read the row with a lock (FOR UPDATE). Two saves of the
    //       same setting then queue up, so "set" or "replaced" (and the audit
    //       row that says which) is decided from the row as it really is.
    if ($ownTransaction) {
        try {
            getDB()->begin_transaction();
            $transactionOpen = true;
        } catch (\Throwable $e) {
            if (function_exists('logError')) {
                logError('error', 'Admin setting could not start a save', 'key: ' . $key . ' | ' . get_class($e));
            }
            return ['ok' => false, 'outcome' => 'failed', 'message' => 'Not saved. Nothing was changed. Please try again.', 'afterSave' => '', 'auditAction' => ''];
        }
    }

    // 📥 5. The stored row (locked).
    $rows = adminSettingsLoadRows([$key], true);
    if ($rows === null) {
        return $leave(['ok' => false, 'outcome' => 'failed', 'message' => 'The setting could not be read, so nothing was saved.', 'afterSave' => '', 'auditAction' => '']);
    }
    $state = $rows[$key];

    if ($state['exists']) {
        if (!$state['isEditable']) {
            return $refuse('That setting is marked as not editable.');
        }
        if ($state['isSensitive'] !== $isSecret) {
            // A secret must never be saved as plain text (setConfig() trusts the row's own flag).
            return $refuse('That setting is not stored the way this screen expects, so it was not changed.');
        }
    }

    // ✅ 6. Validate again, whatever the page did.
    $text = '';
    if (is_string($newValue)) {
        $text = $newValue;
    } elseif (is_bool($newValue) || is_int($newValue) || is_float($newValue)) {
        $text = (string) ((int) $newValue);
        if (is_float($newValue)) {
            $text = (string) $newValue;
        }
    } else {
        return $refuse('That value was not understood.');
    }

    $planKeys = [];
    if ($field['kind'] === 'plan_key') {
        $loadedPlanKeys = adminSettingsTrialPlanKeys();
        if ($loadedPlanKeys !== null) {
            $planKeys = $loadedPlanKeys;
        }
    }
    $checked = adminSettingsValidateValue($field, $text, ['planKeys' => $planKeys]);
    $text = '';
    if (!$checked['ok']) {
        return $refuse($checked['error']);
    }
    $value = $checked['value'];

    // ➖ 7. Nothing to do?
    $oldCanonical = '';
    if (!$isSecret) {
        $oldCanonical = adminSettingsCanonicalCurrent($field, $state['value']);
        if ($state['exists'] && $oldCanonical === $value) {
            return $leave(['ok' => true, 'outcome' => 'unchanged', 'message' => '', 'afterSave' => '', 'auditAction' => '']);
        }
    } elseif ($value === '' && !$state['hasValue']) {
        return $leave(['ok' => true, 'outcome' => 'unchanged', 'message' => '', 'afterSave' => '', 'auditAction' => '']);
    }

    // 🧾 8. The audit entry (what changed, with secrets reduced to a marker).
    $auditAction = 'setting.update';
    $changes = [];
    if ($isSecret) {
        // Stand-in words only: the real values never go near the audit code.
        $beforeMarker = '';
        if ($state['hasValue']) {
            $beforeMarker = 'was-set';
        }
        $afterMarker = '';
        if ($value !== '') {
            $afterMarker = 'is-set';
            if ($state['hasValue']) {
                $afterMarker = 'is-replaced';
            }
        }
        $changes = adminAuditChanges([$key => $beforeMarker], [$key => $afterMarker], [$key]);
        if ($value === '') {
            $auditAction = 'setting.secret_clear';
        } elseif ($state['hasValue']) {
            $auditAction = 'setting.secret_replace';
        } else {
            $auditAction = 'setting.secret_set';
        }
    } else {
        $changes = adminAuditChanges([$key => $oldCanonical], [$key => $value], []);
    }

    $entry = adminAuditEntryForAdmin($admin, $auditAction, 'setting', $key, 'success', $changes, $reason);

    // 💾 9. The setting AND its audit row, in the one transaction.
    $options = [
        'group'       => (string) $field['group'],
        'type'        => (string) $field['type'],
        'isSensitive' => $isSecret,
        'description' => (string) $field['description'],
    ];
    if ($state['exists']) {
        $options['type'] = (string) $state['type'];
    }

    $failed = false;

    // 🔁 10 (moved before the commit, Codex C4 finding 1): when this call owns
    //    the transaction, its cache clearing is part of it; otherwise the
    //    caller (adminSettingsSaveSection()) runs it inside its own.
    $afterSave = (string) $field['afterSave'];

    try {
        if (!setConfig($key, $value, $options)) {
            $failed = true;
        } elseif (!adminAuditWrite($entry)) {
            $failed = true;
        } elseif ($ownTransaction && $afterSave !== '' && !adminSettingsRunAfterSave($afterSave, $admin)) {
            $failed = true;
        }

        if ($failed) {
            if ($transactionOpen) {
                adminRollbackQuietly(getDB());
                $transactionOpen = false;
            }
        } elseif ($transactionOpen) {
            getDB()->commit();
            $transactionOpen = false;
        }
    } catch (\Throwable $e) {
        $failed = true;
        if ($transactionOpen) {
            adminRollbackQuietly(getDB());
            $transactionOpen = false;
        }
        // 🤫 Only the kind of problem is logged - never a message that could hold a value.
        if (function_exists('logError')) {
            logError('error', 'Admin setting could not be saved', 'key: ' . $key . ' | ' . get_class($e));
        }
    }

    $value = '';

    if ($failed) {
        if (function_exists('clearConfigCache')) {
            clearConfigCache($key);
        }
        return ['ok' => false, 'outcome' => 'failed', 'message' => 'Not saved. Nothing was changed. Please try again.', 'afterSave' => '', 'auditAction' => $auditAction];
    }

    // 🔁 Already done inside the transaction when this call owned it.
    if ($ownTransaction) {
        $afterSave = '';
    }

    return ['ok' => true, 'outcome' => 'saved', 'message' => '', 'afterSave' => $afterSave, 'auditAction' => $auditAction];
}

/**
 * 💾 Save every change of one section in ONE transaction: all of them (with
 *    their audit rows) or none. The "afterSave" cache clearing runs once per
 *    action, inside the same transaction, before the commit (Codex C4-1).
 *
 * 🧾 Lost-update protection (plan §7.5): the section's rows are locked first
 *    and their fingerprint is recomputed from the database. If it is not the
 *    one the form carried, the form was out of date: NOTHING is saved and
 *    'stale' is true. A missing fingerprint counts as out of date.
 *
 * @param array<string, mixed>              $admin              From requireGlobalAdminPage()
 * @param string                            $sectionId          Section id
 * @param string                            $postedFingerprint  The form's hidden fingerprint ('' when absent)
 * @param array<int, array<string, mixed>>  $changes            From adminSettingsProcessSection()
 * @param string|null                       $reason             Optional reason
 * @return array{ok: bool, saved: int, stale: bool, message: string}
 */
function adminSettingsSaveSection(array $admin, string $sectionId, string $postedFingerprint, #[\SensitiveParameter] array $changes, ?string $reason = null): array
{
    if ($changes === []) {
        return ['ok' => true, 'saved' => 0, 'stale' => false, 'message' => ''];
    }

    $sectionKeys = array_keys(adminSettingsFieldsForSection($sectionId));
    if ($sectionKeys === []) {
        return ['ok' => false, 'saved' => 0, 'stale' => false, 'message' => 'That section was not recognised, so nothing was saved.'];
    }

    $afterSaves = [];
    $saved = 0;

    try {
        getDB()->begin_transaction();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Admin settings could not start a save', get_class($e));
        }
        return ['ok' => false, 'saved' => 0, 'stale' => false, 'message' => 'Not saved. Nothing was changed. Please try again.'];
    }

    // 🔐 Lock the section's rows, then compare what is there NOW with what the
    //    form showed.
    $lockedStates = adminSettingsLoadRows($sectionKeys, true);
    if ($lockedStates === null) {
        adminRollbackQuietly(getDB());
        return ['ok' => false, 'saved' => 0, 'stale' => false, 'message' => 'The saved settings could not be read, so nothing was saved. Please try again.'];
    }
    $fingerprintNow = adminSettingsSectionFingerprint($sectionId, $lockedStates);
    if ($postedFingerprint === '' || !hash_equals($fingerprintNow, $postedFingerprint)) {
        adminRollbackQuietly(getDB());
        return ['ok' => false, 'saved' => 0, 'stale' => true, 'message' => adminSettingsStaleMessage()];
    }

    foreach ($changes as $change) {
        $result = adminSaveSetting(['key' => (string) $change['key']], (string) $change['value'], $admin, $reason, false);

        if (!$result['ok']) {
            adminRollbackQuietly(getDB());
            foreach ($changes as $undone) {
                if (function_exists('clearConfigCache')) {
                    clearConfigCache((string) $undone['key']);
                }
            }
            $message = $result['message'];
            if ($message === '') {
                $message = 'Not saved. Nothing was changed.';
            }
            return ['ok' => false, 'saved' => 0, 'stale' => false, 'message' => $message . ' None of this section\'s changes were saved.'];
        }

        if ($result['outcome'] === 'saved') {
            $saved++;
            if ($result['afterSave'] !== '' && !in_array($result['afterSave'], $afterSaves, true)) {
                $afterSaves[] = $result['afterSave'];
            }
        }
    }

    // 🔁 Codex C4 finding 1: the caches are cleared INSIDE the transaction;
    //    if one cannot be, nothing in the section is saved.
    foreach ($afterSaves as $afterSave) {
        if (!adminSettingsRunAfterSave($afterSave, $admin)) {
            adminRollbackQuietly(getDB());
            foreach ($changes as $undone) {
                if (function_exists('clearConfigCache')) {
                    clearConfigCache((string) $undone['key']);
                }
            }
            return ['ok' => false, 'saved' => 0, 'stale' => false, 'message' => 'Not saved. Nothing was changed. Please try again.'];
        }
    }

    try {
        getDB()->commit();
    } catch (\Throwable $e) {
        adminRollbackQuietly(getDB());
        if (function_exists('logError')) {
            logError('error', 'Admin settings could not finish a save', get_class($e));
        }
        return ['ok' => false, 'saved' => 0, 'stale' => false, 'message' => 'Not saved. Nothing was changed. Please try again.'];
    }

    return ['ok' => true, 'saved' => $saved, 'stale' => false, 'message' => ''];
}

// ==============================================================================
// 🔌 The two test actions (plan §7.1)
// ==============================================================================

/**
 * 🔢 When did this admin last START the given test? (From the admin's own
 *    audit rows, so the limit holds across browsers, tabs and sessions - a
 *    session-only count could be dodged by signing in again.) Only the rows
 *    written BEFORE a test runs are counted: one per attempt, whether or not
 *    the test worked - and, since final review N-1, whether or not the limit
 *    then refused it (every press counts, so pressing again while refused
 *    keeps it refused). Those rows are named ACTION + ".started" (N-2); rows
 *    written before that change (the plain action with the "Test started"
 *    reason) still count, so the limit carries on across the upgrade.
 *
 * @param int    $userID        The admin's user id
 * @param string $action        'setting.test_graph' or 'setting.test_email'
 * @param int    $now           Now (Unix time)
 * @param int    $windowSeconds How far back to look
 * @return array<int, int>|null Unix times, oldest first; null when the log could not be read
 */
function adminSettingsRecentAttemptTimes(int $userID, string $action, int $now, int $windowSeconds): ?array
{
    if (!function_exists('dbQuery')) {
        return null;
    }

    $times = [];

    try {
        // The age is worked out by the database itself (both ends of the
        // sum are in the database's own clock), so no time-zone maths here.
        $stmt = dbQuery(
            'SELECT TIMESTAMPDIFF(SECOND, `createdAt`, NOW()) AS `ageSeconds` FROM `tblAdminAuditLog` '
            . 'WHERE `actorUserID` = ? AND `createdAt` >= DATE_SUB(NOW(), INTERVAL ? SECOND) '
            . 'AND (`action` = ? OR (`action` = ? AND `reason` = ?)) '
            . 'ORDER BY `auditID`',
            'iisss',
            [$userID, $windowSeconds, $action . CUERCODE_ADMIN_TEST_STARTED_SUFFIX, $action, CUERCODE_ADMIN_TEST_ATTEMPT_REASON]
        );
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $age = (int) $row['ageSeconds'];
            if ($age < 0) {
                $age = 0;
            }
            $times[] = $now - $age;
        }
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Admin test limit could not be read', get_class($e));
        }
        return null;
    }

    sort($times);

    return $times;
}

/**
 * 🧾 Write the audit row that says a test is ABOUT to run. Written first, so a
 *    test that cannot be recorded is not run at all, and BEFORE the limit is
 *    counted (final review N-1), so two presses at the same moment both see
 *    each other. It counts toward the limit. Its action is ACTION + ".started"
 *    (final review N-2); the outcome column says "failed" because it cannot
 *    say "started" (see CUERCODE_ADMIN_TEST_STARTED_SUFFIX) - the next row
 *    says how the test really ended.
 *
 * @param array<string, mixed> $admin  From requireGlobalAdminPage()
 * @param string               $action 'setting.test_graph' or 'setting.test_email'
 * @param string               $entity The entity key for the row
 * @return bool True when the row was written
 */
function adminSettingsWriteTestAttempt(array $admin, string $action, string $entity): bool
{
    return adminAuditWrite(adminAuditEntryForAdmin($admin, $action . CUERCODE_ADMIN_TEST_STARTED_SUFFIX, 'setting', $entity, 'failed', [], CUERCODE_ADMIN_TEST_ATTEMPT_REASON));
}

/**
 * 🚦 After the attempt row is written: is this attempt within the limit?
 *    The count INCLUDES this attempt (final review N-1). When it is over the
 *    limit, a "refused" row is written to say the test did not run.
 *
 * @param array<string, mixed> $admin  From requireGlobalAdminPage()
 * @param string               $action 'setting.test_graph' or 'setting.test_email'
 * @param string               $entity The entity key for the rows
 * @param int                  $now    Now (Unix time)
 * @param int                  $max    Attempts allowed in the window
 * @return array{allowed: bool, unreadable: bool, waitSeconds: int}
 */
function adminSettingsAttemptWithinLimit(array $admin, string $action, string $entity, int $now, int $max): array
{
    $times = adminSettingsRecentAttemptTimes((int) $admin['userID'], $action, $now, CUERCODE_ADMIN_TEST_EMAIL_WINDOW_SECONDS);

    if ($times === null) {
        adminAuditWrite(adminAuditEntryForAdmin($admin, $action, 'setting', $entity, 'refused', [], CUERCODE_ADMIN_TEST_LIMITED_REASON));
        return ['allowed' => false, 'unreadable' => true, 'waitSeconds' => 0];
    }

    // 📌 This attempt is already among $times, so "allowed" means at most
    //    $max in the window (one more than the window helper's own test).
    $including = adminSettingsTestEmailWindow($times, $now, $max + 1);
    if ($including['allowed']) {
        return ['allowed' => true, 'unreadable' => false, 'waitSeconds' => 0];
    }

    // ⏱️ When will a NEW press (which adds itself) fit? When enough of these
    //    have left the window: exactly the window helper's answer for $max.
    $wait = adminSettingsTestEmailWindow($times, $now, $max)['waitSeconds'];

    adminAuditWrite(adminAuditEntryForAdmin($admin, $action, 'setting', $entity, 'refused', [], CUERCODE_ADMIN_TEST_LIMITED_REASON));

    return ['allowed' => false, 'unreadable' => false, 'waitSeconds' => max(1, (int) $wait)];
}

/**
 * 🧾 Write the audit row that says how a test ended.
 *
 * @param array<string, mixed> $admin   From requireGlobalAdminPage()
 * @param string               $action  'setting.test_graph' or 'setting.test_email'
 * @param string               $entity  The entity key for the row
 * @param bool                 $success Did it work?
 * @return bool True when the row was written
 */
function adminSettingsWriteTestResult(array $admin, string $action, string $entity, bool $success): bool
{
    $outcome = 'failed';
    if ($success) {
        $outcome = 'success';
    }

    return adminAuditWrite(adminAuditEntryForAdmin($admin, $action, 'setting', $entity, $outcome, [], CUERCODE_ADMIN_TEST_RESULT_REASON));
}

/**
 * 🔌 "Check Microsoft connection": forget the saved token, ask Microsoft for a
 *    new one, and say Connected or Could not connect. NEVER returns the token
 *    or any error text from Microsoft.
 *
 * Audit (setting.test_graph): a row is written BEFORE the check (if it cannot
 * be written the check is not run), and a second row says how it ended. At
 * most CUERCODE_ADMIN_TEST_GRAPH_MAX attempts per 10 minutes per admin,
 * counted from the first kind of row.
 *
 * @param array<string, mixed> $admin        From requireGlobalAdminPage()
 * @param callable|null        $tokenFetcher Stand-in for getGraphAccessToken() (tests); null = the real one
 * @param int|null             $now          Stand-in clock (tests); null = now
 * @return array{ok: bool, message: string}
 */
function adminSettingsTestGraph(array $admin, ?callable $tokenFetcher = null, ?int $now = null): array
{
    if (!adminTableExists('tblAdminAuditLog')) {
        return ['ok' => false, 'message' => 'Database upgrades are waiting, so this cannot be checked yet.'];
    }

    if ($now === null) {
        $now = time();
    }

    if ($tokenFetcher === null) {
        if (!function_exists('getGraphAccessToken')) {
            return ['ok' => false, 'message' => 'The email code is not available, so this cannot be checked.'];
        }
        $tokenFetcher = 'getGraphAccessToken';
    }

    // 🧾 Record the attempt FIRST: no record, no check. (Final review N-1:
    //    before the limit is counted, so the count includes this attempt.)
    if (!adminSettingsWriteTestAttempt($admin, 'setting.test_graph', 'graph_connection')) {
        return ['ok' => false, 'message' => 'This check could not be recorded in the admin change log, so it was not run. Please try again.'];
    }

    // 🚦 At most 10 in 10 minutes for this admin, counted from the audit log.
    $limit = adminSettingsAttemptWithinLimit($admin, 'setting.test_graph', 'graph_connection', $now, CUERCODE_ADMIN_TEST_GRAPH_MAX);
    if ($limit['unreadable']) {
        return ['ok' => false, 'message' => 'The limit on checks could not be read, so nothing was checked. Please try again.'];
    }
    if (!$limit['allowed']) {
        $minutes = (int) ceil($limit['waitSeconds'] / 60);
        return ['ok' => false, 'message' => 'Too many connection checks just now (the limit is ' . CUERCODE_ADMIN_TEST_GRAPH_MAX . ' in 10 minutes). Try again in about ' . $minutes . ' minute(s).'];
    }

    $allThreeSet = (adminSettingHasValue('graph_tenant_id') && adminSettingHasValue('graph_client_id') && adminSettingHasValue('graph_client_secret'));

    adminSettingsClearCache('graph_token_cache');

    $connected = false;
    try {
        $token = $tokenFetcher();
        if (is_string($token) && $token !== '') {
            $connected = true;
        }
        // 🧹 The token is not kept or shown.
        $token = null;
    } catch (\Throwable $e) {
        $connected = false;
        if (function_exists('logError')) {
            logError('error', 'Microsoft connection check threw', get_class($e));
        }
    }

    $recorded = adminSettingsWriteTestResult($admin, 'setting.test_graph', 'graph_connection', $connected);
    $recordNote = '';
    if (!$recorded) {
        $recordNote = ' (The result could not be written to the admin change log.)';
    }

    if ($connected) {
        return ['ok' => true, 'message' => 'Connected. Microsoft accepted the tenant ID, app ID and app secret.' . $recordNote];
    }

    if (!$allThreeSet) {
        return ['ok' => false, 'message' => 'Could not connect: the tenant ID, app ID and app secret are not all filled in yet.' . $recordNote];
    }

    return ['ok' => false, 'message' => 'Could not connect. Check the tenant ID, app ID and app secret (and that the secret has not expired). More detail is in the activity log (the "email failed" entries).' . $recordNote];
}

/**
 * 🔗 "Test connection" for Go2My.Link (#165): runs go2mylinkTestConnection(),
 *    which only reads (and tries to change a code that cannot exist), so
 *    NOTHING changes on Go2My.Link. Never returns the key or Go2My.Link's
 *    own wording.
 *
 * Audit (setting.test_go2mylink): a row is written BEFORE the check (if it
 * cannot be written the check is not run), and a second row says how it
 * ended. At most CUERCODE_ADMIN_TEST_GO2MYLINK_MAX attempts per 10 minutes
 * per admin, counted from the first kind of row - the same rules as the
 * Microsoft check.
 *
 * @param array<string, mixed> $admin  From requireGlobalAdminPage()
 * @param callable|null        $tester Stand-in for go2mylinkTestConnection() (tests); null = the real one
 * @param int|null             $now    Stand-in clock (tests); null = now
 * @return array{ok: bool, message: string}
 */
function adminSettingsTestGo2MyLink(array $admin, ?callable $tester = null, ?int $now = null): array
{
    if (!adminTableExists('tblAdminAuditLog')) {
        return ['ok' => false, 'message' => 'Database upgrades are waiting, so this cannot be checked yet.'];
    }

    if ($now === null) {
        $now = time();
    }

    if ($tester === null) {
        $tester = 'go2mylinkTestConnection';
    }

    // 🧾 Record the attempt FIRST: no record, no check.
    if (!adminSettingsWriteTestAttempt($admin, 'setting.test_go2mylink', 'go2mylink_connection')) {
        return ['ok' => false, 'message' => 'This check could not be recorded in the admin change log, so it was not run. Please try again.'];
    }

    // 🚦 At most 10 in 10 minutes for this admin, counted from the audit log.
    $limit = adminSettingsAttemptWithinLimit($admin, 'setting.test_go2mylink', 'go2mylink_connection', $now, CUERCODE_ADMIN_TEST_GO2MYLINK_MAX);
    if ($limit['unreadable']) {
        return ['ok' => false, 'message' => 'The limit on checks could not be read, so nothing was checked. Please try again.'];
    }
    if (!$limit['allowed']) {
        $minutes = (int) ceil($limit['waitSeconds'] / 60);
        return ['ok' => false, 'message' => 'Too many connection checks just now (the limit is ' . CUERCODE_ADMIN_TEST_GO2MYLINK_MAX . ' in 10 minutes). Try again in about ' . $minutes . ' minute(s).'];
    }

    $passed = false;
    $lines  = [];
    try {
        $answer = $tester();
        if (is_array($answer)) {
            if (isset($answer['ok']) && $answer['ok'] === true) {
                $passed = true;
            }
            if (isset($answer['lines']) && is_array($answer['lines'])) {
                foreach ($answer['lines'] as $line) {
                    if (is_string($line)) {
                        $lines[] = $line;
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        $passed = false;
        if (function_exists('logError')) {
            logError('error', 'Go2My.Link connection check threw', get_class($e));
        }
    }

    $recorded   = adminSettingsWriteTestResult($admin, 'setting.test_go2mylink', 'go2mylink_connection', $passed);
    $recordNote = '';
    if (!$recorded) {
        $recordNote = ' (The result could not be written to the admin change log.)';
    }

    $summary = 'Could not connect to Go2My.Link.';
    if ($passed) {
        $summary = 'Connected to Go2My.Link.';
    }
    if ($lines === []) {
        $lines[] = 'The check could not be completed. More detail is in the error log.';
    }

    return ['ok' => $passed, 'message' => $summary . ' ' . implode(' ', $lines) . $recordNote];
}

/**
 * ✉️ "Send me a test email": one fixed message to the SIGNED-IN admin's own
 *    address, only while email is on, at most 5 per 10 minutes. Never shows an
 *    error from Microsoft.
 *
 * The limit counts BOTH this browser session and the admin's own audit rows
 * (so signing in again does not reset it). Audit (setting.test_email): a row
 * is written BEFORE the email is sent (if it cannot be written, nothing is
 * sent), and a second row says how it ended.
 *
 * @param array<string, mixed> $admin  From requireGlobalAdminPage()
 * @param callable|null        $sender Stand-in for sendEmailNow() (tests); null = the real one
 * @param int|null             $now    Stand-in clock (tests); null = now
 * @return array{ok: bool, message: string}
 */
function adminSettingsSendTestEmail(array $admin, ?callable $sender = null, ?int $now = null): array
{
    if (!adminTableExists('tblAdminAuditLog')) {
        return ['ok' => false, 'message' => 'Database upgrades are waiting, so this cannot be done yet.'];
    }

    if ($now === null) {
        $now = time();
    }

    if ($sender === null) {
        if (!function_exists('sendEmailNow')) {
            return ['ok' => false, 'message' => 'The email code is not available, so a test email cannot be sent.'];
        }
        $sender = 'sendEmailNow';
    }

    if (!function_exists('getConfig') || (int) getConfig('email_enabled', 0) !== 1) {
        return ['ok' => false, 'message' => 'Email is switched off, so no test email was sent. Turn on "Send emails" and save first.'];
    }

    // 🚦 At most 5 in 10 minutes: for this browser session AND for this admin
    //    as counted in the audit log (the stricter of the two applies).
    $earlier = [];
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION[CUERCODE_ADMIN_TEST_EMAIL_SESSION_KEY]) && is_array($_SESSION[CUERCODE_ADMIN_TEST_EMAIL_SESSION_KEY])) {
        $earlier = $_SESSION[CUERCODE_ADMIN_TEST_EMAIL_SESSION_KEY];
    }
    $window = adminSettingsTestEmailWindow($earlier, $now);
    $tooManyMessage = 'Too many test emails just now (the limit is ' . CUERCODE_ADMIN_TEST_EMAIL_MAX . ' in 10 minutes). Try again in about ';

    // 🍪 This browser's own count first: nothing is written when it alone says no.
    if (!$window['allowed']) {
        return ['ok' => false, 'message' => $tooManyMessage . (int) ceil($window['waitSeconds'] / 60) . ' minute(s).'];
    }

    // 🧾 Record the attempt FIRST: no record, no email. (Final review N-1:
    //    before the audit-log limit is counted, so the count includes it.)
    if (!adminSettingsWriteTestAttempt($admin, 'setting.test_email', 'email_enabled')) {
        return ['ok' => false, 'message' => 'This test could not be recorded in the admin change log, so no email was sent. Please try again.'];
    }

    $limit = adminSettingsAttemptWithinLimit($admin, 'setting.test_email', 'email_enabled', $now, CUERCODE_ADMIN_TEST_EMAIL_MAX);
    if ($limit['unreadable']) {
        return ['ok' => false, 'message' => 'The limit on test emails could not be read, so none was sent. Please try again.'];
    }
    if (!$limit['allowed']) {
        return ['ok' => false, 'message' => $tooManyMessage . (int) ceil($limit['waitSeconds'] / 60) . ' minute(s).'];
    }

    // The attempt counts whether or not it works.
    $window['times'][] = $now;
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION[CUERCODE_ADMIN_TEST_EMAIL_SESSION_KEY] = $window['times'];
    }

    $address = (string) $admin['email'];
    $body = '<p>This is a test email from the CueRCode admin area.</p>'
        . '<p>If you can read this, email sending is working. You do not need to reply.</p>';

    $sent = false;
    try {
        $answer = $sender($address, 'CueRCode test email', $body, null);
        if (is_array($answer) && isset($answer[0]) && $answer[0] === true) {
            $sent = true;
        }
    } catch (\Throwable $e) {
        $sent = false;
        if (function_exists('logError')) {
            logError('error', 'Test email threw', get_class($e));
        }
    }

    $recorded = adminSettingsWriteTestResult($admin, 'setting.test_email', 'email_enabled', $sent);
    $recordNote = '';
    if (!$recorded) {
        $recordNote = ' (The result could not be written to the admin change log.)';
    }

    if ($sent) {
        return ['ok' => true, 'message' => 'A test email was sent to your own address (' . adminMaskEmail($address) . '). It can take a minute to arrive.' . $recordNote];
    }

    return ['ok' => false, 'message' => 'The test email could not be sent. Check the connection first. More detail is in the activity log (the "email failed" entries).' . $recordNote];
}

// ==============================================================================
// 🧱 PURE: HTML for the settings forms (the page owns <form> and the CSRF field)
// ==============================================================================

/**
 * ❗ Must this box have a value? PURE. True for numbers, choices, the time
 *    zone, the plan, web addresses and scopes (the validator refuses an empty
 *    one), and for any text box whose registry entry says it cannot be empty.
 *    False for switches (always on or off), secrets (empty means "keep what
 *    is saved") and the free-text boxes that may be left empty.
 *
 * 📌 Final review M-3: also false for a 'rowOptional' setting that has no
 *    row yet (pass its $state): an empty box then means "keep using the
 *    default", which the save accepts.
 *
 * @param array<string, mixed>      $field A registry entry
 * @param array<string, mixed>|null $state From adminSettingsStateFromRow() (null = not known)
 * @return bool
 */
function adminSettingsFieldIsRequired(array $field, ?array $state = null): bool
{
    $kind = (string) $field['kind'];

    if ($field['rowOptional'] === true && is_array($state) && (!isset($state['exists']) || $state['exists'] !== true)) {
        return false;
    }

    if (in_array($kind, ['int', 'decimal', 'choice', 'timezone', 'plan_key', 'https_url', 'site_url', 'scopes', 'currency_list'], true)) {
        return true;
    }

    if (in_array($kind, ['text', 'email', 'ip_ranges'], true) && $field['allowEmpty'] === false) {
        return true;
    }

    return false;
}

/**
 * 🧱 One settings box with its label, help text and any error. PURE.
 *
 * ⚠️ A secret box is ALWAYS drawn empty: its value is never available here
 *    (the state only says "has a value" and when it changed).
 *
 * @param array<string, mixed> $field A registry entry
 * @param array<string, mixed> $view  Keys: state (from adminSettingsStateFromRow()),
 *                                    posted (what was typed, non-secret only), error (text),
 *                                    planChoices (list of plan keys), timezone (for the "last changed" time)
 * @return string HTML
 */
function adminSettingsRenderField(array $field, array $view): string
{
    $key = (string) $field['key'];
    $id = 'f-' . $key;
    $helpId = 'help-' . $key;
    $errorId = 'err-' . $key;
    $kind = (string) $field['kind'];

    $state = ['exists' => false, 'hasValue' => false, 'value' => null, 'updatedAt' => null];
    if (isset($view['state']) && is_array($view['state'])) {
        $state = array_merge($state, $view['state']);
    }

    $error = '';
    if (isset($view['error']) && is_string($view['error'])) {
        $error = $view['error'];
    }

    $describedBy = $helpId;
    $invalidAttributes = '';
    $invalidClass = '';
    if ($error !== '') {
        $describedBy .= ' ' . $errorId;
        $invalidAttributes = ' aria-invalid="true"';
        $invalidClass = ' is-invalid';
    }

    $errorHtml = '';
    if ($error !== '') {
        $errorHtml = '<div class="invalid-feedback d-block" id="' . adminEscape($errorId) . '"><i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>' . adminEscape($error) . '</div>';
    }

    // 📌 Final review M-3: a 'rowOptional' setting with no row is using the
    //    code's default. Say so (and which number it is), and do not call the
    //    box "required": leaving it empty keeps the default.
    $usingDefault = ($field['rowOptional'] === true && $state['exists'] !== true);
    $helpText = (string) $field['help'];
    if ($usingDefault && $field['default'] !== null) {
        $helpText .= ' Nothing is saved yet, so the default (' . (string) $field['default'] . ') is in use. Leave the box empty to keep it.';
    }
    $helpHtml = '<div class="form-text" id="' . adminEscape($helpId) . '">' . adminEscape($helpText) . '</div>';
    // ❗ A box that cannot be left empty says so in its label (and to screen
    //    readers via aria-required), not only by refusing the save.
    $required = adminSettingsFieldIsRequired($field, $state);
    $requiredWords = '';
    $requiredAttribute = '';
    if ($required) {
        $requiredWords = ' <span class="text-body-secondary fw-normal">(required)</span>';
        $requiredAttribute = ' aria-required="true"';
    }
    $labelHtml = '<label for="' . adminEscape($id) . '" class="form-label">' . adminEscape($field['label']) . $requiredWords . '</label>';
    $name = 'f[' . $key . ']';

    // 🔀 What the box shows: what was typed (after a mistake) or what is saved.
    $shown = '';
    if (isset($view['posted']) && is_string($view['posted'])) {
        $shown = $view['posted'];
    } elseif (is_string($state['value'])) {
        $shown = $state['value'];
        // A number is drawn the one allowed way ("30", not an older "030"), so
        // saving the form untouched never trips the leading-zero rule. A
        // stored value that cannot be written that way ("-5", "30.0", "1e3")
        // is shown exactly as stored (trimmed), and the save skips it while
        // it is untouched (final review M-4).
        if ($kind === 'int' || $kind === 'decimal') {
            $shown = adminSettingsCanonicalCurrent($field, $shown);
        }
    }

    // ------------------------------------------------------------------ secret
    if ($kind === 'secret') {
        $timezone = CUERCODE_ADMIN_DEFAULT_TIMEZONE;
        if (isset($view['timezone']) && is_string($view['timezone']) && adminTimezoneIsValid($view['timezone'])) {
            $timezone = $view['timezone'];
        }

        if ($state['hasValue']) {
            $changed = '';
            if (is_string($state['updatedAt'])) {
                $changed = adminFormatUtcForDisplay($state['updatedAt'], $timezone);
            }
            $statusText = 'Set';
            if ($changed !== '') {
                $statusText = 'Set - last changed ' . $changed . ' (' . adminTimezoneLabel($timezone) . ')';
            }
            $statusHtml = adminStatusHtml('good', $statusText);
        } else {
            $statusHtml = adminStatusHtml('bad', 'Not set');
        }

        $removeHtml = '';
        if ($state['hasValue']) {
            $removeId = 'rm-' . $key;
            $removeHtml = '<div class="form-check mt-2">'
                . '<input class="form-check-input" type="checkbox" id="' . adminEscape($removeId) . '" name="rm[' . adminEscape($key) . ']" value="1" data-admin-confirm-check="Remove this stored secret?">'
                . '<label class="form-check-label" for="' . adminEscape($removeId) . '">Remove this secret (the setting will be empty)</label>'
                . '</div>';
        }

        $statusId = 'status-' . $key;

        return '<div class="mb-4">'
            . $labelHtml
            . '<p class="mb-1" id="' . adminEscape($statusId) . '">' . $statusHtml . '</p>'
            . '<div class="input-group">'
            . '<input type="password" class="form-control' . $invalidClass . '" id="' . adminEscape($id) . '" name="' . adminEscape($name) . '"'
            . ' value="" autocomplete="new-password" spellcheck="false" autocapitalize="off" data-lpignore="true" data-1p-ignore'
            . ' maxlength="' . (int) CUERCODE_ADMIN_SECRET_MAX_LENGTH . '"' . $invalidAttributes
            . ' aria-describedby="' . adminEscape($statusId . ' ' . $describedBy) . '" placeholder="Leave empty to keep what is saved">'
            . '<button type="button" class="btn btn-outline-secondary" data-admin-reveal="' . adminEscape($id) . '" hidden>Show what I typed</button>'
            . '</div>'
            . $helpHtml
            . $errorHtml
            . $removeHtml
            . '</div>';
    }

    // -------------------------------------------------------------------- bool
    if ($kind === 'bool') {
        $checked = '';
        if (isset($view['posted']) && is_string($view['posted'])) {
            if ($view['posted'] === '1') {
                $checked = ' checked';
            }
        } elseif (adminSettingsCanonicalCurrent($field, $state['value']) === '1') {
            $checked = ' checked';
        }

        $confirmHtml = '';
        if ($field['confirmText'] !== '') {
            $confirmId = 'confirm-' . $key;
            $confirmChecked = '';
            if (isset($view['confirmed']) && $view['confirmed'] === true) {
                $confirmChecked = ' checked';
            }
            $confirmHtml = '<div class="form-check mt-2">'
                . '<input class="form-check-input" type="checkbox" id="' . adminEscape($confirmId) . '" name="confirm[' . adminEscape($key) . ']" value="1"' . $confirmChecked . '>'
                . '<label class="form-check-label" for="' . adminEscape($confirmId) . '">' . adminEscape($field['confirmText']) . ' (needed only when turning it on)</label>'
                . '</div>';
        }

        return '<div class="mb-4">'
            . '<div class="form-check form-switch">'
            . '<input type="hidden" name="' . adminEscape($name) . '" value="0">'
            . '<input class="form-check-input' . $invalidClass . '" type="checkbox" role="switch" id="' . adminEscape($id) . '" name="' . adminEscape($name) . '" value="1"' . $checked . $invalidAttributes
            . ' aria-describedby="' . adminEscape($describedBy) . '">'
            . '<label class="form-check-label" for="' . adminEscape($id) . '">' . adminEscape($field['label']) . '</label>'
            . '</div>'
            . $helpHtml
            . $errorHtml
            . $confirmHtml
            . '</div>';
    }

    // ----------------------------------------------------------------- choices
    if ($kind === 'choice' || $kind === 'plan_key') {
        $options = [];
        if ($kind === 'choice') {
            $options = $field['options'];
        } elseif (isset($view['planChoices']) && is_array($view['planChoices'])) {
            foreach ($view['planChoices'] as $planKey) {
                $options[(string) $planKey] = (string) $planKey;
            }
        }
        $currentChoice = adminSettingsCanonicalCurrent($field, $shown);

        // The saved value may not be on the list (an older plan that is no
        // longer active, or a value set by hand): still show it, selected, so
        // saving the form does not silently change it. A saved value that IS
        // on the list is drawn normally.
        if ($currentChoice !== '' && !array_key_exists($currentChoice, $options)) {
            if ($kind === 'plan_key') {
                $options = [$currentChoice => $currentChoice . ' (not an active paid plan)'] + $options;
            } else {
                $options = [$currentChoice => 'Current value (not in list): ' . $currentChoice] + $options;
            }
        }

        // Nothing saved and empty is not allowed: lead with "Choose...", so
        // the box does not look as if the first real option were already saved.
        if ($currentChoice === '' && adminSettingsFieldIsRequired($field)) {
            $options = ['' => 'Choose...'] + $options;
        }

        $optionHtml = '';
        foreach ($options as $optionValue => $optionLabel) {
            $selected = '';
            if ((string) $optionValue === $currentChoice) {
                $selected = ' selected';
            }
            $optionHtml .= '<option value="' . adminEscape((string) $optionValue) . '"' . $selected . '>' . adminEscape($optionLabel) . '</option>';
        }

        return '<div class="mb-4">'
            . $labelHtml
            . '<select class="form-select' . $invalidClass . '" id="' . adminEscape($id) . '" name="' . adminEscape($name) . '"' . $invalidAttributes . $requiredAttribute
            . ' aria-describedby="' . adminEscape($describedBy) . '">' . $optionHtml . '</select>'
            . $helpHtml
            . $errorHtml
            . '</div>';
    }

    // ------------------------------------------------------- text-like entries
    $inputType = 'text';
    $extra = '';
    if ($kind === 'email') {
        $inputType = 'email';
        $extra = ' autocomplete="off" spellcheck="false"';
    } elseif ($kind === 'https_url' || $kind === 'site_url') {
        $inputType = 'url';
        $extra = ' autocomplete="off" spellcheck="false"';
    } elseif ($kind === 'int') {
        $extra = ' inputmode="numeric" autocomplete="off"';
    } elseif ($kind === 'decimal') {
        $extra = ' inputmode="decimal" autocomplete="off"';
    } elseif ($kind === 'scopes') {
        $extra = ' autocomplete="off" spellcheck="false" autocapitalize="off"';
    } elseif ($kind === 'timezone') {
        $extra = ' autocomplete="off" spellcheck="false" list="timezone-list"';
    } elseif ($kind === 'currency_list') {
        $extra = ' autocomplete="off" spellcheck="false" autocapitalize="characters"';
    }

    if ($kind === 'ip_ranges') {
        return '<div class="mb-4">'
            . $labelHtml
            . '<textarea class="form-control' . $invalidClass . '" id="' . adminEscape($id) . '" name="' . adminEscape($name) . '" rows="3" spellcheck="false" autocomplete="off"'
            . ' maxlength="' . (int) $field['maxLength'] . '"' . $invalidAttributes . $requiredAttribute . ' aria-describedby="' . adminEscape($describedBy) . '">' . adminEscape($shown) . '</textarea>'
            . $helpHtml
            . $errorHtml
            . '</div>';
    }

    // 📌 Final review M-3: the default shows faintly inside the empty box.
    $placeholder = '';
    if ($usingDefault && $field['default'] !== null) {
        $placeholder = ' placeholder="' . adminEscape((string) $field['default'] . ' (the default)') . '"';
    }

    return '<div class="mb-4">'
        . $labelHtml
        . '<input type="' . $inputType . '" class="form-control' . $invalidClass . '" id="' . adminEscape($id) . '" name="' . adminEscape($name) . '" value="' . adminEscape($shown) . '"'
        . ' maxlength="' . (int) $field['maxLength'] . '"' . $extra . $placeholder . $invalidAttributes . $requiredAttribute . ' aria-describedby="' . adminEscape($describedBy) . '">'
        . $helpHtml
        . $errorHtml
        . '</div>';
}
