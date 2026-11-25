# 0011 — Commit the third-party QR, PDF and barcode libraries into the repository

- **Date:** 2026-11-25
- **Question (plain words):** The QR, PDF and barcode code libraries are kept out of the repository, so the automatic upload to DreamHost does not send them and a fresh install has no QR engine until someone uploads them by hand. How should a new install get them?
- **Answer (owner):** **Commit them into the repository**, as Swagger UI already is, so the normal deploy ships them.
- **Rules out:** a deploy step that downloads them; relying on a by-hand upload.
- **Implemented in:** `.gitignore` (rules for `_libraries/chillerlan`, `fpdf`, `tecnickcom` removed), `web/CueRCode.net/_libraries/`, `.github/workflows/ci.yml` (clone steps become a check that the committed copies match the pinned versions), `/attributions` licence credits. Keeping the copies current is the monthly watch job (#139).
- **Supersedes:** the "gitignored, manually downloaded" statement in `.claude/CLAUDE.md` Libraries section (updated to point here).
- **Issue:** #113.
