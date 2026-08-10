#!/bin/sh
set -eu

backup_file="${1:-}"
attempts="${DB_BACKUP_ATTEMPTS:-30}"
sleep_seconds=2

if [ -z "$backup_file" ]; then
  echo "Usage: ./scripts/db-restore.sh backups/db/YYYY-MM-DD-HHMMSS-db.sql.gz" >&2
  exit 1
fi

if [ ! -f "$backup_file" ]; then
  echo "Backup file not found: $backup_file" >&2
  exit 1
fi

wait_for_database() {
  current_attempt=1

  while [ "$current_attempt" -le "$attempts" ]; do
    if docker compose exec -T db sh -lc 'mariadb-admin ping -u root -p"$MARIADB_ROOT_PASSWORD" --silent' >/dev/null 2>&1; then
      return 0
    fi

    echo "Waiting for database before restore (${current_attempt}/${attempts})..."
    current_attempt=$((current_attempt + 1))
    sleep "$sleep_seconds"
  done

  return 1
}

stream_backup() {
  case "$backup_file" in
    *.gz)
      gzip -dc "$backup_file"
      ;;
    *)
      cat "$backup_file"
      ;;
  esac
}

echo "Starting database container for restore."
docker compose up -d db >/dev/null

if ! wait_for_database; then
  echo "Database is not ready; cannot restore." >&2
  exit 1
fi

echo "Resetting target database before restore."
docker compose exec -T db sh -lc 'mariadb -u root -p"$MARIADB_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS \`$MARIADB_DATABASE\`; CREATE DATABASE \`$MARIADB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"'

echo "Restoring database from $backup_file."
stream_backup | docker compose exec -T db sh -lc 'mariadb -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"'

echo "Database restore completed."
