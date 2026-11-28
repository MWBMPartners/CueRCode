<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Dynamic QR create, change and switch-off rules (WP4c, #166)
 * ==============================================================================
 *
 * Path: tests/test_dynamic_qr_flows.php
 *
 * The parts of the dynamic-code flows that need no database, plus source
 * checks that the protections are wired where they must be. The database
 * flows themselves (the lock, the counting, the stand-in Go2My.Link, the
 * download gate, the admin screens) are proved by
 * tests/integration/dynamic_qr_real_mysql.php.
 *
 *   1. Which rows may ever be drawn as a QR picture ("active" only).
 *   2. Which rows are suspended (owner changes refused).
 *   3. Which accounts may make or change codes (active, confirmed email).
 *   4. Destinations on a short-link address are refused (a code must never
 *      send people on to another short link).
 *   5. The switched-off page address (lead choice L2).
 *   6. The one link-safety hook (#168 fills it in later).
 *   7. The change planner: what a PATCH means, what is sent to Go2My.Link.
 *   8. What the tidy step does with a look-up answer.
 *   9. Go2My.Link failures as plain member answers.
 *  10. The payload builder refuses unless the create flow allowed it.
 *  11. Source checks: the order of the create steps, the router passing the
 *      sign-in details, the download and save gates, the admin switch.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (WP4c, #166)
 * @see         web/CueRCode.net/_functions/fn_redirects.php
 * @see         web/CueRCode.net/_functions/fn_api_redirects.php
 */

requireFn('fn_redirects.php');
requireFn('fn_go2mylink.php');

// ==============================================================================
// 🧰 A row as tblQRRedirects holds it, with one thing changed per case
// ==============================================================================
$dqRow = static function (array $dqChanges = []): array {
    return array_merge([
        'redirectID'             => 7,
        'redirectUUID'           => 'a1b2c3d4-e5f6-4890-abcd-ef1234567890',
        'shortCode'              => 'Ab3dE9x',
        'syncState'              => 'active',
        'lastSyncError'          => null,
        'ownerUserID'            => 11,
        'ownerOrgID'             => null,
        'destinationURL'         => 'https://example.com/menu',
        'previousDestinationURL' => null,
        'isActive'               => 1,
        'disabledReason'         => null,
        'suspendedAt'            => null,
        'suspendedByUserID'      => null,
    ], $dqChanges);
};

// ==============================================================================
// ✅ Section 1: only "active" may ever be drawn
// ==============================================================================
assertTrue(function_exists('dynamicQrRowIsPrintable'), 'dynamicQrRowIsPrintable() exists');
if (function_exists('dynamicQrRowIsPrintable')) {
    assertTrue(dynamicQrRowIsPrintable($dqRow()), 'an active, switched-on, unsuspended row with a code may be drawn');
    foreach (['pending', 'unconfirmed', 'failed', 'unlinked', '', 'ACTIVE'] as $dqState) {
        assertFalse(dynamicQrRowIsPrintable($dqRow(['syncState' => $dqState])), 'a row in state "' . $dqState . '" is never drawn');
    }
    assertFalse(dynamicQrRowIsPrintable($dqRow(['isActive' => 0, 'disabledReason' => 'owner'])), 'a code its owner switched off is not drawn');
    assertFalse(dynamicQrRowIsPrintable($dqRow(['isActive' => 0])), 'a switched-off code is not drawn even when no reason was stored (each check stands on its own)');
    assertFalse(dynamicQrRowIsPrintable($dqRow(['disabledReason' => 'owner'])), 'a stored switched-off reason alone is enough to refuse');
    assertFalse(dynamicQrRowIsPrintable($dqRow(['disabledReason' => 'abuse'])), 'a code suspended for abuse is not drawn');
    assertFalse(dynamicQrRowIsPrintable($dqRow(['disabledReason' => 'admin'])), 'a code an admin switched off is not drawn');
    assertFalse(dynamicQrRowIsPrintable($dqRow(['suspendedAt' => '2026-11-28 10:00:00'])), 'a code with a suspension time is not drawn');
    assertFalse(dynamicQrRowIsPrintable($dqRow(['shortCode' => null])), 'a row with no short code is not drawn');
    assertFalse(dynamicQrRowIsPrintable($dqRow(['shortCode' => 'install'])), 'a row whose code Go2My.Link reserves is not drawn');
    assertTrue(dynamicQrRowIsPrintable($dqRow(['isActive' => '1'])), 'a string "1" from the database counts as switched on');
    assertFalse(dynamicQrRowIsPrintable([]), 'an empty row is not drawn');
}

// ==============================================================================
// ✅ Section 2: suspended
// ==============================================================================
assertTrue(function_exists('dynamicQrRowIsSuspended'), 'dynamicQrRowIsSuspended() exists');
if (function_exists('dynamicQrRowIsSuspended')) {
    assertFalse(dynamicQrRowIsSuspended($dqRow()), 'a normal code is not suspended');
    assertFalse(dynamicQrRowIsSuspended($dqRow(['isActive' => 0, 'disabledReason' => 'owner'])), 'a code its owner switched off is not suspended');
    assertTrue(dynamicQrRowIsSuspended($dqRow(['isActive' => 0, 'disabledReason' => 'abuse'])), 'abuse means suspended');
    assertTrue(dynamicQrRowIsSuspended($dqRow(['isActive' => 0, 'disabledReason' => 'admin'])), 'switched off by an admin means suspended');
    assertTrue(dynamicQrRowIsSuspended($dqRow(['suspendedAt' => '2026-11-28 10:00:00'])), 'a suspension time means suspended, whatever the other columns say');
}

// ==============================================================================
// ✅ Section 3: who may make or change codes
// ==============================================================================
assertTrue(function_exists('dynamicQrAccountMayChangeCodes'), 'dynamicQrAccountMayChangeCodes() exists');
if (function_exists('dynamicQrAccountMayChangeCodes')) {
    assertTrue(dynamicQrAccountMayChangeCodes(['status' => 'active', 'emailVerifiedAt' => '2026-11-01 09:00:00']), 'an active account with a confirmed email may');
    assertFalse(dynamicQrAccountMayChangeCodes(['status' => 'active', 'emailVerifiedAt' => null]), 'an unconfirmed email may not');
    assertFalse(dynamicQrAccountMayChangeCodes(['status' => 'active', 'emailVerifiedAt' => '']), 'an empty confirmation time may not');
    foreach (['pending', 'suspended', 'deleted', ''] as $dqStatus) {
        assertFalse(dynamicQrAccountMayChangeCodes(['status' => $dqStatus, 'emailVerifiedAt' => '2026-11-01 09:00:00']), 'an account that is "' . $dqStatus . '" may not');
    }
    assertFalse(dynamicQrAccountMayChangeCodes(null), 'no account (not signed in) may not');
}

// ==============================================================================
// ✅ Section 4: no destination on a short-link address
// ==============================================================================
foreach ([
    'https://qrmy.link/Ab3dE9x', 'https://QRmy.Link/other', 'https://alpha.qrmy.link/x', 'https://go2my.link/abc',
    'https://g2my.link/abc', 'https://www.g2my.link/abc', 'https://lnks.page/abc', 'https://qrmy.link',
] as $dqShortLink) {
    $dqCheck = validateRedirectDestination($dqShortLink);
    assertFalse($dqCheck['valid'], 'a destination on a short-link address is refused: ' . $dqShortLink);
    if (!$dqCheck['valid']) {
        assertContains('short link', (string) $dqCheck['error'], '...with a plain reason: ' . $dqShortLink);
    }
}
foreach (['https://example.com/menu', 'https://notqrmy.link/x', 'https://qrmy.link.example.com/x', 'https://cuercode.net/pricing/'] as $dqFine) {
    assertTrue(validateRedirectDestination($dqFine)['valid'], 'an ordinary destination is still accepted: ' . $dqFine);
}
assertTrue(validateRedirectDestination('https://short.example/x', ['short_link_hosts' => []])['valid'], 'the short-link list can be replaced by a caller (tests)');
assertFalse(validateRedirectDestination('https://short.example/x', ['short_link_hosts' => ['short.example']])['valid'], '...and a host on the given list is refused');

// ==============================================================================
// ✅ Section 5: the switched-off page (L2)
// ==============================================================================
assertTrue(function_exists('dynamicQrUnavailablePageUrl'), 'dynamicQrUnavailablePageUrl() exists');
if (function_exists('dynamicQrUnavailablePageUrl')) {
    assertEquals('https://cuercode.net/unavailable/?code=Ab3dE9x', dynamicQrUnavailablePageUrl('https://cuercode.net', 'Ab3dE9x'), 'the switched-off page is on the site, with the code');
    assertEquals('https://cuercode.net/unavailable/?code=Ab3dE9x', dynamicQrUnavailablePageUrl('https://cuercode.net/', 'Ab3dE9x'), 'a trailing slash on the site address is not doubled');
    assertEquals('', dynamicQrUnavailablePageUrl('', 'Ab3dE9x'), 'no site address: no page (switching off is then refused)');
    assertEquals('', dynamicQrUnavailablePageUrl('http://cuercode.net', 'Ab3dE9x'), 'only an https site address is used');
    assertEquals('', dynamicQrUnavailablePageUrl('https://cuercode.net', 'bad code!'), 'a code outside Go2My.Link\'s pattern gives no page');
}

// ==============================================================================
// ✅ Section 6: the one link-safety hook (#168)
// ==============================================================================
assertTrue(function_exists('dynamicQrCheckDestinationSafety'), 'dynamicQrCheckDestinationSafety() exists (the single hook #168 fills in)');
if (function_exists('dynamicQrCheckDestinationSafety')) {
    $dqSafe = dynamicQrCheckDestinationSafety('https://example.com/menu', 'create');
    assertEquals(['allowed' => true, 'reason' => 'not_checked_yet', 'message' => ''], $dqSafe, 'until #168 the hook lets every destination through and says it was not checked');
}

// ==============================================================================
// ✅ Section 7: the change planner
// ==============================================================================
$dqContext = ['allowHttp' => false, 'offPageUrl' => 'https://cuercode.net/unavailable/?code=Ab3dE9x'];
assertTrue(function_exists('dynamicQrPlanChange'), 'dynamicQrPlanChange() exists');
if (function_exists('dynamicQrPlanChange')) {
    $dqPlan = dynamicQrPlanChange($dqRow(), ['destination_url' => 'https://example.com/new'], $dqContext);
    assertTrue($dqPlan['ok'] && $dqPlan['remoteTarget'] === 'https://example.com/new' && $dqPlan['destinationChanged'] && !$dqPlan['activeChanged'], 'a new destination on a switched-on code is sent to Go2My.Link');
    assertEquals('https://example.com/new', $dqPlan['checkDestination'], '...and goes through the link-safety hook');

    $dqPlan = dynamicQrPlanChange($dqRow(), ['active' => false], $dqContext);
    assertTrue($dqPlan['ok'] && $dqPlan['remoteTarget'] === $dqContext['offPageUrl'] && $dqPlan['newActive'] === false, 'switching off points the link at the switched-off page (L2)');
    assertEquals('', $dqPlan['checkDestination'], '...which needs no link-safety check');

    $dqOff = $dqRow(['isActive' => 0, 'disabledReason' => 'owner']);
    $dqPlan = dynamicQrPlanChange($dqOff, ['active' => true], $dqContext);
    assertTrue($dqPlan['ok'] && $dqPlan['remoteTarget'] === 'https://example.com/menu' && $dqPlan['newActive'] === true, 'switching on points the link back at the saved destination');
    assertEquals('https://example.com/menu', $dqPlan['checkDestination'], '...which is checked again before it goes live');

    $dqPlan = dynamicQrPlanChange($dqOff, ['destination_url' => 'https://example.com/later'], $dqContext);
    assertTrue($dqPlan['ok'] && $dqPlan['remoteTarget'] === null && $dqPlan['destinationChanged'], 'a new destination while switched off is saved here only; the link keeps showing the switched-off page');
    assertEquals('https://example.com/later', $dqPlan['checkDestination'], '...and is still checked (every destination change goes through the hook)');

    $dqPlan = dynamicQrPlanChange($dqRow(), ['destination_url' => 'https://example.com/menu', 'active' => true], $dqContext);
    assertTrue($dqPlan['ok'] && $dqPlan['nothing'] === true && $dqPlan['remoteTarget'] === null, 'sending what is already there changes nothing and calls nobody');

    $dqPlan = dynamicQrPlanChange($dqRow(['disabledReason' => 'abuse', 'isActive' => 0]), ['destination_url' => 'https://example.com/new'], $dqContext);
    assertTrue(!$dqPlan['ok'] && $dqPlan['httpCode'] === 409 && $dqPlan['errorCode'] === 'REDIRECT_SUSPENDED', 'a suspended code refuses every owner change (409)');
    $dqPlan = dynamicQrPlanChange($dqRow(['suspendedAt' => '2026-11-28 10:00:00']), ['active' => true], $dqContext);
    assertTrue(!$dqPlan['ok'] && $dqPlan['errorCode'] === 'REDIRECT_SUSPENDED', '...including switching it back on');

    foreach (['pending', 'unconfirmed', 'failed', 'unlinked'] as $dqState) {
        $dqPlan = dynamicQrPlanChange($dqRow(['syncState' => $dqState]), ['destination_url' => 'https://example.com/new'], $dqContext);
        assertTrue(!$dqPlan['ok'] && $dqPlan['httpCode'] === 409 && $dqPlan['errorCode'] === 'REDIRECT_NOT_READY', 'a code in state "' . $dqState . '" cannot be changed (409 REDIRECT_NOT_READY)');
    }

    $dqPlan = dynamicQrPlanChange($dqRow(), [], $dqContext);
    assertTrue(!$dqPlan['ok'] && $dqPlan['httpCode'] === 400 && $dqPlan['errorCode'] === 'NO_FIELDS', 'nothing to change: 400 NO_FIELDS');
    $dqPlan = dynamicQrPlanChange($dqRow(), ['destination_url' => 42], $dqContext);
    assertTrue(!$dqPlan['ok'] && $dqPlan['errorCode'] === 'INVALID_DESTINATION', 'a destination that is not text: 400 INVALID_DESTINATION');
    $dqPlan = dynamicQrPlanChange($dqRow(), ['destination_url' => 'https://10.0.0.1/admin'], $dqContext);
    assertTrue(!$dqPlan['ok'] && $dqPlan['errorCode'] === 'INVALID_DESTINATION', 'a private address: 400 INVALID_DESTINATION');
    $dqPlan = dynamicQrPlanChange($dqRow(), ['destination_url' => 'https://qrmy.link/Zz9yY8x'], $dqContext);
    assertTrue(!$dqPlan['ok'] && $dqPlan['errorCode'] === 'INVALID_DESTINATION', 'another short link: 400 INVALID_DESTINATION');
    foreach (['false', 0, 1, 'yes', null] as $dqNotBool) {
        $dqPlan = dynamicQrPlanChange($dqRow(), ['active' => $dqNotBool], $dqContext);
        assertTrue(!$dqPlan['ok'] && $dqPlan['httpCode'] === 400 && $dqPlan['errorCode'] === 'INVALID_ACTIVE', '"active" must be true or false, not ' . var_export($dqNotBool, true));
    }
    $dqPlan = dynamicQrPlanChange($dqRow(), ['active' => false], ['allowHttp' => false, 'offPageUrl' => '']);
    assertTrue(!$dqPlan['ok'] && $dqPlan['httpCode'] === 503 && $dqPlan['errorCode'] === 'DYNAMIC_QR_UNAVAILABLE', 'switching off without a site address to point at is refused, nothing is sent');
    $dqPlan = dynamicQrPlanChange($dqRow(), ['destination_url' => 'http://example.com/'], $dqContext);
    assertFalse($dqPlan['ok'], 'http is refused unless the setting allows it');
    $dqPlan = dynamicQrPlanChange($dqRow(), ['destination_url' => 'http://example.com/'], ['allowHttp' => true, 'offPageUrl' => $dqContext['offPageUrl']]);
    assertTrue($dqPlan['ok'], '...and accepted when it does');
}

// ==============================================================================
// ✅ Section 8: the tidy step's decision
// ==============================================================================
assertTrue(function_exists('dynamicQrTidyActionFor'), 'dynamicQrTidyActionFor() exists');
if (function_exists('dynamicQrTidyActionFor')) {
    assertEquals('recover', dynamicQrTidyActionFor(go2mylinkSuccess(['shortCode' => 'Ab3dE9x'], 200)), 'Go2My.Link has the link: settle it with the safe create (never a blind retry)');
    assertEquals('failed', dynamicQrTidyActionFor(go2mylinkFailure('not_found', false)), 'Go2My.Link has no such link: nothing was made, so it stops counting');
    foreach (['timeout', 'unreachable', 'unavailable', 'rate_limited', 'auth_failed', 'bad_reply', 'not_configured'] as $dqReason) {
        assertEquals('wait', dynamicQrTidyActionFor(go2mylinkFailure($dqReason, false)), 'a look-up that could not answer (' . $dqReason . ') leaves it waiting, still counted');
    }
}

// ==============================================================================
// ✅ Section 9: Go2My.Link failures as member answers
// ==============================================================================
assertTrue(function_exists('dynamicQrServiceErrorFacts'), 'dynamicQrServiceErrorFacts() exists');
if (function_exists('dynamicQrServiceErrorFacts')) {
    $dqFacts = dynamicQrServiceErrorFacts(go2mylinkFailure('rate_limited', false, ['retryAfter' => 60]));
    assertTrue($dqFacts['httpCode'] === 429 && $dqFacts['errorCode'] === 'LINK_SERVICE_BUSY' && $dqFacts['retryAfter'] === 60, 'Go2My.Link busy: 429 LINK_SERVICE_BUSY with its wait');
    $dqFacts = dynamicQrServiceErrorFacts(go2mylinkFailure('rejected', false));
    assertTrue($dqFacts['httpCode'] === 422 && $dqFacts['errorCode'] === 'LINK_SERVICE_REJECTED', 'Go2My.Link refused the destination: 422 LINK_SERVICE_REJECTED');
    $dqFacts = dynamicQrServiceErrorFacts(go2mylinkFailure('timeout', true));
    assertTrue($dqFacts['httpCode'] === 503 && $dqFacts['errorCode'] === 'LINK_NOT_CONFIRMED', 'an answer that was lost: 503 LINK_NOT_CONFIRMED');
    foreach (['unreachable', 'unavailable', 'auth_failed', 'integration_off', 'tls_failed', 'not_found', 'already_linked', 'bad_reply', 'code_taken'] as $dqReason) {
        $dqFacts = dynamicQrServiceErrorFacts(go2mylinkFailure($dqReason, false));
        assertTrue($dqFacts['httpCode'] === 503 && $dqFacts['errorCode'] === 'LINK_SERVICE_UNAVAILABLE', $dqReason . ': 503 LINK_SERVICE_UNAVAILABLE ("try again later")');
        assertFalse(str_contains($dqFacts['message'], 'Go2My.Link status'), $dqReason . ': the member never sees the admin wording');
    }
    $dqFacts = dynamicQrServiceErrorFacts(go2mylinkFailure('domain_unconfirmed', false, ['remoteExists' => true]));
    assertTrue($dqFacts['httpCode'] === 503 && $dqFacts['errorCode'] === 'LINK_NOT_CONFIRMED', 'a link that exists but cannot be confirmed: 503 LINK_NOT_CONFIRMED (held for an admin)');
}

// ==============================================================================
// ✅ Section 10: the payload builder only draws what the create flow allowed
// ==============================================================================
requireFn('fn_qr_payloads.php');
assertTrue(function_exists('dynamicQrPayloadPermit'), 'dynamicQrPayloadPermit() exists');
$dqUuid = 'a1b2c3d4-e5f6-4890-abcd-ef1234567890';
assertThrows(
    static function () use ($dqUuid): void {
        buildPayload_dynamic_url(['redirect_uuid' => $dqUuid]);
    },
    'typed-in details can never draw a dynamic code (generate, preview, bulk, the no-JavaScript form)',
    \InvalidArgumentException::class
);
assertThrows(
    static function (): void {
        buildQRPayload('dynamic_url', ['redirect_uuid' => 'a1b2c3d4-e5f6-4890-abcd-ef1234567890']);
    },
    '...the same through buildQRPayload(), which every route uses',
    \InvalidArgumentException::class
);
if (function_exists('dynamicQrPayloadPermit')) {
    assertFalse(dynamicQrPayloadPermit('check', $dqUuid), 'no permission is held at the start');
    assertTrue(dynamicQrPayloadPermit('grant', $dqUuid), 'the create flow can grant one for its own code');
    assertTrue(dynamicQrPayloadPermit('check', $dqUuid), '...which is then held');
    assertFalse(dynamicQrPayloadPermit('check', 'b2c3d4e5-f6a7-4901-bcde-f12345678901'), '...for that code only');
    assertThrows(
        static function () use ($dqUuid): void {
            buildPayload_dynamic_url(['redirect_uuid' => $dqUuid]);
        },
        'with permission but no database, the code is still not drawn (its state cannot be read)',
        \InvalidArgumentException::class
    );
    dynamicQrPayloadPermit('revoke', $dqUuid);
    assertFalse(dynamicQrPayloadPermit('check', $dqUuid), 'the permission is taken back afterwards');
    assertFalse(dynamicQrPayloadPermit('grant', 'not-a-uuid'), 'nothing is granted for something that is not a code id');
}

// ==============================================================================
// ✅ Section 11: source checks - where the protections are wired
// ==============================================================================
$dqApi = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_api_redirects.php');
$dqCode = (string) preg_replace('#//[^\n]*#', '', (string) preg_replace('#/\*.*?\*/#s', '', $dqApi));

// 📌 One function's body: from its signature to the next "function ".
$dqBody = static function (string $dqSource, string $dqName): string {
    $dqStart = strpos($dqSource, 'function ' . $dqName . '(');
    if ($dqStart === false) {
        return '';
    }
    $dqNext = strpos($dqSource, "\nfunction ", $dqStart + 10);
    if ($dqNext === false) {
        $dqNext = strlen($dqSource);
    }
    return substr($dqSource, $dqStart, $dqNext - $dqStart);
};
$dqInOrder = static function (string $dqText, array $dqNeedles, string $dqWhere): void {
    $dqLast = -1;
    foreach ($dqNeedles as $dqStep => $dqNeedle) {
        $dqPos = strpos($dqText, $dqNeedle);
        assertTrue($dqPos !== false && $dqPos > $dqLast, $dqWhere . ', in order: ' . $dqStep);
        if ($dqPos !== false) {
            $dqLast = $dqPos;
        }
    }
};

$dqCreate = $dqBody($dqCode, 'createDynamicRedirectForOwner');
assertTrue($dqCreate !== '', 'the create flow is in fn_api_redirects.php');
$dqInOrder($dqCreate, [
    'account check'           => 'dynamicQrAccountMayChangeCodes(',
    'master switch'           => 'dynamicQrMasterSwitchOn(',
    'destination check'       => 'validateRedirectDestination(',
    'safety hook'             => 'dynamicQrCheckDestinationSafety(',
    'lock'                    => 'acquireDynamicCodeLimitLock(',
    'feature, limit and row'  => 'dynamicQrReserveRow(',
    'release (in finally)'    => 'releaseDynamicCodeLimitLock(',
    'Go2My.Link'              => 'go2mylinkCreateLinkSafely(',
    'state saved'             => 'go2mylinkSyncStateFor(',
], 'create');
assertTrue(preg_match('/finally\s*\{\s*releaseDynamicCodeLimitLock\(/', $dqCreate) === 1, 'create: the lock is let go in a finally block');
assertFalse(str_contains($dqCreate, 'errorResponse(') || str_contains($dqCreate, 'successResponse('), 'the create flow never calls a function that exits (its lock is always let go)');

$dqReserve = $dqBody($dqCode, 'dynamicQrReserveRow');
$dqInOrder($dqReserve, [
    'feature'     => "featureEnabled('dynamic_qr'",
    'limit'       => "limitFor('dynamic_codes'",
    'count'       => 'dynamicQrCountHeldCodes(',
    'decision'    => 'evaluateDynamicCodeQuota(',
    'pending row' => "VALUES (?, ?, 'pending'",
], 'reserve (under the lock)');
assertFalse(str_contains($dqReserve, 'go2mylink') && !str_contains($dqReserve, 'go2mylinkGenerateShortCode('), 'reserve: Go2My.Link is never called while the lock is held');
assertEquals(1, substr_count($dqCode, 'dynamicQrReserveRow(') - substr_count($dqCode, 'function dynamicQrReserveRow('), 'the reserve step has one caller: the create flow, inside the lock');
assertContains("`syncState` <> 'failed'", $dqBody($dqCode, 'dynamicQrCountHeldCodes'), 'count: every code counts except one Go2My.Link made nothing for');

$dqChange = $dqBody($dqCode, 'dynamicQrChangeForOwner') . $dqBody($dqCode, 'dynamicQrApplyChange');
assertTrue($dqChange !== '', 'the change flow is in fn_api_redirects.php');
if ($dqChange !== '') {
    $dqOwner  = strpos($dqChange, 'redirectIsWritableBy(');
    $dqPlanAt = strpos($dqChange, 'dynamicQrPlanChange(');
    $dqHook   = strpos($dqChange, 'dynamicQrCheckDestinationSafety(');
    $dqRemote = strpos($dqChange, 'go2mylinkUpdateDestination(');
    $dqLocal  = strpos($dqChange, 'UPDATE `tblQRRedirects` SET');
    assertTrue($dqOwner !== false && $dqPlanAt !== false && $dqOwner < $dqPlanAt, 'change: ownership is checked before anything in the request is read');
    assertTrue($dqHook !== false && $dqRemote !== false && $dqHook < $dqRemote, 'change: the link-safety hook runs before Go2My.Link is called');
    assertTrue($dqRemote !== false && $dqLocal !== false && $dqRemote < $dqLocal, 'change: Go2My.Link is changed FIRST, then the row here');
    assertTrue(str_contains($dqChange, 'acquireDynamicCodeLimitLock(') && str_contains($dqChange, 'releaseDynamicCodeLimitLock('), 'change: runs under the same per-person lock (two changes at once cannot cross)');
    assertFalse(str_contains($dqChange, 'errorResponse(') || str_contains($dqChange, 'successResponse('), 'the change flow never calls a function that exits');
}
assertEquals(2, substr_count($dqCode, 'dynamicQrCheckDestinationSafety('), 'the link-safety hook is called in exactly two places: create and change');
assertTrue(substr_count($dqCode, "'httpCode' => 404, 'errorCode' => 'NOT_FOUND'") >= 3, 'other people\'s codes answer 404 NOT_FOUND (change flow, its re-read under the lock, and the image gate)');

$dqGenerate = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_api_generate.php');
assertContains('function handleGenerate(array $requestData, array $auth = []): void', $dqGenerate, 'handleGenerate() receives the sign-in details');
assertContains('createDynamicRedirectForOwner(', $dqGenerate, 'handleGenerate() makes dynamic codes through the one create flow');
$dqRouter = (string) file_get_contents(CUERCODE_SITE_ROOT . 'public_html' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'v1' . DIRECTORY_SEPARATOR . 'index.php');
assertContains('handleGenerate($requestBody, $auth);', $dqRouter, 'the router passes the sign-in details to handleGenerate()');

$dqDownload = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_api_download.php');
$dqGatePos  = strpos($dqDownload, 'dynamicQrImageGate(');
$dqFilePos  = strpos($dqDownload, 'realpath($filePath)');
assertTrue($dqGatePos !== false && $dqFilePos !== false && $dqGatePos < $dqFilePos, 'download: a dynamic code\'s picture passes the gate before any file is opened');

$dqSave = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_api_qrcodes.php');
assertContains('DYNAMIC_CODE_NOT_SAVEABLE', $dqSave, 'saved codes: a dynamic code\'s picture cannot be claimed into saved codes (which would bypass the gate)');

$dqCron = (string) file_get_contents(CUERCODE_INCLUDES_DIR . 'cron' . DIRECTORY_SEPARATOR . 'process_email_queue.php');
assertContains('dynamicQrTidyPendingRows(', $dqCron, 'the existing cron job runs the tidy step for waiting rows');

// ==============================================================================
// ✅ Section 12: a typed destination made ready for the check (#181 Low 3)
// ==============================================================================
assertEquals('https://example.com/caf%C3%A9%20menu', dynamicQrPrepareDestination('https://example.com/café menu'), 'a pasted address with a space and an accent is put in Go2My.Link\'s stored form');
assertTrue(validateRedirectDestination(dynamicQrPrepareDestination('https://example.com/café menu'))['valid'], '...which the unchanged check then accepts');
assertEquals("https://example.com/\r\nx", dynamicQrPrepareDestination("https://example.com/\r\nx"), 'text with a control character is passed on unchanged...');
assertFalse(validateRedirectDestination(dynamicQrPrepareDestination("https://example.com/\r\nx"))['valid'], '...so the check still refuses it (nothing is silently removed)');
$dqPlan = dynamicQrPlanChange($dqRow(), ['destination_url' => 'https://example.com/a b'], $dqContext);
assertTrue($dqPlan['ok'] && $dqPlan['newDestination'] === 'https://example.com/a%20b' && $dqPlan['remoteTarget'] === 'https://example.com/a%20b', 'the change planner stores and sends the same encoded form');
