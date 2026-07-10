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
    `typeCategory`      VARCHAR(50)     NOT NULL DEFAULT 'general' COMMENT '📂 Category grouping (general, social, payment, media, contact, utility)',
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

    -- 📊 Metadata
    `isPublic`        TINYINT(1)        NOT NULL DEFAULT 0       COMMENT '🌐 1 = visible in public gallery (future feature)',
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
-- 📋 Table 9: tblUsers (STUB - Phase 2)
-- ==============================================================================
-- User account table - created as a schema stub in Phase 1.
-- Will be fully implemented in Phase 2 with registration, authentication,
-- profile management, SSO integration, and role-based access control.
--
-- @see https://www.php.net/manual/en/function.password-hash.php
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `tblUsers` (
    `userID`          INT UNSIGNED    NOT NULL AUTO_INCREMENT  COMMENT '🔑 Primary key - auto-incrementing user identifier',
    `email`           VARCHAR(255)    NOT NULL                 COMMENT '📧 User email address (unique, used for login)',
    `passwordHash`    VARCHAR(255)    NOT NULL                 COMMENT '🔐 Password hash (Argon2id via password_hash(..., PASSWORD_ARGON2ID))',
    `displayName`     VARCHAR(100)    NULL                     COMMENT '📝 User display name',
    `firstName`       VARCHAR(100)    NULL                     COMMENT '📝 User first name',
    `lastName`        VARCHAR(100)    NULL                     COMMENT '📝 User last name',
    `avatarPath`      VARCHAR(500)    NULL                     COMMENT '🖼️ Path to user avatar image',
    `orgID`           INT UNSIGNED    NULL                     COMMENT '🏢 FK to tblOrganisations',
    `roleID`          INT UNSIGNED    NULL                     COMMENT '🎭 User role (1=Global Admin, 2=Admin, 3=Regular User)',
    `isActive`        TINYINT(1)      NOT NULL DEFAULT 1       COMMENT '✅ 1 = account active, 0 = deactivated',
    `isVerified`      TINYINT(1)      NOT NULL DEFAULT 0       COMMENT '✅ 1 = email verified',
    `lastLoginAt`     DATETIME        NULL                     COMMENT '📅 Last successful login timestamp',
    `createdAt`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '📅 Account creation timestamp',
    `updatedAt`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '📅 Last modification timestamp',

    PRIMARY KEY (`userID`),
    UNIQUE KEY `idx_user_email` (`email`),
    INDEX `idx_user_org` (`orgID`),
    INDEX `idx_user_active` (`isActive`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='👤 User accounts (Phase 2 stub - schema only in Phase 1)';

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
-- ✅ Installation Complete
-- ==============================================================================
-- All 11 tables have been created:
--   1. tblSettings          - Application configuration
--   2. tblErrorLog          - Error/exception logging
--   3. tblActivityLog       - Activity tracking
--   4. tblQRCodeTypes       - QR code type definitions
--   5. tblQRCodes           - Generated QR code records
--   6. tblPredefinedLogos   - Predefined logo library
--   7. tblAPIKeys           - API key management
--   8. tblAPIRateLog        - API rate limiting
--   9. tblUsers             - User accounts (Phase 2 stub)
--  10. tblOrganisations     - Organisation accounts (Phase 2 stub)
--  11. tblEmailQueue        - Outbound email queue with retry/backoff (Epic E8)
--
-- 📌 Next step: Run seed_data.sql to populate initial data.
-- ==============================================================================
