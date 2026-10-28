#!/bin/sh
# Opens a playwright-cli browser on the local app, logged in with PICO_DEV_TOKEN from .dev.env.
# Usage: scripts/dev-login.sh [--headed] [--mobile]   (then drive it with playwright-cli snapshot/click/screenshot ...)
cd "$(dirname "$0")/.."
. ./.dev.env
[ -n "$PICO_DEV_TOKEN" ] || { echo "Set PICO_DEV_TOKEN in .dev.env (a Firefly III Personal Access Token)"; exit 1; }
URL=http://localhost:3000

HEADED=
for arg in "$@"; do
  case "$arg" in
    --headed) HEADED=--headed ;;
    --mobile) MOBILE=1 ;;
  esac
done

playwright-cli open "$URL" $HEADED
[ -n "$MOBILE" ] && playwright-cli resize 390 844
playwright-cli localstorage-set authToken "$PICO_DEV_TOKEN" > /dev/null
playwright-cli goto "$URL"
