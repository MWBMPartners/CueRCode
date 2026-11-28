# 7n Phase 2 independent review (c487dab..07a6f55) - running report

Reviewer: Opus (independent, did not write the code). Started 2026-11-27. Not committed.

## Status: complete (all six areas). Ranked: F1 Medium, F5 Medium, F3 Low, F2 Low, F4 Low, F6 Low.
Containers rv7n-mysql80 / rv7n-maria106 and the PHP test server are stopped; dangling volumes 1 before, 1 after. Repo untouched except this file (HEAD moved to 1201cff during the review by another session; the range reviewed is unchanged).

## Findings (ranked)

### F1 (Medium) Installer replay that fails once is never retried; the site ends up without Enterprise and with old prices, and the installer locks itself as "successful"
- Where: `public_html/install/index.php` Step 5a2 (`if ($seedLoadedNow)`, ~line 555) and the error message there; `_functions/fn_migrations.php` `migrationDbReplayDataSteps()`.
- Why: the replay runs only when seed_data.sql was loaded *in the same request*. If the replay fails (for example "busy" because `migrate.php` is running at that moment), seed_data.sql has already committed (with its marker). On the reload the message asks for, the seed is "complete", so `$seedLoadedNow` stays false, the replay is skipped, Step 5b says "Already up to date", and the installer writes its lock file.
- Reproduced (MySQL 8.0.36, real installer over PHP's built-in server on a copy of the site): empty db `cuercode_it_inst1` -> `migrate.php --apply` (0001-0004 applied) -> held the upgrade lock from another session -> POST /install/: "Seed data loaded", "could not be repeated ... reload this page - every step is safe to repeat", not locked. After the lock was released, POST /install/ again: "Seed data skipped", "Already up to date", "Installation Successful!", lock file written. Database: plans anonymous/free/pro 4.99/business 19.99, no enterprise, 0 price points.
- Consequence: silently different site from a normal install (no Enterprise card, placeholder prices), with the installer now disabled; the on-screen promise is untrue.
- Suggested fix: take the upgrade lock BEFORE loading seed_data.sql and run the seed and the replay's DML in one transaction (all InnoDB DML), so a replay failure rolls the seed back and a retry starts from "none"; or persist a "replay owed" marker and replay whenever it is present. Correct the message either way. Add the retry case to scenario J.

### F5 (Medium) After upgrade 0004, an owner's own public price is silently replaced by the draft price the next time ANY price of that plan is saved
- Where: `_includes/sql/migrations/0004_plans_prices_currencies.php` price-point inserts (steps for `monthly_gbp` / `yearly_gbp`, ~lines 293-327) together with `adminMirrorLegacyPlanPrices()` / `adminMirrorDecision()` in `_functions/fn_admin_plans.php` (~1309, ~2853).
- Why: 0004 moves the legacy columns only while they hold the placeholder, but inserts the draft price points (6.99 / 49.99 / 24.99 / 199.99) unconditionally. If the owner had changed a price (the old runbook said to edit plan rows in SQL), the legacy column keeps the owner's figure while the price list holds the draft. Every price save mirrors the WHOLE plan, so saving an unrelated group copies the draft over the owner's figure.
- Reproduced (MySQL 8.0.36): db at 0003, `pro.priceMonthly = 5.49`, apply 0004 -> public 5.49, price points monthly_gbp=6.99, yearly_gbp=49.99. Then `adminPricesSaveGroup(pro, 'yearly', GBP 49.99 + USD 59.00)` -> "Saved: 1 price change(s)", warnings [] -> public pro monthly now 6.99; audit `plan.update` {"priceMonthly":{"old":"5.49","new":"6.99"}} "Pricing page prices copied from the price list".
- Consequence: the public price rises (or falls) from a value the owner chose to a starting value the owner never accepted, with no warning, the opposite of 0004's "never overwrites the owner's changes". Scenario N2 in migrations_real_mysql.php builds exactly this state and calls it "owner changes kept".
- Suggested fix: in 0004 insert each draft price point only while the matching legacy column still holds the placeholder or the draft (e.g. `... WHERE planKey='pro' AND currency='GBP' AND priceMonthly IN (4.99, 6.99)`), or seed the price points FROM the legacy columns after the legacy update, so the list always agrees with the page; and/or make the mirror copy only the group that was saved and warn when it would change the other one. Add the N2 follow-on save to the test.

### F2 (Low) The "can never fill an empty seed table" validator accepts steps that DO fill an empty table
- Where: `_functions/fn_migrations.php` `migrationStepCouldFillEmptySeedTable()` (~line 650-698); claim repeated in its docblock, `migrationSeedProtectionTables()` docblock and DEV_NOTES.
- Why: it only checks that every FROM is the table itself or a bracketed inner query. An aggregate with no GROUP BY (`MAX()`, `COUNT()`, `COALESCE(MAX(...))`) returns one row even from an empty table.
- Reproduced (MySQL 8.0.36 and MariaDB 10.6.28, install.sql only, tblSubscriptionPlans empty): `INSERT ... SELECT 'enterprise', ..., COALESCE(MAX(featuresJSON),'{}') ... FROM tblSubscriptionPlans WHERE planKey='business' ON DUPLICATE KEY UPDATE ...` -> validator errors 0, couldFill=false, rows 0 -> 1 on both engines. Same with `FROM (SELECT COUNT(*) FROM tblSubscriptionPlans) AS x`. The real 0004 steps are fine (checked: couldFill=false and they do not fill).
- Consequence: a future migration written this way would pass validation and the unit test, then on a CLI-first database make the installer see "partial" starting data (F1 of the earlier review returns). Only trusted developers write migrations, so this is a safety-net gap, not an attack.
- Suggested fix: also refuse aggregate function names (and GROUP BY-free HAVING) in such steps, or more simply require the exact 0004 shape; add the two samples above as "must refuse" cases in tests/test_migrations.php; soften the docs' "can never".

### F3 (Low) The protected plans (P5) can be hidden from sale through the details form
- Where: `_functions/fn_admin_plans.php` `adminPlansUpdate()` (~2151) / `adminPlansValidatePlanForm()` sale window; P5 header claim at line 25-28.
- Why: the off-sale action checks `adminPlansProtectedReason()`, the details save does not, and the public grid and the admin status both honour the sale window.
- Reproduced (MySQL 8.0.36, all migrations, real functions): "take free off sale" refused; `adminPlansUpdate(free, available_until_date=2026-01-01)` "Saved: 1 change(s)"; admin status "Sale dates have ended"; same for anonymous; same with a 2030 start date on pro (the trial plan setting). /pricing grid went from free,pro,business,enterprise to business,enterprise.
- Consequence: the Free card (the only sign-up button) disappears from /pricing, and the screen says the fallback plan's sale has ended, which P5 says cannot happen. Gating is unaffected (fn_features does not read the dates).
- Suggested fix: in `adminPlansUpdate()` refuse a sale window (and possibly sortOrder 0) for a protected plan, under the same locked settings read; add a test.

### F4 (Low) The project-wide literal guard misses prices in page HTML and in whole pence
- Where: `tests/test_commercial_literals.php` `clCommercialLiterals()` (only T_CONSTANT_ENCAPSED_STRING / T_ENCAPSED_AND_WHITESPACE / T_DNUMBER).
- Reproduced in a copy of the tree: appended `<p>Pro is £6.99 a month, Business £24.99.</p>` to `_includes/pricing_grid.php` and a function returning `moneyFormat(699,'GBP')`, `moneyFormat(4999,'GBP')`, `moneyFormat(9900,'GBP')` to fn_pricing.php -> guard still 8/8 passed.
- Consequence: the two most likely ways to hard-code a price in this code base (template text, and the new whole-pence integers Phase 2 introduced) pass the S24 guard; the docs say "fails on a starting ... price in any PHP file".
- Suggested fix: scan T_INLINE_HTML text for the price strings (with and without symbols, and "£99"); check T_LNUMBER against the prices in minor units (699, 4999, 2499, 19999, 11999, 499, 1999, 5999, 9900). Expect a few false positives to whitelist.

### F6 (Low) A price's sale dates are not followed by the public page: an ended (or newly started) price keeps (or never gets) its place until someone saves again
- Where: `adminMirrorSelection()` / `adminMirrorLegacyPlanPrices()` (fn_admin_plans.php ~1246, ~2853) run only at save time; `listPublicPlansForDisplay()` reads the legacy columns.
- Reproduced (MySQL 8.0.36, PHP server): saved Business monthly £24.99 with "On sale until" two minutes ahead -> "Saved", no warning. After validUntil 19:03:00 UTC had passed (DB clock 19:03:02), GET /pricing/ still showed "£24.99 /month"; legacy priceMonthly still 24.99.
- Consequence: the public page advertises a price the owner set to stop, or keeps showing an old figure after a scheduled one starts; nothing warns afterwards.
- Suggested fix: either compute the shown figure at page time from the price list when the legacy mirror is stale (dates in play), or refuse/flag sale dates on monthly/yearly prices while charging is off and say plainly in the editor that dates are not followed by the public page yet.

## Checked and sound
- Money (fn_money.php): 200,000 random round trips (GBP/USD/EUR/JPY) of minor -> text -> parse, and of the DECIMAL text the database returns, plus percent half-up against an integer reference: 0 mismatches. Refuses full-width digits, signs, "6.", ".99", hex, 6.999, 100000. No float in the save path; DECIMALs bound as 's'.
- P1 against hand-made calls (MySQL 8.0.36): changing a bought price refused (amount unchanged); retire/delete of another plan's price via this plan's id refused; a group the kind does not have refused; non-offered currencies (JPY, XXX) ignored, no rows; amount sent as an array refused; stale fingerprint refused; delete of a plan with a subscriber refused with reasons; key change refused; extra fields (featuresJSON, isActive, priceMonthly) ignored.
- Races: plan/price rows locked FOR UPDATE, counts read inside the lock; purchase inserts need the parent row's shared lock (FKs on tblSubscriptions, tblPayments via 0001, tblOneOffPurchases RESTRICT). Builders' race scenario D passes on both engines.
- Mutation tests (copy of the tree): making P1 never frozen, removing the P5 off-sale refusal, skipping the audit write, turning the seed guard off, dropping the plan-kind grid filter, and dropping the replay checksum check are each caught by the unit and/or integration tests. Removing the LOCK IN SHARE MODE on reference counts is caught only by a text check (acceptable: in this order the count is taken after the row locks, so it sees every committed purchase anyway).
- Audit in the same transaction: every save goes through adminPlansInTransaction(); adminPlansAudit() throws on a failed write; mirror and settings afterSave run inside the caller's transaction.
- Admin access: all five new pages call requireGlobalAdminPage() first (static test recursive over public_html/admin); POST pages call adminCheckPost(..., true) (CSRF, Origin, fresh password) before any work and refuse while upgrades are pending; output escaped (adminEscape/htmlspecialchars; raw echoes are ints).
- Public page before 0004 (db at 0003, PHP server): HTTP 200, old prices (£4.99 / £49.99, £19.99 / £199.99), no Enterprise, no PHP warnings. Odd data: HTML in name/description escaped; `javascript:` and `mailto:...?bcc=` contact links fall back to site email, and a broken site_email falls back to hello@cuercode.net; unknown currency "XYZ 12.50"; negative and invalid-yen prices show "Price to be announced" (never Free); future-dated and lifetime-deal plans hidden.
- Upgrade 0004: fresh, upgraded-from-0003, owner-changed, forced re-run and CLI-first + replay paths: builders' migrations_real_mysql.php passes 165/165 on MySQL 8.0.36 and MariaDB 10.6.28 (my run); admin_plans_real_mysql.php 96/96 on both. Real 0004 steps pass the seed guard and do not fill an empty table. Doc claims "MySQL 8.0.46" and the check counts are accurate.
- Replay cannot be reached on an existing install (needs no marker and all three starting tables empty); it only re-runs insert-if-missing / update-if-unchanged steps of applied, unchanged files.
- Full unit suite 7513/7513, php -l clean on every changed PHP file, php86-compat-scan exit 0, check_site_polish 0 problems.

## Known issues #159-#161
- #160 is as described (a plan with no price points at all and 0.00 in the old columns shows "Free" and the sign-up button); a plan whose prices were all retired or deleted keeps showing its last mirrored price instead, which is F6's territory. #159 and #161 are as described.

## Could not check
- Real browser, screen sizes, keyboard-only, dark/colourblind palettes; Apache `.htaccess` headers (PHP built-in server only).
- MySQL 8.4 and MariaDB 10.11 (only 8.0.36 and 10.6.28). F1 was driven over HTTP on MySQL only; the logic is engine-independent.
- By reading only, not reproduced (needs exact timing): a price save reads the main currency BEFORE its transaction, so a main-currency change committed in between leaves that one plan mirrored in the old currency; a trial-plan setting saved at the same moment a plan is deleted could name a deleted plan. Both need two admins acting within milliseconds.
- No Codex cross-review was part of this.
