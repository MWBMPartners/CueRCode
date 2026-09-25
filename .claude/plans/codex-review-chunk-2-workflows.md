You are reviewing GitHub Actions workflow changes that Claude Code made to the CueRCode repository. You did not write them, and other Claude agents have already reviewed them, so look for what they MISSED. This is READ-ONLY: do not edit any files.

DO NOT read `AGENTS.md`, `.claude/HANDOFF.md`, `.claude/CLAUDE.md` or `.OpenAI/*` first — this is a review, and everything you need is in this brief. Reading them used up a previous attempt's whole allowance.

YOUR ALLOWANCE IS SMALL, so work economically:
- Read only the steps named below. Use `grep -n` to find them, then `sed -n` to read the ranges. Do not print whole files.
- **Write down each finding the moment you are sure of it**, then carry on.

IMPORTANT FACT: a step with no `shell:` runs as `bash -e {0}`, **without** `pipefail`. None of these workflows sets `shell:`.

SCOPE (chunk 2 of 3), branch `work-in-progress`:
1. `.github/workflows/deploy.yml`, step `id: staging` (#124).
   - Folders are copied with `cp -R "$item". dest/` so the `.htaccess` files are staged.
   - Each folder loop starts with `[[ -d "$item" ]] || continue`.
   - The error-hiding was removed.
   - A check stops the deploy if any `.htaccess` is missing from staging.
   - `sed -n '1,50p'` replaces `head -50`.
2. The same file's two SFTP upload steps (#127).
   - The host key is pinned from the `SFTP_KNOWN_HOSTS` secret. If that secret is missing, the step stops (fail-closed).
   - The address is `sftp://user:@host`, so lftp uses the SSH key instead of asking for a password.
   - `net:max-retries 2`.
   - The key file is removed by `trap … EXIT`.
   - `sftp:auto-confirm` was removed.
3. `.github/workflows/ci.yml`, step `id: suite` in job `tests`.
   - `tests/run.php` present: run the tests.
   - `tests/` present but `run.php` missing: fail.
   - No `tests/` at all: on a pull request, fetch `refs/heads/$BASE_REF` and fail if the target branch has `tests/run.php`; otherwise skip.
4. `.claude/plans/121-security-propagate-fix.patch`, a prepared patch for `.github/workflows/security-propagate.yml`. That file exists only on `main`; see it with `git show origin/main:.github/workflows/security-propagate.yml`. The patch:
   - skips only a genuine empty cherry-pick (`CHERRY_PICK_HEAD` present and a clean tree);
   - opens one tracking issue, or comments on the open one, for every other failure: a conflict, another git error, a refused push, or a failure to check for or create the pull request.

LOOK FOR:
- any way to bypass the host-key check;
- a secret leaking into the logs;
- a failure that passes silently;
- untrusted text (a PR title or branch name) reaching a shell command or an issue body;
- conditions that are always true or never true;
- behaviour under `bash -e` without `pipefail`;
- GNU versus BSD differences in `cp`, `find` or `sed`.

Ignore the roughly 25 existing shellcheck info/style notes, which are tracked in #118.

OUTPUT: numbered findings (real defects only), or "NO FINDINGS". Finish with one line listing what you actually read.
