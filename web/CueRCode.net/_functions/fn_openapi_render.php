<?php
/**
 * ==============================================================================
 * 📖 CueRCode - OpenAPI Spec Loader & Live Renderer Helpers
 * ==============================================================================
 *
 * Path: _functions/fn_openapi_render.php
 *
 * Pure, dependency-free helper functions that load `api/v1/openapi.json` at
 * request time and render its contents into escaped, human-readable HTML for
 * the developer portal's "Endpoint Reference" page
 * (`public_html/developers/reference/index.php`).
 *
 * 🎯 ANTI-DRIFT REQUIREMENT (DEV_NOTES.md §Architecture, rule 7): openapi.json
 * is the single source of truth for the API surface. These functions are
 * intentionally the ONLY place that reads the spec's structure for the
 * developer portal - the reference page contains NO hand-copied endpoint
 * descriptions, parameter lists, or schemas. If the API changes, updating
 * openapi.json is the ONLY change required for the rendered reference page to
 * follow - there is nothing here to keep manually in sync.
 *
 * All functions in this file are pure (no database access, no superglobals) so
 * they can be exercised directly by the CLI test suite
 * (tests/test_openapi_render.php) without a database connection, exactly like
 * the other fn_*.php modules under this directory.
 *
 * Every render* function below returns a STRING guaranteed to only contain
 * dynamic (spec-sourced) text after it has passed through htmlspecialchars()
 * with ENT_QUOTES | 'UTF-8' - matching the project-wide "escape everything"
 * rule for output derived from any non-trusted-at-compile-time source.
 *
 * @package     CueRCode
 * @subpackage  Developer Portal
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (E6 developer portal cycle, #50)
 * @see         public_html/api/v1/openapi.json - the spec these functions render
 * @see         public_html/developers/reference/index.php - the page that calls these
 * @see         https://spec.openapis.org/oas/v3.0.3 OpenAPI 3.0.3 Specification
 * @see         https://datatracker.ietf.org/doc/html/rfc6901 JSON Pointer (RFC 6901)
 * @see         https://www.php.net/manual/en/function.htmlspecialchars.php
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
// 📂 loadOpenApiSpecFromFile() - Load + decode openapi.json from an absolute path
// ==============================================================================

/**
 * 📂 Load and JSON-decode the OpenAPI specification file
 *
 * Reads the given absolute file path and decodes it as an associative array.
 * Returns null on ANY failure (missing file, unreadable, invalid JSON, or a
 * top-level value that is not a JSON object/array) so callers can render a
 * clear "documentation temporarily unavailable" notice instead of a fatal
 * error - the developer portal must never white-screen because of a spec
 * hiccup.
 *
 * @param string $absolutePath Absolute filesystem path to openapi.json
 *
 * @return array|null The decoded spec, or null on failure
 *
 * @see https://www.php.net/manual/en/function.file-get-contents.php
 * @see https://www.php.net/manual/en/function.json-decode.php
 *
 * @example
 *   $spec = loadOpenApiSpecFromFile(__DIR__ . '/../../public_html/api/v1/openapi.json');
 *   if ($spec === null) { echo 'Reference unavailable.'; }
 */
function loadOpenApiSpecFromFile(string $absolutePath): ?array
{
    if (!is_file($absolutePath) || !is_readable($absolutePath)) {
        return null;
    }

    $raw = file_get_contents($absolutePath);

    if ($raw === false || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    if (!is_array($decoded)) {
        return null;
    }

    return $decoded;
}

// ==============================================================================
// 🔗 oapiResolveRef() - Resolve a local "#/..." JSON Pointer reference
// ==============================================================================

/**
 * 🔗 Resolve a local OpenAPI `$ref` (JSON Pointer) against the full spec
 *
 * Only in-document pointers of the form `#/a/b/c` are supported - this spec
 * never references an external file, and supporting only local pointers keeps
 * this function simple and safe (no filesystem/network access from a "render"
 * helper).
 *
 * @param string $ref  The `$ref` value, e.g. '#/components/schemas/Customization'
 * @param array  $spec The full decoded OpenAPI document
 *
 * @return array|null The resolved node, or null if the pointer does not resolve
 *                     to an array (object) within the document
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6901 JSON Pointer (RFC 6901)
 *
 * @example
 *   $customization = oapiResolveRef('#/components/schemas/Customization', $spec);
 */
function oapiResolveRef(string $ref, array $spec): ?array
{
    // 🚫 Only local, in-document pointers are supported (this spec never uses
    //    external $ref targets).
    if (!str_starts_with($ref, '#/')) {
        return null;
    }

    $pointer  = substr($ref, 2);
    $segments = explode('/', $pointer);

    $node = $spec;

    foreach ($segments as $segment) {
        // 📌 JSON Pointer escaping: ~1 -> '/', ~0 -> '~' (RFC 6901 §3). Not used
        //    anywhere in this spec today, but handled for correctness.
        $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);

        if (is_array($node) && array_key_exists($segment, $node)) {
            $node = $node[$segment];
        } else {
            return null;
        }
    }

    if (is_array($node)) {
        return $node;
    }

    return null;
}

// ==============================================================================
// 🧩 oapiResolveSchema() - Fully resolve a schema's $ref / allOf composition
// ==============================================================================

/**
 * 🧩 Resolve a schema node into a flat, directly-renderable schema array
 *
 * Follows a single `$ref` (recursively, in case a ref points at another ref)
 * and merges `allOf` members' `properties`/`required`/`description` into one
 * flattened schema. `oneOf` is intentionally left untouched (the only `oneOf`
 * usage in this spec, `Customization.logo`, is a leaf scalar alternative that
 * is rendered inline by oapiPropertyTypeLabel() rather than expanded).
 *
 * Recursion is depth-capped (default max 8) purely as a defensive guard against
 * an accidental circular reference being introduced in a future openapi.json
 * edit - this spec has no cycles today.
 *
 * @param array $schemaOrRef The schema node (may contain '$ref' and/or 'allOf')
 * @param array $spec        The full decoded OpenAPI document
 * @param int   $depth       Current recursion depth (internal use)
 *
 * @return array The resolved schema (may be empty if resolution failed/looped)
 *
 * @see oapiResolveRef()
 *
 * @example
 *   $resolved = oapiResolveSchema(['$ref' => '#/components/schemas/Customization'], $spec);
 *   // $resolved['properties']['preset'] now holds the preset property definition.
 */
function oapiResolveSchema(array $schemaOrRef, array $spec, int $depth = 0): array
{
    // 🛑 Defensive recursion cap - not expected to trigger on this spec.
    if ($depth > 8) {
        return [];
    }

    $working = $schemaOrRef;

    // 📌 Step 1: follow a direct $ref (possibly chained).
    if (isset($working['$ref']) && is_string($working['$ref'])) {
        $target = oapiResolveRef($working['$ref'], $spec);

        if ($target === null) {
            return [];
        }

        // 📌 Sibling keys alongside a $ref are rare in this spec (e.g. a
        //    'description' override next to a $ref); the resolved target's
        //    own keys are merged in and take priority for structural fields.
        unset($working['$ref']);
        $merged = array_merge($working, $target);

        return oapiResolveSchema($merged, $spec, $depth + 1);
    }

    // 📌 Step 2: flatten 'allOf' composition (used by BulkItem.customization).
    if (isset($working['allOf']) && is_array($working['allOf'])) {
        $mergedProperties  = [];
        $mergedRequired    = [];
        $mergedDescription = '';

        if (isset($working['description']) && is_string($working['description'])) {
            $mergedDescription = $working['description'];
        }

        foreach ($working['allOf'] as $subSchema) {
            if (!is_array($subSchema)) {
                continue;
            }

            $subResolved = oapiResolveSchema($subSchema, $spec, $depth + 1);

            if (isset($subResolved['properties']) && is_array($subResolved['properties'])) {
                $mergedProperties = array_merge($mergedProperties, $subResolved['properties']);
            }

            if (isset($subResolved['required']) && is_array($subResolved['required'])) {
                $mergedRequired = array_merge($mergedRequired, $subResolved['required']);
            }

            if ($mergedDescription === '' && isset($subResolved['description']) && is_string($subResolved['description'])) {
                $mergedDescription = $subResolved['description'];
            }
        }

        $working['properties'] = $mergedProperties;
        $working['required']   = array_values(array_unique($mergedRequired));

        if ($mergedDescription !== '') {
            $working['description'] = $mergedDescription;
        }

        unset($working['allOf']);
    }

    return $working;
}

// ==============================================================================
// 🎨 oapiMethodBadgeClass() - Bootstrap badge class for an HTTP method
// ==============================================================================

/**
 * 🎨 Map an HTTP method to a Bootstrap contextual badge class
 *
 * @param string $method HTTP method, any case (e.g. 'post', 'GET')
 *
 * @return string A Bootstrap 5 `text-bg-*` class name
 */
function oapiMethodBadgeClass(string $method): string
{
    $normalised = strtoupper(trim($method));

    switch ($normalised) {
        case 'GET':
            return 'text-bg-info';

        case 'POST':
            return 'text-bg-primary';

        case 'PUT':
        case 'PATCH':
            return 'text-bg-warning';

        case 'DELETE':
            return 'text-bg-danger';

        default:
            return 'text-bg-secondary';
    }
}

// ==============================================================================
// 🎨 oapiStatusBadgeClass() - Bootstrap badge class for an HTTP status code
// ==============================================================================

/**
 * 🎨 Map an HTTP response status code to a Bootstrap contextual badge class
 *
 * @param string $status Status code as it appears in the spec (e.g. '200', '429')
 *
 * @return string A Bootstrap 5 `text-bg-*` class name
 */
function oapiStatusBadgeClass(string $status): string
{
    $first = substr(trim($status), 0, 1);

    switch ($first) {
        case '2':
            return 'text-bg-success';

        case '3':
            return 'text-bg-info';

        case '4':
            return 'text-bg-warning';

        case '5':
            return 'text-bg-danger';

        default:
            return 'text-bg-secondary';
    }
}

// ==============================================================================
// 🔤 oapiScalarToString() - Render a scalar spec value for display
// ==============================================================================

/**
 * 🔤 Convert a scalar (or small array) spec value to a plain display string
 *
 * The caller is responsible for htmlspecialchars()-escaping the result before
 * output - this function only handles TYPE conversion (bool -> 'true'/'false',
 * null -> 'null', arrays -> compact JSON), not HTML safety.
 *
 * @param mixed $value Any scalar value pulled from the decoded spec
 *
 * @return string A plain-text representation
 */
function oapiScalarToString($value): string
{
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if ($value === null) {
        return 'null';
    }

    if (is_array($value)) {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            return '';
        }

        return $encoded;
    }

    return (string) $value;
}

// ==============================================================================
// 📝 oapiFormatDescription() - Escape + lightly format a spec description
// ==============================================================================

/**
 * 📝 Escape a spec description string and apply light, SAFE inline formatting
 *
 * ⚠️ SECURITY NOTE: the input is htmlspecialchars()-escaped FIRST. Every
 * transform below then operates on the ALREADY-ESCAPED string and only ever
 * substitutes in fixed, hardcoded tags (`<strong>`, `<code>`, `<br>`, `</p><p>`)
 * around a captured group that is itself already-escaped text - a captured
 * group can never contain a literal '<' or '>' (they were converted to
 * '&lt;'/'&gt;' by the initial htmlspecialchars() call), so no new markup can
 * be injected via this formatting step. This is NOT a Markdown renderer - it
 * only recognises `**bold**` and `` `code` `` plus blank-line paragraph breaks,
 * matching the small vocabulary actually used in openapi.json's prose.
 *
 * @param string $text Raw description text from the spec
 *
 * @return string HTML-safe, paragraph-wrapped markup
 *
 * @see https://www.php.net/manual/en/function.htmlspecialchars.php
 * @see https://www.php.net/manual/en/function.preg-replace.php
 */
function oapiFormatDescription(string $text): string
{
    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    // 📌 Blank-line paragraph breaks first (order matters vs. single \n -> <br>).
    $escaped = str_replace("\n\n", '</p><p>', $escaped);
    $escaped = str_replace("\n", '<br>', $escaped);

    // 📌 **bold** -> <strong>...</strong> (captured text is already escaped).
    $withBold = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);
    if ($withBold !== null) {
        $escaped = $withBold;
    }

    // 📌 `code` -> <code>...</code> (captured text is already escaped).
    $withCode = preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped);
    if ($withCode !== null) {
        $escaped = $withCode;
    }

    return '<p>' . $escaped . '</p>';
}

// ==============================================================================
// 🏷️ oapiPropertyTypeLabel() - Compact type label for a property schema
// ==============================================================================

/**
 * 🏷️ Build a compact, human-readable type label for a property schema
 *
 * Examples of output: 'string', 'integer (100-4000)', 'string (max 120 chars)',
 * 'boolean', 'array of object', 'integer or string' (for a `oneOf` leaf).
 *
 * @param array $propSchema A single property's schema definition
 *
 * @return string Plain-text type label (caller must escape before output)
 */
function oapiPropertyTypeLabel(array $propSchema): string
{
    // 📌 oneOf: list each alternative's bare type (Customization.logo only).
    if (isset($propSchema['oneOf']) && is_array($propSchema['oneOf'])) {
        $parts = [];

        foreach ($propSchema['oneOf'] as $alternative) {
            if (is_array($alternative) && isset($alternative['type'])) {
                $parts[] = (string) $alternative['type'];
            }
        }

        if (count($parts) > 0) {
            return implode(' or ', $parts);
        }

        return 'oneOf';
    }

    if (!isset($propSchema['type'])) {
        if (isset($propSchema['$ref']) || isset($propSchema['allOf'])) {
            return 'object';
        }

        return '';
    }

    $type = (string) $propSchema['type'];

    // 📌 Arrays: show the item type when known.
    if ($type === 'array' && isset($propSchema['items']) && is_array($propSchema['items'])) {
        if (isset($propSchema['items']['type'])) {
            return 'array of ' . (string) $propSchema['items']['type'];
        }

        if (isset($propSchema['items']['$ref'])) {
            return 'array of object';
        }

        return 'array';
    }

    // 📌 Numeric ranges / string length caps, when declared.
    $range = '';

    if (isset($propSchema['minimum']) && isset($propSchema['maximum'])) {
        $range = ' (' . oapiScalarToString($propSchema['minimum']) . '-'
            . oapiScalarToString($propSchema['maximum']) . ')';
    } elseif (isset($propSchema['maxLength'])) {
        $range = ' (max ' . oapiScalarToString($propSchema['maxLength']) . ' chars)';
    }

    if (isset($propSchema['format'])) {
        return $type . ' (' . (string) $propSchema['format'] . ')' . $range;
    }

    return $type . $range;
}

// ==============================================================================
// 📋 oapiRenderSchemaProperties() - Render an object schema's properties
// ==============================================================================

/**
 * 📋 Render a resolved object schema's properties as an escaped HTML definition list
 *
 * Recurses into nested object properties (e.g. Customization's `gradient`
 * field, or an array's `items` schema) up to a depth cap, so a request/response
 * body schema renders its FULL shape - not just the top level - without any
 * hand-maintained duplicate of the spec.
 *
 * @param array $schemaOrRef The (possibly still-$ref'd) schema to render
 * @param array $spec        The full decoded OpenAPI document
 * @param int   $depth       Current recursion depth (internal use)
 *
 * @return string Escaped HTML (a `<dl>` list, or a one-line fallback message)
 *
 * @see oapiResolveSchema()
 * @see oapiPropertyTypeLabel()
 */
function oapiRenderSchemaProperties(array $schemaOrRef, array $spec, int $depth = 0): string
{
    if ($depth > 4) {
        return '<p class="text-body-secondary fst-italic mb-0">'
            . '(nested further - see the full schema in openapi.json)</p>';
    }

    $resolved = oapiResolveSchema($schemaOrRef, $spec, $depth);

    if (!isset($resolved['properties']) || !is_array($resolved['properties']) || count($resolved['properties']) === 0) {
        if (isset($resolved['description']) && is_string($resolved['description']) && $resolved['description'] !== '') {
            return oapiFormatDescription($resolved['description']);
        }

        return '<p class="text-body-secondary fst-italic mb-0">(no fixed sub-properties - see description above)</p>';
    }

    $requiredList = [];
    if (isset($resolved['required']) && is_array($resolved['required'])) {
        $requiredList = $resolved['required'];
    }

    $html = '<dl class="row mb-0 oapi-schema">';

    foreach ($resolved['properties'] as $propName => $propSchema) {
        if (!is_array($propSchema)) {
            continue;
        }

        $isRequired = in_array((string) $propName, $requiredList, true);

        $html .= '<dt class="col-sm-4 col-lg-3 text-break">'
            . '<code>' . htmlspecialchars((string) $propName, ENT_QUOTES, 'UTF-8') . '</code>';

        if ($isRequired) {
            $html .= ' <span class="badge text-bg-danger">required</span>';
        }

        $html .= '</dt>';
        $html .= '<dd class="col-sm-8 col-lg-9 mb-3">';

        $typeLabel = oapiPropertyTypeLabel($propSchema);

        if ($typeLabel !== '') {
            $html .= '<div><span class="badge text-bg-secondary">'
                . htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') . '</span>';

            if (array_key_exists('default', $propSchema)) {
                $html .= ' <span class="badge text-bg-light border">default: '
                    . htmlspecialchars(oapiScalarToString($propSchema['default']), ENT_QUOTES, 'UTF-8')
                    . '</span>';
            }

            $html .= '</div>';
        }

        if (isset($propSchema['enum']) && is_array($propSchema['enum']) && count($propSchema['enum']) > 0) {
            $enumParts = [];
            foreach ($propSchema['enum'] as $enumValue) {
                $enumParts[] = '<code>' . htmlspecialchars(oapiScalarToString($enumValue), ENT_QUOTES, 'UTF-8') . '</code>';
            }
            $html .= '<div class="small text-body-secondary mt-1">enum: ' . implode(', ', $enumParts) . '</div>';
        }

        if (isset($propSchema['description']) && is_string($propSchema['description']) && $propSchema['description'] !== '') {
            $html .= '<div class="mt-1">' . oapiFormatDescription($propSchema['description']) . '</div>';
        }

        // 📌 Recurse into a nested object (via $ref/allOf/inline properties).
        $nestedResolved = oapiResolveSchema($propSchema, $spec, $depth + 1);

        if (isset($nestedResolved['properties']) && is_array($nestedResolved['properties']) && count($nestedResolved['properties']) > 0) {
            $html .= '<div class="mt-2 ps-3 border-start">'
                . oapiRenderSchemaProperties($propSchema, $spec, $depth + 1) . '</div>';
        } elseif (isset($propSchema['items']) && is_array($propSchema['items'])) {
            // 📌 Array of objects: recurse into the items schema.
            $itemsResolved = oapiResolveSchema($propSchema['items'], $spec, $depth + 1);

            if (isset($itemsResolved['properties']) && is_array($itemsResolved['properties']) && count($itemsResolved['properties']) > 0) {
                $html .= '<div class="mt-2 ps-3 border-start">'
                    . '<div class="small text-body-secondary mb-1">Each item:</div>'
                    . oapiRenderSchemaProperties($propSchema['items'], $spec, $depth + 1) . '</div>';
            }
        }

        $html .= '</dd>';
    }

    $html .= '</dl>';

    return $html;
}

// ==============================================================================
// 🔑 oapiRenderAuthSummary() - Plain-text auth summary from an operation's security array
// ==============================================================================

/**
 * 🔑 Summarise an operation's `security` requirement array as plain text
 *
 * An empty requirement object (`{}`, decoded as an empty PHP array) means
 * "anonymous is allowed" per the OpenAPI 3.0 spec; a non-empty requirement
 * lists one or more security scheme names that satisfy it.
 *
 * @param array $security The operation's `security` array (list of requirement objects)
 *
 * @return string Plain-text summary (caller must escape before output)
 *
 * @see https://spec.openapis.org/oas/v3.0.3#security-requirement-object
 */
function oapiRenderAuthSummary(array $security): string
{
    $anonymousAllowed = false;
    $schemeNames      = [];

    foreach ($security as $requirement) {
        if (!is_array($requirement) || count($requirement) === 0) {
            $anonymousAllowed = true;
            continue;
        }

        foreach (array_keys($requirement) as $schemeName) {
            $schemeNames[] = (string) $schemeName;
        }
    }

    $schemeNames = array_values(array_unique($schemeNames));

    $labels = [
        'ApiKeyAuth' => 'API key (X-API-Key header)',
        'CsrfAuth'   => 'session CSRF token (X-CSRF-Token header)',
    ];

    $parts = [];
    foreach ($schemeNames as $schemeName) {
        if (isset($labels[$schemeName])) {
            $parts[] = $labels[$schemeName];
        } else {
            $parts[] = $schemeName;
        }
    }

    if (count($parts) > 0 && $anonymousAllowed) {
        return 'Anonymous requests are allowed (rate limited more strictly); '
            . 'authenticate with ' . implode(' or ', $parts) . ' for higher rate limits.';
    }

    if (count($parts) > 0) {
        return 'Authentication required: ' . implode(' or ', $parts) . '.';
    }

    if ($anonymousAllowed) {
        return 'No authentication required (anonymous), but still rate limited.';
    }

    return 'No authentication requirements declared.';
}

// ==============================================================================
// ⏱️ oapiOperationHasRateLimitHeaders() - Detect declared X-RateLimit-* headers
// ==============================================================================

/**
 * ⏱️ Detect whether any response on an operation declares X-RateLimit-* headers
 *
 * Rather than hardcoding "this endpoint is rate limited" per route name (which
 * would be exactly the kind of hand-duplicated fact this page is designed to
 * avoid), this scans the operation's own `responses` object for a declared
 * `X-RateLimit-Limit`/`X-RateLimit-Remaining`/`X-RateLimit-Reset` response
 * header (after resolving any `$ref`'d response) - deriving the fact straight
 * from the spec.
 *
 * @param array $responses The operation's `responses` object
 * @param array $spec      The full decoded OpenAPI document
 *
 * @return bool True if at least one response declares an X-RateLimit-* header
 */
function oapiOperationHasRateLimitHeaders(array $responses, array $spec): bool
{
    foreach ($responses as $responseDef) {
        if (!is_array($responseDef)) {
            continue;
        }

        $resolved = $responseDef;

        if (isset($resolved['$ref']) && is_string($resolved['$ref'])) {
            $target = oapiResolveRef($resolved['$ref'], $spec);
            if (is_array($target)) {
                $resolved = $target;
            }
        }

        if (!isset($resolved['headers']) || !is_array($resolved['headers'])) {
            continue;
        }

        foreach (array_keys($resolved['headers']) as $headerName) {
            if (stripos((string) $headerName, 'X-RateLimit') === 0) {
                return true;
            }
        }
    }

    return false;
}

// ==============================================================================
// 📐 oapiRenderParameters() - Render an operation's `parameters` array
// ==============================================================================

/**
 * 📐 Render an operation's `parameters` array (path/query/header parameters)
 *
 * @param array $parameters The operation's `parameters` array
 *
 * @return string Escaped HTML (a `<dl>` list), or '' if there are none
 */
function oapiRenderParameters(array $parameters): string
{
    if (count($parameters) === 0) {
        return '';
    }

    $html = '<dl class="row mb-0">';

    foreach ($parameters as $param) {
        if (!is_array($param) || !isset($param['name'])) {
            continue;
        }

        $name        = (string) $param['name'];
        $in          = isset($param['in']) ? (string) $param['in'] : '';
        $required    = isset($param['required']) && $param['required'] === true;
        $description = (isset($param['description']) && is_string($param['description'])) ? $param['description'] : '';

        $html .= '<dt class="col-sm-3"><code>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</code>';

        if ($required) {
            $html .= ' <span class="badge text-bg-danger">required</span>';
        }

        $html .= '</dt><dd class="col-sm-9 mb-2">';
        $html .= '<span class="badge text-bg-secondary">' . htmlspecialchars($in, ENT_QUOTES, 'UTF-8') . '</span> ';

        if ($description !== '') {
            $html .= oapiFormatDescription($description);
        }

        $html .= '</dd>';
    }

    $html .= '</dl>';

    return $html;
}

// ==============================================================================
// 🖨️ oapiRenderExampleValue() - Render a request/response example as escaped JSON
// ==============================================================================

/**
 * 🖨️ Render a single example `value` (string or structured) as an escaped `<pre>` block
 *
 * Some examples in openapi.json are pre-formatted XML strings (the `_xml`
 * examples); others are structured PHP arrays decoded from the JSON examples.
 * Both are rendered as escaped, monospaced text - never as live markup.
 *
 * @param mixed $value The example's `value` (string or array)
 *
 * @return string Escaped HTML `<pre><code>...</code></pre>` block
 */
function oapiRenderExampleValue($value): string
{
    if (is_string($value)) {
        $text = $value;
    } else {
        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $text    = ($encoded === false) ? '' : $encoded;
    }

    return '<pre class="bg-body-tertiary p-2 rounded small mb-0 text-wrap"><code>'
        . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
        . '</code></pre>';
}

// ==============================================================================
// 📬 oapiRenderResponses() - Render an operation's `responses` object
// ==============================================================================

/**
 * 📬 Render an operation's `responses` object as escaped HTML
 *
 * For each declared status code: resolves a `$ref`'d shared response
 * (`#/components/responses/...`), shows the description, any declared response
 * headers, and every example found under its content types.
 *
 * @param array $responses The operation's `responses` object
 * @param array $spec      The full decoded OpenAPI document
 *
 * @return string Escaped HTML
 */
function oapiRenderResponses(array $responses, array $spec): string
{
    $html = '';

    foreach ($responses as $status => $responseDef) {
        if (!is_array($responseDef)) {
            continue;
        }

        $resolved = $responseDef;

        if (isset($resolved['$ref']) && is_string($resolved['$ref'])) {
            $target = oapiResolveRef($resolved['$ref'], $spec);
            if (is_array($target)) {
                $resolved = $target;
            }
        }

        $statusStr  = (string) $status;
        $badgeClass = oapiStatusBadgeClass($statusStr);

        $html .= '<div class="mb-3 pb-3 border-bottom">';
        $html .= '<span class="badge ' . $badgeClass . '">' . htmlspecialchars($statusStr, ENT_QUOTES, 'UTF-8') . '</span> ';

        if (isset($resolved['description']) && is_string($resolved['description'])) {
            $html .= '<span class="align-middle">' . htmlspecialchars($resolved['description'], ENT_QUOTES, 'UTF-8') . '</span>';
        }

        if (isset($resolved['headers']) && is_array($resolved['headers']) && count($resolved['headers']) > 0) {
            $headerNames = [];
            foreach (array_keys($resolved['headers']) as $headerName) {
                $headerNames[] = '<code>' . htmlspecialchars((string) $headerName, ENT_QUOTES, 'UTF-8') . '</code>';
            }
            $html .= '<div class="small text-body-secondary mt-1">Headers: ' . implode(', ', $headerNames) . '</div>';
        }

        if (isset($resolved['content']) && is_array($resolved['content'])) {
            foreach ($resolved['content'] as $mediaType => $mediaDef) {
                if (!is_array($mediaDef) || !isset($mediaDef['examples']) || !is_array($mediaDef['examples'])) {
                    continue;
                }

                foreach ($mediaDef['examples'] as $exampleDef) {
                    if (!is_array($exampleDef) || !array_key_exists('value', $exampleDef)) {
                        continue;
                    }

                    $summary = (isset($exampleDef['summary']) && is_string($exampleDef['summary'])) ? $exampleDef['summary'] : '';

                    $html .= '<div class="mt-2">';
                    $html .= '<div class="small fw-semibold">'
                        . htmlspecialchars((string) $mediaType, ENT_QUOTES, 'UTF-8')
                        . ($summary !== '' ? ' &mdash; ' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') : '')
                        . '</div>';
                    $html .= oapiRenderExampleValue($exampleDef['value']);
                    $html .= '</div>';
                }
            }
        }

        $html .= '</div>';
    }

    return $html;
}
