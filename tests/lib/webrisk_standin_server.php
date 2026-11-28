<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - A stand-in Google Web Risk server for the tests (#168)
 * ==============================================================================
 *
 * Path: tests/lib/webrisk_standin_server.php
 *
 * WHAT IT IS (plain English):
 *   A tiny HTTPS server that answers like Google Web Risk's Lookup API
 *   (GET /v1/uris:search), so tests/test_url_reputation.php can prove the
 *   real link-safety code (curl, certificate checks, time limits, the
 *   reply-size limit, no redirects, where the key goes) without ever calling
 *   Google. It only listens on 127.0.0.1, on a port the system picks.
 *
 *   The answers copy Google's own documentation, read on 2026-11-28:
 *     https://cloud.google.com/web-risk/docs/lookup-api (the {} and
 *     {"threat": {"threatTypes": [...], "expireTime": "..."}} replies) and
 *     https://cloud.google.com/apis/design/errors (the error shape).
 *
 *   The first part of the path picks how it behaves:
 *     https://127.0.0.1:<port>/<scenario>/v1/uris:search?...
 *       safe        200 {}  (the address is on no list)
 *       unsafe      200 {"threat": {"threatTypes": ["MALWARE", "SOCIAL_ENGINEERING"], "expireTime": now + 5 minutes}}
 *       emptythreat 200 {"threat": {}}  ("might be empty if the URI is on no list")
 *       badrequest  400 INVALID_ARGUMENT
 *       denied      403 PERMISSION_DENIED (a wrong key, the API not switched on)
 *       quota       429 RESOURCE_EXHAUSTED
 *       broken      500 INTERNAL
 *       slow        waits 3 seconds, then {}
 *       html        200 with a web page, not JSON
 *       big         200 with 200 KB of JSON
 *       redirect    302 to /unsafe/v1/uris:search on this same server
 *
 * How it is run (by the test, never by hand on a real site):
 *   php webrisk_standin_server.php <certFile> <keyFile> <logFile> <expectedKey>
 * It prints "PORT <number>" when ready, writes one JSON line per request to
 * <logFile> (method, scenario, whether the x-goog-api-key header matched,
 * whether the key or "key=" appeared in the address, the uri and threatTypes
 * asked about, whether a body or Accept-Encoding was sent - never the key
 * itself), and stops as soon as its standard input closes.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (#168)
 * @see         tests/test_url_reputation.php
 * @see         tests/lib/test_tls.php - makes the certificate
 */

if (PHP_SAPI !== 'cli') {
    exit('Command line only.');
}

if (!isset($argv[4])) {
    fwrite(STDERR, 'usage: webrisk_standin_server.php <certFile> <keyFile> <logFile> <expectedKey>' . PHP_EOL);
    exit(2);
}

$wrCert        = $argv[1];
$wrKey         = $argv[2];
$wrLog         = $argv[3];
$wrExpectedKey = $argv[4];

// 🔐 TLS server on 127.0.0.1, port chosen by the operating system.
$wrContext = stream_context_create([
    'ssl' => [
        'local_cert'        => $wrCert,
        'local_pk'          => $wrKey,
        'allow_self_signed' => true,
        'verify_peer'       => false,
        'crypto_method'     => STREAM_CRYPTO_METHOD_TLS_SERVER,
    ],
]);
$wrErrno  = 0;
$wrErrstr = '';
$wrServer = stream_socket_server('tls://127.0.0.1:0', $wrErrno, $wrErrstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $wrContext);
if ($wrServer === false) {
    fwrite(STDERR, 'could not listen: ' . $wrErrstr . PHP_EOL);
    exit(1);
}
$wrName = (string) stream_socket_get_name($wrServer, false);
$wrPort = (int) substr($wrName, strrpos($wrName, ':') + 1);
fwrite(STDOUT, 'PORT ' . $wrPort . PHP_EOL);
fflush(STDOUT);

/**
 * ✉️ Write one HTTP answer and close the connection.
 *
 * @param resource           $client
 * @param int                $status
 * @param string             $body
 * @param array<int, string> $extraHeaders
 * @param string             $contentType
 * @return void
 */
function wrSend($client, int $status, string $body, array $extraHeaders = [], string $contentType = 'application/json; charset=UTF-8'): void
{
    $reasons = [200 => 'OK', 302 => 'Found', 400 => 'Bad Request', 403 => 'Forbidden', 429 => 'Too Many Requests', 500 => 'Internal Server Error'];
    $reason  = 'Status';
    if (isset($reasons[$status])) {
        $reason = $reasons[$status];
    }
    $head = 'HTTP/1.1 ' . $status . ' ' . $reason . "\r\n"
        . 'Content-Type: ' . $contentType . "\r\n"
        . 'Content-Length: ' . strlen($body) . "\r\n"
        . 'Connection: close' . "\r\n";
    foreach ($extraHeaders as $header) {
        $head .= $header . "\r\n";
    }
    @fwrite($client, $head . "\r\n" . $body);
    @fclose($client);
}

/**
 * 📦 Google's error shape (AIP-193).
 *
 * @param int    $code
 * @param string $status
 * @param string $message
 * @return string
 */
function wrError(int $code, string $status, string $message): string
{
    return (string) json_encode(['error' => ['code' => $code, 'message' => $message, 'status' => $status]]);
}

// ==============================================================================
// 🔁 The loop: one request at a time, until standard input closes
// ==============================================================================
while (true) {
    $wrRead   = [$wrServer, STDIN];
    $wrWrite  = null;
    $wrExcept = null;
    $wrReady  = @stream_select($wrRead, $wrWrite, $wrExcept, 1);
    if ($wrReady === false) {
        break;
    }
    if (in_array(STDIN, $wrRead, true)) {
        $wrStdin = fread(STDIN, 1024);
        if ($wrStdin === '' || $wrStdin === false || feof(STDIN)) {
            break;
        }
    }
    if (!in_array($wrServer, $wrRead, true)) {
        continue;
    }

    $wrClient = @stream_socket_accept($wrServer, 2);
    if ($wrClient === false) {
        continue;
    }
    stream_set_timeout($wrClient, 5);

    // 📥 The request line and headers.
    $wrRaw = '';
    while (!str_contains($wrRaw, "\r\n\r\n") && strlen($wrRaw) < 65536) {
        $wrChunk = fread($wrClient, 8192);
        if ($wrChunk === false || $wrChunk === '') {
            break;
        }
        $wrRaw .= $wrChunk;
    }
    $wrHeadEnd = strpos($wrRaw, "\r\n\r\n");
    if ($wrHeadEnd === false) {
        @fclose($wrClient);
        continue;
    }
    $wrLines   = explode("\r\n", substr($wrRaw, 0, $wrHeadEnd));
    $wrFirst   = explode(' ', (string) array_shift($wrLines));
    $wrMethod  = (string) $wrFirst[0];
    $wrTarget  = '';
    if (isset($wrFirst[1])) {
        $wrTarget = (string) $wrFirst[1];
    }
    $wrHeaders = [];
    foreach ($wrLines as $wrLine) {
        $wrColon = strpos($wrLine, ':');
        if ($wrColon !== false) {
            $wrHeaders[strtolower(trim(substr($wrLine, 0, $wrColon)))] = trim(substr($wrLine, $wrColon + 1));
        }
    }
    $wrBodyLength = 0;
    if (isset($wrHeaders['content-length'])) {
        $wrBodyLength = (int) $wrHeaders['content-length'];
    }

    $wrPath  = (string) parse_url($wrTarget, PHP_URL_PATH);
    $wrQuery = (string) parse_url($wrTarget, PHP_URL_QUERY);
    $wrParts = explode('/', trim($wrPath, '/'));
    $wrScenario = (string) $wrParts[0];

    // 🧾 The query, read by hand so a repeated threatTypes is kept.
    $wrUri   = '';
    $wrTypes = [];
    foreach (explode('&', $wrQuery) as $wrPair) {
        $wrEq = strpos($wrPair, '=');
        if ($wrEq === false) {
            continue;
        }
        $wrName  = rawurldecode(substr($wrPair, 0, $wrEq));
        $wrValue = rawurldecode(substr($wrPair, $wrEq + 1));
        if ($wrName === 'uri') {
            $wrUri = $wrValue;
        } elseif ($wrName === 'threatTypes') {
            $wrTypes[] = $wrValue;
        }
    }

    $wrKeyHeader = 'missing';
    if (isset($wrHeaders['x-goog-api-key'])) {
        $wrKeyHeader = 'wrong';
        if (hash_equals($wrExpectedKey, $wrHeaders['x-goog-api-key'])) {
            $wrKeyHeader = 'ok';
        }
    }
    $wrAcceptEncoding = 'none';
    if (isset($wrHeaders['accept-encoding'])) {
        $wrAcceptEncoding = $wrHeaders['accept-encoding'];
    }
    $wrAccept = '';
    if (isset($wrHeaders['accept'])) {
        $wrAccept = $wrHeaders['accept'];
    }

    file_put_contents($wrLog, json_encode([
        'method'         => $wrMethod,
        'scenario'       => $wrScenario,
        'route'          => implode('/', array_slice($wrParts, 1)),
        'keyHeader'      => $wrKeyHeader,
        'keyInUrl'       => (str_contains($wrTarget, $wrExpectedKey) || str_contains(strtolower($wrQuery), 'key=')),
        'uri'            => $wrUri,
        'threatTypes'    => $wrTypes,
        'hasBody'        => ($wrBodyLength > 0),
        'acceptEncoding' => $wrAcceptEncoding,
        'accept'         => $wrAccept,
    ]) . PHP_EOL, FILE_APPEND);

    if ($wrKeyHeader !== 'ok' && $wrScenario !== 'denied') {
        wrSend($wrClient, 403, wrError(403, 'PERMISSION_DENIED', 'API key not valid. Please pass a valid API key.'));
        continue;
    }

    if ($wrScenario === 'safe') {
        wrSend($wrClient, 200, '{}');
    } elseif ($wrScenario === 'unsafe') {
        wrSend($wrClient, 200, (string) json_encode(['threat' => ['threatTypes' => ['MALWARE', 'SOCIAL_ENGINEERING'], 'expireTime' => gmdate('Y-m-d\TH:i:s', time() + 300) . '.045123456Z']]));
    } elseif ($wrScenario === 'emptythreat') {
        wrSend($wrClient, 200, '{"threat": {}}');
    } elseif ($wrScenario === 'badrequest') {
        wrSend($wrClient, 400, wrError(400, 'INVALID_ARGUMENT', 'Invalid value at \'uri\'.'));
    } elseif ($wrScenario === 'denied') {
        wrSend($wrClient, 403, wrError(403, 'PERMISSION_DENIED', 'Web Risk API has not been used in project 0 before or it is disabled.'));
    } elseif ($wrScenario === 'quota') {
        wrSend($wrClient, 429, wrError(429, 'RESOURCE_EXHAUSTED', 'Quota exceeded for quota metric \'SearchUris requests\'.'));
    } elseif ($wrScenario === 'broken') {
        wrSend($wrClient, 500, wrError(500, 'INTERNAL', 'Internal error encountered.'));
    } elseif ($wrScenario === 'slow') {
        sleep(3);
        wrSend($wrClient, 200, '{}');
    } elseif ($wrScenario === 'html') {
        wrSend($wrClient, 200, '<!doctype html><title>Sign in</title><p>Please sign in.</p>', [], 'text/html; charset=UTF-8');
    } elseif ($wrScenario === 'big') {
        wrSend($wrClient, 200, '{"padding": "' . str_repeat('x', 200000) . '"}');
    } elseif ($wrScenario === 'redirect') {
        wrSend($wrClient, 302, '', ['Location: /unsafe/v1/uris:search?' . $wrQuery]);
    } else {
        wrSend($wrClient, 404, wrError(404, 'NOT_FOUND', 'Not found.'));
    }
}

exit(0);
