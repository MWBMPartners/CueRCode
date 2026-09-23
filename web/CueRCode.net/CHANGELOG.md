# CueRCode - Website Changelog

> **Note (2026-09-23, #103):** this file stopped being updated in February
> 2026. The root [`/CHANGELOG.md`](../../CHANGELOG.md) is the maintained,
> current changelog for the whole project - this file is kept only for
> historical reference to the site's very earliest changes.

All notable changes to the CueRCode website will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added - Phase 1, Stage 1 (2026-02-27)

- Initial website directory structure
- Security `.htaccess` files for all private directories
- Credential templates for database and API key configuration (not tracked in git: blocked by `.gitignore`)
- Master `.htaccess` with URL rewriting, security headers, and PHP settings
- Custom PSR-4 autoloader for Composer-free library loading
- "Coming Soon" landing page with responsive design
- PWA manifest and robots.txt
- Development environment access control (alpha/beta)
