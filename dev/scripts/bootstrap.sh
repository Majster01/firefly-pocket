#!/usr/bin/env bash
# Builds the local dev stack from nothing: Firefly III + Postgres + the mock importer, a dev user, its access token,
# dev/.env and server/.env, and ~6 months of dummy data. Everything stays on this machine.
#
#   dev/scripts/bootstrap.sh          # first run
#   dev/scripts/bootstrap.sh --reset  # throw the data away and start over
#
# On a host without IPv6 (some Linux cloud boxes) set POCKET_DEV_IPV4=1.
set -euo pipefail

DEV_DIR="$(cd "$(dirname "$0")/.." && pwd)"
ROOT_DIR="$(cd "$DEV_DIR/.." && pwd)"
cd "$DEV_DIR"

EMAIL=dev@example.com
PASSWORD=Pocket-dev-only-9f2c71
FIREFLY_URL=http://localhost:8081
IMPORTER_TOKEN=dev-importer-token-dev-importer-token

compose() {
  if [ "${POCKET_DEV_IPV4:-}" = 1 ]; then
    docker compose -f docker-compose.yml -f docker-compose.ipv4.yml "$@"
  else
    docker compose "$@"
  fi
}

if [ "${1:-}" = --reset ]; then
  compose down -v
fi

echo "Starting Postgres and Firefly III…"
compose up -d db app
for _ in $(seq 1 90); do
  code=$(curl -s -o /dev/null -w '%{http_code}' "$FIREFLY_URL/" || true)
  [ "$code" = 302 ] || [ "$code" = 200 ] && break
  sleep 2
done
[ "$code" = 302 ] || [ "$code" = 200 ] || { echo "Firefly III did not come up on $FIREFLY_URL (last answer: $code)"; exit 1; }

# Registering through the web form runs Firefly's own new-user handling (user group, roles)
jar="$(mktemp)"
trap 'rm -f "$jar"' EXIT
csrf=$(curl -s -c "$jar" -b "$jar" "$FIREFLY_URL/register" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//' || true)
if [ -n "$csrf" ]; then
  echo "Registering $EMAIL…"
  curl -s -c "$jar" -b "$jar" -o /dev/null -X POST "$FIREFLY_URL/register" \
    --data-urlencode "_token=$csrf" --data-urlencode "email=$EMAIL" \
    --data-urlencode "password=$PASSWORD" --data-urlencode "password_confirmation=$PASSWORD"
else
  echo "Registration is closed: $EMAIL exists already"
fi

echo "Creating access tokens…"
compose cp scripts/create-token.php app:/tmp/create-token.php >/dev/null
token() { compose exec -T app php /tmp/create-token.php "$EMAIL" "$1" | tail -1; }
MOCK_TOKEN=$(token mock-importer)
WEB_TOKEN=$(token pocket-web)

printf 'MOCK_FIREFLY_TOKEN=%s\n' "$MOCK_TOKEN" > "$DEV_DIR/.env"
# For the web app's token setup screen (and the e2e checks); gitignored like the rest
printf 'FIREFLY_TOKEN=%s\n' "$WEB_TOKEN" > "$DEV_DIR/.web-token.env"

if [ ! -f "$ROOT_DIR/server/.env" ]; then
  cp "$ROOT_DIR/server/.env.example" "$ROOT_DIR/server/.env"
fi
set_env() {
  local file="$ROOT_DIR/server/.env" key="$1" value="$2"
  if grep -qE "^$key=" "$file"; then
    sed -i.bak -E "s|^$key=.*|$key=$value|" "$file" && rm -f "$file.bak"
  else
    printf '%s=%s\n' "$key" "$value" >> "$file"
  fi
}
set_env FIREFLY_URL "$FIREFLY_URL"
set_env IMPORTER_API_URL "http://localhost:8091/api/fireflyx/v1"
set_env IMPORTER_API_TOKEN "$IMPORTER_TOKEN"
set_env APP_NAME '"Firefly Pocket (dev)"'
set_env APP_URL "http://localhost:8000"
set_env DB_CONNECTION sqlite
set_env DB_DATABASE "$ROOT_DIR/server/database/data/database.sqlite"
# Dev only: no outbound calls for exchange rates, fixed dummy rates instead
set_env EXCHANGE_RATES_MOCK true
# Web Push: a VAPID keypair and the token FireflyX's senders use (dev/scripts/notify-test.sh plays them)
"$DEV_DIR/scripts/push-keys.sh"

echo "Seeding dummy data…"
FIREFLY_URL="$FIREFLY_URL" FIREFLY_TOKEN="$MOCK_TOKEN" node "$DEV_DIR/scripts/seed.mjs"

echo "Starting the mock importer…"
compose up -d --force-recreate importer

cat <<EOF

Ready.
  Firefly III     $FIREFLY_URL  ($EMAIL / $PASSWORD)
  Mock importer   http://localhost:8091
  Web token       dev/.web-token.env

Next: dev/scripts/dev.sh starts Pocket's API (:8000) and the web app (:5173).
EOF
