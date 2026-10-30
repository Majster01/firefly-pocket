import { reactive } from 'vue'
import { get } from './api.js'
import { settings } from './settings.js'

// Counts shown next to navigation items
export const badges = reactive({ suggestions: 0, connections: 0 })

export async function refreshBadges() {
  if (!settings.token) return
  const tasks = []
  if (settings.features.ruleSuggestions) {
    tasks.push(get('rule-suggestions', undefined, { quiet: true }).then((json) => (badges.suggestions = json?.data?.length ?? 0)))
  }
  if (settings.features.bankConnections) {
    tasks.push(
      get('bank-connections', undefined, { quiet: true }).then(
        (json) => (badges.connections = (json?.data ?? []).filter((connection) => ['expiring', 'expired', 'error', 'needs_mapping'].includes(connection.status)).length),
      ),
    )
  }
  await Promise.allSettled(tasks)
}
