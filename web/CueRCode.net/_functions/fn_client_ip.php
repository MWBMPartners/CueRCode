<?php
/**
 * ==============================================================================
 * 🌍 CueRCode - Working out the visitor's internet address (decision 0014)
 * ==============================================================================
 *
 * Path: _functions/fn_client_ip.php
 *
 * Per-visitor limits (sign-in lock-outs, "send me a new link", the API's
 * request limits) and the logs need the VISITOR's internet address. PHP only
 * knows the address of whatever opened the connection to this server
 * (REMOTE_ADDR). When something sits in front of the site - Cloudflare, or a
 * hosting company's own forwarding server - that is the forwarder's address,
 * and the visitor's address arrives in a header the forwarder adds.
 *
 * The danger: anybody can send those headers too. Before decision 0014 the
 * site read the FIRST entry of X-Forwarded-For, which is exactly the part a
 * visitor writes themselves, so a visitor could pick a new "address" for
 * every request and never hit a limit.
 *
 * 📌 The rules (decision 0014,
 *    docs/decisions/0014-client-address-trust-cloudflare-only-from-its-ranges.md):
 *   1. Start from the connecting address (REMOTE_ADDR). It cannot be faked.
 *   2. If it lies inside one of Cloudflare's PUBLISHED ranges
 *      (_includes/data/cloudflare_ip_ranges.php), use Cloudflare's
 *      CF-Connecting-IP header - but only if it holds exactly one valid
 *      address. Otherwise keep the connecting address.
 *   3. Else, if it lies inside a range the owner listed in the
 *      `trusted_proxy_ips` setting (exact addresses or ranges such as
 *      10.0.0.0/8 or 2001:db8::/32, separated by commas), read
 *      X-Forwarded-For from the RIGHT: skip entries that are themselves
 *      forwarders the OWNER listed, and use the first one that is not.
 *      Each forwarder adds the address it saw to the right-hand end, so the
 *      right-most untrusted entry was written by a forwarder we trust; the
 *      entries further left were written by the visitor and are ignored.
 *      A Cloudflare address in the chain is NOT skipped like that (review
 *      finding 4, 2026-11-25): the walk STOPS there. The entry just to its
 *      left is NOT used on its own (final review M-2): a Cloudflare Worker on
 *      anybody's Cloudflare account can send a request straight to our
 *      forwarder and write that entry itself. Instead the CF-Connecting-IP
 *      header - which Cloudflare sets itself - is used when it holds one
 *      valid address; without it, the Cloudflare address that connected to
 *      our forwarder is used (the connecting-address rule, one hop out).
 *   4. Otherwise ignore every forwarded header completely.
 *
 * 📏 Decision 0015 (docs/decisions/0015-trusted-forwarding-list-refuses-very-wide-ranges.md):
 *    the owner's `trusted_proxy_ips` list refuses ranges wider than /8 (IPv4)
 *    or /32 (IPv6), with a plain-English reason, because trusting "every
 *    address on the internet" would let any visitor choose their own address.
 *    The Cloudflare list has its own, looser limit (Cloudflare's widest real
 *    ranges are /12 and /29), so a broken or tampered file cannot trust the
 *    whole internet either.
 *   X-Real-IP is no longer read: it cannot be told apart from one a visitor
 *   sent, and X-Forwarded-For covers every forwarder this site expects.
 *
 * 📌 This file is loaded on EVERY page by bootstrap.php (and by
 *    fn_api_auth.php for the API), so sign-in, forgot-password, sign-up,
 *    "send me a new link", the logs and the API all use the same answer.
 *    Before decision 0014 getClientIP() lived in fn_api_auth.php, which
 *    ordinary pages never load, so those pages silently used REMOTE_ADDR.
 *
 * 📌 resolveClientAddress() is PURE: the server values, the trusted ranges
 *    and the Cloudflare ranges are all passed in, so every case is tested
 *    without a web server (tests/test_client_ip.php). getClientIP() is the
 *    thin wrapper that feeds it the real request.
 *
 * Residual risk, stated plainly: anybody can put their OWN Cloudflare
 * account in front of this server's address. Those requests do come from
 * Cloudflare, so CF-Connecting-IP is trusted. For a visitor passing through
 * Cloudflare, Cloudflare writes that header itself with the address it saw,
 * so it is the real sender's address. For a request MADE by a Cloudflare
 * Worker, Cloudflare's documentation says the header holds the Worker's own
 * (Cloudflare) address - so such requests share one Cloudflare address rather
 * than choosing their own (this relies on Cloudflare's documented behaviour;
 * it was not measured here). Only traffic through the site's OWN Cloudflare
 * zone is guaranteed to carry the visitor's address. Fully closing the side
 * door needs Cloudflare "Authenticated Origin Pulls" or a firewall that only
 * accepts Cloudflare, which are hosting settings, not code.
 *
 * @package     CueRCode
 * @subpackage  Security
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-25 (decision 0014, #31 - Phase 1B review finding I3)
 * @see         _includes/data/cloudflare_ip_ranges.php - Cloudflare's ranges
 * @see         tools/refresh_cloudflare_ranges.php - refreshes that list
 * @see         public_html/admin/index.php - "Visitor address check"
 * @see         _includes/cli/visitor_address.php - the same check on the command line
 * @see         https://developers.cloudflare.com/fundamentals/reference/http-headers/
 * @see         https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/X-Forwarded-For
 * @see         https://www.php.net/manual/en/function.inet-pton.php
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
 * @var int How many X-Forwarded-For entries are looked at, counting from the
 * right. A real chain of forwarders is two or three long; the cap stops a
 * visitor from making the site walk a huge header.
 */
if (!defined('CUERCODE_CLIENT_IP_MAX_FORWARDED_HOPS')) {
    define('CUERCODE_CLIENT_IP_MAX_FORWARDED_HOPS', 20);
}

/**
 * @var int Longest header value read at all (characters). Longer values are
 * treated as missing.
 */
if (!defined('CUERCODE_CLIENT_IP_MAX_HEADER_LENGTH')) {
    define('CUERCODE_CLIENT_IP_MAX_HEADER_LENGTH', 2048);
}

/**
 * @var int Decision 0015: the widest IPv4 range the owner's trusted_proxy_ips
 * list accepts. /8 is about 16.7 million addresses; anything wider is refused.
 */
if (!defined('CUERCODE_TRUSTED_PROXY_MIN_PREFIX_IPV4')) {
    define('CUERCODE_TRUSTED_PROXY_MIN_PREFIX_IPV4', 8);
}

/**
 * @var int Decision 0015: the widest IPv6 range the owner's trusted_proxy_ips
 * list accepts (/32 is the size a whole internet provider is usually given).
 */
if (!defined('CUERCODE_TRUSTED_PROXY_MIN_PREFIX_IPV6')) {
    define('CUERCODE_TRUSTED_PROXY_MIN_PREFIX_IPV6', 32);
}

/**
 * @var int The widest IPv4 range accepted from Cloudflare's published list
 * (Cloudflare's widest on 2026-11-25 is /12). Stops a broken or tampered list
 * from trusting the whole internet.
 */
if (!defined('CUERCODE_CLOUDFLARE_MIN_PREFIX_IPV4')) {
    define('CUERCODE_CLOUDFLARE_MIN_PREFIX_IPV4', 8);
}

/**
 * @var int The widest IPv6 range accepted from Cloudflare's published list
 * (Cloudflare's widest on 2026-11-25 is /29, so /24 leaves room for growth).
 */
if (!defined('CUERCODE_CLOUDFLARE_MIN_PREFIX_IPV6')) {
    define('CUERCODE_CLOUDFLARE_MIN_PREFIX_IPV6', 24);
}

// ==============================================================================
// 🧽 clientIpNormalise() - one address, checked and written the standard way
// ==============================================================================

/**
 * 🧽 Check that a value is exactly ONE internet address and return it in its
 *    standard written form. PURE.
 *
 * - IPv6 is written in lower case and shortened the usual way
 *   ("2001:DB8:0::1" becomes "2001:db8::1").
 * - An IPv4 address written inside IPv6 ("::ffff:192.0.2.1", which some
 *   servers report) becomes plain "192.0.2.1", so it matches IPv4 ranges.
 * - With $allowPort, the "[2001:db8::1]:443" and "192.0.2.1:443" forms some
 *   forwarders write in X-Forwarded-For are accepted (the port is dropped).
 * - Anything else - a list, a name, "unknown", leading zeros, spaces inside -
 *   is refused (null).
 *
 * @param mixed $value     The text to check
 * @param bool  $allowPort Accept (and drop) a ":port" suffix
 * @return string|null The address, or null when the value is not one valid address
 */
function clientIpNormalise(mixed $value, bool $allowPort): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $text = trim($value);

    // 📏 The longest real form is about 50 characters ("[" + IPv6 + "]:65535").
    if ($text === '' || strlen($text) > 64) {
        return null;
    }

    if ($allowPort) {
        $matches = [];
        if (preg_match('/^\[([0-9A-Fa-f:.]+)\](?::[0-9]{1,5})?$/', $text, $matches) === 1) {
            $text = $matches[1];
        } elseif (preg_match('/^([0-9]{1,3}(?:\.[0-9]{1,3}){3}):[0-9]{1,5}$/', $text, $matches) === 1) {
            $text = $matches[1];
        }
    }

    // @see https://www.php.net/manual/en/filter.filters.validate.php (FILTER_VALIDATE_IP)
    if (filter_var($text, FILTER_VALIDATE_IP) === false) {
        return null;
    }

    $packed = inet_pton($text);
    if ($packed === false) {
        return null;
    }

    // 🔁 "::ffff:a.b.c.d" is an IPv4 address in IPv6 clothing - unwrap it.
    if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
        $packed = substr($packed, 12);
    }

    $written = inet_ntop($packed);
    if ($written === false) {
        return null;
    }

    return $written;
}

// ==============================================================================
// 📐 clientIpParseRange() - "10.0.0.0/8", "2001:db8::/32" or one address
// ==============================================================================

/**
 * 📐 Read one range ("192.0.2.0/24", "2001:db8::/32") or one exact address
 *    ("192.0.2.7", which means /32 or /128). PURE.
 *
 * The part after "/" must be a whole number from 0 to 32 (IPv4) or 0 to 128
 * (IPv6), written without leading zeros ("/024" is refused - decision 0015
 * review: the standard way of writing it is "/24", and an odd spelling in a
 * security setting is more likely a mistake than a meaning). Bits after the
 * prefix are cleared, so "192.0.2.7/24" means the same as "192.0.2.0/24".
 *
 * This only checks that the text IS a range. Whether a range is too WIDE to
 * be trusted is a separate question, answered by clientIpRangeTooWideMessage().
 *
 * @param mixed $entry The text
 * @return array{network: string, prefix: int, text: string}|null
 *         network = the packed (binary) address, text = how it is written
 *         back; null when the entry is not a valid range
 */
function clientIpParseRange(mixed $entry): ?array
{
    if (!is_string($entry)) {
        return null;
    }

    $text = trim($entry);
    if ($text === '' || strlen($text) > 64) {
        return null;
    }

    $addressText = $text;
    $prefixText  = null;

    $slashAt = strpos($text, '/');
    if ($slashAt !== false) {
        $addressText = substr($text, 0, $slashAt);
        $prefixText  = substr($text, $slashAt + 1);
    }

    $address = clientIpNormalise($addressText, false);
    if ($address === null) {
        return null;
    }

    $packed = inet_pton($address);
    if ($packed === false) {
        return null;
    }

    $maxPrefix = strlen($packed) * 8;
    $prefix    = $maxPrefix;

    if ($prefixText !== null) {
        // 🔢 "0", or 1-3 digits not starting with 0 ("/024" and "/00" are refused).
        if (preg_match('/^(?:0|[1-9][0-9]{0,2})$/', $prefixText) !== 1) {
            return null;
        }
        $prefix = (int) $prefixText;
        if ($prefix > $maxPrefix) {
            return null;
        }
    }

    // 🧹 Clear the bits after the prefix.
    $network   = '';
    $byteCount = strlen($packed);
    for ($index = 0; $index < $byteCount; $index++) {
        $bitsBefore = $index * 8;
        if ($bitsBefore + 8 <= $prefix) {
            $network .= $packed[$index];
        } elseif ($bitsBefore >= $prefix) {
            $network .= "\0";
        } else {
            $keepBits = $prefix - $bitsBefore;
            $mask     = (0xFF << (8 - $keepBits)) & 0xFF;
            $network .= chr(ord($packed[$index]) & $mask);
        }
    }

    $written = inet_ntop($network);
    if ($written === false) {
        return null;
    }

    return [
        'network' => $network,
        'prefix'  => $prefix,
        'text'    => $written . '/' . $prefix,
    ];
}

// ==============================================================================
// 📋 clientIpParseRangeList() - the trusted_proxy_ips setting
// ==============================================================================

/**
 * 📏 Is this range too wide to trust? Returns a plain-English reason, or null
 *    when it is fine. PURE (decision 0015).
 *
 * "Wider" means a SMALLER number after the slash: /8 covers about 16.7
 * million IPv4 addresses, /0 covers every one of them.
 *
 * @param array $range         From clientIpParseRange()
 * @param int   $minPrefixV4   The smallest IPv4 number after the slash allowed
 * @param int   $minPrefixV6   The smallest IPv6 number after the slash allowed
 * @param bool  $forOwnerList  true = add the advice for the owner's trusted_proxy_ips
 *                             list; false = only the facts (the Cloudflare checks)
 * @return string|null The reason it is refused, or null
 */
function clientIpRangeTooWideMessage(array $range, int $minPrefixV4, int $minPrefixV6, bool $forOwnerList = true): ?string
{
    if (!isset($range['network'], $range['prefix'], $range['text'])) {
        return 'This is not a valid address or range.';
    }

    $isIpv4    = (strlen((string) $range['network']) === 4);
    $minPrefix = $minPrefixV6;
    $family    = 'newer-style (IPv6)';
    if ($isIpv4) {
        $minPrefix = $minPrefixV4;
        $family    = 'older-style (IPv4)';
    }

    if ((int) $range['prefix'] >= $minPrefix) {
        return null;
    }

    $message = '"' . (string) $range['text'] . '" is too wide: for ' . $family . ' addresses the widest range accepted is /'
        . $minPrefix . ' (a smaller number after the slash means more addresses).';

    if ($forOwnerList) {
        $message .= ' A forwarding server is never that big, and trusting such a range would let almost anyone choose'
            . ' the address the site records for them, so the sign-in and request limits would stop working.'
            . ' Enter the forwarding server\'s own address, or its narrower range.';
    }

    return $message;
}

/**
 * 🗒️ Why an entry is not a range at all, in plain English. PURE.
 *
 * @param string $entry The text that clientIpParseRange() refused
 * @return string
 */
function clientIpBadEntryMessage(string $entry): string
{
    // 🔢 The one mistake worth naming on its own: "/024" instead of "/24".
    if (preg_match('/\/0[0-9]+$/', $entry) === 1) {
        return '"' . $entry . '" has a zero at the start of the number after the slash. Write it without the zero (for example /24, not /024).';
    }

    return '"' . $entry . '" is not a valid address or range (for example 192.0.2.10, 10.0.0.0/8 or 2001:db8::/32).';
}

/**
 * 📋 Read a list of ranges separated by commas, spaces or new lines. PURE.
 *
 * Bad entries are never trusted; they are returned separately so the admin
 * check can show them, each with a plain-English reason in 'problems'.
 * Decision 0015: ranges wider than the limits below are bad entries too.
 *
 * @param mixed $setting     The setting value ('' or null = none)
 * @param int   $minPrefixV4 Widest IPv4 range accepted (default: decision 0015's /8)
 * @param int   $minPrefixV6 Widest IPv6 range accepted (default: decision 0015's /32)
 * @return array{ranges: array<int, array>, invalid: array<int, string>, problems: array<int, string>}
 */
function clientIpParseRangeList(
    mixed $setting,
    int $minPrefixV4 = CUERCODE_TRUSTED_PROXY_MIN_PREFIX_IPV4,
    int $minPrefixV6 = CUERCODE_TRUSTED_PROXY_MIN_PREFIX_IPV6
): array {
    $result = ['ranges' => [], 'invalid' => [], 'problems' => []];

    if (!is_string($setting)) {
        return $result;
    }

    $entries = preg_split('/[\s,]+/', $setting);
    if (!is_array($entries)) {
        return $result;
    }

    foreach ($entries as $entry) {
        if ($entry === '') {
            continue;
        }

        $range = clientIpParseRange($entry);
        if ($range === null) {
            $result['invalid'][]  = $entry;
            $result['problems'][] = clientIpBadEntryMessage($entry);
            continue;
        }

        $tooWide = clientIpRangeTooWideMessage($range, $minPrefixV4, $minPrefixV6);
        if ($tooWide !== null) {
            $result['invalid'][]  = $entry;
            $result['problems'][] = $tooWide;
            continue;
        }

        $result['ranges'][] = $range;
    }

    return $result;
}

// ==============================================================================
// 🎯 clientIpInRanges() - is this address inside any of these ranges?
// ==============================================================================

/**
 * 🎯 Is the address inside any of the ranges? PURE.
 *
 * An IPv4 address never matches an IPv6 range and the other way round
 * (IPv4-in-IPv6 addresses were already unwrapped by clientIpNormalise()).
 *
 * @param string $address Address from clientIpNormalise()
 * @param array  $ranges  Ranges from clientIpParseRange()
 * @return bool
 */
function clientIpInRanges(string $address, array $ranges): bool
{
    $packed = inet_pton($address);
    if ($packed === false) {
        return false;
    }

    foreach ($ranges as $range) {
        if (!is_array($range) || !isset($range['network'], $range['prefix'])) {
            continue;
        }

        $network = (string) $range['network'];
        $prefix  = (int) $range['prefix'];

        if (strlen($network) !== strlen($packed)) {
            continue;
        }

        $fullBytes = intdiv($prefix, 8);
        if ($fullBytes > 0 && substr($packed, 0, $fullBytes) !== substr($network, 0, $fullBytes)) {
            continue;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        if ((ord($packed[$fullBytes]) & $mask) === (ord($network[$fullBytes]) & $mask)) {
            return true;
        }
    }

    return false;
}

// ==============================================================================
// 🧭 resolveClientAddress() - THE rule (pure)
// ==============================================================================

/**
 * 🧭 Work out the visitor's address from the request. PURE - everything it
 *    needs is passed in (see the file header for the rules).
 *
 * @param array $server            $_SERVER, or a test's stand-in
 * @param array $trustedProxyRanges From clientIpParseRangeList()['ranges']
 * @param array $cloudflareRanges   From clientIpCloudflareData()['ranges']
 * @return array{
 *     address: string,
 *     connectingAddress: string,
 *     source: string,
 *     fromCloudflare: bool,
 *     fromTrustedProxy: bool,
 *     ignoredHeaders: array<int, string>,
 *     note: string
 * }
 *     address           the address the site will use ('unknown' when even
 *                       the connecting address is missing or invalid)
 *     connectingAddress the connecting address as given ('' when missing)
 *     source            'connection', 'CF-Connecting-IP' or 'X-Forwarded-For'
 *     fromCloudflare    the connecting address is inside a Cloudflare range
 *     fromTrustedProxy  the connecting address is inside a trusted_proxy_ips range
 *     ignoredHeaders    forwarded-address headers that were present but NOT used
 *     note              a plain-English explanation for the admin check
 */
function resolveClientAddress(array $server, array $trustedProxyRanges, array $cloudflareRanges): array
{
    $headerNames = [
        'HTTP_CF_CONNECTING_IP' => 'CF-Connecting-IP',
        'HTTP_X_FORWARDED_FOR'  => 'X-Forwarded-For',
        'HTTP_X_REAL_IP'        => 'X-Real-IP',
        'HTTP_FORWARDED'        => 'Forwarded',
        'HTTP_TRUE_CLIENT_IP'   => 'True-Client-IP',
        'HTTP_X_CLIENT_IP'      => 'X-Client-IP',
    ];

    $presentHeaders = [];
    foreach ($headerNames as $serverKey => $headerName) {
        if (isset($server[$serverKey]) && is_string($server[$serverKey]) && trim($server[$serverKey]) !== '') {
            $presentHeaders[$serverKey] = $headerName;
        }
    }

    $connectingRaw = '';
    if (isset($server['REMOTE_ADDR']) && is_string($server['REMOTE_ADDR'])) {
        $connectingRaw = $server['REMOTE_ADDR'];
    }

    $result = [
        'address'           => 'unknown',
        'connectingAddress' => $connectingRaw,
        'source'            => 'connection',
        'fromCloudflare'    => false,
        'fromTrustedProxy'  => false,
        'ignoredHeaders'    => array_values($presentHeaders),
        'note'              => '',
    ];

    $connecting = clientIpNormalise($connectingRaw, false);
    if ($connecting === null) {
        $result['note'] = 'The connecting address is missing or not a valid address, so no address could be worked out.';
        return $result;
    }

    $result['address'] = $connecting;

    // ☁️ Rule 2: a connection from Cloudflare.
    if (clientIpInRanges($connecting, $cloudflareRanges)) {
        $result['fromCloudflare'] = true;

        $cloudflareHeader = null;
        if (isset($server['HTTP_CF_CONNECTING_IP'])) {
            $cloudflareHeader = clientIpNormalise($server['HTTP_CF_CONNECTING_IP'], false);
        }

        if ($cloudflareHeader === null) {
            $result['note'] = 'The connection comes from Cloudflare, but without one valid address in CF-Connecting-IP, so Cloudflare\'s own address is used.';
            return $result;
        }

        $result['address'] = $cloudflareHeader;
        $result['source']  = 'CF-Connecting-IP';
        unset($presentHeaders['HTTP_CF_CONNECTING_IP']);
        $result['ignoredHeaders'] = array_values($presentHeaders);
        $result['note'] = 'The connection comes from Cloudflare, so Cloudflare\'s CF-Connecting-IP header is used.';
        return $result;
    }

    // 🏢 Rule 3: a connection from a forwarder the owner listed.
    if (clientIpInRanges($connecting, $trustedProxyRanges)) {
        $result['fromTrustedProxy'] = true;

        $forwardedRaw = '';
        if (isset($server['HTTP_X_FORWARDED_FOR']) && is_string($server['HTTP_X_FORWARDED_FOR'])) {
            $forwardedRaw = $server['HTTP_X_FORWARDED_FOR'];
        }

        if (trim($forwardedRaw) === '' || strlen($forwardedRaw) > CUERCODE_CLIENT_IP_MAX_HEADER_LENGTH) {
            $result['note'] = 'The connection comes from a trusted forwarder, but it sent no usable X-Forwarded-For header, so the forwarder\'s own address is used.';
            return $result;
        }

        // 👉 Read from the RIGHT (the end our own forwarders wrote).
        $entries = array_reverse(explode(',', $forwardedRaw));
        $entries = array_slice($entries, 0, CUERCODE_CLIENT_IP_MAX_FORWARDED_HOPS);

        $chosen        = null;
        $lastTrusted   = null;
        $stoppedEarly  = false;
        $cloudflareHop = null;
        $leftOfHop     = null;
        $entryCount    = count($entries);

        for ($index = 0; $index < $entryCount; $index++) {
            $hop = clientIpNormalise($entries[$index], true);

            if ($hop === null) {
                // 🛑 Something that is not an address: stop rather than guess.
                $stoppedEarly = true;
                break;
            }

            // ☁️ A Cloudflare address in the chain: the walk STOPS here
            //    (review finding 4) and nothing to its left is used on its
            //    own (final review M-2, below).
            if (clientIpInRanges($hop, $cloudflareRanges)) {
                $cloudflareHop = $hop;
                if ($index + 1 < $entryCount) {
                    $leftOfHop = clientIpNormalise($entries[$index + 1], true);
                }
                break;
            }

            // 🏢 Only the forwarders the OWNER listed are skipped.
            if (clientIpInRanges($hop, $trustedProxyRanges)) {
                $lastTrusted = $hop;
                continue;
            }

            $chosen = $hop;
            break;
        }

        unset($presentHeaders['HTTP_X_FORWARDED_FOR']);
        $result['ignoredHeaders'] = array_values($presentHeaders);
        $result['source'] = 'X-Forwarded-For';

        if ($cloudflareHop !== null) {
            // ☁️ Final review M-2 (2026-11-25): the X-Forwarded-For entry to
            //    the left of a Cloudflare hop is NOT trusted on its own. When
            //    a Cloudflare Worker (on ANYBODY's Cloudflare account) sends a
            //    request straight to our forwarder, the Worker writes that
            //    entry itself and can put any address there. What Cloudflare
            //    itself vouches for is the CF-Connecting-IP header, which
            //    Cloudflare sets on everything that passes through it. So:
            //      - a valid CF-Connecting-IP is the answer (whether or not
            //        the entry to the left agrees - the note says which);
            //      - with no valid CF-Connecting-IP, the Cloudflare address in
            //        the chain is the answer: it is the address that connected
            //        to our trusted forwarder (the "connecting address" rule,
            //        one hop out), the same fallback as rule 2.
            //    ('fromCloudflare' stays false: it describes the connecting
            //    address, which here is the owner's forwarder.)
            $cloudflareHeader = null;
            if (isset($server['HTTP_CF_CONNECTING_IP'])) {
                $cloudflareHeader = clientIpNormalise($server['HTTP_CF_CONNECTING_IP'], false);
            }

            if ($cloudflareHeader !== null) {
                $result['address'] = $cloudflareHeader;
                $result['source']  = 'CF-Connecting-IP';
                unset($presentHeaders['HTTP_CF_CONNECTING_IP']);
                $result['ignoredHeaders'] = array_values($presentHeaders);
                if ($leftOfHop !== null && $leftOfHop === $cloudflareHeader) {
                    $result['note'] = 'The connection comes from a trusted forwarder and the chain passes through Cloudflare, so Cloudflare\'s CF-Connecting-IP header is used (the X-Forwarded-For entry next to the Cloudflare address agrees with it).';
                } else {
                    $result['note'] = 'The connection comes from a trusted forwarder and the chain passes through Cloudflare, so Cloudflare\'s CF-Connecting-IP header is used. The X-Forwarded-For entry next to the Cloudflare address does not match it and is NOT used: whoever sent the request to Cloudflare may have written it.';
                }
                return $result;
            }

            $result['address'] = $cloudflareHop;
            $result['note'] = 'The connection comes from a trusted forwarder and the chain passes through Cloudflare, but without one valid address in CF-Connecting-IP, so the Cloudflare address that connected to the forwarder is used. X-Forwarded-For entries further left are not used: whoever sent the request to Cloudflare may have written them.';
            return $result;
        }

        if ($chosen !== null) {
            $result['address'] = $chosen;
            $result['note'] = 'The connection comes from a trusted forwarder, so the right-most X-Forwarded-For entry that is not itself a trusted forwarder is used.';
            return $result;
        }

        if ($lastTrusted !== null) {
            $result['address'] = $lastTrusted;
            if ($stoppedEarly) {
                $result['note'] = 'X-Forwarded-For held something that is not an address, so the nearest trusted forwarder\'s address is used.';
            } else {
                $result['note'] = 'Every X-Forwarded-For entry looked at is a trusted forwarder, so the furthest one is used.';
            }
            return $result;
        }

        // 🛑 Nothing usable: the header counts as ignored, not used.
        $result['source'] = 'connection';
        $result['ignoredHeaders'][] = 'X-Forwarded-For';
        $result['note'] = 'The right-most X-Forwarded-For entry is not a valid address, so the forwarder\'s own address is used.';
        return $result;
    }

    // 🚪 Rule 4: anybody else - forwarded headers are ignored completely.
    if ($result['ignoredHeaders'] === []) {
        $result['note'] = 'A direct connection: the connecting address is used.';
    } else {
        $result['note'] = 'The connection does not come from Cloudflare or a trusted forwarder, so its forwarded-address headers are ignored and the connecting address is used.';
    }

    return $result;
}

// ==============================================================================
// ☁️ clientIpCloudflareData() - the committed Cloudflare list
// ==============================================================================

/**
 * ☁️ Load Cloudflare's published ranges from
 *    _includes/data/cloudflare_ip_ranges.php (once per request).
 *
 * A missing or broken file means "trust no Cloudflare address" - the safe
 * direction - and the admin check says so.
 *
 * @param string|null $file A different file (tests only); null = the real one
 * @return array{ok: bool, fetchedOn: string, sources: array<int, string>, ranges: array<int, array>, invalid: array<int, string>}
 */
function clientIpCloudflareData(?string $file = null): array
{
    static $cached = null;

    $useCache = ($file === null);
    if ($useCache && $cached !== null) {
        return $cached;
    }

    if ($file === null) {
        $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . '_includes' . DIRECTORY_SEPARATOR . 'data'
            . DIRECTORY_SEPARATOR . 'cloudflare_ip_ranges.php';
    }

    $result = ['ok' => false, 'fetchedOn' => '', 'sources' => [], 'ranges' => [], 'invalid' => []];

    $data = null;
    if (is_file($file)) {
        $data = include $file;
    }

    if (is_array($data)) {
        if (isset($data['fetchedOn']) && is_string($data['fetchedOn'])) {
            $result['fetchedOn'] = $data['fetchedOn'];
        }
        if (isset($data['sources']) && is_array($data['sources'])) {
            foreach ($data['sources'] as $source) {
                if (is_string($source)) {
                    $result['sources'][] = $source;
                }
            }
        }
        foreach (['ipv4', 'ipv6'] as $family) {
            if (!isset($data[$family]) || !is_array($data[$family])) {
                continue;
            }
            foreach ($data[$family] as $entry) {
                $range = clientIpParseRange($entry);
                // 📏 A range wider than Cloudflare could ever publish (for
                //    example "0.0.0.0/0" from a broken or tampered file) is
                //    never trusted - see CUERCODE_CLOUDFLARE_MIN_PREFIX_*.
                if ($range !== null && clientIpRangeTooWideMessage($range, CUERCODE_CLOUDFLARE_MIN_PREFIX_IPV4, CUERCODE_CLOUDFLARE_MIN_PREFIX_IPV6, false) !== null) {
                    $range = null;
                }
                if ($range === null) {
                    $result['invalid'][] = (string) $entry;
                } else {
                    $result['ranges'][] = $range;
                }
            }
        }
        $result['ok'] = ($result['ranges'] !== []);
    }

    if ($useCache) {
        $cached = $result;
    }

    return $result;
}

// ==============================================================================
// 🔎 clientIpCheckCloudflareList() - check a freshly fetched Cloudflare list
// ==============================================================================

/**
 * 🔎 Check one list fetched from Cloudflare (by tools/refresh_cloudflare_ranges.php)
 *    before it may replace the committed file. PURE.
 *
 * Every entry must be a valid range of the expected family (4 or 6), and no
 * wider than CUERCODE_CLOUDFLARE_MIN_PREFIX_IPV4 / _IPV6 (review nit: a "/0"
 * or similar from a broken answer must never become "trust everybody"). An
 * empty list is refused too. One bad entry refuses the WHOLE list.
 *
 * @param array<int, mixed> $entries The texts as fetched
 * @param int               $family  4 or 6
 * @return array{texts: array<int, string>|null, problem: string}
 *         texts = the standard written forms (null when refused);
 *         problem = a plain-English reason ('' when accepted)
 */
function clientIpCheckCloudflareList(array $entries, int $family): array
{
    $texts = [];

    foreach ($entries as $entry) {
        $range = clientIpParseRange($entry);
        if ($range === null) {
            $shown = '';
            if (is_scalar($entry)) {
                $shown = (string) $entry;
            }
            return ['texts' => null, 'problem' => clientIpBadEntryMessage(mb_substr($shown, 0, 80))];
        }

        $bytes = strlen((string) $range['network']);
        if (($family === 4 && $bytes !== 4) || ($family === 6 && $bytes !== 16)) {
            return ['texts' => null, 'problem' => '"' . (string) $range['text'] . '" is in the wrong list (expected IPv' . $family . ' ranges only).'];
        }

        $tooWide = clientIpRangeTooWideMessage($range, CUERCODE_CLOUDFLARE_MIN_PREFIX_IPV4, CUERCODE_CLOUDFLARE_MIN_PREFIX_IPV6, false);
        if ($tooWide !== null) {
            return ['texts' => null, 'problem' => $tooWide];
        }

        $texts[] = (string) $range['text'];
    }

    if ($texts === []) {
        return ['texts' => null, 'problem' => 'The IPv' . $family . ' list is empty.'];
    }

    return ['texts' => $texts, 'problem' => ''];
}

// ==============================================================================
// ⚙️ clientIpTrustedProxySetting() - the owner's trusted_proxy_ips setting
// ==============================================================================

/**
 * ⚙️ Read and parse the `trusted_proxy_ips` setting (once per request).
 *
 * 🔁 Re-entry guard: reading a setting can fail and log an error, and
 *    logging an error asks for the visitor's address again. While a read is
 *    in progress, a nested call gets "no trusted forwarders" (the safe
 *    answer) instead of looping.
 *
 * @param bool $forget Drop the remembered value (tests and the CLI check)
 * @return array{raw: string, ranges: array<int, array>, invalid: array<int, string>, problems: array<int, string>}
 */
function clientIpTrustedProxySetting(bool $forget = false): array
{
    static $cached = null;
    static $busy   = false;

    $empty = ['raw' => '', 'ranges' => [], 'invalid' => [], 'problems' => []];

    if ($forget) {
        $cached = null;
        return $empty;
    }

    if ($cached !== null) {
        return $cached;
    }

    if ($busy || !function_exists('getConfig')) {
        return $empty;
    }

    $busy = true;
    $raw  = '';

    try {
        $value = getConfig('trusted_proxy_ips', '');
        if (is_string($value)) {
            $raw = $value;
        }
    } catch (\Throwable $e) {
        $raw = '';
    } finally {
        $busy = false;
    }

    $parsed = clientIpParseRangeList($raw);

    $cached = [
        'raw'      => $raw,
        'ranges'   => $parsed['ranges'],
        'invalid'  => $parsed['invalid'],
        'problems' => $parsed['problems'],
    ];

    return $cached;
}

// ==============================================================================
// 🌍 getClientIP() - the visitor's address for THIS request
// ==============================================================================

/**
 * 🌍 The visitor's internet address for the current request, by the rules
 *    in the file header (decision 0014).
 *
 * @return string An IPv4/IPv6 address, or 'unknown'
 *
 * @see resolveClientAddress() - the rule itself
 *
 * @example
 *   $clientIP = getClientIP();   // '203.0.113.9', '2001:db8::1' or 'unknown'
 */
function getClientIP(): string
{
    static $busy = false;

    // 🔁 Re-entry guard (see clientIpTrustedProxySetting()): a nested call
    //    while this one is still running uses the connecting address only.
    if ($busy) {
        $direct = null;
        if (isset($_SERVER['REMOTE_ADDR'])) {
            $direct = clientIpNormalise($_SERVER['REMOTE_ADDR'], false);
        }
        if ($direct === null) {
            return 'unknown';
        }
        return $direct;
    }

    $busy = true;

    try {
        $trusted    = clientIpTrustedProxySetting();
        $cloudflare = clientIpCloudflareData();
        $resolved   = resolveClientAddress($_SERVER, $trusted['ranges'], $cloudflare['ranges']);
    } finally {
        $busy = false;
    }

    return $resolved['address'];
}

// ==============================================================================
// 🩺 clientAddressCheck() - the "Visitor address check" (admin + CLI)
// ==============================================================================

/**
 * 🩺 Everything the owner needs to see to tell whether the site works out
 *    visitor addresses correctly on the live host. PURE.
 *
 * @param array $server         $_SERVER (or the CLI's pretend request)
 * @param array $trustedSetting From clientIpTrustedProxySetting()
 * @param array $cloudflareData From clientIpCloudflareData()
 * @return array{resolved: array, rows: array<int, array{label: string, value: string}>, warnings: array<int, string>}
 */
function clientAddressCheck(array $server, array $trustedSetting, array $cloudflareData): array
{
    $trustedRanges = [];
    if (isset($trustedSetting['ranges']) && is_array($trustedSetting['ranges'])) {
        $trustedRanges = $trustedSetting['ranges'];
    }

    $cloudflareRanges = [];
    if (isset($cloudflareData['ranges']) && is_array($cloudflareData['ranges'])) {
        $cloudflareRanges = $cloudflareData['ranges'];
    }

    $resolved = resolveClientAddress($server, $trustedRanges, $cloudflareRanges);

    $connectingShown = $resolved['connectingAddress'];
    if ($connectingShown === '') {
        $connectingShown = '(none)';
    }

    $headerUsed = 'None - the connecting address itself';
    if ($resolved['source'] === 'CF-Connecting-IP') {
        $headerUsed = 'CF-Connecting-IP (from Cloudflare)';
    } elseif ($resolved['source'] === 'X-Forwarded-For') {
        $headerUsed = 'X-Forwarded-For (from a trusted forwarder)';
    }

    $insideCloudflare = 'No';
    if ($resolved['fromCloudflare']) {
        $insideCloudflare = 'Yes';
    }

    $insideTrusted = 'No';
    if ($resolved['fromTrustedProxy']) {
        $insideTrusted = 'Yes';
    }

    $ignored = 'None';
    if ($resolved['ignoredHeaders'] !== []) {
        $ignored = implode(', ', $resolved['ignoredHeaders']);
    }

    $trustedCount = count($trustedRanges);
    $trustedText  = 'None set';
    if ($trustedCount > 0) {
        $trustedTexts = [];
        foreach ($trustedRanges as $trustedRange) {
            $trustedTexts[] = (string) $trustedRange['text'];
        }
        $trustedText = implode(', ', $trustedTexts);
    }

    $listDate = 'Missing - no Cloudflare address is trusted';
    if (!empty($cloudflareData['ok'])) {
        $listDate = count($cloudflareRanges) . ' ranges, fetched on ' . (string) $cloudflareData['fetchedOn'];
    }

    $rows = [
        ['label' => 'Connecting address', 'value' => $connectingShown],
        ['label' => 'Header used', 'value' => $headerUsed],
        ['label' => 'Address the site will use', 'value' => $resolved['address']],
        ['label' => 'Connecting address inside a Cloudflare range', 'value' => $insideCloudflare],
        ['label' => 'Connecting address inside a trusted forwarder range', 'value' => $insideTrusted],
        ['label' => 'Forwarded headers present but ignored', 'value' => $ignored],
        ['label' => 'Trusted forwarders (trusted_proxy_ips)', 'value' => $trustedText],
        ['label' => 'Cloudflare address list', 'value' => $listDate],
        ['label' => 'Why', 'value' => $resolved['note']],
    ];

    $warnings = [];

    if (isset($trustedSetting['problems']) && is_array($trustedSetting['problems']) && $trustedSetting['problems'] !== []) {
        // 📏 Decision 0015: each refused entry with its own plain reason.
        foreach ($trustedSetting['problems'] as $problem) {
            $warnings[] = 'Ignored trusted_proxy_ips entry: ' . (string) $problem;
        }
    } elseif (isset($trustedSetting['invalid']) && is_array($trustedSetting['invalid']) && $trustedSetting['invalid'] !== []) {
        $warnings[] = 'These trusted_proxy_ips entries are not valid addresses or ranges and are ignored: ' . implode(', ', $trustedSetting['invalid']) . '.';
    }

    if (isset($cloudflareData['invalid']) && is_array($cloudflareData['invalid']) && $cloudflareData['invalid'] !== []) {
        $warnings[] = 'The Cloudflare list has entries that are not valid ranges: ' . implode(', ', $cloudflareData['invalid']) . '.';
    }

    if (!$resolved['fromCloudflare'] && in_array('CF-Connecting-IP', $resolved['ignoredHeaders'], true)) {
        $warnings[] = 'A CF-Connecting-IP header arrived, but the connection does not come from a Cloudflare range. If this site IS behind Cloudflare, the Cloudflare list may be out of date: run tools/refresh_cloudflare_ranges.php. If it is not, someone sent the header themselves and it was rightly ignored.';
    }

    if (!$resolved['fromCloudflare'] && !$resolved['fromTrustedProxy'] && in_array('X-Forwarded-For', $resolved['ignoredHeaders'], true)) {
        $warnings[] = 'An X-Forwarded-For header arrived from a connection that is not trusted, so it was ignored. If your host puts its own forwarding server in front of the site, add that server\'s address or range to the trusted_proxy_ips setting.';
    }

    return ['resolved' => $resolved, 'rows' => $rows, 'warnings' => $warnings];
}
