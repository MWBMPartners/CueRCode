<?php
/**
 * ==============================================================================
 * 🧱 CueRCode - Migration 0003: "sessions valid from" mark (review finding 1)
 * ==============================================================================
 *
 * Path: _includes/sql/migrations/0003_sessions_valid_from.php
 *
 * WHAT IT DOES (plain English): adds two empty columns.
 *   1. tblUsers.sessionsValidFrom - the moment (UTC, to the millionth of a
 *      second) after which only NEW sign-ins count for this account. A
 *      password reset and the command-line "grant --activate" move it
 *      forward, in the same transaction that ends every session.
 *   2. tblUserSessions.sessionsValidFromSeen - a copy of the account's mark
 *      as it was when THIS sign-in checked the password (or the Google /
 *      Microsoft link). currentUser() refuses any session whose copy is not
 *      the account's current mark.
 *
 * WHY: an independent review (2026-11-25) showed that a sign-in already under
 * way when a password is reset could still finish AFTER the reset had ended
 * every session, leaving the old password's holder signed in. Comparing the
 * session's own creation time with the reset time cannot catch that (the late
 * session is created after the reset); comparing the mark the sign-in SAW
 * with the account's current mark does, whatever the timing.
 *
 * Both columns are NULL by default: nothing that exists today changes. An
 * account that has never been reset keeps NULL, and its sessions (copy NULL)
 * stay valid.
 *
 * ⚠️ This file is DATA, not code: it only returns an array. It is read by
 *    _functions/fn_migrations.php, which checks every step before it runs.
 *    Never edit it after it has been applied anywhere - put new work in a new
 *    numbered file. (The plan's later migrations, written as 0003-0006 in
 *    .claude/plans/7n-admin-commerce-plan.md, take the next free numbers.)
 *
 * @package     CueRCode
 * @subpackage  Database
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-25 (independent review of decisions 0013/0014, finding 1, #31)
 * @see         ../../../_functions/fn_migrations.php - the runner
 * @see         ../../../_functions/fn_auth.php - currentUser(), issueUserSession(), accountRemoveOtherWaysIn()
 */

return [
    'key'         => '0003_sessions_valid_from',
    'description' => 'Adds tblUsers.sessionsValidFrom and tblUserSessions.sessionsValidFromSeen so a sign-in that started before a password reset can never leave a working session',
    'steps'       => [

        // ----------------------------------------------------------------------
        // 🕐 tblUsers.sessionsValidFrom - moved forward by a reset or an admin grant
        // ----------------------------------------------------------------------
        [
            'type'  => 'add_column',
            'table' => 'tblUsers',
            'name'  => 'sessionsValidFrom',
            'sql'   => 'ALTER TABLE `tblUsers` ADD COLUMN `sessionsValidFrom` DATETIME(6) NULL'
                . ' COMMENT \'🕐 UTC moment after which only new sign-ins count (moved forward by a password reset or an admin grant, in the same transaction that ends every session; NULL = never moved)\''
                . ' AFTER `lockedUntil`',
        ],

        // ----------------------------------------------------------------------
        // 🪪 tblUserSessions.sessionsValidFromSeen - the mark this sign-in saw
        // ----------------------------------------------------------------------
        [
            'type'  => 'add_column',
            'table' => 'tblUserSessions',
            'name'  => 'sessionsValidFromSeen',
            'sql'   => 'ALTER TABLE `tblUserSessions` ADD COLUMN `sessionsValidFromSeen` DATETIME(6) NULL'
                . ' COMMENT \'🪪 Copy of tblUsers.sessionsValidFrom read together with the password (or linked sign-in) this session was issued for; the session counts only while it still equals the account value\''
                . ' AFTER `revokedAt`',
        ],
    ],
];
