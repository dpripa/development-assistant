#!/bin/sh
set -eu

WP_ROOT="${WP_ROOT:-/app/wp}"
WP_CONTENT_DIR="$WP_ROOT/wp-content"
WP_CONFIG="$WP_ROOT/wp-config.php"
PROJECT_PLUGIN="development-assistant"
attempts="${WP_URL_SYNC_ATTEMPTS:-15}"
sleep_seconds=2

wp_cli() {
  runuser -u www-data -- wp "$@"
}

build_target_url() {
  printf 'https://localhost:%s\n' "${WP_PORT:-21601}"
}

ensure_project_plugin_link() {
  plugin_dir="$WP_CONTENT_DIR/plugins/$PROJECT_PLUGIN"
  plugin_target="../../.."

  mkdir -p "$WP_CONTENT_DIR/plugins"

  if [ -L "$plugin_dir" ]; then
    if [ "$(readlink "$plugin_dir")" != "$plugin_target" ]; then
      echo "Project plugin symlink points to an unexpected target: $plugin_dir" >&2
      exit 1
    fi

    return
  fi

  if [ -e "$plugin_dir" ]; then
    echo "Project plugin path exists but is not a symlink: $plugin_dir" >&2
    exit 1
  fi

  ln -s "$plugin_target" "$plugin_dir"
  echo "Created project plugin symlink: $plugin_dir -> $plugin_target"
}

remove_bundled_extensions() {
  rm -rf "$WP_CONTENT_DIR/plugins/akismet"
  rm -f "$WP_CONTENT_DIR/plugins/hello.php"

  for theme_slug in twentytwentythree twentytwentyfour; do
    rm -rf "$WP_CONTENT_DIR/themes/$theme_slug"
  done
}

ensure_wp_config_constants() {
  constants_sync_file="$(mktemp)"

  cat > "$constants_sync_file" <<'PHP'
<?php
$configPath = $argv[1];
$config = file_get_contents($configPath);
$originalConfig = $config;

$constants = [
    'WP_DEBUG' => 'true',
    'WP_DEBUG_LOG' => 'true',
    'WP_DEBUG_DISPLAY' => 'true',
    'SCRIPT_DEBUG' => 'false',
    'QM_DARK_MODE' => 'true',
    'QM_HIDE_SELF' => 'true',
    'QM_SHOW_ALL_HOOKS' => 'true',
    'QM_ENABLE_CAPS_PANEL' => 'true',
    'WP_ENVIRONMENT' => "'development'",
    'WP_ENVIRONMENT_TYPE' => "'development'",
];

$serverDefaultsSnippet = <<<'PHP_SNIPPET'
// Development Assistant local environment defaults for CLI and HTTPS proxy requests.
if ( empty( $_SERVER['HTTP_HOST'] ) ) {
    $_SERVER['HTTP_HOST'] = 'localhost:' . ( getenv( 'WP_PORT' ) ?: '21601' );
}

if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {
    $_SERVER['HTTPS'] = 'on';
}

PHP_SNIPPET
;

$missingLines = [];

foreach ($constants as $name => $value) {
    $line = "define( '{$name}', {$value} );";
    $pattern = "/define\s*\(\s*(['\"])" . preg_quote($name, '/') . "\g{1}\s*,\s*[^;]*\);/";

    if (preg_match($pattern, $config)) {
        $config = preg_replace($pattern, $line, $config, 1);
    } else {
        $missingLines[] = $line;
    }
}

if ($missingLines) {
    $block = implode(PHP_EOL, $missingLines) . PHP_EOL . PHP_EOL;
    $marker = "/* That's all, stop editing";
    $position = strpos($config, $marker);

    if ($position !== false) {
        $config = substr($config, 0, $position) . $block . substr($config, $position);
    } else {
        $config = rtrim($config) . PHP_EOL . PHP_EOL . $block;
    }
}

if (strpos($config, 'Development Assistant local environment defaults') === false) {
    $marker = "/* That's all, stop editing";
    $position = strpos($config, $marker);

    if ($position !== false) {
        $config = substr($config, 0, $position) . $serverDefaultsSnippet . substr($config, $position);
    } else {
        $config = rtrim($config) . PHP_EOL . PHP_EOL . $serverDefaultsSnippet;
    }
}

if ($config !== $originalConfig) {
    file_put_contents($configPath, $config);
    echo "Synced local development constants in {$configPath}." . PHP_EOL;
}
PHP

  php "$constants_sync_file" "$WP_CONFIG"
  rm -f "$constants_sync_file"
}

ensure_wp_config() {
  if [ ! -f "$WP_CONFIG" ]; then
    wp_cli config create \
      --dbname="${WORDPRESS_DB_NAME:-wordpress}" \
      --dbuser="${WORDPRESS_DB_USER:-wordpress}" \
      --dbpass="${WORDPRESS_DB_PASSWORD:-wordpress}" \
      --dbhost="${WORDPRESS_DB_HOST:-db:3306}" \
      --skip-check
    echo "Created $WP_CONFIG."
  fi

  ensure_wp_config_constants
}

wait_for_database() {
  current_attempt=1

  while [ "$current_attempt" -le "$attempts" ]; do
    if wp_cli --skip-plugins --skip-themes db check --quiet >/dev/null 2>&1; then
      return 0
    fi

    echo "Waiting for WordPress database before init (${current_attempt}/${attempts})..."
    current_attempt=$((current_attempt + 1))
    sleep "$sleep_seconds"
  done

  return 1
}

install_required_plugins() {
  for plugin_slug in ${REQUIRED_PLUGINS:-}; do
    if wp_cli --skip-plugins --skip-themes plugin is-installed "$plugin_slug" >/dev/null 2>&1; then
      echo "Required plugin '$plugin_slug' is already installed."
      continue
    fi

    echo "Installing required plugin '$plugin_slug'."
    wp_cli --skip-plugins --skip-themes plugin install "$plugin_slug"
  done
}

activate_plugin() {
  plugin_slug="$1"

  if ! wp_cli --skip-plugins --skip-themes plugin is-installed "$plugin_slug" >/dev/null 2>&1; then
    echo "Plugin '$plugin_slug' is not detected by WordPress." >&2
    exit 1
  fi

  if wp_cli --skip-plugins --skip-themes plugin is-active "$plugin_slug" >/dev/null 2>&1; then
    echo "Plugin '$plugin_slug' is already active."
    return
  fi

  echo "Activating plugin '$plugin_slug'."
  wp_cli --skip-plugins --skip-themes plugin activate "$plugin_slug"
}

ensure_project_plugin_link
ensure_wp_config
remove_bundled_extensions

if ! wait_for_database; then
  echo "WordPress database is not ready; skipping WordPress init."
  exit 0
fi

if wp_cli --skip-plugins --skip-themes core is-installed >/dev/null 2>&1; then
  echo "WordPress is already installed."
else
  echo "WordPress is not installed; installing automatically."
  wp_cli --skip-plugins --skip-themes core install \
    --url="$(build_target_url)" \
    --title="${WP_SITE_TITLE:-Development Assistant Local}" \
    --admin_user="${WP_ADMIN_USER:-admin}" \
    --admin_password="${WP_ADMIN_PASSWORD:-admin}" \
    --admin_email="${WP_ADMIN_EMAIL:-admin@example.com}" \
    --skip-email
fi

install_required_plugins
activate_plugin "$PROJECT_PLUGIN"

for plugin_slug in ${REQUIRED_PLUGINS:-}; do
  activate_plugin "$plugin_slug"
done

runuser -u www-data -- sh /usr/local/bin/sync-wp-env.sh
