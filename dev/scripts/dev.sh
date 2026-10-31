#!/usr/bin/env bash
# Runs Pocket's API (server/, :8000) and the web app (web/, :5173) against the local dev stack.
# Run dev/scripts/bootstrap.sh once first. Stop with Ctrl+C.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"

cd "$ROOT_DIR/server"
[ -d vendor ] || composer install --no-interaction
[ -f .env ] || { echo "server/.env is missing: run dev/scripts/bootstrap.sh first"; exit 1; }
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force
mkdir -p database/data storage/framework/views storage/framework/cache/data storage/framework/sessions storage/logs && touch database/data/database.sqlite
php artisan migrate --force >/dev/null

cd "$ROOT_DIR/web"
[ -d node_modules ] || npm install

cd "$ROOT_DIR"
# Several workers: the web app asks for many things at once, and one PHP process would queue them
PHP_CLI_SERVER_WORKERS=4 php server/artisan serve --port=8000 &
API_PID=$!
trap 'kill $API_PID 2>/dev/null' EXIT

echo
echo "Web app: http://localhost:5173  (token: dev/.web-token.env)"
echo "On an iPhone in the same network: http://<this machine's IP>:5173"
echo
npm --prefix web run dev
