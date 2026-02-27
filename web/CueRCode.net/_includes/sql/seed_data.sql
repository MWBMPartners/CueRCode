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
('api', 'api_cors_allowed_origins', '*', 'string', 0, 1, 'CORS allowed origins (comma-separated or * for all)'),

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

-- 📧 Email Settings (Phase 2)
('email', 'smtp_host', '', 'string', 0, 1, 'SMTP server hostname'),
('email', 'smtp_port', '587', 'integer', 0, 1, 'SMTP server port'),
('email', 'smtp_username', '', 'string', 1, 1, 'SMTP authentication username'),
('email', 'smtp_password', '', 'string', 1, 1, 'SMTP authentication password'),
('email', 'smtp_encryption', 'tls', 'string', 0, 1, 'SMTP encryption type (tls, ssl, none)'),
('email', 'email_from_address', 'noreply@cuercode.net', 'email', 0, 1, 'Default from email address'),
('email', 'email_from_name', 'CueRCode', 'string', 0, 1, 'Default from name');

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
 '[{"name":"first_name","label":"First Name","type":"text","required":true,"placeholder":"John","maxlength":100},{"name":"last_name","label":"Last Name","type":"text","required":true,"placeholder":"Doe","maxlength":100},{"name":"organisation","label":"Organisation","type":"text","required":false,"placeholder":"Acme Corp","maxlength":200},{"name":"title","label":"Job Title","type":"text","required":false,"placeholder":"Software Engineer","maxlength":100},{"name":"email","label":"Email","type":"email","required":false,"placeholder":"john@example.com","maxlength":254,"validation":"email"},{"name":"phone","label":"Phone","type":"tel","required":false,"placeholder":"+44 20 1234 5678","maxlength":20},{"name":"mobile","label":"Mobile","type":"tel","required":false,"placeholder":"+44 7700 900000","maxlength":20},{"name":"website","label":"Website","type":"url","required":false,"placeholder":"https://example.com","maxlength":500,"validation":"url"},{"name":"street","label":"Street Address","type":"text","required":false,"placeholder":"123 Main St","maxlength":200},{"name":"city","label":"City","type":"text","required":false,"placeholder":"London","maxlength":100},{"name":"postcode","label":"Postcode","type":"text","required":false,"placeholder":"EC1A 1BB","maxlength":20},{"name":"country","label":"Country","type":"text","required":false,"placeholder":"United Kingdom","maxlength":100},{"name":"notes","label":"Notes","type":"textarea","required":false,"placeholder":"Additional notes...","maxlength":500,"rows":3}]',
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
 '[{"name":"url","label":"App Store URL","type":"url","required":true,"placeholder":"https://apps.apple.com/app/... or https://play.google.com/store/apps/...","maxlength":2048,"validation":"url"},{"name":"platform","label":"Platform","type":"select","required":false,"options":["auto","apple","google"],"default":"auto","hint":"Select platform or auto-detect from URL"}]',
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
 21);

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
-- ✅ Seed Data Complete
-- ==============================================================================
-- Inserted:
--   - 52 default settings across 6 groups
--   - 21 QR code type definitions with form field schemas
--   - 25 predefined logo entries
--
-- 📌 The application is now ready for use.
--    Remove or restrict access to the /install/ directory.
-- ==============================================================================
