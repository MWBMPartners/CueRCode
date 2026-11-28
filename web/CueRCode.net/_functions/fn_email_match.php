<?php
/**
 * ==============================================================================
 * 📧 CueRCode - Find an email address in the database EXACTLY
 * ==============================================================================
 *
 * Path: _functions/fn_email_match.php
 *
 * ONE rule for every place that looks up, or changes, rows by an email
 * address typed by a visitor or an operator: sign-in (normal and without
 * JavaScript), sign-up, forgot password, "send me a new link", Google and
 * Microsoft sign-in, the admin command line, its "cancel unsent emails"
 * clean-up and account erasure.
 *
 * 🔒 WHY (fourth-round review, Medium 1, 2026-11-28): the address columns use
 * the database's utf8mb4_unicode_ci comparison, which ignores far more than
 * capital letters. It treats "alicé@", "al<zero-width space>ice@",
 * "al<control character>ice@" and a full-width "ａlice@" as the SAME as
 * "alice@". So a differently spelled address found Alice's account. The
 * no-JavaScript route counts its password checks under the spelling that was
 * typed, so each new spelling got a fresh allowance of guesses at Alice's
 * password (reproduced on MySQL 8.0, MySQL 8.4 and MariaDB 10.6).
 *
 * The fix is to compare the bytes as well: the ordinary comparison stays (so
 * the column's index is still used and the look-up stays instant), and a
 * second, byte-for-byte comparison must ALSO hold. Every address is stored
 * trimmed and in lower case, and every caller lower-cases the typed address
 * the same way first, so the only spellings this refuses are the look-alikes.
 *   Rejected: comparing in PHP after the look-up (it cannot protect an UPDATE
 *   such as the erasure's); "COLLATE utf8mb4_bin" (the index could not be
 *   used, and that comparison still ignores trailing spaces); changing the
 *   columns' collation (an upgrade on live tables, and the unique key would
 *   then allow "alice@" and "alicé@" as two accounts).
 *
 * Sign-up is safe as well: a look-alike of an existing address is not found
 * here, so the insert is tried and the unique key (which still uses the
 * ordinary comparison) refuses it, which sign-up and Google/Microsoft
 * sign-in already treat as "that address is taken".
 *
 * No dependencies, so any file can require_once it (bootstrap.php loads it
 * too). PURE: it only builds SQL text.
 *
 * @package     CueRCode
 * @subpackage  Database
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (fourth-round review, Medium 1, #163)
 * @see         https://dev.mysql.com/doc/refman/8.0/en/cast-functions.html#function_cast
 * @see         https://dev.mysql.com/doc/refman/8.0/en/charset-unicode-sets.html
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

/**
 * 🔎 The WHERE-clause text that matches an email column EXACTLY.
 *
 * It holds TWO placeholders; bind the (lower-cased, trimmed) address to both,
 * with emailExactMatchParams(). Example:
 *
 *     dbQuery('SELECT `userID` FROM `tblUsers` WHERE ' . emailExactMatchSql('email') . ' LIMIT 1',
 *             'ss', emailExactMatchParams($address));
 *
 * @param string $column Column name, letters and digits only (it is written into the SQL)
 * @return string
 * @throws \InvalidArgumentException For any other column name
 */
function emailExactMatchSql(string $column): string
{
    // 🛡️ The name goes into SQL text, so only a plain identifier is accepted.
    if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $column) !== 1) {
        throw new \InvalidArgumentException('emailExactMatchSql(): not a plain column name.');
    }

    // 📌 First the ordinary comparison (uses the index), then the bytes.
    //    CAST(... AS BINARY) compares byte for byte, trailing spaces included.
    return '`' . $column . '` = ? AND CAST(`' . $column . '` AS BINARY) = CAST(? AS BINARY)';
}

/**
 * 📎 The two values for emailExactMatchSql()'s two placeholders.
 *
 * @param string $address The address, already lower-cased and trimmed
 * @return array{0: string, 1: string}
 */
function emailExactMatchParams(string $address): array
{
    return [$address, $address];
}
