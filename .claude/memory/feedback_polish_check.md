---
name: Polish check - no "AI-made" tells
description: Run tools/check_site_polish.php + a real-browser pass at 6 widths before every alpha PR and after any page/CSS/JS change (2026-10-05, #137)
type: feedback
---

After any change to pages, templates, CSS or JS, and before every `work-in-progress → alpha` PR: run `php tools/check_site_polish.php` (0 problems), then open each changed page in a real browser at 320/375/768/1024/1366/1920 px, light and dark, with long text, empty data and error states, and click every new or changed button and link. Record what was and wasn't checked in the issue.

**Why:** Owner request 2026-10-05: "find the tiniest details that make someone look at my app for five seconds and think, yeah, this is vibe-coded AI slop" - and keep checking automatically, here and in every project. The first sweep found the live site's Bootstrap stylesheet blocked by a wrong (half-invented) SRI hash, a navbar showing "▣" instead of the logo, a clipped QR-type grid on every screen size, internal epic/ticket notes on public developer pages, and emoji developer comments in every page's "View source".

**How to apply:** Script = the deterministic half (it also compares every CDN integrity hash with the committed fallback copy). The human-eye half can't be scripted: use headless Chromium/Playwright screenshots in a cloud session. The real site needs a database to render: install MariaDB, load `_includes/sql/install.sql` + `seed_data.sql`, put a local-only `_auth_keys/auth_creds.php` (gitignored), and serve with `php -S` plus a small router that mimics `.htaccess`. Device-wide copy: `.claude/DEVICE_WIDE_RULES.md`.
