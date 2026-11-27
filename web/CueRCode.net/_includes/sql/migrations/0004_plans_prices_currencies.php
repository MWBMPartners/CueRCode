<?php
/**
 * ==============================================================================
 * 🧱 CueRCode - Migration 0004: plans, prices and currencies (task 7n Phase 2)
 * ==============================================================================
 *
 * Path: _includes/sql/migrations/0004_plans_prices_currencies.php
 *
 * WHAT IT DOES (plain English):
 *   1. Gives each plan the extra details the plan editor needs: what kind of
 *      plan it is (an ordinary plan, a lifetime deal or credit packs), whether
 *      its button buys it or says "Contact us" (and where that goes), when it
 *      is on sale and how many may be sold, which plan it was copied from,
 *      and its "price promise" (how long existing customers keep their price).
 *   2. Lets price points be grouped (the GBP, USD and EUR monthly prices of a
 *      plan share the group "monthly"), and lets a subscription remember the
 *      price promise it was sold with (written by checkout later - nothing
 *      writes it yet).
 *   3. Adds five starting settings: which currencies prices can be set in
 *      (GBP, USD, EUR), the main currency (GBP), the site-wide price promise
 *      (for as long as the subscription lasts; 12 months when a fixed period
 *      is chosen), and whether the pricing page shows prices (yes - owner
 *      answer to question Q2, option A, 2026-11-26).
 *   4. Adds the Enterprise plan ("Contact us", a copy of Business's features
 *      and limits for the owner to raise per contract) and the draft GBP
 *      prices: Pro £6.99 a month / £49.99 a year, Business £24.99 / £199.99,
 *      Enterprise from £99 a month. The older single-price columns the public
 *      page reads (charging is off) move from the placeholder £4.99 and
 *      £19.99 ONLY while they still hold exactly those placeholders, and the
 *      GBP price points are then COPIED from those columns (review finding
 *      F5), so a price the owner had already changed stays the owner's price
 *      in the price list too - never the draft.
 *   5. Marks the features the starting Pro and Business descriptions name but
 *      that are not built yet as "coming soon" (owner decision 0022, #161) -
 *      only while each description is still exactly its starting wording.
 *
 * NEVER OVERWRITES THE OWNER'S CHANGES:
 *   - settings, plans and price points are "insert if missing" (ON DUPLICATE
 *     KEY UPDATE `x` = `x` changes nothing when the row is already there);
 *   - the two price moves and the two description changes only match a row
 *     still at its starting value;
 *   - the Enterprise price is only added to an Enterprise plan this migration
 *     created (one whose button is "Contact us"), never to an Enterprise plan
 *     the owner had already made by hand.
 *
 * SAFE BEFORE THE STARTING DATA EXISTS (review finding F1): every row written
 * to tblSubscriptionPlans is COPIED from a row already in it (Enterprise from
 * Business), and every price point is attached by looking its plan up by key.
 * On a brand-new database with no plans yet, these steps change nothing, so
 * the installer still loads seed_data.sql afterwards - and repeats these data
 * steps in the same transaction (migrationDbRunSeedSql(), fn_migrations.php).
 *
 * CHARGING STAYS OFF: this file never touches billing_price_points_enabled,
 * billing_discounts_enabled or billing_payg_enabled. The price points stay
 * unused by the public page until the owner turns price points on.
 *
 * ⚠️ This file is DATA, not code: it only returns an array. It is read by
 *    _functions/fn_migrations.php, which checks every step before it runs.
 *    Never edit it after it has been applied anywhere - put new work in a new
 *    numbered file. (The plan .claude/plans/7n-admin-commerce-plan.md calls
 *    this "migration 0003"; 0003 was taken by the sessions-valid-from change,
 *    so every later migration in the plan is one number higher.)
 *
 * @package     CueRCode
 * @subpackage  Database
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-26 (task 7n Phase 2, slice 2b - #31)
 * @see         ../../../_functions/fn_migrations.php - the runner
 * @see         ../seed_data.sql - the four starting plans this copies from and moves
 * @see         .claude/plans/7n-admin-commerce-plan.md §4.3-4.7, §5.3, §5.4, §5.5
 */

return [
    'key'         => '0004_plans_prices_currencies',
    'description' => 'Adds the plan-editor columns (kind, contact button, sale dates and cap, copied-from, price promise), price-point groups, the subscription price-promise snapshot, the currency and price-promise settings, the Enterprise plan and the draft GBP prices, and marks the unbuilt features in the starting Pro and Business descriptions as coming soon, without overwriting anything the owner has changed',
    'steps'       => [

        // ======================================================================
        // 💳 tblSubscriptionPlans - new columns (plan §5.3)
        // ======================================================================
        // planKind: lifetime deals and credit packs are plans of their own kind
        // (§4.4), so the public grid can show only the ordinary ones.
        [
            'type'  => 'add_column',
            'table' => 'tblSubscriptionPlans',
            'name'  => 'planKind',
            'sql'   => 'ALTER TABLE `tblSubscriptionPlans` ADD COLUMN `planKind` ENUM(\'standard\',\'lifetime_deal\',\'credit_packs\') NOT NULL DEFAULT \'standard\''
                . ' COMMENT \'🧩 What kind of plan: standard (shown on the pricing grid), lifetime_deal (pay once) or credit_packs (pay as you go). Set when the plan is created\''
                . ' AFTER `planKey`',
        ],
        [
            'type'  => 'add_column',
            'table' => 'tblSubscriptionPlans',
            'name'  => 'ctaMode',
            'sql'   => 'ALTER TABLE `tblSubscriptionPlans` ADD COLUMN `ctaMode` ENUM(\'checkout\',\'contact\') NOT NULL DEFAULT \'checkout\''
                . ' COMMENT \'🔘 What the plan button does: checkout (buy it) or contact (a Contact us button)\''
                . ' AFTER `planKind`',
        ],
        [
            'type'  => 'add_column',
            'table' => 'tblSubscriptionPlans',
            'name'  => 'contactUrl',
            'sql'   => 'ALTER TABLE `tblSubscriptionPlans` ADD COLUMN `contactUrl` VARCHAR(255) NULL'
                . ' COMMENT \'✉️ Where Contact us goes: an https address or a mailto: address. NULL = the site_email setting\''
                . ' AFTER `ctaMode`',
        ],
        [
            'type'  => 'add_column',
            'table' => 'tblSubscriptionPlans',
            'name'  => 'availableFrom',
            'sql'   => 'ALTER TABLE `tblSubscriptionPlans` ADD COLUMN `availableFrom` DATETIME NULL'
                . ' COMMENT \'⏰ On sale from this moment (UTC). NULL = no start date\''
                . ' AFTER `sortOrder`',
        ],
        [
            'type'  => 'add_column',
            'table' => 'tblSubscriptionPlans',
            'name'  => 'availableUntil',
            'sql'   => 'ALTER TABLE `tblSubscriptionPlans` ADD COLUMN `availableUntil` DATETIME NULL'
                . ' COMMENT \'⏰ On sale until this moment (UTC). NULL = no end date\''
                . ' AFTER `availableFrom`',
        ],
        [
            'type'  => 'add_column',
            'table' => 'tblSubscriptionPlans',
            'name'  => 'maxSales',
            'sql'   => 'ALTER TABLE `tblSubscriptionPlans` ADD COLUMN `maxSales` INT UNSIGNED NULL'
                . ' COMMENT \'🔢 Most that may ever be sold (lifetime deals). NULL = no cap. Sold so far is counted from purchases, never stored\''
                . ' AFTER `availableUntil`',
        ],
        [
            'type'  => 'add_column',
            'table' => 'tblSubscriptionPlans',
            'name'  => 'basedOnPlanID',
            'sql'   => 'ALTER TABLE `tblSubscriptionPlans` ADD COLUMN `basedOnPlanID` INT UNSIGNED NULL'
                . ' COMMENT \'📋 FK tblSubscriptionPlans - the plan this one was copied from (for information only; later changes to that plan do not flow into this one)\''
                . ' AFTER `maxSales`',
        ],
        [
            'type'  => 'add_column',
            'table' => 'tblSubscriptionPlans',
            'name'  => 'pricePromiseMode',
            'sql'   => 'ALTER TABLE `tblSubscriptionPlans` ADD COLUMN `pricePromiseMode` ENUM(\'while_subscribed\',\'fixed_months\',\'none\') NULL'
                . ' COMMENT \'🤝 How long customers keep the price they signed up at: while_subscribed, fixed_months or none. NULL = the site-wide setting\''
                . ' AFTER `basedOnPlanID`',
        ],
        [
            'type'  => 'add_column',
            'table' => 'tblSubscriptionPlans',
            'name'  => 'pricePromiseMonths',
            'sql'   => 'ALTER TABLE `tblSubscriptionPlans` ADD COLUMN `pricePromiseMonths` SMALLINT UNSIGNED NULL'
                . ' COMMENT \'🤝 Months the price is kept when pricePromiseMode is fixed_months. NULL = the site-wide setting\''
                . ' AFTER `pricePromiseMode`',
        ],

        // 📋 Copied-from link: its own index first (so MySQL does not make one
        //    with a name of its own), then the foreign key. ON DELETE SET NULL:
        //    deleting an unused original never deletes the copy.
        [
            'type'  => 'add_index',
            'table' => 'tblSubscriptionPlans',
            'name'  => 'idx_plan_based_on',
            'sql'   => 'ALTER TABLE `tblSubscriptionPlans` ADD INDEX `idx_plan_based_on` (`basedOnPlanID`)',
        ],
        [
            'type'  => 'add_foreign_key',
            'table' => 'tblSubscriptionPlans',
            'name'  => 'fk_plan_based_on',
            'sql'   => 'ALTER TABLE `tblSubscriptionPlans` ADD CONSTRAINT `fk_plan_based_on` FOREIGN KEY (`basedOnPlanID`)'
                . ' REFERENCES `tblSubscriptionPlans` (`planID`) ON DELETE SET NULL ON UPDATE CASCADE',
        ],

        // 🔎 The public grid and the admin list read plans by kind and status.
        [
            'type'  => 'add_index',
            'table' => 'tblSubscriptionPlans',
            'name'  => 'idx_plan_kind_active',
            'sql'   => 'ALTER TABLE `tblSubscriptionPlans` ADD INDEX `idx_plan_kind_active` (`planKind`, `isActive`, `sortOrder`)',
        ],

        // ======================================================================
        // 💷 tblPlanPricePoints - price groups (plan §5.3, price-key convention)
        // ======================================================================
        // priceGroupKey is written by the editor, never typed: monthly, yearly,
        // lifetime, credits_100 ... and priceKey is the group plus the currency
        // (monthly_gbp, yearly_usd).
        [
            'type'  => 'add_column',
            'table' => 'tblPlanPricePoints',
            'name'  => 'priceGroupKey',
            'sql'   => 'ALTER TABLE `tblPlanPricePoints` ADD COLUMN `priceGroupKey` VARCHAR(48) NULL'
                . ' COMMENT \'🗂️ Groups the same price in different currencies (monthly, yearly, lifetime, credits_100 ...); priceKey is this plus the currency, for example monthly_gbp. Written by the editor\''
                . ' AFTER `priceKey`',
        ],
        [
            'type'  => 'add_index',
            'table' => 'tblPlanPricePoints',
            'name'  => 'idx_pricepoint_plan_group',
            'sql'   => 'ALTER TABLE `tblPlanPricePoints` ADD INDEX `idx_pricepoint_plan_group` (`planID`, `priceGroupKey`)',
        ],

        // ======================================================================
        // 🤝 tblSubscriptions - the price promise a subscription was sold with
        // ======================================================================
        // A snapshot written by checkout later (nothing writes it yet), so a
        // later change to the plan's promise never changes what was promised.
        [
            'type'  => 'add_column',
            'table' => 'tblSubscriptions',
            'name'  => 'pricePromiseMode',
            'sql'   => 'ALTER TABLE `tblSubscriptions` ADD COLUMN `pricePromiseMode` ENUM(\'while_subscribed\',\'fixed_months\',\'none\') NULL'
                . ' COMMENT \'🤝 Snapshot of the price promise when this subscription was bought (written by checkout). NULL = not recorded\''
                . ' AFTER `discountID`',
        ],
        [
            'type'  => 'add_column',
            'table' => 'tblSubscriptions',
            'name'  => 'priceLockedUntil',
            'sql'   => 'ALTER TABLE `tblSubscriptions` ADD COLUMN `priceLockedUntil` DATETIME NULL'
                . ' COMMENT \'🔒 The price is kept until this moment (UTC). NULL with while_subscribed = for as long as the subscription lasts\''
                . ' AFTER `pricePromiseMode`',
        ],

        // ======================================================================
        // ⚙️ Starting settings (plan §5.4) - owner-editable, insert if missing
        // ======================================================================
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting billing_currencies',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'billing\', \'billing_currencies\', \'GBP,USD,EUR\', \'string\', 0, 1, \'Currencies prices can be set in: three-letter codes separated by commas, for example GBP,USD,EUR\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting billing_default_currency',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'billing\', \'billing_default_currency\', \'GBP\', \'string\', 0, 1, \'The main currency (a three-letter code such as GBP). Its prices are the ones the public pricing page shows while charging is off\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting billing_price_promise_default_mode',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'billing\', \'billing_price_promise_default_mode\', \'while_subscribed\', \'string\', 0, 1, \'Price promise for plans that do not set their own: while_subscribed (customers keep their price for as long as they stay subscribed), fixed_months (for a set number of months) or none\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting billing_price_promise_default_months',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'billing\', \'billing_price_promise_default_months\', \'12\', \'integer\', 0, 1, \'How many months customers keep their price when the price promise is fixed_months\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        // 👁️ Owner answer to Q2 (2026-11-26): option A - show the new draft
        //    GBP prices on the pricing page, so this starts at 1.
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting pricing_show_prices',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'billing\', \'pricing_show_prices\', \'1\', \'boolean\', 0, 1, \'Pricing page: 1 = show the prices, 0 = hide the price figures (the plans and what they include still show)\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],

        // ======================================================================
        // 🏢 The Enterprise plan (plan §5.5) - COPIED from Business
        // ======================================================================
        // Features and limits are Business's, for the owner to raise per
        // contract (unlimited bulk would be a load risk). The copy reads
        // Business through an inner query: MySQL 8.0 and MariaDB 10.6 both
        // refuse the plain form as ambiguous (measured 2026-11-26). With no
        // Business plan (a database whose starting data is not loaded yet)
        // nothing is inserted. The legacy monthly column carries the "from"
        // price the Contact us card shows; there is no yearly price.
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSubscriptionPlans',
            'label' => 'plan enterprise (copy of business)',
            'sql'   => 'INSERT INTO `tblSubscriptionPlans` (`planKey`, `planKind`, `ctaMode`, `contactUrl`, `planName`, `description`,'
                . ' `priceMonthly`, `priceYearly`, `currency`, `featuresJSON`, `limitsJSON`, `isActive`, `sortOrder`)'
                . ' SELECT \'enterprise\', \'standard\', \'contact\', NULL, \'Enterprise\','
                . ' \'For organisations that need custom limits, a contract and named support.\','
                . ' 99.00, 0.00, \'GBP\', `basePlan`.`featuresJSON`, `basePlan`.`limitsJSON`, 1, 4'
                . ' FROM (SELECT `featuresJSON`, `limitsJSON` FROM `tblSubscriptionPlans` WHERE `planKey` = \'business\') AS `basePlan`'
                . ' ON DUPLICATE KEY UPDATE `planKey` = `planKey`',
        ],

        // ======================================================================
        // 🔁 Legacy single-price columns - ONLY while still at the placeholder
        // ======================================================================
        // The public page reads these while charging is off. Yearly prices
        // already match the drafts (£49.99, £199.99). An owner-changed price
        // (or a different currency) no longer matches, so it is left alone.
        // ⚠️ These two steps run BEFORE the price points below (review finding
        //    F5): the price points are copied FROM these columns, so the price
        //    list always agrees with what the pricing page showed.
        [
            'type'  => 'update_if_unchanged',
            'table' => 'tblSubscriptionPlans',
            'label' => 'pro monthly 4.99 to 6.99 (placeholder only)',
            'sql'   => 'UPDATE `tblSubscriptionPlans` SET `priceMonthly` = 6.99'
                . ' WHERE `planKey` = \'pro\' AND `priceMonthly` = 4.99 AND `currency` = \'GBP\'',
        ],
        [
            'type'  => 'update_if_unchanged',
            'table' => 'tblSubscriptionPlans',
            'label' => 'business monthly 19.99 to 24.99 (placeholder only)',
            'sql'   => 'UPDATE `tblSubscriptionPlans` SET `priceMonthly` = 24.99'
                . ' WHERE `planKey` = \'business\' AND `priceMonthly` = 19.99 AND `currency` = \'GBP\'',
        ],

        // ======================================================================
        // 💷 GBP price points (plan §5.5) - COPIED from the columns above
        // ======================================================================
        // Review finding F5 (7n Phase 2 review, 2026-11-27): each price point
        // takes its amount FROM the plan's own single-price column, AFTER the
        // placeholder move above - so an untouched plan gets the drafts
        // (£6.99 / £49.99, £24.99 / £199.99) and a plan whose price the owner
        // had already changed gets the OWNER's price. Before, the drafts were
        // inserted whatever the column held, and the next save of any price of
        // that plan copied the draft over the owner's price on the pricing
        // page. Only a plan priced in GBP with an amount above zero gets one.
        // Each is attached by looking its plan up by key, never by a fixed ID.
        // isDefault = 1 marks the recommended (yearly) price, as the plan says.
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblPlanPricePoints',
            'label' => 'price point pro monthly_gbp',
            'sql'   => 'INSERT INTO `tblPlanPricePoints` (`planID`, `priceKey`, `priceGroupKey`, `billingInterval`, `intervalCount`, `amount`, `currency`, `isDefault`, `isActive`, `sortOrder`)'
                . ' SELECT `planID`, \'monthly_gbp\', \'monthly\', \'monthly\', 1, `priceMonthly`, \'GBP\', 0, 1, 1'
                . ' FROM `tblSubscriptionPlans` WHERE `planKey` = \'pro\' AND `currency` = \'GBP\' AND `priceMonthly` > 0'
                . ' ON DUPLICATE KEY UPDATE `priceKey` = `priceKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblPlanPricePoints',
            'label' => 'price point pro yearly_gbp',
            'sql'   => 'INSERT INTO `tblPlanPricePoints` (`planID`, `priceKey`, `priceGroupKey`, `billingInterval`, `intervalCount`, `amount`, `currency`, `isDefault`, `isActive`, `sortOrder`)'
                . ' SELECT `planID`, \'yearly_gbp\', \'yearly\', \'yearly\', 1, `priceYearly`, \'GBP\', 1, 1, 2'
                . ' FROM `tblSubscriptionPlans` WHERE `planKey` = \'pro\' AND `currency` = \'GBP\' AND `priceYearly` > 0'
                . ' ON DUPLICATE KEY UPDATE `priceKey` = `priceKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblPlanPricePoints',
            'label' => 'price point business monthly_gbp',
            'sql'   => 'INSERT INTO `tblPlanPricePoints` (`planID`, `priceKey`, `priceGroupKey`, `billingInterval`, `intervalCount`, `amount`, `currency`, `isDefault`, `isActive`, `sortOrder`)'
                . ' SELECT `planID`, \'monthly_gbp\', \'monthly\', \'monthly\', 1, `priceMonthly`, \'GBP\', 0, 1, 1'
                . ' FROM `tblSubscriptionPlans` WHERE `planKey` = \'business\' AND `currency` = \'GBP\' AND `priceMonthly` > 0'
                . ' ON DUPLICATE KEY UPDATE `priceKey` = `priceKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblPlanPricePoints',
            'label' => 'price point business yearly_gbp',
            'sql'   => 'INSERT INTO `tblPlanPricePoints` (`planID`, `priceKey`, `priceGroupKey`, `billingInterval`, `intervalCount`, `amount`, `currency`, `isDefault`, `isActive`, `sortOrder`)'
                . ' SELECT `planID`, \'yearly_gbp\', \'yearly\', \'yearly\', 1, `priceYearly`, \'GBP\', 1, 1, 2'
                . ' FROM `tblSubscriptionPlans` WHERE `planKey` = \'business\' AND `currency` = \'GBP\' AND `priceYearly` > 0'
                . ' ON DUPLICATE KEY UPDATE `priceKey` = `priceKey`',
        ],
        // 🏢 "From £99/month" - only on the Enterprise plan THIS migration made
        //    (a Contact us plan), again copied from its own monthly column; an
        //    Enterprise plan the owner made by hand before this ran keeps its
        //    own prices.
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblPlanPricePoints',
            'label' => 'price point enterprise monthly_gbp',
            'sql'   => 'INSERT INTO `tblPlanPricePoints` (`planID`, `priceKey`, `priceGroupKey`, `billingInterval`, `intervalCount`, `amount`, `currency`, `isDefault`, `isActive`, `sortOrder`)'
                . ' SELECT `planID`, \'monthly_gbp\', \'monthly\', \'monthly\', 1, `priceMonthly`, \'GBP\', 0, 1, 1'
                . ' FROM `tblSubscriptionPlans` WHERE `planKey` = \'enterprise\' AND `ctaMode` = \'contact\' AND `currency` = \'GBP\' AND `priceMonthly` > 0'
                . ' ON DUPLICATE KEY UPDATE `priceKey` = `priceKey`',
        ],

        // ======================================================================
        // 📝 Starting descriptions: unbuilt features marked "coming soon"
        //    (owner decision 0022, #161)
        // ======================================================================
        // The starting Pro and Business descriptions named features that are
        // not built yet (dynamic QR codes, analytics, automation, team roles,
        // organisation-wide single sign-on). The owner chose to keep naming
        // them, marked as coming soon. ONLY a description still exactly at its
        // starting wording is changed: one an admin has edited is left alone.
        [
            'type'  => 'update_if_unchanged',
            'table' => 'tblSubscriptionPlans',
            'label' => 'pro description: unbuilt features marked coming soon',
            'sql'   => 'UPDATE `tblSubscriptionPlans` SET `description` = \'For individuals who need dynamic QR codes, analytics and automation (all coming soon).\''
                . ' WHERE `planKey` = \'pro\' AND `description` = \'For individuals who need dynamic QR codes, analytics, and automation.\'',
        ],
        [
            'type'  => 'update_if_unchanged',
            'table' => 'tblSubscriptionPlans',
            'label' => 'business description: unbuilt features marked coming soon',
            'sql'   => 'UPDATE `tblSubscriptionPlans` SET `description` = \'For teams and organisations that need enterprise-scale volume, with organisation-wide single sign-on (SSO) and team roles coming soon.\''
                . ' WHERE `planKey` = \'business\' AND `description` = \'For teams and organisations that need SSO, roles, and enterprise-scale volume.\'',
        ],
    ],
];
