#!/usr/bin/env bash
# =============================================================================
# 🗄️ CueRCode - Run the real-database tests (shared by CI and deploy)
# =============================================================================
# Path: tools/ci/run-integration-tests.sh
#
# ONE copy of "run the real-database tests", used by BOTH
# .github/workflows/ci.yml and .github/workflows/deploy.yml so the two can
# never drift apart (#174, decision 0040: nothing is uploaded unless these
# pass on MySQL 8.0, MySQL 8.4 and MariaDB 10.6).
#
# It runs every tests/integration/*_real_mysql.php, one after the other (some
# check timings, so never in parallel), each with its own fresh database
# named cuercode_it_ci_<script>, removed again after the script. The database
# server must already be running and must be a THROWAWAY one:
# tools/ci/integration_db_guard.php refuses a server that is not on this
# machine, an account that cannot see every database, or a server that holds
# any database other than the server's own and cuercode_it_ ones.
#
# Connection details (the throwaway server's, never a real site's):
#   CUERCODE_IT_DB_HOST (127.0.0.1), CUERCODE_IT_DB_PORT (3306),
#   CUERCODE_IT_DB_USER (root),      CUERCODE_IT_DB_PASS (empty)
#
# It fails when:
#   1. a required script (REQUIRED_SCRIPTS below) is missing,
#   2. the guard refuses the server, or cannot reach it,
#   3. no script ran,
#   4. any script fails (exit code not 0),
#   5. PHP deprecation text reaches a script's output, or
#   6. a script says part of it could not run here (CUERCODE_IT_REQUIRE_ALL=1
#      is set, so admin_access_real_mysql.php fails instead of printing
#      "NOT RUN" when the pretend-terminal tool is missing).
#
# Run from the repository root:  bash tools/ci/run-integration-tests.sh
#
# @copyright 2025-2026 MWBM Partners Ltd. All rights reserved.
# =============================================================================
set -euo pipefail

# 📋 Every script that must exist. Add a new script's name here in the same
#    commit that adds the script; a missing one fails the run.
REQUIRED_SCRIPTS="account_recovery admin_access admin_plans admin_settings dynamic_qr dynamic_qr_abuse email_queue human_check migrations resend_verification site_timezone"

export CUERCODE_IT_DB_HOST="${CUERCODE_IT_DB_HOST:-127.0.0.1}"
export CUERCODE_IT_DB_PORT="${CUERCODE_IT_DB_PORT:-3306}"
export CUERCODE_IT_DB_USER="${CUERCODE_IT_DB_USER:-root}"
export CUERCODE_IT_DB_PASS="${CUERCODE_IT_DB_PASS:-}"
export CUERCODE_IT_REQUIRE_ALL=1

GUARD="tools/ci/integration_db_guard.php"

if [ ! -f "${GUARD}" ]; then
  echo "::error::${GUARD} is missing - the real-database tests cannot run safely."
  exit 1
fi

missing=0
for name in ${REQUIRED_SCRIPTS}; do
  if [ ! -f "tests/integration/${name}_real_mysql.php" ]; then
    echo "::error::tests/integration/${name}_real_mysql.php is missing - a required real-database test has been deleted or renamed."
    missing=1
  fi
done
if [ "${missing}" -ne 0 ]; then
  exit 1
fi

# 🛡️ Refuse anything that is not a throwaway server (exit 1 or 2 stops here).
php "${GUARD}"

# 📝 A private temporary file, removed however this script ends.
output="$(mktemp)"
trap 'rm -f "${output}"' EXIT

ran=0
failed=""
for script in tests/integration/*_real_mysql.php; do
  # 🛡️ No match at all leaves the pattern itself; skip it (caught as "none ran").
  [ -f "${script}" ] || continue
  name="$(basename "${script}" _real_mysql.php)"
  export CUERCODE_IT_DB_NAME="cuercode_it_ci_${name}"

  # 🧾 What each script needs: three load the starting tables themselves when
  #    given --load-schema; two of those expect the database to exist first.
  #    (account_recovery creates its own, and checks more when it does.)
  args=()
  case "${name}" in
    email_queue|resend_verification)
      php "${GUARD}" --create="${CUERCODE_IT_DB_NAME}"
      args=(--load-schema)
      ;;
    account_recovery|site_timezone)
      args=(--load-schema)
      ;;
  esac

  echo "::group::${name}"
  status=0
  # ⏱️ ${args[@]+...} keeps bash 3.2 (macOS) happy with an empty list under set -u.
  php "${script}" ${args[@]+"${args[@]}"} > "${output}" 2>&1 || status=$?
  cat "${output}"
  echo "::endgroup::"
  ran=$((ran + 1))

  # 🧹 Third-round review, Info 4: remove this script's database (the guard
  #    checks the server again first). Leaving one behind is harmless on a
  #    throwaway server, so a problem here is a warning, not a failure.
  php "${GUARD}" --drop="${CUERCODE_IT_DB_NAME}" || echo "::warning::could not remove ${CUERCODE_IT_DB_NAME} after ${name}."

  if [ "${status}" -ne 0 ]; then
    echo "::error::${script} failed (exit ${status})."
    failed="${failed} ${name}"
  elif grep -nE '(^|PHP )Deprecated:' "${output}"; then
    echo "::error::${script} printed PHP deprecation text."
    failed="${failed} ${name}"
  else
    echo "${name}: passed."
  fi
done

if [ "${ran}" -eq 0 ]; then
  echo "::error::No real-database test ran."
  exit 1
fi
if [ -n "${failed}" ]; then
  echo "::error::Real-database tests failed:${failed}"
  exit 1
fi
echo "Real-database tests ran: ${ran} script(s), all passed."
