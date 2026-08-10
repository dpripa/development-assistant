# Development Assistant

Development Assistant is an open-source WordPress plugin for debugging and
customer-support workflows. This repository contains the plugin source, build
tooling, release workflow, and a complete local WordPress environment.

## Requirements

- Docker with Docker Compose
- `mkcert`
- NVM with Node.js 14

Composer runs through Docker, so a host PHP or Composer installation is not
required for the standard setup.

## Local Setup

Clone the repository and initialize its dependencies:

```sh
git clone https://github.com/dpripa/development-assistant.git
cd development-assistant
make setup
```

`make setup` creates `.env`, installs Composer and npm dependencies, builds
assets, generates a trusted local certificate, and downloads a WordPress core
mirror for IDE indexing.

Start the local WordPress stack:

```sh
make up
```

The current checkout is mounted directly as the active
`development-assistant` plugin. No sibling repositories or global WordPress
directories are required.

Local services:

- WordPress: `https://localhost:21601`
- Adminer: `http://localhost:21602`
- Mailpit: `http://localhost:21603`

WordPress is installed automatically on first startup. The admin credentials,
ports, required helper plugins, and Xdebug settings can be changed in `.env`.

## Development Commands

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
make start-watch
make wp-core-update
make lint
make fix
```

`make reinit` creates a database backup before replacing the local database
volume. WordPress files, uploads, themes, and installed helper plugins remain in
Docker-managed local state and are not committed.

## Build And Release

Build production and non-minified assets:

```sh
make build-src
```

Create a release archive:

```sh
make create-release-zip
```

The release whitelist in `package.json` controls which plugin files are included
in the ZIP. Docker files, source assets, local state, and development tooling are
not shipped to WordPress.org users.

GitHub Actions can create the release ZIP and run the existing deployment
workflows. Deployment credentials must be stored as GitHub Actions secrets and
must never be committed to this repository.
