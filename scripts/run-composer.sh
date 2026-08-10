#!/bin/sh
set -eu

if [ "${CI:-}" = "true" ] && command -v composer >/dev/null 2>&1; then
	exec composer "$@"
fi

if command -v docker >/dev/null 2>&1; then
	exec docker compose --profile tools run --rm --no-deps composer "$@"
fi

if command -v composer >/dev/null 2>&1; then
	exec composer "$@"
fi

echo "Composer or Docker with Compose is required." >&2
exit 127
