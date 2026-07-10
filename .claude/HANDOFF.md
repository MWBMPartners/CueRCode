# 🤝 CueRCode — Session Handoff

> **Last updated:** 2026-07-10 (Deep sweep #47 addendum — see §7 below; Round-1 planning content in §1-6 otherwise unchanged from 2026-07-09)
> **Read this first when resuming work on CueRCode from a cold start.**
> Companion docs: `PROJECT_ROADMAP.md` (repo root — the master launch plan this handoff summarises), `PROJECT.md` (autopilot memory/backlog), `.dev-team/autopilot.json` (machine state), `VERIFICATION.md` + `verdict.json` (independent verify evidence).

---

## 1. Current state (verified, not assumed)

- **Branch:** `autopilot/2026-06-28` (20 commits ahead of `main`), working tree clean at planning time.
- **PR #46** ("Autopilot: Phase-1 hardening, feature completion & full API parity") — OPEN, `mergeable: MERGEABLE`, `mergeStateStatus: BLOCKED` (awaiting owner review; no failing checks — checks list is empty). +11,584/−647 over 53 files. **Merging this is the first action of the whole roadmap.**
- **Quality bar at head (`f5cf408`):** VERIFY overall PASS (VERIFICATION.md); re-confirmed on 2026-07-09: `php tests/run.php` → **400/400 assertions pass**. 122/122 `php -l` clean. Zero-deprecation bar met. 0 unresolved Critical/High security findings (SECURITY.md: 20 findings, 16 fixed; open/queued = F-004 CSP/SRI, F-006 per-record salt, F-012 bot protection, F-013 Argon2id).
- **Product surface:** 26 QR types (counted in `fn_qr_payloads.php`), 6 output formats (JPEG XL host-blocked, #40), full UI↔API parity (#45 closed), OpenAPI 3.0.3 complete (7 paths) at `web/CueRCode.net/public_html/api/v1/openapi.json`, Swagger UI, bulk-generate, PWA, colourblind toggle, cookie banner, multi-jurisdiction legal TEMPLATE pages (counsel review pending, #38).
- **App version:** 0.1.1 (`public_html/infoAppVer.php`).
- **⚠️ Phase-2 reality check:** ONLY `tblUsers` + `tblOrganisations` schema stubs exist (`install.sql:346-393`). **No** session/oauth/webauthn/subscription/payment/reset/invitation tables and **zero auth code** — anything saying otherwise in older docs is wrong.

## 2. What is DONE (autopilot run, cycles 1–10 + VERIFY)

Safety-net test harness; 7+ correctness fixes; Phase-0 adversarial security audit (20 findings) + remediation of all auto-fixable ones; missing QR types + vCard 4.0 + legal templates; colourblind toggle + cookie banner + og-image; bulk-generate + deeplink + gs1_product types; API parity + complete OpenAPI + uniform endpoint security; standards/zero-deprecation polish; independent VERIFY PASS. GitHub reconciled by the loop: #45/#6/#39/#41/#42/#43/#32 closed with evidence.

## 3. What is QUEUED (gate ledger — owner approval needed)

From `.dev-team/autopilot.json` `gate_ledger` (all `awaiting-user`):

| Gate | What | Roadmap epic |
| --- | --- | --- |
| G-001 | User accounts + /save + /manage | E3 |
| G-002 | Payments (PayPal/Apple/Google) | E4 |
| G-003 | Subscription tiers + gating | E4 |
| G-004 | OAuth SSO + SIGNula | E2 |
| G-005 | MS Graph email | E8 |
| G-006 | WebAuthn/passkeys | E3 (stage 7) |
| G-007 | Dynamic QR redirect layer | E1a |
| G-008 | Scan analytics | E11 |
| G-009 | Admin dashboard | E3 (stage 8) |
| G-010 | Per-record encryption salt (schema) | E3 migration (DS-7) |
| G-011 | Out-of-scope differentiators | `for consideration` issues |
| G-012 | i18n scaffold | E9 |

Queued security: F-004 (SRI/CSP — needs live UI), F-006 (=G-010), F-012 (bot protection — owner keys), F-013 (Argon2id — E3 stage 2).
Owner-gated live tests: **#13, #17, #18, #22** (= epic E10, the soft-launch gate).

## 4. Roadmap epics in recommended order (detail in PROJECT_ROADMAP.md)

| Order | Epic | Model | Effort | Gate |
| --- | --- | --- | --- | --- |
| 1 | **E10** Soft-launch: merge PR #46 → deploy → live verify → harden | Owner + Haiku (runbook/scripts) | M | — |
| 2 | **Deep sweep** (Security/Syntax/Lint, DS-1..DS-10, incl. dev_alpha/beta copies) | Sonnet (+Haiku mechanical) | M | — |
| 3 | **E8** MS Graph email (root Phase-2 dependency) | Sonnet | M | G-005 |
| 4 | **E1a** Dynamic QR local redirect layer (+ **E5a/E5b** accessible presets/templates in parallel) | Sonnet | M | G-007 |
| 5 | **E3** Accounts & orgs (staged: schema→auth→email flows→API→UI→orgs→WebAuthn→admin) | Sonnet (+Haiku boilerplate) | L | G-001/G-006/G-009/G-010 |
| 6 | **E11** Scan analytics | Sonnet | M | G-008 |
| 7 | **E4** Tiers + PayPal payments | Sonnet (+one Opus review of the subscription state machine) | L | G-002/G-003 |
| 8 | **E2** SSO (M365/Google first; SIGNula behind flag pending upstream IdP work) | Sonnet (+one Opus contract review) | M | G-004 |
| 9 | **E6** Developer portal + **E7** platform API (keys/scopes/pagination/idempotency/webhooks) | Haiku docs / Sonnet code | M-L | — |
| 10 | **E9** i18n (after UI stabilises) + **E12** CI hardening (anytime) | Haiku/Sonnet | M / S-M | G-012 |

**Key external facts (verified 2026-07-09, full citations in PROJECT_ROADMAP.md E1/E2/E5):**
- **Go2My.Link:** near-launch (v1.0.0-rc); has pre-built CueRCode schema hooks (`qrCodeExternalUUID`, `createdVia='cuercode'`, `cuercode.*` settings) **but its public API is design-only** — blocked on their #38/#39/#75. Hence E1a (in-house redirect layer) first, Go2My.Link as a second driver (E1b) later.
- **SIGNula.id:** real API (v2.6.0-beta) but **NOT an OIDC IdP** — credential-proxy with opaque tokens. True SSO needs upstream work (issue drafted for their repo). CueRCode ships M365/Google OIDC first.
- **NaviLens/Zapvision:** proprietary; **cannot be generated by third parties** — partnership or nothing. CueRCode differentiates via open accessible-QR presets, Braille/tactile print templates, and (post-E1a) screen-reader-optimised /r/ landing pages.

## 5. Process rules (how this programme runs)

- **Fable = planning/conducting** (this doc + roadmap). **Sonnet = feature implementation. Haiku = mechanical/boilerplate/docs. Opus = only genuinely complex reasoning** (flagged per-epic: E4 payment state machine, E2 federation contract).
- **Per-item flow (project CLAUDE.md checklist):** GitHub issue first → implement fully commented → `php -l` + `php tests/run.php` + security check until zero issues → update issue/close with commit refs → update CHANGELOG.md, DEV_NOTES.md, README.md, PROJECT_STATUS.md → update `.claude/` memory **and this HANDOFF.md** → commit. **NEVER `git push`** — owner pushes/merges. Never commit secrets (`_auth_keys/` real files stay untracked).
- **OpenAPI first** for any API change (`openapi.json` is the source of truth — DEV_NOTES §Architecture 7).
- **Audit before closing issues** — never trust prior completion claims (`.claude/memory/feedback_audit_first.md`).
- **Unactioned ideas** → `for consideration` GitHub issues (user-global standing rule).
- **GitHub writes:** comment + close-with-evidence only; the conductor (not planning agents) creates issues.
- House style: full multi-line PHP, 4-space indent, detailed comments (emojis ok), PHP predefined constants, MySQLi prepared statements only, no layout `<table>`, WCAG 2.1 AA.

## 6. How to resume from a cold start

1. `git status && git log --oneline -5` — confirm branch (`autopilot/2026-06-28` until PR #46 merges, then `main`).
2. Read this file, then `PROJECT_ROADMAP.md` (§Recommended Next Actions), then `.dev-team/autopilot.json` (`state`/`next_action`/`gate_ledger`).
3. `php tests/run.php` — must be 400/400 (or higher) before touching anything; `find web tests -name '*.php' -exec php -l {} \;` clean.
4. `gh pr view 46` and `gh issue list --state open --limit 100` — reconcile against the roadmap's issue table (things may have moved since 2026-07-09).
5. Pick the lowest-numbered incomplete item in §Recommended Next Actions whose gates are approved; if an owner decision is pending (G-00X), surface it and work the next ungated item instead.
6. On finishing any item: run the per-item flow above and refresh this HANDOFF's §1/§3/§4 status lines.

**Do-not list:** no `git push`/deploy/live-testing without owner; no `git config`/remote edits; no production-code edits during planning rounds; drafts of new issues live in PROJECT_ROADMAP.md until the conductor files them.

---

## 7. Deep-sweep addendum (2026-07-10, GitHub issue #47, branch `launch-prep/2026-07-10`)

Executed the "Security / Syntax / Lint Deep-Review Plan" from PROJECT_ROADMAP.md — the DS-1..DS-10 worklist plus the full scan matrix, **including the `public_html_dev_alpha`/`public_html_dev_beta` env copies** that every prior cycle explicitly skipped. Full findings→fixes detail lives in SECURITY.md ("Deep-sweep pass (#47)" section) and CHANGELOG.md (Security/Fixed entry, 2026-07-10). Summary:

- **Fixed:** DS-1 (dev-env `.htaccess` now fail-closed `Require all denied` by default, Basic-Auth block commented with owner instructions), DS-2 (dev_alpha/dev_beta `infoAppVer.php` full standards pass), DS-3 (`deploy.yml` build-info injection moved to a marker-comment scheme — `// @build-info: <key>` anchors — decoupling it from the literal `NULL` casing; all 4 lines now lowercase `null` in all 3 `infoAppVer.php` copies), DS-4 partial (F-004: SRI added for Bootstrap CSS/JS bundle only — version-matched against `public_html_landing/index.php`; FontAwesome/jQuery left as explicit TODOs, no guessed hashes), DS-5 (`validateImageUpload()` docblock-annotated as the reserved E3 upload-path validator), DS-6 (install.sql passwordHash comment bcrypt→Argon2id), DS-9 (empty stray `.openai/` removed).
- **Reported, not deleted:** DS-10 — `.claude/ProjectcBrief_Chat.claude` vs `.claude/ProjectBrief_Chat.claude` are genuinely different historical chat transcripts (not a true duplicate), left for an explicit owner decision.
- **New finding fixed (not on the original DS list):** trailing `?>` in `public_html/api/docs/index.php` removed (last remaining entry page with one). 32 files across the tree were also found missing the `Path:`/`@copyright` docblock fields the project's own CLAUDE.md mandates — fixed mechanically across all of them (script-verified idempotent).
- **Deferred, as directed:** DS-7 (F-006 per-record salt — schema change, folds into E3/E4 migration, gate G-010), DS-8 (F-012 bot-protection keys — owner-supplied), CSP `unsafe-inline`→nonce (needs live UI verification, tracked under F-004 in SECURITY.md).
- **Re-scan evidence:** 123/123 `php -l` clean (was 122 at last count — one new file since), 400/400 `php tests/run.php` assertions green, zero deprecations across all 18 `_functions/fn_*.php` files, zero new OWASP-relevant findings on re-audit of all six Phase-0 surfaces (SQLi/XSS/CSRF/dangerous-functions/secrets/path-traversal probes all clean).
- **Not touched (owner-gated, unchanged from §1-6):** PR #46 merge, deploy secrets, live-server verification (#13/#17/#18/#22), E1a-E12 roadmap epics — none of that was in scope for issue #47.
- **Commits:** made directly on `launch-prep/2026-07-10` (this session did not create a new branch — it was already checked out). Work is commit-only per the issue directive; the conductor pushes.

---

## 8. Accessible-QR addendum (2026-07-10, GitHub issue #48, branch `launch-prep/2026-07-10`)

Implemented PROJECT_ROADMAP.md **E5 DRAFT ISSUE (a)** — "Accessible-QR presets, WCAG contrast validation & physical-size calculator" — the first of the three E5 draft issues (issue #48). Full detail in CHANGELOG.md (Added entry, 2026-07-10, #48) and FEATURES.md (FG-033, BUILT-partial).

- **Built:** `qrContrastRatio()` + `qrRelativeLuminance()` + `qrLineariseSrgbChannel()` (WCAG 2.1 relative-luminance contrast, pure/unit-tested) and `qrRecommendedPrintSizeMm()` (10:1 scan-distance rule, 20mm floor) in `fn_qr_options.php`; `applyAccessiblePreset()` wiring the two together plus forced `ecc=H`/`quiet_zone=true`/auto-version for `preset=accessible` — a byte-for-byte no-op for `preset=none`/absent (parity guarantee, verified — the pre-existing 400 assertions are untouched). `fn_api_generate.php` (`buildCustomizationFromRequest()`, `handleGenerate()`, `handlePreview()`) accepts `preset`+`scan_distance_cm`, rejects sub-3:1 contrast with HTTP 422 (`ACCESSIBLE_CONTRAST_TOO_LOW`) BEFORE `generateQRCode()` ever runs (no DB touch on rejection), and adds a `metadata` object to accessible-preset success responses. `openapi.json` documents both new params, a new `AccessibleMetadata` schema, and a worked 422 example. `index.php` + `cuercode-generator.js` add a keyboard-operable "Accessible QR" toggle, a live client-side WCAG contrast read-out (`role=status`/`role=alert`), and a live scan-distance → print-size calculator (`role=status`) — no `<table>` layout.
- **Tests:** `tests/test_accessibility.php`, 41 new assertions (real outputs captured via `php -r` before asserting, per this repo's convention) — contrast known-pairs, print-size 10:1+floor, preset forcing + fail/warning/pass contrast bands, request-param parsing, and an end-to-end subprocess proof that `handleGenerate()` actually emits the 422. Full suite: **441/441 assertions green** (was 400/400 before this cycle).
- **Verification run this cycle:** `php -l` clean on all 3 changed PHP files + the new test file; `node --check` clean on `cuercode-generator.js`; `openapi.json` re-validated as well-formed JSON via `php json_decode()`; `php tests/run.php` → 441/441.
- **Deliberately NOT built this cycle (tracked in FEATURES.md FG-033 as remaining):** E5 draft issue (b) — Braille-caption + tactile-frame FPDF print templates + Avery label sheets (separate, larger FPDF-layout piece); E5 draft issue (c) — NaviLens/Zapvision partnership assessment (pure owner decision, no code — should become a `for consideration` GitHub issue per the user's standing instruction, not yet filed since this session does not own GitHub writes).
- **Design decisions made without an explicit owner sign-off (flagging per the task's "surface for owner review" ask):**
  1. **Contrast thresholds are exactly as specified** (reject < 3:1, warn 3:1–4.5:1) — these came from the task/roadmap directly, not invented here, but the owner should confirm 4.5:1 (WCAG AA text-level) vs 3:1 (WCAG AA non-text/UI-component level) is the right split for a warning vs a hard reject for THIS preset (some accessibility guidance argues logos/QR codes should always target 4.5:1, i.e. the "warning" band could arguably also be a reject). Currently: only < 3:1 blocks.
  2. **`recommended_print_size_mm` metadata is scoped to `preset=accessible` only** — a caller can send `scan_distance_cm` without `preset=accessible` today and it is silently accepted but produces no metadata/effect. This matches the literal task wording ("expose... for accessible generations") but could be decoupled later if the owner wants the print-size calculator usable standalone via the API (the UI already shows it live regardless of the toggle state, client-side).
  3. **Bulk generation (`fn_api_bulk.php` / `/api/v1/bulk-generate`) was deliberately left untouched** — `preset`/`scan_distance_cm` are not yet threaded through the bulk pipeline. Out of the task's explicit scope; flagged here for a future parity pass if bulk + accessible presets together become a real use case.
- **Not touched (unrelated to #48):** PR #46 merge, deploy secrets, live-server verification, all other roadmap epics.
- **Commits:** made directly on `launch-prep/2026-07-10` (same branch as the #47 deep-sweep). Work is commit-only per the issue directive; the conductor pushes.

---

## 9. Braille-caption / tactile-frame / Avery PDF addendum (2026-07-10, GitHub issue #49, branch `launch-prep/2026-07-10`)

Implemented PROJECT_ROADMAP.md **E5 DRAFT ISSUE (b)** — "Braille-caption & tactile-frame print templates (FPDF) + Avery label sheets" (issue #49), completing the accessible-QR trio started in §8 (#48). Full detail in CHANGELOG.md (Added entry, 2026-07-10, #49) and FEATURES.md (FG-033, now **BUILT**, complete).

- **Built:** a new file `_functions/fn_qr_accessible_pdf.php` implements a `pdf_layout` customisation param (`standard`|`accessible_caption`|`tactile`|`avery_5160`|`avery_l7160`, `format=pdf` only). `standard` (absent/default) is a byte-for-byte no-op, verified via a subprocess test comparing the two request shapes. The other four values bypass the chillerlan `QRFpdf` output class entirely — the raw QR module matrix is obtained via `QRCode::getQRMatrix()` (using the library's own public segment-detection loop, `buildQrMatrixForAccessiblePdf()`) so the code, a large-print caption, a Braille caption, a tactile frame, and/or an Avery label grid can all be composed onto one FPDF canvas. `accessible_caption` = QR + ≥18pt caption + a Grade-1 Braille caption line drawn as geometric dot patterns (`drawFilledCircleOnPdf()` — a pure `Rect()`-strip circle rasteriser, since core FPDF has no circle/ellipse primitive and no Braille-glyph fonts; the maths is unit-tested via `circleStripHalfWidthMm()`). `tactile` adds a second page with CueRCode's own original tactile locator-frame design (`drawTactileLocatorFrame()` — NOT the Thai-patented Chulalongkorn design). `avery_5160`/`avery_l7160` tile the code into Avery label-sheet geometry (`renderAveryLabelSheetPdf()`); a single `/generate` call fills one label, and `fn_api_bulk.php` forks its ENTIRE response (ZIP → a single combined `application/pdf`) when the request-level shared customisation asks for an Avery `pdf_layout` (`bulkRequestWantsAveryLayout()` detects this; `handleBulkAverySheet()` streams it). `pdf_layout` is wired through `buildCustomizationFromRequest()`, the no-JS `index.php` POST fallback, a new UI `<select>` (shown only when format=PDF), `cuercode-generator.js`'s `collectCustomization()`, and `openapi.json` (full enum + the bulk response-shape fork + a new request example). New guidance page `public_html/accessibility/index.php` (linked from the footer) documents the whole print/embossing workflow.
- **Tests:** `tests/test_braille.php`, 65 new assertions — the Grade-1 transliteration table (letters/digits/single-vs-repeated number sign/an ended-then-restarted digit run/punctuation/space/an out-of-scope character rendering as a documented blank cell), dot-geometry helpers, the circle-strip maths (a 3-4-5 right-triangle spot check), the `pdf_layout` enum/param parsing, Avery sheet geometry for both SKUs, caption derivation/truncation, and the bulk Avery-sheet request detector — all pure/in-process. Four subprocess snippets (reusing `test_accessibility.php`'s `runAccessibilitySnippet()` helper, relying on the same documented alphabetical-load-order convention that file itself established) prove: (1) `standard` byte-parity, (2) `accessible_caption`, (3) `tactile`, and (4) `avery_5160` each produce a valid, non-trivial `%PDF`-prefixed document end-to-end through `generateQRCode()`. Full suite: **506/506 assertions green** (was 441/441 before this cycle).
- **Verification run this cycle:** `php -l` clean on all 7 changed/new PHP files + the new test file; `node --check` clean on `cuercode-generator.js`; `openapi.json` re-validated as well-formed JSON; `php tests/run.php` → 506/506. The generated PDFs (accessible_caption, tactile, avery_5160, and a 2-item bulk avery_5160) were also visually rendered and inspected during this session (not just byte-checked) to confirm the QR, caption, Braille dots, tactile frame, and label-sheet tiling all actually look correct — not just "a PDF came out".
- **Design decisions made without an explicit owner sign-off (flagging per house practice for anything not literally dictated by the issue):**
  1. **Tactile locator frame geometry is entirely my own invention** (10mm margin + 3mm-thick continuous square frame + four 8mm corner "L" registration marks + one 5mm bottom "orientation" dot) — the issue only said "design your own frame geometry... do not clone" the Thai patent; it did not specify dimensions. These numbers are a reasonable first cut, not lab-tested with actual blind users or a physical embosser. **Recommend a physical print/emboss trial with real assistive-tech users before treating this geometry as final.**
  2. **Braille dot pitch/diameter constants** ("Marburg Medium"-approximate: 1.5mm dot, 2.5mm intra-cell pitch, 6.0mm inter-cell pitch, 10mm line pitch) are commonly-cited figures gathered from general Braille-printing knowledge, not sourced from a single authoritative embosser spec sheet (no live web-fetch was performed for this pass). **Recommend verifying against the owner's actual target embosser/swell-paper vendor's spec before high-volume production use.**
  3. **Grade-1 punctuation subset is intentionally small** (period/comma/semicolon/colon/question/exclamation/hyphen/apostrophe) and the capital-letter handling applies a single capital-sign per letter (not the full UEB "passage" double-prefix optimisation for runs of capitals) — both are documented simplifications appropriate for a short caption, not full UEB compliance.
  4. **Avery sheet geometry** (5160/L7160 column/row pitches and margins) uses widely-published template dimensions rather than a physically measured sample sheet. **Recommend a test print on real Avery stock before a large run**, exactly as the guidance page itself now advises.
  5. **Bulk Avery mode forces every item onto the SAME sheet layout** (ignores any per-item `format`/`pdf_layout` override) since a mixed-layout sheet is not a coherent single PDF — this seemed like the only sane interpretation of "wire this into fn_api_bulk.php," but is a design call, not something the issue spelled out explicitly.
- **Not touched (unrelated to #49):** PR #46 merge, deploy secrets, live-server verification, all other roadmap epics. E5 draft issue (c) (NaviLens/Zapvision partnership assessment) remains a pure owner decision with no code — still not filed as a `for consideration` GitHub issue since this session does not own GitHub writes (conductor's job per the task directive).
- **Commits:** made directly on `launch-prep/2026-07-10` (same branch as #47/#48). Work is commit-only per the issue directive; the conductor pushes.
