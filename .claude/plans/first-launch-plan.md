# Plan: CueRCode's first launch (free service plus dynamic QR codes)

Written 2026-11-27 by an Opus planning agent (high effort), against `work-in-progress` at `1201cff`. Not committed.
Scope decision: `docs/decisions/0023` (first version = free service plus dynamic QR, nobody charged). Related: 0024 (human check, three providers), 0025 (tests before deploy), 0026 (ask before the alpha pull request), 0001 (model tiers), 0008 (push after each reviewed phase), and `LAUNCH_DECISIONS.md` section H (owner answers O-1 to O-11, 2026-09-25).
Milestone on GitHub: "First launch — free service + dynamic QR" (number 10).

Words used in this plan:
- **Dynamic QR code**: a printed QR code that holds a short web address; the place that address sends people to (the "destination") can be changed later without reprinting.
- **Go2My.Link**: the owner's sister project, a link-shortening service. Its code is in `../Go2My.Link` and `MWBMPartners/Go2My.Link` on GitHub. It has two parts: a dashboard and API at go2my.link, and a "redirect server" that answers short addresses (g2my.link and any extra domain an organisation adds).
- **QRmy.Link**: the short web address owned by the owner that will be printed in CueRCode's dynamic codes (owner answer O-11).
- **API key**: a secret password that one server uses to call another server's API.
- **Short code**: the part after the slash, for example `Ab3dE9x` in `https://QRmy.Link/Ab3dE9x`.
- **Takedown**: stopping a dynamic code from sending people to a harmful page.
- **Link-safety check**: asking an outside service (Google Web Risk) whether a destination is a known phishing or malware page.

---

## 0. Facts established first (dynamic QR on Go2My.Link)

The owner's requirement (comment on CueRCode #27, 2026-09-25, and LAUNCH_DECISIONS.md O-1): dynamic QR must **reuse Go2My.Link's short-link system on the QRmy.Link address**, not a separate CueRCode engine. The older design, `.dev-team/dynamic_dualfn_design.md`, predates that and describes CueRCode's own `/r/{uuid}` redirect route. Its abuse rules (section 2.6) still apply; its redirect route does not.

"Verified" below means I read the code in git or measured it today. "Document only" means I read it in an issue or handoff and did not check it.

### 0.1 What exists in Go2My.Link for CueRCode
| Fact | How known |
|---|---|
| CueRCode wiring (Go2My.Link #145) is commit `8ff478e` (2026-07-19). `POST /api/v1/urls` accepts `qr_external_id` and `qr_external_uuid`, needs the key scope `qr:link` and the setting `cuercode.integration_enabled` (403 otherwise), stores `createdVia='cuercode'`, answers 409 if the QR id was already used. Reply: `short_code`, `short_url`, `qr_code_external_uuid`. | Verified in `web/Go2My.Link/public_html/api/v1/handlers/urls.php` |
| `PUT /api/v1/urls/{code}` changes `destination_url`, `title`, `tags` only. It **cannot switch a link back on**. `DELETE` switches a link off (`isActive=0`). | Verified, same file |
| The 409 reply for a reused QR id **does not include the existing short code**, and `GET /api/v1/urls/{code}` **does not return the QR id**. So if CueRCode's create call times out after Go2My.Link saved the link, CueRCode cannot find that link again unless CueRCode chose the short code itself. A caller-chosen code is allowed only when the setting `cuercode.allow_external_shortcode` is on; codes must match `^[A-Za-z0-9_-]{3,50}$` and avoid a short reserved list (`api`, `admin`, `www`, `expired`, ...). | Verified (`urls.php`, `shorturl_create.php`) |
| Both settings above default to **off** (`0`). | Verified, `web/_sql/seeds/013_cuercode_settings.sql` |
| An API key belongs to one Go2My.Link user in one organisation. So CueRCode would use **one** Go2My.Link organisation for all its links and must keep track of which CueRCode person owns which link itself. | Verified (`urls.php` uses `$keyRow['orgHandle']`, `userUID`) |
| Scan counts: `GET /api/v1/urls/{code}` returns `click_count`; `GET /api/v1/analytics/{code}` returns totals, clicks over time and a scan-source breakdown (default last 30 days). A scan is marked as a QR scan only when the address carries `?src=qr`. | Verified (`urls.php`, `handlers/analytics.php`, `web/G2My.Link/public_html/index.php`) |
| Go2My.Link API rate limits: a daily limit per key from the organisation's plan or `api.default_daily_limit` (5000), and 60 calls a minute per key (`api.default_per_minute`); a per-key override exists. Link creation is also capped by the organisation's plan. | Verified (`web/_functions/api_ratelimit.php`, `urls.php`) |
| The shared destination check `g2ml_validateLinkDestination()` (Go2My.Link #205, commit `5872fa7`) refuses bad addresses, internal and cloud-metadata hosts, and Go2My.Link's own short domains (including any verified custom domain). | Verified in code; it is on the feature branch only (see 0.2) |
| The redirect server answers with a **302** (temporary) redirect, but sends **no** `Cache-Control: no-store` and **no** `X-Robots-Tag` header, and has **no rate limiting** of its own. | Verified (`redirect_resolver.php` `buildRedirectResponse()`; searched `web/G2My.Link/` for rate limits, 429, Cache-Control) |
| A switched-off link shows Go2My.Link's 410 page "This short link has been disabled by its owner". | Verified (`web/G2My.Link/public_html/index.php`) |

### 0.2 Which branch, and has it ever run live
| Fact | How known |
|---|---|
| `8ff478e` (#145) is on Go2My.Link `alpha` and `release-candidate`, **not on `main` or `beta`**. `5872fa7` (#205) is **only** on `feat/2026-09-21-linkspage-and-sweep` (not on `alpha`). | Verified against GitHub today with `gh api .../compare` |
| Go2My.Link's deploy workflow `sftp-deploy.yml` only uploads when the repository variable `SFTP_ENABLED` is `true`. It is `false` (set 2026-07-19 18:18 UTC). Every deploy run since then shows "Deploy via SFTP = skipped". The only upload job that ran was a manual run on 2026-07-19 at 18:15 UTC on commit `40c9291`, which **does not contain** `8ff478e`. Its logs have expired, so I cannot tell whether it was a dry run. | Verified (`gh run list`, `gh run view`, `gh variable list`, `git merge-base`) |
| So the CueRCode wiring has **never been uploaded by the deploy workflow**. A hand upload cannot be ruled out, but nothing suggests one. | Verified as far as above |
| Today `https://go2my.link/` shows "Go2My.link — Coming Soon" and `https://go2my.link/api/v1/ping` returns 404, so the new Go2My.Link API is not live there. | Measured today with curl |
| Go2My.Link's own goal is "ready for a new install" (its decisions D-001, D-002, 2026-11-26). D-002 says the new install must also move the links and accounts from the older service, MWlink, and that work is not done. | Document only (`docs/decisions/` and `.github/HANDOFF.md` on its feature branch) |

**Conclusion:** the Go2My.Link side is written and tested in its own repository, but it is not on `main`, the newest safety fix is not even on `alpha`, and **no live Go2My.Link install exists that could serve QRmy.Link today**. CueRCode's dynamic QR launch therefore depends on Go2My.Link's own install. That is owner question Q1, with a fallback in Q2.

### 0.3 QRmy.Link today (measured 2026-11-27)
- DNS is on DreamHost name servers, **not Cloudflare** (owner answer O-5 wants Cloudflare in front before public launch).
- `http://qrmy.link/` sends visitors to a parked page; `https://qrmy.link/` has a **self-signed certificate**, so browsers would show a security warning.
- g2my.link and go2my.link are already behind Cloudflare.

### 0.4 What QRmy.Link needs to work as a Go2My.Link custom domain
Go2My.Link already supports an organisation's own short domain (verified, `web/_functions/org.php` `addOrgShortDomain()`, `web/G2My.Link/_functions/domain_resolver.php` `getOrgByDomain()`): the organisation adds the domain, it starts "pending" and switched off, the organisation proves it owns the domain with a DNS TXT record, and only then does the redirect server answer that domain for that organisation. New links use the organisation's default verified domain (`getDefaultShortDomain()`). So no new Go2My.Link code is needed for the domain itself. Needed: a "CueRCode" organisation; QRmy.Link added, TXT record set, verified and made default; QRmy.Link pointed at the same web folder as g2my.link on DreamHost; a real certificate; Cloudflare in front; and the organisation's plan allowing enough links and API calls (`maxLinks`, `maxCustomDomains`, `maxAPIRequestsPerDay` or a per-key override).

### 0.5 Abuse handling that exists in Go2My.Link
Exists (verified): Terms of Use and an Acceptable Use Policy forbidding phishing and malware; suspension of a whole user account; switching one link off; a contact page; a destination check at create and edit (#205, feature branch only); an optional live "is the destination reachable" check (off by default).
Missing (searched, not found): an abuse-report page, automatic suspension after reports, any link-safety (reputation) service, a "suspended for abuse" link state with its own wording, rate limiting on the redirect server, a daily anomaly report.

### 0.6 What is missing, and whose side it belongs to
**Go2My.Link side** (to be done in a Go2My.Link session; a CueRCode session must not change that repository):
- G1. A live production install of the new Go2My.Link containing #145 and #205 (its own queue, D-001/D-002). Blocking.
- G2. On that install: `cuercode.integration_enabled = 1`; `cuercode.allow_external_shortcode = 1` (needed for safe retries, lead choice L1 below). Blocking.
- G3. The CueRCode organisation, QRmy.Link verified as its default short domain, and limits big enough for every CueRCode user's links. Blocking. (Owner can do this on the dashboard once G1 exists.)
- G4. An API key for that organisation with scopes `qr:link`, `urls:read`, `urls:write`, `urls:delete`, `analytics:read`. Blocking. (Owner, on the dashboard.)
- G5. Hosting: QRmy.Link served by Go2My.Link's redirect folder, a valid certificate, Cloudflare in front with a rate-limiting rule (the redirect code has none). Blocking for public use; the certificate and hosting are blocking for any test.
- G6. Recommended small changes: send `Cache-Control: no-store` and `X-Robots-Tag: noindex` on redirects (a cached redirect could keep sending people to an old destination); optionally let the 409 reply include the existing short code. Not blocking.
- G7. Go2My.Link's privacy page must cover what it records when a QRmy.Link code is scanned. Blocking for public use (legal review).

**CueRCode side** (this repository): the Go2My.Link connection code, the create/edit/switch-off flows, the web screens, link-safety check, abuse report and takedown, daily monitoring, scan totals, documents. Work packages WP4a to WP4j below.

### 0.7 What happens to CueRCode's existing groundwork (#82, stages S0/S1)
Checked today: `php tests/run.php` 7522 of 7522 pass on PHP 8.5.12 at `1201cff` (the dynamic tests are `tests/test_dynamic_redirect_s0.php` and `_s1.php`; run them in the full suite, because they include guards that fail when run alone with `--filter`).
| Item | Verdict | Why |
|---|---|---|
| `validateRedirectDestination()` (`_functions/fn_redirects.php`) | **Stays** | Still checked by CueRCode before any call to Go2My.Link (https only, no private addresses, no user:password, no self-links, non-English hosts converted or refused). Add Go2My.Link's own domains (go2my.link, g2my.link, lnks.page) to the self-link list so users get a clear message. |
| `redirectIsWritableBy()`, `evaluateDynamicCodeQuota()`, the per-user lock (`acquireDynamicCodeLimitLock()`) | **Stay** | Ownership and the per-plan limit are CueRCode's job; Go2My.Link only knows one organisation. |
| `createDynamicRedirectForOwner()` (`_functions/fn_api_redirects.php`) | **Changes** | Must also create the Go2My.Link link and store its short code; see WP4c. |
| `GET/PATCH /api/v1/qrcodes/{uuid}/destination` | **Changes** | Already routed. PATCH must call Go2My.Link's `PUT` first and only then save locally; a suspended code keeps refusing edits (409 `REDIRECT_SUSPENDED`). |
| `tblQRRedirects` | **Stays, gains columns** | Remains CueRCode's record of each dynamic code (owner, destination copy, state). Add the Go2My.Link short code, a sync state and suspension fields (WP4a). `redirectUUID` becomes the `qr_external_uuid` sent to Go2My.Link; `redirectID` becomes `qr_external_id`. |
| `buildPayload_dynamic_url()` (`fn_qr_payloads.php`) | **Changes** | Today it prints `{base}/r/{uuid}`. It must print `{base}/{shortCode}` (for example `https://QRmy.Link/Ab3dE9x`). Setting `dynamic_redirect_base_url` stays. |
| `dynamic_qr_enabled`, `redirect_allow_http`, the `dynamic_url` type (switched off), plan keys `dynamic_qr`, `dynamic_codes` | **Stay** | Switches and limits are still CueRCode's. `dynamic_qr_enabled` is currently locked in `/admin/settings/`; it is unlocked in WP4c with conditions. |
| The planned public `/r/{uuid}` route, its own rate limiting and scan recording (design 1.3, 2.1) | **Goes** (never built) | Go2My.Link answers the scans. Rate limiting moves to Cloudflare in front of QRmy.Link. |
| `hitCount`, `tblQRScans` | **Not used** at first launch | Scans are recorded by Go2My.Link. Keep the table untouched (lead choice L4) and decide with #33. |
| `tblResolveNonces`, `isDualFunction`, `appID`, `resolvePayloadJSON` | **Not used** at first launch | Only for dual-function QR (#71), see Q6. |

---

## 1. What the first version includes and excludes

**Includes:** the free generator (all current QR and barcode types); accounts (sign-up, email verification, sign-in, password reset, saved codes, API keys); the admin screens built so far (7n Phase 1 and 2: settings, plans, prices, public pricing page, with charging switched off); the human check on public forms (#163, decision 0024); **dynamic QR codes on QRmy.Link through Go2My.Link**, with the section 2.6 abuse protections (WP4); the deploy test gate (#155, decision 0025).

**Excludes (decision 0023):** taking money (PayPal, #26); 7n Phases 3-7 (feature editor, discounts, lifetime deals, pay-as-you-go, housekeeping); passkeys, translations, image fills, JPEG XL (7r); geo or device rules (O-9, deferred); expiry and scheduling of dynamic codes (columns exist, not used).

**Undecided, asked below:** dual-function QR (#71) — Q6, recommended **out**; scan analytics (#33) — Q7, recommended **totals only**.

"Launched" means: the code is on `main`, deployed to cuercode.net, the owner's live checks have passed, and the owner has signed off that every section 2.6 item (see "Must-list table" under WP4) is in place.

---

## 2. Work packages, in order

Tiers (decision 0001): gather facts = Sonnet or Haiku, medium effort; plan = Opus, high effort; build = Sonnet or Haiku, medium effort, **except security and money, which Opus builds**; **every check or review = Opus**, then Codex (project rule; if Codex is out of allowance, follow `.claude/CLAUDE.md` "Code review loop"). One agent at a time in this folder. After each reviewed package: push `work-in-progress` (decision 0008), comment on its issue, update `.claude/HANDOFF.md`.
Every package also obeys: PHP full notation and comments, file headers, MySQLi prepared statements, **every SQL identifier in backticks**, `php tools/php86-compat-scan.php`, `php tools/check_site_polish.php` 0 problems after page changes, docs updated in the same change, OpenAPI updated for any API change.

### WP0 — Lead actions now (no code)
- Put questions Q1-Q9 to the owner in one batch (section 4). Record each answer in `docs/decisions/` and on its issue straight away.
- Post the Go2My.Link-side list (0.6, G1-G7) as a comment on MWBMPartners/Go2My.Link#276, answering its five points with this plan's recommendations. Comment only; no Go2My.Link code, branches or settings are touched from a CueRCode session.
- Create CueRCode issues for WP4b-WP4j (under #27) and for each owner question, in the same action, and add them to milestone 10.
- Tier: lead (the session running the work).

### WP1 — Finish 7n Phase 2 review and fixes (#31, #159, #160, #161)
- Review range `c487dab..07a6f55`. The running report `.claude/plans/7n-phase2-review-report.md` has findings F1-F4 and all six checklist sections still unticked. Finish all six sections, including the two upgrade-tool/installer rule changes from slice 2b.
- Fix: F1 (installer replay never retried; Medium), F2 (validator accepts aggregate steps), F3 (protected plans hidden through the sale dates), F4 (literal-price guard misses HTML and whole pence); #159 per-plan price promise (decision 0020); #160 "Price coming soon", no sign-up button (0021); #161 coming-soon marks without overwriting admin edits (0022).
- Build: **Opus** (money and install data safety). Check: Opus re-review of the fixes, then Codex.
- Done when: every finding fixed with a test shown failing first; full suite green; polish check 0; Codex clean; pushed; #31 commented; #159-#161 closed with commit links and the done label.
- Depends on: nothing. Blocks: WP6.

### WP2 — Test gate before deploy (#155, decision 0025)
- Built in `b7ce022`, unreviewed. Check: Opus, then Codex. It can only be proven on GitHub when `alpha` next receives a push (CI does not run on `work-in-progress`); set a watchdog then.
- Done when: reviewed; the first deploy run on `alpha` shows the test job finishing before the upload job, and a deliberately failing test (on a throwaway branch with the owner's agreement, or by reading the run graph) is shown to block upload.
- Depends on: nothing. Proven at WP6.

### WP3 — Human check with three providers (#163, decision 0024)
- Server-side check plus the widget on registration, sign-in, forgot password, resend verification, and the abuse-report form (WP4f). Provider order Turnstile, then reCAPTCHA, then hCaptcha, by which keys are entered. Secrets encrypted, entered on `/admin/settings/`. Off until keys exist. Update the content security policy in `public_html/.htaccess` for each provider's script and frame addresses (and the `.claude/CLAUDE.md` libraries line). State what happens with JavaScript off. Privacy page names the provider in use.
- Prior art (read only, do not copy across repositories without review): Go2My.Link's `verifyCaptcha()` in `web/Go2My.Link/_functions/shorturl_create.php` (Turnstile and reCAPTCHA, PHP streams, no cURL).
- Build: **Opus** (security). Check: Opus, then Codex; real-browser pass recorded.
- Done when: the criteria on #163 are met (tests per provider: success, failure, provider unreachable, keys absent).
- Depends on: nothing. Blocks: WP4f (report form), WP6.

### WP4 — Dynamic QR on Go2My.Link (#27; abuse items from design section 2.6)
Build against Go2My.Link's documented contract (its `public_html/api/openapi.yaml` and the handlers named in 0.1) with a stand-in server in CueRCode's tests. A run against a real Go2My.Link copy is optional and needs the owner's agreement (use the workstation runtime's isolated copy; never run inside `../Go2My.Link`).

Lead choices made by this plan (record as one lead decision file when WP4 starts; the owner may overrule):
- **L1** CueRCode chooses each short code (7 random letters and digits, checked against Go2My.Link's pattern and reserved list) and sends it as `custom_code`. Reason: verified in 0.1 that a timed-out create cannot otherwise be found again. Needs G2.
- **L2** A takedown points the Go2My.Link link at a CueRCode page "This code has been suspended" (with a report link and the takedown promise), rather than switching the link off in Go2My.Link. Reason: Go2My.Link's switched-off page says "disabled by its owner", and its API cannot switch a link back on (verified). This needs no Go2My.Link change and can be undone.
- **L3** Do not print `?src=qr` in the code. Every link in the CueRCode organisation is a QR link, so per-link counts work without it, and the code stays shorter and easier to scan.
- **L4** Leave `tblQRScans` and `tblResolveNonces` untouched and unused; decide with #33 and #71.
- **L5** CueRCode's `tblQRRedirects` holds the owner and a copy of the destination; Go2My.Link holds the live link. A daily check compares the two and reports differences.

**WP4a (#164) — Database upgrade `0007`** (`0005` was taken by the human check, #163, and `0006` by three-decimal money, #176) (new numbered migration plus `install.sql`, same pattern as `0003`/`0004`). Add to `tblQRRedirects`: Go2My.Link short code (unique), sync state (pending, active, failed), last sync error, suspended-at, suspended-by, suspension note. New table for abuse reports (code, category, details, reporter email encrypted with the existing AES-256-GCM helper, a hashed reporter address for counting different people, status, handled by, timestamps). New settings: Go2My.Link API address, API key (sensitive, encrypted), timeout; link-safety provider and key (sensitive); auto-suspend count (default 3) and window (default 24 hours) per O-4; takedown-promise wording; abuse mailbox address. Build: Sonnet; check: Opus (schema and migration safety rules from the Codex rounds apply). Done when: fresh install, upgrade and re-run all work on MySQL 8.0.13+ and MariaDB 10.6+ (decision 0012) with tests.

**WP4b (#165) — Go2My.Link connection code** (new `_functions/fn_go2mylink.php`): create, change destination, look up one link, read scan totals; key sent as `Authorization: Bearer`; TLS checked; redirects not followed; short timeouts; every Go2My.Link error mapped to a plain CueRCode error; the key never logged. Admin "Test connection" button on `/admin/settings/` (like the email test). Build: **Opus** (outbound calls and a secret). Check: Opus, Codex. Done when: tests with a stand-in server cover success, 403, 409, 422, 429, timeout, bad certificate, unexpected reply shape.

**WP4c (#166) — Create, edit and switch-off flows.** Wire `createDynamicRedirectForOwner()` into `handleGenerate()` for the `dynamic_url` type: owner signed in with a confirmed email (no anonymous dynamic codes), feature `dynamic_qr` and limit `dynamic_codes` under the existing lock, destination checked (WP4e), row saved as pending, Go2My.Link create called, row marked active, QR drawn from `buildPayload_dynamic_url()` (changed to `{base}/{shortCode}`). PATCH destination calls Go2My.Link first. Owner switch-off and switch-on use the L2 mechanism with reason "owner". Unlock `dynamic_qr_enabled` in the admin screen, allowed only when the connection test passes. Go2My.Link unreachable: creation and edits refuse with "try again later", nothing half-made is counted for ever (a tidy step retries or removes stale pending rows); scans are unaffected because Go2My.Link serves them. OpenAPI updated. Build: **Opus** (ownership, limits, the lock). Check: Opus, Codex. Done when: tests cover the paths above, other people's codes answer 404 (not 403), and the #27 acceptance points are met (destination changeable without regenerating; graceful when Go2My.Link is down; scan totals per Q7).

**WP4d (#167) — Web screens.** Generator: a "Dynamic (editable) link" option for signed-in users. A "My dynamic codes" page in the account area: list, change destination, switch off or on, scan total (Q7), suspended state shown plainly. Works without JavaScript; WCAG 2.1 AA; light and dark mode. Build: Sonnet; check: Opus; real-browser pass at the six sizes (#137 rule).

**WP4e (#168) — Link-safety check** (new `_functions/fn_url_reputation.php`, a list of providers so more can be added). Provider per Q3 (recommended: Google Web Risk only). Checked at create and every destination change: "unsafe" refuses with 422; provider unreachable lets it through and logs it (design 2.3, "fail-open"). A daily re-check of every active destination through a new cron script beside `_includes/cron/process_email_queue.php`; an "unsafe" result suspends the code (L2) and tells the owner and the abuse mailbox. Build: **Opus**. Check: Opus, Codex. Done when: tests cover safe, unsafe, unreachable, no key, quota exceeded.

**WP4f (#169) — Abuse report and takedown.** Public `/report/` page (linked from the suspended page, the site footer and the terms), with the human check (WP3), plain categories, optional email, rate limited per visitor. Reports stored (WP4a), emailed to the abuse mailbox and the code's owner through the email queue. **3 reports from different people within 24 hours suspend the code automatically** (O-4; "different people" counted by hashed visitor address, the design's method). Admin screen `/admin/abuse/`: list reports, suspend, restore with a written reason, every action in the admin audit log; an owner cannot edit or switch on a code suspended for abuse. Suspended page on cuercode.net: neutral wording, never shows the destination, shows the takedown promise (Q9) and the report link. Build: **Opus**. Check: Opus, Codex, real-browser pass.

**WP4g (#170) — Daily monitoring email.** Cron script: new and changed destinations in the last 24 hours, codes with the most scans (from Go2My.Link totals), link-safety hits, new reports, differences between CueRCode and Go2My.Link (L5). Sent to the Global Admin and the abuse mailbox. Build: Sonnet; check: Opus.

**WP4h — Scan totals (#33, only if Q7 answer is A).** Show each code's total scans, read from Go2My.Link's `click_count` and cached for a short time so pages do not call Go2My.Link on every view. Build: Sonnet; check: Opus.

**WP4i (#171) — Documents and wording.** Terms (redirect misuse, takedown, the promise from Q9), privacy (Go2My.Link records scans; Google receives destination addresses if Web Risk is used; Cloudflare, see the open owner item), in-app help, developer portal, OpenAPI, `docs/INSTALL.md` (Go2My.Link key, cron jobs), `DEV_NOTES.md`, `CHANGELOG.md`, `README.md`, `PROJECT_STATUS.md`, `.OpenAI/` pointers. Pricing page: `dynamic_qr` status moves from "coming soon" to available only once the feature is switched on (decisions 0022, #125). Mark the July design file with a terminal line ("redirect route superseded by the Go2My.Link approach, see this plan") and move it to a history folder. Build: Sonnet or Haiku; check: Opus. Legal wording goes to the owner's legal review (#38).

**WP4j (#172) — Whole-feature review.** Opus review of all of WP4 together against design section 2.6 as translated in the "Must-list table" below, then Codex until clean.

Depends on: WP4a first; WP4b before WP4c; WP3 before WP4f; Q1, Q3, Q5, Q7 answers before the parts they name. **Real end-to-end use needs G1-G5 (Go2My.Link side and owner).** Everything in WP4 can be built and tested before then.

### Must-list table: design section 2.6, translated to the Go2My.Link approach
| Item (design 2.6) | Under Go2My.Link | Who |
|---|---|---|
| (a) abuse-report page, takedown runbook, published promise | CueRCode `/report/`, `/admin/abuse/`, suspended page (WP4f); runbook in `DEV_NOTES.md` and `docs/INSTALL.md`; promise from Q9 in terms | CueRCode; owner sets the promise |
| (b) automatic suspension | CueRCode, 3 different people in 24 h (WP4f); also on a link-safety hit (WP4e) | CueRCode |
| (c) rate limits burst-tested live | Scans: Cloudflare rule on QRmy.Link (the redirect code has none, verified). Creates and edits: CueRCode's existing per-plan API limits plus Go2My.Link's key limits. Burst test on the live host as in #18 | Owner (Cloudflare), CueRCode (test script), Go2My.Link (G5) |
| (d) terms covering misuse and takedown | CueRCode terms (WP4i) and legal review (#38); Go2My.Link's own terms apply to the CueRCode organisation | Owner and counsel |
| (e) link-safety provider decided | Web Risk (Q3), WP4e | Owner (Q3, Google account), CueRCode |
| (f) daily anomaly report | WP4g | CueRCode |
| (g) owner sign-off | O-1 covers the alpha test group; a fresh sign-off before public use, with evidence for (a)-(f) and (h) | Owner |
| (h) Cloudflare in front | QRmy.Link DNS moved to Cloudflare (measured: not yet) | Owner (G5) |
| plus | QRmy.Link has a valid certificate (measured: self-signed today); `abuse@` mailbox exists | Owner |

### WP5 — Remaining first-install and public-use items
From `.claude/plans/new-install-readiness-queue.md` sections C and D and the open issues.
| Item | Issue | Build / check | Needed before |
|---|---|---|---|
| Admin pages still allow inline scripts | #154 | Sonnet / Opus | Public use |
| Activity log keeps email addresses for ever | #129 | Sonnet / Opus (privacy) | Public use |
| Time-zone faults in expiry checks (matter now: the 24-hour report window and suspension times compare dates) | #158, #130 | Sonnet / Opus | Public use |
| Codex reviews still owed (1A, 1B, 1C, chunk 3, the round-4 fix re-check, Phase 2, #155, all of WP3-WP5) | queue F2 | Codex (Opus stand-in recorded as such) | Alpha pull request |
| HTTPS redirect and HSTS switched on at go-live (`docs/INSTALL.md` step 8) | — | owner switch | Public use |
| Dev alpha/beta password lock (DS-1): the block in `public_html_dev_alpha/.htaccess` is commented out and the sites deny everyone; to let a test group in, the owner makes a password file | #47 | owner | Alpha test group |
| Close issues only after proof (live run or review): #112 #117 #124 #125 #127 #128 #138 #113 #65 #145 #139 | B11 | lead | as proven |
| Live checks on DreamHost: install #13, all types and formats #17, API #18, devices and screen readers #22, plus dynamic QR: print, scan, change destination, scan again, report three times, see suspension, restore | #13 #17 #18 #22 | owner, with a lead-written script | Public use |
| Real-browser pass on every new or changed page (#137 rule) | #137 | lead with a browser tool, Opus check | Alpha pull request |
| Legal review including the 9 placeholders and the new dynamic-QR wording | #38 | owner and counsel | Public use |
Can follow launch (not needed for it): #157 shorthand rewrite, #156 hardening leftovers, #119, #120, #118, #121 (owner's GitHub App), #64 settings, #137 optional follow-ups, #114 (bot protection part done by WP3), #115, #66-#69, #40, #34, #16, #35, #37 beyond #38, #33 beyond totals, #71 (if Q6 = out).

### WP6 — The one `work-in-progress` to `alpha` pull request (#162, decision 0026)
- Before asking the owner: WP1-WP5 reviews clean (Opus, then Codex); full tests green on PHP 8.4 and 8.5; polish check 0 and real-browser pass recorded; workflow-file conflict check (`security-propagate.yml`, codeql/semgrep/ci across branches, `release-candidate` in the triggers, #110).
- Then **ask the owner**; open only after a fresh yes. After merge: watchdog on every check and the deploy run (WP2 proof); the source branch is auto-deleted, so recreate `work-in-progress` from `alpha`.
- Dynamic QR stays switched off on alpha until G1-G5 exist, then is switched on for the test group only (O-1).
- After alpha: the test group period; owner sign-off on the must-list table; promotions to `beta`, `release-candidate`, `main`, each with the owner's approval.
- Tier: lead; checks Opus.

---

## 3. Owner-only steps (nobody else can do these)
1. **Deploy:** add secrets `SFTP_KNOWN_HOSTS` (organisation) and `SFTP_REMOTE_BASE` (repository); set variable `SFTP_ENABLED`; re-run the failed secret-scan check on Dependabot PR #152.
2. **DreamHost for CueRCode:** create `_auth_keys/auth_creds.php` from the committed template; confirm PHP extensions `zip`, `bcmath`, `curl` and `intl` (without `intl`, web addresses with non-English letters are refused as destinations); apply database upgrades `0003`, `0004` and `0005` to `0007` straight after the first deploy; add the cron jobs (email queue, and the two new WP4 jobs); enter the Microsoft mail details on `/admin/settings/`; ask DreamHost about libbrotli (#150); after deploy read "Visitor address check" on `/admin/`.
3. **Human check keys:** create Turnstile, reCAPTCHA and/or hCaptcha keys and enter them on `/admin/settings/`.
4. **Mailbox:** create the `abuse@` mailbox (where, see Q8) before dynamic QR is used by anyone outside the owner.
5. **QRmy.Link:** move its DNS to Cloudflare; give it a valid certificate; point it at Go2My.Link's redirect folder on DreamHost; add a Cloudflare rate-limiting rule; (if Q4 = A) the same for `alpha.QRmy.Link`.
6. **Go2My.Link:** decide its priority (Q1, Q2); once its install is live, switch on the two CueRCode settings (G2), create the CueRCode organisation and add and verify QRmy.Link (G3), create the API key (G4) and enter it on CueRCode's `/admin/settings/`.
7. **Google Cloud (if Q3 = A):** a project with billing, the Web Risk API switched on, an API key restricted to Web Risk, entered on CueRCode's `/admin/settings/`. (Billing is reserved to the owner.)
8. **Alpha test group:** create the password file for the alpha site (DS-1) and give testers access.
9. **Legal:** review of terms and privacy with counsel (#38), including the 9 placeholders, the takedown promise, and naming Go2My.Link, Google and (if chosen) Cloudflare.
10. **Approvals:** the alpha pull request (#162); each later promotion; switching on HTTPS redirect and HSTS; the live checks (#13, #17, #18, #22, dynamic QR); the final public go-live sign-off against the must-list table.
11. **Already pending elsewhere (not repeated as questions here):** #136 instruction-audit decisions; whether the privacy page names Cloudflare; GitHub App for #121; deleting the old backup branch and stale Dependabot branches; decision 0009 and 0017/0018 lead choices (the owner may overrule).

---

## 4. Owner answers (2026-11-27)
The nine questions this plan raised are answered; the records are the truth, and where the text above says "per Q…" read the record.

| Q | Answer | Record |
|---|---|---|
| Q1 | QRmy.Link links live in the main Go2My.Link | 0027 |
| Q2 | If Go2My.Link is late, the free service launches first; dynamic QR follows | 0028 |
| Q3 | Web Risk at launch, with a slot for a second paid provider added later | 0029, 0035 |
| Q4 | Alpha codes use `alpha.QRmy.Link` | 0030 |
| Q5 | 3 codes each; a Global Admin can raise it per person or for everyone. **New work:** per-person limit in WP4a/4c and an admin control | 0031 |
| Q6 | Dual-function QR is not in the first version | 0032 |
| Q7 | Total scans only (WP4h is in) | 0033 |
| Q8, Q9 | `abuse@cuercode.net` (QRmy.Link forwards), one working day | 0034 |

Because of 0028, CueRCode's own work is ordered so the free service can reach launch without waiting for WP4's live use: WP1, WP2, WP3, WP5, WP6 do not depend on Go2My.Link.

---

## 5. Could not verify
- Whether anything from the new Go2My.Link was ever uploaded by hand, or whether the 2026-07-19 manual upload was a dry run (logs expired). Which host name serves Go2My.Link's `public_html_dev_alpha` folder.
- Whether DreamHost can serve QRmy.Link from the same folder as g2my.link (owner's hosting panel).
- Go2My.Link's own privacy wording and how long it keeps scan details (read in its handoff only).
- Anything on the live DreamHost servers, a real browser, or GitHub runs of the new deploy gate.
- Go2My.Link's tests were not run (the brief forbids building there); its test counts above come from its issues.

---
Status: live plan. When its work lands, give it one terminal line and move it to `.claude/plans/history/`.
