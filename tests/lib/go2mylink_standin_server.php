<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - A stand-in Go2My.Link API server for the tests (#165)
 * ==============================================================================
 *
 * Path: tests/lib/go2mylink_standin_server.php
 *
 * WHAT IT IS (plain English):
 *   A tiny HTTPS server that answers like Go2My.Link's /api/v1, so
 *   tests/test_go2mylink.php can prove the real connection code (curl,
 *   certificate checks, time limits, reply-size limit, no redirects) without
 *   ever calling the real Go2My.Link or anything else on the internet. It
 *   only listens on 127.0.0.1, on a port the operating system picks.
 *
 *   The answers copy Go2My.Link's own code as read on 2026-11-28 (branch
 *   feat/2026-09-21-linkspage-and-sweep, which has #145 and #205):
 *   web/Go2My.Link/public_html/api/v1/index.php (the envelope, 401, 403,
 *   404, 429 with Retry-After) and handlers/urls.php (create, read, change).
 *
 *   The first part of the path picks how it behaves, so one server can play
 *   every case: https://127.0.0.1:<port>/<scenario>/api/v1/<route>
 *     ok          behaves like a working Go2My.Link (links are kept in memory)
 *     slowcreate  like ok, but a create is SAVED and the answer comes late
 *     slowurl     like slowcreate, but the look-up also gives short_url (what
 *                 Go2My.Link issue #276 asks for; the real look-up does not)
 *     notkept     like ok, but ignores the code CueRCode chose (the setting
 *                 cuercode.allow_external_shortcode switched off)
 *     wrongdomain like ok, but new links use another short domain
 *     noaccount   like ok, but GET /account says "missing scope"
 *     noqrscope   like ok, but the key's scopes do not include qr:link
 *     nowrite     like ok, but PUT says "missing scope"
 *     forbidden   every call: 403 "The CueRCode integration is currently disabled."
 *     conflict    POST: 409 custom_code taken
 *     reject      POST and PUT: 422 destination refused
 *     ratelimit   every call: 429 with Retry-After: 60
 *     unavailable every call: 503
 *     slow        waits 3 seconds before answering
 *     html        answers 200 with a web page, not JSON
 *     shape       answers 200 with JSON of the wrong shape
 *     big         answers 200 with 200 KB of JSON
 *     redirect    answers 302 to /ok/api/v1/ping on this same server
 *     garbled     answers with a garbled status line (review M1)
 *     nostatus    answers with a body and no status line at all (HTTP/0.9 style)
 *     bigheaders  answers 201 with more than 16 KB of headers
 *     badlength   answers 201 with a broken Content-Length header
 *   (The last four read the WHOLE request first, as a real server would.)
 *
 * How it is run (by the test, never by hand on a real site):
 *   php go2mylink_standin_server.php <certFile> <keyFile> <logFile> <expectedKey>
 * It prints "PORT <number>" when ready, writes one JSON line per request to
 * <logFile> (method, route, scenario, whether the key matched - never the
 * key itself), and stops as soon as its standard input closes (the test
 * ending in any way closes it).
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (#165)
 * @see         tests/test_go2mylink.php
 * @see         tests/lib/test_tls.php - makes the certificate
 */

if (PHP_SAPI !== 'cli') {
    exit('Command line only.');
}

if (!isset($argv[4])) {
    fwrite(STDERR, 'usage: go2mylink_standin_server.php <certFile> <keyFile> <logFile> <expectedKey>' . PHP_EOL);
    exit(2);
}

$standinCert        = $argv[1];
$standinKey         = $argv[2];
$standinLog         = $argv[3];
$standinExpectedKey = $argv[4];

// 🔐 TLS server on 127.0.0.1, port chosen by the operating system.
$standinContext = stream_context_create([
    'ssl' => [
        'local_cert'        => $standinCert,
        'local_pk'          => $standinKey,
        'allow_self_signed' => true,
        'verify_peer'       => false,
        'crypto_method'     => STREAM_CRYPTO_METHOD_TLS_SERVER,
    ],
]);
$standinErrno  = 0;
$standinErrstr = '';
$standinServer = stream_socket_server('tls://127.0.0.1:0', $standinErrno, $standinErrstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $standinContext);
if ($standinServer === false) {
    fwrite(STDERR, 'could not listen: ' . $standinErrstr . PHP_EOL);
    exit(1);
}
$standinName = (string) stream_socket_get_name($standinServer, false);
$standinPort = (int) substr($standinName, strrpos($standinName, ':') + 1);
fwrite(STDOUT, 'PORT ' . $standinPort . PHP_EOL);
fflush(STDOUT);

/** @var array<string, array<string, mixed>> $standinLinks lower-case code => link */
$standinLinks = [];
/** @var array<string, string> $standinUuids lower-case UUID => code */
$standinUuids = [];

/**
 * 📦 Go2My.Link's success envelope.
 *
 * @param mixed $data
 * @return string
 */
function standinSuccess(mixed $data): string
{
    return (string) json_encode([
        'status' => 'success',
        'data'   => $data,
        'meta'   => ['timestamp' => gmdate('Y-m-d\TH:i:s\Z'), 'requestId' => 'standin', 'rateLimit' => ['limit' => 60, 'remaining' => 59, 'resetAt' => '']],
    ]);
}

/**
 * 📦 Go2My.Link's error envelope (g2ml_apiErrorBody()).
 *
 * @param int         $code
 * @param string      $message
 * @param string|null $field
 * @return string
 */
function standinError(int $code, string $message, ?string $field = null): string
{
    return (string) json_encode(['status' => 'error', 'error' => ['code' => $code, 'message' => $message, 'field' => $field]]);
}

/**
 * 🔗 A stored link in Go2My.Link's reply shape (_g2ml_apiUrlsFormatRow()).
 *
 * @param array<string, mixed> $link
 * @return array<string, mixed>
 */
function standinRow(array $link): array
{
    return [
        'short_code'      => $link['short_code'],
        'destination_url' => $link['destination_url'],
        'title'           => null,
        'is_active'       => true,
        'click_count'     => $link['click_count'],
        'created_at'      => '2026-11-28 10:00:00',
        'updated_at'      => '2026-11-28 10:00:00',
        'start_date'      => null,
        'end_date'        => null,
    ];
}

/**
 * ✉️ Write one HTTP answer and close the connection.
 *
 * @param resource             $client
 * @param int                  $status
 * @param string               $body
 * @param array<int, string>   $extraHeaders
 * @param string               $contentType
 * @return void
 */
function standinSend($client, int $status, string $body, array $extraHeaders = [], string $contentType = 'application/json; charset=UTF-8'): void
{
    $reasons = [200 => 'OK', 201 => 'Created', 302 => 'Found', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 409 => 'Conflict', 422 => 'Unprocessable Entity', 429 => 'Too Many Requests', 503 => 'Service Unavailable'];
    $reason  = 'Status';
    if (isset($reasons[$status])) {
        $reason = $reasons[$status];
    }
    $head = 'HTTP/1.1 ' . $status . ' ' . $reason . "\r\n"
        . 'Content-Type: ' . $contentType . "\r\n"
        . 'Content-Length: ' . strlen($body) . "\r\n"
        . 'Cache-Control: no-store' . "\r\n"
        . 'Connection: close' . "\r\n";
    foreach ($extraHeaders as $header) {
        $head .= $header . "\r\n";
    }
    @fwrite($client, $head . "\r\n" . $body);
    @fclose($client);
}

// ==============================================================================
// 🔁 The loop: one request at a time, until standard input closes
// ==============================================================================
while (true) {
    $standinRead   = [$standinServer, STDIN];
    $standinWrite  = null;
    $standinExcept = null;
    $standinReady  = @stream_select($standinRead, $standinWrite, $standinExcept, 1);
    if ($standinReady === false) {
        break;
    }
    if (in_array(STDIN, $standinRead, true)) {
        $standinStdinData = fread(STDIN, 1024);
        if ($standinStdinData === '' || $standinStdinData === false || feof(STDIN)) {
            break;
        }
    }
    if (!in_array($standinServer, $standinRead, true)) {
        continue;
    }

    // 🤝 The TLS handshake happens here; a client that refuses our
    //    certificate makes this fail, and nothing is logged for it.
    $standinClient = @stream_socket_accept($standinServer, 5);
    if ($standinClient === false) {
        continue;
    }
    stream_set_timeout($standinClient, 5);

    // 📥 Read the request: the head, then exactly Content-Length bytes.
    $standinRaw = '';
    while (!str_contains($standinRaw, "\r\n\r\n") && strlen($standinRaw) < 65536) {
        $standinChunk = fread($standinClient, 8192);
        if ($standinChunk === false || $standinChunk === '') {
            break;
        }
        $standinRaw .= $standinChunk;
    }
    $standinSplit = strpos($standinRaw, "\r\n\r\n");
    if ($standinSplit === false) {
        @fclose($standinClient);
        continue;
    }
    $standinHead    = substr($standinRaw, 0, $standinSplit);
    $standinBody    = substr($standinRaw, $standinSplit + 4);
    $standinLines   = explode("\r\n", $standinHead);
    $standinFirst   = explode(' ', (string) array_shift($standinLines));
    $standinMethod  = (string) $standinFirst[0];
    $standinTarget  = '';
    if (isset($standinFirst[1])) {
        $standinTarget = (string) $standinFirst[1];
    }
    $standinHeaders = [];
    foreach ($standinLines as $standinLine) {
        $standinColon = strpos($standinLine, ':');
        if ($standinColon !== false) {
            $standinHeaders[strtolower(trim(substr($standinLine, 0, $standinColon)))] = trim(substr($standinLine, $standinColon + 1));
        }
    }
    $standinLength = 0;
    if (isset($standinHeaders['content-length'])) {
        $standinLength = (int) $standinHeaders['content-length'];
    }
    while (strlen($standinBody) < $standinLength) {
        $standinChunk = fread($standinClient, 8192);
        if ($standinChunk === false || $standinChunk === '') {
            break;
        }
        $standinBody .= $standinChunk;
    }

    // 🧭 /<scenario>/api/v1/<route>[?query]
    $standinPath  = (string) parse_url($standinTarget, PHP_URL_PATH);
    $standinMatch = [];
    if (preg_match('#^/([a-z]+)/api/v1/(.*)$#', $standinPath, $standinMatch) !== 1) {
        standinSend($standinClient, 404, standinError(404, 'The requested endpoint does not exist.'));
        continue;
    }
    $standinScenario = $standinMatch[1];
    $standinRoute    = $standinMatch[2];

    // 🔑 The key, compared but never written down.
    $standinAuth = 'none';
    if (isset($standinHeaders['authorization'])) {
        $standinAuth = 'bad';
        if (hash_equals('Bearer ' . $standinExpectedKey, $standinHeaders['authorization'])) {
            $standinAuth = 'ok';
        }
    }
    $standinDecoded = json_decode($standinBody, true);
    if (!is_array($standinDecoded)) {
        $standinDecoded = null;
    }
    $standinAcceptEncoding = 'none';
    if (isset($standinHeaders['accept-encoding'])) {
        $standinAcceptEncoding = $standinHeaders['accept-encoding'];
    }
    $standinAccept = '';
    if (isset($standinHeaders['accept'])) {
        $standinAccept = $standinHeaders['accept'];
    }
    file_put_contents($standinLog, json_encode([
        'scenario'       => $standinScenario,
        'method'         => $standinMethod,
        'route'          => $standinRoute,
        'query'          => (string) parse_url($standinTarget, PHP_URL_QUERY),
        'auth'           => $standinAuth,
        'accept'         => $standinAccept,
        'acceptEncoding' => $standinAcceptEncoding,
        'body'           => $standinDecoded,
    ]) . PHP_EOL, FILE_APPEND);

    // 🚦 The fixed answers first.
    if ($standinScenario === 'slow') {
        sleep(3);
        standinSend($standinClient, 200, standinSuccess(['pong' => true]));
        continue;
    }
    if ($standinScenario === 'forbidden') {
        standinSend($standinClient, 403, standinError(403, 'The CueRCode integration is currently disabled.'));
        continue;
    }
    if ($standinScenario === 'ratelimit') {
        standinSend($standinClient, 429, standinError(429, 'Rate limit exceeded.'), ['Retry-After: 60']);
        continue;
    }
    if ($standinScenario === 'unavailable') {
        standinSend($standinClient, 503, standinError(503, 'The public API is temporarily unavailable.'));
        continue;
    }
    if ($standinScenario === 'html') {
        standinSend($standinClient, 200, '<!doctype html><title>Coming soon</title>', [], 'text/html; charset=UTF-8');
        continue;
    }
    if ($standinScenario === 'shape') {
        standinSend($standinClient, 200, standinSuccess(['unexpected' => true]));
        continue;
    }
    if ($standinScenario === 'big') {
        standinSend($standinClient, 200, standinSuccess(['padding' => str_repeat('x', 200000)]));
        continue;
    }
    // 🧾 Review M1: the request was received in full, the answer is broken.
    $standinOk201 = standinSuccess(['short_code' => 'Brk7777', 'short_url' => 'https://QRmy.Link/Brk7777', 'qr_code_external_uuid' => '2fa85f64-5717-4562-b3fc-2c963f66afa2']);
    $standinBrokenAnswers = [
        'garbled'    => "HXTP/1.1 2O1 Cre@ted\r\nContent-Length: " . strlen($standinOk201) . "\r\nConnection: close\r\n\r\n" . $standinOk201,
        'nostatus'   => $standinOk201,
        'bigheaders' => "HTTP/1.1 201 Created\r\nContent-Type: application/json\r\n" . str_repeat('X-Padding: ' . str_repeat('p', 1000) . "\r\n", 20) . 'Content-Length: ' . strlen($standinOk201) . "\r\nConnection: close\r\n\r\n" . $standinOk201,
        'badlength'  => "HTTP/1.1 201 Created\r\nContent-Type: application/json\r\nContent-Length: twelve\r\nConnection: close\r\n\r\n" . $standinOk201,
    ];
    if (isset($standinBrokenAnswers[$standinScenario])) {
        @fwrite($standinClient, $standinBrokenAnswers[$standinScenario]);
        @fclose($standinClient);
        continue;
    }

    if ($standinScenario === 'redirect') {
        standinSend($standinClient, 302, '', ['Location: https://127.0.0.1:' . $standinPort . '/ok/api/v1/ping']);
        continue;
    }

    // 🔑 Step 7 of Go2My.Link's front controller: a wrong or missing key.
    if ($standinAuth !== 'ok') {
        standinSend($standinClient, 401, standinError(401, 'Invalid or expired API key.'));
        continue;
    }

    // 🗺️ The routes CueRCode uses.
    $standinCodeMatch = [];
    $standinCode      = '';
    if (preg_match('#^urls/([A-Za-z0-9_-]{1,50})$#', $standinRoute, $standinCodeMatch) === 1) {
        $standinCode = $standinCodeMatch[1];
    }

    if ($standinMethod === 'GET' && $standinRoute === 'ping') {
        standinSend($standinClient, 200, standinSuccess(['pong' => true, 'requestId' => 'standin']));
        continue;
    }

    if ($standinMethod === 'GET' && $standinRoute === 'account') {
        if ($standinScenario === 'noaccount') {
            standinSend($standinClient, 403, standinError(403, 'This API key does not have the required scope.'));
            continue;
        }
        $standinScopes = ['qr:link', 'urls:read', 'urls:write', 'urls:delete', 'analytics:read', 'account:read'];
        if ($standinScenario === 'noqrscope') {
            $standinScopes = ['urls:read', 'urls:write', 'account:read'];
        }
        standinSend($standinClient, 200, standinSuccess(['userUID' => 7, 'email' => 'owner@example.com', 'displayName' => 'Owner', 'orgHandle' => 'cuercode', 'keyName' => 'CueRCode', 'scopes' => $standinScopes]));
        continue;
    }

    if ($standinMethod === 'GET' && $standinRoute === 'urls') {
        standinSend($standinClient, 200, standinSuccess(['items' => [], 'nextCursor' => null]));
        continue;
    }

    if ($standinMethod === 'GET' && $standinCode !== '') {
        if (!isset($standinLinks[strtolower($standinCode)])) {
            standinSend($standinClient, 404, standinError(404, 'No such short URL in this account.'));
            continue;
        }
        $standinFound = standinRow($standinLinks[strtolower($standinCode)]);
        if ($standinScenario === 'slowurl') {
            $standinFound['short_url'] = 'https://QRmy.Link/' . $standinFound['short_code'];
        }
        standinSend($standinClient, 200, standinSuccess($standinFound));
        continue;
    }

    if ($standinMethod === 'PUT' && $standinCode !== '') {
        if ($standinScenario === 'nowrite') {
            standinSend($standinClient, 403, standinError(403, 'This API key does not have the required scope.'));
            continue;
        }
        // Same order as Go2My.Link: the link must exist in this organisation first.
        if (!isset($standinLinks[strtolower($standinCode)])) {
            standinSend($standinClient, 404, standinError(404, 'No such short URL in this account.'));
            continue;
        }
        if ($standinScenario === 'reject') {
            standinSend($standinClient, 422, standinError(422, 'That destination is not permitted. Please enter a public HTTP or HTTPS URL.', 'destination_url'));
            continue;
        }
        if (!is_array($standinDecoded) || !isset($standinDecoded['destination_url']) || !is_string($standinDecoded['destination_url'])) {
            standinSend($standinClient, 422, standinError(422, 'At least one of destination_url, title, or tags must be supplied.'));
            continue;
        }
        $standinLinks[strtolower($standinCode)]['destination_url'] = $standinDecoded['destination_url'];
        standinSend($standinClient, 200, standinSuccess(standinRow($standinLinks[strtolower($standinCode)])));
        continue;
    }

    if ($standinMethod === 'POST' && $standinRoute === 'urls') {
        if ($standinScenario === 'conflict') {
            standinSend($standinClient, 409, standinError(409, 'That alias is already taken. Please choose another.', 'custom_code'));
            continue;
        }
        if ($standinScenario === 'reject') {
            standinSend($standinClient, 422, standinError(422, 'That destination is not permitted. Please enter a public HTTP or HTTPS URL.', 'destination_url'));
            continue;
        }
        if (!is_array($standinDecoded) || !isset($standinDecoded['destination_url'], $standinDecoded['qr_external_id'], $standinDecoded['qr_external_uuid'])) {
            standinSend($standinClient, 422, standinError(422, 'qr_external_id is required when linking a CueRCode QR code.', 'qr_external_id'));
            continue;
        }
        $standinUuid = strtolower((string) $standinDecoded['qr_external_uuid']);
        if (isset($standinUuids[$standinUuid])) {
            standinSend($standinClient, 409, standinError(409, 'That QR code is already linked to a short URL.', 'qr_external_uuid'));
            continue;
        }
        $standinNewCode = 'Srv' . str_pad((string) (count($standinLinks) + 1), 4, '0', STR_PAD_LEFT);
        if ($standinScenario !== 'notkept' && isset($standinDecoded['custom_code']) && is_string($standinDecoded['custom_code'])) {
            $standinNewCode = $standinDecoded['custom_code'];
        }
        if (isset($standinLinks[strtolower($standinNewCode)])) {
            standinSend($standinClient, 409, standinError(409, 'That alias is already taken. Please choose another.', 'custom_code'));
            continue;
        }
        $standinLinks[strtolower($standinNewCode)] = ['short_code' => $standinNewCode, 'destination_url' => (string) $standinDecoded['destination_url'], 'click_count' => 41];
        $standinUuids[$standinUuid] = $standinNewCode;
        $standinDomain = 'QRmy.Link';
        if ($standinScenario === 'wrongdomain') {
            $standinDomain = 'g2my.link';
        }
        if ($standinScenario === 'slowcreate' || $standinScenario === 'slowurl') {
            sleep(3);
        }
        standinSend($standinClient, 201, standinSuccess(['short_code' => $standinNewCode, 'short_url' => 'https://' . $standinDomain . '/' . $standinNewCode, 'qr_code_external_uuid' => (string) $standinDecoded['qr_external_uuid']]));
        continue;
    }

    standinSend($standinClient, 404, standinError(404, 'The requested endpoint does not exist.'));
}

fclose($standinServer);
exit(0);
