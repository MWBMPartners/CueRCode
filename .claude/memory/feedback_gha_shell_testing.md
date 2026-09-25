---
name: Test GitHub Actions steps with the shell GitHub really uses
description: Steps without `shell:` run as `bash -e {0}` (NO pipefail); only `shell: bash` adds `-o pipefail`. Test locally with the matching flags (2026-09-25, #124)
type: feedback
---

When testing a workflow step's `run:` script locally, use the same shell flags GitHub uses for THAT step:

- **No `shell:` on the step, and no `defaults.run.shell`** (every workflow in this repo, as of 2026-09-25) → GitHub runs `bash -e {0}`. There is **no** `pipefail`, so only the LAST command in a pipe decides success.
- **`shell: bash` set explicitly** → `bash --noprofile --norc -eo pipefail {0}`.

**Why:** On 2026-09-25 the lead tested the deploy staging step with `-eo pipefail`. It then told the owner, in a code comment, the CHANGELOG and a pushed commit message (5e2005c), that `find … | head -50` "killed every production deploy". It didn't. The #124 stand-in review disproved it from this repo's own run logs (`shell: /usr/bin/bash -e {0}`). The pushed commit message couldn't be corrected, so the CHANGELOG carries the correction. The flip side matters too: because pipefail is OFF, a failure on the left of `find … | while read` is NOT caught in these workflows.

**How to apply:** check the step for `shell:` (and the workflow/job for `defaults:`) before choosing the local flags. When a claim depends on shell behaviour, confirm it in a real run log (`gh run view <id> --log`, and look for the `shell:` line) before stating it as fact. Related: [[feedback_watchdog]].
