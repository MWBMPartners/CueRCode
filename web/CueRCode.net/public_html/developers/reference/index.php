<?php
/**
 * ==============================================================================
 * 📖 CueRCode - Developer Portal: Live Endpoint Reference
 * ==============================================================================
 *
 * Path: public_html/developers/reference/index.php
 *
 * 🎯 ANTI-DRIFT PAGE: renders EVERY path in `api/v1/openapi.json` - method,
 * summary, description, authentication, parameters, request body schema
 * (including the full Customization schema with the accessibility params
 * `preset`/`scan_distance_cm`/`pdf_layout`), responses, error codes, and
 * per-endpoint rate-limit note - by loading and parsing the spec AT REQUEST
 * TIME. Nothing on this page is a hand-copied duplicate of the spec; if the
 * API surface changes, updating openapi.json is the only change needed and
 * this page follows automatically (DEV_NOTES.md §Architecture rule 7:
 * "openapi.json is the source of truth for the API surface").
 *
 * All rendering logic lives in `_functions/fn_openapi_render.php` (pure,
 * DB-independent helpers, unit-tested in `tests/test_openapi_render.php`) so
 * this file stays a thin view: load the spec, loop over it, call the render
 * helpers, echo their (already HTML-escaped) output.
 *
 * @package     CueRCode
 * @subpackage  Developer Portal
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (E6 developer portal cycle, #50)
 * @see         web/CueRCode.net/_functions/fn_openapi_render.php - the render helpers used below
 * @see         public_html/api/v1/openapi.json - the spec this page renders
 * @see         tests/test_openapi_render.php - unit tests for the render helpers
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================
require_once __DIR__ . '/../../../_includes/bootstrap.php';

// ==============================================================================
// 📦 Load the OpenAPI render helpers
// ==============================================================================
// Not loaded by bootstrap.php since it is developer-portal specific.
// @see _functions/fn_openapi_render.php
// ==============================================================================
$openApiRenderFile = CUERCODE_FUNCTIONS_DIR . 'fn_openapi_render.php';
if (file_exists($openApiRenderFile)) {
    require_once $openApiRenderFile;
} else {
    error_log('CueRCode WARNING: fn_openapi_render.php not found at ' . $openApiRenderFile);
}

// ==============================================================================
// ⚙️ Page Variables
// ==============================================================================
if (function_exists('getConfig')) {
    $result = getConfig('site_name', 'CueRCode');
    if (isset($result)) {
        $siteName = $result;
    } else {
        $siteName = 'CueRCode';
    }
} else {
    $siteName = 'CueRCode';
}

$pageTitle       = 'Endpoint Reference';
$pageDescription = 'Every ' . $siteName . ' API path, method, parameter, request/response schema, and '
    . 'error code - rendered live from openapi.json.';
$bodyClass       = 'page-developers page-developers-reference';
$pageCSS         = [];
$pageJS          = [];

$developersActive = 'reference';

// ==============================================================================
// 📂 Load + decode the OpenAPI specification (THE anti-drift step)
// ==============================================================================
// This file lives at public_html/developers/reference/index.php.
// The spec lives at   public_html/api/v1/openapi.json.
// @see https://www.php.net/manual/en/function.file-get-contents.php
// ==============================================================================
$openApiSpecPath = __DIR__ . '/../../api/v1/openapi.json';
$openApiSpec     = null;

if (function_exists('loadOpenApiSpecFromFile')) {
    $openApiSpec = loadOpenApiSpecFromFile($openApiSpecPath);
}

// ==============================================================================
// 📄 Render Page
// ==============================================================================
require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <section class="py-5">
        <div class="container-fluid">
            <div class="row justify-content-center">
                <div class="col-lg-2">
                    <?php require_once CUERCODE_INCLUDES_DIR . 'developers_nav.php'; ?>
                </div>

                <div class="col-lg-9">
                    <h1 class="h2 fw-bold mb-3">
                        <i class="fa-solid fa-list-check me-2" aria-hidden="true"></i>
                        Endpoint Reference
                    </h1>

                    <?php if ($openApiSpec === null) { ?>
                    <!-- ========================================================
                         ❌ Spec failed to load - fail visibly, not silently.
                         ======================================================== -->
                    <div class="alert alert-danger" role="alert">
                        <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                        The live endpoint reference is temporarily unavailable (could not read or parse
                        <code>/api/v1/openapi.json</code>). Please use the
                        <a href="/api/docs/" class="alert-link">interactive Swagger UI</a> or the
                        <a href="/api/v1/openapi.json" class="alert-link">raw specification file</a> instead.
                    </div>
                    <?php } else {
                        $specInfo    = (isset($openApiSpec['info']) && is_array($openApiSpec['info'])) ? $openApiSpec['info'] : [];
                        $specVersion = (isset($specInfo['version']) && is_string($specInfo['version'])) ? $specInfo['version'] : 'unknown';
                        $specPaths   = (isset($openApiSpec['paths']) && is_array($openApiSpec['paths'])) ? $openApiSpec['paths'] : [];
                        $pathCount   = count($specPaths);
                    ?>

                    <p class="text-body-secondary mb-3">
                        Rendered live from <code>/api/v1/openapi.json</code> (OpenAPI
                        <?php echo htmlspecialchars((string) (isset($openApiSpec['openapi']) ? $openApiSpec['openapi'] : '3.0.3'), ENT_QUOTES, 'UTF-8'); ?>,
                        API version <?php echo htmlspecialchars($specVersion, ENT_QUOTES, 'UTF-8'); ?>,
                        <?php echo (int) $pathCount; ?> path<?php echo ($pathCount === 1) ? '' : 's'; ?>)
                        at the moment you loaded this page - not a hand-maintained copy.
                        <a href="/api/v1/openapi.json">View the raw spec</a> ·
                        <a href="/api/docs/">Open in Swagger UI</a> (try-it-out enabled).
                    </p>

                    <?php
                        // ====================================================
                        // 📑 Build the on-page contents list + collect operations
                        // ====================================================
                        $operations = [];

                        foreach ($specPaths as $pathKey => $pathItem) {
                            if (!is_array($pathItem)) {
                                continue;
                            }

                            // 🔀 Epic E2 (#55/#19/#20/#28): a path CAN carry its own
                            // `servers` override (OpenAPI 3.0 §4.7.9.1) when it lives
                            // OUTSIDE the global `/api/v1` base - the SSO browser-
                            // redirect routes (/auth/{provider}, /auth/callback/
                            // {provider}) do exactly this so they render with their
                            // REAL site-root path instead of a misleading
                            // "/api/v1/auth/..." prefix.
                            if (isset($pathItem['servers'][0]['url']) && is_string($pathItem['servers'][0]['url'])) {
                                $pathBase = $pathItem['servers'][0]['url'];
                            } else {
                                $pathBase = '/api/v1';
                            }

                            foreach ($pathItem as $method => $operation) {
                                $methodLower = strtolower((string) $method);

                                if (!in_array($methodLower, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                                    continue;
                                }

                                if (!is_array($operation)) {
                                    continue;
                                }

                                $anchorId = 'op-' . $methodLower . '-'
                                    . preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $pathKey));
                                $anchorId = trim($anchorId, '-');

                                $operations[] = [
                                    'path'      => (string) $pathKey,
                                    'fullPath'  => $pathBase . (string) $pathKey,
                                    'method'    => $methodLower,
                                    'anchor'    => $anchorId,
                                    'operation' => $operation,
                                ];
                            }
                        }
                    ?>

                    <nav aria-label="Endpoint contents" class="mb-4">
                        <h2 class="h6">Contents</h2>
                        <ul class="mb-0">
                            <?php foreach ($operations as $opEntry) {
                                $summary = '';
                                if (isset($opEntry['operation']['summary']) && is_string($opEntry['operation']['summary'])) {
                                    $summary = $opEntry['operation']['summary'];
                                }
                            ?>
                            <li>
                                <a href="#<?php echo htmlspecialchars($opEntry['anchor'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <span class="badge <?php echo htmlspecialchars(oapiMethodBadgeClass($opEntry['method']), ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars(strtoupper($opEntry['method']), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                    <code><?php echo htmlspecialchars($opEntry['fullPath'], ENT_QUOTES, 'UTF-8'); ?></code>
                                    <?php if ($summary !== '') { ?>
                                        - <?php echo htmlspecialchars($summary, ENT_QUOTES, 'UTF-8'); ?>
                                    <?php } ?>
                                </a>
                            </li>
                            <?php } ?>
                        </ul>
                    </nav>

                    <!-- ============================================================
                         🔁 One card per operation, fully rendered from the spec
                         ============================================================ -->
                    <?php foreach ($operations as $opEntry) {
                        $method    = $opEntry['method'];
                        $path      = $opEntry['path'];
                        $fullPath  = $opEntry['fullPath'];
                        $anchor    = $opEntry['anchor'];
                        $operation = $opEntry['operation'];

                        $summary     = (isset($operation['summary']) && is_string($operation['summary'])) ? $operation['summary'] : '';
                        $description = (isset($operation['description']) && is_string($operation['description'])) ? $operation['description'] : '';
                        $tags        = (isset($operation['tags']) && is_array($operation['tags'])) ? $operation['tags'] : [];
                        $security    = (isset($operation['security']) && is_array($operation['security'])) ? $operation['security'] : [];
                        $parameters  = (isset($operation['parameters']) && is_array($operation['parameters'])) ? $operation['parameters'] : [];
                        $responses   = (isset($operation['responses']) && is_array($operation['responses'])) ? $operation['responses'] : [];
                    ?>
                    <div class="card mb-4" id="<?php echo htmlspecialchars($anchor, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="card-header">
                            <span class="badge <?php echo htmlspecialchars(oapiMethodBadgeClass($method), ENT_QUOTES, 'UTF-8'); ?> me-2">
                                <?php echo htmlspecialchars(strtoupper($method), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <code class="fs-6"><?php echo htmlspecialchars($fullPath, ENT_QUOTES, 'UTF-8'); ?></code>
                            <?php foreach ($tags as $tag) { ?>
                                <span class="badge text-bg-light border ms-1"><?php echo htmlspecialchars((string) $tag, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php } ?>
                            <?php if ($summary !== '') { ?>
                                <div class="small text-body-secondary mt-1"><?php echo htmlspecialchars($summary, ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php } ?>
                        </div>
                        <div class="card-body">

                            <?php if ($description !== '') { ?>
                            <div class="mb-3"><?php echo oapiFormatDescription($description); ?></div>
                            <?php } ?>

                            <h3 class="h6">
                                <i class="fa-solid fa-lock me-1" aria-hidden="true"></i>Authentication
                            </h3>
                            <p><?php echo htmlspecialchars(oapiRenderAuthSummary($security), ENT_QUOTES, 'UTF-8'); ?></p>

                            <h3 class="h6">
                                <i class="fa-solid fa-gauge-high me-1" aria-hidden="true"></i>Rate limiting
                            </h3>
                            <p>
                                <?php if (oapiOperationHasRateLimitHeaders($responses, $openApiSpec)) { ?>
                                    Rate limited - watch the <code>X-RateLimit-Limit</code>/
                                    <code>X-RateLimit-Remaining</code>/<code>X-RateLimit-Reset</code> response
                                    headers below, and <code>Retry-After</code> on a <code>429</code>. See
                                    <a href="/developers/errors/">Errors &amp; Rate Limits</a> for the default
                                    limits by authentication mode.
                                <?php } else { ?>
                                    No <code>X-RateLimit-*</code> response headers are declared for this
                                    endpoint in the spec (exempt in practice).
                                <?php } ?>
                            </p>

                            <?php if (count($parameters) > 0) { ?>
                            <h3 class="h6">
                                <i class="fa-solid fa-sliders me-1" aria-hidden="true"></i>Parameters
                            </h3>
                            <?php echo oapiRenderParameters($parameters); ?>
                            <?php } ?>

                            <?php if (isset($operation['requestBody']) && is_array($operation['requestBody'])) {
                                $requestBody     = $operation['requestBody'];
                                $requestRequired = isset($requestBody['required']) && $requestBody['required'] === true;
                                $jsonContent     = (isset($requestBody['content']['application/json']) && is_array($requestBody['content']['application/json']))
                                    ? $requestBody['content']['application/json']
                                    : [];
                                $bodySchema  = (isset($jsonContent['schema']) && is_array($jsonContent['schema'])) ? $jsonContent['schema'] : [];
                                $bodyExamples = (isset($jsonContent['examples']) && is_array($jsonContent['examples'])) ? $jsonContent['examples'] : [];
                            ?>
                            <h3 class="h6">
                                <i class="fa-solid fa-file-import me-1" aria-hidden="true"></i>Request Body
                                (<code>application/json</code>)
                                <?php if ($requestRequired) { ?>
                                    <span class="badge text-bg-danger">required</span>
                                <?php } ?>
                            </h3>
                            <?php if (count($bodySchema) > 0) { ?>
                                <?php echo oapiRenderSchemaProperties($bodySchema, $openApiSpec); ?>
                            <?php } ?>

                            <?php if (count($bodyExamples) > 0) { ?>
                            <h4 class="h6 mt-3 text-body-secondary">Examples</h4>
                            <div class="accordion" id="examples-<?php echo htmlspecialchars($anchor, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php $exampleIndex = 0; foreach ($bodyExamples as $exampleKey => $exampleDef) {
                                    if (!is_array($exampleDef) || !array_key_exists('value', $exampleDef)) {
                                        continue;
                                    }
                                    $exampleIndex++;
                                    $exampleSummary = (isset($exampleDef['summary']) && is_string($exampleDef['summary'])) ? $exampleDef['summary'] : (string) $exampleKey;
                                    $exampleDomId   = 'ex-' . htmlspecialchars($anchor, ENT_QUOTES, 'UTF-8') . '-' . $exampleIndex;
                                ?>
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button"
                                                data-bs-toggle="collapse" data-bs-target="#<?php echo $exampleDomId; ?>">
                                            <?php echo htmlspecialchars($exampleSummary, ENT_QUOTES, 'UTF-8'); ?>
                                        </button>
                                    </h2>
                                    <div id="<?php echo $exampleDomId; ?>" class="accordion-collapse collapse"
                                         data-bs-parent="#examples-<?php echo htmlspecialchars($anchor, ENT_QUOTES, 'UTF-8'); ?>">
                                        <div class="accordion-body p-2">
                                            <?php echo oapiRenderExampleValue($exampleDef['value']); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php } ?>
                            </div>
                            <?php } ?>
                            <?php } ?>

                            <h3 class="h6 mt-3">
                                <i class="fa-solid fa-reply me-1" aria-hidden="true"></i>Responses
                            </h3>
                            <?php echo oapiRenderResponses($responses, $openApiSpec); ?>

                        </div>
                    </div>
                    <?php } ?>

                    <?php } // end else ($openApiSpec !== null) ?>

                </div>
            </div>
        </div>
    </section>

<?php
// ==============================================================================
// 📄 Load Footer Template
// ==============================================================================
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
