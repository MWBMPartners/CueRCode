# 📱 CueRCode - Professional QR Code Generator

A web-based service for generating customisable, downloadable QR codes via a fully responsive web UI and RESTful API.

## 🌐 Domains

| Domain | Purpose |
| --- | --- |
| 🟢 **CueRCode.net** | Primary domain - main application |
| 🔵 **CueRCode.me** | Secondary domain - redirects to CueRCode.net ("QR Code me!") |
| 🟣 **QRmy.Link** | Tertiary domain - redirects to CueRCode.net with web link QR function |

## 📋 Overview

**CueRCode** is a comprehensive QR code generation platform that supports:

- 📊 **21+ QR code types**: URLs, text, email, phone, SMS, WhatsApp, Zoom, geolocation, vCard, calendar events, PayPal, Bitcoin, media files, social media, Wi-Fi, and more
- 🎨 **7 output formats**: PNG, JPG, GIF, SVG, WEBP, PDF, JPEG XL
- 🖌️ **Full customisation**: colours, patterns, marker shapes, centre logos, frames with labels, gradients, transparency
- 🔌 **RESTful API**: JSON and XML responses, API key authentication, rate limiting, Swagger/OpenAPI documentation
- 📱 **Responsive Web UI**: Bootstrap 5, dark/light mode, AJAX live preview, WCAG accessibility

## ⚙️ Tech Stack

| Component | Technology |
| --- | --- |
| 🖥️ Backend | PHP 8.5 (backward-compatible with 8.4) |
| 🗄️ Database | MySQL via MySQLi (prepared statements only) |
| 📦 QR Library | [chillerlan/php-qrcode](https://github.com/chillerlan/php-qrcode) v5.x |
| 🎨 Frontend | HTML5, CSS3, Bootstrap 5.3, FontAwesome, jQuery |
| 📖 API Docs | Swagger/OpenAPI 3.0 |

## 📁 Project Structure

```text
CueRCode/
├── .claude/                        ← 🤖 Claude AI context and prompts
├── .openai/                        ← 🤖 OpenAI/ChatGPT context and prompts
├── web/                            ← 🌐 All web-deployable files
│   ├── _auth_keys/                 ← 🔐 Server-wide credentials (outside web root)
│   ├── _includes/                  ← 📦 Server-wide shared includes
│   ├── _functions/                 ← ⚙️ Server-wide shared functions
│   ├── _libraries/                 ← 📚 Server-wide shared libraries
│   ├── _uploads/                   ← 📤 Server-wide uploads
│   ├── _backups/                   ← 💾 Server-wide backups
│   └── CueRCode.net/              ← 🌐 Main CueRCode.net website
│       ├── _auth_keys/             ← 🔐 Site-specific credentials
│       ├── _includes/              ← 📦 Site-specific includes (bootstrap, SQL, templates)
│       ├── _functions/             ← ⚙️ Site-specific functions (QR engine, API handlers)
│       ├── _libraries/             ← 📚 Third-party libraries (chillerlan, FPDF)
│       ├── _uploads/               ← 📤 User uploads (logos, generated QR codes)
│       ├── _backups/               ← 💾 Site backups
│       ├── public_html/            ← 🌍 Production web root
│       ├── public_html_dev_alpha/  ← 🧪 Alpha development environment
│       ├── public_html_dev_beta/   ← 🔬 Beta development environment
│       ├── public_html_landing/    ← 🚀 Pre-launch landing page
│       └── public_html_redir/      ← ↩️ Redirection web root
├── CHANGELOG.md
├── PROJECT_STATUS.md
└── DEV_NOTES.md
```

> 🔒 **Note:** Directories prefixed with `_` (underscore) are private and not web-accessible, secured via `.htaccess` deny-all rules.

## 🚀 Getting Started

### Prerequisites

- 🐘 PHP 8.4 or 8.5 with extensions: `mbstring`, `gd`, `json`, `openssl`, `mysqli`
- 🗄️ MySQL 5.7+ or MariaDB 10.3+
- 🌐 Apache with `mod_rewrite` enabled

### Installation

1. **📥 Clone the repository:**

   ```bash
   git clone https://github.com/MWBMPartners/CueRCode.git
   ```

2. **📦 Download third-party libraries** (not included in repo):
   - [chillerlan/php-qrcode v5.x](https://github.com/chillerlan/php-qrcode/releases) → `web/CueRCode.net/_libraries/chillerlan/php-qrcode/`
   - [chillerlan/php-settings-container v3.x](https://github.com/chillerlan/php-settings-container/releases) → `web/CueRCode.net/_libraries/chillerlan/php-settings-container/`
   - [FPDF v1.86](https://www.fpdf.org/) → `web/CueRCode.net/_libraries/fpdf/`

3. **🔐 Configure credentials:**

   ```bash
   cp web/_auth_keys/auth_creds.example.php web/_auth_keys/auth_creds.php
   cp web/CueRCode.net/_auth_keys/auth_creds.example.php web/CueRCode.net/_auth_keys/auth_creds.php
   ```

   Edit the copied files with your actual database credentials and encryption keys.

4. **🗄️ Run the database installer:**
   Navigate to `https://your-domain/install/` in your browser.

5. **🌍 Point your web server** to `web/CueRCode.net/public_html/` as the document root.

### 🧪 Development Environments

| Environment | Directory | Access |
| --- | --- | --- |
| 🟢 Production | `public_html/` | Public |
| 🔬 Beta | `public_html_dev_beta/` | Restricted (`.htaccess` auth) |
| 🧪 Alpha | `public_html_dev_alpha/` | Restricted (`.htaccess` auth) |
| 🚀 Landing | `public_html_landing/` | Public (pre-launch only) |

## 📖 API Documentation

- 📚 **Developer Portal**: `/developers/` - quickstart, authentication guide, a live endpoint reference
  rendered directly from `openapi.json` (no hand-copied duplicate), an errors/rate-limit reference, code
  samples (curl/PHP/JavaScript/Python), and integration recipes.
- 🧪 **Interactive Swagger UI**: `/api/docs/` - explore and try out every endpoint against the live spec.

### 💡 Quick Example

`/generate` always requires authentication (an `X-API-Key` header, or a session `X-CSRF-Token` for the web
UI's own AJAX calls) - see the [Authentication guide](https://cuercode.net/developers/authentication/) for
how to obtain a key, or the [Quickstart](https://cuercode.net/developers/quickstart/) for a genuinely
anonymous zero-setup path via `/bulk-generate`:

```bash
curl -X POST https://cuercode.net/api/v1/generate \
  -H "Content-Type: application/json" \
  -H "X-API-Key: cuercode_your_api_key_here" \
  -d '{
    "type": "url",
    "input": {"url": "https://example.com"},
    "customization": {"size": 800, "ecc": "H", "format": "png"}
  }'
```

## 🔮 Future Integration

- 🔗 **Go2My.Link**: URL shortener integration for dynamic QR code links
- 🔑 **SIGNula**: Single sign-on and account management integration

## 👥 Contributing

This project is maintained by MWBM Partners Ltd. See [DEV_NOTES.md](DEV_NOTES.md) for development conventions and guidelines.

## 📄 License

Copyright &copy; 2026 MWBM Partners Ltd. All rights reserved.

---

*Built with 🐘 PHP, 🗄️ MySQL, 🎨 Bootstrap, and [📦 chillerlan/php-qrcode](https://github.com/chillerlan/php-qrcode)*
