<?php
/**
 * ==============================================================================
 * 📣 CueRCode - Abuse reports: how a reporter is counted (WP4a, #164)
 * ==============================================================================
 *
 * Path: _functions/fn_abuse_reports.php
 *
 * WHAT THIS IS (plain English):
 *   A dynamic code is suspended automatically when 3 DIFFERENT people report
 *   it within 24 hours (owner answer O-4, decision 0034; both numbers are
 *   Global Admin settings). tblAbuseReports (upgrade 0007) never stores a
 *   reporter's internet address. It stores this keyed hash of it instead,
 *   so reports can be counted per person without keeping the address:
 *     - an IPv6 address counts as its whole /64 network (one home or phone
 *       connection is normally given a /64), and an IPv4 address written in
 *       IPv6 form counts as that IPv4 address - the same rule as every other
 *       per-visitor limit (clientAddressCountingKey(), #177);
 *     - the hash is keyed with a key made from ENCRYPTION_KEY, so someone who
 *       only has the database cannot check it against a list of addresses.
 *       Without ENCRYPTION_KEY it refuses to work (review L4): a fallback key
 *       written in this file would be public, and every IPv4 hash could then
 *       be reversed in minutes.
 *   Decision 0046: 12 months (a Global Admin setting) after a report is
 *   dealt with, its email, description and this hash are removed by the
 *   cron clean-up (clearOldAbuseReportDetails() in fn_email.php).
 *   The reporter's email, when given, is stored with the site's existing
 *   encryptValue() (AES-256-GCM, HKDF, its own salt) - nothing new here.
 *
 *   WP4f (#169) adds the rest, below: the public report form's checks, the
 *   wider-network fingerprint, the counting, the automatic suspension, the
 *   emails, and what the admin screen /admin/abuse/ needs.
 *
 * HOW "3 DIFFERENT PEOPLE IN 24 HOURS" IS COUNTED (decision 0034; both
 * numbers are Global Admin settings), and why it is hard for ONE person with
 * many internet addresses to do it alone:
 *   1. only a report that PASSED the human check counts (or while the check
 *      is switched off site-wide); a report sent without it - no JavaScript,
 *      or the provider unreachable - is kept, emailed and read by a person,
 *      but never counted (countedForSuspension, upgrade 0008);
 *   2. the reports must come from that many different addresses (an IPv6
 *      address counts as its /64) AND that many different wider networks
 *      (an IPv4 /24, an IPv6 /48), so addresses from one provider's block
 *      count once;
 *   3. each address may send only a few reports an hour (abuse_report_ip_per_hour,
 *      5 by default, counted per /64 like every other form, before the human
 *      check is asked);
 *   4. reports an admin has dismissed (for example when lifting a
 *      suspension) never count again.
 *   Nothing is counted more aggressively than decision 0034 says: the number
 *   and the window are the owner's (3 in 24 hours by default).
 *
 * WHAT THE REPORTER IS TOLD: always the same thank-you, after at least the
 * same time, whether or not the code exists, whatever it points at, and
 * whether or not it was suspended - the form never reveals a code's
 * existence or its destination.
 *
 * Loading this file defines functions only.
 *
 * @package     CueRCode
 * @subpackage  DynamicQR
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.1.0
 * @since       2026-11-28 (WP4a, #164; WP4f, #169)
 * @see         _includes/sql/migrations/0007_dynamic_qr_go2mylink.php - tblAbuseReports
 * @see         _includes/sql/migrations/0008_link_safety_and_takedown.php - reporterNetworkHash, countedForSuspension
 * @see         _functions/fn_dynamic_qr_takedown.php - dynamicQrSuspend()
 * @see         public_html/report/index.php - the public form
 * @see         public_html/admin/abuse/index.php - the admin screen
 * @see         _functions/fn_client_ip.php - clientAddressCountingKey()
 * @see         _functions/fn_encryption.php - encryptValue()
 */

// ==============================================================================
// 🚫 Direct access protection
// ==============================================================================
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $abuseReportsScriptFilename = $_SERVER['SCRIPT_FILENAME'];
} else {
    $abuseReportsScriptFilename = '';
}
if (basename($abuseReportsScriptFilename) === basename(__FILE__)) {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
    }
    exit('Access denied.');
}
unset($abuseReportsScriptFilename);

// 🌍 The one counting rule for visitor addresses (pure on loading).
if (!function_exists('clientAddressCountingKey')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_client_ip.php';
}

/**
 * 🔑 The site's key for abuseReporterAddressHash(): made from ENCRYPTION_KEY
 *    with its own label, so it is different from every other keyed hash.
 *
 * @return string
 * @throws \RuntimeException When ENCRYPTION_KEY is missing or empty: no report
 *                           may be stored then (review L4)
 */
function abuseReporterHashKey(): string
{
    if (defined('ENCRYPTION_KEY') && is_string(ENCRYPTION_KEY) && ENCRYPTION_KEY !== '') {
        return hash_hmac('sha256', 'cuercode-abuse-reporter', ENCRYPTION_KEY);
    }

    throw new \RuntimeException('ENCRYPTION_KEY is not set, so an abuse report cannot be counted or stored.');
}

/**
 * 🔐 The keyed hash a reporter is counted under (tblAbuseReports.reporterAddressHash).
 *    PURE when $key is given.
 *
 * @param string $ip  getClientIP()
 * @param string $key '' = the site's own key
 * @return string 64 hex characters
 * @throws \RuntimeException When the site's own key is needed and ENCRYPTION_KEY is missing
 */
function abuseReporterAddressHash(string $ip, #[\SensitiveParameter] string $key = ''): string
{
    if ($key === '') {
        $key = abuseReporterHashKey();
    }

    return hash_hmac('sha256', clientAddressCountingKey($ip), $key);
}

// ==============================================================================
// 🔧 WP4f (#169): constants
// ==============================================================================

/** @var string The request-log endpoint name the per-address report limit counts under. */
if (!defined('CUERCODE_ABUSE_REPORT_ENDPOINT')) {
    define('CUERCODE_ABUSE_REPORT_ENDPOINT', 'abuse_report');
}

/** @var int Longest description a reporter may write. */
if (!defined('CUERCODE_ABUSE_DETAILS_MAX')) {
    define('CUERCODE_ABUSE_DETAILS_MAX', 2000);
}

/** @var int Microseconds every report answer takes at least (so timing never shows whether a code exists). */
if (!defined('CUERCODE_ABUSE_REPORT_MIN_MICROSECONDS')) {
    define('CUERCODE_ABUSE_REPORT_MIN_MICROSECONDS', 400000);
}

/** @var string tblSettings key - reports one visitor address may send in an hour (no seeded row). */
if (!defined('CUERCODE_SETTING_ABUSE_REPORT_IP_PER_HOUR')) {
    define('CUERCODE_SETTING_ABUSE_REPORT_IP_PER_HOUR', 'abuse_report_ip_per_hour');
}

/** @var string tblSettings key - report emails the abuse mailbox gets in an hour at most (no seeded row). */
if (!defined('CUERCODE_SETTING_ABUSE_REPORT_EMAILS_PER_HOUR')) {
    define('CUERCODE_SETTING_ABUSE_REPORT_EMAILS_PER_HOUR', 'abuse_report_emails_per_hour');
}

/** @var array<int, string> The words tblAbuseReports.status may hold. */
if (!defined('CUERCODE_ABUSE_STATUSES')) {
    define('CUERCODE_ABUSE_STATUSES', ['new', 'reviewing', 'actioned', 'dismissed']);
}

// ==============================================================================
// 🧠 PURE: the form, the fingerprints, the rule
// ==============================================================================

/**
 * 🏷️ The kinds of harm a reporter can choose, in plain words. PURE.
 *
 * @return array<string, string> word => words shown
 */
function abuseReportCategories(): array
{
    return [
        'phishing' => 'It asks for passwords, bank or card details (phishing)',
        'malware'  => 'It downloads or installs harmful software (malware)',
        'scam'     => 'It is a scam or a fraud',
        'illegal'  => 'It shows illegal content',
        'other'    => 'Something else',
    ];
}

/**
 * 🌐 The wider network a reporter's address belongs to: an IPv4 address's
 *    /24 ("198.51.100.0/24"), an IPv6 address's /48 ("2001:db8:1::/48").
 *    Addresses from one provider's block count as one network. PURE.
 *
 * @param string $ip getClientIP()
 * @return string
 */
function abuseReporterNetworkKey(string $ip): string
{
    $counted = clientAddressCountingKey($ip);

    if (filter_var($counted, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        $parts = explode('.', $counted);
        return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
    }

    $address = $counted;
    if (str_ends_with($address, '/64')) {
        $address = substr($address, 0, -3);
    }
    if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        $packed = inet_pton($address);
        if ($packed !== false && strlen($packed) === 16) {
            $network = inet_ntop(substr($packed, 0, 6) . str_repeat("\x00", 10));
            if ($network !== false) {
                return $network . '/48';
            }
        }
    }

    return 'other:' . $counted;
}

/**
 * 🔐 The keyed hash a reporter's wider network is counted under
 *    (tblAbuseReports.reporterNetworkHash). PURE when $key is given.
 *
 * @param string $ip
 * @param string $key '' = the site's own key
 * @return string 64 hex characters
 */
function abuseReporterNetworkHash(string $ip, #[\SensitiveParameter] string $key = ''): string
{
    if ($key === '') {
        $key = abuseReporterHashKey();
    }

    return hash_hmac('sha256', 'network|' . abuseReporterNetworkKey($ip), $key);
}

/**
 * 🔤 The short code in what a reporter typed: the code alone ("Ab3dE9x"),
 *    or the printed address ("QRmy.Link/Ab3dE9x", "https://alpha.qrmy.link/Ab3dE9x").
 *    '' when it is neither. PURE.
 *
 * @param string $typed
 * @return string
 */
function abuseReportCodeFromText(string $typed): string
{
    $typed = trim($typed);
    if ($typed === '' || strlen($typed) > 300) {
        return '';
    }

    if (preg_match('/^[A-Za-z0-9_-]{3,50}$/D', $typed) === 1) {
        if (function_exists('go2mylinkCodeIsAllowed') && !go2mylinkCodeIsAllowed($typed)) {
            return '';
        }
        return $typed;
    }

    $address = $typed;
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $address) !== 1) {
        $address = 'https://' . $address;
    }
    $host = parse_url($address, PHP_URL_HOST);
    $path = parse_url($address, PHP_URL_PATH);
    if (!is_string($host) || !is_string($path)) {
        return '';
    }
    $host = strtolower(rtrim($host, '.'));
    if ($host !== 'qrmy.link' && !str_ends_with($host, '.qrmy.link')) {
        return '';
    }

    $code = trim($path, '/');

    return abuseReportCodeFromText($code);
}

/**
 * ✅ Check what a reporter sent. Shapes only - nothing here looks anything up,
 *    so an answer never depends on whether a code exists. PURE.
 *
 * @param array<string, mixed> $post $_POST
 * @return array{ok: bool, errors: array<string, string>, code: string, category: string, details: string, email: string}
 */
function abuseReportCheckInput(array $post): array
{
    $text = static function (string $name) use ($post): string {
        if (isset($post[$name]) && is_string($post[$name])) {
            return $post[$name];
        }
        return '';
    };

    $errors = [];

    $code = abuseReportCodeFromText($text('code'));
    if ($code === '') {
        $errors['code'] = 'Type the address printed with the QR code, for example QRmy.Link/Ab3dE9x, or just the code after the slash.';
    }

    $category = $text('category');
    if (!array_key_exists($category, abuseReportCategories())) {
        $errors['category'] = 'Choose what is wrong with the page the code leads to.';
        $category = '';
    }

    $details = trim(str_replace("\r\n", "\n", $text('details')));
    if (preg_match('//u', $details) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $details) === 1) {
        $errors['details'] = 'The description contains characters that are not plain text.';
        $details = '';
    } elseif (mb_strlen($details, 'UTF-8') > CUERCODE_ABUSE_DETAILS_MAX) {
        $errors['details'] = 'Please keep the description to ' . number_format(CUERCODE_ABUSE_DETAILS_MAX) . ' characters.';
    } elseif ($category === 'other' && mb_strlen($details, 'UTF-8') < 10) {
        $errors['details'] = 'Please describe what is wrong (at least a few words) when you choose "Something else".';
    }

    $email = trim($text('email'));
    if ($email !== '' && (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
        $errors['email'] = 'Type a full email address, or leave it empty.';
    }

    return ['ok' => ($errors === []), 'errors' => $errors, 'code' => $code, 'category' => $category, 'details' => $details, 'email' => $email];
}

/**
 * 🧮 Do these reports suspend the code? Only when at least $threshold
 *    different addresses AND $threshold different wider networks sent a
 *    counted report within the window. PURE.
 *
 * @param int $addresses Different reporter addresses (IPv6 as its /64)
 * @param int $networks  Different wider networks (IPv4 /24, IPv6 /48)
 * @param int $threshold abuse_auto_suspend_reports (3 by default)
 * @return bool
 */
function abuseReportShouldSuspend(int $addresses, int $networks, int $threshold): bool
{
    if ($threshold < 1) {
        return false;
    }

    return $addresses >= $threshold && $networks >= $threshold;
}

/**
 * ⚙️ A whole-number setting within its bounds, otherwise its default.
 *
 * @param callable $configGetter
 * @param string   $key
 * @param int      $default
 * @param int      $min
 * @param int      $max
 * @return int
 */
function abuseReportNumberSetting(callable $configGetter, string $key, int $default, int $min, int $max): int
{
    $raw = trim(humanCheckText($configGetter($key, (string) $default)));
    if (preg_match('/^[0-9]{1,6}$/D', $raw) === 1) {
        $number = (int) $raw;
        if ($number >= $min && $number <= $max) {
            return $number;
        }
    }

    return $default;
}

/**
 * ⚙️ The abuse settings (Global Admin settings, /admin/settings/, "Abuse
 *    reports and takedowns"), each within its bounds or its default.
 *
 * @param callable|null $configGetter Stand-in for getConfig() (tests)
 * @return array{autoSuspendReports: int, windowHours: int, ipPerHour: int, emailsPerHour: int}
 */
function abuseReportSettings(?callable $configGetter = null): array
{
    if ($configGetter === null) {
        $configGetter = 'humanCheckSiteConfig';
    }

    return [
        'autoSuspendReports' => abuseReportNumberSetting($configGetter, 'abuse_auto_suspend_reports', 3, 1, 100),
        'windowHours'        => abuseReportNumberSetting($configGetter, 'abuse_auto_suspend_window_hours', 24, 1, 720),
        'ipPerHour'          => abuseReportNumberSetting($configGetter, CUERCODE_SETTING_ABUSE_REPORT_IP_PER_HOUR, 5, 1, 100),
        'emailsPerHour'      => abuseReportNumberSetting($configGetter, CUERCODE_SETTING_ABUSE_REPORT_EMAILS_PER_HOUR, 30, 1, 1000),
    ];
}

// ==============================================================================
// 🗄️ Receiving a report
// ==============================================================================

/**
 * ⏱️ Is this visitor address within its hourly number of reports? Recorded
 *    THEN counted, under a lock (the helper every emailed-link form uses), so
 *    requests at the same moment are counted exactly; an IPv6 address counts
 *    as its /64.
 *
 * @param string $ip
 * @param int    $limit
 * @return bool
 */
function abuseReportRateAllowed(string $ip, int $limit): bool
{
    if (!function_exists('authRecordThenCountLinkRequest')) {
        return false;
    }
    if (!function_exists('recordAPIRequest')) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_api_auth.php';
    }

    return authRecordThenCountLinkRequest($ip, CUERCODE_ABUSE_REPORT_ENDPOINT) <= $limit;
}

/**
 * 📣 Store one report, count it, suspend the code when the rule says so, and
 *    tell the abuse mailbox and (once per window) the code's owner. The
 *    caller always gives the reporter the same answer, whatever this returns.
 *
 * @param array{code: string, category: string, details: string, email: string} $report From abuseReportCheckInput()
 * @param array<string, mixed> $context 'ip' (getClientIP()), 'counted' (passed the human check, or the
 *                                      check is off), 'settings' (abuseReportSettings(), tests),
 *                                      and 'takedown' (options for dynamicQrSuspend(), tests)
 * @return array{stored: bool, reportID: int, known: bool, suspended: bool}
 */
function abuseReportSubmit(array $report, #[\SensitiveParameter] array $context): array
{
    $result = ['stored' => false, 'reportID' => 0, 'known' => false, 'suspended' => false];

    if (!function_exists('dynamicQrSuspend')) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_dynamic_qr_takedown.php';
    }
    if (!function_exists('dbQuery') || !dynamicQrUpgrade0008Ready()) {
        if (function_exists('logError')) {
            logError('error', 'An abuse report could not be stored: database upgrades are waiting', '');
        }
        return $result;
    }

    $ip = '';
    if (isset($context['ip']) && is_string($context['ip'])) {
        $ip = $context['ip'];
    }
    $counted = (isset($context['counted']) && $context['counted'] === true);
    $settings = abuseReportSettings();
    if (isset($context['settings']) && is_array($context['settings'])) {
        $settings = array_merge($settings, $context['settings']);
    }

    // 🔎 Which code (if any). A short code is unique without regard to capitals.
    $row = null;
    try {
        $stmt = dbQuery('SELECT `redirectID`, `redirectUUID`, `shortCode`, `ownerUserID`, `suspendedAt`, `destinationURL` FROM `tblQRRedirects` WHERE `shortCode` = ? LIMIT 1', 's', [$report['code']]);
        $found = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (is_array($found)) {
            $row = $found;
        }
    } catch (\Throwable $e) {
        $row = null;
    }
    $result['known'] = ($row !== null);

    // 💾 The report: the email encrypted, the address only as two keyed hashes.
    try {
        $encryptedEmail = null;
        if ($report['email'] !== '') {
            $encryptedEmail = encryptValue($report['email']);
        }
        $details = null;
        if ($report['details'] !== '') {
            $details = $report['details'];
        }
        $redirectId = null;
        if ($row !== null) {
            $redirectId = (int) $row['redirectID'];
        }
        $countedFlag = 0;
        if ($counted) {
            $countedFlag = 1;
        }

        $insert = dbQuery(
            'INSERT INTO `tblAbuseReports` (`redirectID`, `shortCode`, `category`, `details`, `reporterEmailEncrypted`, `reporterAddressHash`, `reporterNetworkHash`, `countedForSuspension`, `status`, `createdAt`) '
            . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'new', UTC_TIMESTAMP())",
            'issssssi',
            [$redirectId, substr($report['code'], 0, 50), $report['category'], $details, $encryptedEmail, abuseReporterAddressHash($ip), abuseReporterNetworkHash($ip), $countedFlag]
        );
        $result['reportID'] = (int) $insert->insert_id;
        $insert->close();
        $result['stored'] = true;
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'An abuse report could not be stored', get_class($e));
        }
        return $result;
    }

    // 🧮 Counting, and the automatic suspension (decision 0034).
    $people = 0;
    $networks = 0;
    $reportsInWindow = 0;
    if ($row !== null) {
        try {
            $countStmt = dbQuery(
                'SELECT COUNT(*) AS `reports`, '
                . 'COUNT(DISTINCT CASE WHEN `countedForSuspension` = 1 THEN `reporterAddressHash` END) AS `people`, '
                . 'COUNT(DISTINCT CASE WHEN `countedForSuspension` = 1 THEN `reporterNetworkHash` END) AS `networks` '
                . "FROM `tblAbuseReports` WHERE `redirectID` = ? AND `status` <> 'dismissed' AND `createdAt` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? HOUR)",
                'ii',
                [(int) $row['redirectID'], (int) $settings['windowHours']]
            );
            $counts = $countStmt->get_result()->fetch_assoc();
            $countStmt->close();
            $reportsInWindow = (int) $counts['reports'];
            $people          = (int) $counts['people'];
            $networks        = (int) $counts['networks'];
        } catch (\Throwable $e) {
            if (function_exists('logError')) {
                logError('error', 'Abuse reports could not be counted', get_class($e));
            }
        }

        if ($row['suspendedAt'] === null && abuseReportShouldSuspend($people, $networks, (int) $settings['autoSuspendReports'])) {
            // 👥 "Different people": the smaller of the two counts.
            $people = min($people, $networks);
            if (!function_exists('dynamicQrSuspend')) {
                require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_dynamic_qr_takedown.php';
            }
            $takedownOptions = [];
            if (isset($context['takedown']) && is_array($context['takedown'])) {
                $takedownOptions = $context['takedown'];
            }
            // ⏱️ Go2My.Link is told by the every-minute cron job, not here,
            //    so this answer takes the same time as any other report.
            $suspension = dynamicQrSuspend(
                (int) $row['redirectID'],
                ['type' => 'system', 'label' => 'abuse reports'],
                'Suspended automatically: ' . $people . ' reports from different people within ' . (int) $settings['windowHours'] . ' hours (decision 0034).',
                array_merge($takedownOptions, ['source' => 'reports', 'facts' => ['reports' => $people, 'hours' => (int) $settings['windowHours']], 'applyNow' => false])
            );
            $result['suspended'] = ($suspension['outcome'] === 'suspended');
        }
    }

    abuseReportNotify($report, $row, $result, $counted, $reportsInWindow, $settings);

    return $result;
}

/**
 * ✉️ The emails about one report: to the abuse mailbox (at most
 *    abuse_report_emails_per_hour an hour, so a flood cannot bury it; every
 *    report is still on /admin/abuse/ and in the daily email), and to the
 *    code's owner for the FIRST report in the window only (never who sent it
 *    or what they wrote). Nothing is sent to the reporter, so the form can
 *    never be used to send email to someone else.
 *
 * @param array      $report
 * @param array|null $row             The code, when one matched
 * @param array      $result          From abuseReportSubmit()
 * @param bool       $counted
 * @param int        $reportsInWindow Reports about the code in the window, this one included
 * @param array      $settings
 * @return void
 */
function abuseReportNotify(array $report, ?array $row, array $result, bool $counted, int $reportsInWindow, array $settings): void
{
    if (!function_exists('dynamicQrQueueNotice')) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_dynamic_qr_takedown.php';
    }

    $categories = abuseReportCategories();
    $categoryText = $categories[$report['category']];

    // 📮 The abuse mailbox, within its hourly number.
    $recent = 0;
    try {
        $recentStmt = dbQuery('SELECT COUNT(*) AS `recent` FROM `tblAbuseReports` WHERE `createdAt` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)', '', []);
        $recentRow  = $recentStmt->get_result()->fetch_assoc();
        $recentStmt->close();
        $recent = (int) $recentRow['recent'];
    } catch (\Throwable $e) {
        $recent = PHP_INT_MAX;
    }

    $mailbox = dynamicQrAbuseMailbox();
    if ($mailbox !== '' && $recent <= (int) $settings['emailsPerHour']) {
        $lines = ['A report about the code "' . $report['code'] . '": ' . $categoryText . '.'];
        if ($row !== null) {
            $lines[] = 'It matches dynamic code ' . dynamicQrPrintedAddress($row) . ' (account ' . (int) $row['ownerUserID'] . '). Destination (written so it is not a link): ' . dynamicQrDefangUrl((string) $row['destinationURL']) . '.';
        } else {
            $lines[] = 'No dynamic code here has that code (it may be mistyped, or not one of ours).';
        }
        if ($counted) {
            $lines[] = 'The reporter passed the human check, so the report counts towards automatic suspension.';
        } else {
            $lines[] = 'The reporter did NOT pass the human check (no JavaScript, or the check could not be reached), so the report is not counted towards automatic suspension.';
        }
        if ($result['suspended']) {
            $lines[] = 'With this report the code reached the limit for automatic suspension and has been suspended.';
        }
        if ($report['details'] !== '') {
            $lines[] = 'What they wrote: ' . $report['details'];
        }
        if ($report['email'] !== '') {
            $lines[] = 'Their email address, if you need to reply: ' . $report['email'];
        } else {
            $lines[] = 'They gave no email address.';
        }
        $lines[] = 'Deal with it on the admin screen; its personal details are removed 12 months (an admin setting) after it is dealt with (decision 0046).';
        dynamicQrQueueNotice($mailbox, 'CueRCode: a report about a dynamic code (#' . (int) $result['reportID'] . ')', $lines, [], dynamicQrSitePage('/admin/abuse/'), 'Open the abuse screen');
    }

    // 👤 The owner, for the first report in the window only.
    if ($row !== null && $reportsInWindow === 1 && $row['suspendedAt'] === null && !$result['suspended']) {
        $ownerLines = [
            'Someone reported your dynamic QR code ' . dynamicQrPrintedAddress($row) . ' as: ' . $categoryText . '.',
            'We look at every report. ' . dynamicQrTakedownPromise(),
            'If ' . (int) $settings['autoSuspendReports'] . ' different people report it within ' . (int) $settings['windowHours'] . ' hours, it is suspended automatically until we have looked.',
            'If your code leads somewhere it should not, change its destination now. If you think the report is a mistake, there is nothing you need to do.',
        ];
        dynamicQrQueueNotice(dynamicQrAccountEmail((int) $row['ownerUserID']), 'Your CueRCode dynamic QR code was reported', $ownerLines);
    }
}

// ==============================================================================
// 🧑‍💼 The admin screen (/admin/abuse/)
// ==============================================================================

/**
 * 📋 Reports for the admin screen, newest first: those still open ("new" or
 *    "reviewing"), or those dealt with. The reporter's email is decrypted for
 *    the admin (it may be needed to reply); a removed one is empty.
 *
 * @param bool $open  True = still open; false = dealt with
 * @param int  $limit
 * @return array<int, array<string, mixed>>|null Null when it cannot be read
 */
function abuseReportList(bool $open, int $limit): ?array
{
    $statusSql = "`a`.`status` IN ('new', 'reviewing')";
    if (!$open) {
        $statusSql = "`a`.`status` IN ('actioned', 'dismissed')";
    }

    try {
        $stmt = dbQuery(
            'SELECT `a`.`reportID`, `a`.`redirectID`, `a`.`shortCode`, `a`.`category`, `a`.`details`, `a`.`reporterEmailEncrypted`, `a`.`countedForSuspension`, '
            . '`a`.`status`, `a`.`handledAt`, `a`.`personalDetailsRemovedAt`, `a`.`createdAt`, `h`.`email` AS `handledByEmail`, '
            . '`r`.`redirectUUID`, `r`.`shortCode` AS `matchedCode`, `r`.`ownerUserID`, `r`.`destinationURL`, `r`.`suspendedAt` '
            . 'FROM `tblAbuseReports` AS `a` LEFT JOIN `tblQRRedirects` AS `r` ON `r`.`redirectID` = `a`.`redirectID` '
            . 'LEFT JOIN `tblUsers` AS `h` ON `h`.`userID` = `a`.`handledByUserID` '
            . 'WHERE ' . $statusSql . ' ORDER BY `a`.`createdAt` DESC, `a`.`reportID` DESC LIMIT ?',
            'i',
            [$limit]
        );
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (\Throwable $e) {
        return null;
    }

    foreach ($rows as $index => $row) {
        $email = '';
        if ($row['reporterEmailEncrypted'] !== null && function_exists('decryptValue')) {
            try {
                $email = decryptValue((string) $row['reporterEmailEncrypted']);
            } catch (\Throwable $e) {
                $email = '';
            }
        }
        unset($rows[$index]['reporterEmailEncrypted']);
        $rows[$index]['reporterEmail'] = $email;
    }

    return $rows;
}

/**
 * 🏷️ Set a report's status (an admin, with a fresh password, checked by the
 *    page). "actioned" and "dismissed" set the handled date (decision 0046:
 *    the 12 months start then); "new" and "reviewing" clear it. Written to
 *    the admin change log in the same transaction.
 *
 * @param array<string, mixed> $admin    From requireGlobalAdminPage()
 * @param int                  $reportId
 * @param string               $status   new, reviewing, actioned or dismissed
 * @return array{ok: bool, message: string}
 */
function abuseReportSetStatus(array $admin, int $reportId, string $status): array
{
    if (!in_array($status, CUERCODE_ABUSE_STATUSES, true)) {
        return ['ok' => false, 'message' => 'That status was not recognised, so nothing was changed.'];
    }
    if (!adminTableExists('tblAbuseReports') || !adminTableExists('tblAdminAuditLog')) {
        return ['ok' => false, 'message' => 'Database upgrades are waiting, so nothing was changed.'];
    }

    $adminUserId = 0;
    if (isset($admin['userID']) && is_int($admin['userID'])) {
        $adminUserId = $admin['userID'];
    }
    if (adminAccessDecision(['userID' => $adminUserId], adminLoadAccessRow($adminUserId)) !== 'admin') {
        return ['ok' => false, 'message' => 'Only a Global Admin can change a report, so nothing was changed.'];
    }

    try {
        getDB()->begin_transaction();
        $oldStmt = dbQuery('SELECT `status` FROM `tblAbuseReports` WHERE `reportID` = ? FOR UPDATE', 'i', [$reportId]);
        $old = $oldStmt->get_result()->fetch_assoc();
        $oldStmt->close();
        if (!is_array($old)) {
            adminRollbackQuietly(getDB());
            return ['ok' => false, 'message' => 'That report was not found.'];
        }
        if ((string) $old['status'] === $status) {
            adminRollbackQuietly(getDB());
            return ['ok' => true, 'message' => 'Nothing was different, so nothing was changed.'];
        }

        if ($status === 'actioned' || $status === 'dismissed') {
            $update = dbQuery('UPDATE `tblAbuseReports` SET `status` = ?, `handledByUserID` = ?, `handledAt` = UTC_TIMESTAMP() WHERE `reportID` = ?', 'sii', [$status, $adminUserId, $reportId]);
        } else {
            $update = dbQuery('UPDATE `tblAbuseReports` SET `status` = ?, `handledByUserID` = NULL, `handledAt` = NULL WHERE `reportID` = ?', 'si', [$status, $reportId]);
        }
        $update->close();

        if (!adminAuditWrite(adminAuditEntryForAdmin($admin, 'abuse.report_update', 'abuse_report', (string) $reportId, 'success', ['status' => ['old' => (string) $old['status'], 'new' => $status]]))) {
            adminRollbackQuietly(getDB());
            return ['ok' => false, 'message' => 'This could not be recorded in the admin change log, so nothing was changed.'];
        }
        getDB()->commit();
    } catch (\Throwable $e) {
        adminRollbackQuietly(getDB());
        if (function_exists('logError')) {
            logError('error', 'An abuse report could not be changed', get_class($e));
        }
        return ['ok' => false, 'message' => 'Not changed. Please try again.'];
    }

    return ['ok' => true, 'message' => 'Report #' . $reportId . ' is now "' . $status . '".'];
}

/**
 * 🚫 Every suspended code, newest suspension first, for the admin screen:
 *    who owns it, when and by whom it was suspended, why, and whether
 *    Go2My.Link has confirmed the takedown yet.
 *
 * @param int $limit
 * @return array<int, array<string, mixed>>|null Null when it cannot be read
 */
function abuseSuspendedList(int $limit): ?array
{
    try {
        $stmt = dbQuery(
            'SELECT `r`.`redirectID`, `r`.`redirectUUID`, `r`.`shortCode`, `r`.`ownerUserID`, `r`.`destinationURL`, `r`.`isActive`, `r`.`disabledReason`, '
            . '`r`.`suspendedAt`, `r`.`suspensionNote`, `r`.`suspensionAppliedAt`, `r`.`suspendedByUserID`, `s`.`email` AS `suspendedByEmail`, `o`.`email` AS `ownerEmail` '
            . 'FROM `tblQRRedirects` AS `r` LEFT JOIN `tblUsers` AS `s` ON `s`.`userID` = `r`.`suspendedByUserID` '
            . 'LEFT JOIN `tblUsers` AS `o` ON `o`.`userID` = `r`.`ownerUserID` '
            . 'WHERE `r`.`suspendedAt` IS NOT NULL ORDER BY `r`.`suspendedAt` DESC, `r`.`redirectID` DESC LIMIT ?',
            'i',
            [$limit]
        );
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (\Throwable $e) {
        return null;
    }

    return $rows;
}

/**
 * 🔎 The code an admin typed (the code, or the printed address), or null.
 *
 * @param string $typed
 * @return int|null tblQRRedirects.redirectID
 */
function abuseFindRedirectIdByCode(string $typed): ?int
{
    $code = abuseReportCodeFromText($typed);
    if ($code === '') {
        return null;
    }

    try {
        $stmt = dbQuery('SELECT `redirectID` FROM `tblQRRedirects` WHERE `shortCode` = ? LIMIT 1', 's', [$code]);
        $row  = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        return null;
    }

    if (!is_array($row)) {
        return null;
    }

    return (int) $row['redirectID'];
}
