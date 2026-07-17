# CueRCode - Project Instructions

## Project Overview

CueRCode is a web-based QR Code generation service with a responsive web UI and RESTful API. Built with PHP 8.4+/MySQL, hosted on Dreamhost Shared Hosting. Primary domain: CueRCode.net. Secondary: CueRCode.me. Tertiary: QRmy.Link.

## Memory

All Claude memory, context, and configuration should be stored in this `.claude/` directory within the repo.

## Standing Instructions

These instructions MUST be followed for EVERY prompt/action:

### Code Standards

- PHP: Full multi-line notation (no shorthand), detailed inline comments, use PHP predefined constants (DIRECTORY_SEPARATOR, PHP_EOL, etc.)
- PHP: Target PHP 8.5 with backward compatibility to PHP 8.4
- SQL: MySQLi only, prepared statements for ALL queries
- HTML5: Fully compliant, responsive, no `<table>` tags for layout
- CSS3+: Modern CSS with graceful fallbacks
- All code fully commented/annotated with inline comments and comment blocks (emojis welcome in comments)
- File headers must include: file path, copyright (2025-current year, MWBM Partners Ltd)
- Sensitive data encrypted with salt before database storage (AES-256-GCM with HKDF)
- All user inputs sanitised to prevent injections

### File Structure

- `web/CueRCode.net/` - Main site files
- `web/CueRCode.net/public_html/` - Production site root
- `web/CueRCode.net/public_html_dev_alpha/` - Alpha development build
- `web/CueRCode.net/public_html_dev_beta/` - Beta development build
- `web/CueRCode.net/public_html_landing/` - Public landing/coming soon page
- `web/CueRCode.net/public_html_redir/` - Redirect placeholder
- `web/CueRCode.net/_auth_keys/` - Site-specific auth credentials (non-web)
- `web/CueRCode.net/_includes/` - Site-specific includes (non-web)
- `web/CueRCode.net/_functions/` - Site-specific functions (non-web)
- `web/CueRCode.net/_libraries/` - Third-party PHP libraries (non-web)
- `web/CueRCode.net/_uploads/` - User uploads (non-web)
- `web/CueRCode.net/_backups/` - Backups (non-web)
- `web/_auth_keys/`, `web/_includes/`, etc. - Server-wide shared equivalents

### Per-Prompt Checklist

1. Create detailed GitHub Issue for the task (description, scope, acceptance criteria)
2. Implement the work with full comments/annotations
3. Run syntax, lint, and security checks - fix ALL issues until zero remain
4. Update GitHub Issue with status, close if complete, link commit numbers
5. Update: CHANGELOG.md, DEV_NOTES.md, README.md, PROJECT_STATUS.md
6. Update `.claude/` memory and context
7. COMMIT changes (DO NOT PUSH - user will push manually)

### Libraries (CDN-first with local fallback)

- Bootstrap 5.3.3, FontAwesome 6.5.1, jQuery 3.7.1
- PHP: chillerlan/php-qrcode v5.0.5, php-settings-container v3.2.1, FPDF v1.86
- Graphics: SVG-first with PNG/APNG fallback
- No Composer dependency - all PHP libraries manually downloaded/hosted

### UI Requirements

- Dark/Light mode (system default, manual toggle)
- Colourblind-friendly option
- WCAG 2.1 AA accessibility compliant
- AJAX with graceful JS-disabled fallback
- Bot protection: Cloudflare Turnstile > reCAPTCHA > hCaptcha (based on available API keys)
