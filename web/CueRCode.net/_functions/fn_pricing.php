<?php
/**
 * ==============================================================================
 * 💷 CueRCode - Public Pricing / Plan-Comparison Display Helpers
 * ==============================================================================
 *
 * Path: _functions/fn_pricing.php
 *
 * Pure, unit-testable helpers backing the public plan-comparison page
 * (public_html/pricing/index.php - Epic E4, #56). This file is deliberately
 * NOT loaded by bootstrap.php - it is require_once'd by pricing/index.php
 * ONLY, mirroring how other page-specific helper files are scoped in this
 * codebase (e.g. fn_openapi_render.php for the developer reference page).
 *
 * OWNER REQUIREMENT this file inherits from fn_features.php (the gating
 * engine it sits alongside): tblSubscriptionPlans is the SINGLE SOURCE OF
 * TRUTH for every tier's name/price/features/limits. This file contains NO
 * hardcoded plan-key literals whatsoever - the two fallback keys are only
 * ever reached through fn_features.php's constants, and only to decide which
 * plans may read "Free" (pricingIsNoPaymentPlan(), decision 0021) - every plan
 * this page renders, and every feature/limit row within each plan's card, is
 * discovered GENERICALLY from the decoded featuresJSON/limitsJSON of
 * whatever rows the database actually returns. Adding a 5th tier, renaming a
 * feature, or changing a price is a pure SQL row edit - this file never
 * needs to change. (A static regression guard in tests/test_pricing.php
 * scans this file's own source text for every seeded plan-key string and
 * asserts none of them appear quoted anywhere below, including in comments.)
 *
 * DESIGN DECISION (not fully dictated by the originating issue - flagged for
 * the orchestrator/owner): the seeded no-account gating tier
 * (tblSubscriptionPlans sortOrder = 0) is intentionally EXCLUDED from this
 * comparison grid - it is bookkeeping for fn_features.php's gating
 * resolution (see that file's header), not a tier a visitor "chooses".
 * Rather than hardcode that exclusion by plan key (forbidden by this file's
 * own zero-literal rule above), listPublicPlansForDisplay() filters
 * generically on `sortOrder > 0`, so the convention is: sortOrder 0 =
 * resolvable for gating but hidden from the public pricing grid; sortOrder
 * >= 1 = shown on the grid in that order. An owner who wants that no-account
 * tier surfaced as its own card in future need only give it a positive
 * sortOrder - no code change here either.
 *
 * @package     CueRCode
 * @subpackage  Billing
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.1.0
 * @since       Phase 3 (Epic E4, #56); hand-maintained feature/limit
 *              allow-list (pricingAllowedDisplayKeys()) + per-row 'status'
 *              added Phase 4 (#125, 2026-09-25)
 * @see         fn_features.php               - the gating engine this page's data mirrors
 * @see         _includes/sql/seed_data.sql   - the seeded plan rows + featuresJSON/limitsJSON shape
 * @see         _includes/sql/install.sql     - the tblSubscriptionPlans CREATE TABLE
 * @see         public_html/pricing/index.php - the page that renders this file's output
 * @see         DEV_NOTES.md                  - "Pricing page" section (owner price-edit runbook)
 * @see         https://www.php.net/manual/en/function.json-decode.php
 * @see         https://www.php.net/manual/en/function.number-format.php
 */

// ==============================================================================
// 🚫 Direct access protection
// ==============================================================================
// Mirrors every other _functions/fn_*.php file's guard - this file defines
// standalone functions only and must never be requested directly over HTTP.
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

// 💷 Whole-pence money helpers: every price shown here is read from the
//    database's decimal TEXT and formatted without ever becoming a float.
require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_money.php';

// 🏷️ The two fallback plan-key CONSTANTS (decision 0021, #160): only the
//    plans the site gives people without any payment may be called "Free".
//    fn_features.php defines them and is loaded by bootstrap.php; loading it
//    here too (only when it is not loaded yet) keeps this file usable on its
//    own, as tests/test_pricing*.php use it. It only defines constants and
//    functions.
if (!defined('CUERCODE_PLAN_KEY_FREE') || !defined('CUERCODE_PLAN_KEY_ANONYMOUS')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_features.php';
}

// ==============================================================================
// 📋 listPublicPlansForDisplay() - Fetch the plans the pricing page shows
// ==============================================================================

/**
 * 📋 Fetch every plan row eligible for the public pricing/comparison grid.
 *
 * The ONLY DB-touching function in this file. A plan qualifies when it is
 * both `isActive = 1` (selectable/resolvable at all - the same flag
 * fn_features.php's planByKey() honours implicitly via its lookups) AND
 * `sortOrder > 0` (this file's own generic, non-tier-literal convention for
 * "shown on the public pricing grid" - see this file's header comment), and
 * pricingPlanIsOnGrid() accepts it (an ordinary plan, inside its sale dates -
 * task 7n Phase 2: lifetime deals and credit packs are never on this grid).
 *
 * Every column is read (`SELECT *`) on purpose: the plan-editor columns come
 * from database upgrade 0004, and naming them would break the public page on a
 * database where that upgrade has not been applied yet. Without them every
 * plan is an ordinary "buy it" plan, exactly as before.
 *
 * Ordered deterministically by `sortOrder` then `planID` so the grid's
 * left-to-right order never depends on undefined row ordering.
 *
 * Fail-safe: returns an empty array (no warning, no exception escaping) when
 * dbQuery() is unavailable (bootstrap.php's db_connect.php was never loaded
 * or the connection could not be established) OR the query itself throws -
 * exactly mirroring fn_features.php's planByKey() availability check, so the
 * calling page can render its own "temporarily unavailable" panel instead of
 * fataling.
 *
 * @return array Raw plan rows (every tblSubscriptionPlans column, plus
 *               'pricePoints' and 'hasPricePoint' - true when the plan has a
 *               price on sale now or starting later, so a plan with prices is
 *               never called "Free"; priceMonthly / priceYearly / currency are
 *               the figures on sale NOW from the plan's price list, see
 *               pricingApplyPriceList() - review finding F6) - JSON columns are
 *               decoded downstream by pricingDisplayRowsFromPlans(), NOT
 *               here, so this function stays a thin, purely-DB concern.
 *               Empty array when the DB is unavailable or no plan qualifies.
 *
 * @see pricingDisplayRowsFromPlans() - decodes/transforms this function's output
 * @see https://www.php.net/manual/en/mysqli-stmt.get-result.php
 */
function listPublicPlansForDisplay(): array
{
    // 🚨 Fail-safe: no DB layer loaded at all (e.g. this test harness, or a
    // production request where db_connect.php could not establish a
    // connection) - return an empty list rather than warning/fataling.
    if (!function_exists('dbQuery')) {
        return [];
    }

    try {
        // 🔒 Zero user-supplied values feed this query (isActive/sortOrder
        // are static literals, not request input) - still routed through
        // dbQuery()'s prepared-statement wrapper for consistency with every
        // other query in this codebase (house rule: MySQLi, prepared
        // statements for ALL queries).
        $stmt = dbQuery(
            'SELECT * FROM `tblSubscriptionPlans` '
            . 'WHERE `isActive` = 1 AND `sortOrder` > 0 '
            . 'ORDER BY `sortOrder` ASC, `planID` ASC'
        );

        $result = $stmt->get_result();

        $planRows = [];
        $nowUtc   = gmdate('Y-m-d H:i:s');
        while (($row = $result->fetch_assoc()) !== null) {
            // 🚪 Ordinary plans inside their sale dates only.
            if (!pricingPlanIsOnGrid($row, $nowUtc)) {
                continue;
            }

            // 📌 Every plan starts with an EMPTY price-point list - the
            // additive query below (#70, Table 22 tblPlanPricePoints) fills
            // this in ONLY when billing_price_points_enabled=1 AND at least
            // one row exists for this plan. pricingDisplayRowsFromPlans()
            // falls back to the legacy priceMonthly/priceYearly columns
            // whenever a plan's 'pricePoints' stays empty - so a fresh
            // install (kill-switch off, table empty) renders BYTE-IDENTICAL
            // to before this key existed.
            $row['pricePoints']   = [];
            $row['hasPricePoint'] = false;
            $planRows[] = $row;
        }

        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Public pricing plan list failed', $e->getMessage());
        }
        return [];
    }

    // ==========================================================================
    // 🗓️ Each plan's price list, read LIVE (task 7n Phase 2; review finding F6)
    // ==========================================================================
    // Every price row of every plan (one static query, bucketed in PHP - the
    // page shows only a handful of plans). Two things come from it:
    //   - hasPricePoint: the plan has a price on sale now or starting later,
    //     so even 0.00 in the older columns never makes it "Free";
    //   - the shown monthly / yearly figures, worked out from the price list
    //     NOW (pricingApplyPriceList()), so a price whose sale dates have
    //     ended or started is followed without anyone saving again. A plan
    //     without grouped price rows keeps its older columns.
    // `SELECT *` on purpose, like the plans query above: the priceGroupKey
    // column comes from database upgrade 0004. Fail-safe: if this query fails
    // the plans keep their older columns (the page never breaks).
    // ==========================================================================
    $mainCurrency = '';
    if (function_exists('getConfig')) {
        $configuredCurrency = getConfig('billing_default_currency', 'GBP');
        if (is_string($configuredCurrency)) {
            $mainCurrency = strtoupper(trim($configuredCurrency));
        }
    }

    if (!empty($planRows)) {
        try {
            $pricedStmt   = dbQuery('SELECT * FROM `tblPlanPricePoints` ORDER BY `planID` ASC, `sortOrder` ASC, `pricePointID` ASC');
            $pricedResult = $pricedStmt->get_result();
            $pricesByPlan = [];
            while (($pricedRow = $pricedResult->fetch_assoc()) !== null) {
                $pricesByPlan[(int) $pricedRow['planID']][] = $pricedRow;
            }
            $pricedStmt->close();

            foreach ($planRows as $planIndex => $planRow) {
                $planPrices = [];
                if (isset($pricesByPlan[(int) $planRow['planID']])) {
                    $planPrices = $pricesByPlan[(int) $planRow['planID']];
                }

                foreach ($planPrices as $planPrice) {
                    $priceStatus = pricingPriceRowStatus($planPrice, $nowUtc);
                    if ($priceStatus === 'on_sale' || $priceStatus === 'scheduled') {
                        $planRows[$planIndex]['hasPricePoint'] = true;
                    }
                }

                if (moneyCurrencyKnown($mainCurrency)) {
                    $planRows[$planIndex] = pricingApplyPriceList($planRows[$planIndex], $planPrices, $mainCurrency, $nowUtc);
                }
            }
        } catch (\Throwable $e) {
            if (function_exists('logError')) {
                logError('error', 'Public pricing price list failed', $e->getMessage());
            }
        }
    }

    // ==========================================================================
    // 💷 Additive query (#70): PREFER tblPlanPricePoints rows when present.
    // ==========================================================================
    // This file is deliberately NOT bootstrap-loaded (see this file's header
    // comment) and does not require fn_features.php, so the kill-switch
    // check and the read-only SELECT below are duplicated here rather than
    // calling that file's pricePointsForPlan() - the SAME
    // `billing_price_points_enabled` setting gates both, so the two stay in
    // lock-step without a cross-file dependency. This is the ONE additive
    // query the #70 spec calls for: a single STATIC statement (no dynamic
    // `IN (...)` list, no identifier interpolation) fetches every currently
    // -valid, ACTIVE price point across EVERY plan in one round trip, and the
    // rows are bucketed by planID in PHP - the pricing page only ever shows a
    // handful of plans, so this stays cheap. Skipped entirely (zero extra
    // query) when there are no plans to attach to, or the DB layer/config
    // subsystem is unavailable.
    // ==========================================================================
    if (!empty($planRows) && function_exists('getConfig') && ((bool) getConfig('billing_price_points_enabled', false))) {
        try {
            $pricePointStmt = dbQuery(
                'SELECT `pricePointID`, `planID`, `priceKey`, `billingInterval`, `intervalCount`, '
                . '`amount`, `currency`, `grantsJSON`, `validFrom`, `validUntil`, `isDefault` '
                . 'FROM `tblPlanPricePoints` '
                . 'WHERE `isActive` = 1 '
                . 'AND (`validFrom` IS NULL OR `validFrom` <= NOW()) '
                . 'AND (`validUntil` IS NULL OR `validUntil` >= NOW()) '
                . 'ORDER BY `planID` ASC, `sortOrder` ASC, `pricePointID` ASC'
            );

            $pricePointResult    = $pricePointStmt->get_result();
            $pricePointsByPlanID = [];

            while (($pricePointRow = $pricePointResult->fetch_assoc()) !== null) {
                $ownerPlanID = (int) $pricePointRow['planID'];
                if (!isset($pricePointsByPlanID[$ownerPlanID])) {
                    $pricePointsByPlanID[$ownerPlanID] = [];
                }
                $pricePointsByPlanID[$ownerPlanID][] = $pricePointRow;
            }

            $pricePointStmt->close();

            foreach ($planRows as &$planRowRef) {
                $thisPlanID = (int) $planRowRef['planID'];
                if (isset($pricePointsByPlanID[$thisPlanID])) {
                    $planRowRef['pricePoints'] = $pricePointsByPlanID[$thisPlanID];
                }
            }
            // 🧹 Break the by-reference alias now that the loop above is done
            // - leaving a dangling reference bound to the last array element
            // is a classic PHP foreach-by-reference footgun.
            unset($planRowRef);
        } catch (\Throwable $e) {
            if (function_exists('logError')) {
                logError('error', 'Public pricing price-point list failed', $e->getMessage());
            }
            // 🛡️ Fail safe: every plan row's 'pricePoints' stays the [] set
            // above (the legacy-column fallback) - a failure in this SECOND,
            // purely-additive query must never break the whole pricing page.
        }
    }

    return $planRows;
}

// ==============================================================================
// 🚪 pricingPlanIsOnGrid() - which plans the public grid may show (task 7n Phase 2)
// ==============================================================================

/**
 * 🚪 May this plan appear on the public pricing grid? PURE.
 *
 * Only an ordinary ("standard") plan, and only inside its sale dates (both
 * ends included, stored and compared in UTC). Lifetime deals and credit packs
 * have their own screens later. A row without these columns (database upgrade
 * 0004 not applied yet) counts as an ordinary plan with no sale dates. Whether
 * the plan is on sale and has a display order above 0 is decided by the query
 * in listPublicPlansForDisplay().
 *
 * @param array<string, mixed> $planRow A tblSubscriptionPlans row
 * @param string               $nowUtc  "Y-m-d H:i:s" in UTC
 * @return bool
 */
function pricingPlanIsOnGrid(array $planRow, string $nowUtc): bool
{
    if (array_key_exists('planKind', $planRow) && $planRow['planKind'] !== 'standard') {
        return false;
    }

    if (isset($planRow['availableFrom']) && strcmp((string) $planRow['availableFrom'], $nowUtc) > 0) {
        return false;
    }

    if (isset($planRow['availableUntil']) && strcmp((string) $planRow['availableUntil'], $nowUtc) < 0) {
        return false;
    }

    return true;
}

// ==============================================================================
// 🗓️ The price list, read live (review finding F6 of the 7n Phase 2 review)
// ==============================================================================

/**
 * 🚦 Where one price stands right now. PURE.
 *
 * Shared by the public page and the admin editor (adminPlansPriceStatus()),
 * so the two can never disagree about whether a price is on sale.
 *
 * @param array<string, mixed> $row    A tblPlanPricePoints row
 * @param string               $nowUtc "Y-m-d H:i:s" in UTC
 * @return string 'retired' | 'scheduled' (starts later) | 'ended' (its end date passed) | 'on_sale'
 */
function pricingPriceRowStatus(array $row, string $nowUtc): string
{
    if (!isset($row['isActive']) || (int) $row['isActive'] !== 1) {
        return 'retired';
    }

    if (isset($row['validFrom']) && $row['validFrom'] !== null && strcmp((string) $row['validFrom'], $nowUtc) > 0) {
        return 'scheduled';
    }

    if (isset($row['validUntil']) && $row['validUntil'] !== null && strcmp((string) $row['validUntil'], $nowUtc) < 0) {
        return 'ended';
    }

    return 'on_sale';
}

/**
 * 🪞 A plan's monthly and yearly prices on sale RIGHT NOW in one currency. PURE.
 *
 * Chooses by price GROUP ("monthly", "yearly"), never by the recommended flag:
 * the price whose group is monthly and that is on sale now (not retired, and
 * today inside its sale dates), and the same for yearly. If two match (older
 * data), the lowest display order, then the lowest ID, wins. Used live by the
 * public page (pricingApplyPriceList()), by the admin preview, and by the
 * copy into the older price columns (adminMirrorSelection()).
 *
 * @param array<int, array<string, mixed>> $rows     The plan's price rows (any status)
 * @param string                           $currency ISO 4217 code
 * @param string                           $nowUtc   "Y-m-d H:i:s" in UTC
 * @return array{monthly: string|null, yearly: string|null, hasAnyPrice: bool, managed: array{monthly: bool, yearly: bool}}
 *         amounts as DECIMAL text; hasAnyPrice = any price on sale now in any
 *         currency; managed = the group has at least one price row of any
 *         currency or status (so its figure comes from the price list)
 */
function pricingCurrentGroupPrices(array $rows, string $currency, string $nowUtc): array
{
    $selection = ['monthly' => null, 'yearly' => null, 'hasAnyPrice' => false, 'managed' => ['monthly' => false, 'yearly' => false]];
    $chosen = ['monthly' => null, 'yearly' => null];

    foreach ($rows as $row) {
        $group = '';
        if (isset($row['priceGroupKey']) && $row['priceGroupKey'] !== null) {
            $group = (string) $row['priceGroupKey'];
        }
        if ($group === 'monthly' || $group === 'yearly') {
            $selection['managed'][$group] = true;
        }

        if (pricingPriceRowStatus($row, $nowUtc) !== 'on_sale') {
            continue;
        }
        $selection['hasAnyPrice'] = true;

        if (!isset($row['currency']) || (string) $row['currency'] !== $currency) {
            continue;
        }
        if ($group !== 'monthly' && $group !== 'yearly') {
            continue;
        }

        if ($chosen[$group] === null) {
            $chosen[$group] = $row;
            continue;
        }

        $best = $chosen[$group];
        $rowOrder = [(int) $row['sortOrder'], (int) $row['pricePointID']];
        $bestOrder = [(int) $best['sortOrder'], (int) $best['pricePointID']];
        if ($rowOrder < $bestOrder) {
            $chosen[$group] = $row;
        }
    }

    foreach (['monthly', 'yearly'] as $group) {
        if ($chosen[$group] === null || !moneyCurrencyKnown($currency)) {
            continue;
        }
        $minor = moneyStoredDecimalToMinor((string) $chosen[$group]['amount'], $currency);
        if ($minor !== null) {
            $selection[$group] = moneyMinorToDecimalString($minor, $currency);
        }
    }

    return $selection;
}

/**
 * 🗓️ One plan row with its monthly and yearly figures taken LIVE from its
 *    price list. PURE. (Review finding F6 of the 7n Phase 2 review.)
 *
 * WHY: the older single-price columns are a copy made when a price is saved,
 * so a price whose "on sale until" date passed kept its place on the page, and
 * one scheduled to start never appeared, until somebody saved again. The page
 * now works the figures out from the price list every time it loads:
 *   - a group (monthly / yearly) that has price rows shows the price on sale
 *     NOW, or nothing (decision 0021: a paid plan with nothing on sale then
 *     says "Price coming soon");
 *   - a group with no price rows at all keeps the older column (a plan priced
 *     only the old way, for example before database upgrade 0004);
 *   - the currency is the main one (billing_default_currency) - unless the
 *     plan has no monthly price on sale in it, when the plan's own older
 *     currency is used instead (the same rule as the copy into the older
 *     columns, adminMirrorDecision(), so a change of main currency never
 *     blanks a plan priced only in the old one).
 * A plan with no grouped price rows at all is returned unchanged.
 *
 * @param array<string, mixed>             $planRow      A tblSubscriptionPlans row
 * @param array<int, array<string, mixed>> $priceRows    That plan's tblPlanPricePoints rows (any status)
 * @param string                           $mainCurrency billing_default_currency
 * @param string                           $nowUtc       "Y-m-d H:i:s" in UTC
 * @return array<string, mixed> The row, with priceMonthly / priceYearly / currency
 *                              as the page should show them ('0' = nothing on sale
 *                              in that group, '' = none in the currency shown)
 */
function pricingApplyPriceList(array $planRow, array $priceRows, string $mainCurrency, string $nowUtc): array
{
    $selection = pricingCurrentGroupPrices($priceRows, $mainCurrency, $nowUtc);

    if ($selection['managed']['monthly'] !== true && $selection['managed']['yearly'] !== true) {
        return $planRow;
    }

    $storedCurrency = '';
    if (isset($planRow['currency'])) {
        $storedCurrency = (string) $planRow['currency'];
    }

    $shownCurrency = $mainCurrency;
    if ($storedCurrency !== '' && $storedCurrency !== $mainCurrency && $selection['monthly'] === null) {
        $shownCurrency = $storedCurrency;
        $selection = pricingCurrentGroupPrices($priceRows, $storedCurrency, $nowUtc);
    }

    foreach (['monthly' => 'priceMonthly', 'yearly' => 'priceYearly'] as $group => $column) {
        if ($selection['managed'][$group] === true) {
            // 0 = nothing on sale in this group right now. Zero is never shown
            // as a figure, and only a plan given without payment can read
            // "Free" (pricingDisplayRowsFromPlans()); any other says "Price
            // coming soon" when neither group has a price (decision 0021).
            $value = '0';
            if ($selection[$group] !== null) {
                $value = (string) $selection[$group];
            }
            $planRow[$column] = $value;
            continue;
        }

        // 📜 Priced the old way: keep the older column, if it is in the
        //    currency being shown (never mix two currencies on one card).
        if ($storedCurrency !== $shownCurrency) {
            $planRow[$column] = '';
        }
    }

    $planRow['currency'] = $shownCurrency;

    return $planRow;
}

// ==============================================================================
// 🏷️ pricingIsNoPaymentPlan() - which plans can ever be "Free" (decision 0021)
// ==============================================================================

/**
 * 🏷️ Is this one of the plans the site gives people WITHOUT any payment? PURE.
 *
 * Owner decision 0021 (#160, 2026-11-26): a paid plan put on sale before any
 * price is entered must show "Price coming soon" with no sign-up button -
 * never "Free" with a "Create free account" button. The only plans anyone
 * gets without paying are the two the code itself falls back to (fn_features.php):
 * the plan for visitors who are not signed in, and the plan every new account
 * is given. "Create free account" leads to that second plan, so only these two
 * may read "Free". This is a fact about the code, not a commercial rule: the
 * plans' names, prices, features and limits all stay admin-editable data, and
 * either of these plans shows a price if one is ever set on it.
 *
 * @param string $planKey The plan's key
 * @return bool
 */
function pricingIsNoPaymentPlan(string $planKey): bool
{
    return $planKey !== '' && ($planKey === CUERCODE_PLAN_KEY_FREE || $planKey === CUERCODE_PLAN_KEY_ANONYMOUS);
}

// ==============================================================================
// 🤝 pricingPromiseText() - each plan's own price promise (decision 0020, #159)
// ==============================================================================

/**
 * 🤝 The sentence a plan's card shows about how long a customer keeps the price
 *    they sign up at. PURE.
 *
 * Owner decision 0020 (#159, 2026-11-26): the page used to say, for every
 * plan, "we will always honour your price for any subscription already in
 * progress", although each plan can promise a set number of months, or
 * nothing. Each card now says what that plan promises, so the page never
 * promises more than is configured:
 *   - the plan's own promise (tblSubscriptionPlans.pricePromiseMode / Months),
 *     or, when it sets none, the site-wide one (billing_price_promise_default_mode
 *     / _months);
 *   - anything that is not one of the three known choices, or a number of
 *     months outside 1 to 120, promises NOTHING ('' - no sentence at all).
 *
 * @param mixed $planMode      The plan's pricePromiseMode (null = use the site-wide one)
 * @param mixed $planMonths    The plan's pricePromiseMonths
 * @param mixed $defaultMode   billing_price_promise_default_mode
 * @param mixed $defaultMonths billing_price_promise_default_months
 * @return string Plain English, or '' when nothing may be promised
 */
function pricingPromiseText(mixed $planMode, mixed $planMonths, mixed $defaultMode, mixed $defaultMonths): string
{
    $known = ['while_subscribed', 'fixed_months', 'none'];

    $mode = '';
    $months = null;
    if (is_string($planMode) && $planMode !== '') {
        $mode = $planMode;
        $months = $planMonths;
    } elseif (is_string($defaultMode)) {
        $mode = $defaultMode;
        $months = $defaultMonths;
    }

    if (!in_array($mode, $known, true)) {
        return '';
    }

    if ($mode === 'while_subscribed') {
        return 'Your price stays the same for as long as you stay subscribed.';
    }

    if ($mode === 'none') {
        return 'This price can change; a change would apply from your next payment.';
    }

    // 🔢 fixed_months: a whole number from 1 to 120 (the editor's limits).
    $count = 0;
    if (is_int($months)) {
        $count = $months;
    } elseif (is_string($months) && preg_match('/^[1-9][0-9]{0,2}$/D', trim($months)) === 1) {
        $count = (int) trim($months);
    }
    if ($count < 1 || $count > 120) {
        return '';
    }

    if ($count === 1) {
        return 'Your price stays the same for the first month of your subscription.';
    }

    return 'Your price stays the same for the first ' . $count . ' months of your subscription.';
}

/**
 * 🤝 The sentence about price promises in the note above the cards. PURE.
 *    (Recheck finding 4 of the 7n Phase 2 review.)
 *
 * The note used to say "Each plan says how long you keep the price you sign
 * up at" whatever the cards said - and before upgrade 0004 no card states a
 * promise at all. It now follows what the cards really show:
 *   - no priced card, or none states a promise: nothing ('');
 *   - every priced card states one: "Each plan says ...";
 *   - only some do: "Where a plan promises to keep your price, its card says
 *     for how long."
 *
 * @param array<int, string> $pricedCardPromises The promise sentence of each card
 *                                               that shows a price figure ('' = none)
 * @return string Plain English, or ''
 */
function pricingPromiseNote(array $pricedCardPromises): string
{
    $stated = 0;
    foreach ($pricedCardPromises as $promise) {
        if (is_string($promise) && $promise !== '') {
            $stated++;
        }
    }

    if ($stated === 0) {
        return '';
    }

    if ($stated === count($pricedCardPromises)) {
        return 'Each plan says how long you keep the price you sign up at.';
    }

    return 'Where a plan promises to keep your price, its card says for how long.';
}

// ==============================================================================
// 👁️ pricingShowPrices() - the pricing_show_prices setting
// ==============================================================================

/**
 * 👁️ Should price figures be shown? PURE. Off only when the setting clearly
 *    says off; anything else (including a missing setting, whose default is
 *    on) shows them.
 *
 * @param mixed $value The setting as getConfig() returned it
 * @return bool
 */
function pricingShowPrices(mixed $value): bool
{
    if ($value === false || $value === 0) {
        return false;
    }

    if (is_string($value) && in_array(strtolower(trim($value)), ['0', 'false', 'off', 'no'], true)) {
        return false;
    }

    return true;
}

// ==============================================================================
// ✉️ pricingContactLink() - where a "Contact us" button goes
// ==============================================================================

/**
 * ✉️ The link for a "Contact us" plan. PURE.
 *
 * The plan's own contactUrl when it is a plain https address or a
 * "mailto:" email address; otherwise an email to the site_email setting.
 * Checked again here although the editor checks it on saving, because a
 * value typed straight into the database must never become a script link.
 *
 * @param mixed $contactUrl The plan's contactUrl column (may be null)
 * @param mixed $siteEmail  The site_email setting
 * @return string A https: or mailto: link (not yet HTML-escaped)
 */
function pricingContactLink(mixed $contactUrl, mixed $siteEmail): string
{
    if (is_string($contactUrl) && preg_match('/[\x00-\x20\x7F<>"\'\\\\]/', $contactUrl) !== 1) {
        if (stripos($contactUrl, 'https://') === 0) {
            $host = parse_url($contactUrl, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return $contactUrl;
            }
        }

        if (stripos($contactUrl, 'mailto:') === 0) {
            $address = substr($contactUrl, strlen('mailto:'));
            if (pricingEmailIsPlain($address)) {
                return 'mailto:' . $address;
            }
        }
    }

    if (is_string($siteEmail) && pricingEmailIsPlain(trim($siteEmail))) {
        return 'mailto:' . trim($siteEmail);
    }

    // 📌 The same last-resort address the page's own error panel uses.
    return 'mailto:hello@cuercode.net';
}

/**
 * ✉️ One ordinary email address, with nothing that would add extra parts to a
 *    mailto: link (? & #). PURE.
 *
 * @param string $address Candidate address
 * @return bool
 */
function pricingEmailIsPlain(string $address): bool
{
    if (preg_match('/[\s?&#]/', $address) === 1) {
        return false;
    }

    return filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
}

// ==============================================================================
// 🧾 pricingPriceBlock() - the price wording on one card (task 7n Phase 2)
// ==============================================================================

/**
 * 🧾 What one card says about money. PURE.
 *
 * The cases:
 *   - free plan (one of the two plans given without payment, decision 0021):
 *     "Free" (not a price figure, so it stays when prices are hidden);
 *   - any other plan with nothing on sale: "Price coming soon" (decision 0021,
 *     #160), or "Price to be announced" while prices are hidden;
 *   - "Contact us" plan: "From <monthly price>/month", never "Free";
 *   - ordinary paid plan: the monthly price, with the yearly one underneath;
 *     whichever is missing is left out (a missing price is never "Free");
 *   - prices hidden: words only, no figures;
 *   - preview ($previewCurrency not empty): a missing price says so.
 *
 * @param array<string, mixed> $plan            One row from pricingDisplayRowsFromPlans()
 * @param bool                 $showPrices      pricing_show_prices
 * @param string               $previewCurrency '' on the public page; the chosen
 *                                              currency code on the admin preview
 * @return array{prefix: string, headline: string, suffix: string, subline: string, isFigure: bool}
 */
function pricingPriceBlock(array $plan, bool $showPrices, string $previewCurrency = ''): array
{
    $words = static function (string $headline, string $subline): array {
        return ['prefix' => '', 'headline' => $headline, 'suffix' => '', 'subline' => $subline, 'isFigure' => false];
    };

    $isContact = ($plan['ctaMode'] === 'contact');

    if (!$isContact && $plan['isZeroPrice']) {
        $block = $words('Free', 'No payment details needed');
        $block['isFigure'] = true;
        return $block;
    }

    if (!$showPrices) {
        if ($isContact) {
            return $words('Price on request', 'Contact us and we will agree it with you');
        }
        return $words('Price to be announced', '');
    }

    $monthly = $plan['priceMonthlyFormatted'];
    $yearly  = $plan['priceYearlyFormatted'];
    $hasMonthly = ($monthly !== '' && $monthly !== 'Free');
    $hasYearly  = ($yearly !== '' && $yearly !== 'Free');

    if ($isContact) {
        // ✂️ A "from" figure in whole units reads "From £x", without ".00".
        if (str_ends_with($monthly, '.00')) {
            $monthly = substr($monthly, 0, -3);
        }
        if (str_ends_with($yearly, '.00')) {
            $yearly = substr($yearly, 0, -3);
        }

        if ($hasMonthly) {
            $subline = 'Agreed with you to suit your organisation';
            if ($hasYearly) {
                $subline = 'or from ' . $yearly . ' billed yearly';
            }
            return ['prefix' => 'From', 'headline' => $monthly, 'suffix' => '/month', 'subline' => $subline, 'isFigure' => true];
        }

        if ($previewCurrency !== '') {
            $subline = '';
            if ($hasYearly) {
                $subline = 'From ' . $yearly . ' billed yearly';
            }
            return $words('No monthly price in ' . $previewCurrency . ' yet', $subline);
        }

        if ($hasYearly) {
            return ['prefix' => 'From', 'headline' => $yearly, 'suffix' => '/year', 'subline' => 'Billed yearly', 'isFigure' => true];
        }

        return $words('Price on request', 'Contact us and we will agree it with you');
    }

    if ($hasMonthly) {
        $subline = 'Billed monthly';
        if ($hasYearly) {
            $subline = 'or ' . $yearly . ' billed yearly';
        } elseif ($previewCurrency !== '') {
            $subline = 'No yearly price in ' . $previewCurrency . ' yet';
        }
        return ['prefix' => '', 'headline' => $monthly, 'suffix' => '/month', 'subline' => $subline, 'isFigure' => true];
    }

    if ($previewCurrency !== '') {
        if ($hasYearly) {
            return $words('No monthly price in ' . $previewCurrency . ' yet', $yearly . ' billed yearly');
        }
        return $words('No price in ' . $previewCurrency . ' yet', '');
    }

    if ($hasYearly) {
        return ['prefix' => '', 'headline' => $yearly, 'suffix' => '/year', 'subline' => 'Billed yearly', 'isFigure' => true];
    }

    // 🏷️ Decision 0021 (#160): a paid plan with nothing on sale right now.
    return $words('Price coming soon', '');
}

// ==============================================================================
// 🔭 pricingPreviewRows() - plan rows as price points would show them
// ==============================================================================

/**
 * 🔭 For the Global Admin preview: replace each plan's single-price columns
 *    with its current price points in ONE currency. PURE.
 *
 * A price that does not exist in that currency becomes empty - never the old
 * column's figure, and never zero - so the preview shows the gap honestly. A
 * plan with no price of any kind (no price points and nothing in the old
 * columns) stays free.
 *
 * @param array<int, array<string, mixed>>   $planRows   From listPublicPlansForDisplay()
 * @param array<int, array<string, ?string>> $selections planID => ['monthly' => text|null, 'yearly' => text|null]
 *                                                       (adminMirrorSelection())
 * @param string                             $currency   The currency being previewed
 * @return array<int, array<string, mixed>> Rows ready for pricingDisplayRowsFromPlans()
 */
function pricingPreviewRows(array $planRows, array $selections, string $currency): array
{
    $rows = [];

    foreach ($planRows as $planRow) {
        $storedCurrency = '';
        if (isset($planRow['currency'])) {
            $storedCurrency = (string) $planRow['currency'];
        }
        $storedMonthly = '';
        if (isset($planRow['priceMonthly'])) {
            $storedMonthly = (string) $planRow['priceMonthly'];
        }
        $storedYearly = '';
        if (isset($planRow['priceYearly'])) {
            $storedYearly = (string) $planRow['priceYearly'];
        }

        $isFree = (empty($planRow['hasPricePoint'])
            && pricingFormatPrice($storedMonthly, $storedCurrency) === 'Free'
            && pricingFormatPrice($storedYearly, $storedCurrency) === 'Free');

        $planID = (int) $planRow['planID'];
        $planRow['pricePoints']   = [];
        $planRow['currency']      = $currency;
        $planRow['hasPricePoint'] = !$isFree;

        foreach (['monthly' => 'priceMonthly', 'yearly' => 'priceYearly'] as $group => $column) {
            if ($isFree) {
                $planRow[$column] = '0';
            } elseif (isset($selections[$planID][$group])) {
                $planRow[$column] = (string) $selections[$planID][$group];
            } else {
                $planRow[$column] = '';
            }
        }

        $rows[] = $planRow;
    }

    return $rows;
}

// ==============================================================================
// 🗂️ pricingAllowedDisplayKeys() - THE single hand-maintained allow-list
// ==============================================================================

/**
 * 🗂️ THE single hand-maintained allow-list of every featuresJSON/limitsJSON
 * key the public pricing grid is permitted to show, each with its own
 * hand-written display label AND a hand-maintained build status.
 *
 * OWNER DECISION (2026-09-25, #125): the pricing grid must NEVER advertise a
 * feature/limit as "Included" when a customer could not actually use it with
 * the code as it stands today, and it must NEVER show an uncurated,
 * auto-generated label for a brand-new/groundwork key an admin has started
 * seeding ahead of the code that reads it. This function fixes BOTH problems
 * at once:
 *   - A key that is NOT in this array is NEVER shown on the grid, no matter
 *     what any plan's featuresJSON/limitsJSON contains - so a future
 *     groundwork key (e.g. a #70 PAYG-only limit, or the next dark-launched
 *     feature flag) can never leak onto the public page before it has been
 *     reviewed and deliberately added here.
 *   - A key that IS in this array carries a proper, hand-written label
 *     (never the generic snake_case-to-Title-Case fallback) AND an explicit
 *     status:
 *       - 'available'   = a customer could actually USE this today with the
 *                          code exactly as it stands (switching it on via its
 *                          own normal settings/credentials counts as built -
 *                          e.g. SSO's OIDC login flow is fully coded and
 *                          reachable, it only needs the OWNER's own app
 *                          registration + client secret entering before a
 *                          real sign-in works - see the per-key comments
 *                          below for the evidence behind every verdict).
 *       - 'coming_soon' = the feature is NOT built yet (no reachable route,
 *                          no UI, schema-only groundwork, etc.) - a plan row
 *                          that has switched this key on is display-only
 *                          ("Coming soon" on the grid) until the code
 *                          catches up.
 *
 * MAINTENANCE: adding a brand-new gated feature/limit to the app is now a
 * two-step process - (1) start reading it via featureEnabled()/limitFor() in
 * the new code path (fn_features.php's own convention, unchanged), THEN
 * (2) add its key/label/status to this array once it is genuinely ready to
 * be advertised. Forgetting step 2 simply means the new key stays invisible
 * on /pricing - a safe default, never an accidental overclaim.
 *
 * Evidence for every verdict below was captured by direct code inspection on
 * 2026-09-25 (issue #125, cross-checked against .claude/ISSUE_AUDIT_2026-09-23.md)
 * - re-verify against the actual code before ever flipping a 'coming_soon'
 * to 'available'.
 *
 * @return array<string, array{label: string, status: string}> Ordered
 *         key => ['label' => string, 'status' => 'available'|'coming_soon'].
 *         Iteration order is authoritative for the pricing grid's row order
 *         (see pricingDisplayRowsFromPlans()).
 *
 * @see pricingHumanizeKey()          - falls back to this array's labels first
 * @see pricingDisplayRowsFromPlans() - the consumer that filters + orders by this array
 */
function pricingAllowedDisplayKeys(): array
{
    static $allowedDisplayKeys = [

        // ==========================================================================
        // 🚩 featuresJSON keys (seed_data.sql tblSubscriptionPlans - boolean flags)
        // ==========================================================================

        // ✅ AVAILABLE - Barcode generation (1D + 2D, #69). The vendored
        // tc-lib-barcode library is wired straight into the live generate
        // pipeline (_functions/fn_barcode_generate.php, required by
        // fn_api_generate.php) and the router's own `requiredFeature` gate
        // on POST /barcode + /barcode/preview (public_html/api/v1/index.php)
        // proves it is genuinely reachable today, not just present in code.
        'barcode_generation' => [
            'label'  => 'Barcode generation (1D & 2D)',
            'status' => 'available',
        ],

        // ✅ AVAILABLE - Single sign-on (#19/#20/#55). The FULL OIDC login
        // flow is coded and live: fn_oauth.php does state/PKCE, JWKS/RS256
        // id_token verification and account-linking; the redirect_uri route
        // (public_html/auth/callback/index.php) is real and reachable; the
        // login page already renders a working "Sign in with ..." button the
        // moment a provider is configured + enabled. This counts as built
        // per this array's own rule above - it only needs the OWNER's own
        // Microsoft/Google app registration and client secret entering
        // (settings, not code) before a real visitor can sign in with it.
        'sso' => [
            'label'  => 'Single sign-on (SSO)',
            'status' => 'available',
        ],

        // 🚧 COMING SOON - Expiry & scheduling (#27/#82). Schema-only
        // groundwork: install.sql's tblQRRedirects.scheduledStartAt/
        // expiresAt columns are explicitly commented "FG-005 hook ... the
        // (future) /r/ route would ... (not built this stage)" - no live
        // route reads either column yet.
        'expiry_scheduling' => [
            'label'  => 'Expiry & scheduling',
            'status' => 'coming_soon',
        ],

        // 🚧 COMING SOON - Password-protected QR codes. No column, no code,
        // anywhere in the schema or the generation/redirect pipeline.
        'password_protected_qr' => [
            'label'  => 'Password-protected QR codes',
            'status' => 'coming_soon',
        ],

        // 🚧 COMING SOON - Dynamic QR codes (#27/#82). The owner-facing
        // destination-management API exists and is reachable
        // (fn_api_redirects.php), but its own file header calls
        // createDynamicRedirectForOwner() "deliberately NOT wired to any
        // live route" - there is no public /r/{uuid} redirect endpoint for a
        // scanned code to ever resolve through, so a customer cannot
        // actually make a "dynamic" QR code do anything dynamic yet.
        'dynamic_qr' => [
            'label'  => 'Dynamic QR codes (editable destination)',
            'status' => 'coming_soon',
        ],

        // 🚧 COMING SOON - Dual-function QR (#71). Explicitly "waiting on
        // owner" (the G-007 redirect-hosting decision) - no /r/ resolve
        // endpoint, no approved-apps register, nothing reachable.
        'dual_function_qr' => [
            'label'  => 'Dual-function QR codes (app check-in)',
            'status' => 'coming_soon',
        ],

        // 🚧 COMING SOON - Organisations (#21). "Not started": no
        // create-organisation flow, no roles, no invitations UI - only an
        // empty schema stub (tblOrgInvitations).
        'organisations' => [
            'label'  => 'Organisation accounts & roles',
            'status' => 'coming_soon',
        ],

        // 🚧 COMING SOON - Webhooks (#54). Not built anywhere - no outbound-
        // webhook code exists, and the scan events a webhook would fire on
        // do not exist either (see analytics_days below).
        'webhooks' => [
            'label'  => 'Outbound webhooks',
            'status' => 'coming_soon',
        ],

        // 🚧 COMING SOON - Analytics CSV/JSON export. Depends entirely on
        // scan analytics, and #33 (scan tracking/analytics dashboard) is
        // "Not started" - no analytics feature exists to export from.
        'csv_export' => [
            'label'  => 'Analytics CSV/JSON export',
            'status' => 'coming_soon',
        ],

        // ==========================================================================
        // 🔢 limitsJSON keys (seed_data.sql tblSubscriptionPlans - numeric caps)
        // ==========================================================================

        // ✅ AVAILABLE - Saved codes (#24/#52). The saved-codes library +
        // /save + /manage API is live (fn_api_qrcodes.php), genuinely gated
        // by limitFor('saved_codes').
        'saved_codes' => [
            'label'  => 'Saved codes',
            'status' => 'available',
        ],

        // ✅ AVAILABLE - Bulk batch size (#32). Bulk CSV generation is live
        // (web + API), genuinely gated by limitFor('bulk_batch_size') in
        // fn_api_bulk.php.
        'bulk_batch_size' => [
            'label'  => 'Bulk batch size',
            'status' => 'available',
        ],

        // ✅ AVAILABLE - API keys (#54). Self-service key creation/rotation
        // is live in /account/, genuinely gated by limitFor('api_keys') in
        // fn_api_keys.php.
        'api_keys' => [
            'label'  => 'API keys',
            'status' => 'available',
        ],

        // ✅ AVAILABLE - API rate limits (#54). Genuinely enforced per-key:
        // fn_api_auth.php reads tblAPIKeys.rateLimitPerMin/PerDay, which is
        // set from limitFor('api_rate_limit_per_min'/'_per_day') at
        // key-creation time in fn_api_keys.php.
        'api_rate_limit_per_min' => [
            'label'  => 'API rate limit (per minute)',
            'status' => 'available',
        ],
        'api_rate_limit_per_day' => [
            'label'  => 'API rate limit (per day)',
            'status' => 'available',
        ],

        // 🚧 COMING SOON - Dynamic (editable) QR codes. The quota this limit
        // governs belongs to the SAME not-yet-wired dynamic-redirect feature
        // as 'dynamic_qr' above - limitFor('dynamic_codes') is only ever
        // read by the dark createDynamicRedirectForOwner() helper.
        'dynamic_codes' => [
            'label'  => 'Dynamic (editable) QR codes',
            'status' => 'coming_soon',
        ],

        // 🚧 COMING SOON - Analytics history retained. No analytics feature
        // exists at all to retain anything for (#33 "Not started").
        'analytics_days' => [
            'label'  => 'Analytics history retained',
            'status' => 'coming_soon',
        ],

    ];

    return $allowedDisplayKeys;
}

// ==============================================================================
// 🏷️ pricingHumanizeKey() - Generic feature/limit key -> human label
// ==============================================================================

/**
 * 🏷️ Turn a featuresJSON/limitsJSON key into a human-readable display label.
 *
 * Kept as a general-purpose text helper for any OTHER caller of this file
 * (e.g. test fixtures) that wants a readable label for an arbitrary key -
 * pricingDisplayRowsFromPlans() itself now sources its labels directly from
 * pricingAllowedDisplayKeys() (the single hand-maintained allow-list, #125),
 * and this function reads from that SAME array first so the two never drift
 * apart. Any key NOT on that allow-list - i.e. a brand-new feature/limit an
 * admin adds to a plan row's JSON that this file has never heard of - falls
 * through to a generic snake_case-to-Title-Case humaniser, so a new plan key
 * renders sensibly with ZERO code change here.
 *
 * @param string $key The raw featuresJSON/limitsJSON key (e.g. 'csv_export').
 *
 * @return string A human-readable label (e.g. 'Analytics CSV/JSON export').
 *
 * @see pricingAllowedDisplayKeys() - the single source of truth this reads from first
 */
function pricingHumanizeKey(string $key): string
{
    // 🗂️ #125: read the curated label from the single hand-maintained
    // allow-list first - see that function's docblock for the full rationale.
    $allowedDisplayKeys = pricingAllowedDisplayKeys();

    if (array_key_exists($key, $allowedDisplayKeys)) {
        return $allowedDisplayKeys[$key]['label'];
    }

    // 🔧 Generic fallback: snake_case/kebab-case -> "Title Case" - e.g. an
    // unseeded future key like 'priority_support' becomes "Priority support".
    $spaced = str_replace(['_', '-'], ' ', $key);
    $spaced = trim($spaced);

    if ($spaced === '') {
        // 📌 Defensive: an empty-string key (should never occur from a real
        // json_decode() of an object, but keeps this function total).
        return 'Other';
    }

    return ucfirst($spaced);
}

// ==============================================================================
// 🧾 pricingVatNote() - the VAT wording next to the prices (task 7n Phase 1C)
// ==============================================================================

/**
 * 🧾 The short VAT sentence for the pricing page, from the billing_vat_mode
 *    setting (an admin chooses it on /admin/settings/). PURE.
 *
 *   inclusive → "Prices include VAT"
 *   exclusive → "Prices exclude VAT"
 *   none      → "" (not VAT-registered: no VAT wording at all)
 *   anything else (including empty) → "Prices include VAT", the seeded default
 *
 * @param string $mode The setting value
 * @return string The sentence, or '' when no wording should be shown
 *
 * @see .claude/plans/7n-admin-commerce-plan.md §7.2, §8.3
 */
function pricingVatNote(string $mode): string
{
    if ($mode === 'exclusive') {
        return 'Prices exclude VAT';
    }

    if ($mode === 'none') {
        return '';
    }

    // 'inclusive' and any unknown value: the seeded default.
    return 'Prices include VAT';
}

// ==============================================================================
// 💷 pricingFormatPrice() - Format a plan price for display
// ==============================================================================

/**
 * 💷 Format a stored price for the pricing grid. PURE; no floats.
 *
 * Zero reads as the plain word "Free" - clearer at a glance on a comparison
 * grid than "£0.00". Currencies fn_money.php knows are shown with their symbol
 * ("£6.99", "$6.99"); any other code is printed as it is ("CHF 9.90") rather
 * than guessing a symbol. Anything that is not a plain stored amount (empty, a
 * negative number, words) gives '' - never "Free", because an unreadable price
 * is not a free one.
 *
 * Whether a plan whose figure is zero really is free is decided elsewhere (see
 * pricingDisplayRowsFromPlans()): a "Contact us" plan never says "Free".
 *
 * @param string $amount   The decimal TEXT from the database (e.g. a DECIMAL(10,2) column).
 * @param string $currency The ISO 4217 currency code (e.g. 'GBP').
 *
 * @return string The formatted price, 'Free', or ''.
 *
 * @see fn_money.php - moneyStoredDecimalToMinor(), moneyFormat()
 */
function pricingFormatPrice(string $amount, string $currency): string
{
    $parts = [];
    if (preg_match('/^([0-9]{1,8})(?:\.([0-9]{1,2}))?$/D', trim($amount), $parts) !== 1) {
        return '';
    }

    $fraction = '';
    if (isset($parts[2])) {
        $fraction = $parts[2];
    }

    // 🆓 Zero reads as "Free" - driven purely by the VALUE, never a plan name.
    if (trim($parts[1] . $fraction, '0') === '') {
        return 'Free';
    }

    $currencyUpper = strtoupper(trim($currency));

    if (moneyCurrencyKnown($currencyUpper)) {
        $minor = moneyStoredDecimalToMinor(trim($amount), $currencyUpper);
        if ($minor === null) {
            return '';
        }
        return moneyFormat($minor, $currencyUpper);
    }

    if ($currencyUpper === '') {
        // 📌 An empty currency column: a neutral prefix, never assume pounds.
        $pricePrefix = '? ';
    } else {
        $pricePrefix = $currencyUpper . ' ';
    }

    return $pricePrefix . $parts[1] . '.' . str_pad($fraction, 2, '0', STR_PAD_RIGHT);
}

// ==============================================================================
// 🎟️ pricingApplyDiscount() - PURE: apply a tblDiscounts row to an amount (#70)
// ==============================================================================

/**
 * 🎟️ Apply a `tblDiscounts`-shaped percent/fixed discount to a price amount.
 *
 * PURE function - no database access, no I/O - deliberately the smallest
 * possible bridge between install.sql's `tblDiscounts.discountType`/`value`
 * columns (Table 23, #70) and a displayable discounted price, proving that
 * ANY discount - a one-off code, a limited-time sitewide promotion, or a
 * lifetime/forever edu rate - is representable and computable purely from
 * those two columns, with no per-discount code branch anywhere in this
 * codebase. Validity-window checks (`validFrom`/`validUntil`) and redemption
 * caps are `resolveDiscountForCheckout()`'s job (fn_features.php) - this
 * function only does the ARITHMETIC once a discount is already known to
 * apply.
 *
 * ⚠️ DISPLAY ONLY - NOT FOR CHARGING (task 7n Phase 2, plan §8.1): this works
 * in floating-point numbers, which cannot hold most prices exactly, and it
 * rounds with round(). It stays only because the existing pricing-display
 * tests use it. Checkout, the admin editors and anything else that saves or
 * charges money MUST use the whole-pence functions in fn_money.php instead
 * (moneyParseToMinor(), moneyApplyPercentMinor(), moneyApplyFixedMinor(),
 * moneyMinorToDecimalString()), which never use a float and round half up
 * once, at the end.
 *
 * @param float  $amount       The pre-discount price (e.g. a price point's `amount`).
 * @param string $discountType `tblDiscounts.discountType` - 'percent' or 'fixed'.
 * @param float  $value        `tblDiscounts.value` - a percentage (0-100) for
 *                               'percent', or a flat amount in the same
 *                               currency for 'fixed'.
 *
 * @return float The discounted amount, floored at 0.00 and rounded to 2
 *               decimal places (never negative, never more precise than a
 *               real currency amount). An unrecognised $discountType, or a
 *               non-positive $amount/$value, returns $amount unchanged
 *               (clamped to 0.0 floor) - fails safe to "no discount applied"
 *               rather than guessing.
 */
function pricingApplyDiscount(float $amount, string $discountType, float $value): float
{
    // 🛡️ Fail-safe floor: a non-positive amount or a non-positive discount
    // value has nothing meaningful to compute - return the (floored) amount
    // unchanged rather than guessing.
    if ($amount <= 0.0 || $value <= 0.0) {
        return max($amount, 0.0);
    }

    if ($discountType === 'percent') {
        // 💯 Clamp an admin-entered percentage to a sane 0-100 range - a
        // value above 100 would otherwise produce a negative price.
        $clampedPercent = min($value, 100.0);
        $discountedAmount = $amount - ($amount * ($clampedPercent / 100.0));
    } elseif ($discountType === 'fixed') {
        $discountedAmount = $amount - $value;
    } else {
        // 📌 Unrecognised discountType - fail safe to "no discount applied"
        // rather than guessing at an unknown discount shape.
        return $amount;
    }

    if ($discountedAmount < 0.0) {
        // 🛡️ A fixed discount larger than the price itself never goes negative.
        $discountedAmount = 0.0;
    }

    return round($discountedAmount, 2);
}

// ==============================================================================
// 🔢 pricingFormatLimit() - Format a numeric plan limit for display
// ==============================================================================

/**
 * 🔢 Format a single numeric limit value for the pricing grid.
 *
 * Mirrors the -1-means-unlimited convention documented in
 * seed_data.sql/fn_features.php's limitFor(): -1 renders as "Unlimited".
 * A missing/null value (the key was absent from a plan's limitsJSON) renders
 * as an explicit "—" (em dash) - a FAIL-CLOSED display choice consistent
 * with limitFor()'s own fail-closed default (0/denied, never treated as
 * unlimited) - never silently rendered as "0" (which could misleadingly read
 * as "you get nothing" for a key that a plan simply hasn't opted into yet)
 * nor blank (which could read as a rendering bug rather than an intentional
 * absence).
 *
 * @param int|null $v The raw limit value (-1 = unlimited), or null when the
 *                     key is absent from the plan's decoded limitsJSON.
 *
 * @return string The formatted limit ('Unlimited', '—', or a thousands-
 *                separated number via number_format()).
 */
function pricingFormatLimit(int|null $v): string
{
    if ($v === null) {
        // 📌 Fail-closed display: absent means "not offered on this plan",
        // never silently treated as zero or unlimited.
        return '—';
    }

    if ($v === -1) {
        return 'Unlimited';
    }

    return number_format($v);
}

// ==============================================================================
// 💷 pricingFindPricePointByInterval() - PURE: pick the display price point
//    for one billingInterval (#70)
// ==============================================================================

/**
 * 💷 Pick the price point to SHOW for a given billing interval, if any.
 *
 * PURE function - no I/O. Prefers the row explicitly marked `isDefault` for
 * that interval (an admin's "this is the recommended/canonical monthly row"
 * choice, e.g. when several currencies share the same billingInterval); when
 * none is marked default, falls back to the FIRST matching row in the given
 * (already sortOrder-ordered) list - never throws, never warns, simply
 * returns null when no price point matches the requested interval at all
 * (e.g. a lifetime-only plan has no 'monthly' row - the caller then falls
 * back to the legacy priceMonthly column, see pricingDisplayRowsFromPlans()).
 *
 * @param array  $pricePoints     Decoded price-point rows (see
 *                                 pricingDisplayRowsFromPlans()'s Pass 1 shape).
 * @param string $billingInterval 'monthly'|'yearly'|'lifetime'|'one_off'|'metered'.
 *
 * @return array|null The matching price point, or null when none matches.
 */
function pricingFindPricePointByInterval(array $pricePoints, string $billingInterval): ?array
{
    $firstMatch = null;

    foreach ($pricePoints as $pricePoint) {
        if (!isset($pricePoint['billingInterval']) || $pricePoint['billingInterval'] !== $billingInterval) {
            continue;
        }

        if ($firstMatch === null) {
            $firstMatch = $pricePoint;
        }

        if (!empty($pricePoint['isDefault'])) {
            // ⭐ An explicit default for this interval wins outright, even if
            // it was not the first matching row in sortOrder.
            return $pricePoint;
        }
    }

    return $firstMatch;
}

// ==============================================================================
// 🧮 pricingDisplayRowsFromPlans() - PURE transform: raw plan rows -> display rows
// ==============================================================================

/**
 * 🧮 Transform raw tblSubscriptionPlans rows into a stable per-plan display shape.
 *
 * PURE function - no database access, no I/O, safe to unit-test directly with
 * a hand-built $plans array. Decodes each plan's featuresJSON/limitsJSON
 * (malformed/non-object JSON decodes to a safe empty array, never a warning
 * or thrown exception - the same fail-closed convention fn_features.php's
 * planByKey() uses for the same two columns).
 *
 * Feature/limit rows are built from the UNION of every key seen across ALL
 * given plans (not just the keys present on any one plan), so every card in
 * the resulting grid lists the SAME rows in the SAME order - a plan that
 * simply lacks a given key (rather than explicitly setting it false/absent)
 * renders that row as "not included" (features) or "—" (limits), which is
 * the correct comparison-table behaviour and matches featureEnabled()'s /
 * limitFor()'s own fail-closed semantics for an absent key.
 *
 * OWNER DECISION (2026-09-25, #125): that union is additionally FILTERED to
 * only the keys present on pricingAllowedDisplayKeys() (the single
 * hand-maintained allow-list) - a key seen in some plan's JSON that is NOT
 * on that allow-list is silently dropped and never generates a row, and the
 * row order follows the ALLOW-LIST's own order (not first-seen-across-plans
 * order). Every emitted feature/limit row also carries a new 'status' field
 * ('available'|'coming_soon', copied straight from the allow-list) alongside
 * its existing fields - purely additive, every pre-#125 caller/test that
 * only reads 'key'/'label'/'enabled'/'value' keeps working unchanged.
 *
 * #70 flexible-pricing branch: when a plan row carries a non-empty
 * 'pricePoints' array (listPublicPlansForDisplay()'s additive query, ONLY
 * populated when billing_price_points_enabled=1 and rows exist),
 * priceMonthlyFormatted/priceYearlyFormatted PREFER the matching
 * `billingInterval='monthly'`/`'yearly'` price point over the legacy
 * priceMonthly/priceYearly columns (via pricingFindPricePointByInterval()),
 * and every decoded price point is additionally exposed on the output row's
 * new 'pricePoints' key (grantsJSON decoded, amount pre-formatted) - useful
 * for a lifetime/PAYG-pack card that has no 'monthly'/'yearly' row at all. A
 * plan with NO 'pricePoints' input (or an absent key entirely - the exact
 * shape every existing caller/test already passes) behaves byte-for-byte as
 * before this branch existed: legacy priceMonthly/priceYearly, and an empty
 * 'pricePoints' output array.
 *
 * @param array $plans Raw plan rows as returned by listPublicPlansForDisplay()
 *                      (each with string featuresJSON/limitsJSON columns, and
 *                      optionally a 'pricePoints' array of raw tblPlanPricePoints
 *                      rows - #70, absent/empty = legacy-only behaviour).
 *
 * @return array One display row per input plan, each shaped:
 *   [
 *       'planKey'               => string,
 *       'planName'              => string,
 *       'description'           => string,
 *       'ctaMode'               => 'checkout'|'contact',
 *       'contactUrl'            => string|null,
 *       'pricePromiseMode'      => string|null,  // decision 0020; null = site-wide
 *       'pricePromiseMonths'    => int|null,
 *       'priceMonthly'          => string,  // decimal text, never a float
 *       'priceMonthlyFormatted' => string,
 *       'priceYearlyFormatted'  => string,
 *       'currency'              => string,
 *       'isZeroPrice'           => bool,
 *       'features' => [ ['key'=>string,'label'=>string,'enabled'=>bool,
 *                         'status'=>'available'|'coming_soon'], ... ],  // #125
 *       'limits'   => [ ['key'=>string,'label'=>string,'value'=>string,
 *                         'status'=>'available'|'coming_soon'], ... ],  // #125
 *       'pricePoints' => [ ['priceKey'=>string,'billingInterval'=>string,
 *                            'intervalCount'=>int,'amountFormatted'=>string,
 *                            'currency'=>string,'grants'=>array,
 *                            'isDefault'=>bool], ... ],  // #70, [] when none
 *   ]
 *   Empty array when given an empty (or non-plan-shaped) input. A plan whose
 *   JSON contains NO allow-listed key at all (#125) still produces its own
 *   display row - just with empty 'features'/'limits' sub-arrays.
 *
 * @see pricingAllowedDisplayKeys(), pricingHumanizeKey(), pricingFormatPrice(), pricingFormatLimit(), pricingFindPricePointByInterval()
 */
function pricingDisplayRowsFromPlans(array $plans): array
{
    if (empty($plans)) {
        return [];
    }

    // ------------------------------------------------------------------
    // 🔄 Pass 1: decode every plan's featuresJSON/limitsJSON up front, and
    // collect the UNION of feature/limit keys ACTUALLY SEEN across every
    // given plan - as two simple presence SETS here, NOT an ordered list;
    // the final, allow-list-filtered + allow-list-ordered lists are built
    // straight after this loop (see the block immediately below it).
    // ------------------------------------------------------------------
    $decodedPlans    = [];
    $seenFeatureKeys = [];
    $seenLimitKeys   = [];

    // 🚦 #125 owner decision (2026-09-25): THE single hand-maintained
    // allow-list - only a key present here can EVER reach the display
    // output, in THIS array's order, with ITS label/status - never a raw
    // JSON key, never the auto-humanised fallback label, never plan-defined
    // first-seen order. See pricingAllowedDisplayKeys()'s own docblock for
    // the full rationale + the evidence behind every key's status.
    $allowedDisplayKeys = pricingAllowedDisplayKeys();

    foreach ($plans as $planRow) {
        if (isset($planRow['featuresJSON'])) {
            $featuresRaw = (string) $planRow['featuresJSON'];
        } else {
            $featuresRaw = '';
        }

        $featuresDecoded = json_decode($featuresRaw, true);
        if (!is_array($featuresDecoded)) {
            // 🚨 Malformed JSON, an empty string, or valid JSON that decoded
            // to a non-array (e.g. a bare number/string) - fail safe to an
            // empty feature set rather than warning or throwing.
            $featuresDecoded = [];
        }

        if (isset($planRow['limitsJSON'])) {
            $limitsRaw = (string) $planRow['limitsJSON'];
        } else {
            $limitsRaw = '';
        }

        $limitsDecoded = json_decode($limitsRaw, true);
        if (!is_array($limitsDecoded)) {
            $limitsDecoded = [];
        }

        // 💷 #70: decode this plan's price points, if any were attached by
        // listPublicPlansForDisplay()'s additive query - absent/non-array
        // input (every pre-#70 caller, including every existing test fixture)
        // fails safe to an empty list, which is exactly the "no price points"
        // legacy-fallback state Pass 2 below already knows how to render.
        $pricePointsRaw     = (isset($planRow['pricePoints']) && is_array($planRow['pricePoints'])) ? $planRow['pricePoints'] : [];
        $pricePointsDecoded = [];

        foreach ($pricePointsRaw as $pricePointRow) {
            $grantsRaw = (isset($pricePointRow['grantsJSON']) && $pricePointRow['grantsJSON'] !== null)
                ? (string) $pricePointRow['grantsJSON']
                : '';

            $grantsDecoded = json_decode($grantsRaw, true);
            if (!is_array($grantsDecoded)) {
                // 🚨 Same fail-safe convention as featuresJSON/limitsJSON
                // above - NULL/malformed grantsJSON is a normal, expected
                // state for an ordinary (non-PAYG) price point.
                $grantsDecoded = [];
            }

            $pricePointsDecoded[] = [
                'priceKey'        => isset($pricePointRow['priceKey']) ? (string) $pricePointRow['priceKey'] : '',
                'billingInterval' => isset($pricePointRow['billingInterval']) ? (string) $pricePointRow['billingInterval'] : '',
                'intervalCount'   => isset($pricePointRow['intervalCount']) ? (int) $pricePointRow['intervalCount'] : 1,
                'amount'          => isset($pricePointRow['amount']) ? (string) $pricePointRow['amount'] : '',
                'currency'        => (isset($pricePointRow['currency']) && $pricePointRow['currency'] !== '')
                    ? (string) $pricePointRow['currency']
                    : 'GBP',
                'grants'          => $grantsDecoded,
                'isDefault'       => isset($pricePointRow['isDefault']) && ((int) $pricePointRow['isDefault']) === 1,
            ];
        }

        // 🔘 Task 7n Phase 2: what the button does. Absent (before database
        // upgrade 0004) means the ordinary "buy it" button.
        $ctaMode = 'checkout';
        if (isset($planRow['ctaMode']) && $planRow['ctaMode'] === 'contact') {
            $ctaMode = 'contact';
        }
        $contactUrl = null;
        if (isset($planRow['contactUrl'])) {
            $contactUrl = (string) $planRow['contactUrl'];
        }

        // 🤝 Decision 0020 (#159): the plan's own price promise (absent before
        //    database upgrade 0004; null = follow the site-wide setting).
        $promiseMode = null;
        if (isset($planRow['pricePromiseMode'])) {
            $promiseMode = (string) $planRow['pricePromiseMode'];
        }
        $promiseMonths = null;
        if (isset($planRow['pricePromiseMonths'])) {
            $promiseMonths = (int) $planRow['pricePromiseMonths'];
        }

        $decodedPlans[] = [
            'ctaMode'      => $ctaMode,
            'contactUrl'   => $contactUrl,
            'pricePromiseMode'   => $promiseMode,
            'pricePromiseMonths' => $promiseMonths,
            'hasPricePoint' => !empty($planRow['hasPricePoint']),
            'planKey'      => isset($planRow['planKey']) ? (string) $planRow['planKey'] : '',
            'planName'     => isset($planRow['planName']) ? (string) $planRow['planName'] : '',
            'description'  => isset($planRow['description']) ? (string) $planRow['description'] : '',
            'priceMonthly' => isset($planRow['priceMonthly']) ? (string) $planRow['priceMonthly'] : '0',
            'priceYearly'  => isset($planRow['priceYearly']) ? (string) $planRow['priceYearly'] : '0',
            'currency'     => (isset($planRow['currency']) && $planRow['currency'] !== '')
                ? (string) $planRow['currency']
                : 'GBP',
            'features'     => $featuresDecoded,
            'limits'       => $limitsDecoded,
            'pricePoints'  => $pricePointsDecoded,
        ];

        foreach (array_keys($featuresDecoded) as $featureKey) {
            $seenFeatureKeys[$featureKey] = true;
        }

        foreach (array_keys($limitsDecoded) as $limitKey) {
            $seenLimitKeys[$limitKey] = true;
        }
    }

    // ------------------------------------------------------------------
    // 🔒 #125: build the FINAL feature/limit key order by walking the
    // allow-list (never plan-defined first-seen order) and keeping only the
    // keys that are BOTH allow-listed AND actually present on at least one
    // given plan's decoded JSON:
    //   - a key that is allow-listed but genuinely absent from every given
    //     plan's JSON still generates no row (nothing to compare/display);
    //   - a key present in some plan's JSON but NOT on the allow-list is
    //     silently dropped - the core fix this issue asked for, so a future
    //     groundwork key can never leak onto the public page.
    // ------------------------------------------------------------------
    $featureKeyOrder = [];
    $limitKeyOrder   = [];

    foreach (array_keys($allowedDisplayKeys) as $allowedKey) {
        if (isset($seenFeatureKeys[$allowedKey])) {
            $featureKeyOrder[] = $allowedKey;
        }

        if (isset($seenLimitKeys[$allowedKey])) {
            $limitKeyOrder[] = $allowedKey;
        }
    }

    // ------------------------------------------------------------------
    // 🔄 Pass 2: build the final display row for each plan against the
    // shared, allow-list-filtered key order computed above.
    // ------------------------------------------------------------------
    $displayRows = [];

    foreach ($decodedPlans as $decodedPlan) {
        $featureRows = [];
        foreach ($featureKeyOrder as $featureKey) {
            if (array_key_exists($featureKey, $decodedPlan['features'])) {
                $isEnabled = (bool) $decodedPlan['features'][$featureKey];
            } else {
                // 📌 Fail-closed: an absent key is "not included" - the same
                // convention featureEnabled() applies at the gating layer.
                $isEnabled = false;
            }

            $featureRows[] = [
                'key'     => $featureKey,
                'label'   => pricingHumanizeKey($featureKey),
                'enabled' => $isEnabled,
                // 🚦 #125: 'available'|'coming_soon' from the allow-list -
                // $featureKey is only ever a key already proven to exist in
                // $allowedDisplayKeys (it came FROM that array's own key
                // order above), but the null-coalesce is a harmless,
                // fail-safe belt-and-braces default matching this file's
                // house convention of never trusting an array lookup blindly.
                'status'  => $allowedDisplayKeys[$featureKey]['status'] ?? 'coming_soon',
            ];
        }

        $limitRows = [];
        foreach ($limitKeyOrder as $limitKey) {
            if (array_key_exists($limitKey, $decodedPlan['limits'])) {
                $limitValue = (int) $decodedPlan['limits'][$limitKey];
            } else {
                $limitValue = null;
            }

            $limitRows[] = [
                'key'   => $limitKey,
                'label' => pricingHumanizeKey($limitKey),
                'value' => pricingFormatLimit($limitValue),
                // 🚦 #125: 'available'|'coming_soon' from the allow-list -
                // see the matching comment on $featureRows above for why the
                // null-coalesce fallback is present (belt-and-braces only).
                'status' => $allowedDisplayKeys[$limitKey]['status'] ?? 'coming_soon',
            ];
        }

        // ------------------------------------------------------------
        // 💷 #70 branch: PREFER tblPlanPricePoints rows when present for
        // this plan, else fall back to the legacy priceMonthly/priceYearly
        // columns - EXACTLY the existing pricingFormatPrice() calls below,
        // unchanged, whenever a plan carries no price points (the universal
        // case until an admin populates Table 22 AND flips
        // billing_price_points_enabled=1).
        // ------------------------------------------------------------
        // 💱 Only price points in the plan's own currency compete for the
        // headline figures (the same offer exists in several currencies).
        $ownCurrencyPoints = [];
        foreach ($decodedPlan['pricePoints'] as $pricePoint) {
            if ($pricePoint['currency'] === $decodedPlan['currency']) {
                $ownCurrencyPoints[] = $pricePoint;
            }
        }
        $monthlyPricePoint = pricingFindPricePointByInterval($ownCurrencyPoints, 'monthly');
        $yearlyPricePoint  = pricingFindPricePointByInterval($ownCurrencyPoints, 'yearly');

        if ($monthlyPricePoint !== null) {
            $priceMonthlyFormatted = pricingFormatPrice($monthlyPricePoint['amount'], $monthlyPricePoint['currency']);
        } else {
            $priceMonthlyFormatted = pricingFormatPrice($decodedPlan['priceMonthly'], $decodedPlan['currency']);
        }

        if ($yearlyPricePoint !== null) {
            $priceYearlyFormatted = pricingFormatPrice($yearlyPricePoint['amount'], $yearlyPricePoint['currency']);
        } else {
            $priceYearlyFormatted = pricingFormatPrice($decodedPlan['priceYearly'], $decodedPlan['currency']);
        }

        // 📋 Every decoded price point, pre-formatted for direct rendering -
        // e.g. a lifetime-only or PAYG-pack plan's 'one_off'/'lifetime' rows,
        // which the monthly/yearly preference above never picks up. Stays []
        // for every plan with no price points (the legacy-only case).
        $pricePointRows = [];
        foreach ($decodedPlan['pricePoints'] as $pricePoint) {
            $pricePointRows[] = [
                'priceKey'        => $pricePoint['priceKey'],
                'billingInterval' => $pricePoint['billingInterval'],
                'intervalCount'   => $pricePoint['intervalCount'],
                'amountFormatted' => pricingFormatPrice($pricePoint['amount'], $pricePoint['currency']),
                'currency'        => $pricePoint['currency'],
                'grants'          => $pricePoint['grants'],
                'isDefault'       => $pricePoint['isDefault'],
            ];
        }

        // 🆓 Really free = a "buy it" plan with nothing to pay in either of
        // the single-price columns AND no price of its own. A "Contact us"
        // plan (whose yearly column is 0.00) is never free, and (decision
        // 0021, #160) neither is any plan other than the two the site gives
        // without payment: a paid plan with no price yet says "Price coming
        // soon" instead (pricingPriceBlock()).
        $isFree = ($decodedPlan['ctaMode'] !== 'contact'
            && pricingIsNoPaymentPlan($decodedPlan['planKey'])
            && !$decodedPlan['hasPricePoint']
            && pricingFormatPrice($decodedPlan['priceMonthly'], $decodedPlan['currency']) === 'Free'
            && in_array(pricingFormatPrice($decodedPlan['priceYearly'], $decodedPlan['currency']), ['Free', ''], true));

        $displayRows[] = [
            'ctaMode'               => $decodedPlan['ctaMode'],
            'contactUrl'            => $decodedPlan['contactUrl'],
            'pricePromiseMode'      => $decodedPlan['pricePromiseMode'],
            'pricePromiseMonths'    => $decodedPlan['pricePromiseMonths'],
            'planKey'               => $decodedPlan['planKey'],
            'planName'              => $decodedPlan['planName'],
            'description'           => $decodedPlan['description'],
            'priceMonthly'          => $decodedPlan['priceMonthly'],
            'priceMonthlyFormatted' => $priceMonthlyFormatted,
            'priceYearlyFormatted'  => $priceYearlyFormatted,
            'currency'              => $decodedPlan['currency'],
            'isZeroPrice'           => $isFree,
            'features'              => $featureRows,
            'limits'                => $limitRows,
            'pricePoints'           => $pricePointRows,
        ];
    }

    return $displayRows;
}
