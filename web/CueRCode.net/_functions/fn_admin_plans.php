<?php
/**
 * ==============================================================================
 * 💳 CueRCode - Admin plans and prices editor (task 7n Phase 2, slice 2c)
 * ==============================================================================
 *
 * Path: _functions/fn_admin_plans.php
 *
 * Everything behind /admin/plans/ (the list), /admin/plans/new/,
 * /admin/plans/edit/ and /admin/plans/prices/: a Global Admin can make a
 * plan, change its name, description, button, sale dates, sales cap and
 * price promise, put it on sale or take it off sale, delete it while nobody
 * has ever used it, make a "new version" of it, and set its prices in every
 * offered currency.
 *
 * 🧊 THE FREEZING RULES (plan §4.3), enforced HERE on the server, never only by
 *    hiding a box on the page, so a hand-made request cannot get round them:
 *   - P1 A price somebody has bought (a subscription, payment or one-off
 *        purchase points at it) is FROZEN: its amount, currency, billing period,
 *        period count and grants cannot change, and it cannot be deleted. It can
 *        still be retired (taken off sale) and its sale dates changed. To change
 *        the price, retire it and add a new one.
 *   - P4 A plan is only ever deleted while NOTHING refers to it (no
 *        subscription, purchase, payment, bought price, discount or setting).
 *   - P5 The two fallback plans the code relies on (CUERCODE_PLAN_KEY_ANONYMOUS
 *        and CUERCODE_PLAN_KEY_FREE, fn_features.php) and any plan a setting
 *        names (the past-due downgrade plan, the trial plan) cannot be taken
 *        off sale or deleted - nor given sale dates (review finding F3), which
 *        would take them off the pricing page just the same.
 *   - P6 A plan's key cannot change after it is made (and neither can its kind).
 *   (P3 - taking features or limits away from paying customers - belongs to the
 *    features screen, Phase 3. Nothing here edits features or limits; "make a
 *    new version" is the safe way to change a plan people already pay for.)
 *
 * 🔒 RACE-SAFE: every save runs in ONE transaction that first LOCKS the plan
 *    row and its price rows (SELECT ... FOR UPDATE), then counts what refers to
 *    them with LOCKING reads (LOCK IN SHARE MODE), which see the newest
 *    committed rows. A purchase being recorded at that moment must check its
 *    plan / price row (the foreign key) and so waits for the lock; one recorded
 *    just before is seen by the count. Either way a bought price is never
 *    changed. The change and its audit row are committed together, or neither.
 *
 * 🧾 LOST UPDATES (plan §7.5): every form carries a fingerprint of what it
 *    showed (a hash of the row contents - not just updatedAt, which only has
 *    whole seconds). The save recomputes it inside the lock and refuses with
 *    "someone else changed this" when it differs. Nothing is half-saved.
 *
 * 💷 MONEY: amounts go through fn_money.php only (whole pence, never a float)
 *    and are bound to the DECIMAL columns as TEXT.
 *
 * 🕰️ DATES: stored in UTC, typed and shown in the admin time zone (UK time by
 *    default) with fn_admin.php's checked converters (plan §8.4).
 *
 * 🪞 THE MIRROR (plan §4.7): while charging is off the public pricing page
 *    reads the older single-price columns (priceMonthly / priceYearly /
 *    currency). After every price save, adminMirrorLegacyPlanPrices() copies
 *    the plan's CURRENT monthly and yearly prices in the main currency
 *    (billing_default_currency) into them, chosen by price GROUP ("monthly",
 *    "yearly") - never by the "recommended" flag.
 *
 * 🏷️ NO PLAN NAMES IN CODE: the only plan keys named are the two documented
 *    fallbacks, through fn_features.php's constants. No price is written here.
 *
 * House rules kept here: full multi-line PHP (no ??, ternaries, arrow
 * functions or match), MySQLi prepared statements only (through dbQuery()),
 * back-quoted SQL identifiers, every output escaped by the pages.
 *
 * 📦 LOADING: not loaded by bootstrap.php. The plan pages load it after the
 *    gate. Loading defines constants and functions only (no database call, no
 *    output) - tests/test_admin_plans.php relies on that.
 *
 * @package     CueRCode
 * @subpackage  Admin
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-26 (task 7n Phase 2, slice 2c - #31)
 * @see         .claude/plans/7n-admin-commerce-plan.md §4.3-4.7, §6, §7.1, §7.5, §8
 * @see         _functions/fn_money.php - whole-pence money helpers
 * @see         _functions/fn_admin.php - gate, password confirmation, audit trail, dates
 * @see         _includes/sql/migrations/0004_plans_prices_currencies.php - the columns used here
 * @see         tests/test_admin_plans.php, tests/integration/admin_plans_real_mysql.php
 */

// ==============================================================================
// 🚫 Direct access protection
// ==============================================================================
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $adminPlansScriptFilename = $_SERVER['SCRIPT_FILENAME'];
} else {
    $adminPlansScriptFilename = '';
}
if (basename($adminPlansScriptFilename) === basename(__FILE__)) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied.');
}

// 📦 What this file builds on: the admin gate/audit/date helpers, the settings
//    validators (web-address and hidden-character checks are reused, never
//    copied), the money helpers and the two fallback plan-key constants.
require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_admin.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_admin_settings.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_money.php';
// 🗓️ The live price-list rules shared with the public page (review finding F6).
require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_pricing.php';
if (!defined('CUERCODE_PLAN_KEY_FREE')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_features.php';
}

// ==============================================================================
// 🔧 Constants (limits of the boxes, not commercial rules)
// ==============================================================================

/** @var string A plan key: a lower-case letter, then 1-31 lower-case letters, digits or underscores (plan §9). */
if (!defined('CUERCODE_ADMIN_PLAN_KEY_PATTERN')) {
    define('CUERCODE_ADMIN_PLAN_KEY_PATTERN', '/^[a-z][a-z0-9_]{1,31}$/D');
}

/** @var int Largest sales cap that may be typed. */
if (!defined('CUERCODE_ADMIN_PLAN_MAX_SALES')) {
    define('CUERCODE_ADMIN_PLAN_MAX_SALES', 1000000);
}

/** @var int Longest fixed price promise, in months (ten years). */
if (!defined('CUERCODE_ADMIN_PLAN_PROMISE_MONTHS_MAX')) {
    define('CUERCODE_ADMIN_PLAN_PROMISE_MONTHS_MAX', 120);
}

/** @var int Largest display order number. */
if (!defined('CUERCODE_ADMIN_PLAN_SORT_MAX')) {
    define('CUERCODE_ADMIN_PLAN_SORT_MAX', 9999);
}

/** @var array<int, string> Subscription statuses that count as "paying" (fn_features.php resolves these). */
if (!defined('CUERCODE_ADMIN_PLAN_PAYING_STATUSES')) {
    define('CUERCODE_ADMIN_PLAN_PAYING_STATUSES', ['active', 'trialing', 'past_due']);
}

// ==============================================================================
// 🧠 PURE: the fixed choices (facts about the software, not commercial rules)
// ==============================================================================

/**
 * 🧩 The kinds of plan (the tblSubscriptionPlans.planKind values). PURE.
 *
 * @return array<string, array{label: string, help: string}>
 */
function adminPlansKinds(): array
{
    return [
        'standard' => [
            'label' => 'Ordinary plan',
            'help'  => 'Paid monthly or yearly (or free). Shown on the pricing page.',
        ],
        'lifetime_deal' => [
            'label' => 'Lifetime deal',
            'help'  => 'Paid once. Never shown on the ordinary pricing grid. The lifetime deals screen comes later.',
        ],
        'credit_packs' => [
            'label' => 'Pay-as-you-go credit packs',
            'help'  => 'Packs of credits bought one at a time. Pack prices are set on the pay-as-you-go screen, which comes later.',
        ],
    ];
}

/**
 * 🔘 What the plan's button does (tblSubscriptionPlans.ctaMode). PURE.
 *
 * @return array<string, string>
 */
function adminPlansCtaModes(): array
{
    return [
        'checkout' => 'Buy it (a buy button, which says "Coming soon" while charging is off)',
        'contact'  => 'Contact us (for plans agreed one customer at a time)',
    ];
}

/**
 * 🤝 How long existing customers keep the price they signed up at. '' means
 *    "use the site-wide setting" (stored as NULL). PURE.
 *
 * @return array<string, string>
 */
function adminPlansPromiseModes(): array
{
    return [
        ''                 => 'Use the site-wide setting',
        'while_subscribed' => 'For as long as they stay subscribed',
        'fixed_months'     => 'For a set number of months',
        'none'             => 'No promise (a price change applies at their next payment)',
    ];
}

/**
 * 🗂️ The price groups a plan of this kind can have, in page order. PURE.
 *
 * A group is one price in several currencies ("monthly" = the GBP, USD and
 * EUR monthly prices). Its key is written by the editor, never typed. Every
 * group bills once per period (interval count 1): longer periods such as
 * "every three months" are not offered yet.
 *
 * @param string $kind A planKind value
 * @return array<string, array{label: string, interval: string, sortOrder: int}>
 */
function adminPlansPriceGroups(string $kind): array
{
    if ($kind === 'standard') {
        return [
            'monthly' => ['label' => 'Monthly', 'interval' => 'monthly', 'sortOrder' => 1],
            'yearly'  => ['label' => 'Yearly', 'interval' => 'yearly', 'sortOrder' => 2],
        ];
    }

    if ($kind === 'lifetime_deal') {
        return [
            'lifetime' => ['label' => 'Pay once (lifetime)', 'interval' => 'lifetime', 'sortOrder' => 3],
        ];
    }

    // 💳 Credit packs: their own screen (Phase 6) builds the packs and their grants.
    return [];
}

// ==============================================================================
// 🧠 PURE: checking typed values
// ==============================================================================

/**
 * 🏷️ Is this a well-formed plan key? (A lower-case letter, then 1 to 31 more
 *    lower-case letters, digits or underscores.) PURE.
 *
 * @param string $key Candidate key
 * @return bool
 */
function adminPlansKeyIsValid(string $key): bool
{
    return preg_match(CUERCODE_ADMIN_PLAN_KEY_PATTERN, $key) === 1;
}

/**
 * 🔤 Check one line of text typed by an admin (a name or description). PURE.
 *
 * Trimmed; must be valid text with no line breaks or hidden characters, and
 * no < or > (names and descriptions are shown as plain text on the public
 * page, so HTML is refused rather than quietly shown as code).
 *
 * @param mixed  $raw      What was sent (anything that is not text is refused)
 * @param int    $maxLength Longest allowed, in characters (the column size)
 * @param bool   $required True when it may not be empty
 * @return array{ok: bool, value: string, error: string}
 */
function adminPlansCheckText(mixed $raw, int $maxLength, bool $required): array
{
    if (!is_string($raw)) {
        return ['ok' => false, 'value' => '', 'error' => 'That was not understood.'];
    }

    $text = trim($raw);

    if (preg_match('//u', $text) !== 1) {
        return ['ok' => false, 'value' => '', 'error' => 'That contains characters that are not valid text.'];
    }

    if (adminSettingsHasHiddenCharacter($text, false)) {
        return ['ok' => false, 'value' => '', 'error' => 'That contains a line break or another hidden character.'];
    }

    if (str_contains($text, '<') || str_contains($text, '>')) {
        return ['ok' => false, 'value' => '', 'error' => 'Please leave out the < and > characters (plain text only).'];
    }

    if (mb_strlen($text, 'UTF-8') > $maxLength) {
        return ['ok' => false, 'value' => '', 'error' => 'That is too long (the limit is ' . $maxLength . ' characters).'];
    }

    if ($required && $text === '') {
        return ['ok' => false, 'value' => '', 'error' => 'This cannot be empty.'];
    }

    return ['ok' => true, 'value' => $text, 'error' => ''];
}

/**
 * 🔢 Check a whole number typed by an admin. PURE.
 *
 * Digits only, written the one allowed way ("0", or no zero at the start), so
 * "+5", "1e3", "5.0" and "007" are refused.
 *
 * @param mixed $raw        What was sent
 * @param int   $min        Smallest allowed
 * @param int   $max        Largest allowed
 * @param bool  $allowEmpty True when an empty box means "none" (value null)
 * @return array{ok: bool, value: int|null, error: string}
 */
function adminPlansCheckWhole(mixed $raw, int $min, int $max, bool $allowEmpty): array
{
    if (!is_string($raw)) {
        return ['ok' => false, 'value' => null, 'error' => 'That was not understood.'];
    }

    $text = trim($raw);

    if ($text === '') {
        if ($allowEmpty) {
            return ['ok' => true, 'value' => null, 'error' => ''];
        }
        return ['ok' => false, 'value' => null, 'error' => 'Type a whole number from ' . $min . ' to ' . $max . '.'];
    }

    if (preg_match('/^(?:0|[1-9][0-9]{0,8})$/D', $text) !== 1) {
        return ['ok' => false, 'value' => null, 'error' => 'Type a whole number from ' . $min . ' to ' . $max . ' (digits only, no zero at the start).'];
    }

    $number = (int) $text;
    if ($number < $min || $number > $max) {
        return ['ok' => false, 'value' => null, 'error' => 'Type a whole number from ' . $min . ' to ' . $max . '.'];
    }

    return ['ok' => true, 'value' => $number, 'error' => ''];
}

/**
 * ✉️ Check where a "Contact us" button goes: empty (the site's own email
 *    address), an https web address, or "mailto:" and an email address. PURE.
 *
 * @param mixed $raw What was sent
 * @return array{ok: bool, value: string|null, error: string} value null = use the site email
 */
function adminPlansCheckContactUrl(mixed $raw): array
{
    $checked = adminPlansCheckText($raw, 255, false);
    if (!$checked['ok']) {
        return ['ok' => false, 'value' => null, 'error' => $checked['error']];
    }

    $text = $checked['value'];
    if ($text === '') {
        return ['ok' => true, 'value' => null, 'error' => ''];
    }

    if (stripos($text, 'mailto:') === 0) {
        $address = substr($text, strlen('mailto:'));
        if ($address === '' || preg_match('/[\s?&#]/', $address) === 1 || filter_var($address, FILTER_VALIDATE_EMAIL) === false || str_contains($address, '@[')) {
            return ['ok' => false, 'value' => null, 'error' => 'After mailto: type one email address, for example mailto:sales@example.com.'];
        }
        return ['ok' => true, 'value' => 'mailto:' . $address, 'error' => ''];
    }

    $address = adminSettingsValidateHttpsAddress($text);
    if (!$address['ok']) {
        return ['ok' => false, 'value' => null, 'error' => $address['error'] . ' Or type mailto: and an email address, or leave it empty to use the site\'s email address.'];
    }

    return ['ok' => true, 'value' => $address['value'], 'error' => ''];
}

/**
 * 🕰️ A date (and optional time) typed in the admin time zone, turned into UTC
 *    for storing. PURE (plan §8.4).
 *
 * Both empty = no date (null). A time with no date is refused. A date with no
 * time means the start of that day for a start, the end of that day
 * (23:59:59) for an end. A time the clocks skip, or one that happens twice,
 * is refused with a suggestion.
 *
 * @param mixed  $date     What was sent in the date box (YYYY-MM-DD)
 * @param mixed  $time     What was sent in the time box (HH:MM, may be empty)
 * @param string $timezone The admin time zone
 * @param bool   $endOfDay True for an END date
 * @return array{ok: bool, utc: string|null, error: string}
 */
function adminPlansCheckLocalDate(mixed $date, mixed $time, string $timezone, bool $endOfDay): array
{
    if (!is_string($date) || !is_string($time)) {
        return ['ok' => false, 'utc' => null, 'error' => 'That was not understood.'];
    }

    $date = trim($date);
    $time = trim($time);

    if ($date === '' && $time === '') {
        return ['ok' => true, 'utc' => null, 'error' => ''];
    }

    if ($date === '') {
        return ['ok' => false, 'utc' => null, 'error' => 'Choose a date as well as a time (or clear both).'];
    }

    $typed = $date;
    if ($time !== '') {
        if (preg_match('/^[0-9]{2}:[0-9]{2}$/D', $time) !== 1) {
            return ['ok' => false, 'utc' => null, 'error' => 'Type the time as hours and minutes, for example 09:30.'];
        }
        $typed = $date . ' ' . $time;
    } elseif (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date) !== 1) {
        return ['ok' => false, 'utc' => null, 'error' => 'Type the date as year-month-day, for example 2026-12-31.'];
    }

    $check = adminLocalTimeCheck($typed, $timezone, $endOfDay);
    if ($check['ok'] === true) {
        return ['ok' => true, 'utc' => $check['utc'], 'error' => ''];
    }

    $zoneWords = adminTimezoneLabel($timezone);

    if ($check['problem'] === 'nonexistent') {
        $suggestion = '';
        if (is_string($check['suggestion'])) {
            $suggestion = ' The next time that exists is ' . substr($check['suggestion'], 11, 5) . '.';
        }
        return ['ok' => false, 'utc' => null, 'error' => 'That time does not exist in ' . $zoneWords . ' (the clocks go forward then).' . $suggestion];
    }

    if ($check['problem'] === 'ambiguous') {
        $suggestion = '';
        if (is_string($check['suggestion'])) {
            $suggestion = ' Choose ' . substr($check['suggestion'], 11, 5) . ' or later, or a time outside that hour.';
        }
        return ['ok' => false, 'utc' => null, 'error' => 'That time happens twice in ' . $zoneWords . ' (the clocks go back then), so it is not clear which one you mean.' . $suggestion];
    }

    return ['ok' => false, 'utc' => null, 'error' => 'That is not a real date and time.'];
}

/**
 * 🕰️ A stored UTC value split into the date box and time box values, in the
 *    admin time zone. PURE.
 *
 * A start at exactly midnight, or an end at exactly 23:59:59, shows the date
 * only (that is what typing a date alone saves), so the form round-trips.
 *
 * @param string|null $utc      Stored value (null = nothing)
 * @param string      $timezone Admin time zone
 * @param bool        $endOfDay True for an END date
 * @return array{date: string, time: string}
 */
function adminPlansUtcToInputs(?string $utc, string $timezone, bool $endOfDay): array
{
    if ($utc === null || $utc === '') {
        return ['date' => '', 'time' => ''];
    }

    $local = adminUtcToLocal($utc, $timezone);
    if ($local === '') {
        return ['date' => '', 'time' => ''];
    }

    $date = substr($local, 0, 10);
    $clock = substr($local, 11, 8);

    if (!$endOfDay && $clock === '00:00:00') {
        return ['date' => $date, 'time' => ''];
    }
    if ($endOfDay && $clock === '23:59:59') {
        return ['date' => $date, 'time' => ''];
    }

    return ['date' => $date, 'time' => substr($clock, 0, 5)];
}

/**
 * 🗓️ A stored UTC value for people: "31 Dec 2026, 23:59 (UK time)", or the
 *    given words when there is none. PURE.
 *
 * @param mixed  $utc      Stored value as the database gave it (null or text)
 * @param string $timezone Admin time zone
 * @param string $none     Words when there is no value
 * @return string
 */
function adminPlansDateWords(mixed $utc, string $timezone, string $none): string
{
    if (!is_string($utc)) {
        return $none;
    }

    $shown = adminFormatUtcForDisplay($utc, $timezone);
    if ($shown === '') {
        return $none;
    }

    return $shown . ' (' . adminTimezoneLabel($timezone) . ')';
}

// ==============================================================================
// 🧠 PURE: the freezing and protection rules (plan §4.3)
// ==============================================================================

/**
 * 🛡️ Why a plan may not be taken off sale or deleted (P5), or '' when it may.
 *    PURE.
 *
 * @param string                $planKey         The plan's key
 * @param array<string, string> $settingPlanKeys Setting key => the plan key it names
 *                                               (billing_past_due_downgrade_plan_key, billing_trial_plan_key)
 * @return string Plain-English reason, or ''
 */
function adminPlansProtectedReason(string $planKey, array $settingPlanKeys): string
{
    if ($planKey === CUERCODE_PLAN_KEY_ANONYMOUS) {
        return 'This is the plan for visitors who are not signed in. The site relies on it, so it can never be taken off sale or deleted.';
    }

    if ($planKey === CUERCODE_PLAN_KEY_FREE) {
        return 'This is the plan everyone signed in falls back to. The site relies on it, so it can never be taken off sale or deleted.';
    }

    $settingWords = [
        'billing_past_due_downgrade_plan_key' => 'the plan people move to while a payment is overdue',
        'billing_trial_plan_key'              => 'the plan a free trial gives',
    ];

    foreach ($settingPlanKeys as $settingKey => $namedPlanKey) {
        if (!is_string($namedPlanKey) || $namedPlanKey !== $planKey) {
            continue;
        }
        $words = 'named in the setting ' . (string) $settingKey;
        if (isset($settingWords[$settingKey])) {
            $words = $settingWords[$settingKey];
        }
        return 'This plan is ' . $words . ' (setting ' . (string) $settingKey . '). Choose a different plan there first.';
    }

    return '';
}

/**
 * 🛡️ What, in a plan's own boxes, would take a protected plan (P5) off the
 *    pricing page or take away its own button. PURE. (Review finding F3 and
 *    recheck finding 2 of the 7n Phase 2 review.)
 *
 * A protected plan must always be on sale, on the page, with its own button
 * (for the free plan that is "Create free account", the only sign-up button):
 *   - sale dates: a start later or an end that passes takes it off the page;
 *   - "Contact us": replaces its own button;
 *   - display order 0: hides its card. Not for the visitors' plan, which
 *     never has a card of its own (order 0 is how it is kept off the grid);
 *   - a sales cap: ends its sales once reached.
 * A box missing from $plan (a row from before upgrade 0004) is not a problem.
 * Used by the plan form (adminPlansValidatePlanForm()) and by the settings
 * screen (adminPlansTrialPlanChoices()), so the two can never disagree.
 *
 * @param array<string, mixed> $plan    Plan columns: a stored row, or the values about to be saved
 * @param string               $planKey The plan's key
 * @return array<string, string> Form box => plain words (finishing "For the same
 *                               reason ..."); empty when nothing is wrong
 */
function adminPlansProtectedPlanProblems(array $plan, string $planKey): array
{
    $problems = [];
    $datesWords = 'it cannot have sale dates: leave both dates empty.';

    if (isset($plan['availableFrom'])) {
        $problems['available_from_date'] = $datesWords;
    }

    if (isset($plan['availableUntil'])) {
        $problems['available_until_date'] = $datesWords;
    }

    if (isset($plan['ctaMode']) && $plan['ctaMode'] === 'contact') {
        $problems['cta_mode'] = 'it must keep its own button, so it cannot be "Contact us".';
    }

    if ($planKey !== CUERCODE_PLAN_KEY_ANONYMOUS && array_key_exists('sortOrder', $plan) && (int) $plan['sortOrder'] < 1) {
        $problems['sort_order'] = 'it must stay on the pricing page, so its display order must be 1 or more (0 hides a plan).';
    }

    if (isset($plan['maxSales'])) {
        $problems['max_sales'] = 'it cannot have a sales cap: leave the cap empty.';
    }

    return $problems;
}

/**
 * 🎟️ The plans the free-trial setting (billing_trial_plan_key) may name.
 *    PURE. (Recheck finding 2 of the 7n Phase 2 review.)
 *
 * The plan a setting names becomes protected (adminPlansProtectedReason()),
 * so it may only be one the plan form would accept as protected: on sale, an
 * ordinary plan, costing money (monthly or yearly), and with none of the
 * problems in adminPlansProtectedPlanProblems(). Before this, a plan whose
 * sale dates had ENDED could be chosen, and the plan could then not be put
 * right because protected plans may not have dates at all.
 *
 * @param array<int, array<string, mixed>> $planRows tblSubscriptionPlans rows (any order)
 * @return array<int, string> Plan keys, in the order given
 */
function adminPlansTrialPlanChoices(array $planRows): array
{
    $keys = [];

    foreach ($planRows as $row) {
        if (!isset($row['planKey']) || !is_string($row['planKey']) || $row['planKey'] === '') {
            continue;
        }
        if (!isset($row['isActive']) || (int) $row['isActive'] !== 1) {
            continue;
        }
        if (array_key_exists('planKind', $row) && $row['planKind'] !== 'standard') {
            continue;
        }

        // 💷 Costs money: a monthly or a yearly figure above zero. Read as
        //    text (a stored DECIMAL with a non-zero digit), never as a float.
        $costsMoney = false;
        foreach (['priceMonthly', 'priceYearly'] as $column) {
            if (!isset($row[$column])) {
                continue;
            }
            $stored = (string) $row[$column];
            if (preg_match('/^[0-9]{1,8}(\.[0-9]{1,4})?$/D', $stored) === 1 && trim(str_replace('.', '', $stored), '0') !== '') {
                $costsMoney = true;
            }
        }
        if (!$costsMoney) {
            continue;
        }

        if (adminPlansProtectedPlanProblems($row, $row['planKey']) !== []) {
            continue;
        }

        $keys[] = $row['planKey'];
    }

    return $keys;
}

/**
 * 🗑️ May this plan be deleted (P4 + P5)? PURE.
 *
 * Only a plan nothing has ever referred to may go: no subscription (of any
 * status, past or present), no one-off purchase or payment of any of its
 * prices, no discount aimed at it, and no setting naming it.
 *
 * @param array<string, int> $usage           From adminPlansUsage(): subscriptions, oneOffPurchases,
 *                                            payments, discounts (missing keys count as 0)
 * @param string             $protectedReason From adminPlansProtectedReason()
 * @return array{allowed: bool, reasons: array<int, string>}
 */
function adminPlansDeleteDecision(array $usage, string $protectedReason): array
{
    $reasons = [];

    if ($protectedReason !== '') {
        $reasons[] = $protectedReason;
    }

    $words = [
        'subscriptions'   => 'subscription(s), past or present, use this plan',
        'oneOffPurchases' => 'one-off purchase(s) bought one of its prices',
        'payments'        => 'payment(s) were for one of its prices',
        'discounts'       => 'discount(s) are aimed at this plan',
    ];

    foreach ($words as $usageKey => $text) {
        $count = 0;
        if (isset($usage[$usageKey])) {
            $count = (int) $usage[$usageKey];
        }
        if ($count > 0) {
            $reasons[] = $count . ' ' . $text . '.';
        }
    }

    return ['allowed' => ($reasons === []), 'reasons' => $reasons];
}

/**
 * 🧊 Is a price frozen (P1)? True once anything refers to it. PURE.
 *
 * @param int $references Subscriptions + payments + one-off purchases pointing at it
 * @return bool
 */
function adminPlansPriceIsFrozen(int $references): bool
{
    return $references > 0;
}

/**
 * 💷 What saving a typed amount over a stored price would do (P1). PURE.
 *
 * @param string $storedAmount The stored DECIMAL text ("6.99")
 * @param string $currency     The price's currency
 * @param int    $typedMinor   The typed amount in minor units
 * @param bool   $frozen       From adminPlansPriceIsFrozen()
 * @return string 'unchanged' | 'update' | 'frozen' (refused: a bought price) | 'unreadable'
 */
function adminPlansPriceChangeDecision(string $storedAmount, string $currency, int $typedMinor, bool $frozen): string
{
    $storedMinor = moneyStoredDecimalToMinor($storedAmount, $currency);
    if ($storedMinor === null) {
        return 'unreadable';
    }

    if ($storedMinor === $typedMinor) {
        return 'unchanged';
    }

    if ($frozen) {
        return 'frozen';
    }

    return 'update';
}

// ==============================================================================
// 🧠 PURE: keys and fingerprints
// ==============================================================================

/**
 * 🏷️ A suggested key for a new version of a plan: "<key>_v2", "<key>_v3"...
 *    (an existing "_vN" ending is replaced, so "x_v2" suggests "x_v3"), never
 *    one already taken and never longer than 32 characters. PURE.
 *
 * @param string             $planKey      The plan being versioned
 * @param array<int, string> $existingKeys Every plan key already used
 * @return string A free, valid key ('' when none could be found)
 */
function adminPlansNewVersionKey(string $planKey, array $existingKeys): string
{
    $base = $planKey;
    $start = 2;
    $match = [];
    if (preg_match('/^(.+)_v([0-9]+)$/D', $planKey, $match) === 1) {
        $base = $match[1];
        $start = (int) $match[2] + 1;
    }

    for ($number = $start; $number < $start + 1000; $number++) {
        $suffix = '_v' . $number;
        $candidate = substr($base, 0, 32 - strlen($suffix)) . $suffix;
        if (adminPlansKeyIsValid($candidate) && !in_array($candidate, $existingKeys, true)) {
            return $candidate;
        }
    }

    return '';
}

/**
 * 🏷️ The priceKey for a new price: "<group>_<currency>" ("monthly_gbp"), or,
 *    when that key was used before (a retired price keeps its key for ever),
 *    "monthly_gbp_2", "_3"... PURE.
 *
 * Keys are never reused, because discounts can name a price by its key.
 *
 * @param string             $groupKey     The price group (monthly, yearly, lifetime)
 * @param string             $currency     ISO 4217 code
 * @param array<int, string> $existingKeys Every priceKey this plan already has
 * @return string The key ('' when none fits in 48 characters)
 */
function adminPlansNextPriceKey(string $groupKey, string $currency, array $existingKeys): string
{
    $base = $groupKey . '_' . strtolower($currency);

    if (strlen($base) <= 48 && !in_array($base, $existingKeys, true)) {
        return $base;
    }

    for ($number = 2; $number < 10000; $number++) {
        $candidate = $base . '_' . $number;
        if (strlen($candidate) > 48) {
            return '';
        }
        if (!in_array($candidate, $existingKeys, true)) {
            return $candidate;
        }
    }

    return '';
}

/**
 * 🧾 A fingerprint of one plan row as the form showed it (plan §7.5). PURE.
 *
 * Every column is included, so ANY change made since the form was opened -
 * even within the same second - gives a different fingerprint.
 *
 * @param array<string, mixed> $row A tblSubscriptionPlans row
 * @return string 64 hexadecimal characters
 */
function adminPlansRowFingerprint(array $row): string
{
    ksort($row);
    $encoded = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($encoded)) {
        $encoded = '';
    }

    return hash('sha256', 'cuercode-plan-form-v1' . "\n" . $encoded);
}

/**
 * 🧾 A fingerprint of a plan's whole price list (every row, every column),
 *    so a price form opened earlier cannot undo a newer price change. PURE.
 *
 * @param array<int, array<string, mixed>> $rows tblPlanPricePoints rows of one plan
 * @return string 64 hexadecimal characters
 */
function adminPlansPricesFingerprint(array $rows): string
{
    $byID = [];
    foreach ($rows as $row) {
        ksort($row);
        $id = 0;
        if (isset($row['pricePointID'])) {
            $id = (int) $row['pricePointID'];
        }
        $byID[$id] = $row;
    }
    ksort($byID);

    $encoded = json_encode(array_values($byID), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($encoded)) {
        $encoded = '';
    }

    return hash('sha256', 'cuercode-price-list-v1' . "\n" . $encoded);
}

/**
 * 💬 What the admin is told when a form was out of date. PURE.
 *
 * @return string
 */
function adminPlansStaleMessage(): string
{
    return 'Someone else changed this while you were editing. Nothing was saved. Reload the page and make your change again.';
}

// ==============================================================================
// 🧠 PURE: reading the plan form (plan §7.1 new/ and edit/)
// ==============================================================================

/**
 * 🧮 Read and check the plan form. PURE.
 *
 * Only the boxes below are ever read; anything else in the request is
 * ignored, so a hand-made request cannot set redemption counts, features,
 * limits or anything else (plan §10 S6).
 *
 * Creating ($current null) also reads plan_key, plan_kind and copy_from.
 * Editing NEVER changes the key or the kind (P6): if a request sends a
 * different one, it is refused with a message - not silently ignored - so a
 * tampered form is noticed. A box missing from an edit request keeps the
 * stored value.
 *
 * @param array<string, mixed>      $post    The $_POST array
 * @param array<string, mixed>|null $current The stored plan row (null when creating)
 * @param array<string, mixed>      $context timezone (string), soldCount (int, editing),
 *                                           existingKeys (list, creating), sourcePlans (planID => row, creating),
 *                                           protectedReason (string, editing: adminPlansProtectedReason();
 *                                           not empty = the plan may have no sale dates - review F3)
 * @return array{errors: array<string, string>, values: array<string, mixed>}
 *         values = column => value to store (only when errors is empty)
 */
function adminPlansValidatePlanForm(array $post, ?array $current, array $context): array
{
    $errors = [];
    $values = [];
    $creating = ($current === null);

    $timezone = CUERCODE_ADMIN_DEFAULT_TIMEZONE;
    if (isset($context['timezone']) && is_string($context['timezone']) && adminTimezoneIsValid($context['timezone'])) {
        $timezone = $context['timezone'];
    }

    // 📥 A box: what was sent, or (editing) the stored value when it is missing.
    $read = function (string $name, string $column, string $whenCreating) use ($post, $current): mixed {
        if (array_key_exists($name, $post)) {
            return $post[$name];
        }
        if ($current !== null && array_key_exists($column, $current)) {
            if ($current[$column] === null) {
                return '';
            }
            return (string) $current[$column];
        }
        return $whenCreating;
    };

    // ------------------------------------------------- key, kind, copy (create only)
    if ($creating) {
        $key = '';
        if (isset($post['plan_key']) && is_string($post['plan_key'])) {
            $key = trim($post['plan_key']);
        }
        $existing = [];
        if (isset($context['existingKeys']) && is_array($context['existingKeys'])) {
            $existing = $context['existingKeys'];
        }
        if (!adminPlansKeyIsValid($key)) {
            $errors['plan_key'] = 'Type a key of 2 to 32 characters: a lower-case letter first, then lower-case letters, digits or underscores (for example team_plus).';
        } elseif (in_array($key, $existing, true)) {
            $errors['plan_key'] = 'A plan with that key already exists. Choose another key.';
        } else {
            $values['planKey'] = $key;
        }

        $kind = '';
        if (isset($post['plan_kind']) && is_string($post['plan_kind'])) {
            $kind = $post['plan_kind'];
        }
        if (!array_key_exists($kind, adminPlansKinds())) {
            $errors['plan_kind'] = 'Choose one of the kinds listed.';
        } else {
            $values['planKind'] = $kind;
        }

        $copyFrom = '';
        if (isset($post['copy_from']) && is_string($post['copy_from'])) {
            $copyFrom = trim($post['copy_from']);
        }
        $values['basedOnPlanID'] = null;
        if ($copyFrom !== '') {
            $sourcePlans = [];
            if (isset($context['sourcePlans']) && is_array($context['sourcePlans'])) {
                $sourcePlans = $context['sourcePlans'];
            }
            if (preg_match('/^[1-9][0-9]{0,9}$/D', $copyFrom) !== 1 || !isset($sourcePlans[(int) $copyFrom])) {
                $errors['copy_from'] = 'Choose one of the plans listed (or none).';
            } else {
                $values['basedOnPlanID'] = (int) $copyFrom;
            }
        }
    } else {
        // 🔒 P6: the key and the kind are fixed once the plan exists.
        if (array_key_exists('plan_key', $post) && (!is_string($post['plan_key']) || trim($post['plan_key']) !== (string) $current['planKey'])) {
            $errors['plan_key'] = 'A plan\'s key cannot be changed after the plan is made (other records refer to it). To start again under a new key, use "Make a new version".';
        }
        if (array_key_exists('plan_kind', $post) && (!is_string($post['plan_kind']) || $post['plan_kind'] !== (string) $current['planKind'])) {
            $errors['plan_kind'] = 'A plan\'s kind cannot be changed after the plan is made.';
        }
    }

    // ------------------------------------------------------------ name, description
    $name = adminPlansCheckText($read('plan_name', 'planName', ''), 100, true);
    if (!$name['ok']) {
        $errors['plan_name'] = $name['error'];
    } else {
        $values['planName'] = $name['value'];
    }

    $description = adminPlansCheckText($read('plan_description', 'description', ''), 500, false);
    if (!$description['ok']) {
        $errors['plan_description'] = $description['error'];
    } elseif ($description['value'] === '') {
        $values['description'] = null;
    } else {
        $values['description'] = $description['value'];
    }

    // ------------------------------------------------------------------- order
    $sort = adminPlansCheckWhole($read('sort_order', 'sortOrder', '0'), 0, CUERCODE_ADMIN_PLAN_SORT_MAX, false);
    if (!$sort['ok']) {
        $errors['sort_order'] = $sort['error'];
    } else {
        $values['sortOrder'] = $sort['value'];
    }

    // ------------------------------------------------------------ button + contact
    $cta = $read('cta_mode', 'ctaMode', 'checkout');
    if (!is_string($cta) || !array_key_exists($cta, adminPlansCtaModes())) {
        $errors['cta_mode'] = 'Choose one of the options listed.';
    } else {
        $values['ctaMode'] = $cta;
    }

    $contact = adminPlansCheckContactUrl($read('contact_url', 'contactUrl', ''));
    if (!$contact['ok']) {
        $errors['contact_url'] = $contact['error'];
    } else {
        $values['contactUrl'] = $contact['value'];
    }

    // ------------------------------------------------------------- sale window
    $fromInputs = adminPlansUtcToInputs(null, $timezone, false);
    $untilInputs = adminPlansUtcToInputs(null, $timezone, true);
    if ($current !== null) {
        $storedFrom = null;
        if (isset($current['availableFrom'])) {
            $storedFrom = (string) $current['availableFrom'];
        }
        $storedUntil = null;
        if (isset($current['availableUntil'])) {
            $storedUntil = (string) $current['availableUntil'];
        }
        $fromInputs = adminPlansUtcToInputs($storedFrom, $timezone, false);
        $untilInputs = adminPlansUtcToInputs($storedUntil, $timezone, true);
    }

    $fromDate = $fromInputs['date'];
    if (array_key_exists('available_from_date', $post)) {
        $fromDate = $post['available_from_date'];
    }
    $fromTime = $fromInputs['time'];
    if (array_key_exists('available_from_time', $post)) {
        $fromTime = $post['available_from_time'];
    }
    $untilDate = $untilInputs['date'];
    if (array_key_exists('available_until_date', $post)) {
        $untilDate = $post['available_until_date'];
    }
    $untilTime = $untilInputs['time'];
    if (array_key_exists('available_until_time', $post)) {
        $untilTime = $post['available_until_time'];
    }

    $from = adminPlansCheckLocalDate($fromDate, $fromTime, $timezone, false);
    if (!$from['ok']) {
        $errors['available_from_date'] = $from['error'];
    } else {
        $values['availableFrom'] = $from['utc'];
    }

    $until = adminPlansCheckLocalDate($untilDate, $untilTime, $timezone, true);
    if (!$until['ok']) {
        $errors['available_until_date'] = $until['error'];
    } else {
        $values['availableUntil'] = $until['utc'];
    }

    if ($from['ok'] && $until['ok'] && $from['utc'] !== null && $until['utc'] !== null && strcmp($until['utc'], $from['utc']) <= 0) {
        $errors['available_until_date'] = 'The end must be after the start.';
    }

    // -------------------------------------------------------------- sales cap
    $cap = adminPlansCheckWhole($read('max_sales', 'maxSales', ''), 1, CUERCODE_ADMIN_PLAN_MAX_SALES, true);
    if (!$cap['ok']) {
        $errors['max_sales'] = $cap['error'] . ' Leave it empty for no cap.';
    } else {
        $sold = 0;
        if (isset($context['soldCount'])) {
            $sold = (int) $context['soldCount'];
        }
        if ($cap['value'] !== null && $cap['value'] < $sold) {
            $errors['max_sales'] = 'The cap cannot be below the number already sold (' . $sold . ').';
        } else {
            $values['maxSales'] = $cap['value'];
        }
    }

    // ---------------------------------------------------------- price promise
    $promise = $read('promise_mode', 'pricePromiseMode', '');
    if (!is_string($promise) || !array_key_exists($promise, adminPlansPromiseModes())) {
        $errors['promise_mode'] = 'Choose one of the options listed.';
    } else {
        $months = $read('promise_months', 'pricePromiseMonths', '');
        if ($promise === 'fixed_months') {
            $monthsCheck = adminPlansCheckWhole($months, 1, CUERCODE_ADMIN_PLAN_PROMISE_MONTHS_MAX, false);
            if (!$monthsCheck['ok']) {
                $errors['promise_months'] = $monthsCheck['error'];
            } else {
                $values['pricePromiseMode'] = 'fixed_months';
                $values['pricePromiseMonths'] = $monthsCheck['value'];
            }
        } else {
            if (is_string($months) && trim($months) !== '' && array_key_exists('promise_months', $post)) {
                $errors['promise_months'] = 'Leave the number of months empty unless the promise is "for a set number of months".';
            }
            if ($promise === '') {
                $values['pricePromiseMode'] = null;
            } else {
                $values['pricePromiseMode'] = $promise;
            }
            $values['pricePromiseMonths'] = null;
        }
    }

    // 🛡️ Review finding F3 and recheck finding 2 (7n Phase 2): a protected
    //    plan (P5) must always be on the pricing page with its own button.
    //    Taking it off sale was already refused, but other boxes did the same
    //    thing by another route (the Free card - the only sign-up button -
    //    left the page, or lost its button). adminPlansProtectedPlanProblems()
    //    names every such route; each is refused with the reason, and a plan
    //    that somehow already has one is told to put it right before saving.
    //    Boxes that already have an error keep that error.
    $protectedReason = '';
    if (isset($context['protectedReason']) && is_string($context['protectedReason'])) {
        $protectedReason = $context['protectedReason'];
    }
    if ($protectedReason !== '' && !$creating) {
        foreach (adminPlansProtectedPlanProblems($values, (string) $current['planKey']) as $box => $problem) {
            if (!isset($errors[$box])) {
                $errors[$box] = $protectedReason . ' For the same reason ' . $problem;
            }
        }
    }

    if ($errors !== []) {
        return ['errors' => $errors, 'values' => []];
    }

    return ['errors' => [], 'values' => $values];
}

// ==============================================================================
// 🧠 PURE: prices
// ==============================================================================

/**
 * 🚦 Where a price stands right now. PURE.
 *
 * @param array<string, mixed> $row    A tblPlanPricePoints row
 * @param string               $nowUtc "Y-m-d H:i:s" in UTC
 * @return string 'retired' | 'scheduled' (starts later) | 'ended' (its end date passed) | 'on_sale'
 */
function adminPlansPriceStatus(array $row, string $nowUtc): string
{
    // 🗓️ One rule for the editor and the public page (fn_pricing.php).
    return pricingPriceRowStatus($row, $nowUtc);
}

/**
 * 🗂️ The CURRENT (not retired) prices of one group, by currency. PURE.
 *
 * Normally at most one per currency (the editor keeps it that way); a list
 * is returned so older data with two can be shown and refused honestly.
 *
 * @param array<int, array<string, mixed>> $rows     The plan's price rows
 * @param string                           $groupKey The group
 * @return array<string, array<int, array<string, mixed>>> currency => rows (lowest pricePointID first)
 */
function adminPlansCurrentByCurrency(array $rows, string $groupKey): array
{
    $current = [];

    foreach ($rows as $row) {
        if (!isset($row['priceGroupKey']) || (string) $row['priceGroupKey'] !== $groupKey) {
            continue;
        }
        if ((int) $row['isActive'] !== 1) {
            continue;
        }
        $currency = (string) $row['currency'];
        if (!isset($current[$currency])) {
            $current[$currency] = [];
        }
        $current[$currency][] = $row;
    }

    foreach (array_keys($current) as $currency) {
        usort($current[$currency], static function (array $left, array $right): int {
            return (int) $left['pricePointID'] <=> (int) $right['pricePointID'];
        });
    }

    return $current;
}

/**
 * 🧮 Read and check one price group's form. PURE.
 *
 * Form boxes: amount[<CUR>] for every offered currency; valid_from_date /
 * valid_from_time / valid_until_date / valid_until_time for the whole group;
 * recommended = "1" to show this group as the recommended choice. The four
 * date boxes and "recommended" must all be SENT (empty is fine): a request
 * without one is refused as a whole, error key 'form' (Codex finding 1.2).
 *
 * Per offered currency:
 *   - empty box, no current price  → nothing;
 *   - empty box, a current price   → refused ("use Retire" - an empty box is
 *     never taken to mean "stop selling", which is too easy to do by mistake);
 *   - an amount, no current price  → a new price;
 *   - an amount, a current price   → unchanged, or changed while nobody has
 *     bought it, or REFUSED when somebody has (P1).
 * Currencies that are not offered are never read. Zero is refused (a plan
 * that costs nothing has no price at all).
 *
 * @param array<string, mixed>             $post       The $_POST array
 * @param string                           $groupKey   The group being saved
 * @param array<int, string>               $offered    Offered currencies, in order
 * @param array<int, array<string, mixed>> $rows       The plan's price rows (all)
 * @param array<int, int>                  $references pricePointID => how many records point at it
 * @param string                           $timezone   Admin time zone
 * @return array{errors: array<string, string>, operations: array<int, array<string, mixed>>,
 *               validFrom: string|null, validUntil: string|null, recommended: bool}
 *         operations: ['op' => 'create', 'currency', 'amount'] or
 *                     ['op' => 'update', 'pricePointID', 'currency', 'oldAmount', 'amount'] (amounts as DECIMAL text)
 */
function adminPlansProcessPriceGroup(array $post, string $groupKey, array $offered, array $rows, array $references, string $timezone): array
{
    $outcome = ['errors' => [], 'operations' => [], 'validFrom' => null, 'validUntil' => null, 'recommended' => false];

    // 🗓️ Codex finding 1.2 (7n Phase 2 review): the form ALWAYS sends its
    //    four date and time boxes and the "recommended" box (a hidden 0 in
    //    front of the tick box). A request without one of them did not come
    //    from the form as shown, and reading a missing box as "empty" would
    //    clear the sale dates (or the recommended mark) of every current
    //    price in the group - putting a future price on sale at once, or
    //    making an ending offer endless. So it is refused; a box sent EMPTY
    //    still clears its value, as the page says.
    foreach (['valid_from_date', 'valid_from_time', 'valid_until_date', 'valid_until_time', 'recommended'] as $requiredBox) {
        if (!array_key_exists($requiredBox, $post)) {
            $outcome['errors']['form'] = 'Part of the form was missing (the sale dates or the recommended choice), so nothing was saved. Reload the page and try again.';
            return $outcome;
        }
    }

    $amounts = [];
    if (isset($post['amount']) && is_array($post['amount'])) {
        $amounts = $post['amount'];
    }

    $current = adminPlansCurrentByCurrency($rows, $groupKey);

    foreach ($offered as $currency) {
        $fieldName = 'amount_' . $currency;
        $currencyName = $currency;
        $facts = moneyCurrencyInfo();
        if (isset($facts[$currency])) {
            $currencyName = $facts[$currency]['name'] . ' (' . $currency . ')';
        }

        $typed = '';
        if (array_key_exists($currency, $amounts)) {
            if (!is_string($amounts[$currency])) {
                $outcome['errors'][$fieldName] = $currencyName . ': that was not understood.';
                continue;
            }
            $typed = trim($amounts[$currency]);
        }

        $currentRows = [];
        if (isset($current[$currency])) {
            $currentRows = $current[$currency];
        }

        if (count($currentRows) > 1) {
            $outcome['errors'][$fieldName] = $currencyName . ': there is more than one current price. Retire the ones that are not wanted first.';
            continue;
        }

        if ($typed === '') {
            if ($currentRows !== []) {
                $outcome['errors'][$fieldName] = $currencyName . ': to stop selling this price, use its Retire button in the list below. (An empty box does not remove a price.)';
            }
            continue;
        }

        $minor = moneyParseToMinor($typed, $currency);
        if ($minor === null) {
            $places = moneyMinorUnits($currency);
            // (An example that is not one of the seeded prices - the
            //  literal guard in tests/test_admin_plans.php checks that.)
            $example = '12.50';
            if ($places === 0) {
                $example = '1250';
            }
            $outcome['errors'][$fieldName] = $currencyName . ': type the amount as digits with up to ' . $places . ' decimal places and no symbol or commas, for example ' . $example . ' (at most ' . moneyFormat(moneyMaxTypedMinor(), $currency) . ').';
            continue;
        }

        if ($minor === 0) {
            $outcome['errors'][$fieldName] = $currencyName . ': a price must be more than zero. A plan that costs nothing needs no price.';
            continue;
        }

        $amountText = moneyMinorToDecimalString($minor, $currency);

        if ($currentRows === []) {
            $outcome['operations'][] = ['op' => 'create', 'currency' => $currency, 'amount' => $amountText];
            continue;
        }

        $row = $currentRows[0];
        $rowID = (int) $row['pricePointID'];
        $referenceCount = 0;
        if (isset($references[$rowID])) {
            $referenceCount = (int) $references[$rowID];
        }

        $decision = adminPlansPriceChangeDecision((string) $row['amount'], $currency, $minor, adminPlansPriceIsFrozen($referenceCount));

        if ($decision === 'unchanged') {
            continue;
        }
        if ($decision === 'frozen') {
            $outcome['errors'][$fieldName] = $currencyName . ': this price has been bought (' . $referenceCount . ' record(s) use it), so it cannot be changed - customers keep what they bought. Retire it in the list below, then add the new price.';
            continue;
        }
        if ($decision === 'unreadable') {
            $outcome['errors'][$fieldName] = $currencyName . ': the stored price could not be read. Retire it and add a new one.';
            continue;
        }

        $outcome['operations'][] = [
            'op'           => 'update',
            'pricePointID' => $rowID,
            'currency'     => $currency,
            'oldAmount'    => moneyMinorToDecimalString((int) moneyStoredDecimalToMinor((string) $row['amount'], $currency), $currency),
            'amount'       => $amountText,
        ];
    }

    // ------------------------------------------------------- the group's dates
    //    (all four boxes are present - checked at the top, finding 1.2)
    $from = adminPlansCheckLocalDate($post['valid_from_date'], $post['valid_from_time'], $timezone, false);
    if (!$from['ok']) {
        $outcome['errors']['valid_from_date'] = $from['error'];
    }
    $until = adminPlansCheckLocalDate($post['valid_until_date'], $post['valid_until_time'], $timezone, true);
    if (!$until['ok']) {
        $outcome['errors']['valid_until_date'] = $until['error'];
    }
    if ($from['ok'] && $until['ok'] && $from['utc'] !== null && $until['utc'] !== null && strcmp($until['utc'], $from['utc']) <= 0) {
        $outcome['errors']['valid_until_date'] = 'The end must be after the start.';
    }

    $outcome['validFrom'] = $from['utc'];
    $outcome['validUntil'] = $until['utc'];
    $outcome['recommended'] = (isset($post['recommended']) && $post['recommended'] === '1');

    if ($outcome['errors'] !== []) {
        $outcome['operations'] = [];
    }

    return $outcome;
}

/**
 * ⚠️ "Missing $ / €" warnings for a plan: every price group that has a current
 *    price in at least one offered currency but not in another. PURE.
 *
 * @param array<int, array<string, mixed>> $rows    The plan's price rows
 * @param string                           $kind    The plan's kind
 * @param array<int, string>               $offered Offered currencies
 * @return array<int, string> Plain-English lines
 */
function adminPlansMissingCurrencyWarnings(array $rows, string $kind, array $offered): array
{
    $warnings = [];

    foreach (adminPlansPriceGroups($kind) as $groupKey => $group) {
        $current = adminPlansCurrentByCurrency($rows, $groupKey);
        if ($current === []) {
            continue;
        }
        $missing = [];
        foreach ($offered as $currency) {
            if (!isset($current[$currency])) {
                $missing[] = $currency;
            }
        }
        if ($missing !== []) {
            $warnings[] = $group['label'] . ': no price in ' . implode(', ', $missing) . ' yet.';
        }
    }

    return $warnings;
}

// ==============================================================================
// 🧠 PURE: the mirror into the older single-price columns (plan §4.7)
// ==============================================================================

/**
 * 🪞 A plan's monthly and yearly prices on sale now in one currency, chosen by
 *    GROUP, never by the recommended flag. PURE.
 *
 * Since review finding F6 this is the shared pricingCurrentGroupPrices()
 * (fn_pricing.php), which the public page also uses live when it loads, so
 * the copy into the older columns and the page always choose the same price.
 *
 * @param array<int, array<string, mixed>> $rows     The plan's price rows
 * @param string                           $currency The main currency (billing_default_currency)
 * @param string                           $nowUtc   "Y-m-d H:i:s" in UTC
 * @return array{monthly: string|null, yearly: string|null, hasAnyPrice: bool, managed: array{monthly: bool, yearly: bool}}
 *         amounts as DECIMAL text; hasAnyPrice = the plan has any current price in any currency;
 *         managed = the group has price rows (so the page takes its figure from the price list)
 */
function adminMirrorSelection(array $rows, string $currency, string $nowUtc): array
{
    return pricingCurrentGroupPrices($rows, $currency, $nowUtc);
}

/**
 * 🪞 What the mirror should write to one plan's older columns. PURE.
 *
 * Since review finding F6 the public page works its figures out from the
 * price list live (pricingApplyPriceList()); these older columns are what it
 * falls back to for a price group with no price rows at all, and what older
 * code reads (for example the free-trial plan list).
 *
 * Rules:
 *   - a plan with no current price at all (the free plans) is left alone;
 *   - the plan already uses the main currency: each of monthly / yearly that
 *     has a current price is copied; one that has none is LEFT AS IT IS, with
 *     a warning saying what the page shows instead;
 *   - the plan uses another currency (the main currency was changed): the
 *     monthly price must exist, then the currency and monthly price are
 *     copied and the yearly price is copied or set to 0.00 (no yearly price);
 *     without a monthly price nothing changes, with a warning.
 *
 * @param array<string, mixed> $plan      priceMonthly, priceYearly, currency (stored values)
 * @param array<string, mixed> $selection From adminMirrorSelection()
 * @param string               $currency  The main currency
 * @return array{change: bool, values: array{priceMonthly: string, priceYearly: string, currency: string}, warnings: array<int, string>}
 */
function adminMirrorDecision(array $plan, array $selection, string $currency): array
{
    $storedCurrency = (string) $plan['currency'];
    $storedMonthly = (string) $plan['priceMonthly'];
    $storedYearly = (string) $plan['priceYearly'];

    $decision = [
        'change'   => false,
        'values'   => ['priceMonthly' => $storedMonthly, 'priceYearly' => $storedYearly, 'currency' => $storedCurrency],
        'warnings' => [],
    ];

    if ($selection['hasAnyPrice'] !== true) {
        return $decision;
    }

    $currencyFacts = moneyCurrencyInfo();
    $knownStored = isset($currencyFacts[$storedCurrency]);

    if ($storedCurrency === $currency && $knownStored) {
        foreach (['monthly' => 'priceMonthly', 'yearly' => 'priceYearly'] as $group => $column) {
            $storedMinor = moneyStoredDecimalToMinor($decision['values'][$column], $currency);
            if ($selection[$group] === null) {
                // 🤫 Nothing stored (0.00, for example Enterprise has no yearly
                //    price) and nothing to copy: nothing to warn about.
                // 🗓️ Review finding F6: the page now reads the price list live,
                //    so the words say what visitors really see.
                if ($storedMinor !== null && $storedMinor > 0) {
                    if (isset($selection['managed'][$group]) && $selection['managed'][$group] === true) {
                        $decision['warnings'][] = 'No ' . $group . ' price in ' . $currency . ' is on sale right now, so the pricing page shows no ' . $group . ' price for this plan.';
                    } else {
                        $decision['warnings'][] = 'No ' . $group . ' price in ' . $currency . ' in the price list yet, so the pricing page keeps showing the older one (' . moneyFormat($storedMinor, $currency) . ').';
                    }
                }
                continue;
            }
            $newMinor = moneyStoredDecimalToMinor((string) $selection[$group], $currency);
            if ($storedMinor !== $newMinor) {
                $decision['values'][$column] = (string) $selection[$group];
                $decision['change'] = true;
            }
        }
        return $decision;
    }

    if ($selection['monthly'] === null) {
        $decision['warnings'][] = 'No current monthly price in ' . $currency . ' (the main currency), so the pricing page still shows this plan\'s prices in ' . $storedCurrency . '.';
        return $decision;
    }

    $decision['values']['currency'] = $currency;
    $decision['values']['priceMonthly'] = (string) $selection['monthly'];
    if ($selection['yearly'] === null) {
        $decision['values']['priceYearly'] = moneyMinorToDecimalString(0, $currency);
    } else {
        $decision['values']['priceYearly'] = (string) $selection['yearly'];
    }
    $decision['change'] = true;

    return $decision;
}

// ==============================================================================
// 🗄️ DATABASE: reading
// ==============================================================================

/**
 * 🕰️ Now, in UTC, as the database stores it ("Y-m-d H:i:s").
 *
 * @return string
 */
function adminPlansNowUtc(): string
{
    return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
}

/**
 * 📋 The plan columns every query here reads (fixed text, never input).
 *
 * @return string
 */
function adminPlansColumnList(): string
{
    return '`planID`, `planKey`, `planKind`, `ctaMode`, `contactUrl`, `planName`, `description`, '
        . '`priceMonthly`, `priceYearly`, `currency`, `featuresJSON`, `limitsJSON`, `isActive`, `sortOrder`, '
        . '`availableFrom`, `availableUntil`, `maxSales`, `basedOnPlanID`, `pricePromiseMode`, `pricePromiseMonths`, '
        . '`createdAt`, `updatedAt`';
}

/**
 * 📋 The price columns every query here reads (fixed text, never input).
 *
 * @return string
 */
function adminPlansPriceColumnList(): string
{
    return '`pricePointID`, `planID`, `priceKey`, `priceGroupKey`, `billingInterval`, `intervalCount`, `amount`, '
        . '`currency`, `grantsJSON`, `validFrom`, `validUntil`, `isDefault`, `isActive`, `sortOrder`, `createdAt`, `updatedAt`';
}

/**
 * 🧾 The plan fingerprint used by the plan forms: every column EXCEPT the
 *    three older price columns and updatedAt. Those change when a price is
 *    saved (the mirror), which is not a change to anything the plan form
 *    shows, so a price save never makes an open plan form "out of date".
 *    Every column the form does show is covered.
 *
 * @param array<string, mixed> $row A plan row from adminPlansLoadPlan()
 * @return string
 */
function adminPlansFormFingerprint(array $row): string
{
    unset($row['priceMonthly'], $row['priceYearly'], $row['currency'], $row['updatedAt']);

    return adminPlansRowFingerprint($row);
}

/**
 * 📥 One plan row, optionally LOCKED until the transaction ends.
 *
 * @param int  $planID Plan ID
 * @param bool $lock   True inside a transaction: SELECT ... FOR UPDATE
 * @return array{ok: bool, row: array<string, mixed>|null} ok false = could not read; row null = no such plan
 */
function adminPlansLoadPlan(int $planID, bool $lock): array
{
    if ($planID <= 0 || !function_exists('dbQuery')) {
        return ['ok' => ($planID <= 0), 'row' => null];
    }

    $lockClause = '';
    if ($lock) {
        $lockClause = ' FOR UPDATE';
    }

    try {
        $stmt = dbQuery('SELECT ' . adminPlansColumnList() . ' FROM `tblSubscriptionPlans` WHERE `planID` = ? LIMIT 1' . $lockClause, 'i', [$planID]);
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Admin plans: a plan could not be read', get_class($e));
        }
        return ['ok' => false, 'row' => null];
    }

    if (!is_array($row)) {
        return ['ok' => true, 'row' => null];
    }

    return ['ok' => true, 'row' => $row];
}

/**
 * 📥 Every plan, in display order.
 *
 * @return array<int, array<string, mixed>>|null planID => row; null when it could not be read
 */
function adminPlansLoadAllPlans(): ?array
{
    if (!function_exists('dbQuery')) {
        return null;
    }

    try {
        $stmt = dbQuery('SELECT ' . adminPlansColumnList() . ' FROM `tblSubscriptionPlans` ORDER BY `planKind`, `sortOrder`, `planID`');
        $result = $stmt->get_result();
        $plans = [];
        while ($row = $result->fetch_assoc()) {
            $plans[(int) $row['planID']] = $row;
        }
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Admin plans: the plan list could not be read', get_class($e));
        }
        return null;
    }

    return $plans;
}

/**
 * 📥 One plan's prices (every row, retired ones too), optionally LOCKED.
 *
 * @param int  $planID Plan ID
 * @param bool $lock   True inside a transaction: SELECT ... FOR UPDATE
 * @return array<int, array<string, mixed>>|null Rows in display order; null when they could not be read
 */
function adminPlansLoadPrices(int $planID, bool $lock): ?array
{
    if (!function_exists('dbQuery')) {
        return null;
    }

    $lockClause = '';
    if ($lock) {
        $lockClause = ' FOR UPDATE';
    }

    try {
        $stmt = dbQuery(
            'SELECT ' . adminPlansPriceColumnList() . ' FROM `tblPlanPricePoints` WHERE `planID` = ? '
            . 'ORDER BY `sortOrder`, `pricePointID`' . $lockClause,
            'i',
            [$planID]
        );
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Admin plans: prices could not be read', get_class($e));
        }
        return null;
    }

    return $rows;
}

/**
 * 📥 Every plan's prices, grouped by plan (for the list page).
 *
 * @return array<int, array<int, array<string, mixed>>>|null planID => rows; null when they could not be read
 */
function adminPlansLoadAllPrices(): ?array
{
    if (!function_exists('dbQuery')) {
        return null;
    }

    try {
        $stmt = dbQuery('SELECT ' . adminPlansPriceColumnList() . ' FROM `tblPlanPricePoints` ORDER BY `planID`, `sortOrder`, `pricePointID`');
        $result = $stmt->get_result();
        $byPlan = [];
        while ($row = $result->fetch_assoc()) {
            $owner = (int) $row['planID'];
            if (!isset($byPlan[$owner])) {
                $byPlan[$owner] = [];
            }
            $byPlan[$owner][] = $row;
        }
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Admin plans: the price list could not be read', get_class($e));
        }
        return null;
    }

    return $byPlan;
}

/**
 * 🔢 How many records point at each of these prices (subscriptions, payments
 *    and one-off purchases together) - the P1 test.
 *
 * With $lock (inside a transaction, after the price rows themselves are
 * locked) the counts are LOCKING reads: they see the newest committed rows,
 * not an older snapshot, so a purchase recorded a moment ago is counted.
 *
 * @param array<int, int> $pricePointIDs Price IDs
 * @param bool            $lock          True inside a transaction
 * @return array<int, int>|null pricePointID => count; null when they could not be counted
 */
function adminPlansPriceReferences(array $pricePointIDs, bool $lock): ?array
{
    $counts = [];
    foreach ($pricePointIDs as $pricePointID) {
        $counts[(int) $pricePointID] = 0;
    }

    if ($counts === []) {
        return $counts;
    }

    if (!function_exists('dbQuery')) {
        return null;
    }

    $lockClause = '';
    if ($lock) {
        $lockClause = ' LOCK IN SHARE MODE';
    }

    // 📋 The three tables that can point at a price (fixed names, never input).
    $tables = ['tblSubscriptions', 'tblPayments', 'tblOneOffPurchases'];
    $placeholders = implode(', ', array_fill(0, count($counts), '?'));
    $ids = array_keys($counts);

    try {
        foreach ($tables as $table) {
            $stmt = dbQuery(
                'SELECT `pricePointID`, COUNT(*) AS `uses` FROM `' . $table . '` WHERE `pricePointID` IN (' . $placeholders . ') GROUP BY `pricePointID`' . $lockClause,
                str_repeat('i', count($ids)),
                $ids
            );
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $counts[(int) $row['pricePointID']] += (int) $row['uses'];
            }
            $stmt->close();
        }
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Admin plans: price use could not be counted', get_class($e));
        }
        return null;
    }

    return $counts;
}

/**
 * 🔢 Everything that refers to a plan (the P4 test), optionally as LOCKING
 *    reads (see adminPlansPriceReferences()).
 *
 * @param int  $planID Plan ID
 * @param bool $lock   True inside a transaction
 * @return array{subscriptions: int, paying: int, oneOffPurchases: int, payments: int, discounts: int}|null
 */
function adminPlansUsage(int $planID, bool $lock): ?array
{
    if (!function_exists('dbQuery')) {
        return null;
    }

    $lockClause = '';
    if ($lock) {
        $lockClause = ' LOCK IN SHARE MODE';
    }

    $priceSubquery = '(SELECT `pricePointID` FROM `tblPlanPricePoints` WHERE `planID` = ?)';

    try {
        $usage = ['subscriptions' => 0, 'paying' => 0, 'oneOffPurchases' => 0, 'payments' => 0, 'discounts' => 0];

        $stmt = dbQuery(
            'SELECT COUNT(*) AS `total`, COALESCE(SUM(`status` IN (\'active\', \'trialing\', \'past_due\')), 0) AS `paying` '
            . 'FROM `tblSubscriptions` WHERE `planID` = ? OR `pricePointID` IN ' . $priceSubquery . $lockClause,
            'ii',
            [$planID, $planID]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $usage['subscriptions'] = (int) $row['total'];
        $usage['paying'] = (int) $row['paying'];

        $stmt = dbQuery('SELECT COUNT(*) AS `total` FROM `tblOneOffPurchases` WHERE `pricePointID` IN ' . $priceSubquery . $lockClause, 'i', [$planID]);
        $usage['oneOffPurchases'] = (int) $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

        $stmt = dbQuery('SELECT COUNT(*) AS `total` FROM `tblPayments` WHERE `pricePointID` IN ' . $priceSubquery . $lockClause, 'i', [$planID]);
        $usage['payments'] = (int) $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

        $stmt = dbQuery('SELECT COUNT(*) AS `total` FROM `tblDiscounts` WHERE `appliesPlanID` = ?' . $lockClause, 'i', [$planID]);
        $usage['discounts'] = (int) $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Admin plans: plan use could not be counted', get_class($e));
        }
        return null;
    }

    return $usage;
}

/**
 * 👥 Paying subscribers (active, trialing or past due) of every plan.
 *
 * @return array<int, int>|null planID => count; null when they could not be counted
 */
function adminPlansPayingCounts(): ?array
{
    if (!function_exists('dbQuery')) {
        return null;
    }

    try {
        $stmt = dbQuery(
            'SELECT `planID`, COUNT(*) AS `paying` FROM `tblSubscriptions` '
            . 'WHERE `status` IN (\'active\', \'trialing\', \'past_due\') GROUP BY `planID`'
        );
        $result = $stmt->get_result();
        $counts = [];
        while ($row = $result->fetch_assoc()) {
            $counts[(int) $row['planID']] = (int) $row['paying'];
        }
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Admin plans: subscribers could not be counted', get_class($e));
        }
        return null;
    }

    return $counts;
}

/**
 * 🔢 How many of a plan have been sold (plan §4.5 - COUNTED, never stored).
 *
 * Lifetime deals and credit packs: completed one-off purchases of the plan's
 * prices. Ordinary plans: every subscription on the plan, of any status (the
 * careful choice while checkout does not exist yet - a cap can never be set
 * below anyone who has ever had the plan).
 *
 * @param array<string, mixed> $plan A plan row
 * @param bool                 $lock True inside a transaction (locking read)
 * @return int|null null when it could not be counted
 */
function adminPlansSoldCount(array $plan, bool $lock): ?int
{
    if (!function_exists('dbQuery')) {
        return null;
    }

    $lockClause = '';
    if ($lock) {
        $lockClause = ' LOCK IN SHARE MODE';
    }

    try {
        if ((string) $plan['planKind'] === 'standard') {
            $stmt = dbQuery('SELECT COUNT(*) AS `sold` FROM `tblSubscriptions` WHERE `planID` = ?' . $lockClause, 'i', [(int) $plan['planID']]);
        } else {
            $stmt = dbQuery(
                'SELECT COUNT(*) AS `sold` FROM `tblOneOffPurchases` WHERE `status` = \'completed\' '
                . 'AND `pricePointID` IN (SELECT `pricePointID` FROM `tblPlanPricePoints` WHERE `planID` = ?)' . $lockClause,
                'i',
                [(int) $plan['planID']]
            );
        }
        $sold = (int) $stmt->get_result()->fetch_assoc()['sold'];
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Admin plans: sales could not be counted', get_class($e));
        }
        return null;
    }

    return $sold;
}

/**
 * ⚙️ The plan keys the settings name (P5): the past-due downgrade plan and
 *    the trial plan. With $lock the two rows are read with a shared lock, so
 *    the setting cannot be pointed at a plan while it is being taken off sale.
 *
 * @param bool $lock True inside a transaction
 * @return array<string, string>|null setting key => plan key; null when they could not be read
 */
function adminPlansSettingPlanKeys(bool $lock): ?array
{
    if (!function_exists('dbQuery')) {
        return null;
    }

    $lockClause = '';
    if ($lock) {
        $lockClause = ' LOCK IN SHARE MODE';
    }

    $keys = ['billing_past_due_downgrade_plan_key', 'billing_trial_plan_key'];
    $found = [];

    try {
        $stmt = dbQuery(
            'SELECT `settingKey`, `settingValue` FROM `tblSettings` WHERE `settingKey` IN (?, ?)' . $lockClause,
            'ss',
            $keys
        );
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $found[(string) $row['settingKey']] = trim((string) $row['settingValue']);
        }
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Admin plans: the plan settings could not be read', get_class($e));
        }
        return null;
    }

    // 📌 The past-due setting falls back to the free plan when it has no row
    //    (fn_features.php does the same), so that default is protected too.
    if (!isset($found['billing_past_due_downgrade_plan_key']) || $found['billing_past_due_downgrade_plan_key'] === '') {
        $found['billing_past_due_downgrade_plan_key'] = CUERCODE_PLAN_KEY_FREE;
    }

    return $found;
}

/**
 * 💱 The offered currencies (billing_currencies) and the main currency
 *    (billing_default_currency), read fresh from the database and checked.
 *
 * A broken or missing list falls back to the main currency alone; a broken
 * main currency falls back to the first offered one - never to a made-up
 * currency, and never fails open to "every currency".
 *
 * 🔒 $lock (review of 7n Phase 2, race found by reading and reproduced on
 *    MySQL 8.0.36 and MariaDB 10.6): a price save used to read the main
 *    currency BEFORE its transaction, so a change of main currency committed
 *    in between (whose own copy had just put every plan into the new
 *    currency) was undone for that plan - it was copied back into the old
 *    one. The price saves now read these two rows FIRST inside their
 *    transaction with a shared lock: a currency change in progress makes the
 *    save wait and then read the new value, and the change waits for a save
 *    in progress. Both take the settings before the plans, so they cannot
 *    deadlock.
 *
 * @param bool $lock True inside a transaction: read with LOCK IN SHARE MODE
 * @return array{ok: bool, offered: array<int, string>, default: string}
 *         ok false = the settings could not be read (offered/default are then a safe guess)
 */
function adminPlansCurrencySettings(bool $lock = false): array
{
    $outcome = ['ok' => false, 'offered' => [], 'default' => ''];

    if (!function_exists('dbQuery')) {
        return $outcome;
    }

    $lockClause = '';
    if ($lock) {
        $lockClause = ' LOCK IN SHARE MODE';
    }

    $stored = [];
    try {
        $stmt = dbQuery(
            'SELECT `settingKey`, `settingValue` FROM `tblSettings` WHERE `settingKey` IN (?, ?)' . $lockClause,
            'ss',
            ['billing_currencies', 'billing_default_currency']
        );
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $stored[(string) $row['settingKey']] = (string) $row['settingValue'];
        }
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Admin plans: the currency settings could not be read', get_class($e));
        }
        return $outcome;
    }

    $default = '';
    if (isset($stored['billing_default_currency'])) {
        $default = strtoupper(trim($stored['billing_default_currency']));
    }

    $offered = [];
    if (isset($stored['billing_currencies'])) {
        $parsed = moneyParseCurrencyList($stored['billing_currencies']);
        if ($parsed['ok']) {
            $offered = $parsed['codes'];
        }
    }

    // 🌍 A main currency that cannot be offered (unknown, or a three-decimal
    //    one put in by hand) is replaced by the first offered one.
    if (!moneyCurrencyCanBeOffered($default)) {
        $default = '';
        if ($offered !== []) {
            $default = $offered[0];
        }
    }

    if ($default !== '' && !in_array($default, $offered, true)) {
        array_unshift($offered, $default);
    }

    $outcome['ok'] = ($default !== '');
    $outcome['offered'] = $offered;
    $outcome['default'] = $default;

    return $outcome;
}

// ==============================================================================
// 💾 DATABASE: saving - the shared pieces
// ==============================================================================

/**
 * 📦 A result in the one shape every save here returns.
 *
 * @param bool                 $ok      Saved (or nothing needed saving)
 * @param string               $message Plain English for the page
 * @param array<string, mixed> $extra   errors, stale, warnings, planID, changed
 * @return array{ok: bool, message: string, errors: array<string, string>, stale: bool, warnings: array<int, string>, planID: int, changed: bool}
 */
function adminPlansResult(bool $ok, string $message, array $extra = []): array
{
    $result = [
        'ok'       => $ok,
        'message'  => $message,
        'errors'   => [],
        'stale'    => false,
        'warnings' => [],
        'planID'   => 0,
        'changed'  => false,
    ];

    foreach ($extra as $key => $value) {
        if (array_key_exists($key, $result)) {
            $result[$key] = $value;
        }
    }

    return $result;
}

/**
 * 🔁 Run one save in ONE transaction. The work returns a result
 *    (adminPlansResult()): ok true = commit, ok false = roll back. Anything
 *    thrown rolls back and gives "Not saved"; only the kind of problem is
 *    logged.
 *
 * @param callable $work Does the locking, checking, writing and auditing
 * @return array See adminPlansResult()
 */
function adminPlansInTransaction(callable $work): array
{
    $notSaved = adminPlansResult(false, 'Not saved. Nothing was changed. Please try again.');

    if (!function_exists('getDB') || !function_exists('dbQuery')) {
        return $notSaved;
    }

    // 🧾 No change without its audit row (plan §6.7).
    if (!adminTableExists('tblAdminAuditLog')) {
        return adminPlansResult(false, 'Database upgrades are waiting, so nothing can be saved yet.');
    }

    $db = getDB();

    try {
        $db->begin_transaction();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Admin plans: a save could not start', get_class($e));
        }
        return $notSaved;
    }

    try {
        $result = $work();
    } catch (\Throwable $e) {
        adminRollbackQuietly($db);
        if (function_exists('logError')) {
            logError('error', 'Admin plans: a save failed and was undone', get_class($e));
        }
        return $notSaved;
    }

    if (!is_array($result) || !isset($result['ok']) || $result['ok'] !== true) {
        adminRollbackQuietly($db);
        if (!is_array($result)) {
            return $notSaved;
        }
        return $result;
    }

    try {
        $db->commit();
    } catch (\Throwable $e) {
        adminRollbackQuietly($db);
        if (function_exists('logError')) {
            logError('error', 'Admin plans: a save could not be finished', get_class($e));
        }
        return $notSaved;
    }

    return $result;
}

/**
 * 🧾 Write one audit row inside the current transaction, or THROW so the
 *    whole save is undone (a change never exists without its audit row).
 *
 * @param array<string, mixed> $admin      From requireGlobalAdminPage()
 * @param string               $action     One of adminAuditActionNames()
 * @param string               $entityType 'plan' or 'price_point'
 * @param string               $entityKey  The plan key, or "<planKey>/<priceKey>"
 * @param array<string, mixed> $changes    {"field": {"old": x, "new": y}}
 * @param string|null          $reason     Why (optional)
 * @return void
 * @throws \RuntimeException When the row could not be written
 */
function adminPlansAudit(array $admin, string $action, string $entityType, string $entityKey, array $changes, ?string $reason = null): void
{
    $entry = adminAuditEntryForAdmin($admin, $action, $entityType, $entityKey, 'success', $changes, $reason);

    if (!adminAuditWrite($entry)) {
        throw new \RuntimeException('The admin change log row could not be written.');
    }
}

/**
 * 🧊 A JSON object column (features / limits) re-encoded by the server: the
 *    stored text is decoded and written back as a JSON object, never accepted
 *    raw from a form (plan §10 S31). Anything that is not an object becomes {}.
 *
 * @param mixed $json The stored column value
 * @return string JSON object text
 */
function adminPlansCleanJsonObject(mixed $json): string
{
    $decoded = null;
    if (is_string($json) && $json !== '') {
        $decoded = json_decode($json, true);
    }

    if (!is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
        return '{}';
    }

    return json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

/**
 * 🔁 Bind type for a value (null and text as 's', whole numbers as 'i').
 *
 * @param mixed $value
 * @return string
 */
function adminPlansBindType(mixed $value): string
{
    if (is_int($value)) {
        return 'i';
    }

    return 's';
}

// ==============================================================================
// 💾 DATABASE: plans
// ==============================================================================

/**
 * ➕ Make a new plan (plan.create). It starts OFF SALE, so nothing appears
 *    on the public page until "Put this plan on sale" is pressed - and it has
 *    no prices until they are added.
 *
 * Features and limits are copied from the chosen plan (re-encoded by the
 * server), or start empty.
 *
 * @param array<string, mixed> $admin From requireGlobalAdminPage()
 * @param array<string, mixed> $post  The $_POST array
 * @return array See adminPlansResult() (planID set when made)
 */
function adminPlansCreate(array $admin, array $post): array
{
    $plans = adminPlansLoadAllPlans();
    $currencies = adminPlansCurrencySettings();
    if ($plans === null || !$currencies['ok']) {
        return adminPlansResult(false, 'The plans could not be read, so nothing was saved. Please try again.');
    }

    $existingKeys = [];
    foreach ($plans as $plan) {
        $existingKeys[] = (string) $plan['planKey'];
    }

    $checked = adminPlansValidatePlanForm($post, null, [
        'timezone'     => adminDisplayTimezone(),
        'existingKeys' => $existingKeys,
        'sourcePlans'  => $plans,
    ]);

    if ($checked['errors'] !== []) {
        return adminPlansResult(false, 'There is a problem. Nothing was saved.', ['errors' => $checked['errors']]);
    }

    $values = $checked['values'];
    $defaultCurrency = $currencies['default'];

    return adminPlansInTransaction(function () use ($admin, $values, $defaultCurrency): array {
        // 🔒 A locking read on the key: two admins making the same key at
        //    once queue up here, and the second is told it is taken.
        $stmt = dbQuery('SELECT `planID` FROM `tblSubscriptionPlans` WHERE `planKey` = ? FOR UPDATE', 's', [$values['planKey']]);
        $taken = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (is_array($taken)) {
            return adminPlansResult(false, 'There is a problem. Nothing was saved.', ['errors' => ['plan_key' => 'A plan with that key already exists. Choose another key.']]);
        }

        $features = '{}';
        $limits = '{}';
        $sourceKey = null;
        if ($values['basedOnPlanID'] !== null) {
            $source = adminPlansLoadPlan((int) $values['basedOnPlanID'], true);
            if (!$source['ok']) {
                throw new \RuntimeException('The plan to copy could not be read.');
            }
            if ($source['row'] === null) {
                return adminPlansResult(false, 'There is a problem. Nothing was saved.', ['errors' => ['copy_from' => 'That plan no longer exists. Choose another (or none).']]);
            }
            $features = adminPlansCleanJsonObject($source['row']['featuresJSON']);
            $limits = adminPlansCleanJsonObject($source['row']['limitsJSON']);
            $sourceKey = (string) $source['row']['planKey'];
        }

        $zero = moneyMinorToDecimalString(0, $defaultCurrency);

        $columns = [
            'planKey'            => $values['planKey'],
            'planKind'           => $values['planKind'],
            'ctaMode'            => $values['ctaMode'],
            'contactUrl'         => $values['contactUrl'],
            'planName'           => $values['planName'],
            'description'        => $values['description'],
            'priceMonthly'       => $zero,
            'priceYearly'        => $zero,
            'currency'           => $defaultCurrency,
            'featuresJSON'       => $features,
            'limitsJSON'         => $limits,
            'isActive'           => 0,
            'sortOrder'          => $values['sortOrder'],
            'availableFrom'      => $values['availableFrom'],
            'availableUntil'     => $values['availableUntil'],
            'maxSales'           => $values['maxSales'],
            'basedOnPlanID'      => $values['basedOnPlanID'],
            'pricePromiseMode'   => $values['pricePromiseMode'],
            'pricePromiseMonths' => $values['pricePromiseMonths'],
        ];

        $names = [];
        $types = '';
        $params = [];
        foreach ($columns as $column => $value) {
            $names[] = '`' . $column . '`';
            $types .= adminPlansBindType($value);
            $params[] = $value;
        }

        $stmt = dbQuery(
            'INSERT INTO `tblSubscriptionPlans` (' . implode(', ', $names) . ') VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')',
            $types,
            $params
        );
        $planID = (int) $stmt->insert_id;
        $stmt->close();

        $after = $columns;
        unset($after['featuresJSON'], $after['limitsJSON']);
        $after['copiedFrom'] = $sourceKey;
        adminPlansAudit($admin, 'plan.create', 'plan', $values['planKey'], adminAuditChanges([], $after, []));

        return adminPlansResult(true, 'The plan was made. It is not on sale yet: add its prices, then put it on sale.', ['planID' => $planID, 'changed' => true]);
    });
}

/**
 * ✏️ Save the plan form (plan.update). Never changes the key or kind (P6),
 *    never the features, limits or prices.
 *
 * @param array<string, mixed> $admin       From requireGlobalAdminPage()
 * @param int                  $planID      Plan ID (from the address)
 * @param string               $fingerprint The form's hidden fingerprint
 * @param array<string, mixed> $post        The $_POST array
 * @return array See adminPlansResult()
 */
function adminPlansUpdate(array $admin, int $planID, string $fingerprint, array $post): array
{
    $timezone = adminDisplayTimezone();

    return adminPlansInTransaction(function () use ($admin, $planID, $fingerprint, $post, $timezone): array {
        $loaded = adminPlansLoadPlan($planID, true);
        if (!$loaded['ok']) {
            throw new \RuntimeException('The plan could not be read.');
        }
        if ($loaded['row'] === null) {
            return adminPlansResult(false, 'That plan does not exist (it may have just been deleted).');
        }
        $current = $loaded['row'];

        if ($fingerprint === '' || !hash_equals(adminPlansFormFingerprint($current), $fingerprint)) {
            return adminPlansResult(false, adminPlansStaleMessage(), ['stale' => true]);
        }

        $sold = adminPlansSoldCount($current, true);
        if ($sold === null) {
            throw new \RuntimeException('Sales could not be counted.');
        }

        // 🛡️ Review finding F3: is this a protected plan (P5)? Read under the
        //    same shared lock the off-sale action uses, so a setting cannot be
        //    pointed at this plan while its dates are being saved.
        $settingKeys = adminPlansSettingPlanKeys(true);
        if ($settingKeys === null) {
            throw new \RuntimeException('The plan settings could not be read.');
        }

        $checked = adminPlansValidatePlanForm($post, $current, [
            'timezone'        => $timezone,
            'soldCount'       => $sold,
            'protectedReason' => adminPlansProtectedReason((string) $current['planKey'], $settingKeys),
        ]);
        if ($checked['errors'] !== []) {
            return adminPlansResult(false, 'There is a problem. Nothing was saved.', ['errors' => $checked['errors']]);
        }

        $before = [];
        foreach (array_keys($checked['values']) as $column) {
            $before[$column] = $current[$column];
        }
        $changes = adminAuditChanges($before, $checked['values'], []);
        if ($changes === []) {
            return adminPlansResult(true, 'Nothing was different, so nothing was saved.');
        }

        $sets = [];
        $types = '';
        $params = [];
        foreach (array_keys($changes) as $column) {
            $sets[] = '`' . $column . '` = ?';
            $types .= adminPlansBindType($checked['values'][$column]);
            $params[] = $checked['values'][$column];
        }
        $types .= 'i';
        $params[] = $planID;

        $stmt = dbQuery('UPDATE `tblSubscriptionPlans` SET ' . implode(', ', $sets) . ' WHERE `planID` = ?', $types, $params);
        $stmt->close();

        adminPlansAudit($admin, 'plan.update', 'plan', (string) $current['planKey'], $changes);

        return adminPlansResult(true, 'Saved: ' . count($changes) . ' change(s), recorded in the admin change log.', ['planID' => $planID, 'changed' => true]);
    });
}

/**
 * 🏷️ Put a plan on sale (plan.restore) or take it off sale (plan.retire).
 *
 * Taking it off sale hides it from new customers only: anyone already on it
 * keeps it (the plan still works for them). Refused for the protected plans
 * (P5). Taking off sale needs the server-checked confirmation tick.
 *
 * @param array<string, mixed> $admin       From requireGlobalAdminPage()
 * @param int                  $planID      Plan ID
 * @param string               $fingerprint The form's hidden fingerprint
 * @param bool                 $onSale      True = put on sale, false = take off sale
 * @param bool                 $confirmed   The "I understand" tick (needed to take off sale)
 * @return array See adminPlansResult()
 */
function adminPlansSetOnSale(array $admin, int $planID, string $fingerprint, bool $onSale, bool $confirmed): array
{
    return adminPlansInTransaction(function () use ($admin, $planID, $fingerprint, $onSale, $confirmed): array {
        $loaded = adminPlansLoadPlan($planID, true);
        if (!$loaded['ok']) {
            throw new \RuntimeException('The plan could not be read.');
        }
        if ($loaded['row'] === null) {
            return adminPlansResult(false, 'That plan does not exist (it may have just been deleted).');
        }
        $plan = $loaded['row'];

        if ($fingerprint === '' || !hash_equals(adminPlansFormFingerprint($plan), $fingerprint)) {
            return adminPlansResult(false, adminPlansStaleMessage(), ['stale' => true]);
        }

        $isActive = ((int) $plan['isActive'] === 1);

        if ($onSale) {
            if ($isActive) {
                return adminPlansResult(true, 'This plan is already on sale.');
            }
            $stmt = dbQuery('UPDATE `tblSubscriptionPlans` SET `isActive` = 1 WHERE `planID` = ?', 'i', [$planID]);
            $stmt->close();
            adminPlansAudit($admin, 'plan.restore', 'plan', (string) $plan['planKey'], ['isActive' => ['old' => 0, 'new' => 1]]);
            return adminPlansResult(true, 'The plan is on sale again.', ['planID' => $planID, 'changed' => true]);
        }

        $settingKeys = adminPlansSettingPlanKeys(true);
        if ($settingKeys === null) {
            throw new \RuntimeException('The plan settings could not be read.');
        }
        $protected = adminPlansProtectedReason((string) $plan['planKey'], $settingKeys);
        if ($protected !== '') {
            return adminPlansResult(false, $protected);
        }

        if (!$isActive) {
            return adminPlansResult(true, 'This plan is already off sale.');
        }

        if (!$confirmed) {
            return adminPlansResult(false, 'Tick the box to confirm, then press the button again. Nothing was changed.', ['errors' => ['confirm_retire' => 'Tick this box to confirm.']]);
        }

        $stmt = dbQuery('UPDATE `tblSubscriptionPlans` SET `isActive` = 0 WHERE `planID` = ?', 'i', [$planID]);
        $stmt->close();
        adminPlansAudit($admin, 'plan.retire', 'plan', (string) $plan['planKey'], ['isActive' => ['old' => 1, 'new' => 0]]);

        return adminPlansResult(true, 'The plan is off sale. Anyone already on it keeps it.', ['planID' => $planID, 'changed' => true]);
    });
}

/**
 * 🗑️ Delete a plan (plan.delete) - only one nothing has ever used (P4) and
 *    that is not protected (P5). Its unused prices go with it. Needs the
 *    server-checked confirmation tick.
 *
 * @param array<string, mixed> $admin       From requireGlobalAdminPage()
 * @param int                  $planID      Plan ID
 * @param string               $fingerprint The form's hidden fingerprint
 * @param bool                 $confirmed   The "I understand" tick
 * @return array See adminPlansResult()
 */
function adminPlansDelete(array $admin, int $planID, string $fingerprint, bool $confirmed): array
{
    return adminPlansInTransaction(function () use ($admin, $planID, $fingerprint, $confirmed): array {
        $loaded = adminPlansLoadPlan($planID, true);
        if (!$loaded['ok']) {
            throw new \RuntimeException('The plan could not be read.');
        }
        if ($loaded['row'] === null) {
            return adminPlansResult(false, 'That plan does not exist (it may have just been deleted).');
        }
        $plan = $loaded['row'];

        if ($fingerprint === '' || !hash_equals(adminPlansFormFingerprint($plan), $fingerprint)) {
            return adminPlansResult(false, adminPlansStaleMessage(), ['stale' => true]);
        }

        // 🔒 Lock the prices too, then count what refers to them (locking reads).
        if (adminPlansLoadPrices($planID, true) === null) {
            throw new \RuntimeException('The prices could not be read.');
        }
        $usage = adminPlansUsage($planID, true);
        $settingKeys = adminPlansSettingPlanKeys(true);
        if ($usage === null || $settingKeys === null) {
            throw new \RuntimeException('Plan use could not be counted.');
        }

        $decision = adminPlansDeleteDecision($usage, adminPlansProtectedReason((string) $plan['planKey'], $settingKeys));
        if (!$decision['allowed']) {
            return adminPlansResult(false, 'This plan cannot be deleted: ' . implode(' ', $decision['reasons']) . ' You can take it off sale instead.');
        }

        if (!$confirmed) {
            return adminPlansResult(false, 'Tick the box to confirm, then press the button again. Nothing was deleted.', ['errors' => ['confirm_delete' => 'Tick this box to confirm.']]);
        }

        $stmt = dbQuery('DELETE FROM `tblSubscriptionPlans` WHERE `planID` = ?', 'i', [$planID]);
        $deleted = $stmt->affected_rows;
        $stmt->close();
        if ($deleted !== 1) {
            throw new \RuntimeException('The plan was not deleted.');
        }

        adminPlansAudit($admin, 'plan.delete', 'plan', (string) $plan['planKey'], adminAuditChanges(
            ['planName' => $plan['planName'], 'planKind' => $plan['planKind']],
            ['planName' => null, 'planKind' => null],
            []
        ));

        return adminPlansResult(true, 'The plan "' . (string) $plan['planName'] . '" was deleted.', ['changed' => true]);
    });
}

/**
 * 🧬 "Make a new version for new customers" (plan.new_version + plan.retire):
 *    copies the plan (everything, including features, limits and its current
 *    prices, as new unbought prices) under a new key, then takes the old one
 *    off sale. Existing customers stay on the old one, which keeps working.
 *    This is the safe way to change a plan people already pay for (plan §4.3).
 *
 * Refused for a protected plan (P5: it can never be taken off sale).
 *
 * @param array<string, mixed> $admin       From requireGlobalAdminPage()
 * @param int                  $planID      The plan to copy
 * @param string               $fingerprint The form's hidden fingerprint
 * @param string               $newKey      The new version's key
 * @param bool                 $confirmed   The "I understand" tick
 * @return array See adminPlansResult() (planID = the new version)
 */
function adminPlansNewVersion(array $admin, int $planID, string $fingerprint, string $newKey, bool $confirmed): array
{
    $newKey = trim($newKey);

    return adminPlansInTransaction(function () use ($admin, $planID, $fingerprint, $newKey, $confirmed): array {
        $loaded = adminPlansLoadPlan($planID, true);
        if (!$loaded['ok']) {
            throw new \RuntimeException('The plan could not be read.');
        }
        if ($loaded['row'] === null) {
            return adminPlansResult(false, 'That plan does not exist (it may have just been deleted).');
        }
        $plan = $loaded['row'];

        if ($fingerprint === '' || !hash_equals(adminPlansFormFingerprint($plan), $fingerprint)) {
            return adminPlansResult(false, adminPlansStaleMessage(), ['stale' => true]);
        }

        $settingKeys = adminPlansSettingPlanKeys(true);
        if ($settingKeys === null) {
            throw new \RuntimeException('The plan settings could not be read.');
        }
        $protected = adminPlansProtectedReason((string) $plan['planKey'], $settingKeys);
        if ($protected !== '') {
            return adminPlansResult(false, 'A new version cannot replace this plan: ' . $protected);
        }

        if (!adminPlansKeyIsValid($newKey)) {
            return adminPlansResult(false, 'There is a problem. Nothing was saved.', ['errors' => ['new_key' => 'Type a key of 2 to 32 characters: a lower-case letter first, then lower-case letters, digits or underscores.']]);
        }

        $stmt = dbQuery('SELECT `planID` FROM `tblSubscriptionPlans` WHERE `planKey` = ? FOR UPDATE', 's', [$newKey]);
        $taken = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (is_array($taken)) {
            return adminPlansResult(false, 'There is a problem. Nothing was saved.', ['errors' => ['new_key' => 'A plan with that key already exists. Choose another key.']]);
        }

        if (!$confirmed) {
            return adminPlansResult(false, 'Tick the box to confirm, then press the button again. Nothing was changed.', ['errors' => ['confirm_version' => 'Tick this box to confirm.']]);
        }

        $prices = adminPlansLoadPrices($planID, true);
        if ($prices === null) {
            throw new \RuntimeException('The prices could not be read.');
        }

        // 🧬 The copy: every column except the ID, key and dates; it points
        //    back at the original, and is on sale only if the original was.
        $stmt = dbQuery(
            'INSERT INTO `tblSubscriptionPlans` (`planKey`, `planKind`, `ctaMode`, `contactUrl`, `planName`, `description`, '
            . '`priceMonthly`, `priceYearly`, `currency`, `featuresJSON`, `limitsJSON`, `isActive`, `sortOrder`, '
            . '`availableFrom`, `availableUntil`, `maxSales`, `basedOnPlanID`, `pricePromiseMode`, `pricePromiseMonths`) '
            . 'SELECT ?, `planKind`, `ctaMode`, `contactUrl`, `planName`, `description`, '
            . '`priceMonthly`, `priceYearly`, `currency`, `featuresJSON`, `limitsJSON`, `isActive`, `sortOrder`, '
            . '`availableFrom`, `availableUntil`, `maxSales`, `planID`, `pricePromiseMode`, `pricePromiseMonths` '
            . 'FROM `tblSubscriptionPlans` WHERE `planID` = ?',
            'si',
            [$newKey, $planID]
        );
        $newPlanID = (int) $stmt->insert_id;
        $stmt->close();
        if ($newPlanID <= 0) {
            throw new \RuntimeException('The copy was not made.');
        }

        // 💷 The current (not retired) prices are copied as NEW prices: nobody
        //    has bought them on the new version, so they start unfrozen.
        $copiedKeys = [];
        foreach ($prices as $price) {
            if ((int) $price['isActive'] !== 1) {
                continue;
            }
            $key = (string) $price['priceKey'];
            if ($price['priceGroupKey'] !== null) {
                $key = adminPlansNextPriceKey((string) $price['priceGroupKey'], (string) $price['currency'], $copiedKeys);
            }
            if ($key === '' || in_array($key, $copiedKeys, true)) {
                throw new \RuntimeException('A price key could not be made for the copy.');
            }
            $copiedKeys[] = $key;

            $stmt = dbQuery(
                'INSERT INTO `tblPlanPricePoints` (`planID`, `priceKey`, `priceGroupKey`, `billingInterval`, `intervalCount`, `amount`, '
                . '`currency`, `grantsJSON`, `validFrom`, `validUntil`, `isDefault`, `isActive`, `sortOrder`) '
                . 'SELECT ?, ?, `priceGroupKey`, `billingInterval`, `intervalCount`, `amount`, '
                . '`currency`, `grantsJSON`, `validFrom`, `validUntil`, `isDefault`, 1, `sortOrder` '
                . 'FROM `tblPlanPricePoints` WHERE `pricePointID` = ? AND `planID` = ?',
                'isii',
                [$newPlanID, $key, (int) $price['pricePointID'], $planID]
            );
            $stmt->close();
        }

        $wasActive = ((int) $plan['isActive'] === 1);
        if ($wasActive) {
            $stmt = dbQuery('UPDATE `tblSubscriptionPlans` SET `isActive` = 0 WHERE `planID` = ?', 'i', [$planID]);
            $stmt->close();
        }

        adminPlansAudit($admin, 'plan.new_version', 'plan', $newKey, [
            'basedOn'      => ['old' => null, 'new' => (string) $plan['planKey']],
            'copiedPrices' => ['old' => null, 'new' => $copiedKeys],
        ]);
        if ($wasActive) {
            adminPlansAudit($admin, 'plan.retire', 'plan', (string) $plan['planKey'], ['isActive' => ['old' => 1, 'new' => 0]], 'Replaced by the new version ' . $newKey);
        }

        return adminPlansResult(true, 'The new version "' . $newKey . '" was made with the same details and current prices. The old plan is off sale; anyone on it keeps it.', ['planID' => $newPlanID, 'changed' => true]);
    });
}

// ==============================================================================
// 💾 DATABASE: prices
// ==============================================================================

/**
 * 🔒 The shared start of every price save: lock the plan and ALL its prices,
 *    check the form is not out of date, and count (with locking reads) what
 *    refers to each price.
 *
 * @param int    $planID      Plan ID
 * @param string $fingerprint The form's price-list fingerprint
 * @return array{result: array|null, plan: array|null, prices: array, references: array<int, int>}
 *         result is set (a refusal to return) when the save must stop
 * @throws \RuntimeException When something could not be read
 */
function adminPricesLockAndCheck(int $planID, string $fingerprint): array
{
    $stop = ['result' => null, 'plan' => null, 'prices' => [], 'references' => []];

    $loaded = adminPlansLoadPlan($planID, true);
    if (!$loaded['ok']) {
        throw new \RuntimeException('The plan could not be read.');
    }
    if ($loaded['row'] === null) {
        $stop['result'] = adminPlansResult(false, 'That plan does not exist (it may have just been deleted).');
        return $stop;
    }

    $prices = adminPlansLoadPrices($planID, true);
    if ($prices === null) {
        throw new \RuntimeException('The prices could not be read.');
    }

    if ($fingerprint === '' || !hash_equals(adminPlansPricesFingerprint($prices), $fingerprint)) {
        $stop['result'] = adminPlansResult(false, adminPlansStaleMessage(), ['stale' => true]);
        return $stop;
    }

    $ids = [];
    foreach ($prices as $price) {
        $ids[] = (int) $price['pricePointID'];
    }
    $references = adminPlansPriceReferences($ids, true);
    if ($references === null) {
        throw new \RuntimeException('Price use could not be counted.');
    }

    return ['result' => null, 'plan' => $loaded['row'], 'prices' => $prices, 'references' => $references];
}

/**
 * 📝 "<planKey>/<priceKey>" - the audit entity key of a price.
 *
 * @param array<string, mixed> $plan  Plan row
 * @param string               $priceKey Price key
 * @return string
 */
function adminPricesEntityKey(array $plan, string $priceKey): string
{
    return (string) $plan['planKey'] . '/' . $priceKey;
}

/**
 * 💾 Save one price group (price.create / price.update): the amount in each
 *    offered currency, the group's sale dates and whether it is the
 *    recommended choice. Then the mirror runs (plan §4.7). All in ONE
 *    transaction with the audit rows.
 *
 * @param array<string, mixed> $admin       From requireGlobalAdminPage()
 * @param int                  $planID      Plan ID (from the address)
 * @param string               $groupKey    The group (must be one this kind of plan has)
 * @param string               $fingerprint The form's price-list fingerprint
 * @param array<string, mixed> $post        The $_POST array
 * @return array See adminPlansResult() (warnings = mirror notes)
 */
function adminPricesSaveGroup(array $admin, int $planID, string $groupKey, string $fingerprint, array $post): array
{
    $timezone = adminDisplayTimezone();

    return adminPlansInTransaction(function () use ($admin, $planID, $groupKey, $fingerprint, $post, $timezone): array {
        // 🔒 The currencies FIRST, inside the transaction, with a shared lock
        //    (see adminPlansCurrencySettings() for the race this closes).
        $currencies = adminPlansCurrencySettings(true);
        if (!$currencies['ok']) {
            return adminPlansResult(false, 'The currency settings could not be read, so nothing was saved. Please try again.');
        }

        $locked = adminPricesLockAndCheck($planID, $fingerprint);
        if ($locked['result'] !== null) {
            return $locked['result'];
        }
        $plan = $locked['plan'];
        $prices = $locked['prices'];

        $groups = adminPlansPriceGroups((string) $plan['planKind']);
        if (!isset($groups[$groupKey])) {
            return adminPlansResult(false, 'That kind of price cannot be set on this plan, so nothing was saved.');
        }
        $group = $groups[$groupKey];

        $processed = adminPlansProcessPriceGroup($post, $groupKey, $currencies['offered'], $prices, $locked['references'], $timezone);
        if ($processed['errors'] !== []) {
            return adminPlansResult(false, 'There is a problem. Nothing was saved.', ['errors' => $processed['errors']]);
        }

        $changed = 0;
        $isDefault = 0;
        if ($processed['recommended']) {
            $isDefault = 1;
        }

        $existingKeys = [];
        foreach ($prices as $price) {
            $existingKeys[] = (string) $price['priceKey'];
        }

        // ➕ / ✏️ The amounts.
        foreach ($processed['operations'] as $operation) {
            if ($operation['op'] === 'create') {
                $priceKey = adminPlansNextPriceKey($groupKey, $operation['currency'], $existingKeys);
                if ($priceKey === '') {
                    throw new \RuntimeException('No free price key.');
                }
                $existingKeys[] = $priceKey;

                $stmt = dbQuery(
                    'INSERT INTO `tblPlanPricePoints` (`planID`, `priceKey`, `priceGroupKey`, `billingInterval`, `intervalCount`, `amount`, '
                    . '`currency`, `grantsJSON`, `validFrom`, `validUntil`, `isDefault`, `isActive`, `sortOrder`) '
                    . 'VALUES (?, ?, ?, ?, 1, ?, ?, NULL, ?, ?, ?, 1, ?)',
                    'isssssssii',
                    [$planID, $priceKey, $groupKey, $group['interval'], $operation['amount'], $operation['currency'], $processed['validFrom'], $processed['validUntil'], $isDefault, $group['sortOrder']]
                );
                $stmt->close();

                adminPlansAudit($admin, 'price.create', 'price_point', adminPricesEntityKey($plan, $priceKey), adminAuditChanges([], [
                    'amount'          => $operation['amount'],
                    'currency'        => $operation['currency'],
                    'billingInterval' => $group['interval'],
                    'validFrom'       => $processed['validFrom'],
                    'validUntil'      => $processed['validUntil'],
                    'isDefault'       => $isDefault,
                ], []));
                $changed++;
                continue;
            }

            // ✏️ An amount change on an UNBOUGHT price (the frozen test ran in
            //    adminPlansProcessPriceGroup() with the locked counts). The
            //    WHERE also names the plan: a price of another plan is never touched.
            $stmt = dbQuery(
                'UPDATE `tblPlanPricePoints` SET `amount` = ? WHERE `pricePointID` = ? AND `planID` = ? AND `currency` = ?',
                'siis',
                [$operation['amount'], (int) $operation['pricePointID'], $planID, $operation['currency']]
            );
            $updated = $stmt->affected_rows;
            $stmt->close();
            if ($updated !== 1) {
                throw new \RuntimeException('A price was not updated.');
            }
            $priceKey = '';
            foreach ($prices as $price) {
                if ((int) $price['pricePointID'] === (int) $operation['pricePointID']) {
                    $priceKey = (string) $price['priceKey'];
                }
            }
            adminPlansAudit($admin, 'price.update', 'price_point', adminPricesEntityKey($plan, $priceKey), [
                'amount' => ['old' => $operation['oldAmount'], 'new' => $operation['amount']],
            ]);
            $changed++;
        }

        // 🗓️ ⭐ The group's dates and "recommended" on its EXISTING current
        //    prices (allowed on bought prices too - P1 freezes only what was
        //    bought), and "recommended" taken off every other group.
        foreach ($prices as $price) {
            if ((int) $price['isActive'] !== 1) {
                continue;
            }
            $inGroup = ($price['priceGroupKey'] !== null && (string) $price['priceGroupKey'] === $groupKey);

            $wanted = [];
            if ($inGroup) {
                $wanted = ['validFrom' => $processed['validFrom'], 'validUntil' => $processed['validUntil'], 'isDefault' => $isDefault];
            } elseif ($isDefault === 1 && (int) $price['isDefault'] === 1) {
                $wanted = ['isDefault' => 0];
            }

            $before = [];
            foreach (array_keys($wanted) as $column) {
                $before[$column] = $price[$column];
            }
            $changes = adminAuditChanges($before, $wanted, []);
            if ($changes === []) {
                continue;
            }

            $sets = [];
            $types = '';
            $params = [];
            foreach (array_keys($changes) as $column) {
                $sets[] = '`' . $column . '` = ?';
                $types .= adminPlansBindType($wanted[$column]);
                $params[] = $wanted[$column];
            }
            $types .= 'ii';
            $params[] = (int) $price['pricePointID'];
            $params[] = $planID;

            $stmt = dbQuery('UPDATE `tblPlanPricePoints` SET ' . implode(', ', $sets) . ' WHERE `pricePointID` = ? AND `planID` = ?', $types, $params);
            $stmt->close();

            adminPlansAudit($admin, 'price.update', 'price_point', adminPricesEntityKey($plan, (string) $price['priceKey']), $changes);
            $changed++;
        }

        if ($changed === 0) {
            return adminPlansResult(true, 'Nothing was different, so nothing was saved.');
        }

        $mirror = adminMirrorLegacyPlanPrices($admin, $planID, $currencies['default']);

        return adminPlansResult(true, 'Saved: ' . $changed . ' price change(s), recorded in the admin change log.', ['planID' => $planID, 'changed' => true, 'warnings' => $mirror['warnings']]);
    });
}

/**
 * 🛑 Retire a price (price.retire): it stops being offered (and, if it had no
 *    end date or a later one, it ends now). Allowed on bought prices - that is
 *    how a bought price is replaced (P1). Needs the confirmation tick.
 *
 * @param array<string, mixed> $admin        From requireGlobalAdminPage()
 * @param int                  $planID       Plan ID (from the address)
 * @param int                  $pricePointID The price (must belong to that plan)
 * @param string               $fingerprint  The form's price-list fingerprint
 * @param bool                 $confirmed    The "I understand" tick
 * @return array See adminPlansResult()
 */
function adminPricesRetire(array $admin, int $planID, int $pricePointID, string $fingerprint, bool $confirmed): array
{
    return adminPlansInTransaction(function () use ($admin, $planID, $pricePointID, $fingerprint, $confirmed): array {
        // 🔒 The currencies FIRST, inside the transaction, with a shared lock
        //    (see adminPlansCurrencySettings()).
        $currencies = adminPlansCurrencySettings(true);
        if (!$currencies['ok']) {
            return adminPlansResult(false, 'The currency settings could not be read, so nothing was saved. Please try again.');
        }

        $locked = adminPricesLockAndCheck($planID, $fingerprint);
        if ($locked['result'] !== null) {
            return $locked['result'];
        }

        $price = adminPricesFindOwn($locked['prices'], $pricePointID);
        if ($price === null) {
            return adminPlansResult(false, 'That price does not belong to this plan, so nothing was changed.');
        }

        if ((int) $price['isActive'] !== 1) {
            return adminPlansResult(true, 'That price is already retired.');
        }

        if (!$confirmed) {
            return adminPlansResult(false, 'Tick the box beside the Retire button to confirm, then press it again. Nothing was changed.', ['errors' => ['confirm_retire_' . $pricePointID => 'Tick this box to confirm.']]);
        }

        $now = adminPlansNowUtc();
        $newUntil = $price['validUntil'];
        if ($newUntil === null || strcmp((string) $newUntil, $now) > 0) {
            $newUntil = $now;
        }

        $stmt = dbQuery(
            'UPDATE `tblPlanPricePoints` SET `isActive` = 0, `isDefault` = 0, `validUntil` = ? WHERE `pricePointID` = ? AND `planID` = ?',
            'sii',
            [$newUntil, $pricePointID, $planID]
        );
        $stmt->close();

        adminPlansAudit($admin, 'price.retire', 'price_point', adminPricesEntityKey($locked['plan'], (string) $price['priceKey']), adminAuditChanges(
            ['isActive' => $price['isActive'], 'isDefault' => $price['isDefault'], 'validUntil' => $price['validUntil']],
            ['isActive' => 0, 'isDefault' => 0, 'validUntil' => $newUntil],
            []
        ));

        $mirror = adminMirrorLegacyPlanPrices($admin, $planID, $currencies['default']);

        return adminPlansResult(true, 'The price was retired. Anyone who bought it keeps it.', ['planID' => $planID, 'changed' => true, 'warnings' => $mirror['warnings']]);
    });
}

/**
 * 🗑️ Delete a price (price.delete) - only one nobody has bought (P1). Needs
 *    the confirmation tick.
 *
 * @param array<string, mixed> $admin        From requireGlobalAdminPage()
 * @param int                  $planID       Plan ID (from the address)
 * @param int                  $pricePointID The price (must belong to that plan)
 * @param string               $fingerprint  The form's price-list fingerprint
 * @param bool                 $confirmed    The "I understand" tick
 * @return array See adminPlansResult()
 */
function adminPricesDelete(array $admin, int $planID, int $pricePointID, string $fingerprint, bool $confirmed): array
{
    return adminPlansInTransaction(function () use ($admin, $planID, $pricePointID, $fingerprint, $confirmed): array {
        // 🔒 The currencies FIRST, inside the transaction, with a shared lock
        //    (see adminPlansCurrencySettings()).
        $currencies = adminPlansCurrencySettings(true);
        if (!$currencies['ok']) {
            return adminPlansResult(false, 'The currency settings could not be read, so nothing was saved. Please try again.');
        }

        $locked = adminPricesLockAndCheck($planID, $fingerprint);
        if ($locked['result'] !== null) {
            return $locked['result'];
        }

        $price = adminPricesFindOwn($locked['prices'], $pricePointID);
        if ($price === null) {
            return adminPlansResult(false, 'That price does not belong to this plan, so nothing was changed.');
        }

        $references = 0;
        if (isset($locked['references'][$pricePointID])) {
            $references = (int) $locked['references'][$pricePointID];
        }
        if (adminPlansPriceIsFrozen($references)) {
            return adminPlansResult(false, 'This price has been bought (' . $references . ' record(s) use it), so it cannot be deleted - customers keep what they bought. You can retire it instead.');
        }

        if (!$confirmed) {
            return adminPlansResult(false, 'Tick the box beside the Delete button to confirm, then press it again. Nothing was deleted.', ['errors' => ['confirm_delete_' . $pricePointID => 'Tick this box to confirm.']]);
        }

        $stmt = dbQuery('DELETE FROM `tblPlanPricePoints` WHERE `pricePointID` = ? AND `planID` = ?', 'ii', [$pricePointID, $planID]);
        $deleted = $stmt->affected_rows;
        $stmt->close();
        if ($deleted !== 1) {
            throw new \RuntimeException('The price was not deleted.');
        }

        adminPlansAudit($admin, 'price.delete', 'price_point', adminPricesEntityKey($locked['plan'], (string) $price['priceKey']), adminAuditChanges(
            ['amount' => $price['amount'], 'currency' => $price['currency']],
            ['amount' => null, 'currency' => null],
            []
        ));

        $deletedGroup = '';
        if ($price['priceGroupKey'] !== null) {
            $deletedGroup = (string) $price['priceGroupKey'];
        }
        $mirror = adminMirrorLegacyPlanPrices($admin, $planID, $currencies['default'], [$deletedGroup]);

        return adminPlansResult(true, 'The price was deleted.', ['planID' => $planID, 'changed' => true, 'warnings' => $mirror['warnings']]);
    });
}

/**
 * 🔎 The price with this ID, ONLY if it is in this plan's (locked) list -
 *    the "belongs to the right parent" check (plan §10 S5).
 *
 * @param array<int, array<string, mixed>> $prices       The plan's locked prices
 * @param int                              $pricePointID The ID from the form
 * @return array<string, mixed>|null
 */
function adminPricesFindOwn(array $prices, int $pricePointID): ?array
{
    if ($pricePointID <= 0) {
        return null;
    }

    foreach ($prices as $price) {
        if ((int) $price['pricePointID'] === $pricePointID) {
            return $price;
        }
    }

    return null;
}

// ==============================================================================
// 🪞 DATABASE: the mirror (plan §4.7)
// ==============================================================================

/**
 * 🪞 Copy one plan's current monthly and yearly prices in the main currency
 *    into the older single-price columns the public page reads while charging
 *    is off (see adminMirrorDecision() for the exact rules). Writes a
 *    plan.update audit row when anything changes.
 *
 * ⚠️ Runs INSIDE the caller's transaction (it does not start or end one) and
 *    throws on a database problem, so the caller's whole save is undone.
 *
 * @param array<string, mixed> $admin         From requireGlobalAdminPage()
 * @param int                  $planID        Plan ID
 * @param string               $currency      The main currency (billing_default_currency)
 * @param array<int, string>   $emptiedGroups Groups ('monthly', 'yearly') whose price was just
 *                                            DELETED: if no price row of that group is left,
 *                                            its older column is cleared to 0.00 (review F6)
 * @return array{changed: bool, warnings: array<int, string>}
 * @throws \RuntimeException When the plan or its prices could not be read, or the audit row written
 */
function adminMirrorLegacyPlanPrices(array $admin, int $planID, string $currency, array $emptiedGroups = []): array
{
    if (!moneyCurrencyCanBeOffered($currency)) {
        throw new \RuntimeException('The main currency is not one prices can be set in.');
    }

    $loaded = adminPlansLoadPlan($planID, true);
    if (!$loaded['ok'] || $loaded['row'] === null) {
        throw new \RuntimeException('The plan could not be read for the mirror.');
    }
    $plan = $loaded['row'];

    $prices = adminPlansLoadPrices($planID, true);
    if ($prices === null) {
        throw new \RuntimeException('The prices could not be read for the mirror.');
    }

    $selection = adminMirrorSelection($prices, $currency, adminPlansNowUtc());
    $decision = adminMirrorDecision($plan, $selection, $currency);

    // 🗑️ Review finding F6: a group whose LAST price was just deleted has no
    //    price rows any more, so the public page falls back to its older
    //    column - which still held the deleted price. It is cleared to 0.00:
    //    a paid plan then says "Price coming soon" (decision 0021), and only a
    //    plan given without payment reads "Free".
    foreach (['monthly' => 'priceMonthly', 'yearly' => 'priceYearly'] as $group => $column) {
        if (!in_array($group, $emptiedGroups, true) || $selection['managed'][$group] === true) {
            continue;
        }
        $storedMinor = moneyStoredDecimalToMinor((string) $decision['values'][$column], (string) $decision['values']['currency']);
        if ($storedMinor === null || $storedMinor !== 0) {
            $decision['values'][$column] = '0.00';
            $decision['change'] = true;
        }
    }

    if (!$decision['change']) {
        return ['changed' => false, 'warnings' => $decision['warnings']];
    }

    $values = $decision['values'];

    // 💷 Bound as TEXT ('s'), never as a float.
    $stmt = dbQuery(
        'UPDATE `tblSubscriptionPlans` SET `priceMonthly` = ?, `priceYearly` = ?, `currency` = ? WHERE `planID` = ?',
        'sssi',
        [$values['priceMonthly'], $values['priceYearly'], $values['currency'], $planID]
    );
    $stmt->close();

    $changes = adminAuditChanges(
        ['priceMonthly' => $plan['priceMonthly'], 'priceYearly' => $plan['priceYearly'], 'currency' => $plan['currency']],
        $values,
        []
    );
    adminPlansAudit($admin, 'plan.update', 'plan', (string) $plan['planKey'], $changes, 'Pricing page prices copied from the price list (main currency ' . $currency . ')');

    return ['changed' => true, 'warnings' => $decision['warnings']];
}

/**
 * 🪞 Run the mirror for every plan (after the main currency changes).
 *    INSIDE the caller's transaction, like adminMirrorLegacyPlanPrices().
 *
 * @param array<string, mixed> $admin    From requireGlobalAdminPage()
 * @param string               $currency The main currency
 * @return array{changed: int, warnings: array<int, string>} warnings name the plan
 * @throws \RuntimeException On a database problem
 */
function adminMirrorAllLegacyPlanPrices(array $admin, string $currency): array
{
    $stmt = dbQuery('SELECT `planID`, `planName` FROM `tblSubscriptionPlans` ORDER BY `planID` FOR UPDATE');
    $plans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $outcome = ['changed' => 0, 'warnings' => []];

    foreach ($plans as $plan) {
        $mirror = adminMirrorLegacyPlanPrices($admin, (int) $plan['planID'], $currency);
        if ($mirror['changed']) {
            $outcome['changed']++;
        }
        foreach ($mirror['warnings'] as $warning) {
            $outcome['warnings'][] = (string) $plan['planName'] . ': ' . $warning;
        }
    }

    return $outcome;
}

// ==============================================================================
// 🧱 PURE: small HTML builders shared by the plan pages (everything escaped)
// ==============================================================================

/**
 * 🧱 One form box with its visible label, help text and any error, tied
 *    together for screen readers (aria-describedby, aria-invalid). PURE.
 *
 * @param array<string, mixed> $field Keys: id, name, label, kind ('text' | 'select' | 'textarea' |
 *                                    'date' | 'time' | 'number'), value, options (value => words, for
 *                                    'select'), help, error, required (bool), maxLength (int)
 * @return string HTML
 */
function adminPlansFieldHtml(array $field): string
{
    $defaults = ['id' => '', 'name' => '', 'label' => '', 'kind' => 'text', 'value' => '', 'options' => [], 'help' => '', 'error' => '', 'required' => false, 'maxLength' => 0];
    $field = array_merge($defaults, $field);

    $id = (string) $field['id'];
    $helpId = $id . '-help';
    $errorId = $id . '-error';

    $describedBy = [];
    if ($field['help'] !== '') {
        $describedBy[] = $helpId;
    }
    if ($field['error'] !== '') {
        $describedBy[] = $errorId;
    }

    $attributes = ' id="' . adminEscape($id) . '" name="' . adminEscape($field['name']) . '"';
    if ($describedBy !== []) {
        $attributes .= ' aria-describedby="' . adminEscape(implode(' ', $describedBy)) . '"';
    }
    $invalidClass = '';
    if ($field['error'] !== '') {
        $attributes .= ' aria-invalid="true"';
        $invalidClass = ' is-invalid';
    }
    $requiredWords = '';
    if ($field['required'] === true) {
        $attributes .= ' aria-required="true"';
        $requiredWords = ' <span class="text-body-secondary fw-normal">(required)</span>';
    }
    if ((int) $field['maxLength'] > 0) {
        $attributes .= ' maxlength="' . (int) $field['maxLength'] . '"';
    }

    $html = '<div class="mb-3">';
    $html .= '<label for="' . adminEscape($id) . '" class="form-label">' . adminEscape($field['label']) . $requiredWords . '</label>';

    $value = (string) $field['value'];

    if ($field['kind'] === 'select') {
        $html .= '<select class="form-select' . $invalidClass . '"' . $attributes . '>';
        foreach ($field['options'] as $optionValue => $optionWords) {
            $selected = '';
            if ((string) $optionValue === $value) {
                $selected = ' selected';
            }
            $html .= '<option value="' . adminEscape((string) $optionValue) . '"' . $selected . '>' . adminEscape($optionWords) . '</option>';
        }
        $html .= '</select>';
    } elseif ($field['kind'] === 'textarea') {
        $html .= '<textarea class="form-control' . $invalidClass . '" rows="3"' . $attributes . '>' . adminEscape($value) . '</textarea>';
    } else {
        $type = 'text';
        $extra = ' autocomplete="off"';
        if ($field['kind'] === 'date') {
            $type = 'date';
        } elseif ($field['kind'] === 'time') {
            $type = 'time';
        } elseif ($field['kind'] === 'number') {
            $extra .= ' inputmode="numeric"';
        } elseif ($field['kind'] === 'money') {
            $extra .= ' inputmode="decimal" spellcheck="false"';
        }
        $html .= '<input type="' . $type . '" class="form-control' . $invalidClass . '"' . $attributes . ' value="' . adminEscape($value) . '"' . $extra . '>';
    }

    if ($field['help'] !== '') {
        $html .= '<div class="form-text" id="' . adminEscape($helpId) . '">' . adminEscape($field['help']) . '</div>';
    }
    if ($field['error'] !== '') {
        $html .= '<div class="invalid-feedback d-block" id="' . adminEscape($errorId) . '"><i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>' . adminEscape($field['error']) . '</div>';
    }

    $html .= '</div>';

    return $html;
}

/**
 * ❌ The error summary at the top of a page after a refused save: it receives
 *    focus (cuercode-admin.js, data-admin-focus) and links to each box. PURE.
 *
 * @param array<string, string> $errors Box name => message
 * @param array<string, string> $labels Box name => the box's label (unknown names are listed by message only)
 * @param string                $idPrefix Prefix of the box ids (box id = prefix + name)
 * @return string HTML ('' when there are no errors)
 */
function adminPlansErrorSummaryHtml(array $errors, array $labels, string $idPrefix): string
{
    if ($errors === []) {
        return '';
    }

    $html = '<div class="alert alert-danger" role="alert" tabindex="-1" data-admin-focus="1">';
    $html .= '<p class="fw-bold mb-2"><i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>There is a problem. Nothing was saved.</p><ul class="mb-0">';
    foreach ($errors as $name => $message) {
        $label = '';
        if (isset($labels[$name])) {
            $label = $labels[$name];
        }
        if ($label === '') {
            $html .= '<li>' . adminEscape($message) . '</li>';
            continue;
        }
        $html .= '<li><a href="#' . adminEscape($idPrefix . $name) . '">' . adminEscape($label) . '</a>: ' . adminEscape($message) . '</li>';
    }
    $html .= '</ul></div>';

    return $html;
}

/**
 * 🔓 The "editing unlocked / locked / upgrade needed" card at the top of
 *    every plan page. PURE.
 *
 * @param bool   $databaseReady From adminDatabaseReadyForChanges()
 * @param int    $secondsLeft   From adminReauthSecondsLeftForAdmin()
 * @param int    $now           Unix time
 * @param string $timezone      Admin time zone
 * @param string $selfPath      This page's path (to come back to after confirming)
 * @return string HTML
 */
function adminPlansEditStateHtml(bool $databaseReady, int $secondsLeft, int $now, string $timezone, string $selfPath): string
{
    $html = '<div class="card shadow-sm mb-4"><div class="card-body">';

    if (!$databaseReady) {
        $html .= '<p class="mb-2">' . adminStatusHtml('warning', 'Database upgrade needed - plans and prices cannot be changed until the waiting upgrades are applied.') . '</p>';
        $html .= '<a href="/admin/upgrades/">See database upgrades</a>';
    } elseif ($secondsLeft > 0) {
        $html .= '<p class="mb-0">' . adminStatusHtml('good', 'Editing unlocked until ' . adminFormatClockTime($now + $secondsLeft, $timezone) . ' (' . adminTimezoneLabel($timezone) . ')') . '</p>';
    } else {
        $html .= '<p class="mb-2">' . adminStatusHtml('locked', 'Locked - you can look, but confirm your password to make changes.') . '</p>';
        $html .= '<a class="btn btn-primary" href="/admin/reauth/?next=' . rawurlencode(adminSafeNextPath($selfPath, '/admin/plans/')) . '">'
            . '<i class="fa-solid fa-unlock me-1" aria-hidden="true"></i> Unlock editing - confirm your password</a>';
    }

    $html .= '</div></div>';

    return $html;
}

/**
 * 🚦 A plan's sale status in words, with the kind of icon to show. PURE.
 *
 * @param array<string, mixed> $plan   Plan row
 * @param string               $nowUtc Now, UTC
 * @return array{kind: string, text: string} kind for adminStatusHtml()
 */
function adminPlansSaleStatus(array $plan, string $nowUtc): array
{
    if ((int) $plan['isActive'] !== 1) {
        return ['kind' => 'locked', 'text' => 'Off sale'];
    }

    if ($plan['availableFrom'] !== null && strcmp((string) $plan['availableFrom'], $nowUtc) > 0) {
        return ['kind' => 'warning', 'text' => 'On sale later (sale dates not started)'];
    }

    if ($plan['availableUntil'] !== null && strcmp((string) $plan['availableUntil'], $nowUtc) < 0) {
        return ['kind' => 'warning', 'text' => 'Sale dates have ended'];
    }

    return ['kind' => 'good', 'text' => 'On sale'];
}

/**
 * 🔢 The plan ID from the address (?id=), or 0 when it is missing or not a
 *    plain positive whole number. PURE.
 *
 * @param array<string, mixed> $query The $_GET array
 * @return int
 */
function adminPlansIdFromQuery(array $query): int
{
    if (!isset($query['id']) || !is_string($query['id'])) {
        return 0;
    }

    if (preg_match('/^[1-9][0-9]{0,9}$/D', $query['id']) !== 1) {
        return 0;
    }

    $id = (int) $query['id'];
    if ($id > 4294967295) {
        return 0;
    }

    return $id;
}

/**
 * 📋 The labels of the plan form's boxes (used by the boxes and by the
 *    error summary). PURE.
 *
 * @return array<string, string> box name => label
 */
function adminPlansFormLabels(): array
{
    return [
        'plan_key'             => 'Plan key',
        'plan_kind'            => 'Kind of plan',
        'copy_from'            => 'Copy features and limits from',
        'plan_name'            => 'Name',
        'plan_description'     => 'Description',
        'sort_order'           => 'Display order',
        'cta_mode'             => 'What the plan button does',
        'contact_url'          => 'Where "Contact us" goes',
        'available_from_date'  => 'On sale from (date)',
        'available_from_time'  => 'On sale from (time)',
        'available_until_date' => 'On sale until (date)',
        'available_until_time' => 'On sale until (time)',
        'max_sales'            => 'Most that may be sold',
        'promise_mode'         => 'Price promise for existing customers',
        'promise_months'       => 'Months the price is kept',
    ];
}

/**
 * 📋 The plan form's box values for a stored plan (dates in the admin time
 *    zone). PURE.
 *
 * @param array<string, mixed> $plan     Plan row
 * @param string               $timezone Admin time zone
 * @return array<string, string> box name => value
 */
function adminPlansFormValuesFromRow(array $plan, string $timezone): array
{
    $storedFrom = null;
    if ($plan['availableFrom'] !== null) {
        $storedFrom = (string) $plan['availableFrom'];
    }
    $storedUntil = null;
    if ($plan['availableUntil'] !== null) {
        $storedUntil = (string) $plan['availableUntil'];
    }
    $from = adminPlansUtcToInputs($storedFrom, $timezone, false);
    $until = adminPlansUtcToInputs($storedUntil, $timezone, true);

    $values = [
        'plan_key'             => (string) $plan['planKey'],
        'plan_kind'            => (string) $plan['planKind'],
        'plan_name'            => (string) $plan['planName'],
        'plan_description'     => (string) $plan['description'],
        'sort_order'           => (string) $plan['sortOrder'],
        'cta_mode'             => (string) $plan['ctaMode'],
        'contact_url'          => (string) $plan['contactUrl'],
        'available_from_date'  => $from['date'],
        'available_from_time'  => $from['time'],
        'available_until_date' => $until['date'],
        'available_until_time' => $until['time'],
        'max_sales'            => (string) $plan['maxSales'],
        'promise_mode'         => (string) $plan['pricePromiseMode'],
        'promise_months'       => (string) $plan['pricePromiseMonths'],
    ];

    return $values;
}

/**
 * 🧱 The boxes of the plan form (new/ and edit/ share it). PURE. The page
 *    owns the <form> tag, the CSRF field and the buttons.
 *
 * When editing, the key and kind are SHOWN, not editable (P6): they are
 * plain text, not boxes, so the form never sends them.
 *
 * @param array<string, string>  $values      Box name => value to show
 * @param array<string, string>  $errors      Box name => message
 * @param bool                   $creating    True on the new-plan page
 * @param array<int|string, string> $copyChoices planID => plan name (new page only)
 * @param string                 $timezone    Admin time zone
 * @return string HTML
 */
function adminPlansFormFieldsHtml(array $values, array $errors, bool $creating, array $copyChoices, string $timezone): string
{
    $labels = adminPlansFormLabels();
    $zoneWords = adminTimezoneLabel($timezone);

    $value = function (string $name) use ($values): string {
        if (isset($values[$name])) {
            return (string) $values[$name];
        }
        return '';
    };
    $error = function (string $name) use ($errors): string {
        if (isset($errors[$name])) {
            return (string) $errors[$name];
        }
        return '';
    };

    $html = '';

    // ---------------------------------------------------------------- identity
    $html .= '<fieldset class="mb-4"><legend class="h5">The plan</legend>';
    if ($creating) {
        $html .= adminPlansFieldHtml([
            'id' => 'f-plan_key', 'name' => 'plan_key', 'label' => $labels['plan_key'], 'value' => $value('plan_key'), 'required' => true, 'maxLength' => 32,
            'help' => 'The name the software uses for this plan. 2 to 32 characters: a lower-case letter first, then lower-case letters, digits or underscores. It can never be changed later.',
            'error' => $error('plan_key'),
        ]);
        $kindOptions = [];
        foreach (adminPlansKinds() as $kindKey => $kind) {
            $kindOptions[$kindKey] = $kind['label'];
        }
        $html .= adminPlansFieldHtml([
            'id' => 'f-plan_kind', 'name' => 'plan_kind', 'label' => $labels['plan_kind'], 'kind' => 'select', 'value' => $value('plan_kind'), 'options' => $kindOptions, 'required' => true,
            'help' => 'Ordinary plans are paid monthly or yearly. Lifetime deals are paid once. This cannot be changed later.',
            'error' => $error('plan_kind'),
        ]);
        $copyOptions = ['' => 'Nothing - start with no features or limits'];
        foreach ($copyChoices as $copyID => $copyName) {
            $copyOptions[(string) $copyID] = $copyName;
        }
        $html .= adminPlansFieldHtml([
            'id' => 'f-copy_from', 'name' => 'copy_from', 'label' => $labels['copy_from'], 'kind' => 'select', 'value' => $value('copy_from'), 'options' => $copyOptions,
            'help' => 'The new plan starts with a copy of that plan\'s features and limits (they can be changed on the features screen, which comes later). Its prices are not copied.',
            'error' => $error('copy_from'),
        ]);
    } else {
        $kindWords = $value('plan_kind');
        $kinds = adminPlansKinds();
        if (isset($kinds[$kindWords])) {
            $kindWords = $kinds[$kindWords]['label'];
        }
        $html .= '<dl class="row mb-3"><dt class="col-sm-4">' . adminEscape($labels['plan_key']) . '</dt><dd class="col-sm-8"><code>' . adminEscape($value('plan_key')) . '</code> '
            . '<span class="small text-body-secondary">(cannot be changed)</span></dd>'
            . '<dt class="col-sm-4">' . adminEscape($labels['plan_kind']) . '</dt><dd class="col-sm-8">' . adminEscape($kindWords) . ' '
            . '<span class="small text-body-secondary">(cannot be changed)</span></dd></dl>';
    }

    $html .= adminPlansFieldHtml([
        'id' => 'f-plan_name', 'name' => 'plan_name', 'label' => $labels['plan_name'], 'value' => $value('plan_name'), 'required' => true, 'maxLength' => 100,
        'help' => 'Shown to customers. Up to 100 characters, plain text.', 'error' => $error('plan_name'),
    ]);
    $html .= adminPlansFieldHtml([
        'id' => 'f-plan_description', 'name' => 'plan_description', 'label' => $labels['plan_description'], 'value' => $value('plan_description'), 'maxLength' => 500,
        'help' => 'A sentence shown under the name on the pricing page. Up to 500 characters, plain text, no line breaks. May be left empty.', 'error' => $error('plan_description'),
    ]);
    $html .= adminPlansFieldHtml([
        'id' => 'f-sort_order', 'name' => 'sort_order', 'label' => $labels['sort_order'], 'kind' => 'number', 'value' => $value('sort_order'), 'required' => true, 'maxLength' => 4,
        'help' => 'Smaller numbers are shown first. 0 to ' . CUERCODE_ADMIN_PLAN_SORT_MAX . '. A plan with 0 is never shown on the pricing page.', 'error' => $error('sort_order'),
    ]);
    $html .= '</fieldset>';

    // ------------------------------------------------------------------ button
    $html .= '<fieldset class="mb-4"><legend class="h5">The plan button</legend>';
    $html .= adminPlansFieldHtml([
        'id' => 'f-cta_mode', 'name' => 'cta_mode', 'label' => $labels['cta_mode'], 'kind' => 'select', 'value' => $value('cta_mode'), 'options' => adminPlansCtaModes(), 'required' => true,
        'help' => 'Choose "Contact us" for plans priced one customer at a time.', 'error' => $error('cta_mode'),
    ]);
    $html .= adminPlansFieldHtml([
        'id' => 'f-contact_url', 'name' => 'contact_url', 'label' => $labels['contact_url'], 'value' => $value('contact_url'), 'maxLength' => 255,
        'help' => 'Only used by "Contact us". An address starting with https://, or mailto: and an email address. Leave empty to use the site\'s own email address.',
        'error' => $error('contact_url'),
    ]);
    $html .= '</fieldset>';

    // ------------------------------------------------------------- sale rules
    $html .= '<fieldset class="mb-4"><legend class="h5">When it is on sale</legend>';
    $html .= '<p class="small text-body-secondary">Dates and times are ' . adminEscape($zoneWords) . '. A date with no time means the start of that day for "from" and the end of that day (23:59:59) for "until". Leave both empty for no limit.</p>';
    $html .= '<div class="row"><div class="col-sm-7">';
    $html .= adminPlansFieldHtml(['id' => 'f-available_from_date', 'name' => 'available_from_date', 'label' => $labels['available_from_date'], 'kind' => 'date', 'value' => $value('available_from_date'), 'error' => $error('available_from_date')]);
    $html .= '</div><div class="col-sm-5">';
    $html .= adminPlansFieldHtml(['id' => 'f-available_from_time', 'name' => 'available_from_time', 'label' => $labels['available_from_time'], 'kind' => 'time', 'value' => $value('available_from_time'), 'help' => 'Optional.']);
    $html .= '</div></div><div class="row"><div class="col-sm-7">';
    $html .= adminPlansFieldHtml(['id' => 'f-available_until_date', 'name' => 'available_until_date', 'label' => $labels['available_until_date'], 'kind' => 'date', 'value' => $value('available_until_date'), 'error' => $error('available_until_date')]);
    $html .= '</div><div class="col-sm-5">';
    $html .= adminPlansFieldHtml(['id' => 'f-available_until_time', 'name' => 'available_until_time', 'label' => $labels['available_until_time'], 'kind' => 'time', 'value' => $value('available_until_time'), 'help' => 'Optional.']);
    $html .= '</div></div>';
    $html .= adminPlansFieldHtml([
        'id' => 'f-max_sales', 'name' => 'max_sales', 'label' => $labels['max_sales'], 'kind' => 'number', 'value' => $value('max_sales'), 'maxLength' => 7,
        'help' => 'For limited offers such as lifetime deals: 1 to ' . CUERCODE_ADMIN_PLAN_MAX_SALES . '. Leave empty for no cap. It can never be set below the number already sold.',
        'error' => $error('max_sales'),
    ]);
    $html .= '</fieldset>';

    // ---------------------------------------------------------- price promise
    $html .= '<fieldset class="mb-4"><legend class="h5">Price promise</legend>';
    $html .= adminPlansFieldHtml([
        'id' => 'f-promise_mode', 'name' => 'promise_mode', 'label' => $labels['promise_mode'], 'kind' => 'select', 'value' => $value('promise_mode'), 'options' => adminPlansPromiseModes(),
        'help' => 'How long people who already pay keep the price they signed up at if the price goes up. The site-wide setting is on the Settings screen.',
        'error' => $error('promise_mode'),
    ]);
    $html .= adminPlansFieldHtml([
        'id' => 'f-promise_months', 'name' => 'promise_months', 'label' => $labels['promise_months'], 'kind' => 'number', 'value' => $value('promise_months'), 'maxLength' => 3,
        'help' => 'Only for "for a set number of months": 1 to ' . CUERCODE_ADMIN_PLAN_PROMISE_MONTHS_MAX . '. Otherwise leave it empty.',
        'error' => $error('promise_months'),
    ]);
    $html .= '</fieldset>';

    return $html;
}
