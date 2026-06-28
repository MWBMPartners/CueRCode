<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Characterization Tests for QR Payload Builders
 * ==============================================================================
 *
 * Path: tests/test_payloads.php
 *
 * Locks in the CURRENT observed output of every payload builder in
 * web/CueRCode.net/_functions/fn_qr_payloads.php. These are CHARACTERIZATION
 * tests: each expected value below was captured by actually RUNNING the real
 * production builder and pasting its exact output into the assertion. The goal
 * is a SAFETY NET — if a future change alters any builder's output, these tests
 * will flag it. We do NOT fix bugs here; where the current behaviour looks
 * surprising it is documented with a "// BUG:" or "// NOTE:" comment and the
 * CURRENT (possibly imperfect) behaviour is asserted as-is.
 *
 * Builders covered (the 21 seeded QR types, all DB-independent — the individual
 * buildPayload_* functions never touch the database):
 *   url, text, email, phone, sms, whatsapp, zoom, geo, vcard, calendar,
 *   paypal, bitcoin, mp3, video, pdf, image, appstore, facebook, youtube,
 *   wifi, linkedin
 * Plus the pure escaping helpers: vcardEscape, icalEscape, wifiEscape.
 * Plus a dispatcher characterization for an absent builder (instagram).
 *
 * ⚠️ Escaping note for the reader: in PHP single-quoted string literals, only
 *    \\ (backslash-backslash → one backslash) and \' are special. So a literal
 *    '\\;' is the two characters backslash + semicolon, and '\\\\' is two
 *    backslashes. The expected values were verified byte-for-byte against the
 *    raw (non var_export) output of the real builders.
 *
 * ⏰ Determinism note: buildPayload_calendar() uses strtotime()/date(), which
 *    depend on the default timezone. We pin date_default_timezone_set('UTC')
 *    before exercising it so the iCalendar DTSTART/DTEND are reproducible.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 1 (test safety-net cycle)
 * @see         web/CueRCode.net/_functions/fn_qr_payloads.php
 * @see         https://www.php.net/manual/en/function.var-export.php
 */

// ==============================================================================
// 📦 Load the production payload builders (NO database connection)
// ==============================================================================
// requireFn() (from tests/bootstrap.php) includes the fn_ file, which only
// REGISTERS the standalone builder functions — including it never opens a DB
// connection. The individual buildPayload_* functions we test below are all
// pure (no DB), so this is fully self-contained.
// ==============================================================================

requireFn('fn_qr_payloads.php');

// 📌 Pin the timezone so calendar (strtotime/date) output is deterministic.
date_default_timezone_set('UTC');

// ==============================================================================
// 🌐 buildPayload_url()
// ==============================================================================

// 📌 Already has a scheme → returned unchanged.
assertEquals(
    'https://example.com',
    buildPayload_url(['url' => 'https://example.com']),
    'url: full https URL passes through unchanged'
);

// 📌 No scheme → 'https://' is prepended.
assertEquals(
    'https://example.com',
    buildPayload_url(['url' => 'example.com']),
    'url: bare host gets https:// prepended'
);

// 📌 Leading/trailing whitespace is trimmed before scheme detection.
assertEquals(
    'http://test.org/path',
    buildPayload_url(['url' => '  http://test.org/path  ']),
    'url: surrounding whitespace trimmed, existing http scheme kept'
);

// 📌 NON-https existing scheme is preserved (parse_url sees a scheme).
assertEquals(
    'ftp://files.example.com',
    buildPayload_url(['url' => 'ftp://files.example.com']),
    'url: ftp scheme preserved (not forced to https)'
);

// 📌 Missing/empty URL throws InvalidArgumentException.
assertThrows(
    function (): void {
        buildPayload_url([]);
    },
    'url: missing url key throws',
    \InvalidArgumentException::class
);

// ==============================================================================
// 📝 buildPayload_text()
// ==============================================================================

// 📌 Plain text returned verbatim.
assertEquals(
    'Hello, World!',
    buildPayload_text(['text' => 'Hello, World!']),
    'text: plain text returned as-is'
);

// 📌 Newlines and HTML-special chars are NOT escaped — returned raw.
assertEquals(
    "Line1\nLine2 & <tag>",
    buildPayload_text(['text' => "Line1\nLine2 & <tag>"]),
    'text: newlines and &<> preserved verbatim'
);

// 📌 NOTE: the empty-check trims for VALIDATION, but the value is returned
//          WITHOUT trimming, so surrounding whitespace survives in the payload.
assertEquals(
    '  spaced  ',
    buildPayload_text(['text' => '  spaced  ']),
    'text: surrounding whitespace preserved in returned value (only validation trims)'
);

// 📌 Whitespace-only text fails the trim() validation → throws.
assertThrows(
    function (): void {
        buildPayload_text(['text' => '   ']);
    },
    'text: whitespace-only content throws',
    \InvalidArgumentException::class
);

// ==============================================================================
// 📧 buildPayload_email()
// ==============================================================================

// 📌 Full mailto: with rawurlencode'd subject & body ('?' → %3F, space → %20).
assertEquals(
    'mailto:user@example.com?subject=Hello&body=How%20are%20you%3F',
    buildPayload_email([
        'email'   => 'user@example.com',
        'subject' => 'Hello',
        'body'    => 'How are you?',
    ]),
    'email: full mailto with encoded subject and body'
);

// 📌 Email only → no query string.
assertEquals(
    'mailto:user@example.com',
    buildPayload_email(['email' => 'user@example.com']),
    'email: address only, no query params'
);

// 📌 Subject only — '&' encodes to %26, space to %20 via rawurlencode.
assertEquals(
    'mailto:a@b.com?subject=Hi%20there%20%26%20bye',
    buildPayload_email(['email' => 'a@b.com', 'subject' => 'Hi there & bye']),
    'email: subject-only with special chars rawurlencoded'
);

// 📌 Body only — '=' → %3D, '&' → %26, space → %20.
assertEquals(
    'mailto:a@b.com?body=a%3Db%26c%20d',
    buildPayload_email(['email' => 'a@b.com', 'body' => 'a=b&c d']),
    'email: body-only with = and & rawurlencoded'
);

// 📌 Missing email throws.
assertThrows(
    function (): void {
        buildPayload_email(['subject' => 'x']);
    },
    'email: missing email throws',
    \InvalidArgumentException::class
);

// ==============================================================================
// 📞 buildPayload_phone()
// ==============================================================================

// 📌 Spaces stripped, leading + preserved.
assertEquals(
    'tel:+442012345678',
    buildPayload_phone(['phone' => '+44 20 1234 5678']),
    'phone: spaces removed, + kept'
);

// 📌 Parentheses, hyphens removed (no + present → none added).
assertEquals(
    'tel:5551234567',
    buildPayload_phone(['phone' => '(555) 123-4567']),
    'phone: formatting characters stripped'
);

// 📌 Missing phone throws.
assertThrows(
    function (): void {
        buildPayload_phone([]);
    },
    'phone: missing phone throws',
    \InvalidArgumentException::class
);

// ==============================================================================
// 💬 buildPayload_sms()
// ==============================================================================

// 📌 smsto: with message appended after a colon.
assertEquals(
    'smsto:+1234567890:Hello!',
    buildPayload_sms(['phone' => '+1234567890', 'message' => 'Hello!']),
    'sms: phone + message via smsto:'
);

// 📌 No message → just the cleaned phone; formatting stripped, + kept.
assertEquals(
    'smsto:+1234567890',
    buildPayload_sms(['phone' => '+1 (234) 567-890']),
    'sms: phone only, formatting stripped'
);

// 📌 Missing phone throws.
assertThrows(
    function (): void {
        buildPayload_sms(['message' => 'hi']);
    },
    'sms: missing phone throws',
    \InvalidArgumentException::class
);

// ==============================================================================
// 📱 buildPayload_whatsapp()
// ==============================================================================

// 📌 wa.me uses DIGITS ONLY (the leading + is stripped), message rawurlencoded
//    ('!' → %21).
assertEquals(
    'https://wa.me/1234567890?text=Hi%20there%21',
    buildPayload_whatsapp(['phone' => '+1234567890', 'message' => 'Hi there!']),
    'whatsapp: + stripped, message rawurlencoded'
);

// 📌 No message, all non-digits removed.
assertEquals(
    'https://wa.me/447700900000',
    buildPayload_whatsapp(['phone' => '+44 7700 900000']),
    'whatsapp: phone only, digits only'
);

// 📌 Missing phone throws.
assertThrows(
    function (): void {
        buildPayload_whatsapp([]);
    },
    'whatsapp: missing phone throws',
    \InvalidArgumentException::class
);

// ==============================================================================
// 🎥 buildPayload_zoom()
// ==============================================================================

// 📌 Full meeting_url with scheme → passes through.
assertEquals(
    'https://zoom.us/j/123456789',
    buildPayload_zoom(['meeting_url' => 'https://zoom.us/j/123456789']),
    'zoom: full URL passes through'
);

// 📌 meeting_url without scheme → https:// prepended.
assertEquals(
    'https://zoom.us/j/999',
    buildPayload_zoom(['meeting_url' => 'zoom.us/j/999']),
    'zoom: schemeless URL gets https://'
);

// 📌 meeting_id + password → zoommtg:// deep link. ID is digits-only
//    (spaces stripped); password is rawurlencoded (space → %20).
assertEquals(
    'zoommtg://zoom.us/join?confno=1234567890&pwd=abc%20123',
    buildPayload_zoom(['meeting_id' => '123 456 7890', 'password' => 'abc 123']),
    'zoom: id+password builds zoommtg deep link'
);

// 📌 meeting_id only → deep link without pwd.
assertEquals(
    'zoommtg://zoom.us/join?confno=1234567890',
    buildPayload_zoom(['meeting_id' => '1234567890']),
    'zoom: id only deep link'
);

// 📌 Neither URL nor ID → throws.
assertThrows(
    function (): void {
        buildPayload_zoom([]);
    },
    'zoom: no url and no id throws',
    \InvalidArgumentException::class
);

// ==============================================================================
// 📍 buildPayload_geo()
// ==============================================================================

// 📌 Basic geo: URI, lat/lng passed through verbatim (already numeric).
assertEquals(
    'geo:51.5074,-0.1278',
    buildPayload_geo(['latitude' => '51.5074', 'longitude' => '-0.1278']),
    'geo: lat,long joined into geo: URI'
);

// 📌 Missing a coordinate → throws.
assertThrows(
    function (): void {
        buildPayload_geo(['latitude' => '51.5']);
    },
    'geo: missing longitude throws',
    \InvalidArgumentException::class
);

// 📌 Non-numeric coordinate → throws.
assertThrows(
    function (): void {
        buildPayload_geo(['latitude' => 'north', 'longitude' => '0']);
    },
    'geo: non-numeric coordinate throws',
    \InvalidArgumentException::class
);

// ==============================================================================
// 👤 buildPayload_vcard()  (multi-line — assert exact full string)
// ==============================================================================

// 📌 Full vCard. Lines are joined with "\n". Special chars in ORG/ADR/NOTE are
//    vCard-escaped (',' → '\,' and ';' → '\;'). The expected string below was
//    captured from the real builder; '\\,' here is backslash + comma, '\\;' is
//    backslash + semicolon (single-quoted PHP literal).
$expectedVcardFull =
    "BEGIN:VCARD\n"
    . "VERSION:3.0\n"
    . "N:Doe;John;;;\n"
    . "FN:John Doe\n"
    . 'ORG:ACME\, Corp' . "\n"
    . "TITLE:Engineer\n"
    . "TEL;TYPE=WORK:+1-555-111\n"
    . "TEL;TYPE=CELL:+1-555-222\n"
    . "EMAIL:john@acme.com\n"
    . "URL:https://acme.com\n"
    . 'ADR;TYPE=WORK:;;1 Main\; St;London;ENG;EC1A;UK' . "\n"
    . 'NOTE:Note\, with comma' . "\n"
    . "END:VCARD";
assertEquals(
    $expectedVcardFull,
    buildPayload_vcard([
        'first_name'   => 'John',
        'last_name'    => 'Doe',
        'organisation' => 'ACME, Corp',
        'title'        => 'Engineer',
        'phone_work'   => '+1-555-111',
        'phone_cell'   => '+1-555-222',
        'email'        => 'john@acme.com',
        'url'          => 'https://acme.com',
        'street'       => '1 Main; St',
        'city'         => 'London',
        'state'        => 'ENG',
        'zip'          => 'EC1A',
        'country'      => 'UK',
        'note'         => 'Note, with comma',
    ]),
    'vcard: full card with escaping of , and ; in ORG/ADR/NOTE'
);

// 📌 Minimal with only first_name. N: has empty Last field; FN: is just first.
assertEquals(
    "BEGIN:VCARD\nVERSION:3.0\nN:;Jane;;;\nFN:Jane\nEND:VCARD",
    buildPayload_vcard(['first_name' => 'Jane']),
    'vcard: first name only'
);

// 📌 Last name only. N: has empty First field; FN: is just last.
assertEquals(
    "BEGIN:VCARD\nVERSION:3.0\nN:Smith;;;;\nFN:Smith\nEND:VCARD",
    buildPayload_vcard(['last_name' => 'Smith']),
    'vcard: last name only'
);

// 📌 American spelling 'organization' is accepted as a fallback for ORG.
assertEquals(
    "BEGIN:VCARD\nVERSION:3.0\nN:;A;;;\nFN:A\nORG:OrgZ\nEND:VCARD",
    buildPayload_vcard(['first_name' => 'A', 'organization' => 'OrgZ']),
    'vcard: organization (US spelling) fallback maps to ORG'
);

// 📌 FIX (2026-06-28): the seed formFieldsJSON for vcard uses field names
//          'phone', 'mobile', 'website', 'postcode', 'notes'. The builder now
//          accepts these as aliases for 'phone_work', 'phone_cell', 'url',
//          'zip', 'note' respectively, so a vCard built straight from the
//          rendered form now correctly emits TEL/URL/NOTE/ADR lines.
//          (Previously these seed field names were silently ignored — see the
//          git history of this assertion.)
assertEquals(
    "BEGIN:VCARD\n"
    . "VERSION:3.0\n"
    . "N:;A;;;\n"
    . "FN:A\n"
    . "TEL;TYPE=WORK:+1-555-999\n"   // 'phone'    → phone_work
    . "TEL;TYPE=CELL:+1-555-000\n"   // 'mobile'   → phone_cell
    . "URL:https://x.io\n"           // 'website'  → url
    . "ADR;TYPE=WORK:;;;;;AB1;\n"    // 'postcode' → zip (only ZIP slot filled)
    . "NOTE:hi\n"                    // 'notes'    → note
    . "END:VCARD",
    buildPayload_vcard([
        'first_name' => 'A',
        'phone'      => '+1-555-999',   // seed/form alias for phone_work
        'mobile'     => '+1-555-000',   // seed/form alias for phone_cell
        'website'    => 'https://x.io', // seed/form alias for url
        'postcode'   => 'AB1',          // seed/form alias for zip
        'notes'      => 'hi',           // seed/form alias for note
    ]),
    'vcard: FIX — seed field names (phone/mobile/website/postcode/notes) now emit TEL/URL/ADR/NOTE'
);

// 📌 When BOTH a legacy name and its seed alias are supplied, the legacy
//    (canonical) name wins — confirming the preference order of the alias map.
assertEquals(
    "BEGIN:VCARD\n"
    . "VERSION:3.0\n"
    . "N:;A;;;\n"
    . "FN:A\n"
    . "TEL;TYPE=WORK:LEGACY-WORK\n"   // phone_work preferred over phone
    . "TEL;TYPE=CELL:LEGACY-CELL\n"   // phone_cell preferred over mobile
    . "URL:https://legacy.example\n"  // url preferred over website
    . "ADR;TYPE=WORK:;;;;;LEGACYZIP;\n" // zip preferred over postcode
    . "NOTE:legacy note\n"            // note preferred over notes
    . "END:VCARD",
    buildPayload_vcard([
        'first_name' => 'A',
        'phone_work' => 'LEGACY-WORK',  'phone'    => 'SEED-PHONE',
        'phone_cell' => 'LEGACY-CELL',  'mobile'   => 'SEED-MOBILE',
        'url'        => 'https://legacy.example', 'website' => 'https://seed.example',
        'zip'        => 'LEGACYZIP',    'postcode' => 'SEEDZIP',
        'note'       => 'legacy note',  'notes'    => 'seed note',
    ]),
    'vcard: legacy field names take precedence over seed aliases when both present'
);

// 📌 No name at all → throws.
assertThrows(
    function (): void {
        buildPayload_vcard(['email' => 'x@y.com']);
    },
    'vcard: no first or last name throws',
    \InvalidArgumentException::class
);

// ==============================================================================
// 🔧 vcardEscape()
// ==============================================================================

// 📌 Backslash → \\, ';' → \;, ',' → \,, "\n" → \n (literal), "\r" → removed.
//    Input here: a, backslash, b, ;, c, ,, d  →  a\\b\;c\,d
assertEquals(
    'a\\\\b\\;c\\,d',
    vcardEscape('a\\b;c,d'),
    'vcardEscape: backslash, semicolon, comma escaped'
);

// 📌 Newline becomes literal "\n"; carriage return is stripped.
assertEquals(
    'line1\\nline2',
    vcardEscape("line1\nline2"),
    'vcardEscape: newline -> \\n'
);

// 📌 Carriage return removed entirely (not converted).
assertEquals(
    'ab',
    vcardEscape("a\rb"),
    'vcardEscape: carriage return stripped'
);

// ==============================================================================
// 📅 buildPayload_calendar()  (multi-line, CRLF joined — assert exact string)
// ==============================================================================

// 📌 Full event. Lines joined with "\r\n". SUMMARY/LOCATION/DESCRIPTION are
//    ical-escaped (',' → '\,' ';' → '\;'). Dates → Ymd\THis (UTC pinned above).
$expectedCalFull =
    "BEGIN:VCALENDAR\r\n"
    . "VERSION:2.0\r\n"
    . "PRODID:-//CueRCode//QR Code Generator//EN\r\n"
    . "BEGIN:VEVENT\r\n"
    . "DTSTART:20260315T100000\r\n"
    . "DTEND:20260315T110000\r\n"
    . 'SUMMARY:Team\, Meeting' . "\r\n"
    . 'LOCATION:Room\; A' . "\r\n"
    . "DESCRIPTION:sync\r\n"
    . "END:VEVENT\r\n"
    . "END:VCALENDAR";
assertEquals(
    $expectedCalFull,
    buildPayload_calendar([
        'title'       => 'Team, Meeting',
        'start_date'  => '2026-03-15T10:00:00',
        'end_date'    => '2026-03-15T11:00:00',
        'location'    => 'Room; A',
        'description' => 'sync',
    ]),
    'calendar: full VEVENT with escaped summary/location, CRLF joined'
);

// 📌 No end_date → DTEND defaults to start + 1 hour (3600s).
$expectedCalDefault =
    "BEGIN:VCALENDAR\r\n"
    . "VERSION:2.0\r\n"
    . "PRODID:-//CueRCode//QR Code Generator//EN\r\n"
    . "BEGIN:VEVENT\r\n"
    . "DTSTART:20260315T100000\r\n"
    . "DTEND:20260315T110000\r\n"
    . "SUMMARY:Solo\r\n"
    . "END:VEVENT\r\n"
    . "END:VCALENDAR";
assertEquals(
    $expectedCalDefault,
    buildPayload_calendar([
        'title'      => 'Solo',
        'start_date' => '2026-03-15T10:00:00',
    ]),
    'calendar: missing end_date defaults DTEND to start + 1 hour'
);

// 📌 Missing title throws.
assertThrows(
    function (): void {
        buildPayload_calendar(['start_date' => '2026-03-15T10:00:00']);
    },
    'calendar: missing title throws',
    \InvalidArgumentException::class
);

// 📌 Missing start_date throws.
assertThrows(
    function (): void {
        buildPayload_calendar(['title' => 'X']);
    },
    'calendar: missing start_date throws',
    \InvalidArgumentException::class
);

// 📌 Unparseable start_date throws (strtotime returns false).
assertThrows(
    function (): void {
        buildPayload_calendar(['title' => 'X', 'start_date' => 'not-a-date']);
    },
    'calendar: invalid start_date throws',
    \InvalidArgumentException::class
);

// ==============================================================================
// 🔧 icalEscape()
// ==============================================================================

// 📌 ';' → \;, ',' → \,, backslash → \\.  Input: x;y,z\w → x\;y\,z\\w
assertEquals(
    'x\\;y\\,z\\\\w',
    icalEscape('x;y,z\\w'),
    'icalEscape: semicolon, comma, backslash escaped'
);

// 📌 CRLF, lone LF, and lone CR all collapse to the literal "\n".
assertEquals(
    'a\\nb',
    icalEscape("a\r\nb"),
    'icalEscape: CRLF -> \\n'
);

// ==============================================================================
// 💰 buildPayload_paypal()
// ==============================================================================

// 📌 username + numeric amount → paypal.me/<user>/<amount>.
assertEquals(
    'https://paypal.me/johndoe/25.00',
    buildPayload_paypal(['username' => 'johndoe', 'amount' => '25.00']),
    'paypal: username + amount'
);

// 📌 NOTE: the username is rawurlencode'd, so a space becomes %20 (PayPal.me
//          usernames are not actually allowed spaces, but the builder encodes
//          whatever it's given).
assertEquals(
    'https://paypal.me/jane%20doe',
    buildPayload_paypal(['username' => 'jane doe']),
    'paypal: username rawurlencoded (space -> %20)'
);

// 📌 Non-numeric amount is silently dropped (is_numeric gate).
assertEquals(
    'https://paypal.me/x',
    buildPayload_paypal(['username' => 'x', 'amount' => 'lots']),
    'paypal: non-numeric amount dropped'
);

// 📌 Direct paypal_url without scheme → https:// prepended.
assertEquals(
    'https://paypal.me/abc',
    buildPayload_paypal(['paypal_url' => 'paypal.me/abc']),
    'paypal: paypal_url without scheme gets https://'
);

// 📌 Neither username nor URL → throws.
assertThrows(
    function (): void {
        buildPayload_paypal(['amount' => '5']);
    },
    'paypal: no username/url throws',
    \InvalidArgumentException::class
);

// ==============================================================================
// ₿ buildPayload_bitcoin()
// ==============================================================================

// 📌 Full BIP-21: amount unencoded (numeric), label/message rawurlencoded
//    (space → %20, '!' → %21).
assertEquals(
    'bitcoin:1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa?amount=0.005&label=Donation%20gift&message=thanks%21',
    buildPayload_bitcoin([
        'address' => '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa',
        'amount'  => '0.005',
        'label'   => 'Donation gift',
        'message' => 'thanks!',
    ]),
    'bitcoin: full BIP-21 URI with encoded label/message'
);

// 📌 Address only → bare bitcoin: URI.
assertEquals(
    'bitcoin:1ABC',
    buildPayload_bitcoin(['address' => '1ABC']),
    'bitcoin: address only'
);

// 📌 Non-numeric amount dropped (is_numeric gate) → bare URI.
assertEquals(
    'bitcoin:1ABC',
    buildPayload_bitcoin(['address' => '1ABC', 'amount' => 'free']),
    'bitcoin: non-numeric amount dropped'
);

// 📌 Missing address → throws.
assertThrows(
    function (): void {
        buildPayload_bitcoin(['amount' => '0.1']);
    },
    'bitcoin: missing address throws',
    \InvalidArgumentException::class
);

// ==============================================================================
// 🎵🎬📄🖼️ buildPayload_mp3 / video / pdf / image  (URL pass-through builders)
// ==============================================================================

// 📌 mp3: schemeless URL → https:// prepended.
assertEquals(
    'https://example.com/a.mp3',
    buildPayload_mp3(['url' => 'example.com/a.mp3']),
    'mp3: schemeless URL gets https://'
);

// 📌 video: existing scheme preserved.
assertEquals(
    'https://example.com/v.mp4',
    buildPayload_video(['url' => 'https://example.com/v.mp4']),
    'video: full URL passes through'
);

// 📌 pdf: schemeless host → https:// prepended.
assertEquals(
    'https://docs.example.com/b.pdf',
    buildPayload_pdf(['url' => 'docs.example.com/b.pdf']),
    'pdf: schemeless URL gets https://'
);

// 📌 image: full URL passes through.
assertEquals(
    'https://example.com/p.jpg',
    buildPayload_image(['url' => 'https://example.com/p.jpg']),
    'image: full URL passes through'
);

// 📌 Each of these throws when URL missing.
assertThrows(
    function (): void {
        buildPayload_mp3([]);
    },
    'mp3: missing url throws',
    \InvalidArgumentException::class
);
assertThrows(
    function (): void {
        buildPayload_video([]);
    },
    'video: missing url throws',
    \InvalidArgumentException::class
);
assertThrows(
    function (): void {
        buildPayload_pdf([]);
    },
    'pdf: missing url throws',
    \InvalidArgumentException::class
);
assertThrows(
    function (): void {
        buildPayload_image([]);
    },
    'image: missing url throws',
    \InvalidArgumentException::class
);

// ==============================================================================
// 📱 buildPayload_appstore()
// ==============================================================================

// 📌 Direct URL (schemeless) → https:// prepended.
assertEquals(
    'https://apps.apple.com/app/id123',
    buildPayload_appstore(['url' => 'apps.apple.com/app/id123']),
    'appstore: schemeless URL gets https://'
);

// 📌 app_id + platform=android → Google Play URL.
assertEquals(
    'https://play.google.com/store/apps/details?id=com.example.app',
    buildPayload_appstore(['app_id' => 'com.example.app', 'platform' => 'android']),
    'appstore: android platform -> Google Play URL'
);

// 📌 app_id with no platform → defaults to Apple App Store ('id' + app_id).
assertEquals(
    'https://apps.apple.com/app/id123456789',
    buildPayload_appstore(['app_id' => '123456789']),
    'appstore: no platform defaults to Apple App Store'
);

// 📌 'google' (and the legacy 'android' alias) → Google Play, case-insensitive.
assertEquals(
    'https://play.google.com/store/apps/details?id=com.x',
    buildPayload_appstore(['app_id' => 'com.x', 'platform' => 'GOOGLE']),
    'appstore: GOOGLE (case-insensitive) -> Google Play'
);

// 📌 'apple' explicitly selects the Apple App Store branch.
assertEquals(
    'https://apps.apple.com/app/idcom.x',
    buildPayload_appstore(['app_id' => 'com.x', 'platform' => 'apple']),
    'appstore: apple platform -> Apple App Store'
);

// 📌 FIX (2026-06-28): the seed used to offer a 'platform' option of 'auto',
//          and the builder silently fell 'auto' (and any unknown value) through
//          to the Apple branch — producing a MALFORMED URL like
//          https://apps.apple.com/app/idcom.example.app. A static QR code cannot
//          auto-detect the platform, so 'auto' has been removed from the seed
//          options and the builder now THROWS for any platform that is not
//          'apple'/'ios' or 'google'/'android'. (Previously this asserted the
//          malformed Apple URL — see the git history of this assertion.)
assertThrows(
    function (): void {
        buildPayload_appstore(['app_id' => 'com.x', 'platform' => 'auto']);
    },
    'appstore: FIX — invalid platform "auto" now throws instead of building a malformed URL',
    \InvalidArgumentException::class
);

// 📌 Neither URL nor app_id → throws.
assertThrows(
    function (): void {
        buildPayload_appstore(['platform' => 'android']);
    },
    'appstore: no url/app_id throws',
    \InvalidArgumentException::class
);

// ==============================================================================
// 👥 buildPayload_facebook()
// ==============================================================================

// 📌 username → facebook.com/<user>.
assertEquals(
    'https://facebook.com/johndoe',
    buildPayload_facebook(['username' => 'johndoe']),
    'facebook: username -> facebook.com/<user>'
);

// 📌 schemeless URL → https:// prepended.
assertEquals(
    'https://facebook.com/pages/my-page',
    buildPayload_facebook(['url' => 'facebook.com/pages/my-page']),
    'facebook: schemeless URL gets https://'
);

// 📌 Missing both → throws.
assertThrows(
    function (): void {
        buildPayload_facebook([]);
    },
    'facebook: no url/username throws',
    \InvalidArgumentException::class
);

// ==============================================================================
// 📺 buildPayload_youtube()
// ==============================================================================

// 📌 video_id → www.youtube.com/watch?v=<id>.
assertEquals(
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    buildPayload_youtube(['video_id' => 'dQw4w9WgXcQ']),
    'youtube: video_id -> watch URL'
);

// 📌 schemeless URL → https:// prepended (note: NOT normalised to www.).
assertEquals(
    'https://youtube.com/channel/UC123',
    buildPayload_youtube(['url' => 'youtube.com/channel/UC123']),
    'youtube: schemeless URL gets https:// (kept as-is otherwise)'
);

// 📌 Missing both → throws.
assertThrows(
    function (): void {
        buildPayload_youtube([]);
    },
    'youtube: no url/video_id throws',
    \InvalidArgumentException::class
);

// ==============================================================================
// 📶 buildPayload_wifi()
// ==============================================================================

// 📌 Standard WPA network. Format: WIFI:T:<enc>;S:<ssid>;P:<pass>;H:<hidden>;;
assertEquals(
    'WIFI:T:WPA;S:MyNetwork;P:secretpass;H:false;;',
    buildPayload_wifi([
        'ssid'       => 'MyNetwork',
        'password'   => 'secretpass',
        'encryption' => 'WPA',
        'hidden'     => false,
    ]),
    'wifi: standard WPA network string'
);

// 📌 No password → encryption forced to 'nopass', empty P field.
assertEquals(
    'WIFI:T:nopass;S:Guest;P:;H:false;;',
    buildPayload_wifi(['ssid' => 'Guest']),
    'wifi: no password forces nopass'
);

// 📌 Special chars in SSID/password are wifiEscape'd: ';' → \;, ',' → \,,
//    '"' → \", ':' → \:, backslash → \\. hidden=true → H:true.
//    Expected captured raw: WIFI:T:WPA;S:My\;Net\,\"work\";P:pa\:ss\\word;H:true;;
assertEquals(
    'WIFI:T:WPA;S:My\\;Net\\,\\"work\\";P:pa\\:ss\\\\word;H:true;;',
    buildPayload_wifi([
        'ssid'       => 'My;Net,"work"',
        'password'   => 'pa:ss\\word',
        'encryption' => 'WPA',
        'hidden'     => true,
    ]),
    'wifi: SSID and password special chars escaped, hidden=true'
);

// 📌 Unrecognised encryption ('WPA3') falls back to 'WPA'.
assertEquals(
    'WIFI:T:WPA;S:N;P:p;H:false;;',
    buildPayload_wifi(['ssid' => 'N', 'password' => 'p', 'encryption' => 'WPA3']),
    'wifi: BUG — unrecognised encryption (WPA3) silently downgraded to WPA'
);

// 📌 'wep' (lowercase) is upper-cased to a valid 'WEP'.
assertEquals(
    'WIFI:T:WEP;S:N;P:p;H:false;;',
    buildPayload_wifi(['ssid' => 'N', 'password' => 'p', 'encryption' => 'wep']),
    'wifi: lowercase wep normalised to WEP'
);

// 📌 hidden given as the string '1' is treated as true.
assertEquals(
    'WIFI:T:WPA;S:N;P:p;H:true;;',
    buildPayload_wifi(['ssid' => 'N', 'password' => 'p', 'hidden' => '1']),
    'wifi: hidden string "1" -> true'
);

// 📌 Missing SSID → throws.
assertThrows(
    function (): void {
        buildPayload_wifi(['password' => 'p']);
    },
    'wifi: missing ssid throws',
    \InvalidArgumentException::class
);

// ==============================================================================
// 🔧 wifiEscape()
// ==============================================================================

// 📌 backslash → \\, ';' → \;, ',' → \,, '"' → \", ':' → \:.
//    Input: a\b;c,d"e:f → a\\b\;c\,d\"e\:f
assertEquals(
    'a\\\\b\\;c\\,d\\"e\\:f',
    wifiEscape('a\\b;c,d"e:f'),
    'wifiEscape: backslash, ;, ,, ", : all escaped'
);

// ==============================================================================
// 💼 buildPayload_linkedin()
// ==============================================================================

// 📌 username → www.linkedin.com/in/<user>.
assertEquals(
    'https://www.linkedin.com/in/johndoe',
    buildPayload_linkedin(['username' => 'johndoe']),
    'linkedin: username -> /in/<user>'
);

// 📌 schemeless URL → https:// prepended (kept as given otherwise).
assertEquals(
    'https://linkedin.com/company/acme',
    buildPayload_linkedin(['url' => 'linkedin.com/company/acme']),
    'linkedin: schemeless URL gets https://'
);

// 📌 Missing both → throws.
assertThrows(
    function (): void {
        buildPayload_linkedin([]);
    },
    'linkedin: no url/username throws',
    \InvalidArgumentException::class
);

// ==============================================================================
// ❓ Dispatcher: absent builder (instagram / messenger / rating)
// ==============================================================================
// 'instagram' appears in seed_data.sql ONLY as a tblPredefinedLogos row (a logo
// for branding), NOT as a tblQRCodeTypes row, so there is no payloadTemplate and
// no buildPayload_instagram() function. Routing such a type through
// buildPayloadByType() therefore throws InvalidArgumentException. This documents
// the CURRENT behaviour for any caller that mistakes a logo name for a QR type.
// (No facebook_messenger / rating QR types exist either — same outcome.)

assertThrows(
    function (): void {
        buildPayloadByType('instagram', ['url' => 'https://instagram.com/x']);
    },
    'dispatcher: instagram has no builder -> throws (not a QR type, only a logo)',
    \InvalidArgumentException::class
);

// 📌 Confirm the exact current error message for the missing builder.
$instagramMessage = '';
try {
    buildPayloadByType('instagram', ['url' => 'x']);
} catch (\InvalidArgumentException $e) {
    $instagramMessage = $e->getMessage();
}
assertEquals(
    'Unknown QR code type: "instagram". '
    . 'No payload builder function "buildPayload_instagram()" found.',
    $instagramMessage,
    'dispatcher: exact "unknown type" message for absent instagram builder'
);

// 📌 An empty builder result also throws (e.g. a builder returning '' would be
//    rejected by buildPayloadByType). 'rating' is likewise an absent type.
assertThrows(
    function (): void {
        buildPayloadByType('rating', ['stars' => '5']);
    },
    'dispatcher: rating has no builder -> throws',
    \InvalidArgumentException::class
);
