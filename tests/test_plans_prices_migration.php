<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Migration 0004: plans, prices and currencies (task 7n Phase 2)
 * ==============================================================================
 *
 * Path: tests/test_plans_prices_migration.php
 *
 * Without a database, proves that migration 0004 holds exactly what the plan
 * (.claude/plans/7n-admin-commerce-plan.md §5.3-5.5, renumbered from 0003)
 * asks for, and nothing more:
 *   1. it passes every runner check and has the expected steps, in order;
 *   2. the new columns have the planned types, defaults and positions;
 *   3. the five settings start at the planned values (pricing_show_prices
 *      = 1, owner answer Q2 = A, 2026-11-26);
 *   4. the Enterprise plan is a copy of Business, made in a way that cannot
 *      fill an empty plans table (review finding F1);
 *   5. the draft GBP price points and the two "only while still the
 *      placeholder" price moves;
 *   6. what it must NOT do: touch the charging switches, overwrite anything,
 *      or change the frozen install.sql / seed_data.sql.
 * The real SQL on real servers (fresh install, upgrade from 0003, owner edits
 * kept, the empty-database path) is in tests/integration/migrations_real_mysql.php.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-26 (task 7n Phase 2, slice 2b - #31)
 */

requireFn('fn_migrations.php');

$ppSiteRoot = CUERCODE_FUNCTIONS_DIR . '..' . DIRECTORY_SEPARATOR;
$ppSqlDir   = $ppSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR;
$ppPath     = $ppSqlDir . 'migrations' . DIRECTORY_SEPARATOR . '0004_plans_prices_currencies.php';

// ==============================================================================
// ✅ Section 1: it loads, passes every runner check, and comes after 0003
// ==============================================================================
assertTrue(is_file($ppPath), 'migration 0004 exists');
$ppLoaded = migrationLoadFile($ppPath);
assertTrue($ppLoaded['ok'] === true, 'migration 0004 loads');
assertEquals([], migrationValidateDefinition($ppLoaded['definition'], '0004_plans_prices_currencies'), 'migration 0004 passes every runner check (additive, one thing per step, back-quoted, insert-if-missing endings, F1 rule)');
$ppAll = migrationLoadAll(migrationDefaultDirectory());
assertEquals(
    ['0001_subscription_price_snapshot_columns', '0002_admin_audit_log_and_settings', '0003_sessions_valid_from', '0004_plans_prices_currencies'],
    array_slice(array_keys($ppAll['migrations']), 0, 4),
    'migration 0004 is the next number after 0003 (the plan\'s "0003" is renumbered)'
);

$ppSteps   = $ppLoaded['definition']['steps'];
$ppSummary = [];
foreach ($ppSteps as $ppStep) {
    if (isset($ppStep['name'])) {
        $ppSummary[] = $ppStep['type'] . ':' . $ppStep['table'] . '.' . $ppStep['name'];
    } else {
        $ppSummary[] = $ppStep['type'] . ':' . $ppStep['table'] . ' (' . $ppStep['label'] . ')';
    }
}
assertEquals(
    [
        'add_column:tblSubscriptionPlans.planKind',
        'add_column:tblSubscriptionPlans.ctaMode',
        'add_column:tblSubscriptionPlans.contactUrl',
        'add_column:tblSubscriptionPlans.availableFrom',
        'add_column:tblSubscriptionPlans.availableUntil',
        'add_column:tblSubscriptionPlans.maxSales',
        'add_column:tblSubscriptionPlans.basedOnPlanID',
        'add_column:tblSubscriptionPlans.pricePromiseMode',
        'add_column:tblSubscriptionPlans.pricePromiseMonths',
        'add_index:tblSubscriptionPlans.idx_plan_based_on',
        'add_foreign_key:tblSubscriptionPlans.fk_plan_based_on',
        'add_index:tblSubscriptionPlans.idx_plan_kind_active',
        'add_column:tblPlanPricePoints.priceGroupKey',
        'add_index:tblPlanPricePoints.idx_pricepoint_plan_group',
        'add_column:tblSubscriptions.pricePromiseMode',
        'add_column:tblSubscriptions.priceLockedUntil',
        'insert_if_missing:tblSettings (setting billing_currencies)',
        'insert_if_missing:tblSettings (setting billing_default_currency)',
        'insert_if_missing:tblSettings (setting billing_price_promise_default_mode)',
        'insert_if_missing:tblSettings (setting billing_price_promise_default_months)',
        'insert_if_missing:tblSettings (setting pricing_show_prices)',
        'insert_if_missing:tblSubscriptionPlans (plan enterprise (copy of business))',
        'update_if_unchanged:tblSubscriptionPlans (pro monthly 4.99 to 6.99 (placeholder only))',
        'update_if_unchanged:tblSubscriptionPlans (business monthly 19.99 to 24.99 (placeholder only))',
        'insert_if_missing:tblPlanPricePoints (price point pro monthly_gbp)',
        'insert_if_missing:tblPlanPricePoints (price point pro yearly_gbp)',
        'insert_if_missing:tblPlanPricePoints (price point business monthly_gbp)',
        'insert_if_missing:tblPlanPricePoints (price point business yearly_gbp)',
        'insert_if_missing:tblPlanPricePoints (price point enterprise monthly_gbp)',
    ],
    $ppSummary,
    'migration 0004: the 29 planned steps, one piece each, structure first, then settings, the plan, the price moves, then the price points copied from the moved columns (review finding F5)'
);

/**
 * 🔎 The SQL of the step with this name (structure steps) or label (data steps).
 */
function ppStepSql(array $ppSteps, string $ppWanted): string
{
    foreach ($ppSteps as $ppStep) {
        if ((isset($ppStep['name']) && $ppStep['name'] === $ppWanted) || (isset($ppStep['label']) && $ppStep['label'] === $ppWanted)) {
            return (string) $ppStep['sql'];
        }
    }
    return '';
}

/**
 * 🔎 The SQL of a structure step on a given table (two tables have a
 *    pricePromiseMode column).
 */
function ppStepSqlOn(array $ppSteps, string $ppTable, string $ppName): string
{
    foreach ($ppSteps as $ppStep) {
        if ($ppStep['table'] === $ppTable && isset($ppStep['name']) && $ppStep['name'] === $ppName) {
            return (string) $ppStep['sql'];
        }
    }
    return '';
}

// ==============================================================================
// 🧱 Section 2: the new columns (plan §5.3)
// ==============================================================================
$ppColumnRules = [
    ['tblSubscriptionPlans', 'planKind', 'ENUM(\'standard\',\'lifetime_deal\',\'credit_packs\') NOT NULL DEFAULT \'standard\'', 'AFTER `planKey`'],
    ['tblSubscriptionPlans', 'ctaMode', 'ENUM(\'checkout\',\'contact\') NOT NULL DEFAULT \'checkout\'', 'AFTER `planKind`'],
    ['tblSubscriptionPlans', 'contactUrl', 'VARCHAR(255) NULL', 'AFTER `ctaMode`'],
    ['tblSubscriptionPlans', 'availableFrom', 'DATETIME NULL', 'AFTER `sortOrder`'],
    ['tblSubscriptionPlans', 'availableUntil', 'DATETIME NULL', 'AFTER `availableFrom`'],
    ['tblSubscriptionPlans', 'maxSales', 'INT UNSIGNED NULL', 'AFTER `availableUntil`'],
    ['tblSubscriptionPlans', 'basedOnPlanID', 'INT UNSIGNED NULL', 'AFTER `maxSales`'],
    ['tblSubscriptionPlans', 'pricePromiseMode', 'ENUM(\'while_subscribed\',\'fixed_months\',\'none\') NULL', 'AFTER `basedOnPlanID`'],
    ['tblSubscriptionPlans', 'pricePromiseMonths', 'SMALLINT UNSIGNED NULL', 'AFTER `pricePromiseMode`'],
    ['tblPlanPricePoints', 'priceGroupKey', 'VARCHAR(48) NULL', 'AFTER `priceKey`'],
    ['tblSubscriptions', 'pricePromiseMode', 'ENUM(\'while_subscribed\',\'fixed_months\',\'none\') NULL', 'AFTER `discountID`'],
    ['tblSubscriptions', 'priceLockedUntil', 'DATETIME NULL', 'AFTER `pricePromiseMode`'],
];
foreach ($ppColumnRules as $ppRule) {
    $ppSql = ppStepSqlOn($ppSteps, $ppRule[0], $ppRule[1]);
    assertContains('ADD COLUMN `' . $ppRule[1] . '` ' . $ppRule[2], $ppSql, $ppRule[0] . '.' . $ppRule[1] . ' is ' . $ppRule[2]);
    assertTrue(str_ends_with($ppSql, $ppRule[3]), $ppRule[0] . '.' . $ppRule[1] . ' is placed ' . $ppRule[3]);
    assertContains(' COMMENT \'', $ppSql, $ppRule[0] . '.' . $ppRule[1] . ' has a plain-English column comment');
}
assertEquals('ALTER TABLE `tblSubscriptionPlans` ADD INDEX `idx_plan_kind_active` (`planKind`, `isActive`, `sortOrder`)', ppStepSql($ppSteps, 'idx_plan_kind_active'), 'index idx_plan_kind_active (planKind, isActive, sortOrder)');
assertEquals('ALTER TABLE `tblPlanPricePoints` ADD INDEX `idx_pricepoint_plan_group` (`planID`, `priceGroupKey`)', ppStepSql($ppSteps, 'idx_pricepoint_plan_group'), 'index (planID, priceGroupKey) on the price points');
assertEquals('ALTER TABLE `tblSubscriptionPlans` ADD INDEX `idx_plan_based_on` (`basedOnPlanID`)', ppStepSql($ppSteps, 'idx_plan_based_on'), 'the copied-from column has its own named index, added BEFORE the foreign key');
assertContains('FOREIGN KEY (`basedOnPlanID`) REFERENCES `tblSubscriptionPlans` (`planID`) ON DELETE SET NULL', ppStepSql($ppSteps, 'fk_plan_based_on'), 'copied-from foreign key: deleting the original never deletes the copy (ON DELETE SET NULL)');

// ==============================================================================
// ⚙️ Section 3: the five starting settings (plan §5.4)
// ==============================================================================
$ppSettings = [];
foreach ($ppSteps as $ppStep) {
    if ($ppStep['table'] !== 'tblSettings') {
        continue;
    }
    $ppMatch = [];
    if (preg_match("/VALUES \\('([a-z_]+)', '([a-z_]+)', '([^']*)', '([a-z]+)', ([01]), ([01]), '([^']+)'\\) ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`$/D", $ppStep['sql'], $ppMatch) === 1) {
        $ppSettings[$ppMatch[2]] = ['group' => $ppMatch[1], 'value' => $ppMatch[3], 'type' => $ppMatch[4], 'sensitive' => $ppMatch[5], 'editable' => $ppMatch[6], 'description' => $ppMatch[7]];
    }
}
assertEquals(
    [
        'billing_currencies'                   => ['billing', 'GBP,USD,EUR', 'string'],
        'billing_default_currency'             => ['billing', 'GBP', 'string'],
        'billing_price_promise_default_mode'   => ['billing', 'while_subscribed', 'string'],
        'billing_price_promise_default_months' => ['billing', '12', 'integer'],
        'pricing_show_prices'                  => ['billing', '1', 'boolean'],
    ],
    array_map(static function (array $ppRow): array {
        return [$ppRow['group'], $ppRow['value'], $ppRow['type']];
    }, $ppSettings),
    'the five settings: group, starting value and type as planned; pricing_show_prices starts at 1 (owner answer Q2 = A, show the new draft prices)'
);
foreach ($ppSettings as $ppKey => $ppRow) {
    assertEquals(['0', '1'], [$ppRow['sensitive'], $ppRow['editable']], $ppKey . ': not secret, editable by a Global Admin');
    assertTrue(strlen($ppRow['description']) >= 30, $ppKey . ': has a plain-English description for the settings editor');
}

// ==============================================================================
// 🏢 Section 4: the Enterprise plan (plan §5.5)
// ==============================================================================
$ppEnterprise = ppStepSql($ppSteps, 'plan enterprise (copy of business)');
assertContains('SELECT \'enterprise\', \'standard\', \'contact\', NULL, \'Enterprise\',', $ppEnterprise, 'Enterprise: an ordinary plan with a Contact us button, contact address NULL (= site_email)');
assertContains('\'For organisations that need custom limits, a contract and named support.\'', $ppEnterprise, 'Enterprise: the planned description');
assertContains('99.00, 0.00, \'GBP\', `basePlan`.`featuresJSON`, `basePlan`.`limitsJSON`, 1, 4', $ppEnterprise, 'Enterprise: "from £99 a month", Business\'s features and limits, on sale (isActive 1), fifth on the grid (sortOrder 4)');
assertContains('FROM (SELECT `featuresJSON`, `limitsJSON` FROM `tblSubscriptionPlans` WHERE `planKey` = \'business\') AS `basePlan`', $ppEnterprise, 'Enterprise: copied from the Business row through an inner query (the plain form is ambiguous on MySQL 8.0 and MariaDB 10.6)');
foreach ($ppSteps as $ppStep) {
    if ($ppStep['table'] === 'tblSubscriptionPlans') {
        assertFalse(migrationStepCouldFillEmptySeedTable($ppStep), 'F1: ' . migrationDescribeStep($ppStep) . ' cannot put a row into an empty plans table');
    }
}

// ==============================================================================
// 💷 Section 5: draft GBP price points and the legacy price moves (plan §5.5)
// ==============================================================================
// 💷 Review finding F5: each amount is COPIED from the plan's own column (after
//    the placeholder moves), never written as a fixed draft figure, so an
//    owner-changed price stays the owner's (proven on a real database in
//    tests/integration/migrations_real_mysql.php, scenario N2).
$ppPricePoints = [
    ['pro monthly_gbp', 'pro', 'monthly', 'priceMonthly', '0', '1'],
    ['pro yearly_gbp', 'pro', 'yearly', 'priceYearly', '1', '2'],
    ['business monthly_gbp', 'business', 'monthly', 'priceMonthly', '0', '1'],
    ['business yearly_gbp', 'business', 'yearly', 'priceYearly', '1', '2'],
    ['enterprise monthly_gbp', 'enterprise', 'monthly', 'priceMonthly', '0', '1'],
];
foreach ($ppPricePoints as $ppPoint) {
    $ppSql = ppStepSql($ppSteps, 'price point ' . $ppPoint[0]);
    // priceKey = group + _ + currency in lower case (plan §5.3 convention); the
    // group is also the billing interval here.
    assertContains(
        'SELECT `planID`, \'' . $ppPoint[2] . '_gbp\', \'' . $ppPoint[2] . '\', \'' . $ppPoint[2] . '\', 1, `' . $ppPoint[3] . '`, \'GBP\', ' . $ppPoint[4] . ', 1, ' . $ppPoint[5],
        $ppSql,
        'price point ' . $ppPoint[0] . ': key ' . $ppPoint[2] . '_gbp, group ' . $ppPoint[2] . ', amount copied from ' . $ppPoint[3] . ', recommended=' . $ppPoint[4]
    );
    assertContains('AND `currency` = \'GBP\' AND `' . $ppPoint[3] . '` > 0', $ppSql, 'price point ' . $ppPoint[0] . ': only for a plan priced in GBP with an amount above zero');
    assertFalse((bool) preg_match('/, [0-9]+\.[0-9]{2}, \'GBP\'/', $ppSql), 'price point ' . $ppPoint[0] . ': no fixed draft amount is written (F5)');
    assertContains('FROM `tblSubscriptionPlans` WHERE `planKey` = \'' . $ppPoint[1] . '\'', $ppSql, 'price point ' . $ppPoint[0] . ': attached by looking the plan up by key, never a fixed ID');
    assertTrue(str_ends_with($ppSql, 'ON DUPLICATE KEY UPDATE `priceKey` = `priceKey`'), 'price point ' . $ppPoint[0] . ': an existing price point (perhaps edited by the owner) is left alone');
}
assertContains('WHERE `planKey` = \'enterprise\' AND `ctaMode` = \'contact\'', ppStepSql($ppSteps, 'price point enterprise monthly_gbp'), 'the Enterprise price is only added to the Contact us plan this migration made, never to one the owner made by hand');
assertEquals(
    'UPDATE `tblSubscriptionPlans` SET `priceMonthly` = 6.99 WHERE `planKey` = \'pro\' AND `priceMonthly` = 4.99 AND `currency` = \'GBP\'',
    ppStepSql($ppSteps, 'pro monthly 4.99 to 6.99 (placeholder only)'),
    'Pro monthly moves 4.99 -> 6.99 ONLY while it is still the GBP placeholder'
);
assertEquals(
    'UPDATE `tblSubscriptionPlans` SET `priceMonthly` = 24.99 WHERE `planKey` = \'business\' AND `priceMonthly` = 19.99 AND `currency` = \'GBP\'',
    ppStepSql($ppSteps, 'business monthly 19.99 to 24.99 (placeholder only)'),
    'Business monthly moves 19.99 -> 24.99 ONLY while it is still the GBP placeholder'
);
$ppSeedSql = (string) file_get_contents($ppSqlDir . 'seed_data.sql');
assertContains("('pro', 'Pro', 'For individuals who need dynamic QR codes, analytics, and automation.', 4.99, 49.99, 'GBP',", $ppSeedSql, 'the Pro placeholder the move looks for (4.99) is what seed_data.sql loads, and its yearly price already matches the draft (49.99)');
assertContains("('business', 'Business', 'For teams and organisations that need SSO, roles, and enterprise-scale volume.', 19.99, 199.99, 'GBP',", $ppSeedSql, 'the Business placeholder (19.99) is what seed_data.sql loads, and its yearly price already matches (199.99)');

// ==============================================================================
// 🚫 Section 6: what 0004 must NOT do
// ==============================================================================
$ppAllSql = '';
foreach ($ppSteps as $ppStep) {
    $ppAllSql .= $ppStep['sql'] . PHP_EOL;
}
foreach (['billing_price_points_enabled', 'billing_discounts_enabled', 'billing_payg_enabled', '_enabled'] as $ppSwitch) {
    assertFalse(str_contains($ppAllSql, $ppSwitch), 'charging stays off: no step mentions ' . $ppSwitch);
}
foreach ($ppSteps as $ppStep) {
    assertTrue(in_array($ppStep['type'], ['add_column', 'add_index', 'add_foreign_key', 'insert_if_missing', 'update_if_unchanged'], true), migrationDescribeStep($ppStep) . ': additive step type only');
    if ($ppStep['type'] === 'update_if_unchanged') {
        assertMatches('/ AND `priceMonthly` = [0-9.]+ AND `currency` = \'GBP\'$/D', $ppStep['sql'], migrationDescribeStep($ppStep) . ': only matches the exact old value, so an owner-edited price is never overwritten');
    }
}
$ppInstallSql = (string) file_get_contents($ppSqlDir . 'install.sql');
foreach (['planKind', 'ctaMode', 'contactUrl', 'availableFrom', 'maxSales', 'basedOnPlanID', 'pricePromiseMode', 'pricePromiseMonths', 'priceGroupKey', 'priceLockedUntil'] as $ppColumn) {
    assertFalse(str_contains($ppInstallSql, $ppColumn), 'install.sql (the frozen baseline) is NOT changed: ' . $ppColumn . ' comes only from migration 0004');
}
foreach (['billing_currencies', 'billing_default_currency', 'pricing_show_prices', 'billing_price_promise_default'] as $ppKey) {
    assertFalse(str_contains($ppSeedSql, $ppKey), 'seed_data.sql (frozen) is NOT changed: ' . $ppKey . ' comes only from migration 0004');
}
$ppSource = (string) file_get_contents($ppPath);
assertContains('Path: _includes/sql/migrations/0004_plans_prices_currencies.php', $ppSource, 'migration 0004 header names its own path');
assertContains('@copyright   2025–2026 MWBM Partners Ltd', $ppSource, 'migration 0004 has the house copyright header');
