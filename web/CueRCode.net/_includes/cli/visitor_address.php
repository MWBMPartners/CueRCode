<?php
/**
 * ==============================================================================
 * 🌍 CueRCode - Visitor address check (command line only, decision 0014)
 * ==============================================================================
 *
 * Path: _includes/cli/visitor_address.php
 *
 * Shows how the site works out a visitor's internet address, using the SAME
 * rules as every page (fn_client_ip.php). A command line has no visitor, so
 * this tool answers "what WOULD the site decide for this request?":
 *
 *     php _includes/cli/visitor_address.php
 *         the settings in force: trusted forwarders and the Cloudflare list
 *     php _includes/cli/visitor_address.php --remote=162.158.1.1 --cf=203.0.113.9
 *         a pretend request from that connecting address with that
 *         CF-Connecting-IP header
 *     php _includes/cli/visitor_address.php --remote=10.0.0.2 --xff="198.51.100.7, 10.0.0.9"
 *         a pretend request with an X-Forwarded-For header
 *     --trusted="10.0.0.0/8, 192.0.2.10"
 *         use this trusted_proxy_ips value instead of the saved one (and do
 *         not open the database at all)
 *
 * The LIVE check - the address your own browser request arrived from - is on
 * the admin overview page (/admin/, "Visitor address check").
 *
 * Exit codes: 0 done | 2 a bad option (nothing was read).
 *
 * 🔒 COMMAND LINE ONLY, the same three layers as admin_access.php: outside
 *    public_html/ (no web address), _includes/.htaccess denies all web
 *    access, and the FIRST statement refuses anything but the command line.
 *
 * @package     CueRCode
 * @subpackage  Security
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-25 (decision 0014, #31)
 * @see         ../../_functions/fn_client_ip.php - the rules
 * @see         ../../public_html/admin/index.php - the live check
 */

// ==============================================================================
// 🚫 Step 0: command line only - the FIRST statement, before loading anything
// ==============================================================================
if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
    }
    exit('Access denied.');
}

// ==============================================================================
// 📦 Step 1: the rules only (no database yet)
// ==============================================================================
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_functions' . DIRECTORY_SEPARATOR . 'fn_client_ip.php';

// ==============================================================================
// 🧾 Step 2: read the options
// ==============================================================================
$visitorArguments = [];
if (isset($_SERVER['argv']) && is_array($_SERVER['argv'])) {
    $visitorArguments = array_slice($_SERVER['argv'], 1);
}

$visitorOptions = ['remote' => null, 'cf' => null, 'xff' => null, 'trusted' => null];
$visitorHelp    = false;
$visitorBad     = '';

foreach ($visitorArguments as $visitorArgument) {
    if (!is_string($visitorArgument)) {
        continue;
    }

    if ($visitorArgument === '--help' || $visitorArgument === '-h') {
        $visitorHelp = true;
        continue;
    }

    $visitorMatched = false;
    foreach (array_keys($visitorOptions) as $visitorName) {
        $visitorPrefix = '--' . $visitorName . '=';
        if (str_starts_with($visitorArgument, $visitorPrefix)) {
            $visitorOptions[$visitorName] = substr($visitorArgument, strlen($visitorPrefix));
            $visitorMatched = true;
        }
    }

    if (!$visitorMatched) {
        $visitorBad = $visitorArgument;
    }
}

$visitorUsage = [
    'Usage:',
    '  php _includes/cli/visitor_address.php                       the settings in force',
    '  php _includes/cli/visitor_address.php --remote=ADDRESS [--cf=ADDRESS] [--xff="A, B"]',
    '                                                              what the site would decide for a pretend request',
    '  --trusted="RANGES"   use this trusted_proxy_ips value instead of the saved one (no database)',
    'The live check for your own browser is on /admin/ ("Visitor address check").',
];

if ($visitorBad !== '') {
    fwrite(STDERR, 'Unknown option: ' . preg_replace('/[^\x20-\x7E]/', '?', $visitorBad) . PHP_EOL . implode(PHP_EOL, $visitorUsage) . PHP_EOL);
    exit(2);
}

if ($visitorHelp) {
    fwrite(STDOUT, implode(PHP_EOL, $visitorUsage) . PHP_EOL);
    exit(0);
}

// ==============================================================================
// ⚙️ Step 3: the trusted forwarders - given, or read from the saved settings
// ==============================================================================
if ($visitorOptions['trusted'] !== null) {
    $visitorParsed  = clientIpParseRangeList($visitorOptions['trusted']);
    $visitorTrusted = ['raw' => $visitorOptions['trusted'], 'ranges' => $visitorParsed['ranges'], 'invalid' => $visitorParsed['invalid']];
    $visitorSettingSource = 'given with --trusted';
} else {
    // 🗄️ The normal site start-up code, for the saved setting.
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap.php';
    restore_error_handler();
    $visitorTrusted = clientIpTrustedProxySetting();
    $visitorSettingSource = 'from the saved trusted_proxy_ips setting';
}

$visitorCloudflare = clientIpCloudflareData();

// ==============================================================================
// 🖨️ Step 4: print
// ==============================================================================
$visitorLines = ['Visitor address check (decision 0014)', ''];

if ($visitorOptions['remote'] === null) {
    $visitorRangeTexts = [];
    foreach ($visitorTrusted['ranges'] as $visitorRange) {
        $visitorRangeTexts[] = $visitorRange['text'];
    }
    $visitorTrustedText = 'none';
    if ($visitorRangeTexts !== []) {
        $visitorTrustedText = implode(', ', $visitorRangeTexts);
    }

    $visitorLines[] = 'Trusted forwarders (' . $visitorSettingSource . '): ' . $visitorTrustedText;
    if ($visitorTrusted['invalid'] !== []) {
        $visitorLines[] = 'Ignored (not valid addresses or ranges): ' . implode(', ', $visitorTrusted['invalid']);
    }
    if ($visitorCloudflare['ok']) {
        $visitorLines[] = 'Cloudflare list: ' . count($visitorCloudflare['ranges']) . ' ranges, fetched on ' . $visitorCloudflare['fetchedOn'];
    } else {
        $visitorLines[] = 'Cloudflare list: MISSING - no Cloudflare address is trusted';
    }
    $visitorLines[] = '';
    $visitorLines[] = 'A command line has no visitor. Add --remote=ADDRESS (and --cf= or --xff=) to see what the site would decide,';
    $visitorLines[] = 'or open /admin/ in a browser for the live check of your own address.';
    fwrite(STDOUT, implode(PHP_EOL, $visitorLines) . PHP_EOL);
    exit(0);
}

$visitorServer = ['REMOTE_ADDR' => $visitorOptions['remote']];
if ($visitorOptions['cf'] !== null) {
    $visitorServer['HTTP_CF_CONNECTING_IP'] = $visitorOptions['cf'];
}
if ($visitorOptions['xff'] !== null) {
    $visitorServer['HTTP_X_FORWARDED_FOR'] = $visitorOptions['xff'];
}

$visitorCheck = clientAddressCheck($visitorServer, $visitorTrusted, $visitorCloudflare);

$visitorLines[] = 'A pretend request (trusted forwarders ' . $visitorSettingSource . '):';
foreach ($visitorCheck['rows'] as $visitorRow) {
    $visitorLines[] = '  ' . $visitorRow['label'] . ': ' . preg_replace('/[^\x20-\x7E]/', '?', $visitorRow['value']);
}
foreach ($visitorCheck['warnings'] as $visitorWarning) {
    $visitorLines[] = 'Note: ' . preg_replace('/[^\x20-\x7E]/', '?', $visitorWarning);
}

fwrite(STDOUT, implode(PHP_EOL, $visitorLines) . PHP_EOL);
exit(0);
