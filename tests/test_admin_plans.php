<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Tests for the ADMIN PLANS AND PRICES EDITOR (task 7n Phase 2, slice 2c)
 * ==============================================================================
 *
 * Path: tests/test_admin_plans.php
 *
 * Covers _functions/fn_admin_plans.php, the five new settings in
 * _functions/fn_admin_settings.php, moneyParseCurrencyList() and the plan
 * pages, WITHOUT a database:
 *
 *   1. Loading: no site start-up helpers defined.
 *   2. The plan-key rule (^[a-z][a-z0-9_]{1,31}$) and the text, number,
 *      contact-address and UK-time date checks (GMT and BST, clock changes).
 *   3. The freezing rules, each shown to REFUSE when it should and to ALLOW
 *      when it should (so a rule that was broken either way fails here):
 *      P1 bought prices, P4 delete only when unused, P5 protected plans,
 *      P6 key and kind fixed - including against hand-made requests.
 *   4. New-version key suggestions and price keys (never reused).
 *   5. Fingerprints (lost-update protection) change for ANY change.
 *   6. The price-group form: every outcome per currency, including a
 *      hand-made request for a currency that is not offered.
 *   7. The mirror: chosen by price GROUP, not by the recommended flag; never
 *      makes a paid plan look free.
 *   8. The five settings: validation, the "main currency must be offered"
 *      rule, the mirror after-save action.
 *   9. STATIC GUARDS: locking reads, transactions, audit rows, no floats in
 *      the money path, no charging switch written, no seeded plan key or
 *      price literal, back-quoted SQL, the pages' gate/CSRF/scripts/tables -
 *      each guard is first proved to catch a bad sample.
 *
 * ⚠️ NOT covered here (needs a real database): saving, the locks working,
 *    two admins at once, audit rows, the mirror writing. Those are in
 *    tests/integration/admin_plans_real_mysql.php.
 *
 * ⚠️ House rules for this file (it runs in the SAME global scope as
 *    tests/run.php and every other test file): every variable is prefixed $ap,
 *    and it never defines dbQuery(), getDB(), getConfig(), logError() or
 *    logActivity() - other test files assert those do NOT exist.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-26 (task 7n Phase 2, slice 2c - #31)
 * @see         web/CueRCode.net/_functions/fn_admin_plans.php
 * @see         .claude/plans/7n-admin-commerce-plan.md §4.3-4.7, §9 Phase 2
 */

// 🏷️ The two fallback plan keys are fn_features.php's constants. That file is
//    NOT loaded here: a later test file (test_api_keys.php) checks that it was
//    never loaded in this shared process. So the two constants are defined with
//    the same names and values first (fn_admin_plans.php then has no need to
//    load fn_features.php), and Section 1 checks fn_features.php really
//    defines them so.
if (!defined('CUERCODE_PLAN_KEY_ANONYMOUS')) {
    define('CUERCODE_PLAN_KEY_ANONYMOUS', 'anonymous');
}
if (!defined('CUERCODE_PLAN_KEY_FREE')) {
    define('CUERCODE_PLAN_KEY_FREE', 'free');
}

requireFn('fn_admin_plans.php');

$apSiteRoot = CUERCODE_SITE_ROOT;
$apUk = 'Europe/London';

// ==============================================================================
// ✅ Section 1: loading
// ==============================================================================
foreach (['dbQuery', 'getDB', 'getConfig', 'logError', 'logActivity'] as $apForbidden) {
    assertFalse(function_exists($apForbidden), 'loading fn_admin_plans.php does not define ' . $apForbidden . '()');
}
$apFeaturesSource = (string) file_get_contents($apSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_features.php');
assertContains("define('CUERCODE_PLAN_KEY_ANONYMOUS', 'anonymous');", $apFeaturesSource, 'the anonymous fallback key is fn_features.php\'s constant (same value as defined above)');
assertContains("define('CUERCODE_PLAN_KEY_FREE', 'free');", $apFeaturesSource, 'the free fallback key is fn_features.php\'s constant (same value as defined above)');
assertFalse(function_exists('limitFor'), 'fn_features.php was not loaded by this test (a later test file relies on that)');

// ==============================================================================
// ✅ Section 2: checking typed values
// ==============================================================================

// 🏷️ Plan keys (plan §9 Phase 2).
foreach (['ab', 'team_plus', 'pro2', 'a_b_c', 'x' . str_repeat('y', 31)] as $apGood) {
    assertTrue(adminPlansKeyIsValid($apGood), 'plan key accepted: ' . $apGood);
}
foreach (['a', '', 'A1', '1abc', '_ab', 'pro-plus', 'pro plus', 'Pro', 'x' . str_repeat('y', 32), "ab\n", 'ab ', 'café', 'ab;'] as $apBad) {
    assertFalse(adminPlansKeyIsValid($apBad), 'plan key refused: ' . var_export($apBad, true));
}

// 🔤 Names and descriptions: plain text only.
assertEquals('Team Plus', adminPlansCheckText('  Team Plus ', 100, true)['value'], 'a name is trimmed');
assertTrue(adminPlansCheckText(str_repeat('é', 100), 100, true)['ok'], 'a name of 100 characters (not bytes) is accepted');
assertFalse(adminPlansCheckText(str_repeat('a', 101), 100, true)['ok'], 'a name of 101 characters is refused');
assertFalse(adminPlansCheckText('', 100, true)['ok'], 'an empty name is refused');
assertTrue(adminPlansCheckText('', 500, false)['ok'], 'an empty description is fine');
foreach (['<b>Pro</b>', 'Pro >', "Line\nbreak", "Tab\there", "Zero\u{200B}width", "bad\xFF"] as $apBad) {
    assertFalse(adminPlansCheckText($apBad, 100, true)['ok'], 'text refused: ' . bin2hex($apBad));
}
assertFalse(adminPlansCheckText(['array'], 100, true)['ok'], 'a hand-made array instead of text is refused');

// 🔢 Whole numbers.
assertEquals(5, adminPlansCheckWhole('5', 0, 10, false)['value'], 'whole number 5');
assertEquals(null, adminPlansCheckWhole('', 1, 10, true)['value'], 'an empty optional number means none');
foreach (['007', '+5', '1e3', '5.0', '-1', ' 1 2', '11', 'x', ''] as $apBad) {
    assertFalse(adminPlansCheckWhole($apBad, 0, 10, false)['ok'], 'whole number refused: ' . var_export($apBad, true));
}
assertTrue(adminPlansCheckWhole('0', 0, 10, false)['ok'], 'zero itself is fine');

// ✉️ Contact addresses.
assertEquals(null, adminPlansCheckContactUrl('')['value'], 'empty contact address = use the site email (NULL)');
assertEquals('https://example.com/sales', adminPlansCheckContactUrl('https://example.com/sales')['value'], 'an https address is accepted');
assertEquals('mailto:sales@example.com', adminPlansCheckContactUrl('MAILTO:sales@example.com')['value'], 'a mailto: address is accepted (prefix tidied)');
foreach (['http://example.com', 'javascript:alert(1)', 'mailto:', 'mailto:not-an-address', 'mailto:a@example.com?subject=x', 'mailto:a@[127.0.0.1]', 'https://localhost/x', 'example.com', 'https://a b.com'] as $apBad) {
    assertFalse(adminPlansCheckContactUrl($apBad)['ok'], 'contact address refused: ' . $apBad);
}

// 🕰️ UK-time dates → UTC (plan §8.4 examples).
assertEquals('2026-12-31 23:59:59', adminPlansCheckLocalDate('2026-12-31', '', $apUk, true)['utc'], 'end 31 Dec 2026 (GMT) → 2026-12-31 23:59:59 UTC');
assertEquals('2027-06-30 22:59:59', adminPlansCheckLocalDate('2027-06-30', '', $apUk, true)['utc'], 'end 30 Jun 2027 (BST) → 2027-06-30 22:59:59 UTC');
assertEquals('2027-06-29 23:00:00', adminPlansCheckLocalDate('2027-06-30', '', $apUk, false)['utc'], 'start 30 Jun 2027 (BST) → 2027-06-29 23:00:00 UTC');
assertEquals('2026-12-01 09:30:00', adminPlansCheckLocalDate('2026-12-01', '09:30', $apUk, false)['utc'], 'a start with a time (GMT)');
assertEquals(null, adminPlansCheckLocalDate('', '', $apUk, false)['utc'], 'both boxes empty = no date');
assertContains('does not exist', adminPlansCheckLocalDate('2027-03-28', '01:30', $apUk, false)['error'], 'a time the clocks skip is refused');
assertContains('02:00', adminPlansCheckLocalDate('2027-03-28', '01:30', $apUk, false)['error'], '... with the next valid time suggested');
assertContains('happens twice', adminPlansCheckLocalDate('2026-10-25', '01:30', $apUk, false)['error'], 'a time that happens twice is refused');
foreach ([['', '09:30'], ['2026-02-30', ''], ['31/12/2026', ''], ['2026-12-31', '9:30'], ['2026-12-31', '25:00'], [['x'], '']] as $apBad) {
    assertFalse(adminPlansCheckLocalDate($apBad[0], $apBad[1], $apUk, false)['ok'], 'date refused: ' . json_encode($apBad));
}

// 🔁 Round trip: what is saved is shown the way it was typed.
assertEquals(['date' => '2027-06-30', 'time' => ''], adminPlansUtcToInputs('2027-06-30 22:59:59', $apUk, true), 'an end-of-day end shows as the date alone');
assertEquals(['date' => '2027-06-30', 'time' => ''], adminPlansUtcToInputs('2027-06-29 23:00:00', $apUk, false), 'a start-of-day start shows as the date alone');
assertEquals(['date' => '2026-12-01', 'time' => '09:30'], adminPlansUtcToInputs('2026-12-01 09:30:00', $apUk, false), 'a start with a time shows the time');
assertEquals(['date' => '', 'time' => ''], adminPlansUtcToInputs(null, $apUk, false), 'no date shows empty boxes');
assertEquals('31 Dec 2026, 23:59 (UK time)', adminPlansDateWords('2026-12-31 23:59:59', $apUk, 'none'), 'dates are shown labelled "(UK time)"');
assertEquals('No end date', adminPlansDateWords(null, $apUk, 'No end date'), 'no date shows the given words');

// ==============================================================================
// ✅ Section 3: the freezing and protection rules (plan §4.3)
// ==============================================================================

// 🛡️ P5: the two fallbacks and the plans named in settings are protected...
$apSettingKeys = ['billing_past_due_downgrade_plan_key' => 'free', 'billing_trial_plan_key' => 'team_plus'];
assertTrue(adminPlansProtectedReason(CUERCODE_PLAN_KEY_ANONYMOUS, []) !== '', 'P5: the anonymous plan is protected even with no settings');
assertTrue(adminPlansProtectedReason(CUERCODE_PLAN_KEY_FREE, []) !== '', 'P5: the free plan is protected even with no settings');
assertContains('billing_trial_plan_key', adminPlansProtectedReason('team_plus', $apSettingKeys), 'P5: the trial plan is protected and the setting is named');
assertContains('billing_past_due_downgrade_plan_key', adminPlansProtectedReason('grace', ['billing_past_due_downgrade_plan_key' => 'grace']), 'P5: the past-due plan is protected');
// ...and nothing else is (a rule that protected everything would fail here).
assertEquals('', adminPlansProtectedReason('team_basic', $apSettingKeys), 'P5: an ordinary plan is not protected');
assertEquals('', adminPlansProtectedReason('team', ['billing_trial_plan_key' => 'team_plus']), 'P5: a partial match is not a match');

// 🗑️ P4: delete only what nothing refers to.
$apUnused = ['subscriptions' => 0, 'oneOffPurchases' => 0, 'payments' => 0, 'discounts' => 0];
assertTrue(adminPlansDeleteDecision($apUnused, '')['allowed'], 'P4: an unused, unprotected plan may be deleted');
assertTrue(adminPlansDeleteDecision([], '')['allowed'], 'P4: missing counts count as zero');
foreach (array_keys($apUnused) as $apUse) {
    $apUsed = $apUnused;
    $apUsed[$apUse] = 1;
    $apDecision = adminPlansDeleteDecision($apUsed, '');
    assertFalse($apDecision['allowed'], 'P4: one ' . $apUse . ' blocks deleting');
    assertEquals(1, count($apDecision['reasons']), 'P4: ... with one reason given');
}
assertFalse(adminPlansDeleteDecision($apUnused, 'protected')['allowed'], 'P4+P5: a protected plan is never deleted');
assertEquals(3, count(adminPlansDeleteDecision(['subscriptions' => 2, 'payments' => 1], 'protected')['reasons']), 'P4: every reason is listed');

// 🧊 P1: a bought price is frozen; an unbought one is not.
assertTrue(adminPlansPriceIsFrozen(1), 'P1: one record using a price freezes it');
assertFalse(adminPlansPriceIsFrozen(0), 'P1: an unused price is not frozen');
assertEquals('frozen', adminPlansPriceChangeDecision('6.99', 'GBP', 799, true), 'P1: changing a bought price is refused');
assertEquals('update', adminPlansPriceChangeDecision('6.99', 'GBP', 799, false), 'P1: changing an unbought price is allowed');
assertEquals('unchanged', adminPlansPriceChangeDecision('6.99', 'GBP', 699, true), 'P1: the same amount on a bought price is no change (not a refusal)');
assertEquals('unchanged', adminPlansPriceChangeDecision('1200.00', 'JPY', 1200, true), 'P1: a yen price stored with .00 compares as the same');
assertEquals('unreadable', adminPlansPriceChangeDecision('6.9x', 'GBP', 699, false), 'a stored amount that cannot be read is never guessed');

// 🔒 P6 (and S6): the plan form against hand-made requests.
$apPlan = [
    'planID' => 7, 'planKey' => 'team_plus', 'planKind' => 'standard', 'ctaMode' => 'checkout', 'contactUrl' => null,
    'planName' => 'Team Plus', 'description' => 'For teams.', 'priceMonthly' => '9.00', 'priceYearly' => '90.00', 'currency' => 'GBP',
    'featuresJSON' => '{"a":true}', 'limitsJSON' => '{"b":1}', 'isActive' => 1, 'sortOrder' => 3,
    'availableFrom' => null, 'availableUntil' => '2027-06-30 22:59:59', 'maxSales' => 50, 'basedOnPlanID' => null,
    'pricePromiseMode' => null, 'pricePromiseMonths' => null, 'createdAt' => '2026-11-01 10:00:00', 'updatedAt' => '2026-11-01 10:00:00',
];
$apEdit = adminPlansValidatePlanForm(['plan_name' => 'Team Plus 2'], $apPlan, ['timezone' => $apUk, 'soldCount' => 0]);
assertEquals([], $apEdit['errors'], 'edit: a request with only the name changed is fine');
assertEquals('Team Plus 2', $apEdit['values']['planName'], 'edit: the new name is taken');
assertEquals(3, $apEdit['values']['sortOrder'], 'edit: a box missing from the request keeps the stored value');
assertEquals('2027-06-30 22:59:59', $apEdit['values']['availableUntil'], 'edit: a missing date keeps the stored date exactly (no time-zone drift)');
assertEquals(50, $apEdit['values']['maxSales'], 'edit: the stored cap is kept');
assertEquals(
    ['planName', 'description', 'sortOrder', 'ctaMode', 'contactUrl', 'availableFrom', 'availableUntil', 'maxSales', 'pricePromiseMode', 'pricePromiseMonths'],
    array_keys($apEdit['values']),
    'S6: an edit can only ever set these ten columns'
);
$apHostile = adminPlansValidatePlanForm([
    'plan_name' => 'X', 'featuresJSON' => '{"everything":true}', 'isActive' => '1', 'planID' => '1', 'redemptionCount' => '0',
    'priceMonthly' => '0.01', 'basedOnPlanID' => '1', 'createdByUserID' => '1',
], $apPlan, ['timezone' => $apUk, 'soldCount' => 0]);
assertEquals([], $apHostile['errors'], 'S6: unknown boxes in a hand-made request are ignored');
assertFalse(isset($apHostile['values']['featuresJSON']) || isset($apHostile['values']['isActive']) || isset($apHostile['values']['priceMonthly']) || isset($apHostile['values']['basedOnPlanID']), 'S6: ...and never reach the values to save');
$apKeyChange = adminPlansValidatePlanForm(['plan_key' => 'team_max'], $apPlan, ['timezone' => $apUk]);
assertTrue(isset($apKeyChange['errors']['plan_key']), 'P6: a hand-made request that changes the key is REFUSED (not ignored)');
assertEquals([], $apKeyChange['values'], 'P6: ...and nothing is saved');
assertFalse(isset(adminPlansValidatePlanForm(['plan_key' => 'team_plus'], $apPlan, ['timezone' => $apUk])['errors']['plan_key']), 'P6: sending the SAME key is fine');
assertTrue(isset(adminPlansValidatePlanForm(['plan_key' => ['team_plus']], $apPlan, ['timezone' => $apUk])['errors']['plan_key']), 'P6: a key sent as a list is refused');
assertTrue(isset(adminPlansValidatePlanForm(['plan_kind' => 'lifetime_deal'], $apPlan, ['timezone' => $apUk])['errors']['plan_kind']), 'P6: a hand-made request that changes the kind is refused');
assertFalse(isset(adminPlansValidatePlanForm(['plan_kind' => 'standard'], $apPlan, ['timezone' => $apUk])['errors']['plan_kind']), 'P6: sending the same kind is fine');

// 🔢 Sales cap: never below what is sold (plan §4.5).
assertTrue(isset(adminPlansValidatePlanForm(['max_sales' => '9'], $apPlan, ['timezone' => $apUk, 'soldCount' => 10])['errors']['max_sales']), 'cap below sold (9 < 10) is refused');
assertEquals(10, adminPlansValidatePlanForm(['max_sales' => '10'], $apPlan, ['timezone' => $apUk, 'soldCount' => 10])['values']['maxSales'], 'cap equal to sold is fine');
assertEquals(null, adminPlansValidatePlanForm(['max_sales' => ''], $apPlan, ['timezone' => $apUk, 'soldCount' => 10])['values']['maxSales'], 'an empty cap means no cap');
assertTrue(isset(adminPlansValidatePlanForm(['max_sales' => '0'], $apPlan, ['timezone' => $apUk])['errors']['max_sales']), 'a cap of 0 is refused (empty means no cap)');

// 🤝 Price promise.
$apPromise = adminPlansValidatePlanForm(['promise_mode' => 'fixed_months', 'promise_months' => '12'], $apPlan, ['timezone' => $apUk]);
assertEquals(['fixed_months', 12], [$apPromise['values']['pricePromiseMode'], $apPromise['values']['pricePromiseMonths']], 'a fixed 12-month promise');
assertTrue(isset(adminPlansValidatePlanForm(['promise_mode' => 'fixed_months', 'promise_months' => ''], $apPlan, ['timezone' => $apUk])['errors']['promise_months']), 'fixed months needs a number');
assertTrue(isset(adminPlansValidatePlanForm(['promise_mode' => 'fixed_months', 'promise_months' => '121'], $apPlan, ['timezone' => $apUk])['errors']['promise_months']), 'at most 120 months');
assertTrue(isset(adminPlansValidatePlanForm(['promise_mode' => 'none', 'promise_months' => '12'], $apPlan, ['timezone' => $apUk])['errors']['promise_months']), 'months with another promise is refused (never stored silently)');
$apSiteDefault = adminPlansValidatePlanForm(['promise_mode' => '', 'promise_months' => ''], $apPlan, ['timezone' => $apUk]);
assertEquals([null, null], [$apSiteDefault['values']['pricePromiseMode'], $apSiteDefault['values']['pricePromiseMonths']], '"use the site-wide setting" stores NULL');
assertTrue(isset(adminPlansValidatePlanForm(['promise_mode' => 'forever'], $apPlan, ['timezone' => $apUk])['errors']['promise_mode']), 'an unknown promise is refused');

// 🗓️ Sale window.
$apWindow = adminPlansValidatePlanForm(['available_from_date' => '2026-12-01', 'available_from_time' => '', 'available_until_date' => '2026-12-31', 'available_until_time' => ''], $apPlan, ['timezone' => $apUk]);
assertEquals(['2026-12-01 00:00:00', '2026-12-31 23:59:59'], [$apWindow['values']['availableFrom'], $apWindow['values']['availableUntil']], 'a sale window in UK time is stored in UTC');
assertTrue(isset(adminPlansValidatePlanForm(['available_from_date' => '2026-12-31', 'available_from_time' => '', 'available_until_date' => '2026-12-01', 'available_until_time' => ''], $apPlan, ['timezone' => $apUk])['errors']['available_until_date']), 'an end before the start is refused');

// 🔘 Button and contact address.
assertTrue(isset(adminPlansValidatePlanForm(['cta_mode' => 'buy_now'], $apPlan, ['timezone' => $apUk])['errors']['cta_mode']), 'an unknown button choice is refused');
assertTrue(isset(adminPlansValidatePlanForm(['contact_url' => 'http://example.com'], $apPlan, ['timezone' => $apUk])['errors']['contact_url']), 'an http contact address is refused');
assertTrue(isset(adminPlansValidatePlanForm(['plan_name' => '<script>'], $apPlan, ['timezone' => $apUk])['errors']['plan_name']), 'HTML in a name is refused');

// ➕ Creating.
$apSources = [7 => $apPlan];
$apCreate = adminPlansValidatePlanForm(
    ['plan_key' => 'team_max', 'plan_kind' => 'standard', 'copy_from' => '7', 'plan_name' => 'Team Max', 'sort_order' => '5', 'cta_mode' => 'contact', 'contact_url' => '', 'promise_mode' => ''],
    null,
    ['timezone' => $apUk, 'existingKeys' => ['team_plus'], 'sourcePlans' => $apSources]
);
assertEquals([], $apCreate['errors'], 'create: a good form');
assertEquals(['team_max', 'standard', 7], [$apCreate['values']['planKey'], $apCreate['values']['planKind'], $apCreate['values']['basedOnPlanID']], 'create: key, kind and copied-from plan');
assertTrue(isset(adminPlansValidatePlanForm(['plan_key' => 'team_plus', 'plan_kind' => 'standard', 'plan_name' => 'X'], null, ['timezone' => $apUk, 'existingKeys' => ['team_plus']])['errors']['plan_key']), 'create: a key already taken is refused');
assertTrue(isset(adminPlansValidatePlanForm(['plan_key' => 'Bad Key', 'plan_kind' => 'standard', 'plan_name' => 'X'], null, ['timezone' => $apUk])['errors']['plan_key']), 'create: a badly formed key is refused');
assertTrue(isset(adminPlansValidatePlanForm(['plan_key' => 'abc', 'plan_kind' => 'gold', 'plan_name' => 'X'], null, ['timezone' => $apUk])['errors']['plan_kind']), 'create: an unknown kind is refused');
assertTrue(isset(adminPlansValidatePlanForm(['plan_key' => 'abc', 'plan_kind' => 'standard', 'plan_name' => 'X', 'copy_from' => '99'], null, ['timezone' => $apUk, 'sourcePlans' => $apSources])['errors']['copy_from']), 'create: copying from a plan that does not exist is refused (S5)');
assertTrue(isset(adminPlansValidatePlanForm(['plan_key' => 'abc', 'plan_kind' => 'standard', 'plan_name' => 'X', 'copy_from' => '7 OR 1=1'], null, ['timezone' => $apUk, 'sourcePlans' => $apSources])['errors']['copy_from']), 'create: a copied-from ID that is not a plain number is refused');

// ==============================================================================
// ✅ Section 4: keys
// ==============================================================================
assertEquals('team_plus_v2', adminPlansNewVersionKey('team_plus', ['team_plus']), 'new version: _v2');
assertEquals('team_plus_v3', adminPlansNewVersionKey('team_plus', ['team_plus', 'team_plus_v2']), 'new version: _v3 when _v2 is taken');
assertEquals('team_plus_v4', adminPlansNewVersionKey('team_plus_v3', ['team_plus_v3']), 'new version of a version: the number goes up');
$apLong = 'a' . str_repeat('b', 31);
$apLongVersion = adminPlansNewVersionKey($apLong, [$apLong]);
assertTrue(adminPlansKeyIsValid($apLongVersion) && strlen($apLongVersion) <= 32 && str_ends_with($apLongVersion, '_v2'), 'new version of a 32-character key still fits (' . $apLongVersion . ')');
assertEquals('monthly_gbp', adminPlansNextPriceKey('monthly', 'GBP', []), 'price key: group + currency');
assertEquals('monthly_gbp_2', adminPlansNextPriceKey('monthly', 'GBP', ['monthly_gbp']), 'price key: a retired key is never reused');
assertEquals('monthly_gbp_3', adminPlansNextPriceKey('monthly', 'GBP', ['monthly_gbp', 'monthly_gbp_2']), 'price key: _3 next');
assertEquals('yearly_usd', adminPlansNextPriceKey('yearly', 'USD', ['monthly_gbp']), 'price key: another group is independent');

// ==============================================================================
// ✅ Section 5: fingerprints
// ==============================================================================
$apFp = adminPlansRowFingerprint($apPlan);
assertEquals($apFp, adminPlansRowFingerprint(array_reverse($apPlan, true)), 'fingerprint: column order does not matter');
foreach (array_keys($apPlan) as $apColumn) {
    $apChanged = $apPlan;
    $apChanged[$apColumn] = 'changed';
    assertTrue(adminPlansRowFingerprint($apChanged) !== $apFp, 'fingerprint: a change to ' . $apColumn . ' is noticed');
}
$apMirrored = $apPlan;
$apMirrored['priceMonthly'] = '9.99';
$apMirrored['updatedAt'] = '2026-11-01 10:00:01';
assertEquals(adminPlansFormFingerprint($apPlan), adminPlansFormFingerprint($apMirrored), 'form fingerprint: a price save (mirror) does not make an open plan form out of date');
$apRenamed = $apPlan;
$apRenamed['planName'] = 'Other';
assertTrue(adminPlansFormFingerprint($apRenamed) !== adminPlansFormFingerprint($apPlan), 'form fingerprint: a change the form shows IS noticed');

$apPrice = static function (array $apOver): array {
    return array_merge([
        'pricePointID' => 1, 'planID' => 7, 'priceKey' => 'monthly_gbp', 'priceGroupKey' => 'monthly', 'billingInterval' => 'monthly', 'intervalCount' => 1,
        'amount' => '6.99', 'currency' => 'GBP', 'grantsJSON' => null, 'validFrom' => null, 'validUntil' => null, 'isDefault' => 0, 'isActive' => 1,
        'sortOrder' => 1, 'createdAt' => '2026-11-01 10:00:00', 'updatedAt' => '2026-11-01 10:00:00',
    ], $apOver);
};
$apRows = [$apPrice([]), $apPrice(['pricePointID' => 2, 'priceKey' => 'yearly_gbp', 'priceGroupKey' => 'yearly', 'billingInterval' => 'yearly', 'amount' => '49.99', 'isDefault' => 1, 'sortOrder' => 2])];
$apPricesFp = adminPlansPricesFingerprint($apRows);
assertEquals($apPricesFp, adminPlansPricesFingerprint(array_reverse($apRows)), 'price-list fingerprint: row order does not matter');
assertTrue(adminPlansPricesFingerprint([$apRows[0]]) !== $apPricesFp, 'price-list fingerprint: a deleted price is noticed');
assertTrue(adminPlansPricesFingerprint([$apRows[0], $apPrice(['pricePointID' => 2, 'amount' => '49.98'])]) !== $apPricesFp, 'price-list fingerprint: an amount change is noticed');
assertTrue(adminPlansPricesFingerprint(array_merge($apRows, [$apPrice(['pricePointID' => 3, 'currency' => 'USD'])])) !== $apPricesFp, 'price-list fingerprint: an added price is noticed');

// ==============================================================================
// ✅ Section 6: the price-group form
// ==============================================================================
$apOffered = ['GBP', 'USD', 'EUR'];
$apGroup = function (array $apPost, array $apRefs = [], ?array $apRowsIn = null) use ($apOffered, $apRows, $apUk): array {
    $apUse = $apRows;
    if ($apRowsIn !== null) {
        $apUse = $apRowsIn;
    }
    return adminPlansProcessPriceGroup($apPost, 'monthly', $apOffered, $apUse, $apRefs, $apUk);
};

$apOut = $apGroup(['amount' => ['GBP' => '6.99', 'USD' => '7.99', 'EUR' => '']]);
assertEquals([], $apOut['errors'], 'group: unchanged GBP, new USD, empty EUR is fine');
assertEquals([['op' => 'create', 'currency' => 'USD', 'amount' => '7.99']], $apOut['operations'], 'group: only the new USD price is an operation');

$apOut = $apGroup(['amount' => ['GBP' => '7.49']]);
assertEquals('update', $apOut['operations'][0]['op'], 'P1: an UNBOUGHT price may change');
assertEquals(['6.99', '7.49'], [$apOut['operations'][0]['oldAmount'], $apOut['operations'][0]['amount']], 'P1: ...from 6.99 to 7.49');
$apOut = $apGroup(['amount' => ['GBP' => '7.49']], [1 => 3]);
assertTrue(isset($apOut['errors']['amount_GBP']), 'P1: a BOUGHT price may not change (hand-made POST or not)');
assertContains('Retire it', $apOut['errors']['amount_GBP'], 'P1: ...and the admin is told to retire it and add a new one');
assertEquals([], $apOut['operations'], 'P1: ...and nothing at all is saved');
$apOut = $apGroup(['amount' => ['GBP' => '6.99']], [1 => 3]);
assertEquals([], $apOut['errors'], 'P1: re-sending the same amount for a bought price is fine (dates can still be changed)');

$apOut = $apGroup(['amount' => ['GBP' => '']]);
assertContains('Retire button', $apOut['errors']['amount_GBP'], 'an empty box never quietly removes a price');
foreach (['0', '0.00', '6.999', '-1', '1e3', '6,99', '£6.99', '100000.00', ' 6 .99'] as $apBadAmount) {
    $apOut = $apGroup(['amount' => ['USD' => $apBadAmount]]);
    assertTrue(isset($apOut['errors']['amount_USD']), 'group: amount refused ' . var_export($apBadAmount, true));
}
assertEquals('99999.99', $apGroup(['amount' => ['GBP' => '6.99', 'USD' => '99999.99']])['operations'][0]['amount'], 'group: the largest amount is accepted exactly');
assertEquals('6.90', $apGroup(['amount' => ['GBP' => '6.99', 'USD' => '6.9']])['operations'][0]['amount'], 'group: "6.9" is stored as 6.90');
$apOut = $apGroup(['amount' => ['JPY' => '1200', 'GBP' => '6.99']]);
assertEquals([], $apOut['operations'], 'group: a hand-made amount in a currency that is NOT offered is never read');
$apOut = $apGroup(['amount' => ['USD' => ['7.99']]]);
assertTrue(isset($apOut['errors']['amount_USD']), 'group: an amount sent as a list is refused');
$apOut = $apGroup(['amount' => ['GBP' => '6.99']], [], array_merge($apRows, [$apPrice(['pricePointID' => 9, 'priceKey' => 'monthly_gbp_2'])]));
assertContains('more than one current price', $apOut['errors']['amount_GBP'], 'group: two current prices in one currency are refused, not guessed');
$apOut = $apGroup(['amount' => ['GBP' => '6.99'], 'valid_from_date' => '2026-12-01', 'valid_until_date' => '2026-12-31', 'recommended' => '1']);
assertEquals(['2026-12-01 00:00:00', '2026-12-31 23:59:59', true], [$apOut['validFrom'], $apOut['validUntil'], $apOut['recommended']], 'group: the dates (UK time to UTC) and the recommended tick');
$apOut = $apGroup(['amount' => ['USD' => '7.99'], 'valid_from_date' => '2026-12-31', 'valid_until_date' => '2026-12-01']);
assertTrue(isset($apOut['errors']['valid_until_date']) && $apOut['operations'] === [], 'group: an end before the start refuses the whole group');
$apOut = $apGroup(['amount' => ['GBP' => '6.99'], 'recommended' => 'yes']);
assertFalse($apOut['recommended'], 'group: only "1" ticks recommended');
$apRetiredRows = [$apPrice(['isActive' => 0])];
$apOut = $apGroup(['amount' => ['GBP' => '7.49']], [1 => 5], $apRetiredRows);
assertEquals([['op' => 'create', 'currency' => 'GBP', 'amount' => '7.49']], $apOut['operations'], 'P1: after retiring a bought price, a NEW price can be added');

// ⚠️ Missing currencies.
assertEquals(['Monthly: no price in USD, EUR yet.', 'Yearly: no price in USD, EUR yet.'], adminPlansMissingCurrencyWarnings($apRows, 'standard', $apOffered), 'missing $ / € warnings per group');
assertEquals([], adminPlansMissingCurrencyWarnings([], 'standard', $apOffered), 'a plan with no prices at all (a free plan) has no warning');
assertEquals([], adminPlansMissingCurrencyWarnings($apRows, 'standard', ['GBP']), 'no warning when every offered currency has a price');

// 🚦 Status.
$apNow = '2026-11-26 12:00:00';
assertEquals('on_sale', adminPlansPriceStatus($apPrice([]), $apNow), 'status: on sale');
assertEquals('retired', adminPlansPriceStatus($apPrice(['isActive' => 0]), $apNow), 'status: retired');
assertEquals('scheduled', adminPlansPriceStatus($apPrice(['validFrom' => '2026-12-01 00:00:00']), $apNow), 'status: starts later');
assertEquals('ended', adminPlansPriceStatus($apPrice(['validUntil' => '2026-11-01 00:00:00']), $apNow), 'status: ended');

// 🗂️ Groups by kind.
assertEquals(['monthly', 'yearly'], array_keys(adminPlansPriceGroups('standard')), 'an ordinary plan has monthly and yearly prices');
assertEquals(['lifetime'], array_keys(adminPlansPriceGroups('lifetime_deal')), 'a lifetime deal has a pay-once price');
assertEquals([], adminPlansPriceGroups('credit_packs'), 'credit packs are priced on their own screen (later)');
assertEquals([], adminPlansPriceGroups('made_up'), 'an unknown kind has no prices');

// ==============================================================================
// ✅ Section 7: the mirror (plan §4.7)
// ==============================================================================
// ⭐ By GROUP, not by the recommended flag: here the YEARLY price is the
//    recommended one and the monthly is not; monthly must still be monthly.
$apSelection = adminMirrorSelection($apRows, 'GBP', $apNow);
assertEquals(['6.99', '49.99', true], [$apSelection['monthly'], $apSelection['yearly'], $apSelection['hasAnyPrice']], 'mirror: monthly and yearly chosen by group (the yearly one is the recommended one)');
$apFlagSwapped = [$apPrice(['isDefault' => 1]), $apPrice(['pricePointID' => 2, 'priceKey' => 'yearly_gbp', 'priceGroupKey' => 'yearly', 'billingInterval' => 'yearly', 'amount' => '49.99', 'isDefault' => 0])];
assertEquals(['6.99', '49.99'], array_slice(array_values(adminMirrorSelection($apFlagSwapped, 'GBP', $apNow)), 0, 2), 'mirror: moving the recommended flag changes nothing');
$apMixed = [
    $apPrice(['pricePointID' => 1, 'isActive' => 0, 'amount' => '5.00']),
    $apPrice(['pricePointID' => 2, 'currency' => 'USD', 'amount' => '8.00']),
    $apPrice(['pricePointID' => 3, 'validUntil' => '2026-01-01 00:00:00', 'amount' => '4.00']),
    $apPrice(['pricePointID' => 4, 'validFrom' => '2027-01-01 00:00:00', 'amount' => '3.00']),
    $apPrice(['pricePointID' => 5, 'priceGroupKey' => 'lifetime', 'billingInterval' => 'lifetime', 'amount' => '119.00']),
    $apPrice(['pricePointID' => 6, 'priceGroupKey' => null, 'amount' => '2.00']),
    $apPrice(['pricePointID' => 7, 'amount' => '7.25', 'sortOrder' => 5]),
    $apPrice(['pricePointID' => 8, 'amount' => '7.50', 'sortOrder' => 1]),
];
$apSelection = adminMirrorSelection($apMixed, 'GBP', $apNow);
assertEquals('7.50', $apSelection['monthly'], 'mirror: retired, other-currency, ended, scheduled, lifetime and ungrouped prices are skipped; the lowest display order wins');
assertEquals(null, $apSelection['yearly'], 'mirror: no yearly price found');
assertEquals(['monthly' => null, 'yearly' => null, 'hasAnyPrice' => false, 'managed' => ['monthly' => false, 'yearly' => false]], adminMirrorSelection([], 'GBP', $apNow), 'mirror: a plan with no prices');
assertEquals(['monthly' => true, 'yearly' => false], $apSelection['managed'], 'mirror: the monthly group has price rows (even retired or ended ones), the yearly group none');

$apLegacy = ['priceMonthly' => '4.99', 'priceYearly' => '49.99', 'currency' => 'GBP'];
$apDecision = adminMirrorDecision($apLegacy, ['monthly' => '6.99', 'yearly' => '49.99', 'hasAnyPrice' => true], 'GBP');
assertTrue($apDecision['change'], 'mirror: 4.99 becomes 6.99');
assertEquals(['priceMonthly' => '6.99', 'priceYearly' => '49.99', 'currency' => 'GBP'], $apDecision['values'], 'mirror: the values written (text, never floats)');
assertTrue(is_string($apDecision['values']['priceMonthly']) && is_string($apDecision['values']['priceYearly']), 'mirror: amounts stay text');
assertFalse(adminMirrorDecision(['priceMonthly' => '6.99', 'priceYearly' => '49.99', 'currency' => 'GBP'], ['monthly' => '6.99', 'yearly' => '49.99', 'hasAnyPrice' => true], 'GBP')['change'], 'mirror: nothing to change');
$apDecision = adminMirrorDecision($apLegacy, ['monthly' => null, 'yearly' => '59.99', 'hasAnyPrice' => true], 'GBP');
assertEquals('4.99', $apDecision['values']['priceMonthly'], 'mirror: NO current monthly price leaves the old one (never 0.00, which would read "Free")');
assertContains('keeps showing the older one (£4.99)', implode(' ', $apDecision['warnings']), 'mirror: ...and says so (no monthly price rows: the page keeps the older column)');
$apManagedDecision = adminMirrorDecision($apLegacy, ['monthly' => null, 'yearly' => '59.99', 'hasAnyPrice' => true, 'managed' => ['monthly' => true, 'yearly' => true]], 'GBP');
assertContains('shows no monthly price for this plan', implode(' ', $apManagedDecision['warnings']), 'F6: with monthly price rows but none on sale, the warning says the page shows no monthly price (it reads the list live)');
assertEquals('59.99', $apDecision['values']['priceYearly'], 'mirror: ...while the yearly price is still copied');
assertFalse(adminMirrorDecision(['priceMonthly' => '0.00', 'priceYearly' => '0.00', 'currency' => 'GBP'], ['monthly' => null, 'yearly' => null, 'hasAnyPrice' => false], 'GBP')['change'], 'mirror: a free plan (no prices) is left alone');
assertEquals([], adminMirrorDecision(['priceMonthly' => '99.00', 'priceYearly' => '0.00', 'currency' => 'GBP'], ['monthly' => '99.00', 'yearly' => null, 'hasAnyPrice' => true], 'GBP')['warnings'], 'mirror: a monthly-only plan (0.00 yearly) gives no warning');
$apSwitch = adminMirrorDecision($apLegacy, ['monthly' => '7.99', 'yearly' => null, 'hasAnyPrice' => true], 'USD');
assertEquals(['priceMonthly' => '7.99', 'priceYearly' => '0.00', 'currency' => 'USD'], $apSwitch['values'], 'mirror: the main currency changed - monthly copied, no yearly = 0.00');
$apSwitch = adminMirrorDecision($apLegacy, ['monthly' => null, 'yearly' => '79.99', 'hasAnyPrice' => true], 'USD');
assertFalse($apSwitch['change'], 'mirror: a new main currency with no monthly price changes nothing (never a mix of currencies)');
assertContains('still shows', implode(' ', $apSwitch['warnings']), 'mirror: ...and says so');

// ==============================================================================
// ✅ Section 8: the five settings (migration 0004 rows)
// ==============================================================================
$apRegistry = adminSettingsRegistry();
$apNewSettings = [
    'billing_currencies'                   => ['billing', 'string', 'currency_list'],
    'billing_default_currency'             => ['billing', 'string', 'choice'],
    'billing_price_promise_default_mode'   => ['billing', 'string', 'choice'],
    'billing_price_promise_default_months' => ['billing', 'integer', 'int'],
    'pricing_show_prices'                  => ['billing', 'boolean', 'bool'],
];
$apMigration0004 = (string) file_get_contents($apSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '0004_plans_prices_currencies.php');
foreach ($apNewSettings as $apKey => $apShape) {
    assertTrue(isset($apRegistry[$apKey]), $apKey . ' is on the settings screen');
    assertEquals([$apShape[0], $apShape[1], $apShape[2]], [$apRegistry[$apKey]['group'], $apRegistry[$apKey]['type'], $apRegistry[$apKey]['kind']], $apKey . ': group, type and kind');
    assertContains("(\\'" . $apShape[0] . "\\', \\'" . $apKey . "\\'", $apMigration0004, $apKey . ': the same group as migration 0004');
    assertEquals('prices', $apRegistry[$apKey]['section'], $apKey . ' is in the prices section');
}
foreach (array_keys($apRegistry) as $apKey) {
    assertFalse(str_starts_with($apKey, 'billing_') && str_ends_with($apKey, '_enabled') && $apKey !== 'billing_trials_enabled', 'no charging switch is on the settings screen: ' . $apKey);
}

$apValidate = static function (string $apKey, string $apRaw) use ($apRegistry): array {
    return adminSettingsValidateValue($apRegistry[$apKey], $apRaw, []);
};
assertEquals('GBP,USD,EUR', $apValidate('billing_currencies', ' gbp, usd  EUR ')['value'], 'currencies: tidied to GBP,USD,EUR');
foreach (['', 'GBP,GBP', 'GBP,XYZ', 'pounds', 'GB', 'GBP;USD'] as $apBad) {
    assertFalse($apValidate('billing_currencies', $apBad)['ok'], 'currencies refused: ' . var_export($apBad, true));
}
assertTrue($apValidate('billing_default_currency', 'USD')['ok'], 'main currency: a known code');
assertFalse($apValidate('billing_default_currency', 'XYZ')['ok'], 'main currency: an unknown code is refused');
assertFalse($apValidate('billing_default_currency', 'gbp')['ok'], 'main currency: only the listed spelling');
foreach (['while_subscribed', 'fixed_months', 'none'] as $apMode) {
    assertTrue($apValidate('billing_price_promise_default_mode', $apMode)['ok'], 'site-wide promise: ' . $apMode);
}
assertFalse($apValidate('billing_price_promise_default_mode', 'forever')['ok'], 'site-wide promise: unknown refused');
assertTrue($apValidate('billing_price_promise_default_months', '1')['ok'] && $apValidate('billing_price_promise_default_months', '120')['ok'], 'promise months: 1 and 120');
assertFalse($apValidate('billing_price_promise_default_months', '0')['ok'] || $apValidate('billing_price_promise_default_months', '121')['ok'], 'promise months: 0 and 121 refused');
assertEquals('GBP,USD', adminSettingsCanonicalCurrent($apRegistry['billing_currencies'], 'gbp, usd'), 'currencies: a stored list is compared in its tidy form');
assertEquals('legacy_price_mirror', $apRegistry['billing_default_currency']['afterSave'], 'changing the main currency re-copies the pricing page prices');
assertFalse(adminSettingsRunAfterSave('legacy_price_mirror', []), 'the mirror after-save refuses without an admin (its audit rows need one)');
assertTrue(adminSettingsFieldIsRequired($apRegistry['billing_currencies']), 'the currency list box is required');

// 💱 The main currency must be one of the offered ones (both directions).
$apState = static function (string $apValue): array {
    return ['exists' => true, 'hasValue' => true, 'value' => $apValue, 'updatedAt' => null, 'marker' => 'm', 'isSensitive' => false, 'isEditable' => true, 'type' => 'string'];
};
$apCurrent = ['billing_currencies' => $apState('GBP,USD,EUR'), 'billing_default_currency' => $apState('GBP')];
$apOut = adminSettingsProcessSection('prices', ['f' => ['billing_currencies' => 'USD,EUR']], $apCurrent);
assertTrue(isset($apOut['errors']['billing_default_currency']), 'removing the main currency from the list is refused');
$apOut = adminSettingsProcessSection('prices', ['f' => ['billing_currencies' => 'USD,EUR', 'billing_default_currency' => 'USD']], $apCurrent);
assertEquals([], $apOut['errors'], '...but fine together with a new main currency from the list');
$apOut = adminSettingsProcessSection('prices', ['f' => ['billing_default_currency' => 'JPY']], $apCurrent);
assertTrue(isset($apOut['errors']['billing_default_currency']), 'a main currency that is not offered is refused');
$apOut = adminSettingsProcessSection('prices', ['f' => ['billing_vat_mode' => 'exclusive']], ['billing_vat_mode' => $apState('inclusive')]);
assertEquals([], $apOut['errors'], 'a request about something else in the section is not refused because of the currencies');

// 📋 The currency-list reader itself.
assertEquals(['GBP', 'USD'], moneyParseCurrencyList('gbp usd')['codes'], 'moneyParseCurrencyList: spaces work too, order kept');
assertFalse(moneyParseCurrencyList('GBP,gbp')['ok'], 'moneyParseCurrencyList: the same code twice (any case) is refused');
assertFalse(moneyParseCurrencyList(',,')['ok'], 'moneyParseCurrencyList: nothing but commas is refused');

// ==============================================================================
// ✅ Section 9: static guards (each proved against a bad sample first)
// ==============================================================================
$apFunctions = (string) file_get_contents($apSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_admin_plans.php');

/**
 * 🔎 The PHP string and number tokens of a source (comments ignored).
 *
 * @return array<int, array{0: int, 1: string}>
 */
function apLiteralTokens(string $apSource): array
{
    $apFound = [];
    foreach (token_get_all($apSource) as $apToken) {
        if (is_array($apToken) && in_array($apToken[0], [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER, T_ENCAPSED_AND_WHITESPACE], true)) {
            $apFound[] = [$apToken[0], $apToken[1]];
        }
    }
    return $apFound;
}

/**
 * 🏷️ Seeded paid-plan keys and seeded prices written into code, for the
 *    plan editor's own files (tests/test_commercial_literals.php applies the
 *    same rule to the whole site). Returns what was found.
 *
 * @return array<int, string>
 */
function apCommercialLiterals(string $apSource): array
{
    $apKeys = ['pro', 'business', 'enterprise', 'payg', 'pro_lifetime_founders'];
    $apPrices = ['6.99', '49.99', '24.99', '199.99', '119.99', '4.99', '19.99', '59.99', '99.00'];
    $apHits = [];
    foreach (apLiteralTokens($apSource) as $apToken) {
        $apText = $apToken[1];
        if ($apToken[0] === T_CONSTANT_ENCAPSED_STRING) {
            $apText = substr($apText, 1, -1);
        }
        if (in_array($apText, $apKeys, true) || in_array($apText, $apPrices, true)) {
            $apHits[] = $apText;
        }
    }
    return $apHits;
}

assertEquals(['pro', '6.99'], apCommercialLiterals("<?php // pro 6.99 in a comment is fine\n\$a = 'pro'; \$b = 6.99; \$c = 'proper';"), 'literal guard sample: a quoted plan key and a price are caught, comments and longer words are not');

$apSliceFiles = ['_functions' . DIRECTORY_SEPARATOR . 'fn_admin_plans.php'];
foreach (['', 'new', 'edit', 'prices'] as $apPageDir) {
    $apPath = 'public_html' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'plans' . DIRECTORY_SEPARATOR;
    if ($apPageDir !== '') {
        $apPath .= $apPageDir . DIRECTORY_SEPARATOR;
    }
    $apSliceFiles[] = $apPath . 'index.php';
}
foreach ($apSliceFiles as $apFile) {
    $apSource = (string) @file_get_contents($apSiteRoot . $apFile);
    assertTrue($apSource !== '', $apFile . ' exists');
    assertEquals([], apCommercialLiterals($apSource), $apFile . ': no seeded plan key or seeded price written into the code');
    assertFalse((bool) preg_match('/billing_(price_points|discounts|payg)_enabled/', $apSource), $apFile . ': never names a charging switch (so it cannot write one)');
    assertFalse((bool) preg_match('/\(float\)|floatval\(|\(double\)|number_format\(/', $apSource), $apFile . ': no float in the money path');
    // (Full notation - no ??, ?:, ternaries, arrow functions or match - is
    //  checked for every file in the site by tests/test_full_notation.php.)
}

// 💷 Bind types: no 'd' (float) anywhere in the plans code's queries. A bind
//    type is a string of i / s / d / b letters passed as an ARGUMENT (after a
//    comma) or added with .= - so an array key such as ['id'] is not one.
/**
 * 🔎 The bind-type strings of a source.
 *
 * @return array<int, string>
 */
function apBindTypeStrings(string $apSource): array
{
    $apFound = [];
    $apPrevious = '';
    foreach (token_get_all($apSource) as $apToken) {
        if (is_array($apToken) && in_array($apToken[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        if (is_array($apToken) && $apToken[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('/^\'[isdb]+\'$/', $apToken[1]) === 1 && ($apPrevious === ',' || $apPrevious === '.=')) {
            $apFound[] = $apToken[1];
        }
        if (is_array($apToken)) {
            $apPrevious = $apToken[1];
        } else {
            $apPrevious = $apToken;
        }
    }
    return $apFound;
}
assertEquals(["'sd'", "'d'"], apBindTypeStrings("<?php dbQuery('x', 'sd', [\$a]); \$t .= 'd'; \$q['id'];"), 'bind-type guard sample: a float bind type is found, an array key is not');
$apBindTypes = apBindTypeStrings($apFunctions);
assertTrue(count($apBindTypes) >= 8, 'the plans code has bind-type strings to check (' . count($apBindTypes) . ')');
foreach ($apBindTypes as $apBindType) {
    assertFalse(str_contains($apBindType, 'd'), 'no money bound as a float: ' . $apBindType);
}
assertContains("'sssi',", $apFunctions, 'the mirror binds the two prices and the currency as text');

// 🔤 Hand-written SQL: every table back-quoted.
$apSqlStrings = [];
foreach (apLiteralTokens($apFunctions) as $apToken) {
    if ($apToken[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('/tbl[A-Z]/', $apToken[1]) === 1 && str_contains($apToken[1], ' ')) {
        $apSqlStrings[] = $apToken[1];
    }
}
assertTrue(count($apSqlStrings) >= 20, 'the plans code has SQL to check (' . count($apSqlStrings) . ' strings)');
foreach ($apSqlStrings as $apSql) {
    assertFalse((bool) preg_match('/(FROM|INTO|UPDATE|JOIN)\s+tbl/', $apSql), 'SQL has no bare table name: ' . substr($apSql, 0, 60));
    assertFalse((bool) preg_match('/(WHERE|AND|SET|,)\s+[a-z][A-Za-z]+\s*(=|IN\b)/', $apSql), 'SQL has no bare column name: ' . substr($apSql, 0, 60));
}

/**
 * 🧩 The source of one function (from "function NAME(" to the next top-level function).
 */
function apFunctionBody(string $apSource, string $apName): string
{
    $apStart = strpos($apSource, 'function ' . $apName . '(');
    if ($apStart === false) {
        return '';
    }
    $apNext = strpos($apSource, "\nfunction ", $apStart + 10);
    if ($apNext === false) {
        return substr($apSource, $apStart);
    }
    return substr($apSource, $apStart, $apNext - $apStart);
}

// 🔒 Race safety: the counts are locking reads, the loaders lock, every save is one transaction.
assertContains('LOCK IN SHARE MODE', apFunctionBody($apFunctions, 'adminPlansPriceReferences'), 'P1 counts are locking reads (see the newest purchases)');
assertContains('LOCK IN SHARE MODE', apFunctionBody($apFunctions, 'adminPlansUsage'), 'P4 counts are locking reads');
assertContains('LOCK IN SHARE MODE', apFunctionBody($apFunctions, 'adminPlansSoldCount'), 'sales are counted with a locking read');
assertContains('FOR UPDATE', apFunctionBody($apFunctions, 'adminPlansLoadPlan'), 'the plan row can be locked');
assertContains('FOR UPDATE', apFunctionBody($apFunctions, 'adminPlansLoadPrices'), 'the price rows can be locked');
foreach (['adminPlansCreate', 'adminPlansUpdate', 'adminPlansSetOnSale', 'adminPlansDelete', 'adminPlansNewVersion', 'adminPricesSaveGroup', 'adminPricesRetire', 'adminPricesDelete'] as $apSaver) {
    $apBody = apFunctionBody($apFunctions, $apSaver);
    assertContains('adminPlansInTransaction(', $apBody, $apSaver . ' runs in one transaction');
    assertContains('adminPlansAudit(', $apBody, $apSaver . ' writes its audit row in that transaction');
}
foreach (['adminPricesSaveGroup', 'adminPricesRetire', 'adminPricesDelete'] as $apSaver) {
    assertContains('adminPricesLockAndCheck(', apFunctionBody($apFunctions, $apSaver), $apSaver . ' locks the plan and its prices and checks the fingerprint first');
    assertContains('adminMirrorLegacyPlanPrices(', apFunctionBody($apFunctions, $apSaver), $apSaver . ' runs the mirror in the same transaction');
}
assertContains('adminPlansPriceIsFrozen(', apFunctionBody($apFunctions, 'adminPricesDelete'), 'P1: deleting checks the price is not bought');
assertContains('adminPlansDeleteDecision(', apFunctionBody($apFunctions, 'adminPlansDelete'), 'P4: deleting a plan uses the decision');
assertContains('adminPlansProtectedReason(', apFunctionBody($apFunctions, 'adminPlansSetOnSale'), 'P5: taking off sale checks protection');
assertContains('adminPlansProtectedReason(', apFunctionBody($apFunctions, 'adminPlansNewVersion'), 'P5: a new version (which takes the old one off sale) checks protection');
assertContains('adminPricesFindOwn(', apFunctionBody($apFunctions, 'adminPricesRetire'), 'S5: retiring checks the price belongs to the plan');
assertContains('adminPricesFindOwn(', apFunctionBody($apFunctions, 'adminPricesDelete'), 'S5: deleting checks the price belongs to the plan');
assertContains('`planID` = ?', apFunctionBody($apFunctions, 'adminPricesSaveGroup'), 'S5: price updates also name the plan in the WHERE');
assertTrue(adminPricesFindOwn([$apPrice(['pricePointID' => 4])], 4) !== null, 'S5: a price of this plan is found');
assertEquals(null, adminPricesFindOwn([$apPrice(['pricePointID' => 4])], 5), 'S5: a price ID from another plan is not');
assertEquals(null, adminPricesFindOwn([$apPrice(['pricePointID' => 4])], 0), 'S5: ID 0 is never a price');
assertFalse(str_contains($apFunctions, 'roleID'), 'the plans code never touches roleID');
assertFalse((bool) preg_match('/getConfig\(\s*[\'"]billing_default_currency/', $apFunctions), 'the currencies are read fresh from the database, not the request cache');
assertEquals('{}', adminPlansCleanJsonObject('[1,2]'), 'S31: a list is never stored as plan JSON');
assertEquals('{}', adminPlansCleanJsonObject('not json'), 'S31: bad JSON becomes an empty object');
assertEquals('{"a":true,"b":-1}', adminPlansCleanJsonObject('{"a": true, "b": -1}'), 'S31: plan JSON is re-encoded by the server');
foreach (['plan.create', 'plan.update', 'plan.retire', 'plan.restore', 'plan.delete', 'plan.new_version', 'price.create', 'price.update', 'price.retire', 'price.delete'] as $apAction) {
    assertTrue(in_array($apAction, adminAuditActionNames(), true), 'audit action exists: ' . $apAction);
    assertContains("'" . $apAction . "'", $apFunctions, 'the plans code writes ' . $apAction);
}

// 📄 The four pages: gate first (checked for every admin page by
//    test_admin_access.php), every POST checked with a FRESH password, every
//    form carries the CSRF field, nothing saved while upgrades wait, a
//    redirect after a save, no inline script, no layout table, the one admin
//    script file, and the plan functions loaded only after the gate.
foreach (['', 'new', 'edit', 'prices'] as $apPageDir) {
    $apPath = $apSiteRoot . 'public_html' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'plans' . DIRECTORY_SEPARATOR;
    $apName = '/admin/plans/';
    if ($apPageDir !== '') {
        $apPath .= $apPageDir . DIRECTORY_SEPARATOR;
        $apName .= $apPageDir . '/';
    }
    $apPage = (string) file_get_contents($apPath . 'index.php');
    assertContains("requireGlobalAdminPage('" . $apName . "')", $apPage, $apName . ': gate with its own path');
    assertTrue(strpos($apPage, 'requireGlobalAdminPage(') < strpos($apPage, "fn_admin_plans.php'"), $apName . ': the plan functions load only after the gate');
    assertFalse((bool) preg_match('/<script\b/i', $apPage), $apName . ': no inline <script>');
    assertFalse((bool) preg_match('/\son[a-z]+\s*=\s*["\']/i', $apPage), $apName . ': no inline on...= handlers');
    assertFalse((bool) preg_match('/<table\b/i', $apPage), $apName . ': no <table> (layout uses cards and lists)');
    assertContains("'/assets/js/cuercode-admin.js'", $apPage, $apName . ': the one admin script file');
    assertContains('$pageNoIndex     = true;', $apPage, $apName . ': not indexed by search engines');
    assertEquals(substr_count(strtolower($apPage), 'method="post"'), substr_count($apPage, 'csrfField()'), $apName . ': every form carries the CSRF field');
    assertFalse((bool) preg_match('/echo\s+\$(?!adminUser)[a-zA-Z]+\[/', $apPage), $apName . ': no array value echoed without escaping');
    if ($apPageDir !== '') {
        assertContains('adminCheckPost($adminUser, true)', $apPage, $apName . ': every POST needs a fresh password confirmation');
        assertTrue(strpos($apPage, 'adminCheckPost(') < strpos($apPage, '$_POST)'), $apName . ': the POST is checked BEFORE anything reads the form');
        assertContains('adminDatabaseReadyForChanges()', $apPage, $apName . ': nothing is saved while upgrades are waiting');
        assertContains('adminRedirect(', $apPage, $apName . ': a successful save redirects (a reload cannot re-send it)');
    } else {
        assertEquals(0, substr_count(strtolower($apPage), 'method="post"'), $apName . ': the list page has no saving form');
    }
}
$apNav = (string) file_get_contents($apSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'admin_nav.php');
assertContains("'/admin/plans/'", $apNav, 'the admin menu links to the plans page');
