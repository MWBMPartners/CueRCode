<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - API Error-Code Catalogue Drift Guard (#58)
 * ==============================================================================
 *
 * Path: tests/test_error_catalogue.php
 *
 * The single biggest risk to "the documented error_code catalogue matches what
 * the API actually emits" is silent drift: a handler starts returning a new
 * errorResponse(..., 'SOME_NEW_CODE') without the openapi.json catalogue ever
 * being told about it, or a documented code stops being emitted (dead
 * documentation). This file is the anti-drift mechanism for that catalogue,
 * mirroring the existing router/scope/securitySchemes drift guards already
 * proven out in tests/test_openapi_render.php (#50):
 *
 *   1. It re-derives the REAL emitted error-code set by statically scanning the
 *      production source (never by including/executing it - these files have
 *      side effects like CORS headers and dbQuery() calls on include/execute).
 *   2. It compares that derived set, in BOTH directions, against the
 *      machine-readable `x-error-codes` array openapi.json's ErrorResponse
 *      schema now carries (added alongside this test, #58).
 *   3. It spot-checks that specific, easy-to-regress codes are still found in
 *      the specific file that is their canonical source (a "per-file anchor").
 *   4. It proves the human-readable "Known codes include: ..." prose on the
 *      same schema's `error_code` property description stays in sync with the
 *      machine-readable array (the developer portal's errors page renders that
 *      prose live from openapi.json - see public_html/developers/errors/).
 *
 * ------------------------------------------------------------------------------
 * 🔍 Five emission idioms, five scan strategies
 * ------------------------------------------------------------------------------
 * The production code expresses "this response carries error code X" five
 * different ways across the codebase (see fn_response.php's errorResponse()
 * and the various fn_api_*.php handlers). Idiom 1 needs a REAL PHP tokenizer,
 * not a regex, for a documented, concrete reason:
 *
 *   ⚠️ TOKEN SCANNER, NOT REGEX, FOR IDIOM 1 - THE SEMICOLON PITFALL
 *   A naive regex like `/errorResponse\(\s*'[^;]+',\s*(\d+),\s*'([A-Z_]+)'/`
 *   (bounded on "no semicolon before the first comma") looks reasonable until
 *   a real human-readable error MESSAGE contains a semicolon of its own. This
 *   is not a hypothetical: fn_api_account.php:895 reads
 *     'Account management requires an interactive session; API keys cannot
 *     manage the account.'
 *   as the FIRST argument to errorResponse(). A `[^;]`-bounded regex stops
 *   dead at that semicolon and never reaches the real 403/ACCOUNT_MANAGEMENT_
 *   FORBIDDEN third argument a few lines later, silently under-counting. Using
 *   token_get_all() and walking real parenthesis-depth-aware argument
 *   boundaries (splitting on top-level commas only) sidesteps this entirely -
 *   a semicolon inside a T_CONSTANT_ENCAPSED_STRING is just string content,
 *   never a token boundary. Idioms 2-5 are simple `'key' => 'VALUE'` / `$var =
 *   'VALUE'` shapes with no risk of an embedded delimiter, so a regex over
 *   php_strip_whitespace() output (comments stripped, string literals intact)
 *   is suffficient and far simpler there.
 *
 *   Idiom 1: errorResponse($message, $httpCode, 'LITERAL_CODE', ...) - a
 *            literal string as the 3rd positional argument. Scanned with
 *            token_get_all() across ALL 13 allowlisted files.
 *   Idiom 2: 'errorCode' => 'LITERAL_CODE' result-array keys (e.g.
 *            fn_api_accounts.php, fn_api_account.php, fn_api_keys.php, and
 *            fn_features.php's requireFeatureOr402() - whose returned
 *            'errorCode' value the router forwards on as
 *            $featureDenied['errorCode'], a NON-literal 3rd argument that
 *            idiom 1 correctly does NOT match). Regex over ALL 13 files.
 *   Idiom 3: 'code' => 'LITERAL_CODE' bulk parser/validator keys - scoped to
 *            fn_api_bulk.php ONLY (parseBulkInput()/validateBulkRequest()'s
 *            own return shape; no other scanned file uses this exact key for
 *            an error code).
 *   Idiom 4: $errorCode = 'LITERAL_CODE' / $authErrorCode = 'LITERAL_CODE'
 *            variable assignments (the router's auth-failure branch and
 *            fn_api_bulk.php's parse/validation-error branches). Regex over
 *            ALL 13 files.
 *   Idiom 5: 'error_code' => 'LITERAL_CODE' (catches fn_response.php's own
 *            hard-coded JSON_ENCODE_ERROR fallback inside jsonResponse(), the
 *            one place the response envelope's own top-level key name is used
 *            as the array key rather than the internal 'errorCode' convention
 *            idioms 2-4 use). Regex over ALL 13 files.
 *
 * ------------------------------------------------------------------------------
 * 📂 Scanned-file allowlist (13) and rationale for the 2 exclusions
 * ------------------------------------------------------------------------------
 * public_html/api/v1/index.php (the router) + the 12 fn_*.php files an API
 * request can actually reach: fn_api_generate.php, fn_api_bulk.php,
 * fn_api_download.php, fn_api_qrcodes.php, fn_api_accounts.php,
 * fn_api_account.php, fn_api_keys.php, fn_api_types.php, fn_api_auth.php,
 * fn_auth.php, fn_features.php, fn_response.php.
 *
 * EXCLUDED, deliberately, each with its own assertFalse() exclusion-guard
 * assertion below proving neither exclusion's codes leak into the emitted
 * union anyway:
 *   - fn_oauth.php: SSO_STATE_INVALID / ID_TOKEN_EXPIRED etc. are emitted only
 *     from the BROWSER-REDIRECT SSO flow (public_html/auth/index.php +
 *     auth/callback/index.php), which is intentionally OUTSIDE the versioned
 *     JSON api/v1 surface this ErrorResponse schema describes (see
 *     test_openapi_render.php's own router drift guard, which documents the
 *     same "auth/{provider}" exemption from the OTHER direction).
 *   - fn_error_handler.php: its only 'errorCode' mention (SQL_ERROR) lives
 *     inside logError()'s own @example docblock, not real emitted code - it is
 *     a documentation example of logError()'s $extra shape, unrelated to the
 *     API's errorResponse() envelope entirely.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (E7 developer-portal/API-catalogue cycle, #58)
 * @see         tests/bootstrap.php  - requireFn() / CUERCODE_SITE_ROOT / CUERCODE_FUNCTIONS_DIR
 * @see         tests/lib/assert.php - the assert* functions used below
 * @see         tests/test_openapi_render.php - the sibling drift guard this mirrors
 * @see         web/CueRCode.net/_functions/fn_response.php - errorResponse()/jsonResponse()
 * @see         web/CueRCode.net/public_html/api/v1/openapi.json - ErrorResponse schema
 * @see         https://www.php.net/manual/en/function.token-get-all.php
 * @see         https://www.php.net/manual/en/function.php-strip-whitespace.php
 */

// ==============================================================================
// 🧰 Load the OpenAPI render helper (only loadOpenApiSpecFromFile() is used
// here - purely to parse the REAL shipped openapi.json exactly the way the
// developer portal does, no rendering).
// ==============================================================================
requireFn('fn_openapi_render.php');

// ==============================================================================
// 🔧 errcatExtractLiteralThirdArgCodes() - Idiom 1 (token-scanner, NOT regex)
// ==============================================================================

/**
 * 🔧 Extract every literal 3rd-argument error code from errorResponse() calls
 * in a single PHP source file, using a real tokenizer.
 *
 * Walks the token stream looking for the T_STRING identifier "errorResponse"
 * immediately followed (ignoring whitespace) by an opening parenthesis, then
 * collects that call's arguments by tracking parenthesis depth and splitting
 * on top-level (depth === 1) commas ONLY - so a nested function call or array
 * literal inside an argument never gets mistaken for an argument boundary.
 * The 3rd collected argument is captured ONLY when it reduces (after
 * discarding whitespace tokens) to exactly one token that is itself a
 * T_CONSTANT_ENCAPSED_STRING matching /^'([A-Z][A-Z0-9_]*)'$/ - anything else
 * (a variable, a concatenation, an array-index expression like
 * $featureDenied['errorCode']) is correctly left uncaptured, since it is not a
 * literal code this text-level scan could safely name.
 *
 * @param string $filePath Absolute path to the PHP source file to scan.
 *
 * @return array<int, string> Every literal code found (may contain duplicates
 *                             across multiple call sites in the same file -
 *                             callers should array_unique() as needed).
 *
 * @see https://www.php.net/manual/en/function.token-get-all.php
 * @see https://www.php.net/manual/en/tokens.php
 */
function errcatExtractLiteralThirdArgCodes(string $filePath): array
{
    $codesFound = [];

    // 📌 Tokenize the real file contents - never include()/require() it, since
    // several of these files have real side effects (CORS headers, session
    // touches, dbQuery() calls) the moment they execute.
    $sourceText = (string) file_get_contents($filePath);
    $tokens     = token_get_all($sourceText);
    $tokenCount = count($tokens);

    for ($tokenIndex = 0; $tokenIndex < $tokenCount; $tokenIndex++) {
        $currentToken = $tokens[$tokenIndex];

        // 🔎 Look for the T_STRING identifier "errorResponse".
        $isErrorResponseIdentifier = is_array($currentToken)
            && $currentToken[0] === T_STRING
            && $currentToken[1] === 'errorResponse';

        if (!$isErrorResponseIdentifier) {
            continue;
        }

        // 📌 Skip whitespace to find the opening parenthesis. If the very
        // next meaningful token is not '(', this is not actually a function
        // call (e.g. a comment mentioning the word) - skip it.
        $peekIndex = $tokenIndex + 1;
        while ($peekIndex < $tokenCount
            && is_array($tokens[$peekIndex])
            && $tokens[$peekIndex][0] === T_WHITESPACE
        ) {
            $peekIndex++;
        }

        if ($peekIndex >= $tokenCount || $tokens[$peekIndex] !== '(') {
            continue;
        }

        // ==============================================================
        // 📐 Walk the argument list at paren-depth 1, splitting on
        // top-level commas only, so nested calls/arrays inside an
        // argument are never mistaken for argument boundaries.
        // ==============================================================
        $parenDepth      = 1;
        $walkIndex       = $peekIndex + 1;
        $collectedArgs   = [];
        $currentArgToken = [];

        while ($walkIndex < $tokenCount && $parenDepth > 0) {
            $walkToken = $tokens[$walkIndex];

            if ($walkToken === '(') {
                $parenDepth++;
                $currentArgToken[] = $walkToken;
            } elseif ($walkToken === ')') {
                $parenDepth--;
                if ($parenDepth === 0) {
                    // 🏁 Closing paren of the errorResponse(...) call itself.
                    $collectedArgs[] = $currentArgToken;
                    break;
                }
                $currentArgToken[] = $walkToken;
            } elseif ($walkToken === ',' && $parenDepth === 1) {
                // 📌 A TOP-LEVEL comma - ends the current argument.
                $collectedArgs[] = $currentArgToken;
                $currentArgToken = [];
            } else {
                $currentArgToken[] = $walkToken;
            }

            $walkIndex++;
        }

        // 📌 The 3rd argument (index 2) is the $errorCode parameter per
        // errorResponse()'s signature in fn_response.php.
        if (!isset($collectedArgs[2])) {
            continue;
        }

        // 🧹 Strip whitespace tokens so we can check "exactly one meaningful
        // token" without being tripped up by surrounding formatting.
        $meaningfulTokens = array_values(array_filter(
            $collectedArgs[2],
            function ($argToken): bool {
                return !(is_array($argToken) && $argToken[0] === T_WHITESPACE);
            }
        ));

        $isSingleLiteralString = count($meaningfulTokens) === 1
            && is_array($meaningfulTokens[0])
            && $meaningfulTokens[0][0] === T_CONSTANT_ENCAPSED_STRING;

        if (!$isSingleLiteralString) {
            // 📌 Not a bare string literal (e.g. $featureDenied['errorCode']
            // in the router's feature-gate branch) - correctly left uncaptured
            // by this idiom; idiom 2 catches its true source elsewhere.
            continue;
        }

        // 📌 The raw token text still carries its surrounding quotes
        // (e.g. "'ACCOUNT_MANAGEMENT_FORBIDDEN'") - strip them via the anchor
        // pattern and validate the SCREAMING_SNAKE_CASE code shape at once.
        if (preg_match("/^'([A-Z][A-Z0-9_]*)'$/", $meaningfulTokens[0][1], $codeMatch) === 1) {
            $codesFound[] = $codeMatch[1];
        }
    }

    return $codesFound;
}

// ==============================================================================
// 🔧 errcatExtractRegexIdiomCodes() - Idioms 2-5 (regex over stripped source)
// ==============================================================================

/**
 * 🔧 Extract error codes expressed via idioms 2-5 ('errorCode' => 'X',
 * 'code' => 'X', $errorCode = 'X', $authErrorCode = 'X', 'error_code' => 'X')
 * from a single PHP source file.
 *
 * Runs the regexes over php_strip_whitespace($filePath) rather than the raw
 * source - that function tokenizes the file and re-emits it with comments and
 * extraneous whitespace removed while leaving string literals fully intact,
 * so a code name mentioned only in a comment can never be miscounted as a
 * real emission, and a real emission split across multiple lines is
 * normalised onto one line the regex can still match.
 *
 * The 'code' => 'X' idiom (idiom 3) is scoped to fn_api_bulk.php ONLY, per
 * the caller-supplied $isBulkParserFile flag - no other scanned file uses a
 * bare 'code' array key to mean an API error code.
 *
 * @param string $filePath         Absolute path to the PHP source file to scan.
 * @param bool   $isBulkParserFile Whether idiom 3 ('code' => 'X') should also
 *                                 be scanned for this file (true ONLY for
 *                                 fn_api_bulk.php).
 *
 * @return array<int, string> Every code found (may contain duplicates).
 *
 * @see https://www.php.net/manual/en/function.php-strip-whitespace.php
 */
function errcatExtractRegexIdiomCodes(string $filePath, bool $isBulkParserFile): array
{
    $codesFound = [];

    // 📌 Comments stripped, string literals intact, side-effect-free (this
    // reads and re-tokenizes the file text - it never executes it).
    $strippedSource = (string) php_strip_whitespace($filePath);

    // 🔎 Idiom 2: 'errorCode' => 'X' result-array keys (all files).
    if (preg_match_all("/'errorCode'\\s*=>\\s*'([A-Z][A-Z0-9_]*)'/", $strippedSource, $errorCodeKeyMatches) !== false) {
        foreach ($errorCodeKeyMatches[1] as $matchedCode) {
            $codesFound[] = $matchedCode;
        }
    }

    // 🔎 Idiom 3: 'code' => 'X' bulk parser/validator keys - fn_api_bulk.php ONLY.
    if ($isBulkParserFile) {
        if (preg_match_all("/'code'\\s*=>\\s*'([A-Z][A-Z0-9_]*)'/", $strippedSource, $codeKeyMatches) !== false) {
            foreach ($codeKeyMatches[1] as $matchedCode) {
                $codesFound[] = $matchedCode;
            }
        }
    }

    // 🔎 Idiom 4a: $errorCode = 'X' variable assignments (all files).
    if (preg_match_all("/\\\$errorCode\\s*=\\s*'([A-Z][A-Z0-9_]*)'/", $strippedSource, $errorCodeVarMatches) !== false) {
        foreach ($errorCodeVarMatches[1] as $matchedCode) {
            $codesFound[] = $matchedCode;
        }
    }

    // 🔎 Idiom 4b: $authErrorCode = 'X' variable assignments (all files).
    if (preg_match_all("/\\\$authErrorCode\\s*=\\s*'([A-Z][A-Z0-9_]*)'/", $strippedSource, $authErrorCodeVarMatches) !== false) {
        foreach ($authErrorCodeVarMatches[1] as $matchedCode) {
            $codesFound[] = $matchedCode;
        }
    }

    // 🔎 Idiom 5: 'error_code' => 'X' (catches fn_response.php's JSON_ENCODE_ERROR
    // fallback inside jsonResponse()) (all files).
    if (preg_match_all("/'error_code'\\s*=>\\s*'([A-Z][A-Z0-9_]*)'/", $strippedSource, $errorCodeEnvelopeMatches) !== false) {
        foreach ($errorCodeEnvelopeMatches[1] as $matchedCode) {
            $codesFound[] = $matchedCode;
        }
    }

    return $codesFound;
}

// ==============================================================================
// 📂 Step 1: The scanned-file allowlist (13 files) - assert each exists first
// ==============================================================================

$routerSourcePath = CUERCODE_SITE_ROOT . 'public_html' . DIRECTORY_SEPARATOR
    . 'api' . DIRECTORY_SEPARATOR . 'v1' . DIRECTORY_SEPARATOR . 'index.php';

// 📌 Label => [absolute path, is this fn_api_bulk.php (idiom 3 scope)?].
// Full multi-line notation, one entry per line, per house style.
$scannedFiles = [
    'index.php'            => [$routerSourcePath, false],
    'fn_api_generate.php'  => [CUERCODE_FUNCTIONS_DIR . 'fn_api_generate.php', false],
    'fn_api_bulk.php'      => [CUERCODE_FUNCTIONS_DIR . 'fn_api_bulk.php', true],
    'fn_api_download.php'  => [CUERCODE_FUNCTIONS_DIR . 'fn_api_download.php', false],
    'fn_api_qrcodes.php'   => [CUERCODE_FUNCTIONS_DIR . 'fn_api_qrcodes.php', false],
    'fn_api_accounts.php'  => [CUERCODE_FUNCTIONS_DIR . 'fn_api_accounts.php', false],
    'fn_api_account.php'   => [CUERCODE_FUNCTIONS_DIR . 'fn_api_account.php', false],
    'fn_api_keys.php'      => [CUERCODE_FUNCTIONS_DIR . 'fn_api_keys.php', false],
    'fn_api_types.php'     => [CUERCODE_FUNCTIONS_DIR . 'fn_api_types.php', false],
    'fn_api_auth.php'      => [CUERCODE_FUNCTIONS_DIR . 'fn_api_auth.php', false],
    'fn_auth.php'          => [CUERCODE_FUNCTIONS_DIR . 'fn_auth.php', false],
    'fn_features.php'      => [CUERCODE_FUNCTIONS_DIR . 'fn_features.php', false],
    'fn_response.php'      => [CUERCODE_FUNCTIONS_DIR . 'fn_response.php', false],
];

assertEquals(
    13,
    count($scannedFiles),
    'the scanned-file allowlist has exactly the 13 files the plan specifies'
);

foreach ($scannedFiles as $fileLabel => $fileInfo) {
    assertTrue(
        is_file($fileInfo[0]),
        'scanned-file allowlist entry exists on disk: ' . $fileLabel . ' (' . $fileInfo[0] . ')'
    );
}

// ==============================================================================
// 🔬 Step 2: Run all five idioms across all 13 files, tracking per-file source
// ==============================================================================

$emittedCodeSet  = [];   // code => true (the deduplicated emitted union)
$codesByFile     = [];   // basename => [code => true, ...] (for per-file anchors)
$literalOnlyCodeSet = []; // idiom-1-only codes, for the plausibility floor

foreach ($scannedFiles as $fileLabel => $fileInfo) {
    $filePath          = $fileInfo[0];
    $isBulkParserFile  = $fileInfo[1];

    $literalCodes = errcatExtractLiteralThirdArgCodes($filePath);
    $regexCodes   = errcatExtractRegexIdiomCodes($filePath, $isBulkParserFile);

    $codesByFile[$fileLabel] = [];

    foreach ($literalCodes as $literalCode) {
        $emittedCodeSet[$literalCode]        = true;
        $literalOnlyCodeSet[$literalCode]    = true;
        $codesByFile[$fileLabel][$literalCode] = true;
    }

    foreach ($regexCodes as $regexCode) {
        $emittedCodeSet[$regexCode]        = true;
        $codesByFile[$fileLabel][$regexCode] = true;
    }
}

$emittedCodesList = array_keys($emittedCodeSet);
sort($emittedCodesList, SORT_STRING);

// ==============================================================================
// 📊 Step 3: Plausibility floors - a scanner that suddenly finds almost
// nothing is a broken scanner (a silent regression in the idioms above), not
// evidence the API stopped emitting error codes.
// ==============================================================================

assertTrue(
    count($literalOnlyCodeSet) >= 50,
    'plausibility floor: the idiom-1 (literal errorResponse() 3rd-argument) token '
    . 'scan alone finds at least 50 distinct codes (found: ' . count($literalOnlyCodeSet) . ')'
);

assertTrue(
    count($emittedCodesList) >= 80,
    'plausibility floor: the full 5-idiom emitted-code union has at least 80 distinct '
    . 'codes (found: ' . count($emittedCodesList) . ')'
);

// ==============================================================================
// 📂 Step 4: Load the REAL shipped openapi.json and its x-error-codes array
// ==============================================================================

$realSpecPath = CUERCODE_SITE_ROOT . 'public_html' . DIRECTORY_SEPARATOR
    . 'api' . DIRECTORY_SEPARATOR . 'v1' . DIRECTORY_SEPARATOR . 'openapi.json';

$realSpec = loadOpenApiSpecFromFile($realSpecPath);

assertTrue(
    is_array($realSpec),
    'loadOpenApiSpecFromFile() successfully loads and decodes the real openapi.json'
);

$errorResponseSchema = $realSpec['components']['schemas']['ErrorResponse'] ?? null;

assertTrue(
    is_array($errorResponseSchema),
    'the real openapi.json declares components.schemas.ErrorResponse'
);

$xErrorCodes = $errorResponseSchema['x-error-codes'] ?? null;

assertTrue(
    is_array($xErrorCodes) && count($xErrorCodes) > 0,
    'ErrorResponse.x-error-codes exists and is a non-empty array'
);

// 📌 Guard the "string array" shape before doing any string-only work below -
// a single non-string element would otherwise fatal the sort()/prose loop.
$xErrorCodesAllStrings = true;
foreach ((array) $xErrorCodes as $xErrorCodeEntry) {
    if (!is_string($xErrorCodeEntry)) {
        $xErrorCodesAllStrings = false;
        break;
    }
}

assertTrue(
    $xErrorCodesAllStrings,
    'every element of ErrorResponse.x-error-codes is a string'
);

$xErrorCodes = (array) $xErrorCodes;

// ==============================================================================
// ✅ Step 5: x-error-codes shape assertions - sorted, unique, exactly 83
// ==============================================================================

$xErrorCodesSortedCopy = $xErrorCodes;
sort($xErrorCodesSortedCopy, SORT_STRING);

assertEquals(
    $xErrorCodesSortedCopy,
    $xErrorCodes,
    'ErrorResponse.x-error-codes is sorted alphabetically (SORT_STRING ascending)'
);

assertEquals(
    count(array_unique($xErrorCodes)),
    count($xErrorCodes),
    'ErrorResponse.x-error-codes contains no duplicate entries'
);

assertEquals(
    83,
    count($xErrorCodes),
    'ErrorResponse.x-error-codes has exactly 83 entries (the 76 pre-existing codes '
    . 'plus the 7 newly-documented codes: HANDLER_NOT_FOUND, UNKNOWN_HANDLER, '
    . 'FEATURE_NOT_AVAILABLE, BULK_AVERY_ALL_FAILED, BULK_PARSE_ERROR, '
    . 'BULK_VALIDATION_ERROR, JSON_ENCODE_ERROR)'
);

// ==============================================================================
// 🛡️ Step 6: DRIFT GUARD (both directions) - x-error-codes ⇄ real emissions
// ==============================================================================
// This is the whole point of this file. If either list below is non-empty,
// the documented catalogue and the real API surface have drifted apart:
// either a real code shipped undocumented, or a documented code stopped being
// emitted (dead documentation the next reader will trust incorrectly).
// ==============================================================================

$emittedNotInXErrorCodes = array_values(array_diff($emittedCodesList, $xErrorCodes));
$xErrorCodesNotEmitted   = array_values(array_diff($xErrorCodes, $emittedCodesList));

assertEquals(
    [],
    $emittedNotInXErrorCodes,
    'DRIFT GUARD: every code the scanner finds actually emitted is present in '
    . 'ErrorResponse.x-error-codes (emitted-but-undocumented: '
    . implode(', ', $emittedNotInXErrorCodes) . ')'
);

assertEquals(
    [],
    $xErrorCodesNotEmitted,
    'DRIFT GUARD: every code documented in ErrorResponse.x-error-codes is actually '
    . 'emitted somewhere in the scanned production source (documented-but-not-emitted: '
    . implode(', ', $xErrorCodesNotEmitted) . ')'
);

// ==============================================================================
// 📍 Step 7: Per-file anchors - specific codes must be found in their specific
// canonical source file, not merely "somewhere in the union". Guards against a
// future refactor accidentally moving/removing the emission while some OTHER
// unrelated code coincidentally keeps the global union non-empty.
// ==============================================================================

assertTrue(
    isset($codesByFile['fn_api_account.php']['ACCOUNT_MANAGEMENT_FORBIDDEN']),
    'ACCOUNT_MANAGEMENT_FORBIDDEN is found in fn_api_account.php (session-only account '
    . 'management guard, F-026)'
);

assertTrue(
    isset($codesByFile['index.php']['HANDLER_NOT_FOUND']),
    'HANDLER_NOT_FOUND is found in the router (public_html/api/v1/index.php)'
);

assertTrue(
    isset($codesByFile['index.php']['UNKNOWN_HANDLER']),
    'UNKNOWN_HANDLER is found in the router (public_html/api/v1/index.php)'
);

assertTrue(
    isset($codesByFile['fn_features.php']['FEATURE_NOT_AVAILABLE']),
    'FEATURE_NOT_AVAILABLE is found in fn_features.php (requireFeatureOr402())'
);

assertTrue(
    isset($codesByFile['fn_response.php']['JSON_ENCODE_ERROR']),
    'JSON_ENCODE_ERROR is found in fn_response.php (jsonResponse()\'s encode-failure fallback)'
);

assertTrue(
    isset($codesByFile['fn_api_bulk.php']['BULK_AVERY_ALL_FAILED']),
    'BULK_AVERY_ALL_FAILED is found in fn_api_bulk.php (Avery-sheet all-items-failed guard, '
    . 'FG-033/#57)'
);

// ==============================================================================
// 📝 Step 8: Prose sync - every x-error-codes entry must also be named in the
// human-readable error_code property description (the developer portal's
// errors page renders that prose live from openapi.json - see
// public_html/developers/errors/index.php - so this keeps the human-facing
// catalogue text from silently falling out of sync with the machine-readable
// array added alongside it).
// ==============================================================================

$errorCodeDescription = (string) ($errorResponseSchema['properties']['error_code']['description'] ?? '');

assertTrue(
    $errorCodeDescription !== '',
    'ErrorResponse.properties.error_code has a non-empty description'
);

foreach ($xErrorCodes as $xErrorCode) {
    assertContains(
        $xErrorCode,
        $errorCodeDescription,
        'error_code description prose names x-error-codes entry: ' . $xErrorCode
    );
}

// ==============================================================================
// 🚫 Step 9: Exclusion guard - the 2 deliberately-unscanned files' own codes
// must NOT leak into the emitted union (proves the exclusions are inert, not
// merely "not asserted on").
// ==============================================================================
// SSO_STATE_INVALID / ID_TOKEN_EXPIRED: fn_oauth.php - browser-redirect SSO
// flow, outside the versioned JSON api/v1 surface (see this file's header
// docblock and test_openapi_render.php's matching router-scope exemption).
// SQL_ERROR: fn_error_handler.php - a logError() @example docblock value,
// never a real errorResponse() emission.
// AUTH_FAILED / RATE_LIMIT / SQL_ERROR (again): fn_response.php's own
// errorResponse() @example docblock lines - documentation-only illustrative
// codes that were never wired to a real call site (the real codes for those
// scenarios are INVALID_API_KEY/AUTH_REQUIRED and RATE_LIMIT_EXCEEDED
// respectively).
// ==============================================================================

$exclusionGuardCodes = [
    'SSO_STATE_INVALID',
    'ID_TOKEN_EXPIRED',
    'SQL_ERROR',
    'AUTH_FAILED',
];

foreach ($exclusionGuardCodes as $exclusionGuardCode) {
    assertFalse(
        in_array($exclusionGuardCode, $emittedCodesList, true),
        'exclusion guard: ' . $exclusionGuardCode . ' does NOT leak into the emitted-code '
        . 'union from an unscanned file/docblock-only mention'
    );
}
