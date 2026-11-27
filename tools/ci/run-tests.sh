#!/usr/bin/env bash
# =============================================================================
# 🧪 CueRCode - Run the full test suite (shared by CI and deploy)
# =============================================================================
# Path: tools/ci/run-tests.sh
#
# ONE copy of "run the tests", used by BOTH .github/workflows/ci.yml and
# .github/workflows/deploy.yml so the two can never drift apart (#155,
# decision 0025: nothing is uploaded unless the full test suite passes).
#
# It fails when:
#   1. tests/run.php is missing (a deploy must never skip the tests silently),
#   2. a committed library does not match its pinned version (decision 0011),
#   3. the test suite fails, or
#   4. PHP deprecation text reaches the output.
#
# Run from the repository root:  bash tools/ci/run-tests.sh
#
# @copyright 2025-2026 MWBM Partners Ltd. All rights reserved.
# =============================================================================
# 🔒 pipefail matters: without it `php tests/run.php | tee` would report
#    tee's success even when the tests failed.
set -euo pipefail

for f in tests/run.php tools/check_vendored_libraries.php; do
  if [ ! -f "${f}" ]; then
    echo "::error::${f} is missing - the test suite cannot run."
    exit 1
  fi
done

# 📚 Prove the committed libraries are exactly the pinned versions.
php tools/check_vendored_libraries.php

# 📝 A private temporary file, removed however this script ends.
output="$(mktemp)"
trap 'rm -f "${output}"' EXIT

php tests/run.php 2>&1 | tee "${output}"

# 🧯 Backstop for deprecation text that the runner itself did not catch.
if grep -nE '(^|PHP )Deprecated:' "${output}"; then
  echo "::error::PHP deprecation text found in the test output."
  exit 1
fi
