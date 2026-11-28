<?php
/**
 * ==============================================================================
 * 🔐 CueRCode - Throwaway certificates for tests that need real HTTPS
 * ==============================================================================
 *
 * Path: tests/lib/test_tls.php
 *
 * WHY (plain English):
 *   The Go2My.Link connection code (#165) must check the other server's
 *   security certificate. To prove that with a real HTTPS conversation, the
 *   tests need a certificate that is trusted ON PURPOSE (a test-only
 *   "certificate authority" that the test hands to the client) and one that
 *   is NOT trusted (the same server with the client given no such authority).
 *   This file makes both, in a temporary folder, with PHP's own OpenSSL
 *   functions - no outside tools and nothing installed on the machine.
 *
 *   The certificates are valid for 127.0.0.1 and "localhost" only, last one
 *   day, and are deleted by testTlsRemove().
 *
 * Loading this file defines functions only.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (#165)
 * @see         https://www.php.net/manual/en/function.openssl-csr-sign.php
 * @see         tests/lib/go2mylink_standin_server.php - the server that uses them
 */

/**
 * 🔐 Make a test certificate authority and a server certificate it signed.
 *
 * @param string $directory An empty folder that only this test uses
 * @return array{ok: bool, caFile: string, certFile: string, keyFile: string, error: string}
 */
function testTlsMakeCertificates(string $directory): array
{
    $failed = ['ok' => false, 'caFile' => '', 'certFile' => '', 'keyFile' => '', 'error' => ''];

    if (!function_exists('openssl_pkey_new')) {
        $failed['error'] = 'the openssl extension is not loaded';
        return $failed;
    }

    // 📝 A small OpenSSL settings file: what the authority and the server
    //    certificate are allowed to do, and the names the server answers to.
    $configFile = $directory . DIRECTORY_SEPARATOR . 'openssl.cnf';
    $configText = '[req]' . PHP_EOL
        . 'distinguished_name = req_dn' . PHP_EOL
        . '[req_dn]' . PHP_EOL
        . '[v3_ca]' . PHP_EOL
        . 'basicConstraints = critical, CA:TRUE' . PHP_EOL
        . 'keyUsage = critical, keyCertSign, cRLSign' . PHP_EOL
        . 'subjectKeyIdentifier = hash' . PHP_EOL
        . '[v3_server]' . PHP_EOL
        . 'basicConstraints = critical, CA:FALSE' . PHP_EOL
        . 'keyUsage = critical, digitalSignature, keyEncipherment' . PHP_EOL
        . 'extendedKeyUsage = serverAuth' . PHP_EOL
        . 'subjectAltName = IP:127.0.0.1, DNS:localhost' . PHP_EOL
        . 'authorityKeyIdentifier = keyid' . PHP_EOL;
    if (file_put_contents($configFile, $configText) === false) {
        $failed['error'] = 'could not write the OpenSSL settings file';
        return $failed;
    }

    $keyOptions = ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, 'config' => $configFile];

    // 🏛️ 1. The test-only certificate authority (signs itself).
    $caKey = openssl_pkey_new($keyOptions);
    if ($caKey === false) {
        $failed['error'] = 'could not make the authority key';
        return $failed;
    }
    $caRequest = openssl_csr_new(['commonName' => 'CueRCode test authority'], $caKey, ['config' => $configFile, 'digest_alg' => 'sha256']);
    if ($caRequest === false || $caRequest === true) {
        $failed['error'] = 'could not make the authority request';
        return $failed;
    }
    $caCert = openssl_csr_sign($caRequest, null, $caKey, 1, ['config' => $configFile, 'x509_extensions' => 'v3_ca', 'digest_alg' => 'sha256'], random_int(1000, 999999));
    if ($caCert === false) {
        $failed['error'] = 'could not sign the authority';
        return $failed;
    }

    // 🖥️ 2. The server certificate, signed by that authority.
    $serverKey = openssl_pkey_new($keyOptions);
    if ($serverKey === false) {
        $failed['error'] = 'could not make the server key';
        return $failed;
    }
    $serverRequest = openssl_csr_new(['commonName' => '127.0.0.1'], $serverKey, ['config' => $configFile, 'digest_alg' => 'sha256']);
    if ($serverRequest === false || $serverRequest === true) {
        $failed['error'] = 'could not make the server request';
        return $failed;
    }
    $serverCert = openssl_csr_sign($serverRequest, $caCert, $caKey, 1, ['config' => $configFile, 'x509_extensions' => 'v3_server', 'digest_alg' => 'sha256'], random_int(1000000, 9999999));
    if ($serverCert === false) {
        $failed['error'] = 'could not sign the server certificate';
        return $failed;
    }

    // 💾 3. Write the three files the server and the client need.
    $caFile   = $directory . DIRECTORY_SEPARATOR . 'ca.pem';
    $certFile = $directory . DIRECTORY_SEPARATOR . 'server.pem';
    $keyFile  = $directory . DIRECTORY_SEPARATOR . 'server.key';
    $caText   = '';
    $certText = '';
    $keyText  = '';
    if (!openssl_x509_export($caCert, $caText) || !openssl_x509_export($serverCert, $certText) || !openssl_pkey_export($serverKey, $keyText, null, ['config' => $configFile])) {
        $failed['error'] = 'could not export the certificates';
        return $failed;
    }
    if (file_put_contents($caFile, $caText) === false || file_put_contents($certFile, $certText) === false || file_put_contents($keyFile, $keyText) === false) {
        $failed['error'] = 'could not write the certificate files';
        return $failed;
    }

    return ['ok' => true, 'caFile' => $caFile, 'certFile' => $certFile, 'keyFile' => $keyFile, 'error' => ''];
}

/**
 * 🧹 Delete the temporary folder and every file in it (one level only).
 *
 * @param string $directory The folder testTlsMakeCertificates() used
 * @return void
 */
function testTlsRemove(string $directory): void
{
    $files = glob($directory . DIRECTORY_SEPARATOR . '*');
    if (is_array($files)) {
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
    if (is_dir($directory)) {
        rmdir($directory);
    }
}
