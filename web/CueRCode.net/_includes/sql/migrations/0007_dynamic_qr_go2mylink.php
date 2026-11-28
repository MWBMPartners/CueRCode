<?php
/**
 * ==============================================================================
 * 🧱 CueRCode - Migration 0007: dynamic QR on Go2My.Link (WP4a, #164; part of #27)
 * ==============================================================================
 *
 * Path: _includes/sql/migrations/0007_dynamic_qr_go2mylink.php
 *
 * WHAT IT DOES (plain English):
 *   1. tblQRRedirects (one row per dynamic code) gets what the Go2My.Link
 *      approach needs (plan .claude/plans/first-launch-plan.md, WP4a):
 *        - shortCode: the code printed after QRmy.Link/ (unique; CueRCode
 *          chooses it and stores it BEFORE asking Go2My.Link, lead choice L1);
 *        - syncState: unlinked (not asked yet - every existing code), pending
 *          (asked, answer not known), active (Go2My.Link has the link on the
 *          confirmed short domain), unconfirmed (Go2My.Link holds a link that
 *          must not be printed; an admin must look) or failed - a short word,
 *          not an ENUM, so a later upgrade can add states (review L6);
 *        - lastSyncError: the reason the last call failed, as one of the
 *          fixed reason words in _functions/fn_go2mylink.php - never
 *          Go2My.Link's own text and never anything secret;
 *        - suspendedAt, suspendedByUserID, suspensionNote: when a code was
 *          taken down, by which admin (empty = automatically: three reports
 *          or a link-safety hit), and the written reason.
 *   2. tblAbuseReports: one row per report about a dynamic code. The
 *      reporter's email is stored ENCRYPTED (encryptValue(), AES-256-GCM with
 *      HKDF and its own salt). Their internet address is never stored: only
 *      a keyed hash of it, counted as its /64 network for IPv6 (the same rule
 *      as every other limit, clientAddressCountingKey()), so "3 reports from
 *      different people in 24 hours" (owner answer O-4, decision 0034) can
 *      be counted. Decision 0046: 12 months after a report is dealt with,
 *      the email, the description and the hash are removed (the cron
 *      clean-up; personalDetailsRemovedAt says when) and a bare record that
 *      a report happened stays.
 *   3. tblUserLimitOverrides: a limit a Global Admin set for ONE person
 *      (decision 0031: "keep 3 but allow global admin to specifically approve
 *      beyond 3 on a per-user basis"). Decision 0045: it only ever RAISES
 *      their plan's number (they get the higher of the two) and is ignored
 *      while a payment is overdue. Any limit a plan has can be raised this
 *      way, not only dynamic_codes, so no commercial rule is fixed in code.
 *   4. The short-link address's seeded description is corrected (it still
 *      said /r/{uuid}), only while it is the seeded wording (review L11).
 *   5. Starting settings a Global Admin can change:
 *        - Go2My.Link: API address (production; an alpha database sets its
 *          own, decision 0030), API key (stored encrypted), waiting time;
 *        - link safety: the providers to ask, in order, as a list
 *          ("webrisk" first and only for now, decisions 0029 and 0035), and
 *          the Google Web Risk key (stored encrypted);
 *        - abuse: reports before an automatic suspension (3) and the window
 *          (24 hours), the takedown promise ("We act on reports within one
 *          working day.") and the abuse mailbox (abuse@cuercode.net),
 *          decision 0034.
 *
 * Nothing switches dynamic QR on: dynamic_qr_enabled stays 0 and locked.
 * Nothing overwrites a value the owner has set: every insert ends with the
 * no-op ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`. Every step
 * checks the live database first, so the whole upgrade is safe to run again
 * and to finish after stopping half-way.
 *
 * (install.sql is NOT changed: since task 7n Phase 1A it is a frozen
 * starting point and every later change is a numbered upgrade like this one;
 * the web installer applies them straight after install.sql.)
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
 * @since       2026-11-28 (WP4a, #164)
 * @see         ../../../_functions/fn_migrations.php - the runner
 * @see         ../../../_functions/fn_go2mylink.php - reads the Go2My.Link settings and the sync reasons
 * @see         ../../../_functions/fn_abuse_reports.php - the reporter-address hash
 * @see         ../../../_functions/fn_features.php - limitFor() honours tblUserLimitOverrides
 * @see         docs/decisions/0029, 0030, 0031, 0034, 0035
 */

return [
    'key'         => '0007_dynamic_qr_go2mylink',
    'description' => 'Dynamic QR on Go2My.Link: short code, sync state and suspension columns on tblQRRedirects, the abuse report and per-person limit tables, and the Go2My.Link, link-safety and abuse settings, without overwriting any existing value',
    'steps'       => [

        // ======================================================================
        // 🔗 tblQRRedirects: the Go2My.Link short code and its sync state
        // ======================================================================
        [
            'type'  => 'add_column',
            'table' => 'tblQRRedirects',
            'name'  => 'shortCode',
            'sql'   => 'ALTER TABLE `tblQRRedirects` ADD COLUMN `shortCode` VARCHAR(50) NULL'
                . ' COMMENT \'🔗 The code printed after the short-link address (QRmy.Link/<code>); chosen and stored by CueRCode BEFORE Go2My.Link is asked (lead choice L1); unique\''
                . ' AFTER `redirectUUID`',
        ],
        [
            'type'  => 'add_index',
            'table' => 'tblQRRedirects',
            'name'  => 'uq_redirect_short_code',
            'sql'   => 'ALTER TABLE `tblQRRedirects` ADD UNIQUE KEY `uq_redirect_short_code` (`shortCode`)',
        ],
        [
            'type'  => 'add_column',
            'table' => 'tblQRRedirects',
            'name'  => 'syncState',
            // A short word checked by the code (not an ENUM: an upgrade may
            // only ever add, and could never widen an ENUM's list later).
            // Existing codes start as "unlinked" (review L6), never "pending".
            'sql'   => 'ALTER TABLE `tblQRRedirects` ADD COLUMN `syncState` VARCHAR(16) NOT NULL DEFAULT \'unlinked\''
                . ' COMMENT \'🔄 unlinked = Go2My.Link not asked yet (every code from before upgrade 0007); pending = asked, answer not known; active = Go2My.Link has the link on the confirmed short domain (the only state that may be printed); unconfirmed = Go2My.Link holds a link that must not be printed (other or unconfirmed short domain, switched off, or the QR linked under another code) - an admin must look; failed = not made. go2mylinkSyncStateFor() sets it\''
                . ' AFTER `shortCode`',
        ],
        [
            'type'  => 'add_column',
            'table' => 'tblQRRedirects',
            'name'  => 'lastSyncError',
            'sql'   => 'ALTER TABLE `tblQRRedirects` ADD COLUMN `lastSyncError` VARCHAR(64) NULL'
                . ' COMMENT \'⚠️ Why the last Go2My.Link call failed: a reason word from fn_go2mylink.php (never Go2My.Link\'\'s own text, never a secret); NULL after a success\''
                . ' AFTER `syncState`',
        ],
        [
            'type'  => 'add_index',
            'table' => 'tblQRRedirects',
            'name'  => 'idx_redirect_sync',
            'sql'   => 'ALTER TABLE `tblQRRedirects` ADD INDEX `idx_redirect_sync` (`syncState`, `createdAt`)',
        ],

        // ======================================================================
        // 🚫 tblQRRedirects: suspension (takedown) details
        // ======================================================================
        [
            'type'  => 'add_column',
            'table' => 'tblQRRedirects',
            'name'  => 'suspendedAt',
            'sql'   => 'ALTER TABLE `tblQRRedirects` ADD COLUMN `suspendedAt` DATETIME NULL'
                . ' COMMENT \'🚫 When the code was suspended (UTC); NULL when it is not suspended\''
                . ' AFTER `disabledReason`',
        ],
        [
            'type'  => 'add_column',
            'table' => 'tblQRRedirects',
            'name'  => 'suspendedByUserID',
            'sql'   => 'ALTER TABLE `tblQRRedirects` ADD COLUMN `suspendedByUserID` INT UNSIGNED NULL'
                . ' COMMENT \'👤 The admin who suspended it; NULL = suspended automatically (three reports, or a link-safety hit)\''
                . ' AFTER `suspendedAt`',
        ],
        [
            'type'  => 'add_column',
            'table' => 'tblQRRedirects',
            'name'  => 'suspensionNote',
            'sql'   => 'ALTER TABLE `tblQRRedirects` ADD COLUMN `suspensionNote` VARCHAR(1000) NULL'
                . ' COMMENT \'📝 Why it was suspended, in plain words (shown to admins only)\''
                . ' AFTER `suspendedByUserID`',
        ],
        [
            'type'  => 'add_foreign_key',
            'table' => 'tblQRRedirects',
            'name'  => 'fk_redirect_suspended_by',
            'sql'   => 'ALTER TABLE `tblQRRedirects` ADD CONSTRAINT `fk_redirect_suspended_by` FOREIGN KEY (`suspendedByUserID`)'
                . ' REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL ON UPDATE CASCADE',
        ],

        // ======================================================================
        // 📣 tblAbuseReports - reports about a dynamic code
        // ======================================================================
        // category and status are short words checked by the code (not an
        // ENUM), because an upgrade may only ever add and could never widen
        // an ENUM's list later.
        [
            'type'  => 'create_table',
            'table' => 'tblAbuseReports',
            'sql'   => 'CREATE TABLE IF NOT EXISTS `tblAbuseReports` ('
                . ' `reportID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'🔑 Primary key\','
                . ' `redirectID` BIGINT UNSIGNED NULL COMMENT \'🔗 FK tblQRRedirects - the code reported (NULL when the reported code is unknown or was removed)\','
                . ' `shortCode` VARCHAR(50) NOT NULL COMMENT \'🔗 The code as the reporter gave it, kept even when it matches no code\','
                . ' `category` VARCHAR(32) NOT NULL COMMENT \'🏷️ What kind of harm, for example phishing, malware, scam, illegal, other\','
                . ' `details` TEXT NULL COMMENT \'📝 What the reporter wrote (length limited by the form); removed 12 months after the report is dealt with (decision 0046)\','
                . ' `reporterEmailEncrypted` TEXT NULL COMMENT \'🔐 The reporter\'\'s email if they gave one, encrypted with encryptValue() (AES-256-GCM, HKDF, its own salt); NULL when none was given or once removed (decision 0046)\','
                . ' `reporterAddressHash` CHAR(64) NULL COMMENT \'🔐 Keyed hash of the reporter\'\'s internet address as counted (IPv6 as its /64), for counting different people; never the address itself; NULL once removed (decision 0046)\','
                . ' `status` VARCHAR(16) NOT NULL DEFAULT \'new\' COMMENT \'📊 new, reviewing, actioned or dismissed\','
                . ' `handledByUserID` INT UNSIGNED NULL COMMENT \'👤 FK tblUsers - the admin who handled it\','
                . ' `handledAt` DATETIME NULL COMMENT \'📅 When it was handled (UTC)\','
                . ' `personalDetailsRemovedAt` DATETIME NULL COMMENT \'🧹 When the email, description and address hash were removed (UTC), by the cron clean-up 12 months (an admin setting) after a report was dealt with (decision 0046); the bare record stays\','
                . ' `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'📅 When it was reported (UTC)\','
                . ' `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT \'📅 Last change\','
                . ' PRIMARY KEY (`reportID`),'
                . ' INDEX `idx_abuse_redirect_created` (`redirectID`, `createdAt`),'
                . ' INDEX `idx_abuse_code_created` (`shortCode`, `createdAt`),'
                . ' INDEX `idx_abuse_reporter_created` (`reporterAddressHash`, `createdAt`),'
                . ' INDEX `idx_abuse_status_created` (`status`, `createdAt`),'
                . ' INDEX `idx_abuse_details_removal` (`personalDetailsRemovedAt`, `handledAt`),'
                . ' CONSTRAINT `fk_abuse_redirect` FOREIGN KEY (`redirectID`)'
                . ' REFERENCES `tblQRRedirects` (`redirectID`) ON DELETE SET NULL ON UPDATE CASCADE,'
                . ' CONSTRAINT `fk_abuse_handled_by` FOREIGN KEY (`handledByUserID`)'
                . ' REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL ON UPDATE CASCADE'
                . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                . ' COMMENT=\'📣 Reports about dynamic codes (WP4a/WP4f) - the reporter email encrypted, the address only as a keyed hash\'',
        ],

        // ======================================================================
        // 🎚️ tblUserLimitOverrides - one person's own limit (decision 0031)
        // ======================================================================
        [
            'type'  => 'create_table',
            'table' => 'tblUserLimitOverrides',
            'sql'   => 'CREATE TABLE IF NOT EXISTS `tblUserLimitOverrides` ('
                . ' `userID` INT UNSIGNED NOT NULL COMMENT \'👤 FK tblUsers - whose limit this is\','
                . ' `limitKey` VARCHAR(64) NOT NULL COMMENT \'🏷️ The plan limit raised, for example dynamic_codes (a name some plan has)\','
                . ' `limitValue` INT NOT NULL COMMENT \'🔢 The person\'\'s own number: they get the higher of this and their plan\'\'s (-1 = no limit); ignored while a payment is overdue (decision 0045)\','
                . ' `reason` VARCHAR(500) NULL COMMENT \'📝 Why the Global Admin set it\','
                . ' `setByUserID` INT UNSIGNED NULL COMMENT \'👤 FK tblUsers - the Global Admin who set it\','
                . ' `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'📅 When it was first set (UTC)\','
                . ' `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT \'📅 Last change\','
                . ' PRIMARY KEY (`userID`, `limitKey`),'
                . ' INDEX `idx_limit_override_set_by` (`setByUserID`),'
                . ' CONSTRAINT `fk_limit_override_user` FOREIGN KEY (`userID`)'
                . ' REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE ON UPDATE CASCADE,'
                . ' CONSTRAINT `fk_limit_override_set_by` FOREIGN KEY (`setByUserID`)'
                . ' REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL ON UPDATE CASCADE'
                . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                . ' COMMENT=\'🎚️ A Global Admin\'\'s raised limit for one person: the higher of this and their plan\'\'s number applies (decisions 0031, 0045)\'',
        ],

        // ======================================================================
        // ⚙️ Go2My.Link settings (Global Admin editable, /admin/settings/)
        // ======================================================================
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting go2mylink_api_base_url',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'redirects\', \'go2mylink_api_base_url\', \'https://go2my.link/api/v1\', \'url\', 0, 1, \'Go2My.Link API address used for dynamic codes (production by default; an alpha test database sets its own, decision 0030)\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting go2mylink_api_key',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'redirects\', \'go2mylink_api_key\', \'\', \'string\', 1, 1, \'Go2My.Link API key (scopes qr:link, urls:read, urls:write) - stored encrypted and never shown back\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting go2mylink_timeout_seconds',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'redirects\', \'go2mylink_timeout_seconds\', \'5\', \'integer\', 0, 1, \'Go2My.Link: seconds to wait for an answer. Whole number 2-15; anything else means the default of 5.\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],

        // 📝 Review L11: the seeded description of the short-link address
        //    still said codes print as /r/{uuid}. Replaced ONLY while it is
        //    still exactly the seeded wording (seed_data.sql).
        [
            'type'  => 'update_if_unchanged',
            'table' => 'tblSettings',
            'label' => 'description of dynamic_redirect_base_url',
            'sql'   => 'UPDATE `tblSettings` SET `description` = \'The short-link address printed in dynamic codes ({address}/{short code}); must be the CueRCode organisation\'\'s default short domain on Go2My.Link (https://QRmy.Link; an alpha or beta copy uses https://alpha.QRmy.Link, decision 0030)\''
                . ' WHERE `settingKey` = \'dynamic_redirect_base_url\''
                . ' AND `description` = \'Base URL prefixed to /r/{uuid} when building a dynamic QR payload (buildPayload_dynamic_url(), fn_qr_payloads.php) - owner-confirmable before any code is printed for real; see LAUNCH_DECISIONS.md O-11 (defaults to the tertiary domain QRmy.Link to partition redirect-abuse reputation away from the primary product domain)\'',
        ],

        // ======================================================================
        // 🛡️ Link-safety settings: a LIST of providers, Web Risk first (0029, 0035)
        // ======================================================================
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting link_safety_providers',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'link_safety\', \'link_safety_providers\', \'webrisk\', \'string\', 0, 1, \'Link-safety check: the providers asked about a destination, in order, separated by commas. Known so far: webrisk (Google Web Risk). A second paid provider is to be added later (decisions 0029 and 0035). A provider with no key is skipped.\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting link_safety_webrisk_api_key',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'link_safety\', \'link_safety_webrisk_api_key\', \'\', \'string\', 1, 1, \'Google Web Risk API key (restricted to the Web Risk API) - stored encrypted and never shown back\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],

        // ======================================================================
        // 📣 Abuse handling (owner answer O-4, decision 0034)
        // ======================================================================
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting abuse_auto_suspend_reports',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'abuse\', \'abuse_auto_suspend_reports\', \'3\', \'integer\', 0, 1, \'Abuse: reports from different people (an IPv6 address counts as its /64) within the window below that suspend a dynamic code automatically. Whole number 1-100; anything else means the default of 3.\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting abuse_auto_suspend_window_hours',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'abuse\', \'abuse_auto_suspend_window_hours\', \'24\', \'integer\', 0, 1, \'Abuse: the window, in hours, in which those reports are counted. Whole number 1-720; anything else means the default of 24.\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting abuse_takedown_promise',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'abuse\', \'abuse_takedown_promise\', \'We act on reports within one working day.\', \'string\', 0, 1, \'Abuse: the takedown promise shown on the report page, the suspended page and in the terms (decision 0034)\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
        [
            'type'  => 'insert_if_missing',
            'table' => 'tblSettings',
            'label' => 'setting abuse_mailbox',
            'sql'   => 'INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`)'
                . ' VALUES (\'abuse\', \'abuse_mailbox\', \'abuse@cuercode.net\', \'email\', 0, 1, \'Abuse: the mailbox that receives every report and takedown notice (decision 0034; abuse@qrmy.link forwards to it)\')'
                . ' ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
        ],
    ],
];
