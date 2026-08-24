# Development Assistant

Development Assistant is an open-source WordPress plugin for debugging and
customer-support workflows. This repository contains the plugin source, build
tooling, release workflow, and a complete local WordPress environment.

[WordPress.org](https://wordpress.org/plugins/development-assistant/) ·
[Support](SUPPORT.md) · [Contributing](CONTRIBUTING.md) ·
[Security](SECURITY.md)

## Installation

Install Development Assistant from the WordPress admin plugin directory, or
download it from the official
[WordPress.org plugin page](https://wordpress.org/plugins/development-assistant/).

The remaining instructions describe development from a source checkout.

## Development Requirements

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
make test
make fix
```

`make reinit` creates a database backup before replacing the local database
volume. WordPress files, uploads, themes, and installed helper plugins remain in
the ignored local `wp/` directory and are not committed. `make wp-core-update`
updates WordPress Core without replacing `wp-content`, `wp-config.php`, or other
local runtime configuration.

`make start-watch` keeps Vite running and rebuilds plugin assets when frontend
source files change.

`make test` runs the PHPUnit integration and architecture suites against the
local WordPress core in an isolated one-shot container. It never uses the live
development database. Compose creates one fixed `wordpress_tests` database in
a `test-db` tmpfs, then removes both the test runner and test database container
after every run, including failed runs. No test database volume is created or
retained.

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

### GitHub Release

After synchronizing the version, completing the changelog, committing, and
pushing the release commit to the default branch, create the GitHub Release:

```sh
make github-release
```

The command builds the production ZIP, verifies that the clean local commit is
exactly the commit published on its corresponding remote branch, and displays the
commit range, changelog-derived release notes, asset size, and SHA-256 digest.
It continues only after the maintainer types the exact `publish <version>`
confirmation. It then creates and pushes an annotated version tag, prepares a
draft GitHub Release, uploads the ZIP, downloads it again to verify its digest,
and finally publishes the verified draft as the latest release.

If publishing is interrupted after the tag or draft was created, rerun `make
github-release`. The command continues only when the existing tag points to the
expected release commit and the existing Release is still a draft. It refuses
an inconsistent tag or an already published version.

Versions with a SemVer prerelease suffix, such as `2.0.0-beta.1` or
`2.0.0-rc.1`, may be released from any pushed branch. They are published as
GitHub prereleases and never marked as the latest release. Stable versions such
as `2.0.0` can be released only from the pushed default branch and are marked as
latest.

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

Set the next unused release version in the tracked `.version` file, then
synchronize every version-bearing source file:

```sh
make version-sync
```

The synchronization command refuses versions that already exist as a Git tag,
in the local WordPress.org tags working copy, in the published WordPress.org
SVN tags, or as an older changelog entry. It updates the plugin header,
`readme.txt` Stable tag, `package.json`, `package-lock.json`, `composer.json`,
and Composer lock metadata. It also inserts a new `readme.txt` changelog
template. Replace its `TODO` text, review the synchronized files, and commit the
release version before preparing the WordPress.org working copy.

Build, prepare, review, and publish the release through one interactive command:

```sh
make wporg-release
```

Preparation reads the version from `.version`, verifies that every synchronized
file contains it, and rejects an unfilled changelog template. It then syncs the
production ZIP into `trunk/` and creates `tags/<version>` from that local trunk.
The command prints the complete SVN diff and commits only after the maintainer
types the exact `publish <version>` confirmation. Cancelling leaves the prepared
changes available for `make wporg-status` and `make wporg-diff`; rerunning `make
wporg-release` verifies and reuses that prepared state.

WordPress.org publishing accepts only stable SemVer versions from the pushed
default branch. Beta, release-candidate, and other prerelease versions remain
GitHub-only.

Directory banners, icons, screenshots, and `blueprint.json` are maintained under
`wporg/assets/`. Publish reviewed changes separately:

```sh
make wporg-assets-publish confirm=publish
```

The command synchronizes the tracked source directory into the local SVN
`assets/` directory, removes stale SVN assets, excludes `.DS_Store`, and commits
the resulting asset-only changes. No manual copy into `release/wporg/assets/` is
required.

The SVN password is passed to the client through standard input, is not included
in the process arguments, and is not stored in the SVN authentication cache. In
CI, use the CI provider's encrypted secrets instead of creating a `.env` file.

## Project Policies

- Read [CONTRIBUTING.md](CONTRIBUTING.md) before proposing a change.
- Use [SUPPORT.md](SUPPORT.md) to choose between user support and bug reports.
- Report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).
- Community participation is governed by [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).
- See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for repository and runtime boundaries.
- Verified release maintainers must follow [docs/RELEASE_POLICY.md](docs/RELEASE_POLICY.md).
