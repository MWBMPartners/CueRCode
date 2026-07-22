-- ==============================================================================
-- 🗄️ CueRCode - Database Installation Script
-- ==============================================================================
--
-- Creates all tables required for the CueRCode QR code generation service.
-- Run this script via the web-based installer at /install/ or manually via
-- phpMyAdmin or MySQL CLI.
--
-- @package     CueRCode
-- @subpackage  Database
-- @version     1.0.0
-- @since       Phase 1
-- @see         https://dev.mysql.com/doc/refman/8.0/en/create-table.html
-- @see         https://dev.mysql.com/doc/refman/8.0/en/innodb-storage-engine.html
--
-- ⚠️  IMPORTANT: This script uses InnoDB engine with utf8mb4 charset for full
--     Unicode support (including emojis). Ensure your MySQL server supports this.
--
-- 📌 Table naming convention: tblTableName (camelCase with tbl prefix)
-- 📌 Column naming convention: camelCase
-- 📌 All tables include createdAt and updatedAt timestamp columns
-- ==============================================================================

-- ==============================================================================
-- ⚙️ Set SQL mode and character set for this session
-- @see https://dev.mysql.com/doc/refman/8.0/en/sql-mode.html
-- ==============================================================================
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- ==============================================================================
-- 📋 Table 1: tblSettings
-- ==============================================================================
-- Stores ALL application configuration (except database connection credentials).
-- Settings marked as isSensitive are encrypted with AES-256-GCM before storage.
-- New settings can be added at any time via the admin UI without code changes.
--
-- @see https://dev.mysql.com/doc/refman/8.0/en/enum.html ENUM type
-- @see https://dev.mysql.com/doc/refman/8.0/en/datetime.html DATETIME type
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblSettings` (
    `settingID`     INT UNSIGNED        NOT NULL AUTO_INCREMENT  COMMENT '🔑 Primary key - auto-incrementing setting identifier',
    `settingGroup`  VARCHAR(100)        NOT NULL DEFAULT 'general' COMMENT '📂 Logical grouping (general, qr_defaults, api, email, security, branding)',
    `settingKey`    VARCHAR(255)        NOT NULL                 COMMENT '🏷️ Unique setting key name (e.g., site_name, default_qr_size)',
    `settingValue`  TEXT                NULL                     COMMENT '💾 The setting value (encrypted if isSensitive = 1)',
    `settingType`   ENUM(
                        'string',
                        'integer',
                        'float',
                        'boolean',
                        'json',
                        'color',
                        'url',
                        'path',
                        'email'
                    )                   NOT NULL DEFAULT 'string' COMMENT '📝 Data type for validation and type-casting',
    `isSensitive`   TINYINT(1)          NOT NULL DEFAULT 0       COMMENT '🔐 1 = value is encrypted with AES-256-GCM + salt',
    `isEditable`    TINYINT(1)          NOT NULL DEFAULT 1       COMMENT '✏️ 1 = can be modified via admin UI',
    `description`   VARCHAR(500)        NULL                     COMMENT '📖 Human-readable description of this setting',
    `encryptionSalt` BINARY(16)         NULL                     COMMENT '🧂 F-006/G-010 per-record encryption salt, populated when isSensitive = 1. The authoritative salt actually travels embedded in settingValue ciphertext blob produced by encryptValue() (self describing - decryptValue() never needs this column), so this column is a DBA-visible auditability mirror proving each row genuinely uses its own independent salt rather than one shared global salt. See fn_encryption.php / fn_config.php setConfig().',
    `createdAt`     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Record creation timestamp',
    `updatedAt`     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '📅 Last modification timestamp',

    PRIMARY KEY (`settingID`),
    UNIQUE KEY `idx_setting_key` (`settingKey`),
    INDEX `idx_setting_group` (`settingGroup`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='⚙️ Application configuration settings - all config except DB credentials';

-- ==============================================================================
-- 📋 Table 2: tblErrorLog
-- ==============================================================================
-- Logs all PHP errors, exceptions, warnings, and notices with full context.
-- Includes severity, backtrace, request details, and user information.
-- Used for debugging, troubleshooting, and monitoring application health.
--
-- @see https://www.php.net/manual/en/function.set-error-handler.php
-- @see https://www.php.net/manual/en/function.set-exception-handler.php
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblErrorLog` (
    `errorID`         BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT  COMMENT '🔑 Primary key - auto-incrementing error log identifier',
    `errorSeverity`   ENUM(
                          'emergency',
                          'alert',
                          'critical',
                          'error',
                          'warning',
                          'notice',
                          'info',
                          'debug'
                      )                 NOT NULL DEFAULT 'error'  COMMENT '⚠️ Severity level following PSR-3 LoggerInterface',
    `errorCode`       VARCHAR(50)       NULL                     COMMENT '🔢 PHP error code (E_ERROR, E_WARNING, etc.) or custom code',
    `errorTitle`      VARCHAR(500)      NOT NULL                 COMMENT '📝 Brief error title/summary',
    `errorDetail`     TEXT              NULL                     COMMENT '📋 Full error message and details',
    `errorBacktrace`  TEXT              NULL                     COMMENT '🔍 Stack trace for debugging',
    `requestURL`      VARCHAR(2048)     NULL                     COMMENT '🌐 Full URL where the error occurred',
    `requestMethod`   VARCHAR(10)       NULL                     COMMENT '📨 HTTP method (GET, POST, PUT, DELETE, etc.)',
    `requestHeaders`  TEXT              NULL                     COMMENT '📋 Request headers (sanitised - sensitive values redacted)',
    `requestIP`       VARCHAR(45)       NULL                     COMMENT '🌍 Client IP address (supports IPv4 and IPv6)',
    `userAgent`       VARCHAR(500)      NULL                     COMMENT '🖥️ Client user agent string',
    `userID`          INT UNSIGNED      NULL                     COMMENT '👤 Associated user ID (NULL for anonymous/guest - FK Phase 2)',
    `sessionID`       VARCHAR(128)      NULL                     COMMENT '🎫 PHP session ID for request correlation',
    `fileName`        VARCHAR(500)      NULL                     COMMENT '📄 Source file where error occurred',
    `lineNumber`      INT UNSIGNED      NULL                     COMMENT '📍 Line number in source file',
    `phpVersion`      VARCHAR(20)       NULL                     COMMENT '🐘 PHP version at time of error',
    `serverName`      VARCHAR(255)      NULL                     COMMENT '🖥️ Server hostname',
    `additionalData`  JSON              NULL                     COMMENT '📦 Additional context data as JSON',
    `createdAt`       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Error occurrence timestamp',

    PRIMARY KEY (`errorID`),
    INDEX `idx_error_severity` (`errorSeverity`),
    INDEX `idx_error_created` (`createdAt`),
    INDEX `idx_error_user` (`userID`),
    INDEX `idx_error_code` (`errorCode`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='🚨 Application error, warning, and exception log with full debugging context';

-- ==============================================================================
-- 📋 Table 3: tblActivityLog
-- ==============================================================================
-- General application activity log for tracking user actions, system events,
-- and operational metrics. Not limited to errors - logs normal operations too.
--
-- @see https://dev.mysql.com/doc/refman/8.0/en/json.html JSON column type
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblActivityLog` (
    `activityID`      BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT  COMMENT '🔑 Primary key - auto-incrementing activity log identifier',
    `activityType`    VARCHAR(100)      NOT NULL                 COMMENT '📌 Activity type (qr_generated, qr_downloaded, api_call, setting_changed, login, etc.)',
    `activityDetail`  TEXT              NULL                     COMMENT '📋 Detailed description of the activity',
    `entityType`      VARCHAR(50)       NULL                     COMMENT '🏷️ Related entity type (qr_code, setting, api_key, user, etc.)',
    `entityID`        BIGINT UNSIGNED   NULL                     COMMENT '🔗 ID of the related entity',
    `userID`          INT UNSIGNED      NULL                     COMMENT '👤 User who performed the action (NULL for system/anonymous)',
    `sessionID`       VARCHAR(128)      NULL                     COMMENT '🎫 PHP session ID',
    `requestIP`       VARCHAR(45)       NULL                     COMMENT '🌍 Client IP address (IPv4/IPv6)',
    `userAgent`       VARCHAR(500)      NULL                     COMMENT '🖥️ Client user agent string',
    `requestURL`      VARCHAR(2048)     NULL                     COMMENT '🌐 Request URL',
    `additionalData`  JSON              NULL                     COMMENT '📦 Additional context as JSON',
    `createdAt`       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Activity timestamp',

    PRIMARY KEY (`activityID`),
    INDEX `idx_activity_type` (`activityType`),
    INDEX `idx_activity_created` (`createdAt`),
    INDEX `idx_activity_entity` (`entityType`, `entityID`),
    INDEX `idx_activity_user` (`userID`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='📊 General application activity log for operational tracking and auditing';

-- ==============================================================================
-- 📋 Table 4: tblQRCodeTypes
-- ==============================================================================
-- Defines all supported QR code content types and their input field schemas.
-- The formFieldsJSON column stores a JSON array that describes which form
-- fields the web UI should render for each QR type, enabling new types to
-- be added purely via database without any PHP/HTML code changes.
--
-- The payloadTemplate column contains the QR payload format string with
-- {placeholders} that are replaced with user input values at generation time.
--
-- @see https://dev.mysql.com/doc/refman/8.0/en/json.html JSON column type
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblQRCodeTypes` (
    `typeID`            INT UNSIGNED    NOT NULL AUTO_INCREMENT  COMMENT '🔑 Primary key - auto-incrementing type identifier',
    `typeName`          VARCHAR(100)    NOT NULL                 COMMENT '🏷️ Machine-readable type name (url, text, email, wifi, etc.)',
    `typeLabel`         VARCHAR(100)    NOT NULL                 COMMENT '📝 Human-readable display label',
    `typeIcon`          VARCHAR(100)    NULL                     COMMENT '🎨 FontAwesome icon class (e.g., fa-solid fa-link)',
    `typeDescription`   VARCHAR(500)    NULL                     COMMENT '📖 Brief description of this QR code type',
    `typeCategory`      VARCHAR(50)     NOT NULL DEFAULT 'general' COMMENT '📂 Category grouping (general, social, payment, media, contact, utility, barcode)',
    `payloadTemplate`   TEXT            NULL                     COMMENT '📋 Payload format template with {placeholder} markers',
    `formFieldsJSON`    JSON            NOT NULL                 COMMENT '📝 JSON schema defining input form fields for this type',
    `isActive`          TINYINT(1)      NOT NULL DEFAULT 1       COMMENT '✅ 1 = type is available for use, 0 = disabled',
    `sortOrder`         INT             NOT NULL DEFAULT 0       COMMENT '📊 Display order in the UI (lower = first)',
    `createdAt`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Record creation timestamp',
    `updatedAt`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '📅 Last modification timestamp',

    PRIMARY KEY (`typeID`),
    UNIQUE KEY `idx_type_name` (`typeName`),
    INDEX `idx_type_category` (`typeCategory`),
    INDEX `idx_type_active` (`isActive`, `sortOrder`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='📱 QR code type definitions with dynamic form field schemas';

-- ==============================================================================
-- 📋 Table 5: tblQRCodes
-- ==============================================================================
-- Stores records of all generated QR codes including the payload data,
-- customisation options (as a JSON snapshot), output format, file location,
-- and ownership information. Supports both anonymous and authenticated generation.
--
-- Future-proofed with userID, orgID, apiKeyID, and shortLinkID columns
-- for Phase 2+ features (user accounts, organisations, Go2My.Link integration).
--
-- @see https://dev.mysql.com/doc/refman/8.0/en/miscellaneous-functions.html UUID()
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblQRCodes` (
    `qrID`            BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT  COMMENT '🔑 Primary key - auto-incrementing QR code identifier',
    `qrUUID`          CHAR(36)          NOT NULL                 COMMENT '🆔 UUID v4 for public-facing reference (used in download URLs)',
    `qrTypeID`        INT UNSIGNED      NOT NULL                 COMMENT '🔗 FK to tblQRCodeTypes - the type of QR code content',
    `qrPayload`       TEXT              NOT NULL                 COMMENT '📋 Raw data string encoded in the QR code',
    `qrInputData`     JSON              NULL                     COMMENT '📝 Original form field values before payload generation',

    -- 🎨 Customisation settings (snapshot at generation time)
    `qrOptions`       JSON              NOT NULL                 COMMENT '🎨 Complete customisation options as JSON (colours, patterns, size, ECC, etc.)',
    `qrLogoPath`      VARCHAR(500)      NULL                     COMMENT '🖼️ Path to logo file if a centre logo was used',
    `qrFrameStyle`    VARCHAR(50)       NULL                     COMMENT '🖼️ Frame style identifier (none, simple, rounded, banner-bottom, etc.)',
    `qrFrameLabel`    VARCHAR(200)      NULL                     COMMENT '🏷️ Frame label text (e.g., "SCAN ME")',
    `qrFrameFont`     VARCHAR(100)      NULL                     COMMENT '🔤 Frame label font family name',

    -- 📤 Output information
    `qrOutputFormat`  VARCHAR(10)       NOT NULL DEFAULT 'png'   COMMENT '📄 Output format (png, jpg, svg, webp, gif, pdf, jxl)',
    `qrFileSize`      INT UNSIGNED      NULL                     COMMENT '📏 Generated file size in bytes',
    `qrFilePath`      VARCHAR(500)      NULL                     COMMENT '📂 Server file path to cached/stored QR code image',
    `qrWidth`         INT UNSIGNED      NULL                     COMMENT '📐 Output width in pixels (or SVG viewBox width)',
    `qrHeight`        INT UNSIGNED      NULL                     COMMENT '📐 Output height in pixels (or SVG viewBox height)',

    -- 👤 Ownership (future-proofed for Phase 2+)
    `userID`          INT UNSIGNED      NULL                     COMMENT '👤 Owning user ID (NULL = anonymous/guest)',
    `orgID`           INT UNSIGNED      NULL                     COMMENT '🏢 Owning organisation ID (Phase 2)',
    `apiKeyID`        INT UNSIGNED      NULL                     COMMENT '🔑 API key used for generation (NULL = web UI)',

    -- 📚 Saved-codes library (Epic E3 stage 2a, #52/#24, B-001/B-002) - once a
    -- code is claimed by a user via POST /api/v1/save, `qrLabel` is the
    -- caller's own nickname for it (editable via PATCH /api/v1/qrcodes/{uuid});
    -- `deletedAt` is the soft-delete marker set by DELETE /api/v1/qrcodes/{uuid}
    -- (see DEV_NOTES.md/SECURITY.md for the soft-vs-hard-delete decision) -
    -- every saved-codes query (list/get/patch/delete/save-limit-count) filters
    -- `deletedAt IS NULL`, so a deleted row both disappears from the caller's
    -- library AND frees a saved-codes slot without losing the audit trail.
    `qrLabel`         VARCHAR(150)      NULL                     COMMENT '🏷️ Caller-supplied nickname for a saved code (NULL = unlabelled)',
    `deletedAt`       DATETIME          NULL                     COMMENT '🗑️ Soft-delete timestamp (NULL = active); set by DELETE /api/v1/qrcodes/{uuid}',

    -- 📊 Metadata
    `isPublic`        TINYINT(1)        NOT NULL DEFAULT 0       COMMENT '🌐 1 = readable/downloadable by ANY caller regardless of ownership (default 0 = private to the owner - Epic E3 stage 2a)',
    `downloadCount`   INT UNSIGNED      NOT NULL DEFAULT 0       COMMENT '📥 Number of times this QR code has been downloaded',
    `requestIP`       VARCHAR(45)       NULL                     COMMENT '🌍 IP address of the request that generated this code',
    `userAgent`       VARCHAR(500)      NULL                     COMMENT '🖥️ User agent of the generating request',
    `shortLinkID`     BIGINT UNSIGNED   NULL                     COMMENT '🔗 Go2My.Link short link ID (future integration)',
    `createdAt`       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Generation timestamp',
    `updatedAt`       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '📅 Last modification timestamp',
    `expiresAt`       DATETIME          NULL                     COMMENT '⏰ Optional expiration date (NULL = never expires)',

    PRIMARY KEY (`qrID`),
    UNIQUE KEY `idx_qr_uuid` (`qrUUID`),
    INDEX `idx_qr_type` (`qrTypeID`),
    INDEX `idx_qr_user` (`userID`),
    INDEX `idx_qr_user_active` (`userID`, `deletedAt`),
    INDEX `idx_qr_org` (`orgID`),
    INDEX `idx_qr_created` (`createdAt`),
    INDEX `idx_qr_format` (`qrOutputFormat`),

    CONSTRAINT `fk_qr_type` FOREIGN KEY (`qrTypeID`)
        REFERENCES `tblQRCodeTypes` (`typeID`)
        ON DELETE RESTRICT
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='📱 Generated QR code records with full customisation snapshot and metadata';

-- ==============================================================================
-- 📋 Table 6: tblPredefinedLogos
-- ==============================================================================
-- Library of predefined logo icons available for centre logo placement.
-- Users can select from these when customising their QR codes.
-- The actual logo files are stored on the filesystem in assets/img/logos/.
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblPredefinedLogos` (
    `logoID`        INT UNSIGNED      NOT NULL AUTO_INCREMENT  COMMENT '🔑 Primary key - auto-incrementing logo identifier',
    `logoName`      VARCHAR(100)      NOT NULL                 COMMENT '🏷️ Machine-readable name (facebook, youtube, whatsapp, etc.)',
    `logoLabel`     VARCHAR(100)      NOT NULL                 COMMENT '📝 Human-readable display label',
    `logoFileName`  VARCHAR(255)      NOT NULL                 COMMENT '📄 Filename in the predefined logos directory (e.g., facebook.svg)',
    `logoCategory`  VARCHAR(50)       NOT NULL DEFAULT 'social' COMMENT '📂 Category (social, payment, messaging, business, utility, other)',
    `isActive`      TINYINT(1)        NOT NULL DEFAULT 1       COMMENT '✅ 1 = available for selection, 0 = hidden',
    `sortOrder`     INT               NOT NULL DEFAULT 0       COMMENT '📊 Display order in the logo grid',
    `createdAt`     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Record creation timestamp',

    PRIMARY KEY (`logoID`),
    UNIQUE KEY `idx_logo_name` (`logoName`),
    INDEX `idx_logo_category` (`logoCategory`),
    INDEX `idx_logo_active` (`isActive`, `sortOrder`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='🖼️ Predefined logo icon library for QR code centre logo customisation';

-- ==============================================================================
-- 📋 Table 7: tblAPIKeys
-- ==============================================================================
-- Manages API keys for authenticated access to the REST API.
-- Keys are stored as SHA-256 hashes (never in plaintext after initial display).
-- Each key has configurable rate limits and permission scopes.
--
-- @see https://www.php.net/manual/en/function.hash-hmac.php
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblAPIKeys` (
    `apiKeyID`          INT UNSIGNED    NOT NULL AUTO_INCREMENT  COMMENT '🔑 Primary key - auto-incrementing API key identifier',
    `apiKeyHash`        VARCHAR(64)     NOT NULL                 COMMENT '🔐 SHA-256 hash of the API key (never store plaintext)',
    `apiKeyPrefix`      VARCHAR(12)     NOT NULL                 COMMENT '🏷️ First 12 chars of key for identification (e.g., cuercode_abc12345)',
    `apiKeyName`        VARCHAR(100)    NULL                     COMMENT '📝 User-friendly label/name for this key',
    `userID`            INT UNSIGNED    NULL                     COMMENT '👤 Owning user ID (Phase 2 - FK to tblUsers)',
    `orgID`             INT UNSIGNED    NULL                     COMMENT '🏢 Owning organisation ID (Phase 2 - FK to tblOrganisations)',
    `permissions`       JSON            NOT NULL DEFAULT (JSON_ARRAY('generate')) COMMENT '🔒 Array of allowed operations (generate, download, manage, admin)',
    `rateLimitPerMin`   INT UNSIGNED    NOT NULL DEFAULT 60      COMMENT '⏱️ Maximum API requests per minute',
    `rateLimitPerDay`   INT UNSIGNED    NOT NULL DEFAULT 1000    COMMENT '⏱️ Maximum API requests per day',
    `isActive`          TINYINT(1)      NOT NULL DEFAULT 1       COMMENT '✅ 1 = key is active, 0 = revoked',
    `lastUsedAt`        DATETIME        NULL                     COMMENT '📅 Last time this key was used',
    `requestCount`      BIGINT UNSIGNED NOT NULL DEFAULT 0       COMMENT '📊 Total number of requests made with this key',
    `createdAt`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Key creation timestamp',
    `updatedAt`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '📅 Last modification timestamp',
    `expiresAt`         DATETIME        NULL                     COMMENT '⏰ Optional key expiration date (NULL = never expires)',

    PRIMARY KEY (`apiKeyID`),
    UNIQUE KEY `idx_api_key_hash` (`apiKeyHash`),
    INDEX `idx_api_prefix` (`apiKeyPrefix`),
    INDEX `idx_api_user` (`userID`),
    INDEX `idx_api_active` (`isActive`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='🔑 API key management with rate limits and permission scopes';

-- ==============================================================================
-- 📋 Table 8: tblAPIRateLog
-- ==============================================================================
-- Tracks individual API requests for rate limiting enforcement.
-- Records are checked against tblAPIKeys rate limits on each request.
-- Old entries should be periodically cleaned up (e.g., records older than 24h).
--
-- @see https://dev.mysql.com/doc/refman/8.0/en/innodb-locking.html
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblAPIRateLog` (
    `rateID`        BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT  COMMENT '🔑 Primary key - auto-incrementing rate log identifier',
    `apiKeyID`      INT UNSIGNED      NULL                     COMMENT '🔗 FK to tblAPIKeys (NULL for anonymous/unauthenticated requests)',
    `requestIP`     VARCHAR(45)       NOT NULL                 COMMENT '🌍 Client IP address (IPv4/IPv6)',
    `endpoint`      VARCHAR(255)      NOT NULL                 COMMENT '🌐 API endpoint path (e.g., /api/v1/generate)',
    `requestMethod` VARCHAR(10)       NOT NULL DEFAULT 'GET'   COMMENT '📨 HTTP method',
    `responseCode`  SMALLINT UNSIGNED NULL                     COMMENT '📤 HTTP response code returned',
    `requestedAt`   DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Request timestamp',

    PRIMARY KEY (`rateID`),
    INDEX `idx_rate_key_time` (`apiKeyID`, `requestedAt`),
    INDEX `idx_rate_ip_time` (`requestIP`, `requestedAt`),
    INDEX `idx_rate_cleanup` (`requestedAt`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='⏱️ API request rate limiting tracking log';

-- ==============================================================================
-- 📋 Table 9: tblUsers (Epic E3 stage 1 - #11/#12/#14/#15)
-- ==============================================================================
-- User account table. Fully implemented in Epic E3 stage 1: registration,
-- email verification, Argon2id password hashing, login with lockout, and
-- password reset. Organisation membership/roles are tracked per-organisation in
-- tblOrganisationMembers; orgID/roleID here remain the home-organisation quick
-- lookup and platform-wide role respectively. SSO (Epic E2) and WebAuthn
-- (Epic E3 stage 7) identities live in their own linked tables below.
--
-- @see https://www.php.net/manual/en/function.password-hash.php
-- @see _functions/fn_password.php   - hashPassword()/verifyPassword() (Argon2id)
-- @see _functions/fn_auth.php       - registerUser()/loginUser()/... (the auth engine)
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblUsers` (
    `userID`            INT UNSIGNED      NOT NULL AUTO_INCREMENT  COMMENT '🔑 Primary key - auto-incrementing user identifier',
    `email`             VARCHAR(255)      NOT NULL                 COMMENT '📧 User email address (unique, used for login/verification/reset - normalised lowercase before storage)',
    `emailVerifiedAt`   DATETIME          NULL                     COMMENT '✅ Timestamp the email address was verified (NULL = not yet verified)',
    `passwordHash`      VARCHAR(255)      NULL                     COMMENT '🔐 Password hash (Argon2id via password_hash(..., PASSWORD_ARGON2ID)) - NULL only for a future SSO-only account (Epic E2) with no local password set',
    `displayName`       VARCHAR(100)      NULL                     COMMENT '📝 User display name',
    `firstName`         VARCHAR(100)      NULL                     COMMENT '📝 User first name',
    `lastName`          VARCHAR(100)      NULL                     COMMENT '📝 User last name',
    `avatarPath`        VARCHAR(500)      NULL                     COMMENT '🖼️ Path to user avatar image',
    `orgID`             INT UNSIGNED      NULL                     COMMENT '🏢 Home/primary organisation quick-lookup (fine-grained multi-organisation membership + per-org role lives in tblOrganisationMembers) - no FK here so tblUsers can be created before tblOrganisations in a fresh install run',
    `roleID`            INT UNSIGNED      NULL                     COMMENT '🎭 Platform-wide role (1=Global Admin, 2=Admin, 3=Regular User) - distinct from the per-organisation role in tblOrganisationMembers.roleID',
    `status`            ENUM(
                            'active',
                            'suspended',
                            'pending',
                            'deleted'
                        )                 NOT NULL DEFAULT 'pending' COMMENT '📊 Account lifecycle status - pending until email verified, active thereafter, suspended by an administrator, or deleted via GDPR/CCPA self-service erasure (#37/#52 - see erasedAt below; a "deleted" account is ANONYMIZED in place, never a removed row, so referential integrity with tblSubscriptions/tblPayments/tblActivityLog survives)',
    `failedLoginCount`  SMALLINT UNSIGNED NOT NULL DEFAULT 0       COMMENT '🔢 Consecutive failed login attempts since the last success - drives the lockedUntil lockout below',
    `lockedUntil`       DATETIME          NULL                     COMMENT '🔒 Account locked until this timestamp (NULL = not locked) - set once failedLoginCount reaches the security.max_login_attempts setting',
    `lastLoginAt`       DATETIME          NULL                     COMMENT '📅 Last successful login timestamp',
    `erasedAt`          DATETIME          NULL                     COMMENT '🗑️ GDPR/CCPA self-service erasure timestamp (#37/#52 - POST /api/v1/account/erase) - NULL unless status=''deleted''. Set the moment PII (email/displayName/firstName/lastName/avatarPath/passwordHash) was anonymized; the row itself is kept (not hard-deleted) so tblSubscriptions/tblPayments/tblActivityLog retain a valid, referentially-intact FK for legal/financial audit retention. See DEV_NOTES.md "Account erasure: anonymize now, hard-purge later" for the owner-run hard-purge runbook that permanently removes rows past the retention window.',
    `createdAt`         DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Account creation timestamp',
    `updatedAt`         DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '📅 Last modification timestamp',

    PRIMARY KEY (`userID`),
    UNIQUE KEY `idx_user_email` (`email`),
    INDEX `idx_user_org` (`orgID`),
    INDEX `idx_user_status` (`status`),
    INDEX `idx_user_locked` (`lockedUntil`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='👤 User accounts - Epic E3 stage 1 (registration, verification, login, sessions, password reset)';

-- ==============================================================================
-- 📋 Table 10: tblOrganisations (STUB - Phase 2)
-- ==============================================================================
-- Organisation/company account table - created as a schema stub in Phase 1.
-- Will be fully implemented in Phase 2 with organisation management,
-- multi-user access, domain association, and subscription tiers.
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblOrganisations` (
    `orgID`           INT UNSIGNED    NOT NULL AUTO_INCREMENT  COMMENT '🔑 Primary key - auto-incrementing organisation identifier',
    `orgName`         VARCHAR(255)    NOT NULL                 COMMENT '📝 Organisation display name',
    `orgSlug`         VARCHAR(100)    NOT NULL                 COMMENT '🏷️ URL-safe slug (unique identifier)',
    `orgDomain`       VARCHAR(255)    NULL                     COMMENT '🌐 Primary domain name',
    `subscriptionTier` VARCHAR(50)    NOT NULL DEFAULT 'free'  COMMENT '💳 Subscription tier (free, basic, premium, enterprise)',
    `isActive`        TINYINT(1)      NOT NULL DEFAULT 1       COMMENT '✅ 1 = organisation active, 0 = suspended',
    `createdAt`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Organisation creation timestamp',
    `updatedAt`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '📅 Last modification timestamp',

    PRIMARY KEY (`orgID`),
    UNIQUE KEY `idx_org_slug` (`orgSlug`),
    INDEX `idx_org_active` (`isActive`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='🏢 Organisation accounts (Phase 2 stub - schema only in Phase 1)';

-- ==============================================================================
-- 📋 Table 11: tblEmailQueue (Epic E8, #29)
-- ==============================================================================
-- Outbound transactional email queue with retry/backoff support. Emails are
-- NEVER sent synchronously inline with a user-facing request beyond the
-- opportunistic ≤2-per-request shutdown hook (see fn_email.php
-- maybeProcessEmailQueueOnShutdown() - gated OFF by default via the
-- email_queue_autoprocess setting; a Dreamhost panel cron job hitting a
-- small processor script is the recommended production path).
--
-- Rows are inserted by queueEmail() (fn_email.php) and consumed by
-- processEmailQueue(), which claims due rows (status queued/failed AND
-- nextAttemptAt <= NOW() AND attempts < maxAttempts), attempts a Microsoft
-- Graph sendMail call, then transitions status:
--   queued  -> sending -> sent              (success)
--   queued  -> sending -> queued (backoff)   (failure, attempts < maxAttempts)
--   queued  -> sending -> failed             (failure, attempts reaches maxAttempts)
--
-- Backoff schedule: nextAttemptAt = NOW() + (2 ^ attempts) MINUTES, computed by
-- the pure/unit-tested computeEmailBackoffMinutes() helper in fn_email.php.
--
-- @see fn_email.php - queueEmail(), processEmailQueue(), computeEmailBackoffMinutes()
-- @see https://dev.mysql.com/doc/refman/8.0/en/enum.html ENUM type
-- @see https://dev.mysql.com/doc/refman/8.0/en/json.html JSON column type
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblEmailQueue` (
    `emailID`         BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT  COMMENT '🔑 Primary key - auto-incrementing email queue identifier',
    `toAddress`       VARCHAR(320)      NOT NULL                 COMMENT '📧 Recipient email address (RFC 5321 max length 320)',
    `toName`          VARCHAR(255)      NULL                     COMMENT '📝 Recipient display name (optional)',
    `templateKey`     VARCHAR(64)       NOT NULL                 COMMENT '🏷️ Template identifier (e.g., verification, password_reset, invitation, receipt) - must match an allowlisted _includes/email_templates/{key}.php file',
    `paramsJSON`      JSON              NULL                     COMMENT '📦 Template parameters as JSON (rendered fresh at send time; NEVER stores rendered HTML or secrets)',
    `subject`         VARCHAR(255)      NOT NULL                 COMMENT '📝 Email subject line (rendered at queue time for admin visibility)',
    `status`          ENUM(
                          'queued',
                          'sending',
                          'sent',
                          'failed',
                          'skipped'
                      )                 NOT NULL DEFAULT 'queued' COMMENT '📊 Current lifecycle status of this queued email',
    `attempts`        INT UNSIGNED      NOT NULL DEFAULT 0       COMMENT '🔁 Number of send attempts made so far',
    `maxAttempts`     INT UNSIGNED      NOT NULL DEFAULT 5       COMMENT '🔁 Maximum send attempts before permanently marking failed',
    `lastError`       TEXT              NULL                     COMMENT '⚠️ Last error message (HTTP status/Graph error code only - never logs credentials or full response bodies)',
    `nextAttemptAt`   DATETIME          NULL                     COMMENT '⏰ Earliest time this row is eligible to be picked up again (exponential backoff after a failure)',
    `createdAt`       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Row creation (queue) timestamp',
    `updatedAt`       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '📅 Last modification timestamp',
    `sentAt`          DATETIME          NULL                     COMMENT '📅 Timestamp the email was successfully accepted by Microsoft Graph (HTTP 202)',

    PRIMARY KEY (`emailID`),
    INDEX `idx_email_status_next` (`status`, `nextAttemptAt`),
    INDEX `idx_email_template` (`templateKey`),
    INDEX `idx_email_created` (`createdAt`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='📧 Outbound transactional email queue with retry/backoff (Microsoft Graph sendMail, Epic E8)';

-- ==============================================================================
-- 📋 Table 12: tblUserSessions (Epic E3 stage 1 - #14)
-- ==============================================================================
-- Database-backed authenticated user sessions, independent of the PHP session
-- store. A random 64-hex-character token is issued on successful login
-- (fn_auth.php issueUserSession()), stored server-side only, and referenced
-- from the PHP session ($_SESSION['cuercode_user_session_id']). Keeping this
-- as its own table (rather than relying solely on PHP native sessions) allows
-- targeted revocation (logout, password reset, a future "sign out this
-- device" UI) without depending on the PHP session storage backend.
--
-- @see _functions/fn_auth.php - issueUserSession(), currentUser(), logoutCurrentUser()
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblUserSessions` (
    `sessionID`   CHAR(64)        NOT NULL                 COMMENT '🔑 Primary key - random 64-char hex session token (bin2hex(random_bytes(32)))',
    `userID`      INT UNSIGNED    NOT NULL                 COMMENT '👤 FK to tblUsers - the authenticated user this session belongs to',
    `ipAddress`   VARCHAR(45)     NULL                     COMMENT '🌍 Client IP address at session creation (IPv4/IPv6)',
    `userAgent`   VARCHAR(500)    NULL                     COMMENT '🖥️ Client user agent string at session creation',
    `createdAt`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Session creation (login) timestamp',
    `lastSeenAt`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Last time this session authenticated a request (opportunistically bumped by currentUser())',
    `expiresAt`   DATETIME        NOT NULL                 COMMENT '⏰ Absolute session expiry timestamp (createdAt + security.session_lifetime)',
    `revokedAt`   DATETIME        NULL                     COMMENT '🚫 Explicit revocation timestamp (logout, password reset, or a future admin action) - NULL = not revoked',

    PRIMARY KEY (`sessionID`),
    INDEX `idx_session_user` (`userID`),
    INDEX `idx_session_expiry` (`expiresAt`),
    INDEX `idx_session_revoked` (`revokedAt`),

    CONSTRAINT `fk_session_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='🎫 Authenticated user sessions, database-backed and independently revocable - Epic E3 stage 1';

-- ==============================================================================
-- 📋 Table 13: tblPasswordResets (Epic E3 stage 1 - #15)
-- ==============================================================================
-- Single-use, expiring password reset tokens. Only the SHA-256 hash of the
-- random raw token is ever stored - the raw token is emailed once (via the
-- password_reset template) and never persisted. requestPasswordReset()
-- inserts a row; resetPasswordWithToken() atomically claims it (usedAt IS
-- NULL guard) before applying the new password.
--
-- @see _functions/fn_auth.php - requestPasswordReset(), resetPasswordWithToken()
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblPasswordResets` (
    `resetID`    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT  COMMENT '🔑 Primary key - auto-incrementing reset request identifier',
    `userID`     INT UNSIGNED    NOT NULL                 COMMENT '👤 FK to tblUsers - the account this reset token applies to',
    `tokenHash`  CHAR(64)        NOT NULL                 COMMENT '🔐 SHA-256 hash of the random reset token (the raw token itself is never stored)',
    `expiresAt`  DATETIME        NOT NULL                 COMMENT '⏰ Token expiry timestamp (short-lived, default 60 minutes)',
    `usedAt`     DATETIME        NULL                     COMMENT '✅ Timestamp the token was consumed (NULL = still unused) - enforces single-use',
    `requestIP`  VARCHAR(45)     NULL                     COMMENT '🌍 IP address that requested this reset (audit trail)',
    `createdAt`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Token creation timestamp',

    PRIMARY KEY (`resetID`),
    UNIQUE KEY `idx_reset_token_hash` (`tokenHash`),
    INDEX `idx_reset_user` (`userID`),
    INDEX `idx_reset_expiry` (`expiresAt`),

    CONSTRAINT `fk_reset_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='🔑 Single-use, expiring password reset tokens (hashed at rest) - Epic E3 stage 1';

-- ==============================================================================
-- 📋 Table 14: tblEmailVerifications (Epic E3 stage 1 - #12)
-- ==============================================================================
-- Single-use, expiring email verification tokens issued at registration.
-- Only the SHA-256 hash of the random raw token is stored; the raw token is
-- emailed once (via the verification template). verifyEmailToken() atomically
-- claims the row (usedAt IS NULL guard) then flips the owning user from
-- pending to active.
--
-- @see _functions/fn_auth.php - registerUser(), verifyEmailToken()
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblEmailVerifications` (
    `verificationID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT  COMMENT '🔑 Primary key - auto-incrementing verification request identifier',
    `userID`         INT UNSIGNED    NOT NULL                 COMMENT '👤 FK to tblUsers - the account this verification token applies to',
    `tokenHash`      CHAR(64)        NOT NULL                 COMMENT '🔐 SHA-256 hash of the random verification token (the raw token itself is never stored)',
    `expiresAt`      DATETIME        NOT NULL                 COMMENT '⏰ Token expiry timestamp (default 24 hours)',
    `usedAt`         DATETIME        NULL                     COMMENT '✅ Timestamp the token was consumed (NULL = still unused) - enforces single-use',
    `createdAt`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Token creation timestamp',

    PRIMARY KEY (`verificationID`),
    UNIQUE KEY `idx_verification_token_hash` (`tokenHash`),
    INDEX `idx_verification_user` (`userID`),
    INDEX `idx_verification_expiry` (`expiresAt`),

    CONSTRAINT `fk_verification_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='📧 Single-use, expiring email verification tokens (hashed at rest) - Epic E3 stage 1';

-- ==============================================================================
-- 📋 Table 15: tblUserOAuth (Epic E2 - #55/#19/#20/#28 - client code BUILT)
-- ==============================================================================
-- Linked external SSO/OAuth identities per local user account. This schema
-- was created in Epic E3 stage 1 (the whole trust-boundary schema shipped
-- together) and is now BACKED by a real OIDC authorization-code+PKCE client
-- (_functions/fn_oauth.php, Epic E2, #55/#19/#20/#28) - Microsoft 365 and
-- Google are fully wired; 'signula' remains present in the ENUM below but
-- stays structurally INERT (its seeded discovery URL is empty) pending
-- upstream OIDC support in the SIGNula.id repo (issue #88).
-- Every SSO identity MUST link to a local tblUsers account (ProjectBrief section 5.3),
-- never stand alone - see fn_oauth.php's decideOAuthAccountLinkAction() for
-- the conservative (anti-account-takeover) linking decision table.
--
-- @see PROJECT_ROADMAP.md - Epic E2 (SIGNula.id SSO sign-in)
-- @see _functions/fn_oauth.php - the OAuth2/OIDC client engine
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblUserOAuth` (
    `oauthID`            INT UNSIGNED  NOT NULL AUTO_INCREMENT  COMMENT '🔑 Primary key - auto-incrementing linked-identity identifier',
    `userID`             INT UNSIGNED  NOT NULL                 COMMENT '👤 FK to tblUsers - the local account this external identity is linked to',
    `provider`           ENUM(
                             'signula',
                             'microsoft',
                             'google',
                             'apple',
                             'facebook'
                         )             NOT NULL                 COMMENT '🌐 SSO/OAuth provider identifier (Epic E2)',
    `providerSubjectID`  VARCHAR(255)  NOT NULL                 COMMENT '🆔 The stable subject/user identifier issued by the provider (e.g. an OIDC "sub" claim)',
    `tokensEncrypted`    TEXT          NULL                     COMMENT '🔐 AES-256-GCM-encrypted provider token bundle (access/refresh) via encryptValue() - populated once Epic E2 ships',
    `linkedAt`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Timestamp this identity was linked to the local account',

    PRIMARY KEY (`oauthID`),
    UNIQUE KEY `idx_oauth_provider_subject` (`provider`, `providerSubjectID`),
    UNIQUE KEY `idx_oauth_user_provider` (`userID`, `provider`),
    INDEX `idx_oauth_user` (`userID`),

    CONSTRAINT `fk_oauth_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='🔗 Linked external SSO/OAuth identities per user (Epic E2, #55/#19/#20/#28 - Microsoft/Google client code BUILT; signula inert pending upstream)';

-- ==============================================================================
-- 📋 Table 16: tblWebAuthnCredentials (G-006 schema stub - not implemented until E3 stage 7)
-- ==============================================================================
-- WebAuthn/passkey credentials. Schema-only this stage - no registration or
-- assertion verification code exists yet; that is Epic E3 stage 7, gated on
-- G-006 (self-hosted, no-Composer FIDO2 implementation evaluation).
--
-- @see PROJECT_ROADMAP.md - Epic E3 stage 7 (WebAuthn/passkeys)
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblWebAuthnCredentials` (
    `webauthnID`    INT UNSIGNED    NOT NULL AUTO_INCREMENT  COMMENT '🔑 Primary key - auto-incrementing credential identifier',
    `userID`        INT UNSIGNED    NOT NULL                 COMMENT '👤 FK to tblUsers - the owning account',
    `credentialID`  VARCHAR(255)    NOT NULL                 COMMENT '🆔 Base64url-encoded WebAuthn credential ID (unique per registered authenticator)',
    `publicKey`     TEXT            NOT NULL                 COMMENT '🔐 Base64-encoded COSE public key bytes, used to verify future assertions',
    `signCount`     BIGINT UNSIGNED NOT NULL DEFAULT 0       COMMENT '🔢 Authenticator signature counter, used for cloned-authenticator detection',
    `transports`    VARCHAR(255)    NULL                     COMMENT '🔌 Comma-separated transport hints (usb, nfc, ble, internal)',
    `nickname`      VARCHAR(100)    NULL                     COMMENT '📝 User-assigned label for this passkey/security key',
    `createdAt`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Registration timestamp',
    `lastUsedAt`    DATETIME        NULL                     COMMENT '📅 Last time this credential was used to authenticate',

    PRIMARY KEY (`webauthnID`),
    UNIQUE KEY `idx_webauthn_credential_id` (`credentialID`),
    INDEX `idx_webauthn_user` (`userID`),

    CONSTRAINT `fk_webauthn_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='🔐 WebAuthn/passkey credentials (G-006 schema stub - not implemented until Epic E3 stage 7)';

-- ==============================================================================
-- 📋 Table 17: tblOrganisationMembers (Epic E3 org stage schema stub)
-- ==============================================================================
-- Organisation membership and per-organisation role assignment. Schema-only
-- this stage - creation UI, role management, and enforcement are Epic E3's
-- second (organisations) stage.
--
-- @see PROJECT_ROADMAP.md - Epic E3 stage 6 (Organisations - second stage)
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblOrganisationMembers` (
    `orgMemberID`  INT UNSIGNED  NOT NULL AUTO_INCREMENT  COMMENT '🔑 Primary key - auto-incrementing membership identifier',
    `orgID`        INT UNSIGNED  NOT NULL                 COMMENT '🏢 FK to tblOrganisations',
    `userID`       INT UNSIGNED  NOT NULL                 COMMENT '👤 FK to tblUsers',
    `roleID`       INT UNSIGNED  NOT NULL DEFAULT 3       COMMENT '🎭 Organisation-scoped role (1=Global Admin, 2=Admin, 3=Regular User per the project role model)',
    `joinedAt`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Timestamp this user joined the organisation',

    PRIMARY KEY (`orgMemberID`),
    UNIQUE KEY `idx_org_member_unique` (`orgID`, `userID`),
    INDEX `idx_org_member_user` (`userID`),

    CONSTRAINT `fk_org_member_org` FOREIGN KEY (`orgID`)
        REFERENCES `tblOrganisations` (`orgID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_org_member_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='🏢 Organisation membership and per-organisation role (schema stub - org UI/logic deferred to a later E3 stage)';

-- ==============================================================================
-- 📋 Table 18: tblInvitations (Epic E3 org stage schema stub)
-- ==============================================================================
-- Pending/accepted organisation invitations, tokenised the same way as
-- password reset / email verification (hashed at rest, single use). Schema-
-- only this stage - sending/accepting invitations needs E8 email (already
-- available) and the organisations UI, which is a later E3 stage.
--
-- @see PROJECT_ROADMAP.md - Epic E3 stage 6 (Organisations - second stage)
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblInvitations` (
    `invitationID`     INT UNSIGNED  NOT NULL AUTO_INCREMENT  COMMENT '🔑 Primary key - auto-incrementing invitation identifier',
    `orgID`            INT UNSIGNED  NOT NULL                 COMMENT '🏢 FK to tblOrganisations - the organisation being invited into',
    `email`            VARCHAR(255)  NOT NULL                 COMMENT '📧 Invited email address (need not yet have a tblUsers row)',
    `tokenHash`        CHAR(64)      NOT NULL                 COMMENT '🔐 SHA-256 hash of the random invitation token (the raw token itself is never stored)',
    `roleID`           INT UNSIGNED  NOT NULL DEFAULT 3       COMMENT '🎭 Role the invitee will receive on acceptance',
    `invitedByUserID`  INT UNSIGNED  NULL                     COMMENT '👤 FK to tblUsers - who sent the invitation (NULL if the inviting account is later deleted)',
    `expiresAt`        DATETIME      NOT NULL                 COMMENT '⏰ Invitation expiry timestamp',
    `acceptedAt`       DATETIME      NULL                     COMMENT '✅ Timestamp the invitation was accepted (NULL = pending)',
    `createdAt`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Invitation creation timestamp',

    PRIMARY KEY (`invitationID`),
    UNIQUE KEY `idx_invitation_token_hash` (`tokenHash`),
    INDEX `idx_invitation_org` (`orgID`),
    INDEX `idx_invitation_email` (`email`),
    INDEX `idx_invitation_expiry` (`expiresAt`),

    CONSTRAINT `fk_invitation_org` FOREIGN KEY (`orgID`)
        REFERENCES `tblOrganisations` (`orgID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_invitation_inviter` FOREIGN KEY (`invitedByUserID`)
        REFERENCES `tblUsers` (`userID`)
        ON DELETE SET NULL
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='✉️ Pending/accepted organisation invitations (schema stub - org UI/logic deferred to a later E3 stage)';

-- ==============================================================================
-- 📋 Table 19: tblSubscriptionPlans (Epic E4 - #25/#26/#53, PROJECT_ROADMAP.md
--    "Premium Tier Design")
-- ==============================================================================
-- 💳 THE SINGLE SOURCE OF TRUTH for every premium tier CueRCode offers. This
-- table is deliberately DATA-DRIVEN so that adding a brand-new tier, moving a
-- feature between tiers, or changing a price point NEVER requires a code
-- deploy - only an INSERT/UPDATE against this table (via the admin plan
-- editor once it ships, or directly via SQL/phpMyAdmin today). The gating
-- engine (`_functions/fn_features.php`) reads featuresJSON/limitsJSON with
-- fully GENERIC key lookups - it contains zero per-feature/per-tier branches,
-- so a key that does not exist yet in any plan's JSON simply resolves to a
-- fail-closed default (false / 0) until an admin adds it to a plan row.
--
-- featuresJSON shape - a flat JSON object of booleans, e.g.:
--   {"expiry_scheduling": true, "password_protected_qr": true, "sso": false,
--    "organisations": false, "webhooks": true, "csv_export": true}
--
-- limitsJSON shape - a flat JSON object of integers, where -1 means
-- UNLIMITED, e.g.:
--   {"saved_codes": 500, "bulk_batch_size": 500, "dynamic_codes": 100,
--    "api_keys": 5, "analytics_days": 90, "api_rate_limit_per_min": 120,
--    "api_rate_limit_per_day": 20000}
--
-- To add a new tier: INSERT a new row with a unique planKey and its own
-- featuresJSON/limitsJSON - `fn_features.php` resolves ANY planKey found on
-- an active tblSubscriptions row with no code change whatsoever. To add a
-- new gated feature/limit to the app: just start reading it via
-- featureEnabled()/limitFor() in the new code path, then set its value on
-- whichever plan rows should have it (defaults to denied/0 everywhere it is
-- absent, so a rollout is opt-in per plan). See DEV_NOTES.md "How to add or
-- modify a premium tier" for the full walkthrough + worked SQL examples.
--
-- @see https://dev.mysql.com/doc/refman/8.0/en/json.html JSON column type
-- @see _functions/fn_features.php - planByKey(), featureEnabled(), limitFor()
-- @see seed_data.sql - the four seeded tiers (anonymous/free/pro/business)
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblSubscriptionPlans` (
    `planID`        INT UNSIGNED    NOT NULL AUTO_INCREMENT  COMMENT '🔑 Primary key - auto-incrementing plan identifier',
    `planKey`       VARCHAR(32)     NOT NULL                 COMMENT '🏷️ Machine-readable UNIQUE plan key (anonymous, free, pro, business, or any future custom tier) - this is the only value fn_features.php ever matches against, never a hardcoded name',
    `planName`      VARCHAR(100)    NOT NULL                 COMMENT '📝 Human-readable display name (e.g. "Pro")',
    `description`   VARCHAR(500)    NULL                     COMMENT '📖 Short marketing description for a future pricing/plan-selection page',
    `priceMonthly`  DECIMAL(10,2)   NOT NULL DEFAULT 0.00    COMMENT '💷 Monthly price in `currency` (0.00 for the anonymous/free tiers) - admin-editable config, not code',
    `priceYearly`   DECIMAL(10,2)   NOT NULL DEFAULT 0.00    COMMENT '💷 Yearly price in `currency` (0.00 for the anonymous/free tiers) - admin-editable config, not code',
    `currency`      CHAR(3)         NOT NULL DEFAULT 'GBP'   COMMENT '💱 ISO 4217 currency code the two price columns above are denominated in',
    `featuresJSON`  JSON            NOT NULL                 COMMENT '🚩 Flat JSON object of boolean feature flags for this tier - SINGLE SOURCE OF TRUTH read generically by fn_features.php::featureEnabled(); see this table''s header comment for the shape',
    `limitsJSON`    JSON            NOT NULL                 COMMENT '🔢 Flat JSON object of numeric limits for this tier (-1 = unlimited) - SINGLE SOURCE OF TRUTH read generically by fn_features.php::limitFor(); see this table''s header comment for the shape',
    `isActive`      TINYINT(1)      NOT NULL DEFAULT 1       COMMENT '✅ 1 = selectable/resolvable, 0 = retired (existing subscribers keep their subscription row; the plan is just hidden from new signups)',
    `sortOrder`     INT             NOT NULL DEFAULT 0       COMMENT '📊 Display order for a future pricing page / admin plan editor (E3 admin stage)',
    `createdAt`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Record creation timestamp',
    `updatedAt`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '📅 Last modification timestamp',

    PRIMARY KEY (`planID`),
    UNIQUE KEY `idx_plan_key` (`planKey`),
    INDEX `idx_plan_active_sort` (`isActive`, `sortOrder`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='💳 Extensible, data-driven subscription tier definitions (Epic E4) - plans/features/limits/prices are 100% DB config, admin-editable, zero-code-change extensible';

-- ==============================================================================
-- 📋 Table 20: tblSubscriptions (Epic E4 - #25/#26/#53)
-- ==============================================================================
-- Links a subscribing subject - EITHER an individual user OR an organisation,
-- never both - to the plan they are currently on. `fn_features.php` resolves
-- the caller's EFFECTIVE plan by looking up the single ACTIVE-ish (active /
-- trialing / past_due) row here for their userID/orgID; cancelled/expired
-- rows are historical and no longer resolve (the subject falls back to the
-- free/anonymous plan on next resolution).
--
-- Exactly one active/trialing/past_due subscription per subject (userID OR
-- orgID) is an APPLICATION-LEVEL invariant enforced by the (deferred) plan
-- change / webhook handling code, not a DB constraint - MySQL cannot express
-- "at most one row where status IN (...)" per nullable FK declaratively
-- without a generated/virtual-column workaround that would needlessly
-- complicate a table that is intentionally simple pending the E4 payments
-- stage.
--
-- @see _functions/fn_features.php - resolveActiveSubscription(), planForUser(), planForOrg()
-- @see PROJECT_ROADMAP.md - Epic E4 "Enforcement design"
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblSubscriptions` (
    `subscriptionID`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT  COMMENT '🔑 Primary key - auto-incrementing subscription identifier',
    `userID`                  INT UNSIGNED    NULL                     COMMENT '👤 FK tblUsers - the subscribing individual account (NULL when this is an org-level subscription instead)',
    `orgID`                   INT UNSIGNED    NULL                     COMMENT '🏢 FK tblOrganisations - the subscribing organisation (NULL when this is an individual subscription instead). Exactly one of userID/orgID is expected to be non-NULL per row - see this table''s header comment',
    `planID`                  INT UNSIGNED    NOT NULL                 COMMENT '🔗 FK tblSubscriptionPlans - the plan this subscription currently grants',
    `status`                  ENUM(
                                  'active',
                                  'trialing',
                                  'past_due',
                                  'cancelled',
                                  'expired'
                              )             NOT NULL DEFAULT 'active' COMMENT '📊 Lifecycle status - past_due keeps READ access to existing gated objects but the gating engine downgrades the EFFECTIVE plan for CREATING new gated objects until payment recovers (fn_features.php); cancelled/expired subscriptions simply stop resolving (subject falls back to the free/anonymous plan)',
    `currentPeriodStart`      DATETIME        NULL                     COMMENT '📅 Start of the current billing period (owner-facing display + proration math once payments ship)',
    `currentPeriodEnd`        DATETIME        NULL                     COMMENT '📅 End of the current billing period - the (deferred) PayPal webhook renews this on each successful billing-cycle payment',
    `provider`                VARCHAR(32)     NULL                     COMMENT '🏦 Payment provider identifier (paypal, ...) - NULL for a manually-granted/comp subscription with no external billing relationship',
    `providerSubscriptionRef` VARCHAR(255)    NULL                     COMMENT '🆔 The provider-side subscription/agreement identifier (e.g. a PayPal subscription ID such as I-XXXXXXXXXXXX) - used to correlate inbound webhook events back to this row',
    `createdAt`               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Subscription record creation timestamp',
    `updatedAt`               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '📅 Last modification timestamp',

    PRIMARY KEY (`subscriptionID`),
    INDEX `idx_subscription_user` (`userID`, `status`),
    INDEX `idx_subscription_org` (`orgID`, `status`),
    INDEX `idx_subscription_plan` (`planID`),
    INDEX `idx_subscription_provider_ref` (`providerSubscriptionRef`),

    CONSTRAINT `fk_subscription_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_subscription_org` FOREIGN KEY (`orgID`)
        REFERENCES `tblOrganisations` (`orgID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_subscription_plan` FOREIGN KEY (`planID`)
        REFERENCES `tblSubscriptionPlans` (`planID`)
        ON DELETE RESTRICT
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='💳 Active/historical subscriptions linking a user OR an organisation to a plan (Epic E4)';

-- ==============================================================================
-- 📋 Table 21: tblPayments (Epic E4 - #25/#26/#53, SCHEMA ONLY this stage)
-- ==============================================================================
-- Payment/transaction ledger per subscription. This table is created now so
-- the NEXT E4 stage (the PayPal Subscriptions webhook listener, owner-gated
-- on PayPal business credentials - see DEV_NOTES.md "Deferred: PayPal
-- Subscriptions webhook") has somewhere to write to on day one; nothing in
-- this codebase populates it yet.
--
-- `providerTxnRef` is UNIQUE so that a re-delivered webhook event (PayPal, or
-- any provider, routinely retries delivery until it gets a 2xx) is processed
-- IDEMPOTENTLY - a second INSERT attempt for the same providerTxnRef fails
-- the unique constraint and the webhook handler treats that as "already
-- processed, no-op" rather than double-crediting a payment.
--
-- The FK to tblSubscriptions uses ON DELETE RESTRICT (not CASCADE, unlike
-- most FKs in this file) deliberately - a payment/financial audit record
-- must never silently disappear as a side effect of deleting its parent
-- subscription row; deleting a subscription with payment history attached
-- must fail loudly and be a conscious, separate decision.
--
-- @see PROJECT_ROADMAP.md - Epic E4 "Enforcement design" point 5
-- @see DEV_NOTES.md - "Deferred: PayPal Subscriptions webhook" for the full plan
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblPayments` (
    `paymentID`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT  COMMENT '🔑 Primary key - auto-incrementing payment identifier',
    `subscriptionID`  BIGINT UNSIGNED NOT NULL                 COMMENT '🔗 FK tblSubscriptions - the subscription this payment applies to',
    `provider`        VARCHAR(32)     NOT NULL                 COMMENT '🏦 Payment provider identifier (paypal, ...)',
    `providerTxnRef`  VARCHAR(255)    NOT NULL                 COMMENT '🆔 Provider transaction/event identifier - UNIQUE so a re-delivered webhook is idempotent (a duplicate INSERT attempt simply fails and is treated as already-processed)',
    `amount`          DECIMAL(10,2)   NOT NULL                 COMMENT '💷 Amount captured/refunded, denominated in `currency`',
    `currency`        CHAR(3)         NOT NULL DEFAULT 'GBP'   COMMENT '💱 ISO 4217 currency code for `amount`',
    `status`          VARCHAR(32)     NOT NULL DEFAULT 'completed' COMMENT '📊 Provider-reported payment status (completed, refunded, failed, reversed, ...) - deliberately free text (not an ENUM) because it mirrors the payment provider''s own vocabulary rather than an internal state machine, and a future second provider may use different terms',
    `rawWebhookJSON`  JSON            NULL                     COMMENT '📦 Full raw webhook payload for audit/debugging/replay - schema-only this stage; populated once the PayPal webhook listener ships (public_html/api/v1/webhooks/paypal/, see DEV_NOTES.md)',
    `createdAt`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Payment record creation timestamp',

    PRIMARY KEY (`paymentID`),
    UNIQUE KEY `idx_payment_provider_txn` (`providerTxnRef`),
    INDEX `idx_payment_subscription` (`subscriptionID`),
    INDEX `idx_payment_created` (`createdAt`),

    CONSTRAINT `fk_payment_subscription` FOREIGN KEY (`subscriptionID`)
        REFERENCES `tblSubscriptions` (`subscriptionID`)
        ON DELETE RESTRICT
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='💳 Payment/transaction log per subscription (schema only this stage - populated by the deferred PayPal webhook listener)';

-- ==============================================================================
-- 💷 Flexible-pricing schema delta (Epic E4, GitHub issue #70) - BUILT BUT
--    DISABLED, additive-only, inert by default
-- ==============================================================================
-- Everything in Tables 22-26 below plus the two ALTER TABLE blocks that follow
-- them is ADDITIVE - no existing column on tblSubscriptionPlans/
-- tblSubscriptions/tblPayments is touched, no seeded plan row changes, and no
-- existing gating semantics change. Each new table/column is USELESS until:
--   (a) an admin inserts rows into it, AND
--   (b) the matching master kill-switch in the `billing` tblSettings group is
--       flipped from its seeded 0 to 1 - see seed_data.sql's
--       `billing_price_points_enabled` / `billing_discounts_enabled` /
--       `billing_payg_enabled` rows.
-- This mirrors the INERT-BY-DEFAULT house pattern already used for the email
-- subsystem (`email_enabled`) and SSO (`sso_enabled`) - see this file's own
-- header docs on tblSubscriptionPlans (Table 19) for the "zero-code-change
-- extensible" philosophy this delta extends to PRICE and DISCOUNT shape, not
-- just features/limits.
--
-- The five structures this delta makes representable BY DATA ALONE (zero
-- future code changes to add a real instance of any of these):
--   1. Multiple price points per tier (monthly/yearly/lifetime/launch promo/
--      per-currency) - tblPlanPricePoints.
--   2. One-off / limited-time / lifetime (forever) discounts, coupon-code or
--      automatic/sitewide - tblDiscounts + tblDiscountRedemptions.
--   3. Lifetime tiers - a plan whose only price point has
--      billingInterval='lifetime', paired with a tblSubscriptions row whose
--      currentPeriodEnd stays NULL (already-nullable column, Table 20).
--   4. Pay-as-you-go (PAYG) credit packs - a `one_off` price point with a
--      grantsJSON credit grant, consumed via tblCreditLedger.
--   5. PAYG WITH A CAP - the same credit packs, capped by new
--      `credit_spend_cap_monthly`/`credit_balance_cap` limitsJSON keys read
--      by the EXISTING, unmodified generic limitFor() (fn_features.php) -
--      zero engine change, exactly like every other ★ limit key.
--
-- @see fable_analysis.md §C (this delta's authoring spec)
-- @see Pricing_Strategy.md §5 (worked examples of every structure above)
-- @see _functions/fn_features.php - pricePointsForPlan(), resolveDiscountForCheckout(),
--      creditBalanceFor(), consumeCreditsOr402() (all fail-closed + kill-switch-gated)
-- @see _functions/fn_pricing.php - listPublicPlansForDisplay() prefers these
--      price-point rows over the legacy priceMonthly/priceYearly columns when
--      present for a plan
-- ==============================================================================

-- ==============================================================================
-- 📋 Table 22: tblPlanPricePoints (#70)
-- ==============================================================================
-- Kills the "exactly two fixed price columns, one currency" limitation of
-- tblSubscriptionPlans.priceMonthly/priceYearly/currency (Table 19). A plan
-- may have ZERO price points (the legacy two columns keep working exactly as
-- today - this is the "no rows yet" no-op state), or any number of them: a
-- monthly row, a yearly row, several currencies of the same interval, a
-- time-boxed launch-offer row (validFrom/validUntil), a `lifetime` one-off
-- row (Founder Lifetime deal), or a `one_off` PAYG credit-pack row whose
-- grantsJSON describes what buying it grants (see Table 26 tblCreditLedger).
--
-- grantsJSON shape (NULL for ordinary subscription price points) - a flat
-- JSON object naming what a successful one-off purchase of this price point
-- grants, e.g. for a PAYG credit pack:
--   {"credits": {"qr_generations": 100}}
--
-- `priceKey` is UNIQUE PER PLAN (not globally) - 'monthly'/'yearly' are the
-- conventional defaults `fn_pricing.php` looks for when preferring price
-- points over the legacy columns, but any admin-chosen key works
-- (`launch_monthly_2026`, `credits_100`, `lifetime_founder`, ...).
--
-- @see fn_features.php - pricePointsForPlan() (fail-closed on
--      billing_price_points_enabled=0 or an empty table)
-- @see fn_pricing.php   - the additive query that prefers these rows
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblPlanPricePoints` (
    `pricePointID`     INT UNSIGNED   NOT NULL AUTO_INCREMENT  COMMENT '🔑 Primary key - auto-incrementing price-point identifier',
    `planID`           INT UNSIGNED   NOT NULL                 COMMENT '🔗 FK tblSubscriptionPlans - the tier this price point belongs to',
    `priceKey`         VARCHAR(48)    NOT NULL                 COMMENT '🏷️ Machine key, UNIQUE per plan (monthly, yearly, lifetime, launch_monthly_2026, credits_100, ...) - never guessed/hardcoded by PHP, purely admin-chosen config',
    `billingInterval`  ENUM(
                            'monthly',
                            'yearly',
                            'lifetime',
                            'one_off',
                            'metered'
                        )              NOT NULL                 COMMENT '📅 What kind of charge this is - monthly/yearly recurring, lifetime (pay once, never expires), one_off (a single non-recurring charge, e.g. a PAYG credit pack), metered (reserved for future usage-billed intervals)',
    `intervalCount`    INT UNSIGNED   NOT NULL DEFAULT 1       COMMENT '🔢 Multiplier on billingInterval, e.g. intervalCount=3 with billingInterval=monthly means quarterly billing - avoids needing a dedicated quarterly/biennial enum value',
    `amount`           DECIMAL(10,2)  NOT NULL                 COMMENT '💷 Price in `currency` for this specific price point',
    `currency`         CHAR(3)        NOT NULL DEFAULT 'GBP'   COMMENT '💱 ISO 4217 currency code - PER-ROW (unlike tblSubscriptionPlans'' one currency per plan), so USD/EUR price points can sit alongside a GBP one on the same plan with zero schema change',
    `grantsJSON`       JSON           NULL                     COMMENT '🎁 What a successful ONE-OFF purchase of this price point grants (e.g. {"credits":{"qr_generations":100}} for a PAYG pack) - NULL for an ordinary recurring subscription price point',
    `validFrom`        DATETIME       NULL                     COMMENT '⏰ Price point becomes selectable from this timestamp (NULL = no start restriction) - launch-offer windows',
    `validUntil`       DATETIME       NULL                     COMMENT '⏰ Price point stops being selectable after this timestamp (NULL = no end restriction) - limited-time pricing',
    `isDefault`        TINYINT(1)     NOT NULL DEFAULT 0       COMMENT '⭐ 1 = the preferred/highlighted row for its billingInterval on the pricing page (e.g. the yearly row that should read "recommended")',
    `isActive`         TINYINT(1)     NOT NULL DEFAULT 1       COMMENT '✅ 1 = selectable, 0 = retired (existing purchases/subscriptions keep their FK, just hidden from new selection)',
    `sortOrder`        INT            NOT NULL DEFAULT 0       COMMENT '📊 Display order among a plan''s price points',
    `createdAt`        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Record creation timestamp',
    `updatedAt`        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '📅 Last modification timestamp',

    PRIMARY KEY (`pricePointID`),
    UNIQUE KEY `idx_pricepoint_plan_key` (`planID`, `priceKey`),
    INDEX `idx_pricepoint_plan_active_sort` (`planID`, `isActive`, `sortOrder`),

    CONSTRAINT `fk_pricepoint_plan` FOREIGN KEY (`planID`)
        REFERENCES `tblSubscriptionPlans` (`planID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='💷 Per-plan price points (#70) - multi-price-point/multi-currency/lifetime/PAYG-pack pricing, additive to the legacy priceMonthly/priceYearly columns, inert until billing_price_points_enabled=1';

-- ==============================================================================
-- 📋 Table 23: tblDiscounts (#70)
-- ==============================================================================
-- One-off, limited-time, or lifetime (forever) discounts - either a
-- caller-entered coupon `code`, or an automatic/sitewide offer (code IS NULL,
-- applied without the caller typing anything, e.g. a launch-week 25% off
-- promotion). A discount can be scoped to one plan/price-key or apply
-- everywhere (appliesPlanID/appliesPriceKey both NULL).
--
-- "Lifetime discount" = durationType='forever' (applies for as long as the
-- subscription lives, e.g. a permanent edu/non-profit rate).
-- "Limited-time discount" = a validFrom/validUntil window (e.g. a launch
-- promotion that expires on a fixed date) - independent of durationType,
-- which governs how long the discount applies to an ALREADY-REDEEMED
-- subscription, not how long the offer itself is available to redeem.
-- "One-off discount" = durationType='once' (a single billing cycle) and/or
-- maxRedemptions=1 (a single redeemer, e.g. a Founder Lifetime cap).
--
-- `appliesPriceKey` intentionally has NO foreign key - a priceKey is only
-- unique SCOPED to its plan (see tblPlanPricePoints' UNIQUE(planID,priceKey)
-- above), so this column is a plain matching string interpreted alongside
-- appliesPlanID by fn_features.php's resolveDiscountForCheckout(), exactly
-- like every other identifier-shaped-but-not-FK-enforced column in this
-- schema (mirrors tblQRCodeTypes.typeName's own unique-but-unenforced
-- convention).
--
-- @see fn_features.php - resolveDiscountForCheckout() (fail-closed on
--      billing_discounts_enabled=0 or an empty table)
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblDiscounts` (
    `discountID`       INT UNSIGNED   NOT NULL AUTO_INCREMENT  COMMENT '🔑 Primary key - auto-incrementing discount identifier',
    `code`             VARCHAR(64)    NULL                     COMMENT '🎟️ Caller-entered coupon code (case-sensitive as stored - the application layer decides normalisation) - NULL means AUTOMATIC/sitewide, applied without any code entry',
    `name`             VARCHAR(150)   NOT NULL                 COMMENT '📝 Human-readable name shown in admin/checkout UI (e.g. "Launch offer - 25% off for 12 months")',
    `discountType`     ENUM('percent', 'fixed') NOT NULL       COMMENT '➗ percent = value is a percentage (0-100), fixed = value is a flat amount in the price point''s own currency',
    `value`            DECIMAL(10,2)  NOT NULL                 COMMENT '🔢 The percentage (e.g. 25.00) or fixed amount this discount removes, per `discountType`',
    `appliesPlanID`    INT UNSIGNED   NULL                     COMMENT '🔗 FK tblSubscriptionPlans - NULL = applies to every plan',
    `appliesPriceKey`  VARCHAR(48)    NULL                     COMMENT '🏷️ Matches tblPlanPricePoints.priceKey (NOT FK-enforced - see header comment) - NULL = applies to every price point on the scoped plan',
    `durationType`     ENUM('once', 'repeating', 'forever') NOT NULL DEFAULT 'once' COMMENT '⏳ How long the discount applies to an already-redeemed subscription - once = first billing cycle only, repeating = `durationMonths` cycles, forever = the "lifetime discount" case',
    `durationMonths`   INT UNSIGNED   NULL                     COMMENT '🔢 Number of months the discount repeats for when durationType=repeating (NULL/ignored otherwise)',
    `maxRedemptions`   INT UNSIGNED   NULL                     COMMENT '🎯 Total redemption cap across ALL callers (NULL = uncapped) - e.g. a 500-redemption Founder Lifetime cap',
    `redemptionCount`  INT UNSIGNED   NOT NULL DEFAULT 0       COMMENT '🔢 Running total of successful redemptions - incremented ATOMICALLY under the same per-discount MySQL advisory-lock pattern that closed the saved_codes TOCTOU race (SECURITY.md F-023), never via a bare UPDATE ... SET redemptionCount = redemptionCount + 1 with no serialisation',
    `validFrom`        DATETIME       NULL                     COMMENT '⏰ Discount becomes redeemable from this timestamp (NULL = no start restriction)',
    `validUntil`       DATETIME       NULL                     COMMENT '⏰ Discount stops being redeemable after this timestamp (NULL = no end restriction) - the "limited-time" case',
    `isActive`         TINYINT(1)     NOT NULL DEFAULT 1       COMMENT '✅ 1 = redeemable (subject to the validity window/cap above too), 0 = administratively disabled',
    `createdAt`        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Record creation timestamp',
    `updatedAt`        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '📅 Last modification timestamp',

    PRIMARY KEY (`discountID`),
    UNIQUE KEY `idx_discount_code` (`code`),
    INDEX `idx_discount_plan` (`appliesPlanID`),
    INDEX `idx_discount_active_valid` (`isActive`, `validFrom`, `validUntil`),

    CONSTRAINT `fk_discount_plan` FOREIGN KEY (`appliesPlanID`)
        REFERENCES `tblSubscriptionPlans` (`planID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='🎟️ One-off/limited-time/lifetime discounts (#70), coupon-code or automatic/sitewide, inert until billing_discounts_enabled=1';

-- ==============================================================================
-- 📋 Table 24: tblOneOffPurchases (#70)
-- ==============================================================================
-- A standalone one-off purchase (a lifetime-deal purchase or a PAYG credit
-- pack) that is NOT tied to a recurring tblSubscriptions row the way
-- tblPayments (Table 21) is. Kept as its OWN table - rather than weakening
-- tblPayments.subscriptionID to NULLable - specifically so tblPayments' audit
-- posture (NOT NULL, ON DELETE RESTRICT, "a payment must always trace back to
-- a real subscription") stays completely untouched (see Table 21's header
-- comment). `providerTxnRef` is UNIQUE for the exact same webhook-idempotency
-- reason as tblPayments.providerTxnRef.
--
-- A "lifetime tier" purchase (Pricing_Strategy.md §5.3) inserts BOTH a row
-- here (the payment record) AND a tblSubscriptions row with
-- `currentPeriodEnd = NULL`, `status = 'active'` (that column is already
-- nullable - Table 20 - so no schema change was needed there for the
-- "never expires" property itself, only for the pricePointID/discountID
-- snapshot columns added by the ALTER TABLE block below).
--
-- @see Table 21 tblPayments - the recurring-subscription payment ledger this
--      table deliberately does NOT merge into
-- @see Table 26 tblCreditLedger - a PAYG credit-pack purchase here triggers a
--      matching ledger grant row there
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblOneOffPurchases` (
    `purchaseID`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT  COMMENT '🔑 Primary key - auto-incrementing one-off purchase identifier',
    `userID`           INT UNSIGNED    NULL                     COMMENT '👤 FK tblUsers - the purchasing individual (NULL when this is an org-level purchase instead - exactly one of userID/orgID expected non-NULL, mirroring tblSubscriptions'' XOR convention)',
    `orgID`            INT UNSIGNED    NULL                     COMMENT '🏢 FK tblOrganisations - the purchasing organisation (NULL when this is an individual purchase instead)',
    `pricePointID`     INT UNSIGNED    NOT NULL                 COMMENT '🔗 FK tblPlanPricePoints - the one_off/lifetime price point that was purchased',
    `discountID`       INT UNSIGNED    NULL                     COMMENT '🔗 FK tblDiscounts - snapshot of which discount (if any) was applied to this purchase, mirroring tblSubscriptions.discountID below',
    `provider`         VARCHAR(32)     NOT NULL                 COMMENT '🏦 Payment provider identifier (paypal, ...)',
    `providerTxnRef`   VARCHAR(255)    NOT NULL                 COMMENT '🆔 Provider transaction/event identifier - UNIQUE so a re-delivered webhook is idempotent, exactly like tblPayments.providerTxnRef',
    `amount`           DECIMAL(10,2)   NOT NULL                 COMMENT '💷 Amount captured/refunded, denominated in `currency`',
    `currency`         CHAR(3)         NOT NULL DEFAULT 'GBP'   COMMENT '💱 ISO 4217 currency code for `amount`',
    `status`           VARCHAR(32)     NOT NULL DEFAULT 'completed' COMMENT '📊 Provider-reported payment status - deliberately free text, mirrors tblPayments.status'' own reasoning',
    `rawWebhookJSON`   JSON            NULL                     COMMENT '📦 Full raw webhook payload for audit/debugging/replay - schema-only this stage, mirrors tblPayments.rawWebhookJSON',
    `createdAt`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Purchase record creation timestamp',

    PRIMARY KEY (`purchaseID`),
    UNIQUE KEY `idx_oneoff_provider_txn` (`providerTxnRef`),
    INDEX `idx_oneoff_user` (`userID`),
    INDEX `idx_oneoff_org` (`orgID`),
    INDEX `idx_oneoff_pricepoint` (`pricePointID`),
    INDEX `idx_oneoff_created` (`createdAt`),

    CONSTRAINT `fk_oneoff_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_oneoff_org` FOREIGN KEY (`orgID`)
        REFERENCES `tblOrganisations` (`orgID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_oneoff_pricepoint` FOREIGN KEY (`pricePointID`)
        REFERENCES `tblPlanPricePoints` (`pricePointID`)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT `fk_oneoff_discount` FOREIGN KEY (`discountID`)
        REFERENCES `tblDiscounts` (`discountID`)
        ON DELETE SET NULL
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='💷 Standalone one-off purchases (#70) - lifetime-deal/PAYG-credit-pack payments not tied to a recurring subscription, sibling to tblPayments';

-- ==============================================================================
-- 📋 Table 25: tblDiscountRedemptions (#70)
-- ==============================================================================
-- One row per successful discount redemption. UNIQUE per (discountID,userID)
-- AND per (discountID,orgID) enforces the schema-level "one redemption per
-- subject, per discount" default (MySQL treats each NULL in a UNIQUE index as
-- distinct, so many org-redemptions with userID NULL - or many
-- user-redemptions with orgID NULL - coexist freely; only a genuine repeat by
-- the SAME user or SAME org for the SAME discount is rejected).
-- `subscriptionID` is NULL for a redemption attached to a tblOneOffPurchases
-- row instead (a discount applied to a lifetime/PAYG purchase rather than a
-- recurring subscription).
--
-- redemptionCount on tblDiscounts (Table 23) MUST be incremented in the SAME
-- serialised critical section that inserts a row here, using the identical
-- per-subject MySQL advisory-lock pattern (`GET_LOCK`/`RELEASE_LOCK`) that
-- closed the saved_codes TOCTOU race (SECURITY.md F-023) - see
-- fn_features.php's resolveDiscountForCheckout() docblock.
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblDiscountRedemptions` (
    `redemptionID`     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT  COMMENT '🔑 Primary key - auto-incrementing redemption identifier',
    `discountID`       INT UNSIGNED    NOT NULL                 COMMENT '🔗 FK tblDiscounts - the discount that was redeemed',
    `userID`           INT UNSIGNED    NULL                     COMMENT '👤 FK tblUsers - the redeeming individual (NULL when this is an org-level redemption instead)',
    `orgID`            INT UNSIGNED    NULL                     COMMENT '🏢 FK tblOrganisations - the redeeming organisation (NULL when this is an individual redemption instead)',
    `subscriptionID`   BIGINT UNSIGNED NULL                     COMMENT '🔗 FK tblSubscriptions - the subscription this redemption applied to (NULL for a one-off/lifetime purchase redemption - see tblOneOffPurchases.discountID instead)',
    `redeemedAt`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Redemption timestamp',

    PRIMARY KEY (`redemptionID`),
    UNIQUE KEY `idx_redemption_discount_user` (`discountID`, `userID`),
    UNIQUE KEY `idx_redemption_discount_org` (`discountID`, `orgID`),
    INDEX `idx_redemption_subscription` (`subscriptionID`),

    CONSTRAINT `fk_redemption_discount` FOREIGN KEY (`discountID`)
        REFERENCES `tblDiscounts` (`discountID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_redemption_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_redemption_org` FOREIGN KEY (`orgID`)
        REFERENCES `tblOrganisations` (`orgID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_redemption_subscription` FOREIGN KEY (`subscriptionID`)
        REFERENCES `tblSubscriptions` (`subscriptionID`)
        ON DELETE SET NULL
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='🎟️ Discount redemption audit trail (#70) - one row per successful redemption, one-per-subject-per-discount by default via its UNIQUE keys';

-- ==============================================================================
-- 📋 Table 26: tblCreditLedger (#70)
-- ==============================================================================
-- Append-only ledger for pay-as-you-go (PAYG) credit balances. A subject's
-- (user or org) balance for a given `meterKey` (e.g. 'qr_generations') is
-- ALWAYS derived - never stored - as SUM(delta) across every row matching
-- that subject+meterKey: a positive delta GRANTS credits (a PAYG pack
-- purchase, an admin comp grant, a refund reversal), a negative delta
-- CONSUMES credits (one gated act, e.g. one bulk-generate item beyond a
-- subscription's free allowance). This is a classic append-only ledger
-- pattern - rows are NEVER UPDATEd or DELETEd, only inserted - so the running
-- balance is always reconstructable and auditable from history alone.
--
-- PAYG-WITH-A-CAP needs NO new column here at all: `credit_spend_cap_monthly`
-- and `credit_balance_cap` are just two more keys in the EXISTING
-- tblSubscriptionPlans.limitsJSON, read by the EXISTING, unmodified generic
-- limitFor() - this table only needs to exist for consumeCreditsOr402()
-- (fn_features.php) to have somewhere to write consumption rows and sum
-- month-to-date spend against that cap.
--
-- @see fn_features.php - creditBalanceFor() (SUM(delta), request-cached like
--      planByKey()) and consumeCreditsOr402() (the advisory-lock-wrapped
--      writer, mirroring SECURITY.md F-023's GET_LOCK pattern)
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblCreditLedger` (
    `entryID`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT  COMMENT '🔑 Primary key - auto-incrementing ledger entry identifier',
    `userID`           INT UNSIGNED    NULL                     COMMENT '👤 FK tblUsers - the credit-holding individual (NULL when this is an org-level ledger entry instead - XOR with orgID, mirroring tblSubscriptions)',
    `orgID`            INT UNSIGNED    NULL                     COMMENT '🏢 FK tblOrganisations - the credit-holding organisation (NULL when this is an individual ledger entry instead)',
    `meterKey`         VARCHAR(48)     NOT NULL                 COMMENT '🏷️ Machine key naming WHAT is metered (e.g. qr_generations) - a subject can hold independent balances for multiple meter keys',
    `delta`            INT             NOT NULL                 COMMENT '➕➖ Signed change to the balance - positive = grant (purchase/admin/refund), negative = consume (one gated act); the running balance is SUM(delta) for this subject+meterKey, never stored directly',
    `reason`           VARCHAR(100)    NOT NULL                 COMMENT '📝 Free-text audit reason (purchase, consume, refund, admin_grant, expiry, ...) - not an ENUM so a future reason never needs a schema change',
    `refPurchaseID`    BIGINT UNSIGNED NULL                     COMMENT '🔗 FK tblOneOffPurchases - the purchase that granted this entry (NULL for a consumption row or a reason with no purchase, e.g. admin_grant)',
    `refQrID`          BIGINT UNSIGNED NULL                     COMMENT '🔗 FK tblQRCodes - the QR-related act this consumption entry paid for (NULL for a grant row, or a consumption not tied to a specific QR record)',
    `createdAt`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Ledger entry timestamp - month-to-date spend-cap checks filter on this column',

    PRIMARY KEY (`entryID`),
    INDEX `idx_ledger_user_meter_created` (`userID`, `meterKey`, `createdAt`),
    INDEX `idx_ledger_org_meter_created` (`orgID`, `meterKey`, `createdAt`),
    INDEX `idx_ledger_purchase` (`refPurchaseID`),
    INDEX `idx_ledger_qr` (`refQrID`),

    CONSTRAINT `fk_ledger_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_ledger_org` FOREIGN KEY (`orgID`)
        REFERENCES `tblOrganisations` (`orgID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_ledger_purchase` FOREIGN KEY (`refPurchaseID`)
        REFERENCES `tblOneOffPurchases` (`purchaseID`)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT `fk_ledger_qr` FOREIGN KEY (`refQrID`)
        REFERENCES `tblQRCodes` (`qrID`)
        ON DELETE SET NULL
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='💰 Append-only PAYG credit ledger (#70) - balance = SUM(delta) per (subject, meterKey), inert until billing_payg_enabled=1';

-- ==============================================================================
-- ➕ Additive columns (#70): tblSubscriptions.pricePointID / discountID
-- ==============================================================================
-- Snapshot which price point and (if any) discount a subscription was bought
-- under - both NULLable so every EXISTING row (and every row a caller inserts
-- without ever touching the flexible-pricing tables) is completely unaffected
-- - `fn_features.php`'s subscription resolution logic never reads either
-- column, so this ALTER cannot change any gating behaviour. Placed via
-- ALTER TABLE (rather than editing Table 20's CREATE TABLE block above)
-- specifically so that block stays byte-for-byte the schema this codebase has
-- already shipped and tested against; these two columns are purely additive.
-- ==============================================================================
ALTER TABLE `tblSubscriptions`
    ADD COLUMN `pricePointID` INT UNSIGNED NULL COMMENT '🔗 FK tblPlanPricePoints - the specific price point this subscription was purchased under (NULL = legacy priceMonthly/priceYearly checkout, or a manually-granted/comp subscription with no price point)' AFTER `planID`,
    ADD COLUMN `discountID`   INT UNSIGNED NULL COMMENT '🔗 FK tblDiscounts - the discount (if any) applied at checkout, snapshotted so it survives the discount later expiring/being deactivated' AFTER `pricePointID`,
    ADD INDEX `idx_subscription_pricepoint` (`pricePointID`),
    ADD INDEX `idx_subscription_discount` (`discountID`),
    ADD CONSTRAINT `fk_subscription_pricepoint` FOREIGN KEY (`pricePointID`)
        REFERENCES `tblPlanPricePoints` (`pricePointID`)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_subscription_discount` FOREIGN KEY (`discountID`)
        REFERENCES `tblDiscounts` (`discountID`)
        ON DELETE SET NULL
        ON UPDATE CASCADE;

-- ==============================================================================
-- ➕ Additive column (#70): tblPayments.pricePointID
-- ==============================================================================
-- Same rationale as tblSubscriptions above - snapshots which price point a
-- recurring-subscription payment corresponds to, NULLable so every existing/
-- legacy payment row is unaffected. Placed via ALTER TABLE for the same
-- "keep Table 21's CREATE TABLE block untouched" reason.
-- ==============================================================================
ALTER TABLE `tblPayments`
    ADD COLUMN `pricePointID` INT UNSIGNED NULL COMMENT '🔗 FK tblPlanPricePoints - the price point this payment corresponds to (NULL = legacy priceMonthly/priceYearly billing, no price-point table in use)' AFTER `subscriptionID`,
    ADD INDEX `idx_payment_pricepoint` (`pricePointID`),
    ADD CONSTRAINT `fk_payment_pricepoint` FOREIGN KEY (`pricePointID`)
        REFERENCES `tblPlanPricePoints` (`pricePointID`)
        ON DELETE SET NULL
        ON UPDATE CASCADE;

-- ==============================================================================
-- ✅ Installation Complete
-- ==============================================================================
-- All 26 tables have been created:
--   1. tblSettings              - Application configuration (now incl. per-record encryptionSalt, F-006/G-010)
--   2. tblErrorLog              - Error/exception logging
--   3. tblActivityLog           - Activity tracking
--   4. tblQRCodeTypes           - QR code type definitions
--   5. tblQRCodes               - Generated QR code records
--   6. tblPredefinedLogos       - Predefined logo library
--   7. tblAPIKeys               - API key management
--   8. tblAPIRateLog            - API rate limiting
--   9. tblUsers                 - User accounts (Epic E3 stage 1 - #11/#12/#14/#15)
--  10. tblOrganisations         - Organisation accounts
--  11. tblEmailQueue            - Outbound email queue with retry/backoff (Epic E8)
--  12. tblUserSessions          - Authenticated user sessions (Epic E3 stage 1 - #14)
--  13. tblPasswordResets        - Password reset tokens (Epic E3 stage 1 - #15)
--  14. tblEmailVerifications    - Email verification tokens (Epic E3 stage 1 - #12)
--  15. tblUserOAuth             - Linked SSO/OAuth identities (Epic E2, #55/#19/#20/#28 - client code BUILT)
--  16. tblWebAuthnCredentials   - WebAuthn/passkey credentials (G-006 schema stub)
--  17. tblOrganisationMembers   - Organisation membership + roles (Epic E3 org stage stub)
--  18. tblInvitations           - Organisation invitations (Epic E3 org stage stub)
--  19. tblSubscriptionPlans     - Data-driven premium tier definitions (Epic E4, #25/#26/#53)
--  20. tblSubscriptions         - User/org subscriptions to a plan (Epic E4, #25/#26/#53) +
--                                 pricePointID/discountID snapshot columns (#70)
--  21. tblPayments              - Payment ledger, schema only (Epic E4, #25/#26/#53) +
--                                 pricePointID snapshot column (#70)
--  22. tblPlanPricePoints       - Multi-price-point/multi-currency/lifetime/PAYG-pack pricing
--                                 per plan (#70 - flexible pricing, BUILT BUT DISABLED)
--  23. tblDiscounts             - One-off/limited-time/lifetime discounts, coupon or automatic
--                                 (#70 - BUILT BUT DISABLED)
--  24. tblOneOffPurchases       - Standalone lifetime-deal/PAYG-credit-pack purchases, sibling
--                                 to tblPayments (#70 - BUILT BUT DISABLED)
--  25. tblDiscountRedemptions   - Discount redemption audit trail, one-per-subject-per-discount
--                                 (#70 - BUILT BUT DISABLED)
--  26. tblCreditLedger          - Append-only PAYG credit ledger, balance = SUM(delta) (#70 -
--                                 BUILT BUT DISABLED)
--
-- ⚠️ Tables 22-26 and the two additive columns above are INERT until the
--    matching `billing_price_points_enabled` / `billing_discounts_enabled` /
--    `billing_payg_enabled` kill-switches (seed_data.sql, `billing` settings
--    group) are flipped from their seeded 0 to 1 - see #70.
--
-- 📌 Next step: Run seed_data.sql to populate initial data.
-- ==============================================================================
