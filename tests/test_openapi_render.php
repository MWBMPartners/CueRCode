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
 * reference page depends on (20 paths - the original 7 QR-generation paths,
 * the 6 Epic E3 stage 1 account/auth paths, the 3 Epic E3 stage 2a
 * saved-codes paths added 2026-07-10, and the 4 Epic E3 stage 2b own-account
 * dashboard paths (profile/sessions/change-password) added 2026-07-10, the
 * Customization schema's
 * accessibility params, the shared error responses).
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

// 📌 The developer portal's reference page assumes exactly these 20 paths
// exist (7 original QR-generation paths + 6 Epic E3 stage 1 account/auth
// paths + 3 Epic E3 stage 2a saved-codes paths + 4 Epic E3 stage 2b
// own-account dashboard paths, both added 2026-07-10). If this ever fails,
// it means the API surface changed - update this list, not the reference
// page (which follows automatically).
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
    'real openapi.json has exactly the 20 expected paths (no silent additions/removals since this test was written)'
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

// 📌 Real spec: /generate's 200 response declares rate-limit headers; /health's
// 200 response does not (documented as "exempt in practice").
$generateResponses = $realSpec['paths']['/generate']['post']['responses'];
$healthResponses    = $realSpec['paths']['/health']['get']['responses'];

assertTrue(
    oapiOperationHasRateLimitHeaders($generateResponses, $realSpec),
    '/generate\'s real 200 response declares X-RateLimit-* headers'
);
assertFalse(
    oapiOperationHasRateLimitHeaders($healthResponses, $realSpec),
    '/health\'s real 200 response does NOT declare X-RateLimit-* headers (exempt in practice)'
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
