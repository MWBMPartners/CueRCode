<?php
/**
 * ==============================================================================
 * 🤖 CueRCode - Human check on/off switch (command line only, #163)
 * ==============================================================================
 *
 * Path: _includes/cli/human_check.php
 *
 * The way back in if the human check locks people out - for example the
 * provider cannot be reached and the outage cannot be recorded, or a wrong key was
 * saved, and the only Global Admin can no longer sign in to fix it:
 *
 *     php /home/<user>/<path>/CueRCode.net/_includes/cli/human_check.php
 *         what the check is doing now (the same as "status")
 *     php .../_includes/cli/human_check.php off
 *         switch it off (stores provider "none"); every form works without it
 *     php .../_includes/cli/human_check.php on
 *         switch it back on (stores provider "auto": the first provider with
 *         both keys entered, in the order Turnstile, reCAPTCHA, hCaptcha)
 *
 * The change and its row in the admin change log (tblAdminAuditLog, action
 * setting.update, actor "cli:<operating-system user>") are written in ONE
 * transaction. No key is ever shown. After "off", sign in, correct the keys
 * on /admin/settings/ (or wait for the provider), then run "on" or choose a
 * provider on that screen.
 *
 * Exit codes: 0 done (or nothing needed doing) | 1 failed (nothing was
 * changed - run it again) | 2 a bad option (nothing was read or changed).
 *
 * 🔒 COMMAND LINE ONLY, the same three layers as admin_access.php: outside
 *    public_html/ (no web address), _includes/.htaccess denies all web
 *    access, and the FIRST statement refuses anything but the command line.
 *
 * @package     CueRCode
 * @subpackage  Security
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-27 (#163, decision 0024)
 * @see         ../../_functions/fn_human_check.php - humanCheckCliParseArgs(), humanCheckCliSaveChoice(), humanCheckStatusLines()
 * @see         admin_access.php - the command-line guard and start-up pattern copied here
 */

// ==============================================================================
// 🚫 Step 0: command line only - the FIRST statement, before loading anything
// ==============================================================================
if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
    }
    exit('Access denied.');
}

// ==============================================================================
// 📦 Step 1: the human-check functions only (so --help works with no database)
// ==============================================================================
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_functions' . DIRECTORY_SEPARATOR . 'fn_human_check.php';

// ==============================================================================
// 🧾 Step 2: read the options
// ==============================================================================
$humanCliArguments = [];
if (isset($_SERVER['argv']) && is_array($_SERVER['argv'])) {
    $humanCliArguments = array_slice($_SERVER['argv'], 1);
}

$humanCliParsed = humanCheckCliParseArgs($humanCliArguments);

$humanCliUsage = [
    'Usage:',
    '  php _includes/cli/human_check.php          what the human check is doing now',
    '  php _includes/cli/human_check.php status   the same',
    '  php _includes/cli/human_check.php off      switch the human check off (if it locks people out)',
    '  php _includes/cli/human_check.php on       switch it back on (first provider with both keys)',
];

if ($humanCliParsed['error'] !== '') {
    fwrite(STDERR, $humanCliParsed['error'] . PHP_EOL . implode(PHP_EOL, $humanCliUsage) . PHP_EOL);
    exit(2);
}

if ($humanCliParsed['action'] === 'help') {
    fwrite(STDOUT, implode(PHP_EOL, $humanCliUsage) . PHP_EOL);
    exit(0);
}

// ==============================================================================
// 🗄️ Step 3: the normal site start-up code (settings, database, error log)
// ==============================================================================
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap.php';
restore_error_handler();
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_functions' . DIRECTORY_SEPARATOR . 'fn_admin.php';

// ==============================================================================
// 🔁 Step 4: switch it, if asked
// ==============================================================================
$humanCliExit = 0;

if ($humanCliParsed['action'] === 'on' || $humanCliParsed['action'] === 'off') {
    $humanCliSave = humanCheckCliSaveChoice(humanCheckCliChoiceFor($humanCliParsed['action']), adminCliActorLabel());
    fwrite(STDOUT, $humanCliSave['message'] . PHP_EOL);
    if (!$humanCliSave['ok']) {
        $humanCliExit = 1;
    }
    if (function_exists('clearConfigCache')) {
        clearConfigCache('bot_protection_provider');
    }
}

// ==============================================================================
// 🖨️ Step 5: what the check is doing now (never a key)
// ==============================================================================
$humanCliLines = array_merge(['Human check (#163)'], humanCheckStatusLines(humanCheckReadSettings()));
fwrite(STDOUT, implode(PHP_EOL, $humanCliLines) . PHP_EOL);

exit($humanCliExit);
