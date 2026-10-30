# Security Policy

## Reporting a Vulnerability

**Do not report security vulnerabilities through public GitHub issues, pull requests,
discussions, or comments.** Public reports can expose unpatched issues to attackers
before a fix is available.

### Preferred: GitHub Private Vulnerability Reporting

If the repository owner has enabled **Private Vulnerability Reporting**, use it:

1. Go to <https://github.com/navi-crwn/hop-safety/security/advisories/new>.
2. Choose **Report a vulnerability**.
3. Provide a clear description, reproduction steps, affected versions, and impact.

> **Availability:** This channel is available **only if the repository owner has
> enabled Private Vulnerability Reporting** under *Settings → Code security and
> analysis*. If you do not see the "Report a vulnerability" option, the feature is
> not enabled and you should use the fallback below.

You can also check the repository security page:
<https://github.com/navi-crwn/hop-safety/security>.

### Fallback: verified owner channel

If Private Vulnerability Reporting is **not** enabled, do **not** post the details in
public. Contact the repository owner through a **verified, existing** channel only —
for example a direct message on a platform where the owner's identity is already
established. Do not trust contact details found in forks or unverified copies of this
repository.

If no verified private channel is available to you, hold the report until the owner
enables Private Vulnerability Reporting, and do not disclose the issue publicly in the
meantime.

### What to include

- Affected package: `hop/safety` (run `composer show hop/safety` for the installed version).
- PHP version and Laravel framework version.
- A minimal reproduction: code snippet, input payload, or sequence of steps. For
  sanitizer or URL-guard issues, include the exact input that produces unexpected
  output and the observed output.
- Expected behavior vs. actual behavior.
- Impact and any known mitigations.
- Sanitized logs only — **never include secrets, API keys, tokens, or credentials.**

## Coordination

- The maintainer will acknowledge receipt as soon as practical.
- A coordinated disclosure timeline of **90 days** from acknowledgment is the default,
  adjustable by mutual agreement.
- Credit is given in the published advisory unless you request otherwise.

## Supported Versions

This package does not maintain separate long-term branches. The latest `main` and the
most recent tagged release are the only versions that receive security fixes.

## Owner activation steps

To make Private Vulnerability Reporting available to reporters, the repository owner
must enable it (this cannot be done from a pull request):

1. Open <https://github.com/navi-crwn/hop-safety/settings/security_analysis>.
2. Under **Private vulnerability reporting**, enable the feature.
3. Optionally enable Dependabot security updates in the same page.

These are the only repository settings changes referenced by this policy; they are the
owner's responsibility and are not performed by any contributor PR.
