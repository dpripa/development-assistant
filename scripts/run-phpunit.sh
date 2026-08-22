#!/bin/sh
set -eu

compose_command="${COMPOSE:-docker compose}"

cleanup() {
	$compose_command --profile test stop test-db >/dev/null 2>&1 || true
	$compose_command --profile test rm --force --stop test-db >/dev/null 2>&1 || true
}

trap cleanup EXIT INT TERM

$compose_command --profile test up --detach --wait test-db
$compose_command --profile test run --build --rm --no-deps phpunit "$@"
