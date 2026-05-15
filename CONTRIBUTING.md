# Contributing to Aculect AI Companion

Thanks for your interest in improving Aculect AI Companion.

Aculect AI Companion is an early-stage WordPress plugin that helps site owners manage WordPress with AI assistants. Contributions should keep the product simple for non-technical users while keeping the implementation secure, testable, and WordPress-native.

## Before You Start

- Open or comment on an issue before starting larger changes.
- Keep pull requests focused. One behavior change per PR is easier to review.
- Do not include secrets, tokens, private site data, database dumps, or customer data in issues, commits, screenshots, tests, or logs.
- Report security issues privately through the repository security reporting flow. Do not open public issues for suspected vulnerabilities.

## Local Requirements

- PHP 8.2 or newer.
- Composer 2.
- Node.js 20.19.0 or newer.
- npm 10 or newer.
- A local WordPress development environment for manual plugin testing.

Use the project Node version when working locally:

```bash
nvm use
```

Install dependencies:

```bash
composer install
npm ci
```

## Development Workflow

1. Create a branch from `main`.
2. Make the smallest useful change.
3. Add or update tests when behavior changes.
4. Rebuild assets if JavaScript or styles changed.
5. Run the validation commands before opening a pull request.

Recommended validation:

```bash
composer validate
composer test
npm run lint:js
npm run lint:css
npm run build
npm audit --audit-level=low
composer audit
```

## Coding Standards

PHP code should:

- Use strict types and PSR-4 classes under the `Aculect\AICompanion\` namespace.
- Follow WordPress Coding Standards.
- Sanitize input, escape output, and verify capabilities on write paths.
- Use WordPress APIs where practical.
- Keep classes small, focused, and testable.

JavaScript and admin UI code should:

- Use `@wordpress/scripts` for linting and builds.
- Use WordPress components for admin UI.
- Keep source code in `src/` and commit rebuilt files in `build/` when assets change.
- Avoid introducing large dependencies unless they are clearly justified.

## Product Copy

User-facing copy should stay simple and non-technical.

Use terms like:

- connection URL
- approval screen
- actions
- connect
- disconnect

Avoid surfacing protocol or implementation details in product UI unless the screen is explicitly for developer diagnostics.

## Security Expectations

Aculect AI Companion handles site access and can change WordPress content through connected assistants, so security-sensitive changes need extra care.

Security-related changes should consider:

- WordPress capabilities and least privilege.
- Nonces and CSRF protection for admin actions.
- Token and session handling.
- Safe redirects.
- REST permission callbacks.
- SSRF protection for remote media fetches.
- No logging of tokens, secrets, personal data, or sensitive request bodies.

## Pull Request Checklist

Before asking for review, confirm:

- [ ] The PR has a clear summary and testing notes.
- [ ] New behavior has tests where practical.
- [ ] `composer test` passes.
- [ ] `npm run lint:js` passes.
- [ ] `npm run lint:css` passes.
- [ ] `npm run build` was run when assets changed.
- [ ] `npm audit --audit-level=low` passes.
- [ ] `composer audit` passes.
- [ ] User-facing copy avoids unnecessary technical terminology.

## Releases

Maintainers handle GitHub releases and pre-releases. Contribution pull requests should not create release tags, release assets, or pre-release drafts unless a maintainer explicitly asks for that work.
