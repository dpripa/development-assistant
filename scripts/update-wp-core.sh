#!/bin/sh
set -eu

wp_dir="${1:-wp}"
latest_url="${WP_CORE_DOWNLOAD_URL:-https://wordpress.org/latest.tar.gz}"
tmp_dir="$(mktemp -d)"
archive="$tmp_dir/wordpress.tar.gz"
extract_dir="$tmp_dir/extract"

cleanup() {
  rm -rf "$tmp_dir"
}

trap cleanup EXIT INT TERM

if ! command -v curl >/dev/null 2>&1; then
  echo "curl is required to update local WordPress core references." >&2
  exit 1
fi

mkdir -p "$wp_dir" "$extract_dir"

echo "Downloading latest WordPress core for local IDE references."
if ! curl -fsSL "$latest_url" -o "$archive"; then
  if [ -f "$wp_dir/wp-includes/version.php" ]; then
    echo "Could not download latest WordPress core; keeping existing local mirror in $wp_dir." >&2
    exit 0
  fi

  echo "Could not download WordPress core and $wp_dir is not initialized yet." >&2
  exit 1
fi

tar -xzf "$archive" -C "$extract_dir"

if [ ! -f "$extract_dir/wordpress/wp-includes/version.php" ]; then
  echo "Downloaded archive does not look like WordPress core." >&2
  exit 1
fi

find "$wp_dir" -mindepth 1 ! -name '.gitkeep' -exec rm -rf {} +
cp -R "$extract_dir/wordpress/." "$wp_dir/"

# Runtime content is mounted elsewhere. Keep this folder focused on core APIs.
rm -rf "$wp_dir/wp-content"

version="$(sed -n "s/^\$wp_version = '\([^']*\)';/\1/p" "$wp_dir/wp-includes/version.php" | head -n 1)"
version="${version:-unknown}"

printf '%s\n' "$version" > "$wp_dir/.wp-version"

echo "WordPress core references updated in $wp_dir (version $version)."
