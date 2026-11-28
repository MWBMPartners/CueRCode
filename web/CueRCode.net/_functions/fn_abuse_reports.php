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
 *   The report form, the counting query and the suspension are WP4f (#169).
 *
 * Loading this file defines functions only.
 *
 * @package     CueRCode
 * @subpackage  DynamicQR
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (WP4a, #164)
 * @see         _includes/sql/migrations/0007_dynamic_qr_go2mylink.php - tblAbuseReports
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
