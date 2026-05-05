#!/usr/bin/env bash
# Wipes the running anyauction database and re-applies schema.sql + seed.sql.
# Drops every table (schema.sql starts with DROP TABLE IF EXISTS) and reseeds
# from scratch. Auto-incremented IDs reset to 1, sessions are cleared, and any
# user that's currently logged in via a browser will be silently signed out.
#
# Usage (from repo root):
#   ./scripts/reset-db.sh
#
# Requires: docker compose stack running (`docker compose up -d`).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
SCHEMA="$REPO_ROOT/sql/schema.sql"
SEED="$REPO_ROOT/sql/seed.sql"
DB_CONTAINER="${DB_CONTAINER:-db}"
DB_NAME="${DB_NAME:-anyauction}"

if [ ! -f "$SCHEMA" ] || [ ! -f "$SEED" ]; then
    echo "error: cannot find sql/schema.sql or sql/seed.sql under $REPO_ROOT" >&2
    exit 1
fi

if ! docker inspect "$DB_CONTAINER" >/dev/null 2>&1; then
    echo "error: container '$DB_CONTAINER' is not running. Start it with: docker compose up -d" >&2
    exit 1
fi

DB_PW=$(docker inspect "$DB_CONTAINER" \
    --format '{{range .Config.Env}}{{println .}}{{end}}' \
    | grep -E 'MARIADB_ROOT_PASSWORD|MYSQL_ROOT_PASSWORD' \
    | head -1 | cut -d= -f2)

if [ -z "$DB_PW" ]; then
    echo "error: could not read MARIADB_ROOT_PASSWORD from container env" >&2
    exit 1
fi

echo "→ Dropping all tables and re-applying schema..."
docker exec -i "$DB_CONTAINER" mariadb -uroot -p"$DB_PW" "$DB_NAME" < "$SCHEMA"

echo "→ Loading demo seed data..."
docker exec -i "$DB_CONTAINER" mariadb -uroot -p"$DB_PW" "$DB_NAME" < "$SEED"

echo "✓ Database reset. Login with any of:"
echo "    buyer@anyauction.test    / password123"
echo "    seller@anyauction.test   / seller123"
echo "    admin@anyauction.test    / admin123"
echo "    riley@anyauction.test    / password123  (second buyer)"
echo "    sam@anyauction.test      / password123  (buyer with rating history)"
echo "    taylor@anyauction.test   / password123  (second seller)"
echo "    morgan@anyauction.test   / password123  (seller with rating history)"
echo "    spammy@anyauction.test   / password123  (subject of pending reports)"
echo "    warned@anyauction.test   / password123  (admin warning on file)"
echo "    banned@anyauction.test   / password123  (banned account — login should be blocked)"
