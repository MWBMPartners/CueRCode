<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Characterization Tests for the EMAIL SUBSYSTEM (Epic E8, #29)
 * ==============================================================================
 *
 * Path: tests/test_email.php
 *
 * Covers fn_email.php - Microsoft Graph client-credentials sendMail, template
 * rendering, and the exponential-backoff queue/retry math:
 *
 *   - isValidEmailTemplateKey()   - allowlist + path-traversal/format rejection.
 *   - renderEmailTemplate()       - all 4 shipped templates render the expected
 *                                   subject + a full HTML document, and every
 *                                   interpolated parameter is HTML-escaped (an
 *                                   XSS-payload parameter is asserted to appear
 *                                   ONLY in its escaped form, never raw, and
 *                                   never double-escaped).
 *   - emailEscape()               - the shared escaping helper.
 *   - computeEmailBackoffMinutes() - the pure exponential-backoff schedule
 *                                   (2^attempts minutes), including the
 *                                   defensive exponent clamp.
 *   - getGraphAccessToken()/sendEmailNow() - degrade gracefully (null / a
 *                                   "skipped" result) with NO fatal error and
 *                                   NO network call attempted when the
 *                                   configuration subsystem itself is not
 *                                   loaded (the same "credentials effectively
 *                                   absent" state a fresh, unconfigured install
 *                                   is in - all three graph_* settings seed
 *                                   EMPTY). A generous wall-clock ceiling
 *                                   asserts no blocking network attempt was
 *                                   made (a real curl call to a token/sendMail
 *                                   endpoint could not plausibly complete,
 *                                   succeed or fail, in under the threshold
 *                                   used below).
 *   - queueEmail()/processEmailQueue()/maybeProcessEmailQueueOnShutdown() -
 *                                   the FAIL-OPEN contract when dbQuery() is
 *                                   unavailable (this test harness never loads
 *                                   db_connect.php, so this is always true
 *                                   here) - each returns a safe default
 *                                   (false / a zeroed summary / silently) with
 *                                   no fatal. queueEmail() additionally
 *                                   rejects an invalid template key before
 *                                   ever attempting the database call.
 *
 * ⚠️ SKIPPED (DB-dependent - out of scope for this harness, same convention as
 *    test_core.php's fn_config.php/fn_activity_log.php exclusions):
 *   - The actual INSERT performed by queueEmail() and the SELECT/UPDATE claim-
 *     row/settle-success/settle-failure cycle inside processEmailQueue() all
 *     require a live tblEmailQueue table via a real MySQLi connection.
 *   - sendEmailNow()'s Graph HTTP success/failure response-parsing branches
 *     (202 vs. error JSON) require either a live Graph tenant or an HTTP
 *     mocking layer this dependency-free harness does not have; the
 *     credential-absent short-circuit path (tested here) IS the behaviour
 *     that matters for an out-of-the-box, unconfigured install.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E8, #29)
 * @see         tests/bootstrap.php  - requireFn() loader (no DB connection)
 * @see         tests/lib/assert.php - the assert* functions used below
 * @see         web/CueRCode.net/_functions/fn_email.php
 * @see         web/CueRCode.net/_includes/email_templates/*.php
 */

// ==============================================================================
// 🧰 Load the DB-independent production function file (NO database connection)
// ==============================================================================
// fn_email.php only calls dbQuery()/getConfig()/logActivity() from INSIDE
// function bodies, each guarded by function_exists() - none of those files are
// loaded here, so every guard evaluates false and the fail-open paths engage.
// This deliberately mirrors a fresh, unconfigured CueRCode install: no Graph
// credentials, no DB connection available to the email subsystem specifically.
// @see tests/bootstrap.php - requireFn()
// ==============================================================================

requireFn('fn_email.php');

// ==============================================================================
// ✅ Section 1: isValidEmailTemplateKey() - allowlist + traversal rejection
// ==============================================================================

assertTrue(isValidEmailTemplateKey('verification'), 'verification is an allowlisted template key');
assertTrue(isValidEmailTemplateKey('password_reset'), 'password_reset is an allowlisted template key');
assertTrue(isValidEmailTemplateKey('invitation'), 'invitation is an allowlisted template key');
assertTrue(isValidEmailTemplateKey('receipt'), 'receipt is an allowlisted template key');

assertFalse(isValidEmailTemplateKey('nonexistent'), 'an unlisted key is rejected');
assertFalse(isValidEmailTemplateKey('../../etc/passwd'), 'a path-traversal key is rejected');
assertFalse(isValidEmailTemplateKey('verification.php'), 'a key with an extension is rejected (must be a bare slug)');
assertFalse(isValidEmailTemplateKey('VERIFICATION'), 'an uppercase key is rejected (allowlist is exact-case)');
assertFalse(isValidEmailTemplateKey(''), 'an empty key is rejected');
assertFalse(isValidEmailTemplateKey('verification; DROP TABLE tblEmailQueue'), 'a key with SQL-looking content is rejected');

// ==============================================================================
// ✅ Section 2: emailEscape() - shared HTML-escaping helper
// ==============================================================================

assertEquals(
    '&lt;script&gt;alert(1)&lt;/script&gt;',
    emailEscape('<script>alert(1)</script>'),
    'emailEscape() encodes angle brackets'
);
assertEquals('Jane &amp; Bob', emailEscape('Jane & Bob'), 'emailEscape() encodes ampersands');
assertEquals('&quot;quoted&quot;', emailEscape('"quoted"'), 'emailEscape() encodes double quotes (ENT_QUOTES)');
assertEquals('&#039;quoted&#039;', emailEscape("'quoted'"), 'emailEscape() encodes single quotes (ENT_QUOTES)');

// ==============================================================================
// ✅ Section 3: renderEmailTemplate() - each shipped template, escaping proven
// ==============================================================================

// --- verification.php --------------------------------------------------------

$xssPayload = '<script>alert(document.cookie)</script>';

[$verifySubject, $verifyBody] = renderEmailTemplate('verification', [
    'recipientName'   => $xssPayload,
    'verificationUrl' => 'https://cuercode.net/verify?token=abc123',
    'expiryHours'     => 24,
]);

assertEquals('Verify your CueRCode email address', $verifySubject, 'verification template subject is correct');
assertContains('<!DOCTYPE html>', $verifyBody, 'verification template renders a full HTML document');
assertContains('https://cuercode.net/verify?token=abc123', $verifyBody, 'verification template includes the verification URL');
assertContains('24 hours', $verifyBody, 'verification template renders the expiry hours (plural)');
assertFalse(str_contains($verifyBody, $xssPayload), 'verification template NEVER emits the raw XSS payload');
assertContains('&lt;script&gt;alert(document.cookie)&lt;/script&gt;', $verifyBody, 'verification template emits the ESCAPED form of the injected name');
assertFalse(str_contains($verifyBody, '&amp;lt;'), 'verification template does not double-escape the injected name');

// Singular "hour" branch.
[, $verifyBodySingular] = renderEmailTemplate('verification', [
    'verificationUrl' => 'https://cuercode.net/verify?token=xyz',
    'expiryHours'     => 1,
]);
assertContains('1 hour.', $verifyBodySingular, 'verification template uses singular "hour" for expiryHours=1');

// Default recipientName (null) falls back to "Hi there,".
[, $verifyBodyNoName] = renderEmailTemplate('verification', [
    'verificationUrl' => 'https://cuercode.net/verify?token=noname',
]);
assertContains('Hi there,', $verifyBodyNoName, 'verification template falls back to a generic greeting when recipientName is absent');
assertContains('24 hour', $verifyBodyNoName, 'verification template defaults expiryHours to 24 when absent');

// --- password_reset.php -------------------------------------------------------

[$resetSubject, $resetBody] = renderEmailTemplate('password_reset', [
    'recipientName' => 'Jane & Bob "The Builder"',
    'resetUrl'      => 'https://cuercode.net/reset?token=def456',
    'expiryMinutes' => 60,
]);

assertEquals('Reset your CueRCode password', $resetSubject, 'password_reset template subject is correct');
assertContains('<!DOCTYPE html>', $resetBody, 'password_reset template renders a full HTML document');
assertContains('Jane &amp; Bob &quot;The Builder&quot;', $resetBody, 'password_reset template escapes ampersand + quotes in recipientName');
assertFalse(str_contains($resetBody, 'Jane & Bob "The Builder"'), 'password_reset template never emits the raw unescaped name');
assertFalse(str_contains($resetBody, '&amp;amp;'), 'password_reset template does not double-escape the recipient name');
assertContains('60 minutes', $resetBody, 'password_reset template renders the expiry minutes (plural)');

// Singular "minute" branch.
[, $resetBodySingular] = renderEmailTemplate('password_reset', [
    'resetUrl'      => 'https://cuercode.net/reset?token=one',
    'expiryMinutes' => 1,
]);
assertContains('1 minute.', $resetBodySingular, 'password_reset template uses singular "minute" for expiryMinutes=1');

// --- invitation.php ------------------------------------------------------------

[$inviteSubject, $inviteBody] = renderEmailTemplate('invitation', [
    'inviteeName'      => 'New Member',
    'inviterName'      => "O'Brien & Co",
    'organisationName' => '<Acme> Corp',
    'inviteUrl'        => 'https://cuercode.net/invite?token=ghi789',
    'role'             => 'Admin',
    'expiryDays'       => 7,
]);

assertEquals('You have been invited to join <Acme> Corp on CueRCode', $inviteSubject, 'invitation template subject includes the raw org name (subject is plain text, not HTML)');
assertContains('<!DOCTYPE html>', $inviteBody, 'invitation template renders a full HTML document');
assertContains('&lt;Acme&gt; Corp', $inviteBody, 'invitation template HTML-escapes the organisation name in the body');
assertFalse(str_contains($inviteBody, '<Acme> Corp'), 'invitation template never emits the raw unescaped organisation name in the body');
assertContains('O&#039;Brien &amp; Co', $inviteBody, "invitation template escapes the inviter's apostrophe + ampersand");
assertFalse(str_contains($inviteBody, '&amp;amp;'), 'invitation template does not double-escape the inviter name (preheader/title escaping regression guard)');
assertContains('as <strong>Admin</strong>', $inviteBody, 'invitation template renders the optional role clause');
assertContains('7 days', $inviteBody, 'invitation template renders the expiry days (plural)');

// Optional role omitted -> no role clause, no fatal.
[, $inviteBodyNoRole] = renderEmailTemplate('invitation', [
    'inviterName'      => 'Alice',
    'organisationName' => 'Acme',
    'inviteUrl'        => 'https://cuercode.net/invite?token=norole',
]);
// Note: '<strong>' itself still appears around the organisation name (always
// bolded) - the assertion below specifically checks the "as <strong>ROLE</strong>"
// clause is what's absent, not the organisation-name bolding.
assertFalse(str_contains($inviteBodyNoRole, 'as <strong>'), 'invitation template omits the "as <role>" clause entirely when role is absent');
assertContains('7 days', $inviteBodyNoRole, 'invitation template defaults expiryDays to 7 when absent');

// --- receipt.php -----------------------------------------------------------------

[$receiptSubject, $receiptBody] = renderEmailTemplate('receipt', [
    'recipientName'   => 'Test User',
    'orderNumber'     => 'ORD-1001',
    'amount'          => '19.99',
    'currency'        => 'GBP',
    'itemDescription' => '<b>Pro</b> plan upgrade',
    'purchaseDate'    => '2026-07-10',
]);

assertEquals('Your CueRCode receipt (Order ORD-1001)', $receiptSubject, 'receipt template subject includes the order number');
assertContains('<!DOCTYPE html>', $receiptBody, 'receipt template renders a full HTML document');
assertContains('&lt;b&gt;Pro&lt;/b&gt; plan upgrade', $receiptBody, 'receipt template escapes HTML tags injected into the item description');
assertFalse(str_contains($receiptBody, '<b>Pro</b> plan upgrade'), 'receipt template never emits the raw unescaped item description');
assertContains('GBP 19.99', $receiptBody, 'receipt template renders currency + amount together');
assertContains('2026-07-10', $receiptBody, 'receipt template renders the supplied purchase date');

// Default purchaseDate (absent) falls back to today's UTC date, not a fatal.
[, $receiptBodyNoDate] = renderEmailTemplate('receipt', [
    'orderNumber'     => 'ORD-1002',
    'amount'          => '5.00',
    'itemDescription' => 'Basic plan',
]);
assertContains(gmdate('Y-m-d'), $receiptBodyNoDate, 'receipt template defaults purchaseDate to today (UTC) when absent');
assertContains('GBP 5.00', $receiptBodyNoDate, 'receipt template defaults currency to GBP when absent');

// --- renderEmailTemplate() rejects an invalid key before touching the filesystem ---

assertThrows(
    static function (): void {
        renderEmailTemplate('../../etc/passwd', []);
    },
    'renderEmailTemplate() throws for a path-traversal key',
    \InvalidArgumentException::class
);

assertThrows(
    static function (): void {
        renderEmailTemplate('totally_unknown_template', []);
    },
    'renderEmailTemplate() throws for an unlisted template key',
    \InvalidArgumentException::class
);

// ==============================================================================
// ✅ Section 4: computeEmailBackoffMinutes() - pure exponential backoff schedule
// ==============================================================================

assertEquals(1, computeEmailBackoffMinutes(0), 'backoff after 0 attempts is 2^0 = 1 minute');
assertEquals(2, computeEmailBackoffMinutes(1), 'backoff after 1st failed attempt is 2^1 = 2 minutes');
assertEquals(4, computeEmailBackoffMinutes(2), 'backoff after 2nd failed attempt is 2^2 = 4 minutes');
assertEquals(8, computeEmailBackoffMinutes(3), 'backoff after 3rd failed attempt is 2^3 = 8 minutes');
assertEquals(16, computeEmailBackoffMinutes(4), 'backoff after 4th failed attempt is 2^4 = 16 minutes');
assertEquals(32, computeEmailBackoffMinutes(5), 'backoff after 5th failed attempt (default maxAttempts) is 2^5 = 32 minutes');

// 🛡️ Defensive exponent clamp: never let a runaway attempts value produce an
// absurd/overflowing delay. Clamped at 20 -> 2^20 = 1,048,576 minutes.
assertEquals(1048576, computeEmailBackoffMinutes(20), 'backoff exponent clamps at 20 attempts (2^20 minutes)');
assertEquals(1048576, computeEmailBackoffMinutes(999), 'backoff exponent clamps for any value above 20, never grows unbounded');
assertEquals(1, computeEmailBackoffMinutes(-5), 'backoff exponent clamps negative input to 0 (never negative delay)');

// ==============================================================================
// ✅ Section 5: getGraphAccessToken() / sendEmailNow() degrade gracefully
// ==============================================================================
// Neither fn_config.php NOR fn_activity_log.php is loaded in this harness, so
// function_exists('getConfig') is FALSE - the exact same state as a request
// where the config subsystem failed to initialise. Both functions must return
// a safe default IMMEDIATELY, with NO fatal error and NO attempt to reach the
// network (there is no possible network path before the function_exists()
// guard - the guard is the very first statement in getGraphAccessToken(), and
// sendEmailNow() calls getGraphAccessToken() before ever touching curl).
// ==============================================================================

$tokenCallStart = microtime(true);
$token          = getGraphAccessToken();
$tokenCallMs    = (microtime(true) - $tokenCallStart) * 1000;

assertEquals(null, $token, 'getGraphAccessToken() returns null when the config subsystem is unavailable (credentials effectively absent)');
// 🕒 A real network call to login.microsoftonline.com could not plausibly
// complete (success OR failure/timeout) in under this ceiling - this is a
// heuristic proxy for "no network call was attempted", not a network mock.
assertTrue($tokenCallMs < 500.0, 'getGraphAccessToken() returns near-instantly (no network call attempted) when unconfigured');

$sendCallStart = microtime(true);
[$sendOk, $sendError] = sendEmailNow('recipient@example.com', 'Test Subject', '<p>Test body</p>');
$sendCallMs = (microtime(true) - $sendCallStart) * 1000;

assertFalse($sendOk, 'sendEmailNow() returns ok=false when the config subsystem is unavailable');
assertEquals('skipped: configuration subsystem not loaded', $sendError, 'sendEmailNow() reports the specific skip reason when getConfig() is unavailable');
assertTrue($sendCallMs < 500.0, 'sendEmailNow() returns near-instantly (no network call attempted) when unconfigured');

// ==============================================================================
// ✅ Section 6: maybeProcessEmailQueueOnShutdown() - gated no-op when unconfigured
// ==============================================================================

// Must not fatal, must not register a shutdown function that could later
// surface an error, when getConfig() itself is unavailable.
$shutdownGuardThrew = false;
try {
    maybeProcessEmailQueueOnShutdown();
} catch (\Throwable $e) {
    $shutdownGuardThrew = true;
}
assertFalse($shutdownGuardThrew, 'maybeProcessEmailQueueOnShutdown() never throws when the config subsystem is unavailable');

// ==============================================================================
// ✅ Section 7: queueEmail() / processEmailQueue() FAIL-OPEN without a database
// ==============================================================================
// dbQuery() is never defined in this harness (db_connect.php is never loaded),
// so every DB-dependent branch below must degrade to a safe default rather
// than triggering a PHP fatal ("Call to undefined function dbQuery()").
// ==============================================================================

$queueResultNoDb = queueEmail(
    'recipient@example.com',
    'verification',
    ['verificationUrl' => 'https://cuercode.net/verify?token=nodb'],
    null,
    null
);
assertFalse($queueResultNoDb, 'queueEmail() returns false (not a fatal) when dbQuery() is unavailable');

$queueInvalidKeyResult = queueEmail('recipient@example.com', 'not_a_real_template', [], null, null);
assertFalse($queueInvalidKeyResult, 'queueEmail() rejects an invalid template key BEFORE attempting any database call');

$processSummaryNoDb = processEmailQueue(2);
assertEquals(
    ['processed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0],
    $processSummaryNoDb,
    'processEmailQueue() returns a zeroed summary (not a fatal) when dbQuery() is unavailable'
);

// ⚠️ NOT covered here (needs a live MySQLi connection to a real tblEmailQueue
// table - out of scope for this dependency-free harness, same convention as
// fn_config.php's getConfig()/setConfig() DB-path in test_core.php):
//   - queueEmail() actually INSERTing a row and returning its emailID.
//   - processEmailQueue() claiming a due row (status queued/failed AND
//     nextAttemptAt <= NOW() AND attempts < maxAttempts), transitioning it to
//     'sending', then to 'sent' on success or 'queued'/'failed' with the
//     computeEmailBackoffMinutes() delay applied to nextAttemptAt on failure.
//   - settleEmailQueueSuccess()/settleEmailQueueFailure() UPDATE statements.
//   - The concurrent-claim guard (affected_rows check preventing a double-send).
