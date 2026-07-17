<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Tests for the Public Pricing Page Display Helpers (Epic E4, #56)
 * ==============================================================================
 *
 * Path: tests/test_pricing.php
 *
 * Exercises the DB-INDEPENDENT surface of `_functions/fn_pricing.php`:
 *
 *   - pricingFormatPrice()          - GBP/unknown-currency formatting, "Free"
 *                                     zero-handling.
 *   - pricingFormatLimit()          - -1/"Unlimited", 0, positive, null/"—".
 *   - pricingHumanizeKey()          - curated + generic-fallback labelling.
 *   - pricingDisplayRowsFromPlans() - pure JSON-decode transform: ordering
 *                                     stability, valid decode, malformed-JSON
 *                                     fail-safe, unknown-key fallback labels,
 *                                     empty input.
 *   - listPublicPlansForDisplay()   - no-DB fail-safe returns [] (dbQuery()
 *                                     is undefined under this harness, exactly
 *                                     like test_features.php's convention).
 *   - STATIC source-text regression guards: no hardcoded plan-key literals in
 *     fn_pricing.php (mirrors test_features.php's guard technique), no
 *     `<table` in pricing/index.php, pricing/index.php's bootstrap require
 *     line matches accessibility/index.php's depth pattern exactly, and
 *     fn_pricing.php's one query goes through the prepared-statement
 *     dbQuery() wrapper with no string-concatenated SQL.
 *
 * Every expected value below was captured via `php -r` against the real
 * functions BEFORE being written into an assertion (repo convention - see
 * test_features.php/test_openapi_render.php for the same practice).
 *
 * ⚠️ Lives under repo-root tests/ - never deployed.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 3 (Epic E4, #56)
 * @see         web/CueRCode.net/_functions/fn_pricing.php
 * @see         web/CueRCode.net/public_html/pricing/index.php
 * @see         tests/test_features.php - the sibling gating-engine test file this mirrors
 */

// ==============================================================================
// 📦 Load fn_pricing.php (NO database connection - registers functions only)
// ==============================================================================
// fn_pricing.php is intentionally NOT part of bootstrap.php's fn_ auto-load
// set (it is require_once'd by pricing/index.php only) - requireFn() still
// works for it exactly as it does for every other _functions/ file, since it
// just performs a plain require_once against the file path.
// ==============================================================================

requireFn('fn_pricing.php');

// ==============================================================================
// 💷 Section 1: pricingFormatPrice()
// ==============================================================================

assertEquals('Free', pricingFormatPrice(0.0, 'GBP'), 'pricingFormatPrice: zero GBP renders as "Free"');
assertEquals('Free', pricingFormatPrice(0, 'GBP'), 'pricingFormatPrice: int zero renders as "Free"');
assertEquals('Free', pricingFormatPrice(-5, 'GBP'), 'pricingFormatPrice: a negative amount fails safe to "Free"');
assertEquals('£4.99', pricingFormatPrice(4.99, 'GBP'), 'pricingFormatPrice: GBP gets a "£" prefix + 2dp');
assertEquals('£19.99', pricingFormatPrice(19.99, 'GBP'), 'pricingFormatPrice: another seeded GBP price formats correctly');
assertEquals('£49.99', pricingFormatPrice('49.99', 'gbp'), 'pricingFormatPrice: a numeric-string amount + lowercase currency code both normalise correctly');
assertEquals('USD 9.90', pricingFormatPrice(9.9, 'USD'), 'pricingFormatPrice: an unknown/unsupported currency code prefixes its uppercased ISO code, not a guessed symbol');
assertEquals('? 9.90', pricingFormatPrice(9.9, ''), 'pricingFormatPrice: an empty currency code fails safe to a neutral "?" prefix rather than assuming GBP');

// ==============================================================================
// 🔢 Section 2: pricingFormatLimit()
// ==============================================================================

assertEquals('Unlimited', pricingFormatLimit(-1), 'pricingFormatLimit: -1 is the documented "unlimited" sentinel');
assertEquals('0', pricingFormatLimit(0), 'pricingFormatLimit: a real zero limit renders as "0"');
assertEquals('500', pricingFormatLimit(500), 'pricingFormatLimit: a plain positive value is number_format()-ed');
assertEquals('20,000', pricingFormatLimit(20000), 'pricingFormatLimit: thousands separators are applied');
assertEquals('—', pricingFormatLimit(null), 'pricingFormatLimit: a missing/null value fails safe to an explicit em dash, never "0" or blank');

// ==============================================================================
// 🏷️ Section 3: pricingHumanizeKey()
// ==============================================================================

assertEquals(
    'Bulk batch size',
    pricingHumanizeKey('bulk_batch_size'),
    'pricingHumanizeKey: a curated key resolves to its documented human label (matches the generic-fallback shape too)'
);
assertEquals(
    'Single sign-on (SSO)',
    pricingHumanizeKey('sso'),
    'pricingHumanizeKey: the curated map spells out an abbreviation the generic fallback could not ("Sso" would read badly)'
);
assertEquals(
    'Some future flag xyz',
    pricingHumanizeKey('some_future_flag_xyz'),
    'pricingHumanizeKey: a key with NO curated entry falls back to snake_case -> Title Case - a brand-new plan '
    . 'key renders sensibly with zero code changes to this file'
);
assertEquals('Other', pricingHumanizeKey(''), 'pricingHumanizeKey: a defensive empty-string key never returns an empty label');

// ==============================================================================
// 🧮 Section 4: pricingDisplayRowsFromPlans() - pure transform
// ==============================================================================

// 📌 Empty input -> empty output.
assertEquals([], pricingDisplayRowsFromPlans([]), 'pricingDisplayRowsFromPlans: empty input -> empty output');

// 📌 Two plans, shaped like real tblSubscriptionPlans rows (string JSON
// columns, numeric-string prices - mirroring what mysqli hands back), with
// DELIBERATELY different key sets to prove the union-of-keys + fail-closed
// "not included"/"—" behaviour for a key one plan simply lacks.
$twoPlans = [
    [
        'planID'       => 2,
        'planKey'      => 'free',
        'planName'     => 'Free',
        'description'  => 'Create a free account.',
        'priceMonthly' => '0.00',
        'priceYearly'  => '0.00',
        'currency'     => 'GBP',
        'featuresJSON' => json_encode(['expiry_scheduling' => false, 'webhooks' => false]),
        'limitsJSON'   => json_encode(['saved_codes' => 25, 'bulk_batch_size' => 50]),
    ],
    [
        'planID'       => 3,
        'planKey'      => 'pro',
        'planName'     => 'Pro',
        'description'  => 'For individuals.',
        'priceMonthly' => '4.99',
        'priceYearly'  => '49.99',
        'currency'     => 'GBP',
        'featuresJSON' => json_encode(['expiry_scheduling' => true, 'webhooks' => true]),
        'limitsJSON'   => json_encode(['saved_codes' => 500, 'bulk_batch_size' => 500, 'dynamic_codes' => 100]),
    ],
];

$twoPlanRows = pricingDisplayRowsFromPlans($twoPlans);

assertEquals(2, count($twoPlanRows), 'pricingDisplayRowsFromPlans: returns one display row per input plan');

// 🔤 Ordering stability: the display rows preserve the INPUT array's order
// (the caller - listPublicPlansForDisplay() - is responsible for the actual
// sortOrder/planID ordering; this pure function must not reshuffle it).
assertEquals('free', $twoPlanRows[0]['planKey'], 'pricingDisplayRowsFromPlans: preserves input order (plan 0 = free)');
assertEquals('pro', $twoPlanRows[1]['planKey'], 'pricingDisplayRowsFromPlans: preserves input order (plan 1 = pro)');

// 💷 Valid JSON decode + price formatting flowed through correctly.
assertEquals(true, $twoPlanRows[0]['isZeroPrice'], 'pricingDisplayRowsFromPlans: free plan is flagged isZeroPrice');
assertEquals('Free', $twoPlanRows[0]['priceMonthlyFormatted'], 'pricingDisplayRowsFromPlans: free plan price formats as "Free"');
assertEquals(false, $twoPlanRows[1]['isZeroPrice'], 'pricingDisplayRowsFromPlans: pro plan is NOT flagged isZeroPrice');
assertEquals('£4.99', $twoPlanRows[1]['priceMonthlyFormatted'], 'pricingDisplayRowsFromPlans: pro plan monthly price formats correctly');
assertEquals('£49.99', $twoPlanRows[1]['priceYearlyFormatted'], 'pricingDisplayRowsFromPlans: pro plan yearly price formats correctly');

// 🚩 Feature union: both plans list the SAME 2 feature keys, in the same order.
assertEquals(2, count($twoPlanRows[0]['features']), 'pricingDisplayRowsFromPlans: free plan features list has both union keys');
assertEquals(2, count($twoPlanRows[1]['features']), 'pricingDisplayRowsFromPlans: pro plan features list has both union keys');
assertEquals('expiry_scheduling', $twoPlanRows[0]['features'][0]['key'], 'pricingDisplayRowsFromPlans: feature union preserves first-seen key order (0)');
assertEquals('webhooks', $twoPlanRows[0]['features'][1]['key'], 'pricingDisplayRowsFromPlans: feature union preserves first-seen key order (1)');
assertEquals(false, $twoPlanRows[0]['features'][0]['enabled'], 'pricingDisplayRowsFromPlans: free plan expiry_scheduling is disabled');
assertEquals(true, $twoPlanRows[1]['features'][0]['enabled'], 'pricingDisplayRowsFromPlans: pro plan expiry_scheduling is enabled');

// 🔢 Limit union: pro plan defines dynamic_codes, free plan does not - free's
// row for that key must still be PRESENT (union) and render fail-closed "—".
assertEquals(3, count($twoPlanRows[0]['limits']), 'pricingDisplayRowsFromPlans: free plan limits list has all 3 union keys (including one it never set)');
assertEquals('dynamic_codes', $twoPlanRows[0]['limits'][2]['key'], 'pricingDisplayRowsFromPlans: dynamic_codes is present in the union for the free plan row');
assertEquals('—', $twoPlanRows[0]['limits'][2]['value'], 'pricingDisplayRowsFromPlans: a limit key the free plan never set fails closed to "—", not "0"');
assertEquals('100', $twoPlanRows[1]['limits'][2]['value'], 'pricingDisplayRowsFromPlans: pro plan\'s own dynamic_codes value renders correctly');

// 🚨 Malformed JSON in BOTH columns -> safe empty arrays, no warning/throw.
$malformedPlan = [
    [
        'planID'       => 9,
        'planKey'      => 'broken',
        'planName'     => 'Broken',
        'description'  => '',
        'priceMonthly' => '1.00',
        'priceYearly'  => '10.00',
        'currency'     => 'GBP',
        'featuresJSON' => '{not valid json',
        'limitsJSON'   => 'null',
    ],
];

$malformedRows = pricingDisplayRowsFromPlans($malformedPlan);

assertEquals(1, count($malformedRows), 'pricingDisplayRowsFromPlans: malformed JSON still produces exactly one display row (fails safe, does not drop the plan)');
assertEquals([], $malformedRows[0]['features'], 'pricingDisplayRowsFromPlans: malformed featuresJSON decodes to a safe empty array');
assertEquals([], $malformedRows[0]['limits'], 'pricingDisplayRowsFromPlans: limitsJSON that decodes to non-array ("null") also fails safe to an empty array');
assertEquals('£1.00', $malformedRows[0]['priceMonthlyFormatted'], 'pricingDisplayRowsFromPlans: price formatting is unaffected by the malformed JSON columns');

// ==============================================================================
// 📋 Section 5: listPublicPlansForDisplay() - no-DB fail-safe
// ==============================================================================

assertFalse(function_exists('dbQuery'), 'sanity: dbQuery() is genuinely undefined in this harness');
assertEquals([], listPublicPlansForDisplay(), 'listPublicPlansForDisplay: fails safe to an empty array when dbQuery() is unavailable, no warning/throw');

// ==============================================================================
// 🧬 Section 6: STATIC regression guards (source-text inspection, no execution)
// ==============================================================================

// ------------------------------------------------------------------
// 6a. fn_pricing.php: zero hardcoded plan-key literals.
// ------------------------------------------------------------------
// Unlike fn_features.php (which intentionally quotes 'anonymous' and defines
// CUERCODE_PLAN_KEY_FREE as its two documented fallback-key exceptions), this
// file has NO fallback-key concept at all - every one of the four seeded
// plan-key literals must be completely absent from its source.
// ------------------------------------------------------------------

$pricingEngineSource = file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_pricing.php');

assertTrue(
    is_string($pricingEngineSource) && $pricingEngineSource !== '',
    'regression guard: fn_pricing.php source was readable for static inspection'
);

foreach (['anonymous', 'free', 'pro', 'business'] as $seededPlanKey) {
    assertFalse(
        str_contains($pricingEngineSource, "'" . $seededPlanKey . "'"),
        'regression guard: fn_pricing.php never quotes the seeded plan-key literal \''
            . $seededPlanKey . '\' - every plan/feature/limit is discovered generically from decoded JSON'
    );
}

// 🔐 Lightweight security-pattern regression guard (house standing rule:
// prepared statements for ALL queries) - mirrors test_features.php's own
// guard technique exactly.
assertTrue(
    str_contains($pricingEngineSource, 'dbQuery('),
    'regression guard: fn_pricing.php\'s one query goes through the prepared-statement dbQuery() wrapper'
);
assertFalse(
    (bool) preg_match('/dbQuery\(\s*[\'"][^\'"]*\'\s*\.\s*\$/', $pricingEngineSource),
    'regression guard: fn_pricing.php never string-concatenates a variable directly into SQL text'
);

// ------------------------------------------------------------------
// 6b. pricing/index.php: no <table> tag, matches accessibility/index.php's
//     bootstrap require depth exactly.
// ------------------------------------------------------------------

$pricingPagePath = CUERCODE_SITE_ROOT . 'public_html' . DIRECTORY_SEPARATOR
    . 'pricing' . DIRECTORY_SEPARATOR . 'index.php';
$pricingPageSource = file_get_contents($pricingPagePath);

assertTrue(
    is_string($pricingPageSource) && $pricingPageSource !== '',
    'regression guard: pricing/index.php source was readable for static inspection'
);

assertFalse(
    (bool) stripos($pricingPageSource, '<table'),
    'regression guard: pricing/index.php never uses a <table> tag for layout (house rule)'
);

$accessibilityPagePath = CUERCODE_SITE_ROOT . 'public_html' . DIRECTORY_SEPARATOR
    . 'accessibility' . DIRECTORY_SEPARATOR . 'index.php';
$accessibilityPageSource = file_get_contents($accessibilityPagePath);

// 📌 Extract each page's bootstrap require_once line via the same regex, and
// assert they are byte-for-byte IDENTICAL - both pages sit exactly one
// directory below public_html/, so their relative path back to
// _includes/bootstrap.php must match exactly (the spec's "do not
// hand-compute ../ depth" instruction, verified structurally here).
$bootstrapLinePattern = '/require_once __DIR__ \. \'[^\']*_includes[\/\\\\]bootstrap\.php\';/';

$pricingBootstrapMatch = [];
$accessibilityBootstrapMatch = [];

preg_match($bootstrapLinePattern, $pricingPageSource, $pricingBootstrapMatch);
preg_match($bootstrapLinePattern, $accessibilityPageSource, $accessibilityBootstrapMatch);

assertTrue(
    isset($pricingBootstrapMatch[0]) && $pricingBootstrapMatch[0] !== '',
    'regression guard: pricing/index.php contains a recognisable bootstrap.php require_once line'
);
assertTrue(
    isset($accessibilityBootstrapMatch[0]) && $accessibilityBootstrapMatch[0] !== '',
    'regression guard: accessibility/index.php contains a recognisable bootstrap.php require_once line'
);
assertEquals(
    $accessibilityBootstrapMatch[0] ?? null,
    $pricingBootstrapMatch[0] ?? null,
    'regression guard: pricing/index.php\'s bootstrap require line matches accessibility/index.php\'s '
        . 'depth pattern EXACTLY (both sit one directory below public_html/)'
);
