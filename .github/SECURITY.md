<!--
  =============================================================================
  🔐 CueRCode - Security Policy (responsible disclosure)
  File: .github/SECURITY.md
  @copyright 2025-2026 MWBM Partners Ltd. All rights reserved.
  =============================================================================
  NOTE: This is the PUBLIC vulnerability-disclosure policy shown by GitHub in
  the repository "Security" tab. It is distinct from the repository-root
  /SECURITY.md, which is the internal engineering findings register.
-->

# Security Policy

CueRCode is a QR-code generation service operated by MWBM Partners Ltd. We take
the security of the service and of the code that powers it seriously, and we
welcome reports from the security community.

## Supported branches

CueRCode is developed on a four-tier branch model. Security fixes are applied
across all supported tiers:

| Branch              | Channel                          | Security fixes |
| ------------------- | -------------------------------- | -------------- |
| `main`              | Production (CueRCode.net)        | ✅ Supported   |
| `release-candidate` | Release-candidate staging        | ✅ Supported   |
| `beta`              | Beta testing environment         | ✅ Supported   |
| `alpha`             | Alpha testing environment        | ✅ Supported   |

Automated dependency updates (Dependabot) and code scanning (see
`.github/workflows/`) run against all four branches so that a vulnerability
patched on one tier is not silently left open on another. Security fixes that
land on `main` first (Dependabot security updates target only the default
branch) are automatically cherry-picked back to `alpha`, `beta`, and
`release-candidate` by `.github/workflows/security-propagate.yml`. The full CI
suite (tests, syntax check, secret scan via `ci.yml`) currently runs on
`alpha`, `beta`, `release-candidate` and `main`; it does not yet run on the
`work-in-progress` integration branch that feeds `alpha`.

## Reporting a vulnerability

**Please do not open a public GitHub issue for security vulnerabilities.**

Instead, report privately using either of:

1. **GitHub Private Vulnerability Reporting** — use the "Report a vulnerability"
   button under this repository's **Security** tab (preferred; keeps the report
   and remediation private until a fix ships).
2. **Email** — `security@cuercode.net` (or, if unavailable, the contact listed
   on https://CueRCode.net). Please encrypt sensitive details where possible.

Include, where you can:

- A clear description of the issue and its impact.
- Step-by-step reproduction (proof-of-concept, affected endpoint/parameter).
- The affected branch/channel and any relevant version (see `/api/v1/health`).
- Any suggested remediation.

## Our commitment

- **Acknowledgement:** within **3 working days** of your report.
- **Assessment & triage:** an initial severity assessment within **10 working
  days**.
- **Remediation:** we aim to ship fixes for Critical/High issues promptly and
  will keep you updated on progress and expected timelines.
- **Credit:** with your permission, we are happy to credit you once a fix is
  released.

## Scope

In scope: the CueRCode web application, its REST API, and this repository's code.

Out of scope: denial-of-service testing against the live service, social
engineering, physical attacks, and findings in third-party CDN assets outside
our control. Please test against your own generated content and avoid accessing
or modifying other users' data.

## Safe harbour

We will not pursue or support legal action against researchers who act in good
faith, follow this policy, avoid privacy violations and service degradation, and
give us a reasonable opportunity to remediate before any public disclosure.
