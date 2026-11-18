#!/usr/bin/env bash
# =============================================================================
# 🔤 CueRCode - PHP lint (syntax + compile-time deprecations)
# =============================================================================
# Path: tools/ci/php-lint.sh
#
# ONE copy of the lint loop, used by BOTH .github/workflows/ci.yml and
# .github/workflows/deploy.yml so the two can never drift apart (#151).
#
# What it checks, for every .php file under web/, tests/ and tools/:
#   1. `php -l` exits 0 (no syntax error), AND
#   2. its output contains no "Deprecated:" text.
# It also fails when it finds no PHP files at all, so an empty lint cannot pass.
#
# Point 2 matters: `php -l` exits 0 even when the file uses something the PHP
# version has deprecated at compile time, so checking the exit code alone makes
# those deprecations invisible. Here error_reporting is forced to everything and
# display_errors to on, so the text appears and is caught.
#
# Folders that do not exist are skipped (an older tier branch such as
# release-candidate may not have tests/ or tools/ yet, #110).
#
# Run from the repository root:  bash tools/ci/php-lint.sh
#
# @copyright 2025-2026 MWBM Partners Ltd. All rights reserved.
# =============================================================================
set -euo pipefail

fail=0
checked=0

# 📂 Only scan folders that exist.
DIRS=()
for d in web tests tools; do
  if [ -d "${d}" ]; then
    DIRS+=("${d}")
  fi
done

if [ "${#DIRS[@]}" -eq 0 ]; then
  echo "::error::No web/, tests/ or tools/ folder found - run this from the repository root."
  exit 1
fi

while IFS= read -r file; do
  checked=$((checked + 1))
  # 🧪 Capture stdout and stderr together; keep going if php -l fails so we can
  #    report the exit code ourselves.
  status=0
  output="$(php -d error_reporting=-1 -d display_errors=1 -d log_errors=0 -l "${file}" 2>&1)" || status=$?

  if [ "${status}" -ne 0 ]; then
    echo "::error file=${file}::PHP syntax error"
    echo "${output}"
    fail=1
  elif [[ "${output}" == *Deprecated:* ]]; then
    # 🔎 A bash pattern match, not `printf | grep -q`: grep -q can exit early and
    #    SIGPIPE the printf, which pipefail would turn into a false result.
    echo "::error file=${file}::PHP deprecation found at compile time"
    echo "${output}"
    fail=1
  fi
done < <(find "${DIRS[@]}" -name '*.php' -type f | sort)

# 🚨 Zero files means the lint checked nothing (wrong folder, bad find): that is a failure.
if [ "${checked}" -eq 0 ]; then
  echo "::error::No .php files were found under ${DIRS[*]} - nothing was linted."
  exit 1
fi

if [ "${fail}" -ne 0 ]; then
  echo "PHP lint failed (syntax errors or deprecations found)."
  exit 1
fi

echo "php -l clean across ${checked} PHP files (no syntax errors, no deprecations)."
