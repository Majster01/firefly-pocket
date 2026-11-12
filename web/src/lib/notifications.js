// Pure helpers of the notifications center. No imports: format.js and the settings touch the browser at import, and this runs in tests and in the service worker.

const dayFormat = new Intl.DateTimeFormat(undefined, { weekday: 'long', day: 'numeric', month: 'long' })
const dayFormatYear = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'long', year: 'numeric' })

const dayKey = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`

/**
 * The messages (newest first, created_at as Laravel sends it: UTC) as one group per calendar day of the viewer.
 * A day is the viewer's own: a message at 23:30 UTC belongs to the next day in Ljubljana, unlike Firefly's days (format.js).
 * Neighbours of one day make a group, nothing is sorted.
 */
export function groupByDay(items, now = new Date()) {
  const today = dayKey(now)
  // Calendar arithmetic, not 24 hours back: a day with a clock change is 23 or 25 hours long
  const yesterday = dayKey(new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1))
  const groups = []
  for (const item of items) {
    const date = new Date(item.created_at)
    const day = dayKey(date)
    const last = groups.at(-1)
    if (last?.day === day) {
      last.items.push(item)
      continue
    }
    const label = day === today ? 'Today' : day === yesterday ? 'Yesterday' : (date.getFullYear() === now.getFullYear() ? dayFormat : dayFormatYear).format(date)
    groups.push({ day, label, items: [item] })
  }
  return groups
}

/**
 * Where a tap on a push goes: its page, with ?n=<id> added, which the app turns into "this one is read" (App.vue).
 * The service worker has no Firefly token to say so itself. Keeps an existing query and a #hash after it.
 */
export function tappedUrl(path, id) {
  if (!id) return path
  const url = new URL(path, 'https://pocket.invalid')
  url.searchParams.set('n', id)
  return url.pathname + url.search + url.hash
}

/** Shows the count on the app icon, or clears it at 0. The icon is a nicety: no permission, or no support, is not an error. */
export async function applyAppBadge(count, nav = globalThis.navigator) {
  if (!Number.isInteger(count) || count < 0) return
  try {
    if (count > 0) await nav?.setAppBadge?.(count)
    else await nav?.clearAppBadge?.()
  } catch {
    // Refused by the platform
  }
}
