#!/usr/bin/env bash
# Offbeat — one-command local start for Linux and macOS.
#   ./start.sh            Docker stack (MySQL 8.4, builds the PHP image)
#   ./start.sh --compat   Docker stack using pre-pulled php:8.3-apache + mysql:5.7 images
#   ./start.sh --native   No Docker: PHP built-in server + a MySQL/MariaDB already on this host
#   ./start.sh --stop     Stop whatever this script started
set -euo pipefail
cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"

MODE=docker
STOP=no
for arg in "$@"; do
  case "$arg" in
    --compat) MODE=compat ;;
    --native) MODE=native ;;
    --stop)   STOP=yes ;;
    -h|--help) sed -n '2,6p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Unknown option: $arg (try --help)" >&2; exit 2 ;;
  esac
done

compose_file() { [ "$MODE" = compat ] && echo "compose.compat.yaml" || echo "compose.yaml"; }
say() { printf '\n\033[1;32m==>\033[0m %s\n' "$1"; }
die() { printf '\n\033[1;31mError:\033[0m %s\n' "$1" >&2; exit 1; }

# ---------------------------------------------------------------- .env
if [ ! -f .env ]; then
  say "Creating .env with random local database secrets"
  secret() { LC_ALL=C tr -dc 'a-f0-9' </dev/urandom | head -c 48; }
  printf 'APP_PORT=8088\nAPP_ENV=local\nDB_DATABASE=offbeat\nDB_USERNAME=offbeat\nDB_PASSWORD=%s\nDB_ROOT_PASSWORD=%s\n' "$(secret)" "$(secret)" > .env
  chmod 600 .env
fi
# shellcheck disable=SC1091
set -a; . ./.env; set +a
PORT="${APP_PORT:-8088}"

# ---------------------------------------------------------------- stop
if [ "$STOP" = yes ]; then
  if [ -f storage/native-server.pid ] && kill -0 "$(cat storage/native-server.pid)" 2>/dev/null; then
    kill "$(cat storage/native-server.pid)" && rm -f storage/native-server.pid
    say "Stopped the native PHP server."
  fi
  for f in compose.yaml compose.compat.yaml; do
    docker compose -f "$f" down 2>/dev/null || true
  done
  say "Stopped. The database volume and uploads are preserved."
  exit 0
fi

# ---------------------------------------------------------------- native
if [ "$MODE" = native ]; then
  command -v php >/dev/null || die "php not found. Install PHP 8.1+ with the pdo_mysql and fileinfo extensions, or use Docker instead."
  php -r 'exit(extension_loaded("pdo_mysql") && extension_loaded("fileinfo") ? 0 : 1);' \
    || die "PHP is missing pdo_mysql or fileinfo. On Debian/Ubuntu: sudo apt install php-mysql php-fileinfo"
  MYSQL=$(command -v mariadb || command -v mysql) || die "No mysql/mariadb client found, and a local database server is required for --native."

  say "Preparing the '$DB_DATABASE' database on 127.0.0.1:3306"
  ADMIN_ARGS=(-h 127.0.0.1 -u root)
  [ -n "${DB_ROOT_PASSWORD:-}" ] && ADMIN_ARGS+=("-p$DB_ROOT_PASSWORD")
  if ! "$MYSQL" "${ADMIN_ARGS[@]}" -e 'SELECT 1' >/dev/null 2>&1; then
    ADMIN_ARGS=(-h 127.0.0.1 -u root)   # retry via socket/unix auth, e.g. sudo-less MariaDB
    "$MYSQL" "${ADMIN_ARGS[@]}" -e 'SELECT 1' >/dev/null 2>&1 \
      || die "Cannot reach the local database as root. Start MySQL/MariaDB, or set DB_ROOT_PASSWORD in .env."
  fi
  "$MYSQL" "${ADMIN_ARGS[@]}" -e "CREATE DATABASE IF NOT EXISTS \`$DB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS '$DB_USERNAME'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
    ALTER USER '$DB_USERNAME'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
    GRANT ALL PRIVILEGES ON \`$DB_DATABASE\`.* TO '$DB_USERNAME'@'127.0.0.1'; FLUSH PRIVILEGES;"
  "$MYSQL" "${ADMIN_ARGS[@]}" "$DB_DATABASE" < database/schema.sql

  export DB_HOST=127.0.0.1 DB_PORT=3306
  mkdir -p storage public/uploads && chmod 775 public/uploads

  say "Seeding demonstration content"
  if [ ! -f storage/local-access.json ]; then
    printf '{\n  "email": "admin@offbeat.local",\n  "password": "%s"\n}\n' "$(LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 28)" > storage/local-access.json
    chmod 600 storage/local-access.json
  fi
  SEED_ADMIN_EMAIL=$(sed -n 's/.*"email": *"\([^"]*\)".*/\1/p' storage/local-access.json)
  SEED_ADMIN_PASSWORD=$(sed -n 's/.*"password": *"\([^"]*\)".*/\1/p' storage/local-access.json)
  SEED_ADMIN_EMAIL="$SEED_ADMIN_EMAIL" SEED_ADMIN_PASSWORD="$SEED_ADMIN_PASSWORD" php scripts/seed.php
  php scripts/demo-login.php

  if [ -f storage/native-server.pid ] && kill -0 "$(cat storage/native-server.pid)" 2>/dev/null; then
    kill "$(cat storage/native-server.pid)"; sleep 1
  fi
  say "Starting the PHP built-in server"
  php -S "127.0.0.1:$PORT" -t public >storage/native-server.log 2>&1 &
  echo $! > storage/native-server.pid
  sleep 2
  kill -0 "$(cat storage/native-server.pid)" 2>/dev/null || die "The PHP server exited immediately. See storage/native-server.log"

# ---------------------------------------------------------------- docker
else
  command -v docker >/dev/null || die "docker not found. Use ./start.sh --native instead."
  docker info >/dev/null 2>&1 || die "The Docker daemon is not reachable. Start Docker, or use ./start.sh --native."
  FILE=$(compose_file)
  say "Starting the Docker stack ($FILE)"
  BUILD=(); [ "$MODE" = docker ] && BUILD=(--build)
  if ! docker compose -f "$FILE" up -d --wait "${BUILD[@]}"; then
    [ "$MODE" = docker ] && die "The stack failed to start. This host has previously failed to extract the MySQL 8.4 layers; retry with ./start.sh --compat"
    die "The compatibility stack failed to start. See: docker compose -f $FILE logs"
  fi
  docker compose -f "$FILE" exec -T -u root web sh -c "chown -R www-data:$(id -g) public/uploads && chmod 2775 public/uploads"

  if [ ! -f storage/local-access.json ]; then
    printf '{\n  "email": "admin@offbeat.local",\n  "password": "%s"\n}\n' "$(LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 28)" > storage/local-access.json
    chmod 600 storage/local-access.json
  fi
  say "Seeding demonstration content"
  docker compose -f "$FILE" exec -T \
    -e SEED_ADMIN_EMAIL="$(sed -n 's/.*"email": *"\([^"]*\)".*/\1/p' storage/local-access.json)" \
    -e SEED_ADMIN_PASSWORD="$(sed -n 's/.*"password": *"\([^"]*\)".*/\1/p' storage/local-access.json)" \
    web php scripts/seed.php
  docker compose -f "$FILE" exec -T web php scripts/demo-login.php
fi

cat <<EOF

  Offbeat is running:  http://localhost:$PORT

  Demo administrator:  demo.admin@offbeat.local      / OffbeatAdmin!2026
  Demo traveller:      demo.traveller@offbeat.local  / OffbeatDemo!2026
  Private admin:       storage/local-access.json (generated, git-ignored)

  Stop with: ./start.sh --stop
EOF
