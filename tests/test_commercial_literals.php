<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Guard: the STARTING plan keys and prices are not written
 *    into the code (task 7n Phase 2, plan §9 and security check S24)
 * ==============================================================================
 *
 * Path: tests/test_commercial_literals.php
 *
 * Owner rule (2026-10-05): "never hard-code a commercial rule". Plans and
 * prices are data a Global Admin edits.
 *
 * ⚠️ WHAT THIS CAN AND CANNOT PROVE (Codex finding 3.6 of the 7n Phase 2
 *    review): it looks for a FIXED LIST - the plan keys and prices the site
 *    starts with. A different hard-coded amount (say 12.50) or a new plan key
 *    is not on the list and passes. So it stops the starting values from
 *    creeping back into the code; it does not prove the code holds no
 *    commercial value at all. That still depends on review.
 *
 * This test reads every PHP file under web/CueRCode.net/ with PHP's own
 * tokeniser and fails when a string or number token is:
 *   - one of the starting PAID plan keys (pro, business, enterprise, payg,
 *     pro_lifetime_founders - "anonymous" and "free" are left out because they
 *     are the code's documented fallback plans and ordinary words); or
 *   - one of the starting prices (also written with a currency sign, and as a
 *     number such as 99.0);
 *   - since review finding F4 (2026-11-27): a starting price inside a longer
 *     string or in page text outside PHP tags ("Pro is £6.99 a month",
 *     "From £99/month"), or written in whole pence as a whole number (699,
 *     4999 ... 9900 - fn_money.php works in pence).
 *
 * Plain whole numbers that happen to equal a price in pence (1999 could be a
 * year) would also be caught; none is in the code today. If one ever is and
 * it is not a price, write it another way (for example as a named constant
 * built from smaller numbers) rather than widening the exceptions.
 *
 * Only string, number and page-text tokens are looked at, so comments and
 * docblocks do not count. Skipped: _libraries/ (other people's code) and
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
    // 🔎 Review finding F4: the same prices in whole pence (fn_money.php works
    //    in minor units, so moneyFormat(699, 'GBP') is a hard-coded £6.99).
    $clMinorPrices = ['699', '4999', '2499', '19999', '11999', '499', '1999', '5999', '9900'];
    // 🔎 ...and inside text: a starting price written as a decimal (never as
    //    part of a longer number such as 14.99 or 6.995), or the "from" price
    //    99 written with a currency sign (£99, $99, €99, &pound;99 - but not
    //    £99.50, £199 or a bare 99 such as "99px").
    $clDecimals = '(?:6\.99|49\.99|24\.99|199\.99|119\.99|4\.99|19\.99|59\.99)';
    $clInText = '/(?<![0-9.])(?:(?:£|\$|€|&pound;|&euro;|&#163;)\s*99(?:\.00)?(?![0-9.])|' . $clDecimals . '(?![0-9]))/u';

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
            $clFound = (in_array($clValue, $clKeys, true) || in_array($clBare, $clPrices, true) || preg_match($clInText, $clValue) === 1);
        } elseif ($clType === T_INLINE_HTML) {
            // 📄 Page text outside PHP tags (templates such as pricing_grid.php).
            $clFound = (preg_match($clInText, $clText) === 1);
        } elseif ($clType === T_LNUMBER) {
            $clFound = in_array(str_replace('_', '', $clText), $clMinorPrices, true);
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

// 🔎 Review finding F4 (7n Phase 2 review): the two likeliest ways to write a
//    price into this code base - page text outside PHP (T_INLINE_HTML), and
//    the whole-pence numbers Phase 2 brought in (moneyFormat(699, 'GBP')) -
//    and a price inside a longer string, are caught too.
$clWiderSample = "<?php \$x = 1; ?>\n<p>Pro is £6.99 a month, Business £24.99.</p>\n<p>From £99/month</p>\n"
    . "<?php \$a = moneyFormat(699, 'GBP'); \$b = moneyFormat(4999, 'GBP'); \$c = 9900; \$d = 'Pro costs £6.99 a month';\n"
    . "\$e = 1998; \$f = 98; \$g = 'from £99.50'; \$h = 'z-index: 99'; \$i = 16990; \$j = '£199 or 14.99'; ?>\n<p>Only 16.99 or £1999 here, and width 99px.</p>";
assertEquals(
    ['<p>Pro is £6.99 a month, Business £24.99.</p>' . "\n" . '<p>From £99/month</p>' . "\n", '699', '4999', '9900', "'Pro costs £6.99 a month'"],
    array_column(clCommercialLiterals($clWiderSample), 'text'),
    'F4: prices in page text, in whole pence and inside longer strings are caught; other numbers, £99.50, £199, 14.99, 16.99 and 99px are not'
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
