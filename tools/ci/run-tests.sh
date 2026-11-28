#!/usr/bin/env bash
# =============================================================================
# 🧪 CueRCode - Run the unit test suite (shared by CI and deploy)
# =============================================================================
# Path: tools/ci/run-tests.sh
#
# ONE copy of "run the tests", used by BOTH .github/workflows/ci.yml and
# .github/workflows/deploy.yml so the two can never drift apart (#155,
# decision 0025: nothing is uploaded unless the test suite passes).
#
# ⚠️ WHAT THIS DOES NOT RUN: the real-database tests in tests/integration/.
#    They need a MySQL or MariaDB server, so tests/run.php leaves them out (it
#    runs only tests/test_*.php). They have their own shared script,
#    tools/ci/run-integration-tests.sh, which ci.yml and deploy.yml run in a
#    separate job against throwaway servers; a failure there also stops a
#    deploy (decision 0040, #174).
#
# It fails when:
#   1. tests/run.php is missing (a deploy must never skip the tests silently),
#   2. a committed library does not match its pinned version (decision 0011),
#   3. the test suite fails,
#   4. PHP deprecation text reaches the output, or
#   5. the suite did not really run (recheck finding 3 of the 7n Phase 2
#      review): fewer than MIN_TEST_FILES test files exist, the runner did
#      not run every one of them, or no assertion ran at all. A deleted or
#      emptied test folder must never look like a green run.
#
# Run from the repository root:  bash tools/ci/run-tests.sh
#
# @copyright 2025-2026 MWBM Partners Ltd. All rights reserved.
# =============================================================================
# 🔒 pipefail matters: without it `php tests/run.php | tee` would report
#    tee's success even when the tests failed.
set -euo pipefail

# 🔢 The fewest tests/test_*.php files a real run can have (51 on
#    2026-11-27). Raise it as the suite grows; lower it only on purpose, in
#    the same commit that removes test files.
MIN_TEST_FILES=45

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

# 🔢 Recheck finding 3: prove the suite really ran. tests/run.php prints
#    "Discovered N test file(s)." and "Total: N assertion(s) ..." (no colour
#    codes: its output is piped here, so colour is off).
on_disk=0
for test_file in tests/test_*.php; do
  if [ -f "${test_file}" ]; then
    on_disk=$((on_disk + 1))
  fi
done
discovered="$(sed -n 's/^Discovered \([0-9][0-9]*\) test file(s)\.$/\1/p' "${output}" | tail -n 1)"
assertions="$(sed -n 's/^Total: \([0-9][0-9]*\) assertion(s).*/\1/p' "${output}" | tail -n 1)"

if [ "${on_disk}" -lt "${MIN_TEST_FILES}" ]; then
  echo "::error::Only ${on_disk} test file(s) found in tests/ (at least ${MIN_TEST_FILES} expected) - the suite cannot be trusted."
  exit 1
fi
if [ "${discovered}" != "${on_disk}" ]; then
  echo "::error::The test runner ran '${discovered}' test file(s) but tests/ holds ${on_disk}."
  exit 1
fi
if [ -z "${assertions}" ] || [ "${assertions}" -eq 0 ]; then
  echo "::error::No test assertions ran."
  exit 1
fi
echo "Test suite ran: ${discovered} test file(s), ${assertions} assertion(s)."
