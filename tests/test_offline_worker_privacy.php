<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - The offline worker never keeps a private page (fourth-round review, Medium 2)
 * ==============================================================================
 *
 * Path: tests/test_offline_worker_privacy.php
 *
 * WHAT THIS PROVES (plain English). The independent review found that the
 * offline worker (public_html/sw.js) kept full copies of admin pages, which
 * could be read with the network off after signing out. Reads the source only
 * (no browser, no database):
 *   1. /admin and /install are on the worker's private list (never read from
 *      or written to a cache).
 *   2. EVERY place the worker stores a copy first asks mayKeepCopy(), which
 *      refuses a failed response and any response marked "no-store" or
 *      "private" - so a page is never kept against the server's wishes,
 *      whatever its address.
 *   3. The cache version moved on from v4, so the copies older versions kept
 *      are deleted when the new worker starts.
 *   4. Every page is sent with "no-store" by the start-up code itself
 *      (session_cache_limiter('nocache') before session_start()), so this
 *      does not depend on the host's php.ini.
 *
 * The behaviour in a real browser (Microsoft Edge) is recorded in the
 * fourth-round fix report; this test keeps the source from drifting back.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (fourth-round review, Medium 2)
 * @see         web/CueRCode.net/public_html/sw.js
 */

$owSite   = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'CueRCode.net' . DIRECTORY_SEPARATOR;
$owWorker = (string) file_get_contents($owSite . 'public_html' . DIRECTORY_SEPARATOR . 'sw.js');

// ✂️ The worker without its comments (block and line), so a comment can never
//    stand in for code.
$owCode = (string) preg_replace('#/\*.*?\*/#s', '', $owWorker);
$owCode = (string) preg_replace('#^\s*//.*$#m', '', $owCode);

// ==============================================================================
// 1. The private list
// ==============================================================================
preg_match('/var PRIVATE_PATH_PREFIXES = \[(.*?)\];/s', $owCode, $owList);
preg_match_all("/'([^']+)'/", $owList[1] ?? '', $owPrefixes);
foreach (['/admin', '/install', '/account', '/login', '/reset-password'] as $owPrivate) {
    assertTrue(in_array($owPrivate, $owPrefixes[1], true), 'Medium 2: ' . $owPrivate . ' is on the worker\'s private list');
}

// ==============================================================================
// 2. Every stored copy goes through mayKeepCopy()
// ==============================================================================
$owPuts = substr_count($owCode, 'cache.put(');
assertTrue($owPuts >= 4, 'sanity: the worker stores copies in several places (' . $owPuts . ')');
assertEquals($owPuts, substr_count($owCode, 'if (mayKeepCopy(networkResponse)) {'), 'Medium 2: every cache.put() is guarded by mayKeepCopy()');
assertFalse(str_contains($owCode, 'networkResponse && networkResponse.ok'), 'Medium 2: no older "any successful response" check is left');

preg_match('/function mayKeepCopy\(response\) \{(.*?)\n\}/s', $owCode, $owKeep);
$owKeepBody = $owKeep[1] ?? '';
assertContains('!response.ok', $owKeepBody, 'Medium 2: a failed response is never kept');
assertContains("response.headers.get('Cache-Control')", $owKeepBody, 'Medium 2: the server\'s Cache-Control header is read');
assertContains("indexOf('no-store') !== -1", $owKeepBody, 'Medium 2: "no-store" is never kept');
assertContains("indexOf('private') !== -1", $owKeepBody, 'Medium 2: "private" is never kept');
assertContains('.toLowerCase()', $owKeepBody, 'Medium 2: the header is compared in any capitals');

// ==============================================================================
// 3. The version moved on, so old copies are deleted
// ==============================================================================
preg_match("/var CACHE_VERSION = 'cuercode-v(\d+)';/", $owCode, $owVersion);
assertTrue(isset($owVersion[1]) && (int) $owVersion[1] >= 5, 'Medium 2: the cache version is past v4, so the admin copies older workers kept are deleted (v' . ($owVersion[1] ?? '?') . ')');
assertTrue(str_contains($owCode, 'var isActive = ACTIVE_CACHES.indexOf(cacheName) !== -1;') && str_contains($owCode, 'return isCueRCodeCache && !isActive;') && str_contains($owCode, 'return caches.delete(cacheName);'), 'Medium 2: on start the worker deletes every cache that is not one of the current version\'s');

// ==============================================================================
// 4. Every page says "no-store", whatever the host's php.ini says
// ==============================================================================
$owBootstrap = (string) file_get_contents($owSite . '_includes' . DIRECTORY_SEPARATOR . 'bootstrap.php');
$owLimiter   = strpos($owBootstrap, "session_cache_limiter('nocache');");
$owStart     = strpos($owBootstrap, 'session_start();');
assertTrue($owLimiter !== false && $owStart !== false && $owLimiter < $owStart, 'Medium 2: bootstrap.php sets PHP\'s "nocache" header rule (Cache-Control: no-store) before it starts the session');
