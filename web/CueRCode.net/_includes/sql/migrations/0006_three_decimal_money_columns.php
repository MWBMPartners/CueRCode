<?php
/**
 * ==============================================================================
 * 🧱 CueRCode - Migration 0006: money columns hold three decimal places (#176, decision 0042)
 * ==============================================================================
 *
 * Path: _includes/sql/migrations/0006_three_decimal_money_columns.php
 *
 * WHAT IT DOES (plain English): every column that holds an amount of money
 * was DECIMAL(10,2): up to 8 whole-number digits and 2 decimal places. Seven
 * currencies use three decimal places (KWD, BHD, OMR, JOD, IQD, LYD, TND), so
 * their prices could not be saved exactly, and currencies with very small
 * units (for example IDR, VND, LBP) need more whole-number digits. Each
 * column becomes DECIMAL(15,3): up to 12 whole-number digits and 3 decimal
 * places. That is wider in BOTH ways, so every value already stored is kept
 * exactly (6.99 is read back as 6.990, which is the same amount).
 *
 * The six columns (all the money columns there are; the pay-as-you-go cap
 * and the credit ledger count credits, whole numbers, not money, and the
 * VAT rate is a percentage setting):
 *   - tblSubscriptionPlans.priceMonthly and priceYearly (the older
 *     single-price columns the pricing page falls back to);
 *   - tblPlanPricePoints.amount (the price list);
 *   - tblDiscounts.value (a percentage, or a fixed amount off);
 *   - tblPayments.amount and tblOneOffPurchases.amount (what was paid).
 *
 * SAFE TO RE-RUN AND TO FINISH AFTER STOPPING HALF-WAY: each step is a
 * widen_decimal_column step. Before it runs, the runner asks the live
 * database whether the column is already at least this wide (then it is
 * skipped), and whether it is a DECIMAL no wider than the target in either
 * way with the same "may it be empty" (otherwise it is REFUSED and nothing
 * of it runs). So a run that stopped after three columns does the other
 * three next time, and a column someone widened by hand is never narrowed.
 * Each MODIFY repeats the column's NOT NULL, default and description, so
 * nothing else about the column changes.
 *
 * ⚠️ This file is DATA, not code: it only returns an array. It is read by
 *    _functions/fn_migrations.php, which checks every step before it runs.
 *    Never edit it after it has been applied anywhere - put new work in a new
 *    numbered file.
 *
 * @package     CueRCode
 * @subpackage  Database
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (#176, decision 0042)
 * @see         _functions/fn_migrations.php - widen_decimal_column
 * @see         _functions/fn_money.php - moneyStoredDecimalPlaces(), moneyMaxTypedMinor()
 * @see         ../install.sql - Tables 19 (plans), 21 (payments), 22 (price points), 23 (discounts), 24 (one-off purchases)
 */

return [
    'key'         => '0006_three_decimal_money_columns',
    'description' => 'Every money column becomes DECIMAL(15,3) (12 whole digits, 3 decimal places) so three-decimal currencies such as KWD can be priced exactly; only ever wider, so no stored amount changes',
    'steps'       => [
        [
            'type'  => 'widen_decimal_column',
            'table' => 'tblSubscriptionPlans',
            'name'  => 'priceMonthly',
            'sql'   => 'ALTER TABLE `tblSubscriptionPlans` MODIFY COLUMN `priceMonthly` DECIMAL(15,3) NOT NULL DEFAULT 0.000'
                . ' COMMENT \'💷 Monthly price in `currency`, up to 3 decimal places (0 for the anonymous/free tiers) - admin-editable config, not code\'',
        ],
        [
            'type'  => 'widen_decimal_column',
            'table' => 'tblSubscriptionPlans',
            'name'  => 'priceYearly',
            'sql'   => 'ALTER TABLE `tblSubscriptionPlans` MODIFY COLUMN `priceYearly` DECIMAL(15,3) NOT NULL DEFAULT 0.000'
                . ' COMMENT \'💷 Yearly price in `currency`, up to 3 decimal places (0 for the anonymous/free tiers) - admin-editable config, not code\'',
        ],
        [
            'type'  => 'widen_decimal_column',
            'table' => 'tblPlanPricePoints',
            'name'  => 'amount',
            'sql'   => 'ALTER TABLE `tblPlanPricePoints` MODIFY COLUMN `amount` DECIMAL(15,3) NOT NULL'
                . ' COMMENT \'💷 Price in `currency` for this specific price point, up to 3 decimal places\'',
        ],
        [
            'type'  => 'widen_decimal_column',
            'table' => 'tblDiscounts',
            'name'  => 'value',
            'sql'   => 'ALTER TABLE `tblDiscounts` MODIFY COLUMN `value` DECIMAL(15,3) NOT NULL'
                . ' COMMENT \'🔢 The percentage (e.g. 25) or fixed amount (up to 3 decimal places) this discount removes, per `discountType`\'',
        ],
        [
            'type'  => 'widen_decimal_column',
            'table' => 'tblPayments',
            'name'  => 'amount',
            'sql'   => 'ALTER TABLE `tblPayments` MODIFY COLUMN `amount` DECIMAL(15,3) NOT NULL'
                . ' COMMENT \'💷 Amount captured/refunded, denominated in `currency`, up to 3 decimal places\'',
        ],
        [
            'type'  => 'widen_decimal_column',
            'table' => 'tblOneOffPurchases',
            'name'  => 'amount',
            'sql'   => 'ALTER TABLE `tblOneOffPurchases` MODIFY COLUMN `amount` DECIMAL(15,3) NOT NULL'
                . ' COMMENT \'💷 Amount captured/refunded, denominated in `currency`, up to 3 decimal places\'',
        ],
    ],
];
