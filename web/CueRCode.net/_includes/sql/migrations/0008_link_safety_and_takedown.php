<?php
/**
 * ==============================================================================
 * 🧱 CueRCode - Migration 0008: link safety, takedowns and abuse counting (WP4e/WP4f, #168, #169)
 * ==============================================================================
 *
 * Path: _includes/sql/migrations/0008_link_safety_and_takedown.php
 *
 * WHAT IT DOES (plain English):
 *   1. tblQRRedirects (one row per dynamic code) learns what the link-safety
 *      check said about its destination (WP4e, #168):
 *        - safetyVerdict: safe, unsafe or unchecked (no provider could answer,
 *          or none is set up - the code was let through and is checked again
 *          by the daily job); NULL = never checked (codes from before 0008);
 *        - safetyCheckedAt: when that was (UTC), so the daily job can check
 *          every active code again, oldest first, a limited number per run.
 *   2. tblQRRedirects learns whether a takedown has really reached Go2My.Link
 *      (lead choice L2: a suspended code's short address is pointed at
 *      CueRCode's neutral /unavailable/ page):
 *        - suspensionAppliedAt: when Go2My.Link was confirmed to point there;
 *          NULL while a code is suspended means "still to be sent" - the
 *          email-queue cron job sends it again (Go2My.Link was unreachable,
 *          or an owner's change was in flight at the same moment).
 *   3. tblLinkSafetyHits: one row each time a provider said a destination is
 *      unsafe (at create, at a change, or in the daily re-check). It is BOTH
 *      the record the admins see (the daily email, WP4g) AND the short cache
 *      Google's Lookup API asks for: a destination listed as unsafe is
 *      treated as unsafe, without asking again, until the expiry time Google
 *      gave (https://cloud.google.com/web-risk/docs/caching). Rows are deleted
 *      by the daily job after a keeping period (90 days by default, a Global
 *      Admin setting on /admin/settings/).
 *   4. tblAbuseReports (upgrade 0007) gains what WP4f (#169) needs to count
 *      "3 reports from different people in 24 hours" (decision 0034) without
 *      one person with many internet addresses being able to do it alone:
 *        - reporterNetworkHash: a keyed hash of the reporter's WIDER network
 *          (an IPv4 address's /24, an IPv6 address's /48), never the address;
 *          removed with the other personal details (decision 0046);
 *        - countedForSuspension: 1 only for a report that passed the human
 *          check (or while the check is switched off site-wide); a report
 *          sent without it (no JavaScript, or the provider was unreachable)
 *          is kept and read by a person, but never counted automatically.
 *
 * (One upgrade for both work packages, so the list of upgrades an owner
 * applies stays short; nothing here is used until its code is in place.)
 *
 * New settings have NO seeded rows: the code uses its default until a Global
 * Admin saves one (the "rowOptional" entries in fn_admin_settings.php).
 * Nothing switches anything on. Every step checks the live database first,
 * so the upgrade is safe to run again and to finish after stopping half-way.
 *
 * (install.sql is NOT changed: it is a frozen starting point and every later
 * change is a numbered upgrade like this one.)
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
 * @since       2026-11-28 (WP4e, #168; WP4f, #169)
 * @see         ../../../_functions/fn_migrations.php - the runner
 * @see         ../../../_functions/fn_url_reputation.php - the link-safety check and tblLinkSafetyHits
 * @see         ../../../_functions/fn_dynamic_qr_takedown.php - suspension and suspensionAppliedAt
 * @see         ../../../_functions/fn_abuse_reports.php - the reporter hashes and the counting
 * @see         docs/decisions/0029, 0034, 0035, 0046
 */

return [
    'key'         => '0008_link_safety_and_takedown',
    'description' => 'Link safety and takedowns: the safety verdict and the takedown confirmation on tblQRRedirects, the link-safety hits table, and the abuse-report network hash and counting flag',
    'steps'       => [

        // ======================================================================
        // 🛡️ tblQRRedirects: what the link-safety check said (WP4e)
        // ======================================================================
        [
            'type'  => 'add_column',
            'table' => 'tblQRRedirects',
            'name'  => 'safetyVerdict',
            // A short word checked by the code (not an ENUM: an upgrade may
            // only ever add, and could never widen an ENUM's list later).
            'sql'   => 'ALTER TABLE `tblQRRedirects` ADD COLUMN `safetyVerdict` VARCHAR(16) NULL'
                . ' COMMENT \'🛡️ The last link-safety answer about the destination: safe, unsafe, or unchecked (no provider could answer or none is set up: let through and checked again daily); NULL = never checked (codes from before upgrade 0008)\''
                . ' AFTER `lastSyncError`',
        ],
        [
            'type'  => 'add_column',
            'table' => 'tblQRRedirects',
            'name'  => 'safetyCheckedAt',
            'sql'   => 'ALTER TABLE `tblQRRedirects` ADD COLUMN `safetyCheckedAt` DATETIME NULL'
                . ' COMMENT \'📅 When the destination was last checked (UTC); the daily job checks every active code again, oldest first\''
                . ' AFTER `safetyVerdict`',
        ],
        [
            'type'  => 'add_index',
            'table' => 'tblQRRedirects',
            'name'  => 'idx_redirect_safety_checked',
            'sql'   => 'ALTER TABLE `tblQRRedirects` ADD INDEX `idx_redirect_safety_checked` (`safetyCheckedAt`)',
        ],

        // ======================================================================
        // 🚫 tblQRRedirects: has the takedown reached Go2My.Link? (lead choice L2)
        // ======================================================================
        [
            'type'  => 'add_column',
            'table' => 'tblQRRedirects',
            'name'  => 'suspensionAppliedAt',
            'sql'   => 'ALTER TABLE `tblQRRedirects` ADD COLUMN `suspensionAppliedAt` DATETIME NULL'
                . ' COMMENT \'🔗 When Go2My.Link was confirmed to point the suspended code at the neutral page (UTC); NULL while suspended = still to be sent, and the email-queue cron job sends it again\''
                . ' AFTER `suspensionNote`',
        ],
        [
            'type'  => 'add_index',
            'table' => 'tblQRRedirects',
            'name'  => 'idx_redirect_suspension_sync',
            'sql'   => 'ALTER TABLE `tblQRRedirects` ADD INDEX `idx_redirect_suspension_sync` (`suspendedAt`, `suspensionAppliedAt`)',
        ],

        // ======================================================================
        // 📋 tblLinkSafetyHits - every "unsafe" answer, also Google's cache
        // ======================================================================
        [
            'type'  => 'create_table',
            'table' => 'tblLinkSafetyHits',
            'sql'   => 'CREATE TABLE IF NOT EXISTS `tblLinkSafetyHits` ('
                . ' `hitID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'🔑 Primary key\','
                . ' `urlHash` CHAR(64) NOT NULL COMMENT \'🔎 SHA-256 of the exact destination asked about, to find it again quickly\','
                . ' `destinationURL` VARCHAR(2048) NOT NULL COMMENT \'🎯 The destination the provider listed (for the admins; deleted with the row)\','
                . ' `provider` VARCHAR(32) NOT NULL COMMENT \'🏷️ Which provider said so, for example webrisk\','
                . ' `threatTypes` VARCHAR(255) NOT NULL COMMENT \'☣️ What it was listed for, comma separated, for example MALWARE,SOCIAL_ENGINEERING\','
                . ' `occasion` VARCHAR(16) NOT NULL COMMENT \'📌 When it was asked: create, change, restore or recheck\','
                . ' `redirectID` BIGINT UNSIGNED NULL COMMENT \'🔗 FK tblQRRedirects - the code, when there was one (none for a refused new code)\','
                . ' `userID` INT UNSIGNED NULL COMMENT \'👤 FK tblUsers - who asked for it, when known\','
                . ' `expiresAt` DATETIME NOT NULL COMMENT \'⏳ Until when it is treated as unsafe without asking again (UTC; the provider\'\'s own expiry, at most a day)\','
                . ' `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'📅 When the provider said so (UTC)\','
                . ' PRIMARY KEY (`hitID`),'
                . ' INDEX `idx_safety_hit_url` (`urlHash`, `expiresAt`),'
                . ' INDEX `idx_safety_hit_created` (`createdAt`),'
                . ' CONSTRAINT `fk_safety_hit_redirect` FOREIGN KEY (`redirectID`)'
                . ' REFERENCES `tblQRRedirects` (`redirectID`) ON DELETE SET NULL ON UPDATE CASCADE,'
                . ' CONSTRAINT `fk_safety_hit_user` FOREIGN KEY (`userID`)'
                . ' REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL ON UPDATE CASCADE'
                . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                . ' COMMENT=\'🛡️ Every unsafe answer from a link-safety provider (WP4e): the admins\'\' record and the short cache the provider asks for\'',
        ],

        // ======================================================================
        // 📣 tblAbuseReports: counting different people (WP4f, decision 0034)
        // ======================================================================
        [
            'type'  => 'add_column',
            'table' => 'tblAbuseReports',
            'name'  => 'reporterNetworkHash',
            'sql'   => 'ALTER TABLE `tblAbuseReports` ADD COLUMN `reporterNetworkHash` CHAR(64) NULL'
                . ' COMMENT \'🔐 Keyed hash of the reporter\'\'s wider network (IPv4 /24, IPv6 /48), so one person with many addresses counts once; never the address; NULL once removed (decision 0046)\''
                . ' AFTER `reporterAddressHash`',
        ],
        [
            'type'  => 'add_column',
            'table' => 'tblAbuseReports',
            'name'  => 'countedForSuspension',
            'sql'   => 'ALTER TABLE `tblAbuseReports` ADD COLUMN `countedForSuspension` TINYINT(1) NOT NULL DEFAULT 0'
                . ' COMMENT \'🧮 1 = may count towards automatic suspension (passed the human check, or the check is switched off); 0 = sent without the check: kept and read by a person, never counted\''
                . ' AFTER `reporterNetworkHash`',
        ],
        [
            'type'  => 'add_index',
            'table' => 'tblAbuseReports',
            'name'  => 'idx_abuse_counting',
            'sql'   => 'ALTER TABLE `tblAbuseReports` ADD INDEX `idx_abuse_counting` (`redirectID`, `countedForSuspension`, `createdAt`)',
        ],
    ],
];
