<?php
/**
 * ==============================================================================
 * ☁️ CueRCode - Refresh the committed list of Cloudflare's address ranges
 * ==============================================================================
 *
 * Path: tools/refresh_cloudflare_ranges.php
 *
 * The site trusts Cloudflare's CF-Connecting-IP header only from Cloudflare's
 * published ranges (decision 0014). Those ranges are committed in
 * web/CueRCode.net/_includes/data/cloudflare_ip_ranges.php, so the live site
 * never has to fetch anything. Cloudflare changes them rarely; this tool
 * fetches the current lists and shows what changed.
 *
 *     php tools/refresh_cloudflare_ranges.php           fetch, check, compare (changes nothing)
 *     php tools/refresh_cloudflare_ranges.php --write   ...and rewrite the data file
 *
 * It fetches BOTH of Cloudflare's published forms - the plain lists
 * (https://www.cloudflare.com/ips-v4 and ips-v6) and the API
 * (https://api.cloudflare.com/client/v4/ips) - and refuses to write unless
 * they agree exactly, every entry is a valid range, and neither list is empty.
 * Commit the rewritten file afterwards.
 *
 * This is a developer tool: tools/ is never deployed. It needs internet
 * access and PHP's curl extension or allow_url_fopen.
 *
 * Exit codes: 0 done (no change, or written) | 1 fetching or checking failed |
 * 2 bad option | 3 a change was found and NOT written (run with --write).
 *
 * @package     CueRCode
 * @subpackage  Tools
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-25 (decision 0014, #31)
 * @see         web/CueRCode.net/_includes/data/cloudflare_ip_ranges.php
 * @see         web/CueRCode.net/_functions/fn_client_ip.php
 * @see         https://www.cloudflare.com/ips/
 */

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
    }
    exit('Access denied.');
}

$refreshRoot     = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'CueRCode.net' . DIRECTORY_SEPARATOR;
$refreshDataFile = $refreshRoot . '_includes' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'cloudflare_ip_ranges.php';

require_once $refreshRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_client_ip.php';

$refreshWrite = false;
foreach (array_slice($argv, 1) as $refreshArgument) {
    if ($refreshArgument === '--write') {
        $refreshWrite = true;
    } else {
        fwrite(STDERR, 'Unknown option. Use --write to rewrite the data file, or no option to only compare.' . PHP_EOL);
        exit(2);
    }
}

/**
 * 🌐 Fetch one URL as text (curl extension first, then file_get_contents).
 *
 * @param string $url The address
 * @return string|null The body, or null on any failure
 */
function refreshFetch(string $url): ?string
{
    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        if ($handle === false) {
            return null;
        }
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_TIMEOUT, 20);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($handle, CURLOPT_USERAGENT, 'CueRCode-refresh-tool');
        $body   = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        if (!is_string($body) || $status !== 200) {
            return null;
        }
        return $body;
    }

    $context = stream_context_create(['http' => ['timeout' => 20, 'header' => "User-Agent: CueRCode-refresh-tool\r\n"]]);
    $body    = @file_get_contents($url, false, $context);
    if (!is_string($body)) {
        return null;
    }
    return $body;
}

/**
 * 📐 Turn a list of range texts into their standard written forms, or fail.
 *    The checking itself is clientIpCheckCloudflareList() in fn_client_ip.php
 *    (unit tested); this only prints its reason.
 *
 * @param array<int, mixed> $entries The texts
 * @param int               $family  4 or 6
 * @return array<int, string>|null The standard forms, or null if ANY entry is bad
 */
function refreshCheckList(array $entries, int $family): ?array
{
    $checked = clientIpCheckCloudflareList($entries, $family);
    if ($checked['problem'] !== '') {
        fwrite(STDERR, 'Refused from Cloudflare\'s data: ' . preg_replace('/[^\x20-\x7E]/', '?', $checked['problem']) . PHP_EOL);
        return null;
    }
    return $checked['texts'];
}

// ==============================================================================
// 🌐 Fetch both forms
// ==============================================================================
$refreshV4Text = refreshFetch('https://www.cloudflare.com/ips-v4');
$refreshV6Text = refreshFetch('https://www.cloudflare.com/ips-v6');
$refreshApi    = refreshFetch('https://api.cloudflare.com/client/v4/ips');

if ($refreshV4Text === null || $refreshV6Text === null || $refreshApi === null) {
    fwrite(STDERR, 'Could not fetch Cloudflare\'s lists (no internet access?). Nothing was changed.' . PHP_EOL);
    exit(1);
}

$refreshV4Lines = preg_split('/\R/', trim($refreshV4Text));
$refreshV6Lines = preg_split('/\R/', trim($refreshV6Text));
$refreshApiData = json_decode($refreshApi, true);

if (!is_array($refreshV4Lines) || !is_array($refreshV6Lines)
    || !is_array($refreshApiData) || !isset($refreshApiData['result']['ipv4_cidrs'], $refreshApiData['result']['ipv6_cidrs'])
    || !is_array($refreshApiData['result']['ipv4_cidrs']) || !is_array($refreshApiData['result']['ipv6_cidrs'])) {
    fwrite(STDERR, 'Cloudflare\'s answer was not in the expected shape. Nothing was changed.' . PHP_EOL);
    exit(1);
}

$refreshV4    = refreshCheckList($refreshV4Lines, 4);
$refreshV6    = refreshCheckList($refreshV6Lines, 6);
$refreshApiV4 = refreshCheckList($refreshApiData['result']['ipv4_cidrs'], 4);
$refreshApiV6 = refreshCheckList($refreshApiData['result']['ipv6_cidrs'], 6);

if ($refreshV4 === null || $refreshV6 === null || $refreshApiV4 === null || $refreshApiV6 === null) {
    fwrite(STDERR, 'A fetched list was empty or held something that is not a valid range. Nothing was changed.' . PHP_EOL);
    exit(1);
}

$refreshSortedA = array_merge($refreshV4, $refreshV6);
$refreshSortedB = array_merge($refreshApiV4, $refreshApiV6);
sort($refreshSortedA);
sort($refreshSortedB);
if ($refreshSortedA !== $refreshSortedB) {
    fwrite(STDERR, 'Cloudflare\'s plain lists and its API disagree. Nothing was changed; try again later.' . PHP_EOL);
    exit(1);
}

// ==============================================================================
// 🔍 Compare with the committed file
// ==============================================================================
$refreshCurrent      = clientIpCloudflareData($refreshDataFile);
$refreshCurrentTexts = [];
foreach ($refreshCurrent['ranges'] as $refreshRange) {
    $refreshCurrentTexts[] = $refreshRange['text'];
}

$refreshAdded   = array_values(array_diff($refreshSortedA, $refreshCurrentTexts));
$refreshRemoved = array_values(array_diff($refreshCurrentTexts, $refreshSortedA));

echo 'Fetched ' . count($refreshV4) . ' IPv4 and ' . count($refreshV6) . ' IPv6 ranges (plain lists and API agree).' . PHP_EOL;
echo 'Committed list: ' . count($refreshCurrentTexts) . ' ranges, fetched on ' . $refreshCurrent['fetchedOn'] . '.' . PHP_EOL;

if ($refreshAdded === [] && $refreshRemoved === []) {
    echo 'No change: the committed list matches Cloudflare\'s.' . PHP_EOL;
    exit(0);
}

foreach ($refreshAdded as $refreshText) {
    echo '  + ' . $refreshText . PHP_EOL;
}
foreach ($refreshRemoved as $refreshText) {
    echo '  - ' . $refreshText . PHP_EOL;
}

if (!$refreshWrite) {
    echo 'Run again with --write to rewrite the data file, then commit it.' . PHP_EOL;
    exit(3);
}

// ==============================================================================
// ✍️ Rewrite the data file (temporary file, then rename)
// ==============================================================================
$refreshSource = (string) file_get_contents($refreshDataFile);
$refreshToday  = gmdate('Y-m-d');

$refreshV4Php = '';
foreach ($refreshV4 as $refreshText) {
    $refreshV4Php .= "        '" . $refreshText . "'," . PHP_EOL;
}
$refreshV6Php = '';
foreach ($refreshV6 as $refreshText) {
    $refreshV6Php .= "        '" . $refreshText . "'," . PHP_EOL;
}

$refreshBodyStart = strpos($refreshSource, 'return [');
if ($refreshBodyStart === false) {
    fwrite(STDERR, 'The data file does not look as expected (no "return ["). Nothing was changed.' . PHP_EOL);
    exit(1);
}

$refreshHeader = substr($refreshSource, 0, $refreshBodyStart);
$refreshHeader = (string) preg_replace('/Fetched on [0-9]{4}-[0-9]{2}-[0-9]{2}/', 'Fetched on ' . $refreshToday, $refreshHeader);

$refreshNew = $refreshHeader
    . 'return [' . PHP_EOL
    . "    'fetchedOn' => '" . $refreshToday . "'," . PHP_EOL
    . "    'sources'   => [" . PHP_EOL
    . "        'https://www.cloudflare.com/ips-v4'," . PHP_EOL
    . "        'https://www.cloudflare.com/ips-v6'," . PHP_EOL
    . "        'https://api.cloudflare.com/client/v4/ips'," . PHP_EOL
    . '    ],' . PHP_EOL
    . "    'ipv4'      => [" . PHP_EOL
    . $refreshV4Php
    . '    ],' . PHP_EOL
    . "    'ipv6'      => [" . PHP_EOL
    . $refreshV6Php
    . '    ],' . PHP_EOL
    . '];' . PHP_EOL;

$refreshTemp = $refreshDataFile . '.tmp';
if (file_put_contents($refreshTemp, $refreshNew) === false || !rename($refreshTemp, $refreshDataFile)) {
    fwrite(STDERR, 'The data file could not be written.' . PHP_EOL);
    exit(1);
}

echo 'Written: ' . $refreshDataFile . ' (fetched on ' . $refreshToday . '). Commit it.' . PHP_EOL;
exit(0);
