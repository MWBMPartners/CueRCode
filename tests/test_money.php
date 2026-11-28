<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Money helpers (whole pence and cents, never floats)
 * ==============================================================================
 *
 * Path: tests/test_money.php
 *
 * Proves every rule in _functions/fn_money.php (task 7n Phase 2, plan §8.1):
 *   1. the currency facts (every current ISO 4217 currency, with 0, 2 or 3
 *      decimal places) and which of them can be offered;
 *   2. reading a typed price - what is accepted and what is refused;
 *   3. reading a DECIMAL value back from the database;
 *   4. writing amounts for the database and for people to read;
 *   5. percentages, percentage and fixed discounts (half-up, never negative);
 *   6. the "about £x a month" figure;
 *   7. that no float is ever produced, and the file follows house rules.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-26 (task 7n Phase 2, slice 2a - #31)
 * @see         ../web/CueRCode.net/_functions/fn_money.php
 */

requireFn('fn_money.php');

// ==============================================================================
// 🌍 Section 1: currency facts
// ==============================================================================
$moneyInfo = moneyCurrencyInfo();
// 🌍 Codex finding 1.1 (7n Phase 2 review): every current ISO 4217 currency,
//    not just four. 155 = ISO 4217 List One as published on 2026-09-17, less
//    the fund codes (such as CLF and USN) and the entries with no decimal
//    places at all (gold, silver, the test code XTS, "no currency" XXX).
assertEquals(155, count($moneyInfo), 'currency facts: all 155 current ISO 4217 currencies');
$moneyByPlaces = [0 => 0, 2 => 0, 3 => 0];
foreach ($moneyInfo as $moneyCode => $moneyFacts) {
    if (isset($moneyByPlaces[$moneyFacts['minorUnits']])) {
        $moneyByPlaces[$moneyFacts['minorUnits']]++;
    }
}
assertEquals([0 => 16, 2 => 132, 3 => 7], $moneyByPlaces, 'currency facts: 16 with no decimal places, 132 with two, 7 with three (ISO 4217)');
$moneyCodes = array_keys($moneyInfo);
$moneySorted = $moneyCodes;
sort($moneySorted, SORT_STRING);
assertEquals($moneySorted, $moneyCodes, 'currency facts: in alphabetical order of code (the order the main-currency list shows)');
foreach (['CAD' => 2, 'AUD' => 2, 'CHF' => 2, 'INR' => 2, 'KRW' => 0, 'ISK' => 0, 'CLP' => 0, 'VND' => 0, 'KWD' => 3, 'BHD' => 3, 'OMR' => 3, 'TND' => 3] as $moneyCode => $moneyPlaces) {
    assertEquals($moneyPlaces, moneyMinorUnits($moneyCode), $moneyCode . ' is known, with ' . $moneyPlaces . ' decimal places (ISO 4217)');
}
foreach (['XTS', 'XXX', 'XAU', 'CLF', 'USN', 'ANG', 'HRK'] as $moneyNotCurrency) {
    assertFalse(moneyCurrencyKnown($moneyNotCurrency), $moneyNotCurrency . ' is not offered as a currency (a fund, a metal, a test code or a withdrawn currency)');
}
assertEquals(['£', 2], [$moneyInfo['GBP']['symbol'], $moneyInfo['GBP']['minorUnits']], 'GBP: £ and 2 decimal places');
assertEquals(['$', 2], [$moneyInfo['USD']['symbol'], $moneyInfo['USD']['minorUnits']], 'USD: $ and 2 decimal places');
assertEquals(['€', 2], [$moneyInfo['EUR']['symbol'], $moneyInfo['EUR']['minorUnits']], 'EUR: € and 2 decimal places');
assertEquals(['¥', 0], [$moneyInfo['JPY']['symbol'], $moneyInfo['JPY']['minorUnits']], 'JPY: ¥ and no decimal places');
assertEquals('CAD', $moneyInfo['CAD']['symbol'], 'CAD: shown with its code (no guessed symbol: "$" alone would read as US dollars)');
foreach ($moneyInfo as $moneyCode => $moneyFacts) {
    assertMatches('/^[A-Z]{3}$/D', $moneyCode, $moneyCode . ': a three-capital-letter ISO 4217 code');
    assertTrue(is_string($moneyFacts['name']) && $moneyFacts['name'] !== '' && trim($moneyFacts['name']) === $moneyFacts['name'], $moneyCode . ': has an English name, with no stray spaces');
    assertTrue(in_array($moneyFacts['minorUnits'], [0, 2, 3], true), $moneyCode . ': 0, 2 or 3 decimal places');
}
assertTrue(moneyCurrencyKnown('GBP'), 'GBP is known');
assertTrue(moneyCurrencyKnown('CAD'), 'CAD is known (the example in Codex finding 1.1)');
assertFalse(moneyCurrencyKnown('gbp'), 'lower-case gbp is not accepted (codes are stored in capitals)');
assertFalse(moneyCurrencyKnown('XYZ'), 'an unknown code is not known');
assertFalse(moneyCurrencyKnown(''), 'an empty code is not known');
assertEquals(100, moneyMinorPerMajor('GBP'), '100 pence in a pound');
assertEquals(1, moneyMinorPerMajor('JPY'), '1 minor unit in a yen');
assertEquals(1000, moneyMinorPerMajor('KWD'), '1000 fils in a Kuwaiti dinar');

// 🗄️ The price columns are DECIMAL(10,2): they hold two decimal places, so a
//    three-decimal currency (KWD 1.234) cannot be saved exactly. Such a
//    currency is known (its prices can be READ and shown) but cannot be
//    offered until the columns are widened (reported to the lead).
assertEquals(2, moneyStoredDecimalPlaces(), 'the database price columns hold 2 decimal places');
assertTrue(moneyCurrencyCanBeOffered('GBP') && moneyCurrencyCanBeOffered('CAD') && moneyCurrencyCanBeOffered('JPY'), 'two- and zero-decimal currencies can be offered');
assertFalse(moneyCurrencyCanBeOffered('KWD'), 'a three-decimal currency cannot be offered (its prices could not be saved exactly)');
assertFalse(moneyCurrencyCanBeOffered('XYZ'), 'an unknown code cannot be offered');
$moneyList = moneyParseCurrencyList('GBP,CAD,AUD,JPY');
assertTrue($moneyList['ok'] && $moneyList['codes'] === ['GBP', 'CAD', 'AUD', 'JPY'], 'a currency list with CAD and AUD is accepted (Codex finding 1.1)');
$moneyList = moneyParseCurrencyList('GBP,KWD');
assertFalse($moneyList['ok'], 'a currency list with a three-decimal currency is refused');
assertContains('KWD', $moneyList['error'], '...and the reason names it');
assertContains('three decimal places', $moneyList['error'], '...and says why, in plain words');
$moneyList = moneyParseCurrencyList('GBP,XYZ');
assertFalse($moneyList['ok'], 'a list with an unknown code is refused');
assertContains('XYZ', $moneyList['error'], '...and the reason names the code');
assertTrue(strlen($moneyList['error']) < 200, '...without listing all 155 codes');
assertThrows(static function (): void {
    moneyMinorUnits('XYZ');
}, 'an unknown currency is refused loudly', InvalidArgumentException::class);
assertEquals(9999999, moneyMaxTypedMinor(), 'the typing limit is 9,999,999 minor units (99,999.99)');

// ==============================================================================
// ⌨️ Section 2: reading a typed price
// ==============================================================================
$moneyAccepted = [
    ['6.99', 'GBP', 699],
    ['6.9', 'GBP', 690],
    ['6', 'GBP', 600],
    ['0', 'GBP', 0],
    ['0.00', 'GBP', 0],
    ['0.05', 'GBP', 5],
    [' 6.99 ', 'GBP', 699],
    ["6.99\n", 'GBP', 699],
    ['49.99', 'USD', 4999],
    ['199.99', 'EUR', 19999],
    ['99999.99', 'GBP', 9999999],
    ['007.50', 'GBP', 750],
    ['1200', 'JPY', 1200],
    ['9999999', 'JPY', 9999999],
    ['12.50', 'CAD', 1250],
    ['1500', 'KRW', 1500],
    ['0.5', 'CHF', 50],
];
foreach ($moneyAccepted as $moneyCase) {
    assertEquals($moneyCase[2], moneyParseToMinor($moneyCase[0], $moneyCase[1]), 'typed "' . trim($moneyCase[0]) . '" ' . $moneyCase[1] . ' is ' . $moneyCase[2] . ' minor units');
}

$moneyRefused = [
    ['6.999', 'GBP', 'more decimals than the currency has'],
    ['-1', 'GBP', 'a minus sign'],
    ['+1', 'GBP', 'a plus sign'],
    ['1e3', 'GBP', 'an exponent'],
    ['6,99', 'GBP', 'a decimal comma'],
    ['1,000.00', 'GBP', 'a thousands comma'],
    [' 6 .99', 'GBP', 'a space inside'],
    ['100000.00', 'GBP', 'more than 99,999.99'],
    ['10000000', 'JPY', 'more than 9,999,999 yen'],
    ['', 'GBP', 'an empty box'],
    ['   ', 'GBP', 'only spaces'],
    ['6.', 'GBP', 'a point with nothing after it'],
    ['.99', 'GBP', 'nothing before the point'],
    ['£6.99', 'GBP', 'a currency symbol'],
    ['6.99', 'XYZ', 'an unknown currency'],
    ['6.99', 'gbp', 'a lower-case currency code'],
    ['12.5', 'JPY', 'a decimal in a zero-decimal currency'],
    ['12.5', 'KRW', 'a decimal in another zero-decimal currency'],
    ['1.234', 'KWD', 'a three-decimal currency (it cannot be saved exactly in a two-decimal column)'],
    ['1.5', 'KWD', 'any amount in a three-decimal currency'],
    ['0x1A', 'GBP', 'a hexadecimal number'],
    ['١٢', 'GBP', 'digits from another script'],
    ['6.99abc', 'GBP', 'trailing letters'],
    ['NaN', 'GBP', 'not a number'],
];
foreach ($moneyRefused as $moneyCase) {
    assertEquals(null, moneyParseToMinor($moneyCase[0], $moneyCase[1]), 'typed "' . $moneyCase[0] . '" ' . $moneyCase[1] . ' is refused (' . $moneyCase[2] . ')');
}
assertTrue(is_int(moneyParseToMinor('6.99', 'GBP')), 'a parsed amount is a whole number (int), never a float');

// ==============================================================================
// 🗄️ Section 3: reading a DECIMAL(10,2) value from the database
// ==============================================================================
assertEquals(699, moneyStoredDecimalToMinor('6.99', 'GBP'), 'stored "6.99" is 699 pence');
assertEquals(9900, moneyStoredDecimalToMinor('99.00', 'GBP'), 'stored "99.00" is 9900 pence');
assertEquals(0, moneyStoredDecimalToMinor('0.00', 'GBP'), 'stored "0.00" is 0');
assertEquals(1200, moneyStoredDecimalToMinor('1200.00', 'JPY'), 'stored "1200.00" yen is 1200 (the database always shows two decimals)');
assertEquals(null, moneyStoredDecimalToMinor('1200.50', 'JPY'), 'stored "1200.50" yen is refused (half a yen cannot be charged)');
assertEquals(9999999999, moneyStoredDecimalToMinor('99999999.99', 'GBP'), 'the largest DECIMAL(10,2) value is read correctly (a hand-entered value above the typing limit is not lost)');
assertEquals(null, moneyStoredDecimalToMinor('-1.00', 'GBP'), 'a negative stored value is refused');
assertEquals(null, moneyStoredDecimalToMinor('6.99', 'XYZ'), 'an unknown currency is refused');
assertEquals(null, moneyStoredDecimalToMinor('', 'GBP'), 'an empty value is refused');
assertEquals(null, moneyStoredDecimalToMinor(' 6.99', 'GBP'), 'a stored value is never padded, so padding is refused');
assertEquals(1230, moneyStoredDecimalToMinor('1.23', 'KWD'), 'stored "1.23" Kuwaiti dinars is 1230 fils (a three-decimal price can still be READ exactly)');
assertEquals(1250, moneyStoredDecimalToMinor('12.50', 'CAD'), 'stored "12.50" Canadian dollars is 1250 cents');

// ==============================================================================
// 🖨️ Section 4: writing amounts
// ==============================================================================
assertEquals('6.99', moneyMinorToDecimalString(699, 'GBP'), '699 pence is written "6.99"');
assertEquals('0.05', moneyMinorToDecimalString(5, 'GBP'), '5 pence is written "0.05"');
assertEquals('0.00', moneyMinorToDecimalString(0, 'GBP'), '0 is written "0.00"');
assertEquals('99999.99', moneyMinorToDecimalString(9999999, 'USD'), 'the typing limit is written "99999.99"');
assertEquals('1200', moneyMinorToDecimalString(1200, 'JPY'), '1200 yen is written "1200"');
assertThrows(static function (): void {
    moneyMinorToDecimalString(-1, 'GBP');
}, 'a negative amount is refused loudly', InvalidArgumentException::class);
assertThrows(static function (): void {
    moneyMinorToDecimalString(1, 'XYZ');
}, 'an unknown currency is refused loudly when writing', InvalidArgumentException::class);
assertEquals('1.230', moneyMinorToDecimalString(1230, 'KWD'), '1230 fils is written "1.230" (it fits a two-decimal column exactly)');
assertThrows(static function (): void {
    moneyMinorToDecimalString(1234, 'KWD');
}, '1234 fils is refused loudly: the two-decimal column would round it to 1.23', InvalidArgumentException::class);

assertEquals('£6.99', moneyFormat(699, 'GBP'), 'shown as £6.99');
assertEquals('$6.99', moneyFormat(699, 'USD'), 'shown as $6.99');
assertEquals('€6.99', moneyFormat(699, 'EUR'), 'shown as €6.99');
assertEquals('£0.00', moneyFormat(0, 'GBP'), 'zero shown as £0.00 (the page decides whether to say "Free")');
assertEquals('£999.99', moneyFormat(99999, 'GBP'), 'no comma below a thousand');
assertEquals('£1,234.50', moneyFormat(123450, 'GBP'), 'thousands grouped with a comma');
assertEquals('£99,999.99', moneyFormat(9999999, 'GBP'), 'the typing limit is shown as £99,999.99');
assertEquals('£1,000,000.00', moneyFormat(100000000, 'GBP'), 'millions grouped too');
assertEquals('¥1,200', moneyFormat(1200, 'JPY'), 'yen has no decimal part');
assertEquals('¥5', moneyFormat(5, 'JPY'), 'a small yen amount');
assertEquals("CAD\u{00A0}1,234.50", moneyFormat(123450, 'CAD'), 'a currency without its own symbol here is shown with its code and a space that never breaks across lines');
assertEquals("KRW\u{00A0}15,000", moneyFormat(15000, 'KRW'), 'a zero-decimal currency other than yen');
assertEquals("KWD\u{00A0}1.230", moneyFormat(1230, 'KWD'), 'a three-decimal currency is shown with three decimals');

// 🔁 Round trip: typed -> stored -> read back -> shown, exactly (plan Phase 2 acceptance).
foreach (['6.99', '49.99', '24.99', '199.99', '99.00', '0.01', '99999.99'] as $moneyTyped) {
    $moneyMinor  = moneyParseToMinor($moneyTyped, 'GBP');
    $moneyStored = moneyMinorToDecimalString($moneyMinor, 'GBP');
    assertEquals($moneyTyped, $moneyStored, 'round trip: typed ' . $moneyTyped . ' is stored as exactly ' . $moneyTyped);
    assertEquals($moneyMinor, moneyStoredDecimalToMinor($moneyStored, 'GBP'), 'round trip: ' . $moneyTyped . ' reads back to the same minor units');
}
assertEquals('£6.99', moneyFormat(moneyStoredDecimalToMinor(moneyMinorToDecimalString(moneyParseToMinor('6.99', 'GBP'), 'GBP'), 'GBP'), 'GBP'), 'round trip: typed "6.99" is shown "£6.99"');

// ==============================================================================
// 🎟️ Section 5: percentages and discounts
// ==============================================================================
assertEquals(2500, moneyPercentToBasisPoints('25'), '"25" is 2500 basis points');
assertEquals(1250, moneyPercentToBasisPoints('12.5'), '"12.5" is 1250 basis points');
assertEquals(1255, moneyPercentToBasisPoints('12.55'), '"12.55" is 1255 basis points');
assertEquals(10000, moneyPercentToBasisPoints('100'), '"100" is 10000 basis points');
assertEquals(10000, moneyPercentToBasisPoints('100.00'), '"100.00" is 10000 basis points');
assertEquals(1, moneyPercentToBasisPoints('0.01'), '"0.01" is 1 basis point');
assertEquals(2500, moneyPercentToBasisPoints(' 25 '), 'spaces around are allowed');
foreach (['0', '0.00', '100.01', '101', '-5', '+5', '25%', '12.555', '1e2', '12,5', '', '1 2', 'abc'] as $moneyBadPercent) {
    assertEquals(null, moneyPercentToBasisPoints($moneyBadPercent), 'percentage "' . $moneyBadPercent . '" is refused');
}

assertEquals(524, moneyApplyPercentMinor(699, 2500), '25% off 699: discount 174.75 rounds half up to 175, leaving 524');
assertEquals(4374, moneyApplyPercentMinor(4999, 1250), '12.5% off 4999: discount 624.875 rounds to 625, leaving 4374');
assertEquals(1, moneyApplyPercentMinor(2, 2500), '25% off 2: discount 0.5 rounds UP to 1 (half up), leaving 1');
assertEquals(2, moneyApplyPercentMinor(3, 2500), '25% off 3: discount 0.75 rounds to 1, leaving 2');
assertEquals(4, moneyApplyPercentMinor(4, 1000), '10% off 4: discount 0.4 rounds DOWN to 0, leaving 4');
assertEquals(0, moneyApplyPercentMinor(699, 10000), '100% off leaves 0, never negative');
assertEquals(699, moneyApplyPercentMinor(699, 0), '0% off leaves the amount unchanged');
assertEquals(0, moneyApplyPercentMinor(0, 5000), '50% off nothing is nothing');
assertEquals(4999999, moneyApplyPercentMinor(9999999, 5000), '50% off the typing limit: discount 4,999,999.5 rounds up to 5,000,000, leaving 4,999,999');
assertEquals(1000, moneyApplyPercentMinor(9999999, 9999), '99.99% off the typing limit: discount 9,998,999.0001 rounds to 9,998,999, leaving 1000 (worked by hand)');
assertTrue(PHP_INT_SIZE >= 8, 'this PHP has 64-bit integers (the file header\'s stated assumption; the code itself refuses safely on 32-bit)');
assertThrows(static function (): void {
    moneyApplyPercentMinor(-1, 2500);
}, 'a negative amount is refused loudly', InvalidArgumentException::class);
assertThrows(static function (): void {
    moneyApplyPercentMinor(699, 10001);
}, 'more than 100% is refused loudly', InvalidArgumentException::class);
assertThrows(static function (): void {
    moneyApplyPercentMinor(699, -1);
}, 'a negative percentage is refused loudly', InvalidArgumentException::class);
assertThrows(static function (): void {
    moneyApplyPercentMinor(PHP_INT_MAX, 2500);
}, 'an amount so large the sum would overflow is refused, not wrapped round', InvalidArgumentException::class);

assertEquals(499, moneyApplyFixedMinor(699, 200), '£2 off £6.99 leaves £4.99');
assertEquals(0, moneyApplyFixedMinor(699, 699), 'a discount equal to the price leaves 0');
assertEquals(0, moneyApplyFixedMinor(699, 1000), 'a discount bigger than the price leaves 0, never negative');
assertEquals(699, moneyApplyFixedMinor(699, 0), 'a zero discount changes nothing');
assertThrows(static function (): void {
    moneyApplyFixedMinor(-1, 100);
}, 'a negative amount is refused loudly (fixed)', InvalidArgumentException::class);
assertThrows(static function (): void {
    moneyApplyFixedMinor(699, -100);
}, 'a negative discount is refused loudly (it would add money)', InvalidArgumentException::class);

// ==============================================================================
// 📅 Section 6: "about £x a month" from a yearly price (display only)
// ==============================================================================
assertEquals(417, moneyPerMonthFromYearly(4999), '£49.99 a year is about £4.17 a month (416.58 rounds up)');
assertEquals(1667, moneyPerMonthFromYearly(19999), '£199.99 a year is about £16.67 a month (1666.58)');
assertEquals(100, moneyPerMonthFromYearly(1200), 'exactly divisible: 1200 -> 100');
assertEquals(1, moneyPerMonthFromYearly(6), 'half up: 6/12 = 0.5 rounds to 1');
assertEquals(0, moneyPerMonthFromYearly(5), '5/12 = 0.42 rounds to 0');
assertEquals(0, moneyPerMonthFromYearly(0), 'nothing a year is nothing a month');
assertEquals('£4.17', moneyFormat(moneyPerMonthFromYearly(4999), 'GBP'), 'shown as £4.17');
assertThrows(static function (): void {
    moneyPerMonthFromYearly(-12);
}, 'a negative yearly amount is refused loudly', InvalidArgumentException::class);
assertThrows(static function (): void {
    moneyPerMonthFromYearly(PHP_INT_MAX);
}, 'an amount so large the sum would overflow is refused (per month)', InvalidArgumentException::class);

// ==============================================================================
// 🔒 Section 7: no floats anywhere, and the house rules
// ==============================================================================
// Read the file as PHP tokens, so comments (which talk about floats) do not
// count - only real code does.
$moneySource = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_money.php');
$moneyFloatTokens  = [];
$moneyBannedCalls  = [];
foreach (token_get_all($moneySource) as $moneyToken) {
    if (!is_array($moneyToken)) {
        continue;
    }
    if ($moneyToken[0] === T_DNUMBER) {
        $moneyFloatTokens[] = $moneyToken[1];
    }
    if ($moneyToken[0] === T_STRING && in_array(strtolower($moneyToken[1]), ['floatval', 'round', 'number_format', 'floor', 'ceil', 'fdiv', 'bcadd', 'sprintf', 'printf'], true)) {
        $moneyBannedCalls[] = $moneyToken[1];
    }
    if ($moneyToken[0] === T_DOUBLE_CAST || $moneyToken[0] === T_DIV_EQUAL) {
        $moneyBannedCalls[] = $moneyToken[1];
    }
}
// The plain "/" operator (it can produce a float) is a one-character token.
$moneyDivisions = 0;
foreach (token_get_all($moneySource) as $moneyToken) {
    if ($moneyToken === '/') {
        $moneyDivisions++;
    }
}
assertEquals([], $moneyFloatTokens, 'fn_money.php code contains no decimal-point number at all');
assertEquals([], $moneyBannedCalls, 'fn_money.php code never casts to float or calls a float-based function (round, number_format, floor, ceil, sprintf...)');
assertEquals(0, $moneyDivisions, 'fn_money.php code never uses the "/" operator (it can produce a float); intdiv() is used instead');
assertContains('Path: _functions/fn_money.php', $moneySource, 'fn_money.php header names its own path');
assertContains('@copyright   2025–2026 MWBM Partners Ltd', $moneySource, 'fn_money.php has the house copyright header');
assertFalse(function_exists('dbQuery'), 'fn_money.php needs no database (pure)');

// Every result type is int or string, never float, across the main cases.
$moneyResults = [
    moneyParseToMinor('6.99', 'GBP'),
    moneyStoredDecimalToMinor('6.99', 'GBP'),
    moneyApplyPercentMinor(699, 2500),
    moneyApplyFixedMinor(699, 100),
    moneyPerMonthFromYearly(4999),
    moneyPercentToBasisPoints('12.5'),
];
foreach ($moneyResults as $moneyResultIndex => $moneyResult) {
    assertTrue(is_int($moneyResult), 'result ' . $moneyResultIndex . ' is a whole number (int), not a float');
}
