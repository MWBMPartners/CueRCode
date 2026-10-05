# Plan for task 7n — Global-Admin-only admin & commerce screens

Tracking issues: **#31** (admin dashboard), **#53** stage 7 (admin plan editor), **#70** (flexible pricing data), **#113** item 5 (re-runnable install + upgrade mechanism), **#125** (pricing page honesty), **#131 / task 7q** (SSO for every plan). The lead should open one umbrella issue for 7n and link these.

Planner: Opus (deep planning), 2026-10-05, written against `work-in-progress` at **`80018f8`**. Paths are relative to `web/CueRCode.net/` unless they start with `tests/`, `.claude/` or a repo-root file name.

---

## 0. Summary for the owner (plain English)

**What we are building.** A private control panel at `/admin/` that only a **Global Admin** can open. It lets you:
1. **Type in the secret keys** for email (Microsoft 365), "Sign in with Microsoft/Google", bot protection and PayPal. Secrets are locked away (encrypted) the moment you save them, and the screen **never shows them back** — it only says "Set" or "Not set". This comes **first**, because without the email keys nobody can finish signing up.
2. Change the **email-record clean-up periods** (30 / 90 days now), the **VAT wording** on prices (prices include VAT now), and the **free-trial switch** (off now).
3. Manage **plans and prices** in **£, $ and €**, monthly and yearly, starting from your draft prices.
4. Decide **which plan gets which feature** and each plan's limits, including the accessibility split.
5. Run **discount codes**: general, limited-use, one-customer-only, start/end dates, the launch offer (25% for 12 months, until 31 Dec 2026) and the 50% education/non-profit discount you approve by hand.
6. Run **lifetime deals** (Founders Lifetime first: £119.99, 500 places, 60 days) with their own limits and time/quantity caps.
7. Set up **pay-as-you-go credit packs** (£4.99 / £19.99 / £59.99) and a **monthly spending cap** (£20).
8. Set the **price promise** for existing customers ("while subscribed" or a set number of months).

**What we are NOT doing.** Taking money. **Charging stays switched off** until dynamic QR is live; the screens look after the *information*, they never switch payments on. The PayPal checkout itself is a later task.

**The golden rule we are following** (your rule, 2026-10-05): *nothing commercial is written into the program*. Every price, plan, limit, discount, deal and promise is stored as information you can change in these screens. Today's answers are only the starting values.

**How customers stay protected.** Once somebody has bought something, the exact price and discount they bought are **frozen**. If you later change a plan in a way that takes something away from people already paying, the screen stops you, explains who is affected, and offers to make a **new version** for new customers instead (question Q5).

**Safety.** Getting into the panel needs: a signed-in account marked as Global Admin, with a confirmed email address. **Making any change** also needs you to re-type your password if you have not done so in the last 15 minutes. Every change is written to a permanent **change log** (who, when, what it was, what it became) — secret values are never written there, only "secret replaced".

**Order of work.** 1A database-upgrade tool → 1B Global Admin access → 1C settings screen (email unblocked here) → 2 plans & prices → 3 feature placement → 4 discounts → 5 lifetime deals → 6 pay-as-you-go → 7 admin housekeeping. Each step is small enough for one builder, and each is checked before the next starts.

**Six questions for you** are in §11, each with a recommended answer.

### Glossary (plain English)
- **Global Admin**: the top-level staff role. In the database it is `tblUsers.roleID = 1`.
- **Re-authentication ("confirm your password")**: typing your password again before making changes, even though you are already signed in.
- **Secret / encrypted setting**: a setting row with `isSensitive = 1`. It is scrambled with AES-256-GCM before it is stored, so a copy of the database alone does not reveal it.
- **Migration / database upgrade**: a small, numbered file that adds a new table, column or starting row to an existing database. Each one is safe to run twice.
- **Price point**: one price for one plan in one currency for one billing period (e.g. "Pro, yearly, £49.99").
- **Plan JSON**: each plan's list of switched-on features (`featuresJSON`) and its number limits (`limitsJSON`, where −1 means unlimited).
- **Kill switch**: an on/off setting that keeps a whole subsystem inert. The three `billing_*_enabled` switches stay **off**.
- **Minor units**: money counted in pence/cents as a whole number (699 instead of 6.99), so sums never go wrong through rounding.
- **Audit log**: the permanent change log described above (`tblAdminAuditLog`, new).

---

## 1. Read this first (builders)

### 1.1 Preconditions — check before editing anything
1. `git -C "$REPO" status --short` — another session commits website fixes to `work-in-progress`. **Never revert or tidy anything you did not change.** Exclude `.claude/worktrees/` from every search (`grep --exclude-dir=worktrees`, or search only `web tests tools`).
2. `git -C "$REPO" log --oneline -1` — expect `80018f8` or later. If `install.sql`, `seed_data.sql`, `fn_features.php`, `fn_pricing.php`, `fn_config.php` or `fn_auth.php` changed since, re-read the changed parts before relying on any line number below.
3. Baseline: clone the 5 libraries the way `ci.yml` does into `_libraries/` (gitignored), then `php "$REPO/tests/run.php" > <scratchpad>/baseline.txt 2>&1; echo "exit=$?"`. Expect exit 0. Read the exit code directly; never pipe into `grep`/`tail` and trust `&&`.

### 1.2 House rules (from `.claude/CLAUDE.md` and `.claude/plans/112-email-queue-runner.md` §0.2 — still binding)
- **Full multi-line PHP.** No `??`, `?:`, ternaries, arrow functions (`fn`), first-class callables (`foo(...)`) or `match`. Write `if`/`else`.
- **PHP 8.4 compatible** (target 8.5). `#[\SensitiveParameter]` (PHP 8.2+) is allowed and **required** where §10 says so.
- **Every query is a MySQLi prepared statement** with `?` placeholders (`dbQuery()` in app code; the migration runner uses a passed-in `mysqli` and `prepare()`/`bind_param()`). The only SQL text ever joined together is a **static string literal from a migration file** (never request input).
- `DIRECTORY_SEPARATOR`, `PHP_EOL`; file headers per `fn_email.php` lines 1-53 (`Path:`, `@copyright 2025–2026 MWBM Partners Ltd`, `@since`, `@see`); heavy "why" comments; emojis welcome in comments.
- Procedural functions, no classes. Pure decision functions separated from database functions so they can be unit-tested (house pattern: `evaluateCreditSpend()`, `parseEmailQueueRetentionDays()`).
- **Never print or log** secret values, setting values marked sensitive, passwords, full email addresses in error-log fallbacks (`fn_log_mask.php`), or tokens.
- Tests: `tests/test_*.php` must not define `dbQuery`, `getDB`, `getConfig`, `logError`, `logActivity` in the main test process (other test files assert they do not exist). Real-database checks go in `tests/integration/` and refuse to run unless the database name starts with `cuercode_it_` (copy the guard from `tests/integration/email_queue_real_mysql.php`).
- **Do not commit or push** — the lead does that after verification and the Codex review.
- **Never write the literal text `` ALTER TABLE ` `` or `CREATE TABLE IF NOT EXISTS` anywhere new in `install.sql`, including comments** — `tests/test_dynamic_redirect_s0.php:318-332` counts them. Phase 1A deliberately changes those counts and updates that test (see §9).

---

## 2. Requirement → what exists today → what is missing

Evidence was read at `80018f8`. "Gap" = must be built.

### R1. Settings editor (secrets, email-record periods, VAT switch, trial switch)
| Need | Exists today | Gap |
| --- | --- | --- |
| Store any setting, encrypted when sensitive | `tblSettings` (`install.sql:43-71`): `settingGroup`, `settingType` ENUM, `isSensitive`, `isEditable`, `description`, `encryptionSalt`. `setConfig()` (`fn_config.php:229-391`) encrypts when the **existing row** has `isSensitive=1` (`:288-296`, `:307-336`) and clears the request cache (`:388`). `getConfig()` decrypts (`:142-169`). AES-256-GCM + HKDF per record (`fn_encryption.php:120`, `:165`). | **No screen and no caller of `setConfig()` for owners** (audit 2026-09-23 finding 2). |
| Respect "not editable" rows | `isEditable` column; seeded 0 for `app_version`, `app_phase`, `api_version`, `encryption_algorithm`, `graph_token_cache` | `setConfig()`'s UPDATE path (`fn_config.php:347-353`) **does not check `isEditable`** → the admin layer must refuse those keys itself. |
| Correct typing on save | `convertValueForStorage()` (`fn_config.php:681-721`) | `setConfig()` defaults `type` to `'string'` when not passed (`:237-241`) and **ignores the row's own `settingType`** → `false` would be stored as `''`. Admin save must pass the row's `settingType`. |
| Email secrets | `graph_tenant_id`, `graph_client_id`, `graph_client_secret` (sensitive), `graph_sender_mailbox`, `email_from_name`, `email_enabled`, `email_queue_autoprocess` (`seed_data.sql:117-125`) | Screen. Also: when Graph credentials change, the cached access token (`graph_token_cache`, written by `getGraphAccessToken()`, `fn_email.php:574`) must be cleared or the old token keeps being used for up to an hour. |
| Email-record periods 30/90, range 1-3650 (#122) | `email_queue_retention_days`, `email_queue_failed_retention_days` (`seed_data.sql:126-127`); validator `parseEmailQueueRetentionDays()` (`fn_email.php:1832`), max `CUERCODE_EMAIL_QUEUE_RETENTION_DAYS_MAX` = 3650 (`:1163`) | Screen. **Installs from before #122 do not have these two rows** (seed comment `:110-115`) → migration inserts them if missing. |
| SSO secrets | `sso_*` rows (`seed_data.sql:193-211`), callback URL builder `ssoRedirectUri()` (`fn_oauth.php:504`), caches `sso_{provider}_discovery_cache` / `_jwks_cache` (`fn_oauth.php:688`, `:752`) | Screen; clear the two caches when a discovery URL changes; show the exact callback URL to paste into Microsoft/Google. |
| Bot-protection keys | `turnstile_*`, `recaptcha_*` (sensitive), `bot_protection_provider` (`seed_data.sql:74-78`) | **No hCaptcha rows** (the house rule ranks Turnstile > reCAPTCHA > hCaptcha). **Nothing reads any of these keys yet** (grep: no PHP outside the seed) — wiring is #114. |
| PayPal secrets | none | **No rows at all** → add `paypal_mode`, `paypal_client_id`, `paypal_client_secret`, `paypal_webhook_id`. Nothing reads them until #26. |
| VAT display switch | none (`Pricing_Strategy.md` §7 #3 only) | New settings + a small pricing-page change. |
| Trial switch/settings | `tblSubscriptions.status` ENUM already includes `trialing` (`install.sql:835-841`), honoured by `resolveActiveSubscription()` (`fn_features.php:297-342`) | New settings. **No trial start or expiry code** — nothing ends a `trialing` row (later task). |

### R2. Plan & price editor
| Need | Exists today | Gap |
| --- | --- | --- |
| Create / edit / retire plans | `tblSubscriptionPlans` (`install.sql:787-807`): `planKey` UNIQUE, `planName`, `description`, legacy `priceMonthly`/`priceYearly`/`currency`, `featuresJSON`, `limitsJSON`, `isActive` (0 = retired, existing subscribers still resolve because `planByKey()` does not filter on it, `fn_features.php:215-222`), `sortOrder` | Screen. No "kind" (standard / lifetime deal / credit packs), no "contact us" mode, no sale window, no sales cap, no price-promise settings → new columns. |
| Prices in £/$/€, monthly/yearly | `tblPlanPricePoints` (`install.sql:989-1022`): per-row `currency`, `billingInterval` ENUM(monthly, yearly, lifetime, one_off, metered), `intervalCount`, `amount` DECIMAL(10,2), `validFrom`/`validUntil`, `isDefault`, `isActive`; UNIQUE (`planID`,`priceKey`). Read by `pricePointsForPlan()` (`fn_features.php:1057`) and `listPublicPlansForDisplay()` (`fn_pricing.php:169-212`), both only when `billing_price_points_enabled=1` | Screen. No way to group the £/$/€ rows of the same offer → new `priceGroupKey` column. No list of offered currencies → new settings. **Zero rows seeded.** |
| Start values (Pro £6.99/£49.99, Business £24.99/£199.99, Enterprise contact from ~£99) | Seeded legacy prices are placeholders: Pro 4.99/49.99, Business 19.99/199.99 (`seed_data.sql:670`, `:677`). **No Enterprise plan.** | Seed price points; Enterprise plan with "Contact us"; update the legacy columns only if still at the old placeholder values. |
| Existing subscribers keep what they bought | `tblSubscriptions.pricePointID` / `discountID` snapshot columns (`install.sql:1274-1286`), `tblPayments.pricePointID` (`:1296-1302`) | Rules that **freeze** a price point once anything references it (§4.3). Note: those two ALTER blocks are the #113 "install.sql cannot be run twice" bug — Phase 1A moves them into migration 0001. |

### R3. Discount manager
| Need | Exists today | Gap |
| --- | --- | --- |
| General / limited-use codes, start/end dates | `tblDiscounts` (`install.sql:1053-1082`): `code` UNIQUE (NULL = automatic), `discountType` percent/fixed, `value`, `appliesPlanID`, `appliesPriceKey`, `durationType` once/repeating/forever, `durationMonths`, `maxRedemptions`, `redemptionCount`, `validFrom`/`validUntil`, `isActive`. Redemptions `tblDiscountRedemptions` (`:1168-1199`) UNIQUE per (discount,user) and (discount,org). Resolver `resolveDiscountForCheckout()` (`fn_features.php:1180-1292`) — **no production caller** (grep: tests only) | Screen. |
| Codes for one specific customer | none | New `eligibilityMode` + `tblDiscountEligibility`. **`resolveDiscountForCheckout()` must be changed in the same phase** so a restricted code can never apply to anyone else (§10). |
| Education / non-profit 50% after a manual check | none | Same eligibility table ("approved customers" list) + a customer look-up screen. |
| Launch 25% × 12 months until 31 Dec 2026 | none (Pricing_Strategy §5.2 describes the row) | Seed row. **Automatic rows have `code` NULL, so nothing stops a second copy being inserted** → new `discountKey` (stable internal name, UNIQUE). |
| Fixed-amount discounts with three currencies | `value` is "a flat amount in the price point's own currency" (`install.sql:1057`) — meaningless when the same code meets £, $ and € | New `currency` column, required for `fixed`. |
| Scope to several plans / billing periods | one `appliesPlanID` or all; `appliesPriceKey` exact string (breaks once price keys carry a currency suffix) | New `scopeJSON` (plan keys, billing intervals, currencies). Legacy columns kept and still honoured. |

### R4. Lifetime-deal manager
| Need | Exists today | Gap |
| --- | --- | --- |
| Price, plan granted, own limits | `billingInterval='lifetime'` price points; lifetime purchase = `tblOneOffPurchases` row + `tblSubscriptions` row with `currentPeriodEnd` NULL (`install.sql:1096-1101`) | A deal is modelled as **its own plan row** (`planKind='lifetime_deal'`) copied from the plan it is "based on" (new `basedOnPlanID`), with its own `limitsJSON` — no gating-engine change needed (`planByKey()` resolves any key). |
| Time-limited and/or quantity-limited | `validFrom`/`validUntil` on price points only; **no sales counter or cap anywhere** (Pricing_Strategy §5.3 flags this) | New plan-level `availableFrom`, `availableUntil`, `maxSales`. Sold count is **counted**, not stored (§4.5). |

### R5. Pay-as-you-go packs + monthly cap
| Need | Exists today | Gap |
| --- | --- | --- |
| Packs with prices | `one_off` price points + `grantsJSON` (`install.sql:975-978`); ledger `tblCreditLedger` (`:1225-1260`); `creditBalanceFor()`, `consumeCreditsOr402()` (`fn_features.php:1325`, `:1718`) | Seed a `payg` plan (`planKind='credit_packs'`) and three packs; screen. |
| Monthly spending cap (£20) | `credit_spend_cap_monthly` limit read by `evaluateCreditSpend()` (`fn_features.php:1521-1546`) — but it caps **credits used per calendar month**, not **money**, and **0 means "pay-as-you-go not allowed on this plan"** (fail-closed) | **Mismatch with "£20"**. Plan: a money cap per currency on pack purchases (new setting), enforced by checkout later; keep the existing credit-usage cap as a separate plan limit (set −1 on the PAYG plan). Confirm with the owner (Q6). |

### R6. Price promise (grandfathering)
| Need | Exists today | Gap |
| --- | --- | --- |
| Per plan: "while subscribed" or N months | Only `tblSubscriptions.pricePointID` (which price the customer bought) | New plan columns `pricePromiseMode` / `pricePromiseMonths` (NULL = site default), default settings, and snapshot columns on `tblSubscriptions` (`pricePromiseMode`, `priceLockedUntil`) that **checkout writes later**. |

### R7. Feature placement (incl. accessibility split, SSO)
| Need | Exists today | Gap |
| --- | --- | --- |
| Which plan gets which feature / limit | Plan JSON, read generically by `featureEnabled()` / `limitFor()` (`fn_features.php:635-674`); public labels + "available / coming soon" in the hand-written allow-list `pricingAllowedDisplayKeys()` (`fn_pricing.php:274-428`, owner decision #125) | A features × plans grid editor. Labels and build status stay in code (§4.6). |
| Accessibility split adjustable | Braille caption / tactile / Avery are `pdf_layout` values validated at `fn_api_generate.php:1398` and used by `fn_qr_accessible_pdf.php`, `fn_api_bulk.php`, `fn_api_qrcodes.php`, the generator UI; contrast/print-size fields at `fn_api_generate.php:448`, `:684` | **No feature keys and no gates** — today they are free for everyone. Needs new keys + gate code in the generator and API (+ OpenAPI 402 docs). The contrast **safety refusal** (below 3:1, #48) is never gated. |
| SSO for every plan; org-wide SSO = paid Business (#131, 7q) | `sso` feature key (Business only) is **not enforced anywhere** (#131) | Relabel the `sso` key on the pricing allow-list as "Organisation-wide single sign-on — coming soon" (#21); personal SSO stays ungated. |
| Rate limits per plan | `api_rate_limit_per_*` are synced onto `tblAPIKeys` only at key creation and on plan change (`fn_api_keys.php:377-378`, `syncApiKeyLimitsForUser()` `fn_features.php:845`) | Editing a plan's rate limits does nothing to existing keys → add "update existing keys" action. **Anonymous visitors' limits come from the settings `api_rate_limit_anonymous_per_*` (`fn_api_auth.php:132-137`), not the anonymous plan's JSON** → show those two cells read-only with a link to the setting. |

### R8. Charging stays off
The three `billing_*_enabled` switches (`seed_data.sql:156-158`) stay `0`. The admin screens show them **read-only and locked** ("Off — switches on when checkout is built, after dynamic QR"). No admin code path may write them (static test, §9).

### R9. Refunds (30-day money-back, cancel anytime)
Admin touch-points only: a `billing_refund_window_days` setting (30) shown on the pricing page once charging starts and used later by checkout; its help text says the Terms wording must match (legal review **#38**). No `terms.php` change in this task.

### Global Admin itself
`tblUsers.roleID` (`install.sql:374`, comment "1=Global Admin, 2=Admin, 3=Regular User"). **Nothing sets it** (registration `fn_auth.php:373` and SSO sign-up `fn_oauth.php:2165` insert no `roleID`, so every user has NULL) and **nothing checks it**. `currentUser()` (`fn_auth.php:930-994`) does not return it.

---

## 3. Things found while planning (fix inside this task or raise as follow-ups)

| # | Finding (evidence) | Impact | Where it is handled |
| --- | --- | --- | --- |
| F1 | `logActivity()` takes the user from `$_SESSION['userID']` (`fn_activity_log.php:130-134`), but **no code ever sets that key** (the auth engine stores `cuercode_user_session_id`). Same in `fn_error_handler.php:409` and `fn_qr_generate.php:874`. | Every activity-log row records "no user", so it cannot serve as an admin audit trail. | Admin changes go to a **new** `tblAdminAuditLog` with the actor passed explicitly. Fixing `logActivity()` itself → follow-up issue (fits #114). |
| F2 | `setConfig()` ignores `isEditable` and the row's own `settingType` (`fn_config.php:237-241`, `:347-353`). | An admin form could overwrite `graph_token_cache` or store `false` as `''`. | Admin save layer checks `isEditable` and passes `type` (§6.6). `setConfig()` itself is not changed. |
| F3 | Exception traces use `getTraceAsString()` (`fn_error_handler.php:171`, `:183`, `:195`), which **includes function arguments** when PHP's `zend.exception_ignore_args` is Off (DreamHost's value is unknown). | A secret passed to `setConfig()` / `encryptValue()` could land in `tblErrorLog` if anything throws. | Mark secret parameters `#[\SensitiveParameter]` (§10 S14). |
| F4 | The two `ALTER TABLE` blocks in `install.sql:1274-1302` make a second run fail; there is no upgrade path (#113 item 5, #70 acceptance). | First real database test / any upgrade breaks. | Phase 1A: migration runner; the ALTERs move into migration 0001. |
| F5 | `credit_spend_cap_monthly` counts **credits used**, and 0 = "not allowed" (`fn_features.php:1521-1546`); the owner's cap is **£20**. | Wrong meaning if copied blindly. | Money cap per currency as a separate setting (Phase 6, Q6). |
| F6 | `tblDiscounts.value` for `fixed` has no currency; `appliesPriceKey` is an exact string. | Wrong amounts with £/$/€. | New `currency` + `scopeJSON` (Phase 4). |
| F7 | Anonymous API rate limits come from settings, not the anonymous plan's JSON (`fn_api_auth.php:132-137`). | Editing those cells in the plan grid would silently do nothing. | Grid shows them read-only with a link to the setting (Phase 3). Unifying them → follow-up. |
| F8 | Changing a plan's API rate limits does not touch existing keys (synced only at key creation / plan change). | Edits look saved but do not apply. | "Update existing API keys now" action (Phase 3). |
| F9 | `pricingApplyDiscount()` does money arithmetic in floats (`fn_pricing.php:563-591`). | Rounding drift at checkout. | New integer "minor units" helpers in `fn_money.php` (Phase 2); checkout must use them. |
| F10 | The alpha deploy folder `public_html_dev_alpha/` contains only `infoAppVer.php` while the shared `_functions/` and `_includes/` deploy on every branch (`deploy.yml:75-221`). | How alpha testers reach a working sign-up/admin depends on which branch is deployed where. Not caused by this task. | Flag to the lead; not changed here. |
| F11 | `install.sql` gives `tblAPIKeys.permissions` an expression default `(JSON_ARRAY('generate'))` (`install.sql:305`), which needs MySQL 8.0.13+, while README says MySQL 5.7+. | Install fails on MySQL 5.7. | Follow-up (README or schema). New migrations avoid anything newer than MySQL 8.0 / MariaDB 10.3. |
| F12 | `/pricing` today shows **old placeholder prices** (£4.99 Pro monthly) via the legacy columns, because `billing_price_points_enabled` is off. | Public page disagrees with the owner's draft. | Q2; Phase 2 mirrors the default-currency price points into the legacy columns. |

---

## 4. Design decisions (with the alternatives that were rejected)

### 4.1 Where admin pages live and how they talk to the server
- **Decision:** server-rendered pages under `public_html/admin/`, each a folder with `index.php` (house pattern, e.g. `public_html/account/`). Every change is a normal `<form method="POST">` to the same page, followed by a redirect (Post/Redirect/Get). JavaScript only **adds** comfort (show/hide secret, "unlimited" toggle, live helpers, confirmation dialogs); everything works with JavaScript off.
- **No `/api/v1/admin/*` routes.** Admin functions are reachable only with a signed-in browser session, never with an API key. **OpenAPI impact for the admin screens: none.** (Phase 3 has a separate, small API change — §7.3.)
- *Rejected:* AJAX saves through new JSON API routes (the house pattern for the account page). Reason: it would put the most sensitive operations behind the public API router, add API-key and scope questions, and grow `openapi.json` for endpoints nobody else may call. AJAX can be added later on top of the same handlers.

### 4.2 Database upgrades (#113 item 5)
- **Decision:** a small **numbered-migration** mechanism, no Composer, works on shared hosting:
  - `install.sql` and `seed_data.sql` become the **frozen baseline** (as at the commit that adds the runner). The only edit is moving the two `ALTER` blocks out of `install.sql` into migration `0001`, which makes `install.sql` safe to run any number of times (it is then only `SET` + `CREATE TABLE IF NOT EXISTS`).
  - Every later schema or starting-data change is a PHP file in `_includes/sql/migrations/NNNN_short_name.php` that **returns an array of steps** (declarative, no code runs on load). Each step is checked against the live database before it runs (does the table/column/index/foreign key already exist? — via `INFORMATION_SCHEMA`), so **every migration is safe to run twice** and safe on a database that already has part of it.
  - A table `tblSchemaMigrations` records which migrations ran, when, by whom, and a checksum of the file.
  - Three ways to run them: the **web installer** (fresh install: `install.sql` → `seed_data.sql` → all migrations), a **command-line tool** `_includes/cli/migrate.php`, and an **admin page** `/admin/upgrades/` (for owners without SSH).
- *Why not `ADD COLUMN IF NOT EXISTS`?* MySQL (any version) does not support it (only MariaDB does). *Why not stored procedures?* Shared-hosting database users often lack `CREATE ROUTINE`. *Why not keep editing `install.sql` and also write migrations?* Two copies of every change drift apart; one source per change is the GIRFT choice.
- **Seeded rows never overwrite the owner's edits:** inserts use `INSERT ... ON DUPLICATE KEY UPDATE <key> = <key>` (a no-op when the row exists); "update" steps only change a value **while it still equals the old seeded value** (`... WHERE priceMonthly = 4.99`).

### 4.3 "Existing customers keep what they bought" — the freezing rules
- **P1 Price points** referenced by any `tblSubscriptions`, `tblPayments` or `tblOneOffPurchases` row are **frozen**: `amount`, `currency`, `billingInterval`, `intervalCount`, `grantsJSON` cannot change. To change a price you **retire** the old one (set `validUntil` / `isActive=0`) and add a new one. Unused price points are freely editable or deletable.
- **P2 Discounts** with `redemptionCount > 0`: `discountType`, `value`, `currency`, `durationType`, `durationMonths` are frozen. Name, note, end date, cap (never below `redemptionCount`), and on/off stay editable.
- **P3 Plans** with active paying subscribers (`tblSubscriptions.status IN ('active','trialing','past_due')`): changes that **give more** apply straight away; changes that **take something away** (feature switched off, limit lowered, unlimited → number) need either an explicit "apply to existing customers too" confirmation **with a typed reason** (audited), or the **"make a new version for new customers"** action (copies the plan to a new key, retires the old one from sale; existing subscribers stay on the old row, which still resolves). Subject to owner question **Q5**.
- **P4** Plans are never hard-deleted once anything references them; "delete" exists only for a plan that was never used (no subscriptions, purchases, price points with history, discounts or settings pointing at it).
- **P5** `anonymous` and `free` (the two fallback plans referenced by `fn_features.php:72-78`) and any plan named in a setting (`billing_past_due_downgrade_plan_key`, `billing_trial_plan_key`) cannot be retired or deleted.
- **P6** `planKey` cannot be changed after creation (subscriptions, settings, discount scopes and the code's two fallbacks refer to it). `planName` can.

### 4.4 Lifetime deals = a plan of kind `lifetime_deal`
- **Decision:** each deal is its own plan row (`planKind='lifetime_deal'`), created by copying the features and limits of the plan it is based on (`basedOnPlanID`, informational), then given its own limits. It has `billingInterval='lifetime'` price points (one per currency), and sale rules on the plan row: `availableFrom`, `availableUntil`, `maxSales`. A draft deal has `isActive=0`; "Start the deal" sets `isActive=1` and the dates (end date pre-filled from the `billing_lifetime_default_window_days` setting, 60).
- Works with the gating engine unchanged, and buyers keep exactly what they bought because their subscription points at the deal's own row.
- *Rejected:* a separate deals table plus "inherit from base plan with overrides" — needs a change to the plan resolver (`resolvePlanArray()`), and later reductions to Pro would silently reduce lifetime buyers. New features can still be added to several plans at once from the feature grid (Phase 3).

### 4.5 Sale caps are counted, not stored
"Sold so far" = number of `tblOneOffPurchases` rows with `status='completed'` whose price point belongs to the plan (lifetime deals), or `tblSubscriptions` rows created by checkout for the plan (subscription plans, later). Checkout (later) must check the cap **inside a per-plan MySQL named lock** (`GET_LOCK`, the F-023 pattern) before taking payment. The admin screen only displays the count and refuses a cap below it. *Rejected:* a stored counter — it can drift from the real purchases.

### 4.6 Feature labels and "built or not" stay in code
- **Decision:** extend the existing code allow-list (`pricingAllowedDisplayKeys()`) into a fuller **entitlement registry** (new function `entitlementRegistry()` in `fn_pricing.php`, the old function kept as a wrapper so existing tests pass): for each key — kind (feature/limit), public label, plain-English admin help, unit (limits), whether "unlimited" is allowed, min/max, category, and **build status** (`available` / `coming_soon`).
- The **commercial choices** — which plan has which feature, every limit value — are data, edited in the grid.
- Keys found in a plan's JSON but not in the registry are shown as "Unknown setting — not used by the software" (read-only, removable), never silently dropped.
- *Rejected:* a database catalogue of features. A feature does nothing without code that enforces it, so "which features exist and whether they are built" is a fact about the code; putting it in data would let a screen advertise a feature as included when it is not (the exact problem owner decision #125 fixed).

### 4.7 Multi-currency display while charging is off
- Price points in £, $ and € are stored and edited now. The **public** page keeps using the legacy single-currency columns while `billing_price_points_enabled` stays off (instruction R8); the editor **mirrors** each plan's default-currency (`billing_default_currency`, £) monthly and yearly default price points into `priceMonthly` / `priceYearly` / `currency` after every price save (one function, `adminMirrorLegacyPlanPrices()`), subject to Q2. Global Admins get a **pricing preview** (`/admin/pricing-preview/`) that renders the page as it will look with price points and a currency switcher.
- *Rejected:* reading price points for display regardless of the switch — changes the documented meaning of a kill switch the owner asked to keep off.

### 4.8 One discount per purchase
Default rule (Q4): only one discount applies to a purchase; when several could apply, the customer gets the **biggest saving**. Stored as a setting `billing_discount_combining = '0'` so it is data, not code. Checkout implements it later; the admin "check a code" tool already uses the same rule.

### 4.9 Accessibility split
New feature keys for each accessibility output (§5.5, migration 0004). They are seeded **on for every plan** so nothing changes for anyone until the owner moves them in the grid (Q3 recommends doing that on the day charging starts). The contrast **safety refusal** below 3:1 (#48) always runs and has no key — it protects people scanning the code.

---

## 5. Data-model changes (all additive) and how existing databases get them

### 5.1 The migration runner (Phase 1A)

**Files**
- `_functions/fn_migrations.php` — runner functions (pure planner + database executor). Must work **without** `bootstrap.php` (the web installer runs before the app is set up), so it takes a `mysqli` argument and never calls `dbQuery()`/`getConfig()`.
- `_includes/sql/migrations/NNNN_short_name.php` — one file per migration, returns an array (below). Four-digit zero-padded number, applied in number order.
- `_includes/cli/migrate.php` — command line (`--status`, `--dry-run`, `--apply`). First statement: refuse unless `PHP_SAPI === 'cli'` (copy `_includes/cron/process_email_queue.php`'s guard). `_includes/.htaccess` already denies web access.
- `public_html/install/index.php` — after Step 5 (seed), run all migrations with `appliedBy='installer'`; add the new tables to `$expectedTables` (`:326-330`).
- `public_html/admin/upgrades/index.php` — Phase 1B (needs the admin gate).

**Migration file shape** (load-bearing — builders copy this):
```php
<?php
// Path: _includes/sql/migrations/0001_subscription_price_snapshot_columns.php  (+ full house header)
return [
    'key'         => '0001_subscription_price_snapshot_columns',
    'description' => 'Adds the #70 price-point/discount snapshot columns (moved here from install.sql so install.sql can be run twice)',
    'steps'       => [
        [
            'type'   => 'add_column',
            'table'  => 'tblSubscriptions',
            'name'   => 'pricePointID',
            'sql'    => 'ALTER TABLE `tblSubscriptions` ADD COLUMN `pricePointID` INT UNSIGNED NULL COMMENT \'...\' AFTER `planID`',
        ],
        // ... one step per column / index / foreign key / row
    ],
];
```

**Step types** (whitelist; anything else = the migration is invalid and nothing runs):
| type | needed-check (prepared statement, `TABLE_SCHEMA = DATABASE()`) | SQL must start with |
| --- | --- | --- |
| `create_table` | `INFORMATION_SCHEMA.TABLES` (`TABLE_NAME`) | `CREATE TABLE IF NOT EXISTS` |
| `add_column` | `INFORMATION_SCHEMA.COLUMNS` (`TABLE_NAME`, `COLUMN_NAME`) | `ALTER TABLE` + `ADD COLUMN` |
| `add_index` | `INFORMATION_SCHEMA.STATISTICS` (`TABLE_NAME`, `INDEX_NAME`) | `ALTER TABLE` + `ADD INDEX` / `ADD UNIQUE KEY` |
| `add_foreign_key` | `INFORMATION_SCHEMA.TABLE_CONSTRAINTS` (`CONSTRAINT_TYPE='FOREIGN KEY'`, `CONSTRAINT_NAME`) | `ALTER TABLE` + `ADD CONSTRAINT` |
| `insert_if_missing` | none — SQL must end with `ON DUPLICATE KEY UPDATE` `` `x` = `x` `` (no-op) | `INSERT INTO` |
| `update_if_unchanged` | none — SQL must contain a `WHERE` that only matches rows still in their old seeded state (e.g. `WHERE priceMonthly = 4.99`, or `WHERE JSON_CONTAINS_PATH(...) = 0`) | `UPDATE` |

Validation (pure, unit-tested): `key` matches the file name; `table`/`name` appear back-quoted inside `sql`; types whitelisted. The remaining checks run on the SQL **with its single-quoted text (e.g. `COMMENT '...'`) blanked out first**, so words inside comments do not trip them: one statement per step (no `;` except a trailing one); additive only — refuse the whole words `DROP`, `TRUNCATE`, `RENAME`, `MODIFY`, `CHANGE` and the phrase `DELETE FROM` (the foreign-key clause `ON DELETE SET NULL` / `ON DELETE CASCADE` is allowed).

**Running** (`migrationApplyPending(mysqli $db, string $appliedBy, bool $dryRun): array`):
1. `SELECT GET_LOCK('cuercode_schema_migrate', 0)` — if not 1, stop ("another upgrade is running").
2. Create `tblSchemaMigrations` if missing (DDL in §5.2; it is the runner's own "step zero").
3. **Baseline check** for databases that never had a migration: every table in `install.sql` must exist. If any is missing, run `install.sql` (now idempotent) to create it. Then compare columns of each baseline table with the column names parsed from `install.sql`'s `CREATE TABLE` blocks (each column line starts with a back-quoted name); if a baseline **column** is missing, **stop** and print the list — that database is older than the supported baseline and needs a one-off catch-up migration (see Q1).
4. For each pending migration, in order: validate → for each step, run the needed-check → run or skip → on any error stop (do **not** record it; the next run resumes safely because every step re-checks) → when all steps pass, `INSERT` into `tblSchemaMigrations` with the file's SHA-256.
5. A recorded migration whose file checksum has changed is reported as a **warning** ("changed after it was applied") and never re-run.
6. `RELEASE_LOCK`. Return a structured result (applied / skipped steps / error) — never secret values.

Each step's SQL runs through `$db->prepare()` + `execute()` with no parameters (MySQL allows `CREATE`/`ALTER`/`INSERT`/`UPDATE` as prepared statements, which keeps the house "prepared statements for all queries" rule); only the baseline re-run of `install.sql` uses `multi_query()`, exactly as the installer does. Seed rows that need another row's ID use `INSERT ... SELECT` by key (e.g. `SELECT planID FROM tblSubscriptionPlans WHERE planKey = 'pro'`), never a hard-coded ID.

DDL in MySQL is not transactional; idempotent steps are what makes a half-finished run safe to repeat. Long runs: call `set_time_limit()` where allowed; the tables are small today.

**CLI exit codes:** 0 = up to date / applied; 1 = a step failed; 2 = refused (not CLI, busy lock, unsafe baseline); 3 = `--status` found pending migrations (for scripts).

### 5.2 New tables

```sql
-- Phase 1A (runner "step zero")
CREATE TABLE IF NOT EXISTS `tblSchemaMigrations` (
    `migrationKey`  VARCHAR(100)      NOT NULL,              -- '0001_subscription_price_snapshot_columns'
    `checksum`      CHAR(64)          NOT NULL,              -- SHA-256 of the migration file when applied
    `appliedAt`     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `appliedBy`     VARCHAR(100)      NOT NULL,              -- 'installer' | 'cli:<os user>' | 'admin:<userID>'
    `durationMs`    INT UNSIGNED      NULL,
    `stepsRun`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `stepsSkipped`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`migrationKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 1A, migration 0002
CREATE TABLE IF NOT EXISTS `tblAdminAuditLog` (
    `auditID`      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `actorUserID`  INT UNSIGNED    NULL,                     -- FK tblUsers (NULL for cli/system or after hard purge)
    `actorType`    ENUM('admin','cli','system') NOT NULL,
    `actorLabel`   VARCHAR(255)    NOT NULL,                 -- email at the time, or 'cli:<os user>', or 'system'
    `action`       VARCHAR(64)     NOT NULL,                 -- see §6.7 action names
    `entityType`   VARCHAR(50)     NOT NULL,                 -- 'setting','plan','price_point','discount','user','schema',...
    `entityKey`    VARCHAR(100)    NULL,                     -- settingKey / planKey / discountKey / numeric id
    `outcome`      ENUM('success','refused','failed') NOT NULL DEFAULT 'success',
    `changesJSON`  JSON            NULL,                     -- {"field":{"old":..,"new":..}}; secrets ONLY as markers
    `reason`       VARCHAR(500)    NULL,                     -- admin-typed reason (required for risky changes)
    `requestIP`    VARCHAR(45)     NULL,
    `userAgent`    VARCHAR(500)    NULL,
    `createdAt`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`auditID`),
    INDEX `idx_admin_audit_created` (`createdAt`),
    INDEX `idx_admin_audit_actor` (`actorUserID`, `createdAt`),
    INDEX `idx_admin_audit_entity` (`entityType`, `entityKey`),
    CONSTRAINT `fk_admin_audit_actor` FOREIGN KEY (`actorUserID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 4, migration 0005
CREATE TABLE IF NOT EXISTS `tblDiscountEligibility` (
    `eligibilityID`    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `discountID`       INT UNSIGNED    NOT NULL,             -- FK tblDiscounts ON DELETE CASCADE
    `userID`           INT UNSIGNED    NULL,                 -- FK tblUsers ON DELETE CASCADE (XOR with orgID)
    `orgID`            INT UNSIGNED    NULL,                 -- FK tblOrganisations ON DELETE CASCADE
    `approvedByUserID` INT UNSIGNED    NULL,                 -- FK tblUsers ON DELETE SET NULL
    `approvedAt`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expiresAt`        DATETIME        NULL,                 -- optional re-check date (e.g. yearly for education)
    `revokedAt`        DATETIME        NULL,                 -- set when withdrawn; re-approval clears it
    `evidenceNote`     VARCHAR(500)    NULL,                 -- what was checked; NEVER uploaded documents
    PRIMARY KEY (`eligibilityID`),
    UNIQUE KEY `idx_eligibility_discount_user` (`discountID`, `userID`),
    UNIQUE KEY `idx_eligibility_discount_org`  (`discountID`, `orgID`),
    INDEX `idx_eligibility_user` (`userID`)
    -- + the four FOREIGN KEY constraints named fk_eligibility_discount / _user / _org / _approver
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
The audit log is **append-only by design**: no screen or function updates or deletes rows. (Shared hosting gives one database user, so this cannot be enforced with database permissions — stated in SECURITY.md.) Kept indefinitely by default; a retention setting can be added later if the owner wants one (UK accounting records: at least 6 years).

### 5.3 New columns on existing tables (one migration step per column / index / foreign key)

| Table | Column | Definition | Phase / migration |
| --- | --- | --- | --- |
| `tblSubscriptions` | `pricePointID`, `discountID` + indexes + FKs | exactly as `install.sql:1274-1286` today | 1A / 0001 |
| `tblPayments` | `pricePointID` + index + FK | exactly as `install.sql:1296-1302` | 1A / 0001 |
| `tblSubscriptionPlans` | `planKind` | `ENUM('standard','lifetime_deal','credit_packs') NOT NULL DEFAULT 'standard' AFTER planKey` | 2 / 0003 |
| | `ctaMode` | `ENUM('checkout','contact') NOT NULL DEFAULT 'checkout'` | 2 / 0003 |
| | `contactUrl` | `VARCHAR(255) NULL` (https URL or `mailto:`; NULL = use `site_email`) | 2 / 0003 |
| | `availableFrom`, `availableUntil` | `DATETIME NULL` (UTC; NULL = no limit) | 2 / 0003 |
| | `maxSales` | `INT UNSIGNED NULL` (NULL = no cap) | 2 / 0003 |
| | `basedOnPlanID` | `INT UNSIGNED NULL` + index + FK → `tblSubscriptionPlans(planID)` ON DELETE SET NULL | 2 / 0003 |
| | `pricePromiseMode` | `ENUM('while_subscribed','fixed_months','none') NULL` (NULL = site default) | 2 / 0003 |
| | `pricePromiseMonths` | `SMALLINT UNSIGNED NULL` | 2 / 0003 |
| | index | `idx_plan_kind_active (planKind, isActive, sortOrder)` | 2 / 0003 |
| `tblPlanPricePoints` | `priceGroupKey` | `VARCHAR(48) NULL AFTER priceKey` + index `(planID, priceGroupKey)` | 2 / 0003 |
| `tblSubscriptions` | `pricePromiseMode` | same ENUM, `NULL` — **snapshot written by checkout later** | 2 / 0003 |
| | `priceLockedUntil` | `DATETIME NULL` — NULL with `while_subscribed` = for as long as the subscription lasts | 2 / 0003 |
| `tblDiscounts` | `discountKey` | `VARCHAR(48) NULL` + `UNIQUE KEY idx_discount_key` | 4 / 0005 |
| | `eligibilityMode` | `ENUM('anyone','approved_list') NOT NULL DEFAULT 'anyone'` | 4 / 0005 |
| | `scopeJSON` | `JSON NULL` — `{"planKeys":[...],"billingIntervals":[...],"currencies":[...]}`; a missing/empty list = no restriction on that dimension | 4 / 0005 |
| | `currency` | `CHAR(3) NULL` — **required** when `discountType='fixed'`, must be NULL for `percent` | 4 / 0005 |
| | `internalNote` | `VARCHAR(500) NULL` | 4 / 0005 |
| | `createdByUserID` | `INT UNSIGNED NULL` + FK → `tblUsers` ON DELETE SET NULL | 4 / 0005 |

**Price-key convention (written by the editor, never typed by people):** `priceGroupKey` = `monthly`, `yearly`, `lifetime`, `credits_100`, …; `priceKey` = `priceGroupKey` + `_` + lower-case currency (`monthly_gbp`, `yearly_usd`, `lifetime_eur`). This stays within the existing UNIQUE (`planID`, `priceKey`) and within `VARCHAR(48)`.

### 5.4 New settings rows (`insert_if_missing`; group, type, sensitive, default)

| Key | Group | Type | Secret | Seed | Phase |
| --- | --- | --- | --- | --- | --- |
| `email_queue_retention_days` / `email_queue_failed_retention_days` | email | integer | no | 30 / 90 (only if missing — pre-#122 installs) | 1A / 0002 |
| `hcaptcha_site_key`, `hcaptcha_secret_key` | security | string | **yes** | '' | 1A / 0002 |
| (description of `bot_protection_provider` → "(none, turnstile, recaptcha, hcaptcha)", only while unchanged) | | | | | 1A / 0002 |
| `paypal_mode` | paypal | string | no | `sandbox` | 1A / 0002 |
| `paypal_client_id`, `paypal_client_secret`, `paypal_webhook_id` | paypal | string | **yes** | '' | 1A / 0002 |
| `billing_vat_mode` | billing | string | no | `inclusive` (`inclusive` / `exclusive` / `none`) | 1A / 0002 |
| `billing_vat_rate_percent` | billing | float | no | `20.00` | 1A / 0002 |
| `billing_refund_window_days` | billing | integer | no | `30` | 1A / 0002 |
| `billing_trials_enabled` | billing | boolean | no | `0` | 1A / 0002 |
| `billing_trial_plan_key` | billing | string | no | `pro` | 1A / 0002 |
| `billing_trial_days` | billing | integer | no | `14` | 1A / 0002 |
| `billing_trial_requires_card` | billing | boolean | no | `0` | 1A / 0002 |
| `admin_reauth_idle_minutes` | admin | integer | no | `15` | 1A / 0002 |
| `admin_reauth_max_minutes` | admin | integer | no | `60` | 1A / 0002 |
| `admin_display_timezone` | admin | string | no | `Europe/London` | 1A / 0002 |
| `billing_currencies` | billing | string | no | `GBP,USD,EUR` | 2 / 0003 |
| `billing_default_currency` | billing | string | no | `GBP` | 2 / 0003 |
| `billing_price_promise_default_mode` | billing | string | no | `while_subscribed` | 2 / 0003 |
| `billing_price_promise_default_months` | billing | integer | no | `12` (used only for `fixed_months`) | 2 / 0003 |
| `pricing_show_prices` | billing | boolean | no | per **Q2** (recommended `1`) | 2 / 0003 |
| `billing_discount_combining` | billing | boolean | no | `0` (per **Q4**) | 4 / 0005 |
| `billing_lifetime_default_window_days` | billing | integer | no | `60` | 5 / 0006 |
| `billing_payg_monthly_spend_cap` | billing | json | no | `{"GBP":"20.00"}` ($/€ blank until the owner sets them; per **Q6**) | 6 / 0007 |

Descriptions must be plain English (they show in the generic settings editor, Phase 7).

### 5.5 Starting data (seeded by migrations, all editable afterwards)

**Phase 2 / 0003 — plans & prices**
- Price points (GBP only; $ and € are entered by the owner in the editor):
  `pro`: `monthly_gbp` 6.99 (group `monthly`), `yearly_gbp` 49.99 (group `yearly`, `isDefault=1`);
  `business`: `monthly_gbp` 24.99, `yearly_gbp` 199.99 (`isDefault=1`);
  `enterprise`: `monthly_gbp` 99.00 (shown as "From £99/month").
- New plan `enterprise` (insert if missing): `planKind='standard'`, `ctaMode='contact'`, `contactUrl` NULL (→ `site_email`), `sortOrder=4`, `isActive=1`, features = Business's featuresJSON, limits = Business's limitsJSON (the owner raises them per contract; unlimited bulk would be a load risk), description "For organisations that need custom limits, a contract and named support."
- Legacy columns, **only while still at the placeholder values**: `pro.priceMonthly` 4.99 → 6.99; `business.priceMonthly` 19.99 → 24.99 (yearly values already match). Skipped automatically if the owner has changed them. If Q2 = "hide prices", this step is dropped.

**Phase 3 / 0004 — feature keys** (adds each key to every plan's `featuresJSON` **only where missing**, via `UPDATE ... SET featuresJSON = JSON_SET(featuresJSON, '$.key', true) WHERE JSON_CONTAINS_PATH(featuresJSON, 'one', '$.key') = 0`): `a11y_accessible_preset`, `a11y_contrast_report`, `a11y_print_size_guide`, `a11y_braille_caption`, `a11y_tactile_frame`, `a11y_avery_labels` — all `true` on every plan (nothing changes until the owner moves them; Q3).

**Phase 4 / 0005 — discounts**
- `discountKey='launch_2026'`: code NULL (automatic), name "Launch offer — 25% off for 12 months", percent 25.00, `durationType='repeating'`, `durationMonths=12`, `validFrom` NULL, `validUntil='2026-12-31 23:59:59'` (UTC = UK time in December), `scopeJSON={"billingIntervals":["monthly","yearly"]}` (so it never applies to lifetime deals or credit packs), `isActive=1`. Inert while `billing_discounts_enabled=0`.
- `discountKey='edu_nfp'`: code NULL, name "Education / non-profit — 50% off", percent 50.00, `durationType='forever'`, `eligibilityMode='approved_list'`, same scope, `isActive=1`.
- "12 months" on a yearly subscription means the first yearly payment only (cycles that start within 12 months of redemption) — written into the discount help text and the checkout brief.

**Phase 5 / 0006 — Founders Lifetime**
- Plan `pro_lifetime_founders`: `planKind='lifetime_deal'`, `basedOnPlanID` = Pro, features = Pro's, limits = Pro's except `dynamic_codes: 25` (Pricing_Strategy §5.3 starting value — owner adjusts), `isActive=0` (draft), `maxSales=500`, `sortOrder=0`, name "Founders Lifetime".
- Price point `lifetime_gbp` 119.99, `billingInterval='lifetime'`, group `lifetime`.

**Phase 6 / 0007 — pay-as-you-go**
- Plan `payg`: `planKind='credit_packs'`, `isActive=1`, `sortOrder=0`, features/limits per Pricing_Strategy §4 PAYG column, plus `credit_spend_cap_monthly: -1` (credit **usage** not capped; the **money** cap is the setting above).
- Price points (`billingInterval='one_off'`): `credits_100_gbp` 4.99 → `{"credits":{"qr_generations":100}}`; `credits_500_gbp` 19.99 → 500; `credits_2000_gbp` 59.99 → 2000.

### 5.6 Changes to `install.sql`, `seed_data.sql` and existing tests (Phase 1A)
- `install.sql`: delete the two `ALTER` blocks (`:1262-1302`); add a short comment there pointing at `_includes/sql/migrations/0001_subscription_price_snapshot_columns.php` **without** writing the forbidden literal texts; add a header note: "Frozen baseline. Every schema change after 2026-10 is a numbered migration in `_includes/sql/migrations/`." Update the #70 delta header comment (`:919-961`, which says the two blocks "follow" Tables 22-26) and the closing summary (Tables 20/21 lines) to say the columns are added by migration 0001.
- `seed_data.sql`: header note only ("frozen baseline; new starting data lives in migrations").
- `tests/test_dynamic_redirect_s0.php:325-332`: ALTER count 2 → **0**, with a comment that the two #70 ALTERs now live in migration 0001.
- `tests/test_pricing_flex.php:352-353`: assert the `ADD COLUMN` texts in the **migration 0001 file** instead of `install.sql`.

---

## 6. Global Admin access model (Phase 1B unless stated)

### 6.1 Who is a Global Admin
A signed-in user is a Global Admin **only when all of these are true, checked fresh from the database on every request** (never cached in the PHP session, so removing someone takes effect on their very next click):
- `tblUsers.roleID = 1` (new constant `CUERCODE_ROLE_GLOBAL_ADMIN` = 1, in `fn_admin.php`, documented against `install.sql:374`);
- `status = 'active'` and `emailVerifiedAt IS NOT NULL`;
- the database session from `currentUser()` is valid (not revoked, not expired).
`roleID = 2` ("Admin") gets **no** admin access in this task.

New file `_functions/fn_admin.php` (loaded only by admin pages and the CLI tools, not by `bootstrap.php`):
- `adminLoadAccessRow(int $userID): ?array` — `SELECT roleID, status, emailVerifiedAt, (passwordHash IS NOT NULL) AS hasPassword, email, displayName FROM tblUsers WHERE userID = ?`.
- `adminAccessDecision(?array $currentUser, ?array $accessRow): string` — **pure**; returns `not_signed_in` | `not_admin` | `admin`.
- `requireGlobalAdminPage(string $selfPath): array` — the first call on every admin page:
  - not signed in → `302` to `/login/?next=<urlencoded admin path>` (login's `resolveSafeNextUrl()`, `login/index.php:57`, already accepts root-relative paths);
  - signed in but not a Global Admin → **404** using `public_html/error/404.php` (does not reveal that the area exists) + one activity-log row `admin_denied` with the user ID passed as `entityType='user'`, `entityID=<userID>` (because `logActivity()` cannot see the user itself — F1);
  - admin → returns the admin row; also sends the security headers in §7.4.
- `adminCountGlobalAdmins(): int`.
- Small additive change: `currentUser()` (`fn_auth.php:948-993`) also selects and returns `roleID`, so `header.php` can show the "Admin" menu item without an extra query. The menu item is only a convenience — the gate above is what protects the pages.

### 6.2 Creating the FIRST Global Admin safely
No web page can make the first admin (a "first visitor becomes admin" page would let a stranger claim a fresh site before the owner). Steps, documented in DEV_NOTES.md "First Global Admin":
1. Fresh install as today (`/install/`, now also running the migrations) — or `php _includes/cli/migrate.php --apply` on an existing install.
2. The owner registers normally at `/register/`. The account stays **pending** because email is not set up yet (this is the chicken-and-egg problem — no email, so no verification link).
3. Over SSH: `php <site>/_includes/cli/admin_access.php grant owner@example.com --activate`
   - CLI-only guard (first statement); loads `bootstrap.php` as the cron runner does.
   - Refuses unless exactly one account has that email and its status is `pending` or `active` (never `suspended` / `deleted`).
   - Refuses an account with no password (`passwordHash IS NULL`, e.g. SSO-only) — re-authentication needs a password (§6.4).
   - `--activate` sets `status='active'` and `emailVerifiedAt = COALESCE(emailVerifiedAt, UTC_TIMESTAMP())` — justified because whoever has shell access to the server already controls the site.
   - Shows what it will do and asks the operator to type the email again (skipped with `--yes`).
   - Writes `roleID=1` and an audit row (`actorType='cli'`, `actorLabel='cli:'.get_current_user()`, action `admin.grant`) **in one transaction**.
   - Other sub-commands: `list` (Global Admins, emails partly masked), `revoke <email>` (refuses to remove the **last** Global Admin).
   - Exit codes: 0 done, 1 failed, 2 refused.
4. **No SSH?** phpMyAdmin (DreamHost panel) one-liner, documented with a warning to type the email carefully:
   `UPDATE tblUsers SET roleID = 1, status = 'active', emailVerifiedAt = COALESCE(emailVerifiedAt, UTC_TIMESTAMP()) WHERE email = 'owner@example.com' AND status IN ('pending','active') AND passwordHash IS NOT NULL;`
   The first time such an admin opens `/admin/`, the system writes an `admin.first_seen` audit row (`actorType='system'`) for any Global Admin with no `admin.grant` row, so the log is never silent about how someone became an admin.
5. Sign in → `/admin/` → confirm password → Settings → Email.

Later admins are granted from `/admin/admins/` (Phase 7) or the CLI.

### 6.3 Session and CSRF
- Reuses the existing session (`CUERCODE_SID`, HttpOnly, SameSite=Lax, Secure on HTTPS — `bootstrap.php:251-270`) and the database session in `tblUserSessions`.
- Every admin `<form>` carries `csrfField()`; every POST handler checks `validateCSRFToken()` **first** and fails closed if the function is missing (copy `accountPagePostCsrfValid()`, `account/index.php:148-161`). GET requests never change anything (no "delete?id=" links).
- Defence in depth: POST handlers also reject a request whose `Origin` header is present and is not this site's own origin.

### 6.4 Re-authentication ("confirm your password") for changes
- **Rule:** viewing admin pages needs only Global Admin status. **Any change** (any admin POST) also needs a fresh password confirmation.
- State in `$_SESSION['cuercode_admin_reauth']` = `['userID' => int, 'sessionHash' => hash('sha256', <tblUserSessions token>), 'confirmedAt' => int, 'lastUsedAt' => int]`. It is valid only for the same user **and** the same database session, and only while `now − lastUsedAt ≤ admin_reauth_idle_minutes` (default 15, allowed 5–60) **and** `now − confirmedAt ≤ admin_reauth_max_minutes` (default 60, allowed 15–480, never below the idle value). Each successful save refreshes `lastUsedAt`.
- Pure helper `adminReauthIsFresh(?array $state, int $userID, string $sessionHash, int $now, int $idleMinutes, int $maxMinutes): bool` (unit-tested with edge values).
- `/admin/reauth/` page: password field (`autocomplete="current-password"`), checked with `verifyPassword()`; on success `session_regenerate_id(true)`, store the state, audit `reauth.success`, redirect to a **safe admin path** (`adminSafeNextPath()`: must start with `/admin/`, no `//`, no `\`, no `:`, ≤ 512 chars). On failure: audit `reauth.failed` (never the password); after 5 failures in the same session → `logoutCurrentUser()` + audit `reauth.lockout`; failures also count toward the normal account lock-out via `recordFailedLogin()` (`fn_auth.php:754`).
- When a form page is opened without a fresh confirmation, it shows the values read-only with an "Unlock editing — confirm your password" button. If the confirmation runs out while a form is open, the POST is refused with "Please confirm your password again and re-enter your changes" (secret fields are not kept).
- Global Admins **must have a password** (enforced at grant time). Re-authentication with passkeys is later (#16).

### 6.5 Erasure and lock-out guards
- `eraseUserAccount()` (`fn_api_account.php:1586`) refuses (409, plain message) when the user is a Global Admin — they must be removed as admin first, and the last admin cannot be removed. One extra check at the top; covered by a test.
- Nobody can remove the last Global Admin (CLI and Phase 7 screen).

### 6.6 Saving settings safely (used by Phase 1C and Phase 7)
`adminSaveSetting(array $spec, mixed $newValue, array $admin, ?string $reason): array` in `fn_admin_settings.php`:
1. Load the current row (`settingID`, `settingType`, `isSensitive`, `isEditable`, `settingValue` raw).
2. Refuse when `isEditable = 0`, when the key is on the **locked list** (`billing_price_points_enabled`, `billing_discounts_enabled`, `billing_payg_enabled`, `dynamic_qr_enabled`, every `*_cache` key, `graph_token_cache`, `app_version`, `app_phase`, `api_version`, `encryption_algorithm`), or (Phase 1C) when the key is not in the registry. The Phase 7 generic editor also accepts other `isEditable=1`, non-secret, non-locked keys, validated by their `settingType`.
3. Validate with the registry rule (§7.2).
4. `getDB()->begin_transaction()`; `setConfig($key, $value, ['type' => <row settingType>, 'group' => .., 'isSensitive' => <registry>, 'description' => ..])` (the extra options matter only when the row is missing); write the audit row; commit — or roll back and report "not saved" if either fails.
5. Run the registry's `afterSave` action if any (clear `graph_token_cache`; clear `sso_<provider>_discovery_cache` and `_jwks_cache`).
6. Never put the old or new value of a sensitive setting in the result, the audit row, any log, or the page.

### 6.7 The audit trail
- `adminAuditWrite(array $entry): bool` inserts into `tblAdminAuditLog`; `adminAuditChanges(array $before, array $after, array $secretFields): array` is **pure** and builds `{"field": {"old": x, "new": y}}` for changed fields only, replacing secret fields with `{"secret": "set" | "replaced" | "cleared"}`. Over 60 KB → truncated with a marker.
- Written in the **same transaction** as the change, so a change never exists without its audit row.
- **When database upgrades are pending** (or `tblAdminAuditLog` does not exist yet): only `/admin/reauth/` and `/admin/upgrades/` may act — their audit rows fall back to `logActivity()` until the table exists. Every other admin page shows "Database upgrade needed" and **refuses to save**, so no change can ever happen without its audit row.
- IP from `getClientIP()` when loaded, else `REMOTE_ADDR`; user agent truncated to 500.
- Action names (use exactly these): `reauth.success`, `reauth.failed`, `reauth.lockout`, `admin.grant`, `admin.revoke`, `admin.first_seen`, `schema.migrate`, `setting.update`, `setting.secret_set`, `setting.secret_replace`, `setting.secret_clear`, `setting.test_graph`, `setting.test_email`, `plan.create`, `plan.update`, `plan.update_reduction` (with reason), `plan.new_version`, `plan.retire`, `plan.restore`, `plan.delete`, `price.create`, `price.update`, `price.retire`, `price.delete`, `entitlement.update`, `apikeys.resync`, `discount.create`, `discount.update`, `discount.disable`, `discount.eligibility_grant`, `discount.eligibility_revoke`, `customer.lookup`, `deal.create`, `deal.start`, `deal.update`, `deal.end`, `payg.pack_create`, `payg.pack_update`, `payg.cap_update`.

---

## 7. Screens and routes

### 7.1 Page list
All pages: `require bootstrap.php` → `require fn_admin.php` (+ page helpers) → `requireGlobalAdminPage()` **before any other work** → handle POST (CSRF → re-auth → validate → transaction + audit → redirect with a one-time flash message) → render with `_includes/header.php` / `footer.php`, `$pageNoIndex = true`, and an admin sub-menu partial `_includes/admin_nav.php` (pattern: `_includes/developers_nav.php`). Header user menu (`header.php:541-556`) gains an "Admin" item only for Global Admins.

| Route | Phase | What it does | Key validation / guards |
| --- | --- | --- | --- |
| `/admin/` | 1B | Overview: who you are, editing locked/unlocked (with time left), pending database upgrades, email status (switch, credentials Set/Not set, sender set, waiting/sent/given-up counts via `countEmailQueueRowsByStatus()`, warning when `countOverdueEmailQueueRows()` > 0 = "the cron job may not be running"), sign-in providers configured, the three charging switches shown **Off (locked)**, number of Global Admins | read-only |
| `/admin/reauth/` | 1B | Confirm password | §6.4 |
| `/admin/upgrades/` | 1B | Lists pending migrations in plain English, warns about changed files, "Apply N upgrades" | works even when `tblAdminAuditLog` does not exist yet (the gate needs only `tblUsers`); result recorded in `tblSchemaMigrations`, and an audit row once the table exists |
| `/admin/settings/` | 1C | Eight sections, one `<form>` each (§7.2) | registry-driven; locked keys read-only |
| `/admin/settings/` "Check Microsoft connection" | 1C | Clears `graph_token_cache`, calls `getGraphAccessToken()` (`fn_email.php:464`, works even while `email_enabled=0`), shows Connected / Could not connect (never the token or the error body) | POST + re-auth; audit `setting.test_graph` |
| `/admin/settings/` "Send me a test email" | 1C | `sendEmailNow()` to the **signed-in admin's own address only**, fixed subject/body | needs `email_enabled=1` (says so if off); audit `setting.test_email`; max 5 per 10 minutes per session |
| `/admin/plans/` | 2 | Plan list grouped by kind (standard / lifetime deals / credit packs), status (on sale, retired, draft), paying subscribers count, prices per currency with "missing $ / €" warnings | — |
| `/admin/plans/edit/?id=` and `/admin/plans/new/` | 2 | Name, description, sort order, kind (create only), key (create only), contact mode + URL, sale window, cap, price-promise mode/months, retire/restore/delete, "make a new version" | §4.3 rules; optimistic lock (§7.5) |
| `/admin/plans/prices/?id=` | 2 | Price points grouped by `priceGroupKey`; one amount box per offered currency; interval, interval count, valid from/until, default flag, retire | P1 freezing; money rules §8 |
| `/admin/pricing-preview/` | 2 | The public pricing page as it will look with price points, VAT wording and a currency switcher; "Preview — not public" banner | admin only; `noindex` |
| `/admin/features/` | 3 | Features × plans grid (checkboxes) and limits × plans grid (number + "Unlimited"), grouped by category; unknown keys listed; anonymous rate-limit cells read-only (F7); "update existing API keys" after rate-limit changes | P3 reduction rule; registry min/max |
| `/admin/discounts/` | 4 | List: name, code (or "automatic"), value, who can use it, dates (UK time), used / cap, status | — |
| `/admin/discounts/edit/?id=` and `/new/` | 4 | Create/edit; "code for one customer" wizard (random code, links the customer) | P2 freezing; §8 rules |
| `/admin/discounts/eligibility/?id=` | 4 | Approved customers for a discount (education/non-profit, single-customer codes): add by exact email, note, optional expiry, withdraw | audit each grant/withdraw |
| `/admin/discounts/check/` | 4 | "Would this code work?" — code (or automatic), customer email, plan, billing period, currency → yes/no with the reason and the resulting price | read-only dry run; same rule functions as checkout |
| `/admin/customers/?email=` | 4 | **Exact** email look-up only (no browsing/partial search): name, status, plan, discount approvals | audit `customer.lookup` |
| `/admin/lifetime-deals/` (+ `edit/`) | 5 | Deals: based on, price per currency, own limits (link to the grid column), window, cap, sold so far, start / end the deal | cap ≥ sold; window end > start |
| `/admin/payg/` | 6 | Packs (credits + price per currency), monthly money cap per currency, credit-usage limit on the PAYG plan | grants built from the form, never raw JSON |
| `/admin/admins/` | 7 | List Global Admins, grant to an existing active verified account with a password, revoke (never the last, never yourself if last) | re-auth + typed confirmation |
| `/admin/audit/` | 7 | Read-only change log with filters (who, what, entity, date range in UK time), paged | never shows secrets (none are stored) |
| `/admin/settings/other/` | 7 | Generic editor for every remaining `isEditable=1`, non-secret, non-locked setting, validated by `settingType`; risky keys (`debug_mode`, `api_cors_allowed_origins`, `session_lifetime`, …) need a typed reason | registry bounds where known |

### 7.2 Settings sections (Phase 1C) — registry entries
Registry function `adminSettingsRegistry(): array` (`_functions/fn_admin_settings.php`), one entry per field: `key`, `label`, `help` (plain English), `kind` (`secret` | `text` | `email` | `https_url` | `bool` | `int` | `decimal` | `choice` | `timezone` | `plan_key`), bounds/options/pattern, `group`/`type`/`description` (used only if the row is missing), optional `afterSave`, optional `confirmText` (asks for a tick before saving, e.g. turning email on).

1. **Email (Microsoft 365)** — `email_enabled` (confirm: "emails already waiting will start sending"); `graph_tenant_id` (secret; GUID **or** domain name); `graph_client_id` (secret; GUID); `graph_client_secret` (secret); `graph_sender_mailbox` (email); `email_from_name` (≤ 100 chars); `email_queue_autoprocess` (bool, help recommends Off + cron). `afterSave` on any `graph_*` change: clear `graph_token_cache`. Plus the two test buttons and the cron reminder.
2. **Email records** — the two retention settings, validated with `parseEmailQueueRetentionDays()` (1–3650); help: defaults 30 and 90, and `privacy.php` §7 must match (#38).
3. **Sign in with Microsoft / Google** — `sso_enabled`; per provider: `_enabled`, `_client_id` (secret), `_client_secret` (secret), `_discovery_url` (https only), `_scopes` (space-separated, must include `openid`, characters `[a-z0-9.:/_-]`). Shows the exact callback URL from `ssoRedirectUri()` to paste into Entra / Google Cloud. `afterSave` on discovery URL: clear that provider's discovery and JWKS caches. SIGNula is not shown (inert until upstream #88).
4. **Bot protection** — `bot_protection_provider` (none / turnstile / recaptcha / hcaptcha) and the six keys (all secrets). Help: "Stored now; forms start using them with #114."
5. **PayPal** — `paypal_mode` (sandbox / live), three secrets. Help: "Stored safely now; used when checkout is built (#26). Charging stays off."
6. **Prices, VAT & refunds** — `billing_vat_mode` (prices include VAT / prices exclude VAT / not VAT-registered — no VAT wording), `billing_vat_rate_percent` (0–100, 2 dp), `billing_refund_window_days` (0–365; help: the Terms page must say the same, #38). Read-only: the three charging switches, **Off (locked)**.
7. **Free trials** — `billing_trials_enabled`, `billing_trial_plan_key` (active standard paid plans only), `billing_trial_days` (1–90), `billing_trial_requires_card`. Help: "Saved now; nothing changes for visitors until the trial sign-up flow is built."
8. **Admin security** — `admin_reauth_idle_minutes` (5–60), `admin_reauth_max_minutes` (15–480, ≥ idle), `admin_display_timezone` (any identifier in `DateTimeZone::listIdentifiers()`).

**Secret fields (every `kind = secret`):** `<input type="password" autocomplete="new-password" spellcheck="false" autocapitalize="off" data-lpignore="true" data-1p-ignore>`, **always rendered empty**; status text "Set — last changed 5 Oct 2026, 14:02 (UK time)" or "Not set"; **an empty box means "keep what is stored"**; a separate "Remove this secret" checkbox clears it (confirm). Server: trim; reject control characters and line breaks; max 2,048 characters; never echo the submitted value back, even on a validation error. A "show what I typed" toggle (JavaScript only) affects only the box being typed in.

**VAT wording on the public page (in Phase 1C, so the switch is real immediately):** `pricing/index.php` shows "Prices include VAT" / "Prices exclude VAT" / nothing next to the price block, from a pure helper `pricingVatNote(string $mode): string` in `fn_pricing.php` (unknown value → "Prices include VAT", the seeded default).

### 7.3 API endpoints and OpenAPI
- **No new API endpoints** and **no `openapi.json` change** for the admin screens. A static test asserts no route in `public_html/api/v1/index.php` and no path in `openapi.json` starts with `/admin`.
- **Phase 3 (accessibility gates) does change API behaviour:** when a plan lacks the matching feature, the generate/preview/bulk endpoints that accept `pdf_layout` (and any endpoint returning the accessibility fields) answer **402 `FEATURE_NOT_AVAILABLE`** with `upgrade_url` (existing `requireFeatureOr402()` shape, `fn_features.php:710-723`). `openapi.json` must add the 402 response to those operations (today only `/barcode`, `/barcode/preview`, `/save`, `/keys` document 402) and say which `pdf_layout` values are plan-dependent. Because the keys are seeded on for every plan, live behaviour does not change until the owner moves them. The existing OpenAPI and error-catalogue drift tests must stay green.

### 7.4 Security headers, CSP and caching for `/admin/`
`public_html/admin/.htaccess` (mod_headers, like the site file): `X-Frame-Options "DENY"`, `Cache-Control "no-store"`, `X-Robots-Tag "noindex, nofollow"`, and the **site CSP from `public_html/.htaccess:106` with `frame-ancestors 'none'`** — scripts only from `'self'` and `https://cdn.jsdelivr.net` (no new script sources). `requireGlobalAdminPage()` also sends `Cache-Control: no-store` from PHP in case `.htaccess` headers are off. The admin path is **not** listed in `robots.txt` (that would advertise it).

### 7.5 Shared UI rules for every admin page
- **Accessibility (WCAG 2.1 AA):** one `h1`, logical headings; every field has a visible `<label for>`; groups (e.g. the £/$/€ boxes of one price) use `<fieldset><legend>`; required fields say "(required)" in text; help text tied with `aria-describedby`. On a failed save: an error summary at the top (`role="alert"`, receives focus) with links to each field, plus per-field messages and `aria-invalid="true"`. Success messages use `role="status"`. Buttons say exactly what they do ("Save email settings", "Retire the Pro plan"). The features grid **is a data table** (allowed — the no-`<table>` rule is for layout): `<caption>`, `<th scope="col|row">`, each checkbox with a hidden label such as "Braille caption on Pro"; on small screens it scrolls inside `role="region" aria-label="…" tabindex="0"`. Visible focus. The editing time limit is stated on the page ("Editing unlocked until 14:32").
- **Risky actions without JavaScript:** a server-side confirmation step (a summary page or an "I understand…" tick box checked by the server), not only a JavaScript `confirm()`.
- **Dark mode / colourblind mode:** use Bootstrap variables and existing classes only; no hard-coded colours. Every status is **text + icon** ("✓ Set", "✗ Not set", "On", "Off (locked)") — never colour alone. Check in light, dark and each colourblind palette from the header menu.
- **JavaScript:** one local file `public_html/assets/js/cuercode-admin.js` (via `$pageJS`), no inline scripts, no new CDN. It only enhances: show/hide a typed secret, "Unlimited" toggles the number box, "end date = start + N days" helper, "about £x a month" helper, Bootstrap confirmation dialogs, "you have unsaved changes" warning.
- **Lost-update protection:** every edit form carries the row's `updatedAt` as `expected_updated_at`; the `UPDATE ... WHERE id = ? AND updatedAt = ?` must change exactly one row, otherwise "Someone else changed this while you were editing — reload".
- **Output escaping:** `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` on everything, including admin-typed plan names/descriptions shown on the public page. No HTML is accepted in names or descriptions.

---

## 8. Money, VAT and date rules

### 8.1 Money (new `_functions/fn_money.php`, Phase 2 — pure, fully unit-tested)
- **Currency facts** (not commercial rules): `moneyCurrencyInfo(): array` — ISO 4217 code → symbol, minor units, English name, for at least GBP (£, 2), USD ($, 2), EUR (€, 2) plus one zero-decimal currency (JPY, 0) to prove the code handles it. Which currencies are **offered** is the `billing_currencies` setting.
- `moneyParseToMinor(string $input, string $currency): ?int` — trims; accepts only digits with an optional point and up to *minor-units* decimals (`^\d{1,7}(\.\d{1,2})?$` for 2-decimal currencies); rejects commas, signs, spaces inside, exponents, more decimals than allowed, and anything above 99,999.99 (or the zero-decimal equivalent). Returns pence/cents as an integer.
- `moneyMinorToDecimalString(int $minor, string $currency): string` — for binding to `DECIMAL(10,2)` columns **as a string** (`'s'` bind type), never as a float.
- `moneyFormat(int $minor, string $currency): string` — "£6.99", "$6.99", "€6.99" (English formatting; translations later, #34).
- `moneyPercentToBasisPoints(string $input): ?int` — "25" → 2500, "12.5" → 1250; 0 < value ≤ 100, at most 2 decimals.
- `moneyApplyPercentMinor(int $minor, int $basisPoints): int` — discount = round-half-up(`minor × bp / 10000`) computed with integers (`intdiv(minor * bp + 5000, 10000)` for non-negative values); result = `minor − discount`, never below 0. One rounding, once, at the end.
- `moneyApplyFixedMinor(int $minor, int $discountMinor): int` — never below 0; only when currencies match (§8.2).
- `moneyPerMonthFromYearly(int $yearMinor): int` — round-half-up `/12`, display only ("about £4.17 a month").
- Overflow: max amount 9,999,999 minor × 10,000 bp fits easily in a 64-bit integer; document the 64-bit assumption.
- `pricingApplyDiscount()` (floats) stays for the existing display tests but gets a comment pointing checkout at `fn_money.php`.

### 8.2 Discount arithmetic rules (Phase 4; also used by `/admin/discounts/check/`)
- Percent discounts apply to any currency. Fixed discounts apply **only** to purchases in the discount's own `currency`.
- One discount per purchase when `billing_discount_combining=0`: work out each applicable discount's saving in minor units and keep the biggest (tie → lowest `discountID`).
- A discount never makes a price negative; a 100% discount gives 0.

### 8.3 VAT
- `billing_vat_mode='inclusive'` (seed): the amounts typed in **already include VAT**; the page says "Prices include VAT". `exclusive`: amounts are before VAT; page says "Prices exclude VAT". `none`: no wording (not VAT-registered).
- Splitting VAT out of a price (`VAT = gross × rate / (100 + rate)`, half-up, in minor units) happens only at checkout / on receipts (later). EU digital-sales VAT by customer country is an accountant + checkout question (later; already advised in HANDOFF).
- Note for the owner: Pricing_Strategy.md calls the drafts "ex-VAT", but the decision of 2026-10-05 makes them **VAT-inclusive** (so £6.99 is what the customer pays).

### 8.4 Dates and time zones
- **Stored in UTC, always.** The database session is UTC (`db_connect.php:193`) and every validity check compares with `NOW()` in SQL (`fn_features.php:1086-1087`, `:1201-1202`), so stored values must be UTC.
- **Shown and typed in UK time** (`admin_display_timezone`, default `Europe/London`), always labelled "(UK time)". Do **not** use or change `site_timezone` (it sets PHP's global zone in `bootstrap.php:281-294`).
- Helpers in `fn_admin.php` (pure): `adminLocalToUtc(string $local, string $tz, bool $endOfDay): ?string` and `adminUtcToLocal(string $utc, string $tz): string`, using `DateTimeImmutable` + `DateTimeZone` explicitly (never `date()` / `strtotime()` without a zone).
- Inputs: `<input type="date">` (+ optional `<input type="time">`). A date with no time means **00:00:00** for a start and **23:59:59** for an end, in UK time, then converted. Examples to test: end 31 Dec 2026 → `2026-12-31 23:59:59` UTC (GMT); end 30 Jun 2027 → `2027-06-30 22:59:59` UTC (BST).
- Clock-change edge cases: a time that does not exist (spring forward) or happens twice (autumn) → the form refuses it with a plain message and suggests the next valid time (detected by converting there and back and comparing).
- "60 days" helper: end = start + 60 days in UK time, end of that day.
- The comparison is inclusive at both ends (`validFrom <= NOW()` and `validUntil >= NOW()`, as the existing queries do).

---

## 9. Phased build order

Each phase = one builder, then an independent check (Opus), then the Codex review loop (stand-in Opus reviewer if Codex is out of allowance — recorded as such). After each phase the lead pushes to `work-in-progress`, updates the issue, `.claude/`, `.OpenAI/` and HANDOFF §0. Every phase ends with: `php -l` on every changed PHP file, the full test suite green, gitleaks clean, and the docs listed in §13.

Real-database checks for every phase run with the pattern of `tests/integration/email_queue_real_mysql.php` (throwaway container, database name must start with `cuercode_it_`), on **MySQL 8.0 or 8.4 and MariaDB 10.11** (as #122 did).

### Phase 1A — Database upgrade mechanism (builder: **Opus**; it changes how every future schema change ships)
**Build:** §5.1 runner + CLI; `tblSchemaMigrations`; migrations **0001** (the #70 columns moved out of `install.sql`) and **0002** (`tblAdminAuditLog` + the 1A settings rows of §5.4); installer integration; the `install.sql` / test edits of §5.6; DEV_NOTES "Changing the database from now on" runbook.
**Acceptance:**
- Fresh database: installer → `install.sql` + `seed_data.sql` + 0001 + 0002 all succeed; `tblSchemaMigrations` lists both; tables/columns/indexes/foreign keys identical to what the old `install.sql` produced plus the new pieces.
- `install.sql` can be run **twice** on the same database with no error (#113 item 5 / #70 acceptance closed).
- Running the migrations a second time changes nothing and reports "already up to date".
- A database built by the **old** `install.sql` (with the two ALTERs already applied) upgrades cleanly: 0001 skips every step, 0002 applies.
- A database missing a baseline table gets it created; one missing a baseline **column** is refused with a clear list and exit code 2.
- Owner-edited setting values are never overwritten by a re-run.
- Two runs at the same time: the second is refused ("busy"), nothing is half-applied.
**Tests:** `tests/test_migrations.php` (pure: definition validation incl. every refused SQL word, ordering, pending list, checksum-changed warning, `install.sql` column-name parser, CLI argument parsing and exit codes, step-needed decisions with a stand-in "exists" checker); updated `test_dynamic_redirect_s0.php` / `test_pricing_flex.php`; `tests/integration/migrations_real_mysql.php` covering every acceptance bullet; CI syntax-checks the integration file.

### Phase 1B — Global Admin access (builder: **Opus**; security core)
**Build:** `fn_admin.php` (§6.1, §6.3–6.5, §6.7, date helpers §8.4); `_includes/cli/admin_access.php` (§6.2); `/admin/` overview, `/admin/reauth/`, `/admin/upgrades/`; `public_html/admin/.htaccess`; `_includes/admin_nav.php`; `cuercode-admin.js` skeleton; the "Admin" item in the header menu; the erasure guard in `eraseUserAccount()`.
**Acceptance:**
- Not signed in → `/admin/…` redirects to `/login/?next=/admin/…`; signed in but not a Global Admin (including `roleID=2`, `pending`, `suspended`, unverified) → 404, identical to a missing page.
- Global Admin → overview loads; every admin response has `Cache-Control: no-store`, frame-blocking and `noindex`.
- No change is possible without a fresh password confirmation; the confirmation is tied to the database session (copying the session cookie to a new login does not carry it over) and expires on the idle and absolute limits.
- 5 wrong confirmations → signed out; each attempt audited; the password never appears anywhere.
- CLI: `grant --activate` on a pending account makes it an active, verified Global Admin with an audit row; refuses deleted/suspended/password-less accounts, unknown or ambiguous emails, and revoking the last admin; refuses to run from the web.
- A Global Admin made by phpMyAdmin gets one `admin.first_seen` row on first visit.
- A Global Admin cannot erase their own account through `/account/`.
- `/admin/upgrades/` applies pending migrations for a Global Admin even before `tblAdminAuditLog` exists.
**Tests:** `tests/test_admin_access.php` — pure `adminAccessDecision()` for every role/status/verification combination, `adminReauthIsFresh()` at exact boundaries (idle −1 s / +1 s, max, wrong user, wrong session hash), `adminSafeNextPath()` against `//evil`, `/\evil`, `/admin:x`, `https://…`, over-long; `adminAuditChanges()` never contains a secret; CLI argument parsing; **static guards**: (a) every `public_html/admin/**/index.php` calls `requireGlobalAdminPage(` before any other application call, (b) `roleID` is written only in `fn_admin.php` and `admin_access.php`, (c) no `/admin` route in the API router or `openapi.json`, (d) no inline `<script>` in admin pages. Integration: CLI grant/revoke, audit rows, erasure guard. Browser (headless Chromium, `/opt/pw-browsers/...` per HANDOFF): the three access cases, keyboard-only re-auth, JavaScript off.

### Phase 1C — Settings editor (builder: **Sonnet**; Opus verifier) — *unblocks email*
**Build:** `fn_admin_settings.php` (registry §7.2, validators, `adminSaveSetting()` §6.6, test-connection and test-email actions); `/admin/settings/`; `pricingVatNote()` + the pricing-page VAT line; `#[\SensitiveParameter]` on `setConfig()`'s `$value`, `encryptValue()`'s `$plaintext`, `adminSaveSetting()`'s `$newValue` and the CLI/admin password parameters (`verifyPassword()`'s `$plainPassword` too).
**Acceptance:**
- The owner can enter the Microsoft 365 email secrets, check the connection, turn email on, and send themselves a test email — **without any secret ever appearing on screen, in the page source, in the audit log or in any log**.
- Saving a secret stores ciphertext (decrypts to what was typed) with a fresh `encryptionSalt`; empty box keeps the old value; "Remove" clears it; each is audited as set/replaced/cleared.
- Retention periods accept 1–3650 only; VAT mode changes the wording on `/pricing` straight away; trial settings save (with the honest "nothing visible yet" note).
- Locked keys (`billing_*_enabled`, `dynamic_qr_enabled`, caches, `isEditable=0`) cannot be changed even with a hand-crafted POST.
- Changing Graph credentials clears the token cache; changing a discovery URL clears that provider's discovery and JWKS caches.
**Tests:** `tests/test_admin_settings.php` — every validator (GUID-or-domain tenant, GUID client ID, https-only URLs, scopes containing `openid`, emails, ints at bounds 0/1/3650/3651, decimals, timezone list, choice lists, secret control characters and length 2048/2049), registry completeness (every key exists in `seed_data.sql` or migration 0002 with the same type and sensitivity), locked-key refusal, `pricingVatNote()` for all modes + unknown; the rendered settings HTML for a stored secret contains neither the plaintext nor the ciphertext (render the page in a child PHP process with stand-in functions). Integration: save → ciphertext in DB → `getConfig()` returns plaintext; an exception forced inside `setConfig()` leaves no secret in `tblErrorLog` or the PHP error log. Browser: keyboard-only pass through all eight sections, error summary focus, dark + colourblind palettes, JavaScript off.

### Phase 2 — Plans, prices and currencies (builder: **Opus**; money rules)
**Build:** migration **0003** (§5.3 plan / price-point / subscription columns; §5.4 settings; §5.5 seeds); `fn_money.php` (§8.1); `fn_admin_plans.php`; `/admin/plans/`, `edit/`, `new/`, `prices/`, `/admin/pricing-preview/`; `adminMirrorLegacyPlanPrices()` (§4.7); pricing page: show only `planKind='standard'` on the grid, "Contact us" card for `ctaMode='contact'` ("From £99/month"), hide price figures when `pricing_show_prices=0`, show the refund promise line once charging is on (not yet). Extend `tests/test_pricing.php`'s plan-key literal guard to `enterprise`, `payg`, `pro_lifetime_founders`, and add a **project-wide guard**: no seeded paid-plan key (`pro`, `business`, `enterprise`, `payg`, `pro_lifetime_founders` — `anonymous`/`free` are excluded because they are the code's documented fallbacks and also ordinary words such as the API auth method `'anonymous'`) as a string literal, and no seeded price (`6.99`, `49.99`, `24.99`, `199.99`, `119.99`, `4.99`, `19.99`, `59.99`) as a number or string literal in any PHP file under `web/CueRCode.net/` outside `_libraries/` and `_includes/sql/migrations/`. Use `token_get_all()` and inspect only string/number tokens, so comments and docblocks (e.g. `fn_pricing.php:495`, `fn_features.php:357`) do not trip it. This enforces "never hard-code a commercial rule".
**Acceptance:**
- Owner can create a plan, set £/$/€ monthly and yearly prices, mark the recommended one, set sale dates and a cap, choose the price promise, retire and restore a plan, and see "missing $ / €" warnings.
- The P1–P6 rules hold, including against hand-crafted POSTs (frozen price point fields, fallback plans not retirable, `planKey` immutable, delete only when unused).
- Prices round-trip exactly (typed "6.99" → stored `6.99` → shown "£6.99"); no float anywhere in the save path.
- Two admins editing the same plan → the second save is refused, nothing lost silently.
- `/pricing` shows the Enterprise "Contact us" card; with `pricing_show_prices=1` the grid shows £6.99 / £49.99 and £24.99 / £199.99; the three charging switches are untouched.
- The preview page renders price points in each currency and is not reachable by non-admins.
**Tests:** `tests/test_money.php` (parse/format/percent/fixed/per-month for GBP, USD, EUR, JPY; rejects `6.999`, `-1`, `1e3`, `6,99`, ` 6 .99`, `100000.00`, empty; half-up cases such as 25% of 699 → 524, 12.5% of 4999 → 4374); `tests/test_admin_plans.php` (plan-key rule `^[a-z][a-z0-9_]{1,31}$`, reduction detection between two plan JSONs, freezing decisions, new-version key suggestions, mirror selection logic); pricing-page tests for contact card / hide prices / kind filter; integration for 0003 seeds and the "only if unchanged" legacy update.

### Phase 3 — Feature placement + accessibility gates + SSO label (builder: **Sonnet**; Opus verifier)
**Build:** `entitlementRegistry()` (§4.6) with the six accessibility keys (`available`) and `sso` relabelled "Organisation-wide single sign-on" (`coming_soon`, #21; task 7q); migration **0004** (§5.5); `/admin/features/` grid; reduction rule P3 with "apply to existing customers" (typed reason) or "make a new version"; `resyncApiKeyLimitsForPlan(int $planID)` (handles a fallback plan = users with no active subscription) behind an "update existing API keys now" button; generator/API gates for the six keys (each place `pdf_layout`, the accessible preset, the contrast report fields and the print-size guide are produced — builder to list them first; known: `fn_api_generate.php:448`, `:684`, `:1398`, `fn_api_bulk.php`, `fn_api_qrcodes.php`, `fn_qr_accessible_pdf.php`, `public_html/index.php` + `cuercode-generator.js`), UI shows disabled controls with the upgrade hint (`aria-disabled`, never hidden — house rule); `openapi.json` 402 additions (§7.3).
**Acceptance:** grid saves exactly the intended JSON (unknown keys preserved); limits validated by the registry; reductions on plans with paying subscribers blocked unless confirmed; anonymous rate-limit cells read-only; with the seeded values **every current request behaves exactly as before** (regression tests); switching `a11y_braille_caption` off on a plan makes the API answer 402 for `pdf_layout='accessible_caption'` for that plan and the UI show it disabled; the contrast safety refusal still runs for every plan; OpenAPI drift and error-catalogue tests green.
**Tests:** registry shape (every key has kind, label, help, status; every seeded plan key appears), JSON builder from form input, reduction detector, re-sync SQL (integration), gate tests for each output (on/off × anonymous/free/paid), static guard that `pricingAllowedDisplayKeys()` and `entitlementRegistry()` agree.

### Phase 4 — Discounts (builder: **Opus**; security + money)
**Build:** migration **0005** (§5.2 eligibility table, §5.3 discount columns, `billing_discount_combining`, the two seeds); `fn_admin_discounts.php` (code rules: upper-case `A-Z0-9-`, 4–32 chars, unique ignoring case — the column collation is case-insensitive; random single-customer codes: 10 characters from an alphabet without look-alikes `ABCDEFGHJKMNPQRSTUVWXYZ23456789`, `random_int()`); pure `discountAppliesTo(array $discount, array $context): array{applies: bool, reason: string}` (dates, active, cap, scope, currency, eligibility, one-per-customer) and `discountChooseBest(array $candidates, int $priceMinor): ?array`; **update `resolveDiscountForCheckout()`** so it fails closed on `eligibilityMode='approved_list'` without a live approval (not revoked, not expired), honours `scopeJSON` and `currency`, and keeps every existing behaviour; screens `/admin/discounts/…` and `/admin/customers/`.
**Acceptance:** owner can create a general code, a limited-use code, a one-customer code (wizard), edit dates in UK time, disable a code, approve/withdraw education customers; launch discount ends at `2026-12-31 23:59:59` UTC; P2 freezing holds; the checker gives the right answer and reason for every case; a restricted code is **never** accepted for another customer, even with the kill switch turned on in a test database; customer look-up is exact-email only and audited.
**Tests:** `tests/test_discounts.php` — every `discountAppliesTo()` branch, best-saving choice incl. fixed-vs-percent in different currencies, code normalisation/uniqueness, random code alphabet and length, UK-time end-of-day conversion in GMT and BST, P2 freezing decisions; integration: `resolveDiscountForCheckout()` against real rows (approved / not approved / revoked / expired approval; fixed in wrong currency; out-of-scope interval) with `billing_discounts_enabled=1` **in the throwaway database only**.

### Phase 5 — Lifetime deals (builder: **Sonnet**; Opus verifier)
**Build:** migration **0006** (Founders seed, `billing_lifetime_default_window_days`); `/admin/lifetime-deals/` (create from a base plan = copy features/limits, set own limits via the grid column, price per currency, start the deal with a window pre-filled to 60 days, cap, end now); sold-count query (§4.5).
**Acceptance:** Founders Lifetime appears as a draft based on Pro with its own `dynamic_codes` limit and £119.99; starting it sets UK-time dates correctly; cap cannot go below sold; ended or draft deals never appear on the public grid; nothing can be bought (charging off).
**Tests:** copy-from-base logic, window helper (incl. a clock-change week), cap rules, sold-count SQL (integration with fake purchase rows).

### Phase 6 — Pay-as-you-go packs and cap (builder: **Sonnet**; Opus verifier)
**Build:** migration **0007** (`payg` plan, three packs, money cap setting); `/admin/payg/` (packs: credits + price per currency grouped by `priceGroupKey`; money cap per currency per Q6; the PAYG plan's credit-usage limit shown with its meaning).
**Acceptance:** packs and cap editable, grants JSON always built by the server as `{"credits":{"qr_generations":N}}` with N a whole number 1–1,000,000; frozen once sold (P1); `consumeCreditsOr402()` behaviour unchanged.
**Tests:** grants builder, cap validation per currency (money rules), pack grouping, regression on `test_features.php` credit tests.

### Phase 7 — Admin housekeeping (builder: **Sonnet**; Opus verifier)
**Build:** `/admin/admins/`, `/admin/audit/`, `/admin/settings/other/` (§7.1).
**Acceptance:** grant/revoke with the last-admin rule; audit log filterable and paged, read-only; generic editor refuses locked/secret/non-editable keys and requires a reason for risky keys.
**Tests:** admin-count rules, audit filter query building (prepared statements only), generic validation by `settingType`.

*Phases 2–7 can be re-ordered by the lead (e.g. 7 earlier) without design changes; 1A → 1B → 1C must stay in that order.*

---

## 10. Security checklist for the independent reviewer

Tick each with evidence (file:line, test name, or a reproduced request).

**Privilege escalation**
- [ ] S1 Every admin page calls `requireGlobalAdminPage()` before reading input or touching data (static test + read every page).
- [ ] S2 The admin decision reads `roleID`, `status`, `emailVerifiedAt` fresh from the database on each request; nothing about admin status is cached in `$_SESSION`.
- [ ] S3 `roleID` is written only by `fn_admin.php` and `admin_access.php`; no API or account endpoint accepts a `roleID` / `role` field (grep the request-body whitelists in `fn_api_account.php`, `fn_api_accounts.php`, `fn_auth.php`, `fn_oauth.php`).
- [ ] S3b SSO cannot be used to take over an admin: auto-linking is refused when a local account already has the email (`fn_oauth.php:1848-1860`); changes still need the **password** re-confirmation.
- [ ] S3c The last Global Admin cannot be revoked or erased; a Global Admin cannot erase their own account.
- [ ] S3d No admin action is reachable with an API key (no `/api/v1/admin*`; admin pages ignore `X-API-Key`).

**Wrong-object access (IDOR) and mass assignment**
- [ ] S5 Every `id` from a form or URL is an integer, exists, and belongs to the right parent (a price point to *this* plan, an approval to *this* discount) before any change.
- [ ] S6 Handlers copy only whitelisted fields; unknown POST fields are ignored; `planKey`, `planKind`, `redemptionCount`, `createdByUserID`, `approvedByUserID` can never be set from a form.
- [ ] S7 Customer look-up is exact email only, admin-only, audited; no list/browse of users.

**CSRF, clickjacking, redirects, caching**
- [ ] S8 Every POST checks `validateCSRFToken()` first and fails closed; no GET changes state; the `Origin` check rejects foreign origins.
- [ ] S9 `/admin/` responses carry `X-Frame-Options: DENY`, CSP `frame-ancestors 'none'`, `Cache-Control: no-store`, `X-Robots-Tag: noindex`.
- [ ] S10 `adminSafeNextPath()` only allows `/admin/…` paths (tests for `//`, `/\`, `:`, absolute URLs, encoded variants).
- [ ] S11 Re-authentication: tied to user + database session; idle and absolute limits enforced; `session_regenerate_id(true)` on success; 5 failures → signed out; counts toward account lock-out.

**Secret handling**
- [ ] S12 Secret inputs are always empty when rendered; no secret plaintext or ciphertext appears in any HTML (including `value=` attributes, data attributes, comments, flash messages, error messages).
- [ ] S13 Secrets never reach `tblAdminAuditLog`, `tblActivityLog`, `tblErrorLog`, the PHP error log or CLI output — only "set / replaced / cleared".
- [ ] S14 `#[\SensitiveParameter]` on `setConfig()`'s `$value`, `encryptValue()`'s `$plaintext`, `verifyPassword()`'s `$plainPassword` and every new function that takes a secret or password; tested by forcing an exception and reading the stored trace.
- [ ] S15 Secrets are only ever sent by POST over HTTPS; never in a URL or GET.
- [ ] S16 Locked keys (§6.6) and `isEditable=0` rows cannot be changed by any crafted request; the three `billing_*_enabled` switches stay `0` (charging stays off).
- [ ] S17 Changing Graph or SSO settings clears the matching caches so old credentials stop being used.
- [ ] S18 The "test email" can only go to the signed-in admin's own address and is rate-limited.

**Money and rounding**
- [ ] S19 No float in any money save or calculation path (`fn_money.php` integers; DECIMAL bound as strings); half-up rounding applied once; never negative.
- [ ] S20 Currency rules: each price point has an offered currency; fixed discounts only apply in their own currency; zero-decimal currencies handled.
- [ ] S21 Freezing rules P1–P6 enforced server-side (not only by hiding form fields).
- [ ] S22 Restricted discounts (`approved_list`) can never apply to another customer — `resolveDiscountForCheckout()` and `discountAppliesTo()` both fail closed; revoked / expired approvals refused.
- [ ] S23 Caps: discount cap ≥ used; deal cap ≥ sold; the checkout brief requires the F-023 named-lock pattern for redemptions and sales.
- [ ] S24 No hard-coded commercial values in code (project-wide literal guard from Phase 2 passes).

**Dates and time zones**
- [ ] S25 Stored values are UTC; inputs/outputs converted with explicit `DateTimeZone`; end dates are end-of-day UK time; GMT and BST cases tested; non-existent / doubled clock-change times refused.
- [ ] S26 No new code compares a PHP `date()` value with a database DATETIME without converting both to UTC.

**Database upgrades**
- [ ] S27 Migration files contain only static SQL; step types whitelisted; destructive words refused; one statement per step.
- [ ] S28 Named lock prevents two upgrades at once; partial runs are safe to repeat; owner-edited values never overwritten.
- [ ] S29 `migrate.php` and `admin_access.php` refuse to run from the web; `_includes/.htaccess` denies web access.

**Output and input hygiene**
- [ ] S30 All output escaped; admin-typed plan names/descriptions shown on the public page are plain text; length limits match the columns.
- [ ] S31 Plan JSON is always built by the server from validated values (`json_encode(..., JSON_THROW_ON_ERROR)`), never accepted raw from a form.
- [ ] S32 Audit rows written in the same transaction as the change; no code path updates or deletes audit rows.

---

## 11. Open questions for the owner (ask as proper questions; recommended option first)

**Q1 (fact check) — Is there already a CueRCode database on the live server that must be kept?**
- **A (recommended): No — start fresh.** No deploy has run yet (all runs skipped, `SFTP_ENABLED` unset) and the live installer test (#13) has not happened, so there is probably nothing to keep. The upgrade tool then only needs to handle today's design.
- B: Yes — keep it. Phase 1A adds a one-off "catch-up" step for columns added before today (we would need to know roughly when it was installed).

**Q2 — What should the public pricing page show until charging starts?**
- **A (recommended): Your new draft prices in £ (Pro £6.99/£49.99, Business £24.99/£199.99, Enterprise "Contact us — from £99/month"), with "Prices include VAT" and the buy buttons still saying "Coming soon".** It is honest, matches what you will charge, and you can change any price at any time. Today the page shows the old placeholder £4.99, which is wrong.
- B: Hide all price figures ("Pricing coming soon") until charging starts — avoids anyone holding you to a draft price, but tells visitors less.
- C: Leave the old placeholder prices — not recommended (they are out of date).

**Q3 — When should the paid accessibility extras (Braille caption, tactile frame, Avery label sheets) stop being free?**
- **A (recommended): Build the switches now but keep all accessibility outputs free for everyone until charging starts; on launch day you move the three extras to the paid plans in the feature grid (a few clicks).** Nobody can buy a plan yet, so charging-only features now would simply take them away from people.
- B: Move them to paid plans immediately — free and signed-out users lose them now, with no way to get them back until charging starts.

**Q4 — Can discounts be combined (for example education 50% plus launch 25%)?**
- **A (recommended): No — one discount per purchase; if several apply, the customer automatically gets the biggest saving.** Simple to explain, and it prevents accidental very deep discounts. Stored as a setting, so you can change it later.
- B: Allow combining (50% then 25% = 62.5% off in total).

**Q5 — If you change a plan that people already pay for, what should happen?**
- **A (recommended): Changes that give customers more apply to everyone at once; changes that take something away from paying customers are blocked unless you either confirm "apply to existing customers too" with a reason, or choose "make a new version for new customers" (existing customers keep the old version).** Protects customers (and you, legally) while keeping full flexibility.
- B: Every change applies to everyone immediately — simplest, but it is easy to take something away from paying customers by mistake.
- C: Any change to a plan with paying customers always creates a new version — safest, but the plan list fills up with versions.

**Q6 — What does the £20 monthly cap on pay-as-you-go mean?**
- **A (recommended): The most a customer can spend buying credit packs in one calendar month, set separately per currency (£20 now; you set the $ and € figures).** This matches a money amount and stops surprise spending. (The program today has a different kind of cap — on credits *used* per month — which we keep only as an optional extra limit.)
- B: A limit on how many credits can be used per month (what the existing code counts) — does not match a £ figure.

Record each answer in HANDOFF §0 and `LAUNCH_DECISIONS.md` §B straight away. Q2's answer sets the seed of `pricing_show_prices`; Q4's sets `billing_discount_combining`; Q5's sets the P3 rule; Q6's sets the Phase 6 cap design; Q1's decides whether Phase 1A needs the catch-up step.

---

## 12. Not in scope (later tasks)
- **Taking money:** PayPal checkout, subscriptions, webhooks, refunds processing, receipts/invoices (#26); switching on the `billing_*` switches; the sale-cap and redemption named locks at checkout; VAT split per country (EU OSS) and the accountant check.
- **Trial flow:** "Start a free trial" button, trial expiry job, trial emails.
- **Public display** of lifetime deals and credit packs, and multi-currency on the live pricing page (arrive with checkout, when `billing_price_points_enabled` is switched on).
- **Price-change notices** to customers whose price promise is ending (email + scheduled job).
- Organisations, roles and **organisation-wide SSO** (#21, #131).
- Rest of #31: error-log and activity-log viewers, user management (suspend, comp/manual plan grants, custom per-customer Enterprise plans), QR-type manager, API-key admin, system health page.
- Passkeys for admins and re-authentication by passkey (#16); an IP allow-list for `/admin/`.
- Customer-facing "apply for the education discount" form; bulk code generation / CSV export; discount usage reports.
- Translations of the admin area (#34); `terms.php` / `privacy.php` wording (#38) — the screens only note where the wording must match.
- Bot-protection wiring in the forms (#114).
- Follow-ups raised by this plan: F1 (`logActivity()` never records the user), F7 (anonymous rate limits in two places), F10 (alpha deploy folder), F11 (MySQL 5.7 vs the JSON default in `install.sql`), the `auth_creds.php` template (#113).

---

## 13. Documentation and issues to update (per phase, by the lead)
- **DEV_NOTES.md:** "Changing the database from now on" (1A); "First Global Admin" + "Admin area" runbook incl. phpMyAdmin fallback (1B); "Entering secrets" replacing every "fill via admin (not built)" note (1C); plan/price/discount/deal/PAYG runbooks (2–6).
- **README.md** (admin area, upgrade command), **CHANGELOG.md**, **PROJECT_STATUS.md**, **SECURITY.md** (admin threat model, re-auth, audit log is append-only by convention, F1/F3 findings), **LAUNCH_DECISIONS.md** (§C "enter in the admin settings", §E1 install.sql re-run item done, the six answers), **Pricing_Strategy.md** (note: prices are VAT-inclusive; numbers now live in the admin screens, not the document), `tests/README.md` (new test files + integration scripts).
- **openapi.json** only in Phase 3 (§7.3).
- `.claude/HANDOFF.md` §0 task 7n row (phase status), `.claude/` memory, `.OpenAI/` context.
- Issues: close #113 item 5 part when 1A lands (comment on #113, #70); update #31 (settings editor + access done), #53 stage 7, #25, #125, #131/7q, #55/#19/#20/#29 ("credentials can now be entered at /admin/settings/"), #26 (PayPal secrets storable), #114 (hCaptcha rows + F1), and open the follow-ups in §12.
