#!/bin/sh
set -eu

project_root="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
archive="$project_root/release/development-assistant.zip"
plugin_slug="development-assistant"
asset_name="development-assistant.zip"
remote_name="origin"

fail() {
  echo "Error: $*" >&2
  exit 1
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || fail "$1 is required for the GitHub release workflow."
}

sha256_file() {
  if command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$1" | awk '{ print $1 }'
  elif command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | awk '{ print $1 }'
  else
    fail "shasum or sha256sum is required for release asset verification."
  fi
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

require_clean_git() {
  changes="$(git -C "$project_root" status --porcelain --untracked-files=normal)"
  [ -z "$changes" ] || fail "The Git working tree must be clean before creating a GitHub release."
}

verify_branch_is_pushed() {
  branch="$(git -C "$project_root" symbolic-ref --quiet --short HEAD)" || fail "GitHub releases must be created from a branch, not detached HEAD."
  default_remote_ref="$(git -C "$project_root" symbolic-ref --quiet --short "refs/remotes/$remote_name/HEAD")" || fail "Could not determine the default branch for $remote_name."
  default_branch="${default_remote_ref#${remote_name}/}"
  [ "$branch" = "$default_branch" ] || fail "GitHub releases must be created from the default branch '$default_branch'; current branch is '$branch'."

  commit="$(git -C "$project_root" rev-parse HEAD)"
  remote_commit="$(git -C "$project_root" rev-parse "$remote_name/$default_branch")"
  [ "$commit" = "$remote_commit" ] || fail "HEAD is not the commit currently published at $remote_name/$default_branch. Push the release commit first."
}

verify_archive() {
  [ -f "$archive" ] || fail "Release archive not found: $archive"
  unzip -tq "$archive" >/dev/null || fail "Release archive is invalid: $archive"

  unexpected_root="$(unzip -Z1 "$archive" | awk -F/ -v root="$plugin_slug" '$1 != root { print; exit }')"
  [ -z "$unexpected_root" ] || fail "Release archive contains an unexpected top-level path: $unexpected_root"

  archive_dir="$temp_dir/archive"
  mkdir -p "$archive_dir"
  unzip -q "$archive" -d "$archive_dir"
  payload_dir="$archive_dir/$plugin_slug"
  [ -f "$payload_dir/development-assistant.php" ] || fail "Release archive does not contain the plugin root file."
  [ -f "$payload_dir/readme.txt" ] || fail "Release archive does not contain readme.txt."

  cmp -s "$project_root/development-assistant.php" "$payload_dir/development-assistant.php" || fail "Release archive plugin header does not match the source tree."
  cmp -s "$project_root/readme.txt" "$payload_dir/readme.txt" || fail "Release archive readme.txt does not match the source tree."

  plugin_version="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "$payload_dir/development-assistant.php" | head -n 1 | tr -d '\r')"
  stable_tag="$(sed -n 's/^Stable tag:[[:space:]]*//p' "$payload_dir/readme.txt" | head -n 1 | tr -d '\r')"
  [ "$plugin_version" = "$version" ] || fail "Release archive plugin version is '$plugin_version'; expected '$version'."
  [ "$stable_tag" = "$version" ] || fail "Release archive Stable tag is '$stable_tag'; expected '$version'."
}

prepare_release_notes() {
  notes_file="$temp_dir/release-notes.md"
  awk -v heading="= $version =" '
    $0 == heading { found = 1; next }
    found && /^= .* =$/ { exit }
    found { print }
  ' "$project_root/readme.txt" | sed '/./,$!d' > "$notes_file"

  [ -s "$notes_file" ] || fail "readme.txt does not contain release notes for $version."
  ! grep -Fq 'TODO:' "$notes_file" || fail "readme.txt release notes for $version still contain the generated TODO placeholder."
}

inspect_existing_state() {
  local_tag_exists=false
  if git -C "$project_root" rev-parse --verify --quiet "refs/tags/$version" >/dev/null; then
    local_tag_exists=true
    local_tag_object="$(git -C "$project_root" rev-parse "refs/tags/$version")"
    local_tag_commit="$(git -C "$project_root" rev-list -n 1 "refs/tags/$version")"
    [ "$local_tag_commit" = "$commit" ] || fail "Local tag $version points to $local_tag_commit instead of release commit $commit."
  fi

  remote_tag_object="$(git -C "$project_root" ls-remote "$remote_name" "refs/tags/$version" | awk 'NR == 1 { print $1 }')"
  remote_tag_exists=false
  if [ -n "$remote_tag_object" ]; then
    remote_tag_exists=true
    remote_tag_commit="$(git -C "$project_root" ls-remote "$remote_name" "refs/tags/$version^{}" | awk 'NR == 1 { print $1 }')"
    if [ -z "$remote_tag_commit" ]; then
      remote_tag_commit="$remote_tag_object"
    fi
    [ "$remote_tag_commit" = "$commit" ] || fail "Remote tag $version points to $remote_tag_commit instead of release commit $commit."

    if [ "$local_tag_exists" = "true" ]; then
      [ "$local_tag_object" = "$remote_tag_object" ] || fail "Local and remote tag $version have different tag objects."
    else
      git -C "$project_root" fetch --quiet "$remote_name" "refs/tags/$version:refs/tags/$version"
      local_tag_exists=true
      local_tag_object="$(git -C "$project_root" rev-parse "refs/tags/$version")"
      [ "$local_tag_object" = "$remote_tag_object" ] || fail "Could not reproduce remote tag $version locally."
    fi
  fi

  release_exists=false
  release_draft=false
  release_error="$temp_dir/release-view-error"
  if gh release view "$version" --repo "$repository" --json isDraft,tagName > "$temp_dir/release.json" 2> "$release_error"; then
    release_exists=true
    release_tag="$(gh release view "$version" --repo "$repository" --json tagName --jq .tagName)"
    [ "$release_tag" = "$version" ] || fail "GitHub Release tag is '$release_tag'; expected '$version'."
    release_draft="$(gh release view "$version" --repo "$repository" --json isDraft --jq .isDraft)"
    [ "$release_draft" = "true" ] || fail "GitHub Release $version is already published."
    [ "$remote_tag_exists" = "true" ] || fail "Draft GitHub Release $version exists without the expected remote Git tag."
  elif ! grep -Fq 'release not found' "$release_error"; then
    cat "$release_error" >&2
    fail "Could not inspect GitHub Release $version."
  fi
}

show_release_plan() {
  previous_tag="$(git -C "$project_root" describe --tags --abbrev=0 "$commit^" 2>/dev/null || true)"
  archive_size="$(wc -c < "$archive" | tr -d ' ')"
  archive_sha256="$(sha256_file "$archive")"

  echo "GitHub release plan"
  echo "  Repository: $repository"
  echo "  Version:    $version"
  echo "  Commit:     $commit"
  echo "  Subject:    $(git -C "$project_root" log -1 --format=%s "$commit")"
  echo "  Asset:      $asset_name ($archive_size bytes)"
  echo "  SHA-256:    $archive_sha256"
  if [ "$remote_tag_exists" = "true" ]; then
    echo "  Tag:        reuse verified remote tag"
  elif [ "$local_tag_exists" = "true" ]; then
    echo "  Tag:        push verified local annotated tag"
  else
    echo "  Tag:        create and push annotated tag"
  fi
  if [ "$release_exists" = "true" ]; then
    echo "  Release:    resume verified draft"
  else
    echo "  Release:    create draft, verify, then publish"
  fi

  echo
  if [ -n "$previous_tag" ]; then
    echo "Commits since $previous_tag:"
    git -C "$project_root" log --oneline "$previous_tag..$commit"
  else
    echo "Release commit:"
    git -C "$project_root" log -1 --oneline "$commit"
  fi

  echo
  echo "Release notes:"
  cat "$notes_file"
  echo
}

ensure_remote_tag() {
  if [ "$local_tag_exists" = "false" ]; then
    git -C "$project_root" tag -a "$version" "$commit" -m "Release $version"
    local_tag_exists=true
  fi

  if [ "$remote_tag_exists" = "false" ]; then
    git -C "$project_root" push "$remote_name" "refs/tags/$version"
    remote_tag_exists=true
  fi
}

ensure_draft_release() {
  if [ "$release_exists" = "false" ]; then
    gh release create "$version" \
      --repo "$repository" \
      --verify-tag \
      --draft \
      --title "$version" \
      --notes-file "$notes_file"
    release_exists=true
  else
    gh release edit "$version" \
      --repo "$repository" \
      --verify-tag \
      --draft \
      --title "$version" \
      --notes-file "$notes_file" >/dev/null
  fi
}

verify_draft_release() {
  draft_state="$(gh release view "$version" --repo "$repository" --json isDraft --jq .isDraft)"
  [ "$draft_state" = "true" ] || fail "GitHub Release $version is not a draft."

  release_name="$(gh release view "$version" --repo "$repository" --json name --jq .name)"
  [ "$release_name" = "$version" ] || fail "Draft GitHub Release title is '$release_name'; expected '$version'."

  release_body="$(gh release view "$version" --repo "$repository" --json body --jq .body)"
  expected_body="$(cat "$notes_file")"
  [ "$release_body" = "$expected_body" ] || fail "Draft GitHub Release notes do not match readme.txt."
}

upload_and_verify_asset() {
  gh release upload "$version" "$archive#$asset_name" --repo "$repository" --clobber

  download_dir="$temp_dir/download"
  mkdir -p "$download_dir"
  gh release download "$version" --repo "$repository" --pattern "$asset_name" --dir "$download_dir" --clobber
  downloaded_archive="$download_dir/$asset_name"
  [ -f "$downloaded_archive" ] || fail "GitHub Release asset could not be downloaded for verification."

  downloaded_sha256="$(sha256_file "$downloaded_archive")"
  [ "$downloaded_sha256" = "$archive_sha256" ] || fail "Downloaded GitHub Release asset does not match the local release archive."
}

publish_release() {
  gh release edit "$version" --repo "$repository" --draft=false --latest >/dev/null
  published_state="$(gh release view "$version" --repo "$repository" --json isDraft --jq .isDraft)"
  [ "$published_state" = "false" ] || fail "GitHub Release $version was not published successfully."
  gh release view "$version" --repo "$repository" --json url --jq .url
}

require_command cmp
require_command gh
require_command git
require_command node
require_command sed
require_command unzip

[ "$#" -eq 0 ] || fail "github-release does not accept arguments; it reads the version from .version."
[ -t 0 ] && [ -t 1 ] || fail "GitHub publishing requires an interactive terminal to review and confirm the release."

temp_dir="$(mktemp -d "${TMPDIR:-/tmp}/development-assistant-github-release.XXXXXX")"
trap 'rm -rf "$temp_dir"' EXIT INT TERM

load_version
require_clean_git
node "$project_root/scripts/sync-version.mjs" --check
verify_archive
prepare_release_notes

gh auth status >/dev/null 2>&1 || fail "GitHub CLI is not authenticated. Run 'gh auth login' first."
repository="$(gh repo view --json nameWithOwner --jq .nameWithOwner)"
[ -n "$repository" ] || fail "Could not determine the GitHub repository."

git -C "$project_root" fetch --quiet --no-tags "$remote_name"
verify_branch_is_pushed
inspect_existing_state
show_release_plan

printf 'Type "publish %s" to create this GitHub release: ' "$version"
confirmation=""
if ! IFS= read -r confirmation; then
  confirmation=""
fi

if [ "$confirmation" != "publish $version" ]; then
  echo "Publishing cancelled. No new Git tag or GitHub Release changes were made."
  exit 1
fi

ensure_remote_tag
ensure_draft_release
verify_draft_release
upload_and_verify_asset
publish_release
