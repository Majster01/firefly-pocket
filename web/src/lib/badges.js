import { reactive, watch } from 'vue'
import { get } from './api.js'
import { settings } from './settings.js'

// Counts shown next to navigation items
export const badges = reactive({ suggestions: 0, connections: 0 })

let inflight = null

// Several callers ask at once when the app opens: one round of requests serves them all
export function refreshBadges() {
  inflight ??= refresh().finally(() => (inflight = null))
  return inflight
}

async function refresh() {
  zeroSwitchedOff()
  if (!settings.token) return
  const tasks = []
  // Each answer is checked against the switch again: it may have been turned off while the request ran
  if (settings.features.ruleSuggestions) {
    tasks.push(get('rule-suggestions', undefined, { quiet: true }).then((json) => (badges.suggestions = settings.features.ruleSuggestions ? (json?.data?.length ?? 0) : 0)))
  }
  if (settings.features.bankConnections) {
    tasks.push(
      get('bank-connections', undefined, { quiet: true }).then(
        (json) =>
          (badges.connections = settings.features.bankConnections
            ? (json?.data ?? []).filter((connection) => ['expiring', 'expired', 'error', 'needs_mapping'].includes(connection.status)).length
            : 0),
      ),
    )
  }
  await Promise.allSettled(tasks)
}

function zeroSwitchedOff() {
  if (!settings.features.ruleSuggestions) badges.suggestions = 0
  if (!settings.features.bankConnections) badges.connections = 0
}

// A section switched off loses its count at once; switched back on, it is fetched (after any round still running)
watch(
  () => settings.features,
  () => {
    zeroSwitchedOff()
    Promise.resolve(inflight).then(refreshBadges)
  },
  { deep: true },
)
