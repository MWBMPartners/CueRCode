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
 * 📬 WHO EMPTIES THE QUEUE (#112): queueEmail() only ever writes a row into
 * tblEmailQueue. The rows are sent by the command-line runner
 * _includes/cron/process_email_queue.php, which a DreamHost panel cron job
 * runs every minute. Before #112 nothing called the send code at all, so no
 * queued email was ever sent and no sign-up could ever be verified. The
 * page-shutdown hook maybeProcessEmailQueueOnShutdown() is a second, OFF by
 * default, fallback for hosts without cron. Both paths share one batch
 * function, runEmailQueueBatch(), which is built so that two runs at the same
 * time can never send the same email twice (see the #112 sections below).
 *
 * 🧹 KEEPING ONLY WHAT IS NEEDED (#122, #123): a queued sign-up or
 * password-reset email carries a one-time link in its stored details
 * (tblEmailQueue.paramsJSON). So:
 *   - the details are cleared in the same database update that marks an
 *     email sent or skipped, and 24 hours after an email is given up (so the
 *     owner can still re-send it after an outage);
 *   - old email records are deleted: sent and skipped ones after 30 days,
 *     given-up ones after 90 days (two settings);
 *   - an email whose one-time link has expired (or has under 2 minutes left)
 *     is marked "skipped" instead of being sent;
 *   - all of this is done by the queue runner, while it holds its lock and
 *     before it sends anything (runEmailQueueHousekeeping()).
 * Email addresses are also kept out of PHP's error log (#126, fn_log_mask.php).
 *
 * @package     CueRCode
 * @subpackage  Email
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.2.0
 * @since       Phase 2 (Epic E8)
 * @see         https://learn.microsoft.com/en-us/graph/auth-v2-service
 * @see         https://learn.microsoft.com/en-us/graph/api/user-sendmail
 * @see         https://www.php.net/manual/en/book.curl.php
 * @see         fn_config.php   - getConfig()/setConfig() (isSensitive encryption)
 * @see         fn_activity_log.php - logActivity()
 * @see         fn_log_mask.php - cleanLogText(), the shared log-masking rule (#126)
 * @see         _includes/cron/process_email_queue.php - the scheduled queue runner (#112)
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

// 🙈 #126: the shared log-masking helpers (cleanLogText(), maskEmailAddresses()).
//    The cron runner loads THIS file on its own, BEFORE the site start-up code
//    (bootstrap.php), and the tests load it alone too - so it loads its own
//    helper here rather than relying on bootstrap.php. require_once works from
//    the real file path, so bootstrap.php's later load of the same file does
//    nothing.
require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_log_mask.php';

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
        // 🙈 #126: that log is the plain-text channel (on the command line, the
        //    cron email), so the address is hidden by cleanLogText() - it
        //    becomes "[address]" - along with any control characters. The
        //    outcome detail (HTTP status, Graph error code, skip reason) is kept
        //    because that is the useful part. Before #126 this line printed the
        //    raw recipient address.
        error_log('CueRCode Email [' . cleanLogText($activityType, 50) . ']: ' . cleanLogText($toAddress . ' | ' . $detail, 1000));
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

    // 🧹 No curl_close($ch) here - PHP releases the handle automatically
    // once $ch goes out of scope. curl_close() has been a no-op since PHP
    // 8.0 and PHP 8.5 warns when it is called (#117) - do not add it back.

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

    // 🧹 No curl_close($ch) here - PHP releases the handle automatically
    // once $ch goes out of scope. curl_close() has been a no-op since PHP
    // 8.0 and PHP 8.5 warns when it is called (#117) - do not add it back.

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
 * 📥 Queue an email for later delivery by the queue runner (runEmailQueueBatch(),
 * run every minute by _includes/cron/process_email_queue.php - #112).
 *
 * Renders the template immediately (so the subject is known and visible for
 * admin inspection/debugging, and so an invalid template key is rejected at
 * queue time rather than silently at send time), then inserts a 'queued' row
 * with `nextAttemptAt = NOW()` so it is immediately eligible for pickup.
 *
 * The rendered HTML body is deliberately NOT stored - only $params (as JSON)
 * is persisted, and the body is re-rendered fresh from the template at send
 * time by runEmailQueueBatch(). This keeps the stored row small, keeps a
 * template change able to affect not-yet-sent queued rows, and avoids ever
 * persisting a fully-rendered HTML blob that could itself carry stale/PII
 * data for longer than necessary.
 *
 * The parameters can include a one-time link, so they are cleared when the
 * email is sent or skipped, and 24 hours after it is given up (#122). An
 * email whose link has expired before it could be sent is skipped (#123).
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
 * @see runEmailQueueBatch()
 */
function queueEmail(string $to, string $templateKey, array $params, ?string $subject = null, ?string $toName = null): int|false
{
    // ✅ Reject unknown/invalid template keys up front - never insert a row
    // that the queue runner (runEmailQueueBatch()) could not later render.
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
// 📬 EMAIL QUEUE RUNNER (#112) - everything from here to the end of the file
// ==============================================================================
// Before #112 nothing ever called processEmailQueue(), so every queued email
// (sign-up verification, password reset) sat in tblEmailQueue for ever and an
// email-and-password sign-up could never be completed. This part of the file
// adds the pieces that actually send the queue, in five layers:
//
//   1. Pure helpers (no database, no network) - fully unit-tested.
//   2. runEmailQueueBatch() - the ONE place the "claim, send, record the
//      result" logic lives. It takes its database operations and its sender
//      as passed-in callables (a "gateway"), so the tests can drive it with an
//      in-memory stand-in for the table. The test harness has no database and
//      five other test files assert that dbQuery()/getConfig() do NOT exist,
//      so faking those globals was never an option.
//   3. The real database operations - one small function per SQL statement.
//   4. emailQueueRunnerMain() - the testable body of the command-line runner
//      _includes/cron/process_email_queue.php (run by cron every minute).
//   5. processEmailQueue() / maybeProcessEmailQueueOnShutdown() - the older
//      entry points, now thin wrappers over the same batch logic.
//   6. Clean-up (#122/#123) - runEmailQueueHousekeeping(), run inside
//      runEmailQueueBatch() ONLY while it holds the lock, and BEFORE anything
//      is claimed: it skips emails whose one-time link has expired, clears the
//      stored details of given-up emails after 24 hours, and deletes old
//      finished records. A sent or skipped email loses its details in the
//      same statement that finishes it.
//
// 🛡️ Three layers stop the same email being sent twice:
//   - Layer 1, the row claim (the real guarantee): the UPDATE that marks a row
//     'sending' re-checks EVERYTHING - status, "is it due now?" and "has it
//     attempts left?" - in one statement, and every later update only applies
//     while the row is still 'sending'. Only the runner that claimed a row can
//     finish it. This works even with no lock at all.
//   - Layer 2, a named MySQL lock per database: only one runner works through
//     the queue at a time, which also keeps the sending rate down.
//   - Layer 3, stuck-row recovery: a row left 'sending' for more than 10
//     minutes (the runner died part-way) is put back, counted as one attempt.
//
// ⚠️ What this CANNOT do: it is "at least once", not "exactly once". If
// Microsoft accepts an email but the runner dies (or the database fails)
// before it records "sent", the row is recovered after 10 minutes and sent
// again. Recovery counts that as an attempt, so repeats are capped - in
// practice at most one extra copy. Graph's HTTP 202 means "accepted", not
// "delivered"; bounces are not tracked.
//
// ⏳ Expired links (#123): an email whose one-time link has expired - or has
// less than 2 minutes left - by the time it could be sent (typically after an
// outage) is marked 'skipped' instead of being sent, because it would only
// deliver a dead link. The age is measured on the DATABASE's clock from
// `createdAt`, against fn_auth.php's own link lifetimes. The person has to ask
// for a new link.
//
// 🗂️ Alternatives considered and rejected (so nobody re-introduces them):
//   - A new claimToken/lockedUntil column: needs a schema change, and there is
//     no upgrade script (#113), so every install would need a manual ALTER.
//     The existing `updatedAt` column already records when a row was claimed.
//   - SELECT ... FOR UPDATE SKIP LOCKED: needs MySQL 8.0.1+/MariaDB 10.6+ (the
//     README promises MySQL 5.7+/MariaDB 10.3+), and would hold a transaction
//     open across a 10-second web call.
//   - flock() on a lock file: only works on one machine, and DreamHost home
//     folders are network storage where flock() is unreliable.
//   - The named lock alone: any caller that forgot it, or a host where named
//     locks misbehave, would re-open the duplicate hole. The claim must stand
//     on its own.
//   - Row claims alone: safe from duplicates, but parallel runners double the
//     sending rate and stuck-row recovery could race a slow-but-alive runner.
//   - Re-using nextAttemptAt as a "lease" while sending: overloads the
//     column's meaning; updatedAt already records the claim time.
//   - Looping for ~50 seconds inside each cron minute: keeps a process and a
//     database connection busy ~80% of the time on shared hosting (DreamHost
//     reaps long-running processes) for a saving of under a minute of wait.
// ==============================================================================

// ==============================================================================
// ⚙️ Email queue runner constants (#112)
// ==============================================================================

/**
 * @var int How many emails one cron run tries to send by default (--limit).
 * One run a minute at up to 20 emails stays under Exchange Online's sending
 * limit of roughly 30 messages a minute, and still clears any realistic burst
 * of sign-ups within a few minutes.
 */
if (!defined('CUERCODE_EMAIL_QUEUE_DEFAULT_BATCH')) {
    define('CUERCODE_EMAIL_QUEUE_DEFAULT_BATCH', 20);
}

/**
 * @var int The largest batch any caller may ask for. Larger requests are
 * clamped down to this, never obeyed, so a typo in a cron line cannot turn
 * into a burst that trips Microsoft's rate limit.
 */
if (!defined('CUERCODE_EMAIL_QUEUE_MAX_BATCH')) {
    define('CUERCODE_EMAIL_QUEUE_MAX_BATCH', 50);
}

/**
 * @var int Batch size for the OFF-by-default page-shutdown fallback. Kept at
 * 2, matching the behaviour documented before #112, because the fallback runs
 * at the end of an ordinary page visit.
 */
if (!defined('CUERCODE_EMAIL_QUEUE_SHUTDOWN_BATCH')) {
    define('CUERCODE_EMAIL_QUEUE_SHUTDOWN_BATCH', 2);
}

/**
 * @var int A row left 'sending' for longer than this many minutes is treated
 * as stuck (its runner died part-way) and is put back. The worst legitimate
 * "sending" time is about 20 seconds typically and about 80 seconds at the
 * very worst (two 10-second curl timeouts plus a 60-second database read
 * timeout), so 10 minutes is a wide safety margin against recovering a row
 * that a slow-but-alive runner is still sending.
 */
if (!defined('CUERCODE_EMAIL_QUEUE_STUCK_MINUTES')) {
    define('CUERCODE_EMAIL_QUEUE_STUCK_MINUTES', 10);
}

/**
 * @var int The most stuck rows one run will put back. Bounds the work a
 * single run does after a long outage; the rest are picked up next minute.
 */
if (!defined('CUERCODE_EMAIL_QUEUE_STUCK_BATCH')) {
    define('CUERCODE_EMAIL_QUEUE_STUCK_BATCH', 50);
}

/**
 * @var int Seconds a cron run may spend before it stops claiming new emails.
 * Keeps each run comfortably inside its one-minute slot. An email already
 * being sent when the budget runs out is finished, not abandoned.
 */
if (!defined('CUERCODE_EMAIL_QUEUE_TIME_BUDGET_SECONDS')) {
    define('CUERCODE_EMAIL_QUEUE_TIME_BUDGET_SECONDS', 45);
}

/**
 * @var int The (smaller) time budget for the page-shutdown fallback, which
 * runs at the end of a visitor's page request.
 */
if (!defined('CUERCODE_EMAIL_QUEUE_SHUTDOWN_TIME_BUDGET_SECONDS')) {
    define('CUERCODE_EMAIL_QUEUE_SHUTDOWN_TIME_BUDGET_SECONDS', 20);
}

/**
 * @var string Fixed first part of the named MySQL lock's name. The rest of the
 * name is worked out inside MySQL - see CUERCODE_EMAIL_QUEUE_LOCK_NAME_SQL.
 */
if (!defined('CUERCODE_EMAIL_QUEUE_LOCK_PREFIX')) {
    define('CUERCODE_EMAIL_QUEUE_LOCK_PREFIX', 'cuercode_emailq:');
}

/**
 * @var string SQL expression that builds the queue lock's name.
 *
 * 🔒 WHY it includes the database's identity: MySQL lock names are shared by
 * EVERY database on the same MySQL server, and DreamHost's MySQL servers are
 * shared between customers. A plain 'cuercode_emailq' would collide with any
 * other CueRCode install (or anything else using that name) on the same
 * server. DATABASE() is read from the live connection, so it is always the
 * database this runner is actually writing to.
 *
 * 📏 WHY it is hashed: MySQL limits lock names to 64 characters. The prefix
 * (16 characters) plus the first 32 hex characters of a SHA-256 hash = 48.
 *
 * 🔐 WHY it is safe to join this into SQL text: it is a fixed constant with no
 * user input in it. The prefix is still passed as a bound `?` parameter. This
 * is the ONLY piece of text joined into SQL anywhere in the queue code.
 */
if (!defined('CUERCODE_EMAIL_QUEUE_LOCK_NAME_SQL')) {
    define('CUERCODE_EMAIL_QUEUE_LOCK_NAME_SQL', "CONCAT(?, LEFT(SHA2(COALESCE(DATABASE(), ''), 256), 32))");
}

/**
 * @var list<string> The operations every queue "gateway" must provide. The
 * production gateway (emailQueueDatabaseGateway()) maps each one to a real
 * database function; the tests map each one to an in-memory stand-in.
 *
 * The last five were added by #122/#123 (the clean-up and the "never send"
 * guard). They are APPENDED, never inserted, so the order of the #112 ones is
 * unchanged; the tests check this list against the production gateway.
 */
if (!defined('CUERCODE_EMAIL_QUEUE_GATEWAY_KEYS')) {
    define('CUERCODE_EMAIL_QUEUE_GATEWAY_KEYS', [
        'lock',
        'unlock',
        'findStuck',
        'recoverStuck',
        'findDue',
        'claim',
        'load',
        'markSent',
        'markRetry',
        'markGaveUp',
        'release',
        'countDue',
        'countOverdue',
        'countByStatus',
        'markSkipped',      // 🛑 #122/#123: a claimed email that must never be sent
        'skipExpired',      // ⏳ #123: waiting emails whose link has expired
        'clearGivenUp',     // 🧹 #122: clear given-up emails' details after 24 hours
        'purgeFinished',    // 🗑️ #122: delete old sent/skipped records
        'purgeGivenUp',     // 🗑️ #122: delete old given-up records
    ]);
}

/**
 * @var string lastError text stored on a stuck row that is put back for
 * another try. Written in plain English because the owner reads it in
 * phpMyAdmin.
 */
if (!defined('CUERCODE_EMAIL_QUEUE_NOTE_STUCK_RETRY')) {
    define(
        'CUERCODE_EMAIL_QUEUE_NOTE_STUCK_RETRY',
        'Recovered: an earlier run stopped part-way through sending this email, so it may or may not have been delivered. Counted as one attempt; retrying.'
    );
}

/**
 * @var string lastError text stored on a stuck row that had no attempts left.
 */
if (!defined('CUERCODE_EMAIL_QUEUE_NOTE_STUCK_GAVE_UP')) {
    define(
        'CUERCODE_EMAIL_QUEUE_NOTE_STUCK_GAVE_UP',
        'Gave up: an earlier run stopped part-way through sending this email on its last allowed attempt.'
    );
}

// ==============================================================================
// 🧹 Clean-up and expired links (#122, #123)
// ==============================================================================
// 📌 Order matters: the margin must be defined before the expired-link note
//    that quotes it.

/**
 * @var int An email is skipped once its one-time link has LESS than this many
 * seconds left (#123). A run can take over a minute before it reaches a given
 * email (a 45-second claim budget plus up to two 10-second Graph calls), and
 * an email that arrives with seconds left is as useless as one that arrives
 * dead. Rejected: no margin at all (the runner could still send a link that
 * dies mid-run).
 */
if (!defined('CUERCODE_EMAIL_QUEUE_LINK_EXPIRY_MARGIN_SECONDS')) {
    define('CUERCODE_EMAIL_QUEUE_LINK_EXPIRY_MARGIN_SECONDS', 120);
}

/**
 * @var int Hours a GIVEN-UP email keeps its stored details (and so its
 * one-time link), counted from when it was queued (#122, owner decision 2).
 * That is the window in which the owner can still re-send it after an outage
 * (DEV_NOTES.md re-send runbook): there is no "send me a new link" button, so
 * without it a person whose sign-up email was caught by an outage would be
 * locked out for good. 24 hours equals the longest link lifetime, so after
 * that no re-send could help anyway. Switching to "remove at once" later
 * means changing this one number (and the docs).
 */
if (!defined('CUERCODE_EMAIL_QUEUE_RESEND_WINDOW_HOURS')) {
    define('CUERCODE_EMAIL_QUEUE_RESEND_WINDOW_HOURS', 24);
}

/**
 * @var int The most rows ONE clean-up statement touches per run. Keeps each
 * statement short, so its row locks are held only briefly; anything left over
 * is handled on the next run (a minute later with cron).
 */
if (!defined('CUERCODE_EMAIL_QUEUE_TIDY_BATCH')) {
    define('CUERCODE_EMAIL_QUEUE_TIDY_BATCH', 500);
}

/**
 * @var int Default days to keep a SENT or SKIPPED email record before it is
 * deleted (#122, owner decision 1). Long enough to answer "I never got my
 * email" questions. Used when the email_queue_retention_days setting is
 * missing (every install from before #122) or not a valid number.
 */
if (!defined('CUERCODE_EMAIL_QUEUE_RETENTION_DAYS_DEFAULT')) {
    define('CUERCODE_EMAIL_QUEUE_RETENTION_DAYS_DEFAULT', 30);
}

/**
 * @var int Default days to keep a GIVEN-UP (failed) email record (#122, owner
 * decision 1). Longer than sent ones, because given-up emails are the ones
 * support gets asked about later, and there are few of them.
 */
if (!defined('CUERCODE_EMAIL_QUEUE_GIVEN_UP_RETENTION_DAYS_DEFAULT')) {
    define('CUERCODE_EMAIL_QUEUE_GIVEN_UP_RETENTION_DAYS_DEFAULT', 90);
}

/**
 * @var int The largest retention (in days) that is accepted - ten years.
 * Anything above is treated as a typo and the default is used. There is
 * deliberately NO "keep forever" value: keeping personal data only as long as
 * needed is the point of #122.
 */
if (!defined('CUERCODE_EMAIL_QUEUE_RETENTION_DAYS_MAX')) {
    define('CUERCODE_EMAIL_QUEUE_RETENTION_DAYS_MAX', 3650);
}

/** @var string tblSettings key: days to keep sent/skipped email records (#122). */
if (!defined('CUERCODE_EMAIL_QUEUE_SETTING_RETENTION_DAYS')) {
    define('CUERCODE_EMAIL_QUEUE_SETTING_RETENTION_DAYS', 'email_queue_retention_days');
}

/**
 * @var string tblSettings key: days to keep given-up email records (#122).
 * Named "failed" because that is the status the owner sees in phpMyAdmin.
 */
if (!defined('CUERCODE_EMAIL_QUEUE_SETTING_GIVEN_UP_RETENTION_DAYS')) {
    define('CUERCODE_EMAIL_QUEUE_SETTING_GIVEN_UP_RETENTION_DAYS', 'email_queue_failed_retention_days');
}

/**
 * @var array<string, string> The link parameter each template REQUIRES
 * (taken from each template's own docblock). The templates quietly render an
 * EMPTY link when it is missing, rather than failing, so the runner checks for
 * it and skips the email instead of sending it broken (#122). Keep in step
 * with isValidEmailTemplateKey(): a new template that carries a link needs an
 * entry here. 'receipt' carries no link, so it has none.
 */
if (!defined('CUERCODE_EMAIL_QUEUE_REQUIRED_LINK_PARAMS')) {
    define('CUERCODE_EMAIL_QUEUE_REQUIRED_LINK_PARAMS', [
        'verification'   => 'verificationUrl',
        'password_reset' => 'resetUrl',
        'invitation'     => 'inviteUrl',
    ]);
}

/**
 * @var string lastError text stored on an email skipped because its one-time
 * link had expired, or nearly (#123). Plain English: the owner reads it in
 * phpMyAdmin.
 */
if (!defined('CUERCODE_EMAIL_QUEUE_NOTE_LINK_EXPIRED')) {
    define(
        'CUERCODE_EMAIL_QUEUE_NOTE_LINK_EXPIRED',
        'Not sent: its one-time link had expired, or had less than '
            . intdiv(CUERCODE_EMAIL_QUEUE_LINK_EXPIRY_MARGIN_SECONDS, 60)
            . ' minutes left, before it could be sent. The person needs to ask for a new link.'
    );
}

/**
 * @var string lastError text stored on a claimed email whose stored details
 * had already been removed (#122) - for example a cleared row put back to
 * 'queued' by hand. It can never be sent.
 */
if (!defined('CUERCODE_EMAIL_QUEUE_NOTE_DETAILS_REMOVED')) {
    define(
        'CUERCODE_EMAIL_QUEUE_NOTE_DETAILS_REMOVED',
        'Not sent: its stored details (including any one-time link) had already been removed, so it can no longer be sent. The person needs to ask for a new email.'
    );
}

/**
 * @var string lastError text stored on a claimed email whose template needs a
 * one-time link that is missing or empty (#122). Sending it would only deliver
 * a broken email.
 */
if (!defined('CUERCODE_EMAIL_QUEUE_NOTE_LINK_MISSING')) {
    define(
        'CUERCODE_EMAIL_QUEUE_NOTE_LINK_MISSING',
        'Not sent: the one-time link this email needs is missing, so it would only have delivered a broken email.'
    );
}

/**
 * @var int Database read timeout (seconds) the command-line runner sets for
 * its own connection. PHP's default (mysqlnd.net_read_timeout) is 86400
 * seconds - 24 hours - so one hung read would otherwise hold the queue lock,
 * and stop all email, for a whole day.
 */
if (!defined('CUERCODE_EMAIL_RUNNER_DB_READ_TIMEOUT_SECONDS')) {
    define('CUERCODE_EMAIL_RUNNER_DB_READ_TIMEOUT_SECONDS', 60);
}

/**
 * @var int Connection timeout (seconds, default_socket_timeout) the runner
 * sets before it connects, so an unreachable database fails in seconds.
 */
if (!defined('CUERCODE_EMAIL_RUNNER_CONNECT_TIMEOUT_SECONDS')) {
    define('CUERCODE_EMAIL_RUNNER_CONNECT_TIMEOUT_SECONDS', 15);
}

// 🚦 Exit codes of the command-line runner. Cron emails the owner whenever a
//    job prints something, and monitoring tools read the exit code, so each
//    kind of problem gets its own number. When several apply, the most
//    serious wins: 3 (database) > 4 (sending blocked / stalled) > 5 (gave up).

/** @var int Finished normally (includes "nothing to send", "email switched off" and "another run is already working"). */
if (!defined('CUERCODE_EMAIL_RUNNER_EXIT_OK')) {
    define('CUERCODE_EMAIL_RUNNER_EXIT_OK', 0);
}

/** @var int Could not start (for example a missing credentials file) or crashed unexpectedly. */
if (!defined('CUERCODE_EMAIL_RUNNER_EXIT_CRASH')) {
    define('CUERCODE_EMAIL_RUNNER_EXIT_CRASH', 1);
}

/** @var int A bad command-line option. */
if (!defined('CUERCODE_EMAIL_RUNNER_EXIT_USAGE')) {
    define('CUERCODE_EMAIL_RUNNER_EXIT_USAGE', 2);
}

/** @var int A database problem (cannot connect, queue table unreadable, lock error). */
if (!defined('CUERCODE_EMAIL_RUNNER_EXIT_DATABASE')) {
    define('CUERCODE_EMAIL_RUNNER_EXIT_DATABASE', 3);
}

/** @var int Sending is blocked by the settings or by Microsoft Graph, or an earlier run looks stuck ("stalled"). */
if (!defined('CUERCODE_EMAIL_RUNNER_EXIT_EMAIL_SETUP')) {
    define('CUERCODE_EMAIL_RUNNER_EXIT_EMAIL_SETUP', 4);
}

/** @var int Finished, but gave up on at least one email after all its attempts. */
if (!defined('CUERCODE_EMAIL_RUNNER_EXIT_GAVE_UP')) {
    define('CUERCODE_EMAIL_RUNNER_EXIT_GAVE_UP', 5);
}

// ==============================================================================
// 🧮 Pure helpers (#112) - no database, no network, fully unit-tested
// ==============================================================================

/**
 * 🧮 Decide what happens to an email after one failed send attempt.
 *
 * The rule is identical to the one settleEmailQueueFailure() used before #112
 * (retry with back-off until attempts reaches maxAttempts, then give up). It
 * was pulled out into this pure function so it can be tested without a
 * database, and so the batch, stuck-row recovery and the kept-for-
 * compatibility settleEmailQueueFailure() all share ONE copy of the rule.
 *
 * With the default of 5 attempts the waits are 2, 4, 8 and 16 minutes; the
 * 5th failure gives up without waiting. So a message that keeps failing is
 * given up about 30 minutes after its first failure (not the "~62 minutes"
 * the notes said before #112).
 *
 * @param int $attemptsBefore The row's `attempts` value BEFORE this failure
 *                            (negative values are treated as 0).
 * @param int $maxAttempts    The row's `maxAttempts` value.
 *
 * @return array{attemptsAfter: int, giveUp: bool, retryInMinutes: int|null}
 *               retryInMinutes is null when giveUp is true.
 *
 * @see computeEmailBackoffMinutes()
 */
function decideEmailQueueFailure(int $attemptsBefore, int $maxAttempts): array
{
    $attemptsAfter = max(0, $attemptsBefore) + 1;

    if ($attemptsAfter >= $maxAttempts) {
        // 🛑 That was the last allowed attempt - give up for good.
        return [
            'attemptsAfter'  => $attemptsAfter,
            'giveUp'         => true,
            'retryInMinutes' => null,
        ];
    }

    // 🔁 Attempts left - wait 2 ^ attemptsAfter minutes before the next try.
    return [
        'attemptsAfter'  => $attemptsAfter,
        'giveUp'         => false,
        'retryInMinutes' => computeEmailBackoffMinutes($attemptsAfter),
    ];
}

/**
 * 🏷️ Sort a failed send into one of three kinds, because each needs a
 * different response.
 *
 *   'system'    - sending is BLOCKED for every email, not just this one: the
 *                 switch is off, settings are missing, there is no Graph token,
 *                 Graph refused our credentials (401), our permission (403) or
 *                 the sender mailbox (404), or curl could not even start. The
 *                 email is put back WITHOUT using an attempt and the run stops,
 *                 so a set-up mistake can no longer burn through every queued
 *                 email's retries (#112 - before, "not configured yet" turned
 *                 every queued email into a permanent failure within about 30
 *                 minutes).
 *   'transient' - temporary trouble at Microsoft or on the network (HTTP 0,
 *                 408, 429, 5xx, a transport error, or the sender throwing). An
 *                 attempt is used, with back-off, and the run stops early so we
 *                 do not hammer a struggling service or burn one attempt on
 *                 every queued email in the same minute.
 *   'message'   - something about THIS email (for example HTTP 400, a bad
 *                 recipient). An attempt is used and the batch carries on.
 *
 * 📌 404 counts as 'system' because for sendMail the only address in the web
 * address is the SENDER mailbox (/users/{sender}/sendMail). A wrong recipient
 * comes back as 400 (ErrorInvalidRecipients), not 404.
 *
 * The strings matched here are exactly the ones sendEmailNow() produces, plus
 * the one runEmailQueueBatch() builds when a sender throws. If sendEmailNow()'s
 * wording ever changes, this function and its tests must change with it.
 *
 * @param string|null $error The error text from the sender (null/empty is
 *                           treated as a message-specific failure).
 *
 * @return string 'system' | 'transient' | 'message'
 *
 * @see sendEmailNow()
 */
function classifyEmailSendError(?string $error): string
{
    if ($error === null) {
        return 'message';
    }

    $text = trim($error);

    if ($text === '') {
        return 'message';
    }

    // 🚫 Blocked for every email: switched off / not configured / no token.
    if (str_starts_with($text, 'skipped:')) {
        return 'system';
    }

    // 🚫 curl itself could not start - nothing can be sent from this server.
    if (str_starts_with($text, 'failed: could not initialise curl')) {
        return 'system';
    }

    // 🚫 Graph refused our credentials, our permission, or our sender mailbox.
    //    The \b stops "HTTP 4040" style numbers matching.
    if (preg_match('/^HTTP (401|403|404)\b/', $text) === 1) {
        return 'system';
    }

    // ⏳ Temporary trouble: no HTTP answer at all (0), timeout (408), rate
    //    limited (429) or a server-side error (5xx).
    if (preg_match('/^HTTP (0|408|429|5[0-9][0-9])\b/', $text) === 1) {
        return 'transient';
    }

    // ⏳ A network-level failure reported by curl.
    if (str_contains($text, 'transport error')) {
        return 'transient';
    }

    // ⏳ The sender threw instead of returning - treat as temporary trouble.
    if (str_starts_with($text, 'failed: the sender stopped with an unexpected error')) {
        return 'transient';
    }

    // ✉️ Anything else is about this one message.
    return 'message';
}

/**
 * 🧽 Make a piece of text safe to store in lastError or to print from the
 * command-line runner.
 *
 * It removes control characters (so a crafted value cannot forge extra lines
 * in a cron email or a log), replaces anything that looks like an email
 * address with "[address]" (a backstop - the runner never deliberately prints
 * an address, but an error message from a library might contain one, such as
 * a database user "name@host"), collapses runs of whitespace, and cuts the
 * result to a maximum length.
 *
 * ⚠️ It CANNOT recognise every kind of secret. Callers must still never pass
 * email bodies, tokens, passwords or setting values in the first place.
 *
 * #126: now delegates to cleanLogText() (fn_log_mask.php), so the activity
 * logger, the error logger and the queue runner share ONE copy of the rule.
 * The behaviour is unchanged (same steps, same order, same results - the
 * #112 tests for this function pass unedited). Only the default length
 * differs: 200 here, because the runner's lines are short.
 *
 * @param string $text      The text to clean.
 * @param int    $maxLength Maximum length in characters (at least 20).
 *
 * @return string The cleaned text ('' if a regular expression failed).
 *
 * @see cleanLogText()
 */
function emailQueueSafeDetail(string $text, int $maxLength = 200): string
{
    return cleanLogText($text, $maxLength);
}

/**
 * 🔧 Check whether the email settings are good enough for the runner to send.
 *
 * Returns the NAMES of problem settings only - never their values. A
 * sensitive setting that cannot be decrypted (for example because
 * ENCRYPTION_KEY changed after it was saved) comes back from getConfig() as
 * its default '', which is why the wording says "is empty or could not be
 * read".
 *
 * The problem list is ALWAYS worked out, even when email is switched off, so
 * `--check` can show the owner what is still missing before they switch on.
 *
 * @param callable $getSetting Called as $getSetting(string $key, mixed $default)
 *                             - getConfig in production, a stand-in in tests.
 *
 * @return array{state: string, enabled: bool, problems: list<string>}
 *               state is 'disabled' | 'incomplete' | 'ready'.
 */
function checkEmailQueueRunnerSettings(callable $getSetting): array
{
    $enabled  = (bool) $getSetting('email_enabled', false);
    $problems = [];

    // 🔌 Microsoft Graph is the only sender built so far (#30 will add Google
    //    Workspace). An empty value is treated as the seeded default 'graph'.
    //    The value itself is never printed - only the fact that it is wrong.
    $providerValue = $getSetting('email_provider', 'graph');

    if (is_scalar($providerValue)) {
        $provider = strtolower(trim((string) $providerValue));
    } else {
        $provider = 'unreadable';
    }

    if ($provider === '') {
        $provider = 'graph';
    }

    if ($provider !== 'graph') {
        $problems[] = 'email_provider is not "graph" (Microsoft Graph is the only sender built so far)';
    }

    // 🔑 The four settings sendEmailNow() needs before it can send anything.
    $requiredKeys = [
        'graph_tenant_id',
        'graph_client_id',
        'graph_client_secret',
        'graph_sender_mailbox',
    ];

    foreach ($requiredKeys as $requiredKey) {
        $value = $getSetting($requiredKey, '');

        if (!is_scalar($value) || trim((string) $value) === '') {
            $problems[] = $requiredKey . ' is empty or could not be read';
        }
    }

    if (!$enabled) {
        $state = 'disabled';
    } elseif (count($problems) > 0) {
        $state = 'incomplete';
    } else {
        $state = 'ready';
    }

    return [
        'state'    => $state,
        'enabled'  => $enabled,
        'problems' => $problems,
    ];
}

/**
 * 🧾 Read the runner's command-line options.
 *
 * Accepted: --help / -h, --quiet / -q, --check, and --limit=N (N from 1 to
 * CUERCODE_EMAIL_QUEUE_MAX_BATCH). Anything else is refused rather than
 * ignored, so a typo in the cron line shows up instead of silently running
 * with defaults. Only the FIRST problem is reported.
 *
 * @param array $argv The raw argument list; index 0 (the script path) is skipped.
 *
 * @return array{ok: bool, error: string|null, help: bool, quiet: bool, check: bool, limit: int}
 */
function parseEmailQueueRunnerArgs(array $argv): array
{
    $parsed = [
        'ok'    => true,
        'error' => null,
        'help'  => false,
        'quiet' => false,
        'check' => false,
        'limit' => CUERCODE_EMAIL_QUEUE_DEFAULT_BATCH,
    ];

    $arguments = array_slice(array_values($argv), 1);

    foreach ($arguments as $rawArgument) {
        $argument = (string) $rawArgument;
        $problem  = null;

        if ($argument === '--help' || $argument === '-h') {
            $parsed['help'] = true;
        } elseif ($argument === '--quiet' || $argument === '-q') {
            $parsed['quiet'] = true;
        } elseif ($argument === '--check') {
            $parsed['check'] = true;
        } elseif (str_starts_with($argument, '--limit=')) {
            $limitText = substr($argument, strlen('--limit='));

            // 🔢 Digits only (at most 3), then range-checked - so "5x", "-1",
            //    "1e2" and an empty value are all refused.
            if (preg_match('/^[0-9]{1,3}$/', $limitText) === 1
                && (int) $limitText >= 1
                && (int) $limitText <= CUERCODE_EMAIL_QUEUE_MAX_BATCH
            ) {
                $parsed['limit'] = (int) $limitText;
            } else {
                $problem = 'the --limit value must be a whole number from 1 to ' . CUERCODE_EMAIL_QUEUE_MAX_BATCH;
            }
        } else {
            // 🧽 The unknown option is echoed back, but cleaned and cut short.
            $problem = 'unknown option: ' . emailQueueSafeDetail($argument, 40);
        }

        if ($problem !== null) {
            $parsed['ok'] = false;

            if ($parsed['error'] === null) {
                $parsed['error'] = $problem;
            }
        }
    }

    return $parsed;
}

/**
 * 📖 The runner's help text, in plain English.
 *
 * Lines are deliberately NOT indented: every printed line passes through
 * emailQueueSafeDetail(), which collapses whitespace, so indentation would be
 * lost anyway.
 *
 * @return string The help text, lines joined with PHP_EOL.
 */
function emailQueueRunnerUsage(): string
{
    $lines = [
        'CueRCode email queue runner - sends the emails waiting in tblEmailQueue (sign-up verification, password reset and so on).',
        '',
        'Usage:',
        'php process_email_queue.php [--quiet] [--limit=N]',
        'php process_email_queue.php --check',
        'php process_email_queue.php --help',
        '',
        'Options:',
        '--quiet (or -q): print nothing unless something needs attention. Recommended for the cron job.',
        '--limit=N: send at most N emails in this run (1 to ' . CUERCODE_EMAIL_QUEUE_MAX_BATCH . ', default ' . CUERCODE_EMAIL_QUEUE_DEFAULT_BATCH . ').',
        '--check: test the database and the email settings and show the queue counts, without sending anything.',
        '--help (or -h): show this help.',
        '',
        'Exit codes:',
        CUERCODE_EMAIL_RUNNER_EXIT_OK . ' = finished normally (including nothing to send, email switched off, or another run already working).',
        CUERCODE_EMAIL_RUNNER_EXIT_CRASH . ' = could not start (for example a missing _auth_keys/auth_creds.php) or stopped by an unexpected error.',
        CUERCODE_EMAIL_RUNNER_EXIT_USAGE . ' = a bad command-line option.',
        CUERCODE_EMAIL_RUNNER_EXIT_DATABASE . ' = a database problem (cannot connect, queue table unreadable, lock error), or the clean-up of old email records failed (sending is not affected).',
        CUERCODE_EMAIL_RUNNER_EXIT_EMAIL_SETUP . ' = sending is blocked by the settings or by Microsoft Graph, or an earlier run seems stuck.',
        CUERCODE_EMAIL_RUNNER_EXIT_GAVE_UP . ' = finished, but gave up on at least one email after all its attempts.',
        '',
        'It also tidies up: one-time links are removed once an email is sent, emails whose link has expired are skipped, and old email records are deleted - see DEV_NOTES.md, "Email queue clean-up".',
        '',
        'Run it from a DreamHost cron job every minute - see DEV_NOTES.md, "Email queue runner".',
    ];

    return implode(PHP_EOL, $lines);
}

/**
 * 📋 A fresh, all-zero result for one runEmailQueueBatch() call.
 *
 * The tests depend on these exact keys, so add to them rather than renaming.
 * The last five (expired ... housekeepingFailures) were APPENDED by #122/#123.
 *
 * @return array{
 *     status: string, problem: string|null,
 *     processed: int, claimed: int, sent: int, retrying: int, gaveUp: int, released: int,
 *     skipped: int, recovered: int, recordFailures: int, waiting: int|null,
 *     stoppedEarly: bool, stopReason: string|null, stopDetail: string|null, stuckMinutes: int,
 *     expired: int, unsendable: int, detailsCleared: int, purged: int, housekeepingFailures: int
 * }
 *   status is 'ok' | 'busy' | 'stalled' | 'lock_error' | 'db_error' | 'system_problem'.
 *   problem is a safe one-line sentence whenever status is not ok/busy.
 *   Note: 'skipped' (a #112 key) means "could not claim or re-read" - it is NOT
 *   the same as the 'skipped' STATUS a row gets from #122/#123, which is
 *   counted in 'expired' and 'unsendable'.
 */
function emptyEmailQueueRunResult(): array
{
    return [
        'status'         => 'ok',
        'problem'        => null,
        'processed'      => 0,     // 📋 due rows we tried to claim
        'claimed'        => 0,     // 🔒 rows we actually claimed
        'sent'           => 0,     // ✅ accepted by the sender
        'retrying'       => 0,     // 🔁 failed, will be retried later
        'gaveUp'         => 0,     // 🛑 failed for the last time (including stuck rows given up)
        'released'       => 0,     // ↩️ put back unchanged because sending is blocked
        'skipped'        => 0,     // ⏭️ could not claim (another runner got it / no longer due) or could not re-read
        'recovered'      => 0,     // 🧯 stuck rows put back for another try
        'recordFailures' => 0,     // ⚠️ results we could not write to the table
        'waiting'        => null,  // ⏳ due rows left after the run (null = unknown)
        'stoppedEarly'   => false,
        'stopReason'     => null,  // 'time_budget' | 'temporary_problem'
        'stopDetail'     => null,
        'stuckMinutes'   => CUERCODE_EMAIL_QUEUE_STUCK_MINUTES,
        'expired'              => 0,  // ⏳ #123: waiting emails skipped because their link had expired (clean-up step + per-email check)
        'unsendable'           => 0,  // 🛑 #122: claimed emails skipped because their details were removed or their link is missing
        'detailsCleared'       => 0,  // 🧹 #122: given-up emails whose stored details were cleared
        'purged'               => 0,  // 🗑️ #122: old finished records deleted
        'housekeepingFailures' => 0,  // ⚠️ #122/#123: clean-up steps that failed (database error)
    ];
}

/**
 * 🚦 Turn a batch result into the runner's exit code.
 *
 * The most serious problem wins: database (3) > sending blocked or stalled (4)
 * > gave up on an email (5) > fine (0).
 *
 * #122/#123: a failed clean-up step also gives 3. It is a database problem
 * that needs fixing - otherwise old records and one-time links would be kept
 * longer than promised - even though sending itself still happened. Skipped
 * emails (expired links, removed details) do NOT change the exit code; they
 * are reported instead.
 *
 * @param array $result A runEmailQueueBatch() result (an older-shaped result
 *                      without housekeepingFailures is accepted).
 *
 * @return int One of the CUERCODE_EMAIL_RUNNER_EXIT_* codes.
 */
function emailQueueRunnerExitCode(array $result): int
{
    if ($result['status'] === 'lock_error'
        || $result['status'] === 'db_error'
        || (isset($result['housekeepingFailures']) && (int) $result['housekeepingFailures'] > 0)
    ) {
        return CUERCODE_EMAIL_RUNNER_EXIT_DATABASE;
    }

    if ($result['status'] === 'system_problem' || $result['status'] === 'stalled') {
        return CUERCODE_EMAIL_RUNNER_EXIT_EMAIL_SETUP;
    }

    if ((int) $result['gaveUp'] > 0) {
        return CUERCODE_EMAIL_RUNNER_EXIT_GAVE_UP;
    }

    return CUERCODE_EMAIL_RUNNER_EXIT_OK;
}

/**
 * 📝 Turn a batch result into the plain-English lines the runner prints.
 *
 * Counts only: these lines NEVER contain an address, a subject, a body, a
 * token, a setting value or anybody's ID, because cron emails them to the
 * owner and they may end up in logs.
 *
 * @param array $result  A runEmailQueueBatch() result.
 * @param float $seconds How long the run took.
 *
 * @return list<string> The lines to print, in order.
 */
function formatEmailQueueRunReport(array $result, float $seconds): array
{
    $lines = [];

    if ($result['status'] === 'busy') {
        $lines[] = 'CueRCode email queue: another run is already working through the queue - nothing to do this time.';
        return $lines;
    }

    if ($result['status'] === 'stalled') {
        $lines[] = 'CueRCode email queue: ' . (string) $result['problem']
            . '. It clears by itself when that run ends or its database connection closes;'
            . ' if this keeps happening, look for a stuck PHP process on the server.';
        return $lines;
    }

    if ($result['status'] === 'lock_error' || $result['status'] === 'db_error') {
        $lines[] = 'CueRCode email queue: ' . (string) $result['problem']
            . ' - nothing more was sent (details are in the error log).';
    }

    if ($result['status'] === 'system_problem') {
        $lines[] = 'CueRCode email queue: sending is blocked - ' . (string) $result['problem']
            . '. The email was put back without using up an attempt; nothing more will be sent until this is fixed.';
    }

    // 📊 The counts line is always printed for the remaining statuses.
    if ($result['waiting'] === null) {
        $waitingText = 'number still waiting unknown';
    } else {
        $waitingText = (int) $result['waiting'] . ' still waiting';
    }

    $lines[] = sprintf(
        'CueRCode email queue: sent %d, will retry %d, gave up on %d, %s (%.1f seconds).',
        (int) $result['sent'],
        (int) $result['retrying'],
        (int) $result['gaveUp'],
        $waitingText,
        $seconds
    );

    if ((int) $result['recovered'] > 0) {
        $lines[] = 'Put back ' . (int) $result['recovered']
            . ' email(s) that an earlier run left part-way through sending (they may arrive twice).';
    }

    if ($result['stopReason'] === 'temporary_problem') {
        $lines[] = 'Stopped early: Microsoft Graph did not accept an email (' . (string) $result['stopDetail']
            . '); it will be retried automatically.';
    }

    if ($result['stopReason'] === 'time_budget') {
        $lines[] = 'Stopped early to stay within the time limit; the rest will go on the next run.';
    }

    if ((int) $result['gaveUp'] > 0) {
        $lines[] = 'Gave up on ' . (int) $result['gaveUp']
            . ' email(s) after all their attempts - they now have status "failed" in tblEmailQueue'
            . ' (DEV_NOTES.md explains how to re-send them).';
    }

    if ((int) $result['recordFailures'] > 0) {
        $lines[] = 'Could not record the result for ' . (int) $result['recordFailures']
            . ' email(s); they may be sent again after ' . (int) $result['stuckMinutes']
            . ' minutes (details are in the error log).';
    }

    // 🛑 #122: claimed emails that can never be sent (details removed, link missing).
    if (isset($result['unsendable']) && (int) $result['unsendable'] > 0) {
        $lines[] = 'Skipped ' . (int) $result['unsendable']
            . ' email(s) that can no longer be sent because their stored details (including the one-time link) were already removed or are missing.';
    }

    // 🧹 #122/#123: expired links, the clean-up totals and any clean-up failure.
    foreach (formatEmailQueueTidyLines($result) as $tidyLine) {
        $lines[] = $tidyLine;
    }

    return $lines;
}

// ==============================================================================
// 🧮 Clean-up and expired-link helpers (#122, #123) - pure, fully unit-tested
// ==============================================================================

/**
 * 🔢 Read a retention setting (days) and accept only a sensible whole number.
 *
 * Accepts an int, or a string of 1 to 5 digits (with optional spaces around
 * it), whose value is from 1 to CUERCODE_EMAIL_QUEUE_RETENTION_DAYS_MAX.
 * Anything else - including 0, negatives, decimals, "1e3", true, null and
 * words - gives null, meaning "not valid: use the default".
 *
 * 📌 WHY 0 is refused: getConfig() casts an 'integer' setting with (int), so a
 * typo such as "thirty" arrives here as 0. Treating 0 as "delete straight
 * away" would turn a typo into deleting every finished record. There is also
 * deliberately no "keep forever" value.
 *
 * @param mixed $value The raw value (from getConfig() or a batch option).
 *
 * @return int|null The number of days, or null when the value is not valid.
 */
function parseEmailQueueRetentionDays(mixed $value): ?int
{
    // 🔢 Only a whole number from 1 to CUERCODE_EMAIL_QUEUE_RETENTION_DAYS_MAX.
    //    getConfig() turns a typo into 0 for an 'integer' setting, so 0 MUST mean
    //    "not valid" - never "delete everything now". No "keep forever" value on purpose.
    if (is_int($value)) {
        $days = $value;
    } elseif (is_string($value) && preg_match('/^\s*[0-9]{1,5}\s*$/', $value) === 1) {
        $days = (int) trim($value);
    } else {
        return null;
    }

    if ($days < 1 || $days > CUERCODE_EMAIL_QUEUE_RETENTION_DAYS_MAX) {
        return null;
    }

    return $days;
}

/**
 * ⚙️ Read the two clean-up settings (#122) and fall back to the defaults.
 *
 * - A setting that is MISSING (the normal state on installs from before #122,
 *   because there is no upgrade script - #113) quietly uses the default and is
 *   not reported: nothing is wrong.
 * - A setting that is present but not valid (see parseEmailQueueRetentionDays())
 *   uses the default, and its NAME is listed in invalidSettings so `--check`
 *   can say which one to fix.
 * - A settings reader that throws is treated as "missing".
 *
 * 🔐 The VALUE of a setting is never put anywhere in the result, so nothing
 * that prints the result can ever show it.
 *
 * @param callable $getSetting Called as $getSetting(string $key, mixed $default)
 *                             - getConfig in production, a stand-in in tests.
 *
 * @return array{retentionDays: int, givenUpRetentionDays: int, invalidSettings: list<string>}
 */
function emailQueueHousekeepingOptions(callable $getSetting): array
{
    $options = [
        'retentionDays'        => CUERCODE_EMAIL_QUEUE_RETENTION_DAYS_DEFAULT,
        'givenUpRetentionDays' => CUERCODE_EMAIL_QUEUE_GIVEN_UP_RETENTION_DAYS_DEFAULT,
        'invalidSettings'      => [],
    ];

    // 📋 setting key => option name (the default is already in $options).
    $settingMap = [
        CUERCODE_EMAIL_QUEUE_SETTING_RETENTION_DAYS          => 'retentionDays',
        CUERCODE_EMAIL_QUEUE_SETTING_GIVEN_UP_RETENTION_DAYS => 'givenUpRetentionDays',
    ];

    foreach ($settingMap as $settingKey => $optionName) {
        try {
            $rawValue = $getSetting($settingKey, null);
        } catch (\Throwable $e) {
            // 🛡️ A settings reader that fails counts as "missing": the default applies.
            $rawValue = null;
        }

        if ($rawValue === null) {
            // 📭 Missing row: the default applies, and there is nothing to report.
            continue;
        }

        $parsed = parseEmailQueueRetentionDays($rawValue);

        if ($parsed === null) {
            // ⚠️ Present but not valid: keep the default and name the setting (never its value).
            $options['invalidSettings'][] = (string) $settingKey;
            continue;
        }

        $options[$optionName] = $parsed;
    }

    return $options;
}

/**
 * ⏳ The lifetime of each template's one-time link, in seconds (#123).
 *
 * These are fn_auth.php's own constants, which set the real token expiry in
 * the database (CUERCODE_EMAIL_VERIFICATION_TTL_HOURS and
 * CUERCODE_PASSWORD_RESET_TTL_MINUTES) - one place to change a lifetime.
 * They are read at RUN time, inside this function, because this file can be
 * loaded before fn_auth.php (the cron runner loads fn_email.php on its own
 * first; the tests load single files).
 *   Rejected: copying the numbers into this file (two places to change one
 *   number - they would drift apart), and moving the constants here
 *   (bootstrap.php loads this file BEFORE fn_auth.php, so fn_auth.php's own
 *   definition would silently stop taking effect).
 *
 * 'invitation' has no entry yet - nothing issues invitations; add one when
 * they are built (tblInvitations.expiresAt). 'receipt' has no link.
 *
 * ⚠️ What it CANNOT do: if fn_auth.php was not loaded it returns [], and then
 * nothing is skipped for age. `--check` says so loudly. bootstrap.php always
 * loads fn_auth.php before any batch runs.
 *
 * @return array<string, int> Seconds by template key.
 */
function emailQueueLinkLifetimes(): array
{
    $lifetimes = [];

    if (defined('CUERCODE_EMAIL_VERIFICATION_TTL_HOURS')) {
        $lifetimes['verification'] = (int) CUERCODE_EMAIL_VERIFICATION_TTL_HOURS * 3600;
    }

    if (defined('CUERCODE_PASSWORD_RESET_TTL_MINUTES')) {
        $lifetimes['password_reset'] = (int) CUERCODE_PASSWORD_RESET_TTL_MINUTES * 60;
    }

    return $lifetimes;
}

/**
 * 🧹 Keep only sensible link lifetimes: a known template key and a whole
 * number of seconds of at least 1.
 *
 * Used on both emailQueueLinkLifetimes() and the 'linkLifetimes' batch option,
 * so a malformed option can never reach the SQL (an unknown key would only
 * waste a statement; a zero or negative lifetime would skip everything).
 *
 * @param array $lifetimes Seconds by template key (anything).
 *
 * @return array<string, int> The valid entries, in their original order.
 */
function normaliseEmailQueueLinkLifetimes(array $lifetimes): array
{
    $valid = [];

    foreach ($lifetimes as $templateKey => $lifetimeSeconds) {
        if (!is_string($templateKey) || !isValidEmailTemplateKey($templateKey)) {
            continue;
        }

        if (!is_int($lifetimeSeconds) || $lifetimeSeconds < 1) {
            continue;
        }

        $valid[$templateKey] = $lifetimeSeconds;
    }

    return $valid;
}

/**
 * 📏 The age (seconds since it was queued) at which an email counts as having
 * an expired link: its lifetime minus the 2-minute safety margin (#123).
 *
 * Never below 60 seconds, so a tiny lifetime can never turn into "skip
 * everything straight away".
 *
 * @param int $lifetimeSeconds The link's lifetime.
 *
 * @return int The maximum age, in seconds.
 */
function emailQueueLinkMaxAgeSeconds(int $lifetimeSeconds): int
{
    return max(60, $lifetimeSeconds - CUERCODE_EMAIL_QUEUE_LINK_EXPIRY_MARGIN_SECONDS);
}

/**
 * ⏳ Has this email's link expired (or have fewer than 2 minutes left)?
 *
 * This is the SAME rule as the clean-up's SQL (skipExpiredEmailQueueRows()):
 * "createdAt <= NOW() - maxAge" is the same as "age >= maxAge". The age must
 * come from the DATABASE's clock (the re-read row's ageSeconds), never from
 * PHP's time(): PHP's clock follows the site_timezone setting, while the
 * database stores UTC.
 *
 * @param string $templateKey   The email's template.
 * @param int    $ageSeconds    Seconds since it was queued (database clock).
 * @param array  $linkLifetimes Seconds by template key (normalised).
 *
 * @return bool true when the email must be skipped; false for a template with
 *              no known lifetime.
 */
function emailQueueLinkHasExpired(string $templateKey, int $ageSeconds, array $linkLifetimes): bool
{
    if (!array_key_exists($templateKey, $linkLifetimes)) {
        return false;
    }

    return $ageSeconds >= emailQueueLinkMaxAgeSeconds((int) $linkLifetimes[$templateKey]);
}

/**
 * 🔗 The link parameter a template REQUIRES, or null when it needs none.
 *
 * @param string $templateKey The email's template.
 *
 * @return string|null The parameter name (for example 'verificationUrl').
 *
 * @see CUERCODE_EMAIL_QUEUE_REQUIRED_LINK_PARAMS
 */
function emailQueueRequiredLinkParam(string $templateKey): ?string
{
    if (array_key_exists($templateKey, CUERCODE_EMAIL_QUEUE_REQUIRED_LINK_PARAMS)) {
        return CUERCODE_EMAIL_QUEUE_REQUIRED_LINK_PARAMS[$templateKey];
    }

    return null;
}

/**
 * 📝 The plain-English lines describing what the clean-up did (#122/#123).
 *
 * Shared by the normal report (formatEmailQueueRunReport()) and the
 * switched-off path of emailQueueRunnerMain(). Counts only - never an
 * address, a subject, a link or a setting value. Each count is read with
 * isset() and (int), so an older-shaped result cannot crash it.
 *
 * @param array $result A runEmailQueueBatch() result.
 *
 * @return list<string> The lines, in order (empty when there is nothing to say).
 */
function formatEmailQueueTidyLines(array $result): array
{
    $expired        = 0;
    $purged         = 0;
    $detailsCleared = 0;
    $failures       = 0;

    if (isset($result['expired'])) {
        $expired = (int) $result['expired'];
    }

    if (isset($result['purged'])) {
        $purged = (int) $result['purged'];
    }

    if (isset($result['detailsCleared'])) {
        $detailsCleared = (int) $result['detailsCleared'];
    }

    if (isset($result['housekeepingFailures'])) {
        $failures = (int) $result['housekeepingFailures'];
    }

    $lines = [];

    if ($expired > 0) {
        $lines[] = 'Skipped ' . $expired . ' email(s) whose one-time link had expired, or had less than '
            . intdiv(CUERCODE_EMAIL_QUEUE_LINK_EXPIRY_MARGIN_SECONDS, 60)
            . ' minutes left, before it could be sent - the person needs to ask for a new link.';
    }

    if ($purged > 0 || $detailsCleared > 0) {
        $lines[] = 'Tidied up: deleted ' . $purged . ' old email record(s) and removed the stored details of '
            . $detailsCleared . ' given-up email(s).';
    }

    if ($failures > 0) {
        $lines[] = 'Could not finish tidying up old email records (database error - details are in the error log). Sending was not affected.';
    }

    return $lines;
}

// ==============================================================================
// 🔁 The core batch (#112) - claim, send, record, with duplicate-proof claims
// ==============================================================================

/**
 * ✅ Check that a queue gateway provides every operation the batch needs.
 *
 * A missing operation is a programming error, not something that can happen
 * at run time, so this throws rather than returning a status.
 *
 * @param array $gateway Map of operation name => callable.
 *
 * @return void
 *
 * @throws \InvalidArgumentException When an operation is missing or not callable.
 */
function validateEmailQueueGateway(array $gateway): void
{
    foreach (CUERCODE_EMAIL_QUEUE_GATEWAY_KEYS as $operationName) {
        if (!isset($gateway[$operationName]) || !is_callable($gateway[$operationName])) {
            throw new \InvalidArgumentException('Email queue gateway is missing the "' . $operationName . '" operation');
        }
    }
}

/**
 * 🧹 The queue clean-up (#122, #123): skip waiting emails whose one-time link
 * has expired, clear the stored details of given-up emails once the re-send
 * window has passed, and delete old FINISHED records.
 *
 * ⚠️ The caller MUST hold the queue lock. These are range statements (they
 * walk an index and lock a range of rows for a moment). Run at the same time
 * as ANOTHER runner's claims, InnoDB (MySQL's storage engine) could deadlock
 * one against the other, and a deadlocked claim would stop that run. The lock
 * already guarantees one runner at a time, so running the clean-up only under
 * it removes that risk. A web request queuing an email at the same moment may
 * wait a few milliseconds, but cannot deadlock: its insert holds no lock the
 * clean-up needs.
 *   Rejected: running the clean-up without the lock (the deadlock risk above);
 *   a separate "last clean-up time" setting so it runs only hourly (an extra
 *   settings write every hour and more moving parts - each statement is cheap
 *   and uses an index).
 *
 * Each step is independent: a failure (a database error, or an operation that
 * throws) is counted in 'failures' and the next step still runs. The real
 * database functions log their own errors (logError()).
 *
 * ⚠️ What it CANNOT do: each statement handles at most $tidyLimit rows per
 * run; the rest follow on the next run (a minute later with cron). It never
 * touches a row that is being sent, and only the expired-link step touches
 * waiting rows (by design).
 *
 * @param array $gateway              The queue gateway (skipExpired, clearGivenUp,
 *                                    purgeFinished and purgeGivenUp are used).
 * @param array $linkLifetimes        Seconds by template key (normalised).
 * @param int   $retentionDays        Days to keep sent/skipped records.
 * @param int   $givenUpRetentionDays Days to keep given-up records.
 * @param int   $tidyLimit            Most rows per statement.
 *
 * @return array{expired: int, detailsCleared: int, purged: int, failures: int}
 */
function runEmailQueueHousekeeping(array $gateway, array $linkLifetimes, int $retentionDays, int $givenUpRetentionDays, int $tidyLimit): array
{
    $counts = [
        'expired'        => 0,
        'detailsCleared' => 0,
        'purged'         => 0,
        'failures'       => 0,
    ];

    // 1️⃣ #123: skip WAITING emails whose link has expired (or nearly). Done
    //    first, so the batch below can never pick one of them. One statement
    //    per template that has a known lifetime.
    foreach ($linkLifetimes as $templateKey => $lifetimeSeconds) {
        try {
            $changed = $gateway['skipExpired'](
                (string) $templateKey,
                emailQueueLinkMaxAgeSeconds((int) $lifetimeSeconds),
                CUERCODE_EMAIL_QUEUE_NOTE_LINK_EXPIRED,
                $tidyLimit
            );
        } catch (\Throwable $e) {
            $changed = null;
        }

        if (is_int($changed) && $changed >= 0) {
            $counts['expired'] = $counts['expired'] + $changed;
        } else {
            $counts['failures']++;
        }
    }

    // 2️⃣ #122: clear the details of given-up emails once the re-send window has passed.
    try {
        $changed = $gateway['clearGivenUp'](CUERCODE_EMAIL_QUEUE_RESEND_WINDOW_HOURS, $tidyLimit);
    } catch (\Throwable $e) {
        $changed = null;
    }

    if (is_int($changed) && $changed >= 0) {
        $counts['detailsCleared'] = $changed;
    } else {
        $counts['failures']++;
    }

    // 3️⃣ #122: delete old FINISHED records (never waiting or in-flight ones).
    $purges = [
        'purgeFinished' => $retentionDays,
        'purgeGivenUp'  => $givenUpRetentionDays,
    ];

    foreach ($purges as $operationName => $olderThanDays) {
        try {
            $changed = $gateway[$operationName]($olderThanDays, $tidyLimit);
        } catch (\Throwable $e) {
            $changed = null;
        }

        if (is_int($changed) && $changed >= 0) {
            $counts['purged'] = $counts['purged'] + $changed;
        } else {
            $counts['failures']++;
        }
    }

    return $counts;
}

/**
 * 🔁 Work through one batch of the email queue: recover stuck rows, run the
 * clean-up, then claim, send and record each due email.
 *
 * This is the ONLY copy of the queue logic. The cron runner reaches it through
 * emailQueueRunnerMain(), and the page-shutdown fallback through
 * processEmailQueue(). The order of the steps matters and is explained inline.
 *
 * Gateway operations (see emailQueueDatabaseGateway() for the real SQL):
 *   lock(): ?bool, unlock(): void, findStuck(int $minutes, int $limit): ?array,
 *   recoverStuck(int $id, int $attemptsSeen, int $attemptsAfter, ?int $retryIn,
 *   string $note, int $minutes): bool, findDue(int $limit): ?array,
 *   claim(int $id): ?bool, load(int $id): ?array, markSent(int $id): bool,
 *   markRetry(int $id, int $attemptsAfter, int $retryIn, string $detail): bool,
 *   markGaveUp(int $id, int $attemptsAfter, string $detail): bool,
 *   release(int $id, string $reason): bool, countDue(): ?int,
 *   countOverdue(int $minutes): ?int, countByStatus(): ?array,
 *   and since #122/#123: markSkipped(int $id, string $reason): bool,
 *   skipExpired(string $templateKey, int $maxAgeSeconds, string $note, int $limit): ?int,
 *   clearGivenUp(int $olderThanHours, int $limit): ?int,
 *   purgeFinished(int $olderThanDays, int $limit): ?int,
 *   purgeGivenUp(int $olderThanDays, int $limit): ?int.
 *
 * 🧹 #122/#123: while it holds the lock, and before anything is claimed, it
 * runs the clean-up (runEmailQueueHousekeeping()). Then every claimed email
 * is checked before it is rendered: one whose details were removed, whose
 * required link is missing, or whose link has expired (measured on the
 * database's clock) is marked 'skipped' and never sent. With 'useLock' false
 * the clean-up does NOT run (it must never run alongside another runner - see
 * runEmailQueueHousekeeping()); the per-email checks still apply.
 *
 * ⚠️ What it CANNOT do: see the "at least once" note at the top of this
 * section.
 *
 * @param array    $gateway The database operations (validated first).
 * @param callable $sender  Called as $sender($to, $subject, $htmlBody, $toName)
 *                          and expected to return [bool $ok, ?string $error],
 *                          like sendEmailNow().
 * @param array    $options Optional: 'limit' (1-50, default 20),
 *                          'stuckMinutes' (1-1440, default 10),
 *                          'timeBudgetSeconds' (1-600, default 45),
 *                          'useLock' (default true; only tests and the
 *                          real-database check pass false - production never
 *                          does), 'clock' (callable returning float seconds),
 *                          and since #122/#123:
 *                          'sendEmails' (default true; false = a tidy-only run
 *                          that takes the lock, runs the clean-up and counts,
 *                          but recovers, claims and sends nothing - used while
 *                          email is switched off or its settings are incomplete),
 *                          'retentionDays' (1-3650, default 30),
 *                          'givenUpRetentionDays' (1-3650, default 90) - an
 *                          invalid value falls back to the default, never to
 *                          "delete everything now",
 *                          'linkLifetimes' (seconds by template key; default
 *                          emailQueueLinkLifetimes()),
 *                          'tidyLimit' (1-500, default 500).
 *
 * @return array A result shaped like emptyEmailQueueRunResult().
 *
 * @throws \InvalidArgumentException Only for a malformed gateway (a programming error).
 */
function runEmailQueueBatch(array $gateway, callable $sender, array $options = []): array
{
    validateEmailQueueGateway($gateway);

    // ⚙️ Options, each clamped to a safe range so a bad caller cannot ask for
    //    an unbounded batch or switch recovery off by accident.
    $limit = CUERCODE_EMAIL_QUEUE_DEFAULT_BATCH;

    if (isset($options['limit'])) {
        $limit = (int) $options['limit'];
    }

    $limit = max(1, min($limit, CUERCODE_EMAIL_QUEUE_MAX_BATCH));

    $stuckMinutes = CUERCODE_EMAIL_QUEUE_STUCK_MINUTES;

    if (isset($options['stuckMinutes'])) {
        $stuckMinutes = (int) $options['stuckMinutes'];
    }

    $stuckMinutes = max(1, min($stuckMinutes, 1440));

    $timeBudget = (float) CUERCODE_EMAIL_QUEUE_TIME_BUDGET_SECONDS;

    if (isset($options['timeBudgetSeconds'])) {
        $timeBudget = (float) $options['timeBudgetSeconds'];
    }

    $timeBudget = max(1.0, min($timeBudget, 600.0));

    // 🔒 Tests and the real-database check may switch the lock off to prove
    //    the row claim works on its own. Production never does.
    $useLock = true;

    if (array_key_exists('useLock', $options) && $options['useLock'] === false) {
        $useLock = false;
    }

    if (isset($options['clock']) && is_callable($options['clock'])) {
        $clock = $options['clock'];
    } else {
        $clock = static function (): float {
            return microtime(true);
        };
    }

    // 🧹 #122/#123 options. A bad option can never mean "delete everything
    //    now": an invalid retention falls back to the default, the per-statement
    //    cap is clamped, and the link lifetimes are filtered to known templates
    //    with a positive lifetime.
    $sendEmails = true;

    if (array_key_exists('sendEmails', $options) && $options['sendEmails'] === false) {
        $sendEmails = false;   // 🔕 tidy-only run (email switched off / settings incomplete) - see emailQueueRunnerMain()
    }

    $retentionDays = CUERCODE_EMAIL_QUEUE_RETENTION_DAYS_DEFAULT;

    if (array_key_exists('retentionDays', $options)) {
        $parsedDays = parseEmailQueueRetentionDays($options['retentionDays']);

        if ($parsedDays !== null) {
            $retentionDays = $parsedDays;
        }
    }

    $givenUpRetentionDays = CUERCODE_EMAIL_QUEUE_GIVEN_UP_RETENTION_DAYS_DEFAULT;

    if (array_key_exists('givenUpRetentionDays', $options)) {
        $parsedDays = parseEmailQueueRetentionDays($options['givenUpRetentionDays']);

        if ($parsedDays !== null) {
            $givenUpRetentionDays = $parsedDays;
        }
    }

    $tidyLimit = CUERCODE_EMAIL_QUEUE_TIDY_BATCH;

    if (isset($options['tidyLimit'])) {
        $tidyLimit = (int) $options['tidyLimit'];
    }

    $tidyLimit = max(1, min($tidyLimit, CUERCODE_EMAIL_QUEUE_TIDY_BATCH));

    if (isset($options['linkLifetimes']) && is_array($options['linkLifetimes'])) {
        $linkLifetimes = normaliseEmailQueueLinkLifetimes($options['linkLifetimes']);
    } else {
        $linkLifetimes = normaliseEmailQueueLinkLifetimes(emailQueueLinkLifetimes());
    }

    $result                 = emptyEmailQueueRunResult();
    $result['stuckMinutes'] = $stuckMinutes;
    $startedAt              = (float) $clock();
    $haveLock               = false;

    // ==========================================================================
    // 🔒 Layer 2: the named lock - only one runner works through the queue
    // ==========================================================================
    if ($useLock) {
        try {
            $lockOutcome = $gateway['lock']();
        } catch (\Throwable $e) {
            $lockOutcome = null;
        }

        if ($lockOutcome === false) {
            // 🙋 Another run holds the lock. Normally that is fine (it is
            //    simply still working), but if emails have been waiting far
            //    too long, that run may be hung while holding the lock - turn
            //    that into a loud "stalled" alarm instead of a silent stop.
            $result['status'] = 'busy';

            // 🔕 #122: while sending is switched off (a tidy-only run), emails
            //    are EXPECTED to wait, so a held lock is never a "stall" - the
            //    alarm is only raised by a run that would actually send.
            if ($sendEmails) {
                try {
                    $overdue = $gateway['countOverdue']($stuckMinutes);
                } catch (\Throwable $e) {
                    $overdue = null;
                }

                if (is_int($overdue) && $overdue > 0) {
                    $result['status']  = 'stalled';
                    $result['problem'] = 'another run is holding the queue lock, but ' . $overdue
                        . ' email(s) have been waiting for more than ' . $stuckMinutes
                        . ' minutes - that run may be stuck, or sending may be falling behind';
                }
            }

            // ⚠️ We do NOT hold the lock here, so we must never unlock it.
            return $result;
        }

        if ($lockOutcome !== true) {
            $result['status']  = 'lock_error';
            $result['problem'] = 'could not take the email queue lock (database error)';
            return $result;
        }

        $haveLock = true;
    }

    // 📌 Every "return" inside this try still runs the "finally" below, so the
    //    lock is always released - even after an unexpected error.
    try {
        // ======================================================================
        // 🧯 Layer 3: put back rows a dead runner left 'sending' (inside the lock,
        //    so this can never race a slow-but-alive runner)
        // ======================================================================
        if ($sendEmails) {
            $stuckRows = $gateway['findStuck']($stuckMinutes, CUERCODE_EMAIL_QUEUE_STUCK_BATCH);

            if (!is_array($stuckRows)) {
                $result['status']  = 'db_error';
                $result['problem'] = 'could not read the email queue (database error)';
                return $result;
            }
        } else {
            $stuckRows = [];   // 🔕 tidy-only run: nothing is recovered or claimed
        }

        $recoveredIDs = [];

        foreach ($stuckRows as $stuckRow) {
            $emailID      = (int) $stuckRow['emailID'];
            $attemptsSeen = (int) $stuckRow['attempts'];

            // 🧮 We cannot know whether Microsoft accepted the email before the
            //    runner died, so the interrupted try counts as one attempt.
            //    That caps how many times a row can be re-sent this way.
            $decision = decideEmailQueueFailure($attemptsSeen, (int) $stuckRow['maxAttempts']);

            if ($decision['giveUp']) {
                $retryIn = null;
                $note    = CUERCODE_EMAIL_QUEUE_NOTE_STUCK_GAVE_UP;
            } else {
                $retryIn = $decision['retryInMinutes'];
                $note    = CUERCODE_EMAIL_QUEUE_NOTE_STUCK_RETRY;
            }

            $recoveredNow = $gateway['recoverStuck'](
                $emailID,
                $attemptsSeen,
                $decision['attemptsAfter'],
                $retryIn,
                $note,
                $stuckMinutes
            );

            // 📌 false = the row changed under us (or a write failed) - it is
            //    simply looked at again on the next run.
            if ($recoveredNow === true) {
                $recoveredIDs[] = $emailID;

                if ($decision['giveUp']) {
                    $result['gaveUp']++;
                } else {
                    $result['recovered']++;
                }
            }
        }

        if (count($recoveredIDs) > 0 && function_exists('logError')) {
            // 📝 IDs only - never an address.
            logError('warning', 'Email queue: put back emails left part-way through sending', 'emailIDs: ' . implode(', ', $recoveredIDs));
        }

        // ======================================================================
        // 🧹 #122 / #123: the clean-up - ONLY while this run holds the queue lock,
        //    and BEFORE anything is claimed (so an expired link is skipped, never sent)
        // ======================================================================
        // Only under the lock: its range statements must never run at the same
        // time as another runner's claims (InnoDB could deadlock the two), and
        // the lock guarantees one runner at a time. So a run with useLock false
        // (tests and the real-database check only) skips it; the per-email
        // checks further down still apply. A failed step is counted and
        // reported (exit code 3) but never stops sending.
        if ($haveLock) {
            $housekeeping = runEmailQueueHousekeeping($gateway, $linkLifetimes, $retentionDays, $givenUpRetentionDays, $tidyLimit);

            $result['expired']              = $result['expired'] + $housekeeping['expired'];
            $result['detailsCleared']       = $housekeeping['detailsCleared'];
            $result['purged']               = $housekeeping['purged'];
            $result['housekeepingFailures'] = $housekeeping['failures'];
        }

        // ======================================================================
        // 📋 The due rows (a snapshot - it may be out of date by the time each
        //    row is claimed, which is exactly why the claim re-checks everything)
        // ======================================================================
        if ($sendEmails) {
            $dueIDs = $gateway['findDue']($limit);

            if (!is_array($dueIDs)) {
                // ⚠️ Reported as a database problem - never mistaken for "the
                //    queue is empty".
                $result['status']  = 'db_error';
                $result['problem'] = 'could not read the email queue (database error)';
                return $result;
            }
        } else {
            $dueIDs = [];      // 🔕 tidy-only run: nothing is claimed or sent
        }

        foreach ($dueIDs as $dueID) {
            $emailID = (int) $dueID;

            // ⏱️ Time budget: stop claiming NEW emails once it is used up. An
            //    email already claimed is always finished first.
            if (((float) $clock() - $startedAt) >= $timeBudget) {
                $result['stoppedEarly'] = true;
                $result['stopReason']   = 'time_budget';
                break;
            }

            $result['processed']++;

            // 🔒 Layer 1: the claim - only succeeds if the row is STILL
            //    queued/failed, due, and has attempts left right now.
            $claimed = $gateway['claim']($emailID);

            if ($claimed === null) {
                $result['status']  = 'db_error';
                $result['problem'] = 'could not update the email queue (database error)';
                break;
            }

            if ($claimed !== true) {
                // ⏭️ Another runner got it first, or it is no longer due.
                $result['skipped']++;
                continue;
            }

            $result['claimed']++;

            // 🔄 Re-read the row AFTER claiming, and use only these values
            //    (never the possibly stale list) - otherwise a stale `attempts`
            //    value could be written back and lose another runner's count.
            $row = $gateway['load']($emailID);

            if (!is_array($row)) {
                // ⚠️ Left 'sending'; stuck-row recovery puts it back later.
                $result['skipped']++;
                $result['recordFailures']++;

                if (function_exists('logError')) {
                    logError('warning', 'Email queue: claimed an email but could not re-read it', 'emailID ' . $emailID);
                }

                continue;
            }

            $attemptsBefore = (int) $row['attempts'];
            $maxAttempts    = (int) $row['maxAttempts'];
            $toName         = null;

            if (isset($row['toName']) && $row['toName'] !== null && $row['toName'] !== '') {
                $toName = (string) $row['toName'];
            }

            // 🛑 #122 / #123: emails that must never be rendered or sent. Each is marked
            //    'skipped' (a final state; its details are cleared in the same statement)
            //    with a plain-English reason. No network call is made.
            $skipReason = null;
            $skipKind   = null;

            // 1️⃣ Its stored details were already removed (they are cleared when an email is
            //    sent or skipped, and 24 hours after it is given up). The owner's re-send
            //    runbook, or a hand edit, can put such a row back to 'queued'. Rendering it
            //    would send an email with an EMPTY link, because the templates quietly
            //    default a missing link to ''.
            if (!array_key_exists('paramsJSON', $row) || $row['paramsJSON'] === null || $row['paramsJSON'] === '') {
                $skipReason = CUERCODE_EMAIL_QUEUE_NOTE_DETAILS_REMOVED;
                $skipKind   = 'unsendable';
            }

            // 2️⃣ #123 backstop: its link has expired (or nearly), measured on the DATABASE's
            //    clock. The clean-up step normally skips these first; this catches any it did
            //    not reach (its per-run cap, a failed clean-up step, or a run without the lock).
            if ($skipReason === null
                && isset($row['ageSeconds'])
                && is_numeric($row['ageSeconds'])
                && emailQueueLinkHasExpired((string) $row['templateKey'], (int) $row['ageSeconds'], $linkLifetimes)
            ) {
                $skipReason = CUERCODE_EMAIL_QUEUE_NOTE_LINK_EXPIRED;
                $skipKind   = 'expired';
            }

            // 🎨 The template parameters (only decoded when the email may still be sent).
            //    The body is rendered fresh at send time (the queue stores only the
            //    template parameters, never a rendered body).
            $params = [];

            if ($skipReason === null) {
                $decodedParams = json_decode((string) $row['paramsJSON'], true);

                if (is_array($decodedParams)) {
                    $params = $decodedParams;
                }

                // 3️⃣ A template that needs a link, with no link (missing, not text, or blank).
                $requiredLinkParam = emailQueueRequiredLinkParam((string) $row['templateKey']);

                if ($requiredLinkParam !== null
                    && (!isset($params[$requiredLinkParam]) || !is_string($params[$requiredLinkParam]) || trim($params[$requiredLinkParam]) === '')
                ) {
                    $skipReason = CUERCODE_EMAIL_QUEUE_NOTE_LINK_MISSING;
                    $skipKind   = 'unsendable';
                }
            }

            if ($skipReason !== null) {
                if ($gateway['markSkipped']($emailID, $skipReason) === true) {
                    if ($skipKind === 'expired') {
                        $result['expired']++;
                    } else {
                        $result['unsendable']++;
                    }
                } else {
                    // ⚠️ Left 'sending'; stuck-row recovery looks at it again later.
                    $result['recordFailures']++;

                    if (function_exists('logError')) {
                        // 📝 The ID only - never an address.
                        logError('warning', 'Email queue: could not mark an email as skipped', 'emailID ' . $emailID);
                    }
                }

                continue;
            }

            $htmlBody     = null;
            $renderFailed = false;
            $failureText  = '';

            try {
                $rendered = renderEmailTemplate((string) $row['templateKey'], $params);
                $htmlBody = $rendered[1];
            } catch (\Throwable $e) {
                // ✉️ This email's own template or parameters are broken - a
                //    message-level failure that must not stop the others.
                $renderFailed = true;
                $failureText  = emailQueueSafeDetail(
                    'failed: the template could not be rendered (' . emailQueueSafeDetail($e->getMessage(), 300) . ')',
                    500
                );
            }

            $sendKind  = 'message';
            $sendOk    = false;
            $sendError = null;

            if (!$renderFailed) {
                // 📤 Send. A sender that throws is treated as temporary
                //    trouble; one that returns an odd shape as a message error.
                try {
                    $sendOutcome = $sender((string) $row['toAddress'], (string) $row['subject'], (string) $htmlBody, $toName);

                    if (is_array($sendOutcome) && array_key_exists(0, $sendOutcome) && $sendOutcome[0] === true) {
                        $sendOk = true;
                    } elseif (is_array($sendOutcome) && array_key_exists(1, $sendOutcome) && is_string($sendOutcome[1]) && $sendOutcome[1] !== '') {
                        $sendError = $sendOutcome[1];
                    } else {
                        $sendError = 'failed: the sender gave no reason';
                    }
                } catch (\Throwable $e) {
                    $sendError = 'failed: the sender stopped with an unexpected error (' . get_class($e) . ')';
                }

                if ($sendOk) {
                    $result['sent']++;

                    if ($gateway['markSent']($emailID) !== true) {
                        // ⚠️ Sent, but the row stays 'sending'; recovery will
                        //    put it back after the stuck time, so it may be
                        //    sent once more. Reported so the owner knows.
                        $result['recordFailures']++;

                        if (function_exists('logError')) {
                            logError('warning', 'Email queue: an email was sent but the result could not be recorded', 'emailID ' . $emailID);
                        }
                    }

                    continue;
                }

                $sendKind    = classifyEmailSendError($sendError);
                $failureText = emailQueueSafeDetail((string) $sendError, 500);

                if ($sendKind === 'system') {
                    // 🚫 Sending is blocked for EVERY email. Put this one back
                    //    unchanged (no attempt used) and stop - there is no
                    //    point claiming the rest just to put them back too.
                    if ($gateway['release']($emailID, 'Not sent yet - sending is blocked: ' . $failureText) === true) {
                        $result['released']++;
                    } else {
                        $result['recordFailures']++;
                    }

                    $result['status']  = 'system_problem';
                    $result['problem'] = emailQueueSafeDetail((string) $sendError, 200);
                    break;
                }
            }

            // 🔁 A failed attempt (template or message-level or temporary):
            //    retry later with back-off, or give up after the last attempt.
            //    Written with the attempts value we re-read after claiming.
            $decision = decideEmailQueueFailure($attemptsBefore, $maxAttempts);

            if ($decision['giveUp']) {
                if ($gateway['markGaveUp']($emailID, $decision['attemptsAfter'], $failureText) === true) {
                    $result['gaveUp']++;
                } else {
                    $result['recordFailures']++;
                }
            } else {
                if ($gateway['markRetry']($emailID, $decision['attemptsAfter'], (int) $decision['retryInMinutes'], $failureText) === true) {
                    $result['retrying']++;
                } else {
                    $result['recordFailures']++;
                }
            }

            if ($sendKind === 'transient') {
                // ⏳ Temporary trouble at Microsoft or on the network: stop
                //    early rather than hammer a struggling service and burn
                //    one attempt on every email in the queue this minute.
                $result['stoppedEarly'] = true;
                $result['stopReason']   = 'temporary_problem';
                $result['stopDetail']   = emailQueueSafeDetail((string) $sendError, 200);
                break;
            }
        }

        // ⏳ How many are still due, for the summary line (best effort).
        if ($result['status'] === 'ok' || $result['status'] === 'system_problem') {
            try {
                $waiting = $gateway['countDue']();
            } catch (\Throwable $e) {
                $waiting = null;
            }

            if (is_int($waiting)) {
                $result['waiting'] = $waiting;
            }
        }
    } catch (\Throwable $e) {
        $result['status']  = 'db_error';
        $result['problem'] = 'unexpected error while working through the queue (' . get_class($e) . ')';

        if (function_exists('logError')) {
            logError('error', 'Email queue batch stopped by an unexpected error', $e->getMessage());
        }
    } finally {
        if ($haveLock) {
            try {
                $gateway['unlock']();
            } catch (\Throwable $e) {
                // 🔓 Nothing to do: MySQL releases the lock anyway when this
                //    database connection closes (at the end of the process).
            }
        }
    }

    return $result;
}

// ==============================================================================
// 🗄️ Database operations (#112) - the real "gateway" behind runEmailQueueBatch()
// ==============================================================================
// Every statement is a prepared statement through dbQuery(). The only text
// ever joined into SQL is the fixed constant CUERCODE_EMAIL_QUEUE_LOCK_NAME_SQL.
//
// 📌 About affected_rows: MySQL reports rows actually CHANGED (mysqli is not
//    opened with MYSQLI_CLIENT_FOUND_ROWS). Every guarded write below changes
//    `status`, so affected_rows === 1 reliably means "we did it" and 0 means
//    "the row was not in the state we required - somebody else owns it".
// ==============================================================================

/**
 * 📥 Run one read query for the queue code and return every row.
 *
 * Shared by the queue's read operations so that each gets the same handling:
 * a missing database layer or any error returns null (logged, never thrown),
 * and the statement is always closed.
 *
 * @param string $sql        SQL with ? placeholders.
 * @param string $types      bind_param() types ('' for none).
 * @param array  $params     Values to bind.
 * @param string $errorTitle Short title for the error log.
 * @param string $severity   Error-log severity (default 'error').
 *
 * @return list<array<string, mixed>>|null The rows, or null on any error.
 */
function emailQueueFetchRows(string $sql, string $types, array $params, string $errorTitle, string $severity = 'error'): ?array
{
    if (!function_exists('dbQuery')) {
        return null;
    }

    $stmt = null;

    try {
        $stmt   = dbQuery($sql, $types, $params);
        $result = $stmt->get_result();

        if ($result === false) {
            throw new \RuntimeException('the query returned no result set');
        }

        $rows = [];

        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        $result->free();

        return $rows;
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError($severity, $errorTitle, $e->getMessage());
        }

        return null;
    } finally {
        if ($stmt instanceof \mysqli_stmt) {
            $stmt->close();
        }
    }
}

/**
 * ✏️ Run one guarded write for the queue code and report whether it changed
 * exactly one row.
 *
 * Every claim and every final update of a queue row goes through here, so
 * they all share the same "exactly one row changed" rule (see the
 * affected_rows note above).
 *
 * @param string $sql        SQL with ? placeholders.
 * @param string $types      bind_param() types.
 * @param array  $params     Values to bind.
 * @param string $errorTitle Short title for the error log.
 *
 * @return bool|null true = exactly one row changed; false = no row matched the
 *                   guard; null = database error (logged).
 */
function emailQueueChangeOneRow(string $sql, string $types, array $params, string $errorTitle): ?bool
{
    if (!function_exists('dbQuery')) {
        return null;
    }

    $stmt = null;

    try {
        $stmt = dbQuery($sql, $types, $params);

        return $stmt->affected_rows === 1;
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', $errorTitle, $e->getMessage());
        }

        return null;
    } finally {
        if ($stmt instanceof \mysqli_stmt) {
            $stmt->close();
        }
    }
}

/**
 * 🔒 Try to take this database's email queue lock, without waiting.
 *
 * The lock belongs to this database connection. MySQL lets it go by itself
 * the moment the connection closes - including when the process dies - so a
 * crashed runner can never leave the queue locked for good. Every queue write
 * uses the same connection (getDB() keeps one per process), so a runner that
 * has lost its lock has also lost its ability to write.
 *
 * @return bool|null true = we hold it; false = another connection holds it;
 *                   null = database error.
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/locking-functions.html#function_get-lock
 */
function acquireEmailQueueRunLock(): ?bool
{
    // ⏱️ The timeout is 0: a second runner does not queue up behind the first,
    //    it reports "another run is working" and stops.
    $rows = emailQueueFetchRows(
        'SELECT GET_LOCK(' . CUERCODE_EMAIL_QUEUE_LOCK_NAME_SQL . ', 0) AS `lockResult`',
        's',
        [CUERCODE_EMAIL_QUEUE_LOCK_PREFIX],
        'Email queue lock could not be taken'
    );

    if ($rows === null || count($rows) !== 1 || !array_key_exists('lockResult', $rows[0])) {
        return null;
    }

    // 📌 GET_LOCK() returns 1 (got it), 0 (someone else has it) or NULL (error).
    $lockValue = $rows[0]['lockResult'];

    if ($lockValue === null || !is_numeric($lockValue)) {
        return null;
    }

    if ((int) $lockValue === 1) {
        return true;
    }

    if ((int) $lockValue === 0) {
        return false;
    }

    return null;
}

/**
 * 🔓 Let go of this database's email queue lock (best effort).
 *
 * A failure is logged as a warning and otherwise ignored: the lock is let go
 * anyway when the database connection closes at the end of the process.
 *
 * @return void
 */
function releaseEmailQueueRunLock(): void
{
    emailQueueFetchRows(
        'SELECT RELEASE_LOCK(' . CUERCODE_EMAIL_QUEUE_LOCK_NAME_SQL . ') AS `releaseResult`',
        's',
        [CUERCODE_EMAIL_QUEUE_LOCK_PREFIX],
        'Email queue lock could not be released',
        'warning'
    );
}

/**
 * 🧯 List rows that have been 'sending' for longer than $stuckMinutes.
 *
 * `updatedAt` is refreshed on every change to a row, so for a row in
 * 'sending' it records when the row was claimed. No schema change needed.
 *
 * @param int $stuckMinutes How long a row must have been 'sending'.
 * @param int $limit        Most rows to return.
 *
 * @return list<array{emailID: int, attempts: int, maxAttempts: int}>|null null on error.
 */
function findStuckEmailQueueRows(int $stuckMinutes, int $limit): ?array
{
    $rows = emailQueueFetchRows(
        'SELECT `emailID`, `attempts`, `maxAttempts` '
        . 'FROM `tblEmailQueue` '
        . "WHERE `status` = 'sending' "
        . 'AND `updatedAt` < DATE_SUB(NOW(), INTERVAL ? MINUTE) '
        . 'ORDER BY `updatedAt` ASC, `emailID` ASC '
        . 'LIMIT ?',
        'ii',
        [$stuckMinutes, $limit],
        'Email queue stuck-row lookup failed'
    );

    if ($rows === null) {
        return null;
    }

    $stuckRows = [];

    foreach ($rows as $row) {
        $stuckRows[] = [
            'emailID'     => (int) $row['emailID'],
            'attempts'    => (int) $row['attempts'],
            'maxAttempts' => (int) $row['maxAttempts'],
        ];
    }

    return $stuckRows;
}

/**
 * 🧯 Put back one stuck row - for another try, or as given up.
 *
 * Guarded three ways, all in the one UPDATE: the row must still be 'sending',
 * still have the attempts value we saw, and still be older than the stuck
 * time. So if anything touched the row since we looked (including a slow
 * runner finally finishing it), nothing is changed.
 *
 * @param int         $emailID        The row.
 * @param int         $attemptsSeen   `attempts` as read by findStuckEmailQueueRows().
 * @param int         $attemptsAfter  The new `attempts` value (one more).
 * @param int|null    $retryInMinutes Minutes until the retry; null = give up.
 * @param string      $note           Plain-English lastError text.
 * @param int         $stuckMinutes   The stuck time used to find the row.
 *
 * @return bool true when exactly one row was put back.
 */
function recoverStuckEmailQueueRow(int $emailID, int $attemptsSeen, int $attemptsAfter, ?int $retryInMinutes, string $note, int $stuckMinutes): bool
{
    $safeNote = emailQueueSafeDetail($note, 500);

    if ($retryInMinutes !== null) {
        // 🔁 B1: back to 'queued', with the normal back-off.
        $outcome = emailQueueChangeOneRow(
            'UPDATE `tblEmailQueue` '
            . "SET `status` = 'queued', `attempts` = ?, `lastError` = ?, "
            . '`nextAttemptAt` = DATE_ADD(NOW(), INTERVAL ? MINUTE), `updatedAt` = NOW() '
            . "WHERE `emailID` = ? AND `status` = 'sending' AND `attempts` = ? "
            . 'AND `updatedAt` < DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            'isiiii',
            [$attemptsAfter, $safeNote, $retryInMinutes, $emailID, $attemptsSeen, $stuckMinutes],
            'Email queue stuck-row recovery failed'
        );
    } else {
        // 🛑 B2: it was on its last attempt - give up.
        $outcome = emailQueueChangeOneRow(
            'UPDATE `tblEmailQueue` '
            . "SET `status` = 'failed', `attempts` = ?, `lastError` = ?, `updatedAt` = NOW() "
            . "WHERE `emailID` = ? AND `status` = 'sending' AND `attempts` = ? "
            . 'AND `updatedAt` < DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            'isiii',
            [$attemptsAfter, $safeNote, $emailID, $attemptsSeen, $stuckMinutes],
            'Email queue stuck-row give-up failed'
        );
    }

    return $outcome === true;
}

/**
 * 📋 List the IDs of rows that are due to be sent now.
 *
 * ⚠️ This is only a snapshot: by the time each row is claimed it may have
 * changed, which is why claimEmailQueueRow() re-checks every condition.
 *
 * @param int $limit Most rows to return.
 *
 * @return list<int>|null The IDs (oldest-due first), or null on error.
 */
function findDueEmailQueueIDs(int $limit): ?array
{
    $rows = emailQueueFetchRows(
        'SELECT `emailID` '
        . 'FROM `tblEmailQueue` '
        . "WHERE `status` IN ('queued', 'failed') "
        . 'AND (`nextAttemptAt` IS NULL OR `nextAttemptAt` <= NOW()) '
        . 'AND `attempts` < `maxAttempts` '
        . 'ORDER BY `nextAttemptAt` ASC, `emailID` ASC '
        . 'LIMIT ?',
        'i',
        [$limit],
        'Email queue due-row lookup failed'
    );

    if ($rows === null) {
        return null;
    }

    $dueIDs = [];

    foreach ($rows as $row) {
        $dueIDs[] = (int) $row['emailID'];
    }

    return $dueIDs;
}

/**
 * 🔒 Claim one row for sending - THE guard against sending an email twice.
 *
 * 🐛 This used to check only the status (`status IN ('queued','failed')`). A
 * runner working from an out-of-date list could then claim a row another
 * runner had JUST failed and rescheduled: it re-sent the email immediately
 * (ignoring the back-off), wrote back its stale `attempts` value (losing the
 * other runner's count), and could even send a row the other runner had just
 * marked permanently failed (#112). Re-checking "is it due?" and "has it
 * attempts left?" in the SAME statement closes all of that.
 *
 * Why this is safe with two runners at once: MySQL makes two simultaneous
 * UPDATEs of the same row wait for each other, and the second one re-checks
 * its whole WHERE against the first one's committed change - so it matches no
 * row and changes nothing. Exactly one runner gets affected_rows === 1.
 *
 * @param int $emailID The row to claim.
 *
 * @return bool|null true = we claimed it; false = not claimable now (another
 *                   runner has it, or it is not due); null = database error.
 */
function claimEmailQueueRow(int $emailID): ?bool
{
    // 📌 emailQueueChangeOneRow() returns true only when affected_rows === 1.
    return emailQueueChangeOneRow(
        'UPDATE `tblEmailQueue` '
        . "SET `status` = 'sending', `updatedAt` = NOW() "
        . 'WHERE `emailID` = ? '
        . "AND `status` IN ('queued', 'failed') "
        . 'AND (`nextAttemptAt` IS NULL OR `nextAttemptAt` <= NOW()) '
        . 'AND `attempts` < `maxAttempts`',
        'i',
        [$emailID],
        'Email queue claim failed'
    );
}

/**
 * 📄 Re-read a row we have just claimed.
 *
 * Only returns the row while it is still 'sending', so the batch always works
 * from the values as they are NOW, never from the earlier list.
 *
 * #123: it also returns `ageSeconds` - how long ago the email was queued,
 * worked out by the DATABASE (TIMESTAMPDIFF against its own NOW()). The
 * per-email expired-link check uses it. It is measured in SQL, never with
 * PHP's time(), because PHP's clock follows the site_timezone setting while
 * the database stores and compares in UTC (db_connect.php sets the session
 * time zone), so mixing the two could skip or send at the wrong moment.
 *
 * @param int $emailID The claimed row.
 *
 * @return array<string, mixed>|null The row, or null if it is not 'sending' or on error.
 */
function loadClaimedEmailQueueRow(int $emailID): ?array
{
    $rows = emailQueueFetchRows(
        'SELECT `emailID`, `toAddress`, `toName`, `templateKey`, `paramsJSON`, `subject`, `attempts`, `maxAttempts`, '
        . 'TIMESTAMPDIFF(SECOND, `createdAt`, NOW()) AS `ageSeconds` '
        . 'FROM `tblEmailQueue` '
        . "WHERE `emailID` = ? AND `status` = 'sending' "
        . 'LIMIT 1',
        'i',
        [$emailID],
        'Email queue claimed-row read failed'
    );

    if ($rows === null || count($rows) !== 1) {
        return null;
    }

    return $rows[0];
}

/**
 * ✅ Mark a claimed row as sent - and clear its stored details (#122).
 *
 * Before #112 this updated the row whatever state it was in and returned
 * nothing. It now only applies while the row is still 'sending' - only the
 * runner that claimed the row can finish it - and reports whether it did.
 *
 * 🧹 #122: `paramsJSON` (which can hold a one-time sign-up or password-reset
 * link) is set to NULL in this SAME statement, so a sent row never holds its
 * link, not even for a moment. If recording "sent" fails, the row stays
 * 'sending' WITH its details; stuck-row recovery puts it back and the re-send
 * still has its link - the same "at least once" behaviour as #112.
 *   Rejected: keeping only the "non-secret" parameters (needs a per-template
 *   list of secret keys that somebody must keep up to date; the other keys are
 *   copies of toName or constants), and a marker such as {"removed":true}
 *   (could clash with a future template parameter; NULL is unambiguous because
 *   queueEmail() never writes NULL, and easy to query).
 *
 * @param int $emailID The row.
 *
 * @return bool true when exactly one row was updated.
 */
function settleEmailQueueSuccess(int $emailID): bool
{
    $outcome = emailQueueChangeOneRow(
        'UPDATE `tblEmailQueue` '
        . "SET `status` = 'sent', `sentAt` = NOW(), `lastError` = NULL, `paramsJSON` = NULL, `updatedAt` = NOW() "
        . "WHERE `emailID` = ? AND `status` = 'sending'",
        'i',
        [$emailID],
        'Email queue success-settle failed'
    );

    return $outcome === true;
}

/**
 * 🔁 Put a claimed row back for a later retry, with back-off.
 *
 * Only applies while the row is still 'sending' (see settleEmailQueueSuccess()).
 *
 * @param int    $emailID        The row.
 * @param int    $attemptsAfter  The new `attempts` value.
 * @param int    $retryInMinutes Minutes until it is due again.
 * @param string $errorDetail    Short, non-sensitive error text (cleaned again here).
 *
 * @return bool true when exactly one row was updated.
 */
function settleEmailQueueRetry(int $emailID, int $attemptsAfter, int $retryInMinutes, string $errorDetail): bool
{
    $outcome = emailQueueChangeOneRow(
        'UPDATE `tblEmailQueue` '
        . "SET `status` = 'queued', `attempts` = ?, `lastError` = ?, "
        . '`nextAttemptAt` = DATE_ADD(NOW(), INTERVAL ? MINUTE), `updatedAt` = NOW() '
        . "WHERE `emailID` = ? AND `status` = 'sending'",
        'isii',
        [$attemptsAfter, emailQueueSafeDetail($errorDetail, 500), $retryInMinutes, $emailID],
        'Email queue retry-settle failed'
    );

    return $outcome === true;
}

/**
 * 🛑 Mark a claimed row as given up (no attempts left).
 *
 * Only applies while the row is still 'sending' (see settleEmailQueueSuccess()).
 *
 * @param int    $emailID       The row.
 * @param int    $attemptsAfter The final `attempts` value.
 * @param string $errorDetail   Short, non-sensitive error text (cleaned again here).
 *
 * @return bool true when exactly one row was updated.
 */
function settleEmailQueueGaveUp(int $emailID, int $attemptsAfter, string $errorDetail): bool
{
    $outcome = emailQueueChangeOneRow(
        'UPDATE `tblEmailQueue` '
        . "SET `status` = 'failed', `attempts` = ?, `lastError` = ?, `updatedAt` = NOW() "
        . "WHERE `emailID` = ? AND `status` = 'sending'",
        'isi',
        [$attemptsAfter, emailQueueSafeDetail($errorDetail, 500), $emailID],
        'Email queue give-up settle failed'
    );

    return $outcome === true;
}

/**
 * ↩️ Put a claimed row back UNCHANGED because sending is blocked.
 *
 * `attempts` and `nextAttemptAt` are deliberately left alone: a set-up
 * problem (switched off, missing settings, Graph refusing our credentials)
 * is not this email's fault, so it must not use up one of its attempts.
 * Only applies while the row is still 'sending'.
 *
 * @param int    $emailID The row.
 * @param string $reason  Short, non-sensitive reason (cleaned again here).
 *
 * @return bool true when exactly one row was updated.
 */
function releaseEmailQueueClaim(int $emailID, string $reason): bool
{
    $outcome = emailQueueChangeOneRow(
        'UPDATE `tblEmailQueue` '
        . "SET `status` = 'queued', `lastError` = ?, `updatedAt` = NOW() "
        . "WHERE `emailID` = ? AND `status` = 'sending'",
        'si',
        [emailQueueSafeDetail($reason, 500), $emailID],
        'Email queue release failed'
    );

    return $outcome === true;
}

/**
 * ⏳ Count the rows that are due to be sent now.
 *
 * @return int|null The count, or null on error.
 */
function countDueEmailQueueRows(): ?int
{
    $rows = emailQueueFetchRows(
        'SELECT COUNT(*) AS `dueCount` '
        . 'FROM `tblEmailQueue` '
        . "WHERE `status` IN ('queued', 'failed') "
        . 'AND (`nextAttemptAt` IS NULL OR `nextAttemptAt` <= NOW()) '
        . 'AND `attempts` < `maxAttempts`',
        '',
        [],
        'Email queue due-row count failed'
    );

    if ($rows === null || count($rows) !== 1) {
        return null;
    }

    return (int) $rows[0]['dueCount'];
}

/**
 * 🚨 Count rows that suggest the queue is stalled: emails that have been due
 * for more than $minutes, or rows stuck in 'sending' for more than $minutes.
 *
 * Used only when another run holds the lock, to tell "still working" apart
 * from "hung while holding the lock". A row with no nextAttemptAt counts from
 * when it was queued.
 *
 * @param int $minutes How overdue a row must be.
 *
 * @return int|null The count, or null on error.
 */
function countOverdueEmailQueueRows(int $minutes): ?int
{
    $rows = emailQueueFetchRows(
        'SELECT COUNT(*) AS `overdueCount` '
        . 'FROM `tblEmailQueue` '
        . 'WHERE ('
        . "`status` IN ('queued', 'failed') "
        . 'AND `attempts` < `maxAttempts` '
        . 'AND COALESCE(`nextAttemptAt`, `createdAt`) <= DATE_SUB(NOW(), INTERVAL ? MINUTE)'
        . ') OR ('
        . "`status` = 'sending' "
        . 'AND `updatedAt` < DATE_SUB(NOW(), INTERVAL ? MINUTE)'
        . ')',
        'ii',
        [$minutes, $minutes],
        'Email queue overdue-row count failed'
    );

    if ($rows === null || count($rows) !== 1) {
        return null;
    }

    return (int) $rows[0]['overdueCount'];
}

/**
 * 📊 Count the queue's rows by status (for `--check`).
 *
 * @return array{queued: int, sending: int, sent: int, failed: int, skipped: int}|null
 *         Every key is present (0 when there are none), or null on error.
 */
function countEmailQueueRowsByStatus(): ?array
{
    $rows = emailQueueFetchRows(
        'SELECT `status`, COUNT(*) AS `rowCount` FROM `tblEmailQueue` GROUP BY `status`',
        '',
        [],
        'Email queue status count failed'
    );

    if ($rows === null) {
        return null;
    }

    $counts = [
        'queued'  => 0,
        'sending' => 0,
        'sent'    => 0,
        'failed'  => 0,
        'skipped' => 0,
    ];

    foreach ($rows as $row) {
        $statusName = (string) $row['status'];

        if (array_key_exists($statusName, $counts)) {
            $counts[$statusName] = (int) $row['rowCount'];
        }
    }

    return $counts;
}

/**
 * 🩺 Check that the database can be reached AND the queue table exists.
 *
 * This is needed because getConfig() quietly returns its default when the
 * database is down, so "email is switched off" and "the database is down"
 * would otherwise look identical to the runner.
 *
 * 🔐 On failure it returns a generic sentence only. The driver's own message
 * goes to the error log, never to the screen, because it can contain the
 * database user name and host.
 *
 * @return string|null null when fine; otherwise a safe sentence to print.
 */
function checkEmailQueueDatabase(): ?string
{
    $rows = emailQueueFetchRows(
        'SELECT 1 AS `ok` FROM `tblEmailQueue` LIMIT 1',
        '',
        [],
        'Email queue runner could not reach the database or the queue table'
    );

    if ($rows === null) {
        return 'could not reach the database or the email queue table (details are in the error log)';
    }

    return null;
}

/**
 * ❌ Record a failed send: retry with back-off, or give up on the last attempt.
 *
 * ⚠️ Kept ONLY so any outside caller keeps working - the batch no longer uses
 * it (runEmailQueueBatch() calls settleEmailQueueRetry()/settleEmailQueueGaveUp()
 * directly). Since #112 it shares decideEmailQueueFailure()'s rule, only
 * applies while the row is still 'sending', and returns whether it did.
 *
 * @param int    $emailID        The row.
 * @param int    $attemptsBefore `attempts` BEFORE this failure.
 * @param int    $maxAttempts    The row's `maxAttempts`.
 * @param string $errorDetail    Short, non-sensitive error text.
 *
 * @return bool true when exactly one row was updated.
 *
 * @see decideEmailQueueFailure()
 */
function settleEmailQueueFailure(int $emailID, int $attemptsBefore, int $maxAttempts, string $errorDetail): bool
{
    $decision = decideEmailQueueFailure($attemptsBefore, $maxAttempts);

    if ($decision['giveUp']) {
        return settleEmailQueueGaveUp($emailID, $decision['attemptsAfter'], $errorDetail);
    }

    return settleEmailQueueRetry($emailID, $decision['attemptsAfter'], (int) $decision['retryInMinutes'], $errorDetail);
}

// ==============================================================================
// 🧹 Database operations for the clean-up and the "never send" guard (#122, #123)
// ==============================================================================
// Statements N-R in the #122/#123 plan (A-M are #112's). Every one is a
// prepared statement through dbQuery(); no new text is joined into SQL.
//
// 🔒 Rules every one of them keeps:
//   - None ever touches a row that is 'sending', except N, which is the
//     claiming runner finishing its OWN row (guarded by status = 'sending').
//   - Only O (expired links) touches a WAITING row (queued/failed with
//     attempts left) - by design, because those are the ones that must not be
//     sent. The two DELETEs (Q, R) only ever match FINISHED rows.
//   - O, P, Q and R are range statements and are only ever called by
//     runEmailQueueHousekeeping(), which only runs while the batch holds the
//     queue lock (see that function for why).
//   - `LIMIT ?` in UPDATE/DELETE was checked on MariaDB 10.6 and MySQL 8.4
//     (planner, 2026-09-25). MySQL 5.7 and MariaDB 10.3 were NOT checked.
// ==============================================================================

/**
 * ✏️ Run one clean-up write and report how many rows it changed or deleted.
 *
 * The same shape as emailQueueChangeOneRow(): null when the database layer is
 * missing, any error is logged (logError()) and returned as null, and the
 * statement is always closed.
 *
 * affected_rows counts rows actually CHANGED or DELETED (mysqli is not opened
 * with MYSQLI_CLIENT_FOUND_ROWS). Every statement that uses this helper either
 * deletes, or changes a column that is not already that value (status, or
 * paramsJSON guarded by "IS NOT NULL"), so the count is exact.
 *
 * @param string $sql        SQL with ? placeholders.
 * @param string $types      bind_param() types.
 * @param array  $params     Values to bind.
 * @param string $errorTitle Short title for the error log.
 *
 * @return int|null The number of rows changed or deleted, or null on error.
 */
function emailQueueChangeRows(string $sql, string $types, array $params, string $errorTitle): ?int
{
    if (!function_exists('dbQuery')) {
        return null;
    }

    $stmt = null;

    try {
        $stmt         = dbQuery($sql, $types, $params);
        $affectedRows = $stmt->affected_rows;

        // 📌 mysqli reports -1 for an error; anything below 0 is not a count.
        if (!is_int($affectedRows) || $affectedRows < 0) {
            return null;
        }

        return $affectedRows;
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', $errorTitle, $e->getMessage());
        }

        return null;
    } finally {
        if ($stmt instanceof \mysqli_stmt) {
            $stmt->close();
        }
    }
}

/**
 * 🛑 N: mark a claimed row as 'skipped' - it must never be sent (#122/#123).
 *
 * Used by the batch when a claimed email's details were already removed, its
 * required link is missing, or its link has expired. Its details are cleared
 * in the same statement.
 *
 * Only applies while the row is still 'sending': only the runner that claimed
 * the row can finish it (#112). It never touches a waiting row.
 *
 * @param int    $emailID The claimed row.
 * @param string $reason  Plain-English lastError text (cleaned again here).
 *
 * @return bool true when exactly one row was updated.
 */
function skipClaimedEmailQueueRow(int $emailID, string $reason): bool
{
    $outcome = emailQueueChangeOneRow(
        'UPDATE `tblEmailQueue` '
        . "SET `status` = 'skipped', `lastError` = ?, `paramsJSON` = NULL, `updatedAt` = NOW() "
        . "WHERE `emailID` = ? AND `status` = 'sending'",
        'si',
        [emailQueueSafeDetail($reason, 500), $emailID],
        'Email queue skip-settle failed'
    );

    return $outcome === true;
}

/**
 * ⏳ O: mark WAITING emails of one template whose one-time link has expired
 * (or nearly) as 'skipped', and clear their details (#123).
 *
 * "Waiting" means status queued/failed WITH attempts left - whether due now
 * or waiting out a back-off. 'failed' with attempts left is included because
 * code from before #112 wrote that for rows that were still being retried.
 *
 * 🚫 Never touches:
 *   - a 'sending' row - only the runner that claimed it may finish it;
 *   - a given-up row (failed, no attempts left) - that it failed matters more
 *     to support than that its link later expired, so it keeps 'failed';
 *   - a sent or skipped row.
 *
 * 📌 No ORDER BY, on purpose. Every matching row is dealt with eventually, so
 * the order does not matter, and an ORDER BY with a LIMIT can lead MySQL to
 * walk the createdAt index across most of the table; without one it can use
 * the small status index range. MySQL may note an "unsafe for statement-based
 * replication" WARNING for a LIMIT with no ORDER BY - a warning, not an error
 * (dbQuery() only throws on errors), and which rows are changed does not
 * depend on the order.
 *
 * The age is measured on the database's clock (createdAt against NOW()),
 * never PHP's. "createdAt <= NOW() - maxAge" is the same rule as
 * emailQueueLinkHasExpired()'s "age >= maxAge".
 *
 * Only ever called under the queue lock (runEmailQueueHousekeeping()).
 *
 * @param string $templateKey   The template whose link lifetime applies.
 * @param int    $maxAgeSeconds emailQueueLinkMaxAgeSeconds() of its lifetime.
 * @param string $note          Plain-English lastError text (cleaned again here).
 * @param int    $limit         Most rows to change in this run.
 *
 * @return int|null How many emails were skipped, or null on error.
 */
function skipExpiredEmailQueueRows(string $templateKey, int $maxAgeSeconds, string $note, int $limit): ?int
{
    return emailQueueChangeRows(
        'UPDATE `tblEmailQueue` '
        . "SET `status` = 'skipped', `lastError` = ?, `paramsJSON` = NULL, `updatedAt` = NOW() "
        . 'WHERE `templateKey` = ? '
        . "AND `status` IN ('queued', 'failed') "
        . 'AND `attempts` < `maxAttempts` '
        . 'AND `createdAt` <= DATE_SUB(NOW(), INTERVAL ? SECOND) '
        . 'LIMIT ?',
        'ssii',
        [emailQueueSafeDetail($note, 500), $templateKey, $maxAgeSeconds, $limit],
        'Email queue expired-link skip failed'
    );
}

/**
 * 🧹 P: clear the stored details of GIVEN-UP emails (failed, no attempts
 * left) once the re-send window has passed (#122).
 *
 * A given-up email keeps its details for CUERCODE_EMAIL_QUEUE_RESEND_WINDOW_HOURS
 * after it was queued, so the owner can still re-send it after an outage
 * (owner decision 2). After that no re-send could help, because the link
 * itself has expired.
 *   Rejected: clearing at give-up (anyone whose verification email was lost
 *   in an outage could never be sent it again - there is no "send a new link"
 *   feature and they cannot sign up again with the same address).
 *
 * `updatedAt` = `updatedAt` stops MySQL's automatic ON UPDATE refresh, so the
 * time the email was given up stays readable in phpMyAdmin (checked on
 * MariaDB 10.6 and MySQL 8.4).
 *
 * 🚫 Never touches a waiting, 'sending', sent or skipped row. "IS NOT NULL"
 * keeps it from counting rows that were already cleared.
 *
 * 📌 No ORDER BY, on purpose - see skipExpiredEmailQueueRows().
 *
 * Only ever called under the queue lock (runEmailQueueHousekeeping()).
 *
 * @param int $olderThanHours Clear rows queued at least this many hours ago.
 * @param int $limit          Most rows to change in this run.
 *
 * @return int|null How many rows were cleared, or null on error.
 */
function clearGivenUpEmailQueueDetails(int $olderThanHours, int $limit): ?int
{
    return emailQueueChangeRows(
        'UPDATE `tblEmailQueue` '
        . 'SET `paramsJSON` = NULL, `updatedAt` = `updatedAt` '
        . "WHERE `status` = 'failed' "
        . 'AND `attempts` >= `maxAttempts` '
        . 'AND `paramsJSON` IS NOT NULL '
        . 'AND `createdAt` <= DATE_SUB(NOW(), INTERVAL ? HOUR) '
        . 'LIMIT ?',
        'ii',
        [$olderThanHours, $limit],
        'Email queue given-up details clear failed'
    );
}

/**
 * 🗑️ Q: delete old SENT and SKIPPED email records (#122).
 *
 * ⚠️ This deletes data, and that cannot be undone. It ONLY ever matches
 * status 'sent' or 'skipped'. It never matches a queued row, a row being
 * sent, or a failed row (given-up ones have their own statement and period;
 * failed rows with attempts left are still waiting). The source-text tests
 * check this statement never mentions those statuses.
 *
 * 📌 Counted from `createdAt`, not sentAt/updatedAt: it never changes, it has
 * an index (idx_email_created), and every email reaches its final state
 * within about a day of being queued, so the difference is at most a day.
 * updatedAt changes whenever anything touches a row, and sentAt only exists
 * for sent rows.
 *
 * 📌 ORDER BY createdAt, emailID (oldest first) matches idx_email_created,
 * whose entries are stored as (createdAt, emailID), so the delete walks only
 * the old end of the index. The comparison is strict ("<"): a record exactly
 * N days old is kept until the next run.
 *
 * Only ever called under the queue lock (runEmailQueueHousekeeping()).
 *
 * @param int $olderThanDays Delete records queued more than this many days ago.
 * @param int $limit         Most rows to delete in this run.
 *
 * @return int|null How many rows were deleted, or null on error.
 */
function purgeFinishedEmailQueueRows(int $olderThanDays, int $limit): ?int
{
    return emailQueueChangeRows(
        'DELETE FROM `tblEmailQueue` '
        . "WHERE `status` IN ('sent', 'skipped') "
        . 'AND `createdAt` < DATE_SUB(NOW(), INTERVAL ? DAY) '
        . 'ORDER BY `createdAt` ASC, `emailID` ASC '
        . 'LIMIT ?',
        'ii',
        [$olderThanDays, $limit],
        'Email queue old-record delete failed'
    );
}

/**
 * 🗑️ R: delete old GIVEN-UP email records (failed, no attempts left) (#122).
 *
 * ⚠️ This deletes data, and that cannot be undone. It ONLY ever matches
 * status 'failed' WITH no attempts left. It never matches a queued row, a row
 * being sent, or a failed row that still has attempts left (code from before
 * #112 wrote 'failed' for rows still being retried, and they are still
 * waiting). The source-text tests check this statement never mentions the
 * waiting or in-flight statuses.
 *
 * Kept longer than sent ones (default 90 days), because support is asked
 * about these. Counted from `createdAt` and ordered oldest first - see
 * purgeFinishedEmailQueueRows() for why.
 *
 * Only ever called under the queue lock (runEmailQueueHousekeeping()).
 *
 * @param int $olderThanDays Delete records queued more than this many days ago.
 * @param int $limit         Most rows to delete in this run.
 *
 * @return int|null How many rows were deleted, or null on error.
 */
function purgeGivenUpEmailQueueRows(int $olderThanDays, int $limit): ?int
{
    return emailQueueChangeRows(
        'DELETE FROM `tblEmailQueue` '
        . "WHERE `status` = 'failed' "
        . 'AND `attempts` >= `maxAttempts` '
        . 'AND `createdAt` < DATE_SUB(NOW(), INTERVAL ? DAY) '
        . 'ORDER BY `createdAt` ASC, `emailID` ASC '
        . 'LIMIT ?',
        'ii',
        [$olderThanDays, $limit],
        'Email queue old given-up record delete failed'
    );
}

/**
 * 🔌 The production gateway: each queue operation mapped to its real
 * database function, by name (plain strings, so they are easy to search for).
 *
 * @return array<string, string> Operation name => function name.
 */
function emailQueueDatabaseGateway(): array
{
    return [
        'lock'          => 'acquireEmailQueueRunLock',
        'unlock'        => 'releaseEmailQueueRunLock',
        'findStuck'     => 'findStuckEmailQueueRows',
        'recoverStuck'  => 'recoverStuckEmailQueueRow',
        'findDue'       => 'findDueEmailQueueIDs',
        'claim'         => 'claimEmailQueueRow',
        'load'          => 'loadClaimedEmailQueueRow',
        'markSent'      => 'settleEmailQueueSuccess',
        'markRetry'     => 'settleEmailQueueRetry',
        'markGaveUp'    => 'settleEmailQueueGaveUp',
        'release'       => 'releaseEmailQueueClaim',
        'countDue'      => 'countDueEmailQueueRows',
        'countOverdue'  => 'countOverdueEmailQueueRows',
        'countByStatus' => 'countEmailQueueRowsByStatus',
        // 🧹 #122/#123 - appended in the same order as CUERCODE_EMAIL_QUEUE_GATEWAY_KEYS.
        'markSkipped'   => 'skipClaimedEmailQueueRow',
        'skipExpired'   => 'skipExpiredEmailQueueRows',
        'clearGivenUp'  => 'clearGivenUpEmailQueueDetails',
        'purgeFinished' => 'purgeFinishedEmailQueueRows',
        'purgeGivenUp'  => 'purgeGivenUpEmailQueueRows',
    ];
}

// ==============================================================================
// 🖥️ The command-line runner's testable "main" (#112)
// ==============================================================================

/**
 * 🖥️ Everything the command-line runner does, given its options and its
 * "environment" (database check, settings reader, gateway, sender, output).
 *
 * _includes/cron/process_email_queue.php is only a thin wrapper around this,
 * so the whole flow can be tested with stand-ins. Every printed line goes
 * through emailQueueSafeDetail() as a backstop against printing an address.
 *
 * Steps 1 and 2 (bad options, --help) touch nothing but the output, which is
 * why the wrapper can call this BEFORE the site start-up code loads.
 *
 * 🧹 #122/#123: every run except --help, a bad option, an unreachable
 * database and --check also runs the queue clean-up (inside
 * runEmailQueueBatch(), under the lock) - INCLUDING while email sending is
 * switched off or its settings are incomplete, when it runs as a tidy-only
 * batch that claims and sends nothing. A failed clean-up gives exit code 3;
 * emails skipped during a sending run (expired link, details removed) are
 * reported on the error stream even with --quiet.
 *
 * @param array $args A parseEmailQueueRunnerArgs() result.
 * @param array $env  Required keys: 'dbCheck' (callable(): ?string),
 *                    'getSetting' (callable($key, $default)), 'gateway' (array),
 *                    'sender' (callable), 'out' (callable(string)),
 *                    'err' (callable(string)); optional 'clock' (callable(): float).
 *
 * @return int The exit code (CUERCODE_EMAIL_RUNNER_EXIT_*).
 *
 * @throws \InvalidArgumentException When $env is missing a key (a programming error).
 */
function emailQueueRunnerMain(array $args, array $env): int
{
    foreach (['dbCheck', 'getSetting', 'gateway', 'sender', 'out', 'err'] as $requiredKey) {
        if (!array_key_exists($requiredKey, $env)) {
            throw new \InvalidArgumentException('Email queue runner environment is missing "' . $requiredKey . '"');
        }
    }

    // 🧽 Every line printed goes through the address-masking helper first.
    $outSink = $env['out'];
    $errSink = $env['err'];

    $out = static function (string $line) use ($outSink): void {
        $outSink(emailQueueSafeDetail($line, 500));
    };

    $err = static function (string $line) use ($errSink): void {
        $errSink(emailQueueSafeDetail($line, 500));
    };

    if (isset($env['clock']) && is_callable($env['clock'])) {
        $clock = $env['clock'];
    } else {
        $clock = static function (): float {
            return microtime(true);
        };
    }

    // 1️⃣ A bad option: say why, show the help, stop.
    if ($args['ok'] !== true) {
        $err('CueRCode email queue: ' . (string) $args['error'] . '.');

        foreach (explode(PHP_EOL, emailQueueRunnerUsage()) as $usageLine) {
            $err($usageLine);
        }

        return CUERCODE_EMAIL_RUNNER_EXIT_USAGE;
    }

    // 2️⃣ --help.
    if ($args['help'] === true) {
        foreach (explode(PHP_EOL, emailQueueRunnerUsage()) as $usageLine) {
            $out($usageLine);
        }

        return CUERCODE_EMAIL_RUNNER_EXIT_OK;
    }

    validateEmailQueueGateway($env['gateway']);

    // 3️⃣ Is the database there? Checked BEFORE reading any setting, because a
    //    dead database makes getConfig() return "switched off".
    $started = (float) $clock();
    $problem = $env['dbCheck']();

    if ($problem !== null) {
        $err('CueRCode email queue: ' . (string) $problem . ' - nothing was sent.');
        return CUERCODE_EMAIL_RUNNER_EXIT_DATABASE;
    }

    // 4️⃣ The email settings, and the clean-up settings (#122). Only setting
    //    NAMES ever come back from these two - never values.
    $settings     = checkEmailQueueRunnerSettings($env['getSetting']);
    $housekeeping = emailQueueHousekeepingOptions($env['getSetting']);

    // 5️⃣ --check: report, change nothing (no clean-up, no claims).
    if ($args['check'] === true) {
        return emailQueueRunnerCheck($settings, $env['gateway'], $out, $err, $housekeeping);
    }

    $batchOptions = [
        'limit'                => $args['limit'],
        'clock'                => $clock,
        'retentionDays'        => $housekeeping['retentionDays'],
        'givenUpRetentionDays' => $housekeeping['givenUpRetentionDays'],
    ];

    // 6️⃣ / 7️⃣ Not ready to send (switched off, or switched on with settings missing):
    //    still run the clean-up (#122/#123) - keeping data only as long as needed must
    //    not depend on whether sending is on (the site can take sign-ups while email
    //    is off, and those rows would otherwise keep their links and addresses
    //    indefinitely) - but claim and send nothing. That tidy-only run takes the
    //    lock, tidies, counts and lets go; it recovers nothing and raises no stall
    //    alarm, because while sending is off emails are EXPECTED to wait.
    //    Rejected: running the clean-up only when sending is on.
    if ($settings['state'] !== 'ready') {
        $tidyOptions               = $batchOptions;
        $tidyOptions['sendEmails'] = false;
        $tidyResult                = runEmailQueueBatch($env['gateway'], $env['sender'], $tidyOptions);
        $tidyExitCode              = emailQueueRunnerExitCode($tidyResult);   // 0, or 3 when the clean-up/lock failed
        $tidyLines                 = [];

        if ($tidyResult['status'] === 'lock_error' || $tidyResult['status'] === 'db_error') {
            $tidyLines[] = 'CueRCode email queue: ' . (string) $tidyResult['problem']
                . ' - the clean-up of old emails did not run (details are in the error log).';
        }

        foreach (formatEmailQueueTidyLines($tidyResult) as $tidyLine) {
            $tidyLines[] = $tidyLine;
        }

        // 6️⃣ Switched off: that is a normal state (for example before the owner
        //    has finished the Microsoft 365 set-up), so exit 0 and claim nothing -
        //    unless the clean-up hit a database problem, which is reported on the
        //    error stream (even with --quiet) with exit code 3.
        if ($settings['state'] === 'disabled') {
            if ($tidyExitCode !== CUERCODE_EMAIL_RUNNER_EXIT_OK) {
                foreach ($tidyLines as $tidyLine) {
                    $err($tidyLine);
                }

                return $tidyExitCode;
            }

            if ($args['quiet'] !== true) {
                $waiting = $env['gateway']['countDue']();

                if (is_int($waiting)) {
                    $waitingText = (string) $waiting;
                } else {
                    $waitingText = 'an unknown number of';
                }

                $out('CueRCode email queue: email sending is switched off (email_enabled = 0), so nothing was sent. '
                    . $waitingText . ' email(s) are waiting.');

                // 🧹 What the clean-up did - only without --quiet, because while
                //    email is switched off skipped emails are the expected result
                //    of the owner's choice, not something to be woken up for.
                foreach ($tidyLines as $tidyLine) {
                    $out($tidyLine);
                }
            }

            return CUERCODE_EMAIL_RUNNER_EXIT_OK;
        }

        // 7️⃣ Switched on but not ready: say which settings (names only), as before.
        $err('CueRCode email queue: email is switched on, but it cannot send yet: '
            . implode('; ', $settings['problems']) . '. Nothing was sent.');

        foreach ($tidyLines as $tidyLine) {
            $err($tidyLine);
        }

        if ($tidyExitCode !== CUERCODE_EMAIL_RUNNER_EXIT_OK) {
            return $tidyExitCode;          // 3 (database) outranks 4 (email set-up)
        }

        return CUERCODE_EMAIL_RUNNER_EXIT_EMAIL_SETUP;
    }

    // 8️⃣ Send (the clean-up runs first, inside the batch, under the lock).
    $result = runEmailQueueBatch($env['gateway'], $env['sender'], $batchOptions);

    // 9️⃣ Report.
    $exitCode = emailQueueRunnerExitCode($result);
    $lines    = formatEmailQueueRunReport($result, (float) $clock() - $started);

    // 🔟 Problems - and anything the owner should know even on a good run
    //    (emails put back after a crash may arrive twice; results that could
    //    not be recorded may be re-sent; #122/#123: emails skipped because their
    //    link expired or their details were gone mean people did NOT get an
    //    email they asked for) - go to the error stream even with --quiet, so
    //    cron emails them. A quiet, clean run prints nothing.
    if ($exitCode !== CUERCODE_EMAIL_RUNNER_EXIT_OK
        || (int) $result['recovered'] > 0
        || (int) $result['recordFailures'] > 0
        || (int) $result['expired'] > 0
        || (int) $result['unsendable'] > 0
    ) {
        foreach ($lines as $line) {
            $err($line);
        }
    } elseif ($args['quiet'] !== true) {
        foreach ($lines as $line) {
            $out($line);
        }
    }

    return $exitCode;
}

/**
 * 🩺 The runner's `--check` mode: show whether it could send, and the queue
 * counts, WITHOUT sending or claiming anything.
 *
 * It ignores --quiet, because its whole purpose is to show something.
 *
 * 🧹 #122/#123: it also shows how many emails were skipped on purpose, whether
 * the expired-link check is on (and the lifetimes it uses), the clean-up
 * policy (with the retention periods in force), and the NAMES of any
 * clean-up settings that are not valid - never their values. It still
 * changes nothing: no clean-up, no claims. The #112 lines are unchanged
 * (the tests pin the "Queue:" line exactly); the new lines come after it.
 *
 * @param array    $settings     A checkEmailQueueRunnerSettings() result.
 * @param array    $gateway      The queue gateway (only the count operations are used).
 * @param callable $out          Writes one line to the normal stream.
 * @param callable $err          Writes one line to the error stream.
 * @param array    $housekeeping An emailQueueHousekeepingOptions() result; any
 *                               missing key uses the built-in default.
 *
 * @return int 0 = ready or switched off; 3 = queue unreadable; 4 = switched on but not ready.
 */
function emailQueueRunnerCheck(array $settings, array $gateway, callable $out, callable $err, array $housekeeping = []): int
{
    $counts = $gateway['countByStatus']();
    $due    = $gateway['countDue']();

    if (!is_array($counts) || !is_int($due)) {
        $err('CueRCode email queue: could not read the email queue (database error) - nothing was sent.');
        return CUERCODE_EMAIL_RUNNER_EXIT_DATABASE;
    }

    $lines   = [];
    $lines[] = 'CueRCode email queue - setup check (nothing is sent in this mode)';
    $lines[] = 'Database: reachable, and the email queue table was found.';

    if ($settings['enabled'] === true) {
        $lines[] = 'Email sending: switched on';
    } else {
        $lines[] = 'Email sending: switched off (email_enabled = 0)';
    }

    if (count($settings['problems']) === 0) {
        $lines[] = 'Microsoft Graph settings: complete';
    } else {
        $lines[] = 'Microsoft Graph settings: NOT ready - ' . implode('; ', $settings['problems']);
    }

    $lines[] = 'Queue: ' . $due . ' due now; '
        . (int) $counts['queued'] . ' queued in total; '
        . (int) $counts['sending'] . ' being sent; '
        . (int) $counts['sent'] . ' sent; '
        . (int) $counts['failed'] . ' given up.';

    // 🧹 #122/#123 lines - AFTER the "Queue:" line, which stays exactly as it was.
    $skippedCount = 0;

    if (isset($counts['skipped'])) {
        $skippedCount = (int) $counts['skipped'];
    }

    $lines[] = 'Skipped (not sent on purpose - link expired or details removed): ' . $skippedCount . '.';

    // ⏳ The expired-link check, with the lifetimes it will use. Loud when OFF,
    //    because then an expired link would still be sent.
    $linkLifetimes = normaliseEmailQueueLinkLifetimes(emailQueueLinkLifetimes());

    if (count($linkLifetimes) > 0) {
        $lifetimeParts = [];

        foreach ($linkLifetimes as $templateKey => $lifetimeSeconds) {
            if ($lifetimeSeconds >= 7200 && $lifetimeSeconds % 3600 === 0) {
                $lifetimeParts[] = $templateKey . ' ' . intdiv($lifetimeSeconds, 3600) . ' hours';
            } else {
                $lifetimeParts[] = $templateKey . ' ' . intdiv($lifetimeSeconds, 60) . ' minutes';
            }
        }

        $lines[] = 'Expired-link check: on (' . implode(', ', $lifetimeParts) . '); an email is skipped once its link has less than '
            . intdiv(CUERCODE_EMAIL_QUEUE_LINK_EXPIRY_MARGIN_SECONDS, 60) . ' minutes left.';
    } else {
        $lines[] = 'Expired-link check: OFF - the link lifetimes are unknown because fn_auth.php is not loaded, so an expired link would still be sent.'
            . ' The cron runner loads it through bootstrap.php, so this means a set-up fault.';
    }

    // 🧹 The clean-up policy in force (the numbers are OUR defaults or a
    //    validated whole number - never a raw setting value).
    $retentionDays = CUERCODE_EMAIL_QUEUE_RETENTION_DAYS_DEFAULT;

    if (isset($housekeeping['retentionDays'])) {
        $retentionDays = (int) $housekeeping['retentionDays'];
    }

    $givenUpRetentionDays = CUERCODE_EMAIL_QUEUE_GIVEN_UP_RETENTION_DAYS_DEFAULT;

    if (isset($housekeeping['givenUpRetentionDays'])) {
        $givenUpRetentionDays = (int) $housekeeping['givenUpRetentionDays'];
    }

    $lines[] = 'Clean-up: one-time links are removed as soon as an email is sent or skipped, and '
        . CUERCODE_EMAIL_QUEUE_RESEND_WINDOW_HOURS . ' hours after it is given up; sent and skipped records are deleted after '
        . $retentionDays . ' days and given-up ones after ' . $givenUpRetentionDays
        . ' days. This also runs while sending is switched off.';

    // ⚠️ Settings that are present but not valid - their NAMES only, never values.
    if (isset($housekeeping['invalidSettings']) && is_array($housekeeping['invalidSettings']) && count($housekeeping['invalidSettings']) > 0) {
        $lines[] = 'Clean-up settings that are not whole numbers from 1 to ' . CUERCODE_EMAIL_QUEUE_RETENTION_DAYS_MAX
            . ', so the default is used instead: ' . implode(', ', $housekeeping['invalidSettings']) . '.';
    }

    if ($settings['state'] === 'incomplete') {
        foreach ($lines as $line) {
            $err($line);
        }

        return CUERCODE_EMAIL_RUNNER_EXIT_EMAIL_SETUP;
    }

    foreach ($lines as $line) {
        $out($line);
    }

    return CUERCODE_EMAIL_RUNNER_EXIT_OK;
}

/**
 * 🏭 The real environment for emailQueueRunnerMain(), used only by the
 * command-line runner.
 *
 * Building it touches nothing (the database functions are given by name and
 * only called later), so it is safe to build before the start-up code loads.
 *
 * @return array The environment (see emailQueueRunnerMain()).
 */
function emailQueueProductionEnvironment(): array
{
    return [
        'dbCheck'    => 'checkEmailQueueDatabase',
        'getSetting' => 'getConfig',
        'gateway'    => emailQueueDatabaseGateway(),
        'sender'     => 'sendEmailNow',
        'out'        => static function (string $line): void {
            if (defined('STDOUT')) {
                fwrite(STDOUT, $line . PHP_EOL);
            } else {
                echo $line . PHP_EOL;
            }
        },
        'err'        => static function (string $line): void {
            if (defined('STDERR')) {
                fwrite(STDERR, $line . PHP_EOL);
            } else {
                error_log($line);
            }
        },
        'clock'      => static function (): float {
            return microtime(true);
        },
    ];
}

// ==============================================================================
// 🔁 processEmailQueue() - the page-shutdown fallback's entry point
// ==============================================================================

/**
 * 🔁 Send up to $max due emails - a thin wrapper over runEmailQueueBatch().
 *
 * Used by the OFF-by-default page-shutdown fallback
 * (maybeProcessEmailQueueOnShutdown()). The cron runner does not use this: it
 * calls runEmailQueueBatch() through emailQueueRunnerMain(). Both therefore
 * get the same three protections (see the top of the #112 section): a claim
 * that re-checks everything, the named lock, and stuck-row recovery. Problems
 * that block sending for every email (switched off, missing settings, Graph
 * refusing our credentials) no longer use up attempts.
 *
 * 🧹 #122/#123: it also gets the clean-up (under the same lock), using the
 * retention settings read through getConfig(). ⚠️ The page-shutdown fallback
 * only calls this while sending is switched ON and fully set up, so a host
 * with no cron job AND email switched off gets no clean-up - the cron runner
 * is the one that tidies up while email is off.
 *
 * The name, parameter and return shape are unchanged from before #112, so any
 * outside caller keeps working. Fail-open: it never throws.
 *
 * @param int $max Most emails to send (clamped to 1-50; default 2, the
 *                 page-shutdown budget).
 *
 * @return array{processed: int, sent: int, failed: int, skipped: int}
 *         failed = retrying + given up; skipped = not claimed or put back.
 *
 * @see runEmailQueueBatch()
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

    try {
        $batchOptions = [
            'limit'             => $max,
            'timeBudgetSeconds' => CUERCODE_EMAIL_QUEUE_SHUTDOWN_TIME_BUDGET_SECONDS,
        ];

        // 🧹 #122: the clean-up runs inside the batch (under the same lock), so
        //    it needs the owner's retention settings. Without getConfig() the
        //    batch simply uses the defaults.
        if (function_exists('getConfig')) {
            $housekeeping                         = emailQueueHousekeepingOptions('getConfig');
            $batchOptions['retentionDays']        = $housekeeping['retentionDays'];
            $batchOptions['givenUpRetentionDays'] = $housekeeping['givenUpRetentionDays'];
        }

        $result = runEmailQueueBatch(emailQueueDatabaseGateway(), 'sendEmailNow', $batchOptions);
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Email queue processing failed', $e->getMessage());
        }

        return $summary;
    }

    $summary['processed'] = (int) $result['processed'];
    $summary['sent']      = (int) $result['sent'];
    $summary['failed']    = (int) $result['retrying'] + (int) $result['gaveUp'];
    // 📌 #122/#123: 'unsendable' is a claimed row this call finished as
    //    'skipped'. A claimed row skipped for an expired link counts in
    //    'expired', which mixes rows this call claimed with rows the clean-up
    //    skipped in bulk (not rows it processed), so it is deliberately left
    //    out of this older four-number summary.
    $summary['skipped']   = (int) $result['skipped'] + (int) $result['released'] + (int) $result['unsendable'];

    return $summary;
}

// ==============================================================================
// ⏱️ maybeProcessEmailQueueOnShutdown() - OFF-by-default page-shutdown fallback
// ==============================================================================

/**
 * ⏱️ If the owner has switched it on, send up to 2 queued emails at the end of
 * this web request.
 *
 * 📬 The dependable way to send the queue is the cron runner
 * (_includes/cron/process_email_queue.php, every minute). This is a fallback
 * for hosts without cron. It only does anything when BOTH settings are 1:
 *   - `email_queue_autoprocess` (the owner opted in to this fallback), and
 *   - `email_enabled` (the email subsystem is switched on).
 * Both are seeded 0.
 *
 * 🔌 WHY it is now wired into bootstrap.php (#112): before #112 nothing called
 * this function, so setting email_queue_autoprocess to 1 silently did nothing
 * - a documented switch that does nothing is the worst kind of failure. It
 * is called for web requests only, and it is safe to leave on alongside cron
 * because both use the same locked, duplicate-proof batch.
 *
 * 🚫 WHY it is still OFF by default: it adds work to ordinary page visits; it
 * only runs when people visit, so retries stall on a quiet site; and on a
 * server without fastcgi_finish_request() the visitor waits while up to 2
 * emails are sent (up to about 20-40 seconds in the worst case).
 *
 * 🐛 The old docblock said this ran "AFTER the response has been sent". That
 * was not true on its own: PHP-FPM keeps the connection open until shutdown
 * functions finish. So the hook now calls:
 *   - session_write_close() first, because otherwise the visitor's NEXT page
 *     waits on the session file lock until sending finishes; then
 *   - fastcgi_finish_request() (where the server has it), because otherwise
 *     the visitor's page itself keeps loading until sending finishes.
 *
 * 💰 Cost when off: one cached settings read per web request.
 *
 * @return void
 */
function maybeProcessEmailQueueOnShutdown(): void
{
    if (!function_exists('getConfig')) {
        return;
    }

    try {
        // 📌 The opt-in switch is read FIRST, so the everyday cost (switch
        //    off) is a single cached settings read.
        $autoProcess = (bool) getConfig('email_queue_autoprocess', false);

        if (!$autoProcess) {
            return;
        }

        $emailEnabled = (bool) getConfig('email_enabled', false);

        if (!$emailEnabled) {
            return;
        }
    } catch (\Throwable $e) {
        // 🛡️ Fail-open: a settings lookup failure must never stop a normal
        // request from completing, and must never register a hook we cannot
        // be sure is correctly switched on.
        return;
    }

    // 🔂 Register at most once per request, however many times this is called.
    static $registered = false;

    if ($registered) {
        return;
    }

    $registered = true;

    // @see https://www.php.net/manual/en/function.register-shutdown-function.php
    register_shutdown_function(static function (): void {
        try {
            // 🔧 Only when the Graph settings are complete - otherwise every
            //    page visit would claim an email just to put it back.
            $settings = checkEmailQueueRunnerSettings('getConfig');

            if ($settings['state'] !== 'ready') {
                return;
            }

            // 🍪 Let go of the session file so the visitor's next page is not
            //    kept waiting while we send.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            // 📤 Finish the visitor's response before sending (PHP-FPM only).
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            processEmailQueue(CUERCODE_EMAIL_QUEUE_SHUTDOWN_BATCH);
        } catch (\Throwable $e) {
            // 🛡️ Shutdown functions run after the page's output - a failure
            // here must be logged, never shown.
            if (function_exists('logError')) {
                logError('error', 'Shutdown-time email queue processing failed', $e->getMessage());
            }
        }
    });
}
