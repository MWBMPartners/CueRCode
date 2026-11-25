<?php
/**
 * ==============================================================================
 * ✅ CueRCode - Input Validation and Sanitisation
 * ==============================================================================
 *
 * Path: _functions/fn_validation.php
 *
 * Provides functions for validating and sanitising user input. All user-facing
 * input must pass through these functions before being used in database queries,
 * file operations, or output rendering.
 *
 * Functions cover common validation needs:
 *   - General string sanitisation
 *   - Email address validation
 *   - URL validation
 *   - Phone number validation
 *   - Hex colour code validation
 *   - JSON string validation
 *   - File name sanitisation
 *   - Image file upload validation
 *
 * @package     CueRCode
 * @subpackage  Validation
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 1
 * @see         https://www.php.net/manual/en/filter.filters.sanitize.php
 * @see         https://www.php.net/manual/en/filter.filters.validate.php
 * @see         https://www.php.net/manual/en/function.preg-match.php
 * @see         https://cheatsheetseries.owasp.org/cheatsheets/Input_Validation_Cheat_Sheet.html
 */

// 📐 Largest canvas (pixels, each side) an SVG logo may ask to be drawn at -
//    the same as the raster logo limit (CUERCODE_LOGO_MAX_WIDTH/HEIGHT in
//    fn_qr_logo.php). Independent review, Codex C5 finding 2, 2026-11-25.
if (!defined('CUERCODE_SVG_LOGO_MAX_CANVAS')) {
    define('CUERCODE_SVG_LOGO_MAX_CANVAS', 4096);
}

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
// 🛡️ Image upload pixel-dimension limits (F-005)
// ==============================================================================
// Maximum width/height (in pixels) accepted for an uploaded image. getimagesize()
// reads only the header, so a tiny file declaring enormous dimensions (a
// decompression / "pixel bomb") is rejected before any downstream code allocates
// a canvas for it. Conservative defaults that comfortably fit any real logo;
// each is overridable via an earlier define() (e.g. in bootstrap/config) so the
// owner can tune them without editing this file. The byte-size cap is the
// $maxSize parameter of validateImageUpload() (default 5 MB).
// @see https://www.php.net/manual/en/function.getimagesize.php
if (!defined('CUERCODE_UPLOAD_MAX_WIDTH')) {
    // 📐 Max image width in pixels.
    define('CUERCODE_UPLOAD_MAX_WIDTH', 4096);
}
if (!defined('CUERCODE_UPLOAD_MAX_HEIGHT')) {
    // 📐 Max image height in pixels.
    define('CUERCODE_UPLOAD_MAX_HEIGHT', 4096);
}

// ==============================================================================
// 🧹 sanitizeInput() - General string sanitisation
// ==============================================================================

/**
 * 🧹 Sanitise a string input by trimming, stripping tags, and encoding entities
 *
 * Applies a multi-step sanitisation process:
 *   1. Trims leading and trailing whitespace
 *   2. Strips HTML and PHP tags
 *   3. Removes null bytes (which can bypass security filters)
 *   4. Converts special characters to HTML entities
 *
 * This function does NOT validate the content - it only sanitises it.
 * Use the specific validate*() functions for content validation.
 *
 * @param string $input The raw user input string
 *
 * @return string The sanitised string
 *
 * @see https://www.php.net/manual/en/function.trim.php
 * @see https://www.php.net/manual/en/function.strip-tags.php
 * @see https://www.php.net/manual/en/function.htmlspecialchars.php
 * @see https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html
 *
 * ⚠️ CAUTION: this is NOT tag/script *removal*. strip_tags() removes only the
 * tag delimiters, leaving the inner text; htmlspecialchars() then encodes it so
 * the result is inert in an HTML context — but the original text content remains.
 *
 * @example
 *   $clean = sanitizeInput('<script>alert("XSS")</script>Hello');
 *   // Returns: 'alert(&quot;XSS&quot;)Hello'
 *   // (the <script> tags are stripped, the inner text is KEPT, and the quotes
 *   //  are entity-encoded by htmlspecialchars with ENT_QUOTES)
 *
 *   $clean = sanitizeInput('  Hello World  ');
 *   // Returns: 'Hello World'
 */
function sanitizeInput(string $input): string
{
    // 📌 Step 1: Trim leading and trailing whitespace
    // @see https://www.php.net/manual/en/function.trim.php
    $sanitised = trim($input);

    // 📌 Step 2: Remove null bytes (common injection vector)
    // Null bytes (\0) can be used to truncate strings in C-based functions,
    // potentially bypassing file path or string validation.
    // @see https://owasp.org/www-community/attacks/Embedding_Null_Code
    $sanitised = str_replace(chr(0), '', $sanitised);

    // 📌 Step 3: Strip HTML and PHP tags
    // Removes any HTML/XML/PHP tags to prevent XSS via tag injection.
    // @see https://www.php.net/manual/en/function.strip-tags.php
    $sanitised = strip_tags($sanitised);

    // 📌 Step 4: Convert special characters to HTML entities
    // This encodes <, >, &, ", ' into their entity equivalents, preventing
    // any remaining XSS vectors if the output is rendered in HTML context.
    // ENT_QUOTES: Convert both double and single quotes.
    // 'UTF-8': Use UTF-8 encoding for multi-byte character support.
    // @see https://www.php.net/manual/en/function.htmlspecialchars.php
    $sanitised = htmlspecialchars($sanitised, ENT_QUOTES, 'UTF-8');

    return $sanitised;
}

// ==============================================================================
// 📧 validateEmail() - Email address validation
// ==============================================================================

/**
 * 📧 Validate an email address
 *
 * Uses PHP's built-in FILTER_VALIDATE_EMAIL filter, which validates
 * against RFC 5321/5322 syntax rules. This checks format only - it does
 * not verify that the email address actually exists or can receive mail.
 *
 * @param string $email The email address to validate
 *
 * @return bool True if the email is syntactically valid, false otherwise
 *
 * @see https://www.php.net/manual/en/filter.filters.validate.php
 * @see https://www.php.net/manual/en/function.filter-var.php
 * @see https://datatracker.ietf.org/doc/html/rfc5321
 *
 * @example
 *   validateEmail('user@example.com');   // true
 *   validateEmail('not-an-email');       // false
 *   validateEmail('user@.com');          // false
 */
function validateEmail(string $email): bool
{
    // 📌 Trim whitespace before validation
    $email = trim($email);

    // 📌 Check maximum length (RFC 5321 limits the path to 254 characters)
    // @see https://www.php.net/manual/en/function.mb-strlen.php
    if (mb_strlen($email) > 254) {
        return false;
    }

    // ✅ Use PHP's built-in email validation filter
    // FILTER_VALIDATE_EMAIL validates against RFC 5322 syntax.
    // @see https://www.php.net/manual/en/filter.filters.validate.php
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// ==============================================================================
// 🌐 validateURL() - URL validation
// ==============================================================================

/**
 * 🌐 Validate a URL
 *
 * Validates a URL using PHP's FILTER_VALIDATE_URL filter and additionally
 * ensures it uses the http:// or https:// scheme. URLs with other schemes
 * (ftp://, file://, javascript:, etc.) are rejected for security.
 *
 * @param string $url The URL to validate
 *
 * @return bool True if the URL is valid with http(s) scheme, false otherwise
 *
 * @see https://www.php.net/manual/en/filter.filters.validate.php
 * @see https://www.php.net/manual/en/function.parse-url.php
 *
 * @example
 *   validateURL('https://example.com/path?query=1');  // true
 *   validateURL('http://example.com');                 // true
 *   validateURL('ftp://files.example.com');            // false (not http/https)
 *   validateURL('not-a-url');                          // false
 *   validateURL('javascript:alert(1)');                // false
 */
function validateURL(string $url): bool
{
    // 📌 Trim whitespace before validation
    $url = trim($url);

    // 📌 Check maximum length (practical limit for URLs)
    if (mb_strlen($url) > 2048) {
        return false;
    }

    // ✅ Step 1: Use PHP's built-in URL validation filter
    // @see https://www.php.net/manual/en/filter.filters.validate.php
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    // 🔒 Step 2: Ensure the scheme is http or https only
    // This prevents javascript:, data:, file:, and other potentially
    // dangerous URI schemes from being accepted.
    // @see https://www.php.net/manual/en/function.parse-url.php
    $scheme = parse_url($url, PHP_URL_SCHEME);

    if ($scheme === null || $scheme === false) {
        return false;
    }

    // 📌 Compare scheme case-insensitively
    $scheme = strtolower($scheme);

    if ($scheme !== 'http' && $scheme !== 'https') {
        return false;
    }

    return true;
}

// ==============================================================================
// 📞 validatePhone() - Phone number validation
// ==============================================================================

/**
 * 📞 Validate a phone number
 *
 * Validates that the input looks like a plausible phone number. This is a
 * format-level check only - it does not verify the number is in service.
 *
 * Accepted formats include:
 *   - International: +44 20 1234 5678, +1-555-123-4567
 *   - National: 020 1234 5678, (555) 123-4567
 *   - Digits only: 442012345678
 *
 * Allowed characters: digits, spaces, hyphens, parentheses, plus sign, dots.
 * Minimum 7 digits, maximum 15 digits (per ITU-T E.164 recommendation).
 *
 * @param string $phone The phone number to validate
 *
 * @return bool True if the phone number is plausibly valid, false otherwise
 *
 * @see https://www.php.net/manual/en/function.preg-match.php
 * @see https://www.itu.int/rec/T-REC-E.164/en E.164 international numbering plan
 *
 * @example
 *   validatePhone('+44 20 1234 5678');   // true
 *   validatePhone('(555) 123-4567');      // true
 *   validatePhone('+1-555-123-4567');     // true
 *   validatePhone('123');                 // false (too few digits)
 *   validatePhone('not-a-phone');         // false
 */
function validatePhone(string $phone): bool
{
    // 📌 Trim whitespace before validation
    $phone = trim($phone);

    // 📌 Check for empty input
    if ($phone === '') {
        return false;
    }

    // 📌 Step 1: Verify the input contains only allowed characters
    // Allowed: digits (0-9), space, hyphen, plus sign, parentheses, dots
    // @see https://www.php.net/manual/en/function.preg-match.php
    if (preg_match('/^[0-9\s\-\+\(\)\.]+$/', $phone) !== 1) {
        return false;
    }

    // 📌 Step 2: Extract just the digits and verify count
    // ITU-T E.164 allows maximum 15 digits for international numbers.
    // We require a minimum of 7 digits for a plausible phone number.
    // @see https://www.php.net/manual/en/function.preg-replace.php
    $digitsOnly = preg_replace('/[^0-9]/', '', $phone);
    $digitCount = strlen($digitsOnly);

    if ($digitCount < 7 || $digitCount > 15) {
        return false;
    }

    return true;
}

// ==============================================================================
// 🎨 validateColor() - Hex colour code validation
// ==============================================================================

/**
 * 🎨 Validate a hexadecimal colour code
 *
 * Validates that the input is a valid hex colour code in one of these formats:
 *   - 3-digit shorthand: #fff, #F00
 *   - 6-digit full: #ff0000, #2c7a7b
 *   - 8-digit with alpha: #ff000080, #2c7a7bcc
 *
 * The '#' prefix is required. Validation is case-insensitive.
 *
 * @param string $hex The colour code to validate (e.g., '#ff0000')
 *
 * @return bool True if the hex colour is valid, false otherwise
 *
 * @see https://www.php.net/manual/en/function.preg-match.php
 * @see https://developer.mozilla.org/en-US/docs/Web/CSS/hex-color
 *
 * @example
 *   validateColor('#ff0000');   // true  (6-digit)
 *   validateColor('#fff');       // true  (3-digit shorthand)
 *   validateColor('#ff000080'); // true  (8-digit with alpha)
 *   validateColor('ff0000');    // false (missing #)
 *   validateColor('#xyz');       // false (invalid hex characters)
 */
function validateColor(string $hex): bool
{
    // 📌 Trim whitespace before validation
    $hex = trim($hex);

    // ✅ Validate against hex colour pattern
    // Matches: #fff, #ffffff, #ffffffff (3, 6, or 8 hex digits with # prefix)
    // @see https://www.php.net/manual/en/function.preg-match.php
    return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $hex) === 1;
}

// ==============================================================================
// 📋 validateJSON() - JSON string validation
// ==============================================================================

/**
 * 📋 Validate that a string is a JSON object or array
 *
 * Attempts to parse the input as JSON, checks for syntax errors, AND requires
 * that the decoded value be a JSON *structure* — an object or an array. Bare
 * JSON scalars (the literals null, true, false, a number, or a quoted string)
 * are rejected even though they are technically well-formed JSON. This matches
 * the intended use: validating config / form payloads that must be a structured
 * object or array, never a lone scalar.
 *
 * An empty string is likewise invalid.
 *
 * 🐛 FIX (2026-06-28): previously this accepted ANY well-formed JSON, so the
 *    literal 'null' (and 'true' / '123' / '"x"') returned true — contradicting
 *    this docblock. The decoded value is now required to be an array/object,
 *    making code and docblock consistent. validateJSON has no external callers,
 *    so tightening the contract is the lowest-risk fix.
 *
 * @param string $json The JSON string to validate
 *
 * @return bool True if the string is a valid JSON object or array, false otherwise
 *
 * @see https://www.php.net/manual/en/function.json-decode.php
 * @see https://www.php.net/manual/en/function.json-last-error.php
 * @see https://www.php.net/manual/en/json.constants.php
 *
 * @example
 *   validateJSON('{"key": "value"}');        // true  (object)
 *   validateJSON('[1, 2, 3]');                // true  (array)
 *   validateJSON('not json');                  // false (parse error)
 *   validateJSON('');                          // false (empty)
 *   validateJSON('{invalid: json}');           // false (parse error)
 *   validateJSON('null');                      // false (bare scalar, not a structure)
 *   validateJSON('true');                      // false (bare scalar)
 *   validateJSON('123');                       // false (bare scalar)
 *   validateJSON('"a string"');               // false (bare scalar)
 */
function validateJSON(string $json): bool
{
    // 📌 Trim whitespace before validation
    $json = trim($json);

    // 📌 Reject empty strings
    if ($json === '') {
        return false;
    }

    // ✅ Attempt to decode the JSON
    // json_decode() returns null on a parse failure OR for the literal JSON
    // 'null'. We capture the decoded value AND check json_last_error() so we can
    // both reject parse errors and reject well-formed-but-scalar values.
    // Associative decoding (true) makes JSON objects decode to PHP arrays, so a
    // single is_array() check below covers both JSON objects and JSON arrays.
    // @see https://www.php.net/manual/en/function.json-decode.php
    // @see https://www.php.net/manual/en/function.json-last-error.php
    $decoded = json_decode($json, true);

    // 📌 JSON_ERROR_NONE indicates the string parsed without a syntax error.
    // @see https://www.php.net/manual/en/json.constants.php
    if (json_last_error() !== JSON_ERROR_NONE) {
        return false;
    }

    // 📌 Require a JSON structure (object or array). With associative decoding,
    // both JSON objects and JSON arrays become PHP arrays; bare scalars
    // (null / true / false / number / string) do not, so they are rejected.
    return is_array($decoded);
}

// ==============================================================================
// 📄 sanitizeFileName() - File name sanitisation
// ==============================================================================

/**
 * 📄 Sanitise a file name for safe filesystem storage
 *
 * Applies the following sanitisation steps:
 *   1. Extracts only the filename (removes any path components)
 *   2. Removes null bytes
 *   3. Replaces spaces and unsafe characters with hyphens
 *   4. Removes consecutive hyphens
 *   5. Converts to lowercase
 *   6. Truncates to a maximum length
 *   7. Ensures a valid extension is preserved
 *
 * This prevents directory traversal attacks (../ sequences), special character
 * injection, and overly long file names.
 *
 * @param string $filename The original file name to sanitise
 *
 * @return string The sanitised file name (lowercase, safe characters only)
 *
 * @see https://www.php.net/manual/en/function.pathinfo.php
 * @see https://www.php.net/manual/en/function.preg-replace.php
 * @see https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html
 *
 * @example
 *   sanitizeFileName('My Photo (2).JPG');
 *   // Returns: 'my-photo-2.jpg'
 *
 *   sanitizeFileName('../../../etc/passwd');
 *   // Returns: 'passwd'
 *
 *   sanitizeFileName('  hello   world.PNG  ');
 *   // Returns: 'hello-world.png'
 */
function sanitizeFileName(string $filename): string
{
    // 📌 Step 1: Extract just the filename (strip any path components)
    // This prevents directory traversal attacks (e.g., ../../../etc/passwd).
    // @see https://www.php.net/manual/en/function.basename.php
    $filename = basename($filename);

    // 📌 Step 2: Remove null bytes
    $filename = str_replace(chr(0), '', $filename);

    // 📌 Step 3: Separate the extension from the name
    // @see https://www.php.net/manual/en/function.pathinfo.php
    $pathInfo  = pathinfo($filename);
    if (isset($pathInfo['filename'])) {
        $name = $pathInfo['filename'];
    } else {
        $name = 'unnamed';
    }
    if (isset($pathInfo['extension'])) {
        $extension = $pathInfo['extension'];
    } else {
        $extension = '';
    }

    // 📌 Step 4: Replace non-alphanumeric characters (except hyphens and underscores)
    // with hyphens for URL-safe, filesystem-safe naming.
    // @see https://www.php.net/manual/en/function.preg-replace.php
    $name = preg_replace('/[^a-zA-Z0-9\-_]/', '-', $name);

    // 📌 Step 5: Remove consecutive hyphens
    $name = preg_replace('/-+/', '-', $name);

    // 📌 Step 6: Remove leading and trailing hyphens
    $name = trim($name, '-_');

    // 📌 Step 7: Convert to lowercase
    // @see https://www.php.net/manual/en/function.strtolower.php
    $name      = strtolower($name);
    $extension = strtolower($extension);

    // 📌 Step 7b: Trim whitespace from the extension
    // 🐛 FIX (2026-06-28): pathinfo() keeps any trailing/leading whitespace as
    //    part of the extension (e.g. 'world.PNG  ' -> extension 'png  '), which
    //    then leaked into the reassembled name as 'hello-world.png  '. Trim it.
    //    An extension that is *only* whitespace collapses to '' (treated as no
    //    extension), so 'file.   ' does not produce a trailing-dot result.
    // @see https://www.php.net/manual/en/function.trim.php
    $extension = trim($extension);

    // 📌 Step 8: Ensure the name is not empty after sanitisation
    if ($name === '') {
        $name = 'unnamed';
    }

    // 📌 Step 9: Truncate the name to a reasonable length (200 characters max)
    // This prevents filesystem issues with overly long file names.
    // @see https://www.php.net/manual/en/function.mb-substr.php
    $name = mb_substr($name, 0, 200);

    // 📌 Step 10: Reassemble with extension
    if ($extension !== '') {
        return $name . '.' . $extension;
    }

    return $name;
}

// ==============================================================================
// 🛡️ screenSvgLogoContent() - Shared SVG active-content screener (F-024)
// ==============================================================================

/**
 * 🛡️ Screen raw SVG markup for active/script content (XSS vectors)
 *
 * SHARED screener used by BOTH logo-intake paths so they enforce the SAME
 * rule set instead of drifting:
 *   1. The multipart `$_FILES` upload path — validateImageUpload() (below,
 *      this file) calls this function for the `image/svg+xml` branch.
 *   2. The JSON API's base64 `data:image/svg+xml;base64,...` path —
 *      `saveBase64LogoToTemp()` (fn_qr_logo.php) calls this function before
 *      writing decoded SVG bytes to disk. Closes F-024 (SECURITY.md): the
 *      base64 path previously enforced ONLY the F-005 size/pixel caps and
 *      let unscreened SVG bytes reach `overlayLogoOnSVG()`'s output.
 *
 * This is a DEFENSIVE, deny-list content screen — not a full XML sanitiser.
 * When in doubt it rejects, on the theory that a false-positive (a clean SVG
 * momentarily rejected) is far cheaper than a false-negative (a malicious SVG
 * logo reaching the browser inside the generated QR code's own SVG markup).
 *
 * Vectors covered (case-insensitive; matched anywhere in the raw markup so
 * they cannot be smuggled inside CDATA sections, comments, or attribute
 * values):
 *   - `<script` .................. embedded JavaScript
 *   - `on*=` ...................... inline DOM event-handler attributes
 *                                   (onclick, onload, onerror, onmouseover, …)
 *   - `javascript:` / `vbscript:` . scriptable URI schemes in any attribute
 *   - `data:` ..................... data URIs (can smuggle nested active
 *                                   content, e.g. a `data:image/svg+xml`
 *                                   payload containing its OWN `<script>`)
 *   - `<foreignObject` ............ embeds arbitrary (X)HTML inside SVG
 *   - `<!DOCTYPE` / `<!ENTITY` .... XXE / "billion laughs" entity-expansion
 *   - `@import` ................... CSS `@import` (can load external CSS)
 *   - `expression(` ............... legacy CSS `expression()` code execution
 *   - `attributeName="on...` ...... SMIL `<animate>`/`<set>` retargeting an
 *                                   event-handler attribute dynamically,
 *                                   bypassing a naive static `on*=` scan
 *   - non-local `href`/`xlink:href` any `href`/`xlink:href` value that is
 *                                   NOT a same-document `#fragment` — blocks
 *                                   `<use>`/`<image>` pulling in EXTERNAL SVG
 *                                   content (which could itself carry script)
 *
 * @param string $svgContent The raw SVG markup (already decoded — NOT a data
 *                            URI or base64 string) to screen.
 *
 * @return array{valid: bool, error: string|null} 'valid' is true when no
 *              disallowed construct was found; 'error' is a human-readable
 *              reason when 'valid' is false, otherwise null.
 *
 * @see https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html
 * @see https://owasp.org/www-community/vulnerabilities/XML_External_Entity_(XXE)_Processing
 * @see https://developer.mozilla.org/en-US/docs/Web/SVG/Element/foreignObject
 * @see https://developer.mozilla.org/en-US/docs/Web/SVG/Element/animate
 * @see fn_qr_logo.php - saveBase64LogoToTemp() (the base64 intake caller)
 *
 * @example
 *   screenSvgLogoContent('<svg><path d="M0 0h10v10H0z"/></svg>');
 *   // Returns: ['valid' => true, 'error' => null]
 *
 *   screenSvgLogoContent('<svg onload="alert(1)"></svg>');
 *   // Returns: ['valid' => false, 'error' => 'SVG content contains a
 *   //   disallowed construct: inline event-handler attribute (on*=).']
 */
function screenSvgLogoContent(string $svgContent): array
{
    // 📌 Default result: clean until a disallowed construct is found.
    $result = [
        'valid' => true,
        'error' => null,
    ];

    // ==================================================================
    // 📌 Step 1: Simple deny-list of dangerous inline constructs
    // Each pattern is matched case-insensitively against the RAW markup.
    // The array key is the regex; the value is the human-readable label
    // used in the rejection message.
    // @see https://www.php.net/manual/en/function.preg-match.php
    // ==================================================================
    $dangerousPatterns = [
        '/<script\b/i'                              => 'embedded <script> element',
        '/\bon[a-z]+\s*=/i'                          => 'inline event-handler attribute (on*=)',
        '/javascript\s*:/i'                          => 'javascript: URI scheme',
        '/vbscript\s*:/i'                             => 'vbscript: URI scheme',
        '/data\s*:/i'                                 => 'data: URI (potential embedded active content)',
        '/<foreignObject\b/i'                         => '<foreignObject> element (can embed arbitrary HTML)',
        '/<!doctype/i'                                => 'DOCTYPE declaration (XXE / entity-expansion risk)',
        '/<!entity/i'                                 => 'ENTITY declaration (XXE / "billion laughs" risk)',
        '/@import/i'                                  => 'CSS @import',
        '/expression\s*\(/i'                          => 'CSS expression()',
        '/attributeName\s*=\s*["\']?\s*on[a-z]+/i'    => 'SMIL animation (<animate>/<set>) retargeting an event-handler attribute',
    ];

    foreach ($dangerousPatterns as $pattern => $label) {
        if (preg_match($pattern, $svgContent) === 1) {
            $result['valid'] = false;
            $result['error'] = 'SVG content contains a disallowed construct: ' . $label . '.';
            return $result;
        }
    }

    // ==================================================================
    // 📌 Step 2: Non-local href / xlink:href references
    // A legitimate logo SVG never needs to reference anything OUTSIDE its
    // own markup — internal same-document fragment refs (href="#id",
    // used by <use>/<symbol> reuse) are the only accepted form. Anything
    // else (an absolute/relative URL, a protocol-relative "//host/..."
    // reference, or an empty-scheme reference) is rejected: it could pull
    // in an externally-hosted SVG/HTML document carrying its own script.
    // \b before "href" matches both "href=" and "xlink:href=" (the ':' is a
    // non-word character, so the boundary lands correctly either way).
    // @see https://developer.mozilla.org/en-US/docs/Web/SVG/Attribute/href
    // ==================================================================
    if (preg_match_all('/\bhref\s*=\s*["\']([^"\']*)["\']/i', $svgContent, $hrefMatches) > 0) {
        foreach ($hrefMatches[1] as $hrefValue) {
            $hrefValue = trim($hrefValue);

            if ($hrefValue !== '' && !str_starts_with($hrefValue, '#')) {
                $result['valid'] = false;
                $result['error'] = 'SVG content contains a disallowed construct: '
                    . 'external or non-local href/xlink:href reference.';
                return $result;
            }
        }
    }

    // ==================================================================
    // 📌 Step 3: the size the SVG asks to be drawn at (independent review,
    // Codex C5 finding 2, 2026-11-25). A tiny file can declare an enormous
    // canvas (width="200000" height="200000"), and turning it into a PNG
    // (Imagick, for PNG/JPG/GIF/WebP QR codes) would then try to allocate
    // that whole canvas - enough to exhaust the server's memory or disk.
    // The same limit as raster logos applies.
    // ==================================================================
    $canvas = measureSvgLogoCanvas($svgContent);

    if ($canvas['ok'] !== true) {
        $result['valid'] = false;
        $result['error'] = 'SVG content could not be measured: ' . $canvas['error'];
        return $result;
    }

    if ($canvas['width'] > CUERCODE_SVG_LOGO_MAX_CANVAS || $canvas['height'] > CUERCODE_SVG_LOGO_MAX_CANVAS) {
        $result['valid'] = false;
        $result['error'] = 'SVG logo asks to be drawn too large ('
            . (int) ceil($canvas['width']) . ' x ' . (int) ceil($canvas['height'])
            . ' pixels; the most allowed is ' . CUERCODE_SVG_LOGO_MAX_CANVAS . ' x ' . CUERCODE_SVG_LOGO_MAX_CANVAS . ').';
        return $result;
    }

    return $result;
}

// ==============================================================================
// 📐 measureSvgLogoCanvas() - how big does this SVG ask to be drawn?
// ==============================================================================

/**
 * 📐 Work out the canvas size, in pixels, that an SVG's outermost <svg> element
 *    asks for (independent review, Codex C5 finding 2, 2026-11-25).
 *
 * Renderers such as librsvg (used by Imagick) draw an SVG at its root width
 * and height; when those are missing they use the viewBox size; when that is
 * missing too they use a small default. This follows the same order:
 *   - width/height may be plain numbers or px, pt, pc, mm, cm, in, em or ex
 *     (converted at 96 pixels per inch, 16 px per em, 8 px per ex);
 *   - a percentage is taken of the viewBox size (100% = the viewBox size);
 *   - a value that cannot be read at all is an error, so the caller refuses
 *     the logo (fail closed) rather than guess.
 * Comments are removed first, so a decoy <svg> inside a comment cannot hide
 * the real one. DOCTYPE and ENTITY declarations are already refused by
 * screenSvgLogoContent() before this runs.
 *
 * Pure: no disk, no database.
 *
 * @param string $svgContent Raw SVG markup
 *
 * @return array{ok: bool, width: float, height: float, error: string}
 *         width/height are 0.0 when the SVG declares no size at all
 */
function measureSvgLogoCanvas(string $svgContent): array
{
    $withoutComments = (string) preg_replace('/<!--.*?-->/s', '', $svgContent);

    if (preg_match('/<svg\b([^>]*)>/i', $withoutComments, $rootMatch) !== 1) {
        return ['ok' => false, 'width' => 0.0, 'height' => 0.0, 'error' => 'no <svg> element found.'];
    }

    $rootAttributes = $rootMatch[1];

    // 🔎 Read one attribute from the root tag (either quote style).
    $readAttribute = static function (string $name) use ($rootAttributes): ?string {
        if (preg_match('/(?:^|\s)' . preg_quote($name, '/') . '\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $rootAttributes, $attributeMatch) !== 1) {
            return null;
        }
        if (isset($attributeMatch[3]) && $attributeMatch[3] !== '') {
            return trim($attributeMatch[3]);
        }
        return trim($attributeMatch[2]);
    };

    // 📦 The viewBox size, if there is a readable one.
    $viewBoxWidth  = 0.0;
    $viewBoxHeight = 0.0;
    $viewBoxValue  = $readAttribute('viewBox');

    if ($viewBoxValue !== null && $viewBoxValue !== '') {
        $viewBoxParts = preg_split('/[\s,]+/', $viewBoxValue);

        if (!is_array($viewBoxParts) || count($viewBoxParts) !== 4 || !is_numeric($viewBoxParts[2]) || !is_numeric($viewBoxParts[3])) {
            return ['ok' => false, 'width' => 0.0, 'height' => 0.0, 'error' => 'the viewBox could not be read.'];
        }

        $viewBoxWidth  = abs((float) $viewBoxParts[2]);
        $viewBoxHeight = abs((float) $viewBoxParts[3]);
    }

    // 📏 Pixels per unit (96 px per inch, the CSS and SVG standard).
    $unitPixels = [
        ''   => 1.0,
        'px' => 1.0,
        'pt' => 96.0 / 72.0,
        'pc' => 16.0,
        'mm' => 96.0 / 25.4,
        'cm' => 96.0 / 2.54,
        'in' => 96.0,
        'em' => 16.0,
        'ex' => 8.0,
    ];

    $sizes = [];

    foreach (['width' => $viewBoxWidth, 'height' => $viewBoxHeight] as $dimensionName => $viewBoxSize) {
        $rawValue = $readAttribute($dimensionName);

        if ($rawValue === null || $rawValue === '' || strtolower($rawValue) === 'auto') {
            // No size given: the viewBox size is used (0 if there is none).
            $sizes[$dimensionName] = $viewBoxSize;
            continue;
        }

        if (preg_match('/^([0-9]*\.?[0-9]+(?:e[+-]?[0-9]+)?)\s*(px|pt|pc|mm|cm|in|em|ex|%)?$/i', $rawValue, $lengthMatch) !== 1) {
            return ['ok' => false, 'width' => 0.0, 'height' => 0.0, 'error' => 'the ' . $dimensionName . ' "' . mb_substr($rawValue, 0, 40) . '" could not be read.'];
        }

        $number = (float) $lengthMatch[1];
        $unit   = '';

        if (isset($lengthMatch[2])) {
            $unit = strtolower($lengthMatch[2]);
        }

        if ($unit === '%') {
            $sizes[$dimensionName] = $viewBoxSize * ($number / 100.0);
        } else {
            $sizes[$dimensionName] = $number * $unitPixels[$unit];
        }
    }

    return ['ok' => true, 'width' => $sizes['width'], 'height' => $sizes['height'], 'error' => ''];
}

// ==============================================================================
// 🖼️ validateImageUpload() - Image file upload validation
// ==============================================================================

/**
 * 🖼️ Validate an uploaded image file
 *
 * ⚠️ RESERVED / DORMANT (DS-5, SECURITY.md coverage ledger): this function
 * currently has NO call sites anywhere in the codebase. It is deliberately
 * KEPT (not dead code to delete) because it is the reserved validator for the
 * account-era logo-UPLOAD path (Epic E3, multipart/form-data `$_FILES['logo']`
 * uploads once authenticated accounts exist) — see PROJECT_ROADMAP.md Epic E3.
 * Today's ANONYMOUS Phase-1 logo path does NOT use $_FILES at all: it accepts
 * a base64 data-URI in the JSON customization payload, which is validated and
 * size/dimension-capped by saveBase64LogoToTemp() in fn_qr_logo.php (decoded-
 * byte cap + getimagesize() pixel-dimension guard, F-005). Do NOT wire this
 * function into a route that does not exist yet — when the E3 file-upload
 * route ships, call this function first (before move_uploaded_file()) and
 * apply the SAME decoded-size/pixel-dimension caps used by
 * saveBase64LogoToTemp() for consistency between the two logo-intake paths.
 *
 * Performs comprehensive validation of an uploaded image file:
 *   1. Checks that the file was uploaded via HTTP POST (is_uploaded_file)
 *   2. Checks for upload errors ($_FILES error codes)
 *   3. Validates file size against the maximum allowed
 *   4. Validates MIME type against allowed image types
 *   5. Verifies actual image content using getimagesize()
 *   6. Sanitises the file name for safe storage
 *
 * Returns an associative array with validation results:
 *   - 'valid'          (bool)        True if all checks pass
 *   - 'error'          (string|null) Error message if validation failed
 *   - 'sanitized_name' (string|null) Sanitised file name if valid
 *
 * @param array $file    The $_FILES entry for the uploaded file (e.g., $_FILES['logo'])
 * @param int   $maxSize Maximum allowed file size in bytes (default: 5242880 = 5MB)
 *
 * @return array Validation result ['valid' => bool, 'error' => string|null, 'sanitized_name' => string|null]
 *
 * @see https://www.php.net/manual/en/features.file-upload.php
 * @see https://www.php.net/manual/en/features.file-upload.errors.php
 * @see https://www.php.net/manual/en/function.is-uploaded-file.php
 * @see https://www.php.net/manual/en/function.getimagesize.php
 * @see https://www.php.net/manual/en/function.mime-content-type.php
 * @see https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html
 * @see web/CueRCode.net/_functions/fn_qr_logo.php saveBase64LogoToTemp() — the
 *      active anonymous-era logo intake path this function is the future
 *      account-era counterpart to.
 *
 * @example
 *   $result = validateImageUpload($_FILES['logo'], 2 * 1024 * 1024); // 2MB max
 *
 *   if ($result['valid']) {
 *       move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . $result['sanitized_name']);
 *   } else {
 *       echo 'Upload error: ' . $result['error'];
 *   }
 */
function validateImageUpload(array $file, int $maxSize = 5242880): array
{
    // 📌 Default result structure
    $result = [
        'valid'          => false,
        'error'          => null,
        'sanitized_name' => null,
    ];

    // ==================================================================
    // 📌 Step 1: Check that required $_FILES keys exist
    // The file array must contain: tmp_name, error, size, name, type
    // @see https://www.php.net/manual/en/features.file-upload.post-method.php
    // ==================================================================
    $requiredKeys = ['tmp_name', 'error', 'size', 'name', 'type'];

    foreach ($requiredKeys as $key) {
        if (!array_key_exists($key, $file)) {
            $result['error'] = 'Invalid file upload data: missing key "' . $key . '".';
            return $result;
        }
    }

    // ==================================================================
    // 📌 Step 2: Check for PHP upload errors
    // @see https://www.php.net/manual/en/features.file-upload.errors.php
    // ==================================================================
    if ($file['error'] !== UPLOAD_ERR_OK) {
        // 📋 Map PHP upload error codes to human-readable messages
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds the server\'s maximum upload size (upload_max_filesize in php.ini).',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form\'s maximum upload size (MAX_FILE_SIZE directive).',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded. Please select a file.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error: temporary upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server error: failed to write uploaded file to disk.',
            UPLOAD_ERR_EXTENSION  => 'File upload was blocked by a PHP extension.',
        ];

        if (isset($uploadErrors[$file['error']])) {
            $result['error'] = $uploadErrors[$file['error']];
        } else {
            $result['error'] = 'Unknown upload error (code: ' . $file['error'] . ').';
        }
        return $result;
    }

    // ==================================================================
    // 📌 Step 3: Verify the file was uploaded via HTTP POST
    // This prevents manipulation of the tmp_name to point to
    // arbitrary server files.
    // @see https://www.php.net/manual/en/function.is-uploaded-file.php
    // ==================================================================
    if (!is_uploaded_file($file['tmp_name'])) {
        $result['error'] = 'File validation failed: not a valid HTTP upload.';
        return $result;
    }

    // ==================================================================
    // 📌 Step 4: Validate file size
    // Check both the reported size and the actual file size on disk.
    // @see https://www.php.net/manual/en/function.filesize.php
    // ==================================================================
    $actualSize = filesize($file['tmp_name']);

    if ($actualSize === false || $actualSize > $maxSize || $file['size'] > $maxSize) {
        // 📌 Format the max size for a human-readable error message
        $maxSizeMB = number_format($maxSize / (1024 * 1024), 1);
        $result['error'] = 'File is too large. Maximum allowed size is ' . $maxSizeMB . ' MB.';
        return $result;
    }

    // ==================================================================
    // 📌 Step 5: Validate MIME type
    // Check both the client-reported type and the server-detected type.
    // The client type can be spoofed, so we also use mime_content_type()
    // or finfo for server-side verification.
    // @see https://www.php.net/manual/en/function.mime-content-type.php
    // @see https://www.php.net/manual/en/class.finfo.php
    // ==================================================================

    // 📋 Allowed image MIME types
    $allowedMimeTypes = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'image/x-icon',
        'image/vnd.microsoft.icon',
        'image/bmp',
    ];

    // 🔍 Detect the actual MIME type from file content (not the client-reported type)
    $detectedMime = '';

    if (function_exists('mime_content_type')) {
        // @see https://www.php.net/manual/en/function.mime-content-type.php
        $detectedMime = mime_content_type($file['tmp_name']);
    } elseif (class_exists('finfo')) {
        // @see https://www.php.net/manual/en/class.finfo.php
        $finfo        = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file['tmp_name']);
    }

    // 📌 Normalise the detected MIME type
    $detectedMime = strtolower(trim($detectedMime));

    if (!in_array($detectedMime, $allowedMimeTypes, true)) {
        $result['error'] = 'File type not allowed. Accepted types: PNG, JPEG, GIF, WebP, SVG, ICO, BMP. '
            . 'Detected type: ' . htmlspecialchars($detectedMime, ENT_QUOTES, 'UTF-8') . '.';
        return $result;
    }

    // ==================================================================
    // 📌 Step 6: Verify actual image content (except SVG)
    // Use getimagesize() to confirm the file contains valid image data.
    // SVG files are XML-based and not supported by getimagesize().
    // @see https://www.php.net/manual/en/function.getimagesize.php
    // ==================================================================
    if ($detectedMime !== 'image/svg+xml') {
        $imageInfo = @getimagesize($file['tmp_name']);

        if ($imageInfo === false) {
            $result['error'] = 'File does not contain valid image data. '
                . 'The file may be corrupted or not a genuine image.';
            return $result;
        }

        // 📌 Verify the getimagesize() MIME matches what we detected
        if (isset($imageInfo['mime']) && !in_array($imageInfo['mime'], $allowedMimeTypes, true)) {
            $result['error'] = 'Image content type mismatch. Expected an allowed image type but found: '
                . htmlspecialchars($imageInfo['mime'], ENT_QUOTES, 'UTF-8') . '.';
            return $result;
        }

        // 🛡️ F-005: enforce a pixel-dimension cap to block decompression /
        //    "pixel bomb" images. getimagesize() already read the header above,
        //    so we reuse $imageInfo[0]/[1] (width/height) — no extra I/O. A file
        //    declaring dimensions beyond the cap is rejected BEFORE any caller
        //    hands it to GD (which would allocate ~4 bytes per pixel).
        // @see https://www.php.net/manual/en/function.getimagesize.php
        // @see CUERCODE_UPLOAD_MAX_WIDTH / CUERCODE_UPLOAD_MAX_HEIGHT
        if (isset($imageInfo[0])) {
            $imageWidth = (int) $imageInfo[0];
        } else {
            $imageWidth = 0;
        }
        if (isset($imageInfo[1])) {
            $imageHeight = (int) $imageInfo[1];
        } else {
            $imageHeight = 0;
        }

        if ($imageWidth > CUERCODE_UPLOAD_MAX_WIDTH
            || $imageHeight > CUERCODE_UPLOAD_MAX_HEIGHT
        ) {
            $result['error'] = 'Image dimensions are too large. Maximum allowed is '
                . CUERCODE_UPLOAD_MAX_WIDTH . ' x ' . CUERCODE_UPLOAD_MAX_HEIGHT
                . ' pixels. This image is '
                . $imageWidth . ' x ' . $imageHeight . ' pixels.';
            return $result;
        }
    } else {
        // 🔒 SVG-specific validation: check for potentially dangerous content
        // SVGs can contain embedded scripts, event handlers, and external references.
        // 🛡️ F-024 (#52): the actual pattern set now lives in the SHARED
        //    screenSvgLogoContent() helper (defined earlier in this file) so
        //    this multipart upload path and the JSON API's base64 logo_data
        //    path (fn_qr_logo.php - saveBase64LogoToTemp()) enforce IDENTICAL
        //    screening instead of the base64 path having none at all.
        // @see screenSvgLogoContent() - the shared screener
        // @see https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html
        $svgContent = file_get_contents($file['tmp_name']);

        if ($svgContent !== false) {
            $svgScreen = screenSvgLogoContent($svgContent);

            if ($svgScreen['valid'] === false) {
                $result['error'] = 'SVG file contains potentially unsafe content: '
                    . $svgScreen['error']
                    . ' Please upload a clean SVG file.';
                return $result;
            }
        }
    }

    // ==================================================================
    // 📌 Step 7: Sanitise the file name
    // @see sanitizeFileName() in this file
    // ==================================================================
    $sanitisedName = sanitizeFileName($file['name']);

    // ✅ All checks passed
    $result['valid']          = true;
    $result['sanitized_name'] = $sanitisedName;

    return $result;
}
