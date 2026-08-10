#!/bin/sh
set -eu

restore_dev_dependencies() {
  rm -rf vendor
  docker compose --profile tools run --rm --no-deps composer install
}

trap restore_dev_dependencies EXIT INT TERM

rm -rf vendor
docker compose --profile tools run --rm --no-deps composer install --no-dev --optimize-autoloader
"$@"
