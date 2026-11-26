<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Project-wide guard: no commercial value written into the code
 *    (task 7n Phase 2, plan §9 and security check S24)
 * ==============================================================================
 *
 * Path: tests/test_commercial_literals.php
 *
 * Owner rule (2026-10-05): "never hard-code a commercial rule". Plans and
 * prices are data a Global Admin edits. This test reads every PHP file under
 * web/CueRCode.net/ with PHP's own tokeniser and fails when a string or number
 * token is:
 *   - one of the starting PAID plan keys (pro, business, enterprise, payg,
 *     pro_lifetime_founders - "anonymous" and "free" are left out because they
 *     are the code's documented fallback plans and ordinary words); or
 *   - one of the starting prices (also written with a currency sign, and as a
 *     number such as 99.0).
 *
 * Only string and number tokens are looked at, so comments and docblocks do
 * not count. Skipped: _libraries/ (other people's code) and
 * _includes/sql/migrations/ (the starting DATA lives there).
 *
 * 🔓 THE ONE EXCEPTION (a false positive, not a commercial rule):
 *   _functions/fn_migrations.php, inside migrationSeedExpectedRows() only,
 *   names the four plan rows seed_data.sql inserts, so the installer can tell
 *   whether the starting data finished loading. It is allowed exactly the
 *   tokens 'pro' and 'business', once each, inside that function. Anything
 *   else, anywhere, fails.
 *
 * ⚠️ House rules: every variable and function is prefixed cl.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-26 (task 7n Phase 2, slice 2d - #31)
 * @see         .claude/plans/7n-admin-commerce-plan.md §9 Phase 2
 */

/**
 * 🔎 Commercial literals in one PHP source.
 *
 * @param string $clSource PHP source
 * @return array<int, array{text: string, line: int, function: string}> What was
 *         found, with the line and the name of the last function declared
 *         above it ('' at the top of a file)
 */
function clCommercialLiterals(string $clSource): array
{
    $clKeys = ['pro', 'business', 'enterprise', 'payg', 'pro_lifetime_founders'];
    $clPrices = ['6.99', '49.99', '24.99', '199.99', '119.99', '4.99', '19.99', '59.99', '99.00'];

    $clHits = [];
    $clFunction = '';
    $clAwaitingName = false;

    foreach (token_get_all($clSource) as $clToken) {
        if (!is_array($clToken)) {
            // "function (" is an unnamed function: keep the surrounding name.
            if ($clToken === '(') {
                $clAwaitingName = false;
            }
            continue;
        }
        [$clType, $clText, $clLine] = $clToken;

        if ($clType === T_FUNCTION) {
            $clAwaitingName = true;
            continue;
        }
        if ($clAwaitingName && $clType === T_STRING) {
            $clFunction = $clText;
            $clAwaitingName = false;
            continue;
        }

        $clFound = false;

        if ($clType === T_CONSTANT_ENCAPSED_STRING || $clType === T_ENCAPSED_AND_WHITESPACE) {
            $clValue = $clText;
            if ($clType === T_CONSTANT_ENCAPSED_STRING) {
                $clValue = substr($clText, 1, -1);
            }
            $clBare = trim(str_replace(['£', '$', '€'], '', $clValue));
            $clFound = (in_array($clValue, $clKeys, true) || in_array($clBare, $clPrices, true));
        } elseif ($clType === T_DNUMBER) {
            foreach ($clPrices as $clPrice) {
                if ((float) str_replace('_', '', $clText) === (float) $clPrice) {
                    $clFound = true;
                }
            }
        }

        if ($clFound) {
            $clHits[] = ['text' => $clText, 'line' => $clLine, 'function' => $clFunction];
        }
    }

    return $clHits;
}

// 🧪 The guard catches what it should, and only that (deliberately bad samples).
$clSampleTexts = array_column(clCommercialLiterals(
    "<?php // 'pro' 6.99 in a comment is fine\n/** 'business' 99.00 */\n\$a = 'pro'; \$b = \"business\"; \$c = 'enterprise'; \$d = 'payg'; \$e = 'pro_lifetime_founders';\n"
    . "\$f = 6.99; \$g = '99.00'; \$h = 99.00; \$i = 99.0; \$j = '£49.99'; \$k = 'proper'; \$l = 99; \$m = '16.99'; \$n = 'free';"
), 'text');
assertEquals(
    ["'pro'", '"business"', "'enterprise'", "'payg'", "'pro_lifetime_founders'", '6.99', "'99.00'", '99.00', '99.0', "'£49.99'"],
    $clSampleTexts,
    'literal guard samples: plan keys and starting prices are caught; comments, longer words, other numbers and "free" are not'
);

// 🌳 Every PHP file of the site.
$clSiteRoot = CUERCODE_SITE_ROOT;
$clSkip = [
    '_libraries' . DIRECTORY_SEPARATOR,
    '_includes' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR,
];
$clExceptionFile = '_functions' . DIRECTORY_SEPARATOR . 'fn_migrations.php';
$clExceptionUsed = [];
$clProblems = [];
$clScanned = [];

$clFiles = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($clSiteRoot, \FilesystemIterator::SKIP_DOTS));
foreach ($clFiles as $clFile) {
    if (!$clFile->isFile() || strtolower($clFile->getExtension()) !== 'php') {
        continue;
    }
    $clRelative = str_replace($clSiteRoot, '', $clFile->getPathname());
    foreach ($clSkip as $clPrefix) {
        if (str_starts_with($clRelative, $clPrefix)) {
            continue 2;
        }
    }
    $clScanned[$clRelative] = true;

    foreach (clCommercialLiterals((string) file_get_contents($clFile->getPathname())) as $clHit) {
        if (
            $clRelative === $clExceptionFile
            && $clHit['function'] === 'migrationSeedExpectedRows'
            && in_array($clHit['text'], ["'pro'", "'business'"], true)
            && !isset($clExceptionUsed[$clHit['text']])
        ) {
            $clExceptionUsed[$clHit['text']] = true;
            continue;
        }
        $clProblems[] = $clRelative . ':' . $clHit['line'] . ' ' . $clHit['text'];
    }
}

assertTrue(count($clScanned) > 80, 'literal guard is not vacuous: it scanned ' . count($clScanned) . ' PHP files');
foreach (['_functions' . DIRECTORY_SEPARATOR . 'fn_pricing.php', '_includes' . DIRECTORY_SEPARATOR . 'pricing_grid.php', 'public_html' . DIRECTORY_SEPARATOR . 'pricing' . DIRECTORY_SEPARATOR . 'index.php', $clExceptionFile] as $clMustScan) {
    assertTrue(isset($clScanned[$clMustScan]), 'literal guard scanned ' . $clMustScan);
}
assertEquals([], $clProblems, 'no starting paid-plan key or starting price is written into the code (plans and prices are data, S24)');
assertEquals(2, count($clExceptionUsed), 'the one allowed exception is still exactly what it says (remove it if the list changes)');
