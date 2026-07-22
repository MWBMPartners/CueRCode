# CueRCode - Website Directory

This directory contains all files for the CueRCode QR code generation service.

## Directory Layout

| Directory | Purpose |
| --- | --- |
| `_auth_keys/` | Site-specific database credentials and API keys (outside web root) |
| `_includes/` | Shared PHP includes (bootstrap, DB connection, HTML templates, SQL scripts) |
| `_functions/` | PHP function files (QR engine, API handlers, utilities) |
| `_libraries/` | Third-party PHP libraries (chillerlan/php-qrcode, FPDF) |
| `_uploads/` | User-uploaded files (custom logos, generated QR codes) |
| `_backups/` | Site backup files |
| `private_html/` | Private HTML (placeholder, reserved for future use) |
| `public_html/` | Production web root (publicly accessible) |
| `public_html_dev_alpha/` | Alpha development environment (restricted access) |
| `public_html_dev_beta/` | Beta development environment (restricted access) |
| `public_html_landing/` | Pre-launch "Coming Soon" landing page |
| `public_html_redir/` | Redirection web root (limited use) |

> Directories prefixed with `_` are private and secured via `.htaccess` deny-all rules.

## Deployment

Point your web server document root to `public_html/` for production,
or `public_html_dev_beta/` / `public_html_dev_alpha/` for testing environments.
