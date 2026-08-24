# Development

This document describes the supported local environment and repository-owned
commands for contributors working from a source checkout.

Read [CONTRIBUTING.md](../CONTRIBUTING.md) before implementing a change. Runtime
ownership and safety boundaries are documented in
[ARCHITECTURE.md](ARCHITECTURE.md). Publishing is restricted to verified release
maintainers and is documented separately in
[RELEASE_POLICY.md](RELEASE_POLICY.md).

## Requirements

- Docker with Docker Compose
- `mkcert`
- NVM with Node.js 24
- Subversion, `rsync`, and `unzip` for release-maintenance workflows

Composer runs through Docker, so the standard setup does not require host PHP or
Composer installations.

## Local Setup

Clone the repository and initialize its dependencies:

```sh
git clone https://github.com/dpripa/development-assistant.git
cd development-assistant
make setup
```

`make setup` creates `.env`, installs Composer and npm dependencies, builds
assets, generates a trusted local certificate, and downloads WordPress into the
ignored local `wp/` runtime directory.

Start the local WordPress stack:

```sh
make up
```

The current checkout is exposed at the standard
`wp/wp-content/plugins/development-assistant` path through a relative symlink.
No sibling repositories or global WordPress directories are required.

Local services:

- WordPress: `https://localhost:21601`
- Adminer: `http://localhost:21602`
- Mailpit: `http://localhost:21603`

WordPress is installed automatically on first startup. Admin credentials, ports,
required helper plugins, and Xdebug settings can be changed in the ignored
`.env` file.

## Runtime Commands

```sh
make up
make down
make restart
make reinit
make logs
make wp cmd="plugin list"
make shell
make db-shell
make db-backup label=before-change
make db-restore file=backups/db/YYYY-MM-DD-HHMMSS-db.sql.gz
make xdebug-on
make xdebug-off
make wp-core-update
```

`make reinit` creates a database backup before replacing the local database
volume. WordPress files, uploads, themes, and installed helper plugins remain in
the ignored local `wp/` directory and are not committed.

`make wp-core-update` updates WordPress Core without replacing `wp-content`,
`wp-config.php`, or other local runtime configuration.

## Frontend Development

Frontend source is organized by WordPress admin feature under `src/`. Vite
compiles strict TypeScript and plain CSS into the stable classic-script and
stylesheet filenames consumed by the plugin.

Build production and non-minified assets once:

```sh
make build-src
```

Rebuild frontend assets continuously while working:

```sh
make start-watch
```

Generated files under `assets/` are ignored and must not be committed.

## Verification

Run the repository-owned checks before opening a pull request:

```sh
make lint
make test
make build-src
```

`make test` runs the PHPUnit integration and architecture suites against the
local WordPress Core checkout in an isolated one-shot container. It never uses
the live development database. Compose creates one fixed `wordpress_tests`
database in a `test-db` tmpfs and removes both test containers after every run,
including failed runs. No test database volume is retained.

Use `make fix` for repository-owned automatic formatting fixes. Behavior changes
must also be verified through the affected WordPress admin flow as described in
[CONTRIBUTING.md](../CONTRIBUTING.md).

## Continuous Integration

GitHub Actions validates PHP 7.4 and PHP 8.2 compatibility, frontend lint and
type safety, the WordPress integration suite, and the Docker Compose
configuration. CI runs for pull requests and pushes to the default branch.

The manually dispatched `Create Release Zip` workflow can build the production
archive as a downloadable artifact. Artifact creation does not authorize or
publish a release; publishing remains governed by
[RELEASE_POLICY.md](RELEASE_POLICY.md).

## Local State

Do not commit `.env`, credentials, database backups, uploads, debug logs,
generated certificates, `vendor/`, `node_modules/`, `wp/`, `release/` contents,
or generated `assets/` files.
