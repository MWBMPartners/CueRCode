# Decisions — one file each

**Issues:** #151 (first decision recorded). The rule that every project keeps this folder is in the owner's device-wide instructions.

Each record names the issue it governs; the issue carries a comment naming the record. Records are **append-only**: never edit one. A decision taken again is a NEW file naming the one it supersedes.

**Before asking the owner a question, search this folder.** If a record covers it, follow it and say so. Re-ask only when a measured fact makes the recorded answer unworkable, and then quote the record.

Older decisions live in `LAUNCH_DECISIONS.md` and `.claude/HANDOFF.md` §0 and have not been backfilled here (backfill only with dates that can be supported).

| No. | Date | Decision | Status |
|---|---|---|---|
| [0001](0001-model-tiers-for-gather-plan-build.md) | 2026-11-18 | Gather = Sonnet/Haiku medium; plan = Opus high; build = Sonnet/Haiku medium; any AI tool | Current |
| [0002](0002-php-support-matrix-8-4-to-8-6.md) | 2026-11-18 | Supported PHP versions are 8.4, 8.5 and 8.6; all checked on merge and before deploy (8.6 may fail for now) | Current |
| [0003](0003-no-php-8-6-only-features-without-measured-benefit.md) | 2026-11-18 | Do not adopt PHP 8.6-only features without a measured benefit; keep 8.4/8.5 compatible | Superseded by 0004 |
| [0004](0004-adopt-php-8-6-features-where-safe.md) | 2026-11-18 | Owner: use PHP 8.6 features on 8.6 where safe, old code on 8.4/8.5 | Current (supersedes 0003); implemented by 0006 |
| [0005](0005-php-8-6-checks-report-only-for-now.md) | 2026-11-18 | Owner: 8.6 check failures report but do not block yet | Current |
| [0006](0006-php-8-6-adoption-what-and-where.md) | 2026-11-18 | What was adopted from PHP 8.6 (`clamp()` via `cuercodeClampInt()`) and what was skipped, with reasons | Current (implements 0004) |
| [0007](0007-payg-monthly-cap-means-money-spent-on-packs.md) | 2026-11-24 | Owner: the pay-as-you-go monthly cap is money spent on credit packs, per currency | Current |
| [0008](0008-push-work-in-progress-after-each-reviewed-phase.md) | 2026-11-24 | Owner: push `work-in-progress` (only) after each reviewed phase | Current |
| [0009](0009-admin-sign-in-redirect-means-area-not-secret.md) | 2026-11-24 | Lead (owner has not approved; may overrule): signed-out visitors to `/admin/` are sent to sign-in, so the area's existence is not secret; the gate is the protection | Current (unapproved) |
| [0010](0010-first-admin-activation-sets-a-new-password.md) | 2026-11-24 | Owner: activating a waiting account as first Global Admin sets a new password and signs out old sessions | Current |
| [0011](0011-commit-third-party-php-libraries-into-repository.md) | 2026-11-25 | Owner: commit the QR/PDF/barcode libraries into the repository so the deploy ships them | Current |
| [0012](0012-database-minimum-mysql-8-0-13-mariadb-10-6.md) | 2026-11-25 | Owner: minimum database is MySQL 8.0.13+ or MariaDB 10.6+, enforced by the installer | Current |
| [0013](0013-signup-first-trick-verification-needs-password-reset-removes-all.md) | 2026-11-25 | Owner: verification link needs the password; a password reset removes sessions, API keys, linked sign-ins, passkeys | Current |
| [0014](0014-client-address-trust-cloudflare-only-from-its-ranges.md) | 2026-11-25 | Owner: trust Cloudflare's address header only from Cloudflare ranges; add a live check | Current |
| [0015](0015-trusted-forwarding-list-refuses-very-wide-ranges.md) | 2026-11-25 | Owner: the trusted forwarding servers setting refuses ranges wider than /8 (IPv4) or /32 (IPv6) | Current |
| [0016](0016-voluntary-password-change-signs-out-everywhere-only.md) | 2026-11-25 | Owner: a voluntary password change signs out everywhere and does nothing more (no key or link removal) | Current |
| [0017](0017-codex-round-1-fix-choices-by-the-lead.md) | 2026-11-25 | Lead (owner may overrule): seven small design choices made while fixing the Codex round-1 findings | Current |
| [0018](0018-seed-marker-present-means-skip-seeding.md) | 2026-11-25 | Lead (owner may overrule): a present seed marker means the installer skips seeding, even if the tables were emptied | Current |
| [0019](0019-start-later-build-order-before-live-proof.md) | 2026-11-26 | Owner: start the later build order (admin and commerce Phase 2 onwards) now, before the install is proven live | Current |
| [0020](0020-pricing-page-price-promise-follows-each-plan.md) | 2026-11-26 | Owner: the pricing page states each plan's own price promise | Current |
| [0021](0021-paid-plan-with-no-price-shows-price-coming-soon.md) | 2026-11-26 | Owner: a paid plan with no price shows "Price coming soon" and no sign-up button | Current |
| [0022](0022-unbuilt-features-in-plan-descriptions-marked-coming-soon.md) | 2026-11-26 | Owner: starting plan descriptions mark unbuilt features as coming soon | Current |
| [0023](0023-first-version-is-free-service-plus-dynamic-qr.md) | 2026-11-27 | Owner: the first version is the free service plus dynamic QR codes; nobody is charged | Current (changes the order in 0019) |
| [0024](0024-bot-check-supports-three-providers-at-first-launch.md) | 2026-11-27 | Owner: the first launch has a human check supporting Turnstile, reCAPTCHA and hCaptcha | Current |
| [0025](0025-full-test-suite-must-pass-before-deploy.md) | 2026-11-27 | Owner: the full test suite must pass before anything is uploaded | Current |
| [0026](0026-ask-before-opening-the-alpha-pull-request.md) | 2026-11-27 | Owner: ask before opening the pull request into `alpha` | Current |
