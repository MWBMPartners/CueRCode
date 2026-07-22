<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - OpenAPI Render Helpers Tests (Developer Portal, E6 / #50)
 * ==============================================================================
 *
 * Path: tests/test_openapi_render.php
 *
 * Exercises `_functions/fn_openapi_render.php` - the pure, DB-independent
 * helpers behind the developer portal's live "Endpoint Reference" page
 * (`public_html/developers/reference/index.php`). These functions are the
 * ANTI-DRIFT mechanism for that page: they load and parse the REAL
 * `api/v1/openapi.json` at request time rather than duplicating its content
 * by hand, so these tests both (a) prove the helpers themselves are correct,
 * and (b) prove the actual shipped openapi.json still has the shape the
 * reference page depends on (27 paths - the original 7 QR-generation paths,
 * the 6 Epic E3 stage 1 account/auth paths, the 3 Epic E3 stage 2a
 * saved-codes paths added 2026-07-10, the 4 Epic E3 stage 2b own-account
 * dashboard paths (profile/sessions/change-password) added 2026-07-10, the
 * 3 Epic E7 stage 1 self-service API-key paths (keys, keys/{id}/rotate,
 * keys/{id}) added 2026-07-10, the 2 Epic E2 SSO browser-redirect paths
 * (auth/{provider}, auth/callback/{provider}) added 2026-07-10, and the 2
 * GDPR/CCPA data-subject-rights paths (account/export, account/erase, #37/
 * epic #52) added 2026-07-10 - the Customization schema's accessibility
 * params, the shared error responses).
 *
 * ⚠️ Lives under repo-root tests/ - never deployed.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (E6 developer portal cycle, #50)
 * @see         tests/bootstrap.php  - requireFn() / CUERCODE_SITE_ROOT
 * @see         tests/lib/assert.php - the assert* functions used below
 * @see         web/CueRCode.net/_functions/fn_openapi_render.php
 */

// ==============================================================================
// 🧰 Load the production render helpers (NO database connection - pure functions)
// ==============================================================================
requireFn('fn_openapi_render.php');

// ==============================================================================
// 📂 loadOpenApiSpecFromFile() - against the REAL shipped openapi.json
// ==============================================================================
// @see web/CueRCode.net/public_html/api/v1/openapi.json
// ==============================================================================

$realSpecPath = CUERCODE_SITE_ROOT . 'public_html' . DIRECTORY_SEPARATOR
    . 'api' . DIRECTORY_SEPARATOR . 'v1' . DIRECTORY_SEPARATOR . 'openapi.json';

$realSpec = loadOpenApiSpecFromFile($realSpecPath);

assertTrue(
    is_array($realSpec),
    'loadOpenApiSpecFromFile() successfully loads and decodes the real openapi.json'
);

assertTrue(
    isset($realSpec['paths']) && is_array($realSpec['paths']),
    'the real openapi.json has a paths object'
);

// 📌 The developer portal's reference page assumes exactly these 27 paths
// exist (7 original QR-generation paths + 6 Epic E3 stage 1 account/auth
// paths + 3 Epic E3 stage 2a saved-codes paths + 4 Epic E3 stage 2b
// own-account dashboard paths + 3 Epic E7 stage 1 self-service API-key
// paths + 2 Epic E2 SSO browser-redirect paths + 2 GDPR/CCPA data-subject-
// rights paths (#37/epic #52), all added 2026-07-10). If this ever fails, it
// means the API surface changed - update this list, not the reference page
// (which follows automatically).
$expectedPaths = [
    '/generate',
    '/preview',
    '/bulk-generate',
    '/download/{uuid}',
    '/types',
    '/formats',
    '/health',
    '/auth/register',
    '/auth/login',
    '/auth/logout',
    '/auth/verify-email',
    '/auth/request-password-reset',
    '/auth/reset-password',
    '/save',
    '/qrcodes',
    '/qrcodes/{uuid}',
    '/account/profile',
    '/account/sessions',
    '/account/sessions/{id}',
    '/account/change-password',
    '/account/export',
    '/account/erase',
    '/keys',
    '/keys/{id}/rotate',
    '/keys/{id}',
    '/auth/{provider}',
    '/auth/callback/{provider}',
];

foreach ($expectedPaths as $expectedPath) {
    assertTrue(
        isset($realSpec['paths'][$expectedPath]),
        'real openapi.json declares the expected path: ' . $expectedPath
    );
}

assertEquals(
    count($expectedPaths),
    count($realSpec['paths']),
    'real openapi.json has exactly the 27 expected paths (no silent additions/removals since this test was written)'
);

// 📌 loadOpenApiSpecFromFile() fails closed (returns null) for a missing file -
// the reference page must show a friendly notice, not fatal.
assertEquals(
    null,
    loadOpenApiSpecFromFile($realSpecPath . '.does-not-exist'),
    'loadOpenApiSpecFromFile() returns null for a missing file (fails closed)'
);

// ==============================================================================
// 🔗 oapiResolveRef() - local JSON Pointer resolution
// ==============================================================================

$stubSpec = [
    'components' => [
        'schemas' => [
            'Widget' => [
                'type'       => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                ],
            ],
        ],
    ],
];

$resolvedWidget = oapiResolveRef('#/components/schemas/Widget', $stubSpec);

assertTrue(
    is_array($resolvedWidget) && isset($resolvedWidget['properties']['name']),
    'oapiResolveRef() resolves a local JSON Pointer to the correct nested node'
);

assertEquals(
    null,
    oapiResolveRef('#/components/schemas/DoesNotExist', $stubSpec),
    'oapiResolveRef() returns null for a pointer that does not resolve'
);

assertEquals(
    null,
    oapiResolveRef('https://example.com/external.json#/foo', $stubSpec),
    'oapiResolveRef() refuses to follow a non-local ($ref to an external document) pointer'
);

// 📌 Against the REAL spec: the Customization schema must resolve and must
// contain the accessibility params this whole epic is built to surface
// (preset / scan_distance_cm / pdf_layout - E5, #48/#49).
$customizationSchema = oapiResolveRef('#/components/schemas/Customization', $realSpec);

assertTrue(
    is_array($customizationSchema) && isset($customizationSchema['properties']) && is_array($customizationSchema['properties']),
    'oapiResolveRef() resolves the real Customization schema'
);

foreach (['preset', 'scan_distance_cm', 'pdf_layout', 'format', 'fg_color', 'bg_color'] as $expectedCustomizationField) {
    assertTrue(
        isset($customizationSchema['properties'][$expectedCustomizationField]),
        'real Customization schema declares property: ' . $expectedCustomizationField
    );
}

// ==============================================================================
// 🧩 oapiResolveSchema() - $ref following + allOf flattening
// ==============================================================================

$refOnly = oapiResolveSchema(['$ref' => '#/components/schemas/Widget'], $stubSpec);

assertTrue(
    isset($refOnly['properties']['name']),
    'oapiResolveSchema() follows a bare $ref to its target schema'
);

$allOfSpec = [
    'components' => [
        'schemas' => [
            'Base' => [
                'type'       => 'object',
                'required'   => ['id'],
                'properties' => ['id' => ['type' => 'integer']],
            ],
            'Extra' => [
                'type'       => 'object',
                'properties' => ['label' => ['type' => 'string']],
            ],
        ],
    ],
];

$allOfResolved = oapiResolveSchema(
    ['allOf' => [
        ['$ref' => '#/components/schemas/Base'],
        ['$ref' => '#/components/schemas/Extra'],
    ]],
    $allOfSpec
);

assertTrue(
    isset($allOfResolved['properties']['id']) && isset($allOfResolved['properties']['label']),
    'oapiResolveSchema() merges properties from every allOf member'
);

assertEquals(
    ['id'],
    $allOfResolved['required'],
    'oapiResolveSchema() merges the required list from allOf members'
);

// 📌 Real spec: BulkItem.customization uses "allOf: [{$ref: Customization}]" -
// prove it resolves to the full Customization property set, not an empty shell.
$bulkItemSchema = oapiResolveRef('#/components/schemas/BulkItem', $realSpec);
$bulkItemCustomizationResolved = oapiResolveSchema($bulkItemSchema['properties']['customization'], $realSpec);

assertTrue(
    isset($bulkItemCustomizationResolved['properties']['preset']),
    'oapiResolveSchema() flattens BulkItem.customization\'s allOf down to the full Customization properties'
);

// ==============================================================================
// 🎨 oapiMethodBadgeClass() / oapiStatusBadgeClass()
// ==============================================================================

assertEquals('text-bg-primary', oapiMethodBadgeClass('post'), 'oapiMethodBadgeClass() maps POST (any case)');
assertEquals('text-bg-info', oapiMethodBadgeClass('GET'), 'oapiMethodBadgeClass() maps GET');
assertEquals('text-bg-danger', oapiMethodBadgeClass('DELETE'), 'oapiMethodBadgeClass() maps DELETE');
assertEquals('text-bg-secondary', oapiMethodBadgeClass('TRACE'), 'oapiMethodBadgeClass() falls back to secondary for an unmapped method');

assertEquals('text-bg-success', oapiStatusBadgeClass('200'), 'oapiStatusBadgeClass() maps 2xx to success');
assertEquals('text-bg-warning', oapiStatusBadgeClass('429'), 'oapiStatusBadgeClass() maps 4xx to warning');
assertEquals('text-bg-danger', oapiStatusBadgeClass('500'), 'oapiStatusBadgeClass() maps 5xx to danger');

// ==============================================================================
// 📝 oapiFormatDescription() - escape-first, safe formatting
// ==============================================================================

$maliciousDescription = 'Contains <script>alert(1)</script> and **bold** and `code`.';
$formatted            = oapiFormatDescription($maliciousDescription);

assertFalse(
    str_contains($formatted, '<script>'),
    'oapiFormatDescription() never emits an unescaped <script> tag from spec text'
);
assertContains(
    '&lt;script&gt;',
    $formatted,
    'oapiFormatDescription() HTML-escapes a literal "<script>" sequence in the source text'
);
assertContains(
    '<strong>bold</strong>',
    $formatted,
    'oapiFormatDescription() converts **bold** into a real <strong> tag'
);
assertContains(
    '<code>code</code>',
    $formatted,
    'oapiFormatDescription() converts `code` into a real <code> tag'
);

// 📌 A bold marker straddling an already-escaped angle bracket cannot smuggle
// new markup - the captured group can never contain a literal '<'.
$trickyInput  = '**<b>not really bold html</b>**';
$trickyOutput = oapiFormatDescription($trickyInput);
assertFalse(
    str_contains($trickyOutput, '<b>'),
    'oapiFormatDescription() cannot be tricked into emitting a raw <b> tag from spec text'
);

// ==============================================================================
// 🏷️ oapiPropertyTypeLabel()
// ==============================================================================

assertEquals(
    'integer (100-4000)',
    oapiPropertyTypeLabel(['type' => 'integer', 'minimum' => 100, 'maximum' => 4000]),
    'oapiPropertyTypeLabel() renders a numeric range'
);
assertEquals(
    'string (max 120 chars)',
    oapiPropertyTypeLabel(['type' => 'string', 'maxLength' => 120]),
    'oapiPropertyTypeLabel() renders a maxLength cap'
);
assertEquals(
    'string (uuid)',
    oapiPropertyTypeLabel(['type' => 'string', 'format' => 'uuid']),
    'oapiPropertyTypeLabel() renders a declared format'
);
assertEquals(
    'integer OR string',
    oapiPropertyTypeLabel(['oneOf' => [['type' => 'integer'], ['type' => 'string']]]),
    'oapiPropertyTypeLabel() renders a oneOf as "X OR Y" (Customization.logo shape)'
);
assertEquals(
    'array of object',
    oapiPropertyTypeLabel(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/BulkItem']]),
    'oapiPropertyTypeLabel() renders an array of a $ref schema as "array of object"'
);

// ==============================================================================
// 🔑 oapiRenderAuthSummary()
// ==============================================================================

assertContains(
    'No authentication required',
    oapiRenderAuthSummary([[]]),
    'oapiRenderAuthSummary() recognises a lone empty requirement as fully anonymous'
);
assertContains(
    'Authentication required',
    oapiRenderAuthSummary([['ApiKeyAuth' => []], ['CsrfAuth' => []]]),
    'oapiRenderAuthSummary() reports required auth when no empty requirement is present (real /generate shape)'
);
assertContains(
    'Anonymous requests are allowed',
    oapiRenderAuthSummary([[], ['ApiKeyAuth' => []], ['CsrfAuth' => []]]),
    'oapiRenderAuthSummary() reports the anonymous-plus-optional-auth shape (real /bulk-generate shape)'
);

// ==============================================================================
// ⏱️ oapiOperationHasRateLimitHeaders()
// ==============================================================================

$responsesWithRateLimit = [
    '200' => [
        'headers' => [
            'X-RateLimit-Limit' => ['schema' => ['type' => 'integer']],
        ],
    ],
];
$responsesWithoutRateLimit = [
    '200' => ['description' => 'OK'],
];

assertTrue(
    oapiOperationHasRateLimitHeaders($responsesWithRateLimit, $stubSpec),
    'oapiOperationHasRateLimitHeaders() detects a declared X-RateLimit-* header'
);
assertFalse(
    oapiOperationHasRateLimitHeaders($responsesWithoutRateLimit, $stubSpec),
    'oapiOperationHasRateLimitHeaders() returns false when no response declares one'
);

// 📌 Real spec: /generate's 200 response declares rate-limit headers, and so
// does /health's - health is NOT exempt from rate limiting (the router applies
// the same per-key/per-IP check to every route; a prior revision of this spec
// incorrectly documented health as exempt - see the 2026-07-10 OpenAPI refresh).
$generateResponses = $realSpec['paths']['/generate']['post']['responses'];
$healthResponses    = $realSpec['paths']['/health']['get']['responses'];

assertTrue(
    oapiOperationHasRateLimitHeaders($generateResponses, $realSpec),
    '/generate\'s real 200 response declares X-RateLimit-* headers'
);
assertTrue(
    oapiOperationHasRateLimitHeaders($healthResponses, $realSpec),
    '/health\'s real 200 response DOES declare X-RateLimit-* headers (not exempt from rate limiting)'
);

// ==============================================================================
// 📋 oapiRenderSchemaProperties() - full rendering, against the real spec
// ==============================================================================

$renderedCustomization = oapiRenderSchemaProperties(
    ['$ref' => '#/components/schemas/Customization'],
    $realSpec
);

foreach (['preset', 'scan_distance_cm', 'pdf_layout'] as $accessibilityField) {
    assertContains(
        '<code>' . $accessibilityField . '</code>',
        $renderedCustomization,
        'oapiRenderSchemaProperties() renders the accessibility Customization field: ' . $accessibilityField
    );
}

assertFalse(
    str_contains($renderedCustomization, '<script>'),
    'oapiRenderSchemaProperties() never emits a raw <script> tag'
);

// 📌 A schema with no declared properties (GenerateRequest.input) renders a
// graceful fallback rather than an empty/broken list.
$noPropsResult = oapiRenderSchemaProperties(['type' => 'object', 'additionalProperties' => true], $realSpec);
assertContains(
    'no fixed sub-properties',
    $noPropsResult,
    'oapiRenderSchemaProperties() renders a graceful fallback for a schema with no fixed properties'
);

// ==============================================================================
// 🖨️ oapiRenderExampleValue()
// ==============================================================================

$exampleHtml = oapiRenderExampleValue(['type' => 'url', 'input' => ['url' => 'https://example.com']]);

assertContains('<pre', $exampleHtml, 'oapiRenderExampleValue() wraps structured examples in a <pre> block');
assertContains('&quot;type&quot;', $exampleHtml, 'oapiRenderExampleValue() HTML-escapes the JSON-encoded example');

$xmlExampleHtml = oapiRenderExampleValue('<response><success>true</success></response>');
assertContains(
    '&lt;response&gt;',
    $xmlExampleHtml,
    'oapiRenderExampleValue() HTML-escapes a pre-formatted XML string example (never renders it as live markup)'
);

// ==============================================================================
// 📬 oapiRenderResponses() - against the real spec, including a $ref'd shared response
// ==============================================================================

$renderedGenerateResponses = oapiRenderResponses($generateResponses, $realSpec);

assertContains(
    'text-bg-success',
    $renderedGenerateResponses,
    'oapiRenderResponses() renders the 200 response with a success-styled badge'
);
assertContains(
    'AUTH_REQUIRED',
    $renderedGenerateResponses,
    'oapiRenderResponses() resolves the $ref\'d shared "Unauthorized" response and renders its example error_code'
);

// ==============================================================================
// 🛡️ DRIFT GUARD (2026-07-10 OpenAPI refresh) - router route table ⇄ openapi.json
// ==============================================================================
// The single biggest risk to "the OpenAPI spec fully and accurately describes
// the CURRENT API" is silent drift: a route added/removed/changed in the real
// router (public_html/api/v1/index.php) without a matching openapi.json edit.
// This section parses the REAL router source's `$routes` table (regex, not an
// `include` - the router file has side effects like CORS headers on load) and
// diffs its {pattern, methods} set against the real openapi.json's `paths`.
//
// Two SSO browser-redirect paths (auth/{provider}, auth/callback/{provider})
// are DELIBERATELY documented in openapi.json for completeness even though
// they are NOT registered in the api/v1 router's $routes table at all - they
// live at public_html/auth/index.php + public_html/auth/callback/index.php,
// outside the versioned JSON API (see each path's own `servers` override and
// the "SSO" tag description). They are the only permitted spec-has-no-route
// exceptions; anything else on either side of the diff is a real drift bug.
// ==============================================================================

$routerSourcePath = CUERCODE_SITE_ROOT . 'public_html' . DIRECTORY_SEPARATOR
    . 'api' . DIRECTORY_SEPARATOR . 'v1' . DIRECTORY_SEPARATOR . 'index.php';

assertTrue(
    is_file($routerSourcePath),
    'the real router source file exists at the expected path: ' . $routerSourcePath
);

$routerSource = (string) file_get_contents($routerSourcePath);

// 📌 Extract every ['pattern' => '...', 'methods' => [...]] entry from the
// $routes table. This is a text-level parse (not include()) so this test file
// never triggers the router's own header()/CORS/exit side effects.
preg_match_all(
    '/\'pattern\'\s*=>\s*\'([^\']+)\',\s*\n\s*\'methods\'\s*=>\s*\[([^\]]+)\]/',
    $routerSource,
    $routeMatches,
    PREG_SET_ORDER
);

assertTrue(
    count($routeMatches) >= 20,
    'router source regex extracted a plausible number of routes (>= 20): found ' . count($routeMatches)
);

$routerRoutes = [];
foreach ($routeMatches as $routeMatch) {
    $routerPath = '/' . $routeMatch[1];
    preg_match_all("/'([A-Za-z]+)'/", $routeMatch[2], $methodMatches);
    $routerRoutes[$routerPath] = array_map('strtoupper', $methodMatches[1]);
}

// 📌 Every router route must have a matching openapi.json path with every one
// of its HTTP methods declared.
$missingFromSpec = [];
foreach ($routerRoutes as $routerPath => $routerMethods) {
    if (!isset($realSpec['paths'][$routerPath])) {
        $missingFromSpec[] = $routerPath;
        continue;
    }
    foreach ($routerMethods as $routerMethod) {
        $lowerMethod = strtolower($routerMethod);
        if (!isset($realSpec['paths'][$routerPath][$lowerMethod])) {
            $missingFromSpec[] = $routerMethod . ' ' . $routerPath;
        }
    }
}

assertEquals(
    [],
    $missingFromSpec,
    'every router route (path + method) has a matching openapi.json path+method - '
    . 'DRIFT GUARD: if this fails, a real route is undocumented (list: ' . implode(', ', $missingFromSpec) . ')'
);

// 📌 The reverse direction: every openapi.json path must correspond to a real
// router route, EXCEPT the two documented-as-out-of-scope SSO browser routes.
$ssoOutOfRouterScopePaths = ['/auth/{provider}', '/auth/callback/{provider}'];

$specPathsNotInRouter = [];
foreach (array_keys($realSpec['paths']) as $specPath) {
    if (in_array($specPath, $ssoOutOfRouterScopePaths, true)) {
        continue;
    }
    if (!isset($routerRoutes[$specPath])) {
        $specPathsNotInRouter[] = $specPath;
    }
}

assertEquals(
    [],
    $specPathsNotInRouter,
    'every non-SSO openapi.json path corresponds to a real router route - '
    . 'DRIFT GUARD: if this fails, the spec documents a path the router no longer serves (list: '
    . implode(', ', $specPathsNotInRouter) . ')'
);

// 📌 Exactly the 2 SSO exemptions, no more - if a THIRD spec path with no
// router route ever appears, the loop above already caught it via a non-empty
// $specPathsNotInRouter; this just double-checks the exemption list itself is
// still both present and NOT silently grown.
foreach ($ssoOutOfRouterScopePaths as $ssoPath) {
    assertTrue(
        isset($realSpec['paths'][$ssoPath]),
        'the expected SSO out-of-router-scope path is still documented: ' . $ssoPath
    );
    assertFalse(
        isset($routerRoutes[$ssoPath]),
        'the SSO path ' . $ssoPath . ' is confirmed NOT a real api/v1 router route (still an intentional exemption)'
    );
}

// ==============================================================================
// 🔑 DRIFT GUARD - the 7-scope API-key allowlist (fn_api_keys.php ⇄ openapi.json)
// ==============================================================================
// fn_api_keys.php's CUERCODE_API_KEY_SCOPE_ALLOWLIST is the single source of
// truth for which scopes an API key can be granted. openapi.json's
// ApiKeyCreateRequest.scopes.items.enum must list EXACTLY the same set, in the
// same order (the info.description prose also names them - checked below too).
// ==============================================================================

requireFn('fn_api_keys.php');

assertTrue(
    defined('CUERCODE_API_KEY_SCOPE_ALLOWLIST'),
    'fn_api_keys.php defines CUERCODE_API_KEY_SCOPE_ALLOWLIST'
);

$codeScopeAllowlist = defined('CUERCODE_API_KEY_SCOPE_ALLOWLIST') ? CUERCODE_API_KEY_SCOPE_ALLOWLIST : [];

$specScopeEnum = $realSpec['components']['schemas']['ApiKeyCreateRequest']['properties']['scopes']['items']['enum'] ?? null;

assertTrue(
    is_array($specScopeEnum),
    'openapi.json ApiKeyCreateRequest.scopes.items.enum is a declared array'
);

assertEquals(
    $codeScopeAllowlist,
    $specScopeEnum,
    'DRIFT GUARD: openapi.json\'s ApiKeyCreateRequest scope enum matches fn_api_keys.php\'s '
    . 'CUERCODE_API_KEY_SCOPE_ALLOWLIST exactly (same values, same order)'
);

assertEquals(
    7,
    count($codeScopeAllowlist),
    'the API-key scope allowlist has exactly 7 entries (generate, preview, download, bulk, '
    . 'qrcodes:read, qrcodes:write, save)'
);

foreach ($codeScopeAllowlist as $expectedScope) {
    assertContains(
        $expectedScope,
        (string) ($realSpec['info']['description'] ?? ''),
        'openapi.json info.description prose names the scope: ' . $expectedScope
    );
}

// ==============================================================================
// 🔒 DRIFT GUARD - securitySchemes are declared and correctly named
// ==============================================================================

assertTrue(
    isset($realSpec['components']['securitySchemes']['ApiKeyAuth']),
    'openapi.json declares the ApiKeyAuth security scheme'
);
assertTrue(
    isset($realSpec['components']['securitySchemes']['CsrfAuth']),
    'openapi.json declares the CsrfAuth security scheme'
);
assertEquals(
    'apiKey',
    $realSpec['components']['securitySchemes']['ApiKeyAuth']['type'] ?? null,
    'ApiKeyAuth is declared as an apiKey-type security scheme'
);
assertEquals(
    'X-API-Key',
    $realSpec['components']['securitySchemes']['ApiKeyAuth']['name'] ?? null,
    'ApiKeyAuth reads the X-API-Key header (matches fn_api_auth.php\'s authenticateAPIRequest())'
);
assertEquals(
    'X-CSRF-Token',
    $realSpec['components']['securitySchemes']['CsrfAuth']['name'] ?? null,
    'CsrfAuth reads the X-CSRF-Token header (matches fn_api_auth.php\'s authenticateAPIRequest())'
);

// ==============================================================================
// 🩺 DRIFT GUARD - every route the router marks 'rateLimit' => true has a
// 429 response declared in openapi.json (spot-checks the two easiest-to-miss
// anonymous/public routes, since the earlier oapiOperationHasRateLimitHeaders()
// assertions already prove /generate and /health both carry X-RateLimit-*
// headers on 200).
// ==============================================================================

foreach (['/types', '/formats', '/health', '/bulk-generate'] as $rateLimitedPath) {
    $pathMethods = $realSpec['paths'][$rateLimitedPath] ?? [];
    $hasA429 = false;
    foreach ($pathMethods as $methodKey => $operation) {
        if (!is_array($operation) || !isset($operation['responses']) || !is_array($operation['responses'])) {
            continue;
        }
        if (isset($operation['responses']['429'])) {
            $hasA429 = true;
            break;
        }
    }
    assertTrue(
        $hasA429,
        'DRIFT GUARD: ' . $rateLimitedPath . ' declares a 429 response (every router route is rate-limited)'
    );
}

// ==============================================================================
// 🧯 DRIFT GUARD - the shared ServiceUnavailable (503) response component
// exists and is actually referenced by at least the routes known to emit a
// real SERVICE_UNAVAILABLE 503 from their handlers (fn_api_generate.php,
// fn_api_bulk.php, fn_api_qrcodes.php, fn_api_accounts.php/fn_auth.php,
// fn_api_account.php, fn_api_keys.php all call errorResponse(..., 503,
// 'SERVICE_UNAVAILABLE') from a pre-flight dbQuery()-unavailable guard).
// ==============================================================================

assertTrue(
    isset($realSpec['components']['responses']['ServiceUnavailable']),
    'openapi.json declares the shared components/responses/ServiceUnavailable (503) response'
);

foreach (['/generate', '/preview', '/save', '/keys'] as $mustHave503Path) {
    $pathMethods = $realSpec['paths'][$mustHave503Path] ?? [];
    $hasA503 = false;
    foreach ($pathMethods as $methodKey => $operation) {
        if (!is_array($operation) || !isset($operation['responses']) || !is_array($operation['responses'])) {
            continue;
        }
        if (isset($operation['responses']['503'])) {
            $hasA503 = true;
            break;
        }
    }
    assertTrue(
        $hasA503,
        'DRIFT GUARD: ' . $mustHave503Path . ' declares a 503 response (matches its handler\'s real SERVICE_UNAVAILABLE guard)'
    );
}
