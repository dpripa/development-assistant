#!/bin/sh
set -eu

project_root="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
release_dir="$project_root/release"
wporg_dir="$release_dir/wporg"
archive="$release_dir/development-assistant.zip"

if [ -f "$project_root/.env" ]; then
  # shellcheck disable=SC1091
  . "$project_root/.env"
fi

plugin_slug="${WPORG_PLUGIN_SLUG:-development-assistant}"
svn_url="${WPORG_SVN_URL:-https://plugins.svn.wordpress.org/$plugin_slug}"
svn_username="${WPORG_SVN_USERNAME:-}"
svn_password="${WPORG_SVN_PASSWORD:-}"
unset WPORG_SVN_USERNAME WPORG_SVN_PASSWORD

usage() {
  cat <<'EOF'
Usage: scripts/wporg-release.sh COMMAND

Commands:
  checkout          Check out the WordPress.org SVN repository.
  update            Update a clean local SVN working copy.
  prepare           Sync the release ZIP to trunk and create a local tag.
  status            Show local WordPress.org SVN changes.
  diff              Show the local WordPress.org SVN diff.
  publish           Commit the prepared trunk and tag to WordPress.org.
  publish-assets    Commit changes from the top-level SVN assets directory.
EOF
}

fail() {
  echo "Error: $*" >&2
  exit 1
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || fail "$1 is required for the WordPress.org release workflow."
}

require_working_copy() {
  [ -d "$wporg_dir/.svn" ] || fail "WordPress.org SVN is not checked out. Run 'make wporg-checkout' first."

  working_copy_url="$(svn info "$wporg_dir" | sed -n 's/^URL: //p')"
  [ "${working_copy_url%/}" = "${svn_url%/}" ] || fail "The SVN working copy URL is '$working_copy_url'; expected '$svn_url'."
}

require_clean_svn() {
  changes="$(svn status "$wporg_dir")"
  [ -z "$changes" ] || fail "The WordPress.org SVN working copy has local changes. Review or revert them first."
}

require_clean_git() {
  changes="$(git -C "$project_root" status --porcelain --untracked-files=normal)"
  [ -z "$changes" ] || fail "The Git working tree must be clean before preparing a WordPress.org release."
}

load_version() {
  version_file="$project_root/.version"
  [ -f "$version_file" ] || fail ".version is missing. Run 'make version-sync' before preparing a release."
  version="$(cat "$version_file")"
  [ -n "$version" ] || fail ".version is empty."

  case "$version" in
    [0-9]*)
      ;;
    *)
      fail "Invalid release version: $version"
      ;;
  esac

  case "$version" in
    *[!0-9A-Za-z.-]*|*..*|*.)
      fail "Invalid release version: $version"
      ;;
  esac
}

verify_changelog() {
  plugin_root="$1"
  expected="$2"
  label="$3"
  changelog_entry="$(awk -v heading="= $expected =" '
    $0 == heading { found = 1; next }
    found && /^= .* =$/ { exit }
    found { print }
  ' "$plugin_root/readme.txt")"

  [ -n "$changelog_entry" ] || fail "$label readme.txt does not contain a changelog entry for $expected."
  if printf '%s\n' "$changelog_entry" | grep -Fq 'TODO:'; then
    fail "$label readme.txt changelog for $expected still contains the generated TODO placeholder."
  fi
}

validate_configuration() {
  case "$plugin_slug" in
    ''|*[!a-z0-9-]*)
      fail "Invalid WordPress.org plugin slug: $plugin_slug"
      ;;
  esac
}

read_json_version() {
  awk -F '"' '/^[[:space:]]*"version"[[:space:]]*:/ { print $4; exit }' "$1"
}

assert_version_file() {
  label="$1"
  actual="$2"
  expected="$3"

  [ "$actual" = "$expected" ] || fail "$label version is '$actual'; expected '$expected'."
}

verify_source_versions() {
  expected="$1"

  verify_plugin_versions "$project_root" "$expected" "Source"

  assert_version_file "package.json" "$(read_json_version "$project_root/package.json")" "$expected"
  assert_version_file "package-lock.json" "$(read_json_version "$project_root/package-lock.json")" "$expected"
  assert_version_file "composer.json" "$(read_json_version "$project_root/composer.json")" "$expected"
}

verify_plugin_versions() {
  plugin_root="$1"
  expected="$2"
  label="$3"
  plugin_version="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "$plugin_root/development-assistant.php" | head -n 1 | tr -d '\r')"
  stable_tag="$(sed -n 's/^Stable tag:[[:space:]]*//p' "$plugin_root/readme.txt" | head -n 1 | tr -d '\r')"

  assert_version_file "$label plugin header" "$plugin_version" "$expected"
  assert_version_file "$label readme.txt Stable tag" "$stable_tag" "$expected"
  verify_changelog "$plugin_root" "$expected" "$label"
}

schedule_changes() {
  target="$1"

  svn add --force "$target" >/dev/null
  svn status "$target" | while IFS= read -r line; do
    case "$line" in
      !*)
        missing_path="$(printf '%s\n' "$line" | cut -c9-)"
        svn rm --force "$missing_path" >/dev/null
        ;;
    esac
  done
}

require_auth() {
  [ -n "$svn_username" ] || fail "WPORG_SVN_USERNAME is not set in .env."
  [ -n "$svn_password" ] || fail "WPORG_SVN_PASSWORD is not set in .env."
  svn help commit -v | grep -q -- '--password-from-stdin' || fail "This SVN client does not support --password-from-stdin."
}

require_publish_confirmation() {
  [ "${WPORG_CONFIRM:-}" = "publish" ] || fail "Publishing is live. Re-run with confirm=publish after reviewing 'make wporg-diff'."
}

svn_commit() {
  message="$1"
  shift

  printf '%s\n' "$svn_password" | svn commit "$@" \
    --message "$message" \
    --username "$svn_username" \
    --password-from-stdin \
    --no-auth-cache \
    --non-interactive
}

checkout_wporg() {
  require_command svn
  mkdir -p "$release_dir"

  if [ -d "$wporg_dir/.svn" ]; then
    fail "WordPress.org SVN is already checked out at $wporg_dir."
  fi

  if [ -e "$wporg_dir" ] && [ -n "$(find "$wporg_dir" -mindepth 1 -maxdepth 1 -print -quit)" ]; then
    fail "$wporg_dir exists and is not empty."
  fi

  svn checkout --quiet "$svn_url" "$wporg_dir"
  echo "Checked out WordPress.org SVN at $wporg_dir."
}

update_wporg() {
  require_command svn
  require_working_copy
  require_clean_svn
  svn update "$wporg_dir"
}

prepare_release() {
  require_command git
  require_command rsync
  require_command svn
  require_command unzip
  load_version
  require_working_copy
  require_clean_git
  require_clean_svn
  verify_source_versions "$version"
  [ -f "$archive" ] || fail "Release archive not found: $archive"

  svn update "$wporg_dir"

  remote_tags="$(svn list "$svn_url/tags")" || fail "Could not read WordPress.org tags from $svn_url."
  if printf '%s\n' "$remote_tags" | grep -Fqx "$version/"; then
    fail "WordPress.org tag $version already exists."
  fi

  temp_dir="$(mktemp -d "${TMPDIR:-/tmp}/development-assistant-wporg.XXXXXX")"
  trap 'rm -rf "$temp_dir"' EXIT INT TERM

  unzip -q "$archive" -d "$temp_dir"
  payload_dir="$temp_dir/$plugin_slug"
  [ -f "$payload_dir/development-assistant.php" ] || fail "The release archive does not contain the expected plugin root."

  rsync -a --delete "$payload_dir/" "$wporg_dir/trunk/"
  schedule_changes "$wporg_dir/trunk"

  tag_dir="$wporg_dir/tags/$version"
  [ ! -e "$tag_dir" ] || fail "Local WordPress.org tag path already exists: $tag_dir"
  svn copy "$wporg_dir/trunk" "$tag_dir"

  echo "Prepared WordPress.org release $version."
  echo "Review it with 'make wporg-status' and 'make wporg-diff'."
  echo "Publish it with 'make wporg-publish confirm=publish'."
  svn status "$wporg_dir"
}

show_status() {
  require_command svn
  require_working_copy
  svn status "$wporg_dir"
}

show_diff() {
  require_command svn
  require_working_copy
  svn diff "$wporg_dir"
}

publish_release() {
  require_command svn
  load_version
  require_working_copy
  require_publish_confirmation
  require_auth
  require_clean_git
  verify_source_versions "$version"

  tag_dir="$wporg_dir/tags/$version"
  [ -d "$tag_dir" ] || fail "Prepared tag not found: $tag_dir"
  [ "$(svn status "$tag_dir" | sed -n '1s/^\(.\).*/\1/p')" = "A" ] || fail "Tag $version is not scheduled for addition. Run 'make wporg-prepare' first."
  verify_plugin_versions "$wporg_dir/trunk" "$version" "SVN trunk"
  verify_plugin_versions "$tag_dir" "$version" "SVN tag"

  changes="$(svn status "$wporg_dir/trunk" "$tag_dir")"
  [ -n "$changes" ] || fail "There are no prepared release changes to publish."

  unexpected="$(svn status "$wporg_dir" | awk -v trunk="$wporg_dir/trunk" -v tag="$tag_dir" '{ path = substr($0, 9); in_trunk = (path == trunk || index(path, trunk "/") == 1); in_tag = (path == tag || index(path, tag "/") == 1); if (!in_trunk && !in_tag) print $0 }')"
  [ -z "$unexpected" ] || fail "The SVN working copy contains changes outside trunk and tags/$version."

  svn_commit "Release $plugin_slug $version" "$wporg_dir/trunk" "$tag_dir"
  svn update "$wporg_dir"
}

publish_assets() {
  require_command svn
  require_working_copy
  require_publish_confirmation
  require_auth

  assets_dir="$wporg_dir/assets"
  [ -d "$assets_dir" ] || fail "WordPress.org assets directory not found: $assets_dir"
  schedule_changes "$assets_dir"
  changes="$(svn status "$assets_dir")"
  [ -n "$changes" ] || fail "There are no WordPress.org asset changes to publish."

  svn_commit "Update $plugin_slug directory assets" "$assets_dir"
  svn update "$assets_dir"
}

command_name="${1:-}"
validate_configuration

case "$command_name" in
  checkout)
    checkout_wporg
    ;;
  update)
    update_wporg
    ;;
  prepare)
    [ "$#" -eq 1 ] || fail "prepare reads the release version from .version and does not accept a VERSION argument."
    prepare_release
    ;;
  status)
    show_status
    ;;
  diff)
    show_diff
    ;;
  publish)
    [ "$#" -eq 1 ] || fail "publish reads the release version from .version and does not accept a VERSION argument."
    publish_release
    ;;
  publish-assets)
    publish_assets
    ;;
  *)
    usage >&2
    exit 1
    ;;
esac
