#!/bin/sh
set -eu

backup_dir="${BACKUP_DIR:-backups/db}"
label="${1:-}"
attempts="${DB_BACKUP_ATTEMPTS:-30}"
sleep_seconds=2

slugify_label() {
  printf '%s' "$1" \
    | tr '[:upper:]' '[:lower:]' \
    | sed -E 's/[^a-z0-9]+/-/g; s/^-+//; s/-+$//'
}

wait_for_database() {
  current_attempt=1

  while [ "$current_attempt" -le "$attempts" ]; do
    if docker compose exec -T db sh -lc 'mariadb-admin ping -u root -p"$MARIADB_ROOT_PASSWORD" --silent' >/dev/null 2>&1; then
      return 0
    fi

    echo "Waiting for database before backup (${current_attempt}/${attempts})..."
    current_attempt=$((current_attempt + 1))
    sleep "$sleep_seconds"
  done

  return 1
}

mkdir -p "$backup_dir"

echo "Starting database container for backup."
docker compose up -d db >/dev/null

if ! wait_for_database; then
  echo "Database is not ready; skipping backup."
  exit 0
fi

database_name="$(docker compose exec -T db sh -lc 'printf "%s" "$MARIADB_DATABASE"' 2>/dev/null || printf "wordpress")"

if ! docker compose exec -T db sh -lc 'mariadb -u root -p"$MARIADB_ROOT_PASSWORD" -e "USE \`$MARIADB_DATABASE\`;"' >/dev/null 2>&1; then
  echo "Database '$database_name' does not exist yet; skipping backup."
  exit 0
fi

timestamp="$(date '+%Y-%m-%d-%H%M%S')"
safe_label="$(slugify_label "$label")"

if [ -n "$safe_label" ]; then
  backup_file="$backup_dir/${timestamp}-${safe_label}.sql.gz"
else
  backup_file="$backup_dir/${timestamp}-db.sql.gz"
fi

echo "Writing database backup to $backup_file."

docker compose exec -T db sh -lc 'mariadb-dump --single-transaction --quick --routines --triggers -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' \
  | gzip -c > "$backup_file"

echo "Database backup created: $backup_file"
