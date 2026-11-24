# 0008 — Push work-in-progress after each reviewed phase

- **Date:** 2026-11-24
- **Question (plain words):** May finished, reviewed work be pushed to GitHub without asking each time?
- **Answer (owner):** **Yes, push the `work-in-progress` branch after each finished, reviewed phase.** Only that branch; never any other branch, and no pull requests, merges, tags or releases without separate approval.
- **Rules out:** keeping everything local until asked; pushing unreviewed work; pushing to other branches.
- **Implemented in:** session practice. Reconciles the project rule "commit and push to work-in-progress" with the owner's global approval gate for pushes.
- **Supersedes:** nothing.
- **Issue:** #113.
