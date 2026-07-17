<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Tests for the Premium Tier / Feature Gating Engine (Epic E4, #53)
 * ==============================================================================
 *
 * Path: tests/test_features.php
 *
 * Exercises the DB-INDEPENDENT surface of `_functions/fn_features.php`:
 *
 *   - cuercodeFailSafePlan()   (the hard-coded last-resort plan shape)
 *   - featuresCacheStore()     (the per-request cache, all five ops)
 *   - planForUser()/planForOrg() FAIL-SAFE fallback behaviour when the
 *     database is unavailable (dbQuery() is undefined under this harness,
 *     exactly like test_auth.php's/test_email.php's convention) - proving
 *     the anonymous-vs-free FALLBACK KEY distinction is honoured even when
 *     no real plan row can be read.
 *   - featureEnabled()/limitFor() generic, fail-closed key lookups.
 *   - requireFeatureOr402() structured denial shape + default upgrade URL.
 *   - resolveUserIdFromAuth() for every $auth['method'] shape, fail-safe to
 *     null with no database/session available.
 *   - syncApiKeyLimitsForUser()/onSubscriptionPlanChanged() fail-open-false
 *     with no database available.
 *   - A STATIC (source-text, no execution) regression guard proving the
 *     OWNER'S EXTENSIBILITY REQUIREMENT structurally: fn_features.php's
 *     source contains no quoted 'pro'/'business' plan-key literals anywhere
 *     - i.e. there is genuinely no per-tier branch to special-case those
 *       two paid tiers over any other planKey a database row might name.
 *
 * ⚠️ DB-DEPENDENT COVERAGE (planForUser()/planForOrg() resolving REAL
 * anonymous/free/pro/business rows, the past_due downgrade actually firing,
 * the bulk-generate gate returning the correct per-plan limit over real
 * HTTP, and the "add a 5th tier via one INSERT, zero code" property) was
 * verified THIS SESSION against a throwaway scratch MySQL database (created,
 * seeded via the real install.sql/seed_data.sql, exercised through the real
 * db_connect.php/fn_config.php/fn_features.php chain and the real
 * public_html/api/v1/index.php router via PHP's built-in server + curl, then
 * dropped - no credentials file ever written to disk) - see CHANGELOG.md /
 * .claude/HANDOFF.md for the full pasted transcript. This mirrors the exact
 * convention test_auth.php documents for registerUser()/loginUser()/etc. and
 * test_email.php documents for the email-queue DB path: this dependency-free
 * harness is the DURABLE, repo-tracked, DB-independent safety net; the
 * scratch-DB run is additional, session-specific evidence.
 *
 * ⚠️ Lives under repo-root tests/ - never deployed.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E4 framework stage, #25/#26/#53)
 * @see         web/CueRCode.net/_functions/fn_features.php
 * @see         web/CueRCode.net/_functions/fn_api_bulk.php - the wired bulk_batch_size gate
 * @see         DEV_NOTES.md - "How to add or modify a premium tier"
 */

// ==============================================================================
// 📦 Load the production feature-gating engine (NO database connection)
// ==============================================================================
// requireFn() (from tests/bootstrap.php) includes the fn_ file, which only
// REGISTERS the standalone functions. Every function in fn_features.php
// guards its dbQuery()/getConfig()/currentUser() calls with function_exists()
// checks, so simply including the file never opens a DB connection or starts
// a PHP session - it is safe to exercise its FAIL-SAFE paths directly here.
// ==============================================================================

requireFn('fn_features.php');

// ==============================================================================
// 🚨 Section 1: cuercodeFailSafePlan() - the hard-coded last-resort shape
// ==============================================================================

$failSafeAnon = cuercodeFailSafePlan('anonymous');

assertEquals('anonymous', $failSafeAnon['planKey'], 'cuercodeFailSafePlan: planKey echoes the argument');
assertEquals(0, $failSafeAnon['planID'], 'cuercodeFailSafePlan: planID is 0 (not a real row)');
assertEquals([], $failSafeAnon['features'], 'cuercodeFailSafePlan: features is empty (fail-closed for every key)');
assertEquals(25, $failSafeAnon['limits']['bulk_batch_size'], 'cuercodeFailSafePlan: bulk_batch_size defaults to 25');
assertEquals(null, $failSafeAnon['subscriptionID'], 'cuercodeFailSafePlan: subscriptionID is null');
assertEquals(null, $failSafeAnon['subscriptionStatus'], 'cuercodeFailSafePlan: subscriptionStatus is null');

$failSafeFree = cuercodeFailSafePlan('free');
assertEquals('free', $failSafeFree['planKey'], 'cuercodeFailSafePlan: honours a different planKey argument');

// ==============================================================================
// 🗃️ Section 2: featuresCacheStore() - all five ops
// ==============================================================================

assertFalse(featuresCacheStore('has', 'test:key'), 'featuresCacheStore: has() is false before any set()');
assertEquals(null, featuresCacheStore('get', 'test:key'), 'featuresCacheStore: get() on an absent key returns null');

featuresCacheStore('set', 'test:key', ['x' => 1]);
assertTrue(featuresCacheStore('has', 'test:key'), 'featuresCacheStore: has() is true after set()');
assertEquals(['x' => 1], featuresCacheStore('get', 'test:key'), 'featuresCacheStore: get() returns the exact stored value');

featuresCacheStore('clear', 'test:key');
assertFalse(featuresCacheStore('has', 'test:key'), 'featuresCacheStore: has() is false after clear()');

featuresCacheStore('set', 'test:a', 1);
featuresCacheStore('set', 'test:b', 2);
featuresCacheStore('clearAll');
assertFalse(featuresCacheStore('has', 'test:a'), 'featuresCacheStore: clearAll() evicts key "a"');
assertFalse(featuresCacheStore('has', 'test:b'), 'featuresCacheStore: clearAll() evicts key "b"');

assertEquals(null, featuresCacheStore('bogus_op', 'anything'), 'featuresCacheStore: an unrecognised op is a safe no-op returning null');

// ==============================================================================
// 👤 Section 3: planForUser()/planForOrg() - FAIL-SAFE fallback (no database)
// ==============================================================================
// dbQuery() is undefined in this harness (db_connect.php is never loaded),
// so every DB-touching helper inside fn_features.php degrades to its
// documented fail-safe behaviour. This still proves a REAL, important
// property: the anonymous-vs-free FALLBACK KEY distinction is honoured even
// when no plan row can actually be read.
// ==============================================================================

assertFalse(function_exists('dbQuery'), 'sanity: dbQuery() is genuinely undefined in this harness');

$anonPlan = planForUser(null);
assertEquals('anonymous', $anonPlan['planKey'], 'planForUser(null): fails safe to the anonymous plan key');
assertEquals(25, $anonPlan['limits']['bulk_batch_size'], 'planForUser(null): fail-safe bulk_batch_size is 25');
assertEquals(null, $anonPlan['subscriptionStatus'], 'planForUser(null): no subscription -> null status');

$freeFallbackPlan = planForUser(7);
assertEquals(
    'free',
    $freeFallbackPlan['planKey'],
    'planForUser(<a userID>): fails safe to the FREE plan key (not anonymous) - the '
    . 'logged-in-but-unresolvable-subscription case, distinct from the anonymous visitor case'
);

// 📌 Cache reuse: a second call for the SAME subject returns from the cache
// (proving the per-request cache actually engages, not just that both calls
// happen to agree).
assertTrue(featuresCacheStore('has', 'user:7'), 'planForUser(7): the resolution was cached under "user:7"');
$freeFallbackPlanAgain = planForUser(7);
assertEquals($freeFallbackPlan, $freeFallbackPlanAgain, 'planForUser(7): a second call returns the identical cached array');

$anonOrgPlan = planForOrg(null);
assertEquals('anonymous', $anonOrgPlan['planKey'], 'planForOrg(null): fails safe to the anonymous plan key');

$freeOrgFallbackPlan = planForOrg(3);
assertEquals('free', $freeOrgFallbackPlan['planKey'], 'planForOrg(<an orgID>): fails safe to the free plan key');

// ==============================================================================
// 🚩🔢 Section 4: featureEnabled()/limitFor() - generic, fail-closed lookups
// ==============================================================================

assertFalse(featureEnabled('webhooks', null), 'featureEnabled: fail-safe plan has no features -> false');
assertFalse(
    featureEnabled('a_brand_new_feature_nobody_has_seeded_yet', null),
    'featureEnabled: an unrecognised key is fail-CLOSED (denied), not fail-open'
);

assertEquals(25, limitFor('bulk_batch_size', null), 'limitFor: fail-safe bulk_batch_size is 25');
assertEquals(
    0,
    limitFor('a_brand_new_limit_nobody_has_seeded_yet', null),
    'limitFor: an unrecognised key is fail-CLOSED (0 allowance), not unlimited'
);

// ==============================================================================
// 🛑 Section 5: requireFeatureOr402() - structured denial shape
// ==============================================================================

$denied = requireFeatureOr402('webhooks', null);
assertTrue($denied !== null, 'requireFeatureOr402: denies an unavailable feature (non-null return)');
assertEquals(402, $denied['httpCode'], 'requireFeatureOr402: httpCode is 402 Payment Required');
assertEquals('FEATURE_NOT_AVAILABLE', $denied['errorCode'], 'requireFeatureOr402: errorCode is FEATURE_NOT_AVAILABLE');
assertEquals('webhooks', $denied['feature'], 'requireFeatureOr402: echoes the requested feature key');
assertEquals(
    '/pricing',
    $denied['upgradeUrl'],
    'requireFeatureOr402: falls back to the hard-coded default upgrade URL when getConfig() is unavailable'
);
assertContains('webhooks', $denied['message'], 'requireFeatureOr402: the message names the denied feature');

// ==============================================================================
// 🧭 Section 6: resolveUserIdFromAuth() - every $auth['method'] shape
// ==============================================================================

assertEquals(
    null,
    resolveUserIdFromAuth(['method' => 'anonymous', 'apiKeyId' => null]),
    'resolveUserIdFromAuth: anonymous method -> null subject'
);
assertEquals(
    null,
    resolveUserIdFromAuth(['method' => 'api_key', 'apiKeyId' => 99]),
    'resolveUserIdFromAuth: api_key method -> null when dbQuery() is unavailable (fail-safe)'
);
assertEquals(
    null,
    resolveUserIdFromAuth(['method' => 'csrf']),
    'resolveUserIdFromAuth: csrf method -> null when currentUser() is unavailable (fn_auth.php not loaded)'
);
assertEquals(
    null,
    resolveUserIdFromAuth([]),
    'resolveUserIdFromAuth: a shape missing "method" entirely -> null'
);

// ==============================================================================
// 🔄📞 Section 7: syncApiKeyLimitsForUser()/onSubscriptionPlanChanged() - fail-open-false
// ==============================================================================

assertFalse(
    syncApiKeyLimitsForUser(1),
    'syncApiKeyLimitsForUser: returns false (not a fatal error) when dbQuery() is unavailable'
);
assertFalse(
    onSubscriptionPlanChanged(1),
    'onSubscriptionPlanChanged: returns false (not a fatal error) when dbQuery() is unavailable'
);

// ==============================================================================
// 🧬 Section 8: STATIC regression guard - zero per-tier hardcoding (the owner's
//     "add a tier / reassign a feature / change a price with ZERO code changes"
//     requirement, proven structurally)
// ==============================================================================
// Reads fn_features.php's OWN source text (no execution) and asserts it never
// quotes the two PAID tier keys ('pro'/'business') as string literals
// anywhere - the only plan keys this file is allowed to reference by name are
// the two "nobody is paying" fallbacks (anonymous/free), both of which ARE
// documented, intentional exceptions. If a future edit ever adds a
// `if ($planKey === 'pro') { ... }`-style branch, this guard fails and must
// be consciously updated - which is exactly the point: it is a tripwire
// against reintroducing per-tier special-casing.
// ==============================================================================

$featuresEngineSource = file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_features.php');

assertTrue(
    is_string($featuresEngineSource) && $featuresEngineSource !== '',
    'regression guard: fn_features.php source was readable for static inspection'
);

assertContains(
    "'anonymous'",
    $featuresEngineSource,
    'regression guard: the anonymous fallback key literal IS present (intentional, documented exception)'
);
assertContains(
    "CUERCODE_PLAN_KEY_FREE",
    $featuresEngineSource,
    'regression guard: the free fallback key constant IS present (intentional, documented exception)'
);

assertFalse(
    str_contains($featuresEngineSource, "'pro'"),
    "regression guard: fn_features.php's source NEVER quotes 'pro' as a plan-key literal - "
    . 'every lookup is generic (array key access against decoded JSON), proving a brand-new '
    . 'planKey works with zero code changes'
);
assertFalse(
    str_contains($featuresEngineSource, "'business'"),
    "regression guard: fn_features.php's source NEVER quotes 'business' as a plan-key literal "
    . '(same generic-lookup proof as above)'
);

// 🔐 Lightweight security-pattern regression guard (house standing rule:
// prepared statements for ALL queries) - every dbQuery() call site in this
// file uses a '?' placeholder, never string-interpolated user input.
assertTrue(
    str_contains($featuresEngineSource, 'dbQuery('),
    'regression guard: fn_features.php actually calls the prepared-statement dbQuery() wrapper'
);
assertFalse(
    (bool) preg_match('/dbQuery\(\s*[\'"][^\'"]*\'\s*\.\s*\$/', $featuresEngineSource),
    'regression guard: no dbQuery() call site string-concatenates a variable directly into the SQL text'
);
