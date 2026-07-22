<?php
/**
 * ==============================================================================
 * 📧 CueRCode - Email Template: Organisation Invitation
 * ==============================================================================
 *
 * Path: _includes/email_templates/invitation.php
 *
 * Rendered by renderEmailTemplate('invitation', $params) - never web-accessible
 * (denied by _includes/.htaccess) and never require()'d directly by anything
 * other than fn_email.php.
 *
 * Expected $params keys:
 *   - inviteeName       string|null  Optional display name for the greeting.
 *   - inviterName       string       REQUIRED - the person who sent the invite.
 *   - organisationName  string       REQUIRED - the organisation being joined.
 *   - inviteUrl         string       REQUIRED - the tokenised invitation link.
 *   - role              string|null  Optional role label (e.g. "Admin", "User").
 *   - expiryDays        int|null     Days until the link expires (default 7).
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
if (isset($params['inviteeName']) && $params['inviteeName'] !== '') {
    $inviteeName = (string) $params['inviteeName'];
} else {
    $inviteeName = null;
}

if (isset($params['inviterName'])) {
    $inviterName = (string) $params['inviterName'];
} else {
    $inviterName = 'A CueRCode organisation admin';
}

if (isset($params['organisationName'])) {
    $organisationName = (string) $params['organisationName'];
} else {
    $organisationName = 'their organisation';
}

if (isset($params['inviteUrl'])) {
    $inviteUrl = (string) $params['inviteUrl'];
} else {
    $inviteUrl = '';
}

if (isset($params['role']) && $params['role'] !== '') {
    $role = (string) $params['role'];
} else {
    $role = null;
}

if (isset($params['expiryDays']) && (int) $params['expiryDays'] > 0) {
    $expiryDays = (int) $params['expiryDays'];
} else {
    $expiryDays = 7;
}

// ==============================================================================
// 👋 Build the greeting line
// ==============================================================================
if ($inviteeName !== null) {
    $greetingHtml = 'Hi ' . emailEscape($inviteeName) . ',';
} else {
    $greetingHtml = 'Hi there,';
}

// 🔢 Simple singular/plural for "day(s)".
if ($expiryDays === 1) {
    $dayWord = 'day';
} else {
    $dayWord = 'days';
}

// 🎭 Optional role clause.
if ($role !== null) {
    $roleClauseHtml = ' as <strong>' . emailEscape($role) . '</strong>';
} else {
    $roleClauseHtml = '';
}

// ==============================================================================
// 📝 Subject + inner content
// ==============================================================================
$escapedOrgName = emailEscape($organisationName);

$subject = 'You have been invited to join ' . $organisationName . ' on CueRCode';

$escapedUrl = emailEscape($inviteUrl);

$innerHtml = ''
    . '<p style="margin:0 0 16px 0;">' . $greetingHtml . '</p>'
    . '<p style="margin:0 0 16px 0;">'
    . emailEscape($inviterName) . ' has invited you to join <strong>' . $escapedOrgName . '</strong>'
    . $roleClauseHtml . ' on CueRCode. This invitation link expires in '
    . emailEscape((string) $expiryDays) . ' ' . $dayWord . '.'
    . '</p>'
    . '<p style="margin:24px 0; text-align:center;">'
    . '<a href="' . $escapedUrl . '" '
    . 'style="display:inline-block; background-color:#2c7a7b; color:#ffffff; text-decoration:none; '
    . 'padding:12px 28px; border-radius:6px; font-weight:bold;">Accept Invitation</a>'
    . '</p>'
    . '<p style="margin:0 0 8px 0; font-size:13px; color:#666666;">'
    . 'If the button above does not work, copy and paste this link into your browser:'
    . '</p>'
    . '<p style="margin:0; font-size:13px; word-break:break-all;">'
    . '<a href="' . $escapedUrl . '" style="color:#2c7a7b;">' . $escapedUrl . '</a>'
    . '</p>'
    . '<p style="margin:24px 0 0 0; font-size:13px; color:#666666;">'
    . 'If you were not expecting this invitation, you can safely ignore this email.'
    . '</p>';

// ==============================================================================
// 🖼️ Wrap in the shared branded shell and return [subject, htmlBody]
// ==============================================================================
// ⚠️ Pass RAW (not pre-escaped) text for the title/preheader arguments -
// emailRenderShell() escapes both itself; pre-escaping here would double-encode.
$htmlBody = emailRenderShell(
    $subject,
    $inviterName . ' invited you to join ' . $organisationName . ' on CueRCode.',
    $innerHtml
);

return [$subject, $htmlBody];
