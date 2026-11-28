<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Email addresses in the activity log (#129)
 * ==============================================================================
 *
 * Path: tests/test_email_log_privacy.php
 *
 * No database needed. Checks the pure rule that takes an erased account's
 * address out of an activity-log entry, that account erasure uses it, and
 * that the privacy page and the admin settings screen describe the periods.
 * The SQL itself is proved in tests/integration/ (account_recovery and
 * email_queue).
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (#129)
 */

// 📌 fn_log_mask.php only: it defines no logging function, so other test
//    files that expect none to exist are not disturbed.
requireFn('fn_log_mask.php');

$elSite = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'CueRCode.net' . DIRECTORY_SEPARATOR;

if (!function_exists('activityLogEntryWithoutAddress')) {
    assertTrue(false, '#129: the function that removes an address from a log entry exists');
} else {
    $elOut = activityLogEntryWithoutAddress(
        'To: Pat@Example.invalid | Subject: Hello | failed for pat@example.invalid',
        '{"to":"Pat@Example.invalid","other":1}',
        'pat@example.invalid'
    );
    assertEquals('To: [address removed] | Subject: Hello | failed for [address removed]', $elOut['detail'] ?? null, '#129: every copy in the text is replaced, whatever its capitals');
    assertEquals('{"to":"[address removed]","other":1}', $elOut['additionalData'] ?? null, '#129: ...and in the extra data, keeping the rest');

    assertEquals(null, activityLogEntryWithoutAddress('To: xpat@example.invalid | Subject: a | b', '{"to":"xpat@example.invalid"}', 'pat@example.invalid'), '#129: an entry for a longer address is left alone');
    assertEquals(null, activityLogEntryWithoutAddress("To: p\u{00E1}t@example.invalid | Subject: a | b", null, 'pat@example.invalid'), '#129: an address differing by an accent is another person');

    $elOut = activityLogEntryWithoutAddress('To: pat@example.invalid | Subject: a | b', null, 'pat@example.invalid');
    assertEquals('To: [address removed] | Subject: a | b', $elOut['detail'] ?? null, '#129: an entry with no extra data is handled');
    assertTrue(is_array($elOut) && $elOut['additionalData'] === null, '#129: ...and still has none');

    assertEquals(null, activityLogEntryWithoutAddress('To: pat@example.invalid | x', null, ''), '#129: an empty address matches nothing');
}

$elAccount = (string) file_get_contents($elSite . '_functions' . DIRECTORY_SEPARATOR . 'fn_api_account.php');
$elCall    = strpos($elAccount, 'activityLogEraseEmailAddress($erasedEmailAddress)');
$elCommit  = false;
if ($elCall !== false) {
    $elCommit = strpos($elAccount, '$db->commit();', $elCall);
}
assertTrue($elCall !== false && $elCommit !== false, '#129: account erasure removes the address from the activity log inside its transaction');

$elPrivacy = (string) file_get_contents($elSite . 'public_html' . DIRECTORY_SEPARATOR . 'privacy.php');
assertContains('emailQueueHousekeepingOptions', $elPrivacy, '#129: the privacy page shows the periods actually in force');
assertContains('activity log', $elPrivacy, '#129: the privacy page says the activity log is covered');

$elSettings = (string) file_get_contents($elSite . '_functions' . DIRECTORY_SEPARATOR . 'fn_admin_settings.php');
assertContains('activity-log entries', $elSettings, '#129: the admin screen says the periods cover the activity log');

// ==============================================================================
// #179: error log and visitor details - admin-editable periods, privacy page
// ==============================================================================
requireFn('fn_admin_settings.php');
$elRegistry = adminSettingsRegistry();
foreach (['error_log_retention_days' => 'error log', 'activity_visitor_details_retention_days' => 'visitor address and browser name'] as $elKey => $elWords) {
    assertTrue(isset($elRegistry[$elKey]) && $elRegistry[$elKey]['section'] === 'email-records' && $elRegistry[$elKey]['min'] === 1 && $elRegistry[$elKey]['max'] === 3650 && $elRegistry[$elKey]['default'] === 90 && $elRegistry[$elKey]['rowOptional'] === true, '#179: a Global Admin can set the ' . $elWords . ' period (1-3650 days, 90 until saved)');
}
assertTrue(str_contains($elPrivacy, "privacyEmailPeriods['errorLogRetentionDays']") && str_contains($elPrivacy, "privacyEmailPeriods['visitorDetailsRetentionDays']"), '#179: the privacy page states the error-log and visitor-details periods actually in force');
assertFalse(str_contains($elPrivacy, 'Technical and error logs are retained for a limited diagnostic period'), '#179: the vague "limited diagnostic period" sentence is gone');
