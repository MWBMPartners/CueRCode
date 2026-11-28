<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Takedowns and the daily re-check: the parts with no database (WP4e, #168)
 * ==============================================================================
 *
 * Path: tests/test_dynamic_qr_takedown.php
 *
 *   1. Upgrade 0008 is valid and adds exactly the planned pieces.
 *   2. The words of the notices: why a code was suspended, the owner's and
 *      the abuse mailbox's emails (the destination "defanged" for admins and
 *      never shown to the owner as a reason), the cron lines.
 *   3. The cron rule: a command-line run must declare its site copy.
 *   4. Source checks: the order "here first, then Go2My.Link"; the takedown
 *      sent under the owner's lock; the change flow's rule 12; the cron jobs
 *      call the steps; every check goes through the one hook.
 *
 * The database parts are proved by tests/integration/dynamic_qr_abuse_real_mysql.php.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (WP4e, #168)
 * @see         web/CueRCode.net/_functions/fn_dynamic_qr_takedown.php
 */

requireFn('fn_migrations.php');
requireFn('fn_dynamic_qr_takedown.php');
requireFn('fn_email.php');

// ==============================================================================
// ✅ Section 1: upgrade 0008
// ==============================================================================
$tdPath = migrationDefaultDirectory() . '0008_link_safety_and_takedown.php';
assertTrue(is_file($tdPath), 'upgrade 0008 exists');
$tdDefinition = require $tdPath;
assertEquals([], migrationValidateDefinition($tdDefinition, '0008_link_safety_and_takedown'), 'upgrade 0008 passes the upgrade runner\'s own checks');
$tdSteps = [];
$tdAllSql = '';
foreach ($tdDefinition['steps'] as $tdStep) {
    $tdName = '';
    if (isset($tdStep['name'])) {
        $tdName = $tdStep['name'];
    }
    $tdSteps[] = $tdStep['type'] . ' ' . $tdStep['table'] . ' ' . $tdName;
    $tdAllSql .= "\n" . $tdStep['sql'];
}
assertEquals([
    'add_column tblQRRedirects safetyVerdict',
    'add_column tblQRRedirects safetyCheckedAt',
    'add_index tblQRRedirects idx_redirect_safety_checked',
    'add_column tblQRRedirects suspensionAppliedAt',
    'add_index tblQRRedirects idx_redirect_suspension_sync',
    'create_table tblLinkSafetyHits ',
    'add_column tblAbuseReports reporterNetworkHash',
    'add_column tblAbuseReports countedForSuspension',
    'add_index tblAbuseReports idx_abuse_counting',
], $tdSteps, 'upgrade 0008: exactly the planned steps, in order');
assertFalse(str_contains($tdAllSql, 'ENUM('), 'upgrade 0008 adds no ENUM column (an upgrade could never widen one)');
assertFalse(str_contains($tdAllSql, 'tblSettings'), 'upgrade 0008 seeds no settings (the new ones use their defaults until saved)');
assertFalse(str_contains($tdAllSql, 'dynamic_qr_enabled'), 'upgrade 0008 never touches dynamic_qr_enabled');
assertContains('`countedForSuspension` TINYINT(1) NOT NULL DEFAULT 0', $tdAllSql, 'a report counts for nothing automatically unless the code says it passed the human check');
assertContains('`suspensionAppliedAt` DATETIME NULL', $tdAllSql, 'a takedown not yet confirmed by Go2My.Link is an empty suspensionAppliedAt');
assertFalse((bool) preg_match('/`(reporterIP|requestIP|reporterNetwork)`/', $tdAllSql), 'no plain address or plain network is stored');
foreach ($tdDefinition['steps'] as $tdStep) {
    if ($tdStep['type'] !== 'create_table') {
        continue;
    }
    $tdBlank = migrationBlankQuotedText($tdStep['sql']);
    $tdBare  = preg_replace('/`[^`]*`/', '', (string) $tdBlank['text']);
    foreach (['urlHash', 'destinationURL', 'provider', 'threatTypes', 'occasion', 'redirectID', 'userID', 'expiresAt', 'createdAt'] as $tdColumn) {
        assertFalse(str_contains((string) $tdBare, $tdColumn), 'tblLinkSafetyHits: ' . $tdColumn . ' is always back-quoted');
    }
}

// ==============================================================================
// ✅ Section 2: the words
// ==============================================================================
assertEquals('https[:]//evil[.]example[.]com/login?x=1', dynamicQrDefangUrl('https://evil.example.com/login?x=1'), 'a harmful address is written so a mail program does not make it a link');
assertEquals('phishing or another trick, malware', dynamicQrThreatWords(['SOCIAL_ENGINEERING', 'MALWARE', 'SOCIAL_ENGINEERING_EXTENDED_COVERAGE']), 'Web Risk\'s list names in plain words, each once');
assertEquals('a harmful page', dynamicQrThreatWords([]), '...and a fallback');
assertContains('Google Web Risk lists its destination as harmful (malware)', dynamicQrSuspensionWhy('link_safety', ['providerName' => 'Google Web Risk', 'threatTypes' => ['MALWARE']]), 'why (link safety): the service and the list');
assertContains('3 different people reported it as harmful within 24 hours', dynamicQrSuspensionWhy('reports', ['reports' => 3, 'hours' => 24]), 'why (reports): how many people, in how long');
assertContains('An administrator', dynamicQrSuspensionWhy('admin', []), 'why (admin)');

$tdRow = ['shortCode' => 'Ab3dE9x', 'ownerUserID' => 11, 'redirectUUID' => 'a1b2c3d4-e5f6-4890-abcd-ef1234567890', 'destinationURL' => 'https://evil.example/login'];
$tdNotices = dynamicQrSuspensionNotices($tdRow, 'https://QRmy.Link/Ab3dE9x', 'Because.', 'automatically by CueRCode (link-safety check)', 'We act on reports within one working day.', 'abuse@cuercode.net');
$tdOwnerText = implode(' ', $tdNotices['owner']['lines']);
$tdAbuseText = implode(' ', $tdNotices['abuse']['lines']);
assertContains('https://QRmy.Link/Ab3dE9x has been suspended', $tdOwnerText, 'the owner is told which code');
assertContains('cannot change it or switch it back on yourself', $tdOwnerText, 'the owner is told they cannot undo it');
assertContains('email abuse@cuercode.net. We act on reports within one working day.', $tdOwnerText, 'the owner is told where to write, and the promise (decision 0034)');
assertFalse(str_contains($tdOwnerText, 'evil.example'), 'the owner\'s email does not repeat the destination');
assertContains('https[:]//evil[.]example/login', $tdAbuseText, 'the abuse mailbox gets the destination, defanged');
assertFalse(str_contains($tdAbuseText, 'https://evil.example'), '...never as a clickable address');
assertContains('account 11', $tdAbuseText, 'the abuse mailbox gets the account number');
assertFalse(str_contains($tdNotices['abuse']['subject'] . $tdNotices['owner']['subject'], 'evil'), 'no subject carries the destination (subjects are kept in the activity log)');

$tdLines = implode(' ', dynamicQrApplyPendingLines(['ok' => true, 'skipped' => 'copy_unknown', 'looked' => 0, 'applied' => 0, 'waiting' => 2, 'failed' => 0]));
assertContains('NOT sent', $tdLines, 'the cron line says plainly when takedowns were not sent');
assertContains('CUERCODE_SITE_COPY=live', $tdLines, '...and how to fix the cron line');
$tdLines = implode(' ', dynamicQrRecheckLines(['ok' => true, 'skipped' => '', 'checked' => 9, 'safe' => 5, 'unsafe' => 1, 'unchecked' => 3, 'suspended' => 1, 'stoppedEarly' => true, 'left' => 4, 'reason' => 'timeout']));
assertContains('checked 9 code(s): 5 safe, 1 listed as unsafe (1 suspended), 3 could not be checked. 4 still due.', $tdLines, 'the re-check line gives every count');
assertContains('stopped early', $tdLines, '...and says when it stopped early, and why');
assertEquals([], dynamicQrApplyPendingLines(['ok' => true, 'skipped' => '', 'looked' => 0, 'applied' => 0, 'waiting' => 0, 'failed' => 0]), 'nothing waiting: nothing printed');

// ==============================================================================
// ✅ Section 3: the cron rule
// ==============================================================================
assertEquals('copy_unknown', dynamicQrCronCopyProblem(['server' => [], 'commandLine' => true]), 'a command-line run with no declared copy may not call Go2My.Link or Google');
assertEquals('', dynamicQrCronCopyProblem(['server' => ['CUERCODE_SITE_COPY' => 'live'], 'commandLine' => true]), '...a declared copy may');
assertEquals('', dynamicQrCronCopyProblem(['server' => [], 'commandLine' => false]), '...and a web request may (its own folder shows the copy)');

// ==============================================================================
// ✅ Section 4: source checks
// ==============================================================================
$tdSource = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_dynamic_qr_takedown.php');
$tdSuspend = substr($tdSource, (int) strpos($tdSource, 'function dynamicQrSuspend('), 9000);
$tdLocalAt  = strpos($tdSuspend, 'UPDATE `tblQRRedirects` SET `suspendedAt` = UTC_TIMESTAMP()');
$tdAuditAt  = strpos($tdSuspend, 'adminAuditWrite(');
$tdCommitAt = strpos($tdSuspend, 'getDB()->commit()');
$tdRemoteAt = strpos($tdSuspend, 'dynamicQrApplySuspension(');
assertTrue($tdLocalAt !== false && $tdAuditAt !== false && $tdCommitAt !== false && $tdRemoteAt !== false && $tdLocalAt < $tdAuditAt && $tdAuditAt < $tdCommitAt && $tdCommitAt < $tdRemoteAt, 'a suspension is saved here with its admin change log row FIRST, in one transaction, and only then sent to Go2My.Link');
$tdApply = substr($tdSource, (int) strpos($tdSource, 'function dynamicQrApplySuspension('), 1600);
assertTrue(strpos($tdApply, 'acquireDynamicCodeLimitLock(') !== false && strpos($tdApply, 'releaseDynamicCodeLimitLock(') !== false, 'a takedown is sent to Go2My.Link under the owner\'s lock (so an owner\'s change cannot be sent at the same moment)');
assertContains('`suspensionAppliedAt` = UTC_TIMESTAMP() WHERE `redirectID` = ? AND `suspendedAt` IS NOT NULL AND `suspensionAppliedAt` IS NULL', $tdSource, 'only a confirmed takedown fills suspensionAppliedAt');
assertEquals(2, substr_count($tdSource, 'dynamicQrCheckDestinationSafety('), 'the daily re-check and lifting a suspension go through the one link-safety hook');
assertTrue(substr_count($tdSource, 'dynamicQrCronCopyProblem($options)') === 2, 'both cron steps (re-sending takedowns, the re-check) refuse to call out without a declared site copy');

$tdRedirects = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_api_redirects.php');
$tdChange = substr($tdRedirects, (int) strpos($tdRedirects, 'function dynamicQrApplyChange('), 12000);
assertTrue(strpos($tdChange, '$changedRows !== 1') !== false && strpos($tdChange, 'dynamicQrTakedownAfterOwnerRace(') !== false, 'rule 12: a change whose save finds the code suspended sends the takedown again');

$tdQueue = (string) file_get_contents(CUERCODE_INCLUDES_DIR . 'cron' . DIRECTORY_SEPARATOR . 'process_email_queue.php');
assertContains('dynamicQrApplyPendingSuspensions()', $tdQueue, 'the every-minute cron job sends waiting takedowns again');
$tdDaily = (string) file_get_contents(CUERCODE_INCLUDES_DIR . 'cron' . DIRECTORY_SEPARATOR . 'dynamic_qr_daily.php');
assertTrue(str_starts_with(trim(substr($tdDaily, (int) strpos($tdDaily, '*/') + 2)), "// ==") && strpos($tdDaily, "if (PHP_SAPI !== 'cli')") < strpos($tdDaily, 'require_once'), 'the daily job refuses anything but the command line before loading anything');
assertContains('dynamicQrRecheckSafety()', $tdDaily, 'the daily job runs the link-safety re-check');
assertContains('urlReputationPurgeOldHits(', $tdDaily, 'the daily job deletes old link-safety records');
assertTrue(is_file(CUERCODE_EMAIL_TEMPLATES_DIR . 'dynamic_qr_notice.php') && isValidEmailTemplateKey('dynamic_qr_notice'), 'the notice email template exists and is allowed');
[$tdSubject, $tdBody] = renderEmailTemplate('dynamic_qr_notice', ['subject' => 'Test <b>', 'lines' => ['A line with <script>'], 'linkUrl' => 'javascript:alert(1)']);
assertTrue($tdSubject === 'Test <b>' && str_contains($tdBody, 'A line with &lt;script&gt;') && !str_contains($tdBody, 'javascript:'), 'the notice template escapes every value and only links to an https address');
