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
