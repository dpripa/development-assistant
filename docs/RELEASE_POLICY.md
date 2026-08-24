# Release Policy

This document is an operational reference exclusively for verified release
maintainers. It is not a release procedure for general contributors. A release
maintainer must be a repository administrator or a specifically approved user
with release authority. All other contributors submit changes through pull
requests and must not be granted repository permissions that allow releases to
be created.

## Authorization Boundary

GitHub repository permissions and active branch and tag rulesets are the
authorization boundary. GitHub release management requires `Contents: write`,
so that permission is reserved for administrators and explicitly approved
release managers.

The default branch accepts changes through its active protection rules. The
active `Protect release tags` ruleset applies to every tag and permits tag
creation, updates, and deletion only through its bypass list. An approved
non-admin release manager needs both the required repository permission and an
explicit ruleset bypass entry.

Release scripts provide consistency checks and exact confirmation prompts, but
they are not an authorization mechanism and must not be treated as a substitute
for GitHub access control. WordPress.org credentials are issued only to verified
release maintainers and must never be shared with general contributors.

## Package Boundary

`.distignore` defines the production package boundary. The release archive is
built with a pinned WP-CLI `dist-archive` command in Docker so Docker files,
frontend source, local state, and development tooling are not shipped to plugin
users.

Create the archive manually when it needs to be inspected independently:

```sh
make create-release-zip
```

The GitHub and WordPress.org release commands build and verify the archive as
part of their own workflows.

## Version Source of Truth

The tracked `.version` file is the release-version source of truth. Set the next
unused version there and run:

```sh
make version-sync
```

The synchronization command refuses versions that already exist as a Git tag,
in the local WordPress.org tags working copy, in published WordPress.org SVN
tags, or as an older changelog entry. It synchronizes the plugin header,
`readme.txt` Stable tag, `package.json`, `package-lock.json`, `composer.json`, and
Composer lock metadata. It also inserts a matching `readme.txt` changelog
template.

Replace the generated `TODO` text, review the synchronized files, and commit the
version and completed changelog before publishing.

## GitHub Releases

After committing and pushing the release commit, run:

```sh
make github-release
```

The command builds the production ZIP, requires a clean working tree, and
verifies that the local commit exactly matches its corresponding remote branch.
It displays the release channel, branch, commit range, changelog-derived notes,
asset size, and SHA-256 digest. Publishing continues only after the maintainer
types the exact version-specific confirmation.

The command then creates and pushes an annotated version tag, creates a
recoverable draft GitHub Release, uploads the ZIP, downloads it again to verify
its digest, and publishes the verified draft.

If publishing is interrupted after the tag or draft is created, rerun `make
github-release`. The command resumes only when the existing tag points to the
expected release commit and the existing GitHub Release is still a draft. It
rejects an inconsistent tag or an already published version.

SemVer prereleases such as `2.0.0-beta.1` and `2.0.0-rc.1` may originate from
any pushed branch. They are published as GitHub prereleases and are never marked
as the latest release. Stable versions such as `2.0.0` require the pushed
default branch and are marked as latest.

## WordPress.org Releases

The ignored `release/wporg/` directory is the local working copy of the official
WordPress.org SVN repository. The tracked `release/.gitkeep` reserves this local
release workspace, while generated archives and the SVN checkout remain
ignored.

Check out the working copy once, then update it before release work:

```sh
make wporg-checkout
make wporg-update
```

Set the case-sensitive WordPress.org username and a dedicated SVN password in
the ignored `.env` file. Never use the main WordPress.org account password:

```dotenv
WPORG_SVN_USERNAME='your-wordpress-org-username'
WPORG_SVN_PASSWORD='your-dedicated-svn-password'
```

Build, prepare, review, and publish a stable plugin release through one
interactive command:

```sh
make wporg-release
```

The command reads `.version`, verifies all synchronized metadata and the
completed changelog, synchronizes the production ZIP into SVN `trunk/`, and
creates `tags/<version>` from that local trunk. It prints the complete SVN diff
and commits only after the maintainer types the exact `publish <version>`
confirmation.

Cancelling leaves the prepared changes available through `make wporg-status`
and `make wporg-diff`. Rerunning `make wporg-release` verifies and reuses the
prepared state. WordPress.org publishing rejects prerelease versions and release
commits that are not on the pushed default branch.

The SVN password is passed through standard input, is not included in process
arguments, and is not stored in the SVN authentication cache. If publishing is
moved to CI, credentials must be stored as encrypted CI secrets.

## WordPress.org Directory Assets

The tracked `wporg/assets/` directory is the source of truth for WordPress.org
banners, icons, screenshots, and `blueprint.json`. Publish reviewed changes with:

```sh
make wporg-assets-publish confirm=publish
```

The command synchronizes the tracked source directory into the local SVN
`assets/` directory, removes stale SVN assets, excludes `.DS_Store`, and commits
only the resulting directory-asset changes. No manual copy into
`release/wporg/assets/` is required.
