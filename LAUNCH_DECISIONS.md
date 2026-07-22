<!--
  =============================================================================
  🚀 CueRCode - Pre-Launch Decisions & Owner Actions
  File: LAUNCH_DECISIONS.md
  @copyright 2025-2026 MWBM Partners Ltd. All rights reserved.
  =============================================================================
-->

# 🚀 CueRCode — Pre-Launch Decisions & Owner Actions

> **Run through this checklist before launching.** It collects the decisions and
> external setup that **only the owner** can make — things the autonomous build
> cannot decide (money, credentials, legal, cost, live-server, and abuse-surface
> go/no-go calls). Each item links the tracking issue. Last updated **2026-07-22**.

---

## A. GitHub repo / security settings (Settings → Code security)  — issue #64

- [ ] Enable **Dependabot alerts** + **Dependabot security updates**.
- [ ] Enable **Secret scanning** + **push protection**.
- [ ] **GitHub Advanced Security / Code Security** for CodeQL (per-committer cost on private repos). Decision received: **"Both"** (Semgrep now + GHAS for CodeQL). After enabling, remove the two transitional `continue-on-error` lines in `.github/workflows/codeql.yml`.
- [ ] **Vendored-library dependency coverage** (PHP libs + CDN JS have no manifest, so Dependabot can't see them). Choose: **(A)** metadata-only `composer.json`/`package.json` for advisory PRs, or **(B)** a scheduled "vendored-deps watch" Action.
- [ ] **Merge PR #72** (deps/security → `main`) — required for Dependabot to read `.github/dependabot.yml` from the default branch.
- [ ] Add **branch protection** + required status checks on `main`, `alpha`, **and** `beta` (all three receive direct pushes via `deploy.yml`).
- [ ] Replicate the scanning workflows (codeql/semgrep/ci) onto **`beta`** (follow-up after #72/#74 merge).

## B. Pricing & billing  — see `Pricing_Strategy.md`, issues #25 / #53 / #70

- [ ] **Final GBP price points** (DRAFT): Pro £6.99/mo · £49.99/yr; Business £24.99/mo · £199.99/yr; Enterprise floor ~£99/mo.
- [ ] **Currency** at launch: GBP-only vs +USD/EUR price points (schema supports multi-currency per price-point).
- [ ] **VAT** display + registration treatment on `/pricing/`.
- [ ] **Launch discount**: automatic 25% off ×12 months, end 2026-12-31 — go/no-go.
- [ ] **Founder Lifetime** deal: £119.99 one-off, 500-redemption cap, 60-day window — go/no-go.
- [ ] **Education / non-profit** discount: 50% forever + verification method.
- [ ] **PAYG**: at launch or post-launch; pack prices (£4.99/£19.99/£59.99); monthly spend cap (£20).
- [ ] **Trial stance**: freemium-only vs card-free 14-day Pro trial.
- [ ] **Minimum sellable Pro**: gate charging on the E1a dynamic-QR + analytics MVP shipping (don't sell "dynamic codes" that don't exist yet).
- [ ] **Refund / cancellation** terms (30-day money-back? cooling-off?) → `terms.php`.
- [ ] **Grandfathering** commitment wording for early adopters.
- [ ] Confirm **accessibility outputs stay free** at all tiers (recommended differentiator).
- [ ] **Barcode generation** tier placement (#69): Anonymous vs Free-and-up.

## C. Payments & external providers

- [ ] **PayPal** business credentials + webhook endpoint (#26, gate G-002).
- [ ] **SSO IdP app registrations**: Microsoft 365 + Google (client BUILT-inert) (#19/#20/#55, gate G-004).
- [ ] **Microsoft Graph email** creds + DKIM/DMARC (#29, gate G-005) — unblocks email verification/reset.
- [ ] **Bot-protection keys**: Cloudflare Turnstile > reCAPTCHA > hCaptcha (F-012).

## D. Legal

- [ ] **Legal-counsel review** of `privacy.php` / `terms.php` (multi-jurisdiction templates) (#38).
- [ ] Data-processing / GDPR sign-off (export + erasure already BUILT).

## E. Live-server verification (E10 soft-launch gate) — owner-run

- [ ] **#13** — installer on live Dreamhost (tables/seed/lock).
- [ ] **#17** — all 26 QR types × 6 formats generate scannable output.
- [ ] **#18** — all API v1 endpoints (CORS, rate-limit, auth, Swagger).
- [ ] **#22** — responsive + WCAG on real devices/screen-readers.
- [ ] **`php-imagick` availability on Dreamhost** — determines whether **raster** predefined/emoji logos work at request time (#65/#67). If absent, run `tools/render_logo_pngs.php` once in an Imagick-equipped env to commit PNG twins. (SVG-output logos work regardless.)
- [ ] Confirm `SFTP_*` secrets + `SFTP_ENABLED=true` for auto-deploy.

## F. Architecture gates (owner go/no-go)

- [ ] **G-007 — dynamic redirect layer** (`/r/{uuid}`). Foundational for dynamic QR (#27), dual-function QR (#71), scan analytics (#33), and geo/platform rules. **The app becomes a redirect host** (phishing/abuse/uptime surface) — this is the key decision that unlocks a whole cluster of features.
- [ ] **Dual-function QR / ProjectEPass** (#71 + ProjectEPass#18) — approve the sanctioned-app credential + payload-signing scheme; agree the scan-to-check-in contract with the ProjectEPass team.
- [ ] **F-004 CSP/SRI** completion (needs live-UI verification).

## G. Feature scope confirmations

- [ ] **Emoji asset set** (#67): Twemoji (CC-BY, ships PNG+SVG — recommended) vs Noto (needs PNG build); full ~3,700 set vs curated starter subset.
- [ ] **Emoji-ingest** automation (#68): scheduled Action opens a **bot PR** vs a **diff-report issue**.
- [ ] **Barcode library** (#69): confirm vendoring `tecnickcom/tc-lib-barcode` (LGPL-3) for 1D+2D.

---

### Cross-references
- Strategy: `Pricing_Strategy.md` · Roadmap: `PROJECT_ROADMAP.md` · State: `.claude/HANDOFF.md` (§0) · Findings: `SECURITY.md` · Machine state: `.dev-team/autopilot.json`.
- Open issues for the above: #64 (deps/security), #65/#66/#67/#68 (logo/emoji), #69 (barcodes), #70 (flexible pricing), #71 (dual-function QR), #27 (dynamic QR), #25/#53 (tiers/PayPal), #26 (PayPal), #29 (email), #37/#38 (privacy/legal), #55/#19/#20 (SSO), #13/#17/#18/#22 (live tests).
