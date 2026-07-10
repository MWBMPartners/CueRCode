<?php
/**
 * ==============================================================================
 * 🔐 CueRCode - Encryption Utilities
 * ==============================================================================
 *
 * Path: _functions/fn_encryption.php
 *
 * Provides functions for encrypting and decrypting sensitive data using
 * AES-256-GCM with salt. Used for storing sensitive settings (API keys,
 * SSO credentials, etc.) in the database securely.
 *
 * Also provides API key generation and hashing functions.
 *
 * @package     CueRCode
 * @subpackage  Security
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 1
 * @see         https://www.php.net/manual/en/function.openssl-encrypt.php
 * @see         https://www.php.net/manual/en/function.openssl-decrypt.php
 * @see         https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html
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
// 🔑 Encryption Constants
// ==============================================================================

/**
 * @var string The cipher algorithm used for AES-256-GCM encryption
 * AES-256-GCM provides authenticated encryption with associated data (AEAD),
 * meaning it ensures both confidentiality AND integrity of the encrypted data.
 * @see https://www.php.net/manual/en/function.openssl-get-cipher-methods.php
 */
if (!defined('CUERCODE_CIPHER_ALGO')) {
    define('CUERCODE_CIPHER_ALGO', 'aes-256-gcm');
}

/**
 * @var int Length of the GCM authentication tag in bytes
 * The tag verifies that the ciphertext hasn't been tampered with.
 * @see https://csrc.nist.gov/publications/detail/sp/800-38d/final
 */
if (!defined('CUERCODE_TAG_LENGTH')) {
    define('CUERCODE_TAG_LENGTH', 16);
}

// ==============================================================================
// 🔒 Encrypt Value
// ==============================================================================

/**
 * 🔒 Encrypt a plaintext value using AES-256-GCM with a PER-RECORD salt (F-006/G-010)
 *
 * The encryption process:
 * 1. 🧂 Resolves the salt to use for THIS call - see the per-record salt note below.
 * 2. Derives a key from the master key + that salt using HKDF
 * 3. Generates a random 12-byte IV (Initialisation Vector)
 * 4. Encrypts using AES-256-GCM which produces ciphertext + auth tag
 * 5. Packs [salt length][salt][IV][tag][ciphertext] together (salt embedded -
 *    see below)
 * 6. Returns base64-encoded result for safe database storage
 *
 * 🧂 F-006/G-010 PER-RECORD SALT: unlike the previous design (a single global
 * ENCRYPTION_SALT constant shared by every encrypted value in the database),
 * every call to this function that does NOT pass an explicit $salt generates
 * a FRESH, cryptographically random 16-byte salt just for this one value.
 * Two rows holding the identical plaintext therefore derive completely
 * independent AES keys (not merely independent IVs as before).
 *
 * The salt actually used is embedded directly in the returned ciphertext blob
 * (length-prefixed, so it survives any salt length from 0-255 bytes), making
 * decryptValue() fully self-describing - it never needs to be told the salt
 * separately, which is what lets this same function be reused for ANY future
 * encrypted column (e.g. tblUserOAuth.tokensEncrypted, Epic E2) without a
 * bespoke companion salt column for every table. Callers that want visibility
 * into the salt that was generated (e.g. fn_config.php mirroring it into the
 * tblSettings.encryptionSalt column for DBA-visible auditability) may pass a
 * variable as the optional 4th $usedSaltOut by-reference parameter.
 *
 * An explicit $salt argument is still honoured exactly as supplied (any
 * length) - this preserves deterministic/reproducible round-trips for
 * testing and any caller that has a specific reason to pin the salt.
 *
 * @param string      $plaintext    The value to encrypt
 * @param string|null $key          Encryption key (defaults to ENCRYPTION_KEY constant)
 * @param string|null $salt         Explicit salt override (defaults to a fresh random 16-byte salt per call - see above)
 * @param string|null &$usedSaltOut Optional by-reference output: receives the raw salt bytes actually used
 *
 * @return string Base64-encoded encrypted value (salt embedded)
 *
 * @throws \RuntimeException If encryption fails or ENCRYPTION_KEY is not configured
 *
 * @see https://www.php.net/manual/en/function.openssl-encrypt.php
 * @see https://www.php.net/manual/en/function.hash-hkdf.php
 * @see https://www.php.net/manual/en/function.random-bytes.php
 *
 * @example
 *   $encrypted = encryptValue('my-secret-api-key');
 *   // Store $encrypted in database - salt travels embedded in the blob.
 *
 *   $usedSalt = null;
 *   $encrypted = encryptValue('my-secret-api-key', null, null, $usedSalt);
 *   // $usedSalt now holds the raw 16-byte salt that was generated, for
 *   // optional DBA-visible mirroring into a dedicated salt column.
 */
function encryptValue(string $plaintext, ?string $key = null, ?string $salt = null, ?string &$usedSaltOut = null): string
{
    // 🔑 Use default keys if not provided
    if ($key !== null) {
        // Use the provided key
    } else {
        if (defined('ENCRYPTION_KEY')) {
            $key = ENCRYPTION_KEY;
        } else {
            $key = null;
        }
    }

    // ✅ Validate that the master key is available
    if (empty($key)) {
        throw new \RuntimeException(
            'Encryption key not configured. '
            . 'Define ENCRYPTION_KEY in _auth_keys/auth_creds.php'
        );
    }

    // 🧂 F-006/G-010: resolve the per-record salt. An explicitly supplied
    // (non-empty) salt is honoured verbatim; otherwise generate a fresh
    // random 16-byte salt for THIS value only - the whole point of this fix
    // is that we STOP falling back to one shared global ENCRYPTION_SALT.
    // @see https://www.php.net/manual/en/function.random-bytes.php
    if ($salt === null || $salt === '') {
        $salt = random_bytes(16);
    }

    // 📤 Surface the salt actually used to the caller, if requested.
    $usedSaltOut = $salt;

    // 🛡️ Defensive cap: the packed format below stores the salt length in a
    // single byte (0-255). Any caller-supplied salt must fit; a legitimate
    // salt is always far shorter than this (16-32 bytes typical).
    if (strlen($salt) > 255) {
        throw new \RuntimeException('Salt is too long (maximum 255 bytes for the packed format)');
    }

    // 🔑 Derive an encryption key from the master key + salt using HKDF
    // HKDF (HMAC-based Key Derivation Function) produces a key of the exact
    // length needed for AES-256 (32 bytes) from the master key and salt.
    // @see https://www.php.net/manual/en/function.hash-hkdf.php
    // @see https://tools.ietf.org/html/rfc5869
    $derivedKey = hash_hkdf('sha256', $key, 32, 'cuercode-encryption', $salt);

    if ($derivedKey === false) {
        throw new \RuntimeException('Key derivation (HKDF) failed');
    }

    // 🎲 Generate a random Initialisation Vector (IV)
    // AES-256-GCM requires a 12-byte (96-bit) IV for optimal security.
    // Using a random IV means the same plaintext produces different ciphertext each time.
    // @see https://www.php.net/manual/en/function.openssl-random-pseudo-bytes.php
    $ivLength = openssl_cipher_iv_length(CUERCODE_CIPHER_ALGO);
    $iv = openssl_random_pseudo_bytes($ivLength);

    if ($iv === false) {
        throw new \RuntimeException('Failed to generate random IV');
    }

    // 🔒 Encrypt using AES-256-GCM
    // The $tag parameter receives the authentication tag (GCM mode)
    // @see https://www.php.net/manual/en/function.openssl-encrypt.php
    $tag = '';
    $ciphertext = openssl_encrypt(
        $plaintext,          // Data to encrypt
        CUERCODE_CIPHER_ALGO,   // Cipher algorithm (aes-256-gcm)
        $derivedKey,         // Derived encryption key
        OPENSSL_RAW_DATA,   // Return raw binary (not base64)
        $iv,                 // Initialisation Vector
        $tag,                // Output: GCM authentication tag
        '',                  // Additional Authenticated Data (AAD) - empty for our use
        CUERCODE_TAG_LENGTH     // Tag length (16 bytes)
    );

    if ($ciphertext === false) {
        throw new \RuntimeException('Encryption failed: ' . openssl_error_string());
    }

    // 📦 Pack the salt, IV, tag, and ciphertext together
    // Format: [salt length (1 byte)] [salt (variable)] [IV (12 bytes)] [Tag (16 bytes)] [Ciphertext (variable)]
    // The leading salt-length byte makes this self-describing regardless of
    // whether the salt was auto-generated (16 bytes) or supplied explicitly
    // (any length up to 255 bytes), so decryptValue() can always extract the
    // exact salt that was used without being told separately.
    $packed = chr(strlen($salt)) . $salt . $iv . $tag . $ciphertext;

    // 🔤 Return as base64 for safe storage in database TEXT/VARCHAR columns
    // @see https://www.php.net/manual/en/function.base64-encode.php
    return base64_encode($packed);
}

// ==============================================================================
// 🔓 Decrypt Value
// ==============================================================================

/**
 * 🔓 Decrypt an encrypted value
 *
 * Reverses the encryption process:
 * 1. Base64-decodes the stored value
 * 2. Extracts the embedded per-record salt, IV, auth tag, and ciphertext
 * 3. Derives the same key using HKDF with the master key + the EMBEDDED salt
 * 4. Decrypts and verifies integrity via GCM auth tag
 *
 * 🧂 F-006/G-010: because encryptValue() now embeds the per-record salt it
 * used directly in the ciphertext blob (length-prefixed), decryption is fully
 * self-describing from the master key alone - the salt always travels WITH
 * the ciphertext. The optional $salt parameter below is therefore NOT used
 * for key derivation (the embedded salt is always authoritative); it is kept
 * only so existing call signatures (and any caller passing one for
 * documentation/symmetry with encryptValue()) remain valid without change.
 *
 * @param string      $encrypted Base64-encoded encrypted value (as produced by encryptValue())
 * @param string|null $key       Encryption key (defaults to ENCRYPTION_KEY constant)
 * @param string|null $salt      Unused for key derivation (see note above) - retained for signature compatibility
 *
 * @return string The decrypted plaintext value
 *
 * @throws \RuntimeException If decryption fails (wrong key, tampered data, etc.)
 *
 * @see https://www.php.net/manual/en/function.openssl-decrypt.php
 *
 * @example
 *   $plaintext = decryptValue($encryptedValueFromDatabase);
 */
function decryptValue(string $encrypted, ?string $key = null, ?string $salt = null): string
{
    // 🔑 Use default keys if not provided
    if ($key !== null) {
        // Use the provided key
    } else {
        if (defined('ENCRYPTION_KEY')) {
            $key = ENCRYPTION_KEY;
        } else {
            $key = null;
        }
    }

    // ✅ Validate that the master key is available. The salt is no longer
    // required here - it is read from the ciphertext blob itself below.
    if (empty($key)) {
        throw new \RuntimeException(
            'Encryption key not configured. '
            . 'Define ENCRYPTION_KEY in _auth_keys/auth_creds.php'
        );
    }

    // 📦 Decode from base64
    $packed = base64_decode($encrypted, true);

    if ($packed === false) {
        throw new \RuntimeException('Failed to decode encrypted value (invalid base64)');
    }

    // 📐 Extract the embedded salt, IV, tag, and ciphertext from the packed data.
    // Format: [salt length (1 byte)] [salt] [IV (12 bytes)] [Tag (16 bytes)] [Ciphertext]
    // @see encryptValue() - the packing step this mirrors.
    if (strlen($packed) < 1) {
        throw new \RuntimeException('Encrypted data is too short (corrupted or invalid)');
    }

    $saltLength = ord($packed[0]);
    $ivLength   = openssl_cipher_iv_length(CUERCODE_CIPHER_ALGO);

    // Validate minimum total length: length-byte + salt + IV + Tag + at least 1 byte of ciphertext
    $minimumLength = 1 + $saltLength + $ivLength + CUERCODE_TAG_LENGTH + 1;

    if (strlen($packed) < $minimumLength) {
        throw new \RuntimeException('Encrypted data is too short (corrupted or invalid)');
    }

    $offset       = 1;
    $embeddedSalt = substr($packed, $offset, $saltLength);
    $offset      += $saltLength;
    $iv           = substr($packed, $offset, $ivLength);
    $offset      += $ivLength;
    $tag          = substr($packed, $offset, CUERCODE_TAG_LENGTH);
    $offset      += CUERCODE_TAG_LENGTH;
    $ciphertext   = substr($packed, $offset);

    // 🔑 Derive the same key using HKDF with the EMBEDDED per-record salt
    // (must match whatever salt encryptValue() actually used for this value).
    $derivedKey = hash_hkdf('sha256', $key, 32, 'cuercode-encryption', $embeddedSalt);

    if ($derivedKey === false) {
        throw new \RuntimeException('Key derivation (HKDF) failed');
    }

    // 🔓 Decrypt using AES-256-GCM
    // The GCM auth tag ensures the data hasn't been tampered with.
    // If the tag doesn't match, openssl_decrypt returns false.
    // @see https://www.php.net/manual/en/function.openssl-decrypt.php
    $plaintext = openssl_decrypt(
        $ciphertext,         // Encrypted data
        CUERCODE_CIPHER_ALGO,   // Cipher algorithm (aes-256-gcm)
        $derivedKey,         // Derived decryption key (same as encryption)
        OPENSSL_RAW_DATA,   // Input is raw binary
        $iv,                 // Initialisation Vector (extracted from packed data)
        $tag                 // GCM authentication tag (extracted from packed data)
    );

    if ($plaintext === false) {
        throw new \RuntimeException(
            'Decryption failed: data may be corrupted, tampered with, or encrypted with a different key. '
            . openssl_error_string()
        );
    }

    return $plaintext;
}

// ==============================================================================
// 🔑 API Key Functions
// ==============================================================================

/**
 * 🔑 Generate a new API key
 *
 * Creates a cryptographically secure random API key with a recognisable prefix.
 * Format: cuercode_[40 hex characters] (total 45 characters)
 *
 * @return string The generated API key (show to user once, then hash for storage)
 *
 * @see https://www.php.net/manual/en/function.random-bytes.php
 *
 * @example
 *   $rawKey = generateAPIKey();
 *   // Show $rawKey to user (they must save it - it won't be shown again)
 *   // Store hashAPIKey($rawKey) in the database
 */
function generateAPIKey(): string
{
    // 🎲 Generate 20 bytes of cryptographically secure random data
    // bin2hex converts to 40 hexadecimal characters
    // @see https://www.php.net/manual/en/function.random-bytes.php
    return 'cuercode_' . bin2hex(random_bytes(20));
}

/**
 * 🔐 Hash an API key for secure database storage
 *
 * Uses SHA-256 HMAC with the encryption key as the secret.
 * The raw API key is never stored - only this hash.
 *
 * @param string $apiKey The raw API key to hash
 *
 * @return string 64-character hex hash string
 *
 * @see https://www.php.net/manual/en/function.hash-hmac.php
 *
 * @example
 *   $hash = hashAPIKey('cuercode_abc123...');
 *   // Store $hash in tblAPIKeys.apiKeyHash
 */
function hashAPIKey(string $apiKey): string
{
    if (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY !== '') {
        $secret = ENCRYPTION_KEY;
    } else {
        throw new \RuntimeException(
            'ENCRYPTION_KEY not configured. Cannot hash API keys securely. '
            . 'Define ENCRYPTION_KEY in _auth_keys/auth_creds.php'
        );
    }

    // @see https://www.php.net/manual/en/function.hash-hmac.php
    return hash_hmac('sha256', $apiKey, $secret);
}

/**
 * 🔍 Get the prefix portion of an API key for identification
 *
 * Extracts the first 12 characters of the key (including "cuercode_" prefix)
 * for display and identification purposes without exposing the full key.
 *
 * @param string $apiKey The raw API key
 *
 * @return string The first 12 characters (e.g., "cuercode_abc1234")
 */
function getAPIKeyPrefix(string $apiKey): string
{
    return substr($apiKey, 0, 12);
}
