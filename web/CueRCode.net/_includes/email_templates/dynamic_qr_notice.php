<?php
/**
 * ==============================================================================
 * 📧 CueRCode - Email Template: a dynamic-code notice (WP4e-WP4g, #168-#170)
 * ==============================================================================
 *
 * Path: _includes/email_templates/dynamic_qr_notice.php
 *
 * One plain template for every dynamic-code notice: a code suspended (to its
 * owner and to the abuse mailbox), a report received, a suspension lifted, and
 * the daily monitoring email. It is only words - no image, no tracking, no
 * button except the optional one link.
 *
 * Rendered by renderEmailTemplate('dynamic_qr_notice', $params) - never
 * web-accessible (denied by _includes/.htaccess).
 *
 * Expected $params keys:
 *   - subject   string  REQUIRED - the subject line (never personal details
 *                       of a reporter: the activity log records subjects)
 *   - heading   string  REQUIRED - the first line of the email
 *   - lines     array   Paragraphs of plain text, in order
 *   - sections  array   Optional: [['title' => string, 'lines' => array], ...]
 *                       for the daily email; each line becomes a list item
 *   - linkUrl   string  Optional: one https address to a CueRCode page
 *   - linkLabel string  Optional: the words on that link
 *
 * Every value passes through emailEscape() before it is placed into HTML -
 * this file trusts NOTHING from $params. Addresses that may be harmful are
 * written "defanged" by the caller (dynamicQrDefangUrl()), so a mail program
 * does not turn them into links.
 *
 * @package     CueRCode
 * @subpackage  Email
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (WP4e, #168)
 * @see         fn_email.php - renderEmailTemplate(), emailEscape(), emailRenderShell()
 * @see         fn_dynamic_qr_takedown.php - dynamicQrQueueNotice()
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
$subject = 'A notice about a CueRCode dynamic QR code';
if (isset($params['subject']) && is_string($params['subject']) && trim($params['subject']) !== '') {
    $subject = trim($params['subject']);
}

$heading = $subject;
if (isset($params['heading']) && is_string($params['heading']) && trim($params['heading']) !== '') {
    $heading = trim($params['heading']);
}

$lines = [];
if (isset($params['lines']) && is_array($params['lines'])) {
    foreach ($params['lines'] as $line) {
        if (is_string($line) && $line !== '') {
            $lines[] = $line;
        }
    }
}

$sections = [];
if (isset($params['sections']) && is_array($params['sections'])) {
    foreach ($params['sections'] as $section) {
        if (!is_array($section) || !isset($section['title']) || !is_string($section['title'])) {
            continue;
        }
        $sectionLines = [];
        if (isset($section['lines']) && is_array($section['lines'])) {
            foreach ($section['lines'] as $sectionLine) {
                if (is_string($sectionLine) && $sectionLine !== '') {
                    $sectionLines[] = $sectionLine;
                }
            }
        }
        $sections[] = ['title' => $section['title'], 'lines' => $sectionLines];
    }
}

// 🔗 Only an https address may become the one link.
$linkUrl = '';
if (isset($params['linkUrl']) && is_string($params['linkUrl']) && str_starts_with($params['linkUrl'], 'https://')) {
    $linkUrl = $params['linkUrl'];
}
$linkLabel = 'Open CueRCode';
if (isset($params['linkLabel']) && is_string($params['linkLabel']) && trim($params['linkLabel']) !== '') {
    $linkLabel = trim($params['linkLabel']);
}

// ==============================================================================
// 📝 Inner content
// ==============================================================================
$innerHtml = '<p style="margin:0 0 16px 0; font-weight:bold;">' . emailEscape($heading) . '</p>';

foreach ($lines as $line) {
    $innerHtml .= '<p style="margin:0 0 12px 0;">' . emailEscape($line) . '</p>';
}

foreach ($sections as $section) {
    $innerHtml .= '<p style="margin:20px 0 8px 0; font-weight:bold;">' . emailEscape($section['title']) . '</p>';
    if ($section['lines'] === []) {
        $innerHtml .= '<p style="margin:0 0 12px 0; color:#666666;">Nothing to report.</p>';
        continue;
    }
    $innerHtml .= '<ul style="margin:0 0 12px 0; padding-left:20px;">';
    foreach ($section['lines'] as $sectionLine) {
        $innerHtml .= '<li style="margin:0 0 6px 0; word-break:break-word;">' . emailEscape($sectionLine) . '</li>';
    }
    $innerHtml .= '</ul>';
}

if ($linkUrl !== '') {
    $innerHtml .= '<p style="margin:24px 0 0 0;"><a href="' . emailEscape($linkUrl) . '" style="color:#2c7a7b;">' . emailEscape($linkLabel) . '</a></p>';
}

// ==============================================================================
// 🖼️ Wrap in the shared branded shell and return [subject, htmlBody]
// ==============================================================================
// ⚠️ RAW text for the title/preheader: emailRenderShell() escapes both itself.
$htmlBody = emailRenderShell($subject, $heading, $innerHtml);

return [$subject, $htmlBody];
