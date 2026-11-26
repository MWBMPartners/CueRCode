<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - "Full notation" guard: shorthand can only go down
 * ==============================================================================
 *
 * Path: tests/test_full_notation.php
 *
 * The project rule is full multi-line PHP in production code: no `??`, `??=`,
 * `?:`, ternary `? :` or short echo `<?=`. The independent review (Codex C3
 * finding 6, 2026-11-25) found shorthand in production files. The files it
 * named, and the ones the review's fixes touched, were rewritten; 183 older
 * uses in 21 files remain and are tracked as a GitHub issue (they include
 * page templates, which also need the browser polish pass).
 *
 * This test makes sure the number can only go DOWN:
 *   - a file not listed below must have none;
 *   - a listed file may not have MORE than its recorded number.
 * When you clean a listed file, lower (or remove) its number here.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-25 (Codex C3 finding 6, #113)
 * @see         tests/lib/shorthand_scan.php - the tokenizer-based counter
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'shorthand_scan.php';

/** @var array<string, int> The most each not-yet-cleaned file may hold (2026-11-25). */
$fnCeiling = [
    '_functions/fn_api_download.php'             => 3,
    '_functions/fn_api_keys.php'                 => 10,
    '_functions/fn_api_qrcodes.php'              => 22,
    '_functions/fn_api_redirects.php'            => 18,
    '_functions/fn_email.php'                    => 3,
    '_functions/fn_features.php'                 => 6,
    '_functions/fn_openapi_render.php'           => 6,
    '_functions/fn_pricing.php'                  => 15,
    '_functions/fn_redirects.php'                => 1,
    '_includes/header.php'                       => 1,
    'public_html/account/avatar/index.php'       => 2,
    'public_html/account/export/index.php'       => 1,
    'public_html/account/index.php'              => 59,
    'public_html/account/qr-preview/index.php'   => 2,
    'public_html/auth/index.php'                 => 2,
    'public_html/developers/errors/index.php'    => 2,
    'public_html/developers/reference/index.php' => 15,
    'public_html/index.php'                      => 5,
    'public_html/login/index.php'                => 5,
    'public_html/register/index.php'             => 4,
];

// 🧪 The counter itself: each kind is found, and look-alikes are not.
$fnSample = shorthandCount('<?php $a = $b ?? 1; $c ??= 2; $d = $e ?: 3; $f = $g ? 4 : 5; function h(?string $x, #[\SensitiveParameter] ?int $y): ?array { return null; } $s = "a ? b : c"; // x ? y : z' . "\n" . '?><?= $z ?>');
assertEquals(1, $fnSample['coalesce'], 'scanner: finds ??');
assertEquals(1, $fnSample['coalesceAssign'], 'scanner: finds ??=');
assertEquals(1, $fnSample['elvis'], 'scanner: finds ?:');
assertEquals(1, $fnSample['ternary'], 'scanner: finds one ternary, and not the nullable types (also after an attribute), the string or the comment');
assertEquals(1, $fnSample['shortEcho'], 'scanner: finds <?=');

$fnFound = shorthandScanTree(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'CueRCode.net');

foreach ($fnFound as $fnFile => $fnCount) {
    if (!isset($fnCeiling[$fnFile])) {
        assertEquals(0, $fnCount, 'full notation: ' . $fnFile . ' has no shorthand (??, ?:, ternary, <?=) - write it out with if/else');
        continue;
    }
    assertTrue($fnCount <= $fnCeiling[$fnFile], 'full notation: ' . $fnFile . ' has no MORE shorthand than before (' . $fnCount . ' of at most ' . $fnCeiling[$fnFile] . ')');
}

// ✅ The files the review named, and those its fixes touched, stay clean.
foreach ([
    '_functions/fn_oauth.php', '_functions/fn_auth.php', '_functions/fn_api_account.php', '_functions/fn_install_checks.php',
    '_functions/fn_validation.php', '_functions/fn_admin.php', '_functions/fn_admin_settings.php', '_functions/fn_migrations.php',
    '_functions/fn_qr_logo.php', '_functions/fn_qr_generate.php', '_functions/fn_encryption.php',
    'public_html/reset-password/index.php', 'public_html/verify-email/index.php', 'public_html/install/index.php',
    'public_html/admin/reauth/index.php',
] as $fnClean) {
    assertFalse(isset($fnFound[$fnClean]), 'full notation: ' . $fnClean . ' has no shorthand at all');
}
