# 0036 — Upgrade 0004 was corrected in place (one-off)

- **Date:** 2026-11-27
- **Question (plain words):** The rule in DEV_NOTES says never to edit a database upgrade file once it has been applied anywhere. The review fixes changed upgrade 0004 itself. Is that allowed?
- **Answer (lead; the owner may overrule):** **Yes, this once.** No live database exists (owner, 2026-11-22: start fresh) and CueRCode has never been deployed, so the only databases that could hold the old 0004 are developers' own or throwaway ones. Any such database must be rebuilt from empty. The installer must refuse to finish on a database where an applied upgrade file has since changed.
- **Rules out:** editing any upgrade file after the first deploy; this being a precedent.
- **Implemented in:** commits 320505c and ea02cc6; the installer refusal is being built (#31).
- **Supersedes:** nothing.
- **Issue:** #31.
