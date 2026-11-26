# 0019 — Start the later build order now, before the new install is proven live

- **Date:** 2026-11-26
- **Question (plain words):** The code for a new install is written, but nothing has been tried on the real web host and no real-browser check has been done. Should further building wait for that?
- **Answer (owner):** **No. Go on to the later build order now** ("proceed to further dev (ie the \"later build order\" for now"), keeping the handoff note up to date as the work goes, because the session may need to stop.
- **Order:** admin and commerce Phases 2-7, then dynamic and dual-function QR, then passkeys/translations/image fills/JPEG XL, then the prompt-cache add-on and single sign-on wording.
- **Rules out:** waiting for the live install before starting Phase 2. It does **not** switch charging on, and does **not** approve a pull request, merge, release or push to any branch other than `work-in-progress` (decision 0008).
- **Still owed:** the owner's live-install steps and the real-browser pass.
- **Implemented in:** `.claude/HANDOFF.md`, `.claude/plans/7n-admin-commerce-plan.md`.
- **Supersedes:** the "after the install gate" ordering in the queue file (not a numbered decision).
- **Issue:** #31.
