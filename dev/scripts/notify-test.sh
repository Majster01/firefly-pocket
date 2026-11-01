#!/usr/bin/env bash
# Sends the notifications FireflyX sends, to the devices subscribed to the local Pocket API, so each can be seen and
# its setting tried in Settings → Notifications.
#
#   dev/scripts/notify-test.sh            # one of each
#   dev/scripts/notify-test.sh import     # import.success (3 new), import.success (none new), import.failure
#   dev/scripts/notify-test.sh suggestions | upgrade | alert
#
# Needs dev/scripts/push-keys.sh to have run, the API restarted afterwards, and a production build of the web app
# (the service worker does not run under `vite dev`): npm --prefix web run build && npm --prefix web run preview.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
API="${POCKET_API:-http://localhost:8000}"
TOKEN="$(sed -n 's/^NOTIFY_API_TOKEN=//p' "$ROOT_DIR/server/.env" | head -n 1)"
[ -n "$TOKEN" ] || { echo "NOTIFY_API_TOKEN is empty: run dev/scripts/push-keys.sh" >&2; exit 1; }

send() {
  echo "-> $1"
  printf 'header = "Authorization: Bearer %s"\n' "$TOKEN" \
    | curl -sS --max-time 20 --config - -H 'Content-Type: application/json' -d "$2" "$API/api/internal/notify"
  echo
}

what="${1:-all}"
case "$what" in all | import)
  send "import succeeded, 3 new" '{"event":"import.success","count":3,"title":"NLB Current import succeeded","body":"3 new transactions imported.","path":"/transactions"}'
  send "import succeeded, nothing new" '{"event":"import.success","count":0,"title":"NLB Current import succeeded","body":"Nothing new since last night.","path":"/bank-connections"}'
  send "import failed" '{"event":"import.failure","title":"NLB import failed","body":"The bank consent has ended, so nothing was imported. Re-authenticate in Bank connections. The exact error is in the importer logs.","path":"/bank-connections"}'
esac
case "$what" in all | suggestions)
  send "suggestions digest" '{"event":"suggestions.digest","title":"4 category suggestions waiting","body":"Review them to categorize future transactions automatically.","path":"/rule-suggestions/list","badge":4,"tag":"suggestions"}'
esac
case "$what" in all | upgrade)
  send "upgrade" '{"event":"upgrade.success","title":"Upgrade successful","body":"Firefly III 6.6.6 → 6.7.0","path":"/"}'
esac
case "$what" in all | alert)
  send "alert (no event: always delivered)" '{"title":"FireflyX: backup FAILED","body":"The nightly backup did not finish. Check /var/log/fireflyx/backup.log.","path":"/"}'
esac
