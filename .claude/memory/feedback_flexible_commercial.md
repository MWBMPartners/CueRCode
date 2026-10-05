---
name: Flexibility in everything commercial — Global Admin configurable, never hard-coded
description: All tiers, prices, discounts, trials, lifetime deals, PAYG, VAT display and feature placement must be admin-editable data (2026-10-05)
type: feedback
---

Every tier and pricing mechanism must be highly flexible and configurable by a Global Admin: plans and their features/limits, prices in any currency and billing period, VAT display, discount codes (general, limited-use, single-customer, dated), lifetime deals (price, plan, own limits, time/quantity windows, repeatable e.g. Black Friday), trials, pay-as-you-go packs and caps, price promises (while subscribed / set period), and which plan gets which feature (incl. the accessibility free/paid split).

**Why:** Owner, 2026-10-05: "Our plans now may not be plans in 12 months/5 years etc time, so we need flexibility in everything." Every pricing answer that day added "but make it adjustable".

**How to apply:** Never hard-code a commercial rule, price, limit or plan name in code. Store it as data the admin screens can edit, with today's decisions as seed defaults. When proposing options to the owner, assume "and make it configurable" unless told otherwise. Design for plans being added, retired, renamed and re-priced over time (existing subscribers keep what they bought unless the price-promise settings say otherwise).
