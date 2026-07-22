<?php
/**
 * ==============================================================================
 * 📧 CueRCode - Email Subsystem (Microsoft Graph client-credentials sendMail)
 * ==============================================================================
 *
 * Path: _functions/fn_email.php
 *
 * Implements Epic E8 (#29): transactional email via a Microsoft 365 shared
 * mailbox over the Microsoft Graph API, using the OAuth2 client-credentials
 * grant (application permission, no signed-in user). Per ProjectBrief §13 this
 * deliberately does NOT use PHP's built-in mail() function - Graph is required
 * for DKIM/DMARC-compliant delivery from the shared mailbox.
 *
 * Written driver-shaped: every function here is Graph-specific, but the
 * `email_provider` setting (tblSettings, group 'email') is the seam a future
 * Google Workspace driver (#30) will switch on, without callers needing to
 * change how they call queueEmail()/sendEmailNow().
 *
 * 🔌 INERT BY DEFAULT: this whole subsystem is a no-op until an owner:
 *   1. Registers an M365 app (Entra ID) with the Mail.Send APPLICATION
 *      permission (admin-consented) against a dedicated shared mailbox, and
 *      configures DKIM/DMARC on the sending domain.
 *   2. Fills graph_tenant_id / graph_client_id / graph_client_secret /
 *      graph_sender_mailbox via the admin settings UI (tblSettings, seeded
 *      EMPTY - never commit real values).
 *   3. Flips the `email_enabled` kill-switch setting to 1.
 * Every public function below fails OPEN (returns null/false/[skipped]) the
 * moment any required credential or the kill-switch is missing - it NEVER
 * throws into a caller and NEVER attempts a network call when unconfigured.
 *
 * @package     CueRCode
 * @subpackage  Email
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E8)
 * @see         https://learn.microsoft.com/en-us/graph/auth-v2-service
 * @see         https://learn.microsoft.com/en-us/graph/api/user-sendmail
 * @see         https://www.php.net/manual/en/book.curl.php
 * @see         fn_config.php   - getConfig()/setConfig() (isSensitive encryption)
 * @see         fn_activity_log.php - logActivity()
 */

// ==============================================================================
// 🚫 Direct access protection
// ==============================================================================
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $scriptFilename = $_SERVER['SCRIPT_FILENAME'];
} else {
    $scriptFilename = '';
}
if (basename($scriptFilename) === basename(__FILE__)) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied.');
}

// ==============================================================================
// 🔧 Constants
// ==============================================================================

/**
 * @var string sprintf() template for the Microsoft identity platform v2.0
 * client-credentials token endpoint. %s = URL-encoded tenant ID.
 * @see https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-client-creds-grant-flow
 */
if (!defined('CUERCODE_GRAPH_TOKEN_ENDPOINT_TEMPLATE')) {
    define('CUERCODE_GRAPH_TOKEN_ENDPOINT_TEMPLATE', 'https://login.microsoftonline.com/%s/oauth2/v2.0/token');
}

/**
 * @var string The Graph API default scope for the client-credentials grant.
 * The `.default` scope tells Entra ID to grant whatever application
 * permissions have been admin-consented for this app registration.
 */
if (!defined('CUERCODE_GRAPH_SCOPE')) {
    define('CUERCODE_GRAPH_SCOPE', 'https://graph.microsoft.com/.default');
}

/**
 * @var string sprintf() template for the Graph sendMail endpoint.
 * %s = URL-encoded shared mailbox address (the "Send As" mailbox).
 * @see https://learn.microsoft.com/en-us/graph/api/user-sendmail
 */
if (!defined('CUERCODE_GRAPH_SENDMAIL_ENDPOINT_TEMPLATE')) {
    define('CUERCODE_GRAPH_SENDMAIL_ENDPOINT_TEMPLATE', 'https://graph.microsoft.com/v1.0/users/%s/sendMail');
}

/**
 * @var int Hard curl timeout (seconds) for BOTH the token request and the
 * sendMail request. Keeps a misbehaving/unreachable Graph endpoint from
 * stalling a request-triggered call (e.g. the opportunistic shutdown hook).
 */
if (!defined('CUERCODE_GRAPH_CURL_TIMEOUT_SECONDS')) {
    define('CUERCODE_GRAPH_CURL_TIMEOUT_SECONDS', 10);
}

/**
 * @var int Refresh the cached access token once fewer than this many seconds
 * remain before its expiry, to avoid a request racing an about-to-expire token.
 */
if (!defined('CUERCODE_GRAPH_TOKEN_REFRESH_MARGIN_SECONDS')) {
    define('CUERCODE_GRAPH_TOKEN_REFRESH_MARGIN_SECONDS', 60);
}

/**
 * @var string Absolute path to the directory containing email_templates/*.php.
 * Mirrors CUERCODE_INCLUDES_DIR (defined by both the production bootstrap and
 * the test harness bootstrap) so this resolves identically in both contexts.
 */
if (!defined('CUERCODE_EMAIL_TEMPLATES_DIR')) {
    if (defined('CUERCODE_INCLUDES_DIR')) {
        define('CUERCODE_EMAIL_TEMPLATES_DIR', CUERCODE_INCLUDES_DIR . 'email_templates' . DIRECTORY_SEPARATOR);
    } else {
        // ⚠️ Fallback if the bootstrap constant is somehow unavailable - resolve
        // relative to this file: _functions/../_includes/email_templates/
        define(
            'CUERCODE_EMAIL_TEMPLATES_DIR',
            dirname(__DIR__) . DIRECTORY_SEPARATOR . '_includes' . DIRECTORY_SEPARATOR
                . 'email_templates' . DIRECTORY_SEPARATOR
        );
    }
}

// ==============================================================================
// 🔒 emailEscape() - Shared HTML-escaping helper for templates
// ==============================================================================

/**
 * 🔒 Escape a value for safe interpolation into email HTML.
 *
 * Every _includes/email_templates/*.php file MUST run every interpolated
 * parameter through this (or an equivalent htmlspecialchars() call) before
 * echoing it - templates receive caller-supplied data (names, subjects,
 * organisation names, etc.) that must never be trusted as raw HTML.
 *
 * @param mixed $value Any scalar value to render into HTML.
 *
 * @return string The HTML-escaped string.
 *
 * @see https://www.php.net/manual/en/function.htmlspecialchars.php
 */
function emailEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ==============================================================================
// 🖼️ emailRenderShell() - Shared branded HTML wrapper for all templates
// ==============================================================================

/**
 * 🖼️ Wrap message-specific inner HTML in the shared branded email shell.
 *
 * Every _includes/email_templates/*.php file calls this to get consistent
 * chrome (wordmark header, footer, dark-mode-aware colours) around its own
 * content, instead of duplicating the full <html>/<head>/<body> boilerplate
 * across four separate template files.
 *
 * 🔧 EMAIL-ONLY <table> LAYOUT EXCEPTION: this project's house style forbids
 * `<table>` for WEB UI layout (see project CLAUDE.md), but email clients -
 * Outlook desktop's Word rendering engine especially - have extremely limited
 * CSS support and effectively REQUIRE nested `<table>` layout for reliable
 * cross-client rendering (flexbox/grid are widely unsupported in email
 * clients). This is a deliberate, EMAIL-ONLY exception; it does not relax the
 * no-table rule anywhere else in the codebase.
 *
 * @param string $titleText     Plain text for the <title> element.
 * @param string $preheaderText Hidden preview text shown in inbox list views
 *                                (most clients show the first ~100 chars of
 *                                body text next to the subject unless a
 *                                preheader like this is supplied).
 * @param string $innerBodyHtml Pre-built inner HTML for the message-specific
 *                                content area. The CALLER (template) is
 *                                responsible for escaping any interpolated
 *                                values within it via emailEscape() - this
 *                                function only escapes $titleText/$preheaderText.
 *
 * @return string A complete, self-contained HTML document string.
 *
 * @see emailEscape()
 */
function emailRenderShell(string $titleText, string $preheaderText, string $innerBodyHtml): string
{
    // 🔒 Escape the two plain-text-only parameters this function owns directly.
    $escapedTitle     = emailEscape($titleText);
    $escapedPreheader = emailEscape($preheaderText);

    // 📅 Dynamic copyright year (matches the site-wide convention used elsewhere).
    $currentYear = date('Y');

    // 🎨 Colours mirror the seeded branding defaults (tblSettings group
    // 'branding': primary_color #2c7a7b, secondary_color #4fd1c5). Templates
    // are rendered without a DB connection available in tests, so these are
    // intentionally hard-coded fallbacks rather than a getConfig() lookup.
    return <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{$escapedTitle}</title>
    <style>
        /* 🌓 Dark-mode-aware overrides for clients that honour prefers-color-scheme
           (Apple Mail, iOS Mail, some Outlook.com/Outlook mobile builds). Clients
           that ignore this simply keep the light-mode inline styles below. */
        @media (prefers-color-scheme: dark) {
            body, .email-bg { background-color: #1a1a1a !important; }
            .email-card { background-color: #262626 !important; color: #f0f0f0 !important; }
            .email-muted { color: #b0b0b0 !important; }
        }
    </style>
    </head>
    <body class="email-bg" style="margin:0; padding:0; background-color:#f4f4f4; font-family:Arial, Helvetica, sans-serif;">
        <!-- 👻 Hidden preheader text - the inbox preview snippet next to the subject line -->
        <div style="display:none; max-height:0; overflow:hidden; opacity:0; mso-hide:all;">{$escapedPreheader}</div>

        <!-- 📧 EMAIL-ONLY nested <table> layout exception - see this function's docblock -->
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f4f4;">
            <tr>
                <td align="center" style="padding:24px 12px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; width:100%;">
                        <tr>
                            <td class="email-card" style="background-color:#ffffff; border-radius:8px; padding:32px; font-family:Arial, Helvetica, sans-serif; color:#222222;">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                    <tr>
                                        <td style="padding-bottom:20px; border-bottom:3px solid #2c7a7b;">
                                            <span style="font-size:22px; font-weight:bold; color:#2c7a7b;">CueRCode</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding-top:24px; font-size:15px; line-height:1.5;">
                                            {$innerBodyHtml}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="email-muted" style="padding-top:28px; border-top:1px solid #e0e0e0; font-size:12px; color:#888888;">
                                            &copy; {$currentYear} MWBM Partners Ltd &middot; CueRCode.net
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>
    HTML;
}

// ==============================================================================
// ✅ isValidEmailTemplateKey() - Template-key allowlist validation
// ==============================================================================

/**
 * ✅ Validate a template key against the known, allowlisted set of templates.
 *
 * Defence in depth: renderEmailTemplate() builds a filesystem path directly
 * from $key, so this allowlist (rather than only a character-class check)
 * prevents both directory traversal AND requiring an unintended-but-otherwise
 * "safe-looking" file from ever being reachable via a caller-supplied key.
 *
 * @param string $key The template key to validate (e.g. 'verification').
 *
 * @return bool True if $key is a known, safe template identifier.
 */
function isValidEmailTemplateKey(string $key): bool
{
    // 📋 The complete set of shipped templates (Epic E8). Extend this array
    // (and add the matching _includes/email_templates/{key}.php file) when a
    // new template is introduced - callers can never reach an unlisted file.
    $allowedKeys = [
        'verification',
        'password_reset',
        'invitation',
        'receipt',
    ];

    if (!in_array($key, $allowedKeys, true)) {
        return false;
    }

    // 🔒 Belt-and-braces: even an allowlisted key must be a plain lowercase
    // slug (defends against the allowlist itself ever being loosened to a
    // pattern check without this function being revisited).
    if (preg_match('/^[a-z0-9_]+$/', $key) !== 1) {
        return false;
    }

    return true;
}

// ==============================================================================
// 🎨 renderEmailTemplate() - Render a template file to [subject, htmlBody]
// ==============================================================================

/**
 * 🎨 Render an email template to a [subject, htmlBody] pair.
 *
 * Loads _includes/email_templates/{key}.php and executes it with $params
 * available as a local variable. Each template file is expected to `return
 * [$subject, $htmlBody];` - it must NOT echo output directly. Templates are
 * responsible for escaping every interpolated parameter via emailEscape()
 * (or htmlspecialchars()) themselves; this function does not sanitise the
 * $params array on the caller's behalf, since values differ per-template
 * (plain names vs. URLs vs. amounts).
 *
 * Pure/DB-independent: this function never touches the database or network -
 * it only reads a local template file and evaluates PHP. Safe to call from
 * both the queueing path (to compute the subject at insert time) and the
 * sending path (to compute the fresh body at send time).
 *
 * @param string $key    The template key (validated against an allowlist).
 * @param array  $params Named parameters passed into the template's scope.
 *
 * @return array{0: string, 1: string} [$subject, $htmlBody]
 *
 * @throws \InvalidArgumentException If $key is not an allowlisted template.
 * @throws \RuntimeException         If the template file is missing or does
 *                                    not return the expected [subject, body]
 *                                    shape.
 *
 * @see isValidEmailTemplateKey()
 * @see https://www.php.net/manual/en/function.require.php
 */
function renderEmailTemplate(string $key, array $params): array
{
    if (!isValidEmailTemplateKey($key)) {
        throw new \InvalidArgumentException('Unknown or invalid email template key: ' . $key);
    }

    $templatePath = CUERCODE_EMAIL_TEMPLATES_DIR . $key . '.php';

    if (!is_file($templatePath)) {
        throw new \RuntimeException('Email template file not found: ' . $templatePath);
    }

    // 🧰 A tiny closure keeps $params as the ONLY variable visible to the
    // required template (PHP's require/include shares the enclosing scope,
    // so wrapping it in a dedicated closure prevents this function's other
    // local variables - $key, $templatePath - from leaking into templates,
    // and vice versa).
    $renderTemplate = function (array $params) use ($templatePath): mixed {
        return require $templatePath;
    };

    $rendered = $renderTemplate($params);

    if (!is_array($rendered) || !array_key_exists(0, $rendered) || !array_key_exists(1, $rendered)) {
        throw new \RuntimeException(
            'Email template "' . $key . '" did not return a [subject, htmlBody] array'
        );
    }

    return [(string) $rendered[0], (string) $rendered[1]];
}

// ==============================================================================
// 📊 logEmailAttempt() - Shared activity-log helper (NEVER logs body or creds)
// ==============================================================================

/**
 * 📊 Log a single email send attempt to tblActivityLog.
 *
 * Records the recipient address, subject, and a short outcome detail. Never
 * receives or logs the rendered HTML body, the Graph access token, or any of
 * the graph_* credential settings - callers must not pass those in $detail.
 *
 * @param string $activityType One of 'email_sent' | 'email_failed' | 'email_skipped'.
 * @param string $toAddress    Recipient email address.
 * @param string $subject      Email subject line.
 * @param string $detail       Short, non-sensitive outcome detail (HTTP status,
 *                              Graph error code, or a skip reason - never a body).
 *
 * @return void
 *
 * @see fn_activity_log.php - logActivity()
 */
function logEmailAttempt(string $activityType, string $toAddress, string $subject, string $detail): void
{
    if (function_exists('logActivity')) {
        logActivity(
            $activityType,
            'To: ' . $toAddress . ' | Subject: ' . $subject . ' | ' . $detail,
            'email',
            null,
            ['to' => $toAddress]
        );
    } else {
        // ⚠️ Activity logging unavailable (e.g. DB not reachable) - fall back
        // to the PHP error log rather than losing the record entirely.
        error_log('CueRCode Email [' . $activityType . ']: ' . $toAddress . ' | ' . $detail);
    }
}

// ==============================================================================
// 🔑 getGraphAccessToken() - OAuth2 client-credentials grant (cached)
// ==============================================================================

/**
 * 🔑 Obtain (and cache) a Microsoft Graph access token via client-credentials.
 *
 * Reads tenant/client credentials from tblSettings (isSensitive=1, decrypted
 * transparently by getConfig()). If ANY of them is unset/empty - the normal
 * "not configured yet" state for a fresh install - this returns null
 * IMMEDIATELY, without attempting a network call. This is not treated as an
 * error: the whole point of the kill-switch design is that an unconfigured
 * email subsystem never breaks the rest of the application.
 *
 * A previously-obtained token is cached (as JSON: {"access_token":...,
 * "expires_at": unix_timestamp}) in the `graph_token_cache` setting
 * (isSensitive=1, so it is encrypted at rest same as any other credential).
 * The cached token is reused until fewer than
 * CUERCODE_GRAPH_TOKEN_REFRESH_MARGIN_SECONDS remain before its expiry.
 *
 * @return string|null The bearer access token, or null if unconfigured/unavailable.
 *
 * @see https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-client-creds-grant-flow
 * @see https://www.php.net/manual/en/function.curl-setopt-array.php
 */
function getGraphAccessToken(): ?string
{
    // 🚦 If the configuration subsystem itself is not loaded (e.g. a bare test
    // harness, or a very early bootstrap failure), treat email as unconfigured.
    if (!function_exists('getConfig')) {
        return null;
    }

    // 📥 Fetch the three required app-registration credentials.
    $tenantID     = trim((string) getConfig('graph_tenant_id', ''));
    $clientID     = trim((string) getConfig('graph_client_id', ''));
    $clientSecret = trim((string) getConfig('graph_client_secret', ''));

    // ❌ Fail-open: any missing credential means "not configured yet" - this
    // is the expected state for a fresh install (all three seed EMPTY), so we
    // return null quietly rather than throwing or attempting a request.
    if ($tenantID === '' || $clientID === '' || $clientSecret === '') {
        return null;
    }

    // 🗄️ Check the cached token first.
    $cached = getConfig('graph_token_cache', []);

    if (is_array($cached) && isset($cached['access_token'], $cached['expires_at'])) {
        $expiresAt = (int) $cached['expires_at'];

        // ⏰ Reuse the cached token while it still has more than the refresh
        // margin left before expiry.
        if (($expiresAt - time()) > CUERCODE_GRAPH_TOKEN_REFRESH_MARGIN_SECONDS) {
            return (string) $cached['access_token'];
        }
    }

    // 🔄 No usable cached token - request a fresh one via the client-credentials
    // grant. This is the ONLY place in this file that calls the token endpoint.
    $tokenURL = sprintf(CUERCODE_GRAPH_TOKEN_ENDPOINT_TEMPLATE, rawurlencode($tenantID));

    $postFields = http_build_query([
        'client_id'     => $clientID,
        'client_secret' => $clientSecret,
        'scope'         => CUERCODE_GRAPH_SCOPE,
        'grant_type'    => 'client_credentials',
    ]);

    $ch = curl_init();

    if ($ch === false) {
        logEmailAttempt('email_failed', '(token request)', '(n/a)', 'curl_init() failed for Graph token request');
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_URL            => $tokenURL,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => CUERCODE_GRAPH_CURL_TIMEOUT_SECONDS,
        CURLOPT_CONNECTTIMEOUT => CUERCODE_GRAPH_CURL_TIMEOUT_SECONDS,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        // 🔒 Never relax TLS verification, even for a "just testing" call.
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $responseBody = curl_exec($ch);
    $curlErrno    = curl_errno($ch);
    $curlError    = curl_error($ch);
    $httpCode     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // ❌ Transport-level failure (DNS, TLS, timeout, connection refused, ...).
    // NEVER log $postFields (contains the client secret) - only the curl error text.
    if ($curlErrno !== 0 || $responseBody === false) {
        logEmailAttempt(
            'email_failed',
            '(token request)',
            '(n/a)',
            'Graph token request transport error (curl errno ' . $curlErrno . '): ' . $curlError
        );
        return null;
    }

    $decoded = json_decode((string) $responseBody, true);

    // ❌ Non-200 or an unexpected response shape. NEVER log the raw response
    // body - it can echo back a `client_secret` fragment in some Entra ID
    // error payloads. Log only the HTTP status.
    if ($httpCode !== 200 || !is_array($decoded) || !isset($decoded['access_token'], $decoded['expires_in'])) {
        logEmailAttempt('email_failed', '(token request)', '(n/a)', 'Graph token request failed (HTTP ' . $httpCode . ')');
        return null;
    }

    $accessToken = (string) $decoded['access_token'];
    $expiresIn   = (int) $decoded['expires_in'];
    $expiresAt   = time() + $expiresIn;

    // 💾 Persist the fresh token to the encrypted cache setting so the next
    // call (within this or a later request) can reuse it without another
    // round trip. Failing to cache is non-fatal - we still return the token
    // we just obtained.
    if (function_exists('setConfig')) {
        $cachePayload = json_encode(
            ['access_token' => $accessToken, 'expires_at' => $expiresAt],
            JSON_UNESCAPED_SLASHES
        );

        if ($cachePayload !== false) {
            setConfig('graph_token_cache', $cachePayload, [
                'group'       => 'email',
                'type'        => 'json',
                'isSensitive' => true,
                'isEditable'  => false,
                'description' => 'Runtime OAuth2 access-token cache for MS Graph (auto-managed; do not edit)',
            ]);
        }
    }

    return $accessToken;
}

// ==============================================================================
// 📤 sendEmailNow() - Immediate Graph sendMail call
// ==============================================================================

/**
 * 📤 Send a single email immediately via Microsoft Graph sendMail.
 *
 * Fails OPEN at every gate, in order:
 *   1. Configuration subsystem not loaded            -> skipped
 *   2. `email_enabled` = 0 (kill-switch off)          -> skipped
 *   3. No Graph access token available (unconfigured
 *      or the token request itself failed)            -> skipped
 *   4. `graph_sender_mailbox` not set                 -> skipped
 *   5. curl transport failure                          -> failed
 *   6. Graph returns a non-202 response                -> failed
 * A "skipped" outcome is NOT an error - it is the expected, safe behaviour for
 * an unconfigured install. Only transport/API failures are logged as 'failed'.
 *
 * @param string      $to       Recipient email address.
 * @param string      $subject  Email subject line.
 * @param string      $htmlBody Fully-rendered HTML body (already escaped by
 *                                the caller/template - this function does not
 *                                re-escape it, since it is HTML by design).
 * @param string|null $toName   Optional recipient display name.
 *
 * @return array{0: bool, 1: string|null} [$ok, $error] - $error is null on success.
 *
 * @see https://learn.microsoft.com/en-us/graph/api/user-sendmail
 */
function sendEmailNow(string $to, string $subject, string $htmlBody, ?string $toName = null): array
{
    // 🚦 Gate 1: configuration subsystem must be loaded.
    if (!function_exists('getConfig')) {
        return [false, 'skipped: configuration subsystem not loaded'];
    }

    // 🚦 Gate 2: master kill-switch.
    $emailEnabled = (bool) getConfig('email_enabled', false);

    if (!$emailEnabled) {
        logEmailAttempt('email_skipped', $to, $subject, 'email_enabled=0 (kill-switch off)');
        return [false, 'skipped: email_enabled is 0'];
    }

    // 🚦 Gate 3: a usable access token (also covers "credentials unset").
    $accessToken = getGraphAccessToken();

    if ($accessToken === null) {
        logEmailAttempt('email_skipped', $to, $subject, 'no Graph access token available (credentials unset or token request failed)');
        return [false, 'skipped: no Graph access token available'];
    }

    // 🚦 Gate 4: the shared mailbox to send from/as.
    $senderMailbox = trim((string) getConfig('graph_sender_mailbox', ''));

    if ($senderMailbox === '') {
        logEmailAttempt('email_skipped', $to, $subject, 'graph_sender_mailbox is not configured');
        return [false, 'skipped: graph_sender_mailbox not configured'];
    }

    $fromName = trim((string) getConfig('email_from_name', ''));

    // 📦 Build the Graph sendMail JSON payload.
    // @see https://learn.microsoft.com/en-us/graph/api/user-sendmail
    $toRecipient = ['emailAddress' => ['address' => $to]];

    if ($toName !== null && $toName !== '') {
        $toRecipient['emailAddress']['name'] = $toName;
    }

    $message = [
        'subject'      => $subject,
        'body'         => [
            'contentType' => 'HTML',
            'content'     => $htmlBody,
        ],
        'toRecipients' => [$toRecipient],
    ];

    if ($fromName !== '') {
        $message['from'] = [
            'emailAddress' => [
                'address' => $senderMailbox,
                'name'    => $fromName,
            ],
        ];
    }

    $payload = json_encode(
        ['message' => $message, 'saveToSentItems' => true],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($payload === false) {
        logEmailAttempt('email_failed', $to, $subject, 'Failed to JSON-encode the sendMail request payload');
        return [false, 'failed: could not encode request payload'];
    }

    $sendURL = sprintf(CUERCODE_GRAPH_SENDMAIL_ENDPOINT_TEMPLATE, rawurlencode($senderMailbox));

    $ch = curl_init();

    if ($ch === false) {
        logEmailAttempt('email_failed', $to, $subject, 'curl_init() failed for Graph sendMail request');
        return [false, 'failed: could not initialise curl'];
    }

    curl_setopt_array($ch, [
        CURLOPT_URL            => $sendURL,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => CUERCODE_GRAPH_CURL_TIMEOUT_SECONDS,
        CURLOPT_CONNECTTIMEOUT => CUERCODE_GRAPH_CURL_TIMEOUT_SECONDS,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        // 🔒 Never relax TLS verification.
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $responseBody = curl_exec($ch);
    $curlErrno    = curl_errno($ch);
    $curlError    = curl_error($ch);
    $httpCode     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // ✅ Graph's sendMail action returns HTTP 202 Accepted with an EMPTY body
    // on success - there is no message ID to capture synchronously.
    if ($curlErrno === 0 && $httpCode === 202) {
        logEmailAttempt('email_sent', $to, $subject, 'Graph sendMail accepted (HTTP 202)');
        return [true, null];
    }

    // ❌ Failure path - build a short, non-sensitive error detail. We only
    // surface the HTTP status and (if present) Graph's short machine error
    // code - never the raw response body, which could echo request content.
    $errorDetail = 'HTTP ' . $httpCode;

    if ($curlErrno !== 0) {
        $errorDetail .= ' | transport error (errno ' . $curlErrno . '): ' . $curlError;
    }

    $decodedError = json_decode((string) $responseBody, true);

    if (is_array($decodedError) && isset($decodedError['error']['code'])) {
        $errorDetail .= ' | graph_error_code: ' . (string) $decodedError['error']['code'];
    }

    logEmailAttempt('email_failed', $to, $subject, $errorDetail);

    return [false, $errorDetail];
}

// ==============================================================================
// 📥 queueEmail() - Insert a new row into tblEmailQueue
// ==============================================================================

/**
 * 📥 Queue an email for later delivery via processEmailQueue().
 *
 * Renders the template immediately (so the subject is known and visible for
 * admin inspection/debugging, and so an invalid template key is rejected at
 * queue time rather than silently at send time), then inserts a 'queued' row
 * with `nextAttemptAt = NOW()` so it is immediately eligible for pickup.
 *
 * The rendered HTML body is deliberately NOT stored - only $params (as JSON)
 * is persisted, and the body is re-rendered fresh from the template at send
 * time by processEmailQueue(). This keeps the stored row small, keeps a
 * template change able to affect not-yet-sent queued rows, and avoids ever
 * persisting a fully-rendered HTML blob that could itself carry stale/PII
 * data for longer than necessary.
 *
 * @param string      $to         Recipient email address.
 * @param string      $templateKey Template identifier (validated against the
 *                                  isValidEmailTemplateKey() allowlist).
 * @param array       $params     Parameters to pass to the template at render time.
 * @param string|null $subject    Optional subject override; when null/empty,
 *                                  the template's own rendered subject is used.
 * @param string|null $toName     Optional recipient display name.
 *
 * @return int|false The new tblEmailQueue.emailID, or false on failure
 *                     (invalid template key, render failure, or DB error).
 *
 * @see processEmailQueue()
 */
function queueEmail(string $to, string $templateKey, array $params, ?string $subject = null, ?string $toName = null): int|false
{
    // ✅ Reject unknown/invalid template keys up front - never insert a row
    // that processEmailQueue() could not later render.
    if (!isValidEmailTemplateKey($templateKey)) {
        logEmailAttempt('email_failed', $to, (string) $subject, 'Invalid template key at queue time: ' . $templateKey);
        return false;
    }

    // 🎨 Render now purely to derive the subject (and to fail fast on a
    // broken template) - the body itself is re-rendered at send time.
    try {
        [$renderedSubject, ] = renderEmailTemplate($templateKey, $params);
    } catch (\Throwable $e) {
        logEmailAttempt('email_failed', $to, (string) $subject, 'Template render failed at queue time: ' . $e->getMessage());
        return false;
    }

    if ($subject !== null && $subject !== '') {
        $finalSubject = $subject;
    } else {
        $finalSubject = $renderedSubject;
    }

    // 🗄️ DB-dependent from here - fail open if dbQuery() is unavailable.
    if (!function_exists('dbQuery')) {
        error_log('CueRCode Email: cannot queue email - dbQuery() is unavailable (no database connection)');
        return false;
    }

    $paramsEncoded = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($paramsEncoded === false) {
        $paramsEncoded = '{}';
    }

    try {
        $stmt = dbQuery(
            'INSERT INTO `tblEmailQueue` '
            . '(`toAddress`, `toName`, `templateKey`, `paramsJSON`, `subject`, `status`, `nextAttemptAt`) '
            . "VALUES (?, ?, ?, ?, ?, 'queued', NOW())",
            'sssss',
            [$to, $toName, $templateKey, $paramsEncoded, $finalSubject]
        );

        $newEmailID = (int) $stmt->insert_id;
        $stmt->close();

        logEmailAttempt('email_queued', $to, $finalSubject, 'Queued (emailID ' . $newEmailID . ')');

        return $newEmailID;
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Email queue insert failed', $e->getMessage());
        }
        return false;
    }
}

// ==============================================================================
// 📈 computeEmailBackoffMinutes() - Pure exponential backoff calculation
// ==============================================================================

/**
 * 📈 Compute the exponential backoff delay (in minutes) after a failed send.
 *
 * Formula: 2 ^ attempts minutes, where $attempts is the attempt COUNT AFTER
 * incrementing for the failure that just occurred (i.e. call this with the
 * new `attempts` value, not the old one). So: 1st failure -> 2 min, 2nd -> 4
 * min, 3rd -> 8 min, 4th -> 16 min, 5th -> 32 min, etc.
 *
 * Pure function - no I/O, no side effects - so it is fully unit-testable
 * without a database connection.
 *
 * @param int $attemptsAfterFailure The `attempts` counter value AFTER this failure.
 *
 * @return int Minutes to wait before the next attempt is eligible.
 */
function computeEmailBackoffMinutes(int $attemptsAfterFailure): int
{
    // 🛡️ Clamp the exponent defensively - even if maxAttempts were ever raised
    // far beyond the default of 5, this prevents an absurd/overflowing delay.
    $clampedAttempts = max(0, min($attemptsAfterFailure, 20));

    return (int) (2 ** $clampedAttempts);
}

// ==============================================================================
// 🔁 processEmailQueue() - Claim, send, and settle due queue rows
// ==============================================================================

/**
 * 🔁 Process up to $max due rows from tblEmailQueue.
 *
 * Selects rows where status IN ('queued', 'failed') AND nextAttemptAt <= NOW()
 * AND attempts < maxAttempts, claims each one (status -> 'sending', guarded by
 * an affected-rows check so two concurrent processor runs cannot both send the
 * same row), renders its template fresh, and calls sendEmailNow(). On success
 * the row becomes 'sent'; on failure, attempts is incremented and either the
 * row goes back to 'queued' with an exponential backoff `nextAttemptAt`, or -
 * once attempts reaches maxAttempts - the row is marked permanently 'failed'.
 *
 * Fail-open throughout: every DB/network operation is wrapped so a single
 * bad row (or a total DB outage) can never throw out of this function - it
 * always returns a summary array, even if that summary is all zeros.
 *
 * @param int $max Maximum number of rows to process in this call (default 2,
 *                  matching the ≤2-per-request budget documented for the
 *                  opportunistic shutdown-hook processor).
 *
 * @return array{processed: int, sent: int, failed: int, skipped: int} Summary counts.
 *
 * @see maybeProcessEmailQueueOnShutdown()
 */
function processEmailQueue(int $max = 2): array
{
    $summary = [
        'processed' => 0,
        'sent'      => 0,
        'failed'    => 0,
        'skipped'   => 0,
    ];

    // 🗄️ DB-dependent - fail open immediately if unavailable.
    if (!function_exists('dbQuery')) {
        return $summary;
    }

    // 🧮 Clamp $max to a sane range - never select an unbounded batch.
    $max = max(1, min($max, 50));

    // 🔎 Select due rows: queued/failed, past their nextAttemptAt, still
    // under the per-row attempt budget.
    try {
        $selectStmt = dbQuery(
            'SELECT `emailID`, `toAddress`, `toName`, `templateKey`, `paramsJSON`, `subject`, `attempts`, `maxAttempts` '
            . 'FROM `tblEmailQueue` '
            . "WHERE `status` IN ('queued', 'failed') "
            . 'AND (`nextAttemptAt` IS NULL OR `nextAttemptAt` <= NOW()) '
            . 'AND `attempts` < `maxAttempts` '
            . 'ORDER BY `nextAttemptAt` ASC '
            . 'LIMIT ?',
            'i',
            [$max]
        );

        $result = $selectStmt->get_result();
        $dueRows = [];

        while ($row = $result->fetch_assoc()) {
            $dueRows[] = $row;
        }

        $selectStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Email queue select failed', $e->getMessage());
        }
        return $summary;
    }

    // 🔁 Process each due row independently - one bad row must never abort the batch.
    foreach ($dueRows as $row) {
        $summary['processed']++;

        $emailID     = (int) $row['emailID'];
        $attempts    = (int) $row['attempts'];
        $maxAttempts = (int) $row['maxAttempts'];

        // 🔒 Claim this row atomically: only proceed if WE flipped it from
        // queued/failed to 'sending'. If affected_rows is 0, another
        // concurrent processor run already claimed it - skip.
        try {
            $claimStmt = dbQuery(
                "UPDATE `tblEmailQueue` SET `status` = 'sending' "
                . "WHERE `emailID` = ? AND `status` IN ('queued', 'failed')",
                'i',
                [$emailID]
            );
            $claimed = ($claimStmt->affected_rows === 1);
            $claimStmt->close();
        } catch (\Throwable $e) {
            $summary['skipped']++;
            continue;
        }

        if (!$claimed) {
            $summary['skipped']++;
            continue;
        }

        // 🎨 Render the template fresh at send time (never reuses a stale
        // stored body - honours any template change since this row was queued).
        $paramsDecoded = json_decode((string) $row['paramsJSON'], true);

        if (!is_array($paramsDecoded)) {
            $paramsDecoded = [];
        }

        try {
            [, $htmlBody] = renderEmailTemplate((string) $row['templateKey'], $paramsDecoded);
        } catch (\Throwable $e) {
            settleEmailQueueFailure($emailID, $attempts, $maxAttempts, 'Template render failed at send time: ' . $e->getMessage());
            $summary['failed']++;
            continue;
        }

        // 📤 Attempt the actual send.
        [$ok, $error] = sendEmailNow((string) $row['toAddress'], (string) $row['subject'], $htmlBody, $row['toName']);

        if ($ok) {
            settleEmailQueueSuccess($emailID);
            $summary['sent']++;
        } else {
            settleEmailQueueFailure($emailID, $attempts, $maxAttempts, (string) $error);
            $summary['failed']++;
        }
    }

    return $summary;
}

// ==============================================================================
// ✅ settleEmailQueueSuccess() - Mark a queue row as sent
// ==============================================================================

/**
 * ✅ Mark a tblEmailQueue row as successfully sent.
 *
 * @param int $emailID The row to update.
 *
 * @return void
 */
function settleEmailQueueSuccess(int $emailID): void
{
    if (!function_exists('dbQuery')) {
        return;
    }

    try {
        $stmt = dbQuery(
            "UPDATE `tblEmailQueue` SET `status` = 'sent', `sentAt` = NOW(), `lastError` = NULL "
            . 'WHERE `emailID` = ?',
            'i',
            [$emailID]
        );
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Email queue success-settle failed', $e->getMessage());
        }
    }
}

// ==============================================================================
// ❌ settleEmailQueueFailure() - Increment attempts + apply backoff, or fail permanently
// ==============================================================================

/**
 * ❌ Record a failed send attempt and either reschedule (with backoff) or
 * permanently fail the row once maxAttempts is reached.
 *
 * @param int    $emailID           The row to update.
 * @param int    $attemptsBefore    The `attempts` value BEFORE this failure.
 * @param int    $maxAttempts       The row's configured maximum attempts.
 * @param string $errorDetail       Short, non-sensitive error detail to store.
 *
 * @return void
 *
 * @see computeEmailBackoffMinutes()
 */
function settleEmailQueueFailure(int $emailID, int $attemptsBefore, int $maxAttempts, string $errorDetail): void
{
    if (!function_exists('dbQuery')) {
        return;
    }

    $attemptsAfter = $attemptsBefore + 1;

    if ($attemptsAfter >= $maxAttempts) {
        // 🛑 Budget exhausted - permanently failed. No further nextAttemptAt.
        try {
            $stmt = dbQuery(
                "UPDATE `tblEmailQueue` SET `status` = 'failed', `attempts` = ?, `lastError` = ? "
                . 'WHERE `emailID` = ?',
                'isi',
                [$attemptsAfter, $errorDetail, $emailID]
            );
            $stmt->close();
        } catch (\Throwable $e) {
            if (function_exists('logError')) {
                logError('error', 'Email queue permanent-failure settle failed', $e->getMessage());
            }
        }

        return;
    }

    // 🔁 Still under budget - reschedule with exponential backoff.
    $backoffMinutes = computeEmailBackoffMinutes($attemptsAfter);

    try {
        $stmt = dbQuery(
            "UPDATE `tblEmailQueue` SET `status` = 'queued', `attempts` = ?, `lastError` = ?, "
            . '`nextAttemptAt` = DATE_ADD(NOW(), INTERVAL ? MINUTE) '
            . 'WHERE `emailID` = ?',
            'isii',
            [$attemptsAfter, $errorDetail, $backoffMinutes, $emailID]
        );
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Email queue backoff-settle failed', $e->getMessage());
        }
    }
}

// ==============================================================================
// ⏱️ maybeProcessEmailQueueOnShutdown() - Gated, OPT-IN shutdown-hook processor
// ==============================================================================

/**
 * ⏱️ Register an opportunistic queue-processing shutdown hook - GATED.
 *
 * This registers register_shutdown_function() to call processEmailQueue(2)
 * AFTER the response has been sent to the client, but ONLY when BOTH:
 *   - `email_enabled`            = 1  (the subsystem is actually configured)
 *   - `email_queue_autoprocess`  = 1  (the owner explicitly opted into this
 *                                       request-shutdown processing mode)
 * are true. Both settings seed to 0, so calling this function on a fresh
 * install is a complete no-op.
 *
 * ⚠️ NOT wired into bootstrap.php by design (per the Epic E8 task spec) - the
 * core request path stays untouched unless an entry point explicitly calls
 * this function after loading fn_email.php. The RECOMMENDED production
 * approach on Dreamhost shared hosting is a panel cron job hitting a small
 * dedicated queue-processor script instead (no SSH required) - see
 * DEV_NOTES.md "Email Subsystem (Microsoft Graph)" for both wiring options.
 *
 * @return void
 */
function maybeProcessEmailQueueOnShutdown(): void
{
    if (!function_exists('getConfig')) {
        return;
    }

    try {
        $emailEnabled = (bool) getConfig('email_enabled', false);
        $autoProcess  = (bool) getConfig('email_queue_autoprocess', false);
    } catch (\Throwable $e) {
        // 🛡️ Fail-open: a config lookup failure must never prevent a normal
        // request from completing, and must never register a hook we can't
        // be sure is correctly gated.
        return;
    }

    if (!$emailEnabled || !$autoProcess) {
        return;
    }

    // @see https://www.php.net/manual/en/function.register-shutdown-function.php
    register_shutdown_function(static function (): void {
        try {
            processEmailQueue(2);
        } catch (\Throwable $e) {
            // 🛡️ The HTTP response has already been sent by the time shutdown
            // functions run - a failure here must be logged, never surfaced.
            if (function_exists('logError')) {
                logError('error', 'Shutdown-time email queue processing failed', $e->getMessage());
            }
        }
    });
}
