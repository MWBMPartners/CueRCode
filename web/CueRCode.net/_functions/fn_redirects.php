<?php
/**
 * ==============================================================================
 * 🔗 CueRCode - Dynamic Redirect: Pure Core (Epic E1a, #27/#71/#33)
 * ==============================================================================
 *
 * Path: _functions/fn_redirects.php
 *
 * Stage **S0** of the dynamic-redirect / dual-function QR design
 * (`.dev-team/dynamic_dualfn_design.md`). Everything in this file is a PURE,
 * DB-independent, fully unit-testable function - no `dbQuery()` call, no I/O,
 * no network access anywhere below. This is deliberate: the destination
 * validator is the single most security-critical piece of the whole redirect
 * layer (it is the entire open-redirect/SSRF defence, since the future public
 * `/r/{uuid}` route - Stage S2, NOT built yet - will only ever emit a
 * `Location` header sourced from a value THIS function already approved), so
 * it must be exhaustively testable without a database or a live host.
 *
 * 🔗 Since WP4c (#166) dynamic codes run on Go2My.Link (decision 0027), not
 * a CueRCode /r/ route, and the rules for making, changing and drawing them
 * are here too (the section "Dynamic codes on Go2My.Link: the pure rules"
 * at the end). Still pure: the database work is fn_api_redirects.php.
 *
 * Functions in this file:
 *   - validateRedirectDestination() - the destination allowlist validator
 *     (scheme, userinfo, private/loopback IP literals, self-referential
 *     loops, IDN normalisation, length/control-char guards)
 *   - isValidRedirectUuid()         - UUID v4 shape check for a redirectUUID
 *   - redirectIsWritableBy()        - ownership predicate (mirrors
 *                                     fn_api_qrcodes.php's qrCodeIsWritableBy())
 *   - evaluateDynamicCodeQuota()    - pure quota decision (mirrors
 *                                     fn_features.php's evaluateCreditSpend())
 *   - cuercodeIpInCidr()            - small IPv4/IPv6 CIDR-membership helper
 *     backing the private/loopback/link-local/reserved IP-literal rejection
 *   - dynamicQr*()                  - WP4c (#166): which codes may be drawn,
 *     who may change them, the change planner, the tidy step's choice, the
 *     member answers for Go2My.Link failures, the drawing permission and
 *     the one link-safety hook (dynamicQrCheckDestinationSafety(), #168 -
 *     the only function here that is not pure: it asks the provider through
 *     fn_url_reputation.php, loaded when it is first called)
 *
 * @package     CueRCode
 * @subpackage  Redirects
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 4 (Epic E1a Stage S0, #27/#71/#33)
 * @see         _functions/fn_api_redirects.php  - the DB-touching S1 handlers that call these pure helpers
 * @see         _functions/fn_qr_payloads.php    - buildPayload_dynamic_url(), the S0 payload builder
 * @see         _functions/fn_api_qrcodes.php    - qrCodeIsWritableBy(), the sibling ownership predicate this mirrors
 * @see         .dev-team/dynamic_dualfn_design.md §1.4 - the full destination-validator spec this implements
 * @see         https://www.php.net/manual/en/function.filter-var.php
 * @see         https://www.php.net/manual/en/function.parse-url.php
 * @see         https://www.php.net/manual/en/function.inet-pton.php
 * @see         https://www.php.net/manual/en/function.idn-to-ascii.php
 */

// ==============================================================================
// 🚫 Direct access protection
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
 * @var string UUID v4 format regex for a tblQRRedirects.redirectUUID - the
 * SAME shape as fn_api_qrcodes.php's CUERCODE_QR_UUID_REGEX, but its own,
 * distinctly-named constant: a redirectUUID and a qrUUID are deliberately
 * different identifier namespaces (see the design doc §1.1 - a redirect
 * resource outlives the rendered image it names), so this file never assumes
 * fn_api_qrcodes.php happens to be loaded.
 */
if (!defined('CUERCODE_REDIRECT_UUID_REGEX')) {
    define('CUERCODE_REDIRECT_UUID_REGEX', '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
}

/** @var int Maximum destination URL length (matches tblQRRedirects.destinationURL VARCHAR(2048)). */
if (!defined('CUERCODE_REDIRECT_DESTINATION_MAX_LEN')) {
    define('CUERCODE_REDIRECT_DESTINATION_MAX_LEN', 2048);
}

/**
 * @var array Default self-referential host list for open-redirect-loop
 * rejection (design §1.4-e) - a destination host matching one of these WITH a
 * path starting `/r/` would create a redirect chain back into this platform's
 * own redirect layer (or a nested-laundering chain across the three product
 * domains). Callers may override via $opts['self_hosts'] for testability.
 */
if (!defined('CUERCODE_REDIRECT_SELF_HOSTS')) {
    define('CUERCODE_REDIRECT_SELF_HOSTS', ['cuercode.net', 'cuercode.me', 'qrmy.link']);
}

/**
 * @var array Short-link addresses (WP4c, #166; plan section 0.7). A dynamic
 * code's destination may never be on one of these, or on any address under
 * them (alpha.qrmy.link, www.g2my.link, ...), whatever the path: every path
 * there is itself a short link, so the code would send people on to another
 * short link (a chain that hides where it really goes, and could loop).
 * QRmy.Link is CueRCode's own; go2my.link, g2my.link and lnks.page are
 * Go2My.Link's (decision 0027). Callers may replace the list through
 * $opts['short_link_hosts'] (tests).
 */
if (!defined('CUERCODE_REDIRECT_SHORT_LINK_HOSTS')) {
    define('CUERCODE_REDIRECT_SHORT_LINK_HOSTS', ['qrmy.link', 'go2my.link', 'g2my.link', 'lnks.page']);
}

/** @var string The page a switched-off code points at (lead choice L2; WP4f completes the page). */
if (!defined('CUERCODE_DYNAMIC_QR_UNAVAILABLE_PATH')) {
    define('CUERCODE_DYNAMIC_QR_UNAVAILABLE_PATH', '/unavailable/');
}

/** @var string The only syncState whose code may ever be drawn, downloaded or changed (fn_go2mylink.php go2mylinkSyncStateFor()). */
if (!defined('CUERCODE_DYNAMIC_QR_STATE_ACTIVE')) {
    define('CUERCODE_DYNAMIC_QR_STATE_ACTIVE', 'active');
}

/**
 * @var array CIDR ranges (IPv4 + IPv6) a destination host that is an IP
 * LITERAL must never fall within - loopback, RFC1918/ULA private space,
 * link-local, documentation/benchmark test ranges, multicast, and reserved
 * blocks (design §1.4-d). Deliberately hand-maintained rather than relying
 * solely on PHP's FILTER_FLAG_NO_PRIV_RANGE/FILTER_FLAG_NO_RES_RANGE, which
 * the design doc notes "miss some v6 cases" - this list is checked via
 * cuercodeIpInCidr() below, which is exact bitwise CIDR arithmetic, not a
 * regex guess. The IPv4-mapped IPv6 range (::ffff:0:0/96) is included as
 * defence-in-depth so a private v4 address cannot sneak through disguised as
 * an IPv6-shaped host.
 */
if (!defined('CUERCODE_REDIRECT_DISALLOWED_IP_RANGES')) {
    define('CUERCODE_REDIRECT_DISALLOWED_IP_RANGES', [
        // 🌐 IPv4
        '0.0.0.0/8',            // "this" network
        '10.0.0.0/8',           // RFC1918 private
        '100.64.0.0/10',        // carrier-grade NAT (RFC6598)
        '127.0.0.0/8',          // loopback
        '169.254.0.0/16',       // link-local
        '172.16.0.0/12',        // RFC1918 private
        '192.0.0.0/24',         // IETF protocol assignments
        '192.0.2.0/24',         // TEST-NET-1 (documentation)
        '192.168.0.0/16',       // RFC1918 private
        '198.18.0.0/15',        // benchmark testing
        '198.51.100.0/24',      // TEST-NET-2 (documentation)
        '203.0.113.0/24',       // TEST-NET-3 (documentation)
        '224.0.0.0/4',          // multicast
        '240.0.0.0/4',          // reserved
        '255.255.255.255/32',   // limited broadcast
        // 🌐 IPv6
        '::/128',                // unspecified address
        '::1/128',                // loopback
        '::ffff:0:0/96',         // IPv4-mapped IPv6 (defence-in-depth vs. a hidden private v4)
        '64:ff9b::/96',           // NAT64 well-known prefix
        '100::/64',               // discard-only address block
        '2001:db8::/32',          // documentation
        'fc00::/7',               // unique local addresses (private, RFC4193)
        'fe80::/10',              // link-local
        'ff00::/8',               // multicast
    ]);
}

// ==============================================================================
// 🧮 Pure helpers (no DB, no I/O) - unit-testable in isolation
// ==============================================================================

// ------------------------------------------------------------------------
// 🆔 isValidRedirectUuid() - UUID v4 shape check for a redirectUUID
// ------------------------------------------------------------------------

/**
 * 🆔 Validate that a string is a well-formed UUID v4 (a tblQRRedirects.redirectUUID).
 *
 * @param string $uuid The candidate UUID string.
 *
 * @return bool True if it matches the UUID v4 shape.
 */
function isValidRedirectUuid(string $uuid): bool
{
    return preg_match(CUERCODE_REDIRECT_UUID_REGEX, $uuid) === 1;
}

// ------------------------------------------------------------------------
// 🔢 cuercodeIpInCidr() - Exact IPv4/IPv6 CIDR-membership test
// ------------------------------------------------------------------------

/**
 * 🔢 Is a given IP address within a given CIDR range?
 *
 * Uses inet_pton() to get the raw binary address form (4 bytes for IPv4, 16
 * for IPv6) and then compares the address against the subnet byte-by-byte
 * (whole bytes) plus a masked partial-byte comparison for the remaining bits
 * - exact bitwise arithmetic, never a string-prefix guess, so it is correct
 * for both address families and every prefix length.
 *
 * @param string $ip   The IP address to test (no brackets, e.g. "10.0.0.5" or "fe80::1").
 * @param string $cidr A CIDR range, e.g. "10.0.0.0/8" or "fe80::/10".
 *
 * @return bool True if $ip falls within $cidr; false if it does not, or if
 *              either input is malformed, or the two are different address
 *              families (an IPv4 address is never "in" an IPv6 CIDR or vice
 *              versa - inet_pton() produces different-length binary strings
 *              for the two families, and a length mismatch is treated as a
 *              definitive non-match rather than an error).
 *
 * @see https://www.php.net/manual/en/function.inet-pton.php
 */
function cuercodeIpInCidr(string $ip, string $cidr): bool
{
    $cidrParts = explode('/', $cidr, 2);

    if (count($cidrParts) !== 2) {
        return false;
    }

    $subnet       = $cidrParts[0];
    $prefixLength = (int) $cidrParts[1];

    // 🔕 @-suppressed: inet_pton() emits an E_WARNING for a malformed address,
    // which this function converts into a clean false return instead.
    $ipBinary     = @inet_pton($ip);
    $subnetBinary = @inet_pton($subnet);

    if ($ipBinary === false || $subnetBinary === false) {
        return false;
    }

    // 📌 Different address families (4-byte IPv4 vs. 16-byte IPv6) never match.
    if (strlen($ipBinary) !== strlen($subnetBinary)) {
        return false;
    }

    $addressLengthBytes = strlen($ipBinary);

    if ($prefixLength < 0 || $prefixLength > ($addressLengthBytes * 8)) {
        return false;
    }

    $fullBytesToCompare = intdiv($prefixLength, 8);
    $remainderBits      = $prefixLength % 8;

    // 📌 Compare every WHOLE byte covered by the prefix.
    if ($fullBytesToCompare > 0
        && substr($ipBinary, 0, $fullBytesToCompare) !== substr($subnetBinary, 0, $fullBytesToCompare)
    ) {
        return false;
    }

    // 📌 Compare the remaining PARTIAL byte (if the prefix does not end on a
    // byte boundary) using a bitmask covering only the leading $remainderBits.
    if ($remainderBits > 0) {
        $mask = (~(0xFF >> $remainderBits)) & 0xFF;

        $ipByte     = ord($ipBinary[$fullBytesToCompare]);
        $subnetByte = ord($subnetBinary[$fullBytesToCompare]);

        if (($ipByte & $mask) !== ($subnetByte & $mask)) {
            return false;
        }
    }

    return true;
}

// ------------------------------------------------------------------------
// 🎯 validateRedirectDestination() - The destination allowlist validator
// ------------------------------------------------------------------------

/**
 * 🎯 Validate a caller-supplied dynamic-redirect destination URL.
 *
 * The single security-critical function this whole redirect layer rests on
 * (design doc §1.4): an ALLOWLIST (never a denylist) that a destination must
 * satisfy before it is ever written to `tblQRRedirects.destinationURL`. Every
 * rejection is FAIL-CLOSED - an unrecognised scheme, a malformed URL, or an
 * ambiguous edge case is always rejected, never silently accepted.
 *
 * Ordered checks (mirrors the design doc's own lettering, with one
 * deliberate re-ordering explained inline below):
 *   a. Length ≤ 2048, no control characters/CR/LF (Location-header-injection
 *      belt-and-braces, even though the header this ultimately feeds is
 *      always built from this validated/normalised value alone).
 *   b. Must parse as a URL with a scheme + host. Scheme ALLOWLIST: `https`
 *      only by default; `http` accepted only when `$opts['allow_http']`
 *      is explicitly true (mirrors LAUNCH_DECISIONS.md O-2's
 *      `redirect_allow_http` setting - this function itself never reads
 *      tblSettings, staying pure/DB-independent; a caller wires that
 *      setting through via $opts).
 *   c. Userinfo (`user:pass@host`) is rejected outright (phishing/URL-
 *      spoofing obfuscation).
 *   d. If the host is an IP literal (v4 or v6), it must NOT fall within any
 *      loopback/private/link-local/reserved range (see
 *      CUERCODE_REDIRECT_DISALLOWED_IP_RANGES above) - this is the
 *      structural SSRF defence: even though nothing in S0/S1 ever FETCHES a
 *      destination server-side, validating now means a future fetch-side
 *      feature (URL-reputation checks, link previews) inherits this
 *      protection for free rather than needing to "remember" to add it.
 *   f. Non-ASCII ("internationalised") hostnames are normalised to their
 *      Punycode/ASCII form via idn_to_ascii() when the intl extension is
 *      available; otherwise the destination is rejected outright
 *      (fail-closed beats a half-working IDN implementation on shared
 *      hosting - homograph-phishing is a real risk here).
 *      ⚠️ DELIBERATE RE-ORDERING vs. the design doc's own step lettering:
 *      this happens BEFORE step (e) below, not after. PHP's
 *      FILTER_VALIDATE_URL (used for the final well-formedness check)
 *      rejects a raw non-ASCII hostname outright, so IDN normalisation must
 *      run before that final check - and, as a consequence, before the
 *      self-referential-host string comparison in (e), which compares
 *      against a plain-ASCII list and would otherwise silently never match
 *      a Punycode-equivalent host.
 *   e. Self-referential targets are rejected: a destination host matching
 *      cuercode.net / cuercode.me / qrmy.link (or a "www." prefix of any of
 *      them) WITH a path starting `/r/` would create a redirect loop or a
 *      nested-laundering chain back into this platform's own redirect layer.
 *   g. (Reputation-provider check) - a SEAM for a later stage, not
 *      implemented here; see the design doc §2.3. This function has no
 *      network access by design, so it can never itself perform one.
 *
 * @param string $url  The candidate destination URL, exactly as supplied by
 *                      the caller (untrimmed input is fine - this function
 *                      trims it itself).
 * @param array  $opts Optional overrides, ALL with safe defaults so a normal
 *                      call needs none of them:
 *                        - 'allow_http'  (bool)  Accept `http://` in addition
 *                          to `https://`. Default false (https-only).
 *                        - 'self_hosts'  (array) Override the self-
 *                          referential host list. Default
 *                          CUERCODE_REDIRECT_SELF_HOSTS.
 *
 * @return array{valid: bool, normalized: string, error: ?string} `normalized`
 *         is the canonicalised (lower-cased scheme/host, Punycode host) form
 *         of the destination when valid, or an empty string when not.
 *         `error` is a human-readable rejection reason, or null when valid.
 *
 * @see https://www.php.net/manual/en/function.filter-var.php
 * @see https://www.php.net/manual/en/function.parse-url.php
 * @see https://www.php.net/manual/en/function.idn-to-ascii.php
 */
function validateRedirectDestination(string $url, array $opts = []): array
{
    $invalid = static function (string $error): array {
        return ['valid' => false, 'normalized' => '', 'error' => $error];
    };

    // ==================================================================
    // 🔧 Resolve options with safe defaults.
    // ==================================================================
    if (isset($opts['allow_http'])) {
        $allowHttp = (bool) $opts['allow_http'];
    } else {
        $allowHttp = false;
    }

    if (isset($opts['self_hosts']) && is_array($opts['self_hosts'])) {
        $selfHosts = $opts['self_hosts'];
    } else {
        $selfHosts = CUERCODE_REDIRECT_SELF_HOSTS;
    }

    if (isset($opts['short_link_hosts']) && is_array($opts['short_link_hosts'])) {
        $shortLinkHosts = $opts['short_link_hosts'];
    } else {
        $shortLinkHosts = CUERCODE_REDIRECT_SHORT_LINK_HOSTS;
    }

    // ==================================================================
    // a. Length + control-character guard (on the RAW input, before any
    //    trimming - a control character must never be silently trimmed away
    //    and forgotten about).
    // ==================================================================
    if ($url === '') {
        return $invalid('Destination URL is required.');
    }

    if (strlen($url) > CUERCODE_REDIRECT_DESTINATION_MAX_LEN) {
        return $invalid('Destination URL exceeds the maximum length of ' . CUERCODE_REDIRECT_DESTINATION_MAX_LEN . ' characters.');
    }

    // 🔐 Control characters (incl. CR/LF) anywhere in the raw string - a
    // belt-and-braces Location-header-injection guard, even though the
    // header is always built from the NORMALISED value this function
    // returns, never from raw caller input.
    if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
        return $invalid('Destination URL contains control characters, which are not allowed.');
    }

    $trimmedUrl = trim($url);

    if ($trimmedUrl === '') {
        return $invalid('Destination URL is required.');
    }

    // ==================================================================
    // b (part 1). Parse the URL and confirm scheme + host are present.
    // ==================================================================
    $parts = parse_url($trimmedUrl);

    if ($parts === false || !isset($parts['scheme']) || !isset($parts['host']) || $parts['host'] === '') {
        return $invalid('Destination URL could not be parsed - a scheme (https://) and host are required.');
    }

    $scheme = strtolower($parts['scheme']);

    $allowedSchemes = ['https'];
    if ($allowHttp) {
        $allowedSchemes[] = 'http';
    }

    if (!in_array($scheme, $allowedSchemes, true)) {
        $schemeNote = '';
        if ($allowHttp) {
            $schemeNote = ' (and http, per configuration)';
        }
        return $invalid('Destination scheme "' . $scheme . '" is not allowed. Only https is permitted' . $schemeNote . '.');
    }

    // ==================================================================
    // c. Reject userinfo (user:pass@host) - a classic phishing/URL-spoofing
    //    obfuscation technique.
    // ==================================================================
    if (isset($parts['user']) || isset($parts['pass'])) {
        return $invalid('Destination URL must not contain embedded credentials (user:pass@host).');
    }

    $host = $parts['host'];

    // ==================================================================
    // d. If the host is an IP literal, reject loopback/private/link-local/
    //    reserved ranges (structural SSRF defence - see the docblock above).
    //
    // ⚠️ PHP's parse_url() preserves the square brackets around a bracketed
    // IPv6 host verbatim (e.g. "https://[::1]/" -> host === "[::1]", NOT
    // "::1") - unlike some other URL-parsing libraries. filter_var($host,
    // FILTER_VALIDATE_IP) would therefore never recognise a bracketed IPv6
    // literal as an IP at all, silently falling through to the hostname
    // branch below and skipping the private/loopback check entirely - so
    // the brackets are stripped here ONLY for the IP-literal test, and
    // re-attached afterwards if this host does turn out to be an IPv6
    // literal (both for the disallow-range check, which needs the bare
    // address, and for the final reconstructed URL, which needs the
    // brackets back - a bare IPv6 address without brackets is not valid
    // URL syntax).
    // ==================================================================
    $isBracketedIpv6Literal = str_starts_with($host, '[') && str_ends_with($host, ']') && strlen($host) > 2;

    if ($isBracketedIpv6Literal) {
        $hostForIpCheck = substr($host, 1, -1);
    } else {
        $hostForIpCheck = $host;
    }

    if (filter_var($hostForIpCheck, FILTER_VALIDATE_IP) !== false) {
        foreach (CUERCODE_REDIRECT_DISALLOWED_IP_RANGES as $disallowedRange) {
            if (cuercodeIpInCidr($hostForIpCheck, $disallowedRange)) {
                return $invalid('Destination host resolves to a private, loopback, link-local, or reserved IP address, which is not allowed.');
            }
        }

        // 📌 Canonical form: lower-case the literal (IPv6 hex digits are
        // case-insensitive) and re-attach the brackets if this was an IPv6
        // literal - required for a valid reconstructed URL below.
        if ($isBracketedIpv6Literal) {
            $host = '[' . strtolower($hostForIpCheck) . ']';
        } else {
            $host = strtolower($hostForIpCheck);
        }
    } else {
        // ==============================================================
        // f. Non-ASCII (IDN) hostname normalisation - MUST happen before
        //    the final FILTER_VALIDATE_URL well-formedness check (b, part 2)
        //    and before the self-referential comparison (e) - see the
        //    "DELIBERATE RE-ORDERING" note in this function's own docblock.
        // ==============================================================
        if (preg_match('/[^\x20-\x7E]/', $host) === 1) {
            if (function_exists('idn_to_ascii')) {
                $punycodeHost = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

                if ($punycodeHost === false || $punycodeHost === '') {
                    return $invalid('Destination host is not a valid internationalised domain name.');
                }

                $host = $punycodeHost;
            } else {
                // 🔐 Fail-closed: no IDN support available on this host - a
                // non-ASCII hostname is a homograph-phishing surface, so
                // rejecting beats a half-working conversion.
                return $invalid('Destination host contains non-ASCII characters, which are not supported on this server.');
            }
        }
    }

    // 📌 Lower-case the (now guaranteed-ASCII) host for canonical comparison
    // and storage - DNS hostnames are case-insensitive.
    $host = strtolower($host);

    // ==================================================================
    // e. Self-referential-target / redirect-loop rejection.
    // ==================================================================
    $path = '';
    if (isset($parts['path'])) {
        $path = $parts['path'];
    }

    foreach ($selfHosts as $selfHost) {
        $selfHostLower = strtolower((string) $selfHost);

        $isSelfHost = ($host === $selfHostLower) || ($host === 'www.' . $selfHostLower);

        if ($isSelfHost && str_starts_with($path, '/r/')) {
            return $invalid('Destination URL points back at this platform\'s own redirect layer, which would create a redirect loop.');
        }
    }

    // ==================================================================
    // e2. (WP4c, #166) No destination on a short-link address, whatever the
    //     path: every path there is another short link (see
    //     CUERCODE_REDIRECT_SHORT_LINK_HOSTS). The address itself and every
    //     address under it ("alpha.", "www.", ...) are refused; a look-alike
    //     such as notqrmy.link is not.
    // ==================================================================
    foreach ($shortLinkHosts as $shortLinkHost) {
        $shortLinkHostLower = strtolower((string) $shortLinkHost);
        if ($shortLinkHostLower === '') {
            continue;
        }

        if ($host === $shortLinkHostLower || str_ends_with($host, '.' . $shortLinkHostLower)) {
            return $invalid('Destination URL is a short link (' . $shortLinkHostLower . '). A dynamic code cannot send people on to another short link: use the full address it leads to.');
        }
    }

    // ==================================================================
    // b (part 2). Final well-formedness sanity check on the RECONSTRUCTED,
    // now-guaranteed-ASCII URL. Reconstructing first (rather than validating
    // the raw input directly) is what lets step (f) above normalise an IDN
    // hostname to Punycode BEFORE this check runs - FILTER_VALIDATE_URL
    // rejects raw non-ASCII hosts outright.
    // ==================================================================
    $normalized = $scheme . '://' . $host;

    if (isset($parts['port'])) {
        $normalized .= ':' . (string) $parts['port'];
    }

    $normalized .= $path;

    if (isset($parts['query']) && $parts['query'] !== '') {
        $normalized .= '?' . $parts['query'];
    }

    if (isset($parts['fragment']) && $parts['fragment'] !== '') {
        $normalized .= '#' . $parts['fragment'];
    }

    if (filter_var($normalized, FILTER_VALIDATE_URL) === false) {
        return $invalid('Destination URL is not a well-formed URL.');
    }

    // ==================================================================
    // g. (Reputation-provider check) - deliberately NOT implemented here;
    // see the design doc §2.3 ("design now, wire later"). This function
    // performs no network access, so it structurally cannot check reputation
    // itself - a future caller wraps it with that check instead.
    // ==================================================================

    return ['valid' => true, 'normalized' => $normalized, 'error' => null];
}

// ------------------------------------------------------------------------
// 🔐 redirectIsWritableBy() - The single ownership predicate for tblQRRedirects
// ------------------------------------------------------------------------

/**
 * 🔐 Is a tblQRRedirects row writable (GET/PATCH the destination) by the given caller?
 *
 * Mirrors fn_api_qrcodes.php's qrCodeIsWritableBy() contract exactly - a row
 * with no owner set, or a caller identity that could not be resolved, is
 * never writable by anyone. There is no "isPublic" concept for a redirect
 * destination (unlike a saved QR code) - a destination is never publicly
 * readable, only owner-readable, so this SAME predicate backs both the
 * GET and PATCH `qrcodes/{uuid}/destination` handlers (fn_api_redirects.php).
 *
 * Org-owned redirects (`ownerOrgID` set, `ownerUserID` null) always fail
 * closed here - tblOrganisations remains a Phase-2 schema stub with no real
 * org-membership auth context anywhere in this codebase yet, so there is no
 * safe way to resolve "does this caller act for this org" today. This is a
 * deliberate fail-closed default, not an oversight - a future org-auth stage
 * would extend this predicate, never bypass it.
 *
 * @param int|null $ownerUserId  The row's ownerUserID (null = not user-owned).
 * @param int|null $ownerOrgId   The row's ownerOrgID (null = not org-owned).
 * @param int|null $callerUserId The resolved caller identity (null = could
 *                                not be resolved - treated as "nobody").
 *
 * @return bool True if writable.
 */
function redirectIsWritableBy(?int $ownerUserId, ?int $ownerOrgId, ?int $callerUserId): bool
{
    if ($ownerUserId !== null) {
        return $callerUserId !== null && $ownerUserId === $callerUserId;
    }

    // 📌 $ownerOrgId set (or neither set, an invalid/unclaimed row either
    // way) - fail closed. See docblock above.
    return false;
}

// ------------------------------------------------------------------------
// 🔢 evaluateDynamicCodeQuota() - Pure quota decision
// ------------------------------------------------------------------------

/**
 * 🔢 Decide whether creating one more dynamic redirect is within the caller's plan quota.
 *
 * Pure arithmetic over caller-supplied numbers - mirrors fn_features.php's
 * evaluateCreditSpend() pattern (a pure decision function fed real numbers by
 * a DB-touching caller, so the DECISION LOGIC itself is unit-testable without
 * a database). The live count/limit are resolved by
 * countActiveDynamicRedirectsForUser()/limitFor('dynamic_codes', ...) in
 * fn_api_redirects.php; this function only decides given those two numbers.
 *
 * @param int $currentActiveCount The caller's current count of active
 *                                 (isActive=1) dynamic redirects.
 * @param int $limit               The caller's plan `dynamic_codes` limit
 *                                 (-1 = unlimited, 0 = not permitted at all -
 *                                 fn_features.php's limitFor() convention).
 *
 * @return array{allowed: bool, reason: ?string} `reason` is one of
 *         'not_permitted' (limit is 0 - the plan does not include this at
 *         all) or 'limit_reached' (at/over a positive limit) when denied,
 *         or null when allowed.
 */
function evaluateDynamicCodeQuota(int $currentActiveCount, int $limit): array
{
    if ($limit === -1) {
        return ['allowed' => true, 'reason' => null];
    }

    if ($limit <= 0) {
        return ['allowed' => false, 'reason' => 'not_permitted'];
    }

    if ($currentActiveCount >= $limit) {
        return ['allowed' => false, 'reason' => 'limit_reached'];
    }

    return ['allowed' => true, 'reason' => null];
}

// ==============================================================================
// 🔗 Dynamic codes on Go2My.Link: the pure rules (WP4c, #166)
// ==============================================================================
// Everything below decides from the values it is given. The database work and
// the calls to Go2My.Link are in fn_api_redirects.php, which uses these.
// ==============================================================================

/**
 * 🖨️ May this tblQRRedirects row be drawn as a QR picture, downloaded or
 *    changed? ONLY when all of these hold (the brief for WP4c: "active is the
 *    only state whose QR may be produced or downloaded by any route"):
 *      - syncState is "active" (Go2My.Link has the link on the confirmed short
 *        domain; never pending, unconfirmed, failed or unlinked);
 *      - it is switched on (isActive = 1) and has no switched-off reason;
 *      - it is not suspended (no suspension time);
 *      - it has a short code Go2My.Link would accept.
 *    PURE.
 *
 * @param array<string, mixed> $row A tblQRRedirects row
 * @return bool
 */
function dynamicQrRowIsPrintable(array $row): bool
{
    if (!isset($row['syncState']) || $row['syncState'] !== CUERCODE_DYNAMIC_QR_STATE_ACTIVE) {
        return false;
    }

    if (!isset($row['isActive']) || (int) $row['isActive'] !== 1) {
        return false;
    }

    if (isset($row['disabledReason']) && (string) $row['disabledReason'] !== '') {
        return false;
    }

    if (dynamicQrRowIsSuspended($row)) {
        return false;
    }

    if (!isset($row['shortCode']) || !is_string($row['shortCode'])) {
        return false;
    }

    return dynamicQrShortCodeIsUsable($row['shortCode']);
}

/**
 * 🚫 Is this code suspended (taken down)? A suspension time, or switched off
 *    by an admin or for abuse. The owner can change nothing about a
 *    suspended code (it cannot be edited or switched on around a takedown).
 *    PURE.
 *
 * @param array<string, mixed> $row A tblQRRedirects row
 * @return bool
 */
function dynamicQrRowIsSuspended(array $row): bool
{
    if (isset($row['suspendedAt']) && (string) $row['suspendedAt'] !== '') {
        return true;
    }

    if (isset($row['disabledReason']) && in_array($row['disabledReason'], ['abuse', 'admin'], true)) {
        return true;
    }

    return false;
}

/**
 * 🔤 Is this a short code Go2My.Link would accept? Its own check when the
 *    connection code is loaded (pattern and reserved words), otherwise its
 *    pattern alone. PURE.
 *
 * @param string $shortCode
 * @return bool
 */
function dynamicQrShortCodeIsUsable(string $shortCode): bool
{
    if (function_exists('go2mylinkCodeIsAllowed')) {
        return go2mylinkCodeIsAllowed($shortCode);
    }

    return preg_match('/^[A-Za-z0-9_-]{3,50}$/D', $shortCode) === 1;
}

/**
 * 👤 May this account make or change dynamic codes? Only an account that is
 *    "active" AND has a confirmed email address (no anonymous codes, and no
 *    codes from an address nobody has proved they own). PURE.
 *
 * @param array<string, mixed>|null $account tblUsers status and emailVerifiedAt; null = no account
 * @return bool
 */
function dynamicQrAccountMayChangeCodes(?array $account): bool
{
    if ($account === null) {
        return false;
    }

    if (!isset($account['status']) || $account['status'] !== 'active') {
        return false;
    }

    if (!isset($account['emailVerifiedAt']) || trim((string) $account['emailVerifiedAt']) === '') {
        return false;
    }

    return true;
}

/**
 * 📄 The address a switched-off code points at (lead choice L2): this site's
 *    /unavailable/ page with the code, for example
 *    https://cuercode.net/unavailable/?code=Ab3dE9x. Go2My.Link's own
 *    switched-off page says "disabled by its owner" and its API cannot switch
 *    a link back on, so CueRCode points the link here instead. '' when the
 *    site address is not an https address or the code is not a usable code
 *    (switching off is then refused). PURE.
 *
 * @param string $siteUrl   The site_url setting
 * @param string $shortCode The code's short code
 * @return string
 */
function dynamicQrUnavailablePageUrl(string $siteUrl, string $shortCode): string
{
    $siteUrl = trim($siteUrl);

    if (stripos($siteUrl, 'https://') !== 0) {
        return '';
    }

    $host = parse_url($siteUrl, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return '';
    }

    if (!dynamicQrShortCodeIsUsable($shortCode)) {
        return '';
    }

    return rtrim($siteUrl, '/') . CUERCODE_DYNAMIC_QR_UNAVAILABLE_PATH . '?code=' . rawurlencode($shortCode);
}

/**
 * 🔤 A destination as typed, made ready for validateRedirectDestination():
 *    spaces and non-English letters after the website name become %XX codes,
 *    the form Go2My.Link stores (go2mylinkEncodeDestination(), #181 Low 3),
 *    so a pasted "https://example.com/café menu" can be accepted. Text with a
 *    control character (or a line break) is passed on UNCHANGED, so the
 *    validator still refuses it - nothing is ever silently removed. The
 *    validator's own checks are not changed. PURE.
 *
 * @param string $typed
 * @return string
 */
function dynamicQrPrepareDestination(string $typed): string
{
    if (preg_match('/[\x00-\x1F\x7F]/', $typed) === 1 || !function_exists('go2mylinkEncodeDestination')) {
        return $typed;
    }

    return go2mylinkEncodeDestination($typed);
}

/**
 * 🛡️ THE link-safety hook: the ONE place a dynamic code's destination is
 *    checked against a link-safety service (WP4e, #168; decisions 0029 and
 *    0035: Google Web Risk now, a second provider later). Every check goes
 *    through here (a source test counts the callers):
 *      - 'create'  : a code is made (createDynamicRedirectForOwner());
 *      - 'change'  : where a code sends people changes - a new destination,
 *                    or switching it back on (dynamicQrChangeForOwner());
 *      - 'restore' : an admin lifts a suspension (dynamicQrRestore());
 *      - 'recheck' : the daily job checks every code in use again
 *                    (dynamicQrRecheckSafety()).
 *
 *    The answer, from urlReputationCheck() (fn_url_reputation.php):
 *      - allowed false, verdict "unsafe": a provider lists the address. The
 *        callers refuse with 422 DESTINATION_UNSAFE (the daily job suspends);
 *      - allowed true, verdict "safe": every provider asked said it is not listed;
 *      - allowed true, verdict "unchecked": a provider could not answer, or
 *        none is set up - let through and logged (bounded), design section
 *        2.3 ("fail-open"); the daily job checks it again.
 *
 *    ⚠️ The ONE function in this file that is not pure: it asks the provider
 *    (fn_url_reputation.php is loaded here, at call time, so loading this
 *    file stays pure).
 *
 * @param string               $destinationUrl The destination, already through validateRedirectDestination()
 * @param string               $occasion       'create', 'change', 'restore' or 'recheck'
 * @param array<string, mixed> $options        Passed to urlReputationCheck(): 'redirectID' and 'userID'
 *                                             (recorded with a hit), and the tests' stand-ins
 * @return array{allowed: bool, verdict: string, reason: string, message: string, provider: string, threatTypes: array<int, string>}
 */
function dynamicQrCheckDestinationSafety(string $destinationUrl, string $occasion, #[\SensitiveParameter] array $options = []): array
{
    if (!function_exists('urlReputationCheck')) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_url_reputation.php';
    }

    $check = urlReputationCheck($destinationUrl, $occasion, $options);

    $message = '';
    if ($check['allowed'] !== true) {
        $providerName = 'a link-safety service';
        $providers    = urlReputationProviders();
        if (isset($providers[$check['provider']])) {
            $providerName = $providers[$check['provider']]['name'];
        }
        $message = 'This destination is listed as harmful (for example phishing or malware) by ' . $providerName . ', so it cannot be used for a dynamic code.';
    }

    return [
        'allowed'     => ($check['allowed'] === true),
        'verdict'     => (string) $check['verdict'],
        'reason'      => (string) $check['reason'],
        'message'     => $message,
        'provider'    => (string) $check['provider'],
        'threatTypes' => $check['threatTypes'],
    ];
}

/**
 * 🧭 What does a PATCH of one code mean? (The change, switch-off and
 *    switch-on flow, lead choice L2.) Decides everything without calling
 *    anybody. PURE.
 *
 *   - A suspended code refuses every change (409 REDIRECT_SUSPENDED).
 *   - Only an "active" code can be changed (409 REDIRECT_NOT_READY).
 *   - "destination_url" is checked again (400 INVALID_DESTINATION).
 *   - "active" must be true or false (400 INVALID_ACTIVE).
 *   - What Go2My.Link must point at afterwards: the destination while the
 *     code is on, the switched-off page while it is off. remoteTarget is that
 *     address when it differs from what Go2My.Link points at now, otherwise
 *     null (no call: for example a new destination while switched off is
 *     only saved here, and sent when the code is switched on).
 *   - checkDestination is what goes through the link-safety hook: a new
 *     destination, or the saved one when the code is switched back on.
 *
 * @param array<string, mixed> $row     The code (tblQRRedirects)
 * @param array<string, mixed> $body    The request: destination_url and/or active
 * @param array<string, mixed> $context 'allowHttp' (bool, redirect_allow_http) and 'offPageUrl' (dynamicQrUnavailablePageUrl())
 * @return array{ok: bool, httpCode: int, errorCode: string, message: string, nothing: bool, newDestination: string, newActive: bool, destinationChanged: bool, activeChanged: bool, remoteTarget: string|null, checkDestination: string}
 */
function dynamicQrPlanChange(array $row, array $body, array $context): array
{
    $plan = [
        'ok'                 => false,
        'httpCode'           => 200,
        'errorCode'          => '',
        'message'            => '',
        'nothing'            => false,
        'newDestination'     => '',
        'newActive'          => false,
        'destinationChanged' => false,
        'activeChanged'      => false,
        'remoteTarget'       => null,
        'checkDestination'   => '',
    ];

    // 📌 Each refusal is written as 'errorCode' => 'X', so
    //    tests/test_error_catalogue.php finds every code this can give.
    $refuse = static function (array $facts) use ($plan): array {
        $plan['httpCode']  = (int) $facts['httpCode'];
        $plan['errorCode'] = (string) $facts['errorCode'];
        $plan['message']   = (string) $facts['message'];
        return $plan;
    };

    // 🚫 1. A takedown cannot be edited or switched around by the owner.
    if (dynamicQrRowIsSuspended($row)) {
        return $refuse(['httpCode' => 409, 'errorCode' => 'REDIRECT_SUSPENDED', 'message' => 'This dynamic code has been suspended and cannot be changed. Contact support if you believe this is a mistake.']);
    }

    // ⏳ 2. Only a code Go2My.Link has confirmed.
    if (!isset($row['syncState']) || $row['syncState'] !== CUERCODE_DYNAMIC_QR_STATE_ACTIVE) {
        return $refuse(['httpCode' => 409, 'errorCode' => 'REDIRECT_NOT_READY', 'message' => 'This dynamic code was not confirmed by the link service, so it cannot be changed yet. It is being checked; contact support if this lasts more than a day.']);
    }

    $destinationProvided = array_key_exists('destination_url', $body);
    $activeProvided      = array_key_exists('active', $body);

    if (!$destinationProvided && !$activeProvided) {
        return $refuse(['httpCode' => 400, 'errorCode' => 'NO_FIELDS', 'message' => 'No updatable fields were supplied. Provide "destination_url" and/or "active".']);
    }

    $allowHttp = false;
    if (isset($context['allowHttp']) && $context['allowHttp'] === true) {
        $allowHttp = true;
    }
    $offPageUrl = '';
    if (isset($context['offPageUrl']) && is_string($context['offPageUrl'])) {
        $offPageUrl = $context['offPageUrl'];
    }

    $currentDestination = '';
    if (isset($row['destinationURL'])) {
        $currentDestination = (string) $row['destinationURL'];
    }
    $currentActive = (isset($row['isActive']) && (int) $row['isActive'] === 1);

    // 🎯 3. A new destination is checked again, every time.
    $newDestination = $currentDestination;
    if ($destinationProvided) {
        if (!is_string($body['destination_url'])) {
            return $refuse(['httpCode' => 400, 'errorCode' => 'INVALID_DESTINATION', 'message' => 'Field "destination_url" must be a string.']);
        }
        $checked = validateRedirectDestination(dynamicQrPrepareDestination($body['destination_url']), ['allow_http' => $allowHttp]);
        if (!$checked['valid']) {
            return $refuse(['httpCode' => 400, 'errorCode' => 'INVALID_DESTINATION', 'message' => (string) $checked['error']]);
        }
        $newDestination = $checked['normalized'];
        // 🔤 In the form Go2My.Link stores it (#181 Low 3), so the
        //    comparisons below and on Go2My.Link's reply are exact.
        if (function_exists('go2mylinkEncodeDestination')) {
            $newDestination = go2mylinkEncodeDestination($newDestination);
        }
    }

    // 🔘 4. "active" is a real true or false (never "false", 0 or 1).
    $newActive = $currentActive;
    if ($activeProvided) {
        if (!is_bool($body['active'])) {
            return $refuse(['httpCode' => 400, 'errorCode' => 'INVALID_ACTIVE', 'message' => 'Field "active" must be true or false.']);
        }
        $newActive = $body['active'];
    }

    $plan['newDestination']     = $newDestination;
    $plan['newActive']          = $newActive;
    $plan['destinationChanged'] = ($newDestination !== $currentDestination);
    $plan['activeChanged']      = ($newActive !== $currentActive);

    if (!$plan['destinationChanged'] && !$plan['activeChanged']) {
        $plan['ok']      = true;
        $plan['nothing'] = true;
        return $plan;
    }

    // 📄 5. Switching off needs the page to point at.
    if (!$newActive && $plan['activeChanged'] && $offPageUrl === '') {
        return $refuse(['httpCode' => 503, 'errorCode' => 'DYNAMIC_QR_UNAVAILABLE', 'message' => 'Switching a dynamic code off is not available just now. Please try again later.']);
    }

    // 🌐 6. What Go2My.Link points at now, and afterwards.
    $remoteNow = $offPageUrl;
    if ($currentActive) {
        $remoteNow = $currentDestination;
    }
    $remoteNew = $offPageUrl;
    if ($newActive) {
        $remoteNew = $newDestination;
    }
    if ($remoteNew !== $remoteNow && $remoteNew !== '') {
        $plan['remoteTarget'] = $remoteNew;
    }

    // 🛡️ 7. Every change of where the code sends people goes through the hook.
    if ($plan['destinationChanged']) {
        $plan['checkDestination'] = $newDestination;
    } elseif ($plan['activeChanged'] && $newActive) {
        $plan['checkDestination'] = $newDestination;
    }

    $plan['ok'] = true;

    return $plan;
}

/**
 * 🧹 The tidy step's choice for a code left "pending" (its create answer was
 *    lost), from looking its short code up on Go2My.Link. PURE.
 *
 *   'recover' - Go2My.Link has a link with our code: settle it with the safe
 *               create (go2mylinkCreateLinkSafely()), which only ever marks
 *               it active on a confirmed short domain and otherwise holds it
 *               as "unconfirmed" for an admin - never a blind retry;
 *   'failed'  - Go2My.Link has no such link: nothing was made, so the row
 *               stops counting towards the owner's limit;
 *   'wait'    - the look-up could not answer: leave it pending (it keeps
 *               counting) and look again on the next run.
 *
 * @param array<string, mixed> $lookup A result from go2mylinkLookupLink()
 * @return string
 */
function dynamicQrTidyActionFor(array $lookup): string
{
    if (isset($lookup['ok']) && $lookup['ok'] === true) {
        return 'recover';
    }

    if (isset($lookup['reason']) && $lookup['reason'] === 'not_found') {
        return 'failed';
    }

    return 'wait';
}

/**
 * 🧾 A failed Go2My.Link call as the member's answer. Go2My.Link's own
 *    wording and the admin wording are never used. PURE.
 *
 *   rate_limited          -> 429 LINK_SERVICE_BUSY (with its wait)
 *   rejected              -> 422 LINK_SERVICE_REJECTED
 *   may have been done, or done but not confirmed (outcomeUnknown, or
 *   remoteExists)         -> 503 LINK_NOT_CONFIRMED
 *   anything else         -> 503 LINK_SERVICE_UNAVAILABLE ("try again later")
 *
 * @param array<string, mixed> $result   A failed result from fn_go2mylink.php
 * @param string               $occasion 'create' or 'change' (only the words differ)
 * @return array{httpCode: int, errorCode: string, message: string, retryAfter: int}
 */
function dynamicQrServiceErrorFacts(array $result, string $occasion = 'create'): array
{
    $reason = '';
    if (isset($result['reason']) && is_string($result['reason'])) {
        $reason = $result['reason'];
    }
    $retryAfter = 0;
    if (isset($result['retryAfter']) && is_int($result['retryAfter']) && $result['retryAfter'] > 0) {
        $retryAfter = $result['retryAfter'];
    }

    $message = 'The link service could not be reached just now. Please try again in a few minutes.';
    if (function_exists('go2mylinkReasonFacts')) {
        $message = go2mylinkReasonFacts($reason)['message'];
    }

    if ($reason === 'rate_limited') {
        return ['httpCode' => 429, 'errorCode' => 'LINK_SERVICE_BUSY', 'message' => $message, 'retryAfter' => $retryAfter];
    }

    if ($reason === 'rejected') {
        return ['httpCode' => 422, 'errorCode' => 'LINK_SERVICE_REJECTED', 'message' => $message, 'retryAfter' => 0];
    }

    $unknown = (isset($result['outcomeUnknown']) && $result['outcomeUnknown'] === true)
        || (isset($result['remoteExists']) && $result['remoteExists'] === true);
    if ($unknown) {
        $notConfirmed = 'The link service did not confirm the new dynamic code. It is checked again automatically and counts towards your limit until then. Please try again later.';
        if ($occasion === 'change') {
            $notConfirmed = 'The link service did not confirm the change, so nothing was changed here. Please try again in a few minutes.';
        }
        return ['httpCode' => 503, 'errorCode' => 'LINK_NOT_CONFIRMED', 'message' => $notConfirmed, 'retryAfter' => 0];
    }

    return ['httpCode' => 503, 'errorCode' => 'LINK_SERVICE_UNAVAILABLE', 'message' => $message, 'retryAfter' => 0];
}

/**
 * 🎫 The permission to draw ONE dynamic code as a picture, held for the rest
 *    of this request only. The create flow grants it for the code it has just
 *    made (after the ownership and state checks) and takes it back straight
 *    after drawing; buildPayload_dynamic_url() refuses without it. So no
 *    route that draws from typed-in details (generate, preview, bulk, the
 *    no-JavaScript form, or one added later) can ever draw a dynamic code:
 *    nothing a visitor sends can set it.
 *
 * @param string $action 'grant', 'revoke' or 'check'
 * @param string $uuid   The code's redirectUUID
 * @return bool grant/revoke: done; check: held
 */
function dynamicQrPayloadPermit(string $action, string $uuid): bool
{
    static $permits = [];

    $uuid = strtolower(trim($uuid));
    if (!isValidRedirectUuid($uuid)) {
        return false;
    }

    if ($action === 'grant') {
        $permits[$uuid] = true;
        return true;
    }

    if ($action === 'revoke') {
        unset($permits[$uuid]);
        return true;
    }

    return isset($permits[$uuid]);
}
