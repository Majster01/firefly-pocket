#!/bin/sh
# Runs the Laravel backend (:8000) and Nuxt dev server (:3000) together. Ctrl+C stops both.
# The Nuxt dev server proxies /api/* to the backend, so the app works at http://localhost:3000 with no backend URL setup.
cd "$(dirname "$0")/.."
# Match production PHP (8.4); the composer lockfile does not support 8.5 yet
[ -d /opt/homebrew/opt/php@8.4/bin ] && PATH="/opt/homebrew/opt/php@8.4/bin:$PATH"
trap 'kill 0' INT TERM EXIT
(cd back && php artisan serve --port=8000) &
(cd front && npm run dev -- --port 3000) &
wait
