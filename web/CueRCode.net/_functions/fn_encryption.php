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
 * 🔒 Encrypt a plaintext value using AES-256-GCM with salt
 *
 * The encryption process:
 * 1. Derives a key from the master key + salt using HKDF
 * 2. Generates a random 12-byte IV (Initialisation Vector)
 * 3. Encrypts using AES-256-GCM which produces ciphertext + auth tag
 * 4. Packs IV + tag + ciphertext together
 * 5. Returns base64-encoded result for safe database storage
 *
 * @param string      $plaintext The value to encrypt
 * @param string|null $key       Encryption key (defaults to ENCRYPTION_KEY constant)
 * @param string|null $salt      Salt value (defaults to ENCRYPTION_SALT constant)
 *
 * @return string Base64-encoded encrypted value
 *
 * @throws \RuntimeException If encryption fails or keys are not configured
 *
 * @see https://www.php.net/manual/en/function.openssl-encrypt.php
 * @see https://www.php.net/manual/en/function.hash-hkdf.php
 *
 * @example
 *   $encrypted = encryptValue('my-secret-api-key');
 *   // Store $encrypted in database
 */
function encryptValue(string $plaintext, ?string $key = null, ?string $salt = null): string
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
    if ($salt !== null) {
        // Use the provided salt
    } else {
        if (defined('ENCRYPTION_SALT')) {
            $salt = ENCRYPTION_SALT;
        } else {
            $salt = null;
        }
    }

    // ✅ Validate that keys are available
    if (empty($key) || empty($salt)) {
        throw new \RuntimeException(
            'Encryption keys not configured. '
            . 'Define ENCRYPTION_KEY and ENCRYPTION_SALT in _auth_keys/auth_creds.php'
        );
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

    // 📦 Pack the IV, tag, and ciphertext together
    // Format: [IV (12 bytes)] [Tag (16 bytes)] [Ciphertext (variable)]
    // This allows decryption to extract all components from a single string.
    $packed = $iv . $tag . $ciphertext;

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
 * 2. Extracts IV, auth tag, and ciphertext
 * 3. Derives the same key using HKDF with the same master key + salt
 * 4. Decrypts and verifies integrity via GCM auth tag
 *
 * @param string      $encrypted Base64-encoded encrypted value
 * @param string|null $key       Encryption key (defaults to ENCRYPTION_KEY constant)
 * @param string|null $salt      Salt value (defaults to ENCRYPTION_SALT constant)
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
    if ($salt !== null) {
        // Use the provided salt
    } else {
        if (defined('ENCRYPTION_SALT')) {
            $salt = ENCRYPTION_SALT;
        } else {
            $salt = null;
        }
    }

    // ✅ Validate that keys are available
    if (empty($key) || empty($salt)) {
        throw new \RuntimeException(
            'Encryption keys not configured. '
            . 'Define ENCRYPTION_KEY and ENCRYPTION_SALT in _auth_keys/auth_creds.php'
        );
    }

    // 🔑 Derive the same key using HKDF (must match encryption)
    $derivedKey = hash_hkdf('sha256', $key, 32, 'cuercode-encryption', $salt);

    if ($derivedKey === false) {
        throw new \RuntimeException('Key derivation (HKDF) failed');
    }

    // 📦 Decode from base64
    $packed = base64_decode($encrypted, true);

    if ($packed === false) {
        throw new \RuntimeException('Failed to decode encrypted value (invalid base64)');
    }

    // 📐 Extract IV, tag, and ciphertext from the packed data
    $ivLength = openssl_cipher_iv_length(CUERCODE_CIPHER_ALGO);

    // Validate minimum length: IV + Tag + at least 1 byte of ciphertext
    if (strlen($packed) < ($ivLength + CUERCODE_TAG_LENGTH + 1)) {
        throw new \RuntimeException('Encrypted data is too short (corrupted or invalid)');
    }

    $iv         = substr($packed, 0, $ivLength);
    $tag        = substr($packed, $ivLength, CUERCODE_TAG_LENGTH);
    $ciphertext = substr($packed, $ivLength + CUERCODE_TAG_LENGTH);

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
