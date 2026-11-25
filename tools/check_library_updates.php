<?php
/**
 * ==============================================================================
 * 🔭 CueRCode - Library watch: newer releases and known security advisories
 * ==============================================================================
 *
 * Path: tools/check_library_updates.php
 *
 * Our third-party libraries are downloaded by hand and committed (decision
 * 0011), so GitHub's Dependabot cannot see them. This tool is the replacement
 * (#139). For every library listed in web/CueRCode.net/_libraries/PINNED_VERSIONS.json
 * it:
 *   1. asks the upstream home (GitHub tags or the npm registry) for the newest
 *      stable release and compares it with the pinned version, and
 *   2. asks the OSV advisory database (https://api.osv.dev, no key needed) for
 *      known security advisories that affect the PINNED version.
 *
 * It then writes a plain-English Markdown report and a JSON result.
 *
 * Exit codes:
 *   0   everything checked is up to date (or some lookups failed - see report)
 *   10  at least one newer release exists
 *   20  at least one security advisory affects a pinned version
 *
 * A network failure is NEVER reported as "up to date" and never as an advisory:
 * that library is listed as "could not check" and the run carries on.
 *
 * This tool never changes any library. The report says how to upgrade by hand.
 *
 * Usage:
 *   php tools/check_library_updates.php [--report=FILE] [--json=FILE] [--offline]
 *
 * The version-compare, advisory-matching and report logic is pure (no network);
 * the network part takes an injectable "fetcher" callable so tests can fake it.
 *
 * @package     CueRCode
 * @subpackage  Tools
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       #139
 * @see         docs/decisions/0011-commit-third-party-php-libraries-into-repository.md
 */

// 🔢 Exit codes (documented above).
const LIBWATCH_EXIT_OK       = 0;
const LIBWATCH_EXIT_UPDATES  = 10;
const LIBWATCH_EXIT_ADVISORY = 20;

// ==============================================================================
// 🔢 Version handling
// ==============================================================================

/**
 * 🔍 Break a version string into numbers and an optional pre-release label.
 *
 * Accepts a leading "v", any number of dotted number parts ("1.2", "1.2.3",
 * "1.2.3.4") and a "-alpha.1" style pre-release. "+build" suffixes are ignored.
 *
 * @param string $version Raw version or tag name.
 *
 * @return array{parts: int[], pre: string}|null Null when it is not a version.
 */
function libwatchParseVersion(string $version): ?array
{
    $version = trim($version);
    // Drop a leading v / V (tag style "v5.0.5").
    $version = (string) preg_replace('/^[vV]/', '', $version);
    // Drop build metadata ("+abc").
    $version = (string) preg_replace('/\+.*$/', '', $version);

    if (preg_match('/^(\d+(?:\.\d+)*)(?:-([0-9A-Za-z.\-]+))?$/', $version, $m) !== 1) {
        return null;
    }

    $parts = [];
    foreach (explode('.', $m[1]) as $piece) {
        $parts[] = (int) $piece;
    }

    return [
        'parts' => $parts,
        'pre'   => $m[2] ?? '',
    ];
}

/**
 * ⚖️ Compare two parsed pre-release labels by the semantic-versioning rules.
 *
 * @param string $a Pre-release label ('' means a normal release).
 * @param string $b Pre-release label.
 *
 * @return int -1, 0 or 1.
 */
function libwatchComparePre(string $a, string $b): int
{
    // A normal release is newer than any pre-release of the same number.
    if ($a === '' && $b === '') {
        return 0;
    }
    if ($a === '') {
        return 1;
    }
    if ($b === '') {
        return -1;
    }

    $aIds  = explode('.', $a);
    $bIds  = explode('.', $b);
    $count = max(count($aIds), count($bIds));

    for ($i = 0; $i < $count; $i++) {
        // The shorter list is older when everything before it was equal.
        if (!isset($aIds[$i])) {
            return -1;
        }
        if (!isset($bIds[$i])) {
            return 1;
        }

        $aNum = ctype_digit($aIds[$i]);
        $bNum = ctype_digit($bIds[$i]);

        if ($aNum && $bNum) {
            $cmp = ((int) $aIds[$i]) <=> ((int) $bIds[$i]);
        } elseif ($aNum) {
            $cmp = -1; // numbers rank below words
        } elseif ($bNum) {
            $cmp = 1;
        } else {
            $cmp = strcmp($aIds[$i], $bIds[$i]);
            $cmp = $cmp <=> 0;
        }

        if ($cmp !== 0) {
            return $cmp;
        }
    }

    return 0;
}

/**
 * ⚖️ Compare two version strings.
 *
 * Missing number parts count as zero, so "1.2" equals "1.2.0".
 *
 * @param string $a Version or tag.
 * @param string $b Version or tag.
 *
 * @return int|null -1, 0, 1 - or null if either side is not a version.
 */
function libwatchCompareVersions(string $a, string $b): ?int
{
    $pa = libwatchParseVersion($a);
    $pb = libwatchParseVersion($b);
    if ($pa === null || $pb === null) {
        return null;
    }

    $length = max(count($pa['parts']), count($pb['parts']));
    for ($i = 0; $i < $length; $i++) {
        $cmp = ($pa['parts'][$i] ?? 0) <=> ($pb['parts'][$i] ?? 0);
        if ($cmp !== 0) {
            return $cmp;
        }
    }

    return libwatchComparePre($pa['pre'], $pb['pre']);
}

/**
 * 🏆 Pick the newest STABLE version from a list of tag names.
 *
 * Pre-releases (alpha, beta, rc) are skipped unless nothing else exists.
 * Names that are not versions are skipped.
 *
 * @param string[] $tags Tag names as returned by the upstream.
 *
 * @return string|null The winning tag name as written upstream, or null.
 */
function libwatchNewestStable(array $tags): ?string
{
    $best         = null;
    $bestFallback = null;

    foreach ($tags as $tag) {
        $parsed = libwatchParseVersion((string) $tag);
        if ($parsed === null) {
            continue;
        }

        if ($parsed['pre'] === '') {
            if ($best === null || libwatchCompareVersions((string) $tag, $best) === 1) {
                $best = (string) $tag;
            }
        } elseif ($bestFallback === null || libwatchCompareVersions((string) $tag, $bestFallback) === 1) {
            $bestFallback = (string) $tag;
        }
    }

    return $best ?? $bestFallback;
}

// ==============================================================================
// 🛡️ Advisory matching (OSV format)
// ==============================================================================

/**
 * 🛡️ Does one OSV advisory really cover this package at this version?
 *
 * The OSV query already filters by version, but we double-check locally so a
 * wrong server answer cannot raise a false alarm. When the advisory carries no
 * range we can read (for example only git commit ranges), we assume it DOES
 * apply: failing closed is safer than hiding a possible security problem.
 *
 * @param array<string,mixed> $vuln      One entry from OSV's "vulns" list.
 * @param string              $ecosystem OSV ecosystem name (e.g. "npm").
 * @param string              $name      Package name.
 * @param string              $version   The pinned version.
 *
 * @return bool True when the advisory affects the version.
 */
function libwatchAdvisoryAffects(array $vuln, string $ecosystem, string $name, string $version): bool
{
    // A withdrawn advisory was retracted by its author - ignore it.
    if (!empty($vuln['withdrawn'])) {
        return false;
    }

    $affected = $vuln['affected'] ?? null;
    if (!is_array($affected) || $affected === []) {
        return true; // nothing to check against - fail closed
    }

    $sawMatchingPackage = false;
    $couldJudge         = false;

    foreach ($affected as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $package = $entry['package'] ?? [];
        if (
            strcasecmp((string) ($package['ecosystem'] ?? ''), $ecosystem) !== 0
            || strcasecmp((string) ($package['name'] ?? ''), $name) !== 0
        ) {
            continue;
        }
        $sawMatchingPackage = true;

        // 1) An explicit list of affected versions.
        if (!empty($entry['versions']) && is_array($entry['versions'])) {
            $couldJudge = true;
            foreach ($entry['versions'] as $listed) {
                if (libwatchCompareVersions((string) $listed, $version) === 0) {
                    return true;
                }
            }
        }

        // 2) Ranges made of "introduced / fixed / last_affected" events.
        foreach (($entry['ranges'] ?? []) as $range) {
            $type = (string) ($range['type'] ?? '');
            if (($type !== 'SEMVER' && $type !== 'ECOSYSTEM') || empty($range['events'])) {
                continue;
            }
            $couldJudge = true;
            if (libwatchVersionInRange($version, (array) $range['events'])) {
                return true;
            }
        }
    }

    if (!$sawMatchingPackage) {
        return true; // advisory is about something else we cannot compare - fail closed
    }

    // We understood at least one rule and none of them matched: not affected.
    // We understood nothing: assume affected.
    return !$couldJudge;
}

/**
 * 📏 Is a version inside a range described by OSV events?
 *
 * Events are walked in version order. "introduced" opens the affected window,
 * "fixed" closes it (that version is safe), "last_affected" closes it
 * (that version is still affected).
 *
 * @param string                           $version The version to test.
 * @param array<int,array<string,string>>  $events  OSV range events.
 *
 * @return bool True when the version falls in an affected window.
 */
function libwatchVersionInRange(string $version, array $events): bool
{
    // Collect each event as [kind, boundary-version].
    $steps = [];
    foreach ($events as $event) {
        foreach (['introduced', 'fixed', 'last_affected', 'limit'] as $kind) {
            if (isset($event[$kind])) {
                $steps[] = [$kind, (string) $event[$kind]];
            }
        }
    }

    // Sort by boundary; "0" (the very start) sorts first.
    usort(
        $steps,
        static function (array $x, array $y): int {
            return (libwatchCompareVersions($x[1], $y[1]) ?? 0);
        }
    );

    $affected = false;
    foreach ($steps as [$kind, $boundary]) {
        $cmp = libwatchCompareVersions($version, $boundary);
        if ($cmp === null) {
            return true; // cannot compare - fail closed
        }
        if ($kind === 'introduced' && $cmp >= 0) {
            $affected = true;
        } elseif ($kind === 'fixed' && $cmp >= 0) {
            $affected = false;
        } elseif ($kind === 'last_affected' && $cmp > 0) {
            $affected = false;
        }
    }

    return $affected;
}

/**
 * 🧾 Find the first fixed version an advisory names for a package, if any.
 *
 * @param array<string,mixed> $vuln      One OSV advisory.
 * @param string              $ecosystem OSV ecosystem.
 * @param string              $name      Package name.
 *
 * @return string '' when the advisory names no fix.
 */
function libwatchAdvisoryFixedIn(array $vuln, string $ecosystem, string $name): string
{
    foreach (($vuln['affected'] ?? []) as $entry) {
        $package = $entry['package'] ?? [];
        if (
            strcasecmp((string) ($package['ecosystem'] ?? ''), $ecosystem) !== 0
            || strcasecmp((string) ($package['name'] ?? ''), $name) !== 0
        ) {
            continue;
        }
        foreach (($entry['ranges'] ?? []) as $range) {
            foreach (($range['events'] ?? []) as $event) {
                if (isset($event['fixed'])) {
                    return (string) $event['fixed'];
                }
            }
        }
    }

    return '';
}

// ==============================================================================
// 📋 Reading the pinned list
// ==============================================================================

/**
 * 📋 Build the list of things to watch from PINNED_VERSIONS.json.
 *
 * Merges the "libraries" and "frontend" sections with the "watch" section
 * (where to look upstream). A "versionFrom" entry reads the pinned version from
 * another committed file so there is still only one place to change it.
 *
 * @param array<string,mixed> $manifest Decoded PINNED_VERSIONS.json.
 * @param string              $repoRoot Absolute repository root (for versionFrom).
 *
 * @return array<int,array<string,mixed>> One entry per watched library.
 */
function libwatchBuildPins(array $manifest, string $repoRoot): array
{
    $pins  = [];
    $watch = (array) ($manifest['watch'] ?? []);

    foreach (['libraries', 'frontend'] as $section) {
        foreach ((array) ($manifest[$section] ?? []) as $name => $pin) {
            $version = (string) ($pin['version'] ?? '');

            if ($version === '' && isset($pin['versionFrom']['file'], $pin['versionFrom']['key'])) {
                $path = $repoRoot . DIRECTORY_SEPARATOR
                    . str_replace('/', DIRECTORY_SEPARATOR, (string) $pin['versionFrom']['file']);
                $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
                $version = is_array($data) ? (string) ($data[$pin['versionFrom']['key']] ?? '') : '';
            }

            $config = (array) ($watch[$name] ?? []);

            $pins[] = [
                'name'            => (string) $name,
                'pinned'          => $version,
                // The number to compare with upstream (can differ, e.g. FPDF 1.86 = tag 1.8.6).
                'compareAs'       => (string) ($config['upstreamVersion'] ?? $version),
                'compareAsNote'   => (string) ($config['upstreamVersionNote'] ?? ''),
                'latest'          => $config['latest'] ?? null,
                'osv'             => $config['osv'] ?? null,
                'osvNote'         => (string) ($config['osvNote'] ?? ''),
                'source'          => (string) ($pin['source'] ?? ''),
            ];
        }
    }

    return $pins;
}

// ==============================================================================
// 🌐 Network part (all calls go through the injectable fetcher)
// ==============================================================================

/**
 * 🌐 The real fetcher: one HTTPS request, with a time limit and no redirects to
 *    other schemes. The GitHub token (if any) is sent to api.github.com ONLY.
 *
 * @param string      $url      Full https:// address.
 * @param string|null $jsonBody When set, sent as a JSON POST body.
 *
 * @return array{ok: bool, status: int, body: string, error: string}
 */
function libwatchHttpFetch(string $url, ?string $jsonBody = null): array
{
    $host    = (string) parse_url($url, PHP_URL_HOST);
    $headers = [
        'User-Agent: CueRCode-library-watch',
        'Accept: application/json',
    ];

    // 🔑 Only ever give the token to GitHub's own API host.
    $token = getenv('GITHUB_TOKEN');
    if (is_string($token) && $token !== '' && $host === 'api.github.com') {
        $headers[] = 'Authorization: Bearer ' . $token;
        $headers[] = 'X-GitHub-Api-Version: 2022-11-28';
    }

    $http = [
        'method'          => $jsonBody === null ? 'GET' : 'POST',
        'timeout'         => 20,
        'ignore_errors'   => true, // keep the body of 4xx/5xx replies
        'follow_location' => 1,
        'max_redirects'   => 3,
    ];
    if ($jsonBody !== null) {
        $headers[]       = 'Content-Type: application/json';
        $http['content'] = $jsonBody;
    }
    $http['header'] = implode("\r\n", $headers);

    $context = stream_context_create(['http' => $http]);

    // The warning from a failed connection is turned into our own message.
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        $lastError = error_get_last();
        return [
            'ok'     => false,
            'status' => 0,
            'body'   => '',
            'error'  => 'no answer from ' . $host . ($lastError !== null ? ' (' . $lastError['message'] . ')' : ''),
        ];
    }

    $status = 0;
    // 📌 PHP 8.4+ offers a function for the reply headers (the old magic
    // variable is deprecated in 8.5), and 8.4 is our minimum version.
    $responseHeaders = http_get_last_response_headers();
    if (isset($responseHeaders[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', $responseHeaders[0], $m) === 1) {
        $status = (int) $m[1];
    }

    return [
        'ok'     => $status >= 200 && $status < 300,
        'status' => $status,
        'body'   => (string) $body,
        'error'  => ($status >= 200 && $status < 300) ? '' : $host . ' answered HTTP ' . $status,
    ];
}

/**
 * 🔎 Find the newest stable upstream release for one library.
 *
 * @param array<string,mixed> $latest  The "latest" source from the manifest.
 * @param callable            $fetcher fn(string $url, ?string $jsonBody): array.
 *
 * @return array{version: string, error: string} version '' means "could not check".
 */
function libwatchFetchLatest(array $latest, callable $fetcher): array
{
    $type = (string) ($latest['type'] ?? '');

    if ($type === 'npm') {
        // Scoped names keep their "@" but the slash must be encoded.
        $package = str_replace('%40', '@', rawurlencode((string) $latest['package']));
        $reply   = $fetcher('https://registry.npmjs.org/' . $package . '/latest', null);
        if (empty($reply['ok'])) {
            return ['version' => '', 'error' => (string) ($reply['error'] ?? 'lookup failed')];
        }
        $data = json_decode((string) $reply['body'], true);
        if (!is_array($data) || !isset($data['version']) || libwatchParseVersion((string) $data['version']) === null) {
            return ['version' => '', 'error' => 'npm gave an answer we could not read'];
        }
        return ['version' => (string) $data['version'], 'error' => ''];
    }

    if ($type === 'github_tags') {
        $reply = $fetcher('https://api.github.com/repos/' . $latest['repo'] . '/tags?per_page=100', null);
        if (empty($reply['ok'])) {
            return ['version' => '', 'error' => (string) ($reply['error'] ?? 'lookup failed')];
        }
        $data = json_decode((string) $reply['body'], true);
        if (!is_array($data)) {
            return ['version' => '', 'error' => 'GitHub gave an answer we could not read'];
        }
        $names = [];
        foreach ($data as $row) {
            if (is_array($row) && isset($row['name'])) {
                $names[] = (string) $row['name'];
            }
        }
        $newest = libwatchNewestStable($names);
        if ($newest === null) {
            return ['version' => '', 'error' => 'GitHub listed no version tags'];
        }
        return ['version' => $newest, 'error' => ''];
    }

    return ['version' => '', 'error' => 'no upstream source is configured'];
}

/**
 * 🛡️ Ask OSV for advisories that affect one pinned version.
 *
 * @param array<string,mixed> $osv     {ecosystem, name} from the manifest.
 * @param string              $version Version to test.
 * @param callable            $fetcher Injectable fetcher.
 *
 * @return array{advisories: array<int,array<string,string>>, error: string}
 */
function libwatchFetchAdvisories(array $osv, string $version, callable $fetcher): array
{
    $query = json_encode(
        [
            'version' => $version,
            'package' => ['name' => $osv['name'], 'ecosystem' => $osv['ecosystem']],
        ],
        JSON_UNESCAPED_SLASHES
    );

    $reply = $fetcher('https://api.osv.dev/v1/query', (string) $query);
    if (empty($reply['ok'])) {
        return ['advisories' => [], 'error' => (string) ($reply['error'] ?? 'lookup failed')];
    }

    $data = json_decode((string) $reply['body'], true);
    if (!is_array($data)) {
        return ['advisories' => [], 'error' => 'OSV gave an answer we could not read'];
    }

    $found = [];
    foreach ((array) ($data['vulns'] ?? []) as $vuln) {
        if (!is_array($vuln) || empty($vuln['id'])) {
            continue;
        }
        if (!libwatchAdvisoryAffects($vuln, (string) $osv['ecosystem'], (string) $osv['name'], $version)) {
            continue;
        }
        $found[] = [
            'id'       => (string) $vuln['id'],
            'summary'  => (string) ($vuln['summary'] ?? ''),
            'severity' => (string) ($vuln['database_specific']['severity'] ?? ''),
            'fixedIn'  => libwatchAdvisoryFixedIn($vuln, (string) $osv['ecosystem'], (string) $osv['name']),
        ];
    }

    return ['advisories' => $found, 'error' => ''];
}

// ==============================================================================
// 🧮 Putting it together
// ==============================================================================

/**
 * 🧮 Check every pinned library and return one result structure.
 *
 * @param array<int,array<string,mixed>> $pins    From libwatchBuildPins().
 * @param callable                       $fetcher Injectable fetcher.
 *
 * @return array<string,mixed> {libraries: [...], counts: {...}}
 */
function libwatchCheckAll(array $pins, callable $fetcher): array
{
    $libraries = [];

    foreach ($pins as $pin) {
        $row = [
            'name'            => $pin['name'],
            'pinned'          => $pin['pinned'],
            'compareAsNote'   => $pin['compareAsNote'],
            'source'          => $pin['source'],
            'latest'          => '',
            'updateAvailable' => false,
            'majorUpdate'     => false,
            'advisories'      => [],
            'advisoryChecked' => false,
            'notes'           => [],
            'problems'        => [], // things we could not check
        ];

        // ❓ A pin we cannot read is a problem to report, not a pass.
        if (libwatchParseVersion($pin['compareAs']) === null) {
            $row['problems'][] = 'the pinned version "' . $pin['pinned'] . '" could not be read';
            $libraries[]       = $row;
            continue;
        }

        // 1) Newest release.
        if (is_array($pin['latest'])) {
            $latest = libwatchFetchLatest($pin['latest'], $fetcher);
            if ($latest['version'] === '') {
                $row['problems'][] = 'could not look up the newest release (' . $latest['error'] . ')';
            } else {
                $row['latest'] = $latest['version'];
                $cmp           = libwatchCompareVersions($latest['version'], $pin['compareAs']);
                if ($cmp === 1) {
                    $row['updateAvailable'] = true;
                    $newParsed              = libwatchParseVersion($latest['version']);
                    $oldParsed              = libwatchParseVersion($pin['compareAs']);
                    $row['majorUpdate']     = $newParsed !== null && $oldParsed !== null
                        && ($newParsed['parts'][0] ?? 0) > ($oldParsed['parts'][0] ?? 0);
                }
            }
        } else {
            $row['problems'][] = 'no upstream source is listed for the newest-release check';
        }

        // 2) Security advisories on the pinned version.
        if (is_array($pin['osv'])) {
            $adv = libwatchFetchAdvisories($pin['osv'], $pin['compareAs'], $fetcher);
            if ($adv['error'] !== '') {
                $row['problems'][] = 'could not check security advisories (' . $adv['error'] . ')';
            } else {
                $row['advisoryChecked'] = true;
                $row['advisories']      = $adv['advisories'];
            }
        } else {
            $row['notes'][] = $pin['osvNote'] !== '' ? $pin['osvNote'] : 'No advisory source is listed for this library.';
        }

        $libraries[] = $row;
    }

    $counts = ['total' => count($libraries), 'updates' => 0, 'advisories' => 0, 'unchecked' => 0];
    foreach ($libraries as $row) {
        $counts['updates']    += $row['updateAvailable'] ? 1 : 0;
        $counts['advisories'] += count($row['advisories']) > 0 ? 1 : 0;
        $counts['unchecked']  += count($row['problems']) > 0 ? 1 : 0;
    }

    return ['libraries' => $libraries, 'counts' => $counts];
}

/**
 * 🚦 Turn a result into the process exit code.
 *
 * Advisories beat updates. Lookup problems alone never change the code.
 *
 * @param array<string,mixed> $result From libwatchCheckAll().
 *
 * @return int 0, 10 or 20.
 */
function libwatchExitCode(array $result): int
{
    if (($result['counts']['advisories'] ?? 0) > 0) {
        return LIBWATCH_EXIT_ADVISORY;
    }
    if (($result['counts']['updates'] ?? 0) > 0) {
        return LIBWATCH_EXIT_UPDATES;
    }

    return LIBWATCH_EXIT_OK;
}

// ==============================================================================
// 📝 Report
// ==============================================================================

/**
 * 🧼 Make text from outside safe to paste into a GitHub issue.
 *
 * Single line, trimmed, no HTML, no pipe characters (they break tables) and
 * no "@" (which would ping a stranger on GitHub).
 *
 * @param string $text   Raw text from an upstream feed.
 * @param int    $maxLen Longest result.
 *
 * @return string Safe text.
 */
function libwatchCleanText(string $text, int $maxLen = 200): string
{
    $text = (string) preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);
    if (function_exists('mb_substr') && mb_strlen($text) > $maxLen) {
        $text = mb_substr($text, 0, $maxLen - 3) . '...';
    } elseif (strlen($text) > $maxLen) {
        $text = substr($text, 0, $maxLen - 3) . '...';
    }

    return str_replace(
        ['<', '>', '|', '@', '`'],
        ['&lt;', '&gt;', '/', '(at)', "'"],
        $text
    );
}

/**
 * 📝 Write the plain-English Markdown report.
 *
 * @param array<string,mixed> $result From libwatchCheckAll().
 * @param string              $today  Date to print (YYYY-MM-DD).
 *
 * @return string Markdown.
 */
function libwatchRenderReport(array $result, string $today): string
{
    $counts = $result['counts'];
    $lines  = [];

    $lines[] = '## Library watch - ' . $today;
    $lines[] = '';

    // 🧭 One-sentence headline first.
    if ($counts['advisories'] > 0) {
        $lines[] = '**Security warning:** ' . $counts['advisories'] . ' of our ' . $counts['total']
            . ' libraries have a known security advisory that affects the version we use. These need attention first.';
    } elseif ($counts['updates'] > 0) {
        $lines[] = $counts['updates'] . ' of our ' . $counts['total']
            . ' libraries have a newer release. No known security advisories affect the versions we use.';
    } elseif ($counts['unchecked'] === 0) {
        $lines[] = 'All ' . $counts['total'] . ' libraries are up to date and no known security advisories affect them.';
    } else {
        $lines[] = 'Nothing newer or unsafe was found, but some checks could not be done (see below). '
            . 'That is not the same as "all clear".';
    }
    if ($counts['unchecked'] > 0 && ($counts['advisories'] > 0 || $counts['updates'] > 0)) {
        $lines[] = '';
        $lines[] = '**Could not check everything:** ' . $counts['unchecked']
            . ' libraries had a lookup that failed (listed below). Their results above may be incomplete.';
    }
    $lines[] = '';

    // 📊 Summary table (data, not layout).
    $lines[] = '| Library | We use | Newest | Security advisories |';
    $lines[] = '| --- | --- | --- | --- |';
    foreach ($result['libraries'] as $row) {
        $newest = $row['latest'] !== '' ? libwatchCleanText($row['latest'], 40) : 'could not check';
        if ($row['updateAvailable']) {
            $newest .= $row['majorUpdate'] ? ' (new major version)' : ' (update available)';
        } elseif ($row['latest'] !== '') {
            $newest .= ' (up to date)';
        }

        if (count($row['advisories']) > 0) {
            $adv = count($row['advisories']) . ' affect this version';
        } elseif ($row['advisoryChecked']) {
            $adv = 'none known';
        } elseif ($row['problems'] === [] || $row['notes'] !== []) {
            // No lookup failed - there is simply no advisory feed for this one.
            $adv = 'no advisory feed';
        } else {
            $adv = 'could not check';
        }

        $lines[] = '| ' . libwatchCleanText($row['name'], 60) . ' | ' . libwatchCleanText($row['pinned'], 40)
            . ' | ' . $newest . ' | ' . $adv . ' |';
    }
    $lines[] = '';

    // 🛡️ Advisory details.
    if ($counts['advisories'] > 0) {
        $lines[] = '### Security advisories';
        $lines[] = '';
        foreach ($result['libraries'] as $row) {
            foreach ($row['advisories'] as $adv) {
                $line = '- **' . libwatchCleanText($row['name'], 60) . ' ' . libwatchCleanText($row['pinned'], 40) . '**: '
                    . '[' . libwatchCleanText($adv['id'], 60) . '](https://osv.dev/vulnerability/' . rawurlencode($adv['id']) . ')';
                if ($adv['severity'] !== '') {
                    $line .= ' (severity ' . libwatchCleanText($adv['severity'], 20) . ')';
                }
                if ($adv['summary'] !== '') {
                    $line .= ' - ' . libwatchCleanText($adv['summary']);
                }
                $line .= $adv['fixedIn'] !== ''
                    ? '. Fixed in ' . libwatchCleanText($adv['fixedIn'], 40) . '.'
                    : '. No fixed version is named.';
                $lines[] = $line;
            }
        }
        $lines[] = '';
    }

    // ⚠️ Lookups that failed.
    if ($counts['unchecked'] > 0) {
        $lines[] = '### Could not check';
        $lines[] = '';
        foreach ($result['libraries'] as $row) {
            foreach ($row['problems'] as $problem) {
                $lines[] = '- **' . libwatchCleanText($row['name'], 60) . '**: ' . libwatchCleanText($problem, 300) . '.';
            }
        }
        $lines[] = '';
        $lines[] = 'A failed lookup is usually a short outage at the other end. It will be tried again next month, '
            . 'or run the "Library watch" workflow by hand.';
        $lines[] = '';
    }

    // 📝 Notes (for example "no advisory feed for artwork").
    $noteLines = [];
    foreach ($result['libraries'] as $row) {
        foreach ($row['notes'] as $note) {
            $noteLines[] = '- ' . libwatchCleanText($row['name'], 60) . ': ' . libwatchCleanText($note, 300);
        }
        if ($row['compareAsNote'] !== '') {
            $noteLines[] = '- ' . libwatchCleanText($row['name'], 60) . ': ' . libwatchCleanText($row['compareAsNote'], 300);
        }
    }
    if ($noteLines !== []) {
        $lines[] = '### Notes';
        $lines[] = '';
        foreach ($noteLines as $noteLine) {
            $lines[] = $noteLine;
        }
        $lines[] = '';
    }

    // 🔧 How to upgrade (only when there is something to do).
    if ($counts['advisories'] > 0 || $counts['updates'] > 0) {
        $lines[] = '### How to upgrade a library (nothing is changed automatically)';
        $lines[] = '';
        $lines[] = '1. Download the new release from the library\'s own home (the link is in `web/CueRCode.net/_libraries/PINNED_VERSIONS.json`).';
        $lines[] = '2. Replace the library\'s folder with the new files. Do not edit the files by hand.';
        $lines[] = '3. Change the version in `PINNED_VERSIONS.json` (and the version noted in `.claude/CLAUDE.md` and the `/attributions` page if they name it).';
        $lines[] = '4. Run `php tools/check_vendored_libraries.php --update` to refresh the fingerprints, then read the diff.';
        $lines[] = '5. Run `php tests/run.php`, check the pages that use the library in a browser, then commit.';
        $lines[] = '';
        $lines[] = 'Front-end libraries (Bootstrap, jQuery, Font Awesome, Swagger UI) also have a CDN address with an integrity hash in the page templates and the Content Security Policy; update those at the same time (`php tools/check_site_polish.php` checks the hashes).';
        $lines[] = '';
    }

    $lines[] = '_Written by `tools/check_library_updates.php` via `.github/workflows/library-watch.yml` (#139). '
        . 'This issue is kept up to date each month and is never closed automatically._';

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

// ==============================================================================
// 🖥️ Command-line entry point (skipped when a test includes this file)
// ==============================================================================
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $repoRoot     = dirname(__DIR__);
    $manifestPath = $repoRoot . '/web/CueRCode.net/_libraries/PINNED_VERSIONS.json';
    $manifest     = json_decode((string) file_get_contents($manifestPath), true);

    if (!is_array($manifest)) {
        fwrite(STDERR, 'Cannot read ' . $manifestPath . PHP_EOL);
        exit(2);
    }

    $reportPath = null;
    $jsonPath   = null;
    $offline    = false;
    foreach (array_slice($argv, 1) as $arg) {
        if (strpos($arg, '--report=') === 0) {
            $reportPath = substr($arg, 9);
        } elseif (strpos($arg, '--json=') === 0) {
            $jsonPath = substr($arg, 7);
        } elseif ($arg === '--offline') {
            $offline = true; // pretend every lookup failed (for trying the outage wording)
        }
    }

    $fetcher = $offline
        ? static function (string $url, ?string $body = null): array {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'offline mode'];
        }
        : 'libwatchHttpFetch';

    $pins   = libwatchBuildPins($manifest, $repoRoot);
    $result = libwatchCheckAll($pins, $fetcher);
    $code   = libwatchExitCode($result);
    $report = libwatchRenderReport($result, gmdate('Y-m-d'));

    $result['exitCode'] = $code;

    if ($reportPath !== null) {
        file_put_contents($reportPath, $report);
    }
    if ($jsonPath !== null) {
        file_put_contents($jsonPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    echo $report;
    exit($code);
}
