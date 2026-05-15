# Security Policy

## Supported Versions

Aculect AI Companion is an early release and is not intended for production websites yet. Security fixes are provided for the latest `main` branch and the most recent pre-release only.

| Version | Supported |
| --- | --- |
| `main` | Yes |
| Latest pre-release | Yes |
| Older pre-releases | No |

## Reporting a Vulnerability

Please do not open public GitHub issues for suspected security vulnerabilities.

Use GitHub private vulnerability reporting from the repository Security page. If that is not available, contact the maintainer privately before publishing details.

Include as much of the following as possible:

- A clear summary of the issue and potential impact.
- Steps to reproduce the issue.
- The affected Aculect AI Companion version, commit, or release.
- WordPress, PHP, and browser versions if relevant.
- Any relevant logs or screenshots with tokens, secrets, and personal data removed.

## Security Scope

Useful reports include issues involving:

- Authentication, authorization, or session handling.
- Token, client, or approval-flow weaknesses.
- Privilege escalation or capability bypasses.
- Cross-site scripting, cross-site request forgery, or unsafe redirects.
- Unauthorized content, comment, media, settings, plugin, or theme access.
- Unsafe file upload, remote fetch, or server-side request behavior.
- Exposure of private WordPress options, secrets, or personal data.

Reports may be out of scope if they only affect unsupported pre-releases, require unrealistic physical/local access, target WordPress core or unrelated third-party plugins, or do not include enough detail to reproduce.

## Coordinated Disclosure

The maintainer will make a best-effort attempt to acknowledge valid reports within five business days, investigate privately, and publish a fix or advisory when appropriate.

Aculect AI Companion does not currently run a paid bug bounty program. Good-faith security research that avoids data destruction, service disruption, privacy violations, and public disclosure before a fix is available is welcome.
