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
 * S19). Database DECIMAL(10,2) columns are written and read as TEXT
 * ("6.99"), never as a float.
 *
 * 📌 House rules for this file:
 *   - PURE: no database, no settings, no output. Everything is decided from
 *     the arguments alone, so every rule is unit-tested
 *     (tests/test_money.php).
 *   - Currency FACTS only (symbol, number of decimal places, English name).
 *     Which currencies are OFFERED is a commercial choice and lives in the
 *     billing_currencies setting (owner rule 2026-10-05: never hard-code a
 *     commercial rule).
 *   - Rounding is "half up" (0.5 goes up), done ONCE, at the end of a
 *     calculation, with whole-number arithmetic.
 *   - A bad argument from code (a negative price, a percentage over 100%, an
 *     unknown currency) throws \InvalidArgumentException rather than quietly
 *     returning a made-up amount: a wrong price must stop, not ship.
 *     Bad input TYPED BY A PERSON is different: the moneyParse... functions
 *     return null so the form can say what is wrong.
 *
 * 📌 64-bit assumption: the largest amount a person may type is 9,999,999
 *    minor units (99,999.99 in a two-decimal currency) and the largest
 *    percentage is 10,000 basis points (100%), so the biggest product worked
 *    out here is about 10^11, far inside a 64-bit integer (about 9.2 x 10^18).
 *    The overflow checks below use PHP_INT_MAX rather than a fixed number, so
 *    they stay correct on a 32-bit PHP too (they just refuse sooner).
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
 * 🌍 The currencies this code knows how to handle, by ISO 4217 code.
 *
 * These are FACTS about each currency, not commercial choices: the symbol,
 * how many decimal places it uses ("minorUnits"), and its English name.
 * Japanese yen is here, although nobody is offering it, to prove the code
 * copes with a currency that has no decimal places at all.
 *
 * Adding a currency = adding one row here (the facts are fixed by ISO 4217);
 * OFFERING it to customers is then the owner's choice in billing_currencies.
 *
 * @return array<string, array{symbol: string, minorUnits: int, name: string}>
 */
function moneyCurrencyInfo(): array
{
    return [
        'GBP' => ['symbol' => '£', 'minorUnits' => 2, 'name' => 'Pound sterling'],
        'USD' => ['symbol' => '$', 'minorUnits' => 2, 'name' => 'US dollar'],
        'EUR' => ['symbol' => '€', 'minorUnits' => 2, 'name' => 'Euro'],
        'JPY' => ['symbol' => '¥', 'minorUnits' => 0, 'name' => 'Japanese yen'],
    ];
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
 * 📋 Read a list of currency codes such as the billing_currencies setting
 *    ("GBP,USD,EUR") into a tidy list. PURE.
 *
 * Commas and/or spaces separate the codes; each is upper-cased ON PURPOSE
 * here (a person may type "gbp"), must be a code moneyCurrencyInfo() knows,
 * and may appear only once. The order typed is kept (it is the order the
 * admin screens show the currency boxes in).
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

        if (preg_match('/^[A-Z]{3}$/D', $code) !== 1 || !moneyCurrencyKnown($code)) {
            return ['ok' => false, 'codes' => [], 'error' => 'Each currency must be a three-letter code this site knows: ' . implode(', ', array_keys(moneyCurrencyInfo())) . '.'];
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
 * 9,999,999 is 99,999.99 in a two-decimal currency. It is well inside the
 * DECIMAL(10,2) database columns (up to 99,999,999.99) and keeps every
 * calculation here far from integer overflow. The same number of minor
 * units is used for every currency (so ¥9,999,999 for yen), which keeps the
 * overflow reasoning identical everywhere.
 *
 * @return int Maximum typed amount in minor units
 */
function moneyMaxTypedMinor(): int
{
    return 9999999;
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
 * than the currency has ("6.999"), anything above moneyMaxTypedMinor()
 * ("100000.00"), an empty box, and an unknown currency.
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
    if (!moneyCurrencyKnown($currency)) {
        return null;
    }

    $trimmed = trim($input);
    $places  = moneyMinorUnits($currency);

    // 🧩 Up to 7 whole digits (enough for 9,999,999 in a zero-decimal
    //    currency), then the decimal part only if the currency has one.
    if ($places > 0) {
        $pattern = '/^([0-9]{1,7})(?:\.([0-9]{1,' . $places . '}))?$/D';
    } else {
        $pattern = '/^([0-9]{1,7})$/D';
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
 * 🗄️ Turn a DECIMAL(10,2) value read from the database ("6.99", or
 *    "1200.00" for a yen price) into whole minor units.
 *
 * MySQLi hands DECIMAL columns back as TEXT, which is exactly what is wanted:
 * casting it to (float) would bring back the rounding problem this file
 * exists to avoid. The database always gives two decimals, so for a
 * currency with fewer decimal places the extra digits must be zeros
 * ("1200.00" is ¥1200; "1200.50" is refused, because half a yen cannot be
 * charged). Up to 8 whole digits are accepted (the column's own limit), so a
 * value entered by hand above the typing limit is still read correctly
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
    if (preg_match('/^([0-9]{1,8})(?:\.([0-9]{1,2}))?$/D', $stored, $parts) !== 1) {
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
 * 🗄️ Write minor units as the decimal TEXT a DECIMAL(10,2) column is bound
 *    with (699 -> "6.99", 5 -> "0.05", 1200 yen -> "1200").
 *
 * Always bind the result with the 's' (string) type in a prepared statement,
 * never 'd' (float): MySQL then stores exactly these digits.
 *
 * @param int    $minor    Amount in minor units (zero or more)
 * @param string $currency A known ISO 4217 code
 * @return string The amount with a point and the currency's decimal places
 * @throws \InvalidArgumentException For a negative amount or an unknown currency
 */
function moneyMinorToDecimalString(int $minor, string $currency): string
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
 * 💬 Show an amount to people in English style: "£6.99", "$6.99", "€6.99",
 *    "£1,234.50", "¥1,200".
 *
 * The symbol goes first and whole units are grouped in thousands with
 * commas, as in the UK. (Other languages' styles, such as "6,99 €", come with
 * the translation work, #34.) Built from whole numbers and text only - no
 * number_format(), which works on floats.
 *
 * @param int    $minor    Amount in minor units (zero or more)
 * @param string $currency A known ISO 4217 code
 * @return string The formatted amount
 * @throws \InvalidArgumentException For a negative amount or an unknown currency
 */
function moneyFormat(int $minor, string $currency): string
{
    $plain     = moneyMinorToDecimalString($minor, $currency);
    $currencies = moneyCurrencyInfo();
    $symbol    = $currencies[$currency]['symbol'];

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
