<?php
/**
 * ==============================================================================
 * 🔐 CueRCode - Generic OAuth2 / OIDC SSO Client Engine (Epic E2, #55/#19/#20/#28)
 * ==============================================================================
 *
 * Path: _functions/fn_oauth.php
 *
 * A generic, provider-agnostic OAuth2/OIDC **authorization-code + PKCE**
 * client used to offer "Sign in with Microsoft 365" / "Sign in with Google"
 * / (future, currently inert) "Sign in with SIGNula" on CueRCode. Like
 * fn_auth.php, this file deliberately contains NO knowledge of HTTP request/
 * response shaping - every function takes plain scalar/array arguments and
 * returns a plain result array, so the engine is unit-testable and reusable
 * from the thin route files (public_html/auth/index.php,
 * public_html/auth/callback/index.php) and the account-settings link/unlink
 * UI (public_html/account/index.php).
 *
 * INERT BY DEFAULT (mirrors fn_email.php's email_enabled kill-switch
 * convention): a master `sso_enabled` flag (tblSettings, group 'sso') AND a
 * PER-PROVIDER `sso_<provider>_enabled` flag both default to 0, and every
 * provider's client_id/client_secret seed EMPTY. `ssoProviderIsUsable()` is
 * the single gate every entry point calls before doing anything - it fails
 * closed the moment ANY of these is unset, so this whole subsystem is fully
 * buildable and testable without ever touching a real Microsoft/Google
 * tenant, and a fresh install attempts ZERO outbound network calls related
 * to SSO until an owner deliberately configures + enables a provider via the
 * admin settings UI.
 *
 * SECURITY POSTURE (see SECURITY.md and PROJECT_ROADMAP.md Epic E2):
 *   - Authorization-code flow with MANDATORY PKCE (S256) - beginAuth()
 *     generates a fresh code_verifier/code_challenge on every attempt; the
 *     verifier never leaves the server until the token-exchange POST.
 *   - `state` (CSRF/replay defence) and `nonce` (id_token replay defence)
 *     are both cryptographically random (random_bytes(32)), bound to the
 *     PHP session, single-use (oauthConsumePendingState() burns them the
 *     instant they are read, success or failure), and short-lived
 *     (CUERCODE_OAUTH_STATE_TTL_SECONDS).
 *   - id_token is NEVER trusted on its face: the signature is verified via
 *     the provider's own JWKS (fetched over TLS, cached, RS256-only -
 *     `alg: none`/HS256 alg-confusion is explicitly rejected) using
 *     openssl_verify() - no Composer, no third-party JWT library - then
 *     `iss` (exact match against the discovery document), `aud` (== this
 *     app's client_id), `exp`/`iat` (with a small clock-skew allowance), and
 *     `nonce` (== the session-bound value) are all validated before any
 *     claim is trusted.
 *   - CONSERVATIVE ACCOUNT LINKING (the core anti-takeover defence - see
 *     decideOAuthAccountLinkAction()'s docblock for the full decision
 *     table): a brand-new SSO identity is NEVER silently linked onto an
 *     EXISTING local account that happens to share its email - that would
 *     let anyone who can obtain a verified-email login from ANY supported
 *     IdP for a given address hijack a pre-existing CueRCode account. New
 *     accounts are only auto-created when the IdP-asserted email is
 *     `email_verified=true` AND no local account already claims it.
 *     Linking an ADDITIONAL provider onto an account is a SEPARATE,
 *     authenticated-only action (from the account settings page) that
 *     proves ownership via the caller's own existing session instead.
 *   - Provider tokens (access/refresh/id) are stored ONLY as an AES-256-GCM
 *     ciphertext blob (encryptValue(), fn_encryption.php) in
 *     tblUserOAuth.tokensEncrypted - never in plaintext, never logged.
 *   - The exact redirect_uri registered with each IdP
 *     (https://cuercode.net/auth/callback/{provider}) is the ONLY one ever
 *     sent in the authorize/token requests; any caller-supplied post-login
 *     `next`/`redirect` target is validated against a same-site,
 *     root-relative-path-only allowlist (resolveSafeSameSiteRedirect())
 *     that rejects `//evil`, `https://evil`, and `javascript:` - see that
 *     function's docblock.
 *
 * @package     CueRCode
 * @subpackage  Security
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E2, #55/#19/#20/#28)
 * @see         _functions/fn_auth.php        - issueUserSession()/currentUser() (the session engine SSO hands off to)
 * @see         _functions/fn_encryption.php  - encryptValue()/decryptValue() (token-bundle-at-rest encryption)
 * @see         _functions/fn_config.php      - getConfig()/setConfig() (per-provider settings + JWKS/discovery cache)
 * @see         public_html/auth/index.php           - beginAuth() thin route (GET /auth/{provider})
 * @see         public_html/auth/callback/index.php  - completeAuth() + account-linking thin route
 * @see         https://datatracker.ietf.org/doc/html/rfc6749            - OAuth 2.0
 * @see         https://datatracker.ietf.org/doc/html/rfc7636            - PKCE
 * @see         https://openid.net/specs/openid-connect-core-1_0.html   - OpenID Connect Core (id_token)
 * @see         https://openid.net/specs/openid-connect-discovery-1_0.html - /.well-known/openid-configuration
 * @see         https://datatracker.ietf.org/doc/html/rfc7517            - JSON Web Key (JWK/JWKS)
 * @see         https://cheatsheetseries.owasp.org/cheatsheets/OAuth2_Cheat_Sheet.html
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
 * @var array<int, string> The full set of SSO providers CueRCode knows how to
 * speak to. 'signula' is present but remains structurally inert (its
 * discovery URL seeds empty and its enabled flag seeds 0 - see
 * ssoProviderIsUsable()) pending upstream OIDC IdP work in the SIGNula.id
 * repo (SIGNula issue #88 - see DEV_NOTES.md).
 */
if (!defined('CUERCODE_OAUTH_PROVIDERS')) {
    define('CUERCODE_OAUTH_PROVIDERS', ['microsoft', 'google', 'signula']);
}

/**
 * @var array<int, string> Providers considered EXPERIMENTAL for UI labelling
 * purposes only - not a security control (ssoProviderIsUsable() already
 * fails closed for 'signula' via its empty seeded discovery URL).
 */
if (!defined('CUERCODE_OAUTH_EXPERIMENTAL_PROVIDERS')) {
    define('CUERCODE_OAUTH_EXPERIMENTAL_PROVIDERS', ['signula']);
}

/**
 * @var string $_SESSION key holding the single in-flight OAuth attempt
 * (state/nonce/PKCE verifier/next-URL/link-target). Only one SSO attempt can
 * be in flight per browser session at a time - starting a new one
 * (beginAuth()) overwrites/invalidates any previous pending attempt.
 */
if (!defined('CUERCODE_OAUTH_SESSION_KEY')) {
    define('CUERCODE_OAUTH_SESSION_KEY', 'cuercode_oauth_pending');
}

/**
 * @var int How long a beginAuth() attempt remains redeemable, in seconds.
 * Generous enough for a slow IdP login/consent screen, short enough to keep
 * the replay window small.
 */
if (!defined('CUERCODE_OAUTH_STATE_TTL_SECONDS')) {
    define('CUERCODE_OAUTH_STATE_TTL_SECONDS', 600);
}

/**
 * @var int Clock-skew leeway (seconds) applied to id_token `exp`/`iat`
 * validation, to tolerate small clock drift between this host and the IdP.
 */
if (!defined('CUERCODE_OAUTH_CLOCK_SKEW_SECONDS')) {
    define('CUERCODE_OAUTH_CLOCK_SKEW_SECONDS', 60);
}

/**
 * @var int curl timeout (seconds) for EVERY outbound OAuth network call
 * (discovery, JWKS, token exchange, userinfo) - matches the task's "token
 * endpoint ≤10s" requirement, applied uniformly.
 */
if (!defined('CUERCODE_OAUTH_CURL_TIMEOUT_SECONDS')) {
    define('CUERCODE_OAUTH_CURL_TIMEOUT_SECONDS', 10);
}

/**
 * @var int How long a fetched discovery document is cached, in seconds
 * (tblSettings `sso_<provider>_discovery_cache`, group 'sso').
 */
if (!defined('CUERCODE_OAUTH_DISCOVERY_CACHE_TTL_SECONDS')) {
    define('CUERCODE_OAUTH_DISCOVERY_CACHE_TTL_SECONDS', 86400);
}

/**
 * @var int How long a fetched JWKS document is cached, in seconds
 * (tblSettings `sso_<provider>_jwks_cache`, group 'sso').
 */
if (!defined('CUERCODE_OAUTH_JWKS_CACHE_TTL_SECONDS')) {
    define('CUERCODE_OAUTH_JWKS_CACHE_TTL_SECONDS', 86400);
}

/**
 * @var string Default OIDC scope list requested when a provider has not
 * been given an explicit `sso_<provider>_scopes` override.
 */
if (!defined('CUERCODE_OAUTH_DEFAULT_SCOPES')) {
    define('CUERCODE_OAUTH_DEFAULT_SCOPES', 'openid email profile');
}

// ==============================================================================
// 🌐 base64url helpers (RFC 4648 §5) - used by PKCE and JWT (de)coding
// ==============================================================================

/**
 * 🌐 base64url-encode a raw byte string (no padding).
 *
 * @param string $data Raw bytes.
 *
 * @return string base64url-encoded text.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc4648#section-5
 */
function oauthBase64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * 🌐 base64url-decode text (tolerates missing padding) back to raw bytes.
 *
 * @param string $data base64url text.
 *
 * @return string Raw decoded bytes (empty string on decode failure).
 *
 * @see https://datatracker.ietf.org/doc/html/rfc4648#section-5
 */
function oauthBase64UrlDecode(string $data): string
{
    $remainder = strlen($data) % 4;

    if ($remainder !== 0) {
        $data .= str_repeat('=', 4 - $remainder);
    }

    $decoded = base64_decode(strtr($data, '-_', '+/'), true);

    if ($decoded === false) {
        return '';
    }

    return $decoded;
}

// ==============================================================================
// 🔑 PKCE (RFC 7636) - code_verifier / code_challenge (S256)
// ==============================================================================

/**
 * 🔑 Generate a cryptographically random PKCE code_verifier.
 *
 * 64 random bytes -> ~86 base64url characters, comfortably within RFC 7636's
 * required 43-128 character range using only the allowed unreserved
 * character set (base64url's alphabet is a strict subset of it).
 *
 * @return string The PKCE code_verifier.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7636#section-4.1
 */
function generatePkceCodeVerifier(): string
{
    return oauthBase64UrlEncode(random_bytes(64));
}

/**
 * 🔑 Derive the S256 PKCE code_challenge for a given code_verifier.
 *
 * code_challenge = BASE64URL(SHA256(ASCII(code_verifier)))
 *
 * @param string $codeVerifier The PKCE code_verifier (as produced by generatePkceCodeVerifier()).
 *
 * @return string The S256 code_challenge.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7636#section-4.2
 */
function generatePkceCodeChallenge(string $codeVerifier): string
{
    return oauthBase64UrlEncode(hash('sha256', $codeVerifier, true));
}

// ==============================================================================
// 🎲 generateOAuthState() / generateOAuthNonce() - CSRF + replay defences
// ==============================================================================

/**
 * 🎲 Generate a cryptographically random `state` value (CSRF/replay defence
 * on the authorize -> callback round trip).
 *
 * @return string 64-character lowercase hex string.
 */
function generateOAuthState(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * 🎲 Generate a cryptographically random `nonce` value (id_token replay
 * defence - bound into the id_token itself by the IdP and checked in
 * oauthValidateIdTokenClaims()).
 *
 * @return string 64-character lowercase hex string.
 */
function generateOAuthNonce(): string
{
    return bin2hex(random_bytes(32));
}

// ==============================================================================
// 🔗 resolveSafeSameSiteRedirect() - open-redirect-proof allowlist
// ==============================================================================

/**
 * 🔗 Resolve a safe post-login redirect target from a caller-supplied value.
 *
 * Only a root-relative path (starts with exactly one '/', never '//' or
 * '/\' - both browser-interpretable as protocol-relative to a DIFFERENT
 * host - and never containing a ':' which would allow a scheme such as
 * 'javascript:' or 'https:') is accepted. Anything else - including a bare
 * scheme-relative host, an absolute URL, or a data/javascript URI - falls
 * back to the provided default. Mirrors public_html/login/index.php's own
 * resolveSafeNextUrl() (kept as a separate, intentionally duplicated
 * function there to avoid disturbing already-shipped Epic E3 code); this
 * copy is the one used by the SSO initiation/callback routes and is the one
 * covered directly by tests/test_oauth.php.
 *
 * @param mixed  $rawNext The raw, untrusted `next`/`redirect` value.
 * @param string $default The safe fallback destination.
 *
 * @return string A safe, same-site, root-relative path.
 */
function resolveSafeSameSiteRedirect(mixed $rawNext, string $default): string
{
    if (!is_string($rawNext) || $rawNext === '') {
        return $default;
    }

    // 🛡️ Must start with a single '/' and NOT with '//' or '/\' (both are
    // browser-interpretable as protocol-relative to a DIFFERENT host).
    if (!str_starts_with($rawNext, '/') || str_starts_with($rawNext, '//') || str_starts_with($rawNext, '/\\')) {
        return $default;
    }

    // 🛡️ Reject anything containing a scheme separator - defence in depth
    // against something like '/x:attacker.example.com' or embedded
    // 'javascript:'/'https:' anywhere in the path/query.
    if (str_contains($rawNext, ':')) {
        return $default;
    }

    // 📏 Cap length defensively.
    if (mb_strlen($rawNext) > 512) {
        return $default;
    }

    return $rawNext;
}

// ==============================================================================
// ⚙️ Provider registry / configuration (tblSettings, group 'sso')
// ==============================================================================

/**
 * ⚙️ The list of SSO providers CueRCode knows how to speak to.
 *
 * @return array<int, string>
 */
function ssoSupportedProviders(): array
{
    return CUERCODE_OAUTH_PROVIDERS;
}

/**
 * ⚙️ Human-readable display name for a provider.
 *
 * @param string $provider Provider key.
 *
 * @return string Display name.
 */
function ssoProviderDisplayName(string $provider): string
{
    switch ($provider) {
        case 'microsoft':
            return 'Microsoft 365';
            break;
        case 'google':
            return 'Google';
            break;
        case 'signula':
            return 'SIGNula';
            break;
        default:
            return ucfirst($provider);
            break;
    }
}

/**
 * ⚙️ FontAwesome icon class for a provider's sign-in button.
 *
 * @param string $provider Provider key.
 *
 * @return string FontAwesome class string.
 */
function ssoProviderIconClass(string $provider): string
{
    switch ($provider) {
        case 'microsoft':
            return 'fa-brands fa-microsoft';
            break;
        case 'google':
            return 'fa-brands fa-google';
            break;
        case 'signula':
            return 'fa-solid fa-id-badge';
            break;
        default:
            return 'fa-solid fa-right-to-bracket';
            break;
    }
}

/**
 * ⚙️ Whether a provider is currently flagged EXPERIMENTAL for UI labelling
 * purposes (currently only 'signula', pending upstream OIDC support -
 * SIGNula issue #88).
 *
 * @param string $provider Provider key.
 *
 * @return bool
 */
function ssoProviderIsExperimental(string $provider): bool
{
    return in_array($provider, CUERCODE_OAUTH_EXPERIMENTAL_PROVIDERS, true);
}

/**
 * ⚙️ Master SSO kill-switch (tblSettings `sso_enabled`, group 'sso').
 *
 * Mirrors fn_email.php's `email_enabled` convention: an EXTRA layer of
 * "everything OFF by default" on top of the per-provider flags, so a single
 * admin toggle can take the whole subsystem down without touching every
 * provider row individually.
 *
 * @return bool
 */
function ssoMasterEnabled(): bool
{
    if (!function_exists('getConfig')) {
        return false;
    }

    return (bool) getConfig('sso_enabled', false);
}

/**
 * ⚙️ Resolve the exact redirect_uri registered with a provider's IdP app
 * registration: `{site_url}/auth/callback/{provider}`. This is the ONLY
 * redirect_uri ever sent in the authorize/token requests - it must match
 * BYTE-FOR-BYTE what the owner registered with the IdP (Entra ID/Google
 * Cloud Console reject a mismatched redirect_uri outright).
 *
 * @param string $provider Provider key.
 *
 * @return string Absolute callback URL.
 */
function ssoRedirectUri(string $provider): string
{
    $baseUrl = 'https://cuercode.net';

    if (function_exists('getConfig')) {
        $configuredUrl = getConfig('site_url', $baseUrl);
        if (is_string($configuredUrl) && $configuredUrl !== '') {
            $baseUrl = rtrim($configuredUrl, '/');
        }
    }

    return $baseUrl . '/auth/callback/' . rawurlencode($provider);
}

/**
 * ⚙️ Fetch a provider's full configuration from tblSettings (group 'sso').
 *
 * @param string $provider Provider key.
 *
 * @return array{valid: bool, enabled: bool, clientId: string, clientSecret: string, discoveryUrl: string, scopes: string, redirectUri: string}
 */
function ssoProviderConfig(string $provider): array
{
    if (!in_array($provider, CUERCODE_OAUTH_PROVIDERS, true)) {
        return [
            'valid'        => false,
            'enabled'      => false,
            'clientId'     => '',
            'clientSecret' => '',
            'discoveryUrl' => '',
            'scopes'       => '',
            'redirectUri'  => '',
        ];
    }

    $enabled      = false;
    $clientId     = '';
    $clientSecret = '';
    $discoveryUrl = '';
    $scopes       = CUERCODE_OAUTH_DEFAULT_SCOPES;

    if (function_exists('getConfig')) {
        $enabled      = (bool) getConfig('sso_' . $provider . '_enabled', false);
        $clientId     = trim((string) getConfig('sso_' . $provider . '_client_id', ''));
        $clientSecret = trim((string) getConfig('sso_' . $provider . '_client_secret', ''));
        $discoveryUrl = trim((string) getConfig('sso_' . $provider . '_discovery_url', ''));

        $configuredScopes = trim((string) getConfig('sso_' . $provider . '_scopes', ''));
        if ($configuredScopes !== '') {
            $scopes = $configuredScopes;
        }
    }

    return [
        'valid'        => true,
        'enabled'      => $enabled,
        'clientId'     => $clientId,
        'clientSecret' => $clientSecret,
        'discoveryUrl' => $discoveryUrl,
        'scopes'       => $scopes,
        'redirectUri'  => ssoRedirectUri($provider),
    ];
}

/**
 * ⚙️ Whether a provider is FULLY usable right now: the master switch is on,
 * the provider's own flag is on, and it has a non-empty client_id,
 * client_secret, AND discovery URL. This is the SINGLE gate every entry
 * point (login/register button rendering, beginAuth(), completeAuth())
 * checks - a fresh install (or 'signula' pending upstream support) fails
 * this closed automatically without any special-casing.
 *
 * @param string $provider Provider key.
 *
 * @return bool
 */
function ssoProviderIsUsable(string $provider): bool
{
    if (!ssoMasterEnabled()) {
        return false;
    }

    $config = ssoProviderConfig($provider);

    if (!$config['valid'] || !$config['enabled']) {
        return false;
    }

    if ($config['clientId'] === '' || $config['clientSecret'] === '' || $config['discoveryUrl'] === '') {
        return false;
    }

    return true;
}

/**
 * ⚙️ Bundle of display metadata for rendering a provider's sign-in button
 * (or its absence) on login/register/account pages.
 *
 * @param string $provider Provider key.
 *
 * @return array{provider: string, displayName: string, iconClass: string, usable: bool, experimental: bool}
 */
function ssoProviderUiMeta(string $provider): array
{
    return [
        'provider'     => $provider,
        'displayName'  => ssoProviderDisplayName($provider),
        'iconClass'    => ssoProviderIconClass($provider),
        'usable'       => ssoProviderIsUsable($provider),
        'experimental' => ssoProviderIsExperimental($provider),
    ];
}

// ==============================================================================
// 🌐 Outbound HTTP helpers (curl, no Composer) - discovery / JWKS / userinfo
// ==============================================================================

/**
 * 🌐 GET a URL and decode a JSON object response. Generic helper shared by
 * the discovery-document and JWKS fetchers.
 *
 * @param string $url Absolute HTTPS URL.
 *
 * @return array|null Decoded JSON object, or null on any transport/parse failure.
 */
function oauthHttpGetJson(string $url): ?array
{
    $curlHandle = curl_init();

    if ($curlHandle === false) {
        return null;
    }

    curl_setopt_array($curlHandle, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => CUERCODE_OAUTH_CURL_TIMEOUT_SECONDS,
        CURLOPT_CONNECTTIMEOUT => CUERCODE_OAUTH_CURL_TIMEOUT_SECONDS,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        // 🔒 Never relax TLS verification, even for a "just testing" call.
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $responseBody = curl_exec($curlHandle);
    $curlErrno    = curl_errno($curlHandle);
    $httpCode     = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
    curl_close($curlHandle);

    if ($curlErrno !== 0 || $responseBody === false || $httpCode !== 200) {
        return null;
    }

    $decoded = json_decode((string) $responseBody, true);

    if (!is_array($decoded)) {
        return null;
    }

    return $decoded;
}

/**
 * 🌐 Fetch (and cache) a provider's OIDC discovery document
 * (`/.well-known/openid-configuration`).
 *
 * @param string $provider     Provider key (used as the cache-key namespace).
 * @param string $discoveryUrl The provider's discovery URL (tblSettings `sso_<provider>_discovery_url`).
 *
 * @return array|null The discovery document, or null if unavailable/invalid.
 *
 * @see https://openid.net/specs/openid-connect-discovery-1_0.html
 */
function oauthFetchDiscoveryDocument(string $provider, string $discoveryUrl): ?array
{
    if ($discoveryUrl === '') {
        return null;
    }

    $cacheKey = 'sso_' . $provider . '_discovery_cache';

    if (function_exists('getConfig')) {
        $cached = getConfig($cacheKey, []);

        if (is_array($cached) && isset($cached['fetched_at'], $cached['data']) && is_array($cached['data'])) {
            if ((time() - (int) $cached['fetched_at']) < CUERCODE_OAUTH_DISCOVERY_CACHE_TTL_SECONDS) {
                return $cached['data'];
            }
        }
    }

    $document = oauthHttpGetJson($discoveryUrl);

    if ($document === null) {
        if (function_exists('logError')) {
            logError('warning', 'OAuth discovery document fetch failed', 'Provider: ' . $provider);
        }
        return null;
    }

    // ✅ Require exactly the fields this client depends on further down the
    // flow - a discovery document missing any of these is unusable to us
    // regardless of what else it contains.
    if (!isset($document['issuer'], $document['authorization_endpoint'], $document['token_endpoint'], $document['jwks_uri'])) {
        if (function_exists('logError')) {
            logError('error', 'OAuth discovery document missing required fields', 'Provider: ' . $provider);
        }
        return null;
    }

    if (function_exists('setConfig')) {
        $cachePayload = json_encode(['fetched_at' => time(), 'data' => $document], JSON_UNESCAPED_SLASHES);

        if ($cachePayload !== false) {
            setConfig($cacheKey, $cachePayload, [
                'group'       => 'sso',
                'type'        => 'json',
                'isSensitive' => false,
                'isEditable'  => false,
                'description' => 'Cached OIDC discovery document for ' . $provider . ' (auto-managed; do not edit)',
            ]);
        }
    }

    return $document;
}

/**
 * 🌐 Fetch (and cache) a provider's JWKS (public signing keys).
 *
 * @param string $provider Provider key (used as the cache-key namespace).
 * @param string $jwksUri  The provider's JWKS URI (from its discovery document).
 *
 * @return array|null The JWKS document ({"keys": [...]}), or null if unavailable/invalid.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7517
 */
function oauthFetchJwks(string $provider, string $jwksUri): ?array
{
    if ($jwksUri === '') {
        return null;
    }

    $cacheKey = 'sso_' . $provider . '_jwks_cache';

    if (function_exists('getConfig')) {
        $cached = getConfig($cacheKey, []);

        if (is_array($cached) && isset($cached['fetched_at'], $cached['data']) && is_array($cached['data'])) {
            if ((time() - (int) $cached['fetched_at']) < CUERCODE_OAUTH_JWKS_CACHE_TTL_SECONDS) {
                return $cached['data'];
            }
        }
    }

    $jwks = oauthHttpGetJson($jwksUri);

    if ($jwks === null || !isset($jwks['keys']) || !is_array($jwks['keys'])) {
        if (function_exists('logError')) {
            logError('warning', 'OAuth JWKS fetch failed', 'Provider: ' . $provider);
        }
        return null;
    }

    if (function_exists('setConfig')) {
        $cachePayload = json_encode(['fetched_at' => time(), 'data' => $jwks], JSON_UNESCAPED_SLASHES);

        if ($cachePayload !== false) {
            setConfig($cacheKey, $cachePayload, [
                'group'       => 'sso',
                'type'        => 'json',
                'isSensitive' => false,
                'isEditable'  => false,
                'description' => 'Cached JWKS (public signing keys) for ' . $provider . ' (auto-managed; do not edit)',
            ]);
        }
    }

    return $jwks;
}

/**
 * 🌐 Exchange an authorization code (+ PKCE verifier) for tokens at the
 * provider's token endpoint. TLS-only, ≤CUERCODE_OAUTH_CURL_TIMEOUT_SECONDS.
 *
 * @param string $tokenEndpoint The provider's token endpoint URL.
 * @param string $clientId      This app's registered client_id.
 * @param string $clientSecret  This app's registered client_secret.
 * @param string $code          The authorization code from the callback.
 * @param string $codeVerifier  The PKCE code_verifier generated in beginAuth().
 * @param string $redirectUri   The EXACT redirect_uri used in the original authorize request.
 *
 * @return array|null The decoded token response ({id_token, access_token, ...}), or null on failure.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6749#section-4.1.3
 */
function oauthExchangeCodeForTokens(
    string $tokenEndpoint,
    string $clientId,
    string $clientSecret,
    string $code,
    string $codeVerifier,
    string $redirectUri
): ?array {
    $postFields = http_build_query([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => $redirectUri,
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'code_verifier' => $codeVerifier,
    ]);

    $curlHandle = curl_init();

    if ($curlHandle === false) {
        if (function_exists('logError')) {
            logError('error', 'OAuth token exchange curl_init() failed', '');
        }
        return null;
    }

    curl_setopt_array($curlHandle, [
        CURLOPT_URL            => $tokenEndpoint,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => CUERCODE_OAUTH_CURL_TIMEOUT_SECONDS,
        CURLOPT_CONNECTTIMEOUT => CUERCODE_OAUTH_CURL_TIMEOUT_SECONDS,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        // 🔒 Never relax TLS verification, even for a "just testing" call.
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $responseBody = curl_exec($curlHandle);
    $curlErrno    = curl_errno($curlHandle);
    $curlError    = curl_error($curlHandle);
    $httpCode     = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
    curl_close($curlHandle);

    // ❌ Transport-level failure. NEVER log $postFields (contains
    // client_secret + code_verifier) - only the curl error text.
    if ($curlErrno !== 0 || $responseBody === false) {
        if (function_exists('logError')) {
            logError('error', 'OAuth token exchange transport error', 'curl errno ' . $curlErrno . ': ' . $curlError);
        }
        return null;
    }

    $decoded = json_decode((string) $responseBody, true);

    // ❌ Non-200 or a response missing id_token. NEVER log the raw response
    // body - some providers' error payloads can echo request fragments.
    if ($httpCode !== 200 || !is_array($decoded) || !isset($decoded['id_token'])) {
        if (function_exists('logError')) {
            logError('warning', 'OAuth token exchange failed', 'HTTP ' . $httpCode);
        }
        return null;
    }

    return $decoded;
}

/**
 * 🌐 Best-effort corroboration call to the provider's userinfo endpoint.
 *
 * OPTIONAL defence-in-depth only (per the task spec) - the id_token is
 * already cryptographically verified before this is ever called, so a
 * transport failure here does NOT block sign-in. A `sub` MISMATCH between
 * this response and the id_token, however, IS treated as a hard failure by
 * the caller (completeAuth()) - see that function.
 *
 * @param string $userinfoEndpoint The provider's userinfo endpoint URL.
 * @param string $accessToken      The access_token from the token exchange.
 *
 * @return array|null Decoded userinfo claims, or null on any failure.
 */
function oauthFetchUserInfo(string $userinfoEndpoint, string $accessToken): ?array
{
    $curlHandle = curl_init();

    if ($curlHandle === false) {
        return null;
    }

    curl_setopt_array($curlHandle, [
        CURLOPT_URL            => $userinfoEndpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => CUERCODE_OAUTH_CURL_TIMEOUT_SECONDS,
        CURLOPT_CONNECTTIMEOUT => CUERCODE_OAUTH_CURL_TIMEOUT_SECONDS,
        // 🔒 The bearer token travels ONLY in the header, never logged.
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Authorization: Bearer ' . $accessToken],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $responseBody = curl_exec($curlHandle);
    $curlErrno    = curl_errno($curlHandle);
    $httpCode     = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
    curl_close($curlHandle);

    if ($curlErrno !== 0 || $responseBody === false || $httpCode !== 200) {
        return null;
    }

    $decoded = json_decode((string) $responseBody, true);

    if (!is_array($decoded)) {
        return null;
    }

    return $decoded;
}

// ==============================================================================
// 🧩 JWT (JWS) decode + RS256 signature verification (no Composer)
// ==============================================================================

/**
 * 🧩 Split a compact JWT into its header/payload/signature parts.
 *
 * @param string $jwt The compact JWT string (header.payload.signature).
 *
 * @return array{header: array, payload: array, signingInput: string, signature: string}|null
 *         null if the token is malformed (wrong segment count or invalid JSON).
 */
function oauthDecodeJwtSegments(string $jwt): ?array
{
    $parts = explode('.', $jwt);

    if (count($parts) !== 3) {
        return null;
    }

    [$headerB64, $payloadB64, $signatureB64] = $parts;

    $header  = json_decode(oauthBase64UrlDecode($headerB64), true);
    $payload = json_decode(oauthBase64UrlDecode($payloadB64), true);

    if (!is_array($header) || !is_array($payload)) {
        return null;
    }

    return [
        'header'       => $header,
        'payload'      => $payload,
        // 📌 The signing input is the RAW base64url text of header+payload
        // (NOT the decoded JSON) - the signature covers these exact bytes.
        'signingInput' => $headerB64 . '.' . $payloadB64,
        'signature'    => oauthBase64UrlDecode($signatureB64),
    ];
}

/**
 * 🧩 DER length encoding (ASN.1 definite-length form).
 *
 * @param int $length Content length in bytes.
 *
 * @return string DER-encoded length bytes.
 */
function oauthAsn1Length(int $length): string
{
    if ($length < 0x80) {
        return chr($length);
    }

    $lengthBytes = '';

    while ($length > 0) {
        $lengthBytes = chr($length & 0xFF) . $lengthBytes;
        $length     >>= 8;
    }

    return chr(0x80 | strlen($lengthBytes)) . $lengthBytes;
}

/**
 * 🧩 DER-encode a big-endian unsigned integer (ASN.1 INTEGER, tag 0x02).
 *
 * @param string $bytes Raw big-endian unsigned integer bytes (as decoded from a JWK's base64url 'n'/'e').
 *
 * @return string DER-encoded INTEGER.
 */
function oauthAsn1Integer(string $bytes): string
{
    $bytes = ltrim($bytes, "\x00");

    if ($bytes === '') {
        $bytes = "\x00";
    }

    // 📌 DER requires a leading 0x00 pad byte whenever the high bit of the
    // first byte is set, so the value is never misread as negative.
    if ((ord($bytes[0]) & 0x80) !== 0) {
        $bytes = "\x00" . $bytes;
    }

    return "\x02" . oauthAsn1Length(strlen($bytes)) . $bytes;
}

/**
 * 🧩 DER-encode an ASN.1 SEQUENCE (tag 0x30) wrapping already-encoded content.
 *
 * @param string $contents Already DER-encoded child elements, concatenated.
 *
 * @return string DER-encoded SEQUENCE.
 */
function oauthAsn1Sequence(string $contents): string
{
    return "\x30" . oauthAsn1Length(strlen($contents)) . $contents;
}

/**
 * 🧩 DER-encode an ASN.1 BIT STRING (tag 0x03) with a zero unused-bits prefix.
 *
 * @param string $contents The raw bit-string content (e.g. a DER SEQUENCE to embed).
 *
 * @return string DER-encoded BIT STRING.
 */
function oauthAsn1BitString(string $contents): string
{
    $withUnusedBitsPrefix = "\x00" . $contents;

    return "\x03" . oauthAsn1Length(strlen($withUnusedBitsPrefix)) . $withUnusedBitsPrefix;
}

/**
 * 🧩 Build a PEM-encoded RSA public key (X.509 SubjectPublicKeyInfo) from a
 * JWK's base64url-encoded modulus (`n`) and exponent (`e`) - the standard
 * hand-rolled ASN.1/DER technique used by no-dependency JWT verifiers, since
 * PHP's openssl extension has no built-in "build key from raw n/e" call.
 *
 * @param string $modulusB64Url  The JWK 'n' value (base64url).
 * @param string $exponentB64Url The JWK 'e' value (base64url).
 *
 * @return string|null PEM-encoded public key, or null if the inputs decode empty.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7517
 * @see https://datatracker.ietf.org/doc/html/rfc3447 (RSAPublicKey / X.509 SubjectPublicKeyInfo)
 */
function oauthRsaJwkToPem(string $modulusB64Url, string $exponentB64Url): ?string
{
    $modulusBytes  = oauthBase64UrlDecode($modulusB64Url);
    $exponentBytes = oauthBase64UrlDecode($exponentB64Url);

    if ($modulusBytes === '' || $exponentBytes === '') {
        return null;
    }

    // RSAPublicKey ::= SEQUENCE { modulus INTEGER, publicExponent INTEGER }
    $rsaPublicKeySequence = oauthAsn1Sequence(
        oauthAsn1Integer($modulusBytes) . oauthAsn1Integer($exponentBytes)
    );

    // AlgorithmIdentifier ::= SEQUENCE { algorithm OBJECT IDENTIFIER (rsaEncryption, 1.2.840.113549.1.1.1), parameters NULL }
    $rsaEncryptionOidAndNull = "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01" . "\x05\x00";
    $algorithmIdentifier     = oauthAsn1Sequence($rsaEncryptionOidAndNull);

    // SubjectPublicKeyInfo ::= SEQUENCE { algorithm AlgorithmIdentifier, subjectPublicKey BIT STRING }
    $subjectPublicKeyInfo = oauthAsn1Sequence(
        $algorithmIdentifier . oauthAsn1BitString($rsaPublicKeySequence)
    );

    $pemBody = chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n");

    return "-----BEGIN PUBLIC KEY-----\n" . $pemBody . "-----END PUBLIC KEY-----\n";
}

/**
 * 🧩 Find the JWK matching a given `kid` (key ID) within a JWKS document.
 *
 * Falls back to the sole key in the set when no `kid` was supplied by the
 * token AND the JWKS contains exactly one key (some providers omit `kid`
 * entirely while they only have one active signing key).
 *
 * @param array       $jwks The JWKS document ({"keys": [...]}).
 * @param string|null $kid  The `kid` from the token's header, or null.
 *
 * @return array|null The matching JWK, or null if none found.
 */
function oauthFindJwkByKid(array $jwks, ?string $kid): ?array
{
    if (!isset($jwks['keys']) || !is_array($jwks['keys'])) {
        return null;
    }

    foreach ($jwks['keys'] as $jwk) {
        if (!is_array($jwk)) {
            continue;
        }

        if ($kid !== null && isset($jwk['kid']) && hash_equals((string) $jwk['kid'], $kid)) {
            return $jwk;
        }
    }

    if ($kid === null && count($jwks['keys']) === 1 && is_array($jwks['keys'][0])) {
        return $jwks['keys'][0];
    }

    return null;
}

/**
 * 🧩 Verify a compact JWT's RS256 signature against a JWKS document and
 * return its decoded claims on success.
 *
 * Explicitly rejects any `alg` other than `RS256` - this is the alg-
 * confusion defence: without it, an attacker could submit a token signed
 * with `alg: HS256` using the PUBLICLY KNOWN RSA public key bytes as the
 * HMAC secret (a well-known JWT library vulnerability class), or `alg: none`
 * with no signature at all.
 *
 * @param string $jwt  The compact id_token JWT string.
 * @param array  $jwks The provider's JWKS document.
 *
 * @return array{valid: bool, claims: array|null, errorCode: string|null, message: string|null}
 */
function oauthVerifyJwtSignatureRS256(string $jwt, array $jwks): array
{
    $decoded = oauthDecodeJwtSegments($jwt);

    if ($decoded === null) {
        return [
            'valid'     => false,
            'claims'    => null,
            'errorCode' => 'ID_TOKEN_MALFORMED',
            'message'   => 'The ID token is malformed.',
        ];
    }

    $algorithm = $decoded['header']['alg'] ?? null;

    if ($algorithm !== 'RS256') {
        return [
            'valid'     => false,
            'claims'    => null,
            'errorCode' => 'ID_TOKEN_UNSUPPORTED_ALG',
            'message'   => 'The ID token uses an unsupported signing algorithm.',
        ];
    }

    $keyId = $decoded['header']['kid'] ?? null;
    $jwk   = oauthFindJwkByKid($jwks, is_string($keyId) ? $keyId : null);

    if ($jwk === null || !isset($jwk['n'], $jwk['e'])) {
        return [
            'valid'     => false,
            'claims'    => null,
            'errorCode' => 'ID_TOKEN_KEY_NOT_FOUND',
            'message'   => 'No matching signing key was found for this ID token.',
        ];
    }

    $publicKeyPem = oauthRsaJwkToPem((string) $jwk['n'], (string) $jwk['e']);

    if ($publicKeyPem === null) {
        return [
            'valid'     => false,
            'claims'    => null,
            'errorCode' => 'ID_TOKEN_KEY_INVALID',
            'message'   => 'The signing key could not be decoded.',
        ];
    }

    // @see https://www.php.net/manual/en/function.openssl-pkey-get-public.php
    $publicKeyHandle = openssl_pkey_get_public($publicKeyPem);

    if ($publicKeyHandle === false) {
        return [
            'valid'     => false,
            'claims'    => null,
            'errorCode' => 'ID_TOKEN_KEY_INVALID',
            'message'   => 'The signing key is not a valid RSA public key.',
        ];
    }

    // @see https://www.php.net/manual/en/function.openssl-verify.php
    $verifyResult = openssl_verify($decoded['signingInput'], $decoded['signature'], $publicKeyHandle, OPENSSL_ALGO_SHA256);

    if ($verifyResult !== 1) {
        return [
            'valid'     => false,
            'claims'    => null,
            'errorCode' => 'ID_TOKEN_INVALID_SIGNATURE',
            'message'   => 'The ID token signature is invalid.',
        ];
    }

    return [
        'valid'     => true,
        'claims'    => $decoded['payload'],
        'errorCode' => null,
        'message'   => null,
    ];
}

/**
 * 🧩 Validate the STANDARD OIDC claims of an already signature-verified
 * id_token payload: `iss` (exact match), `aud` (contains this app's
 * client_id), `exp`/`iat` (not expired / not issued in the future, with
 * clock-skew leeway), `nonce` (matches the session-bound value), and `sub`
 * (present and non-empty).
 *
 * PURE function - no I/O, no session/database access - fully unit-testable
 * (mirrors fn_auth.php's computeLockoutExpiry() convention).
 *
 * @param array  $claims             The decoded id_token payload.
 * @param string $expectedIssuer     The provider's discovery-document `issuer`.
 * @param string $expectedAudience   This app's client_id.
 * @param string $expectedNonce      The session-bound nonce from beginAuth().
 * @param int    $now                Current Unix timestamp (injected for testability).
 * @param int    $clockSkewSeconds   Allowed clock-skew leeway, in seconds.
 *
 * @return array{valid: bool, errorCode: string|null, message: string|null}
 */
function oauthValidateIdTokenClaims(
    array $claims,
    string $expectedIssuer,
    string $expectedAudience,
    string $expectedNonce,
    int $now,
    int $clockSkewSeconds
): array {
    $issuer = $claims['iss'] ?? null;

    if (!is_string($issuer) || !hash_equals($expectedIssuer, $issuer)) {
        return [
            'valid'     => false,
            'errorCode' => 'ID_TOKEN_INVALID_ISSUER',
            'message'   => 'The ID token issuer does not match the expected provider.',
        ];
    }

    $audienceClaim = $claims['aud'] ?? null;
    $audienceMatches = false;

    if (is_string($audienceClaim)) {
        $audienceMatches = hash_equals($expectedAudience, $audienceClaim);
    } elseif (is_array($audienceClaim)) {
        foreach ($audienceClaim as $singleAudience) {
            if (is_string($singleAudience) && hash_equals($expectedAudience, $singleAudience)) {
                $audienceMatches = true;
                break;
            }
        }
    }

    if (!$audienceMatches) {
        return [
            'valid'     => false,
            'errorCode' => 'ID_TOKEN_INVALID_AUDIENCE',
            'message'   => 'The ID token audience does not match this application.',
        ];
    }

    $expiresAtClaim = $claims['exp'] ?? null;

    if (!is_int($expiresAtClaim) && !is_float($expiresAtClaim)) {
        return [
            'valid'     => false,
            'errorCode' => 'ID_TOKEN_MISSING_EXP',
            'message'   => 'The ID token is missing an expiry claim.',
        ];
    }

    if (((int) $expiresAtClaim) < ($now - $clockSkewSeconds)) {
        return [
            'valid'     => false,
            'errorCode' => 'ID_TOKEN_EXPIRED',
            'message'   => 'The ID token has expired.',
        ];
    }

    $issuedAtClaim = $claims['iat'] ?? null;

    if (($issuedAtClaim !== null) && (is_int($issuedAtClaim) || is_float($issuedAtClaim))) {
        if (((int) $issuedAtClaim) > ($now + $clockSkewSeconds)) {
            return [
                'valid'     => false,
                'errorCode' => 'ID_TOKEN_INVALID_IAT',
                'message'   => 'The ID token was issued in the future.',
            ];
        }
    }

    $nonceClaim = $claims['nonce'] ?? null;

    if (!is_string($nonceClaim) || !hash_equals($expectedNonce, $nonceClaim)) {
        return [
            'valid'     => false,
            'errorCode' => 'ID_TOKEN_INVALID_NONCE',
            'message'   => 'The ID token nonce does not match this sign-in attempt.',
        ];
    }

    $subjectClaim = $claims['sub'] ?? null;

    if (!is_string($subjectClaim) || $subjectClaim === '') {
        return [
            'valid'     => false,
            'errorCode' => 'ID_TOKEN_MISSING_SUB',
            'message'   => 'The ID token is missing a subject identifier.',
        ];
    }

    return [
        'valid'     => true,
        'errorCode' => null,
        'message'   => null,
    ];
}

/**
 * 🧩 Compose signature verification + claim validation into one call.
 *
 * @param string      $idToken          The compact id_token JWT string.
 * @param array       $jwks             The provider's JWKS document.
 * @param string      $expectedIssuer   The provider's discovery-document `issuer`.
 * @param string      $expectedAudience This app's client_id.
 * @param string      $expectedNonce    The session-bound nonce from beginAuth().
 * @param int|null    $now              Current Unix timestamp override (for testability); defaults to time().
 *
 * @return array{valid: bool, claims: array|null, errorCode: string|null, message: string|null}
 */
function oauthValidateAndDecodeIdToken(
    string $idToken,
    array $jwks,
    string $expectedIssuer,
    string $expectedAudience,
    string $expectedNonce,
    ?int $now = null
): array {
    if ($now === null) {
        $now = time();
    }

    $signatureResult = oauthVerifyJwtSignatureRS256($idToken, $jwks);

    if (!$signatureResult['valid']) {
        return [
            'valid'     => false,
            'claims'    => null,
            'errorCode' => $signatureResult['errorCode'],
            'message'   => $signatureResult['message'],
        ];
    }

    $claimsResult = oauthValidateIdTokenClaims(
        $signatureResult['claims'],
        $expectedIssuer,
        $expectedAudience,
        $expectedNonce,
        $now,
        CUERCODE_OAUTH_CLOCK_SKEW_SECONDS
    );

    if (!$claimsResult['valid']) {
        return [
            'valid'     => false,
            'claims'    => null,
            'errorCode' => $claimsResult['errorCode'],
            'message'   => $claimsResult['message'],
        ];
    }

    return [
        'valid'     => true,
        'claims'    => $signatureResult['claims'],
        'errorCode' => null,
        'message'   => null,
    ];
}

// ==============================================================================
// 🚦 beginAuth() - Start an authorization-code + PKCE flow
// ==============================================================================

/**
 * 🚦 Begin an SSO sign-in/link attempt: validates the provider is usable,
 * fetches its discovery document, generates a fresh state/nonce/PKCE pair,
 * binds them (plus the safe post-login destination and, if the CALLER is
 * already authenticated, the target user to link onto) to the PHP session,
 * and returns the provider's authorize URL for the thin route to redirect
 * to.
 *
 * Whether this is a "sign in / register" attempt or a "link an additional
 * provider" attempt is determined ENTIRELY by whether currentUser() resolves
 * a session at the moment this is called - completeAuth() + the
 * account-linking decision table then branch on that captured
 * `link_user_id` (see decideOAuthAccountLinkAction()'s docblock).
 *
 * @param string      $provider  Provider key ('microsoft'|'google'|'signula').
 * @param string|null $rawNextUrl Caller-supplied post-login destination (validated - see resolveSafeSameSiteRedirect()).
 *
 * @return array{success: bool, httpCode: int, errorCode: string|null, message: string, data: array}
 *         data['authorizeUrl'] holds the URL to redirect the browser to on success.
 */
function beginAuth(string $provider, ?string $rawNextUrl = null): array
{
    if (!in_array($provider, CUERCODE_OAUTH_PROVIDERS, true)) {
        return [
            'success'   => false,
            'httpCode'  => 404,
            'errorCode' => 'SSO_PROVIDER_UNKNOWN',
            'message'   => 'Unknown SSO provider.',
            'data'      => [],
        ];
    }

    if (!ssoProviderIsUsable($provider)) {
        return [
            'success'   => false,
            'httpCode'  => 503,
            'errorCode' => 'SSO_PROVIDER_DISABLED',
            'message'   => ssoProviderDisplayName($provider) . ' sign-in is not currently available.',
            'data'      => [],
        ];
    }

    $config = ssoProviderConfig($provider);

    $discoveryDocument = oauthFetchDiscoveryDocument($provider, $config['discoveryUrl']);

    if ($discoveryDocument === null) {
        return [
            'success'   => false,
            'httpCode'  => 503,
            'errorCode' => 'SSO_DISCOVERY_UNAVAILABLE',
            'message'   => 'Unable to reach the ' . ssoProviderDisplayName($provider) . ' sign-in service right now. Please try again shortly.',
            'data'      => [],
        ];
    }

    $state         = generateOAuthState();
    $nonce         = generateOAuthNonce();
    $codeVerifier  = generatePkceCodeVerifier();
    $codeChallenge = generatePkceCodeChallenge($codeVerifier);

    $safeNextUrl = resolveSafeSameSiteRedirect($rawNextUrl, '/account/');

    // 👤 If the CALLER is already authenticated, this is a "link an
    // additional provider" attempt from account settings - capture that
    // target user now so completeAuth()/applyOAuthAccountLink() can bind the
    // new identity to THIS specific account rather than treating it as a
    // fresh sign-in.
    $requestingUser = function_exists('currentUser') ? currentUser() : null;
    $linkUserId     = ($requestingUser !== null) ? (int) $requestingUser['userID'] : null;

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    // 📌 Only ONE pending SSO attempt is tracked per browser session - a new
    // beginAuth() call always overwrites (invalidates) any prior one.
    $_SESSION[CUERCODE_OAUTH_SESSION_KEY] = [
        'provider'     => $provider,
        'state'        => $state,
        'nonce'        => $nonce,
        'verifier'     => $codeVerifier,
        'next'         => $safeNextUrl,
        'link_user_id' => $linkUserId,
        'expires_at'   => time() + CUERCODE_OAUTH_STATE_TTL_SECONDS,
    ];

    $authorizeParams = [
        'client_id'             => $config['clientId'],
        'redirect_uri'          => $config['redirectUri'],
        'response_type'         => 'code',
        'scope'                 => $config['scopes'],
        'state'                 => $state,
        'nonce'                 => $nonce,
        'code_challenge'        => $codeChallenge,
        'code_challenge_method' => 'S256',
    ];

    $authorizeUrl = $discoveryDocument['authorization_endpoint'] . '?' . http_build_query($authorizeParams);

    return [
        'success'   => true,
        'httpCode'  => 302,
        'errorCode' => null,
        'message'   => 'Redirecting to ' . ssoProviderDisplayName($provider) . '...',
        'data'      => ['authorizeUrl' => $authorizeUrl],
    ];
}

// ==============================================================================
// 🔒 oauthConsumePendingState() - Single-use state/nonce/verifier retrieval
// ==============================================================================

/**
 * 🔒 Retrieve AND immediately burn the pending SSO attempt bound to the
 * current PHP session, verifying it matches the given provider + state.
 *
 * Single-use by design: the pending attempt is unset from the session the
 * MOMENT this is called, regardless of whether it turns out to be valid -
 * a second callback request (browser back-button replay, a duplicated
 * redirect, or a deliberate replay attempt) can never reuse it.
 *
 * @param string $provider The provider key from the callback URL.
 * @param string $state    The `state` query parameter from the callback URL.
 *
 * @return array|null The pending attempt array, or null if missing/mismatched/expired.
 */
function oauthConsumePendingState(string $provider, string $state): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!isset($_SESSION[CUERCODE_OAUTH_SESSION_KEY]) || !is_array($_SESSION[CUERCODE_OAUTH_SESSION_KEY])) {
        return null;
    }

    $pending = $_SESSION[CUERCODE_OAUTH_SESSION_KEY];

    // 🔥 Burn it now - single-use, whatever the outcome below.
    unset($_SESSION[CUERCODE_OAUTH_SESSION_KEY]);

    if (!isset($pending['provider'], $pending['state'], $pending['nonce'], $pending['verifier'], $pending['expires_at'])) {
        return null;
    }

    if ((string) $pending['provider'] !== $provider) {
        return null;
    }

    // 🛡️ Timing-safe comparison - this IS the CSRF/replay defence on the
    // authorize -> callback round trip.
    if (!hash_equals((string) $pending['state'], $state)) {
        return null;
    }

    if ((int) $pending['expires_at'] < time()) {
        return null;
    }

    return $pending;
}

// ==============================================================================
// ✅ completeAuth() - Exchange code, verify id_token, return the verified identity
// ==============================================================================

/**
 * ✅ Complete an SSO callback: verifies `state`, exchanges the authorization
 * code (+ PKCE verifier) for tokens, verifies the id_token's signature
 * (JWKS/RS256) and standard claims (iss/aud/exp/iat/nonce), optionally
 * corroborates via the userinfo endpoint, and returns the verified subject +
 * email + email_verified + an AES-256-GCM-encrypted token bundle ready for
 * storage.
 *
 * Deliberately does NOT perform account linking/creation itself - that is a
 * separate, explicit step (applyOAuthAccountLink()) the callback ROUTE
 * performs next, per the task's "ACCOUNT-LINKING rule" - keeping identity
 * VERIFICATION and account-linking DECISIONS as two independently reviewable
 * concerns.
 *
 * @param string $provider Provider key from the callback URL.
 * @param string $code     The `code` query parameter from the callback URL.
 * @param string $state    The `state` query parameter from the callback URL.
 *
 * @return array{success: bool, httpCode: int, errorCode: string|null, message: string, data: array}
 *         data on success: provider, subject, email, emailVerified, displayName,
 *         tokensEncrypted, nextUrl, linkUserId.
 */
function completeAuth(string $provider, string $code, string $state): array
{
    if (!in_array($provider, CUERCODE_OAUTH_PROVIDERS, true)) {
        return [
            'success'   => false,
            'httpCode'  => 404,
            'errorCode' => 'SSO_PROVIDER_UNKNOWN',
            'message'   => 'Unknown SSO provider.',
            'data'      => [],
        ];
    }

    // 🔒 Verify + single-use-consume the session-bound state FIRST, before
    // any network call - a forged/replayed/expired callback is rejected as
    // cheaply as possible.
    $pending = oauthConsumePendingState($provider, $state);

    if ($pending === null) {
        return [
            'success'   => false,
            'httpCode'  => 400,
            'errorCode' => 'SSO_STATE_INVALID',
            'message'   => 'This sign-in attempt could not be verified (it may have expired, or the link was already used). Please try again.',
            'data'      => [],
        ];
    }

    if (!ssoProviderIsUsable($provider)) {
        return [
            'success'   => false,
            'httpCode'  => 503,
            'errorCode' => 'SSO_PROVIDER_DISABLED',
            'message'   => ssoProviderDisplayName($provider) . ' sign-in is not currently available.',
            'data'      => [],
        ];
    }

    $config            = ssoProviderConfig($provider);
    $discoveryDocument = oauthFetchDiscoveryDocument($provider, $config['discoveryUrl']);

    if ($discoveryDocument === null) {
        return [
            'success'   => false,
            'httpCode'  => 503,
            'errorCode' => 'SSO_DISCOVERY_UNAVAILABLE',
            'message'   => 'Unable to reach the ' . ssoProviderDisplayName($provider) . ' sign-in service right now. Please try again shortly.',
            'data'      => [],
        ];
    }

    $tokenResponse = oauthExchangeCodeForTokens(
        (string) $discoveryDocument['token_endpoint'],
        $config['clientId'],
        $config['clientSecret'],
        $code,
        (string) $pending['verifier'],
        $config['redirectUri']
    );

    if ($tokenResponse === null || !isset($tokenResponse['id_token'])) {
        return [
            'success'   => false,
            'httpCode'  => 502,
            'errorCode' => 'SSO_TOKEN_EXCHANGE_FAILED',
            'message'   => 'Sign-in with ' . ssoProviderDisplayName($provider) . ' could not be completed. Please try again.',
            'data'      => [],
        ];
    }

    $jwks = oauthFetchJwks($provider, (string) $discoveryDocument['jwks_uri']);

    if ($jwks === null) {
        return [
            'success'   => false,
            'httpCode'  => 502,
            'errorCode' => 'SSO_JWKS_UNAVAILABLE',
            'message'   => 'Sign-in with ' . ssoProviderDisplayName($provider) . ' could not be verified. Please try again.',
            'data'      => [],
        ];
    }

    $validation = oauthValidateAndDecodeIdToken(
        (string) $tokenResponse['id_token'],
        $jwks,
        (string) $discoveryDocument['issuer'],
        $config['clientId'],
        (string) $pending['nonce']
    );

    if (!$validation['valid']) {
        if (function_exists('logError')) {
            logError('warning', 'OAuth id_token validation failed', 'Provider: ' . $provider . ' | ' . $validation['errorCode']);
        }
        return [
            'success'   => false,
            'httpCode'  => 401,
            'errorCode' => $validation['errorCode'],
            'message'   => 'Sign-in with ' . ssoProviderDisplayName($provider) . ' could not be verified: ' . $validation['message'],
            'data'      => [],
        ];
    }

    $claims  = $validation['claims'];
    $subject = (string) ($claims['sub'] ?? '');

    $email = null;
    if (isset($claims['email']) && is_string($claims['email']) && $claims['email'] !== '') {
        $email = mb_strtolower(trim($claims['email']));
    }

    $emailVerifiedClaim = $claims['email_verified'] ?? false;

    // 📌 Providers encode this as a real JSON boolean (Google) or - in some
    // Entra ID token variants - as the STRING "true"/"false"; normalise both.
    if (is_string($emailVerifiedClaim)) {
        $emailVerified = filter_var($emailVerifiedClaim, FILTER_VALIDATE_BOOLEAN);
    } else {
        $emailVerified = (bool) $emailVerifiedClaim;
    }

    $displayName = null;
    if (isset($claims['name']) && is_string($claims['name']) && trim($claims['name']) !== '') {
        $displayName = mb_substr(trim($claims['name']), 0, 100);
    }

    // 🤝 Optional corroboration via the userinfo endpoint (defence-in-depth
    // ONLY - a transport failure here does not block sign-in since the
    // id_token is already cryptographically verified above). A `sub`
    // MISMATCH, however, is treated as a hard failure.
    if (isset($discoveryDocument['userinfo_endpoint']) && isset($tokenResponse['access_token'])) {
        $userInfo = oauthFetchUserInfo((string) $discoveryDocument['userinfo_endpoint'], (string) $tokenResponse['access_token']);

        if ($userInfo !== null && isset($userInfo['sub']) && (string) $userInfo['sub'] !== $subject) {
            if (function_exists('logError')) {
                logError('error', 'OAuth userinfo subject mismatch', 'Provider: ' . $provider);
            }
            return [
                'success'   => false,
                'httpCode'  => 401,
                'errorCode' => 'SSO_USERINFO_MISMATCH',
                'message'   => 'Sign-in could not be verified.',
                'data'      => [],
            ];
        }
    }

    // 🔐 Build the AES-256-GCM-encrypted-at-rest token bundle for
    // tblUserOAuth.tokensEncrypted - never stored/logged in plaintext.
    $tokenBundle = [
        'access_token'  => $tokenResponse['access_token'] ?? null,
        'refresh_token' => $tokenResponse['refresh_token'] ?? null,
        'id_token'      => $tokenResponse['id_token'],
        'expires_at'    => isset($tokenResponse['expires_in']) ? (time() + (int) $tokenResponse['expires_in']) : null,
        'scope'         => $tokenResponse['scope'] ?? $config['scopes'],
    ];

    $tokensEncrypted = null;

    if (function_exists('encryptValue')) {
        try {
            $tokensEncrypted = encryptValue(json_encode($tokenBundle, JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            if (function_exists('logError')) {
                logError('error', 'OAuth token bundle encryption failed', $e->getMessage());
            }
        }
    }

    return [
        'success'   => true,
        'httpCode'  => 200,
        'errorCode' => null,
        'message'   => 'Sign-in verified.',
        'data'      => [
            'provider'        => $provider,
            'subject'         => $subject,
            'email'           => $email,
            'emailVerified'   => $emailVerified,
            'displayName'     => $displayName,
            'tokensEncrypted' => $tokensEncrypted,
            'nextUrl'         => (string) $pending['next'],
            'linkUserId'      => $pending['link_user_id'],
        ],
    ];
}

// ==============================================================================
// 🧮 decideOAuthAccountLinkAction() - The account-linking decision table (PURE)
// ==============================================================================

/**
 * 🧮 THE core account-linking security decision, as a PURE function (no I/O,
 * no database access) - fully unit-testable, mirroring fn_auth.php's
 * computeLockoutExpiry()/recordFailedLogin() split between pure decision and
 * DB-touching wrapper (applyOAuthAccountLink() below is that wrapper here).
 *
 * DECISION TABLE (ProjectBrief §5.3: every SSO identity MUST link to a local
 * account; multiple providers per user is supported):
 *
 *   1. Caller is ALREADY authenticated ($requestingUserID !== null) - this is
 *      an explicit "link an additional provider" action from account
 *      settings, NOT a sign-in attempt:
 *        a. Identity already linked to THIS SAME user  -> no-op success.
 *        b. Identity already linked to a DIFFERENT user -> REFUSE
 *           (409 SSO_ALREADY_LINKED_ELSEWHERE) - never re-point an existing
 *           link, that would let account A silently steal an identity
 *           vouching for account B.
 *        c. Identity not linked anywhere yet -> LINK to the current user.
 *           Email verification is irrelevant on this branch: the caller
 *           already proved control of the LOCAL account via their own
 *           session - this is not the anti-takeover vector case 2c defends
 *           against.
 *
 *   2. Caller is NOT authenticated (normal "Sign in with ..." flow):
 *        a. Identity already linked (from a PRIOR sign-in/link) -> LOG IN as
 *           that existing linked user.
 *        b. Identity never seen before, AND the IdP's `email_verified` claim
 *           is false -> REFUSE (403 SSO_EMAIL_NOT_VERIFIED). An unverified
 *           IdP email must NEVER create or link an account - the whole
 *           point of requiring `email_verified=true` is that the IdP has
 *           already confirmed control of that mailbox; without it we would
 *           be trusting an assertion the IdP itself does not stand behind.
 *        c. Identity never seen before, email VERIFIED, but a LOCAL account
 *           with that same email ALREADY exists -> REFUSE auto-link (409
 *           SSO_EMAIL_COLLISION), instructing the user to sign in to the
 *           existing account first and link the provider from settings
 *           (falls into case 1c on that subsequent attempt). THIS IS THE
 *           ACCOUNT-TAKEOVER DEFENCE: auto-linking here would let anyone who
 *           can obtain a verified-email SSO login for a given address from
 *           ANY supported provider silently take over a pre-existing local
 *           account that happens to share that email - requiring an
 *           authenticated linking step instead proves ownership of the
 *           LOCAL account independently of what any IdP asserts.
 *        d. Identity never seen before, email VERIFIED, and NO local account
 *           claims that email -> CREATE a new local account (pre-verified
 *           via the IdP) and link it.
 *
 * @param bool     $identityAlreadyLinked      A tblUserOAuth row exists for (provider, subject).
 * @param int|null $identityLinkedToUserID     That row's userID, if $identityAlreadyLinked.
 * @param bool     $emailVerified              The IdP-asserted `email_verified` claim.
 * @param bool     $localAccountExistsForEmail A tblUsers row exists with this normalised email.
 * @param int|null $localAccountUserIDForEmail That row's userID, if $localAccountExistsForEmail.
 * @param int|null $requestingUserID           currentUser() at beginAuth() time - non-null means "link flow".
 *
 * @return array{action: string, userID: int|null, httpCode: int, errorCode: string|null, message: string}
 */
function decideOAuthAccountLinkAction(
    bool $identityAlreadyLinked,
    ?int $identityLinkedToUserID,
    bool $emailVerified,
    bool $localAccountExistsForEmail,
    ?int $localAccountUserIDForEmail,
    ?int $requestingUserID
): array {
    // ------------------------------------------------------------------
    // CASE 1: authenticated "link an additional provider" flow.
    // ------------------------------------------------------------------
    if ($requestingUserID !== null) {
        if ($identityAlreadyLinked) {
            if ($identityLinkedToUserID === $requestingUserID) {
                return [
                    'action'    => 'noop_already_linked_to_self',
                    'userID'    => $requestingUserID,
                    'httpCode'  => 200,
                    'errorCode' => null,
                    'message'   => 'This account is already linked.',
                ];
            }

            return [
                'action'    => 'already_linked_elsewhere',
                'userID'    => null,
                'httpCode'  => 409,
                'errorCode' => 'SSO_ALREADY_LINKED_ELSEWHERE',
                'message'   => 'This provider account is already linked to a different CueRCode account.',
            ];
        }

        return [
            'action'    => 'link_to_current',
            'userID'    => $requestingUserID,
            'httpCode'  => 200,
            'errorCode' => null,
            'message'   => 'Account linked successfully.',
        ];
    }

    // ------------------------------------------------------------------
    // CASE 2: unauthenticated "Sign in with ..." flow.
    // ------------------------------------------------------------------
    if ($identityAlreadyLinked) {
        return [
            'action'    => 'login_existing',
            'userID'    => $identityLinkedToUserID,
            'httpCode'  => 200,
            'errorCode' => null,
            'message'   => 'Signed in successfully.',
        ];
    }

    if (!$emailVerified) {
        return [
            'action'    => 'refuse_unverified',
            'userID'    => null,
            'httpCode'  => 403,
            'errorCode' => 'SSO_EMAIL_NOT_VERIFIED',
            'message'   => 'Your identity provider has not verified this email address, so it cannot be used to sign in yet.',
        ];
    }

    if ($localAccountExistsForEmail) {
        return [
            'action'    => 'require_manual_link',
            'userID'    => $localAccountUserIDForEmail,
            'httpCode'  => 409,
            'errorCode' => 'SSO_EMAIL_COLLISION',
            'message'   => 'An account with this email address already exists. Please sign in to that account and link this provider from your account settings.',
        ];
    }

    return [
        'action'    => 'create_new',
        'userID'    => null,
        'httpCode'  => 201,
        'errorCode' => null,
        'message'   => 'Account created and signed in successfully.',
    ];
}

// ==============================================================================
// 🗄️ applyOAuthAccountLink() - DB-touching wrapper around the decision table
// ==============================================================================

/**
 * 🗄️ Look up the current DB state (existing link? existing local account for
 * this email?), run it through decideOAuthAccountLinkAction(), and apply the
 * resulting DB side effects (create tblUsers row / insert or refresh
 * tblUserOAuth row / refuse). Called by the callback route immediately after
 * completeAuth() succeeds.
 *
 * @param string      $provider         Verified provider key (from completeAuth()'s data).
 * @param string      $subject          Verified subject (`sub`) (from completeAuth()'s data).
 * @param string|null $email            Verified email, if any (from completeAuth()'s data).
 * @param bool        $emailVerified    The IdP's `email_verified` assertion (from completeAuth()'s data).
 * @param string|null $displayName      IdP-asserted display name, if any (only used when creating a new account).
 * @param string|null $tokensEncrypted  The AES-256-GCM-encrypted token bundle (from completeAuth()'s data).
 * @param int|null    $requestingUserID The `linkUserId` captured at beginAuth() time (from completeAuth()'s data).
 *
 * @return array{success: bool, httpCode: int, errorCode: string|null, message: string, data: array}
 *         data['userID'] holds the account to issue a session for on success.
 */
function applyOAuthAccountLink(
    string $provider,
    string $subject,
    ?string $email,
    bool $emailVerified,
    ?string $displayName,
    ?string $tokensEncrypted,
    ?int $requestingUserID
): array {
    if (!function_exists('dbQuery')) {
        return [
            'success'   => false,
            'httpCode'  => 503,
            'errorCode' => 'SERVICE_UNAVAILABLE',
            'message'   => 'Sign-in is temporarily unavailable. Please try again later.',
            'data'      => [],
        ];
    }

    // 🔍 Step 1: does a tblUserOAuth row already exist for (provider, subject)?
    $identityAlreadyLinked  = false;
    $identityLinkedToUserID = null;
    $existingOAuthID        = null;

    try {
        $lookupStmt = dbQuery(
            'SELECT `oauthID`, `userID` FROM `tblUserOAuth` WHERE `provider` = ? AND `providerSubjectID` = ? LIMIT 1',
            'ss',
            [$provider, $subject]
        );
        $lookupRow = $lookupStmt->get_result()->fetch_assoc();
        $lookupStmt->close();

        if ($lookupRow !== null) {
            $identityAlreadyLinked  = true;
            $identityLinkedToUserID = (int) $lookupRow['userID'];
            $existingOAuthID        = (int) $lookupRow['oauthID'];
        }
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'OAuth identity lookup failed', $e->getMessage());
        }
        return [
            'success'   => false,
            'httpCode'  => 500,
            'errorCode' => 'SSO_LOOKUP_FAILED',
            'message'   => 'Sign-in could not be completed. Please try again.',
            'data'      => [],
        ];
    }

    // 🔍 Step 2: does a LOCAL account already exist for this email? (only
    // matters for the unauthenticated create/collision branches below.)
    $localAccountExistsForEmail = false;
    $localAccountUserIDForEmail = null;

    if ($email !== null && $email !== '') {
        try {
            $emailStmt = dbQuery('SELECT `userID` FROM `tblUsers` WHERE `email` = ? LIMIT 1', 's', [$email]);
            $emailRow  = $emailStmt->get_result()->fetch_assoc();
            $emailStmt->close();

            if ($emailRow !== null) {
                $localAccountExistsForEmail = true;
                $localAccountUserIDForEmail = (int) $emailRow['userID'];
            }
        } catch (\Throwable $e) {
            if (function_exists('logError')) {
                logError('error', 'OAuth email lookup failed', $e->getMessage());
            }
            return [
                'success'   => false,
                'httpCode'  => 500,
                'errorCode' => 'SSO_LOOKUP_FAILED',
                'message'   => 'Sign-in could not be completed. Please try again.',
                'data'      => [],
            ];
        }
    }

    // 🧮 Step 3: the PURE decision (see decideOAuthAccountLinkAction()'s
    // docblock for the full table + rationale).
    $decision = decideOAuthAccountLinkAction(
        $identityAlreadyLinked,
        $identityLinkedToUserID,
        $emailVerified,
        $localAccountExistsForEmail,
        $localAccountUserIDForEmail,
        $requestingUserID
    );

    // 🚧 Step 4: apply the DB side effects for the chosen action.
    switch ($decision['action']) {
        case 'noop_already_linked_to_self':
        case 'login_existing':
            // ♻️ Refresh the stored token bundle on every successful
            // (re)link/login - rotates any refresh token the IdP issued.
            if ($existingOAuthID !== null && $tokensEncrypted !== null) {
                try {
                    $refreshStmt = dbQuery(
                        'UPDATE `tblUserOAuth` SET `tokensEncrypted` = ? WHERE `oauthID` = ?',
                        'si',
                        [$tokensEncrypted, $existingOAuthID]
                    );
                    $refreshStmt->close();
                } catch (\Throwable $e) {
                    if (function_exists('logError')) {
                        logError('warning', 'OAuth token refresh failed', $e->getMessage());
                    }
                }
            }

            return [
                'success'   => true,
                'httpCode'  => $decision['httpCode'],
                'errorCode' => null,
                'message'   => $decision['message'],
                'data'      => ['userID' => $decision['userID'], 'action' => $decision['action']],
            ];

        case 'link_to_current':
            try {
                $linkStmt = dbQuery(
                    'INSERT INTO `tblUserOAuth` (`userID`, `provider`, `providerSubjectID`, `tokensEncrypted`) VALUES (?, ?, ?, ?)',
                    'isss',
                    [$requestingUserID, $provider, $subject, $tokensEncrypted]
                );
                $linkStmt->close();
            } catch (\Throwable $e) {
                if (function_exists('logError')) {
                    logError('error', 'OAuth link-to-current insert failed', $e->getMessage());
                }
                return [
                    'success'   => false,
                    'httpCode'  => 500,
                    'errorCode' => 'SSO_LINK_FAILED',
                    'message'   => 'This provider could not be linked. Please try again.',
                    'data'      => [],
                ];
            }

            if (function_exists('logActivity')) {
                logActivity('sso_linked', 'Linked ' . $provider . ' to account (from account settings)', 'user', $requestingUserID, ['provider' => $provider]);
            }

            return [
                'success'   => true,
                'httpCode'  => $decision['httpCode'],
                'errorCode' => null,
                'message'   => $decision['message'],
                'data'      => ['userID' => $decision['userID'], 'action' => $decision['action']],
            ];

        case 'already_linked_elsewhere':
        case 'refuse_unverified':
        case 'require_manual_link':
            if (function_exists('logActivity')) {
                logActivity(
                    'sso_link_refused',
                    'Refused ' . $provider . ' sign-in/link (' . $decision['action'] . ')',
                    'user',
                    $decision['userID'],
                    ['provider' => $provider]
                );
            }

            return [
                'success'   => false,
                'httpCode'  => $decision['httpCode'],
                'errorCode' => $decision['errorCode'],
                'message'   => $decision['message'],
                'data'      => [],
            ];

        case 'create_new':
            // 📝 A NEW local account, pre-verified via the IdP:
            // status='active' + emailVerifiedAt=NOW() immediately (never
            // 'pending' - the IdP already proved control of the mailbox),
            // passwordHash NULL (an SSO-only account until the owner sets
            // one - see fn_auth.php's tblUsers.passwordHash column comment).
            try {
                $createUserStmt = dbQuery(
                    'INSERT INTO `tblUsers` (`email`, `passwordHash`, `displayName`, `status`, `emailVerifiedAt`) '
                    . "VALUES (?, NULL, ?, 'active', NOW())",
                    'ss',
                    [$email, $displayName]
                );
                $newUserID = (int) $createUserStmt->insert_id;
                $createUserStmt->close();
            } catch (\Throwable $e) {
                // 📌 Duplicate-key race: another request created the SAME
                // email between Step 2's lookup and this insert. Fail
                // closed as a collision rather than risk an orphaned link.
                if (function_exists('logError')) {
                    logError('warning', 'OAuth new-account insert conflict', 'Provider: ' . $provider);
                }
                return [
                    'success'   => false,
                    'httpCode'  => 409,
                    'errorCode' => 'SSO_EMAIL_COLLISION',
                    'message'   => 'An account with this email address already exists. Please sign in to that account and link this provider from your account settings.',
                    'data'      => [],
                ];
            }

            try {
                $linkNewStmt = dbQuery(
                    'INSERT INTO `tblUserOAuth` (`userID`, `provider`, `providerSubjectID`, `tokensEncrypted`) VALUES (?, ?, ?, ?)',
                    'isss',
                    [$newUserID, $provider, $subject, $tokensEncrypted]
                );
                $linkNewStmt->close();
            } catch (\Throwable $e) {
                if (function_exists('logError')) {
                    logError('error', 'OAuth new-account link insert failed', $e->getMessage());
                }
                return [
                    'success'   => false,
                    'httpCode'  => 500,
                    'errorCode' => 'SSO_LINK_FAILED',
                    'message'   => 'Your account was created but could not be linked. Please contact support.',
                    'data'      => [],
                ];
            }

            if (function_exists('logActivity')) {
                logActivity('sso_registered', 'New account created via ' . $provider . ' SSO', 'user', $newUserID, ['provider' => $provider]);
            }

            return [
                'success'   => true,
                'httpCode'  => $decision['httpCode'],
                'errorCode' => null,
                'message'   => $decision['message'],
                'data'      => ['userID' => $newUserID, 'action' => 'create_new'],
            ];

        default:
            return [
                'success'   => false,
                'httpCode'  => 500,
                'errorCode' => 'SSO_UNKNOWN_DECISION',
                'message'   => 'Sign-in could not be completed.',
                'data'      => [],
            ];
    }
}

// ==============================================================================
// 📋 Linked-provider list/unlink (account settings UI)
// ==============================================================================

/**
 * 📋 List all providers linked to a given user's account, for the account
 * settings "Linked accounts" section.
 *
 * @param int $userID The account to list linked providers for.
 *
 * @return array<int, array{id: int, provider: string, display_name: string, masked_subject: string, linked_at: string}>
 */
function listLinkedOAuthProvidersForUser(int $userID): array
{
    if (!function_exists('dbQuery')) {
        return [];
    }

    try {
        $stmt = dbQuery(
            'SELECT `oauthID`, `provider`, `providerSubjectID`, `linkedAt` FROM `tblUserOAuth` WHERE `userID` = ? ORDER BY `linkedAt` ASC',
            'i',
            [$userID]
        );
        $result = $stmt->get_result();
        $rows   = [];

        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'List linked OAuth providers failed', 'userID: ' . $userID . ' | ' . $e->getMessage());
        }
        return [];
    }

    $linkedProviders = [];

    foreach ($rows as $row) {
        $subject = (string) $row['providerSubjectID'];

        if (mb_strlen($subject) > 8) {
            $maskedSubject = mb_substr($subject, 0, 6) . '…';
        } else {
            $maskedSubject = $subject;
        }

        $linkedProviders[] = [
            'id'             => (int) $row['oauthID'],
            'provider'       => (string) $row['provider'],
            'display_name'   => ssoProviderDisplayName((string) $row['provider']),
            'masked_subject' => $maskedSubject,
            'linked_at'      => (string) $row['linkedAt'],
        ];
    }

    return $linkedProviders;
}

/**
 * 📋 Whether a user's account has a local password set.
 *
 * @param int $userID The account to check.
 *
 * @return bool True if a password is set. Fails SAFE (returns true, i.e.
 *              "assume a password exists") on a database error, so an
 *              unlink request is blocked rather than incorrectly permitted.
 */
function userHasPasswordSet(int $userID): bool
{
    if (!function_exists('dbQuery')) {
        return true;
    }

    try {
        $stmt = dbQuery('SELECT `passwordHash` FROM `tblUsers` WHERE `userID` = ? LIMIT 1', 'i', [$userID]);
        $row  = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        return true;
    }

    return ($row !== null && $row['passwordHash'] !== null && (string) $row['passwordHash'] !== '');
}

/**
 * 📋 Count how many SSO providers are currently linked to a user's account.
 *
 * @param int $userID The account to count for.
 *
 * @return int
 */
function countLinkedOAuthProvidersForUser(int $userID): int
{
    if (!function_exists('dbQuery')) {
        return 0;
    }

    try {
        $stmt = dbQuery('SELECT COUNT(*) AS `total` FROM `tblUserOAuth` WHERE `userID` = ?', 'i', [$userID]);
        $row  = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        return 0;
    }

    return $row !== null ? (int) $row['total'] : 0;
}

/**
 * 📋 Unlink ONE provider from the caller's OWN account.
 *
 * IDOR-safe: the DELETE's WHERE clause checks `userID = ?` in the SAME
 * statement (mirrors fn_api_account.php's revokeUserSession() convention) -
 * an oauthID that exists but belongs to a DIFFERENT user affects zero rows.
 *
 * NEVER removes the account's LAST sign-in credential: if the account has
 * no password set AND this is its only linked provider, the request is
 * refused - the owner must set a password (or link another provider) first,
 * or they would be permanently locked out of their own account.
 *
 * @param int $userID  The caller's own tblUsers.userID.
 * @param int $oauthID The tblUserOAuth.oauthID to unlink.
 *
 * @return array{success: bool, message: string}
 */
function unlinkOAuthProviderForUser(int $userID, int $oauthID): array
{
    if (!function_exists('dbQuery')) {
        return ['success' => false, 'message' => 'Unlinking is temporarily unavailable. Please try again later.'];
    }

    if (!userHasPasswordSet($userID) && countLinkedOAuthProvidersForUser($userID) <= 1) {
        return [
            'success' => false,
            'message' => 'You cannot remove your only sign-in method. Please set a password (or link another provider) first.',
        ];
    }

    try {
        $deleteStmt = dbQuery(
            'DELETE FROM `tblUserOAuth` WHERE `oauthID` = ? AND `userID` = ?',
            'ii',
            [$oauthID, $userID]
        );
        $deleted = ($deleteStmt->affected_rows === 1);
        $deleteStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'OAuth unlink failed', 'userID: ' . $userID . ' | ' . $e->getMessage());
        }
        return ['success' => false, 'message' => 'That linked account could not be found.'];
    }

    if (!$deleted) {
        return ['success' => false, 'message' => 'That linked account could not be found.'];
    }

    if (function_exists('logActivity')) {
        logActivity('sso_unlinked', 'Unlinked an SSO provider', 'user', $userID, ['oauthID' => $oauthID]);
    }

    return ['success' => true, 'message' => 'Provider unlinked.'];
}
