<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - A typed email address finds a row only when it is EXACTLY the same
 * ==============================================================================
 *
 * Path: tests/test_email_exact_match.php
 *
 * WHAT THIS PROVES (plain English), fourth-round review, Medium 1 (#163):
 *   1. emailExactMatchSql() writes the ordinary comparison (so the index is
 *      used) AND a byte-for-byte one, and refuses anything but a plain
 *      column name.
 *   2. No site code compares an email column with a typed value in the old,
 *      look-alike-blind way: every `email` = ? or `toAddress` = ? left in
 *      _functions, _includes and public_html is a SET (a value being
 *      written), never a match.
 *   3. Each place the review named uses the exact rule: sign-in (normal and
 *      no-JavaScript, which share one check), sign-up, forgot password, "send
 *      me a new link", Google/Microsoft sign-in, the admin command line's
 *      look-up and its "cancel unsent emails", and both erasure steps on the
 *      email queue.
 *
 * The real-database proof (an accented, zero-width, control-character and
 * full-width spelling each refused on MySQL 8.0, MySQL 8.4 and MariaDB 10.6)
 * is scenario EX of tests/integration/account_recovery_real_mysql.php and
 * scenario R of tests/integration/human_check_real_mysql.php.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (fourth-round review, Medium 1)
 * @see         web/CueRCode.net/_functions/fn_email_match.php
 */

requireFn('fn_email_match.php');

$emSiteRoot = CUERCODE_SITE_ROOT;
$emDs       = DIRECTORY_SEPARATOR;

/**
 * ✂️ PHP source without comments.
 *
 * @param string $source
 * @return string
 */
function emCodeOnly(string $source): string
{
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $code .= $token[1];
            continue;
        }
        $code .= $token;
    }
    return $code;
}

/**
 * 🔎 The body of one function in some code ('' when not found).
 *
 * @param string $code
 * @param string $name
 * @return string
 */
function emFunctionBody(string $code, string $name): string
{
    $start = strpos($code, 'function ' . $name . '(');
    if ($start === false) {
        return '';
    }
    $rest = substr($code, $start + 1);
    $end  = strpos($rest, "\nfunction ");
    if ($end === false) {
        return $rest;
    }
    return substr($rest, 0, $end);
}

// ==============================================================================
// 1. The helper
// ==============================================================================

assertEquals(
    '`email` = ? AND CAST(`email` AS BINARY) = CAST(? AS BINARY)',
    emailExactMatchSql('email'),
    'the exact rule: the ordinary comparison (uses the index) AND a byte-for-byte one'
);
assertEquals(['a@b.test', 'a@b.test'], emailExactMatchParams('a@b.test'), 'the address is bound to both placeholders');

$emRefused = 0;
foreach (['email`; DROP TABLE x; --', '', '1email', 'e-mail', 'email ', '`email`'] as $emBadName) {
    try {
        emailExactMatchSql($emBadName);
    } catch (\InvalidArgumentException $emError) {
        $emRefused++;
    }
}
assertEquals(6, $emRefused, 'anything but a plain column name is refused (it is written into the SQL)');

// ==============================================================================
// 2. No look-alike-blind match is left anywhere in the site code
// ==============================================================================

$emFiles = [];
foreach (['_functions', '_includes', 'public_html'] as $emFolder) {
    $emIterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($emSiteRoot . $emFolder, \FilesystemIterator::SKIP_DOTS));
    foreach ($emIterator as $emFile) {
        if ($emFile->isFile() && str_ends_with($emFile->getFilename(), '.php')) {
            $emFiles[] = $emFile->getPathname();
        }
    }
}
$emBareMatches = [];
foreach ($emFiles as $emPath) {
    $emCode = emCodeOnly((string) file_get_contents($emPath));
    // 📌 "= ?" followed by a comma is a SET list (a value written); anything
    //    else is a comparison.
    if (preg_match_all('/`(email|toAddress)`\s*=\s*\?(?!\s*,)/', $emCode, $emMatches) > 0) {
        $emBareMatches[] = str_replace($emSiteRoot, '', $emPath) . ' (' . count($emMatches[0]) . ')';
    }
}
assertTrue(count($emFiles) > 100, 'the scan covers the site code (' . count($emFiles) . ' files)');
assertEquals([], $emBareMatches, 'no site code matches an email column with the database\'s look-alike-blind comparison alone');

// ==============================================================================
// 3. Every place the review named uses the exact rule
// ==============================================================================

$emAuth    = emCodeOnly((string) file_get_contents($emSiteRoot . '_functions' . $emDs . 'fn_auth.php'));
$emOauth   = emCodeOnly((string) file_get_contents($emSiteRoot . '_functions' . $emDs . 'fn_oauth.php'));
$emAdmin   = emCodeOnly((string) file_get_contents($emSiteRoot . '_functions' . $emDs . 'fn_admin.php'));
$emAccount = emCodeOnly((string) file_get_contents($emSiteRoot . '_functions' . $emDs . 'fn_api_account.php'));

$emPlaces = [
    'sign-in (and the no-JavaScript route, which shares it)' => emFunctionBody($emAuth, 'loginUserCheckPassword'),
    'sign-up'                                                => emFunctionBody($emAuth, 'registerUser'),
    'forgot password'                                        => emFunctionBody($emAuth, 'requestPasswordResetWork'),
    'send me a new link'                                     => emFunctionBody($emAuth, 'requestNewVerificationLinkWork'),
    'Google/Microsoft sign-in'                               => emFunctionBody($emOauth, 'applyOAuthAccountLink'),
    'admin command-line look-up'                             => emFunctionBody($emAdmin, 'adminCliFindAccounts'),
    'admin command-line "cancel unsent emails"'              => emFunctionBody($emAdmin, 'adminCliCancelUnsentAccountEmails'),
];
foreach ($emPlaces as $emWhat => $emBody) {
    assertTrue($emBody !== '' && str_contains($emBody, 'emailExactMatchSql('), $emWhat . ': uses the exact rule');
}
assertEquals(2, substr_count(emFunctionBody($emAccount, 'eraseUserAccount'), "emailExactMatchSql('toAddress')"), 'erasure: both email-queue steps use the exact rule (fourth-round review, Info 5)');

// 📦 Loaded by the start-up code, and by each file that uses it.
$emBootstrap = (string) file_get_contents($emSiteRoot . '_includes' . $emDs . 'bootstrap.php');
assertContains("'fn_email_match.php'", $emBootstrap, 'bootstrap.php loads fn_email_match.php');
foreach (['fn_auth.php' => $emAuth, 'fn_oauth.php' => $emOauth, 'fn_admin.php' => $emAdmin, 'fn_api_account.php' => $emAccount] as $emName => $emCode) {
    assertContains("require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_email_match.php';", $emCode, $emName . ' loads fn_email_match.php itself (it can be loaded without bootstrap.php)');
}
