<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Tests for the library watch (#139)
 * ==============================================================================
 *
 * Path: tests/test_library_updates.php
 *
 * Proves tools/check_library_updates.php WITHOUT any network: version parsing
 * and comparing, picking the newest stable tag, advisory matching, the
 * plain-English report, exit codes, outage handling (a fake fetcher that fails),
 * and a static guard on .github/workflows/library-watch.yml (ubuntu-latest,
 * every action pinned by full commit SHA, least-privilege permissions).
 * It also checks the pinned list against the real vendored files.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       #139
 */

require_once dirname(__DIR__) . '/tools/check_library_updates.php';

$root     = dirname(__DIR__);
$manifest = json_decode((string) file_get_contents($root . '/web/CueRCode.net/_libraries/PINNED_VERSIONS.json'), true);

// ------------------------------------------------------------------------------
// 🔢 Parsing
// ------------------------------------------------------------------------------
assertEquals(['parts' => [5, 0, 5], 'pre' => ''], libwatchParseVersion('v5.0.5'), 'v-prefix is dropped');
assertEquals(['parts' => [1, 86], 'pre' => ''], libwatchParseVersion('1.86'), 'two-part version');
assertEquals(['parts' => [1, 2, 3, 4], 'pre' => ''], libwatchParseVersion('1.2.3.4'), 'four-part version');
assertEquals(['parts' => [2, 0, 0], 'pre' => 'rc.1'], libwatchParseVersion('2.0.0-rc.1'), 'pre-release label kept');
assertEquals(['parts' => [1, 0, 0], 'pre' => ''], libwatchParseVersion('1.0.0+build5'), 'build metadata ignored');
assertEquals(null, libwatchParseVersion('latest'), 'a word is not a version');
assertEquals(null, libwatchParseVersion(''), 'empty is not a version');

// ------------------------------------------------------------------------------
// ⚖️ Comparing
// ------------------------------------------------------------------------------
assertEquals(-1, libwatchCompareVersions('5.0.5', '5.0.6'), 'patch is newer');
assertEquals(1, libwatchCompareVersions('v6.0.0', '5.9.9'), 'major beats minor, v ignored');
assertEquals(0, libwatchCompareVersions('1.2', '1.2.0'), 'missing parts count as zero');
assertEquals(-1, libwatchCompareVersions('1.2.3', '1.2.3.1'), 'fourth part counts');
assertEquals(1, libwatchCompareVersions('1.10.0', '1.9.0'), 'numbers compare as numbers not text');
assertEquals(-1, libwatchCompareVersions('2.0.0-rc.1', '2.0.0'), 'pre-release is older than the release');
assertEquals(-1, libwatchCompareVersions('2.0.0-alpha', '2.0.0-beta'), 'alpha before beta');
assertEquals(-1, libwatchCompareVersions('2.0.0-rc.2', '2.0.0-rc.10'), 'numeric pre-release parts compare as numbers');
assertEquals(-1, libwatchCompareVersions('2.0.0-1', '2.0.0-alpha'), 'numeric label ranks below a word');
assertEquals(null, libwatchCompareVersions('abc', '1.0'), 'unreadable gives null');

assertEquals('5.3.9', libwatchNewestStable(['5.3.8', '5.3.9', '6.0.0-alpha1', 'nonsense']), 'newest stable skips pre-releases and junk');
assertEquals('v2.0.0-beta', libwatchNewestStable(['v1.0.0-beta', 'v2.0.0-beta']), 'only pre-releases: newest of them');
assertEquals(null, libwatchNewestStable(['latest', 'main']), 'no version tags gives null');

// ------------------------------------------------------------------------------
// 🛡️ Advisory matching
// ------------------------------------------------------------------------------
$rangeVuln = [
    'id'       => 'GHSA-test-1',
    'affected' => [[
        'package' => ['ecosystem' => 'npm', 'name' => 'bootstrap'],
        'ranges'  => [['type' => 'SEMVER', 'events' => [['introduced' => '0'], ['fixed' => '5.3.4']]]],
    ]],
];
assertTrue(libwatchAdvisoryAffects($rangeVuln, 'npm', 'bootstrap', '5.3.3'), 'version below the fix is affected');
assertFalse(libwatchAdvisoryAffects($rangeVuln, 'npm', 'bootstrap', '5.3.4'), 'the fixed version is safe');
assertFalse(libwatchAdvisoryAffects($rangeVuln, 'npm', 'bootstrap', '5.4.0'), 'later than the fix is safe');
assertEquals('5.3.4', libwatchAdvisoryFixedIn($rangeVuln, 'npm', 'bootstrap'), 'fixed version is reported');

$windowVuln = [
    'id'       => 'GHSA-test-2',
    'affected' => [[
        'package' => ['ecosystem' => 'Packagist', 'name' => 'a/b'],
        'ranges'  => [['type' => 'ECOSYSTEM', 'events' => [['introduced' => '2.0.0'], ['fixed' => '2.5.0']]]],
    ]],
];
assertFalse(libwatchAdvisoryAffects($windowVuln, 'Packagist', 'a/b', '1.9.0'), 'before the window opens is safe');
assertTrue(libwatchAdvisoryAffects($windowVuln, 'Packagist', 'a/b', '2.2.0'), 'inside the window is affected');

$lastVuln = [
    'id'       => 'X',
    'affected' => [[
        'package' => ['ecosystem' => 'npm', 'name' => 'p'],
        'ranges'  => [['type' => 'SEMVER', 'events' => [['introduced' => '1.0.0'], ['last_affected' => '1.4.0']]]],
    ]],
];
assertTrue(libwatchAdvisoryAffects($lastVuln, 'npm', 'p', '1.4.0'), 'last_affected itself is affected');
assertFalse(libwatchAdvisoryAffects($lastVuln, 'npm', 'p', '1.4.1'), 'after last_affected is safe');

$listVuln = [
    'id'       => 'Y',
    'affected' => [['package' => ['ecosystem' => 'npm', 'name' => 'p'], 'versions' => ['1.0.0', '1.0.1']]],
];
assertTrue(libwatchAdvisoryAffects($listVuln, 'npm', 'p', '1.0.1'), 'listed version is affected');
assertFalse(libwatchAdvisoryAffects($listVuln, 'npm', 'p', '1.0.2'), 'unlisted version is not affected');

assertFalse(libwatchAdvisoryAffects(['id' => 'W', 'withdrawn' => '2026-01-01T00:00:00Z'], 'npm', 'p', '1.0.0'), 'withdrawn advisory ignored');
assertTrue(libwatchAdvisoryAffects(['id' => 'Z'], 'npm', 'p', '1.0.0'), 'no detail at all fails closed (treated as affected)');
$gitOnly = ['id' => 'G', 'affected' => [['package' => ['ecosystem' => 'npm', 'name' => 'p'], 'ranges' => [['type' => 'GIT', 'events' => [['introduced' => 'abc']]]]]]];
assertTrue(libwatchAdvisoryAffects($gitOnly, 'npm', 'p', '1.0.0'), 'unreadable range type fails closed');

// ------------------------------------------------------------------------------
// 🌐 A fake upstream: answers by address, no network
// ------------------------------------------------------------------------------
/**
 * Build a fetcher from a map of "address prefix" => reply.
 *
 * @param array<string,array<string,mixed>> $map Address-prefix to reply.
 *
 * @return callable
 */
function libwatchTestFetcher(array $map): callable
{
    return static function (string $url, ?string $body = null) use ($map): array {
        foreach ($map as $prefix => $reply) {
            if (strpos($url, $prefix) === 0) {
                if ($body !== null && isset($reply['needs']) && strpos($body, (string) $reply['needs']) === false) {
                    continue;
                }
                return $reply + ['ok' => true, 'status' => 200, 'error' => ''];
            }
        }
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'no route in test'];
    };
}

$pinsDemo = [
    [
        'name' => 'acme/lib', 'pinned' => '1.0.0', 'compareAs' => '1.0.0', 'compareAsNote' => '', 'source' => '',
        'latest' => ['type' => 'github_tags', 'repo' => 'acme/lib'],
        'osv' => ['ecosystem' => 'Packagist', 'name' => 'acme/lib'], 'osvNote' => '',
    ],
];

// ✅ Up to date, no advisories -> exit 0
$fx = libwatchTestFetcher([
    'https://api.github.com/repos/acme/lib/tags' => ['body' => '[{"name":"v1.0.0"},{"name":"v0.9.0"}]'],
    'https://api.osv.dev/v1/query'               => ['body' => '{}'],
]);
$result = libwatchCheckAll($pinsDemo, $fx);
assertEquals(LIBWATCH_EXIT_OK, libwatchExitCode($result), 'up to date -> exit 0');
assertTrue($result['libraries'][0]['advisoryChecked'], 'advisories were checked');
assertContains('up to date', libwatchRenderReport($result, '2026-12-01'), 'report says up to date');

// 🆕 Update available -> exit 10
$fx = libwatchTestFetcher([
    'https://api.github.com/repos/acme/lib/tags' => ['body' => '[{"name":"v2.0.0"},{"name":"v1.0.0"},{"name":"v3.0.0-rc1"}]'],
    'https://api.osv.dev/v1/query'               => ['body' => '{}'],
]);
$result = libwatchCheckAll($pinsDemo, $fx);
assertEquals(LIBWATCH_EXIT_UPDATES, libwatchExitCode($result), 'newer release -> exit 10');
assertEquals('v2.0.0', $result['libraries'][0]['latest'], 'pre-release is not offered as the newest');
assertTrue($result['libraries'][0]['majorUpdate'], 'major bump flagged');
$report = libwatchRenderReport($result, '2026-12-01');
assertContains('new major version', $report, 'report flags the major version');
assertContains('How to upgrade', $report, 'report explains how to upgrade');
assertContains('check_vendored_libraries.php --update', $report, 'report names the update command');

// 🚨 Advisory -> exit 20 (beats an update too)
$advisoryBody = json_encode(['vulns' => [[
    'id'                => 'GHSA-aaaa-bbbb-cccc',
    'summary'           => "Bad <b>thing</b> @octocat | pipe\nnewline",
    'database_specific' => ['severity' => 'HIGH'],
    'affected'          => [[
        'package' => ['ecosystem' => 'Packagist', 'name' => 'acme/lib'],
        'ranges'  => [['type' => 'ECOSYSTEM', 'events' => [['introduced' => '0'], ['fixed' => '1.0.1']]]],
    ]],
], [
    // The server wrongly returns an advisory that does not cover our version: ignored.
    'id'       => 'GHSA-not-ours',
    'affected' => [[
        'package' => ['ecosystem' => 'Packagist', 'name' => 'acme/lib'],
        'ranges'  => [['type' => 'ECOSYSTEM', 'events' => [['introduced' => '5.0.0']]]],
    ]],
]]]);
$fx = libwatchTestFetcher([
    'https://api.github.com/repos/acme/lib/tags' => ['body' => '[{"name":"v2.0.0"}]'],
    'https://api.osv.dev/v1/query'               => ['body' => (string) $advisoryBody, 'needs' => '"version":"1.0.0"'],
]);
$result = libwatchCheckAll($pinsDemo, $fx);
assertEquals(LIBWATCH_EXIT_ADVISORY, libwatchExitCode($result), 'advisory -> exit 20 even with an update');
assertEquals(1, count($result['libraries'][0]['advisories']), 'only the advisory that covers our version is kept');
$report = libwatchRenderReport($result, '2026-12-01');
assertContains('Security warning', $report, 'report leads with the security warning');
assertContains('https://osv.dev/vulnerability/GHSA-aaaa-bbbb-cccc', $report, 'report links the advisory');
assertContains('Fixed in 1.0.1', $report, 'report gives the fixed version');
assertFalse(strpos($report, '@octocat') !== false, 'an @name from upstream text is defused');
assertFalse(strpos($report, '<b>') !== false, 'HTML from upstream text is escaped');
assertFalse(strpos($report, "pipe\nnewline") !== false, 'newlines from upstream text are flattened');

// 🔌 Outage: every lookup fails -> exit 0, "could not check", never "up to date"
$down   = static function (string $url, ?string $body = null): array {
    return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'no answer from test'];
};
$result = libwatchCheckAll($pinsDemo, $down);
assertEquals(LIBWATCH_EXIT_OK, libwatchExitCode($result), 'outage alone is exit 0, not 20');
assertEquals(1, $result['counts']['unchecked'], 'the library is counted as unchecked');
$report = libwatchRenderReport($result, '2026-12-01');
assertContains('could not check', $report, 'outage is stated in the table');
assertContains('not the same as "all clear"', $report, 'outage is not reported as all clear');
assertFalse(strpos($report, '(up to date)') !== false || strpos($report, 'are up to date') !== false, 'an outage never says "up to date"');

// 🔌 Half an outage: release lookup works, advisory lookup fails -> still honest
$fx = libwatchTestFetcher([
    'https://api.github.com/repos/acme/lib/tags' => ['body' => '[{"name":"v1.0.0"}]'],
]);
$result = libwatchCheckAll($pinsDemo, $fx);
assertFalse($result['libraries'][0]['advisoryChecked'], 'failed advisory lookup is not a pass');
assertEquals(1, $result['counts']['unchecked'], 'half-failed library is counted as unchecked');

// 📉 Garbage answers are treated as failures, not as "nothing found"
$fx = libwatchTestFetcher([
    'https://api.github.com/repos/acme/lib/tags' => ['body' => 'not json'],
    'https://api.osv.dev/v1/query'               => ['body' => 'not json'],
]);
$result = libwatchCheckAll($pinsDemo, $fx);
assertEquals(1, $result['counts']['unchecked'], 'unreadable answers count as unchecked');

// 📦 npm lookup, scoped name encoded
$seen = '';
$fx   = static function (string $url, ?string $body = null) use (&$seen): array {
    $seen = $url;
    return ['ok' => true, 'status' => 200, 'body' => '{"version":"7.0.0"}', 'error' => ''];
};
$latest = libwatchFetchLatest(['type' => 'npm', 'package' => '@fortawesome/fontawesome-free'], $fx);
assertEquals('7.0.0', $latest['version'], 'npm version read');
assertEquals('https://registry.npmjs.org/@fortawesome%2Ffontawesome-free/latest', $seen, 'scoped npm name has its slash encoded');

// 🔁 FPDF: "1.86" is compared as the tag number 1.8.6
$fpdfPin = [[
    'name' => 'setasign/fpdf', 'pinned' => '1.86', 'compareAs' => '1.8.6', 'compareAsNote' => 'note', 'source' => '',
    'latest' => ['type' => 'github_tags', 'repo' => 'Setasign/FPDF'], 'osv' => null, 'osvNote' => 'no feed',
]];
$fx = libwatchTestFetcher(['https://api.github.com/repos/Setasign/FPDF/tags' => ['body' => '[{"name":"1.8.6"},{"name":"1.8.5"}]']]);
$result = libwatchCheckAll($fpdfPin, $fx);
assertEquals(LIBWATCH_EXIT_OK, libwatchExitCode($result), 'FPDF 1.86 equals tag 1.8.6 -> no false update');
assertEquals(0, $result['counts']['unchecked'], 'a library with no advisory feed is not "unchecked"');
assertContains('no advisory feed', libwatchRenderReport($result, '2026-12-01'), 'no-feed wording');

// ------------------------------------------------------------------------------
// 📋 The real pinned list
// ------------------------------------------------------------------------------
$pins  = libwatchBuildPins($manifest, $root);
$names = array_column($pins, 'name');
assertEquals(10, count($pins), 'ten libraries are watched');
foreach (['chillerlan/php-qrcode', 'chillerlan/php-settings-container', 'setasign/fpdf', 'tecnickcom/tc-lib-barcode',
          'tecnickcom/tc-lib-color', 'twbs/bootstrap', 'jquery/jquery', 'fortawesome/fontawesome-free',
          'swagger-api/swagger-ui', 'jdecked/twemoji'] as $expected) {
    assertTrue(in_array($expected, $names, true), $expected . ' is watched');
}
foreach ($pins as $pin) {
    assertTrue(libwatchParseVersion($pin['compareAs']) !== null, $pin['name'] . ' has a readable pinned version');
    assertTrue(is_array($pin['latest']), $pin['name'] . ' has an upstream source');
}
$twemoji = $pins[array_search('jdecked/twemoji', $names, true)];
$vendorTag = json_decode((string) file_get_contents($root . '/tools/emoji_vendor_version.json'), true)['vendoredTag'];
assertEquals($vendorTag, $twemoji['pinned'], 'Twemoji version comes from the one emoji marker file');

// 🔗 Front-end pins match the files actually committed (the list cannot drift).
$publicRoot = $root . '/web/CueRCode.net';
foreach ((array) $manifest['frontend'] as $name => $pin) {
    if (!isset($pin['marker'])) {
        continue;
    }
    $file = $publicRoot . '/' . $pin['path'] . '/' . $pin['marker']['file'];
    $head = is_file($file) ? (string) file_get_contents($file, false, null, 0, 4000) : '';
    $found = preg_match($pin['marker']['regex'], $head, $m) === 1 ? $m[1] : '(not found)';
    assertEquals($pin['version'], $found, $name . ' pinned version matches the committed file');
}

// ------------------------------------------------------------------------------
// 🧱 Static guard on the workflow file
// ------------------------------------------------------------------------------
$workflow = (string) file_get_contents($root . '/.github/workflows/library-watch.yml');
assertTrue($workflow !== '', 'workflow file exists');
assertContains("cron: '17 7 1 * *'", $workflow, 'runs monthly on the 1st');
assertContains('workflow_dispatch', $workflow, 'has a manual run button');
assertContains('contents: read', $workflow, 'contents permission is read-only');
assertContains('issues: write', $workflow, 'issues write is granted');
assertFalse(preg_match('/^\s*(contents|pull-requests|actions|packages):\s*write/m', $workflow) === 1, 'no other write permissions');
assertContains('Library watch: updates / advisories', $workflow, 'uses the one tracking issue title');
assertContains('"security"', $workflow, 'security label');
assertContains('"infrastructure"', $workflow, 'infrastructure label');
assertFalse(strpos($workflow, 'issue close') !== false, 'never closes an issue');
assertFalse(strpos($workflow, 'secrets.') !== false, 'no repository secrets used');

preg_match_all('/^\s*runs-on:\s*(\S+)/m', $workflow, $runners);
assertTrue(count($runners[1]) >= 1, 'has at least one job');
foreach ($runners[1] as $runner) {
    assertEquals('ubuntu-latest', $runner, 'every job runs on ubuntu-latest');
}

preg_match_all('/^\s*(?:-\s*)?uses:\s*(\S+)/m', $workflow, $uses);
assertTrue(count($uses[1]) >= 2, 'actions are used');
foreach ($uses[1] as $ref) {
    assertMatches('/^[\w.\-]+\/[\w.\-\/]+@[0-9a-f]{40}$/', $ref, 'action pinned by full commit SHA: ' . $ref);
}
assertEquals(count($uses[1]), preg_match_all('/uses:\s*\S+@[0-9a-f]{40}\s+#\s*v?\d/', $workflow), 'every pinned action carries a version comment');
