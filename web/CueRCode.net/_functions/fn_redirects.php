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
 * ⚠️ DARK / INERT this stage: nothing in this file is reachable from any
 * public route. There is no `public_html/r/` endpoint anywhere in this
 * codebase yet - see the design doc's Stage S2 (gated on owner sign-off
 * G-007, `LAUNCH_DECISIONS.md`).
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
        return $invalid('Destination scheme "' . $scheme . '" is not allowed. Only https is permitted' . ($allowHttp ? ' (and http, per configuration)' : '') . '.');
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
