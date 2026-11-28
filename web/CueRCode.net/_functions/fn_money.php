<?php
/**
 * ==============================================================================
 * 💷 CueRCode - Money helpers: whole pence and cents only, never floats
 * ==============================================================================
 *
 * Path: _functions/fn_money.php
 *
 * WHAT THIS IS (plain English): every price, discount and "about £x a month"
 * figure the admin screens save or work out goes through these functions.
 * They hold money as a WHOLE NUMBER of the smallest coin ("minor units":
 * pence for pounds, cents for dollars and euros, whole yen for yen), so
 * £6.99 is the integer 699.
 *
 * WHY NOT ORDINARY DECIMAL NUMBERS ("floats"): a computer cannot hold most
 * decimal fractions exactly. 0.1 + 0.2 is 0.30000000000000004, and a price
 * typed as 6.99 can come back as 6.989999... Rounding that the wrong way
 * gives a customer the wrong price. Whole numbers are always exact, so these
 * functions never create a float at any point (plan §8.1, security check
 * S19). Database DECIMAL(15,3) columns are written and read as TEXT
 * ("6.99" written, "6.990" read back), never as a float. (They were
 * DECIMAL(10,2) until upgrade 0006, decision 0042.)
 *
 * 📌 House rules for this file:
 *   - PURE: no database, no settings, no output. Everything is decided from
 *     the arguments alone, so every rule is unit-tested
 *     (tests/test_money.php).
 *   - Currency FACTS only (symbol, number of decimal places, English name),
 *     for every current ISO 4217 currency (moneyCurrencyInfo()). Which
 *     currencies are OFFERED is a commercial choice and lives in the
 *     billing_currencies setting (owner rule 2026-10-05: never hard-code a
 *     commercial rule). Since upgrade 0006 (decision 0042) the price columns
 *     hold three decimal places (moneyStoredDecimalPlaces()), so every
 *     current currency, including the seven three-decimal ones (KWD, BHD,
 *     OMR, JOD, IQD, LYD, TND), can be offered.
 *   - Rounding is "half up" (0.5 goes up), done ONCE, at the end of a
 *     calculation, with whole-number arithmetic.
 *   - A bad argument from code (a negative price, a percentage over 100%, an
 *     unknown currency) throws \InvalidArgumentException rather than quietly
 *     returning a made-up amount: a wrong price must stop, not ship.
 *     Bad input TYPED BY A PERSON is different: the moneyParse... functions
 *     return null so the form can say what is wrong.
 *
 * 📌 64-bit assumption: the largest amount a person may type is
 *    999,999,999,999 minor units (moneyMaxTypedMinor(): 9,999,999,999.99 in a
 *    two-decimal currency, 999,999,999.999 in a three-decimal one,
 *    999,999,999,999 in a zero-decimal one - all within the 12 whole-number
 *    digits of the DECIMAL(15,3) columns) and the largest percentage is
 *    10,000 basis points (100%), so the biggest product worked out here is
 *    about 10^16, far inside a 64-bit integer (about 9.2 x 10^18). On a
 *    32-bit PHP the typing limit drops to 99,999,999 and the overflow checks
 *    below, which use PHP_INT_MAX rather than a fixed number, refuse sooner.
 *
 * @package     CueRCode
 * @subpackage  Billing
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-26 (task 7n Phase 2, slice 2a - #31)
 * @see         .claude/plans/7n-admin-commerce-plan.md §8.1 (money), §8.2 (discount arithmetic)
 * @see         fn_pricing.php - pricingApplyDiscount() (older float version, display tests only)
 * @see         tests/test_money.php
 */

// ==============================================================================
// 🚫 Direct access protection - the same pattern as every other _functions file
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
// 🌍 Currency facts
// ==============================================================================

/**
 * 🌍 Every current currency, by ISO 4217 code.
 *
 * These are FACTS about each currency, not commercial choices: how many
 * decimal places it uses ("minorUnits"), its English name, and the symbol
 * shown before an amount. Which currencies are OFFERED to customers is the
 * Global Admin's choice in the billing_currencies setting (Codex finding 1.1
 * of the 7n Phase 2 review: a valid currency such as CAD must never need a
 * code change before it can be offered).
 *
 * SOURCE: ISO 4217 List One, published 2026-09-17 by SIX, the official
 * maintenance agency (https://www.six-group.com/en/products-services/
 * financial-information/data-standards.html). Every entry with a currency
 * code and a number of decimal places is here, with the official name.
 * Left out on purpose: the fund codes (BOV, CHE, CHW, CLF, COU, MXV, USN,
 * UYI, UYW, XAD - accounting units, not money a customer pays in) and the
 * entries with no decimal places at all (gold, silver and other metals, the
 * IMF and bond-market units, the test code XTS and "no currency" XXX).
 * When ISO 4217 changes (a new currency, a withdrawn one), change this list
 * to match the new edition and the counts in tests/test_money.php.
 *
 * SYMBOLS: only pounds, dollars, euros and yen use a sign ("£6.99"). Every
 * other currency is shown with its code ("CAD 6.99"), because many signs are
 * shared ("$" alone would read as US dollars) and guessing one would mislead.
 *
 * @return array<string, array{symbol: string, minorUnits: int, name: string}>
 *         In alphabetical order of code
 */
function moneyCurrencyInfo(): array
{
    return [
        'AED' => ['symbol' => 'AED', 'minorUnits' => 2, 'name' => 'UAE Dirham'],
        'AFN' => ['symbol' => 'AFN', 'minorUnits' => 2, 'name' => 'Afghani'],
        'ALL' => ['symbol' => 'ALL', 'minorUnits' => 2, 'name' => 'Lek'],
        'AMD' => ['symbol' => 'AMD', 'minorUnits' => 2, 'name' => 'Armenian Dram'],
        'AOA' => ['symbol' => 'AOA', 'minorUnits' => 2, 'name' => 'Kwanza'],
        'ARS' => ['symbol' => 'ARS', 'minorUnits' => 2, 'name' => 'Argentine Peso'],
        'AUD' => ['symbol' => 'AUD', 'minorUnits' => 2, 'name' => 'Australian Dollar'],
        'AWG' => ['symbol' => 'AWG', 'minorUnits' => 2, 'name' => 'Aruban Florin'],
        'AZN' => ['symbol' => 'AZN', 'minorUnits' => 2, 'name' => 'Azerbaijan Manat'],
        'BAM' => ['symbol' => 'BAM', 'minorUnits' => 2, 'name' => 'Convertible Mark'],
        'BBD' => ['symbol' => 'BBD', 'minorUnits' => 2, 'name' => 'Barbados Dollar'],
        'BDT' => ['symbol' => 'BDT', 'minorUnits' => 2, 'name' => 'Taka'],
        'BHD' => ['symbol' => 'BHD', 'minorUnits' => 3, 'name' => 'Bahraini Dinar'],
        'BIF' => ['symbol' => 'BIF', 'minorUnits' => 0, 'name' => 'Burundi Franc'],
        'BMD' => ['symbol' => 'BMD', 'minorUnits' => 2, 'name' => 'Bermudian Dollar'],
        'BND' => ['symbol' => 'BND', 'minorUnits' => 2, 'name' => 'Brunei Dollar'],
        'BOB' => ['symbol' => 'BOB', 'minorUnits' => 2, 'name' => 'Boliviano'],
        'BRL' => ['symbol' => 'BRL', 'minorUnits' => 2, 'name' => 'Brazilian Real'],
        'BSD' => ['symbol' => 'BSD', 'minorUnits' => 2, 'name' => 'Bahamian Dollar'],
        'BTN' => ['symbol' => 'BTN', 'minorUnits' => 2, 'name' => 'Ngultrum'],
        'BWP' => ['symbol' => 'BWP', 'minorUnits' => 2, 'name' => 'Pula'],
        'BYN' => ['symbol' => 'BYN', 'minorUnits' => 2, 'name' => 'Belarusian Ruble'],
        'BZD' => ['symbol' => 'BZD', 'minorUnits' => 2, 'name' => 'Belize Dollar'],
        'CAD' => ['symbol' => 'CAD', 'minorUnits' => 2, 'name' => 'Canadian Dollar'],
        'CDF' => ['symbol' => 'CDF', 'minorUnits' => 2, 'name' => 'Congolese Franc'],
        'CHF' => ['symbol' => 'CHF', 'minorUnits' => 2, 'name' => 'Swiss Franc'],
        'CLP' => ['symbol' => 'CLP', 'minorUnits' => 0, 'name' => 'Chilean Peso'],
        'CNY' => ['symbol' => 'CNY', 'minorUnits' => 2, 'name' => 'Yuan Renminbi'],
        'COP' => ['symbol' => 'COP', 'minorUnits' => 2, 'name' => 'Colombian Peso'],
        'CRC' => ['symbol' => 'CRC', 'minorUnits' => 2, 'name' => 'Costa Rican Colon'],
        'CUP' => ['symbol' => 'CUP', 'minorUnits' => 2, 'name' => 'Cuban Peso'],
        'CVE' => ['symbol' => 'CVE', 'minorUnits' => 2, 'name' => 'Cabo Verde Escudo'],
        'CZK' => ['symbol' => 'CZK', 'minorUnits' => 2, 'name' => 'Czech Koruna'],
        'DJF' => ['symbol' => 'DJF', 'minorUnits' => 0, 'name' => 'Djibouti Franc'],
        'DKK' => ['symbol' => 'DKK', 'minorUnits' => 2, 'name' => 'Danish Krone'],
        'DOP' => ['symbol' => 'DOP', 'minorUnits' => 2, 'name' => 'Dominican Peso'],
        'DZD' => ['symbol' => 'DZD', 'minorUnits' => 2, 'name' => 'Algerian Dinar'],
        'EGP' => ['symbol' => 'EGP', 'minorUnits' => 2, 'name' => 'Egyptian Pound'],
        'ERN' => ['symbol' => 'ERN', 'minorUnits' => 2, 'name' => 'Nakfa'],
        'ETB' => ['symbol' => 'ETB', 'minorUnits' => 2, 'name' => 'Ethiopian Birr'],
        'EUR' => ['symbol' => '€', 'minorUnits' => 2, 'name' => 'Euro'],
        'FJD' => ['symbol' => 'FJD', 'minorUnits' => 2, 'name' => 'Fiji Dollar'],
        'FKP' => ['symbol' => 'FKP', 'minorUnits' => 2, 'name' => 'Falkland Islands Pound'],
        'GBP' => ['symbol' => '£', 'minorUnits' => 2, 'name' => 'Pound Sterling'],
        'GEL' => ['symbol' => 'GEL', 'minorUnits' => 2, 'name' => 'Lari'],
        'GHS' => ['symbol' => 'GHS', 'minorUnits' => 2, 'name' => 'Ghana Cedi'],
        'GIP' => ['symbol' => 'GIP', 'minorUnits' => 2, 'name' => 'Gibraltar Pound'],
        'GMD' => ['symbol' => 'GMD', 'minorUnits' => 2, 'name' => 'Dalasi'],
        'GNF' => ['symbol' => 'GNF', 'minorUnits' => 0, 'name' => 'Guinean Franc'],
        'GTQ' => ['symbol' => 'GTQ', 'minorUnits' => 2, 'name' => 'Quetzal'],
        'GYD' => ['symbol' => 'GYD', 'minorUnits' => 2, 'name' => 'Guyana Dollar'],
        'HKD' => ['symbol' => 'HKD', 'minorUnits' => 2, 'name' => 'Hong Kong Dollar'],
        'HNL' => ['symbol' => 'HNL', 'minorUnits' => 2, 'name' => 'Lempira'],
        'HTG' => ['symbol' => 'HTG', 'minorUnits' => 2, 'name' => 'Gourde'],
        'HUF' => ['symbol' => 'HUF', 'minorUnits' => 2, 'name' => 'Forint'],
        'IDR' => ['symbol' => 'IDR', 'minorUnits' => 2, 'name' => 'Rupiah'],
        'ILS' => ['symbol' => 'ILS', 'minorUnits' => 2, 'name' => 'New Israeli Sheqel'],
        'INR' => ['symbol' => 'INR', 'minorUnits' => 2, 'name' => 'Indian Rupee'],
        'IQD' => ['symbol' => 'IQD', 'minorUnits' => 3, 'name' => 'Iraqi Dinar'],
        'IRR' => ['symbol' => 'IRR', 'minorUnits' => 2, 'name' => 'Iranian Rial'],
        'ISK' => ['symbol' => 'ISK', 'minorUnits' => 0, 'name' => 'Iceland Krona'],
        'JMD' => ['symbol' => 'JMD', 'minorUnits' => 2, 'name' => 'Jamaican Dollar'],
        'JOD' => ['symbol' => 'JOD', 'minorUnits' => 3, 'name' => 'Jordanian Dinar'],
        'JPY' => ['symbol' => '¥', 'minorUnits' => 0, 'name' => 'Yen'],
        'KES' => ['symbol' => 'KES', 'minorUnits' => 2, 'name' => 'Kenyan Shilling'],
        'KGS' => ['symbol' => 'KGS', 'minorUnits' => 2, 'name' => 'Som'],
        'KHR' => ['symbol' => 'KHR', 'minorUnits' => 2, 'name' => 'Riel'],
        'KMF' => ['symbol' => 'KMF', 'minorUnits' => 0, 'name' => 'Comorian Franc'],
        'KPW' => ['symbol' => 'KPW', 'minorUnits' => 2, 'name' => 'North Korean Won'],
        'KRW' => ['symbol' => 'KRW', 'minorUnits' => 0, 'name' => 'Won'],
        'KWD' => ['symbol' => 'KWD', 'minorUnits' => 3, 'name' => 'Kuwaiti Dinar'],
        'KYD' => ['symbol' => 'KYD', 'minorUnits' => 2, 'name' => 'Cayman Islands Dollar'],
        'KZT' => ['symbol' => 'KZT', 'minorUnits' => 2, 'name' => 'Tenge'],
        'LAK' => ['symbol' => 'LAK', 'minorUnits' => 2, 'name' => 'Lao Kip'],
        'LBP' => ['symbol' => 'LBP', 'minorUnits' => 2, 'name' => 'Lebanese Pound'],
        'LKR' => ['symbol' => 'LKR', 'minorUnits' => 2, 'name' => 'Sri Lanka Rupee'],
        'LRD' => ['symbol' => 'LRD', 'minorUnits' => 2, 'name' => 'Liberian Dollar'],
        'LSL' => ['symbol' => 'LSL', 'minorUnits' => 2, 'name' => 'Loti'],
        'LYD' => ['symbol' => 'LYD', 'minorUnits' => 3, 'name' => 'Libyan Dinar'],
        'MAD' => ['symbol' => 'MAD', 'minorUnits' => 2, 'name' => 'Moroccan Dirham'],
        'MDL' => ['symbol' => 'MDL', 'minorUnits' => 2, 'name' => 'Moldovan Leu'],
        'MGA' => ['symbol' => 'MGA', 'minorUnits' => 2, 'name' => 'Malagasy Ariary'],
        'MKD' => ['symbol' => 'MKD', 'minorUnits' => 2, 'name' => 'Denar'],
        'MMK' => ['symbol' => 'MMK', 'minorUnits' => 2, 'name' => 'Kyat'],
        'MNT' => ['symbol' => 'MNT', 'minorUnits' => 2, 'name' => 'Tugrik'],
        'MOP' => ['symbol' => 'MOP', 'minorUnits' => 2, 'name' => 'Pataca'],
        'MRU' => ['symbol' => 'MRU', 'minorUnits' => 2, 'name' => 'Ouguiya'],
        'MUR' => ['symbol' => 'MUR', 'minorUnits' => 2, 'name' => 'Mauritius Rupee'],
        'MVR' => ['symbol' => 'MVR', 'minorUnits' => 2, 'name' => 'Rufiyaa'],
        'MWK' => ['symbol' => 'MWK', 'minorUnits' => 2, 'name' => 'Malawi Kwacha'],
        'MXN' => ['symbol' => 'MXN', 'minorUnits' => 2, 'name' => 'Mexican Peso'],
        'MYR' => ['symbol' => 'MYR', 'minorUnits' => 2, 'name' => 'Malaysian Ringgit'],
        'MZN' => ['symbol' => 'MZN', 'minorUnits' => 2, 'name' => 'Mozambique Metical'],
        'NAD' => ['symbol' => 'NAD', 'minorUnits' => 2, 'name' => 'Namibia Dollar'],
        'NGN' => ['symbol' => 'NGN', 'minorUnits' => 2, 'name' => 'Naira'],
        'NIO' => ['symbol' => 'NIO', 'minorUnits' => 2, 'name' => 'Cordoba Oro'],
        'NOK' => ['symbol' => 'NOK', 'minorUnits' => 2, 'name' => 'Norwegian Krone'],
        'NPR' => ['symbol' => 'NPR', 'minorUnits' => 2, 'name' => 'Nepalese Rupee'],
        'NZD' => ['symbol' => 'NZD', 'minorUnits' => 2, 'name' => 'New Zealand Dollar'],
        'OMR' => ['symbol' => 'OMR', 'minorUnits' => 3, 'name' => 'Rial Omani'],
        'PAB' => ['symbol' => 'PAB', 'minorUnits' => 2, 'name' => 'Balboa'],
        'PEN' => ['symbol' => 'PEN', 'minorUnits' => 2, 'name' => 'Sol'],
        'PGK' => ['symbol' => 'PGK', 'minorUnits' => 2, 'name' => 'Kina'],
        'PHP' => ['symbol' => 'PHP', 'minorUnits' => 2, 'name' => 'Philippine Peso'],
        'PKR' => ['symbol' => 'PKR', 'minorUnits' => 2, 'name' => 'Pakistan Rupee'],
        'PLN' => ['symbol' => 'PLN', 'minorUnits' => 2, 'name' => 'Zloty'],
        'PYG' => ['symbol' => 'PYG', 'minorUnits' => 0, 'name' => 'Guarani'],
        'QAR' => ['symbol' => 'QAR', 'minorUnits' => 2, 'name' => 'Qatari Rial'],
        'RON' => ['symbol' => 'RON', 'minorUnits' => 2, 'name' => 'Romanian Leu'],
        'RSD' => ['symbol' => 'RSD', 'minorUnits' => 2, 'name' => 'Serbian Dinar'],
        'RUB' => ['symbol' => 'RUB', 'minorUnits' => 2, 'name' => 'Russian Ruble'],
        'RWF' => ['symbol' => 'RWF', 'minorUnits' => 0, 'name' => 'Rwanda Franc'],
        'SAR' => ['symbol' => 'SAR', 'minorUnits' => 2, 'name' => 'Saudi Riyal'],
        'SBD' => ['symbol' => 'SBD', 'minorUnits' => 2, 'name' => 'Solomon Islands Dollar'],
        'SCR' => ['symbol' => 'SCR', 'minorUnits' => 2, 'name' => 'Seychelles Rupee'],
        'SDG' => ['symbol' => 'SDG', 'minorUnits' => 2, 'name' => 'Sudanese Pound'],
        'SEK' => ['symbol' => 'SEK', 'minorUnits' => 2, 'name' => 'Swedish Krona'],
        'SGD' => ['symbol' => 'SGD', 'minorUnits' => 2, 'name' => 'Singapore Dollar'],
        'SHP' => ['symbol' => 'SHP', 'minorUnits' => 2, 'name' => 'Saint Helena Pound'],
        'SLE' => ['symbol' => 'SLE', 'minorUnits' => 2, 'name' => 'Leone'],
        'SOS' => ['symbol' => 'SOS', 'minorUnits' => 2, 'name' => 'Somali Shilling'],
        'SRD' => ['symbol' => 'SRD', 'minorUnits' => 2, 'name' => 'Surinam Dollar'],
        'SSP' => ['symbol' => 'SSP', 'minorUnits' => 2, 'name' => 'South Sudanese Pound'],
        'STN' => ['symbol' => 'STN', 'minorUnits' => 2, 'name' => 'Dobra'],
        'SVC' => ['symbol' => 'SVC', 'minorUnits' => 2, 'name' => 'El Salvador Colon'],
        'SYP' => ['symbol' => 'SYP', 'minorUnits' => 2, 'name' => 'Syrian Pound'],
        'SZL' => ['symbol' => 'SZL', 'minorUnits' => 2, 'name' => 'Lilangeni'],
        'THB' => ['symbol' => 'THB', 'minorUnits' => 2, 'name' => 'Baht'],
        'TJS' => ['symbol' => 'TJS', 'minorUnits' => 2, 'name' => 'Somoni'],
        'TMT' => ['symbol' => 'TMT', 'minorUnits' => 2, 'name' => 'Turkmenistan New Manat'],
        'TND' => ['symbol' => 'TND', 'minorUnits' => 3, 'name' => 'Tunisian Dinar'],
        'TOP' => ['symbol' => 'TOP', 'minorUnits' => 2, 'name' => 'Pa’anga'],
        'TRY' => ['symbol' => 'TRY', 'minorUnits' => 2, 'name' => 'Turkish Lira'],
        'TTD' => ['symbol' => 'TTD', 'minorUnits' => 2, 'name' => 'Trinidad and Tobago Dollar'],
        'TWD' => ['symbol' => 'TWD', 'minorUnits' => 2, 'name' => 'New Taiwan Dollar'],
        'TZS' => ['symbol' => 'TZS', 'minorUnits' => 2, 'name' => 'Tanzanian Shilling'],
        'UAH' => ['symbol' => 'UAH', 'minorUnits' => 2, 'name' => 'Hryvnia'],
        'UGX' => ['symbol' => 'UGX', 'minorUnits' => 0, 'name' => 'Uganda Shilling'],
        'USD' => ['symbol' => '$', 'minorUnits' => 2, 'name' => 'US Dollar'],
        'UYU' => ['symbol' => 'UYU', 'minorUnits' => 2, 'name' => 'Peso Uruguayo'],
        'UZS' => ['symbol' => 'UZS', 'minorUnits' => 2, 'name' => 'Uzbekistan Sum'],
        'VED' => ['symbol' => 'VED', 'minorUnits' => 2, 'name' => 'Bolívar Soberano'],
        'VES' => ['symbol' => 'VES', 'minorUnits' => 2, 'name' => 'Bolívar Soberano'],
        'VND' => ['symbol' => 'VND', 'minorUnits' => 0, 'name' => 'Dong'],
        'VUV' => ['symbol' => 'VUV', 'minorUnits' => 0, 'name' => 'Vatu'],
        'WST' => ['symbol' => 'WST', 'minorUnits' => 2, 'name' => 'Tala'],
        'XAF' => ['symbol' => 'XAF', 'minorUnits' => 0, 'name' => 'CFA Franc BEAC'],
        'XCD' => ['symbol' => 'XCD', 'minorUnits' => 2, 'name' => 'East Caribbean Dollar'],
        'XCG' => ['symbol' => 'XCG', 'minorUnits' => 2, 'name' => 'Caribbean Guilder'],
        'XOF' => ['symbol' => 'XOF', 'minorUnits' => 0, 'name' => 'CFA Franc BCEAO'],
        'XPF' => ['symbol' => 'XPF', 'minorUnits' => 0, 'name' => 'CFP Franc'],
        'YER' => ['symbol' => 'YER', 'minorUnits' => 2, 'name' => 'Yemeni Rial'],
        'ZAR' => ['symbol' => 'ZAR', 'minorUnits' => 2, 'name' => 'Rand'],
        'ZMW' => ['symbol' => 'ZMW', 'minorUnits' => 2, 'name' => 'Zambian Kwacha'],
        'ZWG' => ['symbol' => 'ZWG', 'minorUnits' => 2, 'name' => 'Zimbabwe Gold'],
    ];
}

/**
 * 🗄️ How many decimal places the database price columns hold.
 *
 * Every price column (tblSubscriptionPlans.priceMonthly / priceYearly,
 * tblPlanPricePoints.amount, tblDiscounts.value and the payment amounts) is
 * DECIMAL(15,3) since upgrade 0006 (decision 0042; before, DECIMAL(10,2),
 * which would have rounded KWD 1.234 to 1.23). A currency with MORE decimal
 * places than this could not have its prices saved exactly, so it could not
 * be offered (moneyCurrencyCanBeOffered()); no current ISO 4217 currency
 * has more than three.
 *
 * @return int Decimal places in the price columns
 */
function moneyStoredDecimalPlaces(): int
{
    return 3;
}

/**
 * ❓ Is this an ISO 4217 code that moneyCurrencyInfo() knows?
 *
 * Exact match only: codes are stored in capitals ('GBP'), so 'gbp' is not
 * accepted here. Callers that take a code from a person should upper-case it
 * first, on purpose, where they can see it.
 *
 * @param string $currency The three-letter code
 * @return bool True when the code is known
 */
function moneyCurrencyKnown(string $currency): bool
{
    return array_key_exists($currency, moneyCurrencyInfo());
}

/**
 * 💱 May prices be set in this currency? A known code whose decimal places
 *    fit the database price columns (moneyStoredDecimalPlaces()).
 *
 * True for every current currency since upgrade 0006 (zero, two and three
 * decimal places: JPY, GBP, KWD ...); false for an unknown code.
 *
 * @param string $currency The three-letter code
 * @return bool True when it may be offered
 */
function moneyCurrencyCanBeOffered(string $currency): bool
{
    return moneyCurrencyKnown($currency) && moneyMinorUnits($currency) <= moneyStoredDecimalPlaces();
}

/**
 * 📋 Read a list of currency codes such as the billing_currencies setting
 *    ("GBP,USD,EUR") into a tidy list. PURE.
 *
 * Commas and/or spaces separate the codes; each is upper-cased ON PURPOSE
 * here (a person may type "gbp"), must be a current ISO 4217 currency that
 * can be offered (moneyCurrencyCanBeOffered()), and may appear only once.
 * The order typed is kept (it is the order the admin screens show the
 * currency boxes in).
 *
 * Added in slice 2c (task 7n Phase 2, #31) for the settings screen and the
 * price editor, which must agree on what an acceptable list is.
 *
 * @param string $text The list as typed or stored
 * @return array{ok: bool, codes: array<int, string>, error: string}
 *         error is plain English, '' when ok; codes is empty when not ok
 */
function moneyParseCurrencyList(string $text): array
{
    $pieces = preg_split('/[\s,]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($pieces) || $pieces === []) {
        return ['ok' => false, 'codes' => [], 'error' => 'Type at least one currency code, for example GBP.'];
    }

    $codes = [];
    foreach ($pieces as $piece) {
        $code = strtoupper($piece);

        // 🔤 Not even three capital letters: say so without repeating the text.
        if (preg_match('/^[A-Z]{3}$/D', $code) !== 1) {
            return ['ok' => false, 'codes' => [], 'error' => 'Each currency must be a three-letter ISO 4217 code, such as GBP, USD, EUR or CAD.'];
        }

        if (!moneyCurrencyKnown($code)) {
            return ['ok' => false, 'codes' => [], 'error' => $code . ' is not a current ISO 4217 currency code. Use codes such as GBP, USD, EUR or CAD.'];
        }

        // 🗄️ Known, but its prices could not be saved exactly (see moneyStoredDecimalPlaces()).
        if (!moneyCurrencyCanBeOffered($code)) {
            return ['ok' => false, 'codes' => [], 'error' => $code . ' uses ' . moneyMinorUnits($code) . ' decimal places, but prices are saved with ' . moneyStoredDecimalPlaces() . ', so it cannot be offered yet.'];
        }

        if (in_array($code, $codes, true)) {
            return ['ok' => false, 'codes' => [], 'error' => $code . ' is listed twice.'];
        }

        $codes[] = $code;
    }

    return ['ok' => true, 'codes' => $codes, 'error' => ''];
}

/**
 * 🔢 How many decimal places a currency uses (2 for GBP, 0 for JPY).
 *
 * @param string $currency A known ISO 4217 code
 * @return int Number of decimal places
 * @throws \InvalidArgumentException For a code moneyCurrencyInfo() does not know
 */
function moneyMinorUnits(string $currency): int
{
    $currencies = moneyCurrencyInfo();

    if (!array_key_exists($currency, $currencies)) {
        throw new \InvalidArgumentException('Unknown currency code.');
    }

    return $currencies[$currency]['minorUnits'];
}

/**
 * 🔟 Ten to the power of a currency's decimal places (100 for GBP, 1 for
 *    JPY), worked out with whole numbers.
 *
 * @param string $currency A known ISO 4217 code
 * @return int The number of minor units in one whole unit
 */
function moneyMinorPerMajor(string $currency): int
{
    $factor = 1;
    $places = moneyMinorUnits($currency);

    for ($step = 0; $step < $places; $step++) {
        $factor = $factor * 10;
    }

    return $factor;
}

/**
 * 🧱 The largest amount a person may type for a price, in minor units.
 *
 * 999,999,999,999 (decision 0042, #176): 9,999,999,999.99 in a two-decimal
 * currency, 999,999,999.999 in a three-decimal one and 999,999,999,999 in a
 * zero-decimal one. Enough for currencies with very small units (a large
 * yearly price in IDR, VND or LBP runs into the tens of millions); before,
 * the limit was 9,999,999 minor units, which capped rupiah prices at
 * 99,999.99. It never overflows a column: the whole-number part has at most
 * 12 digits, which is what DECIMAL(15,3) holds. It never overflows PHP: the
 * largest sum worked out here, amount x 10,000 + 5,000 (a percentage
 * discount), is about 10^16, far below PHP_INT_MAX on 64-bit PHP (about
 * 9.2 x 10^18). The same number of minor units is used for every currency,
 * which keeps the overflow reasoning identical everywhere. On a 32-bit PHP
 * (not a supported host) it is 99,999,999, which fits that integer.
 *
 * @return int Maximum typed amount in minor units
 */
function moneyMaxTypedMinor(): int
{
    if (PHP_INT_SIZE >= 8) {
        return 999999999999;
    }

    return 99999999;
}

// ==============================================================================
// ⌨️ Reading amounts (typed by a person, or read back from the database)
// ==============================================================================

/**
 * ⌨️ Turn a price typed by a person into whole minor units ("6.99" -> 699).
 *
 * Accepted: optional spaces around it, then digits, optionally followed by a
 * point and up to as many decimals as the currency has ("6", "6.9", "6.99"
 * for pounds; "1200" for yen). Refused (null): commas ("6,99" or "1,000"),
 * a sign ("-1", "+1"), spaces inside (" 6 .99"), exponents ("1e3"), a point
 * with nothing after it ("6."), nothing before it (".99"), more decimals
 * than the currency has ("6.999" for pounds; "1.2345" for dinars), anything
 * above moneyMaxTypedMinor() ("10000000000.00"), an empty box, an unknown
 * currency, and any amount in a currency that cannot be offered (see
 * moneyStoredDecimalPlaces()).
 *
 * Zero ("0" or "0.00") is accepted: it is a valid amount (free). Whether a
 * particular form allows zero is that form's decision.
 *
 * Only the ASCII digits 0-9 count, so look-alike digits from other scripts
 * are refused.
 *
 * @param string $input    What the person typed
 * @param string $currency ISO 4217 code of the price
 * @return int|null Minor units, or null when the text is not an acceptable amount
 */
function moneyParseToMinor(string $input, string $currency): ?int
{
    if (!moneyCurrencyCanBeOffered($currency)) {
        return null;
    }

    $trimmed = trim($input);
    $places  = moneyMinorUnits($currency);

    // 🧩 Up to 12 whole digits (the columns' limit, and enough for
    //    999,999,999,999 in a zero-decimal currency), then the decimal part
    //    only if the currency has one. moneyMaxTypedMinor() then decides.
    if ($places > 0) {
        $pattern = '/^([0-9]{1,12})(?:\.([0-9]{1,' . $places . '}))?$/D';
    } else {
        $pattern = '/^([0-9]{1,12})$/D';
    }

    $parts = [];
    if (preg_match($pattern, $trimmed, $parts) !== 1) {
        return null;
    }

    $fraction = '';
    if (isset($parts[2])) {
        $fraction = $parts[2];
    }

    $minor = moneyDigitsToMinor($parts[1], $fraction, $places);

    if ($minor > moneyMaxTypedMinor()) {
        return null;
    }

    return $minor;
}

/**
 * 🗄️ Turn a DECIMAL value read from the database ("6.990", "1200.000" for
 *    a yen price, "1.234" for a dinar price - or the two-decimal "6.99" of a
 *    database before upgrade 0006) into whole minor units.
 *
 * MySQLi hands DECIMAL columns back as TEXT, which is exactly what is wanted:
 * casting it to (float) would bring back the rounding problem this file
 * exists to avoid. The database gives the column's decimal places (three
 * since upgrade 0006, decision 0042; two before), so for a currency with
 * fewer the extra digits must be zeros ("1200.000" is ¥1200; "1200.500" is
 * refused, because half a yen cannot be charged; "6.990" is £6.99, "6.995"
 * is refused). Up to 12 whole digits are accepted (the column's own limit),
 * so a value entered by hand above the typing limit is still read correctly
 * rather than lost. Negative values are refused (prices are never
 * negative).
 *
 * Not in the plan's §8.1 list: added so the Phase 2 editor can show a saved
 * price without ever turning it into a float.
 *
 * @param string $stored   The column value as MySQLi returned it
 * @param string $currency ISO 4217 code of the row
 * @return int|null Minor units, or null when the value is not a valid stored amount
 */
function moneyStoredDecimalToMinor(string $stored, string $currency): ?int
{
    if (!moneyCurrencyKnown($currency)) {
        return null;
    }

    $parts = [];
    if (preg_match('/^([0-9]{1,12})(?:\.([0-9]{1,3}))?$/D', $stored, $parts) !== 1) {
        return null;
    }

    $places   = moneyMinorUnits($currency);
    $fraction = '';
    if (isset($parts[2])) {
        $fraction = $parts[2];
    }

    // ✂️ Digits beyond the currency's own decimal places must all be zero.
    if (strlen($fraction) > $places) {
        $extra = substr($fraction, $places);
        if (trim($extra, '0') !== '') {
            return null;
        }
        $fraction = substr($fraction, 0, $places);
    }

    return moneyDigitsToMinor($parts[1], $fraction, $places);
}

/**
 * 🧮 Join already-checked whole and decimal digits into minor units, using
 *    whole numbers only. "6" + "9" with 2 places is 690 (the decimal part is
 *    padded on the right: .9 means 90 pence).
 *
 * @param string $wholeDigits    Digits before the point (checked by the caller)
 * @param string $fractionDigits Digits after it, at most $places long (may be empty)
 * @param int    $places         The currency's decimal places
 * @return int Minor units
 */
function moneyDigitsToMinor(string $wholeDigits, string $fractionDigits, int $places): int
{
    $factor = 1;
    for ($step = 0; $step < $places; $step++) {
        $factor = $factor * 10;
    }

    $fractionValue = 0;
    if ($places > 0) {
        $fractionValue = (int) str_pad($fractionDigits, $places, '0', STR_PAD_RIGHT);
    }

    return ((int) $wholeDigits * $factor) + $fractionValue;
}

// ==============================================================================
// 🖨️ Writing amounts (to the database, and for people to read)
// ==============================================================================

/**
 * 🗄️ Write minor units as the decimal TEXT a DECIMAL(15,3) column is bound
 *    with (699 -> "6.99", 5 -> "0.05", 1200 yen -> "1200", 1234 fils -> "1.234").
 *
 * Always bind the result with the 's' (string) type in a prepared statement,
 * never 'd' (float): MySQL then stores exactly these digits.
 *
 * 🛡️ An amount the column cannot hold exactly is refused loudly (a currency
 *    with more decimal places than the columns, of which there are none
 *    today: this only stops a bug).
 *
 * @param int    $minor    Amount in minor units (zero or more)
 * @param string $currency A known ISO 4217 code
 * @return string The amount with a point and the currency's decimal places
 * @throws \InvalidArgumentException For a negative amount, an unknown currency,
 *                                   or an amount the price columns would round
 */
function moneyMinorToDecimalString(int $minor, string $currency): string
{
    $text = moneyMinorToPlainText($minor, $currency);

    // 🔢 Digits beyond the columns' decimal places must all be zero.
    if (!moneyMinorFitsStoredPlaces($minor, moneyMinorUnits($currency), moneyStoredDecimalPlaces())) {
        throw new \InvalidArgumentException('This amount cannot be saved exactly: the price columns hold ' . moneyStoredDecimalPlaces() . ' decimal places.');
    }

    return $text;
}

/**
 * 🧮 Can an amount in minor units be stored exactly in columns with
 *    $storedPlaces decimal places? PURE. Split out so the rule can be tested
 *    with a made-up currency (third-round review, Info 1): no ISO currency
 *    has more decimal places than the columns today.
 *
 * @param int $minor          Amount in minor units (zero or more)
 * @param int $currencyPlaces The currency's decimal places
 * @param int $storedPlaces   The columns' decimal places
 * @return bool True when every digit past $storedPlaces is zero
 */
function moneyMinorFitsStoredPlaces(int $minor, int $currencyPlaces, int $storedPlaces): bool
{
    $extraPlaces = $currencyPlaces - $storedPlaces;
    if ($extraPlaces <= 0) {
        return true;
    }

    $extraFactor = 1;
    for ($step = 0; $step < $extraPlaces; $step++) {
        $extraFactor = $extraFactor * 10;
    }

    return ($minor % $extraFactor) === 0;
}

/**
 * 🔤 Minor units as plain decimal text with the currency's own decimal
 *    places (1234 fils -> "1.234", 699 pence -> "6.99", 1200 yen -> "1200").
 *    Used for showing an amount; moneyMinorToDecimalString() adds the
 *    database check on top.
 *
 * @param int    $minor    Amount in minor units (zero or more)
 * @param string $currency A known ISO 4217 code
 * @return string The amount as text
 * @throws \InvalidArgumentException For a negative amount or an unknown currency
 */
function moneyMinorToPlainText(int $minor, string $currency): string
{
    if ($minor < 0) {
        throw new \InvalidArgumentException('Money amounts here are never negative.');
    }

    $places = moneyMinorUnits($currency);
    $factor = moneyMinorPerMajor($currency);

    $whole = intdiv($minor, $factor);

    if ($places === 0) {
        return (string) $whole;
    }

    $fraction = str_pad((string) ($minor % $factor), $places, '0', STR_PAD_LEFT);

    return (string) $whole . '.' . $fraction;
}

/**
 * 📤 A stored amount as text in its currency's OWN decimal places, for
 *    output such as the account export ("6.990" GBP -> "6.99", "1200.000"
 *    JPY -> "1200", "1.234" KWD -> "1.234"). Decision 0042: the columns
 *    always give three decimal places, which would otherwise show pounds as
 *    "6.990". A value that cannot be read (unknown currency, not a plain
 *    amount, digits the currency cannot have) is returned unchanged, never
 *    rounded or guessed.
 *
 * @param string $stored   The column value as MySQLi returned it
 * @param string $currency ISO 4217 code of the row
 * @return string The amount as text
 */
function moneyStoredDecimalForOutput(string $stored, string $currency): string
{
    $minor = moneyStoredDecimalToMinor($stored, $currency);
    if ($minor === null) {
        return $stored;
    }

    return moneyMinorToPlainText($minor, $currency);
}

/**
 * 💬 Show an amount to people in English style: "£6.99", "$6.99", "€6.99",
 *    "£1,234.50", "¥1,200", "CAD 12.50".
 *
 * The symbol goes first and whole units are grouped in thousands with
 * commas, as in the UK. (Other languages' styles, such as "6,99 €", come with
 * the translation work, #34.) A currency shown by its code ("CAD") is
 * followed by a no-break space, so the code and the amount never end up on
 * different lines. Built from whole numbers and text only - no
 * number_format(), which works on floats.
 *
 * @param int    $minor    Amount in minor units (zero or more)
 * @param string $currency A known ISO 4217 code
 * @return string The formatted amount
 * @throws \InvalidArgumentException For a negative amount or an unknown currency
 */
function moneyFormat(int $minor, string $currency): string
{
    $plain      = moneyMinorToPlainText($minor, $currency);
    $currencies = moneyCurrencyInfo();
    $symbol     = $currencies[$currency]['symbol'];
    if ($symbol === $currency) {
        $symbol .= "\u{00A0}";
    }

    // ✂️ Split "1234.50" into "1234" and ".50".
    $pointAt = strpos($plain, '.');
    if ($pointAt === false) {
        $wholePart = $plain;
        $tail      = '';
    } else {
        $wholePart = substr($plain, 0, $pointAt);
        $tail      = substr($plain, $pointAt);
    }

    // 🔢 Group the whole part in threes from the right: 1234567 -> 1,234,567.
    $grouped = '';
    $length  = strlen($wholePart);
    for ($position = 0; $position < $length; $position++) {
        if ($position > 0 && (($length - $position) % 3) === 0) {
            $grouped .= ',';
        }
        $grouped .= $wholePart[$position];
    }

    return $symbol . $grouped . $tail;
}

// ==============================================================================
// 🎟️ Percentages and discounts
// ==============================================================================

/**
 * ⌨️ Turn a typed percentage into basis points (hundredths of a percent):
 *    "25" -> 2500, "12.5" -> 1250, "100" -> 10000.
 *
 * Accepted: digits with at most two decimals, more than 0 and at most 100.
 * Refused (null): 0, anything over 100, a sign, a "%" sign, commas, spaces
 * inside, exponents, three or more decimals, and an empty box.
 *
 * @param string $input What the person typed
 * @return int|null Basis points (1-10000), or null when not acceptable
 */
function moneyPercentToBasisPoints(string $input): ?int
{
    $parts = [];
    if (preg_match('/^([0-9]{1,3})(?:\.([0-9]{1,2}))?$/D', trim($input), $parts) !== 1) {
        return null;
    }

    $fraction = '';
    if (isset($parts[2])) {
        $fraction = $parts[2];
    }

    $basisPoints = moneyDigitsToMinor($parts[1], $fraction, 2);

    if ($basisPoints <= 0 || $basisPoints > 10000) {
        return null;
    }

    return $basisPoints;
}

/**
 * 🧮 Take a percentage off an amount: what is left after the discount.
 *
 * The discount is minor x basisPoints / 10000, rounded half up ONCE, worked
 * out as intdiv(minor x basisPoints + 5000, 10000) - whole numbers all the
 * way. Examples: 25% off 699 -> discount 175 (174.75 rounded up) -> 524 left;
 * 12.5% off 4999 -> discount 625 (624.875) -> 4374 left. 100% gives 0; 0%
 * gives the amount unchanged. The result is never below zero.
 *
 * @param int $minor       Amount in minor units (zero or more)
 * @param int $basisPoints Discount in hundredths of a percent (0-10000)
 * @return int What is left, in minor units
 * @throws \InvalidArgumentException For a negative amount, basis points
 *                                   outside 0-10000, or an amount so large
 *                                   the sum would overflow
 */
function moneyApplyPercentMinor(int $minor, int $basisPoints): int
{
    if ($minor < 0) {
        throw new \InvalidArgumentException('Money amounts here are never negative.');
    }

    if ($basisPoints < 0 || $basisPoints > 10000) {
        throw new \InvalidArgumentException('A percentage must be between 0 and 100.');
    }

    // 🛡️ minor x 10000 + 5000 must fit in an integer (see the file header).
    if ($minor > intdiv(PHP_INT_MAX - 5000, 10000)) {
        throw new \InvalidArgumentException('The amount is too large to work with safely.');
    }

    $discount = intdiv(($minor * $basisPoints) + 5000, 10000);
    $left     = $minor - $discount;

    if ($left < 0) {
        $left = 0;
    }

    return $left;
}

/**
 * 🧮 Take a fixed amount off: what is left, never below zero.
 *
 * Only call this when the discount and the price are in the SAME currency
 * (plan §8.2: a fixed discount applies only to purchases in its own
 * currency) - this function cannot see currencies, so the caller checks.
 *
 * @param int $minor         Amount in minor units (zero or more)
 * @param int $discountMinor Discount in the same minor units (zero or more)
 * @return int What is left, in minor units
 * @throws \InvalidArgumentException For a negative amount or discount
 */
function moneyApplyFixedMinor(int $minor, int $discountMinor): int
{
    if ($minor < 0 || $discountMinor < 0) {
        throw new \InvalidArgumentException('Money amounts here are never negative.');
    }

    if ($discountMinor >= $minor) {
        return 0;
    }

    return $minor - $discountMinor;
}

/**
 * 📅 A yearly price shown as "about this much a month": the yearly amount
 *    divided by 12, rounded half up (4999 -> 417, shown "about £4.17 a
 *    month").
 *
 * FOR DISPLAY ONLY - nobody is charged this figure.
 *
 * @param int $yearMinor Yearly amount in minor units (zero or more)
 * @return int Monthly equivalent in minor units
 * @throws \InvalidArgumentException For a negative amount, or one so large the sum would overflow
 */
function moneyPerMonthFromYearly(int $yearMinor): int
{
    if ($yearMinor < 0) {
        throw new \InvalidArgumentException('Money amounts here are never negative.');
    }

    if ($yearMinor > PHP_INT_MAX - 6) {
        throw new \InvalidArgumentException('The amount is too large to work with safely.');
    }

    // ➗ Adding half of 12 (6) before dividing rounds half up.
    return intdiv($yearMinor + 6, 12);
}
