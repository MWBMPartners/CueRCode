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

// ==============================================================================
// 💷 Flexible-pricing helpers (Epic E4 flexible-pricing delta, #70) - BUILT
//    BUT DISABLED, additive-only
// ==============================================================================
//
// Everything below is NEW surface added on top of the existing tier-gating
// engine above - not one line above this point was touched. Every helper
// here is FAIL-CLOSED and a complete NO-OP (matching this file's own
// featureEnabled()/limitFor() fail-closed philosophy) whenever:
//   (a) the matching `billing_price_points_enabled` / `billing_discounts_
//       enabled` / `billing_payg_enabled` kill-switch (seed_data.sql
//       `billing` group) is 0 (the seeded default), OR
//   (b) the database/config layer is unavailable (function_exists() guards,
//       exactly like every function above), OR
//   (c) the matching new table (tblPlanPricePoints/tblDiscounts+
//       tblDiscountRedemptions/tblCreditLedger - install.sql Tables 22-26)
//       is simply empty.
// None of the existing functions above (planByKey(), resolveActiveSubscription(),
// effectivePlanForGating(), featureEnabled(), limitFor(), ...) are called
// with different arguments or altered in any way by this section - the new
// helpers only ADD calls to them (resolveCreditLimitForSubject() below reuses
// limitFor()'s own user-subject path verbatim, and only duplicates its two-
// line fail-closed lookup for the org-subject case that limitFor() itself
// does not support).
//
// @see install.sql - Tables 22-26 (#70 schema delta)
// @see seed_data.sql - the three billing_* kill-switches, all seeded 0
// @see fn_pricing.php - listPublicPlansForDisplay() prefers tblPlanPricePoints
//      rows over the legacy priceMonthly/priceYearly columns when present
// @see Pricing_Strategy.md §5 - worked examples of every structure below
// ==============================================================================

/**
 * @var string Prefix for the per-subject-per-meter MySQL advisory lock name
 * used to serialise consumeCreditsOr402()'s balance/spend-cap check + ledger
 * insert (mirrors SECURITY.md F-023's `acquireSaveLimitLock()` pattern in
 * fn_api_qrcodes.php - see that function's docblock for the full TOCTOU
 * rationale, which applies identically here: N concurrent consumption calls
 * for the SAME subject+meterKey must not all read the same pre-consumption
 * balance/spend total and independently decide they can afford $qty). The
 * full lock name is this prefix concatenated with an md5() hash of
 * "<subjectType>:<subjectId>:<meterKey>" - hashed (rather than the raw
 * string, as CUERCODE_SAVE_LOCK_NAME_PREFIX does for a bare userID) purely so
 * the total lock name has a fixed, small length regardless of how long an
 * admin-chosen meterKey happens to be, comfortably under MySQL's
 * 64-character GET_LOCK() name limit.
 */
if (!defined('CUERCODE_CREDIT_LOCK_NAME_PREFIX')) {
    define('CUERCODE_CREDIT_LOCK_NAME_PREFIX', 'cuercode_credit:');
}

/**
 * @var int Seconds GET_LOCK() will wait for the per-subject-per-meter credit
 * lock before giving up (returning 0) - kept short, mirroring
 * CUERCODE_SAVE_LOCK_TIMEOUT_SECONDS, since the critical section it guards is
 * a handful of fast, indexed queries plus one INSERT.
 */
if (!defined('CUERCODE_CREDIT_LOCK_TIMEOUT_SECONDS')) {
    define('CUERCODE_CREDIT_LOCK_TIMEOUT_SECONDS', 5);
}

// ------------------------------------------------------------------------
// 💷 pricePointsForPlan() - Fetch a plan's active, currently-valid price points
// ------------------------------------------------------------------------

/**
 * 💷 Fetch every currently-valid, active price point for one plan.
 *
 * FAIL-CLOSED / NO-OP: returns `[]` immediately (no query at all) when the
 * `billing_price_points_enabled` kill-switch is off (the seeded default) or
 * `getConfig()`/`dbQuery()` are unavailable - identical posture to
 * `planByKey()`'s DB-unavailable fallback above, plus the additional
 * kill-switch gate this new subsystem requires. Cached per request under a
 * `pricepoints:<planID>` key, mirroring `planByKey()`'s `plan:<planKey>`
 * cache exactly (same store, same has/get/set op contract).
 *
 * "Currently-valid" filters `validFrom`/`validUntil` at the SQL level (NULL
 * on either side means no restriction on that side) so a launch-offer price
 * point that has not started yet, or has already expired, is silently
 * excluded - the caller never has to re-check those two columns itself.
 *
 * @param int $planID The `tblSubscriptionPlans.planID` to fetch price points for.
 *
 * @return array A list of price points, each shaped:
 *   [
 *       'pricePointID'    => int,
 *       'planID'          => int,
 *       'priceKey'        => string,
 *       'billingInterval' => string,  // 'monthly'|'yearly'|'lifetime'|'one_off'|'metered'
 *       'intervalCount'   => int,
 *       'amount'          => float,
 *       'currency'        => string,
 *       'grants'          => array,   // decoded grantsJSON, [] when NULL/malformed
 *       'validFrom'       => string|null,
 *       'validUntil'      => string|null,
 *       'isDefault'       => bool,
 *   ]
 *   Empty array when the kill-switch is off, the table is empty for this
 *   plan, or the DB is unavailable.
 *
 * @see https://www.php.net/manual/en/function.json-decode.php
 */
function pricePointsForPlan(int $planID): array
{
    if ($planID <= 0) {
        return [];
    }

    $cacheKey = 'pricepoints:' . $planID;

    if (featuresCacheStore('has', $cacheKey)) {
        return featuresCacheStore('get', $cacheKey);
    }

    // 🚦 Master kill-switch - inert regardless of what rows exist until an
    // admin explicitly opts in (mirrors email_enabled/sso_enabled).
    if (!function_exists('getConfig') || !((bool) getConfig('billing_price_points_enabled', false))) {
        featuresCacheStore('set', $cacheKey, []);
        return [];
    }

    if (!function_exists('dbQuery')) {
        return [];
    }

    try {
        $stmt = dbQuery(
            'SELECT `pricePointID`, `planID`, `priceKey`, `billingInterval`, `intervalCount`, '
            . '`amount`, `currency`, `grantsJSON`, `validFrom`, `validUntil`, `isDefault` '
            . 'FROM `tblPlanPricePoints` '
            . 'WHERE `planID` = ? AND `isActive` = 1 '
            . 'AND (`validFrom` IS NULL OR `validFrom` <= NOW()) '
            . 'AND (`validUntil` IS NULL OR `validUntil` >= NOW()) '
            . 'ORDER BY `sortOrder` ASC, `pricePointID` ASC',
            'i',
            [$planID]
        );

        $result = $stmt->get_result();
        $pricePoints = [];

        while (($row = $result->fetch_assoc()) !== null) {
            // 🔄 Same fail-closed JSON-decode convention as planByKey()'s
            // featuresJSON/limitsJSON handling - malformed/absent JSON
            // decodes to an empty array, never a warning or null.
            $grantsDecoded = json_decode((string) $row['grantsJSON'], true);
            if (!is_array($grantsDecoded)) {
                $grantsDecoded = [];
            }

            $pricePoints[] = [
                'pricePointID'    => (int) $row['pricePointID'],
                'planID'          => (int) $row['planID'],
                'priceKey'        => (string) $row['priceKey'],
                'billingInterval' => (string) $row['billingInterval'],
                'intervalCount'   => (int) $row['intervalCount'],
                'amount'          => (float) $row['amount'],
                'currency'        => (string) $row['currency'],
                'grants'          => $grantsDecoded,
                'validFrom'       => $row['validFrom'],
                'validUntil'      => $row['validUntil'],
                'isDefault'       => ((int) $row['isDefault']) === 1,
            ];
        }

        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Plan price-point lookup failed', 'planID: ' . $planID . ' | ' . $e->getMessage());
        }
        return [];
    }

    featuresCacheStore('set', $cacheKey, $pricePoints);

    return $pricePoints;
}

// ------------------------------------------------------------------------
// 🎟️ resolveDiscountForCheckout() - Resolve the applicable discount, if any
// ------------------------------------------------------------------------

/**
 * 🎟️ Resolve the single discount that applies to a checkout attempt, if any.
 *
 * FAIL-CLOSED / NO-OP: returns `null` (no discount applied) immediately when
 * the `billing_discounts_enabled` kill-switch is off, `dbQuery()` is
 * unavailable, no matching row exists, the matching row's scope
 * (appliesPlanID/appliesPriceKey) does not cover this checkout, its
 * redemption cap is already reached, or (when a $userID is supplied) that
 * user has already redeemed it (tblDiscountRedemptions'
 * `UNIQUE(discountID,userID)` one-per-user default).
 *
 * Two lookup modes, selected purely by whether $code is supplied:
 *   - $code given   -> looks up that EXACT coupon code (`code = ?`), then
 *     verifies it scopes to $planID/$priceKey.
 *   - $code null/''  -> looks up an AUTOMATIC/sitewide discount (`code IS
 *     NULL`), scoped to $planID (or sitewide, appliesPlanID IS NULL),
 *     preferring the more specific (plan-scoped) match first.
 * Both branches are separate, fully-static prepared statements (no shared
 * dynamic SQL fragment) - the same "no identifier interpolation, only VALUE
 * placeholders" discipline `resolveActiveSubscription()` documents above.
 *
 * @param string|null $code    A caller-supplied coupon code, or null/'' to
 *                              look for an automatic/sitewide discount instead.
 * @param int         $planID  The `tblSubscriptionPlans.planID` being checked out.
 * @param string|null $priceKey The `tblPlanPricePoints.priceKey` being
 *                                purchased, or null if not price-point-scoped.
 * @param int|null    $userID  The redeeming user's ID, or null to skip the
 *                              one-per-user redemption check (e.g. a pre-auth
 *                              price preview).
 *
 * @return array|null The resolved discount, or null when none applies. Shape:
 *   [
 *       'discountID'      => int,
 *       'code'            => string|null,
 *       'name'            => string,
 *       'discountType'    => string,   // 'percent'|'fixed'
 *       'value'           => float,
 *       'appliesPlanID'   => int|null,
 *       'appliesPriceKey' => string|null,
 *       'durationType'    => string,   // 'once'|'repeating'|'forever'
 *       'durationMonths'  => int|null,
 *   ]
 */
function resolveDiscountForCheckout(?string $code, int $planID, ?string $priceKey, ?int $userID): ?array
{
    // 🚦 Master kill-switch - inert regardless of what rows exist.
    if (!function_exists('getConfig') || !((bool) getConfig('billing_discounts_enabled', false))) {
        return null;
    }

    if (!function_exists('dbQuery')) {
        return null;
    }

    $hasCode = ($code !== null && $code !== '');

    try {
        if ($hasCode) {
            // 🎟️ Explicit coupon-code lookup.
            $stmt = dbQuery(
                'SELECT `discountID`, `code`, `name`, `discountType`, `value`, `appliesPlanID`, '
                . '`appliesPriceKey`, `durationType`, `durationMonths`, `maxRedemptions`, `redemptionCount` '
                . 'FROM `tblDiscounts` '
                . 'WHERE `code` = ? AND `isActive` = 1 '
                . 'AND (`validFrom` IS NULL OR `validFrom` <= NOW()) '
                . 'AND (`validUntil` IS NULL OR `validUntil` >= NOW()) '
                . 'LIMIT 1',
                's',
                [$code]
            );
        } else {
            // 🤖 Automatic/sitewide discount lookup - most-specific match
            // first (a plan-scoped row before a fully-sitewide one).
            $stmt = dbQuery(
                'SELECT `discountID`, `code`, `name`, `discountType`, `value`, `appliesPlanID`, '
                . '`appliesPriceKey`, `durationType`, `durationMonths`, `maxRedemptions`, `redemptionCount` '
                . 'FROM `tblDiscounts` '
                . 'WHERE `code` IS NULL AND `isActive` = 1 '
                . 'AND (`appliesPlanID` IS NULL OR `appliesPlanID` = ?) '
                . 'AND (`validFrom` IS NULL OR `validFrom` <= NOW()) '
                . 'AND (`validUntil` IS NULL OR `validUntil` >= NOW()) '
                . 'ORDER BY (`appliesPlanID` IS NOT NULL) DESC, `discountID` ASC '
                . 'LIMIT 1',
                'i',
                [$planID]
            );
        }

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Discount lookup failed', 'planID: ' . $planID . ' | ' . $e->getMessage());
        }
        return null;
    }

    if ($row === null) {
        return null;
    }

    // 🔒 Scope check - appliesPlanID (if set) must match this checkout's
    // plan; appliesPriceKey (if set) must match the requested priceKey. The
    // automatic-lookup branch above already filtered appliesPlanID at the
    // SQL level, but a CODE-supplied row's scope is not yet verified, and
    // appliesPriceKey is never filtered at the SQL level by either branch.
    if ($row['appliesPlanID'] !== null && (int) $row['appliesPlanID'] !== $planID) {
        return null;
    }
    if ($row['appliesPriceKey'] !== null && $priceKey !== null && (string) $row['appliesPriceKey'] !== $priceKey) {
        return null;
    }

    // 🎯 Sitewide redemption cap.
    if ($row['maxRedemptions'] !== null && (int) $row['redemptionCount'] >= (int) $row['maxRedemptions']) {
        return null;
    }

    // 👤 One-per-user check (tblDiscountRedemptions.UNIQUE(discountID,userID)) -
    // skipped entirely when no $userID was supplied (a pre-auth price preview).
    if ($userID !== null) {
        try {
            $redemptionStmt = dbQuery(
                'SELECT `redemptionID` FROM `tblDiscountRedemptions` WHERE `discountID` = ? AND `userID` = ? LIMIT 1',
                'ii',
                [(int) $row['discountID'], $userID]
            );
            $redemptionRow = $redemptionStmt->get_result()->fetch_assoc();
            $redemptionStmt->close();
        } catch (\Throwable $e) {
            if (function_exists('logError')) {
                logError('error', 'Discount redemption check failed', 'discountID: ' . $row['discountID'] . ' | ' . $e->getMessage());
            }
            // 🛡️ FAIL CLOSED - a redemption-check failure must never grant a
            // discount it could not actually verify was still available.
            return null;
        }

        if ($redemptionRow !== null) {
            // 📌 Already redeemed by this user - not available again.
            return null;
        }
    }

    return [
        'discountID'      => (int) $row['discountID'],
        'code'            => $row['code'] !== null ? (string) $row['code'] : null,
        'name'            => (string) $row['name'],
        'discountType'    => (string) $row['discountType'],
        'value'           => (float) $row['value'],
        'appliesPlanID'   => $row['appliesPlanID'] !== null ? (int) $row['appliesPlanID'] : null,
        'appliesPriceKey' => $row['appliesPriceKey'] !== null ? (string) $row['appliesPriceKey'] : null,
        'durationType'    => (string) $row['durationType'],
        'durationMonths'  => $row['durationMonths'] !== null ? (int) $row['durationMonths'] : null,
    ];
}

// ------------------------------------------------------------------------
// 💰 creditBalanceFor() - PAYG credit balance lookup (SUM(delta))
// ------------------------------------------------------------------------

/**
 * 💰 Resolve a subject's current PAYG credit balance for one meter key.
 *
 * The balance is ALWAYS derived, never stored: `SUM(delta)` across every
 * `tblCreditLedger` row matching this subject+meterKey (see that table's
 * header comment in install.sql for the append-only-ledger rationale).
 * Cached per request under a `credit:<type>:<id>:<meterKey>` key, mirroring
 * `planByKey()`'s cache exactly.
 *
 * FAIL-CLOSED / NO-OP: returns `0` when the `billing_payg_enabled`
 * kill-switch is off, `dbQuery()` is unavailable, the arguments are
 * malformed, or the ledger has no rows for this subject+meterKey - `0` is
 * the correct "no credits" answer in every one of those cases, so callers
 * never need to distinguish "genuinely zero" from "subsystem unavailable".
 *
 * Written as two separate, fully-static prepared statements (one per
 * subject type) rather than interpolating a column name - the same
 * discipline `resolveActiveSubscription()` documents above.
 *
 * @param string $subjectType Either 'user' or 'org' - any other value fails closed to 0.
 * @param int    $subjectId   The `tblUsers.userID` or `tblOrganisations.orgID` to look up.
 * @param string $meterKey    The credit meter to sum (e.g. 'qr_generations').
 *
 * @return int The current balance (may be negative only if credits were
 *             consumed faster than granted, which consumeCreditsOr402()'s own
 *             balance check is designed to prevent under normal operation).
 */
function creditBalanceFor(string $subjectType, int $subjectId, string $meterKey): int
{
    if (!in_array($subjectType, ['user', 'org'], true) || $subjectId <= 0 || $meterKey === '') {
        return 0;
    }

    $cacheKey = 'credit:' . $subjectType . ':' . $subjectId . ':' . $meterKey;

    if (featuresCacheStore('has', $cacheKey)) {
        return featuresCacheStore('get', $cacheKey);
    }

    // 🚦 Master kill-switch - a subject's ledger history becomes invisible
    // (reads as a flat 0 balance) the moment PAYG is turned off, exactly
    // like every other billing_* switch in this file.
    if (!function_exists('getConfig') || !((bool) getConfig('billing_payg_enabled', false))) {
        featuresCacheStore('set', $cacheKey, 0);
        return 0;
    }

    if (!function_exists('dbQuery')) {
        return 0;
    }

    try {
        if ($subjectType === 'user') {
            $stmt = dbQuery(
                'SELECT COALESCE(SUM(`delta`), 0) AS `balance` FROM `tblCreditLedger` '
                . 'WHERE `userID` = ? AND `meterKey` = ?',
                'is',
                [$subjectId, $meterKey]
            );
        } else {
            $stmt = dbQuery(
                'SELECT COALESCE(SUM(`delta`), 0) AS `balance` FROM `tblCreditLedger` '
                . 'WHERE `orgID` = ? AND `meterKey` = ?',
                'is',
                [$subjectId, $meterKey]
            );
        }

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError(
                'error',
                'Credit balance lookup failed',
                $subjectType . 'ID: ' . $subjectId . ' | meterKey: ' . $meterKey . ' | ' . $e->getMessage()
            );
        }
        return 0;
    }

    $balance = ($row !== null && isset($row['balance'])) ? (int) $row['balance'] : 0;

    featuresCacheStore('set', $cacheKey, $balance);

    return $balance;
}

// ------------------------------------------------------------------------
// 🗓️ creditSpentThisMonth() - Month-to-date consumption for the spend cap
// ------------------------------------------------------------------------

/**
 * 🗓️ Sum this calendar month's CONSUMPTION (negative deltas) for a subject+meterKey.
 *
 * Backs `consumeCreditsOr402()`'s `credit_spend_cap_monthly` enforcement -
 * kept as its own small function (rather than inlined) so it is independently
 * testable and so `creditBalanceFor()` above stays a pure "all-time balance"
 * lookup, not conflated with a calendar-window one. NOT request-cached (unlike
 * `creditBalanceFor()`) because it is only ever called from inside
 * `consumeCreditsOr402()`'s advisory-lock-protected critical section, where a
 * stale cached value would be exactly the kind of TOCTOU risk that lock
 * exists to prevent.
 *
 * @param string $subjectType Either 'user' or 'org' - any other value fails closed to 0.
 * @param int    $subjectId   The `tblUsers.userID` or `tblOrganisations.orgID` to look up.
 * @param string $meterKey    The credit meter to sum (e.g. 'qr_generations').
 *
 * @return int Total units consumed so far this calendar month (always >= 0).
 */
function creditSpentThisMonth(string $subjectType, int $subjectId, string $meterKey): int
{
    if (!in_array($subjectType, ['user', 'org'], true) || $subjectId <= 0 || $meterKey === '') {
        return 0;
    }

    if (!function_exists('dbQuery')) {
        return 0;
    }

    try {
        if ($subjectType === 'user') {
            $stmt = dbQuery(
                'SELECT COALESCE(SUM(-`delta`), 0) AS `spent` FROM `tblCreditLedger` '
                . "WHERE `userID` = ? AND `meterKey` = ? AND `delta` < 0 AND `createdAt` >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')",
                'is',
                [$subjectId, $meterKey]
            );
        } else {
            $stmt = dbQuery(
                'SELECT COALESCE(SUM(-`delta`), 0) AS `spent` FROM `tblCreditLedger` '
                . "WHERE `orgID` = ? AND `meterKey` = ? AND `delta` < 0 AND `createdAt` >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')",
                'is',
                [$subjectId, $meterKey]
            );
        }

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError(
                'error',
                'Credit month-to-date spend lookup failed',
                $subjectType . 'ID: ' . $subjectId . ' | meterKey: ' . $meterKey . ' | ' . $e->getMessage()
            );
        }
        return 0;
    }

    return ($row !== null && isset($row['spent'])) ? (int) $row['spent'] : 0;
}

// ------------------------------------------------------------------------
// 🔢 resolveCreditLimitForSubject() - limitFor() for a user OR an org subject
// ------------------------------------------------------------------------

/**
 * 🔢 Look up a numeric plan limit for either a user or an org subject.
 *
 * `limitFor()` above only accepts a userID - this file's public gating
 * primitive is NOT being changed to add an org parameter (out of scope for
 * #70 and not requested). For the 'user' case this function is a pure
 * pass-through to the REAL `limitFor()` (same cache, same result, zero
 * duplicated logic). For the 'org' case it duplicates ONLY `limitFor()`'s own
 * two-line fail-closed array lookup against `effectivePlanForGating(null,
 * $subjectId)` - not a new gating code path, just the org-resolution half
 * that already exists in `effectivePlanForGating()` above, finished off with
 * the identical fail-closed convention.
 *
 * @param string $limitKey    The limitsJSON key to look up (e.g. 'credit_spend_cap_monthly').
 * @param string $subjectType Either 'user' or 'org'.
 * @param int    $subjectId   The subject's userID or orgID.
 *
 * @return int The limit value (-1 = unlimited), or 0 if absent/unrecognised
 *             (the same fail-closed default `limitFor()` documents).
 */
function resolveCreditLimitForSubject(string $limitKey, string $subjectType, int $subjectId): int
{
    if ($subjectType === 'user') {
        return limitFor($limitKey, $subjectId);
    }

    if ($subjectType !== 'org') {
        return 0;
    }

    $plan = effectivePlanForGating(null, $subjectId);

    if (!array_key_exists($limitKey, $plan['limits'])) {
        return 0;
    }

    return (int) $plan['limits'][$limitKey];
}

// ------------------------------------------------------------------------
// 🧮 evaluateCreditSpend() - PURE PAYG-with-a-cap decision (no I/O)
// ------------------------------------------------------------------------

/**
 * 🧮 PURE decision: given already-known balance/cap/spend figures, decide
 * whether consuming $qty credits is ALLOWED.
 *
 * No database access, no config lookups - unit-testable in complete
 * isolation. This is deliberately the ONLY place the PAYG-with-a-cap business
 * rule is expressed, so it can be proven correct from DATA ALONE (the four
 * plain integers below), exactly the #70 acceptance criterion: feed this
 * function fixture numbers derived from a hypothetical plan's limitsJSON +
 * ledger history and assert the derived allow/deny decision, with zero
 * database involved.
 *
 * @param int $qty             Units requested for this single consumption.
 * @param int $currentBalance  The subject's current balance for this meterKey (creditBalanceFor()).
 * @param int $spendCapMonthly limitFor()-style value for 'credit_spend_cap_monthly' -
 *                              -1 = unlimited, 0 = PAYG not permitted at all on this plan
 *                              (the fail-closed default for a plan that has never opted in).
 * @param int $spentThisMonth  Units already consumed this calendar month (creditSpentThisMonth()).
 *
 * @return array ['allowed' => bool, 'reason' => string|null]. `reason` is one
 *   of 'invalid_quantity' | 'not_permitted' | 'insufficient_balance' |
 *   'spend_cap_exceeded' when denied, or null when allowed.
 */
function evaluateCreditSpend(int $qty, int $currentBalance, int $spendCapMonthly, int $spentThisMonth): array
{
    if ($qty <= 0) {
        return ['allowed' => false, 'reason' => 'invalid_quantity'];
    }

    // 🚫 Fail-closed default: a plan that has never had
    // `credit_spend_cap_monthly` seeded into its limitsJSON resolves to 0 via
    // limitFor()'s own fail-closed convention - here that means "PAYG is not
    // permitted on this plan at all" (distinct from "no spend budget LEFT
    // this month", the spend_cap_exceeded case below).
    if ($spendCapMonthly === 0) {
        return ['allowed' => false, 'reason' => 'not_permitted'];
    }

    if ($currentBalance < $qty) {
        return ['allowed' => false, 'reason' => 'insufficient_balance'];
    }

    // ♾️ -1 = unlimited (the limitFor() convention) - no monthly ceiling to check.
    if ($spendCapMonthly !== -1 && ($spentThisMonth + $qty) > $spendCapMonthly) {
        return ['allowed' => false, 'reason' => 'spend_cap_exceeded'];
    }

    return ['allowed' => true, 'reason' => null];
}

// ------------------------------------------------------------------------
// 📝 creditDenialMessage() - PURE reason-code -> human message mapping
// ------------------------------------------------------------------------

/**
 * 📝 Map an `evaluateCreditSpend()`/`consumeCreditsOr402()` denial reason to
 * a human-readable message.
 *
 * @param string $reason   One of evaluateCreditSpend()'s reason codes, or
 *                          'not_configured'/'service_error' for
 *                          consumeCreditsOr402()'s own gate failures.
 * @param string $meterKey The meter key the denial concerns (named in the message).
 *
 * @return string A human-readable denial message.
 */
function creditDenialMessage(string $reason, string $meterKey): string
{
    switch ($reason) {
        case 'invalid_quantity':
            return 'The requested credit quantity is invalid.';

        case 'not_permitted':
            return 'Pay-as-you-go credits ("' . $meterKey . '") are not available on your current plan.';

        case 'insufficient_balance':
            return 'Insufficient credit balance for "' . $meterKey . '".';

        case 'spend_cap_exceeded':
            return 'This would exceed your monthly credit spend cap for "' . $meterKey . '".';

        case 'service_error':
            return 'The credit service encountered an error. Please try again.';

        default:
            return 'Credit consumption denied.';
    }
}

// ------------------------------------------------------------------------
// 🔒 acquireCreditLock() / releaseCreditLock() - Per-subject-per-meter
//    MySQL advisory lock (mirrors SECURITY.md F-023's pattern)
// ------------------------------------------------------------------------

/**
 * 🔒 Acquire the advisory lock guarding one subject+meterKey's credit
 * consumption critical section.
 *
 * Identical mechanics to `fn_api_qrcodes.php`'s `acquireSaveLimitLock()`
 * (SECURITY.md F-023) - see that function's docblock for the full
 * phantom-row/TOCTOU rationale, which applies unchanged here.
 * `GET_LOCK()`/`RELEASE_LOCK()` are SESSION-scoped, and `dbQuery()` ->
 * `getDB()` returns the same cached `mysqli` connection for the lifetime of
 * one PHP request, so the lock acquired here is guaranteed to be released by
 * `releaseCreditLock()` on the SAME connection later in the SAME request.
 *
 * @param string $lockKey The full lock name (CUERCODE_CREDIT_LOCK_NAME_PREFIX + a hash).
 *
 * @return bool True if acquired within CUERCODE_CREDIT_LOCK_TIMEOUT_SECONDS;
 *              false on timeout or any DB error - FAIL SAFE: the caller must
 *              NOT proceed unserialised when this returns false.
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/locking-functions.html#function_get-lock
 */
function acquireCreditLock(string $lockKey): bool
{
    if (!function_exists('dbQuery')) {
        return false;
    }

    try {
        $lockStmt = dbQuery(
            'SELECT GET_LOCK(?, ?) AS `lockResult`',
            'si',
            [$lockKey, CUERCODE_CREDIT_LOCK_TIMEOUT_SECONDS]
        );
        $lockRow = $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Credit advisory lock acquisition failed', 'lockKey: ' . $lockKey . ' | ' . $e->getMessage());
        }
        return false;
    }

    // 📌 GET_LOCK() returns 1 (acquired), 0 (timeout), or NULL (error) -
    // ANYTHING other than a genuine 1 is treated as "not acquired."
    return $lockRow !== null && isset($lockRow['lockResult']) && (int) $lockRow['lockResult'] === 1;
}

/**
 * 🔓 Release the advisory lock acquired by acquireCreditLock().
 *
 * Best-effort by design, exactly like `releaseSaveLimitLock()` - a failure to
 * release is logged but never thrown, and the lock is connection-scoped, so
 * MySQL releases it automatically at the end of this PHP request regardless.
 *
 * @param string $lockKey The SAME lock name passed to acquireCreditLock().
 *
 * @return void
 */
function releaseCreditLock(string $lockKey): void
{
    if (!function_exists('dbQuery')) {
        return;
    }

    try {
        $releaseStmt = dbQuery('SELECT RELEASE_LOCK(?) AS `releaseResult`', 's', [$lockKey]);
        $releaseStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('warning', 'Credit advisory lock release failed', 'lockKey: ' . $lockKey . ' | ' . $e->getMessage());
        }
    }
}

// ------------------------------------------------------------------------
// 💸 consumeCreditsOr402() - Check + atomically consume PAYG credits
// ------------------------------------------------------------------------

/**
 * 💸 Attempt to consume `$qty` PAYG credits for a subject+meterKey, or deny.
 *
 * Mirrors `requireFeatureOr402()`'s contract exactly: returns `null` when the
 * consumption SUCCEEDED (a ledger row was written), or a structured denial
 * array when it did not - the caller decides how to surface that (an
 * `errorResponse()` call in an API handler, or a unit-test assertion on the
 * returned shape directly).
 *
 * FAIL-CLOSED / NO-OP at every gate, in order:
 *   1. Malformed request shape (empty meterKey, qty <= 0, an unrecognised
 *      subjectType, or subjectId <= 0)      -> denied, CREDIT_INVALID_REQUEST.
 *   2. `billing_payg_enabled` = 0 (the
 *      seeded default - kill-switch off)     -> denied, CREDIT_PAYG_DISABLED.
 *   3. `dbQuery()` unavailable               -> denied, CREDIT_UNAVAILABLE.
 *   4. The advisory lock cannot be acquired
 *      (another consumption call for this
 *      SAME subject+meterKey is mid-flight)  -> denied, CREDIT_LOCK_BUSY (429).
 *   5. `evaluateCreditSpend()`'s pure decision (not_permitted /
 *      insufficient_balance / spend_cap_exceeded) -> denied, matching errorCode.
 * Only once ALL five gates pass does this function INSERT the negative-delta
 * ledger row (Table 26 tblCreditLedger) and return null.
 *
 * Concurrency posture - identical TOCTOU closure to SECURITY.md F-023: the
 * balance/spend-cap CHECK and the ledger INSERT happen inside the SAME
 * `acquireCreditLock()`/`releaseCreditLock()`-guarded critical section, so N
 * concurrent consumption calls for the SAME subject+meterKey cannot each read
 * a stale pre-consumption balance and all independently decide they can
 * afford `$qty`.
 *
 * @param string   $meterKey      The credit meter to consume (e.g. 'qr_generations').
 * @param int      $qty           How many units to consume (must be > 0).
 * @param string   $subjectType   Either 'user' or 'org'.
 * @param int      $subjectId     The subject's userID or orgID.
 * @param string   $reason        Free-text ledger audit reason (default 'consume').
 * @param int|null $refPurchaseID Optional FK tblOneOffPurchases (NULL for a
 *                                 plain consumption row).
 * @param int|null $refQrID       Optional FK tblQRCodes - the QR-related act
 *                                 this consumption paid for (NULL if not applicable).
 *
 * @return array|null Null when the consumption succeeded. Otherwise:
 *   [
 *       'httpCode'   => int,     // 402 (denied) or 429 (lock busy, retry)
 *       'errorCode'  => string,  // e.g. 'CREDIT_INSUFFICIENT_BALANCE'
 *       'message'    => string,
 *       'meterKey'   => string,
 *       'upgradeUrl' => string,  // present on every denial except the
 *                                 // request-shape/lock-busy/service-error ones
 *   ]
 */
function consumeCreditsOr402(
    string $meterKey,
    int $qty,
    string $subjectType,
    int $subjectId,
    string $reason = 'consume',
    ?int $refPurchaseID = null,
    ?int $refQrID = null
): ?array {
    // 🧹 Gate 1: request-shape validation.
    if ($meterKey === '' || $qty <= 0 || !in_array($subjectType, ['user', 'org'], true) || $subjectId <= 0) {
        return [
            'httpCode'  => 402,
            'errorCode' => 'CREDIT_INVALID_REQUEST',
            'message'   => 'Invalid credit consumption request.',
            'meterKey'  => $meterKey,
        ];
    }

    // 🚦 Gate 2: master kill-switch - PAYG stays entirely inert until an
    // admin turns it on, regardless of any rows already in tblCreditLedger.
    if (!function_exists('getConfig') || !((bool) getConfig('billing_payg_enabled', false))) {
        return [
            'httpCode'   => 402,
            'errorCode'  => 'CREDIT_PAYG_DISABLED',
            'message'    => 'Pay-as-you-go credits are not available.',
            'meterKey'   => $meterKey,
            'upgradeUrl' => resolveUpgradeUrl(),
        ];
    }

    // 🚦 Gate 3: database availability.
    if (!function_exists('dbQuery')) {
        return [
            'httpCode'  => 402,
            'errorCode' => 'CREDIT_UNAVAILABLE',
            'message'   => 'The credit service is temporarily unavailable.',
            'meterKey'  => $meterKey,
        ];
    }

    // 🔢 Resolve the plan-driven monthly spend cap BEFORE acquiring the lock
    // - a read-only, already-cached-by-limitFor() lookup, no need to hold the
    // lock for it.
    $spendCapMonthly = resolveCreditLimitForSubject('credit_spend_cap_monthly', $subjectType, $subjectId);

    // 🔒 Gate 4: per-subject-per-meter advisory lock (SECURITY.md F-023 pattern).
    $lockKey      = CUERCODE_CREDIT_LOCK_NAME_PREFIX . md5($subjectType . ':' . $subjectId . ':' . $meterKey);
    $lockAcquired = acquireCreditLock($lockKey);

    if (!$lockAcquired) {
        return [
            'httpCode'  => 429,
            'errorCode' => 'CREDIT_LOCK_BUSY',
            'message'   => 'The credit service is busy processing another request for this account. Please try again shortly.',
            'meterKey'  => $meterKey,
        ];
    }

    // 📌 Populated only inside the try, consumed only after the lock is
    // released in `finally` - mirrors fn_api_qrcodes.php's handleSaveQRCode()
    // $outcome pattern (SECURITY.md F-023).
    $denialReason = null;

    try {
        $currentBalance = creditBalanceFor($subjectType, $subjectId, $meterKey);
        $spentThisMonth = creditSpentThisMonth($subjectType, $subjectId, $meterKey);

        $decision = evaluateCreditSpend($qty, $currentBalance, $spendCapMonthly, $spentThisMonth);

        if (!$decision['allowed']) {
            $denialReason = $decision['reason'];
        } else {
            // ✍️ Append-only: INSERT a negative-delta consumption row, NEVER
            // an UPDATE/DELETE against an existing row (install.sql Table 26).
            if ($subjectType === 'user') {
                $insertStmt = dbQuery(
                    'INSERT INTO `tblCreditLedger` (`userID`, `meterKey`, `delta`, `reason`, `refPurchaseID`, `refQrID`) '
                    . 'VALUES (?, ?, ?, ?, ?, ?)',
                    'isisii',
                    [$subjectId, $meterKey, -$qty, $reason, $refPurchaseID, $refQrID]
                );
            } else {
                $insertStmt = dbQuery(
                    'INSERT INTO `tblCreditLedger` (`orgID`, `meterKey`, `delta`, `reason`, `refPurchaseID`, `refQrID`) '
                    . 'VALUES (?, ?, ?, ?, ?, ?)',
                    'isisii',
                    [$subjectId, $meterKey, -$qty, $reason, $refPurchaseID, $refQrID]
                );
            }
            $insertStmt->close();
        }
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError(
                'error',
                'Credit consumption failed',
                $subjectType . 'ID: ' . $subjectId . ' | meterKey: ' . $meterKey . ' | ' . $e->getMessage()
            );
        }
        $denialReason = 'service_error';
    } finally {
        releaseCreditLock($lockKey);
    }

    if ($denialReason === null) {
        // ✅ Consumed successfully.
        return null;
    }

    // 📌 Fully LITERAL 'errorCode' => 'X' per denial reason - deliberately
    // NOT built via a string-concatenation/strtoupper() prefix trick.
    // SECURITY.md/#58's error-code catalogue drift guard
    // (tests/test_error_catalogue.php) statically scans fn_features.php for
    // the EXACT `'errorCode' => 'LITERAL_CODE'` shape to keep openapi.json's
    // ErrorResponse.x-error-codes catalogue honest - a concatenated value is
    // invisible to (and actively confuses) that scanner, so every code this
    // function can return is spelled out here as its own complete literal.
    return match ($denialReason) {
        'not_permitted' => [
            'httpCode'   => 402,
            'errorCode'  => 'CREDIT_NOT_PERMITTED',
            'message'    => creditDenialMessage($denialReason, $meterKey),
            'meterKey'   => $meterKey,
            'upgradeUrl' => resolveUpgradeUrl(),
        ],
        'insufficient_balance' => [
            'httpCode'   => 402,
            'errorCode'  => 'CREDIT_INSUFFICIENT_BALANCE',
            'message'    => creditDenialMessage($denialReason, $meterKey),
            'meterKey'   => $meterKey,
            'upgradeUrl' => resolveUpgradeUrl(),
        ],
        'spend_cap_exceeded' => [
            'httpCode'   => 402,
            'errorCode'  => 'CREDIT_SPEND_CAP_EXCEEDED',
            'message'    => creditDenialMessage($denialReason, $meterKey),
            'meterKey'   => $meterKey,
            'upgradeUrl' => resolveUpgradeUrl(),
        ],
        // 📌 Covers 'service_error' (the catch{} branch) AND, defensively,
        // any future evaluateCreditSpend() reason this switch does not yet
        // know about - never an uncaught/unmapped denial reason.
        default => [
            'httpCode'   => 402,
            'errorCode'  => 'CREDIT_SERVICE_ERROR',
            'message'    => creditDenialMessage('service_error', $meterKey),
            'meterKey'   => $meterKey,
            'upgradeUrl' => resolveUpgradeUrl(),
        ],
    };
}
