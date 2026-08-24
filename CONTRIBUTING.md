# Contributing to Development Assistant

Thank you for helping improve Development Assistant. Contributions may include
bug reports, documentation, translations, tests, fixes, and focused feature
proposals.

## Before You Start

- Search existing issues before opening a new one.
- Use the issue forms for bug reports and feature requests.
- Discuss substantial behavior, architecture, dependency, or user-interface
  changes in an issue before implementing them.
- Follow [SECURITY.md](SECURITY.md) instead of opening a public issue for a
  suspected vulnerability.
- Use [SUPPORT.md](SUPPORT.md) for installation and usage questions.

## Development Setup

Follow [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) for prerequisites, local setup,
service URLs, repository-owned commands, and test-environment behavior. Read
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) before changing runtime ownership or
safety boundaries.

## Repository Ownership

- `inc/` contains the runtime PHP implementation.
- `src/` contains strict TypeScript and plain CSS source organized by feature.
- `assets/` is generated locally and must not be committed.
- `lang/` contains translation source.
- `docker/`, `docker-compose.yml`, and `scripts/` own the local WordPress runtime.
- `wporg/assets/` contains reviewed WordPress.org directory assets.
- `readme.txt` is the public WordPress.org description and changelog.

Do not commit `.env`, credentials, database backups, uploads, debug logs,
generated certificates, `vendor/`, `node_modules/`, `wp/`, or `release/`
contents.

## Compatibility and Code Standards

- Preserve the documented minimum PHP 7.4 and WordPress 5.0 compatibility
  unless a separately discussed change updates the public compatibility policy.
- Follow the WordPress PHP Coding Standards enforced by PHPCS.
- Sanitize and validate input, escape output, check capabilities, and verify
  nonces at state-changing boundaries.
- Keep frontend code in strict TypeScript and plain CSS.
- Preserve the classic WordPress script and stable asset filename contracts.
- Prefer WordPress APIs and bundled libraries over custom platform replacements.
- Keep changes focused. Do not mix unrelated refactors with behavior changes.
- Update user-facing documentation, `readme.txt`, translations, development
  documentation, or architecture documentation when a change affects those
  contracts.
- Do not change versions, tags, credentials, or publish releases unless the
  maintainer explicitly requests release work.

## Required Checks

Run the repository-owned checks before opening a pull request:

```sh
make lint
make build-src
```

For behavior changes, also test the affected WordPress admin flow in the local
Docker environment. Record the exact manual scenario in the pull request. Test
activation, deactivation, and reset behavior whenever a change can affect stored
options, files, users, or temporarily deactivated plugins.

## Pull Requests

A pull request should:

- reference the related issue when one exists;
- explain the problem and the chosen solution;
- remain small enough to review as one coherent change;
- list automated and manual verification;
- include screenshots for visible interface changes;
- call out compatibility, security, privacy, data, or migration effects;
- contain no unrelated formatting or generated-file changes.

Maintainers may ask for changes or decline proposals that do not fit the project
scope. Submission does not guarantee acceptance.

## License

By contributing, you agree that your contribution is licensed under the same
[GPL-2.0-or-later](license.txt) terms as the project.
