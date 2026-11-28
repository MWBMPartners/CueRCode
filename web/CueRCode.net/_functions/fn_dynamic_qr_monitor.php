<?php
/**
 * ==============================================================================
 * 📊 CueRCode - The daily dynamic-code monitoring email (WP4g, #170)
 * ==============================================================================
 *
 * Path: _functions/fn_dynamic_qr_monitor.php
 *
 * WHAT THIS IS (plain English):
 *   Once a day (_includes/cron/dynamic_qr_daily.php) the Global Admins and
 *   the abuse mailbox get one email about the last 24 hours of dynamic codes,
 *   so a person notices abuse even when nobody reports it:
 *     1. new codes and changed destinations (each with its account number);
 *     2. the busiest codes, by their total scans on Go2My.Link;
 *     3. link-safety hits (destinations Google Web Risk listed), codes that
 *        could not be checked, and the daily re-check's result;
 *     4. new abuse reports, how many were counted, the most reported codes
 *        and how many are still open;
 *     5. suspensions, and takedowns Go2My.Link has not confirmed yet;
 *     6. differences between CueRCode and Go2My.Link (lead choice L5): a
 *        link pointing somewhere other than CueRCode's record says, a link
 *        switched off or missing on Go2My.Link. A SUSPENDED code that
 *        Go2My.Link points elsewhere is also marked so the every-minute job
 *        sends its takedown again.
 *   It is sent even when nothing happened ("nothing to report"), so silence
 *   always means the job did not run. At most one email per 20 hours, so a
 *   cron job run twice does not send two (--force sends anyway).
 *
 *   Harmful-looking addresses are written "defanged" (https[:]//example[.]com)
 *   so a mail program does not turn them into links. Owners are named by
 *   account number only, never by email address.
 *
 *   Go2My.Link is only called when the cron line declares the copy of the
 *   site (CUERCODE_SITE_COPY); otherwise the email says the comparison was
 *   skipped and why. At most 500 codes are compared a day (the rest are
 *   counted as "not compared").
 *
 * House rules: full multi-line PHP; MySQLi prepared statements; every SQL
 * name back-quoted. Loading defines functions only.
 *
 * @package     CueRCode
 * @subpackage  DynamicQR
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (WP4g, #170)
 * @see         _functions/fn_dynamic_qr_takedown.php - the notices, the cron copy rule
 * @see         _includes/cron/dynamic_qr_daily.php
 */

// ==============================================================================
// 🚫 Direct access protection
// ==============================================================================
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $monitorScriptFilename = $_SERVER['SCRIPT_FILENAME'];
} else {
    $monitorScriptFilename = '';
}
if (basename($monitorScriptFilename) === basename(__FILE__)) {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
    }
    exit('Access denied.');
}
unset($monitorScriptFilename);

if (!function_exists('dynamicQrSuspend')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_dynamic_qr_takedown.php';
}
if (!function_exists('abuseReportCategories')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_abuse_reports.php';
}

// ==============================================================================
// 🔧 Constants
// ==============================================================================

/** @var int Most codes compared with Go2My.Link in one daily run (bounds the calls). */
if (!defined('CUERCODE_MONITOR_COMPARE_CAP')) {
    define('CUERCODE_MONITOR_COMPARE_CAP', 500);
}

/** @var int The comparison stops after this many look-ups in a row that could not answer. */
if (!defined('CUERCODE_MONITOR_COMPARE_STOP_AFTER')) {
    define('CUERCODE_MONITOR_COMPARE_STOP_AFTER', 3);
}

/** @var int Most lines listed in one section of the email (the rest are counted). */
if (!defined('CUERCODE_MONITOR_SECTION_LINES')) {
    define('CUERCODE_MONITOR_SECTION_LINES', 50);
}

/** @var int Seconds before another daily email may be sent (20 hours). */
if (!defined('CUERCODE_MONITOR_EMAIL_GAP_SECONDS')) {
    define('CUERCODE_MONITOR_EMAIL_GAP_SECONDS', 72000);
}

// ==============================================================================
// 🧠 PURE: what Go2My.Link should show
// ==============================================================================

/**
 * 🎯 Where Go2My.Link should send a code's scans, from CueRCode's record:
 *    the neutral page while it is suspended or switched off, otherwise its
 *    destination. PURE.
 *
 * @param array<string, mixed> $row         tblQRRedirects
 * @param string               $neutralPage dynamicQrUnavailablePageUrl() for this code
 * @return string
 */
function dynamicQrExpectedRemote(array $row, string $neutralPage): string
{
    if (dynamicQrRowIsSuspended($row)) {
        return $neutralPage;
    }
    if ((int) $row['isActive'] !== 1) {
        return $neutralPage;
    }

    return (string) $row['destinationURL'];
}

/**
 * 🔍 Is Go2My.Link's link different from CueRCode's record? '' when they
 *    agree, otherwise the difference in plain words. PURE.
 *
 * @param string $expected dynamicQrExpectedRemote()
 * @param array  $remote   go2mylinkLookupLink()'s data: destinationUrl, isActive
 * @return string
 */
function dynamicQrRemoteDifference(string $expected, array $remote): string
{
    if (isset($remote['isActive']) && $remote['isActive'] === false) {
        return 'switched off on Go2My.Link';
    }
    if (!isset($remote['destinationUrl']) || (string) $remote['destinationUrl'] !== $expected) {
        return 'Go2My.Link sends scans somewhere else';
    }

    return '';
}

// ==============================================================================
// 🌐 Comparing with Go2My.Link (lead choice L5) and the scan totals
// ==============================================================================

/**
 * 🌐 Look every working or suspended code up on Go2My.Link (at most 500 a
 *    run), note each difference, collect the scan totals, and mark a
 *    suspended code that Go2My.Link points elsewhere so its takedown is sent
 *    again. Stops after three look-ups in a row that could not answer.
 *
 * @param array<string, mixed> $options 'go2mylink', 'server', 'commandLine' (tests), 'cap'
 * @return array{ok: bool, skipped: string, compared: int, notCompared: int, stoppedEarly: bool, reason: string,
 *               differences: array<int, array<string, mixed>>, totals: array<int, array<string, mixed>>, resent: int}
 */
function dynamicQrCompareWithGo2MyLink(#[\SensitiveParameter] array $options = []): array
{
    $summary = ['ok' => false, 'skipped' => '', 'compared' => 0, 'notCompared' => 0, 'stoppedEarly' => false, 'reason' => '', 'differences' => [], 'totals' => [], 'resent' => 0];

    if (!function_exists('dbQuery')) {
        return $summary;
    }
    if (!dynamicQrUpgrade0008Ready()) {
        $summary['ok']      = true;
        $summary['skipped'] = 'upgrade_waiting';
        return $summary;
    }

    $cap = CUERCODE_MONITOR_COMPARE_CAP;
    if (isset($options['cap']) && is_int($options['cap']) && $options['cap'] > 0) {
        $cap = $options['cap'];
    }

    try {
        $countStmt = dbQuery("SELECT COUNT(*) AS `total` FROM `tblQRRedirects` WHERE `syncState` = 'active'", '', []);
        $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();
        $stmt = dbQuery(
            'SELECT ' . CUERCODE_TAKEDOWN_ROW_COLUMNS . " FROM `tblQRRedirects` WHERE `syncState` = 'active' ORDER BY `redirectID` LIMIT ?",
            'i',
            [$cap]
        );
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'The daily comparison with Go2My.Link could not read the codes', get_class($e));
        }
        return $summary;
    }

    $summary['ok']          = true;
    $summary['notCompared'] = $total;
    if ($rows === []) {
        return $summary;
    }

    $copyProblem = dynamicQrCronCopyProblem($options);
    if ($copyProblem !== '') {
        $summary['skipped'] = $copyProblem;
        return $summary;
    }
    $go2Options = dynamicQrGo2Options($options);
    $settings   = go2mylinkSettingsFromOptions($go2Options);
    if ($settings['problem'] !== '') {
        $summary['skipped'] = (string) $settings['problem'];
        return $summary;
    }

    $siteUrl = (string) humanCheckText(humanCheckSiteConfig('site_url', ''));
    $unansweredInARow = 0;
    foreach ($rows as $row) {
        $shortCode = (string) $row['shortCode'];
        $lookup    = go2mylinkLookupLink($shortCode, $go2Options);

        if ($lookup['ok'] !== true && $lookup['reason'] !== 'not_found') {
            $summary['reason'] = (string) $lookup['reason'];
            $unansweredInARow++;
            if ($unansweredInARow >= CUERCODE_MONITOR_COMPARE_STOP_AFTER) {
                $summary['stoppedEarly'] = true;
                break;
            }
            continue;
        }
        $unansweredInARow = 0;
        $summary['compared']++;

        $difference = 'missing on Go2My.Link';
        $actual     = '';
        if ($lookup['ok'] === true) {
            $actual     = (string) $lookup['data']['destinationUrl'];
            $difference = dynamicQrRemoteDifference(dynamicQrExpectedRemote($row, dynamicQrUnavailablePageUrl($siteUrl, $shortCode)), $lookup['data']);
            $summary['totals'][] = ['row' => $row, 'total' => (int) $lookup['data']['clickCount']];
        }

        if ($difference !== '') {
            $summary['differences'][] = ['row' => $row, 'difference' => $difference, 'actual' => $actual];
            // 🔁 A suspended code Go2My.Link points elsewhere: send it again.
            if ($lookup['ok'] === true && $row['suspendedAt'] !== null) {
                dynamicQrMarkSuspensionUnapplied((int) $row['redirectID']);
                $summary['resent']++;
            }
        }
    }

    $summary['notCompared'] = max(0, $total - $summary['compared']);

    // 📈 The busiest codes first.
    usort($summary['totals'], static function (array $left, array $right): int {
        return $right['total'] <=> $left['total'];
    });
    $summary['totals'] = array_slice($summary['totals'], 0, 10);

    return $summary;
}

// ==============================================================================
// 🗄️ The last 24 hours
// ==============================================================================

/**
 * 🗄️ Rows for one section of the report (an empty list when it cannot be read).
 *
 * @param string $sql
 * @param string $types
 * @param array  $params
 * @return array<int, array<string, mixed>>|null Null on a database problem
 */
function dynamicQrMonitorRows(string $sql, string $types = '', array $params = []): ?array
{
    try {
        $stmt = dbQuery($sql, $types, $params);
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'The daily dynamic-code report could not read a section', get_class($e));
        }
        return null;
    }

    return $rows;
}

/**
 * ✂️ At most CUERCODE_MONITOR_SECTION_LINES lines, and a last line counting
 *    the rest. PURE.
 *
 * @param array<int, string> $lines
 * @return array<int, string>
 */
function dynamicQrMonitorTrim(array $lines): array
{
    if (count($lines) <= CUERCODE_MONITOR_SECTION_LINES) {
        return $lines;
    }

    $shown   = array_slice($lines, 0, CUERCODE_MONITOR_SECTION_LINES);
    $shown[] = '... and ' . (count($lines) - CUERCODE_MONITOR_SECTION_LINES) . ' more (see /admin/abuse/ and the database).';

    return $shown;
}

/**
 * 📊 Everything the daily email says, as sections of plain lines.
 *
 * @param array<string, mixed> $runs 'recheck' (dynamicQrRecheckSafety()), 'compare'
 *                                   (dynamicQrCompareWithGo2MyLink()), 'copy' ('' or 'copy_unknown')
 * @return array{ok: bool, subject: string, lines: array<int, string>, sections: array<int, array{title: string, lines: array<int, string>}>}
 */
function dynamicQrMonitorReport(array $runs): array
{
    $ok = true;
    $sections = [];

    // 🆕 1. New codes and changed destinations.
    $new = dynamicQrMonitorRows(
        'SELECT `shortCode`, `ownerUserID`, `destinationURL`, `syncState` FROM `tblQRRedirects` '
        . "WHERE `createdAt` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR) AND `syncState` <> 'failed' ORDER BY `redirectID`"
    );
    $lines = [];
    if ($new === null) {
        $ok = false;
        $lines[] = 'Could not be read (see the error log).';
    } else {
        foreach ($new as $row) {
            $lines[] = dynamicQrPrintedAddress($row) . ' (account ' . (int) $row['ownerUserID'] . ', ' . (string) $row['syncState'] . '): ' . dynamicQrDefangUrl((string) $row['destinationURL']);
        }
    }
    $sections[] = ['title' => 'New dynamic codes in the last 24 hours (' . count((array) $new) . ')', 'lines' => dynamicQrMonitorTrim($lines)];

    $changed = dynamicQrMonitorRows(
        'SELECT `shortCode`, `ownerUserID`, `destinationURL`, `previousDestinationURL` FROM `tblQRRedirects` '
        . 'WHERE `destinationUpdatedAt` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR) ORDER BY `destinationUpdatedAt`'
    );
    $lines = [];
    if ($changed === null) {
        $ok = false;
        $lines[] = 'Could not be read (see the error log).';
    } else {
        foreach ($changed as $row) {
            $lines[] = dynamicQrPrintedAddress($row) . ' (account ' . (int) $row['ownerUserID'] . '): from ' . dynamicQrDefangUrl((string) $row['previousDestinationURL']) . ' to ' . dynamicQrDefangUrl((string) $row['destinationURL']);
        }
    }
    $sections[] = ['title' => 'Destinations changed in the last 24 hours (' . count((array) $changed) . ')', 'lines' => dynamicQrMonitorTrim($lines)];

    // 📈 2. The busiest codes (Go2My.Link's totals).
    $compare = $runs['compare'];
    $lines = [];
    foreach ($compare['totals'] as $item) {
        $lines[] = dynamicQrPrintedAddress($item['row']) . ' (account ' . (int) $item['row']['ownerUserID'] . '): ' . number_format((int) $item['total']) . ' scans in all';
    }
    $sections[] = ['title' => 'Busiest codes (total scans on Go2My.Link)', 'lines' => $lines];

    // 🛡️ 3. Link safety.
    $hits = dynamicQrMonitorRows(
        'SELECT `h`.`destinationURL`, `h`.`threatTypes`, `h`.`occasion`, `h`.`userID`, `r`.`shortCode` FROM `tblLinkSafetyHits` AS `h` '
        . 'LEFT JOIN `tblQRRedirects` AS `r` ON `r`.`redirectID` = `h`.`redirectID` WHERE `h`.`createdAt` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR) ORDER BY `h`.`hitID`'
    );
    $unchecked = dynamicQrMonitorRows(
        "SELECT COUNT(*) AS `c` FROM `tblQRRedirects` WHERE `syncState` = 'active' AND `isActive` = 1 AND `suspendedAt` IS NULL AND (`safetyVerdict` IS NULL OR `safetyVerdict` = 'unchecked')"
    );
    $lines = [];
    if (!urlReputationHasUsableProvider(urlReputationReadSettings())) {
        $lines[] = 'WARNING: no link-safety provider has a key saved, so destinations are NOT being checked. Enter the Google Web Risk key on /admin/settings/.';
    }
    if ($hits === null || $unchecked === null) {
        $ok = false;
        $lines[] = 'Could not be read (see the error log).';
    } else {
        foreach ($hits as $hit) {
            $where = 'a new code that was refused';
            if ($hit['shortCode'] !== null) {
                $where = 'code ' . (string) $hit['shortCode'];
            }
            $account = 'account unknown';
            if ($hit['userID'] !== null) {
                $account = 'account ' . (int) $hit['userID'];
            }
            $lines[] = (string) $hit['occasion'] . ', ' . $where . ' (' . $account . '): ' . dynamicQrDefangUrl((string) $hit['destinationURL']) . ' listed for ' . dynamicQrThreatWords(explode(',', (string) $hit['threatTypes']));
        }
        $lines[] = (int) $unchecked[0]['c'] . ' code(s) in use could not be checked yet (they are checked again daily).';
    }
    foreach (dynamicQrRecheckLines($runs['recheck']) as $recheckLine) {
        $lines[] = $recheckLine;
    }
    $sections[] = ['title' => 'Link-safety check (' . count((array) $hits) . ' harmful destination(s) found)', 'lines' => $lines];

    // 📣 4. Abuse reports.
    $reports = dynamicQrMonitorRows(
        'SELECT COUNT(*) AS `total`, COALESCE(SUM(`countedForSuspension`), 0) AS `counted`, COUNT(DISTINCT `shortCode`) AS `codes` FROM `tblAbuseReports` '
        . 'WHERE `createdAt` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)'
    );
    $mostReported = dynamicQrMonitorRows(
        'SELECT `shortCode`, COUNT(*) AS `reports` FROM `tblAbuseReports` WHERE `createdAt` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR) '
        . 'GROUP BY `shortCode` ORDER BY `reports` DESC, `shortCode` LIMIT 10'
    );
    $open = dynamicQrMonitorRows("SELECT COUNT(*) AS `open` FROM `tblAbuseReports` WHERE `status` IN ('new', 'reviewing')");
    $lines = [];
    if ($reports === null || $mostReported === null || $open === null) {
        $ok = false;
        $lines[] = 'Could not be read (see the error log).';
    } else {
        $lines[] = (int) $reports[0]['total'] . ' report(s) about ' . (int) $reports[0]['codes'] . ' code(s); ' . (int) $reports[0]['counted'] . ' passed the human check (counted), '
            . ((int) $reports[0]['total'] - (int) $reports[0]['counted']) . ' did not (read by a person, not counted).';
        foreach ($mostReported as $row) {
            $lines[] = 'Code ' . (string) $row['shortCode'] . ': ' . (int) $row['reports'] . ' report(s).';
        }
        $lines[] = (int) $open[0]['open'] . ' report(s) are still open on /admin/abuse/. ' . dynamicQrTakedownPromise();
    }
    $sections[] = ['title' => 'Abuse reports in the last 24 hours', 'lines' => $lines];

    // 🚫 5. Suspensions.
    $suspended = dynamicQrMonitorRows(
        'SELECT `r`.`shortCode`, `r`.`ownerUserID`, `r`.`suspensionNote`, `r`.`suspendedByUserID`, `s`.`email` AS `byEmail` FROM `tblQRRedirects` AS `r` '
        . 'LEFT JOIN `tblUsers` AS `s` ON `s`.`userID` = `r`.`suspendedByUserID` WHERE `r`.`suspendedAt` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR) ORDER BY `r`.`suspendedAt`'
    );
    $waiting = dynamicQrMonitorRows('SELECT COUNT(*) AS `c` FROM `tblQRRedirects` WHERE `suspendedAt` IS NOT NULL AND `suspensionAppliedAt` IS NULL');
    $lines = [];
    if ($suspended === null || $waiting === null) {
        $ok = false;
        $lines[] = 'Could not be read (see the error log).';
    } else {
        foreach ($suspended as $row) {
            $by = 'automatically';
            if ($row['byEmail'] !== null) {
                $by = 'by ' . (string) $row['byEmail'];
            }
            $lines[] = dynamicQrPrintedAddress($row) . ' (account ' . (int) $row['ownerUserID'] . '), ' . $by . ': ' . (string) $row['suspensionNote'];
        }
        if ((int) $waiting[0]['c'] > 0) {
            $lines[] = (int) $waiting[0]['c'] . ' suspended code(s) are NOT yet confirmed on Go2My.Link (the every-minute job keeps sending them; check its cron line and Go2My.Link).';
        }
    }
    $sections[] = ['title' => 'Suspensions in the last 24 hours (' . count((array) $suspended) . ')', 'lines' => $lines];

    // 🔍 6. Differences with Go2My.Link (L5).
    $lines = [];
    if ($compare['ok'] !== true) {
        $ok = false;
        $lines[] = 'The comparison could not read the codes (see the error log).';
    } elseif ($compare['skipped'] === 'copy_unknown') {
        $lines[] = 'NOT compared: the cron line does not say which copy of the site it is. Add CUERCODE_SITE_COPY=live (or alpha, or beta) in front of it.';
    } elseif ($compare['skipped'] !== '' && $compare['skipped'] !== 'upgrade_waiting') {
        $lines[] = 'NOT compared: Go2My.Link is not ready (' . $compare['skipped'] . ').';
    } else {
        foreach ($compare['differences'] as $item) {
            $actual = '';
            if ($item['actual'] !== '') {
                $actual = ' (now: ' . dynamicQrDefangUrl((string) $item['actual']) . ')';
            }
            $lines[] = dynamicQrPrintedAddress($item['row']) . ' (account ' . (int) $item['row']['ownerUserID'] . '): ' . $item['difference'] . $actual;
        }
        $lines[] = 'Compared ' . (int) $compare['compared'] . ' code(s); ' . count($compare['differences']) . ' difference(s); ' . (int) $compare['notCompared'] . ' not compared.';
        if ($compare['resent'] > 0) {
            $lines[] = (int) $compare['resent'] . ' suspended code(s) were pointed elsewhere on Go2My.Link: their takedown is being sent again.';
        }
        if ($compare['stoppedEarly'] === true) {
            $lines[] = 'Stopped early: Go2My.Link could not answer ' . CUERCODE_MONITOR_COMPARE_STOP_AFTER . ' times in a row (' . (string) $compare['reason'] . ').';
        }
    }
    $sections[] = ['title' => 'Differences between CueRCode and Go2My.Link', 'lines' => $lines];

    $intro = ['What happened to dynamic QR codes in the last 24 hours. This email is sent every day, even when nothing happened: if it stops arriving, the daily job is not running.'];
    if (isset($runs['copy']) && $runs['copy'] === 'copy_unknown') {
        $intro[] = 'WARNING: the cron line does not declare CUERCODE_SITE_COPY, so nothing was sent to Google or Go2My.Link today.';
    }
    if (!$ok) {
        $intro[] = 'Part of this report could not be read from the database (see the error log).';
    }

    return [
        'ok'       => $ok,
        'subject'  => 'CueRCode daily dynamic-code report (' . gmdate('j M Y') . ')',
        'lines'    => $intro,
        'sections' => $sections,
    ];
}

/**
 * 📮 Who gets the daily email: every active Global Admin and the abuse
 *    mailbox, each once.
 *
 * @return array<int, string>
 */
function dynamicQrMonitorRecipients(): array
{
    $recipients = [];

    $admins = adminCliListAdmins();
    if (is_array($admins)) {
        foreach ($admins as $admin) {
            if ((string) $admin['status'] === 'active' && filter_var((string) $admin['email'], FILTER_VALIDATE_EMAIL) !== false) {
                $recipients[strtolower((string) $admin['email'])] = (string) $admin['email'];
            }
        }
    }

    $mailbox = dynamicQrAbuseMailbox();
    if ($mailbox !== '') {
        $recipients[strtolower($mailbox)] = $mailbox;
    }

    return array_values($recipients);
}

/**
 * ✉️ Queue the daily email, at most once per 20 hours (unless forced).
 *
 * @param array<string, mixed> $report From dynamicQrMonitorReport()
 * @param bool                 $force  Send even if one went in the last 20 hours
 * @param callable|null        $due    Stand-in for humanCheckStateLogDue() (tests)
 * @return array{sent: int, skipped: string}
 */
function dynamicQrMonitorSend(array $report, bool $force = false, ?callable $due = null): array
{
    if ($due === null) {
        $due = 'humanCheckStateLogDue';
    }

    $recipients = dynamicQrMonitorRecipients();
    if ($recipients === []) {
        return ['sent' => 0, 'skipped' => 'no_recipients'];
    }

    // ⏱️ One a day: the same bounded-log record the link-safety lines use.
    if (!$force && $due('log:dynamic_qr_daily_email', CUERCODE_MONITOR_EMAIL_GAP_SECONDS) !== true) {
        return ['sent' => 0, 'skipped' => 'sent_recently'];
    }

    $sent = 0;
    foreach ($recipients as $recipient) {
        if (dynamicQrQueueNotice($recipient, $report['subject'], $report['lines'], $report['sections'], dynamicQrSitePage('/admin/abuse/'), 'Open the abuse screen')) {
            $sent++;
        }
    }

    return ['sent' => $sent, 'skipped' => ''];
}
