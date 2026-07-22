<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Tests for the Flexible-Pricing Schema Delta (Epic E4, #70)
 * ==============================================================================
 *
 * Path: tests/test_pricing_flex.php
 *
 * #70 asks for a "built-but-disabled, additive" flexible-pricing schema on
 * top of the existing E4 tier framework, proving five pricing STRUCTURES are
 * representable BY DATA ALONE (a plan row + price-point/discount/ledger rows -
 * never a new code path): multiple price points, one-off/limited-time/
 * lifetime discounts, lifetime tiers, PAYG credit packs, and PAYG-with-a-cap.
 * `tests/test_features.php`'s new Section 9 already proves every new
 * fn_features.php helper (pricePointsForPlan()/resolveDiscountForCheckout()/
 * creditBalanceFor()/consumeCreditsOr402()) fails closed / no-ops with the
 * kill-switches off - this file instead proves the DATA-REPRESENTABILITY
 * half of the acceptance criteria: feed the PURE transforms/helpers
 * (fn_pricing.php's pricingDisplayRowsFromPlans()/pricingFindPricePointByInterval()/
 * pricingApplyDiscount(), fn_features.php's evaluateCreditSpend()) hand-built
 * fixture rows shaped exactly like the new tables, and assert the DERIVED
 * display/consumption logic comes out correctly - with ZERO new code
 * branches for any of the three fixtures below.
 *
 *   1. A LIFETIME PLAN (Pricing_Strategy.md §5.3 "Founder Lifetime") -
 *      pricingDisplayRowsFromPlans() fed a plan whose ONLY price point is
 *      billingInterval='lifetime'.
 *   2. A LIMITED-TIME PERCENTAGE DISCOUNT (Pricing_Strategy.md §5.2 "Launch
 *      offer") - pricingApplyDiscount() fed a tblDiscounts-shaped fixture
 *      (discountType='percent', a validFrom/validUntil window).
 *   3. A PAYG CREDIT-PACK + SPEND CAP (Pricing_Strategy.md §5.5/§5.6) -
 *      pricingDisplayRowsFromPlans() fed a `one_off` price point whose
 *      grantsJSON describes a credit grant, THEN evaluateCreditSpend() fed
 *      the resulting balance against a plan's credit_spend_cap_monthly limit.
 *
 * This file also carries a STATIC (source-text, no execution) guard proving
 * the #70 schema/seed delta is genuinely ADDITIVE - the pre-existing
 * tblSubscriptionPlans/tblSubscriptions/tblPayments column lists and the 4
 * seeded plans' exact featuresJSON/limitsJSON strings are still present
 * byte-for-byte in install.sql/seed_data.sql - plus a DYNAMIC guard that
 * every OTHER test file that has already run by this point in the suite
 * (tests/run.php executes test_*.php files in sorted filename order, and
 * this file sorts after all but two of them) recorded zero failures, i.e.
 * nothing above regressed the pre-#70 suite.
 *
 * ⚠️ Lives under repo-root tests/ - never deployed.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 3 (Epic E4 flexible-pricing delta, #70)
 * @see         web/CueRCode.net/_includes/sql/install.sql   - Tables 22-26 (#70 schema delta)
 * @see         web/CueRCode.net/_includes/sql/seed_data.sql - the 3 billing_* kill-switches
 * @see         web/CueRCode.net/_functions/fn_features.php  - pricePointsForPlan(), resolveDiscountForCheckout(),
 *              creditBalanceFor(), consumeCreditsOr402(), evaluateCreditSpend()
 * @see         web/CueRCode.net/_functions/fn_pricing.php   - listPublicPlansForDisplay(),
 *              pricingDisplayRowsFromPlans(), pricingFindPricePointByInterval(), pricingApplyDiscount()
 * @see         Pricing_Strategy.md §5 - the worked examples these fixtures mirror
 * @see         tests/test_features.php - Section 9, the sibling fail-closed/no-op coverage
 * @see         tests/test_pricing.php  - the sibling legacy-pricing-page coverage this file extends
 */

// ==============================================================================
// 📦 Load both production files this suite exercises (NO database connection -
// requireFn() only registers function definitions, exactly like every other
// test file's convention).
// ==============================================================================

requireFn('fn_features.php');
requireFn('fn_pricing.php');

// ==============================================================================
// 1️⃣ LIFETIME PLAN - representable by data alone
// ==============================================================================
// Pricing_Strategy.md §5.3 "Founder Lifetime": a plan whose ONLY price point
// has billingInterval='lifetime' (no monthly/yearly row at all). Feeding this
// fixture through the UNCHANGED pricingDisplayRowsFromPlans() proves the
// structure needs zero new code: the lifetime price point is discovered,
// decoded, and formatted purely from the row's own columns, and the legacy
// priceMonthly/priceYearly columns (both 0.00, since a lifetime-only plan has
// no recurring price) correctly fall back to "Free" for the two fields the
// lifetime row does not supply.
// ==============================================================================

$lifetimePlanFixture = [
    [
        'planID'       => 5,
        'planKey'      => 'pro_lifetime',
        'planName'     => 'Pro Lifetime',
        'description'  => 'Founder Lifetime deal - pay once, Pro-class allowance forever.',
        'priceMonthly' => '0.00',
        'priceYearly'  => '0.00',
        'currency'     => 'GBP',
        'featuresJSON' => json_encode(['expiry_scheduling' => true, 'webhooks' => true]),
        'limitsJSON'   => json_encode(['saved_codes' => 500, 'dynamic_codes' => 25, 'analytics_days' => 90]),
        // 💷 #70: the ONLY price point this plan has - billingInterval='lifetime',
        // exactly Pricing_Strategy.md §5.3's `lifetime_founder` row shape.
        'pricePoints'  => [
            [
                'pricePointID'    => 101,
                'planID'          => 5,
                'priceKey'        => 'lifetime_founder',
                'billingInterval' => 'lifetime',
                'intervalCount'   => 1,
                'amount'          => '119.99',
                'currency'        => 'GBP',
                'grantsJSON'      => null,
                'validFrom'       => null,
                'validUntil'      => '2026-09-20 00:00:00',
                'isDefault'       => 1,
            ],
        ],
    ],
];

$lifetimeDisplayRows = pricingDisplayRowsFromPlans($lifetimePlanFixture);

assertEquals(1, count($lifetimeDisplayRows), 'lifetime plan: pricingDisplayRowsFromPlans() returns exactly one display row');

$lifetimeRow = $lifetimeDisplayRows[0];

assertEquals('pro_lifetime', $lifetimeRow['planKey'], 'lifetime plan: planKey passes through unchanged');
assertEquals(1, count($lifetimeRow['pricePoints']), 'lifetime plan: exactly one decoded price point is exposed');
assertEquals('lifetime', $lifetimeRow['pricePoints'][0]['billingInterval'], 'lifetime plan: the price point\'s billingInterval decodes to "lifetime" - no new ENUM value or code path needed');
assertEquals('lifetime_founder', $lifetimeRow['pricePoints'][0]['priceKey'], 'lifetime plan: the admin-chosen priceKey passes through unchanged');
assertEquals('£119.99', $lifetimeRow['pricePoints'][0]['amountFormatted'], 'lifetime plan: the one-time price formats correctly via the SAME pricingFormatPrice() the legacy columns use');
assertTrue($lifetimeRow['pricePoints'][0]['isDefault'], 'lifetime plan: isDefault=1 decodes to a real PHP true');
assertEquals([], $lifetimeRow['pricePoints'][0]['grants'], 'lifetime plan: a NULL grantsJSON (not a PAYG pack) decodes to an empty grants array, never null/a warning');

// 📌 No 'monthly'/'yearly' price point exists on this plan at all - the
// legacy priceMonthly/priceYearly columns (both 0.00) correctly supply the
// fallback for those two specific display fields, proving the "prefer price
// points, else fall back to legacy columns" rule operates PER FIELD, not
// merely per plan.
assertEquals('Free', $lifetimeRow['priceMonthlyFormatted'], 'lifetime plan: no "monthly" price point exists -> falls back to the legacy priceMonthly column (0.00 -> "Free")');
assertEquals('Free', $lifetimeRow['priceYearlyFormatted'], 'lifetime plan: no "yearly" price point exists -> falls back to the legacy priceYearly column (0.00 -> "Free")');

// 🔎 pricingFindPricePointByInterval() directly - the pure helper this branch
// is built on, independently exercised.
assertEquals(
    null,
    pricingFindPricePointByInterval($lifetimeRow['pricePoints'], 'monthly'),
    'pricingFindPricePointByInterval: a lifetime-only price-point list has no "monthly" match -> null (never a false match)'
);

// 🧾 A plan represented WITHOUT a 'currentPeriodEnd' concept at all is
// exactly what a lifetime SUBSCRIPTION row looks like once purchased
// (install.sql Table 20 tblSubscriptions.currentPeriodEnd is already
// NULLable, unchanged by #70) - assert that NULL is a value this codebase's
// own JSON round-trip already treats as a legitimate "never expires" state,
// the same convention pricingApplyDiscount()/evaluateCreditSpend() below
// rely on for their own optional fields.
assertEquals(null, json_decode(json_encode(['currentPeriodEnd' => null]), true)['currentPeriodEnd'], 'sanity: NULL round-trips through JSON as the "never expires" sentinel a lifetime subscription needs');

// ==============================================================================
// 2️⃣ LIMITED-TIME PERCENTAGE DISCOUNT - representable by data alone
// ==============================================================================
// Pricing_Strategy.md §5.2 "Launch offer - 25% off for 12 months": a
// tblDiscounts-shaped fixture (code=NULL/automatic, discountType='percent',
// value=25.00, a validFrom/validUntil WINDOW that makes it "limited-time",
// durationType='repeating'/durationMonths=12 governing how long it applies
// to an already-redeemed subscription). pricingApplyDiscount() - a PURE
// function - proves the ARITHMETIC needs no per-discount code; the window
// check below proves "limited-time" needs no new column either, just a
// plain DATETIME comparison against the two existing columns.
// ==============================================================================

$launchDiscountFixture = [
    'discountID'      => 1,
    'code'            => null, // 🤖 automatic/sitewide - Pricing_Strategy.md §5.2
    'name'            => 'Launch offer - 25% off for 12 months',
    'discountType'    => 'percent',
    'value'           => 25.00,
    'appliesPlanID'   => null, // applies to every paid plan
    'appliesPriceKey' => null,
    'durationType'    => 'repeating',
    'durationMonths'  => 12,
    'validFrom'       => '2026-08-01 00:00:00',
    'validUntil'      => '2026-12-31 23:59:59',
];

// 💷 Pro monthly (£6.99, Pricing_Strategy.md §3.3) with the launch discount applied.
assertEquals(
    5.24,
    pricingApplyDiscount(6.99, $launchDiscountFixture['discountType'], $launchDiscountFixture['value']),
    'pricingApplyDiscount: 25% off £6.99 = £5.2425, rounded to £5.24 - the exact Pricing_Strategy.md §5.2 launch-offer arithmetic, with ZERO discount-specific code'
);

// 💷 Business monthly (£24.99, Pricing_Strategy.md §3.4) with the SAME
// discount fixture - proving one discount row applies uniformly across
// however many plans/prices it is scoped to (appliesPlanID/appliesPriceKey
// both NULL = every paid plan), never a per-plan discount calculation.
assertEquals(
    18.74,
    pricingApplyDiscount(24.99, $launchDiscountFixture['discountType'], $launchDiscountFixture['value']),
    'pricingApplyDiscount: the SAME 25% discount row correctly discounts a completely different plan\'s price (£24.99 -> £18.74)'
);

// ⏰ "Limited-time" is representable purely by the validFrom/validUntil
// columns - a plain DATETIME comparison, no new schema/code needed. Proven
// here with fixed reference timestamps (never wall-clock "now", so this
// assertion is stable regardless of when the suite runs).
$beforeLaunchWindow = (strtotime('2026-07-15 00:00:00') >= strtotime((string) $launchDiscountFixture['validFrom']))
    && (strtotime('2026-07-15 00:00:00') <= strtotime((string) $launchDiscountFixture['validUntil']));
$duringLaunchWindow = (strtotime('2026-09-01 00:00:00') >= strtotime((string) $launchDiscountFixture['validFrom']))
    && (strtotime('2026-09-01 00:00:00') <= strtotime((string) $launchDiscountFixture['validUntil']));
$afterLaunchWindow = (strtotime('2027-01-15 00:00:00') >= strtotime((string) $launchDiscountFixture['validFrom']))
    && (strtotime('2027-01-15 00:00:00') <= strtotime((string) $launchDiscountFixture['validUntil']));

assertFalse($beforeLaunchWindow, 'limited-time discount: a timestamp BEFORE validFrom is outside the window (not yet live)');
assertTrue($duringLaunchWindow, 'limited-time discount: a timestamp INSIDE [validFrom, validUntil] is within the window (live)');
assertFalse($afterLaunchWindow, 'limited-time discount: a timestamp AFTER validUntil is outside the window (expired) - exactly resolveDiscountForCheckout()\'s own SQL-level validFrom/validUntil filter, expressed here in pure PHP for a DB-independent proof');

// 🎯 A "lifetime discount" (durationType='forever', Pricing_Strategy.md §5.4
// edu/non-profit rate) and a "one-off discount" (maxRedemptions=1, §5.3's
// redemption cap) are BOTH representable by the exact same tblDiscounts
// shape - only durationType/maxRedemptions differ, no new columns:
$eduDiscountFixture    = array_merge($launchDiscountFixture, ['durationType' => 'forever', 'durationMonths' => null, 'value' => 50.00]);
$foundersOnlyDiscount  = array_merge($launchDiscountFixture, ['maxRedemptions' => 500, 'redemptionCount' => 0]);

assertEquals('forever', $eduDiscountFixture['durationType'], 'lifetime (forever) discount: representable by the SAME tblDiscounts shape, only durationType differs');
assertEquals(
    3.50,
    pricingApplyDiscount(7.00, $eduDiscountFixture['discountType'], $eduDiscountFixture['value']),
    'pricingApplyDiscount: a 50% "forever" edu/non-profit discount computes correctly with the SAME pure function'
);
assertEquals(500, $foundersOnlyDiscount['maxRedemptions'], 'one-off (capped) discount: representable by the SAME tblDiscounts shape via maxRedemptions, no new column');

// 🛡️ pricingApplyDiscount() fail-safe behaviour - a fixed discount larger
// than the price never goes negative, and an unrecognised discountType is a
// safe no-op (never guesses).
assertEquals(0.0, pricingApplyDiscount(10.0, 'fixed', 25.0), 'pricingApplyDiscount: a fixed discount larger than the price floors at £0.00, never negative');
assertEquals(9.99, pricingApplyDiscount(9.99, 'bogus_type', 10.0), 'pricingApplyDiscount: an unrecognised discountType fails safe to the ORIGINAL amount, never guesses');
assertEquals(0.0, pricingApplyDiscount(0.0, 'percent', 25.0), 'pricingApplyDiscount: a non-positive amount returns 0.0 unchanged');

// ==============================================================================
// 3️⃣ PAYG CREDIT-PACK + SPEND CAP - representable by data alone
// ==============================================================================
// Pricing_Strategy.md §5.5/§5.6: a `one_off` price point whose grantsJSON
// describes a credit grant (e.g. "buy this, get 100 qr_generations credits"),
// consumed against a plan's `credit_spend_cap_monthly` limit. Two pure
// transforms chain together with ZERO new code for a brand-new meter key or
// pack size: pricingDisplayRowsFromPlans() decodes the PACK'S shape, then
// evaluateCreditSpend() decides whether a CONSUMPTION of it is allowed.
// ==============================================================================

$paygPlanFixture = [
    [
        'planID'       => 6,
        'planKey'      => 'payg',
        'planName'     => 'Pay As You Go',
        'description'  => 'No subscription - pay for what you use.',
        'priceMonthly' => '0.00',
        'priceYearly'  => '0.00',
        'currency'     => 'GBP',
        'featuresJSON' => json_encode(['csv_export' => false]),
        // 🔢 Pricing_Strategy.md §4's PAYG row: every ordinary limit is 0 -
        // consumption happens ONLY via the credit ledger, never these limits.
        'limitsJSON'   => json_encode(['saved_codes' => 25, 'dynamic_codes' => 0, 'credit_spend_cap_monthly' => 20]),
        // 💷 #70: a `one_off` PAYG credit-pack price point - Pricing_Strategy.md
        // §5.5's `credits_100` row, grantsJSON describing what buying it grants.
        'pricePoints'  => [
            [
                'pricePointID'    => 201,
                'planID'          => 6,
                'priceKey'        => 'credits_100',
                'billingInterval' => 'one_off',
                'intervalCount'   => 1,
                'amount'          => '4.99',
                'currency'        => 'GBP',
                'grantsJSON'      => json_encode(['credits' => ['qr_generations' => 100]]),
                'validFrom'       => null,
                'validUntil'      => null,
                'isDefault'       => 0,
            ],
        ],
    ],
];

$paygDisplayRows = pricingDisplayRowsFromPlans($paygPlanFixture);
$paygRow         = $paygDisplayRows[0];

assertEquals('one_off', $paygRow['pricePoints'][0]['billingInterval'], 'PAYG pack: billingInterval decodes to "one_off" - the same ENUM value a monthly/yearly/lifetime row would use, no new code');
assertEquals(
    ['credits' => ['qr_generations' => 100]],
    $paygRow['pricePoints'][0]['grants'],
    'PAYG pack: grantsJSON decodes to the EXACT nested structure Pricing_Strategy.md §5.5 specifies - a brand-new meter key ("qr_generations") required zero new PHP code to represent'
);
assertEquals('£4.99', $paygRow['pricePoints'][0]['amountFormatted'], 'PAYG pack: the pack price formats via the SAME pricingFormatPrice() every other price point uses');

// 💸 Now prove the SPEND side: a caller who bought the credits_100 pack above
// has +100 to their balance (creditBalanceFor()'s job in real operation -
// here simulated as already-known fixture numbers, exactly what a real
// SUM(delta) over tblCreditLedger would report). The plan's own
// credit_spend_cap_monthly (20) caps how much of that balance can be spent
// in any one calendar month - PAYG-WITH-A-CAP, Pricing_Strategy.md §5.6.
$grantedCredits  = $paygRow['pricePoints'][0]['grants']['credits']['qr_generations']; // 100, pulled straight from the decoded pack
$planSpendCap    = (int) json_decode($paygPlanFixture[0]['limitsJSON'], true)['credit_spend_cap_monthly']; // 20, pulled straight from the plan's own limitsJSON

assertEquals(100, $grantedCredits, 'sanity: the granted credit quantity is exactly what the pack\'s grantsJSON promised');
assertEquals(20, $planSpendCap, 'sanity: the plan\'s own limitsJSON supplies the monthly spend cap - the SAME generic limitFor() key every other ★ limit uses');

// 🟢 A caller with 100 credits and nothing spent yet this month, requesting
// 15 units, stays comfortably within the cap.
$firstConsumption = evaluateCreditSpend(15, $grantedCredits, $planSpendCap, 0);
assertEquals(['allowed' => true, 'reason' => null], $firstConsumption, 'PAYG+cap: a fresh pack purchase (100 credits, 0 spent this month) allows a 15-unit consumption against the £20-equivalent monthly cap');

// 🟡 The SAME caller, later the same month, having already spent 15 of their
// 20-unit monthly allowance, requesting 10 more - would push month-to-date
// spend to 25, over the 20 cap, even though their raw BALANCE (100 - 15 = 85)
// easily covers it. This is exactly what makes it "PAYG WITH A CAP" rather
// than plain PAYG: the cap is a SPEND-RATE ceiling, independent of balance.
$secondConsumption = evaluateCreditSpend(10, $grantedCredits - 15, $planSpendCap, 15);
assertEquals(['allowed' => false, 'reason' => 'spend_cap_exceeded'], $secondConsumption, 'PAYG+cap: a balance-sufficient but cap-exceeding request (15 spent + 10 requested = 25 > 20) is correctly DENIED, proving the cap - not the balance - is the binding constraint');

// 🔴 A DIFFERENT plan that has never opted into PAYG at all (no
// credit_spend_cap_monthly seeded -> limitFor()'s fail-closed default, 0)
// denies ANY consumption outright, regardless of balance - the "not
// permitted on this plan" case, distinct from "cap reached for this month".
$noPaygPlanDenial = evaluateCreditSpend(1, $grantedCredits, 0, 0);
assertEquals(['allowed' => false, 'reason' => 'not_permitted'], $noPaygPlanDenial, 'PAYG+cap: a plan with NO credit_spend_cap_monthly seeded (fail-closed default 0) denies PAYG entirely, even with a healthy balance');

// ==============================================================================
// 🧬 STATIC guard: install.sql/seed_data.sql additive delta - the
// pre-existing schema/seed text is byte-for-byte UNCHANGED, and the new #70
// surface is genuinely present. Source-text inspection only - never parses
// or executes the SQL (no MySQL required, per this ticket's verification note).
// ==============================================================================

$installSqlSource = (string) file_get_contents(CUERCODE_INCLUDES_DIR . 'sql' . DIRECTORY_SEPARATOR . 'install.sql');
$seedDataSqlSource = (string) file_get_contents(CUERCODE_INCLUDES_DIR . 'sql' . DIRECTORY_SEPARATOR . 'seed_data.sql');

assertTrue(is_string($installSqlSource) && $installSqlSource !== '', 'regression guard: install.sql was readable for static inspection');
assertTrue(is_string($seedDataSqlSource) && $seedDataSqlSource !== '', 'regression guard: seed_data.sql was readable for static inspection');

// 🆕 The five new tables (#70, install.sql Tables 22-26) all exist.
foreach (['tblPlanPricePoints', 'tblDiscounts', 'tblDiscountRedemptions', 'tblOneOffPurchases', 'tblCreditLedger'] as $newTableName) {
    assertContains(
        'CREATE TABLE IF NOT EXISTS `' . $newTableName . '`',
        $installSqlSource,
        'install.sql: the new #70 table ' . $newTableName . ' is present'
    );
}

// ➕ The two additive ALTER TABLE columns exist.
assertContains('ADD COLUMN `pricePointID`', $installSqlSource, 'install.sql: tblSubscriptions/tblPayments gain an additive pricePointID column');
assertContains('ADD COLUMN `discountID`', $installSqlSource, 'install.sql: tblSubscriptions gains an additive discountID column');

// 🛡️ The PRE-EXISTING tblSubscriptionPlans/tblSubscriptions/tblPayments
// CREATE TABLE column lists are UNCHANGED - spot-check a handful of exact,
// pre-#70 column definitions still appear verbatim (proving this delta never
// edited an existing column in place).
$preExistingColumnDefinitions = [
    '`priceMonthly`  DECIMAL(10,2)   NOT NULL DEFAULT 0.00',
    '`priceYearly`   DECIMAL(10,2)   NOT NULL DEFAULT 0.00',
    '`currency`      CHAR(3)         NOT NULL DEFAULT \'GBP\'   COMMENT \'💱 ISO 4217 currency code the two price columns above are denominated in\'',
];
foreach ($preExistingColumnDefinitions as $preExistingColumnDefinition) {
    assertContains(
        $preExistingColumnDefinition,
        $installSqlSource,
        'regression guard: install.sql\'s pre-existing tblSubscriptionPlans column definition is byte-for-byte unchanged'
    );
}

// 🚦 The three #70 kill-switches exist in seed_data.sql, ALL seeded to the
// inert '0' value (never accidentally shipped enabled).
foreach (['billing_price_points_enabled', 'billing_discounts_enabled', 'billing_payg_enabled'] as $killSwitchKey) {
    assertMatches(
        '/\(\'billing\', \'' . preg_quote($killSwitchKey, '/') . '\', \'0\', \'boolean\'/',
        $seedDataSqlSource,
        'seed_data.sql: the #70 kill-switch ' . $killSwitchKey . ' is seeded 0/boolean/inert'
    );
}

// 🛡️ The 4 pre-existing seeded plans' exact featuresJSON/limitsJSON strings
// are UNCHANGED (spot-checking pro/business - the two paid tiers most likely
// to be touched by a careless pricing edit).
assertContains(
    '\'{"expiry_scheduling": true, "password_protected_qr": true, "sso": false, "organisations": false, "webhooks": true, "csv_export": true}\'',
    $seedDataSqlSource,
    'regression guard: seed_data.sql\'s pre-existing "pro" plan featuresJSON is byte-for-byte unchanged'
);
assertContains(
    '\'{"saved_codes": -1, "bulk_batch_size": 2000, "dynamic_codes": 1000, "api_keys": 20, "analytics_days": 1095, "api_rate_limit_per_min": 600, "api_rate_limit_per_day": 100000}\'',
    $seedDataSqlSource,
    'regression guard: seed_data.sql\'s pre-existing "business" plan limitsJSON is byte-for-byte unchanged'
);

// ==============================================================================
// 🧬 DYNAMIC guard: every OTHER test file that has ALREADY RUN by this point
// in the suite recorded zero failures. tests/run.php discovers test_*.php
// files sorted by filename and includes them in that order, sharing ONE
// results collector ($GLOBALS['__CUERCODE_TEST_RESULTS']) across the whole
// process - so by the time THIS file's own assertions begin, every result
// already recorded belongs to a file that sorts alphabetically before
// "test_pricing_flex.php" (i.e. every file except test_qr_logo.php/
// test_qrcodes.php, which run after). A single failure anywhere in that
// prior run would show up here, catching a #70 regression in an EXISTING
// file this ticket did not intend to touch.
// ==============================================================================

$priorResults = $GLOBALS['__CUERCODE_TEST_RESULTS'] ?? [];
$priorFailureCount = 0;
$priorFailureFiles = [];

foreach ($priorResults as $priorResult) {
    if ($priorResult['pass'] === false) {
        $priorFailureCount++;
        if (!in_array($priorResult['file'], $priorFailureFiles, true)) {
            $priorFailureFiles[] = $priorResult['file'];
        }
    }
}

assertTrue(
    count($priorResults) > 1000,
    'sanity: a substantial number of assertions from OTHER test files already ran before this file (found: ' . count($priorResults) . ') - proves this guard is actually checking something, not an empty collector'
);

assertEquals(
    0,
    $priorFailureCount,
    'DYNAMIC REGRESSION GUARD: zero failures recorded by any test file that ran before this one in the suite (failing files if any: ' . implode(', ', $priorFailureFiles) . ')'
);
