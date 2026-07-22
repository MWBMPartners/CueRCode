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
 * hardcoded plan-key literals whatsoever - not even the two fallback keys
 * fn_features.php itself is documented to reference by name - every plan
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
 * @version     1.0.0
 * @since       Phase 3 (Epic E4, #56)
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
 * "shown on the public pricing grid" - see this file's header comment).
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
 * @return array Raw plan rows (planID, planKey, planName, description,
 *               priceMonthly, priceYearly, currency, featuresJSON (raw JSON
 *               string), limitsJSON (raw JSON string)) - JSON columns are
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
            'SELECT `planID`, `planKey`, `planName`, `description`, `priceMonthly`, '
            . '`priceYearly`, `currency`, `featuresJSON`, `limitsJSON` '
            . 'FROM `tblSubscriptionPlans` '
            . 'WHERE `isActive` = 1 AND `sortOrder` > 0 '
            . 'ORDER BY `sortOrder` ASC, `planID` ASC'
        );

        $result = $stmt->get_result();

        $planRows = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $planRows[] = $row;
        }

        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Public pricing plan list failed', $e->getMessage());
        }
        return [];
    }

    return $planRows;
}

// ==============================================================================
// 🏷️ pricingHumanizeKey() - Generic feature/limit key -> human label
// ==============================================================================

/**
 * 🏷️ Turn a featuresJSON/limitsJSON key into a human-readable display label.
 *
 * A small curated map covers the keys seeded today (nicer prose than the raw
 * snake_case, e.g. spelling out "SSO" and "API" rather than title-casing them
 * as "Sso"/"Api"). Any key NOT in the curated map - i.e. a brand-new
 * feature/limit an admin adds to a plan row's JSON that this file has never
 * heard of - falls through to a generic snake_case-to-Title-Case humaniser,
 * so a new plan key renders sensibly with ZERO code change here, exactly the
 * property the issue asked for.
 *
 * @param string $key The raw featuresJSON/limitsJSON key (e.g. 'csv_export').
 *
 * @return string A human-readable label (e.g. 'CSV/JSON analytics export').
 */
function pricingHumanizeKey(string $key): string
{
    // 🗂️ Curated overrides for the keys seeded in seed_data.sql today - see
    // that file's header comment for the authoritative key list/meanings.
    static $curatedLabels = [
        // 🚩 featuresJSON keys
        'expiry_scheduling'      => 'Expiry & scheduling',
        'password_protected_qr'  => 'Password-protected QR codes',
        'sso'                    => 'Single sign-on (SSO)',
        'organisations'          => 'Organisation accounts & roles',
        'webhooks'                => 'Outbound webhooks',
        'csv_export'             => 'Analytics CSV/JSON export',
        // 🔢 limitsJSON keys
        'saved_codes'            => 'Saved codes',
        'bulk_batch_size'        => 'Bulk batch size',
        'dynamic_codes'          => 'Dynamic (editable) QR codes',
        'api_keys'               => 'API keys',
        'analytics_days'         => 'Analytics history retained',
        'api_rate_limit_per_min' => 'API rate limit (per minute)',
        'api_rate_limit_per_day' => 'API rate limit (per day)',
    ];

    if (array_key_exists($key, $curatedLabels)) {
        return $curatedLabels[$key];
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
// 💷 pricingFormatPrice() - Format a plan price for display
// ==============================================================================

/**
 * 💷 Format a monetary amount for the pricing grid.
 *
 * Zero (or negative, which should never occur but is handled the same,
 * fail-safe way) reads as the plain word "Free" - clearer at a glance on a
 * comparison grid than "£0.00" repeated across every zero-price plan. A
 * positive GBP amount gets the "£" symbol prefix; any OTHER currency code
 * (this codebase seeds GBP exclusively today, but tblSubscriptionPlans.currency
 * is a free CHAR(3) column an admin could change) falls back to its
 * uppercased ISO 4217 code as a prefix instead of guessing a symbol.
 *
 * @param float|int|string $amount   The raw price (e.g. tblSubscriptionPlans.priceMonthly).
 * @param string            $currency The ISO 4217 currency code (e.g. 'GBP').
 *
 * @return string The formatted price string (e.g. 'Free', '£4.99', 'USD 9.99').
 *
 * @see https://www.php.net/manual/en/function.number-format.php
 */
function pricingFormatPrice(float|int|string $amount, string $currency): string
{
    // 🔢 Normalise to float regardless of the caller's numeric-ish input type
    // (tblSubscriptionPlans.priceMonthly arrives as a numeric string from
    // mysqli in some driver configurations, or already-float when the caller
    // has cast it).
    $amountFloat = (float) $amount;

    // 🆓 Zero (or below, defensively) reads as "Free" rather than a currency
    // amount - this is the ONLY special-casing in this function, and it is
    // driven purely by the numeric VALUE, never a plan name/key.
    if ($amountFloat <= 0.0) {
        return 'Free';
    }

    $currencyUpper = strtoupper(trim($currency));

    if ($currencyUpper === 'GBP') {
        // 💷 The one currency this codebase seeds today.
        $pricePrefix = '£';
    } elseif ($currencyUpper === '') {
        // 📌 Defensive: an empty/missing currency column - fail safe to a
        // neutral, unambiguous prefix rather than silently assuming GBP.
        $pricePrefix = '? ';
    } else {
        // 🌍 Any other ISO 4217 code - print it explicitly rather than guess
        // a symbol that might be wrong (e.g. '$' is ambiguous across USD/CAD/AUD).
        $pricePrefix = $currencyUpper . ' ';
    }

    return $pricePrefix . number_format($amountFloat, 2);
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
 * @param array $plans Raw plan rows as returned by listPublicPlansForDisplay()
 *                      (each with string featuresJSON/limitsJSON columns).
 *
 * @return array One display row per input plan, each shaped:
 *   [
 *       'planKey'               => string,
 *       'planName'              => string,
 *       'description'           => string,
 *       'priceMonthly'          => float,
 *       'priceMonthlyFormatted' => string,
 *       'priceYearlyFormatted'  => string,
 *       'currency'              => string,
 *       'isZeroPrice'           => bool,
 *       'features' => [ ['key'=>string,'label'=>string,'enabled'=>bool], ... ],
 *       'limits'   => [ ['key'=>string,'label'=>string,'value'=>string], ... ],
 *   ]
 *   Empty array when given an empty (or non-plan-shaped) input.
 *
 * @see pricingHumanizeKey(), pricingFormatPrice(), pricingFormatLimit()
 */
function pricingDisplayRowsFromPlans(array $plans): array
{
    if (empty($plans)) {
        return [];
    }

    // ------------------------------------------------------------------
    // 🔄 Pass 1: decode every plan's featuresJSON/limitsJSON up front, and
    // collect the UNION of feature/limit keys (first-seen order preserved)
    // so every card ends up with an identical row set.
    // ------------------------------------------------------------------
    $decodedPlans    = [];
    $featureKeyOrder = [];
    $limitKeyOrder   = [];

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

        $decodedPlans[] = [
            'planKey'      => isset($planRow['planKey']) ? (string) $planRow['planKey'] : '',
            'planName'     => isset($planRow['planName']) ? (string) $planRow['planName'] : '',
            'description'  => isset($planRow['description']) ? (string) $planRow['description'] : '',
            'priceMonthly' => isset($planRow['priceMonthly']) ? (float) $planRow['priceMonthly'] : 0.0,
            'priceYearly'  => isset($planRow['priceYearly']) ? (float) $planRow['priceYearly'] : 0.0,
            'currency'     => (isset($planRow['currency']) && $planRow['currency'] !== '')
                ? (string) $planRow['currency']
                : 'GBP',
            'features'     => $featuresDecoded,
            'limits'       => $limitsDecoded,
        ];

        foreach (array_keys($featuresDecoded) as $featureKey) {
            if (!in_array($featureKey, $featureKeyOrder, true)) {
                $featureKeyOrder[] = $featureKey;
            }
        }

        foreach (array_keys($limitsDecoded) as $limitKey) {
            if (!in_array($limitKey, $limitKeyOrder, true)) {
                $limitKeyOrder[] = $limitKey;
            }
        }
    }

    // ------------------------------------------------------------------
    // 🔄 Pass 2: build the final display row for each plan against the
    // shared key union computed above.
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
            ];
        }

        $displayRows[] = [
            'planKey'               => $decodedPlan['planKey'],
            'planName'              => $decodedPlan['planName'],
            'description'           => $decodedPlan['description'],
            'priceMonthly'          => $decodedPlan['priceMonthly'],
            'priceMonthlyFormatted' => pricingFormatPrice($decodedPlan['priceMonthly'], $decodedPlan['currency']),
            'priceYearlyFormatted'  => pricingFormatPrice($decodedPlan['priceYearly'], $decodedPlan['currency']),
            'currency'              => $decodedPlan['currency'],
            'isZeroPrice'           => ($decodedPlan['priceMonthly'] <= 0.0),
            'features'              => $featureRows,
            'limits'                => $limitRows,
        ];
    }

    return $displayRows;
}
