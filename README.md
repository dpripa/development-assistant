# Development Assistant

Development Assistant is an open-source WordPress plugin for debugging and
customer-support workflows. This repository contains the plugin source, build
tooling, release workflow, and a complete local WordPress environment.

## Requirements

- Docker with Docker Compose
- `mkcert`
- NVM with Node.js 24
- Subversion, `rsync`, and `unzip` for publishing to WordPress.org

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
assets, generates a trusted local certificate, and downloads WordPress into the
local `wp/` runtime directory.

Start the local WordPress stack:

```sh
make up
```

The complete WordPress runtime is stored in `wp/`, including installed plugins,
themes, uploads, and logs. The current checkout is exposed at the standard
`wp/wp-content/plugins/development-assistant` path through a relative symlink.
No sibling repositories or global WordPress directories are required.

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
the ignored local `wp/` directory and are not committed. `make wp-core-update`
updates WordPress Core without replacing `wp-content`, `wp-config.php`, or other
local runtime configuration.

`make start-watch` keeps Vite running and rebuilds plugin assets when frontend
source files change.

## Build And Release

Build production and non-minified assets:

```sh
make build-src
```

Frontend source is organized by WordPress admin feature under `src/`. Vite
compiles strict TypeScript and plain CSS into the stable classic-script and
stylesheet filenames expected by the plugin's PHP asset loader.

Create a release archive:

```sh
make create-release-zip
```

The `.distignore` file controls which files are excluded from the ZIP. The
archive is built with a pinned WP-CLI `dist-archive` command in Docker, so Docker
files, source assets, local state, and development tooling are not shipped to
WordPress.org users.

GitHub Actions can create the release ZIP. If WordPress.org publishing is later
run in CI, its credentials must be stored as encrypted CI secrets and must never
be committed to this repository.

### WordPress.org SVN

The tracked `wporg/assets/` directory is the source of truth for WordPress.org
directory banners, icons, screenshots, and `blueprint.json`. The ignored
`release/wporg/` directory is the local working copy of the official
WordPress.org SVN repository. Plugin files are synchronized from the release ZIP
into its `trunk/`. The tracked `release/.gitkeep` reserves this local release
workspace, while generated archives and the SVN checkout remain ignored.

Check out or update the working copy:

```sh
make wporg-checkout
make wporg-update
```

Set the case-sensitive WordPress.org username and the dedicated SVN password in
the ignored `.env` file. Never use the main WordPress.org account password:

```dotenv
WPORG_SVN_USERNAME='your-wordpress-org-username'
WPORG_SVN_PASSWORD='your-dedicated-svn-password'
```

Prepare a release locally, inspect the exact SVN changes, and publish only after
reviewing them:

```sh
make wporg-prepare version=1.2.11
make wporg-status
make wporg-diff
make wporg-publish version=1.2.11 confirm=publish
```

Preparation verifies that the plugin header, `readme.txt`, `package.json`,
`package-lock.json`, and `composer.json` contain the requested version. It then
syncs the production ZIP into `trunk/` and creates `tags/<version>` from that
local trunk. Only the explicit publish command commits to WordPress.org.

Directory banners, icons, screenshots, and `blueprint.json` are maintained under
`wporg/assets/`. Copy reviewed changes into `release/wporg/assets/`, inspect the
SVN diff, and publish them separately:

```sh
make wporg-assets-publish confirm=publish
```

The SVN password is passed to the client through standard input, is not included
in the process arguments, and is not stored in the SVN authentication cache. In
CI, use the CI provider's encrypted secrets instead of creating a `.env` file.
