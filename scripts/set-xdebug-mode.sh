#!/bin/sh
set -eu

MODE="${1:-}"
ENV_FILE=".env"

case "$MODE" in
  off|debug|develop|debug,develop|coverage)
    ;;
  *)
    echo "Usage: ./scripts/set-xdebug-mode.sh off|debug|develop|debug,develop|coverage"
    exit 1
    ;;
esac

if [ ! -f "$ENV_FILE" ]; then
  if [ ! -f ".env.example" ]; then
    echo "Cannot create $ENV_FILE: .env.example was not found."
    exit 1
  fi

  cp .env.example "$ENV_FILE"
  echo "Created $ENV_FILE from .env.example."
fi

tmp_file="$(mktemp)"

awk -v mode="$MODE" '
  BEGIN { updated = 0 }
  /^XDEBUG_MODE=/ {
    print "XDEBUG_MODE=" mode
    updated = 1
    next
  }
  { print }
  END {
    if (!updated) {
      print "XDEBUG_MODE=" mode
    }
  }
' "$ENV_FILE" > "$tmp_file"

mv "$tmp_file" "$ENV_FILE"

echo "Set XDEBUG_MODE=$MODE in $ENV_FILE."
