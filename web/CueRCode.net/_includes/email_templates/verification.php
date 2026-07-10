<?php
/**
 * ==============================================================================
 * 📧 CueRCode - Email Template: Account Email Verification
 * ==============================================================================
 *
 * Path: _includes/email_templates/verification.php
 *
 * Rendered by renderEmailTemplate('verification', $params) - never web-accessible
 * (denied by _includes/.htaccess) and never require()'d directly by anything
 * other than fn_email.php.
 *
 * Expected $params keys:
 *   - recipientName    string|null  Optional display name for the greeting.
 *   - verificationUrl  string       REQUIRED - the tokenised verification link.
 *   - expiryHours      int|null     Hours until the link expires (default 24).
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

if (isset($params['verificationUrl'])) {
    $verificationUrl = (string) $params['verificationUrl'];
} else {
    $verificationUrl = '';
}

if (isset($params['expiryHours']) && (int) $params['expiryHours'] > 0) {
    $expiryHours = (int) $params['expiryHours'];
} else {
    $expiryHours = 24;
}

// ==============================================================================
// 👋 Build the greeting line
// ==============================================================================
if ($recipientName !== null) {
    $greetingHtml = 'Hi ' . emailEscape($recipientName) . ',';
} else {
    $greetingHtml = 'Hi there,';
}

// 🔢 Simple singular/plural for "hour(s)".
if ($expiryHours === 1) {
    $hourWord = 'hour';
} else {
    $hourWord = 'hours';
}

// ==============================================================================
// 📝 Subject + inner content
// ==============================================================================
$subject = 'Verify your CueRCode email address';

$escapedUrl = emailEscape($verificationUrl);

$innerHtml = ''
    . '<p style="margin:0 0 16px 0;">' . $greetingHtml . '</p>'
    . '<p style="margin:0 0 16px 0;">'
    . 'Please confirm your email address to finish setting up your CueRCode account. '
    . 'This link expires in ' . emailEscape((string) $expiryHours) . ' ' . $hourWord . '.'
    . '</p>'
    . '<p style="margin:24px 0; text-align:center;">'
    . '<a href="' . $escapedUrl . '" '
    . 'style="display:inline-block; background-color:#2c7a7b; color:#ffffff; text-decoration:none; '
    . 'padding:12px 28px; border-radius:6px; font-weight:bold;">Verify Email Address</a>'
    . '</p>'
    . '<p style="margin:0 0 8px 0; font-size:13px; color:#666666;">'
    . 'If the button above does not work, copy and paste this link into your browser:'
    . '</p>'
    . '<p style="margin:0; font-size:13px; word-break:break-all;">'
    . '<a href="' . $escapedUrl . '" style="color:#2c7a7b;">' . $escapedUrl . '</a>'
    . '</p>'
    . '<p style="margin:24px 0 0 0; font-size:13px; color:#666666;">'
    . 'If you did not create a CueRCode account, you can safely ignore this email.'
    . '</p>';

// ==============================================================================
// 🖼️ Wrap in the shared branded shell and return [subject, htmlBody]
// ==============================================================================
$htmlBody = emailRenderShell(
    $subject,
    'Verify your email address to activate your CueRCode account.',
    $innerHtml
);

return [$subject, $htmlBody];
