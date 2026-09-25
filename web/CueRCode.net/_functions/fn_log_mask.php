<?php
/**
 * ==============================================================================
 * 🙈 CueRCode - Log masking helpers (keep email addresses out of plain-text logs)
 * ==============================================================================
 *
 * Path: _functions/fn_log_mask.php
 *
 * Small helpers with NO dependencies that hide anything shaped like an email
 * address before text is written somewhere less protected than the database -
 * PHP's error log above all. On a web request that log is a server file; on
 * the command line (the email queue runner, run by cron) it is the terminal,
 * which cron emails to the owner (#126).
 *
 * Used by fn_activity_log.php (its error_log() fallbacks), fn_error_handler.php
 * (maskSensitiveForLog()) and fn_email.php (emailQueueSafeDetail() and
 * logEmailAttempt()'s fallback).
 *
 * 📌 WHY a separate file: fn_activity_log.php loads BEFORE fn_email.php and
 * must not depend on it, and fn_email.php is loaded ON ITS OWN by the cron
 * runner (before the site start-up code) and by the tests. A file with no
 * dependencies that each of them require_once's gives ONE copy of the rule
 * and no load-order trap.
 *   Rejected: copying the regular expression into each logger (copies drift
 *   apart); calling emailQueueSafeDetail() from the activity logger (it would
 *   silently not mask whenever fn_email.php had not been loaded yet).
 *
 * 📌 This is the first _functions file that other _functions files load
 * THEMSELVES (fn_activity_log.php, fn_error_handler.php and fn_email.php each
 * require_once it, as well as bootstrap.php). That is deliberate: each of them
 * can be loaded without bootstrap.php, and require_once works from the file's
 * real path, so the repeat loads do nothing.
 *
 * ⚠️ What it CANNOT do: it does not recognise other personal data or secrets -
 * callers must still never log bodies, links, tokens or passwords. Addresses
 * with non-English letters ARE masked: the pattern also accepts any byte from
 * 0x80 to 0xFF (the building blocks of every non-English letter in UTF-8), so
 * "jösé@example.com" and "alice@münchen.de" become "[address]" whole. (The
 * first version accepted plain ASCII only and left such addresses fully or
 * partly visible - found by the #122/#123/#126 independent verification,
 * 2026-09-25.) A true Unicode pattern (the /u flag) is still NOT used, because
 * preg_replace() fails on any invalid UTF-8 byte, which would blank whole log
 * lines; working on raw bytes can never fail that way.
 *
 * @package     CueRCode
 * @subpackage  Logging
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-09-25 (#126)
 * @see         fn_activity_log.php, fn_error_handler.php, fn_email.php
 */

// ==============================================================================
// 🚫 Direct access protection - the same pattern as every other _functions file
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
// 🔧 Constants
// ==============================================================================

/**
 * @var string The address pattern - emailQueueSafeDetail()'s (#112) rule, widened
 * to bytes 0x80-0xFF so non-English addresses are masked too (the ASCII cases,
 * and their tests, are unchanged). No /u flag on purpose: with /u,
 * preg_replace() returns null for any invalid UTF-8 byte, which would blank a
 * whole log line; on raw bytes the pattern can never fail. Because it only
 * ever consumes whole runs of 0x80-0xFF bytes, it never cuts a character in
 * half.
 *
 * It deliberately matches "something@something" loosely rather than following
 * the full address rules: in a log line, hiding a little too much is harmless,
 * while missing a real address is the fault this file exists to prevent.
 */
if (!defined('CUERCODE_LOG_MASK_EMAIL_PATTERN')) {
    define('CUERCODE_LOG_MASK_EMAIL_PATTERN', '/[A-Za-z0-9._%+\-\x80-\xFF]+@[A-Za-z0-9.\-\x80-\xFF]+/');
}

/**
 * @var string What an address is replaced with. Short, fixed and obviously a
 * placeholder, so somebody reading a log knows an address was there.
 */
if (!defined('CUERCODE_LOG_MASK_EMAIL_REPLACEMENT')) {
    define('CUERCODE_LOG_MASK_EMAIL_REPLACEMENT', '[address]');
}

// ==============================================================================
// 🙈 maskEmailAddresses() - hide every address-shaped piece of text
// ==============================================================================

/**
 * 🙈 Replace every piece of text shaped like an email address with "[address]".
 *
 * Nothing else is changed - not the length, the spacing or any other text - so
 * callers that need more cleaning (control characters, length) use
 * cleanLogText() instead. maskSensitiveForLog() in fn_error_handler.php uses
 * this one directly, because it has its own rules for everything else.
 *
 * 🛡️ Fails CLOSED: if the regular expression engine ever fails (for example it
 * hits its backtracking limit on an enormous input), this returns an empty
 * string rather than the raw text. A blank log line is better than a leaked
 * address.
 *
 * ⚠️ What it CANNOT do: see the file header (other personal data and secrets).
 * Note also that "user@host" pairs such as a database login quoted with
 * separate quote marks ('cuercode'@'localhost') are NOT address-shaped (the
 * quote marks break the pattern) and are left alone; those carry a database
 * user name and host, never a password, and are documented in DEV_NOTES.md.
 *
 * @param string $text The text to mask.
 *
 * @return string The text with every address-shaped piece replaced by
 *                "[address]", or '' if the replace failed.
 *
 * @see https://www.php.net/manual/en/function.preg-replace.php
 */
function maskEmailAddresses(string $text): string
{
    // ⚡ Nothing to do for an empty string.
    if ($text === '') {
        return '';
    }

    $masked = preg_replace(CUERCODE_LOG_MASK_EMAIL_PATTERN, CUERCODE_LOG_MASK_EMAIL_REPLACEMENT, $text);

    if (!is_string($masked)) {
        // 🛡️ A failed replace must NEVER let the raw text through - return nothing instead.
        return '';
    }

    return $masked;
}

// ==============================================================================
// 🧽 cleanLogText() - make one piece of text safe for a single log line
// ==============================================================================

/**
 * 🧽 Make a piece of text safe to write as (part of) one plain-text log line.
 *
 * This is exactly the algorithm emailQueueSafeDetail() (fn_email.php) has used
 * since #112; that function is now a thin wrapper round this one, so the email
 * queue runner, the activity logger and the error logger share ONE copy of the
 * rule (#126). The steps, in order, and why:
 *
 *   1. Control characters (including newlines and tabs) become spaces, so a
 *      crafted value cannot forge extra lines in a log or a cron email.
 *   2. Anything shaped like an email address becomes "[address]"
 *      (maskEmailAddresses()), because the log is less protected than the
 *      database and, on the command line, is emailed by cron.
 *   3. Runs of whitespace collapse to one space, and the ends are trimmed, so
 *      the result is one tidy line.
 *   4. Long text is cut to $maxLength characters, with "..." marking the cut,
 *      so one runaway value cannot flood the log. The limit is never below 20,
 *      so the "..." always leaves something readable.
 *
 * 🛡️ Any failed regular expression gives '' (never the raw text).
 *
 * ⚠️ What it CANNOT do: it does not recognise secrets such as tokens, links
 * or passwords - callers must never pass those in the first place. Address
 * masking is ASCII-only (see the file header).
 *
 * @param string $text      The text to clean.
 * @param int    $maxLength Maximum length in characters (raised to at least 20).
 *
 * @return string The cleaned text ('' if a regular expression failed).
 *
 * @see maskEmailAddresses()
 * @see https://www.php.net/manual/en/function.mb-substr.php
 */
function cleanLogText(string $text, int $maxLength = 1000): string
{
    // 🧹 1. Control characters (including newlines and tabs) become spaces, so a
    //       crafted value cannot forge extra lines in a log or a cron email.
    $clean = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);

    if (!is_string($clean)) {
        return '';
    }

    // 🙈 2. Hide anything shaped like an email address. maskEmailAddresses()
    //       already returns '' on failure, so there is nothing more to check.
    $clean = maskEmailAddresses($clean);

    // 📏 3. Collapse runs of whitespace to one space, then trim the ends.
    $clean = preg_replace('/\s+/', ' ', $clean);

    if (!is_string($clean)) {
        return '';
    }

    $clean = trim($clean);

    // ✂️ 4. Cut to length, marking the cut with "..." (never below 20 characters).
    $maxLength = max(20, $maxLength);

    if (mb_strlen($clean) > $maxLength) {
        $clean = mb_substr($clean, 0, $maxLength - 3) . '...';
    }

    return $clean;
}
