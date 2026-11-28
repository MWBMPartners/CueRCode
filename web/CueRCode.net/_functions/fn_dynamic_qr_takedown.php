<?php
/**
 * ==============================================================================
 * 🚫 CueRCode - Taking a dynamic code down, and the daily link-safety re-check (WP4e, #168)
 * ==============================================================================
 *
 * Path: _functions/fn_dynamic_qr_takedown.php
 *
 * WHAT THIS IS (plain English):
 *   A "takedown" (suspension) stops a dynamic QR code from sending people to a
 *   harmful page. Lead choice L2: CueRCode points the code's Go2My.Link link
 *   at its own neutral /unavailable/ page (Go2My.Link's switched-off page
 *   says "disabled by its owner", and its API cannot switch a link back on).
 *
 *   THE ORDER, so a takedown can never be lost:
 *     1. HERE FIRST: the row is marked suspended (suspendedAt, who, why) in
 *        one transaction with its row in the admin change log - an action
 *        never exists without its record. From that moment the owner can
 *        change nothing (409 REDIRECT_SUSPENDED) and no picture is drawn.
 *        suspensionAppliedAt is left empty: "Go2My.Link not told yet".
 *     2. THEN Go2My.Link is told (under the owner's per-person lock, so an
 *        owner's change cannot be sent at the same moment). Only a confirmed
 *        answer - or a look-up showing the link points at the neutral page -
 *        fills suspensionAppliedAt.
 *     3. Anything left empty is sent again by the email-queue cron job every
 *        minute (dynamicQrApplyPendingSuspensions()): Go2My.Link was
 *        unreachable, the owner's lock was busy, or an owner's change that
 *        was already on its way landed after the takedown (the change flow
 *        notices that and sends the takedown again itself, rule 12 in
 *        fn_api_redirects.php).
 *   A cron run only calls Go2My.Link or Google when it declares which copy of
 *   the site it is (CUERCODE_SITE_COPY, #181).
 *
 *   The owner and the abuse mailbox (abuse@cuercode.net, decision 0034) are
 *   emailed about every suspension. Only an admin can lift one (WP4f, #169).
 *
 *   THE DAILY RE-CHECK (dynamicQrRecheckSafety(), run by
 *   _includes/cron/dynamic_qr_daily.php): every code in use is checked again
 *   through the link-safety hook, oldest check first, a limited number per
 *   run. A destination now listed as unsafe is SUSPENDED automatically (the
 *   must-list item b, "also on a link-safety hit"). If the provider cannot
 *   answer three times in a row the run stops early (it is down) and says so.
 *
 * House rules: full multi-line PHP; MySQLi prepared statements through
 * dbQuery(); every SQL name back-quoted. Loading defines functions only.
 *
 * @package     CueRCode
 * @subpackage  DynamicQR
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (WP4e, #168)
 * @see         _functions/fn_api_redirects.php - the create and change flows, the per-person lock
 * @see         _functions/fn_url_reputation.php - the link-safety check
 * @see         _includes/sql/migrations/0008_link_safety_and_takedown.php - suspensionAppliedAt, safetyVerdict
 * @see         docs/decisions/0029, 0034, 0035
 */

// ==============================================================================
// 🚫 Direct access protection
// ==============================================================================
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $takedownScriptFilename = $_SERVER['SCRIPT_FILENAME'];
} else {
    $takedownScriptFilename = '';
}
if (basename($takedownScriptFilename) === basename(__FILE__)) {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
    }
    exit('Access denied.');
}
unset($takedownScriptFilename);

// ==============================================================================
// 📦 What this file builds on (each is pure on loading)
// ==============================================================================
if (!function_exists('dynamicQrApplyChange')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_api_redirects.php';
}
// 🧾 The admin change log (adminAuditWrite()) and the access check.
if (!function_exists('adminAuditWrite')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_admin.php';
}

// ==============================================================================
// 🔧 Constants
// ==============================================================================

/** @var int Most waiting takedowns sent to Go2My.Link in one cron run (bounds the calls). */
if (!defined('CUERCODE_TAKEDOWN_REAPPLY_BATCH')) {
    define('CUERCODE_TAKEDOWN_REAPPLY_BATCH', 25);
}

/** @var int Most codes the daily re-check asks about in one run. */
if (!defined('CUERCODE_TAKEDOWN_RECHECK_BATCH')) {
    define('CUERCODE_TAKEDOWN_RECHECK_BATCH', 200);
}

/** @var int A code is due for the daily re-check when its last check is older than this many hours. */
if (!defined('CUERCODE_TAKEDOWN_RECHECK_AFTER_HOURS')) {
    define('CUERCODE_TAKEDOWN_RECHECK_AFTER_HOURS', 20);
}

/** @var int The re-check stops early after this many unanswered checks in a row (the provider is down). */
if (!defined('CUERCODE_TAKEDOWN_RECHECK_STOP_AFTER')) {
    define('CUERCODE_TAKEDOWN_RECHECK_STOP_AFTER', 3);
}

/** @var int Longest suspension note kept (tblQRRedirects.suspensionNote is VARCHAR(1000)). */
if (!defined('CUERCODE_TAKEDOWN_NOTE_MAX')) {
    define('CUERCODE_TAKEDOWN_NOTE_MAX', 1000);
}

/** @var string The columns a takedown reads (upgrade 0008's included). */
if (!defined('CUERCODE_TAKEDOWN_ROW_COLUMNS')) {
    define('CUERCODE_TAKEDOWN_ROW_COLUMNS', CUERCODE_DYNAMIC_ROW_COLUMNS . ', `suspensionNote`, `suspensionAppliedAt`, `safetyVerdict`, `safetyCheckedAt`');
}

// ==============================================================================
// 🧠 PURE: words for the notices
// ==============================================================================

/**
 * 🧯 An address written so a mail program does not turn it into a link
 *    ("defanged"): https[:]//example[.]com/path. For addresses that may be
 *    harmful, in emails to admins. PURE.
 *
 * @param string $url
 * @return string
 */
function dynamicQrDefangUrl(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST);
    $text = str_replace('://', '[:]//', $url);
    if (is_string($host) && $host !== '') {
        $text = str_replace($host, str_replace('.', '[.]', $host), $text);
    }

    return $text;
}

/**
 * ☣️ Web Risk's list names in plain words. PURE.
 *
 * @param array<int, string> $threatTypes
 * @return string
 */
function dynamicQrThreatWords(array $threatTypes): string
{
    $words = [
        'MALWARE'                              => 'malware',
        'SOCIAL_ENGINEERING'                   => 'phishing or another trick',
        'SOCIAL_ENGINEERING_EXTENDED_COVERAGE' => 'phishing or another trick',
        'UNWANTED_SOFTWARE'                    => 'unwanted software',
    ];

    $out = [];
    foreach ($threatTypes as $type) {
        $word = 'a harmful page';
        if (isset($words[$type])) {
            $word = $words[$type];
        }
        if (!in_array($word, $out, true)) {
            $out[] = $word;
        }
    }

    if ($out === []) {
        return 'a harmful page';
    }

    return implode(', ', $out);
}

/**
 * 📣 Why a code was suspended, in plain words, for both notices. PURE.
 *
 * @param string               $source link_safety, reports or admin
 * @param array<string, mixed> $facts  provider, threatTypes (link_safety); reports, hours (reports)
 * @return string
 */
function dynamicQrSuspensionWhy(string $source, array $facts): string
{
    if ($source === 'link_safety') {
        $provider = 'A link-safety service';
        if (isset($facts['providerName']) && is_string($facts['providerName']) && $facts['providerName'] !== '') {
            $provider = $facts['providerName'];
        }
        $threats = [];
        if (isset($facts['threatTypes']) && is_array($facts['threatTypes'])) {
            $threats = $facts['threatTypes'];
        }
        return $provider . ' lists its destination as harmful (' . dynamicQrThreatWords($threats) . '). This check runs every day on every dynamic code.';
    }

    if ($source === 'reports') {
        $reports = 0;
        if (isset($facts['reports']) && is_int($facts['reports'])) {
            $reports = $facts['reports'];
        }
        $hours = 24;
        if (isset($facts['hours']) && is_int($facts['hours'])) {
            $hours = $facts['hours'];
        }
        return $reports . ' different people reported it as harmful within ' . $hours . ' hours, so it was suspended automatically until a person has looked at the reports.';
    }

    return 'An administrator suspended it after looking into it.';
}

/**
 * ✉️ The two suspension notices: to the code's owner and to the abuse
 *    mailbox. The owner's never shows who reported it. PURE.
 *
 * @param array<string, mixed> $row     The code (shortCode, ownerUserID, destinationURL, redirectUUID)
 * @param string               $shortUrl The printed address, for example https://QRmy.Link/Ab3dE9x
 * @param string               $why     dynamicQrSuspensionWhy()
 * @param string               $by      'automatically' or 'by <admin email>'
 * @param string               $promise The takedown promise setting
 * @param string               $mailbox The abuse mailbox setting
 * @return array{owner: array{subject: string, lines: array<int, string>}, abuse: array{subject: string, lines: array<int, string>}}
 */
function dynamicQrSuspensionNotices(array $row, string $shortUrl, string $why, string $by, string $promise, string $mailbox): array
{
    $ownerLines = [
        'Your dynamic QR code ' . $shortUrl . ' has been suspended. People who scan it now see a neutral page instead of your destination.',
        'Why: ' . $why,
        'While it is suspended you cannot change it or switch it back on yourself.',
    ];
    if ($mailbox !== '') {
        $ownerLines[] = 'If you think this is a mistake, email ' . $mailbox . '. ' . $promise;
    }

    $abuseLines = [
        'Dynamic code ' . $shortUrl . ' (account ' . (int) $row['ownerUserID'] . ', code id ' . (string) $row['redirectUUID'] . ') was suspended ' . $by . '.',
        'Why: ' . $why,
        'Destination (written so it is not a link - do not open it on an unprotected computer): ' . dynamicQrDefangUrl((string) $row['destinationURL']),
        'The owner has been told. Only an admin can lift a suspension, with a written reason.',
    ];

    return [
        'owner' => ['subject' => 'Your CueRCode dynamic QR code has been suspended', 'lines' => $ownerLines],
        'abuse' => ['subject' => 'CueRCode: dynamic code ' . (string) $row['shortCode'] . ' suspended', 'lines' => $abuseLines],
    ];
}

// ==============================================================================
// ⚙️ Settings and small reads
// ==============================================================================

/**
 * 📮 The abuse mailbox (decision 0034), or '' when the setting is not a
 *    usable address.
 *
 * @return string
 */
function dynamicQrAbuseMailbox(): string
{
    $mailbox = trim(humanCheckText(humanCheckSiteConfig('abuse_mailbox', 'abuse@cuercode.net')));
    if ($mailbox === '' || filter_var($mailbox, FILTER_VALIDATE_EMAIL) === false) {
        return '';
    }

    return $mailbox;
}

/**
 * 🤝 The takedown promise shown with every notice (decision 0034).
 *
 * @return string
 */
function dynamicQrTakedownPromise(): string
{
    $promise = trim(humanCheckText(humanCheckSiteConfig('abuse_takedown_promise', 'We act on reports within one working day.')));
    if ($promise === '') {
        return 'We act on reports within one working day.';
    }

    return $promise;
}

/**
 * 🔗 The printed short address of a code (dynamic_redirect_base_url + code).
 *
 * @param array<string, mixed> $row
 * @return string
 */
function dynamicQrPrintedAddress(array $row): string
{
    $base = trim(humanCheckText(humanCheckSiteConfig('dynamic_redirect_base_url', 'https://QRmy.Link')));
    if ($base === '') {
        $base = 'https://QRmy.Link';
    }

    return rtrim($base, '/') . '/' . (string) $row['shortCode'];
}

/**
 * 👤 An account's email address, or '' when it cannot be read.
 *
 * @param int|null $userId
 * @return string
 */
function dynamicQrAccountEmail(?int $userId): string
{
    if ($userId === null || !function_exists('dbQuery')) {
        return '';
    }

    try {
        $stmt = dbQuery('SELECT `email` FROM `tblUsers` WHERE `userID` = ? LIMIT 1', 'i', [$userId]);
        $row  = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        return '';
    }

    if (!is_array($row) || !is_string($row['email'])) {
        return '';
    }

    return $row['email'];
}

/**
 * ✉️ Queue one notice (template dynamic_qr_notice). Never throws.
 *
 * @param string                                                   $to
 * @param string                                                   $subject
 * @param array<int, string>                                       $lines
 * @param array<int, array{title: string, lines: array<int, string>}> $sections
 * @param string                                                   $linkUrl   '' = none
 * @param string                                                   $linkLabel
 * @return bool True when it was queued
 */
function dynamicQrQueueNotice(string $to, string $subject, array $lines, array $sections = [], string $linkUrl = '', string $linkLabel = ''): bool
{
    if ($to === '' || !function_exists('queueEmail')) {
        return false;
    }

    try {
        $queued = queueEmail($to, 'dynamic_qr_notice', [
            'subject'   => $subject,
            'heading'   => $subject,
            'lines'     => $lines,
            'sections'  => $sections,
            'linkUrl'   => $linkUrl,
            'linkLabel' => $linkLabel,
        ]);
    } catch (\Throwable $e) {
        return false;
    }

    return ($queued !== false);
}

/**
 * 🔗 An address on this site (site_url + path), or '' when site_url is not https.
 *
 * @param string $path Starting with /
 * @return string
 */
function dynamicQrSitePage(string $path): string
{
    $siteUrl = trim(humanCheckText(humanCheckSiteConfig('site_url', '')));
    if (!str_starts_with($siteUrl, 'https://')) {
        return '';
    }

    return rtrim($siteUrl, '/') . $path;
}

// ==============================================================================
// 🗄️ Reading and marking a code
// ==============================================================================

/**
 * 🔎 One code with the takedown columns (upgrade 0008).
 *
 * @param int  $redirectId
 * @param bool $forUpdate  Lock the row until the end of the open transaction
 * @return array|null Null when not found or unreadable
 */
function dynamicQrLoadTakedownRow(int $redirectId, bool $forUpdate = false): ?array
{
    if (!function_exists('dbQuery') || !dynamicQrUpgrade0008Ready()) {
        return null;
    }

    $sql = 'SELECT ' . CUERCODE_TAKEDOWN_ROW_COLUMNS . ' FROM `tblQRRedirects` WHERE `redirectID` = ? LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = dbQuery($sql, 'i', [$redirectId]);
    $row  = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!is_array($row)) {
        return null;
    }

    return $row;
}

/**
 * 📝 Go2My.Link has been confirmed to point the suspended code at the
 *    neutral page (or there is nothing on Go2My.Link to point).
 *
 * @param int $redirectId
 * @return void
 */
function dynamicQrMarkSuspensionApplied(int $redirectId): void
{
    $stmt = dbQuery(
        'UPDATE `tblQRRedirects` SET `suspensionAppliedAt` = UTC_TIMESTAMP() WHERE `redirectID` = ? AND `suspendedAt` IS NOT NULL AND `suspensionAppliedAt` IS NULL',
        'i',
        [$redirectId]
    );
    $stmt->close();
}

/**
 * 🔁 The takedown must be sent to Go2My.Link again (an owner's change
 *    landed after it, or the daily comparison found the link pointing
 *    elsewhere). The cron job, or the caller, sends it.
 *
 * @param int $redirectId
 * @return void
 */
function dynamicQrMarkSuspensionUnapplied(int $redirectId): void
{
    if (!dynamicQrUpgrade0008Ready()) {
        return;
    }

    try {
        $stmt = dbQuery(
            'UPDATE `tblQRRedirects` SET `suspensionAppliedAt` = NULL WHERE `redirectID` = ? AND `suspendedAt` IS NOT NULL',
            'i',
            [$redirectId]
        );
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'A takedown could not be marked to be sent again', 'redirectID: ' . $redirectId . ' | ' . get_class($e));
        }
    }
}

// ==============================================================================
// 🚫 dynamicQrSuspend() - take one code down
// ==============================================================================

/**
 * 🚫 Suspend one dynamic code (see the file header for the order).
 *
 * @param int                  $redirectId
 * @param array<string, mixed> $actor   ['type' => 'admin', 'admin' => from requireGlobalAdminPage()]
 *                                      or ['type' => 'system', 'label' => 'link-safety check' | 'abuse reports']
 * @param string               $note    Why, in plain words (shown to admins; an admin's is required)
 * @param array<string, mixed> $options 'source' (link_safety | reports | admin), 'facts' (for the notices),
 *                                      'applyNow' (default true), 'notify' (default true),
 *                                      'go2mylink' (tests)
 * @return array{ok: bool, outcome: string, message: string, applied: string}
 *         outcome: suspended | already | not_found | refused | failed; applied: the Go2My.Link step's outcome
 */
function dynamicQrSuspend(int $redirectId, array $actor, string $note, #[\SensitiveParameter] array $options = []): array
{
    $answer = static function (bool $ok, string $outcome, string $message, string $applied = ''): array {
        return ['ok' => $ok, 'outcome' => $outcome, 'message' => $message, 'applied' => $applied];
    };

    if (!function_exists('dbQuery') || !dynamicQrUpgrade0008Ready() || !adminTableExists('tblAdminAuditLog')) {
        return $answer(false, 'failed', 'Database upgrades are waiting, so the code cannot be suspended yet. Apply them on /admin/upgrades/.');
    }

    // 👤 Who is acting. An admin is checked HERE, read fresh from the database.
    $actorType = '';
    if (isset($actor['type']) && is_string($actor['type'])) {
        $actorType = $actor['type'];
    }
    $adminUserId = null;
    $actorLabel  = '';
    if ($actorType === 'admin') {
        $adminUserId = 0;
        if (isset($actor['admin']['userID']) && is_int($actor['admin']['userID'])) {
            $adminUserId = $actor['admin']['userID'];
        }
        if (adminAccessDecision(['userID' => $adminUserId], adminLoadAccessRow($adminUserId)) !== 'admin') {
            return $answer(false, 'refused', 'Only a Global Admin can suspend a code, so nothing was changed.');
        }
        $actorLabel = (string) $actor['admin']['email'];
    } elseif ($actorType === 'system') {
        $actorLabel = 'CueRCode';
        if (isset($actor['label']) && is_string($actor['label']) && $actor['label'] !== '') {
            $actorLabel = 'CueRCode (' . $actor['label'] . ')';
        }
    } else {
        return $answer(false, 'refused', 'That suspension was not understood, so nothing was changed.');
    }

    // 📝 The note: one line, required.
    $note = trim($note);
    if ($note === '' || mb_strlen($note, 'UTF-8') > CUERCODE_TAKEDOWN_NOTE_MAX || preg_match('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', $note) !== 0) {
        return $answer(false, 'refused', 'Write a short reason on one line (up to ' . CUERCODE_TAKEDOWN_NOTE_MAX . ' characters). It is kept in the admin change log.');
    }

    $source = 'admin';
    if (isset($options['source']) && in_array($options['source'], ['link_safety', 'reports', 'admin'], true)) {
        $source = $options['source'];
    }

    // 💾 1. Here first, with its audit row, in one transaction.
    try {
        getDB()->begin_transaction();
    } catch (\Throwable $e) {
        return $answer(false, 'failed', 'Not suspended: the database could not start the change. Please try again.');
    }

    try {
        $row = dynamicQrLoadTakedownRow($redirectId, true);
        if ($row === null) {
            adminRollbackQuietly(getDB());
            return $answer(false, 'not_found', 'That dynamic code was not found.');
        }
        if ($row['suspendedAt'] !== null) {
            adminRollbackQuietly(getDB());
            return $answer(true, 'already', 'That dynamic code is already suspended.');
        }

        // 🔗 Nothing on Go2My.Link (never made, or not asked yet): nothing to send.
        $nothingRemote = in_array((string) $row['syncState'], ['failed', 'unlinked'], true);
        $appliedSql    = 'NULL';
        if ($nothingRemote) {
            $appliedSql = 'UTC_TIMESTAMP()';
        }

        $update = dbQuery(
            'UPDATE `tblQRRedirects` SET `suspendedAt` = UTC_TIMESTAMP(), `suspendedByUserID` = ?, `suspensionNote` = ?, `suspensionAppliedAt` = ' . $appliedSql . ' '
            . 'WHERE `redirectID` = ? AND `suspendedAt` IS NULL',
            'isi',
            [$adminUserId, $note, $redirectId]
        );
        $changed = ($update->affected_rows === 1);
        $update->close();
        if (!$changed) {
            adminRollbackQuietly(getDB());
            return $answer(false, 'failed', 'Not suspended: the code changed at the same moment. Please try again.');
        }

        // 📣 An admin's suspension deals with the open reports about the code
        //    (decision 0046: their handled date starts the 12 months).
        if ($actorType === 'admin' && adminTableExists('tblAbuseReports')) {
            $reports = dbQuery(
                "UPDATE `tblAbuseReports` SET `status` = 'actioned', `handledByUserID` = ?, `handledAt` = UTC_TIMESTAMP() "
                . "WHERE `redirectID` = ? AND `status` IN ('new', 'reviewing')",
                'ii',
                [$adminUserId, $redirectId]
            );
            $reports->close();
        }

        $changes = ['suspended' => ['old' => false, 'new' => true], 'source' => ['old' => null, 'new' => $source]];
        if ($actorType === 'admin') {
            $entry = adminAuditEntryForAdmin($actor['admin'], 'dynamic_code.suspend', 'dynamic_code', (string) $row['redirectUUID'], 'success', $changes, $note);
        } else {
            $entry = [
                'actorType'   => 'system',
                'actorUserID' => null,
                'actorLabel'  => $actorLabel,
                'action'      => 'dynamic_code.suspend',
                'entityType'  => 'dynamic_code',
                'entityKey'   => (string) $row['redirectUUID'],
                'outcome'     => 'success',
                'changes'     => $changes,
                'reason'      => $note,
            ];
        }
        if (!adminAuditWrite($entry)) {
            adminRollbackQuietly(getDB());
            return $answer(false, 'failed', 'Not suspended: it could not be recorded in the admin change log. Please try again.');
        }

        getDB()->commit();
    } catch (\Throwable $e) {
        adminRollbackQuietly(getDB());
        if (function_exists('logError')) {
            logError('error', 'A dynamic code could not be suspended', 'redirectID: ' . $redirectId . ' | ' . get_class($e));
        }
        return $answer(false, 'failed', 'Not suspended: the database refused the change. Nothing was changed. Please try again.');
    }

    if (function_exists('logActivity')) {
        logActivity('dynamic_code_suspended', 'Suspended dynamic code ' . (string) $row['redirectUUID'], 'redirect', $redirectId, ['uuid' => (string) $row['redirectUUID'], 'source' => $source, 'by' => $actorLabel]);
    }

    // ✉️ The owner and the abuse mailbox are told.
    $notify = true;
    if (isset($options['notify']) && $options['notify'] === false) {
        $notify = false;
    }
    if ($notify) {
        $facts = [];
        if (isset($options['facts']) && is_array($options['facts'])) {
            $facts = $options['facts'];
        }
        $by = 'automatically by ' . $actorLabel;
        if ($actorType === 'admin') {
            $by = 'by ' . $actorLabel;
        }
        $mailbox = dynamicQrAbuseMailbox();
        $notices = dynamicQrSuspensionNotices($row, dynamicQrPrintedAddress($row), dynamicQrSuspensionWhy($source, $facts), $by, dynamicQrTakedownPromise(), $mailbox);
        $noticeOwnerId = null;
        if (isset($row['ownerUserID'])) {
            $noticeOwnerId = (int) $row['ownerUserID'];
        }
        dynamicQrQueueNotice(dynamicQrAccountEmail($noticeOwnerId), $notices['owner']['subject'], $notices['owner']['lines']);
        dynamicQrQueueNotice($mailbox, $notices['abuse']['subject'], $notices['abuse']['lines'], [], dynamicQrSitePage('/admin/'), 'Open the admin area');
    }

    // 🌐 2. Then Go2My.Link (the cron job sends it again if this does not work).
    $applied = '';
    $applyNow = true;
    if (isset($options['applyNow']) && $options['applyNow'] === false) {
        $applyNow = false;
    }
    if ($applyNow && !$nothingRemote) {
        $step    = dynamicQrApplySuspension($redirectId, $options);
        $applied = $step['outcome'];
    }

    return $answer(true, 'suspended', 'The dynamic code was suspended.', $applied);
}

// ==============================================================================
// 🌐 Telling Go2My.Link
// ==============================================================================

/**
 * 🌐 Send one takedown to Go2My.Link, under the owner's per-person lock (so
 *    an owner's change cannot be sent at the same moment). Never throws.
 *
 * @param int                  $redirectId
 * @param array<string, mixed> $options 'go2mylink' (tests)
 * @return array{outcome: string, reason: string} outcome: applied | waiting | busy | not_needed | not_found | failed
 */
function dynamicQrApplySuspension(int $redirectId, #[\SensitiveParameter] array $options = []): array
{
    try {
        $row = dynamicQrLoadTakedownRow($redirectId);
    } catch (\Throwable $e) {
        return ['outcome' => 'failed', 'reason' => 'database'];
    }
    if ($row === null) {
        return ['outcome' => 'not_found', 'reason' => ''];
    }

    $ownerId = dynamicQrOwnerOf($row);
    if ($ownerId !== null && !acquireDynamicCodeLimitLock($ownerId)) {
        // 🔒 The owner's lock is busy (a change of theirs is being sent):
        //    the change flow, or the next cron run, sends the takedown.
        return ['outcome' => 'busy', 'reason' => 'lock_busy'];
    }

    try {
        $result = dynamicQrApplySuspensionLocked($redirectId, dynamicQrGo2Options($options));
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'A takedown could not be sent to Go2My.Link', 'redirectID: ' . $redirectId . ' | ' . get_class($e));
        }
        $result = ['outcome' => 'failed', 'reason' => 'crashed'];
    } finally {
        if ($ownerId !== null) {
            releaseDynamicCodeLimitLock($ownerId);
        }
    }

    return $result;
}

/**
 * 🌐 The takedown itself, called ONLY while the owner's lock is held: point
 *    the Go2My.Link link at the neutral page and confirm it (by the answer, or
 *    by looking the link up), then fill suspensionAppliedAt.
 *
 * @param int                  $redirectId
 * @param array<string, mixed> $go2Options
 * @return array{outcome: string, reason: string}
 */
function dynamicQrApplySuspensionLocked(int $redirectId, #[\SensitiveParameter] array $go2Options): array
{
    $row = dynamicQrLoadTakedownRow($redirectId);
    if ($row === null) {
        return ['outcome' => 'not_found', 'reason' => ''];
    }
    if ($row['suspendedAt'] === null || $row['suspensionAppliedAt'] !== null) {
        return ['outcome' => 'not_needed', 'reason' => ''];
    }

    $state = (string) $row['syncState'];
    if ($state === 'pending') {
        // ⏳ Not known yet whether Go2My.Link made it: the tidy step settles
        //    it first, then the next run sends the takedown.
        return ['outcome' => 'waiting', 'reason' => 'pending'];
    }
    if (!in_array($state, ['active', 'unconfirmed'], true)) {
        dynamicQrMarkSuspensionApplied($redirectId);
        return ['outcome' => 'not_needed', 'reason' => 'nothing_on_go2mylink'];
    }

    $shortCode = '';
    if (isset($row['shortCode']) && is_string($row['shortCode'])) {
        $shortCode = $row['shortCode'];
    }
    $target = dynamicQrUnavailablePageUrl((string) humanCheckText(humanCheckSiteConfig('site_url', '')), $shortCode);
    if ($target === '') {
        if (function_exists('logError')) {
            logError('error', 'A takedown cannot be sent: the website address (site_url) is not an https address', 'redirectID: ' . $redirectId);
        }
        return ['outcome' => 'failed', 'reason' => 'no_site_url'];
    }

    $settings = go2mylinkSettingsFromOptions($go2Options);
    if ($settings['problem'] !== '') {
        return ['outcome' => 'failed', 'reason' => (string) $settings['problem']];
    }

    $result    = go2mylinkUpdateDestination($shortCode, $target, $go2Options);
    $confirmed = ($result['ok'] === true && $result['data']['destinationUrl'] === $target);

    if (!$confirmed && $result['ok'] !== true && $result['reason'] === 'not_found') {
        // 🤷 Go2My.Link has no such link: nothing sends people anywhere.
        dynamicQrNoteSyncError($redirectId, 'not_found');
        dynamicQrMarkSuspensionApplied($redirectId);
        return ['outcome' => 'not_found', 'reason' => 'not_found'];
    }

    if (!$confirmed && ($result['ok'] === true || $result['outcomeUnknown'] === true)) {
        // 🤷 The answer was lost or odd: look the link up.
        $lookup = go2mylinkLookupLink($shortCode, $go2Options);
        if ($lookup['ok'] === true && $lookup['data']['destinationUrl'] === $target) {
            $confirmed = true;
        }
    }

    if (!$confirmed) {
        dynamicQrNoteSyncError($redirectId, (string) $result['reason']);
        return ['outcome' => 'failed', 'reason' => (string) $result['reason']];
    }

    dynamicQrMarkSuspensionApplied($redirectId);

    return ['outcome' => 'applied', 'reason' => ''];
}

/**
 * 🏷️ May a command-line (cron) run call Go2My.Link or Google? Only when it
 *    declares which copy of the site it is (CUERCODE_SITE_COPY, #181): alpha,
 *    beta and live share the same files and settings. '' = yes; otherwise
 *    the reason word 'copy_unknown'. A web request is always allowed (its
 *    own folder and address show the copy).
 *
 * @param array<string, mixed> $options 'server' and 'commandLine' (tests)
 * @return string
 */
function dynamicQrCronCopyProblem(array $options): string
{
    $server = $_SERVER;
    if (isset($options['server']) && is_array($options['server'])) {
        $server = $options['server'];
    }
    $commandLine = (PHP_SAPI === 'cli');
    if (isset($options['commandLine']) && is_bool($options['commandLine'])) {
        $commandLine = $options['commandLine'];
    }

    if ($commandLine && go2mylinkSiteCopyMarker($server) === '') {
        return 'copy_unknown';
    }

    return '';
}

/**
 * 🔁 The email-queue cron job's step: send every takedown Go2My.Link has not
 *    confirmed yet (oldest first, at most 25 a run).
 *
 * @param array<string, mixed> $options 'go2mylink', 'batch', 'server', 'commandLine' (tests)
 * @return array{ok: bool, skipped: string, looked: int, applied: int, waiting: int, failed: int}
 */
function dynamicQrApplyPendingSuspensions(#[\SensitiveParameter] array $options = []): array
{
    $summary = ['ok' => false, 'skipped' => '', 'looked' => 0, 'applied' => 0, 'waiting' => 0, 'failed' => 0];

    if (!function_exists('dbQuery')) {
        return $summary;
    }
    if (!dynamicQrUpgrade0008Ready()) {
        $summary['ok']      = true;
        $summary['skipped'] = 'upgrade_waiting';
        return $summary;
    }

    $batch = CUERCODE_TAKEDOWN_REAPPLY_BATCH;
    if (isset($options['batch']) && is_int($options['batch']) && $options['batch'] > 0) {
        $batch = $options['batch'];
    }

    try {
        $stmt = dbQuery(
            'SELECT `redirectID` FROM `tblQRRedirects` WHERE `suspendedAt` IS NOT NULL AND `suspensionAppliedAt` IS NULL ORDER BY `suspendedAt`, `redirectID` LIMIT ?',
            'i',
            [$batch]
        );
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Waiting takedowns could not be read', get_class($e));
        }
        return $summary;
    }

    $summary['ok'] = true;
    if ($rows === []) {
        return $summary;
    }

    $copyProblem = dynamicQrCronCopyProblem($options);
    if ($copyProblem !== '') {
        $summary['skipped'] = $copyProblem;
        $summary['waiting'] = count($rows);
        return $summary;
    }

    $settings = go2mylinkSettingsFromOptions(dynamicQrGo2Options($options));
    if ($settings['problem'] !== '') {
        $summary['skipped'] = (string) $settings['problem'];
        $summary['waiting'] = count($rows);
        return $summary;
    }

    foreach ($rows as $row) {
        $summary['looked']++;
        $step = dynamicQrApplySuspension((int) $row['redirectID'], $options);
        if (in_array($step['outcome'], ['applied', 'not_needed', 'not_found'], true)) {
            $summary['applied']++;
        } elseif (in_array($step['outcome'], ['waiting', 'busy'], true)) {
            $summary['waiting']++;
        } else {
            $summary['failed']++;
        }
    }

    return $summary;
}

/**
 * 🗒️ The takedown step's summary as plain lines for the cron output (counts
 *    and reason words only). PURE.
 *
 * @param array<string, mixed> $summary From dynamicQrApplyPendingSuspensions()
 * @return array<int, string>
 */
function dynamicQrApplyPendingLines(array $summary): array
{
    if ($summary['ok'] !== true) {
        return ['CueRCode takedowns: the waiting takedowns could not be read (database problem, see the error log).'];
    }
    if ($summary['skipped'] === 'copy_unknown') {
        return ['CueRCode takedowns: ' . (int) $summary['waiting'] . ' suspended code(s) still point at their destination on Go2My.Link and were NOT sent, because this run did not say which copy of the site it is. Add CUERCODE_SITE_COPY=live (or alpha, or beta) in front of the cron command.'];
    }
    if ($summary['skipped'] !== '' && $summary['skipped'] !== 'upgrade_waiting') {
        return ['CueRCode takedowns: ' . (int) $summary['waiting'] . ' suspended code(s) were not sent to Go2My.Link (' . $summary['skipped'] . ').'];
    }
    if ($summary['looked'] > 0) {
        return ['CueRCode takedowns: sent ' . (int) $summary['looked'] . ' waiting takedown(s): ' . (int) $summary['applied'] . ' confirmed, '
            . (int) $summary['waiting'] . ' still waiting, ' . (int) $summary['failed'] . ' failed (tried again next run).'];
    }

    return [];
}

// ==============================================================================
// 🔁 dynamicQrRecheckSafety() - the daily re-check (WP4e)
// ==============================================================================

/**
 * 🔁 Check every code in use again (active, switched on, not suspended), the
 *    oldest check first, at most 200 a run; a destination now listed as
 *    unsafe is suspended automatically. Stops early after three unanswered
 *    checks in a row (the provider is down).
 *
 * @param array<string, mixed> $options 'linkSafety' and 'go2mylink' (tests), 'batch',
 *                                      'afterHours', 'server', 'commandLine'
 * @return array{ok: bool, skipped: string, checked: int, safe: int, unsafe: int, unchecked: int, suspended: int, stoppedEarly: bool, left: int, reason: string}
 */
function dynamicQrRecheckSafety(#[\SensitiveParameter] array $options = []): array
{
    $summary = ['ok' => false, 'skipped' => '', 'checked' => 0, 'safe' => 0, 'unsafe' => 0, 'unchecked' => 0, 'suspended' => 0, 'stoppedEarly' => false, 'left' => 0, 'reason' => ''];

    if (!function_exists('dbQuery')) {
        return $summary;
    }
    if (!dynamicQrUpgrade0008Ready()) {
        $summary['ok']      = true;
        $summary['skipped'] = 'upgrade_waiting';
        return $summary;
    }

    $batch = CUERCODE_TAKEDOWN_RECHECK_BATCH;
    if (isset($options['batch']) && is_int($options['batch']) && $options['batch'] > 0) {
        $batch = $options['batch'];
    }
    $afterHours = CUERCODE_TAKEDOWN_RECHECK_AFTER_HOURS;
    if (isset($options['afterHours']) && is_int($options['afterHours']) && $options['afterHours'] >= 0) {
        $afterHours = $options['afterHours'];
    }

    $due = "`syncState` = 'active' AND `isActive` = 1 AND `disabledReason` IS NULL AND `suspendedAt` IS NULL "
        . 'AND (`safetyCheckedAt` IS NULL OR `safetyCheckedAt` <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? HOUR))';

    try {
        $stmt = dbQuery(
            'SELECT `redirectID`, `redirectUUID`, `ownerUserID`, `destinationURL` FROM `tblQRRedirects` WHERE ' . $due . ' ORDER BY `safetyCheckedAt`, `redirectID` LIMIT ?',
            'ii',
            [$afterHours, $batch]
        );
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'The daily link-safety re-check could not read the codes', get_class($e));
        }
        return $summary;
    }

    $summary['ok'] = true;
    if ($rows === []) {
        return $summary;
    }

    $copyProblem = dynamicQrCronCopyProblem($options);
    if ($copyProblem !== '') {
        $summary['skipped'] = $copyProblem;
        $summary['left']    = dynamicQrRecheckCountDue($due, $afterHours);
        return $summary;
    }

    $safetyBase = [];
    if (isset($options['linkSafety']) && is_array($options['linkSafety'])) {
        $safetyBase = $options['linkSafety'];
    }
    $settings = urlReputationReadSettings();
    if (isset($safetyBase['settings']) && is_array($safetyBase['settings'])) {
        $settings = $safetyBase['settings'];
    }
    if (!urlReputationHasUsableProvider($settings)) {
        $summary['skipped'] = 'not_configured';
        $summary['left']    = dynamicQrRecheckCountDue($due, $afterHours);
        return $summary;
    }

    $unansweredInARow = 0;
    foreach ($rows as $row) {
        $redirectId = (int) $row['redirectID'];
        $safetyOptions = $safetyBase;
        $safetyOptions['redirectID'] = $redirectId;
        if ($row['ownerUserID'] !== null) {
            $safetyOptions['userID'] = (int) $row['ownerUserID'];
        }

        $safety = dynamicQrCheckDestinationSafety((string) $row['destinationURL'], 'recheck', $safetyOptions);
        $summary['checked']++;
        dynamicQrNoteSafety($redirectId, $safety['verdict']);

        if ($safety['verdict'] === 'unsafe') {
            $summary['unsafe']++;
            $unansweredInARow = 0;
            $providerName = $safety['provider'];
            $providers    = urlReputationProviders();
            if (isset($providers[$safety['provider']])) {
                $providerName = $providers[$safety['provider']]['name'];
            }
            $suspended = dynamicQrSuspend(
                $redirectId,
                ['type' => 'system', 'label' => 'link-safety check'],
                'Suspended automatically by the daily link-safety re-check: ' . $providerName . ' lists the destination (' . implode(', ', $safety['threatTypes']) . ').',
                array_merge($options, ['source' => 'link_safety', 'facts' => ['providerName' => $providerName, 'threatTypes' => $safety['threatTypes']]])
            );
            if ($suspended['outcome'] === 'suspended') {
                $summary['suspended']++;
            }
            continue;
        }

        if ($safety['verdict'] === 'safe') {
            $summary['safe']++;
            $unansweredInARow = 0;
            continue;
        }

        $summary['unchecked']++;
        $summary['reason'] = $safety['reason'];
        $unansweredInARow++;
        if ($unansweredInARow >= CUERCODE_TAKEDOWN_RECHECK_STOP_AFTER) {
            $summary['stoppedEarly'] = true;
            break;
        }
    }

    $summary['left'] = dynamicQrRecheckCountDue($due, $afterHours);

    return $summary;
}

/**
 * 🔢 How many codes are still due for the re-check (0 when it cannot be read).
 *
 * @param string $due        The WHERE condition (one ? for the hours)
 * @param int    $afterHours
 * @return int
 */
function dynamicQrRecheckCountDue(string $due, int $afterHours): int
{
    try {
        $stmt = dbQuery('SELECT COUNT(*) AS `due` FROM `tblQRRedirects` WHERE ' . $due, 'i', [$afterHours]);
        $row  = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        return 0;
    }

    if (!is_array($row)) {
        return 0;
    }

    return (int) $row['due'];
}

/**
 * 🗒️ The re-check's summary as plain lines (counts and reason words only). PURE.
 *
 * @param array<string, mixed> $summary From dynamicQrRecheckSafety()
 * @return array<int, string>
 */
function dynamicQrRecheckLines(array $summary): array
{
    if ($summary['ok'] !== true) {
        return ['Link-safety re-check: the codes could not be read (database problem, see the error log).'];
    }
    if ($summary['skipped'] === 'copy_unknown') {
        return ['Link-safety re-check: NOT run, because this run did not say which copy of the site it is (' . (int) $summary['left'] . ' code(s) due). Add CUERCODE_SITE_COPY=live (or alpha, or beta) in front of the cron command.'];
    }
    if ($summary['skipped'] === 'not_configured') {
        return ['Link-safety re-check: NOT run, because no link-safety provider has a key saved (' . (int) $summary['left'] . ' code(s) due). Enter the Google Web Risk key on /admin/settings/ (Dynamic QR codes).'];
    }
    if ($summary['skipped'] === 'upgrade_waiting') {
        return ['Link-safety re-check: waiting for database upgrade 0008.'];
    }

    $lines = ['Link-safety re-check: checked ' . (int) $summary['checked'] . ' code(s): ' . (int) $summary['safe'] . ' safe, '
        . (int) $summary['unsafe'] . ' listed as unsafe (' . (int) $summary['suspended'] . ' suspended), ' . (int) $summary['unchecked'] . ' could not be checked. '
        . (int) $summary['left'] . ' still due.'];
    if ($summary['stoppedEarly'] === true) {
        $lines[] = 'Link-safety re-check: stopped early - the provider could not answer ' . CUERCODE_TAKEDOWN_RECHECK_STOP_AFTER . ' times in a row (' . urlReputationReasonText((string) $summary['reason']) . ').';
    }

    return $lines;
}
