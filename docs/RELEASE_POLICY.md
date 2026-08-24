# Release Policy

This document is an operational reference exclusively for verified release
maintainers. It is not a release procedure for general contributors. A release
maintainer must be a repository administrator or a specifically approved user
with release authority. All other contributors submit changes through pull
requests and must not be granted repository permissions that allow releases to
be created. Because GitHub release management requires `Contents: write`, that
permission is reserved for administrators and explicitly approved release
managers.

## Authorization Boundary

GitHub repository permissions and active branch and tag rulesets are the
authorization boundary. The default branch accepts changes through the protected
pull-request flow. The active `Protect release tags` ruleset applies to every tag
and allows tag creation, updates, and deletion only through its bypass list,
which contains repository administrators and may contain individually approved
release managers. An approved non-admin release manager needs both the required
repository permission and an explicit ruleset bypass entry.

The release scripts provide consistency checks and exact confirmation prompts,
but they are not an authorization mechanism and must not be treated as a
substitute for GitHub access control.

## Package Boundary

`.distignore` defines the production package boundary. `make
create-release-zip` installs production dependencies, runs required checks,
builds assets, and creates the WordPress plugin archive. WordPress.org SVN state
under `release/wporg/` is local and ignored; reviewed directory media originates
from `wporg/assets/`.

## Versioning and Publishing

The tracked `.version` file is the release-version source of truth. `make
version-sync` verifies that the number was not released before, synchronizes the
plugin header, `readme.txt`, Composer and npm metadata plus lock files, and adds
a changelog template.

The interactive `make github-release` command verifies the pushed branch commit,
creates a recoverable draft release, verifies its uploaded ZIP by digest, and
publishes it only after exact version-specific confirmation. SemVer prereleases
may originate from any pushed branch and remain GitHub prereleases; stable
GitHub releases require the default branch.

The interactive `make wporg-release` command reads `.version`, rejects
inconsistent metadata or an unfinished changelog, prepares the local SVN trunk
and tag, prints their complete diff, and requires an exact version-specific
confirmation before publishing. It rejects prerelease versions and non-default
branches. WordPress.org credentials are issued only to verified release
maintainers and must never be shared with general contributors.
