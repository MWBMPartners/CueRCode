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
 *   3. Adds starting settings a Global Admin can change on /admin/settings/:
 *      what happens when EVERY configured provider cannot be reached
 *      ("signin_only", the starting value from decision 0037: only sign-in
 *      is let through, and it is recorded in the activity log; or "refuse"),
 *      how long to wait for a provider (5 seconds; 2 to 15 allowed), the
 *      per-visitor limit on the four web forms before the provider is asked
 *      (30 an hour), and the three limits of the email-confirmed route for
 *      visitors without JavaScript (decision 0038: 5 an hour per visitor
 *      address, 3 a day per email address, 30 an hour for the whole site).
 *   4. Adds four small tables:
 *      - tblHumanCheckTokens: the SHA-256 hash of every token a provider
 *        accepted, for 10 minutes, so a token can never be used twice
 *        (review finding M1);
 *      - tblHumanCheckState: the last result of the server's own test call
 *        to each provider (decision 0037), and when each bounded log line
 *        was last written;
 *      - tblNoScriptRequests: one row per use of the no-JavaScript route
 *        that was let through (the email only as a keyed hash), for its
 *        limits;
 *      - tblEmailSignInRequests: the no-JavaScript email sign-in links
 *        (only hashes of the link and of the browser's secret).
 *
 * (Changed in place on 2026-11-27, before it had been applied anywhere or
 * pushed, to add items 3 and 4 after the independent review of #163.)
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
    'description' => 'Human check: the provider setting gains "auto" ("none" means off), the outage, waiting-time and limit settings (decisions 0037 and 0038), and the tables for used tokens, provider tests and the no-JavaScript route, without overwriting any existing value',
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
        // ⚙️ New starting settings (Global Admin editable)
        // ======================================================================
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting bot_protection_when_unreachable',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'security\', \'bot_protection_when_unreachable\', \'signin_only\', \'string\', 0, 1, \'Human check: what happens when EVERY configured provider cannot be reached (by a test call the server makes itself) - signin_only (decision 0037: only sign-in is let through, recorded in the activity log) or refuse (every form is refused).\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting human_check_form_ip_per_hour',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'security\', \'human_check_form_ip_per_hour\', \'30\', \'integer\', 0, 1, \'Human check: the most times one visitor address may send the four account forms with a token in an hour, before the provider is asked. Whole number 1-1000; anything else means the default of 30.\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting no_script_route_ip_per_hour',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'security\', \'no_script_route_ip_per_hour\', \'5\', \'integer\', 0, 1, \'No-JavaScript email route (decision 0038): the most uses from one visitor address in an hour, all four forms together. Whole number 1-100; anything else means the default of 5.\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting no_script_route_email_per_day',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'security\', \'no_script_route_email_per_day\', \'3\', \'integer\', 0, 1, \'No-JavaScript email route (decision 0038): the most uses for one email address in 24 hours, whoever sends them. Whole number 1-20; anything else means the default of 3.\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting no_script_route_site_per_hour',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'security\', \'no_script_route_site_per_hour\', \'30\', \'integer\', 0, 1, \'No-JavaScript email route (decision 0038): the most uses for the whole site in an hour. Whole number 1-1000; anything else means the default of 30.\')'
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
        // ======================================================================
        // ♻️ tblHumanCheckTokens - accepted tokens, so none is used twice (M1)
        // ======================================================================
        [
            'type'  => 'create_table',
            'table' => 'tblHumanCheckTokens',
            'sql'   => 'CREATE TABLE IF NOT EXISTS `tblHumanCheckTokens` ('
                . ' `tokenHash` CHAR(64) NOT NULL COMMENT \'🔐 SHA-256 of a token the provider accepted (never the token itself)\','
                . ' `provider` VARCHAR(16) NOT NULL COMMENT \'🤖 turnstile, recaptcha or hcaptcha\','
                . ' `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'📅 When it was accepted\','
                . ' `expiresAt` DATETIME NOT NULL COMMENT \'⏳ After this the row is tidied away (10 minutes; every provider token lasts at most 5)\','
                . ' PRIMARY KEY (`tokenHash`),'
                . ' INDEX `idx_human_check_tokens_expires` (`expiresAt`)'
                . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                . ' COMMENT=\'♻️ Human check tokens already accepted, so the same token is refused a second time (#163 review M1)\'',
        ],

        // ======================================================================
        // 🩺 tblHumanCheckState - provider test results and bounded log lines
        // ======================================================================
        [
            'type'  => 'create_table',
            'table' => 'tblHumanCheckState',
            'sql'   => 'CREATE TABLE IF NOT EXISTS `tblHumanCheckState` ('
                . ' `stateKey` VARCHAR(64) NOT NULL COMMENT \'🔑 health:<provider> or log:<what>\','
                . ' `stateValue` VARCHAR(32) NOT NULL COMMENT \'🩺 up, down or misconfigured (from a test call the server makes itself), or log\','
                . ' `checkedAt` DATETIME NOT NULL COMMENT \'📅 When the value was written\','
                . ' `loggedAt` DATETIME NULL COMMENT \'📝 When the bounded log line for this key was last written\','
                . ' PRIMARY KEY (`stateKey`)'
                . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                . ' COMMENT=\'🩺 Human check: the last result of the test call the server makes to each provider, and bounded log timing (decision 0037)\'',
        ],

        // ======================================================================
        // ✉️ tblNoScriptRequests - the no-JavaScript route's limits (0038)
        // ======================================================================
        [
            'type'  => 'create_table',
            'table' => 'tblNoScriptRequests',
            'sql'   => 'CREATE TABLE IF NOT EXISTS `tblNoScriptRequests` ('
                . ' `requestID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'🔑 Primary key\','
                . ' `purpose` VARCHAR(16) NOT NULL COMMENT \'📋 login, register, forgot or resend\','
                . ' `emailHash` CHAR(64) NOT NULL COMMENT \'🔐 Keyed hash of the email address typed (never the address)\','
                . ' `requestIP` VARCHAR(45) NOT NULL COMMENT \'🌍 Visitor address (IPv4 or IPv6)\','
                . ' `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'📅 When it was let through\','
                . ' PRIMARY KEY (`requestID`),'
                . ' INDEX `idx_no_script_ip` (`requestIP`, `createdAt`),'
                . ' INDEX `idx_no_script_email` (`emailHash`, `createdAt`),'
                . ' INDEX `idx_no_script_created` (`createdAt`)'
                . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                . ' COMMENT=\'✉️ Uses of the no-JavaScript email route that were let through, for its limits (decision 0038)\'',
        ],

        // ======================================================================
        // 🎫 tblEmailSignInRequests - the no-JavaScript email sign-in links
        // ======================================================================
        [
            'type'  => 'create_table',
            'table' => 'tblEmailSignInRequests',
            'sql'   => 'CREATE TABLE IF NOT EXISTS `tblEmailSignInRequests` ('
                . ' `requestID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'🔑 Primary key\','
                . ' `userID` INT UNSIGNED NOT NULL COMMENT \'👤 FK tblUsers - whose correct password was typed\','
                . ' `tokenHash` CHAR(64) NOT NULL COMMENT \'🔐 SHA-256 of the emailed single-use link token\','
                . ' `bindingHash` CHAR(64) NOT NULL COMMENT \'🔐 SHA-256 of the secret kept in the session of the asking browser\','
                . ' `sessionsValidFromSeen` DATETIME(6) NULL COMMENT \'🕐 tblUsers.sessionsValidFrom read with the password; a reset moves it and the link stops working\','
                . ' `requestIP` VARCHAR(45) NOT NULL COMMENT \'🌍 Address of the asking browser\','
                . ' `userAgent` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'🖥️ Browser identification of the asking browser\','
                . ' `status` ENUM(\'waiting\',\'approved\',\'used\',\'cancelled\') NOT NULL DEFAULT \'waiting\' COMMENT \'📊 waiting for the link, approved from another browser, used, or cancelled\','
                . ' `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'📅 When the password was checked\','
                . ' `expiresAt` DATETIME NOT NULL COMMENT \'⏳ 15 minutes later\','
                . ' `approvedAt` DATETIME NULL COMMENT \'✅ When the link was approved from another browser\','
                . ' `usedAt` DATETIME NULL COMMENT \'🎫 When a browser was signed in with it\','
                . ' PRIMARY KEY (`requestID`),'
                . ' UNIQUE KEY `uq_email_sign_in_token` (`tokenHash`),'
                . ' INDEX `idx_email_sign_in_binding` (`bindingHash`),'
                . ' INDEX `idx_email_sign_in_expires` (`expiresAt`),'
                . ' INDEX `idx_email_sign_in_user` (`userID`, `status`),'
                . ' CONSTRAINT `fk_email_sign_in_user` FOREIGN KEY (`userID`)'
                . ' REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE ON UPDATE CASCADE'
                . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                . ' COMMENT=\'🎫 No-JavaScript email sign-in links (decision 0038) - only hashes are stored\'',
        ],
    ],
];
