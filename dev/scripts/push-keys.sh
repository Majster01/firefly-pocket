#!/usr/bin/env bash
# Gives the local Pocket API what it needs for Web Push: a VAPID keypair and the NOTIFY_API_TOKEN the FireflyX senders use.
# Fills server/.env only where the values are still empty, so it is safe to run again (bootstrap.sh calls it).
# Rotating the keys invalidates every browser's subscription: switch notifications off and on again in Settings.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
ENV_FILE="$ROOT_DIR/server/.env"
[ -f "$ENV_FILE" ] || { echo "server/.env is missing: run dev/scripts/bootstrap.sh first" >&2; exit 1; }

has_value() { grep -qE "^$1=.+" "$ENV_FILE"; }
set_env() {
  if grep -qE "^#?$1=" "$ENV_FILE"; then
    sed -i.bak -E "s|^#?$1=.*|$1=$2|" "$ENV_FILE" && rm -f "$ENV_FILE.bak"
  else
    printf '%s=%s\n' "$1" "$2" >> "$ENV_FILE"
  fi
}
b64url() { openssl base64 -A | tr '+/' '-_' | tr -d '='; }

if ! has_value VAPID_PUBLIC_KEY || ! has_value VAPID_PRIVATE_KEY; then
  # The raw 32-byte scalar and the 65-byte uncompressed point, cut out of the 121-byte SEC1 DER of a P-256 key
  der="$(mktemp)"
  trap 'rm -f "$der"' EXIT
  openssl ecparam -name prime256v1 -genkey -noout 2>/dev/null | openssl ec -outform DER 2>/dev/null > "$der"
  [ "$(wc -c < "$der" | tr -d ' ')" -eq 121 ] || { echo "openssl made a key of an unexpected size; try: npx web-push generate-vapid-keys" >&2; exit 1; }
  set_env VAPID_PRIVATE_KEY "$(head -c 39 "$der" | tail -c 32 | b64url)"
  set_env VAPID_PUBLIC_KEY "$(tail -c 65 "$der" | b64url)"
  echo "VAPID keypair written to server/.env"
fi
has_value VAPID_SUBJECT || set_env VAPID_SUBJECT "mailto:dev@example.com"
if ! has_value NOTIFY_API_TOKEN; then
  set_env NOTIFY_API_TOKEN "$(openssl rand -hex 24)"
  echo "NOTIFY_API_TOKEN written to server/.env"
fi
