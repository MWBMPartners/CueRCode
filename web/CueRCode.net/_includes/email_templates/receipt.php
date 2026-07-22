<?php
/**
 * ==============================================================================
 * 📧 CueRCode - Email Template: Payment Receipt
 * ==============================================================================
 *
 * Path: _includes/email_templates/receipt.php
 *
 * Rendered by renderEmailTemplate('receipt', $params) - never web-accessible
 * (denied by _includes/.htaccess) and never require()'d directly by anything
 * other than fn_email.php.
 *
 * Expected $params keys:
 *   - recipientName    string|null  Optional display name for the greeting.
 *   - orderNumber      string       REQUIRED - order/invoice reference.
 *   - amount           string|float REQUIRED - the charged amount (numeric
 *                                    or pre-formatted string; NOT re-formatted
 *                                    here beyond escaping, so callers should
 *                                    pass an already-formatted value, e.g. "9.99").
 *   - currency         string|null  ISO 4217 currency code (default 'GBP').
 *   - itemDescription  string       REQUIRED - what was purchased.
 *   - purchaseDate     string|null  Pre-formatted date string (default: today,
 *                                    UTC, Y-m-d).
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

if (isset($params['orderNumber'])) {
    $orderNumber = (string) $params['orderNumber'];
} else {
    $orderNumber = '';
}

if (isset($params['amount'])) {
    $amount = (string) $params['amount'];
} else {
    $amount = '0.00';
}

if (isset($params['currency']) && $params['currency'] !== '') {
    $currency = (string) $params['currency'];
} else {
    $currency = 'GBP';
}

if (isset($params['itemDescription'])) {
    $itemDescription = (string) $params['itemDescription'];
} else {
    $itemDescription = '';
}

if (isset($params['purchaseDate']) && $params['purchaseDate'] !== '') {
    $purchaseDate = (string) $params['purchaseDate'];
} else {
    // 📅 Default to today's date (UTC) if the caller did not supply one.
    $purchaseDate = gmdate('Y-m-d');
}

// ==============================================================================
// 👋 Build the greeting line
// ==============================================================================
if ($recipientName !== null) {
    $greetingHtml = 'Hi ' . emailEscape($recipientName) . ',';
} else {
    $greetingHtml = 'Hi there,';
}

// ==============================================================================
// 📝 Subject + inner content
// ==============================================================================
$subject = 'Your CueRCode receipt (Order ' . $orderNumber . ')';

$innerHtml = ''
    . '<p style="margin:0 0 16px 0;">' . $greetingHtml . '</p>'
    . '<p style="margin:0 0 16px 0;">Thank you for your purchase. Here is your receipt:</p>'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
    . 'style="margin:0 0 16px 0; border:1px solid #e0e0e0; border-radius:6px;">'
    . '<tr>'
    . '<td style="padding:12px 16px; border-bottom:1px solid #e0e0e0; font-size:13px; color:#666666;">Order Number</td>'
    . '<td style="padding:12px 16px; border-bottom:1px solid #e0e0e0; font-size:13px; text-align:right;"><strong>'
    . emailEscape($orderNumber) . '</strong></td>'
    . '</tr>'
    . '<tr>'
    . '<td style="padding:12px 16px; border-bottom:1px solid #e0e0e0; font-size:13px; color:#666666;">Date</td>'
    . '<td style="padding:12px 16px; border-bottom:1px solid #e0e0e0; font-size:13px; text-align:right;">'
    . emailEscape($purchaseDate) . '</td>'
    . '</tr>'
    . '<tr>'
    . '<td style="padding:12px 16px; border-bottom:1px solid #e0e0e0; font-size:13px; color:#666666;">Description</td>'
    . '<td style="padding:12px 16px; border-bottom:1px solid #e0e0e0; font-size:13px; text-align:right;">'
    . emailEscape($itemDescription) . '</td>'
    . '</tr>'
    . '<tr>'
    . '<td style="padding:12px 16px; font-size:14px; font-weight:bold;">Total</td>'
    . '<td style="padding:12px 16px; font-size:14px; font-weight:bold; text-align:right;">'
    . emailEscape($currency) . ' ' . emailEscape($amount) . '</td>'
    . '</tr>'
    . '</table>'
    . '<p style="margin:0; font-size:13px; color:#666666;">'
    . 'If you have any questions about this charge, please contact us referencing the order number above.'
    . '</p>';

// ==============================================================================
// 🖼️ Wrap in the shared branded shell and return [subject, htmlBody]
// ==============================================================================
// ⚠️ Pass RAW (not pre-escaped) text for the title/preheader arguments -
// emailRenderShell() escapes both itself; pre-escaping here would double-encode.
$htmlBody = emailRenderShell(
    $subject,
    'Your receipt for order ' . $orderNumber . ' (' . $currency . ' ' . $amount . ').',
    $innerHtml
);

return [$subject, $htmlBody];
