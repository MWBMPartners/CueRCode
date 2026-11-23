<?php
/**
 * ==============================================================================
 * 🧱 CueRCode - Migration 0001: price-point and discount "snapshot" columns
 * ==============================================================================
 *
 * Path: _includes/sql/migrations/0001_subscription_price_snapshot_columns.php
 *
 * WHAT IT DOES (plain English): adds three columns that remember which price
 * and which discount a subscription or payment was bought under:
 *   - tblSubscriptions.pricePointID and tblSubscriptions.discountID
 *   - tblPayments.pricePointID
 * plus an index and a foreign-key link for each. All three are empty
 * (NULL) by default, so nothing that exists today changes.
 *
 * WHY IT IS A MIGRATION: these columns (#70) used to be added by two
 * table-change blocks at the end of install.sql. Those blocks failed if
 * install.sql was run a second time ("duplicate column"), which is why
 * install.sql could not be re-run (#113 item 5). They now live here, one
 * step per column / index / foreign key, and each step is skipped when the
 * database already has that piece - so a database built by the OLD
 * install.sql (which already has all of them) simply skips every step.
 *
 * The SQL below is the old install.sql text, split into single steps, with
 * the same column comments, so the finished table is identical to what the
 * old install.sql produced.
 *
 * ⚠️ This file is DATA, not code: it only returns an array. It is read by
 *    _functions/fn_migrations.php, which checks every step before it runs.
 *    Never edit it after it has been applied anywhere - put new work in a new
 *    numbered file (an edited file is reported as "changed", never re-run).
 *
 * @package     CueRCode
 * @subpackage  Database
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-23 (task 7n Phase 1A; columns first added by #70)
 * @see         ../../../_functions/fn_migrations.php - the runner
 * @see         ../install.sql - Tables 20, 21 and 22 (tblSubscriptions, tblPayments, tblPlanPricePoints), Table 23 (tblDiscounts)
 * @see         .claude/plans/7n-admin-commerce-plan.md §5.1, §5.3, §5.6
 */

return [
    'key'         => '0001_subscription_price_snapshot_columns',
    'description' => 'Adds the #70 price-point and discount snapshot columns to tblSubscriptions and tblPayments (moved here from install.sql so install.sql can be run twice)',
    'steps'       => [

        // ----------------------------------------------------------------------
        // 💳 tblSubscriptions.pricePointID - which price point it was bought at
        // ----------------------------------------------------------------------
        [
            'type'  => 'add_column',
            'table' => 'tblSubscriptions',
            'name'  => 'pricePointID',
            'sql'   => 'ALTER TABLE `tblSubscriptions` ADD COLUMN `pricePointID` INT UNSIGNED NULL'
                . ' COMMENT \'🔗 FK tblPlanPricePoints - the specific price point this subscription was purchased under (NULL = legacy priceMonthly/priceYearly checkout, or a manually-granted/comp subscription with no price point)\''
                . ' AFTER `planID`',
        ],

        // ----------------------------------------------------------------------
        // 🏷️ tblSubscriptions.discountID - which discount (if any) was used
        // ----------------------------------------------------------------------
        [
            'type'  => 'add_column',
            'table' => 'tblSubscriptions',
            'name'  => 'discountID',
            'sql'   => 'ALTER TABLE `tblSubscriptions` ADD COLUMN `discountID` INT UNSIGNED NULL'
                . ' COMMENT \'🔗 FK tblDiscounts - the discount (if any) applied at checkout, snapshotted so it survives the discount later expiring/being deactivated\''
                . ' AFTER `pricePointID`',
        ],

        // ----------------------------------------------------------------------
        // 🔎 Indexes on the two new tblSubscriptions columns
        // ----------------------------------------------------------------------
        [
            'type'  => 'add_index',
            'table' => 'tblSubscriptions',
            'name'  => 'idx_subscription_pricepoint',
            'sql'   => 'ALTER TABLE `tblSubscriptions` ADD INDEX `idx_subscription_pricepoint` (`pricePointID`)',
        ],
        [
            'type'  => 'add_index',
            'table' => 'tblSubscriptions',
            'name'  => 'idx_subscription_discount',
            'sql'   => 'ALTER TABLE `tblSubscriptions` ADD INDEX `idx_subscription_discount` (`discountID`)',
        ],

        // ----------------------------------------------------------------------
        // 🔗 Foreign keys: if a price point or discount is ever deleted, the
        //    subscription keeps going and the link just becomes empty (NULL).
        // ----------------------------------------------------------------------
        [
            'type'  => 'add_foreign_key',
            'table' => 'tblSubscriptions',
            'name'  => 'fk_subscription_pricepoint',
            'sql'   => 'ALTER TABLE `tblSubscriptions` ADD CONSTRAINT `fk_subscription_pricepoint` FOREIGN KEY (`pricePointID`)'
                . ' REFERENCES `tblPlanPricePoints` (`pricePointID`)'
                . ' ON DELETE SET NULL'
                . ' ON UPDATE CASCADE',
        ],
        [
            'type'  => 'add_foreign_key',
            'table' => 'tblSubscriptions',
            'name'  => 'fk_subscription_discount',
            'sql'   => 'ALTER TABLE `tblSubscriptions` ADD CONSTRAINT `fk_subscription_discount` FOREIGN KEY (`discountID`)'
                . ' REFERENCES `tblDiscounts` (`discountID`)'
                . ' ON DELETE SET NULL'
                . ' ON UPDATE CASCADE',
        ],

        // ----------------------------------------------------------------------
        // 💷 tblPayments.pricePointID + its index and foreign key
        // ----------------------------------------------------------------------
        [
            'type'  => 'add_column',
            'table' => 'tblPayments',
            'name'  => 'pricePointID',
            'sql'   => 'ALTER TABLE `tblPayments` ADD COLUMN `pricePointID` INT UNSIGNED NULL'
                . ' COMMENT \'🔗 FK tblPlanPricePoints - the price point this payment corresponds to (NULL = legacy priceMonthly/priceYearly billing, no price-point table in use)\''
                . ' AFTER `subscriptionID`',
        ],
        [
            'type'  => 'add_index',
            'table' => 'tblPayments',
            'name'  => 'idx_payment_pricepoint',
            'sql'   => 'ALTER TABLE `tblPayments` ADD INDEX `idx_payment_pricepoint` (`pricePointID`)',
        ],
        [
            'type'  => 'add_foreign_key',
            'table' => 'tblPayments',
            'name'  => 'fk_payment_pricepoint',
            'sql'   => 'ALTER TABLE `tblPayments` ADD CONSTRAINT `fk_payment_pricepoint` FOREIGN KEY (`pricePointID`)'
                . ' REFERENCES `tblPlanPricePoints` (`pricePointID`)'
                . ' ON DELETE SET NULL'
                . ' ON UPDATE CASCADE',
        ],
    ],
];
