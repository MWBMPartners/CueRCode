<?php
/**
 * ==============================================================================
 * 📧 CueRCode - Email Template: Password Reset
 * ==============================================================================
 *
 * Path: _includes/email_templates/password_reset.php
 *
 * Rendered by renderEmailTemplate('password_reset', $params) - never
 * web-accessible (denied by _includes/.htaccess) and never require()'d
 * directly by anything other than fn_email.php.
 *
 * Expected $params keys:
 *   - recipientName   string|null  Optional display name for the greeting.
 *   - resetUrl        string       REQUIRED - the tokenised password-reset link.
 *   - expiryMinutes   int|null     Minutes until the link expires (default 60).
 *
 * Every interpolated value below passes through emailEscape() (fn_email.php)
 * before being placed into HTML - this file trusts NOTHING from $params.
 *
 * @package     CueRCode
 * @subpackage  Email
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E8)
 * @see         fn_email.php - renderEmailTemplate(), emailEscape(), emailRenderShell()
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

if (isset($params['resetUrl'])) {
    $resetUrl = (string) $params['resetUrl'];
} else {
    $resetUrl = '';
}

if (isset($params['expiryMinutes']) && (int) $params['expiryMinutes'] > 0) {
    $expiryMinutes = (int) $params['expiryMinutes'];
} else {
    $expiryMinutes = 60;
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
$subject = 'Reset your CueRCode password';

$escapedUrl = emailEscape($resetUrl);

$innerHtml = ''
    . '<p style="margin:0 0 16px 0;">' . $greetingHtml . '</p>'
    . '<p style="margin:0 0 16px 0;">'
    . 'We received a request to reset the password for your CueRCode account. '
    . 'This link expires in ' . emailEscape((string) $expiryMinutes) . ' ' . $minuteWord . '.'
    . '</p>'
    . '<p style="margin:24px 0; text-align:center;">'
    . '<a href="' . $escapedUrl . '" '
    . 'style="display:inline-block; background-color:#2c7a7b; color:#ffffff; text-decoration:none; '
    . 'padding:12px 28px; border-radius:6px; font-weight:bold;">Reset Password</a>'
    . '</p>'
    . '<p style="margin:0 0 8px 0; font-size:13px; color:#666666;">'
    . 'If the button above does not work, copy and paste this link into your browser:'
    . '</p>'
    . '<p style="margin:0; font-size:13px; word-break:break-all;">'
    . '<a href="' . $escapedUrl . '" style="color:#2c7a7b;">' . $escapedUrl . '</a>'
    . '</p>'
    . '<p style="margin:24px 0 0 0; font-size:13px; color:#666666;">'
    . 'If you did not request a password reset, you can safely ignore this email - '
    . 'your password will not be changed.'
    . '</p>';

// ==============================================================================
// 🖼️ Wrap in the shared branded shell and return [subject, htmlBody]
// ==============================================================================
$htmlBody = emailRenderShell(
    $subject,
    'Reset your CueRCode account password.',
    $innerHtml
);

return [$subject, $htmlBody];
