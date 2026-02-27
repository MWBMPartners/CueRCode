<?php
/**
 * ==============================================================================
 * 📦 CueRCode - QR Code Payload Builders
 * ==============================================================================
 *
 * Provides functions for building QR code data payloads (the actual content
 * encoded into the QR code) based on the QR code type selected by the user.
 *
 * The main dispatcher function buildQRPayload() looks up the QR code type
 * in tblQRCodeTypes and either uses the payloadTemplate column (simple
 * placeholder replacement) or delegates to a type-specific builder function
 * for complex payloads (vCard, iCalendar, Zoom, Wi-Fi, etc.).
 *
 * Supported QR code types (21 total):
 *   - url, text, email, phone, sms, whatsapp, zoom, geo, vcard, calendar,
 *     paypal, bitcoin, mp3, video, pdf, image, appstore, facebook, youtube,
 *     wifi, linkedin
 *
 * @package     CueRCode
 * @subpackage  QR Generation
 * @version     1.0.0
 * @since       Phase 2
 * @see         https://www.php.net/manual/en/function.urlencode.php
 * @see         https://www.php.net/manual/en/function.rawurlencode.php
 * @see         https://datatracker.ietf.org/doc/html/rfc6350 vCard 3.0 specification
 * @see         https://datatracker.ietf.org/doc/html/rfc5545 iCalendar specification
 * @see         https://github.com/zxing/zxing/wiki/Barcode-Contents Wi-Fi / SMS / etc. formats
 */

// ==============================================================================
// 🚫 Direct access protection
// ==============================================================================
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $scriptFilenameValue = $_SERVER['SCRIPT_FILENAME'];
} else {
    $scriptFilenameValue = '';
}
if (basename($scriptFilenameValue) === basename(__FILE__)) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied.');
}

// ==============================================================================
// 📦 buildQRPayload() - Main Payload Dispatcher
// ==============================================================================

/**
 * 📦 Build the QR code payload string for a given type and input data
 *
 * This is the main dispatcher function. It:
 *   1. Looks up the QR code type in tblQRCodeTypes by typeName
 *   2. If a payloadTemplate exists, performs {placeholder} replacement
 *   3. For complex types (vcard, calendar, zoom), delegates to specific builders
 *   4. Falls back to type-specific builder functions
 *
 * @param string $typeName  The QR code type name (e.g., 'url', 'vcard', 'wifi')
 * @param array  $inputData Associative array of user-provided input fields
 *
 * @return string The complete payload string to encode into the QR code
 *
 * @throws \InvalidArgumentException If the type is unknown or payload is empty
 *
 * @see https://www.php.net/manual/en/function.str-replace.php
 * @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
 *
 * @example
 *   // Simple URL payload
 *   $payload = buildQRPayload('url', ['url' => 'https://example.com']);
 *   // Returns: 'https://example.com'
 *
 *   // vCard payload
 *   $payload = buildQRPayload('vcard', [
 *       'first_name' => 'John',
 *       'last_name'  => 'Doe',
 *       'email'      => 'john@example.com',
 *   ]);
 *   // Returns: 'BEGIN:VCARD\nVERSION:3.0\n...'
 */
function buildQRPayload(string $typeName, array $inputData): string
{
    // 📌 Sanitise the type name to prevent injection
    // @see https://www.php.net/manual/en/function.strtolower.php
    $typeName = strtolower(trim($typeName));

    // ❌ Validate that a type name was provided
    if ($typeName === '') {
        throw new \InvalidArgumentException('QR code type name cannot be empty.');
    }

    // ==================================================================
    // 🗄️ Look up the type in tblQRCodeTypes for its payloadTemplate
    // @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
    // ==================================================================
    $payloadTemplate = null;

    if (function_exists('dbQuery')) {
        try {
            $stmt = dbQuery(
                "SELECT `typeID`, `typeName`, `payloadTemplate` "
                . "FROM `tblQRCodeTypes` "
                . "WHERE LOWER(`typeName`) = ? "
                . "LIMIT 1",
                's',
                [$typeName]
            );

            $result = $stmt->get_result();
            $typeRow = $result->fetch_assoc();
            $stmt->close();

            if ($typeRow !== null && !empty($typeRow['payloadTemplate'])) {
                $payloadTemplate = $typeRow['payloadTemplate'];
            }
        } catch (\Throwable $e) {
            // ⚠️ Database lookup failed - continue with builder functions
            // The builder functions do not require a database record to exist.
            if (function_exists('logError')) {
                logError(
                    'warning',
                    'QR type lookup failed',
                    'Type: ' . $typeName . ' | ' . $e->getMessage()
                );
            }
        }
    }

    // ==================================================================
    // 🔄 Complex types that always require a dedicated builder function
    // These types generate multi-line structured payloads (vCard, iCalendar, etc.)
    // that cannot be adequately represented by simple template replacement.
    // ==================================================================
    $complexTypes = ['vcard', 'calendar', 'zoom', 'wifi'];

    if (in_array($typeName, $complexTypes, true)) {
        return buildPayloadByType($typeName, $inputData);
    }

    // ==================================================================
    // 📝 Template-based payload generation
    // If the database provides a payloadTemplate, replace {placeholders}
    // with the corresponding input data values.
    // @see https://www.php.net/manual/en/function.str-replace.php
    // ==================================================================
    if ($payloadTemplate !== null) {
        $payload = $payloadTemplate;

        // 🔄 Replace each {placeholder} with the corresponding input value
        foreach ($inputData as $key => $value) {
            // 📌 Ensure the value is a string for replacement
            if (is_string($value)) {
                $replacementValue = $value;
            } else {
                $replacementValue = (string) $value;
            }

            // 📌 Replace the {key} placeholder with the actual value
            $payload = str_replace('{' . $key . '}', $replacementValue, $payload);
        }

        // 🗑️ Remove any unreplaced placeholders (fields the user didn't provide)
        // @see https://www.php.net/manual/en/function.preg-replace.php
        $payload = preg_replace('/\{[a-zA-Z0-9_]+\}/', '', $payload);

        // 📌 Trim any excess whitespace from the final payload
        $payload = trim($payload);

        if ($payload !== '') {
            return $payload;
        }
    }

    // ==================================================================
    // 🔧 Fallback: use type-specific builder function
    // ==================================================================
    return buildPayloadByType($typeName, $inputData);
}

// ==============================================================================
// 🔧 buildPayloadByType() - Route to type-specific builder
// ==============================================================================

/**
 * 🔧 Route to the appropriate type-specific payload builder function
 *
 * Maps the type name to a buildPayload_{type}() function and calls it.
 * Throws an exception if no builder function exists for the given type.
 *
 * @param string $typeName  The QR code type name
 * @param array  $inputData The user-provided input data
 *
 * @return string The generated payload string
 *
 * @throws \InvalidArgumentException If no builder exists for the type
 *
 * @see https://www.php.net/manual/en/function.function-exists.php
 */
function buildPayloadByType(string $typeName, array $inputData): string
{
    // 📌 Construct the builder function name from the type
    // Convention: buildPayload_url, buildPayload_vcard, etc.
    $builderFunction = 'buildPayload_' . $typeName;

    // ✅ Check that the builder function exists
    // @see https://www.php.net/manual/en/function.function-exists.php
    if (function_exists($builderFunction)) {
        $payload = $builderFunction($inputData);

        // ❌ Validate that the builder returned a non-empty payload
        if (is_string($payload) && trim($payload) !== '') {
            return $payload;
        }

        throw new \InvalidArgumentException(
            'Payload builder for type "' . $typeName . '" returned an empty result. '
            . 'Ensure all required input fields are provided.'
        );
    }

    // ❌ No builder function found for this type
    throw new \InvalidArgumentException(
        'Unknown QR code type: "' . $typeName . '". '
        . 'No payload builder function "' . $builderFunction . '()" found.'
    );
}

// ==============================================================================
// 🌐 buildPayload_url() - URL payload
// ==============================================================================

/**
 * 🌐 Build a URL payload for QR code encoding
 *
 * Generates a simple URL string. Prepends 'https://' if no scheme is present
 * to ensure the QR code scanner recognises it as a web link.
 *
 * @param array $inputData Must contain 'url' key
 *
 * @return string The URL payload string
 *
 * @see https://www.php.net/manual/en/function.parse-url.php
 * @see https://datatracker.ietf.org/doc/html/rfc3986
 *
 * @example
 *   buildPayload_url(['url' => 'https://example.com']);
 *   // Returns: 'https://example.com'
 *
 *   buildPayload_url(['url' => 'example.com']);
 *   // Returns: 'https://example.com'
 */
function buildPayload_url(array $inputData): string
{
    // 📌 Extract the URL from input data
    if (isset($inputData['url'])) {
        $urlValue = $inputData['url'];
    } else {
        $urlValue = '';
    }
    $url = trim($urlValue);

    // ❌ Validate that a URL was provided
    if ($url === '') {
        throw new \InvalidArgumentException('URL is required for URL-type QR codes.');
    }

    // 🔍 Check if the URL already has a scheme (http://, https://, ftp://, etc.)
    // If not, prepend 'https://' as the default secure scheme.
    // @see https://www.php.net/manual/en/function.parse-url.php
    $parsedScheme = parse_url($url, PHP_URL_SCHEME);

    if ($parsedScheme === null || $parsedScheme === false) {
        $url = 'https://' . $url;
    }

    return $url;
}

// ==============================================================================
// 📝 buildPayload_text() - Plain text payload
// ==============================================================================

/**
 * 📝 Build a plain text payload for QR code encoding
 *
 * Encodes arbitrary text content into the QR code. No special formatting
 * is applied; the text is used as-is.
 *
 * @param array $inputData Must contain 'text' key
 *
 * @return string The text payload string
 *
 * @example
 *   buildPayload_text(['text' => 'Hello, World!']);
 *   // Returns: 'Hello, World!'
 */
function buildPayload_text(array $inputData): string
{
    // 📌 Extract the text from input data
    if (isset($inputData['text'])) {
        $text = $inputData['text'];
    } else {
        $text = '';
    }

    // ❌ Validate that text was provided
    if (trim($text) === '') {
        throw new \InvalidArgumentException('Text content is required for text-type QR codes.');
    }

    return $text;
}

// ==============================================================================
// 📧 buildPayload_email() - Email (mailto:) payload
// ==============================================================================

/**
 * 📧 Build a mailto: payload for QR code encoding
 *
 * Generates a mailto: URI with optional subject and body parameters.
 * Values are URL-encoded to handle special characters correctly.
 *
 * Format: mailto:{email}?subject={subject}&body={body}
 *
 * @param array $inputData Must contain 'email'; optional 'subject', 'body'
 *
 * @return string The mailto: URI string
 *
 * @see https://www.php.net/manual/en/function.rawurlencode.php
 * @see https://datatracker.ietf.org/doc/html/rfc6068 mailto: URI scheme
 *
 * @example
 *   buildPayload_email([
 *       'email'   => 'user@example.com',
 *       'subject' => 'Hello',
 *       'body'    => 'How are you?'
 *   ]);
 *   // Returns: 'mailto:user@example.com?subject=Hello&body=How%20are%20you%3F'
 */
function buildPayload_email(array $inputData): string
{
    // 📌 Extract email address (required)
    if (isset($inputData['email'])) {
        $emailValue = $inputData['email'];
    } else {
        $emailValue = '';
    }
    $email = trim($emailValue);

    // ❌ Validate that an email was provided
    if ($email === '') {
        throw new \InvalidArgumentException('Email address is required for email-type QR codes.');
    }

    // 📌 Build the mailto: URI
    // @see https://datatracker.ietf.org/doc/html/rfc6068
    $payload = 'mailto:' . $email;

    // 📌 Build query parameters for subject and body
    $queryParams = [];

    if (isset($inputData['subject'])) {
        $subject = $inputData['subject'];
    } else {
        $subject = '';
    }
    if (trim($subject) !== '') {
        // 📌 URL-encode the subject line for safe URI inclusion
        // @see https://www.php.net/manual/en/function.rawurlencode.php
        $queryParams[] = 'subject=' . rawurlencode($subject);
    }

    if (isset($inputData['body'])) {
        $body = $inputData['body'];
    } else {
        $body = '';
    }
    if (trim($body) !== '') {
        // 📌 URL-encode the body text
        $queryParams[] = 'body=' . rawurlencode($body);
    }

    // 📌 Append query parameters if any exist
    if (!empty($queryParams)) {
        $payload .= '?' . implode('&', $queryParams);
    }

    return $payload;
}

// ==============================================================================
// 📞 buildPayload_phone() - Phone (tel:) payload
// ==============================================================================

/**
 * 📞 Build a tel: payload for QR code encoding
 *
 * Generates a tel: URI for phone number dialling. The phone number is
 * cleaned to remove formatting characters (spaces, hyphens, parentheses)
 * while preserving the leading + for international format.
 *
 * Format: tel:{phone_number}
 *
 * @param array $inputData Must contain 'phone' key
 *
 * @return string The tel: URI string
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3966 tel: URI scheme
 *
 * @example
 *   buildPayload_phone(['phone' => '+44 20 1234 5678']);
 *   // Returns: 'tel:+442012345678'
 */
function buildPayload_phone(array $inputData): string
{
    // 📌 Extract the phone number (required)
    if (isset($inputData['phone'])) {
        $phoneValue = $inputData['phone'];
    } else {
        $phoneValue = '';
    }
    $phone = trim($phoneValue);

    // ❌ Validate that a phone number was provided
    if ($phone === '') {
        throw new \InvalidArgumentException('Phone number is required for phone-type QR codes.');
    }

    // 📌 Clean the phone number: keep digits and leading +
    // Remove spaces, hyphens, parentheses, and dots for a clean tel: URI.
    // @see https://www.php.net/manual/en/function.preg-replace.php
    $cleanPhone = preg_replace('/[^0-9+]/', '', $phone);

    return 'tel:' . $cleanPhone;
}

// ==============================================================================
// 💬 buildPayload_sms() - SMS payload
// ==============================================================================

/**
 * 💬 Build an SMS payload for QR code encoding
 *
 * Generates an smsto: URI for sending SMS messages. The format follows
 * the commonly supported convention used by most QR code scanners.
 *
 * Format: smsto:{phone}:{message}
 *
 * @param array $inputData Must contain 'phone'; optional 'message'
 *
 * @return string The smsto: URI string
 *
 * @see https://github.com/zxing/zxing/wiki/Barcode-Contents#sms SMS QR format
 *
 * @example
 *   buildPayload_sms(['phone' => '+1234567890', 'message' => 'Hello!']);
 *   // Returns: 'smsto:+1234567890:Hello!'
 */
function buildPayload_sms(array $inputData): string
{
    // 📌 Extract the phone number (required)
    if (isset($inputData['phone'])) {
        $smsPhoneValue = $inputData['phone'];
    } else {
        $smsPhoneValue = '';
    }
    $phone = trim($smsPhoneValue);

    // ❌ Validate that a phone number was provided
    if ($phone === '') {
        throw new \InvalidArgumentException('Phone number is required for SMS-type QR codes.');
    }

    // 📌 Clean the phone number: keep digits and leading +
    // @see https://www.php.net/manual/en/function.preg-replace.php
    $cleanPhone = preg_replace('/[^0-9+]/', '', $phone);

    // 📌 Build the smsto: URI
    $payload = 'smsto:' . $cleanPhone;

    // 📌 Append the message if provided
    if (isset($inputData['message'])) {
        $message = $inputData['message'];
    } else {
        $message = '';
    }
    if (trim($message) !== '') {
        $payload .= ':' . $message;
    }

    return $payload;
}

// ==============================================================================
// 📱 buildPayload_whatsapp() - WhatsApp payload
// ==============================================================================

/**
 * 📱 Build a WhatsApp deep-link payload for QR code encoding
 *
 * Generates a WhatsApp API URL that opens a chat with the specified phone
 * number and optional pre-filled message. Uses the wa.me short URL format
 * which is supported across all platforms.
 *
 * Format: https://wa.me/{phone}?text={message}
 *
 * @param array $inputData Must contain 'phone'; optional 'message'
 *
 * @return string The WhatsApp URL string
 *
 * @see https://faq.whatsapp.com/5913398998672934 WhatsApp Click to Chat
 *
 * @example
 *   buildPayload_whatsapp(['phone' => '+1234567890', 'message' => 'Hi there!']);
 *   // Returns: 'https://wa.me/1234567890?text=Hi%20there%21'
 */
function buildPayload_whatsapp(array $inputData): string
{
    // 📌 Extract the phone number (required)
    if (isset($inputData['phone'])) {
        $waPhoneValue = $inputData['phone'];
    } else {
        $waPhoneValue = '';
    }
    $phone = trim($waPhoneValue);

    // ❌ Validate that a phone number was provided
    if ($phone === '') {
        throw new \InvalidArgumentException('Phone number is required for WhatsApp-type QR codes.');
    }

    // 📌 Clean the phone number: digits only (wa.me does not use the + prefix)
    // @see https://www.php.net/manual/en/function.preg-replace.php
    $cleanPhone = preg_replace('/[^0-9]/', '', $phone);

    // 📌 Build the WhatsApp URL
    $payload = 'https://wa.me/' . $cleanPhone;

    // 📌 Append pre-filled message if provided
    // @see https://www.php.net/manual/en/function.rawurlencode.php
    if (isset($inputData['message'])) {
        $message = $inputData['message'];
    } else {
        $message = '';
    }
    if (trim($message) !== '') {
        $payload .= '?text=' . rawurlencode($message);
    }

    return $payload;
}

// ==============================================================================
// 🎥 buildPayload_zoom() - Zoom meeting payload
// ==============================================================================

/**
 * 🎥 Build a Zoom meeting payload for QR code encoding
 *
 * If a full meeting_url is provided, uses that directly. Otherwise,
 * constructs a zoommtg:// deep-link URL from the meeting_id and optional
 * password, which opens the Zoom app directly.
 *
 * Formats:
 *   - URL mode: https://zoom.us/j/{meeting_id}?pwd={password}
 *   - Deep link: zoommtg://zoom.us/join?confno={meeting_id}&pwd={password}
 *
 * @param array $inputData May contain 'meeting_url', 'meeting_id', 'password'
 *
 * @return string The Zoom meeting URL or deep link
 *
 * @see https://developers.zoom.us/docs/meeting-sdk/ Zoom meeting URL format
 *
 * @example
 *   // Using a direct URL
 *   buildPayload_zoom(['meeting_url' => 'https://zoom.us/j/123456789']);
 *   // Returns: 'https://zoom.us/j/123456789'
 *
 *   // Using meeting ID and password
 *   buildPayload_zoom(['meeting_id' => '123456789', 'password' => 'abc123']);
 *   // Returns: 'zoommtg://zoom.us/join?confno=123456789&pwd=abc123'
 */
function buildPayload_zoom(array $inputData): string
{
    // 📌 Option 1: If a full meeting URL is provided, use it directly
    if (isset($inputData['meeting_url'])) {
        $meetingUrlValue = $inputData['meeting_url'];
    } else {
        $meetingUrlValue = '';
    }
    $meetingUrl = trim($meetingUrlValue);

    if ($meetingUrl !== '') {
        // 🔍 Ensure the URL has a scheme
        // @see https://www.php.net/manual/en/function.parse-url.php
        $parsedScheme = parse_url($meetingUrl, PHP_URL_SCHEME);
        if ($parsedScheme === null || $parsedScheme === false) {
            $meetingUrl = 'https://' . $meetingUrl;
        }
        return $meetingUrl;
    }

    // 📌 Option 2: Construct zoommtg:// deep link from meeting ID
    if (isset($inputData['meeting_id'])) {
        $meetingIdValue = $inputData['meeting_id'];
    } else {
        $meetingIdValue = '';
    }
    $meetingId = trim($meetingIdValue);

    // ❌ Validate that at least a meeting URL or meeting ID was provided
    if ($meetingId === '') {
        throw new \InvalidArgumentException(
            'Either a meeting URL or meeting ID is required for Zoom-type QR codes.'
        );
    }

    // 📌 Clean the meeting ID: digits only
    // @see https://www.php.net/manual/en/function.preg-replace.php
    $cleanMeetingId = preg_replace('/[^0-9]/', '', $meetingId);

    // 📌 Build the zoommtg:// deep link
    // This format opens the Zoom app directly on mobile and desktop.
    $payload = 'zoommtg://zoom.us/join?confno=' . rawurlencode($cleanMeetingId);

    // 📌 Append password if provided
    // @see https://www.php.net/manual/en/function.rawurlencode.php
    if (isset($inputData['password'])) {
        $password = $inputData['password'];
    } else {
        $password = '';
    }
    if (trim($password) !== '') {
        $payload .= '&pwd=' . rawurlencode($password);
    }

    return $payload;
}

// ==============================================================================
// 📍 buildPayload_geo() - Geographic coordinates payload
// ==============================================================================

/**
 * 📍 Build a geo: URI payload for QR code encoding
 *
 * Generates a geo: URI that opens the device's map application at the
 * specified latitude and longitude coordinates.
 *
 * Format: geo:{latitude},{longitude}
 *
 * @param array $inputData Must contain 'latitude' and 'longitude'
 *
 * @return string The geo: URI string
 *
 * @see https://datatracker.ietf.org/doc/html/rfc5870 geo: URI scheme
 *
 * @example
 *   buildPayload_geo(['latitude' => '51.5074', 'longitude' => '-0.1278']);
 *   // Returns: 'geo:51.5074,-0.1278'
 */
function buildPayload_geo(array $inputData): string
{
    // 📌 Extract latitude and longitude (both required)
    if (isset($inputData['latitude'])) {
        $latitudeValue = $inputData['latitude'];
    } else {
        $latitudeValue = '';
    }
    $latitude = trim($latitudeValue);

    if (isset($inputData['longitude'])) {
        $longitudeValue = $inputData['longitude'];
    } else {
        $longitudeValue = '';
    }
    $longitude = trim($longitudeValue);

    // ❌ Validate that both coordinates are provided
    if ($latitude === '' || $longitude === '') {
        throw new \InvalidArgumentException(
            'Both latitude and longitude are required for geo-type QR codes.'
        );
    }

    // 📌 Validate that coordinates are numeric
    // @see https://www.php.net/manual/en/function.is-numeric.php
    if (!is_numeric($latitude) || !is_numeric($longitude)) {
        throw new \InvalidArgumentException(
            'Latitude and longitude must be valid numeric values.'
        );
    }

    // 📌 Build the geo: URI
    // @see https://datatracker.ietf.org/doc/html/rfc5870
    return 'geo:' . $latitude . ',' . $longitude;
}

// ==============================================================================
// 👤 buildPayload_vcard() - vCard 3.0 payload
// ==============================================================================

/**
 * 👤 Build a vCard 3.0 payload for QR code encoding
 *
 * Generates a complete vCard 3.0 contact card that can be scanned and
 * imported into the device's contacts app. Includes name, organisation,
 * title, phone numbers, email, URL, address, and notes.
 *
 * vCard 3.0 format (RFC 2426) is used for maximum compatibility across
 * QR code scanner apps on both iOS and Android.
 *
 * @param array $inputData Contact fields (all optional except first_name or last_name):
 *   - first_name, last_name, organisation, title
 *   - phone_work, phone_cell, email, url
 *   - street, city, state, zip, country
 *   - note
 *
 * @return string The complete vCard 3.0 payload string
 *
 * @see https://datatracker.ietf.org/doc/html/rfc2426 vCard 3.0 specification
 * @see https://www.php.net/manual/en/function.implode.php
 *
 * @example
 *   buildPayload_vcard([
 *       'first_name'   => 'John',
 *       'last_name'    => 'Doe',
 *       'organisation' => 'ACME Corp',
 *       'email'        => 'john@acme.com',
 *       'phone_cell'   => '+1-555-123-4567',
 *   ]);
 *   // Returns a multi-line vCard 3.0 string
 */
function buildPayload_vcard(array $inputData): string
{
    // 📌 Extract contact fields with defaults
    if (isset($inputData['first_name'])) {
        $firstNameValue = $inputData['first_name'];
    } else {
        $firstNameValue = '';
    }
    $firstName = trim($firstNameValue);

    if (isset($inputData['last_name'])) {
        $lastNameValue = $inputData['last_name'];
    } else {
        $lastNameValue = '';
    }
    $lastName = trim($lastNameValue);

    if (isset($inputData['organisation'])) {
        $organisationValue = $inputData['organisation'];
    } elseif (isset($inputData['organization'])) {
        $organisationValue = $inputData['organization'];
    } else {
        $organisationValue = '';
    }
    $organisation = trim($organisationValue);

    if (isset($inputData['title'])) {
        $titleValue = $inputData['title'];
    } else {
        $titleValue = '';
    }
    $title = trim($titleValue);

    if (isset($inputData['phone_work'])) {
        $phoneWorkValue = $inputData['phone_work'];
    } else {
        $phoneWorkValue = '';
    }
    $phoneWork = trim($phoneWorkValue);

    if (isset($inputData['phone_cell'])) {
        $phoneCellValue = $inputData['phone_cell'];
    } else {
        $phoneCellValue = '';
    }
    $phoneCell = trim($phoneCellValue);

    if (isset($inputData['email'])) {
        $vcardEmailValue = $inputData['email'];
    } else {
        $vcardEmailValue = '';
    }
    $email = trim($vcardEmailValue);

    if (isset($inputData['url'])) {
        $vcardUrlValue = $inputData['url'];
    } else {
        $vcardUrlValue = '';
    }
    $url = trim($vcardUrlValue);

    if (isset($inputData['street'])) {
        $streetValue = $inputData['street'];
    } else {
        $streetValue = '';
    }
    $street = trim($streetValue);

    if (isset($inputData['city'])) {
        $cityValue = $inputData['city'];
    } else {
        $cityValue = '';
    }
    $city = trim($cityValue);

    if (isset($inputData['state'])) {
        $stateValue = $inputData['state'];
    } else {
        $stateValue = '';
    }
    $state = trim($stateValue);

    if (isset($inputData['zip'])) {
        $zipValue = $inputData['zip'];
    } elseif (isset($inputData['postal_code'])) {
        $zipValue = $inputData['postal_code'];
    } else {
        $zipValue = '';
    }
    $zip = trim($zipValue);

    if (isset($inputData['country'])) {
        $countryValue = $inputData['country'];
    } else {
        $countryValue = '';
    }
    $country = trim($countryValue);

    if (isset($inputData['note'])) {
        $noteValue = $inputData['note'];
    } else {
        $noteValue = '';
    }
    $note = trim($noteValue);

    // ❌ Validate that at least a first name or last name is provided
    if ($firstName === '' && $lastName === '') {
        throw new \InvalidArgumentException(
            'At least a first name or last name is required for vCard-type QR codes.'
        );
    }

    // 📌 Build the full display name (FN property)
    // @see https://datatracker.ietf.org/doc/html/rfc2426#section-3.1.1
    $fullName = trim($firstName . ' ' . $lastName);

    // ==================================================================
    // 📋 Construct the vCard 3.0 payload
    // Each property is on its own line, separated by \n (line feed).
    // vCard uses CRLF per spec, but \n is widely compatible with scanners.
    // @see https://datatracker.ietf.org/doc/html/rfc2426
    // ==================================================================
    $lines = [];

    // 📌 vCard header - mandatory
    $lines[] = 'BEGIN:VCARD';
    $lines[] = 'VERSION:3.0';

    // 📌 N property: structured name (Last;First;Middle;Prefix;Suffix)
    // @see https://datatracker.ietf.org/doc/html/rfc2426#section-3.1.2
    $lines[] = 'N:' . vcardEscape($lastName) . ';' . vcardEscape($firstName) . ';;;';

    // 📌 FN property: formatted (display) name - mandatory in vCard 3.0
    // @see https://datatracker.ietf.org/doc/html/rfc2426#section-3.1.1
    $lines[] = 'FN:' . vcardEscape($fullName);

    // 📌 ORG property: organisation name
    if ($organisation !== '') {
        $lines[] = 'ORG:' . vcardEscape($organisation);
    }

    // 📌 TITLE property: job title
    if ($title !== '') {
        $lines[] = 'TITLE:' . vcardEscape($title);
    }

    // 📌 TEL property: work phone number
    // TYPE=WORK indicates a work number per vCard 3.0 spec
    if ($phoneWork !== '') {
        $lines[] = 'TEL;TYPE=WORK:' . vcardEscape($phoneWork);
    }

    // 📌 TEL property: cell/mobile phone number
    // TYPE=CELL indicates a mobile number
    if ($phoneCell !== '') {
        $lines[] = 'TEL;TYPE=CELL:' . vcardEscape($phoneCell);
    }

    // 📌 EMAIL property: email address
    if ($email !== '') {
        $lines[] = 'EMAIL:' . vcardEscape($email);
    }

    // 📌 URL property: website
    if ($url !== '') {
        $lines[] = 'URL:' . vcardEscape($url);
    }

    // 📌 ADR property: structured address (PO Box;Extended;Street;City;State;ZIP;Country)
    // TYPE=WORK indicates a work address
    // @see https://datatracker.ietf.org/doc/html/rfc2426#section-3.2.1
    if ($street !== '' || $city !== '' || $state !== '' || $zip !== '' || $country !== '') {
        $lines[] = 'ADR;TYPE=WORK:;;'
            . vcardEscape($street) . ';'
            . vcardEscape($city) . ';'
            . vcardEscape($state) . ';'
            . vcardEscape($zip) . ';'
            . vcardEscape($country);
    }

    // 📌 NOTE property: additional notes
    if ($note !== '') {
        $lines[] = 'NOTE:' . vcardEscape($note);
    }

    // 📌 vCard footer - mandatory
    $lines[] = 'END:VCARD';

    // 📌 Join lines with newline character
    // @see https://www.php.net/manual/en/function.implode.php
    return implode("\n", $lines);
}

// ==============================================================================
// 🔧 vcardEscape() - Escape special characters in vCard values
// ==============================================================================

/**
 * 🔧 Escape special characters in vCard property values
 *
 * vCard 3.0 requires that certain characters be escaped with a backslash:
 *   - Backslash (\) -> \\
 *   - Semicolon (;) -> \;
 *   - Comma (,) -> \,
 *   - Newline -> \n
 *
 * @param string $value The raw value to escape
 *
 * @return string The escaped value safe for vCard inclusion
 *
 * @see https://datatracker.ietf.org/doc/html/rfc2426#section-2.4.2
 * @see https://www.php.net/manual/en/function.str-replace.php
 */
function vcardEscape(string $value): string
{
    // 📌 Order matters: escape backslash first to prevent double-escaping
    // @see https://www.php.net/manual/en/function.str-replace.php
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace(';', '\\;', $value);
    $value = str_replace(',', '\\,', $value);
    $value = str_replace("\n", '\\n', $value);
    $value = str_replace("\r", '', $value);

    return $value;
}

// ==============================================================================
// 📅 buildPayload_calendar() - iCalendar VEVENT payload
// ==============================================================================

/**
 * 📅 Build an iCalendar VEVENT payload for QR code encoding
 *
 * Generates a VCALENDAR/VEVENT payload that can be scanned to add an
 * event to the device's calendar app. Follows the iCalendar specification
 * (RFC 5545).
 *
 * Date/time values should be provided in ISO 8601 format (e.g., 2026-03-15T10:00:00)
 * and are converted to iCalendar format (YYYYMMDDTHHmmss).
 *
 * @param array $inputData Required: 'title', 'start_date'; Optional: 'end_date', 'location', 'description'
 *
 * @return string The iCalendar VEVENT payload string
 *
 * @see https://datatracker.ietf.org/doc/html/rfc5545 iCalendar specification
 * @see https://www.php.net/manual/en/function.date.php
 * @see https://www.php.net/manual/en/function.strtotime.php
 *
 * @example
 *   buildPayload_calendar([
 *       'title'       => 'Team Meeting',
 *       'start_date'  => '2026-03-15T10:00:00',
 *       'end_date'    => '2026-03-15T11:00:00',
 *       'location'    => 'Conference Room A',
 *       'description' => 'Weekly team sync',
 *   ]);
 */
function buildPayload_calendar(array $inputData): string
{
    // 📌 Extract event fields
    if (isset($inputData['title'])) {
        $calTitleValue = $inputData['title'];
    } elseif (isset($inputData['summary'])) {
        $calTitleValue = $inputData['summary'];
    } else {
        $calTitleValue = '';
    }
    $title = trim($calTitleValue);

    if (isset($inputData['start_date'])) {
        $startDateValue = $inputData['start_date'];
    } elseif (isset($inputData['start'])) {
        $startDateValue = $inputData['start'];
    } else {
        $startDateValue = '';
    }
    $startDate = trim($startDateValue);

    if (isset($inputData['end_date'])) {
        $endDateValue = $inputData['end_date'];
    } elseif (isset($inputData['end'])) {
        $endDateValue = $inputData['end'];
    } else {
        $endDateValue = '';
    }
    $endDate = trim($endDateValue);

    if (isset($inputData['location'])) {
        $locationValue = $inputData['location'];
    } else {
        $locationValue = '';
    }
    $location = trim($locationValue);

    if (isset($inputData['description'])) {
        $descriptionValue = $inputData['description'];
    } else {
        $descriptionValue = '';
    }
    $description = trim($descriptionValue);

    // ❌ Validate required fields
    if ($title === '') {
        throw new \InvalidArgumentException('Event title is required for calendar-type QR codes.');
    }

    if ($startDate === '') {
        throw new \InvalidArgumentException('Start date is required for calendar-type QR codes.');
    }

    // 📌 Convert date strings to iCalendar format (YYYYMMDDTHHmmss)
    // @see https://www.php.net/manual/en/function.strtotime.php
    // @see https://www.php.net/manual/en/function.date.php
    $startTimestamp = strtotime($startDate);

    if ($startTimestamp === false) {
        throw new \InvalidArgumentException(
            'Invalid start date format: "' . $startDate . '". Use ISO 8601 format (e.g., 2026-03-15T10:00:00).'
        );
    }

    // 📌 Format start date/time for iCalendar
    $icalStart = date('Ymd\THis', $startTimestamp);

    // 📌 Format end date/time (defaults to 1 hour after start if not provided)
    if ($endDate !== '') {
        $endTimestamp = strtotime($endDate);
        if ($endTimestamp === false) {
            throw new \InvalidArgumentException(
                'Invalid end date format: "' . $endDate . '". Use ISO 8601 format.'
            );
        }
        $icalEnd = date('Ymd\THis', $endTimestamp);
    } else {
        // 📌 Default: event lasts 1 hour
        $icalEnd = date('Ymd\THis', $startTimestamp + 3600);
    }

    // ==================================================================
    // 📋 Construct the iCalendar payload
    // @see https://datatracker.ietf.org/doc/html/rfc5545
    // ==================================================================
    $lines = [];

    // 📌 VCALENDAR header
    $lines[] = 'BEGIN:VCALENDAR';
    $lines[] = 'VERSION:2.0';
    $lines[] = 'PRODID:-//CueRCode//QR Code Generator//EN';

    // 📌 VEVENT block
    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'DTSTART:' . $icalStart;
    $lines[] = 'DTEND:' . $icalEnd;
    $lines[] = 'SUMMARY:' . icalEscape($title);

    if ($location !== '') {
        $lines[] = 'LOCATION:' . icalEscape($location);
    }

    if ($description !== '') {
        $lines[] = 'DESCRIPTION:' . icalEscape($description);
    }

    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';

    // 📌 Join lines with CRLF (as required by RFC 5545)
    return implode("\r\n", $lines);
}

// ==============================================================================
// 🔧 icalEscape() - Escape special characters in iCalendar values
// ==============================================================================

/**
 * 🔧 Escape special characters in iCalendar property values
 *
 * iCalendar requires that certain characters be escaped:
 *   - Backslash (\) -> \\
 *   - Semicolon (;) -> \;
 *   - Comma (,) -> \,
 *   - Newline -> \n
 *
 * @param string $value The raw value to escape
 *
 * @return string The escaped value safe for iCalendar inclusion
 *
 * @see https://datatracker.ietf.org/doc/html/rfc5545#section-3.3.11
 */
function icalEscape(string $value): string
{
    // 📌 Order matters: escape backslash first
    // @see https://www.php.net/manual/en/function.str-replace.php
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace(';', '\\;', $value);
    $value = str_replace(',', '\\,', $value);
    $value = str_replace("\r\n", '\\n', $value);
    $value = str_replace("\n", '\\n', $value);
    $value = str_replace("\r", '\\n', $value);

    return $value;
}

// ==============================================================================
// 💰 buildPayload_paypal() - PayPal payment link payload
// ==============================================================================

/**
 * 💰 Build a PayPal payment link payload for QR code encoding
 *
 * Generates a PayPal.me or paypal.com/paypalme URL for receiving payments.
 * Optionally includes a pre-filled amount.
 *
 * Format: https://paypal.me/{username}/{amount}
 *
 * @param array $inputData Must contain 'username' or 'paypal_url'; optional 'amount'
 *
 * @return string The PayPal payment URL
 *
 * @see https://www.paypal.com/paypalme/ PayPal.me
 *
 * @example
 *   buildPayload_paypal(['username' => 'johndoe', 'amount' => '25.00']);
 *   // Returns: 'https://paypal.me/johndoe/25.00'
 */
function buildPayload_paypal(array $inputData): string
{
    // 📌 Check for a direct PayPal URL first
    if (isset($inputData['paypal_url'])) {
        $paypalUrlValue = $inputData['paypal_url'];
    } else {
        $paypalUrlValue = '';
    }
    $paypalUrl = trim($paypalUrlValue);

    if ($paypalUrl !== '') {
        // 🔍 Ensure the URL has a scheme
        $parsedScheme = parse_url($paypalUrl, PHP_URL_SCHEME);
        if ($parsedScheme === null || $parsedScheme === false) {
            $paypalUrl = 'https://' . $paypalUrl;
        }
        return $paypalUrl;
    }

    // 📌 Build PayPal.me URL from username
    if (isset($inputData['username'])) {
        $paypalUsernameValue = $inputData['username'];
    } else {
        $paypalUsernameValue = '';
    }
    $username = trim($paypalUsernameValue);

    // ❌ Validate that a username or URL was provided
    if ($username === '') {
        throw new \InvalidArgumentException(
            'PayPal username or URL is required for PayPal-type QR codes.'
        );
    }

    // 📌 URL-encode the username for safe inclusion
    // @see https://www.php.net/manual/en/function.rawurlencode.php
    $payload = 'https://paypal.me/' . rawurlencode($username);

    // 📌 Append amount if provided
    if (isset($inputData['amount'])) {
        $paypalAmountValue = $inputData['amount'];
    } else {
        $paypalAmountValue = '';
    }
    $amount = trim($paypalAmountValue);
    if ($amount !== '' && is_numeric($amount)) {
        $payload .= '/' . $amount;
    }

    return $payload;
}

// ==============================================================================
// ₿ buildPayload_bitcoin() - Bitcoin payment URI payload
// ==============================================================================

/**
 * ₿ Build a Bitcoin payment URI payload for QR code encoding
 *
 * Generates a BIP-21 compliant bitcoin: URI with optional amount,
 * label, and message parameters.
 *
 * Format: bitcoin:{address}?amount={amount}&label={label}&message={message}
 *
 * @param array $inputData Must contain 'address'; optional 'amount', 'label', 'message'
 *
 * @return string The bitcoin: URI string
 *
 * @see https://github.com/bitcoin/bips/blob/master/bip-0021.mediawiki BIP-21 URI scheme
 *
 * @example
 *   buildPayload_bitcoin([
 *       'address' => '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa',
 *       'amount'  => '0.005',
 *       'label'   => 'Donation',
 *   ]);
 *   // Returns: 'bitcoin:1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa?amount=0.005&label=Donation'
 */
function buildPayload_bitcoin(array $inputData): string
{
    // 📌 Extract the Bitcoin address (required)
    if (isset($inputData['address'])) {
        $addressValue = $inputData['address'];
    } else {
        $addressValue = '';
    }
    $address = trim($addressValue);

    // ❌ Validate that an address was provided
    if ($address === '') {
        throw new \InvalidArgumentException(
            'Bitcoin address is required for Bitcoin-type QR codes.'
        );
    }

    // 📌 Build the bitcoin: URI
    // @see https://github.com/bitcoin/bips/blob/master/bip-0021.mediawiki
    $payload = 'bitcoin:' . $address;

    // 📌 Build optional query parameters
    $queryParams = [];

    if (isset($inputData['amount'])) {
        $btcAmountValue = $inputData['amount'];
    } else {
        $btcAmountValue = '';
    }
    $amount = trim($btcAmountValue);
    if ($amount !== '' && is_numeric($amount)) {
        $queryParams[] = 'amount=' . $amount;
    }

    if (isset($inputData['label'])) {
        $btcLabelValue = $inputData['label'];
    } else {
        $btcLabelValue = '';
    }
    $label = trim($btcLabelValue);
    if ($label !== '') {
        $queryParams[] = 'label=' . rawurlencode($label);
    }

    if (isset($inputData['message'])) {
        $btcMessageValue = $inputData['message'];
    } else {
        $btcMessageValue = '';
    }
    $message = trim($btcMessageValue);
    if ($message !== '') {
        $queryParams[] = 'message=' . rawurlencode($message);
    }

    // 📌 Append query parameters if any
    if (!empty($queryParams)) {
        $payload .= '?' . implode('&', $queryParams);
    }

    return $payload;
}

// ==============================================================================
// 🎵 buildPayload_mp3() - MP3/Audio file URL payload
// ==============================================================================

/**
 * 🎵 Build an MP3/Audio URL payload for QR code encoding
 *
 * Generates a direct URL to an audio file. When scanned, the device
 * will open the URL in a browser or media player.
 *
 * @param array $inputData Must contain 'url' (the audio file URL)
 *
 * @return string The audio file URL
 *
 * @example
 *   buildPayload_mp3(['url' => 'https://example.com/audio/song.mp3']);
 *   // Returns: 'https://example.com/audio/song.mp3'
 */
function buildPayload_mp3(array $inputData): string
{
    // 📌 Extract the audio URL (required)
    if (isset($inputData['url'])) {
        $mp3UrlValue = $inputData['url'];
    } else {
        $mp3UrlValue = '';
    }
    $url = trim($mp3UrlValue);

    // ❌ Validate that a URL was provided
    if ($url === '') {
        throw new \InvalidArgumentException('Audio file URL is required for MP3-type QR codes.');
    }

    // 🔍 Ensure the URL has a scheme
    // @see https://www.php.net/manual/en/function.parse-url.php
    $parsedScheme = parse_url($url, PHP_URL_SCHEME);
    if ($parsedScheme === null || $parsedScheme === false) {
        $url = 'https://' . $url;
    }

    return $url;
}

// ==============================================================================
// 🎬 buildPayload_video() - Video URL payload
// ==============================================================================

/**
 * 🎬 Build a video URL payload for QR code encoding
 *
 * Generates a direct URL to a video file or streaming page. When scanned,
 * the device will open the URL in a browser or video player.
 *
 * @param array $inputData Must contain 'url' (the video URL)
 *
 * @return string The video URL
 *
 * @example
 *   buildPayload_video(['url' => 'https://example.com/video/demo.mp4']);
 *   // Returns: 'https://example.com/video/demo.mp4'
 */
function buildPayload_video(array $inputData): string
{
    // 📌 Extract the video URL (required)
    if (isset($inputData['url'])) {
        $videoUrlValue = $inputData['url'];
    } else {
        $videoUrlValue = '';
    }
    $url = trim($videoUrlValue);

    // ❌ Validate that a URL was provided
    if ($url === '') {
        throw new \InvalidArgumentException('Video URL is required for video-type QR codes.');
    }

    // 🔍 Ensure the URL has a scheme
    $parsedScheme = parse_url($url, PHP_URL_SCHEME);
    if ($parsedScheme === null || $parsedScheme === false) {
        $url = 'https://' . $url;
    }

    return $url;
}

// ==============================================================================
// 📄 buildPayload_pdf() - PDF document URL payload
// ==============================================================================

/**
 * 📄 Build a PDF document URL payload for QR code encoding
 *
 * Generates a direct URL to a PDF document. When scanned, the device
 * will open or download the PDF.
 *
 * @param array $inputData Must contain 'url' (the PDF file URL)
 *
 * @return string The PDF document URL
 *
 * @example
 *   buildPayload_pdf(['url' => 'https://example.com/docs/brochure.pdf']);
 *   // Returns: 'https://example.com/docs/brochure.pdf'
 */
function buildPayload_pdf(array $inputData): string
{
    // 📌 Extract the PDF URL (required)
    if (isset($inputData['url'])) {
        $pdfUrlValue = $inputData['url'];
    } else {
        $pdfUrlValue = '';
    }
    $url = trim($pdfUrlValue);

    // ❌ Validate that a URL was provided
    if ($url === '') {
        throw new \InvalidArgumentException('PDF URL is required for PDF-type QR codes.');
    }

    // 🔍 Ensure the URL has a scheme
    $parsedScheme = parse_url($url, PHP_URL_SCHEME);
    if ($parsedScheme === null || $parsedScheme === false) {
        $url = 'https://' . $url;
    }

    return $url;
}

// ==============================================================================
// 🖼️ buildPayload_image() - Image URL payload
// ==============================================================================

/**
 * 🖼️ Build an image URL payload for QR code encoding
 *
 * Generates a direct URL to an image file. When scanned, the device
 * will display the image in a browser.
 *
 * @param array $inputData Must contain 'url' (the image URL)
 *
 * @return string The image URL
 *
 * @example
 *   buildPayload_image(['url' => 'https://example.com/img/photo.jpg']);
 *   // Returns: 'https://example.com/img/photo.jpg'
 */
function buildPayload_image(array $inputData): string
{
    // 📌 Extract the image URL (required)
    if (isset($inputData['url'])) {
        $imageUrlValue = $inputData['url'];
    } else {
        $imageUrlValue = '';
    }
    $url = trim($imageUrlValue);

    // ❌ Validate that a URL was provided
    if ($url === '') {
        throw new \InvalidArgumentException('Image URL is required for image-type QR codes.');
    }

    // 🔍 Ensure the URL has a scheme
    $parsedScheme = parse_url($url, PHP_URL_SCHEME);
    if ($parsedScheme === null || $parsedScheme === false) {
        $url = 'https://' . $url;
    }

    return $url;
}

// ==============================================================================
// 📱 buildPayload_appstore() - App Store link payload
// ==============================================================================

/**
 * 📱 Build an App Store link payload for QR code encoding
 *
 * Generates a URL to an app on the Apple App Store or Google Play Store.
 * Accepts either a direct URL or builds one from the app_id.
 *
 * @param array $inputData Must contain 'url' or ('app_id' + 'platform')
 *
 * @return string The App Store/Google Play URL
 *
 * @see https://developer.apple.com/library/archive/qa/qa1633/_index.html Apple App Store links
 * @see https://developer.android.com/distribute/marketing-tools Google Play links
 *
 * @example
 *   buildPayload_appstore(['url' => 'https://apps.apple.com/app/id123456789']);
 *   // Returns: 'https://apps.apple.com/app/id123456789'
 *
 *   buildPayload_appstore(['app_id' => 'com.example.app', 'platform' => 'android']);
 *   // Returns: 'https://play.google.com/store/apps/details?id=com.example.app'
 */
function buildPayload_appstore(array $inputData): string
{
    // 📌 Check for a direct URL first
    if (isset($inputData['url'])) {
        $appUrlValue = $inputData['url'];
    } else {
        $appUrlValue = '';
    }
    $url = trim($appUrlValue);

    if ($url !== '') {
        // 🔍 Ensure the URL has a scheme
        $parsedScheme = parse_url($url, PHP_URL_SCHEME);
        if ($parsedScheme === null || $parsedScheme === false) {
            $url = 'https://' . $url;
        }
        return $url;
    }

    // 📌 Build from app_id and platform
    if (isset($inputData['app_id'])) {
        $appIdValue = $inputData['app_id'];
    } else {
        $appIdValue = '';
    }
    $appId = trim($appIdValue);

    if (isset($inputData['platform'])) {
        $platformValue = $inputData['platform'];
    } else {
        $platformValue = 'ios';
    }
    $platform = strtolower(trim($platformValue));

    // ❌ Validate that at least a URL or app_id was provided
    if ($appId === '') {
        throw new \InvalidArgumentException(
            'App Store URL or app ID is required for app store-type QR codes.'
        );
    }

    // 📌 Build platform-specific URL
    if ($platform === 'android' || $platform === 'google') {
        // 📌 Google Play Store URL
        // @see https://developer.android.com/distribute/marketing-tools
        return 'https://play.google.com/store/apps/details?id=' . rawurlencode($appId);
    } else {
        // 📌 Apple App Store URL (default)
        // @see https://developer.apple.com/library/archive/qa/qa1633/_index.html
        return 'https://apps.apple.com/app/id' . rawurlencode($appId);
    }
}

// ==============================================================================
// 👥 buildPayload_facebook() - Facebook profile/page URL payload
// ==============================================================================

/**
 * 👥 Build a Facebook profile or page URL payload for QR code encoding
 *
 * Generates a Facebook URL. Accepts either a direct URL or a username
 * to construct the profile/page URL.
 *
 * @param array $inputData Must contain 'url' or 'username'
 *
 * @return string The Facebook URL
 *
 * @example
 *   buildPayload_facebook(['username' => 'johndoe']);
 *   // Returns: 'https://facebook.com/johndoe'
 *
 *   buildPayload_facebook(['url' => 'https://facebook.com/pages/my-page']);
 *   // Returns: 'https://facebook.com/pages/my-page'
 */
function buildPayload_facebook(array $inputData): string
{
    // 📌 Check for a direct URL first
    if (isset($inputData['url'])) {
        $fbUrlValue = $inputData['url'];
    } else {
        $fbUrlValue = '';
    }
    $url = trim($fbUrlValue);

    if ($url !== '') {
        $parsedScheme = parse_url($url, PHP_URL_SCHEME);
        if ($parsedScheme === null || $parsedScheme === false) {
            $url = 'https://' . $url;
        }
        return $url;
    }

    // 📌 Build from username
    if (isset($inputData['username'])) {
        $fbUsernameValue = $inputData['username'];
    } else {
        $fbUsernameValue = '';
    }
    $username = trim($fbUsernameValue);

    // ❌ Validate that at least a URL or username was provided
    if ($username === '') {
        throw new \InvalidArgumentException(
            'Facebook URL or username is required for Facebook-type QR codes.'
        );
    }

    return 'https://facebook.com/' . rawurlencode($username);
}

// ==============================================================================
// 📺 buildPayload_youtube() - YouTube video/channel URL payload
// ==============================================================================

/**
 * 📺 Build a YouTube URL payload for QR code encoding
 *
 * Generates a YouTube URL. Accepts either a direct URL or a video_id
 * to construct the watch URL.
 *
 * @param array $inputData Must contain 'url' or 'video_id'
 *
 * @return string The YouTube URL
 *
 * @example
 *   buildPayload_youtube(['video_id' => 'dQw4w9WgXcQ']);
 *   // Returns: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'
 *
 *   buildPayload_youtube(['url' => 'https://youtube.com/channel/UC12345']);
 *   // Returns: 'https://youtube.com/channel/UC12345'
 */
function buildPayload_youtube(array $inputData): string
{
    // 📌 Check for a direct URL first
    if (isset($inputData['url'])) {
        $ytUrlValue = $inputData['url'];
    } else {
        $ytUrlValue = '';
    }
    $url = trim($ytUrlValue);

    if ($url !== '') {
        $parsedScheme = parse_url($url, PHP_URL_SCHEME);
        if ($parsedScheme === null || $parsedScheme === false) {
            $url = 'https://' . $url;
        }
        return $url;
    }

    // 📌 Build from video_id
    if (isset($inputData['video_id'])) {
        $videoIdValue = $inputData['video_id'];
    } else {
        $videoIdValue = '';
    }
    $videoId = trim($videoIdValue);

    // ❌ Validate that at least a URL or video_id was provided
    if ($videoId === '') {
        throw new \InvalidArgumentException(
            'YouTube URL or video ID is required for YouTube-type QR codes.'
        );
    }

    return 'https://www.youtube.com/watch?v=' . rawurlencode($videoId);
}

// ==============================================================================
// 📶 buildPayload_wifi() - Wi-Fi network payload
// ==============================================================================

/**
 * 📶 Build a Wi-Fi network configuration payload for QR code encoding
 *
 * Generates a WIFI: string in the standard format used by QR code scanners
 * on Android and iOS. When scanned, the device will offer to connect to
 * the specified Wi-Fi network.
 *
 * Format: WIFI:T:{encryption};S:{ssid};P:{password};H:{hidden};;
 *
 * @param array $inputData Must contain 'ssid'; optional 'password', 'encryption', 'hidden'
 *
 * @return string The WIFI: configuration string
 *
 * @see https://github.com/zxing/zxing/wiki/Barcode-Contents#wi-fi-network-config Wi-Fi QR format
 *
 * @example
 *   buildPayload_wifi([
 *       'ssid'       => 'MyNetwork',
 *       'password'   => 'secretpass',
 *       'encryption' => 'WPA',
 *       'hidden'     => false,
 *   ]);
 *   // Returns: 'WIFI:T:WPA;S:MyNetwork;P:secretpass;H:false;;'
 */
function buildPayload_wifi(array $inputData): string
{
    // 📌 Extract the SSID (required)
    if (isset($inputData['ssid'])) {
        $ssid = $inputData['ssid'];
    } else {
        $ssid = '';
    }

    // ❌ Validate that an SSID was provided
    if (trim($ssid) === '') {
        throw new \InvalidArgumentException('Wi-Fi SSID (network name) is required for Wi-Fi-type QR codes.');
    }

    // 📌 Extract optional fields with defaults
    if (isset($inputData['password'])) {
        $password = $inputData['password'];
    } else {
        $password = '';
    }

    if (isset($inputData['encryption'])) {
        $encryptionValue = $inputData['encryption'];
    } else {
        $encryptionValue = 'WPA';
    }
    $encryption = strtoupper(trim($encryptionValue));

    if (isset($inputData['hidden'])) {
        $hidden = $inputData['hidden'];
    } else {
        $hidden = false;
    }

    // 📌 Validate encryption type
    // Supported types: WPA (includes WPA2/WPA3), WEP, nopass (open network)
    // @see https://github.com/zxing/zxing/wiki/Barcode-Contents#wi-fi-network-config
    $validEncryptions = ['WPA', 'WEP', 'nopass'];
    if (!in_array($encryption, $validEncryptions, true)) {
        // 📌 Default to WPA for any unrecognised encryption type
        $encryption = 'WPA';
    }

    // 📌 If no password is provided, set encryption to nopass (open network)
    if (trim($password) === '' && $encryption !== 'nopass') {
        $encryption = 'nopass';
    }

    // 📌 Convert hidden flag to string
    $hiddenStr = 'false';
    if ($hidden === true || $hidden === 'true' || $hidden === '1' || $hidden === 1) {
        $hiddenStr = 'true';
    }

    // 📌 Escape special characters in SSID and password for WIFI: format
    // The WIFI: format requires escaping: \, ;, ,, "
    // @see https://github.com/zxing/zxing/wiki/Barcode-Contents#wi-fi-network-config
    $escapedSsid     = wifiEscape($ssid);
    $escapedPassword = wifiEscape($password);

    // 📌 Build the WIFI: configuration string
    $payload = 'WIFI:'
        . 'T:' . $encryption . ';'
        . 'S:' . $escapedSsid . ';'
        . 'P:' . $escapedPassword . ';'
        . 'H:' . $hiddenStr . ';'
        . ';';

    return $payload;
}

// ==============================================================================
// 🔧 wifiEscape() - Escape special characters in Wi-Fi values
// ==============================================================================

/**
 * 🔧 Escape special characters in Wi-Fi SSID and password values
 *
 * The WIFI: QR format requires escaping backslash, semicolon, colon,
 * comma, and double-quote characters.
 *
 * @param string $value The raw value to escape
 *
 * @return string The escaped value safe for WIFI: string inclusion
 *
 * @see https://github.com/zxing/zxing/wiki/Barcode-Contents#wi-fi-network-config
 * @see https://www.php.net/manual/en/function.str-replace.php
 */
function wifiEscape(string $value): string
{
    // 📌 Escape special characters used by the WIFI: format parser
    // Order matters: escape backslash first to prevent double-escaping
    // @see https://www.php.net/manual/en/function.str-replace.php
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace(';', '\\;', $value);
    $value = str_replace(',', '\\,', $value);
    $value = str_replace('"', '\\"', $value);
    $value = str_replace(':', '\\:', $value);

    return $value;
}

// ==============================================================================
// 💼 buildPayload_linkedin() - LinkedIn profile URL payload
// ==============================================================================

/**
 * 💼 Build a LinkedIn profile URL payload for QR code encoding
 *
 * Generates a LinkedIn profile URL. Accepts either a direct URL or a
 * username/profile ID to construct the profile URL.
 *
 * @param array $inputData Must contain 'url' or 'username'
 *
 * @return string The LinkedIn profile URL
 *
 * @example
 *   buildPayload_linkedin(['username' => 'johndoe']);
 *   // Returns: 'https://www.linkedin.com/in/johndoe'
 *
 *   buildPayload_linkedin(['url' => 'https://linkedin.com/company/acme']);
 *   // Returns: 'https://linkedin.com/company/acme'
 */
function buildPayload_linkedin(array $inputData): string
{
    // 📌 Check for a direct URL first
    if (isset($inputData['url'])) {
        $liUrlValue = $inputData['url'];
    } else {
        $liUrlValue = '';
    }
    $url = trim($liUrlValue);

    if ($url !== '') {
        $parsedScheme = parse_url($url, PHP_URL_SCHEME);
        if ($parsedScheme === null || $parsedScheme === false) {
            $url = 'https://' . $url;
        }
        return $url;
    }

    // 📌 Build from username/profile ID
    if (isset($inputData['username'])) {
        $liUsernameValue = $inputData['username'];
    } else {
        $liUsernameValue = '';
    }
    $username = trim($liUsernameValue);

    // ❌ Validate that at least a URL or username was provided
    if ($username === '') {
        throw new \InvalidArgumentException(
            'LinkedIn URL or username is required for LinkedIn-type QR codes.'
        );
    }

    return 'https://www.linkedin.com/in/' . rawurlencode($username);
}
