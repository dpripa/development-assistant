# Security Policy

Development Assistant changes WordPress debug configuration, reads and manages
debug logs, creates temporary support users, and manages plugin state. Please
report suspected vulnerabilities privately and responsibly.

## Supported Versions

Only the latest stable version published on WordPress.org receives security
updates. Confirm the issue against that version whenever it is safe to do so.

## Reporting a Vulnerability

Do not open a public GitHub issue, discussion, or WordPress.org forum topic for
an undisclosed vulnerability.

Use one of these private channels:

1. Submit a [private GitHub security advisory](https://github.com/dpripa/development-assistant/security/advisories/new).
2. If private reporting is unavailable, email `i@dpripa.com` with the subject
   `Development Assistant security report`.

Include:

- the affected Development Assistant, WordPress, and PHP versions;
- prerequisites and a minimal reproduction;
- the expected security boundary and observed behavior;
- the likely impact;
- any suggested mitigation;
- whether the issue is already public or known to another party.

Remove credentials, personal data, production database contents, private logs,
and unrelated secrets. Use test accounts and sanitized evidence.

## Response and Disclosure

The maintainer will aim to acknowledge a report within seven days and provide
an initial assessment or status update within fourteen days. Resolution time
depends on severity, reproducibility, and release coordination.

Please allow time for investigation and a patched release before public
disclosure. Credit will be offered when desired and appropriate. Reports made
in good faith to improve user safety are welcome.
