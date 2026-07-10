<?php
/**
 * ==============================================================================
 * 💳 CueRCode - Premium Tier & Feature Gating Engine
 * ==============================================================================
 *
 * Path: _functions/fn_features.php
 *
 * Resolves the EFFECTIVE subscription plan for a user or organisation and
 * answers "is this feature on?" / "what is this numeric limit?" generically
 * from tblSubscriptionPlans.featuresJSON/limitsJSON - the single source of
 * truth for every tier's capabilities (PROJECT_ROADMAP.md "Premium Tier
 * Design", Epic E4, #25/#26/#53).
 *
 * OWNER REQUIREMENT this file exists to satisfy: the tier system must be
 * FULLY EXTENSIBLE and DATA-DRIVEN. Adding a new tier, moving a feature
 * between tiers, or changing a limit/price NEVER requires touching this file
 * (or any other PHP file) - it is a row edit in tblSubscriptionPlans. Every
 * lookup below is GENERIC (array key lookup against decoded JSON); there are
 * NO per-feature or per-tier branches anywhere in this file.
 *
 * Mirrors the request-scoped caching pattern established by fn_config.php's
 * getConfig()/configCacheStore() - see featuresCacheStore() below.
 *
 * Plan resolution defaults (documented owner decisions - see DEV_NOTES.md
 * "How to add or modify a premium tier" for the full rationale):
 *   - No user/org context (anonymous visitor)      -> the 'anonymous' plan.
 *   - A logged-in user/org with NO active/trialing/
 *     past_due subscription row                    -> the 'free' plan.
 *   - past_due subscription                        -> featureEnabled()/
 *     limitFor() serve the DOWNGRADE plan (default 'free', configurable via
 *     the 'billing_past_due_downgrade_plan_key' setting) for CREATING new
 *     gated objects. Existing objects remain fully readable regardless -
 *     this file never deletes/hides anything, it only gates NEW creation
 *     checks that call featureEnabled()/limitFor() before acting.
 *
 * @package     CueRCode
 * @subpackage  Billing
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E4 framework stage, #25/#26/#53)
 * @see         fn_config.php   - the request-cache pattern this file mirrors
 * @see         fn_api_bulk.php - the first real gate wired to this engine (bulk_batch_size)
 * @see         PROJECT_ROADMAP.md - "Premium Tier Design" section
 * @see         DEV_NOTES.md - "How to add or modify a premium tier"
 * @see         https://www.php.net/manual/en/function.json-decode.php
 * @see         https://dev.mysql.com/doc/refman/8.0/en/json.html
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
// 🏷️ Fallback plan keys
// ==============================================================================
// The two "nobody is paying" tiers every subject falls back to. These are the
// ONLY plan keys this file ever references by name - every other tier
// (pro/business/a future enterprise tier/...) is resolved purely by whatever
// planKey an active tblSubscriptions row happens to point at.
// ==============================================================================

if (!defined('CUERCODE_PLAN_KEY_ANONYMOUS')) {
    define('CUERCODE_PLAN_KEY_ANONYMOUS', 'anonymous');
}

if (!defined('CUERCODE_PLAN_KEY_FREE')) {
    define('CUERCODE_PLAN_KEY_FREE', 'free');
}

// ==============================================================================
// 🚨 cuercodeFailSafePlan() - Last-resort default plan shape
// ==============================================================================

/**
 * 🚨 Build a hard-coded, minimal "fail-safe" plan array.
 *
 * Used ONLY when the database is unreachable, or the requested plan key AND
 * the fallback plan key are both missing from tblSubscriptionPlans (e.g. an
 * install that ran install.sql but not yet seed_data.sql). Degrading to this
 * conservative default means an outage or partial install fails CLOSED (the
 * most restrictive real tier's shape) rather than granting unlimited access
 * or throwing a fatal error on every gated request.
 *
 * Only `bulk_batch_size` is given a concrete fail-safe value - it is the one
 * limit this framework stage actually wires to a live enforcement point
 * (fn_api_bulk.php). Every other key is intentionally absent so
 * featureEnabled()/limitFor()'s own fail-closed defaults (false / 0) apply.
 *
 * @param string $planKey The plan key to label this fail-safe plan as.
 *
 * @return array The fail-safe plan shape (same keys as a real resolved plan).
 */
function cuercodeFailSafePlan(string $planKey = CUERCODE_PLAN_KEY_ANONYMOUS): array
{
    return [
        'planID'             => 0,
        'planKey'            => $planKey,
        'planName'           => ucfirst($planKey) . ' (fail-safe default)',
        'priceMonthly'       => 0.00,
        'priceYearly'        => 0.00,
        'currency'           => 'GBP',
        'features'           => [],
        'limits'             => [
            'bulk_batch_size' => 25,
        ],
        'isActive'           => 1,
        'subscriptionID'     => null,
        'subscriptionStatus' => null,
    ];
}

// ==============================================================================
// 🗃️ featuresCacheStore() - Shared static per-request cache
// ==============================================================================

/**
 * 🗃️ Single shared static store backing this file's per-request caches.
 *
 * Mirrors fn_config.php's configCacheStore() exactly (same op contract), so
 * both plan-by-key lookups (planByKey()) and resolved-plan-by-subject lookups
 * (planForUser()/planForOrg()) share one cache with distinct key prefixes
 * ('plan:', 'user:', 'org:') to avoid any collision between the two
 * dimensions. Cleared per-key by onSubscriptionPlanChanged() when a
 * subscription's plan changes mid-request (e.g. inside a future webhook
 * handler that both updates a subscription and immediately re-checks a
 * gate).
 *
 * @param string      $op    'get' | 'set' | 'has' | 'clear' | 'clearAll'.
 * @param string|null $key   The cache key (required for get/set/has/clear).
 * @param mixed       $value The value to cache (only used by 'set').
 *
 * @return mixed For 'get' the cached value (or null if absent); for 'has' a
 *               bool; for set/clear/clearAll, null.
 *
 * @see fn_config.php - configCacheStore(), the pattern this mirrors
 */
function featuresCacheStore(string $op, ?string $key = null, mixed $value = null): mixed
{
    // 📌 The one and only request-lifetime feature/plan cache.
    static $store = [];

    switch ($op) {
        case 'has':
            return $key !== null && array_key_exists($key, $store);

        case 'get':
            if ($key !== null && array_key_exists($key, $store)) {
                return $store[$key];
            }
            return null;

        case 'set':
            if ($key !== null) {
                $store[$key] = $value;
            }
            return null;

        case 'clear':
            if ($key !== null && array_key_exists($key, $store)) {
                unset($store[$key]);
            }
            return null;

        case 'clearAll':
            $store = [];
            return null;

        default:
            return null;
    }
}

// ==============================================================================
// 📋 planByKey() - Fetch + decode a single plan row by its planKey
// ==============================================================================

/**
 * 📋 Fetch a single plan row from tblSubscriptionPlans by its planKey.
 *
 * Decodes featuresJSON/limitsJSON into PHP arrays. Cached per request (a
 * given planKey's row cannot change mid-request under normal operation).
 *
 * @param string $planKey The tblSubscriptionPlans.planKey to look up.
 *
 * @return array|null The decoded plan row, or null if not found / DB
 *                     unavailable. Shape:
 *                     ['planID','planKey','planName','priceMonthly',
 *                      'priceYearly','currency','features','limits','isActive']
 *
 * @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
 */
function planByKey(string $planKey): ?array
{
    $cacheKey = 'plan:' . $planKey;

    if (featuresCacheStore('has', $cacheKey)) {
        return featuresCacheStore('get', $cacheKey);
    }

    if (!function_exists('dbQuery')) {
        return null;
    }

    try {
        $stmt = dbQuery(
            'SELECT `planID`, `planKey`, `planName`, `priceMonthly`, `priceYearly`, `currency`, '
            . '`featuresJSON`, `limitsJSON`, `isActive` '
            . 'FROM `tblSubscriptionPlans` '
            . 'WHERE `planKey` = ? '
            . 'LIMIT 1',
            's',
            [$planKey]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Subscription plan lookup failed', 'planKey: ' . $planKey . ' | ' . $e->getMessage());
        }
        return null;
    }

    if ($row === null) {
        // 📌 Cache the miss too, so a repeatedly-requested nonexistent
        // planKey does not re-query the database on every call this request.
        featuresCacheStore('set', $cacheKey, null);
        return null;
    }

    // 🔄 Decode the two JSON columns to plain PHP arrays. A malformed/empty
    // JSON value decodes to an empty array (fail-closed for every key lookup
    // downstream) rather than null, so featureEnabled()/limitFor() never
    // have to null-check.
    // @see https://www.php.net/manual/en/function.json-decode.php
    $featuresDecoded = json_decode((string) $row['featuresJSON'], true);
    if (!is_array($featuresDecoded)) {
        $featuresDecoded = [];
    }

    $limitsDecoded = json_decode((string) $row['limitsJSON'], true);
    if (!is_array($limitsDecoded)) {
        $limitsDecoded = [];
    }

    $plan = [
        'planID'       => (int) $row['planID'],
        'planKey'      => (string) $row['planKey'],
        'planName'     => (string) $row['planName'],
        'priceMonthly' => (float) $row['priceMonthly'],
        'priceYearly'  => (float) $row['priceYearly'],
        'currency'     => (string) $row['currency'],
        'features'     => $featuresDecoded,
        'limits'       => $limitsDecoded,
        'isActive'     => (int) $row['isActive'],
    ];

    featuresCacheStore('set', $cacheKey, $plan);

    return $plan;
}

// ==============================================================================
// 🔎 resolveActiveSubscription() - Find the one active-ish subscription row
// ==============================================================================

/**
 * 🔎 Resolve the single active/trialing/past_due subscription for a subject.
 *
 * Exactly one of $userID/$orgID should be provided (the caller decides
 * which). "Exactly one active per subject" is an application-level
 * invariant (documented in install.sql) - this function simply returns the
 * MOST RECENT matching row if more than one somehow exists, rather than
 * silently picking an arbitrary one.
 *
 * Deliberately written as two separate prepared statements (rather than
 * interpolating a column name into one query) so every SQL string in this
 * file remains a static literal with only VALUE placeholders - no identifier
 * interpolation of any kind.
 *
 * @param int|null $userID The subscribing user's ID, or null.
 * @param int|null $orgID  The subscribing organisation's ID, or null.
 *
 * @return array|null The raw subscription row (subscriptionID, planID,
 *                     status, currentPeriodStart, currentPeriodEnd, planKey),
 *                     or null if none found / DB unavailable.
 */
function resolveActiveSubscription(?int $userID, ?int $orgID): ?array
{
    if (!function_exists('dbQuery')) {
        return null;
    }

    try {
        if ($userID !== null) {
            $stmt = dbQuery(
                'SELECT s.`subscriptionID`, s.`planID`, s.`status`, '
                . 's.`currentPeriodStart`, s.`currentPeriodEnd`, p.`planKey` '
                . 'FROM `tblSubscriptions` s '
                . 'INNER JOIN `tblSubscriptionPlans` p ON p.`planID` = s.`planID` '
                . "WHERE s.`userID` = ? AND s.`status` IN ('active', 'trialing', 'past_due') "
                . 'ORDER BY s.`createdAt` DESC '
                . 'LIMIT 1',
                'i',
                [$userID]
            );
        } elseif ($orgID !== null) {
            $stmt = dbQuery(
                'SELECT s.`subscriptionID`, s.`planID`, s.`status`, '
                . 's.`currentPeriodStart`, s.`currentPeriodEnd`, p.`planKey` '
                . 'FROM `tblSubscriptions` s '
                . 'INNER JOIN `tblSubscriptionPlans` p ON p.`planID` = s.`planID` '
                . "WHERE s.`orgID` = ? AND s.`status` IN ('active', 'trialing', 'past_due') "
                . 'ORDER BY s.`createdAt` DESC '
                . 'LIMIT 1',
                'i',
                [$orgID]
            );
        } else {
            return null;
        }

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Subscription lookup failed', $e->getMessage());
        }
        return null;
    }

    return $row;
}

// ==============================================================================
// 🏢 resolveLegacyOrgTierKey() - Interim tblOrganisations.subscriptionTier bridge
// ==============================================================================

/**
 * 🏢 Honour the legacy tblOrganisations.subscriptionTier placeholder column.
 *
 * tblOrganisations shipped in Phase 1 with a `subscriptionTier` VARCHAR
 * placeholder (default 'free') long before this real plan/subscription
 * schema existed. Until the organisations UI (Epic E3 org stage) lets an org
 * actually subscribe (creating a tblSubscriptions row), this bridges that
 * legacy column as a best-effort hint - but ONLY if it happens to match a
 * REAL, currently-seeded planKey, so a stale legacy value (the column's
 * original comment listed 'free'/'basic'/'premium'/'enterprise', none of
 * which except 'free' exist as real plan rows today) never silently
 * resolves to nothing.
 *
 * @param int $orgID The organisation ID to look up.
 *
 * @return string|null A matching, real planKey, or null if no hint applies.
 */
function resolveLegacyOrgTierKey(int $orgID): ?string
{
    if (!function_exists('dbQuery')) {
        return null;
    }

    try {
        $stmt = dbQuery(
            'SELECT `subscriptionTier` FROM `tblOrganisations` WHERE `orgID` = ? LIMIT 1',
            'i',
            [$orgID]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        return null;
    }

    if ($row === null || !isset($row['subscriptionTier'])) {
        return null;
    }

    $candidateKey = (string) $row['subscriptionTier'];

    if ($candidateKey !== '' && planByKey($candidateKey) !== null) {
        return $candidateKey;
    }

    return null;
}

// ==============================================================================
// 🧩 resolvePlanArray() - Merge a subscription row (if any) onto a plan
// ==============================================================================

/**
 * 🧩 Resolve the final plan array for a subject, given an optional subscription row.
 *
 * @param array|null $subscription   The row from resolveActiveSubscription(),
 *                                    or null if the subject has none.
 * @param string     $fallbackPlanKey The planKey to use when $subscription is
 *                                    null (or its plan row is missing).
 *
 * @return array The resolved plan, always including 'subscriptionID' and
 *               'subscriptionStatus' (both null when there is no subscription).
 */
function resolvePlanArray(?array $subscription, string $fallbackPlanKey): array
{
    if ($subscription !== null && isset($subscription['planKey'])) {
        $plan = planByKey((string) $subscription['planKey']);
    } else {
        $plan = null;
    }

    if ($plan === null) {
        $plan = planByKey($fallbackPlanKey);
    }

    if ($plan === null) {
        // 🚨 Neither the subscription's own plan nor the fallback plan
        // exists (e.g. install.sql ran but seed_data.sql did not). Degrade
        // to the hard-coded fail-safe shape rather than erroring.
        $plan = cuercodeFailSafePlan($fallbackPlanKey);
    }

    if ($subscription !== null) {
        $plan['subscriptionID']     = (int) $subscription['subscriptionID'];
        $plan['subscriptionStatus'] = (string) $subscription['status'];
    } else {
        $plan['subscriptionID']     = null;
        $plan['subscriptionStatus'] = null;
    }

    return $plan;
}

// ==============================================================================
// 👤 planForUser() - Resolve the effective plan for a user (or anonymous)
// ==============================================================================

/**
 * 👤 Resolve the raw (non-past_due-downgraded) plan for a user.
 *
 * Resolution order:
 *   1. $userID === null (anonymous visitor)             -> 'anonymous' plan.
 *   2. An active/trialing/past_due subscription exists  -> that subscription's plan.
 *   3. Logged-in user, no qualifying subscription        -> 'free' plan
 *      (an owner decision - see this file's header comment).
 *
 * This is the RAW resolution - it does NOT apply the past_due downgrade
 * (that only affects featureEnabled()/limitFor() via effectivePlanForGating(),
 * so a caller displaying "your plan" to the user still sees their real paid
 * tier even while payment is past due).
 *
 * @param int|null $userID The user's ID, or null for an anonymous visitor.
 *
 * @return array The resolved plan (see planByKey() for the shape), plus
 *               'subscriptionID' and 'subscriptionStatus'.
 */
function planForUser(?int $userID): array
{
    if ($userID === null) {
        $cacheKey = 'user:anon';
    } else {
        $cacheKey = 'user:' . $userID;
    }

    if (featuresCacheStore('has', $cacheKey)) {
        return featuresCacheStore('get', $cacheKey);
    }

    if ($userID === null) {
        $resolved = resolvePlanArray(null, CUERCODE_PLAN_KEY_ANONYMOUS);
    } else {
        $subscription = resolveActiveSubscription($userID, null);
        $resolved     = resolvePlanArray($subscription, CUERCODE_PLAN_KEY_FREE);
    }

    featuresCacheStore('set', $cacheKey, $resolved);

    return $resolved;
}

// ==============================================================================
// 🏢 planForOrg() - Resolve the effective plan for an organisation
// ==============================================================================

/**
 * 🏢 Resolve the raw (non-past_due-downgraded) plan for an organisation.
 *
 * Resolution order:
 *   1. $orgID === null                                    -> 'anonymous' plan
 *      (there is no such thing as an anonymous org, but this keeps the
 *      function total/never-throwing for a caller that has not yet
 *      resolved an orgID).
 *   2. An active/trialing/past_due subscription exists     -> that plan.
 *   3. No subscription, but tblOrganisations.subscriptionTier
 *      names a REAL plan (legacy placeholder bridge)       -> that plan.
 *   4. No subscription and no matching legacy hint          -> 'free' plan.
 *
 * Organisations do not yet have a UI to actually subscribe (Epic E3 org
 * stage) - this function resolves SENSIBLY today and will pick up real
 * org subscriptions automatically the moment that stage inserts
 * tblSubscriptions rows with orgID set, with zero change here.
 *
 * @param int|null $orgID The organisation's ID, or null.
 *
 * @return array The resolved plan (see planByKey() for the shape), plus
 *               'subscriptionID' and 'subscriptionStatus'.
 */
function planForOrg(?int $orgID): array
{
    if ($orgID === null) {
        $cacheKey = 'org:none';
    } else {
        $cacheKey = 'org:' . $orgID;
    }

    if (featuresCacheStore('has', $cacheKey)) {
        return featuresCacheStore('get', $cacheKey);
    }

    if ($orgID === null) {
        $resolved = resolvePlanArray(null, CUERCODE_PLAN_KEY_ANONYMOUS);
        featuresCacheStore('set', $cacheKey, $resolved);
        return $resolved;
    }

    $subscription = resolveActiveSubscription(null, $orgID);

    if ($subscription === null) {
        $legacyTierKey = resolveLegacyOrgTierKey($orgID);
        if ($legacyTierKey !== null) {
            $resolved = resolvePlanArray(null, $legacyTierKey);
        } else {
            $resolved = resolvePlanArray(null, CUERCODE_PLAN_KEY_FREE);
        }
    } else {
        $resolved = resolvePlanArray($subscription, CUERCODE_PLAN_KEY_FREE);
    }

    featuresCacheStore('set', $cacheKey, $resolved);

    return $resolved;
}

// ==============================================================================
// 🔻 effectivePlanForGating() - Apply the past_due downgrade for gating checks
// ==============================================================================

/**
 * 🔻 Resolve the plan that featureEnabled()/limitFor() should actually gate against.
 *
 * Identical to planForUser()/planForOrg() EXCEPT that a past_due subscription
 * is downgraded to the configurable 'billing_past_due_downgrade_plan_key'
 * setting (default the 'free' plan) for the purposes of this check. This is
 * the ENTIRE implementation of the "past_due blocks creation of new gated
 * objects" rule: every current and future call site that gates a CREATE
 * action via featureEnabled()/limitFor() automatically inherits this
 * behaviour with no per-call-site code - only reads/existing-object access
 * (which never calls this function at all) are unaffected.
 *
 * @param int|null $userID The user's ID, or null.
 * @param int|null $orgID  The organisation's ID, or null. When provided,
 *                          resolves via planForOrg() instead of planForUser().
 *
 * @return array The plan to gate feature/limit checks against.
 *
 * @see planForUser(), planForOrg() - the raw (non-downgraded) resolution
 */
function effectivePlanForGating(?int $userID, ?int $orgID = null): array
{
    if ($orgID !== null) {
        $plan = planForOrg($orgID);
    } else {
        $plan = planForUser($userID);
    }

    if ($plan['subscriptionStatus'] !== 'past_due') {
        return $plan;
    }

    // 📌 past_due: resolve the downgrade plan (default 'free', admin-editable
    // via the 'billing_past_due_downgrade_plan_key' setting - config, not code).
    $downgradeKey = CUERCODE_PLAN_KEY_FREE;

    if (function_exists('getConfig')) {
        $configuredKey = getConfig('billing_past_due_downgrade_plan_key', CUERCODE_PLAN_KEY_FREE);
        if (is_string($configuredKey) && $configuredKey !== '') {
            $downgradeKey = $configuredKey;
        }
    }

    $downgradePlan = planByKey($downgradeKey);

    if ($downgradePlan === null) {
        // 📌 The configured downgrade plan does not exist - keep gating
        // against the (paid) plan rather than silently falling through to
        // the fail-safe/anonymous shape, which would be MORE restrictive
        // than intended for a misconfiguration, not less.
        return $plan;
    }

    // 📌 Preserve the ORIGINAL subscription bookkeeping so a caller can still
    // see this is a past_due subscription being downgraded, not a genuine
    // free-tier subject.
    $downgradePlan['subscriptionID']     = $plan['subscriptionID'];
    $downgradePlan['subscriptionStatus'] = $plan['subscriptionStatus'];

    return $downgradePlan;
}

// ==============================================================================
// 🚩 featureEnabled() - Generic boolean feature flag check
// ==============================================================================

/**
 * 🚩 Check whether a boolean feature flag is enabled for a user's plan.
 *
 * Purely a generic array lookup against the resolved plan's `features`
 * object - there is intentionally NO switch/if-chain per feature name here.
 * Adding a brand-new feature key to any plan row's featuresJSON makes it
 * immediately readable through this function with zero code change.
 *
 * @param string   $featureKey The feature key to check (e.g. 'webhooks').
 * @param int|null $userID     The user's ID, or null for an anonymous visitor.
 *
 * @return bool True if enabled on the user's EFFECTIVE plan, false otherwise
 *              (including when the key is absent from every plan - fail-closed).
 */
function featureEnabled(string $featureKey, ?int $userID = null): bool
{
    $plan = effectivePlanForGating($userID);

    if (!array_key_exists($featureKey, $plan['features'])) {
        return false;
    }

    return (bool) $plan['features'][$featureKey];
}

// ==============================================================================
// 🔢 limitFor() - Generic numeric limit lookup
// ==============================================================================

/**
 * 🔢 Look up a numeric limit for a user's plan.
 *
 * Purely a generic array lookup against the resolved plan's `limits` object -
 * -1 conventionally means unlimited (the caller must check for -1 before
 * treating the return value as a hard cap). Adding a brand-new limit key to
 * any plan row's limitsJSON makes it immediately readable through this
 * function with zero code change.
 *
 * @param string   $limitKey The limit key to check (e.g. 'bulk_batch_size').
 * @param int|null $userID   The user's ID, or null for an anonymous visitor.
 *
 * @return int The limit value (-1 = unlimited), or 0 if the key is absent
 *             from every plan (fail-closed - no allowance, not unlimited).
 */
function limitFor(string $limitKey, ?int $userID = null): int
{
    $plan = effectivePlanForGating($userID);

    if (!array_key_exists($limitKey, $plan['limits'])) {
        return 0;
    }

    return (int) $plan['limits'][$limitKey];
}

// ==============================================================================
// 🛑 requireFeatureOr402() - Structured denial payload for a gated feature
// ==============================================================================

/**
 * 🛑 Check a boolean feature and, if denied, build a structured 402 payload.
 *
 * Does NOT itself call errorResponse()/exit - it returns null when the
 * feature is allowed, or a structured array describing the denial when it is
 * not, leaving the caller free to decide how to surface it (errorResponse()
 * for an API handler, a rendered upgrade prompt for a UI page, or a unit
 * test asserting on the returned shape directly).
 *
 * @param string   $featureKey The feature key to require.
 * @param int|null $userID     The user's ID, or null for an anonymous visitor.
 *
 * @return array|null Null when allowed. Otherwise:
 *   [
 *       'httpCode'   => 402,
 *       'errorCode'  => 'FEATURE_NOT_AVAILABLE',
 *       'message'    => string,
 *       'feature'    => string,
 *       'upgradeUrl' => string,
 *   ]
 *
 * @example
 *   $denied = requireFeatureOr402('webhooks', $currentUserId);
 *   if ($denied !== null) {
 *       errorResponse($denied['message'], $denied['httpCode'], $denied['errorCode'], [
 *           'upgrade_url' => $denied['upgradeUrl'],
 *           'feature'     => $denied['feature'],
 *       ]);
 *   }
 */
function requireFeatureOr402(string $featureKey, ?int $userID = null): ?array
{
    if (featureEnabled($featureKey, $userID)) {
        return null;
    }

    return [
        'httpCode'   => 402,
        'errorCode'  => 'FEATURE_NOT_AVAILABLE',
        'message'    => 'This feature ("' . $featureKey . '") is not available on your current plan.',
        'feature'    => $featureKey,
        'upgradeUrl' => resolveUpgradeUrl(),
    ];
}

// ==============================================================================
// 🔗 resolveUpgradeUrl() - The admin-configurable "go upgrade" URL
// ==============================================================================

/**
 * 🔗 Resolve the URL to point a denied caller at to upgrade their plan.
 *
 * Reads the 'billing_upgrade_url' setting (default '/pricing') so the target
 * page can change (e.g. once a real pricing/plan-selection page ships) with
 * no code change.
 *
 * @return string The upgrade URL.
 */
function resolveUpgradeUrl(): string
{
    $upgradeUrl = '/pricing';

    if (function_exists('getConfig')) {
        $configuredUrl = getConfig('billing_upgrade_url', '/pricing');
        if (is_string($configuredUrl) && $configuredUrl !== '') {
            $upgradeUrl = $configuredUrl;
        }
    }

    return $upgradeUrl;
}

// ==============================================================================
// 🧭 resolveUserIdFromAuth() - Map a router $auth context to a gating userID
// ==============================================================================

/**
 * 🧭 Resolve the gating subject's userID from the API router's $auth array.
 *
 * The router's authenticateAPIRequest() result (see fn_api_auth.php) tells us
 * HOW a request authenticated, but not WHO is asking in gating terms:
 *   - 'csrf'      (session-based web UI call) -> the logged-in user IS the
 *     subject - resolved via currentUser() (fn_auth.php).
 *   - 'api_key'   (X-API-Key header)          -> the KEY's owning user
 *     (tblAPIKeys.userID) is the subject, not the key itself.
 *   - 'anonymous' (or anything unrecognised)   -> no subject (null) - the
 *     caller gates as the 'anonymous' plan.
 *
 * @param array $auth The $auth array produced by authenticateAPIRequest()
 *                     (or the router's default anonymous shape).
 *
 * @return int|null The resolved userID, or null for an anonymous subject.
 *
 * @see fn_api_auth.php - authenticateAPIRequest()
 * @see fn_auth.php     - currentUser()
 */
function resolveUserIdFromAuth(array $auth): ?int
{
    if (!isset($auth['method'])) {
        return null;
    }

    // 📌 Session-based web UI auth: the CSRF-authenticated caller IS the
    // logged-in user.
    if ($auth['method'] === 'csrf') {
        if (function_exists('currentUser')) {
            $user = currentUser();
            if ($user !== null && isset($user['userID'])) {
                return (int) $user['userID'];
            }
        }
        return null;
    }

    // 📌 API-key auth: resolve the key's OWNING user. A future org-owned key
    // (tblAPIKeys.orgID) would need a matching planForOrg() call site once
    // organisations actually issue their own keys - not built yet.
    if ($auth['method'] === 'api_key'
        && isset($auth['apiKeyId'])
        && $auth['apiKeyId'] !== null
        && function_exists('dbQuery')
    ) {
        try {
            $stmt = dbQuery(
                'SELECT `userID` FROM `tblAPIKeys` WHERE `apiKeyID` = ? LIMIT 1',
                'i',
                [(int) $auth['apiKeyId']]
            );
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (\Throwable $e) {
            return null;
        }

        if ($row !== null && $row['userID'] !== null) {
            return (int) $row['userID'];
        }

        return null;
    }

    // 📌 Anonymous, or an auth method this helper does not (yet) understand.
    return null;
}

// ==============================================================================
// 🔄 syncApiKeyLimitsForUser() - Push a plan's rate limits onto tblAPIKeys
// ==============================================================================

/**
 * 🔄 Sync a user's current plan's rate limits onto their active API keys.
 *
 * tblAPIKeys.rateLimitPerMin/PerDay are the columns checkRateLimit()
 * (fn_api_auth.php) actually enforces - they are NOT read live from
 * tblSubscriptionPlans on every request (that would mean an extra join on
 * the request's hot path). Instead, this function pushes the CURRENT plan's
 * 'api_rate_limit_per_min'/'api_rate_limit_per_day' limits onto every active
 * key the user owns whenever their plan changes (see
 * onSubscriptionPlanChanged() below) - so no new rate-limit CODE path is
 * ever needed, exactly per PROJECT_ROADMAP.md "Enforcement design" point 4.
 *
 * @param int $userID The user whose active API keys should be re-synced.
 *
 * @return bool True on success, false on failure (DB unavailable or query error).
 */
function syncApiKeyLimitsForUser(int $userID): bool
{
    if (!function_exists('dbQuery')) {
        return false;
    }

    $perMin = limitFor('api_rate_limit_per_min', $userID);
    $perDay = limitFor('api_rate_limit_per_day', $userID);

    // 📌 tblAPIKeys.rateLimitPerMin/PerDay are INT UNSIGNED columns with no
    // "unlimited" sentinel (unlike the -1 convention used in limitsJSON) -
    // clamp -1 (unlimited) to a very large-but-finite ceiling so the column
    // stays a valid unsigned value while remaining effectively unlimited for
    // any realistic traffic volume.
    if ($perMin < 0) {
        $perMin = 1000000;
    }
    if ($perDay < 0) {
        $perDay = 100000000;
    }

    try {
        $stmt = dbQuery(
            'UPDATE `tblAPIKeys` SET `rateLimitPerMin` = ?, `rateLimitPerDay` = ? '
            . 'WHERE `userID` = ? AND `isActive` = 1',
            'iii',
            [$perMin, $perDay, $userID]
        );
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError(
                'error',
                'API key rate-limit sync failed',
                'userID: ' . $userID . ' | ' . $e->getMessage()
            );
        }
        return false;
    }

    return true;
}

// ==============================================================================
// 📞 onSubscriptionPlanChanged() - STUB caller for the (deferred) plan-change hook
// ==============================================================================

/**
 * 📞 React to a subscription's plan/status changing (STUB - not yet called).
 *
 * 🚧 DEFERRED (Epic E4 payments stage): nothing in this codebase calls this
 * function yet. It is the hook the (owner-gated) PayPal Subscriptions
 * webhook listener (public_html/api/v1/webhooks/paypal/ - see DEV_NOTES.md
 * "Deferred: PayPal Subscriptions webhook") will call whenever a
 * subscription's planID/status changes (upgrade, downgrade, reactivation
 * after past_due recovery), and the future admin plan editor (Epic E3 admin
 * stage) will call for a manually-granted/comp subscription change.
 *
 * Implemented now (not just documented) so the sync mechanism itself is
 * tested and ready - only the CALLER is missing pending the payments
 * integration.
 *
 * @param int $subscriptionID The tblSubscriptions row whose plan/status changed.
 *
 * @return bool True on success, false if the subscription/user could not be
 *              resolved or the sync failed.
 */
function onSubscriptionPlanChanged(int $subscriptionID): bool
{
    if (!function_exists('dbQuery')) {
        return false;
    }

    try {
        $stmt = dbQuery(
            'SELECT `userID`, `orgID` FROM `tblSubscriptions` WHERE `subscriptionID` = ? LIMIT 1',
            'i',
            [$subscriptionID]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'onSubscriptionPlanChanged() lookup failed', $e->getMessage());
        }
        return false;
    }

    if ($row === null) {
        return false;
    }

    if ($row['userID'] !== null) {
        // 🗑️ A plan change invalidates any resolution already cached for
        // this subject within the current request/process.
        featuresCacheStore('clear', 'user:' . (int) $row['userID']);
        return syncApiKeyLimitsForUser((int) $row['userID']);
    }

    if ($row['orgID'] !== null) {
        featuresCacheStore('clear', 'org:' . (int) $row['orgID']);
        // 📌 Org-owned API keys are not populated yet (tblAPIKeys.orgID
        // exists in the schema but nothing sets it until organisations
        // actually issue their own keys) - nothing further to sync today.
        return true;
    }

    return true;
}
