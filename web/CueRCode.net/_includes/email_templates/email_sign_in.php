<?php
/**
 * ==============================================================================
 * 📧 CueRCode - Email Template: Sign in by email (no JavaScript, #163 / decision 0038)
 * ==============================================================================
 *
 * Path: _includes/email_templates/email_sign_in.php
 *
 * Rendered by renderEmailTemplate('email_sign_in', $params) - never
 * web-accessible (denied by _includes/.htaccess) and never require()'d
 * directly by anything other than fn_email.php.
 *
 * Sent ONLY after the correct password was typed on the sign-in page by a
 * visitor without JavaScript (fn_no_script_route.php, noScriptSignInStart()).
 * Opening the link changes nothing; the page it opens has a button to press.
 *
 * Expected $params keys:
 *   - recipientName   string|null  Optional display name for the greeting.
 *   - confirmUrl      string       REQUIRED - the single-use sign-in link.
 *   - expiryMinutes   int|null     Minutes until the link expires (default 15).
 *
 * Every interpolated value below passes through emailEscape() (fn_email.php)
 * before being placed into HTML - this file trusts NOTHING from $params.
 *
 * @package     CueRCode
 * @subpackage  Email
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-27 (#163, decision 0038)
 * @see         fn_email.php - renderEmailTemplate(), emailEscape(), emailRenderShell()
 * @see         fn_no_script_route.php - noScriptSignInStart()
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
// 📥 Extract + default parameters
// ==============================================================================
if (isset($params['recipientName']) && $params['recipientName'] !== '') {
    $recipientName = (string) $params['recipientName'];
} else {
    $recipientName = null;
}

if (isset($params['confirmUrl'])) {
    $confirmUrl = (string) $params['confirmUrl'];
} else {
    $confirmUrl = '';
}

if (isset($params['expiryMinutes']) && (int) $params['expiryMinutes'] > 0) {
    $expiryMinutes = (int) $params['expiryMinutes'];
} else {
    $expiryMinutes = 15;
}

// ==============================================================================
// 👋 Build the greeting line
// ==============================================================================
if ($recipientName !== null) {
    $greetingHtml = 'Hi ' . emailEscape($recipientName) . ',';
} else {
    $greetingHtml = 'Hi there,';
}

// 🔢 Simple singular/plural for "minute(s)".
if ($expiryMinutes === 1) {
    $minuteWord = 'minute';
} else {
    $minuteWord = 'minutes';
}

// ==============================================================================
// 📝 Subject + inner content
// ==============================================================================
$subject = 'Finish signing in to CueRCode';

$escapedUrl = emailEscape($confirmUrl);

$innerHtml = ''
    . '<p style="margin:0 0 16px 0;">' . $greetingHtml . '</p>'
    . '<p style="margin:0 0 16px 0;">'
    . 'Someone typed the correct password for your CueRCode account and asked to sign in by email '
    . '(the way that works without JavaScript). To finish signing in, open the link below and press the button on that page. '
    . 'The link works once and expires in ' . emailEscape((string) $expiryMinutes) . ' ' . $minuteWord . '.'
    . '</p>'
    . '<p style="margin:24px 0; text-align:center;">'
    . '<a href="' . $escapedUrl . '" '
    . 'style="display:inline-block; background-color:#2c7a7b; color:#ffffff; text-decoration:none; '
    . 'padding:12px 28px; border-radius:6px; font-weight:bold;">Open the sign-in page</a>'
    . '</p>'
    . '<p style="margin:0 0 8px 0; font-size:13px; color:#666666;">'
    . 'If the button above does not work, copy and paste this link into your browser:'
    . '</p>'
    . '<p style="margin:0; font-size:13px; word-break:break-all;">'
    . '<a href="' . $escapedUrl . '" style="color:#2c7a7b;">' . $escapedUrl . '</a>'
    . '</p>'
    . '<p style="margin:24px 0 0 0; font-size:13px; color:#666666;">'
    . '<strong>If you did not just try to sign in, do not press anything on that page.</strong> '
    . 'Someone knows your password: please change it with "Forgot password" on the sign-in page, which also signs out every device.'
    . '</p>';

// ==============================================================================
// 🖼️ Wrap in the shared branded shell and return [subject, htmlBody]
// ==============================================================================
$htmlBody = emailRenderShell(
    $subject,
    'Finish signing in to your CueRCode account.',
    $innerHtml
);

return [$subject, $htmlBody];
