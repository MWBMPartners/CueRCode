<?php
/**
 * ==============================================================================
 * ☁️ CueRCode - Cloudflare's published address ranges (data only)
 * ==============================================================================
 *
 * Path: _includes/data/cloudflare_ip_ranges.php
 *
 * The internet address ranges Cloudflare says its servers connect FROM. The
 * site trusts the visitor address in Cloudflare's `CF-Connecting-IP` header
 * ONLY when the connection itself comes from one of these ranges (decision
 * 0014, docs/decisions/0014-client-address-trust-cloudflare-only-from-its-ranges.md).
 * A visitor who is not Cloudflare cannot connect from these ranges, so cannot
 * fake the header.
 *
 * 📅 Fetched on 2026-11-25 from all three of Cloudflare's own published
 *    sources, which agreed exactly:
 *      https://www.cloudflare.com/ips-v4
 *      https://www.cloudflare.com/ips-v6
 *      https://api.cloudflare.com/client/v4/ips
 *    (The human-readable page is https://www.cloudflare.com/ips/.)
 *
 * 🔁 Refreshing: Cloudflare changes these rarely, but it does change them.
 *    Run `php tools/refresh_cloudflare_ranges.php` (it fetches the lists,
 *    checks every entry and shows the difference) and then
 *    `php tools/refresh_cloudflare_ranges.php --write` to rewrite this file.
 *    Commit the result. If this file is out of date, a NEW Cloudflare range
 *    is simply not trusted: visitors arriving through it are limited by
 *    Cloudflare's address rather than their own (safe, but stricter). The
 *    admin overview's "Visitor address check" shows the date below.
 *
 * ⚠️ Owner check: compare this list with https://www.cloudflare.com/ips/
 *    once on the live host and whenever Cloudflare announces a change.
 *
 * @package     CueRCode
 * @subpackage  Security
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-25 (decision 0014, #31)
 * @see         _functions/fn_client_ip.php - the only reader of this file
 * @see         tools/refresh_cloudflare_ranges.php - rewrites this file
 */

return [
    'fetchedOn' => '2026-11-25',
    'sources'   => [
        'https://www.cloudflare.com/ips-v4',
        'https://www.cloudflare.com/ips-v6',
        'https://api.cloudflare.com/client/v4/ips',
    ],
    'ipv4'      => [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
    ],
    'ipv6'      => [
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ],
];
