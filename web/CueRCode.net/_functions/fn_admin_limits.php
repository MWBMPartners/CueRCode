<?php
/**
 * ==============================================================================
 * 🎚️ CueRCode - A Global Admin sets one person's own limit (decision 0031)
 * ==============================================================================
 *
 * Path: _functions/fn_admin_limits.php
 *
 * WHAT THIS IS (plain English):
 *   Decision 0031: everyone keeps their plan's number of dynamic codes (3 on
 *   Free), and a Global Admin can approve more for one named person. This
 *   file saves (or removes) such a number in tblUserLimitOverrides (upgrade
 *   0007), with a written reason, in ONE transaction with its row in the
 *   admin change log ("entitlement.update"), so a change never exists
 *   without its record. limitFor() in fn_features.php then uses it.
 *
 *   Decision 0045: the number only ever RAISES the plan's number (the person
 *   gets the higher of the two) and is ignored while a payment is overdue.
 *
 *   Any limit that some plan has can be set this way (not only
 *   dynamic_codes), so no commercial rule is fixed in code; a name no plan
 *   has is refused. The function checks itself that the caller is a working
 *   Global Admin. The screen that calls it is WP4c/WP4d (#166, #167);
 *   nothing on the site calls it yet.
 *
 * House rules: full multi-line PHP; MySQLi prepared statements (through
 * dbQuery()); back-quoted SQL names. Loading defines functions only.
 *
 * @package     CueRCode
 * @subpackage  Admin
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (WP4a, #164)
 * @see         _includes/sql/migrations/0007_dynamic_qr_go2mylink.php - tblUserLimitOverrides
 * @see         _functions/fn_features.php - limitFor(), userLimitOverrideFor()
 * @see         _functions/fn_admin.php - the audit trail
 */

// ==============================================================================
// 🚫 Direct access protection
// ==============================================================================
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $adminLimitsScriptFilename = $_SERVER['SCRIPT_FILENAME'];
} else {
    $adminLimitsScriptFilename = '';
}
if (basename($adminLimitsScriptFilename) === basename(__FILE__)) {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
    }
    exit('Access denied.');
}
unset($adminLimitsScriptFilename);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_admin.php';

/** @var int The largest number a person's own limit may be (-1 means no limit). */
if (!defined('CUERCODE_ADMIN_LIMIT_OVERRIDE_MAX')) {
    define('CUERCODE_ADMIN_LIMIT_OVERRIDE_MAX', 1000000);
}

/**
 * ✅ Check what an admin typed for one person's limit. PURE.
 *
 *   - the limit name: lower-case letters and underscores (a plan limit key);
 *   - the number: empty = remove the person's own number (back to the plan),
 *     -1 = no limit, or a whole number 1 to 1,000,000 written plainly (0 is
 *     refused: decision 0045 makes it a raise only, and 0 raises nothing);
 *   - the reason: required, up to 500 characters, one line.
 *
 * @param string $limitKey
 * @param string $valueText
 * @param string $reason
 * @return array{ok: bool, value: int|null, reason: string, error: string}
 */
function adminUserLimitOverrideCheck(string $limitKey, string $valueText, string $reason): array
{
    $refuse = static function (string $message): array {
        return ['ok' => false, 'value' => null, 'reason' => '', 'error' => $message];
    };

    if (preg_match('/^[a-z][a-z_]{0,63}$/D', $limitKey) !== 1) {
        return $refuse('That limit was not recognised.');
    }

    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason, 'UTF-8') > 500 || preg_match('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', $reason) !== 0) {
        return $refuse('Write a short reason on one line (up to 500 characters). It is kept in the admin change log.');
    }

    $valueText = trim($valueText);
    if ($valueText === '') {
        return ['ok' => true, 'value' => null, 'reason' => $reason, 'error' => ''];
    }
    if ($valueText === '-1') {
        return ['ok' => true, 'value' => -1, 'reason' => $reason, 'error' => ''];
    }
    if (preg_match('/^[1-9][0-9]{0,6}$/D', $valueText) !== 1 || (int) $valueText > CUERCODE_ADMIN_LIMIT_OVERRIDE_MAX) {
        return $refuse('Type a whole number from 1 to ' . number_format(CUERCODE_ADMIN_LIMIT_OVERRIDE_MAX) . ', -1 for no limit, or leave it empty to use the plan\'s number again. A person\'s own number only ever raises their plan\'s number.');
    }

    return ['ok' => true, 'value' => (int) $valueText, 'reason' => $reason, 'error' => ''];
}

/**
 * 🏷️ Does any plan (tblSubscriptionPlans.limitsJSON) have this limit? The
 *    list comes from the plans themselves, so a new limit an admin adds to
 *    a plan is recognised with no code change.
 *
 * @param string $limitKey Already checked by adminUserLimitOverrideCheck()
 * @return bool|null Null when the plans could not be read
 */
function adminLimitKeyIsKnown(string $limitKey): ?bool
{
    try {
        $stmt = dbQuery('SELECT `limitsJSON` FROM `tblSubscriptionPlans`', '', []);
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'The plan limits could not be read', get_class($e));
        }
        return null;
    }

    foreach ($rows as $row) {
        $limits = json_decode((string) $row['limitsJSON'], true);
        if (is_array($limits) && array_key_exists($limitKey, $limits)) {
            return true;
        }
    }

    return false;
}

/**
 * 💾 Save (or, with null, remove) one person's own number for one limit,
 *    with its audit row, in one transaction.
 *
 * @param array<string, mixed> $admin    From requireGlobalAdminPage()
 * @param int                  $userID   The person
 * @param string               $limitKey For example dynamic_codes
 * @param int|null             $value    The number (-1 = no limit); null = remove
 * @param string               $reason   Checked by adminUserLimitOverrideCheck()
 * @return array{ok: bool, message: string}
 */
function adminSetUserLimitOverride(array $admin, int $userID, string $limitKey, ?int $value, string $reason): array
{
    $valueText = '';
    if ($value !== null) {
        $valueText = (string) $value;
    }
    $checked = adminUserLimitOverrideCheck($limitKey, $valueText, $reason);
    if (!$checked['ok']) {
        return ['ok' => false, 'message' => $checked['error']];
    }

    if (!adminTableExists('tblUserLimitOverrides') || !adminTableExists('tblAdminAuditLog')) {
        return ['ok' => false, 'message' => 'Database upgrades are waiting, so this cannot be saved yet.'];
    }

    // 🛡️ Review M3: checked HERE, not only by the page that calls this: the
    //    caller must be a working Global Admin, read fresh from the database.
    $adminUserID = 0;
    if (isset($admin['userID']) && is_int($admin['userID'])) {
        $adminUserID = $admin['userID'];
    }
    if (adminAccessDecision(['userID' => $adminUserID], adminLoadAccessRow($adminUserID)) !== 'admin') {
        return ['ok' => false, 'message' => 'Only a Global Admin can set a person\'s own limit, so nothing was saved.'];
    }

    // 🏷️ Review M3: only a limit some plan actually has (a typo would be
    //    saved and do nothing).
    $known = adminLimitKeyIsKnown($limitKey);
    if ($known === null) {
        return ['ok' => false, 'message' => 'The plans could not be read, so nothing was saved. Please try again.'];
    }
    if ($known === false) {
        return ['ok' => false, 'message' => 'That limit was not recognised: no plan has a limit called "' . $limitKey . '", so nothing was saved.'];
    }

    try {
        getDB()->begin_transaction();
    } catch (\Throwable $e) {
        return ['ok' => false, 'message' => 'Not saved. Nothing was changed. Please try again.'];
    }

    try {
        // 👤 The person must exist.
        $userStmt = dbQuery('SELECT `userID` FROM `tblUsers` WHERE `userID` = ? LIMIT 1', 'i', [$userID]);
        $userRow  = $userStmt->get_result()->fetch_assoc();
        $userStmt->close();
        if (!is_array($userRow)) {
            adminRollbackQuietly(getDB());
            return ['ok' => false, 'message' => 'That person was not found, so nothing was saved.'];
        }

        // 📥 What is there now (locked until the end of the transaction).
        $oldStmt = dbQuery('SELECT `limitValue` FROM `tblUserLimitOverrides` WHERE `userID` = ? AND `limitKey` = ? FOR UPDATE', 'is', [$userID, $limitKey]);
        $oldRow  = $oldStmt->get_result()->fetch_assoc();
        $oldStmt->close();
        $oldValue = null;
        if (is_array($oldRow)) {
            $oldValue = (int) $oldRow['limitValue'];
        }

        if ($oldValue === $checked['value']) {
            adminRollbackQuietly(getDB());
            return ['ok' => true, 'message' => 'Nothing was different, so nothing was saved.'];
        }

        if ($checked['value'] === null) {
            $writeStmt = dbQuery('DELETE FROM `tblUserLimitOverrides` WHERE `userID` = ? AND `limitKey` = ?', 'is', [$userID, $limitKey]);
        } else {
            $writeStmt = dbQuery(
                'INSERT INTO `tblUserLimitOverrides` (`userID`, `limitKey`, `limitValue`, `reason`, `setByUserID`) VALUES (?, ?, ?, ?, ?)'
                // (Placeholders again rather than VALUES(), which MySQL 8.0.20+ marks as going away.)
                . ' ON DUPLICATE KEY UPDATE `limitValue` = ?, `reason` = ?, `setByUserID` = ?',
                'isisiisi',
                [$userID, $limitKey, $checked['value'], $checked['reason'], (int) $admin['userID'], $checked['value'], $checked['reason'], (int) $admin['userID']]
            );
        }
        $writeStmt->close();

        $audited = adminAuditWrite(adminAuditEntryForAdmin(
            $admin,
            'entitlement.update',
            'user',
            (string) $userID,
            'success',
            [$limitKey => ['old' => $oldValue, 'new' => $checked['value']]],
            $checked['reason']
        ));
        if (!$audited) {
            adminRollbackQuietly(getDB());
            return ['ok' => false, 'message' => 'This could not be recorded in the admin change log, so nothing was saved.'];
        }

        getDB()->commit();
    } catch (\Throwable $e) {
        adminRollbackQuietly(getDB());
        if (function_exists('logError')) {
            logError('error', 'A person\'s own limit could not be saved', get_class($e));
        }
        return ['ok' => false, 'message' => 'Not saved. Nothing was changed. Please try again.'];
    }

    // 🧹 This request's cached answer is now out of date.
    if (function_exists('featuresCacheStore')) {
        featuresCacheStore('clear', 'limit_override:' . $userID . ':' . $limitKey);
    }

    if ($checked['value'] === null) {
        return ['ok' => true, 'message' => 'Saved: this person uses their plan\'s number again.'];
    }

    return ['ok' => true, 'message' => 'Saved: this person\'s own limit is now ' . $checked['value'] . '.'];
}
