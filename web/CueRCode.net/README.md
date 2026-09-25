# CueRCode - Website Directory

This directory contains all files for the CueRCode QR code generation service.

## Directory Layout

| Directory | Purpose |
| --- | --- |
| `_auth_keys/` | Site-specific database credentials and API keys (outside web root) |
| `_includes/` | Shared PHP includes (bootstrap, DB connection, HTML templates, SQL scripts) and `cron/` (command-line jobs, e.g. the email queue runner) |
| `_functions/` | PHP function files (QR engine, API handlers, utilities) |
| `_libraries/` | Third-party PHP libraries (chillerlan/php-qrcode, FPDF, tecnickcom barcode) |
| `_uploads/` | User-uploaded files (custom logos, generated QR codes) |
| `_backups/` | Site backup files |
| `public_html/` | Production web root (publicly accessible) |
| `public_html_dev_alpha/` | Alpha development environment (restricted access) |
| `public_html_dev_beta/` | Beta development environment (restricted access) |
| `public_html_landing/` | Pre-launch "Coming Soon" landing page |
| `public_html_redir/` | Redirection web root (limited use) |

> Directories prefixed with `_` are private and secured via `.htaccess` deny-all rules.

> `_auth_keys/auth_creds.php` must be created by hand on the server - it is not
> in the repo (`.gitignore` blocks it) and the auto-deploy does not create it.
> See the root [README.md](../../README.md) for the exact steps and the
> `define()` shape it needs.

> The third-party libraries listed above under `_libraries/` are not in the
> repo either, and the auto-deploy does not upload them - they must be
> downloaded and placed on the server by hand, once (root README.md has the
> download links and target folders).

## Deployment

Point your web server document root to `public_html/` for production,
or `public_html_dev_beta/` / `public_html_dev_alpha/` for testing environments.

## Scheduled jobs

`_includes/cron/process_email_queue.php` sends the emails the site queues
(sign-up verification, password reset). Once email is set up it **must** be
run by a DreamHost cron job every minute - without it no email is ever sent
(#112). It is command-line only and refuses web requests. See the root
[README.md](../../README.md) ("The email queue job (DreamHost cron)") for the
exact command.
