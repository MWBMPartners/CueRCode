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
