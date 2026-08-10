#!/bin/sh
set -eu

attempts="${WP_URL_SYNC_ATTEMPTS:-15}"
sleep_seconds=2

build_target_url() {
  port="${WP_PORT:-8080}"

  printf 'https://localhost:%s\n' "$port"
}

wait_for_database() {
  current_attempt=1

  while [ "$current_attempt" -le "$attempts" ]; do
    if wp --skip-plugins --skip-themes db check --quiet >/dev/null 2>&1; then
      return 0
    fi

    echo "Waiting for WordPress database (${current_attempt}/${attempts})..."
    current_attempt=$((current_attempt + 1))
    sleep "$sleep_seconds"
  done

  return 1
}

replace_url() {
  old_url="$1"
  target_url="$2"

  if [ -z "$old_url" ] || [ "$old_url" = "$target_url" ]; then
    return
  fi

  echo "Replacing WordPress URL: $old_url -> $target_url"
  wp --skip-plugins --skip-themes search-replace "$old_url" "$target_url" --all-tables --precise --report-changed-only
}

sync_url() {
  target_url="$(build_target_url)"
  current_home="$(wp --skip-plugins --skip-themes option get home 2>/dev/null || true)"
  current_siteurl="$(wp --skip-plugins --skip-themes option get siteurl 2>/dev/null || true)"

  if [ "$current_home" = "$target_url" ] && [ "$current_siteurl" = "$target_url" ]; then
    echo "WordPress URL already matches $target_url."
    return
  fi

  replace_url "$current_home" "$target_url"

  if [ "$current_siteurl" != "$current_home" ]; then
    replace_url "$current_siteurl" "$target_url"
  fi

  wp --skip-plugins --skip-themes option update home "$target_url" >/dev/null
  wp --skip-plugins --skip-themes option update siteurl "$target_url" >/dev/null

  echo "WordPress home and siteurl are synced to $target_url."
}

sync_site_title() {
  target_title="${WP_SITE_TITLE:-Development Assistant Local WordPress}"
  current_title="$(wp --skip-plugins --skip-themes option get blogname 2>/dev/null || true)"

  if [ "$current_title" = "$target_title" ]; then
    echo "WordPress site title already matches '$target_title'."
    return
  fi

  wp --skip-plugins --skip-themes option update blogname "$target_title" >/dev/null
  echo "WordPress site title is synced to '$target_title'."
}

sync_admin_user() {
  admin_sync_file="$(mktemp)"

  cat > "$admin_sync_file" <<'PHP'
<?php
$target_login = getenv('WP_ADMIN_USER') ?: 'admin';
$target_email = getenv('WP_ADMIN_EMAIL') ?: 'admin@example.com';
$target_pass = getenv('WP_ADMIN_PASSWORD') ?: 'admin';
$managed_option = 'development_assistant_toolbox_admin_user_id';

$user_id = (int) get_option($managed_option);
$user = $user_id ? get_user_by('id', $user_id) : false;

if (!$user) {
    $user = get_user_by('login', $target_login);
}

if (!$user) {
    $admins = get_users([
        'role' => 'administrator',
        'number' => 1,
        'orderby' => 'ID',
        'order' => 'ASC',
    ]);
    $user = $admins ? $admins[0] : false;
}

if (!$user) {
    $created_id = wp_create_user($target_login, $target_pass, $target_email);
    if (is_wp_error($created_id)) {
        fwrite(STDERR, 'Failed to create admin user: ' . $created_id->get_error_message() . PHP_EOL);
        exit(1);
    }

    $created_user = new WP_User($created_id);
    $created_user->set_role('administrator');
    update_option($managed_option, $created_id, false);
    echo "Created WordPress admin user '{$target_login}'." . PHP_EOL;
    return;
}

$user_id = (int) $user->ID;

if ($user->user_login !== $target_login) {
    $existing_target_user = get_user_by('login', $target_login);

    if ($existing_target_user && (int) $existing_target_user->ID !== $user_id) {
        fwrite(STDERR, "Cannot rename admin user to '{$target_login}': login already exists." . PHP_EOL);
    } else {
        global $wpdb;
        $wpdb->update(
            $wpdb->users,
            [
                'user_login' => $target_login,
                'user_nicename' => sanitize_title($target_login),
                'display_name' => $target_login,
            ],
            ['ID' => $user_id],
            ['%s', '%s', '%s'],
            ['%d']
        );
        clean_user_cache($user_id);
        echo "Renamed managed admin user to '{$target_login}'." . PHP_EOL;
    }
}

$update_args = [
    'ID' => $user_id,
    'user_email' => $target_email,
    'display_name' => $target_login,
    'nickname' => $target_login,
];

$password_user = get_user_by('id', $user_id);
if (!$password_user || !wp_check_password($target_pass, $password_user->user_pass, $user_id)) {
    $update_args['user_pass'] = $target_pass;
}

$update_result = wp_update_user($update_args);

if (is_wp_error($update_result)) {
    fwrite(STDERR, 'Failed to sync admin user: ' . $update_result->get_error_message() . PHP_EOL);
    exit(1);
}

$updated_user = new WP_User($user_id);
if (!in_array('administrator', (array) $updated_user->roles, true)) {
    $updated_user->add_role('administrator');
}

update_option($managed_option, $user_id, false);
echo "WordPress admin user is synced to '{$target_login}' / '{$target_email}'." . PHP_EOL;
PHP

  wp --skip-plugins --skip-themes eval-file "$admin_sync_file"
  rm -f "$admin_sync_file"
}

if ! wait_for_database; then
  echo "WordPress database is not ready; skipping WordPress env sync."
  exit 0
fi

if ! wp --skip-plugins --skip-themes core is-installed >/dev/null 2>&1; then
  echo "WordPress is not installed; skipping WordPress env sync."
  exit 0
fi

sync_url
sync_site_title
sync_admin_user
