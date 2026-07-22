-- ==============================================================================
-- 🌱 CueRCode - Seed Data Script
-- ==============================================================================
--
-- Populates the database with initial configuration, QR code type definitions,
-- and predefined logo entries. Run this after install.sql.
--
-- @package     CueRCode
-- @subpackage  Database
-- @version     1.0.0
-- @since       Phase 1
-- ==============================================================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- ==============================================================================
-- ⚙️ Default Settings (tblSettings)
-- ==============================================================================

INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`) VALUES

-- 🌐 General Settings
('general', 'site_name', 'CueRCode', 'string', 0, 1, 'The public-facing name of the service'),
('general', 'site_url', 'https://cuercode.net', 'url', 0, 1, 'The base URL of the website'),
('general', 'site_tagline', 'Professional QR Code Generator', 'string', 0, 1, 'Tagline displayed alongside the site name'),
('general', 'site_description', 'Create beautiful, customisable QR codes for any purpose.', 'string', 0, 1, 'Meta description for SEO'),
('general', 'site_email', 'hello@cuercode.net', 'email', 0, 1, 'Primary contact email address'),
('general', 'site_timezone', 'UTC', 'string', 0, 1, 'Default timezone (PHP timezone identifier)'),
('general', 'app_version', '1.0.0', 'string', 0, 0, 'Current application version number'),
('general', 'app_phase', '1', 'integer', 0, 0, 'Current development phase'),
('general', 'copyright_holder', 'MWBM Partners Ltd', 'string', 0, 1, 'Copyright holder name for legal notices'),
('general', 'copyright_year', '2026', 'string', 0, 1, 'Copyright year'),

-- 🎨 QR Code Default Settings
('qr_defaults', 'default_qr_size', '800', 'integer', 0, 1, 'Default output size in pixels'),
('qr_defaults', 'default_qr_ecc', 'H', 'string', 0, 1, 'Default error correction level (L, M, Q, H)'),
('qr_defaults', 'default_qr_format', 'png', 'string', 0, 1, 'Default output format (png, jpg, svg, webp, gif, pdf)'),
('qr_defaults', 'default_fg_color', '#000000', 'color', 0, 1, 'Default foreground (dark module) colour'),
('qr_defaults', 'default_bg_color', '#ffffff', 'color', 0, 1, 'Default background colour'),
('qr_defaults', 'default_transparent_bg', '0', 'boolean', 0, 1, 'Default transparent background setting'),
('qr_defaults', 'default_pattern', 'square', 'string', 0, 1, 'Default QR module pattern style'),
('qr_defaults', 'default_marker_border', 'square', 'string', 0, 1, 'Default finder pattern border shape'),
('qr_defaults', 'default_marker_center', 'square', 'string', 0, 1, 'Default finder pattern centre dot shape'),
('qr_defaults', 'default_frame_style', 'none', 'string', 0, 1, 'Default frame style'),
('qr_defaults', 'default_logo_size', '20', 'integer', 0, 1, 'Default logo size as percentage of QR code (0-100)'),
('qr_defaults', 'max_qr_size', '4000', 'integer', 0, 1, 'Maximum allowed output size in pixels'),
('qr_defaults', 'max_logo_upload_size', '5242880', 'integer', 0, 1, 'Maximum logo upload file size in bytes (5MB)'),

-- 🔑 API Settings
('api', 'api_enabled', '1', 'boolean', 0, 1, 'Enable or disable the REST API'),
('api', 'api_version', 'v1', 'string', 0, 0, 'Current API version'),
('api', 'api_rate_limit_anonymous_per_min', '10', 'integer', 0, 1, 'Rate limit per minute for anonymous API requests'),
('api', 'api_rate_limit_anonymous_per_day', '100', 'integer', 0, 1, 'Rate limit per day for anonymous API requests'),
('api', 'api_default_response_format', 'json', 'string', 0, 1, 'Default API response format (json or xml)'),
-- 🔐 B-053 secure-by-default: seed an EMPTY allow-list so a fresh install denies
--    all cross-origin requests until an admin explicitly adds origins. The CORS
--    code already fails closed on an empty value (no Access-Control-Allow-Origin
--    header emitted), so this makes new deployments safe out of the box. Set this
--    to a comma-separated list of trusted origins (or '*' to allow all) via the
--    admin panel only after consciously deciding cross-origin access is wanted.
('api', 'api_cors_allowed_origins', '', 'string', 0, 1, 'CORS allowed origins (comma-separated list; empty = deny all cross-origin; * = allow all)'),

-- 🛡️ Security Settings
('security', 'debug_mode', '0', 'boolean', 0, 1, 'Enable debug mode globally (overridden by ?debug=true URL param)'),
('security', 'session_lifetime', '3600', 'integer', 0, 1, 'Session lifetime in seconds (default 1 hour)'),
('security', 'csrf_token_lifetime', '3600', 'integer', 0, 1, 'CSRF token validity period in seconds'),
('security', 'encryption_algorithm', 'aes-256-gcm', 'string', 0, 0, 'Encryption algorithm for sensitive data'),
('security', 'password_min_length', '8', 'integer', 0, 1, 'Minimum password length for user accounts (Phase 2)'),
('security', 'max_login_attempts', '5', 'integer', 0, 1, 'Maximum failed login attempts before lockout (Phase 2)'),
('security', 'lockout_duration', '900', 'integer', 0, 1, 'Account lockout duration in seconds after max attempts (Phase 2)'),

-- 🛡️ Bot Protection (CloudFlare Turnstile / reCAPTCHA)
('security', 'turnstile_site_key', '', 'string', 1, 1, 'CloudFlare Turnstile site key'),
('security', 'turnstile_secret_key', '', 'string', 1, 1, 'CloudFlare Turnstile secret key'),
('security', 'recaptcha_site_key', '', 'string', 1, 1, 'Google reCAPTCHA site key'),
('security', 'recaptcha_secret_key', '', 'string', 1, 1, 'Google reCAPTCHA secret key'),
('security', 'bot_protection_provider', 'none', 'string', 0, 1, 'Active bot protection provider (none, turnstile, recaptcha)'),

-- 🎨 Branding Settings
('branding', 'primary_color', '#2c7a7b', 'color', 0, 1, 'Primary brand colour'),
('branding', 'secondary_color', '#4fd1c5', 'color', 0, 1, 'Secondary brand colour'),
('branding', 'logo_path', '/assets/img/branding/logo.svg', 'path', 0, 1, 'Path to the site logo file'),
('branding', 'favicon_path', '/assets/img/branding/favicon.svg', 'path', 0, 1, 'Path to the favicon file'),

-- 📧 Email Settings (Epic E8, #29) - Microsoft Graph client-credentials sendMail
-- ------------------------------------------------------------------------------
-- ⚠️  INERT BY DEFAULT: email_enabled seeds to 0 (kill-switch OFF). Nothing in
--     this subsystem sends a single email until an owner:
--       1. Registers an M365 app (Azure AD / Entra ID) with the Mail.Send
--          APPLICATION permission (admin-consented), a dedicated shared mailbox
--          for Send-As, and DKIM/DMARC configured on the sending domain.
--       2. Fills graph_tenant_id / graph_client_id / graph_client_secret and
--          graph_sender_mailbox via the admin settings UI (never commit these -
--          they are seeded EMPTY here on purpose).
--       3. Flips email_enabled to 1 once ready to go live.
--     @see DEV_NOTES.md "Email Subsystem (Microsoft Graph)" for the full runbook.
--
-- 🔐 The four graph_* credential/cache settings are isSensitive=1, meaning
--     fn_config.php transparently AES-256-GCM encrypts them at rest (via
--     fn_encryption.php) and decrypts on read. graph_token_cache is NOT an
--     owner-entered credential - it is a runtime cache fn_email.php's
--     getGraphAccessToken() writes to via setConfig() once a real token is
--     obtained; it starts empty and is safe to leave alone.
--
-- 🐘 Google Workspace (#30) is intentionally NOT seeded here yet - fn_email.php
--     is written driver-shaped (email_provider selects the active driver) so a
--     future Workspace driver can slot in without a schema change.
('email', 'email_enabled', '0', 'boolean', 0, 1, 'Master kill-switch for the email subsystem - 0 = fully inert, no sends attempted regardless of other settings'),
('email', 'email_provider', 'graph', 'string', 0, 1, 'Active email driver (graph = Microsoft Graph client-credentials sendMail; workspace = future Google Workspace driver, #30)'),
('email', 'email_from_name', '', 'string', 0, 1, 'Display name used as the From name on outgoing email (e.g. "CueRCode Notifications") - owner-set, seeded empty'),
('email', 'graph_sender_mailbox', '', 'string', 0, 1, 'M365 shared mailbox address used as the Graph sendMail sender (Send-As permission required) - owner-set, seeded empty'),
('email', 'graph_tenant_id', '', 'string', 1, 1, 'Microsoft Entra ID (Azure AD) tenant ID for the Graph app registration - ENCRYPTED, owner-set via admin only, never commit'),
('email', 'graph_client_id', '', 'string', 1, 1, 'Microsoft Entra ID app registration (client) ID - ENCRYPTED, owner-set via admin only, never commit'),
('email', 'graph_client_secret', '', 'string', 1, 1, 'Microsoft Entra ID app registration client secret value - ENCRYPTED, owner-set via admin only, never commit'),
('email', 'graph_token_cache', '', 'json', 1, 0, 'Runtime OAuth2 access-token cache (access_token + expires_at), auto-managed by getGraphAccessToken() - ENCRYPTED, not owner-editable, do not populate manually'),
('email', 'email_queue_autoprocess', '0', 'boolean', 0, 1, 'Opportunistic shutdown-hook queue processing (≤2 sends/request) - 0 = OFF (recommended: use a Dreamhost panel cron job hitting the queue processor instead); 1 = ON'),

-- 💳 Billing / Premium Tier Settings (Epic E4 - #25/#26/#53)
-- ------------------------------------------------------------------------------
-- Small, admin-editable config that the gating engine (fn_features.php) reads
-- via getConfig() - these are NOT hardcoded in PHP, so an owner can change the
-- pricing page URL or the past_due downgrade tier without a deploy. The plan
-- catalogue itself (prices/features/limits) lives in tblSubscriptionPlans, not
-- here - these two rows are gating BEHAVIOUR knobs, not plan data.
('billing', 'billing_upgrade_url', '/pricing', 'url', 0, 1, 'URL shown to a caller denied a gated feature/limit (requireFeatureOr402() + the bulk-generate over-cap response) - a future plan-selection/pricing page; owner-editable, no code change needed to point it elsewhere'),
('billing', 'billing_past_due_downgrade_plan_key', 'free', 'string', 0, 1, 'The tblSubscriptionPlans.planKey the gating engine falls back to for CREATING new gated objects while a subscription is past_due (existing objects remain readable regardless) - see fn_features.php effectivePlanForGating()'),

-- 💷 Flexible-pricing kill-switches (Epic E4 flexible-pricing delta, #70) -
-- ------------------------------------------------------------------------------
-- ⚠️  INERT BY DEFAULT (mirrors the email_enabled/sso_enabled kill-switch
--     pattern above): each subsystem below is BUILT (schema + fn_features.php
--     helpers + fn_pricing.php display preference all ship in this same
--     migration) but stays COMPLETELY OFF until an admin flips its switch to
--     1 - AND, independently, until real rows exist in the matching new table
--     (tblPlanPricePoints/tblDiscounts+tblDiscountRedemptions/tblCreditLedger
--     - see install.sql Tables 22-26). A fresh install therefore behaves
--     IDENTICALLY to before this migration: `fn_pricing.php` keeps rendering
--     the legacy priceMonthly/priceYearly columns, and every new
--     fn_features.php helper (pricePointsForPlan()/resolveDiscountForCheckout()/
--     creditBalanceFor()/consumeCreditsOr402()) no-ops/fails closed.
-- @see _includes/sql/install.sql - Tables 22-26 (#70 schema delta)
-- @see _functions/fn_features.php - the kill-switch-gated helpers
-- @see _functions/fn_pricing.php - the price-point-preferring display query
-- @see Pricing_Strategy.md §5 - worked examples of every structure these unlock
('billing', 'billing_price_points_enabled', '0', 'boolean', 0, 1, 'Master kill-switch for tblPlanPricePoints (#70) - 0 = fn_pricing.php ignores any price-point rows and keeps rendering the legacy priceMonthly/priceYearly columns; pricePointsForPlan() (fn_features.php) always returns [] while this is 0'),
('billing', 'billing_discounts_enabled', '0', 'boolean', 0, 1, 'Master kill-switch for tblDiscounts/tblDiscountRedemptions (#70) - 0 = resolveDiscountForCheckout() (fn_features.php) always returns null (no discount applied), regardless of what rows exist'),
('billing', 'billing_payg_enabled', '0', 'boolean', 0, 1, 'Master kill-switch for the pay-as-you-go credit subsystem (tblCreditLedger/tblOneOffPurchases, #70) - 0 = creditBalanceFor() reports a balance of 0 and consumeCreditsOr402() (fn_features.php) always denies (fails closed), regardless of any rows already in the ledger'),

-- 🔐 SSO / OIDC Client Settings (Epic E2 - #55/#19/#20/#28)
-- ------------------------------------------------------------------------------
-- ⚠️  INERT BY DEFAULT (mirrors the email_enabled kill-switch pattern above):
--     `sso_enabled` is a MASTER switch (0 = the whole subsystem is off) AND
--     each provider has its OWN `sso_<provider>_enabled` flag (also 0) - BOTH
--     must be 1, plus a non-empty client_id/client_secret/discovery_url,
--     before `ssoProviderIsUsable()` (fn_oauth.php) will render that
--     provider's "Sign in with ..." button or accept a callback for it. A
--     fresh install therefore attempts ZERO outbound SSO-related network
--     calls until an owner deliberately configures + enables a provider.
--
-- 🔑 The `_client_id`/`_client_secret` pairs are isSensitive=1 (AES-256-GCM
--     encrypted at rest via fn_encryption.php, same as the Graph email
--     credentials above) and seed EMPTY - never commit real values here; fill
--     them via the admin settings UI only. `_discovery_url` seeds a stable,
--     PUBLIC, tenant-agnostic OIDC discovery endpoint for Microsoft/Google
--     (safe to ship - it identifies no owner secret) so a fresh install only
--     needs client_id/client_secret + the enabled flags to go live; override
--     it if a single-tenant Entra ID endpoint is preferred. `_scopes`
--     defaults to the minimal OIDC set (openid + email + profile) needed to
--     resolve a verified email address for account linking.
--
-- 🧪 `signula` is present in the provider registry (tblUserOAuth.provider
--     ENUM already includes it) but its `_discovery_url` seeds EMPTY on
--     purpose: SIGNula.id is not yet an OIDC identity provider (credential-
--     proxy API only - see PROJECT_ROADMAP.md Epic E2 upstream findings and
--     SIGNula issue #88) - `ssoProviderIsUsable()` fails closed on the empty
--     discovery URL regardless of its enabled flag, so this provider stays
--     structurally inert until upstream IdP work lands, with no special-case
--     code required.
--
-- @see _functions/fn_oauth.php - ssoProviderConfig()/ssoProviderIsUsable()
-- @see DEV_NOTES.md - "SSO / OIDC Client (Epic E2)" for the owner IdP-registration runbook
('sso', 'sso_enabled', '0', 'boolean', 0, 1, 'Master kill-switch for the entire SSO subsystem - 0 = fully inert regardless of any per-provider flag below'),

('sso', 'sso_microsoft_enabled', '0', 'boolean', 0, 1, 'Enable "Sign in with Microsoft 365" - requires sso_enabled=1 plus a client ID/secret below'),
('sso', 'sso_microsoft_client_id', '', 'string', 1, 1, 'Microsoft Entra ID (Azure AD) app registration (client) ID - ENCRYPTED, owner-set via admin only, never commit'),
('sso', 'sso_microsoft_client_secret', '', 'string', 1, 1, 'Microsoft Entra ID app registration client secret VALUE - ENCRYPTED, owner-set via admin only, never commit'),
('sso', 'sso_microsoft_discovery_url', 'https://login.microsoftonline.com/common/v2.0/.well-known/openid-configuration', 'url', 0, 1, 'OIDC discovery document URL (multi-tenant "common" endpoint - supports both work/school and personal Microsoft accounts). Override with a tenant-specific URL to restrict sign-in to a single Entra ID tenant.'),
('sso', 'sso_microsoft_scopes', 'openid email profile', 'string', 0, 1, 'Space-separated OIDC scopes requested from Microsoft'),

('sso', 'sso_google_enabled', '0', 'boolean', 0, 1, 'Enable "Sign in with Google" - requires sso_enabled=1 plus a client ID/secret below'),
('sso', 'sso_google_client_id', '', 'string', 1, 1, 'Google Cloud Console OAuth 2.0 client ID - ENCRYPTED, owner-set via admin only, never commit'),
('sso', 'sso_google_client_secret', '', 'string', 1, 1, 'Google Cloud Console OAuth 2.0 client secret - ENCRYPTED, owner-set via admin only, never commit'),
('sso', 'sso_google_discovery_url', 'https://accounts.google.com/.well-known/openid-configuration', 'url', 0, 1, 'Google OIDC discovery document URL (stable, public - no owner-specific value)'),
('sso', 'sso_google_scopes', 'openid email profile', 'string', 0, 1, 'Space-separated OIDC scopes requested from Google'),

('sso', 'sso_signula_enabled', '0', 'boolean', 0, 1, 'Enable "Sign in with SIGNula" - EXPERIMENTAL/pending upstream OIDC support (SIGNula issue #88); stays inert regardless of this flag while sso_signula_discovery_url is empty'),
('sso', 'sso_signula_client_id', '', 'string', 1, 1, 'SIGNula.id OAuth client ID - ENCRYPTED, owner-set via admin only, never commit (not usable until SIGNula ships an OIDC IdP surface)'),
('sso', 'sso_signula_client_secret', '', 'string', 1, 1, 'SIGNula.id OAuth client secret - ENCRYPTED, owner-set via admin only, never commit (not usable until SIGNula ships an OIDC IdP surface)'),
('sso', 'sso_signula_discovery_url', '', 'url', 0, 1, 'OIDC discovery document URL - deliberately seeded EMPTY: SIGNula.id does not yet expose /.well-known/openid-configuration (credential-proxy API only, see PROJECT_ROADMAP.md Epic E2 and SIGNula issue #88). Fill in once upstream ships.'),
('sso', 'sso_signula_scopes', 'openid email profile', 'string', 0, 1, 'Space-separated OIDC scopes to request from SIGNula once its OIDC IdP surface exists');

-- ==============================================================================
-- 📱 QR Code Type Definitions (tblQRCodeTypes)
-- ==============================================================================
-- Each type defines:
--   - typeName: machine-readable identifier
--   - formFieldsJSON: JSON schema for dynamic form field rendering
--   - payloadTemplate: QR payload format with {placeholder} markers
-- ==============================================================================

INSERT INTO `tblQRCodeTypes` (`typeName`, `typeLabel`, `typeIcon`, `typeDescription`, `typeCategory`, `payloadTemplate`, `formFieldsJSON`, `sortOrder`) VALUES

-- 🌐 URL
('url', 'URL / Web Link', 'fa-solid fa-link', 'Open a website or web page', 'general',
 '{url}',
 '[{"name":"url","label":"Website URL","type":"url","required":true,"placeholder":"https://example.com","maxlength":2048,"validation":"url"}]',
 1),

-- 📝 Text
('text', 'Plain Text', 'fa-solid fa-align-left', 'Display plain text content', 'general',
 '{text}',
 '[{"name":"text","label":"Text Content","type":"textarea","required":true,"placeholder":"Enter your text here...","maxlength":4296,"rows":5}]',
 2),

-- 📧 Email
('email', 'Email', 'fa-solid fa-envelope', 'Compose an email message', 'contact',
 'mailto:{email}?subject={subject}&body={body}',
 '[{"name":"email","label":"Email Address","type":"email","required":true,"placeholder":"name@example.com","maxlength":254,"validation":"email"},{"name":"subject","label":"Subject","type":"text","required":false,"placeholder":"Email subject","maxlength":200},{"name":"body","label":"Message Body","type":"textarea","required":false,"placeholder":"Email body text...","maxlength":2000,"rows":4}]',
 3),

-- 📞 Phone
('phone', 'Phone Call', 'fa-solid fa-phone', 'Initiate a phone call', 'contact',
 'tel:{phone}',
 '[{"name":"phone","label":"Phone Number","type":"tel","required":true,"placeholder":"+44 20 1234 5678","maxlength":20,"validation":"phone"}]',
 4),

-- 💬 SMS
('sms', 'SMS Message', 'fa-solid fa-comment-sms', 'Send a text message', 'contact',
 'smsto:{phone}:{message}',
 '[{"name":"phone","label":"Phone Number","type":"tel","required":true,"placeholder":"+44 20 1234 5678","maxlength":20,"validation":"phone"},{"name":"message","label":"Message","type":"textarea","required":false,"placeholder":"Your message...","maxlength":160,"rows":3}]',
 5),

-- 💚 WhatsApp
('whatsapp', 'WhatsApp', 'fa-brands fa-whatsapp', 'Open a WhatsApp chat', 'messaging',
 'https://wa.me/{phone}?text={message}',
 '[{"name":"phone","label":"Phone Number (with country code, no +)","type":"tel","required":true,"placeholder":"447123456789","maxlength":20,"validation":"phone"},{"name":"message","label":"Pre-filled Message","type":"textarea","required":false,"placeholder":"Hello!","maxlength":500,"rows":3}]',
 6),

-- 🎥 Zoom
('zoom', 'Zoom Meeting', 'fa-solid fa-video', 'Join a Zoom meeting', 'utility',
 NULL,
 '[{"name":"meeting_id","label":"Meeting ID","type":"text","required":true,"placeholder":"123 456 7890","maxlength":20},{"name":"password","label":"Passcode","type":"text","required":false,"placeholder":"Meeting passcode","maxlength":50},{"name":"meeting_url","label":"OR Zoom Meeting URL","type":"url","required":false,"placeholder":"https://zoom.us/j/1234567890?pwd=...","maxlength":500,"validation":"url","hint":"Provide either Meeting ID + Passcode OR the full meeting URL"}]',
 7),

-- 📍 Geolocation
('geo', 'Location', 'fa-solid fa-location-dot', 'Share a geographic location', 'utility',
 'geo:{latitude},{longitude}',
 '[{"name":"latitude","label":"Latitude","type":"number","required":true,"placeholder":"51.5074","step":"0.000001","min":"-90","max":"90"},{"name":"longitude","label":"Longitude","type":"number","required":true,"placeholder":"-0.1278","step":"0.000001","min":"-180","max":"180"},{"name":"label","label":"Location Name","type":"text","required":false,"placeholder":"London, UK","maxlength":200}]',
 8),

-- 📇 vCard
('vcard', 'vCard (Business Card)', 'fa-solid fa-address-card', 'Share contact information', 'contact',
 NULL,
 '[{"name":"first_name","label":"First Name","type":"text","required":true,"placeholder":"John","maxlength":100},{"name":"last_name","label":"Last Name","type":"text","required":true,"placeholder":"Doe","maxlength":100},{"name":"organisation","label":"Organisation","type":"text","required":false,"placeholder":"Acme Corp","maxlength":200},{"name":"title","label":"Job Title","type":"text","required":false,"placeholder":"Software Engineer","maxlength":100},{"name":"email","label":"Email","type":"email","required":false,"placeholder":"john@example.com","maxlength":254,"validation":"email"},{"name":"phone","label":"Phone","type":"tel","required":false,"placeholder":"+44 20 1234 5678","maxlength":20},{"name":"mobile","label":"Mobile","type":"tel","required":false,"placeholder":"+44 7700 900000","maxlength":20},{"name":"website","label":"Website","type":"url","required":false,"placeholder":"https://example.com","maxlength":500,"validation":"url"},{"name":"street","label":"Street Address","type":"text","required":false,"placeholder":"123 Main St","maxlength":200},{"name":"city","label":"City","type":"text","required":false,"placeholder":"London","maxlength":100},{"name":"postcode","label":"Postcode","type":"text","required":false,"placeholder":"EC1A 1BB","maxlength":20},{"name":"country","label":"Country","type":"text","required":false,"placeholder":"United Kingdom","maxlength":100},{"name":"notes","label":"Notes","type":"textarea","required":false,"placeholder":"Additional notes...","maxlength":500,"rows":3},{"name":"version","label":"vCard Version","type":"select","required":false,"options":["3.0","4.0"],"default":"3.0","hint":"3.0 has the widest scanner compatibility; 4.0 is the current standard"}]',
 9),

-- 📅 Calendar Event
('calendar', 'Calendar Event', 'fa-solid fa-calendar-plus', 'Add an event to calendar', 'utility',
 NULL,
 '[{"name":"title","label":"Event Title","type":"text","required":true,"placeholder":"Team Meeting","maxlength":200},{"name":"description","label":"Description","type":"textarea","required":false,"placeholder":"Meeting agenda...","maxlength":1000,"rows":3},{"name":"location","label":"Location","type":"text","required":false,"placeholder":"Conference Room A","maxlength":300},{"name":"start_date","label":"Start Date & Time","type":"datetime-local","required":true},{"name":"end_date","label":"End Date & Time","type":"datetime-local","required":true},{"name":"all_day","label":"All Day Event","type":"checkbox","required":false,"default":false}]',
 10),

-- 💰 PayPal
('paypal', 'PayPal Payment', 'fa-brands fa-paypal', 'Send a PayPal payment or donation', 'payment',
 'https://paypal.me/{username}/{amount}',
 '[{"name":"username","label":"PayPal.me Username","type":"text","required":true,"placeholder":"myshop","maxlength":100},{"name":"amount","label":"Amount (optional)","type":"number","required":false,"placeholder":"10.00","step":"0.01","min":"0"}]',
 11),

-- ₿ Bitcoin
('bitcoin', 'Bitcoin Payment', 'fa-brands fa-bitcoin', 'Send a Bitcoin payment', 'payment',
 'bitcoin:{address}?amount={amount}&label={label}&message={message}',
 '[{"name":"address","label":"Bitcoin Address","type":"text","required":true,"placeholder":"1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa","maxlength":100},{"name":"amount","label":"Amount (BTC)","type":"number","required":false,"placeholder":"0.001","step":"0.00000001","min":"0"},{"name":"label","label":"Label","type":"text","required":false,"placeholder":"Payment for...","maxlength":100},{"name":"message","label":"Message","type":"text","required":false,"placeholder":"Thank you!","maxlength":200}]',
 12),

-- 🎵 MP3 / Audio
('mp3', 'MP3 / Audio File', 'fa-solid fa-music', 'Link to an audio file', 'media',
 '{url}',
 '[{"name":"url","label":"Audio File URL","type":"url","required":true,"placeholder":"https://example.com/audio.mp3","maxlength":2048,"validation":"url"}]',
 13),

-- 🎬 Video
('video', 'Video File', 'fa-solid fa-film', 'Link to a video file', 'media',
 '{url}',
 '[{"name":"url","label":"Video File URL","type":"url","required":true,"placeholder":"https://example.com/video.mp4","maxlength":2048,"validation":"url"}]',
 14),

-- 📄 PDF
('pdf', 'PDF Document', 'fa-solid fa-file-pdf', 'Link to a PDF document', 'media',
 '{url}',
 '[{"name":"url","label":"PDF File URL","type":"url","required":true,"placeholder":"https://example.com/document.pdf","maxlength":2048,"validation":"url"}]',
 15),

-- 🖼️ Image
('image', 'Image File', 'fa-solid fa-image', 'Link to an image file', 'media',
 '{url}',
 '[{"name":"url","label":"Image File URL","type":"url","required":true,"placeholder":"https://example.com/photo.jpg","maxlength":2048,"validation":"url"}]',
 16),

-- 📱 App Store
('appstore', 'App Store Link', 'fa-solid fa-mobile-screen-button', 'Link to an app on Apple App Store or Google Play', 'utility',
 '{url}',
 '[{"name":"url","label":"App Store URL","type":"url","required":true,"placeholder":"https://apps.apple.com/app/... or https://play.google.com/store/apps/...","maxlength":2048,"validation":"url"},{"name":"platform","label":"Platform","type":"select","required":false,"options":["apple","google"],"default":"apple","hint":"Select the app store (required when building from an app ID — a static QR code cannot auto-detect the platform)"}]',
 17),

-- 📘 Facebook
('facebook', 'Facebook', 'fa-brands fa-facebook', 'Link to a Facebook page or profile', 'social',
 '{url}',
 '[{"name":"url","label":"Facebook URL","type":"url","required":true,"placeholder":"https://facebook.com/yourpage","maxlength":2048,"validation":"url"}]',
 18),

-- 🎬 YouTube
('youtube', 'YouTube', 'fa-brands fa-youtube', 'Link to a YouTube video or channel', 'social',
 '{url}',
 '[{"name":"url","label":"YouTube URL","type":"url","required":true,"placeholder":"https://youtube.com/watch?v=... or https://youtube.com/@channel","maxlength":2048,"validation":"url"}]',
 19),

-- 📶 Wi-Fi
('wifi', 'Wi-Fi Network', 'fa-solid fa-wifi', 'Connect to a Wi-Fi network', 'utility',
 'WIFI:T:{encryption};S:{ssid};P:{password};H:{hidden};;',
 '[{"name":"ssid","label":"Network Name (SSID)","type":"text","required":true,"placeholder":"MyWiFiNetwork","maxlength":64},{"name":"password","label":"Password","type":"password","required":false,"placeholder":"WiFi password","maxlength":128},{"name":"encryption","label":"Security Type","type":"select","required":true,"options":["WPA","WEP","nopass"],"default":"WPA"},{"name":"hidden","label":"Hidden Network","type":"checkbox","required":false,"default":false}]',
 20),

-- 💼 LinkedIn
('linkedin', 'LinkedIn', 'fa-brands fa-linkedin', 'Link to a LinkedIn profile or page', 'social',
 '{url}',
 '[{"name":"url","label":"LinkedIn URL","type":"url","required":true,"placeholder":"https://linkedin.com/in/yourprofile","maxlength":2048,"validation":"url"}]',
 21),

-- 💬 Facebook Messenger
-- payloadTemplate is NULL so the dispatcher always routes to
-- buildPayload_facebook_messenger(), which strips a leading '@' and validates
-- the username before building the m.me deep link.
('facebook_messenger', 'Facebook Messenger', 'fa-brands fa-facebook-messenger', 'Open a chat in Facebook Messenger', 'messaging',
 NULL,
 '[{"name":"username","label":"Messenger Username","type":"text","required":true,"placeholder":"yourpage","maxlength":100,"hint":"Your Facebook page/profile username (the part after m.me/ — a leading @ is fine)"},{"name":"url","label":"OR Messenger URL","type":"url","required":false,"placeholder":"https://m.me/yourpage","maxlength":2048,"validation":"url","hint":"Provide either a username OR the full m.me link"}]',
 22),

-- 📸 Instagram
-- payloadTemplate is NULL so the dispatcher routes to buildPayload_instagram(),
-- which strips a leading '@' and validates the handle before building the URL.
('instagram', 'Instagram', 'fa-brands fa-instagram', 'Link to an Instagram profile', 'social',
 NULL,
 '[{"name":"username","label":"Instagram Username","type":"text","required":true,"placeholder":"yourhandle","maxlength":100,"hint":"Your Instagram handle (a leading @ is fine)"},{"name":"url","label":"OR Instagram URL","type":"url","required":false,"placeholder":"https://instagram.com/yourhandle","maxlength":2048,"validation":"url","hint":"Provide either a username OR the full profile URL"}]',
 23),

-- ⭐ Review / Rating
-- payloadTemplate is NULL so the dispatcher routes to buildPayload_rating(),
-- which validates the http(s) review URL before encoding it.
('rating', 'Review / Rating', 'fa-solid fa-star', 'Link to a review or rating page', 'utility',
 NULL,
 '[{"name":"url","label":"Review / Rating URL","type":"url","required":true,"placeholder":"https://g.page/r/yourbusiness/review","maxlength":2048,"validation":"url","hint":"e.g. a Google review link, Trustpilot page or App Store review URL"}]',
 24),

-- 🔗 App Deep Link (FG-007)
-- payloadTemplate is NULL so the dispatcher routes to buildPayload_deeplink().
-- A static QR cannot branch on the OS / installed-app state, so it encodes a
-- single https Universal Link (iOS) / App Link (Android): the OS opens the app
-- if installed, else the web. The field names below MUST match the keys read by
-- buildPayload_deeplink(): 'url' (the encoded link) plus the documentation-only
-- 'app_scheme' and 'fallback_url'.
('deeplink', 'App Deep Link', 'fa-solid fa-arrow-up-right-from-square', 'Open an app via a Universal/App Link (falls back to the web)', 'utility',
 NULL,
 '[{"name":"url","label":"Universal / App Link (https)","type":"url","required":true,"placeholder":"https://example.com/app/open/item/42","maxlength":2048,"validation":"url","hint":"A static QR cannot detect the OS — encode an https link your app claims; the OS opens the app if installed, else the web"},{"name":"app_scheme","label":"App Scheme (optional, for your records)","type":"text","required":false,"placeholder":"myapp","maxlength":100,"hint":"Documentation only — the custom scheme your app registers (not encoded into the QR)"},{"name":"fallback_url","label":"Web Fallback URL (optional, for your records)","type":"url","required":false,"placeholder":"https://example.com/get-the-app","maxlength":2048,"validation":"url","hint":"Documentation only — where users without the app should land (not encoded into the QR)"}]',
 25),

-- 🏷️ GS1 Product (GS1 Digital Link) (FG-009)
-- payloadTemplate is NULL so the dispatcher routes to buildPayload_gs1_product(),
-- which builds a GS1 Digital Link URI:
--   https://<resolver>/01/{gtin}[/10/{batch}][/21/{serial}][?17={expiry}]
-- Field names below MUST match the keys read by the builder: 'gtin' (required),
-- optional 'batch' (AI 10), 'serial' (AI 21), 'expiry' (AI 17, YYMMDD), and
-- 'resolver' (host, defaults to id.gs1.org).
-- @see https://www.gs1.org/standards/gs1-digital-link
('gs1_product', 'GS1 Product (Digital Link)', 'fa-solid fa-barcode', 'Encode a product as a GS1 Digital Link (GTIN, batch, serial, expiry)', 'utility',
 NULL,
 '[{"name":"gtin","label":"GTIN","type":"text","required":true,"placeholder":"09521234543213","maxlength":14,"hint":"Global Trade Item Number — 8, 12, 13 or 14 digits (AI 01)"},{"name":"batch","label":"Batch / Lot","type":"text","required":false,"placeholder":"LOT42","maxlength":20,"hint":"AI 10 — batch or lot number (optional)"},{"name":"serial","label":"Serial Number","type":"text","required":false,"placeholder":"SN0001","maxlength":20,"hint":"AI 21 — serial number (optional)"},{"name":"expiry","label":"Expiry (YYMMDD)","type":"text","required":false,"placeholder":"261231","maxlength":6,"hint":"AI 17 — expiry date in YYMMDD format (optional)"},{"name":"resolver","label":"Resolver Host","type":"text","required":false,"placeholder":"id.gs1.org","maxlength":253,"hint":"GS1 Digital Link resolver host (defaults to id.gs1.org)"}]',
 26);

-- ==============================================================================
-- 🖼️ Predefined Logos (tblPredefinedLogos)
-- ==============================================================================
-- These logos correspond to SVG/PNG files in public_html/assets/img/logos/
-- ==============================================================================

INSERT INTO `tblPredefinedLogos` (`logoName`, `logoLabel`, `logoFileName`, `logoCategory`, `sortOrder`) VALUES

-- 🌐 Social Media
('facebook',    'Facebook',     'facebook.svg',     'social',    1),
('instagram',   'Instagram',    'instagram.svg',    'social',    2),
('twitter',     'X (Twitter)',  'twitter.svg',      'social',    3),
('youtube',     'YouTube',      'youtube.svg',      'social',    4),
('linkedin',    'LinkedIn',     'linkedin.svg',     'social',    5),
('tiktok',      'TikTok',       'tiktok.svg',       'social',    6),
('pinterest',   'Pinterest',    'pinterest.svg',    'social',    7),
('snapchat',    'Snapchat',     'snapchat.svg',     'social',    8),

-- 💬 Messaging
('whatsapp',    'WhatsApp',     'whatsapp.svg',     'messaging', 10),
('telegram',    'Telegram',     'telegram.svg',     'messaging', 11),
('skype',       'Skype',        'skype.svg',        'messaging', 12),

-- 💰 Payment
('paypal',      'PayPal',       'paypal.svg',       'payment',   20),
('bitcoin',     'Bitcoin',      'bitcoin.svg',      'payment',   21),

-- 📱 Platforms
('apple',       'Apple',        'apple.svg',        'business',  30),
('android',     'Android',      'android.svg',      'business',  31),
('appstore',    'App Store',    'appstore.svg',     'business',  32),
('playstore',   'Play Store',   'playstore.svg',    'business',  33),

-- 🏢 Business / Utility
('globe',       'Website',      'globe.svg',        'utility',   40),
('email',       'Email',        'email.svg',        'utility',   41),
('phone',       'Phone',        'phone.svg',        'utility',   42),
('location',    'Location',     'location.svg',     'utility',   43),
('calendar',    'Calendar',     'calendar.svg',     'utility',   44),
('wifi',        'Wi-Fi',        'wifi.svg',         'utility',   45),
('document',    'Document',     'document.svg',     'utility',   46),
('camera',      'Camera',       'camera.svg',       'utility',   47),
('qrcode',      'QR Code',      'qrcode.svg',       'utility',   48);

-- ==============================================================================
-- 💳 Subscription Plans (tblSubscriptionPlans) - Epic E4, #25/#26/#53
-- ==============================================================================
-- The FOUR seeded tiers from PROJECT_ROADMAP.md "Premium Tier Design" -
-- anonymous (not logged in), free (registered account, no payment), pro
-- (paid individual), business (paid organisation). These rows are the ONLY
-- place tier behaviour is defined - `_functions/fn_features.php` reads
-- featuresJSON/limitsJSON generically (no per-feature/per-tier PHP branches),
-- so editing a row here (via SQL/phpMyAdmin today, the admin plan editor once
-- Epic E3's admin stage ships) changes what that tier can do with ZERO code
-- changes and no deploy.
--
-- featuresJSON - flat object of booleans. Seeded keys (all six wired to the
-- PROJECT_ROADMAP.md tier matrix; add more any time - an absent key reads as
-- `false` via fn_features.php's fail-closed default, so rollout is opt-in):
--   expiry_scheduling      - QR expiry & scheduling (FG-005)
--   password_protected_qr  - password-protected QR unlock (FG-004)
--   sso                    - SSO sign-in (Epic E2)
--   organisations          - organisation accounts/roles (Epic E3 org stage)
--   webhooks               - outbound event webhooks (FG-013)
--   csv_export             - analytics CSV/JSON export (Epic E11)
--
-- limitsJSON - flat object of integers, where -1 means UNLIMITED. Seeded keys
-- (add more any time - an absent key reads as 0 via fn_features.php's
-- fail-closed default):
--   saved_codes             - max codes in the saved-codes library (Epic E3)
--   bulk_batch_size         - max items per /api/v1/bulk-generate request
--                             (THIS STAGE'S wired gate - fn_api_bulk.php)
--   dynamic_codes           - max editable-destination dynamic QR codes (E1)
--   api_keys                - max active tblAPIKeys rows per subject
--   analytics_days          - scan-analytics retention window (Epic E11)
--   api_rate_limit_per_min  - synced onto tblAPIKeys.rateLimitPerMin
--                             (fn_features.php syncApiKeyLimitsForUser())
--   api_rate_limit_per_day  - synced onto tblAPIKeys.rateLimitPerDay
--
-- ⚠️ priceMonthly/priceYearly/currency below are PLACEHOLDER example values
-- (owner has not set final pricing) - purely config, changeable via a single
-- UPDATE statement with no code impact whatsoever.
--
-- 🆕 TO ADD A FIFTH TIER (e.g. an "enterprise" plan): simply
--   INSERT INTO `tblSubscriptionPlans`
--       (`planKey`, `planName`, `priceMonthly`, `priceYearly`, `currency`,
--        `featuresJSON`, `limitsJSON`, `sortOrder`)
--   VALUES ('enterprise', 'Enterprise', 199.99, 1999.99, 'GBP',
--       '{"expiry_scheduling":true, ... }', '{"saved_codes":-1, ...}', 4);
-- No PHP file needs to change - the moment an active tblSubscriptions row
-- points a user/org at 'enterprise', fn_features.php resolves it.
--
-- @see _functions/fn_features.php
-- @see DEV_NOTES.md - "How to add or modify a premium tier"
-- ==============================================================================

INSERT INTO `tblSubscriptionPlans`
    (`planKey`, `planName`, `description`, `priceMonthly`, `priceYearly`, `currency`, `featuresJSON`, `limitsJSON`, `isActive`, `sortOrder`)
VALUES

-- 👤 Anonymous - not logged in. Generous acquisition funnel: full static QR
-- customisation, but no persistence/dynamism/volume/premium features.
('anonymous', 'Anonymous', 'Generate QR codes instantly with no account required.', 0.00, 0.00, 'GBP',
 '{"expiry_scheduling": false, "password_protected_qr": false, "sso": false, "organisations": false, "webhooks": false, "csv_export": false}',
 '{"saved_codes": 0, "bulk_batch_size": 25, "dynamic_codes": 0, "api_keys": 0, "analytics_days": 0, "api_rate_limit_per_min": 10, "api_rate_limit_per_day": 100}',
 1, 0),

-- 🆓 Free - a registered, unpaid account. Adds persistence (saved codes),
-- a small dynamic-QR allowance, and one API key, still no premium features.
('free', 'Free', 'Create a free account to save your codes and unlock a few extras.', 0.00, 0.00, 'GBP',
 '{"expiry_scheduling": false, "password_protected_qr": false, "sso": false, "organisations": false, "webhooks": false, "csv_export": false}',
 '{"saved_codes": 25, "bulk_batch_size": 50, "dynamic_codes": 3, "api_keys": 1, "analytics_days": 0, "api_rate_limit_per_min": 30, "api_rate_limit_per_day": 2000}',
 1, 1),

-- 💎 Pro - paid individual tier. Unlocks expiry/scheduling, password
-- protection, webhooks, and analytics export; SSO/organisations stay Business-only.
('pro', 'Pro', 'For individuals who need dynamic QR codes, analytics, and automation.', 4.99, 49.99, 'GBP',
 '{"expiry_scheduling": true, "password_protected_qr": true, "sso": false, "organisations": false, "webhooks": true, "csv_export": true}',
 '{"saved_codes": 500, "bulk_batch_size": 500, "dynamic_codes": 100, "api_keys": 5, "analytics_days": 90, "api_rate_limit_per_min": 120, "api_rate_limit_per_day": 20000}',
 1, 2),

-- 🏢 Business - paid organisation tier. Every feature flag on, effectively
-- unlimited saved codes (fair use), the largest bulk/API allowances.
('business', 'Business', 'For teams and organisations that need SSO, roles, and enterprise-scale volume.', 19.99, 199.99, 'GBP',
 '{"expiry_scheduling": true, "password_protected_qr": true, "sso": true, "organisations": true, "webhooks": true, "csv_export": true}',
 '{"saved_codes": -1, "bulk_batch_size": 2000, "dynamic_codes": 1000, "api_keys": 20, "analytics_days": 1095, "api_rate_limit_per_min": 600, "api_rate_limit_per_day": 100000}',
 1, 3);

-- ==============================================================================
-- ✅ Seed Data Complete
-- ==============================================================================
-- Inserted:
--   - 75 default settings across 8 groups (email group is Microsoft Graph -
--     based settings, Epic E8/#29; billing group, Epic E4/#25/#26/#53 plus the
--     3 flexible-pricing kill-switches added by #70 - billing_price_points_
--     enabled/billing_discounts_enabled/billing_payg_enabled, all seeded 0/
--     INERT; sso group - 16 settings, Epic E2/#55/#19/#20/#28 - every provider
--     seeded INERT: sso_enabled=0, all sso_<provider>_enabled=0, all
--     client_id/client_secret empty)
--   - 26 QR code type definitions with form field schemas
--   - 25 predefined logo entries
--   - 4 subscription plan tiers (anonymous/free/pro/business, Epic E4/#53) -
--     UNCHANGED by #70; the 5 new flexible-pricing tables (install.sql Tables
--     22-26) are seeded with ZERO rows here - they stay empty/no-op until an
--     admin populates them AND flips the matching kill-switch above
--
-- 📌 The application is now ready for use.
--    Remove or restrict access to the /install/ directory.
-- ==============================================================================
