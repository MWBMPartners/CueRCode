<?php
/**
 * ==============================================================================
 * 🧱 CueRCode - Migration 0005: human check settings (#163, decision 0024)
 * ==============================================================================
 *
 * Path: _includes/sql/migrations/0005_human_check_settings.php
 *
 * WHAT IT DOES (plain English):
 *   1. The human-check provider setting gets a new value, "auto": use the
 *      first provider whose site key AND secret key are entered, in the
 *      order Cloudflare Turnstile, Google reCAPTCHA, hCaptcha. "none" now
 *      means "switched off". A database still on the starting value "none"
 *      is moved to "auto" - but ONLY if nobody ever changed that setting
 *      from the admin screen or the command line (no row about it in the
 *      admin change log). So a fresh or untouched site is "off until keys
 *      are entered", and an owner's own choice is kept.
 *   2. The setting's description is updated to say this, only while it is
 *      still the starting wording.
 *   3. Adds two starting settings a Global Admin can change on
 *      /admin/settings/: what happens when the provider cannot be reached
 *      ("refuse", the starting value, or "allow"), and how long to wait for
 *      it (5 seconds; 2 to 15 allowed).
 *
 * Nothing here can overwrite a value the owner has set: the updates only
 * match rows still at their starting value, and the inserts end with the
 * no-op ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`.
 *
 * The keys themselves were already stored (seed_data.sql and migration
 * 0002, encrypted) - nothing about them changes here.
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
 * @since       2026-11-27 (#163)
 * @see         ../../../_functions/fn_migrations.php - the runner
 * @see         ../../../_functions/fn_human_check.php - what reads these settings
 */

return [
    'key'         => '0005_human_check_settings',
    'description' => 'Human check: the provider setting gains "auto" (first provider with both keys; "none" means off), plus the when-unreachable and waiting-time settings, without overwriting any existing value',
    'steps'       => [

        // ======================================================================
        // 🧭 "none" -> "auto", only while untouched by any admin or the CLI
        // ======================================================================
        // The admin change log (tblAdminAuditLog, migration 0002) gets a row
        // for every change made on /admin/settings/ or with the command-line
        // switch. No such row means the value is still the starting one.
        [
            'type'  => 'update_if_unchanged',
            'table' => 'tblSettings',
            'label' => 'bot_protection_provider none to auto',
            'sql'   => 'UPDATE `tblSettings` SET `settingValue` = \'auto\''
                . ' WHERE `settingKey` = \'bot_protection_provider\''
                . ' AND `settingValue` = \'none\''
                . ' AND NOT EXISTS (SELECT 1 FROM `tblAdminAuditLog`'
                . ' WHERE `entityType` = \'setting\' AND `entityKey` = \'bot_protection_provider\')',
        ],

        // 📝 The description, only while it is still the starting wording.
        [
            'type'  => 'update_if_unchanged',
            'table' => 'tblSettings',
            'label' => 'description of bot_protection_provider',
            'sql'   => 'UPDATE `tblSettings` SET `description` = \'Human check provider: auto (the first with both keys, in the order turnstile, recaptcha, hcaptcha), turnstile, recaptcha, hcaptcha, or none (off)\''
                . ' WHERE `settingKey` = \'bot_protection_provider\''
                . ' AND `description` = \'Active bot protection provider (none, turnstile, recaptcha, hcaptcha)\'',
        ],

        // ======================================================================
        // ⚙️ Two new starting settings (Global Admin editable)
        // ======================================================================
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting bot_protection_when_unreachable',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'security\', \'bot_protection_when_unreachable\', \'refuse\', \'string\', 0, 1, \'Human check: what happens when the provider cannot be reached - refuse (forms say try again later) or allow (people are let through). Always logged.\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting bot_protection_timeout_seconds',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'security\', \'bot_protection_timeout_seconds\', \'5\', \'integer\', 0, 1, \'Human check: seconds to wait for the provider before treating it as unreachable. Whole number 2-15; anything else means the default of 5 is used.\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
    ],
];
