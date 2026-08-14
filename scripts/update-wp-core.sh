#!/bin/sh
set -eu

wp_dir="${1:-wp}"
project_plugin="development-assistant"
project_plugin_target="../../.."
latest_url="${WP_CORE_DOWNLOAD_URL:-https://wordpress.org/latest.tar.gz}"
tmp_dir="$(mktemp -d)"
archive="$tmp_dir/wordpress.tar.gz"
extract_dir="$tmp_dir/extract"

cleanup() {
  rm -rf "$tmp_dir"
}

trap cleanup EXIT INT TERM

if ! command -v curl >/dev/null 2>&1; then
  echo "curl is required to update the local WordPress runtime." >&2
  exit 1
fi

mkdir -p "$wp_dir" "$extract_dir"

echo "Downloading latest WordPress core for the local runtime."
if ! curl -fsSL "$latest_url" -o "$archive"; then
  if [ -f "$wp_dir/wp-includes/version.php" ]; then
    echo "Could not download latest WordPress core; keeping the existing local runtime in $wp_dir." >&2
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

core_source="$extract_dir/wordpress"

rm -rf "$wp_dir/wp-admin" "$wp_dir/wp-includes"

find "$wp_dir" -maxdepth 1 -type f \
  ! -name 'wp-config.php' \
  \( \
    -name 'index.php' \
    -o -name 'license.txt' \
    -o -name 'readme.html' \
    -o -name 'xmlrpc.php' \
    -o -name 'wp-*.php' \
  \) \
  -exec rm -f {} +

cp -R "$core_source/wp-admin" "$wp_dir/wp-admin"
cp -R "$core_source/wp-includes" "$wp_dir/wp-includes"

for core_file in "$core_source"/*; do
  if [ -f "$core_file" ]; then
    cp "$core_file" "$wp_dir/"
  fi
done

if [ ! -d "$wp_dir/wp-content" ]; then
  cp -R "$core_source/wp-content" "$wp_dir/wp-content"
fi

plugin_dir="$wp_dir/wp-content/plugins/$project_plugin"
mkdir -p "$wp_dir/wp-content/plugins"

if [ -L "$plugin_dir" ]; then
  if [ "$(readlink "$plugin_dir")" != "$project_plugin_target" ]; then
    echo "Project plugin symlink points to an unexpected target: $plugin_dir" >&2
    exit 1
  fi
elif [ -e "$plugin_dir" ]; then
  echo "Project plugin path exists but is not a symlink: $plugin_dir" >&2
  exit 1
else
  ln -s "$project_plugin_target" "$plugin_dir"
fi

version="$(sed -n "s/^\$wp_version = '\([^']*\)';/\1/p" "$wp_dir/wp-includes/version.php" | head -n 1)"
version="${version:-unknown}"

printf '%s\n' "$version" > "$wp_dir/.wp-version"

echo "WordPress core updated in $wp_dir (version $version); wp-content and local configuration were preserved."
