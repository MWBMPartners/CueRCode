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

- [ ] **G-007 — dynamic redirect layer** (`/r/{uuid}`). Foundational for dynamic QR (#27), dual-function QR (#71), scan analytics (#33), and geo/platform rules. **The app becomes a redirect host** (phishing/abuse/uptime surface) — this is the key decision that unlocks a whole cluster of features. **See Section H below for the full owner-decision list (O-1…O-11) this gate is waiting on.**
- [ ] **Dual-function QR / ProjectEPass** (#71 + ProjectEPass#18) — approve the sanctioned-app credential + payload-signing scheme; agree the scan-to-check-in contract with the ProjectEPass team. See H, O-6/O-7/O-8/O-10.
- [ ] **F-004 CSP/SRI** completion (needs live-UI verification).

## G. Feature scope confirmations

- [ ] **Emoji asset set** (#67): Twemoji (CC-BY, ships PNG+SVG — recommended) vs Noto (needs PNG build); full ~3,700 set vs curated starter subset.
- [ ] **Emoji-ingest** automation (#68): scheduled Action opens a **bot PR** vs a **diff-report issue**.
- [ ] **Barcode library** (#69): confirm vendoring `tecnickcom/tc-lib-barcode` (LGPL-3) for 1D+2D.

## H. Dynamic + dual-function QR (gated) — issues #27 / #71 / #33, gate G-007

> **Build status:** Stages **S0** (schema + pure core) and **S1** (owner-facing
> destination API) are **BUILT and DARK/INERT** — see
> `.dev-team/dynamic_dualfn_design.md` for the full phased plan and
> `.claude/HANDOFF.md` §0 for the implementation summary. **Nothing is live**:
> `tblQRRedirects`/`tblQRScans`/`tblResolveNonces` exist but are unreachable by
> any public route; the `dynamic_url` QR type seeds `isActive=0` (invisible to
> `GET /api/v1/types`); the `dynamic_qr_enabled` kill-switch seeds `'0'`; and
> there is **no `public_html/r/` redirect endpoint anywhere in the codebase**.
> **Stage S2 (the live `/r/{uuid}` route) and Stage S4 (dual-function QR /
> `GET /api/v1/resolve/{uuid}`) are BUILT-READY (fully designed, schema in
> place) but NOT STARTED — both are explicitly gated on the G-007 owner
> sign-off below**, per this cycle's build instructions.

The following owner decisions (design doc §9, `O-1` through `O-11`) must be
made — or explicitly deferred with a documented default — before Stage S2
work begins:

- [ ] **O-1 — G-007 go/no-go** (the master gate): approve CueRCode as a
      public redirect host. **Split sign-off requested**: (a) alpha test
      group at Stage S2, (b) production/main promotion only once every §2.6
      MUST-list item in the design doc is evidenced (abuse-report page +
      takedown runbook + SLA, auto-suspend tripwire live, rate limits
      burst-tested, ToS covering redirect abuse, reputation-provider decision
      made, daily anomaly monitoring, Cloudflare proxy recommended).
- [ ] **O-2 — Destination schemes**: `https`-only at launch (**recommended
      default — already the seeded behaviour**: `redirect_allow_http=0`) vs
      allowing `http` behind the `redirect_allow_http` setting.
- [ ] **O-3 — URL-reputation provider**: none / Google Safe Browsing / Web
      Risk. Needs an owner-supplied API key + a `privacy.php` disclosure
      (destination URLs would be shared with the provider); confirm the
      sync-check **fail-open** stance (a third party being unreachable must
      never block an owner's own edit).
- [ ] **O-4 — Abuse ops**: auto-suspend threshold (default proposed: 3
      distinct-IP reports within 24h), takedown SLA wording, an `abuse@`
      mailbox.
- [ ] **O-5 — Cloudflare proxy** in front of the redirect host before
      production (recommended; org Cloudflare accounts already exist).
- [ ] **O-6 — ProjectEPass signature contract**: HMAC-SHA256 with
      server-side verification + a "secret never ships in the app binary"
      clause (**recommended**); Ed25519 deferred to the offline `/e/{segments}`
      variant. Confirm on ProjectEPass#18, including per-organiser-org vs
      per-platform sanctioned-app registration granularity.
- [ ] **O-7 — Nonce TTL / clock skew**: 120s TTL / ±30s skew defaults —
      confirm with the ProjectEPass team.
- [ ] **O-8 — `dual_function_qr` tier placement**: **seeded now** as
      business+enterprise only (Pricing_Strategy.md §3.4/§3.5 — no enterprise
      plan is seeded yet, so only `business` currently has it `true`) vs an
      enterprise-only restriction; confirm the `sanctioned_apps` limit values
      (1 / −1 proposed) when Stage S4 seeds them.
- [ ] **O-9 — FG-006 (geo/platform variant rules) deferral**: confirmed —
      seam-only for now (`resolveRedirectDestination()` is the single future
      plug-in point), no owner action needed unless this changes.
- [ ] **O-10 — Check-in authority**: ProjectEPass is authoritative for
      check-in; CueRCode only logs resolve events (**recommended** — confirm
      as ProjectEPass#18's final contract point).
- [ ] **O-11 — Printed redirect domain**: `cuercode.net/r/` vs the tertiary
      **`QRmy.Link`** (shorter URL → lower QR version/larger modules, and —
      decisive for the abuse-reputation posture — partitions redirect-abuse
      reputation away from the primary product domain, so a Safe-Browsing
      blocklist event on the redirect domain never takes the main site down
      with it). **Seeded now** as `https://QRmy.Link`
      (`dynamic_redirect_base_url` tblSettings key,
      `buildPayload_dynamic_url()`) — **owner-confirmable before any code is
      printed for real; hard to change after printed material is in
      circulation.**

---

### Cross-references
- Strategy: `Pricing_Strategy.md` · Roadmap: `PROJECT_ROADMAP.md` · State: `.claude/HANDOFF.md` (§0) · Findings: `SECURITY.md` · Machine state: `.dev-team/autopilot.json` · Dynamic-redirect design: `.dev-team/dynamic_dualfn_design.md`.
- Open issues for the above: #64 (deps/security), #65/#66/#67/#68 (logo/emoji), #69 (barcodes), #70 (flexible pricing), #71 (dual-function QR), #27 (dynamic QR), #33 (scan analytics), #25/#53 (tiers/PayPal), #26 (PayPal), #29 (email), #37/#38 (privacy/legal), #55/#19/#20 (SSO), #13/#17/#18/#22 (live tests), #82 (this cycle's S0/S1 tracking issue).
