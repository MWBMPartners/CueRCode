<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Characterization Tests for CORE FUNCTIONS (DB-independent)
 * ==============================================================================
 *
 * Path: tests/test_core.php
 *
 * Captures the CURRENT behaviour of the DB-independent core functions so a
 * later refactor / PHP-version bump cannot silently change them. This is a
 * SAFETY NET cycle only: where the current behaviour disagrees with a
 * function's own docblock (a likely bug), we still assert the CURRENT output
 * and flag it in an inline 🐛 comment for a future fix cycle. NO production
 * code under web/CueRCode.net/ is modified.
 *
 * Units covered (all DB-independent):
 *   - fn_encryption.php : encryptValue/decryptValue round-trip, randomised IV,
 *                         tamper rejection, hashAPIKey, generateAPIKey,
 *                         getAPIKeyPrefix.
 *   - fn_csrf.php       : generateCSRFToken + validateCSRFToken (run in a
 *                         SUBPROCESS so a real PHP session can start cleanly
 *                         before any output — the in-process runner has already
 *                         emitted its banner, which would break session_start).
 *   - fn_validation.php : validateEmail / validateURL / validateColor /
 *                         validatePhone / validateJSON / sanitizeInput /
 *                         sanitizeFileName accept + reject cases.
 *   - fn_qr_options.php : mapOutputFormat (each format + unknown->png fallback),
 *                         mapEccLevel (L/M/Q/H + unknown->M), hexToRgb,
 *                         rgbToHex, calculateScale clamping. Needs the QR
 *                         library autoloader (no DB) for the EccLevel / output
 *                         class constants.
 *   - fn_response.php   : determineResponseFormat (pure) and arrayToXML (pure)
 *                         in-process; jsonResponse / xmlResponse SHAPE captured
 *                         in a SUBPROCESS (they call exit, which would terminate
 *                         the in-process runner).
 *
 * ⚠️ SKIPPED (DB- or environment-dependent — out of scope this cycle):
 *   - fn_config.php  : getConfig / setConfig / getConfigGroup / getAllConfig
 *                      (query tblSettings) — DB-dependent.
 *   - fn_activity_log.php : logActivity / getRecentActivity — DB-dependent.
 *   - getCSRFTokenLifetime(): only DB-dependent IF getConfig() is loaded; the
 *                      harness does NOT load fn_config.php, so it returns the
 *                      CUERCODE_CSRF_DEFAULT_LIFETIME constant (3600). We assert
 *                      that fallback path only.
 *   - validateImageUpload(): inspects a real $_FILES upload + filesystem
 *                      (is_uploaded_file/getimagesize) — environment-dependent.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 1 (test safety-net cycle)
 * @see         tests/bootstrap.php  - test-only constants + requireFn()
 * @see         tests/lib/assert.php - the assert* functions used below
 * @see         https://www.php.net/manual/en/function.proc-open.php
 */

// ==============================================================================
// 🧰 Load the DB-independent production function files (NO database connection)
// ==============================================================================
// requireFn() (from bootstrap.php) includes a single web/CueRCode.net/_functions
// fn_ file WITHOUT pulling in db_connect.php. Including a fn_ file only registers
// its functions; nothing connects to a database at include time.
// @see tests/bootstrap.php - requireFn()
// ==============================================================================

requireFn('fn_encryption.php');
requireFn('fn_validation.php');
requireFn('fn_response.php');

// 📚 fn_qr_options.php references chillerlan\QRCode library constants
//    (EccLevel::L, QRGdImagePNG::class, etc.). Load the autoloader first — it
//    opens NO database connection, it only registers the class autoloader.
// @see tests/bootstrap.php - requireLibraryAutoloader()
$qrLibraryLoaded = requireLibraryAutoloader();

if ($qrLibraryLoaded) {
    requireFn('fn_qr_options.php');
}

// ==============================================================================
// 🛰️ runPhpSnippet() - Execute a PHP snippet in a clean SUBPROCESS, capture stdout
// ==============================================================================

/**
 * 🛰️ Run a self-contained PHP snippet in a fresh child process and return stdout.
 *
 * Some production functions cannot be exercised in-process by the main runner:
 *   1. fn_csrf.php calls session_start(), which fails ("headers already sent")
 *      because run.php has already echoed its banner before this test file runs.
 *   2. jsonResponse()/xmlResponse() call exit, which would terminate the runner.
 *
 * Running the snippet in a child PHP process side-steps both: the child starts
 * with no prior output (so session_start works) and its exit only ends the
 * child. We capture the child's STDOUT and assert on it back in the parent.
 *
 * The snippet is wrapped so it has access to the SAME test bootstrap (test-only
 * ENCRYPTION_* constants, requireFn(), requireLibraryAutoloader()).
 *
 * @param string $snippet PHP code to run (WITHOUT a leading "<?php" tag). The
 *                        bootstrap is already required before this code runs.
 *
 * @return string The child process's STDOUT (stderr is discarded).
 *
 * @see https://www.php.net/manual/en/function.proc-open.php
 * @see https://www.php.net/manual/en/reserved.constants.php (PHP_BINARY)
 */
function runPhpSnippet(string $snippet): string
{
    // 📌 Absolute path to this harness's bootstrap so the child shares constants.
    $bootstrapPath = CUERCODE_TEST_REPO_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'bootstrap.php';

    // 📦 Compose a full PHP program: require the bootstrap, then the snippet.
    //    error_reporting is lowered to silence benign session warnings the child
    //    might emit on some SAPIs; we only care about the captured stdout shape.
    $program = '<?php' . PHP_EOL
        . 'error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);' . PHP_EOL
        . 'require ' . var_export($bootstrapPath, true) . ';' . PHP_EOL
        . $snippet . PHP_EOL;

    // 💾 Write the program to a unique temp file.
    // @see https://www.php.net/manual/en/function.tempnam.php
    $tmpFile = tempnam(sys_get_temp_dir(), 'cuercode_test_') . '.php';
    file_put_contents($tmpFile, $program);

    // ▶️ Spawn the child PHP process using the SAME interpreter as the runner.
    // @see https://www.php.net/manual/en/function.proc-open.php
    $descriptors = [
        0 => ['pipe', 'r'],   // child STDIN  (unused)
        1 => ['pipe', 'w'],   // child STDOUT (captured)
        2 => ['pipe', 'w'],   // child STDERR (discarded)
    ];

    $stdout  = '';
    $command = [PHP_BINARY, $tmpFile];
    $process = proc_open($command, $descriptors, $pipes);

    if (is_resource($process)) {
        // 🚪 Close STDIN immediately (we send nothing).
        fclose($pipes[0]);

        // 📥 Read all of STDOUT then STDERR (drain to avoid deadlock).
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        // 🗑️ Drain and discard STDERR.
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        proc_close($process);
    }

    // 🧹 Remove the temp program file.
    @unlink($tmpFile);

    if ($stdout === false) {
        return '';
    }

    return $stdout;
}

// ==============================================================================
// 🔐 fn_encryption.php — encrypt/decrypt round-trip + tamper rejection
// ==============================================================================
// Uses the test-only ENCRYPTION_KEY / ENCRYPTION_SALT constants from
// bootstrap.php so the round-trip is deterministic and self-contained.
// @see web/CueRCode.net/_functions/fn_encryption.php
// ==============================================================================

// 🔒 Round-trip: encrypting then decrypting returns the original plaintext.
$plaintext  = 'my-secret-api-key-🔑';
$ciphertext = encryptValue($plaintext);
$decrypted  = decryptValue($ciphertext);
assertEquals(
    $plaintext,
    $decrypted,
    'encryptValue -> decryptValue round-trips the original plaintext (incl. UTF-8)'
);

// 🔒 The ciphertext is a non-empty base64 string (storable in a TEXT column).
assertTrue(
    is_string($ciphertext) && $ciphertext !== '',
    'encryptValue returns a non-empty string'
);
assertEquals(
    $ciphertext,
    base64_encode((string) base64_decode($ciphertext, true)),
    'encryptValue output is valid base64'
);

// 🎲 A random IV means the same plaintext produces DIFFERENT ciphertext each call.
assertTrue(
    encryptValue('same-input') !== encryptValue('same-input'),
    'encryptValue uses a random IV (same plaintext -> different ciphertext)'
);

// 🔒 Explicit key/salt args are honoured and round-trip independently of defaults.
$explicitKey  = str_repeat('K', 32);
$explicitSalt = str_repeat('S', 32);
$ctExplicit   = encryptValue('explicit-args', $explicitKey, $explicitSalt);
assertEquals(
    'explicit-args',
    decryptValue($ctExplicit, $explicitKey, $explicitSalt),
    'encrypt/decrypt round-trips with explicit key + salt arguments'
);

// 🛡️ Decrypting with the WRONG key throws (GCM auth tag fails to verify).
assertThrows(
    static function () use ($ctExplicit): void {
        decryptValue($ctExplicit, str_repeat('X', 32), str_repeat('S', 32));
    },
    'decryptValue with the wrong key throws (auth tag mismatch)',
    \RuntimeException::class
);

// 🛡️ Tampering with the ciphertext (flip the last byte) is rejected by the GCM tag.
$packed = base64_decode($ciphertext, true);
$lastIndex = strlen($packed) - 1;
$packed[$lastIndex] = chr(ord($packed[$lastIndex]) ^ 0xFF);
$tampered = base64_encode($packed);
assertThrows(
    static function () use ($tampered): void {
        decryptValue($tampered);
    },
    'decryptValue rejects tampered ciphertext/tag (throws RuntimeException)',
    \RuntimeException::class
);

// 🛡️ Tampering with the auth tag region (flip a byte inside the 16-byte tag) is rejected.
$packed2 = base64_decode($ciphertext, true);
// IV is 12 bytes (aes-256-gcm), tag is the next 16 bytes; index 12 is the first tag byte.
$packed2[12] = chr(ord($packed2[12]) ^ 0xFF);
$tamperedTag = base64_encode($packed2);
assertThrows(
    static function () use ($tamperedTag): void {
        decryptValue($tamperedTag);
    },
    'decryptValue rejects a tampered GCM auth tag (throws RuntimeException)',
    \RuntimeException::class
);

// 🛡️ Too-short input (below IV + tag + 1) throws "too short".
assertThrows(
    static function (): void {
        decryptValue(base64_encode('short'));
    },
    'decryptValue rejects data shorter than IV+tag+1 (throws RuntimeException)',
    \RuntimeException::class
);

// 🛡️ Invalid base64 input throws.
assertThrows(
    static function (): void {
        decryptValue('!!!not-valid-base64!!!');
    },
    'decryptValue rejects invalid base64 (throws RuntimeException)',
    \RuntimeException::class
);

// ==============================================================================
// 🔑 fn_encryption.php — API key helpers
// ==============================================================================

// 🔐 hashAPIKey produces a 64-char hex string (SHA-256 HMAC) and is deterministic.
$apiKey  = 'cuercode_0123456789abcdef';
$hash1   = hashAPIKey($apiKey);
$hash2   = hashAPIKey($apiKey);
assertEquals(
    64,
    strlen($hash1),
    'hashAPIKey returns a 64-character string (SHA-256 hex)'
);
assertMatches(
    '/^[0-9a-f]{64}$/',
    $hash1,
    'hashAPIKey output is lowercase hex'
);
assertEquals(
    $hash1,
    $hash2,
    'hashAPIKey is deterministic for the same input + key'
);
assertTrue(
    hashAPIKey('cuercode_AAA') !== hashAPIKey('cuercode_BBB'),
    'hashAPIKey produces different hashes for different keys'
);

// 🎲 generateAPIKey: 'cuercode_' prefix + 40 hex chars = 49 chars total.
$generated = generateAPIKey();
assertEquals(
    49,
    strlen($generated),
    'generateAPIKey returns 49 characters (cuercode_ + 40 hex)'
);
assertMatches(
    '/^cuercode_[0-9a-f]{40}$/',
    $generated,
    'generateAPIKey format: cuercode_ prefix followed by 40 hex chars'
);
assertTrue(
    generateAPIKey() !== generateAPIKey(),
    'generateAPIKey produces unique keys on each call'
);

// 🔍 getAPIKeyPrefix returns the first 12 characters.
assertEquals(
    'cuercode_abc',
    getAPIKeyPrefix('cuercode_abcdefghijklmnop'),
    'getAPIKeyPrefix returns the first 12 characters of the key'
);

// ==============================================================================
// 🛡️ fn_csrf.php — token generate + validate (SUBPROCESS for a clean session)
// ==============================================================================
// validateCSRFToken/generateCSRFToken need a started PHP session. The in-process
// runner has already produced output (its banner), so session_start() would warn
// and behave oddly here. We therefore run the whole CSRF exchange in a child PHP
// process that starts with no prior output, and assert on its JSON-encoded result.
// @see web/CueRCode.net/_functions/fn_csrf.php
// ==============================================================================

$csrfSnippet = <<<'PHP'
session_start();
requireFn('fn_csrf.php');

$token       = generateCSRFToken();
$validRight  = validateCSRFToken($token);
$validWrong  = validateCSRFToken('0000000000000000000000000000000000000000000000000000000000000000');
$validEmpty  = validateCSRFToken('');
$secondToken = generateCSRFToken();

echo json_encode([
    'tokenLen'    => strlen($token),
    'tokenIsHex'  => (preg_match('/^[0-9a-f]{64}$/', $token) === 1),
    'validRight'  => $validRight,
    'validWrong'  => $validWrong,
    'validEmpty'  => $validEmpty,
    'idempotent'  => ($secondToken === $token),
    'lifetimeNoDb'=> getCSRFTokenLifetime(),
]);
PHP;

$csrfRaw  = runPhpSnippet($csrfSnippet);
$csrfData = json_decode($csrfRaw, true);

assertTrue(
    is_array($csrfData),
    'CSRF subprocess returned decodable JSON (sanity check on the subprocess)'
);

if (is_array($csrfData)) {
    // 🔑 generateCSRFToken produces a 64-char hex token (32 random bytes).
    assertEquals(
        64,
        $csrfData['tokenLen'] ?? -1,
        'generateCSRFToken returns a 64-character token'
    );
    assertTrue(
        $csrfData['tokenIsHex'] ?? false,
        'generateCSRFToken token is 64 lowercase hex characters'
    );

    // ✅ A token validates against itself.
    assertTrue(
        $csrfData['validRight'] ?? false,
        'validateCSRFToken accepts the token it just generated'
    );

    // ❌ A wrong token is rejected.
    assertFalse(
        $csrfData['validWrong'] ?? true,
        'validateCSRFToken rejects a wrong token'
    );

    // ❌ An empty token is rejected.
    assertFalse(
        $csrfData['validEmpty'] ?? true,
        'validateCSRFToken rejects an empty token'
    );

    // 🔁 generateCSRFToken returns the SAME token within its lifetime (not rotated).
    assertTrue(
        $csrfData['idempotent'] ?? false,
        'generateCSRFToken returns the existing valid token on a second call (no rotation)'
    );

    // ⏱️ Without fn_config.php loaded, getCSRFTokenLifetime falls back to the
    //    CUERCODE_CSRF_DEFAULT_LIFETIME constant (3600). This is the DB-free path.
    assertEquals(
        3600,
        $csrfData['lifetimeNoDb'] ?? -1,
        'getCSRFTokenLifetime falls back to 3600 when getConfig() (DB) is unavailable'
    );
}

// ==============================================================================
// ✅ fn_validation.php — accept + reject cases for the representative validators
// ==============================================================================
// @see web/CueRCode.net/_functions/fn_validation.php
// ==============================================================================

// 📧 validateEmail
assertTrue(validateEmail('user@example.com'), 'validateEmail accepts a well-formed address');
assertTrue(validateEmail('  user@example.com  '), 'validateEmail trims surrounding whitespace before validating');
assertFalse(validateEmail('not-an-email'), 'validateEmail rejects a string with no @');
assertFalse(validateEmail('user@.com'), 'validateEmail rejects a malformed domain');
assertFalse(validateEmail(''), 'validateEmail rejects an empty string');
// 📏 Over the 254-char RFC 5321 path limit is rejected.
$longLocal = str_repeat('a', 250) . '@example.com';
assertFalse(validateEmail($longLocal), 'validateEmail rejects an address longer than 254 characters');

// 🌐 validateURL
assertTrue(validateURL('https://example.com/path?query=1'), 'validateURL accepts an https URL');
assertTrue(validateURL('http://example.com'), 'validateURL accepts an http URL');
assertFalse(validateURL('ftp://files.example.com'), 'validateURL rejects an ftp scheme');
assertFalse(validateURL('javascript:alert(1)'), 'validateURL rejects a javascript: scheme');
assertFalse(validateURL('not-a-url'), 'validateURL rejects a non-URL string');
assertFalse(validateURL(''), 'validateURL rejects an empty string');
// 📏 Over the 2048-char practical limit is rejected.
$longUrl = 'https://example.com/' . str_repeat('a', 2050);
assertFalse(validateURL($longUrl), 'validateURL rejects a URL longer than 2048 characters');

// 🎨 validateColor (hex)
assertTrue(validateColor('#ff0000'), 'validateColor accepts a 6-digit hex colour');
assertTrue(validateColor('#fff'), 'validateColor accepts a 3-digit shorthand hex colour');
assertTrue(validateColor('#ff000080'), 'validateColor accepts an 8-digit hex colour with alpha');
assertTrue(validateColor('#ABC'), 'validateColor is case-insensitive (uppercase hex accepted)');
assertFalse(validateColor('ff0000'), 'validateColor rejects a hex colour missing the # prefix');
assertFalse(validateColor('#xyz'), 'validateColor rejects invalid hex characters');
assertFalse(validateColor('#ff00'), 'validateColor rejects a 4-digit (non 3/6/8) hex length');
assertFalse(validateColor(''), 'validateColor rejects an empty string');

// 📞 validatePhone (size bounds: min 7, max 15 digits)
assertTrue(validatePhone('+44 20 1234 5678'), 'validatePhone accepts an international number with spaces');
assertTrue(validatePhone('(555) 123-4567'), 'validatePhone accepts a number with parentheses and a hyphen');
assertTrue(validatePhone('1234567'), 'validatePhone accepts exactly 7 digits (lower bound)');
assertTrue(validatePhone('123456789012345'), 'validatePhone accepts exactly 15 digits (upper bound)');
assertFalse(validatePhone('123'), 'validatePhone rejects fewer than 7 digits');
assertFalse(validatePhone('1234567890123456'), 'validatePhone rejects more than 15 digits');
assertFalse(validatePhone('abc-defg'), 'validatePhone rejects letters');
assertFalse(validatePhone(''), 'validatePhone rejects an empty string');

// 📋 validateJSON
assertTrue(validateJSON('{"key":"value"}'), 'validateJSON accepts a JSON object');
assertTrue(validateJSON('[1,2,3]'), 'validateJSON accepts a JSON array');
assertFalse(validateJSON(''), 'validateJSON rejects an empty string');
assertFalse(validateJSON('{invalid}'), 'validateJSON rejects malformed JSON');
// ✅ FIX #3 (2026-06-28): validateJSON now requires a JSON *structure* (object or
//    array) and rejects bare scalars, matching its docblock. Previously these
//    well-formed-but-scalar values all returned true (json_decode succeeded with
//    JSON_ERROR_NONE). The function has no external callers, so tightening to the
//    documented object/array contract was the lowest-risk fix.
assertFalse(validateJSON('null'), 'validateJSON rejects the bare scalar literal "null" (FIX #3: structure-only contract)');
assertFalse(validateJSON('true'), 'validateJSON rejects the bare scalar literal "true" (FIX #3)');
assertFalse(validateJSON('123'), 'validateJSON rejects a bare JSON number (FIX #3)');
assertFalse(validateJSON('"a string"'), 'validateJSON rejects a bare JSON string (FIX #3)');

// 🧹 sanitizeInput
assertEquals('Hi', sanitizeInput('  Hi  '), 'sanitizeInput trims surrounding whitespace');
// ✅ FIX #5 (2026-06-28): behaviour is correct and UNCHANGED — strip_tags() only
//    removes the TAGS, leaving the inner text, which htmlspecialchars() then
//    entity-encodes (quotes via ENT_QUOTES) so the result is inert in HTML. The
//    docblock previously claimed the output was 'Hello' (implying script removal);
//    it has been corrected to the real output below and to note this is NOT
//    tag/script *removal*. This assertion already matched reality, so it stands.
assertEquals(
    'alert(&quot;XSS&quot;)Hello',
    sanitizeInput('<script>alert("XSS")</script>Hello'),
    'sanitizeInput strips tags but KEEPS inner text and entity-encodes quotes (FIX #5: docblock corrected to match)'
);
// 📌 Input '&lt;b&gt;' contains NO literal angle brackets (they are already
//    entities), so strip_tags removes nothing and htmlspecialchars re-encodes
//    the ampersands: '&' -> '&amp;'. CURRENT output is double-encoded.
assertEquals(
    '&amp;lt;b&amp;gt;',
    sanitizeInput('&lt;b&gt;'),
    'sanitizeInput double-encodes ampersands of already-encoded entities (&lt; -> &amp;lt;)'
);

// 📄 sanitizeFileName
assertEquals('my-photo-2.jpg', sanitizeFileName('My Photo (2).JPG'), 'sanitizeFileName lowercases, hyphenates spaces, drops parens');
assertEquals('passwd', sanitizeFileName('../../../etc/passwd'), 'sanitizeFileName strips path traversal components');
// ✅ FIX #4 (2026-06-28): the extension is now trimmed. Previously pathinfo()
//    kept the input's trailing two spaces as part of the extension ('PNG  ' ->
//    'png  '), so the output leaked them as 'hello-world.png  '. The corrected
//    output matches the docblock example: 'hello-world.png' (no trailing spaces).
assertEquals('hello-world.png', sanitizeFileName('  hello   world.PNG  '), 'sanitizeFileName trims the extension (FIX #4: trailing input spaces no longer leak into it)');
// ✅ FIX #4 guard: an extension that is ONLY whitespace collapses to no extension,
//    so we never emit a trailing-dot name like 'data.'.
assertEquals('data', sanitizeFileName('data.   '), 'sanitizeFileName treats an all-whitespace extension as no extension (FIX #4 guard)');
assertEquals('unnamed', sanitizeFileName('***'), 'sanitizeFileName falls back to "unnamed" when nothing safe remains');

// ==============================================================================
// ⚙️ fn_qr_options.php — pure mapping helpers (needs QR library autoloader, no DB)
// ==============================================================================
// @see web/CueRCode.net/_functions/fn_qr_options.php
// ==============================================================================

if (!$qrLibraryLoaded) {
    // ⚠️ The QR library autoloader could not be found; record a single visible
    //    FAIL so the absence is not silently mistaken for "all green". This keeps
    //    the safety net honest about coverage.
    assertTrue(
        false,
        'QR library autoloader not found at _libraries/autoload.php — fn_qr_options tests SKIPPED'
    );
} else {
    // 📋 mapOutputFormat: each supported format maps to the expected class + MIME + ext.
    $pngMap = mapOutputFormat('png');
    assertEquals('chillerlan\\QRCode\\Output\\QRGdImagePNG', $pngMap['outputInterface'], 'mapOutputFormat(png) -> QRGdImagePNG output interface');
    assertEquals('image/png', $pngMap['mimeType'], 'mapOutputFormat(png) -> image/png MIME type');
    assertEquals('png', $pngMap['fileExtension'], 'mapOutputFormat(png) -> png file extension');

    $jpgMap = mapOutputFormat('jpg');
    assertEquals('image/jpeg', $jpgMap['mimeType'], 'mapOutputFormat(jpg) -> image/jpeg MIME type');
    assertEquals('jpg', $jpgMap['fileExtension'], 'mapOutputFormat(jpg) -> jpg file extension');

    // 📷 'jpeg' is an alias for 'jpg' and shares the jpg extension.
    $jpegMap = mapOutputFormat('jpeg');
    assertEquals('image/jpeg', $jpegMap['mimeType'], 'mapOutputFormat(jpeg) -> image/jpeg MIME type');
    assertEquals('jpg', $jpegMap['fileExtension'], 'mapOutputFormat(jpeg alias) -> jpg file extension');

    $gifMap = mapOutputFormat('gif');
    assertEquals('image/gif', $gifMap['mimeType'], 'mapOutputFormat(gif) -> image/gif MIME type');

    $webpMap = mapOutputFormat('webp');
    assertEquals('image/webp', $webpMap['mimeType'], 'mapOutputFormat(webp) -> image/webp MIME type');

    $svgMap = mapOutputFormat('svg');
    assertEquals('chillerlan\\QRCode\\Output\\QRMarkupSVG', $svgMap['outputInterface'], 'mapOutputFormat(svg) -> QRMarkupSVG output interface');
    assertEquals('image/svg+xml', $svgMap['mimeType'], 'mapOutputFormat(svg) -> image/svg+xml MIME type');
    assertEquals(null, $svgMap['outputType'], 'mapOutputFormat(svg) -> null outputType (markup, not GD)');

    $pdfMap = mapOutputFormat('pdf');
    assertEquals('application/pdf', $pdfMap['mimeType'], 'mapOutputFormat(pdf) -> application/pdf MIME type');
    assertEquals('pdf', $pdfMap['fileExtension'], 'mapOutputFormat(pdf) -> pdf file extension');

    // ⚠️ Unknown format falls back to PNG (same mapping as 'png').
    $unknownMap = mapOutputFormat('totally-made-up-format');
    assertEquals($pngMap, $unknownMap, 'mapOutputFormat(unknown) falls back to the PNG mapping');

    // 📐 mapEccLevel: each level maps to the library EccLevel constant.
    //    NOTE: the library's EccLevel constants are NOT 0,1,2,3 in L,M,Q,H order —
    //    the actual values are L=1, M=0, Q=3, H=2. We assert against the constants
    //    themselves (robust to library internals) AND lock in the numeric values
    //    so a future library upgrade that changes them is caught.
    assertEquals(\chillerlan\QRCode\Common\EccLevel::L, mapEccLevel('L'), 'mapEccLevel(L) -> EccLevel::L constant');
    assertEquals(\chillerlan\QRCode\Common\EccLevel::M, mapEccLevel('M'), 'mapEccLevel(M) -> EccLevel::M constant');
    assertEquals(\chillerlan\QRCode\Common\EccLevel::Q, mapEccLevel('Q'), 'mapEccLevel(Q) -> EccLevel::Q constant');
    assertEquals(\chillerlan\QRCode\Common\EccLevel::H, mapEccLevel('H'), 'mapEccLevel(H) -> EccLevel::H constant');
    assertEquals(\chillerlan\QRCode\Common\EccLevel::M, mapEccLevel('ZZ'), 'mapEccLevel(unknown) falls back to EccLevel::M');
    assertEquals(\chillerlan\QRCode\Common\EccLevel::H, mapEccLevel('  h  '), 'mapEccLevel trims + uppercases its input (h -> H)');

    // 🔢 Lock in the CURRENT numeric EccLevel constant values (library-defined).
    assertEquals(1, mapEccLevel('L'), 'mapEccLevel(L) CURRENT numeric value is 1 (library constant)');
    assertEquals(0, mapEccLevel('M'), 'mapEccLevel(M) CURRENT numeric value is 0 (library constant)');
    assertEquals(3, mapEccLevel('Q'), 'mapEccLevel(Q) CURRENT numeric value is 3 (library constant)');
    assertEquals(2, mapEccLevel('H'), 'mapEccLevel(H) CURRENT numeric value is 2 (library constant)');

    // 🎨 hexToRgb
    assertEquals([255, 0, 0], hexToRgb('#ff0000'), 'hexToRgb(#ff0000) -> [255, 0, 0]');
    assertEquals([255, 255, 255], hexToRgb('#fff'), 'hexToRgb(#fff) expands shorthand -> [255, 255, 255]');
    assertEquals([0, 255, 0], hexToRgb('00ff00'), 'hexToRgb works without the # prefix');
    assertEquals([255, 0, 0], hexToRgb('#ff0000aa'), 'hexToRgb strips the 8-digit alpha channel');
    assertEquals([0, 0, 0], hexToRgb('not-a-colour'), 'hexToRgb returns black [0,0,0] for invalid input');

    // 🎨 rgbToHex
    assertEquals('#ff0000', rgbToHex(255, 0, 0), 'rgbToHex(255,0,0) -> #ff0000');
    assertEquals('#0080ff', rgbToHex(0, 128, 255), 'rgbToHex(0,128,255) -> #0080ff');
    assertEquals('#ffffff', rgbToHex(999, 999, 999), 'rgbToHex clamps over-range values to 255 -> #ffffff');
    assertEquals('#000000', rgbToHex(-10, -10, -10), 'rgbToHex clamps negative values to 0 -> #000000');

    // 📏 calculateScale: result is clamped between 1 and 50.
    $scaleBig   = calculateScale(800, mapEccLevel('M'), 50);
    assertTrue($scaleBig >= 1 && $scaleBig <= 50, 'calculateScale stays within the clamped [1,50] range for a typical request');
    $scaleHuge  = calculateScale(8000, mapEccLevel('L'), 1);
    assertEquals(50, $scaleHuge, 'calculateScale clamps a very large target size to the maximum scale of 50');
    $scaleTiny  = calculateScale(10, mapEccLevel('H'), 2000);
    assertEquals(1, $scaleTiny, 'calculateScale clamps a tiny target / large payload to the minimum scale of 1');

    // 📐 estimateModuleCount: ECC-level -> capacity-row mapping (FIX #6).
    // ✅ FIX #6 (2026-06-28): the capacity table was keyed 0=L,1=M,2=Q,3=H and
    //    indexed with the RAW EccLevel constant. But chillerlan's constants are
    //    L=1, M=0, Q=3, H=2, so every level selected the WRONG capacity row
    //    (e.g. ecc=L picked the M row), and the result was non-monotonic in ECC.
    //    estimateModuleCount() now maps the constant to its logical L/M/Q/H row.
    //
    //    Affects scale ESTIMATION only (used by calculateScale + logo-space sizing),
    //    NOT the generated QR matrix.
    //
    //    At dataLength = 60 each ECC level falls on a DISTINCT version, giving the
    //    clean, strictly-increasing sequence below (higher ECC needs more modules
    //    for the same data — never fewer):
    //      L -> v3 -> 4*3+17 = 29   (was buggily 33, the v4/M row)
    //      M -> v4 -> 4*4+17 = 33   (was buggily 29, the v3/L row)
    //      Q -> v5 -> 4*5+17 = 37   (was buggily 41, the v6/H row)
    //      H -> v6 -> 4*6+17 = 41   (was buggily 37, the v5/Q row)
    assertEquals(29, estimateModuleCount(mapEccLevel('L'), 60), 'estimateModuleCount(L, 60) -> 29 modules (v3) — FIX #6 correct capacity row');
    assertEquals(33, estimateModuleCount(mapEccLevel('M'), 60), 'estimateModuleCount(M, 60) -> 33 modules (v4) — FIX #6 correct capacity row');
    assertEquals(37, estimateModuleCount(mapEccLevel('Q'), 60), 'estimateModuleCount(Q, 60) -> 37 modules (v5) — FIX #6 correct capacity row');
    assertEquals(41, estimateModuleCount(mapEccLevel('H'), 60), 'estimateModuleCount(H, 60) -> 41 modules (v6) — FIX #6 correct capacity row');

    // 📈 Monotonicity guard: for fixed data, module count is non-decreasing as ECC
    //    rises (L <= M <= Q <= H). The old bug VIOLATED this (it gave M < L, H < Q).
    $emL = estimateModuleCount(mapEccLevel('L'), 100);
    $emM = estimateModuleCount(mapEccLevel('M'), 100);
    $emQ = estimateModuleCount(mapEccLevel('Q'), 100);
    $emH = estimateModuleCount(mapEccLevel('H'), 100);
    assertTrue($emL <= $emM && $emM <= $emQ && $emQ <= $emH, 'estimateModuleCount is non-decreasing as ECC rises L<=M<=Q<=H at dataLength=100 (FIX #6 monotonicity)');
}

// ==============================================================================
// 📤 fn_response.php — pure helpers in-process; full responses in a subprocess
// ==============================================================================
// @see web/CueRCode.net/_functions/fn_response.php
// ==============================================================================

// 🔍 determineResponseFormat() reads $_GET / $_SERVER and (optionally) getConfig.
//    With no getConfig() loaded, only the request-context branches are exercised.

// 📌 No hints at all -> defaults to json.
unset($_GET['format']);
unset($_SERVER['HTTP_ACCEPT']);
assertEquals('json', determineResponseFormat(), 'determineResponseFormat defaults to json with no hints');

// 📌 Explicit ?format=xml -> xml (case-insensitive).
$_GET['format'] = 'XML';
assertEquals('xml', determineResponseFormat(), 'determineResponseFormat honours ?format=XML (case-insensitive) -> xml');

// 📌 Explicit ?format=json -> json (wins over an xml Accept header).
$_GET['format'] = 'json';
$_SERVER['HTTP_ACCEPT'] = 'application/xml';
assertEquals('json', determineResponseFormat(), 'determineResponseFormat: ?format=json takes priority over Accept header');
unset($_GET['format']);

// 📌 Accept: application/xml (and NOT json) -> xml.
$_SERVER['HTTP_ACCEPT'] = 'application/xml';
assertEquals('xml', determineResponseFormat(), 'determineResponseFormat: Accept xml-only -> xml');

// 📌 Accept lists both xml AND json -> json (json wins per the implementation).
$_SERVER['HTTP_ACCEPT'] = 'application/xml,application/json';
assertEquals('json', determineResponseFormat(), 'determineResponseFormat: Accept with both xml+json -> json');
unset($_SERVER['HTTP_ACCEPT']);

// 🔄 arrayToXML(): pure recursive array -> SimpleXMLElement serialisation.
$xmlRoot = new \SimpleXMLElement('<response/>');
arrayToXML(
    [
        'success' => true,
        'message' => 'hello',
        'data'    => ['uuid' => 'abc-123'],
        'count'   => 3,
        'empty'   => null,
    ],
    $xmlRoot
);
$xmlString = (string) $xmlRoot->asXML();
assertContains('<success>true</success>', $xmlString, 'arrayToXML renders boolean true as the string "true"');
assertContains('<message>hello</message>', $xmlString, 'arrayToXML renders a scalar string value');
assertContains('<uuid>abc-123</uuid>', $xmlString, 'arrayToXML recurses into nested arrays');
assertContains('<count>3</count>', $xmlString, 'arrayToXML renders an integer value as text');
assertContains('<empty/>', $xmlString, 'arrayToXML renders null as an empty/self-closing element');

// 🔄 arrayToXML: numeric keys become <item> (XML names cannot start with a digit).
$xmlNumeric = new \SimpleXMLElement('<list/>');
arrayToXML(['a', 'b'], $xmlNumeric);
$xmlNumericString = (string) $xmlNumeric->asXML();
assertContains('<item>a</item>', $xmlNumericString, 'arrayToXML maps numeric array keys to <item> elements');

// 📋 jsonResponse() SHAPE — captured in a subprocess (the function calls exit).
$jsonSnippet = <<<'PHP'
requireFn('fn_response.php');
jsonResponse(['success' => true, 'message' => 'ok', 'data' => ['x' => 1]], 201);
PHP;
$jsonOut  = runPhpSnippet($jsonSnippet);
$jsonData = json_decode($jsonOut, true);

assertTrue(is_array($jsonData), 'jsonResponse output is valid, decodable JSON');
if (is_array($jsonData)) {
    assertTrue(array_key_exists('success', $jsonData), 'jsonResponse JSON contains a "success" key');
    assertEquals(true, $jsonData['success'] ?? null, 'jsonResponse preserves the success=true value');
    assertEquals('ok', $jsonData['message'] ?? null, 'jsonResponse preserves the message value');
    assertEquals(1, $jsonData['data']['x'] ?? null, 'jsonResponse preserves nested data values');
    // ⏱️ jsonResponse injects a server timestamp when none is supplied.
    assertTrue(array_key_exists('timestamp', $jsonData), 'jsonResponse auto-adds a "timestamp" key');
    assertMatches(
        '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/',
        (string) ($jsonData['timestamp'] ?? ''),
        'jsonResponse timestamp is ISO-8601 UTC (Y-m-dTH:i:s+00:00)'
    );
}

// 📄 xmlResponse() SHAPE — captured in a subprocess (the function calls exit).
$xmlSnippet = <<<'PHP'
requireFn('fn_response.php');
xmlResponse(['success' => true, 'message' => 'ok', 'data' => ['x' => 1]], 200);
PHP;
$xmlOut = runPhpSnippet($xmlSnippet);
assertContains('<response>', $xmlOut, 'xmlResponse wraps output in a <response> root element');
assertContains('<success>true</success>', $xmlOut, 'xmlResponse renders success=true');
assertContains('<message>ok</message>', $xmlOut, 'xmlResponse renders the message element');
assertContains('<x>1</x>', $xmlOut, 'xmlResponse renders nested data elements');
assertContains('<timestamp>', $xmlOut, 'xmlResponse auto-adds a <timestamp> element');
assertContains('xml-stylesheet', $xmlOut, 'xmlResponse includes the XSLT stylesheet processing instruction');
