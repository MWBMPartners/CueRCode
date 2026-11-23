<?php
/**
 * ==============================================================================
 * 🧱 CueRCode - Migration 0002: admin change log + Phase 1A starting settings
 * ==============================================================================
 *
 * Path: _includes/sql/migrations/0002_admin_audit_log_and_settings.php
 *
 * WHAT IT DOES (plain English):
 *   1. Creates tblAdminAuditLog - the permanent change log for the admin area
 *      (who changed what, when, from what to what). Secret values are never
 *      written to it, only "secret replaced". Nothing writes to it yet; the
 *      admin screens arrive in Phase 1B/1C.
 *   2. Adds the starting settings the admin settings screen (Phase 1C) will
 *      edit: hCaptcha and PayPal keys (stored encrypted, empty for now), the
 *      VAT wording, the money-back period, the free-trial switch (off), and
 *      the admin "confirm your password" time limits.
 *   3. Adds "hcaptcha" to the bot-protection setting's description, but only
 *      while that description is still the original wording.
 *
 * OWNER RULE (2026-10-05): every commercial rule is data a Global Admin can
 * change, never fixed in code. These rows are only STARTING values. Every
 * row is "insert if missing" (ON DUPLICATE KEY UPDATE `settingKey` =
 * `settingKey`, which changes nothing), so a value the owner has already
 * edited is never overwritten, however often this runs.
 *
 * The two email clean-up settings (#122) are already in seed_data.sql; they
 * are repeated here so a database installed before #122 gets them too (on a
 * newer database the insert simply does nothing).
 *
 * Deliberately NOT here: billing_discount_combining. Since the owner answer
 * of 2026-11-22, whether a discount may be combined is chosen per discount
 * (tblDiscounts.allowStacking, migration 0005), not by a site-wide setting.
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
 * @since       2026-11-23 (task 7n Phase 1A)
 * @see         ../../../_functions/fn_migrations.php - the runner
 * @see         ../seed_data.sql - the frozen starting settings (tblSettings)
 * @see         .claude/plans/7n-admin-commerce-plan.md §5.2 (tblAdminAuditLog), §5.4 (settings), §6.7 (audit trail)
 */

return [
    'key'         => '0002_admin_audit_log_and_settings',
    'description' => 'Creates tblAdminAuditLog and adds the Phase 1A admin settings (hCaptcha, PayPal, VAT, refunds, trials, admin password-confirmation limits) without overwriting any existing value',
    'steps'       => [

        // ======================================================================
        // 📜 tblAdminAuditLog - append-only admin change log (plan §5.2)
        // ======================================================================
        // No screen or function ever updates or deletes these rows. The user
        // link becomes NULL if a user is ever hard-deleted; actorLabel keeps
        // who it was at the time.
        [
            'type'  => 'create_table',
            'table' => 'tblAdminAuditLog',
            'sql'   => 'CREATE TABLE IF NOT EXISTS `tblAdminAuditLog` ('
                . ' `auditID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'🔑 Primary key\','
                . ' `actorUserID` INT UNSIGNED NULL COMMENT \'👤 FK tblUsers - the admin who acted (NULL for command line or system, or after a hard purge)\','
                . ' `actorType` ENUM(\'admin\',\'cli\',\'system\') NOT NULL COMMENT \'🧑‍💼 Who acted: an admin in the browser, the command line, or the system\','
                . ' `actorLabel` VARCHAR(255) NOT NULL COMMENT \'🏷️ Email at the time, or cli:<os user>, or system\','
                . ' `action` VARCHAR(64) NOT NULL COMMENT \'⚡ What was done, for example setting.update\','
                . ' `entityType` VARCHAR(50) NOT NULL COMMENT \'📦 Kind of thing changed: setting, plan, price_point, discount, user, schema\','
                . ' `entityKey` VARCHAR(100) NULL COMMENT \'🔑 Which one: settingKey, planKey, discountKey or a numeric id\','
                . ' `outcome` ENUM(\'success\',\'refused\',\'failed\') NOT NULL DEFAULT \'success\' COMMENT \'✅ How it ended\','
                . ' `changesJSON` JSON NULL COMMENT \'🔁 {field:{old,new}} - secrets ONLY as markers such as secret replaced, never the value\','
                . ' `reason` VARCHAR(500) NULL COMMENT \'📝 Reason typed by the admin (required for risky changes)\','
                . ' `requestIP` VARCHAR(45) NULL COMMENT \'🌐 IP address of the request (IPv4 or IPv6)\','
                . ' `userAgent` VARCHAR(500) NULL COMMENT \'🖥️ Browser identification of the request\','
                . ' `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'📅 When (UTC)\','
                . ' PRIMARY KEY (`auditID`),'
                . ' INDEX `idx_admin_audit_created` (`createdAt`),'
                . ' INDEX `idx_admin_audit_actor` (`actorUserID`, `createdAt`),'
                . ' INDEX `idx_admin_audit_entity` (`entityType`, `entityKey`),'
                . ' CONSTRAINT `fk_admin_audit_actor` FOREIGN KEY (`actorUserID`)'
                . ' REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL ON UPDATE CASCADE'
                . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                . ' COMMENT=\'📜 Append-only admin change log (task 7n) - never updated or deleted by any screen or function\'',
        ],

        // ======================================================================
        // 📧 Email clean-up periods (#122) - only for databases installed
        //    before #122; identical to the seed_data.sql rows.
        // ======================================================================
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting email_queue_retention_days',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'email\', \'email_queue_retention_days\', \'30\', \'integer\', 0, 1, \'Days to keep a SENT or SKIPPED email record in tblEmailQueue before the queue runner deletes it (#122), counted from when it was queued. Whole number 1-3650; anything else means the default of 30 is used.\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting email_queue_failed_retention_days',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'email\', \'email_queue_failed_retention_days\', \'90\', \'integer\', 0, 1, \'Days to keep a GIVEN-UP (failed) email record before the queue runner deletes it (#122) - longer than sent ones, because support is asked about these. Whole number 1-3650; anything else means the default of 90 is used.\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],

        // ======================================================================
        // 🤖 hCaptcha keys (third bot-protection choice) - secret, empty
        // ======================================================================
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting hcaptcha_site_key',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'security\', \'hcaptcha_site_key\', \'\', \'string\', 1, 1, \'hCaptcha site key, used when the bot protection provider is hcaptcha - stored encrypted\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting hcaptcha_secret_key',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'security\', \'hcaptcha_secret_key\', \'\', \'string\', 1, 1, \'hCaptcha secret key - stored encrypted and never shown back\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],

        // 📝 Mention hcaptcha in the provider description - ONLY while the
        //    description is still exactly the original seeded wording.
        [
            'type'  => 'update_if_unchanged',
            'table' => 'tblSettings',
            'label' => 'description of bot_protection_provider',
            'sql'   => 'UPDATE `tblSettings` SET `description` = \'Active bot protection provider (none, turnstile, recaptcha, hcaptcha)\''
                . ' WHERE `settingKey` = \'bot_protection_provider\''
                . ' AND `description` = \'Active bot protection provider (none, turnstile, recaptcha)\'',
        ],

        // ======================================================================
        // 💳 PayPal (charging stays OFF - these only store the details)
        // ======================================================================
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting paypal_mode',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'paypal\', \'paypal_mode\', \'sandbox\', \'string\', 0, 1, \'PayPal environment: sandbox (testing, no real money) or live. Charging stays off until the billing switches are turned on.\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting paypal_client_id',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'paypal\', \'paypal_client_id\', \'\', \'string\', 1, 1, \'PayPal app client ID - stored encrypted\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting paypal_client_secret',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'paypal\', \'paypal_client_secret\', \'\', \'string\', 1, 1, \'PayPal app client secret - stored encrypted and never shown back\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting paypal_webhook_id',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'paypal\', \'paypal_webhook_id\', \'\', \'string\', 1, 1, \'PayPal webhook ID, used to check that payment messages really come from PayPal - stored encrypted\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],

        // ======================================================================
        // 💷 VAT, refunds and trials (owner-editable starting values)
        // ======================================================================
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting billing_vat_mode',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'billing\', \'billing_vat_mode\', \'inclusive\', \'string\', 0, 1, \'How prices are shown: inclusive (prices include VAT), exclusive (VAT is added on top) or none (no VAT wording)\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting billing_vat_rate_percent',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'billing\', \'billing_vat_rate_percent\', \'20.00\', \'float\', 0, 1, \'VAT rate in percent, for example 20.00 for the UK standard rate\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting billing_refund_window_days',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'billing\', \'billing_refund_window_days\', \'30\', \'integer\', 0, 1, \'Money-back period: a customer may ask for a full refund within this many days of paying\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting billing_trials_enabled',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'billing\', \'billing_trials_enabled\', \'0\', \'boolean\', 0, 1, \'Free-trial switch: 1 = new customers may start a free trial, 0 = trials are off\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting billing_trial_plan_key',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'billing\', \'billing_trial_plan_key\', \'pro\', \'string\', 0, 1, \'Which plan a free trial gives (the plan key, for example pro)\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting billing_trial_days',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'billing\', \'billing_trial_days\', \'14\', \'integer\', 0, 1, \'Length of a free trial in days\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting billing_trial_requires_card',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'billing\', \'billing_trial_requires_card\', \'0\', \'boolean\', 0, 1, \'Free trial: 1 = a payment card is needed to start one, 0 = no card needed\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],

        // ======================================================================
        // 🛡️ Admin area: "confirm your password" limits and display time zone
        // ======================================================================
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting admin_reauth_idle_minutes',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'admin\', \'admin_reauth_idle_minutes\', \'15\', \'integer\', 0, 1, \'Admin area: after this many minutes without activity, the next change asks for the password again\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting admin_reauth_max_minutes',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'admin\', \'admin_reauth_max_minutes\', \'60\', \'integer\', 0, 1, \'Admin area: the longest a password confirmation lasts, in minutes, however active the admin is\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting admin_display_timezone',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'admin\', \'admin_display_timezone\', \'Europe/London\', \'string\', 0, 1, \'Time zone for dates and times shown in the admin area (for example Europe/London). Stored times stay in UTC.\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
    ],
];
