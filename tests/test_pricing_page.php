<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Tests for the public pricing page and its admin preview
 *    (task 7n Phase 2, slice 2d)
 * ==============================================================================
 *
 * Path: tests/test_pricing_page.php
 *
 * Covers, WITHOUT a database:
 *
 *   1. The kind filter and sale dates (pricingPlanIsOnGrid()), and
 *      listPublicPlansForDisplay() against a stand-in database in a CHILD
 *      process - including a database without upgrade 0004's columns.
 *   2. The "Contact us" card: "From <monthly>/month", never "Free", and its
 *      link (own address, else the site email; nothing dangerous).
 *   3. Hidden prices (pricing_show_prices off).
 *   4. The cards as rendered (_includes/pricing_grid.php): escaping, buttons,
 *      VAT wording.
 *   5. The preview: price points per currency, honest gaps, and the page's
 *      access rules (static: gate first, noindex, banner, no JavaScript).
 *
 * ⚠️ NOT covered here: the pages over real HTTP with a real database (done by
 *    hand, see tests/README.md).
 *
 * ⚠️ House rules (shared global scope): every variable is prefixed $pp and this
 *    file never defines dbQuery(), getConfig() or logError() itself - the
 *    stand-ins exist only inside the child process.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-26 (task 7n Phase 2, slice 2d - #31)
 * @see         web/CueRCode.net/_functions/fn_pricing.php
 * @see         web/CueRCode.net/_includes/pricing_grid.php
 */

requireFn('fn_pricing.php');

// ==============================================================================
// 🚪 Section 1: which plans are on the grid
// ==============================================================================
$ppNow = '2026-11-26 12:00:00';
assertTrue(pricingPlanIsOnGrid(['planKind' => 'standard'], $ppNow), 'kind filter: an ordinary plan is shown');
assertFalse(pricingPlanIsOnGrid(['planKind' => 'lifetime_deal'], $ppNow), 'kind filter: a lifetime deal is not shown');
assertFalse(pricingPlanIsOnGrid(['planKind' => 'credit_packs'], $ppNow), 'kind filter: credit packs are not shown');
assertFalse(pricingPlanIsOnGrid(['planKind' => ''], $ppNow), 'kind filter: an unknown kind is not shown');
assertTrue(pricingPlanIsOnGrid(['planName' => 'Old row'], $ppNow), 'kind filter: without the upgrade 0004 column a plan counts as ordinary');
assertFalse(pricingPlanIsOnGrid(['planKind' => 'standard', 'availableFrom' => '2026-11-26 12:00:01'], $ppNow), 'sale dates: not shown before the start');
assertTrue(pricingPlanIsOnGrid(['planKind' => 'standard', 'availableFrom' => $ppNow, 'availableUntil' => $ppNow], $ppNow), 'sale dates: both ends are included');
assertFalse(pricingPlanIsOnGrid(['planKind' => 'standard', 'availableUntil' => '2026-11-26 11:59:59'], $ppNow), 'sale dates: not shown after the end');
assertTrue(pricingPlanIsOnGrid(['planKind' => 'standard', 'availableFrom' => null, 'availableUntil' => null], $ppNow), 'sale dates: none set = shown');

// 🧒 listPublicPlansForDisplay() with a stand-in database, in a child process
//    (this process must never define dbQuery()).
$ppChildSource = <<<'PPCHILD'
<?php
$root = $argv[1];
require $root . 'tests' . DIRECTORY_SEPARATOR . 'bootstrap.php';
class PpResult { private array $rows; public function __construct(array $rows) { $this->rows = $rows; } public function fetch_assoc(): ?array { return array_shift($this->rows); } }
class PpStatement { private array $rows; public function __construct(array $rows) { $this->rows = $rows; } public function get_result(): PpResult { return new PpResult($this->rows); } public function close(): void {} }
function dbQuery(string $sql, string $types = '', array $params = []): PpStatement
{
    global $argv;
    $GLOBALS['ppSql'][] = $sql;
    if (str_contains($sql, '`tblPlanPricePoints`')) {
        if ($argv[2] === 'freepriced') {
            // 🆓 Decision 0041: older data left a price on sale on the Free plan.
            return new PpStatement([['planID' => 1, 'pricePointID' => 9, 'priceKey' => 'monthly_gbp', 'priceGroupKey' => 'monthly', 'billingInterval' => 'monthly', 'intervalCount' => 1, 'amount' => '3.00', 'currency' => 'GBP', 'grantsJSON' => null, 'validFrom' => null, 'validUntil' => null, 'isDefault' => 0, 'isActive' => 1, 'sortOrder' => 1], ['planID' => 2]]);
        }
        return new PpStatement([['planID' => 2]]);
    }
    $old = [
        ['planID' => 1, 'planKey' => CUERCODE_PLAN_KEY_FREE, 'planName' => 'One', 'priceMonthly' => '0.00', 'priceYearly' => '0.00', 'currency' => 'GBP'],
        ['planID' => 2, 'planKey' => 'two', 'planName' => 'Two', 'priceMonthly' => '0.00', 'priceYearly' => '0.00', 'currency' => 'GBP'],
    ];
    if ($argv[2] === 'freepriced') {
        $old[0]['priceMonthly'] = '3.00';
        $old[0]['priceYearly'] = '30.00';
    }
    if ($argv[2] === 'before') {
        return new PpStatement($old);
    }
    $new = $old;
    $new[0]['planKind'] = 'standard';
    $new[1]['planKind'] = 'standard';
    $new[] = ['planID' => 3, 'planKey' => 'deal', 'planName' => 'Deal', 'planKind' => 'lifetime_deal'];
    $new[] = ['planID' => 4, 'planKey' => 'packs', 'planName' => 'Packs', 'planKind' => 'credit_packs'];
    $new[] = ['planID' => 5, 'planKey' => 'later', 'planName' => 'Later', 'planKind' => 'standard', 'availableFrom' => '2999-01-01 00:00:00'];
    return new PpStatement($new);
}
requireFn('fn_pricing.php');
$rows = listPublicPlansForDisplay();
$display = pricingDisplayRowsFromPlans($rows);
echo json_encode(['sql' => $GLOBALS['ppSql'], 'ids' => array_column($rows, 'planID'), 'free' => array_column($display, 'isZeroPrice'), 'cta' => array_column($display, 'ctaMode')]);
PPCHILD;

$ppChildFile = (string) tempnam(sys_get_temp_dir(), 'cuercode_pp_');
file_put_contents($ppChildFile, $ppChildSource);
$ppRuns = [];
foreach (['after', 'before', 'freepriced'] as $ppMode) {
    $ppPipes = [];
    $ppProcess = proc_open([PHP_BINARY, $ppChildFile, CUERCODE_TEST_REPO_ROOT, $ppMode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $ppPipes);
    $ppOut = (string) stream_get_contents($ppPipes[1]);
    $ppErr = (string) stream_get_contents($ppPipes[2]);
    fclose($ppPipes[1]);
    fclose($ppPipes[2]);
    $ppExit = proc_close($ppProcess);
    assertEquals(0, $ppExit, 'listing child (' . $ppMode . ') exits cleanly: ' . $ppErr);
    $ppRuns[$ppMode] = json_decode($ppOut, true);
}
unlink($ppChildFile);

assertEquals([1, 2], $ppRuns['after']['ids'], 'listing: lifetime deals, credit packs and not-yet-on-sale plans are left out');
assertEquals([true, false], $ppRuns['after']['free'], 'listing: a plan that has a price is never called Free, even with 0.00 in the old columns');
assertEquals([1, 2], $ppRuns['before']['ids'], 'listing before upgrade 0004: the plans still show');
// 🆓 Decision 0041 (#175): a price found on the Free plan (older data: a price
//    row on sale and figures in its older columns) is ignored, so its card
//    stays "Free" with the "Create free account" button.
assertEquals([true, false], $ppRuns['freepriced']['free'], '0041: the Free plan with an old price row and old figures is still Free (its sign-up button stays)');
assertEquals(['checkout', 'checkout'], $ppRuns['before']['cta'], 'listing before upgrade 0004: every button is the ordinary one');
foreach (['planKind', 'ctaMode', 'availableFrom', 'priceGroupKey'] as $ppNewColumn) {
    assertFalse(str_contains(implode(' ', $ppRuns['before']['sql']), $ppNewColumn), 'listing SQL never names the upgrade 0004 column ' . $ppNewColumn);
}

// ==============================================================================
// ✉️ Section 2: the "Contact us" card
// ==============================================================================
$ppPlanRow = static function (array $ppOver): array {
    return array_merge([
        'planID' => 7, 'planKey' => 'probe', 'planName' => 'Probe', 'description' => '', 'currency' => 'GBP',
        'priceMonthly' => '0.00', 'priceYearly' => '0.00', 'featuresJSON' => '{}', 'limitsJSON' => '{}',
        'planKind' => 'standard', 'ctaMode' => 'checkout', 'contactUrl' => null,
    ], $ppOver);
};
$ppDisplay = static function (array $ppOver) use ($ppPlanRow): array {
    return pricingDisplayRowsFromPlans([$ppPlanRow($ppOver)])[0];
};
$ppContact = $ppDisplay(['ctaMode' => 'contact', 'priceMonthly' => '150.00']);
$ppBlock = pricingPriceBlock($ppContact, true);
assertEquals(['From', '£150', '/month'], [$ppBlock['prefix'], $ppBlock['headline'], $ppBlock['suffix']], 'contact card: "From £150/month"');
assertFalse(str_contains(json_encode($ppBlock), 'Free'), 'contact card: a yearly figure of 0.00 is never shown as "Free"');
assertFalse($ppContact['isZeroPrice'], 'contact card: never treated as a free plan');
assertEquals('From £150.50', 'From ' . pricingPriceBlock($ppDisplay(['ctaMode' => 'contact', 'priceMonthly' => '150.50']), true)['headline'], 'contact card: pence are kept when there are some');
// 🗄️ Decision 0042: the three-decimal text the columns now give back.
assertEquals(['From', '£150', '/month'], array_slice(array_values(pricingPriceBlock($ppDisplay(['ctaMode' => 'contact', 'priceMonthly' => '150.000', 'priceYearly' => '0.000']), true)), 0, 3), '0042: contact card: "150.000" still reads "From £150/month"');
assertEquals("KWD\u{00A0}99", pricingPriceBlock($ppDisplay(['ctaMode' => 'contact', 'priceMonthly' => '99.000', 'currency' => 'KWD']), true)['headline'], '0042: contact card: a whole number of dinars reads "From KWD 99", without ".000"');
assertEquals("KWD\u{00A0}99.500", pricingPriceBlock($ppDisplay(['ctaMode' => 'contact', 'priceMonthly' => '99.500', 'currency' => 'KWD']), true)['headline'], '0042: contact card: fils are kept when there are some');
assertEquals(['£6.99', 'or £49.99 billed yearly'], [pricingPriceBlock($ppDisplay(['priceMonthly' => '6.990', 'priceYearly' => '49.990']), true)['headline'], pricingPriceBlock($ppDisplay(['priceMonthly' => '6.990', 'priceYearly' => '49.990']), true)['subline']], '0042: an ordinary card with "6.990" / "49.990" reads £6.99, or £49.99 billed yearly');
$ppContactNone = pricingPriceBlock($ppDisplay(['ctaMode' => 'contact']), true);
assertEquals('Price on request', $ppContactNone['headline'], 'contact card with no price at all: "Price on request", not "Free"');
assertFalse($ppDisplay(['ctaMode' => 'contact'])['isZeroPrice'], 'contact plan at zero is still not free');
assertEquals('or from £1,500 billed yearly', pricingPriceBlock($ppDisplay(['ctaMode' => 'contact', 'priceMonthly' => '150.00', 'priceYearly' => '1500.00']), true)['subline'], 'contact card: a yearly figure above zero is shown');

// 📅 Ordinary plans.
$ppPaid = pricingPriceBlock($ppDisplay(['priceMonthly' => '3.50']), true);
assertEquals('Billed monthly', $ppPaid['subline'], 'a monthly-only plan never says "or Free billed yearly"');
$ppYearOnly = pricingPriceBlock($ppDisplay(['priceYearly' => '35.00']), true);
assertEquals(['£35.00', '/year'], [$ppYearOnly['headline'], $ppYearOnly['suffix']], 'a yearly-only plan shows its yearly price, not "Free"');
assertFalse($ppDisplay(['priceYearly' => '35.00'])['isZeroPrice'], 'a yearly-only plan is not free');
$ppFreeBlock = pricingPriceBlock($ppDisplay(['planKey' => CUERCODE_PLAN_KEY_FREE]), true);
assertEquals('Free', $ppFreeBlock['headline'], 'the free plan, with nothing to pay and no price, is Free');
assertEquals('Price coming soon', pricingPriceBlock($ppDisplay(['hasPricePoint' => true]), true)['headline'], 'a priced plan showing 0.00 is not called Free');

// 🏷️ Decision 0021 (#160): only the plans people get without paying (the
//    visitors' plan and the free plan new accounts are given) can be "Free".
//    Any other plan with no price yet says "Price coming soon", with no
//    sign-up button.
$ppNoPriceYet = $ppDisplay(['planKey' => 'team_plus', 'planName' => 'Team plus']);
assertFalse($ppNoPriceYet['isZeroPrice'], '#160: a paid plan put on sale before any price is entered is NOT free');
assertEquals('Price coming soon', pricingPriceBlock($ppNoPriceYet, true)['headline'], '#160: ...its card says "Price coming soon"');
assertEquals('Price to be announced', pricingPriceBlock($ppNoPriceYet, false)['headline'], '#160: ...and with prices hidden it is not "Free" either');
assertTrue(pricingIsNoPaymentPlan(CUERCODE_PLAN_KEY_FREE) && pricingIsNoPaymentPlan(CUERCODE_PLAN_KEY_ANONYMOUS) && !pricingIsNoPaymentPlan('team_plus') && !pricingIsNoPaymentPlan(''), '#160: the no-payment plans are exactly the two fallback plans');
$ppFreeAccountPlan = $ppDisplay(['planKey' => CUERCODE_PLAN_KEY_FREE]);
assertTrue($ppFreeAccountPlan['isZeroPrice'] && pricingPriceBlock($ppFreeAccountPlan, true)['headline'] === 'Free', '#160: the free plan new accounts get still says "Free"');

// 🔗 Where "Contact us" goes.
assertEquals('https://example.com/sales?x=1', pricingContactLink('https://example.com/sales?x=1', 'a@example.com'), 'contact link: the plan\'s https address');
assertEquals('mailto:sales@example.com', pricingContactLink('mailto:sales@example.com', 'a@example.com'), 'contact link: the plan\'s mailto address');
assertEquals('mailto:team@example.com', pricingContactLink(null, 'team@example.com'), 'contact link: no address = the site email');
foreach (['javascript:alert(1)', 'http://example.com', 'data:text/html,x', '//example.com', 'https://', "https://example.com/\" onclick=\"x", 'mailto:a@example.com?bcc=b@example.com', 'mailto:'] as $ppBad) {
    assertEquals('mailto:team@example.com', pricingContactLink($ppBad, 'team@example.com'), 'contact link refuses ' . var_export($ppBad, true));
}
assertEquals('mailto:hello@cuercode.net', pricingContactLink(null, 'not an address'), 'contact link: a broken site email falls back');

// ==============================================================================
// 👁️ Section 3: hidden prices
// ==============================================================================
foreach ([false, 0, '0', 'false', 'off'] as $ppOff) {
    assertFalse(pricingShowPrices($ppOff), 'show prices: off for ' . var_export($ppOff, true));
}
foreach ([true, 1, '1', null] as $ppOn) {
    assertTrue(pricingShowPrices($ppOn), 'show prices: on for ' . var_export($ppOn, true));
}

/**
 * 🖨️ Render the shared cards exactly as the pages include them.
 */
function ppRenderGrid(array $pricingDisplayPlans, bool $pricingShowPrices, string $pricingPreviewCurrency = '', string $pricingSiteEmail = 'team@example.com', array $pricingPromiseDefaults = ['mode' => null, 'months' => null]): string
{
    $pricingVatNote = 'Prices include VAT';
    ob_start();
    require CUERCODE_INCLUDES_DIR . 'pricing_grid.php';
    return (string) ob_get_clean();
}

$ppPlans = pricingDisplayRowsFromPlans([
    $ppPlanRow(['planID' => 1, 'planKey' => CUERCODE_PLAN_KEY_FREE, 'planName' => 'Starter']),
    $ppPlanRow(['planID' => 2, 'planName' => 'Paid', 'priceMonthly' => '3.50', 'priceYearly' => '35.00']),
    $ppPlanRow(['planID' => 3, 'planName' => 'Big <b>"x"</b>', 'description' => '<script>alert(1)</script>', 'ctaMode' => 'contact', 'priceMonthly' => '150.00']),
]);
$ppShown = ppRenderGrid($ppPlans, true);
assertContains('£3.50', $ppShown, 'cards: the monthly price');
assertContains('or £35.00 billed yearly', $ppShown, 'cards: the yearly price');
assertContains('£150', $ppShown, 'cards: the contact "from" figure');
assertContains('Prices include VAT', $ppShown, 'cards: the VAT wording');
assertEquals(1, substr_count($ppShown, 'Coming soon'), 'cards: the one paid plan\'s buy button says "Coming soon"');
assertContains('href="mailto:team@example.com"', $ppShown, 'cards: the contact button links to the site email');
assertContains('Create free account', $ppShown, 'cards: the free plan links to registration');
assertFalse(str_contains($ppShown, '<script'), 'cards: an admin-typed description is escaped');
assertFalse(str_contains($ppShown, '<b>'), 'cards: an admin-typed name is escaped');
assertContains('Big &lt;b&gt;&quot;x&quot;&lt;/b&gt;', $ppShown, 'cards: the name shows as plain text');
assertFalse(str_contains($ppShown, '<table'), 'cards: no table');
$ppNoPriceShown = ppRenderGrid([$ppNoPriceYet], true);
assertFalse(str_contains($ppNoPriceShown, 'Create free account') || str_contains($ppNoPriceShown, '/register/'), '#160: a paid plan with no price has no sign-up button');
assertContains('Price coming soon', $ppNoPriceShown, '#160: ...and its card says "Price coming soon"');
$ppBig = ppRenderGrid([$ppDisplay(['priceMonthly' => '99999.99'])], true);
assertContains('<p class="fs-2 fw-bold mb-0">', $ppBig, 'cards: a very long figure is set smaller so it is never split');
assertContains('<p class="display-6 fw-bold mb-0">', $ppShown, 'cards: an ordinary figure keeps the large size');

$ppHidden = ppRenderGrid($ppPlans, false);
assertFalse(str_contains($ppHidden, '£'), 'hidden prices: no price figure anywhere');
assertFalse(str_contains($ppHidden, '3.50') || str_contains($ppHidden, '35.00') || str_contains($ppHidden, '150'), 'hidden prices: no amount anywhere');
assertFalse(str_contains($ppHidden, 'VAT'), 'hidden prices: no VAT wording about figures that are not there');
assertContains('Price to be announced', $ppHidden, 'hidden prices: a paid plan says so');
assertContains('Price on request', $ppHidden, 'hidden prices: a contact plan says so');
assertContains('Free', $ppHidden, 'hidden prices: the free plan still says Free');
assertContains('Starter', $ppHidden, 'hidden prices: the plans still show');
assertContains('Contact us', $ppHidden, 'hidden prices: the contact button still shows');

$ppPageSource = (string) file_get_contents(CUERCODE_SITE_ROOT . 'public_html' . DIRECTORY_SEPARATOR . 'pricing' . DIRECTORY_SEPARATOR . 'index.php');
assertContains("pricingShowPrices(getConfig('pricing_show_prices', true))", $ppPageSource, 'the pricing page reads pricing_show_prices');
assertContains("getConfig('site_email'", $ppPageSource, 'the pricing page reads site_email');

// ==============================================================================
// 🔭 Section 5: the preview
// ==============================================================================
$ppPreviewSource = [
    $ppPlanRow(['planID' => 1, 'planKey' => CUERCODE_PLAN_KEY_FREE, 'planName' => 'Starter', 'hasPricePoint' => false]),
    $ppPlanRow(['planID' => 2, 'planName' => 'Paid', 'priceMonthly' => '3.50', 'priceYearly' => '35.00', 'hasPricePoint' => true]),
    $ppPlanRow(['planID' => 3, 'planName' => 'Big', 'ctaMode' => 'contact', 'priceMonthly' => '150.00', 'hasPricePoint' => true]),
];
$ppUsd = pricingDisplayRowsFromPlans(pricingPreviewRows($ppPreviewSource, [2 => ['monthly' => '4.25', 'yearly' => null]], 'USD'));
assertEquals('Free', pricingPriceBlock($ppUsd[0], true, 'USD')['headline'], 'preview: a free plan stays free in every currency');
$ppUsdPaid = pricingPriceBlock($ppUsd[1], true, 'USD');
assertEquals(['$4.25', 'No yearly price in USD yet'], [$ppUsdPaid['headline'], $ppUsdPaid['subline']], 'preview: the price point is used and a missing one is said to be missing');
assertEquals('No monthly price in USD yet', pricingPriceBlock($ppUsd[2], true, 'USD')['headline'], 'preview: a contact plan without that currency says so');
$ppEur = pricingDisplayRowsFromPlans(pricingPreviewRows($ppPreviewSource, [], 'EUR'));
assertEquals('No price in EUR yet', pricingPriceBlock($ppEur[1], true, 'EUR')['headline'], 'preview: no price at all in a currency is said honestly');
assertFalse($ppEur[1]['isZeroPrice'], 'preview: a missing price never makes a plan free');
assertFalse(str_contains(ppRenderGrid($ppEur, true, 'EUR'), '£'), 'preview: no figure is borrowed from another currency');
$ppYearOnlyPreview = pricingDisplayRowsFromPlans(pricingPreviewRows($ppPreviewSource, [2 => ['monthly' => null, 'yearly' => '40.00']], 'EUR'));
assertEquals('€40.00 billed yearly', pricingPriceBlock($ppYearOnlyPreview[1], true, 'EUR')['subline'], 'preview: a yearly price alone is shown under the missing monthly one');

// ==============================================================================
// 🗓️ Section 6: the page reads the price list LIVE (review finding F6)
// ==============================================================================
$ppListNow = '2026-11-27 12:00:00';
$ppListPrice = static function (array $ppOver): array {
    return array_merge(['pricePointID' => 1, 'planID' => 7, 'priceGroupKey' => 'monthly', 'currency' => 'GBP', 'amount' => '24.99', 'isActive' => 1, 'validFrom' => null, 'validUntil' => null, 'sortOrder' => 1], $ppOver);
};
$ppStale = $ppPlanRow(['planKey' => 'business_like', 'priceMonthly' => '24.99', 'priceYearly' => '199.99']);
$ppLive = pricingApplyPriceList($ppStale, [
    $ppListPrice(['validUntil' => '2026-11-27 11:59:59']),
    $ppListPrice(['pricePointID' => 2, 'priceGroupKey' => 'yearly', 'amount' => '199.99', 'sortOrder' => 2]),
], 'GBP', $ppListNow);
assertEquals(['0', '199.99', 'GBP'], [$ppLive['priceMonthly'], $ppLive['priceYearly'], $ppLive['currency']], 'F6: an ended monthly price is no longer shown although the older column still says 24.99');
assertEquals('£199.99', pricingPriceBlock(pricingDisplayRowsFromPlans([$ppLive])[0], true)['headline'], 'F6: ...so the card shows the yearly price only');
$ppStarted = pricingApplyPriceList($ppStale, [$ppListPrice(['amount' => '26.99', 'validFrom' => '2026-11-27 11:00:00']), $ppListPrice(['pricePointID' => 3, 'amount' => '28.00', 'validFrom' => '2026-11-28 00:00:00'])], 'GBP', $ppListNow);
assertEquals('26.99', $ppStarted['priceMonthly'], 'F6: a price whose start has passed is shown; one starting tomorrow is not');
assertEquals('199.99', $ppStarted['priceYearly'], 'F6: a group with no price rows keeps the older column');
$ppEnded = pricingDisplayRowsFromPlans([pricingApplyPriceList($ppStale, [$ppListPrice(['isActive' => 0]), $ppListPrice(['pricePointID' => 2, 'priceGroupKey' => 'yearly', 'validUntil' => '2026-01-01 00:00:00'])], 'GBP', $ppListNow)])[0];
assertEquals('Price coming soon', pricingPriceBlock($ppEnded, true)['headline'], 'F6 + decision 0021: nothing on sale for a paid plan - "Price coming soon", never the old figures');
assertEquals($ppStale, pricingApplyPriceList($ppStale, [$ppListPrice(['priceGroupKey' => null])], 'GBP', $ppListNow), 'F6: a plan priced only the old way (no grouped price rows) is left exactly as it is');
$ppOtherCurrency = pricingApplyPriceList($ppStale, [$ppListPrice([])], 'USD', $ppListNow);
assertEquals(['24.99', '199.99', 'GBP'], [$ppOtherCurrency['priceMonthly'], $ppOtherCurrency['priceYearly'], $ppOtherCurrency['currency']], 'F6: main currency USD but no USD monthly price - the plan keeps showing its own GBP prices (as the copy into the older columns does)');
$ppSwitched = pricingApplyPriceList($ppStale, [$ppListPrice([]), $ppListPrice(['pricePointID' => 4, 'currency' => 'USD', 'amount' => '29.00'])], 'USD', $ppListNow);
assertEquals(['29.00', '', 'USD'], [$ppSwitched['priceMonthly'], $ppSwitched['priceYearly'], $ppSwitched['currency']], 'F6: with a USD monthly price the card switches to USD, and the GBP-only yearly figure is not mixed in');
$ppFreeRetired = pricingDisplayRowsFromPlans([pricingApplyPriceList($ppPlanRow(['planKey' => CUERCODE_PLAN_KEY_FREE]), [$ppListPrice(['isActive' => 0, 'amount' => '1.00'])], 'GBP', $ppListNow)])[0];
assertTrue($ppFreeRetired['isZeroPrice'], 'F6: the free plan whose only price was retired is Free again');

// 🎯 Codex finding 3.3 (7n Phase 2 review): ONE rule chooses the figure, on
//    the public card and on the admin preview alike - the price on sale now
//    in the monthly (or yearly) GROUP, lowest display order first. Before the
//    fix, switching billing_price_points_enabled on let the card pick a
//    different row by billing interval and the "recommended" flag instead.
$ppCardAndPreview = static function (array $ppPriceRows) use ($ppPlanRow, $ppListNow): array {
    $ppPublic = pricingApplyPriceList($ppPlanRow(['priceMonthly' => '7.99', 'priceYearly' => '0.00']), $ppPriceRows, 'GBP', $ppListNow);
    // What listPublicPlansForDisplay() attaches while the switch is on.
    $ppPublic['pricePoints'] = $ppPriceRows;
    $ppCard = pricingPriceBlock(pricingDisplayRowsFromPlans([$ppPublic])[0], true);
    $ppPreview = pricingPriceBlock(pricingDisplayRowsFromPlans(pricingPreviewRows([$ppPublic], [7 => pricingCurrentGroupPrices($ppPriceRows, 'GBP', $ppListNow)], 'GBP'))[0], true, 'GBP');
    return [$ppCard['headline'], $ppPreview['headline']];
};
// Two monthly offers in the monthly group: the second is marked recommended.
$ppTwoOffers = [
    $ppListPrice(['pricePointID' => 11, 'billingInterval' => 'monthly', 'amount' => '7.99', 'isDefault' => 0, 'sortOrder' => 1]),
    $ppListPrice(['pricePointID' => 12, 'billingInterval' => 'monthly', 'amount' => '9.99', 'isDefault' => 1, 'sortOrder' => 2]),
];
assertEquals(['£7.99', '£7.99'], $ppCardAndPreview($ppTwoOffers), 'finding 3.3: two monthly offers - the card and the preview both show the group\'s first (£7.99), not the one flagged recommended');
// An older ungrouped monthly row (no price group), flagged recommended.
$ppUngrouped = [
    $ppListPrice(['pricePointID' => 11, 'billingInterval' => 'monthly', 'amount' => '7.99', 'isDefault' => 0, 'sortOrder' => 1]),
    $ppListPrice(['pricePointID' => 13, 'priceGroupKey' => null, 'billingInterval' => 'monthly', 'amount' => '9.99', 'isDefault' => 1, 'sortOrder' => 0]),
];
assertEquals(['£7.99', '£7.99'], $ppCardAndPreview($ppUngrouped), 'finding 3.3: an older monthly row outside the monthly group never replaces the group\'s price');
// A sale that has ended in the group is followed by both, whatever the price-point list says.
$ppEndedOffer = [
    $ppListPrice(['pricePointID' => 11, 'billingInterval' => 'monthly', 'amount' => '5.99', 'validUntil' => '2026-11-01 00:00:00', 'sortOrder' => 1]),
    $ppListPrice(['pricePointID' => 14, 'billingInterval' => 'monthly', 'amount' => '7.99', 'sortOrder' => 2]),
];
assertEquals(['£7.99', '£7.99'], $ppCardAndPreview($ppEndedOffer), 'finding 3.3: an ended sale price shows on neither (sale dates are part of the one rule)');

// 📜 Codex finding 3.4: the preview keeps a price set the OLDER way (the
//    plan's own monthly / yearly column) when that group has no price rows
//    at all and the plan's currency is the one previewed - as the public page
//    does. It used to say "No price in GBP yet" while visitors saw £12.00.
$ppLegacyOnly = $ppPlanRow(['planID' => 8, 'planKey' => 'old_way', 'priceMonthly' => '12.00', 'priceYearly' => '120.00', 'hasPricePoint' => false]);
$ppLegacyPublic = pricingPriceBlock(pricingDisplayRowsFromPlans([$ppLegacyOnly])[0], true);
$ppLegacyPreview = pricingPriceBlock(pricingDisplayRowsFromPlans(pricingPreviewRows([$ppLegacyOnly], [8 => pricingCurrentGroupPrices([], 'GBP', $ppListNow)], 'GBP'))[0], true, 'GBP');
assertEquals(['£12.00', 'or £120.00 billed yearly'], [$ppLegacyPublic['headline'], $ppLegacyPublic['subline']], 'finding 3.4 setup: the public card shows the older columns (£12.00, £120.00 yearly)');
assertEquals([$ppLegacyPublic['headline'], $ppLegacyPublic['subline']], [$ppLegacyPreview['headline'], $ppLegacyPreview['subline']], 'finding 3.4: the GBP preview shows the same figures');
$ppLegacyUsd = pricingPriceBlock(pricingDisplayRowsFromPlans(pricingPreviewRows([$ppLegacyOnly], [8 => pricingCurrentGroupPrices([], 'USD', $ppListNow)], 'USD'))[0], true, 'USD');
assertEquals('No price in USD yet', $ppLegacyUsd['headline'], 'finding 3.4: ...but a USD preview never borrows the GBP figure');
// Monthly managed by price rows (none in GBP on sale), yearly only in the older column.
$ppHalf = pricingApplyPriceList($ppPlanRow(['planID' => 9, 'priceMonthly' => '12.00', 'priceYearly' => '120.00']), [$ppListPrice(['pricePointID' => 21, 'planID' => 9, 'currency' => 'USD', 'amount' => '15.00'])], 'GBP', $ppListNow);
$ppHalfPublic = pricingPriceBlock(pricingDisplayRowsFromPlans([$ppHalf])[0], true);
$ppHalfPreview = pricingPriceBlock(pricingDisplayRowsFromPlans(pricingPreviewRows([$ppHalf], [9 => pricingCurrentGroupPrices([$ppListPrice(['pricePointID' => 21, 'planID' => 9, 'currency' => 'USD', 'amount' => '15.00'])], 'GBP', $ppListNow)], 'GBP'))[0], true, 'GBP');
assertEquals('£120.00', $ppHalfPublic['headline'], 'finding 3.4 setup: monthly is managed (no GBP row on sale), yearly comes from the older column');
assertEquals(['No monthly price in GBP yet', '£120.00 billed yearly'], [$ppHalfPreview['headline'], $ppHalfPreview['subline']], 'finding 3.4: the preview keeps the older yearly figure and says the monthly one is missing');

// 🛑 Codex finding 3.5: when the price list cannot be read, the page must not
//    show the older copied figures (a sale may have started or ended) nor
//    call a plan "Free" - every card says the price is unavailable.
$ppUnavailable = pricingDisplayRowsFromPlans([
    $ppPlanRow(['planID' => 1, 'planKey' => CUERCODE_PLAN_KEY_FREE, 'priceUnavailable' => true]),
    $ppPlanRow(['planID' => 2, 'planKey' => 'paid', 'priceMonthly' => '24.99', 'priceUnavailable' => true]),
    $ppPlanRow(['planID' => 3, 'planKey' => 'big', 'ctaMode' => 'contact', 'priceMonthly' => '99.00', 'priceUnavailable' => true]),
]);
foreach ($ppUnavailable as $ppUnavailablePlan) {
    $ppUnavailableBlock = pricingPriceBlock($ppUnavailablePlan, true);
    assertEquals('Price unavailable', $ppUnavailableBlock['headline'], 'finding 3.5: ' . $ppUnavailablePlan['planKey'] . ' says "Price unavailable" when the price list could not be read');
    assertFalse($ppUnavailablePlan['isZeroPrice'], 'finding 3.5: ' . $ppUnavailablePlan['planKey'] . ' is never "Free" then (no "Create free account" button)');
}
$ppUnavailableGrid = ppRenderGrid($ppUnavailable, true);
assertFalse(str_contains($ppUnavailableGrid, '24.99') || str_contains($ppUnavailableGrid, '£99') || str_contains($ppUnavailableGrid, '>Free<'), 'finding 3.5: no stale figure and no "Free" anywhere on the page');
$ppFetchSource = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_pricing.php');
$ppFetchBody = substr($ppFetchSource, (int) strpos($ppFetchSource, 'function listPublicPlansForDisplay('), 6000);
assertContains("'priceUnavailable'] = true", $ppFetchBody, 'finding 3.5: a failed price-list read marks the plans as unavailable');
assertContains("logError('error', 'Public pricing price list failed'", $ppFetchBody, 'finding 3.5: ...and is still logged');

// ==============================================================================
// 🤝 Section 7: each card states its OWN price promise (decision 0020, #159)
// ==============================================================================
assertEquals('Your price stays the same for as long as you stay subscribed.', pricingPromiseText('while_subscribed', null, 'none', 12), '#159: a plan\'s own promise wins over the site-wide one');
assertEquals('Your price stays the same for the first 6 months of your subscription.', pricingPromiseText('fixed_months', 6, 'while_subscribed', 12), '#159: a fixed number of months, from the plan');
assertEquals('Your price stays the same for the first month of your subscription.', pricingPromiseText('fixed_months', '1', 'while_subscribed', 12), '#159: one month reads naturally');
assertEquals('This price can change; a change would apply from your next payment.', pricingPromiseText('none', null, 'while_subscribed', 12), '#159: no promise is said plainly');
assertEquals('Your price stays the same for the first 12 months of your subscription.', pricingPromiseText(null, null, 'fixed_months', '12'), '#159: a plan that sets nothing follows the site-wide promise and months');
assertEquals('Your price stays the same for the first 24 months of your subscription.', pricingPromiseText('fixed_months', 24, 'fixed_months', 12), '#159: the plan\'s own months win');
foreach ([[null, null, null, null], ['fixed_months', 0, 'none', 12], ['fixed_months', 'x', 'none', 12], [null, null, 'fixed_months', 121], ['made_up', null, 'also_made_up', 12]] as $ppBadPromise) {
    assertEquals('', pricingPromiseText($ppBadPromise[0], $ppBadPromise[1], $ppBadPromise[2], $ppBadPromise[3]), '#159: an unknown or unreadable promise promises NOTHING (never more than is set): ' . json_encode($ppBadPromise));
}
$ppPromisePlans = pricingDisplayRowsFromPlans([
    $ppPlanRow(['planID' => 1, 'planKey' => CUERCODE_PLAN_KEY_FREE, 'planName' => 'Starter']),
    $ppPlanRow(['planID' => 2, 'planName' => 'Six', 'priceMonthly' => '3.50', 'pricePromiseMode' => 'fixed_months', 'pricePromiseMonths' => 6]),
    $ppPlanRow(['planID' => 3, 'planName' => 'Loose', 'priceMonthly' => '4.50', 'pricePromiseMode' => 'none']),
    $ppPlanRow(['planID' => 4, 'planName' => 'Default', 'priceMonthly' => '5.50']),
    $ppPlanRow(['planID' => 5, 'planKey' => 'no_price_yet', 'planName' => 'Later']),
]);
$ppPromiseShown = ppRenderGrid($ppPromisePlans, true, '', 'team@example.com', ['mode' => 'while_subscribed', 'months' => 12]);
assertFalse(str_contains($ppPromiseShown, 'always'), '#159: the old fixed sentence ("we will always honour your price") is gone');
assertContains('for the first 6 months of your subscription', $ppPromiseShown, '#159: the six-month plan says six months');
assertContains('This price can change', $ppPromiseShown, '#159: the plan with no promise says so');
assertEquals(1, substr_count($ppPromiseShown, 'for as long as you stay subscribed'), '#159: only the plan using the site-wide promise says "for as long as you stay subscribed" (not the free plan, not the plan with no price yet)');
assertFalse(str_contains(ppRenderGrid($ppPromisePlans, false, '', 'team@example.com', ['mode' => 'while_subscribed', 'months' => 12]), 'Your price stays'), '#159: with prices hidden there is no price to keep, so no promise is shown');
assertEquals(1, substr_count(ppRenderGrid($ppPromisePlans, true), 'Your price stays the same for the first 6 months'), '#159: a page that passes no site-wide promise still shows a plan\'s own one');

// 🤝 Recheck finding 4: the note above the cards may only say "Each plan says
//    how long you keep the price" when every priced card really does. Before
//    upgrade 0004 (no promise settings or columns) no card states a promise,
//    and the note said so anyway (reproduced over HTTP by the recheck).
$ppEachPlanSays = 'Each plan says how long you keep the price you sign up at.';
$ppNoPromiseShown = ppRenderGrid($ppPlans, true);
assertFalse(str_contains($ppNoPromiseShown, 'Your price stays') || str_contains($ppNoPromiseShown, 'This price can change'), 'recheck F4 setup: before upgrade 0004 no card states a promise');
assertFalse(str_contains($ppNoPromiseShown, 'Each plan says') || str_contains($ppNoPromiseShown, 'its card says'), 'recheck F4: ...so the note above the cards does not claim that any plan states one');
assertContains('Prices shown are introductory pricing and may change.', $ppNoPromiseShown, 'recheck F4: ...and still says prices may change');
assertContains($ppEachPlanSays, $ppPromiseShown, 'recheck F4: every priced card states a promise - the note says each plan does');
$ppSomePromiseShown = ppRenderGrid($ppPromisePlans, true);
assertFalse(str_contains($ppSomePromiseShown, $ppEachPlanSays), 'recheck F4: only some priced cards state a promise - the note does not say "each plan"');
assertContains('Where a plan promises to keep your price, its card says for how long.', $ppSomePromiseShown, 'recheck F4: ...it says where a plan promises, its card says so');
assertEquals('', pricingPromiseNote([]), 'recheck F4: no priced card at all - no promise wording');
assertEquals('', pricingPromiseNote(['', '']), 'recheck F4: no card states a promise - no promise wording');
assertEquals($ppEachPlanSays, pricingPromiseNote(['a', 'b']), 'recheck F4: every card states one - "each plan"');

// 💱 Price points in several currencies (and, since Codex finding 3.3, any
//    attached price point at all) never become the headline: the figure is
//    the plan row's own, which the page has already chosen by price group.
$ppMixed = $ppDisplay(['priceMonthly' => '3.50', 'pricePoints' => [
    ['priceKey' => 'monthly_usd', 'billingInterval' => 'monthly', 'amount' => '4.25', 'currency' => 'USD', 'isDefault' => 1],
    ['priceKey' => 'monthly_gbp', 'billingInterval' => 'monthly', 'amount' => '3.75', 'currency' => 'GBP', 'isDefault' => 0],
]]);
assertEquals('£3.50', $ppMixed['priceMonthlyFormatted'], 'price points: neither another currency\'s nor the plan\'s own attached price point replaces the group-chosen figure (finding 3.3)');

// 🔐 The preview page: gate first, private, no JavaScript needed, changes nothing.
$ppPreviewPage = (string) file_get_contents(CUERCODE_SITE_ROOT . 'public_html' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'pricing-preview' . DIRECTORY_SEPARATOR . 'index.php');
$ppGateAt = strpos($ppPreviewPage, "requireGlobalAdminPage('/admin/pricing-preview/')");
assertTrue($ppGateAt !== false, 'preview access: only a Global Admin (the gate, with its own path)');
assertTrue($ppGateAt !== false && $ppGateAt < strpos($ppPreviewPage, "fn_admin_plans.php'") && $ppGateAt < strpos($ppPreviewPage, '$_GET'), 'preview access: the gate comes before any data or input');
assertContains('$pageNoIndex     = true;', $ppPreviewPage, 'preview: not indexed by search engines');
assertContains('Preview - not public', $ppPreviewPage, 'preview: the banner');
assertContains("href=\"/admin/pricing-preview/?currency=", $ppPreviewPage, 'preview: the currency switcher is ordinary links');
assertFalse((bool) preg_match('/<script\b|\son[a-z]+\s*=\s*["\']|<form\b/i', $ppPreviewPage), 'preview: no inline script and no form (it changes nothing)');
assertFalse((bool) preg_match('/billing_(price_points|discounts|payg)_enabled|setConfig\(/', $ppPreviewPage), 'preview: never touches a charging switch');
$ppNav = (string) file_get_contents(CUERCODE_INCLUDES_DIR . 'admin_nav.php');
assertContains("'/admin/pricing-preview/'", $ppNav, 'the admin menu links to the preview');
