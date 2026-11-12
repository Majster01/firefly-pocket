import { reactive, watch } from 'vue'
import { get, post } from './api.js'
import { settings } from './settings.js'
import { applyAppBadge } from './notifications.js'

// Counts shown next to navigation items. notifications: what is unread in the notifications center, which is also the number on the app icon
export const badges = reactive({ suggestions: 0, connections: 0, notifications: 0 })

let inflight = null

// Several callers ask at once when the app opens: one round of requests serves them all
export function refreshBadges() {
  inflight ??= refresh().finally(() => (inflight = null))
  return inflight
}

// The count and the icon change together, so the server's push badge and the app's never disagree for long
export function setNotificationCount(count) {
  badges.notifications = count
  return applyAppBadge(count)
}

/** Marks one notification read and shows what is left. A notification that is gone, or no network: the next refresh tells the truth. */
export async function markNotificationRead(id) {
  try {
    await setNotificationCount((await post(`notifications/${id}/read`, undefined, { quiet: true })).unread)
  } catch {
    // Pruned meanwhile, or offline
  }
}

async function refresh() {
  zeroSwitchedOff()
  if (!settings.token) {
    setNotificationCount(0)
    return
  }
  // The center has no switch in Settings, so unlike the others it is always asked for
  const tasks = [get('notifications/unread-count', undefined, { quiet: true }).then((json) => setNotificationCount(json?.count ?? 0))]
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
