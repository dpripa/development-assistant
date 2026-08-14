#!/bin/sh
set -eu

ORIGINAL_ENTRYPOINT="/usr/local/bin/docker-entrypoint.sh"
WP_INIT_SCRIPT="/usr/local/bin/init-wp.sh"
WP_ROOT="${WP_ROOT:-/app/wp}"

case "${1:-}" in
  apache2|apache2-*|apache2-foreground)
    "$ORIGINAL_ENTRYPOINT" apache2 -v >/dev/null

    if [ -x "$WP_INIT_SCRIPT" ]; then
      cd "$WP_ROOT" && "$WP_INIT_SCRIPT"
    else
      echo "Skipping WordPress init: $WP_INIT_SCRIPT is not executable."
    fi
    ;;
esac

exec "$ORIGINAL_ENTRYPOINT" "$@"
