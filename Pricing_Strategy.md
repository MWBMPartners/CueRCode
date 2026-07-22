<!--
    File: /Pricing_Strategy.md
    Copyright: 2025-2026 MWBM Partners Ltd
-->

# CueRCode — Launch Pricing & Packaging Strategy

> ## ⚠️ DRAFT — indicative pricing, owner sign-off required
> All prices in this document are **recommendations only**. Nothing here is final until the owner
> signs off (see Section 7). Competitor prices were researched on **2026-07-22** and drift
> constantly — re-verify before any public price is published.

| | |
| --- | --- |
| **Document** | `Pricing_Strategy.md` (repo root) |
| **Date** | 2026-07-22 |
| **Status** | DRAFT for owner review |
| **Currency** | GBP primary (multi-currency supported per price point — see §5/§6) |
| **Grounding** | `_includes/sql/seed_data.sql:465-495` (4 seeded plans), `_includes/sql/install.sql:725-885` (tblSubscriptionPlans/tblSubscriptions/tblPayments), `_functions/fn_features.php` (gating engine), `_functions/fn_pricing.php` + `/pricing/` (#56), `FEATURES.md` (FG register), `PROJECT_ROADMAP.md` "Premium Tier Design" (:293-335), scratchpad `fable_analysis.md` §A2/§C |

---

## 1. Executive summary

- **Freemium, not free-trial, as the primary model.** Anonymous static QR generation stays fully free and generous (all 26 types, full customisation, logos) — this is the acquisition funnel and matches the current live behaviour (`seed_data.sql:469-474`). Money buys **dynamism, analytics, volume, automation, and org features** — exactly where every competitor gates value.
- **Keep the four seeded planKeys** (`anonymous`/`free`/`pro`/`business`) and **add two**: `enterprise` (contact-us, one SQL INSERT per the `install.sql:748-755` runbook) and `payg` (credit packs via the Section-C `tblPlanPricePoints`/`tblCreditLedger` delta).
- **Recommended headline prices (GBP, ex-VAT, DRAFT):** Pro **£6.99/mo** or **£49.99/yr** (≈£4.17/mo, ~40% annual saving); Business **£24.99/mo** or **£199.99/yr** (≈£16.67/mo, ~33% saving); Enterprise from ~£99/mo, quoted. These undercut the market's entry cluster (≈£4-10/mo) while carrying far larger dynamic-QR allowances (100 vs Uniqode's 3 / QRTiger's 12 at entry).
- **Differentiate on what nobody else has**: accessibility outputs (WCAG presets, Braille caption, tactile-frame, Avery label PDFs — FG-033 BUILT) **kept free at every tier** as a trust/ethics signal; native emoji logos; dual-function/enterprise QR + ProjectEPass (Enterprise anchor); ALL barcode symbologies through the same API; GS1 Digital Link readiness ahead of the Jan-2027 1D→GS1 sunrise (FG-009 BUILT); API-first parity; no-lock-in ethos (data export is already built, FG-026).
- **Launch offers, all data-driven**: a sitewide 25%-off-for-12-months launch discount and a capped Founder Lifetime deal (£119.99 one-off, Pro-class) — both expressible as `tblDiscounts`/`tblPlanPricePoints` rows with zero code change once the Section-C schema delta (D-7) ships.
- **Gating is already enforced technically**: `fn_features.php` `featureEnabled()`/`limitFor()` fail-closed generic lookups, `requireFeatureOr402()` returning 402 + `upgrade_url` (seeded `billing_upgrade_url='/pricing'`), rate-limit sync onto `tblAPIKeys`, `past_due` auto-downgrade to `free`. New tiers/keys are seed edits, not deploys.
- **Sequencing caveat (honest):** the Pro tier's anchor value — dynamic QR (E1a/G-007), scan analytics (E11), expiry (FG-005), password protection (FG-004) — and the PayPal checkout (#26/G-002) are **not built yet**. Do not take money before a "minimum sellable Pro" exists (owner decision, §7).

---

## 2. Competitive landscape

Researched **2026-07-22** via web search. Prices are as published/reported on that date, mostly **USD billed annually** (≈£ conversions at $1 ≈ £0.78, indicative only). Direct fetches of several pricing pages were blocked (HTTP 403 via the research proxy); those entries are marked **est.** with the basis stated. **Prices drift — re-verify at launch.**

| Competitor | Free tier | Entry paid ≈£/mo | What entry unlocks | Higher tiers | Lifetime/one-off? |
| --- | --- | --- | --- | --- | --- |
| **qr-code-generator.com** (Bitly co.) | Limited; dynamic features trial-based; static basic | **est.** ~£10 (Starter ~US$12.50/mo annual — long-standing price; 2026 page renders EUR via JS and could not be fetched 2026-07-22) | Dynamic QR, analytics | Advanced ~US$24.99, Professional ~US$49.99, Enterprise custom (est.) | No |
| **Uniqode** (ex-Beaconstac) | None — 14-day trial only | ~£4 (Starter US$5/mo, **annual-only billing**) — 3 dynamic codes, 25k scans, 30-day analytics | Dynamic QR + basic analytics | Lite $15 (50 codes, bulk), Pro $49 (250 codes, API, GPS), Plus $99 (500 codes, 3 seats), Business+ custom (SSO, white-label) | No |
| **QR Tiger** | 3 dynamic codes capped at 500 scans each + unlimited static | ~£5.50 (Starter US$7/mo — 12 dynamic codes) | More dynamic codes | Advanced $16/mo annual ($26 monthly; unlimited dynamic + bulk), Premium $37, Professional $89 (1,200 codes, API, +1 seat) | No |
| **Flowcode** | 2 codes, ~500 scans | ~£4 (Pro US$5/mo annual) | Dynamic QR basics | Pro Plus $25, Growth $250/mo (500 codes, CRM integrations, template locking), Enterprise custom (custom domains) | No |
| **Scanova** | 14-day trial only | ~£4-7 (Basic US$5 / Lite US$9 — 3/10 dynamic codes) | Dynamic QR, no ads | Standard $35 (50 codes), Pro $75 (500 codes), Enterprise quote | **Yes** — "Ultra Lite" US$150 one-time for **1** lifetime dynamic QR |
| **QR Code Monkey** | Unlimited static, no signup, logos, vector export (PNG/SVG/PDF/EPS), no expiry | n/a (paid sibling "QRCode Studio" for dynamic) | — | — | No |
| **Bitly** | Handful of QR codes bundled with links | ~£8 (Core US$10/mo — 5 QR codes, 100 links) | QR bundled with link/landing-page platform | Growth $29 (10 QR), Premium $199 (200 QR), Enterprise; monthly billing +20-30% | No |
| **Canva** | QR generation free (static; Canva branding by default; logo embed needs Pro) | ~£8.33-13 (Pro £100/yr or £13/mo) — QR is a bundled feature, not the product | Logo embed, brand kit | Teams/Enterprise | No |
| **Adobe Express** | Free static QR, colours + logos, never expires | QR itself stays free; Express Premium is a design-suite upsell | — | — | No |
| **QRCodeChimp** | Free tier | ~£5.50 (Starter US$6.99/mo annual — 50 dynamic codes) | Dynamic + folders | Pro $13.99 (300 codes), Ultima $49.99 | No |
| **ME-QR** | Unlimited codes, **ad-supported** scan interstitials | **est.** — page blocked 2026-07-22; Premium removes ads, annual saves ~$51/yr vs monthly | Ad removal, tracking | — | Markets "unlimited lifetime" codes (ad-funded) |

### Takeaways

1. **The market gates exactly five things:** dynamic-QR count, scan-analytics depth/retention, bulk generation, API access, and team seats/SSO/white-label. Static generation is a race to free (Monkey, Canva, Adobe, ME-QR) — CueRCode must not try to charge for it, and the seeded `anonymous` plan already gets this right.
2. **Entry pricing clusters at ≈£4-10/mo billed annually**, but the allowances are tiny (Uniqode 3 codes, QRTiger 12, Bitly 5). CueRCode Pro at £49.99/yr with **100 dynamic codes + 90-day analytics + 5 API keys + webhooks** (`seed_data.sql:485-488`) is dramatically better value at the same price point — a deliberate share-taking posture.
3. **There is a chasm between hobbyist and team pricing** ($49→$99→$250/mo). CueRCode Business at ≈£16.67-24.99/mo with orgs + SSO + 2,000/batch bulk sits squarely in that gap — the single biggest positioning opportunity.
4. **Free tiers are widely booby-trapped** (scan caps, expiring trials, ad interstitials, annual-only billing). An honest, generous free tier with clearly published limits is itself a differentiator and feeds the no-lock-in ethos (self-service data export already shipped — FG-026).
5. **Lifetime deals are rare among leaders** (only Scanova's $150-for-one-code) — a *capped* Founder Lifetime at £119.99 for a Pro-class allowance is safe against leader comparisons, generates launch cash, and its cost exposure is bounded by capping `dynamic_codes` and redemption count (§5.3).
6. **Nobody sells accessibility, emoji logos, barcodes-in-the-same-API, or app-verified dual-function QR.** These are CueRCode's whitespace: FG-033 (BUILT), D-4 emoji, D-6 barcodes (re-scoping FG-016), D-10 dual-function + ProjectEPass.

*Search sources:* [Uniqode pricing roundups](https://lifetimeqrcodes.com/uniqode-pricing), [G2 Uniqode](https://www.g2.com/products/uniqode/pricing), [QR Tiger pricing page/teardowns](https://www.qrcode-tiger.com/payment), [Flowcode reviews](https://tekpon.com/software/flowcode/reviews/), [Scanova pricing](https://scanova.io/pricing/) + [helpdesk](https://support.scanova.io/hc/en-us/articles/38942962402585-Scanova-Plans), [Bitly pricing breakdowns](https://replug.io/blog/bitly-pricing), [QR Code Monkey reviews](https://doitqr.com/en/qr-code-monkey-review), [Canva UK pricing](https://www.photonpay.com/uk/blog/article/canva-pro-pricing) + [Canva QR](https://www.canva.com/qr-code-generator/), [Adobe Express QR help](https://helpx.adobe.com/express/web/add-images-and-visuals/stickers-and-qr-codes/generate-qr-code.html), [QRCodeChimp pricing](https://www.qrcodechimp.com/pricing), [ME-QR pricing](https://me-qr.com/pricing), [qr-code-generator.com pricing](https://www.qr-code-generator.com/pricing/).

---

## 3. Recommended tier ladder

All prices **GBP, DRAFT, ex-VAT** (VAT display treatment is an owner decision, §7). featuresJSON/limitsJSON keys marked **(new)** do not exist in `seed_data.sql` yet and are proposed extensions; everything else is a live seeded key. Remember the engine is fail-closed (`install.sql:733-736`): a new key is deny/0 everywhere until seeded, so rollout is opt-in per plan with zero code risk.

### 3.1 Anonymous — £0 (no account)

- **Positioning:** "The generous front door." Full static QR generation with zero friction — the acquisition funnel (`seed_data.sql:469-470` comment says exactly this).
- **Target user:** anyone landing from search; competitor of QR Code Monkey/Canva/Adobe free tools.
- **Features:** all 26 QR types, full customisation, custom uploaded logos (`custom_logos` **(new)** = true — deliberately free, where Canva paywalls it), emoji logos (`emoji_logos` **(new)** = true, once D-4 ships), accessibility presets + Braille/tactile/Avery PDF layouts (FG-033 — **free at every tier**, see §3.7), barcode generation (`barcode_generation` **(new)** = true once D-6 ships — static single barcodes free, same ethos as static QR).
- **Limits (current seed, keep):** `saved_codes` 0, `bulk_batch_size` 25, `dynamic_codes` 0, `api_keys` 0, `analytics_days` 0, rate 10/min, 100/day.

### 3.2 Free — £0 (registered account)

- **Positioning:** "Persistence + a taste of dynamism." Registration is the conversion event; the plan exists to create logged-in users who hit limits.
- **Target user:** individuals who want to save/re-download codes; developers evaluating the API.
- **Features:** everything Anonymous has, plus saved-codes library, one API key, and a 3-code dynamic allowance (once E1a ships) with scan **totals only** (no dashboard).
- **Limits (current seed `seed_data.sql:478-481`, plus proposals):** `saved_codes` 25, `bulk_batch_size` 50, `dynamic_codes` 3, `api_keys` 1, `analytics_days` 0 (totals shown on the code list, not a dashboard), rate 30/min, 2,000/day; **(new)** `saved_logos` 3, `saved_presets` 5, `team_seats` 1.

### 3.3 Pro — £6.99/mo · £49.99/yr (≈£4.17/mo, save ~40%) — DRAFT

- **Positioning:** "Dynamic QR, analytics and automation for one person, at the market's entry price with 8-30× the allowance."
- **Target user:** freelancers, marketers, small shops, indie developers; the head-to-head against Uniqode Starter/Lite, QRTiger Starter/Advanced, Flowcode Pro, Bitly Core.
- **Features (seeded, `seed_data.sql:485-488`):** `expiry_scheduling` ✅, `password_protected_qr` ✅, `webhooks` ✅, `csv_export` ✅; plus **(new)** `analytics` ✅ (full dashboard), `barcode_generation` ✅ with bulk, `emoji_logos` ✅, `custom_logos` ✅.
- **Limits (seeded, keep):** `saved_codes` 500, `bulk_batch_size` 500, `dynamic_codes` 100, `api_keys` 5, `analytics_days` 90, rate 120/min, 20,000/day; **(new)** `saved_logos` 25, `saved_presets` 50, `team_seats` 1.
- **Price defence:** monthly £6.99 sits between QRTiger Starter ($7) and Bitly Core ($10); annual £49.99 (≈$64) is at Uniqode Starter/Flowcode Pro money for a Lite/Advanced-class allowance. The seeded placeholder (£4.99/£49.99) survives as the **annual** effective price; the monthly point is raised to £6.99 to make annual the obvious choice (standard market pattern — Bitly/QRTiger monthly premiums are +30-60%).

### 3.4 Business — £24.99/mo · £199.99/yr (≈£16.67/mo, save ~33%) — DRAFT

- **Positioning:** "Team features at a fraction of the mid-market chasm" (competitors jump $49→$99→$250 for seats/SSO).
- **Target user:** SMEs, agencies, schools/venues; multiple people managing a shared QR estate.
- **Features (seeded, `seed_data.sql:492-495`):** everything in Pro, plus `sso` ✅ (E2 — M365/Google built), `organisations` ✅ (pending #21 UI); plus **(new)** `dual_function_qr` ✅ (D-10, once built — app-vs-web routing for sanctioned apps), `priority_support` ✅.
- **Limits (seeded, keep):** `saved_codes` −1 (unlimited, fair use), `bulk_batch_size` 2,000, `dynamic_codes` 1,000, `api_keys` 20, `analytics_days` 1,095 (3 years), rate 600/min, 100,000/day; **(new)** `saved_logos` −1, `saved_presets` −1 (org-shared), `team_seats` 10 (seat add-ons later).
- **Price defence:** at ≈£16.67/mo annual, Business undercuts Uniqode Plus ($99), QRTiger Professional ($89) and Flowcode Growth ($250) by 4-15× while offering SSO — which those vendors reserve for custom-priced tiers. The seeded £19.99 monthly placeholder is nudged to £24.99 to widen the annual incentive; annual £199.99 is unchanged from seed.

### 3.5 Enterprise — "Contact us" (guide floor ~£99/mo) — DRAFT

- **Positioning:** "Verified-app QR infrastructure" — the ProjectEPass/dual-function story, GS1 Digital Link at volume ahead of the Jan-2027 sunrise, white-label, custom limits, SLA.
- **Target user:** event operators (ProjectEPass check-in), retail/supply-chain (GS1), franchises needing white-label/custom domains (FG-014).
- **Features:** everything in Business, plus **(new)** `white_label` ✅ (FG-014, when built), `dual_function_qr` ✅ with a sanctioned-app registry allowance, custom `analytics_days`, custom rate limits, contractual SLA + named support.
- **Limits:** all −1 or per-contract.
- **Mechanics:** exactly the `install.sql:748-755` runbook — one INSERT with `planKey='enterprise'`, `sortOrder=4`; already proven live with a synthetic 5th plan (FEATURES.md FG-020). Show the card on `/pricing/` with a "Contact us" CTA instead of a checkout (fn_pricing.php renders from DB; a `priceMonthly=0` + description convention or a small display flag handles the CTA).

### 3.6 PAYG — £0 base + credit packs — DRAFT (post-launch option)

- **Positioning:** "No subscription, pay for what you use" — captures one-off campaign users (wedding, single event, print run) who will never subscribe.
- **Target user:** occasional users; also the overage mechanism for Free users who briefly need Pro-scale bulk.
- **Mechanics (Section-C delta, `fable_analysis.md` §C — requires D-7):** a `payg` plan whose limitsJSON allowances are 0 but whose price points are `one_off` credit packs with `grantsJSON`; consumption via `tblCreditLedger`; meter key `qr_generations` (covers dynamic-code activation-months and bulk items beyond the free allowance).
- **Suggested packs (DRAFT):** 100 credits £4.99 · 500 credits £19.99 · 2,000 credits £59.99.
- **PAYG-capped:** `limitsJSON` gains `credit_spend_cap_monthly` (e.g. 20 = £20/mo) so a PAYG user can never out-spend a Pro/Business subscription in a month — at the cap, the UI/API returns the 402 + `upgrade_url` upsell. Read by the same generic `limitFor()` — zero engine change.

### 3.7 Deliberately NOT gated (strategy calls)

- **Accessibility outputs stay free at all tiers** — WCAG contrast validation, accessible preset, print-size calculator, Braille-caption/tactile/Avery PDF layouts (FG-033, BUILT). Paywalling accessibility is bad ethics and worse optics; as a free capability it is a unique trust marker no competitor can match quickly. (Bulk Avery sheet tiling still implicitly scales with `bulk_batch_size`, so volume remains monetised.)
- **Custom + emoji logos free at all tiers** — directly contrasts Canva (logo embed = Pro) and converts design-conscious users.
- **All 26 QR types + full customisation free** — already live behaviour; changing it would burn goodwill for negligible revenue.
- **Key-design note:** where a numeric limit exists, prefer the limit over a twin boolean — `dynamic_codes > 0` already implies dynamic-QR access and `analytics_days > 0` implies analytics, so `dynamic_qr`/`analytics` booleans are optional sugar for UI copy, not enforcement (the engine treats absent keys as false/0 anyway, `install.sql:733-736`). Recommend seeding **limits only** where possible to keep one source of truth.

---

## 4. Feature-to-tier gating matrix (seed blueprint)

Rows use the **actual key names** to be encoded in `featuresJSON`/`limitsJSON`. ✅ = true, — = false/0, numbers = limit values, −1 = unlimited. Keys marked ★ are **new** (not in the current seed; fail-closed until added). This table IS the seed-data spec for the next `seed_data.sql` revision.

| Key (type) | Anonymous | Free | Pro | Business | Enterprise | PAYG |
| --- | --- | --- | --- | --- | --- | --- |
| `expiry_scheduling` (feat) | — | — | ✅ | ✅ | ✅ | — |
| `password_protected_qr` (feat) | — | — | ✅ | ✅ | ✅ | — |
| `webhooks` (feat) | — | — | ✅ | ✅ | ✅ | — |
| `csv_export` (feat) | — | — | ✅ | ✅ | ✅ | — |
| `organisations` (feat) | — | — | — | ✅ | ✅ | — |
| `sso` (feat) | — | — | — | ✅ | ✅ | — |
| ★ `custom_logos` (feat) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| ★ `emoji_logos` (feat) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| ★ `barcode_generation` (feat) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| ★ `dual_function_qr` (feat) | — | — | — | ✅ | ✅ | — |
| ★ `white_label` (feat) | — | — | — | — | ✅ | — |
| ★ `priority_support` (feat) | — | — | — | ✅ | ✅ | — |
| `saved_codes` (limit) | 0 | 25 | 500 | −1 | −1 | 25 |
| `dynamic_codes` (limit) | 0 | 3 | 100 | 1,000 | per-contract (−1) | credit-metered |
| `bulk_batch_size` (limit) | 25 | 50 | 500 | 2,000 | per-contract | credit-metered |
| `api_keys` (limit) | 0 | 1 | 5 | 20 | per-contract | 1 |
| `analytics_days` (limit) | 0 | 0 (totals only) | 90 | 1,095 | per-contract | 30 |
| `api_rate_limit_per_min` (limit) | 10 | 30 | 120 | 600 | per-contract | 30 |
| `api_rate_limit_per_day` (limit) | 100 | 2,000 | 20,000 | 100,000 | per-contract | 2,000 |
| ★ `saved_logos` (limit) | 0 | 3 | 25 | −1 | −1 | 3 |
| ★ `saved_presets` (limit) | 0 | 5 | 50 | −1 | −1 | 5 |
| ★ `team_seats` (limit) | 0 | 1 | 1 | 10 | −1 | 1 |
| ★ `credit_spend_cap_monthly` (limit) | 0 | 0 | 0 | 0 | 0 | 20 (=£20) |

Rollout safety: every ★ key defaults to denied/0 on all plans until seeded (`fn_features.php` generic fail-closed lookups; regression-guarded by `tests/test_features.php`), so this matrix can land incrementally as each feature ships — matrix-first, feature-later is safe.

---

## 5. Flexible pricing structures (Section-C schema in action)

Everything below rides on the **additive, inert-by-default** schema delta specified in `fable_analysis.md` §C (D-7): `tblPlanPricePoints`, `tblDiscounts` + `tblDiscountRedemptions`, `tblCreditLedger`, `tblOneOffPurchases`, plus snapshot columns on `tblSubscriptions` and `billing_*` kill-switches in `tblSettings`. Until those rows exist, `fn_pricing.php` keeps rendering the legacy `priceMonthly`/`priceYearly` columns — no behaviour change.

### 5.1 Annual discount (standard)

Two price-point rows per paid plan; the yearly row IS the discount (no coupon needed):

| planKey | priceKey | billingInterval | amount | currency | notes |
| --- | --- | --- | --- | --- | --- |
| pro | `monthly` | monthly | 6.99 | GBP | isDefault=0 |
| pro | `yearly` | yearly | 49.99 | GBP | isDefault=1 → "from £4.17/mo" |
| business | `monthly` | monthly | 24.99 | GBP | |
| business | `yearly` | yearly | 199.99 | GBP | isDefault=1 |

### 5.2 Launch / limited-time discount

One **automatic** (code=NULL) sitewide row in `tblDiscounts` — no voucher entry needed at checkout:

| field | value |
| --- | --- |
| code | NULL (automatic) |
| name | "Launch offer — 25% off for 12 months" |
| discountType / value | percent / 25.00 |
| appliesPlanID / appliesPriceKey | NULL / NULL (all paid plans) |
| durationType / durationMonths | repeating / 12 |
| validFrom / validUntil | 2026-0x-xx (launch) / 2026-12-31 |
| maxRedemptions | NULL (uncapped) — or cap if preferred |

Redemption snapshot lands on `tblSubscriptions.discountID`; the counter increment MUST reuse the F-023 advisory-lock pattern (TOCTOU class already proven exploitable in this codebase — `fable_analysis.md` §C.2).

### 5.3 Lifetime deal ("Founder Lifetime")

A dedicated **plan** (so its allowances are independently tunable) + a `lifetime` price point:

- `tblSubscriptionPlans`: `planKey='pro_lifetime'`, featuresJSON = Pro's, limitsJSON = Pro's **except** `dynamic_codes: 25` and `analytics_days: 90` (bounds the perpetual hosting cost).
- `tblPlanPricePoints`: (`pro_lifetime`, `lifetime_founder`, billingInterval=`lifetime`, amount **119.99**, GBP, validUntil = launch + 60 days).
- Purchase → `tblOneOffPurchases` row + a `tblSubscriptions` row with `currentPeriodEnd = NULL`, `status='active'` (NULL period already representable — `install.sql` tblSubscriptions).
- **Cap enforcement:** limit to e.g. **500 redemptions** — the price-point table has no counter, so either pair it with an automatic `tblDiscounts` row (value 0%) purely for its `maxRedemptions` counter, or monitor `COUNT(*)` on `tblOneOffPurchases` and flip `isActive=0`. Flag: schema nicety `maxSales` on price points is a one-column follow-up if lifetime deals recur.
- Market context: Scanova charges US$150 one-time for **one** code; £119.99 for a 25-dynamic-code Pro-class lifetime is a strong AppSumo-style launch asset with bounded exposure.

### 5.4 Education / non-profit discount

`tblDiscounts` row: code `EDU-NFP`, percent 50, `durationType='forever'`, appliesPlanID NULL, `maxRedemptions` NULL, one-per-user via the `UNIQUE(discountID,userID)` redemption constraint. Verification (edu domain? manual review?) is policy, not schema — owner decision (§7).

### 5.5 PAYG credit packs

- `payg` plan (limits per §4) + `one_off` price points with `grantsJSON`:

| priceKey | amount | grantsJSON |
| --- | --- | --- |
| `credits_100` | £4.99 | `{"credits": {"qr_generations": 100}}` |
| `credits_500` | £19.99 | `{"credits": {"qr_generations": 500}}` |
| `credits_2000` | £59.99 | `{"credits": {"qr_generations": 2000}}` |

- Purchase webhook → `tblOneOffPurchases` row → `tblCreditLedger` grant: `(userID, meterKey='qr_generations', delta=+100, reason='purchase', refPurchaseID=…)`. Each gated act inserts `delta=−1` (advisory-locked); balance = `SUM(delta)`, request-cached like `planByKey()`.

### 5.6 PAYG with a cap

Same plan; `limitsJSON.credit_spend_cap_monthly = 20` (≈ Pro-annual-equivalent spend). `consumeCreditsOr402()` checks month-to-date consumption against the cap and returns the standard 402 + `upgrade_url` at the ceiling — turning heavy PAYG users into subscribers instead of surprise bills. Read by the existing generic `limitFor()`; zero engine change.

---

## 6. Rollout & positioning

- **Launch vs steady-state:** launch with the §5.2 automatic 25%×12-months discount (and optionally the §5.3 Founder Lifetime, 60-day window) rather than lower list prices — list prices stay honest, the discount visibly expires, and no repricing exercise is needed later. Steady-state = the §3 list prices.
- **Grandfathering:** built into the schema — retiring a plan is `isActive=0` (existing subscribers keep resolving their plan row; it just hides from new signups — `install.sql:771`), and `tblSubscriptions.pricePointID`/`discountID` snapshot exactly what each customer bought. Public commitment: "your price never rises while your subscription stays active."
- **Currency:** GBP-only at launch (single `currency` column today, `install.sql:768`). Multi-currency needs **no schema change** once Section C lands — currency is per price-point row, so USD/EUR price points can be added per plan later (recommend fixed per-currency price points, not FX conversion).
- **Freemium vs trial:** freemium is primary (Anonymous→Free funnel is already live). Optionally add a card-free 14-day Pro trial using the existing `status='trialing'` subscription state (already in the ENUM and honoured by `resolveActiveSubscription()`); recommend deferring trials until after launch metrics establish the Free→Pro conversion baseline.
- **Technical enforcement (already exists):** every gate goes through `fn_features.php` — `featureEnabled()`/`limitFor()` (generic, fail-closed), `requireFeatureOr402()` emitting **402 + `upgrade_url`** (seeded `billing_upgrade_url='/pricing'`, `seed_data.sql:126-127`); wired today on `bulk_batch_size` (fn_api_bulk.php), `saved_codes` (fn_api_qrcodes.php, F-023 advisory-locked), `api_keys` (fn_api_keys.php); plan changes sync rate limits onto `tblAPIKeys` via `syncApiKeyLimitsForUser()`; `past_due` auto-downgrades gating to `free` (`billing_past_due_downgrade_plan_key`). UI mirrors API gates with visible-but-disabled controls + upgrade tooltips (`aria-disabled`, never silently hidden — PROJECT_ROADMAP.md enforcement design item 3c). New gates for the ★ keys are one `featureEnabled()`/`limitFor()` call at the API handler **and** the matching UI state — always both.
- **Sequencing (honest launch order):** (1) ship D-7 schema delta inert; (2) build E1a dynamic QR + minimum analytics (the Pro anchor — G-007 owner gate); (3) PayPal checkout (#26/G-002); (4) flip `billing_*` kill-switches, publish prices, enable paid CTAs on `/pricing/` (currently "Coming soon" by design, #56). Selling Pro before dynamic QR exists would price against competitors' weakest tier with our weakest feature set — don't.
- **Positioning one-liners per audience:** individuals — "Everything free tools do, plus the pro features, for the price of a coffee"; teams — "SSO and real team management without the £100/mo mid-market cliff"; enterprise/venues — "QR codes your app can trust — verified dual-function codes, GS1-ready before the 2027 sunrise"; everyone — "Accessible by default. Your data is always yours."

---

## 7. Owner decisions required before launch

| # | Decision | Recommendation on the table | Blocking? |
| --- | --- | --- | --- |
| 1 | **Final price points** (Pro £6.99/£49.99, Business £24.99/£199.99, Enterprise floor ~£99) | §3 draft numbers | Yes — seed + `/pricing/` copy |
| 2 | **Currency set at launch** (GBP-only vs +USD/EUR price points) | GBP-only; add USD/EUR post-launch via price points | Yes |
| 3 | **VAT display** (ex-VAT vs inc-VAT pricing page; UK VAT registration status) | Display inc-VAT for consumers if registered | Yes |
| 4 | **Launch discount** — run the automatic 25%×12mo offer? End date? | Yes, until 2026-12-31 | Yes |
| 5 | **Founder Lifetime deal** — run it? £119.99? Cap (500)? Window (60 days)? | Yes, capped | No (can add later) |
| 6 | **Edu/non-profit policy** — 50% forever? Verification method? | Yes, manual verification initially | No |
| 7 | **PAYG at launch or later** + pack sizes + monthly spend cap value | Post-launch; packs per §5.5, cap £20 | No |
| 8 | **Trial stance** — freemium-only vs card-free 14-day Pro trial | Freemium-only at launch | No |
| 9 | **Minimum sellable Pro** — gate paid launch on E1a dynamic QR + analytics MVP? | Yes — do not charge before dynamic QR ships | Yes |
| 10 | **PayPal go-live** (#26, gate G-002 — business credentials, webhook endpoint) | Required for any paid tier | Yes |
| 11 | **Accessibility outputs free at all tiers** — confirm the §3.7 stance | Confirm | Yes (seed design) |
| 12 | **Barcode tier placement** — free at Anonymous (recommended) vs Free-and-up | Free at Anonymous | No (D-6 not shipped) |
| 13 | **Refund/cancellation terms** — money-back window (30 days?), proration, UK consumer-rights (14-day cooling-off for consumers) in terms.php | 30-day money-back, cancel-anytime, no proration refunds after | Yes — legal copy |
| 14 | **Grandfathering commitment** — publish "price never rises while subscribed"? | Yes | No |
| 15 | **GHAS cost** — GitHub Advanced Security spend for the repo (secret scanning/push protection are settings; paid GHAS features are budget) | Enable free-tier settings now; assess paid GHAS | No |
| 16 | **Enterprise sales motion** — who answers "Contact us"? SLA template? | Owner inbox initially | No |

### Owner decisions (for pre-launch tracker)

- Final GBP price points: Pro £6.99/mo · £49.99/yr; Business £24.99/mo · £199.99/yr; Enterprise floor ~£99/mo (all DRAFT)
- Currency set at launch: GBP-only vs +USD/EUR price points
- VAT display and registration treatment on /pricing/
- Launch discount: automatic 25% off ×12 months, end 2026-12-31 — go/no-go
- Founder Lifetime deal: £119.99 one-off, 500-redemption cap, 60-day window — go/no-go
- Edu/non-profit discount: 50% forever + verification method
- PAYG: at launch or post-launch; pack prices (£4.99/£19.99/£59.99); monthly spend cap (£20)
- Trial stance: freemium-only vs card-free 14-day Pro trial
- Minimum sellable Pro: gate paid launch on E1a dynamic QR + analytics MVP
- PayPal go-live (#26 / G-002): credentials + webhook endpoint
- Confirm accessibility outputs stay free at all tiers
- Barcode generation tier placement (Anonymous vs Free-and-up)
- Refund/cancellation terms (30-day money-back? cooling-off?) → terms.php
- Grandfathering commitment wording
- GHAS cost decision (free settings now; paid features budget)
- Enterprise "Contact us" owner + SLA template

---

*End of document — DRAFT, owner sign-off required before any price is published.*
